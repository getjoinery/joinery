<?php

	require_once(PathHelper::getIncludePath('includes/Activation.php'));

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('data/emails_class.php'));
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

	if($_REQUEST['action'] == 'addgroup'){
		//ADD GROUP TO EMAIL
		$email->add_recipient_group(NULL, $_POST['grp_group_id'], $op);
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
	else if($_REQUEST['action'] == 'addevent'){
		//ADD GROUP TO EMAIL
		$email->add_recipient_group($_POST['evt_event_id'], NULL, $op);
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
			if($recipient_group->get('erg_grp_group_id')){
				$group = new Group($recipient_group->get('erg_grp_group_id'), TRUE);
				$members = $group->get_member_list();
				foreach($members as $member){
					$add_user_list[] = $member->get('grm_foreign_key_id');
				}
				$label = $group->get('grp_name');
			}
			else if($recipient_group->get('erg_evt_event_id')){
				$event = new Event($recipient_group->get('erg_evt_event_id'), TRUE);
				$event_registrants = new MultiEventRegistrant(array('event_id' => $recipient_group->get('erg_evt_event_id'), 'expired' => false), NULL);
				//$numregistrants = $event_registrants->count_all();
				$event_registrants->load();
				foreach($event_registrants as $event_registrant){
					$add_user_list[] = $event_registrant->get('evr_usr_user_id');
				}
				$label = $event->get('evt_name');
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

			$delform = '<form id="form2" class="form2" name="form2" method="POST" action="/admin/admin_email_recipients_modify?eml_email_id='.$email->key.'">
			<input type="hidden" class="hidden" name="action" id="action" value="remove" />
			<input type="hidden" class="hidden" name="erg_email_recipient_group_id" id="erg_email_recipient_group_id" value="'.$recipient_group->key.'" />
			<button type="submit">Delete</button>
			</form>';
			array_push($rowvalues, $delform);

			$page->disprow($rowvalues);

		}

		echo '<tr><td colspan="3">';
		$formwriter = $page->getFormWriter('form3', 'v2');
		$formwriter->begin_form('form3', 'POST', '/admin/admin_email_recipients_modify');

		$groups = new MultiGroup(
			array('category'=>'user', 'deleted'=>false),
			array('group_name' => 'ASC'),		//SORT BY => DIRECTION
			NULL,  //NUM PER PAGE
			NULL);  //OFFSET
		$groups->load();

		$optionvals = $groups->get_dropdown_array();
		$formwriter->hiddeninput('action', '', ['value' => 'addgroup']);
		$formwriter->hiddeninput('eml_email_id', '', ['value' => $email->key]);
		$formwriter->hiddeninput('op', '', ['value' => $op]);
		if($op == 'add'){
			$formwriter->dropinput('grp_group_id', 'Add group members', [
				'options' => $optionvals,
				'empty_option' => '-- Select --'
			]);
			echo $formwriter->new_form_button('Add group members');
		}
		else{
			$formwriter->dropinput('grp_group_id', 'Exclude group members', [
				'options' => $optionvals,
				'empty_option' => '-- Select --'
			]);
			echo $formwriter->new_form_button('Exclude group members');
		}
		$formwriter->end_form();

		$events = new MultiEvent(
			array(),  //SEARCH
			array('start_time' => 'DESC'),		//SORT BY => DIRECTION
			NULL,  //NUM PER PAGE
			NULL);  //OFFSET
		$events->load();

		$formwriter = $page->getFormWriter('form4', 'v2');
		$formwriter->begin_form('form4', 'POST', '/admin/admin_email_recipients_modify');
		$optionvals = $events->get_dropdown_array();

		$formwriter->hiddeninput('action', '', ['value' => 'addevent']);
		$formwriter->hiddeninput('eml_email_id', '', ['value' => $email->key]);
		$formwriter->hiddeninput('op', '', ['value' => $op]);
		if($op == 'add'){
			$formwriter->dropinput('evt_event_id', 'Add event attendees', [
				'options' => $optionvals,
				'empty_option' => '-- Select --'
			]);
			echo $formwriter->new_form_button('Add event attendees');
		}
		else{
			$formwriter->dropinput('evt_event_id', 'Exclude event attendees', [
				'options' => $optionvals,
				'empty_option' => '-- Select --'
			]);
			echo $formwriter->new_form_button('Exclude event attendees');
		}
		$formwriter->end_form();
		echo '</td></tr>';

		$page->endtable();

	}
	else{
		throw new SystemDisplayableError('This email has already been sent.  You cannot add or remove recipients.');
		exit();

	}

	$page->admin_footer();

?>
