<?php

	require_once(PathHelper::getIncludePath('includes/Activation.php'));

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('data/emails_class.php'));
	require_once(PathHelper::getIncludePath('includes/RecipientGroupProviderRegistry.php'));
	require_once(PathHelper::getIncludePath('data/groups_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	if($_REQUEST['op'] == 'remove'){
		$op = 'remove';
	}
	else{
		$op = 'add';
	}

	$email = new Email($_REQUEST['eml_email_id'], TRUE);

	$recipient_groups = $email->get_recipient_groups();

	if($_REQUEST['action'] == 'add_recipient'){
		//ADD A PROVIDER-RESOLVED RECIPIENT GROUP TO THE EMAIL
		$email->add_recipient_group($_POST['provider'], $_POST['reference_id'], $op);
		$returnurl = $session->get_return();
		header("Location: /admin/admin_email_recipients_modify?eml_email_id=".$email->key);
		exit();
	}
	else if($_REQUEST['action'] == 'remove'){
		$email_recipient_group = new EmailRecipientGroup($_POST['erg_email_recipient_group_id'], TRUE);
		$email_recipient_group->permanent_delete();
		$returnurl = $session->get_return();
		header("Location: /admin/admin_email_recipients_modify?eml_email_id=".$email->key);
		exit();
	}

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'emails-list',
		'breadcrumbs' => array(
			'Emails'=>'/admin/admin_emails',
			$email->get('eml_description')=>'/admin/admin_email?eml_email_id='.$email->key,
			$email->get('eml_subject') => '',
		),
		'session' => $session,
	)
	);

	if($email->get('eml_status') != Email::EMAIL_SENT && $email->get('eml_status') != Email::EMAIL_QUEUED){

		$headers = array("Recipients", "Count", "Action");

		$altlinks = array();
		 $box_vars =	array(
			'altlinks' => $altlinks,
			'title' => 'Recipients for "'. $email->get('eml_description'). '"'
		);
		$page->tableheader($headers, $box_vars);

		$total = 0;
		$total_unsubscribed = 0;
		$total_duplicates = 0;
		$recipient_list = array();
		foreach($recipient_groups as $recipient_group){

			$group_total = 0;
			$group_unsubscribed = 0;
			$rowvalues=array();

			$add_user_list = array();
			$label = '(none)';
			$provider = RecipientGroupProviderRegistry::get($recipient_group->get('erg_provider'));
			if($provider){
				$ref_id = (int)$recipient_group->get('erg_reference_id');
				$add_user_list = $provider->resolve($ref_id);
				$label = $provider->reference_label($ref_id);
			}

			$num_total = 0;
			foreach($add_user_list as $user_id){
				$user= new User($user_id, TRUE);
				if(!$user->is_unsubscribed_to_contact_type($email->get('eml_ctt_contact_type_id'))){
					$group_total++;
					$recipient_list[] = $user->key;
				}
				else{
					$group_unsubscribed++;
				}
				$num_total++;
			}
			$total += $nummembers;
			if($recipient_group->get('erg_operation') == 'add'){
				array_push($rowvalues, 'Add: '. $label);
				array_push($rowvalues, 'Users subscribed: '.$group_total . ', unsubscribed: '.$group_unsubscribed);
			}
			else{
				array_push($rowvalues, 'Excluded: '. $label);
				array_push($rowvalues, 'Users to exclude: '. $num_total);
			}

			$delform = AdminPage::action_button('Delete', '/admin/admin_email_recipients_modify', [
				'hidden'  => ['action' => 'remove', 'erg_email_recipient_group_id' => $recipient_group->key, 'eml_email_id' => $email->key],
				'confirm' => 'Remove this recipient group?',
			]);
			array_push($rowvalues, $delform);

			$page->disprow($rowvalues);

		}

		echo '<tr><td colspan="3">';

		// One form per registered recipient-group provider (group, event,
		// waiting list, …). Each posts a generic add_recipient action carrying
		// the provider key + the chosen reference id.
		$verb = ($op == 'add') ? 'Add' : 'Exclude';
		$form_index = 3;
		foreach(RecipientGroupProviderRegistry::all() as $provider){
			$formwriter = $page->getFormWriter('form'.$form_index, ['action' => '/admin/admin_email_recipients_modify', 'method' => 'POST']);
			$formwriter->begin_form();
			$formwriter->hiddeninput('action', '', ['value' => 'add_recipient']);
			$formwriter->hiddeninput('eml_email_id', '', ['value' => $email->key]);
			$formwriter->hiddeninput('op', '', ['value' => $op]);
			$formwriter->hiddeninput('provider', '', ['value' => $provider->key()]);
			$formwriter->dropinput('reference_id', $verb.' '.strtolower($provider->label()), [
				'options' => $provider->options(),
				'empty_option' => '-- Select --'
			]);
			echo $formwriter->submitbutton('btn_submit', $verb.' '.strtolower($provider->label()), ['class' => 'btn btn-primary']);
			$formwriter->end_form();
			$form_index++;
		}
		echo '</td></tr>';

		$page->endtable();

	}
	else{
		throw new SystemDisplayableError('This email has already been sent.  You cannot add or remove recipients.');
		exit();

	}

	$page->admin_footer();

?>
