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
 * Compose signature (specs/mailbox_compose_maturity.md § Phase 3): ieg_signature holds
 * a per-grant (user × mailbox) sanitized-HTML signature, edited by the grantee via
 * signatureFor()/saveSignature() and inserted client-side at compose time. Personal, not
 * mailbox administration — never sealed, no admin surface.
 *
 * A SEALING mailbox is the exception to "one alias can have several grants":
 * sealing encrypts to one person, so it must have exactly one holder and that
 * holder must have a vault (specs/mailbox_connect_flow.md § E). The rule lives
 * in sync_for_alias(), which every grant-writing path goes through, because a
 * mailbox that seals with nobody to seal to writes PLAINTEXT — silently, on a
 * mailbox whose whole promise is that it does not. The protection ceremony
 * checks both at the raise; this is what holds them afterwards.
 *
 * @version 1.5
 * @changelog 1.5 - sync_for_alias serialises per mailbox on an advisory lock
 *   (two concurrent syncs could each pass the one-holder check and each
 *   insert); the delete backstop also refuses leaving a sole holder without a
 *   vault
 * @version 1.4
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class InboundEmailMailboxGrantException extends SystemBaseException {}

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/NotifiesRelayMapOnChange.php'));

class InboundEmailMailboxGrant extends SystemBase {
	use NotifiesRelayMapOnChange;
	public static $prefix = 'ieg';
	public static $tablename = 'ieg_inbound_email_mailbox_grants';
	public static $pkey_column = 'ieg_inbound_email_mailbox_grant_id';

	protected static $foreign_key_actions = [
		'ieg_iea_inbound_email_alias_id' => ['action' => 'cascade'],
		// permanent_delete, not cascade: the user cascade is the one grant write
		// that does not pass through sync_for_alias, and a flat SQL delete would
		// take the last holder off a sealing mailbox without anything noticing.
		// This action loads each row as a model and calls its permanent_delete(),
		// which refuses that case (see below).
		'ieg_usr_user_id'                => ['action' => 'permanent_delete'],
	];

