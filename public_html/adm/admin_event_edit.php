<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/events_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/products_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/files_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	if (isset($_REQUEST['evt_event_id'])) {
		$event = new Event($_REQUEST['evt_event_id'], TRUE);
	} else {
		$event = new Event(NULL);
	}

	if($_POST){
		
		
		if($_POST['evt_short_description']){
				$_POST['evt_short_description'] = LibraryFunctions::ToUTF8($_POST['evt_short_description']);
		}
		
		if($_POST['evt_description']){
				$_POST['evt_description'] = LibraryFunctions::ToUTF8($_POST['evt_description']);
		}
		
		if(!$event->get('evt_link')){
			$event->set('evt_link', $event->create_url());
		}
		
		if($_POST['evt_fil_file_id']){
			$event->set('evt_fil_file_id', (int)$_POST['evt_fil_file_id']);
		}
		else if(empty($_POST['evt_fil_file_id'])){		
			$event->set('evt_fil_file_id', NULL);
		}		
			
		if($_POST['evt_usr_user_id_leader']){
			$event->set('evt_usr_user_id_leader', $_POST['evt_usr_user_id_leader']);
		}
		else{
			$event->set('evt_usr_user_id_leader', NULL);
		}		

		if($_POST['evt_after_purchase_message']){
			$event->set('evt_after_purchase_message', $_POST['evt_after_purchase_message']);		
		}
		
		if($_POST['evt_max_signups'] == '' || $_POST['evt_max_signups'] == 0 || $_POST['evt_max_signups'] == NULL){
			$event->set('evt_max_signups', NULL);	
		}	
		else{
			$event->set('evt_max_signups', (int)$_POST['evt_max_signups']);
		}	
		
		if($_POST['evt_is_accepting_signups'] && !$_POST['evt_external_register_link']){
			//CHECK THAT THERE IS AN ASSOCIATED PRODUCT
			$products = new MultiProduct(array('event_id'=> $event->key));
			$numproducts = $products->count_all();
			if(!$numproducts){
				throw new SystemDisplayableError('You cannot turn on registration for an event without attaching a product or an external register link.');
				exit();
			}
		}
		
		$editable_fields = array('evt_name', 'evt_description', 'evt_private_info', 'evt_short_description', 'evt_location', 'evt_external_register_link', 'evt_is_accepting_signups', 'evt_visibility', 'evt_timezone', 'evt_picture_link', 'evt_status', 'evt_allow_waiting_list', 'evt_session_display_type', 'evt_collect_extra_info', 'evt_show_add_to_calendar_link', 'evt_type');

		foreach($editable_fields as $field) {
			$event->set($field, $_REQUEST[$field]);
		}
		
		

		if($_POST['evt_start_time_date'] && $_POST['evt_start_time_time']){
			//$time_combined = $_POST['evt_start_time_date'] . ' ' . LibraryFunctions::toDBTime($_POST['evt_start_time_time']);
			$time_combined = $_POST['evt_start_time_date'] . ' ' . LibraryFunctions::toDBTime($_POST['evt_start_time_time']);
			$utc_time = LibraryFunctions::convert_time($time_combined, $event->get('evt_timezone'),  'UTC', 'c');
			$event->set('evt_start_time', $utc_time);
		}
		
		if($_POST['evt_end_time_date'] && $_POST['evt_end_time_time']){
			$time_combined = $_POST['evt_end_time_date'] . ' ' . LibraryFunctions::toDBTime($_POST['evt_end_time_time']);
			$utc_time = LibraryFunctions::convert_time($time_combined, $event->get('evt_timezone'),  'UTC', 'c');
			$event->set('evt_end_time', $utc_time);		
		}
		
		$event->prepare();
		$event->save();
		$event->load();
		
		LibraryFunctions::redirect('/admin/admin_event?evt_event_id='.$event->key);
		exit;
	}

	$breadcrumbs = array('Events'=>'/admin/admin_events');
	if ($event->key) {
		$breadcrumbs += array('Event '.$event->get('evt_name') => '/admin/admin_event?evt_event_id='.$event->key);
		$breadcrumbs += array('Event Edit'=>'');
	}
	else{
		$breadcrumbs += array('New Event' => '');
	}
	
	$title = $event->get('evt_name');
	$content = $event->get('evt_description');
	//LOAD THE ALTERNATE CONTENT VERSION IF NEEDED
	if($_GET['cnv_content_version_id']){
		$content_version = new ContentVersion($_GET['cnv_content_version_id'], TRUE);
		$content = $content_version->get('cnv_content');
		$title = $content_version->get('cnv_title');
	}	
	
	

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 2,
		'page_title' => 'Edit Event',
		'readable_title' => 'Edit Event',
		'breadcrumbs' => $breadcrumbs,
		'session' => $session,
	)
	);
	
	$pageoptions['title'] = "Edit Event";
	$page->begin_box($pageoptions);
	
	echo '<div uk-grid>
    <div class="uk-width-2-3@m"><div style="padding: 20px">';


	// Editing an existing event
	$formwriter = new FormWriterMaster('form1');
	
	$validation_rules = array();
	$validation_rules['evt_name']['required']['value'] = 'true';
	echo $formwriter->set_validate($validation_rules);		
	
	
	echo $formwriter->begin_form('form1', 'POST', '/admin/admin_event_edit');

	if($event->key){
		echo $formwriter->hiddeninput('evt_event_id', $event->key);
		echo $formwriter->hiddeninput('action', 'edit');
	}
	
	echo $formwriter->textinput('Event name', 'evt_name', NULL, 100, $title, '', 255, '');
	//$optionvals = array("Online Course"=>1, "Retreat"=>2);
	//echo $formwriter->dropinput("Event type", "evt_type", "ctrlHolder", $optionvals, $event->get('evt_type'), '', FALSE);

	$files = new MultiFile(
		array('deleted'=>false, 'picture'=>true),
		array('file_id' => 'DESC'),		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$files->load();
	$optionvals = $files->get_image_dropdown_array();
	echo $formwriter->imageinput("Main image", "evt_fil_file_id", "ctrlHolder", $optionvals, $event->get('evt_fil_file_id'), '', TRUE, TRUE, FALSE, TRUE);	
	
	
	//echo $formwriter->textinput('Picture link', 'evt_picture_link', NULL, 100, $event->get('evt_picture_link'), '', 255, '');
	
	echo $formwriter->textinput('Event location', 'evt_location', NULL, 100, $event->get('evt_location'), '', 255, '');

	echo $formwriter->textinput('Max signups (number)', 'evt_max_signups', NULL, 100, $event->get('evt_max_signups'), '', 255, '');


	echo $formwriter->textbox('Event short description (no html)', 'evt_short_description', 'ctrlHolder', 5, 80, $event->get('evt_short_description'), '', 'no');
	echo $formwriter->textinput('External register link (if needed)', 'evt_external_register_link', NULL, 100, $event->get('evt_external_register_link'), '', 255, '');

		
	$users = new MultiGroupMember(
		array(
			'group_id' => 27,
		),
		NULL,
		NULL,
		NULL);
	$users->load();
	$optionvals = $users->get_user_dropdown_array();

	echo $formwriter->dropinput('Led by', 'evt_usr_user_id_leader', 'ctrlHolder', $optionvals, $event->get('evt_usr_user_id_leader'), '', TRUE);
	
	$optionvals = Address::get_timezone_drop_array();
	echo $formwriter->dropinput("Event Time Zone", "evt_timezone", "ctrlHolder", $optionvals, $event->get('evt_timezone'), '', FALSE);	

	
	
	$optionvals = array("Active"=>1, "Completed"=>2, "Cancelled"=>3);
	echo $formwriter->dropinput("Status", "evt_status", "ctrlHolder", $optionvals, $event->get('evt_status'), '', FALSE);	
	
	$optionvals = array("Live Online"=>1, "Self Paced Online"=>2, "Retreat"=>3, "In Person"=>4);
	echo $formwriter->dropinput("Type of event", "evt_type", "ctrlHolder", $optionvals, $event->get('evt_type'), '', FALSE);	
	 
	$optionvals = array("Hidden"=>0, "Live"=>1, "Live but unlisted"=>2);
	echo $formwriter->dropinput("Visibility", "evt_visibility", "ctrlHolder", $optionvals, $event->get('evt_visibility'), '', FALSE);


	$optionvals = array("Closed"=>0, "Open"=>1);
	echo $formwriter->dropinput("Registration", "evt_is_accepting_signups", "ctrlHolder", $optionvals, $event->get('evt_is_accepting_signups'), '', FALSE);
	
	$optionvals = array("Allow"=>1, "Prevent"=>0);
	echo $formwriter->dropinput("Waiting list", "evt_allow_waiting_list", "ctrlHolder", $optionvals, $event->get('evt_allow_waiting_list'), '', FALSE);
	
	$optionvals = array("Show"=>1, "Hide"=>0);
	echo $formwriter->dropinput("Show calendar link", "evt_show_add_to_calendar_link", "ctrlHolder", $optionvals, $event->get('evt_show_add_to_calendar_link'), '', FALSE);
	
	/*
	$optionvals = array("On"=>1, "Off"=>0);
	echo $formwriter->dropinput("Pre event survey", "evt_collect_extra_info", "ctrlHolder", $optionvals, $event->get('evt_collect_extra_info'), '', FALSE);
	*/
	echo $formwriter->hiddeninput('evt_collect_extra_info', '0');
	
	$optionvals = array("Condensed (all on one page)"=>1, "Separate (separate pages for each session)"=>2);
	echo $formwriter->dropinput("Session display style", "evt_session_display_type", "ctrlHolder", $optionvals, $event->get('evt_session_display_type'), '', FALSE);
		

	echo $formwriter->datetimeinput('Event start time ('. ($event->get('evt_timezone') ? $event->get('evt_timezone') : 'local') . ' timezone)', 'evt_start_time', 'ctrlHolder', LibraryFunctions::convert_time($event->get('evt_start_time'), 'UTC', $event->get('evt_timezone'), 'Y-m-d h:ia'), '', '', '');

	 
	echo $formwriter->datetimeinput('Event end time ('. ($event->get('evt_timezone') ? $event->get('evt_timezone') : 'local'). ' timezone)', 'evt_end_time', 'ctrlHolder', LibraryFunctions::convert_time($event->get('evt_end_time'), 'UTC', $event->get('evt_timezone'), 'Y-m-d h:ia'), '', '', '');

	//echo $formwriter->textinput('Max attendees:', 'evt_max_purchase_count', 'ctrlHolder', 100, $event->get('evt_max_purchase_count'), '', 255, '');

	echo $formwriter->textbox('Event Description', 'evt_description', 'ctrlHolder', 10, 80, $content, '', 'yes');
	//echo $formwriter->textbox('After Purchase Message', 'evt_after_purchase_message', 'ctrlHolder', 10, 80, $event->get('evt_after_purchase_message'), '', 'no');

	echo $formwriter->textbox('Info only for registrants', 'evt_private_info', 'ctrlHolder', 10, 80, $event->get('evt_private_info'), '', 'yes');
 
	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();

	echo $formwriter->end_form();


	echo '	</div>
	</div>
	<div class="uk-width-1-3@m"><div style="padding: 20px">';

	$content_versions = new MultiContentVersion(
		array('type'=>ContentVersion::TYPE_EVENT, 'foreign_key_id' => $event->key),
		array('create_time' => 'DESC'),		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$content_versions->load();
	
	$optionvals = $content_versions->get_dropdown_array(FALSE, $session);

	if(count($optionvals)){
		$formwriter = new FormWriterMaster('form_load_version');
		echo $formwriter->begin_form('form_load_version', 'GET', '/admin/admin_event_edit');
		echo $formwriter->hiddeninput('pst_post_id', $event->key);
		echo $formwriter->dropinput("Load another description", "cnv_content_version_id", "ctrlHolder", $optionvals, NULL, '', TRUE);
		echo $formwriter->new_form_button('Load');	
		echo $formwriter->end_form();
	}
	else{
		echo 'No saved versions.';
	}
	
	echo '	</div>
	</div>
	</div>';

	$page->end_box();

	$page->admin_footer();

?>
