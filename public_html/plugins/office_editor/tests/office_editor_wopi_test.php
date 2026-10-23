<?php
/** @joinery-test
 * name: office_editor_wopi
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The WOPI host the document editor talks to (OfficeEditorWopi), driven
 * in-process: a test proof key stands in for the editor's, so every request
 * here is signed the way the editor signs one.
 *
 * Covers spec § 5.4: token mint/verify, expiry, wrong file and wrong user,
 * a request that did not come in on the loopback listener, a bad proof,
 * permission revoked and level changed mid-session, quota, the timestamp
 * conflict, and one version per editing span — including two saves in one
 * span with the first answer's time fed back, a sync upload landing inside a
 * span, and two people sharing one span.
 *
 * The plugin need not be active: its files are loaded here directly. Its
 * table must exist (installed).
 */
if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

foreach (array('OfficeEditor', 'OfficeEditorToken', 'OfficeEditorWopi', 'WopiProof') as $c) {
	if (!class_exists($c, false)) {
		require_once(PathHelper::getIncludePath('plugins/office_editor/includes/' . $c . '.php'));
	}
}
if (!class_exists('OfficeEditorSession', false)) {
	require_once(PathHelper::getIncludePath('plugins/office_editor/data/office_editor_sessions_class.php'));
}
$dblink = DbConnector::get_instance()->get_db_link();
if (!$dblink->query("SELECT to_regclass('oes_office_editor_sessions')")->fetchColumn()) {
	harness_skip('office editor table', 'the office_editor plugin is not installed here');
	harness_finish();
}

// ---------------------------------------------------------------------------
section('Setup');

$made_files = array();
harness_defer(function () use (&$made_files) {
	foreach ($made_files as $fid) {
		$q = DbConnector::get_instance()->get_db_link()->prepare('SELECT 1 FROM fil_files WHERE fil_file_id = ?');
		$q->execute(array((int)$fid));
		if ($q->fetchColumn()) { (new File((int)$fid, true))->permanent_delete(); }
	}
});

function oe_tier(array $features) {
	$dblink = DbConnector::get_instance()->get_db_link();
	$suffix = bin2hex(random_bytes(4));
	$gq = $dblink->prepare("INSERT INTO grp_groups (grp_name, grp_category) VALUES (?, ?) RETURNING grp_group_id");
	$gq->execute(array('office-editor-test-' . $suffix, 'subscription_tier'));
	$grp = (int)$gq->fetchColumn();
	harness_register_row('grp_groups', 'grp_group_id', $grp);
	$tq = $dblink->prepare(
		"INSERT INTO sbt_subscription_tiers (sbt_grp_group_id, sbt_tier_level, sbt_name, sbt_display_name, sbt_features, sbt_is_active)
		 VALUES (?, ?, ?, ?, ?::jsonb, TRUE) RETURNING sbt_subscription_tier_id");
	$tq->execute(array($grp, 900, 'office-editor-test-' . $suffix, 'Office Editor Test', json_encode($features)));
	harness_register_row('sbt_subscription_tiers', 'sbt_subscription_tier_id', (int)$tq->fetchColumn());
	return $grp;
}
function oe_join($grp, $user) {
	$q = DbConnector::get_instance()->get_db_link()->prepare("INSERT INTO grm_group_members (grm_grp_group_id, grm_foreign_key_id) VALUES (?, ?) RETURNING grm_group_member_id");
	$q->execute(array((int)$grp, (int)$user->key));
	harness_register_row('grm_group_members', 'grm_group_member_id', $q->fetchColumn());
}

$grp = oe_tier(array('office_editing' => true, 'drive_versioning_depth' => 5));
$owner = make_user('oe_owner');
$other = make_user('oe_other');
$editor = make_user('oe_editor');
foreach (array($owner, $other, $editor) as $u) { oe_join($grp, $u); }
OfficeEditor::forgetMembers();
check(OfficeEditor::memberMay($owner->key), 'the owner\'s tier carries office_editing');

function oe_file($owner_id, $title = 'Paper.docx', $bytes = null) {
	global $made_files;
	$f = File::createFromBytes($bytes !== null ? $bytes : 'docx-bytes-' . bin2hex(random_bytes(12)), $title,
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document', $owner_id,
		array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE));
	$made_files[] = $f->key;
	return DriveHelper::load_file($f->key);
}

// The editor's proof key, made here.
$rsa = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
$details = openssl_pkey_get_details($rsa);
$proof_key = array('modulus' => base64_encode($details['rsa']['n']), 'exponent' => base64_encode($details['rsa']['e']));
OfficeEditor::$discovery_override = array('actions' => array('docx' => 'http://127.0.0.1:9980/browser/abc/cool.html?'), 'proof' => $proof_key);
harness_defer(function () { OfficeEditor::$discovery_override = null; });

