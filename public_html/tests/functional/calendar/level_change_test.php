<?php
/** @joinery-test
 * name: calendar_level_change
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 300
 */
/**
 * The calendar's protection level (docs/calendar.md § Protection level):
 *   - Private needs a vault; a reserved subject type never seals;
 *   - raising seals every content column with the window closed, and an
 *     entry created after the flip lands sealed;
 *   - the feed, the busy projection, the entry editor and the email engine
 *     all read a sealed entry with the window closed as a locked state;
 *   - lowering needs the owner's window and opens everything back;
 *   - the level flips first and a long calendar converges in bounded batches,
 *     finished by the vault's deferred work when the page did not;
 *   - editing sealed content with the window closed is refused as locked;
 *     deleting is not; an entry still sealed on a lowered calendar is opened
 *     before an edit;
 *   - a change asks for a recent second factor, for an owner who has one;
 *   - the picker carries the calendar flavour, and mail's Fortress card says
 *     the contact list stays server custody.
 */
if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../lib/harness.php');
require_once(PathHelper::getIncludePath('tests/lib/vault_fixtures.php'));
// The session starts before harness_boot(), which may print (a stale-mail sweep)
// and so make a later session_start() impossible on the CLI.
$has_session = vault_ensure_session();
harness_boot();
require_once(PathHelper::getIncludePath('tests/lib/logic.php'));
require_once(PathHelper::getIncludePath('includes/calendar/CalendarLevel.php'));
require_once(PathHelper::getIncludePath('includes/calendar/CalendarItemSourceRegistry.php'));
require_once(PathHelper::getIncludePath('includes/calendar/CalendarEmailEngine.php'));
require_once(PathHelper::getIncludePath('includes/ProtectionLevelPicker.php'));

$db = DbConnector::get_instance()->get_db_link();
$saved_session = array('usr_user_id' => $_SESSION['usr_user_id'] ?? null, 'loggedin' => $_SESSION['loggedin'] ?? null,
	'permission' => $_SESSION['permission'] ?? null);
harness_defer(function () use ($saved_session) {
	foreach ($saved_session as $k => $v) { if ($v === null) { unset($_SESSION[$k]); } else { $_SESSION[$k] = $v; } }
});
function cal_as($user) {
	$_SESSION['usr_user_id'] = (int)$user->key;
	$_SESSION['loggedin'] = true;
	$_SESSION['permission'] = (int)$user->get('usr_permission');
	CalendarLevel::forget();
}

/** A saved entry for $owner_id at $start (UTC), one hour long. */
function cal_entry(int $owner_id, string $title, string $start_utc = '2031-03-10 15:00:00', array $extra = array()): CalendarEntry {
	$e = new CalendarEntry(NULL);
	$e->set('cal_subject_type', 'user');
	$e->set('cal_subject_id', $owner_id);
	$e->set('cal_type', 'personal');
	$e->set('cal_timezone', 'UTC');
	$e->set('cal_start_utc', $start_utc);
	$e->set('cal_end_utc', gmdate('Y-m-d H:i:s', strtotime($start_utc . ' UTC') + 3600));
	$e->set('cal_start_local', $start_utc);
	$e->set('cal_end_local', gmdate('Y-m-d H:i:s', strtotime($start_utc . ' UTC') + 3600));
	$e->set('cal_title', $title);
	$e->set('cal_location', 'Room ' . $title);
	$e->set('cal_notes', 'Notes for ' . $title);
	foreach ($extra as $k => $v) { $e->set($k, $v); }
	$e->save();
	harness_register_row('cal_entries', 'cal_entry_id', (int)$e->key);
	return $e;
}

/** Straight from the table: [sealed?, title is a blob?, raw title]. */
function cal_row(int $id): array {
	$r = DbConnector::get_instance()->get_db_link()
		->query('SELECT cal_content_sealed, cal_title, cal_location FROM cal_entries WHERE cal_entry_id = ' . (int)$id)->fetch(PDO::FETCH_ASSOC);
	return array('sealed' => in_array($r['cal_content_sealed'], array(true, 't', 1, '1'), true),
		'blob' => strpos((string)$r['cal_title'], 'v1.aead.') === 0, 'title' => (string)$r['cal_title'], 'location' => (string)$r['cal_location']);
}

