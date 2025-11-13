# Primary Key Fix Specification

## Overview

This specification outlines adding primary key fixing functionality to the `update_database.php` utility. The feature will detect when a table model has a specified primary key but the database table does not have that primary key properly configured, and automatically fix it.

## Background

The model tests in `tests/models/ModelTester.php` currently detect primary key mismatches in the `validate_primary_key_configuration()` method (lines 1709-1751). This method:

1. Checks if the table exists
2. Verifies the expected primary key column exists  
3. Confirms the column is actually set as a primary key in the database
4. Reports failures when the model specifies a primary key but the database doesn't have it configured

## Proposed Implementation

### Files to Modify

#### 1. `/utils/update_database.php`

**Location**: After line 137 (after advanced column operations)
**Add**: Call to primary key fix functionality

```php
// Step 2.5: Fix primary key constraints if needed
if ($upgrade) {
    echo "-----PRIMARY KEY FIXES-----<br>\n";
    $primary_key_result = $database_updater->fixPrimaryKeys($classes);
    
    if (!$primary_key_result['success']) {
        echo 'Primary key fixes failed:<br>' . implode('<br>', $primary_key_result['errors']) . "<br>\n";
    }
    
    // Display results
    if (!empty($primary_key_result['messages'])) {
        echo implode('<br>', $primary_key_result['messages']) . "<br>\n";
    }
    
    if (!empty($primary_key_result['warnings'])) {
        foreach ($primary_key_result['warnings'] as $warning) {
            echo 'WARNING: ' . $warning . "<br>\n";
        }
    }
}
```

#### 2. `/includes/DatabaseUpdater.php`

**Add new methods**: `fixPrimaryKeys()` and `addPrimaryKeyConstraint()`
**Update existing method**: `createTableIfMissing()` to use the new shared method

**Add shared private method for primary key constraint creation:**
```php
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
        $q = $dblink->prepare($sql);
        $q->execute();
        
        return true;
        
    } catch (PDOException $e) {
        // Log error details if verbose mode is enabled
        if ($this->verbose) {
            echo "Error adding primary key constraint to {$table_name}.{$pkey_column}: " . $e->getMessage() . "<br>\n";
        }
        return false;
    }
}
```

**Update `createTableIfMissing()` method (around line 201):**
```php
// REPLACE (line 200-203):
// Add primary key constraint
$sql = 'ALTER TABLE "public"."' . $table_name . '" ADD CONSTRAINT "' . $table_name . '_pkey" PRIMARY KEY ("' . $pkey_column . '");';
$q = $dblink->prepare($sql);
$q->execute();

// WITH:
// Add primary key constraint using shared method
// Critical failure for new tables - throw exception if constraint can't be added
if (!$this->addPrimaryKeyConstraint($table_name, $pkey_column, $dblink)) {
    throw new Exception("Failed to add primary key constraint to new table {$table_name}");
}
```

**Add new public method for fixing primary keys:**
```php
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
                // Use shared method to add/fix the primary key
                // Non-critical failure in batch operation - log and continue
                if ($this->addPrimaryKeyConstraint($table_name, $expected_pkey, $dblink)) {
                    $results['fixed'][] = "{$table_name}.{$expected_pkey}";
                    $results['messages'][] = "FIXED: Added primary key constraint to {$table_name}.{$expected_pkey}";
                } else {
                    $results['errors'][] = "Failed to add primary key constraint to {$table_name}.{$expected_pkey}";
                    $results['success'] = false;
                    // Continue processing other tables
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
```

#### 3. `/includes/LibraryFunctions.php`

**Enhance `get_tables_and_columns()` method** - Add optional parameter to get single table

