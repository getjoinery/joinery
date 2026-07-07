<?php
/**
 * API action: profile_dashboard — member dashboard summary as JSON.
 *
 * POST /api/v1/action/profile_dashboard (session key). Same query path as
 * profile_logic.php: user card, section counts, and the first few rows of
 * each list. Sections gated by messaging_active / products_active /
 * subscriptions_active are omitted keys when the setting is off, so a
 * client renders strictly from present keys.
 *
 * @version 1.0.0
 */

require_once(__DIR__ . '/../includes/PathHelper.php');

function profile_dashboard_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/address_class.php'));
	require_once(PathHelper::getIncludePath('data/events_class.php'));
	require_once(PathHelper::getIncludePath('data/event_registrants_class.php'));
	require_once(PathHelper::getIncludePath('data/orders_class.php'));
	require_once(PathHelper::getIncludePath('data/order_items_class.php'));
	require_once(PathHelper::getIncludePath('data/notifications_class.php'));
	require_once(PathHelper::getIncludePath('data/mailing_lists_class.php'));
	require_once(PathHelper::getIncludePath('data/mailing_list_registrants_class.php'));

	$session = SessionControl::get_instance();
	if (!$session->get_user_id()) {
		return LogicResult::error('Sign in required.');
	}

	$settings = Globalvars::get_instance();
	$user = new User($session->get_user_id(), TRUE);
	$now_utc = gmdate('Y-m-d H:i:s');

	$out = array();

	// ---------------------------------------------------------------
	// USER CARD
	// ---------------------------------------------------------------
	$addresses = new MultiAddress(array('user_id' => $user->key, 'deleted' => FALSE));
	$address_string = '';
	if ($addresses->count_all() > 0) {
		$addresses->load();
		$address_string = $addresses->get(0)->get_address_string(', ');
	}

	$out['user'] = array(
		'name'        => $user->display_name(),
		'email'       => $user->get('usr_email'),
		'avatar_url'  => $user->get_picture_link(),
		'address'     => $address_string,
	);

	// ---------------------------------------------------------------
	// PENDING SURVEYS
	// ---------------------------------------------------------------
	$pending_surveys = array();
	$user_registrations = new MultiEventRegistrant(
		array('user_id' => $user->key, 'deleted' => false),
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
				'survey_id'  => (int)$event->get('evt_svy_survey_id'),
				'event_id'   => (int)$event->key,
				'event_name' => $event->get('evt_name'),
			);
		}
	}
	$out['pending_surveys'] = $pending_surveys;

	// ---------------------------------------------------------------
	// EVENTS — active only, upcoming 3
	// ---------------------------------------------------------------
	$active_events = array();
	$active_event_count = 0;
	foreach ($user_registrations as $event_registrant) {
		$event = new Event($event_registrant->get('evr_evt_event_id'), TRUE);
		if (!$event || $event->get('evt_delete_time')) continue;

		$is_expired = $event_registrant->get('evr_expires_time') && $event_registrant->get('evr_expires_time') < $now_utc;
		$is_active = !$is_expired && $event->get('evt_status') == Event::STATUS_ACTIVE;
		if (!$is_active) continue;
		$active_event_count++;
		if (count($active_events) >= 3) continue;

		$next_session = $event->get_next_session();
		$session_url = $event->get('evt_session_display_type') == 2
			? '/profile/event_sessions_course?evt_event_id=' . $event->key
			: '/profile/event_sessions?evt_event_id=' . $event->key;

		$active_events[] = array(
			'registrant_id'    => (int)$event_registrant->key,
			'event_id'         => (int)$event->key,
			'event_name'       => $event->get('evt_name'),
			'next_session_time'=> $next_session ? $next_session->get('evs_start_time') : ($event->get('evt_start_time') ?: null),
			'expires_time'     => $event_registrant->get('evr_expires_time') ?: null,
			'web_url'          => $session_url,
			'sort_time'        => $next_session ? $next_session->get('evs_start_time') : ($event->get('evt_start_time') ?: '9999-12-31'),
		);
	}
	usort($active_events, function($a, $b) { return strcmp($a['sort_time'], $b['sort_time']); });
	foreach ($active_events as &$e) { unset($e['sort_time']); }
	unset($e);
	$out['upcoming_events'] = $active_events;
	$out['upcoming_event_count'] = $active_event_count;

	// ---------------------------------------------------------------
	// MESSAGING (gated)
	// ---------------------------------------------------------------
	if ($settings->get_setting('messaging_active')) {
		require_once(PathHelper::getIncludePath('data/conversations_class.php'));
		require_once(PathHelper::getIncludePath('data/conversation_participants_class.php'));

		$out['unread_conversation_count'] = Conversation::get_unread_count($user->key);

		$recent_conversations = new MultiConversation(
			array('participant_user_id' => $user->key, 'deleted' => false),
			array(),
			3
		);
		$recent_conversations->load();

		$conversations_out = array();
		foreach ($recent_conversations as $cnv) {
			$other_user = $cnv->get_other_participant($user->key);
			$conversations_out[] = array(
				'conversation_id'    => (int)$cnv->key,
				'other_display_name' => $other_user ? $other_user->display_name() : 'Unknown',
				'preview'             => $cnv->latest_message_body ?? '',
				'last_message_time'   => $cnv->latest_message_time ?? null,
				'unread'              => empty($cnv->cnp_last_read_time) || ($cnv->latest_message_time ?? '') > $cnv->cnp_last_read_time,
			);
		}
		$out['recent_conversations'] = $conversations_out;
	}

	// ---------------------------------------------------------------
	// ORDERS (gated)
	// ---------------------------------------------------------------
	if ($settings->get_setting('products_active')) {
		$orders = new MultiOrder(
			array('user_id' => $user->key),
			array('ord_order_id' => 'DESC'),
			3
		);
		$orders->load();

		$orders_out = array();
		foreach ($orders as $order) {
			$orders_out[] = array(
				'order_id' => (int)$order->key,
				'total'    => $order->get('ord_total_cost'),
				'date'     => $order->get('ord_timestamp'),
			);
		}
		$out['recent_orders'] = $orders_out;

		// -----------------------------------------------------------
		// SUBSCRIPTIONS (gated within products)
		// -----------------------------------------------------------
		if ($settings->get_setting('subscriptions_active')) {
			require_once(PathHelper::getIncludePath('data/products_class.php'));
			require_once(PathHelper::getIncludePath('data/product_versions_class.php'));

			$subscriptions = new MultiOrderItem(
				array('user_id' => $user->key, 'is_subscription' => true),
				array('order_item_id' => 'DESC'),
				5
			);
			$subscriptions->load();

			$subs_out = array();
			foreach ($subscriptions as $sub) {
				$product = new Product($sub->get('odi_pro_product_id'), TRUE);
				$subs_out[] = array(
					'order_item_id' => (int)$sub->key,
					'product_name'  => $product ? $product->get('pro_name') : '',
					'price'         => $sub->get('odi_price'),
					'status'        => $sub->get('odi_subscription_cancelled_time') ? 'cancelled' : ($sub->get('odi_subscription_status') ?: 'active'),
				);
			}
			$out['subscriptions'] = $subs_out;

			$active_subs = new MultiOrderItem(array('user_id' => $user->key, 'is_active_subscription' => true));
			$out['active_subscription_count'] = $active_subs->count_all();
		}
	}

	// ---------------------------------------------------------------
	// MAILING LISTS
	// ---------------------------------------------------------------
	$user_subscribed_list = array();
	$user_lists = new MultiMailingListRegistrant(array('deleted' => false, 'user_id' => $user->key));
	$user_lists->load();
	foreach ($user_lists as $user_list) {
		$mailing_list = new MailingList($user_list->get('mlr_mlt_mailing_list_id'), TRUE);
		$user_subscribed_list[] = $mailing_list->get('mlt_name');
	}
	$out['mailing_lists'] = $user_subscribed_list;

	return LogicResult::render($out);
}

function profile_dashboard_logic_api() {
	return [
		'requires_session' => true,
		'description' => 'Member dashboard summary: user card, counts, pending surveys, and recent lists',
	];
}

?>
