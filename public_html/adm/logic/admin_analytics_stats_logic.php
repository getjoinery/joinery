<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_analytics_stats_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

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

	// Get unique visitors data
	$dbhelper = DbConnector::get_instance();
	$dblink = $dbhelper->get_db_link();

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

	// Get top pages data
	$sql = "SELECT
	count(distinct vse_visitor_events.vse_visitor_id) AS visitorcount,
	vse_visitor_events.vse_page as page
	FROM vse_visitor_events
	WHERE
	vse_visitor_events.vse_timestamp >= :startdate AND
	vse_visitor_events.vse_timestamp <= :enddate AND
	(vse_visitor_events.vse_is_404 != TRUE OR vse_visitor_events.vse_is_404 IS NULL)
	GROUP BY page ORDER BY visitorcount DESC";

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

	// Get 404 pages data
	$sql = "SELECT
	count(distinct vse_visitor_events.vse_visitor_id) AS visitorcount,
	vse_visitor_events.vse_page as page
	FROM vse_visitor_events
	WHERE
	vse_visitor_events.vse_timestamp >= :startdate AND
	vse_visitor_events.vse_timestamp <= :enddate AND
	vse_visitor_events.vse_is_404 = TRUE
	GROUP BY page ORDER BY visitorcount DESC";

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

	// Return data for view
	$result = new LogicResult();
	$result->data = array(
		'startdate' => $startdate,
		'enddate' => $enddate,
		'interval' => $interval,
		'sqlinterval' => $sqlinterval,
		'xvals' => $xvals,
		'yvals' => $yvals,
		'unique_visitors' => $unique_visitors,
		'page_visitors' => $page_visitors,
		't404_pages' => $t404_pages,
	);

	return $result;
}
?>
