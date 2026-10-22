<?php
/**
 * IncidentSourceOldCodeServing - plane:old_code_serving: a node is still
 * serving code an update replaced (specs/fleet_security_patching.md WP6).
 *
 * On 2026-10-21 every site container kept serving the unpatched PHP after its
 * packages were upgraded, because nothing restarted its php-fpm. This raises
 * (critical) when:
 *
 *   - a php-fpm master runs a binary an update replaced (check_status's php,
 *     running_current false), ten minutes after its package was installed: an
 *     install in progress reloads it on its own;
 *   - needrestart names PHP-FPM, Apache or PostgreSQL as running replaced code
 *     (host_report's services_needing_restart: their unit names, or on a
 *     machine without needrestart their binaries' paths), ten minutes after
 *     the automatic updates last ran.
 *
 * A site container sends no host report, so its old code is seen through its
 * php-fpm alone. That is the one service whose package does not restart it
 * without systemd (2026-10-21); Apache's and PostgreSQL's go through
 * invoke-rc.d, which their init scripts answer in a container.
 *
 * Clears when a report shows neither.
 *
 * @version 1.1 - a machine without needrestart reports binaries, which match too (reviewer2 d)
 * @version 1.0
 */
class IncidentSourceOldCodeServing implements IncidentSource {

	const NAME = 'plane:old_code_serving';

	/** An update in progress is given this long to reload what it replaced. */
	const GRACE_SECONDS = 600;

	/** The services whose old code a site serves. */
	const SITE_SERVICES = '/^(php[0-9.]*-fpm|apache2|postgresql)(@[A-Za-z0-9.-]+)?\.service$/';

	/** The same, as host_report's /proc fallback names them: binary paths. */
	const SITE_BINARIES = '#(^|/)(php-fpm[0-9.]*|apache2|postgres)$#';

	public function name(): string {
		return self::NAME;
	}

	public function evaluate(ManagedNode $node, ?int $now = null): ?array {
		$now = $now ?? time();
		$old = array();

		$sd = $node->get('mgn_last_status_data');
		if (is_string($sd)) { $sd = json_decode($sd, true); }
		$php = (is_array($sd) && is_array($sd['php'] ?? null)) ? JobResultProcessor::status_php($sd['php']) : null;
		if ($php) {
			$installed = array();
			foreach ($php['packages'] as $p) {
				if (is_int($p['installed_at'])) { $installed[$p['name']] = $p['installed_at']; }
			}
			foreach ($php['fpm'] as $m) {
				if ($m['running_current'] !== false) { continue; }
				$bin = $m['binary'] !== '' ? basename($m['binary']) : 'php-fpm';
				// php-fpm8.5 is package php8.5-fpm.
				$pkg = preg_match('/^php-fpm([0-9.]+)$/', $bin, $mm) ? 'php' . $mm[1] . '-fpm' : '';
				$at = $installed[$pkg] ?? null;
				if ($at !== null && $now - $at < self::GRACE_SECONDS) { continue; }
				$old[] = $bin . ($at !== null ? ' (its package installed ' . gmdate('Y-m-d H:i', $at) . ' UTC)' : '');
			}
		}

		$report = $node->get('mgn_last_host_report');
		if (is_string($report)) { $report = json_decode($report, true); }
		$hr = is_array($report) ? JobResultProcessor::sanitise_host_report($report) : null;
		$snr = is_array($hr) ? ($hr['services_needing_restart'] ?? null) : null;
		if (is_array($snr) && $snr['services']) {
			$ran = $hr['unattended_upgrades_last_run'] ?? null;
			if (!is_int($ran) || $now - $ran >= self::GRACE_SECONDS) {
				foreach ($snr['services'] as $svc) {
					if (preg_match(self::SITE_SERVICES, $svc) || preg_match(self::SITE_BINARIES, $svc)) { $old[] = $svc; }
				}
			}
		}

		if (!$old) {
			return null;
		}
		return array(
			'title'    => 'Old code is still serving: ' . implode(', ', array_slice($old, 0, 4)),
			'severity' => IncidentRecord::SEVERITY_CRITICAL,
			'detail'   => array(
				'What'  => implode('; ', $old),
				'Why'   => 'An update replaced the program on disk, but the running one was started before it, so the fix is not in force until it is reloaded or restarted.',
				'Then'  => 'Install security updates now on the node page reloads PHP without dropping a request; a service needrestart names restarts with the Restart button beside it.',
			),
		);
	}

	public function cleared_text(ManagedNode $node): string {
		return 'The latest reports show nothing serving replaced code.';
	}
}
?>
