/*
 * The theme's sidebar (includes/PublicPage.php): open, close, overlay, Escape.
 *
 * @version 1.0
 */
(function () {
const toggle = document.getElementById('sidebarToggle');
const overlay = document.getElementById('sidebarOverlay');
const panel = document.getElementById('sidebarPanel');
const close = document.getElementById('sidebarClose');
function openSidebar() { overlay.classList.add('active'); panel.classList.add('active'); }
function closeSidebar() { overlay.classList.remove('active'); panel.classList.remove('active'); }
if (toggle) toggle.addEventListener('click', openSidebar);
if (overlay) overlay.addEventListener('click', closeSidebar);
if (close) close.addEventListener('click', closeSidebar);
document.addEventListener('keydown', e => { if (e.key === 'Escape' && overlay) closeSidebar(); });
})();
