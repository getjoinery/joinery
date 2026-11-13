/*
 * The domain availability check (ManagedDomainIntake::availabilityScript):
 * each <script type="application/json" data-sm-domain-check> names a domain
 * field, its status element, an optional radio choice that must be picked
 * for the check to run, and a block to reveal once the name is free.
 *
 * @version 1.0
 */
(function () {
	function wire(cfg) {
		var input = document.getElementById(cfg.input);
		var status = document.getElementById(cfg.status);
		if (!input || !status || !window.joineryApi) { return; }
		var timer = null, last = '';
		// The block asked for only once the name can be bought: shown on a free
		// name, or when the check could not run, and kept open once it holds
		// answers (data-keep, set by the page on a re-shown form).
		var reveal = cfg.reveal ? document.getElementById(cfg.reveal) : null;
		function armed() {
			if (!cfg.when_name) { return true; }
			var picked = document.querySelector('input[name="' + cfg.when_name + '"]:checked');
			return !!picked && picked.value === cfg.when_value;
		}
		function show(text, state) {
			status.textContent = text;
			status.setAttribute('data-state', state);
			if (reveal && reveal.getAttribute('data-keep') !== '1') {
				reveal.hidden = (state !== 'available' && state !== 'error');
			}
		}
		function check() {
			if (!armed()) { last = ''; show('', ''); return; }
			var value = (input.value || '').trim().toLowerCase();
			if (value === last) { return; }
			last = value;
			if (value === '') { show('', ''); return; }
			show('Checking ' + value + '…', 'checking');
			window.joineryApi.post('server_manager/domain_check', { domain: value }).then(function (data) {
				if ((input.value || '').trim().toLowerCase() !== value) { return; }
				if (data && data.available) {
					show(value + ' is available — ' + (data.price_display || '') + ' for the first year.', 'available');
				} else {
					show((data && data.message) || 'That name is not available.', 'unavailable');
				}
			}).catch(function () {
				show('We could not check that name just now. You can still continue — we check again before anything is charged.', 'error');
			});
		}
		input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(check, 600); });
		input.addEventListener('blur', function () { clearTimeout(timer); check(); });
		if (cfg.when_name) {
			document.querySelectorAll('input[name="' + cfg.when_name + '"]').forEach(function (radio) {
				radio.addEventListener('change', function () { last = ''; check(); });
			});
		}
		if (reveal && reveal.getAttribute('data-keep') !== '1') { reveal.hidden = true; }
		check();
	}
	function start() {
		document.querySelectorAll('script[type="application/json"][data-sm-domain-check]').forEach(function (el) {
			if (el.hasAttribute('data-wired')) { return; }
			el.setAttribute('data-wired', '1');
			var cfg;
			try { cfg = JSON.parse(el.textContent); } catch (e) { return; }
			wire(cfg);
		});
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
