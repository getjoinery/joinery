<?php

/**
 * DatabaseUpdater - Abstracts database table creation and migration logic
 * 
 * This class extracts the core functionality from update_database.php to make it
 * reusable for plugin installations and system repairs.
 */
class DatabaseUpdater {
    
    private $dbconnector;
    private $verbose;
    private $upgrade;
    private $cleanup;
    
    /**
     * Constructor
     * 
     * @param bool $verbose Show detailed output
     * @param bool $upgrade Allow data type upgrades
     * @param bool $cleanup Remove superfluous columns
     */
    public function __construct($verbose = false, $upgrade = false, $cleanup = false) {
        $this->dbconnector = DbConnector::get_instance();
        $this->verbose = $verbose;
        $this->upgrade = $upgrade;
        $this->cleanup = $cleanup;
    }
    
    /**
     * Run full database update (equivalent to update_database.php)
     * Note: Now only handles core system tables, not plugin tables
     * 
     * @return array Results with success status and messages
     */
    public function runFullUpdate() {
        return $this->runUpdate(['include_plugins' => false]);
    }
    
    /**
     * Run table creation for a specific plugin only
     * 
     * @param string $plugin_name Plugin directory name
     * @return array Results with success status and messages
     */
    public function runPluginTablesOnly($plugin_name) {
        return $this->runUpdate([
            'include_plugins' => true,
            'plugin_filter' => $plugin_name
        ]);
    }
    
    /**
     * Run table creation for core system only (no plugins)
     * 
     * @return array Results with success status and messages
     */
    public function runCoreTablesOnly() {
        return $this->runUpdate(['include_plugins' => false]);
    }
    
    /**
     * Core database update logic
     * 
     * @param array $options Update options
     * @return array Results with success status and messages
     */
    private function runUpdate($options = []) {
        $results = [
            'success' => false,
            'tables_created' => [],
            'columns_added' => [],
            'sequences_created' => [],
            'warnings' => [],
            'errors' => [],
            'messages' => []
        ];
        
        try {
            // Capture any output during class discovery (including errors)
            ob_start();
            
            // Load model classes
            $classes = LibraryFunctions::discover_model_classes(array_merge([
                'require_tablename' => true,
                'require_field_specifications' => true,
                'verbose' => $this->verbose
            ], $options));
            
            // Check if any errors were output
            $output = ob_get_clean();
            if (strpos($output, 'ERROR:') !== false) {
                $results['errors'][] = strip_tags($output);
                $results['success'] = false;
                return $results;
            }
            
            // Restore any non-error output
            if ($output && $this->verbose) {
                echo $output;
            }
            
            if ($this->verbose) {
                $results['messages'][] = 'Loaded ' . count($classes) . ' model classes';
            }
            
            $dblink = $this->dbconnector->get_db_link();
            $tables_and_columns = LibraryFunctions::get_tables_and_columns();
            
            // First pass: Create missing tables
            foreach ($classes as $class) {
                $table_result = $this->createTableIfMissing($class, $tables_and_columns, $dblink);
                $results = $this->mergeResults($results, $table_result);
            }
            
            // Refresh table information after creating new tables
            $tables_and_columns = LibraryFunctions::get_tables_and_columns();
            
            // Second pass: Add missing columns to existing tables
            foreach ($classes as $class) {
                $column_result = $this->addMissingColumns($class, $tables_and_columns, $dblink);
                $results = $this->mergeResults($results, $column_result);
            }
            
            $results['success'] = true;
            
        } catch (Exception $e) {
            $results['errors'][] = $e->getMessage();
            $results['success'] = false;
        }
        
        return $results;
    }
    
    /**
     * Create a table if it doesn't exist
     * 
     * @param string $class Model class name
     * @param array $tables_and_columns Current database structure
     * @param PDO $dblink Database connection
     * @return array Results
     */
    private function createTableIfMissing($class, $tables_and_columns, $dblink) {
        $results = [
            'tables_created' => [],
            'errors' => [],
            'messages' => []
        ];
        
        $table_name = $class::$tablename;
        $pkey_column = $class::$pkey_column;
        
        // Check if table exists
        if (isset($tables_and_columns[$table_name])) {
            if ($this->verbose) {
                $results['messages'][] = "Table {$table_name} already exists";
            }
            return $results;
        }
        
        try {
            // Create sequence for primary key
            $sequence_name = $table_name . '_' . $pkey_column . '_seq';
            $sql = "CREATE SEQUENCE IF NOT EXISTS {$sequence_name}
                    INCREMENT BY 1
                    NO MAXVALUE
                    NO MINVALUE
                    CACHE 1";
            
            $q = $dblink->prepare($sql);
            $q->execute();
            
            if ($this->verbose) {
                $results['messages'][] = "Created sequence: {$sequence_name}";
            }
            
            // Create table
            $sql = 'CREATE TABLE "public"."' . $table_name . '" (';
            
            foreach ($class::$field_specifications as $field_name => $field_specs) {
                $sql .= ' "' . $field_name . '" ' . $field_specs['type'];
                
                if (isset($field_specs['is_nullable']) && !$field_specs['is_nullable']) {
                    $sql .= ' NOT NULL ';
                }
                
                if (isset($field_specs['serial']) && $field_specs['serial']) {
                    $sql .= "DEFAULT nextval('{$sequence_name}'::regclass)";
                }
                $sql .= ', ';
            }
            
            // Remove last comma
            $sql = substr($sql, 0, -2);
            $sql .= ');';
            
            $q = $dblink->prepare($sql);
            $q->execute();
            
            // Add primary key constraint using shared method
            // Critical failure for new tables - throw exception if constraint can't be added
            if (!$this->addPrimaryKeyConstraint($table_name, $pkey_column, $dblink)) {
                throw new Exception("Failed to add primary key constraint to new table {$table_name}");
            }
            
            $results['tables_created'][] = $table_name;
            $results['messages'][] = "Created table: {$table_name}";
            
        } catch (PDOException $e) {
            $results['errors'][] = "Error creating table {$table_name}: " . $e->getMessage();
        }
        
        return $results;
    }
    
    /**
     * Process all column operations including modifications
     * 
     * @param array $classes Array of model classes
     * @return array Results
     */
    public function processAdvancedColumnOperations($classes) {
        $results = [
            'success' => true,
            'columns_modified' => [],
            'columns_dropped' => [],
            'errors' => [],
            'warnings' => [],
            'messages' => []
        ];
        
        try {
            $dblink = $this->dbconnector->get_db_link();
            $tables_and_columns = LibraryFunctions::get_tables_and_columns();
            
            foreach ($classes as $class) {
                $table_name = $class::$tablename;
                $live_table_columns = $tables_and_columns[$table_name] ?? [];
                
                if (empty($live_table_columns)) {
                    continue; // Skip if table doesn't exist
                }
                
                // Process column modifications and cleanup
                $this->processTableColumns($class, $table_name, $dblink, $results);
            }
            
        } catch (Exception $e) {
            $results['success'] = false;
            $results['errors'][] = "Advanced column processing error: " . $e->getMessage();
        }
        
        return $results;
    }
    
    /**
     * Process columns for a single table - handle modifications and cleanup
     */
    private function processTableColumns($class, $table_name, $dblink, &$results) {
        // Get detailed column information
        $live_columns = $this->getDetailedColumnInfo($table_name, $dblink);
        $field_specifications = $class::$field_specifications ?? [];
        
        // Handle column modifications if upgrade OR cleanup mode is enabled
        if ($this->upgrade || $this->cleanup) {
            foreach ($field_specifications as $field_name => $field_specs) {
                if (isset($live_columns[$field_name])) {
                    $this->processColumnModifications($table_name, $field_name, $field_specs, $live_columns[$field_name], $dblink, $results);
                }
            }
        }
        
        // Handle column cleanup if cleanup mode is enabled
        if ($this->cleanup) {
            $this->processColumnCleanup($table_name, $field_specifications, $live_columns, $dblink, $results);
        }
    }
    
