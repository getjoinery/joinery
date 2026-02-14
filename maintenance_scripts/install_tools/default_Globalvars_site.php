<?php
//Version 1.05
//SITE: {{SITE_NAME}}

//SETTINGS
$this->settings['baseDir'] = '/var/www/html/';  //PATH FROM ROOT TO INSTALLATION DIRECTORY (/)
$this->settings['site_template'] = '{{SITE_NAME}}'; //ACTIVE SITE TEMPLATE.  "default" OR THE DIRECTORY OF YOUR SITE TEMPLATE, NO LEADING OR FOLLOWING SLASH
$this->settings['webDir'] = '{{DOMAIN_NAME}}';	//DOMAIN NAME OF THE WEBSITE (no protocol, no trailing slash)

//DEFAULT DIRECTORIES, GENERALLY LEAVE THESE ALONE
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