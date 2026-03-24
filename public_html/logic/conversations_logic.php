<?php
/**
 * Conversations inbox logic
 *
 * @version 1.0
 */

function conversations_logic($get_vars, $post_vars) {
	$page_vars = array();
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/Pager.php'));
	require_once(PathHelper::getIncludePath('data/conversations_class.php'));
	require_once(PathHelper::getIncludePath('data/conversation_participants_class.php'));
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

	$numperpage = 20;
	$page_offset = isset($get_vars['offset']) ? (int)$get_vars['offset'] : 0;

	$conversations = new MultiConversation(
		array('participant_user_id' => $session->get_user_id(), 'deleted' => false),
		array(),
		$numperpage,
		$page_offset
	);
	$numrecords = $conversations->count_all();
	$conversations->load();

	// Load other participant User objects for each conversation
	$other_users = array();
	foreach ($conversations as $cnv) {
		$other_user = $cnv->get_other_participant($session->get_user_id());
		if ($other_user) {
			$other_users[$cnv->key] = $other_user;
		}
	}

	$page_vars['conversations'] = $conversations;
	$page_vars['other_users'] = $other_users;
	$page_vars['title'] = 'Messages';
	$page_vars['numrecords'] = $numrecords;
	$page_vars['pager'] = new Pager(array('numrecords' => $numrecords, 'numperpage' => $numperpage));

	return LogicResult::render($page_vars);
}
