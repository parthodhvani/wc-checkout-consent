<?php
/**
 * Plugin Name:       Checkout Consent for WooCommerce
 * Plugin URI:        https://github.com/parthodhvani/wc-checkout-consent
 * Description:       Require customers to review and sign a digital consent form before checkout. Capture signatures, enforce consent acceptance, customize consent templates, and manage customer consent records from a dedicated dashboard.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * Author:            Parth Odhvani
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       checkout-consent-for-woocommerce
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'WCCA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCCA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WCCA_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'WCCA_VERSION', '1.2.0' );

// Autoload classes
require_once WCCA_PLUGIN_DIR . 'includes/class-database.php';
require_once WCCA_PLUGIN_DIR . 'includes/class-pdf-generator.php'; // PDF generation
require_once WCCA_PLUGIN_DIR . 'includes/class-admin.php';
require_once WCCA_PLUGIN_DIR . 'includes/class-order-page.php';
require_once WCCA_PLUGIN_DIR . 'includes/class-ajax-handler.php';
require_once WCCA_PLUGIN_DIR . 'includes/class-wcca-checkout-validation.php';
require_once WCCA_PLUGIN_DIR . 'includes/class-export-import.php'; // FIX: was missing

// Create DB tables on activation
register_activation_hook( __FILE__, array( 'WCCA_Database', 'create_tables' ) );

// Declare compatibility with WooCommerce High-Performance Order Storage (HPOS).
add_action( 'before_woocommerce_init', static function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

// Initialise plugin after WooCommerce is confirmed loaded
add_action( 'plugins_loaded', static function () {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return;
    }
    WCCA_Admin::init();
    WCCA_Order_Page::init();
    WCCA_Ajax_Handler::init();
    WCCA_Checkout_Validation::init();
    WCCA_Export_Import::init(); // FIX: was missing
} );

/**
 * Show a "Download Signed Consent PDF" button on the WooCommerce order-received
 * (thank-you) page immediately after the customer signs.
 * Appears only when a PDF has been generated for the order.
 */
add_action( 'woocommerce_thankyou', 'wcca_thankyou_pdf_download_button', 20 );

function wcca_thankyou_pdf_download_button( int $order_id ): void {
    if ( ! $order_id ) {
        return;
    }

    $sig = WCCA_Database::get_by_order( $order_id );

    if ( ! $sig ) {
        return;
    }

    // Build download URL
    $download_url = add_query_arg( [
        'action' => 'wcca_download_pdf',
        'sig_id' => $sig->id,
        'nonce'  => wp_create_nonce( 'wcca_pdf_' . $sig->id ),
    ], admin_url( 'admin-ajax.php' ) );

    ?>
    <div class="wcca-thankyou-pdf-wrap" style="
        margin: 24px 0;
        padding: 24px 28px;
        background: linear-gradient(135deg, #eef2ff 0%, #f0f9ff 100%);
        border: 1px solid #c7d8f9;
        border-left: 4px solid #405fe4;
        border-radius: 10px;
        display: flex;
        align-items: center;
        gap: 20px;
        flex-wrap: wrap;
    ">
        <div style="font-size: 36px; line-height: 1;">📄</div>
        <div style="flex: 1; min-width: 200px;">
            <strong style="display:block; font-size: 15px; color: #101827; margin-bottom: 4px;">
                Your Signed Consent is Ready
            </strong>
            <p style="margin: 0; font-size: 13px; color: #4b5563;">
                A legally binding PDF of your signed consent has been generated for Order #<?php echo esc_html( $order_id ); ?>.
                Download and keep it for your records.
            </p>
        </div>
        <a href="<?php echo esc_url( $download_url ); ?>"
           class="wcca-btn-download"
           style="
               display: inline-flex;
               align-items: center;
               gap: 8px;
               background: #405fe4;
               color: #fff;
               font-weight: 600;
               font-size: 13px;
               padding: 10px 20px;
               border-radius: 6px;
               text-decoration: none;
               white-space: nowrap;
               transition: background 0.2s;
           "
           onmouseover="this.style.background='#2f4ecb'"
           onmouseout="this.style.background='#405fe4'"
        >
            ⬇ Download Signed PDF
        </a>
    </div>
    <?php
}

/**
 * Render the "Review & Sign Consent" button on the checkout page.
 * Respects the wcca_enable_consent setting and the "ask every time" option.
 * If the user has already consented this session (and ask_every_time is off),
 * the button is replaced with a "✓ Consent already on file" notice.
 */
add_action( 'woocommerce_review_order_before_submit', 'wcca_checkout_consent_button' );

function wcca_checkout_consent_button(): void {
    // FIX: respect the Enable Consent setting
    if ( ! get_option( 'wcca_enable_consent', 1 ) ) {
        return;
    }

    $ask_every_time  = (bool) get_option( 'wcca_ask_consent_every_time', 0 );
    $session_consent = WC()->session ? WC()->session->get( 'wcca_cart_consent' ) : null;
    $already_signed  = ! empty( $session_consent['signed'] );

    // FIX: if user already consented this session and we don't force re-sign,
    //      pre-set the hidden field and show a soft confirmation instead of the button.
    if ( $already_signed && ! $ask_every_time ) {
        ?>
        <div id="wcca-checkout-consent-wrap">
            <input type="hidden"
                   id="wcca_checkout_consent"
                   name="wcca_checkout_consent"
                   value="1">
            <p id="wcca-consent-success" class="wcca-already-consented">
                ✓ Consent already on file &nbsp;
                <a href="#" id="wcca-re-sign-link" style="font-size:0.85em;">Re-sign</a>
            </p>
        </div>
        <?php
        return;
    }

    ?>
    <div id="wcca-checkout-consent-wrap">
        <button type="button" id="wcca-open-consent">
            ✍ Review &amp; Sign Consent
        </button>
        <input type="hidden"
               id="wcca_checkout_consent"
               name="wcca_checkout_consent"
               value="0">
        <p id="wcca-consent-success" style="display:none;">
            ✓ Consent signed
        </p>
    </div>
    <?php
}
