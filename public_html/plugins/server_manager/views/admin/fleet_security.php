<?php
/**
 * Server Manager - Fleet security
 * URL: /admin/server_manager/fleet_security
 *
 * Every machine and site container, and whether it is patched
 * (specs/fleet_security_patching.md WP2): the PHP it runs and whether old
 * code still serves, the security fixes waiting, when updates last ran, and a
 * reboot owed (FleetSecurityView). Red rows first.
 *
 * Install security updates everywhere (WP5): every node's own updates, one
 * machine or site container at a time, machines first and each container
 * after its host, stopping at the first whose updater failed
 * (FleetSecurityRunner). The AdvanceFleetSecurityRuns task moves a run;
 * loading this page moves it too.
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

$session = SessionControl::get_instance();
$session->check_permission(10);
$session->set_return();

$page_regex = '/\/admin\/server_manager/';
$self_url = '/admin/server_manager/fleet_security';

$flash = function ($text, $type) use ($session, $page_regex) {
	$session->save_message(new DisplayMessage($text, $type === 'error' ? 'Error' : 'Fleet security', $page_regex,
		$type === 'error' ? DisplayMessage::MESSAGE_ERROR : DisplayMessage::MESSAGE_ANNOUNCEMENT,
		DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
};

if ($_POST) {
	if (!SmAdminCsrf::valid()) { header('Location: ' . $self_url); exit; }
	$action = (string)($_POST['action'] ?? '');
	if ($action === 'start_run') {
		try {
			$r = FleetSecurityRunner::start($session->get_user_id());
			$flash('Installing security updates on ' . count($r->steps()) . ' machine(s) and site container(s), one at a time.', 'ok');
		} catch (FleetSecurityRunException $e) {
			$flash($e->getMessage(), 'error');
		}
	} elseif ($action === 'stop_run') {
		$running = FleetSecurityRun::running();
		if ($running && (int)$running->key === (int)($_POST['run_id'] ?? 0)) {
			FleetSecurityRunner::stop($running, $session->get_user_id());
			$flash('Stopped. Updates already running on a node finish; nothing further is queued.', 'ok');
		}
	}
	header('Location: ' . $self_url);
	exit;
}

// Loading the page is a tick: a run moves even if the scheduler is slow.
$running = FleetSecurityRun::running();
if ($running) {
	FleetSecurityRunner::advance($running);
}
$latest = FleetSecurityRun::latest();

$now = time();
$rows = FleetSecurityView::rows(FleetSecurityView::snapshots(), $now);
$counts = array('red' => 0, 'amber' => 0, 'grey' => 0, 'green' => 0);
foreach ($rows as $r) { $counts[$r['verdict']]++; }

$tz = $session->get_timezone();
$ago = function ($unix) use ($tz) {
	return is_int($unix) && $unix > 0 ? LibraryFunctions::time_ago(gmdate('Y-m-d H:i:s', $unix), $tz) : '';
};
$h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };

$page = new AdminPage();
$page->admin_header([
	'menu-id' => 'server-manager',
	'page_title' => 'Fleet security',
	'readable_title' => 'Fleet security',
	'breadcrumbs' => ['Server Manager' => '/admin/server_manager', 'Fleet security' => ''],
	'session' => $session,
]);

$display_messages = $session->get_messages('/admin/server_manager');
foreach ($display_messages as $msg) {
	$cls = ($msg->display_type == DisplayMessage::MESSAGE_ERROR) ? 'alert-danger' : 'alert-success';
	echo '<div class="alert ' . $cls . '" role="alert">' . $h($msg->message)
		. '<button type="button" class="alert-close" aria-label="Close">&times;</button></div>';
}
$session->mark_shown($display_messages);

$page->begin_box(array());
?>
<p>Every machine and site container, and whether it is patched. A machine looks for the security fixes it is waiting for
every hour; a site container's operating system is its host's base image, whose fixes the host looks for. <strong>Red</strong>:
old code is still serving, or a critical fix waits (PHP, Apache, OpenSSL, PostgreSQL, the kernel). <strong>Amber</strong>:
other fixes wait for the next run, a kernel fix waits for the night and then a reboot, a reboot is owed for over a day, or no check has passed in two hours.</p>

<p>
	<span class="badge bg-danger"><?php echo (int)$counts['red']; ?> red</span>
	<span class="badge bg-warning"><?php echo (int)$counts['amber']; ?> amber</span>
	<span class="badge bg-success"><?php echo (int)$counts['green']; ?> green</span>
	<?php if ($counts['grey']): ?><span class="badge bg-secondary"><?php echo (int)$counts['grey']; ?> not reported</span><?php endif; ?>
</p>

<?php
// The run: under way, or the last one.
if ($latest) {
	$st = (string)$latest->get('fsr_status');
	$st_words = array('running' => 'under way', 'completed' => 'finished', 'halted' => 'stopped at a failure', 'stopped' => 'stopped by hand');
	echo '<div class="border rounded p-3 mb-3">';
	echo '<div><strong>Install security updates everywhere</strong>: ' . $h($st_words[$st] ?? $st)
		. ' <span class="text-muted small">(started ' . $h(LibraryFunctions::time_ago((string)$latest->get('fsr_create_time'), $tz)) . ')</span></div>';
	if ((string)$latest->get('fsr_halt_reason') !== '' && $st === 'halted') {
		echo '<div class="text-danger small mt-1">' . $h($latest->get('fsr_halt_reason')) . '</div>';
	}
	echo '<div class="table-responsive"><table class="table table-sm mt-2 mb-0"><thead><tr><th>Node</th><th>State</th><th>Outcome</th><th>Changed</th><th>Reloaded</th><th>Note</th></tr></thead><tbody>';
	foreach ($latest->steps() as $s) {
		$v = (string)($s['verdict'] ?? '');
		$badge = array('pending' => 'secondary', 'running' => 'info', 'done' => 'success', 'failed' => 'danger', 'skipped' => 'warning')[$v] ?? 'secondary';
		$note = (string)($s['reason'] ?? '');
		if (!empty($s['reboot_required'])) { $note = trim($note . ($note !== '' ? '; ' : '') . 'a reboot is owed'); }
		echo '<tr><td><a href="/admin/server_manager/node_detail?mgn_managed_node_id=' . (int)$s['node_id'] . '">' . $h($s['name']) . '</a></td>'
			. '<td><span class="badge bg-' . $badge . '">' . $h($v) . '</span>'
			. (!empty($s['job_id']) ? ' <a class="small" href="/admin/server_manager/job_detail?job_id=' . (int)$s['job_id'] . '">job</a>' : '') . '</td>'
			. '<td>' . $h(str_replace('_', ' ', (string)($s['outcome'] ?? ''))) . '</td>'
			. '<td>' . (is_int($s['changed'] ?? null) ? (int)$s['changed'] : '') . '</td>'
			. '<td>' . $h(implode(', ', (array)($s['reloaded'] ?? array()))) . '</td>'
			. '<td class="small' . (!empty($s['still_on_old_code']) || $v === 'failed' ? ' text-danger' : '') . '">' . $h($note) . '</td></tr>';
	}
	echo '</tbody></table></div>';
	if ($latest->is_running()) {
		echo '<form method="post" action="' . $self_url . '" class="mt-2">' . SmAdminCsrf::field()
			. '<input type="hidden" name="action" value="stop_run"><input type="hidden" name="run_id" value="' . (int)$latest->key . '">'
			. '<button type="submit" class="btn btn-sm btn-outline-danger">Stop after the node in hand</button></form>';
	}
	echo '</div>';
}

if (!$latest || !$latest->is_running()) {
	$plan = FleetSecurityRunner::plan();
	$ask = 'Install the security updates each machine and site container would take at its next nightly run, now, one at a time ('
		. count($plan['nodes']) . ' in all)? A service whose package is updated restarts for a few seconds, and PHP is reloaded without dropping a request.'
		. ' Nothing is rebooted. The run stops at the first node whose updater fails.';
	echo '<form id="fleetSecurityStart" method="post" action="' . $self_url . '" class="mb-3">' . SmAdminCsrf::field()
		. '<input type="hidden" name="action" value="start_run">'
		. '<button type="button" class="btn btn-sm btn-primary" onclick="JoineryModal.confirm(' . $h(json_encode($ask))
		. ', function(){ document.getElementById(\'fleetSecurityStart\').submit(); })"'
		. ($plan['nodes'] ? '' : ' disabled') . '>Install security updates everywhere</button>';
	echo '</form>';
	if ($plan['left_out']) {
		echo '<details class="small text-muted mb-3"><summary style="cursor:pointer;">' . count($plan['left_out']) . ' left out of a run</summary><ul class="mb-0">';
		foreach ($plan['left_out'] as $l) {
			echo '<li><strong>' . $h($l['name']) . '</strong>: ' . $h($l['reason']) . '</li>';
		}
		echo '</ul></details>';
	}
}
?>

<div class="table-responsive">
<table class="table table-sm align-middle">
	<thead><tr>
		<th></th><th>Node</th><th>OS</th><th>PHP</th><th>Code serving</th><th>Fixes waiting</th><th>Updates ran</th><th>Reboot</th>
	</tr></thead>
	<tbody>
	<?php foreach ($rows as $r):
		$badge = array('red' => 'danger', 'amber' => 'warning', 'grey' => 'secondary', 'green' => 'success')[$r['verdict']]; ?>
		<tr>
			<td><span class="badge bg-<?php echo $badge; ?>" title="<?php echo $h(implode('; ', $r['reasons'])); ?>">&bull;</span></td>
			<td><a href="/admin/server_manager/node_detail?mgn_managed_node_id=<?php echo (int)$r['id']; ?>"><?php echo $h($r['name']); ?></a>
				<?php if ($r['kind'] === 'container'): ?><br><small class="text-muted">container<?php echo $r['host_name'] !== '' ? ' on ' . $h($r['host_name']) : ''; ?></small><?php endif; ?>
				<?php if ($r['reasons'] && $r['verdict'] !== 'green'): ?><br><small class="<?php echo $r['verdict'] === 'red' ? 'text-danger' : 'text-muted'; ?>"><?php echo $h(implode('; ', $r['reasons'])); ?></small><?php endif; ?></td>
			<td class="small"><?php echo $h($r['os']); ?></td>
			<td class="small"><?php echo $h(implode(', ', $r['php'])); ?></td>
			<td><?php if ($r['code'] === 'old'): ?><span class="badge bg-danger">old code</span>
				<?php elseif ($r['code'] === 'current'): ?><span class="small text-muted">current</span>
				<?php else: ?><span class="small text-muted">unknown</span><?php endif; ?></td>
			<td class="small"><?php if ($r['pending'] === null): ?><span class="text-muted">not checked</span>
				<?php else: ?><span title="<?php echo $h(implode(', ', array_slice($r['packages'], 0, 40))); ?>"><?php echo (int)$r['pending']; ?><?php echo $r['critical'] ? ' (' . count($r['critical']) . ' critical)' : ''; ?></span>
				<?php if ($r['checked_at'] !== ''): ?><br><span class="text-muted"><?php echo $h($ago(strtotime($r['checked_at']))); ?></span><?php endif; ?><?php endif; ?></td>
			<td class="small"><?php echo $h($ago($r['updated_at'])); ?></td>
			<td class="small"><?php echo $r['reboot'] !== null ? 'owed' . ($r['reboot'] > 0 ? ' since ' . $h($ago($r['reboot'])) : '') : ''; ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
</div>
<?php
$page->end_box();
$page->admin_footer();
