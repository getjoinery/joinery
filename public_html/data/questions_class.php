<?php
$settings = Globalvars::get_instance();
$siteDir = $settings->get_setting('siteDir');
require_once($siteDir . '/includes/DbConnector.php');
require_once($siteDir . '/includes/FieldConstraints.php');
require_once($siteDir . '/includes/Globalvars.php');
require_once($siteDir . '/includes/LibraryFunctions.php');
require_once($siteDir . '/includes/SingleRowAccessor.php');
require_once($siteDir . '/includes/SystemClass.php');
require_once($siteDir . '/includes/Validator.php');

require_once(LibraryFunctions::get_theme_file_path('FormWriterPublicTW.php', '/includes'));

class QuestionException extends SystemClassException {}

class Question extends SystemBase {
	
	public static $prefix = 'qst';
	public static $tablename = 'qst_questions';
	public static $pkey_column = 'qst_question_id';
	public static $permanent_delete_actions = array(
		'qop_qst_question_id' => 'delete', 
		'qst_question_id' => 'delete', 
		'srq_qst_question_id' => 'prevent',
		'sva_qst_question_id' => 'prevent',
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	const LANGUAGE_ENGLISH = 1;
	
	const TYPE_SHORT_TEXT = 1;
	const TYPE_LONG_TEXT = 2;
	const TYPE_DROPDOWN = 3;
	const TYPE_RADIO = 4;
	const TYPE_CHECKBOX = 5;
	const TYPE_CHECKBOX_LIST = 6;

	public static $fields = array(
		'qst_question_id' => 'ID of the question',
		'qst_translation_of_question_id' => 'If this is a translation, id of the english question',
		'qst_language' => 'Integer representing language',
		'qst_question' => 'Question',
		'qst_options' => 'Array of options',
		'qst_validate' => 'Array of validation options',
		'qst_type' => 'see types above',
		'qst_is_published' => 'Is this question published?',
		'qst_published_time' => 'Time published',
		'qst_create_time' => 'Time Created',
		'qst_delete_time' => 'Time deleted'
	);

	public static $field_specifications = array(
		'qst_question_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'qst_translation_of_question_id' => array('type'=>'int4'),
		'qst_language' => array('type'=>'int4'),
		'qst_question' => array('type'=>'text'),
		'qst_options' => array('type'=>'text'),
		'qst_validate' => array('type'=>'varchar(255)'),
		'qst_type' => array('type'=>'int4'),
		'qst_is_published' => array('type'=>'bool'),
		'qst_published_time' => array('type'=>'timestamp(6)'),
		'qst_create_time' => array('type'=>'timestamp(6)'),
		'qst_delete_time' => array('type'=>'timestamp(6)'),
	);
	
	public static $required_fields = array(
		);

	public static $field_constraints = array();	
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array(
	'qst_create_time' => 'now()', 'qst_is_published' => true
	);	


	function output_js_validation($validation_rules){
		$validation_options = unserialize($this->get('qst_validate')); 
		
		if ($this->get('qst_type') == Question::TYPE_CHECKBOX_LIST){
			$question_name = '"question_'.$this->key.'[]"';
		}
		else{
			$question_name = 'question_'.$this->key;
		}
		
		if(array_key_exists('required', $validation_options)){
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
		$validation_options = unserialize($this->get('qst_validate')); 
		if(array_key_exists('required', $validation_options)){
			if($answers == '' || $answers === NULL || count($answers) == 0){
				return 'You did not answer this question: '. $this->get('qst_question');
			}
		}
		if(array_key_exists('integer', $validation_options)){
			if(!is_integer($answers)){
				return 'This answer to this question needs to be a number: '. $answers;
			}			
		}
		if(array_key_exists('decimal', $validation_options)){
			if(!preg_match('/^\d+\.\d+$/',$number)){
				return 'This answer to this question needs to be a decimal number: '. $answers;
			}				
		}
		if(array_key_exists('max_length', $validation_options)){
			if(strlen($answers) > $validation_options['max_length']){
				return 'This answer to this question is too long: '. $answers;
			}
		}	
		if(array_key_exists('min_length', $validation_options)){
			if(strlen($answers) < $validation_options['min_length']){
				return 'This answer to this question is too short: '. $answers;
			}
		}
		if(array_key_exists('max_value', $validation_options)){
			if($answers > $validation_options['max_value']){
				return 'This answer to this question is too large: '. $answers;
			}
		}
		if(array_key_exists('min_value', $validation_options)){
			if($answers < $validation_options['min_value']){
				return 'This answer to this question is too small: '. $answers;
			}
		}		
		return 'valid';
	}
	
	function output_question($formwriter, $value=NULL){
		$field_name = 'question_'.$this->key;
		$field_max_length = 255;
		if($this->get('max_length')){
			$field_max_length = $this->get('max_length');
		}
		
		if ($this->get('qst_type') == Question::TYPE_SHORT_TEXT){
			return $formwriter->textinput($this->get('qst_question'), $field_name , NULL, 100, $value, '', $field_max_length, '');
		}
		else if ($this->get('qst_type') == Question::TYPE_LONG_TEXT){
			return $formwriter->textbox($this->get('qst_question'), $field_name, NULL, 5, 80, $value, '', 'no');
		}
		else if ($this->get('qst_type') == Question::TYPE_DROPDOWN){
			$options = new MultiQuestionOption(
				array('deleted'=>false, 'question_id'=> $this->key),
				NULL,		//SORT BY => DIRECTION
				NULL,  //NUM PER PAGE
				NULL);  //OFFSET
			$options->load();
			
			$optionvals = $options->get_dropdown_array();
			echo $formwriter->dropinput($this->get('qst_question'), $field_name, NULL, $optionvals, $value, '', TRUE);
		}
		else if ($this->get('qst_type') == Question::TYPE_RADIO){
			$options = new MultiQuestionOption(
				array('deleted'=>false, 'question_id'=> $this->key),
				NULL,		//SORT BY => DIRECTION
				NULL,  //NUM PER PAGE
				NULL);  //OFFSET
			$options->load();

			$checkedval = NULL;
			$optionvals = $options->get_dropdown_array();
			echo $formwriter->radioinput($this->get('qst_question'), $field_name, "radioinput", $optionvals, $value, NULL, "", NULL);		
		}
		else if ($this->get('qst_type') == Question::TYPE_CHECKBOX){
			$options = new MultiQuestionOption(
				array('deleted'=>false, 'question_id'=> $this->key),
				NULL,		//SORT BY => DIRECTION
				NULL,  //NUM PER PAGE
				NULL);  //OFFSET
			$options->load();
			$truevalue = $options->get(0)->get('qop_question_option_value');
			//TODO ERROR CHECKING HERE 
			echo $formwriter->checkboxinput($this->get('qst_question'), $field_name, NULL, NULL, $value, $truevalue, '');			
		}
		else if ($this->get('qst_type') == Question::TYPE_CHECKBOX_LIST){
			$options = new MultiQuestionOption(
				array('deleted'=>false, 'question_id'=> $this->key),
				NULL,		//SORT BY => DIRECTION
				NULL,  //NUM PER PAGE
				NULL);  //OFFSET
			$options->load();
			
			$optionvals = $options->get_dropdown_array();
			echo $formwriter->checkboxlist($this->get('qst_question'), $field_name, NULL, $optionvals, $value, '', TRUE);			
		}
	}
	
	function get_question_options(){
		$options = new MultiQuestionOption(
			array('question_id' => $this->key),  //SEARCH CRITERIA
		);
		$options->load();
		return $options;
	}
	
	function get_tags($return_type = 'name'){ 
		$tags = array();
		$group_members = new MultiGroupMember(
			array('question_id' => $this->key),  //SEARCH CRITERIA
		);
		$group_members->load();

		foreach ($group_members as $group_member){
			$group = new Group($group_member->get('grm_grp_group_id'), TRUE);
			if($return_type == 'name'){
				$tags[] = $group->get('grp_name');
			}
			else{
				$tags[] = $group->key;
			}
		}	
		return $tags;
	}	

	
	function save_tags($tags_array){
		if(count($tags_array) == 0){
			return false;
		}
		
		$session = SessionControl::get_instance();
		//OLD TAGS
		$question_tag_ids = $this->get_tags('id');
		foreach ($question_tag_ids as $question_tag_id){
			$group = new Group($question_tag_id, TRUE);
			$group->remove_member(NULL, NULL, $this->key);
		}		
		
		//NEW TAGS
		foreach ($tags_array as $tag){
			$tag = trim($tag);
			$tag = preg_replace("/[^A-Za-z0-9 -_]/", '', $tag);
			
			if(!$group = Group::get_by_name($tag)){
				$group = Group::add_group($tag, $session->get_user_id(), Group::GROUP_TYPE_POST_TAG);
			}
			$group->add_member(NULL, NULL, $this->key);
		}		
	}	


	function prepare() {

	}	
	
	
	function authenticate_write($session, $other_data=NULL) {

		if ($session->get_permission() < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this question.');
		}
		
	}

	
}

class MultiQuestion extends SystemMultiBase {


	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $question) {
			$items['('.$question->key.') '.$question->get('qst_question')] = $question->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('user_id', $this->options)) {
		 	$where_clauses[] = 'qst_usr_user_id = ?';
		 	$bind_params[] = array($this->options['user_id'], PDO::PARAM_INT);
		} 
		
		if (array_key_exists('link', $this->options)) {
			$where_clauses[] = 'qst_link = ?';
			$bind_params[] = array($this->options['link'], PDO::PARAM_STR);
		}			

		if (array_key_exists('published', $this->options)) {
		 	$where_clauses[] = 'qst_is_published = ' . ($this->options['published'] ? 'TRUE' : 'FALSE');
		}
		
		if (array_key_exists('deleted', $this->options)) {
		 	$where_clauses[] = 'qst_delete_time IS ' . ($this->options['deleted'] ? 'NOT NULL' : 'NULL');
		} 
				
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) FROM qst_questions ' . $where_clause;
		} 
		else {
			$sql = 'SELECT * FROM qst_questions
				' . $where_clause . '
				ORDER BY ';

			if (!$this->order_by) {
				$sql .= " qst_question_id ASC ";
			}
			else {
				if (array_key_exists('question_id', $this->order_by)) {
					$sql .= ' qst_question_id ' . $this->order_by['question_id'];
				}			
			}
			
			$sql .= ' '.$this->generate_limit_and_offset();	
		}

		$q = DbConnector::GetPreparedStatement($sql);

		if($debug){
			echo $sql. "<br>\n";
			print_r($this->options);
		}

		$total_params = count($bind_params);
		for ($i=0; $i<$total_params; $i++) {
			list($param, $type) = $bind_params[$i];
			$q->bindValue($i+1, $param, $type);
		}
		$q->execute();
		$q->setFetchMode(PDO::FETCH_OBJ);

		return $q;
	}

	function load($debug = false) {
		parent::load();
		$q = $this->_get_results(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new Question($row->qst_question_id);
			$child->load_from_data($row, array_keys(Question::$fields));
			$this->add($child);
		}
	}

}


?>
