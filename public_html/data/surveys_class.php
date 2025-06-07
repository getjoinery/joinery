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

require_once($siteDir.'/data/survey_answers_class.php');

class SurveyException extends SystemClassException {}

class Survey extends SystemBase {
	public static $prefix = 'svy';
	public static $tablename = 'svy_surveys';
	public static $pkey_column = 'svy_survey_id';
	public static $permanent_delete_actions = array(
		'svy_survey_id' => 'delete',	
		'srq_svy_survey_id' => 'prevent',
		'sva_svy_survey_id' => 'prevent',
		'evt_svy_survey_id' => 'prevent',
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value

	public static $fields = array(
		'svy_survey_id' => 'ID of the survey',
		'svy_name' => 'The survey',
		'svy_edited_time' => 'Last edit',
		'svy_create_time' => 'Time Created',
		'svy_delete_time' => 'Time of deletion',
	);

	public static $field_specifications = array(
		'svy_survey_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'svy_name' => array('type'=>'varchar(255)'),
		'svy_edited_time' => array('type'=>'timestamp(6)'),
		'svy_create_time' => array('type'=>'timestamp(6)'),
		'svy_delete_time' => array('type'=>'timestamp(6)'),
	);
	
	public static $required_fields = array(
		);

	public static $field_constraints = array();	
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array(
	'svy_create_time' => 'now()', 
	'svy_edited_time' => 'now()'
	);	

	function get_users_who_answered() {

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();	
		$sql = 'SELECT count(*) as count, sva_usr_user_id FROM sva_survey_answers WHERE sva_svy_survey_id='.$this->key.' GROUP BY sva_usr_user_id';
		$q = $dblink->prepare($sql);
		$success = $q->execute();
		$q->setFetchMode(PDO::FETCH_OBJ);

		return $q;
	}	

	function get_num_users_who_answered() {

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();	
		$sql = 'SELECT COUNT(DISTINCT sva_usr_user_id) as count FROM sva_survey_answers WHERE sva_svy_survey_id='.$this->key;
		$q = $dblink->prepare($sql);
		$success = $q->execute();
		$q->setFetchMode(PDO::FETCH_OBJ);

		return $q->fetch()->count;;
	}	
	
	function authenticate_write($data) {
		if ($this->get(static::$prefix.'_usr_user_id') != $data['current_user_id']) {
			// If the user's ID doesn't match, we have to make
			// sure they have admin access, otherwise denied.
			if ($data['current_user_permission'] < 5) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to edit this entry in '. static::$tablename);
			}
		}
	}
	
}

class MultiSurvey extends SystemMultiBase {


	function get_survey_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $survey) {
			$items[$survey->get('svy_name')] = $survey->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        
        if (isset($this->options['deleted'])) {
            $filters['svy_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
        }
        
        return $this->_get_resultsv2('svy_surveys', $filters, $this->order_by, $only_count, $debug);
    }
    
    function load($debug = false) {
        parent::load();
        $q = $this->getMultiResults(false, $debug);
        foreach($q->fetchAll() as $row) {
            $child = new Survey($row->svy_survey_id);
            $child->load_from_data($row, array_keys(Survey::$fields));
            $this->add($child);
        }
    }
    
    function count_all($debug = false) {
        $q = $this->getMultiResults(TRUE, $debug);
        return $q;
    }

}


?>
