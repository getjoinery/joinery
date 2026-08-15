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

    // Per-conversation encryption posture (specs/joinery_ai_chat_encryption.md).
    // 'standard' = server-managed plaintext; 'private' = title/instructions and
    // every turn sealed at rest, unlock required to read; 'fortress' = private +
    // inference pinned to a local model (nothing leaves the box). The level is
    // cleartext operational metadata so the list renders/sorts while locked.
    const LEVEL_STANDARD = 'standard';
    const LEVEL_PRIVATE  = 'private';
    const LEVEL_FORTRESS = 'fortress';

    // Sealed Vault generic read hook (docs/sealed_vault.md): decrypted
    // transparently by SystemBase::get() for a loaded model. aic_title is derived
    // from the first message (content in miniature) and aic_instructions is a
    // user-authored system-prompt override — both content, sealed on a protected
    // conversation. Every other aic column is operational metadata (cleartext).
    public static $sealed_fields = array('aic_title', 'aic_instructions');

    // Sealing runs through ChatSeal, which seals the conversation and its
    // messages under one policy read from aic_security_level.
    public static $seal_on_save = false;

    // aic_owner_user_id doesn't fit the {prefix}_{owner_prefix}_..._id
    // convention (the owning User's own prefix isn't in the column), so it
    // needs an explicit source table. Action is permanent_delete rather than
    // a flat cascade because each conversation has its own message/attachment
    // cleanup (see AiConversationMessage::permanent_delete()) that a flat
    // DELETE would bypass.
    protected static $foreign_key_actions = [
        'aic_owner_user_id' => ['action' => 'permanent_delete', 'source_table' => 'usr_users'],
    ];

    public static $field_specifications = array(
        'aic_conversation_id'    => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
        'aic_owner_user_id'      => array('type'=>'int4', 'required'=>true),
        // 'text' (not varchar(255)) — sealed ciphertext + base64 overhead exceeds
        // 255 (specs/joinery_ai_chat_encryption.md § schema notes).
        'aic_title'              => array('type'=>'text'),
        'aic_model'              => array('type'=>'varchar(100)'),
        // Encryption posture — cleartext operational metadata (see the level consts).
        'aic_security_level'     => array('type'=>'varchar(20)', 'is_nullable'=>false, 'default'=>'standard'),
        // Sealed Vault consumer columns (docs/sealed_vault.md § consumer contract).
        // aic_sealed_key is the per-conversation DEK sealed to the owner's vault
        // public key (title + instructions seal under it); aic_key_generation
        // matches the vault generation the DEK is sealed to (for rotation);
        // aic_content_sealed marks a row whose title/instructions hold ciphertext.
        'aic_sealed_key'         => array('type'=>'text', 'is_nullable'=>true),
        'aic_key_generation'     => array('type'=>'int4', 'is_nullable'=>false, 'default'=>0),
        'aic_content_sealed'     => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
        // Durable egress posture. Set true the first time any turn in this
        // conversation opens sealed content — a tool reading protected mail/drive,
        // or (on a protected conversation) decrypting its own sealed history. Once
        // set it never clears: the transcript now holds sealed-derived context, so
        // a later cold-start turn in a fresh process could otherwise smuggle it out
        // inside an outbound URL/query. Every later turn arms egress restriction
        // from this flag, gating the web tools behind the owner's approval.
        // See specs/ai_hot_turn_egress_approval.md and docs/sealed_vault.md.
        'aic_egress_restricted'  => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
        // Pinned threads sort above the rest in the chat list. Non-nullable so the
        // ORDER BY aic_pinned DESC is clean (NULL would sort ahead of true).
        'aic_pinned'             => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
        // Per-chat capability toggles (all default off → a plain conversational
        // assistant). Data access gates the site-data tool group + model scope;
        // web search gates the web tool group; history access gates the
        // search_conversations tool (search across the owner's own past chats);
        // memory access gates the remember/recall/forget tools + the two-layer
        // memory context (the composer seeds it from joinery_ai_memory_default_on,
        // so the column default stays false like its siblings).
        // See the chat-capabilities spec, specs/chat_search_past_conversations_tool.md,
        // and specs/joinery_ai_memory.md.
        'aic_data_access'        => array('type'=>'bool', 'default'=>false),
        'aic_web_search'         => array('type'=>'bool', 'default'=>false),
        'aic_history_access'     => array('type'=>'bool', 'default'=>false),
        'aic_memory_access'      => array('type'=>'bool', 'default'=>false),
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

    // A sealed conversation must NEVER be save()d: SystemBase::save() rebuilds
    // every column through get(), which decrypts aic_title/aic_instructions and
    // would write plaintext back (unsealing them) or throw when locked. Every
    // write to a protected conversation — token rollups, pin, rename, control
    // edits, seal/reseal — goes through SystemBase::updateColumns() instead.

    /** Whether this conversation seals its content at rest (private or fortress). */
    public function isProtected(): bool {
        $level = (string)$this->get('aic_security_level');
        return $level === self::LEVEL_PRIVATE || $level === self::LEVEL_FORTRESS;
    }

    /**
     * Soft-delete via a targeted UPDATE, not the base save() path: SystemBase::
     * soft_delete() rebuilds every column through get(), which on a sealed
     * conversation would decrypt aic_title/aic_instructions and write them back
     * as plaintext (unsealing a merely-soft-deleted, still-recoverable row) or
     * throw when the vault is locked.
     */
    public function soft_delete() {
        self::updateColumns((int)$this->key, ['aic_delete_time' => gmdate('Y-m-d H:i:s')]);
        $this->set('aic_delete_time', gmdate('Y-m-d H:i:s'));
        return true;
    }

    /**
     * Sealed Vault read hook (docs/sealed_vault.md), the SystemBase::get() path.
     * A value is decrypted only when the row is marked sealed AND the value
     * carries the sealed-blob prefix — an empty or not-yet-sealed field on a
     * protected row (or any Standard row) is returned untouched. Throws
     * VaultLockedException when the owner's vault window is closed.
     */
    protected function decryptSealedField($field, $ciphertext) {
        if (!$this->get('aic_content_sealed') || !is_string($ciphertext)
                || strpos($ciphertext, 'v1.aead.') !== 0) {
            return $ciphertext;
        }
        $owner = (int)$this->get('aic_owner_user_id');
        $sealed_key = (string)$this->get('aic_sealed_key');
        if ($owner <= 0 || $sealed_key === '') {
            require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
            throw new VaultLockedException();
        }
        require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSeal.php'));
        return ChatSeal::openConversationField((int)$this->key, $owner, $sealed_key, $field, $ciphertext);
    }

    /** Same, for a raw associative row (no $this) — the sealed-field contract's
     *  static half. Chat conversations are not $ai_readable, so this path is not
     *  exercised by ModelQueryExecutor, but the contract requires it. */
    public static function decryptSealedFieldStatic($field, $ciphertext, array $row) {
        if (empty($row['aic_content_sealed']) || !is_string($ciphertext)
                || strpos($ciphertext, 'v1.aead.') !== 0) {
            return $ciphertext;
        }
        $owner = (int)($row['aic_owner_user_id'] ?? 0);
        $sealed_key = (string)($row['aic_sealed_key'] ?? '');
        if ($owner <= 0 || $sealed_key === '') {
            require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
            throw new VaultLockedException();
        }
        require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSeal.php'));
        return ChatSeal::openConversationField((int)($row['aic_conversation_id'] ?? 0), $owner, $sealed_key, $field, $ciphertext);
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

    /**
     * Title-or-content search. Standard chats match via SQL ILIKE (title + any
     * message body — fast). Protected chats hold ciphertext in those columns, so
     * the ILIKE can never scan them: they are matched separately by an in-window
     * decrypt-filter (specs/joinery_ai_chat_encryption.md § Phase 4 — chat volume
     * is far lower than a mail archive, so no FTS5 index is needed). A locked
     * vault simply yields no protected matches; the caller adds a `locked` flag so
     * the client prompts unlock and re-searches. Returns a PDOStatement of matched
     * rows (or an int count), matching _get_resultsv2's contract.
     */
    protected function getSearchResults($search, $only_count, $debug) {
        $deleted  = isset($this->options['deleted']) && $this->options['deleted'];
        $owner_id = isset($this->options['owner_user_id']) ? (int)$this->options['owner_user_id'] : 0;
        $like     = '%' . addcslashes($search, '\\%_') . '%';
        $del_sql  = $deleted ? 'IS NOT NULL' : 'IS NULL';

        // 1. Standard chats via SQL ILIKE (level pinned to 'standard' — a protected
        //    row's ciphertext columns must never be scanned this way).
        $std_where = ['aic_delete_time ' . $del_sql, "aic_security_level = 'standard'"];
        if ($owner_id) $std_where[] = 'aic_owner_user_id = :owner_id';
        $std_where[] = '(aic_title ILIKE :title_term OR EXISTS ('
                     . 'SELECT 1 FROM aim_conversation_messages '
                     . 'WHERE aim_aic_conversation_id = aic_conversation_id '
                     . 'AND aim_delete_time IS NULL AND aim_content ILIKE :body_term))';
        $sql = 'SELECT aic_conversation_id FROM aic_conversations WHERE ' . implode(' AND ', $std_where);
        if ($debug) echo "Search SQL (standard): $sql<br>\n";
        $q = DbConnector::GetPreparedStatement($sql);
        if ($owner_id) $q->bindValue(':owner_id', $owner_id, PDO::PARAM_INT);
        $q->bindValue(':title_term', $like, PDO::PARAM_STR);
        $q->bindValue(':body_term', $like, PDO::PARAM_STR);
        $q->execute();
        $ids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));

        // 2. Protected chats via in-window decrypt-filter (only when unlocked).
        require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSeal.php'));
        if ($owner_id && ChatSeal::windowOpenFor($owner_id)) {
            $psql = 'SELECT aic_conversation_id FROM aic_conversations WHERE aic_delete_time ' . $del_sql
                  . ' AND aic_owner_user_id = :powner'
                  . " AND aic_security_level IN ('private','fortress')";
            $pq = DbConnector::GetPreparedStatement($psql);
            $pq->bindValue(':powner', $owner_id, PDO::PARAM_INT);
            $pq->execute();
            $needle = function_exists('mb_strtolower') ? mb_strtolower($search) : strtolower($search);
            foreach ($pq->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                $c = new AiConversation((int)$pid, true);
                if ($c->key && self::protectedConversationMatches($c, $needle)) {
                    $ids[] = (int)$pid;
                }
            }
        }
        $ids = array_values(array_unique($ids));

        if ($only_count) return count($ids);
        if (empty($ids)) {
            $empty = DbConnector::GetPreparedStatement('SELECT * FROM aic_conversations WHERE 1=0');
            $empty->execute();
            $empty->setFetchMode(PDO::FETCH_OBJ);
            return $empty;
        }
        $in = implode(',', array_map('intval', $ids));
        $q2 = DbConnector::GetPreparedStatement(
            "SELECT * FROM aic_conversations WHERE aic_conversation_id IN ($in) "
            . 'ORDER BY aic_pinned DESC, aic_update_time DESC');
        $q2->execute();
        $q2->setFetchMode(PDO::FETCH_OBJ);
        return $q2;
    }

    /** In-window match of a protected conversation's decrypted title or any
     *  decrypted message body against a lowercased needle. A locked read mid-scan
     *  (window closed between the caller's check and here) returns false. */
    private static function protectedConversationMatches(AiConversation $c, string $needle): bool {
        require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversation_messages_class.php'));
        $lc = function ($s) { return function_exists('mb_strtolower') ? mb_strtolower((string)$s) : strtolower((string)$s); };
        try {
            if ($needle !== '' && strpos($lc($c->get('aic_title')), $needle) !== false) return true;
        } catch (Throwable $e) {
            return false;
        }
        $rows = new MultiAiConversationMessage(
            ['conversation_id' => (int)$c->key, 'deleted' => false], ['aim_message_id' => 'ASC']);
        $rows->load();
        foreach ($rows as $m) {
            try {
                $content = $lc($m->get('aim_content'));
                if ($content !== '' && strpos($content, $needle) !== false) return true;
            } catch (Throwable $e) {
                return false;
            }
        }
        return false;
    }

    /** Whether the owner has any protected conversation while their vault is
     *  locked — so a search response can carry a `locked` flag prompting unlock. */
    public static function ownerHasLockedProtected(int $owner_id): bool {
        if ($owner_id <= 0) return false;
        require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSeal.php'));
        if (ChatSeal::windowOpenFor($owner_id)) return false;
        return self::ownerHasProtected($owner_id);
    }

    /** Whether the owner has any non-deleted protected conversation at all —
     *  a cheap, query-independent existence check (no content, no ciphertext read).
     *  This is the only probe the withheld path runs (§4.4). */
    public static function ownerHasProtected(int $owner_id): bool {
        if ($owner_id <= 0) return false;
        $q = DbConnector::get_instance()->get_db_link()->prepare(
            'SELECT 1 FROM aic_conversations WHERE aic_owner_user_id = ? AND aic_delete_time IS NULL '
            . "AND aic_security_level IN ('private','fortress') LIMIT 1");
        $q->execute([$owner_id]);
        return (bool)$q->fetchColumn();
    }

    /** Most-recent protected conversations decrypt-scanned per search on the
     *  surfaced (local + vault-open) path. Beyond this the scan stops and the
     *  caller reports `protected_capped` rather than imply a complete result. The
     *  cap is a local-turn concern only — the withheld path never scans (§4.4/§7). */
    const PROTECTED_SCAN_CAP = 50;

    /** Half-width (chars) of the context window pulled around a snippet match. */
    const SNIPPET_RADIUS = 90;

    /**
     * The search seam behind the `search_conversations` chat tool
     * (specs/chat_search_past_conversations_tool.md §4.4). Everything that touches
     * ciphertext, a vault window, or the surface-or-acknowledge gate lives here, so
     * the tool never handles crypto and this stays the single swap point for a
     * future sealed FTS index (§9).
     *
     * Returns:
     *   ['matches' => [ {id, level, title, snippet, date} ... ],  // standard always;
     *                                                             // protected only when surfaced
     *    'protected_withheld' => bool,   // owner HAS protected chats not searched this turn
     *    'protected_capped'   => bool,   // (surfaced path only) more protected chats than the scan cap
     *    'locked'             => bool ]  // owner has protected chats but the vault is locked
     *
     * $surface_protected true (the turn is on a local model AND the vault window is
     * open) decrypt-scans protected chats in-window and surfaces their matches;
     * false (remote model, or vault locked) NEVER decrypts or scans — it runs only
     * the query-independent existence check, so there is no per-query count to leak
     * as a metadata oracle (§3).
     *
     * $exclude_id (> 0) drops that conversation from both scans — the caller passes
     * the active chat so the tool never "finds" the thread it is already in (§7).
     * Standard and surfaced-protected matches are merged into a single list ordered
     * the way the chat list is (pinned first, then newest) and capped once at $limit,
     * so a recent protected match is never crowded out by older standard ones.
     */
    public static function searchForTool(int $owner_id, string $query, int $limit, bool $surface_protected, int $exclude_id = 0): array {
        $out = ['matches' => [], 'protected_withheld' => false, 'protected_capped' => false, 'locked' => false];
        $query = trim($query);
        if ($owner_id <= 0 || $query === '' || $limit < 1) return $out;

        $db = DbConnector::get_instance()->get_db_link();
        $like = '%' . addcslashes($query, '\\%_') . '%';
        $excl = $exclude_id > 0 ? ' AND aic_conversation_id <> :excl' : '';

        // 1. Standard chats — plaintext at rest, matched by SQL ILIKE (title OR any
        //    non-deleted message body). Fetch up to $limit candidates; the merge
        //    below ranks them against any surfaced protected matches and caps once.
        $sql = "SELECT aic_conversation_id,
                       CASE WHEN aic_pinned THEN 1 ELSE 0 END AS pinned_rank,
                       aic_title,
                       COALESCE(aic_update_time, aic_create_time) AS aic_when
                  FROM aic_conversations
                 WHERE aic_owner_user_id = :owner AND aic_delete_time IS NULL
                   AND aic_security_level = 'standard'$excl
                   AND (aic_title ILIKE :t OR EXISTS (
                        SELECT 1 FROM aim_conversation_messages
                         WHERE aim_aic_conversation_id = aic_conversation_id
                           AND aim_delete_time IS NULL AND aim_content ILIKE :b))
                 ORDER BY pinned_rank DESC, aic_when DESC
                 LIMIT :lim";
        $q = $db->prepare($sql);
        $q->bindValue(':owner', $owner_id, PDO::PARAM_INT);
        $q->bindValue(':t', $like, PDO::PARAM_STR);
        $q->bindValue(':b', $like, PDO::PARAM_STR);
        $q->bindValue(':lim', $limit, PDO::PARAM_INT);
        if ($exclude_id > 0) $q->bindValue(':excl', $exclude_id, PDO::PARAM_INT);
        $q->execute();
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cid = (int)$row['aic_conversation_id'];
            $out['matches'][] = [
                'id'      => $cid,
                'level'   => 'standard',
                'title'   => (string)$row['aic_title'],
                'snippet' => self::standardSnippet($db, $cid, $query, $like),
                'date'    => (string)$row['aic_when'],
                '_pinned' => (int)$row['pinned_rank'],
            ];
        }

        // 2. Protected chats.
        require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSeal.php'));
        if ($surface_protected && ChatSeal::windowOpenFor($owner_id)) {
            // Surfaced path (already-trusted local turn): decrypt-scan in-window,
            // bounded by the candidate cap so an owner with many protected chats
            // can't turn one tool call into an unbounded decrypt loop. Collect every
            // match (not stopping at $limit) so the merge below can rank a recent
            // protected chat above older standard ones.
            $psql = "SELECT aic_conversation_id FROM aic_conversations
                      WHERE aic_owner_user_id = :owner AND aic_delete_time IS NULL
                        AND aic_security_level IN ('private','fortress')$excl
                      ORDER BY aic_pinned DESC, aic_update_time DESC
                      LIMIT :lim";
            $pq = $db->prepare($psql);
            $pq->bindValue(':owner', $owner_id, PDO::PARAM_INT);
            $pq->bindValue(':lim', self::PROTECTED_SCAN_CAP + 1, PDO::PARAM_INT);
            if ($exclude_id > 0) $pq->bindValue(':excl', $exclude_id, PDO::PARAM_INT);
            $pq->execute();
            $pids = array_map('intval', $pq->fetchAll(PDO::FETCH_COLUMN));
            if (count($pids) > self::PROTECTED_SCAN_CAP) {
                $out['protected_capped'] = true;
                $pids = array_slice($pids, 0, self::PROTECTED_SCAN_CAP);
            }
            foreach ($pids as $pid) {
                $c = new AiConversation($pid, true);
                if (!$c->key) continue;
                $hit = self::protectedSnippet($c, $query);
                if ($hit !== null) {
                    $out['matches'][] = [
                        'id'      => $pid,
                        'level'   => (string)$c->get('aic_security_level'),
                        'title'   => $hit['title'],
                        'snippet' => $hit['snippet'],
                        'date'    => (string)($c->get('aic_update_time') ?: $c->get('aic_create_time')),
                        '_pinned' => (bool)$c->get('aic_pinned') ? 1 : 0,
                    ];
                }
            }
        } else {
            // Withheld path: NEVER decrypt or scan. Only the query-independent
            // existence check, so nothing here reads content or the query — no count
            // to leak, no oracle to probe (§3).
            if (self::ownerHasProtected($owner_id)) {
                $out['protected_withheld'] = true;
                $out['locked'] = !ChatSeal::windowOpenFor($owner_id);
            }
        }

        // Merge standard + surfaced-protected into one list, ranked pinned-first
        // then newest (DB times are ISO UTC strings, so strcmp is chronological),
        // and cap once at $limit. The scan cost is still bounded by the candidate
        // cap above, never by $limit (§7).
        usort($out['matches'], function ($a, $b) {
            if ($a['_pinned'] !== $b['_pinned']) return $b['_pinned'] <=> $a['_pinned'];
            return strcmp((string)$b['date'], (string)$a['date']);
        });
        if (count($out['matches']) > $limit) {
            $out['matches'] = array_slice($out['matches'], 0, $limit);
        }
        foreach ($out['matches'] as &$m) unset($m['_pinned']);
        unset($m);
        return $out;
    }

    /** Snippet for a standard (plaintext) match: a window around the query in the
     *  first matching message body, else the opening of the thread for context. */
    private static function standardSnippet(PDO $db, int $cid, string $query, string $like): string {
        $mq = $db->prepare(
            "SELECT aim_content FROM aim_conversation_messages
              WHERE aim_aic_conversation_id = ? AND aim_delete_time IS NULL AND aim_content ILIKE ?
              ORDER BY aim_message_id ASC LIMIT 1");
        $mq->execute([$cid, $like]);
        $body = (string)$mq->fetchColumn();
        if ($body !== '') return self::snippetAround($body, $query);

        // Title-only match: show the thread's opening line for context.
        $oq = $db->prepare(
            "SELECT aim_content FROM aim_conversation_messages
              WHERE aim_aic_conversation_id = ? AND aim_delete_time IS NULL
              ORDER BY aim_message_id ASC LIMIT 1");
        $oq->execute([$cid]);
        return self::firstChars((string)$oq->fetchColumn(), self::SNIPPET_RADIUS * 2);
    }

    /** In-window snippet for a protected match: mirrors protectedConversationMatches()
     *  but captures the matching text. Returns ['title'=>.., 'snippet'=>..] on a
     *  match, or null (no match, or a locked read mid-scan). Content is decrypted
     *  via get(); the caller has already established the surfaced (trusted) path. */
    private static function protectedSnippet(AiConversation $c, string $query): ?array {
        require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversation_messages_class.php'));
        try {
            $title = (string)$c->get('aic_title');
        } catch (Throwable $e) {
            return null;
        }
        $rows = new MultiAiConversationMessage(
            ['conversation_id' => (int)$c->key, 'deleted' => false], ['aim_message_id' => 'ASC']);
        $rows->load();
        $first_body = '';
        foreach ($rows as $m) {
            try {
                $body = (string)$m->get('aim_content');
            } catch (Throwable $e) {
                return null;
            }
            if ($first_body === '') $first_body = $body;
            if ($query !== '' && self::containsCI($body, $query)) {
                return ['title' => $title, 'snippet' => self::snippetAround($body, $query)];
            }
        }
        if ($query !== '' && self::containsCI($title, $query)) {
            return ['title' => $title, 'snippet' => self::firstChars($first_body, self::SNIPPET_RADIUS * 2)];
        }
        return null;
    }

    /** Case-insensitive substring test, mbstring where available. */
    private static function containsCI(string $haystack, string $needle): bool {
        if ($needle === '') return false;
        if (function_exists('mb_stripos')) return mb_stripos($haystack, $needle) !== false;
        return stripos($haystack, $needle) !== false;
    }

    /** A whitespace-collapsed window of text centered on the (case-insensitive)
     *  query, with ellipses where it was clipped. Falls back to the leading slice
     *  when the query isn't present (e.g. a title-only match feeding a body). */
    private static function snippetAround(string $text, string $query): string {
        $text = trim((string)preg_replace('/\s+/', ' ', $text));
        if ($text === '') return '';
        $pos = ($query !== '' && function_exists('mb_stripos')) ? mb_stripos($text, $query)
             : ($query !== '' ? stripos($text, $query) : false);
        if ($pos === false) return self::firstChars($text, self::SNIPPET_RADIUS * 2);

        $len_fn = function_exists('mb_strlen') ? 'mb_strlen' : 'strlen';
        $sub_fn = function_exists('mb_substr') ? 'mb_substr' : 'substr';
        $qlen = $len_fn($query);
        $start = max(0, $pos - self::SNIPPET_RADIUS);
        $slice = $sub_fn($text, $start, self::SNIPPET_RADIUS * 2 + $qlen);
        $prefix = $start > 0 ? '…' : '';
        $suffix = ($start + $len_fn($slice)) < $len_fn($text) ? '…' : '';
        return $prefix . trim($slice) . $suffix;
    }

    /** Whitespace-collapsed leading slice, ellipsized if it was clipped. */
    private static function firstChars(string $text, int $n): string {
        $text = trim((string)preg_replace('/\s+/', ' ', $text));
        $len_fn = function_exists('mb_strlen') ? 'mb_strlen' : 'strlen';
        $sub_fn = function_exists('mb_substr') ? 'mb_substr' : 'substr';
        return $len_fn($text) > $n ? $sub_fn($text, 0, $n) . '…' : $text;
    }

}
