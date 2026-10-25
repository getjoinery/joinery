<?php
/** @joinery-test
 * name: ics_export
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 180
 */
/**
 * Downloading a member's calendar as one .ics (IcsExporter, the calendar_export
 * action; docs/calendar.md § Downloading the calendar):
 *
 *   - round trip (export spec P2): a calendar with every kind of entry (a zone of
 *     its own, all-day over several days, free time, tentative and cancelled,
 *     weekly with a skipped date and an occurrence edited on its own, the last
 *     Friday of the month, yearly, a rule the calendar cannot repeat, text with
 *     commas, line breaks and non-Latin letters) downloads, imports into an empty
 *     calendar as the same entries, and downloads again as the same file;
 *   - the file is well formed: CRLF, folded to 75 octets, valid UTF-8, a
 *     VTIMEZONE for each zone it names and none for dates;
 *   - uploading a downloaded file to the calendar it came from adds nothing, and a
 *     file passed on keeps its UIDs;
 *   - a member downloads only their own entries;
 *   - a Private calendar with the window closed is refused as locked, and
 *     downloads in full in the window;
 *   - a file from another calendar whose skipped dates, replaced occurrence and
 *     end are UTC times lands them on the dates they fall on in the series' zone.
 */
if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
require_once(PathHelper::getIncludePath('tests/lib/vault_fixtures.php'));
// The session starts before harness_boot(), which may print and so make a later
// session_start() impossible on the CLI.
$has_session = vault_ensure_session();
harness_boot();
require_once(PathHelper::getIncludePath('tests/lib/logic.php'));

$db = DbConnector::get_instance()->get_db_link();
$saved_session = array('usr_user_id' => $_SESSION['usr_user_id'] ?? null, 'loggedin' => $_SESSION['loggedin'] ?? null,
	'permission' => $_SESSION['permission'] ?? null);
harness_defer(function () use ($saved_session) {
	foreach ($saved_session as $k => $v) { if ($v === null) { unset($_SESSION[$k]); } else { $_SESSION[$k] = $v; } }
});

function exp_as($user) {
	$_SESSION['usr_user_id'] = (int)$user->key;
	$_SESSION['loggedin'] = true;
	$_SESSION['permission'] = (int)$user->get('usr_permission');
	CalendarLevel::forget();
}

/** Every entry of these members, and their skipped dates, go when the test ends. */
function exp_cleanup(array $user_ids) {
	harness_defer(function () use ($user_ids) {
		$db = DbConnector::get_instance()->get_db_link();
		$in = implode(',', array_map('intval', $user_ids));
		$db->exec("DELETE FROM cex_entry_exceptions WHERE cex_cal_entry_id IN (SELECT cal_entry_id FROM cal_entries WHERE cal_subject_type = 'user' AND cal_subject_id IN ($in))");
		$db->exec("DELETE FROM cal_entries WHERE cal_subject_type = 'user' AND cal_subject_id IN ($in)");
	});
}

/** Save through the calendar's own write path; returns the new entry id. */
function exp_save(array $in): int {
	$r = harness_call_logic('logic/calendar_entry_save_logic.php', 'calendar_entry_save_logic', $in);
	if ($r->error !== null) { throw new RuntimeException('calendar_entry_save: ' . $r->error); }
	return (int)($r->data['entry_id'] ?? 0);
}

function exp_download(): LogicResult {
	return harness_call_logic('logic/calendar_export_logic.php', 'calendar_export_logic', array());
}

/**
 * A member's calendar as comparable values, one per entry: what the member sees,
 * with ids replaced by what they point at.
 */
