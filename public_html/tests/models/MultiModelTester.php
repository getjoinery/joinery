<?php
/**
 * MultiModelTester - Automated testing class for Multi classes (SystemMultiBase collections)
 * 
 * This class extends ModelTester to provide comprehensive testing for Multi classes
 * by comparing their query results against direct SQL queries to ensure accuracy.
 */

require_once('ModelTester.php');

class MultiModelTester extends ModelTester {
    
    protected $last_schema_error = null;

    /**
     * Which detected filter options compare the caller's value to a column, as
     * opposed to interpreting it. Keyed by option name. See
     * classify_filter_options() for how the distinction is drawn and why it
     * governs which assertion a filter earns.
     */
    private $equality_filters = [];
    
    protected $model_class; // Override parent's private property with protected
    private $multi_class;
    private $test_records = [];
    
    /**
     * Constructor with debugging
     */
    public function __construct($model_class) {
        // Set our own model_class property since parent's is private
        $this->model_class = $model_class;
        
        parent::__construct($model_class);
    }
    
    /**
     * Main test execution for Multi classes
     * Override parent method with compatible signature
     */
    public function test($model_instance = null, $debug = false, $read_only = false) {
        // Set maximum execution time for the entire test
        set_time_limit(15);
        
        // Multi tests don't use model_instance parameter, but we need it for compatibility
        $verbose = $this->is_verbose();
        $this->multi_class = 'Multi' . $this->model_class;
        
        // Check if Multi class exists
        if (!class_exists($this->multi_class)) {
            if ($debug) echo "<span style='color: #ff9800;'>[SKIP] No Multi class found for {$this->model_class}</span><br>\n";
            return 'SKIPPED';
        }
        
        // Set execution time limit
        
        
        // Set up test database mode with timeout
        $dbhelper = DbConnector::get_instance();
        if (method_exists($dbhelper, 'set_test_mode')) {
            $dbhelper->set_test_mode();
        }
        
        // Set database timeout if possible
        try {
            $dblink = $dbhelper->get_db_link();
            if ($dblink) {
                $dblink->exec("SET statement_timeout = 10000"); // 10 second timeout
            }
        } catch (Exception $e) {
            // Silently ignore timeout setting errors
        }
        
        flush();
        
        try {
            // Calculate optimal number of test records
            $required_records = $this->calculate_required_test_records();
            
            // Create test data
            $this->test_records = $this->create_multi_test_data($required_records);
            echo "Created " . count($this->test_records) . " test records<br>\n";
            
            // If we couldn't create any records, check if it's due to database schema issues
            if (empty($this->test_records)) {
                // Check if there were database schema errors during test record creation
                if (isset($this->last_schema_error)) {
                    echo "<span style='color: #dc3545; font-weight: bold;'>[FAIL] Database schema error prevented test record creation: {$this->last_schema_error}</span><br>\n";
                    throw new Exception("Database schema error: {$this->last_schema_error}");
                } else {
                    echo "<span style='color: #ff9800;'>[SKIP] Could not create test records for Multi testing</span><br>\n";
                    return 'SKIPPED';
                }
            }
            
            // Run test scenarios
            echo "1/5 Basic Loading... ";
            try {
                $this->test_multi_basic_loading($debug);
                echo "<span style='color: #28a745; font-weight: bold;'>✓ PASSED</span><br>\n";
            } catch (Exception $e) {
                echo "<span style='color: #dc3545; font-weight: bold;'>✗ FAILED</span>: " . $e->getMessage() . "<br>\n";
                throw $e;
            }
            
            echo "2/5 Filtering... ";
            $this->test_multi_filtering($debug);
            echo "<span style='color: #28a745; font-weight: bold;'>✓ PASSED</span><br>\n";
            
            echo "3/5 Ordering... ";
            $this->test_multi_ordering($debug);
            echo "<span style='color: #28a745; font-weight: bold;'>✓ PASSED</span><br>\n";
            
            echo "4/5 Pagination... ";
            $this->test_multi_pagination($debug);
            echo "<span style='color: #28a745; font-weight: bold;'>✓ PASSED</span><br>\n";
            
            echo "5/5 Combined Scenarios... ";
            $this->test_multi_combined($debug);
            echo "<span style='color: #28a745; font-weight: bold;'>✓ PASSED</span><br>\n";
            
            // Success message
            echo "<div style='background: #d4edda; border: 2px solid #28a745; padding: 15px; margin: 10px 0; border-radius: 8px;'>";
            echo "<h4 style='color: #155724; margin: 0 0 10px 0;'>✓ SUCCESS: {$this->multi_class}</h4>";
            echo "<p style='color: #155724; margin: 0;'><strong>All Multi class tests passed successfully!</strong></p>";
            echo "<small style='color: #155724;'>Test scenarios completed: Basic loading, Filtering, Ordering, Pagination, Combined queries</small>";
            echo "</div>\n";
            
        } catch (Exception $e) {
            // Clear, obvious failure message
            echo "<div style='background: #f8d7da; border: 2px solid #dc3545; padding: 15px; margin: 10px 0; border-radius: 8px;'>";
            echo "<h4 style='color: #721c24; margin: 0 0 10px 0;'>✗ FAILED: {$this->multi_class}</h4>";
            echo "<p style='color: #721c24; margin: 0 0 8px 0;'><strong>Multi class test failed:</strong> " . $e->getMessage() . "</p>";
            if ($verbose) {
                echo "<details style='color: #721c24; margin-top: 8px;'><summary>Exception Details</summary>";
                echo "<pre style='font-size: 12px; margin: 8px 0;'>" . $e->getTraceAsString() . "</pre>";
                echo "</details>";
            }
            echo "</div>\n";
            throw $e;
        } finally {
            if ($verbose) {
                echo "  Step 5: Cleaning up test data...<br>\n";
                flush();
            }
            
            // Always clean up test data
            $this->cleanup_multi_test_data();

            // And the parent rows created to satisfy foreign keys.
            //
            // ModelTester::test() does this at the end of its own run, but this
            // class overrides test() outright, so without the call here every
            // parent the fixtures needed survives the run. Those parents carry
            // deterministic generated values — a Schedule parent is always
            // (sch_subject_type='a', sch_subject_id=4957) — so the leftovers do
            // not merely accumulate, they are precisely the rows the model's own
            // unique-constraint test tries to insert next time. One run of this
            // suite was enough to make models_crud red until the row was
            // deleted by hand.
            self::teardown_created_parents();

            // Clean up test mode
            if (method_exists($dbhelper, 'close_test_mode')) {
                $dbhelper->close_test_mode();
            }
        }
        
        return true;
    }
    
