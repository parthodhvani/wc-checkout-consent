<?php defined('ABSPATH') || exit; ?>
<div class="wcca-consent-wrap">

    <div class="wcca-consent-header">
        <h2>📋 Order Consent Form</h2>
        <p>
            <strong>#<?php echo esc_html( $order->get_id() ); ?></strong>
            <?php echo esc_html( wp_date( 'F j, Y', $order->get_date_created()->getTimestamp() ) ); ?>
        </p>
    </div>

    <?php if ($existing): ?>
        <div class="wcca-alert wcca-alert-success">
            <strong>✅ Already Signed</strong> — You signed this consent on
            <?php echo esc_html( wp_date('F j, Y g:i A', strtotime($existing->signed_at)) ); ?>
            <?php if ($existing->pdf_path && file_exists($existing->pdf_path)): ?>
                <br><a
                    href="<?php echo esc_url(add_query_arg(['action' => 'wcca_download_pdf', 'sig_id' => $existing->id, 'nonce' => wp_create_nonce('wcca_pdf_' . $existing->id)], admin_url('admin-ajax.php'))); ?>"
                    class="wcca-btn wcca-btn-primary" style="margin-top:12px">📄 Download Your Signed PDF</a>
            <?php endif; ?>
        </div>
    <?php else: ?>

        <!-- Order Summary -->
        <div class="wcca-order-summary">
            <h3>Order Summary</h3>
            <table class="wcca-items-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Qty</th>
                        <th>Price</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($order->get_items() as $wcca_item): ?>
                        <tr>
                            <td><?php echo esc_html($wcca_item->get_name()); ?></td>
                            <td><?php echo esc_html($wcca_item->get_quantity()); ?></td>
                            <td><?php echo wp_kses_post( wc_price( $wcca_item->get_total() ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="order-total">
                        <td colspan="2"><strong>Order Total</strong></td>
                        <td><strong>
    <?php echo wp_kses_post($order->get_formatted_order_total()); ?>
                        </strong></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Consent Form -->
        <form id="wcca-consent-form" class="wcca-form">
            <?php wp_nonce_field( 'wcca_sign', '_wcca_nonce' ); ?>
            <input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>">
            <input type="hidden" id="wcca-signature-data" name="signature" value="">

            <div class="wcca-form-section">
                <h3>Your Information <span class="wcca-auto-tag">Auto-filled from your account</span></h3>
                <div class="wcca-form-grid">
                    <div class="wcca-field">
                        <label>First Name</label>
                        <input type="text" name="first_name" value="<?php echo esc_attr($data['first_name']) ?>" required>
                    </div>
                    <div class="wcca-field">
                        <label>Last Name</label>
                        <input type="text" name="last_name" value="<?php echo esc_attr($data['last_name']) ?>" required>
                    </div>
                    <div class="wcca-field">
                        <label>Email</label>
                        <input type="email" name="email" value="<?php echo esc_attr($data['email']) ?>" required>
                    </div>
                    <div class="wcca-field">
                        <label>Phone</label>
                        <input type="tel" name="phone" value="<?php echo esc_attr($data['phone']) ?>">
                    </div>
                    <div class="wcca-field wcca-field-full">
                        <label>Address</label>
                        <input type="text" name="address" value="<?php echo esc_attr($data['address']) ?>">
                    </div>
                </div>
            </div>

            <div class="wcca-form-section">
                <h3>Consent Declaration</h3>
                <div class="wcca-consent-text">
                    <p>I, <strong><span
                                id="wcca-name-display"><?php echo esc_html($data['first_name'] . ' ' . $data['last_name']) ?></span></strong>,
                        hereby confirm that:</p>
                    <ul>
                        <li>I have reviewed and agree to the terms and conditions of this purchase.</li>
                        <li>The order information above is accurate and correct.</li>
                        <li>I authorise the processing of my personal data for fulfilment of this order.</li>
                        <li>I understand this digital signature is legally binding.</li>
                    </ul>
                    <p><strong>Order Total: <?php echo wp_kses_post($order->get_formatted_order_total()); ?></strong></p>
                </div>
            </div>

            <div class="wcca-form-section">
                <h3>Digital Signature <span class="wcca-required">Required</span></h3>
                <p class="wcca-hint">Sign in the box below using your mouse or touchscreen.</p>
                <div class="wcca-signature-container">
                    <canvas id="wcca-signature-pad"></canvas>
                    <div class="wcca-sig-placeholder" id="wcca-sig-placeholder">
                        <span>✍️</span>
                        Sign here
                    </div>
                </div>
                <div class="wcca-sig-actions">
                    <button type="button" id="wcca-clear-sig" class="wcca-btn wcca-btn-outline">✕ Clear</button>
                    <span id="wcca-sig-status" class="wcca-sig-status"></span>
                </div>
            </div>

            <div class="wcca-submit-row">
                <button type="submit" id="wcca-submit-btn" class="wcca-btn wcca-btn-primary wcca-btn-lg" disabled>
                    ✍️ Submit Signed Consent
                </button>
                <p class="wcca-submit-hint">A PDF copy will be generated and available for download after signing.</p>
            </div>

            <div id="wcca-result" class="wcca-result"></div>
        </form>

    <?php endif; ?>
</div>