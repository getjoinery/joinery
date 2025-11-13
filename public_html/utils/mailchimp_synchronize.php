<?php
	error_reporting(E_ERROR | E_PARSE);
	set_time_limit(300);
	require_once( __DIR__ . '/../includes/Globalvars.php');
	require_once( __DIR__ . '/../includes/EmailTemplate.php');
	require_once( __DIR__ . '/../data/users_class.php');
	require_once( __DIR__ . '/../data/contact_types_class.php');
	
	$settings = Globalvars::get_instance();
	
	$test = LibraryFunctions::fetch_variable('test', 0,0,'');

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
	$mailing_lists = new MultiMailingList(
		array('deleted'=>false),
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$mailing_lists->load();
	
	foreach($mailing_lists as $mailing_list){
		if($mailchimp_list_id = $mailing_list->get('mlt_mailchimp_list_id')){
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
						$registrant_in_list = $mailing_list->is_user_in_list($user->key);
						$local_change_time = NULL;
						if($registrant_in_list){
							$local_change_time = $registrant_in_list->get('mlr_change_time');
							$local_change_wording = $registrant_in_list->get('mlr_change_time');
						}
						else{
							$local_change_wording = 'Not in local list';
						}
						
						/*
						if(!$local_change_time && $result->last_changed){
							$registrant_in_list->set('mlr_change_time', $result->last_changed);
						}
						*/
						
						echo $user->key. ': Remote:'.$result->last_changed . ' -- Local:'. $local_change_wording.' Result: ';
						
						if($result->status == 'subscribed' && $registrant_in_list){
							//NO CHANGE	
							echo ' NO CHANGE (sub)';
						}
						else if($result->status == 'unsubscribed' && !$registrant_in_list){
							//NO CHANGE
							echo ' NO CHANGE (unsub)';
						}
						else if($result->status == 'subscribed' && !$registrant_in_list){
							if(!$local_change_time || $local_change_time < $result->last_changed){
								//MAILCHIMP IS MOST RECENT.  UPDATE LOCALLY
								echo ' subscribe locally ';
								if(!test){
									$mailing_list->add_registrant($user->key);
								}
							}
							else if ($local_change_time >= $result->last_changed){
								//LOCAL IS MOST RECENT.  UPDATE MAILCHIMP
								if(!test){
									$mailing_list->unsubscribe_from_mailchimp_list($$user->key);
								}
								echo ' set mailchimp to unsubscribed';
							}
						}
						else if($result->status == 'unsubscribed' && $registrant_in_list){
							if(!$local_change_time || $local_change_time < $result->last_changed){
								//MAILCHIMP IS MOST RECENT.  UPDATE LOCALLY
								if(!test){
									$mailing_list->remove_registrant($user->key);
								}
								echo ' unsubscribe locally ';
							}
							else if($local_change_time >= $result->last_changed){
								//LOCAL IS MOST RECENT.  UPDATE MAILCHIMP
								if(!test){
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
								}
								echo ' set mailchimp to subscribed';
							}
						}
						else if($result->status == 'cleaned'){
							if(!test){
								$user->email_unverify_bouncing_user();
							}
						}
						else{
							echo $result->status;
						}
						
						echo "<br>\n";
						if($user->get('usr_first_name') && $user->get('usr_last_name') && $user->get('usr_email')){
							if(!test){
								$user->save();
							}
						}
					}
				}
			}
		}
	}
	$event_log->set('evl_was_success', 1);
	$event_log->set('evl_note', 'Users processed: '.$x);
	$event_log->save();	
	echo 'SYNC COMPLETED<BR>';
	
	exit();
	?>