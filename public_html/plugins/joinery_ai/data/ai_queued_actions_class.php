<?php
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class AiQueuedActionException extends SystemBaseException {}

/**
 * One proposed AI action, waiting for the person it would act for
 * (specs/implemented/ai_action_queue.md). Structural rule, made storage: the AI never
 * changes anything without either a standing rule (a recipe verdict) or a yes
 * the owner clicked — a chat write tool call lands HERE as a pending row
 * instead of executing, and runs only at the moment the owner approves it.
 *
 * Resolved rows are kept: the queue doubles as the audit trail of what the AI
 * did and who approved it.
 *
 * The arguments are the LITERAL structured tool-call input — the card the
 * owner approves is rendered from them by the platform, never from model
 * prose (ActionQueue::factsFor()). aqa_model_note is reserved for a model-
 * authored one-line reason, stored apart from the arguments so it can only
 * ever render as quotation, never as the card's facts.
 *
 * Sealing: a chat turn that has read sealed content is hot, and a proposal's
 * arguments may quote it (a drafted reply, a forward) — so aqa_arguments,
 * aqa_model_note and aqa_result seal to the owner whenever the enqueueing
 * turn is hot (same shape as the sealed idempotency cache), and store in the
 * clear when cold. Rendering a sealed card needs the owner's window — which
 * approval always has, because resolving is an in-browser act.
 *
 * @version 1.0
 */
class AiQueuedAction extends SystemBase {

    public static $prefix = 'aqa';
    public static $tablename = 'aqa_ai_queued_actions';
    public static $pkey_column = 'aqa_ai_queued_action_id';

    const STATUS_PENDING  = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_DECLINED = 'declined';
    const STATUS_EXPIRED  = 'expired';
    const STATUS_FAILED   = 'failed';

    const SOURCE_CHAT   = 'chat';
    const SOURCE_RECIPE = 'recipe';   // reserved — see the spec's "Recipes and the queue"

    /** Proposals are perishable; the world they described moves on. */
    const DEFAULT_EXPIRY_DAYS = 7;

    public static $field_specifications = array(
        'aqa_ai_queued_action_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
        // The only person who can see or resolve the action.
        'aqa_owner_user_id'       => array('type'=>'int4', 'is_nullable'=>false),
        // Area page the action belongs to ('mailbox', 'calendar', …), matching
        // the AI panel's area strings. Empty for the generic chat tools, which
        // act on no one area — those appear in every panel's Waiting list and
        // inline in their conversation.
        'aqa_area'                => array('type'=>'varchar(40)', 'default'=>''),
        'aqa_source_type'         => array('type'=>'varchar(20)', 'default'=>'chat'),
        'aqa_aic_conversation_id' => array('type'=>'int8'),
        'aqa_rcp_recipe_id'       => array('type'=>'int8'),
        // The tool identifier the model called (e.g. update_model).
        'aqa_tool'                => array('type'=>'varchar(100)', 'required'=>true),
        // JSON-encoded literal tool-call arguments. Text, not jsonb: the value
        // is ciphertext when the row is sealed.
        'aqa_arguments'           => array('type'=>'text'),
        'aqa_model_note'          => array('type'=>'text'),
        'aqa_status'              => array('type'=>'varchar(12)', 'default'=>'pending', 'index'=>true),
        // JSON-encoded execution outcome ({status, summary} or {error}).
        'aqa_result'              => array('type'=>'text'),
        'aqa_created_time'        => array('type'=>'timestamp(6)', 'default'=>'now()'),
        'aqa_resolved_time'       => array('type'=>'timestamp(6)'),
        'aqa_expires_time'        => array('type'=>'timestamp(6)'),
        // Layer 0 sealing columns — per row, like the idempotency cache: only a
        // proposal born of a hot turn is sealed; an ordinary one stays plaintext.
        'aqa_content_sealed'      => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
        'aqa_sealed_key'          => array('type'=>'text'),
        'aqa_sealed_owner_user_id'=> array('type'=>'int8'),
        'aqa_key_generation'      => array('type'=>'int4'),
        'aqa_delete_time'         => array('type'=>'timestamp(6)'),
    );

    public static $sealed_fields = array('aqa_arguments', 'aqa_model_note', 'aqa_result');

    // Sealing runs through ActionQueue, which seals the proposal on the way in
    // and the result on the way out under the row's one DEK.
    public static $seal_on_save = false;

    // aqa_owner_user_id doesn't follow the {prefix}_{owner_prefix} convention,
    // so it names its source table explicitly. The queue is the audit trail of
    // what the AI did for a person — it dies with them (permanent_delete), and
    // an action proposed inside a conversation dies with that conversation.
    protected static $foreign_key_actions = [
        'aqa_owner_user_id'       => ['action' => 'permanent_delete', 'source_table' => 'usr_users'],
        'aqa_aic_conversation_id' => ['action' => 'permanent_delete'],
        'aqa_rcp_recipe_id'       => ['action' => 'permanent_delete'],
    ];

    /** Owner-only, both directions: the queue is one person's approvals. */
    function authenticate_read($data) {
        if ((int)$this->get('aqa_owner_user_id') !== (int)$data['current_user_id']) {
            throw new SystemAuthenticationError('This queued action belongs to another user.');
        }
    }

    function authenticate_write($data) {
        if ((int)$this->get('aqa_owner_user_id') !== (int)$data['current_user_id']) {
            throw new SystemAuthenticationError('This queued action belongs to another user.');
        }
    }

    /**
     * Targeted raw UPDATE of operational columns — never save(): save()
     * rebuilds every column through get(), which decrypts the sealed fields
     * and would write plaintext back (unsealing them) or throw while locked.
     * Mirrors AiConversationMessage::updateColumns().
     */
    public static function updateColumns(int $action_id, array $columns): void {
        if ($action_id <= 0 || empty($columns)) return;
        $sets = array();
        $params = array();
        foreach ($columns as $col => $value) {
            if (!array_key_exists($col, static::$field_specifications)) continue;
            $sets[] = $col . ' = ?';
            $params[] = $value;
        }
        if (empty($sets)) return;
        $params[] = $action_id;
        $db = DbConnector::get_instance()->get_db_link();
        $stmt = $db->prepare('UPDATE aqa_ai_queued_actions SET ' . implode(', ', $sets)
            . ' WHERE aqa_ai_queued_action_id = ?');
        foreach (array_values($params) as $i => $value) {
            $type = PDO::PARAM_STR;
            if (is_bool($value))     { $type = PDO::PARAM_BOOL; }
            elseif ($value === null) { $type = PDO::PARAM_NULL; }
            elseif (is_int($value))  { $type = PDO::PARAM_INT; }
            $stmt->bindValue($i + 1, $value, $type);
        }
        $stmt->execute();
    }

}

class MultiAiQueuedAction extends SystemMultiBase {
    protected static $model_class = 'AiQueuedAction';

    protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['owner_user_id'])) {
            $filters['aqa_owner_user_id'] = [$this->options['owner_user_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['status'])) {
            $filters['aqa_status'] = [$this->options['status'], PDO::PARAM_STR];
        }

        if (isset($this->options['conversation_id'])) {
            $filters['aqa_aic_conversation_id'] = [$this->options['conversation_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['area'])) {
            $filters['aqa_area'] = [$this->options['area'], PDO::PARAM_STR];
        }

        $filters['aqa_delete_time'] = 'IS NULL';

        return $this->_get_resultsv2('aqa_ai_queued_actions', $filters,
            $this->order_by ?: ['aqa_ai_queued_action_id' => 'DESC'], $only_count, $debug);
    }
}
?>
