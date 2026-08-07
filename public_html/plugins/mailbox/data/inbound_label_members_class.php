<?php
/**
 * InboundLabelMember - one row per (message, custom label): the membership TRUTH and
 * its IMAP shadow in a single row.
 *
 *   - ilm_present_local : the message carries this label (the truth the reader/filters set).
 *   - ilm_present_base  : the message was in the bound remote folder at the last sync
 *     (the base the two-way merge reconciles against).
 *   - ilm_iif_inbound_imap_folder_id : the binding on the message's feed that mirrors
 *     this label, or NULL for an unbound membership (local mail, or a label not bound on
 *     the message's feed). An unbound row keeps present_base = present_local, so it never
 *     looks dirty and is never pushed.
 *   - ilm_imap_uid / ilm_imap_uidvalidity : the message's UID in that folder, so a
 *     QRESYNC VANISHED report (which returns only UIDs) can be correlated back to the
 *     membership.
 *
 * An element is DIRTY when ilm_present_local <> ilm_present_base — a plain column
 * comparison, so the partial index (WHERE present_local <> present_base) makes the push
 * scan O(dirty), not O(total). A row driven to clean-absent (both false) is deleted.
 *
 * One row works because an inbound message lives on exactly one IMAP feed, so each
 * (message, label) maps to at most one binding — the message's own feed's folder for
 * that label — and the shadow folds inline even though membership is keyed by label.
 *
 * Standard buckets (Inbox/Read/Starred/Spam/Trash/Sent) are NOT labels: they are columns
 * on iem_inbound_email_messages and never appear here. Only custom labels do.
 *
 * See specs/inbound_email_labels.md and ImapSyncer.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class InboundLabelMemberException extends SystemBaseException {}

class InboundLabelMember extends SystemBase {
	public static $prefix = 'ilm';
	public static $tablename = 'ilm_inbound_label_members';
	public static $pkey_column = 'ilm_inbound_label_member_id';

	protected static $foreign_key_actions = array(
		'ilm_iem_inbound_email_message_id' => array('action' => 'cascade'),
		'ilm_ilb_inbound_email_label_id'   => array('action' => 'cascade'),
		'ilm_iif_inbound_imap_folder_id'   => array('action' => 'cascade'),
	);

	public static $field_specifications = array(
		'ilm_inbound_label_member_id'      => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		// One row per (message, label).
		'ilm_iem_inbound_email_message_id' => array('type'=>'int8', 'is_nullable'=>false, 'unique_with'=>array('ilm_ilb_inbound_email_label_id')),
		'ilm_ilb_inbound_email_label_id'   => array('type'=>'int8', 'is_nullable'=>false),
		'ilm_present_local'                => array('type'=>'bool', 'default'=>true, 'is_nullable'=>false),
		'ilm_present_base'                 => array('type'=>'bool', 'default'=>false, 'is_nullable'=>false),
		// The binding on the message's feed (NULL = unbound: local mail or a label not
		// bound on this feed). When set, the row is an IMAP shadow for that folder.
		'ilm_iif_inbound_imap_folder_id'   => array('type'=>'int8'),
		'ilm_imap_uid'                     => array('type'=>'int8'),
		'ilm_imap_uidvalidity'             => array('type'=>'int8'),
		'ilm_create_time'                  => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'ilm_update_time'                  => array('type'=>'timestamp(6)'),
	);

	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in ' . static::$tablename);
		}
	}

	function prepare() {
		$this->set('ilm_update_time', gmdate('Y-m-d H:i:s'));
	}

	// ── lookups ──────────────────────────────────────────────────────────────

	/** The membership row for a (message, label), or null. */
	static function find(int $messageId, int $labelId): ?InboundLabelMember {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			'SELECT ilm_inbound_label_member_id FROM ilm_inbound_label_members
			 WHERE ilm_iem_inbound_email_message_id = ? AND ilm_ilb_inbound_email_label_id = ? LIMIT 1');
		$stmt->execute(array($messageId, $labelId));
		$id = $stmt->fetchColumn();
		return $id ? new InboundLabelMember(intval($id), TRUE) : null;
	}

	/** The membership row holding a given UID in a binding (VANISHED correlation), or null. */
	static function findByFolderUid(int $folderId, int $uid): ?InboundLabelMember {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			'SELECT ilm_inbound_label_member_id FROM ilm_inbound_label_members
			 WHERE ilm_iif_inbound_imap_folder_id = ? AND ilm_imap_uid = ? LIMIT 1');
		$stmt->execute(array($folderId, $uid));
		$id = $stmt->fetchColumn();
		return $id ? new InboundLabelMember(intval($id), TRUE) : null;
	}

	/** True iff the message currently carries the label (present_local). */
	static function isMember(int $messageId, int $labelId): bool {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			'SELECT 1 FROM ilm_inbound_label_members
			 WHERE ilm_iem_inbound_email_message_id = ? AND ilm_ilb_inbound_email_label_id = ?
			   AND ilm_present_local = true LIMIT 1');
		$stmt->execute(array($messageId, $labelId));
		return (bool)$stmt->fetchColumn();
	}

	/**
	 * The iif_ folder on the message's own feed that mirrors $labelId, or null when the
	 * message is local (no feed) or the label isn't bound on that feed. One feed per
	 * message means at most one such binding.
	 */
	static function resolveBinding(int $messageId, int $labelId): ?int {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			'SELECT f.iif_inbound_imap_folder_id
			 FROM iem_inbound_email_messages m
			 JOIN iif_inbound_imap_folders f
			   ON f.iif_iia_inbound_imap_account_id = m.iem_iia_inbound_imap_account_id
			  AND f.iif_ilb_inbound_email_label_id = ?
			 WHERE m.iem_inbound_email_message_id = ?
			   AND m.iem_iia_inbound_imap_account_id IS NOT NULL
			 LIMIT 1');
		$stmt->execute(array($labelId, $messageId));
		$id = $stmt->fetchColumn();
		return $id ? intval($id) : null;
	}

	// ── truth mutations (reader / filters) ───────────────────────────────────

	/**
	 * Apply a label to a message (idempotent). Resolves the binding on the message's own
	 * feed: with a binding the row is a bound shadow (present_base=false → a dirty add the
	 * sync push COPYs); without one it is unbound (present_base=present_local → clean,
	 * never pushed). Re-applying a not-yet-pushed remove simply cancels it.
	 */
	static function apply(int $messageId, int $labelId): void {
		if ($messageId <= 0 || $labelId <= 0) {
			return;
		}
		$binding = self::resolveBinding($messageId, $labelId);
		$row = self::find($messageId, $labelId);
		if (!$row) {
			$row = new InboundLabelMember(NULL);
			$row->set('ilm_iem_inbound_email_message_id', $messageId);
			$row->set('ilm_ilb_inbound_email_label_id', $labelId);
			$row->set('ilm_present_base', false);
		}
		$row->set('ilm_present_local', true);
		if ($binding !== null) {
			// Bound add: keep present_base (false on a fresh row) → dirty, push COPYs.
			$row->set('ilm_iif_inbound_imap_folder_id', $binding);
		} else {
			// Unbound: base == local so it is clean and never enters the dirty index.
			$row->set('ilm_iif_inbound_imap_folder_id', null);
			$row->set('ilm_present_base', true);
		}
		$row->prepare();
		$row->save();
	}

	/**
	 * Remove a label from a message. A bound membership with a synced shadow becomes a
	 * dirty remove (present_local=false, present_base kept) the push EXPUNGEs then clears;
	 * everything else (unbound, or a never-synced add) is deleted outright.
	 */
	static function remove(int $messageId, int $labelId): void {
		$row = self::find($messageId, $labelId);
		if (!$row) {
			return;
		}
		$bound = $row->get('ilm_iif_inbound_imap_folder_id') !== null;
		$base = (bool)$row->get('ilm_present_base');
		if ($bound && $base) {
			$row->set('ilm_present_local', false);
			$row->prepare();
			$row->save();
		} else {
			self::deleteRow(intval($row->key));
		}
	}

	// ── shadow mutations (ingest / sync push) ────────────────────────────────

	/**
	 * Record/advance the IMAP shadow for a (message, label) on a binding: set
	 * present_base and (when given) the UID, creating the row if needed. Used at ingest
	 * (a message seen in a label's remote folder, present_local=present_base=true) and
	 * after a confirmed push (present_base advanced to match local). present_base=false
	 * on a row whose local is also false deletes it (clean-absent); otherwise it lowers
	 * just the shadow. Returns the row, or null when removed.
	 */
	static function setBaseline(int $messageId, int $labelId, int $folderId, bool $base,
			?int $uid = null, ?int $uidvalidity = null): ?InboundLabelMember {
		$row = self::find($messageId, $labelId);
		if (!$base) {
			if ($row) {
				if (!(bool)$row->get('ilm_present_local')) {
					self::deleteRow(intval($row->key));
					return null;
				}
				$row->set('ilm_present_base', false);
				$row->prepare();
				$row->save();
			}
			return $row;
		}
		if (!$row) {
			$row = new InboundLabelMember(NULL);
			$row->set('ilm_iem_inbound_email_message_id', $messageId);
			$row->set('ilm_ilb_inbound_email_label_id', $labelId);
			$row->set('ilm_present_local', true);
		}
		$row->set('ilm_iif_inbound_imap_folder_id', $folderId);
		$row->set('ilm_present_base', true);
		if ($uid !== null) {
			$row->set('ilm_imap_uid', $uid);
			$row->set('ilm_imap_uidvalidity', $uidvalidity);
		}
		$row->prepare();
		$row->save();
		return $row;
	}

	// ── deletes ──────────────────────────────────────────────────────────────

	/** Hard-delete one membership row by id (high-churn store, no dependents). */
	static function deleteRow(int $id): void {
		if ($id <= 0) {
			return;
		}
		$db = DbConnector::get_instance()->get_db_link();
		$db->prepare('DELETE FROM ilm_inbound_label_members WHERE ilm_inbound_label_member_id = ?')
			->execute(array($id));
	}

	/** Drop the (message, label) row, if any. */
	static function clear(int $messageId, int $labelId): void {
		$row = self::find($messageId, $labelId);
		if ($row) {
			self::deleteRow(intval($row->key));
		}
	}

	/** Drop every membership of a label (label deletion). */
	static function clearLabel(int $labelId): void {
		$db = DbConnector::get_instance()->get_db_link();
		$db->prepare('DELETE FROM ilm_inbound_label_members WHERE ilm_ilb_inbound_email_label_id = ?')
			->execute(array($labelId));
	}

	/**
	 * Drop a message's memberships bound to a given set of feed folders (the exclusive
	 * collapse after a MOVE, and the trash relocation). $keepFolderId is left intact.
	 */
	static function clearForFolders(int $messageId, array $folderIds, ?int $keepFolderId = null): void {
		$ids = array();
		foreach ($folderIds as $fid) {
			$fid = intval($fid);
			if ($fid > 0 && $fid !== $keepFolderId) {
				$ids[] = $fid;
			}
		}
		if (!count($ids)) {
			return;
		}
		$in = implode(',', $ids);
		$db = DbConnector::get_instance()->get_db_link();
		$db->prepare("DELETE FROM ilm_inbound_label_members
			WHERE ilm_iem_inbound_email_message_id = ? AND ilm_iif_inbound_imap_folder_id IN ($in)")
			->execute(array($messageId));
	}
}

class MultiInboundLabelMember extends SystemMultiBase {
	protected static $model_class = 'InboundLabelMember';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['message_id'])) {
			$filters['ilm_iem_inbound_email_message_id'] = array($this->options['message_id'], PDO::PARAM_INT);
		}

		if (isset($this->options['label_id'])) {
			$filters['ilm_ilb_inbound_email_label_id'] = array($this->options['label_id'], PDO::PARAM_INT);
		}

		if (isset($this->options['folder_id'])) {
			$filters['ilm_iif_inbound_imap_folder_id'] = array($this->options['folder_id'], PDO::PARAM_INT);
		}

		if (!empty($this->options['present_local'])) {
			$filters['ilm_present_local'] = '= true';
		}

		return $this->_get_resultsv2('ilm_inbound_label_members', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