/** Sealed / total live rows of one owner. */
function cal_counts(int $owner_id): array {
	$r = DbConnector::get_instance()->get_db_link()->query('SELECT COUNT(*) FILTER (WHERE cal_content_sealed) AS sealed, COUNT(*) AS total
		FROM cal_entries WHERE cal_delete_time IS NULL AND cal_subject_type = \'user\' AND cal_subject_id = ' . (int)$owner_id)->fetch(PDO::FETCH_ASSOC);
	return array('sealed' => (int)$r['sealed'], 'total' => (int)$r['total']);
}

function cal_change(string $level): LogicResult {
	return harness_call_logic('logic/calendar_level_change_logic.php', 'calendar_level_change_logic', array('level' => $level));
}

/** Drive calendar_level_batch until nothing remains; returns the last pass. */
function cal_converge(LogicResult $change): LogicResult {
	$remaining = (int)($change->data['remaining'] ?? 0);
	$last = $change;
	for ($i = 0; $i < 20 && $remaining > 0; $i++) {
		$last = harness_call_logic('logic/calendar_level_batch_logic.php', 'calendar_level_batch_logic', array());
		if ($last->error !== null) return $last;
		$remaining = (int)($last->data['remaining'] ?? 0);
		if ((int)($last->data['converted'] ?? 0) === 0) break;
	}
	return $last;
}

function cal_level(int $user_id): string {
	CalendarLevel::forget($user_id);
	return CalendarLevel::levelFor($user_id);
}

function cal_feed(int $user_id, string $start, string $end): array {
	$items = CalendarItemSourceRegistry::getItems(CalendarSubject::user($user_id), $start, $end, CalendarItem::VIS_DETAILS);
	$by_id = array();
	foreach ($items as $it) { if ($it->source === 'native') { $by_id[(int)$it->entry_id] = $it; } }
	return $by_id;
}

$owner = make_user('CalLvlOwner', 0);
$novault = make_user('CalLvlNoVault', 0);
$twofa = make_user('CalLvlTwoFactor', 0);
$twofa->enable_totp('JBSWY3DPEHPK3PXP');
$twofa->save();
$kp = vault_fixture_server_vault((int)$owner->key);
vault_fixture_server_vault((int)$twofa->key);
$window_ok = $has_session && vault_apcu_usable();
$WIN = array('2031-03-01 00:00:00', '2031-04-01 00:00:00');

// ---------------------------------------------------------------------------
section('Policy: Private needs a vault; only a member\'s own calendar seals');

cal_as($novault);
$r = cal_change(ProtectionLevel::PRIVATE_);
check($r->error !== null && stripos((string)$r->error, 'vault') !== false && cal_level((int)$novault->key) === ProtectionLevel::STANDARD,
	'Private without a vault is refused, in words that say why', (string)$r->error);
$r = cal_change('fortress');
check($r->error !== null, 'a level the calendar does not offer is refused', (string)$r->error);

cal_as($owner);
$plain = cal_entry((int)$owner->key, 'Standard dentist');
check(!cal_row((int)$plain->key)['sealed'], 'on a Standard calendar an entry is stored as written');
check(ProtectionLevelPicker::copy(ProtectionLevel::PRIVATE_, ProtectionLevelPicker::SERVICE_CALENDAR)[0] !== ProtectionLevelPicker::copy(ProtectionLevel::PRIVATE_)[0]
	&& count(ProtectionLevelPicker::copy(ProtectionLevel::PRIVATE_, ProtectionLevelPicker::SERVICE_CALENDAR)) === 3,
	'the picker carries three lines of calendar copy for Private');
$note = ProtectionLevelPicker::notesFor(ProtectionLevel::FORTRESS, ProtectionLevelPicker::SERVICE_MAIL);
check(count($note) === 1 && stripos($note[0], 'contact list') !== false && stripos($note[0], 'not end to end') !== false,
	'mail\'s Fortress card says the contact list stays server custody', json_encode($note));

// ---------------------------------------------------------------------------
section('Raising seals everything, with the window closed');

$recurring = cal_entry((int)$owner->key, 'Weekly standup', '2031-03-03 09:00:00', array(
	'cal_recurrence_type' => 'weekly', 'cal_recurrence_interval' => 1, 'cal_recurrence_days_of_week' => '1'));
