<?php
/** @joinery-test
 * name: approval_challenge
 * tier: db
 * env: any
 * needs: []
 * covers: [includes/ApprovalChallenge.php, includes/ApprovalChallengePanel.php, adm/logic/admin_backups_logic.php]
 */
/**
 * The site's half of every approval ceremony, and what it deliberately cannot do.
 *
 * The reassuring property here is a negative one. This class — and the admin
 * page that renders it, and every line of the web tier around them — moves a
 * ciphertext one way and a recovered plaintext the other. It cannot approve
 * anything. The one-time secret is inside a box only the backup recovery key
 * opens, and that key is in somebody's password manager and has never been on a
 * server. So the whole of this side could be rewritten by an attacker and still
 * produce no answer.
 *
 * What IS this side's job, and is asserted here for EVERY scope:
 *
 *   * Show a pending approval when an agent has staged one, and show nothing
 *     when it has not.
 *   * Stop showing one the moment it expires. An approval screen for an act
 *     that is no longer waiting is how somebody authorizes something and then,
 *     hours later, cannot tell whether it happened.
 *   * Refuse to post an answer against a job other than the one waiting.
 *   * Keep the scopes apart: an answer in one scope's rows never lands in
 *     another's, and each scope's strings match the agent's approvalScope.
 *
 * The cross-language half — that the agent's sealed challenge opens in the
 * browser code the operator actually runs — is
 * tests/backups/approval_challenge_parity_gate.sh.
 *
 * Run: php tests/backups/approval_challenge_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

/**
 * Write a handoff row the way the real writers do.
 *
 * Setting::put and NOT the harness's set_setting_raw, which is an UPDATE and
 * silently writes nothing when the row does not exist yet — which is exactly the
 * state of a box where update_database has not run since these settings were
 * declared. A test that used it would pass on a seeded box and quietly assert
 * nothing on a fresh one.
 */
function ac_put($name, $value) {
	Setting::put($name, (string)$value);
}

// Every handoff row is left EMPTY afterwards, not restored to whatever was
// found. That is not laziness about cleanup — it is the correct end state, and
// restoring what was there is actively wrong here.
//
// These rows are ephemeral by construction: the agent writes a challenge,
// waits, and clears both in a deferred block whatever the outcome. Empty is the
// only value a healthy machine ever rests at. So a snapshot-and-restore has no
// state to protect, and it has one failure mode that matters — a run killed
// before its cleanup leaves a fixture behind, and every later run then faithfully
// restores that fixture, permanently. This happened: dev carried a stale
// `{"job_id":1,"challenge":"abc",...}` for hours, and it was only inert because
// its expiry had passed. A fixture with a future expiry would have put a
// FAKE APPROVAL SCREEN on a real site's admin page, which is the one thing this
// mechanism must never show.
function ac_clear_all() {
	foreach (ApprovalChallenge::SCOPES as $s) {
		ac_put($s['request_setting'], '');
		ac_put($s['answer_setting'], '');
	}
}
harness_defer('ac_clear_all');
ac_clear_all();

/** Stage a challenge the way the agent does, expiring $seconds from now; $extra joins the request. */
function ac_stage($scope, $job_id, $seconds, array $extra = array()) {
	$s = ApprovalChallenge::scope($scope);
	$now = time();
	ac_put($s['request_setting'], (string)json_encode($extra + array(
		'job_id'           => $job_id,
		'primitive'        => $scope === ApprovalChallenge::RESTORE ? 'restore_database' : 'decommission_site',
		'summary'          => 'This will erase the database joinerytest on this machine and load an old copy.',
		'facts'            => array(
			array('label' => 'Database', 'value' => 'joinerytest'),
			array('label' => 'Taken', 'value' => '2026-08-30 03:00:00 UTC (6 hours ago)'),
		),
		'statement_sha256' => str_repeat('ab', 32),
		'challenge'        => base64_encode(random_bytes(96)),
		'public_key'       => base64_encode(random_bytes(32)),
		'info'             => $s['info'],
		'issued_time'      => gmdate('Y-m-d H:i:s', $now),
		'expires_time'     => gmdate('Y-m-d H:i:s', $now + $seconds),
	)));
	ac_put($s['answer_setting'], '');
}

