<?php
/**
 * PasskeyService - the single owner of every WebAuthn ceremony on the platform.
 *
 * Wraps web-auth/webauthn-lib (pure-PHP, pinned per docs/passkeys.md) so
 * consumers never touch the library directly. Four ceremonies:
 *
 *   - register / verifyRegistration  - enroll a credential on a signed-in,
 *     step-up-verified session.
 *   - authenticate / verifyAuthentication - passwordless sign-in.
 *   - stepup / verifyStepUp - re-confirm before a sensitive action.
 *   - derive / verifyDerivation - the WebAuthn PRF extension, producing a
 *     stable 32-byte secret per (credential, context) that the server never
 *     holds at rest.
 *
 * Challenges are single-use and scoped to the browser-session id, expiring
 * after CHALLENGE_TTL_SECONDS. They live in pks_passkey_ceremonies (not
 * $_SESSION: browser-session API requests release the session lock before
 * dispatch, so $_SESSION writes made inside an action are discarded - see
 * ApiAuth::authenticateBrowserSession). The step-up marker lives there too,
 * for the same reason. RP ID/origin come from the site's own domain
 * (LibraryFunctions::get_absolute_url()) - no separate setting.
 *
 * @version 1.1
 */

use ParagonIE\ConstantTime\Base64UrlSafe;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticationExtensions\AuthenticationExtensions;
use Webauthn\AuthenticationExtensions\PseudoRandomFunctionInputExtensionBuilder;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Counter\CounterChecker;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Cose\Algorithm\Signature\ECDSA\ES256;
use Cose\Algorithm\Signature\RSA\RS256;

class PasskeyRevocationVetoException extends Exception {}

/**
 * Sign-count regression is flagged, never fatal - synced passkeys (iCloud
 * Keychain, Google Password Manager) legitimately report 0 on every use.
 */
class PasskeyFlaggingCounterChecker implements CounterChecker {
	public function check(CredentialRecord $credentialRecord, int $currentCounter): void {
		$stored = $credentialRecord->counter;
		if ($currentCounter === 0 && $stored === 0) {
			return;
		}
		if ($currentCounter <= $stored) {
			error_log('[PasskeyService] sign-count regression for credential '
				. Base64UrlSafe::encodeUnpadded($credentialRecord->publicKeyCredentialId)
				. ': stored=' . $stored . ' new=' . $currentCounter);
		}
	}
}

class PasskeyService {

	const CHALLENGE_TTL_SECONDS = 120;
	const STEPUP_MARKER_TTL_SECONDS = 3600;

	/** PRF contexts a consumer may request. Add a new entry when a new
	 *  client-held-key consumer enrolls (docs/passkeys.md). One context per
	 *  vault scope, so a KEK derived for one scope can never unwrap another's
	 *  key: 'vault-kek' is server-custody (mail + chat, sent to the server);
	 *  'vault-passwords-kek' and 'vault-drive-kek' are client-custody
	 *  (browser-only, never transmitted). */
	const ALLOWED_PRF_CONTEXTS = array('vault-kek', 'vault-passwords-kek', 'vault-drive-kek');

	/** @var callable[] */
	private static $pre_revoke_callbacks = array();

	/** @var callable[] */
	private static $post_revoke_callbacks = array();

	private $serializer;
	private $rp_id;
	private $rp_name;
	private $origin;
	private $creation_ceremony;
	private $request_ceremony;

