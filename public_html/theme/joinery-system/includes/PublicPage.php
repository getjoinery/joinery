<?php
$settings = Globalvars::get_instance();
$siteDir = $settings->get_setting('siteDir');
require_once($siteDir . '/includes/PublicPageJoinerySystem.php');

class PublicPage extends PublicPageJoinerySystem {
    // Inherits all layout from PublicPageJoinerySystem
}
?>
