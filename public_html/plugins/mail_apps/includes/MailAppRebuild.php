<?php
/**
 * MailAppRebuild - the bytes a mail program is served for a message.
 *
 * IMAP clients ask for whole messages, and most stored mail keeps no original.
 * The mailbox plugin's MessageRebuild decides what a message's whole bytes are
 * (stored, fetched live from a connected source, or rebuilt from what is kept)
 * and builds the rebuilt ones; this class serves them to a mail program, which
 * adds two duties of its own:
 *
 *   - the size is fixed at first telling (mam_client_size), because a program
 *     asks for it in bulk and reads up to it for good;
 *   - a message the program submitted itself is rebuilt with the Message-ID
 *     the program gave it (mam_submitted_message_id).
 *
 * A program is served the rebuilt bytes unmarked: they have never carried
 * X-Joinery-Rebuilt, and adding it would change bytes every program has
 * already cached. The sender's DKIM signature does not verify against a
 * rebuilt body.
 *
 * Sealed content is opened in the window (the model's sealed-field read); a
 * closed one answers 'locked', and nothing is ever served in its place
 * (specs/mail_apps_plugin.md § Locked behaviour, rule 3).
 *
 * @version 1.1.1 - doc no longer names a FORMAT number
 * @version 1.1.0 - the rebuild itself is the mailbox plugin's MessageRebuild
 * @version 1.0.0
 */

class MailAppRebuild {

	/** The rebuilt format (MessageRebuild's): a new value starts every folder over. */
	const FORMAT = MessageRebuild::FORMAT;

	// ── what a mail program asks for ───────────────────────────────────────

	/**
	 * A message's header block and its size, for a mail program's list. The
	 * size is fixed the first time it is told.
	 *
	 * @return array{ok:bool, header?:string, size?:int, error?:string}
	 *   error: 'locked' (sealed and the window is shut), 'unavailable' (no bytes
	 *   can be produced — the source no longer has it, or end-to-end), 'gone'
	 */
	public static function header(MailAppContext $ctx, int $message_id): array {
		$msg = self::loadMessage($ctx, $message_id);
		if ($msg === null) {
			return array('ok' => false, 'error' => 'gone');
		}
		if (InboundEmailMessage::isBrowserSealed($msg)) {
			return array('ok' => false, 'error' => 'unavailable');
		}
		try {
			$kind = MessageRebuild::kindOf($msg);
			if ($kind === 'stored') {
				$raw = $msg->getRawMessage();
				if ($raw === null || $raw === '') {
					return array('ok' => false, 'error' => 'unavailable');
				}
				$size = strlen($raw);
				$header = MessageRebuild::headerOf($raw);
			} elseif ($kind === 'remote') {
				$size = intval($msg->get('iem_size_bytes'));
				if ($size <= 0) {
					return array('ok' => false, 'error' => 'unavailable');
				}
				$retained = trim((string)$msg->get('iem_raw_headers'), "\r\n");
				$header = $retained !== ''
					? MessageRebuild::crlf($retained) . "\r\n\r\n"
					: MessageRebuild::synthesizedHeaders($msg, array(self::class, 'submittedMessageId')) . "\r\n\r\n";
			} else {
				$built = self::build($msg, false);
				$size = $built['size'];
				$header = $built['header'];
			}
		} catch (VaultLockedException $e) {
			return array('ok' => false, 'error' => 'locked');
		}
		$size = self::fixSize($message_id, $size);
		return array('ok' => true, 'header' => $header, 'size' => $size);
	}

	/**
	 * A message's whole bytes.
	 *
	 * @return array{ok:bool, bytes?:string, error?:string}
	 */
	public static function bytes(MailAppContext $ctx, int $message_id): array {
		$msg = self::loadMessage($ctx, $message_id);
		if ($msg === null) {
			return array('ok' => false, 'error' => 'gone');
		}
		if (InboundEmailMessage::isBrowserSealed($msg)) {
			return array('ok' => false, 'error' => 'unavailable');
		}
		try {
			$kind = MessageRebuild::kindOf($msg);
			if ($kind === 'stored') {
				$bytes = $msg->getRawMessage();
				if ($bytes === null || $bytes === '') {
					return array('ok' => false, 'error' => 'unavailable');
				}
			} elseif ($kind === 'remote') {
				require_once(PathHelper::getIncludePath('plugins/mailbox/includes/message_export.php'));
				$resolved = mailbox_resolve_original($msg);
				if ($resolved['locked']) {
					return array('ok' => false, 'error' => 'locked');
				}
				if (!$resolved['ok'] || $resolved['kind'] !== 'imap') {
					return array('ok' => false, 'error' => 'unavailable');
				}
				$bytes = (string)$resolved['raw'];
			} else {
				$built = self::build($msg, true);
				$bytes = $built['bytes'];
			}
		} catch (VaultLockedException $e) {
			return array('ok' => false, 'error' => 'locked');
		}
		$told = self::fixSize($message_id, strlen($bytes));
		if ($told !== strlen($bytes)) {
			// The program was told another size: it reads up to that. Say so
			// loudly — a rebuild that is not byte-stable is a format bug.
			error_log('MailAppRebuild: message ' . $message_id . ' built ' . strlen($bytes)
				. ' bytes but ' . $told . ' were told (format ' . self::FORMAT . ').');
		}
		return array('ok' => true, 'bytes' => $bytes);
	}

