<?php
// PathHelper is already loaded by the time this file is included

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class SdScheduledBlockServiceException extends SystemBaseException {}

class SdScheduledBlockService extends SystemBase {

	public static $prefix = 'sbs';
	public static $tablename = 'sbs_scheduled_block_services';
	public static $pkey_column = 'sbs_scheduled_block_service_id';

	public static $field_specifications = array(
	    'sbs_scheduled_block_service_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'sbs_sdb_scheduled_block_id' => array('type'=>'int4'),
	    'sbs_service_key' => array('type'=>'varchar(32)'),
	    'sbs_action' => array('type'=>'int2'),
	);

}

class MultiSdScheduledBlockService extends SystemMultiBase {
	protected static $model_class = 'SdScheduledBlockService';

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['block_id'])) {
            $filters['sbs_sdb_scheduled_block_id'] = [$this->options['block_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['service_key'])) {
            $filters['sbs_service_key'] = [$this->options['service_key'], PDO::PARAM_STR];
        }

        if (isset($this->options['action'])) {
            $filters['sbs_action'] = [$this->options['action'], PDO::PARAM_INT];
        }

        return $this->_get_resultsv2('sbs_scheduled_block_services', $filters, $this->order_by, $only_count, $debug);
    }

}

?>
