<?php
/**
 * How long a browser-held vault stays unlocked is a core setting,
 * vault_client_autolock_minutes, shared by the password vault and Fortress
 * folders. The password vault plugin's vault_autolock_minutes row is what is
 * left behind. A site that had changed it keeps its choice: the value moves to
 * the core setting when that still holds the factory default. Then the old row
 * goes, so every stored setting stays declared.
 *
 * Idempotent: once the old row is gone there is nothing to carry or delete.
 */
function vault_client_autolock_setting() {
	$db = DbConnector::get_instance()->get_db_link();

	$read = $db->prepare('SELECT stg_value FROM stg_settings WHERE stg_name = ?');
	$read->execute(array('vault_autolock_minutes'));
	$old = $read->fetchColumn();
	if ($old === false) {
		return true;
	}
	$old = trim((string)$old);

	$read->execute(array('vault_client_autolock_minutes'));
	$current = $read->fetchColumn();
	if ((int)$old > 0 && $old !== '15' && ($current === false || trim((string)$current) === '15')) {
		if ($current === false) {
			$db->prepare('INSERT INTO stg_settings (stg_name, stg_value, stg_group_name) VALUES (?, ?, ?)')
				->execute(array('vault_client_autolock_minutes', $old, 'vault_unlock'));
		} else {
			$db->prepare('UPDATE stg_settings SET stg_value = ? WHERE stg_name = ?')
				->execute(array($old, 'vault_client_autolock_minutes'));
		}
		echo "  Carried vault_autolock_minutes ({$old}) to vault_client_autolock_minutes.\n";
	}

	$del = $db->prepare('DELETE FROM stg_settings WHERE stg_name = ?');
	$del->execute(array('vault_autolock_minutes'));
	if ($del->rowCount() > 0) {
		echo "  Removed retired setting vault_autolock_minutes.\n";
	}
	return true;
}
?>
