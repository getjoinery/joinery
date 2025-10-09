<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/files_class.php'));
	require_once(PathHelper::getIncludePath('data/events_class.php'));
	require_once(PathHelper::getIncludePath('data/event_sessions_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	$settings = Globalvars::get_instance();

	$file = new File($_GET['fil_file_id'], TRUE);
	$user = new User($file->get('fil_usr_user_id'), TRUE);

	if($_POST['action'] == 'remove'){
		$file->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$file->permanent_delete();

		//$returnurl = $session->get_return();
		header("Location: /admin/admin_files");
		exit();
	}
	else if($_POST['action'] == 'fileremove'){
		$event_session = new EventSession($_POST['evs_event_session_id'], TRUE);
		$event_session->remove_file($_POST['fil_file_id']);

		//$returnurl = $session->get_return();
		header("Location: /admin/admin_file?fil_file_id=".$file->key);
		exit();
	}
	else if($_POST['action'] == 'fileadd'){
		$event_session = new EventSession($_POST['evs_event_session_id'], TRUE);
		$event_session->add_file($_POST['fil_file_id']);

		//$returnurl = $session->get_return();
		header("Location: /admin/admin_file?fil_file_id=".$file->key);
		exit();
	}
	else if($_REQUEST['action'] == 'delete'){
		$file->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$file->soft_delete();

		header("Location: /admin/admin_files");
		exit();
	}
	else if($_REQUEST['action'] == 'undelete'){
		$file->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$file->undelete();

		header("Location: /admin/admin_files");
		exit();
	}

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'files-parent',
		'page_title' => 'Files',
		'readable_title' => 'Files',
		'breadcrumbs' => array(
			'Files'=>'/admin/admin_files',
			'File: ' . $file->get('fil_title') => '',
		),
		'session' => $session,
	)
	);

	$options['title'] = 'File: '.$file->get('fil_title');
	$options['altlinks'] = array();

	$options['altlinks'] += array('Edit file' => '/admin/admin_file_edit?fil_file_id='.$file->key);
	if($file->get('fil_delete_time')){
		$options['altlinks'] += array('Undelete' => '/admin/admin_file?action=undelete&fil_file_id='.$file->key);
	}
	else{
		$options['altlinks'] += array('Delete' => '/admin/admin_file?action=delete&fil_file_id='.$file->key);
	}
	if($session->get_user_id() == 1){
		$options['altlinks'] += array('Permanent Delete' => '/admin/admin_file_delete?fil_file_id='.$file->key);
	}

	$page->begin_box($options);

	$formwriter = $page->getFormWriter('form1');

	if($file->is_image()){
		echo '<div style="float:left; margin-right:30px; margin-bottom:30px;"><img src="/uploads/small/'.$file->get('fil_name').'"/></div>';
	}
	echo '<strong>Name:</strong> '.$file->get('fil_name') .'<br />';
	echo '<strong>Title:</strong> '.$file->get('fil_title') .'<br />';
	echo '<strong>File permissions:</strong> ';
	$group_or_event=false;
	if($file->get('fil_grp_group_id')){
		$group = new Group($file->get('fil_grp_group_id'), TRUE);
		echo 'ONLY logged in users in the "'.$group->get('grp_name').'" group ';
		$group_or_event=true;
	}
	if($file->get('fil_evt_event_id')){
		$event = new Event($file->get('fil_evt_event_id'), TRUE);
		echo 'ONLY logged in users registered for the "'.$event->get('evt_name').'" event ';
		$group_or_event=true;
	}
	if($group_or_event){
		if($file->get('fil_min_permission') > 0){
			echo 'with minimum permission ('.$file->get('fil_min_permission').') ';
		}
	}
	else{
		if($file->get('fil_min_permission') === NULL){
			echo 'Anyone ';
		}
		else if($file->get('fil_min_permission') === 0){
			echo 'Anyone logged in';
		}
		else{
			echo 'Minimum permission ('.$file->get('fil_min_permission').') ';
		}
	}

	echo '<br />';
	echo '<strong>Description:</strong> '.$file->get('fil_description') .'<br />';
	echo '<strong>User:</strong> ('.$user->key.') <a href="/admin/admin_user?usr_user_id='.$user->key.'">'.$user->display_name() .'</a><br />';
	echo '<strong>Uploaded:</strong> '.LibraryFunctions::convert_time($file->get('fil_create_time'), 'UTC', $session->get_timezone()) .'<br />';

	echo '<br />';
	if($file->is_image()){
		echo '<div class="padding10px">Full size:  <pre><a href="'.$file->get_url('standard','short').'">'.$file->get_url('standard','short').'</a></pre></div>';
		echo '<div class="padding10px">Large size:  <pre><a href="'.$file->get_url('large','short').'">'.$file->get_url('large','short').'</a></pre></div>';
		echo '<div class="padding10px">Medium size:  <pre><a href="'.$file->get_url('medium','short').'">'.$file->get_url('medium','short').'</a></pre></div>';
		echo '<div class="padding10px">Small size:  <pre><a href="'.$file->get_url('small','short').'">'.$file->get_url('small','short').'</a></pre></div>';
		echo '<div class="padding10px">Large thumbnail size:  <pre><a href="'.$file->get_url('lthumbnail','short').'">'.$file->get_url('lthumbnail','short').'</a></pre></div>';
		echo '<div class="padding10px">Thumbnail size:  <pre><a href="'.$file->get_url('thumbnail','short').'">'.$file->get_url('thumbnail','short').'</a></pre></div>';
		//echo '<div class="padding10px"><img src="/uploads/medium/'.$file->get('fil_name').'"/></div>';
	}
	else{
		echo '<strong>Direct link:</strong> <a href="/uploads/'.$file->get('fil_name').'"/>/uploads/'.$file->get('fil_name').'</a>';
	}

	/*
	$event_sessions = $file->get_event_sessions();

	$headers = array("Event Session",  "Action");
	$altlinks = array();
	//$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
	$table_options = array(
		//'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
		'altlinks' => $altlinks,
		'title' => 'Add to event session',
		//'search_on' => TRUE
	);
	$page->tableheader($headers, $table_options);

	foreach($event_sessions as $event_session){
		$event = new Event($event_session->get('evs_evt_event_id'), TRUE);

		$rowvalues = array();
		array_push($rowvalues, '<a href="/admin/admin_event_sessions?evt_event_id='.$event->key.'">'.$event->get('evt_name'). ' - '.$event_session->get('evs_title').'</a>');

		$delform = '<form id="form2" class="form2" name="form2" method="POST" action="/admin/admin_file?fil_file_id='.$file->key.'">
		<input type="hidden" class="hidden" name="action" id="action" value="fileremove" />
		<input type="hidden" class="hidden" name="fil_file_id" value="'.$file->key.'" />
		<input type="hidden" class="hidden" name="evs_event_session_id" value="'.$event_session->key.'" />
		<button type="submit">Delete</button>
		</form>';

		array_push($rowvalues, $delform);

		$page->disprow($rowvalues);

	}
	echo '<tr><td colspan="2">';
	$formwriter = $page->getFormWriter('form2');
	echo $formwriter->begin_form('form2', 'POST', '/admin/admin_file?fil_file_id='. $file->key);

	$sessions = new MultiEventSessions(
		array('deleted'=>true),
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$sessions->load();

	$optionvals = $sessions->get_sessions_dropdown_array();
	echo $formwriter->hiddeninput('action', 'fileadd');
	echo $formwriter->hiddeninput('fil_file_id', $file->key);
	//echo $formwriter->hiddeninput('evs_event_session_id', $event_session->key);
	//echo $formwriter->dropinput("Add to session", "evs_event_session_id", "ctrlHolder", $optionvals, NULL, '', TRUE);

	echo $formwriter->dropinput("Add to session", "evs_event_session_id", "ctrlHolder", $optionvals, NULL, '', TRUE, FALSE, '/ajax/session_search_ajax');

	echo $formwriter->new_form_button('Submit');

	echo '</td></tr>';
	$page->endtable();
	*/

	$page->end_box();

	$page->admin_footer();
?>

