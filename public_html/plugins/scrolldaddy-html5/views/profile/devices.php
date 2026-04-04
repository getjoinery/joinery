<?php
	
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('devices_logic.php', 'logic', 'system', null, 'scrolldaddy'));

	$page_vars = process_logic(devices_logic($_GET, $_POST));
	$tier = $page_vars['tier'];
	$devices = $page_vars['devices'];
	$num_devices =  $page_vars['num_devices'];
	$user = $page_vars['user'];
	$num_blocks_always = $page_vars['num_blocks_always'];
	$scheduled_blocks = $page_vars['scheduled_blocks'];
	$num_deleted_devices =$page_vars['num_deleted_devices'];
	$deleted_devices = $page_vars['deleted_devices'];
	$last_seen = $page_vars['last_seen'] ?? array();

	$page = new PublicPage();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Devices', 
		'breadcrumbs' => array (
			'Devices' => '',
			),
	);
	$page->public_header($hoptions,NULL);

	echo PublicPage::BeginPage('Devices', $hoptions);

if(!$tier){

	?>
	 <section class="space">
        <div class="container">
            <!--<div class="error-img">
                <img src="assets/img/theme-img/error.svg" alt="404 image">
            </div>-->
            <div class="error-content">
                <h2 class="error-title">Choose your plan</h2>
                <p class="error-text">You haven't chosen your plan yet.</p>
                <a href="/pricing" class="th-btn"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em;margin-right:0.5rem"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9,22 9,12 15,12 15,22"/></svg>Choose your plan</a>
            </div>
        </div>
    </section>
	<?php
}
else{			

	if(!$_GET['showdeleted'] && $num_deleted_devices){
	?>
                        <div class="job-content">
                            <div class="job-post_date">
								<h3><?php echo $name; ?></h3>
                                <!--<span class="date"><i class="fa-regular fa-trash"></i></span>-->
                                <div class="icon"><a href="/profile/devices?showdeleted=1"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em"><polyline points="3,6 5,6 21,6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg> Deleted Devices</a></div>
                            </div>
                        </div>
		<?php	
	}
	else if($_GET['showdeleted'] && $num_deleted_devices){
		foreach($deleted_devices as $deleted_device){

				echo '
							<div class="col-lg-6 col-xxl-4">
							<div class="job-post style2">
								<div class="job-content">
									<div class="job-post_date">
										<span class="date"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Deleted</span>
									</div>
									<h3 class="box-title">'.$deleted_device->get_readable_name().'</h3>
								</div>
								<div class="job-post_author">
									<div class="job-wrapp">
										<div class="job-author">
											<svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
										</div>
										<div class="author-info">
											<h3 class="company-name">Deactivation Pin</h3>
											<h5 class="price">'.$deleted_device->get('sddb_deactivation_pin').'</h5>

										</div>
									</div>
									
								</div>
							</div>
						</div>';

		}
	}
	
	foreach($devices as $device){

		if($device->get('sdd_is_active')){
			$seen_label = '';
			if(isset($last_seen[$device->key])){
				if($last_seen[$device->key]['seen']){
					$diff = time() - strtotime($last_seen[$device->key]['last_seen']);
					if($diff < 60) $seen_ago = 'just now';
					elseif($diff < 3600) $seen_ago = floor($diff/60).'m ago';
					elseif($diff < 86400) $seen_ago = floor($diff/3600).'h ago';
					else $seen_ago = floor($diff/86400).'d ago';
					$seen_label = ' &bull; <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em"><path d="M2 10c0-3.87 3.13-7 7-7"/><path d="M22 10c0-3.87-3.13-7-7-7"/><path d="M5 13c0-2.21 1.79-4 4-4"/><path d="M19 13c0-2.21-1.79-4-4-4"/><circle cx="12" cy="13" r="2"/><line x1="12" y1="15" x2="12" y2="22"/></svg> '.$seen_ago;
				}
				else{
					$seen_label = ' &bull; <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em"><line x1="1" y1="1" x2="23" y2="23"/><path d="M2 10c0-3.87 3.13-7 7-7"/><path d="M22 10c0-3.87-3.13-7-7-7"/><path d="M5 13c0-2.21 1.79-4 4-4"/><circle cx="12" cy="13" r="2"/><line x1="12" y1="15" x2="12" y2="22"/></svg> Not yet seen';
				}
			}

			echo '
						<div class="col-lg-6 col-xxl-4">
						<div class="job-post style2">
							<div class="job-content">
								<div class="job-post_date">
									<span class="date"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em"><polyline points="20,6 9,17 4,12"/></svg>Active'.$seen_label.'</span>
									<div class="icon"><a href="/profile/activation?device_id='.$device->key.'" title="Connection Details"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></a> <a href="/profile/device_edit?device_id='.$device->key.'"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></a></div>
								</div>
								<h3 class="box-title">'.$device->get_readable_name().'</h3>
							</div>
							<div class="job-post_author">
								<div class="job-wrapp">
									<div class="job-author">
										<svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
									</div>
									<div class="author-info">
										<h3 class="company-name">Always-On Filters</h3>
										<h5 class="price">'.$num_blocks_always[$device->key].' blocked</h5>
									</div>
								</div>
								<a class="th-btn style5" href="/profile/filters_edit?device_id='.$device->key.'">Edit</a>
							</div>';

							// SCHEDULED BLOCKS
							$device_blocks = $scheduled_blocks[$device->key] ?? null;
							$has_blocks = $device_blocks && count($device_blocks) > 0;

							if($has_blocks){
								foreach($device_blocks as $sblock){
									$block_name = htmlspecialchars($sblock->get('sdb_name') ?: 'Unnamed');
									$schedule_display = htmlspecialchars($sblock->get_schedule_display());
									$rule_count = $sblock->count_rules();
									$active_badge = $sblock->is_active_now() ? ' <span class="badge" style="background:#198754;color:#fff;font-size:11px;padding:2px 6px;border-radius:3px;">Active now</span>' : '';

									echo '
								<br>
								<div class="job-post_author">
									<div class="job-wrapp">
										<div class="job-author">
											<svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em"><circle cx="12" cy="12" r="10"/><polyline points="12,6 12,12 16,14"/></svg>
										</div>
										<div class="author-info">
											<h3 class="company-name">'.$block_name.$active_badge.'</h3>
											<h5 class="price">'.$schedule_display.' ('.$rule_count.' rules)</h5>
										</div>
									</div>
									<div style="display:flex; gap:6px;">
										<a class="th-btn style5" href="/profile/scheduled_block_edit?device_id='.$device->key.'&block_id='.$sblock->key.'">Edit</a>
										<form method="POST" action="/profile/scheduled_block_edit" style="display:inline;" onsubmit="return confirm(\'Delete this scheduled block?\')">
											<input type="hidden" name="action" value="delete">
											<input type="hidden" name="block_id" value="'.$sblock->key.'">
											<button type="submit" class="th-btn style5" style="background:#dc3545;border-color:#dc3545;">Delete</button>
										</form>
									</div>
								</div>';
								}
							}

							echo '
							<br>
							<div class="job-post_author">
								<div class="job-wrapp">
									<div class="job-author">
										<svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
									</div>
									<div class="author-info">
										<h3 class="company-name">Scheduled Blocks</h3>
										<h5 class="price">'.($has_blocks ? '' : 'None.').'</h5>
									</div>
								</div>
								<a class="th-btn style5" href="/profile/scheduled_block_edit?device_id='.$device->key.'">Add</a>
							</div>';

							echo '
					<br>
					<div class="job-post_author">
						<div class="job-wrapp">
							<div class="job-author">
								<svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
							</div>
							<div class="author-info">
								<h3 class="company-name">Test a Domain</h3>
								<div class="scd-test-result" id="scd-result-'.$device->key.'" style="display:none; margin-top:8px;"></div>
							</div>
						</div>
						<div style="display:flex; gap:8px;">
							<input type="text" class="scd-test-input" data-device-id="'.$device->key.'" placeholder="e.g. facebook.com" style="padding:6px 10px; border:1px solid #ddd; border-radius:4px; font-size:14px; width:160px;">
							<button type="button" class="th-btn style5 scd-test-btn" data-device-id="'.$device->key.'">Test</button>
						</div>
					</div>
					</div>
				</div>';

		}
		else{
			echo '
						<div class="col-lg-6 col-xxl-4">
						<div class="job-post style2">
							<div class="job-content">
								<div class="job-post_date">
									<a href="/profile/activation?device_id='.$device->key.'"><span class="date"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>Needs Activation</span></a>
									<div class="icon"><a href="/profile/device_edit?device_id='.$device->key.'"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></a></div>
								</div>
								<h3 class="box-title">'.$device->get_readable_name().'</h3>
								<!--<span class="location"><i class="fa-regular fa-location-dot me-2"></i>United States</span>
								<span class="location"><i class="fa-light fa-briefcase me-2"></i>Full Time</span>-->
							</div>
							<div class="job-post_author">
								<div class="job-wrapp">
									<div class="job-author">
										<svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
									</div>
									<div class="author-info">
										<h3 class="company-name">Needs Activation</h3>
										<h5 class="price">Download our app</span></h5>

									</div>
								</div>
								<a class="th-btn style5" href="/profile/activation?device_id='.$device->key.'">Activate</a>
							</div>
							
						</div>
					</div>';
		}
	}

	// Check if user can add more devices based on tier limit
	$max_devices = $tier->getFeature('scrolldaddy_max_devices', 0);

	if($num_devices < $max_devices){
		?>
					<div class="col-lg-6 col-xxl-4">
						<div class="job-post style2">
							<div class="job-content">
								<div class="job-post_date">
									<h3 class="box-title">Add a Device</h3>
									<a class="th-btn style5" href="/profile/device_edit">Add</a>
								</div>
								<!--<span class="location"><i class="fa-regular fa-location-dot me-2"></i>United States</span>
								<span class="location"><i class="fa-light fa-briefcase me-2"></i>Full Time</span>-->
							</div>

						</div>
					</div>
	<?php
	}
	else{
		?>
					<div class="col-lg-6 col-xxl-4">
						<div class="job-post style2">
							<div class="job-content">
								<div class="job-post_date">
									<h3 class="box-title">Upgrade for more devices</h3>
									<a class="th-btn style5" href="/profile/change-tier">Upgrade</a>
								</div>
								<!--<span class="location"><i class="fa-regular fa-location-dot me-2"></i>United States</span>
								<span class="location"><i class="fa-light fa-briefcase me-2"></i>Full Time</span>-->
							</div>

						</div>
					</div>
	<?php

	}
}

	echo PublicPage::EndPage();
