<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class ContentVersionException extends SystemBaseException {}

/**
 * ContentVersion — one saved edit of an entity (post, page, template, event,
 * location, item, agent file), keyed by cvn_type + cvn_foreign_key_id and
 * chained through cvn_previous_version_id / cvn_next_version_id.
 *
 * @version 1.1 - prefix cvn, table cvn_content_versions: cnv is Conversation's alone
 *   (specs/implemented/shared_prefix_content_version.md)
 */
class ContentVersion extends SystemBase {
	public static $prefix = 'cvn';
	public static $tablename = 'cvn_content_versions';
	public static $pkey_column = 'cvn_content_version_id';

	protected static $foreign_key_actions = array(
		'cvn_usr_user_id' => array('action' => 'set_value', 'value' => User::USER_DELETED),
	);

	const TYPE_POST = 1;
	const TYPE_PAGE_CONTENT = 2;
	const TYPE_EMAIL = 3;
	const TYPE_EMAIL_TEMPLATE = 4;
	const TYPE_EVENT = 5;
	const TYPE_PAGE = 6;
	const TYPE_LOCATION = 7;
	const TYPE_ITEM = 8;
	const TYPE_AGENT_FILE = 9;

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
	    'cvn_content_version_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'cvn_title' => array('type'=>'varchar(255)'),
	    'cvn_usr_user_id' => array('type'=>'int4'),
	    'cvn_description' => array('type'=>'varchar(255)'),
	    'cvn_type' => array('type'=>'varchar(255)'),
	    'cvn_foreign_key_id' => array('type'=>'int4', 'required'=>true),
	    'cvn_next_version_id' => array('type'=>'int4'),
	    'cvn_previous_version_id' => array('type'=>'int4'),
	    'cvn_content' => array('type'=>'text'),
	    'cvn_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'cvn_delete_time' => array('type'=>'timestamp(6)'),
	);

function get_previous_version(){
		if($this->get('cvn_previous_version_id')){
			return new ContentVersion($this->get('cvn_previous_version_id'), TRUE);
		}
		else{
			return false;
		}
	}

	function get_next_version(){
		if($this->get('cvn_next_version_id')){
			return new ContentVersion($this->get('cvn_next_version_id'), TRUE);
		}
		else{
			return false;
		}
	}	
	
	const MAX_VERSIONS_PER_ITEM = 100;

	static function NewVersion($type, $foreign_key_id, $content, $description=NULL, $title=NULL){
		$session = SessionControl::get_instance();
		$results = new MultiContentVersion(array('type' => $type, 'foreign_key_id' => $foreign_key_id), array('content_version_id' => 'DESC'));
		$numresult = $results->count_all();

		if($numresult){
			$results->load();
			$last_item = $results->get(0);
			$new_item = new ContentVersion(NULL);
			$new_item->set('cvn_title', $title);
			$new_item->set('cvn_description', $description);
			$new_item->set('cvn_type', $type);
			$new_item->set('cvn_content', $content);
			$new_item->set('cvn_foreign_key_id', $foreign_key_id);
			$new_item->set('cvn_previous_version_id', $last_item->key);
			if($session->get_user_id()){
				$new_item->set('cvn_usr_user_id', $session->get_user_id());
			}
			$new_item->prepare();
			$new_item->save();
			$new_item->load();

			$last_item->set('cvn_next_version_id', $new_item->key);
			$last_item->save();

		}
		else{
			$new_item = new ContentVersion(NULL);
			$new_item->set('cvn_title', $title);
			$new_item->set('cvn_description', $description);
			$new_item->set('cvn_type', $type);
			$new_item->set('cvn_content', $content);
			$new_item->set('cvn_foreign_key_id', $foreign_key_id);
			if($session->get_user_id()){
				$new_item->set('cvn_usr_user_id', $session->get_user_id());
			}
			$new_item->prepare();
			$new_item->save();
			$new_item->load();
		}

		// Prune old versions if over limit
		self::pruneOldVersions($type, $foreign_key_id);
	}

	/**
	 * Delete oldest versions if count exceeds MAX_VERSIONS_PER_ITEM
	 */
	static function pruneOldVersions($type, $foreign_key_id) {
		$versions = new MultiContentVersion(
			array('type' => $type, 'foreign_key_id' => $foreign_key_id),
			array('content_version_id' => 'ASC') // Oldest first
		);
		$count = $versions->count_all();

		if ($count > self::MAX_VERSIONS_PER_ITEM) {
			$to_delete = $count - self::MAX_VERSIONS_PER_ITEM;
			$versions->load();

			for ($i = 0; $i < $to_delete; $i++) {
				$old_version = $versions->get($i);
				if ($old_version) {
					$old_version->permanent_delete();
				}
			}
		}
	}

	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in '. static::$tablename);
		}
	}

	function permanent_delete($debug=false){
		DbConnector::BeginTransaction();
		
		$next_version = $this->get_next_version();
		$previous_version = $this->get_previous_version();
		
		if($next_version && $previous_version){
			$next_version->set('cvn_previous_version_id', $previous_version->key);
			$next_version->save();

			$previous_version->set('cvn_next_version_id', $next_version->key);
			$previous_version->save();			
		}
		else if($previous_version){
			$previous_version->set('cvn_next_version_id', NULL);
			$previous_version->save();	
		}
		else if($next_version){
			$next_version->set('cvn_previous_version_id', NULL);
			$next_version->save();	
		}
		
		parent::permanent_delete();
		
		DbConnector::Commit();
		
		return true;		
	}

}

class MultiContentVersion extends SystemMultiBase {
	protected static $model_class = 'ContentVersion';

	function get_dropdown_array($session, $include_new=FALSE) {
		$items = array();
		foreach($this as $content_version) {
			if($content_version->get('cvn_description')){
				$items[$content_version->key] = $content_version->get('cvn_description'). ' - ' .  $content_version->get_local('cvn_create_time');
			}
			else{
				$items[$content_version->key] = $content_version->get_local('cvn_create_time');
			}
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['user_id'])) {
            $filters['cvn_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
        }
        
        if (isset($this->options['type'])) {
            $filters['cvn_type'] = [$this->options['type'], PDO::PARAM_INT];
        }

        if (isset($this->options['foreign_key_id'])) {
            $filters['cvn_foreign_key_id'] = [$this->options['foreign_key_id'], PDO::PARAM_INT];
        }

        return $this->_get_resultsv2('cvn_content_versions', $filters, $this->order_by, $only_count, $debug);
    }

}

?>
