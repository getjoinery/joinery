<?php
/**
 * Example Data Model Class Template
 *
 * Demonstrates the complete, current structure for a data model class: table
 * config, schema (field_specifications), REST API + AI exposure and field-level
 * authorization, deletion strategy, row-scope hooks, and the Multi collection.
 * Copy this template and adapt it for your table/entity.
 *
 * USAGE:
 * 1. Copy this file to /data/[tablename]_class.php
 * 2. Replace "Example" with your actual class name (PascalCase)
 * 3. Replace "MultiExample" with Multi + your class name
 * 4. Update all static properties for your table
 * 5. Add any custom methods specific to your entity
 * 6. Run php -l, then maintenance_scripts/dev_tools/validate_php_file.php
 *
 * RELATED DOCS:
 * - docs/api.md ............................ REST API exposure, row scope, field floors
 * - docs/deletion_system.md ................ $foreign_key_actions / $permanent_delete_actions
 * - docs/validation.md ..................... field validation
 * - docs/logic_architecture.md ............. the logic layer that wraps these models
 * - plugins/joinery_ai/docs/overview.md .... AI model read/write surface
 */

require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

/**
 * Single Example Record Class
 * 
 * Handles individual record operations for the example table
 */
class Example extends SystemBase 
{
    // REQUIRED: Table configuration
    public static $prefix = 'exm';                   // 3-character prefix for field names (always 3 chars)
    public static $tablename = 'exm_examples';       // Actual database table name
    public static $pkey_column = 'exm_id';          // Primary key column name

    // ====================================================================
    // REST API exposure & authorization (opt-in, default-closed)
    // Full reference: docs/api.md
    // ====================================================================
    //
    // Three independent layers, each safe by default:
    //   1. Resource exposure — is this class a REST resource at all?
    //   2. Row scope         — which rows may a caller touch?
    //   3. Field floors      — which columns may be read / written?

    // Layer 1 — exposure. Both default false on SystemBase: a model is NOT a
    // CRUD resource until it opts in, and an unexposed class 404s. Read and
    // write are separate, so a model can be read-only ($api_writable = false).
    // Omit both for credential, config, audit/log, and join tables.
    public static $api_readable = true;
    public static $api_writable = true;

    // Layer 2 — public read. Set true ONLY for world-readable catalog content
    // (events, posts, pages): it skips the per-record scope AND the collection
    // owner-filter. Leave false (default) for user-owned data — SystemBase's
    // authenticate_read/write then default to owner-or-staff (deny). You only
    // ever override the row-scope hooks to OPEN access, never to close it
    // (see the authenticate_read note further down).
    // public static $api_public_read = true;

    // Layer 3 — field floors (single definitions SHARED with the AI surface).
    // READ floor: columns never exported over any API. The credential regex
    //   /_(password|secret|key|token|hash)$/i auto-covers *_password, *_secret,
    //   *_key, *_token, *_hash the moment they are added, so list here ONLY
    //   genuine secrets whose names miss the pattern.
    public static $api_unreadable_fields = array();   // e.g. ['exm_authhash']
    // WRITE floor: privileged, non-credential columns that must never be set
    //   over the API (silently dropped from CRUD/AI writes). Credentials are
    //   auto-covered by the same regex, so list only the rest (e.g. a
    //   permission/role column).
    public static $api_unwritable_fields = array();   // e.g. ['exm_permission']
    // DERIVED allowlist: export_for_api() is FAIL-CLOSED — it emits declared
    //   columns (minus the read floor) PLUS only the keys named here. Any
    //   computed key an export_as_array() override injects is dropped unless
    //   listed. Default empty = no derived keys leave over the API. (See the
    //   export_as_array note further down.)
    public static $api_derived_fields = array();      // e.g. ['display_name']

