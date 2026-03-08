<?php
$settings = Globalvars::get_instance();
$siteDir = $settings->get_setting('siteDir');
require_once($siteDir . '/includes/PublicPageFalconHtml5.php');

class PublicPage extends PublicPageFalconHtml5 {
    // Inherits all HTML5 layout from PublicPageFalconHtml5
}
?>
