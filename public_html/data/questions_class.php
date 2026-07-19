<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

require_once(PathHelper::getIncludePath('data/question_options_class.php'));
require_once(PathHelper::getIncludePath('data/survey_answers_class.php'));

class QuestionException extends SystemBaseException {}

class Question extends SystemBase {	public static $prefix = 'qst';
	public static $tablename = 'qst_questions';
	public static $pkey_column = 'qst_question_id';

	// REST CRUD exposure (Layer 1). Public catalog content (Bucket A): readable,
	// writable, and world-readable; writes inherit the deny-by-default scope.
	public static $api_readable = true;
	public static $api_writable = true;
	public static $api_public_read = true;

	// AI auto-discovery (read)
	public static $ai_readable        = true;
	public static $ai_owner_field     = false; // ownerless catalog — members read all rows
	public static $ai_description     = 'Library of reusable form/survey questions.';
	public static $ai_excluded_fields = [];

	const LANGUAGE_ENGLISH = 1;
	
	const TYPE_SHORT_TEXT = 1;
	const TYPE_LONG_TEXT = 2;
	const TYPE_DROPDOWN = 3;
	const TYPE_RADIO = 4;
	const TYPE_CHECKBOX = 5;
	const TYPE_CHECKBOX_LIST = 6;
	const TYPE_CONFIRMATION = 7;

		/**
	 * Field specifications define database column properties and validation rules
	 * 
	 * Database schema properties (used by update_database):
	 *   'type' => 'varchar(255)' | 'int4' | 'int8' | 'text' | 'timestamp' | 'bool' | etc.
	 *   'is_nullable' => true/false - Whether NULL values are allowed
	 *   'serial' => true/false - Auto-incrementing field
	 * 
	 * Validation and behavior properties (used by SystemBase):
	 *   'required' => true/false - Field must have non-empty value on save
	 *   'default' => mixed - Default value for new records (applied on INSERT only)
	 *   'zero_on_create' => true/false - Set to 0 when creating if NULL (INSERT only)
	 * 
	 * Note: Timestamp fields are auto-detected based on type for smart_get() and export_as_array()
	 */
	public static $field_specifications = array(
	    'qst_question_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'qst_translation_of_question_id' => array('type'=>'int4'),
	    'qst_language' => array('type'=>'int4'),
	    'qst_question' => array('type'=>'text'),
	    'qst_options' => array('type'=>'text'),
	    'qst_validate' => array('type'=>'varchar(255)'),
	    'qst_type' => array('type'=>'int4'),
	    'qst_config' => array('type'=>'jsonb'),
	    'qst_is_required' => array('type'=>'bool', 'default'=>false),
	    'qst_is_published' => array('type'=>'bool', 'default'=>true),
	    'qst_published_time' => array('type'=>'timestamp(6)'),
	    'qst_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'qst_delete_time' => array('type'=>'timestamp(6)'),
	);

	public static $json_vars = array('qst_config');

	function output_js_validation($validation_rules){
		$validation_options = unserialize($this->get('qst_validate')) ?: [];

		if ($this->get('qst_type') == Question::TYPE_CHECKBOX_LIST){
			$question_name = '"question_'.$this->key.'[]"';
		}
		else{
			$question_name = 'question_'.$this->key;
		}

		if($this->get('qst_is_required')){
			$validation_rules[$question_name]['required']['value'] = 'true';
		}
		if(array_key_exists('integer', $validation_options)){
			$validation_rules[$question_name]['digits']['value'] = 'true';
		}
		if(array_key_exists('decimal', $validation_options)){
			$validation_rules[$question_name]['number']['value'] = 'true';
		}
		if(array_key_exists('max_length', $validation_options)){
			$validation_rules[$question_name]['maxlength']['value'] = $validation_options['max_length'];
		}
		if(array_key_exists('min_length', $validation_options)){
			$validation_rules[$question_name]['minlength']['value'] = $validation_options['min_length'];
		}
		if(array_key_exists('max_value', $validation_options)){
			$validation_rules[$question_name]['max']['value'] = $validation_options['max_value'];
		}
		if(array_key_exists('min_value', $validation_options)){
			$validation_rules[$question_name]['min']['value'] = $validation_options['min_value'];
		}
		return $validation_rules;
	}
	
