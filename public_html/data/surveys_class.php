<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

require_once(PathHelper::getIncludePath('data/survey_answers_class.php'));

class SurveyException extends SystemBaseException {}

class Survey extends SystemBase {	public static $prefix = 'svy';
	public static $tablename = 'svy_surveys';
	public static $pkey_column = 'svy_survey_id';

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
	    'svy_survey_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'svy_name' => array('type'=>'varchar(255)'),
	    'svy_edited_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'svy_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'svy_delete_time' => array('type'=>'timestamp(6)'),
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
	protected static $model_class = 'Survey';

	function get_survey_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $survey) {
			$items[$survey->key] = $survey->get('svy_name');
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

}

?>
