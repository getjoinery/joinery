<?php
/**
 * FleetBackupPolicy — when this management node backs up each node, and how.
 *
 * A node's policy is its own settings layered over the fleet defaults, and the
 * fleet default is ENABLED. That default is doing real work: it is what stops a
 * newly managed node falling through unnoticed, without anyone having to build a
 * detector for "nobody has decided about this node yet". A node nobody decided
 * about gets backed up; a node somebody switched off was switched off on
 * purpose, and is not reported as a problem.
 *
 * Nothing here says anything about the backups a SITE takes of itself. Those run
 * on that site's schedule, under its own key, and are not this management node's
 * to schedule, count or alarm about.
 *
 * @version 1.2 - is_verify_due() keys the last verify on the ATTEMPT — the later of the node's stamp
 *                and its newest verify_backup job's creation, whatever that job's status — so a
 *                verify that failed on the node is not re-dispatched every tick; and no settling
 *                wait after the first backup (last_verify_attempt is the shared reader)
 * @version 1.1 - verify_every_days: how often a node's newest backup is verified restorable by
 *                opening and reading it (30 by default, 0 never); is_verify_due() is the rule
 * @version 1.0
 */

class FleetBackupPolicy {

	/** Every field of a policy, with the shipped default for each. */
	const DEFAULTS = array(
		'enabled'            => true,
		'frequency'          => 'daily',       // daily | weekly
		'day_of_week'        => 0,             // weekly only, 0 = Sunday
		'window_start'       => '03:00',       // UTC
		'window_minutes'     => 120,
		'mode'               => 'chain',
		'type'               => 'project',
		'keep'               => 4,
		'full_interval_days' => 7,
		// Days between verifications of the newest backup, by opening and
		// reading it on the node. 0 means never — stored as a decision, like
		// backups-off. The scheduled verify is always level 2; a rehearsal
		// (level 3) is only ever asked for by a person and no schedule can
		// select it.
		'verify_every_days'  => 30,
	);

	/** A node never verified reads as a problem this long after its first backup. */
	const NEVER_VERIFIED_GRACE_DAYS = 45;

	/** A verify older than this is stale, whatever the policy interval says. */
	const VERIFY_STALE_DAYS = 60;

	/**
	 * The effective policy for one node: fleet defaults, then the site's own
	 * overrides, then only the keys that are actually recognised.
	 */
	public static function for_node($node): array {
		$policy = self::fleet_defaults();

		$stored = $node->get('mgn_backup_policy');
		if (is_string($stored)) { $stored = json_decode($stored, true); }
		if (is_array($stored)) {
			foreach ($stored as $k => $v) {
				if (array_key_exists($k, self::DEFAULTS)) {
					$policy[$k] = $v;
				}
			}
		}

		return self::normalize($policy);
	}

	/**
	 * Nodes this management node could back up: live, enabled, hosting a Joinery
	 * site, and past install. Bare infrastructure nodes (a DNS box, a mail
	 * relay) have no site to archive and are not a gap.
	 *
	 * One list, shared by the scheduler and the health monitor on purpose: if
	 * the two kept their own filters and they drifted, a node the monitor
	 * watches but the scheduler skips would alarm as never-backed-up forever,
	 * with nothing anyone could fix from the dashboard.
	 */
	public static function eligible_nodes(): array {
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_nodes_class.php'));
		$nodes = new MultiManagedNode(array('deleted' => false, 'enabled' => true),
			array('mgn_name' => 'ASC'), 1000, 0);
		$nodes->load();

		$out = array();
		foreach ($nodes as $node) {
			if (!$node->get('mgn_web_root')) continue;
			if ($node->get('mgn_skip_joinery_checks')) continue;
			if ($node->get('mgn_install_state') === 'installing') continue;
			$out[] = $node;
		}
		return $out;
	}

	/**
	 * How this node's stored policy relates to the fleet default — which of the
	 * three positions the editor offers it currently holds:
	 *
	 *   default  nothing stored; the node follows the fleet settings, including
	 *            future changes to them
	 *   off      somebody switched this node's fleet backups off, on purpose
	 *   custom   a schedule of this node's own, frozen against the fleet default
	 */
	public static function stored_mode($node): string {
		$stored = $node->get('mgn_backup_policy');
		if (is_string($stored)) { $stored = json_decode($stored, true); }
		if (!is_array($stored) || !$stored) { return 'default'; }
		return empty($stored['enabled']) ? 'off' : 'custom';
	}

