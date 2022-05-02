<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Activation.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

require_once($_SERVER['DOCUMENT_ROOT'] . '/data/activation_codes_class.php');

$settings = Globalvars::get_instance();
if(!$settings->get_setting('register_active')){
		header("HTTP/1.0 404 Not Found");
		echo 'This feature is turned off';
		exit();
}

$settings = Globalvars::get_instance();

if(!$url = $settings->get_setting('urbit_endpoint')){
		header("HTTP/1.0 404 Not Found");
		echo 'Urbit Login is turned off';
		exit();
}

if(!$urbit_auth = $settings->get_setting('urbit_endpoint_password')){
		throw new SystemDisplayableError(
		'There is no urbit endpoint password.');
}

$urbit_ship = LibraryFunctions::fetch_variable('urbit_ship', '', 1, '');

$url = "https://planet.jeremytunnell.com/~initiateAuth"; 
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $urbit_ship);
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
	'accept: application/json',
	'content-type: application/json',
    'auth: '.$urbit_auth
));
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
$result = json_decode(curl_exec($ch));
$httpcode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

if($httpcode != 200){
	throw new SystemDisplayableError(
		'Sorry, there was a problem connecting with the urbit server.');
}
else if($result->error){
	throw new SystemDisplayableError(
		'Sorry, there was a problem: '.$result->error);
}
else{
	$token = $result->token;
	$target = $result->target;
	
	$act_code = new ActivationCode(NULL);
	$act_code->set('act_usr_email', trim(strtolower($target)));
	$act_code->set('act_code', trim(strtolower($token)));
	
	$time_interval='2 days';
	$expires_time = new DateTime();
	$expires_time->add(DateInterval::createFromDateString($time_interval));
	$act_code->set('act_expires_time', $expires_time->format(DATE_ATOM), PDO::PARAM_STR);
	$act_code->prepare();
	$act_code->save();
}
?>
