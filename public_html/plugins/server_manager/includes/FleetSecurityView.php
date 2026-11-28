<?php
/**
 * FleetSecurityView - every machine and site container, and whether it is
 * patched (specs/fleet_security_patching.md WP2).
 *
 * One row per operational node. A machine (a server, a container host) is read
 * from its own host report: its OS, the security fixes its hourly check found
 * waiting (security_pending), what still runs code an update replaced
 * (services_needing_restart), when automatic updates last ran, and a reboot
 * owed. A site container is read from its own status check (the PHP it runs,
 * and whether its php-fpm runs the binary installed) and from its host's
 * report: its OS is its host's base image, so the fixes it waits for are the
 * ones the host's base check found (site_refresh).
 *
 * The verdict, most serious first:
 *   red    old code is serving (a php-fpm on a replaced binary, or PHP-FPM,
 *          Apache or PostgreSQL among what needrestart names: the incident's
 *          own rule), or a critical fix waits (PHP, Apache, OpenSSL,
 *          PostgreSQL: one a machine installs at once, Q2), or one was left
 *          half done
 *   amber  other fixes wait (they go in with the next run: the night's, or an
 *          at-once install's), a kernel fix waits (the night installs it, a
 *          person chooses the reboot), a reboot is owed over a day, another
 *          service runs replaced code until it restarts, the
 *          check has not passed for two hours, or a package run was left half
 *          done (its own status check: what dpkg --audit lists, which the next
 *          install finishes). Packages half done under a run that holds its
 *          lock are its work in hand, not left half done, for two hours; past
 *          that the run is taken as stuck.
 *   grey   nothing reported to judge by
 *   green  none of these
 *
 * Pure apart from snapshots(), which reads the nodes; rows() takes the
 * snapshots, so the test drives it with arrays.
 *
 * Each reason is also an issue (row 'issues'): its level, what it asks of a
 * person (KINDS), and a few plain words for the page; summary() gathers them
 * into the page's one answer: is everything patched, and is anything mine to do.
 *
 * @version 1.9 - a machine on an Ubuntu our early fixes are not built for says it takes none (package_fixes.other_release);
 *               one that refused a newer early-fix list is red (channel_refused)
 * @version 1.8 - issues: each reason's kind (now, reboot, wait, look) and short words; summary() (the page's answer)
 * @version 1.7 - a package fix whose archive could not be downloaded says so, apart from apt refusing it (reviewer2 B9)
 * @version 1.6 - a machine's package fixes ahead of Ubuntu (host report package_fixes): each said in words, and one
 *                apt refused is red (specs/package_fixes_ahead_of_ubuntu.md WP6); one waiting to install is
 *                red through the critical list already, since a fix from our origin is critical
 * @version 1.5 - one waiting fix reads '1 other fix waits', not 'wait'
 * @version 1.4 - a node with no agent says so, not 'nothing reported yet' (B27); old code serving is red only for what serves the sites (PHP-FPM, Apache, PostgreSQL), as the
 *                incident counts it; any other service needrestart names is amber until it restarts: getjoinery was
 *                red for networkd-dispatcher (B25)
 * @version 1.3 - a package run left half done is amber, red where a critical package is among them, from the node's
 *                own status check (agent 1.90.0, B17); not while a run holds its lock, for two hours (reviewer2)
 * @version 1.2 - a node whose agent moved away is not listed: it is patched from where it is managed now
 * @version 1.1 - a kernel fix is amber, not red: nothing installs it before the night, and its reboot is a
 *                person's (reviewer2 G3); other fixes wait for the next run, which a critical fix brings forward
 * @version 1.0
 */
class FleetSecurityView {

	/**
	 * A package a critical fix arrives in. The same list as
	 * security_check.sh's; the test pins the two together.
	 */
	const CRITICAL_PATTERN = '/^(php[0-9]|libapache2-mod-php|apache2|libssl|openssl|postgresql|libpq|linux-image|linux-modules)/';

