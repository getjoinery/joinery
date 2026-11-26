<?php
/**
 * IncidentSourceFixAheadOfUbuntu - plane:fix_ahead_of_ubuntu: upstream has
 * fixed a serious hole in a package we run, Ubuntu has not shipped the fix on
 * 26.04, and no package fix of ours carries it yet
 * (specs/package_fixes_ahead_of_ubuntu.md WP5).
 *
 * Read from what the Watch Upstream Security Fixes task concluded
 * (UpstreamFixWatch). Raised on this management node's own node, as soon as
 * upstream's fix is out (Q2: no wait), and only where the automatic runner
 * issues the package fix channel (PackageFixAuto::role() 'issuer'): it is the
 * one box that acts on it. A site that mirrors the channel, or takes it, passes
 * on what that box builds, and nothing there can act.
 *
 *   - critical: a CVE rated critical or high (Q1) by the project, NVD or
 *     Ubuntu past triage, that a person is needed for or that the runner has
 *     not tried yet;
 *   - warning: the same, when every one waits for upstream's release (the
 *     runner builds it once it is out) or is built and on its way out;
 *   - warning: a CVE with no rating yet ("not rated yet": NVD often has no
 *     score in the first hours, and Ubuntu's day-one priority is a
 *     placeholder), or Ubuntu's tracker unread for six hours.
 *
 * Clears when Ubuntu's tracker shows every CVE it named released on 26.04,
 * or an active recipe carries it.
 *
 * @version 1.4 - raised only where the runner issues the channel; says what the runner is doing about each fix (waiting
 *                for upstream's release, a person needed, not tried yet), and is a warning when nothing needs anyone (B9)
 * @version 1.3 - ImageMagick's name goes: it is not watched
 * @version 1.2 - quiet (IncidentSourceQuiet): it waits in the inbox, and the automatic runner emails what is new
 *                (specs/package_fixes_automatic.md WP5)
 * @version 1.1 - a driver's advisories unread for six hours is said in the blind warning (reviewer2 G4)
 * @version 1.0
 */
class IncidentSourceFixAheadOfUbuntu implements IncidentSourceQuiet {

	const NAME = 'plane:fix_ahead_of_ubuntu';

	/** What the automatic runner does with a fix it has not tried yet. */
	const WHAT_HAPPENS = 'Our own build is made automatically within ten minutes and rolled out: the test servers first, then every server '
		. 'once they have stayed healthy for some hours. Fleet Security shows where each fix is.';

	/** The names people know the source packages by. */
	const NAMES = array('php' => 'PHP', 'postgresql' => 'PostgreSQL', 'openssl' => 'OpenSSL', 'apache2' => 'Apache',
		'postfix' => 'Postfix', 'openssh' => 'OpenSSH', 'curl' => 'curl', 'libxml2' => 'libxml2', 'glibc' => 'the C library (glibc)');

	/** "php8.5" -> "PHP 8.5", "postgresql-18" -> "PostgreSQL 18"; an unknown source is its own name. */
	public static function package_name(string $source): string {
		if (preg_match('/^([a-z][a-z0-9]*?)-?([0-9][0-9.]*)?$/', $source, $m) && isset(self::NAMES[$m[1]])) {
			return self::NAMES[$m[1]] . (($m[2] ?? '') !== '' ? ' ' . $m[2] : '');
		}
		return self::NAMES[$source] ?? $source;
	}

	/**
	 * One line per package: "PHP 8.5: 1 critical (CVE-2026-115560)",
	 * "PostgreSQL 18: 38 high (CVE-2026-116304, ... and 33 more)".
	 */
	public static function package_lines(array $items): array {
		$by = array();
		foreach ($items as $u) {
			$by[$u['source']][$u['rating'] !== '' ? $u['rating'] : 'not rated yet'][] = $u['cve'];
		}
		ksort($by);
		$out = array();
		foreach ($by as $source => $ratings) {
			$parts = array();
			foreach (array('critical', 'high', 'not rated yet') as $r) {
				if (empty($ratings[$r])) { continue; }
				$ids = $ratings[$r];
				$shown = array_slice($ids, 0, 5);
				$parts[] = count($ids) . ' ' . $r . ' (' . implode(', ', $shown) . (count($ids) > 5 ? ', and ' . (count($ids) - 5) . ' more' : '') . ')';
			}
			$out[] = self::package_name($source) . ': ' . implode('; ', $parts);
		}
		return $out;
	}

	/** The title's package list: "PHP 8.5 (1 critical), PostgreSQL 18 (38 high)". */
	private static function title_list(array $items): string {
		$by = array();
		foreach ($items as $u) {
			$by[$u['source']][$u['rating']] = ($by[$u['source']][$u['rating']] ?? 0) + 1;
		}
		ksort($by);
		$parts = array();
		foreach ($by as $source => $counts) {
			$c = array();
			foreach (array('critical', 'high') as $r) {
				if (!empty($counts[$r])) { $c[] = $counts[$r] . ' ' . $r; }
			}
			$parts[] = self::package_name($source) . ($c ? ' (' . implode(', ', $c) . ')' : '');
		}
		return implode(', ', $parts);
	}

	public function name(): string {
		return self::NAME;
	}

	public function evaluate(ManagedNode $node, ?int $now = null): ?array {
		if (!ReleaseLogWatch::isSelf($node) || PackageFixAuto::role() !== 'issuer') {
			return null;
		}
		return self::from_state(UpstreamFixWatch::state(), $now ?? time(), (array)(PackageFixAuto::state()['attempts'] ?? array()));
	}

