<?php
/**
 * Messenger's old middle rung becomes Private plus the Nothing leaves unsealed
 * add-on (specs/implemented/protection_levels_fold.md § Data update).
 *
 * A conversation stored at 'guarded' was Private with its exits closed: no
 * message text in notifications, no unencrypted federation. The level becomes
 * 'private' and `cnv_sealed_exits_only` carries the rest, so nothing the
 * members were promised changes. The site default moves the same way:
 * `messenger_default_protection_level = 'guarded'` becomes 'private' with
 * `messenger_default_sealed_exits_only = '1'`.
 *
 * Sealed system messages already in a conversation's history ("... set this
 * conversation to Guarded") stay as written — sealed history is not rewritten.
 *
 * cnv_conversations is a core table, so it exists whether or not the messenger
 * plugin is active; this runs as a core migration for that reason, after the
 * core schema step has added cnv_sealed_exits_only (update_database's schema
 * verification stops the run before migrations if a core column is missing).
 * Core migrations have no "try again next run" outcome — a run either records
 * the migration done or fails and stops the ones after it — so the checks
 * below only ever record it done when nothing is left unconverted: no table,
 * or no row at the old value. A row at the old value with no column to carry
 * the add-on fails the migration, loudly, rather than being recorded as done.
 *
 * The settings are written through Setting::put, which accepts any setting
 * declared in a plugin.json on disk, active or not. A tree without the
 * messenger plugin on disk has no declaration and nothing that reads the
 * rows, so they are left alone.
 *
 * Idempotent: re-running finds no row and no setting still at 'guarded'.
 */
function messenger_sealed_exits_only_fold() {
	$db = DbConnector::get_instance()->get_db_link();

	$q = $db->prepare("SELECT to_regclass('cnv_conversations')");
	$q->execute();
	if ($q->fetchColumn() === null) {
		echo "  messenger fold: no conversations table here, nothing to convert\n";
	} else {
		$q = $db->prepare(
			"SELECT count(1) FROM information_schema.columns
			  WHERE table_name = 'cnv_conversations' AND column_name = 'cnv_sealed_exits_only'");
		$q->execute();
		$has_column = (int)$q->fetchColumn() > 0;
		if (!$has_column) {
			$q = $db->prepare("SELECT count(1) FROM cnv_conversations WHERE cnv_protection_level = 'guarded'");
			$q->execute();
			$waiting = (int)$q->fetchColumn();
			if ($waiting > 0) {
				throw new Exception('messenger fold: ' . $waiting . ' conversation(s) still at the old middle rung'
					. ' and cnv_sealed_exits_only is missing, so the add-on has nowhere to go.'
					. ' Run the schema update, then this migration again.');
			}
			echo "  messenger fold: no conversation at the old middle rung\n";
		} else {
			$q = $db->prepare(
				"UPDATE cnv_conversations
				    SET cnv_protection_level = 'private', cnv_sealed_exits_only = true
				  WHERE cnv_protection_level = 'guarded'");
			$q->execute();
			echo $q->rowCount() > 0
				? "  messenger fold: " . $q->rowCount() . " conversation(s) moved to Private with Nothing leaves unsealed\n"
				: "  messenger fold: no conversation at the old middle rung\n";
		}
	}

	$q = $db->prepare("SELECT stg_value FROM stg_settings WHERE stg_name = 'messenger_default_protection_level'");
	$q->execute();
	$default_level = $q->fetchColumn();
	if ($default_level !== false && strtolower(trim((string)$default_level)) === 'guarded') {
		if (!SettingsDeclarations::isDeclared('messenger_default_protection_level')
				|| !SettingsDeclarations::isDeclared('messenger_default_sealed_exits_only')) {
			echo "  messenger fold: messenger plugin not on disk, its new-conversation default left as stored\n";
		} else {
			Setting::put('messenger_default_protection_level', 'private');
			Setting::put('messenger_default_sealed_exits_only', '1');
			echo "  messenger fold: new-conversation default moved to Private with Nothing leaves unsealed\n";
		}
	} else {
		echo "  messenger fold: new-conversation default not at the old middle rung\n";
	}

	return true;
}
?>