/** A handoff row as it is now, read past the settings cache. */
function ac_read($name) {
	$q = DbConnector::get_instance()->get_db_link()->prepare('SELECT stg_value FROM stg_settings WHERE stg_name = ?');
	$q->execute(array($name));
	return (string)$q->fetchColumn();
}

/** Whether $fn throws ApprovalChallengeException. */
function ac_refused(callable $fn) {
	try { $fn(); } catch (ApprovalChallengeException $e) { return true; }
	return false;
}

foreach (array_keys(ApprovalChallenge::SCOPES) as $scope) {
	$s = ApprovalChallenge::scope($scope);

	// ── Nothing waiting ─────────────────────────────────────────────────────
	section("[$scope] Nothing is shown when nothing is waiting");

	ac_put($s['request_setting'], '');
	check(ApprovalChallenge::pending($scope) === null, 'an empty handoff row means no approval screen');

	ac_put($s['request_setting'], 'not json at all');
	check(ApprovalChallenge::pending($scope) === null,
		'an unreadable handoff row shows nothing rather than half a screen');

	// ── A pending approval ──────────────────────────────────────────────────
	section("[$scope] What the agent staged is what the operator is shown");

	ac_stage($scope, 4242, 900);
	$pending = ApprovalChallenge::pending($scope);

	check(is_array($pending), 'a staged challenge is offered for approval');
	check(($pending['job_id'] ?? 0) === 4242, 'it names the job it is bound to');
	check(strpos((string)($pending['summary'] ?? ''), 'erase the database') !== false,
		'the summary the agent composed travels intact');
	check(count($pending['facts'] ?? array()) === 2, 'the facts the agent composed travel intact');

	// The age is the fact no automatic check can substitute for: a REPLAYED
	// archive is genuine, signed and openable, and only its date is wrong.
	$labels = array();
	foreach ($pending['facts'] as $f) { $labels[$f['label']] = $f['value']; }
	check(isset($labels['Taken']) && strpos($labels['Taken'], 'hours ago') !== false,
		'the age is on the screen as an age', $labels['Taken'] ?? '(missing)');

	check(($pending['info'] ?? '') === $s['info'],
		'the challenge names this scope\'s HKDF context',
		'a browser handed the wrong context cannot open the challenge, and it reads as a bad key');
	check(($pending['seconds_left'] ?? 0) > 0 && ($pending['seconds_left'] ?? 0) <= 900,
		'the operator is told how long they have', (string)($pending['seconds_left'] ?? 0));

	// ── Expiry ──────────────────────────────────────────────────────────────
	section("[$scope] An expired challenge stops being offered");

	ac_stage($scope, 4243, -1);
	check(ApprovalChallenge::pending($scope) === null,
		'a challenge past its expiry is not shown',
		'the agent has stopped watching for an answer, so approving would authorize nothing — and the '
		. 'operator would have no way to tell');
	check(ac_refused(function () use ($scope) { ApprovalChallenge::answer($scope, 4243, 'anything'); }),
		'and cannot be answered');

	// ── Answering ───────────────────────────────────────────────────────────
	section("[$scope] An answer goes to the job that is waiting, and no other");

	ac_stage($scope, 4242, 900);
	check(ac_refused(function () use ($scope) { ApprovalChallenge::answer($scope, 9999, 'x abc'); }),
		'an answer aimed at a different job is refused here',
		'the binding is inside the sealed box too, so this is the legible refusal rather than the check');
	check(ac_refused(function () use ($scope) { ApprovalChallenge::answer($scope, 4242, '   '); }),
		'an empty answer is refused with something to do about it');

	$recovered = rtrim($s['info'], ':') . ' ' . base64_encode('a-recovered-secret');
	ApprovalChallenge::answer($scope, 4242, $recovered);
	$posted = json_decode((string)get_setting_raw($s['answer_setting']), true);
	check(is_array($posted) && ($posted['job_id'] ?? 0) === 4242,
		'a well-formed answer is handed to the agent, carrying the job it belongs to');
	check(($posted['answer'] ?? '') === $recovered,
		'and carries what the browser recovered, unaltered — this side cannot check it, and does not try');

	// Found on the site copy's first live run (2026-10-01): the page reloads
	// before the agent takes the answer, and it showed the same request again,
	// so the owner approved twice.
	$p = ApprovalChallenge::pending($scope);
	check(($p['answered'] ?? null) === 'approved', 'until the agent takes it, the request reads as approved');
	check(ac_refused(function () use ($scope, $recovered) { ApprovalChallenge::answer($scope, 4242, $recovered); }),
		'a second answer to it is refused');
	check(ac_refused(function () use ($scope) { ApprovalChallenge::decline($scope, 4242); }),
		'and so is a decline after the approval');

	// ── Declining ───────────────────────────────────────────────────────────
	section("[$scope] Declining is a first-class answer");

	ac_stage($scope, 4244, 900);
	ApprovalChallenge::decline($scope, 4244);
	$posted = json_decode((string)get_setting_raw($s['answer_setting']), true);
	check(is_array($posted) && !empty($posted['declined']) && ($posted['job_id'] ?? 0) === 4244,
		'a decline is recorded against the job, so the agent reports it refused rather than timed out');
	check(ac_refused(function () use ($scope) { ApprovalChallenge::decline($scope, 1); }),
		'and cannot be aimed at a job that is not waiting');
	$p = ApprovalChallenge::pending($scope);
	check(($p['answered'] ?? null) === 'declined', 'until the agent takes it, the request reads as declined');

	ac_clear_all();
}

