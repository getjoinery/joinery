<?php
/**
 * Cloud Storage Admin Page
 *
 * Single-button workflow: Save runs Test Connection, persists settings,
 * activates the sync task. When enabled, Pause and "Disable and Pull
 * Files Back to Local" appear alongside Save. Health status block at top.
 * Carries the optional private-store bucket field + its privacy-gate results,
 * and a private-store "Disable and Pull Back" off-ramp when it holds cloud objects.
 *
 * @version 1.2
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('adm/logic/admin_cloud_storage_logic.php'));

$page_vars = process_logic(admin_cloud_storage_logic(array_merge($_GET, $_POST)));
extract($page_vars);

$page = new AdminPage();
$page->admin_header(array(
	'menu-id' => null,
	'page_title' => 'Cloud Storage',
	'readable_title' => 'Cloud Storage',
	'breadcrumbs' => array(
		'Settings' => '/admin/admin_settings',
		'Cloud Storage' => '',
	),
	'session' => $session,
));

// =====================================================
// HEALTH STATUS BLOCK
// =====================================================
$pageoptions = array('title' => 'Status');
$page->begin_box($pageoptions);

$dot = function($color) {
	return '<span style="display:inline-block; width:10px; height:10px; border-radius:50%; background:' . $color . '; margin-right:6px; vertical-align:middle;"></span>';
};

// Cron heartbeat
echo '<div style="margin-bottom: 8px;">';
if ($health['cron']['ok']) {
	echo $dot('#28a745') . '<strong>Cron heartbeat:</strong> healthy ';
	echo '<small class="text-muted">(last tick: ' . htmlspecialchars(LibraryFunctions::convert_time($health['cron']['last'], 'UTC', $session->get_timezone())) . ')</small>';
} else {
	echo $dot('#dc3545') . '<strong>Cron heartbeat:</strong> not running. ';
	if ($health['cron']['last']) {
		echo 'Last tick: ' . htmlspecialchars(LibraryFunctions::convert_time($health['cron']['last'], 'UTC', $session->get_timezone()));
	} else {
		echo 'Last tick: <em>never</em>.';
	}
	echo '<br><small>New uploads aren\'t migrating to cloud. Verify the crontab and the cron daemon.</small>';
}
echo '</div>';

// Driver health
echo '<div style="margin-bottom: 8px;">';
if (!$enabled) {
	echo $dot('#999') . '<strong>Driver:</strong> not enabled';
} elseif ($health['driver'] && $health['driver']['ok']) {
	$color = $health['driver']['elapsed_ms'] > 2000 ? '#ffc107' : '#28a745';
	echo $dot($color) . '<strong>Driver:</strong> ' . htmlspecialchars($health['driver']['message']) . ' ';
	echo '<small class="text-muted">(' . (int)$health['driver']['elapsed_ms'] . ' ms)</small>';
} else {
	$msg = $health['driver']['message'] ?? 'unknown';
	echo $dot('#dc3545') . '<strong>Driver:</strong> ' . htmlspecialchars($msg);
	echo '<br><small>Save again to re-run the full diagnostic.</small>';
}
echo '</div>';

// Sync task
echo '<div style="margin-bottom: 8px;">';
if ($health['sync_task']) {
	$color = $health['sync_task']['is_active']
		? ($health['sync_task']['last_status'] === 'error' ? '#dc3545' : '#28a745')
		: '#999';
	echo $dot($color) . '<strong>Sync task:</strong> ';
	echo $health['sync_task']['is_active'] ? 'active' : 'inactive';
	if ($health['sync_task']['last_run']) {
		echo ' &middot; last run: ' . htmlspecialchars(LibraryFunctions::convert_time($health['sync_task']['last_run'], 'UTC', $session->get_timezone()));
		if ($health['sync_task']['last_status']) echo ' (' . htmlspecialchars($health['sync_task']['last_status']) . ')';
		if ($health['sync_task']['last_message']) {
			echo '<br><small class="text-muted">' . htmlspecialchars($health['sync_task']['last_message']) . '</small>';
		}
	}
} else {
	echo $dot('#999') . '<strong>Sync task:</strong> not registered';
}
echo '</div>';

// File counts
echo '<div style="margin-bottom: 8px;">';
echo $dot('#0d6efd') . '<strong>Files:</strong> ';
echo (int)$health['counts']['cloud'] . ' in cloud &middot; ';
echo (int)$health['counts']['pending'] . ' pending migration &middot; ';
echo (int)$health['counts']['migrated_this_week'] . ' migrated this week';
if ($health['counts']['stuck'] > 0) {
	echo ' &middot; <span style="color:#dc3545;"><strong>' . (int)$health['counts']['stuck'] . ' stuck</strong></span>';
}
echo '</div>';

// Private store status
echo '<div style="margin-bottom: 8px;">';
if (!empty($private_status['enabled'])) {
	echo $dot('#28a745') . '<strong>Private store:</strong> enabled (verified non-public)';
	if (!empty($private_status['cloud_count'])) {
		echo ' &middot; ' . (int)$private_status['cloud_count'] . ' object(s) in cloud';
	}
} elseif (!empty($private_status['configured'])) {
	echo $dot('#ffc107') . '<strong>Private store:</strong> bucket configured but not gated. Save to run the anonymous-read-denied check.';
} else {
	echo $dot('#999') . '<strong>Private store:</strong> not configured';
}
echo '</div>';

// Private-store pull-back: offered whenever the private store is enabled or still
// holds cloud objects, so its off-ramp mirrors the public store's.
$private_cloud = (int)($private_status['cloud_count'] ?? 0);
if (!empty($private_status['enabled']) || $private_cloud > 0) {
	echo '<div style="margin-bottom: 12px;">';
	$pconfirm = 'Disable the private store and PULL ALL ' . $private_cloud
		. ' cloud-stored object(s) (offloaded inbound-mail raw) BACK TO LOCAL DISK? '
		. 'Ensure enough free space before continuing. The bucket binding stays until you clear it and Save once the count reaches zero.';
	echo AdminPage::action_button('Disable Private Store and Pull Back', '/admin/admin_cloud_storage', array(
		'hidden'  => array('action' => 'disable_and_pull_private'),
		'confirm' => $pconfirm,
		'class'   => 'btn btn-danger btn-sm',
	));
	echo '</div>';
}

// Reverse task (only when active)
if (!empty($health['reverse_task'])) {
	echo '<div style="margin-bottom: 8px;">';
	echo $dot('#0d6efd') . '<strong>Pull-back in progress.</strong> ';
	if ($health['reverse_task']['last_run']) {
		echo 'Last run: ' . htmlspecialchars(LibraryFunctions::convert_time($health['reverse_task']['last_run'], 'UTC', $session->get_timezone()));
		if ($health['reverse_task']['last_message']) {
			echo '<br><small class="text-muted">' . htmlspecialchars($health['reverse_task']['last_message']) . '</small>';
		}
	}
	echo '</div>';
}

// Stuck files list
if (!empty($health['stuck_rows'])) {
	echo '<div style="margin-top: 16px;">';
	echo '<strong>Stuck files (failed 5+ times):</strong>';
	echo '<table class="table table-sm" style="margin-top: 6px;"><thead><tr>';
	echo '<th>File</th><th>Last attempt</th><th>Failures</th><th></th>';
	echo '</tr></thead><tbody>';
	foreach ($health['stuck_rows'] as $row) {
		echo '<tr>';
		echo '<td>' . htmlspecialchars($row['fbb_stored_name']) . ' <small class="text-muted">(#' . (int)$row['fbb_file_blob_id'] . ')</small></td>';
		echo '<td>' . ($row['fbb_sync_last_attempt'] ? htmlspecialchars(LibraryFunctions::convert_time($row['fbb_sync_last_attempt'], 'UTC', $session->get_timezone())) : '—') . '</td>';
		echo '<td>' . (int)$row['fbb_sync_failed_count'] . '</td>';
		echo '<td>';
		echo '<form method="post" action="/admin/admin_cloud_storage" style="display:inline;">';
		echo '<input type="hidden" name="action" value="retry_stuck">';
		echo '<input type="hidden" name="fbb_file_blob_id" value="' . (int)$row['fbb_file_blob_id'] . '">';
		echo '<button type="submit" class="btn btn-sm btn-outline-primary">Retry</button>';
		echo '</form>';
		echo '</td>';
		echo '</tr>';
	}
	echo '</tbody></table>';
	echo '</div>';
}

$page->end_box();

// =====================================================
// VALIDATION ERRORS (form-level)
// =====================================================
if (!empty($errors)) {
	echo '<div class="alert alert-danger">';
	echo '<strong>Settings not saved:</strong><ul style="margin-bottom:0;">';
	foreach ($errors as $e) echo '<li>' . htmlspecialchars($e) . '</li>';
	echo '</ul></div>';
}

// =====================================================
// TEST CONNECTION RESULTS (rendered inline after a failed save)
// =====================================================
if (!empty($test_results)) {
	$pageoptions = array('title' => 'Test Connection results');
	$page->begin_box($pageoptions);
	if (!$test_results['ok']) {
		echo '<div class="alert alert-danger">Settings were NOT saved. Fix the failed step below and Save again.</div>';
	}
	echo '<table class="table table-sm" style="max-width: 800px;"><tbody>';
	foreach ($test_results['steps'] as $step) {
		$icon_color = '#999';
		$icon = '—';
		if ($step['status'] === 'pass') { $icon = '✓'; $icon_color = '#28a745'; }
		elseif ($step['status'] === 'fail') { $icon = '✗'; $icon_color = '#dc3545'; }
		elseif ($step['status'] === 'warn') { $icon = '!'; $icon_color = '#ffc107'; }
		echo '<tr>';
		echo '<td style="width:30px; color:' . $icon_color . '; font-weight:bold; font-size: 1.2em;">' . $icon . '</td>';
		echo '<td><strong>' . htmlspecialchars($step['label']) . ':</strong> ' . htmlspecialchars($step['message']);
		if (!empty($step['raw'])) {
			echo '<br><small class="text-muted">Raw: <code>' . htmlspecialchars($step['raw']) . '</code></small>';
		}
		echo '</td>';
		echo '</tr>';
	}
	echo '</tbody></table>';
	$page->end_box();
}

// =====================================================
// PRIVATE STORE: errors + privacy-gate results (inline after a failed save)
// =====================================================
if (!empty($private_errors)) {
	echo '<div class="alert alert-danger">';
	echo '<strong>Private store not saved:</strong><ul style="margin-bottom:0;">';
	foreach ($private_errors as $e) echo '<li>' . htmlspecialchars($e) . '</li>';
	echo '</ul></div>';
}
if (!empty($private_test_results)) {
	$pageoptions = array('title' => 'Private store — privacy gate results');
	$page->begin_box($pageoptions);
	if (!$private_test_results['ok']) {
		echo '<div class="alert alert-danger">The private bucket was NOT enabled. Anonymous reads must be denied before it can hold private files.</div>';
	}
	echo '<table class="table table-sm" style="max-width: 800px;"><tbody>';
	foreach ($private_test_results['steps'] as $step) {
		$icon_color = '#999'; $icon = '—';
		if ($step['status'] === 'pass') { $icon = '✓'; $icon_color = '#28a745'; }
		elseif ($step['status'] === 'fail') { $icon = '✗'; $icon_color = '#dc3545'; }
		elseif ($step['status'] === 'warn') { $icon = '!'; $icon_color = '#ffc107'; }
		echo '<tr>';
		echo '<td style="width:30px; color:' . $icon_color . '; font-weight:bold; font-size: 1.2em;">' . $icon . '</td>';
		echo '<td><strong>' . htmlspecialchars($step['label']) . ':</strong> ' . htmlspecialchars($step['message']);
		if (!empty($step['raw'])) {
			echo '<br><small class="text-muted">Raw: <code>' . htmlspecialchars($step['raw']) . '</code></small>';
		}
		echo '</td>';
		echo '</tr>';
	}
	echo '</tbody></table>';
	$page->end_box();
}

// =====================================================
// SETTINGS FORM
// =====================================================
$pageoptions = array('title' => $enabled ? 'Cloud Storage Settings' : 'Configure Cloud Storage');
$page->begin_box($pageoptions);

if (!$enabled) {
	echo '<p style="color:#666;">Public uploaded files (photos, gallery images, blog images) can be moved to a customer-owned S3-compatible bucket. Permissioned/private files always stay on local disk.</p>';
} else {
	echo '<p style="color:#666; margin-bottom: 8px;"><span style="display:inline-block; width:10px; height:10px; border-radius:50%; background:#28a745; margin-right:6px;"></span><strong>Cloud storage is active.</strong> Click Save to re-test the connection. Pause to stop new migrations (existing cloud-stored files keep serving from the bucket).</p>';
}

$formwriter = $page->getFormWriter('cloud_storage_form', ['action' => '/admin/admin_cloud_storage', 'method' => 'post', 'id' => 'cloud_storage_form']);
$formwriter->begin_form();
$formwriter->hiddeninput('action', '', array('value' => 'save'));

$formwriter->textinput('cloud_storage_endpoint', 'Endpoint Hostname', array(
	'value' => $settings_values['endpoint'],
	'helptext' => 'e.g. s3.us-west-002.backblazeb2.com or s3.amazonaws.com. With or without https://.',
));

$formwriter->textinput('cloud_storage_region', 'Region', array(
	'value' => $settings_values['region'],
	'helptext' => 'e.g. us-east-1, us-west-002. Auto-fills on endpoint blur if recognizable.',
));

$formwriter->textinput('cloud_storage_bucket', 'Bucket Name', array(
	'value' => $settings_values['bucket'],
));

$formwriter->textinput('cloud_storage_access_key', 'Access Key', array(
	'value' => $settings_values['access_key'],
));

$formwriter->textinput('cloud_storage_secret_key', 'Secret Key', array(
	'value' => $settings_values['secret_key'],
	'helptext' => 'Stored in stg_settings; rotate via the bucket provider if exposure is suspected.',
));

$formwriter->textinput('cloud_storage_public_base_url', 'Public Base URL (optional)', array(
	'value' => $settings_values['public_base_url'],
	'helptext' => 'Leave empty to auto-derive from endpoint+bucket. Set this when fronting the bucket with a CDN (e.g. https://cdn.example.com).',
));

// Private store — one more bucket on the SAME credentials above. Verified
// non-public (anonymous-read-denied) before any byte lands. Leave empty for
// no private store.
$formwriter->textinput('cloud_storage_private_bucket', 'Private Bucket Name (optional)', array(
	'value' => $settings_values['private_bucket'],
	'helptext' => 'A separate bucket on the SAME provider/account (shares the endpoint, region, and keys above). On Save it is tested to confirm anonymous reads are DENIED before it is enabled. Leave empty for no private store.',
));

// Egress-cost inline banner (live as the admin types — reflects current value).
echo '<div id="egress_warning" style="display:none;" class="alert alert-warning">'
	. '<strong>Egress warning:</strong> This looks like a raw <span id="egress_provider">bucket</span> URL. Without a CDN you\'ll pay egress on every file view, which can exceed storage savings. '
	. 'Cheaper patterns: B2 + Cloudflare (free egress via Bandwidth Alliance), Cloudflare R2, or Bunny.net in front of a bucket. See <code>docs/cloud_storage.md</code>.'
	. '</div>';

echo '<div style="margin-top: 18px; display: flex; gap: 8px; flex-wrap: wrap;">';
$formwriter->submitbutton('btn_save', 'Save', array('class' => 'btn btn-primary'));
echo '</div>';
echo $formwriter->end_form();

if ($enabled) {
	echo '<div style="margin-top: 12px; display: flex; gap: 8px; flex-wrap: wrap;">';
	echo AdminPage::action_button('Pause Cloud Storage', '/admin/admin_cloud_storage', array(
		'hidden'  => array('action' => 'pause'),
		'confirm' => 'Pause cloud storage? Existing cloud-stored files will continue to serve from the bucket; new uploads will stay local.',
		'class'   => 'btn btn-warning',
	));
	$pull_count = (int)$health['counts']['cloud'];
	$disk_free = function_exists('disk_free_space') ? @disk_free_space($settings_values['public_base_url'] ? '/tmp' : '/') : null;
	$free_label = $disk_free !== null ? round($disk_free / 1024 / 1024 / 1024, 1) . ' GB free' : 'unknown free space';
	$confirm_msg = 'Disable cloud storage and PULL ALL ' . $pull_count . ' bucket-stored files BACK TO LOCAL DISK? Local disk: ' . $free_label . '. Ensure several GB of free space before continuing.';
	echo AdminPage::action_button('Disable and Pull Files Back to Local', '/admin/admin_cloud_storage', array(
		'hidden'  => array('action' => 'disable_and_pull'),
		'confirm' => $confirm_msg,
		'class'   => 'btn btn-danger',
	));
	echo '</div>';
}

$page->end_box();

// =====================================================
// CLIENT-SIDE: live egress warning + region auto-fill + pre-save confirm
// =====================================================
?>
<script>
(function() {
	function detectRawHost(host) {
		var h = (host || '').toLowerCase();
		if (!h) return null;
		if (/\.amazonaws\.com$/.test(h))          return 'AWS S3';
		if (/\.backblazeb2\.com$/.test(h))        return 'Backblaze B2';
		if (/\.wasabisys\.com$/.test(h))          return 'Wasabi';
		if (/\.digitaloceanspaces\.com$/.test(h)) return 'DigitalOcean Spaces';
		return null;
	}
	function hostnameOf(s) {
		if (!s) return '';
		try { return new URL(s.indexOf('://') === -1 ? 'https://' + s : s).hostname; }
		catch (e) { return ''; }
	}

	var endpoint = document.getElementById('cloud_storage_endpoint');
	var region   = document.getElementById('cloud_storage_region');
	var publicUrl = document.getElementById('cloud_storage_public_base_url');
	var warningBox = document.getElementById('egress_warning');
	var warningProvider = document.getElementById('egress_provider');

	function refreshEgressWarning() {
		var src = (publicUrl && publicUrl.value) ? publicUrl.value : (endpoint ? endpoint.value : '');
		var host = hostnameOf(src);
		var provider = detectRawHost(host);
		if (provider) {
			warningProvider.textContent = provider;
			warningBox.style.display = 'block';
		} else {
			warningBox.style.display = 'none';
		}
	}
	if (publicUrl) publicUrl.addEventListener('input', refreshEgressWarning);
	if (endpoint)  endpoint.addEventListener('input', refreshEgressWarning);
	refreshEgressWarning();

	// Region auto-fill on endpoint blur.
	if (endpoint && region) {
		endpoint.addEventListener('blur', function() {
			if (region.value) return;
			var host = hostnameOf(endpoint.value);
			// s3.<region>.backblazeb2.com  → us-west-002
			// s3.<region>.amazonaws.com    → us-east-1
			var m = host.match(/^s3[.-]([a-z0-9-]+)\.(amazonaws|backblazeb2|wasabisys|digitaloceanspaces)\.com$/);
			if (m && m[1] && m[1] !== 's3') region.value = m[1];
		});
	}

	// Pre-enable confirm dialog when raw bucket URL detected.
	var form = document.getElementById('cloud_storage_form');
	if (form) {
		form.addEventListener('submit', function(e) {
			// Only on Save action (not the action_button POSTs which submit standalone forms).
			var actionInput = form.querySelector('input[name="action"]');
			if (!actionInput || actionInput.value !== 'save') return;
			var src = (publicUrl && publicUrl.value) ? publicUrl.value : (endpoint ? endpoint.value : '');
			var provider = detectRawHost(hostnameOf(src));
			if (provider) {
				e.preventDefault();
				var msg = 'Your public URL appears to be a raw ' + provider + ' bucket. '
				        + 'Without a CDN you\'ll pay egress on every file view, which can exceed storage savings. '
				        + 'Continue anyway?';
				JoineryModal.confirm(msg, function() { form.submit(); }, { confirmLabel: 'Continue', confirmStyle: 'primary' });
			}
		});
	}
})();
</script>
<?php

$page->admin_footer();
