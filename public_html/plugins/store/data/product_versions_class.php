<?php

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class ProductVersionException extends SystemBaseException {}

class ProductVersion extends SystemBase {	public static $prefix = 'prv';
	public static $tablename = 'prv_product_versions';
	public static $access_class = 'money';
	public static $pkey_column = 'prv_product_version_id';

	protected static $foreign_key_actions = [
		'prv_pro_product_id' => ['action' => 'permanent_delete'],
	];

	// Content packs (specs/content_pack_feature.md): a version is found by its
	// product and name, and a pack may write these fields.
	public static $content_pack_keys = [['prv_pro_product_id', 'prv_version_name']];
	public static $content_pack_fields = ['prv_version_price', 'prv_price_type', 'prv_trial_period_days',
		'prv_status', 'prv_order', 'prv_min_price', 'prv_max_price'];

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
	    'prv_product_version_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'prv_pro_product_id' => array('type'=>'int4', 'required'=>true),
	    'prv_version_name' => array('type'=>'varchar(100)', 'required'=>true),
	    'prv_version_price' => array('type'=>'numeric(10,2)', 'required'=>true),
	    'prv_status' => array('type'=>'int2', 'required'=>true),
	    'prv_order' => array('type'=>'int4'),
	    'prv_price_type' => array('type'=>'varchar(10)'),
	    'prv_trial_period_days' => array('type'=>'int4'),
	    'prv_display_priority' => array('type'=>'int4', 'default'=>0),
	    'prv_stripe_price_id' => array('type'=>'varchar(64)'),
	    'prv_stripe_price_id_test' => array('type'=>'varchar(64)'),
	    // The range a buyer may type when the buyer chooses the price (type 'user').
	    // Either end may be empty: no minimum means any amount above zero, no
	    // maximum means no upper limit.
	    'prv_min_price' => array('type'=>'numeric(10,2)', 'is_nullable'=>true),
	    'prv_max_price' => array('type'=>'numeric(10,2)', 'is_nullable'=>true),
	);

	/** The price types the version edit form offers. Empty is a legacy one-time price. */
	const PRICE_TYPES = array('single', 'user', 'day', 'week', 'month', 'year');

	/**
	 * The version form's rules, so a write from anywhere else keeps them. Each
	 * checks a value only when this save changes it, so a stored row that
	 * predates a rule still saves untouched.
	 */
	function prepare(){

		foreach (array('prv_min_price' => 'minimum', 'prv_max_price' => 'maximum') as $column => $word) {
			$value = $this->get($column);
			if ($value === '' ) {
				$this->set($column, NULL);
				continue;
			}
			if ($value !== NULL && $this->value_changes($column)
				&& (!is_numeric($value) || (float)$value < 0)) {
				throw new SystemDisplayableError('The ' . $word . ' amount must be a number of zero or more, like 5 or 5.50.');
			}
		}
		$min = $this->get('prv_min_price');
		$max = $this->get('prv_max_price');
		if (($this->value_changes('prv_min_price') || $this->value_changes('prv_max_price'))
			&& $min !== NULL && $max !== NULL && (float)$min > (float)$max) {
			throw new SystemDisplayableError('The minimum amount is more than the maximum amount.');
		}

		$price_type = (string)$this->get('prv_price_type');
		if($this->value_changes('prv_price_type')){
			if($price_type !== '' && !in_array($price_type, self::PRICE_TYPES, true)){
				throw new SystemDisplayableError('"' . $price_type . '" is not a price type. Use one of: '
					. implode(', ', self::PRICE_TYPES) . '.');
			}
			if($this->is_subscription() && $this->get('prv_pro_product_id')){
				$product = new Product((int)$this->get('prv_pro_product_id'), TRUE);
				if(trim((string)$product->get('pro_ownership_tag')) !== ''){
					throw new SystemDisplayableError('This product can only be owned once, and ownership applies to '
						. 'one-time purchases only, so a subscription version cannot be added. '
						. 'Set Ownership to "No limit" first.');
				}
			}
		}
	}


