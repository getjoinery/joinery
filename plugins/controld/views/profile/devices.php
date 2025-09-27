<?php
	
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('devices_logic.php', 'logic', 'system', null, 'controld'));

	$page_vars = process_logic(devices_logic($_GET, $_POST));
	$account = $page_vars['account'];
	$devices = $page_vars['devices'];
	$num_devices =  $page_vars['num_devices'];
	$user = $page_vars['user'];
	$num_blocks_always = $page_vars['num_blocks_always'];
	$num_blocks_scheduled = $page_vars['num_blocks_scheduled'];
	$scheduled_string = $page_vars['scheduled_string'];
	$num_deleted_devices =$page_vars['num_deleted_devices'];
	$deleted_devices = $page_vars['deleted_devices'];	

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

if(!$account){
	
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
else if(!$account->is_active()){	

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

	foreach($devices as $device){

		echo '
					<div class="col-lg-6 col-xxl-4">
					<div class="job-post style2">
						<div class="job-content">
							<div class="job-post_date">
								<span class="date"><i class="fa-regular fa-check"></i>Active</span>
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
									<h3 class="company-name">Deactivation Code</h3>
									<h5 class="price">'.$device->get('cdd_deactivation_pin').'</h5>

								</div>
							</div>
							
						</div>
					</div>
				</div>';

	}		
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
											<h5 class="price">'.$deleted_device->get('cdb_deactivation_pin').'</h5>

										</div>
									</div>
									
								</div>
							</div>
						</div>';

		}
	}
	
	foreach($devices as $device){

		if($device->get('cdd_is_active')){	
			if($device->get_active_profile() == 'primary'){
				$primary_icon = 'fa-shield-check';
				$secondary_icon = 'fa-shield-slash';
			}
			else{
				$primary_icon = 'fa-shield-slash';
				$secondary_icon = 'fa-shield-check';
			}
			echo '
						<div class="col-lg-6 col-xxl-4">
						<div class="job-post style2">
							<div class="job-content">
								<div class="job-post_date">
									<span class="date"><i class="fa-regular fa-check"></i>Active: '.$device->get_active_profile('readable').'</span>
									<div class="icon"><a href="/profile/device_edit?device_id='.$device->key.'"><i class="fa-regular fa-edit"></i></a></div>
								</div>
								<h3 class="box-title">'.$device->get_readable_name().'</h3>
								<!--<span class="location"><i class="fa-regular fa-location-dot me-2"></i>United States</span>
								<span class="location"><i class="fa-light fa-briefcase me-2"></i>Full Time</span>-->
							</div>
							<div class="job-post_author">
								<div class="job-wrapp">
									<div class="job-author">
										<i class="fa-regular '.$primary_icon.'"></i>
									</div>
									<div class="author-info">
										<h3 class="company-name">Default blocklist</h3>
										<h5 class="price">'.$num_blocks_always[$device->key].' services blocked<!-- <span class="duration">Mon, Th, Fr, Sun</span>--></h5>
										<h5 class="price">'.$scheduled_string['primary'][$device->key].'</h5>

									</div>
								</div>
								<a class="th-btn style5" href="/profile/filters_edit?device_id='.$device->key.'&profile_choice=primary">Edit</a>
							</div>';
							if($device->get('cdd_cdp_ctldprofile_id_secondary')){
									echo '
								<br>
								<div class="job-post_author">
									<div class="job-wrapp">
										<div class="job-author">
											<i class="fa-regular '.$secondary_icon.'"></i>
										</div>
										<div class="author-info">
											<h3 class="company-name">Scheduled blocklist</h3>
											
											<h5 class="price">'.$num_blocks_scheduled[$device->key].' services blocked</h5>
											<h5 class="price">'.$scheduled_string['secondary'][$device->key].'</h5>

										</div>
									</div>
									<a class="th-btn style5" href="/profile/filters_edit?device_id='.$device->key.'&profile_choice=secondary">Edit</a>
								</div>';
							}
							else{
									echo '
								<br>
								<div class="job-post_author">
									<div class="job-wrapp">
										<div class="job-author">
											<i class="fa-regular fa-shield-slash"></i>
										</div>
										<div class="author-info">
											<h3 class="company-name">Scheduled blocklist</h3>
											
											<h5 class="price">None.</h5>

										</div>
									</div>
									<a class="th-btn style5" href="/profile/filters_edit?device_id='.$device->key.'&profile_choice=secondary">Create</a>
								</div>';								
								
							}
							echo '
						</div>
					</div>';

		}
		else{
			echo '
						<div class="col-lg-6 col-xxl-4">
						<div class="job-post style2">
							<div class="job-content">
								<div class="job-post_date">
									<a href="/profile/ctld_activation?device_id='.$device->key.'"><span class="date"><i class="fa-regular fa-exclamation"></i>Needs Activation</span></a>
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
								<a class="th-btn style5" href="/profile/ctld_activation?device_id='.$device->key.'">Activate</a>
							</div>
							
						</div>
					</div>';
		}
	}

	if($account->can_add_device() && $account->is_active()){
		?>
					<div class="col-lg-6 col-xxl-4">
						<div class="job-post style2">
							<div class="job-content">
								<div class="job-post_date">
									<h3 class="box-title">Add a Device</h3>
									<a class="th-btn style5" href="/profile/ctlddevice_edit">Add</a>
								</div>
								<!--<span class="location"><i class="fa-regular fa-location-dot me-2"></i>United States</span>
								<span class="location"><i class="fa-light fa-briefcase me-2"></i>Full Time</span>-->
							</div>

						</div>
					</div>
	<?php
	}
	else if($account->is_active()){
		?>
					<div class="col-lg-6 col-xxl-4">
						<div class="job-post style2">
							<div class="job-content">
								<div class="job-post_date">
									<h3 class="box-title">Upgrade for more devices</h3>
									<a class="th-btn style5" href="/profile/subscription_edit">Upgrade</a>
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
	$page->public_footer($foptions=array('track'=>TRUE, 'show_survey'=>TRUE));
?>
