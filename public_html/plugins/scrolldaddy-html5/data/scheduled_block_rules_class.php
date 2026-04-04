<?php
// PathHelper is already loaded by the time this file is included

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class SdScheduledBlockRuleException extends SystemBaseException {}

class SdScheduledBlockRule extends SystemBase {

	public static $prefix = 'sbr';
	public static $tablename = 'sbr_scheduled_block_rules';
	public static $pkey_column = 'sbr_scheduled_block_rule_id';

	public static $field_specifications = array(
	    'sbr_scheduled_block_rule_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'sbr_sdb_scheduled_block_id' => array('type'=>'int4'),
	    'sbr_hostname' => array('type'=>'varchar(128)'),
	    'sbr_is_active' => array('type'=>'int2'),
	    'sbr_action' => array('type'=>'int2'),
	);

}

class MultiSdScheduledBlockRule extends SystemMultiBase {
	protected static $model_class = 'SdScheduledBlockRule';

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['block_id'])) {
            $filters['sbr_sdb_scheduled_block_id'] = [$this->options['block_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['active'])) {
            $filters['sbr_is_active'] = $this->options['active'] ? "= 1" : "= 0";
        }

        if (isset($this->options['action'])) {
            $filters['sbr_action'] = [$this->options['action'], PDO::PARAM_INT];
        }

        return $this->_get_resultsv2('sbr_scheduled_block_rules', $filters, $this->order_by, $only_count, $debug);
    }

}

?>
