<?php
/**
 * Profile dashboard logic — loads summary data for the member dashboard.
 *
 * @version 2.0
 */

function profile_logic($get_vars, $post_vars){
	require_once(PathHelper::getIncludePath('includes/Activation.php'));
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/address_class.php'));
	require_once(PathHelper::getIncludePath('data/files_class.php'));
	require_once(PathHelper::getIncludePath('data/events_class.php'));
	require_once(PathHelper::getIncludePath('data/event_registrants_class.php'));
	require_once(PathHelper::getIncludePath('data/event_sessions_class.php'));
	require_once(PathHelper::getIncludePath('data/orders_class.php'));
	require_once(PathHelper::getIncludePath('data/order_items_class.php'));
	require_once(PathHelper::getIncludePath('data/notifications_class.php'));

	$page_vars = array();

	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;
	require_once(PathHelper::getComposerAutoloadPath());

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;
	$session->check_permission(0);
	$session->set_return();

	// Activation code handling
	if (isset($get_vars['act_code']) && $get_vars['act_code']) {
		if ($user_id = $session->get_user_id()) {
			$activated_user = Activation::ActivateUser($get_vars['act_code'], $user_id);
		} else {
			$activated_user = Activation::ActivateUser($get_vars['act_code']);
		}
	}

	$user = new User($session->get_user_id(), TRUE);
	$page_vars['user'] = $user;
	include(PathHelper::getAbsolutePath('utils/registrant_maintenance.php'));

	$now_utc = gmdate('Y-m-d H:i:s');

	// ---------------------------------------------------------------
	// EVENTS — active only, limit 3, sorted soonest-first
	// ---------------------------------------------------------------
	$event_registrants = new MultiEventRegistrant(
		array('user_id' => $user->key, 'deleted' => false),
		array('evr_create_time' => 'DESC')
	);
	$event_registrants->load();

	$active_events = array();
	$active_event_count = 0;

	foreach ($event_registrants as $event_registrant) {
		$event = new Event($event_registrant->get('evr_evt_event_id'), TRUE);
		if (!$event || $event->get('evt_delete_time')) {
			continue;
		}

		// Determine status
		$is_expired = $event_registrant->get('evr_expires_time') && $event_registrant->get('evr_expires_time') < $now_utc;
		$is_active = !$is_expired && $event->get('evt_status') == Event::STATUS_ACTIVE;

		if (!$is_active) continue;
		$active_event_count++;

		// Only build detail for first 3
		if (count($active_events) >= 3) continue;

		$tevent = array();
		$tevent['event_name'] = $event->get('evt_name');

		$next_session = $event->get_next_session();
		$time = '';
		$tz = $event->get('evt_timezone');
		if ($next_session) {
			$time = '<b>Next session: ';
			if ($tz != $session->get_timezone()) {
				$time .= $next_session->get_time_string($session->get_timezone());
			} else {
				$time .= $next_session->get_time_string($tz);
			}
			$time .= '</b>';
			$tevent['sort_time'] = $next_session->get('evs_start_time');
		} else {
			if ($tz != $session->get_timezone()) {
				$time .= $event->get_time_string($session->get_timezone());
			} else {
				$time .= $event->get_time_string($tz);
			}
			$tevent['sort_time'] = $event->get('evt_start_time') ?: '9999-12-31';
		}
		$tevent['event_time'] = $time;

		$tevent['event_expires'] = '';
		if ($event_registrant->get('evr_expires_time')) {
			$tevent['event_expires'] = LibraryFunctions::convert_time($event_registrant->get('evr_expires_time'), 'UTC', $session->get_timezone());
		}
		$tevent['event_status'] = 'Active';

		if ($event->get('evt_session_display_type') == 2) {
			$tevent['event_link'] = '/profile/event_sessions_course?evt_event_id=' . $event->key;
		} else {
			$tevent['event_link'] = '/profile/event_sessions?evt_event_id=' . $event->key;
		}

		$active_events[] = $tevent;
	}

	// Sort by soonest first
	usort($active_events, function($a, $b) {
		return strcmp($a['sort_time'] ?? '', $b['sort_time'] ?? '');
	});
	$page_vars['event_registrations'] = array_slice($active_events, 0, 3);
	$page_vars['active_event_count'] = $active_event_count;

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
	// ORDERS — last 3
	// ---------------------------------------------------------------
	$page_vars['orders'] = null;
	$page_vars['numorders'] = 0;
	if ($settings->get_setting('products_active')) {
		$orders = new MultiOrder(
			array('user_id' => $session->get_user_id()),
			array('ord_order_id' => 'DESC'),
			3
		);
		$page_vars['numorders'] = $orders->count_all();
		$orders->load();
		$page_vars['orders'] = $orders;
	}

	// ---------------------------------------------------------------
	// SUBSCRIPTIONS — for sidebar summary + count
	// ---------------------------------------------------------------
	$page_vars['subscriptions'] = null;
	$page_vars['active_subscription_count'] = 0;
	if ($settings->get_setting('products_active') && $settings->get_setting('subscriptions_active')) {
		$subscriptions = new MultiOrderItem(
			array('user_id' => $user->key, 'is_subscription' => true),
			array('order_item_id' => 'DESC'),
			5
		);
		$subscriptions->load();
		$page_vars['subscriptions'] = $subscriptions;

		// Count active (non-canceled) subscriptions
		$active_subs = new MultiOrderItem(
			array('user_id' => $user->key, 'is_active_subscription' => true)
		);
		$page_vars['active_subscription_count'] = $active_subs->count_all();
	}

	// ---------------------------------------------------------------
	// ADDRESS — for sidebar user card
	// ---------------------------------------------------------------
	$addresses = new MultiAddress(
		array('user_id' => $session->get_user_id(), 'deleted' => FALSE));
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
	// PENDING SURVEYS
	// ---------------------------------------------------------------
	$pending_surveys = array();
	$user_registrations = new MultiEventRegistrant(
		array('user_id' => $session->get_user_id(), 'deleted' => false),
		array('evr_create_time' => 'DESC')
	);
	$user_registrations->load();
	foreach ($user_registrations as $reg) {
		if ($reg->get('evr_survey_completed')) continue;
		$event = new Event($reg->get('evr_evt_event_id'), TRUE);
		if (!$event->get('evt_svy_survey_id')) continue;
		$display = $event->get('evt_survey_display');
		if ($display === 'optional_at_confirmation' || $display === 'after_event') {
			if ($display === 'after_event') {
				$end_time = $event->get('evt_end_time') ?: $event->get('evt_start_time');
				if ($end_time > $now_utc) continue;
			}
			$pending_surveys[] = array(
				'survey_id' => $event->get('evt_svy_survey_id'),
				'event_id' => $event->key,
				'event_name' => $event->get('evt_name'),
			);
		}
	}
	$page_vars['pending_surveys'] = $pending_surveys;

	// ---------------------------------------------------------------
	// DISPLAY MESSAGES (session flash)
	// ---------------------------------------------------------------
	$page_vars['display_messages'] = $session->get_messages($_SERVER['REQUEST_URI']);

	return LogicResult::render($page_vars);
}

?>
