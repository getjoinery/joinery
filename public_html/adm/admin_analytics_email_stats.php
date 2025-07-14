<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

	PathHelper::requireOnce('includes/ErrorHandler.php');
	
	PathHelper::requireOnce('includes/SessionControl.php');
	PathHelper::requireOnce('includes/AdminPage.php');
	PathHelper::requireOnce('includes/DbConnector.php');



$session = SessionControl::get_instance();
$session->check_permission(5);

$dbhelper = DbConnector::get_instance();
$dblink = $dbhelper->get_db_link();

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
echo $formwriter->begin_form("uniForm", "post", "/admin/admin_analytics_email_stats");
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





//CONTENT
$sql = "SELECT 
count(distinct vse_visitor_events.vse_visitor_id) AS visitorcount,
vse_visitor_events.vse_content as content
FROM vse_visitor_events 
WHERE vse_visitor_events.vse_timestamp >= :startdate AND vse_visitor_events.vse_timestamp <= :enddate AND vse_visitor_events.vse_content IS NOT NULL GROUP BY vse_visitor_events.vse_content ORDER BY visitorcount DESC";


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

$email_content_stats = $q->fetchAll();
$headers = array("Email", "Visits");
$box_vars =	array(
	'altlinks' => $altlinks,
	'title' => 'Email Content',
);
$page->tableheader($headers, $box_vars);
$rowtotals = array("<b>Totals</b>", 0);
foreach ($email_content_stats as $email_stats => $values){		

	$rowvalues = array();
	array_push($rowvalues, $values->content);
	array_push($rowvalues, $values->visitorcount);
	$rowtotals[1] += $values->visitorcount;
	$page->disprow($rowvalues);
}
$page->disprow($rowtotals);
$page->endtable();

//MEDIUM
$sql = "SELECT 
count(distinct vse_visitor_events.vse_visitor_id) AS visitorcount,
vse_visitor_events.vse_medium as content
FROM vse_visitor_events 
WHERE vse_visitor_events.vse_timestamp >= :startdate AND vse_visitor_events.vse_timestamp <= :enddate AND vse_visitor_events.vse_medium IS NOT NULL GROUP BY vse_visitor_events.vse_medium ORDER BY visitorcount DESC";


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

$email_medium_stats = $q->fetchAll();
$headers = array("Email", "Visits");
$box_vars =	array(
	'altlinks' => $altlinks,
	'title' => 'Email Mediums',
);
$page->tableheader($headers, $box_vars);
$rowtotals = array("<b>Totals</b>", 0);
foreach ($email_medium_stats as $email_stats => $values){		

	$rowvalues = array();
	array_push($rowvalues, $values->content);
	array_push($rowvalues, $values->visitorcount);
	$rowtotals[1] += $values->visitorcount;
	$page->disprow($rowvalues);
}
$page->disprow($rowtotals);
$page->endtable();

//CAMPAIGN

$sql = "SELECT 
count(distinct vse_visitor_events.vse_visitor_id) AS visitorcount,
vse_visitor_events.vse_campaign as content
FROM vse_visitor_events 
WHERE vse_visitor_events.vse_timestamp >= :startdate AND vse_visitor_events.vse_timestamp <= :enddate AND vse_visitor_events.vse_campaign IS NOT NULL GROUP BY vse_visitor_events.vse_campaign ORDER BY visitorcount DESC";


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

$email_campaign_stats = $q->fetchAll();
$headers = array("Email", "Visits");
$box_vars =	array(
	'altlinks' => $altlinks,
	'title' => 'Email Campaigns',
);
$page->tableheader($headers, $box_vars);
$rowtotals = array("<b>Totals</b>", 0);
foreach ($email_campaign_stats as $email_stats => $values){		

	$rowvalues = array();
	array_push($rowvalues, $values->content);
	array_push($rowvalues, $values->visitorcount);
	$rowtotals[1] += $values->visitorcount;
	$page->disprow($rowvalues);
}
$page->disprow($rowtotals);
$page->endtable();
//SOURCE

$sql = "SELECT 
count(distinct vse_visitor_events.vse_visitor_id) AS visitorcount,
vse_visitor_events.vse_source as content
FROM vse_visitor_events 
WHERE vse_visitor_events.vse_timestamp >= :startdate AND vse_visitor_events.vse_timestamp <= :enddate AND vse_visitor_events.vse_source IS NOT NULL GROUP BY vse_visitor_events.vse_source ORDER BY visitorcount DESC";


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

$email_source_stats = $q->fetchAll();
$headers = array("Email", "Visits");
$box_vars =	array(
	'altlinks' => $altlinks,
	'title' => 'Email Sources',
);
$page->tableheader($headers, $box_vars);
$rowtotals = array("<b>Totals</b>", 0);
foreach ($email_source_stats as $email_stats => $values){		

	$rowvalues = array();
	array_push($rowvalues, $values->content);
	array_push($rowvalues, $values->visitorcount);
	$rowtotals[1] += $values->visitorcount;
	$page->disprow($rowvalues);
}
$page->disprow($rowtotals);
$page->endtable();





$page->admin_footer();

?>
