<?php
/** @joinery-test
 * name: calendar_emails
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Calendar reminder + summary email engine (CalendarEmailEngine, the
 * CalendarEmails task's brain), run against a synthetic clock with an
 * injected sender so nothing real is emailed.
 *
 *   php tests/calendar/calendar_emails_test.php
 *
 * Fixed instant: 2026-08-10 13:00:00 UTC — a Monday, 08:00 in Chicago and
 * 09:00 in New York, so daily/weekly due logic is deterministic.
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/calendar/CalendarEmailEngine.php'));
require_once(PathHelper::getIncludePath('data/calendar_entry_class.php'));
require_once(PathHelper::getIncludePath('data/calendar_preference_class.php'));
require_once(PathHelper::getIncludePath('data/calendar_email_class.php'));

const NOW_UTC = '2026-08-10 13:00:00'; // Monday; Chicago 08:00 CDT, New York 09:00 EDT

$made_entries = [];
function make_entry($user_id, array $overrides = []) {
	global $made_entries;
	$e = new CalendarEntry(NULL);
	$e->set('cal_subject_type', 'user');
	$e->set('cal_subject_id', $user_id);
	$e->set('cal_type', 'personal');
	$e->set('cal_title', $overrides['cal_title'] ?? 'Test entry');
	$e->set('cal_timezone', 'America/Chicago');
	foreach ($overrides as $k => $v) {
		$e->set($k, $v);
	}
	if (!$e->get('cal_end_utc') && $e->get('cal_start_utc')) {
		$e->set('cal_end_utc', gmdate('Y-m-d H:i:s', strtotime($e->get('cal_start_utc') . ' UTC') + 3600));
	}
	$e->save();
	$made_entries[] = $e;
	return $e;
}

/** Reminder due-list filtered to this test's users (dev DB has other rows). */
function due_for(CalendarEmailEngine $engine, array $user_ids) {
	$due = [];
	foreach ($engine->dueReminders() as $cand) {
		if (in_array((int)$cand['user']->key, $user_ids, true)) {
			$due[] = $cand;
		}
	}
	return $due;
}

// ── Fixture users ───────────────────────────────────────────────────────────
$userA = make_user('calemA');   // will opt in: default 30-min reminders, daily summary
$userB = make_user('calemB');   // never opts in (no preference row)
$userC = make_user('calemC');   // daily summary, empty calendar
foreach ([[$userA, 'America/Chicago'], [$userB, 'America/New_York'], [$userC, 'America/Chicago']] as list($u, $tz)) {
	$u->set('usr_timezone', $tz);
	$u->save();
}
$ids = [(int)$userA->key, (int)$userB->key, (int)$userC->key];

$engine = new CalendarEmailEngine(NOW_UTC);
$sent = [];
$engine->sender = function ($template, $to, $vars, $subject) use (&$sent) {
	$sent[] = ['template' => $template, 'to' => $to, 'vars' => $vars, 'subject' => $subject];
	return true;
};

// ── Reminders: nothing without opt-in ───────────────────────────────────────
section('Factory state sends nothing');
make_entry($userA->key, ['cal_title' => 'No pref yet', 'cal_start_utc' => '2026-08-10 13:20:00']);
make_entry($userB->key, ['cal_title' => 'B no pref',   'cal_start_utc' => '2026-08-10 13:20:00']);
check(count(due_for($engine, $ids)) === 0, 'no preference rows, NULL overrides: zero reminders due');

// ── Opt-in default ──────────────────────────────────────────────────────────
section('Owner default lead');
$prefA = CalendarPreference::get_for($userA->key);
$prefA->set('cpr_summary_frequency', 'daily');
$prefA->set('cpr_summary_hour', 7);
$prefA->set('cpr_reminder_default_minutes', 30);
$prefA->save();

$engine2 = new CalendarEmailEngine(NOW_UTC);
$due = due_for($engine2, $ids);
check(count($due) === 1, 'entry 20 min out is inside A\'s 30-min default lead', 'got ' . count($due));
check($due && $due[0]['lead'] === 30, 'inherited lead is the owner default');

