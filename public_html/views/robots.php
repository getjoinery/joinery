<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/settings_class.php');
	$settings = Globalvars::get_instance();
	
header('Content-Type: text/plain');
echo $settings->get_setting('robots_text');


?>
