<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/settings_class.php');
	$settings = Globalvars::get_instance();
	
header('Content-Type: text/plain');
echo $settings->get_setting('robots_text');

/*
Sample robots.txt file
User-agent: *
Disallow: /admin/
Disallow: /ajax/
Disallow: /analytics/
Disallow: /data/
Disallow: /logic/
Disallow: /includes/
Disallow: /page_scripts/
Disallow: /phpincludes/
Disallow: /profile/
Disallow: /template/
Disallow: /test/
Disallow: /theme/
Disallow: /utils/
Disallow: /wp-content/
Disallow: /uploads/
*/
?>
