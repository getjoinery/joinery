<?php

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('querylog_logic.php', 'logic', 'system', null, 'scrolldaddy'));

$page_vars       = process_logic(querylog_logic($_GET, $_POST));
$device          = $page_vars['device'];
$device_name     = $page_vars['device_name'];
$lines           = $page_vars['lines'];
$lines_requested = $page_vars['lines_requested'];
$fetch_error     = $page_vars['fetch_error'];

$device_id = (int)$device->key;

$page = new PublicPage();
$hoptions = array(
	'is_valid_page' => $is_valid_page,
	'title'         => 'Query Log',
	'breadcrumbs'   => array(
		'Devices'   => '/profile/devices',
		'Query Log' => '',
	),
);
$page->public_header($hoptions, NULL);
echo PublicPage::BeginPage('Query Log', $hoptions);

if (!$device->get('sdd_is_active')) {

	echo '
	<section class="space">
		<div class="container">
			<div class="error-content">
				<h2 class="error-title">Device Not Activated</h2>
				<p class="error-text">This device has not been activated yet. There is no query log to view.</p>
				<a href="/profile/activation?device_id=' . $device_id . '" class="th-btn">Activate Device</a>
			</div>
		</div>
	</section>';

} elseif (!$device->get('sdd_log_queries')) {

	echo '
	<section class="space">
		<div class="container">
			<div class="error-content">
				<h2 class="error-title">Query Log &mdash; ' . $device_name . '</h2>
				<p class="error-text">Query logging is not enabled for this device. <a href="/profile/device_edit?device_id=' . $device_id . '">Enable it on the device edit page</a>.</p>
			</div>
		</div>
	</section>';

} else {

	echo '
	<section class="space">
		<div class="container">
			<h2 style="margin-bottom:16px;">Query Log &mdash; ' . $device_name . '</h2>';

	if ($fetch_error) {
		echo '<div style="background:#fff3cd; border:1px solid #ffc107; padding:12px 16px; border-radius:6px; margin-bottom:20px;">Could not retrieve log from the DNS server. Please try again in a moment.</div>';
	}

	// Controls row
	echo '
		<div style="display:flex; align-items:center; gap:16px; margin-bottom:16px; flex-wrap:wrap;">
			<form method="GET" action="/profile/querylog" style="display:flex; align-items:center; gap:8px;">
				<input type="hidden" name="device_id" value="' . $device_id . '">
				<label for="scd-lines-select" style="margin:0; font-size:14px; white-space:nowrap;">Show:</label>
				<select id="scd-lines-select" name="lines" onchange="this.form.submit()" style="padding:4px 8px; border:1px solid #ddd; border-radius:4px; font-size:14px;">
					<option value="100"' . ($lines_requested == 100 ? ' selected' : '') . '>100 entries</option>
					<option value="250"' . ($lines_requested == 250 ? ' selected' : '') . '>250 entries</option>
					<option value="500"' . ($lines_requested == 500 ? ' selected' : '') . '>500 entries</option>
				</select>
			</form>
			<button type="button" id="scd-clear-btn" class="th-btn style5" style="background:#dc3545; border-color:#dc3545; color:#fff;">Clear Log</button>
			<span id="scd-clear-status" style="font-size:13px; color:#6c757d;"></span>
		</div>';

	if (!$fetch_error && count($lines) === 0) {

		echo '<p style="color:#6c757d;">No queries logged yet. Queries will appear here once the device starts using ScrollDaddy DNS.</p>';

	} elseif (!$fetch_error && count($lines) > 0) {

		$category_names = array(
			'ads_small'    => 'Ads (Light)',
			'ads_medium'   => 'Ads (Medium)',
			'ads'          => 'Ads (Strict)',
			'malware'      => 'Malware',
			'ip_malware'   => 'Malware + IP Threats',
			'ai_malware'   => 'Malware + Phishing',
			'typo'         => 'Phishing & Typosquatting',
			'porn'         => 'Adult Content',
			'porn_strict'  => 'Adult Content (Strict)',
			'gambling'     => 'Gambling',
			'social'       => 'Social Media',
			'fakenews'     => 'Disinformation',
			'cryptominers' => 'Cryptomining',
			'dating'       => 'Dating',
			'drugs'        => 'Drugs',
			'games'        => 'Gaming',
			'ddns'         => 'Dynamic DNS',
			'dnsvpn'       => 'DNS/VPN Bypass',
		);

		$device_tz = $device->get('sdd_timezone') ?: 'UTC';

		echo '
		<div style="overflow-x:auto;">
		<table style="width:100%; border-collapse:collapse; font-size:14px;">
			<thead>
				<tr style="border-bottom:2px solid #e0e0e0; text-align:left;">
					<th style="padding:8px 12px; white-space:nowrap;">Time</th>
					<th style="padding:8px 12px;">Domain</th>
					<th style="padding:8px 12px;">Type</th>
					<th style="padding:8px 12px;">Result</th>
					<th style="padding:8px 12px;">Reason</th>
					<th style="padding:8px 12px;">Cached</th>
				</tr>
			</thead>
			<tbody>';

		foreach ($lines as $entry) {

			// Format timestamp in device timezone
			try {
				$dt = new DateTime($entry['timestamp'], new DateTimeZone('UTC'));
				$dt->setTimezone(new DateTimeZone($device_tz));
				$time_display = $dt->format('M j, Y g:i:s A T');
			} catch (Exception $e) {
				$time_display = htmlspecialchars($entry['timestamp']);
			}

			// Result badge colour
			$result = strtoupper($entry['result']);
			switch ($result) {
				case 'BLOCKED':
					$badge_style = 'background:#dc3545; color:#fff;';
					break;
				case 'REFUSED':
					$badge_style = 'background:#fd7e14; color:#fff;';
					break;
				case 'FORWARDED':
					$badge_style = 'background:#198754; color:#fff;';
					break;
				default:
					$badge_style = 'background:#6c757d; color:#fff;';
					break;
			}

			// Human-readable reason
			$reason_key = $entry['reason'];
			$category   = $entry['category'];
			if ($reason_key === 'category_blocklist') {
				$cat_name       = isset($category_names[$category]) ? $category_names[$category] : $category;
				$reason_display = 'Blocked: ' . htmlspecialchars($cat_name);
			} elseif ($reason_key === 'custom_block_rule') {
				$reason_display = 'Custom block rule';
			} elseif ($reason_key === 'custom_allow_rule') {
				$reason_display = 'Custom allow rule';
			} elseif ($reason_key === 'safesearch_rewrite') {
				$reason_display = 'SafeSearch rewrite';
			} elseif ($reason_key === 'safeyoutube_rewrite') {
				$reason_display = 'Safe YouTube rewrite';
			} elseif ($reason_key === 'not_blocked') {
				$reason_display = 'Allowed';
			} elseif ($reason_key === 'unknown_device') {
				$reason_display = 'Unknown device';
			} elseif ($reason_key === 'inactive_device') {
				$reason_display = 'Device inactive';
			} elseif ($reason_key === 'upstream_failed') {
				$reason_display = 'Upstream DNS failed';
			} else {
				$reason_display = htmlspecialchars($reason_key);
			}

			$cached_lower   = strtolower(trim($entry['cached']));
			$cached_display = ($cached_lower === 'yes' || $cached_lower === '1' || $cached_lower === 'true') ? 'Yes' : 'No';

			echo '
			<tr style="border-bottom:1px solid #f0f0f0;">
				<td style="padding:7px 12px; white-space:nowrap; font-size:13px; color:#555;">' . $time_display . '</td>
				<td style="padding:7px 12px; word-break:break-all;">' . htmlspecialchars($entry['domain']) . '</td>
				<td style="padding:7px 12px;">' . htmlspecialchars($entry['qtype']) . '</td>
				<td style="padding:7px 12px;"><span style="' . $badge_style . ' padding:2px 8px; border-radius:3px; font-size:12px; font-weight:600;">' . htmlspecialchars($result) . '</span></td>
				<td style="padding:7px 12px;">' . $reason_display . '</td>
				<td style="padding:7px 12px;">' . $cached_display . '</td>
			</tr>';
		}

		echo '
			</tbody>
		</table>
		</div>';
	}

	echo '
		</div>
	</section>';
}

