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
 * ied_security_level is the per-domain protection level
 * (specs/mailbox_security_levels.md): 'standard' (server-managed plaintext),
 * 'private' (sealed at rest to the owner's server-custody vault) or 'fortress'
 * (sealed end-to-end to the owner's `mail` vault, whose secret only their
 * devices hold — specs/client_custody_mail.md). It is the switch that selects each mechanism's
 * plaintext-vs-sealed branch for every mailbox that inherits it — which is every
 * mailbox on a domain this deployment hosts. A mailbox pulled in over IMAP can
 * carry its own instead (iea_security_level), because gmail.com is not an
 * identity this deployment holds; see InboundEmailAlias::security_level(), which
 * is what a content-sealing decision asks. Domain identity — DKIM, the protected
 * sending identity, the DNS shape, the relay map — stays this class's answer.
 *
 * A Private domain may carry two add-ons (specs/protection_levels_platform.md
 * § Add-ons), each its own flag and inert below Private:
 *   ied_relay_seals_to_owner — Seal at the relay: the relay seals arriving mail
 *     to the owner's vault key instead of the transport key, so a hacked server
 *     cannot read mail that arrives while the owner is away.
 *   ied_send_lock_requested — Only send while I'm signed in, asked for. The
 *     finished state is ied_is_protected_identity, set by the verify-gated
 *     protect ceremony; the request is what tells "has not asked" from "asked,
 *     not finished yet".
 * Either one hardens its holders (userHasHardenedDomain): short unlock-window
 * caps.
 *
 * @version 1.13 - Fortress is settable: ied_level_set_time marks a level set
 *   through set_security_level(), which is what tells a Fortress domain from a
 *   legacy unconverted row; seals_content() covers Private and Fortress;
 *   is_fortress()
 * @version 1.12 - a write to an unconverted row converts it first (set()), and
 *   addon_labels() carries the catalog names and shows an enforcing lock at any
 *   level
 * @version 1.11 - two protection levels plus the relay and sending-lock add-ons;
 *   userHasHardenedDomain() and set_security_level() (refuses the reserved
 *   end-to-end level)
 * @version 1.10 - is_imap_source()/is_authoritative(), and the hosted-address
 *   guards exclude IMAP-source domains (specs/imap_source_domain_boundaries.md)
 * @version 1.9 - maxSecurityLevelForUser() counts live mailboxes only, like
 *   the owned-domain pass beside it
 * @version 1.8
 * @changelog 1.8 - maxSecurityLevelForUser() asks each granted MAILBOX for its own
 *   level, so a pulled-in Private mailbox on a Standard domain is not under-reported
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

	// Protection levels (specs/mailbox_security_levels.md). The single source of
	// truth for a domain's protection level; every mailbox/alias inherits.
	// Standard = server-managed plaintext; Private = sealed at rest.
	const LEVEL_STANDARD = 'standard';
	const LEVEL_PRIVATE  = 'private';
	// End-to-end mail (specs/client_custody_mail.md): stored content seals to
	// the owner's `mail` vault, whose secret only their devices hold, so the
	// server keeps nothing it can open.
	const LEVEL_FORTRESS = 'fortress';

	/** The levels a mail domain or mailbox can be set to. */
	const SETTABLE_LEVELS = array(self::LEVEL_STANDARD, self::LEVEL_PRIVATE, self::LEVEL_FORTRESS);

	// How far this domain's decrypted mail may travel to be read by an AI
	// model, as the most permissive endpoint trust class it may reach. Same
	// three names an endpoint uses, so the sealed-egress gate is a comparison
	// rather than a translation. See specs/joinery_ai_model_capability_resolution.md §9a.
	const CONSENT_LOCAL   = 'local';
	const CONSENT_TRUSTED = 'trusted';
	const CONSENT_CLOUD   = 'cloud';

	/** The three consent values, least to most permissive. */
	const CONSENTS = array(self::CONSENT_LOCAL, self::CONSENT_TRUSTED, self::CONSENT_CLOUD);

	public static $field_specifications = array(
		'ied_inbound_email_domain_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'ied_domain'            => array('type'=>'varchar(255)', 'required'=>true, 'is_nullable'=>false),
		'ied_is_enabled'        => array('type'=>'bool', 'default'=>true, 'is_nullable'=>false),
		'ied_catch_all_mode'    => array('type'=>'varchar(20)', 'default'=>'forward', 'is_nullable'=>false, 'allowed_values'=>array(self::CATCHALL_FORWARD, self::CATCHALL_STORE)),
		'ied_catch_all_address' => array('type'=>'varchar(500)'),
		'ied_reject_unmatched'  => array('type'=>'bool', 'default'=>true, 'is_nullable'=>false),
		'ied_is_imap_source'    => array('type'=>'bool', 'default'=>false, 'is_nullable'=>false),
		// Security posture (specs/mailbox_security_levels.md): the single switch
		// that selects each mechanism's plaintext-vs-sealed branch.
		// Persisted setup verdict (specs/mailbox_setup_verdicts.md). Written only
		// by the CheckDomainSetup task, read only by the Accounts listing's
		// badge. It is a NAVIGATION HINT — "go and look at this domain" — never
		// an answer about the world: the Setup tab re-runs everything live and
		// is the only thing that claims a domain is correct or broken.
		'ied_setup_status'       => array('type'=>'varchar(16)'),   // ok | attention | unknown; empty = never checked
		'ied_setup_checked_time' => array('type'=>'timestamp(6)'),
		'ied_security_level'    => array('type'=>'varchar(10)', 'is_nullable'=>false, 'default'=>'standard'), // 'standard' | 'private' | 'fortress'
		// When the level was last set through set_security_level(). A stored
		// 'fortress' WITH this set is a Fortress domain; one without it is a
		// legacy row from the old top level that mailbox migration
		// ied_003_private_with_addons has not converted yet (is_unconverted()).
		'ied_level_set_time'    => array('type'=>'timestamp(6)', 'is_nullable'=>true),
		// Add-ons on a Private domain (specs/protection_levels_platform.md § Add-ons).
		// Inert below Private: lowering leaves the flags stored, so raising again
		// restores them.
		'ied_relay_seals_to_owner' => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
		'ied_send_lock_requested'  => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
		// Consent for AI features to read this domain's mail
		// (specs/in_window_deferred_work.md § Turning it on has to be a
		// deliberate choice). Only consequential on a sealed level: at
		// 'standard' the server already reads the mail, so there is nothing to
		// consent to and the control is not shown. On 'private' this
		// is the difference between "the server cannot read my mail unless I am
		// here" and "the server reads my mail while I am here, and sends it to
		// the configured model host" — which must never become true silently.
		'ied_ai_processing_enabled' => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
		// Second, narrower consent: how far this domain's decrypted mail may
		// travel. ied_ai_processing_enabled says the AI may read it at all;
		// this says where it may be read. Separate because they are different
		// promises — the first keeps plaintext inside the box, the second lets
		// it out.
		//
		// Holds the MOST PERMISSIVE endpoint trust class the mail may reach:
		//   'local'   — never leaves hardware the operator controls (the default,
		//               and the answer for every domain that has not said otherwise)
		//   'trusted' — may reach a named vendor the operator has accepted terms
		//               with, but not a general cloud
		//   'cloud'   — may reach any configured endpoint
		// Same vocabulary as an endpoint's trust class, so the gate is a direct
		// comparison rather than a translation. A boolean could not express
		// "trusted yes, cloud no", which is a distinction an operator can
		// reasonably want. See specs/joinery_ai_model_capability_resolution.md §9a.
		'ied_ai_processing_consent' => array('type'=>'varchar(20)', 'is_nullable'=>false, 'default'=>'local'),
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

	/**
	 * Every write to a stored row that still holds the legacy top-level value
	 * (is_unconverted()) first makes the conversion the mailbox migration would
	 * make — Private, Seal at the relay on, the sending lock asked for only
	 * where it is already enforcing — and only then applies the caller's
	 * change. The row already behaves that way when read (addon_flag()), so the
	 * caller is editing what it sees; without this, a write clearing an add-on
	 * flag would be read straight back as on, because the legacy value keeps
	 * saying so, and nothing the caller turned off would stay off.
	 *
	 * Stored rows only: a fixture building an unconverted row in memory writes
	 * the legacy value on purpose. Hydration is not a write either — a row being
	 * filled from the database (load_from_data / load_from_object, which go
	 * through set()) must arrive exactly as stored.
	 */
	private $hydrating = false;

	function load_from_data($data, $fields) {
		$this->hydrating = true;
		try {
			parent::load_from_data($data, $fields);
		} finally {
			$this->hydrating = false;
		}
	}

	function load_from_object($other, $fields) {
		$this->hydrating = true;
		try {
			parent::load_from_object($other, $fields);
		} finally {
			$this->hydrating = false;
		}
	}

	function set($key, $value, $check_existance = TRUE) {
		if (!$this->hydrating && $this->key && $this->data !== NULL && $this->is_unconverted()) {
			parent::set('ied_security_level', self::LEVEL_PRIVATE);
			parent::set('ied_relay_seals_to_owner', true);
			parent::set('ied_send_lock_requested',
				$this->stored_flag('ied_send_lock_requested') || $this->stored_flag('ied_is_protected_identity'));
		}
		parent::set($key, $value, $check_existance);
	}

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
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_aliases_class.php'));
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
	 * True when this row anchors a connected external account (a Gmail/Outlook
	 * feed) rather than a domain this deployment runs mail for. Such a row is
	 * bookkeeping for the alias FK — never an identity this deployment owns
	 * (specs/imap_source_domain_boundaries.md § 2).
	 */
	function is_imap_source() {
		$v = $this->get('ied_is_imap_source');
		return ($v === true || $v === 't' || $v === 'true' || $v === '1' || $v === 1);
	}

	/**
	 * Is this deployment AUTHORITATIVE for the domain — enabled, live, and not
	 * an IMAP-source anchor? This is the question behind every identity-shaped
	 * decision: may it hold a Direct signing key, is an address on it "hosted
	 * here", is it local to the messenger, do we prescribe its DNS. A row can
	 * exist and still answer no (specs/imap_source_domain_boundaries.md § 2).
	 */
	function is_authoritative() {
		$enabled = $this->get('ied_is_enabled');
		$enabled = ($enabled === true || $enabled === 't' || $enabled === 'true' || $enabled === '1' || $enabled === 1);
		return $enabled && !$this->get('ied_delete_time') && !$this->is_imap_source();
	}

	/**
	 * True when an email address sits on a mailbox domain hosted on this platform
	 * (specs/mailbox_security_levels.md § Password reset, Population 2). A login OR
	 * recovery email on a hosted domain is circular — a reset link would land in an
	 * inbox that requires this very account to read. Shared by the register-time,
	 * account-email, and recovery-address guards.
	 *
	 * An IMAP-source domain is NOT hosted: Gmail delivers gmail.com, this
	 * deployment merely mirrors one mailbox on it, so nothing is circular —
	 * and answering yes would refuse every @gmail.com signup on the site
	 * (specs/imap_source_domain_boundaries.md § 3). A disabled hosted domain
	 * still answers yes: it can be re-enabled, and the circularity returns.
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
		$row = self::GetByDomain($domain);
		return $row !== false && !$row->is_imap_source();
	}

	/**
	 * This domain's security level (specs/mailbox_security_levels.md) — the
	 * single switch selecting each mechanism's plaintext-vs-sealed branch.
	 * Falls back to Standard for any unrecognized or empty stored value. A
	 * stored 'fortress' is Fortress when set_security_level() wrote it, and
	 * reads as Private when it is a legacy row mailbox migration
	 * ied_003_private_with_addons has not converted yet: that mail is sealed to
	 * the server-custody vault (is_unconverted()).
	 */
	function security_level() {
		$v = strtolower(trim((string)$this->get('ied_security_level')));
		if ($v === self::LEVEL_FORTRESS && $this->is_unconverted()) {
			return self::LEVEL_PRIVATE;
		}
		if (!in_array($v, self::SETTABLE_LEVELS, true)) {
			return self::LEVEL_STANDARD;
		}
		return $v;
	}

	/**
	 * Set the protection level, stamping ied_level_set_time. Only the settable
	 * levels are accepted. The stamp is written first: on a legacy unconverted
	 * row that write converts it (set()), and the level written after it is
	 * then read as what it says.
	 *
	 * Who may set Fortress, and what must hold first (a `mail` vault, one
	 * owner), is the level-change logic's call; this only records the answer.
	 *
	 * @throws InboundEmailDomainException on any other value
	 */
	function set_security_level(string $level) {
		$level = strtolower(trim($level));
		if (!in_array($level, self::SETTABLE_LEVELS, true)) {
			throw new InboundEmailDomainException('A mail domain can be Standard, Private or Fortress.');
		}
		$this->set('ied_level_set_time', gmdate('Y-m-d H:i:s'));
		$this->set('ied_security_level', $level);
	}

	/** A stored bool column read the way every flag on this model is read —
	 *  the stored value, whether or not the level puts it in force. */
	function stored_flag(string $column): bool {
		$v = $this->get($column);
		return ($v === true || $v === 't' || $v === 'true' || $v === '1' || $v === 1);
	}

	/**
	 * True for a row holding the legacy top-level value — 'fortress' with no
	 * ied_level_set_time, which only the old level model wrote and mailbox
	 * migration ied_003_private_with_addons has not converted yet. Until it
	 * runs, such a row behaves exactly as the migration will leave it: Private
	 * with Seal at the relay on, and the sending lock asked for only where it is
	 * already enforcing. A Fortress domain set through set_security_level()
	 * carries the stamp and is not this.
	 */
	function is_unconverted(): bool {
		return strtolower(trim((string)$this->get('ied_security_level'))) === self::LEVEL_FORTRESS
			&& trim((string)$this->get('ied_level_set_time')) === '';
	}

	/**
	 * An add-on's switch as the editor shows it: the stored flag, or on for an
	 * unconverted row (is_unconverted()). Whether the level puts it in force is
	 * a separate question — relay_seals_to_owner() / send_lock_requested().
	 */
	function addon_flag(string $column): bool {
		if ($this->stored_flag($column)) {
			return true;
		}
		if (!$this->is_unconverted()) {
			return false;
		}
		// An unconverted row reads as ied_003 will leave it.
		return $column === 'ied_send_lock_requested'
			? $this->stored_flag('ied_is_protected_identity')
			: true;
	}

	/** The Seal-at-the-relay add-on is on AND in force (the domain seals: Private or Fortress). */
	function relay_seals_to_owner() {
		return $this->seals_content() && $this->addon_flag('ied_relay_seals_to_owner');
	}

	/** The Only-send-while-signed-in add-on was asked for AND is in force (Private or Fortress). */
	function send_lock_requested() {
		return $this->seals_content() && $this->addon_flag('ied_send_lock_requested');
	}

	/**
	 * The sending lock was asked for but the protect ceremony has not finished:
	 * the one state the setup checklist holds open. A Private domain that never
	 * asked is finished as it is.
	 */
	function send_lock_outstanding() {
		return $this->send_lock_requested() && !$this->is_protected_identity();
	}

	/**
	 * The add-ons in force, as the short labels a level chip or badge shows
	 * beside the level (specs/protection_levels_platform.md § Add-ons rule 4):
	 * a member never has to open settings to learn what the domain promises.
	 * The labels are the add-on names from ProtectionLevelPicker's catalog, so a
	 * chip says exactly what the switch that set it says. A sending lock that was
	 * asked for but not finished says so.
	 *
	 * Agrees with is_hardened() by construction: every state that hardens the
	 * domain has a label here. An enforcing sending lock shows at any level —
	 * its signing key opens only in the window, whatever the level says — so
	 * a lock that is enforcing is never invisible.
	 *
	 * @return string[]
	 */
	function addon_labels(): array {
		$labels = array();
		if ($this->relay_seals_to_owner()) {
			$labels[] = ProtectionLevelPicker::addonCopy(ProtectionLevelPicker::ADDON_RELAY_SEALS_TO_OWNER)['label'];
		}
		$send = ProtectionLevelPicker::addonCopy(ProtectionLevelPicker::ADDON_SEND_LOCK)['label'];
		if ($this->is_protected_identity()) {
			$labels[] = $send;
		} elseif ($this->send_lock_outstanding()) {
			$labels[] = $send . ' (unfinished)';
		}
		return $labels;
	}

	/**
	 * True when this domain carries an add-on whose protection depends on the
	 * owner's unlock window being closed (specs/protection_levels_platform.md
	 * § Add-ons rule 5): relay sealing, or the sending lock asked for on a
	 * Private domain. Send protection that is actually enforcing counts at any
	 * level — its signing key opens only in the window, whatever the level says.
	 */
	function is_hardened() {
		return $this->relay_seals_to_owner() || $this->send_lock_requested()
			|| $this->is_protected_identity();
	}

	/**
	 * How far this domain's decrypted mail may travel, validated. Anything
	 * unrecognised reads as the strictest value: an unreadable consent is not a
	 * permission, and sealed mail never travels until someone says so.
	 */
	function ai_processing_consent() {
		$v = strtolower(trim((string)$this->get('ied_ai_processing_consent')));
		return in_array($v, self::CONSENTS, true) ? $v : self::CONSENT_LOCAL;
	}

	/** The stricter of two consent values. Used to fold a recipe's whole bound
	 *  set down to one answer — the strictest sealed address wins. */
	static function strictestConsent($a, $b) {
		$rank = array(self::CONSENT_LOCAL => 0, self::CONSENT_TRUSTED => 1, self::CONSENT_CLOUD => 2);
		return (($rank[$a] ?? 0) <= ($rank[$b] ?? 0)) ? $a : $b;
	}

	/** True when this domain seals stored content at rest (Private or Fortress). */
	function seals_content() {
		return in_array($this->security_level(), array(self::LEVEL_PRIVATE, self::LEVEL_FORTRESS), true);
	}

	/** True when this domain's mail seals end-to-end, to the owner's `mail` vault. */
	function is_fortress() {
		return $this->security_level() === self::LEVEL_FORTRESS;
	}

	/**
	 * The highest security level across everything the user has a stake in — a
	 * domain they own (ied_owner_usr_user_id) or a mailbox they hold a grant on.
	 * Drives the Private unlock-window cap
	 * (specs/mailbox_security_levels.md § The Unlock Window). Returns 'standard'
	 * when the user touches nothing protected. The short caps and the mandatory
	 * second factor ask userHasHardenedDomain() instead.
	 *
	 * A granted MAILBOX contributes its own level, not its domain's
	 * (specs/mailbox_connect_flow.md § D). Asking the domain here would
	 * under-report a user whose only protected mail is a pulled-in Private
	 * mailbox on an otherwise Standard domain — and under-reporting is quiet:
	 * they would silently get a Standard-length unlock window over sealed mail.
	 */
	static function maxSecurityLevelForUser(int $user_id): string {
		$rank = array(self::LEVEL_STANDARD => 0, self::LEVEL_PRIVATE => 1, self::LEVEL_FORTRESS => 2);
		$best = self::LEVEL_STANDARD;

		$consider = function($level) use (&$best, $rank) {
			if (($rank[$level] ?? 0) > $rank[$best]) {
				$best = $level;
			}
		};

		// Domains the user owns outright.
		$owned = new MultiInboundEmailDomain(array('owner_id' => $user_id, 'deleted' => false));
		$owned->load();
		foreach ($owned as $d) {
			if ($d && $d->key) {
				$consider($d->security_level());
			}
		}

		// Every mailbox reached through a grant, each answering for itself.
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grants_class.php'));
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_aliases_class.php'));
		foreach (InboundEmailMailboxGrant::alias_ids_for_user($user_id) as $alias_id) {
			$alias = new InboundEmailAlias($alias_id, true);
			// Live mailboxes only, like the owned-domain pass above: grant rows
			// survive a soft delete, and a deleted Private mailbox must not keep
			// holding its ex-reader to Private unlock caps over mail they can no
			// longer reach.
			if ($alias->key && !$alias->get('iea_delete_time')) {
				$consider($alias->security_level());
			}
		}

		return $best;
	}

	/**
	 * True when anything the user has a stake in carries a hardening add-on — a
	 * domain they own, or the domain of a live mailbox they hold a grant on, that
	 * is Private with relay sealing or the sending lock on (is_hardened()).
	 *
	 * Drives the short unlock-window caps (specs/protection_levels_platform.md
	 * § Add-ons rule 5): these add-ons only help while the window is closed, so a
	 * long window would quietly undo them.
	 *
	 * A grant counts through the mailbox's DOMAIN, not the mailbox's own level:
	 * the add-ons are properties of a domain this deployment hosts.
	 */
	static function userHasHardenedDomain(int $user_id): bool {
		if ($user_id <= 0) {
			return false;
		}
		$owned = new MultiInboundEmailDomain(array('owner_id' => $user_id, 'deleted' => false));
		foreach ($owned as $d) {
			if ($d && $d->key && $d->is_hardened()) {
				return true;
			}
		}

		require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grants_class.php'));
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_aliases_class.php'));
		$seen = array();
		foreach (InboundEmailMailboxGrant::alias_ids_for_user($user_id) as $alias_id) {
			$alias = new InboundEmailAlias($alias_id, true);
			// Live mailboxes only: grant rows survive a soft delete.
			if (!$alias->key || $alias->get('iea_delete_time')) {
				continue;
			}
			$domain_id = intval($alias->get('iea_ied_inbound_email_domain_id'));
			if ($domain_id <= 0 || isset($seen[$domain_id])) {
				continue;
			}
			$seen[$domain_id] = true;
			$domain = new InboundEmailDomain($domain_id, true);
			if ($domain->key && !$domain->get('ied_delete_time') && $domain->is_hardened()) {
				return true;
			}
		}
		return false;
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
			// An IMAP-source anchor (gmail.com) is not a hosted inbox — a login
			// email there is reachable without this account, so not circular.
			if ($d->is_imap_source()) {
				continue;
			}
			$names[strtolower((string)$d->get('ied_domain'))] = true;
		}

		require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grants_class.php'));
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_aliases_class.php'));
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
			if ($domain->key && !$domain->is_imap_source()) {
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


		return $this->_get_resultsv2('ied_inbound_email_domains', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
