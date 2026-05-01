<?php
	
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('profile_logic.php', 'logic', 'system', null, 'dns_filtering'));

	$page_vars = process_logic(profile_logic($_GET, $_POST));
	$tier = $page_vars['tier'];
	$active_subscription =  $page_vars['active_subscription'];
	
	$page = new PublicPage();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => 'My Profile', 
		'breadcrumbs' => array (
			'My Profile' => '',
			),
	);
	$page->public_header($hoptions,NULL);

	echo PublicPage::BeginPage('My Profile', $hoptions);

	foreach($page_vars['display_messages'] AS $display_message) {
		if($display_message->identifier == 'profilebox') {	
			echo PublicPage::alert($display_message->message_title, $display_message->message, $display_message->get_message_class());
		}
	}	
	echo PublicPage::tab_menu($page_vars['tab_menus'], 'My Profile');
	?>

<!--==============================
Team Area  
==============================-->

        <div class="container">
            <div class="team-details">
                <div class="row">

                    <div class="col-xl-7 ps-3 ps-xl-5 align-self-center">
                        <div class="team-about">
                            <div class="team-wrapp">
                                <div>
                                    <h3 class="team-about_title"><?php echo htmlspecialchars($page_vars['user']->display_name()); ?></h3>
                                    <p class="team-about_desig"><?php echo htmlspecialchars($page_vars['user']->get('usr_email')); ?></p>
                                    <!--<p class="team-about_text">Sem consequat mauris conubia inceptos nostra rutrum morbi
                                        sagittis
                                        pulvinar, commodo curabitur maecenas fermentum magna tempus nisi ullamcorper, ante
                                        auctor
                                        magnis pretium eu lectus euismod platea.</p>-->
                                </div>
            
                            </div>
                            <div class="about-info-wrap">
                                <div class="about-info">
                                    <div class="about-info_icon"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div>
                                    <div class="about-info_content">
                                        <p class="about-info_subtitle">Plan</p>
                                        <h6 class="about-info_title"><?php
										if($tier){
											echo htmlspecialchars($tier->get('sbt_display_name'));
										}
										else{
											echo '<a href="/pricing">Choose a plan</a>';
										}
										?></h6>
                                    </div>
                                </div>
                                <div class="about-info">
                                    <div class="about-info_icon"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg></div>
                                    <div class="about-info_content">
                                        <p class="about-info_subtitle">Billing</p>
                                        <h6 class="about-info_title">
										<?php 
										if($active_subscription){
											echo $active_subscription->readable_subscription_status();
										}
										else{
											echo 'No subscription';
										}
										?>
										</h6>
                                    </div>
                                </div>
                                <div class="about-info">
                                    <div class="about-info_icon"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 13a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg></div>
                                    <div class="about-info_content">
                                        <p class="about-info_subtitle">Lists</p>
                                        <h6 class="about-info_title">        <?php 
			
			if(empty($page_vars['user_subscribed_list'])){
				echo '<a href="/profile/contact_preferences">Not subscribed</a>';
			}
			else{
				echo implode(', ', $page_vars['user_subscribed_list']);
			}
			?></h6>
                                    </div>
                                </div>
                                <div class="about-info">
                                    <div class="about-info_icon"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em"><polyline points="22,7 22,20 2,20 2,7"/><path d="M2 7l10-5 10 5"/><line x1="12" y1="2" x2="12" y2="20"/></svg></div>
                                    <div class="about-info_content">
                                        <p class="about-info_subtitle">Timezone</p>
                                        <h6 class="about-info_title"><?php echo $page_vars['user']->get('usr_timezone'); ?></h6>
                                    </div>
                                </div>
                            </div>
                            <a href="/profile/change-tier" class="th-btn">Change Tier</a>
                        </div>
                    </div>
					                    <div class="col-xl-5">
                        <div class="mb-40 mb-xl-0">
                             <?php

				foreach($page_vars['orders'] as $order) {
					?>
					<li>

							Order <?php echo $order->key. ' ($'.$order->get('ord_total_cost').')'; ?>

							<?php echo  LibraryFunctions::convert_time($order->get('ord_timestamp'), 'UTC', $page_vars['session']->get_timezone(), 'M d, Y'); ?>

					</li>
				<?php
				}
				?>

                        </div>
                    </div>
                </div>
            </div>
        </div>

		<?php

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE, 'show_survey'=>TRUE));
?>
