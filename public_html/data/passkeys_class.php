<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class PasskeyException extends SystemBaseException {}

/**
 * One enrolled WebAuthn credential. `pkc_source_json` is the library's
 * serialized CredentialRecord — the authoritative state every ceremony
 * round-trips through. The other columns are denormalized-on-write
 * conveniences for lookup and UI display.
 *
 * @version 1.1
 */
class Passkey extends SystemBase {

	/** vault_capability() answers. See the method for what each one is built
	 *  from and which of them is safe to act on. */
	const VAULT_CAPABLE   = 'capable';
	const VAULT_INCAPABLE = 'incapable';
	const VAULT_UNKNOWN   = 'unknown';

	public static $prefix = 'pkc';
	public static $tablename = 'pkc_passkey_credentials';
	public static $pkey_column = 'pkc_passkey_credential_id';

	protected static $foreign_key_actions = [
		'pkc_usr_user_id' => ['action' => 'permanent_delete'],
	];

	public static $api_readable = true;
	public static $api_writable = false;
	public static $api_unreadable_fields = array('pkc_source_json');
	public static $api_unwritable_fields = array();
	// The owner's own security page badges each passkey with what it can do for
	// the vault, and that answer is derived (vault_capability()), not a column.
	public static $api_derived_fields = array('vault_capability');

	public static $field_specifications = array(
		'pkc_passkey_credential_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true, 'is_primary_key'=>true),
		'pkc_usr_user_id'           => array('type'=>'int8', 'is_nullable'=>false, 'index'=>true,
			'foreign_key'=>array('table'=>'usr_users', 'column'=>'usr_user_id', 'on_delete'=>'CASCADE')),
		'pkc_credential_id'         => array('type'=>'text', 'is_nullable'=>false),
		'pkc_source_json'           => array('type'=>'text', 'is_nullable'=>false),
		'pkc_sign_count'            => array('type'=>'int8', 'is_nullable'=>false, 'default'=>0),
		'pkc_transports'            => array('type'=>'text', 'is_nullable'=>true),
		'pkc_aaguid'                => array('type'=>'varchar(64)', 'is_nullable'=>true),
		'pkc_prf_capable'           => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
		// Stamped when a VERIFIED derivation ceremony returned no PRF output —
		// the only proof an authenticator cannot hold a vault key. Distinct
		// from pkc_prf_capable=false, which merely means "never demonstrated".
		'pkc_prf_failed_time'       => array('type'=>'timestamp(6)', 'is_nullable'=>true),
		'pkc_discoverable'          => array('type'=>'bool', 'is_nullable'=>true),
		'pkc_attachment'            => array('type'=>'varchar(16)', 'is_nullable'=>true),
		'pkc_label'                 => array('type'=>'varchar(255)', 'is_nullable'=>true),
		'pkc_created_time'          => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'pkc_last_used_time'        => array('type'=>'timestamp(6)', 'is_nullable'=>true),
		'pkc_delete_time'           => array('type'=>'timestamp(6)', 'is_nullable'=>true),
	);

	// A revoked row keeps its credential id; a fresh re-enrollment of the
	// same physical credential must still be free to insert.
	public static $index_specifications = array(
		array('columns'=>array('pkc_credential_id'), 'unique'=>true, 'where'=>'pkc_delete_time IS NULL'),
	);

	/**
	 * Owner-ONLY read — tighter than the platform's owner-or-staff default.
	 * Passkeys are authentication credentials (credential id, AAGUID, sign
	 * counts, PRF capability): no one but the owner has a reason to read them,
	 * staff included. Admin support surfaces manage users, not credentials;
	 * revocation flows act on the session user's own rows. On the API's
	 * collection path a non-owned row throws here and is skipped, so any
	 * caller — any permission — only ever receives their own passkeys.
	 */
	function authenticate_read($data) {
		$owner_matches = $this->get('pkc_usr_user_id') == $data['current_user_id'];
		if (!$owner_matches) {
			throw new SystemAuthenticationError(
				'Passkeys are readable only by their owner.');
		}
	}

