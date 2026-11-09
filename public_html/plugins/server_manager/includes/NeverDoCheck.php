<?php
/**
 * NeverDoCheck - the management node's half of the never-do list
 * (specs/node_unexplained_root.md § 5.1, specs/never_do_false_alarms.md; the
 * never_do_check observe word, agent 1.106.0, runs
 * maintenance_scripts/sysadmin_tools/never_do_check.sh on the machine).
 *
 * Each report is sorted two ways, and the difference is the whole design.
 *
 * THE ALARM is only what nothing Joinery, Ubuntu's own packages or a normal
 * operator does can put on a machine (ALARM_KINDS): a setuid-root program no
 * package installed, a setuid or setgid program a package installed whose
 * bytes changed, an account other than root with uid 0, and a library no
 * package installed in /etc/ld.so.preload. No job window excuses one. An item
 * is held only when two reports in a row show it (a check landing inside a
 * package upgrade can see a file before its checksum list), and stays held
 * until a person presses Mark safe, even when a later report no longer shows
 * it: an attacker who removes what they added does not remove the record of
 * it. Mark safe remembers each item by path and content hash, so the same file
 * is quiet afterwards and a change to it alarms again. IncidentSourceNeverDo
 * raises while anything is held here.
 *
 * THE REVIEW LIST is everything else the check sees, each of which can happen
 * legitimately: other setuid or setgid programs no package installed, kernel
 * taint (a DKMS driver), programs in scratch directories (an installer), root
 * programs whose file is gone (a package removed under a running service), a
 * packaged library in ld.so.preload, a cut login log, and every inventory
 * change (SSH keys, login accounts, sudoers, unit and cron files, ports). It
 * raises nothing. The inventory is compared against a baseline kept here: the
 * first complete report is it, and a change is taken into it when this
 * plane's own job history explains it (a machine-changing job ran in the
 * window). Clear makes the machine's last report what is expected.
 *
 * The machine keeps no memory of its lists. This side does, on the node row
 * (mgn_never_do), so an attacker who owns the machine cannot rewrite what it
 * used to be.
 *
 * A machine on a release older than script 1.7 reports format 1: setuid items
 * are paths alone, setgid programs are among the setuid ones, and ld.so.preload
 * is one string. Those kinds are read as they always were (the alarm), so they
 * alarm no more than before; everything else goes on the review list.
 *
 * @version 1.2 - a host's job window also counts its own container sites' jobs: a site upgrade there brings
 *                the release the host runs this check from (docker-prod listed its engine files after one)
 * @version 1.1 - the alarm (ALARM_KINDS, two checks in a row, Mark safe remembers path and hash; a held item
 *                takes the file's current hash; an alarm list the script cut keeps its first sightings, as a
 *                partial run does) and the review list (no incident, Clear, which remembers findings present
 *                job window or not, and keeps one row per program); format 2 reports; a state from 1.0 is sorted on read
 * @version 1.0
 */
class NeverDoCheck {

	/** The inventory lists the plane compares. */
	const INVENTORY = array('ssh_keys', 'accounts', 'sudoers', 'startup', 'ports');

	/** What the alarm holds: nothing legitimate puts these on a machine. */
	const ALARM_KINDS = array('setuid_unpackaged', 'setuid_modified', 'uid0_accounts', 'ld_preload');

	/** Alarm kinds whose item is "path hash" (format 2) or a path (format 1). */
	const HASHED_KINDS = array('setuid_unpackaged', 'setuid_modified', 'ld_preload');

	/** Finding lists the script reports. */
	const FINDING_LISTS = array('setuid_unpackaged', 'setuid_modified', 'setid_other', 'setuid_copied_root', 'uid0_accounts',
		'scratch_programs', 'deleted_root_programs', 'ld_preload_unpackaged', 'ld_preload_packaged');

	/** Finding lists the alarm reads: the script keeps up to MAX_ALARM of each. */
	const ALARM_LISTS = array('setuid_unpackaged', 'setuid_modified', 'uid0_accounts', 'ld_preload_unpackaged');

