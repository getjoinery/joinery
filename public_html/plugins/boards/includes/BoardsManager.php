<?php
/**
 * BoardsManager — what happens to a board itself, its people, its lists and its
 * labels.
 *
 * BoardsCards covers everything on a card; this covers the frame around them.
 * Like BoardsCards it assumes the caller has checked the role (Boards::boardFor)
 * and checks only what it alone can know: that a person being added is a real
 * member, that a group is a user group, that a change would not leave the board
 * with nobody to run it.
 *
 * @version 1.0.1
 * @changelog 1.0.1 - deletes the caller's board role admitted run under rule_checked_write()
 */

class BoardsManager {

	/** The background colours a board can wear (an uploaded image is 'file:{id}'). */
	const BACKGROUNDS = array('blue', 'green', 'orange', 'red', 'purple', 'pink', 'sky', 'lime', 'gray');

	// ------------------------------------------------------------------
	// The board
	// ------------------------------------------------------------------

	/**
	 * Make a board; the creator owns it and runs it.
	 *
	 * @param array $opts description, visibility, background, starter (a starter
	 *   key) or template_id (a template board to copy the shape of)
	 * @throws BoardsRefusal
	 */
	public static function createBoard(int $owner_id, string $name, array $opts = array()): Board {
		$name = Boards::cleanTitle($name, 255, 'A board name');

		$starter = null;
		if (!empty($opts['starter'])) {
			$starter = BoardsTemplates::definition((string)$opts['starter']);
		}
		$template = null;
		if (!empty($opts['template_id'])) {
			$template = Boards::boardFor($opts['template_id'], $owner_id, 'viewer');
			if (!Boards::flag($template->get('bbd_is_template'))) {
				throw new BoardsRefusal('That board is not a template.');
			}
		}

		$background = isset($opts['background']) ? self::cleanBackground((string)$opts['background']) : null;
		if ($background === null && $starter) {
			$background = (string)($starter['background'] ?? 'blue');
		}

		return Boards::transaction(function () use ($owner_id, $name, $opts, $starter, $template, $background) {
			$board = new Board(NULL);
			$board->set('bbd_name', $name);
			$board->set('bbd_description', isset($opts['description']) && trim((string)$opts['description']) !== ''
				? (string)$opts['description'] : ($starter['description'] ?? null));
			$board->set('bbd_visibility', self::cleanVisibility($opts['visibility'] ?? Board::VISIBILITY_INVITE_ONLY));
			$board->set('bbd_background', $background);
			$board->set('bbd_is_template', false);
			$board->set('bbd_usr_user_id_owner', $owner_id);
			$board->save();
			$board->load();

			$member = new BoardMember(NULL);
			$member->set('bbm_bbd_board_id', (int)$board->key);
			$member->set('bbm_usr_user_id', $owner_id);
			$member->set('bbm_role', BoardMember::ROLE_ADMIN);
			// The creator owns the board just made (bbd_usr_user_id_owner); this
			// row is what makes them its admin, so no role admits it yet.
			SystemBase::rule_checked_write(function () use ($member) { $member->save(); });
			Boards::forgetRoles();

			if ($starter) {
				BoardsTemplates::build($board, $starter, $owner_id);
			} elseif ($template) {
				BoardsTemplates::copyInto($board, $template, $owner_id);
			}
			return $board;
		});
	}

	/** @throws BoardsRefusal */
	public static function cleanVisibility($value): string {
		return BoardsAction::oneOf($value, array(Board::VISIBILITY_INVITE_ONLY, Board::VISIBILITY_ALL_MEMBERS), 'visibility');
	}

	/** A colour token or the board's own uploaded image; anything else is no background. */
	public static function cleanBackground(string $value): ?string {
		$value = trim($value);
		if ($value === '') {
			return null;
		}
		if (in_array($value, self::BACKGROUNDS, true)) {
			return $value;
		}
		throw new BoardsRefusal('Unknown background.');
	}

