<?php
/**
 * MailboxFleetSlot - one tenant's slot on the operator's shared relay fleet.
 *
 * (specs/mailbox_relay_shared_fleet.md § Enrollment / Billing). A slot binds a
 * customer account (the API key's user — the entitlement subject) to a shard,
 * a tenant slug, an allocated tunnel address, and a per-tenant MX hostname.
 * Lifecycle: provisioning → active → suspended (entitlement lapse) →
 * released/evicted. The fleet service effects every transition by dispatching
 * a server_manager job against the shard's node; the job id is kept here so
 * fleet_status can reconcile lazily (server_manager never learns what a
 * tenant is).
 *
 * @version 1.3 - mft_cvp_customer_cloud_provision_id: a slot a hosted site's plan grants belongs to that site;
 *                activeForUser() answers only for the buyer's tier slot, activeForProvision() for a site's
 * @version 1.2 - mft_mx_hostname_applied: the mail name last delivered to the slot's shard
 * @version 1.1 - the four shared status values come from the core ServiceTenantLadder
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class MailboxFleetSlotException extends SystemBaseException {}

class MailboxFleetSlot extends SystemBase {
	public static $prefix = 'mft';
	public static $tablename = 'mft_mailbox_fleet_slots';
	public static $access_class = 'config';
	public static $pkey_column = 'mft_mailbox_fleet_slot_id';

	// The four shared rungs are the core ServiceTenantLadder's vocabulary,
	// which the relay reconcile walks; evicted is the fleet's own last rung
	// (the shard has removed the tenant).
	const STATUS_PROVISIONING = ServiceTenantLadder::STATE_PROVISIONING;
	const STATUS_ACTIVE       = ServiceTenantLadder::STATE_ACTIVE;
	const STATUS_SUSPENDED    = ServiceTenantLadder::STATE_SUSPENDED;
	const STATUS_RELEASED     = ServiceTenantLadder::STATE_RELEASED;
	const STATUS_EVICTED      = 'evicted';

	protected static $foreign_key_actions = [
		'mft_mfs_mailbox_fleet_shard_id'  => ['action' => 'prevent',
			'message' => 'Shard still has slots on it — release or evict them first.'],
		'mft_usr_user_id'   => ['action' => 'null'],
		// A purged site leaves its slot a site's slot with no site behind it
		// (-1), never NULL: NULL would turn it into its buyer's tier slot. The
		// reconcile then finds it unentitled and suspends it after grace.
		'mft_cvp_customer_cloud_provision_id' => ['action' => 'set_value', 'value' => -1,
			'source_table' => 'cvp_customer_cloud_provisions'],
	];

	public static $field_specifications = array(
		'mft_mailbox_fleet_slot_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'mft_mfs_mailbox_fleet_shard_id'          => array('type'=>'int8', 'is_nullable'=>false),
		// The customer account that enrolled — entitlement is checked against
		// this user's subscription tier, at enrollment and on the periodic re-check.
		'mft_usr_user_id'           => array('type'=>'int8'),
		// The hosted site whose plan grants this slot (server_manager's
		// cvp_customer_cloud_provisions), or NULL for a slot the buyer's tier
		// grants. A site's slot is that site's alone: its entitlement is the
		// site's hosting, and another site of the same buyer never shares it.
		'mft_cvp_customer_cloud_provision_id' => array('type'=>'int8'),
		// The tenant's identity on the shard: account jt-<slug>, spool
		// /var/spool/joinery-relay/<slug>, fragment drop under home/<slug>/.
		'mft_slug'                  => array('type'=>'varchar(28)'),
		'mft_status'                => array('type'=>'varchar(20)', 'is_nullable'=>false, 'default'=>'provisioning'),
		// Allocated WireGuard address inside the shard's tunnel subnet.
		// The operator-controlled per-tenant MX hostname (an A record to the
		// shard's IP — re-sharding is an A-record change, tenants never touch DNS).
		'mft_mx_hostname'           => array('type'=>'varchar(255)'),
		// The shard and mail name last delivered in a tenant-add to a shard that
		// keeps it (relay 3.13), as <shard id>:<name>: the reconcile re-adds an
		// active slot whose shard or name differs.
		'mft_mx_hostname_applied'   => array('type'=>'varchar(255)'),
		// The tenant's credentials the shard peers/authorizes: main-box
		// WireGuard public key and the pull SSH public key (locked to the
		// tenant shell by add-tenant).
		// The tenant's relay client public key (Ed25519, base64): what the shard
		// holds in its registry and verifies every request against
		// (specs/relay_without_a_shell.md).
		'mft_public_key'            => array('type'=>'varchar(64)'),
		// Shard-policy limits written into the tenant's root-owned limits.json.
		'mft_forward_hourly_limit'  => array('type'=>'int4', 'is_nullable'=>false, 'default'=>200),
		'mft_spool_max_mib'         => array('type'=>'int4', 'is_nullable'=>false, 'default'=>512),
		'mft_spool_max_entries'     => array('type'=>'int4', 'is_nullable'=>false, 'default'=>5000),
		// The most recent lifecycle job (add-tenant / set-domains / remove-tenant)
		// dispatched for this slot; fleet_status reconciles from it lazily.
		// Set when the slot's verified-domain set changed (a claim verified, a
		// suspension); the relay reconcile task dispatches the set-domains job
		// and clears it once the shard allowlist matches again.
		'mft_needs_domain_sync'     => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
		// Entitlement bookkeeping: last successful re-check, and when a lapse was
		// first observed (suspension fires after the grace window).
		'mft_entitlement_check_time'=> array('type'=>'timestamp(6)'),
		'mft_entitlement_lapse_time'=> array('type'=>'timestamp(6)'),
		'mft_create_time'           => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'mft_update_time'           => array('type'=>'timestamp(6)', 'stamp_on_save'=>true),
		'mft_delete_time'           => array('type'=>'timestamp(6)'),
	);

	// Row scope: the SystemBase default (owner-or-staff via mft_usr_user_id)
	// is exactly right — the enrolled customer touches their own slot, the
	// operator's staff touch all of them.


	/**
	 * The live (non-terminal) slot the user's tier grants, or null. A slot a
	 * hosted site's plan grants is that site's, and never answers here.
	 */
	public static function activeForUser(int $user_id): ?MailboxFleetSlot {
		$multi = new MultiMailboxFleetSlot(array('user_id' => $user_id, 'tier_granted' => true, 'live' => true, 'deleted' => false));
		foreach ($multi as $slot) {
			return $slot;
		}
		return null;
	}

	/** The live slot a hosted site's plan grants it, or null. */
	public static function activeForProvision(int $provision_id): ?MailboxFleetSlot {
		if ($provision_id <= 0) {
			return null;
		}
		$multi = new MultiMailboxFleetSlot(array('provision_id' => $provision_id, 'live' => true, 'deleted' => false));
		foreach ($multi as $slot) {
			return $slot;
		}
		return null;
	}

	/** All verified domains claimed by this slot (the shard allowlist content). */
	public function verifiedDomains(): array {
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_fleet_domain_claims_class.php'));
		$claims = new MultiMailboxFleetDomainClaim(array(
			'slot_id' => intval($this->key), 'status' => MailboxFleetDomainClaim::STATUS_VERIFIED, 'deleted' => false,
		));
		$claims->load();
		$domains = array();
		foreach ($claims as $claim) {
			$domains[] = strtolower(trim((string)$claim->get('mfd_domain')));
		}
		sort($domains);
		return array_values(array_unique(array_filter($domains)));
	}
}

