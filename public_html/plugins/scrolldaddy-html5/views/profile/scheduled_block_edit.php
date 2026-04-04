<?php

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
require_once(PathHelper::getIncludePath('plugins/scrolldaddy/includes/ScrollDaddyHelper.php'));

require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('scheduled_block_edit_logic.php', 'logic', 'system', null, 'scrolldaddy'));

$page_vars = process_logic(scheduled_block_edit_logic($_GET, $_POST));
	$device = $page_vars['device'];
	$block = $page_vars['block'];
	$filter_rules = $page_vars['filter_rules'];
	$service_rules = $page_vars['service_rules'];
	$tier = $page_vars['tier'];
	$user = $page_vars['user'];
	$session = SessionControl::get_instance();

	$is_edit = $block->key ? true : false;

	$page = new PublicPage();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => ($is_edit ? 'Edit' : 'Add') . ' Scheduled Block',
		'breadcrumbs' => array (
			'My Profile' => '/profile',
			'Devices' => '/profile/devices',
			($is_edit ? 'Edit' : 'Add') . ' Scheduled Block' => ''),
	);
	$page->public_header($hoptions,NULL);

	echo PublicPage::BeginPage(($is_edit ? 'Edit' : 'Add') . ' Scheduled Block', $hoptions);

	$name = 'New Device';
	if($device->get('sdd_device_name')){
		$name = $device->get_readable_name();
	}

	$formwriter = $page->getFormWriter('form1', [
		'action' => '/profile/scheduled_block_edit'
	]);

	$formwriter->begin_form();
?>
	<div class="job-content">
		<div class="job-post_date">
			<h3><?php echo htmlspecialchars($name); ?></h3>
<?php if($is_edit): ?>
			<div class="icon"><a href="/profile/rules?device_id=<?php echo $device->key; ?>&block_id=<?php echo $block->key; ?>"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg> Custom Rules</a></div>
<?php endif; ?>
		</div>
	</div>
