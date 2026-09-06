<?php
/**
 * BackupTarget - A configured storage target for backups (B2, S3, Linode).
 *
 * Credentials are stored as JSON in bkt_credentials with a unified shape for
 * every provider:
 *   {"access_key": "...", "secret_key": "...", "region": "...", "endpoint": "..."}
 *
 * All providers authenticate via SigV4 against their S3-compatible endpoint.
 * For B2, the endpoint is auto-detected at save time via b2_authorize_account
 * (its S3-compat URL format is https://s3.<region>.backblazeb2.com).
 *
 * Credentials are encrypted at rest with SecretBox: the plaintext credential
 * JSON is sealed and stored as {"enc": "<blob>"} in the jsonb column. save()
 * seals; get_credentials() unseals. A legacy plaintext credential object reads
 * back unchanged, so existing rows migrate the next time they are saved.
 *
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
	public static $pkey_column = 'bkt_id';

	public static $json_vars = array('bkt_credentials', 'bkt_node_credentials');

	public static $field_specifications = array(
		'bkt_id'              => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'bkt_name'            => array('type'=>'varchar(100)', 'required'=>true, 'is_nullable'=>false),
		'bkt_provider'        => array('type'=>'varchar(30)', 'required'=>true, 'is_nullable'=>false, 'allowed_values'=>array('b2', 's3', 'linode')),
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

	private static $valid_providers = ['b2', 's3', 'linode'];

	function prepare() {
		if (empty($this->get('bkt_name'))) {
			throw new BackupTargetException('Target name is required.');
		}

		$provider = $this->get('bkt_provider');
		if (!in_array($provider, self::$valid_providers)) {
			throw new BackupTargetException('Invalid provider. Must be one of: ' . implode(', ', self::$valid_providers));
		}

		if (empty($this->get('bkt_bucket'))) {
			throw new BackupTargetException('Bucket name is required.');
		}

		$this->set('bkt_update_time', gmdate('Y-m-d H:i:s'));
	}

	/**
	 * Seal credentials before persisting. Encryption is mandatory data
	 * transformation, so it lives in save() (prepare() is not guaranteed to run
	 * before save()).
	 */
	function save($debug = false) {
		$this->seal_credentials('bkt_credentials');
		$this->seal_credentials('bkt_node_credentials');
		return parent::save($debug);
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
		return $this->unseal_column('bkt_credentials');
	}

	/**
	 * The write-only credential handed to nodes during a backup run, or [] when
	 * none is configured (nodes then receive the main credential).
	 */
	function get_node_credentials() {
		return $this->unseal_column('bkt_node_credentials');
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
