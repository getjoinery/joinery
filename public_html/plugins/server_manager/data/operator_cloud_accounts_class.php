<?php
/**
 * OperatorCloudAccount - one cloud account of ours that this management node
 * holds a credential for (the cloud_accounts_list spec).
 *
 * Ours, never a customer's: a customer's connected account is a
 * CustomerCloudAccount, which belongs to its user and goes with them. An
 * account here belongs to the management node; oca_added_by_usr_user_id only
 * records who added it, and removing that login removes nothing.
 *
 * Two methods put an account here:
 *   key      an API token pasted on the Hosting tab of Provisioning Setup.
 *            It lasts until it is replaced or removed.
 *   connect  a Linode sign-in (OAuth). The grant lasts two hours and cannot be
 *            refreshed, so the entry is right for a one-off server in somebody's
 *            account and wrong for one this node keeps managing. Past its
 *            expiry it is shown expired and is offered nowhere.
 *
 * What the provider says about the account (its name, company, and the
 * scopes the credential holds) is read when it is added and whenever it is
 * checked again (check()). The company name decides the test flag: the
 * company TestCloudCleanup::COMPANY marks a disposable test account.
 *
 * One account is the default (DEFAULT_SETTING): where a server goes when
 * nobody chose (a hosted order). It may not be a Connect entry, which would
 * expire under an order, nor a test account on a node that holds any other.
 *
 * Every server in one of our accounts names its account on its provision
 * record (cvp_oca_operator_cloud_account_id), and everything done to it later
 * is done with that account's credential (HeldCloudAccounts).
 *
 * @version 1.4 - domains:read_only: the handover note reads the account's DNS Manager (InstanceTransferFinish), so it is declared
 * @version 1.3 - The driver is its provider's (ComputeDriverRegistry): driver_for() takes the provider, add_key() records
 *               the one chosen, scopes are checked only for a provider whose tokens carry them, and a provider whose API
 *               names no account keeps the name typed when it was added (specs/site_copy_anything_to_anything.md D2)
 * @version 1.2 - An API key's own expiry, read at each check where the provider says (CloudKeyExpiry), into
 *               oca_token_expires: a key past it is not usable and says to replace it, and key_expires_within()
 *               warns before. expired() stays a Connect grant's (B77).
 * @version 1.1 - events:read_only: the activity log read before an encrypted data root is opened (Tier 2)
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/SecretBox.php'));

class OperatorCloudAccountException extends SystemBaseException {}

class OperatorCloudAccount extends SystemBase {
	public static $prefix = 'oca';
	public static $tablename = 'oca_operator_cloud_accounts';
	public static $access_class = 'config';
	public static $pkey_column = 'oca_operator_cloud_account_id';

	const METHOD_KEY = 'key';
	const METHOD_CONNECT = 'connect';

	const STATUS_ACTIVE = 'active';
	/** The provider refused the credential at its last check. */
	const STATUS_REFUSED = 'refused';

	/** How long before an API key expires that the Hosting tab and the node's incident warn (B77). */
	const KEY_EXPIRY_WARNING_SECONDS = 7 * 86400;

	/** The managed setting naming the default account's id. */
	const DEFAULT_SETTING = 'server_manager_default_cloud_account_id';

	/** Where the credential is sealed (plugin.json sealed_secrets). */
	const LOCATOR = 'oca_operator_cloud_accounts.oca_credential';

	/**
	 * Every scope a credential of ours is used with, and what for. An account
	 * short of one is still held (the rest works); the Hosting tab names what
	 * will be refused.
	 */
	const SCOPES = array(
		'linodes:read_write' => 'creating, powering and rebuilding servers',
		'account:read_write' => 'the alert when the account\'s transfer allowance is nearly spent, and handing a server to its customer\'s own account',
		'firewall:read_only' => 'the check before a handover, and a site copy\'s switch-over by swapping addresses',
		'volumes:read_only'  => 'the check before a handover',
		'ips:read_write'     => 'a site copy\'s switch-over by swapping two servers\' addresses',
		'events:read_only'   => 'reading a server\'s activity log before its encrypted data root is opened, to refuse a boot after a rescue boot or a root-password reset (Tier 2)',
		'domains:read_only'  => 'listing a domain\'s records from this account\'s DNS Manager in the note a customer gets when their server is handed to their own account',
	);
	/** The scope without which no server can be created on the account. */
	const CREATE_SCOPE = 'linodes:read_write';

	/** What a Connect with Linode asks for: everything above. */
	const CONNECT_SCOPES = array('linodes:read_write', 'account:read_write', 'firewall:read_only',
		'volumes:read_only', 'ips:read_write', 'events:read_only', 'domains:read_only');

	protected static $foreign_key_actions = array(
		// A record of who added it, never an owner: the account stays.
		'oca_added_by_usr_user_id' => array('action' => 'null', 'source_table' => 'usr_users'),
	);

	public static $test_fixture = array(
		'values'       => array('oca_method' => 'key'),
		'update_field' => 'oca_account_name',
	);

	public static $field_specifications = array(
		'oca_operator_cloud_account_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'oca_provider'             => array('type'=>'varchar(32)', 'is_nullable'=>false, 'default'=>'linode'),
		'oca_method'               => array('type'=>'varchar(10)', 'is_nullable'=>false, 'default'=>'key',
			'allowed_values'=>array('key', 'connect')),
		// The token, sealed under LOCATOR. Emptied when the entry is removed.
		'oca_credential'           => array('type'=>'text'),
		// A Connect grant's end; NULL for an API key, which has none we know of.
		'oca_token_expires'        => array('type'=>'timestamp(6)'),
		// What the provider says, read at check().
		'oca_account_name'         => array('type'=>'varchar(255)'),
		'oca_company'              => array('type'=>'varchar(255)'),
		// The scopes the provider reported, space-separated; NULL until checked.
		'oca_scopes'               => array('type'=>'text'),
		'oca_is_test'              => array('type'=>'bool', 'is_nullable'=>false, 'default'=>'false'),
		'oca_status'               => array('type'=>'varchar(20)', 'is_nullable'=>false, 'default'=>'active',
			'allowed_values'=>array('active', 'refused')),
		// Why the last check failed, when it did.
		'oca_check_error'          => array('type'=>'varchar(500)'),
		'oca_checked_time'         => array('type'=>'timestamp(6)'),
		'oca_added_by_usr_user_id' => array('type'=>'int8'),
		'oca_create_time'          => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'oca_update_time'          => array('type'=>'timestamp(6)'),
		'oca_delete_time'          => array('type'=>'timestamp(6)'),
	);

	/** Tests stand in a driver factory here: function (string $token, string $provider) => driver. */
	public static $driver_factory = null;

	function save($debug = false) {
		$method = (string)($this->get('oca_method') ?: self::METHOD_KEY);
		if (!in_array($method, array(self::METHOD_KEY, self::METHOD_CONNECT), true)) {
			throw new OperatorCloudAccountException("Unknown method '{$method}'.");
		}
		$this->set('oca_update_time', gmdate('Y-m-d H:i:s'));
		return parent::save($debug);
	}

	// ── The credential ───────────────────────────────────────────────────────

	/**
	 * Seal a token onto this entry, with a Connect grant's end when there is
	 * one. Does not save. An API key's expiry is read at its next check.
	 */
	public function store_credential(string $token, ?string $expires = null): void {
		$this->set('oca_credential', (new SecretBox())->seal(self::LOCATOR, trim($token)));
		$this->set('oca_token_expires', $expires !== null && trim($expires) !== '' ? $expires : null);
	}

	/** The token, unsealed, or '' when there is none or it cannot be opened. Never shown, logged or put in a job. */
	public function token(): string {
		$sealed = trim((string)$this->get('oca_credential'));
		if ($sealed === '') {
			return '';
		}
		$opened = (new SecretBox())->open($sealed);
		if (($opened['state'] ?? '') !== 'ok') {
			error_log('OperatorCloudAccount: the credential of cloud account #' . (int)$this->key . ' cannot be unsealed.');
			return '';
		}
		return trim((string)$opened['value']);
	}

	/** A Connect grant past its two hours: it did its one job, and is offered nowhere. */
	public function expired(): bool {
		return (string)$this->get('oca_method') === self::METHOD_CONNECT && $this->past_expiry(0);
	}

	/** An API key past the expiry its provider reported: a fault, to be replaced. */
	public function key_expired(): bool {
		return (string)$this->get('oca_method') !== self::METHOD_CONNECT && $this->past_expiry(0);
	}

	/** An API key that stops working within $seconds (and has not yet): its expiry, else null. */
	public function key_expires_within(int $seconds): ?string {
		if ((string)$this->get('oca_method') === self::METHOD_CONNECT || $this->key_expired() || !$this->past_expiry($seconds)) {
			return null;
		}
		return trim((string)$this->get('oca_token_expires'));
	}

	/** The recorded expiry is $seconds or less away (or past). False when none is recorded. */
	private function past_expiry(int $seconds): bool {
		$expires = trim((string)$this->get('oca_token_expires'));
		$at = $expires === '' ? false : strtotime($expires . ' UTC');
		return $at !== false && $at <= time() + $seconds;
	}

	public function removed(): bool {
		return trim((string)$this->get('oca_delete_time')) !== '';
	}

	/** Can anything be done with it now: held, not refused, not expired, a credential that opens. */
	public function usable(): bool {
		return $this->key && !$this->removed() && (string)$this->get('oca_status') === self::STATUS_ACTIVE
			&& !$this->expired() && !$this->key_expired() && $this->token() !== '';
	}

	/** Why it cannot be used, in a sentence, or '' when it can. */
	public function unusable_reason(): string {
		if (!$this->key) {
			return 'The cloud account is not on record.';
		}
		if ($this->removed()) {
			return 'The cloud account ' . $this->label() . ' was removed from this management node.';
		}
		if ($this->expired()) {
			return 'The Linode sign-in for ' . $this->label() . ' has expired (a sign-in lasts two hours). '
				. 'Reconnect it on the Hosting tab of Provisioning Setup.';
		}
		if ($this->key_expired()) {
			return 'The API key for ' . $this->label() . ' expired on ' . $this->get_local('oca_token_expires', 'M j, Y g:i a T')
				. '. Replace it on the Hosting tab of Provisioning Setup.';
		}
		if ((string)$this->get('oca_status') === self::STATUS_REFUSED) {
			return 'The provider refused the credential for ' . $this->label() . ' at its last check'
				. (trim((string)$this->get('oca_check_error')) !== '' ? ' (' . $this->get('oca_check_error') . ')' : '')
				. '. Replace it on the Hosting tab of Provisioning Setup.';
		}
		if ($this->token() === '') {
			return 'The credential for ' . $this->label() . ' cannot be read. Replace it on the Hosting tab of Provisioning Setup.';
		}
		return '';
	}

	/** A driver acting on this account. */
	public function driver() {
		return self::driver_for($this->token(), $this->provider());
	}

	/** The provider's driver, acting with this credential. @throws CloudComputeException when no driver acts for it */
	public static function driver_for(string $token, string $provider) {
		if (self::$driver_factory !== null) {
			return (self::$driver_factory)($token, $provider);
		}
		return ComputeDriverRegistry::driver($provider, $token);
	}

	/** The provider's key: 'linode', 'hetzner'. */
	public function provider(): string {
		return (string)($this->get('oca_provider') ?: 'linode');
	}

	// ── What it is called ────────────────────────────────────────────────────

	/** The account as people know it: the provider's name for it, and test when it is the test account. */
	public function label(): string {
		$name = trim((string)$this->get('oca_account_name'));
		return ComputeDriverRegistry::label($this->provider()) . ' — ' . ($name !== '' ? $name : 'account #' . (int)$this->key)
			. ($this->get('oca_is_test') ? ' (test)' : '');
	}

	/** The key forms and the account watch know it by. */
	public function choice_key(): string {
		return 'oca:' . (int)$this->key;
	}

	// ── What the provider says ───────────────────────────────────────────────

	/** The scopes the provider reported, or null when it has not been checked. */
	public function granted_scopes(): ?array {
		$raw = $this->get('oca_scopes');
		if ($raw === null) {
			return null;
		}
		return array_values(array_filter(preg_split('/[\s,]+/', trim((string)$raw)), 'strlen'));
	}

	/**
	 * The scopes of $wanted (scope => what for; SCOPES when null) the
	 * credential lacks, or null when it has not been checked.
	 */
	public function missing_scopes(?array $wanted = null): ?array {
		$wanted = $wanted ?? self::SCOPES;
		// A provider whose tokens carry no scopes lacks none, checked or not.
		if (!ComputeDriverRegistry::supports($this->provider(), 'CloudTokenScopes')) {
			return array();
		}
		$granted = $this->granted_scopes();
		if ($granted === null) {
			return null;
		}
		$missing = ComputeDriverRegistry::missing_scopes($this->provider(), $granted, array_keys($wanted));
		return array_intersect_key($wanted, array_flip($missing));
	}

	/** Can a server be created on it, as far as its recorded scopes say. */
	public function can_create(): bool {
		$missing = $this->missing_scopes(array(self::CREATE_SCOPE => ''));
		return $missing !== null && !$missing;
	}

	/**
	 * Ask the provider for the account's name, company and the credential's
	 * scopes, and record them, with the test flag from the company. Saves.
	 * Returns '' when the provider answered, else why not; a 401 marks the
	 * entry refused, any other failure leaves what was known.
	 */
	public function check($driver = null): string {
		$token = $this->token();
		if ($token === '') {
			$this->set('oca_check_error', 'no credential');
			$this->save();
			return 'There is no credential to check.';
		}
		$driver = $driver ?? self::driver_for($token, $this->provider());
		try {
			// A provider whose tokens carry no scopes holds every one; one
			// whose API names no account keeps the name it was added under.
			$scopes = $driver instanceof CloudTokenScopes ? $driver->tokenScopes() : array('*');
			$name = $driver instanceof CloudAccountIdentity ? $driver->accountName() : (string)$this->get('oca_account_name');
			if (!($driver instanceof CloudTokenScopes) && !($driver instanceof CloudAccountIdentity)) {
				$driver->getTransfer();   // the credential answers at all
			}
		} catch (CloudComputeException $e) {
			if ((int)$e->getCode() === 401) {
				$this->set('oca_status', self::STATUS_REFUSED);
			}
			$this->set('oca_check_error', mb_substr($e->getMessage(), 0, 500));
			$this->set('oca_checked_time', gmdate('Y-m-d H:i:s'));
			$this->save();
			return 'The provider could not be asked about the account: ' . $e->getMessage();
		}
		// The company needs account:read_only; without it the account is not the test account.
		$company = '';
		if ($driver instanceof CloudAccountCleanup) {
			try {
				$company = $driver->accountCompany();
			} catch (CloudComputeException $e) {
				$company = '';
			}
		}
		// An API key's own expiry, where the provider says (B77). A failed read
		// leaves what was known.
		if ((string)$this->get('oca_method') !== self::METHOD_CONNECT && $driver instanceof CloudKeyExpiry) {
			try {
				$this->set('oca_token_expires', $driver->keyExpiry());
			} catch (CloudComputeException $e) {
			}
		}
		$this->set('oca_scopes', implode(' ', $scopes));
		$this->set('oca_account_name', mb_substr($name, 0, 255));
		$this->set('oca_company', mb_substr($company, 0, 255));
		$this->set('oca_is_test', trim($company) === TestCloudCleanup::COMPANY);
		$this->set('oca_status', self::STATUS_ACTIVE);
		$this->set('oca_check_error', null);
		$this->set('oca_checked_time', gmdate('Y-m-d H:i:s'));
		$this->save();
		return '';
	}

	// ── Adding and removing ──────────────────────────────────────────────────

	/**
	 * Add an account by its API key: checked with the provider first, and
	 * refused (nothing saved) when the provider will not answer to it. A
	 * provider whose API names no account (a Hetzner project) is held under
	 * the name typed here, which it then needs.
	 */
	public static function add_key(string $token, int $user_id, $driver = null, string $provider = 'linode', string $name = ''): OperatorCloudAccount {
		$token = trim($token);
		$name = trim($name);
		if ($token === '') {
			throw new OperatorCloudAccountException('Paste the account\'s API token.');
		}
		if (!ComputeDriverRegistry::known($provider)) {
			throw new OperatorCloudAccountException('Choose the account\'s provider.');
		}
		$driver = $driver ?? self::driver_for($token, $provider);
		if (!($driver instanceof CloudAccountIdentity) && $name === '') {
			throw new OperatorCloudAccountException(ComputeDriverRegistry::label($provider) . '\'s API does not say which '
				. 'account a token is for: name it, as you know it there.');
		}
		try {
			$driver instanceof CloudTokenScopes ? $driver->tokenScopes() : $driver->getTransfer();
		} catch (CloudComputeException $e) {
			throw new OperatorCloudAccountException('The provider did not accept that token ('
				. $e->getMessage() . '). Nothing was added.');
		}
		$account = new OperatorCloudAccount(NULL);
		$account->set('oca_provider', $provider);
		if ($name !== '') {
			$account->set('oca_account_name', mb_substr($name, 0, 255));
		}
		$account->set('oca_method', self::METHOD_KEY);
		$account->set('oca_added_by_usr_user_id', $user_id ?: null);
		$account->store_credential($token);
		$account->save();
		$account->load();
		$account->check($driver);
		return $account;
	}

	/**
	 * Why $token cannot replace this entry's key, or '' when it can: it must
	 * reach the same account, since every server naming this entry follows
	 * it. Where the provider names the account, the names must match; where it
	 * does not (a Hetzner project), the token must see every server on record
	 * in this account.
	 */
	public function replacement_refusal(string $token, $driver = null): string {
		try {
			$driver = $driver ?? self::driver_for($token, $this->provider());
			if ($driver instanceof CloudAccountIdentity) {
				$name = trim($driver->accountName());
				$was = trim((string)$this->get('oca_account_name'));
				return ($was !== '' && $name !== $was) ? 'That token is for ' . $name . ', not ' . $was
					. '. A replacement key must be for the same account; add a different account as its own entry.' : '';
			}
			if (!($driver instanceof CloudMachineTransfer)) {
				return 'This provider cannot say which account a token reaches, so its key cannot be replaced here; add the account '
					. 'again as its own entry.';
			}
			$seen = array();
			foreach ($driver->listInstances() as $i) {
				$seen[(string)$i['id']] = true;
			}
		} catch (CloudComputeException $e) {
			return 'The provider did not accept that token (' . $e->getMessage() . '). Nothing was changed.';
		}
		foreach (new MultiCustomerCloudProvision(array('deleted' => false)) as $p) {
			if ((int)$p->get('cvp_oca_operator_cloud_account_id') === (int)$this->key && trim((string)$p->get('cvp_instance_id')) !== ''
					&& !isset($seen[(string)$p->get('cvp_instance_id')])) {
				return 'That token does not reach server ' . $p->get('cvp_instance_id') . ', which is in this account. A replacement key '
					. 'must be for the same account; add a different account as its own entry.';
			}
		}
		return '';
	}

	/**
	 * Take this entry off the node: its credential is erased and the row kept,
	 * so a server that names it can still say which account it was in. The
	 * default cannot be removed while it is the default.
	 */
	public function remove(): void {
		if ($this->is_default()) {
			throw new OperatorCloudAccountException('This is the default account. Make another account the default first.');
		}
		$this->set('oca_credential', null);
		$this->set('oca_delete_time', gmdate('Y-m-d H:i:s'));
		$this->save();
	}

	// ── The default ──────────────────────────────────────────────────────────

	public static function default_id(): int {
		return (int)trim((string)Globalvars::get_instance()->get_setting(self::DEFAULT_SETTING, false, true));
	}

	/** The default account, or null when none is set or it was removed. */
	public static function default_account(): ?OperatorCloudAccount {
		$id = self::default_id();
		if ($id <= 0) {
			return null;
		}
		$account = new OperatorCloudAccount($id, TRUE);
		return ($account->key && !$account->removed()) ? $account : null;
	}

	public function is_default(): bool {
		return $this->key && (int)$this->key === self::default_id();
	}

	/** Why this entry cannot be the default, or '' when it can. */
	public function default_refusal(): string {
		if (!$this->key || $this->removed()) {
			return 'That account is not held here.';
		}
		if ((string)$this->get('oca_method') === self::METHOD_CONNECT) {
			return 'A Linode sign-in lasts two hours, so it cannot be the default: a hosted order placed after it '
				. 'expires would have nowhere to go. Add the account by API key to make it the default.';
		}
		if ($this->get('oca_is_test')) {
			foreach (self::held() as $other) {
				if ((int)$other->key !== (int)$this->key && !$other->get('oca_is_test')) {
					return 'This is the disposable test account, and this management node holds ' . $other->label()
						. ' as well. A hosted order would go to an account whose servers are deleted after a day; '
						. 'a test account is the default only on a node that holds nothing else.';
				}
			}
		}
		return '';
	}

	public function make_default(): void {
		$why = $this->default_refusal();
		if ($why !== '') {
			throw new OperatorCloudAccountException($why);
		}
		Setting::put(self::DEFAULT_SETTING, (string)(int)$this->key);
	}

	// ── The one token this node held before the list ─────────────────────────

	/** The settings that held it, retired once it is an entry. */
	const RETIRED_SETTINGS = array(
		'server_manager_operator_cloud_token',
		'server_manager_operator_cloud_token_scopes',
		'server_manager_operator_cloud_account',
		'server_manager_operator_cloud_company',
	);

	/**
	 * Move the single operator token into the list (migration sm_019): an
	 * entry by key with the name, company and scopes the settings recorded,
	 * made the default; every server in hosting mode operator (or handed on
	 * from it) that names no account is pointed at it, and so is the
	 * transfer watch's record of it. Then the four settings go. Safe to run
	 * again: with the settings gone there is nothing left to move.
	 *
	 * A token that cannot be unsealed is left where it is, settings and all,
	 * for the sealed-secret health page to flag: nothing usable can be made
	 * of it, and erasing it would hide that it was there.
	 *
	 * @return string what was done, for the log
	 */
	public static function migrate_operator_token($dblink): string {
		$read = $dblink->prepare("SELECT stg_value FROM stg_settings WHERE stg_name = ?");
		$setting = function (string $name) use ($read): string {
			$read->execute(array($name));
			return trim((string)$read->fetchColumn());
		};
		$sealed = $setting('server_manager_operator_cloud_token');
		$moved = '';
		if ($sealed !== '') {
			$opened = (new SecretBox())->open($sealed);
			// A plaintext value (a site that had no secret_box_key when it was saved) is the token too; it is sealed now.
			if (!in_array($opened['state'] ?? '', array(SecretBox::OPEN_OK, SecretBox::OPEN_PLAINTEXT), true)
					|| trim((string)$opened['value']) === '') {
				return 'the operator cloud token cannot be unsealed; left in place for the sealed-secret health page, nothing moved';
			}
			$token = trim((string)$opened['value']);
			$company = $setting('server_manager_operator_cloud_company');
			$scopes = null;
			$record = json_decode($setting('server_manager_operator_cloud_token_scopes'), true);
			if (is_array($record) && is_array($record['scopes'] ?? null)
					&& hash_equals(substr(hash('sha256', $token), 0, 16), (string)($record['token'] ?? ''))) {
				$scopes = implode(' ', array_map('strval', $record['scopes']));
			}
			$account = new OperatorCloudAccount(NULL);
			$account->set('oca_provider', 'linode');
			$account->set('oca_method', self::METHOD_KEY);
			$account->store_credential($token);
			$account->set('oca_account_name', mb_substr($setting('server_manager_operator_cloud_account'), 0, 255) ?: null);
			$account->set('oca_company', mb_substr($company, 0, 255) ?: null);
			$account->set('oca_scopes', $scopes);
			$account->set('oca_is_test', $company === TestCloudCleanup::COMPANY);
			$account->set('oca_status', self::STATUS_ACTIVE);
			$account->save();
			$account->load();
			$id = (int)$account->key;
			// The default, unless one already names an account held here.
			if (self::default_account() === null) {
				Setting::put(self::DEFAULT_SETTING, (string)$id);
			}
			$q = $dblink->prepare("UPDATE cvp_customer_cloud_provisions SET cvp_oca_operator_cloud_account_id = ?
				WHERE cvp_hosting_mode IN ('operator', 'transferred') AND cvp_oca_operator_cloud_account_id IS NULL");
			$q->execute(array($id));
			$servers = $q->rowCount();
			$watched = 0;
			if ($dblink->query("SELECT to_regclass('mtr_machine_transfers') IS NOT NULL")->fetchColumn()) {
				$q = $dblink->prepare("UPDATE mtr_machine_transfers SET mtr_account = ? WHERE mtr_account = 'operator'");
				$q->execute(array('oca:' . $id));
				$watched = $q->rowCount();
			}
			$moved = 'the operator cloud token is cloud account #' . $id . ($company === TestCloudCleanup::COMPANY ? ' (test)' : '')
				. ', the default; ' . $servers . ' server record(s) and ' . $watched . ' transfer record(s) point at it';
		}
		$drop = $dblink->prepare("DELETE FROM stg_settings WHERE stg_name = ANY (string_to_array(?, ','))");
		$drop->execute(array(implode(',', self::RETIRED_SETTINGS)));
		return ($moved !== '' ? $moved : 'no operator cloud token was set') . '; ' . $drop->rowCount() . ' retired setting row(s) removed';
	}

	// ── The list ─────────────────────────────────────────────────────────────

	/** Every account held (not removed), the default first, then oldest first. */
	public static function held(): array {
		$default = self::default_id();
		$out = array();
		foreach (new MultiOperatorCloudAccount(array('deleted' => false), array('oca_operator_cloud_account_id' => 'ASC')) as $a) {
			$out[] = $a;
		}
		usort($out, function ($a, $b) use ($default) {
			$da = (int)$a->key === $default ? 0 : 1;
			$db = (int)$b->key === $default ? 0 : 1;
			return $da <=> $db ?: (int)$a->key <=> (int)$b->key;
		});
		return $out;
	}
}

class MultiOperatorCloudAccount extends SystemMultiBase {
	protected static $model_class = 'OperatorCloudAccount';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];
		if (isset($this->options['provider'])) {
			$filters['oca_provider'] = [$this->options['provider'], PDO::PARAM_STR];
		}
		if (isset($this->options['method'])) {
			$filters['oca_method'] = [$this->options['method'], PDO::PARAM_STR];
		}
		if (isset($this->options['status'])) {
			$filters['oca_status'] = [$this->options['status'], PDO::PARAM_STR];
		}
		return $this->_get_resultsv2('oca_operator_cloud_accounts', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
