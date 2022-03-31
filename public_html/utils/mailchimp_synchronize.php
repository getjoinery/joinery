<?php
	require_once('../includes/Globalvars.php');
	$settings = Globalvars::get_instance();
	$siteDir = $settings->get_setting('siteDir');
	require_once($siteDir . '/includes/EmailTemplate.php');
	require_once($siteDir . '/data/users_class.php');

	$composer_dir = $settings->get_setting('composerAutoLoad');	
	require $composer_dir.'autoload.php';
	use MailchimpAPI\Mailchimp;

	
	$settings = Globalvars::get_instance();

	for ($x=0; $x<=10000; $x+=1000){
		echo $x.'<br>';
		$mailchimp = new Mailchimp($settings->get_setting('mailchimp_api_key'));
		$return = $mailchimp
		->lists($settings->get_setting('mailchimp_list_id'))
		->members()
		->get([
			"count" => "1000", 
			"offset" => $x
		]);
		$results = $return->deserialize();
		if(count($results->members) == 0){
			echo 'DONE';
			exit;
		}
		foreach ($results->members as $result){ 
			$user = User::GetByEmail($result->email_address);
			if($user){
				echo $user->key. ': '.$result->last_changed . ' -- '. $user->get('usr_contact_preference_last_changed');
				
				if($result->status == 'subscribed' && $user->get('usr_contact_preferences') == 1){
					//NO CHANGE	
					echo ' S';
				}
				else if($result->status == 'unsubscribed' && $user->get('usr_contact_preferences') == 0){
					//NO CHANGE
					echo ' U';
				}
				else if($result->status == 'subscribed' && $user->get('usr_contact_preferences') == 0){
					if(!$user->get('usr_contact_preference_last_changed') || $user->get('usr_contact_preference_last_changed') < $result->last_changed){
						//MAILCHIMP IS MOST RECENT.  UPDATE LOCALLY
						echo ' subscribe locally ';
						$user->set('usr_contact_preferences', 1);
						$user->set('usr_contact_preference_last_changed', $result->last_changed);
					}
					else if ($user->get('usr_contact_preference_last_changed') >= $result->last_changed){
						//LOCAL IS MOST RECENT.  UPDATE MAILCHIMP
						$merge_values = [
							"FNAME" => $user->get('usr_first_name'),
							"LNAME" => $user->get('usr_last_name'),
							"MMERGE3" => 'Yes',
						];

						$post_params = [
							"status" => "unsubscribed", 
							"merge_fields" => $merge_values,
						];
						$return = $mailchimp
							->lists($settings->get_setting('mailchimp_list_id'))
							->members(md5(strtolower($user->get('usr_email'))))
							->put($post_params);
						
						$status = $return->deserialize();
						echo ' set mailchimp to unsubscribed';
					}
				}
				else if($result->status == 'unsubscribed' && $user->get('usr_contact_preferences') == 1){
					if(!$user->get('usr_contact_preference_last_changed') || $user->get('usr_contact_preference_last_changed') < $result->last_changed){
						//MAILCHIMP IS MOST RECENT.  UPDATE LOCALLY
						$user->set('usr_contact_preferences', 0);
						$user->set('usr_contact_preference_last_changed', $result->last_changed);
						echo ' unsubscribe locally ';
					}
					else if($user->get('usr_contact_preference_last_changed') >= $result->last_changed){
						//LOCAL IS MOST RECENT.  UPDATE MAILCHIMP
						$merge_values = [
							"FNAME" => $user->get('usr_first_name'),
							"LNAME" => $user->get('usr_last_name'),
							"MMERGE3" => 'Yes',
						];

						$post_params = [
							"status" => "pending", 
							"merge_fields" => $merge_values,
						];
						$return = $mailchimp
							->lists($settings->get_setting('mailchimp_list_id'))
							->members(md5(strtolower($user->get('usr_email'))))
							->put($post_params);
						
						$status = $return->deserialize();
						echo ' set mailchimp to unsubscribed';
					}
				}
				echo '<br>';
				if($user->get('usr_first_name') && $user->get('usr_last_name') && $user->get('usr_email')){
					$user->save();
				}
			}
		}
	}
	
	exit();
	?>