$second = cal_entry((int)$owner->key, 'Oncologist', '2031-03-12 14:00:00', array('cal_link' => 'https://example.com/visit'));
$r = cal_change(ProtectionLevel::PRIVATE_);
check($r->error === null && ($r->data['level'] ?? '') === ProtectionLevel::PRIVATE_, 'the change goes through', (string)$r->error);
$r = cal_converge($r);
$c = cal_counts((int)$owner->key);
check(cal_level((int)$owner->key) === ProtectionLevel::PRIVATE_ && $c['sealed'] === 3 && $c['total'] === 3,
	'every entry is sealed, including the recurring parent', json_encode($c));
$row = cal_row((int)$second->key);
check($row['sealed'] && $row['blob'] && strpos($row['location'], 'v1.aead.') === 0, 'title and location hold ciphertext', json_encode($row));

$r = harness_call_logic('logic/calendar_entry_save_logic.php', 'calendar_entry_save_logic', array(
	'date' => '2031-03-20', 'title' => 'New after flip', 'all_day' => '1', 'location' => 'Clinic', 'timezone' => 'UTC'));
$new_id = (int)($r->data['entry_id'] ?? 0);
if ($new_id) { harness_register_row('cal_entries', 'cal_entry_id', $new_id); }
check($r->error === null && $new_id > 0 && cal_row($new_id)['sealed'] && cal_row($new_id)['blob'],
	'an entry created after the flip lands sealed, with the window closed', (string)$r->error);

$ics = harness_call_logic('logic/calendar_entry_save_logic.php', 'calendar_entry_save_logic', array(
	'date' => '2031-03-21', 'title' => 'Only the time', 'all_day' => '0', 'start_time' => '10:00', 'end_time' => '11:00', 'timezone' => 'UTC'));
$timed_id = (int)($ics->data['entry_id'] ?? 0);
if ($timed_id) { harness_register_row('cal_entries', 'cal_entry_id', $timed_id); }
$timed_row = $db->query('SELECT cal_start_utc, cal_all_day FROM cal_entries WHERE cal_entry_id = ' . $timed_id)->fetch(PDO::FETCH_ASSOC);
check($timed_id > 0 && (string)$timed_row['cal_start_utc'] === '2031-03-21 10:00:00', 'times stay plaintext on a sealed entry', json_encode($timed_row));

// ---------------------------------------------------------------------------
section('Reading with the window closed is a locked state, never an error');

VaultUnlock::close((int)$owner->key);
$feed = cal_feed((int)$owner->key, $WIN[0], $WIN[1]);
$it = $feed[(int)$second->key] ?? null;
check($it !== null && $it->locked && $it->title === CalendarItem::LOCKED_TITLE && $it->url === null && $it->location === null && $it->link === null
	&& (int)$it->entry_id === (int)$second->key && $it->start_utc === '2031-03-12 14:00:00',
	'a locked item carries its times and edit coordinates, a placeholder title, no url, location or link', $it ? json_encode($it->toArray()) : 'missing');
$occ = $feed[(int)$recurring->key] ?? null;
check($occ !== null && $occ->locked && $occ->occurrence_date !== null && $occ->url === null,
	'a locked recurring parent yields locked occurrences', $occ ? json_encode($occ->toArray()) : 'missing');
check(($it->toArray()['locked'] ?? null) === true, 'the feed shape carries locked');
$threw = false;
try {
	$blocks = CalendarItemSourceRegistry::getBusyBlocks(CalendarSubject::user((int)$owner->key), $WIN[0], $WIN[1]);
} catch (Throwable $e) { $threw = true; $blocks = array(); }
check(!$threw && count($blocks) > 0, 'the busy projection over a locked calendar does not throw and still blocks time', $threw ? get_class($e) : count($blocks));

$r = harness_call_logic('logic/calendar_entry_logic.php', 'calendar_entry_logic', array('entry_id' => (int)$second->key));
check($r->error === null && !empty($r->data['locked']) && ($r->data['entry']['title'] ?? 'x') === '' && ($r->data['entry']['date'] ?? '') === '2031-03-12',
	'calendar_entry answers locked: true with the times and no content', json_encode($r->data));

