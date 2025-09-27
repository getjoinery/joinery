<?php
	
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('profile_logic.php', 'logic', 'system', null, 'controld'));

	$page_vars = profile_logic($_GET, $_POST);
	$account = $page_vars['account'];
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
		/*
	if($page_vars['settings']->get_setting('events_active')){
		//DISPLAY REGISTER FINISH LINKS
		foreach($event_registrants as $event_registrant){
			if(!$event_registrant->get('evr_extra_info_completed')){
				$event = new Event($event_registrant->get('evr_evt_event_id'), TRUE);
				if($event->get('evt_collect_extra_info') && $event->get('evt_status') == 1){
					$act_code = Activation::CheckForActiveCode($user->key, Activation::EMAIL_VERIFY);
					$line = 'Your registration for <strong>'.$event->get('evt_name').'</strong> needs some additional information. <a href="/profile/event_register_finish?act_code='.$act_code->act_code.'&userid='.$user->key.'&eventregistrantid='.$event_registrant->key.'">click here to add the information</a>';
					echo '<div class="status_warning">'.$line.'</div><br /><br />';
				}
			}
		}			
	} 
	*/
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
                                    <div class="about-info_icon"><i class="fa-solid fa-user"></i></div>
                                    <div class="about-info_content">
                                        <p class="about-info_subtitle">Plan</p>
                                        <h6 class="about-info_title"><?php 
										if($account){
											echo $account->readable_plan_name(); 
										}
										else{
											echo '<a href="/pricing">Choose a plan</a>';
										}
										?></h6>
                                    </div>
                                </div>
                                <div class="about-info">
                                    <div class="about-info_icon"><i class="fas fa-envelope"></i></div>
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
                                    <div class="about-info_icon"><i class="fas fa-phone"></i></div>
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
                                    <div class="about-info_icon"><i class="fas fa-fax"></i></div>
                                    <div class="about-info_content">
                                        <p class="about-info_subtitle">Timezone</p>
                                        <h6 class="about-info_title"><?php echo $page_vars['user']->get('usr_timezone'); ?></h6>
                                    </div>
                                </div>
                            </div>
                            <a href="/profile/subscription_edit?order_item_id=<?php echo $active_subscription->key; ?>" class="th-btn">Change Plan</a>
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
