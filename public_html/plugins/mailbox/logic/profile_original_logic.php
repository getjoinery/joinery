<?php
/**
 * Logic for the member message-export endpoint (/profile/mailbox/original).
 *
 * Two renderings of one message, chosen by `format`:
 *
 *   eml   (default) — the stored RFC822 original, streamed as a .eml download.
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
 * @version 1.0.0
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
	$alias_id = intval($message->get('iem_iea_inbound_email_alias_id'));
	$allowed = $alias_id > 0 ? $viewer->canAccess($alias_id) : $viewer->isAllAccess();
	if (!$allowed) {
		return _profile_original_error($session, $settings, 'You do not have access to this mailbox.', $reader_url);
	}

	$format = ($input['format'] ?? 'eml') === 'print' ? 'print' : 'eml';

	try {
		if ($format === 'print') {
			mailbox_print_message($message);   // emits and exits
		}
		$raw = $message->getRawMessage();
	} catch (VaultLockedException $e) {
		return _profile_original_error($session, $settings,
			'This message is sealed. Unlock your vault in the mailbox, then try again.', $reader_url);
	} catch (Throwable $e) {
		error_log('profile_original (' . $format . ', message ' . $id . '): ' . $e->getMessage());
		return _profile_original_error($session, $settings, 'The message could not be read.', $reader_url);
	}

	if ($raw === null || $raw === '') {
		$driver = (string)$message->get('iem_raw_storage_driver') ?: 'inline';
		return _profile_original_error($session, $settings,
			$driver === 'remote'
				? 'This message is read directly from its source mailbox over IMAP, which keeps '
					. 'no copy of the original here — there is no file to download.'
				: 'No original was stored for this message, so there is no file to download.',
			$reader_url);
	}

	mailbox_stream_eml($message, $raw);   // emits and exits
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
