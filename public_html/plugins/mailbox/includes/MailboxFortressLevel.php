<?php
/**
 * MailboxFortressLevel - moving stored mail onto and off end-to-end
 * (specs/client_custody_mail.md § R8, WP5).
 *
 * The raise. When a mailbox goes to Fortress its level flips at once (new mail
 * seals to the owner's `mail` key), and the mail already stored moves over in
 * the owner's unlock window as deferred work (`mailbox_fortress_raise`):
 * convertRow() takes one message, Private or Standard, and leaves it exactly as
 * a Fortress message arrives:
 *   - every content column in the browser's format under the message DEK, the
 *     DEK sealed to the mail key (a Private row keeps its DEK:
 *     SystemBase::convertRowToClientCustody(); a Standard row gets one);
 *   - every attachment re-stored as shape (a) under that DEK, the File named
 *     by message and part and typed octet-stream, the ima_ name, type and
 *     Content-ID blank, and the real ones in the sealed manifest;
 *   - search text, snippet and manifest sealed beside it;
 *   - no raw message kept.
 * Rows not yet moved read through the server window as before.
 *
 * The lowering. The owner's browser moves each message's DEK to the server's
 * key (JoinerySealed.changeCustody, VaultCustodyChange); the row keeps its
 * browser-format fields, which the window opens. What the browser cannot do
 * is put the attachment names back on the ima_ rows, since that needs the
 * manifest opened: settleLowered() does it in the window as deferred work
 * (`mailbox_fortress_lowered`), clears the Fortress-only columns (the manifest
 * is the marker that the row is not settled yet), and queues the row for the
 * server search index.
 *
 * Both run only as the vault's deferred work (VaultDeferredWork), which the
 * vault client drives on every page while the window is open; the receipt and
 * the mailbox banner only count (mailbox/fortress_backlog).
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/attachment_retrieval.php'));

class MailboxFortressLevel {

	/** Rows a raise pass takes at most. */
	const RAISE_MAX_ROWS = 100;

	/** Attachment bytes a raise pass reads at most (it stops after the row that crosses it). */
	const RAISE_MAX_BYTES = 67108864;

	/** Rows a settle pass takes at most. */
	const SETTLE_MAX_ROWS = 200;

	/** How long a raise passes by a row whose move failed before trying it again. */
	const RAISE_RETRY_SECONDS = 3600;

	/**
	 * $user_id's messages on a Fortress mailbox not yet sealed to the mail key:
	 * theirs by a grant on the mailbox, or, for mail kept for no mailbox, by
	 * owning the domain. Soft-deleted rows count (a deleted message is
	 * restorable). A relay row still waiting to be parsed does not: parsing
	 * seals it to the mail key. $domain_id narrows to one domain.
	 *
	 * $ready narrows to the rows a pass can take now: not sealed to someone
	 * else's server key (a mailbox that changed hands keeps its old rows on the
	 * old owner's key; only that owner's window opens them), and not failed
	 * within RAISE_RETRY_SECONDS. What is left over is what the receipt reports
	 * as not moved.
	 */
	private static function backlogWhere(int $domain_id, bool $ready = false): string {
		return "FROM iem_inbound_email_messages m
			LEFT JOIN iea_inbound_email_aliases a ON a.iea_inbound_email_alias_id = m.iem_iea_inbound_email_alias_id
			JOIN ied_inbound_email_domains d ON d.ied_inbound_email_domain_id = m.iem_ied_inbound_email_domain_id
			WHERE " . InboundEmailAlias::effectiveLevelSql('a', 'd') . " = '" . InboundEmailDomain::LEVEL_FORTRESS . "'
			  AND m.iem_pending_parse = false
			  AND (m.iem_sealed_key IS NULL OR NOT " . InboundEmailMessage::mailKeySql('m.iem_sealed_key') . ")
			  AND (m.iem_iea_inbound_email_alias_id IN
			         (SELECT ieg_iea_inbound_email_alias_id FROM ieg_inbound_email_mailbox_grants WHERE ieg_usr_user_id = :uid)
			       OR (m.iem_iea_inbound_email_alias_id IS NULL AND d.ied_owner_usr_user_id = :uid))"
			. ($ready ? "
			  AND (m.iem_sealed_key IS NULL OR m.iem_sealed_owner_user_id = :uid)
			  AND (m.iem_fortress_move_attempt_time IS NULL
			       OR m.iem_fortress_move_attempt_time < NOW() AT TIME ZONE 'UTC' - INTERVAL '" . self::RAISE_RETRY_SECONDS . " seconds')" : '')
			. ($domain_id > 0 ? ' AND m.iem_ied_inbound_email_domain_id = ' . intval($domain_id) : '');
	}

	/**
	 * How many of $user_id's messages are still waiting to move to their device
	 * key; with $ready, only those a pass can take now (backlogWhere()).
	 */
	public static function backlogCount(int $user_id, int $domain_id = 0, bool $ready = false): int {
		if ($user_id <= 0) {
			return 0;
		}
		$stmt = DbConnector::get_instance()->get_db_link()->prepare('SELECT COUNT(*) ' . self::backlogWhere($domain_id, $ready));
		$stmt->execute(array(':uid' => $user_id));
		return intval($stmt->fetchColumn());
	}

	/** The deferred-work predicate: one indexed probe. */
	public static function hasRaiseWork(int $user_id): bool {
		if ($user_id <= 0) {
			return false;
		}
		$stmt = DbConnector::get_instance()->get_db_link()->prepare('SELECT 1 ' . self::backlogWhere(0, true) . ' LIMIT 1');
		$stmt->execute(array(':uid' => $user_id));
		return (bool)$stmt->fetchColumn();
	}

	/**
	 * Move $user_id's waiting messages to their mail key until $deadline, at
	 * most RAISE_MAX_ROWS rows or RAISE_MAX_BYTES of attachments. Each row
	 * commits on its own, so a pass cut short loses nothing and the search
	 * index's catch-up (iem_search_written_time) sees each row as it lands.
	 * A row that fails is logged and stamped (iem_fortress_move_attempt_time),
	 * so passes take the rows behind it until RAISE_RETRY_SECONDS have gone by.
	 */
	public static function drainRaise(int $user_id, VaultKey $key, float $deadline): int {
		$vault = InboundEmailMessage::loadSealVault($user_id, InboundEmailMessage::SEAL_SCOPE_FORTRESS);
		if ($vault === null) {
			return 0;   // no mail key: nothing can move (the level change refuses to create this state)
		}
		$stmt = DbConnector::get_instance()->get_db_link()->prepare('SELECT m.iem_inbound_email_message_id '
			. self::backlogWhere(0, true) . ' ORDER BY m.iem_inbound_email_message_id LIMIT ' . self::RAISE_MAX_ROWS);
		$stmt->execute(array(':uid' => $user_id));
		$moved = 0;
		$bytes = 0;
		foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
			if (microtime(true) >= $deadline || $bytes >= self::RAISE_MAX_BYTES) {
				break;
			}
			try {
				$result = self::convertRow(intval($id), $key, $vault);
				if ($result !== null) {
					$moved++;
					$bytes += $result;
				}
			} catch (VaultLockedException $e) {
				break;   // the window closed under us: the next unlock carries on
			} catch (\Throwable $e) {
				error_log('MailboxFortressLevel: message ' . intval($id) . ' could not move to the device key: ' . $e->getMessage());
				InboundEmailMessage::updateColumns(intval($id), array('iem_fortress_move_attempt_time' => gmdate('Y-m-d H:i:s')));
			}
		}
		return $moved;
	}

	/**
	 * Move one message to $mail_vault's key (see the class comment). Returns
	 * the attachment bytes it read, or null when there was nothing to do (the
	 * row is gone, already on the mail key, or waiting to be parsed). A part
	 * whose bytes are no longer anywhere to be had stays listed, without a
	 * file, as the reader already shows such a part. Throws
	 * VaultLockedException when the window closed, anything else on failure,
	 * leaving the row as it was.
	 */
	public static function convertRow(int $message_id, VaultKey $key, UserEncryptionVault $mail_vault): ?int {
		$msg = new InboundEmailMessage($message_id, TRUE);
		if (!$msg->key || InboundEmailMessage::isBrowserSealed($msg) || $msg->get('iem_pending_parse')) {
			return null;
		}
		if ((string)$mail_vault->get('uev_scope') !== InboundEmailMessage::SEAL_SCOPE_FORTRESS) {
			throw new RuntimeException('A message moves to the mail vault only.');
		}
		$sealed = $msg->rowIsSealed();
		$crypto = new VaultCrypto();

		// 1. The content, opened in the window (a Standard row holds it plainly).
		// Read from the table: the model's get() opens through the session's
		// window, and this opens with the key it was handed.
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare('SELECT iem_sealed_key, iem_sender, iem_subject, iem_body_plain, iem_body_html, iem_attachment_manifest
			FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ?');
		$q->execute(array($message_id));
		$stored = $q->fetch(PDO::FETCH_ASSOC);
		$dek_old = $sealed ? $crypto->openItemDek((string)$stored['iem_sealed_key'], $key) : null;
		$open = function (string $col) use ($stored, $sealed, $crypto, $dek_old, $message_id): string {
			$value = (string)($stored[$col] ?? '');
			return ($sealed && $value !== '')
				? $crypto->openField($value, $dek_old, InboundEmailMessage::sealAd($message_id, $col)) : $value;
		};
		$content = array('sender' => $open('iem_sender'), 'subject' => $open('iem_subject'),
			'body_plain' => $open('iem_body_plain'), 'body_html' => $open('iem_body_html'));
		// A row moved off end-to-end and back before its names were put back
		// still has them only in its manifest.
		$named = array();
		$old_manifest = json_decode($open('iem_attachment_manifest'), true);
		foreach (is_array($old_manifest) ? $old_manifest : array() as $entry) {
			$named[intval($entry['id'] ?? 0)] = $entry;
		}

		// 2. Every part's bytes, from wherever they are kept: a File in either
		// sealed shape, or the stored raw.
		$parts = array();
		$read = 0;
		$atts = new MultiInboundMessageAttachment(array('message_id' => $message_id));
		foreach ($atts as $att) {
			$got = mailbox_retrieve_attachment_bytes($att, $msg);
			if (!$got['ok'] && $got['locked']) {
				throw new VaultLockedException();
			}
			$bytes = $got['ok'] ? (string)$got['content'] : null;
			$read += $bytes === null ? 0 : strlen($bytes);
			$was = $named[intval($att->key)] ?? array();
			$parts[] = array('att' => $att, 'bytes' => $bytes,
				'filename' => (string)$att->get('ima_filename') !== '' ? (string)$att->get('ima_filename') : (string)($was['filename'] ?? ''),
				'content_type' => (string)$att->get('ima_content_type') !== '' ? (string)$att->get('ima_content_type') : (string)($was['content_type'] ?? ''),
				'content_id' => (string)$att->get('ima_content_id') !== '' ? (string)$att->get('ima_content_id') : (string)($was['content_id'] ?? ''));
		}
		// A stored raw with no manifest rows (their write failed at arrival)
		// still holds its parts; they get rows here, since the raw goes.
		$raw_parts = array();
		if (!$parts && (string)$msg->get('iem_raw_storage_driver') !== 'remote') {
			$raw = $msg->getRawMessage();
			if ($raw !== null && $raw !== '') {
				foreach ((new InboundEmailRouter())->enumerateNonTextParts($raw) as $part) {
					$cid = (string)$part->getContentId();
					$disp = $part->getDisposition();
					$bytes = (string)$part->getContents();
					$read += strlen($bytes);
					$raw_parts[] = array('bytes' => $bytes, 'mime_part' => substr((string)$part->getMimeId(), 0, 40),
						'filename' => $part->getName() ? substr((string)$part->getName(), 0, 500) : '',
						'content_type' => substr((string)$part->getType() ?: 'application/octet-stream', 0, 255),
						'content_id' => $cid !== '' ? substr(trim($cid, '<>'), 0, 255) : '',
						'inline' => ($disp === 'inline') || ($cid !== '' && $disp !== 'attachment'));
				}
			}
		}

		$owner_id = intval($mail_vault->get('uev_usr_user_id'));
		$old_files = array();
		$new_files = array();
		$manifest = array();
		$db->beginTransaction();
		try {
			// Nothing may have moved the row since it was read: the lock holds
			// off anyone else until this commits, and a changed key means someone
			// did, so this pass leaves it (its part reads may predate that).
			$lock = $db->prepare('SELECT iem_sealed_key FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ? FOR UPDATE');
			$lock->execute(array($message_id));
			if ((string)$lock->fetchColumn() !== (string)$stored['iem_sealed_key']) {
				$db->rollBack();
				return null;
			}
			// 3. The row: same DEK for a Private row, a fresh one for a Standard row.
			$dek = $sealed
				? InboundEmailMessage::convertRowToClientCustody($message_id, $key, $mail_vault)
				: InboundEmailMessage::sealExistingRow($msg, $mail_vault);

			// 4. The parts, as shape (a) under that DEK, named by message and part.
			$update = $db->prepare("UPDATE ima_inbound_message_attachments
				SET ima_filename = '', ima_content_type = '', ima_content_id = '', ima_fil_file_id = ?, ima_is_sealed = ?
				WHERE ima_inbound_message_attachment_id = ?");
			foreach ($parts as $p) {
				$att = $p['att'];
				$mime_part = (string)$att->get('ima_mime_part');
				$file_id = null;
				if ($p['bytes'] !== null) {
					$file = File::createFromBytes(
						InboundEmailMessage::sealAttachmentBytes($p['bytes'], $dek, $message_id, $mime_part, true),
						InboundEmailMessage::fortressAttachmentName($message_id, $mime_part),
						InboundEmailMessage::FORTRESS_FILE_TYPE, $owner_id,
						array('fil_private' => true, 'fil_source' => File::SOURCE_EMAIL_ATTACHMENT));
					$file->set('fil_type', InboundEmailMessage::FORTRESS_FILE_TYPE);
					$file->save();
					$new_files[] = $file;
					$file_id = intval($file->key);
				}
				if (intval($att->get('ima_fil_file_id')) > 0) {
					$old_files[] = intval($att->get('ima_fil_file_id'));
				}
				$update->execute(array($file_id, $file_id !== null ? 'true' : 'false', intval($att->key)));
				$manifest[] = InboundEmailMessage::manifestEntry(intval($att->key), $p['filename'],
					$p['content_type'] ?: 'application/octet-stream', $p['content_id'],
					$mime_part, (bool)$att->get('ima_is_inline'), intval($att->get('ima_size_bytes')));
			}
			foreach ($raw_parts as $p) {
				$file = File::createFromBytes(
					InboundEmailMessage::sealAttachmentBytes($p['bytes'], $dek, $message_id, $p['mime_part'], true),
					InboundEmailMessage::fortressAttachmentName($message_id, $p['mime_part']),
					InboundEmailMessage::FORTRESS_FILE_TYPE, $owner_id,
					array('fil_private' => true, 'fil_source' => File::SOURCE_EMAIL_ATTACHMENT));
				$file->set('fil_type', InboundEmailMessage::FORTRESS_FILE_TYPE);
				$file->save();
				$new_files[] = $file;
				$att = InboundMessageAttachment::CreateEntry(array(
					'ima_iem_inbound_email_message_id' => $message_id,
					'ima_filename' => '', 'ima_content_type' => '', 'ima_content_id' => '',
					'ima_size_bytes' => strlen($p['bytes']), 'ima_mime_part' => $p['mime_part'], 'ima_encoding' => '',
					'ima_is_inline' => $p['inline'], 'ima_fil_file_id' => intval($file->key), 'ima_is_sealed' => true,
				));
				$manifest[] = InboundEmailMessage::manifestEntry(intval($att->key), $p['filename'], $p['content_type'],
					$p['content_id'], $p['mime_part'], $p['inline'], strlen($p['bytes']));
			}

			// 5. What the server derives from plaintext, sealed beside it; no raw kept.
			InboundEmailMessage::sealFortressDerived($message_id, $mail_vault, $dek, $content, $manifest);
			$raw = array('driver' => (string)$msg->get('iem_raw_storage_driver'), 'key' => (string)$msg->get('iem_raw_storage_key'));
			$db->prepare("UPDATE iem_inbound_email_messages
				SET iem_raw_message = '', iem_raw_sealed = false, iem_raw_storage_driver = 'inline', iem_raw_storage_key = NULL,
				    iem_fortress_move_attempt_time = NULL
				WHERE iem_inbound_email_message_id = ?")->execute(array($message_id));
			$db->commit();
		} catch (\Throwable $e) {
			if ($db->inTransaction()) {
				$db->rollBack();
			}
			foreach ($new_files as $f) {
				try { $f->permanent_delete(); } catch (\Throwable $ignore) {}
			}
			throw $e;
		}

		// After the commit, what the row no longer points at.
		foreach ($old_files as $fid) {
			try {
				$old = new File($fid, TRUE);
				if ($old->key) {
					$old->permanent_delete();
				}
			} catch (\Throwable $e) {
				error_log('MailboxFortressLevel: the old file ' . $fid . ' of message ' . $message_id . ' was not removed: ' . $e->getMessage());
			}
		}
		if ($raw['driver'] === 'local' || $raw['driver'] === 'cloud') {
			try {
				RawMessageStore::delete($raw['driver'], $raw['key']);
			} catch (\Throwable $e) {
				error_log('MailboxFortressLevel: the stored raw of message ' . $message_id . ' was not removed: ' . $e->getMessage());
			}
		}
		// The server index drops it at its next fold (a Fortress row is never indexed there).
		MailboxIndex::enqueueRefold(intval($msg->get('iem_iea_inbound_email_alias_id')), $message_id);
		return $read;
	}

	// ------------------------------------------------------------ the lowering

	/**
	 * $user_id's messages moved off the mail key whose attachment names are
	 * still only in the sealed manifest: a manifest on a row no longer on the
	 * mail key. (Not the key's frame: a rotation re-seals a lowered row's key
	 * into the server's own format.)
	 */
	private static function loweredWhere(): string {
		return "FROM iem_inbound_email_messages
			WHERE iem_sealed_owner_user_id = ? AND iem_sealed_key IS NOT NULL
			  AND NOT " . InboundEmailMessage::mailKeySql() . "
			  AND iem_attachment_manifest IS NOT NULL AND iem_attachment_manifest <> ''";
	}

	public static function hasSettleWork(int $user_id): bool {
		if ($user_id <= 0) {
			return false;
		}
		$stmt = DbConnector::get_instance()->get_db_link()->prepare('SELECT 1 ' . self::loweredWhere() . ' LIMIT 1');
		$stmt->execute(array($user_id));
		return (bool)$stmt->fetchColumn();
	}

	/** Settle $user_id's lowered messages until $deadline (settleLowered()). */
	public static function drainSettle(int $user_id, VaultKey $key, float $deadline): int {
		$stmt = DbConnector::get_instance()->get_db_link()->prepare('SELECT iem_inbound_email_message_id '
			. self::loweredWhere() . ' ORDER BY iem_inbound_email_message_id LIMIT ' . self::SETTLE_MAX_ROWS);
		$stmt->execute(array($user_id));
		$done = 0;
		foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
			if (microtime(true) >= $deadline) {
				break;
			}
			try {
				if (self::settleLowered(intval($id), $key)) {
					$done++;
				}
			} catch (\Throwable $e) {
				error_log('MailboxFortressLevel: lowered message ' . intval($id) . ' was not settled: ' . $e->getMessage());
			}
		}
		return $done;
	}

	/**
	 * Put a lowered message's attachment names, types and Content-IDs back on
	 * its ima_ rows from the sealed manifest, clear the Fortress-only columns
	 * (InboundEmailMessage::FORTRESS_DERIVED), and queue the row for the server
	 * search index. The Files keep their neutral names: the reader and the
	 * download take the ima_ row's. The writes happen only if the row still has
	 * the key the manifest was opened with (a raise may have moved it since).
	 */
	public static function settleLowered(int $message_id, VaultKey $key): bool {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare('SELECT iem_sealed_key, iem_attachment_manifest, iem_iea_inbound_email_alias_id
			FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ?');
		$stmt->execute(array($message_id));
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		if (!$row || VaultCrypto::clientCustodyScope((string)$row['iem_sealed_key']) !== null
				|| (string)$row['iem_attachment_manifest'] === '') {
			return false;
		}
		$crypto = new VaultCrypto();
		$dek = $crypto->openItemDek((string)$row['iem_sealed_key'], $key);
		$manifest = json_decode($crypto->openField((string)$row['iem_attachment_manifest'], $dek,
			InboundEmailMessage::sealAd($message_id, 'iem_attachment_manifest')), true);
		$db->beginTransaction();
		try {
			$lock = $db->prepare('SELECT iem_sealed_key FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ? FOR UPDATE');
			$lock->execute(array($message_id));
			if ((string)$lock->fetchColumn() !== (string)$row['iem_sealed_key']) {
				$db->rollBack();
				return false;
			}
			$update = $db->prepare('UPDATE ima_inbound_message_attachments
				SET ima_filename = ?, ima_content_type = ?, ima_content_id = ?
				WHERE ima_inbound_message_attachment_id = ? AND ima_iem_inbound_email_message_id = ?');
			foreach (is_array($manifest) ? $manifest : array() as $entry) {
				$update->execute(array(
					substr((string)($entry['filename'] ?? ''), 0, 500) ?: null,
					substr((string)($entry['content_type'] ?? ''), 0, 255) ?: 'application/octet-stream',
					substr((string)($entry['content_id'] ?? ''), 0, 255) ?: null,
					intval($entry['id'] ?? 0), $message_id));
			}
			$db->prepare('UPDATE iem_inbound_email_messages SET '
				. implode(', ', array_map(function ($c) { return $c . ' = NULL'; }, InboundEmailMessage::FORTRESS_DERIVED))
				. ' WHERE iem_inbound_email_message_id = ?')->execute(array($message_id));
			$db->commit();
		} catch (\Throwable $e) {
			if ($db->inTransaction()) {
				$db->rollBack();
			}
			throw $e;
		}
		MailboxIndex::enqueueRefold(intval($row['iem_iea_inbound_email_alias_id']), $message_id);
		return true;
	}

	/**
	 * How many of $user_id's messages on $domain_id are still sealed to their
	 * mail key after the domain left Fortress: what the lowering banner and
	 * receipt count until the browser has moved them.
	 */
	public static function loweringBacklogCount(int $user_id, int $domain_id = 0): int {
		if ($user_id <= 0) {
			return 0;
		}
		$stmt = DbConnector::get_instance()->get_db_link()->prepare("SELECT COUNT(*)
			FROM iem_inbound_email_messages m
			LEFT JOIN iea_inbound_email_aliases a ON a.iea_inbound_email_alias_id = m.iem_iea_inbound_email_alias_id
			JOIN ied_inbound_email_domains d ON d.ied_inbound_email_domain_id = m.iem_ied_inbound_email_domain_id
			WHERE " . InboundEmailAlias::effectiveLevelSql('a', 'd') . " <> '" . InboundEmailDomain::LEVEL_FORTRESS . "'
			  AND m.iem_sealed_owner_user_id = ? AND " . InboundEmailMessage::mailKeySql('m.iem_sealed_key')
			. ($domain_id > 0 ? ' AND m.iem_ied_inbound_email_domain_id = ' . intval($domain_id) : ''));
		$stmt->execute(array($user_id));
		return intval($stmt->fetchColumn());
	}
}
?>
