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
 * @version 1.8
 * @changelog 1.8 - The vendor autoloader loads at file scope: the counter
 *   checker implements a vendor interface at declaration time, so a lean
 *   request (the pre-auth 2FA passkey actions) fataled before the
 *   constructor's require ever ran.
 * @changelog 1.7 - A missing PRF output stamps pkc_prf_failed_time only when
 *   the signed authenticator data carries no PRF/hmac-secret evaluation —
 *   clientExtensionResults are browser-assembled and unsigned, so a stripped
 *   result must not mark a provably-evaluating credential incapable.
 * @changelog 1.6 - Registration always requests PRF and credProps, and records
 *   discoverability/attachment, so a credential's vault capability is known at
 *   enrollment (Passkey::vault_capability()). getDerivationOptions() takes an
 *   optional credential filter so a ceremony can be scoped to one passkey.
 * @changelog 1.5 - Diagnostics: getDiagnosticOptions()/verifyDiagnostic() mint a
 *   parameterized assertion ceremony (UV / PRF / credential subset) for the
 *   superadmin passkey lab; grants nothing on success.
 */

// At file scope, not in the constructor: PasskeyFlaggingCounterChecker below
// implements a vendor interface, so the vendor autoloader must be registered
// the moment this file loads. Leaving it to the constructor made the class
// declaration depend on whether anything EARLIER in the request happened to
// load composer — true on a busy deployment, false on a lean one, where the
// 2FA passkey actions then fatal with "CounterChecker not found".
require_once(PathHelper::getComposerAutoloadPath());

