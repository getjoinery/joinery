<?php
/**
 * Server Manager Dashboard
 * URL: /admin/server_manager
 *
 * @version 1.49 - a join that matches a provision says why a person still has it (or that it is about to be auto-approved);
 *                 a key that differs from the install's is flagged; provisions show when their agents were auto-approved
 *                 (the auto_approve_provisioned_joins spec WP3)
 * @version 1.48 - a cloud provision's domain links to its site node, and a "host" link opens its host group on the board
 * @version 1.47 - Recent Jobs is a collapsible card like the join and provision lists; All Jobs is a link at its foot
 * @version 1.46 - a host's header carries a health dot: red if any node on it is red, amber if any is amber, green only when all
 *                 are green (kept current as the page refreshes node status); the status strip is flat, not a card
 * @version 1.45 - a host's own agent shows its state: waiting for approval, turned away, request expired, not linked, unpaired,
 *                 not checked in yet, offline, or not heard from; nothing when it is linked and checking in
 * @version 1.44 - machines (bare metal, one-site installs) are flat gray bars with name, domain and IP: no Machines group, nothing to expand
 * @version 1.43 - a value is shown once: a host named by its address does not repeat it, and a machine row shows its domain and IP
 *                 unless the name already is one of them
 * @version 1.42 - the sites-of-max badge appears only on hosts that take new sites, and shows the max the operator set
 *                 (no invented 50); other hosts show a plain count
 * @version 1.41 - the find box and expand/collapse icons live in the Hosts & Sites header, beside the Options dropdown
 * @version 1.40 - the notes at the top are the theme's standard .alert boxes (warning / danger / info), no custom shades
 * @version 1.39 - every box is the theme's card (header strip with an h6 title, body); the join and provision panels are
 *                 collapsible cards with a count badge, not separately coloured boxes
 * @version 1.38 - cloud provisions moved to the sidebar, one compact entry each (domain, status, Dismiss; detail only when there is something to act on)
 * @version 1.37 - a join from a provisioned machine's site agent can be approved from the panel (provider-checked, bound to the
 *                 provision's site node); every entry has Approve
 * @version 1.36 - agents asking to join moved to the sidebar above Recent Jobs, one compact entry each
 * @version 1.35 - the host Actions menu can queue an upgrade for every enabled site on that host
 * @version 1.34 - Recent Jobs are two-line rows, so the sidebar never overflows
 * @version 1.33 - Install Site / Edit Host sit in an Actions menu on each host's header bar
 * @version 1.32 - vanilla markup throughout (no Bootstrap): hosts are <details> groups, closed by default past six
 *                 nodes and remembered per browser, with find / expand-all / collapse-all; join requests are one
 *                 light-blue collapsible table with a one-line note per row; node rows are one line in a grid
 * @version 1.31 - the agent status bar names an update refused as unlogged (release_transparency WP5)
 * @version 1.30 - no banners for broken monitoring, backups from here not happening, or a node that can no
 *                 longer be managed: each is an incident, triaged in the one inbox
 * @version 1.29 -a node in any install state (copy, switching, retired among them) is badged with its
 *                 words and never polled for its status (ManagedNode::is_operational())
 * @version 1.28 - each node row shows its agent version, how far behind the agent this management node
 *                 ships, and a below-minimum badge under AgentVocabulary::FLOOR
 * @version 1.27 - the result sweep reads its terminal statuses from JobResultProcessor::TERMINAL_STATUSES
 * @version 1.26 - the readiness alert's warning clause no longer lists two kinds when a card can carry a third
 *                 (offloaded files sealed to a retired recovery key)
 * @version 1.25 - a host group is a Docker box: its own agent node in the header, its containers as the
 *                 sites; every other node is a machine, listed flat (a node placed on a deleted host too)
 * @version 1.24 - a rejected join can be reopened for a day (reopen_join): a mis-click is reversible, and the machine
 *                keeps asking with the same key until it is answered
 * @version 1.23 - a provision's host join (claim <slug>-host) says approving makes the host node at the instance's IPv4
 *                and links the placement; other joins from a provision's address are still sent to its node
 * @version 1.22 - a provision that brought nothing into existence can be dismissed off the board; one
 *                  that holds an instance, a node, a mail subaccount, a live install password or a
 *                  paid order says so instead of offering the button
 * @version 1.21 - an agent asking to join is announced at the top of the board and can be approved or
 *                  rejected right there: approval makes the node record from the request
 *                  (AgentChannelEndpoint::adoptJoin), and a join from this machine's own address is recognised as
 *                  this management node joining itself. Before this a join request was visible only
 *                  inside a node's API Keys tab, and a machine with no record had no page to approve from
 * @version 1.20 - nodes that can no longer verify their own scripts are named at the top of the
 *                  board; a node in that state cannot be repaired through the agent at all
 * @version 1.19 - a finished provision stays on the board while this plane still holds its install
 *                 password, and every row says where that password stands
 * @version 1.18 - a failing fleet backup links the failed job next to its reason
 * @version 1.17 - agent update state fetch_failed: the artifact could not be fetched and the agent is retrying
 * @version 1.16 - recovery-readiness attention line (never-verified/stale must-save secrets) linking to the readiness page
 * @version 1.16 - the backup alert is recovery-key setup only; per-node escrow rows are gone
 * @version 1.15 - escrow alert covers recovery-not-set-up as its own row and links to the guided
 *                 walkthrough; heading no longer assumes every row is a node
 * @version 1.14 - Show-all-sites toggle (?show_all=1) surfaces removed (soft-deleted) nodes with a Removed badge
 * @version 1.13 - management-node-level escrow problems (agent signing key) render without a node link
 * @version 1.12 - Sweep reconciles all JobResultProcessor-handled types (P-17), not a hardcoded 3
 * @version 1.11 - Shared server_manager.js asset (smApiPost/smEsc/smSafeUrl)
 *          1.10 - Agent self-update surfacing: pending/refused/rolled-back
 *                 alerts from the heartbeat row (specs/implemented/agent_release_channel.md)
 *          1.9 - Relay Fleet console link (mailbox plugin)
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_hosts_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_nodes_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_jobs_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/agent_heartbeats_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobResultProcessor.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/SmAssets.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/AgentChannelEndpoint.php'));

$session = SessionControl::get_instance();
$session->check_permission(10);
$session->set_return();

// Approve or reject a join request from the banner. Approval makes the node
// record from the request and binds the key to it — the same act as approval
// on a node's API Keys tab, without the hand-typed record first.
if ($_POST && in_array($_POST['action'] ?? '', ['adopt_join', 'adopt_provision_join', 'reject_join', 'reopen_join'], true)) {
	$page_regex = '/\/admin\/server_manager/';
	if (!SmAdminCsrf::valid()) { header('Location: /admin/server_manager'); exit; }
	$jr = new AgentJoinRequest((int)($_POST['ajr_agent_join_request_id'] ?? 0), TRUE);
	if (!$jr->key || $jr->get('ajr_delete_time')) {
		$session->save_message(new DisplayMessage('That join request no longer exists.', 'Error', $page_regex,
			DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		header('Location: /admin/server_manager'); exit;
	}
	if ($_POST['action'] === 'reopen_join') {
		if ($jr->get('ajr_status') !== AgentJoinRequest::STATUS_REJECTED) {
			$session->save_message(new DisplayMessage('That join request is not rejected.', 'Error', $page_regex,
				DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
			header('Location: /admin/server_manager'); exit;
		}
		$jr->reopen();
		$session->save_message(new DisplayMessage('Join request from ' . $jr->get('ajr_claimed_name') . ' reopened; the machine\'s next ask sees it pending again, and it can be approved here or on a node.',
			'Reopened', $page_regex, DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		header('Location: /admin/server_manager'); exit;
	}
	if ($_POST['action'] === 'reject_join') {
		$jr->set('ajr_status', AgentJoinRequest::STATUS_REJECTED);
		$jr->save();
		$session->save_message(new DisplayMessage('Join request from ' . $jr->get('ajr_claimed_name') . ' rejected.',
			'Rejected', $page_regex, DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		header('Location: /admin/server_manager'); exit;
	}
	if ($_POST['action'] === 'adopt_provision_join') {
		try {
			$adopted = AgentChannelEndpoint::approveProvisionSiteJoin($jr);
			$session->save_message(new DisplayMessage(
				'Agent connected. ' . $jr->get('ajr_claimed_name') . ' (key '
				. AgentJoinRequest::display_fingerprint((string)$jr->get('ajr_fingerprint')) . ') is now the agent of '
				. $adopted['node']->get('mgn_name') . '; it will pick the approval up on its next check.'
				. ($adopted['host'] ? ' It is this host\'s own agent, so host-scope work routes to it.' : ''),
				'Success', $page_regex, DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		} catch (Exception $e) {
			$session->save_message(new DisplayMessage('Join not approved. ' . $e->getMessage(), 'Error', $page_regex,
				DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		}
		header('Location: /admin/server_manager'); exit;
	}
	try {
		$adopted = AgentChannelEndpoint::adoptJoin($jr);
		$node_url = '/admin/server_manager/node_detail?mgn_managed_node_id=' . (int)$adopted['node']->key;
		$session->save_message(new DisplayMessage(
			'Agent connected. ' . $jr->get('ajr_claimed_name') . ' (key '
			. AgentJoinRequest::display_fingerprint((string)$jr->get('ajr_fingerprint')) . ') is now the agent of '
			. $adopted['node']->get('mgn_name') . ($adopted['self'] ? ', which is this management node itself' : '')
			. '; it will pick the approval up on its next check.'
			. ($adopted['host'] ? ' It is this host\'s own agent, so host-scope work routes to it.' : ''),
			'Success', $page_regex, DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		header('Location: ' . $node_url); exit;
	} catch (Exception $e) {
		$session->save_message(new DisplayMessage('Join not approved. ' . $e->getMessage(), 'Error', $page_regex,
			DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		header('Location: /admin/server_manager'); exit;
	}
}

// Queue an upgrade job for every enabled site on a host (the host's Actions menu).
// Same rules as the node page's "Upgrade All Sites on This Host": one independent
// job per site, the host's own agent node is not a site, and a live site at the
// host's address that is not grouped under it is a refusal, not a silent skip —
// "all sites" must never quietly mean "some".
if ($_POST && ($_POST['action'] ?? '') === 'upgrade_host_sites') {
	$page_regex = '/\/admin\/server_manager/';
	if (!SmAdminCsrf::valid()) { header('Location: /admin/server_manager'); exit; }
	$fail = function (string $msg) use ($session, $page_regex) {
		$session->save_message(new DisplayMessage($msg, 'Error', $page_regex,
			DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		header('Location: /admin/server_manager'); exit;
	};
	$up_host = new ManagedHost((int)($_POST['mgh_managed_host_id'] ?? 0), TRUE);
	if (!$up_host->key || $up_host->get('mgh_delete_time')) { $fail('That host no longer exists.'); }
	$ungrouped = [];
	foreach (new MultiManagedNode(['host' => (string)$up_host->get('mgh_host'), 'enabled' => true, 'deleted' => false], ['mgn_slug' => 'ASC']) as $other) {
		if ($other->hosts_site() && (int)$other->get('mgn_mgh_managed_host_id') !== (int)$up_host->key) {
			$ungrouped[] = $other->get('mgn_slug');
		}
	}
	if ($ungrouped) {
		$fail('Some sites at this address are not grouped under ' . $up_host->get('mgh_name') . ' (' . implode(', ', $ungrouped)
			. '). Assign them on their node pages first, so this action covers every site.');
	}
	$queued = 0;
	foreach (new MultiManagedNode(['host_id' => (int)$up_host->key, 'enabled' => true, 'deleted' => false], ['mgn_slug' => 'ASC']) as $site) {
		if (!$site->hosts_site()) { continue; } // the machine's own agent node: nothing to upgrade
		try {
			$built = JobCommandBuilder::build_apply_update($site);
			ManagementJob::createFromBuild($site->key, 'apply_update', $built, [], $session->get_user_id());
			$queued++;
		} catch (Exception $e) {
			error_log("upgrade_host_sites: failed to queue node {$site->key}: " . $e->getMessage());
		}
	}
	if ($queued === 0) { $fail('No upgrade jobs were queued for ' . $up_host->get('mgh_name') . '.'); }
	$session->save_message(new DisplayMessage(
		"Queued {$queued} upgrade " . ($queued === 1 ? 'job' : 'jobs') . ' for sites on ' . $up_host->get('mgh_name') . '.',
		'Success', $page_regex, DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
	header('Location: /admin/server_manager/jobs'); exit;
}

// Clear a dead provision off the board. A provision that never brought
// anything into existence is a note about an attempt, and an operator should
// not need hand-written SQL to be rid of it. The model decides whether this
// one qualifies and says why when it does not, so the refusal names the thing
// still running rather than reading as a rule.
if ($_POST && ($_POST['action'] ?? '') === 'dismiss_provision') {
	$page_regex = '/\/admin\/server_manager/';
	if (!SmAdminCsrf::valid()) { header('Location: /admin/server_manager'); exit; }
	$prov = new CustomerCloudProvision((int)($_POST['cvp_customer_cloud_provision_id'] ?? 0), TRUE);
	if (!$prov->key || $prov->get('cvp_delete_time')) {
		$session->save_message(new DisplayMessage('That provision is no longer on the board.', 'Error', $page_regex,
			DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		header('Location: /admin/server_manager'); exit;
	}
	$blockers = $prov->dismiss_blockers();
	if ($blockers) {
		$session->save_message(new DisplayMessage(
			$prov->get('cvp_domain') . ' cannot be dismissed: ' . implode('; ', $blockers) . '.',
			'Error', $page_regex, DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		header('Location: /admin/server_manager'); exit;
	}
	$prov->soft_delete();
	$session->save_message(new DisplayMessage(
		$prov->get('cvp_domain') . ' dismissed. It created nothing, so nothing is left running.',
		'Dismissed', $page_regex, DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
	header('Location: /admin/server_manager'); exit;
}

// Process completed jobs that haven't had their results parsed yet.
// Skip nodes that are soft-deleted to avoid spawning chained jobs against
// hosts that no longer exist.
$db = DbConnector::get_instance()->get_db_link();
// Reconcile every unprocessed terminal job whose type JobResultProcessor can
// handle — the Go agent completes jobs by writing the DB directly, so without
// this an unwatched job is never reconciled. The type list comes from the
// processor itself, so relay/SSL/backup results aren't silently skipped (P-17).
// The same rule JobResultProcessor::process_if_due applies to one job.
$processable  = JobResultProcessor::processable_types();
$placeholders = implode(',', array_fill(0, count($processable), '?'));
$terminal     = JobResultProcessor::TERMINAL_STATUSES;
$status_marks = implode(',', array_fill(0, count($terminal), '?'));
$q = $db->prepare(
	"SELECT j.mjb_management_job_id FROM mjb_management_jobs j " .
	"JOIN mgn_managed_nodes n ON n.mgn_managed_node_id = j.mjb_mgn_managed_node_id " .
	"WHERE j.mjb_status IN ($status_marks) " .
	"  AND j.mjb_job_type IN ($placeholders) " .
	"  AND j.mjb_result IS NULL " .
	"  AND j.mjb_delete_time IS NULL " .
	"  AND n.mgn_delete_time IS NULL"
);
$q->execute(array_merge($terminal, $processable));
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
	$unprocessed_job = new ManagementJob($row['mjb_management_job_id'], TRUE);
	JobResultProcessor::process($unprocessed_job);
}

// Load hosts (ordered by name)
$hosts = new MultiManagedHost(['deleted' => false], ['mgh_name' => 'ASC']);
$hosts->load();

// Load nodes and group by host_id. Removed (soft-deleted) sites are hidden by
// default; ?show_all=1 includes them, so a decommissioned site can be found
// again — its record, its history, and a link into its detail page.
$show_all = !empty($_GET['show_all']);
$node_opts = ['enabled' => true];
if (!$show_all) { $node_opts['deleted'] = false; }
$nodes = new MultiManagedNode($node_opts, ['mgn_name' => 'ASC']);
$nodes->load();

// A host record is a Docker box's placement record: which containers live on
// it, plus the box's own agent node (mgh_mgn_managed_node_id). So a host group
// lists the sites placed on it and carries its own node in the header, and
// every other node — a bare machine, a relay, a DNS box, this plane itself —
// is a machine in its own right and is listed flat. A node placed on a host
// that no longer exists is a machine too, not a site that vanishes with it.
$live_host_ids = [];
$host_node_ids = [];
foreach ($hosts as $host) {
	$live_host_ids[(int)$host->key] = true;
	$hn = $host->host_node();
	if ($hn) { $host_node_ids[(int)$hn->key] = (int)$host->key; }
}
$nodes_by_host = [];
$machines = [];
foreach ($nodes as $node) {
	$hid = (int)$node->get('mgn_mgh_managed_host_id');
	if (isset($host_node_ids[(int)$node->key])) {
		continue; // rendered in its host's header
	}
	if ($hid && isset($live_host_ids[$hid])) {
		$nodes_by_host[$hid][] = $node;
	} else {
		$machines[] = $node;
	}
}

// Load recent jobs
$recent_jobs = new MultiManagementJob(['deleted' => false], ['mjb_management_job_id' => 'DESC'], 20);
$recent_jobs->load();

// Agent heartbeat
$agent = AgentHeartbeat::getLatest();

// Cloud provisions still working toward a running site (or stuck), and finished
// ones whose install password this plane still holds (specs/keyless_provisioning.md)
require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_provisions_class.php'));
$inflight_provisions = new MultiCustomerCloudProvision([
	'open'    => true,
	'deleted' => false,
], ['cvp_customer_cloud_provision_id' => 'DESC']);
$inflight_provisions->load();

// Recovery not set up on this management node. A node's own trouble (broken
// monitoring, backups from here not happening, a node that can no longer be
// managed) is an incident, raised in the one inbox where it can be triaged.
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/NodeMonitorHealth.php'));
$recovery_problems = NodeMonitorHealth::backup_recovery_problems();

// Agents asking to join. A join is approved on the API Keys tab of the node
// it belongs to, and that node may not exist yet (a machine that joins before
// it is added here has no page at all). So the request is announced where the
// operator lands, with the nodes it could be approved on and the way to make
// one — otherwise the only trace is a command on the machine waiting for an
// answer nobody knows they owe.
$pending_joins = class_exists('AgentJoinRequest') ? AgentJoinRequest::pending() : [];
$rejected_joins = class_exists('AgentJoinRequest') ? AgentJoinRequest::recently_rejected() : [];
$agentless_nodes = [];
if ($pending_joins) {
	foreach (new MultiManagedNode(['enabled' => true, 'deleted' => false], ['mgn_name' => 'ASC']) as $candidate) {
		if (!$candidate->get('mgn_agent_public_key')) { $agentless_nodes[] = $candidate; }
	}
}

// Recovery readiness: must-save secrets never verified or verified too long
// ago. One line; the details live on the readiness page.
require_once(PathHelper::getIncludePath('includes/RecoveryReadiness.php'));
try {
	$readiness_attention = RecoveryReadiness::attention($session);
} catch (Throwable $e) {
	$readiness_attention = ['never' => 0, 'stale' => 0, 'warnings' => 0];
}

// Cron health: active if ran within 20 minutes
$settings        = Globalvars::get_instance();
$last_cron_run   = $settings->get_setting('scheduled_tasks_last_cron_run');
$cron_is_active  = $last_cron_run && (time() - strtotime($last_cron_run)) < 1200;

$page = new AdminPage();
$page->admin_header([
	'menu-id' => 'server-manager',
	'page_title' => 'Server Manager',
	'readable_title' => 'Server Manager',
	'breadcrumbs' => ['Server Manager' => ''],
	'session' => $session,
]);

$agent_online = $agent && $agent->is_online();
$agent_class  = $agent_online ? 'success' : 'danger';
$agent_label  = $agent_online ? 'Online'  : 'Offline';

// Self-update surfacing: the agent reports what the shipped agent_dist offers
// (bundled version) and its update state. A lagging or refused update is a
// problem someone must see — the agent will never install an artifact that
// fails signature verification.
$agent_update_alert = '';
$agent_update_class = 'warning';
if ($agent_online) {
	$update_state = $agent->get('ahb_update_state');
	$bundled      = $agent->get('ahb_bundled_version');
	if ($update_state === 'verify_failed') {
		$agent_update_alert = "Agent update to v{$bundled} REFUSED: the shipped artifact failed checksum or signature verification. The agent will not retry until a corrected release is published.";
		$agent_update_class = 'danger';
	} elseif ($update_state === 'unlogged') {
		$agent_update_alert = "Agent update to v{$bundled} REFUSED: the binary carries the release signature, but its release is not shown to be in the public log, and this machine installs only releases it can see there. Publish a logged release; the agent checks again when it changes.";
		$agent_update_class = 'danger';
	} elseif ($update_state === 'fetch_failed') {
		$agent_update_alert = "Agent update to v{$bundled} could not be fetched yet (the artifact was unreadable or the request timed out — common while a publish is still writing it). The agent retries on its next check.";
	} elseif ($update_state === 'version_rejected') {
		$agent_update_alert = "Agent v{$bundled} failed to start on this host and was rolled back; the agent is holding at v{$agent->get('ahb_agent_version')} until a newer release ships.";
		$agent_update_class = 'danger';
	} elseif ($update_state === 'unsigned_build') {
		$agent_update_alert = "Agent v{$bundled} is available, but the running agent was built without an update key and cannot self-update. Reinstall once from a published build (Run Plugin Installers on this management node).";
	} elseif ($bundled && $agent->get('ahb_agent_version') && $bundled !== $agent->get('ahb_agent_version')) {
		$agent_update_alert = "Agent update to v{$bundled} pending (running v{$agent->get('ahb_agent_version')}). The agent installs it automatically between jobs.";
	}
}
?>

<?php
// A node-detail link for an agentless node, used by the join-request notes.
$node_links = function (array $cands): string {
	$out = [];
	foreach ($cands as $cand) {
		$out[] = '<a href="/admin/server_manager/node_detail?mgn_managed_node_id=' . (int)$cand->key . '&amp;tab=api_keys">'
			. htmlspecialchars($cand->get('mgn_name') ?: $cand->get('mgn_slug')) . '</a>';
	}
	return implode(', ', $out);
};
$confirm_submit = function (string $form_id, string $message): string {
	return 'JoineryModal.confirm(' . htmlspecialchars(json_encode($message), ENT_QUOTES)
		. ', function(){ document.getElementById(\'' . $form_id . '\').submit(); })';
};
?>

<!-- Agent status strip -->
<div class="svm-strip">
	<div class="svm-strip-facts">
		<span><strong>Agent</strong> <span class="badge badge-<?php echo $agent_class; ?>"><?php echo $agent_label; ?></span>
			<?php if ($agent): ?>
				<?php if ($agent->get('ahb_agent_version')): ?><span class="svm-muted">v<?php echo htmlspecialchars($agent->get('ahb_agent_version')); ?></span><?php endif; ?>
				<span class="svm-muted">heartbeat <?php echo LibraryFunctions::time_ago_or_time($agent->get('ahb_last_heartbeat'), 'UTC', $session->get_timezone(), 'M j, g:i:s A'); ?></span>
			<?php else: ?>
				<span class="svm-muted">none has connected yet</span>
			<?php endif; ?>
		</span>
		<span><strong>Cron</strong> <span class="badge badge-<?php echo $cron_is_active ? 'success' : 'danger'; ?>"><?php echo $cron_is_active ? 'Active' : 'Not detected'; ?></span>
			<?php if ($last_cron_run): ?><span class="svm-muted">last run <?php echo LibraryFunctions::time_ago_or_time($last_cron_run, 'UTC', $session->get_timezone(), 'M j, g:i:s A'); ?></span><?php endif; ?>
		</span>
	</div>
	<div class="svm-strip-actions">
		<?php if (PluginHelper::isPluginActive('mailbox')): ?>
			<a href="/plugins/mailbox/admin/admin_mailbox_fleet" class="btn btn-sm btn-outline-secondary">Relay Fleet</a>
		<?php endif; ?>
		<a href="/admin/server_manager/publish_upgrade" class="btn btn-sm btn-primary">Publish New Upgrade</a>
	</div>
</div>
<?php if ($agent_update_alert): ?>
	<div class="alert alert-<?php echo $agent_update_class === 'danger' ? 'danger' : 'warning'; ?>" role="alert"><div class="alert-body"><?php echo htmlspecialchars($agent_update_alert); ?></div></div>
<?php endif; ?>
<?php if (!$agent_online): ?>
	<div class="alert alert-info" role="status"><div class="alert-body">
		<?php if (!$agent): ?>
			The joinery-agent service runs on the management node and services all connected sites.
			Install it here: <code>cd /home/user1/joinery-agent &amp;&amp; make release VERSION=1.0.0 &amp;&amp; sudo bash joinery-agent-installer.sh --verbose</code>
		<?php else: ?>
			The agent was last seen <?php echo LibraryFunctions::time_ago_or_time($agent->get('ahb_last_heartbeat'), 'UTC', $session->get_timezone(), 'M j, g:i:s A'); ?>.
			Check: <code>sudo systemctl status joinery-agent</code> &mdash; <code>journalctl -u joinery-agent -f</code>
		<?php endif; ?>
	</div></div>
<?php endif; ?>

<?php // Backup recovery is not set up, so encrypted backups cannot run.
      // A backup you cannot restore is as silent as monitoring that cannot alert,
      // so it is surfaced the same way. ?>
<?php if (!empty($recovery_problems)): ?>
<div class="alert alert-warning" role="alert"><div class="alert-body">
	<strong>Backups cannot be recovered yet.</strong>
	<ul>
		<?php foreach ($recovery_problems as $p): ?>
			<li>
				<?php if ((int)$p['id'] > 0): ?>
					<a href="/admin/server_manager/node_detail?mgn_managed_node_id=<?php echo (int)$p['id']; ?>&amp;tab=backups"><?php echo htmlspecialchars($p['name'] ?: $p['slug']); ?></a>
				<?php else: // management-node-level problem (recovery setup, agent signing key) ?>
					<strong><?php echo htmlspecialchars($p['name'] ?: $p['slug']); ?></strong>
				<?php endif; ?>
				&mdash; <?php echo htmlspecialchars($p['health']['detail']); ?>
				<?php if (!empty($p['link'])): ?>
					<a href="<?php echo htmlspecialchars($p['link']); ?>">Set it up</a>.
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
</div></div>
<?php endif; ?>

<?php if ($readiness_attention['never'] + $readiness_attention['stale'] + $readiness_attention['warnings'] > 0): ?>
<div class="alert alert-warning" role="alert"><div class="alert-body">
	<strong>Recovery readiness needs attention.</strong>
	<?php
	$bits = [];
	if ($readiness_attention['never'])    { $bits[] = $readiness_attention['never'] . ' must-save ' . ($readiness_attention['never'] === 1 ? 'secret has' : 'secrets have') . ' never been verified'; }
	if ($readiness_attention['stale'])    { $bits[] = $readiness_attention['stale'] . ' ' . ($readiness_attention['stale'] === 1 ? 'was' : 'were') . ' last verified over ' . RecoveryReadiness::STALE_DAYS . ' days ago'; }
	if ($readiness_attention['warnings']) { $bits[] = $readiness_attention['warnings'] . ' ' . ($readiness_attention['warnings'] === 1 ? 'carries' : 'carry') . ' warnings'; }
	echo htmlspecialchars(implode('; ', $bits)) . '.';
	?>
	<a href="/admin/admin_recovery_readiness">Review and verify</a>.
</div></div>
<?php endif; ?>

<?php
// A host with more sites than this starts closed; a choice the operator has
// made (remembered in this browser) overrides it.
$host_default_open_max = 6;
$total_nodes = count($machines);
foreach ($nodes_by_host as $hn_list) { $total_nodes += count($hn_list); }
?>
<!-- Hosts & Sites (left) | Recent Jobs (right) -->
<div class="svm-board">
	<div class="svm-board-main">
		<?php
		// The box header is written out (not begin_box) so the find box and the
		// expand/collapse icons can sit beside the standard Options dropdown.
		$board_links = [
			'Add Host'       => '/admin/server_manager/host_add',
			'Connect Site'   => '/admin/server_manager/node_add',
			'Remote Install' => '/admin/server_manager/install_node_form',
		];
		?>
		<div class="card-header bg-body-tertiary">
			<h6 class="mb-0">Hosts &amp; Sites</h6>
			<div class="card-header-actions" style="display:flex;align-items:center;flex-wrap:wrap;gap:0.5rem;margin-left:auto;">
				<?php if (count($hosts) > 0 || !empty($machines)): ?>
					<input type="search" class="svm-filter" id="svm-node-filter" placeholder="Find a site or host" aria-label="Find a site or host">
					<span class="svm-muted svm-count" id="svm-node-count"><?php echo (int)$total_nodes; ?> nodes</span>
					<button type="button" class="svm-icon-btn" id="svm-expand-all" title="Expand all" aria-label="Expand all">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 8l5-5 5 5"/><path d="M7 16l5 5 5-5"/></svg>
					</button>
					<button type="button" class="svm-icon-btn" id="svm-collapse-all" title="Collapse all" aria-label="Collapse all">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 3l5 5 5-5"/><path d="M7 21l5-5 5 5"/></svg>
					</button>
				<?php endif; ?>
				<div class="dropdown d-inline-block">
					<button class="btn btn-soft-default btn-sm" type="button" data-toggle="dropdown">Options <svg width="10" height="6" viewBox="0 0 10 6" fill="none" stroke="currentColor" stroke-width="1.5" style="vertical-align:middle;margin-left:2px;"><path d="M1 1l4 4 4-4"/></svg></button>
					<div class="dropdown-menu">
						<?php foreach ($board_links as $link_label => $link_url): ?>
							<?php echo AdminPage::renderActionEntry($link_label, $link_url, 'dropdown-item'); ?>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
		</div>
		<div class="card-body">

		<?php if (count($hosts) === 0 && empty($machines)): ?>
			<div class="alert alert-info" role="status"><div class="alert-body">
				<strong>No hosts configured yet.</strong>
				<a href="/admin/server_manager/host_add">Add your first host</a> or
				<a href="/admin/server_manager/node_add">connect a site directly</a>.
			</div></div>
		<?php else: ?>
			<div class="svm-groups" id="svm-groups">

				<?php foreach ($hosts as $host):
					$host_nodes = $nodes_by_host[$host->key] ?? [];
					$site_count = count($host_nodes);
					$max_sites  = (int)$host->get('mgh_max_sites'); // 0 = never set
					$prov_enabled = (bool)$host->get('mgh_provisioning_enabled');
					// Capacity only means something on a host that takes new sites. 100% full is red,
					// 80% amber; a host taking sites with no max set is called out, not given a made-up one.
					$capacity_pct = $max_sites > 0 ? $site_count / $max_sites * 100 : 0;
					$capacity_color = !$max_sites ? 'danger' : ($capacity_pct >= 100 ? 'danger' : ($capacity_pct >= 80 ? 'warning' : 'secondary'));
					$host_node  = $host->host_node();
					$start_open = ($site_count + ($host_node ? 1 : 0)) <= $host_default_open_max;
				?>
				<details class="svm-group" id="host-<?php echo (int)$host->key; ?>" data-group="host-<?php echo (int)$host->key; ?>" <?php echo $start_open ? 'open' : ''; ?>>
					<summary>
						<span class="svm-chev" aria-hidden="true"></span>
						<?php // Red if any node on this host is red, amber if any is amber, green only when every node is green.
						$colors = [];
						foreach (array_merge($host_node ? [$host_node] : [], $host_nodes) as $hn_check) {
							if ($hn_check->get('mgn_delete_time')) { continue; } // a removed site is history, not health
							$colors[] = node_status_color($hn_check, $db);
						}
						$host_color = in_array('danger', $colors, true) ? 'danger'
							: (in_array('warning', $colors, true) ? 'warning'
							: ($colors && count(array_unique($colors)) === 1 && $colors[0] === 'success' ? 'success' : 'secondary')); ?>
						<span class="svm-dot svm-dot-<?php echo $host_color; ?> svm-host-dot" title="<?php echo $host_color === 'danger' ? 'A node on this host is red' : ($host_color === 'warning' ? 'A node on this host needs attention' : ($host_color === 'success' ? 'Every node on this host is green' : 'No node on this host is reporting green yet')); ?>"></span>
						<strong><?php echo htmlspecialchars($host->get('mgh_name')); ?></strong>
						<?php if (strcasecmp(trim((string)$host->get('mgh_name')), trim((string)$host->get('mgh_host'))) !== 0): // a host named by its address shows it once ?>
							<span class="svm-muted"><?php echo htmlspecialchars($host->get('mgh_host')); ?></span>
						<?php endif; ?>
						<?php $ha = host_agent_state($host, $host_node, $site_count);
						      if ($ha): ?>
							<span class="badge badge-subtle-<?php echo $ha['color']; ?>" title="<?php echo htmlspecialchars($ha['hint']); ?>"><?php echo htmlspecialchars($ha['label']); ?></span>
						<?php endif; ?>
						<span class="svm-summary-end">
							<?php if ($prov_enabled): ?>
								<span class="badge badge-<?php echo $capacity_color; ?>" title="<?php echo $max_sites ? 'This host takes new sites up to ' . $max_sites . '.' : 'Edit the host and set how many sites it can hold.'; ?>"><?php echo $max_sites ? $site_count . ' / ' . $max_sites . ' sites' : $site_count . ' sites, no max set'; ?></span>
							<?php else: ?>
								<span class="svm-muted svm-count"><?php echo $site_count; ?> <?php echo $site_count === 1 ? 'site' : 'sites'; ?></span>
							<?php endif; ?>
							<span class="svm-menu">
								<button type="button" class="btn btn-sm btn-outline-secondary svm-menu-btn" aria-haspopup="true" aria-expanded="false">Actions <span class="svm-caret" aria-hidden="true"></span></button>
								<div class="svm-menu-list" role="menu" hidden>
									<a href="/admin/server_manager/install_node_form" role="menuitem">Install Site</a>
									<a href="/admin/server_manager/host_add?mgh_managed_host_id=<?php echo (int)$host->key; ?>" role="menuitem">Edit Host</a>
									<?php if ($site_count > 0): ?>
									<form method="post" action="/admin/server_manager" id="upgrade_host_<?php echo (int)$host->key; ?>" class="svm-menu-form">
										<input type="hidden" name="action" value="upgrade_host_sites">
										<input type="hidden" name="mgh_managed_host_id" value="<?php echo (int)$host->key; ?>">
										<?php echo SmAdminCsrf::field(); ?>
										<button type="button" role="menuitem"
											onclick="<?php echo $confirm_submit('upgrade_host_' . (int)$host->key, 'Queue an upgrade job for every enabled site on ' . $host->get('mgh_name') . '? Each site upgrades independently; disable a site first to skip it.'); ?>">Upgrade all host sites</button>
									</form>
									<?php endif; ?>
								</div>
							</span>
						</span>
					</summary>
					<div class="svm-group-body">
						<div class="svm-nodes">
							<?php if ($host_node): ?>
								<?php echo render_node_row($host_node, $db, $session, 'host agent'); ?>
							<?php endif; ?>
							<?php foreach ($host_nodes as $node): ?>
								<?php echo render_node_row($node, $db, $session); ?>
							<?php endforeach; ?>
						</div>
						<?php if (empty($host_nodes)): ?>
							<div class="svm-muted svm-empty">No sites on this host.</div>
						<?php endif; ?>
					</div>
				</details>
				<?php endforeach; ?>

				<?php // A machine (bare metal, or a one-site install) is not a box with sites to fold away:
				      // it is one gray bar carrying its name, domain and IP, not collapsible. ?>
				<?php foreach ($machines as $node): ?>
				<div class="svm-group svm-machine" data-group="machine-<?php echo (int)$node->key; ?>">
					<?php echo render_node_row($node, $db, $session, '', true); ?>
				</div>
				<?php endforeach; ?>

			</div>
			<div class="svm-empty svm-muted" id="svm-no-match" hidden>Nothing matches.</div>
		<?php endif; ?>
		<div class="svm-box-foot">
			<?php if ($show_all): ?>
				<a href="/admin/server_manager">Hide removed sites</a>
				<span class="svm-muted">Showing all sites, including removed ones.</span>
			<?php else: ?>
				<a href="/admin/server_manager?show_all=1">Show all sites (including removed)</a>
			<?php endif; ?>
		</div>
		</div>
	</div>

	<!-- RIGHT: Recent Jobs -->
	<div class="svm-board-side">
	<?php // Agents waiting to be let in. A line when closed; compact entries when
	      // open, in a scroll-box, so 50-100 asks a day stay one panel tall. ?>
	<?php if (!empty($pending_joins) || !empty($rejected_joins)): ?>
	<details class="card svm-card" <?php echo !empty($pending_joins) ? 'open' : ''; ?> id="svm-joins">
		<summary class="card-header">
			<span class="svm-chev" aria-hidden="true"></span>
			<h6>Agents asking to join</h6>
			<?php $jn = count($pending_joins); ?>
			<span class="badge <?php echo $jn ? 'badge-primary' : 'badge-subtle-secondary'; ?> svm-card-count"><?php echo $jn ?: 'none'; ?></span>
		</summary>
		<div class="svm-card-body">
			<?php if ($jn): ?>
			<p class="svm-joins-warn">Approve only if the key matches what the machine printed.</p>
			<?php if ($jn > 6): ?>
				<input type="search" class="svm-filter svm-joins-filter" placeholder="Filter by name, address or key"
					aria-label="Filter join requests" data-filter-rows=".svm-join-row">
			<?php endif; ?>
			<div class="svm-joins-scroll">
			<?php foreach ($pending_joins as $jr):
				$jr_since = time() - strtotime($jr->get('ajr_create_time') . ' UTC');
				$jr_age  = max(0, (int)floor($jr_since / 60));
				$jr_fpr  = AgentJoinRequest::display_fingerprint((string)$jr->get('ajr_fingerprint'));
				$jr_ip   = (string)$jr->get('ajr_source_ip');
				$jr_name = (string)$jr->get('ajr_claimed_name');
				$jr_self = AgentChannelEndpoint::isThisMachine($jr_ip);
				$jr_prov = AgentChannelEndpoint::provisionForAddress($jr_ip);
				$jr_host_claim = $jr_prov && trim($jr_name) === trim((string)$jr_prov->get('cvp_slug')) . '-host';
				$jr_site_claim = $jr_prov && !$jr_host_claim;
				$jr_verdict = JoinAutoApproval::verdict($jr); // why this one is still here for a person
				if ($jr_self) {
					$jr_tag = ['this machine', 'primary', 'This management node\'s own machine, asking to be managed like any other. Approving names the record after this site.'];
				} elseif ($jr_host_claim) {
					$jr_tag = ['host agent', 'primary', 'Host agent of provision #' . (int)$jr_prov->key . ' (' . $jr_prov->get('cvp_domain') . '). Approving confirms with the provider that the instance is running, then makes the host node at ' . $jr_prov->get('cvp_instance_ip') . '.'];
				} elseif ($jr_prov) {
					$jr_tag = ['provision', 'warning', 'Address belongs to provision #' . (int)$jr_prov->key . ' (' . $jr_prov->get('cvp_domain') . '). Approving asks the provider to confirm the instance is running at this address, then binds the agent to the provision\'s site node.'];
				} else {
					$jr_tag = ['new node', 'success', 'Approving makes a node record named ' . $jr_name . ' at ' . $jr_ip . '.'];
				}
				if ($jr_verdict['key_mismatch']) {
					$jr_tag = ['key mismatch', 'danger', 'This address is a machine this plane provisioned, but the key is not the one its install showed. Do not approve unless you know why.'];
				}
			?>
				<div class="svm-join-row" data-filter-text="<?php echo htmlspecialchars(strtolower($jr_name . ' ' . $jr_ip . ' ' . $jr_fpr)); ?>">
					<div class="svm-join-top">
						<strong class="svm-join-name" title="<?php echo htmlspecialchars($jr_name); ?>"><?php echo htmlspecialchars($jr_name); ?></strong>
						<span class="badge badge-subtle-<?php echo $jr_tag[1]; ?>" title="<?php echo htmlspecialchars($jr_tag[2]); ?>"><?php echo htmlspecialchars($jr_tag[0]); ?></span>
						<span class="svm-muted svm-join-age"><?php echo $jr_age === 0 ? 'now' : $jr_age . 'm'; ?></span>
					</div>
					<div class="svm-join-sub svm-muted"><?php echo htmlspecialchars($jr_ip); ?> &middot; <code><?php echo htmlspecialchars($jr_fpr); ?></code></div>
					<?php if ($jr_verdict['provision'] && $jr_verdict['eligible']): ?>
						<div class="svm-join-sub svm-muted">Matches what the install showed; approving automatically within a minute.</div>
					<?php elseif ($jr_verdict['provision']): ?>
						<div class="svm-join-sub <?php echo $jr_verdict['key_mismatch'] ? 'svm-bad' : 'svm-muted'; ?>" title="<?php echo htmlspecialchars(ucfirst($jr_verdict['reason'])); ?>"><?php echo $jr_verdict['key_mismatch'] ? '<strong>Key does not match what the install showed.</strong>' : htmlspecialchars(ucfirst($jr_verdict['reason'])) . '.'; ?></div>
					<?php endif; ?>
					<div class="svm-join-actions">
						<form method="post" action="/admin/server_manager" id="adopt_join_<?php echo (int)$jr->key; ?>" class="svm-inline-form">
							<input type="hidden" name="action" value="<?php echo $jr_site_claim ? 'adopt_provision_join' : 'adopt_join'; ?>">
							<input type="hidden" name="ajr_agent_join_request_id" value="<?php echo (int)$jr->key; ?>">
							<?php echo SmAdminCsrf::field(); ?>
							<button type="button" class="btn btn-sm btn-primary"
								onclick="<?php echo $confirm_submit('adopt_join_' . (int)$jr->key, ($jr_self ? 'Connect this management node\'s own agent' : 'Connect ' . $jr_name) . '? Confirm the key ' . $jr_fpr . ' matches what the machine printed first.'); ?>">Approve</button>
						</form>
						<form method="post" action="/admin/server_manager" id="reject_join_<?php echo (int)$jr->key; ?>" class="svm-inline-form">
							<input type="hidden" name="action" value="reject_join">
							<input type="hidden" name="ajr_agent_join_request_id" value="<?php echo (int)$jr->key; ?>">
							<?php echo SmAdminCsrf::field(); ?>
							<button type="button" class="btn btn-sm btn-outline-danger"
								onclick="<?php echo $confirm_submit('reject_join_' . (int)$jr->key, 'Reject the join request from ' . $jr_name . '?'); ?>">Reject</button>
						</form>
					</div>
				</div>
			<?php endforeach; ?>
			</div>
			<?php endif; ?>

			<?php // A rejection can be a mis-click. The machine keeps asking with the same
			      // key; reopening the row lets that ask be answered. Kept for a day. ?>
			<?php if (!empty($rejected_joins)): ?>
			<details class="svm-rejected">
				<summary>Rejected in the last day (<?php echo count($rejected_joins); ?>)</summary>
				<ul>
				<?php foreach ($rejected_joins as $rj): ?>
					<li>
						<span><strong><?php echo htmlspecialchars($rj->get('ajr_claimed_name')); ?></strong>
						<span class="svm-muted"><?php echo htmlspecialchars((string)$rj->get('ajr_source_ip')); ?></span></span>
						<form method="post" action="/admin/server_manager" class="svm-inline-form">
							<input type="hidden" name="action" value="reopen_join">
							<input type="hidden" name="ajr_agent_join_request_id" value="<?php echo (int)$rj->key; ?>">
							<?php echo SmAdminCsrf::field(); ?>
							<button type="submit" class="btn btn-sm btn-outline-secondary">Reopen</button>
						</form>
					</li>
				<?php endforeach; ?>
				</ul>
			</details>
			<?php endif; ?>
		</div>
	</details>
	<?php endif; ?>

	<?php if (count($inflight_provisions)): ?>
	<?php // A provision's machine is a host on this board when a host record carries its address.
	$host_by_addr = [];
	foreach ($hosts as $h) { $host_by_addr[strtolower(trim((string)$h->get('mgh_host')))] = (int)$h->key; }
	?>
	<!-- Cloud provisions in flight -->
	<details class="card svm-card" open>
		<summary class="card-header">
			<span class="svm-chev" aria-hidden="true"></span>
			<h6>Cloud provisions</h6>
			<span class="badge badge-primary svm-card-count"><?php echo count($inflight_provisions); ?></span>
		</summary>
		<div class="svm-card-body svm-prov-list">
			<?php foreach ($inflight_provisions as $prov):
				$pstatus = $prov->get('cvp_status');
				$badge = ($pstatus === 'failed') ? 'danger' : (($pstatus === 'pending_connect') ? 'warning' : (($pstatus === 'done') ? 'success' : 'info'));
				$pw_state = (string)$prov->get('cvp_install_password');
				$pw_summary = ProvisionCustomerCloud::install_password_summary($prov);
				$perr = trim((string)$prov->get('cvp_error'));
				// One line of detail, only when there is something to act on: the error,
				// else an install password that has not been retired.
				$pdetail = $perr !== '' ? $perr : (($pw_state !== 'retired' && $pw_summary !== '') ? $pw_summary : '');
				$dismiss_blockers = $prov->dismiss_blockers();
			?>
				<div class="svm-prov">
					<div class="svm-prov-top">
						<?php $prov_node_id = (int)$prov->get('cvp_mgn_managed_node_id');
						      $prov_host_id = $host_by_addr[strtolower(trim((string)$prov->get('cvp_instance_ip')))] ?? 0; ?>
						<strong class="svm-prov-name" title="<?php echo htmlspecialchars($prov->get('cvp_domain')); ?>"><?php
							if ($prov_node_id): ?><a href="/admin/server_manager/node_detail?mgn_managed_node_id=<?php echo $prov_node_id; ?>"><?php echo htmlspecialchars($prov->get('cvp_domain')); ?></a><?php
							else: echo htmlspecialchars($prov->get('cvp_domain')); endif; ?></strong>
						<span class="badge badge-<?php echo $badge; ?>"><?php echo htmlspecialchars($pstatus); ?></span>
						<?php $prov_auto = 0;
						foreach ($prov->machine_addresses() as $maddr) {
							foreach (new MultiAgentJoinRequest(['source_ip' => $maddr, 'deleted' => false], ['ajr_create_time' => 'DESC'], 10) as $mjr) {
								if ($mjr->get('ajr_decided_by') === 'auto') { $prov_auto++; }
							}
						}
						if ($prov_auto): ?><span class="badge badge-subtle-success" title="<?php echo $prov_auto; ?> agent<?php echo $prov_auto === 1 ? '' : 's'; ?> on this machine approved automatically, matched against the keys its install showed.">auto-approved</span><?php endif; ?>
						<?php if ($prov_host_id): ?><a href="#host-<?php echo $prov_host_id; ?>" class="svm-host-jump" title="Show this machine's host on the board">host</a><?php endif; ?>
						<?php if (!$dismiss_blockers): ?>
							<form method="post" action="/admin/server_manager" id="dismiss_prov_<?php echo (int)$prov->key; ?>" class="svm-inline-form">
								<input type="hidden" name="action" value="dismiss_provision">
								<input type="hidden" name="cvp_customer_cloud_provision_id" value="<?php echo (int)$prov->key; ?>">
								<?php echo SmAdminCsrf::field(); ?>
								<button type="button" class="btn btn-sm btn-outline-secondary"
									onclick="<?php echo $confirm_submit('dismiss_prov_' . (int)$prov->key, 'Dismiss ' . $prov->get('cvp_domain') . '? It created nothing, so this only clears the record off this board.'); ?>">Dismiss</button>
							</form>
						<?php endif; ?>
					</div>
					<?php if ($pdetail !== ''): ?>
						<div class="svm-prov-detail <?php echo ($perr !== '' || $pw_state === 'retire_failed') ? 'svm-bad' : 'svm-muted'; ?>"
							title="<?php echo htmlspecialchars($pdetail . ($pw_summary !== '' ? ' — install password: ' . $pw_summary : '')); ?>"><?php echo htmlspecialchars(mb_substr($pdetail, 0, 90)); ?></div>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
	</details>
	<?php endif; ?>

		<details class="card svm-card" open>
			<summary class="card-header">
				<span class="svm-chev" aria-hidden="true"></span>
				<h6>Recent Jobs</h6>
			</summary>
			<div class="svm-card-body svm-jobs">
			<?php foreach ($recent_jobs as $job): ?>
				<?php
				$status_class = match($job->get('mjb_status')) {
					'completed' => 'success',
					'failed' => 'danger',
					'running' => 'primary',
					'cancelled' => 'secondary',
					default => 'warning',
				};

				$node_name = '-';
				$node_id = $job->get('mjb_mgn_managed_node_id');
				if ($node_id) {
					try {
						$job_node = new ManagedNode($node_id, TRUE);
						$node_name = $job_node->get('mgn_name');
					} catch (Exception $e) {
						$node_name = "Node #{$node_id}";
					}
				}
				?>
				<div class="svm-job">
					<div class="svm-job-top">
						<a href="/admin/server_manager/job_detail?job_id=<?php echo $job->key; ?>">#<?php echo $job->key; ?></a>
						<span class="svm-job-site" title="<?php echo htmlspecialchars($node_name); ?>"><?php echo htmlspecialchars($node_name); ?></span>
						<span class="badge badge-<?php echo $status_class; ?>"><?php echo htmlspecialchars($job->get('mjb_status')); ?></span>
					</div>
					<div class="svm-job-sub svm-muted">
						<?php echo htmlspecialchars(str_replace('_', ' ', $job->get('mjb_job_type'))); ?>
						&middot; <?php echo $job->get('mjb_started_time') ? LibraryFunctions::time_ago_or_time($job->get('mjb_started_time'), 'UTC', $session->get_timezone(), 'M j, g:i A') : 'not started'; ?>
					</div>
				</div>
			<?php endforeach; ?>
			<?php if (count($recent_jobs) === 0): ?>
				<div class="svm-empty svm-muted">No jobs yet</div>
			<?php endif; ?>
				<div class="svm-card-foot"><a href="/admin/server_manager/jobs">All jobs</a></div>
			</div>
		</details>
	</div>
</div>

<?php
/** The colour of a node's status dot: what JobCommandBuilder says of its stored status and last status job. */
function node_status_color($node, $db) {
	$status_data = $node->get('mgn_last_status_data');
	if (is_string($status_data)) $status_data = json_decode($status_data, true);
	$last_job_failed = false;
	$last_job_q = $db->prepare(
		"SELECT mjb_status FROM mjb_management_jobs " .
		"WHERE mjb_mgn_managed_node_id = ? AND mjb_job_type = 'check_status' AND mjb_delete_time IS NULL " .
		"ORDER BY mjb_management_job_id DESC LIMIT 1"
	);
	$last_job_q->execute([$node->key]);
	$last_job_row = $last_job_q->fetch(PDO::FETCH_ASSOC);
	if ($last_job_row && $last_job_row['mjb_status'] === 'failed') {
		$last_job_failed = true;
	}
	return JobCommandBuilder::status_color_for_node($node, $status_data, $last_job_failed);
}
?>

