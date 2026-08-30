<?php
/** @joinery-test
 * name: restore_approval
 * tier: db
 * env: any
 * needs: []
 */
/**
 * The site's half of the restore approval, and what it deliberately cannot do.
 *
 * The reassuring property here is a negative one. This class — and the admin
 * page that renders it, and every line of the web tier around them — moves a
 * ciphertext one way and a recovered plaintext the other. It cannot approve
 * anything. The one-time secret is inside a box only the backup recovery key
 * opens, and that key is in somebody's password manager and has never been on a
 * server. So the whole of this side could be rewritten by an attacker and still
 * produce no answer.
 *
 * What IS this side's job, and is asserted here:
 *
 *   * Show a pending approval when the agent has staged one, and show nothing
 *     when it has not.
 *   * Stop showing one the moment it expires. An approval screen for a restore
 *     that is no longer running is how somebody authorizes something and then,
 *     hours later, cannot tell whether it happened.
 *   * Refuse to post an answer against a job other than the one waiting.
 *
 * The cross-language half — that the agent's sealed challenge opens in the
 * browser code the operator actually runs — is
 * tests/backups/approval_challenge_parity_gate.sh.
 *
 * Run: php tests/backups/restore_approval_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/RestoreApproval.php'));
require_once(PathHelper::getIncludePath('data/settings_class.php'));

/**
 * Write a handoff row the way both real writers do.
 *
 * Setting::put and NOT the harness's set_setting_raw, which is an UPDATE and
 * silently writes nothing when the row does not exist yet — which is exactly the
 * state of a box where update_database has not run since these settings were
 * declared. A test that used it would pass on a seeded box and quietly assert
 * nothing on a fresh one.
 */
function ra_put($name, $value) {
	Setting::put($name, (string)$value);
}

// Both handoff rows are left EMPTY afterwards, not restored to whatever was
// found. That is not laziness about cleanup — it is the correct end state, and
// restoring what was there is actively wrong here.
//
// These two rows are ephemeral by construction: the agent writes a challenge,
// waits, and clears both in a deferred block whatever the outcome. Empty is the
// only value a healthy machine ever rests at. So a snapshot-and-restore has no
// state to protect, and it has one failure mode that matters — a run killed
// before its cleanup leaves a fixture behind, and every later run then faithfully
// restores that fixture, permanently. This happened: dev carried a stale
// `{"job_id":1,"challenge":"abc",...}` for hours, and it was only inert because
// its expiry had passed. A fixture with a future expiry would have put a
// FAKE APPROVAL SCREEN on a real site's admin page, which is the one thing this
// mechanism must never show.
harness_defer(function () {
	ra_put(RestoreApproval::REQUEST_SETTING, '');
	ra_put(RestoreApproval::ANSWER_SETTING, '');
});

/** Stage a challenge the way the agent does, expiring $seconds from now. */
function ra_stage($job_id, $seconds) {
	$now = time();
	ra_put(RestoreApproval::REQUEST_SETTING, (string)json_encode(array(
		'job_id'           => $job_id,
		'primitive'        => 'restore_database',
		'summary'          => 'This will erase the database joinerytest on this machine and load an old copy.',
		'facts'            => array(
			array('label' => 'Database', 'value' => 'joinerytest'),
			array('label' => 'Taken', 'value' => '2026-08-30 03:00:00 UTC (6 hours ago)'),
		),
		'statement_sha256' => str_repeat('ab', 32),
		'challenge'        => base64_encode(random_bytes(96)),
		'public_key'       => base64_encode(random_bytes(32)),
		'info'             => RestoreApproval::BROWSER_INFO,
		'issued_time'      => gmdate('Y-m-d H:i:s', $now),
		'expires_time'     => gmdate('Y-m-d H:i:s', $now + $seconds),
	)));
	ra_put(RestoreApproval::ANSWER_SETTING, '');
}

// ── Nothing waiting ─────────────────────────────────────────────────────────
section('Nothing is shown when nothing is waiting');

ra_put(RestoreApproval::REQUEST_SETTING, '');
check(RestoreApproval::pending() === null, 'an empty handoff row means no approval screen');

ra_put(RestoreApproval::REQUEST_SETTING, 'not json at all');
check(RestoreApproval::pending() === null, 'an unreadable handoff row shows nothing rather than half a screen');

// ── A pending approval ──────────────────────────────────────────────────────
section('What the agent staged is what the operator is shown');

ra_stage(4242, 900);
$pending = RestoreApproval::pending();

