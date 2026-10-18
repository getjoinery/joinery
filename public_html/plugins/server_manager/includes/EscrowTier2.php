<?php
/**
 * EscrowTier2 — before an escrow holder answers a boot, it reads the
 * provider's activity log for the machine's instance, and refuses after what
 * a stolen hosting login does to read a disk or plant code on it
 * (specs/hosted_data_promise_disk_encryption.md D3, Tier 2, WP5).
 *
 * WHAT REFUSES. Since the machine last reported itself unlocked (or was
 * enrolled): a rescue boot, a root-password reset, a rebuild, a clone or an
 * image of its disks, a backup restored onto it, or one of its addresses
 * taken away; or the request coming from an address that is not this
 * instance's. A plain reboot, a provider's maintenance reboot or migration,
 * and a resize are answered. After a rescue boot or a root-password reset
 * nothing on the machine's plain root disk can be trusted, and nothing on it
 * can vouch for it, so there is no plain override: the owner states that they
 * did it themselves (recorded with their name, excusing every event up to
 * then), or the site is recovered by a copy from its backups.
 *
 * WHERE IT APPLIES. A machine whose provision is in one of our accounts
 * holding the events scope: a lasting read of the activity log. A machine in
 * a customer's own account is reached through a Linode Connect sign-in that
 * lasts two hours, so it stays at Tier 1, and its pages say so.
 *
 * READ TWICE. A log can record an event a moment late, so the log is read,
 * a few seconds pass, and it is read again. Every boot is that much slower.
 * A log that cannot be read refuses: the machine asks again every ten
 * seconds, so a provider's outage delays an unlock, never skips the check.
 *
 * A KEY THAT STOPS WORKING. A machine whose account is held by a lasting key
 * with the events scope stays Tier 2 when that key stops working (refused by
 * the provider, past its expiry, or unreadable here): its boot is refused
 * until the key is replaced, and opens meanwhile by its recovery key. It is
 * never answered unchecked, because revoking this plane's key is the first
 * thing a stolen hosting login can do. A 401 from the provider marks the
 * account refused, so the Hosting tab of Provisioning Setup says so (B77).
 *
 * @version 1.3 - Hetzner's own command names for a password reset, a rescue, a rebuild, an image, an ISO and an
 *                address taken away refuse as Linode's do (specs/site_copy_anything_to_anything.md D3)
 * @version 1.2 - A key whose scopes were never read is not Tier 1 either: unread is not "chosen without the
 *               events scope", it is the dead-key shape, so the boot is refused until a check reads them
 *               (reviewer1 F1); machines_in_account() names what removing an account drops to Tier 1 (N1).
 * @version 1.1 - A Tier 2 machine whose account's key stops working is refused, with what to do, instead of being
 *               answered at Tier 1; a 401 marks the account refused (B77). Connect accounts are Tier 1 throughout.
 * @version 1.0
 */

class EscrowTier2 {

	const REREAD_SECONDS = 5;

	/** The tests point this at a fake provider: callable(DataRootEscrow, &$why, &$blocked): ?array. */
	public static $context_override = null;

	/** The scope a credential needs for it. */
	const EVENTS_SCOPE = 'events:read_only';

	/**
	 * Each event that refuses, by the provider's name for it, with what it
	 * is in words. A rescue boot is recognised by rescue_boot().
	 */
	const REFUSING = array(
		'password_reset'  => 'its root password was reset',
		'linode_rebuild'  => 'it was rebuilt',
		'linode_clone'    => 'its disks were cloned',
		'disk_imagize'    => 'an image was made of its disk',
		'disk_duplicate'  => 'its disk was duplicated',
		'backups_restore' => 'a backup was restored onto it',
		'linode_deleteip' => 'one of its addresses was taken away',
		// Hetzner's own command names for the same things.
		'reset_password'  => 'its root password was reset',
		'enable_rescue'   => 'it was set to boot into rescue mode',
		'rebuild_server'  => 'it was rebuilt',
		'create_image'    => 'an image was made of its disk',
		'attach_iso'      => 'an ISO was attached to boot it from',
		'unassign_primary_ip' => 'one of its addresses was taken away',
	);

