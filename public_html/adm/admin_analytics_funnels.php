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
$page_1 = LibraryFunctions::fetch_variable('page_1', NULL, 0, '');
$page_2 = LibraryFunctions::fetch_variable('page_2', NULL, 0, '');
$page_3 = LibraryFunctions::fetch_variable('page_3', NULL, 0, '');
$page_4 = LibraryFunctions::fetch_variable('page_4', NULL, 0, '');
$page_5 = LibraryFunctions::fetch_variable('page_5', NULL, 0, '');

$sql = "SELECT DISTINCT vse_page FROM vse_visitor_events GROUP BY vse_page HAVING COUNT(*) > 5 ORDER BY vse_page ASC;
";

$dbhelper = DbConnector::get_instance();
$dblink = $dbhelper->get_db_link();

try
{
	$q = $dblink->prepare($sql);
	//$q->bindParam(':startdate', $startdate, PDO::PARAM_STR);
	//$q->bindParam(':enddate', $enddate, PDO::PARAM_STR);
	$success = $q->execute();
	$q->setFetchMode(PDO::FETCH_OBJ);
}
catch(PDOException $e)
{
	$dbhelper->handle_query_error($e);
	exit();
}
$results = $q->fetchAll();

$optionvals = array();
foreach ($results as $row) {
	$optionvals[$row->vse_page] = $row->vse_page;
}
unset($results);

$formwriter = LibraryFunctions::get_formwriter_object('form1', 'admin');
echo $formwriter->begin_form("uniForm", "get", "/admin/admin_analytics_funnels");
echo $formwriter->textinput("Start Date", "startdate", "dateinput", 30, $startdate, "", 10);
echo $formwriter->textinput("End Date", "enddate", "dateinput", 30, $enddate, "", 10);
echo $formwriter->dropinput("Page 1", "page_1", "ctrlHolder", $optionvals, $page_1, '', TRUE);
echo $formwriter->dropinput("Page 2", "page_2", "ctrlHolder", $optionvals, $page_2, '', TRUE);
echo $formwriter->dropinput("Page 3", "page_3", "ctrlHolder", $optionvals, $page_3, '', TRUE);
echo $formwriter->dropinput("Page 4", "page_4", "ctrlHolder", $optionvals, $page_4, '', TRUE);
echo $formwriter->dropinput("Page 5", "page_5", "ctrlHolder", $optionvals, $page_5, '', TRUE);

echo $formwriter->start_buttons();
echo $formwriter->new_form_button('Submit');
echo $formwriter->end_buttons();

echo $formwriter->end_form();

echo '<br />';

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
		$dbhelper->handle_query_error($e);
		exit();
	}

	$funnel_stats = $q->fetchAll();

	$headers = array("Page", "Visits", "Conversion from prev", "Total conversion");
	$box_vars =	array(
		'altlinks' => $altlinks,
		'title' => 'Funnel',
	);
	$page->tableheader($headers, $box_vars);

	$prev_count = NULL;
	$initial_count = 0;
	foreach ($funnel_stats as $stat){

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
