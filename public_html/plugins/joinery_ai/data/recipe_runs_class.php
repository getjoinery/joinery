<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class RecipeRunException extends SystemBaseException {}

class RecipeRun extends SystemBase {

    public static $prefix = 'rcr';
    public static $tablename = 'rcr_recipe_runs';
    public static $pkey_column = 'rcr_run_id';

    const STATUS_PENDING  = 'pending';
    const STATUS_RUNNING  = 'running';
    const STATUS_SUCCESS  = 'success';
    const STATUS_FAILED   = 'failed';
    const STATUS_TIMEOUT  = 'timeout';
    const STATUS_SKIPPED  = 'skipped';

    const TRIGGER_SCHEDULE = 'schedule';
    const TRIGGER_MANUAL   = 'manual';

    public static $field_specifications = array(
        'rcr_run_id'            => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
        'rcr_rcp_recipe_id'     => array('type'=>'int8', 'required'=>true),
        'rcr_started_time'      => array('type'=>'timestamp(6)', 'default'=>'now()'),
        'rcr_completed_time'    => array('type'=>'timestamp(6)'),
        'rcr_status'            => array('type'=>'varchar(20)', 'default'=>'pending'),
        'rcr_trigger'           => array('type'=>'varchar(20)', 'default'=>'schedule'),
        'rcr_input_tokens'      => array('type'=>'int4', 'default'=>0),
        'rcr_output_tokens'     => array('type'=>'int4', 'default'=>0),
        'rcr_cost_estimate'     => array('type'=>'numeric(10,4)', 'default'=>0),
        'rcr_output'            => array('type'=>'text'),
        'rcr_error'             => array('type'=>'text'),
        'rcr_tool_calls'        => array('type'=>'jsonb'),
        'rcr_workspace_before'  => array('type'=>'text'),
        'rcr_workspace_after'   => array('type'=>'text'),
        'rcr_create_time'       => array('type'=>'timestamp(6)', 'default'=>'now()'),
        'rcr_delete_time'       => array('type'=>'timestamp(6)'),
    );

    public static $json_vars = array('rcr_tool_calls');

    function authenticate_write($data) {
        if ($data['current_user_permission'] < 10) {
            throw new SystemAuthenticationError(
                'Joinery AI run rows are written by the runner; manual edits require permission level 10.');
        }
    }

}

class MultiRecipeRun extends SystemMultiBase {
    protected static $model_class = 'RecipeRun';

    protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['recipe_id'])) {
            $filters['rcr_rcp_recipe_id'] = [$this->options['recipe_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['status'])) {
            $filters['rcr_status'] = [$this->options['status'], PDO::PARAM_STR];
        }

        if (isset($this->options['active'])) {
            // Convenience: rows currently in flight
            $filters['rcr_status'] = "IN ('pending','running')";
        }

        if (isset($this->options['deleted'])) {
            $filters['rcr_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
        } else {
            $filters['rcr_delete_time'] = "IS NULL";
        }

        return $this->_get_resultsv2('rcr_recipe_runs', $filters, $this->order_by, $only_count, $debug);
    }

}
