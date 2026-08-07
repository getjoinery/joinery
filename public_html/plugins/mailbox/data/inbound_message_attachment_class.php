<?php
/**
 * InboundMessageAttachment - Per-message attachment manifest (transport-agnostic).
 *
 * One row per non-text MIME part of a stored inbound message. The manifest
 * describes WHICH parts exist and WHERE in the MIME tree (section number,
 * encoding, content-type, size) — it does NOT hold the bytes. Attachment bytes
 * are never stored on the platform: the per-attachment download endpoint fetches
 * exactly one part on click (IMAP FETCH BODY[<section>], or, later, a MIME parse
 * over a stored raw) and streams it pass-through.
 *
 * Written at ingest from the IMAP BODYSTRUCTURE the poller already reads to locate
 * the text parts (same structure enumerates every attachment part). Postfix/Mailgun
 * mail adopts per-attachment download later by populating this same table from a
 * MIME parser over their stored raw and reusing the same endpoint + reader UI — no
 * new schema.
 *
 * ATTACHMENT BYTES (specs/implemented/inbound_email_attachment_storage.md). For push mail
 * (Postfix/Mailgun, stored-raw transports) each non-text part is extracted at
 * ingest into a private File and linked here via ima_fil_file_id — the bytes
 * live in exactly one place (the File), not inside a retained raw. A row with
 * ima_fil_file_id set is file-backed: serve / forward read the File. A row
 * without it is a section-pointer into a stored raw (legacy/fallback) or an
 * IMAP ('remote') part fetched on demand via ima_mime_part. Presence of
 * ima_fil_file_id — not the transport — is what dispatch keys on.
 *
 * @version 1.2
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class InboundMessageAttachmentException extends SystemBaseException {}

class InboundMessageAttachment extends SystemBase {
	public static $prefix = 'ima';
	public static $tablename = 'ima_inbound_message_attachments';
	public static $pkey_column = 'ima_inbound_message_attachment_id';

	protected static $foreign_key_actions = array(
		'ima_iem_inbound_email_message_id' => array('action' => 'cascade'),
		'ima_fil_file_id'                  => array('action' => 'cascade'),
	);

	public static $field_specifications = array(
		'ima_inbound_message_attachment_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'ima_iem_inbound_email_message_id'  => array('type'=>'int8', 'is_nullable'=>false),
		'ima_filename'      => array('type'=>'varchar(500)'),
		'ima_content_type'  => array('type'=>'varchar(255)'),
		'ima_size_bytes'    => array('type'=>'int8'),
		'ima_mime_part'     => array('type'=>'varchar(40)'),
		'ima_encoding'      => array('type'=>'varchar(40)'),
		'ima_content_id'    => array('type'=>'varchar(255)'),
		'ima_is_inline'     => array('type'=>'bool', 'default'=>false, 'is_nullable'=>false),
		// Set ⇒ the bytes are a private File (push mail); absent ⇒ section-pointer
		// into a stored raw (legacy/fallback) or an IMAP on-demand part.
		'ima_fil_file_id'   => array('type'=>'int8', 'is_nullable'=>true),
		// Sealed Vault (docs/sealed_vault.md): true ⇒ the linked File's bytes are
		// an AEAD blob under the owning message's DEK. Recorded PER FILE because
		// sealed state is a property of the stored bytes, not of the message — a
		// backfilled message's pre-vault Files stay plaintext while its content
		// columns are sealed. Every reader of the File bytes consults this flag
		// via InboundEmailMessage::openSealedAttachment().
		'ima_is_sealed'     => array('type'=>'bool', 'default'=>false, 'is_nullable'=>false),
		'ima_create_time'   => array('type'=>'timestamp(6)', 'default'=>'now()'),
	);

	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in ' . static::$tablename);
		}
	}

	/** Create and persist one manifest row. */
	static function CreateEntry(array $row): InboundMessageAttachment {
		$att = new InboundMessageAttachment(NULL);
		foreach ($row as $field => $value) {
			$att->set($field, $value);
		}
		$att->save();
		return $att;
	}
}

class MultiInboundMessageAttachment extends SystemMultiBase {
	protected static $model_class = 'InboundMessageAttachment';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['message_id'])) {
			$filters['ima_iem_inbound_email_message_id'] = array($this->options['message_id'], PDO::PARAM_INT);
		}

		// Real attachments only (exclude inline cid: parts that belong to the
		// HTML body) — what the reader's attachment list shows.
		if (isset($this->options['is_inline'])) {
			$filters['ima_is_inline'] = $this->options['is_inline'] ? '= true' : '= false';
		}

		// File-backed (bytes stored as a private File) vs. section-pointer rows.
		if (isset($this->options['file_backed'])) {
			$filters['ima_fil_file_id'] = $this->options['file_backed'] ? 'IS NOT NULL' : 'IS NULL';
		}

		// The manifest row backing a given File — the sealed-attachment decrypt
		// hook's lookup (plugins/mailbox/includes/bootstrap.php).
		if (isset($this->options['file_id'])) {
			$filters['ima_fil_file_id'] = array($this->options['file_id'], PDO::PARAM_INT);
		}

		return $this->_get_resultsv2('ima_inbound_message_attachments', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
