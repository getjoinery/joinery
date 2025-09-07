<?php
	PathHelper::requireOnce('includes/Globalvars.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	PathHelper::requireOnce('includes/PathHelper.php');
PathHelper::requireOnce('includes/ThemeHelper.php');
	ThemeHelper::includeThemeFile('includes/PublicPage');
	PathHelper::requireOnce('plugins/controld/logic/ctlddevice_edit_logic.php');

	$page_vars = ctlddevice_edit_logic($_GET, $_POST);
	$account = $page_vars['account'];
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
	if($device->get('cdd_device_name')){
		$name = $device->get_readable_name();
	}

	
	
	
	
	$formwriter = LibraryFunctions::get_formwriter_object();

	$validation_rules = array();
	$validation_rules['device_name']['required']['value'] = 'true';
	$validation_rules['device_type']['required']['value'] = 'true';	
	/*
	$validation_rules['cmt_body']['required']['value'] = 'true';	
	$validation_rules['"products_list[]"']['required']['value'] = 'true';	
	$validation_rules['single_checkbox']['required']['value'] = 'true';*/
	echo $formwriter->set_validate($validation_rules);	


	echo $formwriter->begin_form('contact-form style2', 'POST', '/profile/ctlddevice_edit');
	if($device->key){
		?>
                        <div class="job-content">
                            <div class="job-post_date">
								<h3><?php echo $name; ?></h3>
                                <!--<span class="date"><i class="fa-regular fa-trash"></i></span>-->
                                <div class="icon"><a href="/profile/ctlddevice_delete?device_id=<?php echo $device->key; ?>"><i class="fa-regular fa-trash"></i> Delete</a></div>
                            </div>
                        </div>
	<?php
	
		echo $formwriter->hiddeninput('device_id', $device->key);
	}
	
	if($device->get('cdd_device_name')){
		$name = $device->get_readable_name();
	}
	else{
		$name = '';
	}	
	
	echo $formwriter->textinput('Device name', 'device_name', NULL, 100, $name, '', 255, '');	

	if(!$device->get('cdd_device_type')){
		$optionvals = [
			'Windows Computer' => 'desktop-windows',
			'Mac Computer' => 'desktop-mac',
			'Linux Computer' => 'desktop-linux',
			"Apple phone or Ipad" => 'mobile-ios',
			"Android phone or tablet" => 'mobile-android',
			//6 => 'browser-chrome',
			//7 => 'browser-firefox',
			//8 => 'browser-edge',
			//9 => 'browser-brave',
			//10 => 'browser-other',
			//11 => 'tv-apple',
			//12 => 'tv-android',
			//13 => 'tv-firetv',
			//14 => 'tv-samsung',
			//15 => 'tv',
			//16 => 'router-asus',
			//17 => 'router-ddwrt',
			//18 => 'router-firewalla',
			//19 => 'router-freshtomato',
			//20 => 'router-glinet',
			//21 => 'router-openwrt',
			//22 => 'router-opnsense',
			//23 => 'router-pfsense',
			//24 => 'router-synology',
			//25 => 'router-ubiquiti',
			//26 => 'router-windows',
			//27 => 'router-linux',
			//28 => 'router',
		];
		//$optionvals = array("Apple phone or Ipad"=>0, "Android phone or tablet"=>1, 'Windows Computer'=>3, 'Mac Computer'=>4, 'Other type'=>5);
		echo $formwriter->dropinput("Device type", "device_type", "", $optionvals, NULL, '', TRUE);
	}	

	if($device->are_filters_editable()){
		$optionvals = array("Only on Sundays"=>0,"Anytime"=>1);
		echo $formwriter->dropinput("I want to be able to edit my blocked sites", "cdd_allow_device_edits", '', $optionvals, $device->get('cdd_allow_device_edits'), '', TRUE);
	}
	
	$optionvals = Address::get_timezone_drop_array();
	echo $formwriter->dropinput("Time zone for scheduling", "cdd_timezone", "ctrlHolder", $optionvals, $device->get('cdd_timezone'), '', FALSE);
	

	
	echo $formwriter->start_buttons('form-btn col-6');
	echo $formwriter->new_form_button('Submit', 'th-btn');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form(true);	
	

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE, 'show_survey'=>TRUE));
?>
