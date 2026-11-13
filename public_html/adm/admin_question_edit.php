<?php
/** @joinery-admin class=content */

	require_once(PathHelper::getIncludePath('/includes/AdminPage.php'));
	require_once(PathHelper::getIncludePath('adm/logic/admin_question_edit_logic.php'));

	$page_vars = process_logic(admin_question_edit_logic(array_merge($_GET, $_POST)));
	extract($page_vars);

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'survey-questions',
		'breadcrumbs' => array(
			'Surveys'=>'/admin/admin_surveys',
			'Questions'=>'/admin/admin_questions',
			'Edit Question' => '',
		),
		'session' => $session,
	)
	);

	$pageoptions['title'] = "Edit Question";
	$page->begin_box($pageoptions);

	?>
	<?php echo PageScript::src('assets/js/admin-question-edit.js'); ?>
	<?php

	// Get V2 FormWriter instance
	$formwriter = $page->getFormWriter('form1', [
		'action' => '/admin/admin_question_edit',
		'method' => 'POST',
		'model' => $question,
		'edit_primary_key_value' => $question->key  // NULL for add, ID for edit
	]);

	$formwriter->begin_form();
	// FormWriter automatically adds: <input type="hidden" name="edit_primary_key_value" value="...">

	$formwriter->textinput('qst_question', 'Question', [
		'value' => $question->get('qst_question'),
		'maxlength' => 255,
		'validation' => ['required' => true]
	]);

	$optionvals = array(Question::TYPE_SHORT_TEXT=>"Short text", Question::TYPE_LONG_TEXT=>"Long Text", Question::TYPE_DROPDOWN=>'Dropdown', Question::TYPE_RADIO=>'Radio', Question::TYPE_CHECKBOX=>'Checkbox', Question::TYPE_CHECKBOX_LIST=>'Checkbox List');
	$formwriter->dropinput('qst_type', 'Type', [
		'options' => $optionvals,
		'value' => $question->get('qst_type'),
		'validation' => ['required' => true]
	]);

	// Validation checkboxes - unserialize and convert
	$validation_data = unserialize((string)$question->get('qst_validate'), ['allowed_classes' => false]) ?: [];
	$checked_vals = [];
	if ($question->get('qst_is_required')) $checked_vals[] = 'required';
	if (!empty($validation_data['integer'])) $checked_vals[] = 'integer';
	if (!empty($validation_data['decimal'])) $checked_vals[] = 'decimal';

	$formwriter->checkboxlist('validation_options', 'Validation options', [
		'options' => [
			'required' => 'Required',
			'integer' => 'Integer (Example: 5)',
			'decimal' => 'Decimal (Example: 5.5)'
		],
		'checked' => $checked_vals
	]);

	// Validation parameter fields (keep existing HTML containers for JavaScript)
	echo '<div id="max_length_container" style="display:none;">';
	$formwriter->textinput('max_length', 'Validation Maximum Length', [
		'value' => $validation_data['max_length'] ?? '',
		'maxlength' => 3
	]);
	echo '</div>';

	echo '<div id="min_length_container" style="display:none;">';
	$formwriter->textinput('min_length', 'Validation Minimum Length', [
		'value' => $validation_data['min_length'] ?? '',
		'maxlength' => 3
	]);
	echo '</div>';

	echo '<div id="max_value_container" style="display:none;">';
	$formwriter->textinput('max_value', 'Validation Maximum Value', [
		'value' => $validation_data['max_value'] ?? '',
		'maxlength' => 10
	]);
	echo '</div>';

	echo '<div id="min_value_container" style="display:none;">';
	$formwriter->textinput('min_value', 'Validation Minimum Value', [
		'value' => $validation_data['min_value'] ?? '',
		'maxlength' => 10
	]);
	echo '</div>';

	$formwriter->submitbutton('submit_button', 'Submit');
	$formwriter->end_form();

	$page->end_box();

	$pageoptions['title'] = "Edit Answers";
		$page->begin_box($pageoptions);
		echo '<span id="answersbox">';
		$question_options = $question->get_question_options();
		if(!count($question_options)){
			echo 'None';
		}
		echo '<ul>';
		$num_options = 0;
		foreach ($question_options as $question_option) {
			$num_options++;
			echo htmlspecialchars($question_option->get('qop_question_option_label')) . ' - '. htmlspecialchars($question_option->get('qop_question_option_value')).' ('
				. AdminPage::action_button('delete', '/admin/admin_question_edit', array(
					'hidden' => array(
						'qop_question_option_id' => $question_option->key,
						'qst_question_id'        => $question->key,
						'action'                 => 'remove_question_option',
					),
					'class'   => 'btn btn-link btn-sm',
					'confirm' => 'Delete this answer option?',
				))
				. ')<br>';

		}
		echo '</ul>';

		if($question->key && $num_options >= 1 && $question->get('qst_type') == Question::TYPE_CHECKBOX){
			//DON'T SHOW THE NEW QUESTION BOX
		}
		else{
			echo '<div id="add-option-form">';
			echo '<h4>Add New Question Option</h4>';
			$formwriter2 = $page->getFormWriter('form2', [
				'action' => '/admin/admin_question_edit',
				'method' => 'POST'
			]);

			$formwriter2->begin_form();
			$formwriter2->hiddeninput('qst_question_id', '', ['value' => $question->key]);
			$formwriter2->hiddeninput('action', '', ['value' => 'add_question_option']);

			$formwriter2->textinput('qop_question_option_label', 'Label', [
				'maxlength' => 255,
				'validation' => ['required' => true]
			]);

			$formwriter2->textinput('qop_question_option_value', 'Value', [
				'maxlength' => 255,
				'validation' => ['required' => true]
			]);

			$formwriter2->submitbutton('add_option', 'Submit');
			$formwriter2->end_form();
			echo '</div>';

			echo '</span>';
			$page->end_box();
		}

	$page->admin_footer();

?>
