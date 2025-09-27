<?php

	// ErrorHandler.php no longer needed - using new ErrorManager system

	PathHelper::requireOnce('includes/AdminPage.php');
	
$session = SessionControl::get_instance();
$session->check_permission(5);

$dbhelper = DbConnector::get_instance();
$dblink = $dbhelper->get_db_link();

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
	/*
?>
<script src="https://canvasjs.com/assets/script/canvasjs.min.js"></script>
<script type="text/javascript">

		$(document).ready(function() 
		{
			$("#sqlbtn").toggle
			(
				function ()
				{
					$("#sql").show();
				},
				function ()
				{
					$("#sql").hide();
				}
			);
			
			$("#sql").hide();
		});
		
</script>

<?php
*/

$today = date("m-d-Y");
$startdate = LibraryFunctions::fetch_variable('startdate', date("m-d-Y", strtotime("-1 months")), 0, '');
$enddate = LibraryFunctions::fetch_variable('enddate', $today, 0, '');
$interval = LibraryFunctions::fetch_variable('interval', 0, 0, ''); // 0 = daily, 1 = weekly, 2 = monthly (default), 3 = quarterly, 4 = yearly
$usrdisabled = LibraryFunctions::fetch_variable("usr_is_disabled", 0, 0, '');

switch ($interval)
{
	case 0:
		$sqlinterval = "day";
		break;
	case 1;
		$sqlinterval = "week";
		break;
	case 2;
		$sqlinterval = "month";
		break;
	case 3;
		$sqlinterval = "quarter";
		break;
	case 4;
		$sqlinterval = "year";
		break;
	default:
		echo "Houston, we have a problem.";
}

$formwriter = LibraryFunctions::get_formwriter_object('form1', 'admin');
echo $formwriter->begin_form("uniForm", "post", "/admin/admin_analytics_stats");
echo $formwriter->textinput("Start Date", "startdate", "dateinput", 30, $startdate, "", 10);
echo $formwriter->textinput("End Date", "enddate", "dateinput", 30, $enddate, "", 10);
/*
$optionvals = array("Day"=>"0", "Week"=>"1", "Month"=>"2", "Quarter"=>"3", "Year"=>"4");
$grouping = array("Day", "Week", "Month", "Quarter", "Year");
$formwriter->radioinput("Group by:", "interval", "radioinput", $optionvals, $interval, "BlockLabel", "", TRUE);
*/
echo $formwriter->start_buttons();
echo $formwriter->new_form_button('Submit');
echo $formwriter->end_buttons();

echo $formwriter->end_form();

echo '<br />';

//Get Data
$sql = "SELECT 
date_part('day', vse_visitor_events.vse_timestamp) as day,
date_part('month', vse_visitor_events.vse_timestamp) as month,
date_part('year', vse_visitor_events.vse_timestamp) as year,
count(distinct vse_visitor_events.vse_visitor_id) AS visitorcount
FROM vse_visitor_events 
WHERE vse_visitor_events.vse_timestamp >= :startdate AND vse_visitor_events.vse_timestamp <= :enddate GROUP BY day, month, year ORDER BY year, month, day ASC";

try
{
	$q = $dblink->prepare($sql);
	$q->bindParam(':startdate', $startdate, PDO::PARAM_STR);
	$q->bindParam(':enddate', $enddate, PDO::PARAM_STR);
	$success = $q->execute();
	$q->setFetchMode(PDO::FETCH_OBJ);
}
catch(PDOException $e)
{
	$dbhelper->handle_query_error($e);
	exit();
}

$unique_visitors = $q->fetchAll();
$yvals = array();
$xvals = array();
$c=0;
foreach($unique_visitors as $unique_visitor => $values){
	$datedisp = $values->year. '-'.$values->month.'-'.$values->day;
	$yvals[$c] = $values->visitorcount;
	$xvals[$c] = $datedisp;
	$c++;
}
 
