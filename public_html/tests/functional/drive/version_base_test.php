<?php
/** @joinery-test
 * name: drive_version_base
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * A new version says what it was based on, and a head that moved refuses it.
 *
 * Two devices saving one file in the same window must meet as a conflict.
 * Without a base the later arrival silently replaced the earlier one, which
 * then survived only as a version nobody was told about (B10). With one:
 *   - a head still holding the base takes the version;
 *   - a head holding other content refuses, `head_moved`, at init before a byte
 *     is sent and at completion where the version is written, giving back the
 *     blob reference and the staged upload, and charging nothing;
 *   - a head already holding the upload's content is a landed retry: success,
 *     no new version row, nothing charged;
 *   - an encrypted file's metadata blob rewritten since (a rename) refuses
 *     `moved_since`, and the new blob is written with the version.
 *
 * Run: php tests/functional/drive/version_base_test.php
 */
if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('logic/drive_upload_init_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_upload_complete_logic.php'));

harness_set_setting_mem('drive_active', '1');

$made_files = array();
$owner = make_user('versionbase');
harness_defer(function () use (&$made_files) {
	foreach ($made_files as $fid) { $f = new File((int)$fid, true); if ($f->key) { $f->permanent_delete(); } }
});
$session = SessionControl::get_instance();
$session->set_api_user($owner->key);
harness_defer(function () use ($session) { $session->clear_api_user(); });

/** Simulate a fully-received chunk upload: write the part file and mark it complete. */
function version_base_fill($raw_token, $content) {
	$up = FileUpload::load_by_token($raw_token);
	$part = $up->part_path();
	$dir = dirname($part);
	if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
	file_put_contents($part, $content);
	$up->set('fup_received_bytes', strlen($content));
	$up->set('fup_update_time', gmdate('Y-m-d H:i:s'));
	$up->save();
}

/**
 * A new version through the chunked path, with whatever base it is given. It
 * names its hash at init as a sync client does, unless $named is false — the
 * one way to reach completion with bytes the owner already holds.
 */
function version_base_upload($fid, $content, array $base = array(), $named = true) {
	$asked = array('file_id' => $fid, 'size_bytes' => strlen($content));
	if ($named) {
		$asked['sha256'] = hash('sha256', $content);
	}
	$init = drive_upload_init_logic(array_merge($asked, $base));
	if ($init->error !== null) {
		return array('stage' => 'init', 'result' => $init, 'token' => null);
	}
	version_base_fill($init->data['upload_token'], $content);
	$done = drive_upload_complete_logic(array_merge(array('upload_token' => $init->data['upload_token']), $base));
	return array('stage' => 'complete', 'result' => $done, 'token' => $init->data['upload_token']);
}

// A written version records a content change; a refusal or a landed retry
// records none. Counted here rather than version rows, which the owner's
// retained depth (none, with no plan) prunes the moment they are made.
$versions = function ($fid) {
	$q = DbConnector::get_instance()->get_db_link()->prepare(
		"SELECT COUNT(*) FROM fch_file_changes WHERE fch_entity_type = 'file' AND fch_entity_id = ? AND fch_change_kind = 'content'");
	$q->execute(array((int)$fid));
	return (int)$q->fetchColumn();
};
$head_sha = function ($fid) { DriveHelper::forget_sync_meta($fid); return DriveHelper::sync_meta_for($fid)['content_sha256']; };
$head_blob = function ($fid) { return (int)(new File((int)$fid, true))->get('fil_fbb_file_blob_id'); };
$refs = function ($blob_id) {
	$q = DbConnector::get_instance()->get_db_link()->prepare('SELECT fbb_reference_count FROM fbb_file_blobs WHERE fbb_file_blob_id = ?');
	$q->execute(array((int)$blob_id));
	$n = $q->fetchColumn();
	return $n === false ? null : (int)$n;
};
$blobs_holding = function ($content) {
	$q = DbConnector::get_instance()->get_db_link()->prepare('SELECT COUNT(*) FROM fbb_file_blobs WHERE fbb_sha256 = ?');
	$q->execute(array(hash('sha256', $content)));
	return (int)$q->fetchColumn();
};
$usage = function () use ($owner) { return (int)DriveUsage::recompute($owner->key); };

$v1 = 'agreed-' . bin2hex(random_bytes(8));
$i1 = drive_upload_init_logic(array('name' => 'base_' . bin2hex(random_bytes(3)) . '.txt', 'size_bytes' => strlen($v1)));
version_base_fill($i1->data['upload_token'], $v1);
$d1 = drive_upload_complete_logic(array('upload_token' => $i1->data['upload_token']));
$fid = (int)($d1->data['file']['id'] ?? 0);
if ($fid) { $made_files[] = $fid; }
check($fid > 0, 'the file exists', var_export($d1->error, true));

section('a head still holding the base takes the version');

$mine = 'mine-' . bin2hex(random_bytes(8));
$up = version_base_upload($fid, $mine, array('base_sha256' => hash('sha256', $v1)));
check($up['result']->error === null, 'the version lands', var_export($up['result']->error, true));
check($head_sha($fid) === hash('sha256', $mine), 'and is the head');
check($versions($fid) === 1, 'one content change is recorded', (string)$versions($fid));

section('a head that moved before a byte is sent refuses at init');

$stale = array('base_sha256' => hash('sha256', $v1));
$early = version_base_upload($fid, 'late-' . bin2hex(random_bytes(8)), $stale);
check($early['stage'] === 'init' && ($early['result']->data['reason'] ?? null) === 'head_moved',
	'refused head_moved before the bytes', var_export($early['result']->data ?? null, true));
check(($early['result']->data['head_sha256'] ?? null) === hash('sha256', $mine), 'naming the head as it stands');
check(empty($early['result']->data['standing']), 'and not standing: the client re-plans rather than holds');