	function validate_answers($answers){
		$validation_options = unserialize($this->get('qst_validate')) ?: [];

		if($this->get('qst_is_required')){
			if($answers === '' || $answers === NULL || (is_array($answers) && count($answers) == 0)){
				return 'You did not answer this question: '. $this->get('qst_question');
			}
		}

		// A checkbox-list answer arrives as an array and is persisted as its
		// comma-joined form. Every rule below measures a single value, so they
		// are applied to that same joined string: a length limit then bounds
		// what is actually stored, and passing the array straight to strlen()
		// would be a fatal TypeError rather than a validation failure.
		$scalar = is_array($answers) ? implode(',', $answers) : (string)$answers;

		if(array_key_exists('integer', $validation_options)){
			// Answers arrive from a form post, so they are strings — is_integer()
			// would reject every genuine answer and make the question
			// unanswerable. This mirrors the client-side "digits" rule that
			// output_js_validation() advertises for the same option, so the two
			// sides accept exactly the same set of values.
			if(!ctype_digit($scalar)){
				return 'This answer to this question "'.$this->get('qst_question').'" needs to be a number: '. $scalar;
			}
		}
		if(array_key_exists('decimal', $validation_options)){
			// Mirrors the client-side "number" rule, which accepts any numeric
			// value rather than requiring a fractional part.
			if(!is_numeric($scalar)){
				return 'This answer to this question "'.$this->get('qst_question').'" needs to be a decimal number: '. $scalar;
			}
		}
		if(array_key_exists('max_length', $validation_options)){
			if(strlen($scalar) > $validation_options['max_length']){
				return 'This answer to this question "'.$this->get('qst_question').'" is too long: '. $scalar;
			}
		}
		if(array_key_exists('min_length', $validation_options)){
			if(strlen($scalar) < $validation_options['min_length']){
				return 'This answer to this question "'.$this->get('qst_question').'" is too short: '. $scalar;
			}
		}
		if(array_key_exists('max_value', $validation_options)){
			if($scalar > $validation_options['max_value']){
				return 'This answer to this question "'.$this->get('qst_question').'" is too large: '. $scalar;
			}
		}
		if(array_key_exists('min_value', $validation_options)){
			if($scalar < $validation_options['min_value']){
				return 'This answer to this question "'.$this->get('qst_question').'" is too small: '. $scalar;
			}
		}
		return 'valid';
	}
	