    // ====================================================================
    // AI model surface (joinery_ai) — opt-in, default-deny
    // Full reference: plugins/joinery_ai/docs/overview.md
    // ====================================================================
    //
    // Read: $ai_readable makes the model eligible for the query_model tool —
    // once a recipe also opts the class in via its Allowed Models checkboxes.
    // Omit these entirely for system/infra/secret tables you don't want
    // recipes reading from.
    public static $ai_readable        = true;
    public static $ai_description     = 'Example records used to demonstrate the data-model template.';
    // Member read-scope: how a NON-admin member's reads are contained to their own
    // rows (admins always read cross-user). Omit and the resolver infers the owner
    // column — the lone *_usr_user_id / *_owner_user_id column. States:
    //   omitted        infer the single owner column; zero or 2+ candidates ⇒ the
    //                  model is hidden from members (ambiguous ownership is never guessed).
    //   'exm_usr_user_id'  name the owner column (e.g. a primary key the convention
    //                      can't infer, or to disambiguate two candidates).
    //   ['col_a','col_b']  OR-match — a member sees a row if they own via any column
    //                      (e.g. messages = sender-or-recipient).
    //   false          ownerless catalog/config — members read every row.
    // Run `php plugins/joinery_ai/cli/owner_scope_report.php` to see how every model
    // resolves and catch an accidentally-exposed or accidentally-hidden table.
    public static $ai_owner_field     = false;              // e.g. 'exm_usr_user_id', or ['col_a','col_b']
    // Relevance/noise trims ONLY. Genuine secrets belong in $api_unreadable_fields
    // (the shared read floor) — ModelSchemaBuilder merges that in automatically,
    // so never re-list secrets here. Use this for noisy-but-not-secret columns
    // (bulky internal blobs, low-signal IDs) you want kept out of the LLM's view.
    public static $ai_excluded_fields = [];

    // Untrusted-input markers: fields whose values originate outside the trust
    // boundary — user bios, message bodies, inbound email, public form answers.
    // The query executor wraps those values with a per-run nonce so the LLM
    // treats them as data, not instructions. Probabilistic defense against
    // indirect prompt injection; cheap to declare, free for fields you omit.
    public static $ai_untrusted_fields = [];                // e.g. ['exm_user_note', 'exm_public_caption']

    // Write: opt the model into the AI write tools (create_model / update_model
    // / delete_model) by allow-listing writable columns. The core write floor
    // ($api_unwritable_fields + the credential regex) is stripped from this set
    // automatically. Only opt in when the model's prepare()/save() rules are the
    // ENTIRE validation gauntlet (no cross-record invariants, no payment
    // effects, no hook firings). See specs/implemented/joinery_ai_write_tools.md.
    // public static $ai_writable_fields = ['exm_name', 'exm_description'];

