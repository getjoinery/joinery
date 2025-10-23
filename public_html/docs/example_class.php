<?php
/**
 * Example Data Model Class Template
 * 
 * This file demonstrates the complete structure and patterns for creating
 * data model classes in this system. Copy this template and modify for
 * your specific table/entity.
 * 
 * USAGE:
 * 1. Copy this file to /data/[tablename]_class.php
 * 2. Replace "Example" with your actual class name (PascalCase)
 * 3. Replace "MultiExample" with Multi + your class name
 * 4. Update all static properties for your table
 * 5. Add any custom methods specific to your entity
 * 6. Run php -l to validate syntax
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
    
    // REQUIRED: Field definitions - controls database schema
    public static $fields = array(
        // Primary key - always required
        'exm_id',
        
        // Example field types with naming convention: [3-char-prefix]_[fieldname]
        'exm_name',           // varchar field - required
        'exm_description',    // text field  
        'exm_link',           // varchar field - URL slug
        'exm_code',           // character field - fixed length
        'exm_status',         // integer field - with default
        'exm_price',          // numeric field - decimal precision
        'exm_counter',        // int8 field - zero on create
        'exm_small_number',   // int2 field - small integers
        'exm_is_active',      // bool field - with default
        'exm_category_id',    // int8 foreign key field
        'exm_metadata',       // jsonb field
        'exm_settings',       // json field
        'exm_event_date',     // date field
        
        // Standard audit fields - recommended for all tables
        'exm_created',        // timestamp - creation time
        'exm_updated',        // timestamp - last update time
        'exm_delete_time',    // timestamp - soft delete (NULL = active)
        'exm_created_by',     // integer - user ID who created
        'exm_updated_by'      // integer - user ID who last updated
    );
    
    // REQUIRED: Complete field specifications - defines database schema AND runtime behavior
    //
    // SUPPORTED DATA TYPES (from LibraryFunctions::translate_data_types):
    // Text types:    'varchar(length)', 'text', 'character(length)'
    // Integer types: 'integer'/'int4', 'int8'/'bigint', 'int2'/'smallint' 
    // Decimal types: 'numeric(precision,scale)'
    // Boolean type:  'bool'/'boolean'
    // Date types:    'date', 'timestamp', 'timestamp with time zone'
    // JSON types:    'json', 'jsonb' (jsonb recommended for performance)
    // Serial types:  'bigserial' (auto-incrementing primary keys)
    //
    // Runtime behavior properties (do NOT affect database schema):
    // 'required' => true           - Field must be non-null and non-empty string
    // 'default' => mixed           - Default value for new records only
    // 'zero_on_create' => true     - Set to 0 when creating if NULL
    // 'unique' => true             - Single field unique constraint
    // 'unique_with' => array(...)  - Multi-field unique constraint
    // 
    // AUTO-DETECTION:
    // - Timestamp fields detected from type: 'timestamp', 'date'
    // - JSON fields detected from type: 'json', 'jsonb'
    //
    public static $field_specifications = array(
        // Primary key specification
        'exm_id' => array(
            'type' => 'bigserial',
            'is_nullable' => false,
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
            'foreign_key' => array(
                'table' => 'categories',
                'column' => 'cat_id',
                'on_delete' => 'SET NULL'
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
            'default' => 'CURRENT_TIMESTAMP'
            // Automatically detected as timestamp field
        ),
        'exm_updated' => array(
            'type' => 'timestamp with time zone', // Supported: timestamp with time zone
            'is_nullable' => false,
            'default' => 'CURRENT_TIMESTAMP'
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
    
    // VALIDATION: Validation rules are handled through field_specifications properties:
    // - 'required' => true     - Field must have a value
    // - 'unique' => true       - Field must be unique across table
    // - 'unique_with' => []    - Multi-field unique constraint
    // These are checked during save() before database operations
    // For form validation, use FormWriter's set_validate() method with rules defined separately


    // REQUIRED: Actions to take when permanently deleting records
    // This array MUST be defined (even if empty) for all model classes
    // Defines cleanup operations when permanent_delete() is called
    public static $permanent_delete_actions = array(
        // Example: delete related files
        // 'delete_files' => array('exm_image_path'),
        // Example: cascade delete related records  
        // 'cascade_delete' => array(
        //     'table' => 'exm_related',
        //     'foreign_key' => 'exm_example_id'
        // )
    );
    
    // NOTE: json_vars is LEGACY - being consolidated into field_specifications
    // JSON fields are now AUTO-DETECTED from field_specifications using optimized is_json_field() method
    // Auto-detection logic (in SystemBase):
    //
    // protected function is_json_field($field_name) {
    //     if (!isset(static::$field_specifications[$field_name])) {
    //         return false;
    //     }
    //     
    //     $type = static::$field_specifications[$field_name]['type'] ?? '';
    //     
    //     // Optimized: Quick rejection based on first character
    //     $first_char = $type[0] ?? '';
    //     if ($first_char !== 'j') {
    //         return false; // Not json/jsonb
    //     }
    //     
    //     // Only check if starts with 'j' - much faster than string search
    //     return $type === 'json' || $type === 'jsonb';
    // }
    //
    // Usage in get_json(): if ($this->is_json_field($field)) { ... }
    // For custom API output control, override get_json() method instead
    
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