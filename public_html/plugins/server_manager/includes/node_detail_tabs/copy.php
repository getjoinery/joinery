<?php
/**
 * node_detail — Copy tab partial (specs/site_copy.md WP8).
 *
 * Copy this site onto a new server and keep it there, dormant and current:
 * the preflight, the two ways to get the new server (this management node
 * creates it, or the owner brings one and runs the command shown), the join,
 * each copy run's steps, the census comparison, the look link, Copy again and
 * Discard. A copy comes from the running site, or from its backups alone when
 * its server is dead (WP10). Then the switch-over: Switch over, Move the
 * address, the way back and Keep the switch-over, the address moving through
 * the proxy (WP7a, asking for the DNS token, which lives for that one request),
 * by an IP swap at Linode (WP12), or, from backups, by the owner's own DNS
 * change.
 * SiteCopyRunner does the work, moved by the Advance Site Copies task; this
 * page only shows it, and reloads itself while a copy is moving.
 *
 * In scope: $node, $page, $session, $base_url, $node_name, $page_regex,
 * $skip_joinery, $tab.
 *
 * @version 1.11 - the source's root SSH keys are listed by fingerprint with a tick to carry them to the copy (WP15)
 * @version 1.10 - a kept switch-over's old container is named until it is removed and its host holds no
 *                  certificate of it, with Remove it from the host here (B5); no longer for a week only
 * @version 1.9 - the key step is one link, the copy's key look path, on a copy whose release answers it; a container
 *                 source's old container is stopped by the switch-over, and an irreversible press asks in the system
 *                 modal instead of a checkbox
 * @version 1.8 - a kept switch-over from a container points at Permanently Delete Site on the old row instead of
 *                 naming a server to delete (B58, site_copy.md WP14); the old row names the site by its name
 * @version 1.7 - the key step is two links at the copy's own address, opening in a new window: no hosts-file line
 * @version 1.6 - the size is a choice of plans with the source's own size chosen
 * @version 1.5 - cloud accounts named as the provider names them; an expired connection is not offered (B47)
 * @version 1.4 - the start box says what each way of copying needs in one line each; a copy from backups is not
 *                 only for a dead server: its start asks nothing, and the switch-over asks that the old server is off
 * @version 1.3 - the IP swap, the owner's own DNS change, and a copy from backups (site_copy.md WP12, WP10)
 * @version 1.2 - the switch-over and the way back
 * @version 1.1 - the region falls back to us-east
 * @version 1.0
 */

$copy_h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

$copy_step_labels = array(
	'host_report/copy'   => 'Check the new server has room',
	'copy_export/source' => 'Export from this site (its owner approves it here, on the site\'s own Backups page)',
	'copy_import/copy'   => 'Hand the export to the copy',
	'copy_stage/copy'    => 'Download the backup onto the copy',
	'copy_restore/copy'  => 'Restore it on the copy',
	'site_census/source' => 'Count this site',
	'site_census/copy'   => 'Count the copy',
	'site_quiet:on/source'  => 'Freeze the site (the maintenance page; no scheduled tasks, no mail out)',
	'backup_run/source'     => 'The final backup of this site',
	'copy_vouch/source'     => 'This site vouches for that backup (a signature; no secret travels)',
	'copy_take_vouch/copy'  => 'The copy takes the vouch',
	'take_node_id/copy'     => 'The copy takes this site\'s node id',
	'site_quiet:off/source' => 'Let the site run (visitors, scheduled tasks, mail)',
	'go_back/source'        => 'Swap the node records back',
	'copy_look/copy'        => 'Ask the copy for its look link',
	'copy_take_key/copy'    => 'Open the backup with the recovery key, on the copy\'s own page',
	'power_off:source/source'  => 'Power this site\'s server off',
	'power_on:source/source'   => 'Power this site\'s server on',
	'ip_swap:over/source'      => 'Swap the IP addresses at Linode',
	'ip_swap:back/source'      => 'Swap the IP addresses back',
	'power_cycle:copy/copy'    => 'Restart the copy\'s server on the address it holds',
	'probe/copy'               => 'Ask for the copy\'s look link at the site\'s name',
	'provision_certificate/source' => 'Issue the site\'s certificate on the new server',
	'hold_container:stop/host'     => 'Stop the old container on its host, and keep it stopped',
);
$copy_verdict_badge = array(
	'pending' => 'secondary', 'running' => 'primary', 'passed' => 'success', 'failed' => 'danger', 'skipped' => 'secondary',
);

// A dormant copy's own row, or a switched-over site's old server, points at
// the tab where the copy is run.
if (in_array(trim((string)$node->get('mgn_install_state')), array('copy', 'retired'), true)) {
	$page->begin_box(['title' => 'Site copy']);
	$src_id = (int)$node->get('mgn_copy_of_node_id');
	echo '<p>' . (trim((string)$node->get('mgn_install_state')) === 'retired'
		? 'This is the old server of a site that switched over to a new one. The switch-over is kept or undone from the Copy tab of the site: '
		: 'This server is a dormant copy. It is made, refreshed and discarded from the Copy tab of the site it copies: ');
	$src_name = '';
	try { $src_name = (string)(new ManagedNode($src_id, TRUE))->get('mgn_name'); } catch (Exception $e) { }
	echo '<a href="/admin/server_manager/node_detail?mgn_managed_node_id=' . $src_id . '&tab=copy">'
		. $copy_h($src_name !== '' ? $src_name : 'the site') . '</a>.</p>';
	if (trim((string)$node->get('mgn_install_state')) === 'retired' && trim((string)$node->get('mgn_container_name')) !== '') {
		echo '<p>Once the switch-over is kept, remove this old container with <strong>Permanently Delete Site</strong> in '
			. 'the Actions menu. Its host checks first that the domain reaches the new server, and removes nothing if it does not.</p>';
	}
	$page->end_box();
	return;
}

