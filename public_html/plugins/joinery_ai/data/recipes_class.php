<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class RecipeException extends SystemBaseException {}

class Recipe extends SystemBase {

    public static $prefix = 'rcp';
    public static $tablename = 'rcp_recipes';
    public static $pkey_column = 'rcp_recipe_id';

    // AI auto-discovery (read)
    public static $ai_readable        = true;
    public static $ai_description     = 'AI automation recipes the user has configured.';
    public static $ai_excluded_fields = [];

    public static $field_specifications = array(
        'rcp_recipe_id'           => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
        'rcp_name'                => array('type'=>'varchar(255)', 'required'=>true),
        'rcp_prompt'              => array('type'=>'text'),
        // agent: the model drives via tools, one conversation per run.
        // pipeline: PHP drives, one bounded exchange per item — see
        // specs/joinery_ai_item_pipeline.md.
        'rcp_mode'                => array('type'=>'varchar(20)', 'default'=>'agent'),
        'rcp_pipeline_job'        => array('type'=>'varchar(100)'),
        'rcp_source_config'       => array('type'=>'jsonb'),
        'rcp_schedule_frequency'  => array('type'=>'varchar(20)', 'default'=>'weekly'),
        'rcp_schedule_day_of_week'=> array('type'=>'int4'),
        'rcp_schedule_time'       => array('type'=>'time'),
        'rcp_allowed_tools'       => array('type'=>'jsonb'),
        'rcp_allowed_models'      => array('type'=>'jsonb'),
        'rcp_allowed_actions'     => array('type'=>'jsonb'),
        'rcp_allow_tainted_writes'=> array('type'=>'bool', 'default'=>false),
        'rcp_model'               => array('type'=>'varchar(100)', 'default'=>'claude-haiku-4-5'),
        // Model controls (NULL temperature/top_p = fall back to the plugin-setting
        // default). See the chat-model-control spec.
        'rcp_temperature'         => array('type'=>'numeric(3,2)'),
        'rcp_top_p'               => array('type'=>'numeric(3,2)'),
        'rcp_thinking_level'      => array('type'=>'varchar(10)', 'default'=>'off'),
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

    public static $json_vars = array('rcp_allowed_tools', 'rcp_allowed_models', 'rcp_allowed_actions',
        'rcp_source_config');

    // rcp_owner_user_id doesn't fit the {prefix}_{owner_prefix}_..._id
    // convention (the owning User's own prefix isn't in the column), so it
    // needs an explicit source table to cascade correctly on user deletion.
    protected static $foreign_key_actions = [
        'rcp_owner_user_id' => ['action' => 'cascade', 'source_table' => 'usr_users'],
    ];

    const MODE_AGENT    = 'agent';
    const MODE_PIPELINE = 'pipeline';

    function authenticate_write($data) {
        if ($data['current_user_permission'] < 10) {
            throw new SystemAuthenticationError(
                'Joinery AI recipes require permission level 10 to edit.');
        }
    }

    /**
     * Validation only (per the prepare() rule — see docs/logic_architecture.md /
     * repo conventions: prepare() isn't guaranteed to run before save(), so it
     * never mutates state, only rejects invalid state). Pipeline mode requires
     * a registered job whose configDescriptor() the stored rcp_source_config
     * must satisfy — coerced and re-validated at read time by PipelineRunner,
     * not persisted here.
     */
    function prepare() {
        if ((string)$this->get('rcp_mode') !== self::MODE_PIPELINE) return;

        require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/PipelineJobRegistry.php'));
        require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/DescriptorValidator.php'));

        $job_id = (string)$this->get('rcp_pipeline_job');
        $job = PipelineJobRegistry::get($job_id);
        if ($job === null) {
            throw new RecipeException(
                "Pipeline recipes require a registered job; '$job_id' is not registered.");
        }

        try {
            $coerced = DescriptorValidator::coerce($job->configDescriptor(), self::decodeSourceConfig($this));
            $job->validateConfig($coerced, $this);
        } catch (InvalidArgumentException $e) {
            throw new RecipeException($e->getMessage());
        }
    }

    /** Decode rcp_source_config (jsonb, may arrive as a JSON string or an
     *  already-decoded array) to a plain array. Shared by prepare() and
     *  PipelineRunner so both read the column the same way. */
    public static function decodeSourceConfig(Recipe $recipe): array {
        $value = $recipe->get('rcp_source_config');
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($value) ? $value : [];
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
