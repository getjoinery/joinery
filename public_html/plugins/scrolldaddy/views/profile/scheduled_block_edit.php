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
	$domain_rules = $page_vars['domain_rules'] ?? array();
	$tier = $page_vars['tier'];
	$user = $page_vars['user'];
	$session = SessionControl::get_instance();

	$is_edit = $block->key ? true : false;
	$is_always_on = $is_edit && $block->get('sdb_is_always_on');
	$has_custom_rules = $tier ? $tier->getFeature('scrolldaddy_custom_rules', false) : false;

	// Title/breadcrumb differs between always-on and scheduled
	if($is_always_on){
		$page_title = 'Always-On Rules';
		$save_label = 'Save Always-On Rules';
	}
	else{
		$page_title = ($is_edit ? 'Edit' : 'Add') . ' Scheduled Block';
		$save_label = 'Save Scheduled Block';
	}

	$page = new PublicPage();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => $page_title,
		'breadcrumbs' => array (
			'My Profile' => '/profile',
			'Devices' => '/profile/scrolldaddy/devices',
			$page_title => ''),
	);
	$page->public_header($hoptions,NULL);

	echo PublicPage::BeginPage($page_title, $hoptions);

	$name = 'New Device';
	if($device->get('sdd_device_name')){
		$name = $device->get_readable_name();
	}

	$formwriter = $page->getFormWriter('form1', [
		'action' => '/profile/scrolldaddy/scheduled_block_edit'
	]);

	$formwriter->begin_form();
?>
	<div class="job-content">
		<div class="job-post_date">
			<h3><?php echo htmlspecialchars($name); ?></h3>
		</div>
	</div>
