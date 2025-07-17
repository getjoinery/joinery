<?php
	require_once(__DIR__ . '/../includes/PathHelper.php');
	PathHelper::requireOnce('includes/ErrorHandler.php');
	
	PathHelper::requireOnce('includes/AdminPage.php');
	PathHelper::requireOnce('includes/SessionControl.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');

	PathHelper::requireOnce('data/coupon_codes_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'coupon_code_id', 0, '');
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
	$searchterm = LibraryFunctions::fetch_variable('searchterm', '', 0, '');
	
	
	$search_criteria = array();

	//ONLY SHOW DELETED TO SUPER ADMINS
	if($_SESSION['permission'] < 10){
		$search_criteria['deleted'] = false;
	}
	
	$coupon_codes = new MultiCouponCode(
		$search_criteria,
		array('ccd_coupon_code_id'=>'DESC'),
		$numperpage,
		$offset,
		'AND');	
	$numrecords = $coupon_codes->count_all();	
	$coupon_codes->load();
	
	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'coupons',
		'breadcrumbs' => array(
			'Products'=>'/admin/admin_products', 
			'Coupon Codes'=>'', 
		),
		'session' => $session,
	)
	);	
	


	$headers = array("CouponCode",  "Created",  "Active");
	$altlinks = array('New Coupon Code'=>'/admin/admin_coupon_code_edit');
	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
	$table_options = array(
		//'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
		'altlinks' => $altlinks,
		'title' => 'Coupon Codes',
		//'search_on' => TRUE
	);
	$page->tableheader($headers, $table_options, $pager);


	foreach ($coupon_codes as $coupon_code){
		
		
		$rowvalues = array();
		array_push($rowvalues, "<a href='/admin/admin_coupon_code?ccd_coupon_code_id=$coupon_code->key'>".$coupon_code->get('ccd_code')."</a>");	
		//array_push($rowvalues, $coupon_code->get('ccd_type'));
		array_push($rowvalues, LibraryFunctions::convert_time($coupon_code->get('ccd_create_time'), 'UTC', $session->get_timezone()));

		if($coupon_code->get('ccd_delete_time')) {
			$status = 'Deleted';
		} else {
			$status = 'Active';
		}		
		array_push($rowvalues, $status);

		$page->disprow($rowvalues);
	}


	$page->endtable($pager);	
	$page->admin_footer();
?>


