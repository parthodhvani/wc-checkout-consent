
    /**
     * WC Customer Affairs — Admin JS
     * Minimal, clean, no dependencies beyond jQuery (loaded by WP).
     */
    (function ($) {
        'use strict';

        // ── Customer list live-search ──────────────────────────────────────────────
        var $search = $('#wcca-search');
        var $rows = $('#wcca-customers-table tbody tr');

        if ($search.length && $rows.length) {
            $search.on('input', function () {
                var q = this.value.toLowerCase().trim();
                $rows.each(function () {
                    var haystack = $(this).data('search') || '';
                    $(this).toggle(!q || haystack.indexOf(q) !== -1);
                });
            });

            // Focus on load for power-users
            $search.focus();
        }

    }(jQuery));
