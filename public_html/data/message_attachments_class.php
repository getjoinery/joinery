<?php
/**
 * MessageAttachment and MultiMessageAttachment classes
 *
 * One row per photo or file hanging off a message. The bytes live in the File
 * system — this table is the manifest: which parts a message carries, what they
 * are called, and whether the stored bytes are sealed under the conversation's
 * key. Same shape the inbound-mail attachment manifest established, so the two
 * read alike.
 *
 * @version 1.0
 */


class MessageAttachmentException extends SystemBaseException {}

class MessageAttachment extends SystemBase {
	public static $prefix = 'msa';
	public static $tablename = 'msa_message_attachments';
	public static $pkey_column = 'msa_message_attachment_id';

	// Not REST-exposed on its own: an attachment is only ever meaningful through
	// the message that carries it, and the messenger's own actions serve it under
	// the conversation's participant check.
	public static $api_readable = false;
	public static $api_writable = false;

	public static $ai_readable = false;

	protected static $foreign_key_actions = array(
		'msa_msg_message_id' => array('action' => 'cascade', 'source_class' => 'Message'),
		'msa_fil_file_id'    => array('action' => 'cascade', 'source_class' => 'File'),
	);

	public static $field_specifications = array(
		'msa_message_attachment_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
		'msa_msg_message_id' => array('type' => 'int8', 'is_nullable' => false),
		'msa_fil_file_id'    => array('type' => 'int8', 'is_nullable' => false),
		'msa_filename'       => array('type' => 'varchar(500)'),
		'msa_mime_type'      => array('type' => 'varchar(255)'),
		'msa_byte_size'      => array('type' => 'int8'),
		// True ⇒ the linked File's bytes are an AEAD blob under the conversation
		// DEK. Recorded per file because sealed state is a property of the stored
		// bytes: a conversation raised to Private after the fact re-seals its
		// history one file at a time.
		'msa_is_sealed'      => array('type' => 'bool', 'default' => false, 'is_nullable' => false),
		'msa_create_time'    => array('type' => 'timestamp(6)'),
		'msa_delete_time'    => array('type' => 'timestamp(6)'),
	);

	/**
	 * Hang an already-stored File off a message.
	 *
	 * @param Message $message the message the file belongs to
	 * @param File    $file    the stored bytes
	 * @param bool    $sealed  whether those bytes are sealed under the conversation key
	 */
	public static function attach($message, $file, $sealed = false): MessageAttachment {
		$row = new MessageAttachment(NULL);
		$row->set('msa_msg_message_id', (int)$message->key);
		$row->set('msa_fil_file_id', (int)$file->key);
		$row->set('msa_filename', substr((string)($file->get('fil_title') ?: $file->get('fil_name')), 0, 500));
		$row->set('msa_mime_type', substr((string)$file->get('fil_type'), 0, 255));
		$row->set('msa_byte_size', (int)$file->size_bytes());
		$row->set('msa_is_sealed', (bool)$sealed);
		$row->set('msa_create_time', gmdate('Y-m-d H:i:s'));
		$row->save();
		return $row;
	}

	/** Does this part render inline as a picture, or as a file chip? */
	public function is_image(): bool {
		return strpos((string)$this->get('msa_mime_type'), 'image/') === 0;
	}

	function display_title() {
		return $this->get('msa_filename') ?: ('Attachment #' . $this->key);
	}
}

class MultiMessageAttachment extends SystemMultiBase {
	protected static $model_class = 'MessageAttachment';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['message_id'])) {
			$filters['msa_msg_message_id'] = array($this->options['message_id'], PDO::PARAM_INT);
		}

		// Every attachment of a set of messages in one query — what the thread
		// render needs, instead of one query per bubble.
		if (isset($this->options['message_ids'])) {
			$ids = array_map('intval', (array)$this->options['message_ids']);
			$ids = array_values(array_filter($ids));
			$filters['msa_msg_message_id'] = $ids
				? 'IN (' . implode(',', $ids) . ')'
				: 'IS NULL';
		}

		if (isset($this->options['file_id'])) {
			$filters['msa_fil_file_id'] = array($this->options['file_id'], PDO::PARAM_INT);
		}

		return $this->_get_resultsv2('msa_message_attachments', $filters, $this->order_by, $only_count, $debug);
	}
}
