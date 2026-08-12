<?php
/**
 * DirectContactGate - the canned authorization every consent-based kind can
 * declare instead of writing its own.
 *
 * The whitelist is the address book you already have. A payload rides the direct
 * path to someone's inbox only if the sender is in that recipient's contacts —
 * not a new idiom, and no new store: `imc_mailbox_contacts` already holds it,
 * and "add sender to your contacts" is something every mail user already knows.
 *
 * Consent lives at PER-PERSON granularity. Trusting a whole domain would hand
 * access to whichever account on it gets compromised next week.
 *
 * The match is on the full sender address **and** a sending domain bound to the
 * verified instance signature, never the bare address alone. A contact entry for
 * alice@example.com is satisfied only by a message signed by example.com's
 * instance key, so a spoofed From cannot borrow someone else's place in your
 * contacts.
 *
 * Blocking needs no branch here. A block removes the contact, so a blocked
 * sender already fails this check and gets the same answer as any stranger; what
 * the block adds is a `mark_spam` filter rule that files the ensuing SMTP
 * message. There is deliberately no gate-time block lookup — that would need a
 * block index readable while locked, which the security-levels design rejects.
 *
 * Because a plugin supplies the actual contact lookup, the gate is a registered
 * callable rather than a direct reference: core never names a plugin symbol.
 * With nothing registered the gate declines, which is the safe direction.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectEnvelope.php'));

class DirectContactGate {

	/** @var callable|null fn(int $user_id, int $alias_id, string $address): bool */
	private static $lookup = null;

	/**
	 * Register the contact lookup. It answers "is $address in this user's
	 * contacts for this mailbox" and must return false — never throw — when the
	 * list cannot be read.
	 */
	public static function registerLookup(callable $fn): void {
		self::$lookup = $fn;
	}

	/** Does this recipient accept this sender? */
	public static function allows(DirectEnvelope $envelope): bool {
		require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
		VaultUnlock::loadConsumerBootstraps();
		if (self::$lookup === null) {
			return false;
		}

		// Address and signing domain must agree, or the address is not identity.
		if (!$envelope->senderIsAligned()) {
			return false;
		}
		// Consent is per-mailbox: a personal mailbox gates on its single owner's
		// contacts, a shared mailbox (no single owner) on the alias's own book,
		// where an entry any grantee added counts. The lookup interprets a zero
		// user id as the shared case; without either handle there is nothing to
		// authorize against.
		$user_id  = $envelope->recipientUserId();
		$alias_id = $envelope->recipientAliasId();
		if ($user_id <= 0 && $alias_id <= 0) {
			return false;
		}

		try {
			return (bool)call_user_func(self::$lookup, $user_id, $alias_id, $envelope->sender());
		} catch (\Throwable $e) {
			error_log('DirectContactGate: contact lookup failed: ' . $e->getMessage());
			return false;
		}
	}

	/** Drop the registered lookup. Tests only. */
	public static function resetForTests(): void {
		self::$lookup = null;
	}
}
