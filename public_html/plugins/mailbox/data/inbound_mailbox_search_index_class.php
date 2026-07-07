<?php
/**
 * InboundMailboxSearchIndex - one row per mailbox owner: bookkeeping for the
 * sealed FTS5 search index (specs/implemented/inbound_email_encryption_at_rest.md § 6,
 * MailboxIndex).
 *
 * The index itself is a disposable /dev/shm SQLite FTS5 file, seal-after-fold
 * persisted as a private File (imi_fil_file_id) so it survives across requests
 * without ever touching disk in cleartext. imi_sealed_key is the DEK that blob
 * is sealed under, regenerated on every fold (the blob is fully disposable —
 * losing it only costs a rebuild from the sealed message rows, never data).
 * imi_fts_high_water is the last message id already folded in.
 *
 * Never excluded from backup — losing this row only costs a search-index
 * rebuild, not content (the ground truth is always the sealed message rows).
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class InboundMailboxSearchIndexException extends SystemBaseException {}

class InboundMailboxSearchIndex extends SystemBase {
	public static $prefix = 'imi';
	public static $tablename = 'imi_inbound_mailbox_search_index';
	public static $pkey_column = 'imi_inbound_mailbox_search_index_id';

	public static $api_readable = false;
	public static $api_writable = false;

	protected static $foreign_key_actions = [
		'imi_usr_user_id' => ['action' => 'cascade'],
	];

	public static $field_specifications = array(
		'imi_inbound_mailbox_search_index_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true, 'is_primary_key'=>true),
		'imi_usr_user_id'      => array('type'=>'int8', 'is_nullable'=>false, 'unique'=>true,
			'foreign_key'=>array('table'=>'usr_users', 'column'=>'usr_user_id', 'on_delete'=>'CASCADE')),
		'imi_fts_high_water'   => array('type'=>'int8', 'is_nullable'=>false, 'default'=>0),
		'imi_fil_file_id'      => array('type'=>'int8', 'is_nullable'=>true),
		'imi_sealed_key'       => array('type'=>'text', 'is_nullable'=>true),
		'imi_created_time'     => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'imi_updated_time'     => array('type'=>'timestamp(6)', 'is_nullable'=>true),
	);

	public static $permanent_delete_actions = array();

	/** The one bookkeeping row for a mailbox owner, creating it if absent. */
	public static function loadOrCreateForUser(int $user_id): InboundMailboxSearchIndex {
		$multi = new MultiInboundMailboxSearchIndex(['user_id' => $user_id]);
		$multi->load();
		if ($multi->count() > 0) {
			return $multi->get(0);
		}
		$row = new InboundMailboxSearchIndex(NULL);
		$row->set('imi_usr_user_id', $user_id);
		$row->save();
		$row->load();
		return $row;
	}
}

class MultiInboundMailboxSearchIndex extends SystemMultiBase {
	protected static $model_class = 'InboundMailboxSearchIndex';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];
		if (isset($this->options['user_id'])) {
			$filters['imi_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
		}
		return $this->_get_resultsv2('imi_inbound_mailbox_search_index', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
