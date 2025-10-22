<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class EmailRecipientException extends SystemBaseException {}

class EmailRecipient extends SystemBase {	public static $prefix = 'erc';
	public static $tablename = 'erc_email_recipients';
	public static $pkey_column = 'erc_email_recipient_id';
	public static $permanent_delete_actions = array(	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	// Status codes
	const EMAIL_SENT = 1;
	const UNSUBSCRIBED = 2;
	const SEND_FAILURE = 3;

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
	    'erc_email_recipient_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'erc_usr_user_id' => array('type'=>'int4'),
	    'erc_email' => array('type'=>'varchar(64)', 'required'=>true),
	    'erc_name' => array('type'=>'varchar(70)'),
	    'erc_eml_email_id' => array('type'=>'int4', 'required'=>true),
	    'erc_sent_time' => array('type'=>'timestamp(6)'),
	    'erc_status' => array('type'=>'int2'),
	);

	public static $field_constraints = array();	

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

	function get_name() { 
		if ($this->get('erc_name')) { 
			return $this->get('erc_name');	
		} else { 
			return '';
		}
	}

	function get_email() { 
		if ($this->get('erc_email')) { 
			return $this->get('erc_email');	
		} else { 
			return '';
		}
	}

	function is_sent() { 
		return $this->get('erc_status') == self::EMAIL_SENT;
	}

	function url_tracking_code() { 
		return 'erc=' . $this->tracking_code();
	}

	function tracking_code() { 
		return self::TrackingCodeForRecipient($this->key);
	}
	
	static function CheckIfExists($erc_eml_email_id, $erc_email) {
		
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$sql = 'SELECT COUNT(1) FROM erc_email_recipients WHERE erc_eml_email_id=:erc_eml_email_id AND LOWER(erc_email)=LOWER(:erc_email)';

		try{
			$q = $dblink->prepare($sql);
			$q->bindValue(':erc_eml_email_id', $erc_eml_email_id, PDO::PARAM_INT);
			$q->bindValue(':erc_email', $erc_email, PDO::PARAM_STR);

			$success = $q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}	
		
		return $q->fetch()->count;
	}
	
	static function CheckIfEmailed($erc_email, $time_since=NULL) {
		
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$sql = 'SELECT COUNT(1) FROM erc_email_recipients 
		INNER JOIN eml_emails ON erc_email_recipients.erc_eml_email_id = eml_emails.eml_email_id 
		WHERE LOWER(erc_email)=LOWER(:erc_email)';

		if($time_since) {
			$sql .= ' AND eml_sent_time > :eml_sent_time';
		}
		
		try{
			$q = $dblink->prepare($sql);
			$q->bindValue(':erc_email', $erc_email, PDO::PARAM_STR);
			if($time_since) {
				$q->bindValue(':eml_sent_time', $time_since, PDO::PARAM_STR);
			}
			
			$success = $q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}	
		
		return $q->fetch()->count;
	}	

	static function DeleteAll($erc_eml_email_id) {
		
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$sql = "DELETE FROM erc_email_recipients WHERE erc_eml_email_id=:erc_eml_email_id";

		try {
			$q = $dblink->prepare($sql);
			$q->bindValue(':erc_eml_email_id', $erc_eml_email_id, PDO::PARAM_INT);
			$q->execute();
		} catch (PDOException $e) {
			$dbhelper->handle_query_error($e);
		}	
	}	

	static function TrackingCodeForRecipient($erc_email_recipient_id) { 
		// Checksummed tracking code for this recipient
		return LibraryFunctions::EncodeWithChecksum($erc_email_recipient_id);
	}

	static function RecipientIdFromTrackingCode($code) { 
		return LibraryFunctions::DecodeWithChecksum($code);
	}
	
}

class MultiEmailRecipient extends SystemMultiBase {
	protected static $model_class = 'EmailRecipient';

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['user_id'])) {
            $filters['erc_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['email_id'])) {
            $filters['erc_eml_email_id'] = [$this->options['email_id'], PDO::PARAM_INT];
        }
        
        if (isset($this->options['user_email'])) {
            $filters['erc_email'] = [$this->options['user_email'], PDO::PARAM_STR];
        }

        if (isset($this->options['sent'])) {
            if ($this->options['sent']) {
                $filters['erc_sent_time'] = "IS NOT NULL";
            } else {
                $filters['erc_sent_time'] = "IS NULL";
            }
        }

        return $this->_get_resultsv2('erc_email_recipients', $filters, $this->order_by, $only_count, $debug);
    }
}

?>
