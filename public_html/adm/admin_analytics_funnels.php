<?php

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('adm/logic/admin_analytics_funnels_logic.php'));

$session = SessionControl::get_instance();
$session->check_permission(5);

$page_vars = process_logic(admin_analytics_funnels_logic($_GET, $_POST));

$page = new AdminPage();
$page->admin_header(
array(
	'menu-id'=> 'email-statistics',
	'breadcrumbs' => array(
		'Statistics'=>'',
	),
	'session' => $session,
)
);

$formwriter = $page->getFormWriter('form1');
echo $formwriter->begin_form();
$formwriter->textinput('startdate', 'Start Date', [
	'value' => $page_vars['startdate']
]);
$formwriter->textinput('enddate', 'End Date', [
	'value' => $page_vars['enddate']
]);
$formwriter->dropinput('page_1', 'Page 1', [
	'options' => $page_vars['optionvals'],
	'value' => $page_vars['page_1']
]);
$formwriter->dropinput('page_2', 'Page 2', [
	'options' => $page_vars['optionvals'],
	'value' => $page_vars['page_2']
]);
$formwriter->dropinput('page_3', 'Page 3', [
	'options' => $page_vars['optionvals'],
	'value' => $page_vars['page_3']
]);
$formwriter->dropinput('page_4', 'Page 4', [
	'options' => $page_vars['optionvals'],
	'value' => $page_vars['page_4']
]);
$formwriter->dropinput('page_5', 'Page 5', [
	'options' => $page_vars['optionvals'],
	'value' => $page_vars['page_5']
]);

$formwriter->submitbutton('btn_submit', 'Submit');

echo $formwriter->end_form();

echo '<br />';

if($page_vars['page_1'] && $page_vars['page_2'] && $page_vars['startdate'] && $page_vars['enddate']){
	$headers = array("Page", "Visits", "Conversion from prev", "Total conversion");
	$box_vars =	array(
		'altlinks' => $altlinks,
		'title' => 'Funnel',
	);
	$page->tableheader($headers, $box_vars);

	$prev_count = NULL;
	$initial_count = 0;
	foreach ($page_vars['funnel_stats'] as $stat){

		$rowvalues = array();
		array_push($rowvalues, $stat->funnel_step);
		array_push($rowvalues, $stat->visitors);
		if($prev_count === NULL){
			array_push($rowvalues, '-');
			$initial_count = $stat->visitors;
		}
		else{
			$pct = round(($stat->visitors/$prev_count)*100, 2);
			array_push($rowvalues, $pct.'%');
		}
		$prev_count = $stat->visitors;

		if($prev_count === NULL){
			array_push($rowvalues, '-');
		}
		else{
			$pct = round(($stat->visitors/$initial_count)*100, 2);
			array_push($rowvalues, $pct.'%');
		}

		$page->disprow($rowvalues);
	}
	$page->endtable();
}

$page->admin_footer();

?>
