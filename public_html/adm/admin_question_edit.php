<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/questions_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/question_options_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(5);

	if (isset($_REQUEST['qst_question_id'])) {
		$question = new Question($_REQUEST['qst_question_id'], TRUE);
	} else {
		$question = new Question(NULL);
	}

	if($_REQUEST['action'] == 'add_question_option'){
		$question_option = new QuestionOption(NULL);
		$question_option->set('qop_qst_question_id', $question->key);
		$question_option->set('qop_question_option_label', $_REQUEST['qop_question_option_label']);
		$question_option->set('qop_question_option_value', $_REQUEST['qop_question_option_value']);
		$question_option->authenticate_write($session);
		$question_option->prepare();
		$question_option->save();

		//$returnurl = $session->get_return();
		header("Location: /admin/admin_question_edit?qst_question_id=".$question->key);
		exit();		
	}	
	if($_REQUEST['action'] == 'remove_question_option'){
		$question_option = new QuestionOption($_REQUEST['qop_question_option_id'], TRUE);
		$question_option->authenticate_write($session);
		$question_option->permanent_delete();

		//$returnurl = $session->get_return();
		header("Location: /admin/admin_question_edit?qst_question_id=".$question->key);
		exit();		
	}	

	if($_POST){
		
		$editable_fields = array('qst_type', 'qst_question', 'qst_is_published');

		foreach($editable_fields as $field) {
			$question->set($field, $_REQUEST[$field]);
		}
		
		if($_REQUEST['qst_is_published']){
			if(!$question->get('qst_published_time')){
				$question->set('qst_published_time', 'NOW()');
			}
		}	
		else {
			$question->set('qst_published_time', NULL);
		}
	
		//VALIDATION

		foreach ($_REQUEST['validation_options'] as $option){
			$validation_array[$option] = $option;
			if($option == 'decimal' || $option == 'integer'){
				if(isset($_REQUEST['max_value']) && $_REQUEST['max_value'] != ''){
					$validation_array['max_value'] = $_REQUEST['max_value'];
				}
				if(isset($_REQUEST['min_value']) && $_REQUEST['min_value'] != ''){
					$validation_array['min_value'] = $_REQUEST['min_value'];
				}
			}
		}
		if(isset($_REQUEST['max_length']) && $_REQUEST['max_length'] != ''){
			$validation_array['max_length'] = $_REQUEST['max_length'];
		}
		if(isset($_REQUEST['min_length']) && $_REQUEST['min_length'] != ''){
			$validation_array['min_length'] = $_REQUEST['min_length'];
		}
		
		$question->set('qst_validate', serialize($validation_array));
				
		$question->prepare();
		$question->save();
		$question->load();
		

		

		LibraryFunctions::redirect('/admin/admin_question?qst_question_id='. $question->key);
		exit;		
	}


	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 35,
		'breadcrumbs' => array(
			'Surveys'=>'/admin/admin_surveys', 
			'Questions'=>'/admin/admin_questions', 
			'Edit Question' => '',
		),
		'session' => $session,
	)
	);	

	
	$pageoptions['title'] = "Edit Question";
	$pageoptions['width'] = 'uk-width-1-2';
	$page->begin_box($pageoptions);
	
	
	?>
	<script type="text/javascript">
	
		function set_validation_choices(){
			var value = $("#qst_type").val();
			if(value == 1){  //SHORT TEXT
				$("#validation_optionsinteger").prop('disabled', false);
				$("#validation_optionsdecimal").prop('disabled', false);		
				$("#max_length_container").show();
				$("#min_length_container").show();
				$("#max_value_container").show();
				$("#min_value_container").show();
			}	
			else if(value == 2){  //LONG TEXT
				$("#validation_optionsinteger").prop('disabled', true);
				$("#validation_optionsdecimal").prop('disabled', true);	
				$("#max_length_container").show();
				$("#min_length_container").show();
				$("#max_value_container").hide();
				$("#min_value_container").hide();				
			}
			else if(value == 3){  //DROPDOWN
				$("#validation_optionsinteger").prop('disabled', true);
                $("#validation_optionsinteger").attr('checked', false);
				$("#validation_optionsdecimal").prop('disabled', true);
                $("#validation_optionsdecimal").attr('checked', false);	
				$("#max_length_container").hide();
				$("#min_length_container").hide();
				$("#max_value_container").hide();
				$("#min_value_container").hide();					
			}
			else if(value == 4){  //RADIO
				$("#validation_optionsinteger").prop('disabled', true);
                $("#validation_optionsinteger").attr('checked', false);
				$("#validation_optionsdecimal").prop('disabled', true);
                $("#validation_optionsdecimal").attr('checked', false);	
				$("#max_length_container").hide();
				$("#min_length_container").hide();
				$("#max_value_container").hide();
				$("#min_value_container").hide();					
			}
			else if(value == 5){  //CHECKBOX
				$("#validation_optionsinteger").prop('disabled', true);
                $("#validation_optionsinteger").attr('checked', false);
				$("#validation_optionsdecimal").prop('disabled', true);
                $("#validation_optionsdecimal").attr('checked', false);	
				$("#max_length_container").hide();
				$("#min_length_container").hide();
				$("#max_value_container").hide();
				$("#min_value_container").hide();					
			}
			else if(value == 6){  //CHECKBOX LIST
				$("#validation_optionsinteger").prop('disabled', true);
                $("#validation_optionsinteger").attr('checked', false);
				$("#validation_optionsdecimal").prop('disabled', true);
                $("#validation_optionsdecimal").attr('checked', false);	
				$("#max_length_container").hide();
				$("#min_length_container").hide();
				$("#max_value_container").hide();
				$("#min_value_container").hide();				
			}			

		}
	
		$(document).ready(function() {
			
			set_validation_choices();
			
			$("#qst_type").change(function() {	
				set_validation_choices();
			});	
		});