	/**
	 * The lock notice: one generated message in Inbox while sealed mail waits
	 * on the lock. Its content never changes — it is built from its own number
	 * and the time it was numbered.
	 */
	public static function lockNotice(MailAppContext $ctx, int $uid, string $created_utc): string {
		$ts = strtotime($created_utc . ' UTC') ?: time();
		$domain = strtolower(substr(strrchr($ctx->address, '@') ?: '@localhost', 1));
		$url = $ctx->unlockUrl();
		$text = "Your mailbox is locked. New mail is waiting.\n\n"
			. "Unlock it at " . $url . "\n\n"
			. "Your mail program keeps showing the mail it already has. The waiting mail\n"
			. "arrives here once you unlock on the website, and this notice goes away.\n";
		$headers = array(
			'Date: ' . gmdate('D, d M Y H:i:s +0000', $ts),
			'From: ' . MessageRebuild::encodePhrase('Joinery') . ' <mailbox-locked@' . $domain . '>',
			'To: <' . $ctx->address . '>',
			'Subject: ' . MessageRebuild::encodeText('Your mailbox is locked — new mail is waiting'),
			'Message-ID: <lock-notice-' . $ctx->alias_id . '-' . $uid . '@' . $domain . '>',
			'MIME-Version: 1.0',
			'Content-Type: text/plain; charset=UTF-8',
			'Content-Transfer-Encoding: quoted-printable',
		);
		return implode("\r\n", $headers) . "\r\n\r\n" . MessageRebuild::qp($text) . "\r\n";
	}

	// ── internals ──────────────────────────────────────────────────────────

	/** The message, when it belongs to the context's mailbox. */
	private static function loadMessage(MailAppContext $ctx, int $message_id): ?InboundEmailMessage {
		if ($message_id <= 0) {
			return null;
		}
		$msg = new InboundEmailMessage($message_id, TRUE);
		if (!$msg->key || intval($msg->get('iem_iea_inbound_email_alias_id')) !== $ctx->alias_id) {
			return null;
		}
		return $msg;
	}

	/** Record the size a program is told, the first time; answer the size already told. */
	private static function fixSize(int $message_id, int $size): int {
		$db = DbConnector::get_instance()->get_db_link();
		$db->prepare('INSERT INTO mam_mail_app_messages (mam_iem_inbound_email_message_id, mam_client_size, mam_client_format)
			VALUES (?, ?, ?)
			ON CONFLICT (mam_iem_inbound_email_message_id) DO UPDATE
			SET mam_client_size = EXCLUDED.mam_client_size, mam_client_format = EXCLUDED.mam_client_format
			WHERE mam_mail_app_messages.mam_client_size IS NULL
			   OR mam_mail_app_messages.mam_client_format IS DISTINCT FROM EXCLUDED.mam_client_format')
			->execute(array($message_id, $size, self::FORMAT));
		$stmt = $db->prepare('SELECT mam_client_size FROM mam_mail_app_messages WHERE mam_iem_inbound_email_message_id = ?');
		$stmt->execute(array($message_id));
		$told = $stmt->fetchColumn();
		return $told !== false && $told !== null ? intval($told) : $size;
	}

	/**
	 * The Message-ID a mail program gave a message it submitted, for a row
	 * that recorded none of its own: the fallback MessageRebuild asks for.
	 */
	public static function submittedMessageId(int $message_id): string {
		$stmt = DbConnector::get_instance()->get_db_link()->prepare('SELECT mam_submitted_message_id
			FROM mam_mail_app_messages WHERE mam_iem_inbound_email_message_id = ?');
		$stmt->execute(array($message_id));
		return (string)$stmt->fetchColumn();
	}

	/** The rebuilt message, with this plugin's Message-ID fallback. */
	private static function build(InboundEmailMessage $msg, bool $with_bytes): array {
		return MessageRebuild::build($msg, $with_bytes, array(self::class, 'submittedMessageId'));
	}
}
?>
