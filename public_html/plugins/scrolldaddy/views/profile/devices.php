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
                <a href="/pricing" class="th-btn"><i class="fal fa-home me-2"></i>Choose your plan</a>
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
                                <div class="icon"><a href="/profile/devices?showdeleted=1"><i class="fa-regular fa-trash"></i> Deleted Devices</a></div>
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
										<span class="date"><i class="fa-regular fa-x"></i>Deleted</span>
									</div>
									<h3 class="box-title">'.$deleted_device->get_readable_name().'</h3>
								</div>
								<div class="job-post_author">
									<div class="job-wrapp">
										<div class="job-author">
											<i class="fa-regular fa-lock"></i>
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
					$seen_label = ' &bull; <i class="fa-regular fa-signal-stream"></i> '.$seen_ago;
				}
				else{
					$seen_label = ' &bull; <i class="fa-regular fa-signal-stream-slash"></i> Not yet seen';
				}
			}

			echo '
						<div class="col-lg-6 col-xxl-4">
						<div class="job-post style2">
							<div class="job-content">
								<div class="job-post_date">
									<span class="date"><i class="fa-regular fa-check"></i>Active'.$seen_label.'</span>
									<div class="icon"><a href="/profile/activation?device_id='.$device->key.'" title="Connection Details"><i class="fa-regular fa-circle-info"></i></a> <a href="/profile/device_edit?device_id='.$device->key.'"><i class="fa-regular fa-edit"></i></a></div>
								</div>
								<h3 class="box-title">'.$device->get_readable_name().'</h3>
							</div>
							<div class="job-post_author">
								<div class="job-wrapp">
									<div class="job-author">
										<i class="fa-regular fa-shield-check"></i>
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
											<i class="fa-regular fa-clock"></i>
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
										<i class="fa-regular fa-plus"></i>
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
								<i class="fa-regular fa-magnifying-glass"></i>
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
									<a href="/profile/activation?device_id='.$device->key.'"><span class="date"><i class="fa-regular fa-exclamation"></i>Needs Activation</span></a>
									<div class="icon"><a href="/profile/device_edit?device_id='.$device->key.'"><i class="fa-regular fa-edit"></i></a></div>
								</div>
								<h3 class="box-title">'.$device->get_readable_name().'</h3>
								<!--<span class="location"><i class="fa-regular fa-location-dot me-2"></i>United States</span>
								<span class="location"><i class="fa-light fa-briefcase me-2"></i>Full Time</span>-->
							</div>
							<div class="job-post_author">
								<div class="job-wrapp">
									<div class="job-author">
										<i class="fa-regular fa-lock"></i>
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

	function formatResult(data) {
		var icon, color, label;
		if (data.result === 'BLOCKED') {
			icon = 'fa-xmark-circle'; color = '#dc3545'; label = 'Blocked';
		} else if (data.result === 'FORWARDED' && (data.reason === 'safesearch_rewrite' || data.reason === 'safeyoutube_rewrite')) {
			icon = 'fa-rotate'; color = '#0d6efd'; label = 'Rewritten';
		} else if (data.result === 'FORWARDED') {
			icon = 'fa-check-circle'; color = '#198754'; label = 'Allowed';
		} else if (data.result === 'REFUSED') {
			icon = 'fa-ban'; color = '#dc3545'; label = 'Refused';
		} else {
			icon = 'fa-question-circle'; color = '#6c757d'; label = data.result;
		}

		var html = '<div style="padding:8px 0;">';
		html += '<div style="font-weight:600; color:' + color + ';"><i class="fa-regular ' + icon + '" style="margin-right:4px;"></i> ' + escHtml(data.domain) + ' &mdash; ' + label + '</div>';
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
