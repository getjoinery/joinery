<?php

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));

require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('ctldfilters_edit_logic.php', 'logic', 'system', null, 'controld'));

$page_vars = process_logic(ctldfilters_edit_logic($_GET, $_POST));
$profile_choice = $page_vars['profile_choice'];
	$profile =  $page_vars['profile'];
	$tier = $page_vars['tier'];
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

	$formwriter = $page->getFormWriter('form1', [
		'action' => '/profile/ctldfilters_edit'
	]);

	// Note: FormWriter v2 handles validation differently - validation rules applied per-field
	// The set_validate() method is not available in v2

	$formwriter->begin_form();
	
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
		$formwriter->hiddeninput('device_id', '', ['value' => $device->key]);
	}

	$formwriter->hiddeninput('action', '', ['value' => 'edit']);
	$formwriter->hiddeninput('profile_choice', '', ['value' => $profile_choice]);
	
	//ONLY ALLOW EDITS IF IT IS EDIT DAY OR IF USER IS NEW
	if($device->are_filters_editable()){
		echo '<h5>Social Media</h5>';
		$formwriter->checkboxinput('block_facebook', 'Facebook', ['value' => 1, 'checked' => $services['facebook']]);
		$formwriter->checkboxinput('block_youtube', 'Youtube', ['value' => 1, 'checked' => $services['youtube']]);
		$formwriter->checkboxinput('block_instagram', 'Instagram', ['value' => 1, 'checked' => $services['instagram']]);
		$formwriter->checkboxinput('block_tiktok', 'Tiktok', ['value' => 1, 'checked' => $services['tiktok']]);
		$formwriter->checkboxinput('block_snapchat', 'Snapchat', ['value' => 1, 'checked' => $services['snapchat']]);
		$formwriter->checkboxinput('block_wechat', 'Wechat', ['value' => 1, 'checked' => $services['wechat']]);
		$formwriter->checkboxinput('block_x', 'Twitter/X', ['value' => 1, 'checked' => $services['x']]);
		$formwriter->checkboxinput('block_linkedin', 'Linkedin', ['value' => 1, 'checked' => $services['linkedin']]);
		$formwriter->checkboxinput('block_pinterest', 'Pinterest', ['value' => 1, 'checked' => $services['pinterest']]);
		$formwriter->checkboxinput('block_reddit', 'Reddit', ['value' => 1, 'checked' => $services['reddit']]);

		echo '<h5>Messaging</h5>';
		$formwriter->checkboxinput('block_whatsapp', 'Whatsapp', ['value' => 1, 'checked' => $services['whatsapp']]);
		$formwriter->checkboxinput('block_telegram', 'Telegram', ['value' => 1, 'checked' => $services['telegram']]);
		$formwriter->checkboxinput('block_discord', 'Discord', ['value' => 1, 'checked' => $services['discord']]);
		$formwriter->checkboxinput('block_messenger', 'Messenger', ['value' => 1, 'checked' => $services['messenger']]);

		echo '<h5>Gambling and Crypto</h5>';
		$formwriter->checkboxinput('block_gambling', 'All Gambling sites', ['value' => 1, 'checked' => $filters['gambling']]);
		$formwriter->checkboxinput('block_cryptominers', 'All Crypto sites', ['value' => 1, 'checked' => $filters['cryptominers']]);

		echo '<h5>Gaming</h5>';
		$formwriter->checkboxinput('block_games', 'All Gaming sites', ['value' => 1, 'checked' => $filters['games']]);

		echo '<h5>Adult Content</h5>';
		$formwriter->checkboxinput('block_porn', 'All Adult sites', ['value' => 1, 'checked' => $filters['porn']]);
		$formwriter->checkboxinput('block_drugs', 'All Drug sites', ['value' => 1, 'checked' => $filters['drugs']]);

		echo '<h5>News and Shopping</h5>';
		$formwriter->checkboxinput('block_news', 'All News sites', ['value' => 1, 'checked' => $filters['news']]);
		$formwriter->checkboxinput('block_shop', 'All Shopping sites', ['value' => 1, 'checked' => $filters['shop']]);

		echo '<h5>Online Dating</h5>';
		$formwriter->checkboxinput('block_dating', 'All Dating sites', ['value' => 1, 'checked' => $filters['dating']]);
	}
	else{
		echo '<div class="alert alert-warning" role="alert">
		  Since you have chosen to allow edits only on Sunday.  Edits are disabled, except for ad and malware blocking.
		</div>';
	}

	if(SubscriptionTier::getUserFeature($session->get_user_id(), 'scrolldaddy_advanced_filters', false)){
		echo '<h5>Ad and Malware</h5>';
		$optionvals = array(0 => "No blocking", 'ads_small'=>"Light blocking", 'ads_medium'=>'Medium blocking' /*, 'ads'=>'Aggressive blocking'*/);
		$formwriter->dropinput('block_ads', 'Ads', [
			'options' => $optionvals,
			'value' => $filters['ads']
		]);

		$optionvals = array(0 => "No blocking",/* 'malware'=>"Light blocking", */'ip_malware'=>'Medium blocking' /*, 'ai_malware'=>'Aggressive blocking'*/);
		$formwriter->dropinput('block_malware', 'Malware', [
			'options' => $optionvals,
			'value' => $filters['malware']
		]);

		$formwriter->checkboxinput('block_fakenews', 'Clickbait and disinformation sites', ['value' => 1, 'checked' => $filters['fakenews']]);

		$formwriter->checkboxinput('block_typo', 'Phishing sites', ['value' => 1, 'checked' => $filters['typo']]);
	}
	
	if($profile_choice == 'secondary'){
		if($session->get_permission() > 8 || $device->are_filters_editable()){
			echo '<h5>Block above sites</h5>';
			echo '<div class="row">';
			echo '<div class="col-md-6">';
			$optionvals = [
			"00:00" => "12:00 AM",
			"01:00"  => "1:00 AM",
			"02:00"  => "2:00 AM",
			"03:00"  => "3:00 AM",
			"04:00"  => "4:00 AM",
			"05:00"  => "5:00 AM",
			"06:00"  => "6:00 AM",
			"07:00"  => "7:00 AM",
			"08:00"  => "8:00 AM",
			"09:00"  => "9:00 AM",
			"10:00" => "10:00 AM",
			"11:00" => "11:00 AM",
			"12:00" => "12:00 PM",
			"13:00"  => "1:00 PM",
			"14:00"  => "2:00 PM",
			"15:00"  => "3:00 PM",
			"16:00"  => "4:00 PM",
			"17:00"  => "5:00 PM",
			"18:00"  => "6:00 PM",
			"19:00"  => "7:00 PM",
			"20:00"  => "8:00 PM",
			"21:00"  => "9:00 PM",
			"22:00" => "10:00 PM",
			"23:00" => "11:00 PM"
		];

			$formwriter->dropinput('start_time', 'Time to start blocking', [
			'options' => $optionvals,
			'value' => $profile->get('cdp_schedule_start')
		]);

		echo '</div>';
		echo '<div class="col-md-6">';
		$formwriter->dropinput('end_time', 'Time to end blocking', [
			'options' => $optionvals,
			'value' => $profile->get('cdp_schedule_end')
		]);
		echo '</div>';
		echo '</div>';

		$optionvals = array(
			'mon' => 'Monday',
			'tue' => 'Tuesday',
			'wed' => 'Wednesday',
			'thu' => 'Thursday',
			'fri' => 'Friday',
			'sat' => 'Saturday',
			'sun' => 'Sunday',
		);
		$checkedvals =  unserialize($profile->get('cdp_schedule_days'));
		$formwriter->checkboxList('days_blocked', 'Days of the week', [
			'options' => $optionvals,
			'checked' => $checkedvals
		]);
		}
	}

	$formwriter->submitbutton('btn_submit', 'Submit', ['class' => 'btn btn-primary']);
	$formwriter->end_form();

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE, 'show_survey'=>TRUE));
?>