	/**
	 * Rename, describe, re-colour or change who can open the board.
	 *
	 * @param array $changes any of: name, description, visibility, background
	 */
	public static function updateBoard(Board $board, int $user_id, array $changes): Board {
		$narrowed = false;
		Boards::transaction(function () use ($board, $changes, &$narrowed) {
			Boards::lockAndReload($board);
			if (array_key_exists('name', $changes)) {
				$board->set('bbd_name', Boards::cleanTitle($changes['name'], 255, 'A board name'));
			}
			if (array_key_exists('description', $changes)) {
				$board->set('bbd_description', trim((string)$changes['description']) === '' ? null : (string)$changes['description']);
			}
			if (array_key_exists('visibility', $changes)) {
				$visibility = self::cleanVisibility($changes['visibility']);
				$narrowed = $visibility === Board::VISIBILITY_INVITE_ONLY
					&& $board->get('bbd_visibility') === Board::VISIBILITY_ALL_MEMBERS;
				$board->set('bbd_visibility', $visibility);
			}
			if (array_key_exists('background', $changes)) {
				$old_file = Boards::backgroundFileId((string)$board->get('bbd_background'));
				$board->set('bbd_background', self::cleanBackground((string)$changes['background']));
				if ($old_file) {
					Boards::deleteFile($old_file);
				}
			}
			$board->set('bbd_update_time', 'now()');
			$board->save();
			$board->load();
		});
		if ($narrowed) {
			self::reconcileAccess($board);
		}
		return $board;
	}

	public static function archiveBoard(Board $board): void {
		Boards::transaction(function () use ($board) {
			Boards::lockAndReload($board);
			$board->set('bbd_archived_time', 'now()');
			$board->set('bbd_update_time', 'now()');
			$board->save();
			$board->load();
		});
	}

	public static function restoreBoard(Board $board): void {
		Boards::transaction(function () use ($board) {
			Boards::lockAndReload($board);
			$board->set('bbd_archived_time', null);
			$board->set('bbd_update_time', 'now()');
			$board->save();
			$board->load();
		});
	}

	public static function setTemplate(Board $board, bool $is_template): void {
		Boards::transaction(function () use ($board, $is_template) {
			Boards::lockAndReload($board);
			$board->set('bbd_is_template', $is_template);
			$board->set('bbd_update_time', 'now()');
			$board->save();
			$board->load();
		});
	}

	/** "Tell me about every new card on this board." */
	public static function setWatching(Board $board, int $user_id, bool $watch): void {
		$q = DbConnector::GetPreparedStatement('SELECT bbw_board_watcher_id FROM bbw_board_watchers WHERE bbw_bbd_board_id = ? AND bbw_usr_user_id = ?');
		$q->execute(array((int)$board->key, $user_id));
		$id = $q->fetchColumn();
		if ($watch && !$id) {
			$row = new BoardWatcher(NULL);
			$row->set('bbw_bbd_board_id', (int)$board->key);
			$row->set('bbw_usr_user_id', $user_id);
			$row->save();
		} elseif (!$watch && $id) {
			// The watcher's own row, on a board the caller can open (boardFor()).
			SystemBase::rule_checked_write(function () use ($id) {
				(new BoardWatcher((int)$id, TRUE))->permanent_delete();
			});
		}
	}

	// ------------------------------------------------------------------
	// People
	// ------------------------------------------------------------------

	/** @throws BoardsRefusal */
	public static function cleanRole($value): string {
		return BoardsAction::oneOf($value, array(BoardMember::ROLE_ADMIN, BoardMember::ROLE_EDITOR, BoardMember::ROLE_VIEWER), 'role');
	}

	/** Add a person (or change the role they already have by name). */
	public static function addUser(Board $board, int $user_id, string $role): BoardMember {
		$role = self::cleanRole($role);
		if ($user_id <= 0 || User::is_placeholder($user_id) || !User::check_if_exists($user_id)) {
			throw new BoardsRefusal('That person is not a member of this site.');
		}
		$q = DbConnector::GetPreparedStatement('SELECT bbm_board_member_id FROM bbm_board_members WHERE bbm_bbd_board_id = ? AND bbm_usr_user_id = ?');
		$q->execute(array((int)$board->key, $user_id));
		$id = $q->fetchColumn();
		$member = $id ? new BoardMember((int)$id, TRUE) : new BoardMember(NULL);
		if (!$id) {
			$member->set('bbm_bbd_board_id', (int)$board->key);
			$member->set('bbm_usr_user_id', $user_id);
		} elseif ($member->get('bbm_role') === BoardMember::ROLE_ADMIN && $role !== BoardMember::ROLE_ADMIN) {
			self::guardLastAdmin($board, (int)$member->key);
		}
		$member->set('bbm_role', $role);
		$member->save();
		Boards::forgetRoles();
		return $member;
	}

