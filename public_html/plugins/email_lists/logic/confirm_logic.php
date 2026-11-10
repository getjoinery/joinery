<?php
/**
 * /email_lists/confirm?t= — a mailing list confirmation link (spec
 * email_lists_plugin §10). Opening it shows the lists and a Confirm button;
 * only the button's POST subscribes. Mail scanners open links in incoming
 * mail, and a link that confirmed on a page view would confirm for them.
 *
 * @version 1.0
 */
function confirm_logic(array $input): LogicResult {
	$token = trim((string)($input['t'] ?? ''));
	$page_vars = array('token' => $token, 'state' => 'invalid', 'lists' => array());

	$rows = MailingListPending::for_token($token);
	if (!$rows) {
		$page_vars['message'] = 'This link has been used or has expired. If you pressed Confirm, you are subscribed; otherwise sign up again for a new link.';
		return LogicResult::render($page_vars);
	}
	foreach ($rows as $row) {
		$list = new MailingList($row->get('mlp_mlt_mailing_list_id'), TRUE);
		if ($list->key && !$list->get('mlt_delete_time')) {
			$page_vars['lists'][] = (string)$list->get('mlt_name');
		}
	}
	$page_vars['email'] = (string)$rows[0]->get('mlp_email');

	if (!LibraryFunctions::isFormSubmission()) {
		$page_vars['state'] = 'confirm';
		return LogicResult::render($page_vars);
	}

	$names = EmailListSubscriptions::confirm($token, (string)SessionControl::get_client_ip(true));
	if ($names === null) {
		$page_vars['message'] = 'This link has been used or has expired. If you pressed Confirm, you are subscribed; otherwise sign up again for a new link.';
		return LogicResult::render($page_vars);
	}
	$page_vars['state'] = 'done';
	$page_vars['lists'] = $names ?: $page_vars['lists'];
	return LogicResult::render($page_vars);
}
