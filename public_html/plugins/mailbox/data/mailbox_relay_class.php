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
 * @version 1.4 - readHealth() carries the relay's Postfix queue depth (NULL when
 *                unknown, never 0); provisionedVersion() and queuedCount()
 *                (specs/mailbox_relay_upgrade_without_server_manager.md)
 * @version 1.3 - scanner health: readHealth()/pollHealth() and the cached answer
 *                (specs/mailbox_relay_scanner_health.md)
 * @version 1.2 - tenant coordinates (slug, hosted-slot fields) + derived
 *                pull-account/spool/fragment-path helpers; recorded authserv-id
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
		// The name the relay's milters stamp Authentication-Results under — its
		// own mail hostname, and the only authserv-id trusted on a pulled message
		// (InboundEmailRouter::authFromRelayMeta). It is NOT the MX hostname on a
		// fleet slot: the MX name is per-tenant, while the stamp carries the
		// shard's. Empty falls back to the MX hostname, which is the same host on
		// a self-hosted relay.
		'mrl_authserv_id'        => array('type'=>'varchar(255)'),
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
		// Held blobs from the last pull: recoverable mail left on the relay
		// because its domain is disabled/unconfigured or its Fortress owner is
		// not yet resolvable (specs/mailbox_data_loss_fixes.md, Fixes 6/7). A
		// live gauge — held blobs are re-counted each pull until the domain
		// returns / the owner resolves, or they age out past the grace window.
		'mrl_last_pull_held'     => array('type'=>'int4', 'is_nullable'=>false, 'default'=>0),
		// Last answer to joinery-ping, cached (specs/mailbox_relay_scanner_health.md).
		// Cached rather than probed on demand for two reasons: an SSH round-trip does
		// not belong in a page render, and an unreachable relay is exactly when a
		// last-seen answer is worth most. The stored shape is readHealth()'s output.
		'mrl_last_health_json'   => array('type'=>'text'),
		'mrl_last_health_time'   => array('type'=>'timestamp(6)'),
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

	/**
	 * The name this relay's milters stamp Authentication-Results under, and so
	 * the only authserv-id trusted on a message pulled from it
	 * (InboundEmailRouter::authFromRelayMeta).
	 *
	 * The recorded value wins, because on a hosted fleet slot it is the only
	 * correct answer: the MX hostname there is a per-tenant name the shard never
	 * stamps under. The MX hostname is the fallback for a self-hosted relay, where
	 * the two are the same host. Empty when neither is a hostname — mrl_name is a
	 * label on a fleet slot, never a host.
	 */
	public function authservId(): string {
		foreach (array('mrl_authserv_id', 'mrl_mx_hostname', 'mrl_name') as $field) {
			$host = strtolower(trim((string)$this->get($field)));
			if ($host !== '' && strpos($host, '.') !== false) {
				return $host;
			}
		}
		return '';
	}

	/** The tenant's map-fragment drop area on the relay (fixed relay layout). */
	public function fragmentDir(): string {
		return '/opt/joinery-relay/home/' . $this->tenantSlug() . '/fragments';
	}

	// --- Scanner health (specs/mailbox_relay_scanner_health.md) ----------------
	//
	// The relay's content scanner is the one part of it a tenant cannot verify from
	// stored mail. opendkim/opendmarc write their verdicts into every message, so a
	// dead one shows up as unverified mail; rspamd writes a header ONLY when it
	// flags something, and milter_default_action is accept — so a relay that scans
	// and finds nothing and a relay whose rspamd is dead send identical evidence,
	// which is none. The relay has to be asked.

	const HEALTH_OK             = 'ok';            // scanning, wired, contract intact
	const HEALTH_NOT_DELIVERING = 'not_delivering';// alive-but-useless or dead — see reason
	const HEALTH_LEGACY         = 'legacy';        // relay predates the health ping
	const HEALTH_UNREADABLE     = 'unreadable';    // answered something we cannot parse
	const HEALTH_UNREACHABLE    = 'unreachable';   // the ping itself did not complete

	/**
	 * Interpret one joinery-ping answer. Pure and static — the whole verdict is a
	 * function of the relay's reply, so it is testable without a relay.
	 *
	 * An old relay answers the plain text `PONG <slug>`, and that is the capability
	 * probe: it is unambiguous, unlike an unknown verb, which answers `denied:
	 * unknown command` — indistinguishable from every other refusal the tenant
	 * shell can issue.
	 *
	 * @return array{state:string,reason:string,detail:string,services:array,
	 *               milters:array,contract:?bool,provisioned:string,slug:string,
	 *               queue:?int,sole:?bool}
	 */
	public static function readHealth(string $raw, int $exit_code = 0): array {
		$out = array('state' => self::HEALTH_UNREADABLE, 'reason' => '', 'detail' => '',
			'services' => array(), 'milters' => array(), 'contract' => null,
			'provisioned' => '', 'slug' => '', 'queue' => null, 'sole' => null);
		$text = trim($raw);
		$snippet = substr($text, 0, 300);

		$decoded = json_decode($text, true);
		if (!is_array($decoded) || !isset($decoded['services']) || !is_array($decoded['services'])
			|| !isset($decoded['milters']) || !is_array($decoded['milters'])) {
			if (stripos($text, 'PONG') === 0) {
				$out['state'] = self::HEALTH_LEGACY;
				$out['detail'] = 'The relay answered ' . $snippet . ' — it was built before the health ping.';
				return $out;
			}
			if ($exit_code !== 0) {
				$out['state'] = self::HEALTH_UNREACHABLE;
				$out['detail'] = 'The ping did not complete (exit ' . $exit_code . ')'
					. ($snippet !== '' ? ': ' . $snippet : '.');
				return $out;
			}
			$out['detail'] = 'The relay answered something this server cannot read'
				. ($snippet !== '' ? ': ' . $snippet : '.');
			return $out;
		}

		$out['services']    = $decoded['services'];
		$out['milters']     = $decoded['milters'];
		$out['contract']    = !empty($decoded['contract']);
		$out['provisioned'] = (string)($decoded['provisioned'] ?? '');
		$out['slug']        = (string)($decoded['slug'] ?? '');

		// Queue depth is absent on a shard (it would read out other tenants' mail
		// volume) and absent when the relay could not measure it. Both stay NULL:
		// an upgrade destroys whatever is queued, so "cannot tell" must never
		// render as "nothing to lose".
		if (isset($decoded['queue']) && is_numeric($decoded['queue'])) {
			$out['queue'] = max(0, intval($decoded['queue']));
		}

		// Is this deployment the only tenant on the relay? Only a relay new enough
		// to answer says; older ones leave it NULL, which means "cannot tell" and
		// NOT "yes". Nothing may wipe a relay on a null.
		if (isset($decoded['sole']) && is_bool($decoded['sole'])) {
			$out['sole'] = $decoded['sole'];
		}

		$rspamd_state = strtolower(trim((string)($decoded['services']['rspamd'] ?? '')));
		$rspamd_wired = !empty($decoded['milters']['rspamd']);

		// Order matters: report the FIRST thing that stops a verdict reaching this
		// server, because that is the thing to fix. A drifted contract on a dead
		// scanner is not news.
		if ($rspamd_state !== 'active') {
			$out['state']  = self::HEALTH_NOT_DELIVERING;
			$out['reason'] = 'dead';
			$out['detail'] = 'The content scanner on the relay is ' . ($rspamd_state !== '' ? $rspamd_state : 'not running') . '.';
			return $out;
		}
		if (!$rspamd_wired) {
			$out['state']  = self::HEALTH_NOT_DELIVERING;
			$out['reason'] = 'unwired';
			$out['detail'] = 'The content scanner is running but is not in the relay\'s mail path, '
				. 'so nothing is scanned.';
			return $out;
		}
		if (!$out['contract']) {
			$out['state']  = self::HEALTH_NOT_DELIVERING;
			$out['reason'] = 'drift';
			$out['detail'] = 'The content scanner is running, but the settings that decide which headers '
				. 'it stamps have been changed on the relay — so its verdicts no longer reach this server.';
			return $out;
		}

		$out['state']  = self::HEALTH_OK;
		$out['detail'] = 'The relay is scanning and its verdicts reach this server.';
		return $out;
	}

	/**
	 * Ask the relay how it is, and cache the answer on this row.
	 *
	 * An UNREACHABLE result is deliberately not stored: it says nothing about the
	 * scanner, and overwriting the last real answer with it would destroy the only
	 * information available during an outage. The cached answer simply ages, and
	 * its age is shown.
	 */
	public function pollHealth(bool $store = true): array {
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelaySsh.php'));
		list($code, $out) = RelaySsh::run(RelaySsh::sshCommand($this, 'joinery-ping'));
		$health = self::readHealth((string)$out, (int)$code);
		$health['checked_time'] = gmdate('Y-m-d H:i:s');

		if ($store && $health['state'] !== self::HEALTH_UNREACHABLE) {
			$this->set('mrl_last_health_json', json_encode($health));
			$this->set('mrl_last_health_time', $health['checked_time']);
			$this->save();
		}
		return $health;
	}

	/** The cached health answer, or null if this relay has never answered one. */
	public function lastHealth(): ?array {
		$json = trim((string)$this->get('mrl_last_health_json'));
		if ($json === '') {
			return null;
		}
		$health = json_decode($json, true);
		if (!is_array($health) || !isset($health['state'])) {
			return null;
		}
		$health['checked_time'] = (string)$this->get('mrl_last_health_time');
		return $health;
	}

	/**
	 * What changed between two health answers: 'down', 'recovered' or 'none'.
	 *
	 * Only a real transition is worth raising — a relay that stays broken is
	 * announced once, not every cron pass. A first-ever answer of "not delivering"
	 * IS a transition (from nothing known), because the operator has never been
	 * told. Legacy, unreadable and unreachable never transition: they are absences
	 * of information, and announcing an absence as a fault would fire on every
	 * relay built before this check existed.
	 */
	public static function healthTransition(string $before, string $after): string {
		if ($after === self::HEALTH_NOT_DELIVERING && $before !== self::HEALTH_NOT_DELIVERING) {
			return 'down';
		}
		if ($after === self::HEALTH_OK && $before === self::HEALTH_NOT_DELIVERING) {
			return 'recovered';
		}
		return 'none';
	}

	/** The cached state alone ('' when never polled) — what a transition compares. */
	public function lastHealthState(): string {
		$health = $this->lastHealth();
		return ($health === null) ? '' : (string)$health['state'];
	}

	/**
	 * The relay's own provisioning version, as it last reported it. '' when the
	 * relay has never answered, or answered a legacy PONG that predates the
	 * marker — both of which read as "unknown" upstream, which offers an upgrade.
	 */
	public function provisionedVersion(): string {
		$health = $this->lastHealth();
		return ($health === null) ? '' : trim((string)($health['provisioned'] ?? ''));
	}

	/**
	 * How many messages sat in the relay's Postfix queue when it last answered,
	 * or NULL for "not known" — a shared shard (where the depth is not this
	 * tenant's to read) or a relay that could not measure it.
	 *
	 * NULL is not zero, and callers must not collapse the two: this number exists
	 * to say what an upgrade would destroy.
	 */
	/**
	 * Is this deployment the ONLY tenant on the relay? TRUE, FALSE, or NULL for
	 * "the relay is too old to say".
	 *
	 * This is the wipe guard. A rebuild replaces every byte on the machine —
	 * every other tenant's account, allowlist, WireGuard peer and un-pulled mail
	 * with it — and the drain only ever empties THIS tenant's spool subdirectory,
	 * so nothing else is even preserved in passing. A deployment can see only its
	 * own tenancy, so the relay has to answer this, and NULL must never be read as
	 * yes.
	 */
	public function isSoleTenant(): ?bool {
		$health = $this->lastHealth();
		if ($health === null || !isset($health['sole']) || $health['sole'] === null) {
			return null;
		}
		return (bool)$health['sole'];
	}

	public function queuedCount(): ?int {
		$health = $this->lastHealth();
		if ($health === null || !isset($health['queue']) || $health['queue'] === null) {
			return null;
		}
		return intval($health['queue']);
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
