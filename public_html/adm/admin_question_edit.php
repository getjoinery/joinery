<?php

	require_once(PathHelper::getIncludePath('/includes/AdminPage.php'));
	require_once(PathHelper::getIncludePath('adm/logic/admin_question_edit_logic.php'));

	$page_vars = process_logic(admin_question_edit_logic($_GET, $_POST));
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
	<script type="text/javascript">

		function set_validation_choices(){
			var value = document.getElementById("qst_type").value;

			// FormWriter V2 generates checkbox IDs as: fieldname_value
			var integerCheckbox = document.getElementById("validation_options_integer");
			var decimalCheckbox = document.getElementById("validation_options_decimal");

			if(value == 1){  //SHORT TEXT
				if(integerCheckbox) integerCheckbox.disabled = false;
				if(decimalCheckbox) decimalCheckbox.disabled = false;
				document.getElementById("max_length_container").style.display = "block";
				document.getElementById("min_length_container").style.display = "block";
				document.getElementById("max_value_container").style.display = "block";
				document.getElementById("min_value_container").style.display = "block";
				document.getElementById("answersbox").style.display = "none";
			}
			else if(value == 2){  //LONG TEXT
				if(integerCheckbox) {
					integerCheckbox.disabled = true;
					integerCheckbox.checked = false;
				}
				if(decimalCheckbox) {
					decimalCheckbox.disabled = true;
					decimalCheckbox.checked = false;
				}
				document.getElementById("max_length_container").style.display = "block";
				document.getElementById("min_length_container").style.display = "block";
				document.getElementById("max_value_container").style.display = "none";
				document.getElementById("min_value_container").style.display = "none";
				document.getElementById("answersbox").style.display = "none";
			}
			else {  //DROPDOWN, RADIO, CHECKBOX, CHECKBOX_LIST
				if(integerCheckbox) {
					integerCheckbox.disabled = true;
					integerCheckbox.checked = false;
				}
				if(decimalCheckbox) {
					decimalCheckbox.disabled = true;
					decimalCheckbox.checked = false;
				}
				document.getElementById("max_length_container").style.display = "none";
				document.getElementById("min_length_container").style.display = "none";
				document.getElementById("max_value_container").style.display = "none";
				document.getElementById("min_value_container").style.display = "none";
				document.getElementById("answersbox").style.display = "block";
			}

			// Additional checkbox-specific logic
			if(value == 5){  //CHECKBOX - limit to one option
				var existingOptions = document.querySelectorAll('.question-option-item');
				if(existingOptions.length >= 1){
					var addForm = document.getElementById("add-option-form");
					if(addForm) addForm.style.display = "none";
				}
			} else {
				var addForm = document.getElementById("add-option-form");
				if(addForm) addForm.style.display = "block";
			}
		}

		// Replace jQuery document ready and change handler
		document.addEventListener('DOMContentLoaded', function() {
			set_validation_choices();
			document.getElementById("qst_type").addEventListener('change', set_validation_choices);
		});
</script>
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
		'showdefault' => false,
		'validation' => ['required' => true]
	]);

	// Validation checkboxes - unserialize and convert
	$validation_data = unserialize($question->get('qst_validate')) ?: [];
	$checked_vals = [];
	if (!empty($validation_data['required'])) $checked_vals[] = 'required';
	if (!empty($validation_data['integer'])) $checked_vals[] = 'integer';
	if (!empty($validation_data['decimal'])) $checked_vals[] = 'decimal';

	$formwriter->checkboxlist('validation_options', 'Validation options', [
		'options' => [
			'Required' => 'required',
			'Integer (Example: 5)' => 'integer',
			'Decimal (Example: 5.5)' => 'decimal'
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
			echo htmlspecialchars($question_option->get('qop_question_option_label')) . ' - '. htmlspecialchars($question_option->get('qop_question_option_value')).' (<a href="/admin/admin_question_edit?qop_question_option_id='. $question_option->key .'&qst_question_id='. $question->key .'&action=remove_question_option">delete</a>)<br>';

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
