<?php
/**
 * IncidentSourceSiteCodeUnverified — plane:site_code_unverified: on a host that
 * refuses unsigned code, a site's code is not what was shipped, or is not being
 * checked (specs/host_restrictions.md D8).
 *
 * Signing checks code when it is installed, not afterwards: a file edited in
 * place by someone with root, or a plugin directory copied in, goes through no
 * install. So every site on such a host checks its whole tree on each converge
 * (site_housekeeping.sh, config/code_check) and the host report carries the
 * answer (host_report.sh 1.21, each container's code_check, and the host's own
 * host_restrictions). Read through the same sanitiser the Host card uses.
 *
 * Critical when any site's check failed: it names each site and each package
 * that is not what was shipped. A warning when a site's only failure is a
 * release manifest of another release than its code (wrong_release): a
 * container recreated from an image built on an older release carries that
 * release's manifest beside upgraded code, and an upgrade realigns the two;
 * it is no sign of an intruder, and saying so sends nobody looking for one.
 * Also a warning when the host refuses unsigned code
 * and a running site's check is missing, unreadable or more than STALE_SECONDS
 * old: a check that stopped running is how a failure would go unseen. It
 * reports and nothing more; nothing is turned off, because an edited core file
 * cannot be, and a miss means a gate to fix, not a customer's site to break.
 * Clears when a later report shows every running site's code checked and ok.
 *
 * @version 1.1 - a site whose only failure is wrong_release (PackageSignature 1.9) is a release mismatch, a
 *                warning that says to upgrade, not code that was changed
 * @version 1.0
 */
class IncidentSourceSiteCodeUnverified implements IncidentSource {

	const NAME = 'plane:site_code_unverified';

	/** A check older than this, against the report's own time, is stale: it runs daily at least. */
	const STALE_SECONDS = 172800;

	public function name(): string {
		return self::NAME;
	}

	public function evaluate(ManagedNode $node): ?array {
		$report = self::report($node);
		if ($report === null) {
			return null;
		}
		list($failed, $unchecked, $mismatched) = self::findings($report);
		if (!$failed && !$unchecked && !$mismatched) {
			return null;
		}

		$detail = array();
		foreach ($failed + $mismatched as $site => $lines) {
			$detail['Site ' . $site] = implode('; ', $lines);
		}
		if ($mismatched) {
			$detail['Release mismatch'] = 'The signed release manifest at the root of ' . implode(', ', array_keys($mismatched))
				. ' is another release\'s than its code, so the code cannot be checked against it. A container recreated '
				. 'from an image built on an older release does this. Upgrade the site to the current release: that puts '
				. 'back a matching manifest and keeps a copy on its config volume for the next recreate.';
		}
		if ($unchecked) {
			$detail['Not checked'] = implode('; ', $unchecked);
		}
		if ($failed) {
			$detail['What to do'] = 'This host runs only signed code. Find out how this got onto the site and close that way in, '
				. 'then put the shipped code back: an upgrade to the current release does that for core and every plugin '
				. 'and theme we ship; remove anything else. Nothing was turned off.';
			$title = count($failed) === 1
				? 'Code on site ' . array_key_first($failed) . ' is not what was shipped'
				: 'Code on ' . count($failed) . ' sites is not what was shipped';
			$severity = IncidentRecord::SEVERITY_CRITICAL;
		} elseif ($mismatched) {
			$title = count($mismatched) === 1
				? 'Site ' . array_key_first($mismatched) . '\'s release manifest does not match its code'
				: count($mismatched) . ' sites\' release manifests do not match their code';
			$severity = IncidentRecord::SEVERITY_WARNING;
		} else {
			$detail['What to do'] = 'Each site checks its code on every converge (site_housekeeping.sh) and writes config/code_check. '
				. 'Converge the site from its node page and look at the result; a site on a release older than the check '
				. 'has none until it is upgraded.';
			$title = 'A site\'s code is not being checked on a host that runs only signed code';
			$severity = IncidentRecord::SEVERITY_WARNING;
		}
		return array(
			'title'    => $title,
			'severity' => $severity,
			'detail'   => $detail,
		);
	}

