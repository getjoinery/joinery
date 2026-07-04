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
        // Pinned threads sort above the rest in the chat list. Non-nullable so the
        // ORDER BY aic_pinned DESC is clean (NULL would sort ahead of true).
        'aic_pinned'             => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
        // Per-chat capability toggles (both default off → a plain conversational
        // assistant). Data access gates the site-data tool group + model scope;
        // web search gates the web tool group. See the chat-capabilities spec.
        'aic_data_access'        => array('type'=>'bool', 'default'=>false),
        'aic_web_search'         => array('type'=>'bool', 'default'=>false),
        // Per-chat model controls (NULL = fall back to the plugin-setting default,
        // then the provider/floor). See the chat-model-control spec.
        'aic_temperature'        => array('type'=>'numeric(3,2)'),   // NULL = use setting
        'aic_top_p'              => array('type'=>'numeric(3,2)'),   // NULL = use setting
        'aic_max_tokens'         => array('type'=>'int4'),          // NULL = use setting
        'aic_instructions'       => array('type'=>'text'),          // per-chat voice block override
        'aic_thinking_level'     => array('type'=>'varchar(10)', 'default'=>'off'),
        // How uploaded files reach the model: 'extract' (default — cheapest door,
        // text-first) or 'original' (send whole files where a native door exists:
        // PDF as a document block, HTML as raw markup). Read at send time and
        // applied to the whole history that turn. See the file-uploads spec §1.
        'aic_attachment_mode'    => array('type'=>'varchar(10)', 'is_nullable'=>false, 'default'=>'extract'),
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
        $search = isset($this->options['search']) ? trim((string)$this->options['search']) : '';

        // No search term: the built-in filter path covers owner + soft-delete.
        if ($search === '') {
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

        // Search path: match the term against the thread title OR any non-deleted
        // message body in the thread (EXISTS subquery). The term is bound, never
        // concatenated; %/_/\ are escaped so they match literally rather than as
        // LIKE wildcards. _get_resultsv2's filter system can't bind an ILIKE, so
        // this case runs its own prepared statement (same return contract).
        return $this->getSearchResults($search, $only_count, $debug);
    }

    /** Prepared title-or-content search. Returns a PDOStatement (rows) or an int
     *  count, matching what _get_resultsv2 returns so load()/count_all() work. */
    protected function getSearchResults($search, $only_count, $debug) {
        $deleted  = isset($this->options['deleted']) && $this->options['deleted'];
        $owner_id = isset($this->options['owner_user_id']) ? (int)$this->options['owner_user_id'] : 0;
        $like     = '%' . addcslashes($search, '\\%_') . '%';

        $where = [];
        $where[] = 'aic_delete_time ' . ($deleted ? 'IS NOT NULL' : 'IS NULL');
        if ($owner_id) {
            $where[] = 'aic_owner_user_id = :owner_id';
        }
        $where[] = '(aic_title ILIKE :title_term OR EXISTS ('
                 . 'SELECT 1 FROM aim_conversation_messages '
                 . 'WHERE aim_aic_conversation_id = aic_conversation_id '
                 . 'AND aim_delete_time IS NULL AND aim_content ILIKE :body_term))';

        $where_sql = 'WHERE ' . implode(' AND ', $where);

        if ($only_count) {
            $sql = "SELECT COUNT(*) FROM aic_conversations $where_sql";
        } else {
            $sql = "SELECT * FROM aic_conversations $where_sql "
                 . 'ORDER BY aic_pinned DESC, aic_update_time DESC';
        }

        if ($debug) {
            echo "Search SQL: $sql<br>\n";
        }

        $q = DbConnector::GetPreparedStatement($sql);
        if ($owner_id) {
            $q->bindValue(':owner_id', $owner_id, PDO::PARAM_INT);
        }
        $q->bindValue(':title_term', $like, PDO::PARAM_STR);
        $q->bindValue(':body_term', $like, PDO::PARAM_STR);
        $q->execute();

        if ($only_count) {
            return $q->fetchColumn();
        }
        $q->setFetchMode(PDO::FETCH_OBJ);
        return $q;
    }

}
