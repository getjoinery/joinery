<?php
/**
 * API action: conversation_action — mute / unmute / delete a conversation.
 *
 * POST /api/v1/action/conversation_action (session key). Params:
 * conversation_id, action (mute / unmute / delete). Replaces the
 * mute_conversation / unmute_conversation / delete_conversation actions
 * of the legacy /ajax/conversations_ajax.php endpoint; participant
 * authorization matches it exactly — only a participant's own
 * ConversationParticipant row may be mutated.
 *
 * @version 1.0.0
 */

require_once(__DIR__ . '/../includes/PathHelper.php');

function conversation_action_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/conversation_participants_class.php'));

	$session = SessionControl::get_instance();
	if (!$session->get_user_id()) {
		return LogicResult::error('Sign in required.');
	}

	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('messaging_active', true, true)) {
		return LogicResult::error('This feature is turned off');
	}

	$current_user_id = $session->get_user_id();
	$conversation_id = isset($input['conversation_id']) ? (int)$input['conversation_id'] : 0;
	$action = isset($input['action']) ? (string)$input['action'] : '';

	if (!$conversation_id) {
		return LogicResult::error('You must provide a conversation_id.');
	}
	if (!in_array($action, array('mute', 'unmute', 'delete'), true)) {
		return LogicResult::error('Invalid action.');
	}

	$participants = new MultiConversationParticipant(
		array('conversation_id' => $conversation_id, 'user_id' => $current_user_id)
	);
	$participants->load();
	if ($participants->count() === 0) {
		return LogicResult::error('You do not have permission to update this conversation.');
	}
	$participant = $participants->get(0);

	if ($action === 'mute' || $action === 'unmute') {
		$participant->set('cnp_is_muted', $action === 'mute');
		$participant->save();
	} else {
		$participant->set('cnp_delete_time', gmdate('Y-m-d H:i:s'));
		$participant->save();
		$_SESSION['message_unread_count'] = null;
	}

	return LogicResult::render(array(
		'conversation_id' => $conversation_id,
		'action'          => $action,
	));
}

function conversation_action_logic_api() {
	return [
		'requires_session' => true,
		'description' => 'Mute, unmute, or delete a conversation for the signed-in owner',
	];
}

?>
