<?php
	require_once('../includes/Globalvars.php');
	$settings = Globalvars::get_instance();
	$siteDir = $settings->get_setting('siteDir');	
require_once($siteDir . '/includes/SessionControl.php');
require_once($siteDir . '/includes/LibraryFunctions.php');
require_once($siteDir . '/includes/SessionControl.php');

echo 'feature turned off';
exit;
error_reporting(E_ALL | E_STRICT);

$session = SessionControl::get_instance();

$time = '2020-03-12 13:00:11.184756';

echo LibraryFunctions::convert_time($time, 'UTC', 'UTC'). $session->get_timezone(). '<br />';
echo LibraryFunctions::convert_time($time, 'UTC', $session->get_timezone()). $session->get_timezone(). '<br />';

$tz = 'UTC';
$tz2 = 'America/New_York';
$dt = new DateTime($time, new DateTimeZone($tz)); //first argument "must" be a string
echo $dt->format('d.m.Y, H:i:s e T');
echo '<br />';

$dt->setTimezone(new DateTimeZone($tz2));


echo $dt->format('d.m.Y, H:i:s e T') ;

?>
