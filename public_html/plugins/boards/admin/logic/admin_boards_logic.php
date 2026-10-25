<?php
/**
 * Admin Boards page logic: every board on the site, with the actions a superadmin
 * needs to clean up after people — archive, restore, delete, undelete and remove
 * for good.
 *
 * Superadmins (permission 10) are an admin on every board, so abandoned ones can
 * be handled here; other site admins have no special access to a board. Every
 * action is a POST.
 *
 * @version 1.0.0
 */

function admin_boards_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();

	$flash = null;
	if (!empty($_POST['action']) && !empty($_POST['bbd_board_id'])) {
		$board_id = (int)$_POST['bbd_board_id'];
		if ($board_id > 0 && Board::check_if_exists($board_id)) {
			$board = new Board($board_id, TRUE);
			$name = (string)$board->get('bbd_name');
			switch ($_POST['action']) {
				case 'archive':
					BoardsManager::archiveBoard($board);
					$flash = 'Archived "' . $name . '".';
					break;
				case 'restore':
					BoardsManager::restoreBoard($board);
					$flash = 'Restored "' . $name . '".';
					break;
				case 'delete':
					$board->soft_delete();
					$flash = 'Deleted "' . $name . '". It can be undeleted.';
					break;
				case 'undelete':
					$board->undelete();
					$flash = 'Undeleted "' . $name . '".';
					break;
				case 'purge':
					// Only a board that is already deleted can be removed for good.
					if ($board->get('bbd_delete_time')) {
						$board->permanent_delete();
						$flash = 'Permanently removed "' . $name . '" and everything on it.';
					}
					break;
			}
		}
		return LogicResult::redirect('/plugins/boards/admin/admin_boards?flash=' . urlencode((string)$flash));
	}

	$per_page = 30;
	$offset = max(0, (int)($input['offset'] ?? 0));
	$show = in_array($input['show'] ?? '', array('active', 'archived', 'deleted', 'templates'), true) ? $input['show'] : 'active';

	$where = array(
		'active'    => 'bbd.bbd_delete_time IS NULL AND bbd.bbd_archived_time IS NULL AND bbd.bbd_is_template = false',
		'archived'  => 'bbd.bbd_delete_time IS NULL AND bbd.bbd_archived_time IS NOT NULL',
		'deleted'   => 'bbd.bbd_delete_time IS NOT NULL',
		'templates' => 'bbd.bbd_delete_time IS NULL AND bbd.bbd_is_template = true',
	)[$show];

	$count = DbConnector::GetPreparedStatement("SELECT COUNT(*) FROM bbd_boards bbd WHERE $where");
	$count->execute();
	$numrecords = (int)$count->fetchColumn();

	$q = DbConnector::GetPreparedStatement(
		"SELECT bbd.bbd_board_id, bbd.bbd_name, bbd.bbd_visibility, bbd.bbd_usr_user_id_owner, bbd.bbd_archived_time,
		        bbd.bbd_delete_time, bbd.bbd_is_template,
		        (SELECT COUNT(*) FROM bbm_board_members bbm WHERE bbm.bbm_bbd_board_id = bbd.bbd_board_id) AS member_rows,
		        (SELECT COUNT(*) FROM bcd_cards bcd WHERE bcd.bcd_bbd_board_id = bbd.bbd_board_id AND bcd.bcd_delete_time IS NULL) AS card_count,
		        (SELECT MAX(bac.bac_create_time) FROM bac_activity bac WHERE bac.bac_bbd_board_id = bbd.bbd_board_id) AS last_activity
		 FROM bbd_boards bbd WHERE $where
		 ORDER BY bbd.bbd_name, bbd.bbd_board_id LIMIT $per_page OFFSET $offset");
	$q->execute();

	return LogicResult::render(array(
		'session'    => $session,
		'boards'     => $q->fetchAll(PDO::FETCH_ASSOC),
		'numrecords' => $numrecords,
		'numperpage' => $per_page,
		'show'       => $show,
		'flash'      => isset($input['flash']) ? (string)$input['flash'] : '',
	));
}
