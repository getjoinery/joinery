<?php
	
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('profile_logic.php', 'logic', 'system', null, 'dns_filtering'));

	$page_vars = process_logic(profile_logic(array_merge($_GET, $_POST, $params ?? [])));
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

	echo $page->render_messages('profilebox');	
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
                                    <p class="team-about_desig"><?php echo htmlspecialchars($page_vars['user']->login_label()); ?></p>
                                    <!--<p class="team-about_text">Sem consequat mauris conubia inceptos nostra rutrum morbi
                                        sagittis
                                        pulvinar, commodo curabitur maecenas fermentum magna tempus nisi ullamcorper, ante
                                        auctor
                                        magnis pretium eu lectus euismod platea.</p>-->
                                </div>
            
                            </div>
                            <div class="about-info-wrap">
                                <div class="about-info">
                                    <div class="about-info_icon"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="dnsf-icon"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div>
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
                                    <div class="about-info_icon"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="dnsf-icon"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg></div>
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
                                <?php foreach ($page_vars['dashboard_sections'] as $dnsf_section): ?>
                                <?php if (empty($dnsf_section->items) && $dnsf_section->empty_message === null) continue; ?>
                                <div class="about-info">
                                    <div class="about-info_icon"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="dnsf-icon"><path d="M4 4h16v16H4z"/><path d="M4 9h16"/></svg></div>
                                    <div class="about-info_content">
                                        <p class="about-info_subtitle"><?php echo htmlspecialchars($dnsf_section->title); ?></p>
                                        <h6 class="about-info_title"><?php
                                        if (empty($dnsf_section->items)) {
                                            $dnsf_empty = $dnsf_section->empty_message;
                                            echo $dnsf_section->view_all_url
                                                ? '<a href="' . htmlspecialchars($dnsf_section->view_all_url) . '">' . htmlspecialchars($dnsf_empty) . '</a>'
                                                : htmlspecialchars($dnsf_empty);
                                        } else {
                                            echo htmlspecialchars(implode(', ', array_map(function ($i) { return $i->title; }, $dnsf_section->items)));
                                        }
                                        ?></h6>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                <div class="about-info">
                                    <div class="about-info_icon"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="dnsf-icon"><polyline points="22,7 22,20 2,20 2,7"/><path d="M2 7l10-5 10 5"/><line x1="12" y1="2" x2="12" y2="20"/></svg></div>
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
