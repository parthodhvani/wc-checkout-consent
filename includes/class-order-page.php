<?php
defined('ABSPATH') || exit;

/**
 * WCCA_Order_Page
 *
 * Manages the front-end consent flow:
 *  - My Account "order-consent" endpoint (sign after ordering)
 *  - Cart / checkout modal
 *  - Checkout validation & order-creation hook
 *  - Asset enqueueing
 */
class WCCA_Order_Page
{

    public static function init(): void
    {
        add_action('init', array(__CLASS__, 'add_endpoint'));
        add_filter('query_vars', array(__CLASS__, 'add_query_vars'));
        add_filter('woocommerce_my_account_my_orders_actions', array(__CLASS__, 'add_sign_action'), 10, 2);
        add_action('woocommerce_account_order-consent_endpoint', array(__CLASS__, 'render_consent_page'));

        // Modal on cart and checkout
        add_action('wp_footer', array(__CLASS__, 'render_cart_consent_modal'));

        // Assets
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_assets'), 999);

        // NOTE: checkout validation is handled by WCCA_Checkout_Validation::validate_consent()
        // Do NOT add a second woocommerce_checkout_process hook here — it would fire twice.

        // After order is created: persist session consent to DB
        add_action('woocommerce_checkout_order_processed', array(__CLASS__, 'save_checkout_consent_to_order'), 10, 3);
    }

    // ── Endpoint ──────────────────────────────────────────────────────────────

    public static function add_endpoint(): void
    {
        add_rewrite_endpoint('order-consent', EP_ROOT | EP_PAGES);
    }

    public static function add_query_vars(array $vars): array
    {
        $vars[] = 'order-consent';
        return $vars;
    }

    // ── My orders action link ─────────────────────────────────────────────────

    public static function add_sign_action(array $actions, WC_Order $order): array
    {
        $sig = WCCA_Database::get_by_order($order->get_id());

        if (!$sig) {
            $actions['wcca_sign'] = array(
                'url' => esc_url(wc_get_endpoint_url('order-consent', $order->get_id(), wc_get_page_permalink('myaccount'))),
                'name' => esc_html__('Sign Consent', 'checkout-consent-for-woocommerce'),
            );
        }

        return $actions;
    }

    // ── Assets ────────────────────────────────────────────────────────────────

        public static function enqueue_assets(): void
        {
            if (!is_cart() && !is_account_page() && !is_checkout()) {
                return;
            }

            wp_enqueue_style(
                'wcca-frontend',
                WCCA_PLUGIN_URL . 'assets/css/frontend.css',
                array(),
                WCCA_VERSION
            );

            wp_enqueue_script(
                'wcca-signature-pad',
                WCCA_PLUGIN_URL . 'assets/js/signature-pad.min.js',
                array(),
                '4.1.7',
                true
            );

            wp_enqueue_script(
                'wcca-frontend',
                WCCA_PLUGIN_URL . 'assets/js/frontend.js',
                array('jquery', 'wcca-signature-pad'),
                WCCA_VERSION,
                true
            );

            $current_user = wp_get_current_user();

            // FIX: pass session-consent state and ask_every_time to JS
            $session_consent   = WC()->session ? WC()->session->get( 'wcca_cart_consent' ) : null;
            $already_consented = ! empty( $session_consent['signed'] ) ? 1 : 0;

            wp_localize_script('wcca-frontend', 'wcca', array(
                'ajax_url'          => esc_url(admin_url('admin-ajax.php')),
                'nonce'             => wp_create_nonce('wcca_sign'),
                'cart_nonce'        => wp_create_nonce('wcca_cart_consent'),
                'is_cart'           => is_cart() ? 1 : 0,
                'is_checkout'       => is_checkout() ? 1 : 0,
                'checkout_url'      => esc_url(wc_get_checkout_url()),
                'user_first_name'   => esc_js($current_user->first_name),
                'user_last_name'    => esc_js($current_user->last_name),
                'user_email'        => esc_js($current_user->user_email),
                'user_phone'        => esc_js((string) get_user_meta($current_user->ID, 'billing_phone', true)),
                'already_consented' => $already_consented,
                'ask_every_time'    => (int) get_option( 'wcca_ask_consent_every_time', 0 ),
            ));
        }

    // ── Consent page (My Account endpoint) ───────────────────────────────────

