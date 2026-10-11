<?php
/**
 * MailAppRebuild - the bytes a mail program is served for a message.
 *
 * IMAP clients ask for whole messages, and most stored mail keeps no original:
 * arrival keeps the header block (iem_raw_headers), the decoded text bodies and
 * each attachment as a private File (mailbox overview § Attachment & message
 * storage). So the plugin serves one of three things:
 *
 *   stored   the original, where one is stored whole (getRawMessage());
 *   remote   a message pulled in from a connected source and kept there: the
 *            original fetched live from the source, as Show original does;
 *   rebuilt  everything else: the retained header block (minus its MIME
 *            headers, or headers made from the row's columns when none was
 *            kept) over a rebuilt MIME tree — multipart/mixed over
 *            multipart/related (HTML and its inline parts by Content-ID) over
 *            multipart/alternative (plain and HTML), with the attachments in
 *            manifest order.
 *
 * THE SAME BYTES EVERY TIME. A mail program keeps each message's bytes by
 * number for good and reads them in pieces up to the size it was told, so a
 * rebuilt message must come out identical on every build: boundaries derived
 * from the message id and the format version, bodies always UTF-8
 * quoted-printable by this class's own encoder, attachments always base64 at
 * 76 columns, CRLF throughout. The output is FORMAT-versioned: changing a byte
 * of it means a new FORMAT, which starts every folder's numbering over
 * (MailAppFolders), never a silent change to bytes already served.
 *
 * Its size is known without reading an attachment: a file-backed part's
 * decoded length is recorded (ima_size_bytes), and base64's length follows
 * from it. The size is fixed at first telling (mam_client_size), because a
 * program asks for it in bulk.
 *
 * A mail app's copy is a faithful rendering of what Joinery holds, not a
 * claim to be the original — the reader's Download .eml still serves only a
 * stored original. The sender's DKIM signature does not verify against a
 * rebuilt body.
 *
 * Sealed content is opened in the window (the model's sealed-field read); a
 * closed one answers 'locked', and nothing is ever served in its place
 * (specs/mail_apps_plugin.md § Locked behaviour, rule 3).
 *
 * @version 1.0.0
 */

class MailAppRebuild {

	/** The rebuilt format. Changing ANY output byte means a new value. */
	const FORMAT = 1;

	/** base64 line length, and quoted-printable's maximum encoded line. */
	const LINE = 76;

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
			$kind = self::kindOf($msg);
			if ($kind === 'stored') {
				$raw = $msg->getRawMessage();
				if ($raw === null || $raw === '') {
					return array('ok' => false, 'error' => 'unavailable');
				}
				$size = strlen($raw);
				$header = self::headerOf($raw);
			} elseif ($kind === 'remote') {
				$size = intval($msg->get('iem_size_bytes'));
				if ($size <= 0) {
					return array('ok' => false, 'error' => 'unavailable');
				}
				$retained = trim((string)$msg->get('iem_raw_headers'), "\r\n");
				$header = $retained !== ''
					? self::crlf($retained) . "\r\n\r\n"
					: self::synthesizedHeaders($msg) . "\r\n\r\n";
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
			$kind = self::kindOf($msg);
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
			'From: ' . self::encodePhrase('Joinery') . ' <mailbox-locked@' . $domain . '>',
			'To: <' . $ctx->address . '>',
			'Subject: ' . self::encodeText('Your mailbox is locked — new mail is waiting'),
			'Message-ID: <lock-notice-' . $ctx->alias_id . '-' . $uid . '@' . $domain . '>',
			'MIME-Version: 1.0',
			'Content-Type: text/plain; charset=UTF-8',
			'Content-Transfer-Encoding: quoted-printable',
		);
		return implode("\r\n", $headers) . "\r\n\r\n" . self::qp($text) . "\r\n";
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