class MultiMailboxFleetSlot extends SystemMultiBase {
	protected static $model_class = 'MailboxFleetSlot';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['user_id'])) {
			$filters['mft_usr_user_id'] = [intval($this->options['user_id']), PDO::PARAM_INT];
		}

		if (isset($this->options['provision_id'])) {
			$filters['mft_cvp_customer_cloud_provision_id'] = [intval($this->options['provision_id']), PDO::PARAM_INT];
		}

		if (!empty($this->options['tier_granted'])) {
			$filters['mft_cvp_customer_cloud_provision_id'] = 'IS NULL';
		}

		if (isset($this->options['shard_id'])) {
			$filters['mft_mfs_mailbox_fleet_shard_id'] = [intval($this->options['shard_id']), PDO::PARAM_INT];
		}

		if (isset($this->options['status'])) {
			$filters['mft_status'] = [(string)$this->options['status'], PDO::PARAM_STR];
		}

		// 'live' = holding a slot: provisioning, active, or suspended (still
		// occupies capacity and keeps its slug); released/evicted are terminal.
		if (!empty($this->options['live'])) {
			$filters['mft_status'] = "IN ('provisioning', 'active', 'suspended')";
		}


		return $this->_get_resultsv2('mft_mailbox_fleet_slots', $filters, $this->order_by, $only_count, $debug);
	}
}