    public static function render_consent_page(): void
    {
        $order_id = absint(get_query_var('order-consent'));

        if (!$order_id) {
            echo '<p>' . esc_html__('No order specified.', 'checkout-consent-for-woocommerce') . '</p>';
            return;
        }

        $order = wc_get_order($order_id);

        if (!$order || (int) $order->get_customer_id() !== get_current_user_id()) {
            echo '<p>' . esc_html__('Access denied.', 'checkout-consent-for-woocommerce') . '</p>';
            return;
        }

        $existing = WCCA_Database::get_by_order($order_id);

        $data = array(
            'first_name' => $order->get_billing_first_name(),
            'last_name' => $order->get_billing_last_name(),
            'email' => $order->get_billing_email(),
            'phone' => $order->get_billing_phone(),
            'address' => implode(', ', array_filter(array(
                $order->get_billing_address_1(),
                $order->get_billing_address_2(),
                $order->get_billing_city(),
                $order->get_billing_state(),
                $order->get_billing_postcode(),
                $order->get_billing_country(),
            ))),
        );

        include WCCA_PLUGIN_DIR . 'templates/consent-form.php';
    }

    // ── Persist checkout consent ──────────────────────────────────────────────
    // NOTE: checkout validation is handled by WCCA_Checkout_Validation — not duplicated here.

    /**
     * After WooCommerce creates the order, move the session consent data
     * into the database and generate the PDF.
     *
     * @param int      $order_id
     * @param array    $posted_data Raw $_POST checkout fields.
     * @param WC_Order $order
     */
    public static function save_checkout_consent_to_order(int $order_id, array $posted_data, WC_Order $order): void
    {
        if (!WC()->session) {
            return;
        }

        $consent = WC()->session->get('wcca_cart_consent');

        if (empty($consent['signed'])) {
            return;
        }

        // Prevent duplicate records
        if (WCCA_Database::get_by_order($order_id)) {
            return;
        }

        $sig_id = WCCA_Database::save_signature(array(
            'order_id' => $order_id,
            'customer_id' => (int) $order->get_customer_id(),
            'first_name' => $consent['first_name'] ?? $order->get_billing_first_name(),
            'last_name' => $consent['last_name'] ?? $order->get_billing_last_name(),
            'email' => $consent['email'] ?? $order->get_billing_email(),
            'phone' => $consent['phone'] ?? $order->get_billing_phone(),
            'address' => implode(', ', array_filter(array(
                $order->get_billing_address_1(),
                $order->get_billing_city(),
                $order->get_billing_state(),
                $order->get_billing_postcode(),
                $order->get_billing_country(),
            ))),
            'signature' => $consent['signature'],
        ));

        if ($sig_id) {
            WCCA_Database::log_action($sig_id, 'signed');

            $pdf_path = WCCA_PDF_Generator::generate($sig_id);
            if ($pdf_path) {
                WCCA_Database::update_pdf_path($sig_id, $pdf_path);
                WCCA_Database::log_action($sig_id, 'pdf_generated');
            }

            // Clear session so the consent cannot be reused on a subsequent order
            WC()->session->__unset('wcca_cart_consent');
        }
    }

    // ── Cart / checkout modal ─────────────────────────────────────────────────