/**
 * Make a request as the editor would: on the loopback listener, signed, with
 * the token in the query string. Returns [status, body].
 */
function oe_request($method, $file_id, $contents, $token, array $headers = array(), $body = null, array $opts = array()) {
	global $rsa;
	$uri = '/office_editor/wopi/files/' . (int)$file_id . ($contents ? '/contents' : '') . '?access_token=' . $token;
	$ticks = WopiProof::ticksFor(isset($opts['time']) ? $opts['time'] : time());
	$signed_url = isset($opts['signed_url']) ? $opts['signed_url'] : OfficeEditor::WOPI_BASE . $uri;
	openssl_sign(WopiProof::signedBytes($token, $signed_url, $ticks), $sig, isset($opts['key']) ? $opts['key'] : $rsa, OPENSSL_ALGO_SHA256);

	$saved = array($_SERVER, $_GET);
	$_SERVER['REQUEST_METHOD'] = $method;
	$_SERVER['REQUEST_URI'] = $uri;
	$_SERVER['SERVER_ADDR'] = isset($opts['server_addr']) ? $opts['server_addr'] : '127.0.0.1';
	if (!isset($opts['no_listener'])) { $_SERVER['JOINERY_OFFICE_WOPI'] = '1'; } else { unset($_SERVER['JOINERY_OFFICE_WOPI']); }
	$_SERVER['HTTP_X_WOPI_TIMESTAMP'] = (string)$ticks;
	$_SERVER['HTTP_X_WOPI_PROOF'] = base64_encode($sig);
	$_SERVER['HTTP_X_WOPI_PROOFOLD'] = base64_encode($sig);
	foreach ($headers as $k => $v) { $_SERVER[$k] = $v; }
	$_GET = array('access_token' => $token);
	$tmp_input = null;
	ob_start();
	try {
		if ($body !== null) {
			// php://input cannot be fed in the CLI: the save is driven through
			// OfficeEditorWopi::save() for bodies (oe_put below).
			throw new LogicException('use oe_put for a body');
		}
		OfficeEditorWopi::$last_status = null;
		OfficeEditorWopi::handle((int)$file_id, $contents);
	} finally {
		$out = ob_get_clean();
		list($_SERVER, $_GET) = $saved;
	}
	return array(OfficeEditorWopi::$last_status, $out);
}

/** A PutFile's save step with $bytes, as putFile() hands it over. */
function oe_put($file_id, $user, $bytes, $timestamp = null, $exit = false) {
	$tmp = tempnam(sys_get_temp_dir(), 'oe_put_test_');
	file_put_contents($tmp, $bytes);
	try {
		$file = DriveHelper::load_file($file_id);
		return OfficeEditorWopi::save($file, $user, $tmp, $timestamp, $exit);
	} finally {
		if (is_file($tmp)) { @unlink($tmp); }
	}
}
function oe_versions($file_id) {
	return (new MultiFileVersion(array('file_id' => (int)$file_id)))->count_all();
}
function oe_head_bytes($file_id) {
	return DriveHelper::load_file($file_id)->read_bytes();
}

// ---------------------------------------------------------------------------
section('Token: mint and verify');

$file = oe_file($owner->key);
$t = OfficeEditorToken::mint($owner->key, $file->key, true, 'https://example.org');
check(preg_match('/^[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+$/', $t['token']) === 1, 'the token is URL-safe as it stands');
$c = OfficeEditorToken::verify($t['token'], $file->key);
check($c !== null && $c['user_id'] === (int)$owner->key && $c['can_write'] === true && $c['origin'] === 'https://example.org', 'it verifies with its claims');
check(OfficeEditorToken::verify($t['token'], $file->key + 1) === null, 'not for another file');
$parts = explode('.', $t['token']);
check(OfficeEditorToken::verify($parts[0] . '.' . strrev($parts[1]), $file->key) === null, 'not with a tampered signature');
$expired = OfficeEditorToken::mint($owner->key, $file->key, true, 'https://example.org', 1);
sleep(2);
check(OfficeEditorToken::verify($expired['token'], $file->key) === null, 'not once expired');
$bad_origin = OfficeEditorToken::mint($owner->key, $file->key, true, 'javascript:alert(1)');
check(OfficeEditorToken::verify($bad_origin['token'], $file->key) === null, 'not with an origin that is not an origin');

