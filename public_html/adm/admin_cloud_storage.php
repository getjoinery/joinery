<?php
/**
 * Cloud Storage Admin Page
 *
 * Health status block at top. Then the store in one of three shapes: the setup
 * form when nothing is configured; what is stored, read-only, with Pause or
 * Enable, Disable and Pull Files Back to Local, and Remove as the state
 * allows; and a form for what may change — only the key, the public URL and
 * the private bucket while files are in the bucket, everything otherwise.
 * Save runs the bucket and key check and persists only when it passes.
 * Carries the private store's privacy-gate results and its own pull-back.
 *
 * @version 1.6 - the store's three shapes; locked fields shown, not edited; Enable and Remove; the
 *                Status box is one state sentence plus lines only for what needs attention
 * @version 1.5 - the Status box says what waits on this server for a backup before its local copy is
 *                released, and that the file store and backup storage share an account when they do
 * @version 1.4 - the file-store check in the Status box: when it last looked, "N offloaded files are
 *                missing from the file store; the backup holds M of them", and Bring them back
 * @version 1.3
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/SettingsFieldRenderer.php'));
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
// STATUS
// =====================================================
// One sentence for the state, with every healthy figure folded into it, under
// the traffic light. A coloured box only for a problem or a warning. Nothing
// for an absence: a driver that answers, a task whose last run succeeded, a private
// store nobody configured, say nothing here.
echo '<p style="max-width: 800px; margin-bottom: 16px;">If your Joinery is running out of disk space, you can add a storage bucket below and Joinery will intelligently offload files to the bucket. '
	. 'Those files will be accessible just like locally, but you\'ll pay for storage and transfer according to your bucket provider\'s policies.</p>';

$page->begin_box(array('title' => 'Status'));

$dot = function($color) {
	return '<span style="display:inline-block; width:10px; height:10px; border-radius:50%; background:' . $color . '; margin-right:6px; vertical-align:middle;"></span>';
};
$when  = function ($utc) use ($session) { return htmlspecialchars(LibraryFunctions::convert_time($utc, 'UTC', $session->get_timezone())); };
$files = function ($count, $bytes = null) {
	return number_format((int)$count) . ' file' . ((int)$count === 1 ? '' : 's')
		. ($bytes !== null && (int)$count > 0 ? ' (' . BackupRunner::human((int)$bytes) . ')' : '');
};
// The state wears the traffic light; a problem or a warning is a coloured box;
// a plain fact is plain text.
$state   = function ($color, $html) use ($dot) { echo '<div style="margin-bottom: 8px;">' . $dot($color) . $html . '</div>'; };
$problem = function ($html) { echo '<div class="alert alert-danger" style="margin-bottom: 8px;">' . $html . '</div>'; };
$warn    = function ($html) { echo '<div class="alert alert-warning" style="margin-bottom: 8px;">' . $html . '</div>'; };
$info    = function ($html) { echo '<div style="margin-bottom: 8px;">' . $html . '</div>'; };

$c = $health['counts'];
$last_run = !empty($health['sync_task']['last_run']) ? '; last run ' . $when($health['sync_task']['last_run']) : '';

// The state.
if (!$configured) {
	$state('#999', '<strong>Not set up.</strong> ' . $files($c['pending'], $c['pending_bytes']) . ' on this server would move to a bucket once one is set up.');
} elseif ($draining) {
	$last = !empty($health['reverse_task']['last_run']) ? '; last run ' . $when($health['reverse_task']['last_run']) : '';
	$state('#0d6efd', '<strong>Pulling files back.</strong> ' . $files($c['cloud'], $c['cloud_bytes']) . ' still in the bucket' . $last . '.'
		. (!empty($health['reverse_task']['last_message']) ? '<br><small class="text-muted">' . htmlspecialchars($health['reverse_task']['last_message']) . '</small>' : ''));
} elseif ($enabled) {
	$state('#28a745', '<strong>Active.</strong> ' . $files($c['cloud'], $c['cloud_bytes']) . ' in the bucket, ' . $files($c['pending'], $c['pending_bytes']) . ' waiting to move, '
		. number_format((int)$c['migrated_this_week']) . ' moved this week' . $last_run . '.');
} else {
	$state('#999', '<strong>Off.</strong> ' . ((int)$c['cloud'] > 0
		? $files($c['cloud'], $c['cloud_bytes']) . ' in the bucket keep serving from it; ' . $files($c['pending'], $c['pending_bytes']) . ' on this server would move once enabled.'
		: $files($c['pending'], $c['pending_bytes']) . ' on this server would move to the bucket once enabled.'));
}

// What needs attention.
if (!$health['cron']['ok']) {
	$problem('<strong>Cron is not running;</strong> nothing moves until it does. Last tick: '
		. ($health['cron']['last'] ? $when($health['cron']['last']) : '<em>never</em>') . '.');
}
if ($enabled && !empty($health['driver']) && !$health['driver']['ok']) {
	$problem('<strong>The bucket did not answer:</strong> ' . htmlspecialchars((string)($health['driver']['message'] ?? 'unknown')) . ' Save to run the full check.');
}
if (!empty($health['sync_task']) && $health['sync_task']['is_active'] && $health['sync_task']['last_status'] === 'error') {
	$problem('<strong>The last run failed:</strong> ' . htmlspecialchars((string)$health['sync_task']['last_message']));
}
if ((int)$c['stuck'] > 0) {
	$problem('<strong>' . $files($c['stuck']) . ' failed to move 5 or more times.</strong> Retry below.');
}

// What is worth knowing.
if (CloudStoreInventoryPanel::has_content($inventory)) {
	$panel = CloudStoreInventoryPanel::render($inventory, $objects_source, '/admin/admin_cloud_storage', $manager_url);
	if ((int)$inventory['missing_count'] > 0) { $problem($panel); } else { $info($panel); }
}
$waiting_line = BackupObjectsStatus::waiting_sentence($objects_status);
if ($waiting_line !== '') {
	$info(htmlspecialchars($waiting_line) . ' <a href="/admin/admin_backups">Backups</a>');
}
$same_account = $configured ? BackupObjectsStatus::same_account_line($objects_status) : '';
if ($same_account !== '') {
	$warn(htmlspecialchars($same_account));
}
$private_cloud = (int)($private_status['cloud_count'] ?? 0);
if (!empty($private_status['enabled'])) {
	$info('<strong>Private bucket:</strong> ' . $files($private_cloud) . '.');
} elseif (!empty($private_status['configured'])) {
	$warn('<strong>Private bucket</strong> is set but not yet proven private. Save runs the check.');
}
if (!empty($private_status['enabled']) || $private_cloud > 0) {
	echo '<div style="margin-bottom: 12px;">';
	echo AdminPage::action_button('Disable Private Store and Pull Back', '/admin/admin_cloud_storage', array(
		'hidden'  => array('action' => 'disable_and_pull_private'),
		'confirm' => 'Disable the private store and pull all ' . $private_cloud . ' object(s) (offloaded inbound-mail raw) back to this server? '
			. 'Ensure enough free space before continuing. The bucket stays named until you clear it and Save once the count reaches zero.',
		'class'   => 'btn btn-danger btn-sm',
	));
	echo '</div>';
}

// Stuck files, with their Retry.
if (!empty($health['stuck_rows'])) {
	echo '<div style="margin-top: 8px;">';
	echo '<table class="table table-sm" style="margin-top: 6px;"><thead><tr>';
	echo '<th>File</th><th>Last attempt</th><th>Failures</th><th></th>';
	echo '</tr></thead><tbody>';
	foreach ($health['stuck_rows'] as $row) {
		echo '<tr>';
		echo '<td>' . htmlspecialchars($row['fbb_stored_name']) . ' <small class="text-muted">(#' . (int)$row['fbb_file_blob_id'] . ')</small></td>';
		echo '<td>' . ($row['fbb_sync_last_attempt'] ? $when($row['fbb_sync_last_attempt']) : '—') . '</td>';
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
// THE STORE
// =====================================================
// Three shapes. Nothing configured: the setup form. Configured: what is
// stored, read-only, with the actions that fit its state. While files are in
// the bucket (or on their way back) the endpoint, region and bucket are
// locked — the records point at objects there — and only the key, the
// public URL and the private bucket may change. With nothing in the bucket
// the whole configuration may change or be removed.
$fields_in_order = array(
	'cloud_storage_endpoint', 'cloud_storage_region', 'cloud_storage_bucket',
	'cloud_storage_access_key', 'cloud_storage_secret_key',
	'cloud_storage_public_base_url', 'cloud_storage_private_bucket',
);
$field_values = array(
	'cloud_storage_endpoint'        => $settings_values['endpoint'],
	'cloud_storage_region'          => $settings_values['region'],
	'cloud_storage_bucket'          => $settings_values['bucket'],
	'cloud_storage_access_key'      => $settings_values['access_key'],
	'cloud_storage_secret_key'      => $settings_values['secret_key'],
	'cloud_storage_public_base_url' => $settings_values['public_base_url'],
	'cloud_storage_private_bucket'  => $settings_values['private_bucket'],
);
// The fields come from the cloud_storage declarations, so this page and the
// core settings tab show the same thing; drawn one at a time so the form reads
// in the order a person fills it in, not the declaration order. No Clear box
// on the secret key: this page writes its own settings after a live bucket
// test, and a bucket with no key is not a state worth offering.
$draw_fields = function ($formwriter, array $names) use ($field_values) {
	foreach ($names as $name) {
		SettingsFieldRenderer::renderGroup($formwriter, 'cloud_storage', array(
			'source'        => 'core',
			'only'          => array($name),
			'field_options' => array('cloud_storage_secret_key' => array('clearable' => false)),
			'values'        => $field_values,
		));
	}
};
$egress_banner = '<div id="egress_warning" style="display:none;" class="alert alert-warning">'
	. '<strong>Egress warning:</strong> This looks like a raw <span id="egress_provider">bucket</span> URL. Without a CDN you\'ll pay egress on every file view, which can exceed storage savings. '
	. 'Cheaper patterns: B2 + Cloudflare (free egress via Bandwidth Alliance), Cloudflare R2, or Bunny.net in front of a bucket. See <code>docs/cloud_storage.md</code>.'
	. '</div>';
$save_failed = !empty($errors) || !empty($private_errors) || (!empty($test_results) && !$test_results['ok']);

if (!$configured) {
	$page->begin_box(array('title' => 'Set up cloud storage'));
	echo '<p style="color:#666;">Public files (photos, gallery and blog images) move to the bucket. Files people must be signed in to see stay on this server unless a private bucket is named too.</p>';
	$formwriter = $page->getFormWriter('cloud_storage_form', ['action' => '/admin/admin_cloud_storage', 'method' => 'post', 'id' => 'cloud_storage_form']);
	$formwriter->begin_form();
	$formwriter->hiddeninput('action', '', array('value' => 'save'));
	$draw_fields($formwriter, $fields_in_order);
	echo $egress_banner;
	echo '<div style="margin-top: 18px;">';
	$formwriter->submitbutton('btn_save', 'Save', array('class' => 'btn btn-primary'));
	echo '</div>';
	echo $formwriter->end_form();
	$page->end_box();
} else {
	// The state is said once, in the Status box above; this box is what is stored.
	$page->begin_box(array('title' => 'Cloud storage'));

	$stored = Globalvars::get_instance();
	$show = function ($label, $value, $muted = '') {
		echo '<tr><th style="width: 180px; font-weight: 600;">' . htmlspecialchars($label) . '</th><td>'
			. ($value !== '' ? htmlspecialchars($value) : '<span class="text-muted">' . htmlspecialchars($muted) . '</span>') . '</td></tr>';
	};
	echo '<table class="table table-sm" style="max-width: 800px;"><tbody>';
	$show('Endpoint', (string)$stored->get_setting('cloud_storage_endpoint'));
	$show('Region', (string)$stored->get_setting('cloud_storage_region'), 'none');
	$show('Bucket', (string)$stored->get_setting('cloud_storage_bucket'));
	$show('Access key', (string)$stored->get_setting('cloud_storage_access_key'));
	$show('Secret key', '', $stored->get_setting('cloud_storage_secret_key') !== '' ? 'stored' : 'none');
	$show('Public base URL', (string)$stored->get_setting('cloud_storage_public_base_url'), 'the bucket\'s own address');
	$show('Private bucket', (string)$stored->get_setting('cloud_storage_private_bucket'), 'none');
	$show('Files in the bucket', $files($public_cloud, $health['counts']['cloud_bytes']) . ((int)($private_status['cloud_count'] ?? 0) > 0 ? ' public, ' . $files($private_status['cloud_count']) . ' private' : ''));
	echo '</tbody></table>';

	// The actions that fit the state.
	echo '<div style="margin-top: 6px; display: flex; gap: 8px; flex-wrap: wrap;">';
	if ($enabled) {
		echo AdminPage::action_button('Pause', '/admin/admin_cloud_storage', array(
			'hidden'  => array('action' => 'pause'),
			'confirm' => 'Pause cloud storage? Files already in the bucket keep serving from it; new uploads stay on this server. Enable again at any time.',
			'class'   => 'btn btn-warning',
		));
	} else {
		echo AdminPage::action_button('Enable', '/admin/admin_cloud_storage', array(
			'hidden'  => array('action' => 'enable'),
			'class'   => 'btn btn-primary',
		));
	}
	if (($enabled || (int)$public_cloud > 0) && !$draining) {
		$disk_free = function_exists('disk_free_space') ? @disk_free_space('/') : null;
		$free_label = $disk_free !== null ? round($disk_free / 1024 / 1024 / 1024, 1) . ' GB free' : 'unknown free space';
		echo AdminPage::action_button('Disable and Pull Files Back to Local', '/admin/admin_cloud_storage', array(
			'hidden'  => array('action' => 'disable_and_pull'),
			'confirm' => 'Disable cloud storage and pull all ' . (int)$public_cloud . ' bucket-stored files back to this server? Local disk: ' . $free_label . '. Ensure several GB of free space before continuing.',
			'class'   => 'btn btn-danger',
		));
	}
	if (!$locked && !$enabled) {
		echo AdminPage::action_button('Remove', '/admin/admin_cloud_storage', array(
			'hidden'  => array('action' => 'remove'),
			'confirm' => 'Forget this bucket and key? Nothing is in the bucket, so no file is affected. Uploads stay on this server.',
			'class'   => 'btn btn-outline-danger',
		));
	}
	echo '</div>';

	if ($locked) {
		// Only what may change while files are in the bucket.
		echo '<p class="text-muted small" style="margin-top: 14px; margin-bottom: 6px;">The endpoint, region and bucket cannot change while files are in the bucket: their records point at objects there. '
			. 'To move to another bucket, disable and pull the files back first. The key and the public URL may change at any time; Save proves the new key before it is stored.</p>';
		$formwriter = $page->getFormWriter('cloud_storage_form', ['action' => '/admin/admin_cloud_storage', 'method' => 'post', 'id' => 'cloud_storage_form']);
		$formwriter->begin_form();
		$formwriter->hiddeninput('action', '', array('value' => 'save'));
		$draw_fields($formwriter, array('cloud_storage_access_key', 'cloud_storage_secret_key', 'cloud_storage_public_base_url', 'cloud_storage_private_bucket'));
		echo $egress_banner;
		echo '<div style="margin-top: 12px;">';
		$formwriter->submitbutton('btn_save', 'Save', array('class' => 'btn btn-primary'));
		echo '</div>';
		echo $formwriter->end_form();
	} else {
		// Nothing is in the bucket, so everything may change. Folded away
		// until asked for; open when a save just failed so the fix is in view.
		echo '<details style="margin-top: 14px;"' . ($save_failed ? ' open' : '') . '>';
		echo '<summary style="cursor: pointer; font-weight: 600;">Change settings</summary>';
		echo '<p class="text-muted small" style="margin-top: 8px;">Nothing is in the bucket, so any of these may change. Save proves the bucket and the key before anything is stored.</p>';
		$formwriter = $page->getFormWriter('cloud_storage_form', ['action' => '/admin/admin_cloud_storage', 'method' => 'post', 'id' => 'cloud_storage_form']);
		$formwriter->begin_form();
		$formwriter->hiddeninput('action', '', array('value' => 'save'));
		$draw_fields($formwriter, $fields_in_order);
		echo $egress_banner;
		echo '<div style="margin-top: 12px;">';
		$formwriter->submitbutton('btn_save', 'Save', array('class' => 'btn btn-primary'));
		echo '</div>';
		echo $formwriter->end_form();
		echo '</details>';
	}
	$page->end_box();
}

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
