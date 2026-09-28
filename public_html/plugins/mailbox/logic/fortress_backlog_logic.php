<?php
/**
 * API action: mailbox/fortress_backlog — where moving the caller's stored mail
 * onto and off their device key stands (specs/client_custody_mail.md § R8),
 * for the receipt card and the mailbox banner to show.
 *
 * POST /api/v1/action/mailbox/fortress_backlog (browser session). Body:
 * {domain_id} optional, narrowing the counts to one domain (the editor's
 * receipt). Nothing moves here: the raise is the vault's deferred work
 * (`mailbox_fortress_raise`), which the vault client runs while the window is
 * open; the lowering is the browser's (JoinerySealed.changeCustody).
 *
 * Returns {raise_remaining, raise_ready, lower_remaining, window_open}:
 * raise_ready is what a pass can still take now; the rest of raise_remaining
 * could not be moved (MailboxFortressLevel::backlogWhere()).
 *
 * @version 1.0
 */

function fortress_backlog_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$user_id = (int)$session->get_user_id();
	if (!$user_id) {
		return LogicResult::error('Sign in required.');
	}
	$domain_id = intval($input['domain_id'] ?? 0);
	return LogicResult::render(array(
		'raise_remaining' => MailboxFortressLevel::backlogCount($user_id, $domain_id),
		'raise_ready'     => MailboxFortressLevel::backlogCount($user_id, $domain_id, true),
		'lower_remaining' => MailboxFortressLevel::loweringBacklogCount($user_id, $domain_id),
		'window_open'     => VaultUnlock::isOpen($user_id),
	));
}

function fortress_backlog_logic_descriptor() {
	return array(
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'mutates' => false,
		'description' => 'Count the caller\'s stored mail still to move onto their device key (all, and what can move now) and off it: {raise_remaining, raise_ready, lower_remaining, window_open}',
		'input' => [
			'domain_id' => ['type' => 'int', 'required' => false, 'label' => 'Count within one domain'],
		],
	);
}
?>
