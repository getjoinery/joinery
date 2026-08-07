<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

/**
 * Platform idempotency for pipeline-mode recipes: one row per (recipe, item)
 * pair that has been processed, regardless of outcome. PipelineJobInterface
 * implementations exclude logged items in their own nextItem() query via
 * MultiAipRecipeItemLog::notExistsClause() — the log is the single source of
 * truth for "has this recipe already looked at this item," so no job needs
 * to reinvent its own marker column or table.
 *
 * Per-recipe rather than per-item: two recipes may legitimately run the same
 * job (or different jobs) over the same source, each with its own progress.
 * A job is free to ALSO stamp its own verdict fields on the item directly
 * (as the email security scan job does) — this table only tracks whether the
 * item has been seen, not what was decided.
 */
class AipRecipeItemLogException extends SystemBaseException {}

class AipRecipeItemLog extends SystemBase {

    public static $prefix = 'aip';
    public static $tablename = 'aip_recipe_item_log';
    public static $pkey_column = 'aip_log_id';

    protected static $foreign_key_actions = array(
        // 'rcp' prefix collides: convention would resolve to RelayCloudProvision, not Recipe
        'aip_rcp_recipe_id' => array('action' => 'cascade', 'source_class' => 'Recipe'),
        'aip_rcr_run_id' => array('action' => 'cascade'),
    );

    const STATUS_DONE  = 'done';
    const STATUS_ERROR = 'error';

    public static $field_specifications = array(
        'aip_log_id'         => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
        'aip_rcp_recipe_id'  => array('type'=>'int8', 'required'=>true,
                                      'unique_with'=>array('aip_item_key')),
        'aip_item_key'       => array('type'=>'varchar(100)', 'required'=>true),
        'aip_rcr_run_id'     => array('type'=>'int8'),
        'aip_status'         => array('type'=>'varchar(10)'),   // done | error
        'aip_processed_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
    );

    function authenticate_write($data) {
        if ($data['current_user_permission'] < 10) {
            throw new SystemAuthenticationError(
                'Joinery AI item log rows are written by the pipeline runner; manual edits require permission level 10.');
        }
    }

}

class MultiAipRecipeItemLog extends SystemMultiBase {
    protected static $model_class = 'AipRecipeItemLog';

    protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['recipe_id'])) {
            $filters['aip_rcp_recipe_id'] = [$this->options['recipe_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['item_key'])) {
            $filters['aip_item_key'] = [$this->options['item_key'], PDO::PARAM_STR];
        }

        if (isset($this->options['status'])) {
            $filters['aip_status'] = [$this->options['status'], PDO::PARAM_STR];
        }

        return $this->_get_resultsv2('aip_recipe_item_log', $filters, $this->order_by, $only_count, $debug);
    }

    /**
     * NOT-EXISTS SQL fragment a job splices into its own item-source query to
     * exclude items this recipe has already processed. $item_key_column is an
     * expression from the job's own query that identifies an item — the same
     * value the job returns as `item_key` from nextItem() — cast to text if
     * it isn't already (aip_item_key is varchar).
     *
     * The caller binds `:aip_recipe_id` to the recipe's id. Example:
     *   $sql = "SELECT ... FROM iem_inbound_email_messages m
     *           WHERE m.iem_recipient = :alias
     *             AND " . MultiAipRecipeItemLog::notExistsClause('m.iem_message_id::text') . "
     *           ORDER BY m.iem_received_time ASC LIMIT 1";
     */
    public static function notExistsClause(string $item_key_column): string {
        return "NOT EXISTS (SELECT 1 FROM aip_recipe_item_log "
             . "WHERE aip_rcp_recipe_id = :aip_recipe_id "
             . "AND aip_item_key = $item_key_column)";
    }

}
