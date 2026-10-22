<?php
/**
 * IncidentSourceSecurityFixPending - plane:security_fix_pending: a security fix
 * has waited longer than it should on a machine (specs/fleet_security_patching.md
 * WP6), as its hourly check reports it (host_report's security_pending).
 *
 * A machine installs a critical fix at once (Q2: PHP, Apache, OpenSSL,
 * PostgreSQL) and every other fix at night, so a fix only waits by
 * something going wrong. This raises, most serious first:
 *
 *   - a critical fix (not the kernel) seen waiting over an hour: the at-once
 *     install did not take it (critical);
 *   - any fix seen waiting over a day, the kernel's included: the nightly
 *     updates did not take it (a full /boot is the classic kernel case); the
 *     reboot after a kernel fix is a person's choice, and its wait shows on
 *     the node page and the fleet page, not here (warning);
 *   - no check for fixes has passed in three hours on a machine whose hourly
 *     check is on (warning).
 *
 * Clears when a report shows none of these.
 *
 * @version 1.1 - a kernel package still waiting after a day is a nightly run that failed, and counts (reviewer2 G3)
 * @version 1.0
 */
class IncidentSourceSecurityFixPending implements IncidentSource {

	const NAME = 'plane:security_fix_pending';

	/** A critical fix may wait this long: the hourly check, then its at-once install. */
	const CRITICAL_SECONDS = 3600;

	/** Any fix may wait this long: the nightly updates, and their spread. */
	const ANY_SECONDS = 93600;

	/** A check older than this has missed three hourly runs. */
	const STALE_SECONDS = 10800;

	const LOOK = 'on the machine, as root: bash maintenance_scripts/sysadmin_tools/security_check.sh status; journalctl -u joinery-security-check; /var/log/unattended-upgrades/unattended-upgrades.log';

	public function name(): string {
		return self::NAME;
	}

	/** The report's security_pending, or null where it has none to read. */
	public static function reading(ManagedNode $node): ?array {
		$report = $node->get('mgn_last_host_report');
		if (is_string($report)) {
			$report = json_decode($report, true);
		}
		if (!is_array($report)) {
			return null;
		}
		$r = JobResultProcessor::sanitise_host_report($report)['security_pending'] ?? null;
		return is_array($r) ? $r : null;
	}

	public function evaluate(ManagedNode $node, ?int $now = null): ?array {
		$r = self::reading($node);
		if ($r === null) {
			return null;
		}
		$now = $now ?? time();
		$critical = array();
		$old = array();
		foreach ($r['packages'] as $p) {
			$seen = $p['first_seen'] !== '' ? strtotime($p['first_seen']) : false;
			if ($seen === false) { continue; }
			$kernel = (bool)preg_match('/^linux-(image|modules)/', $p['package']);
			if ($p['critical'] && !$kernel && $now - $seen > self::CRITICAL_SECONDS) {
				$critical[] = $p['package'] . ' ' . $p['candidate'] . ' (waiting since ' . $p['first_seen'] . ')';
			} elseif ($now - $seen > self::ANY_SECONDS) {
				$old[] = $p['package'] . ' ' . $p['candidate'] . ' (waiting since ' . $p['first_seen'] . ')';
			}
		}
		$at_once = $r['at_once_at'] !== ''
			? 'The hourly check last installed at once at ' . $r['at_once_at'] . ($r['at_once_outcome'] !== '' ? ': ' . str_replace('_', ' ', $r['at_once_outcome']) : '') . '.'
			: 'The hourly check has not installed anything at once.';
		if ($critical) {
			return array(
				'title'    => count($critical) . ' critical security fix' . (count($critical) === 1 ? '' : 'es') . ' waited over an hour and ' . (count($critical) === 1 ? 'is' : 'are') . ' not installed',
				'severity' => IncidentRecord::SEVERITY_CRITICAL,
				'detail'   => array(
					'Waiting' => implode('; ', array_slice($critical, 0, 10)),
					'What'    => $at_once,
					'Then'    => 'Install security updates now on the node page; its job says what stopped the install.',
					'Look'    => self::LOOK,
				),
			);
		}
		if ($old) {
			return array(
				'title'    => count($old) . ' security fix' . (count($old) === 1 ? '' : 'es') . ' waited over a day',
				'severity' => IncidentRecord::SEVERITY_WARNING,
				'detail'   => array(
					'Waiting' => implode('; ', array_slice($old, 0, 10)),
					'What'    => 'The nightly automatic updates did not install ' . (count($old) === 1 ? 'it' : 'them') . '.',
					'Then'    => 'Install security updates now on the node page; its job says what stopped the install.',
					'Look'    => self::LOOK,
				),
			);
		}
		if ($r['scheduled']) {
			$since = $r['checked_ok_at'] !== '' ? $r['checked_ok_at'] : $r['installed_at'];
			$t = $since !== '' ? strtotime($since) : false;
			if ($t !== false && $now - $t > self::STALE_SECONDS) {
				return array(
					'title'    => 'No look for security fixes has passed since ' . $since,
					'severity' => IncidentRecord::SEVERITY_WARNING,
					'detail'   => array(
						'What' => $r['check'] === 'failed' && $r['check_error'] !== '' ? 'The last check failed: ' . $r['check_error'] : 'The hourly check has not run one that passed.',
						'Why'  => 'A fix that is not looked for is not installed at once.',
						'Look' => self::LOOK,
					),
				);
			}
		}
		return null;
	}

	public function cleared_text(ManagedNode $node): string {
		return 'The latest host report shows no security fix waiting past its time.';
	}
}
?>