	public function __construct() {
		require_once(PathHelper::getComposerAutoloadPath());
		require_once(PathHelper::getIncludePath('data/passkeys_class.php'));
		require_once(PathHelper::getIncludePath('data/passkey_ceremonies_class.php'));
		require_once(PathHelper::getIncludePath('data/users_class.php'));

		$settings = Globalvars::get_instance();
		$this->origin = LibraryFunctions::get_absolute_url();
		$this->rp_id = parse_url($this->origin, PHP_URL_HOST);
		$this->rp_name = $settings->get_setting('site_name') ?: 'Joinery';

		$attestation_support_manager = new AttestationStatementSupportManager([
			new NoneAttestationStatementSupport(),
		]);
		$this->serializer = (new WebauthnSerializerFactory($attestation_support_manager))->create();

		$factory = new CeremonyStepManagerFactory();
		$factory->setCounterChecker(new PasskeyFlaggingCounterChecker());
		$factory->setAllowedOrigins([$this->origin]);
		$this->creation_ceremony = $factory->creationCeremony();
		$this->request_ceremony = $factory->requestCeremony();
	}

	// ========================================================================
	// Enrollment
	// ========================================================================

	public function getRegistrationOptions(User $user, bool $prf_capable_requested = false): array {
		$existing = new MultiPasskey(['user_id' => $user->key]);
		$existing->load();
		$exclude = [];
		foreach ($existing as $passkey) {
			$exclude[] = PublicKeyCredentialDescriptor::create(
				PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
				Base64UrlSafe::decodeNoPadding($passkey->get('pkc_credential_id'))
			);
		}

		$extensions = null;
		if ($prf_capable_requested) {
			$extensions = AuthenticationExtensions::create([
				PseudoRandomFunctionInputExtensionBuilder::create()->build(),
			]);
		}

		$challenge = random_bytes(32);
		$options = PublicKeyCredentialCreationOptions::create(
			PublicKeyCredentialRpEntity::create($this->rp_name, $this->rp_id),
			PublicKeyCredentialUserEntity::create($user->get('usr_email'), (string)$user->key, $this->_displayName($user)),
			$challenge,
			$this->_pubKeyCredParams(),
			$this->_authenticatorSelection(),
			PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
			$exclude,
			null,
			$extensions
		);

		$this->_stashChallenge('register:' . $user->key, $challenge);
		return json_decode($this->serializer->serialize($options, 'json'), true);
	}

	public function verifyRegistration(string $client_response_json, string $label): Passkey {
		$session = SessionControl::get_instance();
		$user_id = $session->get_user_id();
		if (!$user_id) {
			throw new PasskeyException('You must be signed in to add a passkey.');
		}
		$user = new User($user_id, TRUE);

		$challenge = $this->_consumeChallenge('register:' . $user->key);

		$data = json_decode($client_response_json, true);
		if (!is_array($data)) {
			throw new PasskeyException('Invalid passkey response.');
		}
		$prf_reported = !empty($data['clientExtensionResults']['prf']['enabled']);

		$pk_credential = $this->serializer->deserialize($client_response_json, PublicKeyCredential::class, 'json');
		if (!$pk_credential->response instanceof AuthenticatorAttestationResponse) {
			throw new PasskeyException('Expected a passkey registration response.');
		}

		$options = PublicKeyCredentialCreationOptions::create(
			PublicKeyCredentialRpEntity::create($this->rp_name, $this->rp_id),
			PublicKeyCredentialUserEntity::create($user->get('usr_email'), (string)$user->key, $this->_displayName($user)),
			$challenge,
			$this->_pubKeyCredParams(),
			null,
			PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE
		);

		$validator = AuthenticatorAttestationResponseValidator::create($this->creation_ceremony);
		$credential_record = $validator->check($pk_credential->response, $options, $this->_host());

		$passkey = new Passkey(NULL);
		$passkey->set('pkc_usr_user_id', $user->key);
		$passkey->set('pkc_credential_id', Base64UrlSafe::encodeUnpadded($credential_record->publicKeyCredentialId));
		$passkey->set('pkc_source_json', $this->serializer->serialize($credential_record, 'json'));
		$passkey->set('pkc_sign_count', $credential_record->counter);
		$passkey->set('pkc_transports', json_encode($credential_record->transports));
		$passkey->set('pkc_aaguid', (string)$credential_record->aaguid);
		$passkey->set('pkc_prf_capable', $prf_reported);
		$passkey->set('pkc_label', trim($label) !== '' ? trim($label) : 'Passkey');
		try {
			$passkey->save();
		} catch (Exception $e) {
			throw new PasskeyException('This passkey could not be saved. It may already be registered.');
		}
		return $passkey;
	}

