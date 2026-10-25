<?php
/** @joinery-admin class=content */
/**
 * Boards admin — every board on the site, for superadmins.
 *
 * Open, archive, restore, delete and remove boards; the plugin's settings are one
 * link away. Every action is a POST button.
 *
 * @version 1.0.0
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('plugins/boards/admin/logic/admin_boards_logic.php'));

$page_vars = process_logic(admin_boards_logic(array_merge($_GET, $_POST)));
extract($page_vars);

$page = new AdminPage();
$page->admin_header(array(
	'menu-id' => 'boards-admin',
	'breadcrumbs' => array('Boards' => ''),
	'session' => $session,
));

if ($flash !== '') {
	echo '<div class="alert alert-success">' . htmlspecialchars($flash) . '</div>';
}

echo '<p>';
foreach (array('active' => 'Boards', 'archived' => 'Archived', 'deleted' => 'Deleted', 'templates' => 'Templates') as $key => $label) {
	echo '<a class="btn btn-sm ' . ($show === $key ? 'btn-primary' : 'btn-secondary') . '" href="/plugins/boards/admin/admin_boards?show=' . $key . '">' . $label . '</a> ';
}
echo ' <a class="btn btn-sm btn-outline" href="/admin/admin_settings_plugins?plugin=boards">Boards settings</a>';
echo '</p>';

$headers = array('Board', 'Owner', 'Access', 'Cards', 'Last activity', 'Actions');
$pager = new Pager(array('numrecords' => $numrecords, 'numperpage' => $numperpage));
$page->tableheader($headers, array('title' => 'Boards'), $pager);

$tz = $session->get_timezone();
foreach ($boards as $row) {
	$id = (int)$row['bbd_board_id'];
	$deleted = !empty($row['bbd_delete_time']);
	$archived = !empty($row['bbd_archived_time']);

	$owner = '—';
	if ($row['bbd_usr_user_id_owner']) {
		$owner = htmlspecialchars(Boards::person((int)$row['bbd_usr_user_id_owner'])['name']);
	}
	$access = ($row['bbd_visibility'] === Board::VISIBILITY_ALL_MEMBERS ? 'Open to all members' : 'Invite only')
		. ' · ' . (int)$row['member_rows'] . ' on the list';
	$last = $row['last_activity'] ? LibraryFunctions::convert_time($row['last_activity'], 'UTC', $tz, 'M j, Y g:i A') : '—';

	$actions = '';
	$hidden = array('bbd_board_id' => $id);
	if ($deleted) {
		$actions .= AdminPage::action_button('Undelete', '/plugins/boards/admin/admin_boards', array('hidden' => $hidden + array('action' => 'undelete')));
		$actions .= ' ' . AdminPage::action_button('Remove for good', '/plugins/boards/admin/admin_boards', array(
			'hidden' => $hidden + array('action' => 'purge'),
			'confirm' => 'Remove "' . $row['bbd_name'] . '" and every card, comment and attachment on it for good? This cannot be undone.',
			'confirm_typed' => 'remove',
			'class' => 'btn btn-danger btn-sm',
		));
	} else {
		$actions .= $archived
			? AdminPage::action_button('Restore', '/plugins/boards/admin/admin_boards', array('hidden' => $hidden + array('action' => 'restore')))
			: AdminPage::action_button('Archive', '/plugins/boards/admin/admin_boards', array('hidden' => $hidden + array('action' => 'archive')));
		$actions .= ' ' . AdminPage::action_button('Delete', '/plugins/boards/admin/admin_boards', array(
			'hidden' => $hidden + array('action' => 'delete'),
			'confirm' => 'Delete "' . $row['bbd_name'] . '"? The board and its cards disappear for everyone. It can be undeleted from the Deleted tab.',
		));
	}

	$rowvalues = array();
	$rowvalues[] = $deleted
		? htmlspecialchars($row['bbd_name'])
		: '<a href="/profile/boards/board?id=' . $id . '">' . htmlspecialchars($row['bbd_name']) . '</a>';
	$rowvalues[] = $owner;
	$rowvalues[] = htmlspecialchars($access);
	$rowvalues[] = (int)$row['card_count'];
	$rowvalues[] = $last;
	$rowvalues[] = $actions;
	$page->disprow($rowvalues);
}

$page->endtable($pager);
$page->admin_footer();
