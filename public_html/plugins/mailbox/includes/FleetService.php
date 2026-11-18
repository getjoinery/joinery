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
 * @version 1.12 - the shard allowlist is shardDomains(): empty for a suspended or released slot; release
 *                empties it before removing the tenant, and a spool still undrained after
 *                mailbox_fleet_release_drain_days goes with a forced remove; set_domains is applied
 *                once the shard installed its maps, whatever it made of the site's map (hosting_paid_launch B6)
 * @version 1.11 - a Managed site on our account gets its own relay slot too (site_plan_grants_slot, hosting_paid_launch B11);
 *                a site moved to its buyer's own account is gone from its slot (siteGone): released, its key retired
 * @version 1.10 - a site's key answers only for its site's slot, never its buyer's tier slot (isSiteKey); a removed
 *                site's slot is released and its key retired (siteGone, releaseSiteSlot)
 * @version 1.9 - site_inbound_records(): the MX and ownership TXT a hosted site's domain needs for its slot
 * @version 1.8 - a hosted site's plan grants it a slot of its own (hosting_launch S5): the caller's slot is
 *                found from the API key it signs with (callerSlot), a site's slot is entitled by the site's
 *                hosting (slotEntitled), and one buyer's sites never share or take each other's slot
 * @version 1.7 - review fixes: the delivered name is recorded with its shard (mailNameKey), and
 *                settleShardVersions() reads each shard's version from its ping
 * @version 1.6 - add_tenant carries the slot's mail name (mx_hostname) and records it once a shard that keeps it
 *                (relay 3.13) has it; mailNameOwed() says when an active slot's shard still lacks it
 * @version 1.5 - the ssh era is over: no tunnel allocation, no lifecycle jobs; applyTenant()
 *                drives every shard through its operator-signed tenant routes
 *                (specs/relay_without_a_shell.md)
 * @version 1.3
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_fleet_shards_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_fleet_slots_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_fleet_domain_claims_class.php'));

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

	/** Domains one hosted site's slot may claim: its own name and a few more. */
	const SITE_MAX_DOMAINS = 5;

	/** How many domains this slot may claim: a site's fixed allowance, or the buyer's tier's. */
	public static function slotMaxDomains(MailboxFleetSlot $slot): int {
		if (intval($slot->get('mft_cvp_customer_cloud_provision_id')) !== 0) {
			return self::SITE_MAX_DOMAINS;
		}
		return self::maxDomains(intval($slot->get('mft_usr_user_id')));
	}

	// ------------------------------------------------- a hosted site's own slot

	/**
	 * Whether a hosted site's plan grants it a relay slot of its own: a sold
	 * site we host, Starter or Managed. Each is a Docker deployment (Starter in
	 * a container on a shared host, Managed in a container on its own
	 * instance), and a Docker deployment runs no mail server of its own
	 * (mailbox_site_has_mail_server() keys on deployment_environment docker),
	 * so the relay is how it receives. Not a landing test: Managed lands on
	 * an instance. Only on our own account
	 * (hosting mode operator): a buyer's site on their own cloud account, or a
	 * Managed site moved to it, is not ours to host, and its buyer's tier
	 * grants a slot as for anyone.
	 */
	public static function site_plan_grants_slot($provision): bool {
		if ($provision === null || !$provision->key || !class_exists('CustomerCloudProvision')) {
			return false;
		}
		if (!$provision->is_sold() || !$provision->is_operator_hosted()) {
			return false;
		}
		// A site with no mail server of its own: a container, or a bare-metal
		// site built without one (cvp_no_mail_server). A bare-metal site with
		// its own Postfix receives for itself and takes no place.
		return $provision->is_container() || (string)($provision->get('cvp_docker_mode') ?: 'docker') === 'docker'
			|| (bool)$provision->get('cvp_no_mail_server');
	}

	/**
	 * Whether a site's plan still entitles it to its slot: the plan grants
	 * one, the site is built and not removed, and its hosting has not been
	 * shut down (a trial, a paid subscription and a payment's grace all
	 * count). The fleet's own grace then runs on top, as for a tier slot.
	 */
	public static function site_entitled($provision): bool {
		if (!self::site_plan_grants_slot($provision) || $provision->get('cvp_delete_time')) {
			return false;
		}
		if ((string)$provision->get('cvp_status') !== 'done') {
			return false;
		}
		$node_id = intval($provision->get('cvp_mgn_managed_node_id'));
		if ($node_id <= 0) {
			return false;
		}
		$node = new ManagedNode($node_id, TRUE);
		if (!$node->key || $node->get('mgn_delete_time')) {
			return false;
		}
		if (class_exists('HostedTrial')) {
			$trial = HostedTrial::for_provision(intval($provision->key));
			if ($trial && !in_array((string)$trial->get('htr_state'), HostedTrial::LIVE_STATES, true)) {
				return false;
			}
		}
		return true;
	}

	/** A hosted site's own fleet key is named this plus its provision id (apk_name holds 32). */
	const SITE_KEY_NAME_PREFIX = 'Fleet enrollment #';

	/**
	 * Whether an API key was minted for a hosted site: named for one, or
	 * recorded on a provision (a removed one too). Such a key answers only
	 * for its site's slot, for good: when the site no longer counts it is
	 * refused, never let fall through to its buyer's tier slot, which may be
	 * another site's.
	 */
	public static function isSiteKey($api_key_id): bool {
		$api_key_id = intval($api_key_id);
		if ($api_key_id <= 0) {
			return false;
		}
		$key = new ApiKey($api_key_id, TRUE);
		if ($key->key && strpos((string)$key->get('apk_name'), self::SITE_KEY_NAME_PREFIX) === 0) {
			return true;
		}
		return class_exists('CustomerCloudProvision')
			&& count(new MultiCustomerCloudProvision(array('cvp_fleet_apk_api_key_id' => $api_key_id))) > 0;
	}

	/**
	 * The hosted site whose slot this API key was minted for (the key the
	 * site's seeding recorded on its provision), when the site is the user's,
	 * not removed, and its plan grants a slot; otherwise null.
	 */
	public static function provisionForKey(int $user_id, $api_key_id) {
		$api_key_id = intval($api_key_id);
		if ($user_id <= 0 || $api_key_id <= 0 || !class_exists('CustomerCloudProvision')) {
			return null;
		}
		$rows = new MultiCustomerCloudProvision(array('cvp_fleet_apk_api_key_id' => $api_key_id, 'deleted' => false));
		foreach ($rows as $provision) {
			if (intval($provision->get('cvp_usr_user_id')) === $user_id && self::site_plan_grants_slot($provision)) {
				return $provision;
			}
		}
		return null;
	}

	/**
	 * The slot the signed-in caller speaks for: a site key's site's slot
	 * (none when the site no longer counts), or any other caller's tier slot.
	 */
	public static function callerSlot($session): ?MailboxFleetSlot {
		$user_id = intval($session->get_user_id());
		if ($user_id <= 0) {
			return null;
		}
		$api_key_id = $session->get_api_key_id();
		if (self::isSiteKey($api_key_id)) {
			$provision = self::provisionForKey($user_id, $api_key_id);
			return $provision !== null ? MailboxFleetSlot::activeForProvision(intval($provision->key)) : null;
		}
		return MailboxFleetSlot::activeForUser($user_id);
	}

	/**
	 * Whether a site's slot has lost its site for good: the site was purged
	 * (-1), its provision or its node was removed, or the site moved to its
	 * buyer's own cloud account (hosting mode transferred), which ends our
	 * hosting of it: its slot is released at once, so its domain can be
	 * claimed again. A site whose hosting only lapsed keeps its slot under
	 * the entitlement ladder instead.
	 */
	public static function siteGone(MailboxFleetSlot $slot): bool {
		$provision_id = intval($slot->get('mft_cvp_customer_cloud_provision_id'));
		if ($provision_id === 0) {
			return false;
		}
		if ($provision_id < 0) {
			return true;
		}
		// Not knowing is not gone: with server_manager inactive the site
		// cannot be looked up, and its slot waits under the ladder instead.
		if (!class_exists('CustomerCloudProvision') || !class_exists('ManagedNode')) {
			return false;
		}
		$provision = new CustomerCloudProvision($provision_id, TRUE);
		if (!$provision->key || $provision->get('cvp_delete_time') || $provision->is_transferred()) {
			return true;
		}
		$node_id = intval($provision->get('cvp_mgn_managed_node_id'));
		if ($node_id <= 0) {
			return true;
		}
		$node = new ManagedNode($node_id, TRUE);
		return !$node->key || (bool)$node->get('mgn_delete_time');
	}

	/**
	 * Release a removed site's slot (its claims are revoked, so the domain
	 * can be claimed again by the buyer's next site) and retire every site
	 * key of its buyer's whose site is gone.
	 */
	public static function releaseSiteSlot(MailboxFleetSlot $slot): void {
		self::releaseSlot($slot);
		$user_id = intval($slot->get('mft_usr_user_id'));
		if ($user_id <= 0 || !class_exists('CustomerCloudProvision') || !class_exists('ManagedNode')) {
			return; // a key is retired only when its site is known to be gone
		}
		foreach (new MultiApiKey(array('user_id' => $user_id)) as $key) {
			$name = (string)$key->get('apk_name');
			if (!$key->get('apk_is_active') || strpos($name, self::SITE_KEY_NAME_PREFIX) !== 0) {
				continue;
			}
			$site_id = intval(substr($name, strlen(self::SITE_KEY_NAME_PREFIX)));
			$site = $site_id > 0 ? new CustomerCloudProvision($site_id, TRUE) : null;
			$live = $site !== null && $site->key && !$site->get('cvp_delete_time') && !$site->is_transferred();
			if ($live) {
				$node_id = intval($site->get('cvp_mgn_managed_node_id'));
				$node = $node_id > 0 ? new ManagedNode($node_id, TRUE) : null;
				$live = $node !== null && $node->key && !$node->get('mgn_delete_time');
			}
			if (!$live) {
				$key->set('apk_is_active', FALSE);
				$key->save();
			}
		}
	}

	/**
	 * The records a hosted site's own domain needs for its slot, once the
	 * site has enrolled and filed its ownership claim: the MX naming the
	 * slot's mail name, and the ownership TXT while the claim is pending.
	 * Each is array(type, name, value, priority, note), the shape
	 * ProvisionHostedMail keeps its mail records in. [] while there is
	 * nothing to publish yet.
	 */
	public static function site_inbound_records($provision): array {
		if (!self::site_plan_grants_slot($provision)) {
			return array();
		}
		$slot = MailboxFleetSlot::activeForProvision(intval($provision->key));
		$mx = $slot !== null ? strtolower(trim((string)$slot->get('mft_mx_hostname'))) : '';
		$domain = strtolower(trim((string)$provision->get('cvp_domain')));
		if ($mx === '' || $domain === '') {
			return array();
		}
		$claim = null;
		foreach (new MultiMailboxFleetDomainClaim(array('slot_id' => intval($slot->key), 'domain' => $domain,
				'live' => true, 'deleted' => false)) as $row) {
			$claim = $row;
			break;
		}
		if ($claim === null) {
			return array();
		}
		$records = array(array('type' => 'MX', 'name' => $domain, 'value' => $mx, 'priority' => 10,
			'note' => 'Brings this site\'s mail in through our relay'));
		if ((string)$claim->get('mfd_status') === MailboxFleetDomainClaim::STATUS_PENDING) {
			$records[] = array('type' => 'TXT', 'name' => $claim->challengeHost(),
				'value' => (string)$claim->get('mfd_txt_token'), 'priority' => null,
				'note' => 'Proves the domain is this site\'s, so the relay accepts its mail');
		}
		return $records;
	}

	/** Whether a slot is still entitled: a site's by the site's hosting, any other by its buyer's tier. */
	public static function slotEntitled(MailboxFleetSlot $slot): bool {
		$user_id = intval($slot->get('mft_usr_user_id'));
		$provision_id = intval($slot->get('mft_cvp_customer_cloud_provision_id'));
		if ($provision_id !== 0) {
			// A site's slot (-1: its site was purged) answers only to its site.
			if ($provision_id < 0 || !class_exists('CustomerCloudProvision')) {
				return false;
			}
			$provision = new CustomerCloudProvision($provision_id, TRUE);
			return $provision->key && intval($provision->get('cvp_usr_user_id')) === $user_id
				&& self::site_entitled($provision);
		}
		return $user_id > 0 && self::entitled($user_id);
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
	public static function enroll(int $user_id, array $keys, $provision = null): MailboxFleetSlot {
		if (!self::enabled()) {
			throw new FleetServiceException('The hosted relay fleet is not accepting enrollments.');
		}
		if ($provision !== null) {
			if (!self::site_entitled($provision) || intval($provision->get('cvp_usr_user_id')) !== $user_id) {
				throw new FleetServiceException('This site\'s hosting does not include a hosted relay slot right now.');
			}
		} elseif (!self::entitled($user_id)) {
			throw new FleetServiceException('Your subscription does not include a hosted relay slot.');
		}
		$public_key = trim((string)($keys['public_key'] ?? ''));
		$decoded = base64_decode($public_key, true);
		if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
			throw new FleetServiceException('public_key is not a valid Ed25519 public key.');
		}

		$existing = $provision !== null
			? MailboxFleetSlot::activeForProvision(intval($provision->key))
			: MailboxFleetSlot::activeForUser($user_id);
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
		$slot->set('mft_mfs_mailbox_fleet_shard_id', intval($shard->key));
		$slot->set('mft_usr_user_id', $user_id);
		$slot->set('mft_cvp_customer_cloud_provision_id', $provision !== null ? intval($provision->key) : null);
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
		$shard = new MailboxFleetShard(intval($slot->get('mft_mfs_mailbox_fleet_shard_id')), TRUE);
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
		$slot->set('mft_release_time', gmdate('Y-m-d H:i:s'));
		// The shard stops accepting for its domains before anything else.
		$slot->set('mft_needs_domain_sync', true);
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
		if ($live->count() >= self::slotMaxDomains($slot)) {
			throw new FleetServiceException('This slot\'s hosted-relay domain limit is reached.');
		}

		$claim = new MailboxFleetDomainClaim(NULL);
		$claim->set('mfd_mft_mailbox_fleet_slot_id', intval($slot->key));
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
		if (intval($claim->get('mfd_mft_mailbox_fleet_slot_id')) !== intval($slot->key)) {
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
	 * Whether an active slot's shard keeps mail names (relay 3.13) and does not
	 * have this slot's yet: the reconcile then re-adds the tenant, which updates
	 * its registration in place and carries the name.
	 */
	public static function mailNameOwed(MailboxFleetSlot $slot): bool {
		$name = strtolower(trim((string)$slot->get('mft_mx_hostname')));
		if ($name === '' || (string)$slot->get('mft_status') !== MailboxFleetSlot::STATUS_ACTIVE
				|| (string)$slot->get('mft_mx_hostname_applied') === self::mailNameKey($slot)) {
			return false;
		}
		$shard = new MailboxFleetShard(intval($slot->get('mft_mfs_mailbox_fleet_shard_id')), TRUE);
		return RelayVersion::passesMailAppsShared((string)$shard->get('mfs_provisioned_version'));
	}

	/**
	 * What mft_mx_hostname_applied records once delivered: the shard and the
	 * name, so a slot moved to another shard is owed its name there too.
	 */
	public static function mailNameKey(MailboxFleetSlot $slot): string {
		return intval($slot->get('mft_mfs_mailbox_fleet_shard_id')) . ':' . strtolower(trim((string)$slot->get('mft_mx_hostname')));
	}

	/**
	 * Each born, active shard's running version, from its operator-signed ping
	 * (the version it reports, not only the one it was provisioned with), so a
	 * shard renewed or upgraded any way is seen at its version. Unreachable
	 * shards keep what was recorded. Returns how many changed.
	 */
	public static function settleShardVersions(): int {
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelayClient.php'));
		$changed = 0;
		foreach (new MultiMailboxFleetShard(array('active' => true, 'deleted' => false)) as $shard) {
			$ip = trim((string)$shard->get('mfs_public_ip'));
			$pin = trim((string)$shard->get('mfs_identity_fingerprint'));
			if ($ip === '' || $pin === '') {
				continue;
			}
			try {
				$ping = RelayClient::forOperator($ip, $pin)->ping();
			} catch (\Throwable $e) {
				error_log('FleetService: shard ' . $shard->key . ' did not answer its ping: ' . $e->getMessage());
				continue;
			}
			$version = substr(trim((string)($ping['provisioned'] ?? '')), 0, 20);
			if ($version !== '' && preg_match('/^\d+(\.\d+)*$/', $version) && $version !== (string)$shard->get('mfs_provisioned_version')) {
				$shard->set('mfs_provisioned_version', $version);
				$shard->save();
				$changed++;
			}
		}
		return $changed;
	}

	/**
	 * Perform one tenant lifecycle act for a slot: add_tenant | set_domains |
	 * remove_tenant - one operator-signed request to the shard's tenant routes,
	 * the slot's status moving on the verdict here and now. Returns true when
	 * the act was performed; $verdict receives the shard's answer. A
	 * remove_tenant with $extra['force'] removes the tenant with its spool.
	 */
	public static function applyTenant(MailboxFleetSlot $slot, string $kind, array $extra = array(), ?array &$verdict = null): bool {
		$verdict = array();
		$shard = new MailboxFleetShard(intval($slot->get('mft_mfs_mailbox_fleet_shard_id')), TRUE);
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
					$mx_hostname = strtolower(trim((string)$slot->get('mft_mx_hostname')));
					$verdict = $client->tenantAdd($slug, (string)$slot->get('mft_public_key'), $slot->shardDomains(), array(
						'forward_hourly_limit' => intval($slot->get('mft_forward_hourly_limit')),
						'spool_max_mib'        => intval($slot->get('mft_spool_max_mib')),
						'spool_max_entries'    => intval($slot->get('mft_spool_max_entries')),
					), $mx_hostname);
					// A shard that keeps the name (3.13) now has it; an older one
					// ignores it, so it stays owed until the shard is renewed.
					if (($verdict['status'] ?? '') === 'ok' && $mx_hostname !== ''
							&& RelayVersion::passesMailAppsShared((string)$shard->get('mfs_provisioned_version'))
							&& (string)$slot->get('mft_mx_hostname_applied') !== self::mailNameKey($slot)) {
						$slot->set('mft_mx_hostname_applied', self::mailNameKey($slot));
						$slot->save();
					}
					if (($verdict['status'] ?? '') === 'ok'
							&& (string)$slot->get('mft_status') === MailboxFleetSlot::STATUS_PROVISIONING) {
						$slot->set('mft_status', MailboxFleetSlot::STATUS_ACTIVE);
						$slot->save();
					}
					break;
				case 'set_domains':
					$verdict = $client->tenantSetDomains($slug, $slot->shardDomains());
					if (self::allowlistApplied($verdict) && (bool)$slot->get('mft_needs_domain_sync')) {
						$slot->set('mft_needs_domain_sync', false);
						$slot->save();
					}
					break;
				case 'remove_tenant':
					$verdict = $client->tenantRemove($slug, !empty($extra['force']));
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
		if ($kind === 'set_domains' && self::allowlistApplied($verdict)) {
			return true;
		}
		if (($verdict['status'] ?? '') !== 'ok') {
			// A refusal (an undrained spool on remove, a bad domain) is the
			// shard's answer; the next reconcile pass asks again.
			error_log('FleetService: shard answered ' . $kind . ' for slot ' . $slot->key . ' with '
				. (string)($verdict['status'] ?? '?') . ': ' . (string)($verdict['reason'] ?? ''));
		}
		return true;
	}

	/**
	 * Did the shard write a set_domains allowlist? It did when its answer is
	 * ok, and also when it answers rejected with its maps installed: the
	 * rejection is then of the site's own map naming a domain the new list
	 * leaves out, which an emptied or narrowed list is meant to do. A list the
	 * shard refused outright carries no installed merge.
	 */
	public static function allowlistApplied(array $verdict): bool {
		$status = (string)($verdict['status'] ?? '');
		if ($status === 'ok') {
			return true;
		}
		return $status === 'rejected' && !empty($verdict['merge']['installed']);
	}

	/** Does the slot's shard run a relay that removes a tenant with mail left (3.20)? Unknown reads as no. */
	public static function shardForcesRemoval(MailboxFleetSlot $slot): bool {
		$shard = new MailboxFleetShard(intval($slot->get('mft_mfs_mailbox_fleet_shard_id')), TRUE);
		return RelayVersion::removesWithMailLeft((string)$shard->get('mfs_provisioned_version'));
	}

	/**
	 * Has a released slot waited long enough for its site to collect what
	 * its shard holds? After mailbox_fleet_release_drain_days the shard is
	 * told to remove it with whatever is left.
	 */
	public static function releaseDrainOver(MailboxFleetSlot $slot, ?int $now = null): bool {
		$released = trim((string)$slot->get('mft_release_time'));
		if ($released === '') {
			// Released before the time was kept: the time counts from now.
			$slot->set('mft_release_time', gmdate('Y-m-d H:i:s'));
			$slot->save();
			return false;
		}
		$raw = trim((string)Globalvars::get_instance()->get_setting('mailbox_fleet_release_drain_days'));
		$days = $raw === '' ? 30 : max(1, intval($raw));
		return strtotime($released . ' UTC') + $days * 86400 <= ($now ?? time());
	}

}
