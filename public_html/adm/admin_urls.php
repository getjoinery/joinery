<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/urls_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'url_id', 0, '');	
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
	

	
	$search_criteria = array();
	//$search_criteria['source'] = $url_source;

	//ONLY SHOW DELETED TO SUPER ADMINS
	if($_SESSION['permission'] < 10){
		$search_criteria['deleted'] = false;
	}	

	$urls = new MultiUrl(
		$search_criteria,
		array($sort=>$sdirection),
		$numperpage,
		$offset);	
	$numrecords = $urls->count_all();	
	$urls->load();
	
	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'urls',
		'page_title' => 'Urls',
		'readable_title' => 'Urls',
		'breadcrumbs' => array(
			'Urls'=>'',
		),
		'session' => $session,
	)
	);


	$headers = array("Url",  "Redirect", "Type");
	$altlinks = array('Add Url'=>'/admin/admin_url_edit');

	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
	$table_options = array(
		//'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
		'altlinks' => $altlinks,
		'title' => 'Urls',
		//'search_on' => TRUE
	);
	$page->tableheader($headers, $table_options, $pager);

	foreach ($urls as $url){
		
		$deleted = '';
		if($url->get('url_delete_time')){
			$deleted = 'DELETED';
		}
		
		$rowvalues = array();
		array_push($rowvalues, "<a href='/admin/admin_url?url_url_id=$url->key'>".$url->get('url_incoming')."</a>". $deleted);	
		if($url->get('url_redirect_url')){
			array_push($rowvalues, $url->get('url_redirect_url'));
		}
		else{
			array_push($rowvalues, $url->get('url_redirect_file'));
		}
		
		if($url->get('url_type') == 301){
			array_push($rowvalues, 'Permanent');
		}
		else if($url->get('url_type') == 302){
			array_push($rowvalues, 'Temporary');
		}


		$page->disprow($rowvalues);
	}


	$page->endtable($pager);
	$page->admin_footer();
?>


