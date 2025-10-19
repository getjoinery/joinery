<?php

require_once(PathHelper::getIncludePath('adm/logic/admin_surveys_logic.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('data/survey_questions_class.php'));

$page_vars = process_logic(admin_surveys_logic($_GET, $_POST));

$session = $page_vars['session'];
$surveys = $page_vars['surveys'];
$numrecords = $page_vars['numrecords'];
$numperpage = $page_vars['numperpage'];

$page = new AdminPage();
$page->admin_header(
array(
	'menu-id'=> 'surveys',
	'page_title' => 'Add User',
	'readable_title' => 'Add User',
	'breadcrumbs' => array(
		'Surveys'=>''
	),
	'session' => $session,
)
);

$headers = array("Survey", "# Questions", "Last Update", "Action");
$altlinks = array();
$altlinks += array('Add Survey'=> '/admin/admin_survey_edit');
$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
$table_options = array(
	'altlinks' => $altlinks,
	'title' => 'Surveys',
);
$page->tableheader($headers, $table_options, $pager);

foreach ($surveys as $survey){
	$sort = 'survey_id';
	$sdirection = 'DESC';

	$survey_questions = new MultiSurveyQuestion(
		array('survey_id' => $survey->key),
		array($sort=>$sdirection),
		$numperpage,
		0,
		'AND'
	);
	$num_questions = $survey_questions->count_all();

	$rowvalues = array();

	array_push($rowvalues, $survey->get('svy_name') ." <a href='/admin/admin_survey?svy_survey_id=$survey->key'>[edit]</a>");

	array_push($rowvalues, $num_questions." questions</a> ");

	array_push($rowvalues, '<a href="/admin/admin_survey_users?svy_survey_id='.$survey->key.'">'.$survey->get_num_users_who_answered().' answers</a>');

	if($survey->get('svy_delete_time')){
		array_push($rowvalues, '<b>Deleted</b>');
	}
	else{
		$delform = '<form id="form2" class="form2" name="form2" method="POST" action="/admin/admin_survey_permanent_delete?svy_survey_id='. $survey->key.'">
		<input type="hidden" class="hidden" name="action" value="removesurvey" />
		<input type="hidden" class="hidden" name="svy_survey_id" value="'.$survey->key.'" />
		<button type="submit">Delete</button>
		</form>';
		array_push($rowvalues, $delform);
	}

	$page->disprow($rowvalues);
}

$page->endtable($pager);
$page->admin_footer();
?>
