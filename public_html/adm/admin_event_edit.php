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

// Editing an existing event
$formwriter = $page->getFormWriter('form1');

$validation_rules = array();
$validation_rules['evt_name']['required']['value'] = 'true';
$validation_rules['evt_external_register_link']['minlength']['value'] = '5';
echo $formwriter->set_validate($validation_rules);

echo $formwriter->begin_form('form1', 'POST', '/admin/admin_event_edit');

if($event->key){
	echo $formwriter->hiddeninput('evt_event_id', $event->key);
	echo $formwriter->hiddeninput('action', 'edit');
}

echo $formwriter->textinput('Event name', 'evt_name', NULL, 100, $title, '', 255, '');

$optionvals = $files->get_image_dropdown_array();
echo $formwriter->imageinput("Main image", "evt_fil_file_id", "ctrlHolder", $optionvals, $event->get('evt_fil_file_id'), '', TRUE, TRUE, FALSE, TRUE);

//echo $formwriter->textinput('Picture link', 'evt_picture_link', NULL, 100, $event->get('evt_picture_link'), '', 255, '');

if($numlocations){
	?>
	<script type="text/javascript">

	function set_choices(){
		var value = $("#evt_loc_location_id").val();
		if(value == ''){  //ONE PRICE
			$("#evt_location_container").show();
		}
		else{  //MULTIPLE PRICES
			$("#evt_location_container").hide();
			$("#evt_location").val('');
		}
	}

	$(document).ready(function() {
		set_choices();
		$("#evt_loc_location_id").change(function() {
			set_choices();
		});
	});

	</script>
	<?php
	$optionvals = $locations->get_dropdown_array();
	echo $formwriter->dropinput('Location', 'evt_loc_location_id', '', $optionvals, $event->get('evt_loc_location_id'), '', 'Custom location');
	echo $formwriter->textinput('Custom location', 'evt_location', NULL, 100, $event->get('evt_location'), '', 255, '');
}
else{
	echo $formwriter->textinput('Location', 'evt_location', NULL, 100, $event->get('evt_location'), '', 255, '');
}

echo $formwriter->textinput('Max signups (number)', 'evt_max_signups', NULL, 100, $event->get('evt_max_signups'), '', 255, '');

echo $formwriter->textinput('Event short description (no html)', 'evt_short_description', NULL, 100, $event->get('evt_short_description'), '', 255, '');
echo $formwriter->textinput('External register link (if needed)', 'evt_external_register_link', NULL, 100, $event->get('evt_external_register_link'), '', 255, '');

$optionvals = $users->get_user_dropdown_array();

echo $formwriter->dropinput('Led by', 'evt_usr_user_id_leader', 'ctrlHolder', $optionvals, $event->get('evt_usr_user_id_leader'), '', 'None');

$optionvals = Address::get_timezone_drop_array();
echo $formwriter->dropinput("Event Time Zone", "evt_timezone", "ctrlHolder", $optionvals, $timezone, '', FALSE);

$optionvals = array("Active"=>1, "Completed"=>2, "Cancelled"=>3);
echo $formwriter->dropinput("Status", "evt_status", "ctrlHolder", $optionvals, $event->get('evt_status'), '', FALSE);

if($num_event_types){
	$optionvals = $event_types->get_dropdown_array();
	echo $formwriter->dropinput("Type of event", "evt_ety_event_type_id", "ctrlHolder", $optionvals, $event->get('evt_ety_event_type_id'), '', FALSE);
}

$optionvals = array("Hidden"=>0, "Live"=>1, "Live but unlisted"=>2);
echo $formwriter->dropinput("Visibility", "evt_visibility", "ctrlHolder", $optionvals, $event->get('evt_visibility'), '', FALSE);

$optionvals = array("Closed"=>0, "Open"=>1);
echo $formwriter->dropinput("Registration", "evt_is_accepting_signups", "ctrlHolder", $optionvals, $event->get('evt_is_accepting_signups'), '', FALSE);

