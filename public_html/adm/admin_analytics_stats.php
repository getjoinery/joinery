<?php

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('adm/logic/admin_analytics_stats_logic.php'));

$session = SessionControl::get_instance();
$session->check_permission(5);

$page_vars = process_logic(admin_analytics_stats_logic($_GET, $_POST));

$page = new AdminPage();
$page->admin_header(
array(
	'menu-id'=> 'web-statistics',
	'breadcrumbs' => array(
		'Statistics'=>'',
	),
	'session' => $session,
)
);

$formwriter = $page->getFormWriter('form1', 'v2');
echo $formwriter->begin_form();
$formwriter->textinput('startdate', 'Start Date', [
	'value' => $page_vars['startdate']
]);
$formwriter->textinput('enddate', 'End Date', [
	'value' => $page_vars['enddate']
]);
$formwriter->submitbutton('btn_submit', 'Submit');

echo $formwriter->end_form();

echo '<br />';

?>
<div style="width: 1000px; height: 500px;">
<script src="https://cdn.jsdelivr.net/npm/chart.js@2.8.0"></script>
<canvas id="myChart" ></canvas>
</div>
<script>
var ctx = document.getElementById('myChart').getContext('2d');
var chart = new Chart(ctx, {
    // The type of chart we want to create
    type: 'line',

    // The data for our dataset
    data: {
        labels: <?php echo json_encode($page_vars['xvals']); ?>,
        datasets: [{
            label: 'Daily Unique Visitors',
            backgroundColor: 'rgb(51, 153, 255)',
            borderColor: 'rgb(0, 0, 204)',
            data: <?php echo json_encode($page_vars['yvals']); ?>
        }]
    },

    // Configuration options go here
    options: {}
});
</script>

<?php

$headers = array("Page", "Visits");
$box_vars =	array(
	'altlinks' => $altlinks,
	'title' => 'Top Pages',
);
$page->tableheader($headers, $box_vars);

$rowtotals = array("<b>Totals</b>", 0);

foreach ($page_vars['page_visitors'] as $page_visitor => $values)
{
	if($values->visitorcount <= 10){
		break;
	}
	$rowvalues = array();

	array_push($rowvalues, $values->page);
	array_push($rowvalues, $values->visitorcount);

	$rowtotals[1] += $values->visitorcount;

	$page->disprow($rowvalues);
}

$page->disprow($rowtotals);

$page->endtable();

$headers = array("404 Pages", "Tries");
$box_vars =	array(
	'altlinks' => $altlinks,
	'title' => '404 Pages',
);
$page->tableheader($headers, $box_vars);

$rowtotals = array("<b>Totals</b>", 0);

foreach ($page_vars['t404_pages'] as $t404_page => $values)
{
	$rowvalues = array();
	if($values->visitorcount <= 5){
		break;
	}

	array_push($rowvalues, $values->page);
	array_push($rowvalues, $values->visitorcount);

	$rowtotals[1] += $values->visitorcount;

	$page->disprow($rowvalues);
}

$page->disprow($rowtotals);

$page->endtable();

$page->admin_footer();

?>