// ---------------------------------------------------------------------------
section('Who may call');

list($st) = oe_request('GET', $file->key, false, $t['token'], array(), null, array('no_listener' => true));
check($st === 403, 'a request that did not come in on the listener is refused (' . $st . ')');
list($st) = oe_request('GET', $file->key, false, $t['token'], array(), null, array('server_addr' => '203.0.113.9'));
check($st === 403, 'one that arrived on a public address is refused (' . $st . ')');
list($st) = oe_request('GET', $file->key, false, $t['token'], array(), null, array('server_addr' => '::1'));
check($st === 200, 'IPv6 loopback is the listener too (' . $st . ')');
list($st) = oe_request('GET', $file->key, false, 'nonsense.token');
check($st === 401, 'a bad token is 401 (' . $st . ')');
$wrong_key = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
list($st) = oe_request('GET', $file->key, false, $t['token'], array(), null, array('key' => $wrong_key));
check($st === 403, 'a proof signed by another key is refused (' . $st . ')');
list($st) = oe_request('GET', $file->key, false, $t['token'], array(), null, array('time' => time() - 3600));
check($st === 403, 'a proof an hour old is refused (' . $st . ')');
list($st) = oe_request('GET', $file->key, false, $t['token'], array(), null, array('signed_url' => 'http://127.0.0.1:9981/office_editor/wopi/files/999999'));
check($st === 403, 'a proof for another URL is refused (' . $st . ')');
$other_file = oe_file($owner->key, 'Other.docx');
list($st) = oe_request('GET', $other_file->key, false, $t['token']);
check($st === 401, 'a token for one file does not open another (' . $st . ')');
$t_other = OfficeEditorToken::mint($other->key, $file->key, true, 'https://example.org');
list($st) = oe_request('GET', $file->key, false, $t_other['token']);
check($st === 404, 'a token for someone with no access to the file opens nothing (' . $st . ')');

// ---------------------------------------------------------------------------
section('CheckFileInfo and GetFile');

