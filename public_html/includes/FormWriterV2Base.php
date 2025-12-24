<?php
/**
 * FormWriter v2 Base Class
 *
 * Modern form generation with clean API, automatic CSRF protection,
 * unified validation, and intelligent auto-detection.
 *
 * Phase 1: Standalone implementation (no breaking changes to v1)
 *
 * @version 2.1.0
 * @changelog 2.1.0 - Added automatic edit_primary_key_value hidden field support
 */

abstract class FormWriterV2Base {
    protected $form_id;
    protected $options;
    protected $fields = [];
    protected $csrf_token;
    protected $validation_rules = [];
    protected $validator;
    protected $values = [];
    protected $errors = [];
    protected $model_validated_fields = [];  // Track fields using automatic model validation
    protected $use_deferred_output = false;  // Deferred output mode flag
    protected $deferred_output = [];  // Collected field HTML when in deferred mode
    protected $edit_primary_key_value = null;  // Store edit key for automatic hidden field

    // Static property for custom validators
    protected static $custom_validators = [];

    // Static property for model prefix map (cached)
    protected static $model_prefix_map = null;

    /**
     * Constructor
     *
     * @param string $form_id Unique form identifier
     * @param array $options Form options including action, method, values, csrf, etc.
     */
    public function __construct($form_id, $options = []) {
        $this->form_id = $form_id;
        $this->options = array_merge($this->getDefaultOptions(), $options);

        // Auto-detect form action if not specified
        // If action is empty, default to current page (without .php extension)
        if (empty($this->options['action'])) {
            $this->options['action'] = $this->getDefaultFormAction();
        }

        // Enable deferred output mode if requested
        $this->use_deferred_output = $options['deferred_output'] ?? false;

        // Initialize validator
        require_once(PathHelper::getIncludePath('includes/Validator.php'));
        $this->validator = new Validator();

        // Build values from model and/or values array
        $final_values = [];

        // First, extract values from model if provided
        if (isset($this->options['model'])) {
            $model = $this->options['model'];

            // Check if model has export_as_array method
            if (is_object($model) && method_exists($model, 'export_as_array')) {
                $final_values = $model->export_as_array();
            } else {
                throw new Exception('FormWriterV2: model option must be an object with export_as_array() method');
            }
        }

        // Second, merge in additional values array if provided (values override model)
        if (isset($this->options['values']) && is_array($this->options['values'])) {
            $final_values = array_merge($final_values, $this->options['values']);
        }

        // Store merged values
        $this->values = $final_values;

        // Handle edit_primary_key_value for automatic hidden field
        if (isset($this->options['edit_primary_key_value']) && $this->options['edit_primary_key_value'] !== null) {
            $this->edit_primary_key_value = $this->options['edit_primary_key_value'];
        }

        // Apply automatic local time conversion to timestamp fields
        $this->convertDateTimeFieldsToLocalTime();

        // Initialize CSRF if needed
        $this->initializeCSRF();
    }

    /**
     * Convert UTC DateTime objects to user's local timezone
     *
     * Converts any DateTime objects in values from UTC to user's timezone.
     * DateTime objects are created by export_as_array() with UTC timezone already set.
     *
     * Converts from UTC to the user's local timezone for display.
     */
    protected function convertDateTimeFieldsToLocalTime() {
        // Need session for timezone info
        try {
            $session = SessionControl::get_instance();
            $user_timezone = $session->get_timezone();
        } catch (Exception $e) {
            // If session not available, skip conversion
            return;
        }

        // Convert any DateTime objects from UTC to user's timezone
        foreach ($this->values as $key => &$value) {
            // Skip null or empty values
            if ($value === null || $value === '') {
                continue;
            }

            // Convert DateTime objects from UTC to user's timezone
            if ($value instanceof DateTime) {
                // Only convert if timezone is UTC (skip if already in another timezone)
                if ($value->getTimezone()->getName() === 'UTC') {
                    try {
                        $value = LibraryFunctions::convert_time(
                            $value,
                            'UTC',
                            $user_timezone,
                            'Y-m-d H:i:s'
                        );
                    } catch (Exception $e) {
                        // If conversion fails, leave value unchanged
                    }
                }
            }
        }
        unset($value);  // Unset reference
    }

    /**
     * Get default form options
     *
     * @return array Default options
     */
    protected function getDefaultOptions() {
        return [
            'method' => 'POST',
            'action' => '',
            'csrf' => null,  // Will be set based on method if not specified
            'csrf_lifetime' => 7200,  // 2 hours
            'csrf_field' => '_csrf_token',
            'csrf_error' => 'Security validation failed. Please refresh and try again.',
            'validation' => true,  // Validation enabled by default
            'class' => '',
            'enctype' => null,
            'debug' => false  // Debug mode - outputs validation details to console
        ];
    }

    /**
     * Get default form action based on current page
     *
     * Automatically determines the form's action attribute if none is specified.
     * Returns the current page URL without the .php extension (for routing compatibility).
     *
     * @return string The form action URL
     */
    protected function getDefaultFormAction() {
        // Get the current request URI from $_SERVER
        // The routing system removes .php extensions, so we need to reconstruct the URL

        if (isset($_SERVER['REQUEST_URI'])) {
            $uri = $_SERVER['REQUEST_URI'];

            // Remove query string if present
            $uri = strtok($uri, '?');

            // Remove .php extension if present (for backward compatibility with direct .php calls)
            if (substr($uri, -4) === '.php') {
                $uri = substr($uri, 0, -4);
            }

            return $uri;
        }

        // Fallback: return empty string (form posts to itself)
        return '';
    }

    /**
     * Initialize CSRF token
     */
    protected function initializeCSRF() {
        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Default CSRF to true for POST, false for GET if not specified
        if ($this->options['csrf'] === null) {
            $this->options['csrf'] = ($this->options['method'] === 'POST');
        }

        // Generate token only if CSRF is enabled
        if ($this->options['csrf'] === true) {
            $this->csrf_token = bin2hex(random_bytes(32));

            // Initialize session array if needed
            if (!isset($_SESSION['csrf_tokens'])) {
                $_SESSION['csrf_tokens'] = [];
            }

            $_SESSION['csrf_tokens'][$this->form_id] = [
                'token' => $this->csrf_token,
                'expires' => time() + $this->options['csrf_lifetime']
            ];

            // Clean up expired tokens
            $this->cleanExpiredCSRFTokens();
        }
    }

    /**
     * Clean up expired CSRF tokens from session
     */
    protected function cleanExpiredCSRFTokens() {
        if (isset($_SESSION['csrf_tokens'])) {
            foreach ($_SESSION['csrf_tokens'] as $form_id => $data) {
                if ($data['expires'] < time()) {
                    unset($_SESSION['csrf_tokens'][$form_id]);
                }
            }
        }
    }

    /**
     * Validate CSRF token
     *
     * @param array $data Form data ($_POST)
     * @return bool True if valid, false otherwise
     */
    public function validateCSRF($data) {
        // Skip if CSRF is disabled
        if ($this->options['csrf'] !== true) {
            return true;
        }

        $field_name = $this->options['csrf_field'];
        $token = $data[$field_name] ?? '';

        // Check if token exists in session
        if (!isset($_SESSION['csrf_tokens'][$this->form_id])) {
            return false;
        }

        $stored = $_SESSION['csrf_tokens'][$this->form_id];

        // Check if expired
        if ($stored['expires'] < time()) {
            unset($_SESSION['csrf_tokens'][$this->form_id]);
            return false;
        }

        // Validate token using hash_equals to prevent timing attacks
        $valid = hash_equals($stored['token'], $token);

        // Clear token after use (one-time use)
        if ($valid) {
            unset($_SESSION['csrf_tokens'][$this->form_id]);
        }

        return $valid;
    }

    /**
     * Validate form data
     *
     * @param array $data Form data to validate
     * @return bool True if no errors, false if there are errors
     */
    public function validate($data) {
        // Clear previous errors
        $this->errors = [];

        // Check if validation is disabled at form level
        if (isset($this->options['validation']) && $this->options['validation'] === false) {
            return true;  // Validation skipped, no errors
        }

        foreach ($this->fields as $field) {
            // Skip fields with validation disabled
            if (empty($field['validation']) || $field['validation'] === false) {
                continue;
            }

            $field_name = $field['name'];
            $field_value = $data[$field_name] ?? null;

            // Validate field
            $field_errors = $this->validateField($field_name, $field_value, $field['validation'], $data);

            if (!empty($field_errors)) {
                $this->errors[$field_name] = $field_errors;
            }
        }

        // Return true if no errors, false if there are errors
        return empty($this->errors);
    }