	/**
	 * May this person put this group on a board? A group's name and size are
	 * site information, so a member can offer only the groups they are in
	 * themselves; a site admin (5 and up) can offer any.
	 */
	public static function mayUseGroup(int $actor_id, int $group_id): bool {
		return Boards::userPermission($actor_id) >= 5 || in_array($group_id, Boards::userGroupIds($actor_id), true);
	}

	/**
	 * Add a whole user group. Only user-category groups can be members, and only
	 * ones the person adding it may offer (see mayUseGroup()). Pass the acting
	 * user; 0 means the system (an import or a test), which may offer any.
	 */
	public static function addGroup(Board $board, int $group_id, string $role, int $actor_id = 0): BoardMember {
		$role = self::cleanRole($role);
		if ($group_id <= 0 || !Group::check_if_exists($group_id)) {
			throw new BoardsRefusal('Group not found.');
		}
		$group = new Group($group_id, TRUE);
		if ($group->get('grp_delete_time') || $group->get('grp_category') !== 'user'
			|| ($actor_id > 0 && !self::mayUseGroup($actor_id, $group_id))) {
			throw new BoardsRefusal('Only groups of members can be added to a board.');
		}
		$q = DbConnector::GetPreparedStatement('SELECT bbm_board_member_id FROM bbm_board_members WHERE bbm_bbd_board_id = ? AND bbm_grp_group_id = ?');
		$q->execute(array((int)$board->key, $group_id));
		$id = $q->fetchColumn();
		$member = $id ? new BoardMember((int)$id, TRUE) : new BoardMember(NULL);
		if (!$id) {
			$member->set('bbm_bbd_board_id', (int)$board->key);
			$member->set('bbm_grp_group_id', $group_id);
		} elseif ($member->get('bbm_role') === BoardMember::ROLE_ADMIN && $role !== BoardMember::ROLE_ADMIN) {
			self::guardLastAdmin($board, (int)$member->key);
		}
		$member->set('bbm_role', $role);
		$member->save();
		Boards::forgetRoles();
		return $member;
	}

	/** A membership row on this board. @throws BoardsRefusal */
	public static function memberOn(Board $board, $member_id): BoardMember {
		$member_id = (int)$member_id;
		if ($member_id <= 0 || !BoardMember::check_if_exists($member_id)) {
			throw new BoardsRefusal('Member not found.');
		}
		$member = new BoardMember($member_id, TRUE);
		if ((int)$member->get('bbm_bbd_board_id') !== (int)$board->key) {
			throw new BoardsRefusal('Member not found.');
		}
		return $member;
	}

	public static function setRole(Board $board, BoardMember $member, string $role): void {
		$role = self::cleanRole($role);
		if ($member->get('bbm_role') === BoardMember::ROLE_ADMIN && $role !== BoardMember::ROLE_ADMIN) {
			self::guardLastAdmin($board, (int)$member->key);
		}
		$member->set('bbm_role', $role);
		$member->save();
		Boards::forgetRoles();
	}

	public static function removeMember(Board $board, BoardMember $member): void {
		if ($member->get('bbm_role') === BoardMember::ROLE_ADMIN) {
			self::guardLastAdmin($board, (int)$member->key);
		}
		// A board admin removing someone, or a person leaving (the caller's
		// boardFor()); a membership row is removed outright.
		SystemBase::rule_checked_write(function () use ($member) { $member->permanent_delete(); });
		Boards::forgetRoles();
		self::reconcileAccess($board);
	}

	/** A board always keeps someone who can run it. @throws BoardsRefusal */
	private static function guardLastAdmin(Board $board, int $leaving_member_id): void {
		$q = DbConnector::GetPreparedStatement(
			"SELECT COUNT(*) FROM bbm_board_members WHERE bbm_bbd_board_id = ? AND bbm_role = 'admin' AND bbm_board_member_id <> ?");
		$q->execute(array((int)$board->key, $leaving_member_id));
		if ((int)$q->fetchColumn() < 1) {
			throw new BoardsRefusal('A board needs at least one admin.');
		}
	}

