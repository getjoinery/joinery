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
echo $formwriter->begin_form("uniForm", "get", "/admin/admin_analytics_funnels");
echo $formwriter->textinput("Start Date", "startdate", "dateinput", 30, $page_vars['startdate'], "", 10);
echo $formwriter->textinput("End Date", "enddate", "dateinput", 30, $page_vars['enddate'], "", 10);
echo $formwriter->dropinput("Page 1", "page_1", "ctrlHolder", $page_vars['optionvals'], $page_vars['page_1'], '', TRUE);
echo $formwriter->dropinput("Page 2", "page_2", "ctrlHolder", $page_vars['optionvals'], $page_vars['page_2'], '', TRUE);
echo $formwriter->dropinput("Page 3", "page_3", "ctrlHolder", $page_vars['optionvals'], $page_vars['page_3'], '', TRUE);
echo $formwriter->dropinput("Page 4", "page_4", "ctrlHolder", $page_vars['optionvals'], $page_vars['page_4'], '', TRUE);
echo $formwriter->dropinput("Page 5", "page_5", "ctrlHolder", $page_vars['optionvals'], $page_vars['page_5'], '', TRUE);

echo $formwriter->start_buttons();
echo $formwriter->new_form_button('Submit');
echo $formwriter->end_buttons();

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