	/** A check older than this has missed two hourly runs. */
	const STALE_CHECK_SECONDS = 7200;

	/** A reboot owed longer than this is amber. */
	const REBOOT_GRACE_SECONDS = 86400;

	/**
	 * A package run holding its lock longer than this is taken as stuck (a
	 * question no one answers, a hang), and what it left half done is shown:
	 * past install_security_updates' own hour, and a night's unattended run.
	 */
	const RUN_STUCK_SECONDS = 7200;

	const ORDER = array('red' => 0, 'amber' => 1, 'grey' => 2, 'green' => 3);

	/**
	 * What an issue asks of a person, in the order the page lists them:
	 *   now     act: open the machine's page (Install security updates now, mostly)
	 *   reboot  updates are in; a restart finishes them, when convenient
	 *   wait    it fixes itself at the next run (tonight's, or the hour's)
	 *   look    the page cannot tell: a check not passing, a host it does not know
	 */
	const KINDS = array('now', 'reboot', 'look', 'wait');

	public static function is_critical_package(string $name): bool {
		return (bool)preg_match(self::CRITICAL_PATTERN, $name);
	}

	/**
	 * Every live node as the rows need it: name, whether it is a site
	 * container and on which host, its host report, its PHP and the packages
	 * a run left half done, each read through the processor's own sanitisers.
	 */
	public static function snapshots(): array {
		$out = array();
		$host_of_group = array();
		foreach (new MultiManagedNode(array('deleted' => false), array('mgn_name' => 'ASC')) as $node) {
			if (!$node->is_operational() || $node->agent_moved_away() !== null) {
				continue;
			}
			$hr = $node->get('mgn_last_host_report');
			if (is_string($hr)) { $hr = json_decode($hr, true); }
			$sd = $node->get('mgn_last_status_data');
			if (is_string($sd)) { $sd = json_decode($sd, true); }
			$group = (int)$node->get('mgn_mgh_managed_host_id');
			$container = trim((string)$node->get('mgn_container_name'));
			$host_id = null;
			if ($container !== '' && $group > 0) {
				if (!array_key_exists($group, $host_of_group)) {
					$host_of_group[$group] = null;
					try {
						$h = (new ManagedHost($group, TRUE))->host_node();
						$host_of_group[$group] = $h ? (int)$h->key : null;
					} catch (Exception $e) {
						$host_of_group[$group] = null;
					}
				}
				$host_id = $host_of_group[$group];
			}
			$out[] = array(
				'id'          => (int)$node->key,
				'name'        => (string)$node->get('mgn_name'),
				'container'   => $container,
				'host_id'     => $host_id,
				'host_report' => is_array($hr) ? JobResultProcessor::sanitise_host_report($hr) : null,
				'php'         => (is_array($sd) && is_array($sd['php'] ?? null)) ? JobResultProcessor::status_php($sd['php']) : null,
				'unfinished'  => is_array($sd) ? JobResultProcessor::status_dpkg_unfinished($sd['dpkg_unfinished'] ?? null) : null,
				'agent'       => trim((string)$node->get('mgn_agent_public_key')) !== '',
			);
		}
		return $out;
	}

