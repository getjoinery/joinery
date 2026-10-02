<?php
/**
 * node_detail — Copy tab partial (specs/site_copy.md WP8).
 *
 * Copy this site onto a new server and keep it there, dormant and current:
 * the preflight, the two ways to get the new server (this management node
 * creates it, or the owner brings one and runs the command shown), the join,
 * each copy run's steps, the census comparison, the look link, Copy again and
 * Discard. SiteCopyRunner does the work, moved by the Advance Site Copies task;
 * this page only shows it, and reloads itself while a copy is moving.
 *
 * In scope: $node, $page, $session, $base_url, $node_name, $page_regex,
 * $skip_joinery, $tab.
 *
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
);
$copy_verdict_badge = array(
	'pending' => 'secondary', 'running' => 'primary', 'passed' => 'success', 'failed' => 'danger', 'skipped' => 'secondary',
);

// A dormant copy's own row points at its source's tab, where the copy is run.
if (trim((string)$node->get('mgn_install_state')) === 'copy') {
	$page->begin_box(['title' => 'Site copy']);
	$src_id = (int)$node->get('mgn_copy_of_node_id');
	echo '<p>This server is a dormant copy. It is made, refreshed and discarded from the Copy tab of the site it copies: ';
	echo '<a href="/admin/server_manager/node_detail?mgn_managed_node_id=' . $src_id . '&tab=copy">node #' . $src_id . '</a>.</p>';
	$page->end_box();
	return;
}

// Read-only: the Advance Site Copies task moves the copy along every tick.
$site_copy = SiteCopy::live_for_source((int)$node->key);

if (!$site_copy) {
	// ── No copy: the preflight, and the two ways to start ──
	$page->begin_box(['title' => 'Copy this site to a new server']);
	?>
	<p>A copy puts this whole site on another server: its database, its files, its custom themes and plugins,
	its sealed secrets (which open there), its certificate and its DKIM keys. It is made from the backups this
	management node takes of the site, so every copy is also a real restore of them.</p>
	<p>Nothing changes here. The copy stays <strong>dormant</strong>: it serves no visitors, sends nothing and runs
	no scheduled task, until a switch-over makes it the site. You can look at it privately, refresh it from newer
	backups as often as you like, or discard it.</p>
	<p class="text-muted">Each copy run asks this site's owner to approve the export on this site's own Backups
	page, with the backup recovery key, because the export hands the site's secrets to the new server.</p>
	<?php
	$refusals = SiteCopyRunner::source_refusals($node);
	$chain_note = '';
	if (!$refusals) {
		try {
			$chain = SiteCopyRunner::newest_chain($node);
			$chain_note = 'The copy will be made from backup ' . $chain['chain_id'] . ', last added to at '
				. $chain['time'] . ' (' . BackupRunner::human($chain['bytes']) . '). It needs about '
				. BackupRunner::human(SiteCopyRunner::disk_needed($chain)) . ' free on the new server.';
		} catch (Exception $e) {
			$refusals[] = $e->getMessage();
		}
	}
	if ($refusals) {
		echo '<div class="alert alert-warning"><strong>This site cannot be copied yet.</strong><ul class="mb-0">';
		foreach ($refusals as $why) {
			echo '<li>' . $copy_h($why) . '</li>';
		}
		echo '</ul></div>';
		$page->end_box();
		return;
	}
	echo '<p><strong>' . $copy_h($chain_note) . '</strong> The new server installs release '
		. $copy_h($node->get('mgn_joinery_version')) . ', this site\'s own, under the same site name and domain.</p>';
	$page->end_box();

	// Create it for me
	$page->begin_box(['title' => 'Create the new server for me']);
	$src_provision = class_exists('CustomerCloudProvision') ? CustomerCloudProvision::latest_for_node($node->key) : null;
	$settings_g = Globalvars::get_instance();
	$account_options = array();
	if (ProvisionCustomerCloud::operator_compute_token() !== '') {
		$account_options['operator'] = 'This management node\'s own cloud account (operator token)';
	}
	foreach (new MultiCustomerCloudAccount(['status' => 'active', 'deleted' => false]) as $ca) {
		$ca_user = new User($ca->get('cca_usr_user_id'), TRUE);
		$who = $ca_user->key ? trim($ca_user->get('usr_first_name') . ' ' . $ca_user->get('usr_last_name')) : ('user #' . $ca->get('cca_usr_user_id'));
		$account_options[(string)$ca->key] = ucfirst($ca->get('cca_provider')) . ' — ' . $who
			. (CustomerCloudAccount::grant_expired($ca) ? ' (grant expired — re-connect first)' : '');
	}
	if (!$account_options) {
		echo '<p class="text-muted">No cloud account is available: set an operator cloud token on the Provisioning Setup page, '
			. 'or connect an account. You can still bring a server of your own, below.</p>';
	} else {
		$fw = $page->getFormWriter('copy_new_server_form', [
			'values' => [
				'copy_region' => ($src_provision && $src_provision->get('cvp_region')) ? $src_provision->get('cvp_region')
					: ($settings_g->get_setting('server_manager_customer_cloud_region') ?: 'us-east'),
				'copy_type'   => ($src_provision && $src_provision->get('cvp_instance_type')) ? $src_provision->get('cvp_instance_type')
					: ($settings_g->get_setting('server_manager_customer_cloud_type') ?: 'g6-nanode-1'),
			],
			'action' => $base_url . '&tab=copy',
		]);
		$fw->begin_form();
		$fw->hiddeninput('action', ['value' => 'copy_new_server']);
		echo SmAdminCsrf::field();
		$fw->dropinput('copy_account', 'Cloud account', ['options' => $account_options, 'required' => true]);
		$fw->textinput('copy_region', 'Region', ['required' => true,
			'helptext' => 'The provider\'s region id, e.g. us-east. Any region works; only a later switch by address swap needs the source\'s.']);
		$fw->textinput('copy_type', 'Instance type', ['required' => true,
			'helptext' => 'Memory at least this site\'s server, and room for the disk figure above.']);
		$fw->submitbutton('btn_copy_new', 'Create the server and copy');
		$fw->end_form();
	}
	$page->end_box();

	// I'll bring a server
	$page->begin_box(['title' => 'I\'ll bring a server']);
	echo '<p>Any fresh Ubuntu server, at any provider. The next page shows the commands to run on it as root.</p>';
	echo '<form method="post" action="' . $copy_h($base_url . '&tab=copy') . '">';
	echo '<input type="hidden" name="action" value="copy_own_server">';
	echo SmAdminCsrf::field();
	echo '<button type="submit" class="btn btn-outline-primary">Copy to a server I bring</button></form>';
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
		echo '<p>This management node is creating the server: <strong>' . $copy_h($copy_provision->get('cvp_status')) . '</strong>';
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
		$label = $copy_step_labels[$s['op'] . '/' . $s['on']] ?? ($s['op'] . ' on the ' . $s['on']);
		echo '<tr><td>' . $copy_h($label) . '</td><td>';
		if (!empty($s['job_id'])) {
			echo '<a href="/admin/server_manager/job_detail?job_id=' . (int)$s['job_id'] . '">#' . (int)$s['job_id'] . '</a>';
		}
		echo '</td><td><span class="badge bg-' . ($copy_verdict_badge[$s['verdict']] ?? 'secondary') . '">' . $copy_h($s['verdict']) . '</span></td>';
		echo '<td>' . $copy_h($s['reason'] ?? '') . '</td></tr>';
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
		echo '<p class="text-success">The census matches exactly: every table\'s rows, every directory\'s files and bytes, and every sealed secret.</p>';
	} else {
		echo '<p>The census comparison (this site is live, so rows written since its backup differ, and are expected to):</p>';
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
		. '<code>' . $copy_h($copy_node->get('mgn_host') . ' ' . $domain) . '</code>, then open '
		. '<code>https://' . $copy_h($domain . $site_copy->get('scp_look_path')) . '</code>. Everyone else gets a 503 from the copy. '
		. 'Anything you change there is undone at the next copy run. A browser that already has a connection open to this site '
		. 'keeps using it: close the browser, or flush its sockets (chrome://net-internals/#sockets), after editing the hosts file.</div>';
}

// Actions
echo '<div class="d-flex gap-2 mt-3">';
if (in_array($copy_status, array(SiteCopy::STATUS_DORMANT, SiteCopy::STATUS_HALTED), true) && $copy_node) {
	echo '<form method="post" action="' . $copy_h($base_url . '&tab=copy') . '">';
	echo '<input type="hidden" name="action" value="copy_again">' . SmAdminCsrf::field();
	echo '<button type="submit" class="btn btn-primary">Copy again from the newest backup</button></form>';
}
echo '<form method="post" action="' . $copy_h($base_url . '&tab=copy') . '" id="copy_discard_form">';
echo '<input type="hidden" name="action" value="copy_discard">' . SmAdminCsrf::field();
echo '<button type="button" class="btn btn-outline-danger" onclick="JoineryModal.confirm(\'Discard this copy? Its record leaves the dashboard. '
	. 'The server itself is not deleted: delete it at its provider afterwards.\', function(){ document.getElementById(\'copy_discard_form\').submit(); })">Discard the copy</button></form>';
echo '</div>';
$page->end_box();

if (in_array($copy_status, array(SiteCopy::STATUS_WAITING, SiteCopy::STATUS_COPYING), true)) {
	// The run moves on the scheduled task's tick; the page follows it.
	echo '<script>setTimeout(function () { window.location.reload(); }, 15000);</script>';
}
