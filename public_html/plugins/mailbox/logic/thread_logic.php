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
 * A Fortress message (specs/client_custody_mail.md § R4) carries its content
 * sealed for the owner's browser under `sealed` ({key, sealed_scope,
 * sealed_dek, sealed_ad_prefix} and the sealed columns as stored), its clear
 * content fields empty, `fortress: true`, and attachments by id and MIME part
 * with no name or URL. The response's own `fortress: true` says some message
 * needs the mail key: a client without it shows that and opens the web reader.
 *
 * @version 1.3.0 - Fortress messages travel sealed; `fortress` on the response
 * @version 1.2.1
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

	// Reading a thread does NOT touch the contact store. A contact is something the
	// user chose to keep (§ Phase 4) — anyone who can send you mail could otherwise
	// write themselves into your address book, which is how a spam address gets there.
	// The reader offers an explicit Add beside the sender instead.

	$fortress = false;
	foreach ($messages as $m) {
		if (!empty($m['fortress'])) {
			$fortress = true;
			break;
		}
	}

	return LogicResult::render(array(
		'messages' => $messages,
		// Some message is end-to-end sealed: only the owner's devices open it.
		'fortress' => $fortress,
		'folders'  => $service->threadFolderIds($alias_id, $thread_key, $trashed),
		// Locked-state contract (specs/mailbox_security_levels.md § 4.2): metadata
		// plus a `locked` flag rather than an error, so the client renders sealed
		// placeholders and triggers the native unlock ceremony on a content action.
		'locked'   => $service->contentLocked(),
	));
}

function thread_logic_descriptor() {
	return [
		'requires_session' => true,
		'description' => 'Fetch a mail thread: messages with bodies, signed attachment and inline-image URLs. '
			. 'An end-to-end (Fortress) message has empty content fields, its sealed columns under `sealed` '
			. '(key, sealed_scope, sealed_dek, sealed_ad_prefix, iem_* ciphertext) for the owner\'s browser to '
			. 'open, `fortress: true`, and attachments without names or URLs; `fortress` on the response says '
			. 'some message needs the mail key.',
		'input' => [
			'thread_key' => ['type' => 'string', 'required' => true, 'label' => 'Thread key'],
			'alias_id' => ['type' => 'string', 'required' => false, 'label' => 'Mailbox alias ID, unmatched, or unmatched:{domain_id}'],
			'trash' => ['type' => 'bool', 'required' => false, 'label' => 'Read from Trash'],
		],
	];
}

?>
