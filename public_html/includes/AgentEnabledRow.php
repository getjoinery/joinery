<?php
/**
 * The agent_enabled row, which no code in this release reads. The agent runs
 * on every machine and the setting has no declaration.
 *
 * An agent binary older than 1.106.0 still reads the row every five seconds,
 * and exits when it is missing. So the row goes only once the agent installed
 * on this machine is 1.106.0 or newer, or there is none. update_database asks
 * on every run, so the row goes at the first run after the agent updates, and
 * declared_settings_test accepts it exactly while it is still needed.
 *
 * @version 1.0
 */
class AgentEnabledRow {

	const NAME = 'agent_enabled';

	/** The first agent that does not read the row. */
	const FIRST_AGENT_WITHOUT_IT = '1.106.0';

	const AGENT_BINARY = '/usr/local/bin/joinery-agent';

	/**
	 * The installed agent's version: '' when there is no agent, null when
	 * there is one whose version cannot be read.
	 */
	public static function installed_agent_version(): ?string {
		if (!is_file(self::AGENT_BINARY)) {
			return '';
		}
		$line = trim((string)shell_exec(escapeshellarg(self::AGENT_BINARY) . ' --version 2>/dev/null'));
		return preg_match('/(\d+\.\d+\.\d+)/', $line, $m) ? $m[1] : null;
	}

	/** Does an agent on this machine still read the row? */
	public static function still_needed(): bool {
		$version = self::installed_agent_version();
		if ($version === '') {
			return false;
		}
		return $version === null || version_compare($version, self::FIRST_AGENT_WITHOUT_IT, '<');
	}

	/**
	 * Delete the row when nothing on this machine reads it. Returns what
	 * happened, as one line; '' when there was no row.
	 */
	public static function remove_when_safe(): string {
		$db = DbConnector::get_instance()->get_db_link();
		$read = $db->prepare('SELECT 1 FROM stg_settings WHERE stg_name = ?');
		$read->execute(array(self::NAME));
		if ($read->fetchColumn() === false) {
			return '';
		}
		if (self::still_needed()) {
			$version = self::installed_agent_version();
			return 'agent_enabled: kept — the agent here is ' . ($version !== null ? $version : 'of unknown version')
				. ', which reads it and stops when it is missing; it goes at the first update after the agent updates';
		}
		$del = $db->prepare('DELETE FROM stg_settings WHERE stg_name = ?');
		$del->execute(array(self::NAME));
		return 'agent_enabled: deleted — nothing on this machine reads it';
	}
}
