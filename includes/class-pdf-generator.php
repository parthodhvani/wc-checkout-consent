<?php
defined( 'ABSPATH' ) || exit;

/**
 * WCCA_PDF_Generator
 *
 * Generates a signed-consent PDF using WordPress native functions only.
 * No external library required — produces a valid PDF 1.4 document
 * by writing raw PDF objects directly.
 *
 * Usage:
 *   $path = WCCA_PDF_Generator::generate( $sig_id );
 *
 * Returns the absolute filesystem path on success, or false on failure.
 */
class WCCA_PDF_Generator {

    /**
     * Generate a consent PDF for the given signature record.
     *
     * @param int $sig_id  Row ID in wcca_signatures.
     * @return string|false  Absolute path to the saved PDF, or false.
     */


    public static function generate(int $sig_id): string|false
    {
        $sig = WCCA_Database::get_signature( $sig_id );
        if ( ! $sig ) {
            return false;
        }

        $order = wc_get_order( (int) $sig->order_id );
        if ( ! $order instanceof WC_Order ) {
            return false;
        }

        $pdf = self::build_pdf( $sig, $order, $sig_id );
        if ( $pdf === false ) {
            return false;
        }

        $upload  = wp_upload_dir();
        $pdf_dir = trailingslashit( $upload['basedir'] ) . 'wcca-consents/';

        if ( ! wp_mkdir_p( $pdf_dir ) ) {
            return false;
        }

        // Drop a silence file so the directory cannot be browsed.
        $index_file = $pdf_dir . 'index.html';
        if ( ! file_exists( $index_file ) ) {
            file_put_contents( $index_file, '<!-- Silence is golden. -->' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        }

        // Unguessable filename keeps these sensitive records private even though
        // they live under wp-content/uploads. Downloads are still gated by a
        // nonce + capability check in the AJAX handler.
        $token    = substr( wp_hash( $sig->order_id . '|' . $sig_id . '|' . $sig->signed_at ), 0, 12 );
        $filename = sprintf( 'consent-order-%d-%d-%s.pdf', (int) $sig->order_id, (int) $sig_id, $token );
        $filepath = $pdf_dir . $filename;

        if ( false === file_put_contents( $filepath, $pdf ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            return false;
        }

        return $filepath;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Build a PDF binary string.
     *
     * @param object   $sig
     * @param WC_Order $order
     * @return string|false
     */
    private static function build_pdf( object $sig, WC_Order $order,
        int $sig_id
    ): string|false {

        // ── Decode signature PNG and flatten to JPEG ──────────────────────────
        // The signature arrives as a transparent PNG data URI. Raw PNG bytes
        // cannot be embedded directly as a PDF image, so we flatten it onto a
        // white background with GD and re-encode as JPEG, which embeds cleanly
        // via the /DCTDecode filter.
        $sig_jpeg = false;
        $img_px_w = 0;
        $img_px_h = 0;
        if ( ! empty( $sig->signature ) && function_exists( 'imagecreatefromstring' ) ) {
            $b64 = preg_replace( '/^data:image\/png;base64,/', '', $sig->signature );
            $raw = base64_decode( $b64, true );
            if ( $raw !== false && strlen( $raw ) > 10 ) {
                $src = @imagecreatefromstring( $raw );
                if ( $src !== false ) {
                    $img_px_w = imagesx( $src );
                    $img_px_h = imagesy( $src );
                    $flat     = imagecreatetruecolor( $img_px_w, $img_px_h );
                    $white    = imagecolorallocate( $flat, 255, 255, 255 );
                    imagefilledrectangle( $flat, 0, 0, $img_px_w, $img_px_h, $white );
                    imagealphablending( $flat, true );
                    imagecopy( $flat, $src, 0, 0, 0, 0, $img_px_w, $img_px_h );
                    ob_start();
                    imagejpeg( $flat, null, 90 );
                    $sig_jpeg = ob_get_clean();
                    imagedestroy( $src );
                    imagedestroy( $flat );
                }
            }
        }

        // ── Gather data ───────────────────────────────────────────────────────
        $full_name   = trim( $sig->first_name . ' ' . $sig->last_name );
        $email       = $sig->email;
        $phone       = $sig->phone;
        $address     = $sig->address;
        $signed_date = wp_date( 'F j, Y \a\t g:i A', strtotime( $sig->signed_at ) );
        $order_id    = $sig->order_id;
        $order_total = html_entity_decode( wp_strip_all_tags( $order->get_formatted_order_total() ), ENT_QUOTES, 'UTF-8' );

        // Order items
        $items_text = '';
        foreach ( $order->get_items() as $item ) {
            $items_text .= sprintf(
                "  - %s  x%d  %s\n",
                $item->get_name(),
                $item->get_quantity(),
                html_entity_decode( wp_strip_all_tags( wc_price( $item->get_total() ) ), ENT_QUOTES, 'UTF-8' )
            );
        }

        // Site info
        $site_name = get_bloginfo( 'name' );
        $site_url  = get_bloginfo( 'url' );

        // ── Assemble page content lines ───────────────────────────────────────
        // We write a minimal but complete PDF manually.
        // Page = A4 (595 x 842 pt). Origin bottom-left in PDF coords.

        $W   = 595;
        $H   = 842;
        $ml  = 57;   // left margin
        $mr  = 538;  // right margin x
        $cw  = $mr - $ml; // content width

        // We collect stream commands; y decreases (top-to-bottom in our model)
        // PDF y origin is bottom-left, so we subtract from page height.

        // ── Object store ─────────────────────────────────────────────────────
        $objects  = []; // obj_id => raw_bytes
        $xref     = []; // obj_id => byte_offset
        $next_obj = 1;

        // Helper: allocate next object id
        $alloc = function() use (&$next_obj) {
            return $next_obj++;
        };

        // ── Image XObject (signature PNG) ─────────────────────────────────────
        $img_obj_id = null;
        $img_w_pt   = 0;
        $img_h_pt   = 0;

        if ( $sig_jpeg && $img_px_w > 0 && $img_px_h > 0 ) {
            // Scale to fit within content width, max height 80pt
            $scale    = min( $cw / $img_px_w, 80 / $img_px_h, 1 );
            $img_w_pt = (int) round( $img_px_w * $scale );
            $img_h_pt = (int) round( $img_px_h * $scale );

            $img_obj_id = $alloc();
            $img_len    = strlen( $sig_jpeg );

            $objects[ $img_obj_id ] =
                "$img_obj_id 0 obj\n" .
                "<<\n" .
                "/Type /XObject\n" .
                "/Subtype /Image\n" .
                "/Width $img_px_w\n" .
                "/Height $img_px_h\n" .
                "/ColorSpace /DeviceRGB\n" .
                "/BitsPerComponent 8\n" .
                "/Filter /DCTDecode\n" .   // JPEG-encoded image data
                "/Length $img_len\n" .
                ">>\n" .
                "stream\n" .
                $sig_jpeg .
                "\nendstream\n" .
                "endobj\n";
        }

        // ── Font resources ────────────────────────────────────────────────────
        $font_reg_id  = $alloc();
        $font_bold_id = $alloc();

        $objects[ $font_reg_id ] =
            "$font_reg_id 0 obj\n" .
            "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>\n" .
            "endobj\n";

        $objects[ $font_bold_id ] =
            "$font_bold_id 0 obj\n" .
            "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>\n" .
            "endobj\n";

        // ── Build page content stream ─────────────────────────────────────────
        $s    = '';   // PDF stream commands
        $y    = 800;  // Start near top of page (in our top-down model)

        $move_y = function( int $delta ) use ( &$y ): void { $y += $delta; };

        // ── HEADER BAR ────────────────────────────────────────────────────────
        // Dark navy rectangle
        $s .= sprintf( "%.4f %.4f %.4f rg\n", 0.063, 0.094, 0.157 ); // #101827 approx
        $s .= sprintf( "%d %d %d %d re f\n", 0, $H - 70, $W, 70 );

        // Header text: site name
        $s .= sprintf(
            "BT /FB 16 Tf 1 1 1 rg %d %d Td (%s) Tj ET\n",
            $ml, $H - 45,
            self::pdf_escape( $site_name )
        );
        $s .= sprintf(
            "BT /FR 9 Tf 0.7 0.75 0.85 rg %d %d Td (%s) Tj ET\n",
            $ml, $H - 60,
            self::pdf_escape( $site_url )
        );
        // Right-side: doc title
        $s .= sprintf(
            "BT /FB 11 Tf 1 1 1 rg %d %d Td (SIGNED CONSENT DOCUMENT) Tj ET\n",
            380, $H - 40
        );
        $s .= sprintf(
            "BT /FR 8 Tf 0.7 0.75 0.85 rg %d %d Td (Order #%s) Tj ET\n",
            380, $H - 53,
            self::pdf_escape( (string) $order_id )
        );

        $y = 90; // After header

        // ── Section: Document Title ───────────────────────────────────────────
        $move_y( 28 );
        // Accent bar
        $s .= sprintf( "%.4f %.4f %.4f rg\n", 0.251, 0.467, 0.894 ); // #405FE4
        $s .= sprintf( "%d %d %d %d re f\n", $ml, $H - $y - 2, 4, 22 );

        $s .= sprintf(
            "BT /FB 18 Tf %.4f %.4f %.4f rg %d %d Td (Customer Consent Form) Tj ET\n",
            0.063, 0.094, 0.157,
            $ml + 12, $H - $y + 4
        );
        $move_y( 12 );
        $s .= sprintf(
            "BT /FR 9 Tf %.4f %.4f %.4f rg %d %d Td (Generated on %s) Tj ET\n",
            0.4, 0.4, 0.4,
            $ml + 12, $H - $y,
            self::pdf_escape( wp_date( 'F j, Y' ) )
        );

        $move_y( 28 );

        // ── Horizontal rule ───────────────────────────────────────────────────
        $s .= sprintf( "%.4f %.4f %.4f rg\n", 0.878, 0.898, 0.937 ); // light blue-grey
        $s .= sprintf( "%d %d %d 1 re f\n", $ml, $H - $y, $cw );
        $move_y( 14 );

        // ── Section: Customer Information ─────────────────────────────────────
        self::section_heading( $s, $y, $H, $ml, 'CUSTOMER INFORMATION' );
        $move_y( 20 );

        self::info_row( $s, $y, $H, $ml, $cw, 'Full Name',   $full_name );
        $move_y( 18 );
        self::info_row( $s, $y, $H, $ml, $cw, 'Email',       $email );
        $move_y( 18 );
        self::info_row( $s, $y, $H, $ml, $cw, 'Phone',       $phone );
        $move_y( 18 );
        self::info_row( $s, $y, $H, $ml, $cw, 'Address',     $address );
        $move_y( 24 );

        // ── Section: Order Summary ─────────────────────────────────────────────
        self::section_heading( $s, $y, $H, $ml, 'ORDER SUMMARY' );
        $move_y( 20 );

        // Table header row
        $s .= sprintf( "%.4f %.4f %.4f rg\n", 0.937, 0.949, 0.969 );
        $s .= sprintf( "%d %d %d 18 re f\n", $ml, $H - $y - 14, $cw );
        $s .= sprintf(
            "BT /FB 8 Tf %.4f %.4f %.4f rg %d %d Td (PRODUCT) Tj ET\n",
            0.063, 0.094, 0.157, $ml + 6, $H - $y - 9
        );
        $s .= sprintf(
            "BT /FB 8 Tf %.4f %.4f %.4f rg %d %d Td (QTY) Tj ET\n",
            0.063, 0.094, 0.157, $ml + 300, $H - $y - 9
        );
        $s .= sprintf(
            "BT /FB 8 Tf %.4f %.4f %.4f rg %d %d Td (PRICE) Tj ET\n",
            0.063, 0.094, 0.157, $ml + 380, $H - $y - 9
        );
        $move_y( 20 );

        // Table rows
        $row_i = 0;
        foreach ( $order->get_items() as $item ) {
            if ( $row_i % 2 === 0 ) {
                $s .= sprintf( "%.4f %.4f %.4f rg\n", 0.976, 0.980, 0.988 );
                $s .= sprintf( "%d %d %d 16 re f\n", $ml, $H - $y - 12, $cw );
            }
            $item_price = html_entity_decode( wp_strip_all_tags( wc_price( $item->get_total() ) ), ENT_QUOTES, 'UTF-8' );
            $s .= sprintf(
                "BT /FR 8 Tf %.4f %.4f %.4f rg %d %d Td (%s) Tj ET\n",
                0.2, 0.2, 0.2,
                $ml + 6, $H - $y - 7,
                self::pdf_escape( substr( $item->get_name(), 0, 50 ) )
            );
            $s .= sprintf(
                "BT /FR 8 Tf %.4f %.4f %.4f rg %d %d Td (%s) Tj ET\n",
                0.2, 0.2, 0.2,
                $ml + 300, $H - $y - 7,
                self::pdf_escape( (string) $item->get_quantity() )
            );
            $s .= sprintf(
                "BT /FR 8 Tf %.4f %.4f %.4f rg %d %d Td (%s) Tj ET\n",
                0.2, 0.2, 0.2,
                $ml + 380, $H - $y - 7,
                self::pdf_escape( $item_price )
            );
            $move_y( 18 );
            $row_i++;
        }

        // Total row
        $s .= sprintf( "%.4f %.4f %.4f rg\n", 0.063, 0.094, 0.157 );
        $s .= sprintf( "%d %d %d 20 re f\n", $ml, $H - $y - 15, $cw );
        $s .= sprintf(
            "BT /FB 9 Tf 1 1 1 rg %d %d Td (ORDER TOTAL) Tj ET\n",
            $ml + 6, $H - $y - 8
        );
        $s .= sprintf(
            "BT /FB 9 Tf 1 1 1 rg %d %d Td (%s) Tj ET\n",
            $ml + 380, $H - $y - 8,
            self::pdf_escape( $order_total )
        );
        $move_y( 28 );

        // ── Section: Consent Declaration ──────────────────────────────────────
        self::section_heading( $s, $y, $H, $ml, 'CONSENT DECLARATION' );
        $move_y( 20 );

        // Light blue box
        $s .= sprintf( "%.4f %.4f %.4f rg\n", 0.937, 0.949, 0.980 );
        $s .= sprintf( "%d %d %d 90 re f\n", $ml, $H - $y - 84, $cw );
        // Blue left border
        $s .= sprintf( "%.4f %.4f %.4f rg\n", 0.251, 0.467, 0.894 );
        $s .= sprintf( "%d %d 3 90 re f\n", $ml, $H - $y - 84 );

        $decl_lines = [
            'I, ' . $full_name . ', hereby confirm that:',
            '',
            '  1. I have reviewed and agree to the terms and conditions of this purchase.',
            '  2. The order information above is accurate and correct.',
            '  3. I authorise the processing of my personal data for order fulfilment.',
            '  4. I understand this digital signature is legally binding.',
        ];
        foreach ( $decl_lines as $line ) {
            if ( $line === '' ) { $move_y( 6 ); continue; }
            $s .= sprintf(
                "BT /FR 8.5 Tf %.4f %.4f %.4f rg %d %d Td (%s) Tj ET\n",
                0.063, 0.094, 0.157,
                $ml + 10, $H - $y,
                self::pdf_escape( $line )
            );
            $move_y( 13 );
        }
        $move_y( 20 );

        // ── Section: Signature ────────────────────────────────────────────────
        self::section_heading( $s, $y, $H, $ml, 'DIGITAL SIGNATURE' );
        $move_y( 16 );

        if ( $img_obj_id ) {            // Signature box background
            $s .= sprintf( "%.4f %.4f %.4f rg\n", 0.97, 0.97, 0.97 );
            $s .= sprintf( "%d %d %d %d re f\n", $ml, $H - $y - $img_h_pt - 12, $img_w_pt + 24, $img_h_pt + 12 );
            // Draw the PNG image
            $s .= sprintf(
                "q %d 0 0 %d %d %d cm /Sig Do Q\n",
                $img_w_pt,
                $img_h_pt,
                $ml + 12,
                $H - $y - $img_h_pt - 6
            );
            $move_y( $img_h_pt + 20 );
        } else {
            $s .= sprintf(
                "BT /FR 9 Tf %.4f %.4f %.4f rg %d %d Td ([Signature on file]) Tj ET\n",
                0.4, 0.4, 0.4,
                $ml, $H - $y
            );
            $move_y( 20 );
        }

        // Signed date line
        $s .= sprintf( "%.4f %.4f %.4f rg\n", 0.878, 0.898, 0.937 );
        $s .= sprintf( "%d %d %d 1 re f\n", $ml, $H - $y, $cw );
        $move_y( 10 );
        $s .= sprintf(
            "BT /FR 8 Tf %.4f %.4f %.4f rg %d %d Td (Signed by: %s  |  Date: %s) Tj ET\n",
            0.4, 0.4, 0.4,
            $ml, $H - $y,
            self::pdf_escape( $full_name ),
            self::pdf_escape( $signed_date )
        );
        $move_y( 32 );

        // ── FOOTER ────────────────────────────────────────────────────────────
        $s .= sprintf( "%.4f %.4f %.4f rg\n", 0.937, 0.949, 0.969 );
        $s .= sprintf( "0 0 %d 40 re f\n", $W );
        $s .= sprintf(
            "BT /FR 7 Tf %.4f %.4f %.4f rg %d 15 Td (This document was automatically generated by %s (%s). Record ID: %d) Tj ET\n",
            0.4, 0.4, 0.4,
            $ml,
            self::pdf_escape( $site_name ),
            self::pdf_escape( $site_url ),
            $sig_id
        );
        $s .= sprintf(
            "BT /FR 7 Tf %.4f %.4f %.4f rg %d 7 Td (This is a legally binding digital consent document. Keep this for your records.) Tj ET\n",
            0.4, 0.4, 0.4,
            $ml
        );

        // ── Page content object ───────────────────────────────────────────────
        $content_obj_id = $alloc();
        $s_len          = strlen( $s );

        $objects[ $content_obj_id ] =
            "$content_obj_id 0 obj\n" .
            "<< /Length $s_len >>\n" .
            "stream\n" .
            $s .
            "\nendstream\n" .
            "endobj\n";

        // ── Resources dict ────────────────────────────────────────────────────
        $res_obj_id = $alloc();

        $xobj_dict = '';
        if ( $img_obj_id ) {
            $xobj_dict = "/XObject << /Sig $img_obj_id 0 R >>\n";
        }

        $objects[ $res_obj_id ] =
            "$res_obj_id 0 obj\n" .
            "<<\n" .
            "/Font << /FR $font_reg_id 0 R /FB $font_bold_id 0 R >>\n" .
            $xobj_dict .
            ">>\n" .
            "endobj\n";

        // ── Page object ───────────────────────────────────────────────────────
        $page_obj_id    = $alloc();
        $pages_obj_id   = $alloc();

        $objects[ $page_obj_id ] =
            "$page_obj_id 0 obj\n" .
            "<<\n" .
            "/Type /Page\n" .
            "/Parent $pages_obj_id 0 R\n" .
            "/MediaBox [0 0 $W $H]\n" .
            "/Contents $content_obj_id 0 R\n" .
            "/Resources $res_obj_id 0 R\n" .
            ">>\n" .
            "endobj\n";

        $objects[ $pages_obj_id ] =
            "$pages_obj_id 0 obj\n" .
            "<<\n" .
            "/Type /Pages\n" .
            "/Kids [$page_obj_id 0 R]\n" .
            "/Count 1\n" .
            ">>\n" .
            "endobj\n";

        // ── Catalog ───────────────────────────────────────────────────────────
        $catalog_obj_id = $alloc();

        $objects[ $catalog_obj_id ] =
            "$catalog_obj_id 0 obj\n" .
            "<< /Type /Catalog /Pages $pages_obj_id 0 R >>\n" .
            "endobj\n";

        // ── Assemble PDF ──────────────────────────────────────────────────────
        $pdf_out = "%PDF-1.4\n";

        // Sort objects by id for a clean xref
        ksort( $objects );

        foreach ( $objects as $oid => $body ) {
            $xref[ $oid ] = strlen( $pdf_out );
            $pdf_out .= $body;
        }

        // xref table
        $xref_offset = strlen( $pdf_out );
        $count       = count( $objects ) + 1; // +1 for object 0
        $pdf_out .= "xref\n";
        $pdf_out .= "0 $count\n";
        $pdf_out .= "0000000000 65535 f \n"; // object 0 (free)
        for ( $i = 1; $i < $count; $i++ ) {
            $offset   = isset( $xref[ $i ] ) ? $xref[ $i ] : 0;
            $pdf_out .= sprintf( "%010d 00000 n \n", $offset );
        }

        $pdf_out .= "trailer\n";
        $pdf_out .= "<< /Size $count /Root $catalog_obj_id 0 R >>\n";
        $pdf_out .= "startxref\n$xref_offset\n%%EOF\n";

        return $pdf_out;
    }

    // ── Drawing helpers ───────────────────────────────────────────────────────

    private static function section_heading( string &$s, int $y, int $H, int $ml, string $text ): void {
        // Small caps-style label
        $s .= sprintf(
            "BT /FB 8 Tf %.4f %.4f %.4f rg %d %d Td (%s) Tj ET\n",
            0.251, 0.467, 0.894,
            $ml, $H - $y,
            self::pdf_escape( $text )
        );
    }

    private static function info_row( string &$s, int $y, int $H, int $ml, int $cw, string $label, string $value ): void {
        $s .= sprintf(
            "BT /FB 8 Tf %.4f %.4f %.4f rg %d %d Td (%s) Tj ET\n",
            0.4, 0.4, 0.4,
            $ml, $H - $y,
            self::pdf_escape( $label )
        );
        $s .= sprintf(
            "BT /FR 9 Tf %.4f %.4f %.4f rg %d %d Td (%s) Tj ET\n",
            0.063, 0.094, 0.157,
            $ml + 90, $H - $y,
            self::pdf_escape( substr( $value, 0, 70 ) )
        );
    }

    /**
     * Escape a string for PDF text stream (parentheses and backslash).
     */
    private static function pdf_escape( string $text ): string {
        // Strip tags, decode entities, then escape for PDF
        $text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
        // Down-convert to Latin-1 (the WinAnsiEncoding font used in the PDF).
        if ( function_exists( 'mb_convert_encoding' ) ) {
            $text = mb_convert_encoding( $text, 'ISO-8859-1', 'UTF-8' );
        }
        $text = str_replace( [ '\\', '(', ')', "\r", "\n" ], [ '\\\\', '\\(', '\\)', '', '' ], $text );
        return $text;
    }
}
