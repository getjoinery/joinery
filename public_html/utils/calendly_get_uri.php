<?php
require_once(__DIR__ . '/../includes/PathHelper.php');
	PathHelper::requireOnce('includes/Globalvars.php');
	$settings = Globalvars::get_instance();

	PathHelper::requireOnce('includes/SessionControl.php');

	PathHelper::requireOnce('data/users_class.php');
	require_once(PathHelper::getIncludePath('plugins/bookings/data/bookings_class.php'));

	echo 'feature turned off';
	exit;

	$session = SessionControl::get_instance();
	$settings = Globalvars::get_instance();

	$session->check_permission(10);

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

    $authToken = json_decode(curl_exec($ch));

    echo '<p>Organization URI: '. $authToken->resource->current_organization.'</p>';
	echo '<p>User URI: '. $authToken->resource->uri.'</p>';;

	 exit;
?>