<?php
/**
 * Logic for the IMAP Accounts list page.
 *
 * Handles the row actions — delete, poll-now, test-connection, and "Connect"
 * (begin an OAuth2 consent flow through the OAuth2 Core for Gmail/Microsoft
 * accounts). Loads the accounts plus their bound-alias labels for display.
 *
 * @version 1.3 - reconnect consent carries the provider's identity scopes, so
 *   the consumer can check which address signed in
 * @version 1.2
 * @changelog 1.2 - Failures no longer arrive as green announcements, and a
 *   connect attempt for a provider with no app credentials leads to the page
 *   that supplies them instead of reporting a dead end.
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
			// If the feed has reference-backed ('remote') messages, deleting it
			// would strand them (attachments fetch from this account on demand).
			// Route through the keep/remove choice instead of a silent orphan
			// (specs/mailbox_data_loss_fixes.md, Fix 8).
			if (_imap_has_reference_backed(intval($account->key))) {
				return LogicResult::redirect(
					'/plugins/mailbox/admin/admin_mailbox_imap_delete?iia_inbound_imap_account_id='
					. intval($account->key));
			}
			$account->soft_delete();
			return _imap_msg_redirect($session, 'IMAP account deleted.', $list_url);
		}

		if ($action === 'test' || $action === 'poll_now') {
			require_once(PathHelper::getIncludePath('plugins/mailbox/includes/ImapIngestor.php'));
			$ingestor = new ImapIngestor($account);
			// A green banner saying the connection failed is a contradiction the eye
			// reads before the words, so every outcome carries its own level.
			$level = DisplayMessage::MESSAGE_ANNOUNCEMENT;
			if ($action === 'test') {
				$res = $ingestor->testConnection();
				$msg = $res['ok'] ? ('Connection OK. ' . $res['message']) : ('Connection failed: ' . $res['message']);
				$level = $res['ok'] ? DisplayMessage::MESSAGE_ANNOUNCEMENT : DisplayMessage::MESSAGE_ERROR;
				$account->recordStatus($res['ok'] ? ('Test OK: ' . $res['message']) : ('Test failed: ' . $res['message']));
			} else {
				if (!$account->isConnectable()) {
					$msg = 'This mailbox has not been connected yet — connect it first, then fetch.';
					$level = DisplayMessage::MESSAGE_WARNING;
				} else {
					try {
						$res = $ingestor->poll(50);
						$msg = 'Fetch complete. ' . ($res['status'] ?? '');
					} catch (Throwable $e) {
						$msg = 'Fetch error: ' . $e->getMessage();
						$level = DisplayMessage::MESSAGE_ERROR;
						$account->recordStatus('Fetch error: ' . substr($e->getMessage(), 0, 400));
					}
				}
			}
			$ingestor->close();
			return _imap_msg_redirect($session, $msg, $list_url, $level);
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
	require_once(PathHelper::getIncludePath('includes/oauth/OAuth2ProviderRegistry.php'));

	$providerKey = $account->getOAuthProviderKey();
	if (!$account->isOAuth() || !$providerKey) {
		return _imap_msg_redirect($session, 'This account does not use OAuth.', $list_url,
			DisplayMessage::MESSAGE_WARNING);
	}

	// Consent means sending the operator to the provider to approve this site's own
	// registered app. With no app credentials there is nothing to approve, so send
	// them to the one page that fixes it rather than back to a list with an error.
	$providerClass = OAuth2ProviderRegistry::get($providerKey);
	if ($providerClass !== null && !$providerClass::isConfigured()) {
		$label = $providerClass::getLabel();
		return _imap_msg_redirect($session,
			$label . ' has to know about this site before it will let anyone sign in to it. '
				. 'Enter the ' . $label . ' app details here once, then come back and connect this mailbox.',
			'/admin/admin_oauth_providers?return=' . urlencode($list_url)
				. '#oauth-' . urlencode($providerKey),
			DisplayMessage::MESSAGE_WARNING,
			'One-time setup');
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
	// The provider's identity scopes ride along so the consumer can check WHICH
	// address signed in against the address this feed is configured for (§ C) —
	// the mismatch otherwise surfaces later as an opaque IMAP login failure.
	if ($providerClass !== null) {
		$scopes = array_values(array_unique(array_merge($scopes, $providerClass::identityScopes())));
	}

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
		return _imap_msg_redirect($session, 'Could not start the connect flow: ' . $e->getMessage(), $list_url,
			DisplayMessage::MESSAGE_ERROR);
	}
}

/** True if this IMAP account has any reference-backed ('remote') messages. */
function _imap_has_reference_backed(int $account_id): bool {
	$db = DbConnector::get_instance()->get_db_link();
	$stmt = $db->prepare(
		"SELECT 1 FROM iem_inbound_email_messages
		 WHERE iem_iia_inbound_imap_account_id = ? AND iem_raw_storage_driver = 'remote' LIMIT 1");
	$stmt->execute(array($account_id));
	return (bool)$stmt->fetchColumn();
}

/**
 * Redirect carrying a flash message. The level is explicit because a failure
 * dressed as an announcement reads as success — the banner is green. The message
 * is pinned to the page being redirected to, so a message that leads somewhere
 * else (say, to enter OAuth app details) still arrives with the operator.
 */
function _imap_msg_redirect($session, string $message, string $url,
		int $level = DisplayMessage::MESSAGE_ANNOUNCEMENT, string $title = 'IMAP Accounts'): LogicResult {
	$path = parse_url($url, PHP_URL_PATH) ?: $url;
	$session->save_message(new DisplayMessage(
		$message,
		$title,
		'~' . preg_quote($path, '~') . '~',
		$level,
		DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
	));
	return LogicResult::redirect($url);
}
?>