// Read-only: the Advance Site Copies task moves the copy along every tick.
$site_copy = SiteCopy::live_for_source((int)$node->key);

if (!$site_copy) {
	// ── No copy: the preflight, and the two ways to start ──
	$ended_copy = SiteCopy::recently_ended_for_source((int)$node->key);
	$ended_server = $ended_copy ? SiteCopyRunner::server_to_delete($ended_copy) : '';
	// A kept switch-over's old container, until it is removed and its host
	// holds nothing of it: however long that takes.
	foreach (SiteCopyRunner::cleanup_left((int)$node->key) as $left) {
		$old = $left['node'];
		$old_url = '/admin/server_manager/node_detail?mgn_managed_node_id=' . (int)$old->key;
		$old_link = '<a href="' . $copy_h($old_url) . '">its old record</a>';
		if (!$left['removed']) {
			echo '<div class="alert alert-warning border">This site\'s old container is still on its host. Open ' . $old_link
				. ' and choose <strong>Permanently Delete Site</strong>. Its host checks first that the domain reaches this server.</div>';
			continue;
		}
		$old_host = '';
		try {
			$old_host = (string)JobCommandBuilder::decommission_host_node_for($old)->get('mgn_name');
		} catch (Exception $e) {
			// The name only decorates the sentence.
		}
		foreach ($left['certificates'] as $cert_name) {
			echo '<div class="alert alert-warning border">This site\'s old container is removed (' . $old_link . '), but its HTTPS '
				. 'certificate <code>' . $copy_h($cert_name) . '</code> is still on ' . $copy_h($old_host ?: 'its host')
				. ', which keeps trying to renew it.'
				. '<form method="post" action="' . $copy_h($old_url . '&tab=overview') . '" class="mt-2">'
				. '<input type="hidden" name="action" value="remove_site_certificate">'
				. '<input type="hidden" name="cert_name" value="' . $copy_h($cert_name) . '">' . SmAdminCsrf::field()
				. '<button type="submit" class="btn btn-sm btn-outline-danger">Remove it from the host</button></form></div>';
		}
	}
	if ($ended_server !== '') {
		echo '<div class="alert alert-light border">The last copy of this site ended ('
			. $copy_h(strtolower($ended_copy->status_label())) . ', ' . $copy_h($ended_copy->get_local('scp_update_time', 'M j, g:i A'))
			. '). If you have not yet, delete its server at the provider: <strong>' . $copy_h($ended_server) . '</strong>.</div>';
	}
	$page->begin_box(['title' => 'Copy this site to a new server']);
	// Two places a copy can come from: the running site, or its backups
	// alone. Each has its own preflight.
	$copy_from_notes = array();
	$copy_from_refusals = array();
	foreach (array(SiteCopy::FROM_SOURCE, SiteCopy::FROM_BACKUPS) as $from) {
		$why = SiteCopyRunner::source_refusals($node, true, $from);
		if (!$why) {
			try {
				$chain = SiteCopyRunner::newest_chain($node, SiteCopyRunner::chain_age_limit($from));
				$copy_from_notes[$from] = 'Uses the backup of ' . gmdate('M j, H:i', (int)strtotime($chain['time'])) . ' UTC ('
					. BackupRunner::human($chain['bytes']) . '); the new server needs about '
					. BackupRunner::human(SiteCopyRunner::disk_needed($chain)) . ' free.';
			} catch (Exception $e) {
				$why[] = $e->getMessage();
			}
		}
		$copy_from_refusals[$from] = $why;
	}
	$copy_from_options = array();
	if (!$copy_from_refusals[SiteCopy::FROM_SOURCE]) {
		$copy_from_options[SiteCopy::FROM_SOURCE] = 'From this running site';
	}
	if (!$copy_from_refusals[SiteCopy::FROM_BACKUPS]) {
		$copy_from_options[SiteCopy::FROM_BACKUPS] = 'From its backups';
	}
	if (!$copy_from_options) {
		echo '<div class="alert alert-warning"><strong>This site cannot be copied yet.</strong><ul class="mb-0">';
		foreach ($copy_from_refusals[SiteCopy::FROM_SOURCE] as $why) {
			echo '<li>' . $copy_h($why) . '</li>';
		}
		echo '</ul></div>';
		$page->end_box();
		return;
	}
	echo '<p>Puts this site on a new server at its release (' . $copy_h($node->get('mgn_joinery_version')) . '). Nothing '
		. 'changes here: the copy serves no visitors, sends nothing and runs no scheduled task until you switch over, and you '
		. 'can look at it privately first.</p>';
	echo '<ul>';
	if (isset($copy_from_options[SiteCopy::FROM_SOURCE])) {
		echo '<li><strong>From this running site:</strong> ' . $copy_h($copy_from_notes[SiteCopy::FROM_SOURCE])
			. ' You approve the export on this site\'s Backups page with its recovery key.</li>';
	} else {
		echo '<li class="text-muted"><strong>From this running site:</strong> not available. '
			. $copy_h(implode(' ', $copy_from_refusals[SiteCopy::FROM_SOURCE])) . '</li>';
	}
	if (isset($copy_from_options[SiteCopy::FROM_BACKUPS])) {
		echo '<li><strong>From its backups:</strong> ' . $copy_h($copy_from_notes[SiteCopy::FROM_BACKUPS])
			. ' You type the recovery key on the new server\'s own page. '
			. 'The new server gets its own certificate at the switch-over.</li>';
	}
	echo '</ul>';
	$page->end_box();

	// Where the copy comes from, on both start forms: a choice only when both
	// ways are open.
	$copy_from_fields = function ($fw) use ($copy_from_options) {
		if (count($copy_from_options) === 1) {
			$fw->hiddeninput('copy_from', ['value' => (string)array_key_first($copy_from_options)]);
			return;
		}
		$fw->dropinput('copy_from', 'Copy', ['options' => $copy_from_options]);
	};

	// Create it for me
	$page->begin_box(['title' => 'Create the new server for me']);
	$src_provision = class_exists('CustomerCloudProvision') ? CustomerCloudProvision::latest_for_node($node->key) : null;
	$settings_g = Globalvars::get_instance();
	$targets = CustomerCloudAccount::provision_targets();
	$account_options = $targets['options'];
	foreach ($targets['unusable'] as $why) {
		echo '<p class="small text-muted">Not offered: ' . $copy_h($why) . '</p>';
	}
	if (!$account_options) {
		echo '<p class="text-muted">No cloud account is available: set an operator cloud token on the Provisioning Setup page, '
			. 'or connect an account. You can still bring a server of your own, below.</p>';
	} else {
		$fw = $page->getFormWriter('copy_new_server_form', [
			'values' => [
				'copy_region' => ($src_provision && $src_provision->get('cvp_region')) ? $src_provision->get('cvp_region')
					: ($settings_g->get_setting('server_manager_customer_cloud_region') ?: 'us-east'),
			],
			'action' => $base_url . '&tab=copy',
		]);
		$fw->begin_form();
		$fw->hiddeninput('action', ['value' => 'copy_new_server']);
		echo SmAdminCsrf::field();
		$copy_from_fields($fw);
		$fw->dropinput('copy_account', 'Cloud account', ['options' => $account_options, 'required' => true]);
		$fw->textinput('copy_region', 'Region', ['required' => true,
			'helptext' => 'The provider\'s region id, e.g. us-east.']);
		// The plans to choose from, with this site's own size chosen. A
		// container reports its whole shared server's memory, so a container
		// site is the one most often copied onto a smaller plan.
		$copy_plans = array();
		try {
			$copy_plans = (new LinodeComputeDriver(''))->instanceTypes();   // a public listing: no token
		} catch (Exception $e) {
			$copy_plans = array();
		}
		$copy_same = '';
		try {
			$copy_same = SiteCopyRunner::same_size_type($node, (string)array_key_last($copy_from_options),
				function () use ($copy_plans) { return $copy_plans; });
		} catch (Exception $e) {
			$copy_same = '';
		}
		if ($copy_plans) {
			$copy_plan_options = array();
			foreach ($copy_plans as $plan) {
				$copy_plan_options[$plan['id']] = $plan['id'] . ' — ' . round($plan['memory_mb'] / 1024) . ' GB memory, '
					. round($plan['disk_mb'] / 1024) . ' GB disk' . ($plan['id'] === $copy_same ? ' (this site\'s size)' : '');
			}
			$fw->dropinput('copy_type', 'Size', ['options' => $copy_plan_options, 'required' => true,
				'value' => $copy_same !== '' ? $copy_same : (string)array_key_first($copy_plan_options),
				'helptext' => trim("Chosen to match this site's server; pick another to resize. Disk needs room for the figure above."
					. ($node->get('mgn_container_name') ? ' A container site\'s size is its whole shared server\'s, so a smaller plan often fits.' : ''))]);
		} else {
			$fw->textinput('copy_type', 'Instance type', ['required' => true, 'value' => $copy_same,
				'helptext' => 'Linode\'s plan list did not answer. A plan id such as g6-standard-2.']);
		}
		// The source's root SSH keys: shown by fingerprint, carried only when ticked.
		$copy_keys = SiteCopyRunner::source_root_keys($node);
		$copy_carry = array();
		if ($copy_keys['known'] && $copy_keys['keys']) {
			echo '<p><strong>Root SSH keys on this site\'s server</strong></p><ul>';
			foreach ($copy_keys['keys'] as $k) {
				echo '<li><code>' . $copy_h($k['fingerprint']) . '</code>' . ($k['comment'] !== '' ? ' ' . $copy_h($k['comment']) : '')
					. ($k['carry'] ? '' : ' — has restrictions, not carried') . '</li>';
				if ($k['carry']) {
					$copy_carry[] = $k['fingerprint'];
				}
			}
			echo '</ul>';
			if ($copy_carry) {
				$fw->hiddeninput('copy_key_fingerprints', ['value' => implode(',', $copy_carry)]);
				$fw->checkboxinput('copy_carry_root_keys', 'Carry these SSH keys to the copy (not recommended)', [
					'helptext' => 'Without this the copy has no root SSH keys and root cannot log in; it is reached through its agent.']);
			}
		} elseif (!$copy_keys['known']) {
			echo '<p class="small text-muted">This site\'s server has not reported its root SSH keys, so none can be carried; the copy will have none.</p>';
		}
		$fw->submitbutton('btn_copy_new', 'Create the server and copy');
		$fw->end_form();
	}
	$page->end_box();

	// I'll bring a server
	$page->begin_box(['title' => 'I\'ll bring a server']);
	echo '<p>Any fresh Ubuntu server, at any provider. The next page shows the commands to run on it as root.</p>';
	$fw = $page->getFormWriter('copy_own_server_form', ['action' => $base_url . '&tab=copy']);
	$fw->begin_form();
	$fw->hiddeninput('action', ['value' => 'copy_own_server']);
	echo SmAdminCsrf::field();
	$copy_from_fields($fw);
	$fw->submitbutton('btn_copy_own', 'Copy to a server I bring', ['class' => 'btn btn-outline-primary']);
	$fw->end_form();
	$page->end_box();
	return;
}

