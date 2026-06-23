<?php

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('data/calendar_entry_class.php'));

class CalEntryException extends SystemBase {
    public static $prefix = 'cex';
    public static $tablename = 'cal_entry_exceptions';
    public static $pkey_column = 'cex_calendar_entry_exception_id';

    public static $field_specifications = array(
        'cex_calendar_entry_exception_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true, 'is_primary_key' => true),
        'cex_cal_entry_id'                => array('type' => 'int8', 'is_nullable' => false, 'required' => true, 'unique_with' => array('cex_exception_date')),
        'cex_exception_date'              => array('type' => 'date',  'is_nullable' => false, 'required' => true),
        'cex_create_time'                 => array('type' => 'timestamp(6)', 'default' => 'now()'),
    );

    // Cascade-delete when the recurring parent is hard-deleted.
    protected static $foreign_key_actions = array(
        'cex_cal_entry_id' => array('action' => 'cascade'),
    );

    public static $permanent_delete_actions = array();
}

class MultiCalEntryException extends SystemMultiBase {
    protected static $model_class = 'CalEntryException';

    protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        if (isset($this->options['cal_entry_id'])) {
            $filters['cex_cal_entry_id'] = [$this->options['cal_entry_id'], PDO::PARAM_INT];
        }
        // Batch lookup for many parents at once (avoids N+1 during expansion).
        // IDs are server-supplied row keys, cast to int — safe to inline as IN (...).
        if (isset($this->options['cal_entry_ids']) && is_array($this->options['cal_entry_ids'])) {
            $ids = array_values(array_filter(array_map('intval', $this->options['cal_entry_ids'])));
            if ($ids) {
                $filters['cex_cal_entry_id'] = 'IN (' . implode(',', $ids) . ')';
            }
        }
        if (isset($this->options['exception_date'])) {
            $filters['cex_exception_date'] = [$this->options['exception_date'], PDO::PARAM_STR];
        }
        return $this->_get_resultsv2('cal_entry_exceptions', $filters, $this->order_by, $only_count, $debug);
    }
}
