<?php
/**
 * Admin: Webhooks — the list. See adm/logic/admin_webhooks_logic.php.
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('adm/logic/admin_webhooks_logic.php'));

$page_vars = process_logic(admin_webhooks_logic(array_merge($_GET, $_POST)));
$session = $page_vars['session'];
$tz = $session->get_timezone();
$summaries = $page_vars['summaries'];

$page = new AdminPage();
$page->admin_header(array(
	'menu-id'        => 'webhooks',
	'page_title'     => 'Webhooks',
	'readable_title' => 'Webhooks',
	'breadcrumbs'    => array('Webhooks' => ''),
	'session'        => $session,
));

$page->tableheader(array('Name', 'Sends to', 'Signals', 'Status', 'Last delivered', 'Waiting', 'Gave up'), array(
	'title'    => 'Webhooks',
	'altlinks' => array('Add webhook' => '/admin/admin_webhook_edit'),
));

foreach ($page_vars['receivers'] as $receiver) {
	$id = (int)$receiver->key;
	$summary = $summaries[$id] ?? array('waiting' => 0, 'given_up' => 0, 'last_delivered' => null);

	$host = (string)parse_url((string)$receiver->get('sgr_url'), PHP_URL_HOST);
	$signals = $receiver->wants_all_signals() ? 'All' : (string)count($receiver->signals());

	$page->disprow(array(
		'<a href="/admin/admin_webhook?sgr_signal_receiver_id=' . $id . '">'
			. htmlspecialchars((string)$receiver->get('sgr_name')) . '</a>',
		htmlspecialchars($host),
		$signals,
		$receiver->get('sgr_is_active') ? 'Active' : 'Paused',
		$summary['last_delivered']
			? htmlspecialchars(LibraryFunctions::convert_time($summary['last_delivered'], 'UTC', $tz, 'M j, g:i A'))
			: 'Never',
		(string)$summary['waiting'],
		(string)$summary['given_up'],
	));
}
$page->endtable();

$page->admin_footer();
?>
