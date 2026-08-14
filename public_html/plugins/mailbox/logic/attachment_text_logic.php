<?php
/**
 * API action: mailbox/attachment_text — one attachment's words, as plain text.
 *
 * POST /api/v1/action/mailbox/attachment_text (session credential). Param:
 * attachment_id. This is what the reader's Preview button calls.
 *
 * The point of it: reading an emailed PDF otherwise means downloading it and
 * opening it in something, which is the one moment a mail reader hands
 * attacker-supplied bytes to a program that will act on them. This returns the
 * document's text and nothing else — no page is rendered, no markup becomes a
 * document, no font is loaded, no URL is fetched.
 *
 * Authorization is mailbox-grant scope, identical to downloading the
 * attachment (MailboxViewer): a preview is exactly as private as the file,
 * which is exactly as private as its message, and a NULL-alias (catch-all /
 * unmatched) message stays superadmin-only.
 *
 * Parsing itself happens in DocumentText's isolated subprocess — this endpoint
 * never sniffs, unpacks or parses the bytes it retrieved. It only gates,
 * throttles, applies the byte ceiling, and hands them down a pipe.
 *
 * On a protected mailbox the attachment is sealed under the message's DEK, so
 * reading it needs an open unlock window; a locked one returns {locked:true},
 * the same contract the body, source and draft reads use.
 *
 * @version 1.1.0
 * @changelog 1.1.0 - review fixes: the byte ceiling refuses from the recorded size BEFORE any fetch/decrypt, and every path that fetched bytes (or refused for size) writes a throttle row — refusals were the one unthrottled, most expensive request
 */

function attachment_text_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/attachment_retrieval.php'));

	// Per-IP throttle. Each preview can cost a 256MB x 20s subprocess, so the
	// risk the global API limit (1000/hr) does not cover is burst, not volume.
	// Literals rather than settings: no operator needs to tune this, and a
	// platform that installs with zero configuration does not grow a knob for it.
	$rate_max = 30;
	$rate_window = 300;

	$session = SessionControl::get_instance();
	if (!$session->get_user_id()) {
		return LogicResult::error('Sign in required.');
	}

	$id = intval($input['attachment_id'] ?? 0);
	if ($id <= 0) {
		return LogicResult::error('No attachment specified.');
	}

	if (!RequestLogger::check_rate_limit('mailbox_preview', $rate_max, $rate_window)) {
		return LogicResult::render(array(
			'previewable' => false,
			'reason'      => 'Too many previews in a short time. Wait a minute and try again.',
			'rate_limited'=> true,
		));
	}

	$att = new InboundMessageAttachment($id, TRUE);
	if (!$att->key) {
		return LogicResult::error('That attachment no longer exists.');
	}

	$message = new InboundEmailMessage(intval($att->get('ima_iem_inbound_email_message_id')), TRUE);
	if (!$message->key || $message->get('iem_delete_time')) {
		return LogicResult::error('The message for this attachment no longer exists.');
	}

	// Grant check — the same rule the download endpoint applies.
	$viewer = MailboxViewer::fromSession($session);
	$alias_id = intval($message->get('iem_iea_inbound_email_alias_id'));
	$allowed = $alias_id > 0 ? $viewer->canAccess($alias_id) : $viewer->isAllAccess();
	if (!$allowed) {
		return LogicResult::error('You do not have access to this mailbox.');
	}

	$filename = (string)$att->get('ima_filename') ?: 'attachment';
	$declared = DocumentText::bestMimeGuess($att->get('ima_content_type'), $filename);

	// Nothing here can read this type. Said before any byte is fetched.
	if (!DocumentText::isExtractable($declared)) {
		return _attachment_text_answer($att, $filename, array(
			'previewable' => false,
			'reason'      => 'This kind of file cannot be shown as text. Download it to open it.',
		));
	}

	$max_bytes = _attachment_text_max_bytes();
	$too_large = function () use ($att, $filename, $declared, $id, $session) {
		// The button was already drawn from the filename, so the honest sentence
		// beats a silent refusal. Logged: a refusal must still count against the
		// throttle, or the costliest requests are the only unthrottled ones.
		RequestLogger::log('mailbox_preview', 'attachment ' . $id . ' too large', false,
			array('user_id' => $session->get_user_id()));
		return _attachment_text_answer($att, $filename, array(
			'previewable' => true,
			'status'      => DocumentText::TOO_LARGE,
			'category'    => DocumentText::categoryForMime($declared),
			'text'        => '',
		));
	};

	// Refuse from the RECORDED size before any byte is fetched: on a sealed or
	// IMAP-backed message, retrieval and decryption are themselves the expensive
	// step, and pulling 50MB into web-request memory just to say "too large"
	// is the burst this endpoint exists to avoid.
	if (intval($att->get('ima_size_bytes')) > $max_bytes) {
		return $too_large();
	}

	$result = mailbox_retrieve_attachment_bytes($att, $message);
	if (!$result['ok']) {
		if (!empty($result['locked'])) {
			// Not logged: the locked handshake does no retrieval, and the retry
			// that follows the unlock ceremony will be counted on its own.
			return LogicResult::render(array('locked' => true));
		}
		RequestLogger::log('mailbox_preview', 'attachment ' . $id . ' retrieval failed', false,
			array('user_id' => $session->get_user_id()));
		return LogicResult::error($result['error'] ?: 'This attachment could not be read.');
	}

	$bytes = (string)$result['content'];
	// Belt and braces behind the recorded-size gate: the stored ima_size_bytes
	// can undercount (or be zero on an old row), and the subprocess budget is
	// sized for what actually came back.
	if (strlen($bytes) > $max_bytes) {
		unset($bytes);
		return $too_large();
	}

	$extract = DocumentText::extractBytes($bytes, $declared, 200000);
	unset($bytes);

	RequestLogger::log('mailbox_preview', 'attachment ' . $id, $extract['status'] === DocumentText::OK,
		array('user_id' => $session->get_user_id()));

	if ($extract['status'] === DocumentText::SKIPPED) {
		return _attachment_text_answer($att, $filename, array(
			'previewable' => false,
			'reason'      => 'This kind of file cannot be shown as text. Download it to open it.',
		));
	}

	return _attachment_text_answer($att, $filename, array(
		'previewable' => true,
		'status'      => $extract['status'],
		'category'    => $extract['category'] ?: DocumentText::categoryForMime($declared),
		'text'        => $extract['text'],
		'truncated'   => DocumentText::wasTruncated($extract['text']),
	));
}

/** Attach the file's identity to whatever answer we reached. */
function _attachment_text_answer(InboundMessageAttachment $att, string $filename, array $data): LogicResult {
	$data['filename'] = $filename;
	$data['size_bytes'] = intval($att->get('ima_size_bytes'));
	if (!isset($data['truncated'])) $data['truncated'] = false;
	return LogicResult::render($data);
}

/** Byte ceiling, enforced BEFORE the subprocess spawns. */
function _attachment_text_max_bytes(): int {
	$v = intval(Globalvars::get_instance()->get_setting('mailbox_preview_max_bytes'));
	return $v > 0 ? $v : 15728640;
}

function attachment_text_logic_descriptor() {
	return array(
		'requires_session' => true,
		'description' => 'Return one mail attachment as plain text, without opening the file',
	);
}
?>
