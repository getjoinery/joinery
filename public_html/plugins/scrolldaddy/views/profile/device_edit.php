<?php

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('device_edit_logic.php', 'logic', 'system', null, 'scrolldaddy'));

$page_vars = process_logic(device_edit_logic($_GET, $_POST));
	$tier = $page_vars['tier'];
	$device = $page_vars['device'];
	$num_devices =  $page_vars['num_devices'];
	$user = $page_vars['user'];
	$session = SessionControl::get_instance();

	$page = new PublicPage();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Device Add/Edit',
		'breadcrumbs' => array (
			'My Profile' => '/profile',
			'Add/Edit Device' => ''),
	);
	$page->public_header($hoptions,NULL);

	echo PublicPage::BeginPage('Add/Edit Device', $hoptions);

	$name = 'New Device';
	if($device->get('sdd_device_name')){
		$name = $device->get_readable_name();
	}

	$formwriter = $page->getFormWriter('form1', [
		'action' => '/profile/device_edit'
	]);

	// Note: FormWriter v2 handles validation differently - validation rules applied per-field
	// $validation_rules are no longer needed with set_validate()

	$formwriter->begin_form();
	if($device->key){
		?>
                    <div class="job-content">
                        <div class="job-post_date">
							<h3><?php echo $name; ?></h3>
                            <!--<span class="date"><i class="fa-regular fa-trash"></i></span>-->
                            <div class="icon"><a href="/profile/device_delete?device_id=<?php echo $device->key; ?>"><i class="fa-regular fa-trash"></i> Delete</a></div>
                        </div>
                    </div>
	<?php

		$formwriter->hiddeninput('device_id', '', ['value' => $device->key]);
	}

	if($device->get('sdd_device_name')){
		$name = $device->get_readable_name();
	}
	else{
		$name = '';
	}

	$formwriter->textinput('device_name', 'Device name', [
		'value' => $name,
		'maxlength' => 255
	]);

	if(!$device->get('sdd_device_type')){
		$optionvals = [
			'Windows Computer' => 'desktop-windows',
			'Mac Computer' => 'desktop-mac',
			'Linux Computer' => 'desktop-linux',
			"Apple phone or Ipad" => 'mobile-ios',
			"Android phone or tablet" => 'mobile-android',
		];
		$formwriter->dropinput('device_type', 'Device type', [
			'options' => $optionvals
		]);
	}

	if($device->are_filters_editable()){
		$optionvals = array(0=>"Only on Sundays", 1=>"Anytime");
		$formwriter->dropinput('sdd_allow_device_edits', 'I want to be able to edit my blocked sites', [
			'options' => $optionvals,
			'value' => $device->get('sdd_allow_device_edits')
		]);
	}

	$optionvals = Address::get_timezone_drop_array();
	$formwriter->dropinput('sdd_timezone', 'Time zone for scheduling', [
		'options' => $optionvals,
		'value' => $device->get('sdd_timezone')
	]);

	$formwriter->submitbutton('btn_submit', 'Submit', ['class' => 'btn btn-primary']);
	$formwriter->end_form();

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE, 'show_survey'=>TRUE));
?>
