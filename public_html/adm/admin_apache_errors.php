<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/phone_number_class.php');



	$session = SessionControl::get_instance();
	$session->check_permission(9);
	$session->set_return();
	
	
	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'create_time', 0, '');
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
	$searchterm = LibraryFunctions::fetch_variable('searchterm', '', 0, '');
	

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'errors',
		'page_title' => 'Errors',
		'readable_title' => 'Errors',
		'breadcrumbs' => NULL,
		'session' => $session,
	)
	);


	$headers = array("Error");
	$altlinks = array();
	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));	
	$table_options = array(
		//'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
		'altlinks' => $altlinks,
		'title' => 'Errors',
		//'search_on' => TRUE
	);
	$page->tableheader($headers, $table_options, $pager);

	$settings = Globalvars::get_instance();
	$error_log = $settings->get_setting('apache_error_log');
	$file = file($error_log);
	if($file){
		$file = array_reverse($file);

		$linenum = 1;
		foreach ($file as $line) {
			$rowvalues = array();
			if(strpos($line, '[php7:error]')){
				array_push($rowvalues, '<span style="color: #a80000">'.$line.'</span>');		
			}
			else{
				array_push($rowvalues, $line);
			}


			$page->disprow($rowvalues);
			$linenum++;
			if($linenum == 50){
				break;
			}
			
		}
	}
	else{
		echo "No error file found at ".$error_log ;
	}

	$page->endtable($pager);

	$page->admin_footer();
?>


