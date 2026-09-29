<?php
/**
 * MailboxRelayPin - the server's half of the relay pin
 * (specs/client_custody_mail.md § R10).
 *
 * Under Seal at the relay, a Fortress mailbox's mail is sealed at the relay to
 * a key only the owner's browsers hold, and the relay learns that key from the
 * map this server pushes. A hacked server could push its own key instead. So
 * the owner's browser asks the relay which key it seals to — the relay signs
 * the answer with its identity key, which this server never holds — and checks
 * it against the relay identity it pinned for the mailbox.
 *
 * The server only carries things: it proxies the relay's statement byte for
 * byte (sealTarget()) and stores the pin the browser made (setPin()). A pin is
 * {relay_identity_public_key, mac}, the MAC made with a key only the owner's
 * `mail` vault derives (VaultKeyring session.mac(), HKDF info
 * 'sealed-vault:pin') over pinMessage(), so a pin written or altered here
 * fails the browser's check. What the server can still do is withhold the
 * check (not list a mailbox, not answer) or delete a pin, which the browser
 * then makes again on first use; the Fortress card says so.
 *
 * @version 1.0
 */

class MailboxRelayPinException extends Exception {}

class MailboxRelayPin {

	/** What the MAC covers, before the alias id and the identity key. */
	const PIN_MESSAGE_PREFIX = "joinery-relay-pin:v1\n";

	/** The bytes a pin's MAC covers: the prefix, the alias id, a newline, the relay identity key. */
	public static function pinMessage(int $alias_id, string $relay_identity_public_key): string {
		return self::PIN_MESSAGE_PREFIX . $alias_id . "\n" . $relay_identity_public_key;
	}

	/**
	 * The relay a pin check goes through: the active relay, reached over its
	 * API, running a program that seals for browsers and signs the seal-target
	 * statement. Null when there is none.
	 */
	public static function relay(): ?MailboxRelay {
		$relay = MailboxRelay::active();
		if ($relay === null || !$relay->usesRelayApi() || !RelayVersion::sealsForBrowsers($relay)
				|| trim((string)$relay->get('mrl_identity_public_key')) === '') {
			return null;
		}
		return $relay;
	}

	/**
	 * $user_id's mailboxes whose mail the relay seals for their browser: the
	 * ones the browser checks. [{alias_id, address}].
	 */
	public static function mailboxesToCheck(int $user_id, ?MailboxRelay $relay = null): array {
		if ($user_id <= 0 || ($relay ?? self::relay()) === null) {
			return array();
		}
		$out = array();
		foreach (InboundEmailMailboxGrant::alias_ids_for_user($user_id) as $alias_id) {
			$alias = self::ownAlias($user_id, intval($alias_id));
			if ($alias !== null) {
				$out[] = array('alias_id' => intval($alias->key), 'address' => $alias->get_full_address());
			}
		}
		return $out;
	}

	/**
	 * The relay's statement for one of $user_id's mailboxes, unread:
	 * {address, relay_answer (the relay's body, byte for byte), relay_identity_public_key
	 * (the identity this server pinned the relay's TLS to, for a first use),
	 * pin (the stored pin, or null)}.
	 *
	 * $relay: the relay to ask, when not the active one (tests).
	 *
	 * @throws MailboxRelayPinException
	 */
	public static function sealTarget(int $user_id, int $alias_id, ?MailboxRelay $relay = null): array {
		$alias = self::ownAlias($user_id, $alias_id);
		$relay = $relay ?? self::relay();
		if ($alias === null || $relay === null) {
			throw new MailboxRelayPinException('This mailbox is not sealed at a relay.');
		}
		$address = $alias->get_full_address();
		try {
			$answer = $relay->withApi(function (RelayClient $c) use ($address) { return $c->sealTarget($address); });
		} catch (\Throwable $e) {
			error_log('MailboxRelayPin: seal-target for mailbox ' . $alias_id . ' failed: ' . $e->getMessage());
			throw new MailboxRelayPinException('The relay could not be asked which key it seals to. Try again later.');
		}
		if ($answer === null) {
			throw new MailboxRelayPinException('The relay does not know this mailbox yet. Try again in a minute.');
		}
		return array(
			'address'                   => $address,
			'relay_answer'              => $answer,
			'relay_identity_public_key' => (string)$relay->get('mrl_identity_public_key'),
			'pin'                       => self::pinOf($alias),
		);
	}

