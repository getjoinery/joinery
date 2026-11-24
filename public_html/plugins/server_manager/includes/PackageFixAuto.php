<?php
/**
 * PackageFixAuto - the automatic path from "upstream fixed it and Ubuntu has
 * not" to every machine running our build, with no person in it unless it
 * stops (specs/package_fixes_automatic.md WP5, WP7, WP9).
 *
 * One run (package_fix_auto.php, a root timer every ten minutes, one at a
 * time), on the box that logs releases:
 *
 *   1. a person's Withdraw (server_manager_package_fix_withdraw) is taken;
 *   2. each critical or high CVE the watch says to build (UpstreamFixWatch)
 *      is told once ("found"), and, for each package with one not yet tried:
 *      the fix commit is found and the recipe written
 *      (PackageFixRecipeWriter, UpstreamFixFinder), else upstream's fixed
 *      release is used, and the recipe is built (PackageFixPublisher::
 *      buildRecipe(): the package's own tests must pass);
 *   3. the set (the tree's fixes plus the automatic ones, less what is
 *      withdrawn) is issued when it changed: to the test machines first
 *      (wide=no) when it adds or changes a fix, to every machine at once when
 *      it only removes or withdraws;
 *   4. the soak: once every machine of this plane that takes a new fix
 *      reports it installed, none has opened a failed-service or site-down
 *      incident since, and server_manager_package_fix_soak_hours have
 *      passed, the same set is issued to every machine. A failure withdraws
 *      the new builds at once and tells a person;
 *   5. what failed is told once ("needs a person"); a fix rolled out
 *      everywhere is a bell notice.
 *
 * On a site other sites upgrade from that does not log releases (getjoinery
 * as a mirror), the run mirrors the channel instead (PackageFixChannel::mirror()).
 *
 * The run's record is a root-owned file (STATE_FILE), readable by the Fleet
 * Security page: what gets signed is built from it, so nothing the web user can
 * write decides what root signs. A person's Withdraw is the one request read
 * from the database, and only a build the set offers can be withdrawn.
 *
 * @version 1.11 - the email lists five CVE ids and a count of the rest, and its subject names each package's outcome
 *                (built and testing, built with some left for a person, or a person needed)
 * @version 1.10 - a Try again never ends on the withdrawn build: the writer is told the highest withdrawn revision,
 *                and a build whose version is withdrawn stops as needing a person rather than counting as built
 * @version 1.9 - a stopped attempt is also tried again when what upstream's advisory says of its CVEs changes (fixed
 *                release, fix commits) and once a day, so a release tag published later is found (live on dev,
 *                2026-11-24: OpenSSL's retry ran seconds before the watch first read its advisories' commits)
 * @version 1.8 - one plain email per run (owner, 2026-11-24): what was found and what came of it, sent after the
 *                build attempt, never "found" and then "a person is needed" a minute apart; reasons in plain words,
 *                the raw detail on the Fleet Security page; "Where everything stands" says what is stuck rather than
 *                calling it handled. A site-down incident on a test machine whose machine never answered the plane
 *                while its own report says its site answers is the plane's path, not the fix: that machine is
 *                waited for, not failed (live: dev's IPv4 banned on test_w21 by a fail2ban live check pulled
 *                PostgreSQL's build, 2026-11-23). A person's Try again (server_manager_package_fix_retry) builds a
 *                withdrawn fix again at the next revision. RETRY_GENERATION 2: build.sh 1.6 and the advisory
 *                commits reach every stopped attempt
 * @version 1.7 - the set every machine has is issued again when it, the copy mirrors pass on, predates this
 *                tree's release, read when the check runs: a build can outlast an upgrade (live: joinerydemo on
 *                0.8.647 refused getjoinery's 0.8.645 set while dev had re-issued only the test-first one)
 * @version 1.6 - an attempt that needed a person is tried again when something that could change its outcome
 *                changed (the source's key file, fixes/UPSTREAMS, RETRY_GENERATION), not on every release:
 *                each release re-ran PHP's hour-long build on dev for nothing
 * @version 1.5 - a failed build on upstream's release also says why the fix commits did not fit
 * @version 1.4 - fixes of one package that could not go on with the rest are told in one email, not one each
 *                (live on dev, 2026-11-22: 7 PostgreSQL CVEs sent 7 emails)
 * @version 1.3 - an attempt that needed a person is tried again once this tree's release changes (a key committed
 *                or a fix to the runner arrives with a release); the same failure again tells nobody twice
 *                (live check on dev, 2026-11-22)
 * @version 1.2 - reviewer2's re-review: a build that fails puts the previous automatic recipe back (B21);
 *                apt refusing the source counts once per report (B22); the set is issued again when this
 *                tree's release is newer than the served one's (B23); the set every machine has starts from
 *                what is served wide, else the tree's, never empty (B24); only the Withdraw requests a run
 *                handled are cleared (B25); an email that fails never loses what was recorded (B26); a test
 *                machine that has not taken the soaking set yet is waited for (B27); a set issued again
 *                during a soak keeps the soak's start for what keeps soaking (B28)
 * @version 1.1 - reviewer2's review: a run that stops keeps what it did (B1), and a Withdraw is cleared only
 *                once the state holding it is saved; the soak needs every holder to have reported in the
 *                last two hours, counts from the newest holder's install, and reads a silent agent as a
 *                failure (B2); a container host taking a fix for its base, a machine that handed it back or
 *                is ahead of it never holds the soak (B3); a withdrawal goes to every machine at once even
 *                while another fix soaks (B4); the state is a root-owned file (B6); only apt refusing the
 *                source twice fails the soak, a download that failed is waiting (B9); a CVE a person's
 *                recipe already carries is not built (B14); an automatic recipe whose build failed is
 *                removed, so its patches do not block the next fix (B15)
 * @version 1.0
 */
class PackageFixAuto {

	/** The runner's record: root's to write, everyone's to read. */
	const STATE_FILE = '/var/lib/joinery-package-fixes-build/auto-state.json';
	const WITHDRAW_SETTING = 'server_manager_package_fix_withdraw';
	/** A person's Try again of a withdrawn build: source => the withdrawn version. */
	const RETRY_SETTING = 'server_manager_package_fix_retry';
	const SOAK_SETTING = 'server_manager_package_fix_soak_hours';
	/** Where automatic recipes live: outside the repository, nothing is committed for them. */
	const AUTO_DIR = '/var/lib/joinery-package-fixes-build/auto';
	/** Incidents on a machine holding a new fix that fail its soak. */
	const SOAK_FAILURES = array('plane:failed_units', 'plane:site_down', 'plane:agent_silent');
	/** A machine that holds a new fix must have reported within this long for it to go wide. */
	const FRESH_REPORT_SECONDS = 7200;
	/** apt refusing the fix's source on this many runs in a row fails the soak. */
	const REFUSED_RUNS = 2;
	const SIGNAL_NEWS = 'package_fix.news';
	const SIGNAL_ROLLED_OUT = 'package_fix.rolled_out';
	const LINK = '/admin/server_manager/fleet_security';
	/** Raise when a change to the runner itself should retry every stopped attempt. */
	const RETRY_GENERATION = 2;

