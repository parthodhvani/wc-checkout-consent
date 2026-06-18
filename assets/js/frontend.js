    /**
     * WC Customer Affairs — Frontend JS
     * Handles: consent-form page signature, cart/checkout modal, Place Order gating.
     *
     * Dependencies: jQuery, SignaturePad (signature-pad.min.js)
     */
    (function ($) {
        'use strict';

        // Guard: wcca object injected by wp_localize_script
        if (typeof wcca === 'undefined') return;

        /* =========================================================================
           Utility helpers
        ========================================================================= */

        function sizeCanvas(canvas, height) {
            var parent = canvas.parentElement;
            var ratio = window.devicePixelRatio || 1;
            var W = Math.floor(parent.getBoundingClientRect().width) || parent.offsetWidth || 560;
            var H = height || 200;

            canvas.width = Math.round(W * ratio);
            canvas.height = Math.round(H * ratio);
            canvas.style.width = W + 'px';
            canvas.style.height = H + 'px';

            var ctx = canvas.getContext('2d');
            ctx.setTransform(1, 0, 0, 1, 0, 0);
            ctx.scale(ratio, ratio);
            ctx.clearRect(0, 0, W, H);

            return { W: W, H: H, ratio: ratio };
        }

        function buildPad(canvas, previousPad, height, callbacks) {
            if (previousPad) {
                try { previousPad.off(); } catch (e) { /* ignore */ }
            }

            sizeCanvas(canvas, height);

            var pad = new SignaturePad(canvas, {
                penColor: '#0f172a',
                backgroundColor: 'rgba(0,0,0,0)',
                minWidth: 1.5,
                maxWidth: 4,
            });

            if (callbacks && callbacks.beginStroke) {
                pad.addEventListener('beginStroke', callbacks.beginStroke);
            }
            if (callbacks && callbacks.afterUpdateStroke) {
                pad.addEventListener('afterUpdateStroke', callbacks.afterUpdateStroke);
            }

            return pad;
        }

        function whenVisible(el, cb, attempts) {
            attempts = attempts || 0;
            var w = el.parentElement ? el.parentElement.getBoundingClientRect().width : 0;
            if (w > 10) {
                cb();
            } else if (attempts < 20) {
                setTimeout(function () { whenVisible(el, cb, attempts + 1); }, 60);
            } else {
                if (el.parentElement) el.parentElement.style.width = '100%';
                cb();
            }
        }

        /* =========================================================================
           A. Consent-form page (account → order-consent endpoint)
        ========================================================================= */
        var canvas = document.getElementById('wcca-signature-pad');

        if (canvas) {
            var pad = null;
            var $submit = $('#wcca-submit-btn');
            var $status = $('#wcca-sig-status');
            var $placeholder = $('#wcca-sig-placeholder');
            var $sigData = $('#wcca-signature-data');
            var $result = $('#wcca-result');
            var $form = $('#wcca-consent-form');

            function buildFormPad() {
                pad = buildPad(canvas, pad, 200, {
                    beginStroke: function () {
                        $placeholder.hide();
                    },
                    afterUpdateStroke: function () {
                        if (!pad.isEmpty()) {
                            $submit.prop('disabled', false);
                            $status.text('Signature captured').addClass('wcca-sig-ok');
                        }
                    },
                });
            }

            whenVisible(canvas, buildFormPad);

            var resizeTimer;
            $(window).on('resize.wcca-form', function () {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function () {
                    var data = pad ? pad.toData() : [];
                    buildFormPad();
                    if (data && data.length && pad) {
                        pad.fromData(data);
                    }
                }, 200);
            });

            $(document).on('click', '#wcca-clear-sig', function () {
                if (pad) pad.clear();
                sizeCanvas(canvas, 200);
                $placeholder.show();
                $submit.prop('disabled', true);
                $status.text('').removeClass('wcca-sig-ok');
            });

            $(document).on('input', '[name="first_name"], [name="last_name"]', function () {
                var first = $('[name="first_name"]').val() || '';
                var last = $('[name="last_name"]').val() || '';
                $('#wcca-name-display').text((first + ' ' + last).trim() || 'Customer');
            });

            $form.on('submit', function (e) {
                e.preventDefault();

                if (!pad || pad.isEmpty()) {
                    var $container = $(canvas).closest('.wcca-signature-container');
                    $container.addClass('wcca-sig-error');
                    setTimeout(function () { $container.removeClass('wcca-sig-error'); }, 2000);
                    $result.html('<div class="wcca-alert wcca-alert-error">Please draw your signature in the box above before submitting.</div>');
                    $('html, body').animate({ scrollTop: $container.offset().top - 120 }, 350);
                    return;
                }

                var png = pad.toDataURL('image/png');
                $sigData.val(png);

                var fd = new FormData(this);
                fd.set('action', 'wcca_save_signature');
                fd.set('nonce', wcca.nonce);
                fd.set('signature', png);

                var origLabel = $submit.html();
                $submit.html('Saving…').prop('disabled', true);

                $.ajax({
                    url: wcca.ajax_url,
                    type: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    success: function (res) {
                        if (res.success) {
                            $form.fadeOut(250, function () {
                                $result.html(
                                    '<div class="wcca-alert wcca-alert-success" style="flex-direction:column;align-items:center;text-align:center;padding:36px 28px;">' +
                                    '<div style="font-size:48px;margin-bottom:12px;">✅</div>' +
                                    '<strong style="font-size:18px;display:block;margin-bottom:8px;">Consent signed successfully</strong>' +
                                    '<p style="margin:0 0 20px;">Your signed consent has been saved. Download your PDF copy below.</p>' +
                                    '<a href="' + res.data.pdf_url + '" target="_blank" rel="noopener" ' +
                                    'class="wcca-btn wcca-btn-primary">Download signed PDF</a>' +
                                    '</div>'
                                ).hide().fadeIn(300);
                            });
                        } else {
                            $submit.html(origLabel).prop('disabled', false);
                            $result.html('<div class="wcca-alert wcca-alert-error">' + (res.data ? res.data.message : 'An error occurred. Please try again.') + '</div>');
                        }
                    },
                    error: function (xhr) {
                        $submit.html(origLabel).prop('disabled', false);
                        $result.html('<div class="wcca-alert wcca-alert-error">Server error (' + xhr.status + '). Please try again.</div>');
                    },
                });
            });
        }

        /* =========================================================================
           B. Cart / Checkout — Consent modal
        ========================================================================= */
        var isCart     = wcca.is_cart == 1;       /* jshint ignore:line */
        var isCheckout = wcca.is_checkout == 1;   /* jshint ignore:line */

        if (!isCart && !isCheckout) return;

        // FIX: read the consent-persistence flags from the server
        var alreadyConsented = wcca.already_consented == 1; /* jshint ignore:line */
        var askEveryTime     = wcca.ask_every_time == 1;    /* jshint ignore:line */

        var modalPad    = null;
        var modalCanvas = null;

        // ── Place Order gating (checkout only) ──────────────────────────────────
        function disablePlaceOrder() {
            $('#place_order').prop('disabled', true).addClass('wcca-consent-pending');
        }

        function enablePlaceOrder() {
            $('#place_order').prop('disabled', false).removeClass('wcca-consent-pending');
        }

        if (isCheckout) {
            // FIX: if user already consented this session and "ask every time" is off,
            //      don't gate Place Order at all — enable immediately.
            if (alreadyConsented && !askEveryTime) {
                $('#wcca_checkout_consent').val('1');
                enablePlaceOrder();
            } else {
                disablePlaceOrder();
                // If the hidden field is already "1" (e.g. after signing in same page load)
                if ($('#wcca_checkout_consent').val() === '1') {
                    enablePlaceOrder();
                }
            }
        }

        // Re-apply gating whenever WooCommerce re-renders checkout fragments.
        // IMPORTANT: if already consented we must re-enable — WooCommerce replaces
        // the Place Order button HTML on every fragment update which wipes disabled state.
        $(document.body).on('updated_checkout', function () {
            if (!isCheckout) return;
            if (alreadyConsented && !askEveryTime) {
                // Re-set hidden field (fragment update may have reset it) and re-enable
                $('#wcca_checkout_consent').val('1');
                enablePlaceOrder();
            } else if ($('#wcca_checkout_consent').val() !== '1') {
                disablePlaceOrder();
            }
        });

        // ── Ensure modal markup is in the DOM ────────────────────────────────────
        $(function () {
            if (!$('#wcca-modal-overlay').length) {
                injectModalMarkup();
            }
            modalCanvas = document.getElementById('wcca-modal-canvas');
        });

        function injectModalMarkup() {
            var html =
                '<div id="wcca-modal-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="wcca-modal-title">' +
                '<div id="wcca-modal">' +
                '<div id="wcca-modal-header">' +
                '<h2 id="wcca-modal-title">Consent Required</h2>' +
                '<p>Review and sign below to continue.</p>' +
                '</div>' +
                '<form id="wcca-cart-consent-form" novalidate>' +
                '<div class="wcca-modal-section">' +
                '<h3>Your Information <span class="wcca-auto-tag">Auto-filled</span></h3>' +
                '<div class="wcca-modal-grid">' +
                '<div class="wcca-modal-field"><label for="wcca-c-firstname">First Name</label>' +
                '<input type="text" id="wcca-c-firstname" name="first_name" autocomplete="given-name" required></div>' +
                '<div class="wcca-modal-field"><label for="wcca-c-lastname">Last Name</label>' +
                '<input type="text" id="wcca-c-lastname" name="last_name" autocomplete="family-name" required></div>' +
                '<div class="wcca-modal-field"><label for="wcca-c-email">Email</label>' +
                '<input type="email" id="wcca-c-email" name="email" autocomplete="email" required></div>' +
                '<div class="wcca-modal-field"><label for="wcca-c-phone">Phone</label>' +
                '<input type="tel" id="wcca-c-phone" name="phone" autocomplete="tel"></div>' +
                '</div>' +
                '</div>' +
                '<div class="wcca-modal-section">' +
                '<h3>Consent Declaration</h3>' +
                '<div class="wcca-consent-text">' +
                '<p>I, <strong><span id="wcca-modal-name">Customer</span></strong>, confirm that:</p>' +
                '<ul>' +
                '<li>I agree to the terms and conditions of this purchase.</li>' +
                '<li>I authorise processing of my personal data for order fulfilment.</li>' +
                '<li>I understand this digital signature is legally binding.</li>' +
                '</ul>' +
                '</div>' +
                '</div>' +
                '<div class="wcca-modal-section">' +
                '<h3>Digital Signature <span class="wcca-required">Required</span></h3>' +
                '<p class="wcca-hint">Draw your signature using your mouse or finger.</p>' +
                '<div class="wcca-modal-sig-container" id="wcca-modal-sig-wrap" role="img" aria-label="Signature drawing area">' +
                '<canvas id="wcca-modal-canvas"></canvas>' +
                '<div class="wcca-sig-placeholder" id="wcca-modal-placeholder" aria-hidden="true">' +
                '<span>✍</span>Sign here' +
                '</div>' +
                '</div>' +
                '<div class="wcca-sig-actions">' +
                '<button type="button" id="wcca-modal-clear" class="wcca-modal-btn-outline" aria-label="Clear signature">Clear</button>' +
                '<span id="wcca-modal-sig-status" class="wcca-sig-status" aria-live="polite"></span>' +
                '</div>' +
                '</div>' +
                '<input type="hidden" id="wcca-modal-sig-data" name="signature" value="">' +
                '<div id="wcca-modal-error" class="wcca-modal-error" style="display:none;" role="alert"></div>' +
                '<div class="wcca-modal-footer">' +
                '<button type="button" id="wcca-modal-cancel" class="wcca-modal-btn-outline">Cancel</button>' +
                '<button type="submit" id="wcca-modal-submit" class="wcca-modal-btn-primary" disabled>Sign &amp; Continue</button>' +
                '</div>' +
                '</form>' +
                '</div>' +
                '</div>';

            $('body').append(html);
            modalCanvas = document.getElementById('wcca-modal-canvas');
        }

        // ── Open modal ────────────────────────────────────────────────────────────
        $(document).on('click', '#wcca-open-consent', function (e) {
            e.preventDefault();
            openModal();
        });

        // FIX: "Re-sign" link on the "already consented" notice — allows the user
        //      to voluntarily re-sign within the same session.
        $(document).on('click', '#wcca-re-sign-link', function (e) {
            e.preventDefault();
            // Temporarily reset the hidden field so the modal submit flow re-enables properly
            $('#wcca_checkout_consent').val('0');
            disablePlaceOrder();
            openModal();
        });

        // ── Continue after already-signed ────────────────────────────────────────
        // ── Continue after already-signed ────────────────────────────────────────
        $(document).on('click', '#wcca-modal-proceed', function () {
            $('#wcca_checkout_consent').val('1');
            closeModal();
            if (isCheckout) {
                enablePlaceOrder();
            } else if (isCart) {
                window.location.href = wcca.checkout_url;
            }
        });

        function openModal() {
            if (!$('#wcca-modal-overlay').length) {
                injectModalMarkup();
            }

            $('#wcca-c-firstname').val(wcca.user_first_name || '');
            $('#wcca-c-lastname').val(wcca.user_last_name || '');
            $('#wcca-c-email').val(wcca.user_email || '');
            $('#wcca-c-phone').val(wcca.user_phone || '');
            updateNameDisplay();

            $('#wcca-modal-overlay').fadeIn(200);
            $('body').css('overflow', 'hidden');

            setTimeout(function () {
                modalCanvas = document.getElementById('wcca-modal-canvas');
                initModalPad();
            }, 120);

            setTimeout(function () {
                var $modal = $('#wcca-modal');
                $modal.find('input, button').first().focus();
            }, 150);
        }

        function closeModal() {
            $('#wcca-modal-overlay').fadeOut(200);
            $('body').css('overflow', '');
            $('#wcca-open-consent').focus();
        }

        // ── Build/rebuild modal pad ───────────────────────────────────────────────
        function initModalPad() {
            if (!modalCanvas) return;

            modalPad = buildPad(modalCanvas, modalPad, 150, {
                beginStroke: function () {
                    $('#wcca-modal-placeholder').hide();
                },
                afterUpdateStroke: function () {
                    if (!modalPad.isEmpty()) {
                        $('#wcca-modal-submit').prop('disabled', false);
                        $('#wcca-modal-sig-status')
                            .text('Signature captured')
                            .addClass('wcca-sig-ok');
                    }
                },
            });
        }

        function updateNameDisplay() {
            var name = (
                ($('#wcca-c-firstname').val() || '') + ' ' +
                ($('#wcca-c-lastname').val() || '')
            ).trim();
            $('#wcca-modal-name').text(name || 'Customer');
        }

        $(document).on('input', '#wcca-c-firstname, #wcca-c-lastname', updateNameDisplay);

        $(document).on('click', '#wcca-modal-clear', function () {
            if (modalPad) {
                modalPad.clear();
                sizeCanvas(modalCanvas, 150);
            }
            $('#wcca-modal-placeholder').show();
            $('#wcca-modal-submit').prop('disabled', true);
            $('#wcca-modal-sig-status').text('').removeClass('wcca-sig-ok');
        });

        $(document).on('click', '#wcca-modal-cancel', closeModal);

        $(document).on('click', '#wcca-modal-overlay', function (e) {
            if (e.target === this) closeModal();
        });

        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && $('#wcca-modal-overlay').is(':visible')) {
                closeModal();
            }
        });

        // ── Submit modal consent ──────────────────────────────────────────────────
        $(document).on('submit', '#wcca-cart-consent-form', function (e) {
            e.preventDefault();

            var $error  = $('#wcca-modal-error');
            var $submit = $('#wcca-modal-submit');
            var $wrap   = $('#wcca-modal-sig-wrap');

            $error.hide();

            if (!modalPad || modalPad.isEmpty()) {
                $wrap.addClass('wcca-sig-error');
                setTimeout(function () { $wrap.removeClass('wcca-sig-error'); }, 2000);
                $error.text('Please draw your signature before proceeding.').show();
                return;
            }

            var png = modalPad.toDataURL('image/png');
            $('#wcca-modal-sig-data').val(png);

            var fd = new FormData(document.getElementById('wcca-cart-consent-form'));
            fd.set('action', 'wcca_save_cart_consent');
            fd.set('nonce', wcca.cart_nonce);
            fd.set('signature', png);

            var origLabel = $submit.html();
            $submit.html('Saving…').prop('disabled', true);

            $.ajax({
                url: wcca.ajax_url,
                type: 'POST',
                data: fd,
                processData: false,
                contentType: false,
                success: function (res) {
                    if (res.success) {
                        // FIX: update runtime flag so subsequent updated_checkout events don't re-gate
                        alreadyConsented = true;

                        $submit.html('✓ Signed');
                        setTimeout(function () {
                            $('#wcca_checkout_consent').val('1');

                            // Inject a PDF download notice below the consent button
                            var $consentWrap = $('#wcca-checkout-consent-wrap');
                            $consentWrap.find('#wcca-open-consent').hide();
                            if (!$consentWrap.find('.wcca-pdf-download-notice').length) {
                                var pdfBtn = res.data && res.data.pdf_url
                                    ? '<a href="' + res.data.pdf_url + '" target="_blank" rel="noopener" ' +
                                      'style="display:inline-flex;align-items:center;gap:6px;background:#405fe4;' +
                                      'color:#fff;font-weight:600;font-size:12px;padding:8px 16px;' +
                                      'border-radius:6px;text-decoration:none;">&#x2B07; Download Signed PDF</a>'
                                    : '';
                                $consentWrap.append(
                                    '<div class="wcca-pdf-download-notice" style="' +
                                    'display:flex;align-items:center;gap:12px;flex-wrap:wrap;' +
                                    'background:linear-gradient(135deg,#eef2ff,#f0f9ff);' +
                                    'border:1px solid #c7d8f9;border-left:4px solid #405fe4;' +
                                    'border-radius:8px;padding:14px 16px;margin-top:10px;">' +
                                    '<span style="font-size:22px">&#x2705;</span>' +
                                    '<div style="flex:1;min-width:160px;">' +
                                    '<strong style="font-size:13px;color:#101827;display:block;">Consent signed</strong>' +
                                    '<span style="font-size:11px;color:#4b5563;">PDF available on confirmation page.</span>' +
                                    '</div>' +
                                    pdfBtn +
                                    '</div>'
                                );
                            }

                            // FIX: update the "already consented" notice text if present
                            if ($consentWrap.find('.wcca-already-consented').length) {
                                $consentWrap.find('.wcca-already-consented').text('✓ Consent signed');
                            } else {
                                $('#wcca-consent-success').show();
                            }
                            closeModal();

                            if (isCheckout) {
                                enablePlaceOrder();
                            } else if (isCart) {
                                window.location.href = wcca.checkout_url;
                            }
                        }, 500);
                    } else {
                        $submit.html(origLabel).prop('disabled', false);
                        $error.text(res.data ? res.data.message : 'Something went wrong. Please try again.').show();
                    }
                },
                error: function (xhr) {
                    $submit.html(origLabel).prop('disabled', false);
                    $error.text('Server error (' + xhr.status + '). Please try again.').show();
                },
            });
        });

    }(jQuery));
