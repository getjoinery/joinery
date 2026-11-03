<?php
/**
 * NeverDoCheck - the management node's half of the never-do list
 * (specs/node_unexplained_root.md § 5.1, the first slice; the never_do_check
 * observe word, agent 1.106.0, runs maintenance_scripts/sysadmin_tools/
 * never_do_check.sh on the machine).
 *
 * The machine reports two things. FINDINGS are wrong on sight: a setuid
 * program no package installed, an extra uid-0 account, a tainted kernel, a
 * program running from a scratch directory or from memory, a root program
 * whose file is gone, an ld.so.preload. The INVENTORY is only wrong when it
 * changes: SSH keys, login accounts, sudoers, the files that start things,
 * listening ports, and the login logs' sizes.
 *
 * The machine keeps no memory of its lists. This side does, on the node row
 * (mgn_never_do), so an attacker who owns the machine cannot rewrite what it
 * used to be. Each report is folded into that state:
 *
 *   - the first report is the baseline;
 *   - an item that is new since the baseline is taken into it when this
 *     plane's own job history explains it (a job that changes the machine ran
 *     in the window), and is otherwise UNEXPLAINED;
 *   - a log that kept its inode and got smaller was cut: unexplained;
 *   - a hard finding is always unexplained; a program in a scratch directory
 *     or a root program whose file is gone is excused by the same job window
 *     (an installer unpacks into /tmp; an update replaces a running program).
 *
 * Unexplained items stay until a person presses Accept on the node page, even
 * when a later report no longer shows them: an attacker who removes what they
 * added does not remove the record of it. IncidentSourceNeverDo raises while
 * any are held.
 *
 * @version 1.0
 */
class NeverDoCheck {

	/** The inventory lists the plane compares. */
	const INVENTORY = array('ssh_keys', 'accounts', 'sudoers', 'startup', 'ports');

	/** Findings that no job explains. */
	const HARD_FINDINGS = array('setuid_unpackaged', 'setuid_modified', 'uid0_accounts');

	/** Findings an explaining job's window excuses. */
	const SOFT_FINDINGS = array('scratch_programs', 'deleted_root_programs');

