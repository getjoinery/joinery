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
    
    public function __construct($model_class) {
        $this->model_class = $model_class;
    }
    
    /**
     * Main test execution method
     */
    public function test($model_instance = null, $debug = false) {
        $this->model_instance = $model_instance ?: new $this->model_class(null);
        
        echo '<b>TESTING CLASS: ' . $this->model_class . "</b><br>\n";
        
        try {
            // Set up test database mode if available
            $dbhelper = DbConnector::get_instance();
            if (method_exists($dbhelper, 'set_test_mode')) {
                $dbhelper->set_test_mode();
            }
            
            // Run automated tests
            $this->test_automated_crud($debug);
            $this->test_automated_validation($debug);
            $this->test_automated_constraints($debug);
            $this->test_automated_edge_cases($debug);
            
            // Clean up test mode if available
            if (method_exists($dbhelper, 'close_test_mode')) {
                $dbhelper->close_test_mode();
            }
            
            echo "[PASS] {$this->model_class} - All automated tests passed<br>\n";
            return true;
            
        } catch (Exception $e) {
            // Clean up on failure
            $dbhelper = DbConnector::get_instance();
            if (method_exists($dbhelper, 'close_test_mode')) {
                $dbhelper->close_test_mode();
            }
            
            echo "[FAIL] {$this->model_class} - " . $e->getMessage() . "<br>\n";
            if ($debug) {
                echo "Stack trace:<br>\n" . nl2br($e->getTraceAsString()) . "<br>\n";
            }
            return false;
        }
    }
    
    /**
     * Test CRUD operations with automatically generated data
     */
    protected function test_automated_crud($debug = false) {
        if ($debug) echo "Testing CRUD operations...<br>\n";
        
        // Generate valid test data automatically
        $test_data = $this->generate_valid_test_data();
        
        // Create a fresh model instance for testing
        $model = new $this->model_class(null);
        
        // Test Create
        foreach ($test_data as $field => $value) {
            $model->set($field, $value);
            if ($debug) echo "Set $field = $value<br>\n";
        }
        
        $model->save();
        $this->assert_true($model->key !== null, "Record should be created");
        
        $original_key = $model->key;
        if ($debug) echo "Created record with key: $original_key<br>\n";
        
        // Test Read
        $model->load();
        $this->assert_equals($original_key, $model->key, "Record should be loaded correctly");
        
        // Test Update
        $updateable_field = $this->find_updateable_field($model);
        if ($updateable_field) {
            $new_value = $this->generate_different_value($updateable_field);
            $model->set($updateable_field, $new_value);
            $model->save();
            $model->load();
            $this->assert_equals($new_value, $model->get($updateable_field), "Field should be updated");
            if ($debug) echo "Updated $updateable_field to $new_value<br>\n";
        }
        
        // Test Delete
        $model->permanent_delete();
        
        // Verify deletion
        $model_class = $this->model_class;
        if (!$model_class::check_if_exists($original_key)) {
            $this->test_pass("Record deleted successfully");
        } else {
            $this->test_fail("Record should be deleted but still exists");
        }
        
        if ($debug) echo "CRUD tests completed<br>\n";
    }
    
    /**
     * Test field validation using field specifications
     */
    protected function test_automated_validation($debug = false) {
        if ($debug) echo "Testing field validation...<br>\n";
        
        $model_class = $this->model_class;
        
        // Test required fields
        foreach ($model_class::$required_fields as $required_field) {
            $this->test_required_field($required_field, $debug);
        }
        
        // Test field type constraints
        foreach ($model_class::$field_specifications as $field => $spec) {
            $this->test_field_type_constraints($field, $spec, $debug);
        }
        
        if ($debug) echo "Validation tests completed<br>\n";
    }
    
    /**
     * Test database constraints
     */
    protected function test_automated_constraints($debug = false) {
        if ($debug) echo "Testing database constraints...<br>\n";
        
        // Test unique constraints
        $unique_fields = $this->infer_unique_fields();
        foreach ($unique_fields as $field) {
            $this->test_unique_constraint($field, $debug);
        }
        
        // Note: Foreign key constraint testing skipped in automated phase
        
        if ($debug) echo "Constraint tests completed<br>\n";
    }
    
    /**
     * Test edge cases and boundary conditions
     */
    protected function test_automated_edge_cases($debug = false) {
        if ($debug) echo "Testing edge cases...<br>\n";
        
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
        
        if ($debug) echo "Edge case tests completed<br>\n";
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
        
        if (strpos($type, 'decimal') !== false || strpos($type, 'float') !== false) {
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
        $original_value = $this->generate_field_value($field);
        
        // Generate a slightly different value
        if (is_string($original_value)) {
            return $original_value . '_updated';
        }
        
        if (is_numeric($original_value)) {
            return $original_value + 1;
        }
        
        return $original_value;
    }
    
    // Constraint detection methods
    protected function infer_unique_fields() {
        $unique_fields = [];
        $model_class = $this->model_class;
        
        // Check field specifications for unique constraints
        foreach ($model_class::$field_specifications as $field => $spec) {
            $field_lower = strtolower($field);
            
            // Email fields are typically unique
            if (strpos($field_lower, 'email') !== false) {
                $unique_fields[] = $field;
            }
            
            // Username fields are typically unique  
            if (strpos($field_lower, 'username') !== false) {
                $unique_fields[] = $field;
            }
            
            // Code fields are often unique
            if (strpos($field_lower, 'code') !== false) {
                $unique_fields[] = $field;
            }
            
            // Slug fields are typically unique
            if (strpos($field_lower, 'slug') !== false) {
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
                strpos($type, 'float') !== false) {
                $numeric_fields[$field] = $type;
            }
        }
        
        return $numeric_fields;
    }
    
    // Validation test methods
    protected function test_required_field($field, $debug) {
        if ($debug) echo "Testing required field: $field<br>\n";
        
        $model_class = $this->model_class;
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
        if ($debug) echo "Testing field type constraints: $field<br>\n";
        
        $type = $spec['type'] ?? '';
        $model_class = $this->model_class;
        
        // Test varchar length constraints
        if (strpos($type, 'varchar') !== false) {
            preg_match('/varchar\\((\\d+)\\)/', $type, $matches);
            $max_length = isset($matches[1]) ? (int)$matches[1] : 255;
            $this->test_varchar_length_constraint($field, $max_length, $debug);
        }
        
        // Test integer constraints  
        if (strpos($type, 'int') !== false) {
            $this->test_integer_constraint($field, $type, $debug);
        }
    }
    
    protected function test_varchar_length_constraint($field, $max_length, $debug) {
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
            // If save succeeds, check if value was truncated
            $model->load();
            $saved_value = $model->get($field);
            $this->assert_true(strlen($saved_value) <= $max_length, 
                "Field $field should be truncated to max length $max_length");
        } catch (Exception $e) {
            // Exception is also acceptable for constraint violation
            $this->test_pass("Field $field correctly rejects overly long values");
        }
        
        $model->permanent_delete();
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
            // If save succeeds, check if value was converted
            $model->load();
            $saved_value = $model->get($field);
            $this->assert_true(is_numeric($saved_value), 
                "Field $field should convert or reject non-numeric values");
        } catch (Exception $e) {
            // Exception is acceptable for type constraint violation
            $this->test_pass("Field $field correctly rejects non-numeric values");
        }
        
        $model->permanent_delete();
    }
    
    protected function test_unique_constraint($field, $debug) {
        if ($debug) echo "Testing unique constraint: $field<br>\n";
        
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
        if ($debug) echo "Testing null value for: $field<br>\n";
        
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
        if ($debug) echo "Testing max length for: $field ($max_length chars)<br>\n";
        
        $this->test_varchar_length_constraint($field, $max_length, $debug);
    }
    
    protected function test_numeric_boundaries($field, $type, $debug) {
        if ($debug) echo "Testing numeric boundaries for: $field ($type)<br>\n";
        
        $this->test_integer_constraint($field, $type, $debug);
    }
    
    // Assertion methods
    protected function assert_equals($expected, $actual, $message = '') {
        if ($expected !== $actual) {
            $this->test_fail("Expected '$expected', got '$actual': $message");
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
    
    protected function test_fail($message) {
        self::$test_fail_count++;
        echo "  [FAIL] $message<br>\n";
        throw new Exception("Test failed: $message");
    }
    
    public static function get_test_stats() {
        return [
            'passed' => self::$test_pass_count,
            'failed' => self::$test_fail_count
        ];
    }
}