	// ========================================================================
	// Authentication (sign-in)
	// ========================================================================

	public function getAuthenticationOptions(?string $email = null): array {
		$allow = [];
		if ($email) {
			$user = User::GetByEmail($email);
			if ($user && $user->key) {
				$creds = new MultiPasskey(['user_id' => $user->key]);
				$creds->load();
				foreach ($creds as $passkey) {
					$allow[] = PublicKeyCredentialDescriptor::create(
						PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
						Base64UrlSafe::decodeNoPadding($passkey->get('pkc_credential_id'))
					);
				}
			}
			// No user / no credentials for that email: fall through with an empty
			// allow list rather than revealing whether the address has passkeys.
		}

		$challenge = random_bytes(32);
		$options = PublicKeyCredentialRequestOptions::create($challenge, $this->rp_id, $allow, 'preferred');
		$this->_stashChallenge('authenticate', $challenge);
		return json_decode($this->serializer->serialize($options, 'json'), true);
	}

	public function verifyAuthentication(string $client_response_json): User {
		$challenge = $this->_consumeChallenge('authenticate');
		$pk_credential = $this->_decodeAssertionResponse($client_response_json);
		$passkey = $this->_findLivePasskeyByRawId($pk_credential->rawId);

		$this->_checkAssertion($pk_credential, $passkey, $challenge);

		$user = new User((int)$passkey->get('pkc_usr_user_id'), TRUE);
		if (!$user || !$user->key) {
			throw new PasskeyException('The account for this passkey no longer exists.');
		}

		SessionControl::get_instance()->store_session_variables($user);
		return $user;
	}

	// ========================================================================
	// Step-up
	// ========================================================================

	/**
	 * @param string[] $exclude_credential_ids base64url credential ids to omit
	 *   from the allow list — used by the vault-holder password reset so the
	 *   step-up second factor is a DIFFERENT credential than the passkey that
	 *   authorized the reset (specs/mailbox_security_levels.md § Password reset:
	 *   the passkey must not transitively open both doors).
	 */
	public function getStepUpOptions(User $user, array $exclude_credential_ids = []): array {
		$creds = new MultiPasskey(['user_id' => $user->key]);
		$creds->load();
		$allow = [];
		foreach ($creds as $passkey) {
			if (in_array($passkey->get('pkc_credential_id'), $exclude_credential_ids, true)) {
				continue;
			}
			$allow[] = PublicKeyCredentialDescriptor::create(
				PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
				Base64UrlSafe::decodeNoPadding($passkey->get('pkc_credential_id'))
			);
		}
		if (!$allow) {
			throw new PasskeyException('No passkeys are enrolled on this account.');
		}

		$challenge = random_bytes(32);
		$options = PublicKeyCredentialRequestOptions::create($challenge, $this->rp_id, $allow, 'preferred');
		$this->_stashChallenge('stepup:' . $user->key, $challenge);
		return json_decode($this->serializer->serialize($options, 'json'), true);
	}

	/** @return string the verified credential's base64url pkc_credential_id —
	 *  the server-derived identity of the passkey that answered, for callers
	 *  that must know WHICH credential stepped up (the vault-holder password
	 *  reset refuses the credential that authorized the reset). */
	public function verifyStepUp(string $client_response_json, User $user): string {
		$challenge = $this->_consumeChallenge('stepup:' . $user->key);
		$pk_credential = $this->_decodeAssertionResponse($client_response_json);
		$passkey = $this->_findLivePasskeyByRawId($pk_credential->rawId);

		if ((int)$passkey->get('pkc_usr_user_id') !== (int)$user->key) {
			throw new PasskeyException('This passkey does not belong to your account.');
		}

		$this->_checkAssertion($pk_credential, $passkey, $challenge);

		$this->_deleteCeremonyRows('stepup');
		$marker = new PasskeyCeremony(NULL);
		$marker->set('pks_session_id', $this->_sessionId());
		$marker->set('pks_kind', 'stepup');
		$marker->set('pks_purpose', 'stepup_verified');
		$marker->set('pks_expires_time', gmdate('Y-m-d H:i:s', time() + self::STEPUP_MARKER_TTL_SECONDS));
		$marker->save();

		return (string)$passkey->get('pkc_credential_id');
	}