section('a head that moved while the bytes were in flight refuses at completion');

$theirs = 'theirs-' . bin2hex(random_bytes(8));
$late = 'raced-' . bin2hex(random_bytes(8));
$init = drive_upload_init_logic(array('file_id' => $fid, 'size_bytes' => strlen($late), 'base_sha256' => hash('sha256', $mine)));
check($init->error === null, 'init accepts a base that still holds', var_export($init->error, true));
version_base_fill($init->data['upload_token'], $late);
// The peer's save lands between this upload's init and its completion.
$peer = version_base_upload($fid, $theirs, array('base_sha256' => hash('sha256', $mine)));
check($peer['result']->error === null, "the peer's save lands first", var_export($peer['result']->error, true));
$before = array('versions' => $versions($fid), 'usage' => $usage(), 'head_refs' => $refs($head_blob($fid)));
$refused = drive_upload_complete_logic(array('upload_token' => $init->data['upload_token'], 'base_sha256' => hash('sha256', $mine)));
check(($refused->data['reason'] ?? null) === 'head_moved', 'the completion is refused head_moved', var_export($refused->data ?? $refused->error, true));
check($head_sha($fid) === hash('sha256', $theirs), "the peer's save is still the head");
check($versions($fid) === $before['versions'], 'no version was written', $versions($fid) . ' vs ' . $before['versions']);
check($blobs_holding($late) === 0, "the refused bytes' blob was given back, not left pinned", (string)$blobs_holding($late));
check($usage() === $before['usage'], 'nothing more is charged to the owner', $usage() . ' vs ' . $before['usage']);
check($refs($head_blob($fid)) === $before['head_refs'], "the head blob's references are unchanged");
check(FileUpload::load_by_token($init->data['upload_token']) === null, 'the staged upload is discarded');

section('a head already holding the upload is a landed retry, not a move');

$before = array('versions' => $versions($fid), 'usage' => $usage(), 'head_refs' => $refs($head_blob($fid)));
// The same bytes again on a base that has since moved past them: what a retry
// after a lost answer sends. Dedup finds the head itself.
$retry = drive_upload_init_logic(array('file_id' => $fid, 'size_bytes' => strlen($theirs),
	'sha256' => hash('sha256', $theirs), 'base_sha256' => hash('sha256', $mine)));
check($retry->error === null, 'it succeeds', var_export($retry->error ?? null, true));
check(($retry->data['file']['content_sha256'] ?? null) === hash('sha256', $theirs), 'with the head it already holds');
check($versions($fid) === $before['versions'], 'and no new version', $versions($fid) . ' vs ' . $before['versions']);
check($refs($head_blob($fid)) === $before['head_refs'], 'the reference dedup took is given back', $refs($head_blob($fid)) . ' vs ' . $before['head_refs']);
check($usage() === $before['usage'], 'nothing more is charged');
// Through the chunked path as well, where the blob is new and must go back.
$again = version_base_upload($fid, $theirs, array('base_sha256' => hash('sha256', $mine)), false);
check($again['result']->error === null, 'the chunked retry succeeds too', var_export($again['result']->error, true));
check($versions($fid) === $before['versions'], 'with no new version');
check($blobs_holding($theirs) === 1, 'and no second copy of the bytes kept', (string)$blobs_holding($theirs));

section('no base is the unconditional replace a browser makes');

$browser = 'browser-' . bin2hex(random_bytes(8));
$plain = version_base_upload($fid, $browser);
check($plain['result']->error === null && $head_sha($fid) === hash('sha256', $browser), 'a version without a base replaces the head');

section('an encrypted file: a metadata blob rewritten since refuses, and the new one is written with the version');

// At the model, on the stored column the guard reads: what a rename leaves.
$file = new File($fid, true);
$dblink = DbConnector::get_instance()->get_db_link();
$set_metadata = function ($blob) use ($dblink, $fid) {
	$q = $dblink->prepare('UPDATE fil_files SET fil_encrypted_metadata = ? WHERE fil_file_id = ?');
	$q->execute(array($blob, $fid));
};
$read_metadata = function () use ($dblink, $fid) {
	$q = $dblink->prepare('SELECT fil_encrypted_metadata FROM fil_files WHERE fil_file_id = ?');
	$q->execute(array($fid));
	return (string)$q->fetchColumn();
};
harness_defer(function () use ($set_metadata) { $set_metadata(null); });
$set_metadata('sealed-by-a-rename');
$next = File::createFromBytes('next-' . bin2hex(random_bytes(8)), 'scratch.txt', 'text/plain', $owner->key, array('fil_private' => true));
$made_files[] = $next->key;
$blob = new FileBlob((int)$next->get('fil_fbb_file_blob_id'), true);
FileBlob::retain($blob->key);
$threw = null;
try {
	FileVersion::save_new_content($file, $blob, $owner->key, array(
		'base_metadata_sha256' => hash('sha256', 'sealed-before-the-rename'),
		'encrypted_metadata' => 'sealed-from-the-old-name',
	));
} catch (FileHeadMovedException $e) {
	$threw = $e;
}
if ($threw) { FileBlob::release($blob->key); }
check($threw !== null && $threw->reason === 'moved_since', 'a blob rewritten since refuses moved_since');
check($read_metadata() === 'sealed-by-a-rename', "the rename's blob survives");
FileBlob::retain($blob->key);
$wrote = FileVersion::save_new_content($file, $blob, $owner->key, array(
	'base_metadata_sha256' => hash('sha256', 'sealed-by-a-rename'),
	'encrypted_metadata' => 'sealed-after-the-rename',
));
check($wrote === true && $read_metadata() === 'sealed-after-the-rename', 'the blob it was sealed from takes it, with its new blob');

harness_finish();