	public static function state(): array {
		return PackageFixChannel::readStateFile(self::STATE_FILE);
	}

	/**
	 * One run. Never throws.
	 *
	 * @param array $opts tests only: 'site', 'now', 'state', 'channel_state', 'watch_state', 'tree_lines', 'nodes',
	 *                    'failures' => callable(int $since): array of node ids, 'issue' => callable, 'write' => callable,
	 *                    'build' => callable, 'dispatch' => callable, 'withdraw_requests' => array, 'save' => false,
	 *                    'role' => 'issuer'|'mirror'|'none', 'soak_hours' => int
	 * @return array{status:string, message:string, state:array}
	 */
	public static function run(array $opts = array()): array {
		$site = $opts['site'] ?? PathHelper::getSiteRoot();
		$role = $opts['role'] ?? self::role();
		if ($role === 'mirror') {
			$r = PackageFixChannel::mirror($site);
			return array('status' => $r['status'] === 'failed' ? 'error' : 'success', 'message' => $r['message'], 'state' => array());
		}
		if ($role !== 'issuer') {
			return array('status' => 'success', 'message' => 'This site neither logs releases nor serves them: no package fix channel to issue or mirror.', 'state' => array());
		}
		$now = $opts['now'] ?? time();
		$state = $opts['state'] ?? self::state();
		$said = array();
		$requests = $opts['withdraw_requests'] ?? self::withdrawRequests();
		$retries = $opts['retry_requests'] ?? self::retryRequests();
		$handled_retries = array();
		// step() folds into $state as it goes: a run that stops keeps every
		// email it sent and every attempt and withdrawal it made.
		$handled = array();
		try {
			self::step($state, $site, $now, $opts, $said, $requests, $handled, $retries, $handled_retries);
			$status = 'success';
		} catch (\Throwable $e) {
			$said[] = 'stopped: ' . $e->getMessage();
			$status = 'error';
		}
		$state['last_run'] = $now;
		$state['last_message'] = implode('; ', $said) ?: 'nothing to do';
		if (($opts['save'] ?? true) !== false) {
			try {
				PackageFixChannel::writeStateFile(self::STATE_FILE, $state);
				// Only now that the state holding them is saved.
				if ($handled && !isset($opts['withdraw_requests'])) {
					self::clearWithdrawRequests($handled);
				}
				if ($handled_retries && !isset($opts['retry_requests'])) {
					self::clearRequests(self::RETRY_SETTING, $handled_retries);
				}
			} catch (\Throwable $e) {
				$state['last_message'] .= '; the record could not be saved: ' . $e->getMessage();
				$status = 'error';
			}
		}
		return array('status' => $status, 'message' => $state['last_message'], 'state' => $state);
	}

	/** issuer: this box logs releases; mirror: it serves releases it received; none. */
	public static function role(): string {
		if (ReleaseLogWatch::watches()) {
			return 'issuer';
		}
		return DeploymentHelper::isUpgradeServer() ? 'mirror' : 'none';
	}

