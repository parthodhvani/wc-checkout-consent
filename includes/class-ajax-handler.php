<?php
defined( 'ABSPATH' ) || exit;

/**
 * WCCA_Ajax_Handler
 *
 * Registers and handles all WordPress AJAX actions for WC Customer Affairs.
 * Every handler verifies nonces and capability before acting.
 */
class WCCA_Ajax_Handler {

    public static function init(): void {
        // Authenticated users only
        add_action( 'wp_ajax_wcca_save_signature',    array( __CLASS__, 'save_signature' ) );
        add_action( 'wp_ajax_wcca_download_pdf',      array( __CLASS__, 'download_pdf' ) );
        add_action( 'wp_ajax_wcca_save_cart_consent', array( __CLASS__, 'save_cart_consent' ) );

        // Cart consent — allow non-logged-in users (guest checkout)
        add_action( 'wp_ajax_nopriv_wcca_save_cart_consent', array( __CLASS__, 'save_cart_consent' ) );

        // PDF download denied for guests
        add_action( 'wp_ajax_nopriv_wcca_download_pdf', static function () {
            wp_die( esc_html__( 'Login required to download consent PDFs.', 'checkout-consent-for-woocommerce' ), 403 );
        } );
    }

    // ── save_signature ────────────────────────────────────────────────────────

