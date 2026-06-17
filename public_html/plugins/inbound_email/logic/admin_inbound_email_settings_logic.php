<?php
require_once(__DIR__ . '/../../../includes/PathHelper.php');

/**
 * Logic for the Inbound Email Settings tab.
 *
 * Server-wide DELIVERY POLICY — spam filtering, forwarding limits, the
 * forwarded-From display, and retention/storage caps. These are distinct from
 * the Setup tab, which owns provisioning and server identity (provider, mail
 * hostname/IP, SRS, the relay, and the health run). One POST saves the whole
 * form; values are read back fresh on the redirect.
 *
 * @version 1.0
 */
function admin_inbound_email_settings_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/settings_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$settings = Globalvars::get_instance();

	$base = '/plugins/inbound_email/admin/admin_inbound_email_settings';

	// The settings this page owns. Booleans render as checkboxes; integers as
	// number inputs clamped to a sensible floor.
	$bool_keys = array(
		'inbound_email_spam_filtering_enabled',
		'inbound_email_from_show_via',
	);
	$int_keys = array(
		'inbound_email_forwarding_max_destinations'      => 1,
		'inbound_email_forwarding_rate_limit_per_alias'  => 0,
		'inbound_email_forwarding_rate_limit_per_domain' => 0,
		'inbound_email_forwarding_rate_limit_window'     => 1,
		'inbound_email_log_retention_days'               => 0,
		'inbound_email_mailbox_retention_days'           => 0,
		'inbound_email_mailbox_max_per_window'           => 0,
	);

	if (!empty($input['save_settings'])) {
		// Unchecked checkboxes are absent from the POST → '0'.
		foreach ($bool_keys as $k) {
			inbound_email_settings_write_setting($k, empty($input[$k]) ? '0' : '1');
		}
		foreach ($int_keys as $k => $min) {
			$value = max($min, intval($input[$k] ?? 0));
			inbound_email_settings_write_setting($k, (string)$value);
		}
		$session->save_message(new DisplayMessage(
			'Settings saved.', 'Saved', '/plugins/inbound_email/admin/',
			DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
		return LogicResult::redirect($base);
	}

	// Current values for the form.
	$values = array();
	foreach ($bool_keys as $k) {
		$values[$k] = (string)$settings->get_setting($k) === '1';
	}
	foreach ($int_keys as $k => $min) {
		$values[$k] = intval($settings->get_setting($k));
	}

	return LogicResult::render(array(
		'session' => $session,
		'base'    => $base,
		'values'  => $values,
	));
}

/**
 * Upsert a single stg_settings row by name (the same model path Setup uses —
 * there is no set_setting()). A missing row is created.
 */
if (!function_exists('inbound_email_settings_write_setting')) {
	function inbound_email_settings_write_setting(string $name, string $value): void {
		$existing = new MultiSetting(array('setting_name' => $name));
		$existing->load();
		if (count($existing)) {
			$setting = $existing->get(0);
		} else {
			$setting = new Setting(NULL);
			$setting->set('stg_name', $name);
		}
		$setting->set('stg_value', $value);
		$setting->save();
	}
}
?>