	/** One package fix on a machine, in words (host report package_fixes). */
	public static function package_fix_words(array $f): string {
		if (!empty($f['fetch_failed'])) {
			return 'our build of ' . $f['source'] . ' ' . $f['version'] . ': could not be downloaded yet; tried again every hour';
		}
		if ($f['source_refused']) {
			return 'our build of ' . $f['source'] . ' ' . $f['version'] . ': apt refused its package source';
		}
		if ($f['handed_back'] !== null) {
			return $f['source'] . ' handed back to Ubuntu ' . $f['handed_back'];
		}
		if ($f['ahead_of_build'] !== null) {
			return $f['source'] . ': Ubuntu ' . $f['ahead_of_build'] . ' installed, ahead of our build'
				. ($f['ubuntu_lacks'] !== null ? ', and it lacks ' . implode(', ', $f['ubuntu_lacks']['cves'])
					: ($f['unreadable'] !== null ? '; its changelog could not be read' : ''));
		}
		if ($f['for_base'] ?? false) {
			return 'our build of ' . $f['source'] . ' ' . $f['version'] . ', for the site containers\' base';
		}
		$words = 'our build of ' . $f['source'] . ' ' . $f['version'] . ($f['installed'] ? '' : ', waiting to install');
		if ($f['ubuntu_lacks'] !== null) {
			$words .= '; Ubuntu\'s ' . $f['ubuntu_lacks']['version'] . ' lacks ' . implode(', ', $f['ubuntu_lacks']['cves']) . ', held';
		}
		return $words;
	}

	/** The rows, most serious first, then by name. */
	public static function rows(array $snapshots, int $now): array {
		$by_id = array();
		foreach ($snapshots as $s) {
			$by_id[$s['id']] = $s;
		}
		$rows = array();
		foreach ($snapshots as $s) {
			$host = ($s['container'] !== '' && $s['host_id'] !== null) ? ($by_id[$s['host_id']] ?? null) : null;
			$rows[] = self::row($s, $host, $now);
		}
		usort($rows, function ($a, $b) {
			return array(self::ORDER[$a['verdict']], strtolower($a['name'])) <=> array(self::ORDER[$b['verdict']], strtolower($b['name']));
		});
		return $rows;
	}

