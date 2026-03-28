<?php

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('adm/logic/admin_yearly_report_donations_logic.php'));

$session = SessionControl::get_instance();
$session->check_permission(10);
$session->set_return();

$page_vars = process_logic(admin_yearly_report_donations_logic($_GET, $_POST));

$page = new AdminPage();
$page->admin_header(
array(
	'menu-id'=> NULL,
	'breadcrumbs' => array(
		'Orders'=>'',
	),
	'page_title' => 'Orders',
	'readable_title' => 'Orders',
	'session' => $session,
)
);

$formwriter = $page->getFormWriter('form1', [
	'method' => 'GET',
	'action' => '/admin/admin_yearly_report_donations'
]);
$formwriter->begin_form();
$formwriter->dateinput('startdate', 'Start Date (UTC Time)', [
	'value' => $page_vars['startdate']
]);
$formwriter->dateinput('enddate', 'End Date (UTC Time)', [
	'value' => $page_vars['enddate']
]);
$formwriter->hiddeninput('source', '', ['value' => 'form']);
$formwriter->submitbutton('submit_button', 'Submit');
$formwriter->end_form();

$headers = array('Name', 'Product', 'Total');
$altlinks = array();
$pager = new Pager(array('numrecords'=>$page_vars['numrecords'], 'numperpage'=> $page_vars['numperpage']));
$table_options = array(
	'altlinks' => $altlinks,
	'title' => 'Orders',
);
$page->tableheader($headers, $table_options, $pager);

foreach($page_vars['results'] as $result){
	if($result['total'] > 0){
		$rowvalues = array();
		array_push($rowvalues, $result['name'] . ' ('.$result['email'].')');
		$page->disprow($rowvalues);
		foreach($result['products'] as $product){
			$rowvalues = array();
			array_push($rowvalues, '');
			array_push($rowvalues, $product['name']);
			array_push($rowvalues, $page_vars['currency_symbol'].$product['amount']);
			$page->disprow($rowvalues);
		}

		$rowvalues = array();
		array_push($rowvalues, '');
		array_push($rowvalues, '<b>Total:</b>');
		array_push($rowvalues, '<b>'.$page_vars['currency_symbol'].$result['total'].'</b>');
		$page->disprow($rowvalues);
	}
}

$page->endtable($pager);

$page->admin_footer();

?>
