<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

/**
 * One interactive AI chat thread owned by an admin. Distinct from the
 * platform's human-to-human messaging (Conversation / cnv_conversations):
 * AI chat carries a model id, per-conversation tool/model/action allowlists,
 * token totals, and assistant-role turns, none of which the human messaging
 * tables model. The class name is AiConversation (not Conversation) precisely
 * because core already owns Conversation.
 *
 * Not $ai_readable — the chat does not query its own transcript tables.
 */
class AiConversationException extends SystemBaseException {}

class AiConversation extends SystemBase {

    public static $prefix = 'aic';
    public static $tablename = 'aic_conversations';
    public static $pkey_column = 'aic_conversation_id';

    public static $field_specifications = array(
        'aic_conversation_id'    => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
        'aic_owner_user_id'      => array('type'=>'int4', 'required'=>true),
        'aic_title'              => array('type'=>'varchar(255)'),
        'aic_model'              => array('type'=>'varchar(100)'),
        // Per-chat capability toggles (both default off → a plain conversational
        // assistant). Data access gates the site-data tool group + model scope;
        // web search gates the web tool group. See the chat-capabilities spec.
        'aic_data_access'        => array('type'=>'bool', 'default'=>false),
        'aic_web_search'         => array('type'=>'bool', 'default'=>false),
        'aic_total_input_tokens' => array('type'=>'int8', 'default'=>0),
        'aic_total_output_tokens'=> array('type'=>'int8', 'default'=>0),
        'aic_create_time'        => array('type'=>'timestamp(6)', 'default'=>'now()'),
        'aic_update_time'        => array('type'=>'timestamp(6)'),
        'aic_delete_time'        => array('type'=>'timestamp(6)'),
    );

    function authenticate_write($data) {
        if ($data['current_user_permission'] < 5) {
            throw new SystemAuthenticationError(
                'Joinery AI chat requires permission level 5 to use.');
        }
    }

}

class MultiAiConversation extends SystemMultiBase {
    protected static $model_class = 'AiConversation';

    protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['owner_user_id'])) {
            $filters['aic_owner_user_id'] = [$this->options['owner_user_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['deleted'])) {
            $filters['aic_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
        } else {
            $filters['aic_delete_time'] = "IS NULL";
        }

        return $this->_get_resultsv2('aic_conversations', $filters, $this->order_by, $only_count, $debug);
    }

}