	/** The run's steps, folded into $state as they go. */
	private static function step(array &$state, string $site, int $now, array $opts, array &$said, array $requests, array &$handled,
			array $retries = array(), array &$handled_retries = array()): void {
		foreach (array('attempts', 'automatic', 'withdrawn', 'notified') as $k) {
			$state[$k] = is_array($state[$k] ?? null) ? $state[$k] : array();
		}
		// What is served withdrawn stays withdrawn, whatever this record says.
		$served = array_key_exists('served_channel', $opts) ? $opts['served_channel'] : PackageFixChannel::read(PackageFixChannel::dir($site));
		foreach ((array)($served['withdrawn'] ?? array()) as $source => $versions) {
			foreach ((array)$versions as $v) {
				if (!in_array($v, (array)($state['withdrawn'][$source] ?? array()), true)) {
					$state = self::withdraw($state, (string)$source, (string)$v, 'the served set withdraws it', $now);
				}
			}
		}
		// Telling never loses what was recorded: an email that fails is logged.
		$raw_dispatch = $opts['dispatch'] ?? function ($signal, $payload) { SignalBus::dispatch($signal, $payload); };
		$dispatch = function ($signal, $payload) use ($raw_dispatch, &$said) {
			try {
				call_user_func($raw_dispatch, $signal, $payload);
			} catch (\Throwable $e) {
				$said[] = 'could not send ' . $signal . ': ' . $e->getMessage();
				error_log('[PackageFixAuto] ' . $signal . ': ' . $e->getMessage());
			}
		};
		$tree = array_key_exists('tree_lines', $opts) ? $opts['tree_lines'] : PackageFixPublisher::readStamp($site . '/' . PackageFixPublisher::SOURCE_SUBDIR);
		if ($tree === null) {
			throw new Exception('this tree\'s fixes/ACTIVE is not whole');
		}

		// 1. A person's Withdraw: only a build the set offers now.
		$offered = PackageFixChannel::merge($tree, $state['automatic'], array());
		foreach ($requests as $source => $version) {
			$handled[$source] = $version;
			if (!is_string($source) || !is_string($version) || ($offered[$source]['version'] ?? null) !== $version) {
				$said[] = 'ignored a Withdraw of ' . (is_string($source) ? $source : '?') . ': it is not a build the set offers';
				continue;
			}
			$state = self::withdraw($state, $source, $version, 'a person withdrew it from the Fleet Security page', $now);
			$said[] = 'withdrew ' . $source . ' ' . $version . ' (asked from the Fleet Security page)';
		}
		// 1b. A person's Try again of a withdrawn build: its fixes are tried
		// afresh, at the next revision (the withdrawn one stays withdrawn).
		foreach ($retries as $source => $version) {
			$handled_retries[$source] = $version;
			if (!is_string($source) || !is_string($version) || !in_array($version, (array)($state['withdrawn'][$source] ?? array()), true)) {
				$said[] = 'ignored a Try again of ' . (is_string($source) ? $source : '?') . ': it is not a withdrawn build';
				continue;
			}
			foreach (array_keys($state['attempts']) as $k) {
				if (strpos($k, $source . ' ') === 0) { unset($state['attempts'][$k]); }
			}
			foreach (array_keys($state['notified']) as $k) {
				if (strpos($k, 'needs_person ' . $source . ' ') === 0) { unset($state['notified'][$k]); }
			}
			$state['retried'][$source] = array('version' => $version, 'at' => $now);
			$said[] = 'trying ' . $source . ' again (asked from the Fleet Security page)';
		}

		// 2. What the watch says to build: told once, then tried once per CVE.
		$watch = $opts['watch_state'] ?? UpstreamFixWatch::state();
		$c = $watch ? UpstreamFixWatch::conclusions($watch, $now) : array('build' => array());
		$new = array();
		foreach ($c['build'] as $u) {
			if (!isset($state['notified']['found ' . $u['source'] . ' ' . $u['cve']])) {
				$new[] = $u;
			}
		}
		// Tried once per CVE; one that needed a person is tried again when what
		// could change its outcome changed: the source's key file, the upstream
		// list, the runner (RETRY_GENERATION), what the advisory says of its
		// CVEs, or the day (a tag upstream publishes later). Not on every release.
		$release = (string)($opts['release'] ?? trim((string)@file_get_contents($site . '/public_html/VERSION')));
		$by_source = array();
		$inputs = array();
		foreach ($c['build'] as $u) {
			$inputs[$u['source']] = $inputs[$u['source']] ?? self::retryInputs($site, $u['source'], $opts,
				array_values(array_filter($c['build'], function ($x) use ($u) { return $x['source'] === $u['source']; })), $watch, $now);
			$a = $state['attempts'][$u['source'] . ' ' . $u['cve']] ?? null;
			if ($a === null || (($a['status'] ?? '') === 'needs_person' && (string)($a['inputs'] ?? '') !== $inputs[$u['source']])) {
				$by_source[$u['source']][$u['cve']] = $u;
			}
		}
		$news = array();
		foreach ($by_source as $source => $cves) {
			$state = self::attempt($state, $site, $source, $cves, $watch, $now, $opts + array('inputs' => $inputs[$source]), $said, $news);
		}
		// One email for the run: what is new and what came of each package's
		// attempt, after the attempt, so it never says "handled" and then
		// "a person is needed" a minute later.
		foreach ($news as $source => $n) {
			if (($n['outcome'] ?? '') === 'needs_person') {
				$key = 'needs_person ' . $source . ' ' . $n['step'] . ' ' . implode(',', self::sorted($n['cves']));
				$has_new = (bool)array_filter($new, function ($u) use ($source) { return $u['source'] === $source; });
				if (isset($state['notified'][$key]) && !$has_new) {
					unset($news[$source]);
					continue;
				}
				$state['notified'][$key] = $now;
			}
			if (!empty($n['left'])) {
				$state['notified']['needs_person ' . $source . ' finding the fix ' . implode(',', self::sorted(array_keys($n['left'])))] = $now;
			}
		}
		if ($new || $news) {
			$soak = (int)($opts['soak_hours'] ?? self::soakHours());
			call_user_func($dispatch, self::SIGNAL_NEWS, self::newsMessage($new, $news, $state, $c['build'], $soak));
			foreach ($new as $u) {
				$state['notified']['found ' . $u['source'] . ' ' . $u['cve']] = $now;
			}
			$said[] = 'told of ' . count($new) . ' new fix(es) and ' . count($news) . ' package outcome(s)';
		}

		$channel = $opts['channel_state'] ?? PackageFixChannel::state();
		$channel_set = (string)($channel['set'] ?? '');
		$issue = $opts['issue'] ?? function ($lines, $withdrawn, $wide) use ($site) { return PackageFixChannel::issue($site, $lines, $withdrawn, $wide); };
		$send = function (array $lines, bool $wide) use (&$state, $issue, &$said, &$channel_set) {
			$r = call_user_func($issue, $lines, $state['withdrawn'], $wide);
			if (($r['status'] ?? '') !== 'issued') {
				throw new Exception($r['message'] ?? 'the channel could not be issued');
			}
			$said[] = $r['message'];
			$channel_set = PackageFixChannel::setHash($lines, $state['withdrawn']);
			return $r;
		};

		// 3a. A new withdrawal goes to every machine at once, whatever soaks:
		// the set every machine has, less what is withdrawn.
		// The set every machine has: as recorded, else as served wide, else the
		// tree's own: never an empty set because the record was lost (B24).
		$served_wide = array_key_exists('served_wide', $opts) ? $opts['served_wide'] : PackageFixChannel::read(PackageFixChannel::wideDir($site));
		if (!is_array($state['wide_lines'] ?? null)) {
			$state['wide_lines'] = is_array($served_wide['lines'] ?? null) ? $served_wide['lines'] : $tree;
		}
		$wide_lines = $state['wide_lines'];
		// A machine installed from a newer release refuses a channel issued
		// before it: issue again once this tree's release moves (B23).
		// The release as it is now: a build earlier in this run can outlast an
		// upgrade. The copy that matters is the one mirrors pass on (channel-wide/).
		$release = (string)($opts['release'] ?? trim((string)@file_get_contents($site . '/public_html/VERSION')));
		$older = function ($ch) use ($release) {
			$r = (string)($ch['release'] ?? '');
			return is_array($ch) && preg_match('/^\d+\.\d+\.\d+$/', $release) && preg_match('/^\d+\.\d+\.\d+$/', $r) && version_compare($r, $release, '<');
		};
		$stale = $older($served_wide) || $older($served);
		if (($state['wide_withdrawn'] ?? array()) != $state['withdrawn'] || $stale) {
			$wide_set = PackageFixChannel::merge($wide_lines, array(), $state['withdrawn']);
			$send($wide_set, true);
			$state['wide_lines'] = $wide_set;
			$state['wide_withdrawn'] = $state['withdrawn'];
			$wide_lines = $wide_set;
			if (isset($state['soak'])) {
				foreach ($state['withdrawn'] as $source => $versions) {
					if (in_array($state['soak']['sources'][$source] ?? null, (array)$versions, true)) {
						unset($state['soak']['sources'][$source]);
					}
				}
				if (empty($state['soak']['sources'])) { unset($state['soak']); }
			}
		}

		// 3b. The whole set, when it changed: a new or changed fix to the test
		// machines first; only removals go to every machine.
		$set = PackageFixChannel::merge($tree, $state['automatic'], $state['withdrawn']);
		$hash = PackageFixChannel::setHash($set, $state['withdrawn']);
		if ($channel_set !== $hash) {
			$adds = self::changedLines($wide_lines, $set);
			$wide = $adds === array();
			$r = $send($set, $wide);
			if ($wide) {
				$state['wide_lines'] = $set;
				$state['wide_withdrawn'] = $state['withdrawn'];
				unset($state['soak']);
			} else {
				// A fix already soaking at the same build keeps its clock.
				$held = array();
				foreach ((array)($state['soak']['held'] ?? array()) as $k => $t) {
					$source = explode('|', $k, 2)[1] ?? '';
					if (($state['soak']['sources'][$source] ?? null) === ($adds[$source] ?? '')) { $held[$k] = $t; }
				}
				// What keeps soaking at the same build keeps the soak's start, so
				// an incident opened since then still counts (B28).
				$started = $now;
				if (isset($state['soak']['started']) && array_intersect_assoc($adds, (array)($state['soak']['sources'] ?? array()))) {
					$started = (int)$state['soak']['started'];
				}
				$state['soak'] = array('serial' => (int)$r['serial'], 'started' => $started, 'set' => $hash, 'sources' => $adds, 'held' => $held,
					'refused' => (array)($state['soak']['refused'] ?? array()));
			}
			return;
		}

		// 4. The soak.
		if (isset($state['soak']) && ($state['soak']['set'] ?? '') === $hash) {
			$state = self::soak($state, $set, $now, $opts, $said, $dispatch, $send);
		}
	}

