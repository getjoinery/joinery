<?php
/**
 * UpstreamFixWatch - which security fixes upstream has shipped that Ubuntu has
 * not, for every source package we run, and when Ubuntu catches up with a fix
 * we built ahead of it (specs/package_fixes_ahead_of_ubuntu.md WP5).
 *
 * Run hourly by the Watch Upstream Security Fixes task, on the management
 * node. For each source package we run (WATCHED_SOURCES, plus any with an
 * active recipe in this tree's fixes/ACTIVE):
 *
 *   - Ubuntu's tracker lists the CVEs not fixed on 26.04;
 *   - each is rated by the highest of the upstream project's own rating (a
 *     driver: UpstreamAdvisoryDriver), NVD's (as the tracker mirrors it), and
 *     Ubuntu's priority once the CVE is past triage (the triage default is a
 *     placeholder, not a rating);
 *   - a driver also says whether upstream has released the fix.
 *
 * For each active recipe, the tracker says which of its CVEs Ubuntu has
 * released on 26.04, and at which version, and the machines' host reports say
 * whether they handed it back or hold it because Ubuntu's build lacks a CVE.
 *
 * The conclusion is stored (STATE_SETTING); the incidents read it:
 * IncidentSourceFixAheadOfUbuntu (a fix to build) and
 * IncidentSourcePackageFixUbuntuNewer (a recipe to retire or rebuild).
 * A source the tracker could not be read for keeps its last answer, and says
 * since when.
 *
 * @version 1.2 - curl, libxml2, glibc and imagemagick are watched too (owner Q4); a CVE a machine finds
 *                missing from Ubuntu's newer build is missing whatever the tracker says, so the recipe is
 *                to rebuild, never to retire (owner Q6)
 * @version 1.1 - reviewer2's build review: a driver's advisories that cannot be read keep its last good answer,
 *                and a CVE with no advisory is "not known", never "not fixed" (G4); a CVE raised stays raised
 *                past the 30 days until it is released or covered (G8); openssh is watched (G9)
 * @version 1.0
 */
class UpstreamFixWatch {

	/** The stored conclusion: a declared setting holding JSON. */
	const STATE_SETTING = 'server_manager_upstream_fix_watch';

	/**
	 * The Ubuntu source packages every node runs (read from localdev's dpkg
	 * database, 2026-11-20): what a site serves with, and what a container
	 * host's site containers' base holds.
	 */
	const WATCHED_SOURCES = array('php8.5', 'postgresql-18', 'apache2', 'postfix', 'openssl', 'openssh', 'curl', 'libxml2', 'glibc', 'imagemagick');

	/** Q1: the ratings that get a recipe. Lower ones wait for Ubuntu. */
	const BUILD_RATINGS = array('critical', 'high');

	/**
	 * A CVE is first raised when it was published this recently: the watch is
	 * for a fix upstream shipped that Ubuntu has not, not for the long list of
	 * old CVEs Ubuntu has deferred or ignored on 26.04. Once raised it stays
	 * raised until it is released, not affected, or carried by a recipe.
	 */
	const RECENT_SECONDS = 2592000;

	/** A source the tracker has not answered for in this long is blind. */
	const BLIND_SECONDS = 21600;

