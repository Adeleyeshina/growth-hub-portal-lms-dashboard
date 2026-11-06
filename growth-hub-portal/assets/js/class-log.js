/**
 * Class Log Interactions - Pill toggles & AJAX submit
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        // Handle Pill Toggles
        function bindPills(containerId, inputId) {
            var container = document.getElementById(containerId);
            var input = document.getElementById(inputId);
            if (!container || !input) return;

            var buttons = container.querySelectorAll('.gh-pill');
            buttons.forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    buttons.forEach(function(b) { b.classList.remove('active'); });
                    this.classList.add('active');
                    input.value = this.getAttribute('data-val');
                });
            });
        }

        bindPills('gh-pills-mode', 'gh_f_mode');
        bindPills('gh-pills-planned', 'gh_f_planned');
    });

})(jQuery);