	/** Try one package: find and write the recipe, then build it. */
	private static function attempt(array $state, string $site, string $source, array $cves, array $watch, int $now, array $opts, array &$said, array &$news): array {
		$inputs = (string)($opts['inputs'] ?? '');
		$mark = function (array $state, string $status, string $step, string $reason) use ($source, $cves, $now, $inputs) {
			foreach (array_keys($cves) as $cve) {
				$state['attempts'][$source . ' ' . $cve] = array('status' => $status, 'step' => $step, 'reason' => $reason, 'at' => $now, 'inputs' => $inputs);
			}
			return $state;
		};
		// The automatic recipe as it stands (the build shipped now): put back if
		// the next build fails, so its fixes are never dropped (B21).
		$auto_root = (string)($opts['auto_dir'] ?? self::AUTO_DIR);
		$auto_dir = $auto_root . '/' . $source;
		$prev = $auto_root . '/.' . $source . '.prev';
		if (is_dir($auto_dir)) {
			self::removeTree($prev);
			self::copyTree($auto_dir, $prev);
		}
		try {
			$want = array();
			foreach ($cves as $cve => $u) {
				$want[$cve] = array('fixed_in' => (string)($u['fixed_in'] ?? ''), 'ids' => self::idsFor($watch, $cve),
					'commits' => UpstreamFixWatch::commitsFor($watch, $cve));
			}
			$write = $opts['write'] ?? function ($source, $want) use ($state) {
				return PackageFixRecipeWriter::write($source, $want, array(
					'ubuntu_version' => PackageFixRecipeWriter::newestUbuntuVersion($source),
					'min_revision'   => self::maxRevision($state, $source),
					'withdrawn_revision' => self::withdrawnRevision($state, $source),
				));
			};
			try {
				$w = call_user_func($write, $source, $want);
			} catch (\Throwable $e) {
				throw new PackageFixAutoStop('finding the fix', $e->getMessage());
			}
			if (($w['status'] ?? '') === 'failed') {
				$reasons = array();
				foreach ((array)($w['failed'] ?? array()) as $cve => $why) { $reasons[] = $cve . ': ' . $why; }
				throw new PackageFixAutoStop('finding the fix', implode('; ', $reasons) ?: 'the recipe could not be written');
			}
			// Nothing written and no automatic recipe: a person's recipe already
			// carries what was asked (the writer started from it).
			$dir = (string)($w['dir'] ?? '');
			if (($w['status'] ?? '') === 'unchanged' && $dir !== '' && !is_file($dir . '/RECIPE')) {
				$state = $mark($state, 'carried', 'write', 'a person\'s recipe already carries it');
				$news[$source] = array('outcome' => 'carried', 'cves' => array_keys($cves));
				$said[] = $source . ': a person\'s recipe already carries it';
				return $state;
			}
			$build = $opts['build'] ?? function ($source) use ($site) { return PackageFixPublisher::buildRecipe($site, self::AUTO_DIR, $source); };
			try {
				$line = call_user_func($build, $source);
			} catch (\Throwable $e) {
				// Its patches must not be carried into the next fix's recipe: the
				// recipe the shipped build was made from goes back (B21).
				if ($dir !== '' && strpos($dir, $auto_root . '/') === 0) {
					self::removeTree($dir);
					if (is_dir($prev)) {
						@rename($prev, $dir);
					}
				}
				// Built on upstream's release because no commit fitted: say why
				// the commits did not, too, not only why the release failed.
				$why = $e->getMessage();
				if (($w['fallback'] ?? null) === 'upstream_release' && !empty($w['failed'])) {
					$why = 'upstream\'s fix commits did not fit (' . implode('; ', array_map(function ($c, $y) { return $c . ': ' . $y; },
						array_keys((array)$w['failed']), (array)$w['failed'])) . '), so upstream\'s fixed release was tried, and: ' . $why;
				}
				throw new PackageFixAutoStop('building and testing it', $why);
			}
			if (in_array((string)($line['version'] ?? ''), (array)($state['withdrawn'][$source] ?? array()), true)) {
				throw new PackageFixAutoStop('building and testing it', 'the build came out as ' . $line['version'] . ', a version already withdrawn');
			}
			self::removeTree($prev);
			$state['automatic'][$source] = $line;
			$state = $mark($state, 'built', 'build', ($w['fallback'] ?? null) === 'upstream_release'
				? 'built on upstream\'s fixed release' : 'built with upstream\'s fix commit');
			// A CVE the writer could not carry is still owed; say so. Built on
			// upstream's fixed release, the release carries every one.
			// One email for the package, listing each fix it could not carry.
			$left = array();
			foreach (($w['fallback'] ?? null) === 'upstream_release' ? array() : (array)($w['failed'] ?? array()) as $cve => $why) {
				if (isset($cves[$cve])) {
					$state['attempts'][$source . ' ' . $cve] = array('status' => 'needs_person', 'step' => 'finding the fix', 'reason' => $why, 'at' => $now, 'inputs' => $inputs);
					$left[$cve] = $why;
				}
			}
			$news[$source] = array('outcome' => 'built', 'cves' => array_values(array_diff(array_keys($cves), array_keys($left))),
				'version' => (string)$line['version'], 'left' => $left);
			$said[] = 'built ' . $source . ' ' . $line['version'];
		} catch (PackageFixAutoStop $e) {
			$state = $mark($state, 'needs_person', $e->step, $e->getMessage());
			$news[$source] = array('outcome' => 'needs_person', 'cves' => array_keys($cves), 'step' => $e->step, 'why' => $e->getMessage());
			$said[] = $source . ': stopped at ' . $e->step;
		}
		return $state;
	}

