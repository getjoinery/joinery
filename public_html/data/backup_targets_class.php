<?php
/**
 * BackupTarget - A configured storage target for backups, at any provider in
 * StorageProvider's catalogue.
 *
 * Credentials are stored as JSON in bkt_credentials with a unified shape for
 * every provider:
 *   {"access_key": "...", "secret_key": "...", "region": "...", "endpoint": "..."}
 *
 * All providers authenticate via SigV4 against their S3-compatible endpoint.
 * The endpoint is stored in one form, https://host (StorageProvider::normalise_endpoint()).
 * For B2, the endpoint and region are taken from b2_authorize_account at save time;
 * a provider that names its endpoint from the region gets it from the catalogue.
 *
 * Credentials are encrypted at rest with SecretBox: the plaintext credential
 * JSON is sealed and stored as {"enc": "<blob>"} in the jsonb column. save()
 * seals; get_credentials() unseals. A legacy plaintext credential object reads
 * back unchanged, so existing rows migrate the next time they are saved.
 *
 * @version 3.1 - no two targets share a name (a node's chain follows its target's name); holdings() reads the management node's storage spaces (active and draining owners); the folder
 *                is stored in one form (normalise_prefix(), prefix()) (specs/storage_targets.md WP4, S11)
 * @version 3.0 - holdings(), location_refusal(), disable_refusal(), delete_refusal(): a target's location is
 *                fixed once anything is stored in it, and it is not switched off or deleted while it is where
 *                new backups go or still holds or serves anything (specs/storage_targets.md WP2)
 * @version 2.9 - the allowed providers are StorageProvider's catalogue; complete_credentials() fills any
 *                provider's endpoint and region from the catalogue (one Backblaze region rule, and a
 *                Backblaze address the rule does not recognise is said); endpoints are normalised on save
 * @version 2.8 - credential_problem(): a Linode endpoint is checked before either save form asks the provider anything
 * @version 2.7 - a Backblaze credential is completed on READ as well as on save: a target saved
 *                before the save-time completion existed kept an empty region for good and every
 *                run signed with nothing (specs/post_release_fleet_defects.md B3). The completed
 *                credential is written back once, a server-initiated reconciliation.
 * @version 2.6 - complete_credentials(): a Backblaze credential's region and endpoint filled from
 *                Backblaze's own authorize answer, shared by the Backups page, the setup wizard and
 *                utils/install_backup_target.php
 * @version 2.5 - b2_s3_location(): region and endpoint from the S3 address Backblaze reports
 * @version 2.4 - bkt_mint_run_keys / can_mint_run_keys(): where the provider allows it, a node-bound
 *                run is handed a key minted for that run and pinned to that node's own prefix
 *                instead of the one write-only credential the whole fleet shares
 * @version 2.3 - bkt_node_credentials: a second, write-only credential handed to nodes during
 *                a backup run in place of the main (delete-capable) key. Optional — when empty,
 *                node-bound jobs carry the main credential as before. Sealed the same way.
 * @version 2.2 - sealed credentials that cannot be decrypted FAIL LOUD (a rotated/missing
 *                secret_box_key must not read as "no credentials"); seal_credentials only
 *                tolerates the no-key zero-config case, never an encryption failure
 * @version 2.1
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/SecretBox.php'));

class BackupTargetException extends SystemBaseException {}

class BackupTarget extends SystemBase {
	public static $prefix = 'bkt';
	public static $tablename = 'bkt_backup_targets';
	public static $pkey_column = 'bkt_backup_target_id';

	public static $json_vars = array('bkt_credentials', 'bkt_node_credentials');

	public static $field_specifications = array(
		'bkt_backup_target_id'              => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'bkt_name'            => array('type'=>'varchar(100)', 'required'=>true, 'is_nullable'=>false),
		// allowed_values is StorageProvider::slugs(), set below the class: one provider list.
		'bkt_provider'        => array('type'=>'varchar(30)', 'required'=>true, 'is_nullable'=>false, 'allowed_values'=>array()),
		'bkt_bucket'          => array('type'=>'varchar(255)'),
		'bkt_path_prefix'     => array('type'=>'varchar(255)', 'default'=>'joinery-backups'),
		'bkt_credentials'      => array('type'=>'jsonb'),
		'bkt_node_credentials' => array('type'=>'jsonb'),
		'bkt_enabled'         => array('type'=>'bool', 'default'=>true, 'is_nullable'=>false),
		// Whether a node-bound run gets a key MINTED for it — pinned to that
		// node's own prefix, write-only, expiring with the run — instead of the
		// one write-only credential every node in the fleet otherwise shares.
		//
		// OFF until an operator turns it on, and that default is load-bearing.
		// Minting needs a master key the provider will let create keys, and
		// whether a given account's key can is not knowable from here. A target
		// switched on by default would try to mint on the next cycle and, where
		// the key cannot, fail EVERY node's backup — trading a working fleet for
		// a better credential nobody asked for yet. The Remote Backup page says
		// what it needs; flipping it is a decision with a check behind it.
		'bkt_mint_run_keys'   => array('type'=>'bool', 'default'=>false, 'is_nullable'=>false),
		'bkt_create_time'     => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'bkt_update_time'     => array('type'=>'timestamp(6)'),
		'bkt_delete_time'     => array('type'=>'timestamp(6)'),
	);

	function prepare() {
		if (empty($this->get('bkt_name'))) {
			throw new BackupTargetException('Target name is required.');
		}

		$provider = $this->get('bkt_provider');
		if (!StorageProvider::known($provider)) {
			throw new BackupTargetException('Invalid provider. Must be one of: ' . implode(', ', StorageProvider::slugs()));
		}

		if (empty($this->get('bkt_bucket'))) {
			throw new BackupTargetException('Bucket name is required.');
		}
		$this->assert_own_name();

		$this->set('bkt_update_time', gmdate('Y-m-d H:i:s'));
	}

	/**
	 * Seal credentials before persisting. Encryption is mandatory data
	 * transformation, so it lives in save() (prepare() is not guaranteed to run
	 * before save()).
	 */
	function save($debug = false) {
		$this->assert_own_name();
		$this->set('bkt_path_prefix', self::normalise_prefix((string)$this->get('bkt_path_prefix')));
		$this->normalise_endpoint('bkt_credentials');
		$this->normalise_endpoint('bkt_node_credentials');
		$this->seal_credentials('bkt_credentials');
		$this->seal_credentials('bkt_node_credentials');
		return parent::save($debug);
	}

	/**
	 * A name says which target a backup went to: a management node sends it
	 * with every run, and a node's chain starts again when it changes. Two
	 * targets of one name would read as one, so a name is never shared.
	 */
	private function assert_own_name(): void {
		$name = trim((string)$this->get('bkt_name'));
		$q = DbConnector::get_instance()->get_db_link()->prepare("SELECT 1 FROM bkt_backup_targets
			WHERE lower(bkt_name) = lower(?) AND bkt_delete_time IS NULL AND bkt_backup_target_id <> ? LIMIT 1");
		$q->execute(array($name, (int)$this->key));
		if ($q->fetchColumn()) {
			throw new BackupTargetException('Another backup target is already called "' . $name . '". Give this one its own name.');
		}
	}

	/**
	 * Store an unsealed credential's endpoint in the one form every reader
	 * expects. A sealed value was normalised when it was set.
	 */
	private function normalise_endpoint($column) {
		$arr = self::creds_to_array($this->get($column));
		if (empty($arr) || self::looks_sealed($arr) || !isset($arr['endpoint'])) {
			return;
		}
		$normal = StorageProvider::normalise_endpoint($arr['endpoint']);
		if ($normal !== $arr['endpoint']) {
			$arr['endpoint'] = $normal;
			$this->set($column, $arr);
		}
	}

	/** The folder used when a target names none. */
	const DEFAULT_PREFIX = 'joinery-backups';

	/**
	 * A folder inside a bucket in its one form: no leading or trailing slash,
	 * no empty segment, the default when blank. Every key composed from a
	 * target starts with this, so a folder entered as '/backups/' and one
	 * entered as 'backups' are the same place.
	 */
	public static function normalise_prefix(string $prefix): string {
		$segments = array_filter(explode('/', trim($prefix)), function ($s) { return trim($s) !== ''; });
		$normal = implode('/', array_map('trim', $segments));
		return $normal !== '' ? $normal : self::DEFAULT_PREFIX;
	}

	/** This target's folder inside its bucket, normalised (no trailing slash). */
	public function prefix(): string {
		return self::normalise_prefix((string)$this->get('bkt_path_prefix'));
	}

	/** The settings that name the target new backups go to, on this deployment. */
	const DEFAULT_SETTINGS = array('backup_target_id', 'server_manager_backup_target_id');

	/** True when a declared setting names this target as where new backups go. */
	public function is_default(): bool {
		if (!$this->key) {
			return false;
		}
		$settings = Globalvars::get_instance();
		foreach (self::DEFAULT_SETTINGS as $name) {
			if ((int)$settings->get_setting($name, false, true) === (int)$this->key) {
				return true;
			}
		}
		return false;
	}

	/**
	 * What this target holds or serves, read from the records that point at it
	 * (specs/storage_targets.md §5). A site's own runs are its history rows; on
	 * a management node, the storage spaces on it: the owners whose new backups
	 * go there (active) and the owners whose older backups are still kept there
	 * while they age out (draining). Counted with the target disabled or not.
	 *
	 * @return array{stored: int, ever: int, active: string[], draining: string[]}
	 *   stored: runs whose objects are still there; ever: runs ever uploaded there;
	 *   active / draining: the owners of its spaces in that state, by name
	 */
	public function holdings(): array {
		$out = array('stored' => 0, 'ever' => 0, 'active' => array(), 'draining' => array());
		if (!$this->key) {
			return $out;
		}
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare("SELECT count(*) AS ever,
				count(*) FILTER (WHERE bkh_delete_time IS NULL AND bkh_pruned_time IS NULL) AS stored
			FROM bkh_backup_history WHERE bkh_bkt_backup_target_id = ? AND bkh_upload_time IS NOT NULL");
		$q->execute(array((int)$this->key));
		$row = $q->fetch(PDO::FETCH_ASSOC) ?: array();
		$out['ever'] = (int)($row['ever'] ?? 0);
		$out['stored'] = (int)($row['stored'] ?? 0);
		if (class_exists('StorageSpace')) {
			$out = array_merge($out, StorageSpace::holdings_of((int)$this->key));
		}
		return $out;
	}

	/**
	 * Why the location (provider, endpoint, region, bucket, folder) may not
	 * change, or '' when it may: once anything is stored there, records point
	 * at objects in that place (R1). A new place is a new target.
	 */
	public function location_refusal(): string {
		$h = $this->holdings();
		$why = array();
		if ($h['ever'] > 0) {
			$why[] = $h['ever'] . ' backup' . ($h['ever'] === 1 ? ' was' : 's were') . ' stored in it';
		}
		if ($h['active']) {
			$why[] = self::backs_up($h['active']);
		}
		if ($h['draining']) {
			$why[] = 'it still holds older backups of ' . self::name_list($h['draining']);
		}
		if (!$why) {
			return '';
		}
		return 'The provider, endpoint, region, bucket and folder cannot change: ' . implode('; ', $why)
			. '. The key and the name can. To use another bucket, add a target.';
	}

	/**
	 * Why this target may not be switched off, or '' when it may. Disabled
	 * means no new backups; reads, restores and pruning carry on. So what is
	 * refused is switching off a place new backups are sent.
	 */
	public function disable_refusal(): string {
		$why = array();
		if ($this->is_default()) {
			$why[] = 'it is where new backups go; choose another target for them first';
		}
		$active = $this->holdings()['active'];
		if ($active) {
			$why[] = self::backs_up($active) . '; move ' . (count($active) === 1 ? 'it' : 'them') . ' to another target first';
		}
		return $why ? 'It cannot be switched off: ' . implode('; ', $why) . '.' : '';
	}

	/** Why this target may not be deleted, or '' when nothing uses it. */
	public function delete_refusal(): string {
		$why = array();
		if ($this->is_default()) {
			$why[] = 'it is where new backups go; choose another target for them first';
		}
		$h = $this->holdings();
		if ($h['active']) {
			$why[] = self::backs_up($h['active']);
		}
		if ($h['draining']) {
			$why[] = 'it still holds older backups of ' . self::name_list($h['draining']) . ', kept until they age out';
		}
		if ($h['stored'] > 0) {
			$why[] = $h['stored'] . ' backup' . ($h['stored'] === 1 ? ' is' : 's are') . ' still stored in it, and retention prunes them there';
		}
		return $why ? 'It cannot be deleted: ' . implode('; ', $why) . '.' : '';
	}

	/** "acme backs up to it", "acme and t5 back up to it". */
	private static function backs_up(array $names): string {
		return self::name_list($names) . ' back' . (count($names) === 1 ? 's' : '') . ' up to it';
	}

	/** "a", "a and b", "a, b and 2 more". */
	private static function name_list(array $names): string {
		$names = array_values($names);
		if (count($names) > 3) {
			return $names[0] . ', ' . $names[1] . ' and ' . (count($names) - 2) . ' more';
		}
		if (count($names) === 1) {
			return $names[0];
		}
		return implode(', ', array_slice($names, 0, -1)) . ' and ' . end($names);
	}

	/**
	 * Get credentials as an associative array. Transparently unseals an
	 * encrypted value; a legacy plaintext credential object is returned as-is.
	 *
	 * A sealed value that cannot be decrypted throws instead of returning [] —
	 * silence here would surface later as a baffling "missing access_key" job
	 * failure while the real cause (rotated/missing secret_box_key, or a DB
	 * restored to a machine without it) stays invisible.
	 */
	function get_credentials() {
		return $this->heal_b2_location('bkt_credentials', $this->unseal_column('bkt_credentials'));
	}

	/**
	 * The write-only credential handed to nodes during a backup run, or [] when
	 * none is configured (nodes then receive the main credential).
	 */
	function get_node_credentials() {
		return $this->heal_b2_location('bkt_node_credentials', $this->unseal_column('bkt_node_credentials'));
	}

	/**
	 * A Backblaze credential with no region or endpoint cannot sign a request.
	 * The forms hide both and complete_credentials() fills them at save time,
	 * so a target saved before that existed carries an empty region for good
	 * and every run that reads it fails at the signer. Completed here, on read,
	 * from Backblaze's own answer, and written back once so the next read finds
	 * it. The write is a reconciliation the server makes on its own (the row
	 * brought into line with a fact just fetched), never something a user
	 * asked for, which is what server_initiated_write() is for. A failure to
	 * ask Backblaze leaves the credential as it was; the caller's signer then
	 * says what is missing, as before.
	 *
	 * @param string $column bkt_credentials | bkt_node_credentials
	 * @param array  $creds  what the column unsealed to
	 * @return array the credential, completed where it could be
	 */
	private function heal_b2_location($column, array $creds) {
		if ($this->get('bkt_provider') !== 'b2' || $this->key === null
				|| (string)($creds['access_key'] ?? '') === '' || (string)($creds['secret_key'] ?? '') === ''
				|| (trim((string)($creds['region'] ?? '')) !== '' && trim((string)($creds['endpoint'] ?? '')) !== '')) {
			return $creds;
		}
		$completed = self::complete_credentials('b2', $creds);
		$healed = $completed['creds'];
		if ($healed['region'] === '' || $healed['endpoint'] === '') {
			return $creds;
		}
		// Keep any extra keys the stored credential carried.
		$healed = array_merge($creds, $healed);
		$this->set($column, $healed);
		try {
			SystemBase::server_initiated_write(function () {
				$this->save();
			});
		} catch (\Throwable $e) {
			error_log('BackupTarget: could not write back the completed Backblaze credential for "'
				. $this->get('bkt_name') . '": ' . $e->getMessage());
		}
		return $healed;
	}

	/**
	 * Whether a node-facing credential is configured, without decrypting it.
	 * This is what decides which placeholder token a node-bound job carries.
	 */
	function has_node_credentials() {
		return !empty(self::creds_to_array($this->get('bkt_node_credentials')));
	}

	/**
	 * Can this target mint a key for one run, scoped to one node's prefix?
	 *
	 * Provider-dependent and deliberately narrow: B2 pins an application key to
	 * a bucket, a name prefix, a capability list and a lifetime in one call.
	 * Amazon's equivalent is an STS session policy and is not built. A target
	 * that cannot mint keeps handing nodes the stored write-only credential,
	 * which is what the fleet had before and is unchanged by any of this.
	 */
	function can_mint_run_keys() {
		return $this->get('bkt_provider') === 'b2'
			&& !empty($this->get('bkt_mint_run_keys'))
			&& !empty(self::creds_to_array($this->get('bkt_credentials')));
	}

	private function unseal_column($column) {
		$arr = self::creds_to_array($this->get($column));
		if (self::looks_sealed($arr)) {
			// Read through the shared open() contract. This target's deliberate
			// fail-loud surface is preserved: a dead credential still throws rather
			// than reading as "no credentials", it just reads the same way every
			// other sealed value does now.
			$result = (new SecretBox())->open($arr['enc']);
			// looks_sealed() guarantees a blob reached here, so a non-OK result is a
			// genuine failure — fail loud rather than reading as "no credentials".
			if ($result['state'] !== SecretBox::OPEN_OK) {
				throw new BackupTargetException(
					'Backup target "' . $this->get('bkt_name') . '" credentials cannot be decrypted. '
					. 'The stored value is sealed with secret_box_key; if that key '
					. 'was rotated or this database was moved to a machine without it, restore the '
					. 'original key or re-enter the credentials on the target.');
			}
			$inner = json_decode((string)$result['value'], true);
			return is_array($inner) ? $inner : [];
		}
		return $arr;
	}

	/**
	 * Encrypt a stored credential column in place as {"enc": "<blob>"}.
	 * Idempotent (an already-sealed value is left alone) and a no-op when there
	 * are no credentials or no SecretBox key is configured — the latter keeps a
	 * zero-config install writing readable plaintext rather than failing.
	 */
	private function seal_credentials($column) {
		$arr = self::creds_to_array($this->get($column));
		if (empty($arr) || self::looks_sealed($arr)) {
			return;
		}
		try {
			$box = new SecretBox();
		} catch (\Throwable $e) {
			// No secret_box_key configured — the zero-config install writes
			// readable plaintext by design. Only THIS case is tolerated.
			return;
		}
		// An actual encryption failure propagates: silently persisting plaintext
		// when encryption was expected would defeat at-rest protection unnoticed.
		$this->set($column, array('enc' => $box->seal(self::$tablename . '.' . $column, json_encode($arr))));
	}

	/**
	 * How Backblaze is asked for the account's S3 address: a callable taking
	 * (access_key, secret_key) and returning the authorize answer's s3_endpoint.
	 * NULL means the real B2Client; a test sets a stand-in so completion runs
	 * without the network.
	 * @var callable|null
	 */
	public static $b2_locator = null;

	/**
	 * Fill what a form did not ask for, from the provider's catalogue entry.
	 * Backblaze is asked: its authorize answer names the account's S3 address,
	 * and the region is a label inside it (StorageProvider::b2_location()). A
	 * provider that names its endpoint from the region gets it from there, and
	 * one with a fixed region gets that. What was typed is never overwritten.
	 * The endpoint comes back in the stored form.
	 *
	 * @param array $creds {access_key, secret_key, region, endpoint}
	 * @return array{creds: array, note: string} note is non-empty when
	 *   Backblaze could not be asked or named an address this site does not
	 *   recognise; the connection test then says so.
	 */
	public static function complete_credentials(string $provider, array $creds): array {
		$creds = array(
			'access_key' => (string)($creds['access_key'] ?? ''),
			'secret_key' => (string)($creds['secret_key'] ?? ''),
			'region'     => trim((string)($creds['region'] ?? '')),
			'endpoint'   => trim((string)($creds['endpoint'] ?? '')),
		);
		$note = '';
		if ($provider === 'b2') {
			if (($creds['region'] === '' || $creds['endpoint'] === '')
					&& $creds['access_key'] !== '' && $creds['secret_key'] !== '') {
				try {
					if (self::$b2_locator !== null) {
						$s3_endpoint = (string)call_user_func(self::$b2_locator, $creds['access_key'], $creds['secret_key']);
					} else {
						$auth = (new B2Client($creds['access_key'], $creds['secret_key']))->authorize();
						$s3_endpoint = (string)($auth['s3_endpoint'] ?? '');
					}
					$loc = StorageProvider::b2_location($s3_endpoint);
					if ($loc['endpoint'] !== '') {
						$creds['region'] = $creds['region'] !== '' ? $creds['region'] : $loc['region'];
						$creds['endpoint'] = $creds['endpoint'] !== '' ? $creds['endpoint'] : $loc['endpoint'];
					} else {
						$note = 'Backblaze named the S3 address "' . $s3_endpoint . '", which this site does not recognise, so the region is unknown.';
					}
				} catch (\Throwable $e) {
					$note = 'Backblaze could not be asked for the bucket\'s S3 address (' . $e->getMessage() . ').';
				}
			}
		} elseif (StorageProvider::known($provider)) {
			$fixed_region = (string)(StorageProvider::catalogue()[$provider]['region'] ?? '');
			if ($creds['region'] === '' && $fixed_region !== '') {
				$creds['region'] = $fixed_region;
			}
			if ($creds['endpoint'] === '') {
				$creds['endpoint'] = StorageProvider::endpoint_for($provider, $creds['region']);
			}
		}
		$creds['endpoint'] = StorageProvider::normalise_endpoint($creds['endpoint']);
		return array('creds' => $creds, 'note' => $note);
	}

	/**
	 * What is wrong with a provider's own fields, as one sentence, or '' when they will do.
	 * Both save forms run this before the provider is asked anything, so a mistyped
	 * endpoint is named as a mistake rather than surfacing as a failed connection test.
	 *
	 * A provider that asks for the region (StorageProvider::asks()) needs one,
	 * and a region is a short name such as us-east-1, never an address.
	 *
	 * Linode addresses a cluster by its host alone, {cluster}.linodeobjects.com, over
	 * https. A bucket name in the host, a path after it and a plain-http scheme are refused.
	 */
	public static function credential_problem(string $provider, array $creds): string {
		$region = trim((string)($creds['region'] ?? ''));
		$endpoint = trim((string)($creds['endpoint'] ?? ''));
		if ($region !== '' && !preg_match('/^[a-z0-9-]+$/i', $region)) {
			return 'The region is a short name such as us-east-1, not an address.';
		}
		if ($provider !== 'linode') {
			if ($region === '' && $provider !== StorageProvider::GENERIC && in_array('region', StorageProvider::asks($provider), true)) {
				return StorageProvider::label($provider) . ' needs the region the bucket is in.';
			}
			return '';
		}
		if ($region === '') {
			return 'Linode needs the region the bucket is in, such as us-east-1.';
		}
		if ($endpoint === '') {
			return 'Linode needs the endpoint, such as us-east-1.linodeobjects.com.';
		}
		$url = strpos($endpoint, '://') === false ? 'https://' . $endpoint : $endpoint;
		$parts = parse_url($url) ?: array();
		$host = strtolower((string)($parts['host'] ?? ''));
		$scheme = strtolower((string)($parts['scheme'] ?? ''));
		if ($scheme !== 'https' || !preg_match('/^[a-z0-9-]+\.linodeobjects\.com$/', $host) || trim((string)($parts['path'] ?? ''), '/') !== '') {
			return 'The Linode endpoint is the cluster\'s address alone over https, such as us-east-1.linodeobjects.com. '
				. 'Leave out the bucket name and any path.';
		}
		return '';
	}

	/**
	 * Every stored credential blob, for the sealed-secret reconciler. Its column
	 * is a jsonb {"enc":"<blob>"} envelope, so the reconciler cannot reach the
	 * blob from the code-free locator alone — this enumerator unwraps it.
	 *
	 * @return array<array{ref:string, blob:?string}>
	 */
	public static function eachCredentialBlob(): array {
		return self::each_column_blob('bkt_credentials');
	}

	/** As eachCredentialBlob(), for the node-facing credential column. */
	public static function eachNodeCredentialBlob(): array {
		return self::each_column_blob('bkt_node_credentials');
	}

	private static function each_column_blob(string $column): array {
		$out = array();
		$targets = new MultiBackupTarget(array('deleted' => false));
		$targets->load();
		foreach ($targets as $target) {
			$arr = self::creds_to_array($target->get($column));
			$out[] = array(
				'ref'  => (string)$target->get('bkt_name'),
				'blob' => self::looks_sealed($arr) ? (string)$arr['enc'] : null,
			);
		}
		return $out;
	}

	/** Normalise the stored credential value (array or JSON string) to an array. */
	private static function creds_to_array($creds) {
		if (is_string($creds)) {
			return json_decode($creds, true) ?: array();
		}
		return is_array($creds) ? $creds : array();
	}

	/** True when the credential array is the sealed {"enc": "<SecretBox blob>"} shape. */
	private static function looks_sealed($arr) {
		return isset($arr['enc']) && is_string($arr['enc']) && SecretBox::looksEncrypted($arr['enc']);
	}

}

BackupTarget::$field_specifications['bkt_provider']['allowed_values'] = StorageProvider::slugs();

class MultiBackupTarget extends SystemMultiBase {
	protected static $model_class = 'BackupTarget';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['provider'])) {
			$filters['bkt_provider'] = [$this->options['provider'], PDO::PARAM_STR];
		}

		if (isset($this->options['enabled'])) {
			$filters['bkt_enabled'] = $this->options['enabled'] ? "= true" : "= false";
		}


		return $this->_get_resultsv2('bkt_backup_targets', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
