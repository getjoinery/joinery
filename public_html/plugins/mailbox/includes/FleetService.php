<?php
/**
 * FleetService - the operator-side brain of the shared relay fleet.
 *
 * (specs/mailbox_relay_shared_fleet.md). Runs ONLY on the platform operator's
 * own deployment. Every fleet decision is made here — this tenant gets a slot
 * on this shard, this domain is verified, this tenant is suspended — and each
 * decision is EFFECTED by dispatching a server_manager job against the shard's
 * managed node. server_manager is the hands: it executes box-level work
 * (provision_relay.sh add-tenant / set-domains / remove-tenant) and never
 * knows what a tenant or a domain claim is.
 *
 * Two execution contexts:
 *   - The fleet_* API actions (called by tenant deployments with their
 *     customer-account API key) run as that customer: they read/write the
 *     customer's OWN slot and claims, and flag work.
 *   - The FleetReconcile scheduled task (cron, operator context) dispatches
 *     the flagged jobs, reconciles finished ones, and re-checks entitlement.
 *
 * @version 1.1
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_fleet_shard_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_fleet_slot_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_fleet_domain_claim_class.php'));

class FleetServiceException extends Exception {}

class FleetService {

	/** The tier feature gating a fleet slot (plugins/mailbox/tier_features.json). */
	const FEATURE_SLOT = 'mailbox_fleet_slot';
	const FEATURE_MAX_DOMAINS = 'mailbox_fleet_max_domains';

	/** Every shard's relay listens at .1 of its tunnel subnet. */
	const RELAY_TUNNEL_IP = '10.99.0.1';

	// ------------------------------------------------------------ service gate

	/** The fleet service accepts enrollments only when configured + enabled. */
	public static function enabled(): bool {
		$settings = Globalvars::get_instance();
		return (string)$settings->get_setting('mailbox_fleet_service_enabled') === '1'
			&& trim((string)$settings->get_setting('mailbox_fleet_mx_zone')) !== '';
	}

	/** Entitlement: the user's tier carries the fleet-slot feature. */
	public static function entitled(int $user_id): bool {
		require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
		return (bool)SubscriptionTier::getUserFeature($user_id, self::FEATURE_SLOT, false);
	}

	public static function maxDomains(int $user_id): int {
		require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
		return max(1, intval(SubscriptionTier::getUserFeature($user_id, self::FEATURE_MAX_DOMAINS, 5)));
	}

	// ------------------------------------------------------------- enrollment

	/**
	 * Enroll (or return) the user's slot. Idempotent: a live slot is returned
	 * as-is so a retried enroll never double-allocates. Throws
	 * FleetServiceException with a user-facing message on any refusal.
	 */
	public static function enroll(int $user_id, string $wg_public_key, string $pull_public_key): MailboxFleetSlot {
		if (!self::enabled()) {
			throw new FleetServiceException('The hosted relay fleet is not accepting enrollments.');
		}
		if (!self::entitled($user_id)) {
			throw new FleetServiceException('Your subscription does not include a hosted relay slot.');
		}
		if (!preg_match('#^[A-Za-z0-9+/]{43}=$#', $wg_public_key)) {
			throw new FleetServiceException('wg_public_key is not a valid WireGuard public key.');
		}
		if (!preg_match('/^(ssh-ed25519|ssh-rsa|ecdsa-[a-z0-9-]+) [A-Za-z0-9+\/=]+( [^\s]+)?$/', trim($pull_public_key))) {
			throw new FleetServiceException('pull_public_key is not a valid SSH public key.');
		}

		$existing = MailboxFleetSlot::activeForUser($user_id);
		if ($existing !== null) {
			// Refresh the keys (a re-enroll after the tenant regenerated either
			// key re-provisions on the next reconcile pass).
			$changed = trim($wg_public_key) !== trim((string)$existing->get('mft_wg_public_key'))
				|| trim($pull_public_key) !== trim((string)$existing->get('mft_pull_public_key'));
			if ($changed) {
				$existing->set('mft_wg_public_key', trim($wg_public_key));
				$existing->set('mft_pull_public_key', trim($pull_public_key));
				$existing->set('mft_status', MailboxFleetSlot::STATUS_PROVISIONING);
				$existing->set('mft_last_job_id', null);
				$existing->save();
			}
			return $existing;
		}

		$shard = self::assignShard();
		if ($shard === null) {
			throw new FleetServiceException('No fleet shard has capacity right now — enrollment is temporarily closed.');
		}

		$slot = new MailboxFleetSlot(NULL);
		$slot->set('mft_mfs_shard_id', intval($shard->key));
		$slot->set('mft_usr_user_id', $user_id);
		$slot->set('mft_status', MailboxFleetSlot::STATUS_PROVISIONING);
		$slot->set('mft_tunnel_ip', self::allocateTunnelIp($shard));
		$slot->set('mft_wg_public_key', trim($wg_public_key));
		$slot->set('mft_pull_public_key', trim($pull_public_key));
		$slot->save();

		// Slug + MX hostname derive from the slot id (stable, unique, short).
		$zone = trim((string)Globalvars::get_instance()->get_setting('mailbox_fleet_mx_zone'));
		$slug = 't' . intval($slot->key);
		$slot->set('mft_slug', $slug);
		$slot->set('mft_mx_hostname', $slug . '.' . $zone);
		$slot->save();

		return $slot;
	}

	/** Least-loaded active shard with free capacity, or null. */
	public static function assignShard(): ?MailboxFleetShard {
		$shards = new MultiMailboxFleetShard(array('active' => true, 'deleted' => false));
		$shards->load();
		$best = null;
		$best_load = PHP_INT_MAX;
		foreach ($shards as $shard) {
			if (intval($shard->get('mfs_mgn_managed_node_id')) <= 0) {
				continue; // no node to run jobs against
			}
			$count = $shard->slotCount();
			if ($count >= intval($shard->get('mfs_capacity'))) {
				continue;
			}
			if ($count < $best_load) {
				$best = $shard;
				$best_load = $count;
			}
		}
		return $best;
	}

	/** Lowest free tunnel address on the shard (.1 is the relay). */
	public static function allocateTunnelIp(MailboxFleetShard $shard): string {
		$slots = new MultiMailboxFleetSlot(array('shard_id' => intval($shard->key), 'live' => true, 'deleted' => false));
		$slots->load();
		$used = array(self::RELAY_TUNNEL_IP => true);
		foreach ($slots as $slot) {
			$ip = trim((string)$slot->get('mft_tunnel_ip'));
			if ($ip !== '') {
				$used[$ip] = true;
			}
		}
		for ($n = 2; $n <= 254; $n++) {
			$candidate = '10.99.0.' . $n;
			if (!isset($used[$candidate])) {
				return $candidate;
			}
		}
		throw new FleetServiceException('Shard tunnel subnet is exhausted.');
	}

	/**
	 * The connection coordinates a tenant deployment stores in its MailboxRelay
	 * row — everything RelaySpoolConsumer/RelayMapSync/the tunnel need.
	 */
	public static function coordinates(MailboxFleetSlot $slot): array {
		$shard = new MailboxFleetShard(intval($slot->get('mft_mfs_shard_id')), TRUE);
		$slug = (string)$slot->get('mft_slug');
		return array(
			'slot_id'         => intval($slot->key),
			'status'          => (string)$slot->get('mft_status'),
			'slug'            => $slug,
			'mx_hostname'     => (string)$slot->get('mft_mx_hostname'),
			'shard_public_ip' => (string)$shard->get('mfs_public_ip'),
			'wg_endpoint'     => (string)$shard->get('mfs_wg_endpoint'),
			'wg_public_key'   => (string)$shard->get('mfs_wg_public_key'),
			'tunnel_ip'       => (string)$slot->get('mft_tunnel_ip'),
			'relay_tunnel_ip' => self::RELAY_TUNNEL_IP,
			'ssh_user'        => 'jt-' . $slug,
			'spool_path'      => '/var/spool/joinery-relay/' . $slug,
		);
	}

	// ----------------------------------------------------------- domain claims

	/**
	 * Create (or return) the slot's claim on a domain, with fleet-wide
	 * uniqueness. Returns the claim carrying the TXT challenge.
	 */
	public static function claimDomain(MailboxFleetSlot $slot, string $domain): MailboxFleetDomainClaim {
		$domain = strtolower(trim($domain));
		if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/', $domain)) {
			throw new FleetServiceException('That is not a valid domain name.');
		}
		if (MailboxFleetDomainClaim::liveClaimByOtherSlot($domain, intval($slot->key)) !== null) {
			throw new FleetServiceException('That domain is already claimed on this fleet.');
		}

		// Idempotent: return the slot's existing live claim.
		$mine = new MultiMailboxFleetDomainClaim(array(
			'slot_id' => intval($slot->key), 'domain' => $domain, 'live' => true, 'deleted' => false,
		));
		$mine->load();
		foreach ($mine as $claim) {
			return $claim;
		}

		$live = new MultiMailboxFleetDomainClaim(array(
			'slot_id' => intval($slot->key), 'live' => true, 'deleted' => false,
		));
		$live->load();
		if ($live->count() >= self::maxDomains(intval($slot->get('mft_usr_user_id')))) {
			throw new FleetServiceException('Your subscription\'s hosted-relay domain limit is reached.');
		}

		$claim = new MailboxFleetDomainClaim(NULL);
		$claim->set('mfd_mft_slot_id', intval($slot->key));
		$claim->set('mfd_domain', $domain);
		$claim->set('mfd_txt_token', 'joinery-fleet-verify-' . bin2hex(random_bytes(16)));
		$claim->set('mfd_status', MailboxFleetDomainClaim::STATUS_PENDING);
		$claim->save();
		return $claim;
	}

	/**
	 * Run the DNS TXT challenge for a pending claim. On success the claim is
	 * verified and the slot is flagged for a shard allowlist sync (the
	 * FleetReconcile task dispatches the set-domains job — the merge then
	 * enforces the claim on every subsequent map sync).
	 */
	public static function verifyClaim(MailboxFleetSlot $slot, MailboxFleetDomainClaim $claim): array {
		if (intval($claim->get('mfd_mft_slot_id')) !== intval($slot->key)) {
			throw new FleetServiceException('That claim does not belong to your slot.');
		}
		if ((string)$claim->get('mfd_status') === MailboxFleetDomainClaim::STATUS_VERIFIED) {
			return array('verified' => true, 'message' => 'Domain already verified.');
		}
		// Uniqueness is re-checked at verification, not only at claim creation.
		$domain = (string)$claim->get('mfd_domain');
		if (MailboxFleetDomainClaim::liveClaimByOtherSlot($domain, intval($slot->key)) !== null) {
			throw new FleetServiceException('That domain is already claimed on this fleet.');
		}

		$expected = (string)$claim->get('mfd_txt_token');
		$records = @dns_get_record($claim->challengeHost(), DNS_TXT) ?: array();
		$found = false;
		foreach ($records as $record) {
			$value = trim((string)($record['txt'] ?? ''));
			if ($value !== '' && hash_equals($expected, $value)) {
				$found = true;
				break;
			}
		}
		if (!$found) {
			return array('verified' => false, 'message' =>
				'TXT record not found. Create a TXT record at ' . $claim->challengeHost()
				. ' with the value ' . $expected . ' and try again (DNS changes can take a few minutes).');
		}

		$claim->set('mfd_status', MailboxFleetDomainClaim::STATUS_VERIFIED);
		$claim->set('mfd_verify_time', gmdate('Y-m-d H:i:s'));
		$claim->save();

		$slot->set('mft_needs_domain_sync', true);
		$slot->save();

		return array('verified' => true, 'message' => 'Domain verified. The fleet will accept mail for it within a few minutes.');
	}

	// ------------------------------------------------------------- job reading

	/**
	 * Raw status/output of a slot's last lifecycle job. Direct SQL — the fleet
	 * actions run as the customer, who has no read grant on server_manager's
	 * job rows; this is the mailbox plugin's own bookkeeping read.
	 */
	public static function lastJobState(MailboxFleetSlot $slot): ?array {
		$job_id = intval($slot->get('mft_last_job_id'));
		if ($job_id <= 0) {
			return null;
		}
		try {
			$db = DbConnector::get_instance()->get_db_link();
			$stmt = $db->prepare(
				"SELECT mjb_job_type, mjb_status, mjb_output FROM mjb_management_jobs
				  WHERE mjb_id = ? LIMIT 1");
			$stmt->execute(array($job_id));
			$row = $stmt->fetch(PDO::FETCH_ASSOC);
			return $row ?: null;
		} catch (\Throwable $e) {
			// A broken read here makes reconcile re-dispatch forever — never fail silently.
			error_log('FleetService::lastJobState failed for job ' . $job_id . ': ' . $e->getMessage());
			return null;
		}
	}

	/**
	 * Fold a finished lifecycle job back into the slot's status. Called from
	 * fleet_status (lazy) and from the FleetReconcile task. Only touches the
	 * slot row (the customer's own).
	 */
	public static function reconcile(MailboxFleetSlot $slot): void {
		$job = self::lastJobState($slot);
		if ($job === null) {
			return;
		}
		$status = (string)$job['mjb_status'];
		$output = (string)($job['mjb_output'] ?? '');
		$type = (string)$job['mjb_job_type'];

		if ($status !== 'completed' && $status !== 'failed') {
			return; // still running
		}

		if ($type === 'relay_add_tenant'
			&& (string)$slot->get('mft_status') === MailboxFleetSlot::STATUS_PROVISIONING) {
			if ($status === 'completed' && strpos($output, 'TENANT_ADDED') !== false) {
				$slot->set('mft_status', MailboxFleetSlot::STATUS_ACTIVE);
				$slot->save();
			}
			// A failed provision leaves the slot in provisioning; the task retries.
		}

		if ($type === 'relay_remove_tenant'
			&& (string)$slot->get('mft_status') === MailboxFleetSlot::STATUS_RELEASED
			&& $status === 'completed' && strpos($output, 'TENANT_REMOVED') !== false) {
			$slot->set('mft_status', MailboxFleetSlot::STATUS_EVICTED);
			$slot->save();
		}

		if ($type === 'relay_set_domains' && $status === 'completed'
			&& strpos($output, 'DOMAINS_SET') !== false && (bool)$slot->get('mft_needs_domain_sync')) {
			$slot->set('mft_needs_domain_sync', false);
			$slot->save();
		}
	}

	// ------------------------------------------------- job dispatch (cron only)

	/**
	 * Dispatch a lifecycle job for a slot. Runs from the FleetReconcile task
	 * (operator/cron context — job rows are server_manager's, and only an
	 * elevated context may write them). $kind: add_tenant | set_domains |
	 * remove_tenant.
	 */
	public static function dispatchJob(MailboxFleetSlot $slot, string $kind, array $extra = array()): ?int {
		if (!PluginHelper::isPluginActive('server_manager')) {
			error_log('FleetService: server_manager inactive; cannot dispatch ' . $kind);
			return null;
		}
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));

		$shard = new MailboxFleetShard(intval($slot->get('mft_mfs_shard_id')), TRUE);
		$node_id = intval($shard->get('mfs_mgn_managed_node_id'));
		if ($node_id <= 0) {
			error_log('FleetService: shard ' . $shard->key . ' has no managed node');
			return null;
		}
		$node = new ManagedNode($node_id, TRUE);

		$slug = (string)$slot->get('mft_slug');
		$params = array('slug' => $slug);
		$job_type = null;

		switch ($kind) {
			case 'add_tenant':
				$job_type = 'relay_add_tenant';
				$params += array(
					'pull_pubkey'       => (string)$slot->get('mft_pull_public_key'),
					'wg_pubkey'         => (string)$slot->get('mft_wg_public_key'),
					'tunnel_ip'         => (string)$slot->get('mft_tunnel_ip'),
					// A fresh fleet tenant has NO allowed domains until its first
					// TXT verification — the shard accepts nothing for it yet.
					'domains'           => implode(',', $slot->verifiedDomains()) ?: '-',
					'forward_limit'     => intval($slot->get('mft_forward_hourly_limit')),
					'spool_max_mib'     => intval($slot->get('mft_spool_max_mib')),
					'spool_max_entries' => intval($slot->get('mft_spool_max_entries')),
				);
				break;
			case 'set_domains':
				$job_type = 'relay_set_domains';
				$params['domains'] = implode(',', $slot->verifiedDomains()) ?: '-';
				break;
			case 'remove_tenant':
				$job_type = 'relay_remove_tenant';
				$params['force'] = !empty($extra['force']);
				break;
			default:
				return null;
		}

		$builder = 'build_' . $job_type;
		$steps = JobCommandBuilder::$builder($node, $params);
		$job = ManagementJob::createJob($node->key, $job_type, $steps, $params, null);

		$slot->set('mft_last_job_id', intval($job->key));
		$slot->save();
		return intval($job->key);
	}
}
