<?php
/**
 * node_detail — Copy tab partial (specs/site_copy.md WP8).
 *
 * Copy this site onto a new server and keep it there, dormant and current:
 * the preflight, the two ways to get the new server (this management node
 * creates it, or the owner brings one and runs the command shown), the join,
 * each copy run's steps, the census comparison, the look link, Copy again and
 * Discard. Then the switch-over (WP7a): Switch over, Move the address, the way
 * back and Keep the switch-over, the first two and the way back asking for the
 * DNS token of the site's domain, which lives for that one request.
 * SiteCopyRunner does the work, moved by the Advance Site Copies task; this
 * page only shows it, and reloads itself while a copy is moving.
 *
 * In scope: $node, $page, $session, $base_url, $node_name, $page_regex,
 * $skip_joinery, $tab.
 *
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
	echo '<a href="/admin/server_manager/node_detail?mgn_managed_node_id=' . $src_id . '&tab=copy">node #' . $src_id . '</a>.</p>';
	$page->end_box();
	return;
}

// Read-only: the Advance Site Copies task moves the copy along every tick.
$site_copy = SiteCopy::live_for_source((int)$node->key);

if (!$site_copy) {
	// ── No copy: the preflight, and the two ways to start ──
	$ended_copy = SiteCopy::recently_ended_for_source((int)$node->key);
	$ended_server = $ended_copy ? SiteCopyRunner::server_to_delete($ended_copy) : '';
	if ($ended_server !== '') {
		echo '<div class="alert alert-light border">The last copy of this site ended ('
			. $copy_h(strtolower($ended_copy->status_label())) . ', ' . $copy_h($ended_copy->get_local('scp_update_time', 'M j, g:i A'))
			. '). If you have not yet, delete its server at the provider: <strong>' . $copy_h($ended_server) . '</strong>.</div>';
	}
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
		$step_key = $s['op'] . (!empty($s['arg']) ? ':' . $s['arg'] : '') . '/' . $s['on'];
		$label = $copy_step_labels[$step_key] ?? ($s['op'] . ' on the ' . $s['on']);
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
$copy_domain = SiteCopyRunner::site_domain($node);
$copy_dns_class = $copy_domain !== '' ? ProxiedOriginMove::driver_class_for($copy_domain) : null;
$copy_address_at_copy = ($copy_switch['address_at'] ?? '') === 'copy';
$copy_swapped = $copy_node && trim((string)$copy_node->get('mgn_install_state')) === 'retired';

// One form per press that carries the DNS token: the driver's own fields,
// typed for that press and never kept.
$copy_token_form = function ($action, $button, $btn_class, $confirm_label = '') use ($page, $base_url, $copy_dns_class, $copy_h) {
	$fw = $page->getFormWriter('copy_' . $action . '_form', ['action' => $base_url . '&tab=copy']);
	$fw->begin_form();
	$fw->hiddeninput('action', ['value' => $action]);
	echo SmAdminCsrf::field();
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
	if ($confirm_label !== '') {
		$fw->checkboxinput('copy_confirm', $confirm_label, ['required' => true]);
	}
	$fw->submitbutton('btn_' . $action, $button, ['class' => 'btn ' . $btn_class]);
	$fw->end_form();
};
$copy_plain_form = function ($action, $button, $btn_class) use ($base_url, $copy_h) {
	echo '<form method="post" action="' . $copy_h($base_url . '&tab=copy') . '" class="d-inline">';
	echo '<input type="hidden" name="action" value="' . $copy_h($action) . '">' . SmAdminCsrf::field();
	echo '<button type="submit" class="btn ' . $copy_h($btn_class) . '">' . $copy_h($button) . '</button></form>';
};
$copy_records_list = function () use ($copy_switch, $copy_h) {
	if (empty($copy_switch['records'])) {
		return;
	}
	echo '<ul class="small">';
	foreach ((array)$copy_switch['records'] as $r) {
		echo '<li><code>' . $copy_h($r['name']) . '</code> ' . $copy_h($r['type']) . ' (proxied): ' . $copy_h($r['from'])
			. ' &rarr; ' . $copy_h($r['to']) . '</li>';
	}
	echo '</ul>';
};

if ($copy_status === SiteCopy::STATUS_DORMANT && !$in_switch) {
	$page->begin_box(['title' => 'Switch over to the copy']);
	echo '<p>A switch-over makes the copy the site. This site is frozen (visitors see a short "back in a few minutes" page), '
		. 'one final backup is copied across and both are counted, which must match exactly. Then you move the address, the '
		. 'copy takes over this node, and starts. Until you keep the switch-over, you can go back to this server.</p>';
	echo '<p class="text-muted">The address moves through the proxy in front of the site (Cloudflare\'s orange cloud): visitors '
		. 'follow within seconds. Every record pointing at this server must be proxied; the records are checked first, and '
		. 'nothing is frozen if they do not qualify. If this server only lets the proxy\'s addresses in, give the new server the '
		. 'same rule before moving: this page cannot see firewalls.</p>';
	$why = SiteCopyRunner::switch_refusals($site_copy);
	if (!$copy_dns_class) {
		$why[] = ($copy_domain === '' ? 'The site has no https address on record.' : $copy_domain . '\'s DNS is not at a host '
			. 'that proxies (Cloudflare).') . ' Switching by moving the IP address, or by changing DNS, is not built yet.';
	}
	if ($why) {
		echo '<div class="alert alert-warning"><strong>It cannot switch over yet.</strong><ul class="mb-0">';
		foreach ($why as $w) {
			echo '<li>' . $copy_h($w) . '</li>';
		}
		echo '</ul></div>';
	} else {
		echo '<p>Enter a DNS token for <strong>' . $copy_h($copy_domain) . '</strong>. You enter it again to move the address.</p>';
		$copy_token_form('copy_switch', 'Check the records and freeze the site', 'btn-warning',
			'Freeze this site now. Visitors see the maintenance page until the switch-over finishes or I go back.');
	}
	$page->end_box();
} elseif ($in_switch || in_array($copy_status, array(SiteCopy::STATUS_SWITCHED, SiteCopy::STATUS_RETURNING), true)) {
	$page->begin_box(['title' => 'Switch-over']);
	if (!empty($copy_switch['frozen_time']) && !in_array($copy_status, array(SiteCopy::STATUS_SWITCHED, SiteCopy::STATUS_RETURNING), true)) {
		echo '<p><strong>This site has been frozen since ' . $copy_h($copy_switch['frozen_time']) . ' UTC.</strong> Visitors see the '
			. 'maintenance page.</p>';
	}
	echo '<p>The address is at <strong>' . ($copy_address_at_copy ? 'the copy' : 'this site\'s own server') . '</strong>';
	if (!empty($copy_switch['zone'])) {
		echo ', zone ' . $copy_h($copy_switch['zone']);
	}
	echo '.</p>';
	$copy_records_list();
	if (!empty($copy_switch['proof']['proven'])) {
		echo '<p class="text-success small">The proxy reached the copy after ' . (int)$copy_switch['proof']['seconds'] . ' s.</p>';
	}
	foreach ((array)($copy_switch['moved_back_notes'] ?? array()) as $note) {
		echo '<div class="alert alert-warning small">' . $copy_h($note) . '</div>';
	}

	$phase = (string)($copy_switch['phase'] ?? '');
	if ($copy_status === SiteCopy::STATUS_READY) {
		echo '<p>The final copy matches this site exactly. Move the address now: the site is down until you do, or until you go back.</p>';
		$copy_token_form('copy_move', 'Move the address to the copy', 'btn-primary');
	}
	if ($copy_status === SiteCopy::STATUS_SWITCHED) {
		echo '<p><strong>Switched over</strong> at ' . $copy_h($copy_switch['switched_time'] ?? '') . ' UTC. This node is the new '
			. 'server now; the old one is frozen, kept for the way back.</p>';
		$copy_plain_form('copy_finish', 'Keep the switch-over', 'btn-success');
		echo '<p class="small text-muted mt-2">Keeping it closes the way back and removes the old server\'s record. The old '
			. 'server itself is yours to delete at its provider.</p>';
	}
	if ($copy_status === SiteCopy::STATUS_HALTED && $phase === 'start' && $copy_address_at_copy) {
		$copy_plain_form('copy_retry_start', 'Try starting the copy again', 'btn-primary');
	}
	if ($copy_status !== SiteCopy::STATUS_RETURNING && ($in_switch || $copy_status === SiteCopy::STATUS_SWITCHED)) {
		echo '<hr><h6>The way back</h6>';
		if ($copy_swapped) {
			echo '<p>This server becomes the site again. <strong>Anything written on the new server since it started is lost</strong>: '
				. 'its server is discarded afterwards. Keeping those writes would be a copy in the other direction.</p>';
		} else {
			echo '<p>This site runs again on its own server, as before. The copy stays a dormant copy.</p>';
		}
		if ($copy_address_at_copy) {
			echo '<p>The address points at the copy, so going back moves it back: enter the DNS token.</p>';
			$copy_token_form('copy_go_back', 'Go back', 'btn-outline-danger',
				$copy_swapped ? 'I understand that what was written on the new server since it started is lost.' : '');
		} else {
			$copy_plain_form('copy_go_back', 'Go back', 'btn-outline-danger');
		}
	}
	$page->end_box();
}

if (in_array($copy_status, array_merge(array(SiteCopy::STATUS_WAITING), SiteCopy::MOVING_STATUSES), true)) {
	// The run moves on the scheduled task's tick; the page follows it.
	echo '<script>setTimeout(function () { window.location.reload(); }, 15000);</script>';
}
