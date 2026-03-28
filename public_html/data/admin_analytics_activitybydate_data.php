<?php

$today = date("m-d-Y");
$startdate = LibraryFunctions::fetch_variable('startdate', date("m-d-Y", strtotime("-1 years")), 0, '');
$enddate = LibraryFunctions::fetch_variable('enddate', $today, 0, '');
$interval = LibraryFunctions::fetch_variable('interval', 2, 0, ''); // 0 = daily, 1 = weekly, 2 = monthly (default), 3 = quarterly, 4 = yearly
$usrdisabled = LibraryFunctions::fetch_variable("usr_is_disabled", 0, 0, '');

switch ($interval)
{
	case 0:
		$sqlinterval = "day";
		break;
	case 1:
		$sqlinterval = "week";
		break;
	case 2:
		$sqlinterval = "month";
		break;
	case 3:
		$sqlinterval = "quarter";
		break;
	case 4:
		$sqlinterval = "year";
		break;
	default:
		echo "Houston, we have a problem.";
}

$sqlstart = "'".$startdate."'";
$sqlend = "'".$enddate."'";


//Get Data
$sql = "SELECT 
date_trunc('$sqlinterval', usr_users.usr_signup_date) AS interval, 
count(distinct usr_users.usr_user_id) AS newusercount,  
count(distinct log_logins.log_login_time) AS numactiveusers
FROM usr_users  
LEFT OUTER JOIN log_logins ON usr_users.usr_user_id = log_logins.log_usr_user_id
WHERE usr_users.usr_signup_date >= :startdate and usr_users.usr_signup_date <= :enddate";

if ($usrdisabled)
{
	$sql .= " AND usr_users.usr_is_disabled IS FALSE";
}



$sql .= " GROUP BY interval ORDER BY interval ASC";

$dbhelper = DbConnector::get_instance();
$dblink = $dbhelper->get_db_link();

try
{
	$q = $dblink->prepare($sql);
	$q->bindParam(':startdate', $sqlstart, PDO::PARAM_STR);
	$q->bindParam(':enddate', $sqlend, PDO::PARAM_STR);
	$success = $q->execute();
	$q->setFetchMode(PDO::FETCH_OBJ);
}
catch(PDOException $e)
{
	$dbhelper->handle_query_error($e);
	exit();
}

$activities = $q->fetchAll();

$intervals = array();

foreach ($activities as $activity)
{
	$intervals[$activity->interval] = array('newusercount' => $activity->newusercount, 'numemailed' => 0, 'numactiveusers' => $activity->numactiveusers);
}

// End Get Data


?>
