<?php
/** @joinery-admin class=personal */
/**
 * admin_outbox — every email the site sent on its own behalf: who it went to,
 * its subject, the template and the record it was about, and what became of
 * it (specs/email_outbox.md §6). Read-only. The body is never shown, and once
 * an email is sent it is not kept.
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('adm/logic/admin_outbox_logic.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/Pager.php'));

$session = SessionControl::get_instance();
$session->set_return();

$page_vars = process_logic(admin_outbox_logic($_GET));
$filters = $page_vars['filters'];

$page = new AdminPage();
$page->admin_header(array(
	'menu-id' => 'email-outbox',
	'page_title' => 'Outbox',
	'readable_title' => 'Outbox',
	'breadcrumbs' => array('Outbox' => ''),
	'session' => $session,
));

echo '<p>Every email this site sends on its own behalf: receipts, notices, reminders, sign-in and reset emails, alerts. '
	. 'A failed email is retried up to three times. Mail a person sends from their own mailbox, campaigns and direct '
	. 'messages keep their own records and are not listed. '
	. (($keep = (int)Globalvars::get_instance()->get_setting('email_outbox_retention_days')) > 0
		? 'Finished lines are kept for ' . $keep . ' days.' : 'Finished lines are kept indefinitely.') . '</p>';

$self = '/admin/admin_outbox';
$form = $page->getFormWriter('filters', array('method' => 'GET', 'action' => $self));
$form->begin_form();
$form->textinput('address', 'Address', array('value' => $filters['address']));
$status_options = array('' => '-- Any --');
foreach (QueuedEmail::$status_to_text as $code => $label) {
	$status_options[(string)$code] = $label;
}
$form->dropinput('status', 'Status', array('options' => $status_options, 'value' => $filters['status']));
$template_options = array('' => '-- Any --');
foreach ($page_vars['templates'] as $t) {
	$template_options[$t] = $t;
}
$form->dropinput('template', 'Template', array('options' => $template_options, 'value' => $filters['template']));
$type_options = array('' => '-- Any --');
foreach ($page_vars['types'] as $t) {
	$type_options[$t] = $t;
}
$form->dropinput('entity_type', 'About (table)', array('options' => $type_options, 'value' => $filters['entity_type']));
$form->textinput('entity_id', 'About (id)', array('value' => $filters['entity_id']));
$form->dateinput('since', 'From', array('value' => $filters['since']));
$form->dateinput('until', 'To', array('value' => $filters['until']));
$form->submitbutton('submit_button', 'Filter');
$form->end_form();

$headers = array('Time', 'To', 'Subject', 'Template', 'About', 'Status', 'Service', 'Error');
$pager = new Pager(array('numrecords' => $page_vars['numrecords'], 'numperpage' => $page_vars['numperpage']));
$page->tableheader($headers, array('title' => 'Outbox'), $pager);

$h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
foreach ($page_vars['emails'] as $email) {
	$recipients = $email->get_json_decoded('equ_recipients');
	$to = array();
	if (is_array($recipients)) {
		foreach (array('to' => '', 'cc' => 'Cc ', 'bcc' => 'Bcc ') as $list => $label) {
			foreach ((array)($recipients[$list] ?? array()) as $r) {
				$to[] = $label . $h($r['email'] ?? '');
			}
		}
	}
	if (!$to) {
		$to[] = $h($email->get('equ_to'));
	}
	$when = $email->get('equ_sent_time') ?: $email->get('equ_timestamp');
	$about = $email->get('equ_entity_type')
		? $h($email->get('equ_entity_type')) . ' #' . (int)$email->get('equ_entity_id')
			. ($email->get('equ_once_key') ? '<br><small class="muted">once: ' . $h($email->get('equ_once_key')) . '</small>' : '')
		: '';
	$page->disprow(array(
		LibraryFunctions::convert_time($when, 'UTC', $session->get_timezone(), 'M j, Y g:i a'),
		implode('<br>', $to),
		$h($email->get('equ_subject')),
		$h($email->get('equ_template')),
		$about,
		$h($email->get_status()) . ((int)$email->get('equ_retry_count') > 0
			? '<br><small class="muted">' . (int)$email->get('equ_retry_count') . ' retr' . ((int)$email->get('equ_retry_count') === 1 ? 'y' : 'ies') . '</small>' : ''),
		$h($email->get('equ_service')),
		$h($email->get('equ_error')),
	));
}

$page->endtable($pager);
$page->admin_footer();
