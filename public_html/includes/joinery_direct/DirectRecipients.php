<?php
/**
 * DirectRecipients - "who is user@thisdomain, and what does answering them
 * disclose?"
 *
 * The framework needs four facts about a delivery's recipient and must never
 * name a plugin symbol to get them, the same discipline `MailIdentityGuard`
 * sets: does this instance host the domain at all; does that domain seal content
 * (which decides whether the gate runs live or defers); whose consent and whose
 * vault the delivery hangs off; and the vault public key to answer the preflight
 * with. A plugin that hosts addresses registers a resolver; with none registered
 * this instance hosts nobody and every preflight refuses at request level.
 *
 * The resolver answers about the DOMAIN even when the local part does not exist,
 * because the sealed tiers have to answer identically for a real address and a
 * made-up one — the decoy key is derived from the domain's own secret, and a
 * resolver that could only speak about addresses it recognised would have leaked
 * existence before the decoy ever ran.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectProtocol.php'));

class DirectRecipients {

	/** @var callable|null fn(string $address): ?array */
	private static $resolver = null;

	/**
	 * Register the address resolver. It returns null when this instance does not
	 * host the address's DOMAIN, and otherwise an array:
	 *
	 *   hosts_domain     bool   always true when non-null
	 *   domain_id        int
	 *   seals_content    bool   Private or Fortress — the posture switch
	 *   exists           bool   is there a live mailbox for this local part
	 *   user_id          int    whose consent gates it, 0 when none resolves
	 *   alias_id         int
	 *   vault_public_key ?string  b64url, null when the recipient holds no vault
	 *   key_generation   int
	 */
	public static function registerResolver(callable $fn): void {
		self::$resolver = $fn;
	}

	/** True when some plugin is prepared to answer for hosted addresses. */
	public static function hasResolver(): bool {
		require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
		VaultUnlock::loadConsumerBootstraps();
		return self::$resolver !== null;
	}

	/**
	 * What this instance knows about $address, or null when it does not host the
	 * domain — which is a request-level refusal, not one of the gate's two
	 * answers, because "we do not host this domain" is a fact about the
	 * deployment rather than about any recipient.
	 */
	public static function resolve(string $address): ?array {
		if (!self::hasResolver()) {
			return null;
		}
		$address = strtolower(trim($address));
		if ($address === '' || DirectProtocol::domainOf($address) === '') {
			return null;
		}
		try {
			$resolved = call_user_func(self::$resolver, $address);
		} catch (\Throwable $e) {
			error_log('DirectRecipients: resolver failed for ' . $address . ': ' . $e->getMessage());
			return null;
		}
		if (!is_array($resolved) || empty($resolved['hosts_domain'])) {
			return null;
		}
		return array(
			'hosts_domain'     => true,
			'domain_id'        => (int)($resolved['domain_id'] ?? 0),
			'seals_content'    => !empty($resolved['seals_content']),
			'exists'           => !empty($resolved['exists']),
			'user_id'          => (int)($resolved['user_id'] ?? 0),
			'alias_id'         => (int)($resolved['alias_id'] ?? 0),
			'vault_public_key' => isset($resolved['vault_public_key']) && $resolved['vault_public_key'] !== ''
				? (string)$resolved['vault_public_key'] : null,
			'key_generation'   => (int)($resolved['key_generation'] ?? 0),
		);
	}

	/** Drop the registered resolver. Tests only. */
	public static function resetForTests(): void {
		self::$resolver = null;
	}
}
