<?php
/**
 * ScaffoldGenerator — declarative CRUD code generator.
 *
 * Consumes one JSON manifest and produces a complete, validator-clean file set
 * (data class + Multi, public/admin logic + views) that follows the platform's
 * existing patterns. It is the reusable engine; utils/scaffold.php is a thin
 * CLI wrapper, and the same engine can later back an admin wizard or AI tool.
 *
 * Clean pure/impure split:
 *   files()  — returns [relative_path => rendered_contents]; pure, previewable.
 *   write()  — puts files() on disk and sets permissions per CLAUDE.md.
 *
 * Rendering uses simple PHP templates (includes/scaffold/templates/*.tpl.php) —
 * no template-engine dependency. The templates are the single source of truth
 * for generated output.
 *
 * Full reference: docs/scaffolding.md.
 */

// PathHelper, DbConnector, Globalvars are always preloaded — used directly.

class ScaffoldGeneratorException extends Exception {}

class ScaffoldGenerator {

    /** Page-set tokens a manifest may name in surfaces:. */
    const PAGE_SURFACES = ['public_list', 'public_edit', 'admin_list', 'admin_edit'];
    const ALL_SURFACES  = ['data', 'public_list', 'public_edit', 'admin_list', 'admin_edit'];
    const SURFACE_ALIASES = [
        'public' => ['public_list', 'public_edit'],
        'admin'  => ['admin_list', 'admin_edit'],
    ];

    /** Columns the API/AI write floor blocks by name regardless of declaration. */
    const CREDENTIAL_REGEX = '/_(password|secret|key|token|hash)$/i';

    protected $manifest;
    protected $ctx;        // computed derivation context (lazy, via buildContext())
    protected $warnings = [];   // non-fatal advisories from the last validate() call

    public function __construct(array $manifest) {
        $this->manifest = $manifest;
    }

    // ====================================================================
    // Validation
    // ====================================================================