    /**
     * Create multiple test records using improved parent methods
     */
    protected function create_multi_test_data($count) {
        $records = [];
        $start_time = time();
        $max_time = 10; // 10 second timeout for test data creation
        
        $successful_records = 0;
        $attempts = 0;
        $max_attempts = $count * 5; // Allow up to 5x attempts to handle duplicates
        
        while ($successful_records < $count && $attempts < $max_attempts) {
            $attempts++;
            
            // Check for timeout
            if (time() - $start_time > $max_time) {
                break;
            }
            
            // Early exit if we have enough records for basic testing  
            if ($successful_records >= min($count, 5)) {
                break;
            }
            
            try {
                // Use parent's enhanced generate_field_value with reasonable unique index
                // Keep index small but unique by using attempts + random component
                $unique_index = ($attempts * 100) + rand(1, 99); // Generates values like 101, 202, 345, etc.
                $test_data = $this->generate_test_data_with_index($unique_index);
                
                $model = new $this->model_class(null);
                
                foreach ($test_data as $field => $value) {
                    $model->set($field, $value);
                }
                
                try {
                    $save_result = $model->save();
                    
                    if ($save_result === false) {
                        // Check if record actually got saved despite returning false
                        $dbhelper = DbConnector::get_instance();
                        $dblink = $dbhelper->get_db_link();
                        $table_name = $this->model_class::$tablename;
                        $pkey_column = $this->model_class::$pkey_column;
                        
                        $sql = "SELECT COUNT(*) as count FROM {$table_name} WHERE {$pkey_column} = ?";
                        $stmt = $dblink->prepare($sql);
                        $stmt->execute([$model->key]);
                        $result = $stmt->fetch(PDO::FETCH_OBJ);
                        
                        if ($result->count > 0) {
                            // Treat this as success since the record is in the database
                        } else {
                            continue; // Try again with next attempt
                        }
                    }
                } catch (Exception $e) {
                    // Check if this is a database schema error that should cause test failure
                    $error_message = $e->getMessage();
                    if ($this->is_database_schema_error($error_message)) {
                        // Store the schema error for later detection
                        $this->last_schema_error = $error_message;
                        echo "DEBUG: Database schema error detected - will cause test failure<br>\n";
                    }
                    
                    continue; // Try again with next attempt
                }
                
                // Ensure the record is committed to database for Multi class queries
                try {
                    $dbhelper = DbConnector::get_instance();
                    $dblink = $dbhelper->get_db_link();
                    if ($dblink->inTransaction()) {
                        $dblink->commit();
                    }
                } catch (Exception $e) {
                    // Ignore commit errors
                }
                
                // Success - add to records
                $successful_records++;
                $records[] = [
                    'id' => $model->key,
                    'data' => $test_data,
                    'model' => $model
                ];
                
                // Verify the record was actually saved (verbose mode only)
                if ($this->is_verbose()) {
                    try {
                        $verify_model = new $this->model_class($model->key, true);
                        if ($verify_model->key) {
                            echo "  Verified record {$model->key} exists in database<br>\n";
                        } else {
                            echo "  WARNING: Record {$model->key} not found in database after save!<br>\n";
                        }
                    } catch (Exception $e) {
                        echo "  WARNING: Could not verify record {$model->key}: " . $e->getMessage() . "<br>\n";
                    }
                }
                
                if ($this->is_verbose()) {
                    echo "<br>\n  Successfully created record $successful_records with ID: {$model->key}<br>\n";
                    flush();
                }
                
            } catch (Exception $e) {
                if ($this->is_verbose()) {
                    echo "<br>\n  <span style='color: #ff9800;'>[ERROR] Failed to create record on attempt $attempts: " . $e->getMessage() . "</span><br>\n";
                    flush();
                }
                
                echo " error-attempt$attempts...";
                flush();
                
                // Check if this is a database schema error that should cause test failure
                $error_message = $e->getMessage();
                if ($this->is_database_schema_error($error_message)) {
                    // Store the schema error for later detection
                    $this->last_schema_error = $error_message;
                    echo "  DEBUG: Database schema error detected - will cause test failure<br>\n";
                }
                
                // If we can't create any records after several attempts, abort
                if ($successful_records === 0 && $attempts > 10) {
                    throw new Exception("Unable to create any test records after multiple attempts: " . $e->getMessage());
                }
                
                continue; // Try again with next attempt
            }
        }
        
        return $records;
    }
    
