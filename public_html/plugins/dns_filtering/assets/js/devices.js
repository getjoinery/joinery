/*
 * /profile/dns_filtering/devices: each device's Actions menu opens and closes.
 *
 * @version 1.0
 */
(function () {
	document.addEventListener('click', function (e) {
		var btn = e.target.closest('.scd-actions-btn');
		// Close all open menus first
		document.querySelectorAll('.scd-actions-menu.is-open').forEach(function (m) {
			m.classList.remove('is-open');
			m.previousElementSibling.setAttribute('aria-expanded', 'false');
		});
		if (btn) {
			var menu = btn.nextElementSibling;
			menu.classList.add('is-open');
			btn.setAttribute('aria-expanded', 'true');
		}
	});
})();