	/** Review-list findings an explaining job's window excuses. */
	const SOFT_FINDINGS = array('scratch_programs', 'deleted_root_programs');

	/** What each kind of item is called on the page and in the incident. */
	const KIND_WORDS = array(
		'setuid_unpackaged'     => 'setuid-root program no package installed',
		'setuid_modified'       => 'setuid program changed since its package installed it',
		'uid0_accounts'         => 'account other than root with uid 0',
		'ld_preload'            => '/etc/ld.so.preload loads a library into every program',
		'setid_other'           => 'setgid program, or one setuid to an account other than root, no package installed',
		'setuid_copied_root'    => 'setuid program inside a copied root filesystem (an image or a build tree)',
		'kernel_taint'          => 'kernel tainted by a module (F forced, O out-of-tree, E unsigned)',
		'ld_preload_packaged'   => '/etc/ld.so.preload names a packaged library, or one that is not there',
		'scratch_programs'      => 'program running from /tmp, /var/tmp, /dev/shm or memory',
		'deleted_root_programs' => 'root program whose file is gone',
		'ssh_keys'              => 'new SSH key',
		'accounts'              => 'new account that can log in',
		'sudoers'               => 'new or changed sudoers file',
		'startup'               => 'new or changed unit or cron file',
		'ports'                 => 'new listening port',
		'log_cut'               => 'login log got shorter without being rotated',
	);

	/**
	 * The job types that change a machine. One of these running, or finished
	 * within EXPLAIN_WINDOW_SECONDS before a report, explains what that report
	 * finds new: an upgrade writes units, a site placement opens a port. A type
	 * missing here costs a false alarm and an Accept, never a miss.
	 */
	const EXPLAINING_TYPES = array(
		'apply_update', 'agent_converge', 'host_converge', 'run_installer', 'run_plugin_installers',
		'install_security_updates', 'refresh_site_containers', 'recreate_site', 'site_install', 'site_create', 'install_node',
		'copy_import', 'copy_restore', 'copy_stage', 'move_to_plane', 'fleet_enroll', 'take_node_id',
		'data_root_encrypt', 'data_root_decrypt', 'data_root_enrol', 'data_root_migrate', 'data_root_rekey',
		'data_root_provider_layer', 'data_root_recipient',
		'decommission_site', 'decommission_moved_site', 'disable_root_login',
		'provision_certificate', 'remove_site_certificate', 'site_certificate_stage',
		'outbound_limits', 'site_limits', 'hold_container', 'restart_container', 'restart_unit',
		'reset_failed_unit', 'restart_agent', 'reboot_machine', 'trust_forwarder', 'hosted_mail_settings',
		'managed_domain_prepare', 'suspended_page', 'publish_upgrade',
		'restore_project', 'restore_chain', 'restore_database', 'restore_objects',
	);

	/** How long after a machine-changing job its changes are still its. */
	const EXPLAIN_WINDOW_SECONDS = 1800;

	/** Items kept at most in the alarm, the review list and the marked-safe set. */
	const MAX_HELD = 200;

	/** Items kept per inventory list and per findings list (the script's caps). */
	const MAX_ITEMS = 400;
	const MAX_FINDINGS = 20;
	const MAX_ALARM = 200;

	/** An item as the script composes it: safe characters, two fields and an arrow at most. */
	const ITEM_PATTERN = '#^[A-Za-z0-9._/@:+=,*%? >-]{1,420}$#';

