<?php
/**
 * MailboxFortressParse - the server's half of parsing relay-sealed Fortress
 * mail in the owner's browser (specs/client_custody_mail.md § R9).
 *
 * A relay that fronts a Fortress mailbox seals each message to the owner's
 * browser-held mail key: the raw message under a fresh DEK (`v1.edge.`, AD
 * `mail:relay:{spool_id}`), the DEK to the key (`v1.edgeseal.mail.`). The pull
 * stores that as a PENDING row whose key is the relay's DEK and whose body is
 * iem_relay_sealed_raw (RelaySpoolConsumer::ingestClient). The server cannot
 * open either, so the first of the owner's browsers to unlock does the parse
 * (MailboxFortress.drainPending): it fetches the row here (next()), opens and
 * parses the message, seals every field and attachment under the SAME DEK, and
 * posts the ciphertext back (store()).
 *
 * store() takes the post only for the row's owner, only under the key the row
 * already has, and only once: a second post for a row that is no longer
 * pending changes nothing, which is what lets two devices race the same row.
 * A post under a key the row no longer has (a rotation re-wrapped it after
 * the fetch) is answered `stale`, and the browser fetches it again. The spam
 * disposition is the relay's (the X-Spam* values the browser read out of the
 * message, InboundEmailRouter::spamFromBrowserHeaders()); mail rules do not
 * run on this path.
 *
 * The parts arrive as ONE upload, `bundle`, each part's ciphertext at an
 * offset: PHP drops every upload past max_file_uploads (20 on this stack), and
 * a newsletter with more inline images than that is ordinary (B37).
 *
 * @version 1.2 - pendingCount() by domain, for the lowering receipt (B46)
 * @version 1.1 - one bundle upload for every part (B37); Files are made before the row's
 *                transaction, so a refused store can delete them (B39); a stale key is
 *                its own answer (B44); the parse is stored under the row's key whatever
 *                the mailbox's level is now (B45); unopenable rows are not handed out
 * @version 1.0
 */

class MailboxFortressParseException extends Exception {}

class MailboxFortressParse {

	/** The columns a parse may fill (InboundEmailMessage::$sealed_fields, less the compose-only ones). */
	const FIELDS = array('iem_sender', 'iem_subject', 'iem_body_plain', 'iem_body_html', 'iem_raw_headers',
		'iem_to', 'iem_cc', 'iem_search_text', 'iem_snippet', 'iem_attachment_manifest');

	/** A part name as the parser numbers it: '1', '2.1', '2.1.3'. */
	const PART_PATTERN = '/^[1-9][0-9]{0,4}(\.[1-9][0-9]{0,4}){0,29}$/';

	/** More parts than any real message carries; the byte bound below does the real work. */
	const MAX_PARTS = 500;

	/** The generic refusal: the post is not what this row's owner's browser would send. */
	const REFUSAL = 'This message could not be stored as sealed. Please report this problem.';

	/** A relay-sealed row still waiting for its owner's browser (with mailKeySql()). */
	const PENDING_WHERE = 'iem_pending_parse = true AND iem_sealed_owner_user_id = ? AND iem_delete_time IS NULL
			AND iem_relay_sealed_raw IS NOT NULL AND iem_key_generation > 0';

	/**
	 * How many relay-sealed rows $user_id's browser still has to parse, on
	 * $domain_id when one is named. Whatever the mailbox's level is now: a
	 * mailbox moved off Fortress still has these for the browser to open
	 * before the lowering can move them (B46).
	 */
	public static function pendingCount(int $user_id, int $domain_id = 0): int {
		$stmt = DbConnector::get_instance()->get_db_link()->prepare('SELECT count(*) FROM iem_inbound_email_messages
			WHERE ' . self::PENDING_WHERE . ' AND ' . InboundEmailMessage::mailKeySql()
			. ($domain_id > 0 ? ' AND iem_ied_inbound_email_domain_id = ' . intval($domain_id) : ''));
		$stmt->execute(array($user_id));
		return intval($stmt->fetchColumn());
	}