	/**
	 * A full custom policy from the Backups tab's posted fields, normalized the
	 * same way a stored one is read back. Full rather than a diff against the
	 * fleet defaults: a value the operator saw and saved is a value they chose,
	 * and it must not drift when the fleet default later moves.
	 *
	 * The schedule arrives as one field — 'daily' or a weekday number — because
	 * that is one decision, not two.
	 */
	public static function from_form(array $input): array {
		$schedule = trim((string)($input['policy_schedule'] ?? 'daily'));
		return self::normalize(array(
			'enabled'            => true,
			'frequency'          => ($schedule === 'daily') ? 'daily' : 'weekly',
			'day_of_week'        => ($schedule === 'daily') ? 0 : (int)$schedule,
			'window_start'       => (string)($input['policy_window_start'] ?? self::DEFAULTS['window_start']),
			'window_minutes'     => (int)($input['policy_window_minutes'] ?? self::DEFAULTS['window_minutes']),
			'mode'               => (string)($input['policy_mode'] ?? self::DEFAULTS['mode']),
			'type'               => self::DEFAULTS['type'],
			'keep'               => (int)($input['policy_keep'] ?? self::DEFAULTS['keep']),
			'full_interval_days' => (int)($input['policy_full_interval_days'] ?? self::DEFAULTS['full_interval_days']),
			'verify_every_days'  => (int)($input['policy_verify_every_days'] ?? self::DEFAULTS['verify_every_days']),
		));
	}

	/** The fleet-wide defaults, from declared plugin settings. */
	public static function fleet_defaults(): array {
		$settings = Globalvars::get_instance();
		$policy = self::DEFAULTS;

		$map = array(
			'enabled'            => 'server_manager_fleet_backup_enabled',
			'window_start'       => 'server_manager_fleet_backup_window_start',
			'window_minutes'     => 'server_manager_fleet_backup_window_minutes',
			'mode'               => 'server_manager_fleet_backup_mode',
			'keep'               => 'server_manager_fleet_backup_keep',
			'full_interval_days' => 'server_manager_fleet_backup_full_interval_days',
			'verify_every_days'  => 'server_manager_fleet_backup_verify_every_days',
		);
		foreach ($map as $field => $setting) {
			$value = $settings->get_setting($setting, true, true);
			if ($value !== null && $value !== '') {
				$policy[$field] = $value;
			}
		}

		return self::normalize($policy);
	}

	public static function max_concurrent(): int {
		$value = (int)Globalvars::get_instance()->get_setting('server_manager_fleet_backup_max_concurrent', true, true);
		return max(1, $value ?: 2);
	}

