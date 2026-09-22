<?php
/** @joinery-test
 * name: mailbox_index_legacy_blob
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The File era's index copies that no File holds any more are counted and
 * reclaimed (specs/implemented/mailbox_search_index_blob_leak.md).
 *
 * Before the index moved to one path per owner, every persist uploaded a
 * private File, so its bytes sit in the upload directory as
 * mailfts_{uid}_{token}.bin. Deleting the File rows reclaims those; what that
 * cannot reach is a copy with no File row: a blob whose reference leaked (one
 * such 77 MiB copy was left on a node after its File records were reclaimed)
 * or a file no row names at all. Pinned here:
 *
 *  - the health check names both shapes, not merely counts them;
 *  - the sweep reclaims both: the leaked blob's row and bytes go, the bare
 *    file goes;
 *  - a copy a live File holds is never touched, whatever its age;
 *  - a copy younger than an hour is left alone (an upload stages its bytes
 *    before the rows that hold them).
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('data/file_blobs_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_mailbox_search_index_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailHealth.php'));

$db = DbConnector::get_instance()->get_db_link();
$upload_dir = rtrim((string)Globalvars::get_instance()->get_setting('upload_dir'), '/');
$owner = make_user('IndexLegacyBlob', 0);
$tag = 2147480000 + random_int(0, 999);   // a uid no real owner has

$age_blob = function ($blob_id) use ($db) {
	$q = $db->prepare("UPDATE fbb_file_blobs SET fbb_create_time = now() - interval '2 hours' WHERE fbb_file_blob_id = ?");
	$q->execute(array((int)$blob_id));
};

// ------------------------------------------------- fixtures

section('fixtures: a leaked blob, a bare file, a held copy, a young file');

// A leaked blob: its File row is gone, its reference count is still one.
$leaked_file = File::createFromBytes(random_bytes(2048), 'mailfts_' . $tag . '.bin',
	'application/octet-stream', (int)$owner->key,
	array('fil_private' => true, 'fil_source' => File::SOURCE_MAILBOX_SEARCH_INDEX));
$leaked_blob_id = (int)$leaked_file->get('fil_fbb_file_blob_id');
$leaked_blob = new FileBlob($leaked_blob_id, TRUE);
$leaked_name = (string)$leaked_blob->get('fbb_stored_name');
$leaked_path = $leaked_blob->filesystem_path('original');
harness_register_row('fbb_file_blobs', 'fbb_file_blob_id', $leaked_blob_id);
harness_defer(function () use ($leaked_path) { if (is_file($leaked_path)) { @unlink($leaked_path); } });
$q = $db->prepare('DELETE FROM fil_files WHERE fil_file_id = ?');
$q->execute(array((int)$leaked_file->key));
$age_blob($leaked_blob_id);
check(is_file($leaked_path) && preg_match(InboundMailboxSearchIndex::LEGACY_BLOB_NAME, $leaked_name) === 1,
	'the leaked copy has an index-shaped name and bytes on disk', $leaked_path);

// A held copy: the same shape, but a live File row still references it.
$held_file = File::createFromBytes(random_bytes(1024), 'mailfts_' . ($tag + 1) . '.bin',
	'application/octet-stream', (int)$owner->key,
	array('fil_private' => true, 'fil_source' => File::SOURCE_MAILBOX_SEARCH_INDEX));
harness_register_row('fil_files', 'fil_file_id', $held_file->key);
$held_blob_id = (int)$held_file->get('fil_fbb_file_blob_id');
$held_blob = new FileBlob($held_blob_id, TRUE);
$held_name = (string)$held_blob->get('fbb_stored_name');
$held_path = $held_blob->filesystem_path('original');
$age_blob($held_blob_id);
touch($held_path, time() - 7200);

// A bare file no row names, old enough to be nobody's upload in flight.
$bare_name = 'mailfts_' . ($tag + 2) . '_zz' . LibraryFunctions::random_string(6) . '.bin';
$bare_path = $upload_dir . '/' . $bare_name;
file_put_contents($bare_path, random_bytes(512));
touch($bare_path, time() - 7200);
harness_defer(function () use ($bare_path) { if (is_file($bare_path)) { @unlink($bare_path); } });

// A young bare file: it may be an upload whose rows are about to be written.
$young_name = 'mailfts_' . ($tag + 3) . '_zz' . LibraryFunctions::random_string(6) . '.bin';
$young_path = $upload_dir . '/' . $young_name;
file_put_contents($young_path, random_bytes(256));
harness_defer(function () use ($young_path) { if (is_file($young_path)) { @unlink($young_path); } });

// ------------------------------------------------- the count

section('the dry run and the health check name both unheld shapes');

$dry = InboundMailboxSearchIndex::sweepLegacyBlobs(true);
check(in_array($leaked_name, $dry['names'], true), 'the leaked blob is counted', implode(', ', $dry['names']));
check(in_array($bare_name, $dry['names'], true), 'the bare file is counted', implode(', ', $dry['names']));
check(!in_array($held_name, $dry['names'], true), 'the held copy is not counted');
check(!in_array($young_name, $dry['names'], true), 'the young file is not counted');
check(is_file($leaked_path) && is_file($bare_path), 'the dry run removed nothing');
check($dry['bytes'] >= 2048 + 512, 'the dry run adds up what it would reclaim', 'bytes=' . $dry['bytes']);

$failed = '';
try { InboundEmailHealth::checkSearchIndexStorage(); }
catch (Throwable $e) { $failed = $e->getMessage(); }
check(strpos($failed, $leaked_name) !== false && strpos($failed, $bare_name) !== false,
	'the health check fails naming both', $failed);

// ------------------------------------------------- the sweep

section('the standing sweep reclaims them and nothing a row holds');

$swept = InboundMailboxSearchIndex::sweepWorkingCopies();
check(strpos($swept['message'], $leaked_name) !== false && strpos($swept['message'], $bare_name) !== false,
	'the sweep says what it reclaimed', $swept['message']);
check(!(new FileBlob($leaked_blob_id, TRUE))->key, 'the leaked blob row is gone');
check(!is_file($leaked_path), 'and its bytes with it');
check(!is_file($bare_path), 'the bare file is gone');
check(is_file($young_path), 'the young file is left alone');
$held_again = new File((int)$held_file->key, TRUE);
check($held_again->key && is_file($held_path)
	&& (int)(new FileBlob($held_blob_id, TRUE))->get('fbb_reference_count') === 1,
	'the held copy, its row and its bytes are untouched');

$after = InboundMailboxSearchIndex::sweepLegacyBlobs(true);
check(!in_array($leaked_name, $after['names'], true) && !in_array($bare_name, $after['names'], true),
	'a second count finds neither');

harness_finish();
