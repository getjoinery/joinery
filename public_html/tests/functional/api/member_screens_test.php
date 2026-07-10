<?php
/**
 * Mobile Native Member Screens — server actions functional test suite
 * (specs/mobile_native_member_screens.md).
 *
 * Covers: payload shape and auth requirement for every new action, settings
 * gating on the dashboard payload, owner scoping on orders/events, and the
 * conversation participant authorization boundary (a non-participant is
 * denied read/send/mute/delete — written first per the spec's explicit
 * instruction, since this is the load-bearing check).
 *
 * USAGE (CLI only):
 *   php tests/functional/api/member_screens_test.php [base_url] [origin_ip]
 *
 * Creates its own users, keys, orders, events, and conversations, and
 * removes them afterwards (LIFO via harness_register_row).
 */

/** @joinery-test
 * name: api_member_screens
 * tier: db
 * env: dev-only
 * needs: []
 */
require_once(__DIR__ . '/api_test_harness.php');
require_once(PathHelper::getIncludePath('plugins/event_manager/data/events_class.php'));
require_once(PathHelper::getIncludePath('plugins/event_manager/data/event_registrants_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/orders_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/order_items_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));
require_once(PathHelper::getIncludePath('data/conversations_class.php'));
require_once(PathHelper::getIncludePath('data/conversation_participants_class.php'));

api_test_boot($argv);
$settings = Globalvars::get_instance();

function session_login($email, $password) {
	$r = api_request('POST', '/api/v1/auth/login', array(), array(
		'email' => $email, 'password' => $password,
	));
	$pub = $r['json']['data']['public_key'] ?? null;
	if ($pub === null) {
		throw new Exception('Login failed: ' . $r['raw']);
	}
	$key_row = ApiKey::GetByColumn('apk_public_key', $pub);
	if ($key_row && $key_row->key) {
		harness_register_key_id($key_row->key);
	}
	return key_headers($pub, $r['json']['data']['secret_key']);
}

try {
	$suffix = strtoupper(LibraryFunctions::random_string(6));
	echo "Base URL: $BASE_URL\nTest suffix: $suffix\n";

	// ------------------------------------------------------------------
	section('Setup');
	$user_a = make_user($suffix . 'A');
	$user_b = make_user($suffix . 'B');
	$password_a = 'TestPassword_' . $suffix . 'A';
	$password_b = 'TestPassword_' . $suffix . 'B';
	$headers_a = session_login($user_a->get('usr_email'), $password_a);
	$headers_b = session_login($user_b->get('usr_email'), $password_b);
	echo "  Created users " . $user_a->key . " (A), " . $user_b->key . " (B)\n";

	$new_actions = array(
		'profile_dashboard', 'store/order_list', 'store/subscription_summary', 'event_manager/my_events',
		'conversation_list', 'conversation_thread', 'security_overview',
		'conversation_send', 'conversation_action',
	);

	// ------------------------------------------------------------------
	section('Auth: every new action requires a session credential');
	foreach ($new_actions as $action) {
		// No key headers and no session cookie: 400, per ApiAuth::authenticateBrowserSession
		// ("no oracle for whether sessions are accepted" — see includes/ApiAuth.php).
		$r = api_request('POST', '/api/v1/action/' . $action, array(), array());
		check($r['status'] === 400, "$action: 400 without any credentials", 'got ' . $r['status']);
	}

	// ------------------------------------------------------------------
	section('profile_dashboard: payload shape + settings gating');
	$r = api_request('POST', '/api/v1/action/profile_dashboard', $headers_a, array());
	check($r['status'] === 200, 'profile_dashboard 200', $r['raw']);
	$data = $r['json']['data'] ?? array();
	check(($data['user']['email'] ?? null) === $user_a->get('usr_email'), 'user card has the caller\'s email');
	check(array_key_exists('upcoming_events', $data), 'upcoming_events key present');
	check(array_key_exists('recent_conversations', $data), 'recent_conversations key present when messaging_active is on');

	$was_messaging_active = get_setting_raw('messaging_active');
	set_setting_raw('messaging_active', '0');
	try {
		$r = api_request('POST', '/api/v1/action/profile_dashboard', $headers_a, array());
		$data = $r['json']['data'] ?? array();
		check(!array_key_exists('recent_conversations', $data), 'recent_conversations key omitted when messaging_active is off');
	} finally {
		set_setting_raw('messaging_active', $was_messaging_active);
	}

	// ------------------------------------------------------------------
	section('order_list: owner scoping + pagination');
	$order_a1 = new Order(NULL);
	$order_a1->set('ord_usr_user_id', $user_a->key);
	$order_a1->set('ord_total_cost', 10.00);
	$order_a1->set('ord_timestamp', gmdate('Y-m-d H:i:s'));
	$order_a1->save();
	harness_register_row('ord_orders', 'ord_order_id', $order_a1->key);

	$order_a2 = new Order(NULL);
	$order_a2->set('ord_usr_user_id', $user_a->key);
	$order_a2->set('ord_total_cost', 20.00);
	$order_a2->set('ord_timestamp', gmdate('Y-m-d H:i:s'));
	$order_a2->save();
	harness_register_row('ord_orders', 'ord_order_id', $order_a2->key);

	$order_b1 = new Order(NULL);
	$order_b1->set('ord_usr_user_id', $user_b->key);
	$order_b1->set('ord_total_cost', 99.00);
	$order_b1->set('ord_timestamp', gmdate('Y-m-d H:i:s'));
	$order_b1->save();
	harness_register_row('ord_orders', 'ord_order_id', $order_b1->key);

	$r = api_request('POST', '/api/v1/action/store/order_list', $headers_a, array());
	check($r['status'] === 200, 'order_list 200', $r['raw']);
	$data = $r['json']['data'] ?? array();
	check(($data['total_count'] ?? null) === 2, 'user A sees exactly their 2 orders', json_encode($data['total_count'] ?? null));
	$seen_ids = array_column($data['orders'] ?? array(), 'order_id');
	check(!in_array((int)$order_b1->key, $seen_ids, true), 'user A\'s order list does not include user B\'s order');

	$r = api_request('POST', '/api/v1/action/store/order_list', $headers_b, array());
	$data = $r['json']['data'] ?? array();
	check(($data['total_count'] ?? null) === 1, 'user B sees exactly their 1 order');

	// ------------------------------------------------------------------
	section('subscription_summary: settings gating');
	$was_subscriptions_active = get_setting_raw('subscriptions_active');
	set_setting_raw('subscriptions_active', '0');
	try {
		$r = api_request('POST', '/api/v1/action/store/subscription_summary', $headers_a, array());
		check(!empty($r['json']['error']), 'subscription_summary errors when subscriptions_active is off');
	} finally {
		set_setting_raw('subscriptions_active', $was_subscriptions_active);
	}
	$r = api_request('POST', '/api/v1/action/store/subscription_summary', $headers_a, array());
	check($r['status'] === 200, 'subscription_summary 200 when enabled', $r['raw']);
	check(array_key_exists('active_subscriptions', $r['json']['data'] ?? array()), 'active_subscriptions key present');

	// ------------------------------------------------------------------
	section('my_events: owner scoping + status filter');
	$event = new Event(NULL);
	$event->set('evt_name', 'Test Event ' . $suffix);
	$event->set('evt_status', Event::STATUS_ACTIVE);
	$event->save();
	harness_register_row('evt_events', 'evt_event_id', $event->key);

	$registrant_a = new EventRegistrant(NULL);
	$registrant_a->set('evr_evt_event_id', $event->key);
	$registrant_a->set('evr_usr_user_id', $user_a->key);
	$registrant_a->save();
	harness_register_row('evr_event_registrants', 'evr_event_registrant_id', $registrant_a->key);

	$r = api_request('POST', '/api/v1/action/event_manager/my_events', $headers_a, array('status' => 'active'));
	check($r['status'] === 200, 'my_events 200', $r['raw']);
	$data = $r['json']['data'] ?? array();
	$names = array_column($data['registrations'] ?? array(), 'event_name');
	check(in_array($event->get('evt_name'), $names, true), 'user A sees their active registration under status=active');

	$r = api_request('POST', '/api/v1/action/event_manager/my_events', $headers_a, array('status' => 'completed'));
	$data = $r['json']['data'] ?? array();
	$names = array_column($data['registrations'] ?? array(), 'event_name');
	check(!in_array($event->get('evt_name'), $names, true), 'active registration excluded under status=completed');

	$r = api_request('POST', '/api/v1/action/event_manager/my_events', $headers_b, array('status' => 'all'));
	$data = $r['json']['data'] ?? array();
	$names = array_column($data['registrations'] ?? array(), 'event_name');
	check(!in_array($event->get('evt_name'), $names, true), 'user B does not see user A\'s registration');

	// ------------------------------------------------------------------
	section('Conversations: cross-user denial (load-bearing)');
	$user_c = make_user($suffix . 'C');
	$password_c = 'TestPassword_' . $suffix . 'C';
	$headers_c = session_login($user_c->get('usr_email'), $password_c);

	$conversation = Conversation::create_conversation(array($user_a->key, $user_b->key));
	// Registered parent-first so LIFO teardown deletes children (messages,
	// then participants) before the conversation row itself.
	harness_register_row('cnv_conversations', 'cnv_conversation_id', $conversation->key);
	$participants = new MultiConversationParticipant(array('conversation_id' => $conversation->key));
	$participants->load();
	foreach ($participants as $p) {
		harness_register_row('cnp_conversation_participants', 'cnp_conversation_participant_id', $p->key);
	}

	$message = $conversation->add_message($user_a->key, 'Hello from A, suffix ' . $suffix);
	harness_register_row('msg_messages', 'msg_message_id', $message->key);

	$r = api_request('POST', '/api/v1/action/conversation_thread', $headers_c, array('conversation_id' => $conversation->key));
	check(!empty($r['json']['error']), 'non-participant C is denied reading the thread', $r['raw']);

	$r = api_request('POST', '/api/v1/action/conversation_send', $headers_c, array('conversation_id' => $conversation->key, 'body' => 'intrusion'));
	check(!empty($r['json']['error']), 'non-participant C is denied sending into the thread', $r['raw']);

	$r = api_request('POST', '/api/v1/action/conversation_action', $headers_c, array('conversation_id' => $conversation->key, 'action' => 'mute'));
	check(!empty($r['json']['error']), 'non-participant C is denied muting the conversation', $r['raw']);

	$r = api_request('POST', '/api/v1/action/conversation_action', $headers_c, array('conversation_id' => $conversation->key, 'action' => 'delete'));
	check(!empty($r['json']['error']), 'non-participant C is denied deleting the conversation', $r['raw']);

	// ------------------------------------------------------------------
	section('Conversations: participant round trip');
	$r = api_request('POST', '/api/v1/action/conversation_list', $headers_a, array());
	check($r['status'] === 200, 'conversation_list 200 for participant A', $r['raw']);
	$conv_ids = array_column($r['json']['data']['conversations'] ?? array(), 'conversation_id');
	check(in_array((int)$conversation->key, $conv_ids, true), 'A\'s inbox includes the conversation');

	$r = api_request('POST', '/api/v1/action/conversation_thread', $headers_b, array('conversation_id' => $conversation->key));
	check($r['status'] === 200, 'conversation_thread 200 for participant B', $r['raw']);
	$bodies = array_column($r['json']['data']['messages'] ?? array(), 'body');
	check(in_array('Hello from A, suffix ' . $suffix, $bodies, true), 'B sees A\'s message');
	check(($r['json']['data']['messages'][0]['is_mine'] ?? null) === false, 'message is not flagged is_mine for B');

	$r = api_request('POST', '/api/v1/action/conversation_send', $headers_b, array('conversation_id' => $conversation->key, 'body' => 'Reply from B, suffix ' . $suffix));
	check($r['status'] === 200, 'conversation_send 200 for participant B', $r['raw']);
	if (!empty($r['json']['data']['message_id'])) {
		harness_register_row('msg_messages', 'msg_message_id', $r['json']['data']['message_id']);
	}

	$r = api_request('POST', '/api/v1/action/conversation_action', $headers_a, array('conversation_id' => $conversation->key, 'action' => 'mute'));
	check($r['status'] === 200, 'participant A can mute the conversation', $r['raw']);

	$r = api_request('POST', '/api/v1/action/conversation_thread', $headers_a, array('conversation_id' => $conversation->key));
	check(($r['json']['data']['is_muted'] ?? null) === true, 'mute state persisted for A');

	// Compose-mode dedup: `to` resolves to the existing conversation, not a new one.
	$r = api_request('POST', '/api/v1/action/conversation_thread', $headers_a, array('to' => $user_b->key));
	check(($r['json']['data']['conversation_id'] ?? null) === (int)$conversation->key, 'compose dedup finds the existing A/B conversation');

	// ------------------------------------------------------------------
	section('security_overview: payload shape + is_current');
	$r = api_request('POST', '/api/v1/action/security_overview', $headers_a, array());
	check($r['status'] === 200, 'security_overview 200', $r['raw']);
	$data = $r['json']['data'] ?? array();
	check($data['totp_enabled'] === false, 'fresh user has totp disabled');
	check(($data['vault_active'] ?? null) === false, 'fresh user has no vault');
	$current = null;
	foreach ($data['app_sessions'] ?? array() as $s) {
		if (!empty($s['is_current'])) $current = $s;
	}
	check($current !== null, 'app_sessions includes an is_current row for the calling session key');

} finally {
	echo "\n== Teardown ==\n";

	// The "no credentials" auth loop above counts as failed-auth attempts
	// against the shared api_auth rate limiter — remove them so this run does
	// not lock out this IP for other test suites (same pattern as
	// session_keys_test.php).
	$db = DbConnector::get_instance()->get_db_link();
	$q = $db->prepare("DELETE FROM rql_request_logs
		WHERE rql_feature = 'api_auth' AND rql_was_success = FALSE AND rql_create_time >= ?");
	$q->execute([$TEST_START_UTC]);
	echo "  Removed " . $q->rowCount() . " failed-auth log rows from this run\n";

	harness_teardown_data();
}

harness_finish();
