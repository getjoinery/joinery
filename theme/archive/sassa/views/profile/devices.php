<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPageSassa.php', '/includes'));
	require_once(LibraryFunctions::get_logic_file_path('devices_logic.php'));

	$page_vars = devices_logic($_GET, $_POST);
	$account = $page_vars['account'];
	$devices = $page_vars['devices'];
	$num_devices =  $page_vars['num_devices'];
	$user = $page_vars['user'];
	$num_blocks_always = $page_vars['num_blocks_always'];
	$num_blocks_scheduled = $page_vars['num_blocks_scheduled'];
	$scheduled_string = $page_vars['scheduled_string'];


	$page = new PublicPageSassa();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Devices', 
		'breadcrumbs' => array (
			'Devices' => '',
			),
	);
	$page->public_header($hoptions,NULL);

	echo PublicPageSassa::BeginPage('Devices', $hoptions);


			

foreach($devices as $device){
	$status = '<a href="/profile/ctld_activation?device_id='.$device->key.'"><span class="date"><i class="fa-regular fa-exclamation"></i>Needs Activation</span></a>';
	if($device->get('cdd_is_active')){
		$status = '<span class="date"><i class="fa-regular fa-check"></i>Active</span>';
	}

	echo '
	                <div class="col-lg-6 col-xxl-4">
                    <div class="job-post style2">
                        <div class="job-content">
                            <div class="job-post_date">
                                '.$status.'
                                <div class="icon"><a href="/profile/device_edit?device_id='.$device->key.'"><i class="fa-regular fa-edit"></i></a></div>
                            </div>
                            <h3 class="box-title">'.$device->get('cdd_device_name').'</h3>
                            <!--<span class="location"><i class="fa-regular fa-location-dot me-2"></i>United States</span>
                            <span class="location"><i class="fa-light fa-briefcase me-2"></i>Full Time</span>-->
                        </div>
						<div class="job-post_author">
                            <div class="job-wrapp">
                                <div class="job-author">
                                    <i class="fa-regular fa-lock"></i>
                                </div>
                                <div class="author-info">
                                    <h3 class="company-name">Always on blocks</h3>
                                    <h5 class="price">'.$num_blocks_always[$device->key].' services blocked<!-- <span class="duration">Mon, Th, Fr, Sun</span>--></h5>

                                </div>
                            </div>
                            <a class="th-btn style5" href="/profile/filters_edit?device_id='.$device->key.'&profile_choice=primary">Edit</a>
                        </div>
						<br>
                        <div class="job-post_author">
                            <div class="job-wrapp">
                                <div class="job-author">
                                    <i class="fa-regular fa-clock"></i>
                                </div>
                                <div class="author-info">
                                    <h3 class="company-name">Scheduled blocks</h3>
									
									<h5 class="price">'.$num_blocks_scheduled[$device->key].' services blocked</h5>
                                    <h5 class="price">'.$scheduled_string[$device->key].'</h5>

                                </div>
                            </div>
                            <a class="th-btn style5" href="/profile/filters_edit?device_id='.$device->key.'&profile_choice=secondary">Edit</a>
                        </div>
                    </div>
                </div>';
}

?>


<?php
if($account && $num_devices < $account->get('cda_plan_max_devices')){
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

		

	echo PublicPageSassa::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE, 'show_survey'=>TRUE));
?>
