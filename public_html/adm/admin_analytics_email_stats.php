<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

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

$formwriter = $page->getFormWriter('form1');
echo $formwriter->begin_form();
$formwriter->textinput('startdate', 'Start Date', [
	'value' => $startdate
]);
$formwriter->textinput('enddate', 'End Date', [
	'value' => $enddate
]);
/*
$optionvals = array("Day"=>"0", "Week"=>"1", "Month"=>"2", "Quarter"=>"3", "Year"=>"4");
$grouping = array("Day", "Week", "Month", "Quarter", "Year");
$formwriter->radioinput("Group by:", "interval", "radioinput", $optionvals, $interval, "BlockLabel", "", TRUE);
*/
$formwriter->submitbutton('btn_submit', 'Submit');

echo $formwriter->end_form();

echo '<br />';

// Page-view traffic by UTM dimension. Old page views live in the rollup, recent
// ones raw; the relation unions the two and VISITORS counts distinct people where
// the rows are raw, the event-count stand-in where they are rolled up.
$relation = AnalyticsRollup::pageview_relation();
$visitors = AnalyticsRollup::VISITORS;

//CONTENT
$sql = "SELECT
{$visitors} AS visitorcount,
content
FROM {$relation}
WHERE content IS NOT NULL GROUP BY content ORDER BY visitorcount DESC";

$dbhelper = DbConnector::get_instance();
$dblink = $dbhelper->get_db_link();

try
{
	$q = $dblink->prepare($sql);
	$q->bindParam(':av_start', $startdate, PDO::PARAM_STR);
	$q->bindParam(':av_end', $enddate, PDO::PARAM_STR);
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
{$visitors} AS visitorcount,
medium as content
FROM {$relation}
WHERE medium IS NOT NULL GROUP BY medium ORDER BY visitorcount DESC";

$dbhelper = DbConnector::get_instance();
$dblink = $dbhelper->get_db_link();

try
{
	$q = $dblink->prepare($sql);
	$q->bindParam(':av_start', $startdate, PDO::PARAM_STR);
	$q->bindParam(':av_end', $enddate, PDO::PARAM_STR);
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
{$visitors} AS visitorcount,
campaign as content
FROM {$relation}
WHERE campaign IS NOT NULL GROUP BY campaign ORDER BY visitorcount DESC";

$dbhelper = DbConnector::get_instance();
$dblink = $dbhelper->get_db_link();

try
{
	$q = $dblink->prepare($sql);
	$q->bindParam(':av_start', $startdate, PDO::PARAM_STR);
	$q->bindParam(':av_end', $enddate, PDO::PARAM_STR);
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
{$visitors} AS visitorcount,
source as content
FROM {$relation}
WHERE source IS NOT NULL GROUP BY source ORDER BY visitorcount DESC";

$dbhelper = DbConnector::get_instance();
$dblink = $dbhelper->get_db_link();

try
{
	$q = $dblink->prepare($sql);
	$q->bindParam(':av_start', $startdate, PDO::PARAM_STR);
	$q->bindParam(':av_end', $enddate, PDO::PARAM_STR);
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
