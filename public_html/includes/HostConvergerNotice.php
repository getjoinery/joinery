<?php
/**
 * HostConvergerNotice — the admin-header notice on a box whose host converger
 * has not run (specs/implemented/host_converger.md).
 *
 * A self-hosted box upgrades from the browser as the web user; the root half
 * of an upgrade (declared PHP extensions, the host installers) is done by the
 * host converger, a root timer installed at first install. When that timer
 * stops, the code keeps landing and the host silently stops following it.
 * This names the fact to the admin who can fix it, with the one command.
 * Information, never a gate.
 *
 * Reads two stored facts: whether a converger is installed on this machine
 * (its unit or cron file exists) and when it last ran (cache/host_converger.last,
 * written by the runner on every run). Silent when it ran within a day, silent
 * while one installed minutes ago waits for its first tick, and silent on a box
 * that never had one — a container converges at every start
 * and a managed node through its upgrade job.
 *
 * @version 1.2 - One rule for whether the converger is keeping up, runState(), which the root-request
 *                warning and VaultHealth read too. A converger installed minutes ago that has not had its
 *                first tick is starting, not dead: every new site's upload pages said its root actor had
 *                not run recently until the first minute passed. A run just past a day old is the daily
 *                run about to happen, not a dead converger: STALE_AFTER gives it an hour.
 * @version 1.1 - A run that stopped because the host's data root is not mounted (data-root-not-ready,
 *                specs/one_data_root.md D3) is named, with the command that says why; it was read
 *                as a healthy run and the notice stayed silent while nothing converged.
 * @version 1.0
 */
class HostConvergerNotice {

	/**
	 * A run older than this is a dead converger. The runner converges again once
	 * its last run is a day old (CONVERGE_MAX_AGE in _plugin_installers_start.sh),
	 * at its next tick; the hour is that run's room, so a healthy box never reads
	 * as dead at the turn of its day.
	 */
	const STALE_AFTER = 90000;

	/**
	 * A converger installed this recently that has never run is waiting for its
	 * first tick, not dead. Ticks are a minute apart; the margin covers a box
	 * still busy with the install that put the converger there.
	 */
	const FIRST_RUN_GRACE = 900;

	/** What installs a converger: a systemd timer, or a cron entry where PID 1 is not systemd. */
	const UNIT_FILES = array('/etc/systemd/system/joinery-host-converger.timer', '/etc/cron.d/joinery-host-converger');

	public static function render(): string {
		if ((int)($_SESSION['permission'] ?? 0) < 10) {
			return '';
		}
		$facts = self::facts();
		return self::forState($facts['installed'], $facts['last_run'], $facts['outcome'], time(),
			self::installCommand(), (int)($facts['pending_requests'] ?? 0), $facts['oldest_request_age'] ?? null,
			$facts['installed_time']);
	}

	/**
	 * Whether the converger is keeping up, from facts():
	 *   absent   - none installed
	 *   starting - installed under FIRST_RUN_GRACE ago and not run yet
	 *   never    - installed longer ago than that and never run
	 *   stale    - its last run is STALE_AFTER old or more
	 *   fresh    - it ran within STALE_AFTER
	 */
	public static function runState(array $facts, int $now): string {
		if (empty($facts['installed'])) {
			return 'absent';
		}
		$last = $facts['last_run'] ?? null;
		if ($last === null) {
			$since = $facts['installed_time'] ?? null;
			return ($since !== null && $now - (int)$since < self::FIRST_RUN_GRACE) ? 'starting' : 'never';
		}
		return ($now - (int)$last) < self::STALE_AFTER ? 'fresh' : 'stale';
	}

	/**
	 * The stored facts: installed (a unit or cron entry exists), installed_time
	 * (when that file was last written, null when none), last_run (unix time of
	 * the last run, null when none), outcome (what that run recorded).
	 */
	public static function facts(?string $site_root = null): array {
		$site_root = $site_root ?? PathHelper::getSiteRoot();
		$installed = false;
		$installed_time = null;
		foreach (self::UNIT_FILES as $unit) {
			if (is_file($unit)) {
				$installed = true;
				$t = @filemtime($unit);
				if ($t !== false) {
					$installed_time = max((int)$installed_time, (int)$t);
				}
			}
		}
		$last_run = null;
		$outcome = '';
		$raw = @file_get_contents($site_root . '/cache/host_converger.last');
		if (is_string($raw) && preg_match('/^(\d+)\s*(\S*)/', trim($raw), $m)) {
			$last_run = (int)$m[1];
			$outcome = $m[2];
		}
		// The converger is also the root actor that carries out root requests, so
		// the queue's depth and the age of its oldest entry are facts about the
		// same machine (specs/implemented/read_only_tree.md). Read here so the notice, the
		// health check and the test runner's `needs` all see one set of facts.
		$pending = array();
		$oldest_age = null;
		if (class_exists('RootRequest')) {
			$pending = RootRequest::pending();
			$oldest_age = RootRequest::oldest_pending_age();
		}

		return array(
			'installed'          => $installed,
			'installed_time'     => $installed_time,
			'last_run'           => $last_run,
			'outcome'            => $outcome,
			'pending_requests'   => count($pending),
			'oldest_request_age' => $oldest_age,
		);
	}

