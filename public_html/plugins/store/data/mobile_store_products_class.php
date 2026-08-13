<?php
/**
 * MobileStoreProduct — maps a mobile-store product identifier to the Joinery
 * product it sells.
 *
 * Each row says: when the App Store or Google Play reports a purchase of
 * store product X, that purchase is of Joinery product Y. The product carries
 * its subscription tier (pro_sbt_subscription_tier_id), so this one mapping
 * is how store purchases reach TierBilling::handleProductPurchase() — the
 * same grant path web checkout uses. Managed at
 * /plugins/store/admin/admin_store_product_mappings.
 *
 * @version 1.0.0
 */

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class MobileStoreProductException extends SystemBaseException {}

class MobileStoreProduct extends SystemBase {
	public static $prefix = 'msp';
	public static $tablename = 'msp_mobile_store_products';
	public static $pkey_column = 'msp_mobile_store_product_id';

	// Admin-only configuration — not exposed to the REST API or AI surface.
	public static $api_readable = false;
	public static $api_writable = false;
	public static $ai_readable  = false;

	public const STORE_APP_STORE  = 'app_store';
	public const STORE_PLAY_STORE = 'play_store';

	protected static $foreign_key_actions = [
		'msp_pro_product_id' => ['action' => 'prevent', 'message' => 'Cannot delete product - mobile store mappings exist', 'source_table' => 'pro_products'],
		'msp_prv_product_version_id' => ['action' => 'prevent'],
	];

	// Nothing references a mapping row, so permanent delete cascades nowhere.

	public static $field_specifications = array(
	    'msp_mobile_store_product_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'msp_store' => array('type'=>'varchar(20)', 'required'=>true, 'allowed_values'=>array(self::STORE_APP_STORE, self::STORE_PLAY_STORE)),
	    'msp_store_product_id' => array('type'=>'varchar(255)', 'required'=>true),
	    'msp_pro_product_id' => array('type'=>'int4', 'required'=>true),
	    'msp_prv_product_version_id' => array('type'=>'int4'),
	    'msp_is_active' => array('type'=>'bool', 'default'=>true),
	    'msp_notes' => array('type'=>'varchar(255)'),
	    'msp_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'msp_delete_time' => array('type'=>'timestamp(6)'),
	);

	public function prepare() {
		if (!in_array($this->get('msp_store'), array(self::STORE_APP_STORE, self::STORE_PLAY_STORE))) {
			throw new MobileStoreProductException('Store must be app_store or play_store');
		}
	}

	/**
	 * The active mapping for a store product identifier, or NULL.
	 */
	public static function GetByStoreProductId($store, $store_product_id) {
		$mappings = new MultiMobileStoreProduct(array(
			'store'            => $store,
			'store_product_id' => $store_product_id,
			'active'           => true,
			'deleted'          => false,
		));
		$mappings->load();
		foreach ($mappings as $mapping) {
			return $mapping;
		}
		return NULL;
	}
}

class MultiMobileStoreProduct extends SystemMultiBase {
	protected static $model_class = 'MobileStoreProduct';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['store'])) {
			$filters['msp_store'] = [$this->options['store'], PDO::PARAM_STR];
		}

		if (isset($this->options['store_product_id'])) {
			$filters['msp_store_product_id'] = [$this->options['store_product_id'], PDO::PARAM_STR];
		}

		if (isset($this->options['product_id'])) {
			$filters['msp_pro_product_id'] = [$this->options['product_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['active'])) {
			$filters['msp_is_active'] = $this->options['active'] ? '= TRUE' : '= FALSE';
		}


		return $this->_get_resultsv2('msp_mobile_store_products', $filters, $this->order_by, $only_count, $debug);
	}
}

?>
