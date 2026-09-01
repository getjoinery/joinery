<?php
/**
 * MailboxDrafts — save / open / delete a compose draft (specs/mailbox_compose_maturity.md
 * § Phase 2). A draft is an iem_inbound_email_messages row with iem_direction='draft';
 * no new table (the sealing plumbing, attachment manifest, and alias FK come free).
 *
 * A draft row carries: iem_iea_inbound_email_alias_id (the From identity), iem_subject /
 * iem_body_html / iem_body_plain as composed so far, iem_recipient (To + Cc combined),
 * iem_bcc, and iem_draft_state — a sealed JSON {mode, source_id, to, cc} that restores the
 * exact fields (iem_recipient alone can't tell To from Cc). iem_message_id_header stays
 * NULL; iem_thread_key is the source thread's key for a reply/forward draft, NULL for a new.
 *
 * Sealing (Private/Fortress owner): content + recipient + bcc + draft_state seal under a
 * per-draft DEK. Autosave never blocks on the unlock window — a fresh DEK seals with only
 * the owner's public key. To keep already-persisted draft attachments (sealed under the
 * draft's DEK) readable across edits, an UPDATE to a sealed draft re-seals its content
 * under the SAME DEK, unwrapped in-window; the one case that needs the window is editing a
 * sealed draft that already has attachments after the window lapsed — that returns
 * locked:true so the client prompts a one-tap unlock (a text-only draft never blocks).
 *
 * Every method is scoped through the MailboxViewer, so a viewer can only touch drafts in a
 * mailbox they hold a grant for. Discard is a hard delete (row + ima_ manifest + Files) —
 * there is no draft trash.
 *
 * @version 1.2 - the sealing posture comes from the mailbox (MailboxSender::sealTargetFor),
 *   not from whether its owner holds a vault, so a Standard mailbox's drafts stay plaintext
 *   like its mail (specs/bugfix_self_addressed_send.md).
 */

require_once(PathHelper::getIncludePath('includes/VaultUnlock.php')); // VaultLockedException + isOpen/secretKey
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxViewer.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxSender.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxHtmlSanitizer.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_message_attachment_class.php'));

class MailboxDraftsException extends Exception {}

class MailboxDrafts {

	/** @var MailboxViewer */
	private $viewer;

	public function __construct(MailboxViewer $viewer) {
		$this->viewer = $viewer;
	}

