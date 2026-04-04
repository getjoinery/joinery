<?php
// PathHelper is already loaded by the time this file is included

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class SdScheduledBlockFilterException extends SystemBaseException {}

class SdScheduledBlockFilter extends SystemBase {

	public static $prefix = 'sbf';
	public static $tablename = 'sbf_scheduled_block_filters';
	public static $pkey_column = 'sbf_scheduled_block_filter_id';

	public static $field_specifications = array(
	    'sbf_scheduled_block_filter_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'sbf_sdb_scheduled_block_id' => array('type'=>'int4'),
	    'sbf_filter_key' => array('type'=>'varchar(32)'),
	    'sbf_action' => array('type'=>'int2'),
	);

}

class MultiSdScheduledBlockFilter extends SystemMultiBase {
	protected static $model_class = 'SdScheduledBlockFilter';

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['block_id'])) {
            $filters['sbf_sdb_scheduled_block_id'] = [$this->options['block_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['filter_key'])) {
            $filters['sbf_filter_key'] = [$this->options['filter_key'], PDO::PARAM_STR];
        }

        if (isset($this->options['action'])) {
            $filters['sbf_action'] = [$this->options['action'], PDO::PARAM_INT];
        }

        return $this->_get_resultsv2('sbf_scheduled_block_filters', $filters, $this->order_by, $only_count, $debug);
    }

}

?>
