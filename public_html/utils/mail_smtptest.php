<?php
require_once(__DIR__ . '/../includes/PathHelper.php');
	PathHelper::requireOnce('includes/Globalvars.php');
	$settings = Globalvars::get_instance();
PathHelper::requireOnce('includes/systemmailer.php');

echo 'feature turned off';
exit;

$mail = new systemmailer();


	$mail->From     = 'jeremy@integralzen.org';
	$mail->FromName = 'Jeremy';


	$mail->Subject  = 'yo';;



			$mail->Body = 'hello';;



				$mail->addAddress('jeremy.tunnell@gmail.com', 'Jeremy');




			if(!$mail->send()){
				echo "There has been a mail error sending bulk mail to " . $reciprow->recipient_email . ":" . $mail->ErrorInfo . "<br>";
			}



		// Clear all addresses and attachments for next loop
		$mail->clearAddresses();



echo 'done';

//CLOSE THE SMTP CONNECTION
$mail->smtpClose();


?>