	/**
	 * The newest relay-sealed row $user_id still has to parse, with how many
	 * remain (this one included). Newest first, the order DeferredIngest drains
	 * in, so the mail you are most likely to look for opens first.
	 *
	 * $skip names rows this device already failed to parse, so one message it
	 * cannot read does not stand in front of the rest.
	 *
	 * @param int[] $skip
	 * @return array{remaining:int, item:?array}
	 */
	public static function next(int $user_id, array $skip = array()): array {
		$db = DbConnector::get_instance()->get_db_link();
		$where = self::PENDING_WHERE . ' AND ' . InboundEmailMessage::mailKeySql();
		$remaining = self::pendingCount($user_id);
		if ($remaining === 0) {
			return array('remaining' => 0, 'item' => null);
		}
		$skip = array_values(array_slice(array_unique(array_filter(array_map('intval', $skip))), 0, 100));
		$not = $skip ? ' AND iem_inbound_email_message_id NOT IN (' . implode(',', array_fill(0, count($skip), '?')) . ')' : '';
		$stmt = $db->prepare('SELECT iem_inbound_email_message_id, iem_sealed_key, iem_relay_sealed_raw, iem_relay_spool_id
			FROM iem_inbound_email_messages WHERE ' . $where . $not . '
			ORDER BY iem_received_time DESC, iem_inbound_email_message_id DESC LIMIT 1');
		$stmt->execute(array_merge(array($user_id), $skip));
		$r = $stmt->fetch(PDO::FETCH_ASSOC);
		if (!$r) {
			return array('remaining' => 0, 'item' => null);
		}
		return array('remaining' => $remaining, 'item' => array(
			'id'               => intval($r['iem_inbound_email_message_id']),
			'sealed_dek'       => (string)$r['iem_sealed_key'],
			'sealed_raw'       => (string)$r['iem_relay_sealed_raw'],
			'raw_ad'           => 'mail:relay:' . (string)$r['iem_relay_spool_id'],
			'sealed_ad_prefix' => InboundEmailMessage::sealedAdPrefix(),
		));
	}

	/**
	 * Store one parsed row from an API post. $params: id, sealed_dek (the key
	 * the browser used, which must be the row's), fields (column => `v1.edge.`
	 * or ''), parts ([{mime_part, size, inline, offset, length}], each part's
	 * ciphertext at that place in the one uploaded $bundle) and spam_headers
	 * ({x_spam, x_spam_flag, x_spam_score, x_spam_status}).
	 *
	 * @param array|null $bundle the `bundle` entry of $_FILES
	 * @return array{id:int, stored:bool, stale?:bool}
	 * @throws MailboxFortressParseException
	 */
	public static function store(int $user_id, array $params, ?array $bundle = null): array {
		return self::storeParts($user_id, $params, self::uploads($params['parts'] ?? array(), $bundle));
	}

	/**
	 * store() once the parts are read: $parts is [{mime_part, size, inline,
	 * bytes}], bytes being the part's `v1.edge.` ciphertext.
	 *
	 * The parts' Files are made first, each committed on its own: a File row
	 * made inside a transaction that then rolls back takes its bytes' only
	 * reference with it, and nothing could release them. The row is then
	 * locked and checked again, and its attachment rows, fields and pending
	 * state change in one transaction. Whatever does not end stored deletes the
	 * Files it made.
	 *
	 * @return array{id:int, stored:bool, stale?:bool}
	 * @throws MailboxFortressParseException
	 */
	public static function storeParts(int $user_id, array $params, array $parts): array {
		$id = intval($params['id'] ?? 0);
		$sealed_dek = (string)($params['sealed_dek'] ?? '');
		$fields = is_array($params['fields'] ?? null) ? $params['fields'] : array();
		foreach ($fields as $col => $value) {
			if (!in_array($col, self::FIELDS, true)) {
				throw self::refuse($id, 'A parsed message does not carry "' . $col . '".');
			}
			if ($value !== '' && (!is_string($value) || !VaultCrypto::isEdgeField($value))) {
				throw self::refuse($id, self::REFUSAL);
			}
		}

		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare('SELECT * FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ?');
		$stmt->execute(array($id));
		$answer = self::standing($stmt->fetch(PDO::FETCH_ASSOC) ?: null, $user_id, $sealed_dek, $id);
		if ($answer !== null) {
			return $answer;
		}
		$stmt->execute(array($id));
		$checked = self::checkParts($parts, intval(($stmt->fetch(PDO::FETCH_ASSOC) ?: array())['iem_size_bytes'] ?? 0), $id);

		$made = array();
		$stored = false;
		try {
			foreach ($checked as $u) {
				$made[] = array('part' => $u, 'file' => InboundEmailMessage::storeFortressPartFile($id, $user_id, $u));
			}
			$db->beginTransaction();
			// One parse per row: the lock makes a second device's post wait, then
			// find the row parsed and change nothing.
			$lock = $db->prepare('SELECT * FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ? FOR UPDATE');
			$lock->execute(array($id));
			$row = $lock->fetch(PDO::FETCH_ASSOC) ?: null;
			$answer = self::standing($row, $user_id, $sealed_dek, $id);
			if ($answer !== null) {
				$db->rollBack();
				return $answer;
			}
			foreach ($made as $m) {
				self::attachmentRow($id, $m['part'], $m['file']);
			}
			InboundEmailMessage::acceptRelayParse($id, $fields);

			$auth = array(
				'dkim'   => (string)$row['iem_dkim_result'],
				'spf'    => (string)$row['iem_spf_result'],
				'dmarc'  => (string)$row['iem_dmarc_result'],
				'source' => (string)$row['iem_auth_source'],
			);
			$spam = (new InboundEmailRouter())->spamFromBrowserHeaders(
				is_array($params['spam_headers'] ?? null) ? $params['spam_headers'] : array(), $auth);
			InboundEmailMessage::updateColumns($id, array(
				'iem_pending_parse'    => false,
				'iem_relay_sealed_raw' => null,
				'iem_spam_verdict'     => $spam['verdict'],
				'iem_spam_score'       => $spam['score'],
			));
			$db->commit();
			$stored = true;
		} catch (Throwable $e) {
			if ($db->inTransaction()) {
				$db->rollBack();
			}
			if ($e instanceof MailboxFortressParseException) {
				throw $e;
			}
			throw self::refuse($id, self::REFUSAL, $e->getMessage());
		} finally {
			if (!$stored) {
				foreach ($made as $m) {
					try { $m['file']->permanent_delete(); } catch (Throwable $e2) {
						error_log('MailboxFortressParse: row ' . $id . ': could not remove a part it stored: ' . $e2->getMessage());
					}
				}
			}
		}
		return array('id' => $id, 'stored' => true);
	}

	/**
	 * What a row says to a post before anything is written: null to go on, an
	 * answer (already parsed; the key changed since the fetch), or a refusal.
	 */
	private static function standing(?array $row, int $user_id, string $sealed_dek, int $id): ?array {
		if (!$row || intval($row['iem_sealed_owner_user_id']) !== $user_id || $row['iem_delete_time'] !== null) {
			throw self::refuse($id, 'That message is not one of yours to open.');
		}
		if (!self::isTrue($row['iem_pending_parse'])) {
			return array('id' => $id, 'stored' => false);
		}
		$key = (string)$row['iem_sealed_key'];
		if (strncmp($key, InboundEmailMessage::MAIL_KEY_PREFIX, strlen(InboundEmailMessage::MAIL_KEY_PREFIX)) !== 0
				|| intval($row['iem_key_generation']) === InboundEmailMessage::RELAY_UNOPENABLE_GENERATION
				|| $row['iem_relay_sealed_raw'] === null) {
			throw self::refuse($id, self::REFUSAL);
		}
		if (!hash_equals($key, $sealed_dek)) {
			// Fields sealed under any other key would sit under a key nothing
			// records. Usually a rotation re-wrapped the row after the fetch:
			// the browser fetches it again (B44).
			return array('id' => $id, 'stored' => false, 'stale' => true);
		}
		return null;
	}

	/** A refusal, logged with its row: the browser shows it only in its console (B43). */
	private static function refuse(int $id, string $message, string $cause = ''): MailboxFortressParseException {
		error_log('MailboxFortressParse: row ' . $id . ' refused: ' . $message . ($cause !== '' ? ' (' . $cause . ')' : ''));
		return new MailboxFortressParseException($message);
	}

	/** The posted parts cut out of the one uploaded bundle: [{mime_part, size, inline, bytes}]. */
	private static function uploads($parts, ?array $bundle): array {
		$parts = is_array($parts) ? $parts : array();
		if (!$parts) {
			return array();
		}
		if (count($parts) > self::MAX_PARTS || !$bundle || ($bundle['error'] ?? 1) !== UPLOAD_ERR_OK) {
			throw new MailboxFortressParseException('The attachments failed to upload.');
		}
		$tmp = (string)($bundle['tmp_name'] ?? '');
		if ($tmp === '' || !is_uploaded_file($tmp)) {
			throw new MailboxFortressParseException(self::REFUSAL);
		}
		$all = file_get_contents($tmp);
		if ($all === false) {
			throw new MailboxFortressParseException(self::REFUSAL);
		}
		$out = array();
		foreach ($parts as $p) {
			$offset = intval($p['offset'] ?? -1);
			$length = intval($p['length'] ?? -1);
			if ($offset < 0 || $length <= 0 || $offset + $length > strlen($all)) {
				throw new MailboxFortressParseException(self::REFUSAL);
			}
			$out[] = array('mime_part' => (string)($p['mime_part'] ?? ''), 'size' => $p['size'] ?? -1,
				'inline' => $p['inline'] ?? false, 'bytes' => substr($all, $offset, $length));
		}
		return $out;
	}

	/**
	 * The parts checked: a part number, named once, ciphertext in the browser's
	 * format whose length fits the plaintext size declared, and together no
	 * larger than the message they came out of.
	 */
	private static function checkParts(array $parts, int $message_bytes, int $id): array {
		if (count($parts) > self::MAX_PARTS) {
			throw self::refuse($id, self::REFUSAL, 'too many parts');
		}
		$seen = array();
		$total = 0;
		$out = array();
		foreach ($parts as $p) {
			$part = (string)($p['mime_part'] ?? '');
			if (!preg_match(self::PART_PATTERN, $part) || isset($seen[$part])) {
				throw self::refuse($id, self::REFUSAL, 'bad part name');
			}
			$seen[$part] = true;
			$bytes = (string)($p['bytes'] ?? '');
			$size = intval($p['size'] ?? -1);
			// base64 of IV + ciphertext + tag, behind the v1.edge. prefix.
			if ($size < 0 || strlen($bytes) !== 8 + 4 * intval(ceil(($size + 28) / 3)) || !VaultCrypto::isEdgeField($bytes)) {
				throw self::refuse($id, self::REFUSAL, 'part ' . $part . ' is not the ciphertext of its size');
			}
			// A decoded part is never larger than the encoded message it came from.
			$total += $size;
			if ($total > $message_bytes) {
				throw self::refuse($id, self::REFUSAL, 'parts larger than the message');
			}
			$inline = $p['inline'] ?? false;
			$out[] = array('mime_part' => $part, 'bytes' => $bytes, 'size' => $size,
				'inline' => !empty($inline) && $inline !== 'false');
		}
		return $out;
	}

	/** The part's attachment row, with nothing in the clear; inside the row's transaction. */
	private static function attachmentRow(int $message_id, array $u, File $file): void {
		InboundMessageAttachment::CreateEntry(array(
			'ima_iem_inbound_email_message_id' => $message_id,
			'ima_filename'     => '',
			'ima_content_type' => '',
			'ima_size_bytes'   => $u['size'],
			'ima_mime_part'    => $u['mime_part'],
			'ima_content_id'   => '',
			'ima_is_inline'    => $u['inline'],
			'ima_fil_file_id'  => (int)$file->key,
			'ima_is_sealed'    => true,
		));
	}

	private static function isTrue($v): bool {
		return $v === true || $v === 't' || $v === 1 || $v === '1';
	}
}
?>
