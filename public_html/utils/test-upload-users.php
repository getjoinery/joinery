<?php
	require_once('../includes/Globalvars.php');
	$settings = Globalvars::get_instance();
	$siteDir = $settings->get_setting('siteDir');	
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');

require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/data/address_class.php');

echo 'feature turned off';
exit();

$row = 1;
if (($handle = fopen("/var/www/html/uploads/temp/unsubscribed_members_export_343591f125.csv", "r")) !== FALSE) {
    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
        //$num = count($data);
        //echo "<p> $num fields in line $row: <br /></p>\n";
		if($row == 1){
			$row++;
			continue;
		}
        $row++;

        //for ($c=0; $c < $num; $c++) {
          //  echo $data[$c] . "<br />\n";	
        //}
	
		echo '<b>'.$data[1]. ' ' . $data[2] . ' - '  .$data[0]. '</b><br>';
		echo 'signup time: '.$data[7]. '<br>';	
	
		$user = User::GetByEmail(trim($data[0]));
		if($user){
			echo 'FOUND USER<BR>';
			
		}
		else{
			echo 'NEW USER<BR>';
			
			$user = new User(NULL);
			$user->set('usr_email', trim(strtolower($data[0])));
			$user->set('usr_first_name', trim($data[1]));
			$user->set('usr_last_name', trim($data[2]));				
			
			try{
				$user->prepare();
				//$user->save();
				//$user->load();
			} catch (Exception $e) { 
				echo '***************INSERT FAILED  ' . $e->getMessage(); 
				continue;
			}	
			
		}		
		
		$user->set('usr_signup_date', $data[7]);
		$user->set('usr_contact_preferences', 0);
		$user->set('usr_email_is_verified', 1);
		$user->set('usr_email_is_verified_time', $data[7]);
	
		

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		if($data[14]){
			
			if(strtolower($data[14]) == 'uk'){
				$data[14] = 'gb';
			}
			
			//LOOK UP COUNTRY
			$sql = "SELECT cco_country_code_id, cco_code,cco_iso_code_2 FROM cco_country_codes
				WHERE lower(cco_iso_code_2) = :code";

			try{
				$q = $dblink->prepare($sql);
				$q->bindValue(':code', strtolower($data[14]), PDO::PARAM_STR);
				$q->execute();
				$q->setFetchMode(PDO::FETCH_OBJ);
			}
			catch(PDOException $e){
				$dbhelper->handle_query_error($e);
			}

			$r = $q->fetch();

			echo  'COUNTRY '. $r->cco_iso_code_2. '<br>';
			$country_code_id = $r->cco_country_code_id;
			$country_code_2 = $r->cco_code;
			$region = $data[15];
			
			//NOW GET THE TIMEZONE
			//LOOK UP COUNTRY
			/*
			$sql = "SELECT zone_name FROM zone
				WHERE lower(country_code) = :code";

			try{
				$q = $dblink->prepare($sql);
				$q->bindValue(':code', strtolower($country_code_2), PDO::PARAM_STR);
				$q->execute();
				$q->setFetchMode(PDO::FETCH_OBJ);
			}
			catch(PDOException $e){
				$dbhelper->handle_query_error($e);
			}

			$r = $q->fetch();			
			$timezone = $r->zone_name;
			echo 'TIMEZONE: '. $timezone. '<br>';
			$user->set('usr_timezone', $timezone);
			*/
			
			
			
			if($user->address()){
				echo 'HAS ADDRESS<BR>';
			}
			else{
				$address = new Address(NULL);
				$address->set('usa_usr_user_id', $user->key);
				$address->set('usa_cco_country_code_id', $country_code_id);
				$address->set('usa_is_default', TRUE);
				if($country_code_id == 1){
					$address->set('usa_state', strtoupper($region));
				}
				
				print_r($address);
				//$address->save();
			}			
			
		}	
		
		//$user->save();
		//print_r($user);
		
		
		echo '<br><br>';
		
    }
    fclose($handle);
	echo 'finished '. $row;
}
?>