	/**
	 * The soak check; promotes or withdraws. A machine's row for a soaking fix
	 * is one of: holding it (installed at that version), waiting (not yet, or
	 * its download failed), refused (apt refused the source), or out of it (a
	 * container host taking it only for its containers' base, a machine that
	 * handed it back or runs a newer Ubuntu build): those never hold the soak.
	 */
	private static function soak(array $state, array $set, int $now, array $opts, array &$said, callable $dispatch, callable $send): array {
		$soak = $state['soak'];
		$soak['held'] = is_array($soak['held'] ?? null) ? $soak['held'] : array();
		$soak['refused'] = is_array($soak['refused'] ?? null) ? $soak['refused'] : array();
		$nodes = $opts['nodes'] ?? UpstreamFixWatch::node_summary();
		$fail_info = isset($opts['failures']) ? call_user_func($opts['failures'], (int)$soak['started']) : self::soakFailures((int)$soak['started']);
		// A plain list is every node failing (tests); otherwise failing, and the
		// nodes the plane could not reach whose own report says the site answers.
		$failing = array_key_exists('failing', $fail_info) ? (array)$fail_info['failing'] : $fail_info;
		$unreachable = (array)($fail_info['unreachable'] ?? array());
		$holding = array();
		$waiting = array();
		$bad = array();
		$refused_now = array();
		foreach ($nodes as $n) {
			$fresh = (int)($n['reported_at'] ?? 0) >= $now - self::FRESH_REPORT_SECONDS;
			// A machine that has not taken this set yet: its rows are the old
			// set's, so it is waited for on every soaking fix (B27).
			if (isset($n['channel']) && is_int($n['channel']) && $n['channel'] < (int)$soak['serial']) {
				foreach (array_keys($soak['sources']) as $source) {
					$waiting[$source][] = $n['name'] . ' (has not taken this set yet)';
				}
				continue;
			}
			foreach ($n['fixes'] ?? array() as $f) {
				$want = $soak['sources'][$f['source']] ?? null;
				if ($want === null || !empty($f['for_base']) || ($f['handed_back'] ?? null) !== null || ($f['ahead_of_build'] ?? null) !== null) {
					continue;
				}
				$key = (int)$n['id'] . '|' . $f['source'];
				if ($f['version'] === $want && $f['installed']) {
					$soak['held'][$key] = $soak['held'][$key] ?? $now;
					if (in_array((int)$n['id'], $failing, true)) {
						$bad[$f['source']][] = $n['name'];
					} elseif (isset($unreachable[(int)$n['id']])) {
						$waiting[$f['source']][] = $n['name'] . ' (' . $unreachable[(int)$n['id']] . ')';
					} elseif (!$fresh) {
						$waiting[$f['source']][] = $n['name'] . ' (no report in two hours)';
					} else {
						$holding[$f['source']][] = $n['name'];
					}
				} elseif ($f['version'] === $want && !empty($f['source_refused']) && empty($f['fetch_failed'])) {
					// Counted once per report, not once per run (B22).
					$was = is_array($soak['refused'][$key] ?? null) ? $soak['refused'][$key] : array('n' => 0, 'at' => 0);
					$at = (int)($n['reported_at'] ?? 0);
					$refused_now[$key] = $at > (int)$was['at'] ? array('n' => (int)$was['n'] + 1, 'at' => $at) : $was;
					if ($refused_now[$key]['n'] >= self::REFUSED_RUNS) {
						$bad[$f['source']][] = $n['name'] . ' (apt refused its package source)';
					} else {
						$waiting[$f['source']][] = $n['name'];
					}
				} else {
					$waiting[$f['source']][] = $n['name'];
				}
			}
		}
		$soak['refused'] = $refused_now;
		$state['soak'] = $soak;
		if ($bad) {
			foreach ($bad as $source => $names) {
				$version = $soak['sources'][$source];
				$why = 'on the test machines, ' . implode(', ', array_unique($names)) . ' failed after taking it';
				$state = self::withdraw($state, $source, $version, $why, $now);
				$state = self::tellNeedsPerson($state, $source, self::cvesOf($set, $source), 'the test machines', $why
					. ' (a failed service, the site not answering, or its agent going quiet). It was pulled back from every server, and the test servers went back to the build before it',
					$now, $dispatch, false, 'was pulled back',
					'look at ' . implode(', ', array_unique($names)) . ' on its node page. If the problem had nothing to do with this build, press Try again for '
					. IncidentSourceFixAheadOfUbuntu::package_name($source) . ' on the Fleet Security page: it is built again and goes back to the test servers.');
				$said[] = 'withdrew ' . $source . ' ' . $version . ': ' . $why;
			}
			// The withdrawal goes out on the next run, to every machine at once (step 3a).
			return $state;
		}
		$hours = (int)($opts['soak_hours'] ?? self::soakHours());
		// The clock runs from the newest holder's install, not from the issue.
		$since = 0;
		$all_held = true;
		foreach ($soak['sources'] as $source => $version) {
			if (empty($holding[$source]) || !empty($waiting[$source])) {
				$all_held = false;
			}
			foreach ($soak['held'] as $k => $t) {
				if ((explode('|', $k, 2)[1] ?? '') === $source) { $since = max($since, (int)$t); }
			}
		}
		$done = $all_held && $since > 0 && $now - $since >= $hours * 3600;
		if (!$done) {
			// Twice the soak with a fix not held everywhere: tell a person, once.
			if ($now - (int)$soak['started'] >= 2 * $hours * 3600) {
				foreach ($soak['sources'] as $source => $version) {
					if ((!empty($holding[$source]) && empty($waiting[$source])) || isset($state['notified']['stuck ' . $source . ' ' . $version])) {
						continue;
					}
					$state['notified']['stuck ' . $source . ' ' . $version] = $now;
					$state = self::tellNeedsPerson($state, $source, self::cvesOf($set, $source), 'the test machines',
						$version . ' is not held on every test machine after ' . (2 * $hours) . ' hours'
						. (!empty($waiting[$source]) ? ' (waiting: ' . implode(', ', array_unique($waiting[$source])) . ')' : ' (none of them runs it)'),
						$now, $dispatch, true, 'is stuck on the test servers',
						'look at the waiting servers on their node pages: a build is promoted only once every test server that takes it reports it installed and healthy.');
				}
			}
			$said[] = 'soaking serial ' . $soak['serial'] . ($since > 0 ? ' (' . round(($now - $since) / 3600, 1) . ' of ' . $hours . ' hours since the last install)' : ' (not installed yet)');
			return $state;
		}
		$r = $send($set, true);
		$state['wide_lines'] = $set;
		$state['wide_withdrawn'] = $state['withdrawn'];
		unset($state['soak']);
		$names = array();
		foreach ($soak['sources'] as $source => $version) {
			$names[] = IncidentSourceFixAheadOfUbuntu::package_name($source) . ' ' . $version;
		}
		call_user_func($dispatch, self::SIGNAL_ROLLED_OUT, array(
			'title' => 'Security fix rolled out: ' . implode(', ', $names),
			'body'  => 'It held on the test machines for ' . $hours . ' hours (' . implode('; ', array_map(function ($s, $n) {
				return IncidentSourceFixAheadOfUbuntu::package_name($s) . ': ' . implode(', ', array_unique($n));
			}, array_keys($holding), $holding)) . '). Every other machine takes it within the hour.',
			'link'  => self::LINK,
		));
		$said[] = 'rolled out serial ' . ($r['serial'] ?? '?') . ' to every machine';
		return $state;
	}

	/** Withdraw one build: never offered again, and machines holding it go back. */
	private static function withdraw(array $state, string $source, string $version, string $why, int $now): array {
		$state['withdrawn'][$source] = array_values(array_unique(array_merge((array)($state['withdrawn'][$source] ?? array()), array($version))));
		if (($state['automatic'][$source]['version'] ?? null) === $version) {
			unset($state['automatic'][$source]);
		}
		$state['withdrawals'][] = array('source' => $source, 'version' => $version, 'why' => $why, 'at' => $now);
		$state['withdrawals'] = array_slice($state['withdrawals'], -50);
		return $state;
	}

