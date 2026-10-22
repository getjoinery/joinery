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
			'verdict'     => 'grey',
			'reasons'     => array(),
		);
		$red = array();
		$amber = array();
		$known = false;

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
					$amber[] = 'a reboot owed for over a day';
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
					$amber[] = count($others) . ' other service' . (count($others) === 1 ? ' runs' : 's run') . ' replaced code until '
						. (count($others) === 1 ? 'it restarts' : 'they restart') . ' (' . implode(', ', array_slice($others, 0, 4))
						. (count($others) > 4 ? ', and ' . (count($others) - 4) . ' more' : '') . ')';
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
					$amber[] = 'no check for fixes has passed in two hours';
				}
			} else {
				$amber[] = 'it does not look for security fixes yet';
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
					$amber[] = 'its host\'s base has not been checked in two hours';
				}
			} elseif ($host === null) {
				$amber[] = 'its host is not known here';
			} else {
				$amber[] = 'its host does not replace its containers when a fix ships';
			}
		}

		if ($row['code'] === 'old') {
			$red[] = 'old code is serving (' . implode(', ', array_unique($row['old_code'])) . ')';
		}
		if ($row['critical']) {
			$red[] = 'a critical fix waits (' . implode(', ', array_slice($row['critical'], 0, 4)) . ')';
		}
		if ($row['kernel']) {
			$amber[] = 'a kernel fix waits for the night, then a reboot';
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
				if ($critical) { $red[] = $why; } else { $amber[] = $why; }
			}
		}
		$others = ($row['pending'] ?? 0) - count($row['critical']) - count($row['kernel']);
		if ($row['pending'] !== null && $others > 0) {
			$amber[] = $others . ' other fix' . ($others === 1 ? '' : 'es') . ' wait for the next run';
		}

		if ($red) {
			$row['verdict'] = 'red';
		} elseif ($amber) {
			$row['verdict'] = 'amber';
		} elseif ($known) {
			$row['verdict'] = 'green';
		}
		$row['reasons'] = array_merge($red, $amber);
		if (!$known && !$row['reasons']) {
			// A node with no agent (the relay, the DNS servers: watched from
			// here, never given one) never reports; it is not waiting (B27).
			$row['reasons'][] = (($s['agent'] ?? true) === false) ? 'no agent reports from it, so its patching cannot be seen here' : 'nothing reported yet';
		}
		return $row;
	}
}
?>