// ── The scopes stay apart ───────────────────────────────────────────────────
section('An answer in one scope never reaches another');

ac_stage(ApprovalChallenge::RESTORE, 5001, 900);
check(ApprovalChallenge::pending(ApprovalChallenge::DECOMMISSION) === null,
	'a staged restore is not offered as a removal');
check(ac_refused(function () { ApprovalChallenge::answer(ApprovalChallenge::DECOMMISSION, 5001, 'x abc'); }),
	'and answering it as a removal is refused');
check(get_setting_raw(ApprovalChallenge::scope(ApprovalChallenge::DECOMMISSION)['answer_setting']) === '',
	'nothing was written into the removal\'s answer row');
ac_clear_all();

$fields = array('request_setting', 'answer_setting', 'info', 'approve_action', 'decline_action',
	'approve_form', 'decline_form', 'id_prefix');
foreach ($fields as $field) {
	$values = array_column(ApprovalChallenge::SCOPES, $field);
	check(count($values) === count(array_unique($values)),
		"every scope has its own $field", implode(', ', $values));
}

section('Posting and ordering');

foreach (ApprovalChallenge::SCOPES as $name => $s) {
	check(ApprovalChallenge::for_action($s['approve_action']) === array('scope' => $name, 'approve' => true),
		"$s[approve_action] answers the $name scope");
	check(ApprovalChallenge::for_action($s['decline_action']) === array('scope' => $name, 'approve' => false),
		"$s[decline_action] declines the $name scope");
	check(in_array($name, ApprovalChallenge::PRECEDENCE, true),
		"the $name scope has a place in the page's order",
		'a scope missing from PRECEDENCE would be staged by its agent and never shown');
}
check(ApprovalChallenge::for_action('save_target') === null, 'an unrelated action is not an approval');

ac_stage(ApprovalChallenge::RESTORE, 5002, 900);
ac_stage(ApprovalChallenge::DECOMMISSION, 5003, 900);
$all = ApprovalChallenge::all_pending();
check(array_keys($all) === array(ApprovalChallenge::DECOMMISSION, ApprovalChallenge::RESTORE),
	'with both waiting, the removal comes first', implode(', ', array_keys($all)));
ac_clear_all();
check(ApprovalChallenge::all_pending() === array(), 'with nothing waiting, nothing is pending');

// ── The settings are declared ───────────────────────────────────────────────
section('Every handoff row is a declared setting');

// Setting::put refuses a name that is not declared, so an undeclared row would
// make every approval fail at the moment somebody tried to give one — which is
// the worst moment to discover a missing line in settings.json.
$declared = json_decode((string)file_get_contents(PathHelper::getIncludePath('settings.json')), true);
$names = array();
foreach (($declared['settings'] ?? array()) as $row) { $names[] = $row['name'] ?? ''; }
foreach (ApprovalChallenge::SCOPES as $name => $s) {
	check(in_array($s['request_setting'], $names, true), "the $name challenge row is declared in settings.json");
	check(in_array($s['answer_setting'], $names, true), "the $name answer row is declared in settings.json");
}

// ── The agent pins the same strings ─────────────────────────────────────────
section('Each scope matches the agent\'s approvalScope');

