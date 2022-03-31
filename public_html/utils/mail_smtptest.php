<?php
	require_once('../includes/Globalvars.php');
	$settings = Globalvars::get_instance();
	$siteDir = $settings->get_setting('siteDir');	
require_once("../includes/systemmailer.php");

echo 'feature turned off';
exit;

$mail = new systemmailer();


	$mail->From     = 'jeremy@integralzen.org';
	$mail->FromName = 'Jeremy';


	$mail->Subject  = 'yo';;



			$mail->Body = 'hello';;



				$mail->AddAddress('jeremy.tunnell@gmail.com', 'Jeremy');




			if(!$mail->Send()){
				echo "There has been a mail error sending bulk mail to " . $reciprow->recipient_email . ":" . $mail->ErrorInfo . "<br>";
			}



		// Clear all addresses and attachments for next loop
		$mail->ClearAddresses();



echo 'done';

//CLOSE THE SMTP CONNECTION
$mail->SmtpClose();


?>