list($st, $out) = oe_request('GET', $file->key, false, $t['token']);
$info = json_decode($out, true);
check($st === 200 && $info['BaseFileName'] === 'Paper.docx' && $info['UserCanWrite'] === true, 'CheckFileInfo names the file and allows writing');
check($info['PostMessageOrigin'] === 'https://example.org', 'PostMessageOrigin is the page origin the token was minted for');
check($info['UserCanNotWriteRelative'] === true && $info['SupportsLocks'] === false, 'no Save As, no locks');
check(preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(\.\d+)?Z$/', (string)$info['LastModifiedTime']) === 1, 'LastModifiedTime is ISO 8601 (' . $info['LastModifiedTime'] . ')');
$t_view = OfficeEditorToken::mint($owner->key, $file->key, false, 'https://example.org');
list(, $out) = oe_request('GET', $file->key, false, $t_view['token']);
check(json_decode($out, true)['UserCanWrite'] === false, 'a view-only token reads UserCanWrite false');

list($st, $out) = oe_request('GET', $file->key, true, $t['token']);
check($st === 200 && $out === oe_head_bytes($file->key), 'GetFile returns the head bytes');
$span = OfficeEditorSession::current($file->key);
check($span !== null && $span['head_blob_id'] === 0, 'loading the file starts its span');

// ---------------------------------------------------------------------------
section('One editing span is one version');

$v0 = oe_versions($file->key);
$r1 = oe_put($file->key, $owner, 'first save ' . bin2hex(random_bytes(8)), $info['LastModifiedTime']);
check($r1['status'] === 200 && !empty($r1['body']['LastModifiedTime']), 'the first save is accepted and answers its new time');
check($r1['body']['LastModifiedTime'] !== $info['LastModifiedTime'], 'the time moved');
check(oe_versions($file->key) === $v0 + 1, 'the first save demoted the loaded head to a version');
$r2 = oe_put($file->key, $owner, 'second save ' . bin2hex(random_bytes(8)), $r1['body']['LastModifiedTime']);
check($r2['status'] === 200, 'the second save, carrying the first answer\'s time, is accepted');
$r3 = oe_put($file->key, $owner, 'third save ' . bin2hex(random_bytes(8)), $r2['body']['LastModifiedTime']);
check($r3['status'] === 200 && oe_versions($file->key) === $v0 + 1, 'autosaves in one span add no versions');
check(strpos(oe_head_bytes($file->key), 'third save') === 0, 'the head holds the last save');
$r_same = oe_put($file->key, $owner, oe_head_bytes($file->key), $r3['body']['LastModifiedTime']);
check($r_same['status'] === 200 && $r_same['body']['LastModifiedTime'] === $r3['body']['LastModifiedTime'], 'saving the bytes already there changes nothing');

section('Two people in one span');
FileAccessGrant::sync_for_entity(DriveHelper::ENTITY_FILE, $file->key, array($editor->key => 'editor'), $owner->key);
$r4 = oe_put($file->key, $editor, 'editor save ' . bin2hex(random_bytes(8)), $r3['body']['LastModifiedTime']);
check($r4['status'] === 200 && oe_versions($file->key) === $v0 + 1, 'a save made with the other participant\'s token stays in the same version');
$r5 = oe_put($file->key, $owner, 'owner again ' . bin2hex(random_bytes(8)), $r4['body']['LastModifiedTime'], true);
check($r5['status'] === 200 && oe_versions($file->key) === $v0 + 1, 'the exit save too');
check(OfficeEditorSession::current($file->key) === null, 'the exit save ends the span');

section('A new span is a new version');
oe_request('GET', $file->key, true, $t['token']);
list(, $out) = oe_request('GET', $file->key, false, $t['token']);
$lm = json_decode($out, true)['LastModifiedTime'];
$r6 = oe_put($file->key, $owner, 'next day ' . bin2hex(random_bytes(8)), $lm);
check($r6['status'] === 200 && oe_versions($file->key) === $v0 + 2, 'the next span\'s first save keeps the last span\'s result as a version');

// ---------------------------------------------------------------------------
section('Changed underneath');

$stale = oe_put($file->key, $owner, 'stale ' . bin2hex(random_bytes(8)), $lm);
check($stale['status'] === 409 && ($stale['body']['COOLStatusCode'] ?? 0) === 1010, 'a save carrying an old time is a conflict (1010)');

// A sync client's upload lands inside a span, after the editor's last save.
$conflict_file = oe_file($owner->key, 'Budget.docx');
oe_request('GET', $conflict_file->key, true, $t2 = OfficeEditorToken::mint($owner->key, $conflict_file->key, true, 'https://example.org')['token']);
list(, $out) = oe_request('GET', $conflict_file->key, false, $t2);
$lm = json_decode($out, true)['LastModifiedTime'];
$tmp = tempnam(sys_get_temp_dir(), 'oe_sync_');
file_put_contents($tmp, 'from a laptop ' . bin2hex(random_bytes(8)));
FileVersion::save_new_content($conflict_file, FileBlob::createFromPath($tmp, 'application/octet-stream', true), $owner->key);
$r = oe_put($conflict_file->key, $owner, 'editor copy ' . bin2hex(random_bytes(8)), $lm);
check($r['status'] === 409, 'the editor\'s first save after a sync upload is a conflict');
check(strpos(oe_head_bytes($conflict_file->key), 'from a laptop') === 0, 'and the laptop\'s copy is still the head');
$versions_before = oe_versions($conflict_file->key);
$r = oe_put($conflict_file->key, $owner, 'keep mine ' . bin2hex(random_bytes(8)), null);
check($r['status'] === 200 && strpos(oe_head_bytes($conflict_file->key), 'keep mine') === 0, 'keeping my copy (no timestamp) saves it');
check(oe_versions($conflict_file->key) === $versions_before + 1, 'and the laptop\'s copy is kept as a version');

// No span at all (the last person's exit save ended it while the editor still
// held the document, and someone else attached): a checked save is still
// guarded, and a stale one is refused.
$nospan = oe_file($owner->key, 'NoSpan.docx');
$tn = OfficeEditorToken::mint($owner->key, $nospan->key, true, 'https://example.org')['token'];
list(, $out) = oe_request('GET', $nospan->key, false, $tn);
$lm_ns = json_decode($out, true)['LastModifiedTime'];
OfficeEditorSession::end($nospan->key);
$ok = oe_put($nospan->key, $owner, 'no span ' . bin2hex(random_bytes(8)), $lm_ns);
check($ok['status'] === 200, 'a checked save with no span goes in');
$stale_ns = oe_put($nospan->key, $owner, 'stale no span ' . bin2hex(random_bytes(8)), $lm_ns);
check($stale_ns['status'] === 409, 'and a stale one with no span is refused');

// A later save whose head is not the one the span wrote.
$race2 = oe_file($owner->key, 'Race2.docx');
$tk = OfficeEditorToken::mint($owner->key, $race2->key, true, 'https://example.org')['token'];
oe_request('GET', $race2->key, true, $tk);
list(, $out) = oe_request('GET', $race2->key, false, $tk);
$ra = oe_put($race2->key, $owner, 'span save ' . bin2hex(random_bytes(8)), json_decode($out, true)['LastModifiedTime']);
OfficeEditorSession::recordSave($race2->key, 1); // the span "wrote" some other blob
$rb = oe_put($race2->key, $owner, 'later ' . bin2hex(random_bytes(8)), $ra['body']['LastModifiedTime']);
check($rb['status'] === 409, 'a later save whose head is not the span\'s is refused where it is written');

// ---------------------------------------------------------------------------
section('Permission and level asked on every request');

$shared = oe_file($owner->key, 'Shared.docx');
FileAccessGrant::sync_for_entity(DriveHelper::ENTITY_FILE, $shared->key, array($editor->key => 'editor'), $owner->key);
$te = OfficeEditorToken::mint($editor->key, $shared->key, true, 'https://example.org')['token'];
list($st) = oe_request('GET', $shared->key, false, $te);
check($st === 200, 'the editor-role member opens the shared file');
FileAccessGrant::sync_for_entity(DriveHelper::ENTITY_FILE, $shared->key, array(), $owner->key);
list($st) = oe_request('GET', $shared->key, false, $te);
check($st === 404, 'after the share is revoked the same token opens nothing (' . $st . ')');
FileAccessGrant::sync_for_entity(DriveHelper::ENTITY_FILE, $shared->key, array($editor->key => 'viewer'), $owner->key);
list(, $out) = oe_request('GET', $shared->key, false, $te);
check(json_decode($out, true)['UserCanWrite'] === false, 'downgraded to viewer, the write token no longer writes');

File::updateColumns((int)$shared->key, array('fil_protection_level' => ProtectionLevel::PRIVATE_));
list($st) = oe_request('GET', $shared->key, false, $t_own = OfficeEditorToken::mint($owner->key, $shared->key, true, 'https://example.org')['token']);
check($st === 403, 'a file that went Private mid-session is refused (' . $st . ')');
File::updateColumns((int)$shared->key, array('fil_protection_level' => ProtectionLevel::STANDARD));

$trash_me = oe_file($owner->key, 'Gone.docx');
$tt = OfficeEditorToken::mint($owner->key, $trash_me->key, true, 'https://example.org')['token'];
$trash_me->soft_delete();
list($st) = oe_request('GET', $trash_me->key, false, $tt);
check($st === 404, 'a trashed file is gone to the editor (' . $st . ')');

// ---------------------------------------------------------------------------
section('Quota');

$small = oe_tier(array('office_editing' => true, 'drive_storage_bytes' => 4096));
$tight = make_user('oe_tight');
oe_join($small, $tight);
OfficeEditor::forgetMembers();
$qf = oe_file($tight->key, 'Small.docx', str_repeat('a', 1000));
DriveUsage::recompute($tight->key);
$r = oe_put($qf->key, $tight, str_repeat('b', 8000), null);
check($r['status'] === 413, 'a save past the quota is refused as too large (' . $r['status'] . ')');
check(oe_head_bytes($qf->key) === str_repeat('a', 1000), 'and nothing was written');
check(OfficeEditorSession::refusal($qf->key) === 'quota', 'the refusal is recorded for the page to report');

section('What the page is told after a save');
require_once(PathHelper::getIncludePath('tests/lib/logic.php'));
SessionControl::get_instance()->set_api_user($owner->key);
$state = harness_call_logic('plugins/office_editor/logic/office_editor_save_state_logic.php', 'office_editor_save_state_logic', array('file_id' => $conflict_file->key));
check($state->data['saved'] === true, 'a file whose last save landed reads saved');
$stale_again = oe_put($conflict_file->key, $owner, 'stale again ' . bin2hex(random_bytes(8)), '2000-01-01T00:00:00Z');
check($stale_again['status'] === 409, 'a stale save is refused');
$state = harness_call_logic('plugins/office_editor/logic/office_editor_save_state_logic.php', 'office_editor_save_state_logic', array('file_id' => $conflict_file->key));
check($state->data['saved'] === false && $state->data['refusal'] === 'conflict' && strpos($state->data['message'], 'changed somewhere else') !== false, 'and the page is told why');
$fine = oe_put($conflict_file->key, $owner, 'fine ' . bin2hex(random_bytes(8)), null);
$state = harness_call_logic('plugins/office_editor/logic/office_editor_save_state_logic.php', 'office_editor_save_state_logic', array('file_id' => $conflict_file->key));
check($fine['status'] === 200 && $state->data['saved'] === true, 'a save that lands clears it');
SessionControl::get_instance()->clear_api_user();

harness_finish();