    /**
     * Process potential column modifications (type changes, constraint changes)
     */
    private function processColumnModifications($table_name, $field_name, $field_specs, $live_column_info, $dblink, &$results) {
        $field_type = $field_specs['type'];
        
        // Extract base type and length
        if (preg_match('/^([a-zA-Z0-9_]+)(?:\((\d+)\))?/', $field_type, $matches)) {
            $field_without_length = $matches[1];
            $field_length = isset($matches[2]) ? (int)$matches[2] : null;
        } else {
            $field_without_length = $field_type;
            $field_length = null;
        }
        
        $is_character_type = in_array($field_without_length, ['varchar', 'char', 'text']);
        
        // Translate database type to our standard format
        ob_start();
        $live_data_type = LibraryFunctions::translate_data_types($live_column_info['data_type']);
        $translation_output = ob_get_clean();
        
        // Check if translation produced an error
        if (strpos($translation_output, 'ERROR:') !== false) {
            $results['errors'][] = "Column {$table_name}.{$field_name}: " . strip_tags($translation_output);
            return; // Skip this column modification
        }
        
        // Check if we need to modify the column type
        $needs_type_change = false;
        $needs_length_change = false;
        
        if (!$this->areTypesEquivalent($live_data_type, $field_without_length)) {
            $needs_type_change = true;
        }
        
        if ($is_character_type && $field_length && $live_column_info['character_maximum_length'] != $field_length) {
            $needs_length_change = true;
        }
        
        // Perform column modification if needed
        if ($needs_type_change || $needs_length_change) {
            $sql = "ALTER TABLE {$table_name} ALTER COLUMN {$field_name} TYPE {$field_type}";
            
            try {
                $q = $dblink->prepare($sql);
                $q->execute();
                $results['columns_modified'][] = "{$table_name}.{$field_name}";
                $results['messages'][] = "Modified column type: {$table_name}.{$field_name} to {$field_type}";
            } catch (PDOException $e) {
                // Check if this is a type casting issue and if we can force it
                if (strpos($e->getMessage(), 'cannot be cast automatically') !== false) {
                    // Check if table is empty or if we should try forced conversion
                    $count_sql = "SELECT COUNT(*) as row_count FROM {$table_name}";
                    $count_q = $dblink->prepare($count_sql);
                    $count_q->execute();
                    $row_count = $count_q->fetch(PDO::FETCH_ASSOC)['row_count'];
                    
                    if ($row_count == 0) {
                        // Table is empty, we can safely force the conversion
                        try {
                            $force_sql = "ALTER TABLE {$table_name} ALTER COLUMN {$field_name} TYPE {$field_type} USING {$field_name}::{$field_type}";
                            $force_q = $dblink->prepare($force_sql);
                            $force_q->execute();
                            $results['columns_modified'][] = "{$table_name}.{$field_name}";
                            $results['messages'][] = "FORCED column type conversion: {$table_name}.{$field_name} to {$field_type} (table was empty)";
                            return; // Success, exit the method
                        } catch (PDOException $force_e) {
                            // Even forced conversion failed
                            $current_type = $live_column_info['data_type'] ?? 'unknown';
                            $results['errors'][] = "CRITICAL: Could not force column conversion {$table_name}.{$field_name} from {$current_type} to {$field_type} even with empty table: " . $force_e->getMessage();
                            return;
                        }
                    } else {
                        // Table has data - this is a critical error that must be fixed before deployment
                        $current_type = $live_column_info['data_type'] ?? 'unknown';
                        $results['errors'][] = "CRITICAL: Could not modify column {$table_name}.{$field_name} from {$current_type} to {$field_type}: Cannot cast automatically and table contains {$row_count} rows. This datatype mismatch will cause application crashes. Manual intervention required - run: ALTER TABLE {$table_name} ALTER COLUMN {$field_name} TYPE {$field_type} USING {$field_name}::{$field_type};";
                        return;
                    }
                }
                
                // Other type of error - treat as critical
                $error_message = $e->getMessage();
                $error_message = str_replace('ERROR:', 'PostgreSQL says:', $error_message);
                $current_type = $live_column_info['data_type'] ?? 'unknown';
                $results['errors'][] = "CRITICAL: Could not modify column {$table_name}.{$field_name} from {$current_type} to {$field_type}: " . $error_message . " This datatype mismatch could cause application crashes.";
            }
        }
        
        // Handle nullable constraint changes
        $spec_nullable = !isset($field_specs['is_nullable']) || $field_specs['is_nullable'];
        $live_nullable = ($live_column_info['is_nullable'] == 'YES');
        
        // Skip nullable constraint changes for primary key columns
        // Primary keys must always be NOT NULL and cannot be modified
        if ($this->isPrimaryKeyColumn($table_name, $field_name, $dblink)) {
            if ($this->verbose) {
                $results['messages'][] = "Skipping nullable constraint change for primary key column: {$table_name}.{$field_name}";
            }
        } elseif ($spec_nullable != $live_nullable) {
            if ($spec_nullable) {
                // Remove NOT NULL constraint
                $sql = "ALTER TABLE {$table_name} ALTER COLUMN {$field_name} DROP NOT NULL";
            } else {
                // Add NOT NULL constraint - but first check for NULL values
                $null_check_sql = "SELECT COUNT(*) as null_count FROM {$table_name} WHERE {$field_name} IS NULL";
                try {
                    $null_q = $dblink->prepare($null_check_sql);
                    $null_q->execute();
                    $null_result = $null_q->fetch(PDO::FETCH_ASSOC);
                    $null_count = $null_result['null_count'];
                    
                    if ($null_count > 0) {
                        $results['warnings'][] = "Cannot add NOT NULL constraint to {$table_name}.{$field_name}: column contains {$null_count} NULL values";
                        return; // Skip this constraint modification
                    }
                } catch (PDOException $e) {
                    $results['warnings'][] = "Could not check for NULL values in {$table_name}.{$field_name}: " . $e->getMessage();
                    return; // Skip this constraint modification  
                }
                
                $sql = "ALTER TABLE {$table_name} ALTER COLUMN {$field_name} SET NOT NULL";
            }
            
            try {
                $q = $dblink->prepare($sql);
                $q->execute();
                $results['columns_modified'][] = "{$table_name}.{$field_name} (nullable constraint)";
                $results['messages'][] = "Modified nullable constraint: {$table_name}.{$field_name}";
            } catch (PDOException $e) {
                $results['warnings'][] = "Could not modify nullable constraint for {$table_name}.{$field_name}: " . $e->getMessage();
            }
        }
    }
    
    /**
     * Clean up columns that don't exist in specifications
     * CRITICAL SAFETY: Never drop primary key columns
     */
    private function processColumnCleanup($table_name, $field_specifications, $live_columns, $dblink, &$results) {
        foreach ($live_columns as $column_name => $column_info) {
            if (!isset($field_specifications[$column_name])) {
                // SAFETY CHECK: Never drop primary key columns
                if ($this->isPrimaryKeyColumn($table_name, $column_name, $dblink)) {
                    $results['warnings'][] = "SAFETY: Skipped dropping primary key column {$table_name}.{$column_name}";
                    continue;
                }
                
                // Column exists in database but not in specifications - safe to drop
                $sql = "ALTER TABLE {$table_name} DROP COLUMN {$column_name}";
                
                try {
                    $q = $dblink->prepare($sql);
                    $q->execute();
                    $results['columns_dropped'][] = "{$table_name}.{$column_name}";
                    $results['messages'][] = "Dropped obsolete column: {$table_name}.{$column_name}";
                } catch (PDOException $e) {
                    $results['warnings'][] = "Could not drop column {$table_name}.{$column_name}: " . $e->getMessage();
                }
            }
        }
    }
    
