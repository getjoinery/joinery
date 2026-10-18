<?php
/**
 * IncidentSourceSiteRefresh — plane:site_refresh: the nightly replacement of a
 * host's site containers (site_refresh.sh, specs/multi_tenant_host_hardening.md
 * D9) needs a person, as the host's last report says (site_refresh).
 *
 * A site container's OS is its image's and nothing inside it installs a fix,
 * so the host replaces each site's container when Ubuntu ships a security fix.
 * This raises, most serious first:
 *
 *   - a site left down: its new container did not come up and its previous
 *     image did not either (critical); the host replaces no more sites;
 *   - a replacement rolled back: the site runs again on its previous image
 *     and waits; the host goes on with its other sites, and stops at a second
 *     site rolled back on the same base (warning);
 *   - a base image that could not be built, so pending fixes wait (warning);
 *   - no check has passed for two days on a host whose timer is on: fixes are
 *     no longer being looked for (warning).
 *
 * Clears when a report shows none of these.
 *
 * @version 1.0
 */
class IncidentSourceSiteRefresh implements IncidentSource {

	const NAME = 'plane:site_refresh';

	/** How long a host may go without a check that passed. */
	const STALE_SECONDS = 172800;

	public function name(): string {
		return self::NAME;
	}

	/** The report's site_refresh reading, or null where it has none. */
	public static function reading(ManagedNode $node): ?array {
		$report = $node->get('mgn_last_host_report');
		if (is_string($report)) {
			$report = json_decode($report, true);
		}
		if (!is_array($report)) {
			return null;
		}
		$r = JobResultProcessor::sanitise_host_report($report)['site_refresh'] ?? null;
		return is_array($r) ? $r : null;
	}

	const LOOK = 'on the host, as root: bash /opt/joinery-agent/tree/maintenance_scripts/install_tools/site_refresh.sh status; journalctl -u joinery-site-refresh; the logs in /var/lib/joinery/site-refresh/logs and /var/log/joinery/recreate';

	public function evaluate(ManagedNode $node, ?int $now = null): ?array {
		$r = self::reading($node);
		if ($r === null) {
			return null;
		}
		$now = $now ?? time();
		// Holds on the base the host runs now: an older one's was lifted by
		// the new base at the next run, and the report says so then. A site
		// left down stays held, and counts, whatever base it was left on.
		$holds = array();
		foreach ($r['holds'] as $h) {
			if ($h['outcome'] === 'down' || $h['base'] === '' || $r['base_id'] === '' || $h['base'] === $r['base_id']) { $holds[] = $h; }
		}
		if ($holds) {
			$down = array();
			$lines = array();
			foreach ($holds as $h) {
				if ($h['outcome'] === 'down') { $down[] = $h['site']; }
				$lines[] = $h['site'] . ($h['outcome'] === 'down' ? ' was left down' : ' rolled back') . ($h['at'] !== '' ? ' at ' . $h['at'] : '')
					. ($h['reason'] !== '' ? ': ' . $h['reason'] : '');
			}
			$stopped = $down || count($holds) >= 2 || $r['run'] === 'held';
			if ($down) {
				$title = implode(', ', $down) . (count($down) === 1 ? ' is' : ' are') . ' down: a container replacement failed and the previous image did not come up';
			} elseif (count($holds) === 1) {
				$title = $holds[0]['site'] . '\'s container replacement was rolled back';
			} else {
				$title = count($holds) . ' sites\' container replacements were rolled back on the same base';
			}
			return array(
				'title'    => $title,
				'severity' => $down ? IncidentRecord::SEVERITY_CRITICAL : IncidentRecord::SEVERITY_WARNING,
				'detail'   => array(
					'Sites'  => implode('; ', $lines),
					'Effect' => ($down ? 'A site left down does not answer. ' : 'A site rolled back runs again on its previous image, without the fixes. ')
						. ($stopped
							? 'The host has stopped replacing site containers on this base: two failures, or a site left down, point at the base or the host, not one site.'
							: 'The host goes on replacing its other sites; this one waits.'),
					'Then'   => 'Once the cause is put right: the Replace button beside the site under Site containers on the host\'s node page (a replacement that succeeds lifts its hold), or site_refresh.sh clear on the host.',
					'Look'   => self::LOOK,
				),
			);
		}
		if ($r['run'] === 'base_failed') {
			return array(
				'title'    => 'The site containers\' base image could not be built',
				'severity' => IncidentRecord::SEVERITY_WARNING,
				'detail'   => array(
					'What'    => $r['run_error'] !== '' ? $r['run_error'] : 'the report did not say',
					'Pending' => $r['base_due'] === 'setup_changed'
						? 'This release\'s server setup waits for the base it changes.'
						: ((is_int($r['pending_security']) ? $r['pending_security'] : 'an unknown number of') . ' security fixes wait'
							. ($r['security_packages'] ? ' (' . implode(', ', $r['security_packages']) . ')' : '')),
					'Look'    => self::LOOK,
				),
			);
		}
		if ($r['scheduled']) {
			$since = $r['checked_ok_at'] !== '' ? $r['checked_ok_at'] : $r['installed_at'];
			$t = $since !== '' ? strtotime($since) : false;
			if ($t !== false && $now - $t > self::STALE_SECONDS) {
				return array(
					'title'    => 'No check for fixes to the site containers\' OS has passed since ' . $since,
					'severity' => IncidentRecord::SEVERITY_WARNING,
					'detail'   => array(
						'What' => $r['check'] === 'failed' && $r['check_error'] !== ''
							? 'The last check failed: ' . $r['check_error']
							: 'The nightly timer has not run a check that passed.',
						'Why'  => 'Fixes Ubuntu ships for the sites\' PHP, Apache, PostgreSQL and libraries are only installed by replacing their containers, which waits on the check.',
						'Look' => self::LOOK,
					),
				);
			}
		}
		return null;
	}

	public function cleared_text(ManagedNode $node): string {
		$r = self::reading($node);
		if ($r === null) {
			return 'The latest host report says nothing of the site containers\' nightly replacement.';
		}
		return 'The latest host report shows the site containers\' nightly replacement running, with no hold.';
	}
}
?>