	function output_question($formwriter, $value=NULL, $append_text=NULL){
		$field_name = 'question_'.$this->key;
		$question_text = $this->get('qst_question') . $append_text;

		// Get validation options
		$validation_options = unserialize($this->get('qst_validate')) ?: [];
		$validation = [];

		// The typing cap on the input comes from the question's own max_length
		// rule. Reading it as a field would always yield NULL — max_length is a
		// validation option, not a column — leaving every input capped at 255
		// characters, so an admin who allowed 500 would find the browser
		// refusing input the server would have accepted.
		$field_max_length = 255;
		if (!empty($validation_options['max_length'])) {
			$field_max_length = $validation_options['max_length'];
		}

		if (!empty($validation_options['required'])) {
			$validation['required'] = true;
		}
		if (!empty($validation_options['integer'])) {
			$validation['digits'] = true;
		}
		if (!empty($validation_options['decimal'])) {
			$validation['number'] = true;
		}
		if (!empty($validation_options['max_length'])) {
			$validation['maxlength'] = $validation_options['max_length'];
		}
		if (!empty($validation_options['min_length'])) {
			$validation['minlength'] = $validation_options['min_length'];
		}
		if (!empty($validation_options['max_value'])) {
			$validation['max'] = $validation_options['max_value'];
		}
		if (!empty($validation_options['min_value'])) {
			$validation['min'] = $validation_options['min_value'];
		}

		if ($this->get('qst_type') == Question::TYPE_SHORT_TEXT){
			$formwriter->textinput($field_name, $question_text, [
				'size' => 100,
				'value' => $value,
				'maxlength' => $field_max_length,
				'validation' => $validation
			]);
		}
		else if ($this->get('qst_type') == Question::TYPE_LONG_TEXT){
			$formwriter->textbox($field_name, $question_text, [
				'rows' => 5,
				'cols' => 80,
				'value' => $value,
				'htmlmode' => 'no',
				'validation' => $validation
			]);
		}
		else if ($this->get('qst_type') == Question::TYPE_DROPDOWN){
			$options = new MultiQuestionOption(
				array('question_id'=> $this->key),
				NULL,		//SORT BY => DIRECTION
				NULL,  //NUM PER PAGE
				NULL);  //OFFSET
			$options->load();

			$optionvals = $options->get_dropdown_array();
			$formwriter->dropinput($field_name, $question_text, [
				'options' => $optionvals,
				'value' => $value,
				'showdefault' => true,
				'validation' => $validation
			]);
		}
		else if ($this->get('qst_type') == Question::TYPE_RADIO){
			$options = new MultiQuestionOption(
				array('question_id'=> $this->key),
				NULL,		//SORT BY => DIRECTION
				NULL,  //NUM PER PAGE
				NULL);  //OFFSET
			$options->load();

			$checkedval = NULL;
			$optionvals = $options->get_dropdown_array();
			$formwriter->radioinput($field_name, $question_text, [
				'options' => $optionvals,
				'value' => $value,
				'validation' => $validation
			]);
		}
		else if ($this->get('qst_type') == Question::TYPE_CHECKBOX){
			$options = new MultiQuestionOption(
				array('question_id'=> $this->key),
				NULL,		//SORT BY => DIRECTION
				NULL,  //NUM PER PAGE
				NULL);  //OFFSET
			$options->load();

			if (!$options->count()) {
				return;
			}
			$truevalue = $options->get(0)->get('qop_question_option_value');

			$formwriter->checkboxinput($field_name, $question_text, [
				'checked' => ($value == $truevalue),
				'value' => $truevalue,
				'validation' => $validation
			]);
		}
		else if ($this->get('qst_type') == Question::TYPE_CHECKBOX_LIST){
			$options = new MultiQuestionOption(
				array('question_id'=> $this->key),
				NULL,		//SORT BY => DIRECTION
				NULL,  //NUM PER PAGE
				NULL);  //OFFSET
			$options->load();

			$optionvals = $options->get_dropdown_array();
			$formwriter->checkboxlist($field_name, $question_text, [
				'options' => $optionvals,
				'checked' => $value,
				'validation' => $validation
			]);
		}
		else if ($this->get('qst_type') == Question::TYPE_CONFIRMATION){
			$config = json_decode($this->get('qst_config'), true) ?: [];
			$body_text = isset($config['body_text']) ? $config['body_text'] : null;
			$checkbox_label = isset($config['checkbox_label']) ? $config['checkbox_label'] : $this->get('qst_question');
			$scrollable = isset($config['scrollable']) ? $config['scrollable'] : false;

			if ($body_text) {
				if ($scrollable) {
					echo '<div class="sm:col-span-6">';
					echo '<label>' . htmlspecialchars($this->get('qst_question')) . '</label>';
					echo '<div style="overflow:auto; height: 100px; border: 1px solid #DDDAD3; width: 100%; padding: 6px; margin-bottom: 5px; background-color: #f5f5f5;">';
					echo $body_text;
					echo '</div>';
					echo '</div>';
				} else {
					echo '<div class="sm:col-span-6">';
					echo '<label>' . htmlspecialchars($this->get('qst_question')) . '</label>';
					echo '<div style="padding: 6px; margin-bottom: 5px;">';
					echo $body_text;
					echo '</div>';
					echo '</div>';
				}
			}

			$validation['required'] = true;
			$formwriter->checkboxinput($field_name, $checkbox_label, [
				'value' => '1',
				'checked' => ($value == '1'),
				'validation' => $validation
			]);
		}
	}

