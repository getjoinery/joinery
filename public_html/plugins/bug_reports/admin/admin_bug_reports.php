<?php
/** @joinery-admin class=personal */
/**
 * Admin: Bug Reports — problem reports received from sites that upgrade from
 * this one.
 *
 * Two views. Grouped (the default) puts the same fault together, however many
 * sites reported it: how many sites and reports, how many times it happened,
 * how many need you, the newest version seen, when it was first and last
 * seen, and how it stands. Its tabs are an incident list's: Needs you,
 * Snoozed, Resolved, Ignored, All. Tick faults and apply Resolve, Ignore,
 * Reopen or a snooze to every report of each (POST). Every report lists them
 * one by one, filtered by host, version, verification or status.
 *
 * @version 1.3.0 - Resolve, Ignore, Snooze and Reopen on the ticked faults, with an incident list's tabs;
 *                  Mute, Unmute and Mark all seen are gone
 * @version 1.2.0 - Mute and Unmute on a group
 * @version 1.1.0 - groups by fingerprint; Times seen
 * @version 1.0.0
 */
$session = SessionControl::get_instance();
$session->set_return();
$self_url = '/plugins/bug_reports/admin/admin_bug_reports';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'triage') {
	// One UPDATE across every report of each fault: the reports' own write level.
	Access::demand(ReceivedBugReport::access_level('write'), $session);
	$changed = 0;
	try {
		foreach (array_unique(array_map('strval', (array)($_POST['groups'] ?? array()))) as $group) {
			$changed += ReceivedBugReport::triage_group($group, (string)($_POST['do'] ?? ''), (int)$session->get_user_id());
		}
	} catch (ReceivedBugReportException $e) {
		// A value the form never offers: nothing changed.
	}
	$back = (string)($_POST['back'] ?? '');
	header('Location: ' . (strpos($back, $self_url) === 0 ? $back : $self_url));
	exit;
}

$view = ($_GET['view'] ?? '') === 'all' ? 'all' : 'grouped';
$state = (string)($_GET['state'] ?? 'needs_you');
if (!isset(ReceivedBugReport::GROUP_VIEWS[$state])) {
	$state = 'needs_you';
}
$tz = $session->get_timezone();
$e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };

$page = new AdminPage();
$page->admin_header(array(
	'menu-id'        => 'bug-reports',
	'page_title'     => 'Bug Reports',
	'readable_title' => 'Bug Reports',
	'breadcrumbs'    => array('Bug Reports' => ''),
	'session'        => $session,
));

echo '<p>Sites that upgrade from this one send a report when a member reports an error, and, where their operator allows it, '
	. 'on their own when an unexpected error happens. An automatic report of an error already filed for the same site and version '
	. 'is not filed again; it adds to that report\'s count. '
	. 'Each report is checked by calling the site back: <strong>verified</strong> means it answered as the Joinery version it claimed, '
	. '<strong>version mismatch</strong> that it runs another version, and <strong>unverified</strong> that it did not answer as a Joinery site '
	. '(a site behind an IP allowlist answers that way too). Report contents come from another machine; treat them as untrusted.</p>';

echo '<p><a href="?view=grouped" class="btn btn-' . ($view === 'grouped' ? 'primary' : 'outline-secondary') . '">Grouped by error</a> '
	. '<a href="?view=all" class="btn btn-' . ($view === 'all' ? 'primary' : 'outline-secondary') . '">Every report</a></p>';

$verdicts = array(
	ReceivedBugReport::VERDICT_VERIFIED   => 'Verified',
	ReceivedBugReport::VERDICT_MISMATCH   => 'Version mismatch',
	ReceivedBugReport::VERDICT_UNVERIFIED => 'Unverified',
);

