<?php
/**
 * InboundEmailLabel - a custom inbound-email label of one mailbox.
 *
 * A custom label is an arbitrary, user-named bucket a message can carry ("Receipts",
 * "Work"). Standard buckets (Inbox, Read, Starred, Spam, Trash, Sent) are NOT labels —
 * they are scalar columns on iem_inbound_email_messages. Only custom labels live here,
 * with genuine many-to-many membership in ilm_inbound_label_members.
 *
 * A label belongs to exactly one mailbox (ilb_iea_inbound_email_alias_id), its name is
 * unique within that mailbox, and a message carries only labels of its own mailbox: only
 * the mailbox's members see a label, deleting one touches only that mailbox, and two
 * mailboxes can each have a "Receipts". A label applied to the mailbox's local mail and
 * to its IMAP feed is the same label; an IMAP folder binds to one
 * (iif_ilb_inbound_email_label_id) to mirror it to the feed.
 *
 * The name lives in ilb_label_name. ilb_name, the column of the site-wide namespace this
 * replaced, is retired: the schema updater never drops a uniqueness rule, so its
 * site-wide one stays on a column new rows leave NULL (NULLs never collide).
 *
 * See mailbox_per_mailbox_labels.md.
 *
 * @version 2.0 - a label belongs to one mailbox: ilb_iea_inbound_email_alias_id, ilb_label_name
 *   unique within the mailbox; getByName()/findOrCreate() take the mailbox; belongsTo()
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class InboundEmailLabelException extends SystemBaseException {}

class InboundEmailLabel extends SystemBase {
	public static $prefix = 'ilb';
	public static $tablename = 'ilb_inbound_email_labels';
	public static $pkey_column = 'ilb_inbound_email_label_id';

	protected static $foreign_key_actions = array(
		// A mailbox's labels go with it; permanent_delete so each label's own rules
		// run (its memberships go, folder bindings are cleared).
		'ilb_iea_inbound_email_alias_id' => array('action' => 'permanent_delete'),
	);

	public static $field_specifications = array(
		'ilb_inbound_email_label_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		// The owning mailbox. Every write path sets it; nullable only so the rows of
		// the site-wide namespace could be given theirs by the mailbox migration.
		'ilb_iea_inbound_email_alias_id' => array('type'=>'int8', 'is_nullable'=>true, 'index'=>true),
		// Unique within the mailbox.
		'ilb_label_name'  => array('type'=>'varchar(255)', 'is_nullable'=>true,
			'unique_with'=>array('ilb_iea_inbound_email_alias_id')),
		'ilb_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'ilb_update_time' => array('type'=>'timestamp(6)'),
		'ilb_delete_time' => array('type'=>'timestamp(6)'),
	);

	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in ' . static::$tablename);
		}
	}

	function prepare() {
		$this->set('ilb_update_time', gmdate('Y-m-d H:i:s'));
	}

	/** The mailbox's non-deleted label with this name, or null. */
	static function getByName(int $alias_id, string $name): ?InboundEmailLabel {
		$name = trim($name);
		if ($name === '' || $alias_id <= 0) {
			return null;
		}
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			'SELECT ilb_inbound_email_label_id FROM ilb_inbound_email_labels
			 WHERE ilb_iea_inbound_email_alias_id = ? AND ilb_label_name = ? AND ilb_delete_time IS NULL LIMIT 1');
		$stmt->execute(array($alias_id, substr($name, 0, 255)));
		$id = $stmt->fetchColumn();
		return $id ? new InboundEmailLabel(intval($id), TRUE) : null;
	}

	/** Find-or-create the mailbox's label with this name, or null if blank or no mailbox. */
	static function findOrCreate(int $alias_id, string $name): ?InboundEmailLabel {
		$name = trim($name);
		if ($name === '' || $alias_id <= 0) {
			return null;
		}
		$existing = self::getByName($alias_id, $name);
		if ($existing) {
			return $existing;
		}
		$label = new InboundEmailLabel(NULL);
		$label->set('ilb_iea_inbound_email_alias_id', $alias_id);
		$label->set('ilb_label_name', substr($name, 0, 255));
		$label->prepare();
		$label->save();
		$label->load();
		return $label;
	}

	/** True when this label is a live label of mailbox $alias_id. */
	function belongsTo(int $alias_id): bool {
		return $alias_id > 0 && $this->key
			&& intval($this->get('ilb_iea_inbound_email_alias_id')) === $alias_id
			&& !$this->get('ilb_delete_time');
	}

	/**
	 * The mailbox a live label belongs to, or null (no such label, deleted, or one of
	 * the site-wide namespace not yet given a mailbox). One query, no model load.
	 */
	static function aliasOf(int $label_id): ?int {
		if ($label_id <= 0) {
			return null;
		}
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			'SELECT ilb_iea_inbound_email_alias_id FROM ilb_inbound_email_labels
			 WHERE ilb_inbound_email_label_id = ? AND ilb_delete_time IS NULL');
		$stmt->execute(array($label_id));
		$alias = $stmt->fetchColumn();
		return ($alias !== false && $alias !== null) ? intval($alias) : null;
	}

	/** Soft-delete the label and drop every membership it carries. */
	function softDelete(): void {
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_label_members_class.php'));
		InboundLabelMember::clearLabel(intval($this->key));
		$this->set('ilb_delete_time', gmdate('Y-m-d H:i:s'));
		$this->prepare();
		$this->save();
	}
}

class MultiInboundEmailLabel extends SystemMultiBase {
	protected static $model_class = 'InboundEmailLabel';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['alias_id'])) {
			$filters['ilb_iea_inbound_email_alias_id'] = array(intval($this->options['alias_id']), PDO::PARAM_INT);
		}
		if (isset($this->options['name'])) {
			$filters['ilb_label_name'] = array($this->options['name'], PDO::PARAM_STR);
		}


		return $this->_get_resultsv2('ilb_inbound_email_labels', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