    /**
     * Validate the manifest. Returns a list of human-readable error strings;
     * an empty list means the manifest is generatable. Read-only DB checks
     * (prefix/table collisions) are best-effort and skipped if no DB link.
     *
     * With $force, the two existence guards (table-already-exists and
     * prefix-already-used) are demoted from hard errors to warnings — `--force`
     * already means "overwrite the files," and equally means "the table may
     * already exist" (e.g. regenerating a class after a template fix). All other
     * validation stays hard. Retrieve demoted advisories via warnings().
     *
     * @return string[]
     */
    public function validate(bool $force = false): array {
        $m = $this->manifest;
        $errors = [];
        $this->warnings = [];

        // --- required scalars ---
        $entity = $m['entity'] ?? '';
        if ($entity === '' || !preg_match('/^[A-Z][A-Za-z0-9]*$/', $entity)) {
            $errors[] = "entity: required, must be PascalCase (e.g. 'Product').";
        }

        $prefix = $m['prefix'] ?? '';
        if (!preg_match('/^[a-z]{3}$/', $prefix)) {
            $errors[] = "prefix: required, must be exactly 3 lowercase letters.";
        }

        $plural = $m['plural'] ?? '';
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $plural)) {
            $errors[] = "plural: required, must be a bare lowercase snake slug (e.g. 'products').";
        }

        if (empty($m['fields']) || !is_array($m['fields'])) {
            $errors[] = "fields: required, must be a non-empty array.";
        }

        // --- surfaces ---
        $raw_surfaces = $m['surfaces'] ?? ['public', 'admin'];
        $valid_tokens = array_merge(self::ALL_SURFACES, array_keys(self::SURFACE_ALIASES));
        foreach ($raw_surfaces as $tok) {
            if (!in_array($tok, $valid_tokens, true)) {
                $errors[] = "surfaces: unknown token '$tok' (allowed: "
                    . implode(', ', $valid_tokens) . ").";
            }
        }
        if ($errors === [] || (is_array($raw_surfaces))) {
            $resolved = $this->resolveSurfaces($raw_surfaces);
            if (empty($resolved)) {
                $errors[] = "surfaces: resolves to an empty set after alias expansion.";
            }
        }

        // --- into: plugin dir must exist ---
        $into = $m['into'] ?? 'core';
        if ($into !== 'core') {
            if (strpos($into, 'plugins/') !== 0) {
                $errors[] = "into: must be 'core' or 'plugins/<name>'.";
            } else {
                $plugin_dir = PathHelper::getIncludePath($into);
                if (!is_dir($plugin_dir)) {
                    $errors[] = "into: plugin directory '$into' does not exist.";
                }
            }
        }

        // --- fields ---
        $supported = $this->supportedTypeRegex();
        $field_cols = [];           // bare-name => prefixed col
        $reserved = [];
        if (preg_match('/^[a-z]{3}$/', $prefix) && !empty($m['entity'])) {
            $reserved = [$prefix . '_' . $this->snake($m['entity']) . '_id', $prefix . '_delete_time'];
        }
        if (!empty($m['fields']) && is_array($m['fields'])) {
            foreach ($m['fields'] as $i => $f) {
                $name = $f['name'] ?? '';
                if ($name === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
                    $errors[] = "fields[$i].name: required, bare lowercase snake (no prefix).";
                    continue;
                }
                if (preg_match('/^[a-z]{3}$/', $prefix) && strpos($name, $prefix . '_') === 0) {
                    $errors[] = "fields[$i].name '$name': must be bare — do not include the '$prefix' prefix.";
                }
                $col = $prefix . '_' . $name;
                if (in_array($col, $reserved, true)) {
                    $errors[] = "fields[$i].name '$name': collides with the auto-generated $col column.";
                }
                $type = $f['type'] ?? '';
                if (preg_match('/^(small|big)?serial(2|4|8)?$/i', trim($type))) {
                    $errors[] = "fields[$i].type '$type': serial types are managed by the "
                        . "generator; declare the column as int8 and let the primary key be "
                        . "auto-generated.";
                } elseif (!preg_match($supported, $type)) {
                    $errors[] = "fields[$i].type '$type': not a supported column type.";
                }
                $field_cols[$name] = $col;
            }
        }
        $all_cols = array_merge(array_values($field_cols), $reserved);

        // --- filters reference real columns ---
        if (!empty($m['filters']) && is_array($m['filters'])) {
            foreach ($m['filters'] as $i => $flt) {
                $column = $flt['column'] ?? '';
                if (!in_array($column, $all_cols, true)) {
                    $errors[] = "filters[$i].column '$column': not a column defined in fields:.";
                }
            }
        }

        // --- api block coherence ---
        $api = $m['api'] ?? [];
        if (!empty($api['public_read']) && empty($api['readable'])) {
            $errors[] = "api.public_read: requires api.readable to be true.";
        }

        // --- ai writable fields must clear the write floor ---
        $ai = $m['ai'] ?? [];
        $unwritable = $api['unwritable_fields'] ?? [];
        foreach (($ai['writable_fields'] ?? []) as $w) {
            if (preg_match(self::CREDENTIAL_REGEX, $w)) {
                $errors[] = "ai.writable_fields: '$w' is a credential column (blocked by the write floor).";
            }
            if (in_array($w, $unwritable, true)) {
                $errors[] = "ai.writable_fields: '$w' is also listed in api.unwritable_fields.";
            }
        }

        // --- read-only DB collision checks (best-effort) ---
        // Under --force these are advisories, not blockers: the developer has
        // accepted overwriting, and the table may legitimately already exist.
        if (preg_match('/^[a-z]{3}$/', $prefix) && preg_match('/^[a-z][a-z0-9_]*$/', $plural)) {
            $existence = $this->validateAgainstDatabase($prefix, $prefix . '_' . $plural);
            if ($force) {
                $this->warnings = array_merge($this->warnings, $existence);
            } else {
                $errors = array_merge($errors, $existence);
            }
        }

        return $errors;
    }

    /** Non-fatal advisories produced by the most recent validate() call. */
    public function warnings(): array {
        return $this->warnings;
    }

    /** Best-effort read-only collision checks against the live schema. */
    protected function validateAgainstDatabase(string $prefix, string $table): array {
        $errors = [];
        try {
            $dblink = DbConnector::get_instance()->get_db_link();
        } catch (Throwable $e) {
            return [];   // no DB available (pure preview) — skip
        }

        $q = $dblink->prepare(
            "SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = ? LIMIT 1");
        $q->execute([$table]);
        if ($q->fetchColumn()) {
            $errors[] = "plural: table '$table' already exists.";
        }

        $q = $dblink->prepare(
            "SELECT table_name FROM information_schema.tables
             WHERE table_schema = 'public' AND table_name LIKE ? LIMIT 1");
        $q->execute([$prefix . '\_%']);
        $existing = $q->fetchColumn();
        if ($existing) {
            $errors[] = "prefix: '$prefix' already used by an existing table ('$existing').";
        }

        return $errors;
    }

    // ====================================================================
    // Public surface: files() / write()
    // ====================================================================

    /**
     * Compute the full output without touching disk.
     *
     * @return array<string,string> relative_path => rendered file contents
     */
    public function files(): array {
        $ctx = $this->buildContext();
        $out = [];

        // Whole-file optionality (surfaces:) is handled here, one level up from
        // the templates: the engine chooses which templates to run.
        $surface_templates = [
            'data'        => [['data_class.tpl.php', $ctx['paths']['data']]],
            'public_list' => [
                ['public_list_logic.tpl.php', $ctx['paths']['public_list_logic']],
                ['public_list_view.tpl.php',  $ctx['paths']['public_list_view']],
            ],
            'public_edit' => [
                ['public_edit_logic.tpl.php', $ctx['paths']['public_edit_logic']],
                ['public_edit_view.tpl.php',  $ctx['paths']['public_edit_view']],
            ],
            'admin_list'  => [
                ['admin_list_logic.tpl.php', $ctx['paths']['admin_list_logic']],
                ['admin_list_view.tpl.php',  $ctx['paths']['admin_list_view']],
            ],
            'admin_edit'  => [
                ['admin_edit_logic.tpl.php', $ctx['paths']['admin_edit_logic']],
                ['admin_edit_view.tpl.php',  $ctx['paths']['admin_edit_view']],
            ],
        ];

        foreach (self::ALL_SURFACES as $surface) {
            if (!in_array($surface, $ctx['surfaces'], true)) {
                continue;
            }
            foreach ($surface_templates[$surface] as $pair) {
                list($template, $path) = $pair;
                $out[$path] = $this->render($template, $ctx);
            }
        }

        return $out;
    }

    /**
     * Write files() to disk.
     *
     * @param bool $force overwrite existing files (default: refuse on collision)
     * @return array{written: string[], skipped: string[]}
     */
    public function write(bool $force = false): array {
        $files = $this->files();
        $root = $this->repoRoot();

        // Collision check first — refuse the whole write unless --force.
        if (!$force) {
            $collisions = [];
            foreach (array_keys($files) as $rel) {
                if (file_exists($root . $rel)) {
                    $collisions[] = $rel;
                }
            }
            if ($collisions) {
                throw new ScaffoldGeneratorException(
                    "Refusing to overwrite existing files (use --force):\n  "
                    . implode("\n  ", $collisions));
            }
        }

        $written = [];
        foreach ($files as $rel => $contents) {
            $abs = $root . $rel;
            $dir = dirname($abs);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
                @chmod($dir, 0777);
            }
            file_put_contents($abs, $contents);
            @chmod($abs, 0666);
            $written[] = $rel;
        }

        return ['written' => $written, 'skipped' => []];
    }

    /** The resolved list of relative paths that files() would emit. */
    public function plannedPaths(): array {
        return array_keys($this->files());
    }

    /** Derived names for the CLI confirmation banner. */
    public function derivedNames(): array {
        $ctx = $this->buildContext();
        return [
            'entity'     => $ctx['entity'],
            'multi'      => $ctx['multi'],
            'table'      => $ctx['table'],
            'pkey'       => $ctx['pkey'],
            'surfaces'   => implode(', ', $ctx['surfaces']),
            'public_url' => '/' . $ctx['plural'],
            'admin_url'  => '/admin/admin_' . $ctx['plural'],
        ];
    }

    // ====================================================================
    // Database-roundtrip acceptance check
    // ====================================================================

    /**
     * Prove the generated data class round-trips through the real database:
     * stand its table up, insert one synthesized row, retrieve the primary key
     * the way SystemBase::save() does, read the row back, then ROLLBACK — leaving
     * nothing behind. This turns "the file parses" into "the entity works": it is
     * what catches the class of bug that passes php -l and the pattern validator
     * and then throws a fatal on the first save() (a column type update_database
     * can't create; a serial PK whose canonical sequence save() can't find).
     *
     * Faithful, not parallel: the table is built by the production code path
     * (DatabaseUpdater::createTableIfMissing — same DDL, sequence and primary-key
     * logic the platform uses), exercised against the class's *actual* emitted
     * $field_specifications (the rendered class is loaded, not reconstructed).
     *
     * Runs only for the `data` surface (the only one that owns a table) and only
     * when a live database is reachable; in a pure-preview context (no DB) it is
     * skipped. Kept out of files() — which must stay pure for the preview/AI
     * consumers — and called from the write path / CLI alongside php -l.
     *
     * @return array{ran: bool, failures: string[], skipped_reason: ?string}
     */
    public function verifyDatabaseRoundtrip(): array {
        $ctx = $this->buildContext();
        if (!in_array('data', $ctx['surfaces'], true)) {
            return ['ran' => false, 'failures' => [], 'skipped_reason' => 'no data surface'];
        }
        try {
            $dblink = DbConnector::get_instance()->get_db_link();
        } catch (Throwable $e) {
            return ['ran' => false, 'failures' => [], 'skipped_reason' => 'no database connection'];
        }

        $entity = $ctx['entity'];
        $table  = $ctx['table'];
        $pkey   = $ctx['pkey'];

        // If the table already exists it has demonstrably round-tripped (it is in
        // production). The check builds a fresh table in a transaction, which would
        // collide with the live one — and re-proving it by drop/recreate would be
        // destructive theatre. Skip it; this is the --force regeneration path.
        $exists = $dblink->prepare(
            "SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = ? LIMIT 1");
        $exists->execute([$table]);
        if ($exists->fetchColumn()) {
            return ['ran' => false, 'failures' => [], 'skipped_reason' => "table {$table} already exists"];
        }

        // Load the generated class so we test its real emitted field specs.
        $load_err = $this->loadGeneratedDataClass($ctx);
        if ($load_err !== null) {
            return ['ran' => true, 'failures' => [$load_err], 'skipped_reason' => null];
        }

        require_once(PathHelper::getIncludePath('includes/DatabaseUpdater.php'));

        $failures = [];
        $in_txn = false;
        try {
            $dblink->beginTransaction();
            $in_txn = true;

            // Build the table via the exact production path (DDL + sequence + PK).
            // createTableIfMissing is private — invoke it directly via reflection
            // rather than reimplementing its mapping (the whole point is fidelity).
            $updater = new DatabaseUpdater(false);
            $create  = new ReflectionMethod('DatabaseUpdater', 'createTableIfMissing');
            $res = $create->invoke($updater, $entity, [], $dblink);
            if (!empty($res['errors'])) {
                foreach ($res['errors'] as $e) {
                    $failures[] = "table {$table}: {$e}";
                }
                return ['ran' => true, 'failures' => $failures, 'skipped_reason' => null];
            }

            // Insert one synthesized row — skip the serial PK so the column's
            // sequence default fills it (the step that exercises bug 3).
            list($cols, $place, $binds) = $this->synthesizeRow($entity);
            $sql  = 'INSERT INTO "' . $table . '" (' . implode(', ', $cols) . ') VALUES ('
                  . implode(', ', $place) . ')';
            $stmt = $dblink->prepare($sql);
            foreach ($binds as $i => $b) {
                $stmt->bindValue($i + 1, $b[0], $b[1]);
            }
            $stmt->execute();

            // Retrieve the PK the way SystemBase::save() does — via the canonical
            // sequence name. A divergent sequence (bug 3) surfaces right here.
            $new_id = $dblink->lastInsertId($table . '_' . $pkey . '_seq');
            if ($new_id === false || $new_id === null || $new_id === '' || (string)$new_id === '0') {
                $failures[] = "primary key: lastInsertId('{$table}_{$pkey}_seq') returned no id — "
                    . "the canonical sequence is not the column's default (scaffold bug 3 shape)";
            } else {
                $check = $dblink->prepare(
                    'SELECT "' . $pkey . '" FROM "' . $table . '" WHERE "' . $pkey . '" = ?');
                $check->execute([$new_id]);
                if ($check->fetchColumn() === false) {
                    $failures[] = "read-back: inserted row (id {$new_id}) not found in {$table}";
                }
            }
        } catch (Throwable $e) {
            $failures[] = "{$table}: " . $e->getMessage();
        } finally {
            if ($in_txn) {
                try { $dblink->rollBack(); } catch (Throwable $e) { /* nothing to undo */ }
            }
        }

        return ['ran' => true, 'failures' => $failures, 'skipped_reason' => null];
    }

    /**
     * Load the rendered data class into the running process so the roundtrip
     * check can read its real static $field_specifications. The generated file's
     * own requires pull in SystemBase (which also defines SystemMultiBase), so no
     * extra bootstrap is needed. Returns null on success, or an error string.
     */
    protected function loadGeneratedDataClass(array $ctx): ?string {
        $entity = $ctx['entity'];
        if (class_exists($entity, false)) {
            return null;   // already loaded (e.g. a repeated fixture run)
        }
        $files = $this->files();
        $data_rel = $ctx['paths']['data'];
        if (!isset($files[$data_rel])) {
            return "data class source not produced for {$entity}";
        }

        $tmp = sys_get_temp_dir() . '/scaffold_rt_' . getmypid() . '_' . $ctx['entity_snake'] . '.php';
        file_put_contents($tmp, $files[$data_rel]);
        try {
            require $tmp;
        } catch (Throwable $e) {
            @unlink($tmp);
            return "could not load generated data class {$entity}: " . $e->getMessage();
        }
        @unlink($tmp);
        if (!class_exists($entity, false)) {
            return "generated source did not define class {$entity}";
        }
        return null;
    }

    /**
     * Build INSERT column list, placeholders and typed binds for one synthetic
     * row covering every non-serial column. The serial PK is omitted so its
     * sequence default fires. FK columns get a plain value — the transient table
     * carries no foreign-key constraints, so referential integrity is not tested.
     *
     * @return array{0: string[], 1: string[], 2: array<array{0: mixed, 1: int}>}
     */
    protected function synthesizeRow(string $entity): array {
        $cols = []; $place = []; $binds = [];
        foreach ($entity::$field_specifications as $col => $spec) {
            if (!empty($spec['serial'])) {
                continue;   // serial PK: let the sequence default supply it
            }
            $cols[]  = '"' . $col . '"';
            $place[] = '?';
            $binds[] = $this->synthValue($spec['type'] ?? 'varchar(255)');
        }
        return [$cols, $place, $binds];
    }

    /** A type-appropriate [value, PDO::PARAM_*] pair for a column declaration. */
    protected function synthValue(string $type): array {
        $t = strtolower(trim($type));
        if (preg_match('/^(int2|int4|int8|integer|bigint|smallint)$/', $t)) {
            return [1, PDO::PARAM_INT];
        }
        if (preg_match('/^(numeric|decimal)/', $t)) {
            return ['1', PDO::PARAM_STR];
        }
        if ($t === 'bool' || $t === 'boolean') {
            return [false, PDO::PARAM_BOOL];
        }
        if ($t === 'date') {
            return ['2020-01-01', PDO::PARAM_STR];
        }
        if (strpos($t, 'timestamp') === 0) {
            return ['2020-01-01 00:00:00', PDO::PARAM_STR];
        }
        if (strpos($t, 'time') === 0) {
            return ['00:00:00', PDO::PARAM_STR];
        }
        if ($t === 'json' || $t === 'jsonb') {
            return ['{}', PDO::PARAM_STR];
        }
        return ['x', PDO::PARAM_STR];   // varchar / character / char / text
    }

    // ====================================================================
    // Derivation
    // ====================================================================

    protected function buildContext(): array {
        if ($this->ctx !== null) {
            return $this->ctx;
        }

        $m = $this->manifest;
        $entity = $m['entity'];
        $entity_snake = $this->snake($entity);
        $prefix = $m['prefix'];
        $plural = $m['plural'];

        $into = $m['into'] ?? 'core';
        $base = ($into === 'core') ? '' : rtrim($into, '/') . '/';

        $soft_delete = (($m['delete']['strategy'] ?? 'soft') === 'soft');

        $surfaces = $this->resolveSurfaces($m['surfaces'] ?? ['public', 'admin']);
        $surface_on = [];
        foreach (self::ALL_SURFACES as $s) {
            $surface_on[$s] = in_array($s, $surfaces, true);
        }

        $fields = $this->normalizeFields($m['fields'] ?? [], $prefix);

        // Form-editable fields = those with a renderable form type.
        // List-displayable fields = short, scalar-ish types (no textarea/password).
        $editable = [];
        $descriptor_inputs = [];
        $display_fields = [];
        foreach ($fields as $f) {
            if (in_array($f['form_type'], ['string', 'int', 'select', 'email', 'date'], true)
                && count($display_fields) < 6) {
                $display_fields[] = $f;
            }
            if ($f['form_type'] === null) {
                continue;
            }
            $editable[] = $f['col'];
            $entry = [
                'col'      => $f['col'],
                'type'     => $f['form_type'],
                'required' => $f['required'],
                'label'    => $f['label'],
            ];
            if ($f['form_type'] === 'select') {
                $entry['options'] = $f['options'];
            }
            if ($f['placeholder'] !== null) { $entry['placeholder'] = $f['placeholder']; }
            if ($f['help'] !== null)        { $entry['help'] = $f['help']; }
            $descriptor_inputs[] = $entry;
        }

        $this->ctx = [
            'manifest'      => $m,
            'entity'        => $entity,
            'entity_snake'  => $entity_snake,
            'multi'         => 'Multi' . $entity,
            'exception'     => $entity . 'Exception',
            'prefix'        => $prefix,
            'plural'        => $plural,
            'table'         => $prefix . '_' . $plural,
            'pkey'          => $prefix . '_' . $entity_snake . '_id',
            'delete_col'    => $prefix . '_delete_time',
            'soft_delete'   => $soft_delete,
            'into'          => $into,
            'base'          => $base,
            'fields'        => $fields,
            'editable'      => $editable,
            'display_fields' => $display_fields,
            'descriptor_inputs' => $descriptor_inputs,
            'filters'       => $this->normalizeFilters($m['filters'] ?? []),
            'api'           => $m['api'] ?? null,
            'ai'            => $m['ai'] ?? null,
            'owner_field'   => $m['owner_field'] ?? null,
            'admin_permission'  => $m['admin_permission'] ?? 5,
            'public_permission' => array_key_exists('public_permission', $m) ? $m['public_permission'] : 0,
            'delete'        => $m['delete'] ?? [],
            'surfaces'      => $surfaces,
            'surface_on'    => $surface_on,
            'title'         => $this->title($entity),
            'title_plural'  => $this->title(str_replace('_', ' ', $plural)),
        ];

        // Paths (relative to repo root, plugin-rooted when into: targets a plugin).
        $this->ctx['paths'] = [
            'data'              => $base . "data/{$entity_snake}_class.php",
            'public_list_logic' => $base . "logic/{$plural}_logic.php",
            'public_list_view'  => $base . "views/{$plural}.php",
            'public_edit_logic' => $base . "logic/{$entity_snake}_edit_logic.php",
            'public_edit_view'  => $base . "views/{$entity_snake}_edit.php",
            'admin_list_logic'  => $base . "adm/logic/admin_{$plural}_logic.php",
            'admin_list_view'   => $base . "adm/admin_{$plural}.php",
            'admin_edit_logic'  => $base . "adm/logic/admin_{$entity_snake}_edit_logic.php",
            'admin_edit_view'   => $base . "adm/admin_{$entity_snake}_edit.php",
        ];

        return $this->ctx;
    }

    /** Expand aliases, drop dupes, force `data` whenever any page is present. */
    protected function resolveSurfaces($raw): array {
        if (!is_array($raw)) {
            $raw = ['public', 'admin'];
        }
        $set = [];
        foreach ($raw as $tok) {
            if (isset(self::SURFACE_ALIASES[$tok])) {
                foreach (self::SURFACE_ALIASES[$tok] as $t) {
                    $set[$t] = true;
                }
            } elseif (in_array($tok, self::ALL_SURFACES, true)) {
                $set[$tok] = true;
            }
            // invalid tokens are reported by validate(), ignored here
        }
        foreach (self::PAGE_SURFACES as $pt) {
            if (isset($set[$pt])) {
                $set['data'] = true;
                break;
            }
        }
        // Emit in canonical order.
        $ordered = [];
        foreach (self::ALL_SURFACES as $s) {
            if (isset($set[$s])) {
                $ordered[] = $s;
            }
        }
        return $ordered;
    }

    /** Normalise manifest fields: prefix names, derive form type + literals. */
    protected function normalizeFields(array $fields, string $prefix): array {
        $out = [];
        foreach ($fields as $f) {
            $name = $f['name'];
            $required = !empty($f['required']);
            $col = $prefix . '_' . $name;

            $out[] = [
                'name'            => $name,
                'col'             => $col,
                'type'            => $f['type'],
                'required'        => $required,
                'is_nullable'     => array_key_exists('nullable', $f) ? (bool)$f['nullable'] : !$required,
                'unique'          => !empty($f['unique']),
                'unique_with'     => $f['unique_with'] ?? null,
                'default'         => array_key_exists('default', $f) ? $f['default'] : null,
                'has_default'     => array_key_exists('default', $f),
                'default_literal' => array_key_exists('default', $f) ? self::phpScalar($f['default']) : null,
                'zero_on_create'  => !empty($f['zero_on_create']),
                'form_type'       => $this->formType($f['type'], $f['as'] ?? null),
                'options'         => $f['options'] ?? [],
                'label'           => $this->title(str_replace('_', ' ', $name)),
                'placeholder'     => $f['placeholder'] ?? null,
                'help'            => $f['help'] ?? null,
            ];
        }
        return $out;
    }

    /** Normalise filters: derive the PDO bind constant for parameterised ones. */
    protected function normalizeFilters(array $filters): array {
        $bind_map = [
            'int'  => 'PDO::PARAM_INT',
            'str'  => 'PDO::PARAM_STR',
            'bool' => 'PDO::PARAM_BOOL',
        ];
        $out = [];
        foreach ($filters as $flt) {
            $kind = 'param';
            if (isset($flt['condition'])) {
                $kind = 'condition';
            } elseif (isset($flt['match'])) {
                $kind = 'match';
            }
            $out[] = [
                'option'    => $flt['option'],
                'column'    => $flt['column'],
                'kind'      => $kind,
                'bind'      => $bind_map[$flt['bind'] ?? 'str'] ?? 'PDO::PARAM_STR',
                'condition' => $flt['condition'] ?? null,
                'match'     => $flt['match'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * Layer 1: DB column type → descriptor/form type. `as:` overrides.
     * Returns NULL for column types with no sane default input (omitted from form).
     */
    protected function formType(string $db_type, ?string $as): ?string {
        if ($as !== null) {
            return $as;   // author-declared semantic type wins
        }
        $t = strtolower(trim($db_type));
        if (preg_match('/^(varchar|character)\s*\(/', $t)) { return 'string'; }
        if ($t === 'text')                                 { return 'text'; }
        if (preg_match('/^(int2|int4|int8|integer|bigint|smallint)$/', $t)) { return 'int'; }
        if (preg_match('/^numeric\s*\(/', $t))             { return 'string'; }
        if ($t === 'bool' || $t === 'boolean')             { return 'bool'; }
        if ($t === 'date')                                 { return 'date'; }
        if (strpos($t, 'time') === 0 && strpos($t, 'timestamp') !== 0) { return null; }   // wall-clock time: no sane default input
        if (strpos($t, 'timestamp') === 0)                 { return null; }   // system-managed
        if ($t === 'json' || $t === 'jsonb')               { return null; }   // no default input
        return null;
    }

    /**
     * Accepted column-type set. Single source of truth: delegate to the
     * update_database engine's authority (DatabaseUpdater::acceptedColumnTypeRegex)
     * so the generator can never reject a type the database supports — the drift
     * that produced the `time` and `timestamp(6)` rejections. Serial types are
     * excluded there on purpose; the PK is the only serial column and the
     * generator injects it with the correct int8 + 'serial'=>true shape.
     */
    protected function supportedTypeRegex(): string {
        require_once(PathHelper::getIncludePath('includes/DatabaseUpdater.php'));
        return DatabaseUpdater::acceptedColumnTypeRegex();
    }

    // ====================================================================
    // Template rendering + small literal helpers (used by templates)
    // ====================================================================

    protected function render(string $template, array $ctx): string {
        $file = __DIR__ . '/templates/' . $template;
        if (!is_file($file)) {
            throw new ScaffoldGeneratorException("Template not found: $template");
        }
        // Templates read $ctx and emit the target file's source.
        ob_start();
        include $file;
        return ob_get_clean();
    }

    protected function repoRoot(): string {
        // PathHelper::getIncludePath('') yields the public_html root with trailing slash.
        return PathHelper::getIncludePath('');
    }

    /** Render a scalar as PHP source (used for field defaults). */
    public static function phpScalar($v): string {
        if (is_bool($v))   { return $v ? 'true' : 'false'; }
        if (is_int($v) || is_float($v)) { return (string)$v; }
        if ($v === null)   { return 'NULL'; }
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], (string)$v) . "'";
    }

    /** Render a flat list as an array('a', 'b') literal. */
    public static function phpList(array $values): string {
        $parts = array_map([self::class, 'phpScalar'], array_values($values));
        return 'array(' . implode(', ', $parts) . ')';
    }

    /** Render an associative map as an array('k' => v, ...) literal. */
    public static function phpMap(array $map): string {
        if (empty($map)) {
            return 'array()';
        }
        $parts = [];
        foreach ($map as $k => $v) {
            $parts[] = self::phpScalar($k) . ' => ' . self::phpScalar($v);
        }
        return 'array(' . implode(', ', $parts) . ')';
    }

    /**
     * Render an arbitrary (possibly nested) value as PHP array() source. Used
     * for declared, verbatim blocks like $foreign_key_actions. Empty arrays
     * render inline as array().
     *
     * @param int $depth tab depth of the array's closing brace
     */
    public static function phpValue($v, int $depth = 1): string {
        if (!is_array($v)) {
            return self::phpScalar($v);
        }
        if (empty($v)) {
            return 'array()';
        }
        $is_list = array_keys($v) === range(0, count($v) - 1);
        $pad  = str_repeat("\t", $depth + 1);
        $padc = str_repeat("\t", $depth);
        $parts = [];
        foreach ($v as $k => $val) {
            $rendered = self::phpValue($val, $depth + 1);
            $parts[] = $is_list ? ($pad . $rendered)
                                : ($pad . self::phpScalar($k) . ' => ' . $rendered);
        }
        return "array(\n" . implode(",\n", $parts) . "\n" . $padc . ")";
    }

    /** PCRE-free literal PHP open/close tags for templates emitting PHP source. */
    public static function open() { return '<' . '?php'; }
    public static function close() { return '?' . '>'; }

    /** snake_case from PascalCase: EventSession -> event_session. */
    protected function snake(string $pascal): string {
        $s = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $pascal);
        $s = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $s);
        return strtolower($s);
    }

    /** Title Case from a space/underscore phrase. */
    protected function title(string $phrase): string {
        return ucwords(str_replace('_', ' ', $phrase));
    }
}
