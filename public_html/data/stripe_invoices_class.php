<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class StripeInvoiceException extends SystemBaseException {}

class StripeInvoice extends SystemBase {	public static $prefix = 'siv';
	public static $tablename = 'siv_stripe_invoices';
	public static $pkey_column = 'siv_stripe_invoice_id';

	protected static $foreign_key_actions = [
		'siv_usr_user_id' => ['action' => 'set_value', 'value' => User::USER_DELETED]
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
	    'siv_stripe_invoice_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'siv_stripe_foreign_invoice_id' => array('type'=>'varchar(32)'),
	    'siv_timestamp' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'siv_amount_paid' => array('type'=>'numeric(10,2)'),
	    'siv_usr_user_id' => array('type'=>'int4'),
	    'siv_stripe_subscription_id' => array('type'=>'varchar(32)'),
	    'siv_description' => array('type'=>'text'),
	    'siv_stripe_charge_id' => array('type'=>'varchar(32)'),
	    'siv_stripe_payment_intent_id' => array('type'=>'varchar(32)'),
	);

public static function GetByStripeSession($session_id) {
		$data = SingleRowFetch('siv_stripe_invoices', 'siv_stripe_session_id',
			$session_id, PDO::PARAM_STR, SINGLE_ROW_ALL_COLUMNS);

		if ($data === NULL) {
			return NULL;
		}

		$stripe_invoice = new StripeInvoice($data->siv_stripe_invoice_id);
		$stripe_invoice->load_from_data($data, array_keys(StripeInvoice::$field_specifications));
		return $stripe_invoice;
	}

	function authenticate_read($data) {
		// If the user's ID doesn't match, we have to make
		// sure they have admin access, otherwise denied.
		if ($data['current_user_permission'] < 8) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to view this entry in '. $this->tablename);
		}
	}
	
	function authenticate_write($data) {
		if ($data['current_user_permission'] < 8) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in '. static::$tablename);
		}
	}

}

class MultiStripeInvoice extends SystemMultiBase {
	protected static $model_class = 'StripeInvoice';

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        
        if (isset($this->options['stripe_foreign_invoice_id'])) {
            $filters['siv_stripe_foreign_invoice_id'] = [$this->options['stripe_foreign_invoice_id'], PDO::PARAM_STR];
        }
        
        if (isset($this->options['stripe_charge_id'])) {
            $filters['siv_stripe_charge_id'] = [$this->options['stripe_charge_id'], PDO::PARAM_STR];
        }
        
        if (isset($this->options['user_id'])) {
            $filters['siv_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
        }
        
        return $this->_get_resultsv2('siv_stripe_invoices', $filters, $this->order_by, $only_count, $debug);
    }
}

?>