use ParagonIE\ConstantTime\Base64UrlSafe;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticationExtensions\AuthenticationExtensions;
use Webauthn\AuthenticationExtensions\CredentialPropertiesInputExtension;
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

	// 5 minutes, matching the WebAuthn-recommended ceremony timeout: the
	// cross-device flow (QR code + phone) and first-time dialog reading
	// routinely exceed a 2-minute window.
	const CHALLENGE_TTL_SECONDS = 300;
	const STEPUP_MARKER_TTL_SECONDS = 3600;

	/**
	 * PRF contexts a consumer may request: one per registered vault scope, so a
	 * KEK derived for one scope can never unwrap another's key (docs/passkeys.md).
	 *
	 * Computed from VaultScopes rather than listed, because the context is
	 * derived from the scope name — a scope declared in a plugin's plugin.json
	 * is derivable here the moment the plugin is active, and cannot be spelled
	 * in a way that collides with another scope's.
	 */
	public static function allowedPrfContexts(): array {
		require_once(PathHelper::getIncludePath('includes/VaultScopes.php'));
		return VaultScopes::prfContexts();
	}

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

	public function getRegistrationOptions(User $user): array {
		$existing = new MultiPasskey(['user_id' => $user->key]);
		$existing->load();
		$exclude = [];
		foreach ($existing as $passkey) {
			$exclude[] = PublicKeyCredentialDescriptor::create(
				PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
				Base64UrlSafe::decodeNoPadding($passkey->get('pkc_credential_id'))
			);
		}

		// Both extensions, unconditionally, on every enrollment.
		//
		// PRF can only be enabled at creation time and is inert on an
		// authenticator that lacks it, so asking always costs nothing — and it
		// is what makes pkc_prf_capable = false mean one thing forever. Were the
		// request caller-controlled, "did not report PRF" and "was never asked"
		// would be the same stored value, and a caller that forgot the flag
		// would mint credentials that look incapable and are not.
		//
		// credProps reports whether a discoverable (resident) credential was
		// actually created. A CTAP1 fallback cannot make one, so that answer is
		// half the evidence behind Passkey::vault_capability().
		$extensions = AuthenticationExtensions::create([
			PseudoRandomFunctionInputExtensionBuilder::create()->build(),
			CredentialPropertiesInputExtension::enable(),
		]);

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

		// The other two capability signals, both absent-tolerant: a client that
		// reports neither yields nulls, which leave the credential `unknown`
		// (Passkey::vault_capability()). Never inferred — an unreported signal
		// is not a negative one.
		$discoverable = null;
		if (isset($data['clientExtensionResults']['credProps']['rk'])) {
			$discoverable = (bool)$data['clientExtensionResults']['credProps']['rk'];
		}
		$attachment = null;
		if (isset($data['authenticatorAttachment'])
			&& in_array($data['authenticatorAttachment'], ['platform', 'cross-platform'], true)) {
			$attachment = (string)$data['authenticatorAttachment'];
		}

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
		if ($discoverable !== null) {
			$passkey->set('pkc_discoverable', $discoverable);
		}
		if ($attachment !== null) {
			$passkey->set('pkc_attachment', $attachment);
		}
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
		$options = PublicKeyCredentialRequestOptions::create($challenge, $this->rp_id, $allow, 'preferred', 120000);
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
		// UV required, not preferred: a step-up guards a sensitive action, and
		// the required+timeout shape is also the one Windows' credential UI
		// handles reliably with a mixed platform/security-key allow list.
		$options = PublicKeyCredentialRequestOptions::create($challenge, $this->rp_id, $allow, 'required', 120000);
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

	/**
	 * @param int[]|null $credential_ids internal pkc row ids the ceremony is
	 *   restricted to. Null offers every live credential — the historical
	 *   behaviour, and still the right one for a caller with no opinion. A
	 *   caller that DOES have an opinion must pass it, because the browser
	 *   decides which credential answers: an unscoped ceremony run to activate a
	 *   named passkey will happily accept a different one (§ 3.1 of
	 *   specs/passkey_vault_capability_detection.md).
	 *
	 *   An empty array is treated as "no opinion" rather than "offer nothing".
	 *   Minting an empty allowCredentials on the unlock path is a vault lockout,
	 *   so the fallback is deliberate and lives here, at the one place every
	 *   caller passes through, rather than in each of them.
	 */
	public function getDerivationOptions(User $user, string $context, ?array $credential_ids = null): array {
		if (!in_array($context, self::allowedPrfContexts(), true)) {
			throw new PasskeyException('Unknown passkey secret context: ' . $context);
		}

		$filter = null;
		if (is_array($credential_ids) && $credential_ids) {
			$filter = array_map('intval', $credential_ids);
		}

		// Within the caller's subset, every live credential may attempt
		// derivation — pkc_prf_capable is registration-time reporting, which
		// some authenticators (notably Windows Hello) omit while evaluating PRF
		// fine at assertion. The ceremony itself is the real capability test;
		// verifyDerivation() upgrades the flag on the first successful
		// evaluation.
		$creds = new MultiPasskey(['user_id' => $user->key]);
		$creds->load();
		$allow = [];
		foreach ($creds as $passkey) {
			if ($filter !== null && !in_array((int)$passkey->key, $filter, true)) {
				continue;
			}
			$allow[] = PublicKeyCredentialDescriptor::create(
				PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
				Base64UrlSafe::decodeNoPadding($passkey->get('pkc_credential_id'))
			);
		}
		if (!$allow) {
			throw new PasskeyException($filter === null
				? 'No passkeys are enrolled on this account.'
				: 'That passkey is not enrolled on your account.');
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
		$options = PublicKeyCredentialRequestOptions::create($challenge, $this->rp_id, $allow, 'required', 120000, $extensions);
		$this->_stashChallenge('derive:' . $context . ':' . $user->key, $challenge);
		return json_decode($this->serializer->serialize($options, 'json'), true);
	}

	public function verifyDerivation(string $client_response_json, string $context): array {
		if (!in_array($context, self::allowedPrfContexts(), true)) {
			throw new PasskeyException('Unknown passkey secret context: ' . $context);
		}

		$data = json_decode($client_response_json, true);
		if (!is_array($data)) {
			// A body that never parsed says nothing about the credential — this
			// must not share the hardware-limit message or type below.
			throw new PasskeyException('The passkey response could not be read. Please try again.');
		}

		$pk_credential = $this->_decodeAssertionResponse($client_response_json);
		$passkey = $this->_findLivePasskeyByRawId($pk_credential->rawId);
		$user_id = (int)$passkey->get('pkc_usr_user_id');

		$challenge = $this->_consumeChallenge('derive:' . $context . ':' . $user_id);
		// Every PRF context is a Sealed Vault unlock - user verification is
		// enforced here (the validator's CheckUserVerification step), not just
		// requested in the options (getDerivationOptions() asks for 'required' too).
		$this->_checkAssertion($pk_credential, $passkey, $challenge, 'required');

		// The verified assertion proves who tapped, and no more: the signature
		// covers authenticatorData and the clientDataJSON hash, never
		// clientExtensionResults — the browser assembles those. Verifying first
		// still matters (a forged request can't stamp someone else's passkey),
		// but a genuine assertion with stripped or dropped extension results
		// remains possible, so a missing PRF output is client-attested evidence,
		// not authenticator-attested. One corroboration IS signed: a CTAP
		// authenticator that evaluated PRF carries the hmac-secret output inside
		// authenticatorData. When that shows an evaluation happened, the missing
		// result is the client's doing and proves nothing about the credential.
		$prf_output_b64url = $data['clientExtensionResults']['prf']['results']['first'] ?? null;
		if (!$prf_output_b64url) {
			if ($this->_signedPrfEvaluated($pk_credential)) {
				// The credential provably evaluated PRF; the browser dropped the
				// result. A client fault, not a hardware limit — no stamp, and
				// not the unsupported type.
				throw new PasskeyException('Your passkey derived the key, but this browser did not return it. Try again, or use a different browser.');
			}
			if (!$passkey->get('pkc_prf_failed_time')) {
				$passkey->set('pkc_prf_failed_time', gmdate('Y-m-d H:i:s'));
				$passkey->save();
			}
			throw new PasskeyPrfUnsupportedException('This passkey did not return a derived secret. It may not support PRF.');
		}

		$user = new User($user_id, TRUE);
		$prf_output = Base64UrlSafe::decodeNoPadding($prf_output_b64url);

		// Evidence beats registration-time reporting: this credential just
		// evaluated PRF, so correct a false-at-creation capability flag — and
		// clear any earlier failure, which a firmware or OS update can undo.
		if (!$passkey->get('pkc_prf_capable') || $passkey->get('pkc_prf_failed_time')) {
			$passkey->set('pkc_prf_capable', true);
			$passkey->set('pkc_prf_failed_time', null);
			$passkey->save();
		}

		return [$user, $passkey, $prf_output];
	}

	// ========================================================================
	// Diagnostics (superadmin passkey lab)
	// ========================================================================

	/**
	 * Mints an assertion-ceremony options payload whose shape is fully caller-
	 * chosen, so a misbehaving browser/authenticator combination can be
	 * isolated one variable at a time (adm/admin_passkey_lab.php). Uses the
	 * same building blocks as the real ceremonies. Completing the ceremony
	 * grants nothing - verifyDiagnostic() sets no step-up marker.
	 *
	 * $variant keys (all optional):
	 *   'uv'             => 'required' (default) | 'preferred'
	 *   'prf'            => bool - attach a throwaway PRF eval input
	 *   'credential_ids' => base64url pkc_credential_id whitelist; empty = all live
	 */
	public function getDiagnosticOptions(User $user, array $variant = []): array {
		$uv = ($variant['uv'] ?? 'required') === 'preferred' ? 'preferred' : 'required';
		$whitelist = array_values(array_filter((array)($variant['credential_ids'] ?? []), 'is_string'));

		$creds = new MultiPasskey(['user_id' => $user->key]);
		$creds->load();
		$allow = [];
		foreach ($creds as $passkey) {
			if ($whitelist && !in_array($passkey->get('pkc_credential_id'), $whitelist, true)) {
				continue;
			}
			$allow[] = PublicKeyCredentialDescriptor::create(
				PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
				Base64UrlSafe::decodeNoPadding($passkey->get('pkc_credential_id'))
			);
		}
		if (!$allow) {
			throw new PasskeyException('No passkeys match this variant.');
		}

		$extensions = null;
		if (!empty($variant['prf'])) {
			$extensions = AuthenticationExtensions::create([
				PseudoRandomFunctionInputExtensionBuilder::create()
					->withInputs($this->_prfSalt('lab-diagnostic'))
					->build(),
			]);
		}

		$challenge = random_bytes(32);
		$options = PublicKeyCredentialRequestOptions::create($challenge, $this->rp_id, $allow, $uv, 120000, $extensions);
		$this->_stashChallenge('lab:' . $user->key, $challenge);
		return json_decode($this->serializer->serialize($options, 'json'), true);
	}

	/**
	 * Verifies a diagnostic assertion. Signature/origin/challenge checks are
	 * real; user verification is checked as 'preferred' because the lab
	 * deliberately mints UV-preferred variants. No marker, no side effects
	 * beyond the credential's routine sign-count/last-used update.
	 *
	 * @return array{credential_id:int, label:string, prf_returned:bool}
	 */
	public function verifyDiagnostic(string $client_response_json, User $user): array {
		$challenge = $this->_consumeChallenge('lab:' . $user->key);
		$pk_credential = $this->_decodeAssertionResponse($client_response_json);
		$passkey = $this->_findLivePasskeyByRawId($pk_credential->rawId);

		if ((int)$passkey->get('pkc_usr_user_id') !== (int)$user->key) {
			throw new PasskeyException('This passkey does not belong to your account.');
		}

		$this->_checkAssertion($pk_credential, $passkey, $challenge);

		$data = json_decode($client_response_json, true);
		return [
			'credential_id' => (int)$passkey->key,
			'label' => (string)$passkey->get('pkc_label'),
			'prf_returned' => !empty($data['clientExtensionResults']['prf']['results']['first']),
		];
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
			call_user_func($callback, (int)$actor->key, $credential_id, []);
		}

		$passkey->soft_delete();

		foreach (self::$post_revoke_callbacks as $callback) {
			call_user_func($callback, (int)$actor->key, $credential_id);
		}
	}

	/**
	 * Superadmin-initiated revocation of another user's credential. Runs the
	 * same pre/post-revoke registries as revoke(); pre-revoke callbacks receive
	 * ['admin_reset' => true] so policy vetoes that a forced reset may
	 * knowingly accept (the possession-factor invariant) can distinguish
	 * themselves from vetoes that are absolute (the stranding floor).
	 * $acting_admin is logged, never authorized here - the caller gates.
	 */
	public function adminRevoke(int $credential_id, User $target, User $acting_admin): void {
		$passkey = $this->_loadOwnedPasskey($credential_id, $target);

		foreach (self::$pre_revoke_callbacks as $callback) {
			call_user_func($callback, (int)$target->key, $credential_id, ['admin_reset' => true]);
		}

		$passkey->soft_delete();

		foreach (self::$post_revoke_callbacks as $callback) {
			call_user_func($callback, (int)$target->key, $credential_id);
		}

		error_log('[ADMIN_2FA_RESET] action=admin_remove_passkey admin=' . (int)$acting_admin->key
			. ' target=' . (int)$target->key . ' credential=' . $credential_id . ' result=done');
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
	 * revoke() and adminRevoke(). A callback throws
	 * PasskeyRevocationVetoException to block the revocation; the message
	 * surfaces to the user. Used when the platform signal bus cannot veto
	 * synchronously (it can't - see docs/signals.md).
	 *
	 * Signature: function (int $user_id, int $credential_id, array $context).
	 * $context is empty for a self-service revoke() and ['admin_reset' => true]
	 * for adminRevoke(), letting a callback distinguish a veto that a forced
	 * administrative reset may knowingly accept from one that is absolute. A
	 * two-parameter callback keeps working - PHP passes the extra argument to a
	 * user-defined callable without complaint.
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

	/**
	 * Whether the SIGNED authenticator data says PRF was evaluated. CTAP
	 * authenticators return the hmac-secret output inside authenticatorData
	 * (encrypted, so unusable as the secret — but its presence is
	 * authenticator-attested proof of capability). Platform authenticators may
	 * carry nothing here, so false means "no signed evidence", not "incapable".
	 */
	private function _signedPrfEvaluated(PublicKeyCredential $pk_credential): bool {
		$extensions = $pk_credential->response->authenticatorData->extensions ?? null;
		if ($extensions === null) {
			return false;
		}
		return $extensions->has('hmac-secret') || $extensions->has('prf');
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