	/**
	 * Create or update a draft. $params: alias_id (From), draft_id (optional, for
	 * update), mode, source_id, to, cc, bcc, subject, body_html / body. New uploads
	 * ($files, same shape as send) persist immediately onto the draft. Returns
	 * ['draft_id'=>int], or ['locked'=>true] when a sealed draft with attachments
	 * needs the unlock window it doesn't have.
	 */
	public function saveDraft(array $params, array $files = array()): array {
		$alias_id = intval($params['alias_id'] ?? 0);
		if ($alias_id <= 0) {
			throw new MailboxDraftsException('Choose a mailbox to draft from.');
		}
		if (!$this->viewer->canAccess($alias_id)) {
			throw new MailboxDraftsException('You do not have access to this mailbox.');
		}
		$alias = new InboundEmailAlias($alias_id, TRUE);
		if (!$alias->key || $alias->get('iea_delete_time')) {
			throw new MailboxDraftsException('That mailbox no longer exists.');
		}

		$existing = null;
		$draft_id = intval($params['draft_id'] ?? 0);
		if ($draft_id > 0) {
			$existing = $this->loadDraftInScope($draft_id);
			if ($existing === null) {
				throw new MailboxDraftsException('That draft no longer exists.');
			}
		}

		// Compose state.
		$mode = (string)($params['mode'] ?? 'new');
		if (!in_array($mode, array('reply', 'reply_all', 'forward', 'new'), true)) {
			$mode = 'new';
		}
		$source_id = intval($params['source_id'] ?? 0);
		$to = trim((string)($params['to'] ?? ''));
		$cc = trim((string)($params['cc'] ?? ''));
		$bcc = trim((string)($params['bcc'] ?? ''));
		$subject = substr((string)($params['subject'] ?? ''), 0, 4000);

		$body_html_param = trim((string)($params['body_html'] ?? ''));
		$body_html = $body_html_param !== ''
			? MailboxHtmlSanitizer::sanitize($body_html_param, true)
			: '';
		$body_plain = $body_html !== ''
			? MailboxHtmlSanitizer::toPlainText($body_html)
			: trim((string)($params['body'] ?? ''));

		// iem_recipient mirrors an outbound row: To + Cc combined. draft_state keeps
		// the split so reopening restores the exact fields.
		$recipient = trim(implode(', ', array_filter(array($to, $cc), function ($v) { return $v !== ''; })));
		$draft_state = json_encode(array('mode' => $mode, 'source_id' => $source_id, 'to' => $to, 'cc' => $cc));

		$thread_key = null;
		if (($mode === 'reply' || $mode === 'reply_all' || $mode === 'forward') && $source_id > 0) {
			$src = new InboundEmailMessage($source_id, TRUE);
			if ($src->key) {
				$tk = trim((string)$src->get('iem_thread_key'));
				$thread_key = $tk !== '' ? substr($tk, 0, 255) : null;
			}
		}

		$sender = strtolower($alias->get_full_address());

		// Sealing posture — the MAILBOX's, resolved through the one resolver every
		// ingress path uses (MailboxSender::sealTargetFor). A draft is the same
		// content as the send it becomes, so the two must answer this identically
		// or a Standard mailbox's draft would seal and its Sent copy would not.
		$seal = MailboxSender::sealTargetFor($alias);
		$owner_id = $seal['owner_id'];
		$vault = $seal['vault'];
		$sealing = $seal['sealing'];

		// DEK strategy for a sealed UPDATE: reuse the existing DEK (in-window) so
		// already-persisted attachments stay readable; a sealed draft that already has
		// attachments and no open window can't be re-sealed → locked.
		$reuse_dek = null;
		if ($sealing && $existing !== null && $existing->get('iem_content_sealed') && $existing->get('iem_sealed_key')) {
			// Unwrap under the DEK's RECORDED owner (immune to a From-change to a
			// different sealed alias — the invariant guarantees the same owner, but
			// resolve it from the row rather than the new alias to be exact).
			$recorded_owner = intval($existing->get('iem_sealed_owner_user_id'));
			if ($recorded_owner <= 0) {
				$recorded_owner = (int)($owner_id ?? 0);
			}
			$reuse_dek = InboundEmailMessage::unwrapDekInWindow($recorded_owner, (string)$existing->get('iem_sealed_key'));
			if ($reuse_dek === null && $this->draftHasAttachments((int)$existing->key)) {
				return array('locked' => true);
			}
		}

		// Inline (pasted) image manifest: local-id => filename, matched against the
		// uploads below so a pasted image persists as an inline part carrying its
		// local id as Content-ID (specs fix pack Fix 7).
		$inline_manifest = MailboxSender::parseInlineManifest($params['inline_manifest'] ?? '');

		// Content columns: plaintext at Standard; empty placeholders when sealing
		// (sealAndPersistContent writes the ciphertext right after).
		$content = array(
			// The From identity — persisted on every save so a mid-draft From change
			// (Fix 5) files the eventual Sent copy into the right mailbox.
			'iem_iea_inbound_email_alias_id'  => $alias_id,
			'iem_ied_inbound_email_domain_id' => intval($alias->get('iea_ied_inbound_email_domain_id')),
			'iem_thread_key'   => $thread_key,
			'iem_received_time' => gmdate('Y-m-d H:i:s'),
			'iem_is_read'      => true,
			'iem_sender'       => $sealing ? '' : substr($sender, 0, 500),
			'iem_recipient'    => $sealing ? '' : $recipient,
			'iem_bcc'          => ($sealing || $bcc === '') ? null : $bcc,
			'iem_subject'      => $sealing ? '' : $subject,
			'iem_body_plain'   => $sealing ? '' : $body_plain,
			'iem_body_html'    => $sealing ? '' : $body_html,
			'iem_draft_state'  => $sealing ? null : $draft_state,
		);

		// Sealed → standard From change (Fix 5): the new alias doesn't seal, so the
		// content columns above are plaintext — flip iem_content_sealed off so reads
		// return them straight. iem_sealed_key/owner stay in place: the draft's
		// already-sealed attachments remain decryptable through them (per-attachment
		// ima_is_sealed governs each read).
		if (!$sealing && $existing !== null && $existing->get('iem_content_sealed')) {
			$content['iem_content_sealed'] = false;
		}

		if ($existing === null) {
			$row = new InboundEmailMessage(NULL);
			$row->set('iem_ied_inbound_email_domain_id', $alias->get('iea_ied_inbound_email_domain_id'));
			$row->set('iem_iea_inbound_email_alias_id', $alias_id);
			$row->set('iem_direction', 'draft');
			$row->set('iem_message_id_header', null);
			// A draft is personal compose state — owned by its author, never a
			// co-grantee or an all-access superadmin (Fix 1).
			$row->set('iem_draft_author_user_id', $this->viewer->getUserId());
			foreach ($content as $col => $val) {
				$row->set($col, $val);
			}
			$row->save();
			$message_id = intval($row->key);
		} else {
			// A targeted UPDATE — never $existing->save(), which would read (and try to
			// decrypt) the loaded row's sealed columns, blocking on the unlock window
			// autosave must survive.
			$message_id = intval($existing->key);
			InboundEmailMessage::updateColumns($message_id, $content);
		}

		$dek = null;
		if ($sealing) {
			$dek = InboundEmailMessage::sealAndPersistContent($message_id, $vault, $sender,
				$recipient, $subject, $body_plain, $body_html, true, $bcc, $draft_state, $reuse_dek);
		}

		if (!empty($files)) {
			$this->persistDraftUploads($message_id, $files, $dek, $inline_manifest);
		}

		// Prune inline parts the user deleted from the editor (Fix 7): once the body
		// HTML is authoritative, any stored inline attachment whose cid: no longer
		// appears is orphaned — drop its File + manifest row.
		if ($body_html_param !== '') {
			$this->pruneStaleInline($message_id, $body_html);
		}

		return array(
			'draft_id'    => $message_id,
			'attachments' => $this->draftAttachments($message_id),
			'inline'      => $this->draftInline($message_id),
		);
	}

