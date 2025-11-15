<?php
	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('plugins/bookings/data/bookings_class.php'));
	require_once(PathHelper::getIncludePath('plugins/bookings/data/booking_types_class.php'));
	require_once(PathHelper::getIncludePath('data/products_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	if (isset($_REQUEST['bkn_booking_id'])) {
		$booking = new Booking($_REQUEST['bkn_booking_id'], TRUE);
	} else {
		$booking = new Booking(NULL);
	}

	if($_POST){

		if($_POST['bkn_usr_user_id_booked']){
			$booking->set('bkn_usr_user_id_booked', $_POST['bkn_usr_user_id_booked']);
		}
		else{
			$booking->set('bkn_usr_user_id_booked', NULL);
		}

		if($_POST['bkn_usr_user_id_client']){
			$booking->set('bkn_usr_user_id_client', $_POST['bkn_usr_user_id_client']);
		}
		else{
			$booking->set('bkn_usr_user_id_client', NULL);
		}

		if($_POST['bkn_notes']){
			$booking->set('bkn_notes', $_POST['bkn_notes']);
		}

		$editable_fields = array();

		foreach($editable_fields as $field) {
			$booking->set($field, $_POST[$field]);
		}

		if($_POST['bkn_start_time_date'] && $_POST['bkn_start_time_time']){
			$time_combined = $_POST['bkn_start_time_date'] . ' ' . LibraryFunctions::toDBTime($_POST['bkn_start_time_time']);
			$utc_time = LibraryFunctions::convert_time($time_combined, $booking->get('bkn_timezone'),  'UTC', 'c');
			$booking->set('bkn_start_time', $utc_time);
			$booking->set('bkn_start_time_local', $time_combined);
		}

		/*
		if($_POST['bkn_end_time_date'] && $_POST['bkn_end_time_time']){
			$time_combined = $_POST['bkn_end_time_date'] . ' ' . LibraryFunctions::toDBTime($_POST['bkn_end_time_time']);
			$utc_time = LibraryFunctions::convert_time($time_combined, $booking->get('bkn_timezone'),  'UTC', 'c');
			$booking->set('bkn_end_time', $utc_time);
			$booking->set('bkn_end_time_local', $time_combined);
		}
		*/
		$booking->prepare();
		$booking->save();
		$booking->load();

		LibraryFunctions::redirect('/admin/admin_booking?bkn_booking_id='.$booking->key);
		exit;
	}

	$breadcrumbs = array('Bookings'=>'/admin/admin_bookings');
	if ($booking->key) {
		$breadcrumbs += array('Booking '.$booking->get('bkn_name') => '/admin/admin_booking?bkn_booking_id='.$booking->key);
		$breadcrumbs += array('Booking Edit'=>'');
	}
	else{
		$breadcrumbs += array('New Booking' => '');
	}

	$title = $booking->get('bkn_name');
	$content = $booking->get('bkn_description');
	//LOAD THE ALTERNATE CONTENT VERSION IF NEEDED
	if($_GET['cnv_content_version_id']){
		$content_version = new ContentVersion($_GET['cnv_content_version_id'], TRUE);
		$content = $content_version->get('cnv_content');
		$title = $content_version->get('cnv_title');
	}

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'bookings',
		'page_title' => 'Edit Booking',
		'readable_title' => 'Edit Booking',
		'breadcrumbs' => $breadcrumbs,
		'session' => $session,
	)
	);

	$pageoptions['title'] = "Edit Booking";
	$page->begin_box($pageoptions);

	// Editing an existing booking
	$formwriter = $page->getFormWriter('form1', [
		'action' => '/admin/admin_booking_edit'
	]);

	$validation_rules = array();
	$validation_rules['bkn_name']['required']['value'] = 'true';
	$validation_rules['bkn_external_register_link']['minlength']['value'] = '5';

	$formwriter->begin_form();

	if($booking->key){
		$formwriter->hiddeninput('bkn_booking_id', $booking->key);
		$formwriter->hiddeninput('action', 'edit');
	}

	//echo $formwriter->textinput('Booking name', 'bkn_name', NULL, 100, $title, '', 255, '');

	/*
	$optionvals = array("Created"=>1, "Completed"=>2, "Cancelled"=>3);
	echo $formwriter->dropinput("Status", "bkn_status", "ctrlHolder", $optionvals, $booking->get('bkn_status'), '', FALSE);
	*/

	/*
	$booking_types = new MultiBookingType();
	$num_booking_types = $booking_types->count_all();
	if($num_booking_types){
		$booking_types->load();
		$optionvals = $booking_types->get_dropdown_array();
		echo $formwriter->dropinput("Type of booking", "bkn_ety_booking_type_id", "ctrlHolder", $optionvals, $booking->get('bkn_ety_booking_type_id'), '', FALSE);
	}
	*/
	$users = new MultiUser(array('deleted' => FALSE), array('last_name' => 'ASC'));
	$users->load();
	$optionvals = $users->get_dropdown_array();

	$formwriter->dropinput("bkn_usr_user_id_booked", "Booked User", [
		'options' => $optionvals,
		'value' => $booking->get('bkn_usr_user_id_booked'),
		'search_endpoint' => '/ajax/user_search_ajax'
	]);

	$formwriter->dropinput("bkn_usr_user_id_client", "Client", [
		'options' => $optionvals,
		'value' => $booking->get('bkn_usr_user_id_client'),
		'search_endpoint' => '/ajax/user_search_ajax'
	]);

	//echo $formwriter->datetimeinput('Booking time', 'bkn_time', 'ctrlHolder', LibraryFunctions::convert_time($booking->get('bkn_time'), 'UTC', $session->get_timezone(), 'Y-m-d h:ia'), '', '', '');

	 /*
	echo $formwriter->datetimeinput('Booking end time ('. ($booking->get('bkn_timezone') ? $booking->get('bkn_timezone') : 'local'). ' timezone)', 'bkn_end_time', 'ctrlHolder', LibraryFunctions::convert_time($booking->get('bkn_end_time_local'), $booking->get('bkn_timezone'), $booking->get('bkn_timezone'), 'Y-m-d h:ia'), '', '', '');
	 */

	$formwriter->textinput('bkn_notes', 'Notes', [
		'value' => $booking->get('bkn_notes'),
		'maxlength' => 255
	]);

	$formwriter->start_buttons();
	$formwriter->submitbutton('submit', 'Submit', ['class' => 'btn btn-primary']);
	$formwriter->end_buttons();

	$formwriter->end_form();

	$page->end_box();

	$page->admin_footer();

?>