    // REQUIRED: Complete field specifications - defines database schema AND runtime behavior
    //
    // SUPPORTED DATA TYPES (from LibraryFunctions::translate_data_types):
    // Text types:    'varchar(length)', 'text', 'character(length)'
    // Integer types: 'integer'/'int4', 'int8'/'bigint', 'int2'/'smallint' 
    // Decimal types: 'numeric(precision,scale)'
    // Boolean type:  'bool'/'boolean'
    // Date types:    'date', 'timestamp', 'timestamp with time zone'
    // JSON types:    'json', 'jsonb' (jsonb recommended for performance)
    // Auto-increment: declare an 'int8' column and add 'serial'=>true (used for
    //                 primary keys). Never declare a 'serial'/'bigserial' type —
    //                 update_database manages the sequence via the 'serial' flag.
    //
    // Runtime behavior properties (do NOT affect database schema):
    // 'required' => true           - Field must be non-null and non-empty string
    // 'default' => mixed           - Default value for new records only.
    //                                A plain PHP value: 'pending', false, 0,
    //                                'now()'. Never SQL-quote it ("'pending'")
    //                                — SystemBase::save() applies it to the
    //                                row verbatim, so the quotes would be
    //                                stored in the column. Bare SQL functions
    //                                ('now()') are fine: Postgres accepts the
    //                                string as time input, and the schema
    //                                backfill renders them unquoted.
    // 'zero_on_create' => true     - Set to 0 when creating if NULL
    // 'unique' => true             - Single field unique constraint
    // 'unique_with' => array(...)  - Multi-field unique constraint
    // 'index' => true              - Plain btree index on this column
    // 'index_with' => array(...)   - Composite btree index (this column first,
    //                                then the listed columns, in order)
    // 'foreign_key' => array(...)  - Real DB-level FOREIGN KEY constraint,
    //                                materialized by update_database / plugin
    //                                sync. Declare for hard ownership edges
    //                                (child meaningless without parent); see
    //                                docs/deletion_system.md for when to use
    //                                this vs $foreign_key_actions.
    //
    // AUTO-DETECTION:
    // - Timestamp fields detected from type: 'timestamp', 'date'
    // - JSON fields detected from type: 'json', 'jsonb'
    //
    public static $field_specifications = array(
        // Primary key specification — int8 + 'serial'=>true is the platform
        // convention (update_database manages the canonical {table}_{pkey}_seq
        // sequence from the 'serial' flag; a 'bigserial' type does not set it).
        'exm_id' => array(
            'type' => 'int8',
            'is_nullable' => false,
            'serial' => true,
            'is_primary_key' => true
        ),
        
        // Text fields - varchar, text, character
        'exm_name' => array(
            'type' => 'varchar(255)',      // Supported: varchar(length)
            'is_nullable' => false,
            'required' => true,
            'unique' => true               // Single field unique constraint
        ),
        'exm_description' => array(
            'type' => 'text',              // Supported: text (unlimited length)
            'is_nullable' => true
        ),
        'exm_link' => array(
            'type' => 'varchar(255)',      // SEO-friendly URL slug
            'is_nullable' => true
            // Used by get_url() with $url_namespace to create /example/slug URLs
        ),
        'exm_code' => array(
            'type' => 'character(5)',      // Supported: character(length) - fixed length
            'is_nullable' => true,
            'unique_with' => array('exm_category_id')  // Multi-field unique constraint
        ),
        
        // Numeric fields - integer/int4, bigint/int8, smallint/int2, numeric
        'exm_status' => array(
            'type' => 'integer',           // Supported: integer (same as int4)
            'is_nullable' => false,
            'default' => 1
        ),
        'exm_price' => array(
            'type' => 'numeric(10,2)',     // Supported: numeric(precision,scale) for money
            'is_nullable' => true
        ),
        'exm_counter' => array(
            'type' => 'int8',              // Supported: int8 (same as bigint)
            'is_nullable' => true,
            'zero_on_create' => true
        ),
        'exm_small_number' => array(
            'type' => 'int2',              // Supported: int2 (same as smallint)
            'is_nullable' => true
        ),
        
        // Boolean field
        'exm_is_active' => array(
            'type' => 'bool',              // Supported: bool (same as boolean)
            'is_nullable' => false,
            'default' => true
        ),
        
        // Foreign key field
        'exm_category_id' => array(
            'type' => 'int8',              // Supported: int8 for foreign keys
            'is_nullable' => true,
            'index' => true,               // Index the FK column — Postgres does
                                           // NOT auto-index the referencing side
            'foreign_key' => array(       // Materialized as a real constraint;
                'table' => 'categories',  // mismatched ON DELETE is corrected,
                'column' => 'cat_id',     // orphan rows block creation loudly.
                'on_delete' => 'SET NULL' // CASCADE | SET NULL | RESTRICT | NO ACTION
            )
        ),
        
        // JSON fields - json, jsonb
        'exm_metadata' => array(
            'type' => 'jsonb',             // Supported: jsonb (better performance)
            'is_nullable' => true
        ),
        'exm_settings' => array(
            'type' => 'json',              // Supported: json (standard JSON)
            'is_nullable' => true
        ),
        
        // Date/Time fields - date, timestamp, timestamp with time zone
        'exm_event_date' => array(
            'type' => 'date',              // Supported: date (no time)
            'is_nullable' => true
            // Automatically detected as timestamp field for smart_get()
        ),
        'exm_created' => array(
            'type' => 'timestamp',         // Supported: timestamp (without time zone)
            'is_nullable' => false,
            'default' => 'now()'
            // Automatically detected as timestamp field
        ),
        'exm_updated' => array(
            'type' => 'timestamp with time zone', // Supported: timestamp with time zone
            'is_nullable' => false,
            'default' => 'now()'
            // Automatically detected as timestamp field
        ),
        'exm_delete_time' => array(
            'type' => 'timestamp with time zone',
            'is_nullable' => true
        ),
        'exm_created_by' => array(
            'type' => 'bigint',
            'is_nullable' => true,
            'foreign_key' => array(
                'table' => 'usr_users',
                'column' => 'usr_id',
                'on_delete' => 'SET NULL'
            )
        ),
        'exm_updated_by' => array(
            'type' => 'bigint',
            'is_nullable' => true,
            'foreign_key' => array(
                'table' => 'usr_users', 
                'column' => 'usr_id',
                'on_delete' => 'SET NULL'
            )
        )
    );
    
