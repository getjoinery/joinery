<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class RecipeException extends SystemBaseException {}

class Recipe extends SystemBase {

    public static $prefix = 'rcp';
    public static $tablename = 'rcp_recipes';
    public static $pkey_column = 'rcp_recipe_id';

    public static $field_specifications = array(
        'rcp_recipe_id'           => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
        'rcp_name'                => array('type'=>'varchar(255)', 'required'=>true),
        'rcp_prompt'              => array('type'=>'text'),
        'rcp_schedule_frequency'  => array('type'=>'varchar(20)', 'default'=>'weekly'),
        'rcp_schedule_day_of_week'=> array('type'=>'int4'),
        'rcp_schedule_time'       => array('type'=>'time'),
        'rcp_allowed_tools'       => array('type'=>'jsonb'),
        'rcp_model'               => array('type'=>'varchar(100)', 'default'=>'claude-haiku-4-5'),
        'rcp_delivery_email'      => array('type'=>'varchar(255)'),
        'rcp_delivery_dashboard'  => array('type'=>'bool', 'default'=>true),
        'rcp_enabled'             => array('type'=>'bool', 'default'=>true),
        'rcp_max_iterations'      => array('type'=>'int4', 'default'=>5),
        'rcp_max_tokens'          => array('type'=>'int4', 'default'=>5000),
        'rcp_monthly_token_cap'   => array('type'=>'int8', 'default'=>200000),
        'rcp_workspace'           => array('type'=>'text'),
        'rcp_owner_user_id'       => array('type'=>'int4'),
        'rcp_create_time'         => array('type'=>'timestamp(6)', 'default'=>'now()'),
        'rcp_update_time'         => array('type'=>'timestamp(6)'),
        'rcp_delete_time'         => array('type'=>'timestamp(6)'),
    );

    public static $json_vars = array('rcp_allowed_tools');

    function authenticate_write($data) {
        if ($data['current_user_permission'] < 10) {
            throw new SystemAuthenticationError(
                'Joinery AI recipes require permission level 10 to edit.');
        }
    }

}

class MultiRecipe extends SystemMultiBase {
    protected static $model_class = 'Recipe';

    protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['enabled'])) {
            $filters['rcp_enabled'] = [$this->options['enabled'] ? 't' : 'f', PDO::PARAM_STR];
        }

        if (isset($this->options['owner_user_id'])) {
            $filters['rcp_owner_user_id'] = [$this->options['owner_user_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['name'])) {
            $filters['rcp_name'] = [$this->options['name'], PDO::PARAM_STR];
        }

        if (isset($this->options['deleted'])) {
            $filters['rcp_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
        } else {
            $filters['rcp_delete_time'] = "IS NULL";
        }

        return $this->_get_resultsv2('rcp_recipes', $filters, $this->order_by, $only_count, $debug);
    }

}
