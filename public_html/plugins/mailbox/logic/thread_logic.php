<?php
/**
 * API action: mailbox/thread — one full thread for the native reader.
 *
 * POST /api/v1/action/mailbox/thread (session key). Params: thread_key
 * (required), alias_id (optional). Returns every in-scope message with its
 * plain/HTML body and attachment manifest, enriched for sessionless clients
 * (specs/implemented/mobile_native_email_server_api_and_ios.md): file-backed attachments carry short-lived
 * signed download URLs and HTML bodies have inline cid: images rewritten to
 * signed URLs (MailboxService::withSignedTransport()). Also returns the
 * thread's current folder/label ids. Empty messages = out of scope.
 *
 * `trash` opens the thread under the Trash scope (specs/mailbox_trash_folder.md) —
 * a discarded conversation is invisible to every other read, so the Trash view
 * says so when it asks.
 *
 * @version 1.1.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function thread_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxService.php'));

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

	$trashed = !empty($input['trash']);
	$messages = $service->getThread($alias_id, $thread_key, $trashed);
	$messages = $service->withSignedTransport($messages);

	// Harvest the thread's inbound senders into the contact store (§ Phase 4) —
	// opportunistic, in-window by construction (the thread just decrypted), best-effort.
	//
	// Grouped by the MESSAGE's own mailbox, not by the view's alias scope: contacts
	// belong to a mailbox, and this thread may have been opened from All mail (no alias)
	// or span two mailboxes that both received it. A message with no mailbox (unmatched)
	// has no store to land in and is skipped.
	if (!$service->contentLocked()) {
		$by_alias = array();
		foreach ($messages as $m) {
			if (($m['direction'] ?? 'inbound') === 'outbound' || empty($m['sender'])) {
				continue;
			}
			$aid = intval($m['alias_id'] ?? 0);
			if ($aid <= 0) {
				continue;
			}
			$by_alias[$aid][] = (string)$m['sender'];
		}
		if (count($by_alias)) {
			try {
				require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxContacts.php'));
				$store = new MailboxContacts();
				foreach ($by_alias as $aid => $senders) {
					$store->harvest(intval($session->get_user_id()), $senders, MailboxContact::SOURCE_RECEIVED, $aid);
				}
			} catch (Throwable $e) {
				error_log('mailbox/thread contact harvest: ' . $e->getMessage());
			}
		}
	}

	return LogicResult::render(array(
		'messages' => $messages,
		'folders'  => $service->threadFolderIds($alias_id, $thread_key, $trashed),
		// Locked-state contract (specs/mailbox_security_levels.md § 4.2): metadata
		// plus a `locked` flag rather than an error, so the client renders sealed
		// placeholders and triggers the native unlock ceremony on a content action.
		'locked'   => $service->contentLocked(),
	));
}

function thread_logic_api() {
	return [
		'requires_session' => true,
		'description' => 'Fetch a mail thread: messages with bodies, signed attachment and inline-image URLs',
	];
}

?>