    // ====================================================================
    // Advanced indexes — full reference: docs/deploy_and_upgrade.md
    // ====================================================================
    //
    // Plain btree indexes are declared inline with 'index' / 'index_with' above.
    // Anything beyond a plain btree — a method override, a partial predicate, an
    // expression, or uniqueness scoped by a predicate — goes in this one block.
    //
    // Each entry: 'columns' (required, array of bare column names OR SQL
    // expressions), optional 'method' (default 'btree'), optional 'where'
    // (partial predicate, stored verbatim), optional 'unique' (boolean).
    //
    // DIVISION OF LABOUR: whole-table uniqueness stays with 'unique' /
    // 'unique_with' above (real UNIQUE constraints — FK-referenceable). Scoped or
    // expression uniqueness uses a 'unique' => true entry here. The two never
    // describe the same index.
    public static $index_specifications = array(
        // Partial index: only the rows an "active records" query actually scans.
        array('columns' => array('exm_category_id'), 'where' => 'exm_delete_time IS NULL'),

        // Method override for a jsonb column.
        array('columns' => array('exm_metadata'), 'method' => 'gin'),

        // Expression index — e.g. case-insensitive lookups.
        array('columns' => array('LOWER(exm_name)')),

        // Partial UNIQUE index — uniqueness scoped to active rows.
        // A plain unique constraint cannot express "unique among non-deleted rows."
        array('columns' => array('exm_name'), 'unique' => true, 'where' => 'exm_delete_time IS NULL'),
    );

    // VALIDATION: Validation rules are handled through field_specifications properties:
    // - 'required' => true     - Field must have a value
    // - 'unique' => true       - Field must be unique across table
    // - 'unique_with' => []    - Multi-field unique constraint
    // These are checked during save() before database operations
    // For client-side form validation, add a 'validation' => array(...) entry to the
    // field spec (or declare it inline on the FormWriter input); end_form() emits the JS.


    // ====================================================================
    // Deletion strategy — full reference: docs/deletion_system.md
    // ====================================================================
    //
    // $foreign_key_actions: what happens to THIS model's rows when a row they
    // reference (a parent) is deleted. Omit a foreign key entirely to let it
    // cascade-delete with the parent. Actions:
    //   ['action' => 'null']                                  - set the FK to NULL
    //   ['action' => 'set_value', 'value' => User::USER_DELETED] - reassign
    //   ['action' => 'prevent', 'message' => '...']           - block parent deletion
    protected static $foreign_key_actions = [
        // 'exm_category_id' => ['action' => 'null'],
        // 'exm_created_by'  => ['action' => 'set_value', 'value' => User::USER_DELETED],
    ];

