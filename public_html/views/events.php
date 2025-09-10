<?php
	require_once(__DIR__ . '/../includes/PathHelper.php');
	
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	ThemeHelper::includeThemeFile('logic/events_logic.php');
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

	$page_vars = events_logic($_GET, $_POST);
	
	$page = new PublicPage();
	$page->public_header(array(
		'is_valid_page' => $is_valid_page,
		'title' => $page_vars['events_label']
	));
	echo PublicPage::BeginPage($page_vars['events_label']);


	
	?>

	
	<script language="javascript">
	 $(document).ready(function() {	
		$('#tab_select').change(function() { 
			<?php
			foreach($page_vars['tab_menus'] as $id => $name){
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
  <div class="d-block d-sm-none">
    <label for="tabs" class="visually-hidden">Categories</label>
    <!-- Use an "onChange" listener to redirect the user to the selected tab URL. -->
    <select id="tab_select" name="tab_select" class="form-select">
			<?php
			foreach($page_vars['tab_menus'] as $id => $name){
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
  <div class="d-none d-sm-block">
    <div class="border-bottom">
      <nav class="nav nav-tabs" aria-label="Tabs">
        <!-- Current: "border-indigo-500 text-indigo-600", Default: "border-transparent text-muted hover:text-muted hover:border" -->
			<?php
			foreach($page_vars['tab_menus'] as $id => $name){
				if($id == $_REQUEST['type']){
				  echo '<a class="nav-link active" aria-current="page" href="/events?type='.$id.'">'.$name.'</a>';					
				}
				else{
				  echo '<a class="nav-link" href="/events?type='.$id.'">'.$name.'</a>';						
				}
			}
			?>
      </nav>
    </div>
  </div>
</div>	
	
	
			

			<div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-lg-4 g-4">
			<?php



	foreach ($page_vars['events'] as $event){
		$now = LibraryFunctions::get_current_time_obj('UTC');
		$event_time = LibraryFunctions::get_time_obj($event->get('evt_start_time'), 'UTC');
		?>
		<div class="col">
		  <div class="card h-100 text-center shadow">
			<div class="card-body d-flex flex-column p-4">
				<?php
				if($pic = $event->get_picture_link('lthumbnail')){
					echo '<img class="rounded-circle mx-auto d-block mb-3" src="'.$pic.'" alt="" style="width: 128px; height: 128px; object-fit: cover;">';
				}
				?>
			  <!--<img class="w-32 h-32 flex-shrink-0 mx-auto rounded-full" src="https://images.unsplash.com/photo-1494790108377-be9c29b29330?ixlib=rb-1.2.1&ixid=eyJhcHBfaWQiOjEyMDd9&auto=format&fit=facearea&facepad=4&w=256&h=256&q=60" alt="">-->
			  <h5 class="card-title mt-3"><?php echo $event->get('evt_name'); ?></h5>
			  <div class="mt-2 flex-grow-1 d-flex flex-column justify-content-between">
				<div class="visually-hidden">Led by</div>
				<p class="text-muted small">				
				<?php
				if($event->get('evt_start_time') && $event_time > $now){				
					echo $event->get_event_start_time($tz, 'M'). ' ' . $event->get_event_start_time($tz, 'd'); 				
				}
				else if($next_session = $event->get_next_session()){
					echo $next_session->get_start_time($tz, 'M'). ' ' . $next_session->get_start_time($tz, 'd'); 
				
				}				
				echo '</p><p class="text-muted small">';
				if($event->get('evt_usr_user_id_leader')){
					$leader = new User($event->get('evt_usr_user_id_leader'), TRUE);
					echo '<p>Led by '. $leader->display_name().'</p>';
				}
				else{
					echo '<p>Various instructors</p>';
				}
				?>
				</p>
				<!--
				<dt class="sr-only">Role</dt>
				<dd class="mt-3">
				  <span class="px-2 py-1 text-green-800 text-xs font-medium bg-green-100 rounded-full">Admin</span>
				</dd>-->
			  </div>
			</div>
			<div class="card-footer bg-white border-0 pt-0">
			  <div class="row g-0">
				<div class="col">
				  <a href="<?php echo $event->get_url(); ?>" class="btn btn-outline-secondary btn-sm w-100">
					<!-- Heroicon name: solid/mail -->
					<svg xmlns="http://www.w3.org/2000/svg" class="me-2" width="16" height="16" viewBox="0 0 20 20" fill="currentColor">
  <path fill-rule="evenodd" d="M10.293 5.293a1 1 0 011.414 0l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414-1.414L12.586 11H5a1 1 0 110-2h7.586l-2.293-2.293a1 1 0 010-1.414z" clip-rule="evenodd" />
</svg>
					Details
				  </a>
				</div>
				<div class="col ps-2">
				  <a href="<?php echo $event->get_url(); ?>" class="btn btn-primary btn-sm w-100">
					<!-- Heroicon name: solid/phone -->
					<svg xmlns="http://www.w3.org/2000/svg" class="me-2" width="16" height="16" viewBox="0 0 20 20" fill="currentColor">
  <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
</svg>
					Register
				  </a>
				</div>
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

