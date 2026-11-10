<?php

require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

require_once(PathHelper::getIncludePath('data/users_class.php'));

class MailingListRegistrantException extends SystemBaseException {}

class MailingListRegistrant extends SystemBase {	public static $prefix = 'mlr';

	public static $tablename = 'mlr_mailing_list_registrants';
	public static $access_class = 'personal';
	public static $pkey_column = 'mlr_mailing_list_registrant_id';

	// REST CRUD exposure (Layer 1). User-owned (Bucket B): readable + writable
	// under the deny-by-default owner-or-staff row scope.
	public static $api_readable = true;
	public static $api_writable = true;

	// AI auto-discovery (read)
	public static $ai_readable        = true;
	public static $ai_description     = 'The user\'s mailing-list subscription records.';
	public static $ai_excluded_fields = [];

	protected static $foreign_key_actions = [
		'mlr_mlt_mailing_list_id' => ['action' => 'prevent', 'message' => 'Cannot delete mailing list - registrants exist'],
		// Each subscription is deleted through its own permanent_delete(),
		// which also tells the list's provider (spec email_lists_plugin G11).
		'mlr_usr_user_id' => ['action' => 'permanent_delete']
	];

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
	    'mlr_mailing_list_registrant_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'mlr_mlt_mailing_list_id' => array('type'=>'int4', 'required'=>true),
	    'mlr_usr_user_id' => array('type'=>'int4', 'required'=>true),
	    'mlr_change_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'mlr_delete_time' => array('type'=>'timestamp(6)'),
	    // The consent behind the subscription (spec email_lists_plugin §10):
	    // when, how, from where, by whom (an admin's), and the address that
	    // confirmed. Mail goes only while that address is still the account's.
	    'mlr_confirm_time' => array('type'=>'timestamp(6)'),
	    'mlr_confirm_source' => array('type'=>'varchar(32)', 'required'=>true),
	    'mlr_confirm_ip' => array('type'=>'inet'),
	    'mlr_confirm_by_usr_user_id' => array('type'=>'int4'),
	    'mlr_confirm_email' => array('type'=>'varchar(255)'),
	);

	/**
	 * How a subscription was confirmed. Only the plugin writes these, through
	 * MailingList::add_registrant(); the API can write none of them, nor bring
	 * back a deleted subscription.
	 */
	const SOURCE_LINK           = 'link';            // the confirmation link's button
	const SOURCE_ACTIVATION     = 'activation';      // registration's box, confirmed by verifying the account
	const SOURCE_VERIFIED_OWNER = 'verified_owner';  // the signed-in owner of a verified address
	const SOURCE_PROVIDER       = 'provider';        // the list's provider (MailChimp) holds the consent
	const SOURCE_ADMIN          = 'admin';           // an admin, for consent collected elsewhere
	const SOURCE_SINGLE_OPT_IN  = 'single_opt_in';   // double opt-in turned off
	const SOURCE_EXISTING       = 'existing';        // subscribed before consent was recorded

	public static $api_unwritable_fields = array(
		'mlr_confirm_time', 'mlr_confirm_source', 'mlr_confirm_ip', 'mlr_confirm_by_usr_user_id',
		'mlr_confirm_email', 'mlr_delete_time',
	);

	// Which list and whose: set when the subscription is made, never moved
	// after, or a member could carry a confirmed row onto another list.
	public static $api_create_only_fields = array('mlr_mlt_mailing_list_id', 'mlr_usr_user_id');

	/**
	 * Set by record_consent() for the one save that records a fresh consent:
	 * what lets a left subscription come back. A new row needs only its
	 * source, which the API cannot write.
	 */
	private $consent_fresh = false;

	/** Record a fresh consent on this row (MailingList::add_registrant()). */
	public function record_consent(array $consent): void {
		$source = (string)($consent['source'] ?? '');
		if ($source === '') {
			throw new MailingListRegistrantException('A subscription needs the consent behind it.');
		}
		$this->set('mlr_confirm_time', 'now()');
		$this->set('mlr_confirm_source', $source);
		$ip = trim((string)($consent['ip'] ?? ''));
		$this->set('mlr_confirm_ip', ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) ? $ip : null);
		$this->set('mlr_confirm_by_usr_user_id', !empty($consent['by_user_id']) ? (int)$consent['by_user_id'] : null);
		$this->set('mlr_confirm_email', strtolower(trim((string)($consent['email'] ?? ''))) ?: null);
		$this->consent_fresh = true;
	}