$engine = new CalendarEmailEngine('2031-03-12 13:40:00');
$vars = $engine->reminderVars(new CalendarEntry((int)$second->key, TRUE), '2031-03-12 14:00:00', '2031-03-12 15:00:00', $owner);
check($vars['title'] === '' && $vars['location'] === '' && $vars['link'] === '' && $vars['notes'] === '' && $vars['start_short'] !== '',
	'a sealed entry\'s reminder vars carry only the time', json_encode($vars));
$summary = $engine->summaryVars($owner, CalendarEmail::KIND_SUMMARY_DAILY, '2031-03-12', 'UTC');
$lines = array();
foreach (($summary['days'] ?? array()) as $day) { foreach ($day['lines'] as $l) { $lines[] = $l['text']; } }
check(count($lines) > 0 && strpos(implode("\n", $lines), CalendarEmailEngine::PRIVATE_LABEL) !== false && strpos(implode("\n", $lines), 'Oncologist') === false,
	'a summary lists the sealed entry as "Private entry" and never its title', json_encode($lines));

$r = harness_call_logic('logic/calendar_entry_save_logic.php', 'calendar_entry_save_logic', array(
	'entry_id' => (int)$second->key, 'date' => '2031-03-12', 'title' => 'Renamed', 'all_day' => '0', 'start_time' => '14:00', 'end_time' => '15:00', 'timezone' => 'UTC'));
check($r->error !== null && !empty($r->data['locked']) && cal_row((int)$second->key)['blob'],
	'editing sealed content with the window closed is refused as locked, and nothing changes', (string)$r->error);
$r = harness_call_logic('logic/calendar_entry_delete_logic.php', 'calendar_entry_delete_logic', array('entry_id' => $new_id));
$gone = $db->query('SELECT cal_delete_time FROM cal_entries WHERE cal_entry_id = ' . $new_id)->fetchColumn();
check($r->error === null && !empty($gone), 'deleting a sealed entry needs no window', (string)$r->error);

// ---------------------------------------------------------------------------
section('Lowering needs the owner\'s window, before anything changes');

$r = cal_change(ProtectionLevel::STANDARD);
check($r->error !== null && stripos((string)$r->error, 'Unlock your vault') !== false
	&& cal_level((int)$owner->key) === ProtectionLevel::PRIVATE_ && cal_counts((int)$owner->key)['sealed'] === cal_counts((int)$owner->key)['total'],
	'with the window closed: refused, the level and every row untouched', (string)$r->error);