make_entry($userA->key, ['cal_title' => 'Too far out', 'cal_start_utc' => '2026-08-10 13:50:00']);
make_entry($userA->key, ['cal_title' => 'Started already', 'cal_start_utc' => '2026-08-10 12:59:00']);
$engine3 = new CalendarEmailEngine(NOW_UTC);
check(count(due_for($engine3, $ids)) === 1, '50-min-out entry (outside lead) and started entry both excluded');

// ── Per-entry overrides, both directions ────────────────────────────────────
section('Per-entry override');
make_entry($userA->key, ['cal_title' => 'Muted', 'cal_start_utc' => '2026-08-10 13:10:00', 'cal_reminder_minutes' => 0]);
make_entry($userB->key, ['cal_title' => 'B explicit hour', 'cal_start_utc' => '2026-08-10 13:45:00', 'cal_reminder_minutes' => 60]);
$engine4 = new CalendarEmailEngine(NOW_UTC);
$due = due_for($engine4, $ids);
$titles = array_map(function ($c) { return $c['entry']->get('cal_title'); }, $due);
check(!in_array('Muted', $titles, true), 'override 0 mutes despite the 30-min default');
check(in_array('B explicit hour', $titles, true), 'override 60 arms despite owner having no default');

// ── Exclusions ──────────────────────────────────────────────────────────────
section('All-day and cancelled excluded');
make_entry($userA->key, ['cal_title' => 'All day', 'cal_start_utc' => '2026-08-10 05:00:00', 'cal_end_utc' => '2026-08-11 05:00:00', 'cal_all_day' => true, 'cal_reminder_minutes' => 60]);
make_entry($userA->key, ['cal_title' => 'Cancelled', 'cal_start_utc' => '2026-08-10 13:15:00', 'cal_status' => 'cancelled']);
$engine5 = new CalendarEmailEngine(NOW_UTC);
$titles = array_map(function ($c) { return $c['entry']->get('cal_title'); }, due_for($engine5, $ids));
check(!in_array('All day', $titles, true), 'all-day entry carries no timed reminder');
check(!in_array('Cancelled', $titles, true), 'cancelled entry is silent');

// ── Recurring expansion ─────────────────────────────────────────────────────
section('Recurring occurrence reminds');
make_entry($userA->key, [
	'cal_title' => 'Weekly standup',
	'cal_start_local' => '2026-07-06 08:25:00', // Mondays 8:25 Chicago = 13:25 UTC (CDT)
	'cal_end_local'   => '2026-07-06 08:55:00',
	'cal_start_utc'   => '2026-07-06 13:25:00',
	'cal_end_utc'     => '2026-07-06 13:55:00',
	'cal_recurrence_type' => 'weekly',
	'cal_recurrence_days_of_week' => '1',
]);
$engine6 = new CalendarEmailEngine(NOW_UTC);
$due = due_for($engine6, $ids);
$standup = null;
foreach ($due as $c) {
	if ($c['entry']->get('cal_title') === 'Weekly standup') { $standup = $c; }
}
check($standup !== null, 'today\'s occurrence of a weekly parent is due');
check($standup && $standup['occurrence_start_utc'] === '2026-08-10 13:25:00', 'occurrence start is this Monday\'s instance', $standup ? $standup['occurrence_start_utc'] : '(none)');

// ── Full run: sends, ledger, idempotency ────────────────────────────────────
section('Run, ledger, idempotency');
$engine7 = new CalendarEmailEngine(NOW_UTC);
$engine7->sender = $engine->sender;
$sent = [];
$result = $engine7->run(false);
$mine = array_filter($sent, function ($s) use ($userA, $userB, $userC) {
	return in_array($s['to'], [$userA->get('usr_email'), $userB->get('usr_email'), $userC->get('usr_email')], true);
});
$my_reminders = array_filter($mine, function ($s) { return $s['template'] === 'calendar_reminder'; });
$my_summaries = array_filter($mine, function ($s) { return $s['template'] === 'calendar_summary'; });
check(count($my_reminders) === 3, 'three reminders sent (A default, B explicit override, standup occurrence)', 'got ' . count($my_reminders) . ': ' . implode(' | ', array_map(function ($s) { return $s['subject']; }, $my_reminders)));
check(count($my_summaries) === 1, 'one summary sent (A daily; B no pref; C empty)', 'got ' . count($my_summaries));

