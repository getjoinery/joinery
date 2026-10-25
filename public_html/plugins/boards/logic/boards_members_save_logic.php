<?php
/**
 * API action: boards/boards_members_save — who is on a board, and finding people.
 *
 * POST /api/v1/action/boards/boards_members_save
 *
 *   add_user       board_id, user_id, role         — board admin
 *   add_group      board_id, group_id, role        — board admin; user-category groups only, and only groups the
 *                                                    person is in themselves (a site admin may offer any)
 *   set_role       board_id, member_id, role       — board admin
 *   remove         board_id, member_id             — board admin; a board keeps at least one admin
 *   join           board_id                        — anyone, on an Open to all members board; becomes an editor
 *   leave          board_id                        — take yourself off the board
 *   search_people  board_id, q                     — names and pictures of site members (never addresses)
 *   search_groups  board_id, q                     — user-category groups, by name
 *   members        board_id                        — the membership rows (board admin)
 *
 * Roles: admin (everything), editor (cards and lists), viewer (read only).
 *
 * @version 1.0.0
 */

function boards_members_save_logic(array $input): LogicResult {
	return BoardsAction::run(function (int $user_id) use ($input) {
		$op = BoardsAction::oneOf($input['op'] ?? '', array(
			'add_user', 'add_group', 'set_role', 'remove', 'join', 'leave', 'search_people', 'search_groups', 'members',
		), 'operation');

		// Joining is the one door that opens on a board the person has no role on.
		if ($op === 'join') {
			$board_id = (int)($input['board_id'] ?? 0);
			if ($board_id <= 0 || !Board::check_if_exists($board_id)) {
				throw new BoardsRefusal('Board not found.');
			}
			$board = new Board($board_id, TRUE);
			if ($board->get('bbd_delete_time') || $board->get('bbd_archived_time')) {
				throw new BoardsRefusal('Board not found.');
			}
			BoardsManager::join($board, $user_id);
			return array('role' => Boards::roleFor($board, $user_id), 'board' => BoardsPayload::board($board));
		}

		$board = Boards::boardFor($input['board_id'] ?? 0, $user_id, in_array($op, array('leave'), true) ? 'viewer' : (
			$op === 'search_people' ? 'editor' : 'admin'));
		$role = Boards::roleFor($board, $user_id);

		switch ($op) {
			case 'search_people':
				// An editor may search on an open board (to put someone on a card);
				// only an admin searches to add people to an invite-only one.
				if ($role !== Boards::ROLE_ADMIN && $board->get('bbd_visibility') !== Board::VISIBILITY_ALL_MEMBERS) {
					throw new BoardsRefusal('Only a board admin can do that.');
				}
				return array('people' => boards_members_search_people((string)($input['q'] ?? ''), $user_id));

			case 'search_groups':
				return array('groups' => boards_members_search_groups((string)($input['q'] ?? ''), $user_id));

			case 'members':
				break;

			case 'add_user':
				BoardsManager::addUser($board, (int)($input['user_id'] ?? 0), (string)($input['role'] ?? 'editor'));
				break;

			case 'add_group':
				BoardsManager::addGroup($board, (int)($input['group_id'] ?? 0), (string)($input['role'] ?? 'editor'), $user_id);
				break;

			case 'set_role':
				BoardsManager::setRole($board, BoardsManager::memberOn($board, $input['member_id'] ?? 0), (string)($input['role'] ?? ''));
				break;

			case 'remove':
				BoardsManager::removeMember($board, BoardsManager::memberOn($board, $input['member_id'] ?? 0));
				break;

			case 'leave':
				BoardsManager::leave($board, $user_id);
				return array('left' => true, 'role' => Boards::roleFor($board, $user_id));
		}

		$people = BoardsPayload::people($board);
		return array(
			'member_rows'  => BoardsPayload::memberRows($board),
			'people'       => $people['people'],
			'people_stamp' => $people['stamp'],
		);
	});
}

/** Site members whose name matches, as names and pictures only. */
function boards_members_search_people(string $q, int $searching_user_id): array {
	$q = trim($q);
	if (mb_strlen($q) < 2) {
		return array();
	}
	$words = preg_split('/\s+/', $q);
	$users = new MultiUser(
		count($words) >= 2 ? array('name_like' => $q) : array('first_name_like' => $q, 'last_name_like' => $q, 'nickname_like' => $q),
		array('usr_first_name' => 'ASC', 'usr_last_name' => 'ASC'),
		40, 0,
		count($words) >= 2 ? 'AND' : 'OR'
	);
	$out = array();
	foreach ($users as $user) {
		$id = (int)$user->key;
		if (User::is_placeholder($id) || $user->get('usr_delete_time') || $user->get('usr_is_disabled') || $user->get('usr_is_admin_disabled')) {
			continue;
		}
		$out[] = array('id' => $id, 'name' => $user->display_name(), 'avatar' => $user->get_picture_link('avatar'));
		if (count($out) >= 20) {
			break;
		}
	}
	return $out;
}

/**
 * User-category groups whose name matches — only ones the person may offer: the
 * groups they are in, or any group for a site admin. A group's name and size are
 * site information a member has no other way to list.
 */
function boards_members_search_groups(string $q, int $user_id): array {
	$q = trim($q);
	$out = array();
	$groups = new MultiGroup(array('category' => 'user', 'deleted' => false), array('grp_name' => 'ASC'), 500);
	foreach ($groups as $group) {
		if (!BoardsManager::mayUseGroup($user_id, (int)$group->key)) {
			continue;
		}
		$name = (string)$group->get('grp_name');
		if ($q === '' || mb_stripos($name, $q) !== false) {
			$out[] = array('id' => (int)$group->key, 'name' => $name, 'members' => (int)$group->get_member_count());
		}
		if (count($out) >= 20) {
			break;
		}
	}
	return $out;
}

function boards_members_save_logic_descriptor(): array {
	return array(
		'description'      => 'Add or remove the people and groups on a board and set their roles; join an open board; find people to add.',
		'requires_session' => true,
		'requires_setting' => 'boards_active',
		'mutates'          => true,
		'input'            => array(
			'op'        => array('type' => 'string', 'required' => true, 'label' => 'Operation', 'enum' => array(
				'add_user', 'add_group', 'set_role', 'remove', 'join', 'leave', 'search_people', 'search_groups', 'members')),
			'board_id'  => array('type' => 'int', 'required' => true, 'label' => 'Board'),
			'user_id'   => array('type' => 'int', 'required' => false, 'label' => 'Person'),
			'group_id'  => array('type' => 'int', 'required' => false, 'label' => 'Group'),
			'member_id' => array('type' => 'int', 'required' => false, 'label' => 'Membership'),
			'role'      => array('type' => 'string', 'required' => false, 'enum' => array('admin', 'editor', 'viewer'), 'label' => 'Role'),
			'q'         => array('type' => 'string', 'required' => false, 'max_length' => 100, 'label' => 'Search'),
		),
		'auth'             => array('min_user_permission' => 0, 'capability' => 'write'),
	);
}
