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
            const isOpen = subNav.classList.contains('open');
            // Optionally close siblings
            link.parentElement.parentElement.querySelectorAll('.sidebar-subnav.open').forEach(el => {
                if (el !== subNav) {
                    el.classList.remove('open');
                    el.previousElementSibling?.classList.remove('open');
                }
            });
            subNav.classList.toggle('open', !isOpen);
            link.classList.toggle('open', !isOpen);
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
