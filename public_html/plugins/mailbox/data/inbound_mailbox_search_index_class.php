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
 * @version 1.1
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

	// Retention: the /dev/shm working copies of this index, not the rows.
	// window_setting is null because the rule is unconditional — a working copy
	// whose vault window has closed is never wanted, so there is no age for an
	// operator to choose. See sweepWorkingCopies() for what actually runs.
	public static $retention_policy = array(
		'label'          => 'Mailbox index working copies',
		'purge_method'   => 'sweepWorkingCopies',
		'window_setting' => null,
	);

	public static $field_specifications = array(
		'imi_inbound_mailbox_search_index_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true, 'is_primary_key'=>true),
		'imi_usr_user_id'      => array('type'=>'int8', 'is_nullable'=>false, 'unique'=>true,
			'foreign_key'=>array('table'=>'usr_users', 'column'=>'usr_user_id', 'on_delete'=>'CASCADE')),
		'imi_fts_high_water'   => array('type'=>'int8', 'is_nullable'=>false, 'default'=>0),
		// Row ids at-or-below the high-water mark that changed after folding (a draft
		// morphed into its Sent row keeps its id) — folded on the next cycle, then cleared.
		'imi_refold_ids'       => array('type'=>'text', 'is_nullable'=>true),   // JSON int array
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

	/**
	 * Passive-close safety net for the /dev/shm working copies of this index
	 * (specs/implemented/inbound_email_encryption_at_rest.md § 6.4).
	 *
	 * The wipe callback (plugins/mailbox/includes/bootstrap.php) already deletes
	 * a user's working copy on an explicit lock or credential event. This
	 * catches everything else that ends a window without firing that callback —
	 * an APCu TTL idle expiry, a php-fpm worker recycle. Worst case a working
	 * copy lingers until the next sweep.
	 *
	 * Unconditional: a copy whose vault window has closed is plaintext nobody
	 * asked for, so there is no window to wait out. $window is ignored.
	 *
	 * @return array  removed, message
	 */
	public static function sweepWorkingCopies($window = 0) {
		require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
		require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));

		$files = glob('/dev/shm/mailfts_*.sqlite');
		if ($files === false || !count($files)) {
			return array('removed' => 0, 'message' => 'no working copies present');
		}

		$swept = 0;
		foreach ($files as $path) {
			if (!preg_match('/mailfts_(\d+)\.sqlite$/', basename($path), $m)) {
				continue; // not one of ours — leave it alone
			}
			if (VaultUnlock::hasAnyOpenWindow((int)$m[1], UserEncryptionVault::SCOPE_USER)) {
				continue; // still in-window somewhere — not this sweep's to touch
			}
			if (@unlink($path)) {
				$swept++;
			}
		}

		return array(
			'removed' => $swept,
			'message' => $swept === 0 ? 'no orphaned working copies' : $swept . ' orphaned working cop' . ($swept === 1 ? 'y' : 'ies'),
		);
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