	/** Join an Open to all members board: the person becomes an editor. */
	public static function join(Board $board, int $user_id): void {
		if ($board->get('bbd_visibility') !== Board::VISIBILITY_ALL_MEMBERS) {
			throw new BoardsRefusal('Board not found.');
		}
		$q = DbConnector::GetPreparedStatement('SELECT 1 FROM bbm_board_members WHERE bbm_bbd_board_id = ? AND bbm_usr_user_id = ?');
		$q->execute(array((int)$board->key, $user_id));
		if ($q->fetchColumn()) {
			return;
		}
		// An Open to all members board takes anyone who can open it (the
		// visibility check above); their membership row is a board admin's write.
		SystemBase::rule_checked_write(function () use ($board, $user_id) {
			self::addUser($board, $user_id, BoardMember::ROLE_EDITOR);
		});
	}

	/** Leave a board (remove the person's own direct row), unless they are its last admin. */
	public static function leave(Board $board, int $user_id): void {
		$q = DbConnector::GetPreparedStatement('SELECT bbm_board_member_id FROM bbm_board_members WHERE bbm_bbd_board_id = ? AND bbm_usr_user_id = ?');
		$q->execute(array((int)$board->key, $user_id));
		$id = $q->fetchColumn();
		if ($id) {
			self::removeMember($board, new BoardMember((int)$id, TRUE));
		}
	}

	/**
	 * Take away what a person only had because they could open the board, once
	 * they cannot: their place on its cards, their watches on it, and their name on
	 * its checklist items. Run after anything that can remove access.
	 */
	public static function reconcileAccess(Board $board): void {
		Boards::forgetRoles();
		$board_id = (int)$board->key;
		$ids = array();
		$sources = array(
			'SELECT DISTINCT bcm.bcm_usr_user_id FROM bcm_card_members bcm JOIN bcd_cards bcd ON bcd.bcd_card_id = bcm.bcm_bcd_card_id WHERE bcd.bcd_bbd_board_id = ?',
			'SELECT DISTINCT bcw.bcw_usr_user_id FROM bcw_card_watchers bcw JOIN bcd_cards bcd ON bcd.bcd_card_id = bcw.bcw_bcd_card_id WHERE bcd.bcd_bbd_board_id = ?',
			'SELECT DISTINCT bci.bci_usr_user_id FROM bci_checklist_items bci JOIN bck_checklists bck ON bck.bck_checklist_id = bci.bci_bck_checklist_id
			   JOIN bcd_cards bcd ON bcd.bcd_card_id = bck.bck_bcd_card_id WHERE bcd.bcd_bbd_board_id = ? AND bci.bci_usr_user_id IS NOT NULL',
			'SELECT DISTINCT bbw_usr_user_id FROM bbw_board_watchers WHERE bbw_bbd_board_id = ?',
		);
		foreach ($sources as $sql) {
			$q = DbConnector::GetPreparedStatement($sql);
			$q->execute(array($board_id));
			foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $uid) {
				$ids[(int)$uid] = true;
			}
		}
		foreach (array_keys($ids) as $uid) {
			if (Boards::roleFor($board, $uid) !== null) {
				continue;
			}
			$db = DbConnector::get_instance()->get_db_link();
			$db->prepare('DELETE FROM bcm_card_members WHERE bcm_usr_user_id = ? AND bcm_bcd_card_id IN (SELECT bcd_card_id FROM bcd_cards WHERE bcd_bbd_board_id = ?)')->execute(array($uid, $board_id));
			$db->prepare('DELETE FROM bcw_card_watchers WHERE bcw_usr_user_id = ? AND bcw_bcd_card_id IN (SELECT bcd_card_id FROM bcd_cards WHERE bcd_bbd_board_id = ?)')->execute(array($uid, $board_id));
			$db->prepare('UPDATE bci_checklist_items SET bci_usr_user_id = NULL WHERE bci_usr_user_id = ? AND bci_bck_checklist_id IN
				(SELECT bck.bck_checklist_id FROM bck_checklists bck JOIN bcd_cards bcd ON bcd.bcd_card_id = bck.bck_bcd_card_id WHERE bcd.bcd_bbd_board_id = ?)')->execute(array($uid, $board_id));
			$db->prepare('DELETE FROM bbw_board_watchers WHERE bbw_usr_user_id = ? AND bbw_bbd_board_id = ?')->execute(array($uid, $board_id));
		}
	}

