<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class AiMemoryException extends SystemBaseException {}

/**
 * One durable AI memory (specs/joinery_ai_memory.md): a fact the assistant can
 * recall across separate chats and recipe runs. Two ownership scopes:
 * 'user' rows belong to one member (mem_owner_user_id set); 'shared' rows are
 * the admin-curated org pool (owner NULL — they belong to the org, not a
 * person) and are readable by every user's recall.
 *
 * Distinct from RecipeNote (rcn_notes): notes upsert by title (a mutable
 * scratchpad), memories accumulate (each fact is its own row) and add the
 * shared scope. They share a storage shape but not a lifecycle.
 *
 * Deliberately NOT $ai_readable: the generic model tools owner-scope by a
 * single owner column, and a shared row (NULL owner) would either leak or
 * vanish under that logic. Access goes only through the dedicated
 * remember/recall/forget tools, which enforce the scope rules explicitly —
 * one code path owns access, and the shared pool can't leak through
 * query_model.
 */
class AiMemory extends SystemBase {

    public static $prefix = 'mem';
    public static $tablename = 'mem_memories';
    public static $pkey_column = 'mem_memory_id';

    public static $ai_readable = false;

    const SCOPE_USER   = 'user';
    const SCOPE_SHARED = 'shared';

    const SOURCE_AI    = 'ai';
    const SOURCE_USER  = 'user';
    const SOURCE_ADMIN = 'admin';

    const MAX_TITLE_LEN     = 255;
    const MAX_CONTENT_CHARS = 50000;

    public static $field_specifications = array(
        'mem_memory_id'          => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
        // NULL only for shared rows (the org owns them, not a person).
        'mem_owner_user_id'      => array('type'=>'int4', 'is_nullable'=>true),
        // Which human authored a shared row (audit); SET NULL on user deletion so
        // removing an admin never deletes or orphans the shared pool.
        'mem_created_by_user_id' => array('type'=>'int4', 'is_nullable'=>true),
        'mem_scope'              => array('type'=>'varchar(16)', 'required'=>true, 'default'=>'user', 'allowed_values'=>array(self::SCOPE_USER, self::SCOPE_SHARED)),
        'mem_title'              => array('type'=>'varchar(255)'),
        'mem_content'            => array('type'=>'text', 'required'=>true),
        'mem_tags'               => array('type'=>'jsonb'),
        // Who created it ('ai' | 'user' | 'admin') — origin provenance for the UI
        // badge. Not rewritten when a human later edits an AI-created memory.
        'mem_source'             => array('type'=>'varchar(16)', 'required'=>true, 'default'=>'user', 'allowed_values'=>array(self::SOURCE_AI, self::SOURCE_USER, self::SOURCE_ADMIN)),
        'mem_create_time'        => array('type'=>'timestamp(6)', 'default'=>'now()'),
        'mem_update_time'        => array('type'=>'timestamp(6)'),
        'mem_delete_time'        => array('type'=>'timestamp(6)'),
    );

    public static $json_vars = array('mem_tags');

    // mem_owner_user_id / mem_created_by_user_id don't fit the
    // {prefix}_{owner_prefix}_..._id convention, so both need an explicit
    // source table. Owner cascades (a deleted user's private memories go with
    // them; shared rows have a NULL owner and are untouched); created_by is
    // only an audit pointer, nulled so the shared memory itself survives.
    protected static $foreign_key_actions = [
        'mem_owner_user_id'      => ['action' => 'cascade', 'source_table' => 'usr_users'],
        'mem_created_by_user_id' => ['action' => 'null',    'source_table' => 'usr_users'],
    ];

    // Generic CRUD testing (ModelTester) can't infer the scope/owner pairing
    // rule: user-scope rows need an owner, shared-scope rows must not have
    // one. Pin the scope whose validity doesn't depend on another row, and
    // point the update probe at a field with no enum validation.
    public static $test_fixture = array(
        'values' => array('mem_scope' => self::SCOPE_SHARED),
        'update_field' => 'mem_content',
    );

