<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

/**
 * One turn in an AiConversation — a user message or an assistant reply.
 * Assistant rows additionally carry the per-turn tool trace, token counts,
 * and any pending (proposed-but-unconfirmed) mutating tool call awaiting the
 * admin's sign-off.
 *
 * The FK column follows the {prefix}_{source_prefix}_{entity}_id convention
 * (aim_aic_conversation_id → aic_conversations) so the deletion system
 * auto-detects the parent and cascades when a conversation is permanently
 * deleted. Not $ai_readable.
 */
class AiConversationMessageException extends SystemBaseException {}

class AiConversationMessage extends SystemBase {

    public static $prefix = 'aim';
    public static $tablename = 'aim_conversation_messages';
    public static $pkey_column = 'aim_message_id';

    const ROLE_USER      = 'user';
    const ROLE_ASSISTANT = 'assistant';

    // Turn lifecycle for asynchronous chat. A user row is COMPLETE on insert;
    // an assistant placeholder is RUNNING until the in-process turn finishes it
    // (COMPLETE) or it errors / is reaped (FAILED). See ChatAsync.
    const STATUS_RUNNING  = 'running';
    const STATUS_COMPLETE = 'complete';
    const STATUS_FAILED   = 'failed';

    protected static $foreign_key_actions = [
        'aim_aic_conversation_id' => ['action' => 'cascade'],
    ];

    public static $field_specifications = array(
        'aim_message_id'          => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
        'aim_aic_conversation_id' => array('type'=>'int8', 'required'=>true),
        'aim_role'                => array('type'=>'varchar(20)', 'required'=>true),
        'aim_content'             => array('type'=>'text'),
        'aim_tool_calls'          => array('type'=>'jsonb'),
        'aim_pending_action'      => array('type'=>'jsonb'),
        'aim_input_tokens'        => array('type'=>'int4', 'default'=>0),
        'aim_output_tokens'       => array('type'=>'int4', 'default'=>0),
        'aim_status'              => array('type'=>'varchar(20)', 'default'=>'complete'),
        'aim_error'               => array('type'=>'text'),
        'aim_create_time'         => array('type'=>'timestamp(6)', 'default'=>'now()'),
        'aim_delete_time'         => array('type'=>'timestamp(6)'),
    );

    public static $json_vars = array('aim_tool_calls', 'aim_pending_action');

    function authenticate_write($data) {
        if ($data['current_user_permission'] < 5) {
            throw new SystemAuthenticationError(
                'Joinery AI chat messages require permission level 5 to write.');
        }
    }

}

class MultiAiConversationMessage extends SystemMultiBase {
    protected static $model_class = 'AiConversationMessage';

    protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['conversation_id'])) {
            $filters['aim_aic_conversation_id'] = [$this->options['conversation_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['role'])) {
            $filters['aim_role'] = [$this->options['role'], PDO::PARAM_STR];
        }

        if (isset($this->options['status'])) {
            $filters['aim_status'] = [$this->options['status'], PDO::PARAM_STR];
        }

        if (isset($this->options['deleted'])) {
            $filters['aim_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
        } else {
            $filters['aim_delete_time'] = "IS NULL";
        }

        return $this->_get_resultsv2('aim_conversation_messages', $filters, $this->order_by, $only_count, $debug);
    }

}
