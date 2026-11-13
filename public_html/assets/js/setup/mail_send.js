/*
 * The mail sending step: the record tabs, the provider fields, the Mailgun EU switch and the DNS publish form (includes/setup_steps/mail_send.php).
 * Loaded by views/setup.php for this step.
 *
 * @version 1.0
 */
// The two ways of adding records are tabs; automatic is the assumed path.
(function () {
	var tabs = document.querySelectorAll('[data-ms-tab]');
	if (!tabs.length) { return; }
	tabs.forEach(function (btn) {
		btn.addEventListener('click', function () {
			tabs.forEach(function (b) { b.setAttribute('aria-selected', b === btn ? 'true' : 'false'); });
			['auto', 'manual'].forEach(function (key) {
				var panel = document.getElementById('setup-ms-tab-' + key);
				if (panel) { panel.classList.toggle('active', btn.getAttribute('data-ms-tab') === key); }
			});
		});
	});
})();

// Only the chosen provider's credential fields are on screen.
(function () {
	var select = document.getElementById('email_service');
	if (!select) { return; }
	function toggle() {
		document.querySelectorAll('.setup-provider-fields').forEach(function (div) {
			div.classList.toggle('d-none', div.getAttribute('data-email-provider') !== select.value);
		});
	}
	select.addEventListener('change', toggle);
	toggle();
})();

// The EU checkbox drives the endpoint value too, not just its visibility:
// ticking fills the standard EU address into an empty field, unticking clears
// it so the save stores nothing. A hidden field still submits, so clearing on
// untick is what makes the checkbox honest.
(function () {
	var box = document.getElementById('mailgun_eu_region');
	var field = document.getElementById('mailgun_eu_api_link');
	if (!box || !field) { return; }
	box.addEventListener('change', function () {
		if (box.checked) {
			if (!field.value) { field.value = 'https://api.eu.mailgun.net'; }
		} else {
			field.value = '';
		}
	});
})();

// The DNS-stage publish form. Ungated host: its credential fields, nothing
// else. Gated host: the amber restriction notice and the three-way radio
// lead, and the choice drives the page — "api" drops the amber and reveals
// the credential form, "manual" grays this tab and lands on the manual one,
// "move" opens the guided Linode transfer below the form.
(function () {
	var provider = document.getElementById('dns_provider');
	if (!provider) { return; }
	var autoTab = document.querySelector('[data-ms-tab="auto"]');
	var manualTab = document.querySelector('[data-ms-tab="manual"]');
	var submitRow = document.getElementById('setup-ms-submit');
	var moveBlock = document.getElementById('setup-ms-move');
	function sync() {
		var key = provider.value;
		var gate = document.querySelector('.setup-ms-gate[data-dns-driver="' + key + '"]');
		var checked = gate ? gate.querySelector('input[type="radio"]:checked') : null;
		var choice = checked ? checked.value : '';
		var mismatch = document.querySelector('.setup-ms-mismatch[data-dns-driver="' + key + '"]');
		// A Linode pick when the domain lives elsewhere means "move me there":
		// the mismatch notice plus the move form replace the publish form.
		var moveInstead = !!(mismatch && mismatch.getAttribute('data-move-instead') === '1');
		document.querySelectorAll('.setup-ms-gate').forEach(function (div) {
			div.classList.toggle('d-none', div.getAttribute('data-dns-driver') !== key);
		});
		document.querySelectorAll('.setup-ms-mismatch').forEach(function (div) {
			div.classList.toggle('d-none', div.getAttribute('data-dns-driver') !== key);
		});
		if (gate) {
			var amber = gate.querySelector('.setup-ms-gate-amber');
			if (amber) { amber.classList.toggle('d-none', choice === 'api'); }
		}
		document.querySelectorAll('.setup-ms-cred').forEach(function (div) {
			var mine = div.getAttribute('data-dns-driver') === key;
			div.classList.toggle('d-none', !mine || (!!gate && choice !== 'api') || moveInstead);
		});
		if (submitRow) { submitRow.classList.toggle('d-none', (!!gate && choice !== 'api') || moveInstead); }
		if (moveBlock) { moveBlock.classList.toggle('d-none', !moveInstead && (!gate || choice !== 'move')); }
		if (autoTab) { autoTab.style.opacity = (gate && choice === 'manual') ? '0.5' : ''; }
		if (gate && choice === 'manual' && manualTab && manualTab.getAttribute('aria-selected') !== 'true') {
			manualTab.click();
		}
	}
	provider.addEventListener('change', sync);
	document.querySelectorAll('.setup-ms-gate input[type="radio"]').forEach(function (radio) {
		radio.addEventListener('change', sync);
	});
	sync();
})();