<?php
/**
 * What the server manager can say about a host's own (machine-level) agent. The host agent does
 * what a site's agent cannot do for itself: remove sites, renew certificates, install containers.
 * Only what has contacted this plane is knowable, so "no agent exists" and "an agent exists
 * but has never reached us" are the same state (nothing heard).
 *
 * Returns ['label', 'color', 'hint'] for a state worth showing, or null when the agent is
 * linked and checking in (or the host has nothing for an agent to do yet).
 */
function host_agent_state($host, $host_node, int $site_count): ?array {
	if ($host_node) {
		if (trim((string)$host_node->get('mgn_agent_public_key')) === '') {
			return ['label' => 'host agent unpaired', 'color' => 'warning',
				'hint' => 'The host agent record exists but is not paired. Removing sites and renewing certificates on this server wait for it to join again.'];
		}
		if (trim((string)$host_node->get('mgn_agent_last_poll')) === '') {
			return ['label' => 'host agent not checked in yet', 'color' => 'warning',
				'hint' => 'The host agent was approved but has not checked in yet.'];
		}
		if ((new IncidentSourceAgentSilent())->evaluate($host_node) !== null) {
			return ['label' => 'host agent offline', 'color' => 'danger',
				'hint' => 'The host agent has not checked in for over ' . (int)(IncidentSourceAgentSilent::SILENT_AFTER / 3600)
					. ' hours (last: ' . $host_node->get('mgn_agent_last_poll') . ' UTC). Removing sites and renewing certificates here wait until it returns.'];
		}
		return null; // linked and checking in
	}

	$addr = trim((string)$host->get('mgh_host'));
	$norm = function ($ip) {
		$ip = trim((string)$ip);
		return class_exists('CustomerCloudProvision') ? CustomerCloudProvision::normalize_address($ip) : strtolower($ip);
	};

	// An agent that asked to join from this box and has not been let in. Waiting beats turned away.
	$waiting = $turned_away = $expired = false;
	foreach (new MultiAgentJoinRequest(['source_ip' => $addr, 'deleted' => false], ['ajr_create_time' => 'DESC'], 10) as $jr) {
		if ($norm($jr->get('ajr_source_ip')) !== $norm($addr)) { continue; }
		$status = $jr->get('ajr_status');
		if ($status === AgentJoinRequest::STATUS_REJECTED) { $turned_away = true; }
		elseif ($status === AgentJoinRequest::STATUS_PENDING) { if ($jr->is_expired()) { $expired = true; } else { $waiting = true; } }
	}
	if ($waiting) {
		return ['label' => 'host agent waiting for approval', 'color' => 'primary',
			'hint' => 'An agent on this server asked to join and is waiting for you. Approve it in "Agents asking to join".'];
	}

	// A machine-level node at this address that is paired but not named on the host record.
	foreach (new MultiManagedNode(['host' => $addr, 'deleted' => false]) as $n) {
		if (trim((string)$n->get('mgn_container_name')) === '' && trim((string)$n->get('mgn_web_root')) === ''
				&& trim((string)$n->get('mgn_agent_public_key')) !== '') {
			return ['label' => 'host agent not linked', 'color' => 'warning',
				'hint' => $n->get('mgn_name') . ' joined from this server but this host record does not point to it. Edit Host and choose it as the host agent.'];
		}
	}

	if ($turned_away) {
		return ['label' => 'host agent turned away', 'color' => 'warning',
			'hint' => 'An agent on this server asked to join and was rejected. It can be reopened for a day under "Rejected in the last day".'];
	}
	if ($expired) {
		return ['label' => 'host agent request expired', 'color' => 'warning',
			'hint' => 'An agent on this server asked to join, nobody answered in time, and the request expired. Run the join again on the machine.'];
	}
	if ($site_count === 0) { return null; } // nothing here for a host agent to do yet
	return ['label' => 'host agent not heard from', 'color' => 'secondary',
		'hint' => 'No host agent has contacted this plane from this server, so there is nothing to tell an agent that is not installed from one that has not reached us. Removing sites and renewing certificates here have no path until one joins.'];
}
?>

