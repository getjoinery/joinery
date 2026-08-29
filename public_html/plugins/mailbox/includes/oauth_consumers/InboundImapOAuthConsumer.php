<?php
/**
 * InboundImapOAuthConsumer - Receives an OAuth2 grant for an IMAP mailbox.
 *
 * The IMAP transport is the OAuth2 Core's first consumer. This consumer owns no
 * grant/refresh/callback logic — the provider returns to the shared
 * /oauth_callback, which exchanges the code and dispatches here by purpose.
 * What is left is: put this grant where it belongs.
 *
 * There are two shapes of "where it belongs", and the difference is the whole
 * point of the connect flow (specs/mailbox_connect_flow.md § B):
 *
 *   - RECONNECT — the payload names an existing feed (`account_id`). The
 *     mailbox is already there; store the token on it.
 *   - CONNECT — the payload carries the operator's INTENT (provider, who reads
 *     it, what protection it gets) and no ids, because none of those rows exist
 *     yet. The mailbox is created HERE, on success, from the address the
 *     provider reports. That inversion is what lets the flow ask its questions
 *     in the order the answers exist, instead of making the operator build a
 *     mailbox before they have signed in to anything.
 *
 * When the provider reports no address — it cannot, or the call failed — the
 * grant is not thrown away: it is held in the operator's own session
 * (ImapConnectStash) and the wizard asks for the address with the connection
 * already in hand. Losing the convenience must never lose the connection.
 *
 * Discovered by interface from this plugin's includes/oauth_consumers/ (see
 * OAuth2ConsumerRegistry); no registration call is needed.
 *
 * @version 2.2
 * @changelog 2.2 - both entry points check WHAT was granted and refuse a grant
 *   that authorizes sign-in but not mail access, instead of storing it and
 *   letting the next poll fail with an opaque authentication error
 * @changelog 2.1 - reconnect checks WHICH address signed in and refuses a
 *   mismatch with both named; a never-configured feed finishes in the wizard's
 *   configure state; any provisioning failure stashes the token, not just the
 *   provisioner's own exception type
 * @changelog 2.0 - a grant with no account_id creates the mailbox from the
 *   operator's intent instead of being silently discarded
 */

require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Consumer.php'));
require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Client.php'));
require_once(PathHelper::getIncludePath('includes/oauth/OAuth2ProviderRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_account_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/ImapFeedProvisioner.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/ImapConnectStash.php'));

class InboundImapOAuthConsumer implements OAuth2Consumer {

	const ACCOUNTS_URL = '/plugins/mailbox/admin/admin_mailbox_accounts';
	const WIZARD_URL   = '/plugins/mailbox/admin/admin_mailbox_connect';

	public static function getPurpose(): string {
		return 'inbound_imap';
	}

	/**
	 * Store the granted access + refresh tokens (encrypted) and expiry, and
	 * return where to send the operator next.
	 */
	public function onTokenGranted(OAuth2Token $token, array $payload): string {
		$accountId = intval($payload['account_id'] ?? 0);
		if ($accountId > 0) {
			return $this->reconnect($accountId, $token);
		}
		return $this->connect($token, $payload);
	}

