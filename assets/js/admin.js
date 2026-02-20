/**
 * VDL Agent Admin JavaScript
 */

(function($) {
    'use strict';

    // DOM Ready
    $(document).ready(function() {
        initCopyButtons();
        initPurgeCache();
        initCharts();
    });

    /**
     * Initialize copy to clipboard buttons
     */
    function initCopyButtons() {
        $('.vdl-copy-btn').on('click', function() {
            var $btn = $(this);
            var targetId = $btn.data('target');
            var $input = $('#' + targetId);

            if ($input.length) {
                $input.select();
                document.execCommand('copy');

                // Visual feedback
                var $icon = $btn.find('.dashicons');
                $icon.removeClass('dashicons-clipboard').addClass('dashicons-yes');

                setTimeout(function() {
                    $icon.removeClass('dashicons-yes').addClass('dashicons-clipboard');
                }, 2000);

                // Show tooltip
                showTooltip($btn, vdlAgent.strings.copied);
            }
        });
    }

    /**
     * Initialize purge cache button
     */
    function initPurgeCache() {
        $('#vdl-purge-cache').on('click', function() {
            var $btn = $(this);

            if (!confirm(vdlAgent.strings.confirm)) {
                return;
            }

            $btn.prop('disabled', true).addClass('vdl-loading');

            $.ajax({
                url: vdlAgent.apiUrl + 'cache/purge',
                method: 'POST',
                headers: {
                    'X-WP-Nonce': vdlAgent.nonce
                },
                success: function(response) {
                    if (response.success) {
                        showNotice('success', response.message);
                    } else {
                        showNotice('error', vdlAgent.strings.error);
                    }
                },
                error: function() {
                    showNotice('error', vdlAgent.strings.error);
                },
                complete: function() {
                    $btn.prop('disabled', false).removeClass('vdl-loading');
                }
            });
        });
    }

    /**
     * Initialize Chart.js if available
     */
    function initCharts() {
        // Check if Chart.js is loaded
        if (typeof Chart === 'undefined') {
            // Load Chart.js dynamically
            var script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js';
            script.onload = function() {
                // Trigger chart initialization after load
                $(document).trigger('vdl-charts-ready');
            };
            document.head.appendChild(script);
        }
    }

    /**
     * Show tooltip near element
     */
    function showTooltip($element, message) {
        var $tooltip = $('<span class="vdl-tooltip">' + message + '</span>');

        $tooltip.css({
            position: 'absolute',
            background: '#333',
            color: '#fff',
            padding: '5px 10px',
            borderRadius: '4px',
            fontSize: '12px',
            zIndex: 9999,
            top: $element.offset().top - 30,
            left: $element.offset().left + ($element.outerWidth() / 2) - 30
        });

        $('body').append($tooltip);

        setTimeout(function() {
            $tooltip.fadeOut(function() {
                $tooltip.remove();
            });
        }, 1500);
    }

    /**
     * Show admin notice
     */
    function showNotice(type, message) {
        var $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');

        $('.vdl-admin h1').after($notice);

        // Make it dismissible
        $notice.find('.notice-dismiss').on('click', function() {
            $notice.fadeOut(function() {
                $notice.remove();
            });
        });

        // Auto dismiss after 5 seconds
        setTimeout(function() {
            $notice.fadeOut(function() {
                $notice.remove();
            });
        }, 5000);

        // Scroll to top
        $('html, body').animate({ scrollTop: 0 }, 300);
    }

    /**
     * Format number with locale
     */
    function formatNumber(num) {
        return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    /**
     * Debounce function
     */
    function debounce(func, wait) {
        var timeout;
        return function() {
            var context = this;
            var args = arguments;
            clearTimeout(timeout);
            timeout = setTimeout(function() {
                func.apply(context, args);
            }, wait);
        };
    }

})(jQuery);
