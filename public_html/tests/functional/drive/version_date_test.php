<?php
/** @joinery-test
 * name: drive_version_date
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * A new version's date is the uploading client's, or none.
 *
 * modified_time in a file's export is the uploading client's own date for the
 * content (docs/drive.md). A new version from a client that sends none (the
 * browser) has none; keeping the previous version's date handed every computer
 * the edit stamped with the old time, and a sync client that applies the
 * server's date to what it downloads put that old time on new bytes.
 *
 * Both paths a version takes are covered: upload_init's dedup short-circuit
 * (bytes the owner already holds) and the ordinary upload_complete.
 *
 * Run: php tests/functional/drive/version_date_test.php
 */
if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('logic/drive_upload_init_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_upload_complete_logic.php'));

harness_set_setting_mem('drive_active', '1');

$made_files = array();
$owner = make_user('versiondate');
harness_defer(function () use (&$made_files) {
	foreach ($made_files as $fid) { $f = new File((int)$fid, true); if ($f->key) { $f->permanent_delete(); } }
});
$session = SessionControl::get_instance();
$session->set_api_user($owner->key);
harness_defer(function () use ($session) { $session->clear_api_user(); });

/** Simulate a fully-received chunk upload: write the part file and mark it complete. */
function version_date_fill($raw_token, $content) {
	$up = FileUpload::load_by_token($raw_token);
	$part = $up->part_path();
	$dir = dirname($part);
	if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
	file_put_contents($part, $content);
	$up->set('fup_received_bytes', strlen($content));
	$up->set('fup_update_time', gmdate('Y-m-d H:i:s'));
	$up->save();
}

$date_of = function ($fid) {
	DriveHelper::forget_sync_meta($fid);
	return DriveHelper::file_export(new File($fid, true))['modified_time'];
};

section('a new version sent without a date carries none, not the previous one');

$v1 = 'dated-' . bin2hex(random_bytes(6));
$i1 = drive_upload_init_logic(array('name' => 'dated_' . bin2hex(random_bytes(3)) . '.txt',
	'size_bytes' => strlen($v1), 'modified_time' => '2021-03-04T05:06:07Z'));
version_date_fill($i1->data['upload_token'], $v1);
$d1 = drive_upload_complete_logic(array('upload_token' => $i1->data['upload_token']));
$fid = (int)($d1->data['file']['id'] ?? 0);
if ($fid) { $made_files[] = $fid; }
check($fid > 0 && strpos((string)$date_of($fid), '2021-03-04 05:06:07') === 0, 'the first version carries the client date',
	var_export($fid ? $date_of($fid) : $d1->error, true));

$v2 = 'undated-' . bin2hex(random_bytes(6));
$i2 = drive_upload_init_logic(array('file_id' => $fid, 'size_bytes' => strlen($v2)));
version_date_fill($i2->data['upload_token'], $v2);
$d2 = drive_upload_complete_logic(array('upload_token' => $i2->data['upload_token']));
check($d2->error === null, 'a new version sent without a date lands', var_export($d2->error, true));
$after = $date_of($fid);
check($after === null || $after === '', "and carries no date, not the previous version's", var_export($after, true));

section('the dedup short-circuit takes the same rule');

// Bytes the owner already holds in other files complete at init without an
// upload; one holder per step, since a version history may keep nothing.
$holder = function ($content) use ($owner, &$made_files) {
	$f = File::createFromBytes($content, 'holder_' . bin2hex(random_bytes(3)) . '.txt', 'text/plain', $owner->key,
		array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE));
	$made_files[] = $f->key;
	return $content;
};
$v3 = $holder('held-three-' . bin2hex(random_bytes(6)));
$v4 = $holder('held-four-' . bin2hex(random_bytes(6)));
$i3 = drive_upload_init_logic(array('file_id' => $fid, 'size_bytes' => strlen($v3),
	'sha256' => hash('sha256', $v3), 'modified_time' => '2022-01-02T03:04:05Z'));
check($i3->error === null && empty($i3->data['upload_token']), 'a version whose bytes the owner holds completes at init',
	var_export($i3->error ?? $i3->data, true));
check(strpos((string)$date_of($fid), '2022-01-02 03:04:05') === 0, 'with the date it sent', var_export($date_of($fid), true));
drive_upload_init_logic(array('file_id' => $fid, 'size_bytes' => strlen($v4), 'sha256' => hash('sha256', $v4)));
$after2 = $date_of($fid);
check($after2 === null || $after2 === '', 'and with none when it sends none', var_export($after2, true));

harness_finish();