	/**
	 * The decrypted compose state for a draft (or ['locked'=>true] when sealed and
	 * the owner's window is closed). Empty array when the draft is not in scope.
	 */
	public function getDraft(int $draft_id): array {
		$row = $this->loadDraftInScope($draft_id);
		if ($row === null) {
			return array();
		}
		$alias_id = intval($row->get('iem_iea_inbound_email_alias_id'));

		if ($row->get('iem_content_sealed')) {
			$owner_id = intval($row->get('iem_sealed_owner_user_id'));
			if ($owner_id <= 0) {
				$owner_id = (int)(InboundEmailMessage::singleOwnerUserId($alias_id) ?? 0);
			}
			if ($owner_id <= 0 || !VaultUnlock::isOpen($owner_id)) {
				return array('locked' => true);
			}
		}

		try {
			$state = json_decode((string)$row->get('iem_draft_state'), true);
			$body_html = (string)$row->get('iem_body_html');
			$bcc = (string)($row->get('iem_bcc') ?? '');
			$subject = (string)$row->get('iem_subject');
		} catch (VaultLockedException $e) {
			return array('locked' => true);
		}
		if (!is_array($state)) {
			$state = array();
		}

		return array(
			'draft_id'    => intval($row->key),
			'alias_id'    => $alias_id,
			'mode'        => (string)($state['mode'] ?? 'new'),
			'source_id'   => intval($state['source_id'] ?? 0),
			'to'          => (string)($state['to'] ?? ''),
			'cc'          => (string)($state['cc'] ?? ''),
			'bcc'         => $bcc,
			'subject'     => $subject,
			'body_html'   => $body_html,
			'attachments' => $this->draftAttachments($draft_id),
			'inline'      => $this->draftInline($draft_id),
		);
	}

