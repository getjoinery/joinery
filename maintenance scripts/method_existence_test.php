#!/usr/bin/env php
<?php
/**
 * Method Existence Test
 *
 * Analyzes a PHP file for function/method calls and verifies their existence.
 * Loads model files and dependencies to check for undefined functions.
 *
 * Usage: php method_existence_test.php <path_to_php_file>
 */

// Bootstrap the application
$bootstrap_path = '/var/www/html/joinerytest/public_html/includes/PathHelper.php';
if (!file_exists($bootstrap_path)) {
    die("ERROR: Cannot find PathHelper.php at: $bootstrap_path\n");
}

require_once($bootstrap_path);

// Now PathHelper, Globalvars, SessionControl, etc. are available
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

class MethodExistenceTest {
    private $file_path;
    private $tokens;
    private $function_calls = [];
    private $method_calls = [];
    private $static_calls = [];
    private $property_accesses = []; // Track $this->property accesses
    private $loaded_classes = [];
    private $namespace = '';
    private $use_statements = [];
    private $defined_methods = [];
    private $constructors = [];
    private $variable_types = []; // Track variable => class name mappings

    // Whitelist of common methods by class
    private $common_methods = [
        'PDO' => ['query', 'prepare', 'exec', 'beginTransaction', 'commit', 'rollBack', 'lastInsertId'],
        'PDOStatement' => ['execute', 'fetch', 'fetchAll', 'fetchColumn', 'rowCount', 'closeCursor'],
        'SessionControl' => ['set_user_id', 'get_user_id', 'check_permission', 'get_instance'],
        'Globalvars' => ['get_setting', 'get_instance'],
        'DbConnector' => ['get_db_link', 'get_instance', 'set_test_mode', 'close_test_mode'],
        'SystemBase' => ['get', 'set', 'save', 'load', 'prepare', 'soft_delete', 'permanent_delete', 'undelete', 'export_as_array', 'check_for_duplicate'],
        'SystemMultiBase' => ['load', 'count', 'count_all', 'get', 'get_by_key', 'add', 'remove', 'is_valid', 'contains'],
    ];

    // Blacklist of known incorrect property/method access patterns
    private $blacklist = [
        // Property access blacklist - these properties don't exist or are wrong
        'property' => [
            '$this->sorts' => 'Use $this->order_by instead (SystemMultiBase stores order in $order_by property)',
        ],
        // Method blacklist - obsolete or incorrect methods
        'method' => [
            'CtldAccount::' => 'CtldAccount class is obsolete, use SubscriptionTier instead',
            'getUserAccount' => 'Method is obsolete, use getUserTier() or SubscriptionTier::GetUserTier() instead',
            'get_formwriter_object' => 'Removed - use $page->getFormWriter() in views/admin, or direct instantiation: require_once(PathHelper::getThemeFilePath(\'FormWriter.php\', \'includes\')); $fw = new FormWriter()',
        ],
        // Static call blacklist - class::method patterns that are wrong
        'static' => [
            'CtldAccount::' => 'CtldAccount class is obsolete, use SubscriptionTier instead',
        ],
        // Code pattern blacklist - string patterns to search for in source code
        'code_pattern' => [
            // Core files that should never be required (always loaded)
            "require_once(PathHelper::getIncludePath('includes/PathHelper.php'))" => 'PathHelper is always loaded - never require it',
            "require_once(PathHelper::getIncludePath('includes/Globalvars.php'))" => 'Globalvars is always loaded - never require it',
            "require_once(PathHelper::getIncludePath('includes/DbConnector.php'))" => 'DbConnector is always loaded - never require it',
            "require_once(PathHelper::getIncludePath('includes/SessionControl.php'))" => 'SessionControl is always loaded - never require it',
            "require_once(PathHelper::getIncludePath('includes/ThemeHelper.php'))" => 'ThemeHelper is always loaded - never require it',
            "require_once(PathHelper::getIncludePath('includes/PluginHelper.php'))" => 'PluginHelper is always loaded - never require it',

            // Direct path usage
            '$_SERVER[\'DOCUMENT_ROOT\']' => 'Never use $_SERVER[\'DOCUMENT_ROOT\'] - use PathHelper::getIncludePath() instead',
            '__DIR__ . \'/../' => 'Avoid __DIR__ navigation - use PathHelper::getIncludePath() for proper path resolution',

            // Constructor without parameters
            'new Product()' => 'Product constructor requires parameter: new Product(NULL) for new, new Product($id, TRUE) to load',
            'new User()' => 'User constructor requires parameter: new User(NULL) for new, new User($id, TRUE) to load',
            'new Order()' => 'Order constructor requires parameter: new Order(NULL) for new, new Order($id, TRUE) to load',
            'new Event()' => 'Event constructor requires parameter: new Event(NULL) for new, new Event($id, TRUE) to load',

            // Field specification anti-patterns
            "'type'=>'serial'" => "Use 'type'=>'int8' with 'serial'=>true instead of 'type'=>'serial' (PostgreSQL serial is a pseudo-type)",
            "'type' => 'serial'" => "Use 'type'=>'int8' with 'serial'=>true instead of 'type'=>'serial' (PostgreSQL serial is a pseudo-type)",
        ],
    ];