	/** The notice for one set of facts. Public and pure so the wording can be tested. */
	public static function forState(bool $installed, ?int $last_run, string $outcome, int $now,
			string $command, int $pending = 0, ?int $oldest_age = null, ?int $installed_time = null): string {
		$run = self::runState(array('installed' => $installed, 'installed_time' => $installed_time, 'last_run' => $last_run), $now);
		if ($run === 'absent' || $run === 'starting') {
			return '';
		}
		$fresh = $run === 'fresh';

		// A queue that is not moving is its own alarm, even on a converger that
		// is otherwise healthy: the timer can tick while every request it picks
		// up fails, and an upgrade that quietly never happened is worse than one
		// that visibly failed.
		if ($fresh && $outcome !== 'installer-failed' && $outcome !== 'installer-refused'
			&& $oldest_age !== null && $oldest_age >= 3600) {
			$hours = max(1, (int)round($oldest_age / 3600));
			return self::css()
				. '<div class="jy-converger-notice" role="status">'
				. '<div class="jy-converger-notice__text"><strong>'
				. htmlspecialchars($pending . ' root request' . ($pending === 1 ? '' : 's')
					. ' ' . ($pending === 1 ? 'has' : 'have') . ' been waiting up to '
					. $hours . ' hour' . ($hours === 1 ? '' : 's') . '.', ENT_QUOTES, 'UTF-8')
				. '</strong> '
				. htmlspecialchars('The host converger is running, so the requests themselves are failing. '
					. 'Upgrades, extension installs and doc saves queued from the browser are not being carried out; '
					. 'the transcripts are in logs/root_requests/ on the host.', ENT_QUOTES, 'UTF-8')
				. '</div></div>';
		}

		if ($fresh && $outcome === 'data-root-not-ready') {
			$status = self::dataRootStatusCommand();
			return self::css()
				. '<div class="jy-converger-notice" role="status">'
				. '<div class="jy-converger-notice__text"><strong>'
				. htmlspecialchars('This host\'s data root, /srv/joinery, is not mounted, so the host converger is running nothing.', ENT_QUOTES, 'UTF-8')
				. '</strong> '
				. htmlspecialchars('Every service whose data lives there waits for it. Run this on the host as root to see why:', ENT_QUOTES, 'UTF-8')
				. ' <code class="jy-converger-notice__cmd">' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</code></div>'
				. '</div>';
		}
		if ($fresh && $outcome !== 'installer-failed' && $outcome !== 'installer-refused') {
			return '';
		}
		if ($last_run === null) {
			$lead = 'The host converger has never run on this box.';
		} elseif (!$fresh) {
			$lead = 'The host converger has not run since ' . gmdate('Y-m-d H:i', $last_run) . ' UTC.';
		} elseif ($outcome === 'installer-refused') {
			$lead = 'The host converger refused an installer it could not attribute.';
		} else {
			$lead = 'The host converger\'s last run reported a failed installer.';
		}
		$body = 'Upgrades land the code, but the root half — PHP extensions, the parser jail, plugin services — '
			. 'is applied by a root timer, and it is not doing so. Run this on the host as root to reinstall it:';
		return self::css()
			. '<div class="jy-converger-notice" role="status">'
			. '<div class="jy-converger-notice__text"><strong>' . htmlspecialchars($lead, ENT_QUOTES, 'UTF-8') . '</strong> '
			. htmlspecialchars($body, ENT_QUOTES, 'UTF-8')
			. ' <code class="jy-converger-notice__cmd">' . htmlspecialchars($command, ENT_QUOTES, 'UTF-8') . '</code></div>'
			. '</div>';
	}

	/** The command that says what is wrong with the host's data root. */
	public static function dataRootStatusCommand(): string {
		return 'sudo bash ' . PathHelper::getSiteRoot() . '/maintenance_scripts/install_tools/joinery_data_root.sh status';
	}

	/** The one command that installs or repairs the converger on this machine. */
	public static function installCommand(): string {
		return 'sudo bash ' . PathHelper::getSiteRoot() . '/maintenance_scripts/install_tools/install_host_converger.sh';
	}

	private static function css(): string {
		static $sent = false;
		if ($sent) return '';
		$sent = true;
		return '<style>'
			. '.jy-converger-notice{margin:0 0 1rem;padding:.75rem 1rem;border:1px solid #fcd34d;border-radius:6px;background:#fffbeb;color:#78350f;font-size:.95rem}'
			. '.jy-converger-notice__cmd{display:inline-block;margin-top:.25rem;padding:.15rem .4rem;border-radius:4px;background:#fef3c7;font-size:.9em;user-select:all;overflow-wrap:anywhere}'
			. '</style>';
	}
}
