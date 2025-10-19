<?php

require_once(PathHelper::getIncludePath('adm/logic/admin_questions_logic.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

$page_vars = process_logic(admin_questions_logic($_GET, $_POST));

$session = $page_vars['session'];
$questions = $page_vars['questions'];
$numrecords = $page_vars['numrecords'];
$numperpage = $page_vars['numperpage'];

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
	'altlinks' => $altlinks,
	'title' => 'Questions',
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