	/**
	 * Tell a person about a build already out on the test machines (withdrawn,
	 * or stuck there), once per package, step and set of CVEs.
	 */
	private static function tellNeedsPerson(array $state, string $source, array $cves, string $step, string $why, int $now, callable $dispatch, bool $force = false, string $headline = 'could not be finished automatically', string $todo = ''): array {
		$cves = self::sorted($cves);
		$key = 'needs_person ' . $source . ' ' . $step . ' ' . implode(',', $cves);
		if (isset($state['notified'][$key]) && !$force) {
			return $state;
		}
		$state['notified'][$key] = $now;
		$name = IncidentSourceFixAheadOfUbuntu::package_name($source);
		call_user_func($dispatch, self::SIGNAL_NEWS, array(
			'kind'  => 'needs_person',
			'title' => 'A person is needed: the ' . $name . ' security fix ' . $headline,
			'body'  => 'What: our own build of ' . $name . ' with ' . self::fixCount(count($cves)) . ' (' . implode(', ', $cves) . ").\n"
				. 'What happened: ' . $why . ".\n\n"
				. ($todo !== '' ? 'What to do: ' . $todo . "\n\n" : '')
				. 'Until then every server runs the build it had before, and Ubuntu\'s own update replaces it whenever Ubuntu ships one.',
			'link'  => self::LINK,
		));
		return $state;
	}

	/**
	 * The run's one email: each package with something new, what came of its
	 * attempt, then where every package stands. Pure.
	 *
	 * @param array $new   the watch's newly found CVEs (source, cve, rating, fixed_in, upstream_fixed)
	 * @param array $news  source => outcome of this run's attempt: outcome built|needs_person|carried,
	 *                     cves, and version + left (built) or step + why (needs_person)
	 * @param array $state the runner's record (attempts, soak, wide_lines, automatic)
	 * @param array $all   every CVE the watch says to build
	 */
	public static function newsMessage(array $new, array $news, array $state, array $all, int $soak_hours): array {
		$by = array();
		foreach ($new as $u) { $by[$u['source']][] = $u; }
		$sources = array_values(array_unique(array_merge(array_keys($by), array_keys($news))));
		$blocks = array();
		$stuck = array();
		$built = array();
		$top = '';
		foreach ($sources as $source) {
			$name = IncidentSourceFixAheadOfUbuntu::package_name($source);
			$lines = array();
			if (!empty($by[$source])) {
				$ratings = array();
				$fixed_in = '';
				foreach ($by[$source] as $u) {
					$ratings[(string)$u['rating']] = true;
					if ($u['rating'] === 'critical') { $top = 'critical'; }
					if (($u['upstream_fixed'] ?? null) === true && (string)($u['fixed_in'] ?? '') !== '') { $fixed_in = (string)$u['fixed_in']; }
				}
				$rating = isset($ratings['critical']) ? 'critical' : 'high';
				$ids = array_map(function ($u) { return $u['cve']; }, $by[$source]);
				$lines[] = $name . ': ' . count($ids) . ' new ' . $rating . '-rated security fix' . (count($ids) === 1 ? '' : 'es')
					. ' (' . self::cveList($ids) . ')' . ($fixed_in !== '' ? ', fixed upstream in ' . $fixed_in : '') . ', not yet shipped by Ubuntu.';
			} else {
				$lines[] = $name . ': tried again (' . self::cveList($news[$source]['cves'] ?? array()) . ').';
			}
			$n = $news[$source] ?? null;
			$outcome = $n['outcome'] ?? self::lastOutcome($state, $source);
			$hole = count($n['cves'] ?? ($by[$source] ?? array())) === 1 ? 'this hole' : 'these holes';
			if ($outcome === 'built') {
				$built[] = $name;
				$lines[] = 'Done automatically: we built our own ' . $name . ' with the fix' . (isset($n['version']) ? ' (' . $n['version'] . ')' : '')
					. '. It goes to the test servers now, and to every server once they have stayed healthy for ' . $soak_hours . ' hours. Nothing to do.';
				if (!empty($n['left'])) {
					$stuck[] = $name;
					$left = self::sorted(array_keys($n['left']));
					$lines[] = 'Not in this build: ' . self::cveList($left) . '. ' . ucfirst(self::plainReason('finding the fix', implode('; ', $n['left']))) . '.'
						. ' A person is needed for ' . (count($left) === 1 ? 'that one' : 'those') . ': ask a Claude session "build the package fix for '
						. $source . ', ' . implode(' ', $left) . '".';
				}
			} elseif ($outcome === 'carried') {
				$lines[] = 'Already covered: a fix built by hand carries it. Nothing to do.';
			} elseif ($outcome === 'needs_person') {
				$stuck[] = $name;
				$why = $n['why'] ?? '';
				$step = $n['step'] ?? '';
				if ($n === null) {
					foreach ((array)($state['attempts'] ?? array()) as $k => $a) {
						if (strpos($k, $source . ' ') === 0 && ($a['status'] ?? '') === 'needs_person') { $why = (string)$a['reason']; $step = (string)$a['step']; break; }
					}
				}
				$cves = self::sorted($n['cves'] ?? array_map(function ($u) { return $u['cve']; }, $by[$source] ?? array()));
				$lines[] = 'Not done: ' . self::plainReason($step, $why) . '.';
				$lines[] = 'Until someone builds it, every server keeps Ubuntu\'s ' . $name . ', which still has ' . $hole
					. '. Ubuntu\'s own update fixes ' . ($hole === 'this hole' ? 'it' : 'them') . ' whenever Ubuntu ships one.';
				$lines[] = 'What to do: ask a Claude session "build the package fix for ' . $source . ', ' . implode(' ', $cves) . '".';
			} else {
				$lines[] = 'Being built now; the next email says how it went.';
			}
			$blocks[] = implode("\n", $lines);
		}

		// Where everything stands, from the record: never "handled" for what is stuck.
		$stand = array();
		$name_of = function ($s) { return IncidentSourceFixAheadOfUbuntu::package_name($s); };
		$everywhere = array();
		foreach ((array)($state['wide_lines'] ?? array()) as $s => $l) {
			$everywhere[] = $name_of($s) . ' (' . self::fixCount(count(self::cvesOf($state['wide_lines'], $s))) . ')';
		}
		$testing = array();
		foreach (array_keys((array)($state['soak']['sources'] ?? array())) as $s) { $testing[$s] = $name_of($s); }
		foreach ($news as $s => $n) { if (($n['outcome'] ?? '') === 'built') { $testing[$s] = $name_of($s); } }
		$waiting = array();
		$open = array();
		foreach ($all as $u) { $open[$u['source'] . ' ' . $u['cve']] = $u['source']; }
		foreach ((array)($state['attempts'] ?? array()) as $k => $a) {
			if (isset($open[$k]) && ($a['status'] ?? '') === 'needs_person') { $waiting[$open[$k]] = ($waiting[$open[$k]] ?? 0) + 1; }
		}
		foreach ($news as $s => $n) {
			foreach (($n['outcome'] ?? '') === 'needs_person' ? (array)$n['cves'] : array_keys((array)($n['left'] ?? array())) as $c) {
				if (!isset($state['attempts'][$s . ' ' . $c]) || ($state['attempts'][$s . ' ' . $c]['status'] ?? '') !== 'needs_person') {
					$waiting[$s] = ($waiting[$s] ?? 0) + 1;
				}
			}
		}
		if ($everywhere) { $stand[] = '- On every server, our own build: ' . implode(', ', $everywhere); }
		if ($testing) { $stand[] = '- On the test servers now: ' . implode(', ', array_values($testing)); }
		if ($waiting) {
			ksort($waiting);
			$stand[] = '- Waiting for a person: ' . implode(', ', array_map(function ($s, $c) use ($name_of) { return $name_of($s) . ' (' . self::fixCount($c) . ')'; },
				array_keys($waiting), $waiting));
		}

		$parts = array();
		foreach ($sources as $source) {
			$n = $news[$source] ?? null;
			$outcome = $n['outcome'] ?? self::lastOutcome($state, $source);
			$name = $name_of($source);
			if ($outcome === 'built' && !empty($n['left'])) {
				$parts[] = $name . ' built (' . self::fixCount(count((array)$n['cves'])) . ', testing now), ' . count($n['left']) . ' need a person';
			} elseif ($outcome === 'built') {
				$parts[] = $name . ' built, testing now';
			} elseif ($outcome === 'needs_person') {
				$parts[] = 'a person is needed for ' . $name;
			} elseif ($outcome === 'carried') {
				$parts[] = $name . ' already covered';
			} else {
				$parts[] = $name;
			}
		}
		$title = 'Security fix' . (count($sources) === 1 ? '' : 'es') . ' ahead of Ubuntu: ' . implode('; ', $parts);
		if ($top === 'critical') { $title .= ' (critical)'; }
		return array(
			'kind'  => $stuck ? 'needs_person' : 'found',
			'title' => $title,
			'body'  => implode("\n\n", $blocks) . ($stand ? "\n\nWhere everything stands:\n" . implode("\n", $stand) : '')
				. "\n\nThe full detail for each fix is on the Fleet Security page.",
			'link'  => self::LINK,
		);
	}