	/**
	 * The script's object held to its shape, or null when it is not one.
	 * Pure; the unit test drives it.
	 */
	public static function sanitise($d): ?array {
		if (!is_array($d) || !is_array($d['findings'] ?? null) || !is_array($d['inventory'] ?? null)) {
			return null;
		}
		$f = $d['findings'];
		$findings = array();
		foreach (self::FINDING_LISTS as $k) {
			$findings[$k] = self::items($f[$k] ?? array(), in_array($k, self::ALARM_LISTS, true) ? self::MAX_ALARM : self::MAX_FINDINGS);
		}
		$taint = is_string($f['kernel_taint'] ?? null) ? $f['kernel_taint'] : '';
		$findings['kernel_taint'] = preg_match('/^[FOE]{0,3}$/', $taint) ? $taint : '?';
		$preload = is_string($f['ld_preload'] ?? null) ? $f['ld_preload'] : '';
		$findings['ld_preload'] = ($preload === '' || preg_match(self::ITEM_PATTERN, $preload)) ? $preload : '?';

		$inv = $d['inventory'];
		$inventory = array();
		foreach (self::INVENTORY as $k) {
			$inventory[$k] = self::items($inv[$k] ?? array(), self::MAX_ITEMS);
		}
		$logs = array();
		foreach (self::items($inv['logs'] ?? array(), self::MAX_FINDINGS) as $line) {
			if (preg_match('#^(/var/log/[A-Za-z0-9._-]+) ([0-9]{1,20}) ([0-9]{1,20})$#', $line, $m)) {
				$logs[$m[1]] = array((int)$m[2], (int)$m[3]);
			}
		}
		$inventory['logs'] = $logs;

		$truncated = array();
		foreach ((array)($d['truncated'] ?? array()) as $t) {
			if (is_string($t) && preg_match('/^[a-z_0-9]{1,40}$/', $t)) { $truncated[] = $t; }
		}
		$at = $d['generated_at'] ?? null;
		return array(
			'format'       => (($d['format'] ?? 1) === 2) ? 2 : 1,
			'findings'     => $findings,
			'inventory'    => $inventory,
			'truncated'    => $truncated,
			'partial'      => ($d['partial'] ?? true) !== false,
			'generated_at' => is_int($at) ? $at : null,
		);
	}

	private static function items($list, int $cap): array {
		$out = array();
		if (!is_array($list)) { return $out; }
		foreach (array_slice(array_values($list), 0, $cap) as $s) {
			if (is_string($s) && preg_match(self::ITEM_PATTERN, $s)) { $out[] = $s; }
		}
		return array_values(array_unique($out));
	}

	/**
	 * The report's alarm items, as [kind, item] pairs. A format-1 report's
	 * setuid lists hold setgid programs too and its preload is one string:
	 * they alarm as they always did. Pure.
	 */
	public static function alarm_items(array $report): array {
		$f = $report['findings'];
		$out = array();
		foreach (array('setuid_unpackaged', 'setuid_modified', 'uid0_accounts') as $k) {
			foreach ($f[$k] as $item) { $out[] = array($k, $item); }
		}
		if ($report['format'] >= 2) {
			foreach ($f['ld_preload_unpackaged'] as $item) { $out[] = array('ld_preload', $item); }
		} elseif ($f['ld_preload'] !== '') {
			$out[] = array('ld_preload', $f['ld_preload']);
		}
		return $out;
	}

	/** The report's review-list findings, as "kind|item" keys. Pure. */
	private static function review_findings(array $report, bool $explained): array {
		$f = $report['findings'];
		$out = array();
		if ($report['format'] >= 2) {
			foreach ($f['setid_other'] as $item) { $out[] = 'setid_other|' . $item; }
			foreach ($f['setuid_copied_root'] as $item) { $out[] = 'setuid_copied_root|' . $item; }
			foreach ($f['ld_preload_packaged'] as $item) { $out[] = 'ld_preload_packaged|' . $item; }
		}
		if ($f['kernel_taint'] !== '') { $out[] = 'kernel_taint|' . $f['kernel_taint']; }
		if (!$explained) {
			foreach (self::SOFT_FINDINGS as $k) {
				foreach ($f[$k] as $item) { $out[] = $k . '|' . $item; }
			}
		}
		return $out;
	}

	/** An item's path: its first field. */
	private static function path_of(string $item): string {
		$sp = strpos($item, ' ');
		return $sp === false ? $item : substr($item, 0, $sp);
	}