echo PublicPage::EndPage();
?>
<script>
(function () {
	var clearBtn = document.getElementById('scd-clear-btn');
	var statusEl = document.getElementById('scd-clear-status');
	var deviceId = <?php echo $device_id; ?>;

	if (!clearBtn) return;

	clearBtn.addEventListener('click', function () {
		if (!confirm('Are you sure you want to clear the query log for this device? This cannot be undone.')) {
			return;
		}
		clearBtn.disabled = true;
		statusEl.textContent = 'Clearing\u2026';

		var xhr = new XMLHttpRequest();
		xhr.open('POST', '/ajax/purge_querylog');
		xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
		xhr.onload = function () {
			if (xhr.status !== 200) {
				statusEl.textContent = 'Error clearing log. Please try again.';
				clearBtn.disabled = false;
				return;
			}
			var data;
			try { data = JSON.parse(xhr.responseText); } catch (e) {
				statusEl.textContent = 'Error clearing log. Please try again.';
				clearBtn.disabled = false;
				return;
			}
			if (data.success) {
				window.location.reload();
			} else {
				statusEl.textContent = data.message || 'Error clearing log.';
				clearBtn.disabled = false;
			}
		};
		xhr.onerror = function () {
			statusEl.textContent = 'Network error. Please try again.';
			clearBtn.disabled = false;
		};
		xhr.send('device_id=' + encodeURIComponent(deviceId));
	});
})();
</script>
<?php
	$page->public_footer($foptions = array('track' => TRUE, 'show_survey' => TRUE));
?>
