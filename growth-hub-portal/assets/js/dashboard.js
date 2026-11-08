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

    // Global Floating Toast Notification System
    window.ghToast = function(msg, type) {
        type = type || 'success';
        var container = document.getElementById('gh-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'gh-toast-container';
            document.body.appendChild(container);
        }
        var toast = document.createElement('div');
        toast.className = 'gh-toast gh-toast-' + type;
        
        var iconSvg = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg>';
        if (type === 'error') {
            iconSvg = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';
        } else if (type === 'info') {
            iconSvg = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>';
        }

        toast.innerHTML = '<span class="gh-toast-icon">' + iconSvg + '</span><span>' + msg + '</span>';
        container.appendChild(toast);

        setTimeout(function() {
            toast.classList.add('fade-out');
            setTimeout(function() { toast.remove(); }, 300);
        }, 3200);
    };

    // Global Confirmation Modal System
    var ghConfirmCallback = null;
    window.ghConfirm = function(title, desc, onConfirm, confirmText, isDanger) {
        confirmText = confirmText || 'Confirm';
        isDanger = (typeof isDanger === 'undefined') ? true : isDanger;
        ghConfirmCallback = onConfirm;
        
        var modal = document.getElementById('gh-confirm-modal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'gh-confirm-modal';
            modal.innerHTML = '<div class="gh-confirm-card">' +
                '<div class="gh-confirm-icon-wrap ' + (isDanger ? 'gh-confirm-icon-danger' : 'gh-confirm-icon-primary') + '" id="gh-confirm-icon">' +
                    '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>' +
                '</div>' +
                '<h3 class="gh-confirm-title" id="gh-confirm-title">' + title + '</h3>' +
                '<p class="gh-confirm-desc" id="gh-confirm-desc">' + desc + '</p>' +
                '<div class="gh-confirm-actions">' +
                    '<button type="button" class="gh-btn-cancel-modal" onclick="ghCloseConfirmModal()">Cancel</button>' +
                    '<button type="button" class="gh-btn-action-modal ' + (isDanger ? 'danger' : 'primary') + '" id="gh-confirm-action-btn" onclick="ghExecuteConfirm()">' + confirmText + '</button>' +
                '</div>' +
            '</div>';
            document.body.appendChild(modal);
        } else {
            document.getElementById('gh-confirm-title').innerText = title;
            document.getElementById('gh-confirm-desc').innerText = desc;
            var btn = document.getElementById('gh-confirm-action-btn');
            btn.innerText = confirmText;
            btn.className = 'gh-btn-action-modal ' + (isDanger ? 'danger' : 'primary');
            var iconWrap = document.getElementById('gh-confirm-icon');
            iconWrap.className = 'gh-confirm-icon-wrap ' + (isDanger ? 'gh-confirm-icon-danger' : 'gh-confirm-icon-primary');
        }
        modal.style.display = 'flex';
    };

    window.ghCloseConfirmModal = function() {
        var modal = document.getElementById('gh-confirm-modal');
        if (modal) modal.style.display = 'none';
        ghConfirmCallback = null;
    };

    window.ghExecuteConfirm = function() {
        if (typeof ghConfirmCallback === 'function') {
            ghConfirmCallback();
        }
        ghCloseConfirmModal();
    };

})(jQuery);
