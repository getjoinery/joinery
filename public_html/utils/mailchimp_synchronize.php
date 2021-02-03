<?php

	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/EmailTemplate.php');

	$settings = Globalvars::get_instance();
	$composer_dir = $settings->get_setting('composerAutoLoad');	
	require $composer_dir.'autoload.php';
	use MailchimpAPI\Mailchimp;

	echo 'turned off';
	exit();


	$session = SessionControl::get_instance();
	//$session->check_permission(0);
	$session_id = $_GET['session_id']; 
	

	
	
	$settings = Globalvars::get_instance();

	for ($x=0; $x<=2700; $x+=50){
		echo $x.'<br>';
		$mailchimp = new Mailchimp($settings->get_setting('mailchimp_api_key'));
		$return = $mailchimp
		->lists($settings->get_setting('mailchimp_list_id'))
		->members()
		->get([
			"count" => "50", 
			"offset" => $x
		]);
		$results = $return->deserialize();
		foreach ($results->members as $result){ 
			$user = User::GetByEmail($result->email_address);
			if($user){
				if($result->status == 'subscribed'){
					$user->set('usr_contact_preferences', 1);
					$user->save();
				}
				else{
					$user->set('usr_contact_preferences', 0);
					$user->save();			
				}
			}
		}
	}
	exit();
	?>