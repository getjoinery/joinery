/*
 * The monthly / yearly switch on the home and pricing pages (views/index.php,
 * views/pricing.php): switching it opens the matching pricing page.
 *
 * @version 1.0
 */
(function () {
	var toggle = document.getElementById('switcher');
	if (!toggle) return;
	toggle.addEventListener('change', function () {
		window.location.href = this.checked ? '/pricing?page=year' : '/pricing';
	});
})();