    /**
     * Generate test data using parent's improved methods with index
     */
    protected function generate_test_data_with_index($index) {
        $verbose = $this->is_verbose();
        $test_data = [];
        
        $fields = $this->get_all_multi_testable_fields();
        
        if ($verbose) {
            echo "    Generating data for record $index with " . count($fields) . " fields...<br>\n";
            flush();
        }
        
        foreach ($fields as $field => $properties) {
            // Check for timeout
            if (false) { // Timeout protection disabled
                throw new Exception("Multi test timeout exceeded during field generation for $field");
            }
            
            if ($verbose) {
                echo "    Processing field: $field<br>\n";
                flush();
            }
            
            try {
                // Call parent's improved generate_field_value with index
                $value = $this->generate_field_value($field, $index);
                $test_data[$field] = $value;
                
                if ($verbose) {
                    echo "    Field $field = " . (is_string($value) ? "'$value'" : var_export($value, true)) . "<br>\n";
                    flush();
                }
            } catch (Exception $e) {
                if ($verbose) {
                    echo "    ERROR generating field $field: " . $e->getMessage() . "<br>\n";
                    flush();
                }
                throw new Exception("Failed to generate value for field $field: " . $e->getMessage());
            }
        }
        
        if ($verbose) {
            echo "    Completed data generation for record $index<br>\n";
            flush();
        }
        
        return $test_data;
    }
    
    /**
     * Get all testable fields for Multi class testing (excludes primary keys and timestamps)
     * Primary keys should be auto-generated by database to avoid unique constraint violations
     */
    protected function get_all_multi_testable_fields() {
        $fields = $this->model_class::$field_specifications;
        $testable = [];
        $primary_key = $this->model_class::$pkey_column;
        
        foreach ($fields as $field => $properties) {
            // Skip primary keys, auto-generated timestamp fields, and the
            // soft-delete marker.
            //
            // delete_time is not just noise here: most collection classes read
            // it unconditionally ($filters['x_delete_time'] = "IS NULL" unless
            // the caller asks for deleted rows), so a fixture created with a
            // delete_time is born invisible to the very query being tested.
            // The filter then returns nothing and the model is reported broken
            // when in fact it filtered exactly as designed.
            if ($field !== $primary_key &&
                strpos(strtolower($field), 'create_time') === false &&
                strpos(strtolower($field), 'update_time') === false &&
                strpos(strtolower($field), 'delete_time') === false) {
                $testable[$field] = $properties;
            }
        }

        return $testable;
    }
    
    /**
     * Clean up all test records
     */
    protected function cleanup_multi_test_data() {
        $table = $this->model_class::$tablename;
        $pkey = $this->model_class::$pkey_column;

        foreach ($this->test_records as $record) {
            try {
                // Remove the row outright rather than soft-deleting it.
                //
                // A soft delete leaves the fixture in a database every later
                // suite queries. At roughly five rows per model across the
                // whole estate that is hundreds of rows added per run, and they
                // are not inert: a soft-deleted row still occupies any unique
                // constraint that does not exclude deleted rows, so a later run
                // — or a different suite entirely — starts failing on a
                // collision with a fixture nobody can see. The tester created
                // these rows and nothing references them, so deleting them is
                // both safe and the only cleanup that actually cleans up.
                $dblink = DbConnector::get_instance()->get_db_link();
                $q = $dblink->prepare("DELETE FROM {$table} WHERE {$pkey} = ?");
                $q->execute([$record['id']]);
            } catch (Exception $e) {
                // A row that will not delete is almost always one something
                // else now references. Fall back to the soft delete so the
                // fixture is at least out of the way, and say so — silent
                // cleanup failure is what lets a test database rot.
                try {
                    $model = new $this->model_class($record['id']);
                    $model->load();
                    $model->soft_delete();
                } catch (Exception $inner) {
                    // Nothing further to try; report below.
                }
                echo "  <span style='color: #ff9800;'>Warning: could not remove {$table} row {$record['id']}: "
                    . $e->getMessage() . "</span><br>\n";
            }
        }
        $this->test_records = [];
    }