$optionvals = array("Allow"=>1, "Prevent"=>0);
echo $formwriter->dropinput("Waiting list", "evt_allow_waiting_list", "ctrlHolder", $optionvals, $event->get('evt_allow_waiting_list'), '', FALSE);

$optionvals = array("Show"=>1, "Hide"=>0);
echo $formwriter->dropinput("Show calendar link", "evt_show_add_to_calendar_link", "ctrlHolder", $optionvals, $event->get('evt_show_add_to_calendar_link'), '', FALSE);

/*
$surveys = new MultiSurvey(
	array('deleted'=>false));
$surveys->load();
$optionvals = $surveys->get_survey_dropdown_array();
echo $formwriter->dropinput("Event survey", "evt_svy_survey_id", "ctrlHolder", $optionvals, $event->get('evt_svy_survey_id'), '', 'No Survey');

$optionvals = array("Required"=>1, "Not Required"=>0);
echo $formwriter->dropinput("Event survey required before registration", "evt_survey_required", "ctrlHolder", $optionvals, $event->get('evt_survey_required'), '', FALSE);

 ?>
<script type="text/javascript">

	function set_survey_choices(){
		var value = $("#evt_svy_survey_id").val();
		if(value == ''){
			$("#evt_survey_required_container").hide();
		}
		else {
			$("#evt_survey_required_container").show();
		}
	}

	$(document).ready(function() {
		set_survey_choices();
		$("#evt_svy_survey_id").change(function() {
			set_survey_choices();
		});

	});

</script>
 <?php
 */
echo $formwriter->hiddeninput('evt_collect_extra_info', '0');

$optionvals = array("Condensed (all on one page)"=>1, "Separate (separate pages for each session)"=>2);
echo $formwriter->dropinput("Session display style", "evt_session_display_type", "ctrlHolder", $optionvals, $event->get('evt_session_display_type'), '', FALSE);

echo $formwriter->datetimeinput('Event start time ('. ($event->get('evt_timezone') ? $event->get('evt_timezone') : 'local') . ' timezone)', 'evt_start_time', 'ctrlHolder', LibraryFunctions::convert_time($event->get('evt_start_time_local'), $event->get('evt_timezone'), $event->get('evt_timezone'), 'Y-m-d h:ia'), '', '', '');

echo $formwriter->datetimeinput('Event end time ('. ($event->get('evt_timezone') ? $event->get('evt_timezone') : 'local'). ' timezone)', 'evt_end_time', 'ctrlHolder', LibraryFunctions::convert_time($event->get('evt_end_time_local'), $event->get('evt_timezone'), $event->get('evt_timezone'), 'Y-m-d h:ia'), '', '', '');

//echo $formwriter->textinput('Max attendees:', 'evt_max_purchase_count', 'ctrlHolder', 100, $event->get('evt_max_purchase_count'), '', 255, '');

echo $formwriter->textbox('Event Description', 'evt_description', 'ctrlHolder', 10, 80, $content, '', 'yes');
//echo $formwriter->textbox('After Purchase Message', 'evt_after_purchase_message', 'ctrlHolder', 10, 80, $event->get('evt_after_purchase_message'), '', 'no');

echo $formwriter->textbox('Info only for registrants', 'evt_private_info', 'ctrlHolder', 10, 80, $event->get('evt_private_info'), '', 'yes');

echo $formwriter->start_buttons();
echo $formwriter->new_form_button('Submit');
echo $formwriter->end_buttons();

echo $formwriter->end_form();

echo '    </div>
    </div>
    <div class="col-md-4">
      <div class="p-3">';

$optionvals = $content_versions->get_dropdown_array($session, FALSE);

if(count($optionvals)){
	$formwriter = $page->getFormWriter('form_load_version');
	echo $formwriter->begin_form('form_load_version', 'GET', '/admin/admin_event_edit');
	echo $formwriter->hiddeninput('evt_event_id', $event->key);
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