check(is_array($pending), 'a staged challenge is offered for approval');
check(($pending['job_id'] ?? 0) === 4242, 'it names the job it is bound to');
check(strpos((string)($pending['summary'] ?? ''), 'erase the database') !== false,
	'the summary says what will be destroyed, in words');
check(count($pending['facts'] ?? array()) === 2, 'the facts the node composed travel intact');

// The age is the fact no automatic check can substitute for: a REPLAYED archive
// is genuine, signed and openable, and only its date is wrong.
$labels = array();
foreach ($pending['facts'] as $f) { $labels[$f['label']] = $f['value']; }
check(isset($labels['Taken']) && strpos($labels['Taken'], 'hours ago') !== false,
	'the archive\'s age is on the screen as an age', $labels['Taken'] ?? '(missing)');

check(($pending['info'] ?? '') === RestoreApproval::BROWSER_INFO,
	'the challenge names the approval HKDF context, not the possession one',
	'a browser handed the wrong context cannot open the challenge, and it reads as a bad key');
check(($pending['seconds_left'] ?? 0) > 0 && ($pending['seconds_left'] ?? 0) <= 900,
	'the operator is told how long they have', (string)($pending['seconds_left'] ?? 0));

// ── Expiry ──────────────────────────────────────────────────────────────────
section('An expired challenge stops being offered');

ra_stage(4243, -1);
check(RestoreApproval::pending() === null,
	'a challenge past its expiry is not shown',
	'the agent has stopped watching for an answer, so approving would authorize nothing — and the '
	. 'operator would have no way to tell');

$declined = false;
try { RestoreApproval::answer(4243, 'anything'); }
catch (RestoreApprovalException $e) { $declined = true; }
check($declined, 'and cannot be answered');

// ── Answering ───────────────────────────────────────────────────────────────
section('An answer goes to the job that is waiting, and no other');

ra_stage(4242, 900);

$wrong_job = false;
try { RestoreApproval::answer(9999, 'joinery-restore-approval abc'); }
catch (RestoreApprovalException $e) { $wrong_job = true; }
check($wrong_job, 'an answer aimed at a different job is refused here',
	'the binding is inside the sealed box too, so this is the legible refusal rather than the check');

$empty = false;
try { RestoreApproval::answer(4242, '   '); }
catch (RestoreApprovalException $e) { $empty = true; }
check($empty, 'an empty answer is refused with something to do about it');

RestoreApproval::answer(4242, 'joinery-restore-approval ' . base64_encode('a-recovered-secret'));
$posted = json_decode((string)get_setting_raw(RestoreApproval::ANSWER_SETTING), true);
check(is_array($posted) && ($posted['job_id'] ?? 0) === 4242,
	'a well-formed answer is handed to the agent, carrying the job it belongs to');
check(strpos((string)($posted['answer'] ?? ''), 'joinery-restore-approval') === 0,
	'and carries what the browser recovered, unaltered — this side cannot check it, and does not try');

// ── Declining ───────────────────────────────────────────────────────────────
section('Declining is a first-class answer');

ra_stage(4244, 900);
RestoreApproval::decline(4244);
$posted = json_decode((string)get_setting_raw(RestoreApproval::ANSWER_SETTING), true);
check(is_array($posted) && !empty($posted['declined']) && ($posted['job_id'] ?? 0) === 4244,
	'a decline is recorded against the job, so the agent reports it refused rather than timed out');

$wrong_decline = false;
try { RestoreApproval::decline(1); }
catch (RestoreApprovalException $e) { $wrong_decline = true; }
check($wrong_decline, 'and cannot be aimed at a job that is not waiting');

// ── The settings are declared ───────────────────────────────────────────────
section('Both handoff rows are declared settings');

// Setting::put refuses a name that is not declared, so an undeclared row would
// make every approval fail at the moment somebody tried to give one — which is
// the worst moment to discover a missing line in settings.json.
$declared = json_decode((string)file_get_contents(PathHelper::getIncludePath('settings.json')), true);
$names = array();
foreach (($declared['settings'] ?? array()) as $row) { $names[] = $row['name'] ?? ''; }
check(in_array(RestoreApproval::REQUEST_SETTING, $names, true),
	'the challenge row is declared in settings.json');
check(in_array(RestoreApproval::ANSWER_SETTING, $names, true),
	'the answer row is declared in settings.json — Setting::put refuses an undeclared name, and '
	. 'an approval that could not be written would fail at the one moment it matters');

harness_finish();
