<?php
/**
 * admin_notification_preferences_logic.php
 *
 * Logic for the admin notification preferences page. Each signal shows what
 * the admin actually gets — a saved choice, or what delivery does with none —
 * through Notify::preference_states(), and saves through
 * Notify::save_preferences(), the same rules the member page uses.
 *
 * See docs/signals.md and docs/notifications.md.
 *
 * @version 1.3
 */

function admin_notification_preferences_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/Notify.php'));
	require_once(PathHelper::getIncludePath('data/scheduled_tasks_class.php'));

	$settings = Globalvars::get_instance();
	$session  = SessionControl::get_instance();

	$session->check_permission(5);
	$session->set_return();

	$page_vars = array();
	$page_vars['settings'] = $settings;
	$page_vars['session']  = $session;

	$user_id = $session->get_user_id();
	$signals = Notify::notifiable_signals();

	if (isset($input['action']) && $input['action'] === 'save') {
		$subscribed = (isset($input['subscribe']) && is_array($input['subscribe']))
			? $input['subscribe'] : array();
		$emails = (isset($input['notify_email']) && is_array($input['notify_email']))
			? $input['notify_email'] : array();
		Notify::save_preferences($user_id, $signals, $subscribed, $emails);
		return LogicResult::redirect('/admin/admin_notification_preferences');
	}

	$page_vars['notifiable_signals'] = $signals;
	$page_vars['prefs']              = Notify::preference_states($user_id, $signals);

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