// ── A copy exists ──
$copy_status = $site_copy->status();
$copy_node = SiteCopyRunner::copy_row($site_copy, false);
$copy_provision = SiteCopyRunner::provision($site_copy);

$page->begin_box(['title' => 'Copy of this site']);
$status_class = array('waiting' => 'info', 'copying' => 'primary', 'dormant' => 'success', 'halted' => 'warning');
echo '<p><span class="badge bg-' . ($status_class[$copy_status] ?? 'secondary') . '">' . $copy_h($site_copy->status_label()) . '</span> ';
echo 'Release ' . $copy_h($site_copy->get('scp_release'));
if ($copy_node) {
	echo ' · server <a href="/admin/server_manager/node_detail?mgn_managed_node_id=' . (int)$copy_node->key . '">'
		. $copy_h($copy_node->get('mgn_name')) . '</a> at ' . $copy_h($copy_node->get('mgn_host'));
	if ($copy_node->get('mgn_agent_public_key')) {
		$fpr = AgentJoinRequest::fingerprint((string)base64_decode((string)$copy_node->get('mgn_agent_public_key')));
		echo ' · agent key <code>' . $copy_h(AgentJoinRequest::display_fingerprint($fpr)) . '</code>';
	}
}
echo '</p>';
if ($site_copy->get('scp_last_copied_time')) {
	echo '<p>Current as of the backup taken at <strong>' . $copy_h($site_copy->get('scp_chain_time')) . '</strong> ('
		. $copy_h($site_copy->get('scp_chain_id')) . '), copied ' . $copy_h($site_copy->get_local('scp_last_copied_time', 'M j, g:i A')) . '.</p>';
}
if ($copy_status === SiteCopy::STATUS_HALTED && $site_copy->get('scp_halt_reason')) {
	echo '<div class="alert alert-warning"><strong>Stopped.</strong> ' . $copy_h($site_copy->get('scp_halt_reason')) . '</div>';
}

