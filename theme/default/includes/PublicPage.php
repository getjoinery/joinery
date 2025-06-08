<?php
$settings = Globalvars::get_instance();
$siteDir = $settings->get_setting('siteDir');
require_once($siteDir . '/includes/PublicPageTailwind.php');

class PublicPage extends PublicPageTailwind {

}

?>
