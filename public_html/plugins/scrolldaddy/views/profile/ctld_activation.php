<?php

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('ctld_activation_logic.php', 'logic', 'system', null, 'scrolldaddy'));

$page_vars = process_logic(ctld_activation_logic($_GET, $_POST));
	$tier = $page_vars['tier'];
	$device = $page_vars['device'];
	$user = $page_vars['user'];
	$doh_url = $page_vars['doh_url'];
	$dot_hostname = $page_vars['dot_hostname'];
	$resolver_uid = $page_vars['resolver_uid'];

	$page = new PublicPage();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => 'My Profile', 
		'breadcrumbs' => array (
			'My Profile' => '',
			),
	);
	$page->public_header($hoptions,NULL);

	echo PublicPage::BeginPage('Activation', $hoptions);

	$status = 'Needs Activation';
	if($device->get('cdd_is_active')){

		echo '<section class="space">
        <div class="container">
            <h2 class="sec-title text-center mb-50">Set Up '.strip_tags($device->get_readable_name()).'</h2>
            <div class="row justify-content-center gy-4">';

		// DoH card
		if($doh_url){
			echo '
			<div class="col-lg-6">
				<div class="job-post style2">
					<div class="job-content">
						<h3 class="box-title">DNS over HTTPS (DoH)</h3>
						<p>Use this URL as your custom DoH resolver. Works on any network.</p>
					</div>
					<div class="job-post_author">
						<div class="job-wrapp">
							<div class="job-author"><i class="fa-regular fa-link"></i></div>
							<div class="author-info">
								<h3 class="company-name">DoH URL</h3>
								<h5 class="price" style="word-break:break-all;font-size:13px;">'.htmlspecialchars($doh_url).'</h5>
							</div>
						</div>
					</div>
				</div>
			</div>';
		}

		// iOS setup card
		echo '
		<div class="col-lg-6">
			<div class="job-post style2">
				<div class="job-content">
					<h3 class="box-title">iOS / iPadOS Setup</h3>
					<p>Install the DNS profile on your iPhone or iPad — the easiest way:</p>
				</div>
				<div class="job-post_author">
					<div class="job-wrapp">
						<div class="job-author"><i class="fa-brands fa-apple"></i></div>
						<div class="author-info">
							<ol style="padding-left:16px;font-size:14px;line-height:2;">
								<li>Open this page in <strong>Safari</strong> on your iPhone or iPad</li>
								<li>Tap <strong>Download Profile</strong> below</li>
								<li>Go to <strong>Settings</strong> and tap <strong>Profile Downloaded</strong></li>
								<li>Tap <strong>Install</strong> and follow the prompts</li>
							</ol>
						</div>
					</div>
					<a class="th-btn style5" href="/profile/ctld_mobileconfig?device_id='.$device->key.'">Download Profile</a>
				</div>
			</div>
		</div>

		<div class="col-12 text-center mt-30">
			<a href="/profile/devices" class="th-btn"><i class="fal fa-home me-2"></i>Back To Devices</a>
		</div>

        </div>
        </div>
    </section>';

	}
	else{

		echo '
	            <div class="col-lg-6 col-xxl-4">
                    <div class="job-post style2">
                        <div class="job-content">
                            <h3 class="box-title">
							';
							if($command){
								echo 'Run the following command as Admin';
							}
							else{
								echo 'Step 1:  Download the ControlD App';
							}
							echo '
							</h3>

                        </div>
						<div class="job-post_author">
                            <div class="job-wrapp">
                                <div class="job-author">
                                    <i class="fa-regular fa-down"></i>
                                </div>
                                <div class="author-info">';
									if($link){
										echo '<h3 class="company-name">'.$linkname.'</h3>
											<h5 class="price"><a href="'.$link.'" target="_blank">'.$linkname.' </a></h5>';
									}
									else if($command){
										echo '<h3 class="company-name">'.$linkname.'</h3>
											<input type="text" id="name" name="user_name" value="'.$command.'">';	
									}

                                echo '
								</div>
                            </div>';
							if($link){
								echo '<a class="th-btn style5" href="'.$link.'" target="_blank">Install</a>';
							}
                        echo '</div>';
						
						if($link2){
						echo '<br>
						<div class="job-post_author">
                            <div class="job-wrapp">
                                <div class="job-author">
                                    <i class="fa-regular fa-link"></i>
                                </div>
                                <div class="author-info">';
							
									echo '<h3 class="company-name">'.$linkname2.'</h3>
                                    <h5 class="price"><a href="'.$link2.'" target="_blank">'.$linkname2.'</a></h5>';

                                echo '
								</div>
                            </div>
                            <a class="th-btn style5" href="'.$link2.'">Install</a>
                        </div>';		
						}		
				
	echo '</div></div>
	                <div class="col-lg-6 col-xxl-4">
                    <div class="job-post style2">
                        <div class="job-content">
                            <div class="job-post_date">
                                <!--<span class="date"><i class="fa-regular fa-check"></i>Needs Activation</span>-->
                                <!--<div class="icon"><a href="/profile/ctlddevice_edit?device_id='.$device->key.'"><i class="fa-regular fa-edit"></i></a></div>-->
                            </div>
                            <h3 class="box-title">Step 2:  Open the app, and type in the activation code</h3>
                            <!--<span class="location"><i class="fa-regular fa-location-dot me-2"></i>United States</span>
                            <span class="location"><i class="fa-light fa-briefcase me-2"></i>Full Time</span>-->
                        </div>
						<div class="job-post_author">
                            <div class="job-wrapp">
                                <div class="job-author">
                                    <i class="fa-regular fa-key"></i>
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
	}

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE, 'show_survey'=>TRUE));
?>
