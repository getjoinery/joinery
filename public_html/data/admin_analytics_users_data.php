<?php

$today = date("m-d-Y");
$startdate = LibraryFunctions::fetch_variable('startdate', date("m-d-Y", strtotime("-1 years")), 0, '');
$enddate = LibraryFunctions::fetch_variable('enddate', $today, 0, '');
$mintotal = (int)LibraryFunctions::fetch_variable('mintotal', 0, 0, '');
$disabled = LibraryFunctions::fetch_variable("usr_is_disabled", 1, 0, '');

$sqlstart = "'".$startdate."'";
$sqlend = "'".$enddate."'";

//Get Data
$sql_domains = "select substring(usr_email from '.*@(.*)') as edomain, count(*) as edomaincount from usr_users where usr_signup_date > :startdate and usr_signup_date < :enddate";
if (!$disabled)
{
	$sql_domains .= " and usr_is_disabled = :disabled";
}
$sql_domains .= " group by edomain order by edomaincount DESC";

$dbhelper = DbConnector::get_instance();
$dblink = $dbhelper->get_db_link();

try{
	$q = $dblink->prepare($sql_domains);
	$q->bindParam(':startdate', $sqlstart, PDO::PARAM_STR);
	$q->bindParam(':enddate', $sqlend, PDO::PARAM_STR);
	if (!$disabled)
	{
		$q->bindParam(':disabled', $disabled, PDO::PARAM_INT);
	}
	$success = $q->execute();
	$q->setFetchMode(PDO::FETCH_OBJ);
}
catch(PDOException $e){
	$dbhelper->handle_query_error($e);
	exit();
}

$domains = $q->fetchAll();

$domaincounts = array();
foreach ($domains as $domain)
{
	$domaincounts[$domain->edomain] = array('total' => $domain->edomaincount, 'vtotal' => 0);
}

$sql_verifieds = "select substring(usr_email from '.*@(.*)') as edomain, count(*) as edomaincount from usr_users where usr_signup_date > :startdate and usr_signup_date < :enddate and usr_email_is_verified is TRUE"; 
if (!$disabled)
{
	$sql_verifieds .= " and usr_is_disabled = :disabled";
}
$sql_verifieds .= " group by edomain order by edomaincount DESC";

$dbhelper = DbConnector::get_instance();
$dblink = $dbhelper->get_db_link();

try{
	$q = $dblink->prepare($sql_verifieds);
	$q->bindParam(':startdate', $sqlstart, PDO::PARAM_STR);
	$q->bindParam(':enddate', $sqlend, PDO::PARAM_STR);
	if (!$disabled)
	{
		$q->bindParam(':disabled', $disabled, PDO::PARAM_INT);
	}
	$success = $q->execute();
	$q->setFetchMode(PDO::FETCH_OBJ);
}
catch(PDOException $e){
	$dbhelper->handle_query_error($e);
	exit();
}

$verifieds = $q->fetchAll();

foreach ($verifieds as $verified)
{
	$domaincounts[$verified->edomain]['vtotal'] = $verified->edomaincount;
}
// End Get Data

?>