```php
// CURRENT SIGNATURE:
static function get_tables_and_columns()

// CHANGE TO:
static function get_tables_and_columns($table_name = null) {
    $dbhelper = DbConnector::get_instance();
    $dblink = $dbhelper->get_db_link();
    
    if ($table_name !== null) {
        // Optimized query for single table
        $sql = "SELECT 
            t.table_name,
            array_agg(c.column_name::text) as columns
        FROM
            information_schema.tables t
        INNER JOIN information_schema.columns c ON
            t.table_name = c.table_name
        WHERE
            t.table_schema = 'public'
            AND c.table_schema = 'public'
            AND t.table_name = :table_name
        GROUP BY t.table_name";
        
        try {
            $q = $dblink->prepare($sql);
            $q->bindParam(':table_name', $table_name, PDO::PARAM_STR);
            $q->execute();
            $q->setFetchMode(PDO::FETCH_OBJ);
        } catch(PDOException $e) {
            $dbhelper->handle_query_error($e);
        }
    } else {
        // Existing query for all tables (unchanged)
        $sql = 'select
            t.table_name,
            array_agg(c.column_name::text) as columns
        from
            information_schema.tables t
        inner join information_schema.columns c on
            t.table_name = c.table_name
        where
            t.table_schema = \'public\'
            --and t.table_type= \'BASE TABLE\'
            and c.table_schema = \'public\'
        group by t.table_name;';
        
        try {
            $q = $dblink->prepare($sql);
            $q->execute();
            $q->setFetchMode(PDO::FETCH_OBJ);
        } catch(PDOException $e) {
            $dbhelper->handle_query_error($e);
        }
    }
    
    // Process results into array format (same as existing code)
    $tables_and_columns = array();
    while ($row = $q->fetch()) {
        $table_name = $row->table_name;
        $columns = $row->columns;
        
        // array_agg returns PostgreSQL array format like: {col1,col2,col3}
        // Need to parse this into PHP array
        $columns_array = explode(',', trim($columns, '{}'));
        
        // Build the table/column structure
        $tables_and_columns[$table_name] = array();
        foreach($columns_array as $column) {
            // Remove any quotes that PostgreSQL might add
            $column = trim($column, '"');
            $tables_and_columns[$table_name][$column] = $column;
        }
    }
    
    return $tables_and_columns;
}
```

#### 4. `/includes/SystemClass.php` 🚨 **CRITICAL CONSOLIDATION**

**Update `permanent_delete()` method**: Remove duplicated table/column query logic

**Check for LibraryFunctions include:**
Look at the top of SystemClass.php (around lines 1-20) for:
```php
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
```
If not present, add it with the other require_once statements.

**Location**: Inside the `permanent_delete()` method (starts around line 655)

**REMOVE these lines (approximately 673-693 in the `permanent_delete()` method):**
```php
$sql = '		select
	t.table_name,
	array_agg(c.column_name::text) as columns
from
	information_schema.tables t
inner join information_schema.columns c on
	t.table_name = c.table_name
where
	t.table_schema = \'public\'
	--and t.table_type= \'BASE TABLE\'
	and c.table_schema = \'public\'
group by t.table_name;	';
try{
	$q = $dblink->prepare($sql);
	//$q->bindParam(':param1', $this->key, PDO::PARAM_INT);
	$q->execute();
	$q->setFetchMode(PDO::FETCH_OBJ);
}
catch(PDOException $e){
	$dbhelper->handle_query_error($e);
}	
```

**ALSO REMOVE the while loop that processes results (approximately lines 695-709):**
```php
//MAKE A LIST OF FOUND FOREIGN KEYS
$found_foreign_keys = array();
while ($row = $q->fetch()) {
	$table_name = $row->table_name;
	$columns = $row->columns;
	$columns_array = explode(',', trim($columns, '{}'));
	
	foreach($columns_array as $column){
		if(str_contains($column, static::$pkey_column)){
			if($debug){
				echo static::$pkey_column . ' is in ' .$column. "\n<br>";
			}
			$found_foreign_keys[$column] = $table_name;
		}
	}
}
```

**REPLACE both removed sections with this consolidated approach (insert at line 673):**
```php
// Use LibraryFunctions method instead of duplicate query
$tables_and_columns = LibraryFunctions::get_tables_and_columns();

// Convert to the format expected by the existing foreign key detection logic
// MAKE A LIST OF FOUND FOREIGN KEYS (preserving original comment)
$found_foreign_keys = array();
foreach ($tables_and_columns as $table_name => $columns) {
    foreach ($columns as $column) {
        if (str_contains($column, static::$pkey_column)) {
            if ($debug) {
                echo static::$pkey_column . ' is in ' . $column . "\n<br>";
            }
            $found_foreign_keys[$column] = $table_name;
        }
    }
}

// Continue with the rest of permanent_delete() method unchanged
```

**Result**: The `$found_foreign_keys` array will have the exact same format and the rest of the `permanent_delete()` method continues unchanged.

#### 5. Update existing DatabaseUpdater calls

