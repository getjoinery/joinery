<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SessionControl.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemClass.php');
PathHelper::requireOnce('includes/Validator.php');



class StripeInvoiceException extends SystemClassException {}

class StripeInvoice extends SystemBase {
	public static $prefix = 'siv';
	public static $tablename = 'siv_stripe_invoices';
	public static $pkey_column = 'siv_stripe_invoice_id';
	public static $permanent_delete_actions = array(	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value

	public static $fields = array(		'siv_stripe_foreign_invoice_id' => 'ID at stripe',
		'siv_timestamp' => 'Time of stripe_invoice',
		'siv_amount_paid' => 'Total paid of the stripe_invoice',
		'siv_usr_user_id' => 'ID of the attached user',
		'siv_stripe_subscription_id' => 'Subscription id if it is a subscription',
		'siv_description' => 'Payment intent id for stripe checkout',
		'siv_stripe_charge_id' => 'Stripe charge id',
		'siv_stripe_payment_intent_id' => 'Stripe payment intent id',
	);

	/**
	 * Field specifications define database column properties and schema constraints
	 * Available options:
	 *   'type' => 'varchar(255)' | 'int4' | 'int8' | 'text' | 'timestamp(6)' | 'numeric(10,2)' | 'bool' | etc.
	 *   'serial' => true/false - Auto-incrementing field
	 *   'is_nullable' => true/false - Whether NULL values are allowed
	 *   'unique' => true - Field must be unique (single field constraint)
	 *   'unique_with' => array('field1', 'field2') - Composite unique constraint with other fields
	 */
	public static $field_specifications = array(
		'siv_stripe_invoice_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'siv_stripe_foreign_invoice_id' => array('type'=>'varchar(32)'),
		'siv_timestamp' => array('type'=>'timestamp(6)'),
		'siv_amount_paid' => array('type'=>'numeric(10,2)'),
		'siv_usr_user_id' => array('type'=>'int4'),
		'siv_stripe_subscription_id' => array('type'=>'varchar(32)'),
		'siv_description' => array('type'=>'text'),
		'siv_stripe_charge_id' => array('type'=>'varchar(32)'),
		'siv_stripe_payment_intent_id' => array('type'=>'varchar(32)'),
	);
			  	
	public static $required_fields = array();

	public static $field_constraints = array();	
	
	public static $zero_variables = array();	

	public static $initial_default_values = array('siv_timestamp' => 'now()'
		);	
	

	public static function GetByStripeSession($session_id) {
		$data = SingleRowFetch('siv_stripe_invoices', 'siv_stripe_session_id',
			$session_id, PDO::PARAM_STR, SINGLE_ROW_ALL_COLUMNS);

		if ($data === NULL) {
			return NULL;
		}

		$stripe_invoice = new StripeInvoice($data->siv_stripe_invoice_id);
		$stripe_invoice->load_from_data($data, array_keys(StripeInvoice::$fields));
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
    
    function load($debug = false) {
        parent::load();
        $q = $this->getMultiResults(false, $debug);
        foreach($q->fetchAll() as $row) {
            $child = new StripeInvoice($row->siv_stripe_invoice_id);
            $child->load_from_data($row, array_keys(StripeInvoice::$fields));
            $this->add($child);
        }
    }
    
    function count_all($debug = false) {
        $q = $this->getMultiResults(TRUE, $debug);
        return $q;
    }
}

?>
