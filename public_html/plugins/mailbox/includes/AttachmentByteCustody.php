<?php
/**
 * AttachmentByteCustody — "local bytes win" (specs/mailbox_attachment_byte_custody.md).
 *
 * A reference is what the platform has when it does not have the bytes. A
 * message ingested over IMAP records WHICH attachments exist but not their
 * bytes — opening one fetches the part from the source mailbox. Whenever any
 * path turns up the message's real bytes while holding only references — an
 * archive import of the same message, a live SMTP delivery deduping against an
 * IMAP-fed row, a Joinery Direct delivery carrying the same message's parts —
 * it keeps them, and the message stops depending on a mailbox the user may
 * disconnect. One shared implementation is what makes every order of events
 * converge on the same end state.
 *
 * Two entry points for the two shapes bytes arrive in: adopt() for a path
 * holding a raw MIME document, adoptParts() for a path holding already-decoded
 * parts (Joinery Direct never assembles a raw). Both never throw — adoption is
 * a bonus on top of dedup, never a condition of it, and a total failure leaves
 * the dedup outcome exactly as it was.
 *
 * @version 1.2
 * @changelog 1.2 - manifestRowCount(), so a caller can ask whether a stored copy
 *   lists any attachments at all before paying to parse a MIME document.
 * @changelog 1.1 - adoptParts() so Joinery Direct's decoded parts adopt too;
 *   Direct parts carry no MIME section, so only the Content-ID and
 *   filename+type matching rules can claim them.
 */

class AttachmentByteCustody {

	/**
	 * Take the real attachment bytes for a message the platform already holds
	 * as references, and return how many were adopted.
	 *
	 * Additive and idempotent — a row that already has a File is not selected,
	 * so running against the same message twice does nothing the second time.
	 * $router supplies the shared part enumeration and owner resolution so
	 * "attachment" means the same thing here as at ingest.
	 */
	public static function adopt(int $message_id, string $raw, InboundEmailRouter $router): int {
		return self::adoptVia($message_id, $router, function () use ($raw, $router) {
			return $router->enumerateNonTextParts($raw);
		});
	}

	/**
	 * The same adoption for a path holding already-decoded parts instead of a
	 * raw MIME document — Joinery Direct delivers each attachment as an array
	 * of filename / content_type / content_id / bytes. Each is wrapped to
	 * answer the same questions a parsed MIME part does; with no section
	 * number, only the Content-ID and filename+type rules can claim one, and
	 * anything ambiguous is left alone exactly as everywhere else.
	 */
	public static function adoptParts(int $message_id, array $delivered, InboundEmailRouter $router): int {
		return self::adoptVia($message_id, $router, function () use ($delivered) {
			$parts = array();
			foreach ($delivered as $d) {
				if (is_array($d) && (string)($d['bytes'] ?? '') !== '') {
					$parts[] = new DeliveredAttachmentPart($d);
				}
			}
			return $parts;
		});
	}

