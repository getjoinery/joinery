<?php

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
require_once(PathHelper::getIncludePath('plugins/dns_filtering/includes/ScrollDaddyHelper.php'));

require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('scheduled_block_edit_logic.php', 'logic', 'system', null, 'dns_filtering'));

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
		$page_title = ($is_edit ? 'Edit' : 'Add') . ' Scheduled Filter';
		$save_label = 'Save';
	}

	$page = new PublicPage();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => $page_title,
		'breadcrumbs' => array (
			'My Profile' => '/profile',
			'Devices' => '/profile/dns_filtering/devices',
			$page_title => ''),
	);
	$page->public_header($hoptions,NULL);

	echo PublicPage::BeginPage($page_title, $hoptions);

	$name = 'New Device';
	if($device->get('sdd_device_name')){
		$name = $device->get_readable_name();
	}

	$formwriter = $page->getFormWriter('form1', [
		'action' => '/profile/dns_filtering/scheduled_block_edit'
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

	// LABEL — only for scheduled filters. Always-on filter name is fixed.
	if(!$is_always_on){
		$formwriter->textinput('sdb_name', 'Label', [
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

	// CATEGORY RULES — split rendering by always-on vs scheduled.
	// Always-on uses a binary Block | Allow segmented radio per category. "Allow" submits
	// the empty string so update_filters() / update_services() delete any existing row —
	// this matters because the resolver merge unions all AllowKeys across active blocks
	// to delete categories from effectiveCategories, so an explicit allow row on the
	// always-on baseline would silently erase any "Block" override on a scheduled block.
	// Scheduled blocks are an overrides list — only the rows the user has chosen to
	// override against the always-on baseline are shown; "Add override" appends new rows.
	$can_edit_main = $device->are_filters_editable();
	$has_advanced = SubscriptionTier::getUserFeature($session->get_user_id(), 'scrolldaddy_advanced_filters', false);

	// Curated category metadata. Each entry: 'type' => 'filter'|'service' tells us which
	// rules table the key belongs to (drives lookup in $filter_rules vs $service_rules).
	// 'advanced' marks tier-gated entries (ScrollDaddyHelper::getRestrictedFilters()).
	$lifestyle_groups = [
		'General' => [
			'porn' => ['label' => 'Adult content', 'type' => 'filter'],
			'drugs' => ['label' => 'Illegal drugs', 'type' => 'filter'],
			'gambling' => ['label' => 'Gambling sites', 'type' => 'filter'],
			'cryptominers' => ['label' => 'Cryptocurrency', 'type' => 'filter'],
			'dating' => ['label' => 'Dating sites', 'type' => 'filter'],
			'games' => ['label' => 'Games', 'type' => 'filter'],
		],
		'Search Safety' => [
			'safesearch' => ['label' => 'SafeSearch (Google / Bing / DuckDuckGo)', 'type' => 'filter'],
			'safeyoutube' => ['label' => 'YouTube Restricted Mode', 'type' => 'filter'],
		],
		'Social Media' => [
			'facebook' => ['label' => 'Facebook', 'type' => 'service'],
			'instagram' => ['label' => 'Instagram', 'type' => 'service'],
			'linkedin' => ['label' => 'LinkedIn', 'type' => 'service'],
			'pinterest' => ['label' => 'Pinterest', 'type' => 'service'],
			'reddit' => ['label' => 'Reddit', 'type' => 'service'],
			'snapchat' => ['label' => 'Snapchat', 'type' => 'service'],
			'tiktok' => ['label' => 'TikTok', 'type' => 'service'],
			'twitter' => ['label' => 'Twitter / X', 'type' => 'service'],
			'wechat' => ['label' => 'WeChat', 'type' => 'service'],
			'youtube' => ['label' => 'YouTube', 'type' => 'service'],
		],
		'Messaging' => [
			'discord' => ['label' => 'Discord', 'type' => 'service'],
			'messenger' => ['label' => 'Messenger', 'type' => 'service'],
			'telegram' => ['label' => 'Telegram', 'type' => 'service'],
			'whatsapp' => ['label' => 'WhatsApp', 'type' => 'service'],
		],
	];
	$advanced_groups = [
		'Ads & Trackers' => [
			'ads_small' => ['label' => 'Ads & Trackers (Light)', 'type' => 'filter'],
			'ads_medium' => ['label' => 'Ads & Trackers (Balanced)', 'type' => 'filter'],
			'ads' => ['label' => 'Ads & Trackers (Strict)', 'type' => 'filter'],
		],
		'Malware & Phishing' => [
			'malware' => ['label' => 'Malware (Light)', 'type' => 'filter'],
			'ip_malware' => ['label' => 'Malware (Balanced)', 'type' => 'filter'],
			'ai_malware' => ['label' => 'Malware (Strict)', 'type' => 'filter'],
			'typo' => ['label' => 'Phishing domains', 'type' => 'filter'],
			'fakenews' => ['label' => 'Hoaxes and disinformation', 'type' => 'filter'],
		],
	];
	$advanced_keys = ScrollDaddyHelper::getRestrictedFilters();

	// Flatten metadata for quick lookup by key
	$category_meta = [];
	foreach($lifestyle_groups as $gname => $items){
		foreach($items as $k => $v){ $category_meta[$k] = $v + ['advanced' => false, 'group' => $gname]; }
	}
	foreach($advanced_groups as $gname => $items){
		foreach($items as $k => $v){ $category_meta[$k] = $v + ['advanced' => true, 'group' => $gname]; }
	}

	$lookup_action = function($key, $type) use ($filter_rules, $service_rules) {
		if($type === 'filter'){ return isset($filter_rules[$key]) ? (string)$filter_rules[$key] : null; }
		return isset($service_rules[$key]) ? (string)$service_rules[$key] : null;
	};

	// Renders a Block | Allow segmented radio. $allow_submit_value differs by mode:
	//   always-on -> '' (so submitting "Allow" deletes the row, see resolver-merge note above)
	//   scheduled -> '1' (explicit allow row, intentionally overrides always-on Block)
	$render_segmented = function($key, $current_action, $allow_submit_value, $disabled = false) {
		$name = 'rule_' . $key;
		$id_block = $name . '_block';
		$id_allow = $name . '_allow';
		$is_block = ((string)$current_action === '0');
		$is_allow = !$is_block && $current_action !== null;
		// No existing row -> default Allow (no row in DB == not blocked at the resolver)
		if(!$is_block && !$is_allow){ $is_allow = true; }
		$dis = $disabled ? ' disabled' : '';
		$h  = '<div class="sd-segmented" role="radiogroup">';
		$h .= '<input type="radio" name="'.htmlspecialchars($name).'" id="'.htmlspecialchars($id_block).'" value="0"'.($is_block?' checked':'').$dis.'>';
		$h .= '<label for="'.htmlspecialchars($id_block).'">Block</label>';
		$h .= '<input type="radio" name="'.htmlspecialchars($name).'" id="'.htmlspecialchars($id_allow).'" value="'.htmlspecialchars((string)$allow_submit_value).'"'.($is_allow?' checked':'').$dis.'>';
		$h .= '<label for="'.htmlspecialchars($id_allow).'">Allow</label>';
		$h .= '</div>';
		return $h;
	};

	if($is_always_on){
		// ===== ALWAYS-ON: BASELINE EDITOR (binary Block | Allow per category) =====
		if($can_edit_main){
			foreach($lifestyle_groups as $group_name => $items){
				echo '<h5>'.htmlspecialchars($group_name).'</h5>';
				echo '<div class="sd-rule-section">';
				foreach($items as $key => $meta){
					$cur = $lookup_action($key, $meta['type']);
					echo '<div class="sd-rule-row sd-auto-save" data-key="'.htmlspecialchars($key).'" data-type="'.htmlspecialchars($meta['type']).'">';
					echo '<span class="sd-rule-label">'.htmlspecialchars($meta['label']).'</span>';
					echo $render_segmented($key, $cur, '');
					echo '</div>';
				}
				echo '</div>';
			}
		}
		else{
			echo '<div class="alert alert-warning" role="alert">
			  Since you have chosen to allow edits only on Sunday. Edits are disabled, except for ad and malware blocking.
			</div>';
		}

		if($has_advanced){
			foreach($advanced_groups as $group_name => $items){
				echo '<h5>'.htmlspecialchars($group_name).'</h5>';
				echo '<div class="sd-rule-section">';
				foreach($items as $key => $meta){
					$cur = $lookup_action($key, $meta['type']);
					echo '<div class="sd-rule-row sd-auto-save" data-key="'.htmlspecialchars($key).'" data-type="'.htmlspecialchars($meta['type']).'">';
					echo '<span class="sd-rule-label">'.htmlspecialchars($meta['label']).'</span>';
					echo $render_segmented($key, $cur, '');
					echo '</div>';
				}
				echo '</div>';
			}
		}
	}
	else{
		// ===== SCHEDULED: FILTERS LIST =====
		echo '<h5>Filters</h5>';

		// Build the list of currently-overridden categories from the loaded rules.
		$overrides = [];
		foreach($filter_rules as $k => $action){
			if(isset($category_meta[$k]) && $category_meta[$k]['type'] === 'filter'){
				$overrides[$k] = (string)$action;
			}
		}
		foreach($service_rules as $k => $action){
			if(isset($category_meta[$k]) && $category_meta[$k]['type'] === 'service'){
				$overrides[$k] = (string)$action;
			}
		}

		echo '<div id="sd-overrides-list" class="sd-rule-section">';
		if(empty($overrides)){
			echo '<div id="sd-overrides-empty" class="sd-empty-state">No filters yet — this schedule uses your always-on rules.</div>';
		}
		foreach($overrides as $key => $action){
			$meta = $category_meta[$key];
			$is_advanced = !empty($meta['advanced']);
			// Editable when: $can_edit_main allows lifestyle edits, OR (advanced editing is gated only by tier)
			$editable = $is_advanced ? $has_advanced : $can_edit_main;
			echo '<div class="sd-rule-row sd-override-row" data-key="'.htmlspecialchars($key).'" data-advanced="'.($is_advanced?'1':'0').'">';
			echo '<span class="sd-rule-label">'.htmlspecialchars($meta['label']).'</span>';
			echo '<div class="sd-rule-controls">';
			echo $render_segmented($key, $action, '1', !$editable);
			if($editable || ($is_advanced && !$has_advanced)){
				// Allow remove for editable rows always; for downgraded users, remove on
				// advanced is the option-C escape hatch (visible + removable, not editable).
				echo '<button type="button" class="btn-remove-override" aria-label="Remove filter">&times;</button>';
			}
			echo '</div>';
			echo '</div>';
		}
		echo '</div>';

		// Add picker — selecting a category auto-adds it (default action: Block).
		// Categories already in the list are filtered out client-side.
		if($can_edit_main || $has_advanced){
			echo '<div id="sd-add-override" class="sd-add-override">';
			echo '<select id="sd-add-category" aria-label="Add a filter">';
			echo '<option value="">Add a filter…</option>';
			$render_optgroup = function($groups, $is_advanced_group) use ($overrides, $can_edit_main, $has_advanced){
				foreach($groups as $group_name => $items){
					$g_html = '';
					foreach($items as $k => $meta){
						if(isset($overrides[$k])) continue; // already in list
						if(!$is_advanced_group && !$can_edit_main) continue; // lifestyle locked
						if($is_advanced_group && !$has_advanced) continue; // advanced gated
						$g_html .= '<option value="'.htmlspecialchars($k).'" data-type="'.htmlspecialchars($meta['type']).'" data-advanced="'.($is_advanced_group?'1':'0').'">'.htmlspecialchars($meta['label']).'</option>';
					}
					if($g_html !== ''){
						echo '<optgroup label="'.htmlspecialchars($group_name).'">'.$g_html.'</optgroup>';
					}
				}
			};
			$render_optgroup($lifestyle_groups, false);
			$render_optgroup($advanced_groups, true);
			echo '</select>';
			echo '</div>';
		}
		else{
			echo '<p class="text-muted"><em>Adding new filters is locked while accountability mode is active.</em></p>';
		}
	}

	// Always-on edits save-on-change via AJAX, so the explicit submit button is unnecessary.
	if(!$is_always_on){
		$formwriter->submitbutton('btn_submit', $save_label, ['class' => 'btn btn-primary']);
	}
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
			echo '<a class="th-btn" href="/pricing" style="margin-top:8px;">Upgrade</a>';
			echo '</div>';
		}
	}

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE, 'show_survey'=>TRUE));
?>

<?php if($is_always_on): ?>
<script>
// Always-on save-on-change: each segmented Block|Allow change posts to the
// per-rule AJAX endpoint immediately, so users don't scroll for a Save button.
(function(){
	var rows = document.querySelectorAll('.sd-rule-row.sd-auto-save');
	if(!rows.length){ return; }
	var blockId = <?php echo (int)$block->key; ?>;
	if(!blockId){ return; }

	function flash(row, cls){
		row.classList.remove('sd-saving','sd-saved','sd-save-error');
		row.classList.add(cls);
		if(cls === 'sd-saved'){
			setTimeout(function(){ row.classList.remove('sd-saved'); }, 1200);
		}
	}

	rows.forEach(function(row){
		var key = row.getAttribute('data-key');
		var type = row.getAttribute('data-type');
		row.querySelectorAll('input[type="radio"]').forEach(function(r){
			r.addEventListener('change', function(){
				if(!r.checked){ return; }
				flash(row, 'sd-saving');
				var fd = new FormData();
				fd.append('block_id', blockId);
				fd.append('type', type);
				fd.append('key', key);
				fd.append('action', r.value);
				fetch('/ajax/block_filter_set', {
					method: 'POST', body: fd, credentials: 'same-origin'
				})
				.then(function(res){ return res.json(); })
				.then(function(j){
					flash(row, j.success ? 'sd-saved' : 'sd-save-error');
					if(!j.success && j.error){ console.error('Save failed:', j.error); }
				})
				.catch(function(e){
					flash(row, 'sd-save-error');
					console.error('Save failed:', e);
				});
			});
		});
	});
})();
</script>
<?php endif; ?>

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

// Override list management
(function(){
	var list = document.getElementById('sd-overrides-list');
	if(!list){ return; }
	var addCat = document.getElementById('sd-add-category');
	var form = list.closest('form');

	function escapeHtml(s){
		return String(s).replace(/[&<>"']/g, function(c){
			return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
		});
	}

	function clearEmptyMessage(){
		var empty = document.getElementById('sd-overrides-empty');
		if(empty){ empty.remove(); }
	}

	function showEmptyMessageIfNeeded(){
		if(list.querySelectorAll('.sd-override-row').length === 0 && !document.getElementById('sd-overrides-empty')){
			var d = document.createElement('div');
			d.id = 'sd-overrides-empty';
			d.className = 'sd-empty-state';
			d.textContent = 'No filters yet — this schedule uses your always-on rules.';
			list.appendChild(d);
		}
	}

	function buildRow(key, label, action, isAdvanced){
		var row = document.createElement('div');
		row.className = 'sd-rule-row sd-override-row';
		row.setAttribute('data-key', key);
		row.setAttribute('data-advanced', isAdvanced ? '1' : '0');
		var nameAttr = 'rule_' + key;
		var idBlock = nameAttr + '_block';
		var idAllow = nameAttr + '_allow';
		var blockChecked = action === '0' ? ' checked' : '';
		var allowChecked = action === '1' ? ' checked' : '';
		row.innerHTML =
			'<span class="sd-rule-label">' + escapeHtml(label) + '</span>' +
			'<div class="sd-rule-controls">' +
				'<div class="sd-segmented" role="radiogroup">' +
					'<input type="radio" name="' + escapeHtml(nameAttr) + '" id="' + escapeHtml(idBlock) + '" value="0"' + blockChecked + '>' +
					'<label for="' + escapeHtml(idBlock) + '">Block</label>' +
					'<input type="radio" name="' + escapeHtml(nameAttr) + '" id="' + escapeHtml(idAllow) + '" value="1"' + allowChecked + '>' +
					'<label for="' + escapeHtml(idAllow) + '">Allow</label>' +
				'</div>' +
				'<button type="button" class="btn-remove-override" aria-label="Remove filter">&times;</button>' +
			'</div>';
		return row;
	}

	if(addCat){
		addCat.addEventListener('change', function(){
			if(!addCat.value){ return; }
			var opt = addCat.options[addCat.selectedIndex];
			var key = addCat.value;
			var label = opt.textContent;
			var isAdvanced = opt.getAttribute('data-advanced') === '1';

			clearEmptyMessage();
			// New filters default to Block; user can flip via the segmented control on the row.
			var row = buildRow(key, label, '0', isAdvanced);
			list.appendChild(row);

			// Remove the chosen option from the picker so the user can't add a duplicate.
			opt.remove();
			// If the optgroup is now empty, remove it for cleanliness.
			addCat.querySelectorAll('optgroup').forEach(function(og){
				if(og.children.length === 0){ og.remove(); }
			});
			addCat.selectedIndex = 0;
		});
	}

	list.addEventListener('click', function(e){
		var btn = e.target.closest('.btn-remove-override');
		if(!btn){ return; }
		var row = btn.closest('.sd-override-row');
		if(!row){ return; }
		var key = row.getAttribute('data-key');
		var isAdvanced = row.getAttribute('data-advanced') === '1';

		// If this is an advanced override and the user lacks the feature, the radio
		// inputs are disabled and won't submit anything — so update_filters() with
		// $skip_keys would preserve the row. Fall back to the explicit-delete path.
		var radio = row.querySelector('input[type="radio"]');
		if(isAdvanced && radio && radio.disabled){
			var hidden = document.createElement('input');
			hidden.type = 'hidden';
			hidden.name = 'remove_advanced_keys[]';
			hidden.value = key;
			form.appendChild(hidden);
		}

		row.remove();
		showEmptyMessageIfNeeded();

		// Restore the option to the picker (only if user can edit this category).
		if(addCat){
			var meta = window.SD_CATEGORY_META && window.SD_CATEGORY_META[key];
			if(meta){
				var canRestore = isAdvanced ? window.SD_HAS_ADVANCED : window.SD_CAN_EDIT_MAIN;
				if(canRestore){
					var groupName = meta.group;
					var og = addCat.querySelector('optgroup[label="' + CSS.escape(groupName) + '"]');
					if(!og){
						og = document.createElement('optgroup');
						og.setAttribute('label', groupName);
						addCat.appendChild(og);
					}
					var newOpt = document.createElement('option');
					newOpt.value = key;
					newOpt.textContent = meta.label;
					newOpt.setAttribute('data-type', meta.type);
					newOpt.setAttribute('data-advanced', isAdvanced ? '1' : '0');
					og.appendChild(newOpt);
				}
			}
		}
	});
})();
</script>
<script>
// Category metadata + tier flags exported to JS for override list re-population.
window.SD_CATEGORY_META = <?php echo json_encode($category_meta); ?>;
window.SD_CAN_EDIT_MAIN = <?php echo $can_edit_main ? 'true' : 'false'; ?>;
window.SD_HAS_ADVANCED = <?php echo $has_advanced ? 'true' : 'false'; ?>;
</script>
<?php endif; ?>
