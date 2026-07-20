<?php
/**
 * MailboxRelay - the hardened ingest relay a deployment is fronted by.
 *
 * (specs/inbound_email_hardened_ingest_relay_executor.md). A relay-fronted
 * deployment puts a minimal, disposable VPS at the public MX: Postfix + verify
 * milters + the Go sealing binary + WireGuard, no PHP/DB/web. It seals each
 * accepted message to the recipient's public key and spools ciphertext; the main
 * Joinery box dials out over WireGuard and pulls sealed blobs.
 *
 * There is at most ONE active relay per deployment — once a relay exists it is
 * the MX for ALL hosted domains (a mixed MX would leak the origin), so this row
 * is effectively a singleton (see active()). It holds the tunnel connection
 * details the pull consumer and smarthost use, the alias-map sync bookkeeping the
 * health checks read, and the AMBIENT TRANSPORT KEYPAIR:
 *
 *   - Fortress mail is sealed at the relay to the owner's own vault public key —
 *     only a session opens it.
 *   - Standard/Private mail is sealed at the relay to this transport keypair,
 *     whose secret Joinery holds ambiently (sealed at rest under SecretBox). The
 *     pull consumer opens those blobs immediately and runs today's ingest. The
 *     transport wrapping exists so the relay disk never holds plaintext at rest
 *     for ANY level; it is transit protection, not a security-level guarantee.
 *
 * mailbox mustn't hard-depend on server_manager (provision_relay.sh is the
 * standalone floor), so the relay's identity lives here, not on a ManagedNode.
 * mrl_mgn_managed_node_id links to the server_manager node when one exists (for
 * the health dot on the dashboard).
 *
 * The relay stack is TENANCY-NATIVE (specs/mailbox_relay_shared_fleet.md): on
 * the relay this deployment is one tenant — identified by mrl_tenant_slug —
 * with its own spool subdirectory, restricted pull account, and fragment drop
 * area. A self-hosted relay is a fleet of one (slug 'main'); a hosted fleet
 * slot (mrl_is_hosted) carries the coordinates the fleet service returned at
 * enrollment instead of self-provisioned ones. Either way this row remains the
 * deployment's ONE relay, so active() stays a singleton.
 *
 * @version 1.1 - tenant coordinates (slug, hosted-slot fields) + derived
 *                pull-account/spool/fragment-path helpers
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class MailboxRelayException extends SystemBaseException {}

class MailboxRelay extends SystemBase {
	public static $prefix = 'mrl';
	public static $tablename = 'mrl_mailbox_relays';
	public static $pkey_column = 'mrl_mailbox_relay_id';

	protected static $foreign_key_actions = [
		'mrl_mgn_managed_node_id' => ['action' => 'null'],
	];

	// A relay row owns no dependent rows (the message/spool link is by value, not
	// FK), so a permanent delete cascades to nothing.
	public static $permanent_delete_actions = [];

	public static $field_specifications = array(
		'mrl_mailbox_relay_id'   => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'mrl_name'               => array('type'=>'varchar(100)'),
		// Tunnel address (the relay's WireGuard IP) used for rsync/ssh/smarthost;
		// mrl_public_ip is the public MX IP that mail DNS points at.
		'mrl_host'               => array('type'=>'varchar(255)'),
		'mrl_public_ip'          => array('type'=>'varchar(64)'),
		// The tenant's restricted pull account on the relay (jt-<slug>, locked to
		// the joinery-tenant-shell forced command). Empty derives jt-<tenant slug>
		// via pullUser() — never a root-class login.
		'mrl_ssh_user'           => array('type'=>'varchar(50)'),
		'mrl_ssh_port'           => array('type'=>'int4', 'default'=>22),
		'mrl_ssh_key_path'       => array('type'=>'varchar(500)'),
		// The tenant's spool SUBDIRECTORY on the relay. Empty derives
		// /var/spool/joinery-relay/<tenant slug> via spoolPath().
		'mrl_spool_path'         => array('type'=>'varchar(500)'),
		// This deployment's tenant identity on the relay (spool subdir, pull
		// account, fragment drop area all derive from it). 'main' on a
		// self-hosted fleet of one; fleet-assigned on a hosted slot.
		'mrl_tenant_slug'        => array('type'=>'varchar(28)', 'default'=>'main'),
		// Hosted fleet slot (specs/mailbox_relay_shared_fleet.md § Enrollment):
		// true when this relay is a slot on the operator's shared fleet. The MX
		// hostname is the operator-controlled per-tenant A record tenants point
		// their domains' MX at; the slot id is the enrollment's remote handle.
		'mrl_is_hosted'          => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
		'mrl_mx_hostname'        => array('type'=>'varchar(255)'),
		'mrl_fleet_slot_id'      => array('type'=>'int8'),
		// WireGuard: the relay's public key + listen endpoint (host:port), and the
		// tunnel IP assigned to the relay. Joinery always initiates the peering.
		'mrl_wg_public_key'      => array('type'=>'varchar(255)'),
		'mrl_wg_endpoint'        => array('type'=>'varchar(255)'),
		'mrl_wg_ip'              => array('type'=>'varchar(64)'),
		// Ambient transport keypair (Standard/Private sealing). Public key is
		// cleartext; the secret is sealed at rest under SecretBox and only opened
		// in-process by the pull consumer.
		'mrl_transport_public_key'   => array('type'=>'text'),
		'mrl_transport_secret_sealed'=> array('type'=>'text'),
		'mrl_transport_key_generation'=> array('type'=>'int4', 'is_nullable'=>false, 'default'=>1),
		// Relay-fronted mode active. When true, the MTA-stack decommission and the
		// smarthost/health retargeting apply.
		'mrl_is_enabled'         => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
		// Alias-map sync bookkeeping. mrl_map_content_hash is the sha256 of the last
		// successfully pushed map; the sync compares a freshly-built map's hash to it
		// and pushes only on a real change (so any routing edit is picked up
		// automatically, and forward-counter churn never triggers a push).
		// mrl_map_version counts successful pushes, for display/health.
		'mrl_map_version'        => array('type'=>'int8', 'is_nullable'=>false, 'default'=>0),
		'mrl_map_content_hash'   => array('type'=>'varchar(64)'),
		'mrl_last_push_time'     => array('type'=>'timestamp(6)'),
		'mrl_last_pull_time'     => array('type'=>'timestamp(6)'),
		'mrl_mgn_managed_node_id'=> array('type'=>'int8'),
		// Cloud-provisioned relays (specs/mailbox_relay_cloud_provisioning.md):
		// the customer-account instance a destroy/rebuild targets.
		'mrl_cloud_provider'     => array('type'=>'varchar(32)'),
		'mrl_cloud_instance_id'  => array('type'=>'varchar(50)'),
		'mrl_create_time'        => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'mrl_update_time'        => array('type'=>'timestamp(6)'),
		'mrl_delete_time'        => array('type'=>'timestamp(6)'),
	);

	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in ' . static::$tablename);
		}
	}

	function prepare() {
		$this->set('mrl_update_time', gmdate('Y-m-d H:i:s'));
	}

	/**
	 * The single active (enabled, non-deleted) relay, or null on a colocated
	 * deployment. Callers gate every relay behaviour on this being non-null.
	 */
	public static function active(): ?MailboxRelay {
		try {
			$db = DbConnector::get_instance()->get_db_link();
			$stmt = $db->query(
				"SELECT mrl_mailbox_relay_id FROM mrl_mailbox_relays
				  WHERE mrl_is_enabled = true AND mrl_delete_time IS NULL
				  ORDER BY mrl_mailbox_relay_id ASC LIMIT 1"
			);
			$id = $stmt ? $stmt->fetchColumn() : false;
			if ($id === false || $id === null) {
				return null;
			}
			return new MailboxRelay(intval($id), TRUE);
		} catch (\Throwable $e) {
			// The table may not exist yet (before update_database), and this is
			// called from core send/health paths that must never fatal.
			return null;
		}
	}

	/**
	 * Generate the ambient transport keypair if this relay has none yet, sealing
	 * the secret under SecretBox. Idempotent — returns the public key either way.
	 */
	public function ensureTransportKeypair(): string {
		$existing = (string)$this->get('mrl_transport_public_key');
		if ($existing !== '') {
			return $existing;
		}
		require_once(PathHelper::getIncludePath('includes/SealedBox.php'));
		require_once(PathHelper::getIncludePath('includes/SecretBox.php'));
		$kp = (new SealedBox())->generateKeypair();
		$sealed_secret = (new SecretBox())->encrypt($kp['secret']);
		$this->set('mrl_transport_public_key', $kp['public']);
		$this->set('mrl_transport_secret_sealed', $sealed_secret);
		$this->save();
		return $kp['public'];
	}

	/** The ambient transport public key (Standard/Private sealing target for the map). */
	public function transportPublicKey(): string {
		return (string)$this->get('mrl_transport_public_key');
	}

	/** This deployment's tenant identity on the relay ('main' when unset). */
	public function tenantSlug(): string {
		$slug = strtolower(trim((string)$this->get('mrl_tenant_slug')));
		return preg_match('/^[a-z0-9][a-z0-9-]{0,27}$/', $slug) ? $slug : 'main';
	}

	/** The restricted pull account (jt-<slug> unless the row overrides it). */
	public function pullUser(): string {
		return trim((string)$this->get('mrl_ssh_user')) ?: ('jt-' . $this->tenantSlug());
	}

	/** The tenant's spool subdirectory on the relay. */
	public function spoolPath(): string {
		$path = rtrim(trim((string)$this->get('mrl_spool_path')), '/');
		return $path !== '' ? $path : ('/var/spool/joinery-relay/' . $this->tenantSlug());
	}

	/** The tenant's map-fragment drop area on the relay (fixed relay layout). */
	public function fragmentDir(): string {
		return '/opt/joinery-relay/home/' . $this->tenantSlug() . '/fragments';
	}

	/**
	 * The ambient transport SECRET key (base64url), opened from its SecretBox
	 * wrapping. Used by the pull consumer to open Standard/Private blobs so they
	 * can run today's ingest immediately. Never logged.
	 */
	public function transportSecretKey(): string {
		$sealed = (string)$this->get('mrl_transport_secret_sealed');
		if ($sealed === '') {
			throw new MailboxRelayException('MailboxRelay: transport keypair not initialised');
		}
		require_once(PathHelper::getIncludePath('includes/SecretBox.php'));
		return (new SecretBox())->decrypt($sealed);
	}

}

class MultiMailboxRelay extends SystemMultiBase {
	protected static $model_class = 'MailboxRelay';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['enabled'])) {
			$filters['mrl_is_enabled'] = $this->options['enabled'] ? "= true" : "= false";
		}

		if (isset($this->options['deleted'])) {
			$filters['mrl_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

		return $this->_get_resultsv2('mrl_mailbox_relays', $filters, $this->order_by, $only_count, $debug);
	}
}