    // $permanent_delete_actions: cleanup when permanent_delete() runs on a row
    // of THIS model (delete owned files, cascade owned child rows). MUST be
    // defined (even if empty) for every model class.
    public static $permanent_delete_actions = array(
        // 'delete_files' => array('exm_image_path'),
        // 'cascade_delete' => array(
        //     'table' => 'exm_related',
        //     'foreign_key' => 'exm_example_id'
        // )
    );
    
    // JSON fields are auto-detected from field_specifications types ('json' /
    // 'jsonb') — no separate declaration needed. get_json() / smart_get()
    // decode them automatically.

    // OPTIONAL: URL namespace for generating SEO-friendly URLs
    // Used by get_url() method to create URLs like: /example/my-record-link
    // Requires a corresponding {prefix}_link field (e.g., 'exm_link') in field_specifications
    // Examples: 'product', 'event', 'page', 'post' - maps to /product/item-slug URLs
    public static $url_namespace = 'example';
    
    /**
     * Constructor
     * 
     * @param int|null $key Primary key value, or NULL for new record
     * @param bool $and_load Whether to load data immediately
     */
    function __construct($key, $and_load = FALSE) 
    {
        parent::__construct($key, $and_load);
    }
    
    /**
     * CUSTOM METHOD EXAMPLE: Validate before save
     * 
     * Override prepare() to add custom validation logic
     */
    function prepare() 
    {
        // Call parent validation first
        $result = parent::prepare();
        
        // Add custom validation (required fields are handled automatically by parent)
        $name = $this->get('exm_name');
        if (!empty($name) && strlen($name) < 3) {
            $result['messages'][] = 'Name must be at least 3 characters';
            $result['success'] = false;
        }
        
        // Validate price if provided
        $price = $this->get('exm_price');
        if (!is_null($price) && $price < 0) {
            $result['messages'][] = 'Price cannot be negative';
            $result['success'] = false;
        }
        
        return $result;
    }

    // ====================================================================
    // Row-scope authorization (REST API Layer 2) — override ONLY when needed
    // ====================================================================
    //
    // The SystemBase default is owner-or-staff (deny): a caller may touch a row
    // only if they own it (the {prefix}_usr_user_id column == current_user_id)
    // or they are staff (permission >= 5). A model with no owner column defaults
    // to staff-only. The contract is THROW-to-deny (return nothing to allow).
    //
    // Do NOT override for the common cases:
    //   - public content      -> set $api_public_read = true (above), not here
    //   - standard ownership   -> the default already handles it
    // Override ONLY for non-standard ownership (e.g. sender/recipient columns,
    // or a model owned by its own id like User):
    //
    // function authenticate_read($data) {
    //     if ($this->get('exm_owner_id') != $data['current_user_id']
    //         && (int)$data['current_user_permission'] < 5) {
    //         throw new SystemAuthenticationError(
    //             'Current user does not have permission to view this entry in ' . static::$tablename);
    //     }
    // }
    // authenticate_write($data) is identical in shape (wording: 'edit').

    // ====================================================================
    // Custom API export (REST API Layer 3) — derived keys & child embeds
    // ====================================================================
    //
    // export_as_array() returns the FULL row and is for internal/admin/webhook
    // callers. export_for_api() — what the REST boundary and embeds use — is
    // fail-closed: declared columns minus the read floor, plus ONLY the keys in
    // $api_derived_fields. So if you override export_as_array() to add a computed
    // key or embed a child model, you must:
    //   (a) list any computed key in $api_derived_fields to expose it, and
    //   (b) embed children via the child's export_for_api(), not export_as_array(),
    //       so the read floor holds through the nesting.
    //
    // function export_as_array() {
    //     $data = parent::export_as_array();
    //     $data['display_name'] = $this->get('exm_name');               // expose -> add to $api_derived_fields
    //     $category = $this->category();
    //     $data['category'] = $category ? $category->export_for_api() : NULL;  // floor-safe embed
    //     return $data;
    // }
}