	public function hasRecentStepUp(int $max_age_seconds = 300): bool {
		$markers = new MultiPasskeyCeremony(['session_id' => $this->_sessionId(), 'kind' => 'stepup']);
		$markers->load();
		foreach ($markers as $marker) {
			$verified_at = strtotime($marker->get('pks_created_time') . ' UTC');
			if ($verified_at && (time() - $verified_at) <= $max_age_seconds) {
				return true;
			}
		}
		return false;
	}

	// ========================================================================
	// PRF secret derivation
	// ========================================================================

	public function getDerivationOptions(User $user, string $context): array {
		if (!in_array($context, self::ALLOWED_PRF_CONTEXTS, true)) {
			throw new PasskeyException('Unknown passkey secret context: ' . $context);
		}

		$creds = new MultiPasskey(['user_id' => $user->key, 'prf_capable' => true]);
		$creds->load();
		$allow = [];
		foreach ($creds as $passkey) {
			$allow[] = PublicKeyCredentialDescriptor::create(
				PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
				Base64UrlSafe::decodeNoPadding($passkey->get('pkc_credential_id'))
			);
		}
		if (!$allow) {
			throw new PasskeyException('No PRF-capable passkeys are enrolled on this account.');
		}

		$extensions = AuthenticationExtensions::create([
			PseudoRandomFunctionInputExtensionBuilder::create()
				->withInputs($this->_prfSalt($context))
				->build(),
		]);

		// Every PRF context today is a Sealed Vault unlock (vault-kek and its
		// future per-scope siblings) - unlike sign-in/step-up, these require
		// user verification, not merely preferred (specs/mailbox_security_levels.md
		// § Authentication).
		$challenge = random_bytes(32);
		$options = PublicKeyCredentialRequestOptions::create($challenge, $this->rp_id, $allow, 'required', null, $extensions);
		$this->_stashChallenge('derive:' . $context . ':' . $user->key, $challenge);
		return json_decode($this->serializer->serialize($options, 'json'), true);
	}

	public function verifyDerivation(string $client_response_json, string $context): array {
		if (!in_array($context, self::ALLOWED_PRF_CONTEXTS, true)) {
			throw new PasskeyException('Unknown passkey secret context: ' . $context);
		}

		$data = json_decode($client_response_json, true);
		$prf_output_b64url = $data['clientExtensionResults']['prf']['results']['first'] ?? null;
		if (!is_array($data) || !$prf_output_b64url) {
			throw new PasskeyException('This passkey did not return a derived secret. It may not support PRF.');
		}

		$pk_credential = $this->_decodeAssertionResponse($client_response_json);
		$passkey = $this->_findLivePasskeyByRawId($pk_credential->rawId);
		$user_id = (int)$passkey->get('pkc_usr_user_id');

		$challenge = $this->_consumeChallenge('derive:' . $context . ':' . $user_id);
		// Every PRF context is a Sealed Vault unlock - user verification is
		// enforced here (the validator's CheckUserVerification step), not just
		// requested in the options (getDerivationOptions() asks for 'required' too).
		$this->_checkAssertion($pk_credential, $passkey, $challenge, 'required');

		$user = new User($user_id, TRUE);
		$prf_output = Base64UrlSafe::decodeNoPadding($prf_output_b64url);
		return [$user, $passkey, $prf_output];
	}

	// ========================================================================
	// Consumer helpers
	// ========================================================================

