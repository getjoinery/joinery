<?php
/**
 * Boards — the shared middle of the Boards plugin.
 *
 * Every Boards action asks the same questions before it does anything (is the
 * feature on, who is asking, what role do they have on this board), and every
 * ordered thing — lists, cards, labels, checklists, checklist items — is placed
 * the same way. Both live here so the answers cannot drift between actions.
 *
 * ACCESS. There is one rule and one function: roleFor() returns 'admin',
 * 'editor', 'viewer' or null. A null role means the board does not exist as far
 * as that person is concerned — the same refusal as "no such board", so a board's
 * name never leaks to someone who cannot open it. See docs/overview.md § Access.
 *
 * ORDER. Positions are fractions: a drop between two neighbours takes the
 * midpoint, so one drag changes one row. They are stored as numeric(20,6) (the
 * platform has no float column), so when the gap between two neighbours falls
 * below MIN_POSITION_GAP the whole list is renumbered 1024, 2048, … in the same
 * transaction.
 *
 * @version 1.0.1
 * @changelog 1.0.1 - deleteFile() runs under rule_checked_write() behind its callers' board role
 */

/** A refusal with a message the member can read. */
class BoardsRefusal extends Exception {}

class Boards {

	const ROLE_ADMIN = 'admin';
	const ROLE_EDITOR = 'editor';
	const ROLE_VIEWER = 'viewer';

	const ROLE_RANK = array('viewer' => 1, 'editor' => 2, 'admin' => 3);

	/** A superadmin reaches every board, so abandoned ones can be cleaned up. */
	const SUPERADMIN_PERMISSION = 10;

	const POSITION_STEP = 1024;

	/**
	 * Below this gap between two neighbours a midpoint cannot be told apart from
	 * either at six decimals — renumber first. Ten times the stored resolution,
	 * so a midpoint is always strictly between its neighbours.
	 */
	const MIN_POSITION_GAP = 0.00001;

	/** Where the ordered things live. */
	private static $ordered = array(
		'list' => array(
			'table' => 'bls_lists', 'pk' => 'bls_list_id', 'pos' => 'bls_position',
			'parent' => 'bls_bbd_board_id', 'live' => 'bls_archived_time IS NULL'),
		'card' => array(
			'table' => 'bcd_cards', 'pk' => 'bcd_card_id', 'pos' => 'bcd_position',
			'parent' => 'bcd_bls_list_id', 'live' => 'bcd_delete_time IS NULL AND bcd_archived_time IS NULL'),
		'label' => array(
			'table' => 'blb_labels', 'pk' => 'blb_label_id', 'pos' => 'blb_position',
			'parent' => 'blb_bbd_board_id', 'live' => 'TRUE'),
		'checklist' => array(
			'table' => 'bck_checklists', 'pk' => 'bck_checklist_id', 'pos' => 'bck_position',
			'parent' => 'bck_bcd_card_id', 'live' => 'TRUE'),
		'item' => array(
			'table' => 'bci_checklist_items', 'pk' => 'bci_checklist_item_id', 'pos' => 'bci_position',
			'parent' => 'bci_bck_checklist_id', 'live' => 'TRUE'),
	);

	/** @var array<string,?string> per-request role cache, "board:user" => role */
	private static $roles = array();

	/** @var array<int,int> per-request permission cache */
	private static $permissions = array();

	/** @var array<int,int[]> per-request cache of a user's user-category groups */
	private static $groups = array();

	// ------------------------------------------------------------------
	// Gates
	// ------------------------------------------------------------------

	/**
	 * The signed-in member's id, or a refusal.
	 *
	 * @throws BoardsRefusal when nobody is signed in or Boards is switched off
	 */
	public static function requireMember(): int {
		$session = SessionControl::get_instance();
		$user_id = (int)$session->get_user_id();
		if (!$user_id) {
			throw new BoardsRefusal('Sign in required.');
		}
		if (!Globalvars::get_instance()->get_setting('boards_active', true, true)) {
			throw new BoardsRefusal('Boards is turned off on this site.');
		}
		return $user_id;
	}

	// ------------------------------------------------------------------
	// Settings
	// ------------------------------------------------------------------

