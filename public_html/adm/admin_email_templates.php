<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/email_templates_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	//$session->set_return();

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'email_template_id', 0, '');
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
	$searchterm = LibraryFunctions::fetch_variable('searchterm', '', 0, '');

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'email-templates',
		'page_title' => 'Users',
		'readable_title' => 'Users',
		'breadcrumbs' => array(
			'EmailTemplates'=>'', 
		),
		'session' => $session,
	)
	);	
	
	$search_criteria = array();
	$search_criteria['template_type'] = EmailTemplateStore::TEMPLATE_TYPE_INNER;

	$email_templates = new MultiEmailTemplateStore(
		$search_criteria,
		array($sort=>$sdirection),
		$numperpage,
		$offset,
		'OR');
	$numrecords = $email_templates->count_all();
	$email_templates->load();	

		
	$headers = array('Email Template', 'Type');
	$altlinks = array('New Email Template'=>'/admin/admin_email_template_edit');
	$title = 'Email Content Templates'; 

	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));	
	$table_options = array(
		//'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
		'altlinks' => $altlinks,
		'title' => $title,
		//'search_on' => TRUE
	);
	$page->tableheader($headers, $table_options, $pager);

	foreach($email_templates as $email_template) {
		
		$rowvalues = array();
		array_push($rowvalues, '<a href="/admin/admin_email_template?emt_email_template_id='.$email_template->key.'">'.$email_template->get('emt_name').'</a>');


		if($email_template->get('emt_type') == EmailTemplateStore::TEMPLATE_TYPE_OUTER){
			array_push($rowvalues, 'Outer template');
		}
		else if($email_template->get('emt_type') == EmailTemplateStore::TEMPLATE_TYPE_INNER){
			array_push($rowvalues, 'Inner template');
		} 	
		else if($email_template->get('emt_type') == EmailTemplateStore::TEMPLATE_TYPE_FOOTER){
			array_push($rowvalues, 'Footer template');
		}
			
	
		$page->disprow($rowvalues);
	}
		
	$page->endtable($pager);		
		







	$search_criteria = array();
	$search_criteria['template_type'] = EmailTemplateStore::TEMPLATE_TYPE_OUTER;

	$email_templates = new MultiEmailTemplateStore(
		$search_criteria,
		array($sort=>$sdirection),
		$numperpage,
		$offset,
		'OR');
	$numrecords = $email_templates->count_all();
	$email_templates->load();	

		
	$headers = array('Email Template', 'Type');
	$altlinks = array('New Email Template'=>'/admin/admin_email_template_edit');
	$title = 'Email Outer Templates';

	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));	
	$table_options = array(
		//'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
		'altlinks' => $altlinks,
		'title' => $title,
		//'search_on' => TRUE
	);
	$page->tableheader($headers, $table_options, $pager);

	foreach($email_templates as $email_template) {
		
		$rowvalues = array();
		array_push($rowvalues, '<a href="/admin/admin_email_template?emt_email_template_id='.$email_template->key.'">'.$email_template->get('emt_name').'</a>');


		if($email_template->get('emt_type') == EmailTemplateStore::TEMPLATE_TYPE_OUTER){
			array_push($rowvalues, 'Outer template');
		}
		else if($email_template->get('emt_type') == EmailTemplateStore::TEMPLATE_TYPE_INNER){
			array_push($rowvalues, 'Inner template');
		} 	
		else if($email_template->get('emt_type') == EmailTemplateStore::TEMPLATE_TYPE_FOOTER){
			array_push($rowvalues, 'Footer template');
		}
			
	
		$page->disprow($rowvalues);
	}
		
	$page->endtable($pager);		





	$search_criteria = array();
	$search_criteria['template_type'] = EmailTemplateStore::TEMPLATE_TYPE_FOOTER;

	$email_templates = new MultiEmailTemplateStore(
		$search_criteria,
		array($sort=>$sdirection),
		$numperpage,
		$offset,
		'OR');
	$numrecords = $email_templates->count_all();
	$email_templates->load();	

		
	$headers = array('Email Template', 'Type');
	$altlinks = array('New Email Template'=>'/admin/admin_email_template_edit');
	$title = 'Email Footer Templates';

	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));	
	$table_options = array(
		//'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
		'altlinks' => $altlinks,
		'title' => $title,
		//'search_on' => TRUE
	);
	$page->tableheader($headers, $table_options, $pager);

	foreach($email_templates as $email_template) {
		
		$rowvalues = array();
		array_push($rowvalues, '<a href="/admin/admin_email_template?emt_email_template_id='.$email_template->key.'">'.$email_template->get('emt_name').'</a>');


		if($email_template->get('emt_type') == EmailTemplateStore::TEMPLATE_TYPE_OUTER){
			array_push($rowvalues, 'Outer template');
		}
		else if($email_template->get('emt_type') == EmailTemplateStore::TEMPLATE_TYPE_INNER){
			array_push($rowvalues, 'Inner template');
		} 	
		else if($email_template->get('emt_type') == EmailTemplateStore::TEMPLATE_TYPE_FOOTER){
			array_push($rowvalues, 'Footer template');
		}
			
	
		$page->disprow($rowvalues);
	}
		
	$page->endtable($pager);		



	$page->admin_footer();

?>
