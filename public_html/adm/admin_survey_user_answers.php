<?php

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('adm/logic/admin_survey_user_answers_logic.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/questions_class.php'));

$session = SessionControl::get_instance();
$session->check_permission(5);
$session->set_return();

$page_vars = process_logic(admin_survey_user_answers_logic($_GET, $_POST));

$page = new AdminPage();
$page->admin_header(
array(
	'menu-id'=> 'surveys',
	'page_title' => 'Add User',
	'readable_title' => 'Add User',
	'breadcrumbs' => array(
		'Surveys'=>'/admin/admin_surveys',
		$page_vars['survey']->get('svy_name'). ' answers' =>'/admin/admin_survey_users?svy_survey_id='.$page_vars['survey']->key,
		$page_vars['user']->display_name() .'\'s answers' => '',
	),
	'session' => $session,
)
);

$headers = array("Question", "Answer", "Last Update");
$altlinks = array();
$altlinks += array();
$pager = new Pager(array('numrecords'=>$page_vars['numrecords'], 'numperpage'=> $page_vars['numperpage']));
$table_options = array(
	'altlinks' => $altlinks,
	'title' => $page_vars['user']->display_name(). '\'s answers to survey "'.$page_vars['survey']->get('svy_name').'"',
);
$page->tableheader($headers, $table_options, $pager);

foreach ($page_vars['answers'] as $answer){
	$question = new Question($answer->get('sva_qst_question_id'), TRUE);

	$rowvalues = array();

	array_push($rowvalues, $question->get('qst_question'));
	array_push($rowvalues, $question->get_answer_readable($answer->get('sva_answer')));

	array_push($rowvalues, LibraryFunctions::convert_time($answer->get('sva_create_time'), "UTC", $session->get_timezone(), 'M j, Y'));

	$page->disprow($rowvalues);
}
$page->endtable($pager);

$page->admin_footer();
?>