/*
?>
<div id="chartContainer" style="height: 370px; width: 100%;"></div>
<script>
window.onload = function () {
 
var chart = new CanvasJS.Chart("chartContainer", {
	title: {
		text: "Daily Unique Visitors"
	},
	axisY: {
		title: "Unique Visitors"
	},
	data: [{
		type: "line",
		dataPoints: <?php echo json_encode($datapoints, JSON_NUMERIC_CHECK); ?>
	}]
});
chart.render();
 
}
</script>
<?php
*/

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
        labels: <?php echo json_encode($xvals); ?>,
        datasets: [{
            label: 'Daily Unique Visitors',
            backgroundColor: 'rgb(51, 153, 255)',
            borderColor: 'rgb(0, 0, 204)',
            data: <?php echo json_encode($yvals); ?>
        }]
    },

    // Configuration options go here
    options: {}
});
</script>

<?php

$sql = "SELECT 
count(distinct vse_visitor_events.vse_visitor_id) AS visitorcount,
vse_visitor_events.vse_page as page
FROM vse_visitor_events 
WHERE 
vse_visitor_events.vse_timestamp >= :startdate AND 
vse_visitor_events.vse_timestamp <= :enddate AND 
(vse_visitor_events.vse_is_404 != TRUE OR vse_visitor_events.vse_is_404 IS NULL)
GROUP BY page ORDER BY visitorcount DESC";

$dbhelper = DbConnector::get_instance();
$dblink = $dbhelper->get_db_link();

try
{
	$q = $dblink->prepare($sql);
	$q->bindParam(':startdate', $startdate, PDO::PARAM_STR);
	$q->bindParam(':enddate', $enddate, PDO::PARAM_STR);
	$success = $q->execute();
	$q->setFetchMode(PDO::FETCH_OBJ);
}
catch(PDOException $e)
{
	$dbhelper->handle_query_error($e);
	exit();
}

$page_visitors = $q->fetchAll();

$sql = "SELECT 
count(distinct vse_visitor_events.vse_visitor_id) AS visitorcount,
vse_visitor_events.vse_page as page
FROM vse_visitor_events 
WHERE 
vse_visitor_events.vse_timestamp >= :startdate AND 
vse_visitor_events.vse_timestamp <= :enddate AND 
vse_visitor_events.vse_is_404 = TRUE 
GROUP BY page ORDER BY visitorcount DESC";

$dbhelper = DbConnector::get_instance();
$dblink = $dbhelper->get_db_link();

try
{
	$q = $dblink->prepare($sql);
	$q->bindParam(':startdate', $startdate, PDO::PARAM_STR);
	$q->bindParam(':enddate', $enddate, PDO::PARAM_STR);
	$success = $q->execute();
	$q->setFetchMode(PDO::FETCH_OBJ);
}
catch(PDOException $e)
{
	$dbhelper->handle_query_error($e);
	exit();
}

$t404_pages = $q->fetchAll();

/*
$headers = array("Date Range: " . $grouping[$interval], "Unique Visitors");
$box_vars =	array(
	'altlinks' => $altlinks,
	'title' => 'Visitors',
);
$page->tableheader($headers, $box_vars);

$rowtotals = array("<b>Totals</b>", 0);

foreach ($unique_visitors as $unique_visitor => $values)
{		
	$rowvalues = array();
	
	array_push($rowvalues, $values->year. '-'.$values->month.'-'.$values->day);
	array_push($rowvalues, $values->visitorcount);
	
	$rowtotals[1] += $values->visitorcount;

	$page->disprow($rowvalues);
}

$page->disprow($rowtotals);
$page->endtable();
*/

$headers = array("Page", "Visits");
$box_vars =	array(
	'altlinks' => $altlinks,
	'title' => 'Top Pages',
);
$page->tableheader($headers, $box_vars);

$rowtotals = array("<b>Totals</b>", 0);

foreach ($page_visitors as $page_visitor => $values)
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

foreach ($t404_pages as $t404_page => $values)
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
