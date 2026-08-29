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
 * for OAuth hosts, the OAuth provider key). A row's 'auth' is the DEFAULT
 * sign-in — the easiest one that works for that host — and a row carrying an
 * oauth_provider supports OAuth besides (authMethodsFor() is the derivation).
 * Gmail defaults to an app password precisely because that path needs no app
 * registration; Microsoft is 'oauth2' only because outlook.com retired basic
 * auth. Each account stores which method IT signed in with (iia_auth_method,
 * stamped by the credential setters) — the catalog says what is possible, the
 * account says what happened. Adding a host is a one-line edit here. The editor
 * reads PRESETS to fill the form; ImapIngestor reads the account's own stored
 * connection columns (the preset only seeds them).
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
 * @version 1.4 - saving a feed drops the remembered Setup verdict for its
 *   mailbox, so a reconnect is believed immediately
 * @version 1.3 - the catalog's 'auth' is the default (easiest) method, not the
 *   only one: Gmail defaults to app password with OAuth still supported;
 *   iia_auth_method is per-account truth, stamped by setPassword/setOAuthToken
 *   and validated (never overwritten) by prepare()
 * @version 1.2
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

	// How far back into the source mailbox a feed reaches. Future-only is the
	// default: the cursor seeds to the mailbox head, so a ten-year archive and an
	// empty mailbox cost the same to connect. Days reaches back a fixed window —
	// enough context to work with, without the whole archive. Full imports
	// everything, oldest-first.
	const SCOPE_FUTURE = 'future';
	const SCOPE_DAYS   = 'days';
	const SCOPE_FULL   = 'full';

	const IMPORT_DAYS_DEFAULT = 30;
	// Ten years. A window this wide is "everything" for any real mailbox, and the
	// ceiling keeps a typo (300000) from turning into a pointless deep seek.
	const IMPORT_DAYS_MAX = 3650;

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
	 * @var array<string,array{label:string,host:?string,port:int,encryption:string,auth:string,oauth_provider:?string,smtp_host:?string,smtp_port:int,smtp_encryption:?string,smtp_files_sent:bool}>
	 */
	const PRESETS = array(
		'imap_gmail'     => array('label'=>'Gmail / Google Workspace', 'host'=>'imap.gmail.com',        'port'=>993, 'encryption'=>'ssl', 'auth'=>'password', 'oauth_provider'=>'google',    'app_password_url'=>'https://myaccount.google.com/apppasswords',              'smtp_host'=>'smtp.gmail.com',     'smtp_port'=>587, 'smtp_encryption'=>'tls', 'smtp_files_sent'=>true),
		'imap_microsoft' => array('label'=>'Microsoft 365 / Outlook',  'host'=>'outlook.office365.com', 'port'=>993, 'encryption'=>'ssl', 'auth'=>'oauth2',   'oauth_provider'=>'microsoft', 'app_password_url'=>null,                                                     'smtp_host'=>'smtp.office365.com', 'smtp_port'=>587, 'smtp_encryption'=>'tls', 'smtp_files_sent'=>true),
		'imap_yahoo'     => array('label'=>'Yahoo / AOL',              'host'=>'imap.mail.yahoo.com',   'port'=>993, 'encryption'=>'ssl', 'auth'=>'password', 'oauth_provider'=>null,        'app_password_url'=>'https://login.yahoo.com/myaccount/security/app-password', 'smtp_host'=>'smtp.mail.yahoo.com','smtp_port'=>465, 'smtp_encryption'=>'ssl', 'smtp_files_sent'=>true),
		'imap_icloud'    => array('label'=>'iCloud',                   'host'=>'imap.mail.me.com',      'port'=>993, 'encryption'=>'ssl', 'auth'=>'password', 'oauth_provider'=>null,        'app_password_url'=>'https://account.apple.com/account/manage',                'smtp_host'=>'smtp.mail.me.com',   'smtp_port'=>587, 'smtp_encryption'=>'tls', 'smtp_files_sent'=>true),
		'imap_fastmail'  => array('label'=>'Fastmail',                 'host'=>'imap.fastmail.com',     'port'=>993, 'encryption'=>'ssl', 'auth'=>'password', 'oauth_provider'=>null,        'app_password_url'=>'https://app.fastmail.com/settings/security',              'smtp_host'=>'smtp.fastmail.com',  'smtp_port'=>465, 'smtp_encryption'=>'ssl', 'smtp_files_sent'=>true),
		'imap_generic'   => array('label'=>'Generic IMAP',             'host'=>null,                    'port'=>993, 'encryption'=>'ssl', 'auth'=>'password', 'oauth_provider'=>null,        'app_password_url'=>null,                                                     'smtp_host'=>null,                 'smtp_port'=>587, 'smtp_encryption'=>'tls', 'smtp_files_sent'=>false),
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
		// How far back the feed reaches, and the window size when it is 'days'.
		'iia_import_scope'              => array('type'=>'varchar(10)', 'default'=>'future', 'is_nullable'=>false, 'allowed_values'=>array(self::SCOPE_FUTURE, self::SCOPE_DAYS, self::SCOPE_FULL)),
		'iia_import_days'               => array('type'=>'int4', 'default'=>'30'),
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

		// Auth method: one the catalog supports for this host, defaulting to the
		// preset's own (easiest) method. A stored value survives — the credential
		// setters keep it truthful — so a host offering both app password and
		// OAuth keeps whichever sign-in this account actually made.
		$method = (string)$this->get('iia_auth_method');
		if (!in_array($method, self::authMethodsFor($provider), true)) {
			$this->set('iia_auth_method', self::PRESETS[$provider]['auth']);
		}

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

		// Import scope: a known value, with a sane window when it is day-bounded.
		$scope = $this->get('iia_import_scope') ?: self::SCOPE_FUTURE;
		if (!in_array($scope, array(self::SCOPE_FUTURE, self::SCOPE_DAYS, self::SCOPE_FULL), true)) {
			$scope = self::SCOPE_FUTURE;
		}
		$this->set('iia_import_scope', $scope);
		$days = intval($this->get('iia_import_days'));
		if ($days < 1) { $days = self::IMPORT_DAYS_DEFAULT; }
		$this->set('iia_import_days', min($days, self::IMPORT_DAYS_MAX));

		$this->set('iia_update_time', gmdate('Y-m-d H:i:s'));
	}

	// --- Preset helpers -----------------------------------------------------

	/** The preset row for this account's provider_key (or the generic row). */
	function getPreset(): array {
		$key = $this->get('iia_provider_key') ?: 'imap_generic';
		return self::PRESETS[$key] ?? self::PRESETS['imap_generic'];
	}

	/**
	 * The auth methods the catalog supports for a preset: its default first,
	 * plus oauth2 whenever the host has an OAuth provider. The order matters —
	 * index 0 is the method a new connection should offer.
	 */
	static function authMethodsFor(string $preset_key): array {
		$preset = self::PRESETS[$preset_key] ?? self::PRESETS['imap_generic'];
		$methods = array($preset['auth']);
		if (!empty($preset['oauth_provider']) && !in_array(self::AUTH_OAUTH2, $methods, true)) {
			$methods[] = self::AUTH_OAUTH2;
		}
		return $methods;
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

	// --- Import scope helpers ----------------------------------------------

	/** How far back this feed reaches: future | days | full. */
	function importScope(): string {
		$scope = $this->get('iia_import_scope') ?: self::SCOPE_FUTURE;
		return in_array($scope, array(self::SCOPE_FUTURE, self::SCOPE_DAYS, self::SCOPE_FULL), true)
			? $scope : self::SCOPE_FUTURE;
	}

	/** The day window when the scope is day-bounded, else 0. */
	function importDays(): int {
		if ($this->importScope() !== self::SCOPE_DAYS) { return 0; }
		$days = intval($this->get('iia_import_days'));
		if ($days < 1) { $days = self::IMPORT_DAYS_DEFAULT; }
		return min($days, self::IMPORT_DAYS_MAX);
	}

	/** The oldest message time this feed wants, as a UTC timestamp string (or null). */
	function importCutoffUtc(): ?string {
		$days = $this->importDays();
		return $days > 0
			? LibraryFunctions::time_shift(gmdate('Y-m-d H:i:s'), '-' . $days . ' days', 'Y-m-d H:i:s')
			: null;
	}

	/**
	 * Whether moving from $wasScope/$wasDays to this account's current setting
	 * changes where the feed should start reading.
	 */
	function importScopeChanged(string $wasScope, int $wasDays): bool {
		if ($this->importScope() !== $wasScope) { return true; }
		return $this->importScope() === self::SCOPE_DAYS && $this->importDays() !== $wasDays;
	}

	/**
	 * Whether a scope change from $wasScope/$wasDays requires the folder cursors
	 * to rewind so the next poll re-seeds. A change that still reads backward
	 * (full, or a day window) re-seeds: widening backfills further, and a changed
	 * day window moves to its new boundary — dedup keeps re-walked mail from
	 * storing twice. Switching to future-only does NOT rewind: the cursor is
	 * already where "from now on" continues from, and re-seeding it to the
	 * mailbox head would permanently skip whatever arrived at the source since
	 * the last poll.
	 */
	function importScopeRequiresRewind(string $wasScope, int $wasDays): bool {
		return $this->importScopeChanged($wasScope, $wasDays)
			&& $this->importScope() !== self::SCOPE_FUTURE;
	}

	/** One-line description of the scope, for confirmations and status lines. */
	function describeImportScope(): string {
		switch ($this->importScope()) {
			case self::SCOPE_FULL: return 'the full mailbox history';
			case self::SCOPE_DAYS: return 'the last ' . $this->importDays() . ' days of mail';
			default:               return 'only mail arriving from now on';
		}
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
		if ($oauthProviderKey === 'google') {
			// Google's one mail scope covers both directions — which is exactly why
			// it has to be named here. Treating Google as needing no scope at all
			// was true of a grant that had it and false of one that did not, and
			// the second case reported itself as authorized right up until SMTP
			// answered 535 BadCredentials into the error log.
			return array('https://mail.google.com/');
		}
		return array();
	}

	/**
	 * The scopes a grant must carry before this feed can READ mail at all.
	 *
	 * Signing in and being allowed into the mailbox are two different
	 * permissions, and a provider will happily grant the first without the
	 * second: Google's consent screen lists mail access as its own tick box, so
	 * an operator can come back holding a perfectly valid token that identifies
	 * them and authorizes nothing. IMAP then fails to log in, minutes later,
	 * under a scheduled poll, saying only "Authentication failed" — which reads
	 * as a broken or expired sign-in and sends the operator round the same loop.
	 *
	 * Keyed by IMAP PRESET (the key learnAddress takes), so a caller holding a
	 * feed's provider never has to translate between the two key spaces.
	 *
	 * @return string[] every scope that must be present; empty for a host with
	 *                  no OAuth (a password feed authorizes by password).
	 */
	static function requiredMailScopes(?string $preset_key): array {
		$oauth_key = self::PRESETS[$preset_key]['oauth_provider'] ?? null;
		if ($oauth_key === 'microsoft') {
			return array('https://outlook.office365.com/IMAP.AccessAsUser.All');
		}
		if ($oauth_key === 'google') {
			// Google's IMAP scope, which also covers SMTP send (requiredSendScopes).
			return array('https://mail.google.com/');
		}
		return array();
	}

	/**
	 * Which required mail scopes a REPORTED grant does not carry.
	 *
	 * A provider that reports no scope at all has told us nothing, and silence
	 * is not evidence of refusal — that case returns empty, so a grant is only
	 * ever turned away on what the provider actually said.
	 *
	 * @return string[] the missing scopes; empty means "nothing known to be wrong".
	 */
	static function missingMailScopes(?string $preset_key, string $reported_scope): array {
		$reported = trim($reported_scope);
		if ($reported === '') {
			return array();
		}
		$granted = preg_split('/\s+/', $reported);
		$missing = array();
		foreach (self::requiredMailScopes($preset_key) as $scope) {
			if (!in_array($scope, $granted, true)) {
				$missing[] = $scope;
			}
		}
		return $missing;
	}

	/**
	 * True when the grant this feed holds is known NOT to authorize reading the
	 * mailbox — connected, but not let in. Distinct from "never connected" and
	 * from "the token went bad", and the only one of the three an operator can
	 * fix by ticking a box on the way through the consent screen.
	 */
	function mailAccessRefused(): bool {
		if (!$this->isOAuth() || !$this->hasOAuthToken()) {
			return false;
		}
		return (bool)self::missingMailScopes((string)$this->get('iia_provider_key'),
			(string)$this->get('iia_oauth_scopes'));
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
		if (empty($granted)) {
			// The provider reported no scopes, so there is nothing to grade
			// against. Silence is not refusal: blocking a send on an absence would
			// strand every feed whose grant predates scope recording.
			return true;
		}
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
		$this->set('iia_password_enc', (new SecretBox())->seal('iia_inbound_imap_accounts.iia_password_enc', $plaintext));
		// The credential defines the method: a stored password IS a password
		// sign-in, whatever the host's default.
		$this->set('iia_auth_method', self::AUTH_PASSWORD);
	}

	function getPassword(): ?string {
		$blob = $this->get('iia_password_enc');
		if (!$blob) {
			return null;
		}
		// A dead password (moved database / rotated key) reads as null — the
		// account degrades to needs-reauth rather than throwing.
		return (new SecretBox())->open($blob)['value'];
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
		$this->set('iia_oauth_access_token_enc', $box->seal('iia_inbound_imap_accounts.iia_oauth_access_token_enc', $token->getAccessToken()));
		$refresh = $token->getRefreshToken();
		if ($refresh !== null && $refresh !== '') {
			$this->set('iia_oauth_refresh_token_enc', $box->seal('iia_inbound_imap_accounts.iia_oauth_refresh_token_enc', $refresh));
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
		// The credential defines the method: a granted token IS an OAuth
		// sign-in, whatever the host's default.
		$this->set('iia_auth_method', self::AUTH_OAUTH2);
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
		$access = $box->open($accessBlob)['value'];
		if ($access === null) {
			// Dead access token (moved database / rotated key): no usable token, so
			// the account degrades to needs-reauth rather than throwing.
			return null;
		}
		$refreshBlob = $this->get('iia_oauth_refresh_token_enc');
		return new OAuth2Token(
			$access,
			$refreshBlob ? $box->open($refreshBlob)['value'] : null,
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

	/**
	 * Saving a feed retires the operator's remembered Setup verdict for its
	 * mailbox.
	 *
	 * The reader's "this mailbox needs attention" banner does not re-run the
	 * checks on every click — it reads the last answer out of the operator's
	 * session (mailbox_setup_memory.php). For an IMAP-pull mailbox, that answer
	 * is largely a reading of THIS row: connected, needing reconnection, feed
	 * switched off. So every write here is a write to what the banner should
	 * say, and leaving the memory in place is how an operator who has just
	 * reconnected a mailbox gets told, for the rest of the window, that the
	 * authorization they renewed has expired.
	 *
	 * Forgetting rather than recomputing is deliberate: the correct answer costs
	 * DNS lookups and host probes, and a poll running under cron has no operator
	 * to answer for. Dropping the memory makes the next ask — from someone
	 * actually looking at the mailbox — run live.
	 */
	function save($debug = false) {
		$result = parent::save($debug);
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/mailbox_setup_memory.php'));
		mailbox_setup_status_forget(intval($this->get('iia_iea_inbound_email_alias_id')));
		return $result;
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
