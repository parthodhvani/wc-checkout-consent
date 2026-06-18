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

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');

        $out = fopen('php://output', 'w');
        fputcsv($out, $columns);
        foreach ($rows as $row) {
            fputcsv($out, array_map(fn($k) => $row[$k] ?? '', $columns));
        }
        fclose($out);
        exit;
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
        if (empty($_FILES['wcca_import_file']['tmp_name'])) {
            return array('type' => 'error', 'message' => __('No file uploaded.', 'woocommerce-checkout-consent'));
        }

        $file = $_FILES['wcca_import_file']['tmp_name'];

        if (!is_uploaded_file($file)) {
            return array('type' => 'error', 'message' => __('Invalid file upload.', 'woocommerce-checkout-consent'));
        }

        $handle = fopen($file, 'r'); // phpcs:ignore WordPress.WP.AlternativeFunctions
        if (!$handle) {
            return array('type' => 'error', 'message' => __('Could not read file.', 'woocommerce-checkout-consent'));
        }

        $required = array('order_id', 'customer_id', 'first_name', 'last_name', 'email', 'phone', 'address', 'signed_at');
        $header = fgetcsv($handle);

        if (!$header) {
            fclose($handle);
            return array('type' => 'error', 'message' => __('CSV file is empty.', 'woocommerce-checkout-consent'));
        }

        $header = array_map('trim', $header);

        foreach ($required as $col) {
            if (!in_array($col, $header, true)) {
                fclose($handle);
                /* translators: %s: required CSV column name. */
                return array('type' => 'error', 'message' => sprintf(__('Missing required column: %s', 'woocommerce-checkout-consent'), $col));
            }
        }

        $imported = 0;
        $skipped = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($header, $row);
            if (!$data) {
                $skipped++;
                continue;
            }

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
                'order_id' => $order_id,
                'customer_id' => absint($data['customer_id'] ?? 0),
                'first_name' => sanitize_text_field($data['first_name'] ?? ''),
                'last_name' => sanitize_text_field($data['last_name'] ?? ''),
                'email' => sanitize_email($data['email'] ?? ''),
                'phone' => sanitize_text_field($data['phone'] ?? ''),
                'address' => sanitize_textarea_field($data['address'] ?? ''),
                'signature' => '',
            ));

            if ($result) {
                $imported++;
            } else {
                $skipped++;
            }
        }

        fclose($handle);

        return array(
            'type' => 'success',
            /* translators: 1: number of imported records, 2: number of skipped records. */
            'message' => sprintf(
                __('Import complete: %1$d records imported, %2$d skipped (already exist or invalid).', 'woocommerce-checkout-consent'),
                $imported,
                $skipped
            ),
        );
    }
}