	/**
	 * The same thing on the machine: the same kind and path. With $by_content,
	 * the same content too, when both sides carry a hash; a path alone (a
	 * format-1 report, or a Mark safe from one) matches its path.
	 */
	private static function same(array $a, string $kind, string $item, bool $by_content): bool {
		if ($a['kind'] !== $kind) { return false; }
		if ($a['item'] === $item) { return true; }
		if (!in_array($kind, self::HASHED_KINDS, true)) { return false; }
		if (self::path_of($a['item']) !== self::path_of($item)) { return false; }
		if (!$by_content) { return true; }
		return strpos($a['item'], ' ') === false || strpos($item, ' ') === false;
	}

	/**
	 * A stored state in this version's shape. A 1.0 state held one list,
	 * unexplained: its alarm kinds stay in the alarm and the rest moves to the
	 * review list, which raises nothing, so their incidents clear (moved_at
	 * says why). Pure.
	 */
	public static function upgrade_state(array $s, int $now): array {
		if (array_key_exists('unexplained', $s) && !array_key_exists('alarm', $s)) {
			$alarm = array(); $review = array();
			foreach ((array)$s['unexplained'] as $u) {
				if (!is_array($u) || !isset($u['kind'], $u['item'])) { continue; }
				$row = array('kind' => (string)$u['kind'], 'item' => (string)$u['item'], 'seen' => (int)($u['seen'] ?? $now));
				if (in_array($row['kind'], self::ALARM_KINDS, true)) { $alarm[] = $row; } else { $review[] = $row; }
			}
			unset($s['unexplained']);
			$s['alarm'] = $alarm;
			$s['review'] = $review;
			if ($review) { $s['moved_at'] = $now; }
		}
		foreach (array('alarm', 'pending', 'accepted', 'review', 'findings_known') as $k) {
			$s[$k] = is_array($s[$k] ?? null) ? array_values($s[$k]) : array();
		}
		return $s;
	}

