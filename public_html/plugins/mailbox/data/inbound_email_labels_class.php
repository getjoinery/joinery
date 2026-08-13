<?php
/**
 * InboundEmailLabel - a custom inbound-email label (the global name registry).
 *
 * A custom label is an arbitrary, user-named bucket a message can carry ("Receipts",
 * "Work"). Standard buckets (Inbox, Read, Starred, Spam, Trash, Sent) are NOT labels —
 * they are scalar columns on iem_inbound_email_messages. Only custom labels live here,
 * with genuine many-to-many membership in ilm_inbound_label_members.
 *
 * The name is globally unique, so a label is one shared concept across every mailbox: a
 * label applied to local mail and to an IMAP feed is the same label. An IMAP folder
 * binds to a label (iif_ilb_inbound_email_label_id) to mirror it to one feed.
 *
 * See specs/inbound_email_labels.md.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class InboundEmailLabelException extends SystemBaseException {}

class InboundEmailLabel extends SystemBase {
	public static $prefix = 'ilb';
	public static $tablename = 'ilb_inbound_email_labels';
	public static $pkey_column = 'ilb_inbound_email_label_id';

	public static $field_specifications = array(
		'ilb_inbound_email_label_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		// One global namespace — a label name is unique across the platform.
		'ilb_name'        => array('type'=>'varchar(255)', 'is_nullable'=>false, 'unique'=>true),
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

	/** The non-deleted label with this name, or null. */
	static function getByName(string $name): ?InboundEmailLabel {
		$name = trim($name);
		if ($name === '') {
			return null;
		}
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			'SELECT ilb_inbound_email_label_id FROM ilb_inbound_email_labels
			 WHERE ilb_name = ? AND ilb_delete_time IS NULL LIMIT 1');
		$stmt->execute(array($name));
		$id = $stmt->fetchColumn();
		return $id ? new InboundEmailLabel(intval($id), TRUE) : null;
	}

	/** Find-or-create the label with this name (the global namespace), or null if blank. */
	static function findOrCreate(string $name): ?InboundEmailLabel {
		$name = trim($name);
		if ($name === '') {
			return null;
		}
		$existing = self::getByName($name);
		if ($existing) {
			return $existing;
		}
		$label = new InboundEmailLabel(NULL);
		$label->set('ilb_name', substr($name, 0, 255));
		$label->prepare();
		$label->save();
		$label->load();
		return $label;
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

		if (isset($this->options['name'])) {
			$filters['ilb_name'] = array($this->options['name'], PDO::PARAM_STR);
		}


		return $this->_get_resultsv2('ilb_inbound_email_labels', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
