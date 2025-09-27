<?php

	PathHelper::requireOnce('includes/Activation.php');

	PathHelper::requireOnce('includes/AdminPage.php');

	PathHelper::requireOnce('data/files_class.php');
	PathHelper::requireOnce('data/mailing_lists_class.php');
	PathHelper::requireOnce('data/mailing_list_registrants_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	$mailing_list = new MailingList($_REQUEST['mlt_mailing_list_id'], TRUE);

	if($_REQUEST['action'] == 'delete'){
		$mailing_list->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$mailing_list->soft_delete();

		header("Location: /admin/admin_mailing_lists");
		exit();
	}
	else if($_REQUEST['action'] == 'undelete'){
		$mailing_list->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$mailing_list->undelete();

		header("Location: /admin/admin_mailing_lists");
		exit();
	}
	else if($_REQUEST['action'] == 'removeregistrant'){
		$registrant = new MailingListRegistrant($_REQUEST['mlr_mailing_list_registrant_id'], TRUE);
		$mailing_list->remove_registrant($registrant->get('mlr_usr_user_id'));
		header("Location: /admin/admin_mailing_list?mlt_mailing_list_id=".$mailing_list->key);
		exit();
	}

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'mailing_list_registrant_id', 0, '');
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
	$searchterm = LibraryFunctions::fetch_variable('searchterm', '', 0, '');

	$search_criteria = array(
		'deleted' => false,
		'mailing_list_id' => $mailing_list->key);
	$registrants = new MultiMailingListRegistrant(
		$search_criteria,
		array($sort=>$sdirection),
		$numperpage,
		$offset);
	$registrants->load();
	$numrecords = $registrants->count_all();

	$session->set_return();

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'mailing-lists',
		'breadcrumbs' => array(
			'Emails'=>'/admin/admin_emails',
			'Mailing Lists'=>'/admin/admin_mailing_lists',
			'Mailing List: '.$mailing_list->get('mlt_name') => '',
		),
		'session' => $session,
	)
	);

	$options['title'] = 'Mailing List: '.$mailing_list->get('mlt_name');
	$options['altlinks'] = array();
	if(!$mailing_list->get('mlt_delete_time')) {
		$options['altlinks'] += array('Edit Mailing List' => '/admin/admin_mailing_list_edit?mlt_mailing_list_id='.$mailing_list->key);
	}

	if($_SESSION['permission'] >= 8){
		if($mailing_list->get('mlt_delete_time')) {
			$options['altlinks']['Undelete'] = '/admin/admin_mailing_list?action=undelete&mlt_mailing_list_id='.$mailing_list->key;
		}
		else {
			$options['altlinks']['Soft Delete'] = '/admin/admin_mailing_list?action=delete&mlt_mailing_list_id='.$mailing_list->key;
		}
	}

	$page->begin_box($options);

	echo '<h3>'.$mailing_list->get('mlt_name').'</h3>';
	echo '<p>'.$mailing_list->get('mlt_description').'</p>';

	if($mailing_list->get('mlt_delete_time')){
		echo 'Status: Deleted at '.LibraryFunctions::convert_time($mailing_list->get('mlt_delete_time'), 'UTC', $session->get_timezone()).'<br />';
	}
	else{
		echo 'Status: Active'.'<br />';

		if($mailing_list->get('mlt_visibility') == MailingList::VISIBILITY_PUBLIC_UNLISTED){
			echo 'Visibility: Public, Unlisted (<a href="'. $mailing_list->get_url(). '">'. $mailing_list->get_url(). '</a>)<br />';
		}
		else if ($mailing_list->get('mlt_visibility') == MailingList::VISIBILITY_PUBLIC){
			echo 'Visibility: Public (<a href="'. $mailing_list->get_url(). '">'. $mailing_list->get_url(). '</a>)<br />';
		}
		else{
			echo 'Visibility: Hidden<br>';
		}
		echo 'Subscribed users: '. $numusers = $mailing_list->count_subscribed_users(). '<br />';

	}

	if($mailing_list->get('mlt_emt_email_template_id')){
		if($mailing_list->get('mlt_fil_file_id')){
			$file = new File($mailing_list->get('mlt_fil_file_id'), true);
			echo 'Welcome emails active.  File attached: '.$mailing_list->get('mlt_mailchimp_list_id').'<br />';
		}
		else{
			echo 'Welcome emails active.<br />';
		}
	}
	else{
		echo 'Welcome emails are inactive.<br />';
	}

	if($mailing_list->get('mlt_mailchimp_list_id')){
		echo 'Mailchimp integration active.  Mailchimp ID: '.$mailing_list->get('mlt_mailchimp_list_id').'<br />';
	}
	else{
		echo 'Mailchimp integration inactive.';
	}
	echo '<br><br>';

	$page->end_box();

	$headers = array("Users",  "Action");

	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
	$altlinks = array();
	 $box_vars =	array(
		'altlinks' => $altlinks,
		'title' => 'Users in '. $mailing_list->get('mlt_name')
	);
	$page->tableheader($headers, $box_vars, $pager);

	foreach($registrants as $registrant){
		$user = new User($registrant->get('mlr_usr_user_id'), TRUE);
		$rowvalues=array();

		array_push($rowvalues, $user->display_name());

		$delform = '<form id="form2" class="form2" name="form2" method="POST" action="/admin/admin_mailing_list?mlt_mailing_list_id='.$mailing_list->key.'">
		<input type="hidden" class="hidden" name="action" id="action" value="removeregistrant" />
		<input type="hidden" class="hidden" name="mlr_mailing_list_registrant_id" id="mlr_mailing_list_registrant_id" value="'.$registrant->key.'" />
		<button type="submit">Delete</button>
		</form>';
		array_push($rowvalues, $delform);

		$page->disprow($rowvalues);

	}

		$page->endtable($pager);

	$page->admin_footer();
?>