	const RANK = array('' => 0, 'none' => 0, 'negligible' => 0, 'low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4);

	/** The drivers that rate a project's own fixes. */
	public static function drivers(): array {
		return array(new UpstreamAdvisoryPhp());
	}

	/**
	 * One run of the scheduled task.
	 *
	 * @param array $opts tests only: 'tracker' => UbuntuCveTracker, 'drivers' => UpstreamAdvisoryDriver[],
	 *                    'recipes' => ACTIVE lines by source, 'nodes' => node_summary() rows, 'now' => int, 'save' => false
	 */
	public static function run(array $opts = array()): array {
		$now = $opts['now'] ?? time();
		$tracker = $opts['tracker'] ?? new UbuntuCveTracker();
		$drivers = $opts['drivers'] ?? self::drivers();
		$recipes = $opts['recipes'] ?? self::recipes();
		$nodes = $opts['nodes'] ?? self::node_summary();
		$state = self::check(self::state(), $tracker, $drivers, $recipes, $nodes, $now);
		if (($opts['save'] ?? true) !== false) {
			self::save($state);
		}
		$c = self::conclusions($state, $now);
		$blind = array_keys(array_filter($state['sources'], function ($s) { return ($s['error'] ?? '') !== ''; }));
		foreach ($state['drivers'] as $label => $d) {
			if ($d['error'] !== '') { $blind[] = $label . '\'s advisories'; }
		}
		$msg = count($c['build']) . ' CVE' . (count($c['build']) === 1 ? '' : 's') . ' to build ahead of Ubuntu, '
			. count($c['unrated']) . ' not rated yet, ' . count($c['recipes']) . ' recipe' . (count($c['recipes']) === 1 ? '' : 's') . ' to retire or rebuild'
			. ($blind ? '; could not read Ubuntu\'s tracker for ' . implode(', ', $blind) : '');
		return array('status' => $blind ? 'error' : 'success', 'message' => $msg, 'state' => $state);
	}

	/**
	 * Fold one look into $state. Pure apart from what $tracker and $drivers fetch.
	 */
	public static function check(array $state, UbuntuCveTracker $tracker, array $drivers, array $recipes, array $nodes, int $now): array {
		$state['checked_at'] = $now;
		$state['sources'] = is_array($state['sources'] ?? null) ? $state['sources'] : array();
		$prev_drivers = is_array($state['drivers'] ?? null) ? $state['drivers'] : array();
		$state['drivers'] = array();
		$advisories = array();
		foreach ($drivers as $d) {
			$a = $d->advisories();
			$prev = $prev_drivers[$d->label()] ?? array();
			if ($a === null) {
				// Keep the last good answer: one refused fetch must not close an
				// incident that a rating from this driver opened.
				$state['drivers'][$d->label()] = array('error' => 'its advisories could not be read', 'read_at' => (int)($prev['read_at'] ?? 0),
					'advisories' => is_array($prev['advisories'] ?? null) ? $prev['advisories'] : array());
			} else {
				$state['drivers'][$d->label()] = array('error' => '', 'read_at' => $now, 'advisories' => $a);
			}
			$advisories[$d->label()] = $state['drivers'][$d->label()]['advisories'];
		}
		$sources = array_values(array_unique(array_merge(self::WATCHED_SOURCES, array_keys($recipes))));
		sort($sources);
		foreach ($sources as $source) {
			$open = $tracker->cvesForPackage($source, UbuntuCveTracker::CODENAME, true);
			$prev = $state['sources'][$source] ?? array('checked_at' => 0, 'unfixed' => array());
			if ($open === null) {
				$prev['error'] = 'Ubuntu\'s tracker could not be read';
				$state['sources'][$source] = $prev;
				continue;
			}
			$driver = null;
			foreach ($drivers as $d) {
				if ($d->covers($source)) { $driver = $d; break; }
			}
			$unfixed = array();
			foreach ($open as $cve => $st) {
				if (UbuntuCveTracker::isDone($st['status'])) { continue; }
				$rating = '';
				$by = '';
				$up = $driver ? ($advisories[$driver->label()][$cve] ?? null) : null;
				$candidates = array();
				if ($up !== null && $up['rating'] !== '') { $candidates[] = array($up['rating'], $driver->label()); }
				if ($st['severity'] !== '') { $candidates[] = array($st['severity'], 'NVD'); }
				// The tracker's priority while a CVE awaits triage is its default, not a rating.
				if ($st['status'] !== 'needs-triage' && $st['priority'] !== '') { $candidates[] = array($st['priority'], 'Ubuntu'); }
				foreach ($candidates as $c) {
					if ((self::RANK[$c[0]] ?? 0) > (self::RANK[$rating] ?? 0)) { $rating = $c[0]; $by = $c[1]; }
				}
				$unfixed[$cve] = array(
					'status'         => $st['status'],
					'rating'         => $rating,
					'rated_by'       => $by,
					// A driver with no advisory for it says nothing: not known, not "not fixed".
					'upstream_fixed' => $up !== null ? $up['fixed'] : null,
					'fixed_in'       => $up['fixed_in'] ?? '',
					'advisory'       => $up['url'] ?? '',
					'published'      => $st['published'],
				);
			}
			ksort($unfixed);
			$state['sources'][$source] = array('checked_at' => $now, 'error' => '', 'driver' => $driver ? $driver->label() : '', 'unfixed' => $unfixed);
		}
		// Active recipes: what Ubuntu has released of each one's CVEs.
		$state['recipes'] = array();
		foreach ($recipes as $source => $r) {
			$cves = array();
			foreach (preg_split('/\s+/', trim((string)$r['cves']), -1, PREG_SPLIT_NO_EMPTY) as $cve) {
				$st = $tracker->cveStatus($cve, $source);
				$cves[$cve] = $st === null ? array('status' => 'unknown', 'version' => '') : array('status' => $st['status'], 'version' => $st['version']);
			}
			$state['recipes'][$source] = array('version' => (string)$r['version'], 'ubuntu_base' => (string)$r['ubuntu_base'], 'cves' => $cves);
		}
		$state['nodes'] = $nodes;
		// What is raised now stays raised past the recent window, until it is
		// released, not affected, or carried by a recipe.
		$c = self::conclusions($state, $now);
		$raised = array();
		foreach (array_merge($c['build'], $c['unrated']) as $u) {
			$key = $u['source'] . ' ' . $u['cve'];
			$raised[$key] = (int)($state['raised'][$key] ?? $now);
		}
		$state['raised'] = $raised;
		return $state;
	}

	/**
	 * What the stored state calls for:
	 *   build   CVEs rated critical or high (Q1), unfixed on 26.04, published
	 *           recently, that upstream has fixed (or, for a source no driver
	 *           reads, may have), and that no active recipe carries;
	 *   unrated the same, with no rating yet;
	 *   recipes each active recipe Ubuntu has caught up with in part or in
	 *           full: retire, or rebuild on Ubuntu's newer build;
	 *   blind   sources the tracker has not answered for in BLIND_SECONDS.
	 */
	public static function conclusions(array $state, int $now): array {
		$covered = array();
		foreach ($state['recipes'] ?? array() as $source => $r) {
			foreach (array_keys($r['cves'] ?? array()) as $cve) {
				$covered[$source . ' ' . $cve] = true;
			}
		}
		$build = array();
		$unrated = array();
		$blind = array();
		$raised = is_array($state['raised'] ?? null) ? $state['raised'] : array();
		foreach ($state['sources'] ?? array() as $source => $s) {
			if (($s['error'] ?? '') !== '' && $now - (int)($s['checked_at'] ?? 0) > self::BLIND_SECONDS) {
				$blind[$source] = (int)($s['checked_at'] ?? 0);
			}
			foreach ($s['unfixed'] ?? array() as $cve => $u) {
				if (isset($covered[$source . ' ' . $cve]) || $u['upstream_fixed'] === false) { continue; }
				$pub = strtotime((string)$u['published'] . ' UTC');
				$recent = $pub !== false && $now - $pub <= self::RECENT_SECONDS;
				if (!$recent && !isset($raised[$source . ' ' . $cve])) { continue; }
				$item = array('source' => $source, 'cve' => $cve) + $u;
				if (in_array($u['rating'], self::BUILD_RATINGS, true)) {
					$build[] = $item;
				} elseif ($u['rating'] === '') {
					$unrated[] = $item;
				}
			}
		}
		$recipes = array();
		foreach ($state['recipes'] ?? array() as $source => $r) {
			$released = array();
			$missing = array();
			foreach ($r['cves'] ?? array() as $cve => $c) {
				if ($c['status'] === 'released' && $c['version'] !== '') {
					$released[$cve] = $c['version'];
				} else {
					$missing[] = $cve;
				}
			}
			$lacks = array();
			$handed = 0;
			$holding = 0;
			foreach ($state['nodes'] ?? array() as $n) {
				foreach ($n['fixes'] ?? array() as $f) {
					if ($f['source'] !== $source) { continue; }
					if ($f['handed_back'] !== null) { $handed++; }
					if ($f['ubuntu_lacks'] !== null) {
						$holding++;
						$lacks[$f['ubuntu_lacks']['version']] = true;
						// A machine read Ubuntu's build itself: a CVE it found missing
						// (unnamed in the changelog, or its fix not in the source) is
						// missing, whatever the tracker says (owner Q6).
						foreach ((array)($f['ubuntu_lacks']['cves'] ?? array()) as $cve) {
							if (is_string($cve) && !in_array($cve, $missing, true)) { $missing[] = $cve; }
						}
					}
				}
			}
			// Only a build newer than the one the recipe is made on: a recipe
			// rebuilt on Ubuntu's newest build is not behind it.
			$newest = '';
			foreach (array_merge(array_values($released), array_keys($lacks)) as $v) {
				if (self::compare_versions($v, (string)$r['ubuntu_base']) <= 0) { continue; }
				if ($newest === '' || self::compare_versions($v, $newest) > 0) { $newest = $v; }
			}
			if ($newest === '') { continue; }
			$recipes[] = array(
				'source'   => $source,
				'version'  => $r['version'],
				'action'   => $missing ? 'rebuild' : 'retire',
				'newest'   => $newest,
				'released' => $released,
				'missing'  => $missing,
				'handed_back' => $handed,
				'holding'  => $holding,
			);
		}
		$driver_errors = array();
		foreach ($state['drivers'] ?? array() as $label => $d) {
			if (($d['error'] ?? '') !== '' && $now - (int)($d['read_at'] ?? 0) > self::BLIND_SECONDS) {
				$driver_errors[$label] = (int)($d['read_at'] ?? 0);
			}
		}
		return array('build' => $build, 'unrated' => $unrated, 'recipes' => $recipes, 'blind' => $blind, 'driver_errors' => $driver_errors);
	}

	/** The active recipes this tree's release carries, by source (fixes/ACTIVE). */
	public static function recipes(): array {
		$lines = PackageFixPublisher::readStamp(PathHelper::getSiteRoot() . '/' . PackageFixPublisher::SOURCE_SUBDIR);
		return is_array($lines) ? $lines : array();
	}

	/** Each live node's package_fixes, as its last host report gave it. */
	public static function node_summary(): array {
		$out = array();
		foreach (new MultiManagedNode(array('deleted' => false), array('mgn_name' => 'ASC')) as $node) {
			if (!$node->is_operational()) { continue; }
			$hr = $node->get('mgn_last_host_report');
			if (is_string($hr)) { $hr = json_decode($hr, true); }
			if (!is_array($hr)) { continue; }
			$pf = JobResultProcessor::sanitise_host_report($hr)['package_fixes'] ?? null;
			if (is_array($pf) && $pf['fixes']) {
				$out[] = array('id' => (int)$node->key, 'name' => (string)$node->get('mgn_name'), 'fixes' => $pf['fixes']);
			}
		}
		return $out;
	}

	/** Debian version order, as dpkg --compare-versions decides it. */
	public static function compare_versions(string $a, string $b): int {
		if ($a === $b) { return 0; }
		exec('dpkg --compare-versions ' . escapeshellarg($a) . ' gt ' . escapeshellarg($b) . ' 2>/dev/null', $o, $gt);
		if ($gt === 0) { return 1; }
		exec('dpkg --compare-versions ' . escapeshellarg($a) . ' lt ' . escapeshellarg($b) . ' 2>/dev/null', $o, $lt);
		return $lt === 0 ? -1 : 0;
	}

	public static function state(): array {
		$state = json_decode((string)Globalvars::get_instance()->get_setting(self::STATE_SETTING), true);
		return is_array($state) ? $state : array();
	}

	private static function save(array $state): void {
		Setting::put(self::STATE_SETTING, json_encode($state, JSON_UNESCAPED_SLASHES));
	}
}
?>