// The agent's half of each scope lives in approval.go. A drift in a setting
// name is an approval staged into a row this page never reads; a drift in the
// HKDF context is a challenge the browser cannot open. Read the source where it
// is on this box; a box without it is not one where the two can drift.
$agent_dir = getenv('JOINERY_AGENT_SOURCE') ?: '/home/user1/joinery-agent';
$go = @file_get_contents($agent_dir . '/approval.go');
if ($go === false) {
	harness_skip('agent parity not applicable', "no agent source at {$agent_dir}");
} else {
	// Resolve the file's string constants, then read each scope literal.
	preg_match_all('/^\s*(\w+)\s*=\s*"([^"]*)"/m', $go, $m, PREG_SET_ORDER);
	$consts = array();
	foreach ($m as $c) { $consts[$c[1]] = $c[2]; }
	$field_of = function ($body, $go_field) use ($consts) {
		if (!preg_match('/\b' . $go_field . ':\s*("([^"]*)"|(\w+))/', $body, $f)) { return null; }
		return isset($f[3]) && $f[3] !== '' ? ($consts[$f[3]] ?? null) : $f[2];
	};
	foreach (ApprovalChallenge::SCOPES as $name => $s) {
		$has = preg_match('/var\s+' . $name . 'Scope\s*=\s*approvalScope\{(.*?)\n\}/s', $go, $body);
		check((bool)$has, "the agent declares {$name}Scope");
		if (!$has) { continue; }
		check($field_of($body[1], 'requestSetting') === $s['request_setting'], "$name: the challenge row matches");
		check($field_of($body[1], 'answerSetting') === $s['answer_setting'], "$name: the answer row matches");
		check($field_of($body[1], 'infoPrefix') === $s['info'], "$name: the HKDF context matches");
	}
}

// ── The panel renders each scope ────────────────────────────────────────────
section('The panel says each scope\'s own words');

// The panel asks its page for FormWriters and nothing else.
$page = new class {
	public function getFormWriter($id, $o = array()) { return new FormWriterV2HTML5($id); }
};
foreach (ApprovalChallenge::SCOPES as $name => $s) {
	ac_stage($name, 6000, 900);
	ob_start();
	$rendered = ApprovalChallengePanel::render($page, $name);
	$html = ob_get_clean();
	check($rendered === true, "the $name panel renders when a challenge is waiting");
	check(strpos($html, 'value="' . $s['approve_action'] . '"') !== false, "it posts $s[approve_action]");
	check(strpos($html, 'value="' . $s['decline_action'] . '"') !== false, "it posts $s[decline_action]");
	check(strpos($html, '"keyInputId":"' . $s['id_prefix'] . '-privkey"') !== false,
		'the ceremony bridge names this panel\'s own key box');
	check(strpos($html, htmlspecialchars($s['approve_button'])) !== false, 'the approve button says what it does');
	ApprovalChallenge::answer($name, 6000, rtrim($s['info'], ':') . ' ' . base64_encode('x'));
	ob_start();
	ApprovalChallengePanel::render($page, $name);
	$html = ob_get_clean();
	check(strpos($html, 'You approved this.') !== false && strpos($html, $s['approve_action']) === false,
		'once answered, it says so and offers no second approval');
	ac_clear_all();
	ob_start();
	$rendered = ApprovalChallengePanel::render($page, $name);
	ob_end_clean();
	check($rendered === false, "and renders nothing when none is waiting");
}

section('A restore of a backup another server made opens its key with the same approval');

$recovery_pub = random_bytes(32);
$box = array('ephemeral_public' => base64_encode(random_bytes(32)), 'recovery_fingerprint' => hash('sha256', $recovery_pub));
ac_stage(ApprovalChallenge::RESTORE, 7000, 900, array('public_key' => base64_encode($recovery_pub), 'key_box' => $box));
$p = ApprovalChallenge::pending(ApprovalChallenge::RESTORE);
check(($p['key_box'] ?? null) === array('version' => 1) + $box, 'the box the agent staged is shown as it was staged, as a v1 box',
	json_encode($p['key_box'] ?? null));
check(ac_refused(function () { ApprovalChallenge::answer(ApprovalChallenge::RESTORE, 7000, 'restore abc'); }),
	'an answer that does not carry what opens the box is refused, so the agent is never handed half');