    /**
     * Save a consent signature submitted from the My Account order-consent page.
     * Requires the user to be logged in and own the order.
     */
    public static function save_signature(): void {
        check_ajax_referer( 'wcca_sign', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'Login required.', 'checkout-consent-for-woocommerce' ) ), 403 );
        }

        $order_id = absint( $_POST['order_id'] ?? 0 );
        if ( ! $order_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid order.', 'checkout-consent-for-woocommerce' ) ), 400 );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order || (int) $order->get_customer_id() !== get_current_user_id() ) {
            wp_send_json_error( array( 'message' => __( 'Access denied.', 'checkout-consent-for-woocommerce' ) ), 403 );
        }

        if ( WCCA_Database::get_by_order( $order_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Consent already recorded for this order.', 'checkout-consent-for-woocommerce' ) ), 409 );
        }

        // Strictly validated as a base64 PNG data URI inside validate_signature_data().
        $raw_signature = isset( $_POST['signature'] ) ? wp_unslash( $_POST['signature'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $signature     = self::validate_signature_data( $raw_signature );
        if ( is_wp_error( $signature ) ) {
            wp_send_json_error( array( 'message' => $signature->get_error_message() ), 400 );
        }

        $sig_id = WCCA_Database::save_signature( array(
            'order_id'    => $order_id,
            'customer_id' => get_current_user_id(),
            'first_name'  => sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) ),
            'last_name'   => sanitize_text_field( wp_unslash( $_POST['last_name']  ?? '' ) ),
            'email'       => sanitize_email( wp_unslash( $_POST['email']  ?? '' ) ),
            'phone'       => sanitize_text_field( wp_unslash( $_POST['phone']  ?? '' ) ),
            'address'     => sanitize_textarea_field( wp_unslash( $_POST['address'] ?? '' ) ),
            'signature'   => $signature,
        ) );

        if ( ! $sig_id ) {
            wp_send_json_error( array( 'message' => __( 'Failed to save. Please try again.', 'checkout-consent-for-woocommerce' ) ), 500 );
        }

        WCCA_Database::log_action( $sig_id, 'signed' );

        $pdf_path = WCCA_PDF_Generator::generate( $sig_id );
        if ( $pdf_path ) {
            WCCA_Database::update_pdf_path( $sig_id, $pdf_path );
            WCCA_Database::log_action( $sig_id, 'pdf_generated' );
        }

        wp_send_json_success( array(
            'message' => __( 'Consent signed successfully.', 'checkout-consent-for-woocommerce' ),
            'pdf_url' => esc_url( add_query_arg( array(
                'action' => 'wcca_download_pdf',
                'sig_id' => $sig_id,
                'nonce'  => wp_create_nonce( 'wcca_pdf_' . $sig_id ),
            ), admin_url( 'admin-ajax.php' ) ) ),
        ) );
    }

    // ── download_pdf ──────────────────────────────────────────────────────────

    /**
     * Stream a consent PDF to the browser.
     * Requires a per-record nonce; accessible by the record owner or a shop manager.
     */
    public static function download_pdf(): void {
        $sig_id = absint( $_GET['sig_id'] ?? 0 );
        if ( ! $sig_id ) {
            wp_die( esc_html__( 'Invalid request.', 'checkout-consent-for-woocommerce' ), 400 );
        }

        check_ajax_referer( 'wcca_pdf_' . $sig_id, 'nonce' );

        $sig = WCCA_Database::get_signature( $sig_id );

        if ( ! $sig ) {
            wp_die( esc_html__( 'Record not found.', 'checkout-consent-for-woocommerce' ), 404 );
        }

        // Allow the record owner or any shop manager
        $is_owner   = is_user_logged_in() && (int) $sig->customer_id === get_current_user_id();
        $is_manager = current_user_can( 'manage_woocommerce' );

        if ( ! $is_owner && ! $is_manager ) {
            wp_die( esc_html__( 'Access denied.', 'checkout-consent-for-woocommerce' ), 403 );
        }

        // Regenerate PDF if it no longer exists on disk
        if ( empty( $sig->pdf_path ) || ! file_exists( $sig->pdf_path ) ) {
            $path = WCCA_PDF_Generator::generate( $sig_id );
            if ( ! $path ) {
                wp_die( esc_html__( 'PDF could not be generated. Please try again.', 'checkout-consent-for-woocommerce' ), 500 );
            }
            $sig->pdf_path = $path;
        }

        WCCA_Database::log_action( $sig_id, 'pdf_downloaded' );

        $filename = 'consent-order-' . absint( $sig->order_id ) . '.pdf';

        header( 'Content-Type: application/pdf' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . filesize( $sig->pdf_path ) );
        header( 'X-Content-Type-Options: nosniff' );
        readfile( $sig->pdf_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
        exit;
    }

    // ── save_cart_consent ─────────────────────────────────────────────────────

    /**
     * Save signed cart consent to the WooCommerce session.
     * Accessible by both logged-in users and guests.
     */
    public static function save_cart_consent(): void {
        check_ajax_referer( 'wcca_cart_consent', 'nonce' );

        // Strictly validated as a base64 PNG data URI inside validate_signature_data().
        $raw_signature = isset( $_POST['signature'] ) ? wp_unslash( $_POST['signature'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $signature     = self::validate_signature_data( $raw_signature );
        if ( is_wp_error( $signature ) ) {
            wp_send_json_error( array( 'message' => $signature->get_error_message() ), 400 );
        }

        if ( ! WC()->session ) {
            wp_send_json_error( array( 'message' => __( 'Session unavailable. Please refresh and try again.', 'checkout-consent-for-woocommerce' ) ), 500 );
        }

        WC()->session->set( 'wcca_cart_consent', array(
            'signed'     => true,
            'first_name' => sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) ),
            'last_name'  => sanitize_text_field( wp_unslash( $_POST['last_name']  ?? '' ) ),
            'email'      => sanitize_email( wp_unslash( $_POST['email']  ?? '' ) ),
            'phone'      => sanitize_text_field( wp_unslash( $_POST['phone']  ?? '' ) ),
            'signature'  => $signature,
            'signed_at'  => current_time( 'mysql' ),
        ) );

        wp_send_json_success( array( 'message' => __( 'Consent saved.', 'checkout-consent-for-woocommerce' ) ) );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Validate that a value is a well-formed base64-encoded PNG data URI.
     *
     * @param mixed $raw Raw POST value.
     * @return string|WP_Error Validated data URI, or WP_Error on failure.
     */
    private static function validate_signature_data( $raw ): string|\WP_Error {
        if ( ! is_string( $raw ) || empty( $raw ) ) {
            return new \WP_Error( 'invalid_signature', __( 'Signature data is missing.', 'checkout-consent-for-woocommerce' ) );
        }

        // Must be a PNG data URI
        if ( ! preg_match( '/^data:image\/png;base64,[A-Za-z0-9+\/]+=*$/', $raw ) ) {
            return new \WP_Error( 'invalid_signature', __( 'Invalid signature format.', 'checkout-consent-for-woocommerce' ) );
        }

        // Sanity-check the base64 payload decodes to something PNG-shaped
        $b64  = substr( $raw, strlen( 'data:image/png;base64,' ) );
        $data = base64_decode( $b64, true );
        if ( $data === false || strlen( $data ) < 8 || substr( $data, 0, 8 ) !== "\x89PNG\r\n\x1a\n" ) {
            return new \WP_Error( 'invalid_signature', __( 'Signature image is corrupt.', 'checkout-consent-for-woocommerce' ) );
        }

        return $raw;
    }
}
