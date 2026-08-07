<?php
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class StripeCustomerException extends SystemBaseException {}

/**
 * StripeCustomer — the Stripe customer identity for a user.
 *
 * Billing code needs a stable Stripe customer id per user (one for live, one
 * for test mode). This lived on the users table as `usr_stripe_customer_id` /
 * `usr_stripe_customer_id_test`; it belongs to the store, so it moves to its
 * own store-owned table keyed by user. Lookups by customer id (webhook →
 * user) go through `GetByCustomerId()`.
 *
 * @version 1.0.0
 */
class StripeCustomer extends SystemBase {
	public static $prefix = 'stc';
	public static $tablename = 'stc_stripe_customers';
	public static $pkey_column = 'stc_stripe_customer_id';

	protected static $foreign_key_actions = array(
		'stc_usr_user_id' => array('action' => 'cascade'),
	);

	public static $field_specifications = array(
		'stc_stripe_customer_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true, 'is_primary_key'=>true),
		'stc_usr_user_id'        => array('type'=>'int8', 'is_nullable'=>false, 'required'=>true, 'unique'=>true),
		'stc_customer_id'        => array('type'=>'varchar(64)', 'index'=>true),
		'stc_customer_id_test'   => array('type'=>'varchar(64)', 'index'=>true),
	);

	/**
	 * Load (or create) the StripeCustomer row for a user id.
	 * Returns a StripeCustomer; new (unsaved) if the user has no row yet.
	 */
	public static function GetForUser($usr_user_id) {
		$data = SingleRowFetch('stc_stripe_customers', 'stc_usr_user_id', $usr_user_id,
			PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);
		if ($data && isset($data->stc_stripe_customer_id)) {
			return new StripeCustomer($data->stc_stripe_customer_id, true);
		}
		$sc = new StripeCustomer(null);
		$sc->set('stc_usr_user_id', $usr_user_id);
		return $sc;
	}

	/**
	 * Look up the user id behind a Stripe customer id, honoring the mode column.
	 *
	 * @param string $customer_id Stripe customer id
	 * @param bool   $test_mode   Match the test-mode column instead of live
	 * @return int|null           usr_user_id or null
	 */
	public static function GetUserIdByCustomerId($customer_id, $test_mode = false) {
		$column = $test_mode ? 'stc_customer_id_test' : 'stc_customer_id';
		$data = SingleRowFetch('stc_stripe_customers', $column, $customer_id,
			PDO::PARAM_STR, SINGLE_ROW_ALL_COLUMNS);
		return ($data && isset($data->stc_usr_user_id)) ? $data->stc_usr_user_id : null;
	}

	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in '. static::$tablename);
		}
	}
}

class MultiStripeCustomer extends SystemMultiBase {
	protected static $model_class = 'StripeCustomer';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		if (isset($this->options['user_id'])) {
			$filters['stc_usr_user_id'] = array($this->options['user_id'], PDO::PARAM_INT);
		}
		if (isset($this->options['customer_id'])) {
			$filters['stc_customer_id'] = array($this->options['customer_id'], PDO::PARAM_STR);
		}
		return $this->_get_resultsv2('stc_stripe_customers', $filters, $this->order_by, $only_count, $debug);
	}
}