	/**
	 * [driver, instance id] where Tier 2 applies to the row's machine and can
	 * be checked now, or null. Null with $why: Tier 1. Null with $blocked set
	 * as well: Tier 2 applies and its account's key does not work now, so the
	 * boot is refused with $blocked (B77).
	 */
	public static function context(DataRootEscrow $row, ?string &$why = null, ?string &$blocked = null) {
		$why = '';
		$blocked = null;
		if (self::$context_override !== null) {
			return (self::$context_override)($row, $why, $blocked);
		}
		$node_id = (int)$row->get('dre_mgn_managed_node_id');
		if (!$node_id) {
			$why = 'it is not a node of this management node';
			return null;
		}
		$node = new ManagedNode($node_id, TRUE);
		$provision = $node->key ? IpSwapMove::provision_of($node) : null;
		if (!$provision) {
			$why = 'no server of ours is recorded for it (adopt it on its node page)';
			return null;
		}
		$oca_id = (int)$provision->get('cvp_oca_operator_cloud_account_id');
		if (!$oca_id) {
			$why = 'it is in its owner\'s own cloud account, which this management node holds no lasting key for';
			return null;
		}
		$account = new OperatorCloudAccount($oca_id, TRUE);
		if (!$account->key || $account->removed()) {
			$why = 'the cloud account it was in is no longer held here';
			return null;
		}
		if ((string)$account->get('oca_method') === OperatorCloudAccount::METHOD_CONNECT) {
			$why = 'its cloud account is held by a Linode sign-in, which lasts two hours, not by a lasting key';
			return null;
		}
		$missing = $account->missing_scopes(array(self::EVENTS_SCOPE => ''));
		if ($missing === null) {
			// Never read: not the owner's choice of a key without the scope, but
			// a key this node has not managed to ask about.
			$why = 'its cloud account\'s key has not been checked yet';
			$blocked = 'Its cloud account\'s key has never been checked, so whether it can read the activity log is not known: check it on the Hosting tab of Provisioning Setup (the daily check also does). Until then its boot is refused here, never answered unchecked; it opens by its recovery key.';
			return null;
		}
		if ($missing) {
			$why = 'the key for its cloud account cannot read the activity log (events:read_only)';
			return null;
		}
		if (!$account->usable()) {
			$why = 'its cloud account\'s key does not work now';
			$blocked = 'Its cloud account\'s key does not work, so its provider\'s activity log cannot be read: '
				. $account->unusable_reason() . ' Until then its boot is refused here, never answered unchecked; it opens by its recovery key.';
			return null;
		}
		$driver = $account->driver();
		if (!($driver instanceof CloudProviderEvents)) {
			$why = 'its provider has no activity log to read';
			return null;
		}
		return array($driver, (string)$provision->get('cvp_instance_id'));
	}

	/** Tier 2 applies, whether or not it can be checked now. */
	public static function applies(DataRootEscrow $row): bool {
		$why = '';
		$blocked = null;
		return self::context($row, $why, $blocked) !== null || $blocked !== null;
	}

	/**
	 * Why the boot asking from $addr is refused, or null to answer it. Records
	 * the event that refused it on the row (not saved: the caller saves).
	 *
	 * @param callable|null $sleep  injected for tests; sleep() otherwise
	 */
	public static function refusal(DataRootEscrow $row, string $addr, ?callable $sleep = null): ?string {
		$why = '';
		$blocked = null;
		$ctx = self::context($row, $why, $blocked);
		if ($ctx === null) {
			return $blocked;
		}
		list($driver, $instance) = $ctx;
		$row->set('dre_tier2_event', null);
		try {
			$asking = $driver->instanceForAddress($addr);
			if ($asking !== $instance) {
				$row->set('dre_tier2_event', 'asked from ' . $addr . ($asking !== '' ? ', instance ' . $asking : ', no instance of this account'));
				return 'Asked from ' . $addr . ', which ' . ($asking !== '' ? 'belongs to instance ' . $asking : 'belongs to no instance in this account')
					. ', not to this machine\'s instance ' . $instance . ': a copy of it, or its address moved.';
			}
			$since = self::since($row);
			for ($read = 1; $read <= 2; $read++) {
				$event = self::first_refusing($driver->instanceEvents($instance, $since));
				if ($event !== null) {
					$row->set('dre_tier2_event', substr($event['action'] . ' at ' . $event['time'], 0, 255));
					return 'Since it last opened, ' . $event['words'] . ' (' . $event['action'] . ', ' . $event['time']
						. ' UTC): nothing on its root disk can be trusted. On the holder\'s page: say you did it yourself, or recover the site by a copy from its backups.';
				}
				if ($read === 1) {
					($sleep ?? 'sleep')(self::REREAD_SECONDS);
				}
			}
		} catch (Exception $e) {
			// The key itself refused: the account is marked so, for the Hosting
			// tab and every machine in it, and the boot waits for a new key.
			if ((int)$e->getCode() === 401 && self::account_refused($row, $e)) {
				return 'Its cloud account\'s key was refused by the provider (' . substr($e->getMessage(), 0, 120)
					. '): replace it on the Hosting tab of Provisioning Setup. Until then its boot is refused here, never answered unchecked; it opens by its recovery key.';
			}
			return 'Its provider\'s activity log could not be read (' . substr($e->getMessage(), 0, 160)
				. '), and the boot is answered only once it has been; the machine asks again every ten seconds.';
		}
		return null;
	}