	/**
	 * How many attachments a message lists, whatever shape they are in.
	 *
	 * Zero on a message whose source copy plainly HAS attachments is a
	 * discrepancy, not an absence — a manifest that was never written. It is one
	 * cheap COUNT so a caller can ask before paying to parse a MIME document
	 * (D3, specs/mail_import_loss_proof.md).
	 */
	public static function manifestRowCount(int $message_id): int {
		if ($message_id <= 0) {
			return 0;
		}
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare('SELECT COUNT(*) FROM ima_inbound_message_attachments
			WHERE ima_iem_inbound_email_message_id = ?');
		$stmt->execute(array($message_id));
		return intval($stmt->fetchColumn());
	}

	/**
	 * The shared adoption core. $enumerate produces the candidate parts and is
	 * called only after the cheap guards pass, so a message with nothing to
	 * upgrade never pays for MIME parsing.
	 */
	private static function adoptVia(int $message_id, InboundEmailRouter $router, callable $enumerate): int {
		try {
			$msg = new InboundEmailMessage($message_id, TRUE);
			if (!$msg->key) {
				return 0;
			}
			// A deleted message is not resurrected in any form. Dedup counts
			// deleted rows on purpose so a re-import cannot bring back mail the
			// user threw away; refilling its attachments would be the same
			// mistake in a smaller shape.
			if ($msg->get('iem_delete_time')) {
				return 0;
			}
			// Only a message whose bytes live somewhere else is upgraded. A row with
			// no File on a message whose raw we already store is a section pointer
			// INTO that raw — those bytes are local already, and copying them out
			// would duplicate custody rather than establish it.
			if ((string)$msg->get('iem_raw_storage_driver') !== 'remote') {
				return 0;
			}

			$rows = new MultiInboundMessageAttachment(
				array('message_id' => $message_id, 'file_backed' => false));
			$rows->load();
			$manifest = array();
			foreach ($rows as $row) {
				$manifest[] = $row;
			}
			if (empty($manifest)) {
				return 0;
			}

			$parts = $enumerate();
			if (empty($parts)) {
				return 0;
			}

			// Seal iff the MESSAGE is sealed, to the owner the message itself
			// records — never read from the mailbox's current protection setting.
			// Other code (EmailAttachmentDigest) relies on "an attachment is sealed
			// only when its message is" to know that skipping sealed messages means
			// never meeting sealed bytes.
			$sealed = (bool)$msg->get('iem_content_sealed');
			$owner_id = null;
			if ($sealed) {
				$owner_id = InboundEmailMessage::sealedOwnerFor($msg);
				if ($owner_id === null || $owner_id <= 0) {
					error_log('AttachmentByteCustody: message ' . $message_id . ' is sealed but names no owner; '
						. 'its attachments stay reference-backed rather than being stored in the clear.');
					return 0;
				}
			} else {
				$alias_id = $msg->get('iem_iea_inbound_email_alias_id');
				$alias = $alias_id ? new InboundEmailAlias(intval($alias_id), TRUE) : null;
				$owner_id = $router->attachmentOwnerId($alias);
			}

			$pairs = self::matchPartsToRows($manifest, $parts);

			$adopted = 0;
			foreach ($pairs as $row_index => $part_index) {
				try {
					if (self::adoptOnePart($manifest[$row_index], $parts[$part_index], $sealed, intval($owner_id))) {
						$adopted++;
					}
				} catch (\Throwable $e) {
					// One unreadable part must not cost the others.
					error_log('AttachmentByteCustody: could not adopt attachment bytes for row '
						. intval($manifest[$row_index]->key) . ' on message ' . $message_id . ' — ' . $e->getMessage());
				}
			}

			$unmatched = count($manifest) - count($pairs);
			if ($unmatched > 0) {
				// Attaching the WRONG bytes to a row is worse than leaving it as a
				// reference, so an ambiguous or absent match is skipped and said out
				// loud. The row keeps working exactly as it did.
				error_log('AttachmentByteCustody: message ' . $message_id . ' — ' . $unmatched . ' of '
					. count($manifest) . ' attachment row(s) could not be matched to a part in the '
					. 'in-hand copy; they stay reference-backed.');
			}

			return $adopted;
		} catch (\Throwable $e) {
			error_log('AttachmentByteCustody: attachment byte adoption failed for message '
				. $message_id . ' — ' . $e->getMessage());
			return 0;
		}
	}

	/**
	 * Store one part's bytes as the File behind a manifest row. Returns whether
	 * the row was upgraded.
	 *
	 * The bytes move as a STREAM, never a PHP string: a large decoded
	 * attachment materialized whole can breach memory_limit and take the whole
	 * background run with it. Horde hands the decoded content back as a stream
	 * and it goes straight to a private temp file — which is the shape both
	 * File creators want anyway.
	 *
	 * File first, row second: a crash between them leaves an orphaned File
	 * (invisible, harmless, cheap) rather than a row pointing at bytes that do
	 * not exist. A failure to write the row takes the File back with it, which
	 * is the receive path's own rollback.
	 */
	private static function adoptOnePart(InboundMessageAttachment $att, $part, bool $sealed, int $owner_id): bool {
		$stream = $part->getContents(array('stream' => true));
		if (!is_resource($stream)) {
			return false;
		}

		$tmp = tempnam(sys_get_temp_dir(), 'joinery-mail-adopt-');
		if ($tmp === false) {
			throw new RuntimeException('Could not create a temporary file for the attachment bytes.');
		}
		try {
			// Sealed plaintext stays owner-read-only on disk for its short life;
			// a plaintext spool matches File::createFromBytes' permissions.
			@chmod($tmp, $sealed ? 0600 : 0666);
			$out = @fopen($tmp, 'wb');
			if ($out === false) {
				throw new RuntimeException('Could not open the temporary attachment file for writing.');
			}
			@rewind($stream);
			$size = stream_copy_to_stream($stream, $out);
			fclose($out);
			if ($size === false || $size <= 0) {
				return false;
			}

			// The manifest's identity columns were written from the source mailbox and
			// are what the reader already shows, so they win over the in-hand copy's.
			$name = (string)($att->get('ima_filename') ?: $part->getName() ?: 'attachment');
			$type = (string)($att->get('ima_content_type') ?: $part->getType() ?: 'application/octet-stream');
			$restrictions = array('fil_private' => true, 'fil_source' => File::SOURCE_EMAIL_ATTACHMENT);

			// Sealing runs with nobody signed in, and that is fine: it needs only
			// the vault's PUBLIC key (docs/sealed_vault.md — a consumer seals a
			// random per-item key to the public key and the content under that
			// key). Only reading needs an unlock window. createFromUpload consumes
			// the temp path; createSealedFile does not, so the finally sweeps
			// whichever is left.
			$file = $sealed
				? DriveSealed::createSealedFile($tmp, $name, $type, $owner_id, $restrictions)
				: File::createFromUpload($tmp, $name, $type, $owner_id, $restrictions);

			try {
				InboundMessageAttachment::updateColumns(intval($att->key), array(
					'ima_fil_file_id' => intval($file->key),
					// On a file-backed row this is the plain decoded size, which is what
					// "size" means everywhere else; the old value came from the IMAP
					// BODYSTRUCTURE and described the transfer-encoded part.
					'ima_size_bytes'  => $size,
					// ima_encoding is deliberately NOT touched. On a file-backed row it
					// describes the SOURCE part's transfer encoding, not how the bytes
					// are stored — which is what the MIME split writes too
					// (InboundEmailRouter::extractAttachmentsToFiles). The IMAP ingest
					// already wrote the same value from BODYSTRUCTURE, so leaving it
					// alone is exactly what makes the different orders converge.
					// ima_is_sealed stays false: the File carries its own sealed state.
				));
			} catch (\Throwable $e) {
				try { $file->permanent_delete(); } catch (\Throwable $ignore) {}
				throw $e;
			}
			return true;
		} finally {
			if (is_file($tmp)) { @unlink($tmp); }
		}
	}

	/**
	 * Which in-hand part belongs to which manifest row: [row index => part index].
	 *
	 * The rows were written from an IMAP BODYSTRUCTURE and the parts come from
	 * Horde parsing the in-hand copy. Their numbering usually agrees, but
	 * "usually" is not good enough when the cost of being wrong is a row serving
	 * some other attachment's bytes. So each rule must identify the pair
	 * UNIQUELY ON BOTH SIDES — exactly one row and exactly one part carrying that
	 * key — and anything ambiguous is left alone. No fuzzy matching, and no size
	 * matching (a reference row's size is the encoded one and cannot equal a
	 * decoded length).
	 *
	 * The rules run strongest first, and each pass only considers rows and parts
	 * the earlier passes did not claim.
	 */
	private static function matchPartsToRows(array $rows, array $parts): array {
		$clean = function ($value) {
			return strtolower(trim((string)$value, " <>\t\r\n"));
		};
		$type_of = function ($value) {
			// 'text/plain; charset=utf-8' and 'text/plain' are the same type.
			$value = strtolower(trim((string)$value));
			$semi = strpos($value, ';');
			return $semi === false ? $value : trim(substr($value, 0, $semi));
		};

		$rules = array(
			// 1. Content-ID — the only identifier either side actually promises.
			array(
				function ($row) use ($clean) {
					$cid = $clean($row->get('ima_content_id'));
					return $cid !== '' ? 'cid:' . $cid : null;
				},
				function ($part) use ($clean) {
					$cid = $clean($part->getContentId());
					return $cid !== '' ? 'cid:' . $cid : null;
				},
			),
			// 2. MIME section number AND type — agreement on both, never section alone.
			array(
				function ($row) use ($type_of) {
					$section = trim((string)$row->get('ima_mime_part'));
					$type = $type_of($row->get('ima_content_type'));
					return ($section !== '' && $type !== '') ? 'mime:' . $section . '|' . $type : null;
				},
				function ($part) use ($type_of) {
					$section = trim((string)$part->getMimeId());
					$type = $type_of($part->getType());
					return ($section !== '' && $type !== '') ? 'mime:' . $section . '|' . $type : null;
				},
			),
			// 3. Filename AND type — the last resort, and only when unique.
			array(
				function ($row) use ($type_of) {
					$name = strtolower(trim((string)$row->get('ima_filename')));
					$type = $type_of($row->get('ima_content_type'));
					return ($name !== '' && $type !== '') ? 'name:' . $name . '|' . $type : null;
				},
				function ($part) use ($type_of) {
					$name = strtolower(trim((string)$part->getName()));
					$type = $type_of($part->getType());
					return ($name !== '' && $type !== '') ? 'name:' . $name . '|' . $type : null;
				},
			),
		);

		$pairs = array();
		$claimed = array();

		foreach ($rules as $rule) {
			list($row_key, $part_key) = $rule;

			$row_buckets = array();
			foreach ($rows as $i => $row) {
				if (isset($pairs[$i])) {
					continue;
				}
				$key = $row_key($row);
				if ($key !== null) {
					$row_buckets[$key][] = $i;
				}
			}

			$part_buckets = array();
			foreach ($parts as $j => $part) {
				if (isset($claimed[$j])) {
					continue;
				}
				$key = $part_key($part);
				if ($key !== null) {
					$part_buckets[$key][] = $j;
				}
			}

			foreach ($row_buckets as $key => $row_indexes) {
				if (count($row_indexes) !== 1) {
					continue; // two rows answer to this key — cannot tell them apart
				}
				if (!isset($part_buckets[$key]) || count($part_buckets[$key]) !== 1) {
					continue; // no part, or more than one — same problem
				}
				$pairs[$row_indexes[0]] = $part_buckets[$key][0];
				$claimed[$part_buckets[$key][0]] = true;
			}
		}

		return $pairs;
	}
}

/**
 * One already-decoded delivered part (Joinery Direct's array shape), answering
 * the same questions the matcher and adopter ask of a parsed MIME part. There
 * is no MIME section number to report, so getMimeId() is empty and the
 * section-based matching rule never fires for these. The bytes are already in
 * memory (that is how Direct delivered them), so the stream getContents()
 * hands back wraps that string rather than re-reading anything.
 */
class DeliveredAttachmentPart {
	private $part;

	public function __construct(array $part) {
		$this->part = $part;
	}

	public function getContentId() {
		return (string)($this->part['content_id'] ?? '');
	}

	public function getMimeId() {
		return '';
	}

	public function getName() {
		return (string)($this->part['filename'] ?? '');
	}

	public function getType() {
		return (string)($this->part['content_type'] ?? '');
	}

	public function getContents(array $options = array()) {
		$stream = fopen('php://temp', 'w+b');
		if ($stream === false) {
			return false;
		}
		fwrite($stream, (string)($this->part['bytes'] ?? ''));
		rewind($stream);
		return $stream;
	}
}
?>
