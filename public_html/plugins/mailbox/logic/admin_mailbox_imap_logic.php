<?php
/**
 * Logic for the IMAP Accounts list page.
 *
 * Handles the row actions — delete, poll-now, test-connection, and "Connect"
 * (begin an OAuth2 consent flow through the OAuth2 Core for Gmail/Microsoft
 * accounts). Loads the accounts plus their bound-alias labels for display.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function admin_mailbox_imap_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_account_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));

	$session = SessionControl::get_instance();
	// Managing IMAP accounts means handling full-mailbox credentials — superadmin only.
	$session->check_permission(10);
	$settings = Globalvars::get_instance();

	// IMAP feeds are managed from the Accounts tree now; actions return there.
	$list_url = '/plugins/mailbox/admin/admin_mailbox_accounts';

	$action = $input['action'] ?? '';
	$account_id = intval($input['iia_inbound_imap_account_id'] ?? 0);

	if ($action && $account_id > 0) {
		$account = new InboundImapAccount($account_id, TRUE);
		if (!$account->key) {
			return LogicResult::redirect($list_url);
		}

		if ($action === 'delete') {
			$account->soft_delete();
			return _imap_msg_redirect($session, 'IMAP account deleted.', $list_url);
		}

		if ($action === 'test' || $action === 'poll_now') {
			require_once(PathHelper::getIncludePath('plugins/mailbox/includes/ImapIngestor.php'));
			$ingestor = new ImapIngestor($account);
			if ($action === 'test') {
				$res = $ingestor->testConnection();
				$msg = $res['ok'] ? ('Connection OK. ' . $res['message']) : ('Connection failed: ' . $res['message']);
				$account->recordStatus($res['ok'] ? ('Test OK: ' . $res['message']) : ('Test failed: ' . $res['message']));
			} else {
				if (!$account->isConnectable()) {
					$msg = 'Account is not fully credentialed yet — connect/authorize it first.';
				} else {
					try {
						$res = $ingestor->poll(50);
						$msg = 'Fetch complete. ' . ($res['status'] ?? '');
					} catch (Throwable $e) {
						$msg = 'Fetch error: ' . $e->getMessage();
						$account->recordStatus('Fetch error: ' . substr($e->getMessage(), 0, 400));
					}
				}
			}
			$ingestor->close();
			return _imap_msg_redirect($session, $msg, $list_url);
		}

		if ($action === 'connect') {
			return _imap_begin_consent($account, $session, $list_url);
		}
	}

	// The standalone IMAP list is retired — IMAP feeds live in the Accounts tree.
	// This page is now only an action handler; any bare visit bounces to Accounts.
	return LogicResult::redirect($list_url);
}

/**
 * Begin the OAuth2 consent flow for an OAuth account, returning a redirect to the
 * provider consent URL. The provider returns to the shared /oauth_callback, which
 * dispatches to InboundImapOAuthConsumer (purpose inbound_imap).
 */
function _imap_begin_consent(InboundImapAccount $account, $session, string $list_url): LogicResult {
	require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Client.php'));

	$providerKey = $account->getOAuthProviderKey();
	if (!$account->isOAuth() || !$providerKey) {
		return _imap_msg_redirect($session, 'This account does not use OAuth.', $list_url);
	}

	// One consent grants BOTH directions (§6 unified onboarding): IMAP read for the
	// inbound feed AND SMTP send for outbound. Google's https://mail.google.com/
	// already authorizes SMTP send, so no extra scope is needed. Microsoft needs
	// SMTP.Send added alongside IMAP (offline_access yields the refresh token;
	// Google returns one via access_type=offline + prompt=consent in the provider).
	$scopes = ($providerKey === 'microsoft')
		? array(
			'https://outlook.office365.com/IMAP.AccessAsUser.All',
			'https://outlook.office365.com/SMTP.Send',
			'offline_access',
		)
		: array('https://mail.google.com/');

	try {
		$client = new OAuth2Client();
		$consentUrl = $client->beginConsent(
			$providerKey,
			$scopes,
			'inbound_imap',
			array('account_id' => intval($account->key)),
			$list_url
		);
		return LogicResult::redirect($consentUrl);
	} catch (Throwable $e) {
		return _imap_msg_redirect($session, 'Could not start the connect flow: ' . $e->getMessage(), $list_url);
	}
}

function _imap_msg_redirect($session, string $message, string $url): LogicResult {
	$session->save_message(new DisplayMessage(
		$message,
		'IMAP Accounts',
		'~/plugins/mailbox/admin/~',
		DisplayMessage::MESSAGE_ANNOUNCEMENT,
		DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
	));
	return LogicResult::redirect($url);
}
?>