if ($copy_status === SiteCopy::STATUS_WAITING) {
	if ($copy_provision) {
		echo '<p>This management node is creating the server (' . $copy_h($copy_provision->get('cvp_instance_type')) . '): <strong>'
			. $copy_h($copy_provision->get('cvp_status')) . '</strong>';
		if ($copy_provision->get('cvp_instance_ip')) {
			echo ' at ' . $copy_h($copy_provision->get('cvp_instance_ip'));
		}
		if ($copy_provision->get('cvp_error')) {
			echo ' — ' . $copy_h($copy_provision->get('cvp_error'));
		}
		echo '. Its install takes a few minutes and ends quiet; its agent then asks to join.</p>';
	} else {
		echo '<p>On a fresh Ubuntu server, as root, run:</p>';
		echo '<pre class="bg-light p-2" style="white-space: pre-wrap; word-break: break-all;">'
			. $copy_h(SiteCopyRunner::owner_command($site_copy)) . '</pre>';
		echo '<p>It installs this site\'s release under the same site name and domain, ends quiet, and asks to join here. '
			. 'Then run <code>joinery-agent status</code> on that server and approve below only the request whose fingerprint is the same.</p>';
	}
	$joins = SiteCopyRunner::candidate_joins($site_copy);
	if ($joins) {
		echo '<table class="table table-sm"><thead><tr><th>Asks to join</th><th>From</th><th>Fingerprint</th><th></th></tr></thead><tbody>';
		foreach ($joins as $request) {
			echo '<tr><td>' . $copy_h($request->get('ajr_claimed_name')) . '</td>';
			echo '<td>' . $copy_h($request->get('ajr_source_ip')) . '</td>';
			echo '<td><code>' . $copy_h(AgentJoinRequest::display_fingerprint((string)$request->get('ajr_fingerprint'))) . '</code></td>';
			echo '<td><form method="post" action="' . $copy_h($base_url . '&tab=copy') . '">';
			echo '<input type="hidden" name="action" value="copy_approve_join">';
			echo '<input type="hidden" name="ajr_agent_join_request_id" value="' . (int)$request->key . '">';
			echo SmAdminCsrf::field();
			echo '<button type="submit" class="btn btn-sm btn-primary">Approve as this copy</button></form></td></tr>';
		}
		echo '</tbody></table>';
	} elseif ($copy_node && $copy_node->get('mgn_agent_public_key')) {
		echo '<p>The copy\'s agent has joined. The first copy run starts when it reports what it can do, within a minute or two.</p>';
	}
}

