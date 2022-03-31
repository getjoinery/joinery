<?php
	require_once('../includes/Globalvars.php');
	$settings = Globalvars::get_instance();
	$siteDir = $settings->get_setting('siteDir');	
	require_once($siteDir . '/includes/EmailTemplate.php');
	require_once($siteDir . '/data/email_templates_class.php');
	require_once($siteDir . '/data/users_class.php');

	if($_REQUEST['password'] != 'testemail'){
		echo 'bad password';
		exit;
	}
	
	echo 'feature turned off';
	exit;

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


	$user = new User(1, true);

	$email_template = new EmailTemplate('blank_template', $user);		
	$email_template->fill_template(array(
			'subject' => 'Test email with emailTemplate',
			'body' => 'emailTemplate sending is working.',
	));
	$email_template->email_subject = 'Test email with direct mailgun';
	$email_template->email_from = $settings->get_setting('defaultemail');
	$result = $email_template->send(FALSE);

	echo 'emailTemplate email sent';

?>