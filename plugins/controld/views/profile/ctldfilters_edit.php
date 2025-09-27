<?php
	
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('ctldfilters_edit_logic.php', 'logic', 'system', null, 'controld'));

	$page_vars = ctldfilters_edit_logic($_GET, $_POST);
	$profile_choice = $page_vars['profile_choice'];
	$profile =  $page_vars['profile'];
	$account = $page_vars['account'];
	$device = $page_vars['device'];
	$filters = $page_vars['filters'];
	$services = $page_vars['services'];
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

	echo PublicPage::BeginPage('Add/Edit Filters', $hoptions);
	
	$name = 'New Device';
	if($device->get('cdd_device_name')){
		$name = $device->get_readable_name();
	}

	$formwriter = $page->getFormWriter();

	if($profile_choice == 'secondary'){
		$validation_rules = array();
		$validation_rules['start_time']['required']['value'] = 'true';
		$validation_rules['end_time']['required']['value'] = 'true';	
		$validation_rules['end_time']['timeGreaterThan']['value'] = '"#start_time"';
		$validation_rules['"days_blocked[]"']['required']['value'] = 'true';	

		echo $formwriter->set_validate($validation_rules);	
	}	

	echo $formwriter->begin_form('contact-form style2', 'POST', '/profile/ctldfilters_edit', true);
	
		?>
                        <div class="job-content">
                            <div class="job-post_date">
								<h3><?php echo $name; ?></h3>
                                <!--<span class="date"><i class="fa-regular fa-trash"></i></span>-->
                                <div class="icon"><a href="/profile/rules?device_id=<?php echo $device->key; ?>&profile_choice=<?php echo $profile_choice; ?>"><i class="fa-regular fa-list"></i> Custom Rules</a>
								
								<?php if($profile_choice == 'secondary'){ ?>
								&nbsp;&nbsp;&nbsp;<a href="/profile/ctldprofile_delete?profile_id=<?php echo $profile->key; ?>"><i class="fa-regular fa-trash"></i> Delete profile</a>
								<?php } ?>
								
								</div>
                            </div>
                        </div>
	<?php

	if($device){
		echo $formwriter->hiddeninput('device_id', $device->key);
	}
	
	echo $formwriter->hiddeninput('action', 'edit');
	echo $formwriter->hiddeninput('profile_choice', $profile_choice);
	
	//ONLY ALLOW EDITS IF IT IS EDIT DAY OR IF USER IS NEW
	if($device->are_filters_editable()){
		echo '<h5>Social Media</h5>';
		echo $formwriter->toggleinput("Facebook", "block_facebook", '', $services['facebook'], 1, '');
		echo $formwriter->toggleinput("Youtube", "block_youtube", '', $services['youtube'], 1, '');
		echo $formwriter->toggleinput("Instagram", "block_instagram", '', $services['instagram'], 1, '');
		echo $formwriter->toggleinput("Tiktok", "block_tiktok", '', $services['tiktok'], 1, '');
		echo $formwriter->toggleinput("Snapchat", "block_snapchat", '', $services['snapchat'], 1, '');
		echo $formwriter->toggleinput("Wechat", "block_wechat", '', $services['wechat'], 1, '');
		echo $formwriter->toggleinput("Twitter/X", "block_x", '', $services['x'], 1, '');
		echo $formwriter->toggleinput("Linkedin", "block_linkedin", '', $services['linkedin'], 1, '');
		echo $formwriter->toggleinput("Pinterest", "block_pinterest", '', $services['pinterest'], 1, '');
		echo $formwriter->toggleinput("Reddit", "block_reddit", '', $services['reddit'], 1, '');
		
		echo '<h5>Messaging</h5>';
		echo $formwriter->toggleinput("Whatsapp", "block_whatsapp", '', $services['whatsapp'], 1, '');
		echo $formwriter->toggleinput("Telegram", "block_telegram", '', $services['telegram'], 1, '');
		echo $formwriter->toggleinput("Discord", "block_discord", '', $services['discord'], 1, '');
		echo $formwriter->toggleinput("Messenger", "block_messenger", '', $services['messenger'], 1, '');
		
		echo '<h5>Gambling and Crypto</h5>';
		echo $formwriter->toggleinput("All Gambling sites", "block_gambling", '', $filters['gambling'], 1, '');
		echo $formwriter->toggleinput("All Crypto sites", "block_cryptominers", '', $filters['cryptominers'], 1, '');	

		echo '<h5>Gaming</h5>';
		echo $formwriter->toggleinput("All Gaming sites", "block_games", '', $filters['games'], 1, '');

		echo '<h5>Adult Content</h5>';

		echo $formwriter->toggleinput("All Adult sites", "block_porn", '', $filters['porn'], 1, '');
		echo $formwriter->toggleinput("All Drug sites", "block_drugs", '', $filters['drugs'], 1, '');
		
		echo '<h5>News and Shopping</h5>';

		echo $formwriter->toggleinput("All News sites", "block_news", '', $filters['news'], 1, '');
		echo $formwriter->toggleinput("All Shopping sites", "block_shop", '', $filters['shop'], 1, '');

		echo '<h5>Online Dating</h5>';
		echo $formwriter->toggleinput("All Dating sites", "block_dating", '', $filters['dating'], 1, '');
	}
	else{
		echo '<div class="alert alert-warning" role="alert">
		  Since you have chosen to allow edits only on Sunday.  Edits are disabled, except for ad and malware blocking.
		</div>';
	}

	if($account->get('cda_plan') == CtldAccount::PREMIUM_PLAN || $account->get('cda_plan') == CtldAccount::PRO_PLAN ){
		echo '<h5>Ad and Malware</h5>';
		$optionvals = array("No blocking" => 0, "Light blocking"=>'ads_small', 'Medium blocking'=>'ads_medium' /*, 'Aggressive blocking'=>'ads'*/);
		echo $formwriter->dropinput("Ads", "block_ads", "", $optionvals, $filters['ads'], '', TRUE);

		$optionvals = array("No blocking" => 0,/* "Light blocking"=>'malware', */'Medium blocking'=>'ip_malware' /*, 'Aggressive blocking'=>'ai_malware'*/);
		echo $formwriter->dropinput("Malware", "block_malware", "", $optionvals, $filters['malware'], '', TRUE);	
		
		echo $formwriter->toggleinput("Clickbait and disinformation sites", "block_fakenews", '', $filters['fakenews'], 1, '');
		
		echo $formwriter->toggleinput("Phishing sites", "block_typo", '', $filters['typo'], 1, '');
		
		//echo $formwriter->toggleinput("Newly registered sites", "block_nrd", '', $filters['nrd'], 1, '');
	}
	
	if($profile_choice == 'secondary'){
		if($session->get_permission() > 8 || $device->are_filters_editable()){
			echo '<h5>Block above sites</h5>';
			echo '<div class="row">';
			echo '<div class="col-md-6">';
			$optionvals = [
			"12:00 AM" => "00:00",
			"1:00 AM"  => "01:00",
			"2:00 AM"  => "02:00",
			"3:00 AM"  => "03:00",
			"4:00 AM"  => "04:00",
			"5:00 AM"  => "05:00",
			"6:00 AM"  => "06:00",
			"7:00 AM"  => "07:00",
			"8:00 AM"  => "08:00",
			"9:00 AM"  => "09:00",
			"10:00 AM" => "10:00",
			"11:00 AM" => "11:00",
			"12:00 PM" => "12:00",
			"1:00 PM"  => "13:00",
			"2:00 PM"  => "14:00",
			"3:00 PM"  => "15:00",
			"4:00 PM"  => "16:00",
			"5:00 PM"  => "17:00",
			"6:00 PM"  => "18:00",
			"7:00 PM"  => "19:00",
			"8:00 PM"  => "20:00",
			"9:00 PM"  => "21:00",
			"10:00 PM" => "22:00",
			"11:00 PM" => "23:00"
		];

			echo $formwriter->dropinput("Time to start blocking", "start_time", '', $optionvals, $profile->get('cdp_schedule_start'), '', TRUE);		
			
			echo '</div>';
			echo '<div class="col-md-6">';
			echo $formwriter->dropinput("Time to end blocking", "end_time", '', $optionvals, $profile->get('cdp_schedule_end'), '', TRUE);	
			echo '</div>';
			echo '</div>';

			$optionvals = array(
				'Monday' => 'mon',
				'Tuesday' => 'tue',
				'Wednesday' => 'wed',
				'Thursday' => 'thu',
				'Friday' => 'fri',
				'Saturday' => 'sat',
				'Sunday' => 'sun',		
			);
			$checkedvals =  unserialize($profile->get('cdp_schedule_days'));
			$disabledvals = array();
			$readonlyvals = array(); 
			echo $formwriter->checkboxList("Days of the week", 'days_blocked', "", $optionvals, $checkedvals, $disabledvals, $readonlyvals);
		}			
	}

	echo $formwriter->start_buttons('form-btn col-6');
	echo $formwriter->new_form_button('Submit', 'primary');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form(true);	

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE, 'show_survey'=>TRUE));
?>