	/**
	 * Fold one sanitised report into the node's state. Pure: $explained says
	 * whether a machine-changing job covers this report's window (the review
	 * list's excuse; the alarm takes none).
	 *
	 * @param array|null $state  the node's stored state, null before the first report
	 * @param array      $report sanitise()'s answer
	 * @return array the new state
	 */
	public static function fold(?array $state, array $report, bool $explained, int $now): array {
		$state = self::upgrade_state(is_array($state) ? $state : array(), $now);
		$complete = empty($report['partial']);

		// The alarm: held on the second report in a row, quiet once marked safe.
		$alarm = $state['alarm'];
		$accepted = $state['accepted'];
		$pending_before = $state['pending'];
		$pending = array();
		$seen_paths = array();
		foreach (self::alarm_items($report) as list($kind, $item)) {
			$seen_paths[$kind . '|' . self::path_of($item)] = true;
			$known = false;
			foreach ($accepted as $i => $a) {
				if (self::same($a, $kind, $item, true)) {
					// A path marked safe from a format-1 report takes the content first seen.
					if (strpos($a['item'], ' ') === false && strpos($item, ' ') !== false) { $accepted[$i]['item'] = $item; }
					$known = true;
					break;
				}
			}
			if ($known) { continue; }
			foreach ($alarm as $i => $h) {
				if (self::same($h, $kind, $item, false)) {
					// Held already: it takes the file's current content, so Mark
					// safe remembers what is there now, not what was first seen.
					if ($h['item'] !== $item && strpos($item, ' ') !== false) { $alarm[$i]['item'] = $item; }
					$known = true;
					break;
				}
			}
			if ($known) { continue; }
			$first = null;
			foreach ($pending_before as $p) {
				if (self::same($p, $kind, $item, false)) { $first = (int)$p['seen']; break; }
			}
			if ($first !== null) {
				if (count($alarm) < self::MAX_HELD) {
					$alarm[] = array('kind' => $kind, 'item' => $item, 'seen' => $first, 'confirmed' => $now);
				}
			} else {
				$pending[] = array('kind' => $kind, 'item' => $item, 'seen' => $now);
			}
		}
		// A run that could not see everything (the setuid walk ran out of time,
		// or an alarm list the script cut) does not forget what the one before
		// it saw.
		$alarm_cut = array_intersect(self::ALARM_LISTS, $report['truncated']);
		if (!$complete || $alarm_cut) {
			foreach ($pending_before as $p) {
				if (empty($seen_paths[$p['kind'] . '|' . self::path_of($p['item'])])) { $pending[] = $p; }
			}
		}

		// The review list.
		$review = $state['review'];
		// One entry per file: a listed program whose content changes (a copied
		// root rebuilt) takes its current hash rather than adding a second row.
		$add = function ($kind, $item) use (&$review, $now) {
			foreach ($review as $i => $u) {
				if ($u['kind'] !== $kind) { continue; }
				if ($u['item'] === $item) { return; }
				if (in_array($kind, array('setid_other', 'setuid_copied_root'), true)
						&& self::path_of($u['item']) === self::path_of($item)) {
					$review[$i]['item'] = $item;
					return;
				}
			}
			if (count($review) < self::MAX_HELD) {
				$review[] = array('kind' => $kind, 'item' => $item, 'seen' => $now);
			}
		};
		// Findings, against what was there at the last Clear (findings_known):
		// a taint that stays is listed once, not again after every Clear.
		$found = self::review_findings($report, $explained);
		foreach ($found as $key) {
			if (in_array($key, $state['findings_known'], true)) { continue; }
			list($kind, $item) = explode('|', $key, 2);
			$add($kind, $item);
		}
		// Pruned against what is there, job window or not: a Cleared program in
		// /tmp is not forgotten because a job ran while it was still there.
		$known = $state['findings_known'];
		$present = self::review_findings($report, false);
		if ($complete) { $known = array_values(array_intersect($known, $present)); }

		// The inventory, only from a complete run as root: a partial one
		// cannot see every home or process, so it neither lists nor teaches.
		$baseline = is_array($state['baseline'] ?? null) ? $state['baseline'] : null;
		$logs_before = is_array($state['logs'] ?? null) ? $state['logs'] : array();
		$inv = $report['inventory'];
		if ($complete) {
			if ($baseline === null) {
				$baseline = array();
				foreach (self::INVENTORY as $k) { $baseline[$k] = $inv[$k]; }
			} else {
				foreach (self::INVENTORY as $k) {
					$was = is_array($baseline[$k] ?? null) ? $baseline[$k] : array();
					$new = array_values(array_diff($inv[$k], $was));
					if ($explained) {
						$was = array_values(array_unique(array_merge($was, $new)));
					} else {
						foreach ($new as $item) { $add($k, $item); }
					}
					// What is gone leaves the baseline, so its return is seen;
					// a list the script cut keeps what it could not show.
					if (!in_array($k, $report['truncated'], true)) {
						$was = array_values(array_intersect($was, $inv[$k]));
					}
					$baseline[$k] = $was;
				}
			}
			foreach ($inv['logs'] as $path => $now_is) {
				$before = $logs_before[$path] ?? null;
				if (is_array($before) && $before[0] === $now_is[0] && $now_is[1] < $before[1]) {
					$add('log_cut', $path . ' ' . $before[1] . ' -> ' . $now_is[1]);
				}
			}
			$logs_before = $inv['logs'];
		}

		$state['alarm'] = $alarm;
		$state['pending'] = $pending;
		$state['accepted'] = $accepted;
		$state['review'] = $review;
		$state['findings_known'] = $known;
		$state['findings_current'] = $present;
		$state['baseline'] = $baseline;
		$state['current'] = $complete ? array_intersect_key($inv, array_flip(self::INVENTORY)) : ($state['current'] ?? null);
		$state['logs'] = $logs_before;
		$state['partial'] = !$complete;
		$state['truncated'] = $report['truncated'];
		$state['format'] = $report['format'];
		$state['checked_at'] = $now;
		return $state;
	}

