<?php
/** @joinery-test
 * name: task_config_select
 * tier: db
 * env: any
 * needs: []
 * timeout: 60
 */
/**
 * A scheduled task's config field of type select takes its choices the way a
 * setting does: `options`, or `options_from` naming a static method (spec
 * email_lists_plugin G8). No task field type is tied to one feature's table.
 *
 * Run: php tests/run.php db --filter=task_config_select
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

section('options and options_from resolve');
check(SettingsDeclarations::resolveOptions(array('type' => 'select', 'options' => array('a' => 'A'))) === array('a' => 'A'),
	'a literal options map is used as is');
check(SettingsDeclarations::resolveOptions(array('type' => 'select', 'options_from' => 'CoreSettingOptions::emailServices')) !== array(),
	'options_from calls the named method');

section('The weekly digest picks its list through options_from');
$json = json_decode(file_get_contents(PathHelper::getIncludePath('plugins/event_manager/tasks/WeeklyEventsDigest.json')), true);
$field = $json['config_fields']['mailing_list_id'] ?? array();
check(($field['type'] ?? '') === 'select' && ($field['options_from'] ?? '') === 'MailingList::select_options',
	'mailing_list_id is a select with options_from', json_encode($field));
if (class_exists('MailingList')) {
	check(is_array(SettingsDeclarations::resolveOptions($field)), 'and it resolves while lists exist');
}

section('No feature-specific field type is left in the page');
$page = file_get_contents(PathHelper::getIncludePath('adm/admin_scheduled_tasks.php'));
check(strpos($page, "'mailing_list'") === false && strpos($page, '$mailing_lists') === false,
	'admin_scheduled_tasks knows no mailing_list type');

harness_finish();
