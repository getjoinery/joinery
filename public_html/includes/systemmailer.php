<?php

require_once(__DIR__ . '/Globalvars.php');

// Get the composer autoload path from settings
$settings = Globalvars::get_instance();
$composerAutoLoad = $settings->get_setting('composerAutoLoad');

if (!$composerAutoLoad) {
    throw new Exception('composerAutoLoad setting is not configured in the database.');
}

if (!file_exists($composerAutoLoad)) {
    throw new Exception('Composer autoload file not found at configured location: ' . $composerAutoLoad);
}

require_once($composerAutoLoad);

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
