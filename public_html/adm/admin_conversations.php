<?php
/**
 * Admin conversation list
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/Pager.php'));
require_once(PathHelper::getIncludePath('data/conversations_class.php'));
require_once(PathHelper::getIncludePath('data/conversation_participants_class.php'));
require_once(PathHelper::getIncludePath('data/messages_class.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));

$session = SessionControl::get_instance();
$session->check_permission(8);
$session->set_return();

$numperpage = 25;
$page_offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

// Search filter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$conversations = new MultiConversation(
	array('deleted' => false),
	array('cnv_conversation_id' => 'DESC'),
	$numperpage,
	$page_offset
);
$numrecords = $conversations->count_all();
$conversations->load();

$page = new AdminPage();
$page->admin_header(
	array(
		'menu-id' => 'conversations',
		'page_title' => 'Conversations',
		'readable_title' => 'Conversations',
		'breadcrumbs' => array(
			'Conversations' => '',
		),
		'session' => $session,
	)
);

$headers = array("ID", "Participants", "Messages", "Last Message", "Last Activity", "Status", "Actions");
$pager = new Pager(array('numrecords' => $numrecords, 'numperpage' => $numperpage));
$table_options = array(
	'title' => 'Conversations (' . $numrecords . ')',
);
$page->tableheader($headers, $table_options, $pager);

foreach ($conversations as $cnv) {
	// Load participants
	$participants = new MultiConversationParticipant(
		['conversation_id' => $cnv->key]
	);
	$participants->load();

	$participant_names = array();
	foreach ($participants as $p) {
		try {
			$user = new User($p->get('cnp_usr_user_id'), TRUE);
			$participant_names[] = '<a href="/admin/admin_user?usr_user_id=' . (int)$p->get('cnp_usr_user_id') . '">' . htmlspecialchars($user->display_name(), ENT_QUOTES, 'UTF-8') . '</a>';
		} catch (Exception $e) {
			$participant_names[] = 'User #' . (int)$p->get('cnp_usr_user_id');
		}
	}

	// Count messages
	$msg_count = new MultiMessage(
		['conversation_id' => $cnv->key, 'deleted' => false]
	);
	$message_count = $msg_count->count_all();

	// Get latest message
	$latest_messages = new MultiMessage(
		['conversation_id' => $cnv->key, 'deleted' => false],
		['msg_sent_time' => 'DESC'],
		1
	);
	$latest_messages->load();
	$latest_preview = '';
	$latest_time = '';
	if ($latest_messages->count() > 0) {
		$latest = $latest_messages->get(0);
		$latest_preview = htmlspecialchars(substr(strip_tags($latest->get('msg_body')), 0, 50), ENT_QUOTES, 'UTF-8');
		$latest_time = LibraryFunctions::convert_time($latest->get('msg_sent_time'), 'UTC', $session->get_timezone(), 'M j, Y g:i A');
	}

	$status = $cnv->get('cnv_delete_time') ? 'Deleted' : 'Active';

	$rowvalues = array();
	$rowvalues[] = $cnv->key;
	$rowvalues[] = implode(', ', $participant_names);
	$rowvalues[] = $message_count;
	$rowvalues[] = $latest_preview ? '"' . $latest_preview . '..."' : '-';
	$rowvalues[] = $latest_time ?: '-';
	$rowvalues[] = $status;
	$rowvalues[] = '<a href="/admin/admin_conversation?cnv_conversation_id=' . (int)$cnv->key . '">View</a>';

	$page->disprow($rowvalues);
}

$page->endtable($pager);
$page->admin_footer();
?>