check(ac_refused(function () { ApprovalChallenge::answer(ApprovalChallenge::RESTORE, 7000, 'restore abc', 'short', base64_encode(random_bytes(32))); }),
	'nor one whose value is not 32 bytes');
ob_start();
ApprovalChallengePanel::render($page, ApprovalChallenge::RESTORE);
$html = ob_get_clean();
check(strpos($html, 'name="approval_shared"') !== false && strpos($html, 'name="approval_public_key"') !== false
		&& strpos($html, '"ephemeralPublic":"' . $box['ephemeral_public'] . '"') !== false
		&& strpos($html, '"fingerprint":"' . $box['recovery_fingerprint'] . '"') !== false,
	'the panel carries the box to the browser and has fields for what the browser works out');
check(strpos($html, 'ra-boxkey') === false && strpos($html, 'Another server made this backup') !== false,
	'sealed to the current recovery key: the one pasted key does both, and the page says so');
$shared = base64_encode(random_bytes(32));
ApprovalChallenge::answer(ApprovalChallenge::RESTORE, 7000, 'restore abc', $shared, base64_encode($recovery_pub));
$a = json_decode(ac_read('restore_approval_answer'), true);
check(($a['shared'] ?? '') === $shared && ($a['public_key'] ?? '') === base64_encode($recovery_pub) && ($a['answer'] ?? '') === 'restore abc',
	'the answer carries the box\'s value beside the recovered sentence', json_encode($a));

// Sealed to an earlier recovery key: a second box asks for that one.
ac_stage(ApprovalChallenge::RESTORE, 7001, 900, array('public_key' => base64_encode(random_bytes(32)), 'key_box' => $box));
ob_start();
ApprovalChallengePanel::render($page, ApprovalChallenge::RESTORE);
$html = ob_get_clean();
check(strpos($html, 'id="ra-boxkey"') !== false && strpos($html, '"keyInputId":"ra-boxkey"') !== false
		&& strpos($html, substr($box['recovery_fingerprint'], 0, 16)) !== false,
	'a backup sealed to an earlier recovery key asks for that key too, by its fingerprint');

// A backup sealed to an X-Wing recovery key: the box carries the KEM
// ciphertext, and the answer must name the 1216-byte public key.
$x_pub = (new XWing())->publicKey(random_bytes(32));
$x_box = array('ciphertext' => base64_encode(random_bytes(XWing::CIPHERTEXT_BYTES)), 'recovery_fingerprint' => hash('sha256', $x_pub));
ac_stage(ApprovalChallenge::RESTORE, 7003, 900, array('public_key' => base64_encode($x_pub), 'key_box' => $x_box));
$p = ApprovalChallenge::pending(ApprovalChallenge::RESTORE);
check(($p['key_box'] ?? null) === array('version' => 2) + $x_box, 'a box with a ciphertext is a v2 box', json_encode($p['key_box'] ?? null));
check(ac_refused(function () { ApprovalChallenge::answer(ApprovalChallenge::RESTORE, 7003, 'restore abc', base64_encode(random_bytes(32)), base64_encode(random_bytes(32))); }),
	'a v2 answer naming a 32-byte public key is refused');
$shared = base64_encode(random_bytes(32));
ApprovalChallenge::answer(ApprovalChallenge::RESTORE, 7003, 'restore abc', $shared, base64_encode($x_pub));
$a = json_decode(ac_read('restore_approval_answer'), true);
check(($a['shared'] ?? '') === $shared && ($a['public_key'] ?? '') === base64_encode($x_pub),
	'one naming the 1216-byte key is carried to the agent', json_encode($a));

// A request with no box, or a malformed one, carries none.
ac_stage(ApprovalChallenge::RESTORE, 7002, 900, array('key_box' => array('ephemeral_public' => 'x', 'recovery_fingerprint' => 'y')));
check(ApprovalChallenge::pending(ApprovalChallenge::RESTORE)['key_box'] === null, 'a malformed box is no box');
ApprovalChallenge::answer(ApprovalChallenge::RESTORE, 7002, 'restore abc', 'ignored', 'ignored');
$a = json_decode(ac_read('restore_approval_answer'), true);
check(!isset($a['shared']) && !isset($a['public_key']), 'and an answer to a request with no box carries nothing for one');
ac_clear_all();

harness_finish();
