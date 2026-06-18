<?php
defined('ABSPATH') || exit;

/**
 * WCCA_Database
 *
 * Handles all direct database interactions for WC Customer Affairs.
 * All queries use $wpdb->prepare() — no raw string interpolation of user data.
 */
class WCCA_Database
{

    /**
     * Create plugin tables (called on activation via register_activation_hook).
     */
    public static function create_tables(): void
    {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();

        $sql_signatures = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wcca_signatures (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id    BIGINT UNSIGNED NOT NULL,
            customer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            first_name  VARCHAR(100)    NOT NULL DEFAULT '',
            last_name   VARCHAR(100)    NOT NULL DEFAULT '',
            email       VARCHAR(200)    NOT NULL DEFAULT '',
            phone       VARCHAR(50)     NOT NULL DEFAULT '',
            address     TEXT            NOT NULL DEFAULT '',
            signature   LONGTEXT        NOT NULL,
            signed_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ip_address  VARCHAR(45)     NOT NULL DEFAULT '',
            user_agent  VARCHAR(500)    NOT NULL DEFAULT '',
            pdf_path    VARCHAR(500)    NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            KEY idx_order_id    (order_id),
            KEY idx_customer_id (customer_id)
        ) {$charset};";

        $sql_logs = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wcca_consent_logs (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            signature_id BIGINT UNSIGNED NOT NULL,
            action       VARCHAR(50)     NOT NULL,
            performed_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ip_address   VARCHAR(45)     NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            KEY idx_signature_id (signature_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_signatures);
        dbDelta($sql_logs);
    }

    /**
     * Save a new signature record.
     *
     * @param array $data Sanitised signature data.
     * @return int|false  Inserted row ID, or false on failure.
     */
    public static function save_signature(array $data): int|false
    {
        global $wpdb;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching      
        $result = $wpdb->insert(
            $wpdb->prefix . 'wcca_signatures',
            array(
                'order_id' => absint($data['order_id']),
                'customer_id' => absint($data['customer_id']),
                'first_name' => sanitize_text_field($data['first_name'] ?? ''),
                'last_name' => sanitize_text_field($data['last_name'] ?? ''),
                'email' => sanitize_email($data['email'] ?? ''),
                'phone' => sanitize_text_field($data['phone'] ?? ''),
                'address' => sanitize_textarea_field($data['address'] ?? ''),
                'signature' => $data['signature'],   // validated base64 before reaching here
                'ip_address' => self::get_client_ip(),
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT'])
                    ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']))
                    : '',
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        return $result ? (int) $wpdb->insert_id : false;
    }

    /**
     * Retrieve the most recent signature record for an order.
     *
     * @param int $order_id WooCommerce order ID.
     * @return object|null
     */
    public static function get_by_order(int $order_id): ?object
    {
        global $wpdb;

        $cache_key = 'wcca_order_' . $order_id;

        $signature = wp_cache_get($cache_key, 'wcca');

        if (false === $signature) {

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching  
            $signature = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}wcca_signatures
                 WHERE order_id = %d
                 ORDER BY signed_at DESC
                 LIMIT 1",
                    $order_id
                )
            );

            wp_cache_set(
                $cache_key,
                $signature,
                'wcca',
                HOUR_IN_SECONDS
            );
        }

        return $signature ?: null;
    }
    /**
     * Retrieve all signature records for a customer.
     *
     * @param int $customer_id WP user ID.
     * @return object[]
     */
    public static function get_by_customer(int $customer_id): array
    {
        global $wpdb;

        $cache_key = 'wcca_customer_' . $customer_id;

        $results = wp_cache_get(
            $cache_key,
            'wcca'
        );

        if (false === $results) {

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching  
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT s.*
                FROM {$wpdb->prefix}wcca_signatures s
                WHERE s.customer_id = %d
                ORDER BY s.signed_at DESC",
                    $customer_id
                )
            );

            wp_cache_set(
                $cache_key,
                $results,
                'wcca',
                HOUR_IN_SECONDS
            );
        }

        return $results ?: array();
    }

    /**
     * Update the stored PDF path after generation.
     *
     * @param int    $signature_id
     * @param string $path Absolute filesystem path.
     */
    public static function update_pdf_path(int $signature_id, string $path): void
    {
        global $wpdb;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching  
        $wpdb->update(
            $wpdb->prefix . 'wcca_signatures',
            array('pdf_path' => sanitize_text_field($path)),
            array('id' => $signature_id),
            array('%s'),
            array('%d')
        );
    }

    /**
     * Log a consent-related action for audit trail.
     *
     * @param int    $signature_id
     * @param string $action  One of: 'signed', 'pdf_generated', 'pdf_downloaded', 'viewed'.
     */
    public static function log_action(int $signature_id, string $action): void
    {
        global $wpdb;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching  
        $wpdb->insert(
            $wpdb->prefix . 'wcca_consent_logs',
            array(
                'signature_id' => $signature_id,
                'action' => sanitize_key($action),
                'ip_address' => self::get_client_ip(),
            ),
            array('%d', '%s', '%s')
        );
    }

    /**
     * Return the client IP address, with basic proxy header support.
     */
    private static function get_client_ip(): string
    {
        $headers = array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                // X-Forwarded-For may contain a list; take the first entry.
                $ip = trim(explode(',', sanitize_text_field(wp_unslash($_SERVER[$header])))[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '';
    }
    public static function get_signature(int $sig_id): ?object
    {
        global $wpdb;

        $cache_key = 'wcca_signature_' . $sig_id;

        $sig = wp_cache_get($cache_key, 'wcca');

        if (false === $sig) {

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $sig = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}wcca_signatures
                WHERE id = %d
                LIMIT 1",
                    $sig_id
                )
            );

            wp_cache_set(
                $cache_key,
                $sig,
                'wcca',
                HOUR_IN_SECONDS
            );
        }

        return $sig ?: null;
    }
}

