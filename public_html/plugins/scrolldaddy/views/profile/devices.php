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
	$always_on_block_ids = $page_vars['always_on_block_ids'] ?? array();
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
                <a href="/scrolldaddy/pricing" class="th-btn"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em;margin-right:0.5rem"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9,22 9,12 15,12 15,22"/></svg>Choose your plan</a>
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
                                <div class="icon"><a href="/profile/scrolldaddy/devices?showdeleted=1"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em"><polyline points="3,6 5,6 21,6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg> Deleted Devices</a></div>
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
	
	$session = SessionControl::get_instance();
	$has_custom_rules = SubscriptionTier::getUserFeature($user->key, 'scrolldaddy_custom_rules', false);

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
									<div class="scd-actions-wrap"><button class="scd-actions-btn" aria-expanded="false" aria-label="Device actions"><svg xmlns="http://www.w3.org/2000/svg" width="1.2em" height="1.2em" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" style="vertical-align:-0.125em"><circle cx="12" cy="5" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="12" cy="19" r="1.5"/></svg></button><div class="scd-actions-menu" role="menu"><a href="/profile/scrolldaddy/activation?device_id='.$device->key.'" role="menuitem">Connection Details</a><a href="/profile/scrolldaddy/device_edit?device_id='.$device->key.'" role="menuitem">Edit Device</a><a href="/profile/scrolldaddy/test?device_id='.$device->key.'" role="menuitem">Test a Domain/Page</a>'.($device->get('sdd_log_queries') ? '<a href="/profile/scrolldaddy/querylog?device_id='.$device->key.'" role="menuitem">View Query Log</a>' : '').'</div></div>
								</div>
								<h3 class="box-title">'.$device->get_readable_name().'</h3>
							</div>
							<div class="job-post_author">
								<div class="job-wrapp">
									<div class="job-author">
										<svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
									</div>
									<div class="author-info">
										<h3 class="company-name">Always-On Rules</h3>
										<h5 class="price">'.$num_blocks_always[$device->key].' blocked</h5>
									</div>
								</div>
								<a class="th-btn style5" href="/profile/scrolldaddy/scheduled_block_edit?device_id='.$device->key.'&block_id='.$always_on_block_ids[$device->key].'">Edit</a>
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
										<a class="th-btn style5" href="/profile/scrolldaddy/scheduled_block_edit?device_id='.$device->key.'&block_id='.$sblock->key.'">Edit</a>
										<form method="POST" action="/profile/scrolldaddy/scheduled_block_edit" style="display:inline;" onsubmit="return confirm(\'Delete this scheduled block?\')">
											<input type="hidden" name="action" value="delete">
											<input type="hidden" name="block_id" value="'.$sblock->key.'">
											<button type="submit" class="th-btn style5" style="background:#dc3545;border-color:#dc3545;">Delete</button>
										</form>
									</div>
								</div>';
								}
							}

							$max_blocks = $tier ? $tier->getFeature('scrolldaddy_max_scheduled_blocks', 1) : 1;
							$block_count = $device_blocks ? count($device_blocks) : 0;
							$add_block_btn = ($block_count >= $max_blocks)
								? '<span style="font-size:13px; color:#6c757d;">Upgrade to add more</span>'
								: '<a class="th-btn style5" href="/profile/scrolldaddy/scheduled_block_edit?device_id='.$device->key.'">Add</a>';

							echo '
							<br>
							<div class="job-post_author">
								<div class="job-wrapp">
									<div class="job-author">
										<svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
									</div>
									<div class="author-info">
										<h3 class="company-name">Scheduled Filters</h3>
										<h5 class="price">'.($has_blocks ? '' : 'None.').'</h5>
									</div>
								</div>
								'.$add_block_btn.'
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
									<a href="/profile/scrolldaddy/activation?device_id='.$device->key.'"><span class="date"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>Needs Activation</span></a>
									<div class="icon"><a href="/profile/scrolldaddy/device_edit?device_id='.$device->key.'">Edit Device</a></div>
								</div>
								<h3 class="box-title">'.$device->get_readable_name().'</h3>
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
								<a class="th-btn style5" href="/profile/scrolldaddy/activation?device_id='.$device->key.'">Activate</a>
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
									<a class="th-btn style5" href="/profile/scrolldaddy/device_edit">Add</a>
								</div>
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
							</div>

						</div>
					</div>
	<?php

	}
}

	echo PublicPage::EndPage();
?>
<style>
.scd-actions-wrap { position: relative; display: inline-block; }
.scd-actions-btn { background: none; border: none; cursor: pointer; padding: 4px 6px; line-height: 1; border-radius: 4px; color: inherit; }
.scd-actions-btn:hover { background: rgba(0,0,0,0.07); }
.scd-actions-menu { display: none; position: absolute; right: 0; top: calc(100% + 4px); min-width: 190px; background: #fff; border: 1px solid #e0e0e0; border-radius: 6px; box-shadow: 0 4px 14px rgba(0,0,0,0.12); z-index: 200; padding: 4px 0; }
.scd-actions-menu.is-open { display: block; }
.scd-actions-menu a { display: block; padding: 8px 16px; color: #333; text-decoration: none; font-size: 14px; white-space: nowrap; }
.scd-actions-menu a:hover { background: #f5f5f5; }
</style>
<script>
(function () {
	document.addEventListener('click', function (e) {
		var btn = e.target.closest('.scd-actions-btn');
		// Close all open menus first
		document.querySelectorAll('.scd-actions-menu.is-open').forEach(function (m) {
			m.classList.remove('is-open');
			m.previousElementSibling.setAttribute('aria-expanded', 'false');
		});
		if (btn) {
			var menu = btn.nextElementSibling;
			menu.classList.add('is-open');
			btn.setAttribute('aria-expanded', 'true');
		}
	});
})();
</script>
<?php
	$page->public_footer($foptions=array('track'=>TRUE, 'show_survey'=>TRUE));
?>
