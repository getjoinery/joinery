<?php
	// PathHelper is always available - never require it
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	require_once(PathHelper::getThemeFilePath('event_logic.php', 'logic'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	
	$page_vars = event_logic($_GET, $_POST, $event);
	$event = $page_vars['event'];
	$settings = Globalvars::get_instance();
	
	$page = new PublicPage();
	$page_options = array(
		'is_valid_page' => $is_valid_page,
		'title' => $event->get('evt_name')
	);
	if($event->get('evt_short_description')){
		$page_options['meta_description'] = $event->get('evt_short_description');
	}
	if($event->get_picture_link('large')){
		$page_options['preview_image_url'] = $event->get_picture_link('large');
	}
	$page->public_header($page_options);
?>

	<!-- Page Title
	============================================= -->
	<section class="page-title bg-transparent">
		<div class="container">
			<div class="page-title-row">

				<div class="page-title-content">
					<h1>Event Details</h1>
				</div>

				<nav aria-label="breadcrumb">
					<ol class="breadcrumb">
						<li class="breadcrumb-item"><a href="/">Home</a></li>
						<li class="breadcrumb-item"><a href="/events">Events</a></li>
						<li class="breadcrumb-item active" aria-current="page">Event Single</li>
					</ol>
				</nav>

			</div>
		</div>
	</section><!-- .page-title end -->

	<!-- Content
	============================================= -->
	<section id="content">
		<div class="content-wrap">
			<div class="container">

				<div class="row gx-5 col-mb-80">
					<!-- Left column - Main Content -->
					<main class="postcontent col-lg-8">
						<div class="single-event">

							<!-- Event Header -->
							<div class="bg-white rounded-4 shadow-sm p-4 mb-4">
								<h2 class="mb-3"><?php echo htmlspecialchars($event->get('evt_name')); ?></h2>
								
								<?php 
								if($time_string = $event->get_time_string()){
									echo '<p class="fs-5 text-muted mb-2"><i class="bi-calendar4-event me-2"></i>'.$time_string.'</p>';
								}
								
								if($event->get('evt_timezone') != $page_vars['session']->get_timezone()){
									echo '<p class="text-muted mb-2"><i class="bi-clock me-2"></i>'.$event->get_time_string($page_vars['session']->get_timezone()).'</p>';
								}
								
								if($event->get('evt_location')){
									echo '<p class="text-muted mb-2"><i class="bi-geo-alt me-2"></i>'.$event->get('evt_location').'</p>';
								}
								
								if($event->get('evt_usr_user_id_leader')){
									$leader = new User($event->get('evt_usr_user_id_leader'), TRUE);
									echo '<p class="text-muted"><i class="bi-person me-2"></i>Led by: '.$leader->display_name().'</p>';
								}
								?>
							</div>

							<!-- Event Image -->
							<?php if($picture_link = $event->get_picture_link('medium')){ ?>
								<div class="mb-5">
									<img src="<?php echo $picture_link; ?>" alt="<?php echo htmlspecialchars($event->get('evt_name')); ?>" class="w-100 rounded-4 shadow-sm">
								</div>
							<?php } ?>

							<!-- Event Description -->
							<div class="bg-white rounded-4 shadow-sm p-4 mb-4">
								<h3 class="mb-3">Description</h3>
								<div class="entry-content">
									<?php echo $event->get('evt_description'); ?>
								</div>
							</div>

							<!-- Location Details -->
							<?php if($page_vars['location_object']){ ?>
							<div class="bg-white rounded-4 shadow-sm p-4">
								<h3 class="mb-3">Location: <?php echo $page_vars['location_object']->get('loc_name'); ?></h3>
								
								<?php if($page_vars['location_object']->get('loc_address')){ ?>
									<p class="mb-2"><i class="bi-geo-alt me-2"></i><?php echo $page_vars['location_object']->get('loc_address'); ?></p>
								<?php } ?>
								
								<?php if($page_vars['location_object']->get('loc_website')){ ?>
									<p class="mb-3"><i class="bi-globe me-2"></i><a href="<?php echo $page_vars['location_object']->get('loc_website'); ?>" target="_blank"><?php echo $page_vars['location_object']->get('loc_website'); ?></a></p>
								<?php } ?>

								<?php if($page_vars['location_picture']){ ?>
									<div class="mb-3">
										<img src="<?php echo $page_vars['location_picture']; ?>" class="w-100 rounded-3 shadow-sm" alt="<?php echo htmlspecialchars($page_vars['location_object']->get('loc_name')); ?>">
									</div>
								<?php } ?>
								
								<?php if($page_vars['location_object']->get('loc_description')){ ?>
									<div class="mb-3">
										<?php echo $page_vars['location_object']->get('loc_description'); ?>
									</div>
								<?php } ?>
							</div>
							<?php } ?>

						</div>
					</main>

					<!-- Right column - Sidebar -->
					<aside class="sidebar col-lg-4">
						
						<!-- Registration Widget -->
						<div class="widget bg-white rounded-4 shadow-sm p-4 mb-4">
							<h4 class="mb-3">Registration</h4>
							
							<?php
							if($page_vars['registration_message']){
								echo '<p class="mb-3">'.$page_vars['registration_message'].'</p>';
							}

							foreach($page_vars['register_urls'] as $register_url){
								$formwriter = $page->getFormWriter('form1');
								echo '<div class="d-grid mb-2">';
								echo '<a href="'.$register_url['link'].'" class="button button-3d button-rounded button-dirtygreen">'.$register_url['label'].'</a>';
								echo '</div>';
							}
							
							if($page_vars['if_registered_message']){
								echo '<p class="text-muted small mt-3 mb-0">'.$page_vars['if_registered_message'].'</p>';
							}
							?>
						</div>

						<!-- Sessions Widget -->
						<?php
						if($page_vars['show_sessions_block'] || $page_vars['numsessions'] > 0 || $page_vars['future_numsessions'] > 0 || $page_vars['past_numsessions'] > 0){
						?>
						<div class="widget bg-white rounded-4 shadow-sm p-4">
							<h4 class="mb-3">Sessions</h4>
							
							<div class="accordion accordion-bg" data-collapsible="true">
								<?php
								// Display sessions based on display type
								if($event->get('evt_session_display_type') == Event::DISPLAY_SEPARATE && $page_vars['numsessions'] > 0){
									foreach($page_vars['event_sessions'] as $event_session){
										$session_title = $event_session->get('evs_title');
										if($event_session->get('evs_session_number')){
											$session_title = 'Session ' . $event_session->get('evs_session_number') . ' - ' . $session_title;
										}
										?>
										<div class="accordion-header">
											<div class="accordion-icon">
												<i class="accordion-closed bi-plus-circle"></i>
												<i class="accordion-open bi-dash-circle"></i>
											</div>
											<div class="accordion-title">
												<?php echo htmlspecialchars($session_title); ?>
											</div>
										</div>
										<div class="accordion-content">
											<?php echo preg_replace('#<a.*?>(.*?)</a>#i', '\1', $event_session->get('evs_content')); ?>
										</div>
										<?php
									}
								} else {
									// Future sessions
									if($page_vars['future_numsessions'] > 0){
										foreach($page_vars['future_event_sessions'] as $event_session){
											$time_string = '';
											if($ts = $event_session->get_time_string($tz)){
												$time_string = ' - ' . $ts;
											}
											?>
											<div class="accordion-header">
												<div class="accordion-icon">
													<i class="accordion-closed bi-plus-circle"></i>
													<i class="accordion-open bi-dash-circle"></i>
												</div>
												<div class="accordion-title">
													<?php echo htmlspecialchars($event_session->get('evs_title') . $time_string); ?>
												</div>
											</div>
											<div class="accordion-content">
												<?php echo preg_replace('#<a.*?>(.*?)</a>#i', '\1', $event_session->get('evs_content')); ?>
											</div>
											<?php
										}
									}
									
									// Past sessions
									if($page_vars['past_numsessions'] > 0){
										?>
										<h5 class="mt-4 mb-3">Past Sessions</h5>
										<?php
										foreach($page_vars['past_event_sessions'] as $event_session){
											$time_string = '';
											if($ts = $event_session->get_time_string($tz)){
												$time_string = ' - ' . $ts;
											}
											?>
											<div class="accordion-header">
												<div class="accordion-icon">
													<i class="accordion-closed bi-plus-circle"></i>
													<i class="accordion-open bi-dash-circle"></i>
												</div>
												<div class="accordion-title">
													<?php echo htmlspecialchars($event_session->get('evs_title') . $time_string); ?>
												</div>
											</div>
											<div class="accordion-content">
												<?php echo preg_replace('#<a.*?>(.*?)</a>#i', '\1', $event_session->get('evs_content')); ?>
												<p class="mt-3 mb-0"><a href="/profile/event_sessions?evt_event_id=<?php echo $event->key; ?>" class="text-primary">View videos and materials</a></p>
											</div>
											<?php
										}
									}
								}
								?>
							</div>
						</div>
						<?php } ?>

					</aside>
				</div>
			</div>
		</div>
	</section><!-- #content end -->

<?php
	$page->public_footer($foptions=array('track'=>TRUE));
?>