    // Track method return types for common patterns
    private $method_return_types = [
        'DbConnector::get_db_link' => 'PDO',
        'SessionControl::get_instance' => 'SessionControl',
        'Globalvars::get_instance' => 'Globalvars',
        'DbConnector::get_instance' => 'DbConnector',
        'PDO::query' => 'PDOStatement',
        'PDO::prepare' => 'PDOStatement',
        'StripeHelper::get_or_create_price' => 'ProductVersion',
        'Product::get_default_version' => 'ProductVersion',
    ];

    public function __construct($file_path) {
        if (!file_exists($file_path)) {
            throw new Exception("File not found: $file_path");
        }
        $this->file_path = $file_path;
    }

    /**
     * Run the analysis
     */
    public function analyze() {
        echo "Analyzing: {$this->file_path}\n";
        echo str_repeat("=", 80) . "\n\n";

        // Load the file and tokenize
        $content = file_get_contents($this->file_path);
        $this->tokens = token_get_all($content);

        // Try to load the file to get defined functions/classes
        $this->loadFile();

        // Parse tokens to find function/method calls
        $this->parseTokens();

        // Check function calls
        $this->checkFunctionCalls();

        // Check method calls
        $this->checkMethodCalls();

        // Check static calls
        $this->checkStaticCalls();

        // Check property accesses
        $this->checkPropertyAccesses();

        // Check code patterns
        $this->checkCodePatterns();

        // Summary
        $this->printSummary();
    }

    /**
     * Load the file to register its functions and classes
     */
    private function loadFile() {
        echo "Loading file and dependencies...\n";

        // Load common data models
        $this->loadDataModels();

        try {
            // Include the file being tested
            require_once($this->file_path);
            echo "✓ File loaded successfully\n";
        } catch (Exception $e) {
            echo "⚠ Warning: Could not load file: " . $e->getMessage() . "\n";
        }

        echo "\n";
    }

    /**
     * Load common data models
     */
    private function loadDataModels() {
        $data_dir = PathHelper::getIncludePath('data');

        if (!is_dir($data_dir)) {
            return;
        }

        $model_files = glob($data_dir . '/*_class.php');

        foreach ($model_files as $model_file) {
            try {
                require_once($model_file);
            } catch (Exception $e) {
                // Silent fail - some models may have dependencies
            }
        }

        echo "✓ Loaded " . count($model_files) . " data model files\n";
    }

