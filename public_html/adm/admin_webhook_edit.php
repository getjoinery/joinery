<?php
/**
 * Admin: add or edit a webhook. See adm/logic/admin_webhook_edit_logic.php.
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('adm/logic/admin_webhook_edit_logic.php'));

$page_vars = process_logic(admin_webhook_edit_logic(array_merge($_GET, $_POST)));
$session = $page_vars['session'];
$receiver = $page_vars['receiver'];
$catalog = $page_vars['catalog'];
$title = $receiver->key ? 'Edit webhook' : 'Add webhook';

$breadcrumbs = array('Webhooks' => '/admin/admin_webhooks');
if ($receiver->key) {
	$breadcrumbs[(string)$receiver->get('sgr_name')] = '/admin/admin_webhook?sgr_signal_receiver_id=' . (int)$receiver->key;
}
$breadcrumbs[$title] = '';

$page = new AdminPage();
$page->admin_header(array(
	'menu-id'        => 'webhooks',
	'page_title'     => $title,
	'readable_title' => $title,
	'breadcrumbs'    => $breadcrumbs,
	'session'        => $session,
));

// Every signal this site can raise, by category, plus any saved name whose
// plugin is off right now so it stays chosen.
$options = array();
foreach ($catalog as $name => $meta) {
	$options[$name] = ($meta['category'] ?? 'Other') . ' — ' . ($meta['label'] ?? $name);
}
asort($options);
$chosen = $receiver->wants_all_signals() ? array() : $receiver->signals();
foreach ($chosen as $name) {
	if (!isset($options[$name])) {
		$options[$name] = $name . ' (not available on this site now)';
	}
}

$page->begin_box(array('title' => $title));
$formwriter = $page->getFormWriter('form1', array('model' => $receiver));
$formwriter->begin_form();

$formwriter->textinput('sgr_name', 'Name', array('required' => true, 'placeholder' => 'What receives these, e.g. CRM'));
$formwriter->textinput('sgr_url', 'URL', array('required' => true, 'placeholder' => 'https://'));
$formwriter->radioinput('send', 'Send', array(
	'options' => array('all' => 'Every signal', 'some' => 'Only the signals chosen below'),
	'value'   => $receiver->wants_all_signals() ? 'all' : 'some',
	'visibility_rules' => array(
		'all'  => array('hide' => array('signals')),
		'some' => array('show' => array('signals')),
	),
));
$formwriter->checkboxList('signals', 'Signals', array(
	'options' => $options,
	'checked' => $chosen,
));
$formwriter->checkboxinput('sgr_is_active', 'Active', array(
	'checked' => (bool)$receiver->get('sgr_is_active'),
));

$formwriter->submitbutton('btn_save', $receiver->key ? 'Save' : 'Add webhook');
$formwriter->end_form();
$page->end_box();

$page->admin_footer();
?>
