<?php
// PathHelper is always available - never require it
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('events_logic.php', 'logic'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

$page_vars = process_logic(events_logic($_GET, $_POST));
$page = new PublicPage();
$page->public_header(array(
	'is_valid_page' => $is_valid_page,
	'title' => $page_vars['events_label']
));
?>

<!-- Start Events Area -->
<section class="latest-article-area pb-70 pt-100">
	<div class="container">
		<div class="section-title">
			<h2><?php echo htmlspecialchars($page_vars['events_label']); ?></h2>
			<p>Browse our upcoming events and register today</p>
		</div>

		<!-- Category Filter Tabs -->
		<div class="text-center mb-4">
			<?php
			foreach($page_vars['tab_menus'] as $id => $name){
				$active_class = ($id == $_REQUEST['type'] || (!isset($_REQUEST['type']) && $id == 'future')) ? 'btn-primary' : 'btn-outline-secondary';
				echo '<a href="/events?type='.$id.'" class="btn '.$active_class.' btn-sm m-1">'.htmlspecialchars($name).'</a>';
			}
			?>
		</div>

		<!-- Subscribe to Calendar -->
		<div class="text-center mb-5">
			<a href="/events/calendar.ics" class="text-muted small">
				<i class="bx bx-calendar-plus"></i> Subscribe to Calendar
			</a>
		</div>

		<!-- Mobile Dropdown for Categories -->
		<div class="d-block d-sm-none mb-4">
			<select id="event_category_select" class="form-select" onchange="window.location.href=this.value;">
				<?php
				foreach($page_vars['tab_menus'] as $id => $name){
					$selected = ($id == $_REQUEST['type'] || (!isset($_REQUEST['type']) && $id == 'future')) ? 'selected' : '';
					echo '<option value="/events?type='.$id.'" '.$selected.'>'.htmlspecialchars($name).'</option>';
				}
				?>
			</select>
		</div>

		<?php if(empty($page_vars['events'])){ ?>
			<div class="text-center p-5">
				<i class="bx bx-calendar-x" style="font-size: 64px; color: #ddd;"></i>
				<h3 class="mt-3">No Events Found</h3>
				<p class="text-muted">There are no events in this category right now.</p>
			</div>
		<?php } else { ?>
			<div class="row">
				<?php
				$event_count = 0;
				foreach ($page_vars['events'] as $event){
					if ($event_count >= 20) break;
					$is_virtual = (is_object($event) && isset($event->is_virtual) && $event->is_virtual);
					$is_cancelled = (!$is_virtual && $event instanceof Event && $event->get('evt_status') == Event::STATUS_CANCELED);

					// Unified field accessor
					$evt_name = $is_virtual ? $event->evt_name : $event->get('evt_name');
					$evt_start_time = $is_virtual ? $event->evt_start_time : $event->get('evt_start_time');
					$evt_link = $is_virtual ? $event->evt_link : $event->get('evt_link');
					$evt_leader_id = $is_virtual ? $event->evt_usr_user_id_leader : $event->get('evt_usr_user_id_leader');
					$evt_short = $is_virtual ? $event->evt_short_description : $event->get('evt_short_description');
					$evt_tz = $is_virtual ? ($event->evt_timezone ?: 'America/New_York') : ($event->get('evt_timezone') ?: 'America/New_York');

					// Build URL
					if ($is_virtual) {
						$event_url = '/event/' . $evt_link . '/' . $event->instance_date;
						$pic = null;
						if ($event->evt_fil_file_id) {
							$pic_file = new File($event->evt_fil_file_id, TRUE);
							$pic = $pic_file->get_url('profile_card', 'full');
						} elseif ($event->evt_picture_link) {
							$pic = $event->evt_picture_link;
						}
					} else {
						$event_url = $event->get_url();
						$pic = $event->get_picture_link();
					}

					// Get date string
					$date_str = '';
					if($evt_start_time){
						$date_str = LibraryFunctions::convert_time($evt_start_time, 'UTC', $evt_tz, 'M j, Y');
					}
					else if(!$is_virtual && ($next_session = $event->get_next_session())){
						$date_str = $next_session->get_start_time($tz, 'M j, Y');
					}

					// Get time string
					$time_str = '';
					if($evt_start_time){
						$time_str = LibraryFunctions::convert_time($evt_start_time, 'UTC', $evt_tz, 'g:i A');
					}

					// Get instructor
					$instructor_str = '';
					if($evt_leader_id){
						$leader = new User($evt_leader_id, TRUE);
						$instructor_str = $leader->display_name();
					}
					?>

					<div class="col-lg-4 col-md-6 col-12 mb-4">
						<div class="single-featured event-card">
							<a href="<?php echo $event_url; ?>" class="blog-img">
								<?php if($pic){ ?>
									<img src="<?php echo $pic; ?>" alt="<?php echo htmlspecialchars($evt_name); ?>">
								<?php } else { ?>
									<img src="/theme/phillyzouk/assets/images/home-three/blog-item/1.jpg" alt="<?php echo htmlspecialchars($evt_name); ?>">
								<?php } ?>
							</a>
							<div class="featured-content">
								<?php if($is_cancelled){ ?>
								<div class="mb-2"><span class="badge bg-danger">Cancelled</span></div>
								<?php } ?>
								<ul>
									<?php if($date_str){ ?>
									<li>
										<i class="bx bx-calendar"></i>
										<?php echo $date_str; ?>
									</li>
									<?php } ?>
									<?php if($time_str){ ?>
									<li>
										<i class="bx bx-time"></i>
										<?php echo $time_str; ?>
									</li>
									<?php } ?>
								</ul>
								<a href="<?php echo $event_url; ?>">
									<h3><?php echo htmlspecialchars($evt_name); ?></h3>
								</a>
								<?php if($instructor_str){ ?>
									<p class="mb-1"><i class="bx bx-user" style="color: #d80650;"></i> <?php echo htmlspecialchars($instructor_str); ?></p>
								<?php } ?>
								<a href="<?php echo $event_url; ?>" class="read-more">View Event</a>
							</div>
						</div>
					</div>

				<?php
					$event_count++;
				} ?>
			</div>
		<?php } ?>
	</div>
</section>
<!-- End Events Area -->

<?php
$page->public_footer();
?>