	/**
	 * One integer knob, with its factory default when unset or blank.
	 * get_setting has no default parameter, so the fallback lives here and must
	 * match the default declared in plugin.json.
	 */
	public static function settingInt(string $name, int $default): int {
		$value = (int)Globalvars::get_instance()->get_setting($name, true, true);
		return $value > 0 ? $value : $default;
	}

	/** The knobs the browser needs. */
	public static function clientSettings(): array {
		return array(
			'poll_ms'            => self::settingInt('boards_poll_seconds', 5) * 1000,
			'max_attachment_mb'  => self::settingInt('boards_max_attachment_mb', 25),
		);
	}

	// ------------------------------------------------------------------
	// Access
	// ------------------------------------------------------------------

	/** A person's site permission level (0 for a member, 10 for a superadmin). */
	public static function userPermission(int $user_id): int {
		if (!isset(self::$permissions[$user_id])) {
			$permission = 0;
			if ($user_id > 0 && User::check_if_exists($user_id)) {
				$user = new User($user_id, TRUE);
				$permission = (int)$user->get('usr_permission');
			}
			self::$permissions[$user_id] = $permission;
		}
		return self::$permissions[$user_id];
	}

	/**
	 * The user-category groups a person is in, right now. Membership is read live
	 * on every request, so joining or leaving a group changes board access at
	 * once. Other categories (event groups, mailing lists) never count.
	 *
	 * @return int[]
	 */
	public static function userGroupIds(int $user_id): array {
		if (!isset(self::$groups[$user_id])) {
			$q = DbConnector::GetPreparedStatement(
				"SELECT grm.grm_grp_group_id FROM grm_group_members grm
				 JOIN grp_groups grp ON grp.grp_group_id = grm.grm_grp_group_id
				 WHERE grm.grm_foreign_key_id = ? AND grp.grp_category = 'user' AND grp.grp_delete_time IS NULL");
			$q->execute(array($user_id));
			self::$groups[$user_id] = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
		}
		return self::$groups[$user_id];
	}

	/** Forget cached roles, permissions and groups (after membership changes; tests). */
	public static function forgetRoles(): void {
		self::$roles = array();
		self::$permissions = array();
		self::$groups = array();
	}

	/**
	 * What this person may do on this board: 'admin', 'editor', 'viewer' or null.
	 *
	 * Resolution order:
	 *  1. Superadmins (permission 10) are admin on every board. Other site admins
	 *     (5–9) get no special access — they see a board only through the steps
	 *     below, like any member.
	 *  2. A direct row for the person.
	 *  3. The highest role among the person's groups on this board.
	 *  4. On an Open to all members board, any signed-in member is a viewer.
	 *  5. Otherwise null.
	 *
	 * A deleted board has no roles for anyone.
	 */
	public static function roleFor(Board $board, int $user_id): ?string {
		if ($user_id <= 0 || !$board->key || $board->get('bbd_delete_time')) {
			return null;
		}
		$cache_key = $board->key . ':' . $user_id;
		if (array_key_exists($cache_key, self::$roles)) {
			return self::$roles[$cache_key];
		}

		$role = null;
		if (Access::allows(self::SUPERADMIN_PERMISSION, (int)self::userPermission($user_id))) {
			$role = self::ROLE_ADMIN;
		} else {
			$q = DbConnector::GetPreparedStatement(
				'SELECT bbm_role FROM bbm_board_members WHERE bbm_bbd_board_id = ? AND bbm_usr_user_id = ?');
			$q->execute(array((int)$board->key, $user_id));
			$direct = $q->fetchColumn();
			if ($direct !== false) {
				$role = $direct;
			} else {
				$groups = self::userGroupIds($user_id);
				if ($groups) {
					$q = DbConnector::GetPreparedStatement(
						'SELECT bbm_role FROM bbm_board_members WHERE bbm_bbd_board_id = ? AND bbm_grp_group_id IN ('
						. implode(',', $groups) . ')');
					$q->execute(array((int)$board->key));
					foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $group_role) {
						if (self::roleRank($group_role) > self::roleRank($role)) {
							$role = $group_role;
						}
					}
				}
				if ($role === null && $board->get('bbd_visibility') === Board::VISIBILITY_ALL_MEMBERS
					&& User::check_if_exists($user_id)) {
					$role = self::ROLE_VIEWER;
				}
			}
		}
		return self::$roles[$cache_key] = $role;
	}

