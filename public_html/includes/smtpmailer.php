<?php
require_once('PHPMailer.php');

class systemmailer extends PHPMailer {
	function __construct() {
		$this->Mailer = 'smtp';
		$this->Host = '';
		$this->Encoding = 'quoted-printable';
		$this->Helo = 'integralzen.org';
		$this->Hostname = 'integralzen.org';
		$this->Sender = 'bounces@integralzen.org';
	}
}

?>
