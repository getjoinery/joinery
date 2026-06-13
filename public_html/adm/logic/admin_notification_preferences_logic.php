<?php
/**
 * admin_notification_preferences_logic.php
 *
 * Logic for the admin notification preferences page. The per-user load/save
 * helpers (notification_preferences_load / notification_preferences_save) are
 * page-object-agnostic — they take a user id and have no dependency on
 * AdminPage — so a future user-facing /profile/notifications view can reuse
 * them unchanged.
 *
 * See docs/signals.md and docs/notifications.md.
 *
 * @version 1.2
 */

function admin_notification_preferences_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/Notify.php'));
	require_once(PathHelper::getIncludePath('data/notification_preferences_class.php'));
	require_once(PathHelper::getIncludePath('data/scheduled_tasks_class.php'));

	$settings = Globalvars::get_instance();
	$session  = SessionControl::get_instance();

	$session->check_permission(5);
	$session->set_return();

	$page_vars = array();
	$page_vars['settings'] = $settings;
	$page_vars['session']  = $session;

	$user_id = $session->get_user_id();

	if (isset($input['action']) && $input['action'] === 'save') {
		$subscribed = (isset($input['subscribe']) && is_array($input['subscribe']))
			? $input['subscribe'] : array();
		$emails = (isset($input['notify_email']) && is_array($input['notify_email']))
			? $input['notify_email'] : array();
		notification_preferences_save($user_id, $subscribed, $emails);
		return LogicResult::redirect('/admin/admin_notification_preferences');
	}

	$page_vars['notifiable_signals'] = Notify::notifiable_signals();
	$page_vars['prefs']              = notification_preferences_load($user_id);

	// Check whether the email delivery pipeline is operational.
	$send_queued = new MultiScheduledTask(array('task_class' => 'SendQueuedEmails', 'active' => true, 'deleted' => false));
	$page_vars['send_queued_active'] = $send_queued->count_all() > 0;

	$last_cron_run = $settings->get_setting('scheduled_tasks_last_cron_run');
	$cron_is_active = false;
	if ($last_cron_run) {
		$last_run_dt = new DateTime($last_cron_run, new DateTimeZone('UTC'));
		$now_dt      = new DateTime('now', new DateTimeZone('UTC'));
		$cron_is_active = ($now_dt->getTimestamp() - $last_run_dt->getTimestamp()) < 1800;
	}
	$page_vars['cron_is_active'] = $cron_is_active;

	return LogicResult::render($page_vars);
}

/**
 * Load a user's notification preferences as signal_name => array(subscribed, email).
 * Page-object-agnostic — reusable by any preferences UI.
 */
function notification_preferences_load($user_id) {
	require_once(PathHelper::getIncludePath('data/notification_preferences_class.php'));

	$prefs = array();
	$multi = new MultiNotificationPreference(array('user_id' => $user_id, 'deleted' => false));
	$multi->load();
	foreach ($multi as $pref) {
		$prefs[$pref->get('ntp_signal_name')] = array(
			'subscribed' => (bool)$pref->get('ntp_subscribed'),
			'email'      => (bool)$pref->get('ntp_email_enabled'),
		);
	}
	return $prefs;
}

/**
 * Save a user's notification preferences. $subscribed_signals and $email_signals
 * are arrays of signal names. One NotificationPreference row per (user, signal)
 * — at most one row per notifiable signal. Page-object-agnostic.
 */
function notification_preferences_save($user_id, $subscribed_signals, $email_signals) {
	require_once(PathHelper::getIncludePath('includes/Notify.php'));
	require_once(PathHelper::getIncludePath('data/notification_preferences_class.php'));

	foreach (Notify::notifiable_signals() as $signal_name => $meta) {
		$subscribed = in_array($signal_name, $subscribed_signals, true);
		$email      = $subscribed && in_array($signal_name, $email_signals, true);

		$pref = NotificationPreference::get_for($user_id, $signal_name);
		if (!$pref) {
			if (!$subscribed) {
				continue;  // absence of a row already means "not subscribed"
			}
			$pref = new NotificationPreference(NULL);
			$pref->set('ntp_usr_user_id', $user_id);
			$pref->set('ntp_signal_name', $signal_name);
		}
		$pref->set('ntp_subscribed', $subscribed);
		$pref->set('ntp_email_enabled', $email);
		$pref->save();
	}
}
