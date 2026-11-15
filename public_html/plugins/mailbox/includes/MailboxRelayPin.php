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
 * @version 1.3.1 - updateFor() keeps a dismissed run; trustBox() counts only mailboxes pinned elsewhere and
 *                  offers an admin with none of their own the count of others (reviewer2 B1, B2, Q2)
 * @version 1.3 - sealTarget()'s answer carries 'update', the site's account of the run that gave the relay its
 *                identity; fingerprint(), the code people compare; trustBox(), the Relay section's trust box
 *                (specs/relay_update_trust_step.md R1, R4, R6)
 * @version 1.2 - sealTarget() passes the browser's challenge to the relay
 * @version 1.1 - setPin() writes the pin as rule_checked_write(): grantedAlias() checked the caller holds the mailbox
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
	 * $challenge: the browser's, for the relay to sign into the statement
	 * (base64url, 22-64 characters), or '' for none.
	 * $relay: the relay to ask, when not the active one (tests).
	 *
	 * @throws MailboxRelayPinException
	 */
	public static function sealTarget(int $user_id, int $alias_id, ?MailboxRelay $relay = null,
			string $challenge = ''): array {
		if ($challenge !== '' && !preg_match('/^[A-Za-z0-9_-]{22,64}$/', $challenge)) {
			throw new MailboxRelayPinException('That is not a challenge this check can pass to the relay.');
		}
		$alias = self::ownAlias($user_id, $alias_id);
		$relay = $relay ?? self::relay();
		if ($alias === null || $relay === null) {
			throw new MailboxRelayPinException('This mailbox is not sealed at a relay.');
		}
		$address = $alias->get_full_address();
		try {
			$answer = $relay->withApi(function (RelayClient $c) use ($address, $challenge) { return $c->sealTarget($address, $challenge); });
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
			'update'                    => self::updateFor($relay),
		);
	}

	/**
	 * The site's account of how $relay came by its identity: the provision or
	 * update run that produced it, whatever its status, or null when none did.
	 * {relay_id, run_id, kind, status, started_by, started_time, done_time,
	 * identity_public_key}, times as UTC ISO 8601. The owner's browser shows it
	 * as what "this site says"; it is never a trust input
	 * (specs/relay_update_trust_step.md R2, R6).
	 */
	public static function updateFor(MailboxRelay $relay): ?array {
		$identity = (string)$relay->get('mrl_identity_public_key');
		if ($identity === '') {
			return null;
		}
		// A dismissed run (soft-deleted from the Relay section) is still the
		// site's record of how the relay came by its identity.
		$runs = new MultiRelayCloudProvision(array('identity_public_key' => $identity),
			array('rcl_relay_cloud_provision_id' => 'DESC'), 1);
		foreach ($runs as $run) {
			$by = '';
			$uid = intval($run->get('rcl_usr_user_id_started'));
			if ($uid > 0) {
				$user = new User($uid, TRUE);
				if ($user->key && !$user->get('usr_delete_time')) {
					$by = (string)$user->display_name();
				}
			}
			$iso = function ($t) { return $t ? gmdate('Y-m-d\\TH:i:s\\Z', strtotime($t . ' UTC')) : null; };
			return array(
				'relay_id'            => intval($relay->key),
				'run_id'              => intval($run->key),
				'kind'                => (string)$run->get('rcl_kind'),
				'status'              => (string)$run->get('rcl_status'),
				'started_by'          => $by,
				'started_time'        => $iso($run->get('rcl_create_time')),
				'done_time'           => $iso($run->get('rcl_done_time')),
				'identity_public_key' => $identity,
			);
		}
		return null;
	}

	/**
	 * A relay identity as people compare it: SHA-256 over the raw Ed25519
	 * public key, its first 16 bytes as hex in groups of four. The same string
	 * the mail page's popup, the provider console's login screen
	 * (provision_relay.sh) and the Relay section show
	 * (specs/relay_update_trust_step.md R4). '' for a key that does not decode.
	 */
	public static function fingerprint(string $public_key_b64): string {
		$raw = base64_decode(strtr(trim($public_key_b64), '-_', '+/'), true);
		if ($raw === false || $raw === '') {
			return '';
		}
		return implode(' ', str_split(substr(hash('sha256', $raw), 0, 32), 4));
	}

	/**
	 * The Relay section's trust box for $user_id, or null when it has nothing
	 * to offer: the run that gave the active relay its identity is done and a
	 * mailbox under the relay is pinned to another identity, the user's own or
	 * someone else's. A mailbox with no pin yet is not counted: its first
	 * check pins without asking. {relay_id, run_id, code, mine (the user's
	 * mailboxes pinned elsewhere), others (how many other people's are)}.
	 */
	public static function trustBox(int $user_id, ?MailboxRelay $relay = null): ?array {
		$relay = $relay ?? self::relay();
		if ($relay === null || $user_id <= 0) {
			return null;
		}
		$update = self::updateFor($relay);
		if ($update === null || $update['status'] !== 'done') {
			return null;
		}
		$identity = $update['identity_public_key'];
		$mine = array();
		foreach (self::mailboxesToCheck($user_id, $relay) as $box) {
			$pin = self::pinOf(new InboundEmailAlias($box['alias_id'], TRUE));
			if ($pin !== null && $pin['relay_identity_public_key'] !== $identity) {
				$mine[] = $box;
			}
		}
		$others = 0;
		$aliases = new MultiInboundEmailAlias(array('deleted' => false));
		foreach ($aliases as $alias) {
			if (!$alias->is_fortress()) continue;
			$owner = InboundEmailMessage::singleOwnerUserId(intval($alias->key));
			if ($owner <= 0 || $owner === $user_id || self::ownAlias($owner, intval($alias->key)) === null) continue;
			$pin = self::pinOf($alias);
			if ($pin !== null && $pin['relay_identity_public_key'] !== $identity) $others++;
		}
		if (!$mine && $others === 0) {
			return null;
		}
		return array(
			'relay_id' => intval($relay->key),
			'run_id'   => $update['run_id'],
			'code'     => self::fingerprint($identity),
			'mine'     => $mine,
			'others'   => $others,
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
		// The same mailbox the pin is read for: the caller's own sealed mailbox.
		$alias = self::ownAlias($user_id, $alias_id);
		if ($alias === null) {
			throw new MailboxRelayPinException('That mailbox is not one of yours.');
		}
		$key = base64_decode($relay_identity_public_key, true);
		$m = base64_decode($mac, true);
		if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || $m === false || strlen($m) !== 32) {
			throw new MailboxRelayPinException('That is not a relay pin.');
		}
		// ownAlias() above: the caller is this sealed mailbox's one owner.
		SystemBase::rule_checked_write(function () use ($alias, $relay_identity_public_key, $mac) {
			InboundEmailAlias::updateColumns(intval($alias->key), array('iea_relay_identity_pin' => json_encode(array(
				'relay_identity_public_key' => $relay_identity_public_key,
				'mac'                       => $mac,
			))));
		});
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
