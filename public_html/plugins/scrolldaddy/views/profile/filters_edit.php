<?php

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));

require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('filters_edit_logic.php', 'logic', 'system', null, 'scrolldaddy'));

$page_vars = process_logic(filters_edit_logic($_GET, $_POST));
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
		'title' => 'Always-On Filters',
		'breadcrumbs' => array (
			'My Profile' => '/profile',
			'Devices' => '/profile/scrolldaddy/devices',
			'Always-On Filters' => ''),
	);
	$page->public_header($hoptions,NULL);

	echo PublicPage::BeginPage('Always-On Filters', $hoptions);

	$name = 'New Device';
	if($device->get('sdd_device_name')){
		$name = $device->get_readable_name();
	}

	$formwriter = $page->getFormWriter('form1', [
		'action' => '/profile/scrolldaddy/filters_edit'
	]);

	$formwriter->begin_form();

		?>
                    <div class="job-content">
                        <div class="job-post_date">
							<h3><?php echo $name; ?></h3>
                            <div class="icon"><a href="/profile/scrolldaddy/rules?device_id=<?php echo $device->key; ?>"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg> Custom Rules</a></div>
                        </div>
                    </div>
	<?php

	if($device){
		$formwriter->hiddeninput('device_id', '', ['value' => $device->key]);
	}

	$formwriter->hiddeninput('action', '', ['value' => 'edit']);

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
		$optionvals = array(0 => "No blocking", 'ads_small'=>"Light blocking", 'ads_medium'=>'Medium blocking', 'ads'=>'Aggressive blocking');
		$formwriter->dropinput('block_ads', 'Ads', [
			'options' => $optionvals,
			'value' => $filters['ads']
		]);

		$optionvals = array(0 => "No blocking", 'malware'=>"Light blocking", 'ip_malware'=>'Medium blocking', 'ai_malware'=>'Aggressive blocking');
		$formwriter->dropinput('block_malware', 'Malware', [
			'options' => $optionvals,
			'value' => $filters['malware']
		]);

		$formwriter->checkboxinput('block_fakenews', 'Clickbait and disinformation sites', ['value' => 1, 'checked' => $filters['fakenews']]);

		$formwriter->checkboxinput('block_typo', 'Phishing sites', ['value' => 1, 'checked' => $filters['typo']]);
	}

	$formwriter->submitbutton('btn_submit', 'Submit', ['class' => 'btn btn-primary']);
	$formwriter->end_form();

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE, 'show_survey'=>TRUE));
?>