    /** Validation runs from BOTH prepare() and save() — prepare() is not
     *  guaranteed to run first, and a bad scope/source must fail closed rather
     *  than become an unqueryable row. */
    private function validateRow() {
        // Normalize nulls to the spec defaults so a fresh row validates the
        // same values save() would persist.
        if ($this->get('mem_scope') === NULL)  $this->set('mem_scope', self::SCOPE_USER);
        if ($this->get('mem_source') === NULL) $this->set('mem_source', self::SOURCE_USER);

        $scope = (string)$this->get('mem_scope');
        if (!in_array($scope, [self::SCOPE_USER, self::SCOPE_SHARED], true)) {
            throw new AiMemoryException("Invalid memory scope '$scope'.");
        }
        $source = (string)$this->get('mem_source');
        if (!in_array($source, [self::SOURCE_AI, self::SOURCE_USER, self::SOURCE_ADMIN], true)) {
            throw new AiMemoryException("Invalid memory source '$source'.");
        }
        if ($scope === self::SCOPE_USER && !(int)$this->get('mem_owner_user_id')) {
            throw new AiMemoryException('A user-scope memory requires an owner.');
        }
        if ($scope === self::SCOPE_SHARED && $this->get('mem_owner_user_id') !== NULL) {
            throw new AiMemoryException('A shared memory cannot have an owner.');
        }
        if (trim((string)$this->get('mem_content')) === '') {
            throw new AiMemoryException('A memory needs content.');
        }
        if (mb_strlen((string)$this->get('mem_content')) > self::MAX_CONTENT_CHARS) {
            throw new AiMemoryException('Memory content exceeds ' . self::MAX_CONTENT_CHARS . ' characters.');
        }
        if (mb_strlen((string)$this->get('mem_title')) > self::MAX_TITLE_LEN) {
            throw new AiMemoryException('Memory title exceeds ' . self::MAX_TITLE_LEN . ' characters.');
        }
    }

    function prepare() {
        $this->validateRow();
        parent::prepare();
    }

    function save($debug = false) {
        $this->validateRow();
        return parent::save($debug);
    }

    function authenticate_write($data) {
        // Shared rows and cross-user edits are admin-only; a member (or the
        // owner-scoped API surface) may only touch their own user-scope rows.
        if ($data['current_user_permission'] >= 10) return;
        if ((string)$this->get('mem_scope') === self::SCOPE_SHARED
                || (int)$this->get('mem_owner_user_id') !== (int)$data['current_user_id']) {
            throw new SystemAuthenticationError(
                'Cannot edit a memory you do not own.');
        }
    }

}

class MultiAiMemory extends SystemMultiBase {
    protected static $model_class = 'AiMemory';

