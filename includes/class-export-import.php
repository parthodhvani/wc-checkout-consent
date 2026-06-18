<?php
defined('ABSPATH') || exit;

class WCCA_Export_Import
{

    public static function init(): void
    {
        add_action('admin_init', array(__CLASS__, 'handle_requests'));
    }

    public static function handle_requests(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        // Export
        if (isset($_POST['wcca_export_format']) && check_admin_referer('wcca_export_nonce', 'wcca_export_nonce')) {
            $format = sanitize_key($_POST['wcca_export_format']);
            if ($format === 'csv') {
                self::export_csv();
            } elseif ($format === 'json') {
                self::export_json();
            }
        }

        // Import
        if (isset($_POST['wcca_import_submit']) && check_admin_referer('wcca_import_nonce', 'wcca_import_nonce')) {
            $result = self::import_csv();
            set_transient(
                'wcca_import_result_' . get_current_user_id(),
                $result,
                60
            );
            wp_safe_redirect(add_query_arg('page', 'wcca-export-import', admin_url('admin.php')));
            exit;
        }
    }

    private static function get_records(): array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $wpdb->get_results(
            "SELECT id, order_id, customer_id, first_name, last_name, email, phone, address, signed_at, ip_address
             FROM {$wpdb->prefix}wcca_signatures
             ORDER BY signed_at DESC",
            ARRAY_A
        ) ?: array();
    }

    private static function export_csv(): void
    {
        $rows = self::get_records();
        $columns = array('id', 'order_id', 'customer_id', 'first_name', 'last_name', 'email', 'phone', 'address', 'signed_at', 'ip_address');
        $filename = 'wcca-consents-' . gmdate('Y-m-d') . '.csv';

        $csv = self::csv_line($columns);
        foreach ($rows as $row) {
            $csv .= self::csv_line(array_map(static fn($k) => (string) ($row[$k] ?? ''), $columns));
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        // CSV file download — not HTML output.
        echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    /**
     * Build a single RFC-4180 style CSV line. Newlines within values are
     * collapsed to spaces so each record stays on one line.
     *
     * @param array $fields Field values.
     * @return string
     */
    private static function csv_line(array $fields): string
    {
        $escaped = array_map(static function ($field) {
            $field = str_replace(array("\r\n", "\r", "\n"), ' ', (string) $field);
            return '"' . str_replace('"', '""', $field) . '"';
        }, $fields);

        return implode(',', $escaped) . "\r\n";
    }

    private static function export_json(): void
    {
        $rows = self::get_records();
        $filename = 'wcca-consents-' . gmdate('Y-m-d') . '.json';

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');

        echo wp_json_encode($rows, JSON_PRETTY_PRINT);
        exit;
    }

    private static function import_csv(): array
    {
        // The request nonce is verified by the caller (handle_requests()).
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $tmp_name = isset($_FILES['wcca_import_file']['tmp_name']) ? sanitize_text_field(wp_unslash($_FILES['wcca_import_file']['tmp_name'])) : '';

        if ('' === $tmp_name) {
            return array('type' => 'error', 'message' => __('No file uploaded.', 'checkout-consent-for-woocommerce'));
        }

        if (!is_uploaded_file($tmp_name)) {
            return array('type' => 'error', 'message' => __('Invalid file upload.', 'checkout-consent-for-woocommerce'));
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        global $wp_filesystem;
        WP_Filesystem();

        $contents = $wp_filesystem ? $wp_filesystem->get_contents($tmp_name) : false;
        if (false === $contents || '' === $contents) {
            return array('type' => 'error', 'message' => __('Could not read file.', 'checkout-consent-for-woocommerce'));
        }

        $lines = preg_split('/\r\n|\r|\n/', $contents);
        $lines = array_values(array_filter((array) $lines, static fn($l) => '' !== trim($l)));

        if (empty($lines)) {
            return array('type' => 'error', 'message' => __('CSV file is empty.', 'checkout-consent-for-woocommerce'));
        }

        $header   = array_map('trim', str_getcsv(array_shift($lines)));
        $required = array('order_id', 'customer_id', 'first_name', 'last_name', 'email', 'phone', 'address', 'signed_at');

        foreach ($required as $col) {
            if (!in_array($col, $header, true)) {
                return array(
                    'type'    => 'error',
                    /* translators: %s: required CSV column name. */
                    'message' => sprintf(__('Missing required column: %s', 'checkout-consent-for-woocommerce'), $col),
                );
            }
        }

        $imported = 0;
        $skipped  = 0;

        foreach ($lines as $line) {
            $row = str_getcsv($line);
            if (count($row) !== count($header)) {
                $skipped++;
                continue;
            }

            $data = array_combine($header, $row);

            $order_id = absint($data['order_id'] ?? 0);
            if (!$order_id) {
                $skipped++;
                continue;
            }

            // Skip if already exists
            if (WCCA_Database::get_by_order($order_id)) {
                $skipped++;
                continue;
            }

            $result = WCCA_Database::save_signature(array(
                'order_id'    => $order_id,
                'customer_id' => absint($data['customer_id'] ?? 0),
                'first_name'  => sanitize_text_field($data['first_name'] ?? ''),
                'last_name'   => sanitize_text_field($data['last_name'] ?? ''),
                'email'       => sanitize_email($data['email'] ?? ''),
                'phone'       => sanitize_text_field($data['phone'] ?? ''),
                'address'     => sanitize_textarea_field($data['address'] ?? ''),
                'signature'   => '',
            ));

            if ($result) {
                $imported++;
            } else {
                $skipped++;
            }
        }

        $message = sprintf(
            /* translators: 1: number of imported records, 2: number of skipped records. */
            __('Import complete: %1$d records imported, %2$d skipped (already exist or invalid).', 'checkout-consent-for-woocommerce'),
            $imported,
            $skipped
        );

        return array('type' => 'success', 'message' => $message);
    }
}