	//TAKES AN ANSWER AS INPUT AND RETURNS A HUMAN READABLE ANSWER, RETURN_SAFE OPTIONALLY ADDS ESCAPING
	function get_answer_readable($answer, $return_safe=true){
		$return_string = '';
		if ($this->get('qst_type') == Question::TYPE_SHORT_TEXT){
			$return_string = $answer;
		}
		else if ($this->get('qst_type') == Question::TYPE_LONG_TEXT){
			$return_string = $answer;
		}
		else if ($this->get('qst_type') == Question::TYPE_CONFIRMATION){
			$return_string = $answer ? 'Yes' : 'No';
		}
		else if ($this->get('qst_type') == Question::TYPE_CHECKBOX_LIST){
			$options = new MultiQuestionOption(
				array('question_id'=> $this->key),
				NULL,		//SORT BY => DIRECTION
				NULL,  //NUM PER PAGE
				NULL);  //OFFSET
			$options->load();
			
			$answers_out = array();
			$answers_pieces = explode(',', $answer);
			foreach($answers_pieces as $answers_piece){
				foreach($options as $option){
					if($answers_piece == $option->get('qop_question_option_value')){
						$answers_out[] = $option->get('qop_question_option_label') . '('.$option->get('qop_question_option_value').')';
					}
				}
			}
			
			//IF WE CAN'T FIND THAT QUESTION OPTION (MAYBE IT WAS DELETED), JUST RETURN THE ANSWER TEXT
			if(empty($answers_out)){
				$return_string = implode(", ", $answers_pieces);
			}
			else{
				$return_string =  implode(", ", $answers_out);
			}
		}
		else {
			$options = new MultiQuestionOption(
				array('question_id'=> $this->key),
				NULL,		//SORT BY => DIRECTION
				NULL,  //NUM PER PAGE
				NULL);  //OFFSET
			$options->load();
			
			foreach($options as $option){
				if($answer == $option->get('qop_question_option_value')){
					$return_string =  $option->get('qop_question_option_label') . '('.$option->get('qop_question_option_value').')';
				}
			}
			
			//IF WE CAN'T FIND THAT QUESTION OPTION (MAYBE IT WAS DELETED), JUST RETURN THE ANSWER TEXT
			if(empty($return_string)){
				$return_string = $answer;
			}

		}
		
		if($return_safe){
			return htmlspecialchars($return_string);
		}
		else{
			return $return_string;
		}
	}
	
	function get_question_options(){
		$options = new MultiQuestionOption(
			array('question_id' => $this->key),  //SEARCH CRITERIA
		);
		$options->load();
		return $options;
	}

	function prepare() {
		
		//CHECK TO MAKE SURE WE HAVE AT LEAST ONE OPTION FOR QUESTION TYPES WITH OPTIONS
		/*
		if ($this->get('qst_type') == Question::TYPE_DROPDOWN){
			$options = new MultiQuestionOption(
				array('question_id'=> $this->key),
				NULL,		//SORT BY => DIRECTION
				NULL,  //NUM PER PAGE
				NULL);  //OFFSET

			if(!$options->count_all()){
				throw new QuestionException(
				'This question "'.$this->get('qst_question').'" requires some answer options.');
				exit;
			}
		}
		else if ($this->get('qst_type') == Question::TYPE_RADIO){
			$options = new MultiQuestionOption(
				array('question_id'=> $this->key),
				NULL,		//SORT BY => DIRECTION
				NULL,  //NUM PER PAGE
				NULL);  //OFFSET
			if(!$options->count_all()){
				throw new QuestionException(
				'This question "'.$this->get('qst_question').'" requires some answer options.');
				exit;
			}		
		}
		else if ($this->get('qst_type') == Question::TYPE_CHECKBOX){
			$options = new MultiQuestionOption(
				array('question_id'=> $this->key),
				NULL,		//SORT BY => DIRECTION
				NULL,  //NUM PER PAGE
				NULL);  //OFFSET
			if(!$options->count_all()){
				throw new QuestionException(
				'This question "'.$this->get('qst_question').'" requires some answer options.');
				exit;
			}			
		}
		else if ($this->get('qst_type') == Question::TYPE_CHECKBOX_LIST){
			$options = new MultiQuestionOption(
				array('question_id'=> $this->key),
				NULL,		//SORT BY => DIRECTION
				NULL,  //NUM PER PAGE
				NULL);  //OFFSET
			if(!$options->count_all()){
				throw new QuestionException(
				'This question "'.$this->get('qst_question').'" requires some answer options.');
				exit;
			}			
		}*/
	}	

	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in '. static::$tablename);
		}
	}

}

class MultiQuestion extends SystemMultiBase {
	protected static $model_class = 'Question';

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $question) {
			$items[$question->key] = '('.$question->key.') '.$question->get('qst_question');
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		// Note: Invalid filters removed - qst_usr_user_id, qst_identifier, qst_link fields do not exist in model

		if (isset($this->options['published'])) {
			$filters['qst_is_published'] = $this->options['published'] ? "= TRUE" : "= FALSE";
		}

		if (isset($this->options['type'])) {
			$filters['qst_type'] = [$this->options['type'], PDO::PARAM_INT];
		}

		if (isset($this->options['deleted'])) {
			$filters['qst_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

		return $this->_get_resultsv2('qst_questions', $filters, $this->order_by, $only_count, $debug);
	}

}

?>
