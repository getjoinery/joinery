<?php
/**
 * Conversations AJAX endpoint
 * Actions: send_message, mark_read, delete_conversation, mute_conversation, unmute_conversation
 *
 * @version 1.0
 */
header('Content-Type: application/json');

try {
	require_once(PathHelper::getIncludePath('data/conversations_class.php'));
	require_once(PathHelper::getIncludePath('data/conversation_participants_class.php'));
	require_once(PathHelper::getIncludePath('data/messages_class.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
} catch (Exception $e) {
	echo json_encode(array('success' => false, 'message' => 'Failed to load dependencies'));
	exit;
}

$session = SessionControl::get_instance();
if (!$session->get_user_id()) {
	echo json_encode(array('success' => false, 'message' => 'Not logged in'));
	exit;
}

$current_user_id = $session->get_user_id();
$action = isset($_POST['action']) ? $_POST['action'] : '';

switch ($action) {

	case 'send_message':
		$body = isset($_POST['body']) ? trim($_POST['body']) : '';
		$conversation_id = isset($_POST['conversation_id']) ? (int)$_POST['conversation_id'] : 0;
		$recipient_user_id = isset($_POST['recipient_user_id']) ? (int)$_POST['recipient_user_id'] : 0;

		if (empty($body)) {
			echo json_encode(array('success' => false, 'message' => 'Message cannot be empty'));
			exit;
		}

		if (mb_strlen($body) > Conversation::MAX_MESSAGE_LENGTH) {
			echo json_encode(array('success' => false, 'message' => 'Message is too long'));
			exit;
		}

		try {
			if ($recipient_user_id) {
				// New or existing conversation with recipient
				if ($recipient_user_id == $current_user_id) {
					echo json_encode(array('success' => false, 'message' => 'Cannot message yourself'));
					exit;
				}
				$conversation = Conversation::get_or_create_conversation($current_user_id, $recipient_user_id);
			} elseif ($conversation_id) {
				$conversation = new Conversation($conversation_id, TRUE);
				if (!$conversation->has_participant($current_user_id)) {
					echo json_encode(array('success' => false, 'message' => 'Permission denied'));
					exit;
				}
			} else {
				echo json_encode(array('success' => false, 'message' => 'No conversation or recipient specified'));
				exit;
			}

			$message = $conversation->add_message($current_user_id, $body);

			// Build message HTML for DOM insertion
			$clean_body = htmlspecialchars(strip_tags($body), ENT_QUOTES, 'UTF-8');
			$time = LibraryFunctions::convert_time(
				$message->get('msg_sent_time'), 'UTC',
				$session->get_timezone(), 'g:i A'
			);
			$message_html = '<div class="message-bubble message-mine">'
				. '<div class="message-body">' . nl2br($clean_body) . '</div>'
				. '<div class="message-time">' . htmlspecialchars($time, ENT_QUOTES, 'UTF-8') . '</div>'
				. '</div>';

			echo json_encode(array(
				'success' => true,
				'conversation_id' => (int)$conversation->key,
				'message_id' => (int)$message->key,
				'message_html' => $message_html,
				'sent_time' => $message->get('msg_sent_time')
			));
		} catch (ConversationException $e) {
			echo json_encode(array('success' => false, 'message' => $e->getMessage()));
		} catch (Exception $e) {
			echo json_encode(array('success' => false, 'message' => 'Failed to send message'));
		}
		break;

	case 'mark_read':
		$conversation_id = (int)(isset($_POST['conversation_id']) ? $_POST['conversation_id'] : 0);
		if (!$conversation_id) {
			echo json_encode(array('success' => false, 'message' => 'No conversation ID'));
			exit;
		}

		try {
			$participants = new MultiConversationParticipant(
				['conversation_id' => $conversation_id, 'user_id' => $current_user_id]
			);
			$participants->load();
			if ($participants->count() === 0) {
				echo json_encode(array('success' => false, 'message' => 'Permission denied'));
				exit;
			}
			$participant = $participants->get(0);
			$participant->set('cnp_last_read_time', gmdate('Y-m-d H:i:s'));
			$participant->save();
			$_SESSION['message_unread_count'] = null;
			echo json_encode(array('success' => true));
		} catch (Exception $e) {
			echo json_encode(array('success' => false, 'message' => 'Failed to mark read'));
		}
		break;

	case 'delete_conversation':
		$conversation_id = (int)(isset($_POST['conversation_id']) ? $_POST['conversation_id'] : 0);
		if (!$conversation_id) {
			echo json_encode(array('success' => false, 'message' => 'No conversation ID'));
			exit;
		}

		try {
			$participants = new MultiConversationParticipant(
				['conversation_id' => $conversation_id, 'user_id' => $current_user_id]
			);
			$participants->load();
			if ($participants->count() === 0) {
				echo json_encode(array('success' => false, 'message' => 'Permission denied'));
				exit;
			}
			$participant = $participants->get(0);
			$participant->set('cnp_delete_time', gmdate('Y-m-d H:i:s'));
			$participant->save();
			$_SESSION['message_unread_count'] = null;
			echo json_encode(array('success' => true));
		} catch (Exception $e) {
			echo json_encode(array('success' => false, 'message' => 'Failed to delete conversation'));
		}
		break;

	case 'mute_conversation':
	case 'unmute_conversation':
		$conversation_id = (int)(isset($_POST['conversation_id']) ? $_POST['conversation_id'] : 0);
		if (!$conversation_id) {
			echo json_encode(array('success' => false, 'message' => 'No conversation ID'));
			exit;
		}

		try {
			$participants = new MultiConversationParticipant(
				['conversation_id' => $conversation_id, 'user_id' => $current_user_id]
			);
			$participants->load();
			if ($participants->count() === 0) {
				echo json_encode(array('success' => false, 'message' => 'Permission denied'));
				exit;
			}
			$participant = $participants->get(0);
			$is_muted = ($action === 'mute_conversation');
			$participant->set('cnp_is_muted', $is_muted);
			$participant->save();
			echo json_encode(array('success' => true, 'is_muted' => $is_muted));
		} catch (Exception $e) {
			echo json_encode(array('success' => false, 'message' => 'Failed to update mute status'));
		}
		break;

	default:
		echo json_encode(array('success' => false, 'message' => 'Invalid action'));
		break;
}
