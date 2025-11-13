<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/Globalvars.php'));

require_once(PathHelper::getIncludePath('includes/SessionControl.php'));

require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('plugins/bookings/data/bookings_class.php'));

$settings = Globalvars::get_instance();
$composer_dir = $settings->get_setting('composerAutoLoad');
require_once(PathHelper::getAbsolutePath('vendor/autoload.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(10);

	// TEMPORARILY DISABLED - Calendly integration under review
	echo 'Calendly test temporarily disabled';
	exit;

	$ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, 'https://api.calendly.com/users/me');

    $headers = array(
    'authorization: Bearer '.$settings->get_setting('calendly_api_token'),
    );
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    curl_setopt($ch, CURLOPT_HEADER, 0);
    $body = '{}';

    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
    curl_setopt($ch, CURLOPT_POSTFIELDS,$body);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // Timeout in seconds
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

	$response = curl_exec($ch);

	if (curl_errno($response)) {
		echo 'Error:  ' . curl_errno($response);
	}
	else if ($http_code = curl_getinfo($ch , CURLINFO_HTTP_CODE) != 200){
		echo 'Error:  Http code ' . $http_code;
	}
	else{
		$decoded = json_decode(curl_exec($ch));
		echo 'Success: Retrieved info for user '.$decoded->resource->name . ', '. $decoded->resource->email;
	}

	 exit;
?>