<?php
/**
 * Render a single node row (used in each host panel and the ungrouped section).
 * One line: status dot, name, badges, site URL pushed right.
 */
function render_node_row($node, $db, $session, $role_badge = '', $show_ip = false) {
	$last_check = $node->get('mgn_last_status_check');
	$install_state = $node->get('mgn_install_state');
	$status_color = node_status_color($node, $db);

	$node_version = $node->get('mgn_joinery_version');
	$version_cmp  = null;
	if ($node_version) {
		$cp_version = LibraryFunctions::get_joinery_version();
		if ($cp_version !== '' && preg_match('/^\d+\.\d+\.\d+$/', $node_version)) {
			$version_cmp = version_compare($node_version, $cp_version);
		}
	}

	$api_refreshable = !empty($node->get('mgn_site_url'))
		&& $node->is_operational()
		&& !$node->get('mgn_delete_time'); // never poll a removed site

	$ssl_state = $node->get('mgn_ssl_state');
	$name = (string)$node->get('mgn_name');
	$url  = (string)$node->get('mgn_site_url');
	// The small text after the name: the site's domain and, on a machine of its own
	// (not a container sharing its host's address), the IP. Each value once: a domain
	// the name already is, or an IP the name already is, is not repeated.
	$shown = [strtolower(trim($name))];
	$where = [];
	$url_host = (string)(parse_url($url, PHP_URL_HOST) ?: '');
	foreach (array_filter([$url_host, $show_ip ? trim((string)$node->get('mgn_host')) : '']) as $piece) {
		if (!in_array(strtolower($piece), $shown, true)) { $shown[] = strtolower($piece); $where[] = $piece; }
	}

	ob_start();
	?>
	<div class="svm-node node-row"<?php echo $node->get('mgn_delete_time') ? ' data-removed="1"' : ''; ?>
		data-href="/admin/server_manager/node_detail?mgn_managed_node_id=<?php echo $node->key; ?>"
		data-node-id="<?php echo $node->key; ?>"
		data-filter-text="<?php echo htmlspecialchars(strtolower($name . ' ' . $url . ' ' . $node->get('mgn_host'))); ?>"
		data-api-refreshable="<?php echo $api_refreshable ? '1' : '0'; ?>"
		onclick="if(!event.target.closest('form,button,input,a')) window.location=this.dataset.href">
		<span class="svm-dot svm-dot-<?php echo htmlspecialchars($status_color); ?> js-status-badge"></span>
		<span class="svm-node-name"><?php echo htmlspecialchars($name); ?></span>
		<?php if ($role_badge !== ''): ?>
			<span class="badge badge-primary"><?php echo htmlspecialchars($role_badge); ?></span>
		<?php endif; ?>
		<?php if ($node->get('mgn_delete_time')): ?>
			<span class="badge badge-secondary" title="Removed <?php echo htmlspecialchars($node->get_local('mgn_delete_time', 'M j, Y')); ?>">Removed</span>
		<?php endif; ?>
		<?php if (!$node->is_operational()): ?>
			<span class="badge badge-<?php echo htmlspecialchars(JobCommandBuilder::install_state_color($install_state)); ?>"><?php echo htmlspecialchars($node->install_state_label()); ?></span>
		<?php endif; ?>
		<?php if ($ssl_state === 'pending'): ?>
			<span class="badge badge-warning">SSL pending</span>
		<?php elseif ($ssl_state === 'failed'): ?>
			<span class="badge badge-danger">SSL failed</span>
		<?php endif; ?>
		<span class="js-version-indicator">
			<?php if ($version_cmp === -1): ?>
				<span class="badge badge-warning" title="Management node is at <?php echo htmlspecialchars($cp_version ?? ''); ?>">upgrade available</span>
			<?php elseif ($version_cmp === 1): ?>
				<span class="badge badge-danger" title="Management node is at <?php echo htmlspecialchars($cp_version ?? ''); ?>">ahead of management node</span>
			<?php endif; ?>
		</span>
		<?php
		// Agent version spread (specs/agent_recipes_and_vocabulary.md,
		// Different agent versions): each node's agent, how far behind
		// the agent this management node ships, and whether it is below
		// the oldest this management node supports — where the only job
		// it is offered is Apply Update.
		$spread = AgentVocabulary::spread($node);
		if ($spread !== null):
			if ($spread['below_floor']): ?>
				<span class="badge badge-danger" title="Below <?php echo htmlspecialchars(AgentVocabulary::FLOOR); ?>, the oldest agent this management node supports. Apply an update to this node; nothing else is offered until then."><?php echo htmlspecialchars($spread['label']); ?> — below minimum</span>
			<?php elseif ((int)$spread['behind'] > 0): ?>
				<span class="badge badge-subtle-secondary" title="This management node ships agent <?php echo htmlspecialchars((string)AgentVocabulary::newest()); ?>"><?php echo htmlspecialchars($spread['label']); ?></span>
			<?php else: ?>
				<small class="svm-muted"><?php echo htmlspecialchars($spread['label']); ?></small>
			<?php endif;
		endif; ?>
		<small class="svm-muted js-last-check"><?php
			if ($last_check) {
				echo '(' . htmlspecialchars(LibraryFunctions::time_ago_or_time($last_check, 'UTC', $session->get_timezone(), 'M j, g:i A')) . ')';
			}
		?></small>
		<?php if ($where): ?>
			<small class="svm-muted svm-node-url" title="<?php echo htmlspecialchars($url !== '' ? $url : implode(' · ', $where)); ?>"><?php echo htmlspecialchars(implode(' · ', $where)); ?></small>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}