	// ------------------------------------------------------------------
	// Lists
	// ------------------------------------------------------------------

	public static function createList(Board $board, int $user_id, string $name, array $opts = array()): BoardList {
		$name = Boards::cleanTitle($name, 255, 'A list name');
		return Boards::transaction(function () use ($board, $user_id, $name, $opts) {
			$list = new BoardList(NULL);
			$list->set('bls_bbd_board_id', (int)$board->key);
			$list->set('bls_name', $name);
			$list->set('bls_position', Boards::computePosition('list', (int)$board->key, $opts['prev_id'] ?? null, $opts['next_id'] ?? null));
			$list->save();
			Boards::log((int)$board->key, null, $user_id, 'list.created', array('list_id' => (int)$list->key, 'name' => $name));
			return $list;
		});
	}

	public static function renameList(Board $board, BoardList $list, int $user_id, string $name): void {
		$list->set('bls_name', Boards::cleanTitle($name, 255, 'A list name'));
		$list->save();
		$list->load();
		// A rename has no verb of its own; the poll carries the lists every time.
		Boards::log((int)$board->key, null, $user_id, 'list.moved', array('quiet' => true, 'list_id' => (int)$list->key));
	}

	public static function moveList(Board $board, BoardList $list, int $user_id, ?int $prev_id, ?int $next_id): void {
		Boards::transaction(function () use ($board, $list, $user_id, $prev_id, $next_id) {
			$list->set('bls_position', Boards::computePosition('list', (int)$board->key, $prev_id, $next_id, (int)$list->key));
			$list->save();
			$list->load();
			Boards::log((int)$board->key, null, $user_id, 'list.moved', array('list_id' => (int)$list->key, 'name' => (string)$list->get('bls_name'), 'quiet' => true));
		});
	}

	public static function archiveList(Board $board, BoardList $list, int $user_id): void {
		Boards::transaction(function () use ($board, $list, $user_id) {
			$list->set('bls_archived_time', 'now()');
			$list->save();
			$list->load();
			Boards::log((int)$board->key, null, $user_id, 'list.archived', array('list_id' => (int)$list->key, 'name' => (string)$list->get('bls_name')));
		});
	}

	public static function restoreList(Board $board, BoardList $list, int $user_id): void {
		Boards::transaction(function () use ($board, $list, $user_id) {
			$list->set('bls_archived_time', null);
			$list->set('bls_position', Boards::computePosition('list', (int)$board->key, null, null, (int)$list->key));
			$list->save();
			$list->load();
			Boards::log((int)$board->key, null, $user_id, 'list.moved', array('list_id' => (int)$list->key, 'quiet' => true));
		});
	}

	// ------------------------------------------------------------------
	// Labels
	// ------------------------------------------------------------------

	/** Make a label, or change the one given. */
	public static function saveLabel(Board $board, ?BoardLabel $label, ?string $name, ?string $color): BoardLabel {
		if ($label === null) {
			$label = new BoardLabel(NULL);
			$label->set('blb_bbd_board_id', (int)$board->key);
			$label->set('blb_position', Boards::computePosition('label', (int)$board->key));
		}
		if ($name !== null) {
			$name = trim(preg_replace('/\s+/u', ' ', $name));
			$label->set('blb_name', $name === '' ? null : mb_substr($name, 0, 100));
		}
		if ($color !== null) {
			$label->set('blb_color', BoardsAction::oneOf($color, BoardLabel::COLORS, 'colour'));
		}
		$label->save();
		$label->load();
		return $label;
	}

	public static function deleteLabel(Board $board, BoardLabel $label): void {
		// A board admin (the caller's boardFor(..., 'admin')).
		SystemBase::rule_checked_write(function () use ($label) { $label->permanent_delete(); });
	}

	public static function moveLabel(Board $board, BoardLabel $label, ?int $prev_id, ?int $next_id): void {
		$label->set('blb_position', Boards::computePosition('label', (int)$board->key, $prev_id, $next_id, (int)$label->key));
		$label->save();
		$label->load();
	}
}
