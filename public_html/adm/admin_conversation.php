<?php
/**
 * Admin conversation view with moderation
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/conversations_class.php'));
require_once(PathHelper::getIncludePath('data/conversation_participants_class.php'));
require_once(PathHelper::getIncludePath('data/messages_class.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));

$session = SessionControl::get_instance();
$session->check_permission(8);
$session->set_return();

$conversation_id = isset($_GET['cnv_conversation_id']) ? (int)$_GET['cnv_conversation_id'] : 0;
if (!$conversation_id) {
	header("Location: /admin/admin_conversations");
	exit();
}

$conversation = new Conversation($conversation_id, TRUE);

// Handle actions
if (isset($_REQUEST['action'])) {
	$action = $_REQUEST['action'];

	if ($action === 'delete_message' && isset($_REQUEST['msg_message_id'])) {
		$msg = new Message((int)$_REQUEST['msg_message_id'], TRUE);
		$msg->soft_delete();
		header("Location: /admin/admin_conversation?cnv_conversation_id=" . $conversation_id);
		exit();
	}

	if ($action === 'delete_conversation') {
		$conversation->soft_delete();
		header("Location: /admin/admin_conversations");
		exit();
	}
}

// Load participants
$participants = new MultiConversationParticipant(
	['conversation_id' => $conversation_id]
);
$participants->load();

// Load messages
$messages = new MultiMessage(
	['conversation_id' => $conversation_id],
	['msg_sent_time' => 'ASC']
);
$messages->load();

$page = new AdminPage();
$page->admin_header(
	array(
		'menu-id' => 'conversations',
		'page_title' => 'Conversation #' . $conversation_id,
		'readable_title' => 'Conversation',
		'breadcrumbs' => array(
			'Conversations' => '/admin/admin_conversations',
			'Conversation #' . $conversation_id => '',
		),
		'session' => $session,
	)
);

// Conversation metadata
$options = array('title' => 'Conversation Details');
if (!$conversation->get('cnv_delete_time')) {
	$options['altlinks']['Delete Conversation'] = '/admin/admin_conversation?action=delete_conversation&cnv_conversation_id=' . $conversation_id;
}
$page->begin_box($options);

echo '<strong>ID:</strong> ' . (int)$conversation_id . '<br>';
echo '<strong>Created:</strong> ' . LibraryFunctions::convert_time($conversation->get('cnv_create_time'), 'UTC', $session->get_timezone()) . '<br>';
if ($conversation->get('cnv_subject')) {
	echo '<strong>Subject:</strong> ' . htmlspecialchars($conversation->get('cnv_subject'), ENT_QUOTES, 'UTF-8') . '<br>';
}
if ($conversation->get('cnv_delete_time')) {
	echo '<strong>Status:</strong> <span style="color:red;">Deleted</span> at ' . LibraryFunctions::convert_time($conversation->get('cnv_delete_time'), 'UTC', $session->get_timezone()) . '<br>';
} else {
	echo '<strong>Status:</strong> Active<br>';
}
echo '<strong>Total Messages:</strong> ' . $messages->count() . '<br>';

$page->end_box();

// Participants
$options = array('title' => 'Participants');
$page->begin_box($options);

echo '<table style="width:100%;border-collapse:collapse;">';
echo '<tr><th style="text-align:left;padding:4px 8px;">User</th><th style="text-align:left;padding:4px 8px;">Joined</th><th style="text-align:left;padding:4px 8px;">Last Read</th><th style="text-align:left;padding:4px 8px;">Muted</th><th style="text-align:left;padding:4px 8px;">Deleted</th></tr>';

foreach ($participants as $p) {
	$user_id = $p->get('cnp_usr_user_id');
	try {
		$user = new User($user_id, TRUE);
		$user_link = '<a href="/admin/admin_user?usr_user_id=' . (int)$user_id . '">' . htmlspecialchars($user->display_name(), ENT_QUOTES, 'UTF-8') . '</a>';
	} catch (Exception $e) {
		$user_link = 'User #' . (int)$user_id;
	}

	$joined = $p->get('cnp_create_time') ? LibraryFunctions::convert_time($p->get('cnp_create_time'), 'UTC', $session->get_timezone()) : '-';
	$last_read = $p->get('cnp_last_read_time') ? LibraryFunctions::convert_time($p->get('cnp_last_read_time'), 'UTC', $session->get_timezone()) : 'Never';
	$muted = $p->get('cnp_is_muted') ? 'Yes' : 'No';
	$deleted = $p->get('cnp_delete_time') ? LibraryFunctions::convert_time($p->get('cnp_delete_time'), 'UTC', $session->get_timezone()) : '-';

	echo '<tr>';
	echo '<td style="padding:4px 8px;">' . $user_link . '</td>';
	echo '<td style="padding:4px 8px;">' . $joined . '</td>';
	echo '<td style="padding:4px 8px;">' . $last_read . '</td>';
	echo '<td style="padding:4px 8px;">' . $muted . '</td>';
	echo '<td style="padding:4px 8px;">' . $deleted . '</td>';
	echo '</tr>';
}

echo '</table>';
$page->end_box();

// Messages
$options = array('title' => 'Messages');
$page->begin_box($options);

if ($messages->count() === 0) {
	echo '<p>No messages in this conversation.</p>';
} else {
	foreach ($messages as $msg) {
		$sender_id = $msg->get('msg_usr_user_id_sender');
		try {
			$sender = new User($sender_id, TRUE);
			$sender_name = htmlspecialchars($sender->display_name(), ENT_QUOTES, 'UTF-8');
		} catch (Exception $e) {
			$sender_name = 'User #' . (int)$sender_id;
		}

		$time = LibraryFunctions::convert_time($msg->get('msg_sent_time'), 'UTC', $session->get_timezone(), 'M j, Y g:i A');
		$body = htmlspecialchars($msg->get('msg_body'), ENT_QUOTES, 'UTF-8');
		$is_deleted = (bool)$msg->get('msg_delete_time');

		$bg = $is_deleted ? '#fee' : '#f9f9f9';
		echo '<div style="background:' . $bg . ';padding:0.75rem;margin-bottom:0.5rem;border-radius:4px;border:1px solid #eee;">';
		echo '<div style="display:flex;justify-content:space-between;margin-bottom:0.25rem;">';
		echo '<strong>' . $sender_name . '</strong>';
		echo '<span style="color:#888;font-size:0.85rem;">' . htmlspecialchars($time, ENT_QUOTES, 'UTF-8') . '</span>';
		echo '</div>';
		echo '<div>' . nl2br($body) . '</div>';
		if ($is_deleted) {
			echo '<div style="color:red;font-size:0.85rem;margin-top:0.25rem;">Deleted at ' . LibraryFunctions::convert_time($msg->get('msg_delete_time'), 'UTC', $session->get_timezone()) . '</div>';
		} else {
			echo '<div style="margin-top:0.25rem;"><a href="/admin/admin_conversation?action=delete_message&cnv_conversation_id=' . $conversation_id . '&msg_message_id=' . (int)$msg->key . '" style="color:#c00;font-size:0.85rem;">Delete</a></div>';
		}
		echo '</div>';
	}
}

$page->end_box();

$page->admin_footer();
?>