	/** Hard-delete a draft: row + ima_ manifest + backing Files (no draft trash). */
	public function deleteDraft(int $draft_id): bool {
		$row = $this->loadDraftInScope($draft_id);
		if ($row === null) {
			return false;
		}
		$row->permanent_delete();
		return true;
	}

	/**
	 * Remove one regular (non-inline) attachment from a draft (the saved-chip ×,
	 * Fix 3): permanent-delete the backing File then the manifest row. The draft
	 * is author-scoped via loadDraftInScope; the ima_ row must belong to it and be
	 * non-inline (inline parts are managed by the body HTML, not the chip strip).
	 */
	public function deleteDraftAttachment(int $draft_id, int $attachment_id): bool {
		$row = $this->loadDraftInScope($draft_id);
		if ($row === null || $attachment_id <= 0) {
			return false;
		}
		$att = new InboundMessageAttachment($attachment_id, TRUE);
		if (!$att->key
			|| intval($att->get('ima_iem_inbound_email_message_id')) !== intval($row->key)
			|| $this->isInline($att)) {
			return false;
		}
		$fil_id = intval($att->get('ima_fil_file_id'));
		if ($fil_id > 0) {
			$file = new File($fil_id, TRUE);
			if ($file->key) {
				try { $file->permanent_delete(); } catch (Throwable $e) { /* best-effort */ }
			}
		}
		$att->permanent_delete();
		return true;
	}

	// ── internals ────────────────────────────────────────────────────────────

	/** Load a draft row iff it is a draft, not deleted, and in the viewer's scope. */
	private function loadDraftInScope(int $draft_id): ?InboundEmailMessage {
		if ($draft_id <= 0) {
			return null;
		}
		$row = new InboundEmailMessage($draft_id, TRUE);
		if (!$row->key || $row->get('iem_delete_time') || $row->get('iem_direction') !== 'draft') {
			return null;
		}
		$alias_id = intval($row->get('iem_iea_inbound_email_alias_id'));
		if ($alias_id <= 0 || !$this->viewer->canAccess($alias_id)) {
			return null;
		}
		// A draft belongs to its author alone (Fix 1) — a co-grantee of the shared
		// mailbox or an all-access superadmin cannot open/edit/send/delete it. A
		// null/legacy author fails closed.
		if (intval($row->get('iem_draft_author_user_id')) !== $this->viewer->getUserId()) {
			return null;
		}
		return $row;
	}

