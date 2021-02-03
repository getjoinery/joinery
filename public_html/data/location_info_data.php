<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

require_once($_SERVER['DOCUMENT_ROOT'] . '/data/address_class.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');

class LocationInfo {

	static function find_location($location=NULL, $addr_id=NULL) {
		$session = SessionControl::get_instance();

		if (!$location && $session->get_location_data()) {
			// If the user didn't put in a new location and we already have location data
			// just use that!
			return TRUE;
		}

		if ($location) {
			// If the user has entered a location, this is always the first thing we check
			// because even logged in users might want to change their location every now and then!
			$cache_result = LibraryFunctions::GetLocationInfoFromCache($location);
			if ($cache_result){
				// If we successfully can load the location information from the cache,
				// we are done, since the above function also updates the session for us
				$session->_set_location_data_array($cache_result);
				return TRUE;
			}

			$bind_params = array();
			$location_data = NULL;
			if (preg_match("/^([0-9]{5})-?[0-9]{0,4}$/", $location, $zipmatches)) {
				// They have entered a zip code
				$location_data = LibraryFunctions::GetLocationData($zipmatches[1]);
			} else if (preg_match("/^([a-zA-Z ]+),\W*([a-zA-Z]+)$/", $location, $city_state_matches)) {
				// They have entered a city/state
				$fixed_city = ucwords(strtolower($city_state_matches[1]));
				$fixed_state = LibraryFunctions::any_state_to_abbr($city_state_matches[2]);
				//$location_data = LibraryFunctions::GetLocationData(NULL, $fixed_city, $fixed_state);
			}

			//if ($location_data) {
			//	$session->_set_location_data_array($location_data);
			//	return TRUE;
			//}

			// Ok, so at this point it wasn't a zip code or city, state... lets check for an address
			/*
			$settings = Globalvars::get_instance();
			$key = $settings->get_setting('GoogleMapAPIKey');
			$url = "http://maps.google.com/maps/geo?sensor=false&q=". urlencode($location) . "&key=" . $key;

			$ch = curl_init();

			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_TIMEOUT, 5000);

			$data = curl_exec($ch);
			curl_close($ch);

			if ($data) {
				$decoded_data = json_decode($data, TRUE);
				if ($decoded_data['Status']['code'] == 200) {
					// First lets get the level of accuracy
					$accuracy = isset($decoded_data['Placemark'][0]['AddressDetails']['Accuracy']) ? $decoded_data['Placemark'][0]['AddressDetails']['Accuracy'] : NULL;

					if (!$accuracy) {
						// There is no accuracy for this placemark, we can't do anything
						return FALSE;
					}

					if ($accuracy < 4) {
						// If the accuracy is less than 4, it means we only have accuracy down
						// to the "sub-region", which is not specific enough for us
						return FALSE;
					}

					if ($decoded_data['Placemark'][0]['AddressDetails']['Country']['CountryNameCode'] !== 'US') {
						// If the country isn't the US... this also is a fail
						return FALSE;
					}

					$point = $decoded_data['Placemark'][0]['Point']['coordinates'];
					$lon = $point[0];
					$lat = $point[1];

					$administrative_area = $decoded_data['Placemark'][0]['AddressDetails']['Country']['AdministrativeArea'];
					// Google doesn't give us the timezone, so we have to figure that out on our own
					if (isset($administrative_area['SubAdministrativeArea']['Locality']['PostalCode']['PostalCodeNumber'])) {
						$loc_data = LibraryFunctions::GetLocationData(
							$administrative_area['SubAdministrativeArea']['Locality']['PostalCode']['PostalCodeNumber']);
						if ($loc_data) {
							$timezone = $loc_data['timezone'];
						} else {
							// This is a bummer, we can't find the timezone from the given zip code
							// we are going to have to go with a default
							$timezone = FALSE;
						}
					} else {
						$city = $administrative_area['SubAdministrativeArea']['Locality']['LocalityName'];
						$state = $administrative_area['AdministrativeAreaName'];

						$loc_data = LibraryFunctions::GetLocationData(NULL, $city, $state);
						if ($loc_data) {
							$timezone = $loc_data['timezone'];
						} else {
							// This is a bummer, we can't find the timezone from the given zip code
							// we are going to have to go with a default
							$timezone = FALSE;
						}
					}

					$project_coords = LibraryFunctions::TransformLatLonToProjected($lat, $lon);
					$session->set_location_data(
						$project_coords[0], $project_coords[1],
						$lat, $lon,
						$decoded_data['Placemark'][0]['address'],
						$timezone);
					return TRUE;
				}
			} else {
				// Could not load the location data from the given location
				// TODO: Do something about this
				return FALSE;
			}
			*/
		}


		if ($session->get_user_id()) {
			// The user is logged in and didn't set a location
			// First try to pull the address that is passed in.
			// If we can't pull that one, fallback to their default
			$user = new User($session->get_user_id(), TRUE);

			$address = NULL;
			if ($addr_id) {
				try {
					$address = new Address($addr_id, TRUE);
					if ($address->get('usa_usr_user_id') !== $session->get_user_id()) {
						$address = NULL;
					}
				}	catch (TTClassException $e) {
					// If there an error reading the address, set it to null and just pull their default
					$address = NULL;
				}
			}

			if ($address === NULL) {
				$address = new Address($user->get_default_address(), TRUE);
			}

			$session->set_location_data(
				$address->get_address_string(', '),
				$address->get('usa_timezone'));
			return TRUE;
		}

		// Now at this point the user hasn't set a location and they aren't logged in
		// So we first will try to pickup their stuff from facebook if they are facebook connected
		// Otherwise we will fallback to trying to pick up info based on their IP

		/*
		$facebook = LibraryFunctions::GetFacebookApi();
		$facebook_user = $facebook->get_loggedin_user();
		if ($facebook_user) {
			// User might be logged in
			try {
				$user_details = $facebook->api_client->users_getInfo($facebook_user, array('current_location'));
				if (is_array($user_details) && isset($user_details[0]) && isset($user_details[0]['current_location'])) {
					$location_data = LibraryFunctions::GetLocationData(
						isset($user_details[0]['current_location']['zip']) ? @$user_details[0]['current_location']['zip'] : NULL,
						isset($user_details[0]['current_location']['city']) ? @$user_details[0]['current_location']['city'] : NULL,
						isset($user_details[0]['current_location']['state']) ? @$user_details[0]['current_location']['state'] : NULL
					);

					if ($location_data) {
						$session->_set_location_data_array($location_data);
						return TRUE;
					} 
				}
			} catch (FacebookRestClientException $e) {
				// We tried and failed to get their facebook information.  Moving along...
			}
		}
		*/

		// Fall back to trying to get information from the IP
		$ip_info = LibraryFunctions::getCityStateFromIP($_SERVER['REMOTE_ADDR']);

		if ($ip_info) {
			list($city, $state) = $ip_info;
			$location_data = LibraryFunctions::GetLocationData(NULL, $city, $state);
			if ($location_data) {
				$session->_set_location_data_array($location_data);
				return TRUE;
			}
		}

		return FALSE;
	}
}

?>
