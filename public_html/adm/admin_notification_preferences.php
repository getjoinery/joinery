<?php
/**
 * admin_notification_preferences.php
 *
 * Admin page where an admin opts in to notification hook point alerts and
 * chooses which should also be emailed.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('adm/logic/admin_notification_preferences_logic.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

$page_vars = process_logic(admin_notification_preferences_logic($_GET, $_POST));

$session     = $page_vars['session'];
$settings    = $page_vars['settings'];
$hook_points = $page_vars['hook_points'];
$prefs       = $page_vars['prefs'];

$page = new AdminPage();
$page->admin_header(array(
	'menu-id'        => 'notification-preferences',
	'page_title'     => 'Notification Preferences',
	'readable_title' => 'Notification Preferences',
	'breadcrumbs'    => array('Notification Preferences' => ''),
	'session'        => $session,
));

// Build the checkbox option list (value => "Category — Label"), and the
// currently-checked sets, from the merged hook point declarations.
$options            = array();
$subscribed_checked = array();
$email_checked      = array();

foreach ($hook_points as $name => $meta) {
	$category = isset($meta['category']) ? $meta['category'] : 'Other';
	$label    = isset($meta['label']) ? $meta['label'] : $name;
	$options[$name] = $category . ' — ' . $label;

	if (isset($prefs[$name]) && $prefs[$name]['subscribed']) {
		$subscribed_checked[] = $name;
	}
	if (isset($prefs[$name]) && $prefs[$name]['email']) {
		$email_checked[] = $name;
	}
}
asort($options);

$page->begin_box(array('title' => 'Admin Alerts'));

echo '<p>Choose which site events you want to be alerted about. Alerts appear in '
	. 'your notifications; tick the same event under "Also email me" to additionally '
	. 'receive an email when it happens.</p>';

if (empty($options)) {
	echo '<p>No notification hook points are declared.</p>';
} else {
	$formwriter = $page->getFormWriter('form1');
	$formwriter->begin_form();
	$formwriter->hiddeninput('action', array('value' => 'save'));
	$formwriter->checkboxList('subscribe', 'Alert me about', array(
		'options' => $options,
		'checked' => $subscribed_checked,
	));
	$formwriter->checkboxList('notify_email', 'Also email me about', array(
		'options' => $options,
		'checked' => $email_checked,
	));
	$formwriter->submitbutton('submit', 'Save Preferences');
	$formwriter->end_form();
}

$page->end_box();

$page->admin_footer();
