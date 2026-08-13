<?php
/**
 * InboundImapAccount - A polled IMAP mailbox the platform reads inbound mail from.
 *
 * IMAP is the "pull" inbound transport: instead of mail being pushed in (Postfix
 * MX->pipe, Mailgun webhook), the platform connects to an existing mailbox on a
 * schedule (PollImapAccounts) and ingests new messages. Each account binds to an
 * inbound alias (the mailbox it populates), so fetched mail appears in the
 * Mailbox Reader like any other stored mail and honors the same grant model.
 *
 * Accounts are additive and independent: any number can run alongside whatever
 * the system's single push transport (mailbox_provider) is. Adding one
 * never changes that transport.
 *
 * Per-host connection details are DATA, not behavior — the PRESETS catalog below
 * is the single inventory of every supported host (host/port/encryption/auth and,
 * for OAuth hosts, the OAuth provider key). Gmail and Microsoft are not special:
 * they are simply the rows whose auth is 'oauth2'. Adding a host is a one-line
 * edit here. The editor reads PRESETS to fill the form; ImapIngestor reads the
 * account's own stored connection columns (the preset only seeds them).
 *
 * Secrets (IMAP password, OAuth access/refresh tokens) are encrypted at rest with
 * the core SecretBox helper. The *_enc columns are never read directly by callers
 * and never logged or echoed — use the get/set accessors, which encrypt/decrypt on
 * use only.
 *
 * The same connected account drives outbound: the PRESETS smtp_* coordinates and
 * the stored grant power SMTP send (SmtpConfig::fromConnectedAccount), and the
 * outbound helpers below report SMTP send-capability and granted-scope state.
 *
 * @version 1.1
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/SecretBox.php'));
require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Token.php'));

class InboundImapAccountException extends SystemBaseException {}

class InboundImapAccount extends SystemBase {
	public static $prefix = 'iia';
	public static $tablename = 'iia_inbound_imap_accounts';
	public static $pkey_column = 'iia_inbound_imap_account_id';

	// Auth methods
	const AUTH_PASSWORD = 'password';
	const AUTH_OAUTH2   = 'oauth2';

	// Sync modes (specs/two_way_imap_sync.md §4). Off by default so existing feeds
	// are unaffected. Read-only follows the source one-way; Two-way reconciles both.
	const SYNC_OFF  = 'off';
	const SYNC_PULL = 'pull';   // Read-only: source → Joinery
	const SYNC_BOTH = 'both';   // Two-way: bidirectional

	// Encryption modes (maps onto the IMAP connection's secure transport)
	const ENC_SSL = 'ssl';
	const ENC_TLS = 'tls';
	const ENC_NONE = 'none';

	/**
	 * The preset catalog: every supported host as pure data. The account editor
	 * reads this to populate the provider dropdown and pre-fill host/port/
	 * encryption when a provider is picked; imap_generic leaves the host blank for
	 * the user to supply. 'oauth_provider' is the OAuth2ProviderRegistry key for
	 * oauth2 hosts (null for password hosts). Add a host by adding a row here.
	 *
	 * The smtp_* coordinates make one row drive BOTH directions: the same account
	 * that feeds inbound IMAP also sends outbound through these SMTP settings
	 * (SmtpConfig::fromConnectedAccount). 'smtp_encryption' is 'tls' (STARTTLS,
	 * port 587) or 'ssl' (implicit TLS, port 465). 'smtp_files_sent' records
	 * whether the provider's SMTP auto-saves the sent copy to the Sent folder —
	 * true for the hosted mailbox providers, false for generic self-hosted
	 * submission (where two-way sync APPENDs the copy instead). Generic has no
	 * fixed SMTP host: connected-account outbound is unavailable for it, and a
	 * relay-class provider is used to send for those mailboxes.
	 *
	 * 'smtp_rewrites_message_id' records whether the provider's SMTP rewrites the
	 * Message-ID on send (Gmail does) — true means a Joinery-composed message can
	 * never be matched to its filed Sent copy by Message-ID, so no local outbound
	 * row is stored and the message surfaces on the next Sent ingest (§9 dedup).
	 *
	 * @var array<string,array{label:string,host:?string,port:int,encryption:string,auth:string,oauth_provider:?string,smtp_host:?string,smtp_port:int,smtp_encryption:?string,smtp_files_sent:bool,smtp_rewrites_message_id:bool}>
	 */
	const PRESETS = array(
		'imap_gmail'     => array('label'=>'Gmail / Google Workspace', 'host'=>'imap.gmail.com',        'port'=>993, 'encryption'=>'ssl', 'auth'=>'oauth2',   'oauth_provider'=>'google',    'smtp_host'=>'smtp.gmail.com',     'smtp_port'=>587, 'smtp_encryption'=>'tls', 'smtp_files_sent'=>true,  'smtp_rewrites_message_id'=>true),
		'imap_microsoft' => array('label'=>'Microsoft 365 / Outlook',  'host'=>'outlook.office365.com', 'port'=>993, 'encryption'=>'ssl', 'auth'=>'oauth2',   'oauth_provider'=>'microsoft', 'smtp_host'=>'smtp.office365.com', 'smtp_port'=>587, 'smtp_encryption'=>'tls', 'smtp_files_sent'=>true,  'smtp_rewrites_message_id'=>false),
		'imap_yahoo'     => array('label'=>'Yahoo / AOL',              'host'=>'imap.mail.yahoo.com',   'port'=>993, 'encryption'=>'ssl', 'auth'=>'password', 'oauth_provider'=>null,        'smtp_host'=>'smtp.mail.yahoo.com','smtp_port'=>465, 'smtp_encryption'=>'ssl', 'smtp_files_sent'=>true,  'smtp_rewrites_message_id'=>false),
		'imap_icloud'    => array('label'=>'iCloud',                   'host'=>'imap.mail.me.com',      'port'=>993, 'encryption'=>'ssl', 'auth'=>'password', 'oauth_provider'=>null,        'smtp_host'=>'smtp.mail.me.com',   'smtp_port'=>587, 'smtp_encryption'=>'tls', 'smtp_files_sent'=>true,  'smtp_rewrites_message_id'=>false),
		'imap_fastmail'  => array('label'=>'Fastmail',                 'host'=>'imap.fastmail.com',     'port'=>993, 'encryption'=>'ssl', 'auth'=>'password', 'oauth_provider'=>null,        'smtp_host'=>'smtp.fastmail.com',  'smtp_port'=>465, 'smtp_encryption'=>'ssl', 'smtp_files_sent'=>true,  'smtp_rewrites_message_id'=>false),
		'imap_generic'   => array('label'=>'Generic IMAP',             'host'=>null,                    'port'=>993, 'encryption'=>'ssl', 'auth'=>'password', 'oauth_provider'=>null,        'smtp_host'=>null,                 'smtp_port'=>587, 'smtp_encryption'=>'tls', 'smtp_files_sent'=>false, 'smtp_rewrites_message_id'=>false),
	);

	/**
	 * The canonical email-address domain for the fixed-domain providers, so an
	 * IMAP-source domain (e.g. gmail.com) and its provider preset stay in sync.
	 * Generic/Workspace hosts have no fixed domain and are absent here.
	 *
	 * @var array<string,string>
	 */
	const PROVIDER_EMAIL_DOMAINS = array(
		'imap_gmail'     => 'gmail.com',
		'imap_microsoft' => 'outlook.com',
		'imap_yahoo'     => 'yahoo.com',
		'imap_icloud'    => 'icloud.com',
		'imap_fastmail'  => 'fastmail.com',
	);

	/** Provider preset key for an email-address domain, or null if not a fixed-domain provider. */
	public static function providerForEmailDomain(?string $domain): ?string {
		$domain = strtolower(trim((string)$domain));
		$map = array_flip(self::PROVIDER_EMAIL_DOMAINS);
		return $map[$domain] ?? null;
	}

	protected static $foreign_key_actions = array(
		// permanent_delete, not cascade: a feed owns its folder rows, which in
		// turn own label memberships.
		'iia_iea_inbound_email_alias_id' => array('action' => 'permanent_delete'),
	);

	public static $field_specifications = array(
		'iia_inbound_imap_account_id'   => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'iia_label'                     => array('type'=>'varchar(255)'),
		'iia_provider_key'              => array('type'=>'varchar(40)', 'default'=>'imap_generic', 'is_nullable'=>false),
		'iia_iea_inbound_email_alias_id'=> array('type'=>'int4'),
		'iia_imap_host'                 => array('type'=>'varchar(255)'),
		'iia_imap_port'                 => array('type'=>'int4', 'default'=>'993'),
		'iia_imap_encryption'           => array('type'=>'varchar(10)', 'default'=>'ssl', 'allowed_values'=>array(self::ENC_SSL, self::ENC_TLS, self::ENC_NONE)),
		'iia_imap_folder'               => array('type'=>'varchar(255)', 'default'=>'INBOX'),
		'iia_username'                  => array('type'=>'varchar(255)'),
		'iia_auth_method'               => array('type'=>'varchar(10)', 'default'=>'password', 'is_nullable'=>false),
		'iia_password_enc'              => array('type'=>'text'),
		'iia_oauth_access_token_enc'    => array('type'=>'text'),
		'iia_oauth_refresh_token_enc'   => array('type'=>'text'),
		'iia_oauth_token_expires'       => array('type'=>'timestamp(6)'),
		'iia_oauth_scopes'              => array('type'=>'text'),
		'iia_poll_interval_seconds'     => array('type'=>'int4', 'default'=>'300'),
		// Legacy single-folder cursor — superseded by the per-folder iif_ cursor
		// (specs/two_way_imap_sync.md §5). Seeded into the INBOX iif_ row on first
		// poll of an existing feed; no longer read after that.
		'iia_uidvalidity'               => array('type'=>'int8'),
		'iia_last_seen_uid'             => array('type'=>'int8'),
		// Two-way sync (specs/two_way_imap_sync.md §4, §5). Off by default.
		'iia_sync_mode'                 => array('type'=>'varchar(10)', 'default'=>'off', 'is_nullable'=>false, 'allowed_values'=>array(self::SYNC_OFF, self::SYNC_PULL, self::SYNC_BOTH)),
		'iia_sync_deletes'              => array('type'=>'bool', 'default'=>false, 'is_nullable'=>false),
		'iia_show_compose'              => array('type'=>'bool', 'default'=>false, 'is_nullable'=>false),
		// CONDSTORE is the sync gate (incremental flag/membership pull via
		// CHANGEDSINCE); QRESYNC is the faster removal-detection path (VANISHED) when
		// the server also advertises it. Gmail has CONDSTORE but not QRESYNC, so
		// CONDSTORE — not QRESYNC — is what enables sync.
		'iia_supports_condstore'        => array('type'=>'bool', 'default'=>false, 'is_nullable'=>false),
		'iia_supports_qresync'          => array('type'=>'bool', 'default'=>false, 'is_nullable'=>false),
		'iia_folders_exclusive'         => array('type'=>'bool', 'default'=>true, 'is_nullable'=>false),
		'iia_is_enabled'                => array('type'=>'bool', 'default'=>true, 'is_nullable'=>false),
		'iia_last_poll_time'            => array('type'=>'timestamp(6)'),
		'iia_last_status'               => array('type'=>'varchar(500)'),
		'iia_needs_reauth'              => array('type'=>'bool', 'default'=>false, 'is_nullable'=>false),
		'iia_import_history'            => array('type'=>'bool', 'default'=>false, 'is_nullable'=>false),
		'iia_create_time'               => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'iia_update_time'               => array('type'=>'timestamp(6)'),
		'iia_delete_time'               => array('type'=>'timestamp(6)'),
	);

	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in ' . static::$tablename);
		}
	}

	function prepare() {
		// Provider must be a known preset.
		$provider = $this->get('iia_provider_key') ?: 'imap_generic';
		if (!isset(self::PRESETS[$provider])) {
			throw new InboundImapAccountException('Unknown IMAP provider: ' . htmlspecialchars($provider));
		}
		$this->set('iia_provider_key', $provider);

		// Auth method is derived from the preset — the catalog is authoritative.
		$this->set('iia_auth_method', self::PRESETS[$provider]['auth']);

		$enc = $this->get('iia_imap_encryption') ?: self::ENC_SSL;
		if (!in_array($enc, array(self::ENC_SSL, self::ENC_TLS, self::ENC_NONE), true)) {
			throw new InboundImapAccountException('Invalid encryption: ' . htmlspecialchars($enc));
		}
		$this->set('iia_imap_encryption', $enc);

		if (!$this->get('iia_imap_folder')) {
			$this->set('iia_imap_folder', 'INBOX');
		}
		if (!intval($this->get('iia_imap_port'))) {
			$this->set('iia_imap_port', 993);
		}
		if (!intval($this->get('iia_poll_interval_seconds'))) {
			$this->set('iia_poll_interval_seconds', 300);
		}

		// Sync mode: a known value, and never sync without CONDSTORE (incremental
		// flag/membership pull). Removals are detected via QRESYNC VANISHED when the
		// server has it, else a UID-set diff — so CONDSTORE alone (e.g. Gmail) is
		// enough. A feed whose capability is unknown/false can only be Off until the
		// next poll/Test detects CONDSTORE.
		$mode = $this->get('iia_sync_mode') ?: self::SYNC_OFF;
		if (!in_array($mode, array(self::SYNC_OFF, self::SYNC_PULL, self::SYNC_BOTH), true)) {
			$mode = self::SYNC_OFF;
		}
		if ($mode !== self::SYNC_OFF && !$this->get('iia_supports_condstore')) {
			$mode = self::SYNC_OFF;
		}
		$this->set('iia_sync_mode', $mode);

		$this->set('iia_update_time', gmdate('Y-m-d H:i:s'));
	}

	// --- Preset helpers -----------------------------------------------------

	/** The preset row for this account's provider_key (or the generic row). */
	function getPreset(): array {
		$key = $this->get('iia_provider_key') ?: 'imap_generic';
		return self::PRESETS[$key] ?? self::PRESETS['imap_generic'];
	}

	function isOAuth(): bool {
		return $this->get('iia_auth_method') === self::AUTH_OAUTH2;
	}

	/** OAuth2ProviderRegistry key for this account (e.g. 'google'), or null. */
	function getOAuthProviderKey(): ?string {
		return $this->getPreset()['oauth_provider'] ?? null;
	}

	// --- Outbound (SMTP send) helpers --------------------------------------
	// The same connected account that feeds inbound IMAP can authenticate the
	// outbound SMTP sender (SmtpConfig::fromConnectedAccount). These read the
	// preset's SMTP coordinates and the granted-scope state.

	/** Whether this account has SMTP coordinates to send through (false for generic). */
	function canSendViaSmtp(): bool {
		return !empty($this->getPreset()['smtp_host']);
	}

	/** Whether the provider's SMTP auto-files the sent copy in Sent (PRESETS capability). */
	function smtpFilesSent(): bool {
		return !empty($this->getPreset()['smtp_files_sent']);
	}

	/** Whether the provider's SMTP rewrites the Message-ID on send (Gmail) — drives §9 dedup. */
	function smtpRewritesMessageId(): bool {
		return !empty($this->getPreset()['smtp_rewrites_message_id']);
	}

	// --- Sync helpers (specs/two_way_imap_sync.md §4) -----------------------

	/** Current sync mode: off | pull | both. */
	function syncMode(): string {
		$mode = $this->get('iia_sync_mode') ?: self::SYNC_OFF;
		return in_array($mode, array(self::SYNC_OFF, self::SYNC_PULL, self::SYNC_BOTH), true) ? $mode : self::SYNC_OFF;
	}

	/** Sync is on in either direction (pull or both). */
	function syncEnabled(): bool {
		return $this->syncMode() !== self::SYNC_OFF;
	}

	/** Two-way: Joinery writes changes back to the source. */
	function isTwoWay(): bool {
		return $this->syncMode() === self::SYNC_BOTH;
	}

	/** Delete propagation is gated independently of mode. */
	function syncDeletes(): bool {
		return (bool)$this->get('iia_sync_deletes');
	}

	/** Reader compose/Sent affordances are surfaced for this feed. */
	function showCompose(): bool {
		return (bool)$this->get('iia_show_compose');
	}

	/** Server advertised CONDSTORE (cached). The sync gate — required for any sync. */
	function supportsCondstore(): bool {
		return (bool)$this->get('iia_supports_condstore');
	}

	/** Server advertised QRESYNC (cached). The fast removal-detection path (VANISHED). */
	function supportsQresync(): bool {
		return (bool)$this->get('iia_supports_qresync');
	}

	/** Membership cardinality: true = a message lives in exactly one folder (classic IMAP). */
	function foldersExclusive(): bool {
		return (bool)$this->get('iia_folders_exclusive');
	}

	/**
	 * The OAuth scopes an OAuth account needs in order to SEND via SMTP XOAUTH2.
	 * Google's IMAP scope (https://mail.google.com/) already authorizes SMTP, so
	 * no extra scope is required. Microsoft needs SMTP.Send added to the IMAP
	 * scopes (a re-consent). Password providers need no scopes.
	 *
	 * @return string[] the scope strings that must all be present to send.
	 */
	static function requiredSendScopes(?string $oauthProviderKey): array {
		if ($oauthProviderKey === 'microsoft') {
			return array('https://outlook.office365.com/SMTP.Send');
		}
		// Google: the IMAP scope already covers SMTP send.
		return array();
	}

	/** The scopes the stored grant was issued with (space-delimited → array). */
	function grantedScopes(): array {
		$raw = trim((string)$this->get('iia_oauth_scopes'));
		if ($raw === '') {
			return array();
		}
		return preg_split('/\s+/', $raw);
	}

	/**
	 * True when this account is authorized to send outbound right now: password
	 * accounts always are (the app password covers SMTP); OAuth accounts must
	 * hold a token and every required send scope. Drives the proactive
	 * "Reconnect to allow sending" prompt (§4.1) before any send is attempted.
	 */
	function isSendAuthorized(): bool {
		if (!$this->canSendViaSmtp()) {
			return false;
		}
		if (!$this->isOAuth()) {
			return $this->hasPassword();
		}
		if (!$this->hasOAuthToken() || $this->needsReauth()) {
			return false;
		}
		$required = self::requiredSendScopes($this->getOAuthProviderKey());
		if (empty($required)) {
			return true;
		}
		$granted = $this->grantedScopes();
		foreach ($required as $scope) {
			if (!in_array($scope, $granted, true)) {
				return false;
			}
		}
		return true;
	}

	// --- Encrypted secret accessors ----------------------------------------
	// Plaintext is encrypted on set and decrypted on get; the *_enc columns are
	// never exposed. Empty input clears the column.

	function setPassword(?string $plaintext): void {
		if ($plaintext === null || $plaintext === '') {
			$this->set('iia_password_enc', null);
			return;
		}
		$this->set('iia_password_enc', (new SecretBox())->encrypt($plaintext));
	}

	function getPassword(): ?string {
		$blob = $this->get('iia_password_enc');
		if (!$blob) {
			return null;
		}
		return (new SecretBox())->decrypt($blob);
	}

	function hasPassword(): bool {
		return (bool)$this->get('iia_password_enc');
	}

	/**
	 * Persist a granted OAuth token set on this account (access + refresh +
	 * expiry), encrypting the tokens at rest. A refresh response often omits the
	 * refresh token — OAuth2Token::withRefreshedAccess already preserves the prior
	 * one, so the token handed here always carries the refresh token to store.
	 */
	function setOAuthToken(OAuth2Token $token): void {
		$box = new SecretBox();
		$this->set('iia_oauth_access_token_enc', $box->encrypt($token->getAccessToken()));
		$refresh = $token->getRefreshToken();
		if ($refresh !== null && $refresh !== '') {
			$this->set('iia_oauth_refresh_token_enc', $box->encrypt($refresh));
		}
		$this->set('iia_oauth_token_expires', $token->getExpiresAt());
		// Persist granted scopes so outbound can detect whether SMTP send was
		// authorized (e.g. Microsoft SMTP.Send) and prompt a re-consent if not.
		// Only overwrite when the response actually carries a scope — a refresh
		// response often omits it, and we must not erase the grant-time scopes.
		$scope = $token->getScope();
		if ($scope !== '') {
			$this->set('iia_oauth_scopes', $scope);
		}
		// A freshly granted/refreshed token clears any prior re-auth flag.
		$this->set('iia_needs_reauth', false);
	}

	/** True when the stored token is known-broken (refresh/auth failed) and the
	 *  account must be reconnected. Distinct from "never connected" (no token). */
	function needsReauth(): bool {
		return $this->isOAuth() && $this->hasOAuthToken() && (bool)$this->get('iia_needs_reauth');
	}

	/** Flag this OAuth account as needing reconnection and persist it. */
	function markNeedsReauth(): void {
		$this->set('iia_needs_reauth', true);
		$this->save();
	}

	/**
	 * Reconstruct the stored OAuth2Token, or null if no token is on record.
	 * The scope/token_type are not persisted (not needed for XOAUTH2 use); the
	 * access token, refresh token, and expiry are what drive ensureFresh().
	 */
	function getOAuthToken(): ?OAuth2Token {
		$accessBlob = $this->get('iia_oauth_access_token_enc');
		if (!$accessBlob) {
			return null;
		}
		$box = new SecretBox();
		$refreshBlob = $this->get('iia_oauth_refresh_token_enc');
		return new OAuth2Token(
			$box->decrypt($accessBlob),
			$refreshBlob ? $box->decrypt($refreshBlob) : null,
			$this->get('iia_oauth_token_expires') ?: null
		);
	}

	function hasOAuthToken(): bool {
		return (bool)$this->get('iia_oauth_access_token_enc');
	}

	/** Is this account credentialed enough to attempt a poll? */
	function isConnectable(): bool {
		if ($this->isOAuth()) {
			return $this->hasOAuthToken();
		}
		return $this->hasPassword() && (bool)$this->get('iia_imap_host');
	}

	/**
	 * Record the outcome of a poll/connection attempt. Status is truncated and
	 * stored verbatim — callers MUST pass an already credential-free string
	 * (never an IMAP password or token).
	 */
	function recordStatus(string $status): void {
		$this->set('iia_last_poll_time', gmdate('Y-m-d H:i:s'));
		$this->set('iia_last_status', substr($status, 0, 500));
		$this->save();
	}
}

class MultiInboundImapAccount extends SystemMultiBase {
	protected static $model_class = 'InboundImapAccount';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['enabled'])) {
			$filters['iia_is_enabled'] = $this->options['enabled'] ? '= true' : '= false';
		}

		if (isset($this->options['provider_key'])) {
			$filters['iia_provider_key'] = array($this->options['provider_key'], PDO::PARAM_STR);
		}

		if (isset($this->options['alias_id'])) {
			$filters['iia_iea_inbound_email_alias_id'] = array($this->options['alias_id'], PDO::PARAM_INT);
		}

		// "due": accounts whose poll interval has elapsed since the last poll.
		// A never-polled account (last_poll_time IS NULL) is always due. Uses the
		// split-parenthesis OR convention so the NULL case groups with the
		// interval test without widening any other clause.
		if (!empty($this->options['due'])) {
			$filters['(iia_last_poll_time'] =
				"IS NULL OR iia_last_poll_time + (iia_poll_interval_seconds * INTERVAL '1 second') <= now())";
		}


		return $this->_get_resultsv2('iia_inbound_imap_accounts', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