	/** 0 for no role, 1–3 for viewer–admin. */
	public static function roleRank(?string $role): int {
		return $role !== null && isset(self::ROLE_RANK[$role]) ? self::ROLE_RANK[$role] : 0;
	}

	/** Does this role satisfy the minimum? */
	public static function roleAtLeast(?string $role, string $minimum): bool {
		return self::roleRank($role) >= self::roleRank($minimum);
	}

	/**
	 * Refuse unless the person has at least $minimum on the board.
	 *
	 * "No role" and "no such board" are the same answer. An archived board is
	 * read-only: changing anything on it (other than restoring it) needs it
	 * restored first.
	 *
	 * @throws BoardsRefusal
	 */
	public static function requireRole(Board $board, int $user_id, string $minimum, bool $allow_archived = false): string {
		$role = self::roleFor($board, $user_id);
		if ($role === null) {
			throw new BoardsRefusal('Board not found.');
		}
		if (!self::roleAtLeast($role, $minimum)) {
			throw new BoardsRefusal($minimum === self::ROLE_ADMIN
				? 'Only a board admin can do that.'
				: 'You can view this board but not change it.');
		}
		if ($minimum !== self::ROLE_VIEWER && !$allow_archived && $board->get('bbd_archived_time')) {
			throw new BoardsRefusal('This board is archived. Restore it to make changes.');
		}
		return $role;
	}

	/**
	 * Load a board the person may use at $minimum.
	 *
	 * @throws BoardsRefusal
	 */
	public static function boardFor($board_id, int $user_id, string $minimum = 'viewer', bool $allow_archived = false): Board {
		$board_id = (int)$board_id;
		if ($board_id <= 0 || !Board::check_if_exists($board_id)) {
			throw new BoardsRefusal('Board not found.');
		}
		$board = new Board($board_id, TRUE);
		if ($board->get('bbd_delete_time')) {
			throw new BoardsRefusal('Board not found.');
		}
		self::requireRole($board, $user_id, $minimum, $allow_archived);
		return $board;
	}

	/**
	 * Load a card, and its board, for a person who may use it at $minimum.
	 * An archived card is read-only until it is restored.
	 *
	 * @return array{0:BoardCard,1:Board}
	 * @throws BoardsRefusal
	 */
	public static function cardFor($card_id, int $user_id, string $minimum = 'viewer', bool $allow_archived = false): array {
		$card_id = (int)$card_id;
		if ($card_id <= 0 || !BoardCard::check_if_exists($card_id)) {
			throw new BoardsRefusal('Card not found.');
		}
		$card = new BoardCard($card_id, TRUE);
		if ($card->get('bcd_delete_time')) {
			throw new BoardsRefusal('Card not found.');
		}
		// $allow_archived is about the CARD (restoring it); an archived BOARD stays
		// read-only for everything, so a card on one cannot be restored or edited.
		$board = self::boardFor($card->get('bcd_bbd_board_id'), $user_id, $minimum, false);
		if ($minimum !== self::ROLE_VIEWER && !$allow_archived && $card->get('bcd_archived_time')) {
			throw new BoardsRefusal('This card is archived. Restore it to make changes.');
		}
		return array($card, $board);
	}

	/**
	 * The model-layer form of requireRole(): throws SystemAuthenticationError, the
	 * way a model's authenticate_read/write must, so the API and AI surfaces
	 * cannot reach a row the board role does not allow.
	 *
	 * @param array $data the acting identity: current_user_id (and permission)
	 * @throws SystemAuthenticationError
	 */
	public static function assertRoleForBoardId(int $board_id, array $data, string $minimum): void {
		$user_id = (int)($data['current_user_id'] ?? 0);
		try {
			$board = new Board($board_id, TRUE);
			if (!$board->key) {
				throw new BoardsRefusal('Board not found.');
			}
			// Models do not apply the archived rule: restoring is a write on an
			// archived board, and that is the action layer's call.
			$role = self::roleFor($board, $user_id);
			if ($role === null || !self::roleAtLeast($role, $minimum)) {
				throw new BoardsRefusal('No access.');
			}
		} catch (BoardsRefusal $e) {
			throw new SystemAuthenticationError('Current user does not have access to this board.');
		} catch (SystemBaseException $e) {
			throw new SystemAuthenticationError('Current user does not have access to this board.');
		}
	}

