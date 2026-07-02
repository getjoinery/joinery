<?php
/**
 * MailboxViewer - the access seam for the Mailbox Reader.
 *
 * A small value object answering "who is looking, and what may they touch?".
 * A mailbox IS an alias, so a "scope" is just the list of alias ids a request
 * may read or mutate — the viewer returns that list directly; there is no
 * separate scope object.
 *
 *   - A permission-10 superadmin is all-access: every alias, plus a merged
 *     "All mail" view that (in the service) also surfaces unmatched NULL-alias
 *     mail. accessibleAliasIds() returns every alias id for them.
 *   - Everyone else sees only the aliases they hold an explicit grant for
 *     (the ieg grant table).
 *
 * Audience-to-query translation lives ONLY in scopeAliasIds(); no other part of
 * the reader decides visibility. Both reader mounts — the admin page and the
 * member page at /profile/inbound_email/mailbox — sit on this one seam.
 *
 * @version 1.1
 */

require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_mailbox_grant_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_alias_class.php'));

final class MailboxViewer {

	/** @var int */
	private $user_id;
	/** @var int */
	private $permission;
	/** @var int[]|null lazily-resolved accessible alias ids */
	private $accessible_cache = null;

	private function __construct(int $user_id, int $permission) {
		$this->user_id = $user_id;
		$this->permission = $permission;
	}

	public static function fromSession(SessionControl $session): MailboxViewer {
		return new MailboxViewer(
			intval($session->get_user_id()),
			intval($session->get_permission())
		);
	}

	/**
	 * Build a viewer for an explicit user/permission, independent of the current
	 * session. The session adapter above is the normal path; this exists for the
	 * deferred member-mount and for tests.
	 */
	public static function forUser(int $user_id, int $permission): MailboxViewer {
		return new MailboxViewer($user_id, $permission);
	}

	/**
	 * Superadmin oversight: sees every mailbox plus the merged "All mail" /
	 * "Unmatched" views without needing a grant per address.
	 */
	public function isAllAccess(): bool {
		return $this->permission >= 10;
	}

	/**
	 * The alias ids this viewer may read. For an all-access superadmin, every
	 * (non-deleted) alias id; otherwise the aliases the viewer holds a grant for.
	 *
	 * @return int[]
	 */
	public function accessibleAliasIds(): array {
		if ($this->accessible_cache !== null) {
			return $this->accessible_cache;
		}

		if ($this->isAllAccess()) {
			$aliases = new MultiInboundEmailAlias(array('deleted' => false));
			$aliases->load();
			$ids = array();
			foreach ($aliases as $a) {
				$ids[] = intval($a->key);
			}
			$this->accessible_cache = $ids;
			return $ids;
		}

		if ($this->user_id <= 0) {
			$this->accessible_cache = array();
			return array();
		}

		// Granted alias ids, minus any that have since been soft-deleted — a
		// stale grant to a removed mailbox must not grant access. (The platform
		// cascades grant cleanup on user delete via deletion rules; alias delete
		// is normally a soft delete, so this filter is the authoritative guard.)
		$granted = InboundEmailMailboxGrant::alias_ids_for_user($this->user_id);
		$this->accessible_cache = $this->filterDeletedAliases($granted);
		return $this->accessible_cache;
	}

	/** Keep only alias ids that exist and are not soft-deleted. */
	private function filterDeletedAliases(array $alias_ids): array {
		$alias_ids = array_values(array_unique(array_map('intval', $alias_ids)));
		if (!count($alias_ids)) {
			return array();
		}
		$in = implode(',', $alias_ids);
		$db = DbConnector::get_instance()->get_db_link();
		$rows = $db->query(
			"SELECT iea_inbound_email_alias_id FROM iea_inbound_email_aliases
			 WHERE iea_inbound_email_alias_id IN ($in) AND iea_delete_time IS NULL"
		)->fetchAll(PDO::FETCH_COLUMN);
		$live = array();
		foreach ($rows as $id) { $live[] = intval($id); }
		return $live;
	}

	public function canAccess(int $aliasId): bool {
		return in_array($aliasId, $this->accessibleAliasIds(), true);
	}

	/**
	 * The single chokepoint where audience becomes a query filter.
	 *
	 *   - $aliasId given and accessible → [$aliasId]
	 *   - $aliasId given but NOT accessible → [] (matches nothing — the service
	 *     treats this as "no messages", indistinguishable from an empty mailbox)
	 *   - $aliasId null → the full accessible set (the merged "All mail" view)
	 *
	 * Note the superadmin "All mail" unconstrained case (null + isAllAccess) is
	 * handled in MailboxService, not here, so this method only ever returns a
	 * plain array of ids.
	 *
	 * @return int[]
	 */
	public function scopeAliasIds(?int $aliasId): array {
		if ($aliasId !== null) {
			return $this->canAccess($aliasId) ? array($aliasId) : array();
		}
		return $this->accessibleAliasIds();
	}

	/**
	 * A grant means full access to the mailbox — reading it and sending as
	 * it (the scoping MailboxSender enforces per-alias). So a viewer may
	 * compose exactly when they can access at least one mailbox.
	 */
	public function canCompose(): bool {
		return count($this->accessibleAliasIds()) > 0;
	}

	public function getUserId(): int {
		return $this->user_id;
	}

	public function getPermission(): int {
		return $this->permission;
	}
}
?>
