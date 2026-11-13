/* Checks for tests/unit/consent_banner_test.php; the page names its mode. */
(function () {
	'use strict';
	var results = document.getElementById('results');
	var mode = JSON.parse(document.getElementById('joinery-consent-config').textContent).mode;
	function report(name, ok, detail) {
		var li = document.createElement('li');
		li.setAttribute('data-result', ok ? 'pass' : 'fail');
		li.setAttribute('data-name', mode + ': ' + name);
		li.textContent = name + (ok ? '' : ' :: ' + (detail || ''));
		results.appendChild(li);
	}
	function $(id) { return document.getElementById(id); }
	function run() {
		try {
			if (mode === 'ccpa') {
				report('tracking runs until the visitor opts out', window.__ran === 1, String(window.__ran));
				report('no banner is shown', !$('joinery-cc-banner'));
				$('ccpa-link').click();
				report('the Do Not Sell link opens the preferences', !!$('joinery-cc-modal-overlay'));
				report('with both boxes showing what holds now (on)', $('joinery-cc-analytics').checked && $('joinery-cc-marketing').checked);
				$('joinery-cc-analytics').checked = false; $('joinery-cc-marketing').checked = false;
				$('joinery-cc-save').click();
				report('opting out stores the choice', /joinery_cookie_consent=[^;]*%22a%22%3A0/.test(document.cookie), document.cookie);
				$('ccpa-link').click();
				report('opting out holds: the dialog reopens with both off', !$('joinery-cc-analytics').checked && !$('joinery-cc-marketing').checked);
				report('and records it', window.__posts.length === 1 && window.__posts[0].indexOf('"analytics":false') !== -1, window.__posts.join(' '));
			} else {
				report('nothing is released before a choice', !window.__ran, String(window.__ran));
				var banner = $('joinery-cc-banner');
				report('the banner asks', !!banner);
				var link = banner && banner.querySelector('a.joinery-cc-link');
				report('the policy link keeps its whole address in href, with no attribute broken out',
					!!link && link.getAttribute('href') === '/privacy" onmouseover="x' && !link.hasAttribute('onmouseover'), link && link.outerHTML);
				$('joinery-cc-manage').click();
				report('the preferences open with both boxes off', !$('joinery-cc-analytics').checked && !$('joinery-cc-marketing').checked);
				$('joinery-cc-accept-all').click();
				report('Accept All releases the held-back tag', window.__ran === 1, String(window.__ran));
				report('and the banner goes', !$('joinery-cc-banner'));
			}
		} catch (e) {
			report('the checks ran without throwing', false, String(e && e.stack || e));
		}
		document.body.setAttribute('data-done', '1');
	}
	if (document.readyState === 'complete') run(); else window.addEventListener('load', run);
})();
