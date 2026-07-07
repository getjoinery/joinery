<?php
/**
 * API action: conversation_list — the owner's paginated conversation inbox as JSON.
 *
 * POST /api/v1/action/conversation_list (session key). Params: offset
 * (20/page, matching the web inbox). Shares conversations_logic.php's
 * query path (the lateral-join latest-message inbox query).
 *
 * @version 1.0.0
 */

require_once(__DIR__ . '/../includes/PathHelper.php');

function conversation_list_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/conversations_class.php'));
	require_once(PathHelper::getIncludePath('data/conversation_participants_class.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$session = SessionControl::get_instance();
	if (!$session->get_user_id()) {
		return LogicResult::error('Sign in required.');
	}

	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('messaging_active', true, true)) {
		return LogicResult::error('This feature is turned off');
	}

	$user_id = $session->get_user_id();
	$numperpage = 20;
	$page_offset = isset($input['offset']) ? max(0, (int)$input['offset']) : 0;

	$conversations = new MultiConversation(
		array('participant_user_id' => $user_id, 'deleted' => false),
		array(),
		$numperpage,
		$page_offset
	);
	$total = $conversations->count_all();
	$conversations->load();

	$out = array();
	foreach ($conversations as $cnv) {
		$other_user = $cnv->get_other_participant($user_id);
		$out[] = array(
			'conversation_id'    => (int)$cnv->key,
			'other_display_name' => $other_user ? $other_user->display_name() : 'Unknown',
			'preview'            => $cnv->latest_message_body ?? '',
			'last_message_time'  => $cnv->latest_message_time ?? null,
			'unread'             => empty($cnv->cnp_last_read_time) || ($cnv->latest_message_time ?? '') > $cnv->cnp_last_read_time,
			'muted'              => (bool)$cnv->cnp_is_muted,
		);
	}

	return LogicResult::render(array(
		'conversations' => $out,
		'total_count'   => $total,
		'offset'        => $page_offset,
		'per_page'      => $numperpage,
	));
}

function conversation_list_logic_api() {
	return [
		'requires_session' => true,
		'description' => 'Paginated conversation inbox for the signed-in owner',
	];
}

?>
