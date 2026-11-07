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
 * member page at /profile/mailbox/mailbox — sit on this one seam.
 *
 * @version 1.4 - all-access is deliberate (mail_access_hardening WP3): a level-10 viewer is all-access only
 *               with a second factor on the account and a step-up marker younger than
 *               mailbox_oversight_stepup_minutes on the browser session; withOversight() / fromSession()
 *               alone decide it, forUser() never does; oversightState() says why not
 * @version 1.3 - holdsMailbox() / holdsMessage(): the owner rule of the mailbox's rows
 *               (InboundEmailMessage, its labels, filters, attachments and folders), so a
 *               grant holder below the model's level may change their own mailbox's rows
 * @version 1.2 - canReadMessage(): the one rule for "may this viewer read this stored message"
 * @version 1.1
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grants_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_aliases_class.php'));

final class MailboxViewer {

	/** @var int */
	private $user_id;
	/** @var int */
	private $permission;
	/** @var int[]|null lazily-resolved accessible alias ids */
	private $accessible_cache = null;
	/**
	 * @var string Why this viewer is, or is not, all-access (the OVERSIGHT_* words).
	 * Decided once, by withOversight(): a viewer built without a session has no
	 * marker to show and is never all-access, whatever its level.
	 */
	private $oversight = self::OVERSIGHT_NONE;

	// Oversight: a level-10 account reading every mailbox on the site (the merged
	// All mail and Unmatched views included) is deliberate, not automatic
	// (mail_access_hardening WP3). It needs a second factor on the account and a
	// step-up marker on this browser session younger than
	// mailbox_oversight_stepup_minutes (1 to 60; the marker itself lives one hour,
	// PasskeyService::STEPUP_MARKER_TTL_SECONDS, so 60 is the ceiling). Without
	// them the account reads like any grant holder: its own mailboxes.
	const OVERSIGHT_CONFIRMED          = 'confirmed';            // all-access
	const OVERSIGHT_NEEDS_STEPUP       = 'needs_stepup';         // level 10 with a factor, no fresh marker
	const OVERSIGHT_NEEDS_SECOND_FACTOR = 'needs_second_factor'; // level 10 with no factor to confirm with
	const OVERSIGHT_NONE               = 'none';                 // below level 10, or no session

	const OVERSIGHT_DEFAULT_MINUTES = 60;
	const OVERSIGHT_MAX_MINUTES     = 60;

	private function __construct(int $user_id, int $permission) {
		$this->user_id = $user_id;
		$this->permission = $permission;
	}

	public static function fromSession(SessionControl $session): MailboxViewer {
		return self::withOversight(
			intval($session->get_user_id()),
			intval($session->get_permission()),
			(string)session_id()
		);
	}

	/**
	 * A viewer for a person on a browser session, which is the one place
	 * all-access can be decided: the session is what carries the step-up
	 * marker. fromSession() calls this with the live session id; a test calls
	 * it with a session id it stamped itself.
	 */
	public static function withOversight(int $user_id, int $permission, string $session_id): MailboxViewer {
		$viewer = new MailboxViewer($user_id, $permission);
		$viewer->oversight = self::decideOversight($user_id, $permission, $session_id);
		return $viewer;
	}

	/**
	 * Build a viewer for an explicit user/permission, independent of the current
	 * session: the deferred member-mount, a mail program's grant-only viewer, a
	 * board or recipe acting for a person, and tests. It has no session to show
	 * a marker from, so it is never all-access: a level-10 person read this way
	 * reads the mailboxes they hold, which is what acting for someone should mean.
	 */
	public static function forUser(int $user_id, int $permission): MailboxViewer {
		return new MailboxViewer($user_id, $permission);
	}

	/**
	 * Tests only: a viewer whose oversight is confirmed without a session or a
	 * marker, for the suites that exercise all-access reads. Refused outside the
	 * test harness, so there is no production path to an unconfirmed all-access.
	 */
	public static function confirmedForTest(int $user_id, int $permission): MailboxViewer {
		if (!defined('JOINERY_HARNESS_LOADED')) {
			throw new LogicException('MailboxViewer::confirmedForTest() is for the test harness alone.');
		}
		$viewer = new MailboxViewer($user_id, $permission);
		$viewer->oversight = Access::allows(10, $permission) ? self::OVERSIGHT_CONFIRMED : self::OVERSIGHT_NONE;
		return $viewer;
	}

	/** Why this viewer is, or is not, all-access: one of the OVERSIGHT_* words. */
	public function oversightState(): string {
		return $this->oversight;
	}

	/** The configured oversight window, clamped to 1 to OVERSIGHT_MAX_MINUTES. */
	public static function oversightMinutes(): int {
		try {
			$v = Globalvars::get_instance()->get_setting('mailbox_oversight_stepup_minutes');
		} catch (\Throwable $e) {
			$v = null;
		}
		$minutes = ($v === null || $v === '') ? self::OVERSIGHT_DEFAULT_MINUTES : intval($v);
		return max(1, min(self::OVERSIGHT_MAX_MINUTES, $minutes));
	}

