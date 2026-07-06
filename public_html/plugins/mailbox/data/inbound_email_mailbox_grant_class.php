<?php
/**
 * InboundEmailMailboxGrant - Access grant linking a user to a mailbox (alias).
 *
 * A mailbox IS an address (alias). Access to a mailbox is an explicit grant of
 * a user to an alias. One alias can have several grants (a shared team inbox
 * like legal@ worked by Beth and Bob); one user can hold several grants
 * (beth@ + legal@). The (alias_id, user_id) pair is UNIQUE — one grant per pair.
 *
 * Read/star state lives on the message row (shared per mailbox), so this table
 * carries no state beyond the access relationship itself.
 *
 * Cascade: the platform cascades via application-level deletion rules
 * (del_deletion_rules, consulted by SystemBase::permanent_delete), not DB FK
 * constraints. Both the user→grant and alias→grant cascades auto-register from
 * $foreign_key_actions (deleting a user or deleting an alias each remove the
 * grants that reference it). MailboxViewer::accessibleAliasIds() also excludes
 * soft-deleted aliases at the read layer, independent of the cascade — belt
 * and suspenders against a grant to a mailbox that was soft-deleted rather than
 * permanently deleted.
 *
 * @version 1.2
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class InboundEmailMailboxGrantException extends SystemBaseException {}

class InboundEmailMailboxGrant extends SystemBase {
	public static $prefix = 'ieg';
	public static $tablename = 'ieg_inbound_email_mailbox_grants';
	public static $pkey_column = 'ieg_inbound_email_mailbox_grant_id';

	protected static $foreign_key_actions = [
		'ieg_iea_inbound_email_alias_id' => ['action' => 'cascade'],
		'ieg_usr_user_id'                => ['action' => 'cascade'],
	];

	public static $field_specifications = array(
		'ieg_inbound_email_mailbox_grant_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'ieg_iea_inbound_email_alias_id'     => array('type'=>'int4', 'is_nullable'=>false, 'unique_with'=>array('ieg_usr_user_id')),
		'ieg_usr_user_id'                    => array('type'=>'int4', 'is_nullable'=>false),
		'ieg_create_time'                    => array('type'=>'timestamp(6)', 'default'=>'now()'),
	);

	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in ' . static::$tablename);
		}
	}

	/**
	 * Return the alias ids a user holds a grant for.
	 *
	 * @param int $user_id
	 * @return int[] alias ids (may be empty)
	 */
	static function alias_ids_for_user(int $user_id): array {
		$grants = new MultiInboundEmailMailboxGrant(array('user_id' => $user_id));
		$grants->load();
		$ids = array();
		foreach ($grants as $g) {
			$ids[] = intval($g->get('ieg_iea_inbound_email_alias_id'));
		}
		return $ids;
	}

	/**
	 * Return the user ids granted access to an alias (the mailbox's members).
	 *
	 * @param int $alias_id
	 * @return int[] user ids (may be empty)
	 */
	static function user_ids_for_alias(int $alias_id): array {
		$grants = new MultiInboundEmailMailboxGrant(array('alias_id' => $alias_id));
		$grants->load();
		$ids = array();
		foreach ($grants as $g) {
			$ids[] = intval($g->get('ieg_usr_user_id'));
		}
		return $ids;
	}

	/**
	 * Make the grant set for an alias exactly match $user_ids: insert grants for
	 * newly-added users, delete grants for removed ones, leave unchanged ones be.
	 * Used by the alias editor's "Users with access" control.
	 *
	 * @param int   $alias_id
	 * @param int[] $user_ids the complete desired set of grantees
	 */
	static function sync_for_alias(int $alias_id, array $user_ids): void {
		$desired = array();
		foreach ($user_ids as $uid) {
			$uid = intval($uid);
			if ($uid > 0) {
				$desired[$uid] = true;
			}
		}

		$existing = new MultiInboundEmailMailboxGrant(array('alias_id' => $alias_id));
		$existing->load();

		$current = array();
		foreach ($existing as $g) {
			$uid = intval($g->get('ieg_usr_user_id'));
			$current[$uid] = $g;
		}

		// Delete grants no longer wanted. The grant table has no delete_time
		// column — a removed grant is a hard delete (permanent_delete handles it).
		foreach ($current as $uid => $grant) {
			if (!isset($desired[$uid])) {
				$grant->permanent_delete();
			}
		}

		// Insert grants newly wanted.
		foreach ($desired as $uid => $_) {
			if (!isset($current[$uid])) {
				$grant = new InboundEmailMailboxGrant(NULL);
				$grant->set('ieg_iea_inbound_email_alias_id', $alias_id);
				$grant->set('ieg_usr_user_id', $uid);
				$grant->save();
			}
		}
	}
}

class MultiInboundEmailMailboxGrant extends SystemMultiBase {
	protected static $model_class = 'InboundEmailMailboxGrant';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['user_id'])) {
			$filters['ieg_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['alias_id'])) {
			$filters['ieg_iea_inbound_email_alias_id'] = [$this->options['alias_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['alias_ids'])) {
			$ids = array();
			foreach ((array)$this->options['alias_ids'] as $id) {
				$ids[] = intval($id);
			}
			if (count($ids)) {
				$filters['ieg_iea_inbound_email_alias_id'] = 'IN (' . implode(',', $ids) . ')';
			} else {
				// Empty set matches nothing.
				$filters['ieg_iea_inbound_email_alias_id'] = 'IN (NULL)';
			}
		}

		return $this->_get_resultsv2('ieg_inbound_email_mailbox_grants', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
