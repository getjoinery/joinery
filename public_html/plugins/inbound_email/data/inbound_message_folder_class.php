<?php
/**
 * InboundMessageFolder - membership join (message ↔ folder), the heart of §7.
 *
 * One row per (message, folder) the message belongs to. Many-to-many: a Gmail
 * message labelled INBOX + Receipts + Work has three rows. A message present in
 * no membership folder (archived with no label, filed straight to All Mail) has
 * zero rows and is reachable only through the reader's folder-unfiltered
 * "All Mail" view.
 *
 * Each row carries the SHADOW that makes membership a conflict-free set merge:
 *   - imf_present_local : is the message in this folder per Joinery now.
 *   - imf_present_base  : was it in this folder at the last successful sync.
 * A membership element is DIRTY iff present_local ≠ present_base. A row driven to
 * (false, false) is deleted, not kept.
 *
 * See specs/two_way_imap_sync.md (§5, §7.2) and ImapSyncer.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class InboundMessageFolderException extends SystemBaseException {}

class InboundMessageFolder extends SystemBase {
	public static $prefix = 'imf';
	public static $tablename = 'imf_inbound_message_folders';
	public static $pkey_column = 'imf_inbound_message_folder_id';

	protected static $foreign_key_actions = array(
		'imf_iem_inbound_email_message_id' => array('action' => 'cascade'),
		'imf_iif_inbound_imap_folder_id'   => array('action' => 'cascade'),
	);

	public static $field_specifications = array(
		'imf_inbound_message_folder_id'    => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		// Unique on the (message, folder) pair — one membership row per pair.
		'imf_iem_inbound_email_message_id' => array('type'=>'int8', 'is_nullable'=>false, 'unique_with'=>array('imf_iif_inbound_imap_folder_id')),
		'imf_iif_inbound_imap_folder_id'   => array('type'=>'int8', 'is_nullable'=>false),
		'imf_present_local'                 => array('type'=>'bool', 'default'=>true, 'is_nullable'=>false),
		'imf_present_base'                  => array('type'=>'bool', 'default'=>true, 'is_nullable'=>false),
		// The message's UID in THIS folder, recorded at ingest. QRESYNC VANISHED
		// returns only UIDs for messages that no longer exist (they cannot be
		// re-fetched), so this is how a vanished UID is correlated back to its
		// membership row to clear it incrementally (§7.4). Null until first seen.
		'imf_imap_uid'                     => array('type'=>'int8'),
		'imf_imap_uidvalidity'             => array('type'=>'int8'),
		'imf_create_time'                  => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'imf_update_time'                  => array('type'=>'timestamp(6)'),
	);

	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in ' . static::$tablename);
		}
	}

	function prepare() {
		$this->set('imf_update_time', gmdate('Y-m-d H:i:s'));
	}

	/** Dirty iff local presence diverges from the shadow (an unsynced local edit). */
	function isDirty(): bool {
		return (bool)$this->get('imf_present_local') !== (bool)$this->get('imf_present_base');
	}

	/**
	 * Hard-delete one membership row by id. imf_ is a high-churn join with no
	 * dependents, so a direct DELETE is correct (and avoids the deletion-rules
	 * machinery of permanent_delete()).
	 */
	static function deleteRow(int $id): void {
		if ($id <= 0) {
			return;
		}
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare('DELETE FROM imf_inbound_message_folders WHERE imf_inbound_message_folder_id = ?');
		$stmt->execute(array($id));
	}

	/**
	 * Fetch the membership row for a (message, folder) pair, or null. Used by the
	 * pull/push reconciliation to read base/local state per element.
	 */
	static function find(int $messageId, int $folderId): ?InboundMessageFolder {
		$rows = new MultiInboundMessageFolder(array('message_id' => $messageId, 'folder_id' => $folderId));
		$rows->load();
		return count($rows) ? new InboundMessageFolder($rows->get(0)->key, TRUE) : null;
	}

	/**
	 * Set local + base presence for a (message, folder) pair in one call,
	 * creating the row if needed. A row driven to (false, false) is removed.
	 * When $uid is given (the message's UID in this folder) it is recorded for
	 * later VANISHED correlation. Returns the row, or null when it was removed.
	 */
	static function setPresence(int $messageId, int $folderId, bool $local, bool $base,
			?int $uid = null, ?int $uidvalidity = null): ?InboundMessageFolder {
		$row = self::find($messageId, $folderId);
		if (!$local && !$base) {
			if ($row) {
				self::deleteRow(intval($row->key));
			}
			return null;
		}
		if (!$row) {
			$row = new InboundMessageFolder(NULL);
			$row->set('imf_iem_inbound_email_message_id', $messageId);
			$row->set('imf_iif_inbound_imap_folder_id', $folderId);
		}
		$row->set('imf_present_local', $local);
		$row->set('imf_present_base', $base);
		if ($uid !== null) {
			$row->set('imf_imap_uid', $uid);
			$row->set('imf_imap_uidvalidity', $uidvalidity);
		}
		$row->prepare();
		$row->save();
		return $row;
	}

	/** The membership row holding a given UID in a folder (VANISHED correlation), or null. */
	static function findByFolderUid(int $folderId, int $uid): ?InboundMessageFolder {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			'SELECT imf_inbound_message_folder_id FROM imf_inbound_message_folders
			 WHERE imf_iif_inbound_imap_folder_id = ? AND imf_imap_uid = ? LIMIT 1');
		$stmt->execute(array($folderId, $uid));
		$id = $stmt->fetchColumn();
		return $id ? new InboundMessageFolder(intval($id), TRUE) : null;
	}
}

class MultiInboundMessageFolder extends SystemMultiBase {
	protected static $model_class = 'InboundMessageFolder';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['message_id'])) {
			$filters['imf_iem_inbound_email_message_id'] = array($this->options['message_id'], PDO::PARAM_INT);
		}

		if (isset($this->options['folder_id'])) {
			$filters['imf_iif_inbound_imap_folder_id'] = array($this->options['folder_id'], PDO::PARAM_INT);
		}

		// IN-list of folder ids (e.g. every membership folder for a feed).
		if (isset($this->options['folder_ids'])) {
			$ids = array();
			foreach ((array)$this->options['folder_ids'] as $id) {
				$ids[] = intval($id);
			}
			$filters['imf_iif_inbound_imap_folder_id'] = count($ids)
				? 'IN (' . implode(',', $ids) . ')'
				: 'IN (NULL)';
		}

		if (isset($this->options['present_local'])) {
			$filters['imf_present_local'] = $this->options['present_local'] ? '= true' : '= false';
		}

		// Dirty elements: local presence diverges from the shadow.
		if (!empty($this->options['dirty'])) {
			$filters['(imf_present_local'] = '<> imf_present_base)';
		}

		return $this->_get_resultsv2('imf_inbound_message_folders', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
