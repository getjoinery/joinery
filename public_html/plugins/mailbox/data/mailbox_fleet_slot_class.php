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
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class MailboxFleetSlotException extends SystemBaseException {}

class MailboxFleetSlot extends SystemBase {
	public static $prefix = 'mft';
	public static $tablename = 'mft_mailbox_fleet_slots';
	public static $pkey_column = 'mft_mailbox_fleet_slot_id';

	const STATUS_PROVISIONING = 'provisioning';
	const STATUS_ACTIVE       = 'active';
	const STATUS_SUSPENDED    = 'suspended';
	const STATUS_RELEASED     = 'released';
	const STATUS_EVICTED      = 'evicted';

	protected static $foreign_key_actions = [
		'mft_mfs_shard_id'  => ['action' => 'prevent',
			'message' => 'Shard still has slots on it — release or evict them first.'],
		'mft_usr_user_id'   => ['action' => 'null'],
	];

	public static $field_specifications = array(
		'mft_mailbox_fleet_slot_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'mft_mfs_shard_id'          => array('type'=>'int8', 'is_nullable'=>false),
		// The customer account that enrolled — entitlement is checked against
		// this user's subscription tier, at enrollment and on the periodic re-check.
		'mft_usr_user_id'           => array('type'=>'int8'),
		// The tenant's identity on the shard: account jt-<slug>, spool
		// /var/spool/joinery-relay/<slug>, fragment drop under home/<slug>/.
		'mft_slug'                  => array('type'=>'varchar(28)'),
		'mft_status'                => array('type'=>'varchar(20)', 'is_nullable'=>false, 'default'=>'provisioning'),
		// Allocated WireGuard address inside the shard's tunnel subnet.
		// The operator-controlled per-tenant MX hostname (an A record to the
		// shard's IP — re-sharding is an A-record change, tenants never touch DNS).
		'mft_mx_hostname'           => array('type'=>'varchar(255)'),
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
		'mft_update_time'           => array('type'=>'timestamp(6)'),
		'mft_delete_time'           => array('type'=>'timestamp(6)'),
	);

	// Row scope: the SystemBase default (owner-or-staff via mft_usr_user_id)
	// is exactly right — the enrolled customer touches their own slot, the
	// operator's staff touch all of them.

	function prepare() {
		$this->set('mft_update_time', gmdate('Y-m-d H:i:s'));
	}

	/** The tenant's live (non-terminal) slot for a user, or null. */
	public static function activeForUser(int $user_id): ?MailboxFleetSlot {
		$multi = new MultiMailboxFleetSlot(array('user_id' => $user_id, 'live' => true, 'deleted' => false));
		$multi->load();
		foreach ($multi as $slot) {
			return $slot;
		}
		return null;
	}

	/** All verified domains claimed by this slot (the shard allowlist content). */
	public function verifiedDomains(): array {
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_fleet_domain_claim_class.php'));
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

		if (isset($this->options['shard_id'])) {
			$filters['mft_mfs_shard_id'] = [intval($this->options['shard_id']), PDO::PARAM_INT];
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
