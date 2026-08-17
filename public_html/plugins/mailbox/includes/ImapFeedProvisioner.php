<?php
/**
 * ImapFeedProvisioner — the one place a pulled-in mailbox comes into being
 * (specs/mailbox_connect_flow.md § B).
 *
 * A pulled-in mailbox is four rows that only make sense together: the provider
 * domain (gmail.com, flagged as an IMAP source), the store-mode alias under it,
 * the grant naming who reads it, and the feed that fetches for it. Building
 * them was inline in the combined editor, which is why consent could not create
 * a mailbox — there was nowhere else to ask. Both paths call this instead, so
 * there is exactly one way a pulled-in mailbox exists.
 *
 * Built ON the headless helpers in includes/provisioning.php rather than beside
 * them: mailbox_provision_domain() / mailbox_provision_mailbox() already
 * find-or-create a domain, alias and grant idempotently, and a second
 * implementation of that is how "one creation path" dies on day one.
 *
 * The whole call is idempotent. Re-running it after a partial failure — or
 * after a reconnect — reuses what exists and finishes the rest.
 *
 * @version 1.1
 * @changelog 1.1 - the provider domain is shaped in its creating save (retry
 *   after a partial failure finds it correct); the level only ever raises, and
 *   only when the ceremony's own grant-and-vault rule passes right now
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/provisioning.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_account_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));
require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Token.php'));

/** Provisioning could not complete; the message is fit to show an operator. */
class ImapFeedProvisionException extends Exception {}

class ImapFeedProvisioner {