	private static function normalize(array $p): array {
		$p['enabled']  = !in_array((string)$p['enabled'], array('', '0', 'false', 'off'), true);
		$p['frequency'] = ((string)$p['frequency'] === 'weekly') ? 'weekly' : 'daily';
		$p['mode']      = ((string)$p['mode'] === 'full') ? 'full' : 'chain';
		$p['type']      = ((string)$p['type'] === 'database') ? 'database' : 'project';
		$p['keep']      = max(1, (int)$p['keep']);
		$p['full_interval_days'] = max(0, (int)$p['full_interval_days']);
		$p['verify_every_days']  = max(0, (int)($p['verify_every_days'] ?? self::DEFAULTS['verify_every_days']));
		$p['day_of_week'] = max(0, min(6, (int)$p['day_of_week']));
		$p['window_minutes'] = max(1, (int)$p['window_minutes']);
		if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', (string)$p['window_start'])) {
			$p['window_start'] = self::DEFAULTS['window_start'];
		}
		return $p;
	}

	/**
	 * The minute of the day this node starts, derived from its slug.
	 *
	 * Stable per node and spread across the window, so a fleet does not converge
	 * on one minute and hand the bucket forty simultaneous multi-hundred-megabyte
	 * uploads. Derived rather than random because it has to be the same answer on
	 * every tick — a value that moved would make "is it due yet" meaningless.
	 *
	 * A window crossing midnight wraps: a slot past 24:00 lands in the early
	 * hours of the SAME calendar day, so a weekly policy fires in the small hours
	 * of its configured weekday rather than the day after. The shipped window
	 * (03:00 + 120) never wraps.
	 */
	public static function slot_minute(array $policy, string $slug): int {
		list($h, $m) = explode(':', $policy['window_start']);
		$start = ((int)$h * 60) + (int)$m;
		$offset = crc32($slug) % $policy['window_minutes'];
		return ($start + $offset) % 1440;
	}

	/** The slot as HH:MM UTC, for showing a person. */
	public static function slot_time(array $policy, string $slug): string {
		$minute = self::slot_minute($policy, $slug);
		return sprintf('%02d:%02d UTC', intdiv($minute, 60), $minute % 60);
	}

	/**
	 * Whether this node is due, given its last run.
	 *
	 * "Due" means: the slot has passed today (or on the scheduled weekday), and
	 * no run has been started since that slot. Keyed on the last job's creation
	 * rather than on its outcome — a run that failed has still been attempted,
	 * and retrying it every fifteen minutes until the next slot would hammer a
	 * node that is already unwell.
	 *
	 * @param string $now UTC 'Y-m-d H:i:s'
	 */
	public static function is_due(array $policy, string $slug, $last_job, string $now): bool {
		$now_ts = strtotime($now . ' UTC');
		if ($now_ts === false) { return false; }

		if ($policy['frequency'] === 'weekly' && (int)gmdate('w', $now_ts) !== $policy['day_of_week']) {
			return false;
		}

		$slot_ts = strtotime(gmdate('Y-m-d', $now_ts) . ' UTC') + (self::slot_minute($policy, $slug) * 60);
		if ($now_ts < $slot_ts) {
			return false;
		}

		if ($last_job) {
			$started = strtotime(((string)$last_job->get('mjb_create_time')) . ' UTC');
			if ($started !== false && $started >= $slot_ts) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether this node's newest backup is due to be verified restorable.
	 *
	 * Due when the policy asks for verification at all (verify_every_days > 0),
	 * the node has a successful backup from here, and either
	 *
	 *   - no verify has ever been attempted, or
	 *   - the last attempt is older than verify_every_days and a successful
	 *     backup has been taken since it.
	 *
	 * "Attempted" is the word: the last verify is the LATER of the node's
	 * verify stamp and the creation of its newest verify_backup job, whatever
	 * became of that job. A verify that failed on the node comes back as a
	 * failed job, and a failed job is never folded into the node's columns, so
	 * the stamp alone would say "never verified" and re-dispatch the whole
	 * download every tick until one passed. Keyed on the attempt, as is_due()
	 * is for backups, a failing verify runs once per interval and is surfaced
	 * as a problem by the health check in between. A pending or running job
	 * counts the same way, so the rule never asks for a second verify beside
	 * the first.
	 *
	 * No settling wait after the first backup: the stamp a backup leaves is
	 * written from the node's own completed result, so the upload it names has
	 * landed. The busy check in the pass keeps a verify off a chain a backup is
	 * still extending.
	 *
	 * Reads the node's plane-side stamps and the one job row the pass already
	 * holds, so it costs nothing per tick.
	 *
	 * @param string $now UTC 'Y-m-d H:i:s'
	 * @param object|null $last_verify_job the node's newest verify_backup job, any status
	 */
	public static function is_verify_due(array $policy, $node, string $now, $last_verify_job = null): bool {
		$every = (int)($policy['verify_every_days'] ?? 0);
		if ($every <= 0) {
			return false;
		}
		$now_ts = strtotime($now . ' UTC');
		if ($now_ts === false) { return false; }

		if ((string)$node->get('mgn_last_backup_outcome') !== 'success') {
			return false;
		}
		$last_backup = trim((string)$node->get('mgn_last_backup_time'));
		$backup_ts = ($last_backup !== '') ? strtotime($last_backup . ' UTC') : false;
		if ($backup_ts === false) {
			return false;
		}

		$verify_ts = self::last_verify_attempt($node, $last_verify_job);
		if ($verify_ts === false) {
			return true;    // never attempted, and there is a backup to prove
		}
		if ($backup_ts <= $verify_ts) {
			return false;   // nothing newer than what was last verified
		}
		return ($now_ts - $verify_ts) >= ($every * 86400);
	}

	/**
	 * When a verify of this node was last attempted: the later of the node's
	 * verify stamp and the newest verify_backup job's creation. False when
	 * neither exists.
	 *
	 * @return int|false
	 */
	public static function last_verify_attempt($node, $last_verify_job = null) {
		$last_verify = trim((string)$node->get('mgn_backup_verify_time'));
		$stamp_ts = ($last_verify !== '') ? strtotime($last_verify . ' UTC') : false;
		$job_ts = false;
		if ($last_verify_job) {
			$created = trim((string)$last_verify_job->get('mjb_create_time'));
			$job_ts = ($created !== '') ? strtotime($created . ' UTC') : false;
		}
		if ($stamp_ts === false) { return $job_ts; }
		if ($job_ts === false) { return $stamp_ts; }
		return max($stamp_ts, $job_ts);
	}
}
