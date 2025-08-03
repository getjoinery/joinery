<?php

require_once(__DIR__ . '/PathHelper.php');
require_once(PathHelper::getAbsolutePath('vendor/autoload.php'));

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
