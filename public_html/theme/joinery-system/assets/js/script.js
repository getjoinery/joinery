/* ============================================================
   Falcon HTML5 - Vanilla JS
   Minimal JS: sidebar, dropdowns, collapsible nav, alerts
   No jQuery, no Bootstrap JS, no FontAwesome
   ============================================================ */

document.addEventListener('DOMContentLoaded', () => {

    // Remove preload class to enable transitions after initial paint
    document.body.classList.remove('preload');

    // ===== Sidebar toggle (desktop collapse) =====
    const sidebarToggleBtns = document.querySelectorAll('.sidebar-toggle-btn, .topbar-hamburger');
    sidebarToggleBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            const layout = document.querySelector('.admin-layout') || document.body;
            // On mobile: toggle .sidebar-open; on desktop: toggle .sidebar-collapsed
            if (window.innerWidth < 1200) {
                layout.classList.toggle('sidebar-open');
            } else {
                layout.classList.toggle('sidebar-collapsed');
            }
        });
    });

    // ===== Sidebar overlay close (mobile) =====
    const overlay = document.querySelector('.sidebar-overlay');
    if (overlay) {
        overlay.addEventListener('click', () => {
            document.querySelector('.admin-layout')?.classList.remove('sidebar-open');
        });
    }

    // ===== Collapsible sidebar nav sections =====
    document.querySelectorAll('.sidebar-nav .nav-link.has-children').forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            const subNav = link.nextElementSibling;
            if (!subNav || !subNav.classList.contains('sidebar-subnav')) return;
            if (subNav.classList.contains('open')) return;
            // Close siblings
            link.parentElement.parentElement.querySelectorAll('.sidebar-subnav.open').forEach(el => {
                if (el !== subNav) {
                    el.classList.remove('open');
                    el.previousElementSibling?.classList.remove('open');
                }
            });
            subNav.classList.add('open');
            link.classList.add('open');
        });
    });

    // ===== Dropdown menus (topbar) =====
    document.querySelectorAll('.dropdown').forEach(dropdown => {
        const toggle = dropdown.querySelector('[data-toggle="dropdown"]');
        const menu = dropdown.querySelector('.dropdown-menu');
        if (!toggle || !menu) return;

        toggle.addEventListener('click', (e) => {
            e.stopPropagation();
            const isOpen = menu.classList.contains('open');
            // Close all dropdowns
            document.querySelectorAll('.dropdown-menu.open').forEach(m => m.classList.remove('open'));
            if (!isOpen) menu.classList.add('open');
        });
    });

    // Close dropdowns on outside click
    document.addEventListener('click', () => {
        document.querySelectorAll('.dropdown-menu.open').forEach(m => m.classList.remove('open'));
    });

    // ===== Alert dismiss =====
    document.querySelectorAll('.alert-close').forEach(btn => {
        btn.addEventListener('click', () => {
            const alert = btn.closest('.alert');
            if (alert) alert.style.display = 'none';
        });
    });

    // ===== Auto-open active sidebar section =====
    document.querySelectorAll('.sidebar-subnav .nav-link.active').forEach(activeLink => {
        const subNav = activeLink.closest('.sidebar-subnav');
        if (subNav) {
            subNav.classList.add('open');
            subNav.previousElementSibling?.classList.add('open');
        }
    });

    // ===== Sortable table column highlight =====
    document.querySelectorAll('th[data-sort]').forEach(th => {
        th.style.cursor = 'pointer';
        th.addEventListener('click', () => {
            const url = th.dataset.sort;
            if (url) window.location.href = url;
        });
    });
});

// ===== JoineryModal =====
const JoineryModal = (() => {
    let dialog, msgEl, inputEl, confirmBtn, cancelBtn;

    function init() {
        if (dialog) return;
        dialog = document.createElement('dialog');
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

        confirmBtn.textContent = (options && options.confirmLabel)  || 'Confirm';
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