<?php

	$formwriter->hiddeninput('device_id', '', ['value' => $device->key]);
	$formwriter->hiddeninput('action', '', ['value' => 'edit']);
	if($is_edit){
		$formwriter->hiddeninput('block_id', '', ['value' => $block->key]);
	}

	// BLOCK NAME
	echo '<h5>Block Name</h5>';
	$formwriter->textinput('sdb_name', 'Name', [
		'value' => $block->get('sdb_name') ?: '',
		'placeholder' => 'e.g. Bedtime, School Hours, Weekend Fun',
		'maxlength' => 64
	]);

	// SCHEDULE
	echo '<h5>Schedule</h5>';

	$time_options = [
		"00:00" => "12:00 AM",
		"01:00" => "1:00 AM",
		"02:00" => "2:00 AM",
		"03:00" => "3:00 AM",
		"04:00" => "4:00 AM",
		"05:00" => "5:00 AM",
		"06:00" => "6:00 AM",
		"07:00" => "7:00 AM",
		"08:00" => "8:00 AM",
		"09:00" => "9:00 AM",
		"10:00" => "10:00 AM",
		"11:00" => "11:00 AM",
		"12:00" => "12:00 PM",
		"13:00" => "1:00 PM",
		"14:00" => "2:00 PM",
		"15:00" => "3:00 PM",
		"16:00" => "4:00 PM",
		"17:00" => "5:00 PM",
		"18:00" => "6:00 PM",
		"19:00" => "7:00 PM",
		"20:00" => "8:00 PM",
		"21:00" => "9:00 PM",
		"22:00" => "10:00 PM",
		"23:00" => "11:00 PM"
	];

	echo '<div class="row">';
	echo '<div class="col-md-6">';
	$formwriter->dropinput('start_time', 'Start Time', [
		'options' => $time_options,
		'value' => $block->get('sdb_schedule_start') ?: ''
	]);
	echo '</div>';
	echo '<div class="col-md-6">';
	$formwriter->dropinput('end_time', 'End Time', [
		'options' => $time_options,
		'value' => $block->get('sdb_schedule_end') ?: ''
	]);
	echo '</div>';
	echo '</div>';

	echo '<p class="text-muted" id="overnight-hint" style="display:none;"><em>This schedule spans overnight (crosses midnight)</em></p>';

	$day_options = array(
		'mon' => 'Monday',
		'tue' => 'Tuesday',
		'wed' => 'Wednesday',
		'thu' => 'Thursday',
		'fri' => 'Friday',
		'sat' => 'Saturday',
		'sun' => 'Sunday',
	);
	$checked_days = $block->get('sdb_schedule_days') ? json_decode($block->get('sdb_schedule_days'), true) : array();
	$formwriter->checkboxList('days_blocked', 'Days of the week', [
		'options' => $day_options,
		'checked' => $checked_days
	]);

	echo '<p>';
	echo '<a href="#" onclick="selectDays([\'mon\',\'tue\',\'wed\',\'thu\',\'fri\']); return false;">Weekdays</a>';
	echo ' &nbsp;|&nbsp; ';
	echo '<a href="#" onclick="selectDays([\'mon\',\'tue\',\'wed\',\'thu\',\'fri\',\'sat\',\'sun\']); return false;">Every day</a>';
	echo '</p>';

	// RULES — three-state per category: —/Block/Allow
	$rule_options = ['' => '—', '0' => 'Block', '1' => 'Allow'];

	// Determine if we should show all categories or restrict by editability
	$can_edit_main = $device->are_filters_editable();

	if($can_edit_main){
		echo '<h5>Social Media</h5>';
		$social_services = ['facebook'=>'Facebook', 'youtube'=>'YouTube', 'instagram'=>'Instagram',
			'tiktok'=>'TikTok', 'snapchat'=>'Snapchat', 'wechat'=>'WeChat', 'x'=>'Twitter/X',
			'linkedin'=>'LinkedIn', 'pinterest'=>'Pinterest', 'reddit'=>'Reddit'];
		foreach($social_services as $key => $label){
			$val = isset($service_rules[$key]) ? (string)$service_rules[$key] : '';
			$formwriter->dropinput('rule_'.$key, $label, ['options' => $rule_options, 'value' => $val]);
		}

		echo '<h5>Messaging</h5>';
		$msg_services = ['whatsapp'=>'WhatsApp', 'telegram'=>'Telegram', 'discord'=>'Discord', 'messenger'=>'Messenger'];
		foreach($msg_services as $key => $label){
			$val = isset($service_rules[$key]) ? (string)$service_rules[$key] : '';
			$formwriter->dropinput('rule_'.$key, $label, ['options' => $rule_options, 'value' => $val]);
		}

		echo '<h5>Gambling and Crypto</h5>';
		$val = isset($filter_rules['gambling']) ? (string)$filter_rules['gambling'] : '';
		$formwriter->dropinput('rule_gambling', 'All Gambling sites', ['options' => $rule_options, 'value' => $val]);
		$val = isset($filter_rules['cryptominers']) ? (string)$filter_rules['cryptominers'] : '';
		$formwriter->dropinput('rule_cryptominers', 'All Crypto sites', ['options' => $rule_options, 'value' => $val]);

		echo '<h5>Gaming</h5>';
		$val = isset($filter_rules['games']) ? (string)$filter_rules['games'] : '';
		$formwriter->dropinput('rule_games', 'All Gaming sites', ['options' => $rule_options, 'value' => $val]);

		echo '<h5>Adult Content</h5>';
		$val = isset($filter_rules['porn']) ? (string)$filter_rules['porn'] : '';
		$formwriter->dropinput('rule_porn', 'All Adult sites', ['options' => $rule_options, 'value' => $val]);
		$val = isset($filter_rules['drugs']) ? (string)$filter_rules['drugs'] : '';
		$formwriter->dropinput('rule_drugs', 'All Drug sites', ['options' => $rule_options, 'value' => $val]);

		echo '<h5>News and Shopping</h5>';
		$val = isset($filter_rules['news']) ? (string)$filter_rules['news'] : '';
		$formwriter->dropinput('rule_news', 'All News sites', ['options' => $rule_options, 'value' => $val]);
		$val = isset($filter_rules['shop']) ? (string)$filter_rules['shop'] : '';
		$formwriter->dropinput('rule_shop', 'All Shopping sites', ['options' => $rule_options, 'value' => $val]);

		echo '<h5>Online Dating</h5>';
		$val = isset($filter_rules['dating']) ? (string)$filter_rules['dating'] : '';
		$formwriter->dropinput('rule_dating', 'All Dating sites', ['options' => $rule_options, 'value' => $val]);
	}
	else{
		echo '<div class="alert alert-warning" role="alert">
		  Since you have chosen to allow edits only on Sunday. Edits are disabled, except for ad and malware blocking.
		</div>';
	}

	// Ad and malware — always editable
	if(SubscriptionTier::getUserFeature($session->get_user_id(), 'scrolldaddy_advanced_filters', false)){
		echo '<h5>Ad and Malware</h5>';

		$ad_filters = ['ads_small'=>'Ads (Light)', 'ads_medium'=>'Ads (Medium)', 'ads'=>'Ads (Aggressive)'];
		foreach($ad_filters as $key => $label){
			$val = isset($filter_rules[$key]) ? (string)$filter_rules[$key] : '';
			$formwriter->dropinput('rule_'.$key, $label, ['options' => $rule_options, 'value' => $val]);
		}

		$malware_filters = ['malware'=>'Malware (Light)', 'ip_malware'=>'Malware (Medium)', 'ai_malware'=>'Malware (Aggressive)'];
		foreach($malware_filters as $key => $label){
			$val = isset($filter_rules[$key]) ? (string)$filter_rules[$key] : '';
			$formwriter->dropinput('rule_'.$key, $label, ['options' => $rule_options, 'value' => $val]);
		}

		$val = isset($filter_rules['fakenews']) ? (string)$filter_rules['fakenews'] : '';
		$formwriter->dropinput('rule_fakenews', 'Clickbait and disinformation', ['options' => $rule_options, 'value' => $val]);

		$val = isset($filter_rules['typo']) ? (string)$filter_rules['typo'] : '';
		$formwriter->dropinput('rule_typo', 'Phishing sites', ['options' => $rule_options, 'value' => $val]);
	}

	$formwriter->submitbutton('btn_submit', 'Save Scheduled Block', ['class' => 'btn btn-primary']);
	$formwriter->end_form();

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE, 'show_survey'=>TRUE));
?>

<script>
// Overnight hint
function checkOvernight() {
	var start = document.querySelector('[name="start_time"]');
	var end = document.querySelector('[name="end_time"]');
	var hint = document.getElementById('overnight-hint');
	if (start && end && hint) {
		hint.style.display = (end.value < start.value && end.value !== '' && start.value !== '') ? 'block' : 'none';
	}
}
document.addEventListener('DOMContentLoaded', function() {
	var start = document.querySelector('[name="start_time"]');
	var end = document.querySelector('[name="end_time"]');
	if (start) start.addEventListener('change', checkOvernight);
	if (end) end.addEventListener('change', checkOvernight);
	checkOvernight();
});

// Day shortcut links
function selectDays(days) {
	var checkboxes = document.querySelectorAll('[name="days_blocked[]"]');
	checkboxes.forEach(function(cb) {
		cb.checked = days.indexOf(cb.value) !== -1;
	});
}
</script>
