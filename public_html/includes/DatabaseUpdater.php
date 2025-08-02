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
            'errors' => [],
            'messages' => []
        ];
        
        try {
            // Load model classes
            $classes = LibraryFunctions::discover_model_classes(array_merge([
                'require_tablename' => true,
                'require_field_specifications' => true,
                'verbose' => $this->verbose
            ], $options));
            
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
     * Add missing columns to an existing table
     * 
     * @param string $class Model class name
     * @param array $tables_and_columns Current database structure
     * @param PDO $dblink Database connection
     * @return array Results
     */
    private function addMissingColumns($class, $tables_and_columns, $dblink) {
        $results = [
            'columns_added' => [],
            'errors' => [],
            'messages' => []
        ];
        
        $table_name = $class::$tablename;
        $live_table_columns = $tables_and_columns[$table_name] ?? [];
        
        if (empty($live_table_columns)) {
            // Table doesn't exist, skip column checking
            return $results;
        }
        
        try {
            // Get current column information
            $sql = "SELECT column_name, data_type, character_maximum_length, is_nullable
                    FROM information_schema.columns
                    WHERE table_name = ?";
            $q = $dblink->prepare($sql);
            $q->execute([$table_name]);
            $existing_columns = $q->fetchAll(PDO::FETCH_ASSOC);
            
            $existing_column_names = array_column($existing_columns, 'column_name');
            
            // Check for missing columns
            foreach ($class::$field_specifications as $field_name => $field_specs) {
                if (!in_array($field_name, $existing_column_names)) {
                    // Add missing column
                    $sql = 'ALTER TABLE "public"."' . $table_name . '" ADD COLUMN "' . $field_name . '" ' . $field_specs['type'];
                    
                    if (isset($field_specs['is_nullable']) && !$field_specs['is_nullable']) {
                        $sql .= ' NOT NULL ';
                    }
                    
                    $q = $dblink->prepare($sql);
                    $q->execute();
                    
                    $results['columns_added'][] = "{$table_name}.{$field_name}";
                    $results['messages'][] = "Added column: {$table_name}.{$field_name}";
                }
            }
            
        } catch (PDOException $e) {
            $results['errors'][] = "Error adding columns to {$table_name}: " . $e->getMessage();
        }
        
        return $results;
    }
    
    /**
     * Merge results from multiple operations
     * 
     * @param array $target Target results array
     * @param array $source Source results array
     * @return array Merged results
     */
    private function mergeResults($target, $source) {
        foreach (['tables_created', 'columns_added', 'errors', 'messages'] as $key) {
            if (isset($source[$key])) {
                $target[$key] = array_merge($target[$key] ?? [], $source[$key]);
            }
        }
        return $target;
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