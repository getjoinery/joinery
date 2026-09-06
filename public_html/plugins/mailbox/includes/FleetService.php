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
 *   - The relay reconcile scheduled task (cron, operator context) dispatches
 *     the flagged jobs, reconciles finished ones, and re-checks entitlement.
 *
 * @version 1.5 - the ssh era is over: no tunnel allocation, no lifecycle jobs; applyTenant()
 *                drives every shard through its operator-signed tenant routes
 *                (specs/relay_without_a_shell.md)
 * @version 1.3
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
	/**
	 * Enroll a tenant. $keys carries the tenant's relay client public key
	 * (`public_key`, Ed25519, base64) - what the shard holds in its registry and
	 * verifies every request against. Idempotent: an existing live slot is
	 * returned, re-registered on the next reconcile pass if the key changed.
	 */
	public static function enroll(int $user_id, array $keys): MailboxFleetSlot {
		if (!self::enabled()) {
			throw new FleetServiceException('The hosted relay fleet is not accepting enrollments.');
		}
		if (!self::entitled($user_id)) {
			throw new FleetServiceException('Your subscription does not include a hosted relay slot.');
		}
		$public_key = trim((string)($keys['public_key'] ?? ''));
		$decoded = base64_decode($public_key, true);
		if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
			throw new FleetServiceException('public_key is not a valid Ed25519 public key.');
		}

		$existing = MailboxFleetSlot::activeForUser($user_id);
		if ($existing !== null) {
			if ($public_key !== trim((string)$existing->get('mft_public_key'))) {
				$existing->set('mft_public_key', $public_key);
				$existing->set('mft_status', MailboxFleetSlot::STATUS_PROVISIONING);
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
		$slot->set('mft_public_key', $public_key);
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
			if (trim((string)$shard->get('mfs_identity_fingerprint')) === '') {
				continue; // not born yet: nothing can register a tenant on it
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


	/**
	 * The connection coordinates a tenant deployment stores in its MailboxRelay
	 * row - everything RelayClient needs.
	 */
	public static function coordinates(MailboxFleetSlot $slot): array {
		$shard = new MailboxFleetShard(intval($slot->get('mft_mfs_shard_id')), TRUE);
		$slug = (string)$slot->get('mft_slug');
		return array(
			'slot_id'         => intval($slot->key),
			'status'          => (string)$slot->get('mft_status'),
			'slug'            => $slug,
			'mx_hostname'     => (string)$slot->get('mft_mx_hostname'),
			// The shard's own hostname, which its milters stamp Authentication-Results
			// under. The tenant needs it to trust those stamps: the MX hostname above
			// is a per-tenant name and never appears on a stamp.
			'authserv_id'     => (string)$shard->get('mfs_hostname'),
			'shard_public_ip' => (string)$shard->get('mfs_public_ip'),
			// The identity pin the tenant connects with; with the address, the
			// whole coordinate set.
			'identity_fingerprint' => (string)$shard->get('mfs_identity_fingerprint'),
			'spool_path'      => '/var/spool/joinery-relay/' . $slug,
		);
	}

	/**
	 * Release a slot (the tenant's exit ramp). Marks it released — the
	 * relay reconcile task dispatches the remove-tenant job once the spool
	 * drains — and revokes every live domain claim immediately: release means
	 * the domains are moving, and their next home (a new slot here or another
	 * fleet) must be able to claim them before this slot finishes evicting.
	 */
	public static function releaseSlot(MailboxFleetSlot $slot): void {
		$slot->set('mft_status', MailboxFleetSlot::STATUS_RELEASED);
		$slot->save();

		$claims = new MultiMailboxFleetDomainClaim(array(
			'slot_id' => intval($slot->key), 'live' => true, 'deleted' => false,
		));
		$claims->load();
		foreach ($claims as $claim) {
			$claim->set('mfd_status', MailboxFleetDomainClaim::STATUS_REVOKED);
			$claim->save();
		}
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
	 * relay reconcile task dispatches the set-domains job — the merge then
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

	// ---------------------------------------- tenant lifecycle (cron only)

	/**
	 * Perform one tenant lifecycle act for a slot: add_tenant | set_domains |
	 * remove_tenant - one operator-signed request to the shard's tenant routes,
	 * the slot's status moving on the verdict here and now. Returns true when
	 * the act was performed.
	 */
	public static function applyTenant(MailboxFleetSlot $slot, string $kind, array $extra = array()): bool {
		$shard = new MailboxFleetShard(intval($slot->get('mft_mfs_shard_id')), TRUE);
		if (trim((string)$shard->get('mfs_identity_fingerprint')) === '' || trim((string)$shard->get('mfs_public_ip')) === '') {
			error_log('FleetService: shard ' . $shard->key . ' has not been born yet; cannot ' . $kind . ' for slot ' . $slot->key);
			return false;
		}
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelayClient.php'));
		$slug = (string)$slot->get('mft_slug');
		$client = RelayClient::forOperator((string)$shard->get('mfs_public_ip'), (string)$shard->get('mfs_identity_fingerprint'));
		try {
			switch ($kind) {
				case 'add_tenant':
					$verdict = $client->tenantAdd($slug, (string)$slot->get('mft_public_key'), $slot->verifiedDomains(), array(
						'forward_hourly_limit' => intval($slot->get('mft_forward_hourly_limit')),
						'spool_max_mib'        => intval($slot->get('mft_spool_max_mib')),
						'spool_max_entries'    => intval($slot->get('mft_spool_max_entries')),
					));
					if (($verdict['status'] ?? '') === 'ok'
							&& (string)$slot->get('mft_status') === MailboxFleetSlot::STATUS_PROVISIONING) {
						$slot->set('mft_status', MailboxFleetSlot::STATUS_ACTIVE);
						$slot->save();
					}
					break;
				case 'set_domains':
					$verdict = $client->tenantSetDomains($slug, $slot->verifiedDomains());
					if (($verdict['status'] ?? '') === 'ok' && (bool)$slot->get('mft_needs_domain_sync')) {
						$slot->set('mft_needs_domain_sync', false);
						$slot->save();
					}
					break;
				case 'remove_tenant':
					$verdict = $client->tenantRemove($slug);
					if (($verdict['status'] ?? '') === 'ok'
							&& (string)$slot->get('mft_status') === MailboxFleetSlot::STATUS_RELEASED) {
						$slot->set('mft_status', MailboxFleetSlot::STATUS_EVICTED);
						$slot->save();
					}
					break;
				default:
					return false;
			}
		} catch (RelayClientException $e) {
			error_log('FleetService: ' . $kind . ' for slot ' . $slot->key . ' failed (' . $e->failure_class . '): ' . $e->getMessage());
			return false;
		}
		if (($verdict['status'] ?? '') !== 'ok') {
			// A refusal (an undrained spool on remove, a bad domain) is the
			// shard's answer; the next reconcile pass asks again.
			error_log('FleetService: shard answered ' . $kind . ' for slot ' . $slot->key . ' with '
				. (string)($verdict['status'] ?? '?') . ': ' . (string)($verdict['reason'] ?? ''));
		}
		return true;
	}

}
