<?php
/**
 * Updates — what release this site runs, where it came from, what it has
 * installed, and, on a site no management node looks after, updating it.
 *
 * Served by the machine it describes, so it points rather than proves: it
 * names the public commit and the public log entry, and says how to check them
 * from somewhere else (spec release_transparency, D7). Anything installed with
 * Install anyway is listed apart, said plainly as not logged.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('adm/logic/admin_updates_logic.php'));

$page_vars = process_logic(admin_updates_logic(array_merge($_GET, $_POST)));

$session      = $page_vars['session'];
$managed      = $page_vars['managed'];
$manager_url  = $page_vars['manager_url'];
$origin       = $page_vars['origin'];
$version      = $page_vars['version'];
$running      = $page_vars['running'];
$log_required = $page_vars['log_required'];
$source       = $page_vars['source'];
$offered      = $page_vars['offered'];
$request_id   = $page_vars['request_id'];
$error        = $page_vars['error'];
$history      = $page_vars['history'];
$sideloaded   = $page_vars['sideloaded'];
$forks        = $page_vars['forks'];

/** A commit as a short link to its public page. */
function admin_updates_commit_link(string $repo, string $commit): string {
	$url = ReleaseProvenance::commit_url($repo, $commit);
	return $url === '' ? '' : '<a href="' . htmlspecialchars($url) . '" target="_blank" rel="noopener"><code>'
		. htmlspecialchars(substr($commit, 0, 8)) . '</code></a>';
}

$page = new AdminPage();
$page->admin_header(array(
	'menu-id'     => 'system-updates',
	'breadcrumbs' => array('System' => '', 'Updates' => ''),
	'session'     => $session,
));

if ($error !== '') {
	echo '<div class="alert alert-danger" role="alert">' . htmlspecialchars($error) . '</div>';
}

// ---- What this site runs ---------------------------------------------------
$page->begin_box(array('title' => 'What this site runs'));
if ($origin) {
	echo '<p>This site is where Joinery is written. It runs its own working copy of the code, not a release, '
		. 'and publishes the releases other sites install.</p>';
} elseif ($running !== null) {
	echo '<p>This site runs release <strong>' . htmlspecialchars($running['version']) . '</strong>, built from public commit '
		. admin_updates_commit_link(ReleaseProvenance::CORE_REPO, $running['core_commit'])
		. ($running['agent_commit'] !== '' ? ' (the agent from ' . admin_updates_commit_link(ReleaseProvenance::AGENT_REPO, $running['agent_commit']) . ')' : '')
		. ($running['log_index'] !== null
			? ' and written to the public log <code>' . htmlspecialchars($running['log_origin']) . '</code> as entry <code>'
				. (int)$running['log_index'] . '</code>'
			: '')
		. ($running['published_at'] !== '' ? ', published ' . htmlspecialchars(substr($running['published_at'], 0, 10)) : '')
		. '.</p>';
	if ($version !== '' && $version !== $running['version']) {
		echo '<div class="alert alert-warning" role="status">The code here says it is version ' . htmlspecialchars($version)
			. ', but its release statement is for ' . htmlspecialchars($running['version'])
			. '. The two should match after every update; the statement may be left over from an earlier release.</div>';
	}
	echo '<p class="text-muted">This page is served by the machine it describes, so it can point but not prove. '
		. 'To check this release from your own computer, clone <a href="' . htmlspecialchars(ReleaseProvenance::CORE_REPO)
		. '" target="_blank" rel="noopener">the public repository</a> and run <code>php utils/verify_release.php '
		. htmlspecialchars($running['version']) . ($source !== '' ? ' --source=' . htmlspecialchars(rtrim($source, '/')) : '')
		. '</code>. It rebuilds the release from the public commits, compares it with what was published, '
		. 'and checks its entry in the public log.</p>';
} else {
	echo '<p>This site runs version <strong>' . htmlspecialchars($version !== '' ? $version : 'unknown')
		. '</strong>, a release from before releases were written to a public log, so there is no log entry to point to.</p>';
}
if (!$origin) {
	echo '<p>' . ($log_required
		? 'This site installs only releases that are in the public log. A release that is not is refused, and nothing is changed.'
		: 'This site does not require releases to be in the public log: it installs any release signed with the Joinery release key.')
		. '</p>';
}
$page->end_box();