?>
<script>
(function() {
	function cleanDomain(input) {
		var d = input.trim().toLowerCase();
		d = d.replace(/^https?:\/\//, '');
		d = d.replace(/[\/\?#].*$/, '');
		d = d.replace(/\.+$/, '');
		return d.trim();
	}

	function testDomain(deviceId) {
		var input = document.querySelector('.scd-test-input[data-device-id="' + deviceId + '"]');
		var resultDiv = document.getElementById('scd-result-' + deviceId);
		var domain = cleanDomain(input.value);

		if (!domain || domain.indexOf('.') === -1) {
			resultDiv.style.display = 'block';
			resultDiv.innerHTML = '<span style="color:#dc3545;">Please enter a valid domain (e.g. facebook.com)</span>';
			return;
		}

		resultDiv.style.display = 'block';
		resultDiv.innerHTML = '<span style="color:#6c757d;">Testing...</span>';

		var xhr = new XMLHttpRequest();
		xhr.open('GET', '/ajax/test_domain?device_id=' + deviceId + '&domain=' + encodeURIComponent(domain));
		xhr.onload = function() {
			if (xhr.status !== 200) {
				resultDiv.innerHTML = '<span style="color:#dc3545;">Request failed. Please try again.</span>';
				return;
			}
			var data;
			try { data = JSON.parse(xhr.responseText); } catch(e) {
				resultDiv.innerHTML = '<span style="color:#dc3545;">Invalid response.</span>';
				return;
			}
			if (!data.success) {
				resultDiv.innerHTML = '<span style="color:#dc3545;">' + escHtml(data.message) + '</span>';
				return;
			}
			resultDiv.innerHTML = formatResult(data);
		};
		xhr.onerror = function() {
			resultDiv.innerHTML = '<span style="color:#dc3545;">Network error. Please try again.</span>';
		};
		xhr.send();
	}

	var _svgAttrs = 'xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em;margin-right:4px;"';
	var _icons = {
		'xmark-circle': '<svg ' + _svgAttrs + '><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
		'rotate':       '<svg ' + _svgAttrs + '><polyline points="23,4 23,10 17,10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>',
		'check-circle': '<svg ' + _svgAttrs + '><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22,4 12,14.01 9,11.01"/></svg>',
		'ban':          '<svg ' + _svgAttrs + '><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>',
		'question-circle': '<svg ' + _svgAttrs + '><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>'
	};

	function formatResult(data) {
		var iconKey, color, label;
		if (data.result === 'BLOCKED') {
			iconKey = 'xmark-circle'; color = '#dc3545'; label = 'Blocked';
		} else if (data.result === 'FORWARDED' && (data.reason === 'safesearch_rewrite' || data.reason === 'safeyoutube_rewrite')) {
			iconKey = 'rotate'; color = '#0d6efd'; label = 'Rewritten';
		} else if (data.result === 'FORWARDED') {
			iconKey = 'check-circle'; color = '#198754'; label = 'Allowed';
		} else if (data.result === 'REFUSED') {
			iconKey = 'ban'; color = '#dc3545'; label = 'Refused';
		} else {
			iconKey = 'question-circle'; color = '#6c757d'; label = data.result;
		}

		var html = '<div style="padding:8px 0;">';
		html += '<div style="font-weight:600; color:' + color + ';">' + _icons[iconKey] + ' ' + escHtml(data.domain) + ' &mdash; ' + label + '</div>';
		if (data.detail) {
			html += '<div style="font-size:13px; color:#6c757d; margin-top:2px; padding-left:20px;">' + escHtml(data.detail) + '</div>';
		}
		if (data.profile) {
			html += '<div style="font-size:13px; color:#6c757d; padding-left:20px;">Active profile: ' + escHtml(data.profile) + '</div>';
		}
		html += '</div>';
		return html;
	}

	function escHtml(s) {
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(s));
		return div.innerHTML;
	}

	// Bind click handlers
	document.querySelectorAll('.scd-test-btn').forEach(function(btn) {
		btn.addEventListener('click', function() {
			testDomain(this.getAttribute('data-device-id'));
		});
	});

	// Bind Enter key on inputs
	document.querySelectorAll('.scd-test-input').forEach(function(input) {
		input.addEventListener('keydown', function(e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				testDomain(this.getAttribute('data-device-id'));
			}
		});
	});
})();
</script>
<?php
	$page->public_footer($foptions=array('track'=>TRUE, 'show_survey'=>TRUE));
?>
