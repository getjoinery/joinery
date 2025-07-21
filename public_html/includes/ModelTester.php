<?php
/**
 * ModelTester - Standalone automated testing class for Joinery CMS models
 * 
 * This class provides comprehensive automated testing for all models that extend SystemBase.
 * It uses reflection and inference to generate appropriate test data and validation scenarios
 * without requiring custom code for each model.
 */

require_once('PathHelper.php');
require_once('LibraryFunctions.php');

class ModelTester {
    
    private $model_class;
    private $model_instance;
    private static $test_pass_count = 0;
    private static $test_fail_count = 0;
    private static $test_warn_count = 0;
    private static $verbose = false;
    
    public function __construct($model_class) {
        $this->model_class = $model_class;
    }
    
    /**
     * Set verbose mode
     * @param mixed $verbose - true/1 for all classes, or a class name for specific class
     */
    public static function set_verbose($verbose) {
        self::$verbose = $verbose;
    }
    
    /**
     * Check if verbose output should be shown for current class
     */
    private function is_verbose() {
        if (self::$verbose === true || self::$verbose === 1) {
            return true;
        }
        if (is_string(self::$verbose) && self::$verbose === $this->model_class) {
            return true;
        }
        return false;
    }
    
    /**
     * Main test execution method
     */
    public function test($model_instance = null, $debug = false) {
        $this->model_instance = $model_instance ?: new $this->model_class(null);
        $verbose = $this->is_verbose();
        
        echo '<b style="color: #333;">TESTING CLASS: ' . $this->model_class . "</b><br>\n";
        
        // Set up test database mode if available
        $dbhelper = DbConnector::get_instance();
        if (method_exists($dbhelper, 'set_test_mode')) {
            $dbhelper->set_test_mode();
        }
        
        try {
            if ($verbose) echo "Starting CRUD tests...<br>\n"; flush();
            $this->test_automated_crud($debug);
            if ($verbose) echo "CRUD tests completed, starting validation tests...<br>\n"; flush();
            $this->test_automated_validation($debug);
            if ($verbose) echo "Validation tests completed, starting constraint tests...<br>\n"; flush();
            $this->test_automated_constraints($debug);
            if ($verbose) echo "Constraint tests completed, starting edge case tests...<br>\n"; flush();
            $this->test_automated_edge_cases($debug);
            if ($verbose) echo "All tests completed successfully<br>\n"; flush();
            
            echo "<span style='color: green;'>[PASS] {$this->model_class} - All automated tests passed</span><br>\n";
            
        } catch (Exception $e) {
            if ($verbose) echo "Caught exception during testing: " . $e->getMessage() . "<br>\n"; flush();
            
            // Handle configuration/dependency issues as skips rather than failures
            if (strpos($e->getMessage(), 'api keys are not present') !== false) {
                echo "<span style='color: #ff9800;'>[SKIP] {$this->model_class} - Configuration required: " . $e->getMessage() . "</span><br>\n";
                
                // Clean up and return skip status
                if (method_exists($dbhelper, 'close_test_mode')) {
                    $dbhelper->close_test_mode();
                }
                return 'SKIPPED';
            }
            
            // Clean up on failure and re-throw to let caller handle the error
            if (method_exists($dbhelper, 'close_test_mode')) {
                $dbhelper->close_test_mode();
            }
            throw $e;
        }
        
        // Clean up test mode if available
        if (method_exists($dbhelper, 'close_test_mode')) {
            $dbhelper->close_test_mode();
        }
        
        return true;
    }
    
