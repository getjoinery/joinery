<?php
/**
 * HostConvergerNotice — the admin-header notice on a box whose host converger
 * has not run (specs/host_converger.md).
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
 * written by the runner on every run). Silent when it ran within a day, and
 * silent on a box that never had one — a container converges at every start
 * and a managed node through its upgrade job.
 *
 * @version 1.0
 */
class HostConvergerNotice {

	/** A run older than this is a dead converger. */
	const STALE_AFTER = 86400;

	public static function render(): string {
		if ((int)($_SESSION['permission'] ?? 0) < 10) {
			return '';
		}
		$facts = self::facts();
		return self::forState($facts['installed'], $facts['last_run'], $facts['outcome'], time(), self::installCommand());
	}

	/**
	 * The stored facts: installed (a unit or cron entry exists), last_run
	 * (unix time of the last run, null when none), outcome (what that run
	 * recorded).
	 */
	public static function facts(?string $site_root = null): array {
		$site_root = $site_root ?? PathHelper::getSiteRoot();
		$installed = is_file('/etc/systemd/system/joinery-host-converger.timer')
			|| is_file('/etc/cron.d/joinery-host-converger');
		$last_run = null;
		$outcome = '';
		$raw = @file_get_contents($site_root . '/cache/host_converger.last');
		if (is_string($raw) && preg_match('/^(\d+)\s*(\S*)/', trim($raw), $m)) {
			$last_run = (int)$m[1];
			$outcome = $m[2];
		}
		return array('installed' => $installed, 'last_run' => $last_run, 'outcome' => $outcome);
	}

	/** The notice for one set of facts. Public and pure so the wording can be tested. */
	public static function forState(bool $installed, ?int $last_run, string $outcome, int $now, string $command): string {
		if (!$installed) {
			return '';
		}
		$fresh = $last_run !== null && ($now - $last_run) < self::STALE_AFTER;
		if ($fresh && $outcome !== 'installer-failed') {
			return '';
		}
		if ($last_run === null) {
			$lead = 'The host converger has never run on this box.';
		} elseif (!$fresh) {
			$lead = 'The host converger has not run since ' . gmdate('Y-m-d H:i', $last_run) . ' UTC.';
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