	/** What each kind of item is called on the page and in the incident. */
	const KIND_WORDS = array(
		'setuid_unpackaged'     => 'setuid program no package installed',
		'setuid_modified'       => 'setuid program changed since its package installed it',
		'uid0_accounts'         => 'account other than root with uid 0',
		'kernel_taint'          => 'kernel tainted by a module (F forced, O out-of-tree, E unsigned)',
		'ld_preload'            => '/etc/ld.so.preload loads a library into every program',
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
		'install_security_updates', 'refresh_site_containers', 'recreate_site', 'site_install', 'install_node',
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

	/** Unexplained items kept at most; the incident says when there are more. */
	const MAX_UNEXPLAINED = 200;

	/** Items kept per inventory list and per findings list (the script's caps). */
	const MAX_ITEMS = 400;
	const MAX_FINDINGS = 20;

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
		foreach (array_merge(self::HARD_FINDINGS, self::SOFT_FINDINGS) as $k) {
			$findings[$k] = self::items($f[$k] ?? array(), self::MAX_FINDINGS);
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
	 * Fold one sanitised report into the node's state. Pure: $explained says
	 * whether a machine-changing job covers this report's window.
	 *
	 * @param array|null $state  the node's stored state, null before the first report
	 * @param array      $report sanitise()'s answer
	 * @return array the new state
	 */
	public static function fold(?array $state, array $report, bool $explained, int $now): array {
		$state = is_array($state) ? $state : array();
		$unexplained = is_array($state['unexplained'] ?? null) ? $state['unexplained'] : array();
		$baseline = is_array($state['baseline'] ?? null) ? $state['baseline'] : null;
		$logs_before = is_array($state['logs'] ?? null) ? $state['logs'] : array();
		$inv = $report['inventory'];

		$add = function ($kind, $item) use (&$unexplained, $now) {
			foreach ($unexplained as $u) {
				if ($u['kind'] === $kind && $u['item'] === $item) { return; }
			}
			if (count($unexplained) < self::MAX_UNEXPLAINED) {
				$unexplained[] = array('kind' => $kind, 'item' => $item, 'seen' => $now);
			}
		};

		// Findings.
		foreach (self::HARD_FINDINGS as $k) {
			foreach ($report['findings'][$k] as $item) { $add($k, $item); }
		}
		if ($report['findings']['kernel_taint'] !== '') { $add('kernel_taint', $report['findings']['kernel_taint']); }
		if ($report['findings']['ld_preload'] !== '') { $add('ld_preload', $report['findings']['ld_preload']); }
		if (!$explained) {
			foreach (self::SOFT_FINDINGS as $k) {
				foreach ($report['findings'][$k] as $item) { $add($k, $item); }
			}
		}

		// The inventory, only from a complete run as root: a partial one
		// cannot see every home or process, so it neither alarms nor teaches.
		$complete = empty($report['partial']);
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

		return array(
			'baseline'    => $baseline,
			'current'     => $complete ? array_intersect_key($inv, array_flip(self::INVENTORY)) : ($state['current'] ?? null),
			'logs'        => $logs_before,
			'unexplained' => $unexplained,
			'partial'     => !$complete,
			'truncated'   => $report['truncated'],
			'checked_at'  => $now,
			'accepted_at' => $state['accepted_at'] ?? null,
			'accepted_by' => $state['accepted_by'] ?? null,
		);
	}

	/**
	 * A person says what is held is theirs: the baseline becomes what the
	 * machine last showed and nothing is held. Pure.
	 */
	public static function accept(array $state, int $user_id, int $now): array {
		if (is_array($state['current'] ?? null)) {
			$state['baseline'] = $state['current'];
		}
		$state['unexplained'] = array();
		$state['accepted_at'] = $now;
		$state['accepted_by'] = $user_id;
		return $state;
	}

	/** Whether a machine-changing job ran on this node in the window ending now. */
	public static function explained_by_jobs(int $node_id, int $now): bool {
		$db = DbConnector::get_instance()->get_db_link();
		$types = implode(',', array_fill(0, count(self::EXPLAINING_TYPES), '?'));
		$q = $db->prepare(
			"SELECT 1 FROM mjb_management_jobs
			  WHERE mjb_mgn_managed_node_id = ?
			    AND mjb_job_type IN ($types)
			    AND mjb_delete_time IS NULL
			    AND (mjb_status = 'running'
			         OR (mjb_status IN ('completed', 'failed')
			             AND COALESCE(mjb_completed_time, mjb_update_time) >= ?))
			  LIMIT 1");
		$q->execute(array_merge(array($node_id), self::EXPLAINING_TYPES,
			array(gmdate('Y-m-d H:i:s', $now - self::EXPLAIN_WINDOW_SECONDS))));
		return (bool)$q->fetchColumn();
	}

	/** The node's stored state, or null. */
	public static function state(ManagedNode $node): ?array {
		$s = $node->get('mgn_never_do');
		if (is_string($s)) { $s = json_decode($s, true); }
		return is_array($s) ? $s : null;
	}

	/** A never_do_check job's result folded into its node. */
	public static function record(ManagedNode $node, array $report, int $now): array {
		$state = self::fold(self::state($node), $report, self::explained_by_jobs((int)$node->key, $now), $now);
		$node->set('mgn_never_do', json_encode($state));
		$node->set('mgn_never_do_time', gmdate('Y-m-d H:i:s', $now));
		$node->save();
		return $state;
	}

	/** The words for one held item. */
	public static function describe(array $u): string {
		return (self::KIND_WORDS[$u['kind']] ?? $u['kind']) . ': ' . $u['item'];
	}
}
?>