    /**
     * Validate a single field
     *
     * @param string $field_name Field name
     * @param mixed $value Field value
     * @param array $rules Validation rules
     * @param array $all_data All form data (for field comparison)
     * @return array Array of error messages
     */
    protected function validateField($field_name, $value, $rules, $all_data = []) {
        $errors = [];

        // Handle string validation shorthand (e.g., 'email')
        if (is_string($rules)) {
            $rules = $this->getTypeValidation($rules);
        }

        foreach ($rules as $rule => $param) {
            switch ($rule) {
                case 'required':
                    if ($param && empty($value) && $value !== '0') {
                        $message = $rules['messages']['required'] ?? $field_name . ' is required';
                        $errors[] = $message;
                    }
                    break;

                case 'email':
                    if ($param && !empty($value)) {
                        if (!$this->validator->validateEmail($value, $field_name, $rules['messages']['email'] ?? null)) {
                            $errors[] = end($this->validator->errors);
                        }
                    }
                    break;

                case 'zip':
                    if ($param && !empty($value)) {
                        if (!$this->validator->validateZip($value, $field_name, $rules['messages']['zip'] ?? null)) {
                            $errors[] = end($this->validator->errors);
                        }
                    }
                    break;

                case 'phone':
                    if ($param && !empty($value)) {
                        if (!$this->validator->validatePhone($value, $field_name, $rules['messages']['phone'] ?? null)) {
                            $errors[] = end($this->validator->errors);
                        }
                    }
                    break;

                case 'number':
                    if ($param && !empty($value)) {
                        if (!$this->validator->validateNumber($value, $field_name, $rules['messages']['number'] ?? null)) {
                            $errors[] = end($this->validator->errors);
                        }
                    }
                    break;

                case 'date':
                    if ($param && !empty($value)) {
                        if (!$this->validator->validateDate($value, $field_name, $rules['messages']['date'] ?? null)) {
                            $errors[] = end($this->validator->errors);
                        }
                    }
                    break;

                case 'url':
                    if ($param && !empty($value)) {
                        if (!$this->validator->validateURL($value, $field_name, $rules['messages']['url'] ?? null)) {
                            $errors[] = end($this->validator->errors);
                        }
                    }
                    break;

                case 'ssn':
                    if ($param && !empty($value)) {
                        if (!$this->validator->validateSSN($value, $field_name, $rules['messages']['ssn'] ?? null)) {
                            $errors[] = end($this->validator->errors);
                        }
                    }
                    break;

                case 'ein':
                    if ($param && !empty($value)) {
                        if (!$this->validator->validateEIN($value, $field_name, $rules['messages']['ein'] ?? null)) {
                            $errors[] = end($this->validator->errors);
                        }
                    }
                    break;

                case 'credit_card':
                    if ($param && !empty($value)) {
                        if (!$this->validator->validateCard($value, $field_name, $rules['messages']['credit_card'] ?? null)) {
                            $errors[] = end($this->validator->errors);
                        }
                    }
                    break;

                case 'minlength':
                    if (!empty($value) && strlen($value) < $param) {
                        $errors[] = $rules['messages']['minlength'] ?? "Must be at least {$param} characters";
                    }
                    break;

                case 'maxlength':
                    if (!empty($value) && strlen($value) > $param) {
                        $errors[] = $rules['messages']['maxlength'] ?? "Must be no more than {$param} characters";
                    }
                    break;

                case 'min':
                    if (!empty($value) && is_numeric($value) && $value < $param) {
                        $errors[] = $rules['messages']['min'] ?? "Must be at least {$param}";
                    }
                    break;

                case 'max':
                    if (!empty($value) && is_numeric($value) && $value > $param) {
                        $errors[] = $rules['messages']['max'] ?? "Must be no more than {$param}";
                    }
                    break;

                case 'pattern':
                    if (!empty($value) && !preg_match($param, $value)) {
                        $errors[] = $rules['messages']['pattern'] ?? "Invalid format";
                    }
                    break;

                case 'matches':
                    // Field comparison - $param is the field name to match
                    $compare_value = $all_data[$param] ?? null;
                    if ($value !== $compare_value) {
                        $errors[] = $rules['messages']['matches'] ?? "Does not match {$param}";
                    }
                    break;

                case 'unique':
                    // Database uniqueness check
                    if (!empty($value) && is_array($param)) {
                        $table = $param['table'];
                        $column = $param['column'];
                        $exclude_id = $param['exclude_id'] ?? null;

                        $dbconnector = DbConnector::get_instance();
                        $dblink = $dbconnector->get_db_link();

                        if ($exclude_id) {
                            $sql = "SELECT COUNT(*) as count FROM {$table} WHERE {$column} = ? AND " .
                                   (isset($param['id_column']) ? $param['id_column'] : 'id') . " != ?";
                            $q = $dblink->prepare($sql);
                            $q->execute([$value, $exclude_id]);
                        } else {
                            $sql = "SELECT COUNT(*) as count FROM {$table} WHERE {$column} = ?";
                            $q = $dblink->prepare($sql);
                            $q->execute([$value]);
                        }

                        $result = $q->fetch(PDO::FETCH_ASSOC);
                        if ($result['count'] > 0) {
                            $errors[] = $rules['messages']['unique'] ?? "This value is already in use";
                        }
                    }
                    break;

                case 'custom':
                    // Custom validator function
                    if (is_callable($param)) {
                        try {
                            $result = call_user_func($param, $value, $field_name);
                            if ($result !== true) {
                                $errors[] = $rules['messages']['custom'] ?? "Validation failed";
                            }
                        } catch (Exception $e) {
                            $errors[] = $e->getMessage();
                        }
                    } elseif (is_string($param) && isset(self::$custom_validators[$param])) {
                        // Named custom validator
                        $custom_rules = self::$custom_validators[$param];
                        $custom_errors = $this->validateField($field_name, $value, $custom_rules, $all_data);
                        $errors = array_merge($errors, $custom_errors);
                    }
                    break;

                case 'messages':
                    // Skip - this is just the messages array
                    break;
            }
        }

        return $errors;
    }

    /**
     * Get validation rules for a type shorthand
     *
     * @param string $type Type shorthand (e.g., 'email', 'phone')
     * @return array Validation rules
     */
    protected function getTypeValidation($type) {
        // Map of types to validation rules
        static $type_validators = [
            'email' => ['email' => true],
            'url' => ['url' => true],
            'zip' => ['zip' => true],
            'phone' => ['phone' => true],
            'date' => ['date' => true],
            'number' => ['number' => true],
            'ssn' => ['ssn' => true],
            'ein' => ['ein' => true],
            'credit_card' => ['credit_card' => true],
        ];

        // Check if it's a registered custom validator
        if (isset(self::$custom_validators[$type])) {
            return self::$custom_validators[$type];
        }

        return $type_validators[$type] ?? [];
    }

    /**
     * Register a custom named validator
     *
     * @param string $name Validator name
     * @param array $rules Validation rules
     */
    public static function registerValidator($name, $rules) {
        self::$custom_validators[$name] = $rules;
    }

    /**
     * Auto-detect model from field naming convention
     *
     * @param string $field_name Field name (e.g., 'usr_email')
     * @return string|null Model class name or null
     */
    protected function detectModelFromFieldName($field_name) {
        // Extract prefix (e.g., 'usr_' from 'usr_email')
        if (!preg_match('/^([a-z]+)_/', $field_name, $matches)) {
            if (!empty($this->options['debug'])) {
                error_log("[FormWriterV2 DEBUG] detectModelFromFieldName($field_name): No prefix found");
            }
            return null;  // No prefix found
        }

        $prefix = $matches[1];

        // Get prefix map (cached for performance)
        $prefix_map = $this->getModelPrefixMap();

        if (!empty($this->options['debug'])) {
            error_log("[FormWriterV2 DEBUG] detectModelFromFieldName($field_name): Prefix=$prefix | Prefix map: " . json_encode($prefix_map));
        }

        $model_name = $prefix_map[$prefix] ?? null;

        if (!empty($this->options['debug'])) {
            error_log("[FormWriterV2 DEBUG] detectModelFromFieldName($field_name): Model name=$model_name | Class exists: " . (class_exists($model_name) ? 'YES' : 'NO'));
        }

        if ($model_name && class_exists($model_name)) {
            // Verify the field actually exists in this model
            if (isset($model_name::$field_specifications[$field_name])) {
                if (!empty($this->options['debug'])) {
                    error_log("[FormWriterV2 DEBUG] detectModelFromFieldName($field_name): ✓ Field found in model $model_name");
                }
                return $model_name;
            } else {
                if (!empty($this->options['debug'])) {
                    error_log("[FormWriterV2 DEBUG] detectModelFromFieldName($field_name): ✗ Field NOT found in model $model_name. Available fields: " . json_encode(array_keys($model_name::$field_specifications)));
                }
            }
        }

        return null;  // No matching model found
    }

    /**
     * Build or retrieve the prefix-to-model mapping
     *
     * @return array Prefix to model class name mapping
     */
    protected function getModelPrefixMap() {
        if (self::$model_prefix_map === null) {
            self::$model_prefix_map = [];

            // Auto-discover by scanning /data directory
            $data_files = glob(PathHelper::getIncludePath('data/*_class.php'));

            foreach ($data_files as $file) {
                // Extract class name from filename
                $basename = basename($file, '_class.php');

                // Convert to class name, handling plural filenames -> singular class names
                // e.g., 'users' -> 'User', 'locations' -> 'Location', 'event_registrants' -> 'EventRegistrant'
                $class_name = str_replace(' ', '', ucwords(str_replace('_', ' ', $basename)));

                // Try to load the class
                if (!class_exists($class_name)) {
                    require_once($file);
                }

                // If plural class name doesn't exist, try singular version (remove trailing 's')
                $singular_class = $class_name;
                if (!class_exists($class_name) && substr($class_name, -1) === 's') {
                    $singular_class = substr($class_name, 0, -1);
                    if (!class_exists($singular_class)) {
                        require_once($file);
                    }
                }

                // Add to map if class exists and has prefix (prefer singular version)
                if (class_exists($singular_class) && isset($singular_class::$prefix)) {
                    self::$model_prefix_map[$singular_class::$prefix] = $singular_class;
                } elseif (class_exists($class_name) && isset($class_name::$prefix)) {
                    self::$model_prefix_map[$class_name::$prefix] = $class_name;
                }
            }
        }

        return self::$model_prefix_map;
    }

    /**
     * Get validation rules from model field specifications
     *
     * @param string $model_class Model class name
     * @param string $field_name Field name
     * @return array Validation rules
     */
    protected function getModelValidation($model_class, $field_name) {
        if (!class_exists($model_class)) {
            if (!empty($this->options['debug'])) {
                error_log("[FormWriterV2 DEBUG] getModelValidation($model_class, $field_name): Class doesn't exist");
            }
            return [];
        }

        $field_specs = $model_class::$field_specifications[$field_name] ?? [];

        if (!empty($this->options['debug'])) {
            error_log("[FormWriterV2 DEBUG] getModelValidation($model_class, $field_name): Field specs: " . json_encode($field_specs));
        }

        // Build validation array from field_specifications
        $validation = $field_specs['validation'] ?? [];

        // Add legacy properties to validation
        if (isset($field_specs['required']) && $field_specs['required']) {
            $validation['required'] = true;
        }

        if (isset($field_specs['unique']) && $field_specs['unique']) {
            $validation['unique'] = [
                'table' => $model_class::$tablename,
                'column' => $field_name
            ];
        }

        if (!empty($this->options['debug'])) {
            error_log("[FormWriterV2 DEBUG] getModelValidation($model_class, $field_name): Final validation: " . json_encode($validation));
        }

        return $validation;
    }

    /**
     * Get all validation rules for JavaScript output
     *
     * @return array Field validation rules
     */
    protected function getAllValidationRules() {
        $rules = [];
        foreach ($this->fields as $field) {
            if (!empty($field['validation']) && $field['validation'] !== false) {
                $rules[$field['name']] = $field['validation'];
            }
        }

        // Debug output
        if (!empty($this->options['debug'])) {
            error_log("[FormWriterV2 DEBUG] getAllValidationRules() collected rules for " . count($rules) . " fields: " . json_encode(array_keys($rules)));
            foreach ($rules as $fieldName => $fieldRules) {
                error_log("[FormWriterV2 DEBUG]   - $fieldName: " . json_encode($fieldRules));
            }
        }

        return $rules;
    }

    // Error handling methods

    /**
     * Check if there are any validation errors
     *
     * @return bool True if errors exist
     */
    public function hasErrors() {
        return !empty($this->errors);
    }

    /**
     * Get all validation errors
     *
     * @return array All errors
     */
    public function getErrors() {
        return $this->errors;
    }

    /**
     * Get errors for a specific field
     *
     * @param string $field Field name
     * @return array Field errors
     */
    public function getFieldErrors($field) {
        return $this->errors[$field] ?? [];
    }

    /**
     * Set errors manually
     *
     * @param array $errors Errors array
     */
    public function setErrors($errors) {
        $this->errors = $errors;
    }