$sent = [];
$engine8 = new CalendarEmailEngine(NOW_UTC);
$engine8->sender = $engine7->sender;
$engine8->run(false);
$mine2 = array_filter($sent, function ($s) use ($userA, $userB, $userC) {
	return in_array($s['to'], [$userA->get('usr_email'), $userB->get('usr_email'), $userC->get('usr_email')], true);
});
check(count($mine2) === 0, 'second run at the same instant re-sends nothing');

section('Ledger rows');
$log = new MultiCalendarEmail(['user_id' => $userA->key, 'kind' => CalendarEmail::KIND_SUMMARY_DAILY]);
$log->load();
$found = 0;
foreach ($log as $row) { $found++; check($row->get('cme_period_key') === '2026-08-10', 'summary ledger row keyed by local period date'); }
check($found === 1, 'exactly one daily-summary ledger row for A');
check(CalendarEmail::claim($userA->key, 'reminder', 'reminder:claimtest:x', null, '2026-08-10 13:20:00') !== NULL, 'fresh dedup key claims');
check(CalendarEmail::claim($userA->key, 'reminder', 'reminder:claimtest:x', null, '2026-08-10 13:20:00') === NULL, 'repeat dedup key refuses');

// ── Empty period: claimed but silent ────────────────────────────────────────
section('Empty summary claims without sending');
$prefC = CalendarPreference::get_for($userC->key);
$prefC->set('cpr_summary_frequency', 'daily');
$prefC->set('cpr_summary_hour', 7);
$prefC->save();
$sent = [];
$engine9 = new CalendarEmailEngine(NOW_UTC);
$engine9->sender = $engine7->sender;
$engine9->run(false);
$c_sends = array_filter($sent, function ($s) use ($userC) { return $s['to'] === $userC->get('usr_email'); });
$c_rows = new MultiCalendarEmail(['user_id' => $userC->key]);
check(count($c_sends) === 0, 'empty calendar sends no summary');
check($c_rows->count_all() === 1, 'but the period is claimed so it is not re-checked all day');

// ── Summary timing rules ────────────────────────────────────────────────────
section('Summary hour and weekly gating');
$prefA->set('cpr_summary_hour', 20); // 8 PM Chicago — not reached at 08:00
$prefA->save();
$engineA = new CalendarEmailEngine('2026-08-11 13:00:00'); // Tuesday 08:00 Chicago
$due_s = array_filter($engineA->dueSummaries(), function ($c) use ($userA) { return (int)$c['user']->key === (int)$userA->key; });
check(count($due_s) === 0, 'before the chosen hour, not due');
$engineB = new CalendarEmailEngine('2026-08-12 01:30:00'); // Tuesday 20:30 Chicago
$due_s = array_filter($engineB->dueSummaries(), function ($c) use ($userA) { return (int)$c['user']->key === (int)$userA->key; });
check(count($due_s) === 1 && $due_s[array_key_first($due_s)]['period_key'] === '2026-08-11', 'after the hour, due with the local period key');

$prefA->set('cpr_summary_frequency', 'weekly');
$prefA->set('cpr_summary_hour', 7);
$prefA->save();
$engineC = new CalendarEmailEngine('2026-08-11 13:00:00'); // Tuesday
$due_s = array_filter($engineC->dueSummaries(), function ($c) use ($userA) { return (int)$c['user']->key === (int)$userA->key; });
check(count($due_s) === 0, 'weekly is silent on a Tuesday');
$engineD = new CalendarEmailEngine('2026-08-17 13:00:00'); // next Monday
$due_s = array_filter($engineD->dueSummaries(), function ($c) use ($userA) { return (int)$c['user']->key === (int)$userA->key; });
check(count($due_s) === 1 && $due_s[array_key_first($due_s)]['kind'] === CalendarEmail::KIND_SUMMARY_WEEKLY, 'weekly is due on Monday');

