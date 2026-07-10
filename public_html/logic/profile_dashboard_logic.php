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

	// Upcoming events + pending surveys are contributed by the event_manager
	// profile-dashboard providers via the registry loop below.

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
	// ---------------------------------------------------------------
	// PLUGIN-CONTRIBUTED SECTIONS
	//   store:         recent_orders + subscriptions
	//   event_manager: upcoming_events + pending_surveys
	// Each active plugin registers its providers from serve.php; with a plugin
	// inactive nothing is contributed and its keys are simply absent — a client
	// renders strictly from present keys. Each section's items are serialized to
	// their raw native `data` payloads; each stat becomes a top-level count key.
	// ---------------------------------------------------------------
	require_once(PathHelper::getIncludePath('includes/ProfileDashboardRegistry.php'));
	foreach (ProfileDashboardRegistry::sections($user) as $section) {
		$out[$section->id] = array_map(function ($i) { return $i->data; }, $section->items);
		if ($section->stat) {
			$out[$section->stat->key] = $section->stat->count;
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
