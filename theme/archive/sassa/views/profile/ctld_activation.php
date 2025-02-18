<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPageSassa.php', '/includes'));
	require_once(LibraryFunctions::get_logic_file_path('ctld_activation_logic.php'));

	$page_vars = ctld_activation_logic($_GET, $_POST);
	$account = $page_vars['account'];
	$device = $page_vars['device'];
	$user = $page_vars['user'];



	$page = new PublicPageSassa();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => 'My Profile', 
		'breadcrumbs' => array (
			'My Profile' => '',
			),
	);
	$page->public_header($hoptions,NULL);

	echo PublicPageSassa::BeginPage('Activation', $hoptions);
	

	$status = 'Needs Activation';
	if($device->get('cdd_is_active')){
		$status = 'Active';
	}

	echo '
	                <div class="col-lg-6 col-xxl-4">
                    <div class="job-post style2">
                        <div class="job-content">
                            <div class="job-post_date">
                                <span class="date"><i class="fa-regular fa-check"></i>'.$status.'</span>
                                <!--<div class="icon"><a href="/profile/ctlddevice_edit?device_id='.$device->key.'"><i class="fa-regular fa-edit"></i></a></div>-->
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
                                    <h3 class="company-name">Activation Code</h3>
                                    <h5 class="price">'.$device->get('cdd_controld_resolver').'<!-- <span class="duration">Mon, Th, Fr, Sun</span>--></h5>

                                </div>
                            </div>
                            <a class="th-btn style5" href="/profile/ctld_activation?device_id='.$device->key.'">Refresh</a>
                        </div>
						
                    </div>
                </div>';


		

	echo PublicPageSassa::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE, 'show_survey'=>TRUE));
?>
