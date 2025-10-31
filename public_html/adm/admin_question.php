<?php

	require_once(PathHelper::getIncludePath('/includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('/includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('/data/users_class.php'));
	require_once(PathHelper::getIncludePath('/data/questions_class.php'));
	require_once(PathHelper::getIncludePath('/data/question_options_class.php'));

	require_once(PathHelper::getIncludePath('adm/logic/admin_question_logic.php'));

	$page_vars = process_logic(admin_question_logic($_GET, $_POST));

	extract($page_vars);

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'survey-questions',
		'breadcrumbs' => array(
			'Surveys'=>'/admin/admin_surveys',
			'Questions'=>'/admin/admin_questions',
			'Question '.$question->key=>'',
		),
		'session' => $session,
	)
	);

	$options['title'] = 'Question '.$question->key;
	$options['altlinks'] = array('Edit Question' => '/admin/admin_question_edit?qst_question_id='.$question->key);
	$options['altlinks'] += array('Delete Question' => '/admin/admin_question_permanent_delete?qst_question_id='.$question->key);
	if(!$question->get('qst_delete_time') && $_SESSION['permission'] >= 8) {
		$options['altlinks']['Soft Delete'] = '/admin/admin_question?action=delete&qst_question_id='.$question->key;
	}

	$page->begin_box($options);

	if($question->get('qst_delete_time')){
		echo 'Status: Deleted at '.LibraryFunctions::convert_time($question->get('qst_delete_time'), 'UTC', $session->get_timezone()).'<br />';
	}
	else if($question->get('qst_is_published')){
		echo '<strong>Published:</strong> ' . LibraryFunctions::convert_time($question->get('qst_published_time'), 'UTC', $session->get_timezone()). '<br />';
	}
	else{
		echo '<strong>UNPUBLISHED</strong><br />';
	}

	echo '<strong>Created:</strong> '.LibraryFunctions::convert_time($question->get('qst_create_time'), 'UTC', $session->get_timezone()) .'<br />';

	if($_POST){
		echo '<b>'.$valid.'</b>';
	}

	// Get V2 FormWriter instance
	$formwriter = $page->getFormWriter('form1', 'v2', [
		'action' => '/admin/admin_question',
		'method' => 'POST'
	]);

	$formwriter->begin_form();
	$formwriter->hiddeninput('qst_question_id', $question->key);

	// The Question class output_question() method now uses V2 syntax
	$question->output_question($formwriter);

	$formwriter->submitbutton('test_button', 'Test');
	$formwriter->end_form();

	$page->end_box();

	$page->admin_footer();
?>