    /**
     * Parse tokens to find all function/method calls
     */
    private function parseTokens() {
        $count = count($this->tokens);
        $in_class = false;
        $class_depth = 0;
        $brace_depth = 0;

        for ($i = 0; $i < $count; $i++) {
            $token = $this->tokens[$i];

            if (!is_array($token)) {
                // Track brace depth for class detection
                if ($token === '{') {
                    $brace_depth++;
                } elseif ($token === '}') {
                    $brace_depth--;
                    if ($in_class && $brace_depth < $class_depth) {
                        $in_class = false;
                    }
                }
                continue;
            }

            $token_type = $token[0];
            $token_value = $token[1];
            $line_number = $token[2];

            // Track when we enter a class
            if ($token_type === T_CLASS) {
                $in_class = true;
                $class_depth = $brace_depth + 1;
            }

            // Track namespace
            if ($token_type === T_NAMESPACE) {
                $this->namespace = $this->extractNamespace($i);
            }

            // Track use statements
            if ($token_type === T_USE) {
                $this->extractUseStatement($i);
            }

            // Track function/method definitions within the file
            if ($token_type === T_FUNCTION) {
                $function_name = $this->extractFunctionName($i);
                if ($function_name) {
                    $this->defined_methods[$function_name] = true;
                }
            }

            // Track variable assignments: $var = new ClassName() or $var = Class::method()
            if ($token_type === T_VARIABLE) {
                $var_name = $token_value;
                $class_name = $this->extractVariableType($i);
                if ($class_name) {
                    $this->variable_types[$var_name] = $class_name;
                }
            }

            // Look for function/method calls
            if ($token_type === T_STRING) {
                // Check next non-whitespace token
                $next_token = $this->getNextToken($i);

                if ($next_token === '(') {
                    // Check previous token to determine type of call
                    $prev_token = $this->getPrevToken($i);

                    if ($prev_token && is_array($prev_token)) {
                        if ($prev_token[0] === T_OBJECT_OPERATOR) {
                            // Method call: $obj->method()
                            $var_name = $this->getVariableBeforeMethodCall($i);
                            $this->method_calls[] = [
                                'name' => $token_value,
                                'variable' => $var_name,
                                'line' => $line_number
                            ];
                        } elseif ($prev_token[0] === T_DOUBLE_COLON || $prev_token[0] === T_PAAMAYIM_NEKUDOTAYIM) {
                            // Static call: Class::method()
                            $class_name = $this->getClassBeforeStaticCall($i);
                            $this->static_calls[] = [
                                'class' => $class_name,
                                'method' => $token_value,
                                'line' => $line_number
                            ];
                        } elseif ($prev_token[0] === T_NEW) {
                            // Constructor call: new ClassName()
                            $this->constructors[] = [
                                'name' => $token_value,
                                'line' => $line_number
                            ];
                        } else {
                            // Regular function call
                            $this->function_calls[] = [
                                'name' => $token_value,
                                'line' => $line_number
                            ];
                        }
                    } else {
                        // Regular function call (or possibly constructor without 'new' detected)
                        $this->function_calls[] = [
                            'name' => $token_value,
                            'line' => $line_number
                        ];
                    }
                } elseif ($prev_token = $this->getPrevToken($i)) {
                    // Check for property access without method call: $obj->property (not followed by '(')
                    if (is_array($prev_token) && $prev_token[0] === T_OBJECT_OPERATOR) {
                        $var_name = $this->getVariableBeforeMethodCall($i);
                        $this->property_accesses[] = [
                            'property' => $token_value,
                            'variable' => $var_name,
                            'line' => $line_number
                        ];
                    }
                }
            }
        }
    }

