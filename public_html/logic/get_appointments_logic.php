<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Activation.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AcuityScheduling.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPageTW.php', '/includes'));	
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	

	$session = SessionControl::get_instance();	
	$session->check_permission(0);
	
	$user = new User($session->get_user_id(), TRUE);
	
	$page = new PublicPageTW();

	try{
		$settings = Globalvars::get_instance();
		$acuity = new AcuityScheduling(array(
		  'userId' => $settings->get_setting('acuity_user_id'),
		  'apiKey' => $settings->get_setting('acuity_api_key')
		));
		$dt_now = new DateTime();
		
		$endpoint = '/appointments?email='. $user->get('usr_email').'&minDate='.$dt_now->format('Y-m-d');
		$appointments = $acuity->request($endpoint);

		if($appointments['status_code']){
			if($appointments['status_code'] != '401'){
				foreach($appointments as $appointment){
					$dt = new DateTime($appointment['datetime']);
					if($dt > $dt_now){	
						$rowvalues=array();
						$appt_link = '<a href="'.$appointment['confirmationPage'].'">'.$appointment['calendar'] . ' (' . $appointment['type'] . ') </a>';
						if($appointment['location']){
							$appt_link .= ' Meeting link: <a href="'.$appointment['location'].'">'.$appointment['location'].'</a>';
						}
						array_push($rowvalues, $appt_link);
						
						array_push($rowvalues, LibraryFunctions::convert_time($dt->format('M j, Y g:i a'), $dt->format('T'), $session->get_timezone()));
						$page->disprow($rowvalues);
					}
				}	
				$page->endtable();	
			}
			else{
				echo 'No appointments found.';
			}
		}	
		else{
			echo 'No appointments found.';
		}
	}
	catch(Exception $e){
		echo 'No appointments found.'; 
	}	
	
	?>