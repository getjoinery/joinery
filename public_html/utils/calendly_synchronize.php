<?php
	error_reporting(E_ERROR | E_PARSE);
	require_once( __DIR__ . '/../includes/Globalvars.php');	
	require_once( __DIR__ . '/../includes/ErrorHandler.php');
	require_once( __DIR__ . '/../includes/LibraryFunctions.php');
	require_once( __DIR__ . '/../includes/SessionControl.php');

	require_once( __DIR__ . '/../data/users_class.php');
	require_once( __DIR__ . '/../data/bookings_class.php');


	$event_uuid = LibraryFunctions::fetch_variable('event_uuid', NULL,0,'');
	$min_start_time = LibraryFunctions::fetch_variable('min_start_time', NULL,0,'');

	if($event_uuid){
		$results = get_booking_info($event_uuid, $min_start_time);
		print_r($results);
		
		$results = get_booking_invitees($event_uuid);
		print_r($results);
	}
	else{
		$bookings = get_booking_info(NULL, $min_start_time);
		foreach($bookings as $booking){
			echo $booking->name.'<br>';
			echo basename($booking->uri).'<br>';
			
			if(!$new_booking = Booking::get_by_calendly_uuid(basename($booking->uri))){
				$new_booking = new Booking(NULL);
				$new_booking->set('bkn_calendly_event_uuid', basename($booking->uri));
			}
			
			if(!$new_booking->get('bkn_created_at')){
				$new_booking->set('bkn_created_at', $booking->created_at);
			}
			echo $booking->start_time.'<br>';
			$new_booking->set('bkn_start_time', $booking->start_time);
			echo $booking->end_time.'<br>';
			$new_booking->set('bkn_end_time', $booking->end_time);
			echo basename($booking->event_memberships[0]->user).'<br>';
			$new_booking->set('bkn_location', $booking->location->location);
			
			if($booking->status == 'canceled'){
				$new_booking->set('bkn_status', Booking::BOOKING_STATUS_CANCELED);
			}
			else if(!$new_booking->get('bkn_status')){
				$new_booking->set('bkn_status', Booking::BOOKING_STATUS_BOOKED);
			}

			$invitees = get_booking_invitees(basename($booking->uri));
			//TODO: HANDLE MORE THAN ONE INVITEE
			foreach($invitees as $invitee){
				echo $invitee->name.'<br>';
				echo $invitee->email.'<br>';
				echo $invitee->tracking->salesforce_uuid.'<br>';
				$new_booking->set('bkn_cancel_link', $invitee->cancel_url);
				$new_booking->set('bkn_reschedule_link', $invitee->reschedule_url);
				if($invitee->tracking->salesforce_uuid){
					$new_booking->set('bkn_usr_user_id_client', $invitee->tracking->salesforce_uuid);
				}
				else if($user = User::GetByEmail($invitee->email)){
					$new_booking->set('bkn_usr_user_id_client', $user->key);
				}
				else{
					//TODO: DECIDE WHETHER TO CREATE NEW USERS
				}
			}
			$new_booking->prepare();
			$new_booking->save();
			
		}
	}

	
	function get_booking_info($event_uuid=NULL, $min_start_time=NULL, $status='active'){
		$settings = Globalvars::get_instance();
			
		if($event_uuid){
			$extra_params = '/'.$event_uuid;
		}
		else{
			$extra_params = '';
		}
		
		$min_start_time_parameter = '';
		if($min_start_time){
			$min_start_time_parameter = '&min_start_time='.$min_start_time;
		}
		
		$url = 'https://api.calendly.com/scheduled_events'.$extra_params.'?organization='.$settings->get_setting('calendly_organization_uri').'&status='.$status.'&count=100'.$min_start_time_parameter;

		$ch = curl_init();

		curl_setopt($ch, CURLOPT_URL, $url);

		$headers = array(
		'authorization: Bearer '.$settings->get_setting('calendly_api_token'),
		);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET"); 
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		// Timeout in seconds
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);

		$result = json_decode(curl_exec($ch));

		if($event_uuid){
			return $result->resource;
		}
		else{
			return $result->collection;
		}

	}
	
	function get_booking_invitees($event_uuid, $status='active'){
		$settings = Globalvars::get_instance();
			
		if($event_uuid){
			$extra_params = '/'.$event_uuid.'/invitees/';
		}
		else{
			$extra_params = '';
		}
		$url = 'https://api.calendly.com/scheduled_events'.$extra_params.'?organization='.$settings->get_setting('calendly_organization_uri').'&status='.$status.'&count=100';

		$ch = curl_init();

		curl_setopt($ch, CURLOPT_URL, $url);

		$headers = array(
		'authorization: Bearer '.$settings->get_setting('calendly_api_token'),
		);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET"); 
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		// Timeout in seconds
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);

		$result = json_decode(curl_exec($ch));

		return $result->collection;
		
	}
	
	exit;

?>