    protected function getMultiResults($only_count = false, $debug = false) {
        $search = isset($this->options['search']) ? trim((string)$this->options['search']) : '';
        if ($search !== '') {
            // ILIKE across title+content needs an OR with a bound parameter,
            // which the filter system can't express — own prepared statement,
            // same return contract.
            return $this->getSearchResults($search, $only_count, $debug);
        }

        $filters = [];

        if (isset($this->options['owner_user_id'])) {
            $filters['mem_owner_user_id'] = [$this->options['owner_user_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['scope'])) {
            $filters['mem_scope'] = [$this->options['scope'], PDO::PARAM_STR];
        }

        if (isset($this->options['source'])) {
            $filters['mem_source'] = [$this->options['source'], PDO::PARAM_STR];
        }

        if (isset($this->options['ids'])) {
            $ids = array_map('intval', (array)$this->options['ids']);
            $ids = array_values(array_filter($ids, fn($i) => $i > 0));
            if (empty($ids)) $ids = [0];   // never matches, never malformed SQL
            $filters['mem_memory_id'] = 'IN (' . implode(',', $ids) . ')';
        }

        if (isset($this->options['deleted'])) {
            $filters['mem_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
        } else {
            $filters['mem_delete_time'] = "IS NULL";
        }

        return $this->_get_resultsv2('mem_memories', $filters, $this->order_by, $only_count, $debug);
    }

    /** Same option keys as getMultiResults, plus a bound ILIKE over
     *  title+content. Returns a PDOStatement (or an int count). */
    protected function getSearchResults($search, $only_count, $debug) {
        $where  = [];
        $params = [];

        $deleted = isset($this->options['deleted']) && $this->options['deleted'];
        $where[] = 'mem_delete_time ' . ($deleted ? 'IS NOT NULL' : 'IS NULL');

        if (isset($this->options['owner_user_id'])) {
            $where[]  = 'mem_owner_user_id = ?';
            $params[] = (int)$this->options['owner_user_id'];
        }
        if (isset($this->options['scope'])) {
            $where[]  = 'mem_scope = ?';
            $params[] = (string)$this->options['scope'];
        }
        if (isset($this->options['source'])) {
            $where[]  = 'mem_source = ?';
            $params[] = (string)$this->options['source'];
        }
        if (isset($this->options['ids'])) {
            $ids = array_map('intval', (array)$this->options['ids']);
            $ids = array_values(array_filter($ids, fn($i) => $i > 0));
            if (empty($ids)) $ids = [0];
            $where[] = 'mem_memory_id IN (' . implode(',', $ids) . ')';
        }

        $like = '%' . addcslashes($search, '\\%_') . '%';
        $where[]  = '(mem_title ILIKE ? OR mem_content ILIKE ?)';
        $params[] = $like;
        $params[] = $like;

        $order = '';
        if (!empty($this->order_by)) {
            $parts = [];
            foreach ($this->order_by as $col => $dir) {
                if (!array_key_exists($col, AiMemory::$field_specifications)) continue;
                $parts[] = $col . ' ' . (strtoupper((string)$dir) === 'ASC' ? 'ASC' : 'DESC');
            }
            if ($parts) $order = ' ORDER BY ' . implode(', ', $parts);
        }

        $select = $only_count ? 'COUNT(*)' : '*';
        $sql = "SELECT $select FROM mem_memories WHERE " . implode(' AND ', $where)
             . ($only_count ? '' : $order);
        if ($debug) echo "Memory search SQL: $sql<br>\n";

        $q = DbConnector::get_instance()->get_db_link()->prepare($sql);
        $q->execute($params);
        if ($only_count) return (int)$q->fetchColumn();
        $q->setFetchMode(PDO::FETCH_OBJ);
        return $q;
    }

    // ------------------------------------------------------------------
    // The runtime seam (specs/joinery_ai_memory.md § tools / § runtime).
    // Everything the tools and the per-turn injection read goes through these
    // scope-aware helpers, so "own user rows + all shared rows" is written in
    // exactly one place.
    // ------------------------------------------------------------------

    /** WHERE fragment + params for the caller's in-scope rows.
     *  $scope: 'all' (own + shared), 'mine' (own only), 'shared' (shared only). */
    private static function scopeSql(int $uid, string $scope): array {
        $mine   = "(mem_scope = 'user' AND mem_owner_user_id = ?)";
        $shared = "(mem_scope = 'shared')";
        switch ($scope) {
            case 'mine':   return [$mine, [$uid]];
            case 'shared': return [$shared, []];
            default:       return ["($shared OR $mine)", [$uid]];
        }
    }

    /**
     * The recall tool's read: the acting user's own user-rows + all shared
     * rows, optionally narrowed by $scope, matched by bound ILIKE ($query) or
     * an id list ($ids) — ids are filtered through the same scope, never
     * fetched blind, so a guessed id for another user's memory returns nothing
     * and leaks nothing. Rows ordered by recency. Returns assoc arrays.
     */
    public static function recallRows(int $uid, string $query, array $ids, int $limit, string $scope = 'all'): array {
        if ($uid <= 0 || $limit < 1) return [];
        [$scope_sql, $params] = self::scopeSql($uid, $scope);
        $where = ['mem_delete_time IS NULL', $scope_sql];

        $ids = array_values(array_filter(array_map('intval', $ids), fn($i) => $i > 0));
        if (!empty($ids)) {
            $where[] = 'mem_memory_id IN (' . implode(',', $ids) . ')';
        }
        if ($query !== '') {
            $like = '%' . addcslashes($query, '\\%_') . '%';
            $where[]  = '(mem_title ILIKE ? OR mem_content ILIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }
        if (empty($ids) && $query === '') return [];

        $sql = 'SELECT * FROM mem_memories WHERE ' . implode(' AND ', $where)
             . ' ORDER BY COALESCE(mem_update_time, mem_create_time) DESC LIMIT ' . (int)$limit;
        $q = DbConnector::get_instance()->get_db_link()->prepare($sql);
        $q->execute($params);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** How many non-deleted rows are in the caller's scope (own + shared) —
     *  the denominator for the pre-retrieval selectivity guard. */
    public static function inScopeCount(int $uid): int {
        [$scope_sql, $params] = self::scopeSql($uid, 'all');
        $q = DbConnector::get_instance()->get_db_link()->prepare(
            'SELECT COUNT(*) FROM mem_memories WHERE mem_delete_time IS NULL AND ' . $scope_sql);
        $q->execute($params);
        return (int)$q->fetchColumn();
    }

    /** Per-term in-scope match counts (title OR content, bound ILIKE) in one
     *  scan — feeds the selectivity guard. Returns [term => count]. */
    public static function termMatchCounts(int $uid, array $terms): array {
        $terms = array_values(array_unique(array_filter(array_map('strval', $terms), 'strlen')));
        if ($uid <= 0 || empty($terms)) return [];
        [$scope_sql, $scope_params] = self::scopeSql($uid, 'all');

        // Positional params bind in the order the ?s appear in the SQL: the
        // FILTER clauses (SELECT list) first, then the scope clause (WHERE).
        $selects = [];
        $term_params = [];
        foreach ($terms as $i => $term) {
            $selects[] = "COUNT(*) FILTER (WHERE mem_title ILIKE ? OR mem_content ILIKE ?) AS t$i";
            $like = '%' . addcslashes($term, '\\%_') . '%';
            array_push($term_params, $like, $like);
        }
        $sql = 'SELECT ' . implode(', ', $selects)
             . ' FROM mem_memories WHERE mem_delete_time IS NULL AND ' . $scope_sql;
        $q = DbConnector::get_instance()->get_db_link()->prepare($sql);
        $q->execute(array_merge($term_params, $scope_params));
        $row = $q->fetch(PDO::FETCH_ASSOC) ?: [];

        $out = [];
        foreach ($terms as $i => $term) {
            $out[$term] = (int)($row["t$i"] ?? 0);
        }
        return $out;
    }

    /**
     * Layer-1 pre-retrieval candidates: in-scope rows matching at least one
     * term, ranked most-distinct-terms-matched then most-recent. One scan of
     * the scoped set regardless of term count. Returns assoc arrays with a
     * `match_score` column.
     */
    public static function prefetchRows(int $uid, array $terms, int $limit): array {
        $terms = array_values(array_unique(array_filter(array_map('strval', $terms), 'strlen')));
        if ($uid <= 0 || empty($terms) || $limit < 1) return [];
        [$scope_sql, $scope_params] = self::scopeSql($uid, 'all');

        $score_parts = [];
        $match_parts = [];
        $term_params = [];
        foreach ($terms as $term) {
            $like = '%' . addcslashes($term, '\\%_') . '%';
            $score_parts[] = '(CASE WHEN mem_title ILIKE ? OR mem_content ILIKE ? THEN 1 ELSE 0 END)';
            array_push($term_params, $like, $like);
        }
        $score_sql = implode(' + ', $score_parts);

        $sql = "SELECT *, ($score_sql) AS match_score FROM mem_memories"
             . ' WHERE mem_delete_time IS NULL AND ' . $scope_sql
             . " AND ($score_sql) > 0"
             . ' ORDER BY match_score DESC, COALESCE(mem_update_time, mem_create_time) DESC'
             . ' LIMIT ' . (int)$limit;
        // $score_sql appears twice (SELECT + WHERE) — bind its params twice, in order.
        $params = array_merge($term_params, $scope_params, $term_params);
        $q = DbConnector::get_instance()->get_db_link()->prepare($sql);
        $q->execute($params);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Layer-2 title index rows: ALL shared rows + the user's personal rows
     * most-recent-first up to $personal_cap (curated org facts are few and
     * important — never crowded out by a big personal set). $exclude_ids drops
     * rows already pre-retrieved in Layer 1 (dedup by id). Returns assoc arrays,
     * shared first, each group recency-ordered.
     */
    public static function indexRows(int $uid, int $personal_cap, array $exclude_ids = []): array {
        if ($uid <= 0) return [];
        $exclude_ids = array_values(array_filter(array_map('intval', $exclude_ids), fn($i) => $i > 0));
        $excl = empty($exclude_ids) ? '' : ' AND mem_memory_id NOT IN (' . implode(',', $exclude_ids) . ')';
        $db = DbConnector::get_instance()->get_db_link();
        $cols = 'mem_memory_id, mem_title, mem_scope, mem_source, mem_tags, mem_create_time, mem_update_time';

        $q = $db->prepare("SELECT $cols FROM mem_memories WHERE mem_delete_time IS NULL"
            . " AND mem_scope = 'shared'$excl"
            . ' ORDER BY COALESCE(mem_update_time, mem_create_time) DESC');
        $q->execute();
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);

        if ($personal_cap > 0) {
            $q = $db->prepare("SELECT $cols FROM mem_memories WHERE mem_delete_time IS NULL"
                . " AND mem_scope = 'user' AND mem_owner_user_id = ?$excl"
                . ' ORDER BY COALESCE(mem_update_time, mem_create_time) DESC LIMIT ' . (int)$personal_cap);
            $q->execute([$uid]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[] = $r;
        }
        return $rows;
    }

}