	/**
	 * What the runner is doing about each fix to build, from its attempts
	 * ('source CVE' => attempt): 'waiting' (for upstream's release), 'person',
	 * 'built' (on its way out), 'untried'. Each group's lines are per package.
	 */
	public static function runner_groups(array $build, array $attempts): array {
		$g = array('waiting' => array(), 'person' => array(), 'built' => array(), 'untried' => array());
		foreach ($build as $u) {
			$a = $attempts[$u['source'] . ' ' . $u['cve']] ?? null;
			$st = is_array($a) ? (string)($a['status'] ?? '') : '';
			if ($st === 'waiting_release') {
				$label = self::package_name($u['source']) . ' ' . (string)($a['release'] ?? '');
				$g['waiting'][$label][] = $u['cve'];
			} elseif ($st === 'needs_person') {
				$g['person'][self::package_name($u['source'])][] = $u['cve'];
			} elseif ($st === 'built' || $st === 'carried') {
				$g['built'][self::package_name($u['source'])][] = $u['cve'];
			} else {
				$g['untried'][self::package_name($u['source'])][] = $u['cve'];
			}
		}
		return $g;
	}

	/** "OpenSSL 3.5.10: CVE-1, CVE-2" per package, five ids shown. */
	private static function group_lines(array $by): string {
		$out = array();
		foreach ($by as $label => $ids) {
			$out[] = trim($label) . ': ' . implode(', ', array_slice($ids, 0, 5)) . (count($ids) > 5 ? ', and ' . (count($ids) - 5) . ' more' : '');
		}
		return implode("\n", $out);
	}

	/** The incident the watch's stored state calls for, or null. Pure. $attempts: the runner's, 'source CVE' => attempt. */
	public static function from_state(array $state, int $now, array $attempts = array()): ?array {
		if (!$state) {
			return null;
		}
		$c = UpstreamFixWatch::conclusions($state, $now);
		$line = function ($u) {
			return $u['cve'] . ': ' . self::package_name($u['source']) . ', '
				. ($u['rating'] !== '' ? $u['rating'] . ' (' . $u['rated_by'] . '\'s rating)' : 'not rated yet')
				. ($u['upstream_fixed'] === true && $u['fixed_in'] !== '' ? ', fixed upstream in ' . $u['fixed_in'] : '')
				. '; Ubuntu: ' . $u['status']
				. ($u['advisory'] !== '' ? '; ' . $u['advisory'] : '');
		};
		if ($c['build']) {
			$n = count($c['build']);
			$g = self::runner_groups($c['build'], $attempts);
			$happens = array();
			if ($g['person']) {
				$happens[] = 'A person is needed (Fleet Security says why):' . "\n" . self::group_lines($g['person']);
			}
			if ($g['untried']) {
				$happens[] = 'Not tried yet. ' . self::WHAT_HAPPENS . "\n" . self::group_lines($g['untried']);
			}
			if ($g['waiting']) {
				$happens[] = 'Waiting for upstream\'s release, then built automatically; nothing to do:' . "\n" . self::group_lines($g['waiting']);
			}
			if ($g['built']) {
				$happens[] = 'Built, on its way to the servers:' . "\n" . self::group_lines($g['built']);
			}
			return array(
				'title'    => $n . ' security fix' . ($n === 1 ? '' : 'es') . ' Ubuntu has not shipped yet: ' . self::title_list($c['build'])
					. ($g['person'] || $g['untried'] ? '' : ', nothing needed'),
				'severity' => $g['person'] || $g['untried'] ? IncidentRecord::SEVERITY_CRITICAL : IncidentRecord::SEVERITY_WARNING,
				'detail'   => array(
					'What this is'  => 'These projects published security fixes that Ubuntu has not put in its 26.04 packages yet, '
						. 'so every server still runs the unfixed versions.',
					'Packages'      => implode("\n", self::package_lines($c['build'])),
					'What happens'  => implode("\n\n", $happens),
					'Not rated yet' => $c['unrated'] ? implode("\n", self::package_lines($c['unrated'])) : 'none',
					'Each fix'      => implode("\n", array_map($line, array_slice($c['build'], 0, 30)))
						. ($n > 30 ? "\n...and " . ($n - 30) . ' more' : ''),
					'Clears'        => 'when Ubuntu ships each fix, or our own build carries it',
				),
			);
		}
		if ($c['unrated']) {
			$n = count($c['unrated']);
			return array(
				'title'    => $n . ' security fix' . ($n === 1 ? '' : 'es') . ' Ubuntu has not shipped yet, not rated yet: '
					. implode(', ', array_map(array(self::class, 'package_name'), array_values(array_unique(array_column($c['unrated'], 'source'))))),
				'severity' => IncidentRecord::SEVERITY_WARNING,
				'detail'   => array(
					'What this is' => 'Nobody (the project, NVD or Ubuntu) has rated these yet. One rated critical or high is built '
						. 'automatically as soon as the rating appears; lower ones wait for Ubuntu.',
					'Packages'     => implode("\n", self::package_lines($c['unrated'])),
					'Each fix'     => implode("\n", array_map($line, array_slice($c['unrated'], 0, 30))),
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
		return 'Every critical or high security fix for a package we run is in Ubuntu\'s 26.04 build, or in our own.';
	}
}
?>
