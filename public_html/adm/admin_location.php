<?php
	require_once(__DIR__ . '/../includes/PathHelper.php');
	
	PathHelper::requireOnce('includes/Activation.php');
	PathHelper::requireOnce('includes/ErrorHandler.php');
	
	PathHelper::requireOnce('includes/AdminPage.php');
	PathHelper::requireOnce('includes/SessionControl.php');
	PathHelper::requireOnce('includes/DbConnector.php');

	PathHelper::requireOnce('data/locations_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	
	$settings = Globalvars::get_instance();

	$location = new Location($_REQUEST['loc_location_id'], TRUE);

	if($_REQUEST['action'] == 'delete'){
		$location->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));		
		$location->soft_delete();

		header("Location: /admin/admin_locations");
		exit();				
	}
	else if($_REQUEST['action'] == 'undelete'){
		$location->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));		
		$location->undelete();

		header("Location: /admin/admin_locations");
		exit();				
	}

	$session->set_return();


	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'locations',
		'breadcrumbs' => array(
			'Emails'=>'/admin/admin_emails', 
			'Locations'=>'/admin/admin_locations', 
			'Location: '.$location->get('loc_name') => '',
		),
		'session' => $session,
	)
	);	



	$options['title'] = 'Location: '.$location->get('loc_name');
	$options['altlinks'] = array();
	if(!$location->get('loc_delete_time')) {
		$options['altlinks'] += array('Edit Location' => '/admin/admin_location_edit?loc_location_id='.$location->key);
	}
	
	if(!$location->get('loc_delete_time') && $_SESSION['permission'] >= 8) {
		$options['altlinks']['Soft Delete'] = '/admin/admin_location?action=delete&loc_location_id='.$location->key;
	}
		
	$page->begin_box($options);
	
	echo '<h3>'.$location->get('loc_name').'</h3>'; 
	echo '<strong>Link:</strong> <a href="'.$location->get_url().'">'.$location->get_url('short').'</a><br />';	
	?><p><?php echo 'Address: '.$location->get('loc_address'); ?></p><?php
	?><p><?php echo 'Website: '.$location->get('loc_website'); ?></p><?php
	if($location->get('loc_is_published')){
		echo 'Status: Published'.'<br />';
	}
	else{
		echo 'Status: Unpublished'.'<br />';
	}
	
	if($location->get('loc_delete_time')){
		echo 'Status: Deleted at '.LibraryFunctions::convert_time($location->get('loc_delete_time'), 'UTC', $session->get_timezone()).'<br />';
	}
	else{
		echo 'Status: Active'.'<br />';
	}
	
	if($location->get('loc_fil_file_id')){
		$file = new File($location->get('loc_fil_file_id'), true);

		echo '<img src="'.LibraryFunctions::get_absolute_url('/uploads/small/'.$file->get('fil_name')).'">';
	}	

	
	echo '<br><br>';
	?><p><?php echo $location->get('loc_short_description'); ?></p><?php
	echo '<br><br>';
	?><p><?php echo $location->get('loc_description'); ?></p>


<?php 
	$page->end_box();

	$page->admin_footer();
?>
