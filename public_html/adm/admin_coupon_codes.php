<?php

require_once(PathHelper::getIncludePath('adm/logic/admin_coupon_codes_logic.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

$page_vars = process_logic(admin_coupon_codes_logic($_GET, $_POST));

$session = $page_vars['session'];
$coupon_codes = $page_vars['coupon_codes'];
$numrecords = $page_vars['numrecords'];
$numperpage = $page_vars['numperpage'];

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
	'altlinks' => $altlinks,
	'title' => 'Coupon Codes',
);
$page->tableheader($headers, $table_options, $pager);

foreach ($coupon_codes as $coupon_code){
	$rowvalues = array();
	array_push($rowvalues, "<a href='/admin/admin_coupon_code?ccd_coupon_code_id=$coupon_code->key'>".$coupon_code->get('ccd_code')."</a>");
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
