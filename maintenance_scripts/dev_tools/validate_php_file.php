#!/usr/bin/env php
<?php
/**
 * PHP File Validator
 *
 * Analyzes a PHP file for function/method calls and verifies their existence.
 * Loads model files and dependencies to check for undefined functions.
 *
 * Usage: php validate_php_file.php <path_to_php_file>
 */

// Bootstrap the application using path relative to this script
$bootstrap_path = __DIR__ . '/../../public_html/includes/PathHelper.php';
if (!file_exists($bootstrap_path)) {
    die("ERROR: Cannot find PathHelper.php at: $bootstrap_path\n" .
        "       This script must be run from within the Joinery project structure.\n");
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
    private $current_class_name = ''; // Name of the class currently being parsed (for self::/static:: resolution)
    private $current_parent_class = ''; // Name of the parent class (for parent:: resolution)
    private $contract_errors = 0; // Hard contract violations: model + logic (drive the exit code)
    private $call_errors = 0;     // Confirmed missing functions/methods/classes/args (drive the exit code)

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
        // Method blacklist - obsolete or incorrect methods (bare method names,
        // matched exactly)
        'method' => [
            'getUserAccount' => 'Method is obsolete, use getUserTier() or SubscriptionTier::GetUserTier() instead',
            'get_formwriter_object' => 'Removed - use $page->getFormWriter() in views/admin, or direct instantiation: require_once(PathHelper::getThemeFilePath(\'FormWriter.php\', \'includes\')); $fw = new FormWriter()',
            'start_buttons' => 'Method does not exist in FormWriter V2 - use submitbutton() instead',
            'end_buttons' => 'Method does not exist in FormWriter V2 - use submitbutton() instead',
            'new_form_button' => 'Method does not exist in FormWriter V2 - use submitbutton() instead',
        ],
        // Static call blacklist - 'Class::method' matched exactly; a trailing
        // '::' makes the entry a class-wide prefix. Also consulted for
        // instance calls when the object's class is known.
        'static' => [
            'CtldAccount::' => 'CtldAccount class is obsolete, use SubscriptionTier instead',
            'LogicResult::data' => 'Method does not exist - use LogicResult::render() instead for all return statements in logic files',
            'Pager::get_param_string' => 'Method does not exist - use Pager::get_url() instead',
            'Pager::get_param' => 'Method does not exist - use Pager::current_page() instead',
            'Pager::get_limit' => 'Method does not exist - use Pager::num_per_page() instead',
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

            // Bare-relative require of core files (no path prefix at all)
            "require_once('PathHelper.php')" => 'PathHelper is always loaded - never require it',
            "require_once('Globalvars.php')" => 'Globalvars is always loaded - never require it',
            "require_once('DbConnector.php')" => 'DbConnector is always loaded - never require it',
            "require_once('SessionControl.php')" => 'SessionControl is always loaded - never require it',
            "require_once('ThemeHelper.php')" => 'ThemeHelper is always loaded - never require it',
            "require_once('PluginHelper.php')" => 'PluginHelper is always loaded - never require it',

            // Direct path usage
            '$_SERVER[\'DOCUMENT_ROOT\']' => 'Never use $_SERVER[\'DOCUMENT_ROOT\'] - use PathHelper::getIncludePath() instead',
            '__DIR__ . \'/../' => 'Avoid __DIR__ navigation - use PathHelper::getIncludePath() for proper path resolution',

            // Field specification anti-patterns
            "'type'=>'serial'" => "Use 'type'=>'int8' with 'serial'=>true instead of 'type'=>'serial' (PostgreSQL serial is a pseudo-type)",
            "'type' => 'serial'" => "Use 'type'=>'int8' with 'serial'=>true instead of 'type'=>'serial' (PostgreSQL serial is a pseudo-type)",

            // Removed/deprecated patterns
            'public static $field_constraints' => 'field_constraints was removed - validation is now handled via field_specifications (required, unique, unique_with)',
            "static \$field_constraints" => 'field_constraints was removed - validation is now handled via field_specifications (required, unique, unique_with)',
            "::field_constraints[" => 'field_constraints was removed - validation is now handled via field_specifications (required, unique, unique_with)',

            // FormWriter V2 anti-patterns
            "\$formwriter->submitbutton('submit'" => "Never use submitbutton('submit' - shadows form.submit() method. Use submitbutton('submit_button' or similar instead",
            "->begin(" => "FormWriter V2 uses begin_form() not begin() - change ->begin() to ->begin_form()",
            "->submit(" => "FormWriter V2 uses submitbutton() not submit() - change ->submit('Label') to ->submitbutton('name', 'Label')",
            "'ctrlHolder'" => "ctrlHolder is a FormWriter V1 class - remove it; form rows are styled by the .jy-ui kit automatically",
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

        // Check constructor calls
        $this->checkConstructors();

        // Check code patterns
        $this->checkCodePatterns();

        // Check CSS-kit style policy
        $this->checkStylePolicy();

        // Check AI action descriptor contract
        $this->checkDescriptorContract();

        // Check data-model structure contract (SystemBase subclasses)
        $this->checkModelContract();

        // Check logic-file structure contract
        $this->checkLogicContract();

        // Summary
        $this->printSummary();

        return $this->contract_errors + $this->call_errors;
    }

    /**
     * Load the file to register its functions and classes
     */
    private function loadFile() {
        echo "Loading file and dependencies...\n";

        // Core and active-plugin classes resolve through the platform
        // autoloader (registered by PathHelper), so class_exists() and
        // method_exists() answer for any class the file references whether or
        // not the file requires it — an autoloadable class is never reported
        // as "Class not found".
        //
        // This second loader covers what the platform map deliberately omits:
        // classes belonging to plugins that are installed but not active, which
        // a developer may still be editing.
        spl_autoload_register(function ($class) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $class)) {
                return;
            }
            $base = PathHelper::getIncludePath('');
            $candidates = array_merge(
                [PathHelper::getIncludePath('includes/' . $class . '.php')],
                glob($base . 'includes/*/' . $class . '.php') ?: [],
                glob($base . 'plugins/*/includes/' . $class . '.php') ?: [],
                glob($base . 'plugins/*/includes/*/' . $class . '.php') ?: []
            );
            foreach ($candidates as $path) {
                if (is_file($path)) {
                    try {
                        require_once($path);
                    } catch (Throwable $e) {
                        // Dependency failure — leave the class unresolved
                    }
                    return;
                }
            }
        });

        // Composer autoloader, so vendor classes (\OTPHP\TOTP, Stripe, ...)
        // resolve. The composerAutoLoad setting holds the vendor dir path
        // relative to public_html; fall back to the conventional location.
        try {
            $vendor_dir = Globalvars::get_instance()->get_setting('composerAutoLoad');
        } catch (Throwable $e) {
            $vendor_dir = null;
        }
        foreach ([$vendor_dir, '../vendor/'] as $dir) {
            if (!$dir) continue;
            $autoload = PathHelper::getIncludePath(rtrim($dir, '/') . '/autoload.php');
            if (is_file($autoload)) {
                require_once($autoload);
                break;
            }
        }

        // Load common data models
        $this->loadDataModels();

        try {
            // Include the file being tested
            require_once($this->file_path);
            echo "✓ File loaded successfully\n";
        } catch (Throwable $e) {
            echo "⚠ Warning: Could not load file: " . $e->getMessage() . "\n";
        }

        echo "\n";
    }

    /**
     * Lazily built index of every global function defined under logic/,
     * includes/, and their plugin equivalents — collected with a regex scan
     * (no code execution), so functions the router loads at runtime from
     * sibling files are recognized without the side effects of require'ing
     * every file. Maps lowercase function name => defining file.
     */
    private $function_definition_index = null;

    private function isFunctionDefinedSomewhere($function_name) {
        if ($this->function_definition_index === null) {
            $this->function_definition_index = [];
            $scan_files = array_merge(
                glob(PathHelper::getIncludePath('logic') . '/*.php') ?: [],
                glob(PathHelper::getIncludePath('includes') . '/*.php') ?: [],
                glob(PathHelper::getIncludePath('plugins') . '/*/logic/*.php') ?: [],
                glob(PathHelper::getIncludePath('plugins') . '/*/includes/*.php') ?: []
            );
            foreach ($scan_files as $scan_file) {
                foreach ($this->extractGlobalFunctionNames($scan_file) as $name) {
                    $this->function_definition_index[strtolower($name)] = $scan_file;
                }
            }
        }
        return isset($this->function_definition_index[strtolower($function_name)]);
    }

    /**
     * Tokenize a file and return top-level (non-method) function names.
     * Functions inside class/trait/interface bodies are skipped so methods
     * are never mistaken for global functions.
     */
    private function extractGlobalFunctionNames($file) {
        $names = [];
        try {
            $tokens = token_get_all(file_get_contents($file));
        } catch (Throwable $e) {
            return $names;
        }
        $depth = 0;
        $class_depths = []; // brace depths at which a class body opened
        $pending_class = false;
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                if ($token === '{') {
                    $depth++;
                    if ($pending_class) {
                        $class_depths[] = $depth;
                        $pending_class = false;
                    }
                } elseif ($token === '}') {
                    if ($class_depths && end($class_depths) === $depth) {
                        array_pop($class_depths);
                    }
                    $depth--;
                }
                continue;
            }
            if ($token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++; // string interpolation braces close with a bare '}'
                continue;
            }
            if ($token[0] === T_CLASS || $token[0] === T_TRAIT || $token[0] === T_INTERFACE) {
                // Skip ::class constants
                $prev = $i > 0 && is_array($tokens[$i - 1]) ? $tokens[$i - 1][0] : null;
                if ($prev !== T_DOUBLE_COLON && $prev !== T_PAAMAYIM_NEKUDOTAYIM) {
                    $pending_class = true;
                }
                continue;
            }
            if ($token[0] === T_FUNCTION && empty($class_depths)) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $t = $tokens[$j];
                    // Skip whitespace and the by-reference '&' (a bare char on
                    // older PHP, dedicated tokens on 8.1+)
                    if ($t === '&' || (is_array($t) && ($t[0] === T_WHITESPACE
                        || (defined('T_AMPERSAND_FOLLOWED_BY_VOID_OR_NULLABLE_TYPE') && $t[0] === T_AMPERSAND_FOLLOWED_BY_VOID_OR_NULLABLE_TYPE)
                        || (defined('T_AMPERSAND_NOT_FOLLOWED_BY_VOID_OR_NULLABLE_TYPE') && $t[0] === T_AMPERSAND_NOT_FOLLOWED_BY_VOID_OR_NULLABLE_TYPE)))) {
                        continue;
                    }
                    if (is_array($t) && $t[0] === T_STRING) {
                        $names[] = $t[1];
                    }
                    break; // '(' means a closure — no name to record
                }
            }
        }
        return $names;
    }

    /**
     * Load common data models
     */
    private function loadDataModels() {
        $data_dir = PathHelper::getIncludePath('data');

        if (!is_dir($data_dir)) {
            return;
        }

        // Core models plus every plugin's models
        $model_files = array_merge(
            glob($data_dir . '/*_class.php'),
            glob(PathHelper::getIncludePath('plugins') . '/*/data/*_class.php')
        );

        foreach ($model_files as $model_file) {
            try {
                require_once($model_file);
            } catch (Throwable $e) {
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

            // Track when we enter a class — capture the class name and (if present) parent class
            // so self::, static::, and parent:: calls can be resolved later.
            if ($token_type === T_CLASS) {
                $in_class = true;
                $class_depth = $brace_depth + 1;
                // Look ahead for the class name (next T_STRING after whitespace)
                for ($k = $i + 1; $k < $count; $k++) {
                    $tk = $this->tokens[$k];
                    if (is_array($tk) && $tk[0] === T_WHITESPACE) continue;
                    if (is_array($tk) && $tk[0] === T_STRING) {
                        $this->current_class_name = $tk[1];
                    }
                    break;
                }
                // Look ahead for `extends ParentName`
                $this->current_parent_class = '';
                for ($k = $i + 1; $k < $count; $k++) {
                    $tk = $this->tokens[$k];
                    if (!is_array($tk)) {
                        if ($tk === '{') break; // class body started, no extends found
                        continue;
                    }
                    if ($tk[0] === T_EXTENDS) {
                        for ($m = $k + 1; $m < $count; $m++) {
                            $pt = $this->tokens[$m];
                            if (is_array($pt) && $pt[0] === T_WHITESPACE) continue;
                            if (is_array($pt) && $pt[0] === T_STRING) {
                                $this->current_parent_class = $pt[1];
                            }
                            break 2;
                        }
                    }
                }
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

            // Track foreach over a tracked Multi collection: the value variable
            // holds an instance of the collection's model class
            if ($token_type === T_FOREACH) {
                $this->extractForeachValueType($i);
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
                            // Method call: $obj->method() — snapshot the
                            // variable's tracked type NOW; the same name may
                            // be rebound to a different class later in the file
                            $var_name = $this->getVariableBeforeMethodCall($i);
                            $this->method_calls[] = [
                                'name' => $token_value,
                                'variable' => $var_name,
                                'line' => $line_number,
                                'tracked_class' => ($var_name && isset($this->variable_types[$var_name]))
                                    ? $this->variable_types[$var_name] : null
                            ];
                        } elseif ($prev_token[0] === T_DOUBLE_COLON || $prev_token[0] === T_PAAMAYIM_NEKUDOTAYIM) {
                            // Static call: Class::method() — snapshot the
                            // enclosing class/parent NOW, so self::/parent::
                            // resolve against the class this call sits in
                            // (not whichever class the file declares last)
                            $class_name = $this->getClassBeforeStaticCall($i);
                            $this->static_calls[] = [
                                'class' => $class_name,
                                'method' => $token_value,
                                'line' => $line_number,
                                'context_class' => $this->current_class_name,
                                'context_parent' => $this->current_parent_class
                            ];
                        } elseif ($prev_token[0] === T_NEW) {
                            // Constructor call: new ClassName(...) — note whether
                            // any argument is passed (')' directly after '(')
                            $paren_index = $this->getNextTokenIndex($i);
                            $after_paren = $paren_index !== null ? $this->getNextToken($paren_index) : null;
                            $this->constructors[] = [
                                'name' => $token_value,
                                'line' => $line_number,
                                'no_args' => ($after_paren === ')')
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
     * Get index of next non-whitespace token
     */
    private function getNextTokenIndex($index) {
        $count = count($this->tokens);
        for ($i = $index + 1; $i < $count; $i++) {
            $token = $this->tokens[$i];
            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }
            return $i;
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
                // T_NAME_QUALIFIED / T_NAME_FULLY_QUALIFIED cover namespaced
                // names (Foo\Bar, \Foo\Bar), which tokenize as a single token
                if ($token[0] === T_STRING || $token[0] === T_NAME_QUALIFIED || $token[0] === T_NAME_FULLY_QUALIFIED) {
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

            if (is_array($token) && ($token[0] === T_STRING || $token[0] === T_NS_SEPARATOR
                || $token[0] === T_NAME_QUALIFIED || $token[0] === T_NAME_FULLY_QUALIFIED)) {
                // PHP 8 tokenizes 'Foo\Bar\Baz' as a single T_NAME_QUALIFIED
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
                // Found assignment. Only the FIRST expression token after '='
                // may decide the type — scanning further would latch onto
                // unrelated tokens later in the statement or file (e.g. a
                // `throw new X()` after an `if ($v = expr)` condition).

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
                                    // Follow any chained calls (e.g.
                                    // DbConnector::get_instance()->get_db_link() is a PDO)
                                    return $this->followMethodChain($this->method_return_types[$lookup_key], $m);
                                }

                                // Convention: factory methods (GetBy*, Create*, get_by_*, Find*)
                                // typically return an instance of their own class
                                if (class_exists($class_name) && $this->isFactoryMethodName($method_name)) {
                                    return $this->followMethodChain($class_name, $m);
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
                                    return $this->followMethodChain($this->method_return_types[$lookup_key], $m);
                                }
                            }
                        }
                    }

                    // First non-whitespace token examined — whatever the
                    // branches above concluded is the answer. Never scan on.
                    break;
                }
            }

            // Stop if we hit something that's not part of the assignment
            break;
        }

        return null;
    }

    /**
     * For `foreach ($source as [$key =>] $value)`, when $source has a tracked
     * SystemMultiBase type, record $value as that collection's model class.
     */
    private function extractForeachValueType($index) {
        $count = count($this->tokens);
        $source_var = null;
        $value_var = null;
        $seen_as = false;
        for ($i = $index + 1; $i < $count; $i++) {
            $token = $this->tokens[$i];
            if ($token === ')') {
                break;
            }
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] === T_AS) {
                $seen_as = true;
            } elseif ($token[0] === T_VARIABLE) {
                if (!$seen_as) {
                    // Only track a simple `$source` (first variable, no expression)
                    if ($source_var === null) {
                        $source_var = $token[1];
                    }
                } else {
                    // Last variable before ')' — handles both `as $v` and `as $k => $v`
                    $value_var = $token[1];
                }
            }
        }
        if (!$source_var || !$value_var) {
            return;
        }
        // `foreach ($this as $x)` inside a Multi class iterates its models
        $source_class = null;
        if ($source_var === '$this') {
            $source_class = $this->current_class_name;
        } elseif (isset($this->variable_types[$source_var])) {
            $source_class = $this->variable_types[$source_var];
        }
        if ($source_class && class_exists($source_class) && is_subclass_of($source_class, 'SystemMultiBase')) {
            $props = (new ReflectionClass($source_class))->getStaticProperties();
            if (!empty($props['model_class'])) {
                $this->variable_types[$value_var] = $props['model_class'];
                return;
            }
        }
        // Unknown source: clear any stale mapping so a type tracked for this
        // variable name in an earlier function doesn't bleed into this loop
        unset($this->variable_types[$value_var]);
    }

    /**
     * Follow a chained method call to its final return type. Starting from a
     * method-name token whose call returns $type, skip that call's argument
     * list; if a further ->method() follows, map it through
     * $method_return_types. An unmapped link returns NULL — an unknown chain
     * must not leave the variable tracked with a wrong intermediate type.
     */
    private function followMethodChain($type, $method_index) {
        $count = count($this->tokens);
        $i = $this->getNextTokenIndex($method_index);
        if ($i === null || $this->tokens[$i] !== '(') {
            return $type; // not a call — nothing chained
        }
        // Skip the balanced argument list
        $paren_depth = 0;
        for (; $i < $count; $i++) {
            $token = $this->tokens[$i];
            if ($token === '(') {
                $paren_depth++;
            } elseif ($token === ')') {
                $paren_depth--;
                if ($paren_depth === 0) {
                    break;
                }
            }
        }
        $next = $this->getNextTokenIndex($i);
        if ($next === null || !is_array($this->tokens[$next]) || $this->tokens[$next][0] !== T_OBJECT_OPERATOR) {
            return $type; // chain ends here
        }
        $name_index = $this->getNextTokenIndex($next);
        if ($name_index === null || !is_array($this->tokens[$name_index]) || $this->tokens[$name_index][0] !== T_STRING) {
            return null; // dynamic member — unknown
        }
        $lookup_key = $type . '::' . $this->tokens[$name_index][1];
        if (!isset($this->method_return_types[$lookup_key])) {
            return null; // unmapped chain link — unknown
        }
        return $this->followMethodChain($this->method_return_types[$lookup_key], $name_index);
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
     * Check if a method name matches common factory method patterns.
     * Factory methods typically return an instance of their own class.
     * Patterns derived from actual codebase usage in data/ and includes/.
     */
    private function isFactoryMethodName($method_name) {
        $factory_prefixes = [
            'GetBy',        // GetByEmail, GetByColumn, GetByStripeCustomerId, etc.
            'CreateNew',    // CreateNew, CreateCompleteNew
            'CreateFrom',   // CreateFromForm
            'Create',       // CreateAddressFromForm, CreateLegacyTemplate
            'get_by_',      // get_by_link, get_by_slug, get_by_name, get_by_theme_name
            'GetDefault',   // GetDefaultAddressForUser
            'GetUser',      // GetUserTier
        ];

        foreach ($factory_prefixes as $prefix) {
            if (strpos($method_name, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a call matches a blacklist pattern.
     * Names match exactly (so 'start_buttons' cannot flag 'restart_buttons');
     * an entry ending in '::' is a class-wide prefix (e.g. 'CtldAccount::').
     */
    private function checkBlacklist($type, $pattern) {
        if (!isset($this->blacklist[$type])) {
            return null;
        }

        if (isset($this->blacklist[$type][$pattern])) {
            return $this->blacklist[$type][$pattern];
        }

        foreach ($this->blacklist[$type] as $blacklisted => $reason) {
            if (substr($blacklisted, -2) === '::' && strpos($pattern, $blacklisted) === 0) {
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
            } elseif ($this->isFunctionDefinedSomewhere($function_name)) {
                // Defined in a sibling logic/includes file the router loads at runtime
                $found++;
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

        $this->call_errors += $missing;
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
            $tracked = false;

            // 1. Use the type tracked at the call's position in the file
            // (assignment/foreach bindings in effect at that point)
            if (!empty($call['tracked_class'])) {
                $class_name = $call['tracked_class'];
                $tracked = true;
            }

            // 2. Try to infer from variable name
            $inferred_name = null;
            if ($var_name) {
                $inferred_name = $this->inferClassFromVariable($var_name);
            }
            if (!$class_name) {
                $class_name = $inferred_name;
            }

            if ($class_name) {
                // Class::method blacklist entries also apply to instance calls
                $blacklist_reason = $this->checkBlacklist('static', "$class_name::$method_name");
                if ($blacklist_reason) {
                    $blacklisted++;
                    $issues[] = sprintf("  🚫 Line %4d: %s->%s() - BLACKLISTED: %s",
                        $line, $var_name ?: '?', $method_name, $blacklist_reason);
                    continue;
                }

                if (class_exists($class_name)) {
                    // The class is loaded — method_exists is authoritative,
                    // no whitelist shortcut (a whitelist hit for a DIFFERENT
                    // class must not excuse a missing method on this one)
                    if (method_exists($class_name, $method_name)) {
                        $found++;
                    } elseif (!$tracked) {
                        // The class was only guessed from the variable's name —
                        // a guess is not evidence, so a miss is unverifiable,
                        // not an error
                        $unknown++;
                    } else {
                        $missing++;
                        $issues[] = sprintf("  ✗ Line %4d: %s->%s() [tracked class: %s]",
                            $line, $var_name ?: '?', $method_name, $class_name);
                    }
                } elseif ($this->isMethodWhitelisted($class_name, $method_name)) {
                    // Class not loadable in this environment — accept its
                    // documented method surface
                    $whitelisted++;
                } else {
                    // Class identified but not loadable and not documented —
                    // unverifiable
                    $unknown++;
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

        $this->call_errors += $missing;
        echo sprintf("✓ Found: %d  ✗ Missing: %d  🚫 Blacklisted: %d  ? Unknown: %d  ⊘ Whitelisted: %d\n\n",
            $found, $missing, $blacklisted, $unknown, $whitelisted);
    }

    /**
     * Check if a method is whitelisted for a class. Consulted only for
     * classes the validator could not load (loaded classes are verified with
     * method_exists directly), so this is a strict per-class lookup — a
     * method documented for one class never excuses a call on another.
     */
    private function isMethodWhitelisted($class_name, $method_name) {
        return isset($this->common_methods[$class_name]) &&
            in_array($method_name, $this->common_methods[$class_name]);
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
        $unknown = 0;
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

            // The extractor couldn't identify the class (e.g. $var::method(),
            // complex expressions) — unverifiable, not wrong
            if ($class_name === 'Unknown') {
                $unknown++;
                continue;
            }

            // Resolve class name — self/static/parent use the class context
            // captured at parse time
            if ($class_name === 'self' || $class_name === 'static') {
                $resolved_class = !empty($call['context_class']) ? $call['context_class'] : $class_name;
            } elseif ($class_name === 'parent') {
                $resolved_class = !empty($call['context_parent']) ? $call['context_parent'] : $class_name;
            } else {
                $resolved_class = $this->resolveClassName($class_name);
            }

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

        $this->call_errors += $missing;
        echo sprintf("\n✓ Found: %d  ✗ Missing: %d  🚫 Blacklisted: %d  ? Unknown: %d\n\n", $found, $missing, $blacklisted, $unknown);
    }

    /**
     * Resolve class name using namespace and use statements
     */
    private function resolveClassName($class_name) {
        // Handle self/static — refer to the class currently being parsed.
        if ($class_name === 'self' || $class_name === 'static') {
            return $this->current_class_name !== '' ? $this->current_class_name : $class_name;
        }
        // Handle parent — refer to the parent class captured at class declaration.
        if ($class_name === 'parent') {
            return $this->current_parent_class !== '' ? $this->current_parent_class : $class_name;
        }

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
     * Check constructor calls: a zero-argument `new X()` is an error when
     * X::__construct requires parameters. Covers every SystemBase model
     * (whose constructors require $key) and any other class with required
     * constructor parameters — verified via reflection, so no per-class
     * pattern list is needed.
     */
    private function checkConstructors() {
        if (empty($this->constructors)) {
            echo "No constructor calls found.\n\n";
            return;
        }

        echo "CONSTRUCTOR CALLS (" . count($this->constructors) . " total)\n";
        echo str_repeat("-", 80) . "\n";

        $found = 0;
        $missing = 0;
        $unknown = 0;
        $issues = [];

        foreach ($this->constructors as $call) {
            $class_name = $this->resolveClassName($call['name']);

            if (!class_exists($class_name)) {
                $unknown++;
                continue;
            }

            $constructor = (new ReflectionClass($class_name))->getConstructor();
            $required = $constructor ? $constructor->getNumberOfRequiredParameters() : 0;

            if (!empty($call['no_args']) && $required > 0) {
                $missing++;
                $hint = is_subclass_of($class_name, 'SystemBase')
                    ? " - use new {$class_name}(NULL) for new, new {$class_name}(\$id, TRUE) to load"
                    : '';
                $issues[] = sprintf("  ✗ Line %4d: new %s() requires %d argument(s)%s",
                    $call['line'], $call['name'], $required, $hint);
            } else {
                $found++;
            }
        }

        if (!empty($issues)) {
            echo "Issues found:\n";
            foreach ($issues as $issue) {
                echo $issue . "\n";
            }
        }

        $this->call_errors += $missing;
        echo sprintf("\n✓ OK: %d  ✗ Missing args: %d  ? Unknown class: %d\n\n", $found, $missing, $unknown);
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
     * Check for platform CSS-kit style-policy violations.
     *
     * Platform code styles through the .jy-ui kit, not ad-hoc CSS:
     *   - no inline style="..." attributes
     *   - no inline <style> blocks
     * Server-computed exceptions (e.g. a progress-bar width or the brand-token
     * block) are allowed when the line carries a jy-allow-style marker comment.
     * These are advisory and do not change the exit status.
     */
    private function checkStylePolicy() {
        echo "STYLE POLICY ANALYSIS\n";
        echo str_repeat("-", 80) . "\n";

        $lines = explode("\n", file_get_contents($this->file_path));
        $checks = [
            '~\bstyle\s*=\s*["\']~i' => "Inline style attribute — use a .jy-ui kit class instead",
            '~<style[\s>]~i'         => "Inline <style> block — move CSS into the kit or a stylesheet",
        ];

        $issues = [];
        foreach ($lines as $i => $line) {
            if (stripos($line, 'jy-allow-style') !== false) {
                continue; // explicit escape hatch for intentional, server-computed CSS
            }
            foreach ($checks as $pattern => $reason) {
                if (preg_match($pattern, $line)) {
                    $issues[] = sprintf("  ⚠️  Line %4d: %s", $i + 1, $reason);
                }
            }
        }

        if (!empty($issues)) {
            echo "Advisories (add a jy-allow-style comment to a line to silence an intentional, server-computed case):\n";
            foreach ($issues as $issue) {
                echo $issue . "\n";
            }
        } else {
            echo "✓ No style-policy advisories\n";
        }

        echo sprintf("\n⚠️  Total style-policy advisories: %d\n\n", count($issues));
    }

    /**
     * AI action descriptor contract. A *_logic_descriptor() function exposes
     * its action to the AI agent only if it declares an 'ai_agent' key
     * ('confirm' or 'auto') — default-deny. Surface the state of each
     * descriptor so a developer who means an action to be agent-callable
     * knows to opt it in; absent is a valid choice (keeps the action private).
     * Advisory only — does not change exit status.
     */
    private function checkDescriptorContract() {
        echo "ACTION DESCRIPTOR CONTRACT\n";
        echo str_repeat("-", 80) . "\n";

        $content = file_get_contents($this->file_path);
        if (!preg_match_all('/function\s+([a-zA-Z0-9_]+_logic_descriptor)\s*\(/', $content, $m)) {
            echo "✓ No action descriptors in this file\n\n";
            return;
        }

        $issues = [];
        foreach ($m[1] as $fn) {
            if (!function_exists($fn)) continue;
            try {
                $d = call_user_func($fn);
            } catch (Throwable $e) {
                continue;
            }
            if (!is_array($d)) continue;

            $action = substr($fn, 0, -strlen('_logic_descriptor'));
            $ai_agent = $d['ai_agent'] ?? null;
            $mutates = !empty($d['mutates']);

            if ($ai_agent === null) {
                $issues[] = sprintf("  ⚠️  %s%s: no 'ai_agent' key — NOT callable by the AI agent. "
                    . "Add 'ai_agent' => 'confirm' (or 'auto') to expose it, or leave as-is to keep it private.",
                    $action, $mutates ? ' (mutating)' : '');
            } elseif (!in_array($ai_agent, ['confirm', 'auto'], true)) {
                $issues[] = sprintf("  ✗ %s: invalid ai_agent '%s' — must be 'confirm' or 'auto'.",
                    $action, is_scalar($ai_agent) ? (string)$ai_agent : gettype($ai_agent));
            }
        }

        if (!empty($issues)) {
            echo "Advisories:\n";
            foreach ($issues as $issue) {
                echo $issue . "\n";
            }
        } else {
            echo "✓ All action descriptors declare a valid ai_agent tier\n";
        }

        echo sprintf("\n⚠️  Total descriptor advisories: %d\n\n", count($issues));
    }

    /**
     * Data-model structure contract. When the file declares SystemBase
     * subclasses, verify them against the platform model contract
     * (docs/example_class.php is the annotated reference):
     *
     *   ERRORS (✗ — nonzero exit code):
     *   - $prefix declared, exactly 3 lowercase letters
     *   - $tablename declared, lowercase snake_case, starts with {prefix}_
     *   - $pkey_column declared, starts with {prefix}_, present in
     *     $field_specifications, and flagged 'serial' => true
     *   - $field_specifications declared and non-empty; every column key is
     *     lowercase snake_case and starts with {prefix}_; every spec declares
     *     a 'type' accepted by DatabaseUpdater::acceptedColumnTypeRegex()
     *   - a SystemMultiBase subclass in the same file whose $model_class
     *     points back at the model, implementing getMultiResults()
     *   - every foreign-key-shaped column that resolves to a source table by
     *     naming convention has a declared action in $foreign_key_actions
     *     (an undeclared relationship registers as 'prevent' and refuses the
     *     source row's deletion; ModelTester fails the db tier for it)
     *
     *   ADVISORIES (⚠️ — reported, do not affect exit code):
     *   - $permanent_delete_actions declared on the class (nothing reads it)
     *   - Multi class not named Multi{Model}
     *   - $prefix shared with another loaded model class
     *
     * Checks run via reflection on the live classes (the file has already been
     * require'd), so they see exactly what SystemBase will see at runtime.
     */
    private function checkModelContract() {
        echo "DATA MODEL CONTRACT\n";
        echo str_repeat("-", 80) . "\n";

        // Collect classes declared by THIS file
        $real_path = realpath($this->file_path);
        $file_models = [];
        $file_multis = [];
        foreach (get_declared_classes() as $class) {
            $reflection = new ReflectionClass($class);
            if ($reflection->getFileName() !== $real_path || $reflection->isAbstract()) {
                continue;
            }
            if ($reflection->isSubclassOf('SystemBase')) {
                $file_models[$class] = $reflection;
            } elseif ($reflection->isSubclassOf('SystemMultiBase')) {
                $file_multis[$class] = $reflection;
            }
        }

        if (empty($file_models)) {
            echo "✓ No data-model classes in this file\n\n";
            return;
        }

        if (!class_exists('DatabaseUpdater')) {
            require_once(PathHelper::getIncludePath('includes/DatabaseUpdater.php'));
        }

        // Map model class => Multi class (within this file)
        $multi_by_model = [];
        foreach ($file_multis as $multi_class => $reflection) {
            $props = $reflection->getStaticProperties();
            if (!empty($props['model_class'])) {
                $multi_by_model[$props['model_class']] = $multi_class;
            }
        }

        // Maps across every loaded model: prefix collisions (advisory),
        // and prefix → tablename / tablename set for simulating the
        // deletion-rule auto-detector's source-table guess
        $prefix_owners = [];
        $prefix_tables = [];
        $all_tables = [];
        foreach (get_declared_classes() as $class) {
            $reflection = new ReflectionClass($class);
            if (!$reflection->isSubclassOf('SystemBase') || $reflection->isAbstract()) continue;
            $props = $reflection->getStaticProperties();
            if (!empty($props['tablename'])) {
                $all_tables[$props['tablename']] = $class;
                if (!empty($props['prefix'])
                    && !in_array($props['tablename'], $prefix_tables[$props['prefix']] ?? [], true)) {
                    // A prefix can be claimed by two models (bkt, cnv, rcp...);
                    // keep every claimant so the convention mirror can
                    // disambiguate the way DeletionRule does.
                    $prefix_tables[$props['prefix']][] = $props['tablename'];
                }
            }
            if (isset($file_models[$class])) continue;
            if (!empty($props['prefix'])) {
                $prefix_owners[$props['prefix']][] = $class;
            }
        }

        $errors = [];
        $advisories = [];
        $type_regex = DatabaseUpdater::acceptedColumnTypeRegex();

        foreach ($file_models as $class => $reflection) {
            $props = $reflection->getStaticProperties();
            $prefix = $props['prefix'] ?? null;
            $tablename = $props['tablename'] ?? null;
            $pkey = $props['pkey_column'] ?? null;
            $specs = $props['field_specifications'] ?? null;

            // $prefix — exactly 3 lowercase letters
            if (!is_string($prefix) || $prefix === '') {
                $errors[] = "$class: \$prefix is not declared";
                $prefix = null;
            } elseif (!preg_match('/^[a-z]{3}$/', $prefix)) {
                $errors[] = "$class: \$prefix '$prefix' must be exactly 3 lowercase letters";
            }

            // $tablename — snake_case, carries the prefix
            if (!is_string($tablename) || $tablename === '') {
                $errors[] = "$class: \$tablename is not declared";
            } else {
                if (!preg_match('/^[a-z][a-z0-9_]*$/', $tablename)) {
                    $errors[] = "$class: \$tablename '$tablename' must be lowercase snake_case";
                }
                if ($prefix && strpos($tablename, $prefix . '_') !== 0) {
                    $errors[] = "$class: \$tablename '$tablename' must start with '{$prefix}_' (the class prefix)";
                }
            }

            // $field_specifications — present, prefixed keys, valid types
            if (!is_array($specs) || empty($specs)) {
                $errors[] = "$class: \$field_specifications is missing or empty";
                $specs = [];
            }
            foreach ($specs as $column => $spec) {
                if (!preg_match('/^[a-z][a-z0-9_]*$/', $column)) {
                    $errors[] = "$class: column '$column' must be lowercase snake_case";
                }
                if ($prefix && strpos($column, $prefix . '_') !== 0) {
                    $errors[] = "$class: column '$column' must start with '{$prefix}_' (the class prefix)";
                }
                if (!is_array($spec) || empty($spec['type'])) {
                    $errors[] = "$class: column '$column' has no 'type'";
                } elseif (!preg_match($type_regex, trim($spec['type']))) {
                    $errors[] = "$class: column '$column' type '{$spec['type']}' is not an accepted column type "
                              . "(see DatabaseUpdater::acceptedColumnTypeRegex)";
                }
            }

            // $pkey_column — declared, prefixed, specified, serial
            if (!is_string($pkey) || $pkey === '') {
                $errors[] = "$class: \$pkey_column is not declared";
            } else {
                if ($prefix && strpos($pkey, $prefix . '_') !== 0) {
                    $errors[] = "$class: \$pkey_column '$pkey' must start with '{$prefix}_' (the class prefix)";
                }
                if ($specs && !isset($specs[$pkey])) {
                    $errors[] = "$class: \$pkey_column '$pkey' is not in \$field_specifications";
                } elseif ($specs && empty($specs[$pkey]['serial'])) {
                    $errors[] = "$class: primary key '$pkey' must declare 'serial' => true "
                              . "(int8 + serial is the platform primary-key convention)";
                }
            }

            // Multi collection class — required, in the same file
            if (!isset($multi_by_model[$class])) {
                $errors[] = "$class: no SystemMultiBase collection class in this file with "
                          . "\$model_class = '$class' (every model requires its Multi class)";
            } else {
                $multi_class = $multi_by_model[$class];
                if (!method_exists($multi_class, 'getMultiResults')) {
                    $errors[] = "$multi_class: getMultiResults() is not implemented";
                }
                if ($multi_class !== 'Multi' . $class) {
                    $advisories[] = "$multi_class: collection class is conventionally named Multi{$class}";
                }
            }

            // Deletion behaviour is declared by the child model in
            // $foreign_key_actions, so a model declaring $permanent_delete_actions
            // is stating a rule nothing reads (see docs/deletion_system.md).
            if ($reflection->hasProperty('permanent_delete_actions')
                && $reflection->getProperty('permanent_delete_actions')->getDeclaringClass()->getName() === $class) {
                $advisories[] = "$class: \$permanent_delete_actions is declared but nothing reads it "
                              . "— deletion behaviour belongs in the child model's \$foreign_key_actions "
                              . "(see docs/deletion_system.md)";
            }

            // Prefix collision with other loaded models (advisory)
            if ($prefix && !empty($prefix_owners[$prefix])) {
                $advisories[] = "$class: \$prefix '$prefix' is also used by "
                              . implode(', ', $prefix_owners[$prefix])
                              . " — prefer a unique prefix for new models";
            }

            // $foreign_key_actions overrides that don't auto-register. The
            // detector (data/deletion_rule_class.php) resolves a column's
            // source table by stripping the declaring model's own prefix and
            // looking up the remainder's first segment in a real
            // prefix -> tablename registry built from every loaded model
            // (never a guess). A key that neither resolves that way nor
            // declares an explicit 'source_table'/'source_class' override
            // will not register at all. See docs/deletion_system.md.
            $fk_actions = $props['foreign_key_actions'] ?? [];
            foreach ($fk_actions as $column => $override) {
                if (isset($override['source_table']) || isset($override['source_class'])) {
                    continue;
                }
                if ($this->resolvesFkColumnByConvention($column, $prefix, $prefix_tables)) {
                    continue;
                }
                $advisories[] = "$class: \$foreign_key_actions['$column'] does not resolve to a known source "
                              . "table by naming convention, and no 'source_table' or 'source_class' override is "
                              . "given — this rule will NOT register. Add 'source_table' (or 'source_class') to "
                              . "the override.";
            }

            // Every FK-shaped column that DOES resolve by convention must have
            // a declared action — an undeclared relationship registers as
            // 'prevent' and refuses the source row's deletion, and ModelTester
            // fails the db tier for it. Same rule, surfaced at edit time.
            foreach ($specs as $column => $spec) {
                if ($column === $pkey || array_key_exists($column, $fk_actions)) {
                    continue;
                }
                if ($this->resolvesFkColumnByConvention($column, $prefix, $prefix_tables)) {
                    $errors[] = "$class: column '$column' is a detected foreign key with no declared "
                              . "deletion action — add it to \$foreign_key_actions "
                              . "(see docs/deletion_system.md for choosing an action)";
                }
            }
        }

        if (!empty($errors)) {
            echo "Errors:\n";
            foreach ($errors as $error) {
                echo "  ✗ $error\n";
            }
        }
        if (!empty($advisories)) {
            echo ($errors ? "\n" : "") . "Advisories:\n";
            foreach ($advisories as $advisory) {
                echo "  ⚠️  $advisory\n";
            }
        }
        if (empty($errors) && empty($advisories)) {
            echo "✓ " . count($file_models) . " model class(es) satisfy the data-model contract\n";
        }

        $this->contract_errors += count($errors);
        echo sprintf("\n✗ Contract errors: %d  ⚠️  Advisories: %d\n\n", count($errors), count($advisories));
    }

    /**
     * Mirror of DeletionRule::getSourceTableFromColumn() so the model-contract
     * pass can predict whether the auto-detector will register a given FK
     * column: strip the declaring model's own prefix, then check whether the
     * remainder's first segment is a known model prefix and the remainder
     * contains '_id'. Must stay in sync with data/deletion_rule_class.php.
     */
    private function resolvesFkColumnByConvention($column, $own_prefix, $prefix_tables) {
        if (!$own_prefix) {
            return false;
        }
        $own_prefix_str = $own_prefix . '_';
        if (strpos($column, $own_prefix_str) !== 0) {
            return false;
        }
        $remainder = substr($column, strlen($own_prefix_str));
        if (strpos($remainder, '_id') === false) {
            return false;
        }
        $first_segment = strstr($remainder, '_', true);
        if ($first_segment === false || $first_segment === '') {
            return false;
        }
        $candidates = $prefix_tables[$first_segment] ?? [];
        if (count($candidates) === 1) {
            return true;
        }
        if (count($candidates) === 0) {
            return false;
        }
        // Ambiguous prefix: resolve only on an exact singular/plural entity
        // match, exactly as DeletionRule::getSourceTableFromColumn() does.
        $entity = substr($remainder, 0, strpos($remainder, '_id'));
        foreach ($candidates as $candidate) {
            if ($candidate === $entity || $candidate === $entity . 's') {
                return true;
            }
        }
        return false;
    }

    /**
     * Logic-file structure contract. Applied when the file lives in a logic/
     * directory and is named *_logic.php (docs/logic_architecture.md is the
     * reference):
     *
     *   ERRORS (✗ — nonzero exit code):
     *   - Core logic/ file must define its entry function, named exactly after
     *     the file basename. Plugin logic functions carry the plugin name
     *     (file profile_chat_logic.php in plugin joinery_ai may define
     *     profile_joinery_ai_chat_logic) — for plugins a missing entry is an
     *     advisory instead, because shared-helper logic files are legitimate.
     *   - The entry function takes a single required parameter ($input).
     *   - No exit()/die() anywhere in a logic file — every code path must
     *     return a LogicResult.
     *
     *   ADVISORIES (⚠️):
     *   - throw outside any lexical try block (uncontained throws should be
     *     converted to LogicResult::error(); throws caught in-function are
     *     accepted practice)
     *   - entry function missing the `array` parameter type or the
     *     `: LogicResult` return type
     */
    private function checkLogicContract() {
        echo "LOGIC FILE CONTRACT\n";
        echo str_repeat("-", 80) . "\n";

        $real = str_replace('\\', '/', realpath($this->file_path));
        if (!preg_match('~(?:^|/)(?:(plugins|theme)/([^/]+)/)?logic/([a-z0-9_]+_logic)\.php$~', $real, $m)) {
            echo "✓ Not a logic file\n\n";
            return;
        }
        $plugin = ($m[1] === 'plugins') ? $m[2] : '';
        $basename = $m[3];

        $errors = [];
        $advisories = [];

        // Locate the entry function: exact basename, or (plugins) the
        // basename with the plugin name inserted
        $entry = null;
        foreach ($this->extractGlobalFunctionNames($this->file_path) as $fn) {
            if ($fn === $basename || ($plugin && str_replace($plugin . '_', '', $fn) === $basename)) {
                $entry = $fn;
                break;
            }
        }

        if ($entry === null) {
            $message = "no entry function for '$basename.php' — expected {$basename}()"
                . ($plugin ? " (or the route-named variant carrying '{$plugin}')" : '');
            if ($plugin) {
                $advisories[] = "$message. Fine for a shared-helper logic file; a page logic file needs it.";
            } else {
                $errors[] = $message;
            }
        } elseif (function_exists($entry)) {
            $reflection = new ReflectionFunction($entry);
            if ($reflection->getNumberOfRequiredParameters() > 1) {
                $errors[] = "{$entry}() must take a single \$input array (merged GET/POST/route params) — "
                          . $reflection->getNumberOfRequiredParameters() . " required parameters found";
            }
            $params = $reflection->getParameters();
            if (isset($params[0])) {
                $param_type = $params[0]->getType();
                if (!$param_type || (string)$param_type !== 'array') {
                    $advisories[] = "{$entry}() first parameter should be typed `array \$input`";
                }
            }
            $return_type = $reflection->getReturnType();
            if (!$return_type || (string)$return_type !== 'LogicResult') {
                $advisories[] = "{$entry}() should declare `: LogicResult` as its return type";
            }
        }

        // exit()/die() are forbidden in logic files; throw is accepted only
        // inside a lexical try block (locally contained)
        $depth = 0;
        $try_depths = [];
        foreach ($this->tokens as $token) {
            if (!is_array($token)) {
                if ($token === '{') {
                    $depth++;
                } elseif ($token === '}') {
                    while ($try_depths && end($try_depths) > $depth) {
                        array_pop($try_depths);
                    }
                    $depth--;
                }
                continue;
            }
            if ($token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
                continue;
            }
            if ($token[0] === T_TRY) {
                $try_depths[] = $depth + 1;
            } elseif ($token[0] === T_EXIT) {
                $errors[] = "line {$token[2]}: " . trim($token[1]) . "() is forbidden in logic files — "
                          . "return LogicResult::redirect()/error() instead (see docs/logic_architecture.md)";
            } elseif ($token[0] === T_THROW && empty($try_depths)) {
                $advisories[] = "line {$token[2]}: uncontained throw — return LogicResult::error() "
                              . "or contain it in a try block";
            }
        }

        if (!empty($errors)) {
            echo "Errors:\n";
            foreach ($errors as $error) {
                echo "  ✗ $error\n";
            }
        }
        if (!empty($advisories)) {
            echo ($errors ? "\n" : "") . "Advisories:\n";
            foreach ($advisories as $advisory) {
                echo "  ⚠️  $advisory\n";
            }
        }
        if (empty($errors) && empty($advisories)) {
            echo "✓ Logic file satisfies the structure contract" . ($entry ? " (entry: {$entry})" : "") . "\n";
        }

        $this->contract_errors += count($errors);
        echo sprintf("\n✗ Contract errors: %d  ⚠️  Advisories: %d\n\n", count($errors), count($advisories));
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
    echo "Usage: php validate_php_file.php <path_to_php_file>\n";
    echo "\n";
    echo "Examples:\n";
    echo "  php validate_php_file.php /var/www/html/joinerytest/public_html/logic/profile_logic.php\n";
    echo "  php validate_php_file.php ../../public_html/adm/admin_users.php\n";
    exit(1);
}

$file_path = $argv[1];

// Convert relative path to absolute if needed
if ($file_path[0] !== '/') {
    $file_path = getcwd() . '/' . $file_path;
}

try {
    $tester = new MethodExistenceTest($file_path);
    $contract_errors = $tester->analyze();
    // Model-contract violations are hard failures; other findings are
    // reported in the output but do not change the exit status.
    exit($contract_errors > 0 ? 1 : 0);
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
