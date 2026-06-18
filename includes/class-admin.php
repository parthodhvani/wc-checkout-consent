<?php
defined( 'ABSPATH' ) || exit;

/**
 * WCCA_Admin
 *
 * Registers the WooCommerce sub-menu page, enqueues admin assets, and
 * renders the customer list and customer detail views.
 */
class WCCA_Admin {

    public static function init(): void {
        add_action( 'admin_menu',            array( __CLASS__, 'add_menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'admin_init',            array( __CLASS__, 'register_settings' ) );
    }

    public static function register_settings(): void {
        // ── Consent Template — own group so saving it NEVER touches checkboxes ──
        register_setting(
            'wcca_template_group',
            'wcca_consent_template',
            array(
                'sanitize_callback' => array( __CLASS__, 'sanitize_consent_template' ),
            )
        );

        // ── General Settings — own group so saving NEVER clears the template ──
        $bool_args = array(
            'type'              => 'boolean',
            'sanitize_callback' => array( __CLASS__, 'sanitize_checkbox' ),
            'default'           => 0,
        );
        register_setting( 'wcca_settings_group', 'wcca_enable_consent', $bool_args );
        register_setting( 'wcca_settings_group', 'wcca_require_signature', $bool_args );
        register_setting( 'wcca_settings_group', 'wcca_generate_pdf', $bool_args );
        register_setting( 'wcca_settings_group', 'wcca_attach_pdf', $bool_args );
        register_setting( 'wcca_settings_group', 'wcca_autofill_customer', $bool_args );
        register_setting( 'wcca_settings_group', 'wcca_ask_consent_every_time', $bool_args );
    }

    /**
     * Normalise a checkbox setting to 1 or 0.
     *
     * @param mixed $value Raw submitted value.
     * @return int
     */
    public static function sanitize_checkbox( $value ): int {
        return ! empty( $value ) ? 1 : 0;
    }

    public static function sanitize_consent_template( $value ): string {
        return wp_kses_post( $value );
    }

    // ── Menu ──────────────────────────────────────────────────────────────────

    public static function add_menu(): void {
        add_menu_page(
            'Checkout Consent',
            'Checkout Consent',
            'manage_woocommerce',
            'wcca-customer-affairs',
            array( __CLASS__, 'render_page' ),
            'dashicons-clipboard',
            56
        );

        // Dashboard
        add_submenu_page(
            'wcca-customer-affairs',
            'Dashboard',
            'Dashboard',
            'manage_woocommerce',
            'wcca-customer-affairs',
            array( __CLASS__, 'render_page' )
        );

        // Consent Template
        add_submenu_page(
            'wcca-customer-affairs',
            'Consent Template',
            'Consent Template',
            'manage_woocommerce',
            'wcca-consent-template',
            array( __CLASS__, 'render_consent_template_page' )
        );

        // Settings
        add_submenu_page(
            'wcca-customer-affairs',
            'Settings',
            'Settings',
            'manage_woocommerce',
            'wcca-settings',
            array( __CLASS__, 'render_settings_page' )
        );

        // FIX: Export / Import page was never registered
        add_submenu_page(
            'wcca-customer-affairs',
            'Export / Import',
            'Export / Import',
            'manage_woocommerce',
            'wcca-export-import',
            array( __CLASS__, 'render_export_import_page' )
        );

        // Feedback & Support
        add_submenu_page(
            'wcca-customer-affairs',
            'Feedback & Support',
            'Feedback & Support',
            'manage_woocommerce',
            'wcca-feedback-support',
            array( __CLASS__, 'render_feedback_support_page' )
        );
    }

    // ── Assets ────────────────────────────────────────────────────────────────

    public static function enqueue_assets( string $hook ): void {
        if (
            strpos( $hook, 'wcca' ) === false &&
            strpos( $hook, 'checkout-consent' ) === false
        ) {
            return;
        }

        wp_enqueue_style(
            'wcca-admin',
            WCCA_PLUGIN_URL . 'assets/css/admin.css',
            [],
            filemtime( WCCA_PLUGIN_PATH . 'assets/css/admin.css' )
        );

        wp_enqueue_script(
            'wcca-admin',
            WCCA_PLUGIN_URL . 'assets/js/admin.js',
            [ 'jquery' ],
            WCCA_VERSION,
            true
        );

        wp_localize_script(
            'wcca-admin',
            'wcca',
            [
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'wcca_nonce' ),
            ]
        );
    }

    // ── Page dispatcher ───────────────────────────────────────────────────────

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'checkout-consent-for-woocommerce' ) );
        }

        // Read-only navigation parameters for an admin screen; no state is changed,
        // so a nonce is not applicable. Values are fully sanitized below.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $view        = isset( $_GET['wcca_view'] ) ? sanitize_key( wp_unslash( $_GET['wcca_view'] ) ) : 'list';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $customer_id = isset( $_GET['customer_id'] ) ? absint( wp_unslash( $_GET['customer_id'] ) ) : 0;
        ?>
        <div class="wcca-wrap">
            <div class="wcca-header">
                <div class="wcca-header-inner">
                    <h1><?php esc_html_e( 'Customer Affairs', 'checkout-consent-for-woocommerce' ); ?></h1>
                    <p><?php esc_html_e( 'Manage customer consents, signatures and purchase history.', 'checkout-consent-for-woocommerce' ); ?></p>
                </div>
            </div>

            <?php if ( $view === 'detail' && $customer_id ) : ?>
                <?php self::render_customer_detail( $customer_id ); ?>
            <?php else : ?>
                <?php self::render_customer_list(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    // ── Consent Template page ─────────────────────────────────────────────────

    public static function render_consent_template_page(): void {
        $default_template = '
<div style="font-family:Arial,sans-serif;line-height:1.8;color:#333;">

    <h2>Checkout Consent Agreement</h2>

    <p>
        Before completing this purchase, please review and acknowledge the
        following statements. Your consent confirms that you understand and
        agree to the terms outlined below.
    </p>

    <ul>
        <li>I confirm that the information provided during checkout is accurate and complete.</li>
        <li>I voluntarily authorize this purchase and understand the products or services included in my order.</li>
        <li>I agree to the website Terms &amp; Conditions and Privacy Policy applicable to this transaction.</li>
        <li>I understand that my consent and electronic signature will be securely stored as proof of authorization.</li>
        <li>I acknowledge that this electronic signature has the same legal effect as a handwritten signature where permitted by law.</li>
        <li>I understand that a copy of this consent may be generated and linked with my order for future reference.</li>
    </ul>

    <p><strong>Customer Name:</strong> {customer_name}</p>
    <p><strong>Email Address:</strong> {customer_email}</p>
    <p><strong>Order Total:</strong> {cart_total}</p>

    <p style="margin-top:25px;">
        By signing below, I confirm that I have read, understood and voluntarily
        agree to the statements above.
    </p>

</div>';

        $template = get_option( 'wcca_consent_template' );
        if ( empty( $template ) ) {
            $template = $default_template;
        }
        ?>

        <?php // FIX: removed duplicate <h1> that was copy-pasted inside the card ?>
        <div class="wrap wcca-settings-page">

            <div class="wcca-header">
                <h1>📄 Consent Template</h1>
                <p>
                    Customize the consent agreement displayed to customers before they
                    provide their signature during checkout.
                </p>
            </div>

            <form method="post" action="options.php">

                <?php settings_fields( 'wcca_template_group' ); ?>

                <div class="wcca-section-card" style="margin-bottom:20px;">
                    <h2 style="margin-top:0;">ℹ️ About This Template</h2>
                    <p>
                        Every installation includes a professionally structured consent
                        template built directly into the plugin. You can start using it
                        immediately without making any changes.
                    </p>
                    <p>
                        If you decide to customize the wording, simply edit the content
                        below and save your changes. Once saved, your custom version will
                        automatically replace the built-in default for all future checkouts.
                    </p>
                </div>

                <div class="wcca-section-card" style="margin-bottom:20px;">
                    <h2 style="margin-top:0;">🏷️ Available Placeholders</h2>
                    <p>Use the following variables anywhere in your template. They will be replaced automatically when displayed to the customer.</p>
                    <div class="wcca-placeholder-grid">
                        <span class="wcca-placeholder">
                            <code>{customer_name}</code>
                            <small>Customer Full Name</small>
                        </span>
                        <span class="wcca-placeholder">
                            <code>{customer_email}</code>
                            <small>Customer Email</small>
                        </span>
                        <span class="wcca-placeholder">
                            <code>{cart_total}</code>
                            <small>Current Order Total</small>
                        </span>
                    </div>
                </div>

                <div class="wcca-section-card">
                    <?php
                    wp_editor(
                        $template,
                        'wcca_consent_template',
                        array(
                            'textarea_name' => 'wcca_consent_template',
                            'textarea_rows' => 18,
                            'media_buttons' => false,
                            'teeny'         => false,
                        )
                    );
                    ?>
                </div>

                <?php submit_button( 'Save Consent Template' ); ?>

            </form>

        </div>
        <?php
    }

    // ── Settings page ─────────────────────────────────────────────────────────

    public static function render_settings_page(): void {
        ?>
        <div class="wrap wcca-settings-page">

            <div class="wcca-header">
                <h1>⚙️ Checkout Consent Settings</h1>
                <p>Configure how the consent form behaves during checkout.</p>
            </div>

            <form method="post" action="options.php">

                <?php settings_fields( 'wcca_settings_group' ); ?>

                <div class="wcca-section-card">
                    <table class="form-table">

                        <tr>
                            <th>Enable Consent Form</th>
                            <td>
                                <label>
                                    <input type="checkbox"
                                        name="wcca_enable_consent"
                                        value="1"
                                        <?php checked( get_option( 'wcca_enable_consent', 1 ), 1 ); ?>>
                                    Show consent form on checkout
                                </label>
                            </td>
                        </tr>

                        <tr>
                            <th>Require Signature</th>
                            <td>
                                <label>
                                    <input type="checkbox"
                                        name="wcca_require_signature"
                                        value="1"
                                        <?php checked( get_option( 'wcca_require_signature', 1 ), 1 ); ?>>
                                    Customer must draw signature
                                </label>
                            </td>
                        </tr>

                        <?php // FIX: new setting — controls whether consent persists in the session ?>
                        <tr>
                            <th>
                                Ask for Consent Every Time
                                <p class="description" style="font-weight:normal;margin-top:4px;">
                                    When <strong>unchecked</strong> (default): if a customer has already
                                    signed during this browser session they will not be asked again.<br>
                                    When <strong>checked</strong>: the consent form will appear on every
                                    checkout, regardless of previous session consent.
                                </p>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox"
                                        name="wcca_ask_consent_every_time"
                                        value="1"
                                        <?php checked( get_option( 'wcca_ask_consent_every_time', 0 ), 1 ); ?>>
                                    Always ask for consent, even if already signed this session
                                </label>
                            </td>
                        </tr>

                        <tr>
                            <th>Generate PDF</th>
                            <td>
                                <label>
                                    <input type="checkbox"
                                        name="wcca_generate_pdf"
                                        value="1"
                                        <?php checked( get_option( 'wcca_generate_pdf', 1 ), 1 ); ?>>
                                    Automatically generate consent PDF
                                </label>
                            </td>
                        </tr>

                        <tr>
                            <th>Attach PDF to Order</th>
                            <td>
                                <label>
                                    <input type="checkbox"
                                        name="wcca_attach_pdf"
                                        value="1"
                                        <?php checked( get_option( 'wcca_attach_pdf', 1 ), 1 ); ?>>
                                    Save generated PDF with WooCommerce order
                                </label>
                            </td>
                        </tr>

                        <tr>
                            <th>Auto Fill Customer Information</th>
                            <td>
                                <label>
                                    <input type="checkbox"
                                        name="wcca_autofill_customer"
                                        value="1"
                                        <?php checked( get_option( 'wcca_autofill_customer', 1 ), 1 ); ?>>
                                    Pre-fill customer details from billing information
                                </label>
                            </td>
                        </tr>

                    </table>
                </div>

                <?php submit_button( 'Save Settings' ); ?>

            </form>

        </div>
        <?php
    }

    // ── Export / Import page ──────────────────────────────────────────────────
    // FIX: this entire method was missing — the class existed but had no admin UI

    public static function render_export_import_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'checkout-consent-for-woocommerce' ) );
        }

        // Show import result notice if one was stored in a transient
        $result = get_transient( 'wcca_import_result_' . get_current_user_id() );
        if ( $result ) {
            delete_transient( 'wcca_import_result_' . get_current_user_id() );
        }
        ?>
        <div class="wrap wcca-settings-page">

            <div class="wcca-header">
                <h1>📤 Export / Import</h1>
                <p>Export all consent records to CSV or JSON, or import records from a previously exported CSV file.</p>
            </div>

            <?php if ( $result ) : ?>
                <div class="notice notice-<?php echo esc_attr( $result['type'] === 'success' ? 'success' : 'error' ); ?> is-dismissible">
                    <p><?php echo esc_html( $result['message'] ); ?></p>
                </div>
            <?php endif; ?>

            <?php // ── Export ── ?>
            <div class="wcca-section-card" style="margin-bottom:24px;">
                <h2 style="margin-top:0;">⬇️ Export Consent Records</h2>
                <p>Download all stored consent records as a file. The export includes every record except signature image data.</p>

                <form method="post">
                    <?php wp_nonce_field( 'wcca_export_nonce', 'wcca_export_nonce' ); ?>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-top:16px;">
                        <button type="submit" name="wcca_export_format" value="csv" class="button button-primary">
                            ⬇ Export as CSV
                        </button>
                        <button type="submit" name="wcca_export_format" value="json" class="button button-secondary">
                            ⬇ Export as JSON
                        </button>
                    </div>
                </form>
            </div>

            <?php // ── Import ── ?>
            <div class="wcca-section-card">
                <h2 style="margin-top:0;">⬆️ Import Consent Records</h2>
                <p>
                    Upload a CSV file exported by this plugin. Records that already exist (matched by <code>order_id</code>) will be skipped.
                </p>

                <div class="notice notice-info inline" style="margin:12px 0;padding:12px 16px;">
                    <strong>Required CSV columns:</strong>
                    <code>order_id</code>, <code>customer_id</code>, <code>first_name</code>,
                    <code>last_name</code>, <code>email</code>, <code>phone</code>,
                    <code>address</code>, <code>signed_at</code>
                </div>

                <form method="post" enctype="multipart/form-data" style="margin-top:16px;">
                    <?php wp_nonce_field( 'wcca_import_nonce', 'wcca_import_nonce' ); ?>
                    <table class="form-table" style="max-width:600px;">
                        <tr>
                            <th style="width:140px;"><label for="wcca_import_file">CSV File</label></th>
                            <td>
                                <input type="file"
                                       id="wcca_import_file"
                                       name="wcca_import_file"
                                       accept=".csv,text/csv"
                                       required>
                                <p class="description">Only <code>.csv</code> files exported by this plugin are supported.</p>
                            </td>
                        </tr>
                    </table>
                    <p>
                        <button type="submit" name="wcca_import_submit" value="1" class="button button-primary">
                            ⬆ Import CSV
                        </button>
                    </p>
                </form>
            </div>

        </div>
        <?php
    }

    // ── Feedback & Support page ───────────────────────────────────────────────

    public static function render_feedback_support_page(): void {
        ?>
        <div class="wrap wcca-feedback-page">

            <div class="wcca-hero">
                <h1>🚀 Help Shape the Future</h1>
                <p>
                    Checkout Consent is built with the community in mind.
                    Every suggestion, feature request, and improvement idea helps make
                    the plugin better for everyone using it.
                </p>
            </div>

            <div class="wcca-grid">

                <div class="wcca-card">
                    <h3>💡 Have an Idea?</h3>
                    <p>If you've thought of a feature that could save time or improve your workflow, we'd genuinely love to hear it.</p>
                    <ul>
                        <li>New functionality</li>
                        <li>UI &amp; UX improvements</li>
                        <li>Automation ideas</li>
                        <li>Workflow enhancements</li>
                    </ul>
                </div>

                <div class="wcca-card">
                    <h3>🐞 Found Something?</h3>
                    <p>Bugs, compatibility issues, or unexpected behavior can happen. Reporting them helps us deliver a better experience for everyone.</p>
                    <ul>
                        <li>Bug reports</li>
                        <li>WooCommerce compatibility</li>
                        <li>Theme conflicts</li>
                        <li>Performance improvements</li>
                    </ul>
                </div>

                <div class="wcca-card">
                    <h3>✨ Need Something Custom?</h3>
                    <p>Every business has different requirements. If you need custom workflows, integrations, or tailored functionality, we're happy to discuss what's possible.</p>
                    <ul>
                        <li>Custom consent flows</li>
                        <li>Third-party integrations</li>
                        <li>Advanced checkout logic</li>
                        <li>Business-specific solutions</li>
                    </ul>
                </div>

            </div>

            <div class="wcca-contact">
                <h2>Let's Build Something Better Together</h2>
                <p>
                    Your feedback directly influences future updates. Whether it's a
                    small tweak, a big feature idea, or a custom requirement, we'd love
                    to hear from you.
                </p>
                <a href="mailto:parthodhvani010@gmail.com" class="wcca-btn-main">
                    Contact Support
                </a>
                <div class="wcca-footer-note">
                    We aim to review every message and appreciate you taking the time to share your thoughts.
                </div>
            </div>

        </div>
        <?php
    }

    // ── Customer list ─────────────────────────────────────────────────────────

    private static function render_customer_list(): void {
        global $wpdb;

        $customers = get_users( array(
            'orderby' => 'registered',
            'order'   => 'DESC',
            'number'  => 50,
        ) );

        $orders_page  = wc_get_orders( array( 'limit' => 1, 'paginate' => true, 'return' => 'ids' ) );
        $total_orders = isset( $orders_page->total ) ? (int) $orders_page->total : 0;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery
        $total_signed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wcca_signatures" );
        $this_month   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wcca_signatures WHERE MONTH(signed_at) = MONTH(NOW()) AND YEAR(signed_at) = YEAR(NOW())" );
        // phpcs:enable
        ?>

        <div class="wcca-stats-bar">
            <div class="wcca-stat-card">
                <span class="stat-number"><?php echo esc_html( number_format( $total_orders ) ); ?></span>
                <span class="stat-label"><?php esc_html_e( 'Total Orders', 'checkout-consent-for-woocommerce' ); ?></span>
            </div>
            <div class="wcca-stat-card">
                <span class="stat-number"><?php echo esc_html( number_format( $total_signed ) ); ?></span>
                <span class="stat-label"><?php esc_html_e( 'Signed Consents', 'checkout-consent-for-woocommerce' ); ?></span>
            </div>
            <div class="wcca-stat-card">
                <span class="stat-number"><?php echo esc_html( number_format( $this_month ) ); ?></span>
                <span class="stat-label"><?php esc_html_e( 'Signed This Month', 'checkout-consent-for-woocommerce' ); ?></span>
            </div>
            <div class="wcca-stat-card">
                <span class="stat-number"><?php echo esc_html( number_format( count( $customers ) ) ); ?></span>
                <span class="stat-label"><?php esc_html_e( 'Customers', 'checkout-consent-for-woocommerce' ); ?></span>
            </div>
        </div>

        <div class="wcca-search-bar">
            <input type="text"
                   id="wcca-search"
                   placeholder="<?php esc_attr_e( 'Search by name, email or order #…', 'checkout-consent-for-woocommerce' ); ?>"
                   aria-label="<?php esc_attr_e( 'Search customers', 'checkout-consent-for-woocommerce' ); ?>">
        </div>

        <div class="wcca-table-wrap">
            <table class="wcca-table" id="wcca-customers-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Customer', 'checkout-consent-for-woocommerce' ); ?></th>
                        <th><?php esc_html_e( 'Email', 'checkout-consent-for-woocommerce' ); ?></th>
                        <th><?php esc_html_e( 'Registered', 'checkout-consent-for-woocommerce' ); ?></th>
                        <th><?php esc_html_e( 'Orders', 'checkout-consent-for-woocommerce' ); ?></th>
                        <th><?php esc_html_e( 'Total Spent', 'checkout-consent-for-woocommerce' ); ?></th>
                        <th><?php esc_html_e( 'Last Signed', 'checkout-consent-for-woocommerce' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'checkout-consent-for-woocommerce' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $customers as $customer ) :
                        $customer_orders = wc_get_orders( array(
                            'customer_id' => $customer->ID,
                            'limit'       => -1,
                            'return'      => 'objects',
                            'status'      => array_keys( wc_get_order_statuses() ),
                        ) );

                        $order_count = count( $customer_orders );
                        $total_spent = 0.0;
                        foreach ( $customer_orders as $o ) {
                            if ( in_array( $o->get_status(), array( 'completed', 'processing' ), true ) ) {
                                $total_spent += (float) $o->get_total();
                            }
                        }

                        // phpcs:disable WordPress.DB.DirectDatabaseQuery
                        $last_signed = $wpdb->get_var( $wpdb->prepare(
                            "SELECT signed_at FROM {$wpdb->prefix}wcca_signatures WHERE customer_id = %d ORDER BY signed_at DESC LIMIT 1",
                            $customer->ID
                        ) );
                        // phpcs:enable

                        $search_str = strtolower( $customer->display_name . ' ' . $customer->user_email );
                        ?>
                        <tr data-search="<?php echo esc_attr( $search_str ); ?>">
                            <td>
                                <div class="wcca-customer-cell">
                                    <?php echo get_avatar( $customer->ID, 34 ); ?>
                                    <span><?php echo esc_html( $customer->display_name ); ?></span>
                                </div>
                            </td>
                            <td><?php echo esc_html( $customer->user_email ); ?></td>
                            <td><?php echo esc_html( wp_date( 'M j, Y', strtotime( $customer->user_registered ) ) ); ?></td>
                            <td><span class="wcca-badge"><?php echo esc_html( $order_count ); ?></span></td>
                            <td><strong><?php echo wp_kses_post( wc_price( $total_spent ) ); ?></strong></td>
                            <td>
                                <?php if ( $last_signed ) : ?>
                                    <span class="wcca-tag signed">✓ <?php echo esc_html( wp_date( 'M j, Y', strtotime( $last_signed ) ) ); ?></span>
                                <?php else : ?>
                                    <span class="wcca-tag unsigned"><?php esc_html_e( '— Not signed', 'checkout-consent-for-woocommerce' ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?php echo esc_url( add_query_arg( array( 'wcca_view' => 'detail', 'customer_id' => $customer->ID ) ) ); ?>"
                                   class="wcca-btn wcca-btn-primary">
                                    <?php esc_html_e( 'View', 'checkout-consent-for-woocommerce' ); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    // ── Customer detail ───────────────────────────────────────────────────────

    private static function render_customer_detail( int $customer_id ): void {
        $user = get_userdata( $customer_id );

        if ( ! $user ) {
            echo '<p>' . esc_html__( 'Customer not found.', 'checkout-consent-for-woocommerce' ) . '</p>';
            return;
        }

        $customer   = new WC_Customer( $customer_id );
        $orders     = wc_get_orders( array( 'customer_id' => $customer_id, 'limit' => -1 ) );
        $signatures = WCCA_Database::get_by_customer( $customer_id );

        $total_spent = 0.0;
        foreach ( $orders as $o ) {
            if ( in_array( $o->get_status(), array( 'completed', 'processing' ), true ) ) {
                $total_spent += (float) $o->get_total();
            }
        }
        ?>
        <div class="wcca-detail-wrap">
            <a href="<?php echo esc_url( remove_query_arg( array( 'wcca_view', 'customer_id' ) ) ); ?>"
               class="wcca-back">
                ← <?php esc_html_e( 'Back to Customers', 'checkout-consent-for-woocommerce' ); ?>
            </a>

            <div class="wcca-detail-grid">

                <!-- Profile Card -->
                <div class="wcca-profile-card">
                    <div class="wcca-avatar-lg">
                        <?php echo get_avatar( $customer_id, 80 ); ?>
                    </div>
                    <h2><?php echo esc_html( $user->display_name ); ?></h2>
                    <p class="wcca-email"><?php echo esc_html( $user->user_email ); ?></p>

                    <div class="wcca-profile-meta">
                        <div>
                            <label><?php esc_html_e( 'Phone', 'checkout-consent-for-woocommerce' ); ?></label>
                            <span><?php echo esc_html( $customer->get_billing_phone() ?: '—' ); ?></span>
                        </div>
                        <div>
                            <label><?php esc_html_e( 'Member Since', 'checkout-consent-for-woocommerce' ); ?></label>
                            <span><?php echo esc_html( wp_date( 'M j, Y', strtotime( $user->user_registered ) ) ); ?></span>
                        </div>
                        <div>
                            <label><?php esc_html_e( 'Orders', 'checkout-consent-for-woocommerce' ); ?></label>
                            <span><?php echo esc_html( count( $orders ) ); ?></span>
                        </div>
                        <div>
                            <label><?php esc_html_e( 'Total Spent', 'checkout-consent-for-woocommerce' ); ?></label>
                            <span><?php echo wp_kses_post( wc_price( $total_spent ) ); ?></span>
                        </div>
                    </div>

                    <?php $addr1 = $customer->get_billing_address_1(); if ( $addr1 ) : ?>
                    <div class="wcca-address-block">
                        <label><?php esc_html_e( 'Billing Address', 'checkout-consent-for-woocommerce' ); ?></label>
                        <address>
                            <?php echo esc_html( $addr1 ); ?><br>
                            <?php if ( $customer->get_billing_address_2() ) : ?>
                                <?php echo esc_html( $customer->get_billing_address_2() ); ?><br>
                            <?php endif; ?>
                            <?php echo esc_html( $customer->get_billing_city() ); ?>,
                            <?php echo esc_html( $customer->get_billing_state() ); ?>
                            <?php echo esc_html( $customer->get_billing_postcode() ); ?><br>
                            <?php echo esc_html( $customer->get_billing_country() ); ?>
                        </address>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Right column -->
                <div class="wcca-detail-main">

                    <!-- Order History -->
                    <div class="wcca-section-card">
                        <h3>📦 <?php esc_html_e( 'Order History', 'checkout-consent-for-woocommerce' ); ?></h3>

                        <?php if ( empty( $orders ) ) : ?>
                            <div class="wcca-empty">
                                <div class="wcca-empty-icon">🛒</div>
                                <p><?php esc_html_e( 'No orders found for this customer.', 'checkout-consent-for-woocommerce' ); ?></p>
                            </div>
                        <?php else : ?>
                        <table class="wcca-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Order', 'checkout-consent-for-woocommerce' ); ?></th>
                                    <th><?php esc_html_e( 'Date', 'checkout-consent-for-woocommerce' ); ?></th>
                                    <th><?php esc_html_e( 'Status', 'checkout-consent-for-woocommerce' ); ?></th>
                                    <th><?php esc_html_e( 'Items', 'checkout-consent-for-woocommerce' ); ?></th>
                                    <th><?php esc_html_e( 'Total', 'checkout-consent-for-woocommerce' ); ?></th>
                                    <th><?php esc_html_e( 'Consent', 'checkout-consent-for-woocommerce' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ( $orders as $order ) :
                                $sig = WCCA_Database::get_by_order( $order->get_id() );
                            ?>
                            <tr>
                                <td><strong>#<?php echo esc_html( $order->get_id() ); ?></strong></td>
                                <td><?php echo esc_html( wp_date( 'M j, Y', $order->get_date_created()->getTimestamp() ) ); ?></td>
                                <td>
                                    <span class="wcca-status <?php echo esc_attr( $order->get_status() ); ?>">
                                        <?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html( $order->get_item_count() ); ?></td>
                                <td><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></td>
                                <td>
                                    <?php if ( $sig ) : ?>
                                        <span class="wcca-tag signed">✓ <?php esc_html_e( 'Signed', 'checkout-consent-for-woocommerce' ); ?></span>
                                        <?php if ( ! empty( $sig->pdf_path ) && file_exists( $sig->pdf_path ) ) : ?>
                                            <a href="<?php echo esc_url( add_query_arg( array(
                                                    'action' => 'wcca_download_pdf',
                                                    'sig_id' => absint( $sig->id ),
                                                    'nonce'  => wp_create_nonce( 'wcca_pdf_' . absint( $sig->id ) ),
                                                ), admin_url( 'admin-ajax.php' ) ) ); ?>"
                                               class="wcca-btn wcca-btn-outline wcca-btn-sm">
                                                ↓ <?php esc_html_e( 'PDF', 'checkout-consent-for-woocommerce' ); ?>
                                            </a>
                                        <?php endif; ?>
                                    <?php else : ?>
                                        <span class="wcca-tag unsigned"><?php esc_html_e( 'Unsigned', 'checkout-consent-for-woocommerce' ); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>

                    <!-- Signature Timeline -->
                    <?php if ( ! empty( $signatures ) ) : ?>
                    <div class="wcca-section-card">
                        <h3>✍ <?php esc_html_e( 'Signature History', 'checkout-consent-for-woocommerce' ); ?></h3>
                        <div class="wcca-timeline">
                            <?php foreach ( $signatures as $sig ) : ?>
                            <div class="wcca-timeline-item">
                                <div class="wcca-timeline-dot"></div>
                                <div class="wcca-timeline-content">
                                    <div class="wcca-timeline-header">
                                        <strong>
                                            <?php
                                            printf(
                                                /* translators: %d: WooCommerce order ID. */
                                                esc_html__( 'Order #%d', 'checkout-consent-for-woocommerce' ),
                                                absint( $sig->order_id )
                                            );
                                            ?>
                                        </strong>
                                        <time datetime="<?php echo esc_attr( $sig->signed_at ); ?>">
                                            <?php echo esc_html( wp_date( 'M j, Y g:i A', strtotime( $sig->signed_at ) ) ); ?>
                                        </time>
                                    </div>
                                    <p>
                                        <?php
                                        printf(
                                            /* translators: 1: customer full name, 2: customer email address. */
                                            esc_html__( 'Signed by %1$s · %2$s', 'checkout-consent-for-woocommerce' ),
                                            esc_html( $sig->first_name . ' ' . $sig->last_name ),
                                            esc_html( $sig->email )
                                        );
                                        ?>
                                    </p>
                                    <?php if ( ! empty( $sig->signature ) ) : ?>
                                        <img src="<?php echo esc_attr( $sig->signature ); ?>"
                                             class="wcca-sig-preview"
                                             alt="<?php esc_attr_e( 'Signature', 'checkout-consent-for-woocommerce' ); ?>">
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php else : ?>
                    <div class="wcca-section-card">
                        <h3>✍ <?php esc_html_e( 'Signature History', 'checkout-consent-for-woocommerce' ); ?></h3>
                        <div class="wcca-empty">
                            <div class="wcca-empty-icon">📋</div>
                            <p><?php esc_html_e( 'No consent records found for this customer.', 'checkout-consent-for-woocommerce' ); ?></p>
                        </div>
                    </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>
        <?php
    }
}