	public function cleared_text(ManagedNode $node): string {
		return 'The latest host report shows the code of every running site checked and exactly what was shipped.';
	}

	/**
	 * [site => [plain sentence per failed package]], [site: why it is not checked] and
	 * [site => [plain sentence]] for a site whose every failure is wrong_release.
	 * The second is filled only on a host that refuses unsigned code. A site is
	 * in the first or the third, never both: one with any other failure is
	 * in the first, its mismatch named beside the rest.
	 */
	public static function findings(array $report): array {
		$failed = array();
		$unchecked = array();
		$mismatched = array();
		$containers = is_array($report['containers'] ?? null) ? $report['containers'] : array();
		$restrictions = $report['host_restrictions'] ?? null;
		// unknown is a file the sites read as every restriction on.
		$restricted = $restrictions === 'unknown'
			|| (is_array($restrictions) && isset($restrictions['unsigned_packages']));
		$now = is_int($report['generated_at'] ?? null) ? $report['generated_at'] : time();
		foreach ($containers as $c) {
			if (!is_array($c)) {
				continue;
			}
			$site = (string)($c['name'] ?? '');
			$check = $c['code_check'] ?? null;
			if (is_array($check) && $check['state'] === 'failed') {
				$lines = array();
				$only_release = (bool)$check['failures'];
				foreach ($check['failures'] as $f) {
					$lines[] = self::sentence($f);
					$only_release = $only_release && $f['verdict'] === PackageSignature::WRONG_RELEASE;
				}
				if ($only_release) {
					$mismatched[$site] = $lines;
				} else {
					$failed[$site] = $lines ?: array('the check failed without naming a package');
				}
				continue;
			}
			if (!$restricted || ($c['state'] ?? '') !== 'running' || !empty($c['held'])) {
				continue;
			}
			if (!is_array($check)) {
				$unchecked[] = $site . ': ' . ($check === 'none' || $check === null ? 'no check on record' : 'the check could not be read');
				continue;
			}
			$at = ($check['at'] !== 'unknown') ? strtotime($check['at']) : false;
			if ($at === false || $now - $at > self::STALE_SECONDS) {
				$unchecked[] = $site . ': last checked ' . ($at === false ? 'at an unknown time' : gmdate('Y-m-d H:i', $at) . ' UTC');
			}
		}
		return array($failed, $unchecked, $mismatched);
	}

	/** One failed package in plain words. */
	private static function sentence(array $f): string {
		$what = $f['kind'] === 'core' ? 'core'
			: ($f['kind'] === 'check' ? 'the check' : $f['kind'] . ' ' . ($f['name'] !== '' ? $f['name'] : '(unnamed)'));
		$file = $f['file'] !== '' ? ' ' . $f['file'] : '';
		switch ($f['verdict']) {
			case 'tampered':     $why = 'a file was changed:' . $file; break;
			case 'extra_file':   $why = $file !== '' ? 'a file was added:' . $file : 'a file that belongs to no package'; break;
			case 'missing_file': $why = 'a file is missing:' . $file; break;
			case 'unsigned':     $why = 'not built by Joinery (no signature)'; break;
			case 'unknown_key':  $why = 'signed by a key this host does not trust'; break;
			case 'unlogged':     $why = 'signed, but not in the public release log'; break;
			case 'no_keys':      $why = 'the site has no release keys to check with'; break;
			case 'wrong_release': $why = 'its release manifest is another release\'s than its code'; break;
			case 'unreadable':   $why = $f['kind'] === 'check' ? 'the check did not run' : 'its signature could not be read'; break;
			default:             $why = 'not what was shipped'; break;
		}
		return $what . ': ' . $why;
	}

	/** The stored host report, sanitised, or null where there is none or no site containers. */
	private static function report(ManagedNode $node): ?array {
		$report = $node->get('mgn_last_host_report');
		if (is_string($report)) {
			$report = json_decode($report, true);
		}
		if (!is_array($report)) {
			return null;
		}
		$report = JobResultProcessor::sanitise_host_report($report);
		return is_array($report['containers'] ?? null) ? $report : null;
	}
}
?>