	/**
	 * Can this credential ever unlock a Sealed Vault? Three answers, derived on
	 * read from stored signals rather than held as a stored verdict:
	 *
	 *   capable   - it has already evaluated PRF. verifyDerivation() sets
	 *               pkc_prf_capable on the first success, so this is evidence.
	 *   incapable - the browser fell back to CTAP1 at registration: a U2F-only
	 *               key, which can neither verify a user nor evaluate
	 *               hmac-secret, so it can never unlock a vault whatever the
	 *               owner does to it.
	 *   unknown   - anything else, and deliberately PERMISSIVE. Windows Hello
	 *               omits prf.enabled at creation and evaluates PRF fine at
	 *               assertion, so registration-time reporting must never be the
	 *               thing that stops an attempt.
	 *
	 * Two independent evidence sets reach `incapable`; either is sufficient.
	 *
	 * The first is what registration records now: PRF not reported, the
	 * credential explicitly non-discoverable, and attachment explicitly
	 * cross-platform. All three must be KNOWN and negative — a null is the
	 * absence of a signal, not a negative one.
	 *
	 * The second reads what was stored all along, for credentials enrolled
	 * before those columns existed. `uvInitialized` in the library's
	 * CredentialRecord is a latch: set at registration and, while false, raised
	 * by the first assertion that verifies the user
	 * (AuthenticatorAssertionResponseValidator, WebAuthn § 26.3). It never
	 * returns to false. So false here means this credential has never verified a
	 * user in its life — which a FIDO2 key with a PIN would have done at its
	 * first sign-in. Paired with transports that exclude `internal` (platform
	 * authenticators are the known false negative this whole design exists to
	 * accommodate), that is the same conclusion from the signals available.
	 *
	 * `incapable` is safe to act on in one specific way: a credential that never
	 * supported PRF cannot have completed a derivation, so it cannot hold a
	 * vault wrapping. Excluding it from a vault ceremony can never remove a
	 * working unlocker.
	 */
	public function vault_capability(): string {
		if ($this->get('pkc_prf_capable')) {
			return self::VAULT_CAPABLE;
		}

		// A verified ceremony that produced no PRF output is the strongest
		// evidence there is — stronger than any registration-time signal.
		if ($this->get('pkc_prf_failed_time')) {
			return self::VAULT_INCAPABLE;
		}

		$discoverable = $this->get('pkc_discoverable');
		$attachment   = $this->get('pkc_attachment');
		if ($discoverable !== null && !$discoverable && $attachment === 'cross-platform') {
			return self::VAULT_INCAPABLE;
		}

		if ($this->uv_never_performed() && !$this->is_platform_authenticator()) {
			return self::VAULT_INCAPABLE;
		}

		return self::VAULT_UNKNOWN;
	}

	/** True only when the stored CredentialRecord says user verification has
	 *  never happened. A missing flag is not evidence and returns false. */
	public function uv_never_performed(): bool {
		$source = json_decode((string)$this->get('pkc_source_json'), true);
		return is_array($source) && array_key_exists('uvInitialized', $source)
			&& $source['uvInitialized'] === false;
	}

	/** Carries the derived capability alongside the columns, so every reader —
	 *  the API, the security page, the passkey lab — gets the same answer from
	 *  the same method rather than re-deriving it from raw signals. */
	function export_as_array() {
		$out = parent::export_as_array();
		$out['vault_capability'] = $this->vault_capability();
		return $out;
	}

	/** Built into the device (Touch ID, Windows Hello) rather than a removable
	 *  security key. An empty/absent transport list is not a claim either way,
	 *  so it counts as platform — the conservative side, since that is what
	 *  keeps a credential out of `incapable`. */
	public function is_platform_authenticator(): bool {
		$transports = json_decode((string)$this->get('pkc_transports'), true);
		if (!is_array($transports) || !$transports) {
			return true;
		}
		return in_array('internal', $transports, true);
	}

	/**
	 * Whether this account has any credential that could still hold a vault
	 * key — `capable`, or `unknown` and therefore worth attempting.
	 *
	 * This is the gate for the bypass-phrase compatibility fallback
	 * (docs/sealed_vault.md § When a passkey cannot hold the key). It answers
	 * FALSE only when every live credential is provably incapable, so the
	 * weaker unlocker can never be reached by an account that has a working
	 * passkey route. Read it on the server before honouring any phrase-first
	 * request — a hidden button is not a gate.
	 */
	public static function userHasVaultCapableOption(int $user_id): bool {
		$passkeys = new MultiPasskey(array('user_id' => $user_id));
		foreach ($passkeys as $passkey) {
			if ($passkey->vault_capability() !== self::VAULT_INCAPABLE) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the bypass-phrase bootstrap is permitted for this account: it
	 * holds at least one passkey and every one of them is provably incapable.
	 *
	 * The count requirement is what keeps this a compatibility fallback rather
	 * than a preference — an account with no passkeys at all is sent to enrol
	 * one, and only becomes eligible if that credential then fails a real
	 * derivation. Nobody can reach the weaker unlocker by simply owning
	 * nothing.
	 */
	public static function userNeedsPassphraseFallback(int $user_id): bool {
		$passkeys = new MultiPasskey(array('user_id' => $user_id));
		return $passkeys->count_all() > 0 && !self::userHasVaultCapableOption($user_id);
	}
}

class MultiPasskey extends SystemMultiBase {
	protected static $model_class = 'Passkey';

	protected function getMultiResults($only_count=false, $debug=false) {
		$filters = [];
		if (isset($this->options['user_id']))
			$filters['pkc_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
		if (isset($this->options['credential_id']))
			$filters['pkc_credential_id'] = [$this->options['credential_id'], PDO::PARAM_STR];
		if (isset($this->options['prf_capable']))
			$filters['pkc_prf_capable'] = "= " . ($this->options['prf_capable'] ? 'TRUE' : 'FALSE');
		$filters['pkc_delete_time'] = (isset($this->options['deleted']) && $this->options['deleted']) ? "IS NOT NULL" : "IS NULL";
		return $this->_get_resultsv2('pkc_passkey_credentials', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