public static function CheckIfExists($user_id, $mailing_list_id) {
		
		$count = new MultiMailingListRegistrant(array(
			'user_id' => $user_id,
			'mailing_list_id' => $mailing_list_id,
		));
		 
		if ($count->count_all() > 0) {
			$count->load();
			return $count->get(0);
		}
		return false;
	}	

	function prepare() {

		if(!$this->key){
			if($this->check_for_duplicate(array('mlr_mlt_mailing_list_id', 'mlr_usr_user_id'))){
				throw new MailingListRegistrantException('This is a duplicate mailing list registrant:'. $this->get('mlr_usr_user_id'));
			}
			// A new subscription carries its consent: a REST create, which can
			// write none of the consent fields, cannot step around confirmation.
			if (!$this->get('mlr_confirm_source')) {
				throw new MailingListRegistrantException('A subscription is made by confirming it, not by writing the row.');
			}
		}
		else if (!$this->get('mlr_delete_time') && !$this->consent_fresh && $this->stored_delete_time() !== null) {
			// Bringing back a left subscription needs a fresh consent too.
			throw new MailingListRegistrantException('A subscription that was left is renewed by confirming it again.');
		}

	}

	/** The delete time as stored, or null (a row that is live, or not saved yet). */
	private function stored_delete_time(): ?string {
		if (!$this->key) {
			return null;
		}
		$q = DbConnector::get_instance()->get_db_link()->prepare('SELECT mlr_delete_time FROM mlr_mailing_list_registrants WHERE mlr_mailing_list_registrant_id = ?');
		$q->execute(array($this->key));
		$value = $q->fetchColumn();
		return $value ? (string)$value : null;
	}
	
	function save($debug=false) {
		if(!$this->key){
			if($this->check_for_duplicate(array('mlr_mlt_mailing_list_id', 'mlr_usr_user_id'))){
				return FALSE;
			}			
		}
		$this->prepare_consent_guard();
		parent::save($debug);
		$this->consent_fresh = false;
	}

	/** prepare()'s consent rule, for a save that did not call prepare() first. */
	private function prepare_consent_guard(): void {
		if ($this->key) {
			$q = DbConnector::get_instance()->get_db_link()->prepare('SELECT mlr_mlt_mailing_list_id, mlr_usr_user_id FROM mlr_mailing_list_registrants WHERE mlr_mailing_list_registrant_id = ?');
			$q->execute(array($this->key));
			$stored = $q->fetch(PDO::FETCH_ASSOC);
			if ($stored && ((int)$stored['mlr_mlt_mailing_list_id'] !== (int)$this->get('mlr_mlt_mailing_list_id')
					|| (int)$stored['mlr_usr_user_id'] !== (int)$this->get('mlr_usr_user_id'))) {
				throw new MailingListRegistrantException('A subscription cannot be moved to another list or person.');
			}
		}
		if (!$this->key && !$this->get('mlr_confirm_source')) {
			throw new MailingListRegistrantException('A subscription is made by confirming it, not by writing the row.');
		}
		if ($this->key && !$this->get('mlr_delete_time') && !$this->consent_fresh && $this->stored_delete_time() !== null) {
			throw new MailingListRegistrantException('A subscription that was left is renewed by confirming it again.');
		}
	}

	/**
	 * Deleting a subscription also unsubscribes the address at the list's
	 * provider while it is still active here (a deleted account, say). The
	 * provider call never blocks the delete.
	 */
	function permanent_delete($debug = false) {
		if (!$debug && $this->key && !$this->get('mlr_delete_time') && $this->get('mlr_mlt_mailing_list_id')) {
			try {
				$list = new MailingList($this->get('mlr_mlt_mailing_list_id'), TRUE);
				if ($list->key) {
					$list->sync_unsubscribe($this->get('mlr_usr_user_id'));
				}
			} catch (Throwable $e) {
				error_log('[MailingListRegistrant] provider unsubscribe on delete failed: ' . $e->getMessage());
			}
		}
		return parent::permanent_delete($debug);
	}
}

class MultiMailingListRegistrant extends SystemMultiBase {
	protected static $model_class = 'MailingListRegistrant';

	function get_dropdown_array($include_new=FALSE) {
		return false;
	}

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['user_id'])) {
			$filters['mlr_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['mailing_list_id'])) {
			$filters['mlr_mlt_mailing_list_id'] = [$this->options['mailing_list_id'], PDO::PARAM_INT];
		}

		// Consent follows the address: only subscribers whose confirmed address
		// is still their account's.
		if (!empty($this->options['consented'])) {
			$filters['mlr_confirm_email'] = "= (SELECT lower(usr_email) FROM usr_users WHERE usr_user_id = mlr_usr_user_id)";
		}


		return $this->_get_resultsv2('mlr_mailing_list_registrants', $filters, $this->order_by, $only_count, $debug);
	}

}

?>
