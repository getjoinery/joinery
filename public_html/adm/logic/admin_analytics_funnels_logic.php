<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_analytics_funnels_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);

	$today = date("m-d-Y");
	$startdate = LibraryFunctions::fetch_variable('startdate', date("m-d-Y", strtotime("-1 months")), 0, '');
	$enddate = LibraryFunctions::fetch_variable('enddate', $today, 0, '');
	$page_1 = LibraryFunctions::fetch_variable('page_1', NULL, 0, '');
	$page_2 = LibraryFunctions::fetch_variable('page_2', NULL, 0, '');
	$page_3 = LibraryFunctions::fetch_variable('page_3', NULL, 0, '');
	$page_4 = LibraryFunctions::fetch_variable('page_4', NULL, 0, '');
	$page_5 = LibraryFunctions::fetch_variable('page_5', NULL, 0, '');

	$dbhelper = DbConnector::get_instance();
	$dblink = $dbhelper->get_db_link();

	// Get available pages for dropdown
	$sql = "SELECT DISTINCT vse_page FROM vse_visitor_events GROUP BY vse_page HAVING COUNT(*) > 5 ORDER BY vse_page ASC";

	try
	{
		$q = $dblink->prepare($sql);
		$success = $q->execute();
		$q->setFetchMode(PDO::FETCH_OBJ);
	}
	catch(PDOException $e)
	{
		return LogicResult::error('A database error occurred while loading analytics data.');
	}
	$results = $q->fetchAll();

	$optionvals = array();
	foreach ($results as $row) {
		$optionvals[$row->vse_page] = $row->vse_page;
	}
	unset($results);

	// Initialize funnel stats
	$funnel_stats = array();

	// Build and execute funnel query if pages are selected
	if($page_1 && $page_2 && $startdate && $enddate){
		//CONTENT
		$sql = "WITH step1 AS (
		  SELECT
			vse_visitor_id,
			MIN(vse_timestamp) AS step1_ts
		  FROM vse_visitor_events
		  WHERE vse_page = '$page_1'
		  AND vse_timestamp >= :startdate AND vse_timestamp <= :enddate
		  GROUP BY vse_visitor_id
		)";
		if($page_2){
			$sql .= ", step2 AS (
			  SELECT
				v.vse_visitor_id,
				MIN(v.vse_timestamp) AS step2_ts
			  FROM vse_visitor_events v
			  JOIN step1 s
				ON v.vse_visitor_id = s.vse_visitor_id
			  WHERE v.vse_page = '$page_2'
				AND v.vse_timestamp > s.step1_ts
				AND vse_timestamp >= :startdate AND vse_timestamp <= :enddate
			  GROUP BY v.vse_visitor_id
			)";
		}
		if($page_3){
			$sql .= ", step3 AS (
			  SELECT
				v.vse_visitor_id,
				MIN(v.vse_timestamp) AS step2_ts
			  FROM vse_visitor_events v
			  JOIN step1 s
				ON v.vse_visitor_id = s.vse_visitor_id
			  WHERE v.vse_page = '$page_3'
				AND v.vse_timestamp > s.step1_ts
				AND vse_timestamp >= :startdate AND vse_timestamp <= :enddate
			  GROUP BY v.vse_visitor_id
			)";
		}
		if($page_4){
			$sql .= ", step4 AS (
			  SELECT
				v.vse_visitor_id,
				MIN(v.vse_timestamp) AS step2_ts
			  FROM vse_visitor_events v
			  JOIN step1 s
				ON v.vse_visitor_id = s.vse_visitor_id
			  WHERE v.vse_page = '$page_4'
				AND v.vse_timestamp > s.step1_ts
				AND vse_timestamp >= :startdate AND vse_timestamp <= :enddate
			  GROUP BY v.vse_visitor_id
			)";
		}
		if($page_5){
			$sql .= ", step5 AS (
			  SELECT
				v.vse_visitor_id,
				MIN(v.vse_timestamp) AS step2_ts
			  FROM vse_visitor_events v
			  JOIN step1 s
				ON v.vse_visitor_id = s.vse_visitor_id
			  WHERE v.vse_page = '$page_5'
				AND v.vse_timestamp > s.step1_ts
				AND vse_timestamp >= :startdate AND vse_timestamp <= :enddate
			  GROUP BY v.vse_visitor_id
			)";
		}

		$sql .= "SELECT '/' AS funnel_step, COUNT(*) AS visitors
		FROM step1";

		if($page_2){
			$sql .= " UNION ALL SELECT '$page_2' AS funnel_step, COUNT(*) AS visitors
			FROM step2
			";
		}

		if($page_3){
			$sql .= " UNION ALL SELECT '$page_3' AS funnel_step, COUNT(*) AS visitors
			FROM step3
			";
		}

		if($page_4){
			$sql .= " UNION ALL SELECT '$page_4' AS funnel_step, COUNT(*) AS visitors
			FROM step4
			";
		}

		if($page_5){
			$sql .= " UNION ALL SELECT '$page_5' AS funnel_step, COUNT(*) AS visitors
			FROM step5
			";
		}

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
			return LogicResult::error('A database error occurred while processing funnel data.');
		}

		$funnel_stats = $q->fetchAll();
	}

	// Return data for view
	$result = new LogicResult();
	$result->data = array(
		'startdate' => $startdate,
		'enddate' => $enddate,
		'page_1' => $page_1,
		'page_2' => $page_2,
		'page_3' => $page_3,
		'page_4' => $page_4,
		'page_5' => $page_5,
		'optionvals' => $optionvals,
		'funnel_stats' => $funnel_stats,
	);

	return $result;
}
?>