	/**
	 * Bring a pulled-in mailbox into being for $address, connected if a token is
	 * in hand.
	 *
	 * $provider_key is an InboundImapAccount::PRESETS key ('imap_gmail'), which
	 * is what decides host, port and auth method — the OAuth provider is derived
	 * from it, never passed alongside, so the two cannot disagree.
	 *
	 * $intent is what the operator chose before signing in:
	 *   reader_user_id  (int)     who reads this mailbox — required
	 *   security_level  (?string) 'private' raises a provider-domain mailbox
	 *                             (when the raise is safe — see below); anything
	 *                             else leaves the mailbox as it is
	 *   import_scope / import_days — optional, the configure step's job normally
	 *
	 * The feed is created DISABLED. It starts fetching when the operator
	 * finishes the configure step, so an abandoned flow leaves a mailbox that is
	 * visibly not enabled rather than one quietly pulling mail nobody asked for.
	 *
	 * $notes collects anything the operator should be told about a choice that
	 * could not be honored as asked — a Private intent left at Standard, and why.
	 * Provisioning still succeeds; the note is the statement of the difference.
	 *
	 * @throws ImapFeedProvisionException
	 */
	public static function provision(string $provider_key, string $address, array $intent,
			?OAuth2Token $token = null, ?array &$notes = null): InboundImapAccount {
		$notes = $notes ?? array();

		$address = strtolower(trim($address));
		$at = strrpos($address, '@');
		if ($at === false || $at === 0 || $at === strlen($address) - 1) {
			throw new ImapFeedProvisionException(
				'"' . $address . '" is not an email address, so there is no mailbox to make from it.');
		}
		$local_part  = substr($address, 0, $at);
		$domain_name = substr($address, $at + 1);

		if (!isset(InboundImapAccount::PRESETS[$provider_key])) {
			$provider_key = 'imap_generic';
		}
		$preset = InboundImapAccount::PRESETS[$provider_key];

		$reader_user_id = intval($intent['reader_user_id'] ?? 0);
		if ($reader_user_id <= 0) {
			throw new ImapFeedProvisionException(
				'A mailbox needs someone to read it — choose who this one belongs to.');
		}

		// A domain this deployment already hosts is reused as it is: that domain
		// IS an identity we hold, so its mailboxes inherit its protection. A
		// domain that does not exist yet is created AS an IMAP source, in its
		// creating save — mail is pulled in per mailbox, there is no MX to point
		// here, and it makes no protection claim of its own (§ D). Shaping it at
		// birth, not afterwards, is what keeps a retry after a partial failure
		// from finding a half-made, hosted-looking gmail.com and believing it.
		$provisioned = mailbox_provision_mailbox($domain_name, $local_part, $reader_user_id, true);
		if ($provisioned['error'] !== null) {
			throw new ImapFeedProvisionException($provisioned['error']);
		}
		$domain = $provisioned['domain'];
		$alias  = $provisioned['alias'];

		// The level LAST, and only on a provider domain: the grant above is what
		// gives it a key to seal to, and writing the level first would be asking
		// the invariant to pass before its precondition exists. A mailbox on a
		// domain this deployment hosts inherits, and is not overridden here.
		//
		// This RAISES only, and only when the raise is safe right now. It never
		// lowers: a mailbox that is already Private stays Private whatever the
		// intent says, because lowering is the ceremony's job — it runs the
		// unseal pass and issues the receipt, and nothing here can. And a raise
		// that the ceremony would refuse — several holders, a holder without a
		// vault, mail already stored unsealed — is not made behind its back
		// either: the mailbox is left as it is and the difference is stated, so
		// the operator finishes the raise where the ceremony runs.
		if ($domain->get('ied_is_imap_source')
				&& self::wantedLevel($intent) === InboundEmailDomain::LEVEL_PRIVATE
				&& $alias->security_level() !== InboundEmailDomain::LEVEL_PRIVATE) {
			require_once(PathHelper::getIncludePath('plugins/mailbox/includes/protection_ceremony.php'));
			$holders = InboundEmailMailboxGrant::user_ids_for_alias(intval($alias->key));
			$refusal = InboundEmailMailboxGrant::grant_set_error(true, $holders);
			if ($refusal !== null) {
				$notes[] = 'The mailbox was created, but at Standard rather than Private: ' . $refusal;
			} elseif (mailbox_protection_backlog_count(intval($domain->key), intval($alias->key)) > 0) {
				$notes[] = 'The mailbox was created, but it stays Standard for now because it already '
					. 'holds unprotected mail. Raise it to Private from its editor — that runs the '
					. 'protection ceremony, which seals the existing mail as part of the change.';
			} else {
				$alias->set('iea_security_level', InboundEmailDomain::LEVEL_PRIVATE);
				$alias->prepare();
				$alias->save();
				$alias->load();
			}
		}

		$account = self::feedFor(intval($alias->key));
		$account->set('iia_provider_key', $provider_key);
		$account->set('iia_iea_inbound_email_alias_id', intval($alias->key));
		$account->set('iia_username', $address);
		if ((string)$account->get('iia_label') === '') {
			$account->set('iia_label', $address);
		}
		$account->set('iia_imap_host', $preset['host']);
		$account->set('iia_imap_port', $preset['port']);
		$account->set('iia_imap_encryption', $preset['encryption']);
		if (!$account->key) {
			$account->set('iia_imap_folder', 'INBOX');
			$account->set('iia_poll_interval_seconds', 300);
			$account->set('iia_import_scope', InboundImapAccount::SCOPE_FUTURE);
			// Disabled until the configure step: an abandoned flow must not leave a
			// feed quietly pulling mail nobody finished asking for.
			$account->set('iia_is_enabled', false);
		}
		if (isset($intent['import_scope'])) {
			$account->set('iia_import_scope', (string)$intent['import_scope']);
		}
		if (isset($intent['import_days'])) {
			$account->set('iia_import_days', intval($intent['import_days']));
		}

		try {
			$account->prepare();
			$account->save();
			$account->load();
		} catch (\Throwable $e) {
			throw new ImapFeedProvisionException($e->getMessage());
		}

		if ($token !== null) {
			$account->setOAuthToken($token);
			$account->set('iia_needs_reauth', false);
			$account->set('iia_last_status', 'Connected via OAuth ' . gmdate('Y-m-d H:i') . ' UTC.');
			$account->save();
			$account->load();
		}

		return $account;
	}

	/**
	 * The level the intent asks for, or NULL to leave the mailbox inheriting.
	 * Standard and Private only — Fortress is a sending-identity guarantee that
	 * mail on somebody else's server cannot make.
	 */
	private static function wantedLevel(array $intent): ?string {
		$level = strtolower(trim((string)($intent['security_level'] ?? '')));
		return in_array($level, array(InboundEmailDomain::LEVEL_STANDARD,
			InboundEmailDomain::LEVEL_PRIVATE), true) ? $level : null;
	}

	/** This mailbox's existing feed, or a new unsaved one. */
	private static function feedFor(int $alias_id): InboundImapAccount {
		$feeds = new MultiInboundImapAccount(array('alias_id' => $alias_id, 'deleted' => false));
		$feeds->load();
		foreach ($feeds as $feed) {
			return new InboundImapAccount($feed->key, TRUE);
		}
		return new InboundImapAccount(NULL);
	}
}
