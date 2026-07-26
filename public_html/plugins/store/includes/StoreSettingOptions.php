<?php
/**
 * Option lists for the store's declared settings.
 *
 * A setting whose choices are discovered rather than fixed names a resolver
 * here from its `options_from` key in plugin.json, so the shared settings
 * renderer can build the field without the store shipping a form of its own.
 *
 * @version 1.0
 */
class StoreSettingOptions {

	/**
	 * Active products, for the optional-donation picker.
	 *
	 * @return array product id => name, with an "off" entry first.
	 */
	public static function donationProducts(): array {
		require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));

		$options = array('' => '-- Off --');
		$products = new MultiProduct(array('is_active' => true), array('pro_name' => 'ASC'), 500, NULL);
		$products->load();
		foreach ($products as $product) {
			$options[(string)$product->key] = $product->get('pro_name');
		}
		return $options;
	}
}