	/** An existing feed re-authorized: the mailbox is already there. */
	private function reconnect(int $accountId, OAuth2Token $token): string {
		$account = new InboundImapAccount($accountId, TRUE);
		if (!$account->key) {
			// The feed was deleted while the sign-in was out. Say so — a silent
			// return would leave the operator wondering where the consent went.
			SessionControl::get_instance()->save_message(new DisplayMessage(
				'That mailbox no longer exists, so the sign-in was not attached to anything. '
					. 'If it should exist, create it again from Connect a mailbox.',
				'Mailbox gone', '~/plugins/mailbox/admin/~',
				DisplayMessage::MESSAGE_WARNING, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
			return self::ACCOUNTS_URL;
		}

		// WHO signed in, where the provider can say. The feed authenticates as
		// iia_username, so a token belonging to a different account would fail
		// IMAP login later, opaquely (§ C) — the mismatch is refused HERE, named,
		// with nothing changed, instead of being discovered by the first fetch.
		$configured = strtolower(trim((string)$account->get('iia_username')));
		$learned = $this->learnAddress((string)$account->get('iia_provider_key'), $token);
		if ($learned !== null && $configured !== '' && $learned !== $configured) {
			SessionControl::get_instance()->save_message(new DisplayMessage(
				'You signed in as ' . htmlspecialchars($learned) . ', but this mailbox collects '
					. htmlspecialchars($configured) . '. Nothing was changed. Sign in with '
					. htmlspecialchars($configured) . ' to connect it — or set '
					. htmlspecialchars($learned) . ' up as its own mailbox from Connect a mailbox.',
				'Different account', '~/plugins/mailbox/admin/~',
				DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
			return self::ACCOUNTS_URL;
		}

		// WHAT was granted. Signing in is not the same permission as being let
		// into the mailbox, and the consent screen asks for them separately — so
		// a grant that carries only identity is refused HERE, named, with the
		// feed's existing state untouched, instead of being discovered as an
		// opaque "Authentication failed" on the next scheduled poll.
		$refusal = $this->mailAccessRefusal($account->get('iia_provider_key'), $token);
		if ($refusal !== null) {
			SessionControl::get_instance()->save_message($refusal);
			return self::ACCOUNTS_URL;
		}

		$account->setOAuthToken($token);
		$account->set('iia_needs_reauth', false);
		$account->set('iia_last_status', 'Connected via OAuth ' . gmdate('Y-m-d H:i') . ' UTC.');
		$account->save();

		// A feed that was created "someone else will sign in" has just gained its
		// first credential — its folder and import questions were never asked, and
		// it is still switched off. That is the wizard's configure state, exactly:
		// a feed that exists and is connected. Finish there, not on a list.
		if (!$account->get('iia_is_enabled')) {
			return self::WIZARD_URL . '?state=configure&account_id=' . intval($account->key) . '&connected=1';
		}

		// Say so. Landing back on a list where nothing acknowledges the round trip
		// reads as "did that work?" — the one question the whole trip answered.
		SessionControl::get_instance()->save_message(new DisplayMessage(
			htmlspecialchars((string)$account->get('iia_username')) . ' is connected.',
			'Connected', '~/plugins/mailbox/admin/~',
			DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));

		return self::ACCOUNTS_URL;
	}

	/**
	 * A first connection: the mailbox does not exist yet, so build it from the
	 * intent the wizard carried through the flow.
	 *
	 * The address comes from the provider where it can (§ C) and is
	 * AUTHORITATIVE when it does: it is the account that actually consented, and
	 * it is what the IMAP session will authenticate as. Where the provider
	 * cannot say, the grant is stashed and the wizard asks.
	 */
	private function connect(OAuth2Token $token, array $payload): string {
		$provider_key = (string)($payload['provider_key'] ?? '');
		$intent = array(
			'reader_user_id' => intval($payload['reader_user_id'] ?? 0),
			'security_level' => (string)($payload['security_level'] ?? ''),
		);

		$refusal = $this->mailAccessRefusal($provider_key, $token);
		if ($refusal !== null) {
			SessionControl::get_instance()->save_message($refusal);
			return self::ACCOUNTS_URL;
		}

		$address = $this->learnAddress($provider_key, $token);
		if ($address === null) {
			ImapConnectStash::put($provider_key, $intent, $token);
			return self::WIZARD_URL . '?state=configure&ask_address=1';
		}

		try {
			$notes = array();
			$account = ImapFeedProvisioner::provision($provider_key, $address, $intent, $token, $notes);
		} catch (\Throwable $e) {
			// Consent succeeded; only the rows failed. WHATEVER failed: catching
			// only the provisioner's own exception type would let a model-layer
			// throw fall through to the generic callback error page and take the
			// granted token with it — the one loss this stash exists to prevent.
			// Hold the grant so the operator answers one question rather than
			// signing in again.
			ImapConnectStash::put($provider_key, $intent, $token);
			error_log('InboundImapOAuthConsumer: provisioning failed for ' . $address . ': ' . $e->getMessage());
			return self::WIZARD_URL . '?state=configure&ask_address=1&provision_error='
				. rawurlencode($e->getMessage());
		}

		foreach ($notes as $note) {
			SessionControl::get_instance()->save_message(new DisplayMessage(
				htmlspecialchars($note), 'Protection', '~/plugins/mailbox/admin/~',
				DisplayMessage::MESSAGE_WARNING, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		}
		ImapConnectStash::clear();
		return self::WIZARD_URL . '?state=configure&account_id=' . intval($account->key) . '&connected=1';
	}

	/**
	 * The message to show when a grant cannot read mail, or null when it can.
	 *
	 * Both entry points ask the same question of the same grant, so they ask it
	 * in one place: a first connection that cannot open the mailbox is exactly as
	 * useless as a reconnection that cannot, and telling an operator two
	 * different stories about one missed tick box is how they stop reading
	 * either.
	 */
	private function mailAccessRefusal(?string $preset_key, OAuth2Token $token): ?DisplayMessage {
		if (!InboundImapAccount::missingMailScopes($preset_key, $token->getScope())) {
			return null;
		}
		$label = InboundImapAccount::PRESETS[$preset_key]['label'] ?? 'the mail provider';
		return new DisplayMessage(
			'You signed in, but did not grant access to the mail itself, so nothing can read the '
				. 'mailbox. Nothing was changed. Connect again and, on the permission screen, allow '
				. htmlspecialchars($label) . ' access to your email — approving only your name and '
				. 'email address is what leaves it looking connected while every fetch is refused.',
			'Mail access not granted', '~/plugins/mailbox/admin/~',
			DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE);
	}

	/**
	 * The address that just consented, per the provider, or null. The IMAP
	 * preset names the OAuth provider, so the two can never be given separately
	 * and disagree.
	 */
	private function learnAddress(string $provider_key, OAuth2Token $token): ?string {
		$preset = InboundImapAccount::PRESETS[$provider_key] ?? null;
		$oauth_key = $preset['oauth_provider'] ?? null;
		if ($oauth_key === null) {
			return null;
		}
		$providerClass = OAuth2ProviderRegistry::get($oauth_key);
		if ($providerClass === null) {
			return null;
		}
		return (new OAuth2Client())->fetchIdentity($providerClass, $token);
	}
}
?>
