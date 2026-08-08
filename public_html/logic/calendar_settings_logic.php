<?php
/**
 * Calendar email settings (/profile/calendar_settings), also exposed as the
 * calendar_settings API action.
 *
 * Three per-user choices, stored in cpr_calendar_preferences (absence of a
 * row = everything off): summary frequency (none/daily/weekly), the local
 * hour summaries go out at, and the default reminder lead applied to entries
 * without their own override. The page form submits through
 * /api/v1/action/calendar_settings with action=save; the render branch feeds
 * the web view its current values.
 *
 * @version 1.1 - the read branch reports the site-wide send blocker
 *                (EmailSender::transactionalSendBlocker), so the page can say
 *                these emails cannot currently send instead of taking
 *                preferences for a dead letterbox
 */

require_once(__DIR__ . '/../includes/PathHelper.php');

function calendar_settings_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/calendar_preference_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$session->set_return();

	$user_id = $session->get_user_id();

	if (($input['action'] ?? '') === 'save') {
		$frequency = (string)($input['summary_frequency'] ?? 'none');
		if (!in_array($frequency, CalendarPreference::SUMMARY_FREQUENCIES, true)) {
			return LogicResult::error('Choose a valid summary option.');
		}
		$hour = (int)($input['summary_hour'] ?? 7);
		if ($hour < 0 || $hour > 23) {
			return LogicResult::error('Choose a valid send hour.');
		}
		$minutes = (int)($input['reminder_default_minutes'] ?? 0);
		if (!in_array($minutes, CalendarPreference::REMINDER_MINUTE_CHOICES, true)) {
			return LogicResult::error('Choose a valid reminder lead time.');
		}

		$pref = CalendarPreference::get_for($user_id);
		$pref->set('cpr_summary_frequency', $frequency);
		$pref->set('cpr_summary_hour', $hour);
		$pref->set('cpr_reminder_default_minutes', $minutes);
		$pref->set('cpr_update_time', gmdate('Y-m-d H:i:s'));
		$pref->save();

		return LogicResult::render(array('saved' => true));
	}

	// JSON-safe on purpose — this same branch answers the API read call.
	require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
	$pref = CalendarPreference::get_for($user_id);
	return LogicResult::render(array(
		'summary_frequency'        => $pref->get('cpr_summary_frequency') ?: 'none',
		'summary_hour'             => (int)$pref->get('cpr_summary_hour'),
		'reminder_default_minutes' => (int)$pref->get('cpr_reminder_default_minutes'),
		// Why calendar email cannot currently send site-wide, or null. The
		// standing rule: every UI that enables transactional mail surfaces
		// this verdict beside the switch.
		'send_blocker'             => EmailSender::transactionalSendBlocker(),
	));
}

function calendar_settings_logic_descriptor(): array {
	return [
		'description' => 'Read or save the signed-in member\'s calendar email preferences (summaries and reminder default).',
		'mutates'     => true,
		'auth'        => [
			'requires_session' => true,
		],
		'input'       => [
			'action'                   => ['type' => 'string', 'required' => false, 'enum' => ['save'], 'label' => 'Pass "save" to write; omit to read'],
			'summary_frequency'        => ['type' => 'string', 'required' => false, 'enum' => ['none', 'daily', 'weekly'], 'label' => 'Summary emails'],
			'summary_hour'             => ['type' => 'int',    'required' => false, 'label' => 'Local hour (0-23) summaries are sent'],
			'reminder_default_minutes' => ['type' => 'int',    'required' => false, 'label' => 'Default reminder lead in minutes (0 = off; 60, 30, 15, or 5)'],
		],
	];
}

?>