// ── Moved entry earns a fresh reminder ──────────────────────────────────────
section('Rescheduled entry re-reminds');
$moved = $made_entries[0]; // 'No pref yet', already reminded in the full run
$moved->set('cal_start_utc', '2026-08-10 14:10:00');
$moved->set('cal_end_utc',   '2026-08-10 15:10:00');
$moved->save();
$engineE = new CalendarEmailEngine('2026-08-10 13:45:00');
$engineE->sender = $engine7->sender;
$sent = [];
$engineE->run(false);
$again = array_filter($sent, function ($s) { return strpos($s['subject'], 'No pref yet') !== false; });
check(count($again) === 1, 'new start time makes a new dedup key, so it actually sends again');

// ── Template rendering (the real templates, seeded by migration 168) ────────
section('Templates render');
require_once(PathHelper::getIncludePath('includes/EmailMessage.php'));
try {
	$msg = EmailMessage::fromTemplate('calendar_reminder', [
		'recipient' => $userA->export_as_array(),
		'title' => 'Dentist', 'tentative' => '1',
		'start_display' => 'Monday, Aug 10, 2026 8:20 AM CDT', 'end_display' => '9:20 AM CDT', 'start_short' => '8:20 AM',
		'calendar_url' => 'https://x/profile/calendar', 'settings_url' => 'https://x/profile/calendar_settings',
	]);
	$html = $msg->getHtmlBody();
	check(strpos($html, 'Dentist') !== false, 'reminder renders the title');
	check(strpos($html, 'tentative') !== false, 'reminder renders the tentative note');
} catch (Throwable $e) {
	check(false, 'calendar_reminder renders', $e->getMessage());
}
try {
	$generic = EmailMessage::fromTemplate('calendar_reminder', [
		'recipient' => $userA->export_as_array(),
		'title' => '', 'tentative' => '',
		'start_display' => 'Monday, Aug 10, 2026 8:20 AM CDT', 'end_display' => '9:20 AM CDT', 'start_short' => '8:20 AM',
		'calendar_url' => 'https://x/profile/calendar', 'settings_url' => 'https://x/profile/calendar_settings',
	]);
	$html = $generic->getHtmlBody();
	check(strpos($html, 'You have a calendar entry coming up') !== false, 'titleless reminder falls back to the generic line (future Private form)');
} catch (Throwable $e) {
	check(false, 'generic calendar_reminder renders', $e->getMessage());
}
try {
	$msg = EmailMessage::fromTemplate('calendar_summary', [
		'recipient' => $userA->export_as_array(),
		'period_label' => 'Your calendar today — Monday, August 10',
		'days' => [
			['label' => 'Monday, August 10', 'lines' => [['text' => '8:20 AM – 9:20 AM — Dentist'], ['text' => 'All day — Trip · event']]],
		],
		'calendar_url' => 'https://x/profile/calendar', 'settings_url' => 'https://x/profile/calendar_settings',
	]);
	$html = $msg->getHtmlBody();
	check(strpos($html, 'Dentist') !== false && strpos($html, 'Trip') !== false, 'summary nested loops render every line');
} catch (Throwable $e) {
	check(false, 'calendar_summary renders', $e->getMessage());
}

// ── Cleanup ─────────────────────────────────────────────────────────────────
section('Cleanup');
foreach ($made_entries as $e) {
	if ($e->key) { $e->permanent_delete(); }
}
// Users are harness-registered; their permanent_delete cascades cpr_/cme_ rows
// through the declared FK actions. Verify no ledger residue survives.
foreach ([$userA, $userB, $userC] as $u) {
	$uid = $u->key;
	$u->permanent_delete();
	$left = new MultiCalendarEmail(['user_id' => $uid]);
	check($left->count_all() === 0, 'no cme_ residue for deleted user ' . $uid);
}

harness_finish();