?>

<?php echo SmAssets::script_tag(); ?>
<?php echo SmAssets::script_tag('server_manager_board.js'); ?>
<script>
// Auto-refresh status for nodes with API credentials. Fires once on page load
// in parallel, bypassing the agent/job pipeline. Silent on failure — the
// pre-rendered dot (from last stored status) stays as the fallback.
(function() {
	var rows = document.querySelectorAll('.node-row[data-api-refreshable="1"]');
	if (!rows.length) return;

	var colors = ['secondary','success','warning','danger','info','primary'];

	// A host's dot follows its nodes: red if any is red, amber if any is amber, green only if all are green.
	function refreshHostDot(row) {
		var group = row.closest('.svm-group');
		var hostDot = group && group.querySelector('.svm-host-dot');
		if (!hostDot) return;
		var seen = [];
		group.querySelectorAll('.svm-node:not([data-removed]) .svm-dot').forEach(function(d) {
			colors.forEach(function(c) { if (d.classList.contains('svm-dot-' + c)) seen.push(c); });
		});
		var color = seen.indexOf('danger') !== -1 ? 'danger'
			: (seen.indexOf('warning') !== -1 ? 'warning'
			: (seen.length && seen.every(function(c) { return c === 'success'; }) ? 'success' : 'secondary'));
		colors.forEach(function(c) { hostDot.classList.remove('svm-dot-' + c); });
		hostDot.classList.add('svm-dot-' + color);
	}

	rows.forEach(function(row) {
		var nodeId = row.getAttribute('data-node-id');
		var dot = row.querySelector('.js-status-badge');
		var versionSpan = row.querySelector('.js-version-indicator');
		var lastCheckSpan = row.querySelector('.js-last-check');
		if (dot) dot.style.opacity = '0.4';

		smApiPost('refresh_node_status', { node_id: nodeId })
			.then(function(j) {
				if (dot) dot.style.opacity = '';
				if (!j.ok) return;

				if (dot && j.status_color) {
					colors.forEach(function(c) { dot.classList.remove('svm-dot-' + c); });
					dot.classList.add('svm-dot-' + j.status_color);
				}

				// Only update the version badge when the response includes definitive
				// version data. HTTP-only checks omit version_cmp entirely; API checks
				// without a joinery_version return null. In both cases, preserve the
				// server-rendered badge rather than clearing it.
				if (versionSpan && 'version_cmp' in j && j.version_cmp !== null) {
					versionSpan.innerHTML = '';
					if (j.version_cmp === -1) {
						versionSpan.innerHTML = '<span class="badge badge-warning" title="Management node is at ' +
							smEsc(j.cp_version || '') + '">upgrade available</span>';
					} else if (j.version_cmp === 1) {
						versionSpan.innerHTML = '<span class="badge badge-danger" title="Management node is at ' +
							smEsc(j.cp_version || '') + '">ahead of management node</span>';
					}
				}

				if (lastCheckSpan && j.last_check) {
					lastCheckSpan.textContent = '(' + j.last_check + ')';
				}
				refreshHostDot(row);
			})
			.catch(function() {
				if (dot) dot.style.opacity = '';
			});
	});
})();
</script>

<?php
$page->admin_footer();
?>
