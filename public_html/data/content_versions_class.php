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

class ContentVersionException extends SystemClassException {}

class ContentVersion extends SystemBase {

	public static $prefix = 'cnv';
	public static $tablename = 'cnv_content_versions';
	public static $pkey_column = 'cnv_content_version_id';
	
	const TYPE_POST = 1;
	const TYPE_PAGE_CONTENT = 2;
	const TYPE_EMAIL = 3;
	const TYPE_EMAIL_TEMPLATE = 4;
	const TYPE_EVENT = 5;

	public static $fields = array(
		'cnv_content_version_id' => 'ID of the content_version',
		'cnv_title' => 'Title',
		'cnv_usr_user_id' => 'User who created the version',
		'cnv_description' => 'Description to recognize this version',
		'cnv_type' => 'Type of content, see above',
		'cnv_foreign_key_id' => 'Contains the foreign key to whatever table the version is for',
		'cnv_next_version_id' => 'Key of the next newer version',
		'cnv_previous_version_id' => 'Key of the previous version',
		'cnv_content' => 'Body of the content_version',
		'cnv_create_time' => 'Time Created',
		'cnv_delete_time' => 'Time Deleted'
	);

	public static $field_specifications = array(
		'cnv_content_version_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'cnv_title' => array('type'=>'varchar(255)'),
		'cnv_usr_user_id' => array('type'=>'int4'),
		'cnv_description' => array('type'=>'varchar(255)'),
		'cnv_type' => array('type'=>'varchar(255)'),
		'cnv_foreign_key_id' => array('type'=>'int4'),
		'cnv_next_version_id' => array('type'=>'int4'),
		'cnv_previous_version_id' => array('type'=>'int4'),
		'cnv_content' => array('type'=>'text'),
		'cnv_create_time' => array('type'=>'timestamp(6)'),
		'cnv_delete_time' => array('type'=>'timestamp(6)'),
	);
			
	public static $required_fields = array('cnv_foreign_key_id'
		);

	public static $field_constraints = array();	
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array(
	'cnv_create_time' => 'now()'
	);	
	
	function get_previous_version(){
		if($this->get('cnv_previous_version_id')){
			return new ContentVersion($this->get('cnv_previous_version_id'), TRUE);
		}
		else{
			return false;
		}
	}

	function get_next_version(){
		if($this->get('cnv_next_version_id')){
			return new ContentVersion($this->get('cnv_next_version_id'), TRUE);
		}
		else{
			return false;
		}
	}	
	
	static function NewVersion($type, $foreign_key_id, $content, $description=NULL, $title=NULL){
		$session = SessionControl::get_instance();
		$results = new MultiContentVersion(array('type' => $type, 'foreign_key_id' => $foreign_key_id), array('content_version_id' => 'DESC'));
		$numresult = $results->count_all();

		if($numresult){
			$results->load();
			$last_item = $results->get(0);
			$new_item = new ContentVersion(NULL);
			$new_item->set('cnv_title', $title);
			$new_item->set('cnv_description', $description);
			$new_item->set('cnv_type', $type);
			$new_item->set('cnv_content', $content);
			$new_item->set('cnv_foreign_key_id', $foreign_key_id);
			$new_item->set('cnv_previous_version_id', $last_item->key);
			if($session->get_user_id()){
				$new_item->set('cnv_usr_user_id', $session->get_user_id());
			}
			$new_item->prepare();
			$new_item->save();
			$new_item->load();
			
			$last_item->set('cnv_next_version_id', $new_item->key);
			$last_item->save();
			
		}
		else{
			$new_item = new ContentVersion(NULL);
			$new_item->set('cnv_title', $title);
			$new_item->set('cnv_description', $description);
			$new_item->set('cnv_type', $type);
			$new_item->set('cnv_content', $content);
			$new_item->set('cnv_foreign_key_id', $foreign_key_id);
			if($session->get_user_id()){
				$new_item->set('cnv_usr_user_id', $session->get_user_id());
			}
			$new_item->prepare();
			$new_item->save();
			$new_item->load();
		}

	}
	
	
	function authenticate_write($session, $other_data=NULL) {
		if ($session->get_permission() < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this content_version.');
		}
	}

	
	function permanent_delete(){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();	
		$dblink->beginTransaction();
		
		$next_version = $this->get_next_version();
		$previous_version = $this->get_previous_version();
		
		if($next_version && $previous_version){
			$next_version->set('cnv_previous_version_id', $previous_version->key);
			$next_version->save();

			$previous_version->set('cnv_next_version_id', $next_version->key);
			$previous_version->save();			
		}
		else if($previous_version){
			$previous_version->set('cnv_next_version_id', NULL);
			$previous_version->save();	
		}
		else if($next_version){
			$next_version->set('cnv_previous_version_id', NULL);
			$next_version->save();	
		}
		
		parent::permanent_delete();
		
		$dblink->commit();
		
		return true;		
	}

	
}

class MultiContentVersion extends SystemMultiBase {


	function get_dropdown_array($include_new=FALSE, $session) {
		$items = array();
		foreach($this as $content_version) {
			if($content_version->get('cnv_description')){
				$items[$content_version->get('cnv_description'). ' - ' .  LibraryFunctions::convert_time($content_version->get('cnv_create_time'), 'UTC', $session->get_timezone())] = $content_version->key;
			}
			else{
				$items[LibraryFunctions::convert_time($content_version->get('cnv_create_time'), 'UTC', $session->get_timezone())] = $content_version->key;				
			}
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
		 	$where_clauses[] = 'cnv_usr_user_id = ?';
		 	$bind_params[] = array($this->options['user_id'], PDO::PARAM_INT);
		} 
		
		if (array_key_exists('type', $this->options)) {
		 	$where_clauses[] = 'cnv_type = ?';
		 	$bind_params[] = array($this->options['type'], PDO::PARAM_INT);
		} 

		if (array_key_exists('foreign_key_id', $this->options)) {
		 	$where_clauses[] = 'cnv_foreign_key_id = ?';
		 	$bind_params[] = array($this->options['foreign_key_id'], PDO::PARAM_INT);
		} 		
				
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) FROM cnv_content_versions ' . $where_clause;
		} 
		else {
			$sql = 'SELECT * FROM cnv_content_versions
				' . $where_clause . '
				ORDER BY ';

			if (!$this->order_by) {
				$sql .= " cnv_content_version_id ASC ";
			}
			else {
				if (array_key_exists('content_version_id', $this->order_by)) {
					$sql .= ' cnv_content_version_id ' . $this->order_by['content_version_id'];
				}	

				if (array_key_exists('create_time', $this->order_by)) {
					$sql .= ' cnv_create_time ' . $this->order_by['create_time'];
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
			$child = new ContentVersion($row->cnv_content_version_id);
			$child->load_from_data($row, array_keys(ContentVersion::$fields));
			$this->add($child);
		}
	}

}


?>
