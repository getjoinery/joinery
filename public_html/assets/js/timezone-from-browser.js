/**
 * timezone-from-browser.js — a new account's time zone starts as the visitor's own.
 *
 * A sign-up form's time zone list opens on the site's default zone. When the
 * list still shows the zone the page rendered, this picks the browser's zone
 * instead, if the list has it. A choice the visitor made, or a form shown
 * again with their answer, is left alone.
 *
 * @version 1.0
 */
(function () {
	function prefill() {
		var select = document.getElementById('usr_timezone');
		if (!select) { return; }
		var rendered = '';
		for (var i = 0; i < select.options.length; i++) {
			if (select.options[i].defaultSelected) { rendered = select.options[i].value; break; }
		}
		if (select.value !== rendered) { return; }
		var detected = '';
		try { detected = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) { return; }
		if (!detected || detected === rendered) { return; }
		for (var j = 0; j < select.options.length; j++) {
			if (select.options[j].value === detected) { select.value = detected; return; }
		}
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', prefill); } else { prefill(); }
})();
