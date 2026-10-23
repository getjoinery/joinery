<?php
/**
 * Logic for the member message-export endpoint (/profile/mailbox/original).
 *
 * Two renderings of one message, chosen by `format`:
 *
 *   eml   (default) — the message as a .eml download: the stored original,
 *                     the original fetched live from the source mailbox for a
 *                     reference-backed row, or a full rebuild from what is
 *                     kept (mailbox_message_bytes), marked X-Joinery-Rebuilt.
 *   print           — a standalone print sheet: an addressed header block, the
 *                     message body, and its attachment names, styled for paper
 *                     and printed on load.
 *
 * Authorization is mailbox-grant scope (MailboxViewer), identical to reading the
 * message in the reader: an export is exactly as private as the message, and a
 * NULL-alias (catch-all / unmatched) message stays superadmin-only.
 *
 * This file gates; includes/message_export.php renders. On success the renderer
 * emits its bytes and exit()s, so anything that returns from here is a refusal
 * — a LogicResult the view turns into an honest message.
 *
 * @version 1.2.0 - a lean record downloads as a full rebuild instead of being refused (B1)
 * @version 1.1.0
 */

function profile_original_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/message_export.php'));

	$session = SessionControl::get_instance();
	$reader_url = '/profile/mailbox/mailbox';
	if (!$session->get_user_id()) {
		return LogicResult::redirect('/login?return=' . urlencode($reader_url));
	}
	$settings = Globalvars::get_instance();

	$id = intval($input['message_id'] ?? 0);
	if ($id <= 0) {
		return _profile_original_error($session, $settings, 'No message specified.', $reader_url);
	}

	$message = new InboundEmailMessage($id, TRUE);
	if (!$message->key || $message->get('iem_delete_time')) {
		return _profile_original_error($session, $settings, 'That message no longer exists.', $reader_url);
	}

	// Grant check — the same rule the reader applies to the message itself.
	$viewer = MailboxViewer::fromSession($session);
	if (!$viewer->canReadMessage($message)) {
		return _profile_original_error($session, $settings, 'You do not have access to this mailbox.', $reader_url);
	}

	$format = ($input['format'] ?? 'eml') === 'print' ? 'print' : 'eml';

	try {
		if ($format === 'print') {
			mailbox_print_message($message);   // emits and exits
		}
		$resolved = mailbox_message_bytes($message);
	} catch (Throwable $e) {
		error_log('profile_original (' . $format . ', message ' . $id . '): ' . $e->getMessage());
		return _profile_original_error($session, $settings, 'The message could not be read.', $reader_url);
	}

	if ($resolved['locked']) {
		return _profile_original_error($session, $settings,
			'This message is sealed. Unlock your vault in the mailbox, then try again.', $reader_url);
	}
	if (!$resolved['ok']) {
		return _profile_original_error($session, $settings,
			$resolved['reason'] . ' There is no file to download.', $reader_url);
	}
	mailbox_stream_eml($message, $resolved['raw']);   // emits and exits
}

function _profile_original_error($session, $settings, string $message, string $reader_url): LogicResult {
	return LogicResult::render(array(
		'session' => $session,
		'settings' => $settings,
		'error' => $message,
		'reader_url' => $reader_url,
	));
}
?>
