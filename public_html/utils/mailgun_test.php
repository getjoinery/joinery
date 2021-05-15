<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/EmailTemplate.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/email_templates_class.php');

	$settings = Globalvars::get_instance();
	$composer_dir = $settings->get_setting('composerAutoLoad');	
	require $composer_dir.'autoload.php';

	use Mailgun\Mailgun;
	# Instantiate the client.
	
	$mg = Mailgun::create($settings->get_setting('mailgun_api_key'));
	//$mg = new Mailgun($settings->get_setting('mailgun_api_key'), 'https://api.eu.mailgun.net');
	$domain = $settings->get_setting('mailgun_domain');		
	# Make the call to the client.

	$mg->messages()->send($domain, [
	  'from'    => $settings->get_setting('defaultemail'),
	  'to'      => 'jeremy.tunnell@gmail.com',
	  'subject' => 'The PHP SDK is awesome!',
	  'text'    => 'It is so simple to send a message.'
	]);	
	/*
	$result = $mg->sendMessage($domain, array(
		'from'	=> $settings->get_setting('defaultemail'),
		'to'	=> 'jeremy.tunnell@gmail.com',
		'subject' => 'Hello',
		'text'	=> 'Testing some Mailgun awesomness!'
	));
	*/
?>