$copy_steps = $site_copy->steps();
if ($copy_steps) {
	echo '<table class="table table-sm"><thead><tr><th>Step</th><th>Job</th><th>State</th><th></th></tr></thead><tbody>';
	foreach ($copy_steps as $s) {
		$step_key = $s['op'] . (!empty($s['arg']) ? ':' . $s['arg'] : '') . '/' . $s['on'];
		$label = $copy_step_labels[$step_key] ?? ($s['op'] . ' on the ' . $s['on']);
		echo '<tr><td>' . $copy_h($label) . '</td><td>';
		if (!empty($s['job_id'])) {
			echo '<a href="/admin/server_manager/job_detail?job_id=' . (int)$s['job_id'] . '">#' . (int)$s['job_id'] . '</a>';
		}
		echo '</td><td><span class="badge bg-' . ($copy_verdict_badge[$s['verdict']] ?? 'secondary') . '">' . $copy_h($s['verdict']) . '</span></td>';
		echo '<td>' . $copy_h($s['reason'] ?? '') . '</td></tr>';
		if ($s['op'] === 'copy_take_key' && $s['verdict'] === 'running' && $copy_node) {
			// The key page needs no hosts-file line: it answers at the copy's
			// own address, the look link's cookie included (the certificate
			// warning is the copy's placeholder, accepted once).
			$copy_ip = (string)$copy_node->get('mgn_host');
			$copy_ip_host = filter_var($copy_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '[' . $copy_ip . ']' : $copy_ip;
			$look_url = 'https://' . $copy_ip_host . $site_copy->get('scp_look_path');
			$key_note = 'check the backup and the key\'s fingerprint it names, and paste the recovery key. It stays in your '
				. 'browser; this management node never sees it.';
			if (version_compare((string)$site_copy->get('scp_release'), JobCommandBuilder::COPY_KEY_LOOK_MIN_VERSION, '>=')) {
				// One link: the key look path sets the look cookie and lands on
				// the key page. The copy's home page has no accounts yet.
				echo '<tr><td colspan="4" class="table-info"><strong>Waiting for the backup\'s recovery key.</strong> '
					. '<a href="' . $copy_h($look_url . '/key') . '" target="_blank" rel="noopener">Open the copy\'s key page</a>, '
					. 'accept the certificate warning (the copy has no certificate of its own yet), then ' . $key_note . '</td></tr>';
			} else {
				echo '<tr><td colspan="4" class="table-info"><strong>Waiting for the backup\'s recovery key.</strong><ol class="mb-1">'
					. '<li><a href="' . $copy_h($look_url) . '" target="_blank" rel="noopener">Open the copy</a> and accept the certificate '
					. 'warning (the copy has no certificate of its own yet). Close that page: the copy has no accounts yet.</li>'
					. '<li><a href="' . $copy_h('https://' . $copy_ip_host . '/copy-key') . '" target="_blank" rel="noopener">Open its key '
					. 'page</a>, ' . $key_note . '</li></ol></td></tr>';
			}
		}
		if ($s['op'] === 'copy_export' && $s['verdict'] === 'running') {
			$approve_url = rtrim((string)$node->get('mgn_site_url'), '/') . '/admin/admin_backups';
			echo '<tr><td colspan="4" class="table-info">Waiting for this site\'s owner to approve the export at '
				. '<a href="' . $copy_h($approve_url) . '" target="_blank" rel="noopener">' . $copy_h($approve_url) . '</a>'
				. '. The statement there names the copy by its key fingerprint; it must match the one above.</td></tr>';
		}
	}
	echo '</tbody></table>';
}

$census = $site_copy->census();
if ($census) {
	if (!empty($census['match'])) {
		echo $site_copy->from_backups()
			? '<p class="text-success">The copy\'s census holds: its key canary opens, no sealed secret is dead, and the offloaded files it sampled answer.</p>'
			: '<p class="text-success">The census matches exactly: every table\'s rows, every directory\'s files and bytes, and every sealed secret.</p>';
	} else {
		echo $site_copy->from_backups()
			? '<p>The copy\'s census, judged alone (there is no running site to compare it with):</p>'
			: '<p>The census comparison (this site is live, so rows written since its backup differ, and are expected to):</p>';
		echo '<table class="table table-sm"><thead><tr><th>What</th><th>This site</th><th>The copy</th><th></th></tr></thead><tbody>';
		foreach ((array)($census['differences'] ?? array()) as $d) {
			echo '<tr><td>' . $copy_h($d['what']) . '</td><td>' . $copy_h(is_scalar($d['source']) ? $d['source'] : json_encode($d['source']))
				. '</td><td>' . $copy_h(is_scalar($d['copy']) ? $d['copy'] : json_encode($d['copy'])) . '</td><td>'
				. (!empty($d['blocking']) ? '<span class="badge bg-danger">blocks</span>' : '<span class="badge bg-secondary">expected</span>')
				. '</td></tr>';
		}
		echo '</tbody></table>';
	}
}

if ($site_copy->get('scp_look_path') && $copy_node && in_array($copy_status, array(SiteCopy::STATUS_DORMANT, SiteCopy::STATUS_HALTED), true)) {
	$domain = SiteCopyRunner::site_domain($node);
	echo '<div class="alert alert-light border"><strong>Look at the copy.</strong> Point your computer at it with one hosts-file line, '
		. '<code>' . $copy_h($copy_node->get('mgn_host') . ' ' . $domain) . '</code>, then '
		. '<a href="' . $copy_h('https://' . $domain . $site_copy->get('scp_look_path')) . '" target="_blank" rel="noopener">open the copy</a>. '
		. 'Everyone else gets a 503 from the copy. '
		. 'Anything you change there is undone at the next copy run. A browser that already has a connection open to this site '
		. 'keeps using it: close the browser, or flush its sockets (chrome://net-internals/#sockets), after editing the hosts file.</div>';
}

// Actions
$in_switch = SiteCopyRunner::in_switch_over($site_copy);
echo '<div class="d-flex gap-2 mt-3">';
if (in_array($copy_status, array(SiteCopy::STATUS_DORMANT, SiteCopy::STATUS_HALTED), true) && $copy_node && !$in_switch) {
	echo '<form method="post" action="' . $copy_h($base_url . '&tab=copy') . '">';
	echo '<input type="hidden" name="action" value="copy_again">' . SmAdminCsrf::field();
	echo '<button type="submit" class="btn btn-primary">Copy again from the newest backup</button></form>';
}
if (!$in_switch) {
	echo '<form method="post" action="' . $copy_h($base_url . '&tab=copy') . '" id="copy_discard_form">';
	echo '<input type="hidden" name="action" value="copy_discard">' . SmAdminCsrf::field();
	echo '<button type="button" class="btn btn-outline-danger" onclick="JoineryModal.confirm(\'Discard this copy? Its record leaves the dashboard. '
		. 'The server itself is not deleted: delete it at its provider afterwards.\', function(){ document.getElementById(\'copy_discard_form\').submit(); })">Discard the copy</button></form>';
}
echo '</div>';
$page->end_box();

// ── The switch-over (steps 7-10) ──
$copy_switch = $site_copy->switch_record();
$copy_method = (string)($copy_switch['method'] ?? '');
$copy_domain = SiteCopyRunner::site_domain($node);
$copy_dns_class = $copy_domain !== '' ? ProxiedOriginMove::driver_class_for($copy_domain) : null;
$copy_address_at_copy = ($copy_switch['address_at'] ?? '') === 'copy';
$copy_swapped = $copy_node && trim((string)$copy_node->get('mgn_install_state')) === 'retired';
$copy_backups = $site_copy->from_backups();
$copy_method_names = array(
	SiteCopyRunner::METHOD_PROXIED => 'through the proxy in front of the site (Cloudflare)',
	SiteCopyRunner::METHOD_IP_SWAP => 'by swapping the two servers\' IP addresses at Linode',
	SiteCopyRunner::METHOD_MANUAL  => 'by your own change to the site\'s DNS',
);

// One form per press. A DNS token, where one is asked, is the driver's own
// fields, typed for that press and never kept.
$copy_press_form = function ($action, $button, $btn_class, $confirm_label = '', $hidden = array(), $token = false)
		use ($page, $base_url, $copy_dns_class) {
	$fw = $page->getFormWriter('copy_' . $action . '_' . md5(json_encode($hidden)) . '_form', ['action' => $base_url . '&tab=copy']);
	$fw->begin_form();
	$fw->hiddeninput('action', ['value' => $action]);
	foreach ($hidden as $k => $v) {
		$fw->hiddeninput($k, ['value' => $v]);
	}
	echo SmAdminCsrf::field();
	if ($token) {
		$guide = $copy_dns_class ? $copy_dns_class::credentialGuide() : null;
		foreach ($copy_dns_class ? $copy_dns_class::credentialFields() : array() as $field => $spec) {
			$opts = ['autocomplete' => 'off', 'required' => true, 'help_modal' => $guide,
				'helptext' => 'Used for this press only, and not kept.'];
			$guide = null;
			if (!empty($spec['secret'])) {
				$fw->passwordinput('dns_cred_' . $field, $spec['label'] ?? $field, $opts);
			} else {
				$fw->textinput('dns_cred_' . $field, $spec['label'] ?? $field, $opts);
			}
		}
	}
	$btn_opts = ['class' => 'btn ' . $btn_class];
	if ($confirm_label !== '') {
		// A press that cannot be taken back says what it does in the system
		// modal, once; copy_confirm is set only by the modal's yes, and the
		// handler refuses a press without it.
		$fw->hiddeninput('copy_confirm', ['value' => '']);
		$json = function ($v) { return json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); };
		$btn_opts['onclick'] = 'event.preventDefault(); var f = this.closest(\'form\'); if (!f.reportValidity()) { return; } '
			. 'JoineryModal.confirm(' . $json($confirm_label) . ', function () { f.elements[\'copy_confirm\'].value = \'1\'; f.submit(); }, '
			. $json(['confirmLabel' => $button, 'confirmStyle' => 'warning']) . ');';
	}
	$fw->submitbutton('btn_' . $action, $button, $btn_opts);
	$fw->end_form();
};
$copy_plain_form = function ($action, $button, $btn_class) use ($base_url, $copy_h) {
	echo '<form method="post" action="' . $copy_h($base_url . '&tab=copy') . '" class="d-inline">';
	echo '<input type="hidden" name="action" value="' . $copy_h($action) . '">' . SmAdminCsrf::field();
	echo '<button type="submit" class="btn ' . $copy_h($btn_class) . '">' . $copy_h($button) . '</button></form>';
};
$copy_records_list = function () use ($copy_switch, $copy_h) {
	if (!empty($copy_switch['records'])) {
		echo '<ul class="small">';
		foreach ((array)$copy_switch['records'] as $r) {
			echo '<li><code>' . $copy_h($r['name']) . '</code> ' . $copy_h($r['type']) . ' (proxied): ' . $copy_h($r['from'])
				. ' &rarr; ' . $copy_h($r['to']) . '</li>';
		}
		echo '</ul>';
	}
	if (!empty($copy_switch['swap'])) {
		$sw = $copy_switch['swap'];
		echo '<ul class="small"><li>This site\'s server (Linode ' . $copy_h($sw['source']['instance_id']) . ') <code>'
			. $copy_h($sw['source']['ip']) . '</code> &harr; the copy\'s server (Linode ' . $copy_h($sw['copy']['instance_id'])
			. ') <code>' . $copy_h($sw['copy']['ip']) . '</code>, in ' . $copy_h($sw['region']) . '. Each address keeps its reverse DNS.</li>';
		$v6 = IpSwapMove::ipv6_note($sw);
		if ($v6 !== '') {
			echo '<li>' . $copy_h($v6) . '</li>';
		}
		echo '</ul>';
	}
};

