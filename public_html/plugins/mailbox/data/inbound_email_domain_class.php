<?php
/**
 * InboundEmailDomain - Tracks domains that accept inbound mail.
 *
 * ied_is_imap_source marks a domain whose mail arrives by IMAP poll (an
 * InboundImapAccount populates one of its aliases) rather than by MX delivery.
 * The Setup tab skips MX/DNS checks for such a domain — the mail is already in
 * the remote mailbox, so no MX is required.
 *
 * ied_is_protected_identity marks a domain as a protected sending identity
 * (specs/mailbox_outbound_send_protection.md): while no unlock window is open,
 * the box holds no credential that can produce a DMARC-passing message From this
 * domain. Its DKIM private key is sealed to ied_owner_usr_user_id's vault public
 * key (ied_dkim_sealed_key), never given to opendkim, and unwrapped in-window at
 * compose time only. ied_dkim_public_dns holds the cleartext DKIM DNS value so
 * the Setup tab can verify the published record while the vault is locked.
 *
 * Rotation is staged: a new key seals into the ied_dkim_pending_* columns while
 * the live key keeps signing; cutover (pending → live) happens only after the
 * pending selector's DNS record verifies. Signing always reads the live columns.
 *
 * ied_security_level is the per-domain protection posture
 * (specs/mailbox_security_levels.md): 'standard' (server-managed plaintext),
 * 'private' (sealed at rest), or 'fortress' (sealed at the edge + session-gated
 * sending identity). It is the single switch that selects each mechanism's
 * plaintext-vs-sealed branch; mailboxes and aliases inherit it by design.
 *
 * @version 1.7
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class InboundEmailDomainException extends SystemBaseException {}

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/NotifiesRelayMapOnChange.php'));

class InboundEmailDomain extends SystemBase {
	use NotifiesRelayMapOnChange;
	public static $prefix = 'ied';
	public static $tablename = 'ied_inbound_email_domains';
	public static $pkey_column = 'ied_inbound_email_domain_id';

	// Catch-all mode values
	const CATCHALL_FORWARD = 'forward';
	const CATCHALL_STORE = 'store';

	// Security levels (specs/mailbox_security_levels.md). The single source of
	// truth for a domain's protection posture; every mailbox/alias inherits.
	// Standard = server-managed plaintext; Private = sealed at rest; Fortress =
	// sealed at the edge + session-gated sending identity.
	const LEVEL_STANDARD = 'standard';
	const LEVEL_PRIVATE  = 'private';
	const LEVEL_FORTRESS = 'fortress';

	public static $field_specifications = array(
		'ied_inbound_email_domain_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'ied_domain'            => array('type'=>'varchar(255)', 'required'=>true, 'is_nullable'=>false),
		'ied_is_enabled'        => array('type'=>'bool', 'default'=>true, 'is_nullable'=>false),
		'ied_catch_all_mode'    => array('type'=>'varchar(20)', 'default'=>'forward', 'is_nullable'=>false),
		'ied_catch_all_address' => array('type'=>'varchar(500)'),
		'ied_reject_unmatched'  => array('type'=>'bool', 'default'=>true, 'is_nullable'=>false),
		'ied_is_imap_source'    => array('type'=>'bool', 'default'=>false, 'is_nullable'=>false),
		// Security posture (specs/mailbox_security_levels.md): the single switch
		// that selects each mechanism's plaintext-vs-sealed branch.
		'ied_security_level'    => array('type'=>'varchar(10)', 'is_nullable'=>false, 'default'=>'standard'), // 'standard' | 'private' | 'fortress'
		// Outbound send protection (specs/mailbox_outbound_send_protection.md).
		'ied_is_protected_identity' => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
		'ied_owner_usr_user_id'     => array('type'=>'int8', 'is_nullable'=>true),   // whose vault seals the DKIM key
		'ied_dkim_selector'         => array('type'=>'varchar(63)', 'is_nullable'=>true),  // e.g. 'mailk1'
		'ied_dkim_sealed_key'       => array('type'=>'text', 'is_nullable'=>true),   // DKIM private key, crypto_box_seal'd to the owner public key
		'ied_dkim_public_dns'       => array('type'=>'text', 'is_nullable'=>true),   // cleartext DKIM DNS record value (Setup tab reads it while locked)
		'ied_dkim_key_generation'   => array('type'=>'int4', 'is_nullable'=>false, 'default'=>0),
		// Staged rotation: the next key, sealed and awaiting DNS verification.
		// The live key keeps signing until cutover swaps pending → live.
		'ied_dkim_pending_selector'   => array('type'=>'varchar(63)', 'is_nullable'=>true),
		'ied_dkim_pending_sealed_key' => array('type'=>'text', 'is_nullable'=>true),
		'ied_dkim_pending_public_dns' => array('type'=>'text', 'is_nullable'=>true),
		'ied_forwarding_subdomain'  => array('type'=>'varchar(255)', 'is_nullable'=>true),  // e.g. 'fwd.example.com' (per-domain only)
		'ied_create_time'       => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'ied_update_time'       => array('type'=>'timestamp(6)'),
		'ied_delete_time'       => array('type'=>'timestamp(6)'),
	);

	function prepare() {
		// Normalize domain to lowercase
		$domain = strtolower(trim($this->get('ied_domain')));
		$this->set('ied_domain', $domain);

		// Validate domain format
		if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $domain)) {
			throw new InboundEmailDomainException('Invalid domain format.');
		}

		// Validate catch-all mode
		$mode = $this->get('ied_catch_all_mode');
		if (!$mode) {
			$mode = self::CATCHALL_FORWARD;
			$this->set('ied_catch_all_mode', $mode);
		}
		if (!in_array($mode, [self::CATCHALL_FORWARD, self::CATCHALL_STORE], true)) {
			throw new InboundEmailDomainException('Invalid catch-all mode: ' . htmlspecialchars($mode));
		}

		if ($mode === self::CATCHALL_STORE) {
			// In store mode the address is ignored — clear it for consistency.
			$this->set('ied_catch_all_address', '');
		} else {
			// Validate catch-all address if provided (forward mode)
			$catch_all = $this->get('ied_catch_all_address');
			if ($catch_all && !filter_var($catch_all, FILTER_VALIDATE_EMAIL)) {
				throw new InboundEmailDomainException('Invalid catch-all email address.');
			}
		}

		// Check for duplicate domain
		if (!$this->key) {
			$existing = new MultiInboundEmailDomain(array('domain' => $domain, 'deleted' => false));
			if ($existing->count_all() > 0) {
				throw new InboundEmailDomainException('Domain already exists.');
			}
		}

		$this->set('ied_update_time', gmdate('Y-m-d H:i:s'));
	}

	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in ' . static::$tablename);
		}
	}

	/**
	 * Get the alias count for this domain.
	 */
	function get_alias_count() {
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
		$aliases = new MultiInboundEmailAlias(array('domain_id' => $this->key, 'deleted' => false));
		return $aliases->count_all();
	}

	/**
	 * Look up a domain by name.
	 */
	static function GetByDomain($domain) {
		$results = new MultiInboundEmailDomain(array('domain' => strtolower($domain), 'deleted' => false));
		$results->load();
		if (count($results)) {
			return $results->get(0);
		}
		return false;
	}

	/**
	 * True when an email address sits on a mailbox domain hosted on this platform
	 * (specs/mailbox_security_levels.md § Password reset, Population 2). A login OR
	 * recovery email on a hosted domain is circular — a reset link would land in an
	 * inbox that requires this very account to read. Shared by the register-time,
	 * account-email, and recovery-address guards.
	 */
	static function isHostedEmailAddress($email) {
		$email = strtolower(trim((string)$email));
		$at = strrpos($email, '@');
		if ($at === false) {
			return false;
		}
		$domain = substr($email, $at + 1);
		if ($domain === '') {
			return false;
		}
		return self::GetByDomain($domain) !== false;
	}

	/**
	 * This domain's security level (specs/mailbox_security_levels.md) — the
	 * single switch selecting each mechanism's plaintext-vs-sealed branch.
	 * Falls back to Standard for any unrecognized or empty stored value.
	 */
	function security_level() {
		$v = strtolower(trim((string)$this->get('ied_security_level')));
		if (!in_array($v, array(self::LEVEL_STANDARD, self::LEVEL_PRIVATE, self::LEVEL_FORTRESS), true)) {
			return self::LEVEL_STANDARD;
		}
		return $v;
	}

	/** True when this domain seals stored content at rest (Private or Fortress). */
	function seals_content() {
		return in_array($this->security_level(), array(self::LEVEL_PRIVATE, self::LEVEL_FORTRESS), true);
	}

	/**
	 * The highest security level across every domain the user has a stake in —
	 * one they own (ied_owner_usr_user_id) or hold a mailbox grant on (a grant
	 * on one of its aliases). Drives the per-level unlock-window caps
	 * (specs/mailbox_security_levels.md § The Unlock Window) and the Fortress
	 * mandatory-2FA enrollment gate. Returns 'standard' when the user touches
	 * no protected domain.
	 */
	static function maxSecurityLevelForUser(int $user_id): string {
		$rank = array(self::LEVEL_STANDARD => 0, self::LEVEL_PRIVATE => 1, self::LEVEL_FORTRESS => 2);
		$best = self::LEVEL_STANDARD;

		$consider = function($domain) use (&$best, $rank) {
			if (!$domain || !$domain->key) {
				return;
			}
			$level = $domain->security_level();
			if (($rank[$level] ?? 0) > $rank[$best]) {
				$best = $level;
			}
		};

		// Domains the user owns outright.
		$owned = new MultiInboundEmailDomain(array('owner_id' => $user_id, 'deleted' => false));
		$owned->load();
		foreach ($owned as $d) {
			$consider($d);
		}

		// Domains reached through a mailbox grant on one of their aliases.
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
		$alias_ids = InboundEmailMailboxGrant::alias_ids_for_user($user_id);
		$seen_domains = array();
		foreach ($alias_ids as $alias_id) {
			$alias = new InboundEmailAlias($alias_id, true);
			if (!$alias->key) {
				continue;
			}
			$domain_id = intval($alias->get('iea_ied_inbound_email_domain_id'));
			if ($domain_id <= 0 || isset($seen_domains[$domain_id])) {
				continue;
			}
			$seen_domains[$domain_id] = true;
			$consider(new InboundEmailDomain($domain_id, true));
		}

		return $best;
	}

	/**
	 * Lowercased names of every domain the user has a stake in — one they own
	 * (ied_owner_usr_user_id) or hold a mailbox grant on (a grant on one of its
	 * aliases). Used by the Population-2 precondition
	 * (specs/mailbox_security_levels.md § Password reset): making ANY of these the
	 * account login email would send reset links into an inbox the user could be
	 * locked out of — a grant-reached mailbox is exactly as circular as an owned
	 * one. Mirrors maxSecurityLevelForUser()'s traversal.
	 *
	 * @return string[] distinct lowercase domain names
	 */
	static function userHostedDomainNames(int $user_id): array {
		$names = array();

		$owned = new MultiInboundEmailDomain(array('owner_id' => $user_id, 'deleted' => false));
		$owned->load();
		foreach ($owned as $d) {
			$names[strtolower((string)$d->get('ied_domain'))] = true;
		}

		require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
		$alias_ids = InboundEmailMailboxGrant::alias_ids_for_user($user_id);
		$seen_domains = array();
		foreach ($alias_ids as $alias_id) {
			$alias = new InboundEmailAlias($alias_id, true);
			if (!$alias->key) {
				continue;
			}
			$domain_id = intval($alias->get('iea_ied_inbound_email_domain_id'));
			if ($domain_id <= 0 || isset($seen_domains[$domain_id])) {
				continue;
			}
			$seen_domains[$domain_id] = true;
			$domain = new InboundEmailDomain($domain_id, true);
			if ($domain->key) {
				$names[strtolower((string)$domain->get('ied_domain'))] = true;
			}
		}

		return array_keys($names);
	}

	/** True when this domain is an enforced protected sending identity. */
	function is_protected_identity() {
		$v = $this->get('ied_is_protected_identity');
		return ($v === true || $v === 't' || $v === 'true' || $v === '1' || $v === 1);
	}

	/**
	 * The forwarding-subdomain the SRS envelope leaves from: the per-domain
	 * value, else the bare domain (the behavior for a non-protected domain).
	 * Strictly per-domain — a shared server-wide value would rewrite one
	 * tenant's envelope onto another tenant's subdomain.
	 */
	function forwarding_subdomain() {
		$per_domain = trim((string)$this->get('ied_forwarding_subdomain'));
		return $per_domain !== '' ? $per_domain : (string)$this->get('ied_domain');
	}

	/**
	 * Protected domains owned by a user (their DKIM key seals to this user's
	 * vault). Used by the vault reseal callback so a key-rotation re-seals the
	 * sealed DKIM key alongside the message DEKs.
	 *
	 * @return InboundEmailDomain[]
	 */
	static function ProtectedForOwner(int $user_id) {
		$multi = new MultiInboundEmailDomain(array('owner_id' => $user_id, 'protected' => true, 'deleted' => false));
		$multi->load();
		$out = array();
		foreach ($multi as $d) {
			$out[] = $d;
		}
		return $out;
	}
}

class MultiInboundEmailDomain extends SystemMultiBase {
	protected static $model_class = 'InboundEmailDomain';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['domain'])) {
			$filters['ied_domain'] = [$this->options['domain'], PDO::PARAM_STR];
		}

		if (isset($this->options['owner_id'])) {
			$filters['ied_owner_usr_user_id'] = [$this->options['owner_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['protected'])) {
			$filters['ied_is_protected_identity'] = $this->options['protected'] ? "= true" : "= false";
		}

		if (isset($this->options['security_level'])) {
			$filters['ied_security_level'] = [$this->options['security_level'], PDO::PARAM_STR];
		}

		if (isset($this->options['enabled'])) {
			$filters['ied_is_enabled'] = $this->options['enabled'] ? "= true" : "= false";
		}

		if (isset($this->options['deleted'])) {
			$filters['ied_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

		return $this->_get_resultsv2('ied_inbound_email_domains', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
