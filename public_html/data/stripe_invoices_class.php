<?php
$settings = Globalvars::get_instance();
$siteDir = $settings->get_setting('siteDir');
require_once($siteDir . '/includes/DbConnector.php');
require_once($siteDir . '/includes/FieldConstraints.php');
require_once($siteDir . '/includes/LibraryFunctions.php');
require_once($siteDir . '/includes/SessionControl.php');
require_once($siteDir . '/includes/SingleRowAccessor.php');
require_once($siteDir . '/includes/SystemClass.php');
require_once($siteDir . '/includes/Validator.php');



class StripeInvoiceException extends SystemClassException {}

class StripeInvoice extends SystemBase {

	public static $fields = array(
		'siv_stripe_invoice_id' => 'StripeInvoice ID',
		'siv_stripe_foreign_invoice_id' => 'ID at stripe',
		'siv_timestamp' => 'Time of stripe_invoice',
		'siv_amount_paid' => 'Total paid of the stripe_invoice',
		'siv_usr_user_id' => 'ID of the attached user',
		'siv_stripe_subscription_id' => 'Subscription id if it is a subscription',
		'siv_description' => 'Payment intent id for stripe checkout',
		'siv_stripe_charge_id' => 'Stripe charge id',
		'siv_stripe_payment_intent_id' => 'Stripe payment intent id',
	);

	public static $required_fields = array();

	public static $field_constraints = array();	
	
	public static $zero_variables = array();	

	public static $initial_default_values = array(
		);	
		
	function load($debug = false) {
		parent::load();

		$this->data = SingleRowFetch('siv_stripe_invoices', 'siv_stripe_invoice_id',
			$this->key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);

		if ($this->data === NULL) {
			throw new StripeInvoiceException('Invalid stripe_invoice ID');
		}
	}

	function save() {
		parent::save();
		$rowdata = array();
		foreach(array_keys(self::$fields) as $field) {
			$rowdata[$field] = $this->get($field);
		}

		if ($this->key) {
			$p_keys = array('siv_stripe_invoice_id' => $this->key);
			//throw new StripeInvoiceException('StripeInvoice are immutable, cannot be edited.');
		} else {
			$p_keys = NULL;
			// Creating a new stripe_invoice
			unset($rowdata['siv_stripe_invoice_id']);
		}
		
		
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		
		$p_keys_return = LibraryFunctions::edit_table(
			$dbhelper, $dblink, "siv_stripe_invoices", $p_keys, $rowdata, FALSE, 0);
			

		$this->key = $p_keys_return['siv_stripe_invoice_id'];
		
		
	}
	
	function permanent_delete() {
		
		$dbhelper = DbConnector::get_instance(); 
		$dblink = $dbhelper->get_db_link();
		
		$this_transaction = false;
		if(!$dblink->inTransaction()){
			$dblink->beginTransaction();
			$this_transaction = true;
		}


		$sql = 'DELETE FROM siv_stripe_invoices WHERE siv_stripe_invoice_id=:siv_stripe_invoice_id';
		try{
			$q = $dblink->prepare($sql);
			$q->bindParam(':siv_stripe_invoice_id', $this->key, PDO::PARAM_INT);
			$q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}	
	
		$this->key = NULL;

		return TRUE;
		
	}	
	

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
	

	function authenticate_read($session) {
		if ($session->get_permission() < 8) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to perform this action.');
		}
	}
	
	function authenticate_write($session) {
		if ($session->get_permission() < 8) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to perform this action.');
		}
	}	

	static function InitDB($mode='structure'){
	
		try{
			$sql = '
				CREATE SEQUENCE IF NOT EXISTS siv_stripe_invoices_siv_stripe_invoice_id_seq
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
			CREATE TABLE IF NOT EXISTS "public"."siv_stripe_invoices" (
			  "siv_stripe_invoice_id" int4 NOT NULL DEFAULT nextval(\'siv_stripe_invoices_siv_stripe_invoice_id_seq\'::regclass),
			  "siv_stripe_foreign_invoice_id" varchar(32),
			  "siv_stripe_subscription_id" varchar(32),
			  "siv_usr_user_id" int4,
			  "siv_amount_paid" numeric(10,2),
			  "siv_timestamp" timestamp(6) DEFAULT now(),
			  "siv_description" text COLLATE "pg_catalog"."default",
			  "siv_stripe_charge_id" varchar(32),
			  "siv_stripe_payment_intent_id" varchar(32)
			)
			;';
		$q = $dblink->prepare($sql);
		$success = $q->execute();
		
		try{		
			$sql = 'ALTER TABLE "public"."siv_stripe_invoices" ADD CONSTRAINT "siv_stripe_invoices_pkey" PRIMARY KEY ("siv_stripe_invoice_id");';
			$q = $dblink->prepare($sql);
			$success = $q->execute();
		}
		catch  (Exception $e){
			//SKIP
		}

		//FOR FUTURE
		//ALTER TABLE table_name ADD COLUMN IF NOT EXISTS column_name INTEGER;
	}			
}


class MultiStripeInvoice extends SystemMultiBase {

	function _get_results($only_count=FALSE, $debug = false) {
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('stripe_foreign_invoice_id', $this->options)) {
			$where_clauses[] = 'siv_stripe_foreign_invoice_id = ?';
			$bind_params[] = array($this->options['stripe_foreign_invoice_id'], PDO::PARAM_STR);
		}
		
		if (array_key_exists('stripe_charge_id', $this->options)) {
			$where_clauses[] = 'siv_stripe_charge_id = ?';
			$bind_params[] = array($this->options['stripe_charge_id'], PDO::PARAM_STR);
		}

		if (array_key_exists('user_id', $this->options)) {
			$where_clauses[] = 'siv_usr_user_id = ?';
			$bind_params[] = array($this->options['user_id'], PDO::PARAM_INT);
		}
			


		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		if ($only_count) {
			$sql = 'SELECT COUNT(1) as count_all FROM siv_stripe_invoices
				' . $where_clause;
		} else {
			$sql = 'SELECT * FROM siv_stripe_invoices
				' . $where_clause . ' ORDER BY ';

			if ($this->stripe_invoice_by === NULL) {
				$sql .= 'siv_stripe_invoice_id DESC';
			} else {
				$sort_clauses = array();
				if (array_key_exists('siv_stripe_invoice_id', $this->stripe_invoice_by)) {
					$sort_clauses[] = 'siv_stripe_invoice_id ' . $this->stripe_invoice_by['siv_stripe_invoice_id'];
				}
				$sql .= implode(',', $sort_clauses);
			}
			$sql .= $this->generate_limit_and_offset();
		}

		try {
			$q = $dblink->prepare($sql);

			if($debug){
				echo $sql. "<br>\n";
				print_r($this->options);
			}

			$total_params = count($bind_params);
			for($i=0;$i<$total_params;$i++) {
				list($param, $type) = $bind_params[$i];
				$q->bindValue($i+1, $param, $type);
			}
			$q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}

		return $q;
	}

	function load($debug = false) {
		$q = $this->_get_results();
		foreach($q->fetchAll() as $row) {
			$child = new StripeInvoice($row->siv_stripe_invoice_id);
			$child->load_from_data($row, array_keys(StripeInvoice::$fields));
			$this->add($child);
		}
	}

	function count_all($debug = false) {
		$q = $this->_get_results(TRUE);
		$counter = $q->fetch();
		return $counter->count_all;
	}
}

?>
