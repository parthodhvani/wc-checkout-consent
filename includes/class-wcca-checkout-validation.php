<?php
defined( 'ABSPATH' ) || exit;

class WCCA_Checkout_Validation {

    public static function init() {
        add_action( 'woocommerce_checkout_process', array( __CLASS__, 'validate_consent' ) );
        add_action( 'wp_footer',                    array( __CLASS__, 'disable_place_order_button' ) );
    }

    /**
     * Validate consent before order placement.
     *
     * Logic:
     *  - If the consent form is disabled in settings → skip entirely.
     *  - If "ask every time" is OFF and the customer has a valid session consent → allow through.
     *  - Otherwise, require the consent hidden field to be "1" AND the session to be set.
     */
    public static function validate_consent() {
        // FIX: respect the Enable Consent setting
        if ( ! get_option( 'wcca_enable_consent', 1 ) ) {
            return;
        }

        $ask_every_time  = (bool) get_option( 'wcca_ask_consent_every_time', 0 );
        $session_consent = WC()->session ? WC()->session->get( 'wcca_cart_consent' ) : array();
        $already_signed  = ! empty( $session_consent['signed'] );

        // FIX: if "ask every time" is off and the session already has consent, let them through.
        if ( ! $ask_every_time && $already_signed ) {
            return;
        }

        // WooCommerce verifies the checkout nonce before firing
        // woocommerce_checkout_process, so the value is already trusted here.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $consent_posted = isset( $_POST['wcca_checkout_consent'] )
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            ? sanitize_text_field( wp_unslash( $_POST['wcca_checkout_consent'] ) )
            : '';

        if ( empty( $already_signed ) || $consent_posted !== '1' ) {
            wc_add_notice(
                __( 'Please sign the consent form before placing your order.', 'checkout-consent-for-woocommerce' ),
                'error'
            );
            // Stop checkout flow
            throw new Exception( 'Consent required' );
        }
    }

    /**
     * Disable Place Order button until JS enables it.
     * Only injected when consent is actually required.
     */
    public static function disable_place_order_button() {
        if ( ! is_checkout() || is_order_received_page() ) {
            return;
        }

        // FIX: don't disable the button if consent feature is off
        if ( ! get_option( 'wcca_enable_consent', 1 ) ) {
            return;
        }

        // FIX: don't disable if user already consented and we don't ask every time
        $ask_every_time  = (bool) get_option( 'wcca_ask_consent_every_time', 0 );
        $session_consent = WC()->session ? WC()->session->get( 'wcca_cart_consent' ) : array();
        $already_signed  = ! empty( $session_consent['signed'] );

        if ( ! $ask_every_time && $already_signed ) {
            return; // button stays enabled — user has already consented
        }
        ?>
        <script>
            jQuery(function ($) {
                $('#place_order').prop('disabled', true);
            });
        </script>
        <?php
    }
}
