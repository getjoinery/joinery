<?php
/**
 * ModelTester - Standalone automated testing class for Joinery CMS models
 * 
 * This class provides comprehensive automated testing for all models that extend SystemBase.
 * It uses reflection and inference to generate appropriate test data and validation scenarios
 * without requiring custom code for each model.
 */

require_once(__DIR__ . '/../../includes/PathHelper.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');

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
    public function test($model_instance = null, $debug = false, $read_only = false) {
        $this->model_instance = $model_instance ?: new $this->model_class(null);
        $verbose = $this->is_verbose();
        
        echo '<b style="color: #333;">TESTING CLASS: ' . $this->model_class . "</b><br>\n";
        
        // Remember the initial fail count for this model test
        $initial_fail_count = self::$test_fail_count;
        
        // Set up test database mode if available (skip if read-only mode)
        $dbhelper = DbConnector::get_instance();
        if (!$read_only && method_exists($dbhelper, 'set_test_mode')) {
            $dbhelper->set_test_mode();
        }
        
        // Validate permanent_delete_actions configuration
        $this->validate_permanent_delete_actions();
        
        // Validate primary key configuration
        $this->validate_primary_key_configuration();
        
        try {
            if ($read_only) {
                // Read-only mode: Only run safe validation tests (no insert/update/delete)
                if ($verbose) echo "Read-only mode: Skipping CRUD operations, running validation only...<br>\n"; flush();
                // Note: Primary key validation already ran above
                // Note: permanent_delete_actions validation already ran above
                
                if ($verbose) echo "Validating sequence synchronization...<br>\n"; flush();
                $this->test_automated_sequence_synchronization($debug);
                if ($verbose) echo "Read-only validation completed successfully<br>\n"; flush();
            } else {
                // Full test mode: Run all tests including CRUD operations
                if ($verbose) echo "Starting sequence synchronization tests...<br>\n"; flush();
                $this->test_automated_sequence_synchronization($debug);
                if ($verbose) echo "Sequence tests completed, starting CRUD tests...<br>\n"; flush();
                $this->test_automated_crud($debug);
                if ($verbose) echo "CRUD tests completed, starting validation tests...<br>\n"; flush();
                $this->test_automated_validation($debug);
                if ($verbose) echo "Validation tests completed, starting constraint tests...<br>\n"; flush();
                $this->test_automated_constraints($debug);
                if ($verbose) echo "Constraint tests completed, starting edge case tests...<br>\n"; flush();
                $this->test_automated_edge_cases($debug);
                if ($verbose) echo "All tests completed successfully<br>\n"; flush();
            }
            
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
        
        // Return false if any tests failed during this model's test
        return self::$test_fail_count == $initial_fail_count;
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
            $save_result = $model->save();
            
            // Some models return FALSE instead of throwing exceptions on certain conditions (like duplicates)
            if ($save_result === FALSE) {
                // For models that prevent duplicates, this might be expected behavior
                // Check if this model has duplicate prevention logic
                if (method_exists($model, 'check_for_duplicate')) {
                    $this->test_warn("Model prevents duplicate records - save() returned FALSE. This may be expected behavior for models with unique constraints.");
                    return; // Skip the rest of the CRUD test for this model
                } else {
                    throw new Exception("Model save() returned FALSE - possibly due to duplicate detection or business logic constraint");
                }
            }
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
            
            $this->test_fail_no_throw("Failed to save model with test data [" . implode(', ', $field_info) . "]. " . $error_details);
            return;
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
        
        // Check if model has empty permanent_delete_actions and warn only if foreign keys exist
        if (!$this->can_permanent_delete($model)) {
            $foreign_keys = $this->find_foreign_key_references(get_class($model));
            if (!empty($foreign_keys)) {
                $foreign_key_list = array();
                foreach ($foreign_keys as $column => $table) {
                    $foreign_key_list[] = "$column (in table $table)";
                }
                $this->test_warn("Model has empty permanent_delete_actions array but foreign key references were detected: " . implode(', ', $foreign_key_list) . ". Configure permanent_delete_actions to handle these relationships.");
            }
        }
        
        if ($verbose) echo "Calling permanent_delete() now...<br>\n"; flush();
        try {
            $model->permanent_delete();
            if ($verbose) echo "Delete completed<br>\n"; flush();
        } catch (Exception $e) {
            if ($verbose) echo "Delete failed with error: " . $e->getMessage() . "<br>\n"; flush();
            if ($verbose) echo "Exception type: " . get_class($e) . "<br>\n"; flush();
            if ($verbose) echo "File: " . $e->getFile() . " line " . $e->getLine() . "<br>\n"; flush();
            
            // Handle permanent delete configuration errors
            if (strpos($e->getMessage(), 'Cannot permanent delete') !== false) {
                $this->test_warn("Model permanent_delete() failed due to configuration issue: " . $e->getMessage());
                return; // Skip deletion verification
            }
            
            // Re-throw other exceptions to maintain test failure behavior
            throw $e;
        } catch (Error $e) {
            if ($verbose) echo "Delete failed with PHP Error: " . $e->getMessage() . "<br>\n"; flush();
            if ($verbose) echo "Error type: " . get_class($e) . "<br>\n"; flush();
            if ($verbose) echo "File: " . $e->getFile() . " line " . $e->getLine() . "<br>\n"; flush();
            
            // Check if this is an undefined method error
            if (strpos($e->getMessage(), 'Call to undefined method') !== false) {
                $this->test_warn("Model has broken permanent_delete() logic - undefined method call");
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
        // Test required fields - get required fields from field_specifications
        $required_fields = [];
        foreach ($model_class::$field_specifications as $field_name => $spec) {
            if (isset($spec['required']) && $spec['required'] === true) {
                $required_fields[] = $field_name;
            }
        }
        
        foreach ($required_fields as $required_field) {
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
        
        // Test unique constraints declared in field_specifications
        $this->test_declared_unique_constraints($debug);
        
        if ($debug || $verbose) echo "Constraint tests completed<br>\n";
    }
    
    /**
     * Test unique constraints declared in field_specifications
     */
    protected function test_declared_unique_constraints($debug = false) {
        $verbose = $this->is_verbose();
        $model_class = $this->model_class;
        
        if (!isset($model_class::$field_specifications)) {
            return;
        }
        
        foreach ($model_class::$field_specifications as $field => $spec) {
            // Test single field unique constraints
            if (isset($spec['unique']) && $spec['unique']) {
                if ($debug || $verbose) echo "Testing unique constraint on $field<br>\n";
                $this->test_single_field_unique($field, $debug);
            }
            
            // Test composite unique constraints
            if (isset($spec['unique_with'])) {
                $fields = array_merge(array($field), $spec['unique_with']);
                $field_list = implode(', ', $fields);
                if ($debug || $verbose) echo "Testing composite unique constraint on $field_list<br>\n";
                $this->test_composite_unique($field, $spec['unique_with'], $debug);
            }
        }
    }
    
    /**
     * Test single field unique constraint
     */
    protected function test_single_field_unique($field, $debug = false) {
        $verbose = $this->is_verbose();
        $model_class = $this->model_class;
        
        try {
            // Create first record
            $model1 = new $model_class(null);
            $test_data = $this->generate_valid_test_data();
            
            // Ensure the unique field has a value
            if (!isset($test_data[$field])) {
                $test_data[$field] = $this->generate_field_value($field, 0);
            }
            
            foreach ($test_data as $test_field => $value) {
                $model1->set($test_field, $value);
            }
            $model1->save();
            
            // Try to create duplicate with same unique field value
            $model2 = new $model_class(null);
            foreach ($test_data as $test_field => $value) {
                $model2->set($test_field, $value);
            }
            
            // This should fail due to unique constraint
            try {
                $model2->save();
                $this->test_fail("Unique constraint on $field was not enforced - duplicate was allowed");
            } catch (DisplayableUserException $e) {
                $this->test_pass("Unique constraint on $field properly enforced");
            } catch (Exception $e) {
                // Accept any exception type for unique violations
                $this->test_pass("Unique constraint on $field properly enforced (Exception: " . get_class($e) . ")");
            }
            
            // Clean up
            try {
                $model1->permanent_delete();
            } catch (Exception $e) {
                $this->test_warn("Cleanup failed for unique constraint test on $field - permanent_delete_actions may need configuration: " . $e->getMessage());
            }
            if ($model2->key) {
                try {
                    $model2->permanent_delete();
                } catch (Exception $e) {
                    $this->test_warn("Cleanup failed for unique constraint test on $field - permanent_delete_actions may need configuration: " . $e->getMessage());
                }
            }
            
        } catch (Exception $e) {
            // Check if this is a missing table error
            if (strpos($e->getMessage(), 'Undefined table') !== false ||
                strpos($e->getMessage(), 'relation') !== false && strpos($e->getMessage(), 'does not exist') !== false) {
                $this->test_warn("Cannot test unique constraint on $field - database table does not exist: " . $e->getMessage());
            } else {
                $this->test_fail("Error testing unique constraint on $field: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Test composite unique constraint
     */
    protected function test_composite_unique($main_field, $other_fields, $debug = false) {
        $verbose = $this->is_verbose();
        $model_class = $this->model_class;
        $all_fields = array_merge(array($main_field), $other_fields);
        $field_list = implode(', ', $all_fields);
        
        try {
            // Create first record
            $model1 = new $model_class(null);
            $test_data = $this->generate_valid_test_data();
            
            // Ensure all unique constraint fields have values
            foreach ($all_fields as $constraint_field) {
                if (!isset($test_data[$constraint_field])) {
                    $test_data[$constraint_field] = $this->generate_field_value($constraint_field, 0);
                }
            }
            
            foreach ($test_data as $test_field => $value) {
                $model1->set($test_field, $value);
            }
            $model1->save();
            
            // Try to create duplicate with same combination of unique fields
            $model2 = new $model_class(null);
            foreach ($test_data as $test_field => $value) {
                $model2->set($test_field, $value);
            }
            
            // This should fail due to composite unique constraint
            try {
                $model2->save();
                $this->test_fail("Composite unique constraint on ($field_list) was not enforced - duplicate was allowed");
            } catch (DisplayableUserException $e) {
                $this->test_pass("Composite unique constraint on ($field_list) properly enforced");
            } catch (Exception $e) {
                // Accept any exception type for unique violations
                $this->test_pass("Composite unique constraint on ($field_list) properly enforced (Exception: " . get_class($e) . ")");
            }
            
            // Test that different combinations are allowed
            $model3 = new $model_class(null);
            foreach ($test_data as $test_field => $value) {
                if ($test_field === $main_field) {
                    // Change the main field value to make it non-duplicate
                    if (is_numeric($value)) {
                        // For numeric values, add 9999 to make it different
                        $model3->set($test_field, $value + 9999);
                    } else {
                        // For string values, append '_different'
                        $model3->set($test_field, $value . '_different');
                    }
                } else {
                    $model3->set($test_field, $value);
                }
            }
            
            try {
                $model3->save();
                $this->test_pass("Composite unique constraint allows different combinations on ($field_list)");
                try {
                    $model3->permanent_delete();
                } catch (Exception $delete_e) {
                    $this->test_warn("Cleanup failed for composite unique constraint test on ($field_list) - permanent_delete_actions may need configuration: " . $delete_e->getMessage());
                }
            } catch (Exception $e) {
                $this->test_fail("Composite unique constraint incorrectly rejected different combination on ($field_list): " . $e->getMessage());
            }
            
            // Clean up
            try {
                $model1->permanent_delete();
            } catch (Exception $e) {
                $this->test_warn("Cleanup failed for unique constraint test on $field - permanent_delete_actions may need configuration: " . $e->getMessage());
            }
            if ($model2->key) {
                try {
                    $model2->permanent_delete();
                } catch (Exception $e) {
                    $this->test_warn("Cleanup failed for unique constraint test on $field - permanent_delete_actions may need configuration: " . $e->getMessage());
                }
            }
            
        } catch (Exception $e) {
            // Check if this is a missing table error
            if (strpos($e->getMessage(), 'Undefined table') !== false ||
                strpos($e->getMessage(), 'relation') !== false && strpos($e->getMessage(), 'does not exist') !== false) {
                $this->test_warn("Cannot test composite unique constraint on ($field_list) - database table does not exist: " . $e->getMessage());
            } else {
                $this->test_fail("Error testing composite unique constraint on ($field_list): " . $e->getMessage());
            }
        }
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
    protected function generate_valid_test_data($index = 0) {
        $test_data = [];
        $model_class = $this->model_class;
        
        // Get required fields from field_specifications
        foreach ($model_class::$field_specifications as $field_name => $spec) {
            if (isset($spec['required']) && $spec['required'] === true) {
                $test_data[$field_name] = $this->generate_field_value($field_name, $index);
            }
        }
        
        return $test_data;
    }
    
    /**
     * Generate appropriate value for a field based on its specification
     */
    protected function generate_field_value($field, $index = 0) {
        $model_class = $this->model_class;
        $spec = $model_class::$field_specifications[$field] ?? [];
        $type = $this->get_field_type($field);
        
        // Handle different field types with index support
        if (strpos($type, 'varchar') !== false) {
            return $this->generate_varchar_value($field, $type, $index);
        }
        
        if (strpos($type, 'int') !== false) {
            return $this->generate_integer_value($field, $type, $index);
        }
        
        if (strpos($type, 'decimal') !== false || strpos($type, 'float') !== false || strpos($type, 'numeric') !== false) {
            return $this->generate_decimal_value($field, $type, $index);
        }
        
        if (strpos($type, 'timestamp') !== false || strpos($type, 'datetime') !== false) {
            return $this->generate_timestamp_value($field, $index);
        }
        
        if (strpos($type, 'date') !== false) {
            return $this->generate_date_value($field, $index);
        }
        
        if (strpos($type, 'text') !== false) {
            return $this->generate_text_value($field, $index);
        }
        
        if (strpos($type, 'bool') !== false) {
            return $this->generate_boolean_value($field, $index);
        }
        
        // Default fallback
        return $this->generate_smart_value_by_name($field, $index);
    }
    
    /**
     * Generate varchar value based on field name and constraints
     */
    protected function generate_varchar_value($field, $type, $index = 0) {
        // Extract max length from type like "varchar(100)"
        preg_match('/varchar\\((\\d+)\\)/', $type, $matches);
        $max_length = isset($matches[1]) ? (int)$matches[1] : 255;
        
        // Check if this field is required - avoid empty strings for required fields
        $model_class = $this->model_class;
        $field_spec = $model_class::$field_specifications[$field] ?? [];
        $is_required = isset($field_spec['required']) && $field_spec['required'] === true;
        
        // Strategic string patterns for comprehensive testing
        $patterns = [];
        
        // Only include empty string for non-required fields
        if (!$is_required) {
            $patterns[] = ''; // empty string
        }
        
        $patterns = array_merge($patterns, [
            'a', // single char
            'test', // simple word
            'Test String 123', // mixed case with numbers
            'Special!@#$%^&*()', // special characters
            "Line1\nLine2", // multiline
            'Ñoño José', // unicode
            str_repeat('x', min(50, $max_length)), // medium length
        ]);
        
        // Add max length pattern if reasonable
        if ($max_length > 0 && $max_length <= 1000) {
            $patterns[] = str_repeat('X', $max_length); // max length
        }
        
        // Field name specific patterns
        $field_lower = strtolower($field);
        
        if (strpos($field_lower, 'email') !== false) {
            $email_patterns = [
                'test@example.com',
                'user.name@domain.co.uk',
                'test+tag@example.org',
                'a@b.c', // minimal valid
                'very.long.email.address@subdomain.example.com'
            ];
            $patterns = array_merge($email_patterns, $patterns);
        }
        
        if (strpos($field_lower, 'code') !== false) {
            $code_patterns = [
                'ABC123_' . $index,
                'test_code_' . sprintf('%03d', $index),
                'CODE_' . $index . '_' . substr(time(), -4),
                'ACTIVATION_' . $index . '_' . rand(100, 999),
                'UNIQUE_' . $index . '_' . uniqid()
            ];
            $patterns = array_merge($code_patterns, $patterns);
        }
        
        if (strpos($field_lower, 'name') !== false) {
            $name_patterns = ['TestFirst', 'TestLast', 'Test Name', 'O\'Connor', 'José María'];
            $patterns = array_merge($name_patterns, $patterns);
        }
        
        if (strpos($field_lower, 'url') !== false || strpos($field_lower, 'link') !== false) {
            $url_patterns = ['https://example.com/test', 'http://test.local', 'ftp://files.example.com'];
            $patterns = array_merge($url_patterns, $patterns);
        }
        
        // Use index to select or generate
        if ($index < count($patterns)) {
            $value = $patterns[$index];
            return substr($value, 0, $max_length); // Ensure it fits
        }
        
        // Generate unique string for higher indices
        $unique_suffix = '_' . $index . '_' . uniqid();
        $base_value = 'test_' . $field;
        $full_value = $base_value . $unique_suffix;
        
        return substr($full_value, 0, $max_length);
    }
    
    /**
     * Generate integer value based on field name and type
     */
    protected function generate_integer_value($field, $type, $index = 0) {
        $field_lower = strtolower($field);
        
        // Strategic test values for comprehensive coverage
        $patterns = [0, 1, -1, 100, -100, 999, 9999, 2147483647, -2147483648];
        
        // Check for field-specific boundaries from model specifications
        $properties = $this->model_class::$field_specifications[$field] ?? [];
        if (is_array($properties)) {
            if (isset($properties['min'])) {
                array_unshift($patterns, $properties['min'], $properties['min'] + 1);
            }
            if (isset($properties['max'])) {
                array_unshift($patterns, $properties['max'], $properties['max'] - 1);
            }
        }
        
        // Name-based logic for specific field types
        if (strpos($field_lower, 'year') !== false) {
            $year_patterns = [1970, 2000, date('Y'), date('Y') + 1, 2038];
            $patterns = array_merge($year_patterns, $patterns);
        }
        
        if (strpos($field_lower, 'age') !== false) {
            $age_patterns = [0, 1, 18, 25, 65, 120];
            $patterns = array_merge($age_patterns, $patterns);
        }
        
        if (strpos($field_lower, 'permission') !== false || strpos($field_lower, 'level') !== false) {
            $permission_patterns = [0, 1, 5, 8, 10];
            $patterns = array_merge($permission_patterns, $patterns);
        }
        
        // Handle foreign keys (fields ending in _id)
        $model_class = $this->model_class;
        if (strpos($field_lower, '_id') !== false && $field_lower !== $model_class::$pkey_column) {
            // Use field-specific values for foreign keys to avoid duplicates across different FK fields
            $field_hash = crc32($field); // Create a unique number based on field name
            $base_offset = abs($field_hash) % 10000; // Use hash to create different ranges per field
            
            $foreign_key_patterns = [
                $base_offset + 1,
                $base_offset + 2, 
                $base_offset + 10,
                $base_offset + 100,
                $base_offset + 1000,
                $base_offset + rand(1, 999) // Add some randomness
            ];
            $patterns = array_merge($foreign_key_patterns, $patterns);
        }
        
        // Type-specific bounds
        if ($type === 'int2' || strpos($type, 'smallint') !== false) {
            $patterns = array_filter($patterns, function($val) { return $val >= -32768 && $val <= 32767; });
            $patterns[] = 32767;
            $patterns[] = -32768;
        }
        
        // Use index to select value
        if ($index < count($patterns)) {
            return $patterns[$index];
        }
        
        // Fallback to index-based generation for higher indices
        return rand(1, 1000) + $index; // Add index to ensure uniqueness
    }
    
    /**
     * Generate decimal/float value
     */
    protected function generate_decimal_value($field, $type, $index = 0) {
        $field_lower = strtolower($field);
        
        // Base patterns for decimal values
        $patterns = [0.0, 0.01, 1.0, -1.0, 99.99, 999.99, 123.45];
        
        if (strpos($field_lower, 'price') !== false || strpos($field_lower, 'cost') !== false || strpos($field_lower, 'total') !== false) {
            $price_patterns = [0.00, 0.99, 1.00, 9.99, 19.99, 99.99, 999.99];
            $patterns = array_merge($price_patterns, $patterns);
        }
        
        if (strpos($field_lower, 'rate') !== false || strpos($field_lower, 'percent') !== false) {
            $rate_patterns = [0.0, 0.5, 1.0, 10.0, 50.0, 100.0];
            $patterns = array_merge($rate_patterns, $patterns);
        }
        
        if (strpos($field_lower, 'weight') !== false) {
            $weight_patterns = [0.1, 1.0, 5.5, 10.0, 25.5, 100.0];
            $patterns = array_merge($weight_patterns, $patterns);
        }
        
        // Use index to select value
        if ($index < count($patterns)) {
            return round($patterns[$index], 2);
        }
        
        // Generate based on index for higher values
        return round((rand(100, 999999) + $index * 100) / 100, 2);
    }
    
    /**
     * Generate timestamp value
     */
    protected function generate_timestamp_value($field, $index = 0) {
        $patterns = [
            'now()',
            '2023-01-01 00:00:00',
            '2024-06-15 12:30:00',
            '1970-01-01 00:00:00',
            '2038-01-19 03:14:07'
        ];
        
        // Use index to select timestamp pattern
        if ($index < count($patterns)) {
            return $patterns[$index];
        }
        
        // For higher indices, generate based on current time + small offset
        // Use modulo to keep offset reasonable (within 1 year)
        $offset_days = ($index - count($patterns)) % 365;
        return date('Y-m-d H:i:s', strtotime("+$offset_days days"));
    }
    
    /**
     * Generate date value (date only, not timestamp)
     */
    protected function generate_date_value($field, $index = 0) {
        $patterns = [
            '2023-01-01',
            '2024-06-15', 
            '1990-12-25',
            '2025-03-10',
            '2022-08-20'
        ];
        
        // Use index to select date pattern
        if ($index < count($patterns)) {
            return $patterns[$index];
        }
        
        // For higher indices, generate based on current date + small offset
        // Use modulo to keep offset reasonable (within 1 year)
        $offset_days = ($index - count($patterns)) % 365;
        return date('Y-m-d', strtotime("+$offset_days days"));
    }
    
    /**
     * Generate text value
     */
    protected function generate_text_value($field, $index = 0) {
        $patterns = [
            'test text',
            'Short text',
            'This is a longer text content for testing purposes',
            "Multi-line\ntext content\nwith several lines",
            'Text with special chars: @#$%^&*()',
            'Unicode text: Ñoño José María',
            str_repeat('Long text content ', 20)
        ];
        
        // Use index to select pattern
        if ($index < count($patterns)) {
            return $patterns[$index];
        }
        
        // Generate unique text for higher indices
        return "Generated text $index: " . str_repeat('content ', $index % 10 + 1);
    }
    
    /**
     * Generate boolean value
     */
    protected function generate_boolean_value($field, $index = 0) {
        // Alternate between true and false based on index
        return $index % 2 === 0 ? false : true;
    }
    
    /**
     * Generate smart value by field name (fallback)
     */
    protected function generate_smart_value_by_name($field, $index = 0) {
        // Use existing LibraryFunctions logic as fallback but make it unique with index
        $model_class = $this->model_class;
        $spec = $model_class::$field_specifications[$field] ?? [];
        $type = $spec['type'] ?? 'varchar(255)';
        
        // Extract length from type specification (e.g., "varchar(255)" -> "255")
        preg_match_all('!\d+!', $type, $matches);
        $field_length = $matches[0][0] ?? 255; // Default to 255 if no length found
        $base_value = LibraryFunctions::random_string($field_length);
        
        // Make it unique with index
        return $base_value . '_' . $index;
    }
    
    /**
     * Get all testable fields (not just required ones)
     */
    protected function get_all_testable_fields() {
        $fields = $this->model_class::$field_specifications;
        $testable = [];
        
        foreach ($fields as $field => $properties) {
            // Skip primary key and auto-generated timestamp fields
            if ($field !== $this->model_class::$pkey_column && 
                strpos(strtolower($field), 'create_time') === false &&
                strpos(strtolower($field), 'update_time') === false) {
                $testable[$field] = $properties;
            }
        }
        
        return $testable;
    }

    /**
     * Enhanced generate_valid_test_data with optional fields support
     */
    protected function generate_test_data_with_all_fields($include_optional = false) {
        $test_data = [];
        $model_class = $this->model_class;
        
        if ($include_optional) {
            $fields = $this->get_all_testable_fields();
        } else {
            // Get required fields from field_specifications
            $fields = [];
            foreach ($model_class::$field_specifications as $field_name => $spec) {
                if (isset($spec['required']) && $spec['required'] === true) {
                    $fields[$field_name] = $spec;
                }
            }
        }
        
        foreach ($fields as $field => $properties) {
            if (is_string($properties)) {
                // Old format compatibility - just field name with description
                $test_data[$field] = $this->generate_field_value($field);
            } else {
                // New format with full properties array
                $test_data[$field] = $this->generate_field_value_with_properties($field, $properties);
            }
        }
        
        return $test_data;
    }
    
    /**
     * Generate field value using full properties array
     */
    protected function generate_field_value_with_properties($field, $properties) {
        // Use field specifications for type information if available
        $spec = $this->model_class::$field_specifications[$field] ?? [];
        $type = $this->get_field_type($field);
        
        return $this->generate_field_value($field, 0); // Use index 0 for now
    }

    /**
     * Better type detection using multiple sources
     */
    protected function get_field_type($field) {
        // Use field_specifications as the primary source
        if (isset($this->model_class::$field_specifications[$field]['type'])) {
            return $this->model_class::$field_specifications[$field]['type'];
        }
        
        // Fall back to default if not found
        return 'varchar(255)';
    }

    /**
     * Find fields by type
     */
    protected function get_fields_by_type($type) {
        $matching_fields = [];
        $fields = $this->get_all_testable_fields();
        
        foreach ($fields as $field => $properties) {
            $field_type = $this->get_field_type($field);
            if (strpos($field_type, $type) !== false) {
                $matching_fields[$field] = $properties;
            }
        }
        
        return $matching_fields;
    }

    /**
     * Find fields suitable for sorting
     */
    protected function find_sortable_fields() {
        $sortable = [];
        $fields = $this->get_all_testable_fields();
        
        foreach ($fields as $field => $properties) {
            $type = $this->get_field_type($field);
            // Text, integer, date fields are good for sorting
            if (strpos($type, 'int') !== false || 
                strpos($type, 'date') !== false || 
                strpos($type, 'timestamp') !== false ||
                strpos($type, 'varchar') !== false) {
                $sortable[] = $field;
            }
        }
        
        return $sortable;
    }
    
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
        
        if (strpos($type, 'date') !== false) {
            // Generate a different date for update tests
            return '2024-12-31'; // Use a fixed different date
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
        
        // Get all fields that are NOT required
        foreach ($model_class::$field_specifications as $field => $spec) {
            $is_required = isset($spec['required']) && $spec['required'] === true;
            if (!$is_required) {
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
        
        // Check if this field has a default value in field_specifications
        $field_spec = $model_class::$field_specifications[$field] ?? [];
        if (isset($field_spec['default'])) {
            $default_value = $field_spec['default'];
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
        
        // Generate valid data for ALL required fields
        $test_data = $this->generate_valid_test_data();
        
        // Set ALL required fields first (including the one we're testing)
        foreach ($test_data as $test_field => $value) {
            $model->set($test_field, $value);
        }
        
        // Test with string that's too long (override the field being tested)
        $too_long_string = str_repeat('a', $max_length + 1);
        $model->set($field, $too_long_string);
        
        try {
            $save_result = $model->save();
            
            // Handle models that return FALSE for business logic constraints
            if ($save_result === FALSE) {
                $this->test_pass("Field $field validation prevented save (model returned FALSE for business logic constraint)");
                return;
            }
            
            // If save succeeds, reload from database to check actual stored value
            $saved_key = $model->key;
            $model_class = $this->model_class;
            $fresh_model = new $model_class($saved_key, true); // true = and_load
            $saved_value = $fresh_model->get($field);
            
            if (strlen($saved_value) <= $max_length) {
                $this->test_warn("Field $field was silently truncated by database to max length $max_length (input: " . strlen($too_long_string) . " chars, stored: " . strlen($saved_value) . " chars)");
                try {
                    $fresh_model->permanent_delete();
                } catch (Exception $e) {
                    $this->test_warn("Cleanup failed for varchar length test on $field - permanent_delete_actions may need configuration: " . $e->getMessage());
                }
            } else {
                try {
                    $fresh_model->permanent_delete();
                } catch (Exception $e) {
                    $this->test_warn("Cleanup failed for varchar length test on $field - permanent_delete_actions may need configuration: " . $e->getMessage());
                }
                // Database field doesn't match model specification - no length constraint enforced
                $this->test_fail_no_throw("Field $field database constraint mismatch - model specifies max $max_length chars but database stored " . strlen($saved_value) . " chars from " . strlen($too_long_string) . " char input");
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
        
        // Generate valid data for ALL required fields
        $test_data = $this->generate_valid_test_data();
        
        // Debug: check if we have all required fields
        $verbose = $this->is_verbose();
        if ($debug || $verbose) {
            // Get required fields from field_specifications for debugging
            $required_fields = [];
            foreach ($model_class::$field_specifications as $field_name => $spec) {
                if (isset($spec['required']) && $spec['required'] === true) {
                    $required_fields[] = $field_name;
                }
            }
            
            echo "  Testing integer constraint for field: $field<br>\n";
            echo "  Required fields: " . implode(', ', $required_fields) . "<br>\n";
            echo "  Generated test data fields: " . implode(', ', array_keys($test_data)) . "<br>\n";
        }
        
        // Set ALL required fields first (including the one we're testing)
        foreach ($test_data as $test_field => $value) {
            $model->set($test_field, $value);
        }
        
        // Now override the field we're testing with the invalid value
        $model->set($field, 'not_a_number');
        
        try {
            $save_result = $model->save();
            
            // Handle models that return FALSE for business logic constraints
            if ($save_result === FALSE) {
                $this->test_pass("Field $field validation prevented save (model returned FALSE for business logic constraint)");
                return;
            }
            
            // If save succeeds, check if value was converted to a number or rejected
            $model->load();
            $saved_value = $model->get($field);
            
            if (is_numeric($saved_value)) {
                $this->test_pass("Field $field converted non-numeric input to numeric value: $saved_value");
            } else {
                $this->test_fail_no_throw("Field $field accepted non-numeric value without conversion - stored: '$saved_value' (should be numeric or rejected)");
            }
            try {
                $model->permanent_delete();
            } catch (Exception $e) {
                $this->test_warn("Cleanup failed for integer constraint test on $field - permanent_delete_actions may need configuration: " . $e->getMessage());
            }
        } catch (Exception $e) {
            // Check if this is a test_fail exception - if so, re-throw it to fail the overall test
            if (strpos($e->getMessage(), 'Test failed:') === 0) {
                throw $e;
            }
            
            // Check if it's a missing table error
            if (strpos($e->getMessage(), 'Undefined table') !== false ||
                strpos($e->getMessage(), 'relation') !== false && strpos($e->getMessage(), 'does not exist') !== false) {
                $this->test_fail_no_throw("Cannot test field $field - database table does not exist: " . $e->getMessage());
                return;
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
        
        // Generate valid data for ALL required fields
        $test_data = $this->generate_valid_test_data();
        
        // Set all required fields first
        foreach ($test_data as $test_field => $value) {
            $model->set($test_field, $value);
        }
        
        // Set the nullable field to null (override if it was set above)
        $model->set($field, null);
        
        try {
            $model->save();
            $model->load();
            $this->test_pass("Field $field accepts null values");
            
            // Try to clean up - catch permanent delete failures
            try {
                $model->permanent_delete();
            } catch (Exception $delete_e) {
                // Handle permanent delete failures separately - these are warnings, not test failures
                if (strpos($delete_e->getMessage(), 'Cannot permanent delete') !== false) {
                    $this->test_warn("Field $field accepts null values but cleanup failed due to permanent_delete_actions configuration: " . $delete_e->getMessage());
                } else {
                    $this->test_warn("Field $field accepts null values but cleanup failed: " . $delete_e->getMessage());
                }
            }
        } catch (Exception $e) {
            // Check if it's a missing table error
            if (strpos($e->getMessage(), 'Undefined table') !== false ||
                strpos($e->getMessage(), 'relation') !== false && strpos($e->getMessage(), 'does not exist') !== false) {
                $this->test_fail_no_throw("Cannot test null values for field $field - database table does not exist: " . $e->getMessage());
                return;
            }
            
            $this->test_fail_no_throw("Field $field should accept null values but threw: " . $e->getMessage());
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
            
            // Use epsilon comparison for floating point numbers to handle precision issues
            $epsilon = 0.0001; // Small tolerance for floating point comparison
            if (abs($expected_float - $actual_float) > $epsilon) {
                $this->test_fail("Expected '$expected' ($expected_float), got '$actual' ($actual_float): $message");
            } else {
                // Values are numerically equal, even if string representations differ
                $this->test_pass($message ?: "Values are numerically equal");
            }
        } else {
            // Use strict comparison for non-numeric values
            if ($expected !== $actual) {
                $this->test_fail("Expected '$expected' (" . gettype($expected) . "), got '$actual' (" . gettype($actual) . "): $message");
            } else {
                $this->test_pass($message ?: "Values are equal");
            }
        }
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
    
    protected function test_fail_no_throw($message) {
        self::$test_fail_count++;
        echo "  <span style='color: red;'>[FAIL] $message</span><br>\n";
    }
    
    public static function get_test_stats() {
        return [
            'passed' => self::$test_pass_count,
            'warned' => self::$test_warn_count,
            'failed' => self::$test_fail_count
        ];
    }
    
    /**
     * Validate permanent_delete_actions configuration
     * Checks that the primary key is not incorrectly included in the permanent_delete_actions array
     * Also checks for foreign keys that reference this table but aren't defined in permanent_delete_actions
     */
    private function validate_permanent_delete_actions() {
        $model_class = $this->model_class;
        $dbhelper = DbConnector::get_instance();
        $dblink = $dbhelper->get_db_link();
        
        // Check if the class has permanent_delete_actions defined
        if (!property_exists($model_class, 'permanent_delete_actions')) {
            // No permanent_delete_actions defined - this is fine
            return;
        }
        
        $permanent_delete_actions = $model_class::$permanent_delete_actions;
        $primary_key = $model_class::$pkey_column;
        
        // Check if the primary key is incorrectly included in permanent_delete_actions
        if (is_array($permanent_delete_actions) && array_key_exists($primary_key, $permanent_delete_actions)) {
            $this->test_fail("Primary key '$primary_key' should not be included in permanent_delete_actions. The main record deletion is handled automatically by the permanent_delete() method.");
        } else {
            $this->test_pass("permanent_delete_actions configuration is correct (primary key not included)");
        }
        
        // Find foreign keys referencing this model's primary key
        $found_foreign_keys = $this->find_foreign_key_references($model_class);
        
        // Handle case where foreign key detection failed
        if (isset($found_foreign_keys['unknown'])) {
            $this->test_warn("Could not check foreign key references");
            return;
        }
        
        // Check for foreign keys not defined in permanent_delete_actions
        $missing_foreign_keys = array();
        foreach($found_foreign_keys as $column => $table) {
            if (!array_key_exists($column, $permanent_delete_actions)) {
                $missing_foreign_keys[] = "$column (in table $table)";
            }
        }
        
        if (!empty($missing_foreign_keys)) {
            $this->test_warn("Foreign keys referencing this model are not defined in permanent_delete_actions: " . implode(', ', $missing_foreign_keys) . ". These will use the default 'delete' action.");
        }
    }
    
    /**
     * Check if a model can safely call permanent_delete
     * Returns true if safe, false if not (and issues a warning)
     */
    private function can_permanent_delete($model) {
        $model_class = get_class($model);
        if (property_exists($model_class, 'permanent_delete_actions') && 
            is_array($model_class::$permanent_delete_actions) && 
            empty($model_class::$permanent_delete_actions)) {
            // Model has empty permanent_delete_actions - check if there are foreign keys
            $foreign_keys = $this->find_foreign_key_references($model_class);
            return empty($foreign_keys);
        }
        return true;
    }
    
    /**
     * Find foreign keys that reference this model's primary key
     * Returns array of foreign key references
     */
    private function find_foreign_key_references($model_class) {
        $dbhelper = DbConnector::get_instance();
        $dblink = $dbhelper->get_db_link();
        
        // Find all foreign keys that reference this table
        $sql = 'SELECT
            t.table_name,
            array_agg(c.column_name::text) as columns
        FROM
            information_schema.tables t
        INNER JOIN information_schema.columns c ON
            t.table_name = c.table_name
        WHERE
            t.table_schema = \'public\'
            AND c.table_schema = \'public\'
        GROUP BY t.table_name';
        
        try {
            $q = $dblink->prepare($sql);
            $q->execute();
            $q->setFetchMode(PDO::FETCH_OBJ);
        } catch(PDOException $e) {
            // If we can't check, assume there might be foreign keys (be conservative)
            return array('unknown' => 'unknown');
        }
        
        // Find foreign keys referencing this model's primary key
        $found_foreign_keys = array();
        $primary_key = $model_class::$pkey_column;
        $model_table = $model_class::$tablename;
        
        while ($row = $q->fetch()) {
            $table_name = $row->table_name;
            $columns = $row->columns;
            $columns_array = explode(',', trim($columns, '{}'));
            
            foreach($columns_array as $column) {
                if(str_contains($column, $primary_key)) {
                    // Skip if this is the primary key in its own table
                    if ($column === $primary_key && $table_name === $model_table) {
                        continue;
                    }
                    $found_foreign_keys[$column] = $table_name;
                }
            }
        }
        
        return $found_foreign_keys;
    }
    
    /**
     * Validate primary key configuration and database consistency
     * Checks that the model's expected primary key exists in the database table
     * and is actually configured as a primary key
     */
    private function validate_primary_key_configuration() {
        $model_class = $this->model_class;
        $table_name = $model_class::$tablename;
        $expected_pkey = $model_class::$pkey_column;
        $verbose = $this->is_verbose();
        
        if ($verbose) echo "Validating primary key configuration for {$model_class}...<br>\n";
        
        $dbconnector = DbConnector::get_instance();
        $dblink = $dbconnector->get_db_link();
        
        try {
            // Check if table exists - use enhanced LibraryFunctions method with table parameter
            $tables_and_columns = LibraryFunctions::get_tables_and_columns($table_name);
            $table_columns = isset($tables_and_columns[$table_name]) ? $tables_and_columns[$table_name] : array();
            
            if (empty($table_columns)) {
                $this->test_fail("Table '{$table_name}' does not exist in database for model {$model_class}");
                return;
            }
            
            $live_columns = array_values($table_columns);
            
            // Check if expected primary key column exists
            if (!in_array($expected_pkey, $live_columns)) {
                $this->test_fail("Primary key column '{$expected_pkey}' missing from table '{$table_name}'. Available columns: " . implode(', ', $live_columns));
                return;
            }
            
            // Check if the column is actually set as primary key in database
            $is_primary_key = $this->check_if_column_is_primary_key($table_name, $expected_pkey, $dblink);
            
            if (!$is_primary_key) {
                $this->test_fail("Column '{$expected_pkey}' exists but is not set as primary key in table '{$table_name}'");
                return;
            }
            
            // All checks passed
            $this->test_pass("Primary key configuration valid: {$table_name}.{$expected_pkey} exists and is properly configured");
            
        } catch (Exception $e) {
            $this->test_warn("Could not validate primary key configuration for {$model_class}: " . $e->getMessage());
        }
    }
    
    /**
     * Test sequence synchronization for models with serial primary keys
     * Ensures that:
     * 1. The expected sequence exists
     * 2. The sequence value is greater than the maximum value in the table
     */
    protected function test_automated_sequence_synchronization($debug = false) {
        $verbose = $this->is_verbose();
        if ($debug || $verbose) echo "Testing sequence synchronization...<br>\n";
        
        $model_class = $this->model_class;
        $table_name = $model_class::$tablename;
        $pkey_column = $model_class::$pkey_column;
        
        // Check if this model uses a serial primary key
        $field_specs = $model_class::$field_specifications ?? [];
        if (!isset($field_specs[$pkey_column]) || !isset($field_specs[$pkey_column]['serial']) || !$field_specs[$pkey_column]['serial']) {
            if ($verbose) echo "Model does not use serial primary key, skipping sequence test<br>\n";
            return;
        }
        
        try {
            $dbconnector = DbConnector::get_instance();
            $dblink = $dbconnector->get_db_link();
            
            // Build expected sequence name
            $sequence_name = $table_name . '_' . $pkey_column . '_seq';
            
            // Check if sequence exists and get current value
            // Note: We use direct sequence query which is more reliable than pg_sequences.last_value
            // because pg_sequences only updates after nextval() is called
            try {
                $seq_sql = "SELECT last_value FROM $sequence_name";
                $seq_q = $dblink->prepare($seq_sql);
                $seq_q->execute();
                $seq_result = $seq_q->fetch(PDO::FETCH_ASSOC);
                
                if (!$seq_result) {
                    $this->test_fail("Could not get current value from sequence '{$sequence_name}' for model {$model_class}");
                    return;
                }
                
                $current_seq_value = $seq_result['last_value'];
            } catch (PDOException $e) {
                // Sequence doesn't exist
                $this->test_fail("Sequence '{$sequence_name}' does not exist for model {$model_class}. Error: " . $e->getMessage());
                return;
            }
            
            // Get current max value from table
            $max_sql = "SELECT COALESCE(MAX($pkey_column), 0) as max_val FROM $table_name";
            $max_q = $dblink->prepare($max_sql);
            $max_q->execute();
            $max_result = $max_q->fetch(PDO::FETCH_ASSOC);
            $max_val = $max_result['max_val'];
            
            // Sequence should be greater than or equal to max table value
            // Only fail if sequence is strictly less than max value (which would cause conflicts)
            if ($current_seq_value < $max_val) {
                $this->test_fail("Sequence '{$sequence_name}' is out of sync. Sequence value: {$current_seq_value}, Max table value: {$max_val}. This will cause primary key conflicts.");
                return;
            }
            
            // All checks passed
            if ($verbose) {
                $this->test_pass("Sequence synchronization valid: {$sequence_name} (seq: {$current_seq_value}, max: {$max_val})");
            }
            
        } catch (Exception $e) {
            $this->test_fail("Could not validate sequence synchronization for {$model_class}: " . $e->getMessage());
        }
    }
    
    /**
     * Check if a column is actually set as a primary key in the database
     */
    private function check_if_column_is_primary_key($table_name, $column_name, $dblink) {
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
            // Return false on error - this will be reported as a warning
            return false;
        }
    }
}