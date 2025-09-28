<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class ChangeTracking extends SystemBase {
    public static $prefix = 'cht';
    public static $tablename = 'cht_change_tracking';
    public static $pkey_column = 'cht_change_tracking_id';

    public static $field_specifications = array(
        'cht_change_tracking_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
        'cht_entity_type' => array('type'=>'varchar(50)', 'required'=>true),
        'cht_entity_id' => array('type'=>'int8'),
        'cht_usr_user_id' => array('type'=>'int4'),
        'cht_field_name' => array('type'=>'varchar(100)'),
        'cht_old_value' => array('type'=>'text'),
        'cht_new_value' => array('type'=>'text'),
        'cht_change_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
        'cht_change_reason' => array('type'=>'varchar(50)'),
        'cht_reference_type' => array('type'=>'varchar(50)'),
        'cht_reference_id' => array('type'=>'int8'),
        'cht_changed_by_usr_user_id' => array('type'=>'int4'),
        'cht_metadata' => array('type'=>'text')
    );

    /**
     * Static method to log a change
     */
    public static function logChange($entity_type, $entity_id, $user_id, $field_name,
                                     $old_value, $new_value, $change_reason,
                                     $reference_type = null, $reference_id = null,
                                     $changed_by_user_id = null, $metadata = null) {
        $change = new ChangeTracking(NULL);
        $change->set('cht_entity_type', $entity_type);
        $change->set('cht_entity_id', $entity_id);
        $change->set('cht_usr_user_id', $user_id);
        $change->set('cht_field_name', $field_name);
        $change->set('cht_old_value', is_null($old_value) ? null : (string)$old_value);
        $change->set('cht_new_value', is_null($new_value) ? null : (string)$new_value);
        $change->set('cht_change_reason', $change_reason);
        $change->set('cht_reference_type', $reference_type);
        $change->set('cht_reference_id', $reference_id);
        $change->set('cht_changed_by_usr_user_id', $changed_by_user_id);
        $change->set('cht_metadata', $metadata);
        $change->save();
        return $change;
    }

    /**
     * Get all changes for a specific entity
     */
    public static function getEntityHistory($entity_type, $entity_id) {
        $changes = new MultiChangeTracking([
            'cht_entity_type' => $entity_type,
            'cht_entity_id' => $entity_id
        ], ['cht_change_time' => 'DESC']);
        $changes->load();
        return $changes;
    }

    /**
     * Get all changes for a specific user
     */
    public static function getUserHistory($user_id) {
        $changes = new MultiChangeTracking([
            'cht_usr_user_id' => $user_id
        ], ['cht_change_time' => 'DESC']);
        $changes->load();
        return $changes;
    }
}

class MultiChangeTracking extends SystemMultiBase {
    protected static $model_class = 'ChangeTracking';

    protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['cht_entity_type'])) {
            $filters['cht_entity_type'] = [$this->options['cht_entity_type'], PDO::PARAM_STR];
        }

        if (isset($this->options['cht_entity_id'])) {
            $filters['cht_entity_id'] = [$this->options['cht_entity_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['cht_usr_user_id'])) {
            $filters['cht_usr_user_id'] = [$this->options['cht_usr_user_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['cht_field_name'])) {
            $filters['cht_field_name'] = [$this->options['cht_field_name'], PDO::PARAM_STR];
        }

        if (isset($this->options['cht_change_reason'])) {
            $filters['cht_change_reason'] = [$this->options['cht_change_reason'], PDO::PARAM_STR];
        }

        if (isset($this->options['cht_reference_type'])) {
            $filters['cht_reference_type'] = [$this->options['cht_reference_type'], PDO::PARAM_STR];
        }

        if (isset($this->options['cht_reference_id'])) {
            $filters['cht_reference_id'] = [$this->options['cht_reference_id'], PDO::PARAM_INT];
        }

        // Handle any standard filters from parent class
        $sorts = [];
        if (!empty($this->sorts)) {
            $sorts = $this->sorts;
        }

        return $this->_get_resultsv2('cht_change_tracking', $filters, $sorts, $only_count, $debug);
    }
}