function exp_snapshot(int $user_id): array {
	$entries = array();
	foreach (new MultiCalendarEntry(array('subject_type' => 'user', 'subject_id' => $user_id, 'deleted' => false)) as $e) {
		$entries[(int)$e->key] = $e;
	}
	$out = array();
	foreach ($entries as $id => $e) {
		$all_day = (bool)$e->get('cal_all_day');
		$skipped = array();
		foreach (new MultiCalEntryException(array('cal_entry_id' => $id)) as $x) { $skipped[] = substr($x->get('cex_exception_date'), 0, 10); }
		sort($skipped);
		$days = $e->get('cal_recurrence_days_of_week');
		$parent = (int)$e->get('cal_parent_entry_id');
		$row = array(
			'title'    => $e->get('cal_title'),
			'location' => $e->get('cal_location'),
			'link'     => $e->get('cal_link'),
			'notes'    => $e->get('cal_notes'),
			'all_day'  => $all_day,
			// Dates for an all-day entry; the wall clock in its own zone otherwise.
			'when'     => $all_day
				? substr($e->get('cal_start_local'), 0, 10) . ' to ' . substr($e->get('cal_end_local'), 0, 10)
				: $e->get('cal_timezone') . ' ' . substr($e->get('cal_start_local'), 0, 19) . ' to ' . substr($e->get('cal_end_local'), 0, 19),
			'start_utc' => $all_day ? null : substr($e->get('cal_start_utc'), 0, 19),
			'busy'     => (bool)$e->get('cal_blocks_availability'),
			'status'   => $e->get('cal_status'),
			'repeats'  => $e->get('cal_recurrence_type')
				? array($e->get('cal_recurrence_type'), (int)$e->get('cal_recurrence_interval'), $days === null ? null : (string)$days,
					$e->get('cal_recurrence_week_of_month') === null ? null : (int)$e->get('cal_recurrence_week_of_month'),
					$e->get('cal_recurrence_end_date') ? substr($e->get('cal_recurrence_end_date'), 0, 10) : null)
				: null,
			'raw_rule' => $e->get('cal_recurrence_type') ? null : ($e->get('cal_rrule_raw') ?: null),
			'skipped'  => $skipped,
			'replaces' => $parent && isset($entries[$parent])
				? array($entries[$parent]->get('cal_title'), substr($e->get('cal_parent_entry_date'), 0, 10)) : null,
		);
		$out[] = $row;
	}
	usort($out, function ($a, $b) { return strcmp(json_encode($a), json_encode($b)); });
	return $out;
}

/** The VEVENTs of a file, unfolded, each with its DTSTAMP taken out, sorted. */
function exp_vevents(string $ics): array {
	preg_match_all('/BEGIN:VEVENT\r\n.*?END:VEVENT/s', str_replace("\r\n ", '', $ics), $m);
	$out = array_map(function ($v) { return preg_replace('/\r\nDTSTAMP:[^\r]*/', '', $v); }, $m[0]);
	sort($out);
	return $out;
}

$a = make_user('IcsExpA', 0);
$b = make_user('IcsExpB', 0);
$c = make_user('IcsExpC', 0);
exp_cleanup(array((int)$a->key, (int)$b->key, (int)$c->key));

// ---------------------------------------------------------------------------
section('A calendar with every kind of entry downloads as one .ics');

exp_as($a);
$dentist = exp_save(array('date' => '2026-11-03', 'title' => 'Dentist, room 4; bring "forms"', 'all_day' => '0', 'start_time' => '09:00', 'end_time' => '10:15',
	'timezone' => 'America/Los_Angeles', 'blocks' => '1', 'location' => 'Main St, Suite 5',
	'link' => 'https://example.com/visit?a=1&b=2', 'notes' => "Line one\nLine two: Zoë, José, 漢字 \\ backslash"));
$conference = exp_save(array('date' => '2026-11-10', 'title' => 'Conference', 'all_day' => '1', 'blocks' => '0', 'timezone' => 'America/Chicago'));
// Three days: the all-day end is exclusive.
$db->exec("UPDATE cal_entries SET cal_end_local = '2026-11-13 00:00:00', cal_status = 'tentative' WHERE cal_entry_id = " . $conference);
$standup = exp_save(array('date' => '2026-11-02', 'title' => 'Standup', 'all_day' => '0', 'start_time' => '09:00', 'end_time' => '09:15',
	'timezone' => 'America/New_York', 'blocks' => '1',
	'recurrence' => array('type' => 'weekly', 'interval' => 1, 'days_of_week' => array(1, 3), 'ends' => 'date', 'end_date' => '2026-12-30')));
// A skipped date, and an occurrence edited on its own (an exception plus a replacement).
$db->exec("INSERT INTO cex_entry_exceptions (cex_cal_entry_id, cex_exception_date) VALUES ($standup, '2026-11-04')");
exp_save(array('entry_id' => $standup, 'occurrence_date' => '2026-11-09', 'scope' => 'this', 'date' => '2026-11-09', 'title' => 'Standup (late)',
	'all_day' => '0', 'start_time' => '10:00', 'end_time' => '10:30', 'timezone' => 'America/New_York', 'blocks' => '1'));
$club = exp_save(array('date' => '2026-11-27', 'title' => 'Book club', 'all_day' => '0', 'start_time' => '19:00', 'end_time' => '21:00',
	'timezone' => 'Europe/London', 'blocks' => '1',
	'recurrence' => array('type' => 'monthly', 'interval' => 1, 'week_of_month' => -1, 'days_of_week' => 5, 'ends' => 'never')));
