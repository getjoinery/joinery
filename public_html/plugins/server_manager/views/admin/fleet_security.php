<?php
/** @joinery-admin class=config */
/**
 * Server Manager - Fleet security
 * URL: /admin/server_manager/fleet_security
 *
 * Every machine and site container, and whether it is patched
 * (specs/fleet_security_patching.md WP2): the PHP it runs and whether old
 * code still serves, the security fixes waiting, when updates last ran, and a
 * reboot owed (FleetSecurityView). Red rows first.
 *
 * Install security updates everywhere (WP5): each machine's own updates, one
 * at a time; then each container host replaces its site containers on a fresh
 * base (its Refresh now, Q7); a site container whose host does not replace
 * them installs its own after its host. It stops at the first failure
 * (FleetSecurityRunner). The AdvanceFleetSecurityRuns task moves a run;
 * loading this page moves it too.
 *
 * @version 1.5 - Security fixes ahead of Ubuntu: the package fix channel, its soak, what the automatic runner did,
 *                and a Withdraw per fix (specs/package_fixes_automatic.md WP8, WP9)
 * @version 1.4 - a machine's package fixes ahead of Ubuntu are listed under its name (specs/package_fixes_ahead_of_ubuntu.md WP6)
 * @version 1.3 - the key names the amber for a service that does not serve the sites (B25)
 * @version 1.2 - the page-load tick is the server's own write (server_initiated_write): a page view may move a running run
 * @version 1.1 - a container host's step replaces its site containers and names them (Q7); the key says the kernel
 *                is amber, and a package run left half done (B17)
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

$session = SessionControl::get_instance();
$session->set_return();

$page_regex = '/\/admin\/server_manager/';
$self_url = '/admin/server_manager/fleet_security';

$flash = function ($text, $type) use ($session, $page_regex) {
	$session->save_message(new DisplayMessage($text, $type === 'error' ? 'Error' : 'Fleet security', $page_regex,
		$type === 'error' ? DisplayMessage::MESSAGE_ERROR : DisplayMessage::MESSAGE_ANNOUNCEMENT,
		DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
};

// Each action needs its own level (SmActionLevels), whatever the page's.
SmActionLevels::refuse_post('fleet_security', $session, $self_url);

if ($_POST) {
	if (!SmAdminCsrf::valid()) { header('Location: ' . $self_url); exit; }
	$action = (string)($_POST['action'] ?? '');
	if ($action === 'start_run') {
		try {
			$r = FleetSecurityRunner::start($session->get_user_id());
			$flash('Installing security updates in ' . count($r->steps()) . ' step(s), one at a time.', 'ok');
		} catch (FleetSecurityRunException $e) {
			$flash($e->getMessage(), 'error');
		}
	} elseif ($action === 'withdraw_fix') {
		$source = (string)($_POST['source'] ?? '');
		$version = (string)($_POST['version'] ?? '');
		if (preg_match('/^[a-z0-9][a-z0-9.+-]+$/', $source) && preg_match('/^([0-9]+:)?[0-9][A-Za-z0-9.+~-]*\+joinery[0-9]+$/', $version)) {
			$asked = json_decode((string)Globalvars::get_instance()->get_setting(PackageFixAuto::WITHDRAW_SETTING), true);
			$asked = is_array($asked) ? $asked : array();
			$asked[$source] = $version;
			Setting::put(PackageFixAuto::WITHDRAW_SETTING, json_encode($asked, JSON_UNESCAPED_SLASHES));
			$flash('Withdrawing ' . $source . ' ' . $version . ': within ten minutes every machine is told, and each one holding it '
				. 'goes back to the build before it within the hour.', 'ok');
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
// Lazy processing, the server's own write: it only moves a run a person
// already started (a POST), as the AdvanceFleetSecurityRuns task would.
$running = FleetSecurityRun::running();
if ($running) {
	SystemBase::server_initiated_write(function () use ($running) { FleetSecurityRunner::advance($running); });
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
old code is still serving, or a critical fix waits (PHP, Apache, OpenSSL, PostgreSQL). <strong>Amber</strong>:
other fixes wait for the next run, a kernel fix waits for the night and then a reboot, a reboot is owed for over a day, no check has passed in two hours,
a package run was left half done, or a service that does not serve the sites runs replaced code until it restarts.</p>

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
		$covers = (($s['word'] ?? '') === FleetSecurityRunner::REFRESH_WORD)
			? '<br><small class="text-muted">replaces its site containers: ' . $h(implode(', ', (array)($s['covers'] ?? array()))) . '</small>' : '';
		echo '<tr><td><a href="/admin/server_manager/node_detail?mgn_managed_node_id=' . (int)$s['node_id'] . '">' . $h($s['name']) . '</a>' . $covers . '</td>'
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
		. ' A container host replaces its site containers on a fresh base, each site down about 12 seconds.'
		. ' Nothing is rebooted. The run stops at the first failure.';
	echo '<form id="fleetSecurityStart" method="post" action="' . $self_url . '" class="mb-3">' . SmAdminCsrf::field()
		. '<input type="hidden" name="action" value="start_run">'
		. '<button type="button" class="btn btn-sm btn-primary" data-jy-confirm="' . $h($ask) . '" data-jy-submit="fleetSecurityStart"'
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

<?php
// Security fixes ahead of Ubuntu: the channel this site issues or mirrors, and the runner's record.
$pf_role = PackageFixAuto::role();
$pf_channel = PackageFixChannel::read(PackageFixChannel::dir(PathHelper::getSiteRoot()));
if ($pf_role !== 'none' || $pf_channel !== null) {
	$pf = PackageFixAuto::state();
	echo '<div class="border rounded p-3 mb-3"><div><strong>Security fixes ahead of Ubuntu</strong></div>';
	echo '<p class="small text-muted mb-2">When a project we run ships a critical or high security fix Ubuntu has not, our own build is made '
		. 'automatically, goes to the machines this site serves directly first, and to every machine once they have stayed healthy for '
		. (int)PackageFixAuto::soakHours() . ' hours. ' . ($pf_role === 'mirror' ? 'This site passes on each set once its upstream sends it to every machine.' : '')
		. ' Withdraw sends a build back: every machine holding it returns to the build before it.</p>';
	if ($pf_channel === null) {
		echo '<div class="small">No set has been issued yet; machines take the fixes their release names.</div>';
	} else {
		echo '<div class="small mb-2">Set ' . (int)$pf_channel['serial'] . ', ' . ($pf_channel['wide'] ? 'for every machine' : 'for the test machines first')
			. ($pf_channel['issued'] !== '' ? ', issued ' . $h($ago(strtotime($pf_channel['issued']))) : '') . '.</div>';
		if (!empty($pf['soak'])) {
			echo '<div class="small mb-2">On the test machines since ' . $h($ago((int)$pf['soak']['started'])) . ': '
				. $h(implode(', ', array_map(function ($s, $v) { return IncidentSourceFixAheadOfUbuntu::package_name($s) . ' ' . $v; },
					array_keys($pf['soak']['sources']), $pf['soak']['sources']))) . '.</div>';
		}
		if ($pf_channel['lines']) {
			echo '<div class="table-responsive"><table class="table table-sm mb-2"><thead><tr><th>Package</th><th>Our build</th><th>Fixes</th><th>Made</th><th></th></tr></thead><tbody>';
			foreach ($pf_channel['lines'] as $source => $l) {
				$auto = (($pf['automatic'][$source]['version'] ?? null) === $l['version']);
				$fid = 'withdraw-' . preg_replace('/[^a-z0-9]/', '-', $source);
				echo '<tr><td>' . $h(IncidentSourceFixAheadOfUbuntu::package_name($source)) . '</td><td class="small">' . $h($l['version']) . '</td>'
					. '<td class="small">' . $h($l['cves']) . '</td><td class="small">' . ($auto ? 'automatically' : 'by a person') . '</td><td>';
				if ($pf_role === 'issuer') {
					echo '<form id="' . $fid . '" method="post" action="' . $self_url . '" class="m-0">' . SmAdminCsrf::field()
						. '<input type="hidden" name="action" value="withdraw_fix"><input type="hidden" name="source" value="' . $h($source) . '">'
						. '<input type="hidden" name="version" value="' . $h($l['version']) . '">'
						. '<button type="button" class="btn btn-sm btn-outline-danger" data-jy-confirm="' . $h('Withdraw ' . $l['version']
							. '? Every machine holding it goes back to the build before it, and the fixes it carries are missing again until another build or Ubuntu brings them.')
						. '" data-jy-submit="' . $fid . '">Withdraw</button></form>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table></div>';
		}
		foreach ($pf_channel['withdrawn'] as $source => $versions) {
			echo '<div class="small text-muted">Withdrawn: ' . $h(IncidentSourceFixAheadOfUbuntu::package_name($source) . ' ' . implode(', ', $versions)) . '</div>';
		}
	}
	$pf_people = array();
	foreach ((array)($pf['attempts'] ?? array()) as $key => $a) {
		if (($a['status'] ?? '') === 'needs_person') {
			$pf_people[] = $key . ': stopped at ' . ($a['step'] ?? '?') . ' - ' . ($a['reason'] ?? '');
		}
	}
	if ($pf_people) {
		echo '<div class="small text-danger mt-2"><strong>A person is needed</strong><ul class="mb-0">';
		foreach (array_slice($pf_people, 0, 20) as $p) { echo '<li>' . $h($p) . '</li>'; }
		echo '</ul></div>';
	}
	if (!empty($pf['last_run'])) {
		echo '<div class="small text-muted mt-2">Last run ' . $h($ago((int)$pf['last_run'])) . ': ' . $h($pf['last_message'] ?? '') . '</div>';
	}
	echo '</div>';
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
				<?php if ($r['reasons'] && $r['verdict'] !== 'green'): ?><br><small class="<?php echo $r['verdict'] === 'red' ? 'text-danger' : 'text-muted'; ?>"><?php echo $h(implode('; ', $r['reasons'])); ?></small><?php endif; ?>
				<?php foreach ($r['package_fixes'] as $pf_words): ?><br><small class="text-muted"><?php echo $h($pf_words); ?></small><?php endforeach; ?></td>
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
