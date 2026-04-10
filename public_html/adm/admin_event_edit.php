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
$formwriter = $page->getFormWriter('form1', [
	'model' => $event,
	'edit_primary_key_value' => $event->key
]);

$formwriter->begin_form();

if ($parent_event_id && $instance_date) {
	$formwriter->hiddeninput('parent_event_id', '', ['value' => $parent_event_id]);
	$formwriter->hiddeninput('instance_date', '', ['value' => $instance_date]);
}

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
	// Just use field IDs - FormWriter automatically detects _container elements
	$visibility_rules = array(
		'' => array('show' => array('evt_location'), 'hide' => array()),
	);

	// Add rules for each predefined location (hide custom location field)
	// Note: $optionvals is [location_id => location_name] format
	foreach ($optionvals as $location_id => $location_name) {
		$visibility_rules[$location_id] = array('show' => array(), 'hide' => array('evt_location'));
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

$formwriter->textinput('evt_short_description', 'Event short description (no html)', [
	'maxlength' => 255
]);

$optionvals = $users->get_user_dropdown_array();
$formwriter->dropinput('evt_usr_user_id_leader', 'Led by', [
	'options' => $optionvals,
	'empty_option' => 'None'
]);

$optionvals = array(1=>"Active", 2=>"Completed", 3=>"Cancelled");
$formwriter->dropinput('evt_status', 'Status', [
	'options' => $optionvals
]);

$optionvals = array(0=>"Hidden", 1=>"Live", 2=>"Live but unlisted");
$formwriter->dropinput('evt_visibility', 'Visibility', [
	'options' => $optionvals
]);

$optionvals = array(0=>"Closed", 1=>"Open");
$formwriter->dropinput('evt_is_accepting_signups', 'Registration', [
	'options' => $optionvals
]);

$formwriter->datetimeinput('evt_start_time', 'Event start time ('. ($event->get('evt_timezone') ? $event->get('evt_timezone') : 'local') . ' timezone)');

$formwriter->datetimeinput('evt_end_time', 'Event end time ('. ($event->get('evt_timezone') ? $event->get('evt_timezone') : 'local'). ' timezone)');

// Recurrence section - only show for parent events and new events, NOT for materialized instances
if (!$event->is_instance()) {

	$recurrence_options = array('' => 'None', 'daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'yearly' => 'Yearly');
	$formwriter->dropinput('evt_recurrence_type', 'Repeat', [
		'options' => $recurrence_options,
		'empty_option' => false,
		'visibility_rules' => array(
			'' => array('show' => array(), 'hide' => array('evt_recurrence_interval', 'recurrence_days_of_week_group', 'recurrence_monthly_group', 'recurrence_end_group')),
			'daily' => array('show' => array('evt_recurrence_interval', 'recurrence_end_group'), 'hide' => array('recurrence_days_of_week_group', 'recurrence_monthly_group')),
			'weekly' => array('show' => array('evt_recurrence_interval', 'recurrence_days_of_week_group', 'recurrence_end_group'), 'hide' => array('recurrence_monthly_group')),
			'monthly' => array('show' => array('evt_recurrence_interval', 'recurrence_monthly_group', 'recurrence_end_group'), 'hide' => array('recurrence_days_of_week_group')),
			'yearly' => array('show' => array('evt_recurrence_interval', 'recurrence_end_group'), 'hide' => array('recurrence_days_of_week_group', 'recurrence_monthly_group')),
		)
	]);

	$formwriter->textinput('evt_recurrence_interval', 'Every N intervals (e.g., 1 = every, 2 = every other)', [
		'validation' => ['min' => 1]
	]);

	// Days of week checkboxes (for weekly)
	echo '<div id="recurrence_days_of_week_group_container"' . ($event->get('evt_recurrence_type') !== 'weekly' ? ' style="display:none;"' : '') . '>';
	echo '<label class="form-label">Repeat on</label>';
	echo '<div class="mb-3">';
	$day_names = array('Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat');
	$current_days = $event->get('evt_recurrence_days_of_week') ? explode(',', $event->get('evt_recurrence_days_of_week')) : array();
	foreach ($day_names as $i => $day_name) {
		$checked = in_array((string)$i, $current_days) ? ' checked' : '';
		echo '<div class="form-check form-check-inline">';
		echo '<input class="form-check-input recurrence-dow-check" type="checkbox" name="recurrence_dow_' . $i . '" id="recurrence_dow_' . $i . '" value="' . $i . '"' . $checked . '>';
		echo '<label class="form-check-label" for="recurrence_dow_' . $i . '">' . $day_name . '</label>';
		echo '</div>';
	}
	echo '</div></div>';

	// Monthly options (for monthly)
	echo '<div id="recurrence_monthly_group_container"' . ($event->get('evt_recurrence_type') !== 'monthly' ? ' style="display:none;"' : '') . '>';
	$month_by_week = ($event->get('evt_recurrence_week_of_month') !== null && $event->get('evt_recurrence_week_of_month') !== '');
	echo '<div class="mb-3">';
	echo '<div class="form-check">';
	echo '<input class="form-check-input" type="radio" name="monthly_type" id="monthly_by_date" value="by_date"' . (!$month_by_week ? ' checked' : '') . '>';
	echo '<label class="form-check-label" for="monthly_by_date">On the same day each month</label>';
	echo '</div>';
	echo '<div class="form-check">';
	echo '<input class="form-check-input" type="radio" name="monthly_type" id="monthly_by_week" value="by_week"' . ($month_by_week ? ' checked' : '') . '>';
	echo '<label class="form-check-label" for="monthly_by_week">On a specific weekday of the month</label>';
	echo '</div>';
	echo '<div id="week_of_month_select"' . (!$month_by_week ? ' style="display:none;"' : '') . ' class="mt-2">';
	$wom_options = array(1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th', -1 => 'Last');
	$formwriter->dropinput('evt_recurrence_week_of_month', 'Which week', [
		'options' => $wom_options,
		'empty_option' => '-- Select --'
	]);
	echo '</div>';
	echo '</div></div>';

	// End group
	echo '<div id="recurrence_end_group_container"' . (!$event->get('evt_recurrence_type') ? ' style="display:none;"' : '') . '>';
	$has_end_date = !empty($event->get('evt_recurrence_end_date'));
	echo '<div class="mb-3">';
	echo '<label class="form-label">Ends</label>';
	echo '<div class="form-check">';
	echo '<input class="form-check-input" type="radio" name="recurrence_end_type" id="end_never" value="never"' . (!$has_end_date ? ' checked' : '') . '>';
	echo '<label class="form-check-label" for="end_never">Never</label>';
	echo '</div>';
	echo '<div class="form-check">';
	echo '<input class="form-check-input" type="radio" name="recurrence_end_type" id="end_on_date" value="on_date"' . ($has_end_date ? ' checked' : '') . '>';
	echo '<label class="form-check-label" for="end_on_date">On date</label>';
	echo '</div>';
	echo '<div id="recurrence_end_date_input"' . (!$has_end_date ? ' style="display:none;"' : '') . ' class="mt-2">';
	$formwriter->dateinput('evt_recurrence_end_date', 'End date');
	echo '</div>';
	echo '</div></div>';

} // end recurrence section

// Tier Gating
require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
$tier_options = ['' => 'Public (no tier required)'];
$all_tiers_list = MultiSubscriptionTier::GetAllActive();
foreach ($all_tiers_list as $tier) {
	$tier_options[$tier->get('sbt_tier_level')] = htmlspecialchars($tier->get('sbt_display_name')) . ' (Level ' . $tier->get('sbt_tier_level') . ')';
}
$formwriter->dropinput('evt_tier_min_level', 'Minimum Tier Required', [
	'options' => $tier_options,
	'helptext' => 'Restrict this event to users with this subscription tier or higher'
]);

$early_access_options = ['' => 'Never (permanent)', '1' => '1 hour', '3' => '3 hours', '12' => '12 hours', '24' => '1 day', '72' => '3 days', '168' => '7 days', '336' => '14 days', '720' => '30 days', '2160' => '90 days'];
$formwriter->dropinput('evt_tier_public_after_hours', 'Make public after', [
	'options' => $early_access_options,
	'helptext' => 'Automatically remove the tier gate after this delay from creation time'
]);

$formwriter->textbox('evt_description', 'Event Description', [
	'rows' => 10,
	'cols' => 80,
	'htmlmode' => 'yes'
]);

// Determine if this is a new event (hide advanced fields by default)
$is_new_event = empty($event->key);
$advanced_style = $is_new_event ? 'display: none;' : '';
$toggle_style = $is_new_event ? '' : 'display: none;';

echo '<div id="advanced-toggle" class="mb-3" style="' . $toggle_style . '">
	<a href="#" onclick="document.getElementById(\'advanced-fields\').style.display=\'block\'; document.getElementById(\'advanced-toggle\').style.display=\'none\'; return false;" class="btn btn-outline-secondary btn-sm">
		Show Advanced Options
	</a>
</div>';

echo '<div id="advanced-fields" style="' . $advanced_style . '">';

$formwriter->textinput('evt_max_signups', 'Max signups (number)');

$formwriter->textinput('evt_external_register_link', 'External register link (if needed)', [
	'validation' => ['minlength' => 5],
	'maxlength' => 255
]);

$optionvals = Address::get_timezone_drop_array();
$formwriter->dropinput('evt_timezone', 'Event Time Zone', [
	'options' => $optionvals
]);

if($num_event_types){
	$optionvals = $event_types->get_dropdown_array();
	$formwriter->dropinput('evt_ety_event_type_id', 'Type of event', [
		'options' => $optionvals,
		'empty_option' => '-- Select --'
	]);
}

$optionvals = array(0=>"Prevent", 1=>"Allow");
$formwriter->dropinput('evt_allow_waiting_list', 'Waiting list', [
	'options' => $optionvals
]);

$optionvals = array(1=>"Show", 0=>"Hide");
$formwriter->dropinput('evt_show_add_to_calendar_link', 'Show calendar link', [
	'options' => $optionvals
]);

$optionvals = array(1=>"Condensed (all on one page)", 2=>"Separate (separate pages for each session)");
$formwriter->dropinput('evt_session_display_type', 'Session display style', [
	'options' => $optionvals
]);

$formwriter->textbox('evt_private_info', 'Info only for registrants', [
	'rows' => 10,
	'cols' => 80,
	'htmlmode' => 'yes'
]);

$formwriter->textinput('evt_custom_registration_message', 'Custom registration message (replaces default when no register button)', [
	'maxlength' => 255
]);

// Survey link
require_once(PathHelper::getIncludePath('data/surveys_class.php'));
$surveys = new MultiSurvey(array('deleted'=>false));
$surveys->load();
$survey_options = array();
foreach ($surveys as $survey) {
	$survey_options[$survey->key] = $survey->get('svy_name');
}
if (!empty($survey_options)) {
	$formwriter->dropinput('evt_svy_survey_id', 'Event Survey', [
		'options' => $survey_options,
		'showdefault' => 'No Survey'
	]);

	$display_options = array(
		'none' => 'No survey',
		'required_before_purchase' => 'Required before purchase (on product page)',
		'optional_at_confirmation' => 'Optional at confirmation (shown after purchase)',
		'after_event' => 'After event (sent via email after event ends)',
	);
	$formwriter->dropinput('evt_survey_display', 'Survey display', [
		'options' => $display_options,
		'showdefault' => false
	]);
}

echo '</div>';

$formwriter->submitbutton('btn_submit', 'Submit');
$formwriter->end_form();

echo '    </div>
    </div>
    <div class="col-md-4">
      <div class="p-3">';

$optionvals = $content_versions->get_dropdown_array($session, FALSE);

if(count($optionvals)){
	$formwriter = $page->getFormWriter('form_load_version', ['action' => '/admin/admin_event_edit', 'method' => 'GET']);
	$formwriter->begin_form();
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

if (!$event->is_instance()) {
	echo '<script>
document.addEventListener("DOMContentLoaded", function() {
	// Monthly type radio toggle
	var monthlyByDate = document.getElementById("monthly_by_date");
	var monthlyByWeek = document.getElementById("monthly_by_week");
	var weekOfMonthSelect = document.getElementById("week_of_month_select");

	if (monthlyByDate && monthlyByWeek && weekOfMonthSelect) {
		monthlyByDate.addEventListener("change", function() {
			if (this.checked) weekOfMonthSelect.style.display = "none";
		});
		monthlyByWeek.addEventListener("change", function() {
			if (this.checked) weekOfMonthSelect.style.display = "";
		});
	}

	// End type radio toggle
	var endNever = document.getElementById("end_never");
	var endOnDate = document.getElementById("end_on_date");
	var endDateInput = document.getElementById("recurrence_end_date_input");

	if (endNever && endOnDate && endDateInput) {
		endNever.addEventListener("change", function() {
			if (this.checked) endDateInput.style.display = "none";
		});
		endOnDate.addEventListener("change", function() {
			if (this.checked) endDateInput.style.display = "";
		});
	}

	// Append day-of-week name to "Which week" dropdown options
	var dayNames = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];
	var baseLabels = {};
	var womSelect = document.getElementById("evt_recurrence_week_of_month");
	var dateInput = document.getElementById("evt_start_time_dateinput");

	if (womSelect && dateInput) {
		for (var i = 0; i < womSelect.options.length; i++) {
			baseLabels[i] = womSelect.options[i].text;
		}

		function updateWomLabels() {
			var val = dateInput.value;
			if (!val) {
				for (var i = 0; i < womSelect.options.length; i++) {
					womSelect.options[i].text = baseLabels[i];
				}
				return;
			}
			var parts = val.split("-");
			var d = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
			var dow = dayNames[d.getDay()];
			for (var i = 0; i < womSelect.options.length; i++) {
				if (womSelect.options[i].value === "") {
					womSelect.options[i].text = baseLabels[i];
				} else {
					womSelect.options[i].text = baseLabels[i] + " " + dow;
				}
			}
		}

		dateInput.addEventListener("change", updateWomLabels);
		updateWomLabels();
	}
});
</script>';
}

$page->admin_footer();

?>
