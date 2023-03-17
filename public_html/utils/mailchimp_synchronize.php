<?php
	error_reporting(E_ERROR | E_PARSE);
	require_once( __DIR__ . '/../includes/Globalvars.php');
	require_once( __DIR__ . '/../includes/EmailTemplate.php');
	require_once( __DIR__ . '/../data/users_class.php');
	require_once( __DIR__ . '/../data/contact_types_class.php');
	
	$settings = Globalvars::get_instance();

	$composer_dir = $settings->get_setting('composerAutoLoad');	
	require $composer_dir.'autoload.php';
	use MailchimpAPI\Mailchimp;

	require_once( __DIR__ . '/../data/event_logs_class.php');
	
	$event_log = new EventLog(NULL);
	$event_log->set('evl_event', 'mailchimp_synchronize');
	$event_log->set('evl_usr_user_id', User::USER_SYSTEM);
	$event_log->save();
	$event_log->load();
	
	$settings = Globalvars::get_instance();

	//GET LIST OF CONTACT TYPES
	$contact_types = new MultiContactType(
		array('deleted'=>false),
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$contact_types->load();
	
	foreach($contact_types as $contact_type){
		if($mailchimp_list_id = $contact_type->get('ctt_mailchimp_list_id')){
			for ($x=0; $x<=10000; $x+=1000){
				echo $x."<br>\n";
				$mailchimp = new Mailchimp($settings->get_setting('mailchimp_api_key'));
				$return = $mailchimp
				->lists($mailchimp_list_id)
				->members()
				->get([
					"count" => "1000", 
					"offset" => $x
				]);
				$results = $return->deserialize();
				if(count($results->members) == 0){
					break;
				}
				foreach ($results->members as $result){ 
					$user = User::GetByEmail($result->email_address);
					if($user){


						
						echo $user->key. ': '.$result->last_changed . ' -- '. $user->get('usr_contact_preference_last_changed');
						
						if($result->status == 'subscribed' && !$user->is_unsubscribed_to_contact_type($contact_type->key)){
							//NO CHANGE	
							echo ' S';
						}
						else if($result->status == 'unsubscribed' && $user->is_unsubscribed_to_contact_type($contact_type->key)){
							//NO CHANGE
							echo ' U';
						}
						else if($result->status == 'subscribed' && $user->is_unsubscribed_to_contact_type($contact_type->key)){
							if(!$user->get('usr_contact_preference_last_changed') || $user->get('usr_contact_preference_last_changed') < $result->last_changed){
								//MAILCHIMP IS MOST RECENT.  UPDATE LOCALLY
								echo ' subscribe locally ';
								$user->subscribe_to_contact_type($contact_type->key);
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
									->lists($mailchimp_list_id)
									->members(md5(strtolower($user->get('usr_email'))))
									->put($post_params);
								
								$status = $return->deserialize();
								echo ' set mailchimp to unsubscribed';
							}
						}
						else if($result->status == 'unsubscribed' && !$user->is_unsubscribed_to_contact_type($contact_type->key)){
							if(!$user->get('usr_contact_preference_last_changed') || $user->get('usr_contact_preference_last_changed') < $result->last_changed){
								//MAILCHIMP IS MOST RECENT.  UPDATE LOCALLY
								$user->unsubscribe_from_contact_type($contact_type->key);
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
									->lists($mailchimp_list_id)
									->members(md5(strtolower($user->get('usr_email'))))
									->put($post_params);
								
								$status = $return->deserialize();
								echo ' set mailchimp to unsubscribed';
							}
						}
						
						echo "<br>\n";
						if($user->get('usr_first_name') && $user->get('usr_last_name') && $user->get('usr_email')){
							$user->save();
						}
					}
				}
			}
		}
	}
	$event_log->set('evl_was_success', 1);
	$event_log->set('evl_note', 'Users processed: '.$x);
	$event_log->save();	
	
	exit();
	?>