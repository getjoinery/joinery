<?php
/**
 * API action: mailbox/signature_save — save the compose signature for a mailbox.
 *
 * POST /api/v1/action/mailbox/signature_save (session credential). Params: alias_id,
 * signature (HTML). The signature is server-sanitized against the compose allowlist with
 * images excluded (a signature carries no inline images) and saved on the caller's OWN
 * grant for that mailbox — InboundEmailMailboxGrant::saveSignature returns false when the
 * caller holds no such grant, which is reported as "no access". Returns {ok, signature}
 * (the stored, sanitized HTML, so the client can re-render it).
 *
 * @version 1.0.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function signature_save_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxViewer.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxHtmlSanitizer.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));

	$session = SessionControl::get_instance();
	if (!$session->get_user_id()) {
		return LogicResult::error('Sign in required.');
	}

	$alias_id = intval($input['alias_id'] ?? 0);
	if ($alias_id <= 0) {
		return LogicResult::error('No mailbox specified.');
	}

	// A signature has no inline images (allow_images = false strips any <img>).
	$sanitized = MailboxHtmlSanitizer::sanitize((string)($input['signature'] ?? ''), false);

	$viewer = MailboxViewer::fromSession($session);
	if (!$viewer->canAccess($alias_id)) {
		return LogicResult::error('You do not have access to this mailbox.');
	}

	$ok = InboundEmailMailboxGrant::saveSignature($viewer->getUserId(), $alias_id, $sanitized);
	if (!$ok) {
		return LogicResult::error('You do not have access to this mailbox.');
	}

	return LogicResult::render(array('ok' => true, 'signature' => $sanitized));
}

function signature_save_logic_api() {
	return array(
		'requires_session' => true,
		'description' => 'Save the compose signature for one of the caller\'s mailboxes',
	);
}
?>