	public function listCredentials(User $user): MultiPasskey {
		$creds = new MultiPasskey(['user_id' => $user->key], ['pkc_created_time' => 'ASC']);
		$creds->load();
		return $creds;
	}

	public function revoke(int $credential_id, User $actor): void {
		$passkey = $this->_loadOwnedPasskey($credential_id, $actor);

		foreach (self::$pre_revoke_callbacks as $callback) {
			call_user_func($callback, (int)$actor->key, $credential_id);
		}

		$passkey->soft_delete();

		foreach (self::$post_revoke_callbacks as $callback) {
			call_user_func($callback, (int)$actor->key, $credential_id);
		}
	}

	public function rename(int $credential_id, User $actor, string $label): void {
		$passkey = $this->_loadOwnedPasskey($credential_id, $actor);
		$label = trim($label);
		if ($label === '') {
			throw new PasskeyException('Please enter a label for this passkey.');
		}
		$passkey->set('pkc_label', $label);
		$passkey->save();
	}

	/**
	 * Registers a veto callback consulted (in registration order) before every
	 * revoke(). A callback throws PasskeyRevocationVetoException to block the
	 * revocation; the message surfaces to the user. Used when the platform
	 * signal bus cannot veto synchronously (it can't - see docs/signals.md).
	 */
	public static function onPreRevoke(callable $callback): void {
		self::$pre_revoke_callbacks[] = $callback;
	}

	/**
	 * Registers a callback consulted (in registration order) after every
	 * successful revoke() - the credential is already soft-deleted by the
	 * time it runs. A consumer with per-credential state tied to a passkey
	 * (e.g. the vault's per-passkey wrapping) cleans it up here, mirroring
	 * onPreRevoke()'s registry mechanism.
	 */
	public static function onPostRevoke(callable $callback): void {
		self::$post_revoke_callbacks[] = $callback;
	}

	// ========================================================================
	// Internals
	// ========================================================================

	private function _loadOwnedPasskey(int $credential_id, User $actor): Passkey {
		$passkey = new Passkey($credential_id, TRUE);
		if (!$passkey->key || (int)$passkey->get('pkc_usr_user_id') !== (int)$actor->key || $passkey->get('pkc_delete_time')) {
			throw new PasskeyException('Passkey not found.');
		}
		return $passkey;
	}

	private function _pubKeyCredParams(): array {
		return [
			PublicKeyCredentialParameters::createPk(ES256::ID),
			PublicKeyCredentialParameters::createPk(RS256::ID),
		];
	}

	/** Ask for a discoverable credential where the authenticator supports it -
	 *  usernameless sign-in (empty allowCredentials) only works with resident
	 *  keys. 'preferred', not 'required', so old security keys still enroll. */
	private function _authenticatorSelection(): AuthenticatorSelectionCriteria {
		return AuthenticatorSelectionCriteria::create(
			null,
			AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
			AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED
		);
	}

	private function _displayName(User $user): string {
		$name = trim($user->get('usr_first_name') . ' ' . $user->get('usr_last_name'));
		return $name !== '' ? $name : $user->get('usr_email');
	}

	private function _host(): string {
		return $_SERVER['HTTP_HOST'] ?? $this->rp_id;
	}

	/** Fixed, deterministic per-context salt so the same context always evaluates the same PRF input. */
	private function _prfSalt(string $context): string {
		return hash('sha256', 'joinery-passkey-prf:' . $context, true);
	}

	private function _decodeAssertionResponse(string $client_response_json): PublicKeyCredential {
		$pk_credential = $this->serializer->deserialize($client_response_json, PublicKeyCredential::class, 'json');
		if (!$pk_credential->response instanceof AuthenticatorAssertionResponse) {
			throw new PasskeyException('Expected a passkey authentication response.');
		}
		return $pk_credential;
	}

