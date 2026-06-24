/* ============================================================
   Joinery Base JS
   Minimal vanilla JS to handle interactive components
   in base view files. Only needed when the theme doesn't
   include Bootstrap JS.
   ============================================================ */

document.addEventListener('DOMContentLoaded', function() {

    // --- Tab/Pill toggle (replaces Bootstrap's data-bs-toggle="pill"/"tab") ---
    document.querySelectorAll('[data-bs-toggle="pill"], [data-bs-toggle="tab"]').forEach(function(trigger) {
        trigger.addEventListener('click', function(e) {
            e.preventDefault();
            var targetSelector = this.getAttribute('data-bs-target') || this.getAttribute('href');
            if (!targetSelector) return;

            // Deactivate siblings
            var nav = this.closest('.nav, ul');
            if (nav) {
                nav.querySelectorAll('.nav-link').forEach(function(link) {
                    link.classList.remove('active');
                    link.setAttribute('aria-selected', 'false');
                });
            }

            // Activate this tab
            this.classList.add('active');
            this.setAttribute('aria-selected', 'true');

            // Hide all sibling panes
            var tabContent = document.querySelector(targetSelector);
            if (tabContent) {
                var container = tabContent.parentElement;
                if (container) {
                    container.querySelectorAll('.tab-pane').forEach(function(pane) {
                        pane.classList.remove('show', 'active');
                    });
                }
                tabContent.classList.add('show', 'active');
            }
        });
    });

    // --- Dropdown toggle (replaces Bootstrap's data-bs-toggle="dropdown") ---
    // Only activate if Bootstrap JS is NOT loaded (Bootstrap handles its own dropdowns)
    document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(function(trigger) {
        trigger.addEventListener('click', function(e) {
            // If Bootstrap is available, let it handle the dropdown natively
            if (typeof bootstrap !== 'undefined' && bootstrap.Dropdown) return;
            e.preventDefault();
            e.stopPropagation();
            var menu = this.nextElementSibling;
            if (menu && menu.classList.contains('dropdown-menu')) {
                var wasOpen = menu.classList.contains('show');
                // Close all open dropdowns first
                document.querySelectorAll('.dropdown-menu.show').forEach(function(m) {
                    m.classList.remove('show');
                });
                if (!wasOpen) {
                    menu.classList.add('show');
                }
            }
        });
    });

    // Close dropdowns when clicking outside (only when Bootstrap is not present)
    document.addEventListener('click', function() {
        if (typeof bootstrap !== 'undefined' && bootstrap.Dropdown) return;
        document.querySelectorAll('.dropdown-menu.show').forEach(function(m) {
            m.classList.remove('show');
        });
    });

    // --- Tooltip (replaces Bootstrap's data-bs-toggle="tooltip") ---
    // Simple title-based fallback — no fancy positioning needed
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
        if (!el.getAttribute('title') && el.getAttribute('data-bs-original-title')) {
            el.setAttribute('title', el.getAttribute('data-bs-original-title'));
        }
    });
});

// ===== JoineryModal =====
// System confirm/alert/prompt modal. Native <dialog>, lazily created on first
// use and appended to <body>. The dialog carries the `jy-ui` class so the kit's
// .jy-ui-scoped .dialog-* rules in joinery-styles.css reach it even though it
// renders outside any page-level .jy-ui wrapper.
const JoineryModal = (() => {
    let dialog, msgEl, inputEl, confirmBtn, cancelBtn;

    function init() {
        if (dialog) return;
        dialog = document.createElement('dialog');
        dialog.className = 'jy-ui';
        dialog.innerHTML =
            '<p class="dialog-message"></p>' +
            '<input class="dialog-input" type="text">' +
            '<div class="dialog-actions">' +
            '<button class="dialog-btn-cancel">Cancel</button>' +
            '<button class="dialog-btn-confirm">Confirm</button>' +
            '</div>';
        document.body.appendChild(dialog);
        msgEl      = dialog.querySelector('.dialog-message');
        inputEl    = dialog.querySelector('.dialog-input');
        confirmBtn = dialog.querySelector('.dialog-btn-confirm');
        cancelBtn  = dialog.querySelector('.dialog-btn-cancel');
        cancelBtn.addEventListener('click', () => dialog.close());
    }

    function _open(message, options, showInput, showCancel) {
        init();
        msgEl.textContent = message;
        inputEl.style.display = showInput ? 'block' : 'none';
        if (showInput) {
            inputEl.value       = (options && options.defaultValue)  || '';
            inputEl.placeholder = (options && options.placeholder)   || '';
        }
        cancelBtn.style.display = showCancel ? '' : 'none';
        cancelBtn.textContent   = (options && options.cancelLabel)  || 'Cancel';
        confirmBtn.textContent  = (options && options.confirmLabel) || 'Confirm';
        const style = (options && options.confirmStyle) || 'danger';
        confirmBtn.className = 'dialog-btn-confirm dialog-btn-' + style;
        dialog.showModal();
        if (showInput) inputEl.focus();
    }

    function confirm(message, onConfirm, options) {
        _open(message, options, false, true);
        confirmBtn.onclick = () => { dialog.close(); onConfirm(); };
    }

    function alert(message, onClose, options) {
        const opts = Object.assign({ confirmLabel: 'OK', confirmStyle: 'primary' }, options);
        _open(message, opts, false, false);
        confirmBtn.onclick = () => { dialog.close(); if (onClose) onClose(); };
    }

    function prompt(message, onConfirm, options) {
        const opts = Object.assign({ confirmStyle: 'primary' }, options);
        _open(message, opts, true, true);
        function submit() { dialog.close(); onConfirm(inputEl.value); }
        confirmBtn.onclick  = submit;
        inputEl.onkeydown   = (e) => { if (e.key === 'Enter') submit(); };
    }

    return { confirm, alert, prompt };
})();
