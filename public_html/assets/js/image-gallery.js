/*
 * The image gallery component (views/components/image_gallery.php): clicking
 * a thumbnail shows its large image, and its caption when it has one.
 *
 * @version 1.0
 */
// Each gallery on a page loads this file; the listener goes on once.
if (!window.__joineryGalleryWired) {
window.__joineryGalleryWired = true;
document.addEventListener('click', function (e) {
	var el = e.target.closest ? e.target.closest('.jy-gallery .jy-gallery-thumb') : null;
	if (!el) return;
	var g = el.closest('.jy-gallery');
	var main = g.querySelector('.jy-gallery-main img');
	if (main && el.dataset.largeSrc) main.src = el.dataset.largeSrc;
	var cap = g.querySelector('.jy-gallery-caption');
	if (cap) {
		if (el.dataset.caption) { cap.textContent = el.dataset.caption; cap.hidden = false; }
		else { cap.hidden = true; }
	}
	g.querySelectorAll('.jy-gallery-thumb').forEach(function (t) { t.classList.remove('is-active'); });
	el.classList.add('is-active');
});
}