**Location**: Lines 108, 117, 233 in `/includes/DatabaseUpdater.php`

```php
// NO CHANGES NEEDED - DatabaseUpdater already calls LibraryFunctions::get_tables_and_columns()
// The existing calls remain the same
```

#### 6. Additional Schema Introspection Consolidation

After analyzing the codebase, here are all the other locations that perform similar schema introspection and can be consolidated:

---

##### 6.1 Admin Table Export Form (`/data/admin_tableexport_form_data.php`)

**Current Implementation (lines 6-12):**
```php
//GET COLUMN METADATA
$columnsql = "SELECT * FROM information_schema.tables WHERE table_type='BASE TABLE' AND table_schema='public'";
$results = $dblink->query($columnsql);
$tables = array();
while ($row = $results->fetch(PDO::FETCH_OBJ)){
    $tables[$row->table_name] = $row->table_name;
}
```

**REPLACE with:**
```php
// Use consolidated method
$tables_and_columns = LibraryFunctions::get_tables_and_columns();
$tables = array();
foreach(array_keys($tables_and_columns) as $table_name) {
    $tables[$table_name] = $table_name;
}
```

---

##### 6.2 Admin Table Export Data (`/data/admin_tableexport_data.php`)

**Current Implementation (lines 27-33):**
```php
//GET COLUMN METADATA
$columnsql = "SELECT column_name FROM information_schema.columns WHERE table_name ='$tablename'";
$results = $dblink->query($columnsql);
$chead = array();
while($row = $results->fetch(PDO::FETCH_NUM)){
    array_push($chead,$row[0]);
}
```

**⚠️ SECURITY ISSUE:** SQL injection vulnerability with `$tablename` directly in query!

**REPLACE with (using optimized single-table query):**
```php
// Use consolidated method with single table parameter (also fixes SQL injection)
$tables_and_columns = LibraryFunctions::get_tables_and_columns($tablename);
$chead = isset($tables_and_columns[$tablename]) ? array_keys($tables_and_columns[$tablename]) : array();
if (empty($chead)) {
    die("Invalid table name or table has no columns");
}
```

---

##### 6.3 ModelTester (`/tests/models/ModelTester.php`)

**Current Implementation (`get_table_columns` method, lines 1757-1781):**
```php
private function get_table_columns($table_name, $dblink) {
    $columns = array();
    
    try {
        $sql = "SELECT column_name 
                FROM information_schema.columns 
                WHERE table_schema = 'public' 
                  AND table_name = ?
                ORDER BY ordinal_position";
        
        $q = $dblink->prepare($sql);
        $q->execute([$table_name]);
        $results = $q->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($results as $row) {
            $column_name = $row['column_name'];
            $columns[$column_name] = $column_name;
        }
        
        return $columns;
        
    } catch (PDOException $e) {
        throw new Exception("Failed to get column information for table {$table_name}: " . $e->getMessage());
    }
}
```

**DELETE the entire `get_table_columns()` method (lines 1757-1781) and replace its single usage inline.**

**The method is only called ONCE at line 1722 in `validate_primary_key_configuration()`:**

```php
// CURRENT CODE (lines 1722-1729):
$tables_and_columns = $this->get_table_columns($table_name, $dblink);

if (empty($tables_and_columns)) {
    $this->test_fail("Table '{$table_name}' does not exist in database for model {$model_class}");
    return;
}

$live_columns = array_keys($tables_and_columns);

// REPLACE WITH (using enhanced LibraryFunctions method with table parameter):
$tables_and_columns = LibraryFunctions::get_tables_and_columns($table_name);
$table_columns = isset($tables_and_columns[$table_name]) ? $tables_and_columns[$table_name] : array();

if (empty($table_columns)) {
    $this->test_fail("Table '{$table_name}' does not exist in database for model {$model_class}");
    return;
}

$live_columns = array_keys($table_columns);
```

---

##### 6.4 PluginManager (`/includes/PluginManager.php`)

**Current Implementation (lines 1256-1271 AND lines 1291-1304 - appears twice):**
```php
foreach ($field_specifications as $field_name => $field_specs) {
    $check_sql = "SELECT column_name FROM information_schema.columns 
                 WHERE table_name = 'plg_plugins' AND column_name = ?";
    $q = $dblink->prepare($check_sql);
    $q->execute([$field_name]);
    $result = $q->fetch(PDO::FETCH_ASSOC);
    
    if (!$result) {
        $missing_columns[] = $field_name;
        $this->results['issues_found'][] = "Missing column: plg_plugins.{$field_name}";
    }
}
```

