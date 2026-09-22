<?php
/**
 * Cloud Storage Admin Page
 *
 * Health status block at top. Then the store in one of three shapes: the setup
 * form when nothing is configured, headed by a provider picker that shows only
 * the fields the provider needs (StorageProvider); what is stored, read-only, with Pause or
 * Enable, Disable and Pull Files Back to Local, and Remove as the state
 * allows; and a form for what may change — Replace key while files are
 * in the bucket, everything otherwise. Either one runs the bucket and key check, the
 * privacy gate among its steps, and persists only when it passes.
 *
 * @version 2.2 - a stored secret key is a locked field with Reset
 * @changelog 2.1 - while files are in the bucket the key folds behind Replace key, which proves the key
 *                and stores it alone; it opens itself when the bucket stopped answering
 * @version 2.0.2 - records with no bytes on this server are listed apart from stuck files, without Retry;
 *                  the stuck table shows each file's last error
 * @version 2.0.1 - Pause and Disable and Pull Files Back are plain grey buttons
 * @version 2.0 - one private store (specs/cloud_storage_private_only.md): the intro says what moves;
 *                the forms draw provider, endpoint, region, bucket and the key; the private-store lines,
 *                the second pull-back, the egress banner and the pre-save confirm are gone
 * @version 1.7 - the provider picker heads the form; the endpoint and region fields show only for
 *                a provider that asks for them, and the script fills each field's example and help
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
// for an absence: a driver that answers, a task whose last run succeeded, say
// nothing here.
echo '<p style="max-width: 800px; margin-bottom: 16px;">If your Joinery is running out of disk space, you can add a storage bucket below and Joinery will intelligently offload files to the bucket. '
	. 'Those files will be accessible just like locally, but you\'ll pay for storage and transfer according to your bucket provider\'s policies. '
	. 'Only files people must be signed in to see move: private uploads, Drive files and inbound mail. Public images and downloads stay on this server, so nothing on a page is served from the bucket.</p>';

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
// The ping runs off the latch too, so a paused or draining store reports a key
// that stopped working — those stores still serve every offloaded file. A
// store that is off and holds nothing says nothing: there is no file to lose.
$driver_failed = !empty($health['driver']) && !$health['driver']['ok'];
if ($driver_failed && ($enabled || $locked)) {
	$problem('<strong>The bucket did not answer:</strong> ' . htmlspecialchars((string)($health['driver']['message'] ?? 'unknown'))
		. ' If the key was revoked or has expired, replace it below; the files in the bucket cannot be served or pulled back until one works.');
}
if (!empty($health['sync_task']) && $health['sync_task']['is_active'] && $health['sync_task']['last_status'] === 'error') {
	$problem('<strong>The last run failed:</strong> ' . htmlspecialchars((string)$health['sync_task']['last_message']));
}
if ((int)$c['stuck'] > 0) {
	$problem('<strong>' . $files($c['stuck']) . ' failed to move 5 or more times.</strong> Retry below.');
}
if ((int)$c['missing'] > 0) {
	// Nothing to retry: the bytes were gone before the bucket was set up.
	$names = array();
	foreach ($health['missing_rows'] as $row) {
		$names[] = htmlspecialchars((string)($row['fbb_stored_name'] ?? ('#' . (int)($row['id'] ?? 0))));
	}
	$problem('<strong>' . $files($c['missing']) . ' ' . ((int)$c['missing'] === 1 ? 'has' : 'have') . ' no bytes on this server,</strong> so there is nothing to move. '
		. 'Each was deleted or lost before the bucket was set up; permanently deleting the file releases its record.'
		. ($names ? '<br><small class="text-muted">' . implode(', ', $names) . ((int)$c['missing'] > count($names) ? ', …' : '') . '</small>' : ''));
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
// Stuck files, with their Retry.
if (!empty($health['stuck_rows'])) {
	echo '<div style="margin-top: 8px;">';
	echo '<table class="table table-sm" style="margin-top: 6px;"><thead><tr>';
	echo '<th>File</th><th>Last attempt</th><th>Failures</th><th>Last error</th><th></th>';
	echo '</tr></thead><tbody>';
	foreach ($health['stuck_rows'] as $row) {
		echo '<tr>';
		echo '<td>' . htmlspecialchars($row['fbb_stored_name']) . ' <small class="text-muted">(#' . (int)$row['fbb_file_blob_id'] . ')</small></td>';
		echo '<td>' . ($row['fbb_sync_last_attempt'] ? $when($row['fbb_sync_last_attempt']) : '—') . '</td>';
		echo '<td>' . (int)$row['fbb_sync_failed_count'] . '</td>';
		echo '<td><small>' . htmlspecialchars((string)($row['fbb_sync_last_error'] ?? '')) . '</small></td>';
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
// THE STORE
// =====================================================
// Three shapes. Nothing configured: the setup form. Configured: what is
// stored, read-only, with the actions that fit its state. While files are in
// the bucket (or on their way back) the endpoint, region and bucket are
// locked — the records point at objects there — and only the key may
// change. With nothing in the bucket the whole configuration may change or
// be removed.
$fields_in_order = array(
	'cloud_storage_provider', 'cloud_storage_endpoint', 'cloud_storage_region', 'cloud_storage_bucket',
	'cloud_storage_access_key', 'cloud_storage_secret_key',
);
$field_values = array(
	'cloud_storage_provider'   => $settings_values['provider'],
	'cloud_storage_endpoint'   => $settings_values['endpoint'],
	'cloud_storage_region'     => $settings_values['region'],
	'cloud_storage_bucket'     => $settings_values['bucket'],
	'cloud_storage_access_key' => $settings_values['access_key'],
	'cloud_storage_secret_key' => $settings_values['secret_key'],
);
// The fields come from the cloud_storage declarations, and this page is the
// only one that draws them: a plain settings save would store a bucket and
// key nobody proved. Drawn one at a time so the form reads
// in the order a person fills it in, and so the locked form can draw a subset.
// The provider's show_when rules ride on the picker whichever fields follow.
$draw_fields = function ($formwriter, array $names) use ($field_values) {
	foreach ($names as $name) {
		SettingsFieldRenderer::renderGroup($formwriter, 'cloud_storage', array(
			'source'        => 'core',
			'only'          => array($name),
			'values'        => $field_values,
		));
	}
};
$save_failed = !empty($errors) || (!empty($test_results) && !$test_results['ok']);

if (!$configured) {
	$page->begin_box(array('title' => 'Set up cloud storage'));
	echo '<p style="color:#666;">The bucket must be private: Save refuses one anyone can read.</p>';
	$formwriter = $page->getFormWriter('cloud_storage_form', ['action' => '/admin/admin_cloud_storage', 'method' => 'post', 'id' => 'cloud_storage_form']);
	$formwriter->begin_form();
	$formwriter->hiddeninput('action', '', array('value' => 'save'));
	$draw_fields($formwriter, $fields_in_order);
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
	$show('Provider', StorageProvider::label(StorageProvider::effective($stored->get_setting('cloud_storage_provider'), $stored->get_setting('cloud_storage_endpoint'))));
	$show('Endpoint', (string)$stored->get_setting('cloud_storage_endpoint'));
	$show('Region', (string)$stored->get_setting('cloud_storage_region'), 'none');
	$show('Bucket', (string)$stored->get_setting('cloud_storage_bucket'));
	$show('Access key', (string)$stored->get_setting('cloud_storage_access_key'));
	$show('Secret key', '', $stored->get_setting('cloud_storage_secret_key') !== '' ? 'stored' : 'none');
	$show('Files in the bucket', $files($cloud_count, $health['counts']['cloud_bytes']));
	echo '</tbody></table>';

	// The actions that fit the state.
	echo '<div style="margin-top: 6px; display: flex; gap: 8px; flex-wrap: wrap;">';
	if ($enabled) {
		echo AdminPage::action_button('Pause', '/admin/admin_cloud_storage', array(
			'hidden'  => array('action' => 'pause'),
			'confirm' => 'Pause cloud storage? Files already in the bucket keep serving from it; new uploads stay on this server. Enable again at any time.',
			'class'   => 'btn btn-secondary',
		));
	} else {
		echo AdminPage::action_button('Enable', '/admin/admin_cloud_storage', array(
			'hidden'  => array('action' => 'enable'),
			'class'   => 'btn btn-primary',
		));
	}
	if (($enabled || (int)$cloud_count > 0) && !$draining) {
		$disk_free = function_exists('disk_free_space') ? @disk_free_space('/') : null;
		$free_label = $disk_free !== null ? round($disk_free / 1024 / 1024 / 1024, 1) . ' GB free' : 'unknown free space';
		echo AdminPage::action_button('Disable and Pull Files Back to Local', '/admin/admin_cloud_storage', array(
			'hidden'  => array('action' => 'disable_and_pull'),
			'confirm' => 'Disable cloud storage and pull all ' . (int)$cloud_count . ' bucket-stored files back to this server? Local disk: ' . $free_label . '. Ensure several GB of free space before continuing.',
			'class'   => 'btn btn-secondary',
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
		// Only the key may change while files are in the bucket, and it is
		// folded away: a store doing its job is a set of facts and actions, not
		// two credential boxes. It opens on its own when the bucket stopped
		// answering — a revoked key is the one fault only this box can fix.
		echo '<p class="text-muted small" style="margin-top: 14px; margin-bottom: 6px;">The provider, endpoint, region and bucket cannot change while files are in the bucket: their records point at objects there. '
			. 'To move to another bucket, disable and pull the files back first.</p>';
		echo '<details' . ($save_failed || $driver_failed ? ' open' : '') . '>';
		echo '<summary style="cursor: pointer; font-weight: 600;">Replace key</summary>';
		echo '<p class="text-muted small" style="margin-top: 8px;">Paste the replacement key — after rotating it at your provider, or after revoking one that leaked. '
			. 'The new key is proved against this same bucket before it is stored, and storing it changes nothing else: '
			. 'a paused store stays paused, and a pull-back in progress carries on with the new key.</p>';
		$formwriter = $page->getFormWriter('cloud_storage_key_form', ['action' => '/admin/admin_cloud_storage', 'method' => 'post', 'id' => 'cloud_storage_key_form']);
		$formwriter->begin_form();
		$formwriter->hiddeninput('action', '', array('value' => 'replace_key'));
		$draw_fields($formwriter, array('cloud_storage_access_key', 'cloud_storage_secret_key'));
		echo '<div style="margin-top: 12px;">';
		$formwriter->submitbutton('btn_replace_key', 'Replace key', array('class' => 'btn btn-primary'));
		echo '</div>';
		echo $formwriter->end_form();
		echo '</details>';
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
		echo '<div style="margin-top: 12px;">';
		$formwriter->submitbutton('btn_save', 'Save', array('class' => 'btn btn-primary'));
		echo '</div>';
		echo $formwriter->end_form();
		echo '</details>';
	}
	$page->end_box();
}

// =====================================================
// CLIENT-SIDE: the provider's fields + region auto-fill
// =====================================================
?>
<script>
(function() {
	// What each provider asks for and names, from StorageProvider. The picker's
	// show/hide is FormWriter's; this fills each shown field's example and help.
	var providers = <?php echo json_encode(StorageProvider::catalogue()); ?>;
	var provider = document.getElementById('cloud_storage_provider');
	function currentProvider() {
		var p = provider ? provider.value : '';
		return providers[p] ? p : 'generic';
	}
	function helpOf(id) {
		var c = document.getElementById(id + '_container');
		return c ? c.querySelector('.form-help') : null;
	}
	function hostnameOf(s) {
		if (!s) return '';
		try { return new URL(s.indexOf('://') === -1 ? 'https://' + s : s).hostname; }
		catch (e) { return ''; }
	}

	var endpoint = document.getElementById('cloud_storage_endpoint');
	var region   = document.getElementById('cloud_storage_region');

	function applyProvider() {
		var spec = providers[currentProvider()];
		if (endpoint) {
			endpoint.placeholder = spec.example.endpoint || '';
			var eh = helpOf('cloud_storage_endpoint');
			if (eh && spec.endpoint_help) eh.textContent = spec.endpoint_help;
		}
		if (region) {
			region.placeholder = spec.example.region || '';
			var rh = helpOf('cloud_storage_region');
			if (rh && spec.region_help) rh.textContent = spec.region_help;
		}
	}
	if (provider) provider.addEventListener('change', applyProvider);
	applyProvider();

	// Region auto-fill on endpoint blur, for a generic endpoint that says.
	if (endpoint && region) {
		endpoint.addEventListener('blur', function() {
			if (region.value || currentProvider() !== 'generic') return;
			var host = hostnameOf(endpoint.value);
			// s3.<region>.backblazeb2.com  → us-west-002
			// s3.<region>.amazonaws.com    → us-east-1
			var m = host.match(/^s3[.-]([a-z0-9-]+)\.(amazonaws|backblazeb2|wasabisys|digitaloceanspaces)\.com$/);
			if (m && m[1] && m[1] !== 's3') region.value = m[1];
		});
	}
})();
</script>
<?php

$page->admin_footer();
