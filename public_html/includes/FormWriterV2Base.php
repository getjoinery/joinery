<?php
/**
 * FormWriter v2 Base Class
 *
 * Modern form generation with clean API, automatic CSRF protection,
 * unified validation, and intelligent auto-detection.
 *
 * Phase 1: Standalone implementation (no breaking changes to v1)
 *
 * @version 2.0.0
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

        // Initialize validator
        require_once(PathHelper::getIncludePath('includes/Validator.php'));
        $this->validator = new Validator();

        // Store values array if provided
        if (isset($this->options['values'])) {
            $this->values = $this->options['values'];
        }

        // Initialize CSRF if needed
        $this->initializeCSRF();
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
    public function textarea($name, $label = '', $options = []) {
        $this->registerField($name, 'textarea', $label, $options);
        $this->outputTextarea($name, $label, $options);
    }

    /**
     * Create a select dropdown field
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options (must include 'options' key with select options)
     */
    public function dropinput($name, $label = '', $options = []) {
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
        $this->registerField($name, 'radio', $label, $options);
        $this->outputRadioInput($name, $label, $options);
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
        // Default to name_date and name_time if not specified
        $date_name = $options['date_name'] ?? $name . '_date';
        $time_name = $options['time_name'] ?? $name . '_time';

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

        $this->outputDateTimeInput($name, $label, $options, $date_name, $time_name);
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
        // Output CSS to ensure placeholders are visually distinct from actual input text
        echo '<style>';
        echo '#' . htmlspecialchars($this->form_id) . ' input::placeholder,';
        echo '#' . htmlspecialchars($this->form_id) . ' textarea::placeholder {';
        echo '  color: #999 !important;';
        echo '  opacity: 0.8 !important;';
        echo '}';
        echo '</style>';

        // Output form tag with all attributes from options
        echo '<form';
        echo ' id="' . htmlspecialchars($this->form_id) . '"';
        echo ' method="' . htmlspecialchars($this->options['method']) . '"';
        echo ' action="' . htmlspecialchars($this->options['action']) . '"';

        // Additional attributes
        if (!empty($this->options['class'])) {
            echo ' class="' . htmlspecialchars($this->options['class']) . '"';
        }
        if (!empty($this->options['enctype'])) {
            echo ' enctype="' . htmlspecialchars($this->options['enctype']) . '"';
        }

        // Any data-* attributes
        foreach ($this->options as $key => $value) {
            if (strpos($key, 'data-') === 0) {
                echo ' ' . htmlspecialchars($key) . '="' . htmlspecialchars($value) . '"';
            }
        }

        echo '>';

        // Output CSRF token if enabled
        if ($this->csrf_token) {
            echo '<input type="hidden" name="' . htmlspecialchars($this->options['csrf_field']) . '" value="' . htmlspecialchars($this->csrf_token) . '">';
        }
    }

    /**
     * Output the closing form tag
     */
    public function end_form() {
        // Output JoineryValidator initialization if we have validation rules
        // This is called AFTER all fields have been registered, so we have complete validation rules
        $this->outputJavascriptValidation();

        // Output any ready scripts added via addReadyScript()
        echo $this->outputReadyScripts();

        echo '</form>';
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

    // Abstract methods for theme-specific HTML generation
    // Each theme implementation must provide these methods

    abstract protected function outputTextInput($name, $label, $options);
    abstract protected function outputPasswordInput($name, $label, $options);
    abstract protected function outputTextarea($name, $label, $options);
    abstract protected function outputDropInput($name, $label, $options);
    abstract protected function outputCheckboxInput($name, $label, $options);
    abstract protected function outputRadioInput($name, $label, $options);
    abstract protected function outputDateInput($name, $label, $options);
    abstract protected function outputTimeInput($name, $label, $options);
    abstract protected function outputDateTimeInput($name, $label, $options, $date_name, $time_name);
    abstract protected function outputDateTimeInput2($name, $label, $options);
    abstract protected function outputFileInput($name, $label, $options);
    abstract protected function outputHiddenInput($name, $options);
    abstract protected function outputSubmitButton($name, $label, $options);

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

        $date_field = $field_name . '_date';
        $hour_field = $field_name . '_time_hour';
        $minute_field = $field_name . '_time_minute';
        $ampm_field = $field_name . '_time_ampm';

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
}
