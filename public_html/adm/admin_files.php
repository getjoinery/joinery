<?php
	require_once(__DIR__ . '/../includes/PathHelper.php');
	
	PathHelper::requireOnce('includes/AdminPage.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	PathHelper::requireOnce('data/files_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	//$session->set_return();

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'file_id', 0, '');
	$filter = LibraryFunctions::fetch_variable('filter', 'all', 0, '');
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
	$searchterm = LibraryFunctions::fetch_variable('searchterm', '', 0, '');

	$search_criteria = array();
	if($searchterm){
		$search_criteria['filename_like'] = $searchterm;
	}	

	//ONLY SHOW DELETED TO SUPER ADMINS
	if($_SESSION['permission'] < 10){
		$search_criteria['deleted'] = false;
	}
	
	if($filter == 'files'){
		$search_criteria['picture'] = false;
	}
	else if($filter == 'images'){
		$search_criteria['picture'] = true;
	}
	else{
		//nothing
	}
	
	$files = new MultiFile(
		$search_criteria,
		array($sort=>$sdirection),
		$numperpage,
		$offset,
		'AND');
	$files->load();	
	$numrecords = $files->count_all();

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'files',
		'page_title' => 'Files',
		'readable_title' => 'Files',
		'breadcrumbs' => array(
			'Files'=>'', 
		),
		'session' => $session,
	)
	);

	$headers = array('Thumb','File', 'File Type', 'Uploaded', 'By');
	$altlinks = array('Upload file'=>'/admin/admin_file_upload');
	$title= 'Files';
	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
	$table_options = array(
		'filteroptions'=>array("All files"=>"all", "Files only"=>"files", "Images only"=>"images"),
		'altlinks' => $altlinks,
		'title' => $title,
		//'search_on' => TRUE
	);
	$page->tableheader($headers, $table_options, $pager);
	

	foreach($files as $file) {
		$deleted = '';
		if($file->get('fil_delete_time')){
			$deleted = 'DELETED';
		}
		$user = new User($file->get('fil_usr_user_id'), TRUE);
		
		$rowvalues = array();
		if (strpos($file->get('fil_type'), 'image/') !== false) { 
			array_push($rowvalues, '<img loading="lazy" src="/uploads/lthumbnail/'.$file->get('fil_name').'">');
		}
		else if (strpos($file->get('fil_type'), 'application/pdf') !== false) { 
			array_push($rowvalues, '<img loading="lazy" src="/assets/images/pdf_icon_80px.png">');
		}
		else if (strpos($file->get('fil_type'), 'application/msword') !== false || strpos($file->get('fil_type'), 'wordprocessingml.document') !== false || strpos($file->get('fil_name'), '.docx') !== false) { 
			array_push($rowvalues, '<img loading="lazy" src="/assets/images/microsoft_word_icon_80px.png">');
		}
		else if (strpos($file->get('fil_name'), '.xlsx') !== false) { 
			array_push($rowvalues, '<img loading="lazy" src="/assets/images/excel_icon_80px.png">');
		}			
		else{
			array_push($rowvalues, '');
		}
		array_push($rowvalues, '<a href="/admin/admin_file?fil_file_id='.$file->key.'">'.$file->get('fil_title').'</a> '. $deleted);
		array_push($rowvalues, $file->get('fil_type'));

		array_push($rowvalues,  LibraryFunctions::convert_time($file->get('fil_create_time'), "UTC", $session->get_timezone()));
		
		array_push($rowvalues, $user->display_name());
		$page->disprow($rowvalues);
	}
		
	$page->endtable($pager);		
		
	$page->admin_footer();

?>