	/** The account of the row's machine is marked refused after a 401 (MachineTransferWatch's rule). True when it is refused now. */
	private static function account_refused(DataRootEscrow $row, Exception $e): bool {
		$account = self::account_for($row);
		if ($account === null) {
			return false;
		}
		MachineTransferWatch::revoke_rejected($account->choice_key(), $e);
		$account = new OperatorCloudAccount((int)$account->key, TRUE);
		return (string)$account->get('oca_status') === OperatorCloudAccount::STATUS_REFUSED;
	}

	/**
	 * The account of ours, held by a lasting key with the events scope, whose
	 * log Tier 2 reads for the row's machine, whether or not its key works now;
	 * null where Tier 2 does not apply (IncidentSourceCloudAccountKey).
	 */
	public static function account_for(DataRootEscrow $row): ?OperatorCloudAccount {
		$node = new ManagedNode((int)$row->get('dre_mgn_managed_node_id'), TRUE);
		$provision = $node->key ? IpSwapMove::provision_of($node) : null;
		$oca_id = $provision ? (int)$provision->get('cvp_oca_operator_cloud_account_id') : 0;
		if (!$oca_id) {
			return null;
		}
		$account = new OperatorCloudAccount($oca_id, TRUE);
		if (!$account->key || $account->removed() || (string)$account->get('oca_method') === OperatorCloudAccount::METHOD_CONNECT) {
			return null;
		}
		$missing = $account->missing_scopes(array(self::EVENTS_SCOPE => ''));
		return ($missing === null || !$missing) ? $account : null;
	}

	/**
	 * The machines whose boots this node checks at Tier 2 through account
	 * $oca_id: [node id => node name]. Removing the account drops each to
	 * Tier 1, which the Hosting tab says before it is removed (reviewer1 N1).
	 */
	public static function machines_in_account(int $oca_id): array {
		$out = array();
		foreach (new MultiDataRootEscrow(array('live' => true)) as $row) {
			if ((string)$row->get('dre_status') !== 'enrolled') {
				continue;
			}
			$account = self::account_for($row);
			if ($account !== null && (int)$account->key === $oca_id) {
				$node = new ManagedNode((int)$row->get('dre_mgn_managed_node_id'), TRUE);
				$out[(int)$node->key] = (string)$node->get('mgn_name');
			}
		}
		return $out;
	}

	/** From when events count: the last unlock reported, or the enrolment, or the owner's excuse, whichever is latest. */
	public static function since(DataRootEscrow $row): string {
		$times = array();
		foreach (array('dre_unlocked_time', 'dre_enrolled_time', 'dre_tier2_excused_time') as $f) {
			$t = (string)$row->get($f);
			if ($t !== '') {
				$times[] = substr($t, 0, 19);
			}
		}
		return $times ? max($times) : gmdate('Y-m-d H:i:s', time() - 86400);
	}

	/** The first event that refuses, with 'words', or null. */
	public static function first_refusing(array $events): ?array {
		foreach ($events as $e) {
			if (($e['status'] ?? '') === 'failed') {
				continue;
			}
			$action = (string)($e['action'] ?? '');
			if (isset(self::REFUSING[$action])) {
				return $e + array('words' => self::REFUSING[$action]);
			}
			if (self::rescue_boot($e)) {
				return $e + array('words' => 'it was booted into rescue mode');
			}
		}
		return null;
	}

	/**
	 * A boot into the provider's rescue system. Linode logs it as a boot or a
	 * reboot naming no configuration profile; every other boot or reboot, asked
	 * for with a profile or not, names the one it booted (found in the test
	 * account, 2026-10-11).
	 */
	public static function rescue_boot(array $e): bool {
		$action = (string)($e['action'] ?? '');
		if (!in_array($action, array('linode_boot', 'linode_reboot'), true)) {
			return false;
		}
		return (string)($e['via'] ?? '') === ''
			|| stripos((string)($e['message'] ?? ''), 'rescue') !== false;
	}

	/** The owner's word that they caused what refused it: every event up to now is excused. */
	public static function excuse(DataRootEscrow $row, int $user_id): void {
		$row->set('dre_tier2_excused_time', gmdate('Y-m-d H:i:s'));
		$row->set('dre_tier2_excused_usr_user_id', $user_id);
		$row->set('dre_tier2_event', null);
		$row->save();
	}
}
