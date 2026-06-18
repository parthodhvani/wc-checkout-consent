<?php
/**
 * Uninstall handler for WooCommerce Checkout Consent.
 *
 * Removes plugin settings on uninstall. Signature/consent records are
 * intentionally preserved because they may be legally significant; remove the
 * `{prefix}wcca_signatures` and `{prefix}wcca_consent_logs` tables manually if
 * you also want to delete that data.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$wcca_options = array(
    'wcca_consent_template',
    'wcca_enable_consent',
    'wcca_require_signature',
    'wcca_generate_pdf',
    'wcca_attach_pdf',
    'wcca_autofill_customer',
    'wcca_ask_consent_every_time',
);

foreach ( $wcca_options as $wcca_option ) {
    delete_option( $wcca_option );
}
