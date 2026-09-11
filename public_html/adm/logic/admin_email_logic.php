<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_email_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/Activation.php'));
	require_once(PathHelper::getIncludePath('data/emails_class.php'));
	require_once(PathHelper::getIncludePath('includes/RecipientGroupProviderRegistry.php'));
	require_once(PathHelper::getIncludePath('data/email_recipient_groups_class.php'));
	require_once(PathHelper::getIncludePath('data/groups_class.php'));
	require_once(PathHelper::getIncludePath('data/mailing_lists_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	$email = new Email($input['eml_email_id'], TRUE);

	// Handle actions
	if($input['action'] == 'delete'){
		$email->assert_can_write($session);
		//REMOVE THE RECIPIENTS
		EmailRecipient::DeleteAll($email->key);
		$email->soft_delete();

		return LogicResult::redirect('/admin/admin_emails');
	}
	else if($input['action'] == 'undelete'){
		$email->assert_can_write($session);
		$email->undelete();

		return LogicResult::redirect('/admin/admin_emails');
	}
	else if($input['action'] == 'unqueue'){
		$email->set('eml_status', Email::EMAIL_CREATED);
		$email->assert_can_write($session);
		$email->save();
		//REMOVE THE RECIPIENTS
		EmailRecipient::DeleteAll($email->key);

		return LogicResult::redirect('/admin/admin_emails');
	}

	if($input['action'] == 'add_recipient'){
		//ADD A PROVIDER-RESOLVED RECIPIENT GROUP TO THE EMAIL
		$email->add_recipient_group($input['provider'], $input['reference_id']);
		$returnurl = $session->get_return();
		return LogicResult::redirect($returnurl);
	}
	else if($input['action'] == 'remove'){
		$email_recipient_group = new EmailRecipientGroup($input['erg_email_recipient_group_id'], TRUE);
		$email_recipient_group->permanent_delete();
		$returnurl = $session->get_return();
		return LogicResult::redirect($returnurl);
	}

	// Load recipients if email is sent/queued
	$recipients = null;
	$numrecords = 0;
	$pager = null;

	if($email->get('eml_status') == Email::EMAIL_SENT || $email->get('eml_status') == Email::EMAIL_QUEUED){
		$numperpage = 50;
		$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
		$sort = LibraryFunctions::fetch_variable('sort', 'email', 0, '');
		$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');

		$search_criteria = array('email_id' => $email->key);

		$recipients = new MultiEmailRecipient(
			$search_criteria,
			array($sort=>$sdirection),
			$numperpage,
			$offset);
		$numrecords = $recipients->count_all();
		$recipients->load();

		$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
	}

	$page_vars = array(
		'session' => $session,
		'email' => $email,
		'recipients' => $recipients,
		'numrecords' => $numrecords,
		'pager' => $pager,
	);

	return LogicResult::render($page_vars);
}