    /**
     * Render the consent modal HTML + styles into wp_footer.
     * Only outputs on cart and checkout pages.
     */
    public static function render_cart_consent_modal(): void
    {
        if (!is_cart() && !is_checkout()) {
            return;
        }

        $consent = WC()->session ? WC()->session->get('wcca_cart_consent') : null;
        $already_signed = !empty($consent['signed']);
        ?>
        <div id="wcca-modal-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="wcca-modal-title">
            <div id="wcca-modal">
                <div id="wcca-modal-header">
                    <h2 id="wcca-modal-title"><?php esc_html_e('Consent Required', 'checkout-consent-for-woocommerce'); ?></h2>
                    <p><?php esc_html_e('Please review and sign below to continue.', 'checkout-consent-for-woocommerce'); ?></p>
                </div>

                <?php if ($already_signed): ?>
                    <div class="wcca-modal-success">
                        <div style="font-size:48px;margin-bottom:8px;">✅</div>
                        <strong><?php esc_html_e('Consent Already Signed', 'checkout-consent-for-woocommerce'); ?></strong>
                        <p><?php esc_html_e('Your consent for this session has been recorded.', 'checkout-consent-for-woocommerce'); ?></p>
                        <button id="wcca-modal-proceed" class="wcca-modal-btn-primary" type="button">
                            <?php esc_html_e('Continue to Checkout', 'checkout-consent-for-woocommerce'); ?>
                        </button>
                    </div>
                    <?php if (is_checkout()): ?>
                    <script>
                    jQuery(function($){
                        // Session consent is valid — pre-set hidden field and unlock Place Order immediately
                        $('#wcca_checkout_consent').val('1');
                        $('#place_order').prop('disabled', false).removeClass('wcca-consent-pending');
                    });
                    </script>
                    <?php endif; ?>
                <?php else: ?>
                    <form id="wcca-cart-consent-form" novalidate>

                        <div class="wcca-modal-section">
                            <h3>
                                <?php esc_html_e('Your Information', 'checkout-consent-for-woocommerce'); ?>
                                <span class="wcca-auto-tag"><?php esc_html_e('Auto-filled', 'checkout-consent-for-woocommerce'); ?></span>
                            </h3>
                            <div class="wcca-modal-grid">
                                <div class="wcca-modal-field">
                                    <label
                                        for="wcca-c-firstname"><?php esc_html_e('First Name', 'checkout-consent-for-woocommerce'); ?></label>
                                    <input type="text" id="wcca-c-firstname" name="first_name" autocomplete="given-name" required>
                                </div>
                                <div class="wcca-modal-field">
                                    <label for="wcca-c-lastname"><?php esc_html_e('Last Name', 'checkout-consent-for-woocommerce'); ?></label>
                                    <input type="text" id="wcca-c-lastname" name="last_name" autocomplete="family-name" required>
                                </div>
                                <div class="wcca-modal-field">
                                    <label for="wcca-c-email"><?php esc_html_e('Email', 'checkout-consent-for-woocommerce'); ?></label>
                                    <input type="email" id="wcca-c-email" name="email" autocomplete="email" required>
                                </div>
                                <div class="wcca-modal-field">
                                    <label for="wcca-c-phone"><?php esc_html_e('Phone', 'checkout-consent-for-woocommerce'); ?></label>
                                    <input type="tel" id="wcca-c-phone" name="phone" autocomplete="tel">
                                </div>
                            </div>
                        </div>

                        <div class="wcca-modal-section">
                            <h3><?php esc_html_e('Consent Declaration', 'checkout-consent-for-woocommerce'); ?></h3>
                            <?php

                            $template = get_option(
                                'wcca_consent_template',
                                ''
                            );

                            $customer_name = 'Customer';

                            if (is_user_logged_in()) {

                                $user = wp_get_current_user();

                                $customer_name =
                                    trim(
                                        $user->first_name . ' ' . $user->last_name
                                    );
                            }

                            $cart_total = '';

                            if (WC()->cart) {

                                $cart_total = WC()->cart->get_cart_total();
                            }

                            $template = str_replace(
                                '{customer_name}',
                                esc_html($customer_name),
                                $template
                            );

                            $template = str_replace(
                                '{cart_total}',
                                wp_kses_post($cart_total),
                                $template
                            );

                            ?>

                            <div class="wcca-consent-text">
                                <?php echo wp_kses_post($template); ?>
                            </div>
                        </div>

                        <div class="wcca-modal-section">
                            <h3>
                                <?php esc_html_e('Digital Signature', 'checkout-consent-for-woocommerce'); ?>
                                <span class="wcca-required"><?php esc_html_e('Required', 'checkout-consent-for-woocommerce'); ?></span>
                            </h3>
                            <p class="wcca-hint">
                                <?php esc_html_e('Draw your signature using your mouse or finger.', 'checkout-consent-for-woocommerce'); ?></p>
                            <div class="wcca-modal-sig-container" id="wcca-modal-sig-wrap" role="img"
                                aria-label="<?php esc_attr_e('Signature drawing area', 'checkout-consent-for-woocommerce'); ?>">
                                <canvas id="wcca-modal-canvas"></canvas>
                                <div class="wcca-sig-placeholder" id="wcca-modal-placeholder" aria-hidden="true">
                                    <span>✍</span>
                                    <?php esc_html_e('Sign here', 'checkout-consent-for-woocommerce'); ?>
                                </div>
                            </div>
                            <div class="wcca-sig-actions">
                                <button type="button" id="wcca-modal-clear" class="wcca-modal-btn-outline"
                                    aria-label="<?php esc_attr_e('Clear signature', 'checkout-consent-for-woocommerce'); ?>">
                                    <?php esc_html_e('Clear', 'checkout-consent-for-woocommerce'); ?>
                                </button>
                                <span id="wcca-modal-sig-status" class="wcca-sig-status" aria-live="polite"></span>
                            </div>
                        </div>

                        <input type="hidden" id="wcca-modal-sig-data" name="signature" value="">

                        <div id="wcca-modal-error" class="wcca-modal-error" style="display:none;" role="alert"></div>

                        <div class="wcca-modal-footer">
                            <button type="button" id="wcca-modal-cancel" class="wcca-modal-btn-outline">
                                <?php esc_html_e('Cancel', 'checkout-consent-for-woocommerce'); ?>
                            </button>
                            <button type="submit" id="wcca-modal-submit" class="wcca-modal-btn-primary" disabled>
                                <?php esc_html_e('Sign & Continue', 'checkout-consent-for-woocommerce'); ?>
                            </button>
                        </div>

                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
