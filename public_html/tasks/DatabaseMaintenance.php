<?php
require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

/**
 * Nightly VACUUM / ANALYZE of whichever tables need it, read from PostgreSQL's
 * own statistics (DatabaseUpkeep). Runs at 03:30, after RetentionSweep has
 * deleted the day's expired rows and before the 04:00 backups.
 *
 * @version 1.0
 */
class DatabaseMaintenance implements ScheduledTaskInterface, ScheduledTaskDryRunnable {

	public function run(array $config) {
		$started = microtime(true);
		$r = DatabaseUpkeep::run();
		$vacuumed = count(array_filter($r['done'], function ($s) { return $s['action'] === 'vacuum'; }));
		$analyzed = count($r['done']) - $vacuumed;
		$message = 'Vacuumed ' . $vacuumed . ', analyzed ' . $analyzed . ' in '
			. round(microtime(true) - $started, 1) . ' s';
		if ($r['deferred']) {
			$message .= '; ' . $r['deferred'] . ' left for the next run';
		}
		$slowest = $r['done'];
		usort($slowest, function ($a, $b) { return $b['ms'] <=> $a['ms']; });
		if ($slowest && $slowest[0]['ms'] >= 1000) {
			$message .= '; longest ' . $slowest[0]['table'] . ' ' . round($slowest[0]['ms'] / 1000, 1) . ' s';
		}
		if ($r['failed']) {
			$names = array_map(function ($s) { return $s['table'] . ' (' . $s['error'] . ')'; }, array_slice($r['failed'], 0, 3));
			return array('status' => 'error', 'message' => $message . '; failed: ' . implode('; ', $names));
		}
		return array('status' => 'success', 'message' => $message);
	}

	public function dryRun(array $config) {
		$steps = DatabaseUpkeep::plan(DatabaseUpkeep::tableStats());
		if (!$steps) {
			return array('status' => 'success', 'message' => 'Every table is current; nothing would run');
		}
		$vacuum = count(array_filter($steps, function ($s) { return $s['action'] === 'vacuum'; }));
		$html = '<table class="table table-sm"><thead><tr><th>Table</th><th>Would</th><th>Because</th></tr></thead><tbody>';
		foreach ($steps as $s) {
			$html .= '<tr><td>' . htmlspecialchars($s['table']) . '</td><td>' . htmlspecialchars($s['action'])
				. '</td><td>' . htmlspecialchars($s['reason']) . '</td></tr>';
		}
		$html .= '</tbody></table>';
		return array('status' => 'success',
			'message' => 'Would vacuum ' . $vacuum . ' and analyze ' . (count($steps) - $vacuum) . ' tables',
			'html' => $html);
	}
}
