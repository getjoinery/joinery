<?php
/**
 * Single conversation logic (view + compose mode)
 *
 * @version 1.0
 */

function conversation_logic($get_vars, $post_vars) {
	$page_vars = array();
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/Pager.php'));
	require_once(PathHelper::getIncludePath('data/conversations_class.php'));
	require_once(PathHelper::getIncludePath('data/conversation_participants_class.php'));
	require_once(PathHelper::getIncludePath('data/messages_class.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$session = SessionControl::get_instance();
	if (!$session->is_logged_in()) {
		return LogicResult::redirect('/login');
	}

	// Check if messaging is active
	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('messaging_active', true, true)) {
		return LogicResult::redirect('/');
	}

	$current_user_id = $session->get_user_id();

	// Compose mode: new conversation
	if (isset($get_vars['new']) && $get_vars['new'] == '1' && isset($get_vars['to'])) {
		$recipient_id = (int)$get_vars['to'];

		if ($recipient_id == $current_user_id) {
			return LogicResult::redirect('/profile/conversations');
		}

		// Check if recipient exists
		if (!User::check_if_exists($recipient_id)) {
			return LogicResult::redirect('/profile/conversations');
		}

		// Check if conversation already exists between these users
		try {
			$dbconnector = DbConnector::get_instance();
			$dblink = $dbconnector->get_db_link();
			$sql = "SELECT cnp1.cnp_cnv_conversation_id
					FROM cnp_conversation_participants cnp1
					JOIN cnp_conversation_participants cnp2
					  ON cnp1.cnp_cnv_conversation_id = cnp2.cnp_cnv_conversation_id
					JOIN cnv_conversations cnv
					  ON cnv.cnv_conversation_id = cnp1.cnp_cnv_conversation_id
					WHERE cnp1.cnp_usr_user_id = ?
					  AND cnp2.cnp_usr_user_id = ?
					  AND cnv.cnv_delete_time IS NULL
					  AND NOT EXISTS (
					      SELECT 1 FROM cnp_conversation_participants cnp3
					      WHERE cnp3.cnp_cnv_conversation_id = cnp1.cnp_cnv_conversation_id
					        AND cnp3.cnp_usr_user_id NOT IN (?, ?)
					  )
					LIMIT 1";
			$q = $dblink->prepare($sql);
			$q->execute([$current_user_id, $recipient_id, $current_user_id, $recipient_id]);
			$row = $q->fetch(PDO::FETCH_ASSOC);
			if ($row) {
				return LogicResult::redirect('/profile/conversation?id=' . $row['cnp_cnv_conversation_id']);
			}
		} catch (Exception $e) {
			// Tables may not exist yet — continue to compose mode
		}

		$recipient = new User($recipient_id, TRUE);

		$page_vars['is_compose_mode'] = true;
		$page_vars['recipient'] = $recipient;
		$page_vars['recipient_id'] = $recipient_id;
		$page_vars['conversation'] = null;
		$page_vars['messages'] = null;
		$page_vars['other_user'] = $recipient;
		$page_vars['title'] = 'New Message to ' . $recipient->display_name();
		$page_vars['pager'] = null;

		return LogicResult::render($page_vars);
	}

	// View existing conversation
	if (!isset($get_vars['id'])) {
		return LogicResult::redirect('/profile/conversations');
	}

	$conversation_id = (int)$get_vars['id'];

	try {
		$conversation = new Conversation($conversation_id, TRUE);
	} catch (Exception $e) {
		return LogicResult::redirect('/profile/conversations');
	}

	// Verify current user is a participant
	if (!$conversation->has_participant($current_user_id)) {
		return LogicResult::redirect('/profile/conversations');
	}

	// Load messages
	$numperpage = 50;
	$page_offset = isset($get_vars['offset']) ? (int)$get_vars['offset'] : 0;

	$messages = new MultiMessage(
		array('conversation_id' => $conversation_id, 'deleted' => false),
		array('msg_sent_time' => 'ASC'),
		$numperpage,
		$page_offset
	);
	$num_messages = $messages->count_all();
	$messages->load();

	// Get other participant
	$other_user = $conversation->get_other_participant($current_user_id);

	// Mark conversation as read
	$participants = new MultiConversationParticipant(
		['conversation_id' => $conversation_id, 'user_id' => $current_user_id]
	);
	$participants->load();
	if ($participants->count() > 0) {
		$my_participant = $participants->get(0);
		$my_participant->set('cnp_last_read_time', gmdate('Y-m-d H:i:s'));
		$my_participant->save();
	}

	// Invalidate unread count cache
	$_SESSION['message_unread_count'] = null;

	// Get participant info for mute status
	$is_muted = false;
	if ($participants->count() > 0) {
		$is_muted = (bool)$participants->get(0)->get('cnp_is_muted');
	}

	$page_vars['is_compose_mode'] = false;
	$page_vars['conversation'] = $conversation;
	$page_vars['messages'] = $messages;
	$page_vars['num_messages'] = $num_messages;
	$page_vars['other_user'] = $other_user;
	$page_vars['is_muted'] = $is_muted;
	$page_vars['title'] = $other_user ? $other_user->display_name() : 'Conversation';
	$page_vars['pager'] = new Pager(array('numrecords' => $num_messages, 'numperpage' => $numperpage));

	return LogicResult::render($page_vars);
}
