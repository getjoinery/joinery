/*
 * The welcome step: the timezone is filled in from the browser while the account still has the factory default (includes/setup_steps/welcome.php).
 * Loaded by views/setup.php for this step.
 *
 * @version 1.0
 */
// Prefill the timezone from the browser when the account still carries the
// factory default — a wrong timezone silently skews every reminder and
// summary send hour.
(function () {
	var select = document.getElementById('usr_timezone');
	if (!select) { return; }
	var marker = document.getElementById('setup-tz-current');
	var current = marker ? (marker.getAttribute('data-current') || '') : '';
	var untouched = (current === '' || current === 'America/New_York');
	if (!untouched) { return; }
	try {
		var detected = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
		if (!detected) { return; }
		for (var i = 0; i < select.options.length; i++) {
			if (select.options[i].value === detected) {
				select.value = detected;
				break;
			}
		}
	} catch (e) { /* keep the current value */ }
})();
