<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

/**
 * Member notification preferences (/profile/notification_preferences).
 *
 * Lists the targeted signals — the ones delivered to a member because an
 * action of theirs produced them (supports_topic false) — and lets the member
 * mute each, opt into an email copy, or keep it off push. Topic-subscription signals (operator
 * alerts like new sales and signups) stay on the admin page
 * (/admin/admin_notification_preferences).
 *
 * With no saved row a targeted signal is on (email per the signal's
 * default_email); Notify::preference_states() and Notify::save_preferences()
 * hold that rule for this page and the admin page alike.
 *
 * @version 1.2 - a push choice per signal
 */
function notification_preferences_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/Notify.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$session->set_return();

	$user_id = $session->get_user_id();
	$signals = member_notifiable_signals();

	if (($input['action'] ?? '') === 'save') {
		$subscribed = (isset($input['subscribe']) && is_array($input['subscribe'])) ? $input['subscribe'] : array();
		$emails     = (isset($input['notify_email']) && is_array($input['notify_email'])) ? $input['notify_email'] : array();
		$pushes     = (isset($input['notify_push']) && is_array($input['notify_push'])) ? $input['notify_push'] : array();
		Notify::save_preferences($user_id, $signals, $subscribed, $emails, $pushes);
		$session->save_message(new DisplayMessage(
			'Your notification preferences have been saved.',
			'Preferences saved',
			'/\/profile\/notification_preferences.*/',
			DisplayMessage::MESSAGE_ANNOUNCEMENT,
			DisplayMessage::MESSAGE_DISPLAY_IN_PAGE,
			'notifybox',
			TRUE
		));
		return LogicResult::redirect('/profile/notification_preferences');
	}

	$page_vars = array(
		'session' => $session,
		'signals' => $signals,
		'prefs'   => Notify::preference_states($user_id, $signals),
	);
	return LogicResult::render($page_vars);
}

/**
 * The notifiable signals a member controls: targeted deliveries (no topic
 * subscription), e.g. mail import progress. Keyed by signal name.
 */
function member_notifiable_signals() {
	require_once(PathHelper::getIncludePath('includes/Notify.php'));
	$member = array();
	foreach (Notify::notifiable_signals() as $name => $meta) {
		if (empty($meta['notify']['supports_topic'])) {
			$member[$name] = $meta;
		}
	}
	return $member;
}