// ---- Updating --------------------------------------------------------------
$page->begin_box(array('title' => 'Update'));
if ($origin) {
	echo '<p>Releases are published from this site, so it has nothing to update from.</p>';
} elseif ($managed) {
	echo '<p>This site is looked after by a management node, <code>' . htmlspecialchars($manager_url)
		. '</code>, and updates are applied from there.</p>';
} else {
	echo '<table style="margin-bottom:1rem;border-collapse:collapse;">';
	foreach (array(
		'Running here'    => $version !== '' ? $version : 'unknown',
		'Upgrade source'  => $source !== '' ? $source : 'none set',
		'Available there' => $offered['version'] !== '' ? $offered['version'] : 'unknown',
	) as $label => $value) {
		echo '<tr><th style="text-align:left;padding:.25rem 1rem .25rem 0;font-weight:600;">' . htmlspecialchars($label)
			. '</th><td style="padding:.25rem 0;">' . htmlspecialchars((string)$value) . '</td></tr>';
	}
	echo '</table>';
	if ($offered['error'] !== '') {
		echo '<div class="alert alert-warning" role="status">' . htmlspecialchars($offered['error']) . '</div>';
	}

	// Every update goes through the root actor, so a machine without one
	// cannot update until it has one back. Said before the button.
	echo AdminPage::root_actor_notice();

	if ($request_id !== '') {
		echo '<p>This update is being carried out by the host converger. The transcript below is the same output '
			. 'an update run at the command line prints.</p>';
		echo AdminPage::root_request_panel($request_id);
		echo '<p style="margin-top:1rem;"><a class="jy-btn" href="/admin/admin_updates">Back</a></p>';
	} else {
		$same = ($version !== '' && $offered['version'] !== '' && $version === $offered['version']);
		if ($same) {
			echo '<p>This site runs the version its source offers. Updating again re-installs the same release, '
				. 'which is harmless and occasionally useful.</p>';
		}
		echo AdminPage::action_button(
			$same ? 'Re-install this version' : 'Update now',
			'/admin/admin_updates',
			array(
				'hidden'  => array('action' => 'queue_upgrade'),
				'class'   => 'btn btn-primary',
				'confirm' => $same
					? 'Re-install the version already running?'
					: 'Update this site to ' . ($offered['version'] !== '' ? $offered['version'] : 'the available version') . '?',
			));
		echo '<p style="margin-top:.75rem;" class="text-muted">The update runs as root on this machine. '
			. 'Each release is checked before anything is changed: it must be signed with the Joinery release key'
			. ($log_required ? ' and be in the public log' : '') . ', or it is refused.</p>';
	}
}
$page->end_box();

// ---- Install history -------------------------------------------------------
$outcome_words = array(
	ReleaseInstall::INSTALLED   => '<span class="badge bg-success">Installed</span>',
	ReleaseInstall::REFUSED     => '<span class="badge bg-danger">Refused</span>',
	ReleaseInstall::ROLLED_BACK => '<span class="badge bg-warning">Rolled back</span>',
	ReleaseInstall::STOPPED     => '<span class="badge bg-secondary">Stopped</span>',
);
if (count($history) === 0) {
	$page->begin_box(array('title' => 'Install history'));
	echo '<p class="text-muted">No updates recorded yet. Every update run on this site from now on is listed here: '
		. 'what it installed, or why it did not.</p>';
	$page->end_box();
} else {
	$page->tableheader(array('When', 'From', 'To', 'Outcome', 'Details'), array('title' => 'Install history'));
	foreach ($history as $row) {
		$outcome = (string)$row->get('rin_outcome');
		if ($outcome === ReleaseInstall::INSTALLED) {
			$details = $row->get('rin_core_commit')
				? 'Commit ' . admin_updates_commit_link(ReleaseProvenance::CORE_REPO, (string)$row->get('rin_core_commit'))
					. ($row->get('rin_log_index') !== null
						? ', log entry <code>' . (int)$row->get('rin_log_index') . '</code>'
						: '')
				: '<span class="text-muted">A release from before releases were logged.</span>';
		} else {
			$details = htmlspecialchars((string)$row->get('rin_detail'));
		}
		$page->disprow(array(
			htmlspecialchars($row->get_local('rin_create_time')),
			htmlspecialchars((string)$row->get('rin_from_version')),
			htmlspecialchars((string)$row->get('rin_to_version')),
			$outcome_words[$outcome] ?? htmlspecialchars($outcome),
			$details,
		));
	}
	$page->endtable();
}

// ---- Installed with Install anyway -----------------------------------------
if ($sideloaded === array()) {
	$page->begin_box(array('title' => 'Installed with Install anyway'));
	echo '<p class="text-muted">Nothing on this site was installed with Install anyway.</p>';
	$page->end_box();
} else {
	$page->tableheader(array('Name', 'Type', 'Version', 'Why it was refused', 'Approved by', 'When'),
		array('title' => 'Installed with Install anyway'));
	foreach ($sideloaded as $item) {
		$page->disprow(array(
			htmlspecialchars($item['name']),
			htmlspecialchars(ucfirst($item['type'])),
			htmlspecialchars($item['version']),
			$item['trust'] === 'unlogged' ? 'Signed by Joinery, but not in the public log' : 'Not signed by Joinery',
			htmlspecialchars($item['approval']['who'] ?? 'not recorded'),
			htmlspecialchars($item['approval']['when'] ?? ''),
		));
	}
	$page->endtable();
	echo '<p class="text-muted" style="margin-top:.5rem;">These were installed by an administrator of this site, past the warning, '
		. 'and are not in the public log. Nothing above about the release covers them.</p>';
}

// ---- Kept out of updates ---------------------------------------------------
if ($forks !== array()) {
	$page->tableheader(array('Name', 'Type', 'Version here', 'Version Joinery ships'), array('title' => 'Kept out of updates'));
	foreach ($forks as $fork) {
		$page->disprow(array(
			htmlspecialchars($fork['name']),
			htmlspecialchars(ucfirst($fork['type'])),
			htmlspecialchars($fork['version']),
			htmlspecialchars($fork['shipped'] !== '' ? $fork['shipped'] : 'unknown'),
		));
	}
	$page->endtable();
	echo '<p class="text-muted" style="margin-top:.5rem;">Local copies: updates leave them alone, so they get no fixes from Joinery, '
		. 'including security fixes. Allow upgrade on the <a href="/admin/admin_plugins">Plugins</a> or '
		. '<a href="/admin/admin_themes">Themes</a> page hands one back to updates.</p>';
}

$page->admin_footer();