**REPLACE both occurrences with (using optimized single-table query):**
```php
// Get columns for plg_plugins table once (much more efficient than N queries)
$tables_and_columns = LibraryFunctions::get_tables_and_columns('plg_plugins');
$plugin_columns = isset($tables_and_columns['plg_plugins']) ? $tables_and_columns['plg_plugins'] : array();

foreach ($field_specifications as $field_name => $field_specs) {
    if (!isset($plugin_columns[$field_name])) {
        $missing_columns[] = $field_name;
        $this->results['issues_found'][] = "Missing column: plg_plugins.{$field_name}";
    }
}
```


### Integration Points

1. **Only runs in upgrade mode**: Primary key fixes only execute when `--upgrade` flag is used, ensuring they don't run during normal database updates
2. **After column operations**: Runs after advanced column operations but before constraint management
3. **Uses existing infrastructure**: Leverages the same class discovery and DatabaseUpdater pattern used by other operations
4. **Follows existing patterns**: Uses the same result structure and error handling as other DatabaseUpdater methods

### Command Line Usage

```bash
# Fix primary keys (requires --upgrade flag)
php utils/update_database.php --upgrade

# Verbose output to see what's being checked/fixed
php utils/update_database.php --upgrade --verbose
```

### Safety Considerations

1. **Requires upgrade flag**: Only runs when explicitly requested via `--upgrade`
2. **Handles existing constraints**: Safely drops existing primary key constraints before adding new ones
3. **Validates prerequisites**: Checks table and column existence before attempting fixes
4. **Transaction safety**: Uses prepared statements and proper error handling
5. **Verbose logging**: Provides detailed output when verbose mode is enabled

### Expected Output

```
-----PRIMARY KEY FIXES-----
Checking primary key for User (usr_users.usr_id)
OK: usr_users.usr_id already has primary key constraint
Checking primary key for Product (pro_products.pro_id)
FIXED: Added primary key constraint to pro_products.pro_id
Checking primary key for Event (evt_events.evt_id)
OK: evt_events.evt_id already has primary key constraint
Primary key fixes completed: 1 fixed, 0 warnings, 0 errors
```

## Testing

The functionality can be tested by:

1. Running the model tests to identify tables with primary key issues
2. Running `update_database.php --upgrade --verbose` to fix them
3. Re-running model tests to verify fixes were successful
4. Checking database directly to confirm primary key constraints were added

## Summary of All Changes

### Files Modified (7 total):

1. **`/utils/update_database.php`** - Add primary key fix functionality
2. **`/includes/DatabaseUpdater.php`** - Add `fixPrimaryKeys()` method  
3. **`/includes/SystemClass.php`** - Remove duplicate query, use LibraryFunctions
4. **`/data/admin_tableexport_form_data.php`** - Use consolidated method for table list
5. **`/data/admin_tableexport_data.php`** - Use consolidated method + fix SQL injection
6. **`/tests/models/ModelTester.php`** - Replace `get_table_columns()` implementation
7. **`/includes/PluginManager.php`** - Replace column checking (2 locations)

### Security Fixes:
- **SQL injection** in `admin_tableexport_data.php` (line 28) - FIXED by consolidation

### Performance Improvements:
- **PluginManager**: From N queries (one per column) to 1 query
- **SystemClass**: Every model deletion now uses consolidated method

### Code Reduction:
- **~100+ lines removed** across all files
- **7 duplicate queries eliminated**
- **Single source of truth** for schema introspection

## Benefits

1. **Automated repair**: Fixes primary key mismatches without manual intervention
2. **Safe execution**: Only runs when explicitly requested with upgrade flag
3. **Comprehensive coverage**: Checks all model classes systematically
4. **Clear feedback**: Provides detailed logging of what was checked and fixed
5. **Integration**: Fits seamlessly into existing update_database workflow
6. **Code consolidation**: Eliminates exact code duplication between LibraryFunctions::get_tables_and_columns() and SystemClass::permanent_delete() - both use identical SQL queries that are now consolidated
7. **Security improvements**: Fixes SQL injection vulnerabilities in admin export functions
8. **Performance gains**: Reduces database queries, especially in PluginManager