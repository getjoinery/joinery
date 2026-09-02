<?php
/**
 * Export, then delete, the per-recipient message copies.
 *
 * A msg_messages row with no conversation is a copy of a group send (one per
 * recipient, plus a record row per send) from before group sends were email
 * campaigns. The Email row is the record of those sends; no member surface
 * renders the copies. They go to a JSON file in the site root — the directory
 * alongside public_html, outside the web root — and are then deleted.
 *
 * Refuses to delete anything the file does not hold: the delete runs only
 * after the export is written, re-read and counted. Idempotent: a second run
 * finds nothing to select and does nothing.
 *
 * The work is on LegacyMessageExport so a test can point it at a directory of
 * its own; the migration function is the one-line production call.
 */

class LegacyMessageExport {

	/**
	 * @param string $directory Where the export file goes.
	 * @return array ['exported' => int, 'deleted' => int, 'file' => string|null]
	 * @throws RuntimeException when the file cannot be written or does not
	 *         hold what was selected — nothing is deleted in that case.
	 */
	public static function run($directory) {
		$db = DbConnector::get_instance()->get_db_link();

		$rows = $db->query("SELECT * FROM msg_messages WHERE msg_cnv_conversation_id IS NULL ORDER BY msg_message_id")
			->fetchAll(PDO::FETCH_ASSOC);
		if (empty($rows)) {
			return array('exported' => 0, 'deleted' => 0, 'file' => null);
		}

		$directory = rtrim((string)$directory, '/');
		if ($directory === '' || !is_dir($directory) || !is_writable($directory)) {
			throw new RuntimeException('Export directory is not writable: ' . $directory . ' — nothing deleted.');
		}

		$base = $directory . '/legacy_message_export_' . gmdate('Y-m-d');
		$file = $base . '.json';
		for ($n = 2; file_exists($file); $n++) {
			$file = $base . '_' . $n . '.json';
		}

		$json = json_encode(array(
			'exported_at' => gmdate('c'),
			'table' => 'msg_messages',
			'selection' => 'msg_cnv_conversation_id IS NULL',
			'count' => count($rows),
			'rows' => $rows,
		), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
		if ($json === false) {
			throw new RuntimeException('Could not encode the export: ' . json_last_error_msg() . ' — nothing deleted.');
		}
		if (file_put_contents($file, $json, LOCK_EX) !== strlen($json)) {
			@unlink($file);
			throw new RuntimeException('Could not write ' . $file . ' — nothing deleted.');
		}
		@chmod($file, 0600);

		// Trust what is on disk, not what was meant to be written.
		$written = json_decode((string)file_get_contents($file), true);
		$ids = array_map('intval', array_column($rows, 'msg_message_id'));
		$written_ids = is_array($written) && isset($written['rows'])
			? array_map('intval', array_column($written['rows'], 'msg_message_id')) : array();
		if ($written_ids !== $ids) {
			throw new RuntimeException('Export file ' . $file . ' does not hold every selected row — nothing deleted.');
		}

		$stmt = $db->prepare("DELETE FROM msg_messages WHERE msg_cnv_conversation_id IS NULL AND msg_message_id = ANY(?)");
		$stmt->execute(array('{' . implode(',', $ids) . '}'));
		$deleted = $stmt->rowCount();

		return array('exported' => count($rows), 'deleted' => $deleted, 'file' => $file);
	}
}

function export_legacy_message_rows() {
	$result = LegacyMessageExport::run(PathHelper::getSiteRoot());
	if ($result['exported'] === 0) {
		echo "No per-recipient message copies to export.\n";
		return true;
	}
	echo "Exported {$result['exported']} rows to {$result['file']}; deleted {$result['deleted']}.\n";
	return true;
}
