<?php
$settings = Globalvars::get_instance();
$siteDir = $settings->get_setting('siteDir');
require_once($siteDir . '/includes/PublicPageFalcon.php');

class PublicPage extends PublicPageFalcon {

}

?>
