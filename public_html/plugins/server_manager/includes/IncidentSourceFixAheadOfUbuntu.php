<?php
/**
 * IncidentSourceFixAheadOfUbuntu - plane:fix_ahead_of_ubuntu: upstream has
 * fixed a serious hole in a package we run, Ubuntu has not shipped the fix on
 * 26.04, and no package fix of ours carries it yet
 * (specs/package_fixes_ahead_of_ubuntu.md WP5).
 *
 * Read from what the Watch Upstream Security Fixes task concluded
 * (UpstreamFixWatch). Raised on this management node's own node, as soon as
 * upstream's fix is out (Q2: no wait):
 *
 *   - critical: a CVE rated critical or high (Q1) by the project, NVD or
 *     Ubuntu past triage;
 *   - warning: a CVE with no rating yet ("not rated yet": NVD often has no
 *     score in the first hours, and Ubuntu's day-one priority is a
 *     placeholder), or Ubuntu's tracker unread for six hours.
 *
 * Clears when Ubuntu's tracker shows every CVE it named released on 26.04,
 * or an active recipe carries it.
 *
 * @version 1.1 - a driver's advisories unread for six hours is said in the blind warning (reviewer2 G4)
 * @version 1.0
 */
class IncidentSourceFixAheadOfUbuntu implements IncidentSource {

	const NAME = 'plane:fix_ahead_of_ubuntu';

	const STEPS = 'specs/package_fixes_ahead_of_ubuntu.md, WP1: write maintenance_scripts/install_tools/fixes/<source>/RECIPE (state=active, '
		. 'the Ubuntu version every node runs, the CVEs) and one patches/NNNN-CVE-....patch per fix from upstream\'s commit, '
		. 'commit it, and publish with Deploy to all managed nodes ticked.';

	public function name(): string {
		return self::NAME;
	}

	public function evaluate(ManagedNode $node, ?int $now = null): ?array {
		if (!ReleaseLogWatch::isSelf($node)) {
			return null;
		}
		return self::from_state(UpstreamFixWatch::state(), $now ?? time());
	}

	/** The incident the watch's stored state calls for, or null. Pure. */
	public static function from_state(array $state, int $now): ?array {
		if (!$state) {
			return null;
		}
		$c = UpstreamFixWatch::conclusions($state, $now);
		$line = function ($u) {
			return $u['source'] . ': ' . $u['cve'] . ' (' . ($u['rating'] !== '' ? $u['rating'] . ', rated by ' . $u['rated_by'] : 'not rated yet')
				. '; Ubuntu: ' . $u['status']
				. ($u['upstream_fixed'] === true ? '; fixed upstream' . ($u['fixed_in'] !== '' ? ' in ' . $u['fixed_in'] : '') : '; upstream fix: check the project\'s advisories')
				. ($u['advisory'] !== '' ? '; ' . $u['advisory'] : '') . ')';
		};
		if ($c['build']) {
			$sources = array_values(array_unique(array_column($c['build'], 'source')));
			return array(
				'title'    => count($c['build']) . ' serious security fix' . (count($c['build']) === 1 ? '' : 'es') . ' upstream has shipped and Ubuntu has not, for '
					. implode(', ', $sources),
				'severity' => IncidentRecord::SEVERITY_CRITICAL,
				'detail'   => array(
					'CVEs'       => implode("\n", array_map($line, array_slice($c['build'], 0, 30))),
					'Not rated yet' => $c['unrated'] ? implode("\n", array_map($line, array_slice($c['unrated'], 0, 20))) : 'none',
					'What it means' => 'Every node runs Ubuntu\'s build of ' . implode(', ', $sources) . ', which does not have these fixes. '
						. 'Ubuntu\'s own priority on the first day is its triage default, not a rating.',
					'What to do' => self::STEPS,
					'Clears'     => 'when Ubuntu\'s tracker shows each released on 26.04, or an active recipe carries it',
				),
			);
		}
		if ($c['unrated']) {
			return array(
				'title'    => count($c['unrated']) . ' security fix' . (count($c['unrated']) === 1 ? '' : 'es') . ' Ubuntu has not shipped, not rated yet',
				'severity' => IncidentRecord::SEVERITY_WARNING,
				'detail'   => array(
					'CVEs'          => implode("\n", array_map($line, array_slice($c['unrated'], 0, 30))),
					'What it means' => 'Neither the project, NVD nor Ubuntu has rated these yet. Read each advisory: a critical or high one gets a recipe now.',
					'What to do'    => self::STEPS,
				),
			);
		}
		if ($c['blind'] || $c['driver_errors']) {
			$since = array();
			foreach ($c['blind'] as $source => $t) {
				$since[] = $source . ': Ubuntu\'s tracker' . ($t > 0 ? ' (last read ' . gmdate('Y-m-d H:i', $t) . ' UTC)' : ' (never read)');
			}
			foreach ($c['driver_errors'] as $label => $t) {
				$since[] = $label . '\'s own advisories' . ($t > 0 ? ' (last read ' . gmdate('Y-m-d H:i', $t) . ' UTC)' : ' (never read)');
			}
			$what = array();
			if ($c['blind']) { $what[] = 'Ubuntu\'s CVE tracker for ' . implode(', ', array_keys($c['blind'])); }
			if ($c['driver_errors']) { $what[] = implode(', ', array_keys($c['driver_errors'])) . '\'s own advisories'; }
			return array(
				'title'    => implode(' and ', $what) . ' not read in six hours',
				'severity' => IncidentRecord::SEVERITY_WARNING,
				'detail'   => array(
					'Sources' => implode("\n", $since),
					'Why'     => 'A fix upstream ships for these is not seen until the tracker answers again.',
					'Look'    => 'the Watch Upstream Security Fixes task\'s last message, under Scheduled Tasks',
				),
			);
		}
		return null;
	}

	public function cleared_text(ManagedNode $node): string {
		return 'Every serious security fix upstream has shipped for a package we run is in Ubuntu\'s build on 26.04, or in one of ours.';
	}
}
?>