	/** The board a card is on, without loading the card. */
	public static function boardIdForCard(int $card_id): int {
		$q = DbConnector::GetPreparedStatement('SELECT bcd_bbd_board_id FROM bcd_cards WHERE bcd_card_id = ?');
		$q->execute(array($card_id));
		return (int)$q->fetchColumn();
	}

	/** The board a checklist is on, without loading it. */
	public static function boardIdForChecklist(int $checklist_id): int {
		$q = DbConnector::GetPreparedStatement(
			'SELECT bcd.bcd_bbd_board_id FROM bck_checklists bck
			 JOIN bcd_cards bcd ON bcd.bcd_card_id = bck.bck_bcd_card_id WHERE bck.bck_checklist_id = ?');
		$q->execute(array($checklist_id));
		return (int)$q->fetchColumn();
	}

	/**
	 * Everyone with an explicit place on the board: the owner, direct members and
	 * the current members of member groups. This is the list the @mention picker
	 * and the "add to card" picker draw from; an Open to all members board lets a
	 * card name anyone who can open the board (see canSeeBoard()).
	 *
	 * @return array<int,string> user id => role (the highest the person has)
	 */
	public static function explicitMembers(Board $board): array {
		$out = array();
		$q = DbConnector::GetPreparedStatement(
			'SELECT bbm_usr_user_id, bbm_role FROM bbm_board_members WHERE bbm_bbd_board_id = ? AND bbm_usr_user_id IS NOT NULL');
		$q->execute(array((int)$board->key));
		foreach ($q->fetchAll(PDO::FETCH_NUM) as $row) {
			$out[(int)$row[0]] = $row[1];
		}

		// Group rows: every current member of each group, at the group's role —
		// unless the person has a direct row, which wins.
		$q = DbConnector::GetPreparedStatement(
			"SELECT bbm.bbm_role, grm.grm_foreign_key_id
			 FROM bbm_board_members bbm
			 JOIN grp_groups grp ON grp.grp_group_id = bbm.bbm_grp_group_id
			 JOIN grm_group_members grm ON grm.grm_grp_group_id = grp.grp_group_id
			 WHERE bbm.bbm_bbd_board_id = ? AND grp.grp_category = 'user' AND grp.grp_delete_time IS NULL");
		$q->execute(array((int)$board->key));
		$direct = $out;
		foreach ($q->fetchAll(PDO::FETCH_NUM) as $row) {
			$uid = (int)$row[1];
			if (isset($direct[$uid])) {
				continue;
			}
			if (self::roleRank($row[0]) > self::roleRank($out[$uid] ?? null)) {
				$out[$uid] = $row[0];
			}
		}

		$owner = (int)$board->get('bbd_usr_user_id_owner');
		if ($owner && !isset($out[$owner]) && !User::is_placeholder($owner)) {
			$out[$owner] = self::ROLE_ADMIN;
		}
		return $out;
	}

	/** Can this person open the board at all? (A cheap form of roleFor() !== null.) */
	public static function canSeeBoard(Board $board, int $user_id): bool {
		return self::roleFor($board, $user_id) !== null;
	}

	/**
	 * Take away board-level watches from people who can no longer see the board.
	 * Run after anything that can remove access: removing a member or group,
	 * lowering a board from Open to all members to Invite only, or a person
	 * leaving a group.
	 */
	public static function pruneWatchers(Board $board): int {
		$removed = 0;
		$q = DbConnector::GetPreparedStatement('SELECT bbw_board_watcher_id, bbw_usr_user_id FROM bbw_board_watchers WHERE bbw_bbd_board_id = ?');
		$q->execute(array((int)$board->key));
		foreach ($q->fetchAll(PDO::FETCH_NUM) as $row) {
			if (self::roleFor($board, (int)$row[1]) === null) {
				$d = DbConnector::GetPreparedStatement('DELETE FROM bbw_board_watchers WHERE bbw_board_watcher_id = ?');
				$d->execute(array((int)$row[0]));
				$removed++;
			}
		}
		return $removed;
	}

	// ------------------------------------------------------------------
	// Transactions
	// ------------------------------------------------------------------

	/**
	 * Run $work in a transaction (joining the caller's if there is one). A throw
	 * rolls back what this call began and is re-thrown.
	 *
	 * @return mixed whatever $work returns
	 */
	public static function transaction(callable $work) {
		$db = DbConnector::get_instance()->get_db_link();
		$own = !$db->inTransaction();
		if ($own) {
			$db->beginTransaction();
		}
		try {
			$result = $work();
			if ($own) {
				$db->commit();
			}
			return $result;
		} catch (Throwable $e) {
			if ($own && $db->inTransaction()) {
				$db->rollBack();
			}
			throw $e;
		}
	}

	/**
	 * Lock a row for the rest of the transaction and re-read it.
	 *
	 * The copy was loaded when the request began, and a change decided from it
	 * (a position worked out from the old order) is only right if nothing moved
	 * the row since. Holding the row and re-reading it makes a second change a
	 * moment later work from the first one's result. Call it first thing inside
	 * the transaction that changes the row.
	 */
	public static function lockAndReload(SystemBase $model): void {
		$class = get_class($model);
		$q = DbConnector::get_instance()->get_db_link()->prepare(
			'SELECT 1 FROM ' . $class::$tablename . ' WHERE ' . $class::$pkey_column . ' = ? FOR UPDATE');
		$q->execute(array($model->key));
		$model->load();
	}

	/**
	 * Take the activity locks of several boards in one fixed order, so two
	 * changes that each touch the same pair of boards (cards moved in opposite
	 * directions) cannot wait on each other.
	 *
	 * @param int[] $board_ids
	 */
	public static function lockBoards(array $board_ids): void {
		$board_ids = array_unique(array_map('intval', $board_ids));
		sort($board_ids);
		$lock = DbConnector::get_instance()->get_db_link()->prepare('SELECT pg_advisory_xact_lock(?)');
		foreach ($board_ids as $board_id) {
			$lock->execute(array(crc32('boards_activity_' . $board_id)));
		}
	}

	// ------------------------------------------------------------------
	// Order
	// ------------------------------------------------------------------

	/**
	 * Where to put an item so it lands between two neighbours.
	 *
	 * The neighbours are the item above ($prev_id) and the item below ($next_id)
	 * in the list the item is going to; either may be null (the start or the end).
	 * Positions are read from the database, not trusted from the browser, so a
	 * stale drag still lands next to the right card. When only one neighbour is
	 * given the other is whatever is actually beside it; when neither is, the item
	 * goes to the end.
	 *
	 * @param string   $kind      list | card | label | checklist | item
	 * @param int      $parent_id the board / list / card / checklist the item sits in
	 * @param int|null $moving_id the item being placed (excluded from the neighbours)
	 * @return float
	 */
	public static function computePosition(string $kind, int $parent_id, ?int $prev_id = null, ?int $next_id = null, ?int $moving_id = null): float {
		$cfg = self::$ordered[$kind];

		for ($attempt = 0; $attempt < 2; $attempt++) {
			$before = $prev_id ? self::positionOf($cfg, $parent_id, $prev_id, $moving_id) : null;
			$after = $next_id ? self::positionOf($cfg, $parent_id, $next_id, $moving_id) : null;

			if ($before === null && $after === null) {
				return self::endPosition($cfg, $parent_id, $moving_id);
			}
			if ($before !== null && $after === null) {
				$after = self::neighbour($cfg, $parent_id, $before, true, $moving_id);
			} elseif ($before === null) {
				$before = self::neighbour($cfg, $parent_id, $after, false, $moving_id);
			}

			if ($before === null) {
				return round($after - self::POSITION_STEP, 6);
			}
			if ($after === null) {
				return round($before + self::POSITION_STEP, 6);
			}
			if (abs($after - $before) >= self::MIN_POSITION_GAP * 2) {
				return round(($before + $after) / 2, 6);
			}
			// No room between them. Renumber the whole list and look again.
			self::renumber($kind, $parent_id);
		}
		// Renumbering opens a 1024 gap, so this is unreachable unless two
		// neighbours share an id; the end is a safe answer.
		return self::endPosition($cfg, $parent_id, $moving_id);
	}

	private static function positionOf(array $cfg, int $parent_id, int $id, ?int $moving_id): ?float {
		if ($moving_id !== null && $id === $moving_id) {
			return null;
		}
		$q = DbConnector::GetPreparedStatement(
			"SELECT {$cfg['pos']} FROM {$cfg['table']} WHERE {$cfg['pk']} = ? AND {$cfg['parent']} = ? AND {$cfg['live']}");
		$q->execute(array($id, $parent_id));
		$value = $q->fetchColumn();
		return $value === false ? null : (float)$value;
	}

	/** The position of the nearest item above/below $position, or null. */
	private static function neighbour(array $cfg, int $parent_id, float $position, bool $above, ?int $moving_id): ?float {
		$sql = "SELECT {$cfg['pos']} FROM {$cfg['table']} WHERE {$cfg['parent']} = ? AND {$cfg['live']}"
			. " AND {$cfg['pos']} " . ($above ? '>' : '<') . ' ?'
			. ($moving_id !== null ? " AND {$cfg['pk']} <> " . (int)$moving_id : '')
			. " ORDER BY {$cfg['pos']} " . ($above ? 'ASC' : 'DESC') . ' LIMIT 1';
		$q = DbConnector::GetPreparedStatement($sql);
		$q->execute(array($parent_id, $position));
		$value = $q->fetchColumn();
		return $value === false ? null : (float)$value;
	}

	private static function endPosition(array $cfg, int $parent_id, ?int $moving_id): float {
		$sql = "SELECT MAX({$cfg['pos']}) FROM {$cfg['table']} WHERE {$cfg['parent']} = ?"
			. ($moving_id !== null ? " AND {$cfg['pk']} <> " . (int)$moving_id : '');
		$q = DbConnector::GetPreparedStatement($sql);
		$q->execute(array($parent_id));
		$max = $q->fetchColumn();
		return $max === null || $max === false ? (float)self::POSITION_STEP : round((float)$max + self::POSITION_STEP, 6);
	}

	/**
	 * Respace one list 1024, 2048, … in its current order, in one statement.
	 * Archived rows are renumbered too: they keep their place for a restore.
	 */
	public static function renumber(string $kind, int $parent_id): void {
		$cfg = self::$ordered[$kind];
		if ($kind === 'card') {
			// Every card on the list now has a new position, but only one card is
			// about to be saved. Tell the poll to resend the whole list.
			$b = DbConnector::GetPreparedStatement('SELECT bls_bbd_board_id FROM bls_lists WHERE bls_list_id = ?');
			$b->execute(array($parent_id));
			$board_id = (int)$b->fetchColumn();
			if ($board_id) {
				self::log($board_id, null, null, 'list.moved', array('quiet' => true, 'renumbered_list' => $parent_id));
			}
		}
		$q = DbConnector::GetPreparedStatement(
			"UPDATE {$cfg['table']} AS t SET {$cfg['pos']} = ranked.rn * " . self::POSITION_STEP . "
			 FROM (SELECT {$cfg['pk']} AS id, row_number() OVER (ORDER BY {$cfg['pos']}, {$cfg['pk']}) AS rn
			       FROM {$cfg['table']} WHERE {$cfg['parent']} = ?) AS ranked
			 WHERE t.{$cfg['pk']} = ranked.id");
		$q->execute(array($parent_id));
	}

	// ------------------------------------------------------------------
	// Activity
	// ------------------------------------------------------------------

	/**
	 * Record one thing that happened, and return its id.
	 *
	 * The activity ids are the poll cursor, so they must become visible in the
	 * order they were taken or a poll could skip a row that commits late. A
	 * transaction-scoped advisory lock per board, held until commit, serialises
	 * the inserts for one board.
	 *
	 * @param array $data what the verb needs to read back later, as it was now
	 */
	public static function log(int $board_id, ?int $card_id, ?int $user_id, string $verb, array $data = array()): int {
		return self::transaction(function () use ($board_id, $card_id, $user_id, $verb, $data) {
			$db = DbConnector::get_instance()->get_db_link();
			$lock = $db->prepare('SELECT pg_advisory_xact_lock(?)');
			$lock->execute(array(crc32('boards_activity_' . $board_id)));
			$q = $db->prepare(
				'INSERT INTO bac_activity (bac_bbd_board_id, bac_bcd_card_id, bac_usr_user_id, bac_verb, bac_data)
				 VALUES (?, ?, ?, ?, ?::jsonb) RETURNING bac_activity_id');
			$q->execute(array(
				$board_id, $card_id, $user_id, $verb,
				$data ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
			));
			return (int)$q->fetchColumn();
		});
	}

	/** The newest activity id on a board (0 if none) — the poll cursor a fresh load starts from. */
	public static function latestActivityId(int $board_id): int {
		$q = DbConnector::GetPreparedStatement('SELECT COALESCE(MAX(bac_activity_id), 0) FROM bac_activity WHERE bac_bbd_board_id = ?');
		$q->execute(array($board_id));
		return (int)$q->fetchColumn();
	}

	// ------------------------------------------------------------------
	// Files
	// ------------------------------------------------------------------

	/** The File id held in a board background ('file:123'), or 0. */
	public static function backgroundFileId(string $background): int {
		return preg_match('/^file:(\d+)$/', $background, $m) ? (int)$m[1] : 0;
	}

	/** Remove a File and its bytes. A file that is already gone is not an error. */
	public static function deleteFile(int $file_id): void {
		if ($file_id <= 0 || !File::check_if_exists($file_id)) {
			return;
		}
		try {
			$file = new File($file_id, TRUE);
			// Every caller holds the board role for the change that drops the
			// file (a board admin's new background, an editor's removed
			// attachment, a refused upload, the row's own delete).
			SystemBase::rule_checked_write(function () use ($file) { $file->permanent_delete(); });
		} catch (Throwable $e) {
			error_log('[Boards] could not remove file ' . $file_id . ': ' . $e->getMessage());
		}
	}

	// ------------------------------------------------------------------
	// People
	// ------------------------------------------------------------------

	/** @var array<int,array> */
	private static $people = array();

	/** Name and picture for one person, cached for the request. */
	public static function person($user_id): array {
		$user_id = (int)$user_id;
		if (isset(self::$people[$user_id])) {
			return self::$people[$user_id];
		}
		$out = array('id' => $user_id, 'name' => 'Unknown', 'avatar' => '/assets/images/blank-avatar.png');
		try {
			if (User::check_if_exists($user_id)) {
				$user = new User($user_id, TRUE);
				$out['name'] = $user->display_name();
				$out['avatar'] = $user->get_picture_link('avatar');
			}
		} catch (Exception $e) {
			// A deleted or unreadable member still needs a name to sit under.
		}
		return self::$people[$user_id] = $out;
	}

	// ------------------------------------------------------------------
	// Values
	// ------------------------------------------------------------------

	/** A boolean column as PHP holds it: it may arrive as true, 't', 'true', 1 or '1'. */
	public static function flag($value): bool {
		return $value === true || $value === 't' || $value === 'true' || $value === 1 || $value === '1';
	}

	/**
	 * A title on one line: whitespace collapsed (a title becomes a subject line
	 * and a notification heading, so it never carries a line break) and cut to
	 * $max characters.
	 *
	 * @throws BoardsRefusal when nothing is left
	 */
	public static function cleanTitle($value, int $max = 255, string $what = 'A title'): string {
		$title = trim(preg_replace('/\s+/u', ' ', (string)$value));
		if ($title === '') {
			throw new BoardsRefusal($what . ' cannot be empty.');
		}
		return mb_substr($title, 0, $max);
	}

	// ------------------------------------------------------------------
	// Time
	// ------------------------------------------------------------------

	/**
	 * A due time as the browser sends it, to the UTC string the column holds.
	 * Accepts an ISO-8601 instant ("2026-10-12T17:00:00Z"); an empty value clears.
	 *
	 * @throws BoardsRefusal when it is not a time
	 */
	public static function utcFromInput($value): ?string {
		if ($value === null || $value === '') {
			return null;
		}
		$ts = strtotime((string)$value);
		// A year outside 1970–2200 is never a real due date (and a zero date in an
		// import would otherwise reach the database as year -1).
		if ($ts === false || $ts < 0 || $ts > 7258118400) {
			throw new BoardsRefusal('That is not a valid date.');
		}
		return gmdate('Y-m-d H:i:s', $ts);
	}

	/** A stored UTC string as an ISO-8601 instant for the browser, or null. */
	public static function isoFromUtc($value): ?string {
		if ($value === null || $value === '') {
			return null;
		}
		$ts = strtotime($value . ' UTC');
		return $ts === false ? null : gmdate('Y-m-d\TH:i:s\Z', $ts);
	}
}
