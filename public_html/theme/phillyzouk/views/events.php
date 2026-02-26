<?php
// PathHelper is always available - never require it
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
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
				foreach ($page_vars['events'] as $event){
					$now = LibraryFunctions::get_current_time_obj('UTC');
					$is_virtual = (is_object($event) && isset($event->is_virtual) && $event->is_virtual);

					// Unified field accessor
					$evt_name = $is_virtual ? $event->evt_name : $event->get('evt_name');
					$evt_start_time = $is_virtual ? $event->evt_start_time : $event->get('evt_start_time');
					$evt_link = $is_virtual ? $event->evt_link : $event->get('evt_link');
					$evt_leader_id = $is_virtual ? $event->evt_usr_user_id_leader : $event->get('evt_usr_user_id_leader');

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
						$pic = $event->get_picture_link('profile_card');
					}

					$event_time = $evt_start_time ? LibraryFunctions::get_time_obj($evt_start_time, 'UTC') : null;

					// Get date string
					$date_str = '';
					if($evt_start_time && $event_time && $event_time > $now){
						if ($is_virtual) {
							$date_str = date('M j, Y', strtotime($evt_start_time));
						} else {
							$date_str = $event->get_event_start_time($tz, 'M j, Y');
						}
					}
					else if(!$is_virtual && ($next_session = $event->get_next_session())){
						$date_str = $next_session->get_start_time($tz, 'M j, Y');
					}

					// Get instructor
					$instructor_str = '';
					if($evt_leader_id){
						$leader = new User($evt_leader_id, TRUE);
						$instructor_str = $leader->display_name();
					} else {
						$instructor_str = 'Various instructors';
					}
					?>

					<div class="col-lg-4 col-md-6 col-12 mb-4">
						<div class="event-card bg-white rounded shadow-sm overflow-hidden h-100">
							<a href="<?php echo $event_url; ?>">
								<?php if($pic){ ?>
									<img src="<?php echo $pic; ?>" alt="<?php echo htmlspecialchars($evt_name); ?>" class="w-100" style="height: 200px; object-fit: cover;">
								<?php } else { ?>
									<div class="d-flex align-items-center justify-content-center bg-light" style="height: 200px;">
										<i class="bx bx-calendar-event" style="font-size: 64px; color: #ddd;"></i>
									</div>
								<?php } ?>
							</a>
							<div class="p-3">
								<h5 class="mb-2">
									<a href="<?php echo $event_url; ?>" style="color: #333; text-decoration: none;"><?php echo htmlspecialchars($evt_name); ?></a>
								</h5>
								<?php if($date_str){ ?>
									<p class="mb-1 small text-muted">
										<i class="bx bx-calendar" style="color: #d80650;"></i>
										<?php echo $date_str; ?>
									</p>
								<?php } ?>
								<?php if($instructor_str){ ?>
									<p class="mb-0 small text-muted">
										<i class="bx bx-user" style="color: #d80650;"></i>
										<?php echo htmlspecialchars($instructor_str); ?>
									</p>
								<?php } ?>
							</div>
						</div>
					</div>

				<?php } ?>
			</div>
		<?php } ?>
	</div>
</section>
<!-- End Events Area -->

<?php
$page->public_footer();
?>
