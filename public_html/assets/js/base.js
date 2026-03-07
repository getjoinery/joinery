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
    document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(function(trigger) {
        trigger.addEventListener('click', function(e) {
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

    // Close dropdowns when clicking outside
    document.addEventListener('click', function() {
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
