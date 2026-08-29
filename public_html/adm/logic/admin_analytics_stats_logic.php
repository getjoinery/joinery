<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_analytics_stats_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);

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

	// Get unique visitors data. Old page views live in the rollup, recent ones
	// raw; AnalyticsRollup::pageview_relation() unions the two seamlessly, and
	// VISITORS counts distinct people where the rows are raw, the event-count
	// stand-in where they are rolled up. Binds :av_start / :av_end.
	$dbhelper = DbConnector::get_instance();
	$dblink = $dbhelper->get_db_link();

	$relation = AnalyticsRollup::pageview_relation();
	$visitors = AnalyticsRollup::VISITORS;

	$sql = "SELECT
	date_part('day', d) as day,
	date_part('month', d) as month,
	date_part('year', d) as year,
	{$visitors} AS visitorcount
	FROM {$relation}
	GROUP BY day, month, year ORDER BY year, month, day ASC";

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
		return LogicResult::error('A database error occurred while loading visitor data.');
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
	{$visitors} AS visitorcount,
	page
	FROM {$relation}
	WHERE is_404 IS NOT TRUE
	GROUP BY page ORDER BY visitorcount DESC";

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
		return LogicResult::error('A database error occurred while loading page data.');
	}

	$page_visitors = $q->fetchAll();

	// Get 404 pages data
	$sql = "SELECT
	{$visitors} AS visitorcount,
	page
	FROM {$relation}
	WHERE is_404 IS TRUE
	GROUP BY page ORDER BY visitorcount DESC";

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
		return LogicResult::error('A database error occurred while loading 404 data.');
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
		'rollup_notice' => AnalyticsRollup::proxy_notice($startdate),
	);

	return $result;
}
?>
