<?php
/**
 * API action: inbound_email/thread — one full thread for the native reader.
 *
 * POST /api/v1/action/inbound_email/thread (session key). Params: thread_key
 * (required), alias_id (optional). Returns every in-scope message with its
 * plain/HTML body and attachment manifest, enriched for sessionless clients
 * (specs/mobile_native_email.md): file-backed attachments carry short-lived
 * signed download URLs and HTML bodies have inline cid: images rewritten to
 * signed URLs (MailboxService::withSignedTransport()). Also returns the
 * thread's current folder/label ids. Empty messages = out of scope.
 *
 * @version 1.0.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function thread_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/MailboxService.php'));

	$session = SessionControl::get_instance();
	if (!$session->get_user_id()) {
		return LogicResult::error('Sign in required.');
	}

	$thread_key = isset($input['thread_key']) ? (string)$input['thread_key'] : '';
	if ($thread_key === '') {
		return LogicResult::error('thread_key is required.');
	}

	$viewer = MailboxViewer::fromSession($session);
	$service = new MailboxService($viewer);

	$alias_id = MailboxService::parseAliasParam($input['alias_id'] ?? null);

	$messages = $service->getThread($alias_id, $thread_key);
	$messages = $service->withSignedTransport($messages);

	return LogicResult::render(array(
		'messages' => $messages,
		'folders'  => $service->threadFolderIds($alias_id, $thread_key),
	));
}

function thread_logic_api() {
	return [
		'requires_session' => true,
		'description' => 'Fetch a mail thread: messages with bodies, signed attachment and inline-image URLs',
	];
}

?>