	/** One node's row. $host is a site container's host's snapshot, or null. */
	public static function row(array $s, ?array $host, int $now): array {
		$is_container = ($s['container'] !== '');
		$row = array(
			'id'          => $s['id'],
			'name'        => $s['name'],
			'kind'        => $is_container ? 'container' : 'machine',
			'host_id'     => $host ? $host['id'] : null,
			'host_name'   => $host ? $host['name'] : '',
			'os'          => '',
			'php'         => array(),
			'code'        => 'unknown',
			'old_code'    => array(),
			'pending'     => null,
			'critical'    => array(),
		'kernel'      => array(),
			'packages'    => array(),
			'checked_at'  => '',
			'updated_at'  => null,
			'reboot'      => null,
			'package_fixes' => array(),
			'verdict'     => 'grey',
			'reasons'     => array(),
		);
		$red = array();
		$amber = array();
		$issues = array();
		$known = false;
		// One reason: its long words on the reason list, its short ones on the page.
		$add = function (string $level, string $kind, string $long, string $short) use (&$red, &$amber, &$issues) {
			if ($level === 'red') { $red[] = $long; } else { $amber[] = $long; }
			$issues[] = array('level' => $level, 'kind' => $kind, 'text' => $short);
		};

		// The PHP it runs, and whether the code serving is the code installed.
		$php = $s['php'];
		if (is_array($php)) {
			foreach ($php['packages'] as $p) {
				$row['php'][] = $p['name'] . ' ' . $p['version'];
			}
			$current = null;
			foreach ($php['fpm'] as $m) {
				if ($m['running_current'] === false) {
					$row['old_code'][] = $m['binary'] !== '' ? basename($m['binary']) : 'php-fpm';
					$current = false;
				} elseif ($m['running_current'] === true && $current === null) {
					$current = true;
				}
			}
			if ($current !== null) {
				$row['code'] = $current ? 'current' : 'old';
				$known = true;
			}
		}

		$hr = $is_container ? ($host['host_report'] ?? null) : $s['host_report'];
		if (!$is_container && is_array($hr)) {
			$known = true;
			if (is_array($hr['os'] ?? null)) {
				$row['os'] = ($hr['os']['id'] === 'ubuntu' ? 'Ubuntu' : $hr['os']['id']) . ' ' . $hr['os']['version'];
			}
			$row['updated_at'] = is_int($hr['unattended_upgrades_last_run'] ?? null) ? $hr['unattended_upgrades_last_run'] : null;
			if (($hr['reboot_required'] ?? null) === true) {
				$since = $hr['reboot_required_since'] ?? null;
				$row['reboot'] = is_int($since) ? $since : 0;
				if (is_int($since) && $now - $since > self::REBOOT_GRACE_SECONDS) {
					$add('amber', 'reboot', 'a reboot owed for over a day', 'reboot owed since ' . gmdate('M j', $since));
				}
			}
			// What serves the sites on replaced code is old code serving;
			// any other service waits for its own restart (B25).
			$snr = $hr['services_needing_restart'] ?? null;
			if (is_array($snr) && $snr['services']) {
				$others = array();
				foreach ($snr['services'] as $svc) {
					if (preg_match(IncidentSourceOldCodeServing::SITE_SERVICES, $svc) || preg_match(IncidentSourceOldCodeServing::SITE_BINARIES, $svc)) {
						$row['old_code'][] = $svc;
						$row['code'] = 'old';
					} else {
						$others[] = $svc;
					}
				}
				if ($others) {
					$add('amber', 'reboot', count($others) . ' other service' . (count($others) === 1 ? ' runs' : 's run') . ' replaced code until '
						. (count($others) === 1 ? 'it restarts' : 'they restart') . ' (' . implode(', ', array_slice($others, 0, 4))
						. (count($others) > 4 ? ', and ' . (count($others) - 4) . ' more' : '') . ')',
						count($others) . ' background service' . (count($others) === 1 ? '' : 's') . ' on old code until a restart');
				}
			}
			// Our own builds ahead of Ubuntu: what each machine runs, in words.
			// One waiting to install is red already (security_check marks our
			// origin critical); one apt refused is not on any list, so it is
			// red here, naming the source.
			$pf = $hr['package_fixes'] ?? null;
			if (is_array($pf) && !empty($pf['other_release'])) {
				$add('amber', 'look', 'this machine runs Ubuntu ' . $pf['other_release'] . ', and our early fixes are built only for 26.04, so it takes none',
					'takes none of our early fixes (Ubuntu ' . $pf['other_release'] . ')');
			}
			if (is_array($pf) && !empty($pf['channel_refused'])) {
				$add('red', 'now', 'a newer list of our early fixes (serial ' . (int)$pf['channel_refused'] . ') did not verify here, so no new early fix is taken; its agent is likely older than the release keys',
					'refused our newest early-fix list');
			}
			if (is_array($pf)) {
				foreach ($pf['fixes'] as $f) {
					$row['package_fixes'][] = self::package_fix_words($f);
					if (!empty($f['fetch_failed'])) {
						$add('red', 'now', 'our build of ' . $f['source'] . ' ' . $f['version'] . ' could not be downloaded',
							'our ' . $f['source'] . ' fix could not be downloaded');
					} elseif ($f['source_refused']) {
						$add('red', 'now', 'apt refused our build of ' . $f['source'] . ' ' . $f['version'], 'our ' . $f['source'] . ' fix was refused by apt');
					}
					// On an Ubuntu build newer than ours that lacks our fix: exposed.
					if ($f['ahead_of_build'] !== null && $f['ubuntu_lacks'] !== null) {
						$add('red', 'now', 'Ubuntu ' . $f['ahead_of_build'] . ' of ' . $f['source'] . ' is installed and lacks ' . implode(', ', $f['ubuntu_lacks']['cves']),
							'a newer Ubuntu ' . $f['source'] . ' replaced our fix and lacks ' . count($f['ubuntu_lacks']['cves']) . ' of its fixes');
					} elseif ($f['unreadable'] !== null) {
						$add('amber', 'look', 'Ubuntu ' . $f['unreadable'] . '\'s changelog for ' . $f['source'] . ' could not be read',
							'cannot tell whether Ubuntu\'s new ' . $f['source'] . ' carries our fixes');
					}
				}
			}
			$sp = $hr['security_pending'] ?? null;
			if (is_array($sp) && $sp['check'] !== '') {
				$row['checked_at'] = $sp['checked_ok_at'];
				if ($sp['check'] === 'ok' || $sp['checked_ok_at'] !== '') {
					$row['pending'] = is_int($sp['pending']) ? $sp['pending'] : null;
					foreach ($sp['packages'] as $p) {
						$row['packages'][] = $p['package'] . ' ' . $p['candidate'];
						if ($p['critical'] && preg_match('/^linux-(image|modules)/', $p['package'])) {
							$row['kernel'][] = $p['package'];
						} elseif ($p['critical']) {
							$row['critical'][] = $p['package'];
						}
					}
				}
				$ok = $sp['checked_ok_at'] !== '' ? strtotime($sp['checked_ok_at']) : false;
				if ($ok === false || $now - $ok > self::STALE_CHECK_SECONDS) {
					$add('amber', 'look', 'no check for fixes has passed in two hours', 'its hourly check for fixes has not passed in two hours');
				}
			} else {
				$add('amber', 'look', 'it does not look for security fixes yet', 'it does not check for fixes yet');
			}
		} elseif ($is_container) {
			// Its OS is its host's base image: the fixes the base waits for.
			$sr = is_array($hr) ? ($hr['site_refresh'] ?? null) : null;
			if (is_array($sr)) {
				$known = true;
				$row['os'] = 'base ' . ($sr['base_version'] !== '' ? $sr['base_version'] : '?')
					. ($sr['base_built_at'] !== '' ? ', built ' . substr($sr['base_built_at'], 0, 10) : '');
				$row['checked_at'] = $sr['checked_ok_at'];
				$row['pending'] = is_int($sr['pending_security']) ? $sr['pending_security'] : null;
				foreach ($sr['security_packages'] as $p) {
					$row['packages'][] = $p;
					if (self::is_critical_package($p)) { $row['critical'][] = $p; }
				}
				foreach ($sr['replaced'] as $r) {
					if ($r['site'] === $s['container'] && $sr['ran_at'] !== '') {
						$row['updated_at'] = strtotime($sr['ran_at']) ?: null;
					}
				}
				$ok = $sr['checked_ok_at'] !== '' ? strtotime($sr['checked_ok_at']) : false;
				if ($ok === false || $now - $ok > self::STALE_CHECK_SECONDS) {
					$add('amber', 'look', 'its host\'s base has not been checked in two hours', 'its host has not checked for fixes in two hours');
				}
			} elseif ($host === null) {
				$add('amber', 'look', 'its host is not known here', 'its host is not known here');
			} else {
				$add('amber', 'look', 'its host does not replace its containers when a fix ships', 'its host does not replace it when a fix ships');
			}
		}

		if ($row['code'] === 'old') {
			$add('red', 'now', 'old code is serving (' . implode(', ', array_unique($row['old_code'])) . ')',
				'sites are served by old code (' . implode(', ', array_slice(array_unique($row['old_code']), 0, 2)) . ')');
		}
		if ($row['critical']) {
			$add('red', 'now', 'a critical fix waits (' . implode(', ', array_slice($row['critical'], 0, 4)) . ')',
				'a critical fix is not installed (' . implode(', ', array_slice($row['critical'], 0, 2)) . ')');
		}
		if ($row['kernel']) {
			$add('amber', 'wait', 'a kernel fix waits for the night, then a reboot', 'a kernel fix installs tonight; a reboot after');
		}
		// A package run that died part way leaves packages half done, and
		// nothing else says so until the next install finishes them. One
		// still holding its lock is at work on them, until it is taken as
		// stuck. A critical package among them is a critical fix that did not
		// land, which the pending list cannot show (apt counts it as unpacked
		// already); the kernel stays amber, as its pending fix does.
		$unfinished = $s['unfinished'] ?? null;
		if (is_array($unfinished) && $unfinished['count'] > 0) {
			// A holder whose start /proc would not give is almost always one
			// that ended between the two reads (reviewer2): silent, as under
			// two hours, and the next hourly check settles it.
			$running_for = ($unfinished['in_progress'] === true) ? ($unfinished['since'] !== null ? $now - $unfinished['since'] : 0) : null;
			if ($running_for === null || $running_for > self::RUN_STUCK_SECONDS) {
				$names = array();
				$critical = false;
				foreach ($unfinished['packages'] as $i => $p) {
					if ($i < 4) { $names[] = $p['name'] . ' ' . $p['state']; }
					if (self::is_critical_package($p['name']) && !preg_match('/^linux-(image|modules)/', $p['name'])) { $critical = true; }
				}
				$more = $unfinished['count'] - count($names);
				$what = '(' . implode(', ', $names) . ($more > 0 ? ', and ' . $more . ' more' : '') . ')';
				if ($unfinished['in_progress'] === true) {
					$why = 'a package run has held its lock for over ' . (int)floor($running_for / 3600) . ' hours'
						. ' with packages half done ' . $what . ': it may be stuck';
				} else {
					$why = ($critical ? 'a critical fix was' : 'a package run was') . ' left half done ' . $what . ': Install security updates now finishes it';
				}
				$add($critical ? 'red' : 'amber', 'now', $why, $unfinished['in_progress'] === true
					? 'an update run may be stuck' : ($critical ? 'a critical fix was left half installed' : 'an update run was left half done'));
			}
		}
		$others = ($row['pending'] ?? 0) - count($row['critical']) - count($row['kernel']);
		if ($row['pending'] !== null && $others > 0) {
			$add('amber', 'wait', $others . ' other ' . ($others === 1 ? 'fix waits' : 'fixes wait') . ' for the next run',
				$others . ' minor fix' . ($others === 1 ? ' installs' : 'es install') . ' tonight');
		}

		if ($red) {
			$row['verdict'] = 'red';
		} elseif ($amber) {
			$row['verdict'] = 'amber';
		} elseif ($known) {
			$row['verdict'] = 'green';
		}
		$row['reasons'] = array_merge($red, $amber);
		usort($issues, function ($a, $b) { return ($a['level'] === 'red' ? 0 : 1) <=> ($b['level'] === 'red' ? 0 : 1); });
		if (!$known && !$row['reasons']) {
			// A node with no agent (the relay, the DNS servers: watched from
			// here, never given one) never reports; it is not waiting (B27).
			$no_agent = (($s['agent'] ?? true) === false);
			$row['reasons'][] = $no_agent ? 'no agent reports from it, so its patching cannot be seen here' : 'nothing reported yet';
			$issues[] = array('level' => 'grey', 'kind' => 'unseen', 'text' => $no_agent ? 'no agent, so it cannot be seen from here' : 'nothing reported yet');
		}
		$row['issues'] = $issues;
		return $row;
	}

	/**
	 * The page's answer, from the rows: how many are patched, and each kind of
	 * issue with the rows it is on ('now' => [[row, [texts]], ...], ...),
	 * 'unseen' for rows nothing reports from.
	 */
	public static function summary(array $rows): array {
		$out = array('total' => count($rows), 'up_to_date' => 0, 'unseen' => array());
		foreach (self::KINDS as $k) { $out[$k] = array(); }
		foreach ($rows as $r) {
			$by = array();
			foreach ($r['issues'] as $i) {
				$by[$i['kind']][] = $i['text'];
			}
			if (isset($by['unseen'])) {
				$out['unseen'][] = array($r, $by['unseen']);
				continue;
			}
			if (!$by) { $out['up_to_date']++; }
			foreach (self::KINDS as $k) {
				if (isset($by[$k])) { $out[$k][] = array($r, $by[$k]); }
			}
		}
		return $out;
	}
}
?>