	/** Every pin $user_id's browser made, for a mail key rotation to make again: [{alias_id, relay_identity_public_key, mac}]. */
	public static function pins(int $user_id): array {
		$out = array();
		foreach (InboundEmailMailboxGrant::alias_ids_for_user($user_id) as $alias_id) {
			$alias = new InboundEmailAlias(intval($alias_id), TRUE);
			$pin = ($alias->key && !$alias->get('iea_delete_time')) ? self::pinOf($alias) : null;
			if ($pin !== null) {
				$out[] = array_merge(array('alias_id' => intval($alias->key)), $pin);
			}
		}
		return $out;
	}

	/**
	 * Would storing this pin change which relay the mailbox trusts? A first pin
	 * does not (trust on first use); a new MAC over the same identity (a key
	 * rotation) does not; another identity does, and needs a recent step-up.
	 */
	public static function changesIdentity(int $user_id, int $alias_id, string $relay_identity_public_key): bool {
		$alias = self::grantedAlias($user_id, $alias_id);
		$pin = ($alias !== null) ? self::pinOf($alias) : null;
		return $pin !== null && !hash_equals($pin['relay_identity_public_key'], $relay_identity_public_key);
	}

	/**
	 * Store the pin the browser made for one of $user_id's mailboxes. Nothing
	 * here can check the MAC — only the owner's vault derives its key — so this
	 * checks the shapes and stores it.
	 *
	 * @throws MailboxRelayPinException
	 */
	public static function setPin(int $user_id, int $alias_id, string $relay_identity_public_key, string $mac): void {
		$alias = self::grantedAlias($user_id, $alias_id);
		if ($alias === null) {
			throw new MailboxRelayPinException('That mailbox is not one of yours.');
		}
		$key = base64_decode($relay_identity_public_key, true);
		$m = base64_decode($mac, true);
		if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || $m === false || strlen($m) !== 32) {
			throw new MailboxRelayPinException('That is not a relay pin.');
		}
		InboundEmailAlias::updateColumns(intval($alias->key), array('iea_relay_identity_pin' => json_encode(array(
			'relay_identity_public_key' => $relay_identity_public_key,
			'mac'                       => $mac,
		))));
	}

	/** The stored pin, or null. */
	private static function pinOf(InboundEmailAlias $alias): ?array {
		$pin = json_decode((string)$alias->get('iea_relay_identity_pin'), true);
		if (!is_array($pin) || !is_string($pin['relay_identity_public_key'] ?? null) || !is_string($pin['mac'] ?? null)) {
			return null;
		}
		return array('relay_identity_public_key' => $pin['relay_identity_public_key'], 'mac' => $pin['mac']);
	}

	/** One of $user_id's mailboxes (a live grant), or null. */
	private static function grantedAlias(int $user_id, int $alias_id): ?InboundEmailAlias {
		if ($user_id <= 0 || $alias_id <= 0
				|| !in_array($user_id, InboundEmailMailboxGrant::user_ids_for_alias($alias_id), true)) {
			return null;
		}
		$alias = new InboundEmailAlias($alias_id, TRUE);
		return ($alias->key && !$alias->get('iea_delete_time')) ? $alias : null;
	}

	/** One of $user_id's mailboxes that the relay seals for their browser, or null. */
	private static function ownAlias(int $user_id, int $alias_id): ?InboundEmailAlias {
		$alias = self::grantedAlias($user_id, $alias_id);
		if ($alias === null || !$alias->is_fortress()
				|| InboundEmailMessage::singleOwnerUserId(intval($alias->key)) !== $user_id) {
			return null;
		}
		$domain = new InboundEmailDomain(intval($alias->get('iea_ied_inbound_email_domain_id')), TRUE);
		return ($domain->key && $domain->relay_seals_to_owner()) ? $alias : null;
	}
}
?>
