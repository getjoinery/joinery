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
		// step() folds into $state as it goes: a run that stops keeps every
		// email it sent and every attempt and withdrawal it made.
		$handled = array();
		try {
			self::step($state, $site, $now, $opts, $said, $requests, $handled);
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
	private static function step(array &$state, string $site, int $now, array $opts, array &$said, array $requests, array &$handled): void {
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

		// 2. What the watch says to build: told once, then tried once per CVE.
		$watch = $opts['watch_state'] ?? UpstreamFixWatch::state();
		$c = $watch ? UpstreamFixWatch::conclusions($watch, $now) : array('build' => array());
		$new = array();
		foreach ($c['build'] as $u) {
			if (!isset($state['notified']['found ' . $u['source'] . ' ' . $u['cve']])) {
				$new[] = $u;
			}
		}
		if ($new) {
			$soak = (int)($opts['soak_hours'] ?? self::soakHours());
			call_user_func($dispatch, self::SIGNAL_NEWS, self::foundMessage($new, $c['build'], $soak));
			foreach ($new as $u) {
				$state['notified']['found ' . $u['source'] . ' ' . $u['cve']] = $now;
			}
			$said[] = 'told of ' . count($new) . ' new fix(es)';
		}
		$by_source = array();
		foreach ($c['build'] as $u) {
			if (!isset($state['attempts'][$u['source'] . ' ' . $u['cve']])) {
				$by_source[$u['source']][$u['cve']] = $u;
			}
		}
		foreach ($by_source as $source => $cves) {
			$state = self::attempt($state, $site, $source, $cves, $watch, $now, $opts, $said, $dispatch);
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
		if (!is_array($state['wide_lines'] ?? null)) {
			$served_wide = array_key_exists('served_wide', $opts) ? $opts['served_wide'] : PackageFixChannel::read(PackageFixChannel::wideDir($site));
			$state['wide_lines'] = is_array($served_wide['lines'] ?? null) ? $served_wide['lines'] : $tree;
		}
		$wide_lines = $state['wide_lines'];
		// A machine installed from a newer release refuses a channel issued
		// before it: issue again once this tree's release moves (B23).
		$release = (string)($opts['release'] ?? trim((string)@file_get_contents($site . '/public_html/VERSION')));
		$served_release = (string)($served['release'] ?? '');
		$stale = $served !== null && preg_match('/^\d+\.\d+\.\d+$/', $release) && preg_match('/^\d+\.\d+\.\d+$/', $served_release)
			&& version_compare($served_release, $release, '<');
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
	private static function attempt(array $state, string $site, string $source, array $cves, array $watch, int $now, array $opts, array &$said, callable $dispatch): array {
		$mark = function (array $state, string $status, string $step, string $reason) use ($source, $cves, $now) {
			foreach (array_keys($cves) as $cve) {
				$state['attempts'][$source . ' ' . $cve] = array('status' => $status, 'step' => $step, 'reason' => $reason, 'at' => $now);
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
				$want[$cve] = array('fixed_in' => (string)($u['fixed_in'] ?? ''), 'ids' => self::idsFor($watch, $cve));
			}
			$write = $opts['write'] ?? function ($source, $want) use ($state) {
				return PackageFixRecipeWriter::write($source, $want, array(
					'ubuntu_version' => PackageFixRecipeWriter::newestUbuntuVersion($source),
					'min_revision'   => self::maxRevision($state, $source),
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
				throw new PackageFixAutoStop('building and testing it', $e->getMessage());
			}
			self::removeTree($prev);
			$state['automatic'][$source] = $line;
			$state = $mark($state, 'built', 'build', ($w['fallback'] ?? null) === 'upstream_release'
				? 'built on upstream\'s fixed release' : 'built with upstream\'s fix commit');
			// A CVE the writer could not carry is still owed; say so. Built on
			// upstream's fixed release, the release carries every one.
			foreach (($w['fallback'] ?? null) === 'upstream_release' ? array() : (array)($w['failed'] ?? array()) as $cve => $why) {
				if (isset($cves[$cve])) {
					$state['attempts'][$source . ' ' . $cve] = array('status' => 'needs_person', 'step' => 'finding the fix', 'reason' => $why, 'at' => $now);
					$state = self::tellNeedsPerson($state, $source, array($cve), 'finding the fix', $why, $now, $dispatch);
				}
			}
			$said[] = 'built ' . $source . ' ' . $line['version'];
		} catch (PackageFixAutoStop $e) {
			$state = $mark($state, 'needs_person', $e->step, $e->getMessage());
			$state = self::tellNeedsPerson($state, $source, array_keys($cves), $e->step, $e->getMessage(), $now, $dispatch);
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
		$failing = isset($opts['failures']) ? call_user_func($opts['failures'], (int)$soak['started']) : self::failingNodes((int)$soak['started']);
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
					. '. It was withdrawn from every machine and the test machines went back to the build before it', $now, $dispatch);
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
						$now, $dispatch, true);
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

	/** Tell a person, once per package, step and set of CVEs. */
	private static function tellNeedsPerson(array $state, string $source, array $cves, string $step, string $why, int $now, callable $dispatch, bool $force = false): array {
		sort($cves);
		$key = 'needs_person ' . $source . ' ' . $step . ' ' . implode(',', $cves);
		if (isset($state['notified'][$key]) && !$force) {
			return $state;
		}
		$state['notified'][$key] = $now;
		$name = IncidentSourceFixAheadOfUbuntu::package_name($source);
		call_user_func($dispatch, self::SIGNAL_NEWS, array(
			'kind'  => 'needs_person',
			'title' => 'A person is needed: the ' . $name . ' security fix could not be finished automatically',
			'body'  => 'Fix: ' . implode(', ', $cves) . ".\n"
				. 'Stopped at: ' . $step . ".\n"
				. 'Why: ' . $why . ".\n\n"
				. 'Until then the servers that do not have it still run ' . $name . ' without this fix, and Ubuntu\'s own update clears it '
				. 'whenever Ubuntu ships one. To build it by hand, ask a Claude session: "build the package fix for ' . $source . ', '
				. implode(' ', $cves) . '".',
			'link'  => self::LINK,
		));
		return $state;
	}

	/** The "found" email: what is new, and everything still waiting, by package. */
	public static function foundMessage(array $new, array $all, int $soak_hours): array {
		$names = array();
		$top = '';
		foreach ($new as $u) {
			$names[$u['source']] = IncidentSourceFixAheadOfUbuntu::package_name($u['source']);
			if ($u['rating'] === 'critical') { $top = 'critical'; }
		}
		$top = $top ?: 'high';
		$lines = array();
		foreach (IncidentSourceFixAheadOfUbuntu::package_lines($new) as $l) {
			$lines[] = '- ' . $l;
		}
		$fixed_in = array();
		foreach ($new as $u) {
			if ($u['upstream_fixed'] === true && $u['fixed_in'] !== '') {
				$fixed_in[$names[$u['source']]] = $u['fixed_in'];
			}
		}
		$others = array_values(array_filter(IncidentSourceFixAheadOfUbuntu::package_lines(array_values(array_filter($all, function ($u) use ($new) {
			foreach ($new as $n) { if ($n['source'] === $u['source'] && $n['cve'] === $u['cve']) { return false; } }
			return true;
		})))));
		$body = (count($new) === 1 ? 'A project we run published a security fix that Ubuntu has not shipped yet:' : 'Projects we run published security fixes that Ubuntu has not shipped yet:')
			. "\n" . implode("\n", $lines)
			. ($fixed_in ? "\n\nFixed upstream in: " . implode('; ', array_map(function ($n, $v) { return $n . ' ' . $v; }, array_keys($fixed_in), $fixed_in)) . '.' : '')
			. "\n\nEvery server runs Ubuntu's build, so every server has " . (count($new) === 1 ? 'this hole' : 'these holes') . ' until it is fixed.'
			. "\n\nWhat happens now: we build our own with the fix, automatically. The test servers get it first, then every server once they "
			. 'have stayed healthy for ' . $soak_hours . ' hours. Nothing is needed from you unless a second email says a person is needed.'
			. ($others ? "\n\nAlso still waiting on Ubuntu, already being handled:\n- " . implode("\n- ", $others) : '');
		return array(
			'kind'  => 'found',
			'title' => 'Security fix' . (count($names) === 1 && count($new) === 1 ? '' : 'es') . ' ahead of Ubuntu: '
				. implode(', ', array_values($names)) . ' (' . $top . ')',
			'body'  => $body,
			'link'  => self::LINK,
		);
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

	/** Nodes with a soak-failing incident opened since $since. */
	private static function failingNodes(int $since): array {
		$ids = array();
		foreach (self::SOAK_FAILURES as $source) {
			foreach (new MultiIncidentRecord(array('source' => $source, 'deleted' => false)) as $inc) {
				$t = strtotime((string)$inc->get('inc_opened_time') . ' UTC');
				if ($t !== false && $t >= $since) {
					$ids[] = (int)$inc->get('inc_mgn_managed_node_id');
				}
			}
		}
		return array_values(array_unique($ids));
	}

	private static function withdrawRequests(): array {
		$r = json_decode((string)Globalvars::get_instance()->get_setting(self::WITHDRAW_SETTING), true);
		return is_array($r) ? $r : array();
	}

	/** Remove the requests this run took; one pressed meanwhile stays. */
	private static function clearWithdrawRequests(array $taken): void {
		Globalvars::get_instance()->forget_setting(self::WITHDRAW_SETTING);
		$left = self::withdrawRequests();
		foreach ($taken as $source => $version) {
			if (($left[$source] ?? null) === $version) { unset($left[$source]); }
		}
		Setting::put(self::WITHDRAW_SETTING, $left ? json_encode($left, JSON_UNESCAPED_SLASHES) : '');
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
