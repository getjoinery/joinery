<?php
/**
 * RelayClientIdentity - the Ed25519 key this deployment signs relay requests with.
 *
 * A relay without a shell (specs/relay_without_a_shell.md) authenticates its
 * callers by signature, not by a shared secret: every /relay/ request carries
 * an envelope signed with a key whose PUBLIC half the relay holds in its
 * registry. This row is that key. There is one per KIND:
 *
 *   client    this deployment as a relay's tenant. Its public key rides in the
 *             user-data at birth (tenant `main` on a self-hosted relay) or is
 *             sent at fleet enrollment.
 *   operator  this deployment as the operator of a fleet shard. Its public key
 *             rides in a shard's user-data; it signs the tenant routes only.
 *
 * The secret is sealed at rest with SecretBox exactly as DirectIdentity::mint()
 * seals a Direct signing key outside vault custody. It is never transmitted: the
 * relay gets a public key, the run row gets a public key, and an operator never
 * holds anything that could read a tenant's spool.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class RelayClientIdentityException extends SystemBaseException {}

class RelayClientIdentity extends SystemBase {
	public static $prefix = 'rci';
	public static $tablename = 'rci_relay_client_identities';
	public static $pkey_column = 'rci_relay_client_identity_id';

	const KIND_CLIENT   = 'client';
	const KIND_OPERATOR = 'operator';

	public static $field_specifications = array(
		'rci_relay_client_identity_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'rci_kind'              => array('type'=>'varchar(16)', 'is_nullable'=>false, 'allowed_values'=>array('client', 'operator')),
		// Standard base64 of the raw 32-byte Ed25519 public key - the form the
		// relay's registry and the user-data carry.
		'rci_public_key'        => array('type'=>'varchar(64)', 'is_nullable'=>false),
		// SecretBox-sealed base64 of the 64-byte secret key.
		'rci_sealed_secret_key' => array('type'=>'text', 'is_nullable'=>false),
		'rci_is_active'         => array('type'=>'bool', 'is_nullable'=>false, 'default'=>true),
		'rci_create_time'       => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'rci_update_time'       => array('type'=>'timestamp(6)'),
		'rci_delete_time'       => array('type'=>'timestamp(6)'),
	);

	function authenticate_read($data) {
		if ($data['current_user_permission'] < 10) {
			throw new SystemAuthenticationError('Only a superadmin may read relay identities.');
		}
	}

	function authenticate_write($data) {
		if ($data['current_user_permission'] < 10) {
			throw new SystemAuthenticationError('Only a superadmin may edit relay identities.');
		}
	}

	function prepare() {
		$this->set('rci_update_time', gmdate('Y-m-d H:i:s'));
	}

	/** @var array kind => RelayClientIdentity, request-scoped */
	private static $cache = array();

	/** The active identity of a kind, or null when none has been minted. */
	public static function forKind(string $kind): ?RelayClientIdentity {
		self::assertKind($kind);
		if (isset(self::$cache[$kind])) {
			return self::$cache[$kind];
		}
		$multi = new MultiRelayClientIdentity(array('kind' => $kind, 'active' => true, 'deleted' => false),
			array('rci_relay_client_identity_id' => 'DESC'), 1);
		foreach ($multi as $row) {
			self::$cache[$kind] = $row;
			return $row;
		}
		return null;
	}

	/** The active identity of a kind, minted on first use. */
	public static function ensure(string $kind): RelayClientIdentity {
		$existing = self::forKind($kind);
		if ($existing !== null) {
			return $existing;
		}
		return self::mint($kind);
	}

	/** The public key a relay is told about, standard base64. */
	public static function publicKey(string $kind): string {
		return (string)self::ensure($kind)->get('rci_public_key');
	}

	/**
	 * Sign a byte string with the kind's key. Returns the detached signature,
	 * standard base64.
	 */
	public static function sign(string $kind, string $message): string {
		$identity = self::ensure($kind);
		$secret = $identity->openSecretKey();
		try {
			return base64_encode(sodium_crypto_sign_detached($message, $secret));
		} finally {
			sodium_memzero($secret);
		}
	}

	/**
	 * Rotate: retire the active identity and mint a fresh one. The relay's
	 * registry still holds the old public key until an update re-images it
	 * (self-hosted) or the operator re-registers the tenant (fleet), so this is
	 * an act to pair with one of those, never a routine one.
	 */
	public static function rotate(string $kind): RelayClientIdentity {
		$previous = self::forKind($kind);
		$fresh = self::mint($kind);
		if ($previous !== null) {
			$previous->set('rci_is_active', false);
			$previous->save();
		}
		unset(self::$cache[$kind]);
		return $fresh;
	}

	private static function mint(string $kind): RelayClientIdentity {
		self::assertKind($kind);
		$pair   = sodium_crypto_sign_keypair();
		$secret = sodium_crypto_sign_secretkey($pair);
		$public = sodium_crypto_sign_publickey($pair);

		require_once(PathHelper::getIncludePath('includes/SecretBox.php'));
		$row = new RelayClientIdentity(NULL);
		$row->set('rci_kind', $kind);
		$row->set('rci_public_key', base64_encode($public));
		$row->set('rci_sealed_secret_key',
			(new SecretBox())->seal('rci_relay_client_identities.rci_sealed_secret_key', base64_encode($secret)));
		$row->set('rci_is_active', true);
		$row->save();
		sodium_memzero($secret);

		$fresh = new RelayClientIdentity(intval($row->key), TRUE);
		self::$cache[$kind] = $fresh;
		return $fresh;
	}

	/** The raw 64-byte secret key. Callers memzero it. */
	private function openSecretKey(): string {
		require_once(PathHelper::getIncludePath('includes/SecretBox.php'));
		$opened = (new SecretBox())->open((string)$this->get('rci_sealed_secret_key'));
		$decoded = ($opened['value'] === null) ? false : base64_decode($opened['value'], true);
		if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
			throw new RelayClientIdentityException('Relay identity ' . $this->key
				. ' is unreadable (moved database or rotated SecretBox key).');
		}
		return $decoded;
	}

	private static function assertKind(string $kind): void {
		if ($kind !== self::KIND_CLIENT && $kind !== self::KIND_OPERATOR) {
			throw new RelayClientIdentityException('Unknown relay identity kind: ' . $kind);
		}
	}

	/** Forget the request-scoped cache (tests mint and rotate between cases). */
	public static function resetForTests(): void {
		self::$cache = array();
	}
}

class MultiRelayClientIdentity extends SystemMultiBase {
	protected static $model_class = 'RelayClientIdentity';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		if (isset($this->options['kind'])) {
			$filters['rci_kind'] = [(string)$this->options['kind'], PDO::PARAM_STR];
		}
		if (isset($this->options['active'])) {
			$filters['rci_is_active'] = $this->options['active'] ? '= true' : '= false';
		}
		return $this->_get_resultsv2('rci_relay_client_identities', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
