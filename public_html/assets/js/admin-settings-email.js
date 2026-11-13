/*
 * /admin/admin_settings_email: each provider's settings section shows only
 * while that provider is the chosen sender or the fallback. Two pickers
 * decide one section, which visibility_rules (one trigger per rule) can't
 * say, so the page has this file.
 *
 * @version 1.0
 */
(function () {
	'use strict';
	function init() {
		var pickers = [document.getElementById('email_service'), document.getElementById('email_fallback_service')];
		function showChosenEmailServices() {
			var chosen = pickers.map(function (p) { return p ? p.value : ''; });
			document.querySelectorAll('.email-provider-section').forEach(function (section) {
				section.style.display = chosen.indexOf(section.getAttribute('data-provider')) !== -1 ? '' : 'none';
			});
		}
		pickers.forEach(function (p) { if (p) p.addEventListener('change', showChosenEmailServices); });
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
