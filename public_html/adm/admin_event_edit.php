<?php

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('adm/logic/admin_event_edit_logic.php'));

$page_vars = process_logic(admin_event_edit_logic($_GET, $_POST));
extract($page_vars);

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

$page = new AdminPage();
$page->admin_header(
array(
	'menu-id'=> 'all-events',
	'page_title' => 'Edit Event',
	'readable_title' => 'Edit Event',
	'breadcrumbs' => $breadcrumbs,
	'session' => $session,
)
);

$pageoptions['title'] = "Edit Event";
$page->begin_box($pageoptions);

echo '<div class="row">
    <div class="col-md-8">
      <div class="p-3">';

// FormWriter V2 with model and edit_primary_key_value
$formwriter = $page->getFormWriter('form1', 'v2', [
	'model' => $event,
	'edit_primary_key_value' => $event->key
]);

$formwriter->begin_form();

$formwriter->textinput('evt_name', 'Event name', [
	'validation' => ['required' => true, 'maxlength' => 255]
]);

$optionvals = $files->get_image_dropdown_array();
$formwriter->imageinput('evt_fil_file_id', 'Main image', [
	'options' => $optionvals
]);

//echo $formwriter->textinput('Picture link', 'evt_picture_link', NULL, 100, $event->get('evt_picture_link'), '', 255, '');

if($numlocations){
	$optionvals = $locations->get_dropdown_array();
	$location_id = $event->get('evt_loc_location_id');

	// Build visibility rules for location dropdown
	$visibility_rules = array(
		'' => array('show' => array('evt_location_container'), 'hide' => array()),
	);

	// Add rules for each predefined location (hide custom location field)
	foreach ($optionvals as $label => $value) {
		$visibility_rules[$value] = array('show' => array(), 'hide' => array('evt_location_container'));
	}

	$formwriter->dropinput('evt_loc_location_id', 'Location', [
		'options' => $optionvals,
		'empty_option' => 'Custom location',
		'visibility_rules' => $visibility_rules
	]);

	$formwriter->textinput('evt_location', 'Custom location', [
		'maxlength' => 255
	]);
}
else{
	$formwriter->textinput('evt_location', 'Location', [
		'maxlength' => 255
	]);
}

$formwriter->textinput('evt_max_signups', 'Max signups (number)');

$formwriter->textinput('evt_short_description', 'Event short description (no html)', [
	'maxlength' => 255
]);

$formwriter->textinput('evt_external_register_link', 'External register link (if needed)', [
	'validation' => ['minlength' => 5],
	'maxlength' => 255
]);

$optionvals = $users->get_user_dropdown_array();
$formwriter->dropinput('evt_usr_user_id_leader', 'Led by', [
	'options' => $optionvals,
	'empty_option' => 'None'
]);

$optionvals = Address::get_timezone_drop_array();
$formwriter->dropinput('evt_timezone', 'Event Time Zone', [
	'options' => $optionvals
]);

$optionvals = array("Active"=>1, "Completed"=>2, "Cancelled"=>3);
$formwriter->dropinput('evt_status', 'Status', [
	'options' => $optionvals
]);

if($num_event_types){
	$optionvals = $event_types->get_dropdown_array();
	$formwriter->dropinput('evt_ety_event_type_id', 'Type of event', [
		'options' => $optionvals,
		'empty_option' => '-- Select --'
	]);
}

$optionvals = array("Hidden"=>0, "Live"=>1, "Live but unlisted"=>2);
$formwriter->dropinput('evt_visibility', 'Visibility', [
	'options' => $optionvals
]);

$optionvals = array("Closed"=>0, "Open"=>1);
$formwriter->dropinput('evt_is_accepting_signups', 'Registration', [
	'options' => $optionvals
]);

$optionvals = array("Allow"=>1, "Prevent"=>0);
$formwriter->dropinput('evt_allow_waiting_list', 'Waiting list', [
	'options' => $optionvals
]);

$optionvals = array("Show"=>1, "Hide"=>0);
$formwriter->dropinput('evt_show_add_to_calendar_link', 'Show calendar link', [
	'options' => $optionvals
]);

$formwriter->hiddeninput('evt_collect_extra_info', '', ['value' => 0]);

$optionvals = array("Condensed (all on one page)"=>1, "Separate (separate pages for each session)"=>2);
$formwriter->dropinput('evt_session_display_type', 'Session display style', [
	'options' => $optionvals
]);

$formwriter->datetimeinput('evt_start_time', 'Event start time ('. ($event->get('evt_timezone') ? $event->get('evt_timezone') : 'local') . ' timezone)');

$formwriter->datetimeinput('evt_end_time', 'Event end time ('. ($event->get('evt_timezone') ? $event->get('evt_timezone') : 'local'). ' timezone)');

//echo $formwriter->textinput('Max attendees:', 'evt_max_purchase_count', 'ctrlHolder', 100, $event->get('evt_max_purchase_count'), '', 255, '');

$formwriter->textbox('evt_description', 'Event Description', [
	'rows' => 10,
	'cols' => 80,
	'htmlmode' => 'yes'
]);

$formwriter->textbox('evt_private_info', 'Info only for registrants', [
	'rows' => 10,
	'cols' => 80,
	'htmlmode' => 'yes'
]);

$formwriter->submitbutton('btn_submit', 'Submit');
$formwriter->end_form();

echo '    </div>
    </div>
    <div class="col-md-4">
      <div class="p-3">';

$optionvals = $content_versions->get_dropdown_array($session, FALSE);

if(count($optionvals)){
	$formwriter = $page->getFormWriter('form_load_version', 'v2');
	$formwriter->begin_form('form_load_version', 'GET', '/admin/admin_event_edit');
	$formwriter->hiddeninput('evt_event_id', '', ['value' => $event->key]);
	$formwriter->dropinput('cnv_content_version_id', 'Load another description', [
		'options' => $optionvals,
		'empty_option' => '-- Select --'
	]);
	$formwriter->submitbutton('btn_load', 'Load');
	$formwriter->end_form();
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
