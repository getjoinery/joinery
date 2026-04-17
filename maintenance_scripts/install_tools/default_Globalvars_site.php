<?php
//Version 1.05
//SITE: {{SITE_NAME}}

// ============================================================
// IMPORTANT: This file is a BOOTSTRAP-LEVEL infrastructure
// config. It is loaded before the database is available and
// should almost NEVER be changed after initial site setup.
//
// DO NOT use this file to change the active visual theme.
// The active theme is controlled by the 'theme_template'
// setting in the database (stg_settings table), NOT here.
// ============================================================

//SETTINGS
$this->settings['baseDir'] = '/var/www/html/';  //PATH FROM ROOT TO INSTALLATION DIRECTORY (/)
$this->settings['site_template'] = '{{SITE_NAME}}'; //SITE INSTALLATION DIRECTORY NAME — maps to /var/www/html/{site_template}/public_html/. This is NOT the visual theme; it is the site identifier used to locate files and the database. Change only if relocating the entire installation.
$this->settings['webDir'] = '{{DOMAIN_NAME}}';	//DOMAIN NAME OF THE WEBSITE (no protocol, no trailing slash)

//DEFAULT DIRECTORIES — DO NOT CHANGE THESE
$this->settings['siteDir'] = $this->settings['baseDir'] . $this->settings['site_template']. '/public_html';  //PATH FROM COMPUTER ROOT DIRECTORY TO LOCATION OF WEB ROOT (/), LEAVE OFF THE FINAL SLASH
$this->settings['upload_dir'] = $this->settings['baseDir'] . $this->settings['site_template']. '/uploads';  //WHERE UPLOADS ARE STORED
$this->settings['upload_web_dir'] = 'uploads';  //URL AFTER webDir WHERE UPLOADS ARE ACCESSED, NO LEADING OR TRAILING SLASH
$this->settings['static_files_dir'] = $this->settings['baseDir'] . $this->settings['site_template']. '/static_files';  //LOCATION OF STATIC FILES EXAMPLE: /var/www/html/test/static_files
	

$this->settings['dbusername'] = 'postgres';  //DATABASE USERNAME
$this->settings['dbname'] = '{{SITE_NAME}}';  //DATABASE NAME
$this->settings['dbpassword'] = '';  //DATABASE PASSWORD

$this->settings['dbusername_test'] = 'postgres';  //DATABASE USERNAME
$this->settings['dbname_test'] = 'test_{{SITE_NAME}}';  //DATABASE NAME
$this->settings['dbpassword_test'] = '';  //DATABASE PASSWORD


?>