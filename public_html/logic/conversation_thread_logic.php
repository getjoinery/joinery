<?php
/**
 * API action: conversation_thread — one conversation's messages as JSON.
 *
 * POST /api/v1/action/conversation_thread (session key). Params:
 * conversation_id (existing thread) OR to (compose-mode dedup — returns
 * the existing 1:1 conversation with that user if one exists, otherwise
 * an empty compose-mode payload); before / after (ISO UTC cursors, 50
 * messages per page). Marks the conversation read (cnp_last_read_time)
 * exactly as the web view does. Shares conversation_logic.php's query
 * path and participant checks.
 *
 * @version 1.0.0
 */

require_once(__DIR__ . '/../includes/PathHelper.php');

function conversation_thread_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/conversations_class.php'));
	require_once(PathHelper::getIncludePath('data/conversation_participants_class.php'));
	require_once(PathHelper::getIncludePath('data/messages_class.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$session = SessionControl::get_instance();
	if (!$session->get_user_id()) {
		return LogicResult::error('Sign in required.');
	}

	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('messaging_active', true, true)) {
		return LogicResult::error('This feature is turned off');
	}

	$current_user_id = $session->get_user_id();

	// Compose-mode dedup: given `to` instead of `conversation_id`.
	if (!isset($input['conversation_id']) && isset($input['to'])) {
		$recipient_id = (int)$input['to'];
		if ($recipient_id == $current_user_id) {
			return LogicResult::error('Cannot message yourself.');
		}
		if (!User::check_if_exists($recipient_id)) {
			return LogicResult::error('That user does not exist.');
		}

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

		if (!$row) {
			$recipient = new User($recipient_id, TRUE);
			return LogicResult::render(array(
				'is_compose_mode'    => true,
				'conversation_id'    => null,
				'other_display_name' => $recipient->display_name(),
				'other_user_id'      => $recipient_id,
				'messages'           => array(),
				'has_more'           => false,
			));
		}

		$input['conversation_id'] = $row['cnp_cnv_conversation_id'];
	}

	if (!isset($input['conversation_id'])) {
		return LogicResult::error('You must provide a conversation_id or to.');
	}

	$conversation_id = (int)$input['conversation_id'];

	if (!Conversation::check_if_exists($conversation_id)) {
		return LogicResult::error('Conversation not found.');
	}
	$conversation = new Conversation($conversation_id, TRUE);

	if (!$conversation->has_participant($current_user_id)) {
		return LogicResult::error('You do not have permission to view this conversation.');
	}

	$numperpage = 50;
	$where = array('msg_cnv_conversation_id = ?', 'msg_delete_time IS NULL');
	$params = array($conversation_id);
	$order = 'ASC';

	if (!empty($input['before'])) {
		$where[] = 'msg_sent_time < ?';
		$params[] = (string)$input['before'];
		$order = 'DESC'; // fetch the most recent page below the cursor, then reverse to chronological order
	} elseif (!empty($input['after'])) {
		$where[] = 'msg_sent_time > ?';
		$params[] = (string)$input['after'];
		$order = 'ASC';
	}

	$dbconnector = DbConnector::get_instance();
	$dblink = $dbconnector->get_db_link();
	$sql = 'SELECT msg_message_id, msg_usr_user_id_sender, msg_body, msg_sent_time
			FROM msg_messages
			WHERE ' . implode(' AND ', $where) . '
			ORDER BY msg_sent_time ' . $order . ', msg_message_id ' . $order . '
			LIMIT ' . ($numperpage + 1);
	$q = $dblink->prepare($sql);
	$q->execute($params);
	$rows = $q->fetchAll(PDO::FETCH_ASSOC);

	$has_more = count($rows) > $numperpage;
	$rows = array_slice($rows, 0, $numperpage);
	if ($order === 'DESC') {
		$rows = array_reverse($rows);
	}

	$messages_out = array();
	foreach ($rows as $row) {
		$messages_out[] = array(
			'message_id' => (int)$row['msg_message_id'],
			'sender_id'  => (int)$row['msg_usr_user_id_sender'],
			'body'       => $row['msg_body'],
			'time'       => $row['msg_sent_time'],
			'is_mine'    => (int)$row['msg_usr_user_id_sender'] === $current_user_id,
		);
	}

	// Mark conversation read, exactly as the web view does.
	$participants = new MultiConversationParticipant(
		array('conversation_id' => $conversation_id, 'user_id' => $current_user_id)
	);
	$participants->load();
	$is_muted = false;
	if ($participants->count() > 0) {
		$my_participant = $participants->get(0);
		$my_participant->set('cnp_last_read_time', gmdate('Y-m-d H:i:s'));
		$my_participant->save();
		$is_muted = (bool)$my_participant->get('cnp_is_muted');
	}
	$_SESSION['message_unread_count'] = null;

	$other_user = $conversation->get_other_participant($current_user_id);

	return LogicResult::render(array(
		'is_compose_mode'    => false,
		'conversation_id'    => $conversation_id,
		'other_display_name' => $other_user ? $other_user->display_name() : 'Unknown',
		'other_user_id'      => $other_user ? (int)$other_user->key : null,
		'is_muted'           => $is_muted,
		'messages'           => $messages_out,
		'has_more'           => $has_more,
	));
}

function conversation_thread_logic_descriptor() {
	return [
		'requires_session' => true,
		'description' => 'One conversation\'s messages, cursor-paginated; marks it read. Given `to` instead of conversation_id, dedups to an existing 1:1 conversation for compose mode.',
	];
}

?>
