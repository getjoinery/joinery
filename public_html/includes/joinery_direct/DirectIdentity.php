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
 * @version 1.2 - ensureCustody(): a key whose custody no longer matches its domain moves
 *   into the right one in place, keeping its key id (security_weak_spots_2026_11 WP1);
 *   rotate() erases a retired key's secret
 * @version 1.1.1 - comment wording: the sealed tier is Private; relay-fronted is a topology
 * @version 1.1 - mint() refuses a domain the deployment is not authoritative
 *   for, via a registered resolver (specs/imap_source_domain_boundaries.md)
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

	/** @var callable|null fn(string $domain): bool — may this deployment sign for the domain at all? */
	private static $authority_resolver = null;

	/** @var callable|null fn(string $domain): ?int — the domain's owner, whatever its level */
	private static $domain_owner_resolver = null;

	/** @var array<string,bool> domains whose custody sign() has checked this request */
	private static $custody_checked = array();

	/** @var array<string,DirectIdentity|null> request-scoped; signing runs per message */
	private static $cache = array();

	/**
	 * Register "whose vault seals this domain's Direct signing key". Returning
	 * null means box custody. Called once, from the mailbox plugin bootstrap.
	 */
	public static function registerVaultOwnerResolver(callable $fn): void {
		self::$vault_owner_resolver = $fn;
	}

	/**
	 * Register "is this deployment authoritative for $domain". Guarding here, at
	 * the mint, is what keeps a signing identity from ever existing for a domain
	 * this deployment merely mirrors (an IMAP-source anchor like gmail.com) —
	 * whatever future caller asks for one (specs/imap_source_domain_boundaries.md
	 * § 4). Same registered-callable shape as the vault-owner resolver, so core
	 * never names a plugin symbol. Unregistered means allow.
	 */
	public static function registerAuthorityResolver(callable $fn): void {
		self::$authority_resolver = $fn;
	}

	/**
	 * Register "who owns this domain", whatever its level. ensureCustody() uses
	 * it to tell a lowering (the holder still owns the domain and will be back)
	 * from a change of owner (the old holder will not, so the key is replaced).
	 */
	public static function registerDomainOwnerResolver(callable $fn): void {
		self::$domain_owner_resolver = $fn;
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
	 * rotates a key by accident. A key in the wrong custody for its domain
	 * today is moved there by ensureCustody().
	 */
	public static function ensureFor(string $domain): DirectIdentity {
		$domain = strtolower(trim($domain));
		if ($domain === '') {
			throw new InvalidArgumentException('DirectSigningIdentity::ensureFor needs a domain.');
		}
		$existing = self::forDomain($domain);
		if ($existing !== null) {
			return self::ensureCustody($domain) ?? $existing;
		}
		// The site's own signing key, made the first time anything needs it:
		// no person is its author, whoever's request got here first.
		return SystemBase::server_initiated_write(function () use ($domain) { return self::mint($domain); });
	}

	/**
	 * Keep an existing key in the custody its domain calls for today.
	 *
	 * Custody is chosen when a key is minted, and a domain can change after
	 * that: raised to Private, lowered from it, given to another owner, or its
	 * owner can set up a vault. A key left box-held after a raise could sign in
	 * the domain's name with nobody signed in, which is what vault custody
	 * exists to prevent; one left vault-held after a lowering makes every send
	 * while the owner is signed out fall back to SMTP.
	 *
	 * The key moves in place, keeping its key id, so the published TXT record
	 * stays right and nothing has to be republished in DNS:
	 *   - box to vault (a raise): the box copy is sealed to the owner and then
	 *     erased. Sealing needs only the owner's public key, so this happens at
	 *     once, from any session.
	 *   - vault to box (a lowering): the secret is opened with the holder's
	 *     open window (or $secret, when sign() just opened it). With no window
	 *     it stays where it is until one is open; nothing is lost meanwhile,
	 *     sends fall back to SMTP.
	 * A key whose holder no longer owns the domain, or whose vault is gone (its
	 * owner was deleted), is replaced by a new key in the right custody: the
	 * holder will not be back to move it. The domain's DNS plan then shows the
	 * new TXT to publish.
	 *
	 * Lookups that fail throw rather than read as "no vault": a database error
	 * must never move a Private domain's key to the box. A domain this
	 * deployment is not authoritative for is left alone, since no key can be
	 * minted for it.
	 *
	 * Returns the identity when it changed, null when nothing moved.
	 */
	public static function ensureCustody(string $domain, ?string $secret = null): ?DirectIdentity {
		$domain = strtolower(trim($domain));
		$active = ($domain === '') ? null : DirectIdentity::activeFor($domain);
		if ($active === null) {
			return null;
		}
		VaultUnlock::loadConsumerBootstraps();
		if (self::$vault_owner_resolver === null) {
			// Nothing can say whose vault should hold the key (the mailbox is
			// not loaded): "could not ask" must not read as "box custody".
			return null;
		}
		if (self::$authority_resolver !== null && !call_user_func(self::$authority_resolver, $domain)) {
			return null;
		}

		$vault_held = ((string)$active->get('jdi_sealed_secret_key') !== '');
		$held_by = $vault_held ? intval($active->get('jdi_owner_usr_user_id')) : null;
		$owner_id = self::vaultOwnerFor($domain);
		$owner_public = ($owner_id !== null) ? self::vaultPublicKey($owner_id) : null;
		$wanted = ($owner_public !== null) ? $owner_id : null;
		if ($held_by === $wanted) {
			return null;
		}

		$replace = $vault_held && ($held_by <= 0 || self::vaultPublicKey($held_by) === null);   // its vault is gone
		if ($vault_held && !$replace && self::$domain_owner_resolver !== null) {
			// The holder no longer owns the domain: a former owner will not be
			// signing in to move it, so waiting for their window would leave
			// every send falling back to SMTP for good.
			$domain_owner = call_user_func(self::$domain_owner_resolver, $domain);
			$replace = (intval($domain_owner) !== $held_by);
		}
		if ($replace) {
			return SystemBase::server_initiated_write(function () use ($domain) { return self::rotate($domain); });
		}

		if ($vault_held) {
			if ($secret === null) {
				$vault_key = VaultUnlock::secretKey($held_by);
				if ($vault_key === null) {
					return null;   // moved when the holder next has a window open
				}
				$secret = (new VaultCrypto())->openItemDek((string)$active->get('jdi_sealed_secret_key'), $vault_key);
			}
		} else {
			$secret = self::openSecretKey($active);
		}

		try {
			if ($wanted !== null) {
				$active->set('jdi_sealed_secret_key', (new VaultCrypto())->sealItemDek($secret, $owner_public));
				$active->set('jdi_owner_usr_user_id', $wanted);
				$active->set('jdi_secret_key', null);
			} else {
				$active->set('jdi_secret_key', (new SecretBox())->seal('jdi_direct_identities.jdi_secret_key', base64_encode($secret)));
				$active->set('jdi_sealed_secret_key', null);
				$active->set('jdi_owner_usr_user_id', null);
			}
		} finally {
			sodium_memzero($secret);
		}
		// The site's own key, moved because its domain changed: no person is
		// its author, whoever saved the change.
		SystemBase::server_initiated_write(function () use ($active) { $active->save(); });
		unset(self::$cache[$domain]);
		return new DirectIdentity(intval($active->key), TRUE);
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
			// Only the active row signs (sign()), so a retired key keeps its
			// public half for verifiers and loses its secret at once: a copy
			// left behind could still sign under a key id DNS publishes.
			$previous->set('jdi_is_active', false);
			$previous->set('jdi_retire_time', gmdate('Y-m-d H:i:s'));
			$previous->set('jdi_secret_key', null);
			$previous->set('jdi_sealed_secret_key', null);
			$previous->save();
		}
		unset(self::$cache[$domain]);
		return $fresh;
	}

	/** Mint and store a keypair under whichever custody applies to $domain. */
	private static function mint(string $domain): DirectIdentity {
		VaultUnlock::loadConsumerBootstraps();
		if (self::$authority_resolver !== null
				&& !call_user_func(self::$authority_resolver, $domain)) {
			throw new RuntimeException('This deployment is not authoritative for '
				. $domain . ', so no Joinery Direct signing identity may exist for it.');
		}
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
			$row->set('jdi_secret_key', $box->seal('jdi_direct_identities.jdi_secret_key', base64_encode($secret)));
		}
		$row->save();

		sodium_memzero($secret);
		unset(self::$cache[$domain]);
		return new DirectIdentity(intval($row->key), TRUE);
	}

	/** The vault public key for a user, or null when they hold no vault. */
	private static function vaultPublicKey(int $user_id): ?string {
		require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
		// A failed lookup throws: read as "no vault" it would pick box custody.
		$vault = UserEncryptionVault::loadForUser($user_id);
		if ($vault === null) {
			return null;
		}
		// The key new material seals to: the pending one during a client-custody
		// rotation, so nothing sealed now is left on the key it retires.
		$public = $vault->sealingPublicKey();
		return $public === '' ? null : $public;
	}

	/**
	 * Sign $message as $domain's instance. Returns ['key_id','signature'] with a
	 * base64 signature, or throws.
	 *
	 * A vault-custody domain with no open unlock window throws
	 * VaultLockedException rather than signing — the send path treats that as a
	 * failure and, for mail, falls back to SMTP, which on a relay-fronted
	 * deployment is the edge-sealing ingest relay. Falling back never drops to a less-protected
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
			if ((string)$identity->get('jdi_sealed_secret_key') !== '' && empty(self::$custody_checked[$domain])) {
				// The secret is open now, which is the moment a vault-held key
				// waiting to move (a lowered domain) can move. Once per domain
				// per request, so a bulk send asks once.
				self::$custody_checked[$domain] = true;
				try {
					self::ensureCustody($domain, $secret);
				} catch (\Throwable $e) {
					error_log('DirectSigningIdentity: could not move the key of ' . $domain . ': ' . $e->getMessage());
				}
			}
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
			$vault_key = ($owner_id > 0) ? VaultUnlock::secretKey($owner_id) : null;
			if ($vault_key === null) {
				// Locked. The compose path turns this into a one-tap unlock prompt;
				// an ambient send falls back rather than signing in nobody's name.
				throw new VaultLockedException();
			}
			$crypto = new VaultCrypto();
			return $crypto->openItemDek($sealed, $vault_key);
		}

		$stored = (string)$identity->get('jdi_secret_key');
		if ($stored === '') {
			throw new RuntimeException('Direct signing identity ' . $identity->key . ' holds no secret key.');
		}
		$box = new SecretBox();
		$opened = $box->open($stored);
		$decoded = $opened['value'] === null ? false : base64_decode($opened['value'], true);
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