	private function draftHasAttachments(int $message_id): bool {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare('SELECT 1 FROM ima_inbound_message_attachments
			WHERE ima_iem_inbound_email_message_id = ? LIMIT 1');
		$stmt->execute(array($message_id));
		return (bool)$stmt->fetchColumn();
	}

	/** Non-inline attachment list for the reopen composer (chips). */
	private function draftAttachments(int $message_id): array {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare('SELECT ima_inbound_message_attachment_id, ima_filename,
			ima_content_type, ima_size_bytes
			FROM ima_inbound_message_attachments
			WHERE ima_iem_inbound_email_message_id = ? AND ima_is_inline = false
			ORDER BY ima_inbound_message_attachment_id ASC');
		$stmt->execute(array($message_id));
		$out = array();
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
			$out[] = array(
				'id'           => intval($r['ima_inbound_message_attachment_id']),
				'filename'     => $r['ima_filename'] ?: 'attachment',
				'content_type' => $r['ima_content_type'] ?: 'application/octet-stream',
				'size_bytes'   => intval($r['ima_size_bytes']),
			);
		}
		return $out;
	}

	/**
	 * The draft's inline (pasted-image) parts for the reopen composer (Fix 7):
	 * each carries its Content-ID and a short-lived signed URL to the decrypted
	 * bytes (the File decrypt hook serves a sealed inline image in the clear
	 * in-window; getDraft already gated on an open window for a sealed draft).
	 * The client rewrites cid:{content_id} img srcs to these URLs on open.
	 */
	private function draftInline(int $message_id): array {
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxService.php')); // INLINE_IMAGE_TTL
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare('SELECT ima_content_id, ima_filename, ima_fil_file_id
			FROM ima_inbound_message_attachments
			WHERE ima_iem_inbound_email_message_id = ? AND ima_is_inline = true
			ORDER BY ima_inbound_message_attachment_id ASC');
		$stmt->execute(array($message_id));
		$out = array();
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
			$cid = trim((string)$r['ima_content_id']);
			$fil_id = intval($r['ima_fil_file_id']);
			if ($cid === '' || $fil_id <= 0) {
				continue;
			}
			$file = new File($fil_id, TRUE);
			if (!$file->key || $file->get('fil_delete_time')) {
				continue;
			}
			$out[] = array(
				'content_id' => $cid,
				'filename'   => $r['ima_filename'] ?: 'image',
				'url'        => $file->mintSignedUrl('original', MailboxService::INLINE_IMAGE_TTL, 'full'),
			);
		}
		return $out;
	}

	/**
	 * Drop any inline part of the draft the user deleted from the editor (Fix 7):
	 * a stored inline attachment whose cid:{content_id} no longer appears in the
	 * sanitized body HTML is orphaned — remove its File + manifest row.
	 */
	private function pruneStaleInline(int $message_id, string $sanitized_html): void {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare('SELECT ima_inbound_message_attachment_id, ima_content_id, ima_fil_file_id
			FROM ima_inbound_message_attachments
			WHERE ima_iem_inbound_email_message_id = ? AND ima_is_inline = true');
		$stmt->execute(array($message_id));
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
			$cid = trim((string)$r['ima_content_id']);
			if ($cid !== '' && strpos($sanitized_html, 'cid:' . $cid) !== false) {
				continue; // still referenced by the body
			}
			$fil_id = intval($r['ima_fil_file_id']);
			if ($fil_id > 0) {
				$file = new File($fil_id, TRUE);
				if ($file->key) {
					try { $file->permanent_delete(); } catch (Throwable $e) { /* best-effort */ }
				}
			}
			$att = new InboundMessageAttachment(intval($r['ima_inbound_message_attachment_id']), TRUE);
			if ($att->key) {
				try { $att->permanent_delete(); } catch (Throwable $e) { /* best-effort */ }
			}
		}
	}

	/** Robust truthiness for ima_is_inline across PDO bool representations. */
	private function isInline(InboundMessageAttachment $att): bool {
		$v = $att->get('ima_is_inline');
		return ($v === true || $v === 't' || $v === 'true' || $v === '1' || $v === 1);
	}

	/**
	 * Persist newly-uploaded files as private Files + ima_ manifest rows on the draft,
	 * sealed under the draft DEK when sealing. Mirrors MailboxSender's outbound-upload
	 * persistence; enforces the same per-file / count / total caps against the draft's
	 * existing attachment bytes.
	 */
	private function persistDraftUploads(int $message_id, array $files, ?string $dek, array $inline_manifest = array()): void {
		$crypto = null;
		if ($dek !== null) {
			require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
			$crypto = new VaultCrypto();
		}
		$running = $this->draftAttachmentBytes($message_id);
		$count = $this->draftAttachmentCount($message_id);
		$pending_inline = $inline_manifest; // consumed as matching uploads arrive

		$index = 0;
		foreach ($files as $f) {
			if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
				continue;
			}
			if (($f['error'] ?? 1) !== UPLOAD_ERR_OK) {
				throw new MailboxDraftsException('An attachment failed to upload.');
			}
			$tmp = (string)($f['tmp_name'] ?? '');
			if ($tmp === '' || !is_uploaded_file($tmp)) {
				throw new MailboxDraftsException('An attachment could not be read.');
			}
			$size = intval($f['size'] ?? 0);
			if ($size > MailboxSender::MAX_UPLOAD_BYTES) {
				throw new MailboxDraftsException('An attachment exceeds the per-file size limit.');
			}
			if (++$count > MailboxSender::MAX_UPLOAD_FILES) {
				throw new MailboxDraftsException('Too many attachments on this draft.');
			}
			$running += $size;
			if ($running > MailboxSender::MAX_TOTAL_BYTES) {
				throw new MailboxDraftsException('The draft attachments exceed the total size limit.');
			}
			$bytes = file_get_contents($tmp);
			if ($bytes === false) {
				throw new MailboxDraftsException('An attachment could not be read.');
			}
			$name = $this->safeFilename((string)($f['name'] ?? 'attachment'));
			$index++;
			// A pasted inline image (matched by filename against the manifest) persists
			// as an inline part carrying its local id as Content-ID, so a reopened draft
			// resolves cid:{localId} back to the stored bytes (Fix 7).
			$localId = MailboxSender::matchInlineLocalId($pending_inline, (string)($f['name'] ?? ''), $name);
			$is_inline = ($localId !== null);
			try {
				$mime = File::detect_mime_bytes($bytes) ?: 'application/octet-stream';
				$original_size = strlen($bytes);
				$prefix = $is_inline ? 'draftinl:' : 'draft:';
				$part_id = $prefix . $message_id . ':' . $index . ':' . bin2hex(random_bytes(3));
				$stored = $bytes;
				if ($crypto !== null) {
					$stored = $crypto->sealField($bytes, $dek, InboundEmailMessage::attachmentAd($message_id, $part_id));
				}
				$file = File::createFromBytes($stored, $name, $mime, $this->viewer->getUserId(), array(
					'fil_private' => true,
					'fil_source'  => File::SOURCE_EMAIL_ATTACHMENT,
				));
				if ($crypto !== null) {
					$file->set('fil_type', substr($mime, 0, 128));
					$file->save();
				}
				InboundMessageAttachment::CreateEntry(array(
					'ima_iem_inbound_email_message_id' => $message_id,
					'ima_filename'     => $name,
					'ima_content_type' => $mime,
					'ima_size_bytes'   => $original_size,
					'ima_mime_part'    => substr($part_id, 0, 40),
					'ima_content_id'   => $is_inline ? $localId : null,
					'ima_is_inline'    => $is_inline,
					'ima_fil_file_id'  => (int)$file->key,
					'ima_is_sealed'    => ($crypto !== null),
				));
			} catch (Throwable $e) {
				error_log('MailboxDrafts: failed to persist draft upload "' . $name . '" on draft '
					. $message_id . ': ' . $e->getMessage());
			}
		}
	}

	private function draftAttachmentBytes(int $message_id): int {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare('SELECT COALESCE(SUM(ima_size_bytes),0) FROM ima_inbound_message_attachments
			WHERE ima_iem_inbound_email_message_id = ?');
		$stmt->execute(array($message_id));
		return intval($stmt->fetchColumn());
	}

	private function draftAttachmentCount(int $message_id): int {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare('SELECT COUNT(*) FROM ima_inbound_message_attachments
			WHERE ima_iem_inbound_email_message_id = ?');
		$stmt->execute(array($message_id));
		return intval($stmt->fetchColumn());
	}

	private function safeFilename(string $name): string {
		$name = str_replace(array("\r", "\n", '"', '\\', '/'), '', $name);
		$name = trim($name);
		return $name !== '' ? substr($name, 0, 255) : 'attachment';
	}
}
?>
