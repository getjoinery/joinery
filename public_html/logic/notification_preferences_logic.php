<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

/**
 * Member notification preferences (/profile/notification_preferences).
 *
 * Lists the targeted signals — the ones delivered to a member because an
 * action of theirs produced them (supports_topic false) — and lets the member
 * mute each or opt into an email copy. Topic-subscription signals (operator
 * alerts like new sales and signups) stay on the admin page
 * (/admin/admin_notification_preferences).
 *
 * Saves explicit rows: for a targeted signal the absence of a row means the
 * platform default (notify in-app; email per the signal's default_email), so
 * a mute must be materialized as subscribed=false rather than a missing row.
 *
 * @version 1.0
 */
function notification_preferences_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/Notify.php'));
	require_once(PathHelper::getIncludePath('data/notification_preferences_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$session->set_return();

	$user_id = $session->get_user_id();
	$signals = member_notifiable_signals();

	if (($input['action'] ?? '') === 'save') {
		$subscribed = (isset($input['subscribe']) && is_array($input['subscribe'])) ? $input['subscribe'] : array();
		$emails     = (isset($input['notify_email']) && is_array($input['notify_email'])) ? $input['notify_email'] : array();
		foreach ($signals as $signal_name => $meta) {
			$sub   = in_array($signal_name, $subscribed, true);
			$email = $sub && in_array($signal_name, $emails, true);
			$pref = NotificationPreference::get_for($user_id, $signal_name);
			if (!$pref) {
				$pref = new NotificationPreference(NULL);
				$pref->set('ntp_usr_user_id', $user_id);
				$pref->set('ntp_signal_name', $signal_name);
			}
			$pref->set('ntp_subscribed', $sub);
			$pref->set('ntp_email_enabled', $email);
			$pref->save();
		}
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

	// Current state: an explicit row wins; no row means the signal's default.
	$prefs = array();
	$multi = new MultiNotificationPreference(array('user_id' => $user_id, 'deleted' => false));
	$multi->load();
	foreach ($multi as $pref) {
		$prefs[$pref->get('ntp_signal_name')] = array(
			'subscribed' => (bool)$pref->get('ntp_subscribed'),
			'email'      => (bool)$pref->get('ntp_email_enabled'),
		);
	}

	$page_vars = array(
		'session' => $session,
		'signals' => $signals,
		'prefs'   => $prefs,
	);
	$page_vars['display_messages'] = $session->get_messages($_SERVER['REQUEST_URI']);
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