if ($copy_status === SiteCopy::STATUS_DORMANT && !$in_switch) {
	$page->begin_box(['title' => 'Switch over to the copy']);
	if ($copy_backups) {
		// The old server is powered off by the switch-over when this management
		// node created it; a container is stopped on its shared server, and kept
		// stopped, once the copy has taken over. Otherwise the owner turns it off.
		$copy_powers_off = ($sp = IpSwapMove::provision_of($node)) && (string)$sp->get('cvp_provider') === 'linode'
			&& !$sp->is_transferred();
		$copy_holds = trim((string)$node->get('mgn_container_name')) !== '';
		echo '<p>A switch-over makes the copy the site, as of the backup above: nothing is frozen and there is no final copy. '
			. ($copy_powers_off ? 'This management node powers the old server off first. '
				: ($copy_holds ? 'Once the copy has taken over, this management node stops the old container on its host and '
					. 'keeps it stopped until you remove it. '
				: '<strong>Turn the old server off first</strong>: this management node did not create it and cannot. '))
			. 'Then the address moves, the copy takes over this node, starts and gets its own certificate; until then browsers '
			. 'warn, and a proxy set to strict refuses it. You can go back until the copy takes over, not after.</p>';
	} else {
		echo '<p>A switch-over makes the copy the site. This site is frozen (visitors see a short "back in a few minutes" page), '
			. 'one final backup is copied across and both are counted, which must match exactly. Then you move the address, the '
			. 'copy takes over this node, and starts. Until you keep the switch-over, you can go back to this server.</p>';
	}
	$why = SiteCopyRunner::switch_refusals($site_copy);
	if ($why) {
		echo '<div class="alert alert-warning"><strong>It cannot switch over yet.</strong><ul class="mb-0">';
		foreach ($why as $w) {
			echo '<li>' . $copy_h($w) . '</li>';
		}
		echo '</ul></div>';
	} else {
		$confirm = $copy_backups
			? ($copy_powers_off
				? 'Power the old server off and switch over. Anything written to it after the backup is lost.'
				: ($copy_holds
					? 'Switch over, then stop the old container. Anything written to it after the backup is lost.'
					: 'I have turned the old server off. Switch over: anything written to it after the backup is lost.'))
			: 'Freeze this site now. Visitors see the maintenance page until the switch-over finishes or I go back.';
		$any = false;
		foreach (SiteCopyRunner::switch_methods($site_copy) as $method => $method_why) {
			echo '<hr><h6>Move the address ' . $copy_h($copy_method_names[$method]) . '</h6>';
			if ($method_why) {
				echo '<p class="small text-muted">Not available: ' . $copy_h(implode(' ', $method_why)) . '</p>';
				continue;
			}
			$any = true;
			if ($method === SiteCopyRunner::METHOD_PROXIED) {
				echo '<p class="small">Visitors follow within seconds. Every record pointing at this server must be proxied; the records '
					. 'are checked first, and nothing changes if they do not qualify. If this server only lets the proxy\'s addresses in, '
					. 'give the new server the same rule before moving: this page cannot see firewalls. Enter a DNS token for <strong>'
					. $copy_h($copy_domain) . '</strong>; you enter it again to move the address.</p>';
				$copy_press_form('copy_switch', 'Check the records and start', 'btn-warning', $confirm,
					array('copy_method' => $method), true);
			} elseif ($method === SiteCopyRunner::METHOD_IP_SWAP) {
				echo '<p class="small">The two servers swap their public IPv4 addresses at Linode, so every record, allow-list and '
					. 'reverse DNS naming this site\'s address stays true, with no DNS wait. The old server is powered off for the swap '
					. 'and the copy restarts on its new address, a few minutes. Both servers are checked at Linode first (one account, one '
					. 'region, Network Helper on, this server\'s firewalls also on the copy), and nothing changes if they do not qualify. '
					. 'IPv6 addresses do not move.</p>';
				$copy_press_form('copy_switch', 'Check the servers and start', 'btn-warning', $confirm, array('copy_method' => $method));
			} else {
				echo '<p class="small">You point ' . $copy_h($copy_domain) . '\'s DNS at the copy yourself, at your DNS host. Visitors '
					. 'whose resolvers still hold the old address fail until it expires, so the record\'s TTL is the wait.</p>';
				$copy_press_form('copy_switch', 'Start, and I will change DNS', 'btn-warning', $confirm, array('copy_method' => $method));
			}
		}
		if (!$any) {
			echo '<p class="text-muted">No way to move this site\'s address is available.</p>';
		}
	}
	$page->end_box();
} elseif ($in_switch || in_array($copy_status, array(SiteCopy::STATUS_SWITCHED, SiteCopy::STATUS_RETURNING), true)) {
	$page->begin_box(['title' => 'Switch-over']);
	if (!$copy_backups && !empty($copy_switch['frozen_time']) && !in_array($copy_status, array(SiteCopy::STATUS_SWITCHED, SiteCopy::STATUS_RETURNING), true)) {
		echo '<p><strong>This site has been frozen since ' . $copy_h($copy_switch['frozen_time']) . ' UTC.</strong> Visitors see the '
			. 'maintenance page.</p>';
	}
	echo '<p>The address moves ' . $copy_h($copy_method_names[$copy_method] ?? $copy_method) . '. It is at <strong>'
		. ($copy_address_at_copy ? 'the copy' : 'this site\'s own server') . '</strong>';
	if (!empty($copy_switch['zone'])) {
		echo ', zone ' . $copy_h($copy_switch['zone']);
	}
	echo '.</p>';
	$copy_records_list();
	if (!empty($copy_switch['proof']['proven'])) {
		echo '<p class="text-success small">The site\'s name reached the copy after ' . (int)$copy_switch['proof']['seconds'] . ' s.</p>';
	}
	if (!empty($copy_switch['move_failure'])) {
		echo '<div class="alert alert-warning small">The last move did not finish (' . $copy_h($copy_switch['move_failure']) . ')'
			. ($copy_method === SiteCopyRunner::METHOD_IP_SWAP ? '; the addresses were put back.' : '.') . '</div>';
	}
	foreach ((array)($copy_switch['moved_back_notes'] ?? array()) as $note) {
		echo '<div class="alert alert-warning small">' . $copy_h($note) . '</div>';
	}

	$phase = (string)($copy_switch['phase'] ?? '');
	if ($copy_status === SiteCopy::STATUS_READY) {
		echo '<p>' . ($copy_backups ? 'The copy is ready.' : 'The final copy matches this site exactly.')
			. ' Move the address now' . ($copy_backups ? '.' : ': the site is down until you do, or until you go back.') . '</p>';
		if ($copy_method === SiteCopyRunner::METHOD_PROXIED) {
			$copy_press_form('copy_move', 'Move the address to the copy', 'btn-primary', '', array(), true);
		} elseif ($copy_method === SiteCopyRunner::METHOD_IP_SWAP) {
			$copy_press_form('copy_move', 'Swap the addresses now', 'btn-primary');
		} else {
			$to = (array)($copy_switch['to'] ?? array());
			echo '<p>At your DNS host, point ' . $copy_h($copy_domain) . ' (and any other name served by the old server) at the copy: <code>'
				. $copy_h(implode(', ', $to)) . '</code>. Then press the button; this management node asks the copy\'s look path at '
				. $copy_h($copy_domain) . ' until the copy answers.</p>';
			$copy_press_form('copy_move', 'The address points at the copy', 'btn-primary');
		}
	}
	if (in_array($copy_status, array(SiteCopy::STATUS_STARTING, SiteCopy::STATUS_UNDOING), true)) {
		echo '<p>This page follows the steps above as they happen.</p>';
	}
	if ($copy_status === SiteCopy::STATUS_SWITCHED) {
		echo '<p><strong>Switched over</strong> at ' . $copy_h($copy_switch['switched_time'] ?? '') . ' UTC. This node is the new '
			. 'server now' . ($copy_backups ? '.' : '; the old one is frozen, kept for the way back.') . '</p>';
		$copy_plain_form('copy_finish', 'Keep the switch-over', 'btn-success');
		$copy_old_row = SiteCopyRunner::copy_row($site_copy, false);
		$copy_old_is_container = $copy_old_row && trim((string)$copy_old_row->get('mgn_container_name')) !== '';
		echo '<p class="small text-muted mt-2">Keeping it ' . ($copy_backups ? '' : 'closes the way back and ') . 'removes the old server\'s '
			. 'record. ' . ($copy_old_is_container
				? 'The old container is then removed from its host with Permanently Delete Site on the old record, linked here.'
				: 'The old server itself is yours to delete at its provider.') . '</p>';
	}
	if ($copy_status === SiteCopy::STATUS_HALTED && $phase === 'start' && $copy_address_at_copy) {
		$copy_plain_form('copy_retry_start', 'Try starting the copy again', 'btn-primary');
	}
	$copy_way_back = $copy_status !== SiteCopy::STATUS_RETURNING && ($in_switch || $copy_status === SiteCopy::STATUS_SWITCHED)
		&& !in_array($copy_status, array(SiteCopy::STATUS_STARTING, SiteCopy::STATUS_UNDOING), true)
		&& !($copy_backups && $copy_swapped);
	if ($copy_way_back) {
		echo '<hr><h6>The way back</h6>';
		if ($copy_swapped) {
			echo '<p>This server becomes the site again. <strong>Anything written on the new server since it started is lost</strong>: '
				. 'its server is discarded afterwards. Keeping those writes would be a copy in the other direction.</p>';
		} elseif ($copy_backups) {
			echo '<p>The copy stays a dormant copy, and the old ' . (trim((string)$node->get('mgn_container_name')) !== ''
					? 'container keeps running: it is stopped only once the copy has taken over.' : 'server stays off.')
				. ($copy_method === SiteCopyRunner::METHOD_MANUAL && $copy_address_at_copy ? ' Point the DNS back yourself.' : '') . '</p>';
		} else {
			echo '<p>This site runs again on its own server, as before. The copy stays a dormant copy.</p>';
		}
		$confirm = $copy_swapped ? 'Go back to this server. What was written on the new server since it started is lost.' : '';
		if ($copy_method === SiteCopyRunner::METHOD_PROXIED && $copy_address_at_copy) {
			echo '<p>The address points at the copy, so going back moves it back: enter the DNS token.</p>';
			$copy_press_form('copy_go_back', 'Go back', 'btn-outline-danger', $confirm, array(), true);
		} elseif ($confirm !== '') {
			$copy_press_form('copy_go_back', 'Go back', 'btn-outline-danger', $confirm);
		} else {
			$copy_plain_form('copy_go_back', 'Go back', 'btn-outline-danger');
		}
	}
	$page->end_box();
}

if (in_array($copy_status, array_merge(array(SiteCopy::STATUS_WAITING), SiteCopy::MOVING_STATUSES), true)) {
	// The run moves on the scheduled task's tick, a node's posted result, or
	// the worker following local steps; the page follows it.
	echo '<script>setTimeout(function () { window.location.reload(); }, 15000);</script>';
}
