/*
 * The theme's mobile menu toggle (includes/PublicPage.php).
 *
 * @version 1.0
 */
(function () {
// Mobile menu toggle
const menuToggle = document.querySelector('.menu-toggle');
const mainNav = document.querySelector('.main-nav');
if (menuToggle && mainNav) {
    menuToggle.addEventListener('click', function() {
        mainNav.classList.toggle('open');
    });
}
})();
