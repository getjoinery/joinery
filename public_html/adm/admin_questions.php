<?php

	PathHelper::requireOnce('/includes/AdminPage.php');

	PathHelper::requireOnce('/includes/LibraryFunctions.php');

	PathHelper::requireOnce('/data/users_class.php');
	PathHelper::requireOnce('/data/questions_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'question_id', 0, '');
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'ASC', 0, '');
	$searchterm = LibraryFunctions::fetch_variable('searchterm', '', 0, '');

	$search_criteria = array();

	//ONLY SHOW DELETED TO SUPER ADMINS
	if($_SESSION['permission'] < 10){
		$search_criteria['deleted'] = false;
	}

	$questions = new MultiQuestion(
		$search_criteria,
		array($sort=>$sdirection),
		$numperpage,
		$offset);
	$numrecords = $questions->count_all();
	$questions->load();

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'survey-questions',
		'breadcrumbs' => array(
			'Surveys'=>'/admin/admin_surveys',
			'Questions'=>'',
		),
		'session' => $session,
	)
	);

	$headers = array("Question",  "Type", "Created", "Published", "Active");
	$altlinks = array('New Question'=>'/admin/admin_question_edit');
	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
	$table_options = array(
		//'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
		'altlinks' => $altlinks,
		'title' => 'Questions',
		//'search_on' => TRUE
	);
	$page->tableheader($headers, $table_options, $pager);

	foreach ($questions as $question){

		$rowvalues = array();
		array_push($rowvalues, "Question ".$question->key.": ".$question->get('qst_question')." <a href='/admin/admin_question?qst_question_id=$question->key'> [edit]</a>");
		array_push($rowvalues, $question->get('qst_type'));
		array_push($rowvalues, LibraryFunctions::convert_time($question->get('qst_create_time'), 'UTC', $session->get_timezone()));
		array_push($rowvalues, LibraryFunctions::convert_time($question->get('qst_published_time'), 'UTC', $session->get_timezone()));

		if($question->get('qst_delete_time')) {
			$status = 'Deleted';
		} else {
			$status = 'Active';
		}
		array_push($rowvalues, $status);

		$page->disprow($rowvalues);
	}

	$page->endtable($pager);
	$page->admin_footer();
?>

