<?php
	// PathHelper is always available - never require it
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	require_once(PathHelper::getThemeFilePath('events_logic.php', 'logic'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

	$page_vars = events_logic($_GET, $_POST);
	// Handle LogicResult return format
if ($page_vars->redirect) {
    LibraryFunctions::redirect($page_vars->redirect);
    exit();
}
$page_vars = $page_vars->data;
	
	$page = new PublicPage();
	$page->public_header(array(
		'is_valid_page' => $is_valid_page,
		'title' => $page_vars['events_label']
	));
?>

	<!-- Page Title
	============================================= -->
	<section class="page-title bg-transparent">
		<div class="container">
			<div class="page-title-row">

				<div class="page-title-content">
					<h1><?php echo $page_vars['events_label']; ?></h1>
					<span>Browse our upcoming events and register today</span>
				</div>

				<nav aria-label="breadcrumb">
					<ol class="breadcrumb">
						<li class="breadcrumb-item"><a href="/">Home</a></li>
						<li class="breadcrumb-item active" aria-current="page">Events</li>
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

				<div class="grid-filter-wrap">

					<!-- Events Categories Navigation (styled like portfolio filter)
					============================================= -->
					<ul class="grid-filter grid-filter-links" style="position: relative;">
						<?php
						foreach($page_vars['tab_menus'] as $id => $name){
							if($id == $_REQUEST['type']){
								echo '<li class="activeFilter"><a href="/events?type='.$id.'">'.$name.'</a></li>';
							}
							else{
								echo '<li><a href="/events?type='.$id.'">'.$name.'</a></li>';
							}
						}
						?>
					</ul><!-- .grid-filter end -->

				</div>

				<!-- Mobile Dropdown for Categories -->
				<div class="d-block d-sm-none mb-4">
					<select id="event_category_select" class="form-select" onchange="window.location.href=this.value;">
						<?php
						foreach($page_vars['tab_menus'] as $id => $name){
							$selected = ($id == $_REQUEST['type']) ? 'selected' : '';
							echo '<option value="/events?type='.$id.'" '.$selected.'>'.$name.'</option>';
						}
						?>
					</select>
				</div>

				<!-- Portfolio/Event Items Grid
				============================================= -->
				<div id="portfolio" class="portfolio row grid-container gutter-30" data-layout="fitRows">

					<?php
					foreach ($page_vars['events'] as $event){
						$now = LibraryFunctions::get_current_time_obj('UTC');
						$event_time = LibraryFunctions::get_time_obj($event->get('evt_start_time'), 'UTC');
						?>
						<article class="portfolio-item col-md-4 col-sm-6 col-12">
							<div class="grid-inner">
								<div class="portfolio-image">
									<?php if($pic = $event->get_picture_link('lthumbnail')){ ?>
										<a href="<?php echo $event->get_url(); ?>">
											<img src="<?php echo $pic; ?>" alt="<?php echo htmlspecialchars($event->get('evt_name')); ?>">
										</a>
									<?php } else { ?>
										<a href="<?php echo $event->get_url(); ?>">
											<div class="d-flex align-items-center justify-content-center bg-light" style="height: 250px;">
												<i class="uil uil-calendar-alt" style="font-size: 64px; color: #DDD;"></i>
											</div>
										</a>
									<?php } ?>
									<div class="bg-overlay">
										<div class="bg-overlay-content dark" data-hover-animate="fadeIn">
											<a href="<?php echo $event->get_url(); ?>" class="overlay-trigger-icon bg-light text-dark" data-hover-animate="fadeInDownSmall" data-hover-animate-out="fadeOutUpSmall" data-hover-speed="350"><i class="uil uil-plus"></i></a>
											<a href="<?php echo $event->get_url(); ?>" class="overlay-trigger-icon bg-light text-dark" data-hover-animate="fadeInDownSmall" data-hover-animate-out="fadeOutUpSmall" data-hover-speed="350"><i class="uil uil-ellipsis-h"></i></a>
										</div>
										<div class="bg-overlay-bg dark" data-hover-animate="fadeIn"></div>
									</div>
								</div>
								<div class="portfolio-desc">
									<h3><a href="<?php echo $event->get_url(); ?>"><?php echo $event->get('evt_name'); ?></a></h3>
									<span>
										<?php
										$date_str = '';
										$instructor_str = '';
										
										// Get date
										if($event->get('evt_start_time') && $event_time > $now){				
											$date_str = $event->get_event_start_time($tz, 'M j, Y');
										}
										else if($next_session = $event->get_next_session()){
											$date_str = $next_session->get_start_time($tz, 'M j, Y'); 
										}
										
										// Get instructor
										if($event->get('evt_usr_user_id_leader')){
											$leader = new User($event->get('evt_usr_user_id_leader'), TRUE);
											$instructor_str = $leader->display_name();
										}
										else{
											$instructor_str = 'Various instructors';
										}
										
										// Display like portfolio categories
										if($date_str) {
											echo '<a href="#">'.$date_str.'</a>';
										}
										if($instructor_str) {
											if($date_str) echo ', ';
											echo '<a href="#">'.$instructor_str.'</a>';
										}
										?>
									</span>
								</div>
							</div>
						</article>
					<?php
					}	
					?>

				</div><!-- #portfolio end -->

			</div>
		</div>
	</section><!-- #content end -->

<?php
	$page->public_footer($foptions=array('track'=>TRUE));
?>