	private static function decideOversight(int $user_id, int $permission, string $session_id): string {
		if ($user_id <= 0 || !Access::allows(10, $permission)) {
			return self::OVERSIGHT_NONE;
		}
		try {
			$user = new User($user_id, TRUE);
			if (!$user->key || !SessionControl::get_instance()->user_has_second_factor($user)) {
				return self::OVERSIGHT_NEEDS_SECOND_FACTOR;
			}
			if ($session_id === '') {
				return self::OVERSIGHT_NEEDS_STEPUP;
			}
			// The same marker SessionControl::has_recent_second_factor() reads
			// (stamp_second_factor(), PasskeyService::verifyStepUp()), keyed by the
			// browser session, read for this session id rather than the live one.
			$q = DbConnector::get_instance()->get_db_link()->prepare(
				"SELECT 1 FROM pks_passkey_ceremonies WHERE pks_session_id = ? AND pks_kind = 'stepup'
				 AND pks_create_time > ? LIMIT 1");
			$q->execute(array($session_id, gmdate('Y-m-d H:i:s', time() - self::oversightMinutes() * 60)));
			return $q->fetchColumn() ? self::OVERSIGHT_CONFIRMED : self::OVERSIGHT_NEEDS_STEPUP;
		} catch (\Throwable $e) {
			// A question that cannot be answered is answered no: oversight is
			// the exception, and a failure must never widen a viewer's scope.
			error_log('MailboxViewer: could not decide oversight for user ' . $user_id . ' — ' . $e->getMessage());
			return self::OVERSIGHT_NEEDS_STEPUP;
		}
	}

	/**
	 * Superadmin oversight: sees every mailbox plus the merged "All mail" /
	 * "Unmatched" views without needing a grant per address. Level 10 AND
	 * confirmed on this session (see the OVERSIGHT_* words above): the check
	 * lives here, not in the callers, so no surface can forget it.
	 */
	public function isAllAccess(): bool {
		return Access::allows(10, (int)$this->permission) && $this->oversight === self::OVERSIGHT_CONFIRMED;
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
	 * May this viewer read this stored message? A viewer with a grant on the
	 * message's mailbox may; a message that belongs to no mailbox (a NULL alias: the
	 * catch-all, unmatched mail) is readable only by an all-access viewer. The one
	 * place this rule lives: the reader's export and every plugin page that opens a
	 * message by id (a reader action's target) call it rather than re-deriving it.
	 */
	public function canReadMessage(InboundEmailMessage $message): bool {
		$alias_id = intval($message->get('iem_iea_inbound_email_alias_id'));
		return $alias_id > 0 ? $this->canAccess($alias_id) : $this->isAllAccess();
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

	/** Mailboxes each user was found to hold this request: user id => alias id => true. */
	private static $held = array();

	/**
	 * Does $user_id hold a grant on mailbox $alias_id (a live alias)? This is the
	 * owner rule of every row that belongs to a mailbox (a message, a label, a
	 * filter, an attachment, a feed folder: their access_owner_is()), so a grant
	 * holder below the model's level may change their own mailbox's rows, as the
	 * reader, the composer and the filters page already let them. Only a holding
	 * is remembered for the request; a miss asks again, so a grant made earlier
	 * in the same request counts.
	 */
	public static function holdsMailbox(int $user_id, int $alias_id): bool {
		if ($user_id <= 0 || $alias_id <= 0) {
			return false;
		}
		if (isset(self::$held[$user_id][$alias_id])) {
			return true;
		}
		if (!self::forUser($user_id, 0)->canAccess($alias_id)) {
			return false;
		}
		self::$held[$user_id][$alias_id] = true;
		return true;
	}

	/**
	 * Does $user_id hold the stored message $message_id: a grant on its mailbox,
	 * or being the owner whose vault it is sealed to (who alone can open it, and
	 * whose unlocked browser and window parse, seal and sort it); and for a
	 * draft, being its author (a draft is its author's alone, never a
	 * co-grantee's)? Read straight from the row, so no sealed column is touched.
	 */
	public static function holdsMessage(int $user_id, int $message_id): bool {
		if ($user_id <= 0 || $message_id <= 0) {
			return false;
		}
		$q = DbConnector::get_instance()->get_db_link()->prepare(
			'SELECT iem_iea_inbound_email_alias_id, iem_direction, iem_draft_author_user_id, iem_sealed_owner_user_id
			 FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ?');
		$q->execute(array($message_id));
		$row = $q->fetch(PDO::FETCH_ASSOC);
		if (!$row) {
			return false;
		}
		return self::holdsMessageRow($user_id, (int)$row['iem_iea_inbound_email_alias_id'],
			(string)$row['iem_direction'], (int)$row['iem_draft_author_user_id'], (int)$row['iem_sealed_owner_user_id']);
	}

	/** holdsMessage() over values already in hand (the message model's own stored columns). */
	public static function holdsMessageRow(int $user_id, int $alias_id, string $direction, int $draft_author,
			int $sealed_owner = 0): bool {
		if ($user_id <= 0) {
			return false;
		}
		if ($direction === 'draft') {
			return $draft_author === $user_id && self::holdsMailbox($user_id, $alias_id);
		}
		return $sealed_owner === $user_id || self::holdsMailbox($user_id, $alias_id);
	}

	public function getUserId(): int {
		return $this->user_id;
	}

	public function getPermission(): int {
		return $this->permission;
	}
}
?>
