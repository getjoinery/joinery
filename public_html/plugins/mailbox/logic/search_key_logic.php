<?php
/**
 * API action: mailbox/search_key — the caller's search key, sealed to their
 * mail vault, which seals the search index each of their browsers keeps over
 * their end-to-end encrypted mail (specs/client_custody_mail.md § R5).
 *
 * POST /api/v1/action/mailbox/search_key (browser session). op 'get' returns
 * {set_up, sealed_key, user_id}. op 'create' stores a key the browser minted
 * and sealed (sealed_key, public_key = the vault key it sealed to), once:
 * refused when one exists ({already_set_up: true}), so a second browser racing
 * the first fetches the winner's. The server never opens it.
 *
 * @version 1.0
 */

function search_key_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$user_id = (int)$session->get_user_id();
	$op = (string)($input['op'] ?? 'get');
	if ($op === 'create') {
		try {
			MailboxSearchKey::acceptBrowserKey($user_id, (string)($input['sealed_key'] ?? ''),
				(string)($input['public_key'] ?? ''));
		} catch (MailboxSearchKeyException $e) {
			return LogicResult::error($e->getMessage(),
				array('already_set_up' => MailboxSearchKey::sealedKeyFor($user_id) !== null));
		}
	}
	$sealed = MailboxSearchKey::sealedKeyFor($user_id);
	return LogicResult::render(array('set_up' => $sealed !== null, 'sealed_key' => $sealed, 'user_id' => $user_id));
}

function search_key_logic_descriptor() {
	return array(
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Get, or create once, the caller\'s search key sealed to their mail vault (an opaque blob the server never opens)',
		'input' => [
			'op'         => ['type' => 'string', 'required' => false, 'enum' => ['get', 'create'], 'label' => 'get (default) or create'],
			'sealed_key' => ['type' => 'string', 'required' => false, 'max_length' => 1024, 'label' => 'The key sealed to the mail vault (create)'],
			'public_key' => ['type' => 'string', 'required' => false, 'max_length' => 512, 'label' => 'The vault key it was sealed to (create)'],
		],
	);
}
?>
