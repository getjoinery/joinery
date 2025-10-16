<?php

	require_once(PathHelper::getIncludePath('/includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('/includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('/data/surveys_class.php'));
	require_once(PathHelper::getIncludePath('/data/survey_questions_class.php'));
	require_once(PathHelper::getIncludePath('/data/questions_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	if($_POST['action'] == 'addquestion'){
		$survey_question = new SurveyQuestion(NULL);
		$survey_question->set('srq_svy_survey_id', $_REQUEST['svy_survey_id']);
		$survey_question->set('srq_qst_question_id', $_REQUEST['qst_question_id']);
		$survey_question->prepare();
		$survey_question->save();
	}
	else if($_POST['action'] == 'removequestion'){
		$survey_question = new SurveyQuestion($_REQUEST['srq_survey_question_id'], TRUE);
		$survey_question->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$survey_question->permanent_delete();
	}
	else if($_POST['action'] == 'removesurvey'){
		$survey = new Survey($_REQUEST['svy_survey_id'], TRUE);
		$survey->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$survey->permanent_delete();
	}

	$svy_survey_id = LibraryFunctions::fetch_variable('svy_survey_id', 0, 0, '');
	$survey = new Survey($svy_survey_id, TRUE);

	if($_REQUEST['action'] == 'delete'){
		$survey->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$survey->soft_delete();

		header("Location: /admin/admin_surveys");
		exit();
	}
	else if($_REQUEST['action'] == 'undelete'){
		$survey->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$survey->soft_delete();

		header("Location: /admin/admin_surveys");
		exit();
	}

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'survey_question_id', 0, '');
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');

	//$searchterm = LibraryFunctions::fetch_variable('searchterm', '', 0, '');

	$survey_questions = new MultiSurveyQuestion(
		array('survey_id' => $survey->key),  //SEARCH CRITERIA
		array($sort=>$sdirection),  //SORT AND DIRECTION array($usrsort=>$usrsdirection)
		$numperpage,  //NUM PER PAGE
		$offset,  //OFFSET
		'AND'  //AND OR OR
	);
	$numrecords = $survey_questions->count_all();
	$survey_questions->load();

	// Build dropdown actions
	$options['altlinks'] = array('Edit Survey' => '/admin/admin_survey_edit?svy_survey_id='.$survey->key);
	if(!$survey->get('svy_delete_time') && $_SESSION['permission'] >= 8) {
		$options['altlinks']['Soft Delete'] = '/admin/admin_survey?action=delete&svy_survey_id='.$survey->key;
	}
	else if($_SESSION['permission'] >= 8){
		$options['altlinks']['Undelete'] = '/admin/admin_survey?action=undelete&svy_survey_id='.$survey->key;
	}

	// Build dropdown button from altlinks
	$dropdown_button = '';
	if (!empty($options['altlinks'])) {
		$dropdown_button = '<div class="dropdown">';
		$dropdown_button .= '<button class="btn btn-falcon-default btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Actions</button>';
		$dropdown_button .= '<div class="dropdown-menu dropdown-menu-end py-0">';
		foreach ($options['altlinks'] as $label => $url) {
			$is_danger = strpos($label, 'Delete') !== false;
			$dropdown_button .= '<a href="' . htmlspecialchars($url) . '" class="dropdown-item' . ($is_danger ? ' text-danger' : '') . '">' . htmlspecialchars($label) . '</a>';
		}
		$dropdown_button .= '</div>';
		$dropdown_button .= '</div>';
	}

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'surveys',
		'page_title' => 'Survey',
		'readable_title' => $survey->get('svy_name'),
		'breadcrumbs' => array(
			'Surveys'=>'/admin/admin_surveys',
			$survey->get('svy_name') => '',
		),
		'session' => $session,
		'no_page_card' => true,
		'header_action' => $dropdown_button,
	)
	);

	// Count total responses
	$total_responses = 0; // Would need to query survey_answers table to get actual count
	?>

	<!-- Two Column Layout -->
	<div class="row g-3 mb-3">
		<!-- Left Column -->
		<div class="col-xxl-6">
			<!-- Survey Information Card -->
			<div class="card mb-3">
				<div class="card-header bg-body-tertiary">
					<h5 class="mb-0">Survey Information</h5>
				</div>
				<div class="card-body">
					<table class="table table-borderless mb-0" style="font-size: 0.875rem;">
						<tbody>
							<tr>
								<td class="p-1 text-800 fw-semi-bold" style="width: 180px;">Name</td>
								<td class="p-1 text-600"><?php echo htmlspecialchars($survey->get('svy_name')); ?></td>
							</tr>
							<?php if(!$survey->get('svy_delete_time')): ?>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Survey Link</td>
								<td class="p-1 text-600"><a href="/survey?survey_id=<?php echo LibraryFunctions::encode($survey->key); ?>" target="_blank"><?php echo htmlspecialchars(LibraryFunctions::get_absolute_url('/survey?survey_id=' . LibraryFunctions::encode($survey->key))); ?></a></td>
							</tr>
							<?php endif; ?>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Total Questions</td>
								<td class="p-1 text-600"><?php echo $survey_questions->count(); ?></td>
							</tr>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Total Responses</td>
								<td class="p-1 text-600"><?php echo $total_responses; ?></td>
							</tr>
							<?php if($survey->get('svy_create_time')): ?>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Created</td>
								<td class="p-1 text-600"><?php echo LibraryFunctions::convert_time($survey->get('svy_create_time'), 'UTC', $session->get_timezone(), 'M j, Y g:i A T'); ?></td>
							</tr>
							<?php endif; ?>
							<?php if($survey->get('svy_last_edit_time')): ?>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Last Edited</td>
								<td class="p-1 text-600"><?php echo LibraryFunctions::convert_time($survey->get('svy_last_edit_time'), 'UTC', $session->get_timezone(), 'M j, Y g:i A T'); ?></td>
							</tr>
							<?php endif; ?>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Status</td>
								<td class="p-1">
									<?php if($survey->get('svy_delete_time')): ?>
										<span class="badge badge-danger">Deleted at <?php echo LibraryFunctions::convert_time($survey->get('svy_delete_time'), 'UTC', $session->get_timezone()); ?></span>
									<?php else: ?>
										<span class="badge badge-subtle-success">Active</span>
									<?php endif; ?>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>
		</div>

		<!-- Right Column -->
		<div class="col-xxl-6">
			<!-- Associated Events Card (if applicable) -->
			<?php
			// Check if survey is associated with events
			$associated_events = array(); // Would need to implement this relationship
			if(!empty($associated_events)):
			?>
			<div class="card mb-3">
				<div class="card-header bg-body-tertiary">
					<h5 class="mb-0">Associated Events</h5>
				</div>
				<div class="card-body">
					<div class="list-group list-group-flush">
						<?php foreach($associated_events as $event): ?>
						<a href="/admin/admin_event?evt_event_id=<?php echo $event->key; ?>" class="list-group-item list-group-item-action px-0">
							<div class="d-flex justify-content-between align-items-center">
								<div>
									<h6 class="mb-0"><?php echo htmlspecialchars($event->get('evt_name')); ?></h6>
									<small class="text-600">Event info here</small>
								</div>
								<i class="bi bi-chevron-right text-600"></i>
							</div>
						</a>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
			<?php endif; ?>
		</div>
	</div>

	<?php
	// Survey Questions Table
	$headers = array('Question',  'Action');
	$altlinks = array();
	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
	$table_options = array(
		'altlinks' => $altlinks,
		'title' => 'Survey Questions (' . $survey_questions->count() . ')',
		'card' => true
	);
	$page->tableheader($headers, $table_options, $pager);

	foreach ($survey_questions as $survey_question){
		$question = new Question($survey_question->get('srq_qst_question_id'), TRUE);

		$rowvalues = array();
		array_push($rowvalues, '<a href="/admin/admin_question?qst_question_id='.$survey_question->get('srq_qst_question_id').'">'.$question->get('qst_question').'</a>');

		//array_push($rowvalues, '<a href="/admin/admin_survey_answers?survey_id='.$survey->key.'&question_id='.$survey_question->key.'">answers</a>');

		$delform = '<form id="form2" class="form2" name="form2" method="POST" action="/admin/admin_survey">
		<input type="hidden" class="hidden" name="action" id="action" value="removequestion" />
		<input type="hidden" class="hidden" name="svy_survey_id" id="action" value="'.$survey->key.'"  />
		<input type="hidden" class="hidden" name="srq_survey_question_id" value="'.$survey_question->key.'" />
		<button type="submit">Remove</button>
		</form>';
		array_push($rowvalues, $delform);

		$page->disprow($rowvalues);
	}
	$questions = new MultiQuestion(
		array('deleted'=>false),
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$questions->load();
	$numquestions = $questions->count_all();

	if($numquestions){
		echo '<tr><td colspan="3">';
		$formwriter = $page->getFormWriter('form3');
		//$validation_rules = array();
		//$validation_rules['evt_event_id']['required']['value'] = 'true';
		//echo $formwriter->set_validate($validation_rules);
		echo $formwriter->begin_form('form2', 'POST', '/admin/admin_survey?svy_survey_id='. $survey->key);

		$optionvals = $questions->get_dropdown_array();
		echo $formwriter->hiddeninput('action', 'addquestion');
		echo $formwriter->dropinput("Add question to survey", "qst_question_id", "ctrlHolder", $optionvals, NULL, '', TRUE);
		echo $formwriter->new_form_button('Add');
		echo $formwriter->end_form();
		echo '</td></tr>';
	}
	else{
		echo 'There are no questions.  <a href="/admin/admin_questions">Add one</a>.';
	}
	$page->endtable($pager);

	$page->admin_footer();
?>

