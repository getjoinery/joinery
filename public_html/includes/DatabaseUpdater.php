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
            
            // Add primary key constraint
            $sql = 'ALTER TABLE "public"."' . $table_name . '" ADD CONSTRAINT "' . $table_name . '_pkey" PRIMARY KEY ("' . $pkey_column . '");';
            $q = $dblink->prepare($sql);
            $q->execute();
            
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
        
        // Handle column modifications if upgrade mode is enabled
        if ($this->upgrade) {
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
            $results['warnings'][] = "Column {$table_name}.{$field_name}: " . strip_tags($translation_output);
            return; // Skip this column modification
        }
        
        // Check if we need to modify the column type
        $needs_type_change = false;
        $needs_length_change = false;
        
        if ($live_data_type != $field_without_length) {
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
                $results['warnings'][] = "Could not modify column {$table_name}.{$field_name}: " . $e->getMessage();
            }
        }
        
        // Handle nullable constraint changes
        $spec_nullable = !isset($field_specs['is_nullable']) || $field_specs['is_nullable'];
        $live_nullable = ($live_column_info['is_nullable'] == 'YES');
        
        if ($spec_nullable != $live_nullable) {
            if ($spec_nullable) {
                // Remove NOT NULL constraint
                $sql = "ALTER TABLE {$table_name} ALTER COLUMN {$field_name} DROP NOT NULL";
            } else {
                // Add NOT NULL constraint
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
     */
    private function processColumnCleanup($table_name, $field_specifications, $live_columns, $dblink, &$results) {
        foreach ($live_columns as $column_name => $column_info) {
            if (!isset($field_specifications[$column_name])) {
                // Column exists in database but not in specifications
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
     */
    private function addMissingColumn($table_name, $field_name, $field_specs, $dblink, &$results) {
        try {
            $sql = 'ALTER TABLE "public"."' . $table_name . '" ADD COLUMN "' . $field_name . '" ' . $field_specs['type'];
            
            if (isset($field_specs['is_nullable']) && !$field_specs['is_nullable']) {
                $sql .= ' NOT NULL ';
            }
            
            $q = $dblink->prepare($sql);
            $q->execute();
            
            $results['columns_added'][] = "{$table_name}.{$field_name}";
            $results['messages'][] = "Added column: {$table_name}.{$field_name}";
            
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
        
        // Check data type mismatch
        if ($live_data_type != $field_without_length) {
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
                $constraint_name = $table . '_' . $field . '_unique';
                if (!$this->constraintExists($constraint_name, $dblink)) {
                    $sql = "ALTER TABLE $table ADD CONSTRAINT $constraint_name UNIQUE ($field)";
                    if ($this->executeConstraintSql($sql, $dblink)) {
                        $results['constraints_added'][] = $constraint_name;
                        $results['messages'][] = "Added unique constraint: $constraint_name";
                    } else {
                        $results['errors'][] = "Failed to add constraint: $constraint_name";
                    }
                } elseif ($this->verbose) {
                    $results['messages'][] = "Unique constraint already exists: $constraint_name";
                }
            }
            
            // Composite unique constraints
            if (isset($spec['unique_with'])) {
                $fields = array_merge(array($field), $spec['unique_with']);
                $constraint_name = $table . '_' . implode('_', $fields) . '_unique';
                if (!$this->constraintExists($constraint_name, $dblink)) {
                    $columns = implode(', ', $fields);
                    $sql = "ALTER TABLE $table ADD CONSTRAINT $constraint_name UNIQUE ($columns)";
                    if ($this->executeConstraintSql($sql, $dblink)) {
                        $results['constraints_added'][] = $constraint_name;
                        $results['messages'][] = "Added composite unique constraint: $constraint_name";
                    } else {
                        $results['errors'][] = "Failed to add constraint: $constraint_name";
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
     * Execute constraint SQL with error handling
     */
    private function executeConstraintSql($sql, $dblink) {
        try {
            if ($this->verbose) {
                echo "Executing: $sql\n";
            }
            $q = $dblink->prepare($sql);
            $q->execute();
            return true;
        } catch(PDOException $e) {
            if ($this->verbose) {
                echo "Error: " . $e->getMessage() . "\n";
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
     * Get expected unique constraints from field_specifications
     */
    private function getExpectedUniqueConstraints($class) {
        $constraints = array();
        $table = $class::$tablename;
        
        if (isset($class::$field_specifications)) {
            foreach ($class::$field_specifications as $field => $spec) {
                if (isset($spec['unique']) && $spec['unique']) {
                    $constraints[] = $table . '_' . $field . '_unique';
                }
                
                if (isset($spec['unique_with'])) {
                    $fields = array_merge(array($field), $spec['unique_with']);
                    $constraints[] = $table . '_' . implode('_', $fields) . '_unique';
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
}

?>