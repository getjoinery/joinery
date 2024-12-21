<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/products_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/product_details_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	$numperpage = 60;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'product_detail_id', 0, '');	
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
	
	$search_criteria = array();

	//ONLY SHOW DELETED TO SUPER ADMINS
	if($_SESSION['permission'] < 10){
		$search_criteria['deleted'] = false;
	}
	
	$details = new MultiProductDetail(
		$search_criteria,
		array($sort=>$sdirection),
		$numperpage,
		$offset);
	$numrecords = $details->count_all();
	$details->load();
	
	$page = new AdminPage();
	$page->admin_header(	
		array(
		'menu-id'=> 'shadow-sessions',
		'page_title' => 'Shadow Sessions',
		'readable_title' => 'Shadow Sessions',
		'breadcrumbs' => array(
			'Products'=>''
		),
		'session' => $session,
	));

	echo '<h1>Shadow Sessions Orders</h1>';


	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
	$table_options = array(
		//'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
		'altlinks' => '',
		'title' => 'Shadow Sessions',
		//'search_on' => TRUE
	);
	$page->tableheader($headers, $table_options, $pager);

	
	foreach($details as $detail) {
		$rowvalues = array();
		
		if($detail->get('prd_usr_user_id')){
			$detail_user = new User($detail->get('prd_usr_user_id'), TRUE);
		}
		else{
			$detail_user = new User(NULL);
		}
		

		//array_push($rowvalues, $detail->key);

		
		array_push($rowvalues, '('.$detail_user->key . ')  <a href="/admin/admin_user?usr_user_id=' . $detail_user->key . '">' . $detail_user->display_name() . '</a>');


		//array_push($rowvalues,  LibraryFunctions::convert_time($detail->get('ord_timestamp'), "UTC", $session->get_timezone()));
		array_push($rowvalues, $detail->get('prd_num_sessions'));
		array_push($rowvalues, $detail->get('prd_num_used'));
		array_push($rowvalues, $detail->get('prd_notes'));
		array_push($rowvalues, '<a href="/admin/admin_shadow_session_edit?prd_product_detail_id=' . $detail->key . '">Edit sessions</a>');
		//array_push($rowvalues, $status_to_html[$min_status ?: 1]);
		$page->disprow($rowvalues);
	}
	$page->endtable($pager);


	$page->admin_footer();	

?>
