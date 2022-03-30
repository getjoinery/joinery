<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once (LibraryFunctions::get_logic_file_path('events_logic.php'));
	require_once(LibraryFunctions::get_theme_file_path('PublicPageTW.php', '/includes'));
	require_once(LibraryFunctions::get_theme_file_path('FormWriterPublicTW.php', '/includes'));

	$page = new PublicPageTW(TRUE);
	$page->public_header(array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Events'
	));
	echo PublicPageTW::BeginPage('Retreats and Events');


	
	?>

	
	<script language="javascript">
	 $(document).ready(function() {	
		$('#tab_select').change(function() { 
			<?php
			foreach($tab_menus as $id => $name){
				?>
				if($('#tab_select').val() == "<?php echo $name; ?>"){
					$(location).attr("href","/events?type=<?php echo $id; ?>");
				}
				<?php
			}
			?>
		});	
	});
	</script>	
<div>
  <div class="sm:hidden">
    <label for="tabs" class="sr-only">Categories</label>
    <!-- Use an "onChange" listener to redirect the user to the selected tab URL. -->
    <select id="tab_select" name="tab_select" class="block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md">
			<?php
			foreach($tab_menus as $id => $name){
				if($id == $_REQUEST['type']){
				  echo '<option selected>'.$name.'</option>';					
				}
				else{
				  echo '<option>'.$name.'</option>';						
				}
			}
			?>

    </select>
  </div>
  <div class="hidden sm:block">
    <div class="border-b border-gray-200">
      <nav class="-mb-px flex space-x-8" aria-label="Tabs">
        <!-- Current: "border-indigo-500 text-indigo-600", Default: "border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300" -->
			<?php
			foreach($tab_menus as $id => $name){
				if($id == $_REQUEST['type']){
				  echo '<a class="border-indigo-500 text-indigo-600 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm" aria-current="page" href="/events?type='.$id.'">'.$name.'</a>';					
				}
				else{
				  echo '<a class="border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm" href="/events?type='.$id.'">'.$name.'</a>';						
				}
			}
			?>
      </nav>
    </div>
  </div>
</div>	
	
	
			

			<ul role="list" class="grid grid-cols-1 gap-6 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
			<?php



	foreach ($events as $event){
		$now = LibraryFunctions::get_current_time_obj('UTC');
		$event_time = LibraryFunctions::get_time_obj($event->get('evt_start_time'), 'UTC');
		?>
		<li class="col-span-1 flex flex-col text-center bg-white rounded-lg shadow divide-y divide-gray-200">
			<div class="flex-1 flex flex-col p-8">
				<?php
				if($pic = $event->get_picture_link('lthumbnail')){
					echo '<img class="w-32 h-32 flex-shrink-0 mx-auto rounded-full" src="'.$pic.'" alt="">';
				}
				?>
			  <!--<img class="w-32 h-32 flex-shrink-0 mx-auto rounded-full" src="https://images.unsplash.com/photo-1494790108377-be9c29b29330?ixlib=rb-1.2.1&ixid=eyJhcHBfaWQiOjEyMDd9&auto=format&fit=facearea&facepad=4&w=256&h=256&q=60" alt="">-->
			  <h3 class="mt-6 text-gray-900 text-sm font-medium"><?php echo $event->get('evt_name'); ?></h3>
			  <dl class="mt-1 flex-grow flex flex-col justify-between">
				<dt class="sr-only">Led by</dt>
				<dd class="text-gray-500 text-sm">				
				<?php
				if($event->get('evt_start_time') && $event_time > $now){				
					echo $event->get_event_start_time($tz, 'M'). ' ' . $event->get_event_start_time($tz, 'd'); 				
				}
				else if($next_session = $event->get_next_session()){
					echo $next_session->get_start_time($tz, 'M'). ' ' . $next_session->get_start_time($tz, 'd'); 
				
				}				
				echo '</dd><dd class="text-gray-500 text-sm">';
				if($event->get('evt_usr_user_id_leader')){
					$leader = new User($event->get('evt_usr_user_id_leader'), TRUE);
					echo '<p>Led by '. $leader->display_name().'</p>';
				}
				else{
					echo '<p>Various instructors</p>';
				}
				?>
				</dd>
				<!--
				<dt class="sr-only">Role</dt>
				<dd class="mt-3">
				  <span class="px-2 py-1 text-green-800 text-xs font-medium bg-green-100 rounded-full">Admin</span>
				</dd>-->
			  </dl>
			</div>
			<div>
			  <div class="-mt-px flex divide-x divide-gray-200">
				<div class="w-0 flex-1 flex">
				  <a href="<?php echo $event->get_url(); ?>" class="relative -mr-px w-0 flex-1 inline-flex items-center justify-center py-4 text-sm text-gray-700 font-medium border border-transparent rounded-bl-lg hover:text-gray-500">
					<!-- Heroicon name: solid/mail -->
					<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
  <path fill-rule="evenodd" d="M10.293 5.293a1 1 0 011.414 0l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414-1.414L12.586 11H5a1 1 0 110-2h7.586l-2.293-2.293a1 1 0 010-1.414z" clip-rule="evenodd" />
</svg>
					<span class="ml-3">Details</span>
				  </a>
				</div>
				<div class="-ml-px w-0 flex-1 flex">
				  <a href="<?php echo $event->get_url(); ?>" class="relative w-0 flex-1 inline-flex items-center justify-center py-4 text-sm text-gray-700 font-medium border border-transparent rounded-br-lg hover:text-gray-500">
					<!-- Heroicon name: solid/phone -->
					<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
  <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
</svg>
					<span class="ml-3">Register</span>
				  </a>
				</div>
			  </div>
			</div>
		  </li>		
		
		
	<?php
	}	
	?>
	
			</ul><!-- end container -->

		<?php
  
	echo PublicPageTW::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));
?>