</script>
	<?php
	

	$formwriter = new FormWriterMaster('form1');
	
	$validation_rules = array();
	$validation_rules['qst_question']['required']['value'] = 'true';
	$validation_rules['qst_type']['required']['value'] = 'true';
	echo $formwriter->set_validate($validation_rules);	



	echo $formwriter->begin_form('form', 'POST', '/admin/admin_question_edit');

	if($question->key){
		echo $formwriter->hiddeninput('qst_question_id', $question->key);
		echo $formwriter->hiddeninput('action', 'edit');
	}
	
	echo $formwriter->textinput('Question', 'qst_question', NULL, 100, $question->get('qst_question'), '', 255, '');	
	
	
	$optionvals = array("Short text"=>Question::TYPE_SHORT_TEXT, "Long Text"=>Question::TYPE_LONG_TEXT, 'Dropdown'=>Question::TYPE_DROPDOWN, 'Radio'=>Question::TYPE_RADIO, 'Checkbox'=>Question::TYPE_CHECKBOX, 'Checkbox List'=>Question::TYPE_CHECKBOX_LIST);
	echo $formwriter->dropinput("Type", "qst_type", "ctrlHolder", $optionvals, $question->get('qst_type'), '', FALSE);
	

	$optionvals = array('Required'=>'required', 'Integer (Example: 5)'=>'integer', 'Decimal (Example: 5.5)'=>'decimal');
	
	if ($question->key) {
		//FILL THE CHECKED VALUES
		$checkedvals = unserialize($question->get('qst_validate'));
		$max_length = $checkedvals['max_length'];
		unset($checkedvals['max_length']);
		$min_length = $checkedvals['min_length'];
		unset($checkedvals['min_length']);
		$max_value = $checkedvals['max_value'];
		unset($checkedvals['max_value']);
		$min_value = $checkedvals['min_value'];
		unset($checkedvals['min_value']);
	}
	else{
		$checkedvals = array();
	}
	$disabledvals = array();
	$readonlyvals = array(); 
	echo $formwriter->checkboxList("Validation options", 'validation_options', "ctrlHolder", $optionvals, $checkedvals, $disabledvals, $readonlyvals);	

	echo $formwriter->textinput('Validation Maximum Length', 'max_length', NULL, 14, $max_length, '', 3, '');	
	echo $formwriter->textinput('Validation Minimum Length', 'min_length', NULL, 100, $min_length, '', 3, '');	
	echo $formwriter->textinput('Validation Maximum Value', 'max_value', NULL, 100, $max_value, '', 10, '');	
	echo $formwriter->textinput('Validation Minimum Value', 'min_value', NULL, 100, $min_value, '', 10, '');	



	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form();

	
	$page->end_box();

	if($question->key){
		$pageoptions['title'] = "Edit Answers";
		$pageoptions['width'] = 'uk-width-1-2';
		$page->begin_box($pageoptions);

		$question_options = $question->get_question_options();
		if(!count($question_options)){
			echo 'None';
		}
		echo '<ul>';
		foreach ($question_options as $question_option) {
			echo $question_option->get('qop_question_option_label') . ' - '.  $question_option->get('qop_question_option_value').' (<a href="/admin/admin_question_edit?qop_question_option_id='. $question_option->key .'&qst_question_id='. $question->key .'&action=remove_question_option">delete</a>)<br>'; 
			
		}
		echo '</ul>';
		echo '<h4>Add New Question Option</h4>';
		$formwriter = new FormWriterMaster('form2');
		
		$validation_rules = array();
		$validation_rules['qop_question_option_label']['required']['value'] = 'true';
		$validation_rules['qop_question_option_value']['required']['value'] = 'true';
		echo $formwriter->set_validate($validation_rules);				
		
		echo $formwriter->begin_form('form2', 'POST', '/admin/admin_question_edit');
		echo $formwriter->hiddeninput('qst_question_id', $question->key);
		echo $formwriter->hiddeninput('action', 'add_question_option');
		echo $formwriter->textinput('Label', 'qop_question_option_label', NULL, 100, '', '', 255, '');
		echo $formwriter->textinput('Value', 'qop_question_option_value', 'ctrlHolder', 100, '', '', 255, '');
		echo $formwriter->start_buttons();
		echo $formwriter->new_form_button('Submit');
		echo $formwriter->end_buttons();
		echo $formwriter->end_form();


		$page->end_box();	
	}

	$page->admin_footer();

?>
