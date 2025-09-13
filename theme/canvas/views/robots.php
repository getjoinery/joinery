<?php
	// Core files (PathHelper, Globalvars, SessionControl) are guaranteed available
	PathHelper::requireOnce('data/settings_class.php');
	$settings = Globalvars::get_instance();
	
	header('Content-Type: text/plain');
	echo $settings->get_setting('robots_text');
?>