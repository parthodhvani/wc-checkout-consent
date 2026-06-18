# WooCommerce Checkout Consent

A WordPress/WooCommerce plugin (pure PHP, no build step, no Composer/npm dependencies,
and no automated test suite). The repo root **is** the plugin directory.

## Cursor Cloud specific instructions

This repo is just the plugin; running it requires a full WordPress + WooCommerce + MySQL
stack. The update script installs nothing repo-specific (there are no per-repo deps). The
stack below is provisioned once and persisted in the VM snapshot, so future sessions only
need to **start services** (not reinstall).

### Where things live
- WordPress install: `~/wordpress` (the plugin is symlinked in at
  `~/wordpress/wp-content/plugins/woocommerce-checkout-consent -> /workspace`).
- Admin login: user `admin` / password `admin123`, site at `http://localhost:8080`.
- DB: MariaDB, database `wordpress`, user `wpuser` / `wppass`.

### Starting services (NOT in the update script — start manually each session)
- Start MariaDB (the socket dir is not auto-created):
  - `sudo mkdir -p /run/mysqld && sudo chown mysql:mysql /run/mysqld`
  - `sudo -b bash -c 'mariadbd --user=mysql >/tmp/mariadbd.log 2>&1'`
- Start the dev server (run from `~/wordpress`, keep it in a tmux session):
  - `cd ~/wordpress && wp server --host=0.0.0.0 --port=8080`

### Non-obvious gotchas
- **DB host must be `127.0.0.1`, not `localhost`.** PHP's `mysqli` default socket path does
  not match MariaDB's socket here, so socket (`localhost`) connections fail with
  "No such file or directory". `wp-config.php` is set to `DB_HOST=127.0.0.1` (TCP). The
  `wpuser` account is granted for `localhost`, `127.0.0.1`, and `%`.
- **The consent button only renders on the classic shortcode checkout.** The plugin hooks
  `woocommerce_review_order_before_submit`, which does not fire on the default block
  checkout. The checkout page (`woocommerce_checkout_page_id`) content is set to
  `[woocommerce_checkout]` and the cart page to `[woocommerce_cart]`.
- **Disable WooCommerce "coming soon" mode** or the order-received/thank-you page (where the
  "Download Signed PDF" button appears) shows a placeholder: `wp option update
  woocommerce_coming_soon 'no'`.
- Plugin options: `wcca_enable_consent` (1 = show consent button) and
  `wcca_ask_consent_every_time` (1 = always prompt, instead of "consent already on file").
- The plugin DB tables (`wp_wcca_signatures`, `wp_wcca_consent_logs`) are created on plugin
  activation.

### Lint / test / build
- There is no lint config, no automated tests, and no build step in this repo. "Running" the
  app means exercising it through the WordPress site above (e.g. the product → checkout →
  sign consent → place order flow).