    /**
     * Add an error for a specific field
     *
     * @param string $field Field name
     * @param string $message Error message
     */
    public function addError($field, $message) {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = [];
        }
        $this->errors[$field][] = $message;
    }

    /**
     * Clear all errors
     */
    public function clearErrors() {
        $this->errors = [];
    }

    /**
     * Check if form is valid (for compatibility)
     *
     * @return bool True if valid
     */
    public function isValid() {
        return !$this->hasErrors();
    }

    // Field creation methods - consistent signature: ($name, $label = '', $options = [])

    /**
     * Create a text input field
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    public function textinput($name, $label = '', $options = []) {
        $this->registerField($name, 'text', $label, $options);
        $this->outputTextInput($name, $label, $options);
    }

    /**
     * Create a password input field
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    public function passwordinput($name, $label = '', $options = []) {
        $this->registerField($name, 'password', $label, $options);
        $this->outputPasswordInput($name, $label, $options);
    }

    /**
     * Create a textarea field
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    /**
     * Create a select dropdown field
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options (must include 'options' key with select options)
     */
    public function dropinput($name, $label = '', $options = []) {
        // Validate option format in debug mode
        if (isset($options['options'])) {
            $this->validateOptionFormat($options['options'], "dropinput('$name')");
        }

        $this->registerField($name, 'select', $label, $options);
        $this->outputDropInput($name, $label, $options);
    }

    /**
     * Create a checkbox input field
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    public function checkboxinput($name, $label = '', $options = []) {
        $this->registerField($name, 'checkbox', $label, $options);
        $this->outputCheckboxInput($name, $label, $options);
    }

    /**
     * Create a radio input field
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options (must include 'options' key with radio options)
     */
    public function radioinput($name, $label = '', $options = []) {
        // Validate option format in debug mode
        if (isset($options['options'])) {
            $this->validateOptionFormat($options['options'], "radioinput('$name')");
        }

        $this->registerField($name, 'radio', $label, $options);
        $this->outputRadioInput($name, $label, $options);
    }

    /**
     * Create a checkbox list (multiple checkboxes with same name)
     *
     * @param string $name Field name (will be submitted as array name[])
     * @param string $label Field label
     * @param array $options Field options including 'options', 'checked_values', 'disabled_values', 'readonly_values'
     */
    public function checkboxList($name, $label = '', $options = []) {
        // Validate option format in debug mode
        if (isset($options['options'])) {
            $this->validateOptionFormat($options['options'], "checkboxList('$name')");
        }

        $this->registerField($name, 'checkboxlist', $label, $options);
        $this->outputCheckboxList($name, $label, $options);
    }

    /**
     * Create a date input field
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    public function dateinput($name, $label = '', $options = []) {
        $this->registerField($name, 'date', $label, $options);
        $this->outputDateInput($name, $label, $options);
    }

    /**
     * Create a time input field
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    public function timeinput($name, $label = '', $options = []) {
        $this->registerField($name, 'time', $label, $options);
        $this->outputTimeInput($name, $label, $options);
    }

    /**
     * Create separate date and time input fields
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options (includes date_name, time_name for separate fields)
     */
    public function datetimeinput($name, $label = '', $options = []) {
        // Create unique field names for the date and time inputs
        $date_name = $name . '_dateinput';
        $time_name = $name . '_timeinput';

        // Auto-parse datetime value from database if it exists as a single field
        // Use PHP DateTime for maximum compatibility with various database datetime formats
        if (isset($this->values[$name]) && !isset($options['value']) && !isset($options['date_value'])) {
            $datetime_value = $this->values[$name];
            if ($datetime_value) {
                try {
                    // If it's already a DateTime object, use it directly
                    if ($datetime_value instanceof DateTime) {
                        $dt = $datetime_value;
                    } else {
                        // Otherwise create a DateTime object from the string
                        $dt = new DateTime($datetime_value);
                    }
                    $options['date_value'] = $dt->format('Y-m-d');
                    $options['time_value'] = $dt->format('H:i');  // 24-hour format for parsing
                } catch (Exception $e) {
                    // If DateTime parsing fails, fall back to existing behavior
                    if (is_string($datetime_value) && strpos($datetime_value, ' ') !== false) {
                        list($date_part, $time_part) = explode(' ', $datetime_value, 2);
                        $options['date_value'] = $date_part;
                        $options['time_value'] = $time_part;
                    }
                }
            }
        }

        $this->registerField($date_name, 'date', $label ? $label . ' (Date)' : '', $options);
        $this->registerField($time_name, 'time', $label ? $label . ' (Time)' : '', $options);

        $this->outputDateTimeInput($name, $label, $options);
    }

    /**
     * Create a file input field
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    public function fileinput($name, $label = '', $options = []) {
        $this->registerField($name, 'file', $label, $options);
        $this->outputFileInput($name, $label, $options);
    }

    /**
     * Create a hidden input field
     *
     * @param string $name Field name
     * @param string $label Field label (ignored for hidden fields)
     * @param array $options Field options
     */
    public function hiddeninput($name, $label = '', $options = []) {
        $this->registerField($name, 'hidden', '', $options);
        $this->outputHiddenInput($name, $options);
    }

    /**
     * Create a submit button
     *
     * @param string $name Button name (defaults to 'submit')
     * @param string $label Button label
     * @param array $options Button options
     */
    public function submitbutton($name = 'submit', $label = 'Submit', $options = []) {
        // If $name is empty string, use 'submit'
        if (!$name) $name = 'submit';

        $this->outputSubmitButton($name, $label, $options);
    }

    /**
     * Create a rich text editor field (textbox)
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    public function textbox($name, $label = '', $options = []) {
        $this->registerField($name, 'textbox', $label, $options);
        $this->outputTextbox($name, $label, $options);
    }

    /**
     * Create an image input/selection field
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    public function imageinput($name, $label = '', $options = []) {
        $this->registerField($name, 'image', $label, $options);
        $this->outputImageInput($name, $label, $options);
    }

    /**
     * Create a textarea field
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    public function textarea($name, $label = '', $options = []) {
        $this->registerField($name, 'textarea', $label, $options);
        $this->outputTextarea($name, $label, $options);
    }

    /**
     * Register a field for validation tracking
     *
     * @param string $name Field name
     * @param string $input_type HTML input type
     * @param string $label Field label
     * @param array $options Field options
     */
    protected function registerField($name, $input_type, $label, &$options) {
        // Auto-fill value from values array if not explicitly provided
        if (!isset($options['value']) && isset($this->values[$name])) {
            $options['value'] = $this->values[$name];
        }

        $model_class = null;

        // Determine model class
        if (isset($options['model']) && $options['model'] === false) {
            // Explicitly disabled auto-detection
            $model_class = null;
            unset($options['model']);
        } else {
            // Auto-detect from field prefix
            $model_class = $this->detectModelFromFieldName($name);
        }

        // Handle validation options
        if (isset($options['validation']) && $options['validation'] === false) {
            // Explicitly disabled validation
            $options['validation'] = [];
        } else {
            // Handle string validation shorthand (e.g., 'validation' => 'email')
            if (isset($options['validation']) && is_string($options['validation'])) {
                $options['validation'] = $this->getTypeValidation($options['validation']);
            }

            // Start with model validation if available
            $base_validation = [];
            if ($model_class) {
                $base_validation = $this->getModelValidation($model_class, $name);

                // Track when model validation is applied
                if (!empty($base_validation)) {
                    $this->model_validated_fields[$name] = [
                        'model' => $model_class,
                        'rules' => $base_validation
                    ];
                }
            }

            // Merge with any provided validation (provided takes precedence)
            if (isset($options['validation']) && is_array($options['validation'])) {
                $options['validation'] = array_merge($base_validation, $options['validation']);
            } else if (!isset($options['validation'])) {
                $options['validation'] = $base_validation;
            }
        }

        // Store field configuration for validation
        $this->fields[$name] = [
            'name' => $name,
            'input_type' => $input_type,
            'label' => $label,
            'options' => $options,
            'validation' => $options['validation'] ?? [],
            'model_class' => $model_class  // Store for console output
        ];

        // Debug output
        if (!empty($this->options['debug'])) {
            error_log("[FormWriterV2 DEBUG] Registered field: $name | Type: $input_type | Model: " . ($model_class ? $model_class : 'NONE') . " | Validation: " . json_encode($this->fields[$name]['validation']));
        }
    }

    /**
     * Output the opening form tag and CSRF token
     */
    public function begin_form() {
        // Build CSS to ensure placeholders are visually distinct from actual input text
        $html = '<style>';
        $html .= '#' . htmlspecialchars($this->form_id) . ' input::placeholder,';
        $html .= '#' . htmlspecialchars($this->form_id) . ' textarea::placeholder {';
        $html .= '  color: #999 !important;';
        $html .= '  opacity: 0.8 !important;';
        $html .= '}';
        $html .= '</style>';

        // Build form tag with all attributes from options
        $html .= '<form';
        $html .= ' id="' . htmlspecialchars($this->form_id) . '"';
        $html .= ' method="' . htmlspecialchars($this->options['method']) . '"';
        $html .= ' action="' . htmlspecialchars($this->options['action']) . '"';

        // Additional attributes
        if (!empty($this->options['class'])) {
            $html .= ' class="' . htmlspecialchars($this->options['class']) . '"';
        }
        if (!empty($this->options['enctype'])) {
            $html .= ' enctype="' . htmlspecialchars($this->options['enctype']) . '"';
        }

        // Any data-* attributes
        foreach ($this->options as $key => $value) {
            if (strpos($key, 'data-') === 0) {
                $html .= ' ' . htmlspecialchars($key) . '="' . htmlspecialchars($value) . '"';
            }
        }

        $html .= '>';

        // Add CSRF token if enabled
        if ($this->csrf_token) {
            $html .= '<input type="hidden" name="' . htmlspecialchars($this->options['csrf_field']) . '" value="' . htmlspecialchars($this->csrf_token) . '">';
        }

        // Add edit_primary_key_value hidden field if provided
        if ($this->edit_primary_key_value !== null) {
            $html .= '<input type="hidden" name="edit_primary_key_value" value="' . htmlspecialchars($this->edit_primary_key_value) . '">';
        }

        // Either echo immediately or store for deferred output
        if ($this->use_deferred_output) {
            $this->deferred_output['_form_begin'] = $html;
        } else {
            echo $html;
        }
    }

    /**
     * Output the closing form tag
     */
    public function end_form() {
        $html = '';

        // Get JavaScript validation (this returns HTML string)
        $html .= $this->getJavascriptValidation();

        // Get any ready scripts
        $html .= $this->outputReadyScripts();

        $html .= '</form>';

        // Either echo immediately or store for deferred output
        if ($this->use_deferred_output) {
            $this->deferred_output['_form_end'] = $html;
        } else {
            echo $html;
        }
    }

    /**
     * Get all deferred field HTML as a string
     *
     * Used when deferred_output mode is enabled to retrieve collected field HTML
     * without echoing it immediately.
     *
     * @return string All collected field HTML including form tags
     */
    public function getFieldsHTML() {
        $html = '';

        // Output in correct order: form begin, fields, form end
        if (isset($this->deferred_output['_form_begin'])) {
            $html .= $this->deferred_output['_form_begin'];
        }

        // Output all fields (excluding special _form_begin and _form_end keys)
        foreach ($this->deferred_output as $key => $field_html) {
            if ($key !== '_form_begin' && $key !== '_form_end') {
                $html .= $field_html;
            }
        }

        // Output form end
        if (isset($this->deferred_output['_form_end'])) {
            $html .= $this->deferred_output['_form_end'];
        }

        return $html;
    }

    /**
     * Get JavaScript validation initialization as string
     */
    protected function getJavascriptValidation() {
        ob_start();
        $this->outputJavascriptValidation();
        return ob_get_clean();
    }

    /**
     * Output JavaScript validation initialization
     */
    protected function outputJavascriptValidation() {
        $validation_rules = $this->getAllValidationRules();

        if (empty($validation_rules)) {
            echo '<script type="text/javascript">';
            echo 'console.log("No validation rules found for form");';
            echo '</script>';
            return;  // No validation needed
        }

        echo '<script type="text/javascript">';
        echo 'console.log("=== FormWriterV2 DEBUG ===");';
        echo 'console.log("Form ID:", "' . htmlspecialchars($this->form_id) . '");';

        // Output model validation information
        if (!empty($this->model_validated_fields)) {
            echo 'console.log("🔍 Automatic Model Validation Detected:");';
            foreach ($this->model_validated_fields as $field_name => $info) {
                echo 'console.log("  ✓ ' . htmlspecialchars($field_name) . ' → Model: ' . htmlspecialchars($info['model']) . '", ' . json_encode($info['rules']) . ');';
            }
        }

        echo 'document.addEventListener("DOMContentLoaded", function() {';
        echo '    var form = document.getElementById("' . htmlspecialchars($this->form_id) . '");';
        echo '    if (form) {';

        // Build rules and messages in JoineryValidator format
        $js_rules = [];
        $js_messages = [];

        foreach ($validation_rules as $fieldName => $fieldRules) {
            $field_js_rules = [];
            $field_js_messages = [];

            // Convert to JoineryValidator format
            foreach ($fieldRules as $rule => $param) {
                if ($rule === 'messages') continue;  // Skip messages array

                switch ($rule) {
                    case 'required':
                        if ($param) {
                            $field_js_rules['required'] = true;
                            if (isset($fieldRules['messages']['required'])) {
                                $field_js_messages['required'] = $fieldRules['messages']['required'];
                            }
                        }
                        break;

                    case 'email':
                        if ($param) {
                            $field_js_rules['email'] = true;
                            if (isset($fieldRules['messages']['email'])) {
                                $field_js_messages['email'] = $fieldRules['messages']['email'];
                            }
                        }
                        break;

                    case 'minlength':
                        $field_js_rules['minlength'] = $param;
                        if (isset($fieldRules['messages']['minlength'])) {
                            $field_js_messages['minlength'] = $fieldRules['messages']['minlength'];
                        }
                        break;

                    case 'maxlength':
                        $field_js_rules['maxlength'] = $param;
                        if (isset($fieldRules['messages']['maxlength'])) {
                            $field_js_messages['maxlength'] = $fieldRules['messages']['maxlength'];
                        }
                        break;

                    case 'min':
                        $field_js_rules['min'] = $param;
                        if (isset($fieldRules['messages']['min'])) {
                            $field_js_messages['min'] = $fieldRules['messages']['min'];
                        }
                        break;

                    case 'max':
                        $field_js_rules['max'] = $param;
                        if (isset($fieldRules['messages']['max'])) {
                            $field_js_messages['max'] = $fieldRules['messages']['max'];
                        }
                        break;

                    case 'pattern':
                        // Convert PHP regex to JS regex (remove delimiters if present)
                        $pattern = $param;
                        if (preg_match('/^\/(.*)\/[imsxu]*$/', $pattern, $matches)) {
                            $pattern = $matches[1];
                        }
                        $field_js_rules['pattern'] = $pattern;
                        if (isset($fieldRules['messages']['pattern'])) {
                            $field_js_messages['pattern'] = $fieldRules['messages']['pattern'];
                        }
                        break;

                    case 'matches':
                        $field_js_rules['equalTo'] = $param;
                        if (isset($fieldRules['messages']['matches'])) {
                            $field_js_messages['equalTo'] = $fieldRules['messages']['matches'];
                        }
                        break;

                    case 'url':
                        if ($param) {
                            $field_js_rules['url'] = true;
                            if (isset($fieldRules['messages']['url'])) {
                                $field_js_messages['url'] = $fieldRules['messages']['url'];
                            }
                        }
                        break;

                    case 'number':
                        if ($param) {
                            $field_js_rules['number'] = true;
                            if (isset($fieldRules['messages']['number'])) {
                                $field_js_messages['number'] = $fieldRules['messages']['number'];
                            }
                        }
                        break;

                    case 'phone':
                        if ($param) {
                            $field_js_rules['phone'] = true;
                            if (isset($fieldRules['messages']['phone'])) {
                                $field_js_messages['phone'] = $fieldRules['messages']['phone'];
                            }
                        }
                        break;

                    case 'zip':
                        if ($param) {
                            $field_js_rules['zip'] = true;
                            if (isset($fieldRules['messages']['zip'])) {
                                $field_js_messages['zip'] = $fieldRules['messages']['zip'];
                            }
                        }
                        break;

                    case 'require_one_group':
                        // require_one_group validation - pass to JoineryValidator for client-side validation
                        // JoineryValidator expects the group name as the rule value, not an object
                        if (is_array($param) && isset($param['value'])) {
                            $field_js_rules['require_one_group'] = $param['value'];  // Just the group name string
                            if (isset($param['message'])) {
                                $field_js_messages['require_one_group'] = $param['message'];
                            }
                        }
                        break;
                }
            }

            if (!empty($field_js_rules)) {
                $js_rules[$fieldName] = $field_js_rules;
            }
            if (!empty($field_js_messages)) {
                $js_messages[$fieldName] = $field_js_messages;
            }
        }

        // Output JoineryValidator initialization
        if (!empty($js_rules)) {
            echo '        console.log("✓ Validation rules:", ' . json_encode($js_rules, JSON_UNESCAPED_SLASHES) . ');';
        }
        echo '        var validator = new JoineryValidator(form, {';
        echo '            rules: ' . json_encode($js_rules, JSON_UNESCAPED_SLASHES) . ',';
        echo '            messages: ' . json_encode($js_messages, JSON_UNESCAPED_SLASHES) . ',';
        echo '            debug: true';  // Always enable debug
        echo '        });';

        // Add AJAX submission handler if needed
        if (isset($this->options['data-ajax']) && $this->options['data-ajax'] === 'true') {
            echo $this->renderAjaxHandler();
        }

        echo '    }';
        echo '});';
        echo '</script>';
    }

    /**
     * Render inline AJAX handler
     *
     * @return string JavaScript code
     */
    protected function renderAjaxHandler() {
        return '
        form.addEventListener("submit", function(e) {
            e.preventDefault();

            var formData = new FormData(form);

            fetch(form.action, {
                method: form.method,
                body: formData,
                headers: {
                    "X-Requested-With": "XMLHttpRequest"
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    var callback = form.dataset.callback;
                    if (callback && window[callback]) {
                        window[callback](data);
                    } else {
                        alert(data.message || "Saved successfully");
                    }
                } else if (data.errors) {
                    Object.keys(data.errors).forEach(function(fieldName) {
                        var field = form.querySelector("[name=\'" + fieldName + "\']");
                        if (field) {
                            var errorDiv = field.parentElement.querySelector(".error-message");
                            if (!errorDiv) {
                                errorDiv = document.createElement("div");
                                errorDiv.className = "error-message text-danger";
                                field.parentElement.appendChild(errorDiv);
                            }
                            errorDiv.textContent = data.errors[fieldName].join(", ");
                        }
                    });
                } else if (data.error) {
                    alert(data.error);
                }
            })
            .catch(error => {
                console.error("Form submission error:", error);
                alert("An error occurred. Please try again.");
            });
        });';
    }

    // ===== SHARED JAVASCRIPT OUTPUT METHODS =====
    // These methods extract JavaScript that was previously duplicated across theme implementations

    /**
     * Output shared JavaScript for time input fields
     * Handles the updateTimeInput function and DOMContentLoaded event listener
     * Used by both FormWriterV2Bootstrap and FormWriterV2Tailwind
     */
    protected function outputTimeInputJavaScript() {
        static $time_input_js_loaded = false;
        if (!$time_input_js_loaded) {
            echo '<script type="text/javascript">
function updateTimeInput(hourId, minuteId, ampmId, hiddenId) {
    var hour = document.getElementById(hourId).value;
    var minute = document.getElementById(minuteId).value;
    var ampm = document.getElementById(ampmId).value;

    if (hour && minute) {
        var h = parseInt(hour);
        if (ampm === "PM" && h !== 12) h += 12;
        if (ampm === "AM" && h === 12) h = 0;

        var timeValue = String(h).padStart(2, "0") + ":" + String(minute).padStart(2, "0");
        document.getElementById(hiddenId).value = timeValue;
    }
}

document.addEventListener("DOMContentLoaded", function() {
    var timeInputs = document.querySelectorAll("[data-time-hour]");
    timeInputs.forEach(function(el) {
        var hourId = el.getAttribute("data-time-hour");
        var minuteId = el.getAttribute("data-time-minute");
        var ampmId = el.getAttribute("data-time-ampm");
        var hiddenId = el.getAttribute("data-time-hidden");

        document.getElementById(hourId).addEventListener("change", function() {
            updateTimeInput(hourId, minuteId, ampmId, hiddenId);
        });
        document.getElementById(minuteId).addEventListener("change", function() {
            updateTimeInput(hourId, minuteId, ampmId, hiddenId);
        });
        document.getElementById(ampmId).addEventListener("change", function() {
            updateTimeInput(hourId, minuteId, ampmId, hiddenId);
        });
    });
});
</script>';
            $time_input_js_loaded = true;
        }
    }

    /**
     * Output shared JavaScript for AJAX search select functionality
     * Contains AjaxSearchSelect class definition used by both theme implementations
     */
    protected function outputAjaxSearchSelectJavaScript() {
        static $ajax_search_select_js_loaded = false;
        if (!$ajax_search_select_js_loaded) {
            echo '<script type="text/javascript">
class AjaxSearchSelect {
    constructor(selectId, searchEndpoint, minChars = 2) {
        this.selectId = selectId;
        this.searchEndpoint = searchEndpoint;
        this.minChars = minChars;
        this.selectElement = document.getElementById(selectId);
        this.init();
    }

    init() {
        if (!this.selectElement) return;

        const self = this;
        const input = this.selectElement.querySelector(".search-input");
        const resultsDiv = this.selectElement.querySelector(".search-results");
        const selectedDiv = this.selectElement.querySelector(".selected-items");
        const hiddenInput = this.selectElement.querySelector(".search-hidden-value");

        if (!input) return;

        input.addEventListener("input", (e) => {
            const query = e.target.value.trim();

            if (query.length < this.minChars) {
                resultsDiv.innerHTML = "";
                return;
            }

            fetch(this.searchEndpoint + "?q=" + encodeURIComponent(query))
                .then(response => response.json())
                .then(data => {
                    resultsDiv.innerHTML = "";

                    if (!data.results || data.results.length === 0) {
                        resultsDiv.innerHTML = "<div class=\"no-results\">No results found</div>";
                        return;
                    }

                    data.results.forEach(item => {
                        const div = document.createElement("div");
                        div.className = "result-item";
                        div.textContent = item.label;
                        div.addEventListener("click", () => {
                            self.selectItem(item.value, item.label, input, selectedDiv, hiddenInput);
                        });
                        resultsDiv.appendChild(div);
                    });
                })
                .catch(error => console.error("Search error:", error));
        });
    }

    selectItem(value, label, input, selectedDiv, hiddenInput) {
        input.value = label;
        hiddenInput.value = value;

        const tag = document.createElement("span");
        tag.className = "selected-tag";
        tag.textContent = label + " ";

        const removeBtn = document.createElement("button");
        removeBtn.type = "button";
        removeBtn.className = "remove-tag";
        removeBtn.textContent = "×";
        removeBtn.addEventListener("click", (e) => {
            e.preventDefault();
            tag.remove();
            input.value = "";
            hiddenInput.value = "";
        });

        tag.appendChild(removeBtn);
        selectedDiv.appendChild(tag);
    }
}
</script>';
            $ajax_search_select_js_loaded = true;
        }
    }

    // ===== TIME HANDLING HELPER METHODS =====
    // These methods centralize time parsing and conversion logic previously duplicated in concrete classes

    /**
     * Parse time value from various formats into components
     * Handles both 24-hour database format and 12-hour display format with AM/PM
     * This consolidates the duplicated parsing logic from both concrete implementations
     * @param string $value Time in any supported format (HH:MM, HH:MM:SS, or H:MM AM/PM)
     * @return array ['hour' => string, 'minute' => string, 'ampm' => string]
     */
    protected function parseTimeValue($value) {
        $hour = '';
        $minute = '';
        $ampm = 'AM';

        if (!$value) {
            return ['hour' => $hour, 'minute' => $minute, 'ampm' => $ampm];
        }

        // Check if value contains AM/PM (e.g., "3:15 PM" from datetimeinput)
        if (stripos($value, 'am') !== false || stripos($value, 'pm') !== false) {
            // Extract AM/PM first
            if (stripos($value, 'pm') !== false) {
                $ampm = 'PM';
                $value = str_ireplace('pm', '', $value);
            } else {
                $ampm = 'AM';
                $value = str_ireplace('am', '', $value);
            }
            $value = trim($value);
        }

        // Parse hour and minute from remaining value
        if (strpos($value, ':') !== false) {
            list($h, $m) = explode(':', $value);
            $h = intval(trim($h));
            $m = intval(trim($m));

            // If we extracted AM/PM, the hour is already in 12-hour format
            if ($ampm === 'PM' && $h !== 12) {
                // Keep as is, conversion happens on submit
            } elseif ($ampm === 'AM' && $h === 12) {
                // Keep as 12
            } elseif ($h >= 12 && (stripos($value, 'am') === false && stripos($value, 'pm') === false)) {
                // If no AM/PM was in original value, convert from 24-hour to 12-hour
                if ($h >= 12) {
                    $ampm = 'PM';
                    if ($h > 12) $h -= 12;
                } else {
                    $ampm = 'AM';
                    if ($h == 0) $h = 12;
                }
            }

            $hour = str_pad($h, 2, '0', STR_PAD_LEFT);
            $minute = str_pad($m, 2, '0', STR_PAD_LEFT);
        }

        return ['hour' => $hour, 'minute' => $minute, 'ampm' => $ampm];
    }

    /**
     * Convert 12-hour time components to 24-hour database format
     * @param string $hour Hour (1-12)
     * @param string $minute Minute (00-59)
     * @param string $ampm AM or PM
     * @return string Time in HH:MM format suitable for database storage
     */
    protected function convertTimeToDatabase($hour, $minute, $ampm) {
        if (empty($hour) || empty($minute)) {
            return '';
        }

        $hour24 = (int)$hour;

        if ($ampm === 'PM' && $hour24 !== 12) {
            $hour24 += 12;
        } elseif ($ampm === 'AM' && $hour24 === 12) {
            $hour24 = 0;
        }

        return str_pad($hour24, 2, '0', STR_PAD_LEFT) . ':' .
               str_pad($minute, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Format time for display
     * @param string $value Time in database format (HH:MM)
     * @param string $format Output format ('12hour' or '24hour')
     * @return string Formatted time
     */
    protected function formatTimeForDisplay($value, $format = '12hour') {
        if (empty($value)) {
            return '';
        }

        if ($format === '24hour') {
            return $value;
        }

        $components = $this->parseTimeValue($value);
        return $components['hour'] . ':' . $components['minute'] . ' ' . $components['ampm'];
    }

    /**
     * Handle output of HTML for a form field
     * Supports both immediate output and deferred output mode
     * @param string $field_name The name of the field
     * @param string $html The HTML to output
     */
    protected function handleOutput($field_name, $html) {
        if ($this->use_deferred_output) {
            $this->deferred_output[$field_name] = $html;
        } else {
            echo $html;
        }
    }

    // Abstract methods for theme-specific HTML generation
    // Each theme implementation must provide these methods

    abstract protected function outputTextInput($name, $label, $options);
    abstract protected function outputPasswordInput($name, $label, $options);
    abstract protected function outputDropInput($name, $label, $options);
    abstract protected function outputCheckboxInput($name, $label, $options);
    abstract protected function outputRadioInput($name, $label, $options);
    abstract protected function outputCheckboxList($name, $label, $options);
    abstract protected function outputDateInput($name, $label, $options);
    abstract protected function outputTimeInput($name, $label, $options);
    abstract protected function outputDateTimeInput($name, $label, $options);
    abstract protected function outputFileInput($name, $label, $options);
    abstract protected function outputHiddenInput($name, $options);
    abstract protected function outputSubmitButton($name, $label, $options);
    abstract protected function outputTextbox($name, $label, $options);
    abstract protected function outputImageInput($name, $label, $options);
    abstract protected function outputTextarea($name, $label, $options);

    // ===== NEW FIELD VISIBILITY & CUSTOM SCRIPT METHODS =====

    /**
     * Array to store ready scripts for output at form end
     */
    protected $ready_scripts = array();

    /**
     * Add JavaScript to run when form loads
     * @param string $script - Raw JavaScript (will be wrapped in DOMContentLoaded)
     */
    public function addReadyScript($script) {
        if (!isset($this->ready_scripts)) {
            $this->ready_scripts = array();
        }
        $this->ready_scripts[] = $script;
    }

    /**
     * Output all accumulated ready scripts
     * @return string - JavaScript HTML to be included before form close
     */
    protected function outputReadyScripts() {
        if (empty($this->ready_scripts)) return '';

        $output = '<script>';
        $output .= 'document.addEventListener("DOMContentLoaded", function() {';
        foreach ($this->ready_scripts as $script) {
            $output .= $script;
        }
        $output .= '});';
        $output .= '</script>';
        return $output;
    }

    /**
     * Sanitize field name for use as JavaScript variable name
     * @param string $fieldName - Field name that may contain special characters
     * @return string - Valid JavaScript variable name
     */
    protected function sanitizeForJsVariable($fieldName) {
        // Replace non-alphanumeric characters with underscores
        // Ensure it starts with a letter or underscore
        $sanitized = preg_replace('/[^a-zA-Z0-9_]/', '_', $fieldName);
        if (preg_match('/^[0-9]/', $sanitized)) {
            $sanitized = '_' . $sanitized;
        }
        return $sanitized;
    }

    /**
     * Validate visibility rules for conflicts and errors
     * @param string $fieldId - Field ID being configured
     * @param array $rules - Visibility rules to validate
     */
    protected function validateVisibilityRules($fieldId, $rules) {
        foreach ($rules as $selectValue => $rule) {
            $show = isset($rule['show']) ? $rule['show'] : array();
            $hide = isset($rule['hide']) ? $rule['hide'] : array();

            // Check for conflicting fields
            $conflicts = array_intersect($show, $hide);
            if (!empty($conflicts)) {
                $conflictList = implode(', ', $conflicts);
                trigger_error(
                    "Visibility conflict in field '{$fieldId}' for value '{$selectValue}': " .
                    "Fields cannot be both shown and hidden: {$conflictList}",
                    E_USER_ERROR
                );
            }

            // Check for non-string values
            foreach (array_merge($show, $hide) as $fieldRef) {
                if (!is_string($fieldRef)) {
                    trigger_error(
                        "Invalid field reference in '{$fieldId}': field IDs must be strings, " .
                        "got " . gettype($fieldRef),
                        E_USER_ERROR
                    );
                }
            }
        }
    }

    /**
     * Generate visibility rules JavaScript
     * @param string $fieldName - Field identifier (used for variable naming)
     * @param string $fieldId - HTML id of the select element
     * @param array $rules - Visibility rules
     * @return string - JavaScript code
     */
    protected function generateVisibilityScript($fieldName, $fieldId, $rules) {
        // Validate rules first
        $this->validateVisibilityRules($fieldId, $rules);

        // Sanitize field name for JavaScript variable
        $varName = $this->sanitizeForJsVariable($fieldName);

        // Convert PHP array to JavaScript object
        $jsRules = json_encode($rules);

        // Add CSS for fade transitions (only once per page)
        static $cssAdded = false;
        $cssOutput = '';
        if (!$cssAdded) {
            $cssOutput = '<style>' . "\n";
            $cssOutput .= '/* FormWriter visibility transitions */' . "\n";
            $cssOutput .= '.fw-field-hidden {' . "\n";
            $cssOutput .= '  opacity: 0 !important;' . "\n";
            $cssOutput .= '  transition: opacity 0.3s ease-out;' . "\n";
            $cssOutput .= '  pointer-events: none;' . "\n";
            $cssOutput .= '}' . "\n";
            $cssOutput .= '.fw-field-visible {' . "\n";
            $cssOutput .= '  opacity: 1;' . "\n";
            $cssOutput .= '  transition: opacity 0.3s ease-in;' . "\n";
            $cssOutput .= '}' . "\n";
            $cssOutput .= '</style>' . "\n";
            $cssAdded = true;
        }

        $output = $cssOutput . '<script>' . "\n";
        $output .= '(function() {' . "\n";
        $output .= '  const visibilityRules' . $varName . ' = ' . $jsRules . ';' . "\n";
        $output .= '  ' . "\n";
        $output .= '  function update' . $varName . 'Visibility() {' . "\n";
        $output .= '    const selected = document.getElementById("' . $fieldId . '").value;' . "\n";
        $output .= '    const rules = visibilityRules' . $varName . '[selected] || {};' . "\n";
        $output .= '    ' . "\n";
        $output .= '    (rules.show || []).forEach(function(id) {' . "\n";
        $output .= '      // Try to find element with _container suffix first, then fall back to plain ID' . "\n";
        $output .= '      const el = document.getElementById(id + "_container") || document.getElementById(id);' . "\n";
        $output .= '      if (el) {' . "\n";
        $output .= '        // Show with fade in effect' . "\n";
        $output .= '        el.style.display = "";' . "\n";
        $output .= '        el.classList.remove("fw-field-hidden");' . "\n";
        $output .= '        setTimeout(function() {' . "\n";
        $output .= '          el.classList.add("fw-field-visible");' . "\n";
        $output .= '        }, 10);' . "\n";
        $output .= '      }' . "\n";
        $output .= '    });' . "\n";
        $output .= '    ' . "\n";
        $output .= '    (rules.hide || []).forEach(function(id) {' . "\n";
        $output .= '      // Try to find element with _container suffix first, then fall back to plain ID' . "\n";
        $output .= '      const el = document.getElementById(id + "_container") || document.getElementById(id);' . "\n";
        $output .= '      if (el) {' . "\n";
        $output .= '        // Hide with fade out effect' . "\n";
        $output .= '        el.classList.remove("fw-field-visible");' . "\n";
        $output .= '        el.classList.add("fw-field-hidden");' . "\n";
        $output .= '        setTimeout(function() {' . "\n";
        $output .= '          el.style.display = "none";' . "\n";
        $output .= '        }, 300);' . "\n";
        $output .= '      }' . "\n";
        $output .= '    });' . "\n";
        $output .= '  }' . "\n";
        $output .= '  ' . "\n";
        $output .= '  document.addEventListener("DOMContentLoaded", function() {' . "\n";
        $output .= '    const selectEl = document.getElementById("' . $fieldId . '");' . "\n";
        $output .= '    if (!selectEl) return;' . "\n";
        $output .= '    update' . $varName . 'Visibility();' . "\n";
        $output .= '    selectEl.addEventListener("change", update' . $varName . 'Visibility);' . "\n";
        $output .= '  });' . "\n";
        $output .= '})();' . "\n";
        $output .= '</script>';

        return $output;
    }

    /**
     * Generate field-level event handler wrapper
     * @param string $fieldId - HTML id of the element
     * @param string $scriptBody - JavaScript to execute in handler
     * @return string - JavaScript code with addEventListener wrapper
     */
    protected function generateFieldScript($fieldId, $scriptBody) {
        $output = '<script>' . "\n";
        $output .= 'document.addEventListener("DOMContentLoaded", function() {' . "\n";
        $output .= '  const selectEl = document.getElementById("' . $fieldId . '");' . "\n";
        $output .= '  if (!selectEl) return;' . "\n";
        $output .= '  ' . "\n";
        $output .= '  selectEl.addEventListener("change", function() {' . "\n";
        $output .= $scriptBody;
        $output .= '  });' . "\n";
        $output .= '});' . "\n";
        $output .= '</script>';

        return $output;
    }

    /**
     * Process a datetime input from POST data
     *
     * @param array $post_vars The POST data array
     * @param string $field_name The base field name (e.g., 'ccd_start_time')
     * @param bool $to_utc Whether to convert to UTC (default true)
     * @return string|null The processed datetime string, or NULL if fields not present
     */
    public static function process_datetimeinput($post_vars, $field_name, $to_utc = true) {
        require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

        $date_field = $field_name . '_dateinput';
        $hour_field = $field_name . '_timeinput_hour';
        $minute_field = $field_name . '_timeinput_minute';
        $ampm_field = $field_name . '_timeinput_ampm';

        // Check if the required fields are present
        if(empty($post_vars[$date_field]) || !isset($post_vars[$hour_field])){
            return NULL;
        }

        // Combine hour, minute, ampm into time string
        $hour = intval($post_vars[$hour_field]);
        $minute = str_pad($post_vars[$minute_field], 2, '0', STR_PAD_LEFT);
        $ampm = $post_vars[$ampm_field];
        $time_string = $hour . ':' . $minute . $ampm;

        // Combine date and time
        $time_combined = $post_vars[$date_field] . ' ' . LibraryFunctions::toDBTime($time_string);

        // Convert to UTC if requested
        if($to_utc){
            $session = SessionControl::get_instance();
            return LibraryFunctions::convert_time($time_combined, $session->get_timezone(), 'UTC', 'c');
        }

        return $time_combined;
    }

    /**
     * Helper method to create styled upload buttons
     *
     * @param string $context Button context (browse, upload, clear)
     * @param string $id Element ID
     * @param string $label Button label
     * @param bool $disabled Whether button is disabled
     * @return string HTML button element
     */
    protected function multi_upload_button($context, $id, $label, $disabled = false) {
        $disabled_attr = $disabled ? ' disabled' : '';
        $style_class = '';

        switch($context) {
            case 'browse':
                $style_class = 'button primary';
                break;
            case 'upload':
                $style_class = 'button primary';
                break;
            case 'clear':
                $style_class = 'button secondary';
                break;
            default:
                $style_class = 'button';
        }

        return '<button type="button" id="' . $id . '" class="' . $style_class . '"' . $disabled_attr . '>' . $label . '</button>';
    }

    /**
     * Bulk file upload interface with drag-and-drop support
     * Renders a complete file upload UI with progress tracking
     *
     * @param array $getvars Additional form variables to pass with upload
     * @param bool $delete Whether to show delete option
     * @param bool $checkall Whether to show check all option
     * @return string HTML/JavaScript output for file upload interface
     */
    public function file_upload_full($getvars=NULL, $delete=FALSE, $checkall=FALSE){
        ob_start();
        $getargs = '';
        if($getvars){
            foreach($getvars as $getvar=>$getval){
                $getargs.= '<input type="hidden" name="'.$getvar.'" value="'.$getval.'"/>';
            }
        }

        $settings = Globalvars::get_instance();
        $allowed_extensions = $settings->get_setting('allowed_upload_extensions');
        $accept_attr = '.' . str_replace(',', ',.', $allowed_extensions);

        // Get actual PHP upload limits
        $upload_max = ini_get('upload_max_filesize');
        $post_max = ini_get('post_max_size');
        // Convert to bytes to compare
        function parseSize($size) {
            $unit = preg_replace('/[^bkmgtpezy]/i', '', $size);
            $size = preg_replace('/[^0-9\.]/', '', $size);
            if ($unit) {
                return round($size * pow(1024, stripos('bkmgtpezy', $unit[0])));
            } else {
                return round($size);
            }
        }
        $upload_max_bytes = parseSize($upload_max);
        $post_max_bytes = parseSize($post_max);
        $max_size = min($upload_max_bytes, $post_max_bytes);
        $max_size_display = round($max_size / (1024 * 1024)) . 'MB';
    ?>
        <!-- File Drop Zone -->
        <div id="file-drop-zone" class="file-drop-zone" style="border: 2px dashed #ccc; border-radius: 5px; padding: 20px; text-align: center; margin-bottom: 20px; background-color: #f9f9f9; transition: all 0.3s ease; cursor: pointer;">
            <div style="font-size: 48px; color: #999; margin-bottom: 10px;">☁️</div>
            <h3 style="color: #666; margin: 10px 0;">Drop files here or click to browse</h3>
            <p style="color: #999; margin: 10px 0;">Maximum file size: <?php echo $max_size_display; ?> | Allowed types: <?php echo strtoupper(str_replace(',', ', ', $allowed_extensions)); ?></p>
            <input type="file" id="file-input" multiple accept="<?php echo $accept_attr; ?>" style="display: none;">
            <div style="margin-top: 10px;">
                <?php echo $this->multi_upload_button('browse', 'browse-btn', '📁 Browse Files'); ?>
            </div>
        </div>

        <!-- Upload Controls -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <div>
                <?php echo $this->multi_upload_button('upload', 'upload-all-btn', '⬆️ Upload All', true); ?>
                <span style="margin-left: 10px;"><?php echo $this->multi_upload_button('clear', 'clear-all-btn', '🗑️ Clear All', true); ?></span>
            </div>
            <div id="overall-progress" style="display: none; flex-grow: 1; margin-left: 20px;">
                <progress id="overall-progress-bar" value="0" max="100" style="width: 100%; height: 20px;">0%</progress>
            </div>
        </div>

        <!-- Files Table -->
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background-color: #f5f5f5;">
                        <th style="padding: 10px; text-align: left; border-bottom: 2px solid #ddd;">📄 File Name</th>
                        <th style="padding: 10px; text-align: left; border-bottom: 2px solid #ddd;">📊 Size</th>
                        <th style="padding: 10px; text-align: left; border-bottom: 2px solid #ddd;">ℹ️ Status</th>
                        <th style="padding: 10px; text-align: left; border-bottom: 2px solid #ddd;">⚙️ Actions</th>
                    </tr>
                </thead>
                <tbody id="files-list">
                    <tr id="no-files-message">
                        <td colspan="4" style="text-align: center; padding: 40px; color: #999;">
                            <div style="font-size: 32px; margin-bottom: 10px;">📤</div>
                            <div>No files selected</div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <?php if($getargs): ?>
        <form id="hidden-form-data" style="display: none;">
            <?php echo $getargs; ?>
        </form>
        <?php endif; ?>
        <script>
        $(function() {
            'use strict';

            let selectedFiles = [];

            // Get allowed file extensions from server setting
            const allowedExtensions = '<?php echo $allowed_extensions; ?>';
            const allowedTypes = new RegExp('\\.(' + allowedExtensions.replace(/,/g, '|') + ')$', 'i');
            const maxFileSize = <?php echo $max_size; ?>; // Maximum file size in bytes

            // DOM elements
            const $dropZone = $('#file-drop-zone');
            const $fileInput = $('#file-input');
            const $browseBtn = $('#browse-btn');
            const $uploadAllBtn = $('#upload-all-btn');
            const $clearAllBtn = $('#clear-all-btn');
            const $filesList = $('#files-list');
            const $noFilesMessage = $('#no-files-message');
            const $overallProgress = $('#overall-progress');
            const $progressBar = $('#overall-progress-bar');

            // File size formatter
            function formatFileSize(bytes) {
                if (bytes === 0) return '0 Bytes';
                const k = 1024;
                const sizes = ['Bytes', 'KB', 'MB', 'GB'];
                const i = Math.floor(Math.log(bytes) / Math.log(k));
                return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
            }

            // Generate unique ID for each file
            function generateFileId() {
                return 'file_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
            }

            // Get file icon based on extension
            function getFileIcon(filename) {
                const ext = filename.split('.').pop().toLowerCase();
                const iconMap = {
                    'pdf': '📕',
                    'doc': '📘',
                    'docx': '📘',
                    'xls': '📗',
                    'xlsx': '📗',
                    'jpg': '🖼️',
                    'jpeg': '🖼️',
                    'png': '🖼️',
                    'gif': '🖼️',
                    'mp3': '🎵',
                    'mp4': '🎬',
                    'm4a': '🎵'
                };
                return iconMap[ext] || '📄';
            }

            // Add files to the list
            function addFiles(files) {
                Array.from(files).forEach(file => {
                    // Validate file type using server setting
                    if (!allowedTypes.test(file.name)) {
                        showToast('Invalid file type: ' + file.name + '. Allowed: ' + allowedExtensions, 'error');
                        return;
                    }

                    // Validate file size using server limit
                    if (file.size > maxFileSize) {
                        showToast('File too large: ' + file.name + '. Maximum size: <?php echo $max_size_display; ?>', 'error');
                        return;
                    }

                    const fileId = generateFileId();
                    const fileObj = {
                        id: fileId,
                        file: file,
                        status: 'pending'
                    };

                    selectedFiles.push(fileObj);
                    renderFileRow(fileObj);
                });

                updateUI();
            }

            // Render a file row in the table
            function renderFileRow(fileObj) {
                $noFilesMessage.hide();

                const fileIcon = getFileIcon(fileObj.file.name);
                const $row = $(`
                    <tr data-file-id="${fileObj.id}" class="file-row" style="border-bottom: 1px solid #eee;">
                        <td style="padding: 10px;">
                            <div style="display: flex; align-items: center;">
                                <span style="margin-right: 8px; font-size: 20px;">${fileIcon}</span>
                                <span class="file-name">${fileObj.file.name}</span>
                            </div>
                        </td>
                        <td class="file-size" style="padding: 10px;">${formatFileSize(fileObj.file.size)}</td>
                        <td class="file-status" style="padding: 10px;">
                            <span style="padding: 2px 8px; background: #6c757d; color: white; border-radius: 3px; font-size: 12px;">Ready to upload</span>
                        </td>
                        <td class="file-actions" style="padding: 10px;">
                            <button type="button" class="button small upload-single-btn" title="Upload this file" style="padding: 4px 8px; font-size: 12px;">
                                ⬆️
                            </button>
                            <button type="button" class="button small danger remove-file-btn" title="Remove this file" style="padding: 4px 8px; font-size: 12px; margin-left: 5px;">
                                ❌
                            </button>
                        </td>
                    </tr>
                `);

                $filesList.append($row);
            }

            // Update UI state
            function updateUI() {
                const hasFiles = selectedFiles.length > 0;
                const pendingFiles = selectedFiles.filter(f => f.status === 'pending').length;

                $uploadAllBtn.prop('disabled', pendingFiles === 0);
                $clearAllBtn.prop('disabled', !hasFiles);

                if (!hasFiles) {
                    $noFilesMessage.show();
                }

                // Update button text with count
                if (pendingFiles > 0) {
                    $uploadAllBtn.html(`⬆️ Upload All (${pendingFiles})`);
                } else {
                    $uploadAllBtn.html('⬆️ Upload All');
                }
            }

            // Show toast notification
            function showToast(message, type = 'info') {
                console.log(type + ': ' + message);
                // Simple alert for now - can be enhanced with proper toast notifications
                if (type === 'error') {
                    alert('Error: ' + message);
                }
            }

            // Upload a single file
            function uploadFile(fileObj) {
                return new Promise((resolve, reject) => {
                    const formData = new FormData();
                    formData.append('files[]', fileObj.file);

                    // Add any additional form data
                    $('#hidden-form-data input').each(function() {
                        formData.append($(this).attr('name'), $(this).val());
                    });

                    const $row = $(`.file-row[data-file-id="${fileObj.id}"]`);
                    const $status = $row.find('.file-status');
                    const $actions = $row.find('.file-actions');

                    // Update UI to uploading state
                    $status.html('<span style="padding: 2px 8px; background: #007bff; color: white; border-radius: 3px; font-size: 12px;">Uploading...</span>');
                    $actions.html(`
                        <div style="display: flex; align-items: center;">
                            <progress value="0" max="100" style="width: 60px; height: 20px; margin-right: 8px;">0%</progress>
                            <span style="color: #666; font-size: 12px;">0%</span>
                        </div>
                    `);

                    // Create XMLHttpRequest for progress tracking
                    const xhr = new XMLHttpRequest();

                    xhr.upload.addEventListener('progress', function(e) {
                        if (e.lengthComputable) {
                            const progress = Math.round((e.loaded / e.total) * 100);
                            $actions.find('progress').val(progress).text(progress + '%');
                            $actions.find('span').text(progress + '%');
                            $status.html(`<span style="padding: 2px 8px; background: #007bff; color: white; border-radius: 3px; font-size: 12px;">Uploading ${progress}%</span>`);
                        }
                    });

                    xhr.addEventListener('load', function() {
                        if (xhr.status === 200) {
                            try {
                                const response = JSON.parse(xhr.responseText);
                                if (response.files && response.files[0]) {
                                    const file = response.files[0];
                                    console.log('Upload response file object:', file); // Debug log
                                    if (file.url) {
                                        // Success
                                        $status.html('<span style="padding: 2px 8px; background: #28a745; color: white; border-radius: 3px; font-size: 12px;">✓ Upload successful</span>');
                                        $actions.html(`
                                            <a href="${file.url}" target="_blank" class="button small success" title="Download file" style="padding: 4px 8px; font-size: 12px; text-decoration: none;">
                                                ⬇️
                                            </a>
                                            <button type="button" class="button small danger remove-file-btn" title="Remove from list" style="padding: 4px 8px; font-size: 12px; margin-left: 5px;">
                                                ❌
                                            </button>
                                        `);

                                        // Make filename clickable if we have a file ID
                                        console.log('Checking for file_id:', file.file_id); // Debug log
                                        if (file.file_id) {
                                            const $nameElement = $row.find('.file-name');
                                            const fileName = $nameElement.text();
                                            const fileIcon = getFileIcon(fileName);

                                            console.log('Making filename clickable:', fileName, 'with ID:', file.file_id); // Debug log

                                            $nameElement.parent().html(`
                                                <div style="display: flex; align-items: center;">
                                                    <span style="margin-right: 8px; font-size: 20px;">${fileIcon}</span>
                                                    <a href="/admin/admin_file?fil_file_id=${file.file_id}" style="color: #0066cc; text-decoration: none;">${fileName}</a>
                                                </div>
                                            `);
                                        } else {
                                            console.log('No file_id found in response'); // Debug log
                                        }

                                        fileObj.status = 'completed';
                                        fileObj.url = file.url;
                                        fileObj.file_id = file.file_id;
                                        resolve(fileObj);
                                    } else if (file.error) {
                                        throw new Error(file.error);
                                    }
                                } else {
                                    throw new Error('Invalid response format');
                                }
                            } catch (e) {
                                reject(e);
                            }
                        } else {
                            reject(new Error('Upload failed with status: ' + xhr.status));
                        }
                    });

                    xhr.addEventListener('error', function() {
                        reject(new Error('Network error during upload'));
                    });

                    xhr.open('POST', '/admin/admin_file_upload_process');
                    xhr.send(formData);
                }).catch(error => {
                    // Handle error
                    const $row = $(`.file-row[data-file-id="${fileObj.id}"]`);
                    const $status = $row.find('.file-status');
                    const $actions = $row.find('.file-actions');

                    $status.html(`<span style="padding: 2px 8px; background: #dc3545; color: white; border-radius: 3px; font-size: 12px;">⚠️ Error</span>`);
                    $actions.html(`
                        <button type="button" class="button small upload-single-btn" title="Retry upload" style="padding: 4px 8px; font-size: 12px;">
                            🔄
                        </button>
                        <button type="button" class="button small danger remove-file-btn" title="Remove this file" style="padding: 4px 8px; font-size: 12px; margin-left: 5px;">
                            ❌
                        </button>
                    `);
                    fileObj.status = 'error';
                    showToast(`Upload failed: ${fileObj.file.name} - ${error.message}`, 'error');
                    throw error;
                });
            }

            // Event Handlers
            $browseBtn.on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $fileInput[0].click(); // Use native click instead of jQuery
            });

            $fileInput.on('change', function() {
                if (this.files.length > 0) {
                    addFiles(this.files);
                    this.value = ''; // Reset input
                }
            });

            // Drag and drop styling
            $dropZone.on('dragover dragenter', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).css({'border-color': '#007bff', 'background-color': '#e7f3ff'});
            });

            $dropZone.on('dragleave', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).css({'border-color': '#ccc', 'background-color': '#f9f9f9'});
            });

            $dropZone.on('drop', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).css({'border-color': '#ccc', 'background-color': '#f9f9f9'});

                const files = e.originalEvent.dataTransfer.files;
                if (files.length > 0) {
                    addFiles(files);
                }
            });

            // Hover effect
            $dropZone.on('mouseenter', function() {
                $(this).css('background-color', '#e7f3ff');
            }).on('mouseleave', function() {
                $(this).css('background-color', '#f9f9f9');
            });

            // Upload all files
            $uploadAllBtn.on('click', async function() {
                const pendingFiles = selectedFiles.filter(f => f.status === 'pending');
                if (pendingFiles.length === 0) return;

                $overallProgress.show();
                $uploadAllBtn.prop('disabled', true);

                let completed = 0;
                const total = pendingFiles.length;

                for (const fileObj of pendingFiles) {
                    try {
                        await uploadFile(fileObj);
                        completed++;
                        const progress = Math.round((completed / total) * 100);
                        $progressBar.val(progress);
                    } catch (error) {
                        console.error('Upload failed:', error);
                        completed++; // Count errors as completed for progress
                        const progress = Math.round((completed / total) * 100);
                        $progressBar.val(progress);
                    }
                }

                setTimeout(() => {
                    $overallProgress.hide();
                    $progressBar.val(0);
                    updateUI();
                }, 1000);
            });

            // Clear all files
            $clearAllBtn.on('click', function() {
                if (confirm('Are you sure you want to clear all files?')) {
                    selectedFiles = [];
                    $filesList.find('.file-row').remove();
                    updateUI();
                }
            });

            // Event delegation for dynamic buttons
            $filesList.on('click', '.upload-single-btn', function() {
                const fileId = $(this).closest('.file-row').data('file-id');
                const fileObj = selectedFiles.find(f => f.id === fileId);
                if (fileObj && (fileObj.status === 'pending' || fileObj.status === 'error')) {
                    uploadFile(fileObj).then(() => {
                        updateUI();
                    }).catch(() => {
                        updateUI();
                    });
                }
            });

            $filesList.on('click', '.remove-file-btn', function() {
                const $row = $(this).closest('.file-row');
                const fileId = $row.data('file-id');

                // Remove from array
                selectedFiles = selectedFiles.filter(f => f.id !== fileId);

                // Remove from DOM with animation
                $row.fadeOut(300, function() {
                    $(this).remove();
                    updateUI();
                });
            });

            // Click to browse anywhere in drop zone (except on buttons)
            $dropZone.on('click', function(e) {
                // Only trigger if clicking directly on the drop zone, not on child elements
                if (e.target === this) {
                    e.preventDefault();
                    e.stopPropagation();
                    $fileInput[0].click(); // Use native click
                }
            });
        });
        </script>

        <style>
        #file-drop-zone:hover {
            background-color: #e7f3ff !important;
            border-color: #007bff !important;
        }

        .file-row {
            transition: background-color 0.2s ease;
        }

        .file-row:hover {
            background-color: #f8f9fa;
        }

        .button {
            cursor: pointer;
            border: 1px solid #ccc;
            background: #f5f5f5;
            padding: 8px 16px;
            border-radius: 4px;
            font-size: 14px;
        }

        .button.primary {
            background: #007bff;
            color: white;
            border-color: #0056b3;
        }

        .button.secondary {
            background: #6c757d;
            color: white;
            border-color: #545b62;
        }

        .button.success {
            background: #28a745;
            color: white;
            border-color: #1e7e34;
        }

        .button.danger {
            background: #dc3545;
            color: white;
            border-color: #bd2130;
        }

        .button.small {
            padding: 4px 8px;
            font-size: 12px;
        }

        .button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        progress {
            border-radius: 4px;
        }
        </style>

    <!-- Modern browsers handle CORS natively, no IE8/9 support needed -->
      <?php
        return ob_get_clean();
    }

    /**
     * Validate option array format - detects reversed [label => id] arrays
     * Only runs when debug mode is enabled in settings
     *
     * @param array $options The options array to validate
     * @param string $context Context info (field name/type) for error message
     */
    protected function validateOptionFormat($options, $context = '') {
        // Only run in debug mode
        $settings = Globalvars::get_instance();
        if (!$settings->get_setting('debug')) {
            return;
        }

        if (!is_array($options)) return;

        // Whitelist: Known valid patterns
        static $whitelist = ['new' => true];

        foreach ($options as $key => $value) {
            // Skip whitelisted keys
            if (isset($whitelist[$key])) continue;

            // Skip if key is not string (already correct format: numeric => string)
            if (!is_string($key)) continue;

            $confidence = 0;

            // Fast checks first (most reliable indicators)

            // Check 1: String key with numeric value (HIGH confidence)
            // Pattern: "Active" => 1, "Yes" => 0
            if (is_numeric($value)) {
                $confidence += 50;
            }

            // Check 2: Key contains spaces (MEDIUM confidence)
            // Pattern: "United States" => 'us', "Test Option 1" => '1'
            if ($confidence < 50 && strpos($key, ' ') !== false) {
                $confidence += 40;
            }

            // Check 3: Key contains special patterns (MEDIUM confidence)
            // Patterns: "(123) Name", "+1 United States", "Name - Description"
            if ($confidence < 50) {
                if (strpos($key, '(') !== false ||
                    strpos($key, '+') === 0 ||
                    strpos($key, ' - ') !== false) {
                    $confidence += 35;
                }
            }

            // Check 4: Key much longer than value (LOW confidence, helper)
            // Pattern: "Windows Computer" => "desktop-windows"
            if ($confidence < 50 && is_string($value)) {
                if (strlen($key) > strlen($value) * 2) {
                    $confidence += 25;
                }
            }

            // Report if confidence threshold met
            if ($confidence >= 50) {
                // Get caller info for debugging
                $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
                $caller = $backtrace[2] ?? $backtrace[1] ?? [];

                error_log(sprintf(
                    "[REVERSED_ARRAY] Confidence: %d%% | '%s' => '%s' | File: %s:%d | Field: %s",
                    $confidence,
                    substr($key, 0, 40),
                    is_scalar($value) ? substr($value, 0, 40) : gettype($value),
                    basename($caller['file'] ?? 'unknown'),
                    $caller['line'] ?? 0,
                    $context
                ));
            }
        }
    }

    /**
     * Generate anti-spam question input field
     * @param string $type Optional type (e.g., 'blog')
     * @return string HTML for anti-spam field or false if not configured
     */
    public function antispam_question_input($type=NULL){
        $settings = Globalvars::get_instance();
        if($type == 'blog'){
            $correct_answer = $settings->get_setting('anti_spam_answer_comments');
        }
        else{
            $correct_answer = $settings->get_setting('anti_spam_answer');
        }

        if($correct_answer){
            $output = $this->textbox("antispam_question", "Type '".strtolower($correct_answer)."' into this field (to prove you are human)");
            $output .= $this->hidden("antispam_question_answer", strtolower($correct_answer));
            return $output;
        }
        else{
            return false;
        }
    }

    /**
     * Add anti-spam validation rules
     * @param array $validation_rules Existing validation rules
     * @param string $type Optional type (e.g., 'blog')
     * @return array Updated validation rules
     */
    public function antispam_question_validate($validation_rules, $type=NULL){
        $settings = Globalvars::get_instance();
        if($type == 'blog'){
            $correct_answer = $settings->get_setting('anti_spam_answer_comments');
        }
        else{
            $correct_answer = $settings->get_setting('anti_spam_answer');
        }

        if($correct_answer){
            $validation_rules['antispam_question']['required']['value'] = 'true';
            $validation_rules['antispam_question']['equalTo']['value'] = "'#antispam_question_answer'";
            $validation_rules['antispam_question']['equalTo']['message'] = "'You must type the correct word here'";
        }
        return $validation_rules;
    }

    /**
     * Check if anti-spam answer is correct
     * @param array $postvars POST variables
     * @param string $type Optional type (e.g., 'blog')
     * @return boolean True if answer is correct or not configured
     */
    public static function antispam_question_check($postvars, $type=NULL){
        $settings = Globalvars::get_instance();
        if($type == 'blog'){
            $correct_answer = $settings->get_setting('anti_spam_answer_comments');
        }
        else{
            $correct_answer = $settings->get_setting('anti_spam_answer');
        }

        if($correct_answer){
            if(strtolower($postvars['antispam_question']) == strtolower($correct_answer)){
                return true;
            }
            else{
                return false;
            }
        }
        else{
            return true;
        }
    }

    /**
     * Render a repeater field for the Page Component System.
     *
     * A repeater allows users to add/remove multiple grouped entries (e.g., a list of
     * features, team members, or slides). Each row contains the same set of sub-fields
     * defined in the schema.
     *
     * The output includes:
     * - A container with existing rows
     * - An "Add" button for adding new rows via JavaScript
     * - A hidden <template> element that JavaScript clones when adding rows
     *
     * Field names use array syntax (e.g., features[0][title]) which PHP automatically
     * parses into nested arrays on form submission.
     *
     * @param string $name    The field name (becomes array key in pac_config)
     * @param string $label   Display label shown above the repeater
     * @param array  $options {
     *     @type array  $value     Existing data array (e.g., [['title'=>'...'], ['title'=>'...']])
     *     @type array  $fields    Sub-field definitions from com_config_schema
     *     @type string $add_label Button text (default: '+ Add Item')
     * }
     * @return void
     *
     * @see Page Component System spec: /specs/page_component_system.md
     */
    public function repeater($name, $label = '', $options = []) {
        $items = $options['value'] ?? [];
        $subfields = $options['fields'] ?? [];
        $add_label = $options['add_label'] ?? '+ Add Item';

        // Ensure items is an array
        if (!is_array($items)) {
            $items = [];
        }

        // Repeater container - data-name used by JavaScript for targeting
        echo '<div class="repeater mb-4" data-name="' . htmlspecialchars($name) . '">';
        echo '<label class="form-label fw-bold">' . htmlspecialchars($label) . '</label>';

        // Help text if provided
        if (!empty($options['help'])) {
            echo '<div class="form-text text-muted mb-2">' . htmlspecialchars($options['help']) . '</div>';
        }

        echo '<div class="repeater-items">';

        // Render existing rows from saved data
        foreach ($items as $index => $item) {
            $this->repeater_row($name, $index, $subfields, $item);
        }

        echo '</div>';

        // Add button - JavaScript attaches click handler
        echo '<button type="button" class="repeater-add btn btn-secondary btn-sm mt-2">';
        echo htmlspecialchars($add_label);
        echo '</button>';

        // Hidden template for JavaScript cloning - __INDEX__ replaced with actual index
        echo '<template class="repeater-template">';
        $this->repeater_row($name, '__INDEX__', $subfields, []);
        echo '</template>';

        echo '</div>';
    }

    /**
     * Render a single row within a repeater field.
     *
     * Each row contains all sub-fields defined in the schema plus a remove button.
     * Called by repeater() for each existing item and once for the JS template.
     *
     * @param string     $name      Parent repeater field name
     * @param int|string $index     Row index (integer for real rows, '__INDEX__' for template)
     * @param array      $subfields Sub-field definitions from schema (type uses FormWriter method names)
     * @param array      $values    Current values for this row (empty for template)
     * @return void
     *
     * @see Page Component System spec: /specs/page_component_system.md
     */
    protected function repeater_row($name, $index, $subfields, $values) {
        echo '<div class="repeater-row card card-body mb-2" data-index="' . htmlspecialchars($index) . '">';
        echo '<div class="row align-items-end">';

        // Render each sub-field - type is the FormWriter method name directly
        foreach ($subfields as $subfield) {
            $field_name = $name . '[' . $index . '][' . $subfield['name'] . ']';
            $field_value = $values[$subfield['name']] ?? '';
            $method = $subfield['type'] ?? 'textinput';

            // Calculate column width based on number of fields
            $col_class = 'col-md';
            if (count($subfields) <= 2) {
                $col_class = 'col-md-5';
            } elseif (count($subfields) == 3) {
                $col_class = 'col-md-3';
            } elseif (count($subfields) >= 4) {
                $col_class = 'col-md';
            }

            echo '<div class="' . $col_class . '">';

            // Build options for the sub-field
            $field_options = [
                'value' => $field_value,
                'model' => false,  // Disable auto-detection for repeater fields
                'validation' => false  // Disable validation for individual repeater items
            ];

            // Merge in any options from the schema
            if (isset($subfield['options'])) {
                $field_options = array_merge($field_options, $subfield['options']);
            }

            // Handle dropinput options
            if ($method === 'dropinput' && isset($subfield['options'])) {
                $field_options['options'] = $subfield['options'];
            }

            // Call the appropriate FormWriter method
            if (method_exists($this, $method)) {
                $this->$method($field_name, $subfield['label'] ?? '', $field_options);
            } else {
                // Fallback to textinput if method doesn't exist
                $this->textinput($field_name, $subfield['label'] ?? '', $field_options);
            }

            echo '</div>';
        }

        // Remove button - JavaScript attaches click handler via event delegation
        echo '<div class="col-auto">';
        echo '<button type="button" class="repeater-remove btn btn-outline-danger btn-sm mb-3">Remove</button>';
        echo '</div>';

        echo '</div></div>';
    }

    /**
     * Process repeater data from POST for saving
     *
     * Reindexes array to ensure sequential keys and handles empty arrays.
     * Use this when processing form submission for components.
     *
     * @param array $post_data The $_POST data for a repeater field
     * @return array Reindexed array suitable for JSON encoding
     */
    public static function process_repeater_data($post_data) {
        if (!is_array($post_data)) {
            return [];
        }
        // Reindex to ensure sequential keys
        return array_values($post_data);
    }
}
