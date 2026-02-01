<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');
	require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
	$settings = Globalvars::get_instance();
	require_once(PathHelper::getIncludePath('includes/EmailTemplate.php'));
	require_once(PathHelper::getIncludePath('includes/EmailMessage.php'));
	require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
	require_once(PathHelper::getIncludePath('data/email_templates_class.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	if($_REQUEST['password'] != 'testemail'){
		echo 'please enter the password for this utility';
		exit;
	}
	


	require_once(PathHelper::getComposerAutoloadPath());

	use Mailgun\Mailgun;

	// Instantiate the Mailgun client (v3.x SDK)
	if ($settings->get_setting('mailgun_eu_api_link')) {
		$mg = Mailgun::create($settings->get_setting('mailgun_api_key'), $settings->get_setting('mailgun_eu_api_link'));
	} else {
		$mg = Mailgun::create($settings->get_setting('mailgun_api_key'));
	}

	$domain = $settings->get_setting('mailgun_domain');

	$result = $mg->messages()->send($domain, [
		'from'    => $settings->get_setting('defaultemail'),
		'to'      => $settings->get_setting('webmaster_email'),
		'subject' => 'Test email with direct mailgun',
		'text'    => 'Direct mailgun sending is working.'
	]);
				
	print_r($result);


	try {
		$user = new User(1, true);
		
		$message = EmailMessage::fromTemplate('blank_template', [
			'subject' => 'Test email with new system',
			'body' => 'This is the body of the test email.',
			'recipient' => $user->export_as_array()
		]);
		
		$message->to($settings->get_setting('webmaster_email'), 'Test User');
		
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