/**
 * Multiple Example Records Collection Class
 * 
 * Handles collections of Example records with search, filter, and pagination
 */
class MultiExample extends SystemMultiBase 
{
    // REQUIRED: Model class reference - tells SystemMultiBase which model to use
    protected static $model_class = 'Example';
    
    /**
     * REQUIRED: Implement getMultiResults method
     * This method handles all filtering logic and returns database results
     * 
     * @param bool $only_count Return count only (for pagination)
     * @param bool $debug Enable debug output
     * @return array|int Query results or count
     */
    protected function getMultiResults($only_count = false, $debug = false) 
    {
        $filters = [];
        
        // Standard filtering patterns based on constructor options
        
        if (isset($this->options['status'])) {
            $filters['exm_status'] = [$this->options['status'], PDO::PARAM_INT];
        }
        
        if (isset($this->options['category_id'])) {
            $filters['exm_category_id'] = [$this->options['category_id'], PDO::PARAM_INT];
        }
        
        if (isset($this->options['active'])) {
            $filters['exm_is_active'] = $this->options['active'] ? "= TRUE" : "= FALSE";
        }
        
        if (isset($this->options['deleted'])) {
            $filters['exm_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
        }
        
        if (isset($this->options['link'])) {
            $filters['exm_link'] = [$this->options['link'], PDO::PARAM_STR];
        }
        
        // Use SystemMultiBase's _get_resultsv2 method for database query
        return $this->_get_resultsv2('exm_examples', $filters, $this->order_by, $only_count, $debug);
    }
}

/**
 * USAGE EXAMPLES:
 * 
 * // Create new record - defaults and zero_on_create automatically applied
 * $example = new Example(NULL);
 * $example->set('exm_name', 'Test Example');           // Required field
 * $example->set('exm_description', 'This is a test');
 * $example->set('exm_price', 19.99);
 * $result = $example->prepare();
 * if ($result['success']) {
 *     $example->save();
 *     echo "Created example with ID: " . $example->get('exm_id');
 *     echo "Status (default): " . $example->get('exm_status');      // Will be 1 (default)
 *     echo "Counter (zero): " . $example->get('exm_counter');       // Will be 0 (zero_on_create)
 *     echo "Active (default): " . $example->get('exm_is_active');   // Will be true (default)
 * }
 * 
 * // Load existing record
 * $example = new Example(123, true);
 * if ($example->is_loaded()) {
 *     echo "Name: " . $example->get('exm_name');
 *     echo "Price: " . $example->getFormattedPrice();
 *     
 *     // Timestamp fields auto-detected - smart_get returns DateTime objects
 *     $created = $example->smart_get('exm_created');  // Returns DateTime object
 *     echo "Created: " . $created->format('Y-m-d H:i:s');
 * }
 * 
 * // Update record - defaults not applied on updates
 * $example->set('exm_name', 'Updated Name');
 * $example->save();
 * 
 * // Required field validation (automatic)
 * $example = new Example(NULL);
 * // $example->set('exm_name', '');  // Don't set required field
 * try {
 *     $example->save();
 * } catch (SystemBaseException $e) {
 *     echo $e->getMessage();  // "Required field 'exm_name' must be set."
 * }
 * 
 * // Soft delete
 * $example->soft_delete();
 * 
 * // Search multiple records using options array
 * $examples = new MultiExample(
 *     array('active' => true, 'status' => 1),  // Uses getMultiResults filtering
 *     array('exm_name' => 'ASC')                // Sort order
 * );
 * 
 * if ($examples->count_all() > 0) {
 *     $examples->load();
 *     foreach ($examples as $example) {
 *         echo $example->get('exm_name') . "\n";
 *     }
 * }
 * 
 * // Built-in URL generation (requires exm_link field and $url_namespace)
 * $short_url = $example->get_url('short');  // Returns /example/my-slug  
 * $full_url = $example->get_url('full');    // Returns https://domain.com/example/my-slug
 */

?>