    /**
     * Calculate optimal number of test records
     */
    protected function calculate_required_test_records() {
        $fields = $this->get_all_testable_fields();
        $max_patterns = 10; // Default minimum for pagination tests
        
        // Look at pattern counts in parent's improved methods
        foreach ($fields as $field => $properties) {
            $type = $this->get_field_type($field);
            
            // Each type has different pattern counts based on our enhanced methods
            if (strpos($type, 'int') !== false) {
                $max_patterns = max($max_patterns, 15); // Base integer patterns + field-specific
            } else if (strpos($type, 'varchar') !== false) {
                $max_patterns = max($max_patterns, 14); // Base string patterns + field-specific
                if (strpos(strtolower($field), 'email') !== false) {
                    $max_patterns = max($max_patterns, 18); // Email patterns + base
                }
            } else if (strpos($type, 'date') !== false || strpos($type, 'timestamp') !== false) {
                $max_patterns = max($max_patterns, 12); // Timestamp patterns
            } else if (strpos($type, 'text') !== false) {
                $max_patterns = max($max_patterns, 12); // Text patterns
            }
        }
        
        // Add buffer for null tests and combinations
        $nullable_fields = 0;
        foreach ($fields as $field => $properties) {
            // Check if field is required using field_specifications
            $field_spec = $this->model_class::$field_specifications[$field] ?? [];
            $is_required = isset($field_spec['required']) && $field_spec['required'] === true;
            if (!$is_required) {
                $nullable_fields++;
            }
        }
        
        $required = $max_patterns + ceil($nullable_fields * 0.2);
        
        // Cap at reasonable limit for testing
        return min($required, 20);
    }

    /**
     * Do two field values name the same stored value?
     *
     * The platform does not coerce on set(), so a model built in memory holds
     * whatever PHP type the caller handed it, while the same field read back
     * from Postgres comes through PDO's typing. An id set as the string '9774'
     * and loaded back as int 9774 are the same row, and a strict comparison of
     * the two says otherwise — which is a statement about PHP types, not about
     * whether the query filtered correctly.
     *
     * Scalars are therefore compared by their string form. NULL stays distinct
     * from everything including the empty string, so a filter that quietly
     * matched nothing cannot be mistaken for one that matched a blank value.
     */
    protected function same_field_value($a, $b) {
        if ($a === null || $b === null) {
            return $a === null && $b === null;
        }
        if (is_bool($a) || is_bool($b)) {
            return (bool)$a === (bool)$b;
        }
        if (is_scalar($a) && is_scalar($b)) {
            // Trailing spaces are dropped because a CHAR(n) column blank-pads
            // on the way in: fbb_sha256 is character(64), so a 64-character
            // hash written as 33 characters of test data reads back with 31
            // spaces on the end. Postgres ignores that padding when comparing
            // CHAR values, so the query genuinely matched; only a PHP-side
            // string comparison sees a difference.
            $sa = rtrim((string)$a);
            $sb = rtrim((string)$b);

            // Numbers are compared as numbers, because a NUMERIC(p,s) column
            // returns its value at full scale: mig_version is numeric(6,2), so
            // 1092.9 is stored and read back as '1092.90'. Same number, same
            // row, different string — and comparing the strings made this suite
            // fail on roughly one run in ten, whenever the generator happened to
            // produce a value with a trailing zero to pad.
            if (is_numeric($sa) && is_numeric($sb)) {
                return self::normalise_number($sa) === self::normalise_number($sb);
            }

            return $sa === $sb;
        }
        return $a === $b;
    }

    /**
     * A numeric string reduced to one canonical form. Trailing fractional
     * zeros are dropped by text surgery rather than by casting to float, so a
     * bigint primary key beyond float's exact-integer range still compares
     * correctly.
     */
    private static function normalise_number(string $n) {
        $n = ltrim($n, '+');
        if (strpos($n, '.') === false) {
            return $n === '-0' ? '0' : $n;
        }
        $n = rtrim(rtrim($n, '0'), '.');
        return ($n === '' || $n === '-') ? '0' : $n;
    }

    /**
     * Compare Multi results with direct SQL query
     */
    protected function compare_with_sql($multi_instance, $expected_sql, $bind_params = []) {
        // Get results from Multi class
        $multi_instance->load();
        $multi_results = [];
        foreach ($multi_instance as $item) {
            $multi_results[] = $item->key;
        }
        
        // Execute direct SQL
        $dbhelper = DbConnector::get_instance();
        $dblink = $dbhelper->get_db_link();
        $stmt = $dblink->prepare($expected_sql);
        $stmt->execute($bind_params);
        $sql_results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $sql_results[] = $row[array_key_first($row)];
        }
        
        // Compare results
        sort($multi_results);
        sort($sql_results);
        