    /**
     * Get next non-whitespace token
     */
    private function getNextToken($index) {
        $count = count($this->tokens);
        for ($i = $index + 1; $i < $count; $i++) {
            $token = $this->tokens[$i];
            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }
            return $token;
        }
        return null;
    }

    /**
     * Get previous non-whitespace token
     */
    private function getPrevToken($index) {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $this->tokens[$i];
            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }
            return $token;
        }
        return null;
    }

    /**
     * Get class name before static call
     */
    private function getClassBeforeStaticCall($index) {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $this->tokens[$i];
            if (is_array($token)) {
                if ($token[0] === T_STRING) {
                    return $token[1];
                }
                if ($token[0] !== T_WHITESPACE && $token[0] !== T_DOUBLE_COLON && $token[0] !== T_PAAMAYIM_NEKUDOTAYIM) {
                    break;
                }
            } elseif ($token !== '::') {
                break;
            }
        }
        return 'Unknown';
    }

    /**
     * Extract namespace from tokens
     */
    private function extractNamespace($index) {
        $namespace = '';
        $count = count($this->tokens);

        for ($i = $index + 1; $i < $count; $i++) {
            $token = $this->tokens[$i];

            if (is_array($token) && ($token[0] === T_STRING || $token[0] === T_NS_SEPARATOR)) {
                $namespace .= $token[1];
            } elseif ($token === ';' || $token === '{') {
                break;
            }
        }

        return $namespace;
    }

    /**
     * Extract use statement
     */
    private function extractUseStatement($index) {
        $use = '';
        $count = count($this->tokens);

        for ($i = $index + 1; $i < $count; $i++) {
            $token = $this->tokens[$i];

            if (is_array($token) && ($token[0] === T_STRING || $token[0] === T_NS_SEPARATOR)) {
                $use .= $token[1];
            } elseif ($token === ';') {
                if ($use) {
                    $this->use_statements[] = $use;
                }
                break;
            }
        }
    }

    /**
     * Extract function name from function definition
     */
    private function extractFunctionName($index) {
        $count = count($this->tokens);

        for ($i = $index + 1; $i < $count; $i++) {
            $token = $this->tokens[$i];

            if (is_array($token) && $token[0] === T_STRING) {
                return $token[1];
            } elseif ($token === '(') {
                break;
            }
        }

        return null;
    }

    /**
     * Extract variable type from assignment
     * Looks for patterns like:
     * - $var = new ClassName()
     * - $var = Class::method()
     * - $var = $obj->method()
     */
    private function extractVariableType($index) {
        $count = count($this->tokens);

        // Look ahead for = sign
        for ($i = $index + 1; $i < $count; $i++) {
            $token = $this->tokens[$i];

            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }

            if ($token === '=') {
                // Found assignment, check what's being assigned

                // Pattern 1: $var = new ClassName()
                for ($j = $i + 1; $j < $count; $j++) {
                    $next_token = $this->tokens[$j];

                    if (is_array($next_token)) {
                        if ($next_token[0] === T_WHITESPACE) {
                            continue;
                        }

                        if ($next_token[0] === T_NEW) {
                            // Found 'new', get the class name
                            for ($k = $j + 1; $k < $count; $k++) {
                                $class_token = $this->tokens[$k];
                                if (is_array($class_token)) {
                                    if ($class_token[0] === T_WHITESPACE) {
                                        continue;
                                    }
                                    if ($class_token[0] === T_STRING) {
                                        return $class_token[1];
                                    }
                                }
                                break;
                            }
                        }

                        // Pattern 2: $var = ClassName::method()
                        if ($next_token[0] === T_STRING) {
                            $class_name = $next_token[1];
                            $method_name = null;

                            // Look for :: and method name
                            for ($k = $j + 1; $k < $count; $k++) {
                                $check_token = $this->tokens[$k];
                                if (is_array($check_token) && $check_token[0] === T_WHITESPACE) {
                                    continue;
                                }
                                if (is_array($check_token) && ($check_token[0] === T_DOUBLE_COLON || $check_token[0] === T_PAAMAYIM_NEKUDOTAYIM)) {
                                    // Found ::, get method name
                                    for ($m = $k + 1; $m < $count; $m++) {
                                        $method_token = $this->tokens[$m];
                                        if (is_array($method_token) && $method_token[0] === T_WHITESPACE) {
                                            continue;
                                        }
                                        if (is_array($method_token) && $method_token[0] === T_STRING) {
                                            $method_name = $method_token[1];
                                            break;
                                        }
                                        break;
                                    }
                                    break;
                                }
                                break;
                            }

                            // Check if we have a known return type for this method
                            if ($method_name) {
                                $lookup_key = "$class_name::$method_name";
                                if (isset($this->method_return_types[$lookup_key])) {
                                    return $this->method_return_types[$lookup_key];
                                }
                            }
                        }

                        // Pattern 3: $var = $other_var->method()
                        if ($next_token[0] === T_VARIABLE) {
                            $source_var = $next_token[1];
                            $method_name = null;

                            // Look for -> and method name
                            for ($k = $j + 1; $k < $count; $k++) {
                                $check_token = $this->tokens[$k];
                                if (is_array($check_token) && $check_token[0] === T_WHITESPACE) {
                                    continue;
                                }
                                if (is_array($check_token) && $check_token[0] === T_OBJECT_OPERATOR) {
                                    // Found ->, get method name
                                    for ($m = $k + 1; $m < $count; $m++) {
                                        $method_token = $this->tokens[$m];
                                        if (is_array($method_token) && $method_token[0] === T_WHITESPACE) {
                                            continue;
                                        }
                                        if (is_array($method_token) && $method_token[0] === T_STRING) {
                                            $method_name = $method_token[1];
                                            break;
                                        }
                                        break;
                                    }
                                    break;
                                }
                                break;
                            }

                            // Check if we know the source variable's type and the method's return type
                            if ($method_name && isset($this->variable_types[$source_var])) {
                                $source_class = $this->variable_types[$source_var];
                                $lookup_key = "$source_class::$method_name";
                                if (isset($this->method_return_types[$lookup_key])) {
                                    return $this->method_return_types[$lookup_key];
                                }
                            }
                        }
                    }

                    // Stop looking if we hit something that's not assignment-related
                    if ($token === ';' || $token === ',') {
                        break;
                    }
                }
            }

            // Stop if we hit something that's not part of the assignment
            break;
        }

        return null;
    }

    /**
     * Get variable name before method call
     * For $obj->method(), extract '$obj'
     */
    private function getVariableBeforeMethodCall($index) {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $this->tokens[$i];

            if (is_array($token)) {
                if ($token[0] === T_VARIABLE) {
                    return $token[1];
                }
                if ($token[0] !== T_WHITESPACE && $token[0] !== T_OBJECT_OPERATOR) {
                    break;
                }
            } elseif ($token !== '->') {
                break;
            }
        }

        return null;
    }

    /**
     * Infer class name from variable name
     * E.g., $product => Product, $user => User, $order_item => OrderItem
     */
    private function inferClassFromVariable($var_name) {
        // Remove $ prefix
        $name = ltrim($var_name, '$');

        // Common class name patterns
        $patterns = [
            // Exact matches for common variables
            'settings' => 'Globalvars',
            'dbconnector' => 'DbConnector',
            'session' => 'SessionControl',
            'dblink' => 'PDO',
            'stmt' => 'PDOStatement',
            'statement' => 'PDOStatement',
            'pdo' => 'PDO',

            // Plurals - try singular
            // E.g., $products => Product (but we'll handle Multi classes too)
        ];

        if (isset($patterns[$name])) {
            return $patterns[$name];
        }

        // Convert snake_case to PascalCase
        // order_item => OrderItem
        $parts = explode('_', $name);
        $class_name = '';
        foreach ($parts as $part) {
            $class_name .= ucfirst($part);
        }

        // Check if this class exists
        if (class_exists($class_name)) {
            return $class_name;
        }

        // Try singular for plurals
        if (substr($name, -1) === 's') {
            $singular = substr($name, 0, -1);
            $singular_class = ucfirst($singular);
            if (class_exists($singular_class)) {
                return $singular_class;
            }
        }

        return null;
    }

    /**
     * Check if a call matches a blacklist pattern
     */
    private function checkBlacklist($type, $pattern) {
        if (!isset($this->blacklist[$type])) {
            return null;
        }

        foreach ($this->blacklist[$type] as $blacklisted => $reason) {
            if (strpos($pattern, $blacklisted) !== false) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * Check function calls for existence
     */
    private function checkFunctionCalls() {
        if (empty($this->function_calls)) {
            echo "No function calls found.\n\n";
            return;
        }

        echo "FUNCTION CALLS (" . count($this->function_calls) . " total)\n";
        echo str_repeat("-", 80) . "\n";

        $found = 0;
        $missing = 0;
        $skipped = 0;
        $blacklisted = 0;
        $issues = [];

        foreach ($this->function_calls as $call) {
            $function_name = $call['name'];
            $line = $call['line'];

            // Check blacklist first
            $blacklist_reason = $this->checkBlacklist('method', $function_name);
            if ($blacklist_reason) {
                $blacklisted++;
                $issues[] = sprintf("  🚫 Line %4d: %s() - BLACKLISTED: %s", $line, $function_name, $blacklist_reason);
                continue;
            }

            // Skip if this is a method defined in the file (likely $this->method() without proper detection)
            if (isset($this->defined_methods[$function_name])) {
                $skipped++;
                continue;
            }

            // Check if function exists
            if (function_exists($function_name)) {
                $found++;
            } elseif (function_exists($this->namespace . '\\' . $function_name)) {
                $found++;
            } elseif (class_exists($function_name)) {
                // This is likely a constructor - skip it
                $skipped++;
            } else {
                $missing++;
                $issues[] = sprintf("  ✗ Line %4d: %s()", $line, $function_name);
            }
        }

        if (!empty($issues)) {
            echo "Issues found:\n";
            foreach ($issues as $issue) {
                echo $issue . "\n";
            }
        }

        echo sprintf("\n✓ Found: %d  ✗ Missing: %d  🚫 Blacklisted: %d  ⊘ Skipped: %d\n\n", $found, $missing, $blacklisted, $skipped);
    }

    /**
     * Check method calls
     */
    private function checkMethodCalls() {
        if (empty($this->method_calls)) {
            echo "No method calls found.\n\n";
            return;
        }

        echo "METHOD CALLS (" . count($this->method_calls) . " total)\n";
        echo str_repeat("-", 80) . "\n";

        $found = 0;
        $missing = 0;
        $unknown = 0;
        $whitelisted = 0;
        $blacklisted = 0;
        $issues = [];

        foreach ($this->method_calls as $call) {
            $method_name = $call['name'];
            $var_name = $call['variable'];
            $line = $call['line'];

            // Check blacklist first
            $blacklist_reason = $this->checkBlacklist('method', $method_name);
            if ($blacklist_reason) {
                $blacklisted++;
                $issues[] = sprintf("  🚫 Line %4d: %s->%s() - BLACKLISTED: %s",
                    $line, $var_name ?: '?', $method_name, $blacklist_reason);
                continue;
            }

            // Try to determine the class
            $class_name = null;

            // 1. Check if we tracked this variable from assignment
            if ($var_name && isset($this->variable_types[$var_name])) {
                $class_name = $this->variable_types[$var_name];
            }

            // 2. Try to infer from variable name
            if (!$class_name && $var_name) {
                $class_name = $this->inferClassFromVariable($var_name);
            }

            if ($class_name) {
                // Check if method is whitelisted for this class
                if ($this->isMethodWhitelisted($class_name, $method_name)) {
                    $whitelisted++;
                    continue;
                }

                // We know the class, check if method exists
                if (class_exists($class_name) && method_exists($class_name, $method_name)) {
                    $found++;
                } else {
                    $missing++;
                    $issues[] = sprintf("  ✗ Line %4d: %s->%s() [inferred class: %s]",
                        $line, $var_name ?: '?', $method_name, $class_name);
                }
            } else {
                // Can't determine class
                $unknown++;
            }
        }

        if (!empty($issues)) {
            echo "Issues found:\n";
            foreach ($issues as $issue) {
                echo $issue . "\n";
            }
            echo "\n";
        }

        echo sprintf("✓ Found: %d  ✗ Missing: %d  🚫 Blacklisted: %d  ? Unknown: %d  ⊘ Whitelisted: %d\n\n",
            $found, $missing, $blacklisted, $unknown, $whitelisted);
    }

    /**
     * Check if a method is whitelisted for a class
     * Also checks parent classes (SystemBase, SystemMultiBase)
     * If class identification is uncertain, checks if method exists in any whitelist
     */
    private function isMethodWhitelisted($class_name, $method_name) {
        // Direct match
        if (isset($this->common_methods[$class_name]) &&
            in_array($method_name, $this->common_methods[$class_name])) {
            return true;
        }

        // Check if class extends SystemBase
        if (class_exists($class_name)) {
            if (is_subclass_of($class_name, 'SystemBase') &&
                isset($this->common_methods['SystemBase']) &&
                in_array($method_name, $this->common_methods['SystemBase'])) {
                return true;
            }

            // Check if class extends SystemMultiBase
            if (is_subclass_of($class_name, 'SystemMultiBase') &&
                isset($this->common_methods['SystemMultiBase']) &&
                in_array($method_name, $this->common_methods['SystemMultiBase'])) {
                return true;
            }
        }

        // Fallback: If class doesn't exist or doesn't match, check if method is in ANY whitelist
        // This handles cases where variable type tracking is imperfect
        foreach ($this->common_methods as $whitelisted_class => $methods) {
            if (in_array($method_name, $methods)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check static calls
     */
    private function checkStaticCalls() {
        if (empty($this->static_calls)) {
            echo "No static method calls found.\n\n";
            return;
        }

        echo "STATIC METHOD CALLS (" . count($this->static_calls) . " total)\n";
        echo str_repeat("-", 80) . "\n";

        $found = 0;
        $missing = 0;
        $blacklisted = 0;
        $issues = [];

        foreach ($this->static_calls as $call) {
            $class_name = $call['class'];
            $method_name = $call['method'];
            $line = $call['line'];

            // Check blacklist first (check both class:: and full class::method)
            $static_pattern = "$class_name::$method_name";
            $class_pattern = "$class_name::";

            $blacklist_reason = $this->checkBlacklist('static', $static_pattern);
            if (!$blacklist_reason) {
                $blacklist_reason = $this->checkBlacklist('static', $class_pattern);
            }

            if ($blacklist_reason) {
                $blacklisted++;
                $issues[] = sprintf("  🚫 Line %4d: %s::%s() - BLACKLISTED: %s",
                    $line, $class_name, $method_name, $blacklist_reason);
                continue;
            }

            // Resolve class name
            $resolved_class = $this->resolveClassName($class_name);

            // Check if class exists
            if (!class_exists($resolved_class)) {
                $missing++;
                $issues[] = sprintf("  ✗ Line %4d: %s::%s() - Class not found", $line, $class_name, $method_name);
                continue;
            }

            // Check if method exists
            if (method_exists($resolved_class, $method_name)) {
                $found++;
            } else {
                $missing++;
                $issues[] = sprintf("  ✗ Line %4d: %s::%s() - Method not found", $line, $class_name, $method_name);
            }
        }

        if (!empty($issues)) {
            echo "Issues found:\n";
            foreach ($issues as $issue) {
                echo $issue . "\n";
            }
        }

        echo sprintf("\n✓ Found: %d  ✗ Missing: %d  🚫 Blacklisted: %d\n\n", $found, $missing, $blacklisted);
    }

    /**
     * Resolve class name using namespace and use statements
     */
    private function resolveClassName($class_name) {
        // If fully qualified, return as-is
        if ($class_name[0] === '\\') {
            return substr($class_name, 1);
        }

        // Check use statements
        foreach ($this->use_statements as $use) {
            if (substr($use, -strlen($class_name)) === $class_name) {
                return $use;
            }
        }

        // If in namespace, prepend namespace
        if ($this->namespace && class_exists($this->namespace . '\\' . $class_name)) {
            return $this->namespace . '\\' . $class_name;
        }

        return $class_name;
    }

    /**
     * Check property accesses
     */
    private function checkPropertyAccesses() {
        if (empty($this->property_accesses)) {
            echo "No property accesses found.\n\n";
            return;
        }

        echo "PROPERTY ACCESSES (" . count($this->property_accesses) . " total)\n";
        echo str_repeat("-", 80) . "\n";

        $blacklisted = 0;
        $safe = 0;
        $issues = [];

        foreach ($this->property_accesses as $access) {
            $property_name = $access['property'];
            $var_name = $access['variable'];
            $line = $access['line'];

            // Build the property pattern for blacklist checking
            $property_pattern = "{$var_name}->{$property_name}";

            // Check blacklist
            $blacklist_reason = $this->checkBlacklist('property', $property_pattern);
            if ($blacklist_reason) {
                $blacklisted++;
                $issues[] = sprintf("  🚫 Line %4d: %s - BLACKLISTED: %s",
                    $line, $property_pattern, $blacklist_reason);
            } else {
                $safe++;
            }
        }

        if (!empty($issues)) {
            echo "Issues found:\n";
            foreach ($issues as $issue) {
                echo $issue . "\n";
            }
        }

        echo sprintf("\n✓ Safe: %d  🚫 Blacklisted: %d\n\n", $safe, $blacklisted);
    }

    /**
     * Check source code for blacklisted patterns
     */
    private function checkCodePatterns() {
        if (!isset($this->blacklist['code_pattern'])) {
            echo "No code patterns configured.\n\n";
            return;
        }

        echo "CODE PATTERN ANALYSIS\n";
        echo str_repeat("-", 80) . "\n";

        $source = file_get_contents($this->file_path);
        $lines = explode("\n", $source);

        $blacklisted = 0;
        $issues = [];

        foreach ($this->blacklist['code_pattern'] as $pattern => $reason) {
            // Search for pattern in source code
            $line_num = 0;
            foreach ($lines as $line_num => $line_content) {
                // Use case-insensitive search for better matching, trim whitespace
                $trimmed_line = trim($line_content);

                if (strpos($line_content, $pattern) !== false) {
                    $blacklisted++;
                    $issues[] = sprintf("  🚫 Line %4d: Contains '%s'\n           → %s",
                        $line_num + 1,
                        $this->truncatePattern($pattern),
                        $reason);
                }
            }
        }

        if (!empty($issues)) {
            echo "Issues found:\n";
            foreach ($issues as $issue) {
                echo $issue . "\n";
            }
        } else {
            echo "✓ No blacklisted code patterns found\n";
        }

        echo sprintf("\n🚫 Total pattern violations: %d\n\n", $blacklisted);
    }

    /**
     * Truncate long patterns for display
     */
    private function truncatePattern($pattern, $max_length = 50) {
        if (strlen($pattern) <= $max_length) {
            return $pattern;
        }
        return substr($pattern, 0, $max_length - 3) . '...';
    }

    /**
     * Print summary
     */
    private function printSummary() {
        echo str_repeat("=", 80) . "\n";
        echo "SUMMARY\n";
        echo str_repeat("=", 80) . "\n";
        echo sprintf("Function calls:       %d\n", count($this->function_calls));
        echo sprintf("Method calls:         %d\n", count($this->method_calls));
        echo sprintf("Static method calls:  %d\n", count($this->static_calls));
        echo sprintf("Property accesses:    %d\n", count($this->property_accesses));
        echo sprintf("Constructors (new):   %d\n", count($this->constructors));
        echo sprintf("Defined methods:      %d\n", count($this->defined_methods));
        echo sprintf("Namespace:            %s\n", $this->namespace ?: '(global)');
        echo sprintf("Use statements:       %d\n", count($this->use_statements));
        echo "\n";
    }
}

// Main execution
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

if ($argc < 2) {
    echo "Usage: php method_existence_test.php <path_to_php_file>\n";
    echo "\n";
    echo "Examples:\n";
    echo "  php method_existence_test.php /var/www/html/joinerytest/public_html/logic/profile_logic.php\n";
    echo "  php method_existence_test.php ../public_html/adm/admin_users.php\n";
    exit(1);
}

$file_path = $argv[1];

// Convert relative path to absolute if needed
if ($file_path[0] !== '/') {
    $file_path = getcwd() . '/' . $file_path;
}

try {
    $tester = new MethodExistenceTest($file_path);
    $tester->analyze();
    exit(0);
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