    /**
     * Check if a column is a primary key column
     * CRITICAL SAFETY METHOD: Prevents accidental deletion of primary keys
     */
    private function isPrimaryKeyColumn($table_name, $column_name, $dblink) {
        $sql = "SELECT 1 FROM information_schema.table_constraints tc
                JOIN information_schema.key_column_usage kcu 
                  ON tc.constraint_name = kcu.constraint_name
                WHERE tc.table_name = ? 
                  AND tc.table_schema = 'public'
                  AND tc.constraint_type = 'PRIMARY KEY' 
                  AND kcu.column_name = ?";
        
        try {
            $q = $dblink->prepare($sql);
            $q->execute([$table_name, $column_name]);
            return $q->rowCount() > 0;
        } catch (PDOException $e) {
            // If we can't determine, err on the side of caution
            if ($this->verbose) {
                echo "Error checking primary key status for {$table_name}.{$column_name}: " . $e->getMessage() . "\n";
            }
            return true; // Assume it's a primary key to prevent dropping
        }
    }
    

    /**
     * Get detailed column information for a table
     */
    private function getDetailedColumnInfo($table_name, $dblink) {
        $sql = "SELECT column_name, data_type, character_maximum_length, is_nullable, column_default
                FROM information_schema.columns
                WHERE table_name = ? AND table_schema = 'public'";
        
        try {
            $q = $dblink->prepare($sql);
            $q->execute([$table_name]);
            $columns = [];
            
            while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
                $columns[$row['column_name']] = $row;
            }
            
            return $columns;
        } catch (PDOException $e) {
            if ($this->verbose) {
                echo "Error getting column info for {$table_name}: " . $e->getMessage() . "\n";
            }
            return [];
        }
    }
    
    /**
     * Add missing columns and validate existing columns
     * 
     * @param string $class Model class name
     * @param array $tables_and_columns Current database structure
     * @param PDO $dblink Database connection
     * @return array Results
     */
    private function addMissingColumns($class, $tables_and_columns, $dblink) {
        $results = [
            'columns_added' => [],
            'sequences_created' => [],
            'warnings' => [],
            'errors' => [],
            'messages' => []
        ];
        
        $table_name = $class::$tablename;
        $pkey_column = $class::$pkey_column;
        $live_table_columns = $tables_and_columns[$table_name] ?? [];
        
        if (empty($live_table_columns)) {
            // Table doesn't exist, skip column checking
            return $results;
        }
        
        try {
            // Get detailed column information
            $sql = "SELECT column_name, data_type, character_maximum_length, is_nullable
                    FROM information_schema.columns
                    WHERE table_name = ?";
            $q = $dblink->prepare($sql);
            $q->execute([$table_name]);
            $existing_columns = $q->fetchAll(PDO::FETCH_ASSOC);
            
            // Index columns by name for easy lookup
            $live_column_info = [];
            foreach ($existing_columns as $col) {
                $live_column_info[$col['column_name']] = $col;
            }
            
            $existing_column_names = array_keys($live_column_info);
            
            // Check each field specification
            foreach ($class::$field_specifications as $field_name => $field_specs) {
                if (!in_array($field_name, $existing_column_names)) {
                    // Add missing column
                    $this->addMissingColumn($table_name, $field_name, $field_specs, $dblink, $results);
                } else {
                    // Validate existing column
                    $this->validateExistingColumn($table_name, $field_name, $field_specs, $live_column_info[$field_name], $results);
                }
                
                // Handle sequence creation for serial fields
                if (isset($field_specs['serial']) && $field_specs['serial']) {
                    $this->ensureSequenceExists($table_name, $pkey_column, $field_name, $dblink, $results);
                }
            }
            
        } catch (PDOException $e) {
            $results['errors'][] = "Error processing columns for {$table_name}: " . $e->getMessage();
        }
        
        return $results;
    }
    
    /**
     * Add a missing column to a table
     * Adds columns as nullable initially - NOT NULL constraints handled by processAdvancedColumnOperations()
     */
    private function addMissingColumn($table_name, $field_name, $field_specs, $dblink, &$results) {
        try {
            // Add column as nullable to avoid constraint violations on existing tables
            // NOT NULL constraints will be handled by processAdvancedColumnOperations() when --upgrade flag is used
            $sql = 'ALTER TABLE "public"."' . $table_name . '" ADD COLUMN "' . $field_name . '" ' . $field_specs['type'];
            
            $q = $dblink->prepare($sql);
            $q->execute();
            
            $results['columns_added'][] = "{$table_name}.{$field_name}";
            $results['messages'][] = "Added column: {$table_name}.{$field_name}";
            
            // Note about NOT NULL constraints
            if (isset($field_specs['is_nullable']) && !$field_specs['is_nullable']) {
                $results['messages'][] = "Note: {$table_name}.{$field_name} will be set to NOT NULL during advanced operations (use --upgrade flag)";
            }
            
        } catch (PDOException $e) {
            $results['errors'][] = "Error adding column {$table_name}.{$field_name}: " . $e->getMessage();
        }
    }
    
    /**
     * Validate an existing column against specifications
     */
    private function validateExistingColumn($table_name, $field_name, $field_specs, $live_column_info, &$results) {
        $field_type = $field_specs['type'];
        
        // Extract base type and length for comparison
        if (preg_match('/^([a-zA-Z0-9_]+)(?:\((\d+)\))?/', $field_type, $matches)) {
            $field_without_length = $matches[1];
            $field_length = isset($matches[2]) ? (int)$matches[2] : null;
        } else {
            $field_without_length = $field_type;
            $field_length = null;
        }
        
        // Translate database type to our standard format
        ob_start();
        $live_data_type = LibraryFunctions::translate_data_types($live_column_info['data_type']);
        $translation_output = ob_get_clean();
        
        // Check if translation produced an error
        if (strpos($translation_output, 'ERROR:') !== false) {
            $results['errors'][] = "Column {$table_name}.{$field_name}: " . strip_tags($translation_output);
            return; // Skip this column
        }
        
        // Check data type mismatch (considering PostgreSQL type aliases)
        if (!$this->areTypesEquivalent($live_data_type, $field_without_length)) {
            $results['warnings'][] = "Data type mismatch on {$table_name}.{$field_name} (live: {$live_data_type} <-> spec: {$field_without_length})";
        }
        
        // Check character length for text fields
        $is_character_type = in_array($field_without_length, ['varchar', 'char', 'text']);
        if ($is_character_type && $field_length) {
            if (!$live_column_info['character_maximum_length']) {
                $results['warnings'][] = "Model specifies max length {$field_length} but database column has no length limit on {$table_name}.{$field_name}";
            } elseif ($live_column_info['character_maximum_length'] != $field_length) {
                $results['warnings'][] = "Max character length mismatch on {$table_name}.{$field_name} (live: {$live_column_info['character_maximum_length']} <-> spec: {$field_length})";
            }
        } elseif ($is_character_type && !$field_length && $live_column_info['character_maximum_length']) {
            $results['warnings'][] = "Database has length limit {$live_column_info['character_maximum_length']} but model specifies no length limit on {$table_name}.{$field_name}";
        }
        
        // Check nullable constraint
        $spec_nullable = !isset($field_specs['is_nullable']) || $field_specs['is_nullable'];
        $live_nullable = ($live_column_info['is_nullable'] == 'YES');
        
        if ($spec_nullable != $live_nullable) {
            if ($spec_nullable) {
                $results['warnings'][] = "Column {$table_name}.{$field_name} should allow NULL but currently has NOT NULL constraint";
            } else {
                $results['warnings'][] = "Column {$table_name}.{$field_name} should have NOT NULL constraint but currently allows NULL";
            }
        }
    }
    
    /**
     * Ensure sequence exists for serial fields
     */
    private function ensureSequenceExists($table_name, $pkey_column, $field_name, $dblink, &$results) {
        try {
            $sequence_name = $table_name . '_' . $pkey_column . '_seq';
            
            // Check if sequence already exists
            $sql = "SELECT 1 FROM information_schema.sequences WHERE sequence_name = ?";
            $q = $dblink->prepare($sql);
            $q->execute([$sequence_name]);
            
            if (!$q->fetch()) {
                // Get current max value for sequence start
                $sql = "SELECT COALESCE(MAX({$field_name}), 0) + 1 as start_val FROM {$table_name}";
                $q = $dblink->prepare($sql);
                $q->execute();
                $row = $q->fetch(PDO::FETCH_ASSOC);
                $start_val = $row['start_val'] ?: 1;
                
                // Create sequence
                $sql = "CREATE SEQUENCE {$sequence_name} START WITH {$start_val} INCREMENT BY 1 NO MAXVALUE NO MINVALUE CACHE 1";
                $q = $dblink->prepare($sql);
                $q->execute();
                
                // Set column default
                $sql = "ALTER TABLE {$table_name} ALTER COLUMN {$field_name} SET DEFAULT nextval('{$sequence_name}'::regclass)";
                $q = $dblink->prepare($sql);
                $q->execute();
                
                $results['sequences_created'][] = $sequence_name;
                $results['messages'][] = "Created sequence: {$sequence_name}";
            }
            
        } catch (PDOException $e) {
            $results['errors'][] = "Error managing sequence for {$table_name}.{$field_name}: " . $e->getMessage();
        }
    }
    
    /**
     * Merge results from multiple operations
     * 
     * @param array $target Target results array
     * @param array $source Source results array
     * @return array Merged results
     */
    private function mergeResults($target, $source) {
        foreach (['tables_created', 'columns_added', 'sequences_created', 'warnings', 'errors', 'messages'] as $key) {
            if (isset($source[$key])) {
                $target[$key] = array_merge($target[$key] ?? [], $source[$key]);
            }
        }
        return $target;
    }
    
    /**
     * Manage unique constraints for all tables
     * 
     * @param array $classes Array of model classes
     * @return array Results
     */
    public function manageUniqueConstraints($classes) {
        $results = [
            'success' => true,
            'constraints_added' => [],
            'constraints_removed' => [],
            'errors' => [],
            'messages' => []
        ];
        
        try {
            $dblink = $this->dbconnector->get_db_link();
            
            foreach ($classes as $class) {
                $table = $class::$tablename;
                
                // Add missing constraints
                if ($this->cleanup || $this->upgrade) {
                    $this->addMissingConstraints($class, $table, $dblink, $results);
                }
                
                // Remove obsolete constraints (cleanup mode only)
                if ($this->cleanup) {
                    $this->removeObsoleteConstraints($class, $table, $dblink, $results);
                }
            }
            
        } catch (Exception $e) {
            $results['success'] = false;
            $results['errors'][] = "Constraint management error: " . $e->getMessage();
        }
        
        return $results;
    }
    
    /**
     * Add missing unique constraints for a table
     */
    private function addMissingConstraints($class, $table, $dblink, &$results) {
        if (!isset($class::$field_specifications)) {
            return;
        }
        
        foreach ($class::$field_specifications as $field => $spec) {
            // Single field unique constraints
            if (isset($spec['unique']) && $spec['unique']) {
                $constraint_name = $this->generateOptimalConstraintName($table, [$field], 'unique');
                
                // Check if constraint exists by name first
                if (!$this->constraintExists($constraint_name, $dblink)) {
                    // Also check for existing constraint by column structure (handles truncated names)
                    $existing_constraint = $this->findExistingConstraintByColumns($table, [$field], $dblink, 'UNIQUE');
                    if ($existing_constraint) {
                        if ($this->verbose) {
                            $results['messages'][] = "Unique constraint already exists with different name: $existing_constraint";
                        }
                        // Don't add - constraint already exists with different name
                    } else {
                        // No existing constraint found - safe to add
                        $sql = "ALTER TABLE $table ADD CONSTRAINT $constraint_name UNIQUE ($field)";
                        if ($this->executeConstraintSql($sql, $dblink, $table, [$field])) {
                            $results['constraints_added'][] = $constraint_name;
                            $results['messages'][] = "Added unique constraint: $constraint_name";
                        } else {
                            $results['warnings'][] = "Unique constraint skipped for $constraint_name due to duplicate values";
                        }
                    }
                } elseif ($this->verbose) {
                    $results['messages'][] = "Unique constraint already exists: $constraint_name";
                }
            }
            
            // Composite unique constraints
            if (isset($spec['unique_with'])) {
                $fields = array_merge(array($field), $spec['unique_with']);
                $constraint_name = $this->generateOptimalConstraintName($table, $fields, 'unique');
                
                // Check if constraint exists by name first
                if (!$this->constraintExists($constraint_name, $dblink)) {
                    // Also check for existing constraint by column structure (handles truncated names)
                    $existing_constraint = $this->findExistingConstraintByColumns($table, $fields, $dblink, 'UNIQUE');
                    if ($existing_constraint) {
                        if ($this->verbose) {
                            $results['messages'][] = "Composite unique constraint already exists with different name: $existing_constraint";
                        }
                        // Don't add - constraint already exists with different name
                    } else {
                        // No existing constraint found - safe to add
                        $columns = implode(', ', $fields);
                        $sql = "ALTER TABLE $table ADD CONSTRAINT $constraint_name UNIQUE ($columns)";
                        if ($this->executeConstraintSql($sql, $dblink, $table, $fields)) {
                            $results['constraints_added'][] = $constraint_name;
                            $results['messages'][] = "Added composite unique constraint: $constraint_name";
                        } else {
                            $results['warnings'][] = "Unique constraint skipped for $constraint_name due to duplicate values";
                        }
                    }
                } elseif ($this->verbose) {
                    $results['messages'][] = "Composite unique constraint already exists: $constraint_name";
                }
            }
        }
    }
    
    /**
     * Remove obsolete constraints from a table
     */
    private function removeObsoleteConstraints($class, $table, $dblink, &$results) {
        $existing_constraints = $this->getTableUniqueConstraints($table, $dblink);
        $expected_constraints = $this->getExpectedUniqueConstraints($class);
        
        foreach ($existing_constraints as $constraint_name) {
            if (!in_array($constraint_name, $expected_constraints)) {
                $sql = "ALTER TABLE $table DROP CONSTRAINT $constraint_name";
                if ($this->executeConstraintSql($sql, $dblink)) {
                    $results['constraints_removed'][] = $constraint_name;
                    $results['messages'][] = "Removed obsolete unique constraint: $constraint_name";
                } else {
                    $results['errors'][] = "Failed to remove constraint: $constraint_name";
                }
            }
        }
    }
    
    /**
     * Check if a constraint exists
     */
    private function constraintExists($constraint_name, $dblink) {
        $sql = "SELECT 1 FROM information_schema.table_constraints 
                WHERE constraint_name = :constraint_name 
                AND table_schema = 'public'";
        try {
            $q = $dblink->prepare($sql);
            $q->bindValue(':constraint_name', $constraint_name, PDO::PARAM_STR);
            $q->execute();
            return $q->rowCount() > 0;
        } catch(PDOException $e) {
            if ($this->verbose) {
                echo "Error checking constraint existence: " . $e->getMessage() . "\n";
            }
            return false;
        }
    }
    
    /**
     * Check for duplicate values in unique constraint columns
     * 
     * @param string $table_name Table name
     * @param array $columns Array of column names for the unique constraint
     * @param PDO $dblink Database connection
     * @return array Array with 'has_duplicates' boolean and 'duplicates' array
     */
    private function checkForDuplicateUniqueConstraintValues($table_name, $columns, $dblink) {
        $result = [
            'has_duplicates' => false,
            'duplicates' => []
        ];
        
        try {
            // Build the GROUP BY and SELECT clauses for multiple columns
            // Use COALESCE to handle NULLs consistently
            $select_columns = [];
            $group_columns = [];
            
            foreach ($columns as $column) {
                $select_columns[] = "COALESCE(CAST({$column} AS TEXT), '<NULL>') as {$column}_str";
                $group_columns[] = "COALESCE(CAST({$column} AS TEXT), '<NULL>')";
            }
            
            $select_clause = implode(', ', $select_columns);
            $group_clause = implode(', ', $group_columns);
            
            // Create a concatenated representation for display
            $concat_columns = [];
            foreach ($columns as $column) {
                $concat_columns[] = "COALESCE(CAST({$column} AS TEXT), '<NULL>')";
            }
            $concat_clause = implode(" || '|' || ", $concat_columns);
            
            $sql = "SELECT {$concat_clause} as combined_value, COUNT(*) as count 
                    FROM {$table_name} 
                    GROUP BY {$group_clause}
                    HAVING COUNT(*) > 1 
                    ORDER BY COUNT(*) DESC";
            
            if ($this->verbose) {
                echo "Checking for duplicate values in unique constraint on {$table_name}(" . implode(', ', $columns) . ")<br>\n";
            }
            
            $q = $dblink->prepare($sql);
            $q->execute();
            
            $duplicates = $q->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($duplicates)) {
                $result['has_duplicates'] = true;
                foreach ($duplicates as $duplicate) {
                    $result['duplicates'][$duplicate['combined_value']] = $duplicate['count'];
                }
            }
            
            if ($this->verbose && !$result['has_duplicates']) {
                echo "No duplicate values found in unique constraint columns<br>\n";
            }
            
        } catch (PDOException $e) {
            if ($this->verbose) {
                echo "Error checking for unique constraint duplicates in {$table_name}: " . $e->getMessage() . "<br>\n";
            }
            // If we can't check for duplicates, assume there might be some to be safe
            $result['has_duplicates'] = true;
            $result['duplicates']['UNKNOWN'] = 'Error checking for duplicates';
        }
        
        return $result;
    }
    
    /**
     * Execute constraint SQL with error handling and duplicate checking
     */
    private function executeConstraintSql($sql, $dblink, $table_name = null, $columns = null, $constraint_type = 'unique') {
        // If we have table and column info, check for duplicates first
        if ($table_name && $columns && strpos($sql, 'UNIQUE') !== false) {
            $duplicate_info = $this->checkForDuplicateUniqueConstraintValues($table_name, $columns, $dblink);
            
            if ($duplicate_info['has_duplicates']) {
                // Display clear warning for unique constraint duplicates
                echo "<br>⚠️  UNIQUE CONSTRAINT WARNING<br>";
                echo "═══════════════════════════════════════════════════════════════════════════════<br>";
                echo "Table: {$table_name}<br>";
                echo "Columns: " . implode(', ', $columns) . "<br>";
                echo "Issue: Duplicate values found - cannot create unique constraint<br>";
                echo "<br>";
                echo "Duplicate values detected:<br>";
                
                foreach ($duplicate_info['duplicates'] as $value => $count) {
                    if ($value === 'UNKNOWN') {
                        echo "  • {$duplicate_info['duplicates'][$value]}<br>";
                    } else {
                        // Parse the combined value back into readable format
                        $parts = explode('|', $value);
                        if (count($parts) == count($columns)) {
                            $readable_parts = [];
                            for ($i = 0; $i < count($columns); $i++) {
                                $readable_parts[] = $columns[$i] . "='" . $parts[$i] . "'";
                            }
                            echo "  • Values (" . implode(', ', $readable_parts) . "): {$count} occurrences<br>";
                        } else {
                            echo "  • Value '{$value}': {$count} occurrences<br>";
                        }
                    }
                }
                
                echo "<br>";
                echo "Action: Unique constraint creation skipped<br>";
                echo "Impact: Database will continue to function normally<br>";
                echo "═══════════════════════════════════════════════════════════════════════════════<br>";
                echo "<br>";
                
                // Return true to indicate we handled this gracefully (not an error)
                return true;
            }
        }
        
        try {
            if ($this->verbose) {
                echo "Executing: $sql<br>\n";
            }
            $q = $dblink->prepare($sql);
            $q->execute();
            return true;
        } catch(PDOException $e) {
            if ($this->verbose) {
                echo "Error: " . $e->getMessage() . "<br>\n";
            }
            return false;
        }
    }
    
    /**
     * Get existing unique constraints for a table
     */
    private function getTableUniqueConstraints($table, $dblink) {
        $sql = "SELECT constraint_name FROM information_schema.table_constraints 
                WHERE table_name = :table_name 
                AND table_schema = 'public'
                AND constraint_type = 'UNIQUE'";
        try {
            $q = $dblink->prepare($sql);
            $q->bindValue(':table_name', $table, PDO::PARAM_STR);
            $q->execute();
            return $q->fetchAll(PDO::FETCH_COLUMN);
        } catch(PDOException $e) {
            if ($this->verbose) {
                echo "Error getting table constraints: " . $e->getMessage() . "\n";
            }
            return array();
        }
    }
    
    /**
     * Check if two PostgreSQL types are equivalent
     * Handles common aliases like int/int4/integer, int8/bigint, etc.
     */
    private function areTypesEquivalent($type1, $type2) {
        // Direct match
        if ($type1 === $type2) {
            return true;
        }
        
        // Define equivalent type groups
        $equivalents = [
            ['int', 'int4', 'integer'],
            ['int8', 'bigint'],
            ['int2', 'smallint'],
            ['varchar', 'character varying'],
            ['char', 'character'],
            ['bool', 'boolean'],
            ['timestamp', 'timestamp without time zone'],
            ['timestamptz', 'timestamp with time zone'],
            ['json', 'jsonb']  // json and jsonb are considered equivalent for our purposes
        ];
        
        // Check if both types belong to the same equivalence group
        foreach ($equivalents as $group) {
            if (in_array($type1, $group) && in_array($type2, $group)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get expected unique constraints from field_specifications
     */
    private function getExpectedUniqueConstraints($class) {
        $constraints = array();
        $table = $class::$tablename;
        
        if (isset($class::$field_specifications)) {
            foreach ($class::$field_specifications as $field => $spec) {
                if (isset($spec['unique']) && $spec['unique']) {
                    $constraints[] = $this->generateOptimalConstraintName($table, [$field], 'unique');
                }
                
                if (isset($spec['unique_with'])) {
                    $fields = array_merge(array($field), $spec['unique_with']);
                    $constraints[] = $this->generateOptimalConstraintName($table, $fields, 'unique');
                }
            }
        }
        
        // Also check field_constraints for backward compatibility
        if (isset($class::$field_constraints)) {
            foreach ($class::$field_constraints as $constraint_name => $constraint_info) {
                if ($constraint_info['type'] === 'unique' && !in_array($constraint_name, $constraints)) {
                    $constraints[] = $constraint_name;
                }
            }
        }
        
        return $constraints;
    }
    
    /**
     * Run migrations after table creation
     * 
     * @param array $migrations Migrations array
     * @return array Results
     */
    public function runMigrations($migrations) {
        $results = [
            'success' => false,
            'migrations_run' => [],
            'errors' => [],
            'messages' => []
        ];
        
        try {
            // Load existing migration logic
            PathHelper::requireOnce('data/migrations_class.php');
            
            $migration_obj = new Migration();
            
            foreach ($migrations as $migration) {
                if ($migration_obj->check_migration($migration)) {
                    $run_result = $migration_obj->run_migration($migration);
                    if ($run_result) {
                        $results['migrations_run'][] = $migration['database_version'];
                        $results['messages'][] = "Applied migration: " . $migration['database_version'];
                    } else {
                        $results['errors'][] = "Failed migration: " . $migration['database_version'];
                    }
                }
            }
            
            $results['success'] = true;
            
        } catch (Exception $e) {
            $results['errors'][] = "Migration error: " . $e->getMessage();
            $results['success'] = false;
        }
        
        return $results;
    }
    
    /**
     * Fix primary key constraints for all model classes
     * 
     * Error handling: Batch operation that logs errors and continues processing
     * - Does not throw exceptions (non-critical operation)
     * - Returns success=false if any fixes failed
     * - Attempts to fix all tables even if some fail
     * 
     * @param array $classes Array of model classes to check
     * @return array Results with success status and messages
     */
    public function fixPrimaryKeys($classes) {
        $results = [
            'success' => true,
            'fixed' => [],
            'warnings' => [],
            'errors' => [],
            'messages' => []
        ];
        
        $dblink = $this->dbconnector->get_db_link();
        
        // Get all tables and columns info (using improved LibraryFunctions method)
        $tables_and_columns = LibraryFunctions::get_tables_and_columns();
        
        foreach ($classes as $class) {
            try {
                $table_name = $class::$tablename;
                $expected_pkey = $class::$pkey_column;
                
                if ($this->verbose) {
                    $results['messages'][] = "Checking primary key for {$class} ({$table_name}.{$expected_pkey})";
                }
                
                // Check if table exists (using existing pattern)
                if (!isset($tables_and_columns[$table_name])) {
                    $results['warnings'][] = "Table '{$table_name}' does not exist for model {$class}";
                    continue;
                }
                
                // Get detailed column info (using existing method)
                $column_info = $this->getDetailedColumnInfo($table_name, $dblink);
                
                // Check if primary key column exists
                if (!isset($column_info[$expected_pkey])) {
                    $results['warnings'][] = "Primary key column '{$expected_pkey}' missing from table '{$table_name}'";
                    continue;
                }
                
                // Check if column is actually set as primary key (using existing method)
                if (!$this->isPrimaryKeyColumn($table_name, $expected_pkey, $dblink)) {
                    // First, clean up NULL values in the primary key column if needed
                    if ($this->cleanupNullPrimaryKeyValues($class, $table_name, $expected_pkey, $dblink, $results)) {
                        // Use shared method to add/fix the primary key
                        // Note: addPrimaryKeyConstraint now handles duplicates internally and returns true even when skipped
                        if ($this->addPrimaryKeyConstraint($table_name, $expected_pkey, $dblink)) {
                            // Check if this was actually fixed or just skipped due to duplicates
                            // Re-check if primary key was actually added
                            if ($this->isPrimaryKeyColumn($table_name, $expected_pkey, $dblink)) {
                                $results['fixed'][] = "{$table_name}.{$expected_pkey}";
                                $results['messages'][] = "FIXED: Added primary key constraint to {$table_name}.{$expected_pkey}";
                            } else {
                                // Was skipped due to duplicates - this is expected and not an error
                                $results['warnings'][] = "Primary key constraint skipped for {$table_name}.{$expected_pkey} due to duplicate values";
                            }
                        } else {
                            $results['errors'][] = "Failed to add primary key constraint to {$table_name}.{$expected_pkey}";
                            $results['success'] = false;
                            // Continue processing other tables
                        }
                    } else {
                        $results['errors'][] = "Failed to cleanup NULL values in {$table_name}.{$expected_pkey} - cannot add primary key";
                        $results['success'] = false;
                    }
                } else {
                    if ($this->verbose) {
                        $results['messages'][] = "OK: {$table_name}.{$expected_pkey} already has primary key constraint";
                    }
                }
                
            } catch (Exception $e) {
                $results['errors'][] = "Error processing {$class}: " . $e->getMessage();
                $results['success'] = false;
            }
        }
        
        return $results;
    }
    
    /**
     * Add primary key constraint to a table
     * Used by both table creation and primary key fixing
     * 
     * Error handling: Returns true/false to let callers decide how to handle failures
     * - Table creation will throw exception on false (critical failure)
     * - Batch fixing will log error and continue (non-critical)
     * 
     * @param string $table_name Table name
     * @param string $pkey_column Primary key column name
     * @param PDO $dblink Database connection
     * @return bool Success status
     */
    private function addPrimaryKeyConstraint($table_name, $pkey_column, $dblink) {
        try {
            // FIRST: Check for duplicate values that would prevent primary key creation
            $duplicate_info = $this->checkForDuplicatePrimaryKeyValues($table_name, $pkey_column, $dblink);
            
            if ($duplicate_info['has_duplicates']) {
                // Log duplicates as clear warnings but don't fail
                echo "<br>⚠️  PRIMARY KEY CONSTRAINT WARNING<br>";
                echo "═══════════════════════════════════════════════════════════════════════════════<br>";
                echo "Table: {$table_name}<br>";
                echo "Column: {$pkey_column}<br>";
                echo "Issue: Duplicate values found - cannot create primary key constraint<br>";
                echo "<br>";
                echo "Duplicate values detected:<br>";
                
                foreach ($duplicate_info['duplicates'] as $value => $count) {
                    if ($value === '') {
                        echo "  • NULL values: {$count} occurrences<br>";
                    } elseif ($value === 'UNKNOWN') {
                        echo "  • {$duplicate_info['duplicates'][$value]}<br>";
                    } else {
                        echo "  • Value '{$value}': {$count} occurrences<br>";
                    }
                }
                
                echo "<br>";
                echo "Action: Primary key constraint creation skipped<br>";
                echo "Impact: Database will continue to function normally<br>";
                echo "═══════════════════════════════════════════════════════════════════════════════<br>";
                echo "<br>";
                
                // Don't attempt to add the constraint, but return true to not fail the overall operation
                // Primary key constraints are not essential for database functioning
                return true; // Return success to continue processing
            }
            
            // Check if any primary key already exists
            $check_sql = "SELECT constraint_name FROM information_schema.table_constraints 
                          WHERE table_name = ? 
                            AND table_schema = 'public' 
                            AND constraint_type = 'PRIMARY KEY'";
            
            $q = $dblink->prepare($check_sql);
            $q->execute([$table_name]);
            $existing_pk = $q->fetch(PDO::FETCH_ASSOC);
            
            if ($existing_pk) {
                // Drop existing primary key first (for fix scenarios)
                // Note: Can't parameterize constraint name in DROP CONSTRAINT
                $constraint_name = $existing_pk['constraint_name'];
                // Validate constraint name to prevent injection (block obviously malicious characters)
                // PostgreSQL allows many characters in quoted identifiers, but block dangerous ones
                // Block: semicolon, quotes, comment markers, null bytes
                if (preg_match('/[;\'"\\\\-]{2}|\/\*|\*\/|\\x00/', $constraint_name)) {
                    throw new PDOException("Invalid constraint name - contains potentially malicious characters");
                }
                $drop_sql = "ALTER TABLE {$table_name} DROP CONSTRAINT {$constraint_name}";
                $q = $dblink->prepare($drop_sql);
                $q->execute();
            }
            
            // Add primary key constraint using consistent naming pattern
            $constraint_name = $table_name . '_pkey';
            // Note: PostgreSQL doesn't allow parameterized identifiers in DDL statements
            // Validate table/column names (block obviously malicious characters)
            // These come from model class definitions (not user input) but validate anyway
            // Block: semicolon, quotes, comment markers, null bytes
            if (preg_match('/[;\'"\\\\-]{2}|\/\*|\*\/|\\x00/', $table_name) || 
                preg_match('/[;\'"\\\\-]{2}|\/\*|\*\/|\\x00/', $pkey_column)) {
                throw new PDOException("Invalid table or column name - contains potentially malicious characters");
            }
            
            $sql = 'ALTER TABLE "public"."' . $table_name . '" ADD CONSTRAINT "' . $constraint_name . '" PRIMARY KEY ("' . $pkey_column . '");';
            
            if ($this->verbose) {
                echo "Executing: {$sql}<br>\n";
            }
            
            $q = $dblink->prepare($sql);
            $q->execute();
            
            return true;
            
        } catch (PDOException $e) {
            // Always log error details for primary key failures (critical for debugging)
            echo "ERROR: Failed to add primary key constraint to {$table_name}.{$pkey_column}<br>\n";
            echo "Database error: " . $e->getMessage() . "<br>\n";
            
            // Show SQL in verbose mode
            if ($this->verbose && isset($sql)) {
                echo "SQL attempted: {$sql}<br>\n";
                echo "Error code: " . $e->getCode() . "<br>\n";
            }
            return false;
        }
    }
    
    /**
     * Check for duplicate values in a primary key column
     * 
     * @param string $table_name Table name
     * @param string $pkey_column Primary key column name
     * @param PDO $dblink Database connection
     * @return array Array with 'has_duplicates' boolean and 'duplicates' array of value => count
     */
    private function checkForDuplicatePrimaryKeyValues($table_name, $pkey_column, $dblink) {
        $result = [
            'has_duplicates' => false,
            'duplicates' => []
        ];
        
        try {
            // Check for duplicate values (including NULLs)
            // Use COALESCE to convert NULL to empty string for consistent handling
            $sql = "SELECT COALESCE(CAST({$pkey_column} AS TEXT), '') as value, COUNT(*) as count 
                    FROM {$table_name} 
                    GROUP BY COALESCE(CAST({$pkey_column} AS TEXT), '') 
                    HAVING COUNT(*) > 1 
                    ORDER BY COUNT(*) DESC, value";
            
            if ($this->verbose) {
                echo "Checking for duplicate values in {$table_name}.{$pkey_column}<br>\n";
            }
            
            $q = $dblink->prepare($sql);
            $q->execute();
            
            $duplicates = $q->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($duplicates)) {
                $result['has_duplicates'] = true;
                foreach ($duplicates as $duplicate) {
                    $result['duplicates'][$duplicate['value']] = $duplicate['count'];
                }
            }
            
            if ($this->verbose && !$result['has_duplicates']) {
                echo "No duplicate values found in {$table_name}.{$pkey_column}<br>\n";
            }
            
        } catch (PDOException $e) {
            if ($this->verbose) {
                echo "Error checking for duplicates in {$table_name}.{$pkey_column}: " . $e->getMessage() . "<br>\n";
            }
            // If we can't check for duplicates, assume there might be some to be safe
            $result['has_duplicates'] = true;
            $result['duplicates']['UNKNOWN'] = 'Error checking for duplicates';
        }
        
        return $result;
    }
    
    /**
     * Clean up NULL values in primary key columns before adding constraints
     * 
     * @param string $class Model class name
     * @param string $table_name Table name
     * @param string $pkey_column Primary key column name
     * @param PDO $dblink Database connection
     * @param array $results Results array to update with messages
     * @return bool True if cleanup successful or not needed, false if failed
     */
    private function cleanupNullPrimaryKeyValues($class, $table_name, $pkey_column, $dblink, &$results) {
        try {
            // First, check if there are any NULL values
            $check_sql = "SELECT COUNT(*) as null_count FROM {$table_name} WHERE {$pkey_column} IS NULL";
            $q = $dblink->prepare($check_sql);
            $q->execute();
            $result = $q->fetch(PDO::FETCH_ASSOC);
            $null_count = $result['null_count'];
            
            if ($null_count == 0) {
                // No NULL values, nothing to clean up
                return true;
            }
            
            if ($this->verbose) {
                echo "Found {$null_count} NULL values in {$table_name}.{$pkey_column}<br>\n";
            }
            
            // Check if this is a serial column that should auto-increment
            $field_specs = $class::$field_specifications ?? [];
            $is_serial = isset($field_specs[$pkey_column]['serial']) && $field_specs[$pkey_column]['serial'];
            
            if ($is_serial) {
                // For serial columns, update NULLs to use the next sequence value
                if ($this->fixSerialColumnNulls($table_name, $pkey_column, $dblink, $null_count)) {
                    $results['messages'][] = "CLEANED: Fixed {$null_count} NULL values in serial column {$table_name}.{$pkey_column}";
                    return true;
                } else {
                    return false;
                }
            } else {
                // For non-serial columns, we can't safely fix NULL values automatically
                // This would require manual intervention to determine appropriate values
                $results['warnings'][] = "Cannot automatically fix {$null_count} NULL values in non-serial column {$table_name}.{$pkey_column}";
                return false;
            }
            
        } catch (PDOException $e) {
            if ($this->verbose) {
                echo "Error checking for NULL values in {$table_name}.{$pkey_column}: " . $e->getMessage() . "<br>\n";
            }
            return false;
        }
    }
    
    /**
     * Fix NULL values in serial/auto-increment columns
     */
    private function fixSerialColumnNulls($table_name, $pkey_column, $dblink, $null_count) {
        try {
            // Get the sequence name (PostgreSQL naming convention)
            $sequence_name = $table_name . '_' . $pkey_column . '_seq';
            
            // First, check if sequence exists
            $seq_check_sql = "SELECT 1 FROM information_schema.sequences WHERE sequence_name = ? AND sequence_schema = 'public'";
            $q = $dblink->prepare($seq_check_sql);
            $q->execute([$sequence_name]);
            
            if ($q->rowCount() == 0) {
                if ($this->verbose) {
                    echo "Sequence {$sequence_name} not found - cannot fix NULL values<br>\n";
                }
                return false;
            }
            
            // Update NULL values to use nextval from the sequence
            $update_sql = "UPDATE {$table_name} SET {$pkey_column} = nextval('{$sequence_name}') WHERE {$pkey_column} IS NULL";
            
            if ($this->verbose) {
                echo "Executing: {$update_sql}<br>\n";
            }
            
            $q = $dblink->prepare($update_sql);
            $q->execute();
            
            $updated_rows = $q->rowCount();
            if ($this->verbose) {
                echo "Updated {$updated_rows} NULL values with sequence values<br>\n";
            }
            
            return $updated_rows == $null_count;
            
        } catch (PDOException $e) {
            if ($this->verbose) {
                echo "Error fixing serial column NULLs: " . $e->getMessage() . "<br>\n";
            }
            return false;
        }
    }
    
    /**
     * Generate optimal constraint name using hybrid approach
     * Handles PostgreSQL's 63-character identifier limit
     * 
     * @param string $table_name Table name
     * @param array $columns Array of column names
     * @param string $type Constraint type ('unique', 'pk', etc.)
     * @return string Optimized constraint name
     */
    private function generateOptimalConstraintName($table_name, $columns, $type = 'unique') {
        // Original name for comparison
        $original_name = $table_name . '_' . implode('_', $columns) . '_' . $type;
        
        // Try abbreviated names first
        $abbreviated = $this->abbreviateConstraintName($table_name, $columns, $type);
        if (strlen($abbreviated) <= 63) {
            if ($this->verbose && $abbreviated !== $original_name) {
                echo "Constraint name abbreviated: $original_name -> $abbreviated<br>\n";
            }
            return $abbreviated;
        }
        
        // Try positional naming for multi-column constraints
        if (count($columns) > 2) {
            $positional = $this->generateCompositeConstraintName($table_name, $columns, $type);
            if (strlen($positional) <= 63) {
                if ($this->verbose) {
                    echo "Constraint name using positional: $original_name -> $positional<br>\n";
                }
                return $positional;
            }
        }
        
        // Final fallback: hash-based naming
        $hashed = $this->generateHashBasedConstraintName($table_name, $columns, $type);
        if ($this->verbose) {
            echo "Constraint name using hash: $original_name -> $hashed<br>\n";
        }
        return $hashed;
    }
    
    /**
     * Create abbreviated constraint name by shortening common column prefixes
     */
    private function abbreviateConstraintName($table_name, $columns, $type = 'unique') {
        $abbreviations = [
            // Common patterns in the codebase
            'sva_svy_survey_id' => 'svy_id',
            'sva_qst_question_id' => 'qst_id',
            'sva_usr_user_id' => 'usr_id',
            'ewl_evt_event_id' => 'evt_id',
            'grm_grp_group_id' => 'grp_id',
            'grm_foreign_key_id' => 'fk_id',
            // Add more mappings as needed based on actual column names
            '_id' => '_id',  // Keep _id suffix as is
        ];
        
        $short_columns = array_map(function($col) use ($abbreviations) {
            foreach ($abbreviations as $long => $short) {
                if ($col === $long) {
                    return $short;
                }
            }
            // Try to shorten by removing table prefix if it matches
            if (strpos($col, '_') !== false) {
                $parts = explode('_', $col);
                if (count($parts) >= 3) {
                    // Remove first part if it's likely a table prefix
                    return implode('_', array_slice($parts, 1));
                }
            }
            return $col;
        }, $columns);
        
        return $table_name . '_' . implode('_', $short_columns) . '_' . $type;
    }
    
    /**
     * Generate positional constraint name for multi-column constraints
     */
    private function generateCompositeConstraintName($table_name, $columns, $type = 'unique') {
        if (count($columns) > 2) {
            // Use positional naming: table_col1_col2_3col_unique
            $first_two = array_slice($columns, 0, 2);
            $remaining_count = count($columns) - 2;
            $short_name = $table_name . '_' . implode('_', $first_two) . '_' . $remaining_count . 'col_' . $type;
            
            if (strlen($short_name) <= 63) {
                return $short_name;
            }
        }
        
        // If still too long, continue to hash-based naming
        return $this->generateHashBasedConstraintName($table_name, $columns, $type);
    }
    
    /**
     * Generate hash-based constraint name for very long constraints
     * Always starts with readable table name
     */
    private function generateHashBasedConstraintName($table_name, $columns, $type = 'unique') {
        $full_name = $table_name . '_' . implode('_', $columns) . '_' . $type;
        $hash = substr(md5($full_name), 0, 8);
        
        // Calculate space available for hash after table name and type
        $base_length = strlen($table_name) + 1 + strlen($type) + 1; // table + _ + type + _
        $available_space = 63 - $base_length;
        
        if ($available_space >= 8) {
            // Standard format: table_hash_type
            return $table_name . '_' . $hash . '_' . $type;
        } else {
            // Very long table name - use shorter hash
            $short_hash = substr($hash, 0, max(4, $available_space));
            return $table_name . '_' . $short_hash . '_' . $type;
        }
    }
    
    /**
     * Find existing constraint by column structure rather than name
     * This helps identify constraints even when names were truncated
     */
    private function findExistingConstraintByColumns($table_name, $columns, $dblink, $type = 'UNIQUE') {
        $sql = "SELECT c.conname, 
                       array_agg(a.attname ORDER BY a.attnum) as column_names
                FROM pg_constraint c
                JOIN pg_class t ON c.conrelid = t.oid
                JOIN pg_namespace n ON t.relnamespace = n.oid
                JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(c.conkey)
                WHERE t.relname = ? 
                  AND n.nspname = 'public'
                  AND c.contype = ?
                  AND array_length(c.conkey, 1) = ?
                GROUP BY c.conname, c.conkey";
        
        try {
            $constraint_type = ($type === 'UNIQUE') ? 'u' : 'p';
            $q = $dblink->prepare($sql);
            $q->execute([$table_name, $constraint_type, count($columns)]);
            
            // Sort the expected columns for comparison
            $expected_columns = $columns;
            sort($expected_columns);
            
            while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
                // Parse PostgreSQL array format: {col1,col2,col3}
                $constraint_columns = trim($row['column_names'], '{}');
                $constraint_columns = explode(',', $constraint_columns);
                
                // Clean up column names (remove quotes if any)
                $constraint_columns = array_map(function($col) {
                    return trim($col, '"');
                }, $constraint_columns);
                
                sort($constraint_columns);
                
                // Check if columns match exactly
                if ($constraint_columns === $expected_columns) {
                    if ($this->verbose) {
                        echo "Found existing constraint by columns: " . $row['conname'] . " (columns: " . implode(', ', $constraint_columns) . ")<br>\n";
                    }
                    return $row['conname'];
                }
            }
            
            if ($this->verbose) {
                echo "No existing constraint found for columns: " . implode(', ', $expected_columns) . "<br>\n";
                // Debug: show what constraints DO exist for this table
                echo "DEBUG: Existing constraints for {$table_name}:<br>\n";
                $debug_sql = "SELECT c.conname, c.contype, array_agg(a.attname ORDER BY a.attnum) as column_names
                             FROM pg_constraint c
                             JOIN pg_class t ON c.conrelid = t.oid
                             JOIN pg_namespace n ON t.relnamespace = n.oid
                             LEFT JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(c.conkey)
                             WHERE t.relname = ? AND n.nspname = 'public'
                             GROUP BY c.conname, c.contype, c.conkey";
                try {
                    $debug_q = $dblink->prepare($debug_sql);
                    $debug_q->execute([$table_name]);
                    while ($debug_row = $debug_q->fetch(PDO::FETCH_ASSOC)) {
                        $debug_columns = trim($debug_row['column_names'], '{}');
                        echo "  - {$debug_row['conname']} ({$debug_row['contype']}): {$debug_columns}<br>\n";
                    }
                } catch (PDOException $e) {
                    echo "  Error getting debug info: " . $e->getMessage() . "<br>\n";
                }
            }
            return null;
            
        } catch (PDOException $e) {
            if ($this->verbose) {
                echo "Error finding constraint by columns: " . $e->getMessage() . "<br>\n";
            }
            return null;
        }
    }
}

?>