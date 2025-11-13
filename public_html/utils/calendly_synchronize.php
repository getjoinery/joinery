<?php
	error_reporting(E_ERROR | E_PARSE);
	require_once( __DIR__ . '/../includes/Globalvars.php');

	require_once( __DIR__ . '/../includes/SessionControl.php');
	require_once( __DIR__ . '/../includes/PathHelper.php');

	require_once( __DIR__ . '/../data/users_class.php');
	require_once(PathHelper::getIncludePath('plugins/bookings/data/bookings_class.php'));
	require_once(PathHelper::getIncludePath('plugins/bookings/data/booking_types_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);

	// TEMPORARILY DISABLED - Calendly integration under review
	echo 'Calendly synchronization temporarily disabled';
	exit;

	$event_uri = LibraryFunctions::fetch_variable('event_uri', NULL,0,'');
	$min_start_time = LibraryFunctions::fetch_variable('min_start_time', NULL,0,'');

/*
	$organization = get_organization_for_user('https://api.calendly.com/users/FGCFC77JIDEIANP3');
	print_r($organization);
	exit;
*/

	//HANDLE THE EVENT TYPES
	$event_types = get_event_types_info();
	foreach($event_types as $event_type){
		//print_r($event_type);
		echo $event_type->name.'<br>';
		echo $event_type->uri.'<br>';

		if(!$new_booking = BookingType::GetByCalendlyUri($event_type->uri)){
			$new_booking = new BookingType(NULL);
			$new_booking->set('bkt_calendly_event_type_uri', $event_type->uri);
		}

		if(!$new_booking->get('bkt_created_at')){
			$new_booking->set('bkt_created_at', $event_type->created_at);
		}
		$new_booking->set('bkt_update_time', $event_type->updated_at);
		//echo basename($booking->event_memberships[0]->user).'<br>';
		$new_booking->set('bkt_name', $event_type->name);
		$new_booking->set('bkt_description_html', $event_type->description_html);
		$new_booking->set('bkt_description_plain', $event_type->description_plain);
		$new_booking->set('bkt_schedule_link', $event_type->scheduling_url);
		$new_booking->set('bkt_slug', $event_type->slug);

		if($event_type->active == 1){
			$new_booking->set('bkt_status', BookingType::BOOKING_STATUS_ACTIVE);
		}
		else if(!$new_booking->get('bkn_status')){
			$new_booking->set('bkt_status', BookingType::BOOKING_STATUS_INACTIVE);
		}
		echo $event_type->status.'<br>';

		//OWNER
		if(!$user = User::GetByCalendlyUri($event_type->profile->owner)){
			echo "Error: There is no user in the system with the calendly uri of ". $event_type->profile->owner;
			exit;
		}
		else{
			$new_booking->set('bkt_usr_user_id', $user->key);
		}

		$new_booking->prepare();
		$new_booking->save();

	}

	//NOW HANDLE THE EVENTS
	if($event_uri){
		$results = get_booking_info($event_uri, $min_start_time, NULL);
		print_r($results);

		$results = get_booking_invitees($event_uri, NULL);
		print_r($results);
	}
	else{
		$bookings = get_booking_info(NULL, $min_start_time, NULL);
		foreach($bookings as $booking){
			//print_r($booking);
			echo $booking->name.'<br>';
			echo $booking->uri.'<br>';

			if(!$new_booking = Booking::GetByCalendlyUri($booking->uri)){
				$new_booking = new Booking(NULL);
				$new_booking->set('bkn_calendly_event_uri', $booking->uri);
			}

			if(!$new_booking->get('bkn_created_at')){
				$new_booking->set('bkn_created_at', $booking->created_at);
			}
			echo $booking->start_time.'<br>';
			$new_booking->set('bkn_start_time', $booking->start_time);
			echo $booking->end_time.'<br>';
			$new_booking->set('bkn_end_time', $booking->end_time);
			$new_booking->set('bkn_update_time', $booking->updated_at);
			echo $booking->event_memberships[0]->user.'<br>';
			$new_booking->set('bkn_location', $booking->location->location);

			$new_booking->set('bkn_type', $booking->event_type);
			$booking_type = BookingType::GetByCalendlyUri($booking->event_type);
			$new_booking->set('bkn_bkt_booking_type_id', $booking_type->key);

			if($user = User::GetByCalendlyUri($booking->event_memberships[0]->user)){
				$new_booking->set('bkn_usr_user_id_booked', $user->key);
			}

			if($booking->status == 'canceled'){
				$new_booking->set('bkn_status', Booking::BOOKING_STATUS_CANCELED);
			}
			else if(!$new_booking->get('bkn_status')){
				$new_booking->set('bkn_status', Booking::BOOKING_STATUS_BOOKED);
			}
			echo $booking->status.'<br>';

			if($booking->status == 'active'){
				$invitees = get_booking_invitees(basename($booking->uri), 'active');
			}
			else{
				$invitees = get_booking_invitees(basename($booking->uri), 'canceled');
			}

			//TODO: HANDLE MORE THAN ONE INVITEE
			foreach($invitees as $invitee){
				echo $invitee->name.'<br>';
				echo $invitee->email.'<br>';
				echo $invitee->tracking->salesforce_uuid.'<br>';
				$new_booking->set('bkn_cancel_link', $invitee->cancel_url);
				$new_booking->set('bkn_reschedule_link', $invitee->reschedule_url);
				if($user = User::GetByEmail($invitee->email)){
					$new_booking->set('bkn_usr_user_id_client', $user->key);
				}
				else if($invitee->tracking->salesforce_uuid){
					$new_booking->set('bkn_usr_user_id_client', $invitee->tracking->salesforce_uuid);
				}
				else{
					//CREATE NEW USER
					$data = array(
						'usr_first_name' => $invitee->first_name,
						'usr_last_name' => $invitee->last_name,
						'usr_email' => $invitee->email,
						'password' => NULL,
						'send_emails' => false
					);
					$user = User::CreateNew($data);
				}
			}
			$new_booking->prepare();
			$new_booking->save();

		}
	}

	function get_event_types_info($event_uri=NULL, $min_start_time=NULL, $status='active'){
		$settings = Globalvars::get_instance();

		if($event_uri){
			$extra_params = '/'.$event_uri;
		}
		else{
			$extra_params = '';
		}

		$min_start_time_parameter = '';
		if($min_start_time){
			$min_start_time_parameter = '&min_start_time='.$min_start_time;
		}

		if($status){
			$url = 'https://api.calendly.com/event_types'.$extra_params.'?organization='.$settings->get_setting('calendly_organization_uri').'&status='.$status.'&count=100'.$min_start_time_parameter;
		}
		else{
			$url = 'https://api.calendly.com/event_types'.$extra_params.'?organization='.$settings->get_setting('calendly_organization_uri').'&count=100'.$min_start_time_parameter;
		}

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

		if($event_uri){
			return $result->resource;
		}
		else{
			return $result->collection;
		}

	}

	function get_organization_for_user($user_uri){
		$settings = Globalvars::get_instance();

		$url = 'https://api.calendly.com/organization_memberships'.$extra_params.'?user='.$user_uri;

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

	function get_booking_info($event_uri=NULL, $min_start_time=NULL, $status='active'){
		$settings = Globalvars::get_instance();

		if($event_uri){
			$extra_params = '/'.$event_uri;
		}
		else{
			$extra_params = '';
		}

		$min_start_time_parameter = '';
		if($min_start_time){
			$min_start_time_parameter = '&min_start_time='.$min_start_time;
		}

		if($status){
			$url = 'https://api.calendly.com/scheduled_events'.$extra_params.'?organization='.$settings->get_setting('calendly_organization_uri').'&status='.$status.'&count=100'.$min_start_time_parameter;
		}
		else{
			$url = 'https://api.calendly.com/scheduled_events'.$extra_params.'?organization='.$settings->get_setting('calendly_organization_uri').'&count=100'.$min_start_time_parameter;
		}

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

		if($event_uri){
			return $result->resource;
		}
		else{
			return $result->collection;
		}

	}

	function get_booking_invitees($event_uri, $status='active'){
		$settings = Globalvars::get_instance();

		if($event_uri){
			$extra_params = '/'.$event_uri.'/invitees/';
		}
		else{
			$extra_params = '';
		}

		if($status){
			$url = 'https://api.calendly.com/scheduled_events'.$extra_params.'?organization='.$settings->get_setting('calendly_organization_uri').'&status='.$status.'&count=100';
		}
		else{
			$url = 'https://api.calendly.com/scheduled_events'.$extra_params.'?organization='.$settings->get_setting('calendly_organization_uri').'&count=100';
		}

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