	/** stored | remote | rebuilt. */
	public static function kindOf(InboundEmailMessage $msg): string {
		$driver = (string)$msg->get('iem_raw_storage_driver') ?: 'inline';
		if ($driver === 'remote') {
			return 'remote';
		}
		if ($driver === 'local' || $driver === 'cloud') {
			return trim((string)$msg->get('iem_raw_storage_key')) !== '' ? 'stored' : 'rebuilt';
		}
		$stmt = DbConnector::get_instance()->get_db_link()->prepare('SELECT COALESCE(length(iem_raw_message), 0) > 0
			FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ?');
		$stmt->execute(array(intval($msg->key)));
		return $stmt->fetchColumn() ? 'stored' : 'rebuilt';
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

	/** The header block of a whole message, through its blank line. */
	private static function headerOf(string $raw): string {
		$pos = strpos($raw, "\r\n\r\n");
		if ($pos !== false) {
			return substr($raw, 0, $pos + 4);
		}
		$pos = strpos($raw, "\n\n");
		return $pos !== false ? substr($raw, 0, $pos + 2) : $raw;
	}

	/**
	 * Build the rebuilt message. With $with_bytes false, attachments are
	 * counted, never read.
	 *
	 * @return array{header:string, size:int, bytes?:string}
	 */
	private static function build(InboundEmailMessage $msg, bool $with_bytes): array {
		$id = intval($msg->key);
		$plain = (string)$msg->get('iem_body_plain');
		$html = (string)$msg->get('iem_body_html');

		$inline = array();
		$attached = array();
		$manifest = new MultiInboundMessageAttachment(array('message_id' => $id, 'file_backed' => true),
			array('ima_inbound_message_attachment_id' => 'ASC'));
		// An attached message (message/rfc822, "forward as attachment") travels
		// whole; arrival also lists its insides as parts of their own, which
		// would otherwise be served again beside it.
		$enclosing = array();
		foreach ($manifest as $att) {
			if (stripos((string)$att->get('ima_content_type'), 'message/') === 0) {
				$enclosing[] = (string)$att->get('ima_mime_part') . '.';
			}
		}
		foreach ($manifest as $att) {
			$part = (string)$att->get('ima_mime_part');
			foreach ($enclosing as $prefix) {
				if ($prefix !== '.' && strpos($part, $prefix) === 0) {
					continue 2;
				}
			}
			$cid = trim((string)$att->get('ima_content_id'), '<> ');
			if ($att->get('ima_is_inline') && $cid !== '' && $html !== '') {
				$inline[] = $att;
			} else {
				$attached[] = $att;
			}
		}

		// The tree, innermost first.
		$texts = array();
		if ($plain !== '' || $html === '') {
			$texts[] = self::textPart('text/plain', $plain);
		}
		if ($html !== '') {
			$texts[] = self::textPart('text/html', $html);
		}
		$body = count($texts) === 2 ? self::multipart('alternative', $id, $texts) : $texts[0];
		if ($inline) {
			$parts = array($body);
			foreach ($inline as $att) {
				$parts[] = self::attachmentPart($att, true);
			}
			$body = self::multipart('related', $id, $parts);
		}
		if ($attached) {
			$parts = array($body);
			foreach ($attached as $att) {
				$parts[] = self::attachmentPart($att, false);
			}
			$body = self::multipart('mixed', $id, $parts);
		}

		$header = self::messageHeaders($msg) . "\r\n" . 'MIME-Version: 1.0' . "\r\n" . $body['headers'] . "\r\n\r\n";
		$size = strlen($header);
		$out = $with_bytes ? $header : null;
		foreach ($body['segments'] as $segment) {
			if (is_string($segment)) {
				$size += strlen($segment);
				if ($with_bytes) {
					$out .= $segment;
				}
				continue;
			}
			$size += self::base64Length(intval($segment->get('ima_size_bytes')));
			if ($with_bytes) {
				$out .= self::base64Lines(self::attachmentBytes($msg, $segment));
			}
		}
		$size += 2;
		if ($with_bytes) {
			$out .= "\r\n";
		}
		$result = array('header' => $header, 'size' => $size);
		if ($with_bytes) {
			$result['bytes'] = $out;
		}
		return $result;
	}

	/** One attachment's decoded bytes, opened in the window when sealed. */
	private static function attachmentBytes(InboundEmailMessage $msg, InboundMessageAttachment $att): string {
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/attachment_retrieval.php'));
		$got = mailbox_retrieve_attachment_bytes($att, $msg);
		if (!empty($got['locked'])) {
			throw new VaultLockedException();
		}
		$bytes = $got['ok'] ? (string)$got['content'] : '';
		if (strlen($bytes) !== intval($att->get('ima_size_bytes'))) {
			// The size told came from the manifest; serving other bytes would
			// break the message in every program that cached it. Log, and keep
			// the length told so the message stays whole.
			error_log('MailAppRebuild: attachment ' . $att->key . ' of message ' . $msg->key . ' has '
				. strlen($bytes) . ' bytes, manifest says ' . intval($att->get('ima_size_bytes')));
			$bytes = str_pad(substr($bytes, 0, intval($att->get('ima_size_bytes'))), intval($att->get('ima_size_bytes')), "\0");
		}
		return $bytes;
	}

	/**
	 * The message's own headers: the retained header block without its MIME
	 * headers, or headers made from the row's columns. No trailing CRLF.
	 */
	private static function messageHeaders(InboundEmailMessage $msg): string {
		$retained = (string)$msg->get('iem_raw_headers');
		if (trim($retained) === '') {
			return self::synthesizedHeaders($msg);
		}
		$out = array();
		$skip = false;
		foreach (preg_split('/\r\n|\n|\r/', trim(self::crlf($retained), "\r\n")) as $line) {
			if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t")) {
				if (!$skip) {
					$out[] = $line;
				}
				continue;
			}
			$name = strtolower(trim((string)strstr($line, ':', true)));
			$skip = ($name === 'mime-version' || strpos($name, 'content-') === 0);
			if (!$skip && $line !== '') {
				$out[] = $line;
			}
		}
		return implode("\r\n", $out);
	}

	/** Headers made from the row's columns, for a row that kept no header block. */
	private static function synthesizedHeaders(InboundEmailMessage $msg): string {
		$ts = strtotime((string)$msg->get('iem_received_time') . ' UTC') ?: 0;
		$lines = array('Date: ' . gmdate('D, d M Y H:i:s +0000', $ts));
		$from = MailAddressList::parse((string)$msg->get('iem_sender'));
		if ($from) {
			$lines[] = 'From: ' . self::addressList($from);
		}
		$to = trim((string)$msg->get('iem_to'));
		if ($to === '' && InboundEmailMessage::isComposedDirection((string)$msg->get('iem_direction'))) {
			$to = (string)$msg->get('iem_recipient');
		}
		if ($to !== '' && ($list = MailAddressList::parse($to))) {
			$lines[] = 'To: ' . self::addressList($list);
		}
		$cc = trim((string)$msg->get('iem_cc'));
		if ($cc !== '' && ($list = MailAddressList::parse($cc))) {
			$lines[] = 'Cc: ' . self::addressList($list);
		}
		if (InboundEmailMessage::isComposedDirection((string)$msg->get('iem_direction'))) {
			$bcc = trim((string)$msg->get('iem_bcc'));
			if ($bcc !== '' && ($list = MailAddressList::parse($bcc))) {
				$lines[] = 'Bcc: ' . self::addressList($list);
			}
		}
		$lines[] = 'Subject: ' . self::encodeText((string)$msg->get('iem_subject'));
		$message_id = trim((string)$msg->get('iem_message_id_header'));
		if ($message_id === '') {
			$stmt = DbConnector::get_instance()->get_db_link()->prepare('SELECT mam_submitted_message_id
				FROM mam_mail_app_messages WHERE mam_iem_inbound_email_message_id = ?');
			$stmt->execute(array(intval($msg->key)));
			$message_id = trim((string)$stmt->fetchColumn());
		}
		if (preg_match('/^<[^<>\s]+>$/', $message_id)) {
			$lines[] = 'Message-ID: ' . $message_id;
		}
		return implode("\r\n", $lines);
	}

	/** An address list as a header value: names encoded, entries folded one per line. */
	private static function addressList(array $entries): string {
		$out = array();
		foreach ($entries as $e) {
			$email = preg_replace('/[\s<>"]/', '', (string)$e['email']);
			$name = trim((string)($e['name'] ?? ''));
			$out[] = $name !== '' ? self::encodePhrase($name) . ' <' . $email . '>' : '<' . $email . '>';
		}
		return implode(",\r\n ", $out);
	}

	/** A text part: UTF-8, quoted-printable. */
	private static function textPart(string $type, string $text): array {
		return array(
			'headers'  => 'Content-Type: ' . $type . '; charset=UTF-8' . "\r\n" . 'Content-Transfer-Encoding: quoted-printable',
			'segments' => array(self::qp($text)),
		);
	}

	/** An attachment part; its body is a placeholder segment the builder encodes or counts. */
	private static function attachmentPart(InboundMessageAttachment $att, bool $inline): array {
		$type = strtolower(trim((string)$att->get('ima_content_type')));
		if (!preg_match('~^[a-z0-9][a-z0-9!#$&^_.+-]*/[a-z0-9][a-z0-9!#$&^_.+-]*$~', $type)) {
			$type = 'application/octet-stream';
		}
		$name = trim(str_replace(array("\r", "\n", "\t"), ' ', (string)$att->get('ima_filename')));
		if ($name === '') {
			$name = 'attachment';
		}
		$headers = 'Content-Type: ' . $type . '; ' . self::nameParam('name', $name)
			. "\r\n" . 'Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; ' . self::nameParam('filename', $name)
			. "\r\n" . 'Content-Transfer-Encoding: base64';
		$cid = trim((string)$att->get('ima_content_id'), '<> ');
		if ($cid !== '' && preg_match('/^[\x21-\x7e]+$/', $cid)) {
			$headers .= "\r\n" . 'Content-ID: <' . $cid . '>';
		}
		return array('headers' => $headers, 'segments' => array($att));
	}

	/** A multipart over $parts, with a boundary derived from the message and the level. */
	private static function multipart(string $subtype, int $message_id, array $parts): array {
		$boundary = '=_jm' . self::FORMAT . '_' . substr(hash('sha256', 'mail_apps|' . self::FORMAT . '|'
			. $message_id . '|' . $subtype), 0, 28);
		$segments = array();
		foreach ($parts as $part) {
			$segments[] = '--' . $boundary . "\r\n" . $part['headers'] . "\r\n\r\n";
			foreach ($part['segments'] as $segment) {
				$segments[] = $segment;
			}
			$segments[] = "\r\n";
		}
		$segments[] = '--' . $boundary . '--';
		return array(
			'headers'  => 'Content-Type: multipart/' . $subtype . '; boundary="' . $boundary . '"',
			'segments' => $segments,
		);
	}

	/** A filename parameter: quoted when plain ASCII, RFC 2231 otherwise. */
	private static function nameParam(string $param, string $name): string {
		if (preg_match('/^[\x20-\x7e]+$/', $name) && strpbrk($name, "\"\\") === false) {
			return $param . '="' . $name . '"';
		}
		return $param . "*=UTF-8''" . rawurlencode($name);
	}

	/** base64 at 76 columns, CRLF between lines, none after the last. */
	public static function base64Lines(string $bytes): string {
		if ($bytes === '') {
			return '';
		}
		// chunk_split ends every line, the last included; the last CRLF is the
		// part's closing one, which multipart() writes.
		return substr(chunk_split(base64_encode($bytes), self::LINE, "\r\n"), 0, -2);
	}

	/** The length base64Lines() produces for $n bytes. */
	public static function base64Length(int $n): int {
		if ($n <= 0) {
			return 0;
		}
		$encoded = 4 * intdiv($n + 2, 3);
		$lines = intdiv($encoded + self::LINE - 1, self::LINE);
		return $encoded + 2 * ($lines - 1);
	}

	/**
	 * Quoted-printable (RFC 2045 § 6.7), this class's own encoder so the bytes
	 * never depend on a PHP build: line breaks become CRLF, printable ASCII but
	 * '=' passes, a space or tab passes except at the end of a line, everything
	 * else is =XX, and lines wrap with a soft break before 76 characters,
	 * never inside an escape.
	 */
	public static function qp(string $text): string {
		$text = str_replace(array("\r\n", "\r"), "\n", $text);
		$out = array();
		foreach (explode("\n", $text) as $line) {
			$encoded = '';
			$current = 0;
			$len = strlen($line);
			for ($i = 0; $i < $len; $i++) {
				$c = $line[$i];
				$o = ord($c);
				$last = ($i === $len - 1);
				if (($o >= 33 && $o <= 126 && $o !== 61) || (($o === 32 || $o === 9) && !$last)) {
					$token = $c;
				} else {
					$token = sprintf('=%02X', $o);
				}
				if ($current + strlen($token) > self::LINE - 1) {
					$encoded .= "=\r\n";
					$current = 0;
				}
				$encoded .= $token;
				$current += strlen($token);
			}
			$out[] = $encoded;
		}
		return implode("\r\n", $out);
	}

	/** Header text as RFC 2047 encoded words when it is not plain ASCII. */
	public static function encodeText(string $text): string {
		$text = trim(str_replace(array("\r", "\n", "\t"), ' ', $text));
		if (preg_match('/^[\x20-\x7e]*$/', $text)) {
			return $text;
		}
		return self::encodedWords($text);
	}

	/** A display name: quoted when plain ASCII, encoded words otherwise. */
	public static function encodePhrase(string $name): string {
		$name = trim(str_replace(array("\r", "\n", "\t", '"', '\\'), ' ', $name));
		if (preg_match('/^[\x20-\x7e]*$/', $name)) {
			return '"' . $name . '"';
		}
		return self::encodedWords($name);
	}

	/** UTF-8 base64 encoded words of at most 45 source bytes, folded. */
	private static function encodedWords(string $text): string {
		$words = array();
		$chunk = '';
		foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: str_split($text) as $char) {
			if (strlen($chunk) + strlen($char) > 45) {
				$words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
				$chunk = '';
			}
			$chunk .= $char;
		}
		if ($chunk !== '') {
			$words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
		}
		return implode("\r\n ", $words);
	}

	/** Line endings as CRLF. */
	private static function crlf(string $text): string {
		return preg_replace('/\r\n|\n|\r/', "\r\n", $text);
	}
}
?>