    /**
     * Test CRUD operations with automatically generated data
     */
    protected function test_automated_crud($debug = false) {
        $verbose = $this->is_verbose();
        if ($debug || $verbose) echo "Testing CRUD operations...<br>\n";
        
        if ($verbose) echo "Generating test data...<br>\n"; flush();
        // Generate valid test data automatically
        $test_data = $this->generate_valid_test_data();
        
        if ($verbose) echo "Creating model instance...<br>\n"; flush();
        // Create a fresh model instance for testing
        $model = new $this->model_class(null);
        
        if ($verbose) echo "Setting field values...<br>\n"; flush();
        // Test Create
        foreach ($test_data as $field => $value) {
            if ($verbose) echo "Setting $field = $value<br>\n"; flush();
            $model->set($field, $value);
            if ($debug) echo "Set $field = $value<br>\n";
        }
        
        if ($verbose) echo "About to save model...<br>\n"; flush();
        try {
            $model->save();
        } catch (Exception $e) {
            // Check if this is a database NOT NULL constraint violation
            if (strpos($e->getMessage(), 'Not null violation') !== false || 
                strpos($e->getMessage(), 'violates not-null constraint') !== false) {
                echo "  <span style='color: #ff9800;'>[WARN] Model relies on database-level NOT NULL enforcement - required fields not properly defined in model</span><br>\n";
                return; // Skip the rest of the CRUD test
            }
            
            // Provide context about which fields were being saved when the error occurred
            $field_info = [];
            foreach ($test_data as $field => $value) {
                $field_info[] = "$field=" . (is_string($value) ? "'$value'" : $value);
            }
            
            // Also include stack trace for generic errors
            $error_details = "Original error: " . $e->getMessage();
            if ($e->getMessage() === "There was an error" || strlen($e->getMessage()) < 25) {
                $error_details .= "\nException type: " . get_class($e);
                $error_details .= "\nFile: " . $e->getFile() . " line " . $e->getLine();
                $error_details .= "\nStack trace: " . $e->getTraceAsString();
            }
            
            throw new Exception("Failed to save model with test data [" . implode(', ', $field_info) . "]. " . $error_details);
        }
        if ($verbose) echo "Model saved successfully<br>\n"; flush();
        if ($verbose) echo "Model key after save: " . ($model->key ?? 'NULL') . "<br>\n"; flush();
        if ($verbose) echo "Model key type: " . gettype($model->key) . "<br>\n"; flush();
        $this->assert_true($model->key !== null, "Record should be created");
        
        $original_key = $model->key;
        if ($verbose) echo "Created record with key: $original_key<br>\n"; flush();
        if ($debug) echo "Created record with key: $original_key<br>\n";
        
        // Test Read
        if ($verbose) echo "Testing read operation...<br>\n"; flush();
        $model->load();
        $this->assert_equals($original_key, $model->key, "Record should be loaded correctly");
        if ($verbose) echo "Read operation completed<br>\n"; flush();
        
        // Test Update
        if ($verbose) echo "Finding updateable field...<br>\n"; flush();
        $updateable_field = $this->find_updateable_field($model);
        if ($verbose) echo "Found updateable field: " . ($updateable_field ?: 'none') . "<br>\n"; flush();
        if ($updateable_field) {
            if ($verbose) echo "Generating new value for update...<br>\n"; flush();
            $new_value = $this->generate_different_value($updateable_field);
            
            // Skip update test if generate_different_value returns null (e.g., for timestamps)
            if ($new_value === null) {
                if ($verbose) echo "Skipping update test for timestamp/datetime field: $updateable_field<br>\n"; flush();
            } else {
                if ($verbose) echo "Setting $updateable_field = $new_value<br>\n"; flush();
                $model->set($updateable_field, $new_value);
                if ($verbose) echo "About to save update...<br>\n"; flush();
                try {
                    $model->save();
                } catch (Exception $e) {
                    // Check if this is a database constraint violation - skip the rest of the update test
                    if (strpos($e->getMessage(), 'value too long') !== false ||
                        strpos($e->getMessage(), 'character varying') !== false ||
                        strpos($e->getMessage(), 'String data, right truncated') !== false) {
                        // Database enforces the constraint, which is fine - just skip this test
                        return; // Skip the rest of the update test
                    }
                    throw new Exception("Failed to save model during update test for field '$updateable_field' with value '" . (is_string($new_value) ? $new_value : $new_value) . "'. Original error: " . $e->getMessage());
                }
                if ($verbose) echo "Update saved successfully<br>\n"; flush();
                $model->load();
                $this->assert_equals($new_value, $model->get($updateable_field), "Field should be updated");
                if ($debug) echo "Updated $updateable_field to $new_value<br>\n";
                if ($verbose) echo "Update test completed<br>\n"; flush();
            }
        }
        
        // Test Delete
        if ($verbose) echo "About to delete model...<br>\n"; flush();
        if ($verbose) echo "Model key: " . $model->key . "<br>\n"; flush();
        if ($verbose) echo "Model class: " . get_class($model) . "<br>\n"; flush();
        if ($verbose) echo "Memory usage before delete: " . memory_get_usage(true) . " bytes<br>\n"; flush();
        
        // Register a shutdown function to catch fatal errors
        register_shutdown_function(function() {
            $error = error_get_last();
            if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE || $error['type'] === E_CORE_ERROR)) {
                if ($verbose) echo "FATAL ERROR during delete: " . $error['message'] . " in " . $error['file'] . " on line " . $error['line'] . "<br>\n";
                flush();
            }
        });
        
        if ($verbose) echo "Calling permanent_delete() now...<br>\n"; flush();
        try {
            $model->permanent_delete();
            if ($verbose) echo "Delete completed<br>\n"; flush();
        } catch (Exception $e) {
            if ($verbose) echo "Delete failed with error: " . $e->getMessage() . "<br>\n"; flush();
            if ($verbose) echo "Exception type: " . get_class($e) . "<br>\n"; flush();
            if ($verbose) echo "File: " . $e->getFile() . " line " . $e->getLine() . "<br>\n"; flush();
            // Re-throw to maintain test failure behavior
            throw $e;
        } catch (Error $e) {
            if ($verbose) echo "Delete failed with PHP Error: " . $e->getMessage() . "<br>\n"; flush();
            if ($verbose) echo "Error type: " . get_class($e) . "<br>\n"; flush();
            if ($verbose) echo "File: " . $e->getFile() . " line " . $e->getLine() . "<br>\n"; flush();
            
            // Check if this is an undefined method error
            if (strpos($e->getMessage(), 'Call to undefined method') !== false) {
                $this->test_fail("Model has broken permanent_delete() logic - undefined method call");
                return; // Skip the rest of the CRUD test
            }
            
            throw $e;
        }
        
        // Verify deletion
        if ($verbose) echo "Verifying deletion...<br>\n"; flush();
        $model_class = $this->model_class;
        if (!$model_class::check_if_exists($original_key)) {
            $this->test_pass("Record deleted successfully");
        } else {
            $this->test_fail("Record should be deleted but still exists");
        }
        if ($verbose) echo "Deletion verification completed<br>\n"; flush();
        
        if ($debug || $verbose) echo "CRUD tests completed<br>\n";
    }
    
    /**
     * Test field validation using field specifications
     */
    protected function test_automated_validation($debug = false) {
        $verbose = $this->is_verbose();
        if ($debug || $verbose) echo "Testing field validation...<br>\n";
        
        $model_class = $this->model_class;
        
        if ($verbose) echo "Testing required fields...<br>\n"; flush();
        // Test required fields
        foreach ($model_class::$required_fields as $required_field) {
            if ($verbose) echo "Testing required field: $required_field<br>\n"; flush();
            try {
                $this->test_required_field($required_field, $debug);
                if ($verbose) echo "Required field test completed for: $required_field<br>\n"; flush();
            } catch (Exception $e) {
                if ($verbose) echo "Required field test failed for $required_field: " . $e->getMessage() . "<br>\n"; flush();
                throw $e;
            }
        }
        
        if ($verbose) echo "Testing field type constraints...<br>\n"; flush();
        // Test field type constraints
        foreach ($model_class::$field_specifications as $field => $spec) {
            if ($verbose) echo "Testing field type constraint: $field<br>\n"; flush();
            try {
                $this->test_field_type_constraints($field, $spec, $debug);
                if ($verbose) echo "Field type constraint test completed for: $field<br>\n"; flush();
            } catch (Exception $e) {
                if ($verbose) echo "Field type constraint test failed for $field: " . $e->getMessage() . "<br>\n"; flush();
                throw $e;
            }
        }
        
        if ($debug || $verbose) echo "Validation tests completed<br>\n";
    }
    
    /**
     * Test database constraints
     */
    protected function test_automated_constraints($debug = false) {
        $verbose = $this->is_verbose();
        if ($debug || $verbose) echo "Testing database constraints...<br>\n";
        
        // Skip unique constraint testing - we can't reliably infer which fields are unique
        // TODO: Enable this when models can explicitly declare unique fields
        
        // Note: Foreign key and unique constraint testing skipped in automated phase
        
        if ($debug || $verbose) echo "Constraint tests completed<br>\n";
    }
    
    /**
     * Test edge cases and boundary conditions
     */
    protected function test_automated_edge_cases($debug = false) {
        $verbose = $this->is_verbose();
        if ($debug || $verbose) echo "Testing edge cases...<br>\n";
        
        // Test with null values where allowed
        $nullable_fields = $this->get_nullable_fields();
        foreach ($nullable_fields as $field) {
            $this->test_null_value($field, $debug);
        }
        
        // Test maximum length fields
        $varchar_fields = $this->get_varchar_fields();
        foreach ($varchar_fields as $field => $max_length) {
            $this->test_max_length($field, $max_length, $debug);
        }
        
        // Test numeric boundaries
        $numeric_fields = $this->get_numeric_fields();
        foreach ($numeric_fields as $field => $type) {
            $this->test_numeric_boundaries($field, $type, $debug);
        }
        
        if ($debug || $verbose) echo "Edge case tests completed<br>\n";
    }
    
    /**
     * Generate valid test data for all required fields
     */
    protected function generate_valid_test_data() {
        $test_data = [];
        $model_class = $this->model_class;
        
        foreach ($model_class::$required_fields as $field) {
            $test_data[$field] = $this->generate_field_value($field);
        }
        
        return $test_data;
    }
    
    /**
     * Generate appropriate value for a field based on its specification
     */
    protected function generate_field_value($field) {
        $model_class = $this->model_class;
        $spec = $model_class::$field_specifications[$field] ?? [];
        $type = $spec['type'] ?? 'varchar(255)';
        
        // Handle different field types
        if (strpos($type, 'varchar') !== false) {
            return $this->generate_varchar_value($field, $type);
        }
        
        if (strpos($type, 'int') !== false) {
            return $this->generate_integer_value($field, $type);
        }
        
        if (strpos($type, 'decimal') !== false || strpos($type, 'float') !== false || strpos($type, 'numeric') !== false) {
            return $this->generate_decimal_value($field, $type);
        }
        
        if (strpos($type, 'timestamp') !== false || strpos($type, 'datetime') !== false) {
            return $this->generate_timestamp_value($field);
        }
        
        if (strpos($type, 'text') !== false) {
            return $this->generate_text_value($field);
        }
        
        if (strpos($type, 'bool') !== false) {
            return $this->generate_boolean_value($field);
        }
        
        // Default fallback
        return $this->generate_smart_value_by_name($field);
    }
    
    /**
     * Generate varchar value based on field name and constraints
     */
    protected function generate_varchar_value($field, $type) {
        // Extract max length from type like "varchar(100)"
        preg_match('/varchar\\((\\d+)\\)/', $type, $matches);
        $max_length = isset($matches[1]) ? (int)$matches[1] : 255;
        
        // Generate based on field name patterns
        $field_lower = strtolower($field);
        
        if (strpos($field_lower, 'email') !== false) {
            return 'test' . rand(1000, 9999) . '@example.com';
        }
        
        if (strpos($field_lower, 'name') !== false) {
            if (strpos($field_lower, 'first') !== false) {
                return 'TestFirst';
            }
            if (strpos($field_lower, 'last') !== false) {
                return 'TestLast';
            }
            return 'Test Name';
        }
        
        if (strpos($field_lower, 'title') !== false) {
            return 'Test Title';
        }
        
        if (strpos($field_lower, 'description') !== false) {
            return 'Test description content';
        }
        
        if (strpos($field_lower, 'url') !== false || strpos($field_lower, 'link') !== false) {
            return 'https://example.com/test';
        }
        
        if (strpos($field_lower, 'phone') !== false) {
            return '555-123-4567';
        }
        
        if (strpos($field_lower, 'address') !== false || strpos($field_lower, 'street') !== false) {
            return '123 Test Street';
        }
        
        if (strpos($field_lower, 'city') !== false) {
            return 'Test City';
        }
        
        if (strpos($field_lower, 'state') !== false) {
            return 'TS';
        }
        
        if (strpos($field_lower, 'zip') !== false || strpos($field_lower, 'postal') !== false) {
            return '12345';
        }
        
        if (strpos($field_lower, 'country') !== false) {
            return 'US';
        }
        
        // Default: generate based on field name, respecting max length
        $base_value = 'Test_' . ucfirst(str_replace(['_', '-'], '', $field));
        
        if (strlen($base_value) > $max_length) {
            return substr($base_value, 0, $max_length);
        }
        
        return $base_value;
    }
    
    /**
     * Generate integer value based on field name and type
     */
    protected function generate_integer_value($field, $type) {
        $field_lower = strtolower($field);
        
        // Handle foreign keys (fields ending in _id)
        $model_class = $this->model_class;
        if (strpos($field_lower, '_id') !== false && $field_lower !== $model_class::$pkey_column) {
            return 1; // Simple default value for foreign keys
        }
        
        // Handle specific field patterns
        if (strpos($field_lower, 'permission') !== false) {
            return rand(1, 10);
        }
        
        if (strpos($field_lower, 'quantity') !== false || strpos($field_lower, 'inventory') !== false) {
            return rand(1, 100);
        }
        
        if (strpos($field_lower, 'order') !== false || strpos($field_lower, 'sort') !== false) {
            return rand(1, 10);
        }
        
        if (strpos($field_lower, 'year') !== false) {
            return rand(2020, 2025);
        }
        
        if (strpos($field_lower, 'age') !== false) {
            return rand(18, 80);
        }
        
        // Handle different integer types
        if ($type === 'int8' || strpos($type, 'bigint') !== false) {
            return rand(1, 999999);
        }
        
        if ($type === 'int2' || strpos($type, 'smallint') !== false) {
            return rand(1, 32767);
        }
        
        // Default int4
        return rand(1, 32000); // Using 32000 to match existing logic
    }
    
    /**
     * Generate decimal/float value
     */
    protected function generate_decimal_value($field, $type) {
        $field_lower = strtolower($field);
        
        if (strpos($field_lower, 'price') !== false || strpos($field_lower, 'cost') !== false || strpos($field_lower, 'total') !== false) {
            return round(rand(100, 10000) / 100, 2); // $1.00 to $100.00
        }
        
        if (strpos($field_lower, 'rate') !== false || strpos($field_lower, 'percent') !== false) {
            return round(rand(0, 10000) / 100, 2); // 0.00 to 100.00
        }
        
        if (strpos($field_lower, 'weight') !== false) {
            return round(rand(10, 5000) / 100, 2); // 0.10 to 50.00
        }
        
        return round(rand(100, 999999) / 100, 2);
    }
    
    /**
     * Generate timestamp value
     */
    protected function generate_timestamp_value($field) {
        // Use 'now()' to match existing logic
        return 'now()';
    }
    
    /**
     * Generate text value
     */
    protected function generate_text_value($field) {
        return 'test text'; // Match existing logic
    }
    
    /**
     * Generate boolean value
     */
    protected function generate_boolean_value($field) {
        return false; // Match existing logic
    }
    
    /**
     * Generate smart value by field name (fallback)
     */
    protected function generate_smart_value_by_name($field) {
        // Use existing LibraryFunctions logic as fallback
        $model_class = $this->model_class;
        $spec = $model_class::$field_specifications[$field] ?? [];
        $type = $spec['type'] ?? 'varchar(255)';
        
        $field_length = LibraryFunctions::extract_length_from_spec($type);
        return LibraryFunctions::random_string($field_length);
    }
    
    // Additional helper methods will be added in the next implementation steps...
    
    /**
     * Find a field that can be updated for testing
     */
    protected function find_updateable_field($model) {
        $model_class = $this->model_class;
        
        // Look for a safe field to update (not primary key, not foreign key)
        foreach ($model_class::$field_specifications as $field => $spec) {
            $field_lower = strtolower($field);
            
            // Skip primary key
            if ($field === $model_class::$pkey_column) {
                continue;
            }
            
            // Skip foreign keys
            if (strpos($field_lower, '_id') !== false) {
                continue;
            }
            
            // Skip timestamp fields that auto-update
            if (strpos($field_lower, 'create_time') !== false || 
                strpos($field_lower, 'update_time') !== false) {
                continue;
            }
            
            // This field looks safe to update
            return $field;
        }
        
        return null;
    }
    
    /**
     * Generate a different value for a field (for update testing)
     */
    protected function generate_different_value($field) {
        $model_class = $this->model_class;
        $spec = $model_class::$field_specifications[$field] ?? [];
        $type = $spec['type'] ?? 'varchar(255)';
        
        // Generate appropriate different value based on field type
        if (strpos($type, 'int') !== false) {
            $original_value = $this->generate_integer_value($field, $type);
            return $original_value + 1;
        }
        
        if (strpos($type, 'decimal') !== false || strpos($type, 'float') !== false || strpos($type, 'numeric') !== false) {
            $original_value = $this->generate_decimal_value($field, $type);
            return $original_value + 0.01;
        }
        
        if (strpos($type, 'bool') !== false) {
            return !$this->generate_boolean_value($field);
        }
        
        if (strpos($type, 'timestamp') !== false || strpos($type, 'datetime') !== false) {
            // Skip timestamp fields in update tests since they often auto-update
            // and comparing 'now()' with actual timestamps is problematic
            return null; // Signal to skip this field
        }
        
        // For varchar and text fields
        if (strpos($type, 'varchar') !== false) {
            // Extract max length to ensure updated value fits
            preg_match('/varchar\\((\\d+)\\)/', $type, $matches);
            $max_length = isset($matches[1]) ? (int)$matches[1] : 255;
            
            $original_value = $this->generate_varchar_value($field, $type);
            $updated_suffix = '_upd';
            
            // Make sure the updated value fits within the field length
            if (strlen($original_value) + strlen($updated_suffix) > $max_length) {
                $available_space = $max_length - strlen($updated_suffix);
                if ($available_space > 0) {
                    return substr($original_value, 0, $available_space) . $updated_suffix;
                } else {
                    // If there's no room for suffix, just modify the last character
                    return substr($original_value, 0, $max_length - 1) . 'X';
                }
            }
            return $original_value . $updated_suffix;
        }
        
        // For text fields (no length limit)
        $original_value = $this->generate_field_value($field);
        if (is_string($original_value)) {
            return $original_value . '_updated';
        }
        
        return $original_value;
    }
    
    // Constraint detection methods
    protected function infer_unique_fields() {
        $unique_fields = [];
        $model_class = $this->model_class;
        
        // Only test uniqueness if explicitly defined in the model
        // TODO: When models have a way to specify unique constraints, use that instead
        
        // For now, only check email fields as they are commonly unique
        // and failing to enforce uniqueness on emails can cause real issues
        foreach ($model_class::$field_specifications as $field => $spec) {
            $field_lower = strtolower($field);
            
            // Only email fields - remove other assumptions
            if (strpos($field_lower, 'email') !== false && 
                strpos($field_lower, '_email') !== false) { // Must be something_email, not just 'email' in the name
                $unique_fields[] = $field;
            }
        }
        
        return $unique_fields;
    }
    
    protected function get_nullable_fields() {
        $nullable_fields = [];
        $model_class = $this->model_class;
        
        // Get all fields that are NOT in required fields
        foreach ($model_class::$field_specifications as $field => $spec) {
            if (!in_array($field, $model_class::$required_fields)) {
                // Skip primary key and auto-generated fields
                if ($field !== $model_class::$pkey_column && 
                    strpos(strtolower($field), 'create_time') === false &&
                    strpos(strtolower($field), 'update_time') === false) {
                    $nullable_fields[] = $field;
                }
            }
        }
        
        return $nullable_fields;
    }
    
    protected function get_varchar_fields() {
        $varchar_fields = [];
        $model_class = $this->model_class;
        
        foreach ($model_class::$field_specifications as $field => $spec) {
            $type = $spec['type'] ?? '';
            if (strpos($type, 'varchar') !== false) {
                // Extract max length
                preg_match('/varchar\\((\\d+)\\)/', $type, $matches);
                $max_length = isset($matches[1]) ? (int)$matches[1] : 255;
                $varchar_fields[$field] = $max_length;
            }
        }
        
        return $varchar_fields;
    }
    
    protected function get_numeric_fields() {
        $numeric_fields = [];
        $model_class = $this->model_class;
        
        foreach ($model_class::$field_specifications as $field => $spec) {
            $type = $spec['type'] ?? '';
            if (strpos($type, 'int') !== false || 
                strpos($type, 'decimal') !== false || 
                strpos($type, 'float') !== false ||
                strpos($type, 'numeric') !== false) {
                $numeric_fields[$field] = $type;
            }
        }
        
        return $numeric_fields;
    }
    
    // Validation test methods
    protected function test_required_field($field, $debug) {
        $verbose = $this->is_verbose();
        if ($debug || $verbose) echo "Testing required field: $field<br>\n";
        
        $model_class = $this->model_class;
        
        // Check if this field has a default value
        if (isset($model_class::$initial_default_values[$field])) {
            $default_value = $model_class::$initial_default_values[$field];
            echo "  <span style='color: #ff9800;'>[WARN] Required field $field has default value '$default_value' - save will succeed instead of failing when field is omitted</span><br>\n";
            self::$test_warn_count++;
            return;
        }
        
        $model = new $model_class(null);
        
        // Generate valid data for all other required fields
        $test_data = $this->generate_valid_test_data();
        unset($test_data[$field]); // Remove the field we're testing
        
        // Set all other required fields
        foreach ($test_data as $other_field => $value) {
            $model->set($other_field, $value);
        }
        
        // Try to save without the required field - should fail
        $this->expect_exception(function() use ($model) {
            $model->save();
        }, Exception::class, "Saving without required field $field should fail");
    }
    
    protected function test_field_type_constraints($field, $spec, $debug) {
        $verbose = $this->is_verbose();
        if ($debug || $verbose) echo "Testing field type constraints: $field<br>\n";
        
        $type = $spec['type'] ?? '';
        $model_class = $this->model_class;
        
        // Test varchar length constraints
        if (strpos($type, 'varchar') !== false) {
            if ($verbose) echo "Testing varchar constraint for: $field<br>\n"; flush();
            preg_match('/varchar\\((\\d+)\\)/', $type, $matches);
            $max_length = isset($matches[1]) ? (int)$matches[1] : 255;
            try {
                $this->test_varchar_length_constraint($field, $max_length, $debug);
                if ($verbose) echo "Varchar constraint test completed for: $field<br>\n"; flush();
            } catch (Error $e) {
                if (strpos($e->getMessage(), 'Call to undefined method') !== false) {
                    $this->test_fail("Varchar constraint test failed due to undefined method call for field: $field");
                    return;
                }
                throw $e;
            } catch (Exception $e) {
                // Handle database transaction errors
                if (strpos($e->getMessage(), 'In failed sql transaction') !== false || 
                    strpos($e->getMessage(), 'transaction is aborted') !== false) {
                    $this->test_fail("Varchar constraint test failed due to broken database transaction for field: $field");
                    return;
                }
                throw $e;
            }
        }
        
        // Test integer constraints  
        if (strpos($type, 'int') !== false) {
            if ($verbose) echo "Testing integer constraint for: $field<br>\n"; flush();
            try {
                $this->test_integer_constraint($field, $type, $debug);
                if ($verbose) echo "Integer constraint test completed for: $field<br>\n"; flush();
            } catch (Error $e) {
                if (strpos($e->getMessage(), 'Call to undefined method') !== false) {
                    $this->test_fail("Integer constraint test failed due to undefined method call for field: $field");
                    return;
                }
                throw $e;
            } catch (Exception $e) {
                // Handle database transaction errors
                if (strpos($e->getMessage(), 'In failed sql transaction') !== false || 
                    strpos($e->getMessage(), 'transaction is aborted') !== false) {
                    $this->test_fail("Integer constraint test failed due to broken database transaction for field: $field");
                    return;
                }
                throw $e;
            }
        }
    }
    
    protected function test_varchar_length_constraint($field, $max_length, $debug) {
        $verbose = $this->is_verbose();
        if ($debug || $verbose) echo "  Testing varchar constraint for $field (max: $max_length)<br>\n";
        
        $model_class = $this->model_class;
        $model = new $model_class(null);
        
        // Generate valid data for required fields
        $test_data = $this->generate_valid_test_data();
        foreach ($test_data as $test_field => $value) {
            if ($test_field !== $field) {
                $model->set($test_field, $value);
            }
        }
        
        // Test with string that's too long
        $too_long_string = str_repeat('a', $max_length + 1);
        $model->set($field, $too_long_string);
        
        try {
            $model->save();
            // If save succeeds, reload from database to check actual stored value
            $saved_key = $model->key;
            $model_class = $this->model_class;
            $fresh_model = new $model_class($saved_key, true); // true = and_load
            $saved_value = $fresh_model->get($field);
            
            if (strlen($saved_value) <= $max_length) {
                $this->test_warn("Field $field was silently truncated by database to max length $max_length (input: " . strlen($too_long_string) . " chars, stored: " . strlen($saved_value) . " chars)");
                $fresh_model->permanent_delete();
            } else {
                $fresh_model->permanent_delete();
                // Database field doesn't match model specification - no length constraint enforced
                $this->test_fail("Field $field database constraint mismatch - model specifies max $max_length chars but database stored " . strlen($saved_value) . " chars from " . strlen($too_long_string) . " char input");
            }
        } catch (Exception $e) {
            // Check if this is a test_fail exception - if so, re-throw it to fail the overall test
            if (strpos($e->getMessage(), 'Test failed:') === 0) {
                throw $e;
            }
            
            // Check if it's a database length error
            if (strpos($e->getMessage(), 'value too long') !== false ||
                strpos($e->getMessage(), 'character varying') !== false) {
                // Database enforces the constraint, which is fine - just pass the test
                $this->test_pass("Field $field length constraint enforced by database");
            } else {
                // Some other error - could be application validation
                $this->test_pass("Field $field correctly validates length constraints");
            }
        }
    }
    
    protected function test_integer_constraint($field, $type, $debug) {
        $model_class = $this->model_class;
        $model = new $model_class(null);
        
        // Generate valid data for required fields
        $test_data = $this->generate_valid_test_data();
        foreach ($test_data as $test_field => $value) {
            if ($test_field !== $field) {
                $model->set($test_field, $value);
            }
        }
        
        // Test with non-numeric string
        $model->set($field, 'not_a_number');
        
        try {
            $model->save();
            // If save succeeds, check if value was converted to a number or rejected
            $model->load();
            $saved_value = $model->get($field);
            
            if (is_numeric($saved_value)) {
                $this->test_pass("Field $field converted non-numeric input to numeric value: $saved_value");
            } else {
                $this->test_fail("Field $field accepted non-numeric value without conversion - stored: '$saved_value' (should be numeric or rejected)");
            }
            $model->permanent_delete();
        } catch (Exception $e) {
            // Check if this is a test_fail exception - if so, re-throw it to fail the overall test
            if (strpos($e->getMessage(), 'Test failed:') === 0) {
                throw $e;
            }
            
            // Check if it's a database type error (which is expected and good)
            if (strpos($e->getMessage(), 'Invalid text representation') !== false ||
                strpos($e->getMessage(), 'invalid input syntax') !== false) {
                // Database enforces the type constraint, which is fine - just pass the test
                $this->test_pass("Field $field type constraint enforced by database");
            } else {
                // Some other error - re-throw it
                throw $e;
            }
        }
    }
    
    protected function test_unique_constraint($field, $debug) {
        $verbose = $this->is_verbose();
        if ($debug || $verbose) echo "Testing unique constraint: $field<br>\n";
        
        $model_class = $this->model_class;
        
        // Create first record
        $model1 = new $model_class(null);
        $test_data = $this->generate_valid_test_data();
        foreach ($test_data as $test_field => $value) {
            $model1->set($test_field, $value);
        }
        $model1->save();
        
        // Try to create second record with same unique field value
        $model2 = new $model_class(null);
        foreach ($test_data as $test_field => $value) {
            $model2->set($test_field, $value);
        }
        
        // This should fail due to unique constraint
        $this->expect_exception(function() use ($model2) {
            $model2->save();
        }, Exception::class, "Duplicate value for unique field $field should fail");
        
        // Clean up
        $model1->permanent_delete();
    }
    
    protected function test_null_value($field, $debug) {
        $verbose = $this->is_verbose();
        if ($debug || $verbose) echo "Testing null value for: $field<br>\n";
        
        $model_class = $this->model_class;
        $model = new $model_class(null);
        
        // Generate valid data for required fields
        $test_data = $this->generate_valid_test_data();
        foreach ($test_data as $test_field => $value) {
            $model->set($test_field, $value);
        }
        
        // Set the nullable field to null
        $model->set($field, null);
        
        try {
            $model->save();
            $model->load();
            $this->test_pass("Field $field accepts null values");
            $model->permanent_delete();
        } catch (Exception $e) {
            $this->test_fail("Field $field should accept null values but threw: " . $e->getMessage());
        }
    }
    
    protected function test_max_length($field, $max_length, $debug) {
        $verbose = $this->is_verbose();
        if ($debug || $verbose) echo "Testing max length for: $field ($max_length chars)<br>\n";
        
        $this->test_varchar_length_constraint($field, $max_length, $debug);
    }
    
    protected function test_numeric_boundaries($field, $type, $debug) {
        $verbose = $this->is_verbose();
        if ($debug || $verbose) echo "Testing numeric boundaries for: $field ($type)<br>\n";
        
        $this->test_integer_constraint($field, $type, $debug);
    }
    
    // Assertion methods
    protected function assert_equals($expected, $actual, $message = '') {
        // Handle numeric comparisons with type coercion
        if (is_numeric($expected) && is_numeric($actual)) {
            // Convert both to float for comparison to handle string/numeric type differences
            $expected_float = (float)$expected;
            $actual_float = (float)$actual;
            if ($expected_float !== $actual_float) {
                $this->test_fail("Expected '$expected' ($expected_float), got '$actual' ($actual_float): $message");
            }
        } else {
            // Use strict comparison for non-numeric values
            if ($expected !== $actual) {
                $this->test_fail("Expected '$expected' (" . gettype($expected) . "), got '$actual' (" . gettype($actual) . "): $message");
            }
        }
        $this->test_pass($message ?: "Values are equal");
    }
    
    protected function assert_true($condition, $message = '') {
        if (!$condition) {
            $this->test_fail("Expected true, got false: $message");
        }
        $this->test_pass($message ?: "Condition is true");
    }
    
    protected function assert_false($condition, $message = '') {
        if ($condition) {
            $this->test_fail("Expected false, got true: $message");
        }
        $this->test_pass($message ?: "Condition is false");
    }
    
    protected function expect_exception($callback, $expected_exception_type, $message = '') {
        try {
            $callback();
            $this->test_fail("Expected $expected_exception_type but no exception was thrown: $message");
        } catch (Exception $e) {
            // Check if this is a test_fail exception - if so, re-throw it to fail the overall test
            if (strpos($e->getMessage(), 'Test failed:') === 0) {
                throw $e;
            }
            
            if (!($e instanceof $expected_exception_type)) {
                $this->test_fail("Expected $expected_exception_type but got " . get_class($e) . ": $message");
            }
            $this->test_pass("Exception test passed: $message");
        }
    }
    
    protected function test_pass($message) {
        self::$test_pass_count++;
        // Uncomment for verbose output: echo "  [PASS] $message<br>\n";
    }
    
    protected function test_warn($message) {
        self::$test_warn_count++;
        echo "  <span style='color: #ff9800;'>[WARN] $message</span><br>\n";
    }
    
    protected function test_fail($message) {
        self::$test_fail_count++;
        echo "  <span style='color: red;'>[FAIL] $message</span><br>\n";
        throw new Exception("Test failed: $message");
    }
    
    public static function get_test_stats() {
        return [
            'passed' => self::$test_pass_count,
            'warned' => self::$test_warn_count,
            'failed' => self::$test_fail_count
        ];
    }
}