public function is_subscription(){
		if($this->get('prv_price_type') == 'day' || $this->get('prv_price_type') == 'week' || $this->get('prv_price_type') == 'month' || $this->get('prv_price_type') == 'year'){
			return $this->get('prv_price_type');
		}
		return false;
	}

	/**
	 * What follows a price shown to a buyer: '/month' for a monthly
	 * subscription, '' for a one-time price.
	 */
	public function price_period_suffix(): string {
		$period = $this->is_subscription();
		return $period ? '/' . $period : '';
	}

	/**
	 * An amount as a buyer reads it: '$5' for a whole amount, '$5.50' otherwise.
	 */
	public static function readable_amount($amount, string $currency_symbol): string {
		$amount = (float)$amount;
		return $currency_symbol . (floor($amount) == $amount
			? number_format($amount, 0, '.', '') : number_format($amount, 2, '.', ''));
	}

	/**
	 * The price to show for this version: its own price, or for a version
	 * whose buyer chooses the amount the range they may type ('$5 to $500',
	 * '$5 or more', 'Up to $500'). False for a buyer-chosen amount with no
	 * minimum and no maximum: there is no price to show, as with a donation.
	 */
	public function readable_price(string $currency_symbol) {
		if ($this->get('prv_price_type') !== 'user') {
			return $currency_symbol . $this->get('prv_version_price');
		}
		$min = $this->get('prv_min_price');
		$max = $this->get('prv_max_price');
		$has_min = $min !== NULL && $min !== '';
		$has_max = $max !== NULL && $max !== '';
		if ($has_min && $has_max) {
			return self::readable_amount($min, $currency_symbol) . ' to ' . self::readable_amount($max, $currency_symbol);
		}
		if ($has_min) {
			return self::readable_amount($min, $currency_symbol) . ' or more';
		}
		if ($has_max) {
			return 'Up to ' . self::readable_amount($max, $currency_symbol);
		}
		return false;
	}

	/**
	 * The sentence that names the amounts a buyer may type for this version
	 * ("Enter an amount from $5 to $500"), or '' when it has no minimum and
	 * no maximum.
	 */
	public function price_range_text(?string $currency_symbol = null): string {
		if ($currency_symbol === null) {
			$settings = Globalvars::get_instance();
			$currency_symbol = CurrencyHelper::symbol(strtolower((string)$settings->get_setting('site_currency'))) ?? '$';
		}
		$min = $this->get('prv_min_price');
		$max = $this->get('prv_max_price');
		$has_min = $min !== NULL && $min !== '';
		$has_max = $max !== NULL && $max !== '';
		if ($has_min && $has_max) {
			return 'Enter an amount from ' . self::readable_amount($min, $currency_symbol)
				. ' to ' . self::readable_amount($max, $currency_symbol);
		}
		if ($has_min) {
			return 'Enter an amount of at least ' . self::readable_amount($min, $currency_symbol);
		}
		if ($has_max) {
			return 'Enter an amount of no more than ' . self::readable_amount($max, $currency_symbol);
		}
		return '';
	}

	/**
	 * Whether an amount (a plain decimal string, as Product::buyer_amount()
	 * returns) lies within this version's minimum and maximum. A version with
	 * neither takes any amount.
	 */
	public function amount_in_range($amount): bool {
		$cents = (int)round((float)$amount * 100);
		$min = $this->get('prv_min_price');
		$max = $this->get('prv_max_price');
		if ($min !== NULL && $min !== '' && $cents < (int)round((float)$min * 100)) {
			return false;
		}
		if ($max !== NULL && $max !== '' && $cents > (int)round((float)$max * 100)) {
			return false;
		}
		return true;
	}
	
}

class MultiProductVersion extends SystemMultiBase {
	protected static $model_class = 'ProductVersion';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['product_id'])) {
			$filters['prv_pro_product_id'] = [$this->options['product_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['version_name'])) {
			$filters['prv_version_name'] = [$this->options['version_name'], PDO::PARAM_STR];
		}

		if (isset($this->options['is_active'])) {
			if ($this->options['is_active']) {
				$filters['prv_status'] = "> 0";
			}
		}

		// A comparison with a whole number ('> 0'), or a bare number for
		// equality. Anything else is not a priority filter and is refused: the
		// value becomes part of the condition.
		if (isset($this->options['prv_display_priority'])) {
			$priority = trim((string)$this->options['prv_display_priority']);
			if (preg_match('/^-?\d+$/D', $priority)) {
				$filters['prv_display_priority'] = [(int)$priority, PDO::PARAM_INT];
			} elseif (preg_match('/^(=|!=|<>|>=|<=|>|<)\s*(-?\d+)$/D', $priority, $m)) {
				// The comparison is one of these written conditions; the number is bound.
				$comparisons = array('=' => '= ?', '!=' => '!= ?', '<>' => '<> ?', '>=' => '>= ?', '<=' => '<= ?', '>' => '> ?', '<' => '< ?');
				$filters['prv_display_priority'] = SqlCondition::bind($comparisons[$m[1]], (int)$m[2]);
			} else {
				throw new UnknownMultiOptionException("MultiProductVersion: prv_display_priority takes a whole number or a comparison with one, such as '> 0'.");
			}
		}

		return $this->_get_resultsv2('prv_product_versions', $filters, $this->order_by, $only_count, $debug);
	}

}

?>
