<?php
/**
 * Profile dashboard logic — loads summary data for the member dashboard.
 *
 * Subscription reconciliation runs on the cron runner
 * (the ReconcileSubscriptions task), never inline here — a page render is
 * read-only.
 *
 * @version 2.1
 */

function profile_logic(array $input): LogicResult{
	require_once(PathHelper::getIncludePath('includes/Activation.php'));
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/address_class.php'));
	require_once(PathHelper::getIncludePath('data/files_class.php'));
	require_once(PathHelper::getIncludePath('data/notifications_class.php'));
	require_once(PathHelper::getIncludePath('includes/ProfileDashboardRegistry.php'));

	$page_vars = array();

	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;
	require_once(PathHelper::getComposerAutoloadPath());

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;
	$session->check_permission(0);
	$session->set_return();

	// Activation code handling
	if (isset($input['act_code']) && $input['act_code']) {
		if ($user_id = $session->get_user_id()) {
			$activated_user = Activation::ActivateUser($input['act_code'], $user_id);
		} else {
			$activated_user = Activation::ActivateUser($input['act_code']);
		}
	}

	$user = new User($session->get_user_id(), TRUE);
	$page_vars['user'] = $user;

	$now_utc = gmdate('Y-m-d H:i:s');

	// ---------------------------------------------------------------
	// PLUGIN DASHBOARD SECTIONS
	//   store:         recent_orders + subscriptions
	//   event_manager: upcoming_events + pending_surveys
	// Each active plugin registers its providers from serve.php. The list-card
	// sections render in the main column; sections that carry a stat also feed
	// the stat grid; the pending_surveys section drives the actions banner.
	// ---------------------------------------------------------------
	$dashboard_sections = array();
	$dashboard_stats = array();
	$pending_surveys = array();
	foreach (ProfileDashboardRegistry::sections($user) as $section) {
		if ($section->id === 'pending_surveys') {
			foreach ($section->items as $it) {
				$pending_surveys[] = $it->data;
			}
			continue; // shown in the actions banner, not as a card
		}
		$dashboard_sections[] = $section;
		if ($section->stat) {
			$dashboard_stats[] = $section->stat;
		}
	}
	$page_vars['dashboard_sections'] = $dashboard_sections;
	$page_vars['dashboard_stats'] = $dashboard_stats;
	$page_vars['pending_surveys'] = $pending_surveys;

	// ---------------------------------------------------------------
	// NOTIFICATIONS — unread count + last 5
	// ---------------------------------------------------------------
	$page_vars['unread_notifications'] = Notification::get_unread_count($user->key);

	$recent_notifications = new MultiNotification(
		array('user_id' => $user->key, 'deleted' => false),
		array('ntf_create_time' => 'DESC'),
		5
	);
	$recent_notifications->load();
	$page_vars['recent_notifications'] = $recent_notifications;

	// ---------------------------------------------------------------
	// MESSAGES / CONVERSATIONS — unread count + last 3
	// ---------------------------------------------------------------
	$page_vars['unread_messages'] = 0;
	$page_vars['recent_conversations'] = null;
	$page_vars['conversation_other_users'] = array();

	if ($settings->get_setting('messaging_active')) {
		require_once(PathHelper::getIncludePath('data/conversations_class.php'));
		$page_vars['unread_messages'] = Conversation::get_unread_count($user->key);

		$recent_conversations = new MultiConversation(
			array('participant_user_id' => $user->key, 'deleted' => false),
			array(),
			3
		);
		$conv_count = $recent_conversations->count_all();
		if ($conv_count > 0) {
			$recent_conversations->load();

			// Load other participant names
			$other_users = array();
			require_once(PathHelper::getIncludePath('data/conversation_participants_class.php'));
			foreach ($recent_conversations as $cnv) {
				$other_user = $cnv->get_other_participant($user->key);
				$other_users[$cnv->key] = $other_user ? $other_user->display_name() : 'Unknown';
			}
			$page_vars['conversation_other_users'] = $other_users;
		}
		$page_vars['recent_conversations'] = $recent_conversations;
	}

	// ---------------------------------------------------------------
	// ADDRESS — for sidebar user card
	// ---------------------------------------------------------------
	$addresses = new MultiAddress(
		array('user_id' => $session->get_user_id()));
	$num_addresses = $addresses->count_all();
	if ($num_addresses) {
		$addresses->load();
		$address = $addresses->get(0);
	} else {
		$address = new Address(NULL);
	}
	$page_vars['address'] = $address;

	// ---------------------------------------------------------------
	// MAILING LISTS
	// ---------------------------------------------------------------
	$user_subscribed_list = array();
	$user_lists = new MultiMailingListRegistrant(
		array('deleted' => false, 'user_id' => $user->key));
	$user_lists->load();
	foreach ($user_lists as $user_list) {
		$mailing_list = new MailingList($user_list->get('mlr_mlt_mailing_list_id'), TRUE);
		$user_subscribed_list[] = $mailing_list->get('mlt_name');
	}
	$page_vars['user_subscribed_list'] = $user_subscribed_list;

	// ---------------------------------------------------------------
	// DISPLAY MESSAGES (session flash)
	// ---------------------------------------------------------------
	$page_vars['display_messages'] = $session->get_messages($_SERVER['REQUEST_URI']);

	return LogicResult::render($page_vars);
}

?>