if (!$window_ok) {
	harness_skip('in-window paths', 'no session or no APCu on this CLI');
} else {
	vault_fixture_open_window((int)$owner->key, $kp['secret']);
	$feed = cal_feed((int)$owner->key, $WIN[0], $WIN[1]);
	$it = $feed[(int)$second->key] ?? null;
	check($it !== null && !$it->locked && $it->title === 'Oncologist' && $it->location === 'Room Oncologist' && $it->link === 'https://example.com/visit',
		'in the window the feed shows the entry as written', $it ? json_encode($it->toArray()) : 'missing');
	$r = harness_call_logic('logic/calendar_entry_logic.php', 'calendar_entry_logic', array('entry_id' => (int)$second->key));
	check($r->error === null && empty($r->data['locked']) && ($r->data['entry']['notes'] ?? '') === 'Notes for Oncologist', 'and calendar_entry answers in full');

	$r = cal_change(ProtectionLevel::STANDARD);
	$r = cal_converge($r);
	$c = cal_counts((int)$owner->key);
	check($r->error === null && cal_level((int)$owner->key) === ProtectionLevel::STANDARD && $c['sealed'] === 0,
		'in the window: the level is Standard and every row is open', json_encode($c) . ' ' . (string)$r->error);
	$reread = new CalendarEntry((int)$second->key, TRUE);
	check((string)$reread->get('cal_title') === 'Oncologist' && (string)$reread->get('cal_notes') === 'Notes for Oncologist'
		&& !cal_row((int)$second->key)['blob'], 'the content reads back as written, as plaintext');

	// -----------------------------------------------------------------------
	section('The level flips first; a long calendar converges in bounded batches');

	for ($i = 0; $i < 110; $i++) {
		cal_entry((int)$owner->key, 'Bulk ' . $i, gmdate('Y-m-d H:i:s', strtotime('2031-05-01 08:00:00 UTC') + $i * 3600));
	}
	$total = cal_counts((int)$owner->key)['total'];
	$r = cal_change(ProtectionLevel::PRIVATE_);
	$c = cal_counts((int)$owner->key);
	check($r->error === null && cal_level((int)$owner->key) === ProtectionLevel::PRIVATE_, 'the promise is Private as soon as the change returns', (string)$r->error);
	check((int)($r->data['remaining'] ?? 0) > 0 && $c['sealed'] < $total && $c['sealed'] > 0,
		'one bounded pass ran and the rest is reported as remaining', json_encode($r->data) . ' ' . json_encode($c));
	check(CalendarLevel::hasWork((int)$owner->key), 'the vault\'s deferred work sees the unfinished calendar');
	$done = CalendarLevel::drain((int)$owner->key, microtime(true) + 60);
	$c = cal_counts((int)$owner->key);
	check($done > 0 && $c['sealed'] === $total, 'and its drain finishes it', json_encode($c));
	check(!CalendarLevel::hasWork((int)$owner->key), 'once everything is at its promise there is no deferred work');
	$r = cal_change(ProtectionLevel::PRIVATE_);
	check($r->error === null && (int)($r->data['remaining'] ?? -1) === 0, 'picking the level already in force is harmless');

	// -----------------------------------------------------------------------
	section('A sealed row on a lowered calendar is opened before an edit');

	// Flip without converging, as a tab closing right after the change would.
	$scope = new CalendarLevel((int)$owner->key);
	$verdict = ProtectionLevelChange::change($scope, ProtectionLevel::STANDARD, (int)$owner->key);
	check($verdict['status'] === ProtectionLevelChange::OK && cal_row((int)$second->key)['sealed'],
		'fixture: a Standard calendar whose rows are still sealed', json_encode($verdict));
	$r = harness_call_logic('logic/calendar_entry_save_logic.php', 'calendar_entry_save_logic', array(
		'entry_id' => (int)$second->key, 'date' => '2031-03-12', 'title' => 'Oncologist (moved)', 'all_day' => '0',
		'start_time' => '15:00', 'end_time' => '16:00', 'timezone' => 'UTC'));
	$row = cal_row((int)$second->key);
	check($r->error === null && !$row['sealed'] && $row['title'] === 'Oncologist (moved)',
		'the edit opens the row and lands as plaintext', (string)$r->error . ' ' . json_encode($row));
	cal_converge(cal_change(ProtectionLevel::STANDARD));
	check(cal_counts((int)$owner->key)['sealed'] === 0, 'and the rest converge');
	VaultUnlock::close((int)$owner->key);
}

// ---------------------------------------------------------------------------
section('A change asks for a recent second factor, for an owner who has one');

if (!$has_session || session_id() === '') {
	harness_skip('step-up', 'no session could be started on the CLI');
} else {
	$sid = session_id();
	$clear_markers = function () use ($sid) {
		DbConnector::get_instance()->get_db_link()
			->prepare("DELETE FROM pks_passkey_ceremonies WHERE pks_session_id = ?")->execute(array($sid));
	};
	harness_defer($clear_markers);
	$clear_markers();
	cal_as($twofa);
	$r = cal_change(ProtectionLevel::PRIVATE_);
	check($r->error === null && cal_level((int)$twofa->key) === ProtectionLevel::PRIVATE_,
		'an empty calendar is a first choice: no step-up', (string)$r->error);
	cal_entry((int)$twofa->key, 'Twofa entry');
	$r = cal_change(ProtectionLevel::STANDARD);
	check($r->error !== null && !empty($r->data['requires_stepup']) && cal_level((int)$twofa->key) === ProtectionLevel::PRIVATE_,
		'with entries and no recent confirmation: refused with requires_stepup, nothing changes', (string)$r->error);
	SessionControl::get_instance()->stamp_second_factor();
	$r = cal_change(ProtectionLevel::PRIVATE_);
	check($r->error === null, 'confirmed: the same level is accepted (nothing to converge)', (string)$r->error);
	$clear_markers();
}

harness_finish();
