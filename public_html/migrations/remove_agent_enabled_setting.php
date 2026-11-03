<?php
/**
 * Delete the agent_enabled row. Nothing in this release reads it: the agent
 * runs on every machine, and the setting has no declaration.
 *
 * An agent binary older than 1.106.0 does read it, every five seconds, and
 * exits when the row is missing. So the row is deleted only when the agent
 * installed on this machine is 1.106.0 or newer, or there is none. A machine
 * whose agent is older keeps the row, set on, which is harmless.
 *
 * Idempotent: once the row is gone there is nothing to delete.
 */
function remove_agent_enabled_setting() {
	$db = DbConnector::get_instance()->get_db_link();

	$read = $db->prepare('SELECT 1 FROM stg_settings WHERE stg_name = ?');
	$read->execute(array('agent_enabled'));
	if ($read->fetchColumn() === false) {
		return true;
	}

	$binary = '/usr/local/bin/joinery-agent';
	if (is_file($binary)) {
		$line = trim((string)shell_exec(escapeshellarg($binary) . ' --version 2>/dev/null'));
		$version = preg_match('/(\d+\.\d+\.\d+)/', $line, $m) ? $m[1] : '';
		if ($version === '' || version_compare($version, '1.106.0', '<')) {
			echo "  agent_enabled: kept — the agent here is " . ($version !== '' ? $version : 'of unknown version')
				. ", which reads it and stops when it is missing\n";
			return true;
		}
	}

	$del = $db->prepare('DELETE FROM stg_settings WHERE stg_name = ?');
	$del->execute(array('agent_enabled'));
	echo "  agent_enabled: deleted — nothing on this machine reads it\n";
	return true;
}
?>
