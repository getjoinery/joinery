<?php
/**
 * PluginInstallerStatus - what a plugin's host installer did the last time it
 * ran, for the Plugins page and utils/check_provisioning.php.
 *
 * Root writes one record per plugin on every run, however it was started (the
 * converger's pass, --only-plugin, a run_plugin_installer request, a managed
 * node's job): cache/plugin_installers/<plugin>.json and the run's output in
 * logs/plugin_installers/<plugin>.log, both root:www-data 640
 * (_plugin_installers_start.sh, plugin_installer_from_the_browser.md D2).
 * This class only reads them.
 *
 * @version 1.0
 */
class PluginInstallerStatus {

	const RECORD_DIR = 'cache/plugin_installers';
	const LOG_DIR    = 'logs/plugin_installers';

	/** How many lines of the log the page shows. */
	const TAIL_LINES = 20;

	/**
	 * The last run's record plus the last lines of its output, or null when no
	 * run has been recorded (or the name is not a plugin name).
	 *
	 * @return array{plugin:string, installer:string, started_at:string,
	 *               finished_at:string, outcome:string, exit_code:int,
	 *               release:string, tail:string}|null
	 */
	public static function forPlugin(string $name): ?array {
		if (preg_match('/^[a-z][a-z0-9_]{1,49}$/', $name) !== 1) {
			return null;
		}
		$root = PathHelper::getSiteRoot();
		$raw = @file_get_contents($root . '/' . self::RECORD_DIR . '/' . $name . '.json');
		if ($raw === false) {
			return null;
		}
		$record = json_decode($raw, true);
		if (!is_array($record) || !isset($record['outcome'])) {
			return null;
		}
		$out = array(
			'plugin'      => $name,
			'installer'   => (string)($record['installer'] ?? ''),
			'started_at'  => (string)($record['started_at'] ?? ''),
			'finished_at' => (string)($record['finished_at'] ?? ''),
			'outcome'     => (string)$record['outcome'],
			'exit_code'   => (int)($record['exit_code'] ?? 0),
			'release'     => (string)($record['release'] ?? ''),
			'tail'        => '',
		);
		$log = @file($root . '/' . self::LOG_DIR . '/' . $name . '.log', FILE_IGNORE_NEW_LINES);
		if (is_array($log)) {
			$out['tail'] = implode("\n", array_slice($log, -self::TAIL_LINES));
		}
		return $out;
	}

	/** Whether this outcome is one the page shows as a failure. */
	public static function isFailure(string $outcome): bool {
		return $outcome !== 'ok';
	}

	/**
	 * The one line the page and the CLI print: "Host setup: installed
	 * 2026-10-11 14:02 UTC (release 0.8.484)", "Host setup: failed ...", or
	 * "Host setup: not run yet" when $record is null.
	 */
	public static function statusLine(?array $record): string {
		if ($record === null) {
			return 'Host setup: not run yet';
		}
		$when = $record['finished_at'] !== '' ? self::readable($record['finished_at']) : '';
		$word = match ($record['outcome']) {
			'ok'                => 'installed',
			'failed'            => 'failed',
			'refused-untrusted' => 'refused (the installer is not a file this server runs as root)',
			'refused-unsigned'  => 'refused (not a package we built)',
			'missing'           => 'refused (the declared installer is missing)',
			default             => $record['outcome'],
		};
		$line = 'Host setup: ' . $word . ($when !== '' ? ' ' . $when : '');
		if ($record['outcome'] === 'ok' && $record['release'] !== '') {
			$line .= ' (release ' . $record['release'] . ')';
		}
		return $line;
	}

	/** 2026-10-11T14:02:33Z as 2026-10-11 14:02 UTC. */
	private static function readable(string $iso): string {
		return preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})/', $iso, $m) === 1 ? $m[1] . ' ' . $m[2] . ' UTC' : $iso;
	}
}
