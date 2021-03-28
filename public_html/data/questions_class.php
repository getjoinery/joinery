<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FieldConstraints.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SingleRowAccessor.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SystemClass.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Validator.php');

require_once(LibraryFunctions::get_theme_path().'/includes/FormWriterPublic.php');

class QuestionException extends SystemClassException {}

class Question extends SystemBase {
	
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

	public static $constants = array();

	public static $required = array(
		);

	public static $field_constraints = array();	
	
	public static $zero_variables = array();
	
	public static $default_values = array(
	'qst_create_time' => 'now()'
	);	

	static function check_if_exists($key) {
		$data = SingleRowFetch('qst_questions', 'qst_question_id',
			$key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);
		if ($data === NULL) {
			return FALSE;
		}
		else{
			return TRUE;
		}
	}

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
			return $formwriter->textbox($this->get('qst_question'), $field_name, 'ctrlHolder', 5, 80, $value, '', 'no');
		}
		else if ($this->get('qst_type') == Question::TYPE_DROPDOWN){
			$options = new MultiQuestionOption(
				array('deleted'=>false, 'question_id'=> $this->key),
				NULL,		//SORT BY => DIRECTION
				NULL,  //NUM PER PAGE
				NULL);  //OFFSET
			$options->load();
			
			$optionvals = $options->get_dropdown_array();
			echo $formwriter->dropinput($this->get('qst_question'), $field_name, "ctrlHolder", $optionvals, $value, '', TRUE);
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
			echo $formwriter->checkboxinput($this->get('qst_question'), $field_name, "ctrlHolder", NULL, $value, $truevalue, '');			
		}
		else if ($this->get('qst_type') == Question::TYPE_CHECKBOX_LIST){
			$options = new MultiQuestionOption(
				array('deleted'=>false, 'question_id'=> $this->key),
				NULL,		//SORT BY => DIRECTION
				NULL,  //NUM PER PAGE
				NULL);  //OFFSET
			$options->load();
			
			$optionvals = $options->get_dropdown_array();
			echo $formwriter->checkboxlist($this->get('qst_question'), $field_name, "ctrlHolder", $optionvals, $value, '', TRUE);			
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

	function load() {
		parent::load();
		$this->data = SingleRowFetch('qst_questions', 'qst_question_id',
			$this->key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);
		if ($this->data === NULL) {
			throw new QuestionException(
				'This question does not exist');
		}
	}
	
	function prepare() {
		if ($this->data === NULL) {
			throw new QuestionException('This has no data.');
		}
		

		if ($this->key === NULL) {
			foreach (static::$zero_variables as $variable) {
				if ($this->key === NULL && $this->get($variable) === NULL) {
					$this->set($variable, 0);
				}
			}

		}
		
		if ($this->key === NULL) {
			foreach (static::$default_values as $variable=>$value) {
				if ($this->key === NULL && $this->get($variable) === NULL) { 
					$this->set($variable, $value);
				}
			}
		}		

		CheckRequiredFields($this, self::$required, self::$fields);

		foreach (self::$field_constraints as $field => $constraints) {
			foreach($constraints as $constraint) {
				if (gettype($constraint) == 'array') {
					$params = array();
					$params[] = self::$fields[$field];
					$params[] = $this->get($field);
					for($i=1;$i<count($constraint);$i++) {
						$params[] = $constraint[$i];
					}
					call_user_func_array($constraint[0], $params);
				} else {
					call_user_func($constraint, self::$fields[$field], $this->get($field));
				}
			}
		}

	}	
	
	
	function authenticate_write($session, $other_data=NULL) {

		if ($session->get_permission() < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this question.');
		}
		
	}

	function save() {
		$rowdata = array();
		foreach(array_keys(self::$fields) as $field) {
			$rowdata[$field] = $this->get($field);
		}

		if ($this->key) {
			$p_keys = array('qst_question_id' => $this->key);
			// Editing an existing record
		} else {
			$p_keys = NULL;
			// Creating a new record
			unset($rowdata['qst_question_id']);
			$rowdata['qst_create_time'] = 'now()';
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		$p_keys_return = LibraryFunctions::edit_table(
			$dbhelper, $dblink, 'qst_questions', $p_keys, $rowdata, FALSE, 0);

		$this->key = $p_keys_return['qst_question_id'];
	}

	function soft_delete(){
		$this->set('qst_delete_time', 'now()');
		$this->save();
		return true;
	}
	
	function undelete(){
		$this->set('qst_delete_time', NULL);
		$this->save();	
		return true;
	}
	
	function permanent_delete(){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$comments = new MultiComment(
		array('question_id'=>$this->key),
		NULL,
		NULL,
		NULL);
		$comments->load();
		
		foreach ($comments as $comment){
			$comment->permanent_delete();
		}

		$sql = 'DELETE FROM qst_questions WHERE qst_question_id=:qst_question_id';
		try{
			$q = $dblink->prepare($sql);
			$q->bindParam(':qst_question_id', $this->key, PDO::PARAM_INT);
			$count = $q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}
		
		$this->key = NULL;
		
		return true;		
	}
	
	static function InitDB($mode='structure'){
	
		try{
			$sql = '
				CREATE SEQUENCE IF NOT EXISTS qst_questions_qst_question_id_seq
				INCREMENT BY 1
				NO MAXVALUE
				NO MINVALUE
				CACHE 1;';
			$q = $dblink->prepare($sql);
			$success = $q->execute();
		}
		catch  (Exception $e){
			//SKIP
		}			
	
		$sql = '
			CREATE TABLE IF NOT EXISTS "public"."qst_questions" (
			  "qst_question_id" int4 NOT NULL DEFAULT nextval(\'qst_questions_qst_question_id_seq\'::regclass),
			  "qst_translation_of_question_id" int4,
			  "qst_language" int4,
			  "qst_question" text COLLATE "pg_catalog"."default",
			  "qst_options" text COLLATE "pg_catalog"."default",
			  "qst_validate" varchar(255) COLLATE "pg_catalog"."default",
			  "qst_type" int4,
			  "qst_is_published" bool DEFAULT true,
			  "qst_is_on_homepage" bool DEFAULT true,
			  "qst_create_time" timestamp(6),
			  "qst_published_time" timestamp(6),
			  "qst_delete_time" timestamp(6),
			)
			;';
		$q = $dblink->prepare($sql);
		$success = $q->execute();
		
		try{		
			$sql = 'ALTER TABLE "public"."qst_questions" ADD CONSTRAINT "qst_questions_pkey" PRIMARY KEY ("qst_question_id");';
			$q = $dblink->prepare($sql);
			$success = $q->execute();
		}
		catch  (Exception $e){
			//SKIP
		}

		/*
		try{		
			$sql = 'CREATE INDEX CONCURRENTLY qst_questions_qst_link ON qst_questions USING HASH (qst_link);';
			$q = $dburl->prepare($sql);
			$success = $q->execute();
		}
		catch  (Exception $e){
			//SKIP
		}
		*/
	
		//FOR FUTURE
		//ALTER TABLE table_name ADD COLUMN IF NOT EXISTS column_name INTEGER;
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

	private function _get_results($only_count=FALSE) { 
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

		$total_params = count($bind_params);
		for ($i=0; $i<$total_params; $i++) {
			list($param, $type) = $bind_params[$i];
			$q->bindValue($i+1, $param, $type);
		}
		$q->execute();
		$q->setFetchMode(PDO::FETCH_OBJ);

		return $q;
	}

	function load() {
		$q = $this->_get_results();
		foreach($q->fetchAll() as $row) {
			$child = new Question($row->qst_question_id);
			$child->load_from_data($row, array_keys(Question::$fields));
			$this->add($child);
		}
	}

	function count_all() {
		$q = $this->_get_results(TRUE);
		$counter = $q->fetch();
		return $counter->count;
	}
}


?>
