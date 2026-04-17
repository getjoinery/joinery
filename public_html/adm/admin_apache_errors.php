<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/phone_number_class.php'));

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
	if(file_exists($error_log)){
		$lines = explode("\n", shell_exec("tail -n 500 " . escapeshellarg($error_log)));
		$lines = array_reverse($lines);

		$linenum = 1;
		foreach ($lines as $line) {
			if(trim($line) === '') continue;
			$rowvalues = array();
			if(strpos($line, '[php7:error]') !== false || strpos($line, '[php:error]') !== false){
				array_push($rowvalues, '<span style="color: #a80000">'.htmlspecialchars($line).'</span>');
			}
			else{
				array_push($rowvalues, htmlspecialchars($line));
			}

			$page->disprow($rowvalues);
			$linenum++;
		}
	}
	else{
		echo "No error file found at ".htmlspecialchars($error_log);
	}

	$page->endtable($pager);

	$page->admin_footer();
?>

