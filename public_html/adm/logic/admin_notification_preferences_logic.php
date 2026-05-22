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
 * See specs/notification_hooks.md.
 *
 * @version 1.0
 */

function admin_notification_preferences_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/Notify.php'));
	require_once(PathHelper::getIncludePath('data/notification_preferences_class.php'));

	$settings = Globalvars::get_instance();
	$session  = SessionControl::get_instance();

	$session->check_permission(5);
	$session->set_return();

	$page_vars = array();
	$page_vars['settings'] = $settings;
	$page_vars['session']  = $session;

	$user_id = $session->get_user_id();

	if (isset($post_vars['action']) && $post_vars['action'] === 'save') {
		$subscribed = (isset($post_vars['subscribe']) && is_array($post_vars['subscribe']))
			? $post_vars['subscribe'] : array();
		$emails = (isset($post_vars['notify_email']) && is_array($post_vars['notify_email']))
			? $post_vars['notify_email'] : array();
		notification_preferences_save($user_id, $subscribed, $emails);
		return LogicResult::redirect('/admin/admin_notification_preferences');
	}

	$page_vars['hook_points'] = Notify::hook_points();
	$page_vars['prefs']       = notification_preferences_load($user_id);

	return LogicResult::render($page_vars);
}

/**
 * Load a user's notification preferences as hook_point => array(subscribed, email).
 * Page-object-agnostic — reusable by any preferences UI.
 */
function notification_preferences_load($user_id) {
	require_once(PathHelper::getIncludePath('data/notification_preferences_class.php'));

	$prefs = array();
	$multi = new MultiNotificationPreference(array('user_id' => $user_id, 'deleted' => false));
	$multi->load();
	foreach ($multi as $pref) {
		$prefs[$pref->get('ntp_hook_point')] = array(
			'subscribed' => (bool)$pref->get('ntp_subscribed'),
			'email'      => (bool)$pref->get('ntp_email_enabled'),
		);
	}
	return $prefs;
}

/**
 * Save a user's notification preferences. $subscribed_hooks and $email_hooks
 * are arrays of hook point names. One NotificationPreference row per (user,
 * hook point) — at most one row per declared hook point. Page-object-agnostic.
 */
function notification_preferences_save($user_id, $subscribed_hooks, $email_hooks) {
	require_once(PathHelper::getIncludePath('includes/Notify.php'));
	require_once(PathHelper::getIncludePath('data/notification_preferences_class.php'));

	foreach (Notify::hook_points() as $hook_name => $meta) {
		$subscribed = in_array($hook_name, $subscribed_hooks, true);
		$email      = $subscribed && in_array($hook_name, $email_hooks, true);

		$pref = NotificationPreference::get_for($user_id, $hook_name);
		if (!$pref) {
			if (!$subscribed) {
				continue;  // absence of a row already means "not subscribed"
			}
			$pref = new NotificationPreference(NULL);
			$pref->set('ntp_usr_user_id', $user_id);
			$pref->set('ntp_hook_point', $hook_name);
		}
		$pref->set('ntp_subscribed', $subscribed);
		$pref->set('ntp_email_enabled', $email);
		$pref->save();
	}
}
