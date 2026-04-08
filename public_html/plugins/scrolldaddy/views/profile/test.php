<?php

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('test_logic.php', 'logic', 'system', null, 'scrolldaddy'));

$page_vars     = process_logic(test_logic($_GET, $_POST));
$device        = $page_vars['device'];
$device_id     = (int)$device->key;
$device_name   = htmlspecialchars($device->get_readable_name());
$is_active     = $device->get('sdd_is_active');
$can_add_rules = $page_vars['can_add_rules'];

$page    = new PublicPage();
$hoptions = array(
	'is_valid_page' => $is_valid_page,
	'title'         => 'Test a Domain/Page',
	'breadcrumbs'   => array(
		'Devices'            => '/profile/scrolldaddy/devices',
		'Test a Domain/Page' => '',
	),
);
$page->public_header($hoptions, NULL);
echo PublicPage::BeginPage('Test a Domain/Page', $hoptions);

if (!$is_active) {
	echo '
	<section class="space">
		<div class="container">
			<div class="error-content">
				<h2 class="error-title">Device Not Activated</h2>
				<p class="error-text">The domain test feature requires an activated device. Please activate <strong>' . $device_name . '</strong> first.</p>
				<a href="/profile/scrolldaddy/activation?device_id=' . $device_id . '" class="th-btn">Activate Device</a>
			</div>
		</div>
	</section>';
} else {
	echo '
	<section class="space">
		<div class="container">
			<div class="row justify-content-center">
				<div class="col-lg-8">

					<div class="job-post style2" style="margin-bottom:20px;">
						<div class="job-content">
							<h3 class="box-title">' . $device_name . '</h3>
							<p style="color:#888; margin-top:4px; font-size:14px;">Enter a domain to check how your filter handles it, or paste a full page URL to scan all domains that page loads.</p>
						</div>
						<div class="job-post_author" style="flex-wrap:wrap; gap:8px; align-items:center;">
							<div class="job-wrapp" style="flex:1; min-width:220px;">
								<input type="text" id="scd-test-input" placeholder="e.g. facebook.com or https://example.com/page" style="width:100%; padding:8px 12px; border:1px solid #ddd; border-radius:4px; font-size:15px;">
							</div>
							<button type="button" id="scd-test-btn" class="th-btn">Test</button>
						</div>
					</div>

					<div id="scd-result" style="display:none;"></div>

				</div>
			</div>
		</div>
	</section>';
}

