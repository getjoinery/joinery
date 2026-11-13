/*
 * The theme's menus (includes/PublicPage.php): the dropdowns, the user menu
 * and the mobile menu open and close.
 *
 * @version 1.0
 */
document.addEventListener('DOMContentLoaded', function() {
    // Dropdown menus
    document.querySelectorAll('.js-clickable-menu').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            var clicked_menu = this.nextElementSibling;
            while (clicked_menu && !clicked_menu.classList.contains('js-clicked-menu')) {
                clicked_menu = clicked_menu.nextElementSibling;
            }
            if (clicked_menu) {
                clicked_menu.classList.toggle('invisible');
                document.querySelectorAll('.js-clicked-menu').forEach(function(m) {
                    if (m !== clicked_menu) m.classList.add('invisible');
                });
            }
            e.stopPropagation();
        });
    });

    // User menu
    var userMenuBtn = document.getElementById('user-menu-button');
    if (userMenuBtn) {
        userMenuBtn.addEventListener('click', function(e) {
            var menu = document.getElementById('user-menu');
            if (menu) menu.classList.toggle('invisible');
            e.stopPropagation();
        });
    }

    // Mobile menu
    var mobileToggle = document.getElementById('mobile-toggle-button');
    if (mobileToggle) {
        mobileToggle.addEventListener('click', function() {
            var m = document.getElementById('mobile-menu');
            if (m) m.classList.remove('invisible');
        });
    }
    var mobileClose = document.getElementById('mobile-close-button');
    if (mobileClose) {
        mobileClose.addEventListener('click', function() {
            var m = document.getElementById('mobile-menu');
            if (m) m.classList.add('invisible');
        });
    }

    // Close all dropdowns on outside click
    document.addEventListener('click', function() {
        document.querySelectorAll('.js-clicked-menu').forEach(function(m) {
            m.classList.add('invisible');
        });
        var userMenu = document.getElementById('user-menu');
        if (userMenu) userMenu.classList.add('invisible');
    });
});