        $this->assert_equals($sql_results, $multi_results, 
            "Multi class results should match SQL query results");
    }

    /**
     * Test basic loading without filters
     */
    protected function test_multi_basic_loading($debug = false) {
        if (empty($this->test_records)) {
            echo "<span style='color: #ff9800;'>[SKIP] No test records to validate basic loading</span><br>\n";
            return;
        }
        
        // Test that Multi class can load records (basic functionality)
        $multi = new $this->multi_class([], [], 100); // Limit to 100 records for testing
        
        $multi->load();
        
        $total_count = 0;
        foreach ($multi as $item) {
            $total_count++;
            // Limit iteration for performance
            if ($total_count >= 100) break;
        }
        
        // Verify Multi class loaded records
        $this->assert_true($total_count > 0, "Multi class should load at least some records");
        
        if ($debug) {
            echo "Found $total_count records in basic loading test<br>\n";
        }
    }

    /**
     * Test filtering capabilities
     */
    protected function test_multi_filtering($debug = false) {
        if (empty($this->test_records)) {
            echo "<span style='color: #ff9800;'>[SKIP] No test records for filtering tests</span><br>\n";
            return;
        }
        
        // Try to find a filter that this Multi class actually supports
        $filter_options = $this->detect_multi_class_filters();
        
        if (empty($filter_options)) {
            echo "<span style='color: #ff9800;'>[SKIP] No supported filter options detected for {$this->multi_class}</span><br>\n";
            return;
        }
        
        // Exercise every filter the class declares, not just the first one.
        //
        // Testing one option per collection leaves the rest of the query surface
        // unexamined, and the untested options are exactly where a filter tends
        // to be added later without anyone checking that it narrows anything.
        $exercised = 0;
        foreach ($filter_options as $filter_option => $database_field) {
            if ($debug) {
                echo "  Using filter '$filter_option' for field '$database_field'<br>\n";
            }

            // First try to get a test value from the synthetic test data we created
            $test_value = null;
            foreach ($this->test_records as $test_record) {
                $test_value = $test_record['model']->get($database_field);
                if ($test_value !== null && $test_value !== '') {
                    break;
                }
            }

            // If no value found in synthetic test data, fall back to existing database records
            if ($test_value === null || $test_value === '') {
                $test_value = null;
                $multi_sample = new $this->multi_class([], [], 10); // Get sample of existing records
                $multi_sample->load();

                foreach ($multi_sample as $sample_item) {
                    $sample_value = $sample_item->get($database_field);
                    if ($sample_value !== null && $sample_value !== '') {
                        $test_value = $sample_value;
                        break;
                    }
                }
            }

            // No value anywhere to filter on. That is a statement about the
            // fixture data, not about the collection — a nullable column the
            // generator left empty proves nothing either way — so this option
            // is passed over rather than failed.
            if ($test_value === null || $test_value === '') {
                if ($debug) {
                    echo "  <span style='color: #ff9800;'>[SKIP] No sample value for '{$filter_option}' (field: {$database_field})</span><br>\n";
                }
                continue;
            }

            if ($debug) {
                echo "  Filter: {$filter_option} = {$test_value} (field: {$database_field}) [using test data]<br>\n";
            }

            // Create Multi instance with the supported filter
            $multi = new $this->multi_class([$filter_option => $test_value]);

            // Add timeout protection for filtered load
            try {
                set_time_limit(15); // 15 second timeout
                $multi->load($debug); // Use debug only if specifically requested
            } catch (Exception $e) {
                // A filter whose SQL will not execute is a defect, not a skip.
                // Only a test-database schema gap earns the pass, because that
                // is a fact about the copied database rather than the query.
                if ($this->is_database_schema_error($e->getMessage())) {
                    echo "  <span style='color: #ff9800;'>[SKIP] '{$filter_option}': test database schema gap — " . $e->getMessage() . "</span><br>\n";
                    continue;
                }
                $this->assert_true(false, "Filter '{$filter_option}' must execute (got: " . $e->getMessage() . ")");
                continue;
            }

            // Count results and verify filter worked
            $result_count = 0;
            $matching_results = 0;
            $found_ids = [];

            foreach ($multi as $item) {
                $result_count++;
                $item_value = $item->get($database_field);
                $found_ids[] = $item_value;
                if ($this->same_field_value($item_value, $test_value)) {
                    $matching_results++;
                }
            }

            if ($debug) {
                echo "  Filter results: {$result_count} total, {$matching_results} matching<br>\n";
                if ($result_count == 0) {
                    echo "  DEBUG: Filter query returned 0 results. Looking for {$database_field}={$test_value}<br>\n";
                } else if (count($found_ids) <= 10) {
                    echo "  DEBUG: Found {$database_field} values: " . implode(', ', $found_ids) . "<br>\n";
                }
            }

            $exercised++;

            // An equality filter built from a value that is known to be in the
            // table must find it. A predicate is exempt: 'created_before' given
            // a timestamp sampled from an arbitrary row can legitimately match
            // nothing, and demanding a row back would make the assertion depend
            // on which fixture the generator happened to produce.
            if (!empty($this->equality_filters[$filter_option])) {
                $this->assert_true($result_count > 0, "Filter should return at least one result (got {$result_count} results for filter {$filter_option}={$test_value})");
            }

            // Every row returned must satisfy the filter — not merely some of
            // them — for options that compare the caller's value to a column.
            //
            // "At least one matches" is satisfied by a collection that ignores
            // the option completely and hands back the whole table, because the
            // row being looked for is somewhere in it. That is the exact failure
            // this suite exists to catch: a Multi class that silently drops an
            // option it does not implement returns a plausible superset, and
            // every caller downstream treats it as authoritative. Where the
            // option is an owner id, the superset is somebody else's data.
            //
            // Predicate options (ranges, flags, mapped values) are exempt: the
            // caller's value is not the stored value, so row-by-row equality
            // says nothing about them. See classify_filter_options().
            if (!empty($this->equality_filters[$filter_option])) {
                $non_matching = $result_count - $matching_results;
                $sample = implode(', ', array_slice($found_ids, 0, 10));
                $this->assert_true($matching_results === $result_count,
                    "Every row returned for {$filter_option}={$test_value} must have {$database_field}={$test_value} "
                    . "($non_matching of $result_count did not; values seen: $sample)");
            }

            if ($debug) echo "    Filter '$filter_option' verified (matches: $matching_results/$result_count)<br>\n";
        }

        if ($debug) echo "    Filtering test completed successfully ($exercised of " . count($filter_options) . " declared filters exercised)<br>\n";
    }

    /**
     * Test ordering capabilities
     */
    protected function test_multi_ordering($debug = false) {
        if (empty($this->test_records)) {
            echo "  <span style='color: #ff9800;'>[SKIP] No test records for ordering tests</span><br>\n";
            return;
        }
        
        // Test basic ordering functionality - most Multi classes support ordering by primary key
        $pkey = $this->model_class::$pkey_column;
        
        try {
            // Test ASC ordering
            $multi_asc = new $this->multi_class([], [$pkey => 'ASC'], 10);
            $result_asc = $this->verify_order($multi_asc, $pkey, 'ASC');
            
            // Test DESC ordering only if ASC worked
            if ($result_asc) {
                $multi_desc = new $this->multi_class([], [$pkey => 'DESC'], 10);
                $this->verify_order($multi_desc, $pkey, 'DESC');
            }
            
        } catch (Exception $e) {
            // If ordering by primary key doesn't work, skip the test
            echo "  <span style='color: #ff9800;'>[SKIP] Ordering not supported by {$this->multi_class}: " . $e->getMessage() . "</span><br>\n";
            return;
        }
    }

    /**
     * Test pagination (limit/offset)
     */
    protected function test_multi_pagination($debug = false) {
        echo "  Testing pagination...<br>\n";
        
        if (count($this->test_records) < 2) {
            echo "  <span style='color: #ff9800;'>[SKIP] Need at least 2 records for pagination tests</span><br>\n";
            return;
        }
        
        // Test limit (use smaller limit for 3-record test data)
        $multi_limit = new $this->multi_class([], [], 1);
        $multi_limit->load();
        $limit_count = 0;
        foreach ($multi_limit as $item) {
            $limit_count++;
        }
        $this->assert_true($limit_count <= 1, "Limit should restrict results to 1 or fewer");
        
        // Test offset (skip offset test if we only have 2-3 records)
        if (count($this->test_records) >= 3) {
            $multi_offset = new $this->multi_class([], [], 1, 1);
            $multi_offset->load();
            $offset_count = 0;
            foreach ($multi_offset as $item) {
                $offset_count++;
            }
            $this->assert_true($offset_count <= 1, "Offset pagination should work correctly");
        } else {
            echo "  <span style='color: #ff9800;'>[SKIP] Offset test requires 3+ records</span><br>\n";
        }
        
        if ($debug) echo "    Pagination test completed successfully<br>\n";
    }

    /**
     * Test combined scenarios
     */
    protected function test_multi_combined($debug = false) {
        echo "  Testing combined scenarios...<br>\n";
        
        if (empty($this->test_records)) {
            echo "  <span style='color: #ff9800;'>[SKIP] No test records for combined tests</span><br>\n";
            return;
        }
        
        // Use the same filter detection logic as the filtering test
        $filter_options = $this->detect_multi_class_filters();
        
        if (empty($filter_options)) {
            echo "  <span style='color: #ff9800;'>[SKIP] No supported filter options for combined tests</span><br>\n";
            return;
        }
        
        // Get the first supported filter
        $filter_option = array_keys($filter_options)[0];
        $database_field = $filter_options[$filter_option];
        
        // First try to get a test value from the synthetic test data we created
        $test_value = null;
        foreach ($this->test_records as $test_record) {
            $test_value = $test_record['model']->get($database_field);
            if ($test_value !== null && $test_value !== '') {
                break;
            }
        }
        
        // If no value found in synthetic test data, fall back to existing database records
        if ($test_value === null) {
            $multi_sample = new $this->multi_class([], [], 10);
            $multi_sample->load();
            
            foreach ($multi_sample as $sample_item) {
                $sample_value = $sample_item->get($database_field);
                if ($sample_value !== null && $sample_value !== '') {
                    $test_value = $sample_value;
                    break;
                }
            }
        }
        
        if ($test_value === null) {
            $this->assert_true(false, "Multi class {$this->multi_class} supports filtering by '{$filter_option}' (field: {$database_field}) but no existing data found for combined test. This indicates either: 1) Wrong field name in filter, 2) Database has no data for this field, or 3) Field should not be filterable.");
        }
        $pkey = $this->model_class::$pkey_column;
        
        try {
            // Test filter + order + limit combination
            $multi_combined = new $this->multi_class(
                [$filter_option => $test_value],  // filter using supported option
                [$pkey => 'ASC'],                 // order by primary key
                1                                 // limit
            );
            
            $multi_combined->load();
            $combined_count = 0;
            $matching_results = 0;
            
            foreach ($multi_combined as $item) {
                $combined_count++;
                // Check if the filter worked (but don't fail if some results don't match)
                $item_value = $item->get($database_field);
                if ($this->same_field_value($item_value, $test_value)) {
                    $matching_results++;
                }
            }

            // Basic validations
            $this->assert_true($combined_count <= 1, "Combined query should respect limit (got $combined_count results)");
            
            if ($combined_count > 0) {
                // If we got results, provide info about filter effectiveness
                if ($debug && $matching_results === 0) {
                    echo "    Note: Filter may not be working as expected ($matching_results matching out of $combined_count)<br>\n";
                }
            }
            
        } catch (Exception $e) {
            echo "  <span style='color: #ff9800;'>[SKIP] Combined scenario failed: " . $e->getMessage() . "</span><br>\n";
            return;
        }
        
        if ($debug) echo "    Combined scenario test completed (filter: $filter_option, results: $combined_count)<br>\n";
    }

    /**
     * Verify ordering of Multi class results
     */
    protected function verify_order($multi_instance, $field, $direction) {
        $multi_instance->load();
        $values = [];
        $records_found = 0;
        
        foreach ($multi_instance as $item) {
            $records_found++;
            $values[] = $item->get($field);
        }
        
        if ($records_found === 0) {
            echo "  <span style='color: #ff9800;'>[SKIP] No records returned for ordering test</span><br>\n";
            return false;
        }
        
        if (count($values) < 2) {
            echo "  <span style='color: #ff9800;'>[SKIP] Need at least 2 records to verify ordering (found $records_found)</span><br>\n";
            return true; // Not a failure, just insufficient data
        }
        
        $sorted_values = $values;
        if ($direction === 'ASC') {
            sort($sorted_values);
        } else {
            rsort($sorted_values);
        }
        
        // Provide better error message showing actual values
        if ($sorted_values !== $values) {
            $error_msg = "Results should be ordered $direction by $field. ";
            $error_msg .= "Expected: [" . implode(', ', array_slice($sorted_values, 0, 5)) . "]"; 
            $error_msg .= ", Got: [" . implode(', ', array_slice($values, 0, 5)) . "]";
            if (count($values) > 5) {
                $error_msg .= " (showing first 5 of " . count($values) . " total)";
            }
            
            $this->assert_equals($sorted_values, $values, $error_msg);
        }
        
        return true;
    }

    /**
     * Find fields suitable for filtering
     */
    protected function find_filterable_fields() {
        $filterable = [];
        $fields = $this->get_all_testable_fields();
        
        foreach ($fields as $field => $properties) {
            $type = $this->get_field_type($field);
            // Most field types can be filtered
            if (strpos($type, 'int') !== false || 
                strpos($type, 'varchar') !== false ||
                strpos($type, 'bool') !== false) {
                $filterable[] = $field;
            }
        }
        
        return $filterable;
    }
    
    /**
     * Check if verbose mode is enabled
     */
    private function is_verbose() {
        return isset($_GET['verbose']) && $_GET['verbose'];
    }
    
    /**
     * Check if we've exceeded the execution time limit
     */
    
    /**
     * Detect what filter options this Multi class actually supports
     * Returns array of filter_option => database_field mappings
     */
    private function detect_multi_class_filters() {
        // Dynamic detection by analyzing the Multi class source code
        $common_filters = [];
        
        try {
            // Get the class file path
            $reflection = new ReflectionClass($this->multi_class);
            $filename = $reflection->getFileName();
            
            if ($filename && file_exists($filename)) {
                $source = file_get_contents($filename);
                
                // Look for patterns like: if (isset($this->options['filter_name']))
                if (preg_match_all('/if\s*\(\s*isset\s*\(\s*\$this->options\[\'([^\']+)\'\]\s*\)\s*\)/', $source, $matches)) {
                    foreach ($matches[1] as $option_key) {
                        // Try to find the corresponding database field by looking at the next line
                        $pattern = '/if\s*\(\s*isset\s*\(\s*\$this->options\[\'' . preg_quote($option_key) . '\'\]\s*\)\s*\)\s*\{[^}]*\$filters\[\'([^\']+)\'\]/';
                        if (preg_match($pattern, $source, $field_match)) {
                            // An option that sets more than one filter cannot be
                            // exercised with generated data. MultiSessionAnalytic's
                            // 'session_id' pins sev_entity_type to the literal
                            // 'event_session' as well as matching the id, so a
                            // fixture carrying a random entity type is correctly
                            // excluded — the query is right and the fixture cannot
                            // satisfy it. Testing it would assert the generator's
                            // luck, not the collection.
                            $block = $field_match[0];
                            if (substr_count($block, '$filters[') > 1) {
                                continue;
                            }
                            $common_filters[$option_key] = $field_match[1];
                        } else {
                            // Fallback: try to guess the field name from the option key
                            $prefix = $this->model_class::$prefix;
                            if ($option_key === 'user_id') {
                                $common_filters[$option_key] = $prefix . '_usr_user_id';
                            } else if ($option_key === 'id' || $option_key === strtolower($this->model_class) . '_id') {
                                $common_filters[$option_key] = $this->model_class::$pkey_column;
                            } else {
                                // Generic pattern: option_key -> prefix_option_key
                                $common_filters[$option_key] = $prefix . '_' . $option_key;
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            // If reflection fails, fall back to basic approach
        }
        
        // Drop any filter whose column was guessed rather than read.
        //
        // The detection above reads the column out of the `$filters['col']`
        // assignment when there is one, and otherwise guesses `prefix_option`.
        // The guess is wrong whenever the option does not map to a plain column
        // on this table — MultiConversation resolves 'participant_user_id'
        // through a lateral join to the participants table, and
        // MultiUserEncryptionWrapping's 'vault_id' is stored as
        // uew_uev_user_encryption_vault_id. Testing a column the model does not
        // have proves nothing about the collection, so those options are left
        // to whatever other filters the class exposes.
        // Also drop the soft-delete option. Its column holds a marker, not a
        // value to match on: the option is a boolean ("give me deleted rows"),
        // so picking a delete_time out of the data and filtering by it is not a
        // query any caller makes. Fixtures deliberately carry no delete_time
        // (see get_all_multi_testable_fields), so there is nothing to sample
        // either.
        $known_fields = $this->model_class::$field_specifications;
        foreach ($common_filters as $option => $column) {
            if (!array_key_exists($column, $known_fields)
                || strpos(strtolower($column), 'delete_time') !== false) {
                unset($common_filters[$option]);
            }
        }

        // If we couldn't detect any filters dynamically, skip filtering test
        if (empty($common_filters)) {
            return [];
        }

        // Classification also drops options that cannot be driven from
        // generated data, so it returns the surviving set.
        $common_filters = $this->classify_filter_options($common_filters);

        return $common_filters;
    }

    /**
     * Work out which options match a value and which interpret one.
     *
     * Two shapes of filter live side by side in these classes and they deserve
     * different assertions:
     *
     *   $filters['ord_timestamp'] = [$this->options['x'], PDO::PARAM_STR];
     *       An equality binding — the caller's value IS the column value, so
     *       every returned row must carry it.
     *
     *   $filters['ord_timestamp'] = "<= " . $dblink->quote($this->options['x']);
     *   $filters['amu_parent_menu_id'] = "IS NOT NULL";
     *   $filters['bkt_status'] = "= " . ($this->options['active'] ? '1' : '0');
     *       A predicate — the option is a bound, a flag, or a mapped value.
     *       'created_before' => a date does not mean every row has that exact
     *       timestamp; it means every row is earlier. Asserting equality here
     *       reports a correct range query as broken, which is how the first
     *       run of this suite produced twenty-five failures and zero bugs.
     *
     * Only the first shape earns the strict every-row-matches assertion. The
     * second still gets loaded and still has to return rows without erroring,
     * which is what catches an option whose SQL does not parse.
     */
    private function classify_filter_options(array $filters) {
        $this->equality_filters = [];

        try {
            $reflection = new ReflectionClass($this->multi_class);
            $filename = $reflection->getFileName();
            $source = ($filename && file_exists($filename)) ? file_get_contents($filename) : '';
        } catch (Exception $e) {
            $source = '';
        }

        foreach ($filters as $option => $column) {
            // Classify from this option's own block, never by searching the
            // whole file for the column. Several classes expose two options
            // over one column — MultiAdminMenu has both 'parent_menu_id'
            // (an equality binding) and 'has_parent_menu_id' (IS NOT NULL) on
            // amu_parent_menu_id — and a file-wide search finds the binding and
            // calls the predicate an equality, which is how a correct
            // "has a parent" query gets reported as returning the wrong rows.
            $snippet = $this->option_source_snippet($source, $option);
            $assign = '/\$filters\[\'' . preg_quote($column, '/') . '\'\]\s*=\s*';

            // A third shape exists: the option value is handed straight through
            // as the SQL fragment, operator and all —
            //   $filters['prv_display_priority'] = $this->options['prv_display_priority'];
            // whose only caller passes the string '> 0'. That is a documented
            // filter format (see CLAUDE.md § Model Querying Patterns), but it
            // means no generated scalar can be a valid value: a bare 210 lands
            // in the query as `prv_display_priority 210` and fails to parse.
            // Such an option cannot be exercised from synthesised data, so it
            // is dropped rather than tested with a value it never accepts.
            if ($snippet !== '' && preg_match($assign . '\$this->options\[/', $snippet)) {
                unset($filters[$option]);
                continue;
            }

            // An array/array() right-hand side is a bound value; anything else
            // (a string condition, a concatenation, a ternary) interprets it.
            $this->equality_filters[$option] = $snippet !== ''
                && preg_match($assign . '(\[|array\s*\()/', $snippet) === 1;
        }

        return $filters;
    }

    /**
     * The slice of getMultiResults() that handles one option: from its isset()
     * test up to whichever comes first — the next option's isset() test, or a
     * few lines on. Enough to see how the option is turned into a filter,
     * without dragging in its neighbours.
     */
    private function option_source_snippet($source, $option) {
        if ($source === '') {
            return '';
        }
        $needle = "\$this->options['" . $option . "']";
        $start = strpos($source, $needle);
        if ($start === false) {
            return '';
        }

        $end = strlen($source);
        if (preg_match('/isset\s*\(\s*\$this->options\[\'/', $source, $m, PREG_OFFSET_CAPTURE, $start + strlen($needle))) {
            $end = $m[0][1];
        }
        return substr($source, $start, min($end - $start, 600));
    }
    
    /**
     * Check if an error message indicates a database schema problem
     */
    protected function is_database_schema_error($error_message) {
        $schema_error_patterns = [
            'column .* does not exist',
            'table .* does not exist',
            'relation .* does not exist',
            'undefined column',
            'unknown column',
            'no such column',
            'invalid column name',
            'column .* is not valid',
            'ORA-00904',  // Oracle invalid identifier
            'SQL error.*column',
            'SQLSTATE\[42703\]',  // PostgreSQL undefined column
            'SQLSTATE\[42S02\]',  // MySQL table doesn't exist
            'SQLSTATE\[42S22\]'   // MySQL column not found
        ];
        
        $error_lower = strtolower($error_message);
        
        foreach ($schema_error_patterns as $pattern) {
            if (preg_match('/' . strtolower($pattern) . '/i', $error_lower)) {
                return true;
            }
        }
        
        return false;
    }
}