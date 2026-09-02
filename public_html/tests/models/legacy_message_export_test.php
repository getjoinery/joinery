<?php
/** @joinery-test
 * name: legacy_message_export
 * tier: test-db
 * env: dev-only
 * needs: [test-db]
 */
/**
 * migrations/export_legacy_message_rows.php — the per-recipient message copies
 * are written to a file and then deleted (specs/group_sends_one_row.md §3.7).
 *
 * Runs against the copied test database, whose msg_messages is empty, so the
 * only rows in play are the fixture's four legacy shapes and one conversation
 * message. The export file holds every legacy row, the rows are gone, the
 * conversation message is untouched, a second run is a no-op, and an
 * unwritable directory refuses without deleting.
 *
 * Run: php tests/run.php test-db --filter=legacy_message_export
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
harness_test_mode();

require_once(PathHelper::getIncludePath('migrations/export_legacy_message_rows.php'));

$db = DbConnector::get_instance()->get_db_link();

function lme_insert(array $row) {
	$db = DbConnector::get_instance()->get_db_link();
	$cols = array_keys($row);
	$sql = 'INSERT INTO msg_messages (' . implode(',', $cols) . ', msg_sent_time) VALUES ('
		. implode(',', array_fill(0, count($cols), '?')) . ', now()) RETURNING msg_message_id';
	$q = $db->prepare($sql);
	$q->execute(array_values($row));
	return (int)$q->fetchColumn();
}

function lme_count($where, array $params = array()) {
	$q = DbConnector::get_instance()->get_db_link()->prepare('SELECT COUNT(*) FROM msg_messages WHERE ' . $where);
	$q->execute($params);
	return (int)$q->fetchColumn();
}

$dir = sys_get_temp_dir() . '/lme_' . bin2hex(random_bytes(4));
mkdir($dir, 0700);
harness_defer(function () use ($dir) {
	foreach (glob($dir . '/*') ?: array() as $f) { @unlink($f); }
	@rmdir($dir);
});

try {
	$baseline = lme_count('msg_cnv_conversation_id IS NULL');
	check($baseline === 0, 'the test database starts with no legacy rows', "found $baseline");

	section('Fixture: the four legacy shapes and one conversation message');
	$record   = lme_insert(array('msg_usr_user_id_sender' => 1, 'msg_usr_user_id_recipient' => null, 'msg_context_type' => 'event', 'msg_context_id' => 7, 'msg_body' => 'record row'));
	$copy     = lme_insert(array('msg_usr_user_id_sender' => 1, 'msg_usr_user_id_recipient' => 2, 'msg_context_type' => 'event', 'msg_context_id' => 7, 'msg_body' => 'per-recipient copy'));
	$single   = lme_insert(array('msg_usr_user_id_sender' => 1, 'msg_usr_user_id_recipient' => 3, 'msg_body' => 'single-user send'));
	$orphan   = lme_insert(array('msg_usr_user_id_sender' => 1, 'msg_body' => 'orphan: neither recipient nor context'));
	$conv_id  = (int)$db->query("INSERT INTO cnv_conversations (cnv_create_time, cnv_update_time) VALUES (now(), now()) RETURNING cnv_conversation_id")->fetchColumn();
	$conv_msg = lme_insert(array('msg_usr_user_id_sender' => 1, 'msg_cnv_conversation_id' => $conv_id, 'msg_body' => 'conversation message'));
	$legacy_ids = array($record, $copy, $single, $orphan);
	check(lme_count('msg_cnv_conversation_id IS NULL') === 4, 'four legacy rows are in place');

	section('Refusal: unwritable directory deletes nothing');
	$threw = null;
	try {
		LegacyMessageExport::run($dir . '/does-not-exist');
	} catch (RuntimeException $e) {
		$threw = $e->getMessage();
	}
	check($threw !== null, 'an unwritable directory is refused', (string)$threw);
	check(lme_count('msg_cnv_conversation_id IS NULL') === 4, 'and no row was deleted');

	section('Export and delete');
	$result = LegacyMessageExport::run($dir);
	check($result['exported'] === 4, 'four rows exported', json_encode($result));
	check($result['deleted'] === 4, 'four rows deleted');
	check(is_string($result['file']) && is_file($result['file']), 'the export file exists', (string)$result['file']);
	$written = json_decode((string)file_get_contents($result['file']), true);
	$written_ids = array_map('intval', array_column($written['rows'] ?? array(), 'msg_message_id'));
	sort($written_ids); sort($legacy_ids);
	check($written_ids === $legacy_ids, 'the file holds every legacy row', json_encode($written_ids));
	$bodies = array_column($written['rows'] ?? array(), 'msg_body');
	check(in_array('per-recipient copy', $bodies, true) && in_array('orphan: neither recipient nor context', $bodies, true), 'rows carry their columns');
	check(lme_count('msg_cnv_conversation_id IS NULL') === 0, 'no legacy row remains');
	check(lme_count('msg_message_id = ?', array($conv_msg)) === 1, 'the conversation message is untouched');
	check(strpos(basename($result['file']), 'legacy_message_export_') === 0, 'the file is named for what it is');

	section('Second run is a no-op');
	$again = LegacyMessageExport::run($dir);
	check($again === array('exported' => 0, 'deleted' => 0, 'file' => null), 'nothing selected, nothing written', json_encode($again));
	check(count(glob($dir . '/*.json')) === 1, 'no second file');
	check(lme_count('msg_message_id = ?', array($conv_msg)) === 1, 'the conversation message is still there');

	section('The migration entry');
	require_once(PathHelper::getIncludePath('data/migrations_class.php'));
	$entry = null;
	foreach (Migration::loadMigrations() as $m) {
		if (($m['migration_file'] ?? '') === 'export_legacy_message_rows.php') { $entry = $m; }
	}
	check($entry !== null, 'migrations.php names the file');
	if ($entry) {
		$count = (int)$db->query($entry['test'])->fetchColumn();
		check($count === 1, 'with no legacy rows left, the test query says skip', "count=$count");
	}

	$db->exec("DELETE FROM msg_messages WHERE msg_message_id = $conv_msg");
	$db->exec("DELETE FROM cnv_conversations WHERE cnv_conversation_id = $conv_id");

} catch (\Throwable $e) {
	check(false, 'no exception', get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();