	/**
	 * Why an attempt stopped, in plain words: the raw reason (patch rejects,
	 * build log lines) stays on the Fleet Security page. Pure.
	 */
	public static function plainReason(string $step, string $raw): string {
		if (preg_match('/no signing key (is committed )?for /', $raw)) {
			return 'upstream\'s signing key is not on file yet, so its release could not be checked';
		}
		if (strpos($raw, 'failed after taking it') !== false) {
			return $raw;
		}
		$no_apply = (bool)preg_match('/does not apply|hunks? FAILED/', $raw);
		$no_commit = (bool)preg_match('/no (upstream )?commit/', $raw);
		$release_blocked = strpos($raw, 'neither applies to upstream') !== false || strpos($raw, 'would not go on upstream') !== false;
		$release_tried = strpos($raw, 'so upstream\'s fixed release was tried') !== false;
		$first = '';
		if ($no_apply && $no_commit) {
			$first = 'upstream\'s fix does not fit Ubuntu\'s version of the code for some of them, and for the others we could not find upstream\'s exact fix';
		} elseif ($no_apply) {
			$first = 'upstream\'s fix does not fit Ubuntu\'s version of the code';
		} elseif ($no_commit) {
			$first = 'we could not find upstream\'s exact fix';
		}
		if ($release_blocked) {
			return ($first !== '' ? $first : 'upstream\'s fix could not be used') . ', and Ubuntu\'s own changes to the package do not fit upstream\'s fixed release';
		}
		if ($release_tried) {
			return ($first !== '' ? $first : 'upstream\'s fix could not be used') . ', and building on upstream\'s fixed release failed';
		}
		if ($first !== '') {
			return $first;
		}
		if ($step === 'building and testing it') {
			return 'the build or the package\'s own tests failed';
		}
		$plain = trim((string)preg_replace('/^(CVE-\d{4}-\d+: )+/', '', trim((string)preg_split('/[|\n;]/', $raw)[0])));
		return $plain !== '' ? rtrim(mb_substr($plain, 0, 200), '.') : 'it stopped at ' . ($step !== '' ? $step : 'an unknown step');
	}

	private static function lastOutcome(array $state, string $source): string {
		$out = '';
		foreach ((array)($state['attempts'] ?? array()) as $k => $a) {
			if (strpos($k, $source . ' ') === 0) {
				$out = (string)($a['status'] ?? '');
				if ($out === 'needs_person') { return $out; }
			}
		}
		return $out;
	}

	/** At most five CVE ids, then how many more. */
	private static function cveList(array $cves): string {
		$cves = self::sorted($cves);
		$more = count($cves) - 5;
		return implode(', ', array_slice($cves, 0, 5)) . ($more > 0 ? ' and ' . $more . ' more' : '');
	}

	private static function fixCount(int $n): string {
		return $n . ' fix' . ($n === 1 ? '' : 'es');
	}

	private static function sorted(array $a): array {
		$a = array_values(array_map('strval', $a));
		sort($a);
		return $a;
	}

	/** Lines in $now that are new or changed against $before: source => version. Pure. */
	public static function changedLines(array $before, array $now): array {
		$out = array();
		foreach ($now as $source => $line) {
			if (!isset($before[$source]) || $before[$source]['version'] !== $line['version'] || $before[$source]['sha256'] !== $line['sha256']) {
				$out[$source] = $line['version'];
			}
		}
		ksort($out);
		return $out;
	}

	/** The highest +joinery revision this box ever used for a source: the next build goes above it. */
	public static function maxRevision(array $state, string $source): int {
		$max = 0;
		$versions = array();
		foreach (array($state['automatic'] ?? array(), $state['wide_lines'] ?? array()) as $set) {
			if (isset($set[$source]['version'])) { $versions[] = $set[$source]['version']; }
		}
		foreach ((array)($state['withdrawn'][$source] ?? array()) as $v) { $versions[] = $v; }
		foreach ($versions as $v) {
			if (preg_match('/\+joinery(\d+)$/', (string)$v, $m)) { $max = max($max, (int)$m[1]); }
		}
		return $max;
	}

	/** The highest +joinery revision withdrawn for a source (0 when none). */
	public static function withdrawnRevision(array $state, string $source): int {
		$max = 0;
		foreach ((array)($state['withdrawn'][$source] ?? array()) as $v) {
			if (preg_match('/\+joinery(\d+)$/', (string)$v, $m)) { $max = max($max, (int)$m[1]); }
		}
		return $max;
	}

