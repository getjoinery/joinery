<?php
/** @joinery-admin class=keys */
/**
 * Admin: one webhook. See adm/logic/admin_webhook_logic.php.
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('adm/logic/admin_webhook_logic.php'));

$page_vars = process_logic(admin_webhook_logic(array_merge($_GET, $_POST)));
$session = $page_vars['session'];
$tz = $session->get_timezone();
$receiver = $page_vars['receiver'];
$catalog = $page_vars['catalog'];
$here = $page_vars['here'];
$id = (int)$receiver->key;
$name = (string)$receiver->get('sgr_name');
$active = (bool)$receiver->get('sgr_is_active');

$page = new AdminPage();
$page->admin_header(array(
	'menu-id'        => 'webhooks',
	'page_title'     => 'Webhook: ' . $name,
	'readable_title' => $name,
	'breadcrumbs'    => array('Webhooks' => '/admin/admin_webhooks', $name => ''),
	'session'        => $session,
	'header_action'  => '<a class="btn btn-soft-default btn-sm" href="/admin/admin_webhook_edit?sgr_signal_receiver_id=' . $id . '">Edit</a>',
));

$page->begin_box(array('title' => 'Settings'));
if ($receiver->wants_all_signals()) {
	$signals_html = 'Every signal';
} else {
	$labels = array();
	foreach ($receiver->signals() as $signal) {
		$labels[] = htmlspecialchars(isset($catalog[$signal]['label']) ? $catalog[$signal]['label'] . ' (' . $signal . ')' : $signal . ' (not available on this site now)');
	}
	$signals_html = implode('<br>', $labels);
}
echo '<table class="table table-sm" style="max-width: 800px;"><tbody>';
echo '<tr><th>Sends to</th><td><code>' . htmlspecialchars((string)$receiver->get('sgr_url')) . '</code></td></tr>';
echo '<tr><th>Signals</th><td>' . $signals_html . '</td></tr>';
echo '<tr><th>Status</th><td>' . ($active ? 'Active' : 'Paused') . '</td></tr>';
echo '</tbody></table>';
echo '<div>';
echo AdminPage::action_button('Send a test message', $here, array('hidden' => array('action' => 'test'))) . ' ';
echo AdminPage::action_button($active ? 'Pause' : 'Resume', $here, array('hidden' => array('action' => $active ? 'pause' : 'resume'))) . ' ';
echo AdminPage::action_button('Delete', $here, array(
	'hidden'        => array('action' => 'delete'),
	'model'         => $receiver,
	'verb'          => 'permanent_delete',
	'confirm'       => 'Delete this webhook and its delivery history?',
	'confirm_label' => 'Delete',
	'confirm_style' => 'danger',
));
echo '</div>';
$page->end_box();

$page->begin_box(array('title' => 'Signing secret'));
if ($page_vars['secret'] === null) {
	echo '<p>The stored secret can no longer be read, so nothing is being sent. Make a new one and put it in the receiving app.</p>';
} else {
	echo '<details><summary>Show</summary><p><code>' . htmlspecialchars($page_vars['secret']) . '</code></p></details>';
}
echo '<div>' . AdminPage::action_button('Make a new secret', $here, array(
	'hidden'  => array('action' => 'new_secret'),
	'confirm' => 'The receiving app will reject messages until it has the new secret. Make a new one?',
)) . '</div>';
$page->end_box();

$pager = new Pager(array('numrecords' => $page_vars['numrecords'], 'numperpage' => $page_vars['numperpage']));
$page->tableheader(array('Signal', 'Raised', 'Status', 'Attempts', 'Last result', 'Next try'), array(
	'title'         => 'Deliveries',
	'filteroptions' => $page_vars['statuses'],
), $pager);

foreach ($page_vars['deliveries'] as $delivery) {
	$status = (string)$delivery->get('sgd_status');

	$signal_html = htmlspecialchars((string)$delivery->get('sgd_signal'));
	if ($status !== SignalDelivery::STATUS_WITHHELD) {
		$payload = $delivery->get_json_decoded('sgd_payload');
		$signal_html = '<details><summary>' . $signal_html . '</summary><pre style="white-space: pre-wrap">'
			. htmlspecialchars(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
			. '</pre></details>';
	}

	$status_html = htmlspecialchars(SignalDelivery::status_label($status));
	if ($status === SignalDelivery::STATUS_DELIVERED && $delivery->get('sgd_delivered_time')) {
		$status_html .= '<br><small>' . htmlspecialchars(LibraryFunctions::convert_time($delivery->get('sgd_delivered_time'), 'UTC', $tz, 'M j, g:i A')) . '</small>';
	} elseif ($status === SignalDelivery::STATUS_WITHHELD) {
		$status_html .= '<br><small>Raised while sealed content was open, so it was not recorded or sent.</small>';
	} elseif ($status === SignalDelivery::STATUS_FAILED) {
		$status_html .= '<br>' . AdminPage::action_button('Send again', $here, array(
			'hidden' => array('action' => 'retry', 'sgd_signal_delivery_id' => (int)$delivery->key),
		));
	}

	$result = '';
	if ($delivery->get('sgd_last_attempt_time')) {
		$code = $delivery->get('sgd_last_response_code');
		$error = (string)$delivery->get('sgd_last_error');
		$result = $error !== '' ? htmlspecialchars($error) : 'HTTP ' . (int)$code;
		$result .= '<br><small>' . htmlspecialchars(LibraryFunctions::convert_time($delivery->get('sgd_last_attempt_time'), 'UTC', $tz, 'M j, g:i A')) . '</small>';
	}

	$next = '';
	if ($status === SignalDelivery::STATUS_PENDING && $delivery->get('sgd_next_attempt_time')) {
		$next = htmlspecialchars(LibraryFunctions::convert_time($delivery->get('sgd_next_attempt_time'), 'UTC', $tz, 'M j, g:i A'));
		if (!$active) {
			$next .= '<br><small>Held while paused</small>';
		}
	}

	$page->disprow(array(
		$signal_html,
		htmlspecialchars(LibraryFunctions::convert_time($delivery->get('sgd_create_time'), 'UTC', $tz, 'M j, g:i A')),
		$status_html,
		(int)$delivery->get('sgd_attempts') . ' of ' . SignalDelivery::max_attempts(),
		$result,
		$next,
	));
}
$page->endtable($pager);

$page->admin_footer();
?>
