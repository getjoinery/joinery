<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');
	PathHelper::requireOnce('includes/Globalvars.php');
	$settings = Globalvars::get_instance();
	PathHelper::requireOnce('includes/EmailTemplate.php');
	PathHelper::requireOnce('includes/EmailMessage.php');
	PathHelper::requireOnce('includes/EmailSender.php');
	PathHelper::requireOnce('data/email_templates_class.php');
	PathHelper::requireOnce('data/users_class.php');

	if($_REQUEST['password'] != 'testemail'){
		echo 'please enter the password for this utility';
		exit;
	}
	


	$settings = Globalvars::get_instance();
	$composer_dir = $settings->get_setting('composerAutoLoad');	
	require $composer_dir.'autoload.php';

	use Mailgun\Mailgun;
	# Instantiate the client.
	
	if($settings->get_setting('mailgun_version') == 1){
		if($settings->get_setting('mailgun_eu_api_link')){
			$mg = new Mailgun($settings->get_setting('mailgun_api_key'), $settings->get_setting('mailgun_eu_api_link'));
		}
		else{
			$mg = new Mailgun($settings->get_setting('mailgun_api_key'));
		}
	}
	else{
		if($settings->get_setting('mailgun_eu_api_link')){
			$mg = Mailgun::create($settings->get_setting('mailgun_api_key'), $settings->get_setting('mailgun_eu_api_link'));
		}
		else{
			$mg = Mailgun::create($settings->get_setting('mailgun_api_key'));
		}						
	}
	$domain = $settings->get_setting('mailgun_domain');		


	if($settings->get_setting('mailgun_version') == 1){
		$result = $mg->sendMessage($domain, [
		  'from'    => $settings->get_setting('defaultemail'),
		  'to'      => 'jeremy.tunnell@gmail.com',
		  'subject' => 'Test email with direct mailgun',
		  'text'    => 'Direct mailgun sending is working.'
		]);
	}
	else{
		$result = $mg->messages()->send($domain, [
		  'from'    => $settings->get_setting('defaultemail'),
		  'to'      => 'jeremy.tunnell@gmail.com',
		  'subject' => 'Test email with direct mailgun',
		  'text'    => 'Direct mailgun sending is working.'
		]);
	}
				
	print_r($result);


	try {
		$user = new User(1, true);
		
		$message = EmailMessage::fromTemplate('blank_template', [
			'subject' => 'Test email with new system',
			'body' => 'This is the body of the test email.',
			'recipient' => $user->export_as_array()
		]);
		
		$message->to('jeremy.tunnell+3@gmail.com', 'Test User');
		
		$sender = new EmailSender();
		$result = $sender->send($message);
		
		if ($result) {
			echo "Email sent successfully\n";
		} else {
			echo "Email sending failed (queued for retry)\n";
		}
	} catch (Exception $e) {
		echo "Email error: " . $e->getMessage() . "\n";
	}

?>