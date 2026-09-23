<?php
/**
 * Server Manager — Staged rollout
 * URL: /admin/server_manager/rollouts
 *
 * Apply the release this management node serves across chosen nodes, one at
 * a time, in the order given, stopping at the first node whose apply does not
 * prove good by its structured apply result (specs/agent_recipes_and_vocabulary.md,
 * "staged_rollout"). A tracked record: it survives a reload, shows where it
 * is, and can be stopped between nodes. The AdvanceStagedRollouts task moves
 * it; loading this page moves it too.
 *
 * @version 1.1 - the release shown and rolled out is the newest published one (review B20)
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

$session = SessionControl::get_instance();
$session->check_permission(10);
$session->set_return();

$page_regex = '/\/admin\/server_manager/';
$self_url = '/admin/server_manager/rollouts';

$flash = function ($text, $type) use ($session, $page_regex) {
	$session->save_message(new DisplayMessage($text, $type === 'error' ? 'Error' : 'Rollout', $page_regex,
		$type === 'error' ? DisplayMessage::MESSAGE_ERROR : DisplayMessage::MESSAGE_ANNOUNCEMENT,
		DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
};

if ($_POST) {
	if (!SmAdminCsrf::valid()) { header('Location: ' . $self_url); exit; }
	$action = (string)($_POST['action'] ?? '');
	if ($action === 'start_rollout') {
		// Each node's position is a number; blank leaves it out. Ties keep the
		// order the list shows.
		$order = array();
		foreach ($_POST as $k => $v) {
			if (preg_match('/^order_(\d+)$/', $k, $m) && trim((string)$v) !== '' && ctype_digit(trim((string)$v))) {
				$order[] = array((int)trim((string)$v), (int)$m[1]);
			}
		}
		usort($order, function ($a, $b) { return $a[0] <=> $b[0]; });
		try {
			$r = StagedRolloutRunner::start(array_column($order, 1), $session->get_user_id());
			$flash('Rollout of ' . $r->get('srl_release') . ' started across ' . count($r->steps()) . ' node(s).', 'ok');
		} catch (StagedRolloutException $e) {
			$flash($e->getMessage(), 'error');
		}
	} elseif ($action === 'stop_rollout') {
		$running = StagedRollout::running();
		if ($running && (int)$running->key === (int)($_POST['rollout_id'] ?? 0)) {
			StagedRolloutRunner::stop($running, $session->get_user_id());
			$flash('Rollout stopped. An apply already running on a node finishes; nothing further is queued.', 'ok');
		}
	}
	header('Location: ' . $self_url);
	exit;
}

// Loading the page is a tick: a rollout moves even if the scheduler is slow.
$running = StagedRollout::running();
if ($running) {
	StagedRolloutRunner::advance($running);
	$running->load();
	if (!$running->is_running()) {
		$running = null;
	}
}

$page = new AdminPage();
$page->admin_header([
	'menu-id' => 'server-manager',
	'page_title' => 'Staged rollout',
	'readable_title' => 'Staged rollout',
	'breadcrumbs' => ['Server Manager' => '/admin/server_manager', 'Staged rollout' => ''],
	'session' => $session,
]);

$display_messages = $session->get_messages('/admin/server_manager');
foreach ($display_messages as $msg) {
	$cls = ($msg->display_type == DisplayMessage::MESSAGE_ERROR) ? 'alert-danger' : 'alert-success';
	echo '<div class="alert ' . $cls . '" role="alert">' . htmlspecialchars($msg->message)
		. '<button type="button" class="alert-close" aria-label="Close">&times;</button></div>';
}
$session->mark_shown($display_messages);
$session->clear_clearable_messages();

// The newest PUBLISHED release: what a node's apply_update pulls.
$release = (string)(StagedRolloutRunner::published_release() ?? 'nothing published');
$verdict_class = array('passed' => 'text-success', 'failed' => 'text-danger', 'running' => 'text-info', 'pending' => 'text-muted', 'skipped' => 'text-muted');

$render_rollout = function (StagedRollout $r) use ($verdict_class) {
	$status = (string)$r->get('srl_status');
	echo '<p class="mb-1"><strong>' . htmlspecialchars($r->get('srl_release')) . '</strong> — ' . htmlspecialchars($status)
		. ' <small class="text-muted">started ' . htmlspecialchars($r->get_local('srl_create_time', 'M j, g:i A')) . '</small></p>';
	if ($r->get('srl_halt_reason')) {
		echo '<div class="alert alert-danger small">Halted at ' . htmlspecialchars($r->get('srl_halt_reason')) . '</div>';
	}
	echo '<table class="table table-sm mb-2"><thead><tr><th>#</th><th>Node</th><th>Result</th><th>Job</th></tr></thead><tbody>';
	foreach ($r->steps() as $i => $s) {
		$v = (string)$s['verdict'];
		echo '<tr><td>' . ($i + 1) . '</td><td><a href="/admin/server_manager/node_detail?mgn_managed_node_id=' . (int)$s['node_id'] . '">'
			. htmlspecialchars($s['name']) . '</a></td><td class="' . ($verdict_class[$v] ?? '') . '">' . htmlspecialchars($v)
			. ($s['reason'] !== '' ? ' — ' . htmlspecialchars($s['reason']) : '') . '</td><td>'
			. (!empty($s['job_id']) ? '<a href="/admin/server_manager/job_detail?job_id=' . (int)$s['job_id'] . '">#' . (int)$s['job_id'] . '</a>' : '')
			. '</td></tr>';
	}
	echo '</tbody></table>';
};

$page->begin_box(['title' => 'Rollout in progress']);
if ($running) {
	$render_rollout($running);
	echo '<form method="post" class="svm-inline-form">' . SmAdminCsrf::field()
		. '<input type="hidden" name="action" value="stop_rollout">'
		. '<input type="hidden" name="rollout_id" value="' . (int)$running->key . '">'
		. '<button type="button" class="btn btn-sm btn-outline-danger" onclick="var f=this.parentElement; JoineryModal.confirm(\'Stop this rollout? An apply already running on a node finishes; no further node is started.\', function(){ f.submit(); })">Stop</button></form>';
} else {
	echo '<p class="text-muted mb-0">None. A rollout applies this management node\'s release (' . htmlspecialchars($release)
		. ') to one node at a time and moves on only when that node\'s apply completed, passed its deploy tier, reports the new version, and did not roll back.</p>';
}
$page->end_box();

if (!$running) {
	$page->begin_box(['title' => 'Start a rollout of ' . $release]);
	echo '<p class="text-muted">Give each node to include a position: 1 is applied first. Put the node you would least mind breaking first. Leave a node blank to leave it out.</p>';
	$fw = $page->getFormWriter('rollout_form');
	$fw->begin_form();
	$fw->hiddeninput('action', '', ['value' => 'start_rollout']);
	$fw->hiddeninput(SmAdminCsrf::FIELD, '', ['value' => SmAdminCsrf::token()]);
	$any = false;
	foreach (new MultiManagedNode(array('deleted' => false), array('mgn_name' => 'ASC')) as $node) {
		if (!$node->hosts_site()) { continue; }
		$why = StagedRolloutRunner::node_refusal($node);
		$label = $node->get('mgn_name') . ' — site ' . ($node->get('mgn_joinery_version') ?: 'version unknown')
			. ', agent ' . (AgentVocabulary::version($node) ?: 'unknown');
		if ($why !== null) {
			echo '<div class="small text-muted mb-2">' . htmlspecialchars($label) . ': ' . htmlspecialchars($why) . '</div>';
			continue;
		}
		$any = true;
		$fw->numberinput('order_' . $node->key, $label, ['min' => 1, 'max' => 999]);
	}
	if ($any) {
		$fw->submitbutton('btn_start', 'Start rollout');
	} else {
		echo '<p class="text-muted">No node can take a rollout right now.</p>';
	}
	$fw->end_form();
	$page->end_box();
}

$page->begin_box(['title' => 'Recent rollouts']);
$recent = new MultiStagedRollout(array('deleted' => false), array('srl_staged_rollout_id' => 'DESC'), 10);
$shown = 0;
foreach ($recent as $r) {
	if ($running && (int)$r->key === (int)$running->key) { continue; }
	$render_rollout($r);
	$shown++;
}
if (!$shown) {
	echo '<p class="text-muted mb-0">None yet.</p>';
}
$page->end_box();
$page->admin_footer();