	/** The project's own ids for a CVE (PHP's GHSA), from the watch's stored driver answers. */
	private static function idsFor(array $watch, string $cve): array {
		$ids = array();
		foreach ($watch['drivers'] ?? array() as $d) {
			foreach ((array)($d['advisories'][$cve]['ids'] ?? array()) as $id) {
				if (is_string($id)) { $ids[] = $id; }
			}
		}
		return array_values(array_unique($ids));
	}

	private static function cvesOf(array $set, string $source): array {
		return isset($set[$source]['cves']) ? preg_split('/\s+/', trim((string)$set[$source]['cves'])) : array();
	}

	/**
	 * The soak's failures since $since: nodes with a failed-service, site-down
	 * or silent-agent incident opened since then. A site-down incident is not
	 * counted when the machine never answered the plane (refused, timed out)
	 * and its own report, read after the incident opened, says its site
	 * answers: the plane's path to it is broken (a ban, a route), not the
	 * build. Such a node is waited for instead.
	 *
	 * @return array{failing: list<int>, unreachable: array<int, string>}
	 */
	private static function soakFailures(int $since): array {
		$failing = array();
		$unreachable = array();
		foreach (self::SOAK_FAILURES as $source) {
			foreach (new MultiIncidentRecord(array('source' => $source, 'deleted' => false)) as $inc) {
				$t = strtotime((string)$inc->get('inc_opened_time') . ' UTC');
				if ($t === false || $t < $since) {
					continue;
				}
				$id = (int)$inc->get('inc_mgn_managed_node_id');
				if ($source === IncidentSourceSiteDown::NAME) {
					try {
						$node = new ManagedNode($id, TRUE);
						$excuse = self::siteDownExcuse($node->get('mgn_uptime_down_answered'), $node->get('mgn_last_host_report'), $t);
					} catch (\Throwable $e) {
						$excuse = null;
					}
					if ($excuse !== null) {
						$unreachable[$id] = $excuse;
						continue;
					}
				}
				$failing[] = $id;
			}
		}
		$failing = array_values(array_unique($failing));
		foreach ($failing as $id) { unset($unreachable[$id]); }
		return array('failing' => $failing, 'unreachable' => $unreachable);
	}

	/**
	 * Why a site-down incident opened at $opened is the plane's path rather
	 * than the machine, or null when it may be the machine. Pure.
	 *
	 * @param mixed $answered the node's mgn_uptime_down_answered: FALSE only when the
	 *                        failing probe got no answer at all
	 * @param mixed $report   the node's mgn_last_host_report (JSON or array)
	 */
	public static function siteDownExcuse($answered, $report, int $opened): ?string {
		if ($answered !== false && $answered !== 'f' && $answered !== 0 && $answered !== '0') {
			return null;
		}
		if (is_string($report)) {
			$report = json_decode($report, true);
		}
		if (!is_array($report) || (int)($report['generated_at'] ?? 0) < $opened) {
			return null;
		}
		$answers = is_array($report['answers'] ?? null) ? $report['answers'] : array();
		if (($answers['apache2'] ?? '') !== 'yes' || !in_array($answers['php-fpm'] ?? '', array('yes', 'quiet'), true)) {
			return null;
		}
		return 'the plane cannot reach its site, but the machine says its site answers: not counted as a failure';
	}

	private static function withdrawRequests(): array {
		$r = json_decode((string)Globalvars::get_instance()->get_setting(self::WITHDRAW_SETTING), true);
		return is_array($r) ? $r : array();
	}

	/**
	 * What could change a stopped attempt's outcome for $source, as one hash:
	 * its key file, the upstream list, RETRY_GENERATION, what the advisory says
	 * of each of its CVEs (fixed release, fix commits), and the UTC day, so an
	 * attempt stopped for want of a tag upstream had not published yet is tried
	 * again daily.
	 *
	 * @param array $items the watch's build items for $source
	 */
	public static function retryInputs(string $site, string $source, array $opts = array(), array $items = array(), array $watch = array(), int $now = 0): string {
		if (isset($opts['retry_inputs'])) {
			return (string)call_user_func($opts['retry_inputs'], $source);
		}
		$fixes = rtrim($site, '/') . '/' . PackageFixPublisher::SOURCE_SUBDIR;
		$said = array();
		foreach ($items as $u) {
			$commits = UpstreamFixWatch::commitsFor($watch, (string)$u['cve']);
			sort($commits);
			$said[] = $u['cve'] . '|' . (string)($u['fixed_in'] ?? '') . '|' . implode(',', $commits);
		}
		sort($said);
		return hash('sha256', self::RETRY_GENERATION . "\0" . (string)@file_get_contents($fixes . '/UPSTREAMS')
			. "\0" . (string)@file_get_contents($fixes . '/upstream_keys/' . $source . '.asc')
			. "\0" . implode("\n", $said) . "\0" . gmdate('Y-m-d', $now ?: time()));
	}

	private static function retryRequests(): array {
		$r = json_decode((string)Globalvars::get_instance()->get_setting(self::RETRY_SETTING), true);
		return is_array($r) ? $r : array();
	}

	/** Remove the Withdraw requests this run took; one pressed meanwhile stays. */
	private static function clearWithdrawRequests(array $taken): void {
		self::clearRequests(self::WITHDRAW_SETTING, $taken);
	}

	/** Remove the requests (source => version) this run took from $setting; one pressed meanwhile stays. */
	private static function clearRequests(string $setting, array $taken): void {
		Globalvars::get_instance()->forget_setting($setting);
		$left = json_decode((string)Globalvars::get_instance()->get_setting($setting), true);
		$left = is_array($left) ? $left : array();
		foreach ($taken as $source => $version) {
			if (($left[$source] ?? null) === $version) { unset($left[$source]); }
		}
		Setting::put($setting, $left ? json_encode($left, JSON_UNESCAPED_SLASHES) : '');
	}

	private static function copyTree(string $from, string $to): void {
		@mkdir($to, 0755, true);
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
		foreach ($it as $f) {
			$dest = $to . '/' . substr($f->getPathname(), strlen($from) + 1);
			if ($f->isLink()) { continue; }
			$f->isDir() ? @mkdir($dest, 0755, true) : @copy($f->getPathname(), $dest);
		}
	}

	private static function removeTree(string $dir): void {
		if (!is_dir($dir) || is_link($dir)) { return; }
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($it as $f) { $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
		@rmdir($dir);
	}

	public static function soakHours(): int {
		$h = (int)Globalvars::get_instance()->get_setting(self::SOAK_SETTING);
		return $h >= 1 ? $h : 6;
	}
}

/** The automatic path stopped at a step a person must take over. */
class PackageFixAutoStop extends Exception {
	public $step;
	public function __construct(string $step, string $why) {
		parent::__construct($why);
		$this->step = $step;
	}
}
?>