echo PublicPage::EndPage();
?>
<script>
(function () {
	var deviceId     = <?php echo $device_id; ?>;
	var scdCanAddRules = <?php echo $can_add_rules ? 'true' : 'false'; ?>;

	var _svgAttrs = 'xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em;margin-right:4px;"';
	var _icons = {
		'xmark-circle':    '<svg ' + _svgAttrs + '><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
		'rotate':          '<svg ' + _svgAttrs + '><polyline points="23,4 23,10 17,10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>',
		'check-circle':    '<svg ' + _svgAttrs + '><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22,4 12,14.01 9,11.01"/></svg>',
		'ban':             '<svg ' + _svgAttrs + '><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>',
		'question-circle': '<svg ' + _svgAttrs + '><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>'
	};

	function escHtml(s) {
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(String(s)));
		return div.innerHTML;
	}

	function detectMode(input) {
		var noProto = input.replace(/^https?:\/\//, '');
		var match = noProto.match(/\/(.+)/);
		return (match && match[1].length > 0) ? 'url_scan' : 'domain';
	}

	function cleanDomain(input) {
		var d = input.trim().toLowerCase();
		d = d.replace(/^https?:\/\//, '');
		d = d.replace(/[\/\?#].*$/, '');
		d = d.replace(/\.+$/, '');
		return d.trim();
	}

	function runTest() {
		var inputEl   = document.getElementById('scd-test-input');
		var resultDiv = document.getElementById('scd-result');
		var input     = inputEl.value.trim();

		if (!input) {
			resultDiv.style.display = 'block';
			resultDiv.innerHTML = '<span style="color:#dc3545;">Please enter a domain or URL.</span>';
			return;
		}

		var mode = detectMode(input);

		if (mode === 'domain') {
			var domain = cleanDomain(input);
			if (!domain || domain.indexOf('.') === -1) {
				resultDiv.style.display = 'block';
				resultDiv.innerHTML = '<span style="color:#dc3545;">Please enter a valid domain (e.g. facebook.com).</span>';
				return;
			}
			resultDiv.style.display = 'block';
			resultDiv.innerHTML = '<span style="color:#6c757d;">Testing...</span>';

			var xhr = new XMLHttpRequest();
			xhr.open('GET', '/ajax/test_domain?device_id=' + deviceId + '&domain=' + encodeURIComponent(domain));
			xhr.onload = function () {
				if (xhr.status !== 200) { resultDiv.innerHTML = '<span style="color:#dc3545;">Request failed. Please try again.</span>'; return; }
				var data; try { data = JSON.parse(xhr.responseText); } catch (e) { resultDiv.innerHTML = '<span style="color:#dc3545;">Invalid response.</span>'; return; }
				if (!data.success) { resultDiv.innerHTML = '<span style="color:#dc3545;">' + escHtml(data.message) + '</span>'; return; }
				resultDiv.innerHTML = formatDomainResult(data);
			};
			xhr.onerror = function () { resultDiv.innerHTML = '<span style="color:#dc3545;">Network error. Please try again.</span>'; };
			xhr.send();

		} else {
			resultDiv.style.display = 'block';
			resultDiv.innerHTML = '<span style="color:#6c757d;">Fetching page\u2026 (this may take a few seconds)</span>';

			var xhr2 = new XMLHttpRequest();
			xhr2.open('POST', '/ajax/scan_url');
			xhr2.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
			xhr2.onload = function () {
				if (xhr2.status !== 200) { resultDiv.innerHTML = '<span style="color:#dc3545;">Request failed. Please try again.</span>'; return; }
				var data; try { data = JSON.parse(xhr2.responseText); } catch (e) { resultDiv.innerHTML = '<span style="color:#dc3545;">Invalid response.</span>'; return; }
				if (!data.success) { resultDiv.innerHTML = '<span style="color:#dc3545;">' + escHtml(data.message) + '</span>'; return; }
				resultDiv.innerHTML = formatScanResult(data);
			};
			xhr2.onerror = function () { resultDiv.innerHTML = '<span style="color:#dc3545;">Network error. Please try again.</span>'; };
			xhr2.send('device_id=' + encodeURIComponent(deviceId) + '&url=' + encodeURIComponent(input));
		}
	}

	function formatDomainResult(data) {
		var iconKey, color, label;
		if (data.result === 'BLOCKED') {
			iconKey = 'xmark-circle'; color = '#dc3545'; label = 'Blocked';
		} else if (data.result === 'FORWARDED' && (data.reason === 'safesearch_rewrite' || data.reason === 'safeyoutube_rewrite')) {
			iconKey = 'rotate'; color = '#0d6efd'; label = 'Rewritten';
		} else if (data.result === 'FORWARDED') {
			iconKey = 'check-circle'; color = '#198754'; label = 'Allowed';
		} else if (data.result === 'REFUSED') {
			iconKey = 'ban'; color = '#dc3545'; label = 'Refused';
		} else {
			iconKey = 'question-circle'; color = '#6c757d'; label = data.result;
		}

		var html = '<div class="job-post style2" style="padding:16px 20px;">';
		html += '<div style="font-weight:600; font-size:15px; color:' + color + ';">' + _icons[iconKey] + escHtml(data.domain) + ' &mdash; ' + label + '</div>';
		if (data.detail)   html += '<div style="font-size:13px; color:#6c757d; margin-top:4px; padding-left:20px;">' + escHtml(data.detail) + '</div>';
		if (data.profile)  html += '<div style="font-size:13px; color:#6c757d; padding-left:20px;">Active profile: ' + escHtml(data.profile) + '</div>';

		if (scdCanAddRules
				&& data.reason !== 'custom_block_rule' && data.reason !== 'custom_allow_rule'
				&& data.reason !== 'safesearch_rewrite' && data.reason !== 'safeyoutube_rewrite') {
			var ruleAction, ruleLabel;
			if (data.result === 'BLOCKED' || data.result === 'REFUSED') {
				ruleAction = 1; ruleLabel = 'Allow this domain';
			} else if (data.result === 'FORWARDED') {
				ruleAction = 0; ruleLabel = 'Block this domain';
			}
			if (ruleAction !== undefined) {
				html += '<div style="margin-top:10px; padding-left:20px;">';
				html += '<button type="button" class="scd-add-rule-btn th-btn"'
					  + ' data-domain="' + escHtml(data.domain) + '"'
					  + ' data-device="' + deviceId + '"'
					  + ' data-action="' + ruleAction + '">'
					  + escHtml(ruleLabel) + '</button>';
				html += '<span class="scd-rule-feedback" style="display:none; font-size:13px; margin-left:8px;"></span>';
				html += '</div>';
			}
		}

		html += '</div>';
		return html;
	}

	function renderGroup(label, items, color, collapsed, ruleAction) {
		var html = '<details' + (collapsed ? '' : ' open') + ' style="margin-bottom:8px;">';
		html += '<summary style="cursor:pointer; font-weight:600; color:' + color + '; padding:5px 0; user-select:none;">';
		html += escHtml(label) + ' <span style="font-size:13px; font-weight:400; color:#888;">(' + items.length + ')</span>';
		html += '</summary>';
		html += '<div style="padding-left:10px; margin-top:4px;">';
		items.forEach(function (r) {
			html += '<div style="padding:5px 0; border-bottom:1px solid #f0f0f0; display:flex; align-items:baseline; gap:8px;">';
			html += '<div style="flex:1;">';
			html += '<div style="font-size:14px;">' + escHtml(r.domain) + '</div>';
			if (r.detail) html += '<div style="font-size:12px; color:#888;">' + escHtml(r.detail) + '</div>';
			html += '</div>';
			if (scdCanAddRules && ruleAction !== null && r.result !== 'ERROR'
					&& r.reason !== 'custom_block_rule' && r.reason !== 'custom_allow_rule') {
				var btnLabel = ruleAction === 1 ? 'Allow' : 'Block';
				html += '<button type="button" class="scd-add-rule-btn th-btn"'
					  + ' data-domain="' + escHtml(r.domain) + '"'
					  + ' data-device="' + deviceId + '"'
					  + ' data-action="' + ruleAction + '">'
					  + escHtml(btnLabel) + '</button>';
				html += '<span class="scd-rule-feedback" style="display:none; font-size:12px;"></span>';
			}
			html += '</div>';
		});
		html += '</div></details>';
		return html;
	}

	function formatScanResult(data) {
		var results = data.results || [];
		var grouped = { BLOCKED: [], REFUSED: [], REWRITTEN: [], ALLOWED: [] };

		results.forEach(function (r) {
			if (r.result === 'BLOCKED') {
				grouped.BLOCKED.push(r);
			} else if (r.result === 'REFUSED') {
				grouped.REFUSED.push(r);
			} else if (r.result === 'FORWARDED' && (r.reason === 'safesearch_rewrite' || r.reason === 'safeyoutube_rewrite')) {
				grouped.REWRITTEN.push(r);
			} else {
				grouped.ALLOWED.push(r);
			}
		});

		var html = '<div class="job-post style2" style="padding:16px 20px;">';

		html += '<div style="font-weight:600; font-size:16px; margin-bottom:6px;">Scan complete: ' + escHtml(String(data.domains_checked)) + ' domains checked</div>';

		if (data.capped) {
			html += '<div style="font-size:13px; color:#888; margin-bottom:4px;">Showing first ' + escHtml(String(data.domains_checked)) + ' of ' + escHtml(String(data.domains_found)) + ' external domains found on the page.</div>';
		}
		if (data.truncated) {
			html += '<div style="font-size:13px; color:#e67e22; margin-bottom:4px;">Time limit reached \u2014 some domains may not have been checked.</div>';
		}

		var blockedCount = grouped.BLOCKED.length + grouped.REFUSED.length;
		html += '<div style="font-size:14px; color:#555; margin-bottom:14px; padding-bottom:12px; border-bottom:1px solid #eee;">';
		html += blockedCount + ' blocked &bull; ' + grouped.REWRITTEN.length + ' rewritten &bull; ' + grouped.ALLOWED.length + ' allowed';
		html += '</div>';

		if (grouped.BLOCKED.length > 0)   html += renderGroup('Blocked',   grouped.BLOCKED,   '#dc3545', false, 1);
		if (grouped.REFUSED.length > 0)   html += renderGroup('Refused',   grouped.REFUSED,   '#e67e22', false, 1);
		if (grouped.REWRITTEN.length > 0) html += renderGroup('Rewritten', grouped.REWRITTEN, '#0d6efd', false, null);
		if (grouped.ALLOWED.length > 0)   html += renderGroup('Allowed',   grouped.ALLOWED,   '#198754', true,  0);

		html += '</div>';
		return html;
	}

	var btn      = document.getElementById('scd-test-btn');
	var inputEl  = document.getElementById('scd-test-input');
	if (btn)     btn.addEventListener('click', runTest);
	if (inputEl) inputEl.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); runTest(); } });

	document.addEventListener('click', function (e) {
		var btn = e.target.closest('.scd-add-rule-btn');
		if (!btn) return;
		btn.disabled = true;
		var feedback = btn.parentNode.querySelector('.scd-rule-feedback');
		var xhr = new XMLHttpRequest();
		xhr.open('POST', '/profile/scrolldaddy/rules');
		xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
		xhr.onload = function () {
			var data;
			try { data = JSON.parse(xhr.responseText); } catch (e) {}
			if (data && data.success) {
				btn.style.display = 'none';
				feedback.style.display = 'inline';
				feedback.style.color = '#198754';
				feedback.innerHTML = 'Rule added. <a href="/profile/scrolldaddy/rules?device_id=' + encodeURIComponent(btn.dataset.device) + '">Manage rules \u2192</a>';
			} else {
				btn.disabled = false;
				feedback.style.display = 'inline';
				feedback.style.color = '#dc3545';
				feedback.textContent = (data && data.message) ? data.message : 'Failed to add rule.';
			}
		};
		xhr.onerror = function () {
			btn.disabled = false;
			feedback.style.display = 'inline';
			feedback.style.color = '#dc3545';
			feedback.textContent = 'Network error. Please try again.';
		};
		xhr.send(
			'ajax=1' +
			'&device_id='    + encodeURIComponent(btn.dataset.device) +
			'&sdr_hostname=' + encodeURIComponent(btn.dataset.domain) +
			'&sdr_action='   + encodeURIComponent(btn.dataset.action)
		);
	});
})();
</script>
<?php
	$page->public_footer($foptions = array('track' => TRUE, 'show_survey' => TRUE));
?>
