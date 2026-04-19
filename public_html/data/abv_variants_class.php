<?php
/**
 * AbTestVariant — an arm of a multi-armed bandit test.
 *
 * One row per variant. abv_overrides is a JSON object whose keys are a subset of
 * the parent entity's $ab_testable_fields. Absence of a key means the variant
 * inherits the parent's current value; a present key with an empty value means
 * "override to empty" (an explicit choice) — never conflate the two.
 *
 * @see /specs/ab_testing_framework.md
 */

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class AbTestVariantException extends SystemBaseException {}

class AbTestVariant extends SystemBase {
	public static $prefix = 'abv';
	public static $tablename = 'abv_variants';
	public static $pkey_column = 'abv_variant_id';

	public static $field_specifications = array(
		'abv_variant_id'    => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
		'abv_abt_test_id'   => array('type' => 'int8', 'is_nullable' => false),
		'abv_name'          => array('type' => 'varchar(64)'),
		'abv_overrides'     => array('type' => 'json', 'is_nullable' => true),
		'abv_trials'        => array('type' => 'int8', 'default' => 0, 'zero_on_create' => true),
		'abv_rewards'       => array('type' => 'int8', 'default' => 0, 'zero_on_create' => true),
		'abv_create_time'   => array('type' => 'timestamp(6)', 'default' => 'now()'),
		'abv_modified_time' => array('type' => 'timestamp(6)', 'is_nullable' => true),
		'abv_delete_time'   => array('type' => 'timestamp(6)', 'is_nullable' => true),
	);

	public static $json_vars = array('abv_overrides');

	/**
	 * Conversion rate. Returns null until the variant has any trials, so the
	 * leaderboard can distinguish "no data" from "genuinely zero."
	 */
	public function conversion_rate() {
		$trials = (int)$this->get('abv_trials');
		if ($trials <= 0) return null;
		return ((int)$this->get('abv_rewards')) / $trials;
	}
}

class MultiAbTestVariant extends SystemMultiBase {
	protected static $model_class = 'AbTestVariant';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['test_id'])) {
			$filters['abv_abt_test_id'] = array($this->options['test_id'], PDO::PARAM_INT);
		}

		if (isset($this->options['deleted'])) {
			$filters['abv_delete_time'] = $this->options['deleted'] ? 'IS NOT NULL' : 'IS NULL';
		}

		return $this->_get_resultsv2('abv_variants', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
