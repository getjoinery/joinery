<?php
/**
 * DirectSigningIdentity - the sending instance's Ed25519 identity, and the
 * custody rules for its secret half.
 *
 * The instance signature is DKIM's job made mandatory: no valid signature, no
 * acceptance. So the key custody deliberately mirrors DKIM's rather than
 * inventing a second model.
 *
 *   - **Box custody** (Standard/Private, and any domain whose owner holds no
 *     vault): the secret key lives at rest under SecretBox, exactly as the
 *     platform's other at-rest secrets do, and is unwrapped per send.
 *   - **Vault custody** (a domain whose owner holds a Sealed Vault): the secret
 *     key is sealed to that owner's vault public key and unwrapped in-window,
 *     per send, then dropped — the same shape `MailboxDkimSigner` already uses
 *     for a protected identity's DKIM key. A locked box cannot sign, which is
 *     the property that makes it worth doing.
 *
 * In both cases the BOX signs. A relay originates the outbound connection so the
 * recipient sees the relay's address and never the box's, but it never holds
 * this key and never signs — the same division `OutboundTransport` already
 * enforces, and the reason the relay stays a pure address-hiding forwarder
 * rather than acquiring a signing identity.
 *
 * The vault-owner lookup is a registered callable rather than a direct mailbox
 * reference, so core never names a plugin symbol (the discipline
 * `MailIdentityGuard` already sets).
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SecretBox.php'));
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php')); // declares VaultLockedException
require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
require_once(PathHelper::getIncludePath('data/direct_identities_class.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectProtocol.php'));

class DirectSigningIdentity {

	/** @var callable|null fn(string $domain): ?int — the vault owner whose key seals this domain's signing key */
	private static $vault_owner_resolver = null;

	/** @var array<string,DirectIdentity|null> request-scoped; signing runs per message */
	private static $cache = array();

	/**
	 * Register "whose vault seals this domain's Direct signing key". Returning
	 * null means box custody. Called once, from the mailbox plugin bootstrap.
	 */
	public static function registerVaultOwnerResolver(callable $fn): void {
		self::$vault_owner_resolver = $fn;
	}

	/** The owner whose vault should hold this domain's key, or null for box custody. */
	private static function vaultOwnerFor(string $domain): ?int {
		VaultUnlock::loadConsumerBootstraps();
		if (self::$vault_owner_resolver === null) {
			return null;
		}
		$owner = call_user_func(self::$vault_owner_resolver, $domain);
		return (is_int($owner) && $owner > 0) ? $owner : null;
	}

	/** The active identity row for a domain, memoized for the request. */
	public static function forDomain(string $domain): ?DirectIdentity {
		$domain = strtolower(trim($domain));
		if ($domain === '') {
			return null;
		}
		if (!array_key_exists($domain, self::$cache)) {
			self::$cache[$domain] = DirectIdentity::activeFor($domain);
		}
		return self::$cache[$domain];
	}

	/** Does this deployment hold a signing identity for $domain? */
	public static function hasIdentity(string $domain): bool {
		return self::forDomain($domain) !== null;
	}

	/**
	 * The key id this domain currently signs with, or ''.
	 *
	 * The key id is part of what the preflight signature covers, so a sender has
	 * to know it before it builds the bytes to sign.
	 */
	public static function keyIdFor(string $domain): string {
		$identity = self::forDomain($domain);
		return $identity === null ? '' : (string)$identity->get('jdi_key_id');
	}

	/**
	 * Create (or return) this domain's signing identity.
	 *
	 * Idempotent: a domain that already has an active identity keeps it, so
	 * calling this from setup, from a provisioner, or from a first send never
	 * rotates a key by accident — rotation is `rotate()`, which is explicit.
	 */
	public static function ensureFor(string $domain): DirectIdentity {
		$domain = strtolower(trim($domain));
		if ($domain === '') {
			throw new InvalidArgumentException('DirectSigningIdentity::ensureFor needs a domain.');
		}
		$existing = self::forDomain($domain);
		if ($existing !== null) {
			return $existing;
		}
		return self::mint($domain);
	}

	/**
	 * Publish a NEW key for $domain alongside the old one.
	 *
	 * The old row stays publishable (and its TXT stays in the plan) until it is
	 * retired, because a sender that cached the capability record may still be
	 * quoting the old key id. That overlap is the whole reason key ids exist —
	 * rotation without a flag day.
	 */
	public static function rotate(string $domain): DirectIdentity {
		$domain = strtolower(trim($domain));
		$previous = self::forDomain($domain);
		$fresh = self::mint($domain);
		if ($previous !== null) {
			$previous->set('jdi_is_active', false);
			$previous->set('jdi_retire_time', gmdate('Y-m-d H:i:s'));
			$previous->save();
		}
		unset(self::$cache[$domain]);
		return $fresh;
	}

	/** Mint and store a keypair under whichever custody applies to $domain. */
	private static function mint(string $domain): DirectIdentity {
		$pair   = sodium_crypto_sign_keypair();
		$secret = sodium_crypto_sign_secretkey($pair);
		$public = sodium_crypto_sign_publickey($pair);

		$row = new DirectIdentity(NULL);
		$row->set('jdi_domain', $domain);
		$row->set('jdi_key_id', substr(bin2hex(random_bytes(8)), 0, 16));
		$row->set('jdi_public_key', base64_encode($public));
		$row->set('jdi_is_active', true);

		$owner_id = self::vaultOwnerFor($domain);
		$owner_public = ($owner_id !== null) ? self::vaultPublicKey($owner_id) : null;
		if ($owner_public !== null) {
			// Vault custody: sealed to the owner, openable only in-window.
			$crypto = new VaultCrypto();
			$row->set('jdi_sealed_secret_key', $crypto->sealItemDek($secret, $owner_public));
			$row->set('jdi_owner_usr_user_id', $owner_id);
		} else {
			$box = new SecretBox();
			$row->set('jdi_secret_key', $box->encrypt(base64_encode($secret)));
		}
		$row->save();

		sodium_memzero($secret);
		unset(self::$cache[$domain]);
		return new DirectIdentity(intval($row->key), TRUE);
	}

	/** The vault public key for a user, or null when they hold no vault. */
	private static function vaultPublicKey(int $user_id): ?string {
		require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
		try {
			$vault = UserEncryptionVault::loadForUser($user_id);
		} catch (\Throwable $e) {
			return null;
		}
		if ($vault === null) {
			return null;
		}
		$public = (string)$vault->get('uev_public_key');
		return $public === '' ? null : $public;
	}

	/**
	 * Sign $message as $domain's instance. Returns ['key_id','signature'] with a
	 * base64 signature, or throws.
	 *
	 * A vault-custody domain with no open unlock window throws
	 * VaultLockedException rather than signing — the send path treats that as a
	 * failure and, for mail, falls back to SMTP, which under Fortress is the
	 * edge-sealing ingest relay. Falling back never drops to a less-protected
	 * path.
	 *
	 * @return array{key_id:string,signature:string}
	 * @throws VaultLockedException|RuntimeException
	 */
	public static function sign(string $domain, string $message): array {
		$identity = self::forDomain($domain);
		if ($identity === null) {
			throw new RuntimeException('No Joinery Direct signing identity for ' . $domain . '.');
		}
		$secret = self::openSecretKey($identity);
		try {
			$signature = sodium_crypto_sign_detached($message, $secret);
		} finally {
			sodium_memzero($secret);
		}
		return array(
			'key_id'    => (string)$identity->get('jdi_key_id'),
			'signature' => base64_encode($signature),
		);
	}

	/** Unwrap the secret half under whichever custody this row uses. */
	private static function openSecretKey(DirectIdentity $identity): string {
		$sealed = (string)$identity->get('jdi_sealed_secret_key');
		if ($sealed !== '') {
			$owner_id = intval($identity->get('jdi_owner_usr_user_id'));
			$vault_secret = ($owner_id > 0) ? VaultUnlock::secretKey($owner_id) : null;
			if ($vault_secret === null) {
				// Locked. The compose path turns this into a one-tap unlock prompt;
				// an ambient send falls back rather than signing in nobody's name.
				throw new VaultLockedException();
			}
			$crypto = new VaultCrypto();
			return $crypto->openItemDek($sealed, $vault_secret);
		}

		$stored = (string)$identity->get('jdi_secret_key');
		if ($stored === '') {
			throw new RuntimeException('Direct signing identity ' . $identity->key . ' holds no secret key.');
		}
		$box = new SecretBox();
		$decoded = base64_decode($box->decrypt($stored), true);
		if ($decoded === false) {
			throw new RuntimeException('Direct signing identity ' . $identity->key . ' is unreadable.');
		}
		return $decoded;
	}

	/**
	 * Verify a signature made by $public_key_b64 over $message.
	 *
	 * Stateless crypto, no vault involved — which is exactly why authentication
	 * can run at receive on a locked box while authorization has to wait for an
	 * unlock window.
	 */
	public static function verify(string $message, string $signature_b64, string $public_key_b64): bool {
		$signature = base64_decode($signature_b64, true);
		$public    = base64_decode($public_key_b64, true);
		if ($signature === false || $public === false
				|| strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
				|| strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
			return false;
		}
		try {
			return sodium_crypto_sign_verify_detached($signature, $message, $public);
		} catch (\Throwable $e) {
			return false;
		}
	}

	/** Forget the request-scoped cache (tests re-mint identities between cases). */
	public static function resetForTests(): void {
		self::$cache = array();
	}
}
