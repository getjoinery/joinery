<?php

require_once(__DIR__ . '/Globalvars.php');

// Get the composer directory from settings
$settings = Globalvars::get_instance();
$composer_dir = $settings->get_setting('composerAutoLoad');

if (!$composer_dir) {
    throw new Exception('composerAutoLoad setting is not configured in the database.');
}

// The setting contains the vendor directory path, we need to append autoload.php
$autoload_path = $composer_dir . 'autoload.php';

if (!file_exists($autoload_path)) {
    throw new Exception('Composer autoload.php not found at: ' . $autoload_path);
}

require_once($autoload_path);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

class systemmailer extends PHPMailer {
	function __construct() {
		$this->isSMTP();
		$this->Host = '64.77.41.226';
		$this->Encoding = 'quoted-printable';
		$this->Helo = 'integralzen.org';
		$this->Hostname = 'integralzen.org';
		$this->Sender = 'bounces@integralzen.org';
	}
}

?>