if ($view === 'grouped') {
	$counts = ReceivedBugReport::group_counts();
	echo '<ul class="nav nav-tabs flex-wrap mb-3">';
	foreach (ReceivedBugReport::GROUP_VIEWS as $key => $label) {
		echo '<li class="nav-item"><a class="nav-link' . ($key === $state ? ' active' : '') . '" href="?view=grouped&amp;state=' . $e($key) . '">'
			. $e($label) . ' <span class="badge bg-light text-dark">' . (int)($counts[$key] ?? 0) . '</span></a></li>';
	}
	echo '</ul>';

	$groups = ReceivedBugReport::groups($state);
	if (count($groups) === 0) {
		echo '<p class="text-muted">' . ($state === 'needs_you'
			? 'Nothing needs you. Every error is resolved, ignored or snoozed.'
			: 'No errors here.') . '</p>';
	} else {
		$options = ReceivedBugReport::do_options();
		$fw = new FormWriterV2HTML5('report_bulk', array('action' => $self_url, 'id' => 'report_bulk'));
		echo '<div class="mb-3">';
		$fw->begin_form();
		$fw->hiddeninput('action', '', array('value' => 'triage'));
		$fw->hiddeninput('back', '', array('value' => $self_url . '?view=grouped&state=' . $state));
		$fw->dropinput('do', 'With the selected', array('options' => $options, 'value' => ReceivedBugReport::STATUS_RESOLVED));
		$fw->submitbutton('btn_report_bulk', 'Apply', array('class' => 'btn btn-sm btn-outline-primary'));
		$fw->end_form();
		echo '</div>';

		$page->tableheader(array('', 'Error', 'Where', 'Sites', 'Reports', 'Times seen', 'Need you', 'Newest version', 'First', 'Last', 'Status'),
			array('title' => ReceivedBugReport::GROUP_VIEWS[$state]));
		foreach ($groups as $g) {
			$hash = (string)$g['hash'];
			$label = $hash === '' ? 'No recorded error (message only)' : ($g['kind'] ?: 'Error');
			// Reports with no recorded error are each their own: triaged one by one, on their pages.
			$pick = ReceivedBugReport::is_group_key($hash)
				? '<input type="checkbox" name="groups[]" value="' . $e($hash) . '" form="report_bulk" aria-label="Select this error">'
				: '';
			$page->disprow(array(
				$pick,
				'<a href="?view=all&amp;group=' . urlencode($hash) . '">' . $e($label) . '</a><br><small>'
					. $e(mb_substr((string)$g['message'], 0, 120)) . '</small>',
				'<small>' . $e($g['location']) . '</small>',
				(int)$g['sites'],
				(int)$g['reports'],
				(int)$g['occurrences'],
				(int)$g['needs_you'],
				$e($g['newest_version']),
				$e(LibraryFunctions::convert_time($g['first_time'], 'UTC', $tz, 'M j, Y')),
				$e(LibraryFunctions::convert_time($g['last_time'], 'UTC', $tz, 'M j, Y g:i A')),
				$e(ReceivedBugReport::state_words((string)$g['state'], $g['snooze_until'], $g['ignored_through'], $tz)),
			));
		}
		$page->endtable();
	}

	echo '<p class="small text-muted"><strong>Resolve</strong> an error that is dealt with: if it happens again it needs you again. '
		. '<strong>Ignore</strong> one you have decided to live with: its reports keep counting but stay quiet while it happens on the versions '
		. 'already reported; the first report from a newer version brings it back. <strong>Snooze</strong> puts it back in Needs you when it ends. '
		. '<strong>Reopen</strong> puts it back now. Each applies to every report of the error, from every site. '
		. 'A member\'s own report always arrives needing you.</p>';
} else {
	$options = array();
	foreach (array('host', 'version', 'verdict', 'status', 'group') as $key) {
		if ((isset($_GET[$key]) && $_GET[$key] !== '') || ($key === 'group' && isset($_GET['group']))) {
			$options[$key] = (string)$_GET[$key];
		}
	}
	$numperpage = 30;
	$offset = (int)($_GET['offset'] ?? 0);
	$reports = new MultiReceivedBugReport($options, array('rbr_received_time' => 'DESC'), $numperpage, $offset);
	$pager = new Pager(array('numrecords' => $reports->count_all(), 'numperpage' => $numperpage));
	if ($options) {
		$shown = array();
		foreach ($options as $k => $v) {
			$shown[] = $k . ' = ' . ($v === '' ? '(none)' : $v);
		}
		echo '<p>Showing ' . $e(implode(', ', $shown)) . '. <a href="?view=all">Show every report</a></p>';
	}
	$page->tableheader(array('Report', 'Received', 'Site', 'Version', 'Check', 'Error', 'Times seen', 'Status'),
		array('title' => 'Every report'), $pager);
	foreach ($reports as $r) {
		$page->disprow(array(
			'<a href="/plugins/bug_reports/admin/admin_bug_report?rbr_received_bug_report_id=' . (int)$r->key . '">Report ' . (int)$r->key . '</a>',
			$e(LibraryFunctions::convert_time($r->get('rbr_received_time'), 'UTC', $tz, 'M j, g:i A')),
			'<a href="?view=all&amp;host=' . urlencode((string)$r->get('rbr_claimed_host')) . '">' . $e($r->get('rbr_claimed_host')) . '</a>',
			'<a href="?view=all&amp;version=' . urlencode((string)$r->get('rbr_claimed_version')) . '">' . $e($r->get('rbr_claimed_version')) . '</a>',
			$e($verdicts[$r->get('rbr_verdict')] ?? (string)$r->get('rbr_verdict')),
			'<small>' . $e(mb_substr((string)($r->get('rbr_error_location') ?: $r->get('rbr_error_message')), 0, 80)) . '</small>',
			(int)$r->get('rbr_occurrences') . ((bool)$r->get('rbr_automatic') ? ' (automatic)' : ''),
			$e(ReceivedBugReport::state_words($r->status(), $r->get('rbr_snooze_until'), $r->get('rbr_ignored_through'), $tz)),
		));
	}
	$page->endtable($pager);
}

$page->admin_footer();
