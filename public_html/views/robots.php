<?php
	require_once(__DIR__ . '/../includes/PathHelper.php');
	PathHelper::requireOnce('/data/settings_class.php');
	$settings = Globalvars::get_instance();
	
header('Content-Type: text/plain');
echo $settings->get_setting('robots_text');


?>