	private function _findLivePasskeyByRawId(string $raw_id): Passkey {
		$credential_id_b64url = Base64UrlSafe::encodeUnpadded($raw_id);
		$candidates = new MultiPasskey(['credential_id' => $credential_id_b64url, 'deleted' => false]);
		$candidates->load();
		if ($candidates->count() !== 1) {
			throw new PasskeyException('This passkey is not recognized.');
		}
		return $candidates->get(0);
	}

	/**
	 * Verifies a WebAuthn assertion against the credential's stored source
	 * record and the single-use challenge, then persists the updated sign
	 * count / last-used time. Returns the updated CredentialRecord.
	 */
	private function _checkAssertion(PublicKeyCredential $pk_credential, Passkey $passkey, string $challenge, string $user_verification = 'preferred'): CredentialRecord {
		$credential_record = $this->serializer->deserialize($passkey->get('pkc_source_json'), CredentialRecord::class, 'json');
		$options = PublicKeyCredentialRequestOptions::create($challenge, $this->rp_id, [], $user_verification);

		$validator = AuthenticatorAssertionResponseValidator::create($this->request_ceremony);
		$credential_record = $validator->check(
			$credential_record,
			$pk_credential->response,
			$options,
			$this->_host(),
			$credential_record->userHandle
		);

		$passkey->set('pkc_source_json', $this->serializer->serialize($credential_record, 'json'));
		$passkey->set('pkc_sign_count', $credential_record->counter);
		$passkey->set('pkc_last_used_time', gmdate('Y-m-d H:i:s'));
		$passkey->save();

		return $credential_record;
	}

	/** The browser-session id ceremony state is keyed by. Ensures the session
	 *  exists (SessionControl starts an anonymous one pre-login). */
	private function _sessionId(): string {
		SessionControl::get_instance();
		$session_id = session_id();
		if (!$session_id) {
			throw new PasskeyException('A browser session is required for passkey ceremonies.');
		}
		return $session_id;
	}

	private function _deleteCeremonyRows(string $kind): void {
		$dblink = DbConnector::get_instance()->get_db_link();
		$stmt = $dblink->prepare('DELETE FROM pks_passkey_ceremonies WHERE pks_session_id = ? AND pks_kind = ?');
		$stmt->execute([$this->_sessionId(), $kind]);
	}

	/** One in-flight ceremony per session: a new stash replaces any pending
	 *  challenge. Expired rows (any session) are swept here too. */
	private function _stashChallenge(string $purpose, string $challenge): void {
		$dblink = DbConnector::get_instance()->get_db_link();
		$dblink->prepare('DELETE FROM pks_passkey_ceremonies WHERE pks_expires_time < ?')
			->execute([gmdate('Y-m-d H:i:s')]);
		$this->_deleteCeremonyRows('challenge');

		$row = new PasskeyCeremony(NULL);
		$row->set('pks_session_id', $this->_sessionId());
		$row->set('pks_kind', 'challenge');
		$row->set('pks_purpose', $purpose);
		$row->set('pks_challenge', base64_encode($challenge));
		$row->set('pks_expires_time', gmdate('Y-m-d H:i:s', time() + self::CHALLENGE_TTL_SECONDS));
		$row->save();
	}

	/** Single-use: deletes the stash before validating, so a replay finds nothing. */
	private function _consumeChallenge(string $expected_purpose): string {
		$rows = new MultiPasskeyCeremony(['session_id' => $this->_sessionId(), 'kind' => 'challenge']);
		$rows->load();
		$stash = $rows->count() ? $rows->get(0) : null;
		$this->_deleteCeremonyRows('challenge');

		if (!$stash || $stash->get('pks_purpose') !== $expected_purpose) {
			throw new PasskeyException('This passkey request has expired or is invalid. Please try again.');
		}
		if ($stash->get('pks_expires_time') < gmdate('Y-m-d H:i:s')) {
			throw new PasskeyException('This passkey request has expired. Please try again.');
		}
		return base64_decode($stash->get('pks_challenge'));
	}
}
?>
