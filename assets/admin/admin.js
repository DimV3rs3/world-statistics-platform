/**
 * World Statistics Platform — Admin JS.
 *
 * Dashboard interactions, extensions manager tab switching.
 */
(function($) {
    'use strict';

    $(document).ready(function() {

        // Extensions manager tab switching (handled inline in extensions.php)

        // Flush cache handler
        $('a[href*="action=flush_cache"]').on('click', function(e) {
            if (!confirm('Очистить весь кеш платформы?')) {
                e.preventDefault();
            }
        });

        // Confirm dangerous actions
        $('.wsp-danger-zone .button').on('click', function(e) {
            if (!confirm('Вы уверены? Это действие нельзя отменить.')) {
                e.preventDefault();
            }
        });

    });

})(jQuery);
