<?php
	require_once('../includes/Globalvars.php');
	$settings = Globalvars::get_instance();
	$baseDir = $settings->get_setting('baseDir');
	$site_template = $settings->get_setting('site_template');
	$full_site_dir = $baseDir.$site_template;
	
	$sourceFile     = 'https://jeremytunnell.com/static_files/current_upgrade.zip';
	$target_location = $full_site_dir.'/uploads/current_upgrade.zip';
	$stage_location = $full_site_dir.'/uploads/upgrades/';
	$live_directory = $full_site_dir. 'public_html';
	$backup_directory = $full_site_dir. 'public_html_last';
	$stage_directory = $stage_location. 'public_html_stage';
	$theme_directory = $full_site_dir.'/theme';
	
	//GET THE UPGRADE FILE
			echo 'Getting: '. $sourceFile.'<br>';;
            if(saveFileByUrl($sourceFile, $target_location)){
				echo 'Upgrade downloaded...<br>';
			}
			else{
				echo 'Unable to download upgrade...<br>';
				exit;
			}
			chmod($target_location, 0777);

	//CLEAR OLD STAGED FILES 
	exec ("rm -rf $stage_location");
	
			//$result = array();
			//system("unzip $target_location $stage_location");
 
			$zip = new ZipArchive;
			if ($zip->open($target_location)){
			  $zip->extractTo($stage_location);
			  $zip->close();
			  echo 'Upgrade unzipped...<br>';
			} 
			else {
			  echo 'Unable to unzip upgrade<br>';
			  exit;
			}			
			
			//TODO: DO BACKUPS
			
			
			//TODO:  THIS IS A HACK, FIX IT
			rename($stage_location.'var/www/html/jeremytunnell/public_html_stage', $stage_directory);
			
			//COPY THE THEME FILES 
			exec("cp -r $theme_directory $stage_directory");
			
			//RUN THE DEPLOY
				//CLEAR OLD STAGED FILES 
			exec ("rm -rf $backup_directory");
			rename($live_directory, $backup_directory);
			rename($stage_directory, $live_directory);
			
			//DO THE MIGRATION
			require_once($_SERVER['DOCUMENT_ROOT'].'/utils/update_database.php');
			
			
			
			//UNTAR IT 
			/*
			try {
				$phar = new PharData($targetLocation);
				$phar->extractTo($stage_location); // extract all files
			} catch (Exception $e) {
				print_r($e);
			}	
			*/		

			
		

            function saveFileByUrl ( $source, $destination ) {
 
                   return file_put_contents($destination, file_get_contents($source));
                
            }

			function deleteDir($dirPath) {
				if (! is_dir($dirPath)) {
					throw new InvalidArgumentException("$dirPath must be a directory");
				}
				if (substr($dirPath, strlen($dirPath) - 1, 1) != '/') {
					$dirPath .= '/';
				}
				$files = glob($dirPath . '*', GLOB_MARK);
				foreach ($files as $file) {
					if (is_dir($file)) {
						deleteDir($file);
					} else {
						unlink($file);
					}
				}
				rmdir($dirPath);
			}
			
			function deleteallfiles($directory){
					$files = glob($directory); // get all file names
				foreach($files as $file){ // iterate files
				  if(is_file($file)) {
					unlink($file); // delete file
				  }
				}			
			}


 /*
 deploy_directory="/var/www/html/$1"

if [[ ! -d $deploy_directory ]]
then
    echo "Deploy directory $deploy_directory does not exist."
fi

rm -rf /var/www/html/$1/public_html_stage
mkdir /var/www/html/$1/public_html_stage
git init /var/www/html/$1/public_html_stage
cd /var/www/html/$1/public_html_stage
echo "Enter git credentials."
if ! git remote add origin https://github.com/Tunnell-Software/membership.git
then
git remote set-url origin https://github.com/Tunnell-Software/membership.git
fi
git pull origin main
rm -rf /var/www/html/$1/public_html_last
mv /var/www/html/$1/public_html /var/www/html/$1/public_html_last
mv /var/www/html/$1/public_html_stage /var/www/html/$1/public_html

if ! /usr/bin/php /var/www/html/$1/public_html/utils/update_database.php
then
echo "ERROR: Database update failed.  Reverting deploy"
mv /var/www/html/$1/public_html_last /var/www/html/$1/public_html
exit 1
fi

echo 'Deploy to' $1 'complete'
*/

	/*
	require_once('../includes/Globalvars.php');
	$settings = Globalvars::get_instance();
	$siteDir = $settings->get_setting('siteDir');	
	require_once($siteDir . '/includes/ErrorHandler.php');
	require_once($siteDir . '/includes/LibraryFunctions.php');
	require_once($siteDir . '/includes/SessionControl.php');

	require_once($siteDir . '/data/users_class.php');
	require_once($siteDir . '/data/bookings_class.php');

	$settings = Globalvars::get_instance();
	$composer_dir = $settings->get_setting('composerAutoLoad');	
	require_once $composer_dir.'autoload.php';	

	$session = SessionControl::get_instance();
	$session->check_permission(10);

	$ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, 'https://api.calendly.com/users/me');

    $headers = array(
    'authorization: Bearer '.$settings->get_setting('calendly_api_token'),
    );
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	
    curl_setopt($ch, CURLOPT_HEADER, 0);
    $body = '{}';

    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET"); 
    curl_setopt($ch, CURLOPT_POSTFIELDS,$body);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // Timeout in seconds
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

	$response = curl_exec($ch);
	
	if (curl_errno($response)) {
		echo 'Error:  ' . curl_errno($response);
	}
	else if ($http_code = curl_getinfo($ch , CURLINFO_HTTP_CODE) != 200){
		echo 'Error:  Http code ' . $http_code;
	}
	else{
		$decoded = json_decode(curl_exec($ch));
		echo 'Success: Retrieved info for user '.$decoded->resource->name . ', '. $decoded->resource->email;
	}
		

	 exit;
	 */
?>