exp_save(array('date' => '2026-12-05', 'title' => 'Anniversary', 'all_day' => '1', 'blocks' => '0', 'timezone' => 'America/Chicago',
	'recurrence' => array('type' => 'yearly', 'interval' => 1, 'ends' => 'never')));
$meds = exp_save(array('date' => '2026-11-01', 'title' => 'Meds', 'all_day' => '0', 'start_time' => '08:00', 'end_time' => '08:05',
	'timezone' => 'Asia/Kolkata', 'blocks' => '0',
	'recurrence' => array('type' => 'daily', 'interval' => 2, 'ends' => 'count', 'count' => 10)));
$odd = exp_save(array('date' => '2026-11-02', 'title' => 'First and third Monday', 'all_day' => '0', 'start_time' => '12:00', 'end_time' => '13:00',
	'timezone' => 'UTC', 'blocks' => '1'));
// An entry imported with a rule the calendar cannot repeat keeps the rule.
$db->exec("UPDATE cal_entries SET cal_rrule_raw = 'FREQ=MONTHLY;BYDAY=1MO,3MO' WHERE cal_entry_id = " . $odd);
$cancelled = exp_save(array('date' => '2026-11-20', 'title' => 'Old plan', 'all_day' => '0', 'start_time' => '18:00', 'end_time' => '19:00',
	'timezone' => 'Asia/Tokyo', 'blocks' => '1'));
$db->exec("UPDATE cal_entries SET cal_status = 'cancelled' WHERE cal_entry_id = " . $cancelled);
// Outlook's monthly repeat, imported as a native one with its rule kept. The member
// turns the repeat off and moves the entry to the 16th: the rule stays off.
$switched_in = array('date' => '2026-11-15', 'title' => 'Was monthly', 'all_day' => '0', 'start_time' => '07:00', 'end_time' => '07:30',
	'timezone' => 'UTC', 'blocks' => '1');
$switched = exp_save($switched_in + array('recurrence' => array('type' => 'monthly', 'interval' => 1, 'ends' => 'never')));
$db->exec("UPDATE cal_entries SET cal_rrule_raw = 'FREQ=MONTHLY;BYMONTHDAY=15' WHERE cal_entry_id = " . $switched);
exp_save(array('entry_id' => $switched, 'date' => '2026-11-16') + $switched_in);
// Editing only the title of the unrepeatable one keeps its rule.
exp_save(array('entry_id' => $odd, 'date' => '2026-11-02', 'title' => 'First and third Monday', 'all_day' => '0', 'start_time' => '12:00', 'end_time' => '13:00',
	'timezone' => 'UTC', 'blocks' => '1'));
$raw = $db->query('SELECT cal_entry_id, cal_rrule_raw FROM cal_entries WHERE cal_entry_id IN (' . $odd . ',' . $switched . ')')->fetchAll(PDO::FETCH_KEY_PAIR);
check($raw[$odd] === 'FREQ=MONTHLY;BYDAY=1MO,3MO' && $raw[$switched] === null,
	'a save that changes the repeat drops the rule an entry was imported with; one that leaves it alone keeps it', json_encode($raw));

$source = exp_snapshot((int)$a->key);
$r = exp_download();
$ics = (string)($r->data['content'] ?? '');
check($r->error === null && (int)($r->data['count'] ?? 0) === 10 && count($source) === 10,
	'the download carries all ten entries, the edited occurrence included', (string)$r->error . ' count ' . json_encode($r->data['count'] ?? null));
check(($r->data['content_type'] ?? '') === 'text/calendar;charset=utf-8' && preg_match('/^calendar-\d{4}-\d{2}-\d{2}\.ics$/', (string)($r->data['filename'] ?? '')) === 1,
	'an iCalendar file named for the day', json_encode(array($r->data['filename'] ?? null, $r->data['content_type'] ?? null)));

$lines = explode("\r\n", rtrim($ics, "\r\n"));
check(strpos($ics, "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n") === 0 && substr($ics, -15) === "END:VCALENDAR\r\n"
	&& strpos(str_replace("\r\n", '', $ics), "\n") === false && strpos(str_replace("\r\n", '', $ics), "\r") === false,
	'a VCALENDAR with every line ending in CRLF');
check(array_filter($lines, function ($l) { return strlen($l) > 75 || !mb_check_encoding($l, 'UTF-8'); }) === array(),
	'every line is 75 octets or under and valid UTF-8 on its own');
preg_match_all('/^TZID:(.+)$/m', $ics, $tz);
$zones = array_map('trim', $tz[1]);
sort($zones);
check($zones === array('America/Los_Angeles', 'America/New_York', 'Asia/Kolkata', 'Asia/Tokyo', 'Europe/London', 'UTC'),
	'a VTIMEZONE for each zone a time is in, and none for the all-day entries', json_encode($zones));
