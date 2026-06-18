=== Checkout Consent for WooCommerce ===
Contributors: parthodhvani
Tags: woocommerce, consent, signature, checkout, gdpr
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Require customers to review and digitally sign a consent form before completing WooCommerce checkout, with a signed PDF record for every order.

== Description ==

Checkout Consent for WooCommerce adds a digital consent and signature step to your WooCommerce store. Before a customer can place an order, they review a customizable consent agreement and sign it with their mouse or finger. The signed consent is stored against the order and a PDF record is generated automatically.

**Key features**

* "Review & Sign Consent" button on the classic WooCommerce checkout.
* Mouse/touch signature pad powered by the bundled Signature Pad library.
* Customizable consent template with `{customer_name}`, `{customer_email}` and `{cart_total}` placeholders.
* Automatic generation of a signed-consent PDF (no external library required).
* "Download Signed Consent PDF" button on the order-received page and in My Account.
* Dedicated admin dashboard listing customers, orders and consent records.
* Export consent records to CSV/JSON and import from CSV.
* Audit log of consent actions (signed, PDF generated, downloaded).
* Compatible with WooCommerce High-Performance Order Storage (HPOS).

This plugin requires the WooCommerce plugin to be installed and active.

== External services ==

This plugin does not connect to any external services. All data, including signatures and generated PDFs, is stored on your own site.

== Installation ==

1. Upload the `woocommerce-checkout-consent` folder to the `/wp-content/plugins/` directory, or install the plugin through the **Plugins > Add New** screen in WordPress.
2. Activate the plugin through the **Plugins** screen.
3. Make sure WooCommerce is installed and active.
4. The classic checkout shortcode `[woocommerce_checkout]` must be used on the Checkout page so the consent step is displayed. If you use the block-based checkout, switch the Checkout page to the classic shortcode.
5. Configure the plugin under **Checkout Consent > Settings** and edit the agreement under **Checkout Consent > Consent Template**.

== Frequently Asked Questions ==

= Does this work with the block-based checkout? =

The consent step hooks into the classic WooCommerce checkout. Set your Checkout page to use the `[woocommerce_checkout]` shortcode to display the consent button.

= Where are the signed PDFs stored? =

PDFs are generated in `wp-content/uploads/wcca-consents/` with unguessable filenames. Downloads are additionally protected by a nonce and a capability check.

= Is a customer's consent reused across orders? =

By default, once a customer signs during a browser session they are not asked again until checkout completes. You can force the consent prompt on every checkout via **Settings > Ask for Consent Every Time**.

== Changelog ==

= 1.2.0 =
* Fixed: PDF generation now produces a complete, valid signed-consent document (previously an empty placeholder file was written).
* Fixed: customer signature image is now embedded in the generated PDF.
* Fixed: removed a deprecated `utf8_decode()` call for PHP 8.2+ compatibility.
* Fixed: corrected malformed markup and an escaped nonce field on the My Account consent form.
* Changed: unified the text domain to `woocommerce-checkout-consent`.
* Changed: removed remote Google Fonts requests; the UI now uses bundled/system fonts.
* Added: WooCommerce HPOS compatibility declaration.
* Added: `readme.txt` and `uninstall.php` for WordPress.org compliance.

== Upgrade Notice ==

= 1.2.0 =
Fixes PDF generation and signature embedding, improves PHP 8.2+ compatibility, and aligns the plugin with WordPress.org guidelines.
