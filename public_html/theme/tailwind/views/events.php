<?php

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
	echo PublicPage::BeginPage($page_vars['events_label']);

	?>

	<script language="javascript">
	document.addEventListener('DOMContentLoaded', function() {
		const tabSelect = document.getElementById('tab_select');

		if (tabSelect) {
			tabSelect.addEventListener('change', function() {
				const selectedValue = this.value;
				<?php
				foreach($page_vars['tab_menus'] as $id => $name){
					?>
					if(selectedValue == "<?php echo htmlspecialchars($name); ?>"){
						window.location.href = "/events?type=<?php echo htmlspecialchars($id); ?>";
					}
					<?php
				}
				?>
			});
		}
	});
	</script>
<div>
  <div class="block sm:hidden">
    <label for="tabs" class="sr-only">Categories</label>
    <!-- Use an "onChange" listener to redirect the user to the selected tab URL. -->
    <select id="tab_select" name="tab_select" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
			<?php
			foreach($page_vars['tab_menus'] as $id => $name){
				if($id == ($_REQUEST['type'] ?? null)){
				  echo '<option selected>'.htmlspecialchars($name).'</option>';
				}
				else{
				  echo '<option>'.htmlspecialchars($name).'</option>';
				}
			}
			?>

    </select>
  </div>
  <div class="hidden sm:block">
    <div class="border-b border-gray-200">
      <nav class="flex -mb-px" aria-label="Tabs">
        <!-- Current: "border-indigo-500 text-indigo-600", Default: "border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300" -->
			<?php
			foreach($page_vars['tab_menus'] as $id => $name){
				if($id == ($_REQUEST['type'] ?? null)){
				  echo '<a class="border-b-2 border-indigo-500 text-indigo-600 py-4 px-6 text-sm font-medium" aria-current="page" href="/events?type='.htmlspecialchars($id).'">'.htmlspecialchars($name).'</a>';
				}
				else{
				  echo '<a class="border-b-2 border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 py-4 px-6 text-sm font-medium" href="/events?type='.htmlspecialchars($id).'">'.htmlspecialchars($name).'</a>';
				}
			}
			?>
      </nav>
    </div>
  </div>
</div>

			<div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 mt-6">
			<?php

	foreach ($page_vars['events'] as $event){
		$now = LibraryFunctions::get_current_time_obj('UTC');
		$event_time = LibraryFunctions::get_time_obj($event->get('evt_start_time'), 'UTC');
		?>
		<div class="bg-white rounded-lg shadow-md overflow-hidden flex flex-col h-full">
		  <div class="p-6 flex flex-col items-center flex-grow">
				<?php
				if($pic = $event->get_picture_link('lthumbnail')){
					echo '<img class="rounded-full mb-4" src="'.htmlspecialchars($pic).'" alt="" style="width: 128px; height: 128px; object-fit: cover;">';
				}
				?>
			  <h5 class="text-lg font-semibold text-gray-900 text-center mt-3"><?php echo htmlspecialchars($event->get('evt_name')); ?></h5>
			  <div class="mt-2 flex-grow flex flex-col justify-between w-full">
				<div class="sr-only">Led by</div>
				<p class="text-gray-500 text-sm text-center">
				<?php
				if($event->get('evt_start_time') && $event_time > $now){
					echo htmlspecialchars($event->get_event_start_time($tz, 'M'). ' ' . $event->get_event_start_time($tz, 'd'));
				}
				else if($next_session = $event->get_next_session()){
					echo htmlspecialchars($next_session->get_start_time($tz, 'M'). ' ' . $next_session->get_start_time($tz, 'd'));

				}
				echo '</p><p class="text-gray-500 text-sm text-center">';
				if($event->get('evt_usr_user_id_leader')){
					$leader = new User($event->get('evt_usr_user_id_leader'), TRUE);
					echo '<p>Led by '. htmlspecialchars($leader->display_name()).'</p>';
				}
				else{
					echo '<p>Various instructors</p>';
				}
				?>
				</p>
			  </div>
			</div>
			<div class="px-6 pb-6 pt-0">
			  <div class="flex gap-2">
				<div class="flex-1">
				  <a href="<?php echo htmlspecialchars($event->get_url()); ?>" class="flex items-center justify-center bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 font-semibold py-2 px-4 rounded transition text-sm w-full">
					<!-- Heroicon name: solid/arrow-right -->
					<svg xmlns="http://www.w3.org/2000/svg" class="mr-2" width="16" height="16" viewBox="0 0 20 20" fill="currentColor">
  <path fill-rule="evenodd" d="M10.293 5.293a1 1 0 011.414 0l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414-1.414L12.586 11H5a1 1 0 110-2h7.586l-2.293-2.293a1 1 0 010-1.414z" clip-rule="evenodd" />
</svg>
					Details
				  </a>
				</div>
				<div class="flex-1">
				  <a href="<?php echo htmlspecialchars($event->get_url()); ?>" class="flex items-center justify-center bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded transition text-sm w-full">
					<!-- Heroicon name: solid/check -->
					<svg xmlns="http://www.w3.org/2000/svg" class="mr-2" width="16" height="16" viewBox="0 0 20 20" fill="currentColor">
  <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
</svg>
					Register
				  </a>
				</div>
			  </div>
			</div>
		  </div>

	<?php
	}
	?>

			</div><!-- end container -->

		<?php

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));
?>
