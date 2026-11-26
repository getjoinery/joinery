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
 * @version 1.14 - the answer: one line per fact; security fixes counted as installed, not 'fully up to date' (a restart
 *                owed or a minor update tonight is not a missing fix); why each restart waits folded
 * @version 1.13 - a machine owing a reboot is listed as rebooting by itself (RebootWhenQuietRunner: why it waits), or
 *                as one to reboot by hand when set to never or refused
 * @version 1.12 - one answer first: whether anything needs a person, and what (FleetSecurityView::summary()); the fixes
 *                Ubuntu has not shipped each with a plain status; every machine's row in short words, the detail folded;
 *                the update run below, its last run folded
 * @version 1.11 - Made and a replaced withdrawal are read from the channel and the release's fixes, right on a mirror too (B8, B11)
 * @version 1.10 - a withdrawal a later build replaced is not listed (B8)
 * @version 1.9 - fixes waiting for a release upstream has not put out are listed apart, as nothing to do
 * @version 1.8 - Try again stays offered until a build above the withdrawn one exists
 * @version 1.7 - saved messages are shown once, by the admin header (they were shown twice)
 * @version 1.6 - Try again on a withdrawn fix build (server_manager_package_fix_retry) and why it was withdrawn; each
 *                stopped fix says why in plain words, the raw detail folded under it
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
	} elseif ($action === 'retry_fix') {
		$source = (string)($_POST['source'] ?? '');
		$version = (string)($_POST['version'] ?? '');
		if (preg_match('/^[a-z0-9][a-z0-9.+-]+$/', $source) && preg_match('/^([0-9]+:)?[0-9][A-Za-z0-9.+~-]*\+joinery[0-9]+$/', $version)) {
			$asked = json_decode((string)Globalvars::get_instance()->get_setting(PackageFixAuto::RETRY_SETTING), true);
			$asked = is_array($asked) ? $asked : array();
			$asked[$source] = $version;
			Setting::put(PackageFixAuto::RETRY_SETTING, json_encode($asked, JSON_UNESCAPED_SLASHES));
			$flash('Trying ' . IncidentSourceFixAheadOfUbuntu::package_name($source) . ' again: within ten minutes it is built afresh, '
				. 'and the new build goes to the test servers first. An email says how it went.', 'ok');
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


$page->begin_box(array());

$sum = FleetSecurityView::summary($rows);
$node_link = function ($r) use ($h) {
	return '<a href="/admin/server_manager/node_detail?mgn_managed_node_id=' . (int)$r['id'] . '">' . $h($r['name']) . '</a>';
};
$names = function (array $list) use ($node_link) {
	return implode(', ', array_map(function ($e) use ($node_link) { return $node_link($e[0]); }, $list));
};

// The runner's record and the channel: what the answer says of fixes Ubuntu has not shipped.
$pf_role = PackageFixAuto::role();
$pf_channel = PackageFixChannel::read(PackageFixChannel::dir(PathHelper::getSiteRoot()));
$pf = ($pf_role !== 'none' || $pf_channel !== null) ? PackageFixAuto::state() : array();
$pf_people = array();
$pf_upstream = array();
foreach ((array)($pf['attempts'] ?? array()) as $key => $a) {
	$src = strtok($key, ' ');
	$cve = substr($key, strlen($src) + 1);
	if (($a['status'] ?? '') === 'needs_person') {
		$pf_people[$src][] = array($cve, PackageFixAuto::plainReason((string)($a['step'] ?? ''), (string)($a['reason'] ?? '')), (string)($a['reason'] ?? ''));
	} elseif (($a['status'] ?? '') === 'waiting_release') {
		$pf_upstream[$src . ' ' . (string)($a['release'] ?? '')][] = $cve;
	}
}

// --- The answer: is everything patched, and is anything mine to do ------------
// One line per fact, and only the facts that apply. A restart still owed and a
// minor update due tonight are handled by themselves: they never make the
// answer amber, and the per-machine detail of why a restart waits is folded.
$tone = $sum['now'] || $pf_people ? 'danger' : ($sum['look'] ? 'warning' : 'success');
if ($sum['now'] || $pf_people) {
	$parts = array();
	if ($sum['now']) { $parts[] = count($sum['now']) . ' machine' . (count($sum['now']) === 1 ? '' : 's'); }
	if ($pf_people) { $parts[] = count($pf_people) . ' security fix build' . (count($pf_people) === 1 ? '' : 's'); }
	$headline = implode(' and ', $parts) . ' need' . (count($sum['now']) + count($pf_people) === 1 ? 's' : '') . ' you.';
} elseif ($sum['look']) {
	$headline = count($sum['look']) . ' machine' . (count($sum['look']) === 1 ? '' : 's') . ' cannot be judged right now.';
} else {
	$headline = 'Nothing needs you.';
}
$watched = $sum['total'] - count($sum['unseen']);
// A machine that cannot be judged is not counted as missing a fix.
$ids_of = function (array $list) { return array_map(function ($e) { return (int)$e[0]['id']; }, $list); };
$missing = count(array_unique($ids_of($sum['now'])));
$unjudged = count(array_diff(array_unique($ids_of($sum['look'])), $ids_of($sum['now'])));
$patched = $watched - $missing - $unjudged;
$list_style = 'padding-left:1.25rem;list-style:disc;';
// The theme's alert lays its children in a row: one child holds them all.
echo '<div class="alert alert-' . $tone . ' mb-3"><div style="flex:1;">';
echo '<div class="fw-semibold mb-2" style="font-size:1.15rem;">' . $h($headline) . '</div>';
echo '<ul class="mb-0" style="' . $list_style . '">';

// The machines that cannot be judged have their own line; counted here only
// against those that can.
$judged = $watched - $unjudged;
if ($judged > 0) {
	$fixes_line = $patched === $judged
		? 'Security fixes installed on ' . ($unjudged ? 'every machine that can be judged (' . $judged . ')' : 'all ' . $judged . ' machine' . ($judged === 1 ? '' : 's')) . '.'
		: 'Security fixes installed on ' . $patched . ' of ' . $judged . ' machine' . ($judged === 1 ? '' : 's') . ($unjudged ? ' that can be judged' : '') . '.';
	echo '<li>' . $h($fixes_line) . '</li>';
}
if ($sum['now']) {
	echo '<li><strong>Needs you:</strong> ';
	echo implode('; ', array_map(function ($e) use ($node_link, $h) { return $node_link($e[0]) . ' (' . $h(implode('; ', $e[1])) . ')'; }, $sum['now']));
	echo '. Open the machine; <em>Install security updates now</em> fixes most of these.</li>';
}
if ($pf_people) {
	echo '<li><strong>A fix needs a person:</strong> ' . $h(implode(', ', array_map(function ($src, $list) {
			return IncidentSourceFixAheadOfUbuntu::package_name($src) . ' (' . count($list) . ')';
		}, array_keys($pf_people), $pf_people)))
		. '. Ubuntu has not shipped it and our automatic build could not be made; details below.</li>';
}
if ($sum['look']) {
	// Under the headline that already says so, the machines alone.
	echo '<li>' . (($sum['now'] || $pf_people) ? '<strong>Cannot be judged right now:</strong> ' : '');
	echo implode('; ', array_map(function ($e) use ($node_link, $h) { return $node_link($e[0]) . ' (' . $h(implode('; ', $e[1])) . ')'; }, $sum['look']));
	echo '. This usually settles within the hour.</li>';
}
if ($sum['reboot']) {
	// Each restarts by itself (RebootWhenQuietRunner) unless set to never or refused.
	$by_itself = array();
	$by_hand = array();
	foreach ($sum['reboot'] as $e) {
		$n = new ManagedNode((int)$e[0]['id'], TRUE);
		$why = RebootWhenQuietRunner::waiting_words($n);
		if (RebootWhenQuietRunner::mode($n) === 'never' || JobCommandBuilder::reboot_refusal($n) !== null) {
			$by_hand[] = array($e[0], $why);
		} else {
			$by_itself[] = array($e[0], $why);
		}
	}
	if ($by_itself) {
		echo '<li>Restart' . (count($by_itself) === 1 ? 's' : '') . ' by ' . (count($by_itself) === 1 ? 'itself' : 'themselves')
			. ' to finish installed updates (when nobody is using ' . (count($by_itself) === 1 ? 'it' : 'them') . ', or 04:00-05:00 UTC): '
			. $names($by_itself) . '.'
			. '<details class="small"><summary>Why each is still waiting</summary><ul class="mb-0" style="' . $list_style . '">';
		foreach ($by_itself as $e) { echo '<li>' . $node_link($e[0]) . ': ' . $h($e[1]) . '</li>'; }
		echo '</ul></details></li>';
	}
	if ($by_hand) {
		echo '<li><strong>Restart when convenient</strong> (the machine\'s page has a Reboot button): ';
		echo implode('; ', array_map(function ($e) use ($node_link, $h) { return $node_link($e[0]) . ' (' . $h($e[1]) . ')'; }, $by_hand));
		echo '.</li>';
	}
}
if ($sum['wait']) {
	echo '<li>Minor updates install tonight on ' . (count($sum['wait']) === $watched ? 'every machine' : $names($sum['wait'])) . '.</li>';
}
if ($pf_upstream) {
	echo '<li>Waiting for a release upstream has not put out yet, then built and sent out automatically: ' . $h(implode(', ', array_map(function ($k) {
			$src = strtok($k, ' ');
			return IncidentSourceFixAheadOfUbuntu::package_name($src) . ' ' . substr($k, strlen($src) + 1);
		}, array_keys($pf_upstream)))) . '.</li>';
}
echo '</ul>';
if ($sum['unseen']) {
	echo '<div class="mt-2 small text-muted">Not watched from here (no agent): ' . $names($sum['unseen']) . '.</div>';
}
echo '</div></div>';

// --- Our own builds of fixes Ubuntu has not shipped ---------------------------
if ($pf_role !== 'none' || $pf_channel !== null) {
	echo '<h3 class="mt-4 mb-1" style="font-size:1.05rem;">Security fixes Ubuntu has not shipped yet</h3>';
	echo '<p class="small text-muted mb-2">When a project we run fixes a serious hole before Ubuntu does, we build the fixed package ourselves. '
		. 'It goes to the test servers first, then to every server once they have stayed healthy for ' . (int)PackageFixAuto::soakHours() . ' hours. '
		. 'When Ubuntu ships the fix, servers go back to Ubuntu\'s package.'
		. ($pf_role === 'mirror' ? ' This site passes on what its upstream has sent to every server.' : '') . '</p>';
	$soaking = (array)($pf['soak']['sources'] ?? array());
	$fix_rows = array();
	if ($pf_channel !== null) {
		$pf_tree = PackageFixPublisher::readStamp(PathHelper::getSiteRoot() . '/' . PackageFixPublisher::SOURCE_SUBDIR);
		foreach ($pf_channel['lines'] as $source => $l) {
			$testing = (isset($soaking[$source]) && $soaking[$source] === $l['version']) || !$pf_channel['wide'];
			$fix_rows[] = array('source' => $source, 'cves' => preg_split('/\s+/', trim((string)$l['cves']), -1, PREG_SPLIT_NO_EMPTY),
				'state' => $testing ? 'On the test servers' . (!empty($pf['soak']['started']) ? ' since ' . $ago((int)$pf['soak']['started']) : '') . '; every server next' : 'On every server',
				'tone' => $testing ? 'info' : 'success', 'line' => $l,
				'made' => PackageFixAuto::madeAutomatically($pf_tree, $source, (string)$l['version']) ? 'built automatically' : 'built by a person');
		}
	}
	foreach ($pf_upstream as $k => $cves) {
		$src = strtok($k, ' ');
		$fix_rows[] = array('source' => $src, 'cves' => $cves, 'state' => 'Waiting for ' . IncidentSourceFixAheadOfUbuntu::package_name($src) . ' '
			. substr($k, strlen($src) + 1) . ' to be released; built automatically then', 'tone' => 'secondary', 'line' => null, 'made' => '');
	}
	foreach ($pf_people as $src => $list) {
		$fix_rows[] = array('source' => $src, 'cves' => array_column($list, 0), 'state' => 'A person is needed', 'tone' => 'danger', 'line' => null, 'made' => '', 'people' => $list);
	}
	if (!$fix_rows) {
		echo '<div class="small mb-3">None: every serious fix the projects have published is in Ubuntu\'s own packages.</div>';
	} else {
		echo '<div class="table-responsive"><table class="table table-sm align-middle mb-2"><thead><tr><th>Package</th><th>Status</th><th>Fixes</th><th></th></tr></thead><tbody>';
		foreach ($fix_rows as $fr) {
			$n = count($fr['cves']);
			echo '<tr><td>' . $h(IncidentSourceFixAheadOfUbuntu::package_name($fr['source']))
				. ($fr['line'] ? '<br><small class="text-muted">' . $h($fr['line']['version']) . ', ' . $h($fr['made']) . '</small>' : '') . '</td>'
				. '<td><span class="badge bg-' . $fr['tone'] . '">' . $h($fr['state']) . '</span>';
			if (!empty($fr['people'])) {
				$why = array();
				foreach ($fr['people'] as $p) { $why[$p[1]][] = $p; }
				foreach ($why as $reason => $ps) {
					echo '<div class="small text-danger mt-1">' . $h($reason) . ' (' . count($ps) . ')';
					echo ' <details class="d-inline"><summary class="d-inline">detail</summary><code class="small d-block">'
						. $h(implode("\n", array_map(function ($p) { return $p[0] . ': ' . $p[2]; }, $ps))) . '</code></details></div>';
				}
			}
			echo '</td><td class="small"><details><summary>' . $n . ' fix' . ($n === 1 ? '' : 'es') . '</summary>' . $h(implode(', ', $fr['cves'])) . '</details></td><td>';
			if ($fr['line'] && $pf_role === 'issuer') {
				$fid = 'withdraw-' . preg_replace('/[^a-z0-9]/', '-', $fr['source']);
				echo '<form id="' . $fid . '" method="post" action="' . $self_url . '" class="m-0">' . SmAdminCsrf::field()
					. '<input type="hidden" name="action" value="withdraw_fix"><input type="hidden" name="source" value="' . $h($fr['source']) . '">'
					. '<input type="hidden" name="version" value="' . $h($fr['line']['version']) . '">'
					. '<button type="button" class="btn btn-sm btn-outline-danger" data-jy-confirm="' . $h('Withdraw ' . $fr['line']['version']
						. '? Every machine holding it goes back to the build before it, and the fixes it carries are missing again until another build or Ubuntu brings them.')
					. '" data-jy-submit="' . $fid . '">Withdraw</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
	if ($pf_channel !== null) {
		foreach ($pf_channel['withdrawn'] as $source => $versions) {
			if (PackageFixAuto::withdrawalSuperseded($pf_channel, $source)) { continue; }
			$why = '';
			foreach (array_reverse((array)($pf['withdrawals'] ?? array())) as $w) {
				if (($w['source'] ?? '') === $source) { $why = (string)($w['why'] ?? ''); break; }
			}
			$last = (string)end($versions);
			// Not offered while a press waits for the runner (a build above it hides the line).
			$pending = json_decode((string)Globalvars::get_instance()->get_setting(PackageFixAuto::RETRY_SETTING), true);
			$retry_asked = is_array($pending) && ($pending[$source] ?? null) === $last;
			echo '<div class="small text-muted d-flex gap-2 align-items-center mb-2">Withdrawn: ' . $h(IncidentSourceFixAheadOfUbuntu::package_name($source) . ' ' . implode(', ', $versions))
				. ($why !== '' ? ' (' . $h($why) . ')' : '');
			if ($pf_role === 'issuer' && !$retry_asked) {
				$fid = 'retry-' . preg_replace('/[^a-z0-9]/', '-', $source);
				echo '<form id="' . $fid . '" method="post" action="' . $self_url . '" class="m-0">' . SmAdminCsrf::field()
					. '<input type="hidden" name="action" value="retry_fix"><input type="hidden" name="source" value="' . $h($source) . '">'
					. '<input type="hidden" name="version" value="' . $h($last) . '">'
					. '<button type="button" class="btn btn-sm btn-outline-secondary" data-jy-confirm="' . $h('Build '
						. IncidentSourceFixAheadOfUbuntu::package_name($source) . ' again and send it to the test servers? Use this when the problem that pulled '
						. $last . ' back had nothing to do with it.') . '" data-jy-submit="' . $fid . '">Try again</button></form>';
			}
			echo '</div>';
		}
	}
	if (!empty($pf['last_run'])) {
		echo '<div class="small text-muted mb-3">The automatic builder last ran ' . $h($ago((int)$pf['last_run'])) . ': ' . $h($pf['last_message'] ?? '') . '</div>';
	}
}

// --- Every machine -------------------------------------------------------------
// A row's word: the most a person is asked, of its issues' kinds.
$word = array('now' => 'Needs you', 'look' => 'Cannot be judged', 'reboot' => 'Reboot owed', 'wait' => 'Finishes tonight', 'unseen' => 'Not seen');
?>
<h3 class="mt-4 mb-1" style="font-size:1.05rem;">Every machine and site</h3>
<div class="table-responsive">
<table class="table table-sm align-middle">
	<thead><tr><th>Machine</th><th>Status</th><th>Checked for fixes</th><th>Updates last ran</th></tr></thead>
	<tbody>
	<?php foreach ($rows as $r):
		$badge = array('red' => 'danger', 'amber' => 'warning', 'grey' => 'secondary', 'green' => 'success')[$r['verdict']];
		$label = 'Up to date';
		foreach ($word as $k => $w) {
			if (in_array($k, array_column($r['issues'], 'kind'), true)) { $label = $w; break; }
		} ?>
		<tr>
			<td><?php echo $node_link($r); ?>
				<?php if ($r['kind'] === 'container'): ?><br><small class="text-muted">site on <?php echo $h($r['host_name'] !== '' ? $r['host_name'] : 'a host'); ?></small>
				<?php elseif ($r['os'] !== ''): ?><br><small class="text-muted"><?php echo $h($r['os']); ?></small><?php endif; ?></td>
			<td><span class="badge bg-<?php echo $badge; ?>"><?php echo $h($label); ?></span>
				<?php if ($r['issues']): ?><div class="small<?php echo $r['verdict'] === 'red' ? ' text-danger' : ''; ?>"><?php echo $h(implode('; ', array_column($r['issues'], 'text'))); ?></div><?php endif; ?>
				<?php if ($r['reasons'] || $r['package_fixes'] || $r['php']): ?><details class="small text-muted"><summary>detail</summary>
					<?php foreach (array_merge($r['reasons'], $r['package_fixes']) as $line): ?><div><?php echo $h($line); ?></div><?php endforeach; ?>
					<?php if ($r['php']): ?><div>PHP: <?php echo $h(implode(', ', $r['php'])); ?></div><?php endif; ?>
					<?php if ($r['packages']): ?><div>Waiting: <?php echo $h(implode(', ', array_slice($r['packages'], 0, 40))); ?></div><?php endif; ?>
				</details><?php endif; ?></td>
			<td class="small"><?php echo $r['checked_at'] !== '' ? $h($ago(strtotime($r['checked_at']))) : '<span class="text-muted">never</span>'; ?></td>
			<td class="small"><?php echo $h($ago($r['updated_at'])); ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
</div>

<?php
// --- Install security updates everywhere: the button, and the last run folded ---
echo '<h3 class="mt-4 mb-1" style="font-size:1.05rem;">Install security updates now</h3>';
echo '<p class="small text-muted mb-2">Every machine installs critical fixes within the hour and the rest each night by itself. '
	. 'This installs everything waiting now, one machine at a time.</p>';
if (!$latest || !$latest->is_running()) {
	$plan = FleetSecurityRunner::plan();
	$ask = 'Install the security updates each machine and site container would take at its next nightly run, now, one at a time ('
		. count($plan['nodes']) . ' in all)? A service whose package is updated restarts for a few seconds, and PHP is reloaded without dropping a request.'
		. ' A container host replaces its site containers on a fresh base, each site down about 12 seconds.'
		. ' Nothing is rebooted. The run stops at the first failure.';
	echo '<form id="fleetSecurityStart" method="post" action="' . $self_url . '" class="mb-2">' . SmAdminCsrf::field()
		. '<input type="hidden" name="action" value="start_run">'
		. '<button type="button" class="btn btn-sm btn-primary" data-jy-confirm="' . $h($ask) . '" data-jy-submit="fleetSecurityStart"'
		. ($plan['nodes'] ? '' : ' disabled') . '>Install security updates everywhere</button>';
	echo '</form>';
	if ($plan['left_out']) {
		echo '<details class="small text-muted mb-2"><summary>' . count($plan['left_out']) . ' left out of a run</summary><ul class="mb-0" style="padding-left:1.25rem;list-style:disc;">';
		foreach ($plan['left_out'] as $l) {
			echo '<li><strong>' . $h($l['name']) . '</strong>: ' . $h($l['reason']) . '</li>';
		}
		echo '</ul></details>';
	}
}
if ($latest) {
	$st = (string)$latest->get('fsr_status');
	$st_words = array('running' => 'under way', 'completed' => 'finished', 'halted' => 'stopped at a failure', 'stopped' => 'stopped by hand');
	$open = $latest->is_running() || $st === 'halted';
	echo '<details class="mb-2"' . ($open ? ' open' : '') . '><summary class="small">Last run: ' . $h($st_words[$st] ?? $st)
		. ', started ' . $h(LibraryFunctions::time_ago((string)$latest->get('fsr_create_time'), $tz)) . '</summary>';
	if ((string)$latest->get('fsr_halt_reason') !== '' && $st === 'halted') {
		echo '<div class="text-danger small mt-1">' . $h($latest->get('fsr_halt_reason')) . '</div>';
	}
	echo '<div class="table-responsive"><table class="table table-sm mt-2 mb-0"><thead><tr><th>Machine</th><th>State</th><th>Outcome</th><th>Changed</th><th>Reloaded</th><th>Note</th></tr></thead><tbody>';
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
	echo '</details>';
}
$page->end_box();
$page->admin_footer();
