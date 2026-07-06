<?php
/**
 * InboundImapOAuthConsumer - Receives an OAuth2 grant for an IMAP account.
 *
 * The IMAP transport is the OAuth2 Core's first consumer. When an admin clicks
 * "Connect" on a Gmail/Microsoft IMAP account, the account editor calls
 * OAuth2Client::beginConsent(..., 'inbound_imap', ['account_id' => N], ...). The
 * provider returns to the shared /oauth_callback, which exchanges the code and
 * dispatches here by purpose. This consumer owns no grant/refresh/callback logic
 * — only "store the granted tokens on the account."
 *
 * Discovered by interface from this plugin's includes/oauth_consumers/ (see
 * OAuth2ConsumerRegistry); no registration call is needed.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Consumer.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_account_class.php'));

class InboundImapOAuthConsumer implements OAuth2Consumer {

	const ACCOUNTS_URL = '/plugins/mailbox/admin/admin_mailbox_accounts';

	public static function getPurpose(): string {
		return 'inbound_imap';
	}

	/**
	 * Store the granted access + refresh tokens (encrypted) and expiry on the
	 * account named by the flow payload, then return the IMAP-accounts admin URL.
	 * The payload carries the account id the initiating editor passed to
	 * beginConsent().
	 */
	public function onTokenGranted(OAuth2Token $token, array $payload): string {
		$accountId = intval($payload['account_id'] ?? 0);
		if ($accountId <= 0) {
			return self::ACCOUNTS_URL;
		}

		$account = new InboundImapAccount($accountId, TRUE);
		if (!$account->key) {
			return self::ACCOUNTS_URL;
		}

		$account->setOAuthToken($token);
		$account->set('iia_last_status', 'Connected via OAuth ' . gmdate('Y-m-d H:i') . ' UTC.');
		$account->save();

		return self::ACCOUNTS_URL . '?connected=' . $accountId;
	}
}
?>
