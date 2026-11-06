/**
 * The Growth Hub Dashboard - Core Interactions & Mobile Drawer
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        var $sidebar = $('#gh-sidebar');
        var $overlay = $('#gh-overlay');
        var $openBtn = $('#gh-open-menu');
        var $closeBtn = $('#gh-close-menu');

        // Open drawer on mobile/tablet
        if ($openBtn.length && $sidebar.length) {
            $openBtn.on('click', function(e) {
                e.preventDefault();
                $sidebar.addClass('open');
                $overlay.addClass('open');
                $('body').css('overflow', 'hidden');
            });
        }

        // Close drawer
        function closeDrawer() {
            if ($sidebar.length) $sidebar.removeClass('open');
            if ($overlay.length) $overlay.removeClass('open');
            $('body').css('overflow', '');
        }

        if ($closeBtn.length) {
            $closeBtn.on('click', function(e) {
                e.preventDefault();
                closeDrawer();
            });
        }

        if ($overlay.length) {
            $overlay.on('click', function() {
                closeDrawer();
            });
        }
    });

})(jQuery);
