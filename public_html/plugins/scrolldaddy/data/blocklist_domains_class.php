<?php
// PathHelper is already loaded by the time this file is included

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class BlocklistDomainException extends SystemBaseException {}

class BlocklistDomain extends SystemBase {

	public static $prefix = 'bld';
	public static $tablename = 'bld_blocklist_domains';
	public static $pkey_column = 'bld_blocklist_domain_id';

	public static $field_specifications = array(
		'bld_blocklist_domain_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'bld_category_key'        => array('type'=>'varchar(64)'),
		'bld_domain'              => array('type'=>'varchar(255)'),
	);

}

class MultiBlocklistDomain extends SystemMultiBase {
	protected static $model_class = 'BlocklistDomain';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['category_key'])) {
			$filters['bld_category_key'] = [$this->options['category_key'], PDO::PARAM_STR];
		}

		return $this->_get_resultsv2('bld_blocklist_domains', $filters, $this->order_by, $only_count, $debug);
	}
}

?>
