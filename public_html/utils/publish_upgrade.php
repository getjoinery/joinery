<?php
	require_once( __DIR__ . '/../includes/Globalvars.php');
	require_once( __DIR__ . '/../includes/SessionControl.php');
	require_once( __DIR__ . '/../includes/AdminPage-uikit3.php');
	require_once( __DIR__ . '/../includes/FormWriterMaster.php');
	require_once( __DIR__ . '/../includes/LibraryFunctions.php');
	require_once( __DIR__ . '/../data/upgrades_class.php');

	$settings = Globalvars::get_instance();
	$baseDir = $settings->get_setting('baseDir');
	$site_template = $settings->get_setting('site_template');
	$full_site_dir = $baseDir.$site_template;
	
	//IF WE ARE ACTING AS A SERVER
	if(!$settings->get_setting('upgrade_server_active')){
		echo 'Setting is turned off.';
		exit;		
	}
	
	$session = SessionControl::get_instance();
	$session->check_permission(8);
	
	if(isset($_REQUEST['version_major']) && isset($_REQUEST['version_minor'])){
	
		$version_major = $_REQUEST['version_major'];
		$version_minor = $_REQUEST['version_minor'];

		$filename = 'current_upgrade'.$version_major.'-'.$version_minor.'.upg.zip';
		
		$file_output_folder = $full_site_dir.'/static_files';
		$file_output_location = $full_site_dir.'/static_files/'.$filename;

		//CHECK ALL FILE Permissions and owners
		if(substr(sprintf('%o', fileperms($file_output_folder)), -3) != '770'){
			echo $file_output_folder . ' must have permissions of 770.  Aborting upgrade.<br>';
			echo 'Instead, it is owned by '.posix_getpwuid(fileowner($file_output_folder))['name'].' and has permissions '.substr(sprintf('%o', fileperms($file_output_folder)), -3).'<br>';
			exit;
		}
		/*
		if(posix_getpwuid(fileowner($file_output_folder))['name'] != 'www-data'){
			echo $file_output_folder . ' must be owned by www-data and have permissions of 770.  Aborting upgrade.<br>';
			echo 'Instead, it is owned by '.posix_getpwuid(fileowner($file_output_folder))['name'].' and has permissions '.substr(sprintf('%o', fileperms($file_output_folder)), -3).'<br>';
			exit;		
		}		
		*/
		
		//EXPORT THE ZIP FILE
		$zip_command = 'zip -qr '.$file_output_location. ' ' .$full_site_dir."/public_html -x '*.git*' -x "."'".$full_site_dir."/public_html/theme'";
		echo $zip_command.'<br>';
		exec("$zip_command");

		if(!file_exists($file_output_location)){
			echo "Failed to write the zip file: $file_output_location...aborting.<br>";
			exit;
		}
	
		//STORE THE INFO IN THE DATABASE
		$upgrade = new Upgrade(NULL);
		$upgrade->set('upg_major_version', $version_major);
		$upgrade->set('upg_minor_version', $version_minor);
		$upgrade->set('upg_name', $filename);
		$upgrade->set('upg_release_notes', $_REQUEST['release_notes']);
		$upgrade->prepare();
		$upgrade->save();
		
		
		//GET THE UPGRADE FILE
		echo 'Exported: '. $file_output_location.'<br>';


		
		//UNZIP THE FILE
		/*
		$zip = new ZipArchive;
		if ($zip->open($file_download_location)){
		  $zip->extractTo($stage_location);
		  $zip->close();
		  echo 'Upgrade unzipped...<br>';
		} 
		else {
		  echo 'Unable to unzip upgrade<br>';
		  exit;
		}	
		*/	
	}
	else{
		$breadcrumbs = array();


		$page = new AdminPage();
		$page->admin_header(	
		array(
			'menu-id'=> 'orders-list',
			'page_title' => 'Publish Upgrade',
			'readable_title' => 'Publish Upgrade',
			'breadcrumbs' => $breadcrumbs,
			'session' => $session,
		)
		);
		
		$pageoptions['title'] = "Publish Upgrade";
		$page->begin_box($pageoptions);
		
		echo '<h4>Upgrade History</h4>';
		
		$upgrades = new MultiUpgrade(array(), array('upgrade_id' => 'DESC'), 10, 0);
		$upgrades->load();
		foreach ($upgrades as $upgrade){
			echo 'Version '.$upgrade->get('upg_major_version'). '.'. $upgrade->get('upg_minor_version'). ' - '. LibraryFunctions::convert_time($upgrade->get('upg_create_time'), 'UTC', $session->get_timezone()) . ' - '. substr($upgrade->get('upg_release_notes'), 0, 30) .'<br />';
		}
		echo '<br><br>';


		// Editing an existing order
		$formwriter = new FormWriterMaster('form1');	
		
		
		echo $formwriter->begin_form('form1', 'POST', '/utils/publish_upgrade');

		
		$major = new MultiUpgrade(array(), array('major_version' => DESC));
		$major->load();
		$count = $major->count_all();
		if($count){
			$major_temp =  $major->get(0);
			$major_version = $major_temp->get('upg_major_version');
		}
		else{
			$major_version = 0;
		}

		$minor = new MultiUpgrade(array(), array('minor_version' => DESC));
		$minor->load();
		$count = $minor->count_all();
		if($count){
		$minor_temp =  $minor->get(0);
		$minor_version = $minor_temp->get('upg_major_version') + 1;
		}
		else{
			$minor_version = 0;
		}

		
		echo $formwriter->textinput('Major Version', 'version_major', NULL, 100, $major_version, '', 255, '');
		echo $formwriter->textinput('Minor Version', 'version_minor', NULL, 100, $minor_version, '', 255, '');
		echo $formwriter->textbox('Release notes', 'release_notes', 'ctrlHolder', 10, 80, NULL, '', 'no');
		
	 
		echo $formwriter->start_buttons();
		echo $formwriter->new_form_button('Submit');
		echo $formwriter->end_buttons();

		echo $formwriter->end_form();


		$page->end_box();

		$page->admin_footer();		
		
		
	}
	


?>