	/**
	 * Mark safe: a person says what the alarm holds is theirs. Each item is
	 * remembered by path and content, so the same file stays quiet and a
	 * change to it alarms again. The review list is not touched. Pure.
	 */
	public static function accept(array $state, int $user_id, int $now): array {
		$state = self::upgrade_state($state, $now);
		foreach ($state['alarm'] as $h) {
			$state['accepted'][] = array('kind' => $h['kind'], 'item' => $h['item'], 'at' => $now, 'by' => $user_id);
		}
		$state['accepted'] = array_slice($state['accepted'], -self::MAX_HELD);
		$state['alarm'] = array();
		$state['accepted_at'] = $now;
		$state['accepted_by'] = $user_id;
		return $state;
	}

	/**
	 * Clear the review list: what the machine last showed becomes what is
	 * expected, and nothing is listed. The alarm is not touched. Pure.
	 */
	public static function clear(array $state, int $user_id, int $now): array {
		$state = self::upgrade_state($state, $now);
		if (is_array($state['current'] ?? null)) {
			$state['baseline'] = $state['current'];
		}
		$state['findings_known'] = is_array($state['findings_current'] ?? null) ? $state['findings_current'] : array();
		$state['review'] = array();
		$state['cleared_at'] = $now;
		$state['cleared_by'] = $user_id;
		return $state;
	}

	/**
	 * Whether a machine-changing job ran on this node, or on a container site
	 * this node hosts, in the window ending now. A host runs this check from a
	 * site tree on it, so a site's upgrade changes what the host reports.
	 */
	public static function explained_by_jobs(int $node_id, int $now): bool {
		$db = DbConnector::get_instance()->get_db_link();
		$types = implode(',', array_fill(0, count(self::EXPLAINING_TYPES), '?'));
		$q = $db->prepare(
			"SELECT 1 FROM mjb_management_jobs
			  WHERE (mjb_mgn_managed_node_id = ?
			         OR mjb_mgn_managed_node_id IN (
			             SELECT mgn_managed_node_id FROM mgn_managed_nodes
			              WHERE mgn_delete_time IS NULL
			                AND mgn_mgh_managed_host_id IN (
			                    SELECT mgh_managed_host_id FROM mgh_managed_hosts
			                     WHERE mgh_mgn_managed_node_id = ? AND mgh_delete_time IS NULL)))
			    AND mjb_job_type IN ($types)
			    AND mjb_delete_time IS NULL
			    AND (mjb_status = 'running'
			         OR (mjb_status IN ('completed', 'failed')
			             AND COALESCE(mjb_completed_time, mjb_update_time) >= ?))
			  LIMIT 1");
		$q->execute(array_merge(array($node_id, $node_id), self::EXPLAINING_TYPES,
			array(gmdate('Y-m-d H:i:s', $now - self::EXPLAIN_WINDOW_SECONDS))));
		return (bool)$q->fetchColumn();
	}

	/** The node's stored state in this version's shape, or null. */
	public static function state(ManagedNode $node): ?array {
		$s = $node->get('mgn_never_do');
		if (is_string($s)) { $s = json_decode($s, true); }
		return is_array($s) ? self::upgrade_state($s, time()) : null;
	}

	/** A never_do_check job's result folded into its node. */
	public static function record(ManagedNode $node, array $report, int $now): array {
		$state = self::fold(self::state($node), $report, self::explained_by_jobs((int)$node->key, $now), $now);
		$node->set('mgn_never_do', json_encode($state));
		$node->set('mgn_never_do_time', gmdate('Y-m-d H:i:s', $now));
		$node->save();
		return $state;
	}

	/** The words for one held item. A content hash reads as such. */
	public static function describe(array $u): string {
		$item = (string)$u['item'];
		if (in_array($u['kind'], self::HASHED_KINDS, true) || in_array($u['kind'], array('setid_other', 'setuid_copied_root'), true)) {
			if (preg_match('#^(\S+) ([0-9a-f]{16})$#', $item, $m)) { $item = $m[1] . ' (content ' . $m[2] . ')'; }
		}
		return (self::KIND_WORDS[$u['kind']] ?? $u['kind']) . ': ' . $item;
	}
}
?>