$unfolded = str_replace("\r\n ", '', $ics);
check(strpos($unfolded, 'DTSTART;VALUE=DATE:20261110') !== false && strpos($unfolded, 'DTEND;VALUE=DATE:20261113') !== false,
	'an all-day entry is written as dates, its end the day after its last');
check(preg_match('/RRULE:FREQ=WEEKLY;BYDAY=MO,WE;UNTIL=20261231T045959Z/', $unfolded) === 1
	&& strpos($unfolded, 'EXDATE;TZID=America/New_York:20261104T090000') !== false
	&& strpos($unfolded, 'EXDATE;TZID=America/New_York:20261109T090000') === false,
	'the weekly series: its rule, the skipped date, and not the date an override replaces');
check(preg_match('/RECURRENCE-ID;TZID=America\/New_York:20261109T090000\r\nSUMMARY:Standup \(late\)/', $unfolded) === 1,
	'the edited occurrence replaces its series\' 9:00 on the 9th');
check(strpos($unfolded, 'RRULE:FREQ=MONTHLY;BYDAY=1MO,3MO') !== false && strpos($unfolded, 'BYMONTHDAY=15') === false,
	'a rule the calendar cannot repeat goes back out; one the member switched off does not, though the entry moved off the 15th');
check(strpos($unfolded, 'SUMMARY:Dentist\\, room 4\\; bring "forms"') !== false
	&& strpos($unfolded, 'DESCRIPTION:Line one\\nLine two: Zoë\\, José\\, 漢字 \\\\ backslash') !== false,
	'text is escaped and non-Latin letters kept');

// ---------------------------------------------------------------------------
section('It imports into an empty calendar as the same entries');

exp_as($b);
$r = exp_download();
check($r->error === null && (int)$r->data['count'] === 0 && strpos((string)$r->data['content'], 'BEGIN:VEVENT') === false,
	'another member downloads only their own entries: none yet');
$summary = IcsImporter::import(IcsImporter::parse($ics), CalendarSubject::user((int)$b->key), 'America/Chicago');
check($summary['created'] === 10 && $summary['skipped_duplicate'] === 0 && !$summary['failed'] && $summary['imported_as_single'] === 1,
	'ten created, the unrepeatable rule as a single entry, nothing failed', json_encode(array_diff_key($summary, array('warnings' => 1))));
$copy = exp_snapshot((int)$b->key);
$diff = array();
foreach ($source as $i => $row) {
	if (($copy[$i] ?? null) !== $row) { $diff[] = json_encode($row) . ' became ' . json_encode($copy[$i] ?? null); }
}
check($diff === array(), 'every entry reads back as it was: text, times and zone, busy or free, status, repeat, skipped dates, the edited occurrence',
	implode("\n", $diff));

$r = exp_download();
check($r->error === null && exp_vevents((string)$r->data['content']) === exp_vevents($ics),
	'and downloads again as the same file, UIDs included');

// ---------------------------------------------------------------------------
section('A downloaded file uploaded again adds nothing');

$again = IcsImporter::import(IcsImporter::parse($ics), CalendarSubject::user((int)$a->key), 'America/Chicago');
check($again['created'] === 0 && $again['skipped_duplicate'] === 10 && count(exp_snapshot((int)$a->key)) === 10,
	'to the calendar it came from: all ten already there', json_encode(array_diff_key($again, array('warnings' => 1))));
$again = IcsImporter::import(IcsImporter::parse((string)$r->data['content']), CalendarSubject::user((int)$b->key), 'America/Chicago');
check($again['created'] === 0 && $again['skipped_duplicate'] === 10, 'nor to the one it was passed on to', json_encode(array_diff_key($again, array('warnings' => 1))));

// ---------------------------------------------------------------------------
section('A Private calendar downloads in the window, and is refused as locked outside it');

$kp = vault_fixture_server_vault((int)$c->key);
exp_as($c);
$r = harness_call_logic('logic/calendar_level_change_logic.php', 'calendar_level_change_logic', array('level' => ProtectionLevel::PRIVATE_));
check($r->error === null, 'the member chooses Private', (string)$r->error);
$secret_id = exp_save(array('date' => '2026-11-12', 'title' => 'Oncologist', 'all_day' => '0', 'start_time' => '14:00', 'end_time' => '15:00',
	'timezone' => 'UTC', 'location' => 'Clinic', 'notes' => 'Bring scans'));