	public static $field_specifications = array(
		'ieg_inbound_email_mailbox_grant_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'ieg_iea_inbound_email_alias_id'     => array('type'=>'int4', 'is_nullable'=>false, 'unique_with'=>array('ieg_usr_user_id')),
		'ieg_usr_user_id'                    => array('type'=>'int4', 'is_nullable'=>false),
		// Per-grant compose signature (specs/mailbox_compose_maturity.md § Phase 3):
		// sanitized HTML, personal to (this user, this mailbox). NOT sealed — a
		// signature is a template broadcast in cleartext on every outgoing message,
		// so sealing protects nothing and would add a decrypt dependency to
		// compose-open. Inserted client-side so the user sees exactly what sends.
		'ieg_signature'                      => array('type'=>'text', 'is_nullable'=>true),
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
	 * The compose signature for (user, mailbox), or '' when none / no grant.
	 * Personal to the grant — one grantee of a shared alias never sees another's.
	 */
	static function signatureFor(int $user_id, int $alias_id): string {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare('SELECT ieg_signature FROM ieg_inbound_email_mailbox_grants
			WHERE ieg_usr_user_id = ? AND ieg_iea_inbound_email_alias_id = ? LIMIT 1');
		$stmt->execute(array($user_id, $alias_id));
		$v = $stmt->fetchColumn();
		return ($v !== false && $v !== null) ? (string)$v : '';
	}

	/**
	 * Save the compose signature on a user's OWN grant — a targeted single-column
	 * UPDATE scoped to (user, mailbox), so it needs no admin permission (the
	 * model's authenticate_write governs full-row admin edits, not a member editing
	 * their personal signature). Returns false when the user holds no such grant
	 * (rowCount 0), which the caller treats as "not authorized".
	 */
	static function saveSignature(int $user_id, int $alias_id, string $html): bool {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare('UPDATE ieg_inbound_email_mailbox_grants SET ieg_signature = ?
			WHERE ieg_usr_user_id = ? AND ieg_iea_inbound_email_alias_id = ?');
		$stmt->bindValue(1, $html === '' ? null : $html, $html === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
		$stmt->bindValue(2, $user_id, PDO::PARAM_INT);
		$stmt->bindValue(3, $alias_id, PDO::PARAM_INT);
		$stmt->execute();
		return $stmt->rowCount() > 0;
	}

	/**
	 * The refusal message for setting a sealing mailbox's holder set to
	 * $user_ids, or null when the change is fine. THE rule, stated once: a
	 * mailbox that seals content has exactly one holder, and that holder holds a
	 * vault to seal to. Every caller that writes grants — the alias editor, the
	 * combined IMAP editor, headless provisioning, the ceremony's inline fixes —
	 * resolves to this, so the refusal cannot be routed around.
	 *
	 * $seals is the mailbox's effective posture (InboundEmailAlias::seals_content()).
	 * A non-sealing mailbox has no constraint at all: shared team inboxes are
	 * ordinary there.
	 */
	static function grant_set_error(bool $seals, array $user_ids): ?string {
		if (!$seals) {
			return null;
		}
		$ids = array();
		foreach ($user_ids as $uid) {
			$uid = intval($uid);
			if ($uid > 0) {
				$ids[$uid] = true;
			}
		}
		if (count($ids) > 1) {
			return 'This mailbox is protected, and protected mail is sealed to one person\'s key — '
				. 'it can have exactly one member. To share it, set this mailbox to Standard first.';
		}
		if (count($ids) === 0) {
			return 'This mailbox is protected, so it needs its owner from the start — mail arriving '
				. 'with no one to seal to would be stored unprotected. Pick exactly one member.';
		}

		// The holder's vault is the key the mail seals TO. Without it a protected
		// mailbox stores plaintext, so "has a member" is not enough on its own.
		require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
		require_once(PathHelper::getIncludePath('data/users_class.php'));
		$holder_id = intval(array_key_first($ids));
		if (UserEncryptionVault::loadForUser($holder_id) === null) {
			$user = new User($holder_id, TRUE);
			$name = trim((string)$user->get('usr_first_name') . ' ' . (string)$user->get('usr_last_name'));
			if ($name === '') {
				$name = (string)$user->get('usr_email');
			}
			if ($name === '') {
				$name = 'That member';
			}
			return $name . ' has no vault yet, and a protected mailbox seals its mail to its member\'s '
				. 'vault — without one the mail would be stored unprotected. Only they can create it, '
				. 'from their Security page. Set this mailbox to Standard first if you need it now.';
		}
		return null;
	}

	/**
	 * Make the grant set for an alias exactly match $user_ids: insert grants for
	 * newly-added users, delete grants for removed ones, leave unchanged ones be.
	 * Used by the alias editor's "Users with access" control.
	 *
	 * Refuses outright on a sealing mailbox when the result would break the
	 * one-holder-with-a-vault rule — see grant_set_error(). Every surface that
	 * writes grants comes through here, so the invalid state is unreachable
	 * rather than merely discouraged.
	 *
	 * @param int   $alias_id
	 * @param int[] $user_ids the complete desired set of grantees
	 * @throws InboundEmailMailboxGrantException
	 */
	static function sync_for_alias(int $alias_id, array $user_ids): void {
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));

		// One sync per mailbox at a time. The rule below is check-then-write, and
		// two concurrent syncs that each read an empty grant set would each pass
		// the one-holder check and each insert — two holders on a sealing
		// mailbox, the exact state this method exists to make unreachable. The
		// advisory lock is transaction-scoped, so it releases itself however this
		// call ends.
		$db = DbConnector::get_instance()->get_db_link();
		$own_tx = !$db->inTransaction();
		if ($own_tx) {
			$db->beginTransaction();
		}
		try {
			$lock = $db->prepare('SELECT pg_advisory_xact_lock(?, ?)');
			$lock->execute(array(self::SYNC_LOCK_CLASS, $alias_id));
			self::sync_for_alias_locked($alias_id, $user_ids);
			if ($own_tx) {
				$db->commit();
			}
		} catch (\Throwable $e) {
			if ($own_tx && $db->inTransaction()) {
				$db->rollBack();
			}
			throw $e;
		}
	}

	/** The advisory-lock class sync_for_alias() serialises on (arbitrary, fixed). */
	const SYNC_LOCK_CLASS = 74211;

	/** The body of sync_for_alias(), entered only under the per-alias lock. */
	private static function sync_for_alias_locked(int $alias_id, array $user_ids): void {
		$alias = new InboundEmailAlias($alias_id, TRUE);
		$error = self::grant_set_error($alias->key ? $alias->seals_content() : false, $user_ids);
		if ($error !== null) {
			throw new InboundEmailMailboxGrantException($error);
		}

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

		// Insert first, THEN remove. Handing a sealing mailbox from one holder to
		// another is a legitimate edit, and removing the outgoing holder first
		// would take it through zero holders — which the last-holder refusal
		// below would (correctly) stop. Adding first means the mailbox always has
		// someone to seal to, including mid-call.
		foreach ($desired as $uid => $_) {
			if (!isset($current[$uid])) {
				$grant = new InboundEmailMailboxGrant(NULL);
				$grant->set('ieg_iea_inbound_email_alias_id', $alias_id);
				$grant->set('ieg_usr_user_id', $uid);
				$grant->save();
			}
		}

		// Delete grants no longer wanted. The grant table has no delete_time
		// column — a removed grant is a hard delete (permanent_delete handles it).
		foreach ($current as $uid => $grant) {
			if (!isset($desired[$uid])) {
				$grant->permanent_delete();
			}
		}
	}

	/**
	 * Removing one grant, refusing to take the last holder off a sealing
	 * mailbox. This is the backstop for the write that does NOT come through
	 * sync_for_alias: deleting a user cascades their grant rows away, and doing
	 * that to the sole holder of a protected mailbox would leave every message
	 * arriving after it stored in plaintext.
	 */
	public function permanent_delete($debug = false) {
		$alias_id = intval($this->get('ieg_iea_inbound_email_alias_id'));
		if ($alias_id > 0 && !$debug) {
			require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
			$alias = new InboundEmailAlias($alias_id, TRUE);
			if ($alias->key && $alias->seals_content()) {
				$remaining = array();
				foreach (self::user_ids_for_alias($alias_id) as $uid) {
					if (intval($uid) !== intval($this->get('ieg_usr_user_id'))) {
						$remaining[] = intval($uid);
					}
				}
				if (count($remaining) === 0) {
					throw new SystemDisplayableError(
						$alias->get_full_address() . ' is a protected mailbox and this is its only member. '
						. 'Mail arriving with no one to seal to would be stored unprotected — set that '
						. 'mailbox to Standard, or give it another member, first.');
				}
				// The other half of the invariant, held here too: on a (legacy)
				// multi-holder sealing mailbox, removing a grant is fine while it
				// moves TOWARD one holder with a vault — but not when the one it
				// would leave behind has no vault to seal to.
				if (count($remaining) === 1) {
					$err = self::grant_set_error(true, $remaining);
					if ($err !== null) {
						throw new SystemDisplayableError($alias->get_full_address() . ': ' . $err);
					}
				}
			}
		}
		return parent::permanent_delete($debug);
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