<?php

	$formwriter->hiddeninput('device_id', '', ['value' => $device->key]);
	$formwriter->hiddeninput('action', '', ['value' => 'edit']);
	if($is_edit){
		$formwriter->hiddeninput('block_id', '', ['value' => $block->key]);
	}

	// BLOCK NAME — only for scheduled blocks. Always-on block name is fixed.
	if(!$is_always_on){
		echo '<h5>Block Name</h5>';
		$formwriter->textinput('sdb_name', 'Name', [
			'value' => $block->get('sdb_name') ?: '',
			'placeholder' => 'e.g. Bedtime, School Hours, Weekend Fun',
			'maxlength' => 64
		]);
	}

	// SCHEDULE — only for scheduled blocks. Always-on is active 24/7 by definition.
	if(!$is_always_on){
		echo '<h5>Schedule</h5>';

		$time_options = [
			"00:00" => "12:00 AM", "01:00" => "1:00 AM", "02:00" => "2:00 AM", "03:00" => "3:00 AM",
			"04:00" => "4:00 AM", "05:00" => "5:00 AM", "06:00" => "6:00 AM", "07:00" => "7:00 AM",
			"08:00" => "8:00 AM", "09:00" => "9:00 AM", "10:00" => "10:00 AM", "11:00" => "11:00 AM",
			"12:00" => "12:00 PM", "13:00" => "1:00 PM", "14:00" => "2:00 PM", "15:00" => "3:00 PM",
			"16:00" => "4:00 PM", "17:00" => "5:00 PM", "18:00" => "6:00 PM", "19:00" => "7:00 PM",
			"20:00" => "8:00 PM", "21:00" => "9:00 PM", "22:00" => "10:00 PM", "23:00" => "11:00 PM"
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
			'mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday',
			'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday',
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
	}

	// CATEGORY RULES — three-state per category: —/Block/Allow
	$rule_options = ['' => '—', '0' => 'Block', '1' => 'Allow'];

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

		echo '<h5>Search Safety</h5>';
		$val = isset($filter_rules['safesearch']) ? (string)$filter_rules['safesearch'] : '';
		$formwriter->dropinput('rule_safesearch', ScrollDaddyHelper::$filters['safesearch'], ['options' => $rule_options, 'value' => $val]);
		$val = isset($filter_rules['safeyoutube']) ? (string)$filter_rules['safeyoutube'] : '';
		$formwriter->dropinput('rule_safeyoutube', ScrollDaddyHelper::$filters['safeyoutube'], ['options' => $rule_options, 'value' => $val]);
	}
	else{
		echo '<div class="alert alert-warning" role="alert">
		  Since you have chosen to allow edits only on Sunday. Edits are disabled, except for ad and malware blocking.
		</div>';
	}

	// Ad and malware — gated by scrolldaddy_advanced_filters
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

	$formwriter->submitbutton('btn_submit', $save_label, ['class' => 'btn btn-primary']);
	$formwriter->end_form();

	// CUSTOM DOMAIN RULES — rendered outside the main form; uses inline AJAX for add/delete
	if($is_edit){
		echo '<hr>';
		echo '<h5>Custom Domain Rules</h5>';

		if($has_custom_rules){
			echo '<p class="text-muted">Block or allow specific hostnames — for example <code>youtube.com</code> or <code>reddit.com</code>.</p>';
			echo '<table class="sd-table" id="domain-rules-table" style="width:100%;"><thead><tr><th>Hostname</th><th>Action</th><th></th></tr></thead><tbody id="domain-rules-rows">';
			foreach($domain_rules as $rule){
				$rid = $rule->get('sbr_rule_id');
				$host = htmlspecialchars($rule->get('sbr_hostname'));
				$action_label = $rule->get('sbr_action') == 1 ? 'Allow' : 'Block';
				echo '<tr data-rule-id="'.$rid.'"><td>'.$host.'</td><td>'.$action_label.'</td><td><button type="button" class="th-btn style5 sd-rule-delete" data-rule-id="'.$rid.'" style="background:#dc3545;border-color:#dc3545;">Delete</button></td></tr>';
			}
			if(count($domain_rules) == 0){
				echo '<tr id="domain-rules-empty"><td colspan="3" class="text-muted">No custom rules yet.</td></tr>';
			}
			echo '</tbody></table>';

			echo '<div id="domain-rules-add" style="margin-top:16px; display:flex; gap:8px; align-items:flex-end;">';
			echo '<input type="text" id="sd-new-hostname" class="form-control" placeholder="example.com" style="flex:1;">';
			echo '<select id="sd-new-action" class="form-control" style="width:auto;"><option value="0">Block</option><option value="1">Allow</option></select>';
			echo '<button type="button" class="th-btn" id="sd-add-rule">Add</button>';
			echo '</div>';
			echo '<p id="sd-rule-error" class="text-danger" style="display:none; margin-top:8px;"></p>';
			?>
			<script>
			(function(){
				var blockId = <?php echo (int)$block->key; ?>;
				var tbody = document.getElementById('domain-rules-rows');
				var hostInput = document.getElementById('sd-new-hostname');
				var actionSelect = document.getElementById('sd-new-action');
				var addBtn = document.getElementById('sd-add-rule');
				var errEl = document.getElementById('sd-rule-error');

				function showError(msg){
					errEl.textContent = msg;
					errEl.style.display = 'block';
				}
				function clearError(){
					errEl.textContent = '';
					errEl.style.display = 'none';
				}

				addBtn.addEventListener('click', function(){
					clearError();
					var hostname = hostInput.value.trim();
					if(!hostname){ showError('Enter a hostname.'); return; }
					addBtn.disabled = true;

					var fd = new FormData();
					fd.append('block_id', blockId);
					fd.append('hostname', hostname);
					fd.append('action', actionSelect.value);

					fetch('/ajax/block_rule_add', {
						method: 'POST',
						body: fd,
						credentials: 'same-origin'
					}).then(function(r){ return r.json(); }).then(function(data){
						addBtn.disabled = false;
						if(!data.success){
							showError(data.error || 'Could not add rule.');
							return;
						}
						var empty = document.getElementById('domain-rules-empty');
						if(empty){ empty.remove(); }
						var tr = document.createElement('tr');
						tr.setAttribute('data-rule-id', data.rule_id);
						tr.innerHTML = '<td></td><td>'+(data.action_label)+'</td><td><button type="button" class="th-btn style5 sd-rule-delete" data-rule-id="'+data.rule_id+'" style="background:#dc3545;border-color:#dc3545;">Delete</button></td>';
						tr.firstChild.textContent = data.hostname;
						tbody.appendChild(tr);
						hostInput.value = '';
					}).catch(function(){
						addBtn.disabled = false;
						showError('Network error. Try again.');
					});
				});

				tbody.addEventListener('click', function(e){
					var btn = e.target.closest('.sd-rule-delete');
					if(!btn){ return; }
					clearError();
					var ruleId = btn.getAttribute('data-rule-id');
					btn.disabled = true;

					var fd = new FormData();
					fd.append('rule_id', ruleId);

					fetch('/ajax/block_rule_delete', {
						method: 'POST',
						body: fd,
						credentials: 'same-origin'
					}).then(function(r){ return r.json(); }).then(function(data){
						if(!data.success){
							btn.disabled = false;
							showError(data.error || 'Could not delete rule.');
							return;
						}
						var row = btn.closest('tr');
						if(row){ row.remove(); }
						if(tbody.children.length === 0){
							var empty = document.createElement('tr');
							empty.id = 'domain-rules-empty';
							empty.innerHTML = '<td colspan="3" class="text-muted">No custom rules yet.</td>';
							tbody.appendChild(empty);
						}
					}).catch(function(){
						btn.disabled = false;
						showError('Network error. Try again.');
					});
				});
			})();
			</script>
			<?php
		}
		else{
			echo '<div class="alert alert-info" role="alert" style="margin-top:8px;">';
			echo '<strong>Custom domain rules</strong> <em>(Premium & Pro)</em><br>';
			echo 'Block or allow specific websites by domain — like <code>youtube.com</code> or <code>reddit.com</code>.<br>';
			echo '<a class="th-btn" href="/scrolldaddy/pricing" style="margin-top:8px;">Upgrade</a>';
			echo '</div>';
		}
	}

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE, 'show_survey'=>TRUE));
?>

<?php if(!$is_always_on): ?>
<script>
// Overnight hint — only relevant to scheduled blocks
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
<?php endif; ?>
