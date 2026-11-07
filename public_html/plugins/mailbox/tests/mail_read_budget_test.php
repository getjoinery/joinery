<?php
/** @joinery-test
 * name: mail_read_budget
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * A person's hour of mail content is bounded (mail_access_hardening WP2,
 * MailReadBudget over MailReadLedger).
 *
 *  - With the hard line at 3 on the browser, four thread reads: the fourth is
 *    paused and answers requires_stepup; a fresh step-up stamp lets the fifth
 *    through, counting from the stamp.
 *  - A re-read of the same messages counts once.
 *  - An account with no second factor is told to wait, with when.
 *  - An app credential at app_hard 3 is paused with a 429 and Retry-After; its
 *    header fetches are pages and count against the same line; the browser's
 *    rows and the program's are metered apart.
 *  - List pages have their own line on the browser.
 *  - A download counts as its message count in one row.
 *  - Past the soft line, one mail_read_budget_soft event row per window; a
 *    pause writes one mail_read_budget_hard row per window.
 *  - A paused read writes no ledger row; 0 turns a line off.
 *
 * Run: php tests/run.php db --filter=mail_read_budget
 *
 * @version 1.1 - reviewer2 B1 (a download larger than the line goes through), B2 (a pause right after a
 *                confirmation waits), B4 (the notice counts messages alone)
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

if (!PluginHelper::isPluginActive('mailbox')) { harness_skip('mailbox plugin inactive'); harness_finish(); }

$db = DbConnector::get_instance()->get_db_link();
$suffix = bin2hex(random_bytes(4));
$user = make_user('RdBud' . $suffix, 0);
$uid = (int)$user->key;
$sid = 'rdbud-session-' . $suffix;

MailReadBudget::$limit_overrides = array('soft' => 2, 'hard' => 3, 'app_hard' => 3, 'pages_hard' => 2);
harness_defer(function () use ($db, $uid, $sid) {
	MailReadBudget::$limit_overrides = null;
	$db->prepare('DELETE FROM mrb_mail_read_ledger WHERE mrb_usr_user_id = ?')->execute(array($uid));
	$db->prepare('DELETE FROM pks_passkey_ceremonies WHERE pks_session_id = ?')->execute(array($sid));
	$db->prepare("DELETE FROM evl_event_logs WHERE evl_usr_user_id = ? AND evl_event IN (?, ?)")
		->execute(array($uid, MailReadBudget::EVENT_SOFT, MailReadBudget::EVENT_HARD));
});

$ledger_rows = function () use ($db, $uid): int {
	$q = $db->prepare('SELECT COUNT(*) FROM mrb_mail_read_ledger WHERE mrb_usr_user_id = ?');
	$q->execute(array($uid));
	return (int)$q->fetchColumn();
};
$events = function (string $event) use ($db, $uid): int {
	$q = $db->prepare('SELECT COUNT(*) FROM evl_event_logs WHERE evl_usr_user_id = ? AND evl_event = ?');
	$q->execute(array($uid, $event));
	return (int)$q->fetchColumn();
};
// Message ids need not exist: the ledger carries no foreign key, only the
// deletion rule, and the budget never reads the message.
$ids = range(900000001, 900000020);
$B = MailReadBudget::CREDENTIAL_BROWSER;
$A = MailReadBudget::CREDENTIAL_APP;

// ---------------------------------------------------------------------------
section('the browser hard line pauses the read that crosses it, and asks for a step-up');

$s1 = MailReadBudget::messages($uid, array($ids[0]), $B, $sid);
$s2 = MailReadBudget::messages($uid, array($ids[1]), $B, $sid);
$s3 = MailReadBudget::messages($uid, array($ids[2]), $B, $sid);
check(!$s1['paused'] && !$s2['paused'] && !$s3['paused'] && $s3['count'] === 3, 'three reads go through', json_encode($s3));
check($ledger_rows() === 3, 'three ledger rows');
$s4 = MailReadBudget::messages($uid, array($ids[3]), $B, $sid);
check($s4['paused'] && $s4['reason'] === MailReadBudget::PAUSE_WAIT, 'the fourth is paused; with no second factor it waits', json_encode($s4));
check($ledger_rows() === 3, 'a paused read writes no ledger row');
check($s4['retry_after'] >= 3600 && $s4['retry_after'] <= 7200, 'the wait ends when the oldest hour leaves the window', (string)$s4['retry_after']);
$r = MailReadBudget::refusal($s4);
check($r->error !== null && !empty($r->data['read_budget_paused']) && empty($r->data['requires_stepup'])
		&& $r->data['retry_after_seconds'] === $s4['retry_after'] && strpos($r->error, 'Profile > Security') !== false,
	'the refusal says to wait and where a second factor is added', json_encode($r->data));
check($events(MailReadBudget::EVENT_HARD) === 1, 'one mail_read_budget_hard event row');
MailReadBudget::messages($uid, array($ids[4]), $B, $sid);
check($events(MailReadBudget::EVENT_HARD) === 1, 'a second paused read in the window writes no second row');

// Give the account a second factor: the same pause now asks for a step-up.
$db->prepare("UPDATE usr_users SET usr_totp_secret = 'rdbud-test-secret', usr_totp_enabled_time = now() WHERE usr_user_id = ?")->execute(array($uid));
$s4b = MailReadBudget::messages($uid, array($ids[3]), $B, $sid);
check($s4b['paused'] && $s4b['reason'] === MailReadBudget::PAUSE_STEPUP, 'with a second factor the pause asks for a step-up', json_encode($s4b));
$r = MailReadBudget::refusal($s4b);
check(!empty($r->data['requires_stepup']) && !empty($r->data['read_budget_paused']), 'the refusal carries requires_stepup');

// ---------------------------------------------------------------------------
section('a fresh step-up stamp restarts the count from the stamp');

$marker = new PasskeyCeremony(NULL);
$marker->set('pks_session_id', $sid);
$marker->set('pks_kind', 'stepup');
$marker->set('pks_purpose', 'stepup_verified');
$marker->set('pks_expires_time', gmdate('Y-m-d H:i:s', time() + 3600));
$marker->save();
harness_register_row('pks_passkey_ceremonies', 'pks_passkey_ceremony_id', (int)$marker->key);
// The stamp must be newer than the three rows above.
$db->prepare('UPDATE pks_passkey_ceremonies SET pks_create_time = now() WHERE pks_passkey_ceremony_id = ?')->execute(array((int)$marker->key));
$db->prepare("UPDATE mrb_mail_read_ledger SET mrb_create_time = now() - interval '2 seconds' WHERE mrb_usr_user_id = ?")->execute(array($uid));
$s5 = MailReadBudget::messages($uid, array($ids[3]), $B, $sid);
check(!$s5['paused'] && $s5['count'] === 1, 'the fifth read goes through, counted from the stamp', json_encode($s5));
check($ledger_rows() === 4, 'the earlier rows stay');
$again = MailReadBudget::messages($uid, array($ids[3]), $B, $sid);
check(!$again['paused'] && $again['count'] === 1 && $ledger_rows() === 4, 'a re-read of the same message counts once and writes nothing');
$s6 = MailReadBudget::messages($uid, array($ids[5], $ids[6]), $B, $sid);
check(!$s6['paused'] && $s6['count'] === 3, 'a batch of two counts two');
$s7 = MailReadBudget::messages($uid, array($ids[7]), $B, $sid);
check($s7['paused'], 'and the line is reached again after the stamp');
// The stamp is seconds old: the ceremony would answer at once without asking,
// so asking again would loop the page (reviewer2 B2). It waits instead.
check($s7['reason'] === MailReadBudget::PAUSE_WAIT && !empty($s7['just_confirmed'])
		&& $s7['retry_after'] >= 60 && $s7['retry_after'] <= MailReadBudget::STEPUP_BOUNCE_SECONDS,
	'a pause right after a confirmation waits out the ceremony\'s bounce window instead of asking again', json_encode($s7));
$r = MailReadBudget::refusal($s7);
check(empty($r->data['requires_stepup']) && strpos($r->error, 'a moment ago') !== false, 'and says so');
// An older stamp (past the bounce window) asks for a step-up again.
$db->prepare("UPDATE pks_passkey_ceremonies SET pks_create_time = now() - interval '400 seconds' WHERE pks_session_id = ?")->execute(array($sid));
$db->prepare("UPDATE mrb_mail_read_ledger SET mrb_create_time = now() - interval '300 seconds' WHERE mrb_usr_user_id = ? AND mrb_create_time > now() - interval '60 seconds'")->execute(array($uid));
$s7b = MailReadBudget::messages($uid, array($ids[7]), $B, $sid);
check($s7b['paused'] && $s7b['reason'] === MailReadBudget::PAUSE_STEPUP, 'a stamp older than the bounce window: the pause asks for a step-up', json_encode($s7b));
// Another browser session without the stamp is still paused by the same ledger.
$other = MailReadBudget::messages($uid, array($ids[8]), $B, 'rdbud-other-' . $suffix);
check($other['paused'], 'the stamp lifts the pause for the session that proved itself, not another');

// ---------------------------------------------------------------------------
section('a program is metered on its own credential and told 429 with Retry-After');

$a1 = MailReadBudget::messages($uid, array($ids[0], $ids[1]), $A);
check(!$a1['paused'] && $a1['count'] === 2, 'the browser\'s rows do not count against the program', json_encode($a1));
$a2 = MailReadBudget::page($uid, $A);
check(!$a2['paused'] && $a2['count'] === 3, 'a header fetch is a page against the same app line', json_encode($a2));
$a3 = MailReadBudget::messages($uid, array($ids[2]), $A);
check($a3['paused'] && $a3['reason'] === MailReadBudget::PAUSE_WAIT, 'past the app line the program waits', json_encode($a3));
$r = MailReadBudget::refusal($a3);
check(($r->data['http_status'] ?? 0) === 429 && ($r->data['retry_after_seconds'] ?? 0) >= 60 && ($r->data['imap_code'] ?? '') === 'LIMIT',
	'the refusal is 429 with Retry-After and the gateway\'s LIMIT word', json_encode($r->data));
$a4 = MailReadBudget::messages($uid, range($ids[9], $ids[12]), $A);
check($a4['paused'], 'and stays paused for a batch');

// ---------------------------------------------------------------------------
section('list pages have their own line on the browser');

$fresh = make_user('RdBuP' . $suffix, 0);
$fid = (int)$fresh->key;
harness_defer(function () use ($db, $fid) { $db->prepare('DELETE FROM mrb_mail_read_ledger WHERE mrb_usr_user_id = ?')->execute(array($fid)); });
$p1 = MailReadBudget::page($fid, $B, 'rdbud-p-' . $suffix);
$p2 = MailReadBudget::page($fid, $B, 'rdbud-p-' . $suffix);
$p3 = MailReadBudget::page($fid, $B, 'rdbud-p-' . $suffix);
check(!$p1['paused'] && !$p2['paused'] && $p2['count'] === 2 && $p3['paused'] && $p3['kind'] === MailReadBudget::KIND_PAGE,
	'two pages, then the third is paused', json_encode($p3));
// The reader re-reads its list after every drain that parsed mail
// (sealed_mail_after_unlock.md); each re-read is a page like any other, on
// purpose: the browser cannot be trusted to say which reads a drain caused.
check($p2['count'] === $p1['count'] + 1, 'a re-read of the same list (a drain\'s refresh) counts as another page');
$m1 = MailReadBudget::messages($fid, array($ids[0]), $B, 'rdbud-p-' . $suffix);
check(!$m1['paused'] && $m1['count'] === 1, 'pages do not spend the message line');

// ---------------------------------------------------------------------------
section('a download counts as its message count; past the soft line the account is told once');

$dl = make_user('RdBuD' . $suffix, 0);
$did = (int)$dl->key;
harness_defer(function () use ($db, $did) {
	$db->prepare('DELETE FROM mrb_mail_read_ledger WHERE mrb_usr_user_id = ?')->execute(array($did));
	$db->prepare("DELETE FROM evl_event_logs WHERE evl_usr_user_id = ? AND evl_event IN (?, ?)")
		->execute(array($did, MailReadBudget::EVENT_SOFT, MailReadBudget::EVENT_HARD));
});
// A download is judged on the count before it: refusing it for its own size
// would refuse every download larger than the line (reviewer2 B1).
$d1 = MailReadBudget::mailbox($did, 5, $B, 'rdbud-d-' . $suffix);
check(!$d1['paused'] && $d1['count'] === 5, 'a download of five on a line of three goes through, in one row', json_encode($d1));
$d2 = MailReadBudget::messages($did, array($ids[1]), $B, 'rdbud-d-' . $suffix);
check($d2['paused'], 'and the next read is the paused one', json_encode($d2));
$d3 = MailReadBudget::mailbox($did, 2, $B, 'rdbud-d-' . $suffix);
check($d3['paused'], 'a second download while paused is refused on the count before it', json_encode($d3));
$q = $db->prepare('SELECT COUNT(*), COALESCE(SUM(mrb_count), 0) FROM mrb_mail_read_ledger WHERE mrb_usr_user_id = ?');
$q->execute(array($did));
$row = $q->fetch(PDO::FETCH_NUM);
check((int)$row[0] === 1 && (int)$row[1] === 5, 'one weighted row; the paused reads wrote nothing', json_encode($row));
$soft_before = $events(MailReadBudget::EVENT_SOFT);
$q = $db->prepare('SELECT COUNT(*) FROM evl_event_logs WHERE evl_usr_user_id = ? AND evl_event = ?');
$q->execute(array($did, MailReadBudget::EVENT_SOFT));
check((int)$q->fetchColumn() === 1, 'past the soft line (2): one mail_read_budget_soft row');
MailReadBudget::$limit_overrides = array('soft' => 2, 'hard' => 0, 'app_hard' => 3, 'pages_hard' => 2);
MailReadBudget::messages($did, array($ids[0]), $B, 'rdbud-d-' . $suffix);
$q->execute(array($did, MailReadBudget::EVENT_SOFT));
check((int)$q->fetchColumn() === 1, 'and no second one in the same window');

// ---------------------------------------------------------------------------
section('0 turns a line off');

$off = MailReadBudget::messages($did, range($ids[10], $ids[19]), $B, 'rdbud-d-' . $suffix);
check(!$off['paused'] && $off['limit'] === 0 && $off['count'] === 16, 'hard 0: sixteen in the hour and no pause', json_encode($off));

// ---------------------------------------------------------------------------
section('the notice counts a program\'s messages, not its header fetches');

MailReadBudget::$limit_overrides = array('soft' => 2, 'hard' => 3, 'app_hard' => 10, 'pages_hard' => 2);
$pg = make_user('RdBuG' . $suffix, 0);
$gid = (int)$pg->key;
harness_defer(function () use ($db, $gid) {
	$db->prepare('DELETE FROM mrb_mail_read_ledger WHERE mrb_usr_user_id = ?')->execute(array($gid));
	$db->prepare("DELETE FROM evl_event_logs WHERE evl_usr_user_id = ? AND evl_event IN (?, ?)")
		->execute(array($gid, MailReadBudget::EVENT_SOFT, MailReadBudget::EVENT_HARD));
});
$soft_rows = function () use ($db, $gid): int {
	$q = $db->prepare('SELECT COUNT(*) FROM evl_event_logs WHERE evl_usr_user_id = ? AND evl_event = ?');
	$q->execute(array($gid, MailReadBudget::EVENT_SOFT));
	return (int)$q->fetchColumn();
};
MailReadBudget::messages($gid, array($ids[0], $ids[1]), $A);
MailReadBudget::page($gid, $A);
$g = MailReadBudget::page($gid, $A);
check(!$g['paused'] && $g['count'] === 4 && $soft_rows() === 0,
	'two messages and two header fetches: four against the app line, no notice (two messages is not past two)', json_encode($g));
MailReadBudget::messages($gid, array($ids[2]), $A);
check($soft_rows() === 1, 'the third message is past the notice line: one notice row (reviewer2 B4)');

// ---------------------------------------------------------------------------
section('the credential kind follows what the request was authenticated as');

check(MailReadBudget::credentialKind() === MailReadBudget::CREDENTIAL_BROWSER, 'a CLI (no key) is the browser kind');
RequestLogger::set_api_key_type(ApiKey::TYPE_SESSION);
check(MailReadBudget::credentialKind() === MailReadBudget::CREDENTIAL_DEVICE, 'a session key is a device');
RequestLogger::set_api_key_type(ApiKey::TYPE_APP_PASSWORD);
check(MailReadBudget::credentialKind() === MailReadBudget::CREDENTIAL_APP, 'an app password is a program');
RequestLogger::set_api_key_type(null);

harness_finish();