$sealed = $db->query('SELECT cal_content_sealed FROM cal_entries WHERE cal_entry_id = ' . $secret_id)->fetchColumn();
check(in_array($sealed, array(true, 't', 1, '1'), true), 'the entry is stored sealed');
VaultUnlock::close((int)$c->key);
$r = exp_download();
check($r->error !== null && !empty($r->data['locked']) && stripos((string)$r->error, 'unlock') !== false && empty($r->data['content']),
	'with the window closed: refused as locked, nothing in it', (string)$r->error);
if (!$has_session || !vault_apcu_usable()) {
	harness_skip('in-window download', 'no session or no APCu on this CLI');
} else {
	vault_fixture_open_window((int)$c->key, $kp['secret']);
	$r = exp_download();
	$text = str_replace("\r\n ", '', (string)($r->data['content'] ?? ''));
	check($r->error === null && strpos($text, 'SUMMARY:Oncologist') !== false && strpos($text, 'LOCATION:Clinic') !== false
		&& strpos($text, 'DESCRIPTION:Bring scans') !== false, 'in the window: the entry as written', (string)$r->error);
	VaultUnlock::close((int)$c->key);
}

// ---------------------------------------------------------------------------
section('An entry with a zone and no wall clock is its UTC instant read in the zone');

exp_as($d = make_user('IcsExpD', 0));
exp_cleanup(array((int)$d->key));
$bare = exp_save(array('date' => '2026-11-03', 'title' => 'Old row', 'all_day' => '0', 'start_time' => '09:00', 'end_time' => '10:00',
	'timezone' => 'America/New_York', 'blocks' => '1'));
$db->exec('UPDATE cal_entries SET cal_start_local = NULL, cal_end_local = NULL WHERE cal_entry_id = ' . $bare);
$r = exp_download();
check(strpos((string)($r->data['content'] ?? ''), "DTSTART;TZID=America/New_York:20261103T090000\r\nDTEND;TZID=America/New_York:20261103T100000") !== false,
	'14:00 UTC is written as 9:00 in New York, not as a 14:00 wall clock', (string)($r->data['content'] ?? $r->error));

// ---------------------------------------------------------------------------
section('A file from another calendar: UTC times land on the series\' own dates');

// A Monday series at 20:00 in New York is 01:00 UTC on the Tuesday. Its skipped
// date, its replaced occurrence and its end are written in UTC, as Google does.
$utc_file = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
	. "BEGIN:VEVENT\r\nUID:utc-series@example.test\r\nSUMMARY:Evening class\r\n"
	. "DTSTART;TZID=America/New_York:20261102T200000\r\nDTEND;TZID=America/New_York:20261102T210000\r\n"
	. "RRULE:FREQ=WEEKLY;BYDAY=MO;UNTIL=20261201T010000Z\r\nEXDATE:20261110T010000Z\r\nEND:VEVENT\r\n"
	. "BEGIN:VEVENT\r\nUID:utc-series@example.test\r\nRECURRENCE-ID:20261117T010000Z\r\nSUMMARY:Evening class (moved)\r\n"
	. "DTSTART;TZID=America/New_York:20261118T200000\r\nDTEND;TZID=America/New_York:20261118T210000\r\nEND:VEVENT\r\n"
	. "END:VCALENDAR\r\n";
// An import is the calendar owner's own act: sign in as them.
exp_as($c);
$s = IcsImporter::import(IcsImporter::parse($utc_file), CalendarSubject::user((int)$c->key), 'UTC');
$rows = $db->query("SELECT cal_entry_id, cal_recurrence_end_date, cal_parent_entry_date FROM cal_entries WHERE cal_subject_type = 'user' AND cal_subject_id = "
	. (int)$c->key . " AND cal_source_event_id = 'utc-series@example.test' ORDER BY cal_entry_id")->fetchAll(PDO::FETCH_ASSOC);
$skipped = $rows ? $db->query('SELECT cex_exception_date FROM cex_entry_exceptions WHERE cex_cal_entry_id = ' . (int)$rows[0]['cal_entry_id'] . ' ORDER BY 1')->fetchAll(PDO::FETCH_COLUMN) : array();
check($s['created'] === 2 && count($rows) === 2, 'the series and its moved occurrence', json_encode(array_diff_key($s, array('warnings' => 1))));
check($skipped === array('2026-11-09', '2026-11-16'), 'the skipped and the replaced dates are the Mondays, not the Tuesdays they are in UTC', json_encode($skipped));
check(($rows[0]['cal_recurrence_end_date'] ?? '') === '2026-11-30' && ($rows[1]['cal_parent_entry_date'] ?? '') === '2026-11-16',
	'the series ends on the Monday its last class falls on', json_encode($rows));

harness_finish();
