<?php
/**
 * FormWriter v2 Base Class
 *
 * Modern form generation with clean API, automatic CSRF protection,
 * unified validation, and intelligent auto-detection.
 *
 * Phase 1: Standalone implementation (no breaking changes to v1)
 *
 * @version 2.6.1
 * @changelog 2.6.1 - prepareCheckboxData: use array_key_exists instead of isset so null 'checked' value is treated as unchecked (not missing)
 * @changelog 2.6.0 - Phase 2 cleanup: buildAjaxSelectScript shared method, visibility/custom_script in base output methods, outputTextbox uses handleOutput
 * @changelog 2.5.0 - Prepare/render split: base class concrete output*() methods call prepare*Data() + abstract render*()
 * @changelog 2.4.0 - Added numberinput(), repeater min/max/item_label, sub-field schema passthrough
 * @changelog 2.3.0 - Added colorpicker() method with theme color extraction and custom picker
 * @changelog 2.2.0 - Added imageselector() method for visual image picker with modal
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
    // TODO (security): CSRF protection currently covers form submissions only.
    // AJAX endpoints in ajax/*.php do not validate CSRF tokens. With SameSite=Lax
    // on the session cookie, cross-site POST CSRF is largely mitigated, but explicit
    // token validation on state-changing AJAX endpoints (conversations, reactions,
    // notifications, entity_photos, checkout) would provide defence-in-depth.
    // Implementation would require passing a CSRF token in JS requests and a
    // server-side validation helper callable outside of FormWriter.
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
     * Create a number input field
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options (supports min, max, step, placeholder, required)
     */
    public function numberinput($name, $label = '', $options = []) {
        $this->registerField($name, 'number', $label, $options);
        $this->outputNumberInput($name, $label, $options);
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
     * IMPORTANT: Options use [value => label] format where:
     *   - Key (value) = what gets submitted/stored in database
     *   - Value (label) = what the user sees in the dropdown
     *
     * Example - Correct:
     *   'options' => [
     *       'gdpr' => 'GDPR (Opt-in required)',
     *       'ccpa' => 'CCPA (Opt-out)',
     *       1 => 'Yes',
     *       0 => 'No',
     *   ]
     *
     * Example - WRONG (backwards):
     *   'options' => [
     *       'GDPR (Opt-in required)' => 'gdpr',  // Don't do this!
     *       'Yes' => 1,                           // Don't do this!
     *   ]
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options including:
     *   - 'options' array: [value => label] pairs for dropdown choices
     *   - 'value' mixed: Currently selected value
     *   - 'empty_option' bool|string: Add empty first option (true for "Select...", string for custom)
     *   - 'multiple' bool: Allow multiple selections
     *   - 'disabled' bool: Disable the field
     *   - 'helptext' string: Help text displayed below field
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
     * CORRECT USAGE: hiddeninput('field_name', '', ['value' => $value])
     *
     * Note: Always pass an empty string as the second parameter (label), even though
     * it's ignored for hidden fields. This maintains consistency with other form methods.
     *
     * @param string $name Field name
     * @param string|array $label Field label (ignored) or options array for backwards compatibility
     * @param array $options Field options (use 'value' key to set the hidden field value)
     */
    public function hiddeninput($name, $label = '', $options = []) {
        // Support legacy two-argument pattern for backwards compatibility
        if (is_array($label)) {
            $options = $label;
            $label = '';
        }
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
    public function submitbutton($name = 'btn_submit', $label = 'Submit', $options = []) {
        // If $name is empty string, use 'btn_submit'
        if (!$name) $name = 'btn_submit';

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
     * Create an image selector field with modal picker
     *
     * Complete implementation in base class - works for all themes out of the box.
     * Themes can override this method entirely if completely different markup is needed.
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options:
     *
     *   Core Options:
     *   - value: Current image URL
     *   - helptext: Help text
     *   - required: Boolean
     *   - placeholder: Placeholder text for search (default: 'Search images...')
     *   - ajax_endpoint: Custom endpoint URL (default: '/ajax/image_list_ajax')
     *   - page_size: Images per AJAX load (default: 20)
     *
     *   Styling Options (all optional - sensible defaults provided):
     *   - button_class: CSS class for select button (default: 'btn btn-outline-secondary btn-sm')
     *   - button_text: Button label (default: 'Select Image')
     *   - grid_columns: Number of columns in image grid (default: 5)
     *   - thumbnail_width: Thumbnail display width in px (default: 80)
     *   - preview_width: Preview image width in px (default: 80)
     *   - primary_color: Selection highlight color (default: '#0d6efd')
     *   - border_radius: Border radius for thumbnails (default: '4px')
     */
    public function imageselector($name, $label = '', $options = []) {
        $this->registerField($name, 'imageselector', $label, $options);

        // Extract options with defaults
        $value = $options['value'] ?? '';
        $help = $options['helptext'] ?? '';
        $id = $options['id'] ?? preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
        $ajaxEndpoint = $options['ajax_endpoint'] ?? '/ajax/image_list_ajax';
        $pageSize = $options['page_size'] ?? 20;
        $placeholder = $options['placeholder'] ?? 'Search images...';

        // Styling options with sensible defaults
        $buttonClass = $options['button_class'] ?? 'btn btn-outline-secondary btn-sm';
        $buttonText = $options['button_text'] ?? 'Select Image';
        $gridColumns = $options['grid_columns'] ?? 5;
        $thumbnailWidth = $options['thumbnail_width'] ?? 80;
        $previewWidth = $options['preview_width'] ?? 80;
        $primaryColor = $options['primary_color'] ?? '#0d6efd';
        $borderRadius = $options['border_radius'] ?? '4px';

        $uniqueId = 'imgselector_' . $id . '_' . uniqid();

        // Output field wrapper
        echo '<div class="mb-3 imageselector-wrapper" id="' . htmlspecialchars($uniqueId) . '" ';
        echo 'data-endpoint="' . htmlspecialchars($ajaxEndpoint) . '" ';
        echo 'data-pagesize="' . intval($pageSize) . '" ';
        echo 'data-fieldname="' . htmlspecialchars($name) . '" ';
        echo 'data-fieldid="' . htmlspecialchars($id) . '"';
        echo ' style="--is-primary-color:' . htmlspecialchars($primaryColor) . ';';
        echo '--is-grid-columns:' . intval($gridColumns) . ';';
        echo '--is-thumbnail-width:' . intval($thumbnailWidth) . 'px;';
        echo '--is-preview-width:' . intval($previewWidth) . 'px;';
        echo '--is-border-radius:' . htmlspecialchars($borderRadius) . ';">';

        // Label
        if ($label) {
            echo '<label class="form-label">' . htmlspecialchars($label) . '</label>';
        }

        // Hidden input for URL value
        echo '<input type="hidden" name="' . htmlspecialchars($name) . '" ';
        echo 'id="' . htmlspecialchars($id) . '" ';
        echo 'class="imageselector-value" ';
        echo 'value="' . htmlspecialchars($value) . '">';

        // Preview and button container
        echo '<div class="d-flex align-items-center gap-2 imageselector-controls">';

        // Preview area
        echo '<div class="imageselector-preview">';
        if ($value) {
            echo '<img src="' . htmlspecialchars($value) . '" alt="Selected image">';
        } else {
            echo '<div class="imageselector-no-preview"><i class="bx bx-image" style="font-size:24px;color:#ccc;"></i></div>';
        }
        echo '</div>';

        // Select button
        echo '<button type="button" class="imageselector-open ' . htmlspecialchars($buttonClass) . '">';
        echo htmlspecialchars($buttonText);
        echo '</button>';

        // Clear button
        echo '<button type="button" class="imageselector-clear btn btn-outline-danger btn-sm" ';
        if (!$value) {
            echo 'style="display:none;" ';
        }
        echo 'title="Clear selection">&times;</button>';

        // Current filename display
        if ($value) {
            $filename = basename($value);
            echo '<small class="imageselector-filename text-muted">' . htmlspecialchars($filename) . '</small>';
        } else {
            echo '<small class="imageselector-filename text-muted"></small>';
        }

        echo '</div>'; // end controls container

        // Help text
        if ($help) {
            echo '<div class="form-text">' . htmlspecialchars($help) . '</div>';
        }

        echo '</div>'; // end wrapper

        // Output CSS and JS assets (once per page)
        $this->outputImageSelectorAssets($placeholder);
    }

    /**
     * Output CSS and JavaScript for image selector functionality
     * Called once per page regardless of number of image selector fields
     */
    protected function outputImageSelectorAssets($placeholder) {
        static $assets_loaded = false;
        if ($assets_loaded) return;
        $assets_loaded = true;

        // Inline CSS
        echo '<style>
.imageselector-wrapper {
    --is-primary-color: #0d6efd;
    --is-grid-columns: 5;
    --is-thumbnail-width: 80px;
    --is-preview-width: 80px;
    --is-border-radius: 4px;
}
.imageselector-preview {
    width: var(--is-preview-width);
    height: var(--is-preview-width);
    border: 1px solid #dee2e6;
    border-radius: var(--is-border-radius);
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f8f9fa;
    flex-shrink: 0;
}
.imageselector-preview img {
    max-width: 100%;
    max-height: 100%;
    object-fit: cover;
}
.imageselector-no-preview {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
}
.imageselector-filename {
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
/* Modal styles */
.imageselector-modal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    z-index: 10000;
    align-items: center;
    justify-content: center;
}
.imageselector-modal.active {
    display: flex;
}
.imageselector-modal-content {
    background: #fff;
    border-radius: 8px;
    width: 90%;
    max-width: 800px;
    max-height: 80vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
}
.imageselector-modal-header {
    padding: 15px 20px;
    border-bottom: 1px solid #dee2e6;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.imageselector-modal-header h5 {
    margin: 0;
    font-size: 1.1rem;
}
.imageselector-modal-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    color: #666;
    line-height: 1;
}
.imageselector-modal-close:hover {
    color: #000;
}
.imageselector-search {
    padding: 15px 20px;
    border-bottom: 1px solid #dee2e6;
}
.imageselector-search input {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    font-size: 14px;
}
.imageselector-search input:focus {
    outline: none;
    border-color: var(--is-primary-color);
    box-shadow: 0 0 0 2px rgba(13, 110, 253, 0.15);
}
.imageselector-grid-container {
    flex: 1;
    overflow-y: auto;
    padding: 15px;
}
.imageselector-grid {
    display: grid;
    grid-template-columns: repeat(var(--is-grid-columns), 1fr);
    gap: 10px;
}
.imageselector-item {
    aspect-ratio: 1;
    cursor: pointer;
    border: 2px solid transparent;
    border-radius: var(--is-border-radius);
    overflow: hidden;
    background: #f8f9fa;
    transition: border-color 0.15s, transform 0.15s;
}
.imageselector-item:hover {
    border-color: #adb5bd;
    transform: scale(1.02);
}
.imageselector-item.selected {
    border-color: var(--is-primary-color);
    box-shadow: 0 0 0 2px rgba(13, 110, 253, 0.25);
}
.imageselector-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.is-placeholder-icon {
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: #adb5bd;
    background: #f8f9fa;
    gap: 8px;
}
.is-placeholder-icon svg {
    opacity: 0.5;
}
.is-placeholder-icon span {
    font-size: 10px;
    text-align: center;
    opacity: 0.7;
}
.imageselector-loading {
    text-align: center;
    padding: 20px;
    color: #666;
}
.imageselector-empty {
    text-align: center;
    padding: 40px 20px;
    color: #666;
}
.imageselector-load-more {
    text-align: center;
    padding: 15px;
}
.imageselector-modal-footer {
    padding: 15px 20px;
    border-top: 1px solid #dee2e6;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}
/* Responsive adjustments */
@media (max-width: 768px) {
    .imageselector-grid {
        grid-template-columns: repeat(3, 1fr);
    }
    .imageselector-modal-content {
        width: 95%;
        max-height: 90vh;
    }
}
</style>';

        // Inline JavaScript
        echo '<script>
(function() {
    "use strict";

    // Image Selector Manager
    window.ImageSelectorManager = {
        modal: null,
        activeWrapper: null,
        images: [],
        offset: 0,
        hasMore: true,
        loading: false,
        searchTimeout: null,
        selectedUrl: null,

        init: function() {
            this.createModal();
            this.bindEvents();
        },

        createModal: function() {
            if (document.getElementById("imageselector-modal")) return;

            var modal = document.createElement("div");
            modal.id = "imageselector-modal";
            modal.className = "imageselector-modal";
            modal.innerHTML = \'<div class="imageselector-modal-content">\' +
                \'<div class="imageselector-modal-header">\' +
                    \'<h5>Select Image</h5>\' +
                    \'<button type="button" class="imageselector-modal-close">&times;</button>\' +
                \'</div>\' +
                \'<div class="imageselector-search">\' +
                    \'<input type="text" placeholder="' . htmlspecialchars($placeholder) . '" class="imageselector-search-input">\' +
                \'</div>\' +
                \'<div class="imageselector-grid-container">\' +
                    \'<div class="imageselector-grid"></div>\' +
                    \'<div class="imageselector-loading" style="display:none;">Loading...</div>\' +
                    \'<div class="imageselector-empty" style="display:none;">No images found</div>\' +
                    \'<div class="imageselector-load-more" style="display:none;">\' +
                        \'<button type="button" class="btn btn-outline-secondary btn-sm">Load More</button>\' +
                    \'</div>\' +
                \'</div>\' +
                \'<div class="imageselector-modal-footer">\' +
                    \'<button type="button" class="btn btn-secondary imageselector-cancel">Cancel</button>\' +
                    \'<button type="button" class="btn btn-primary imageselector-confirm" disabled>Select</button>\' +
                \'</div>\' +
            \'</div>\';
            document.body.appendChild(modal);
            this.modal = modal;

            // Apply CSS variables from active wrapper when opening
            var gridContainer = modal.querySelector(".imageselector-grid-container");
            gridContainer.addEventListener("scroll", this.handleScroll.bind(this));
        },

        bindEvents: function() {
            var self = this;

            // Delegate click events for open buttons
            document.addEventListener("click", function(e) {
                // Open button
                if (e.target.classList.contains("imageselector-open")) {
                    var wrapper = e.target.closest(".imageselector-wrapper");
                    if (wrapper) self.open(wrapper);
                }
                // Clear button
                if (e.target.classList.contains("imageselector-clear")) {
                    var wrapper = e.target.closest(".imageselector-wrapper");
                    if (wrapper) self.clear(wrapper);
                }
            });

            // Modal events (delegated)
            document.addEventListener("click", function(e) {
                // Close button
                if (e.target.classList.contains("imageselector-modal-close")) {
                    self.close();
                }
                // Cancel button
                if (e.target.classList.contains("imageselector-cancel")) {
                    self.close();
                }
                // Confirm button
                if (e.target.classList.contains("imageselector-confirm")) {
                    self.confirm();
                }
                // Image item click
                if (e.target.closest(".imageselector-item")) {
                    self.selectImage(e.target.closest(".imageselector-item"));
                }
                // Load more button
                if (e.target.closest(".imageselector-load-more button")) {
                    self.loadMore();
                }
                // Close on backdrop click
                if (e.target.classList.contains("imageselector-modal")) {
                    self.close();
                }
            });

            // Search input
            document.addEventListener("input", function(e) {
                if (e.target.classList.contains("imageselector-search-input")) {
                    clearTimeout(self.searchTimeout);
                    self.searchTimeout = setTimeout(function() {
                        self.search(e.target.value);
                    }, 300);
                }
            });

            // Escape key closes modal
            document.addEventListener("keydown", function(e) {
                if (e.key === "Escape" && self.modal && self.modal.classList.contains("active")) {
                    self.close();
                }
            });
        },

        open: function(wrapper) {
            this.activeWrapper = wrapper;
            this.selectedUrl = null;
            this.offset = 0;
            this.hasMore = true;
            this.images = [];

            // Copy CSS variables from wrapper to modal
            var style = getComputedStyle(wrapper);
            this.modal.style.setProperty("--is-primary-color", style.getPropertyValue("--is-primary-color"));
            this.modal.style.setProperty("--is-grid-columns", style.getPropertyValue("--is-grid-columns"));
            this.modal.style.setProperty("--is-border-radius", style.getPropertyValue("--is-border-radius"));

            // Reset modal state
            this.modal.querySelector(".imageselector-search-input").value = "";
            this.modal.querySelector(".imageselector-grid").innerHTML = "";
            this.modal.querySelector(".imageselector-confirm").disabled = true;

            // Show modal
            this.modal.classList.add("active");
            document.body.style.overflow = "hidden";

            // Load first batch
            this.loadImages();
        },

        close: function() {
            this.modal.classList.remove("active");
            document.body.style.overflow = "";
            this.activeWrapper = null;
        },

        loadImages: function(append) {
            if (this.loading) return;
            this.loading = true;

            var self = this;
            var wrapper = this.activeWrapper;
            var endpoint = wrapper.dataset.endpoint;
            var pageSize = parseInt(wrapper.dataset.pagesize) || 20;
            var searchValue = this.modal.querySelector(".imageselector-search-input").value;

            var url = endpoint + "?limit=" + pageSize + "&offset=" + this.offset;
            if (searchValue) {
                url += "&q=" + encodeURIComponent(searchValue);
            }

            // Show loading
            this.modal.querySelector(".imageselector-loading").style.display = "block";
            this.modal.querySelector(".imageselector-empty").style.display = "none";
            this.modal.querySelector(".imageselector-load-more").style.display = "none";

            fetch(url)
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    self.loading = false;
                    self.modal.querySelector(".imageselector-loading").style.display = "none";

                    if (data.error) {
                        self.modal.querySelector(".imageselector-empty").textContent = data.error;
                        self.modal.querySelector(".imageselector-empty").style.display = "block";
                        return;
                    }

                    self.hasMore = data.hasMore;

                    if (!append) {
                        self.images = data.images;
                        self.modal.querySelector(".imageselector-grid").innerHTML = "";
                    } else {
                        self.images = self.images.concat(data.images);
                    }

                    if (self.images.length === 0) {
                        self.modal.querySelector(".imageselector-empty").style.display = "block";
                        return;
                    }

                    self.renderImages(data.images);

                    if (self.hasMore) {
                        self.modal.querySelector(".imageselector-load-more").style.display = "block";
                    }
                })
                .catch(function(err) {
                    self.loading = false;
                    self.modal.querySelector(".imageselector-loading").style.display = "none";
                    self.modal.querySelector(".imageselector-empty").textContent = "Error loading images";
                    self.modal.querySelector(".imageselector-empty").style.display = "block";
                    console.error("ImageSelector error:", err);
                });
        },

        renderImages: function(images) {
            var grid = this.modal.querySelector(".imageselector-grid");
            var currentValue = this.activeWrapper.querySelector(".imageselector-value").value;

            images.forEach(function(img) {
                var item = document.createElement("div");
                item.className = "imageselector-item";
                item.dataset.url = img.url;
                item.dataset.filename = img.filename;
                item.title = img.title || img.filename;

                // Check if this is the currently selected image
                if (img.url === currentValue) {
                    item.classList.add("selected");
                    this.selectedUrl = img.url;
                    this.modal.querySelector(".imageselector-confirm").disabled = false;
                }

                var imgEl = document.createElement("img");
                imgEl.src = img.thumbnail;
                imgEl.alt = img.title || img.filename;
                imgEl.loading = "lazy";
                imgEl.dataset.fallback = img.url;
                imgEl.dataset.fallbackAttempted = "false";
                // Fallback to standard URL if thumbnail fails, then show placeholder if both fail
                imgEl.onerror = function() {
                    if (this.dataset.fallbackAttempted === "false" && this.dataset.fallback && this.src !== this.dataset.fallback) {
                        // First failure: try the standard URL
                        this.dataset.fallbackAttempted = "true";
                        this.src = this.dataset.fallback;
                    } else {
                        // Both thumbnail and standard URL failed - show placeholder
                        this.style.display = "none";
                        var placeholder = document.createElement("div");
                        placeholder.className = "is-placeholder-icon";
                        placeholder.innerHTML = "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"48\" height=\"48\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><rect x=\"3\" y=\"3\" width=\"18\" height=\"18\" rx=\"2\" ry=\"2\"/><circle cx=\"8.5\" cy=\"8.5\" r=\"1.5\"/><polyline points=\"21 15 16 10 5 21\"/></svg><span>Image unavailable</span>";
                        this.parentNode.appendChild(placeholder);
                    }
                };

                item.appendChild(imgEl);
                grid.appendChild(item);
            }, this);
        },

        selectImage: function(item) {
            // Remove selection from all
            this.modal.querySelectorAll(".imageselector-item.selected").forEach(function(el) {
                el.classList.remove("selected");
            });

            // Select this one
            item.classList.add("selected");
            this.selectedUrl = item.dataset.url;

            // Enable confirm button
            this.modal.querySelector(".imageselector-confirm").disabled = false;
        },

        confirm: function() {
            if (!this.selectedUrl || !this.activeWrapper) return;

            var wrapper = this.activeWrapper;
            var valueInput = wrapper.querySelector(".imageselector-value");
            var preview = wrapper.querySelector(".imageselector-preview");
            var clearBtn = wrapper.querySelector(".imageselector-clear");
            var filenameEl = wrapper.querySelector(".imageselector-filename");

            // Update value
            valueInput.value = this.selectedUrl;

            // Update preview
            preview.innerHTML = \'<img src="\' + this.selectedUrl + \'" alt="Selected image">\';

            // Show clear button
            clearBtn.style.display = "";

            // Update filename
            var filename = this.selectedUrl.split("/").pop();
            filenameEl.textContent = filename;

            // Trigger change event
            valueInput.dispatchEvent(new Event("change", { bubbles: true }));

            this.close();
        },

        clear: function(wrapper) {
            var valueInput = wrapper.querySelector(".imageselector-value");
            var preview = wrapper.querySelector(".imageselector-preview");
            var clearBtn = wrapper.querySelector(".imageselector-clear");
            var filenameEl = wrapper.querySelector(".imageselector-filename");

            // Clear value
            valueInput.value = "";

            // Reset preview
            preview.innerHTML = \'<div class="imageselector-no-preview"><i class="bx bx-image" style="font-size:24px;color:#ccc;"></i></div>\';

            // Hide clear button
            clearBtn.style.display = "none";

            // Clear filename
            filenameEl.textContent = "";

            // Trigger change event
            valueInput.dispatchEvent(new Event("change", { bubbles: true }));
        },

        search: function(query) {
            this.offset = 0;
            this.hasMore = true;
            this.loadImages(false);
        },

        loadMore: function() {
            if (!this.hasMore || this.loading) return;
            var pageSize = parseInt(this.activeWrapper.dataset.pagesize) || 20;
            this.offset += pageSize;
            this.loadImages(true);
        },

        handleScroll: function(e) {
            var container = e.target;
            // Auto-load more when near bottom
            if (this.hasMore && !this.loading) {
                if (container.scrollHeight - container.scrollTop - container.clientHeight < 100) {
                    this.loadMore();
                }
            }
        }
    };

    // Initialize when DOM is ready
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", function() {
            ImageSelectorManager.init();
        });
    } else {
        ImageSelectorManager.init();
    }
})();
</script>';
    }

    /**
     * Create a color picker field with theme color swatches
     *
     * Provides theme-extracted color swatches plus a native color picker for custom colors.
     * Colors are automatically extracted from the current theme's CSS files.
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options:
     *   - value: Current hex color value (default: '')
     *   - helptext: Help text
     *   - required: Boolean
     *   - theme: Theme name to scan for colors (default: current theme)
     *   - max_swatches: Maximum swatches to show (default: 100)
     *   - custom_colors: Additional colors to include (array of hex values)
     *   - show_custom_picker: Show "Custom..." button (default: true)
     *   - swatch_size: CSS size for swatches (default: '32px')
     *   - sort: Sort order - 'dark_first', 'light_first', 'frequency', 'none' (default: 'dark_first')
     *   - initial_display: Colors to show before "more" link (default: 100)
     */
    public function colorpicker($name, $label = '', $options = []) {
        $this->registerField($name, 'colorpicker', $label, $options);

        // Extract options with defaults
        $value = $options['value'] ?? '';
        $help = $options['helptext'] ?? '';
        $required = !empty($options['required']);
        $id = $options['id'] ?? preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
        $theme = $options['theme'] ?? null;
        $max_swatches = $options['max_swatches'] ?? 100; // Hard limit for sanity
        $initial_display = $options['initial_display'] ?? 100; // Show this many before "more"
        $custom_colors = $options['custom_colors'] ?? [];
        $show_custom_picker = $options['show_custom_picker'] ?? true;
        $swatch_size = $options['swatch_size'] ?? '32px';
        $sort = $options['sort'] ?? 'dark_first';

        // Get theme colors (fetch up to max)
        $theme_colors = $this->extractThemeColors($theme, [
            'limit' => $max_swatches,
            'sort' => $sort
        ]);

        // Merge custom colors at the beginning
        if (!empty($custom_colors)) {
            $theme_colors = array_merge($custom_colors, $theme_colors);
            $theme_colors = array_unique($theme_colors);
            $theme_colors = array_slice($theme_colors, 0, $max_swatches);
        }

        // Calculate if we need "show more"
        $total_colors = count($theme_colors);
        $has_more = $total_colors > $initial_display;

        $uniqueId = 'colorpicker_' . $id . '_' . uniqid();

        // Output field wrapper (starts collapsed)
        echo '<div class="mb-3 colorpicker-wrapper" id="' . htmlspecialchars($uniqueId) . '"';
        echo ' style="--cp-swatch-size:' . htmlspecialchars($swatch_size) . ';">';

        // Label
        if ($label) {
            echo '<label class="form-label" for="' . htmlspecialchars($id) . '">';
            echo htmlspecialchars($label);
            if ($required) {
                echo ' <span class="text-danger">*</span>';
            }
            echo '</label>';
        }

        // Hidden input for the actual value
        echo '<input type="hidden" name="' . htmlspecialchars($name) . '" ';
        echo 'id="' . htmlspecialchars($id) . '" ';
        echo 'class="colorpicker-value" ';
        echo 'value="' . htmlspecialchars($value) . '"';
        if ($required) {
            echo ' required';
        }
        echo '>';

        // Collapsed trigger - color preview + hex + expand icon
        echo '<div class="colorpicker-trigger">';
        echo '<div class="colorpicker-preview" style="background-color:' . htmlspecialchars($value ?: 'transparent') . ';"></div>';
        echo '<input type="text" class="colorpicker-hex-input form-control form-control-sm" ';
        echo 'value="' . htmlspecialchars($value) . '" ';
        echo 'placeholder="#000000" ';
        echo 'pattern="^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$">';
        echo '<button type="button" class="colorpicker-expand-btn" title="Choose from theme colors">';
        echo '<svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor"><path d="M8 4a.5.5 0 0 1 .5.5v3h3a.5.5 0 0 1 0 1h-3v3a.5.5 0 0 1-1 0v-3h-3a.5.5 0 0 1 0-1h3v-3A.5.5 0 0 1 8 4z"/><path d="M2 2a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V2zm2-1a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1H4z"/></svg>';
        echo '</button>';
        echo '</div>';

        // Expandable swatches panel (hidden by default)
        echo '<div class="colorpicker-panel">';
        echo '<div class="colorpicker-swatches">';

        // Theme color swatches
        foreach ($theme_colors as $index => $color) {
            $is_selected = (strtolower($value) === strtolower($color));
            $swatch_class = 'colorpicker-swatch' . ($is_selected ? ' selected' : '');

            // Hide colors beyond initial_display
            if ($has_more && $index >= $initial_display) {
                $swatch_class .= ' colorpicker-hidden';
            }

            echo '<div class="' . $swatch_class . '" ';
            echo 'data-color="' . htmlspecialchars($color) . '" ';
            echo 'style="background-color:' . htmlspecialchars($color) . ';" ';
            echo 'title="' . htmlspecialchars($color) . '"></div>';
        }

        // "Show more" link
        if ($has_more) {
            $remaining = $total_colors - $initial_display;
            echo '<a href="#" class="colorpicker-show-more">+' . $remaining . ' more</a>';
        }

        // Custom color picker button
        if ($show_custom_picker) {
            echo '<label class="colorpicker-custom-btn" title="Choose custom color">';
            echo '<input type="color" class="colorpicker-native" value="' . htmlspecialchars($value ?: '#000000') . '">';
            echo '<span>Custom...</span>';
            echo '</label>';
        }

        echo '</div>'; // end swatches
        echo '</div>'; // end panel

        // Help text
        if ($help) {
            echo '<div class="form-text">' . htmlspecialchars($help) . '</div>';
        }

        echo '</div>'; // end wrapper

        // Output CSS and JS assets (once per page)
        $this->outputColorPickerAssets();
    }

    /**
     * Extract colors from theme CSS files
     *
     * @param string|null $theme_name Theme name, or null for current theme
     * @param array $options Options: limit, sort ('dark_first'|'light_first'|'frequency'|'none'), dedupe
     * @return array Array of hex color codes
     */
    protected function extractThemeColors($theme_name = null, $options = []) {
        static $color_cache = [];

        // Get theme name
        if ($theme_name === null) {
            $settings = Globalvars::get_instance();
            $theme_name = $settings->get_setting('theme_template');
        }

        // Check cache
        $cache_key = $theme_name . '_' . md5(serialize($options));
        if (isset($color_cache[$cache_key])) {
            return $color_cache[$cache_key];
        }

        // Default options
        $limit = $options['limit'] ?? 20;
        $sort = $options['sort'] ?? 'dark_first';
        $dedupe = $options['dedupe'] ?? true;

        // Find CSS files (skip framework/library files)
        $css_dir = PathHelper::getIncludePath('theme/' . $theme_name . '/assets/css');
        $colors = [];

        // Framework files to skip - these contain generic colors, not theme-specific
        $skip_patterns = [
            'bootstrap', 'boxicons', 'fontawesome', 'animate',
            'magnific', 'owl.', 'slick', 'swiper', 'flaticon',
            'meanmenu', 'nice-select', 'jquery', 'vendor'
        ];

        if (is_dir($css_dir)) {
            $css_files = glob($css_dir . '/*.css');
            foreach ($css_files as $css_file) {
                // Skip framework files
                $filename = strtolower(basename($css_file));
                $skip = false;
                foreach ($skip_patterns as $pattern) {
                    if (strpos($filename, $pattern) !== false) {
                        $skip = true;
                        break;
                    }
                }
                if ($skip) continue;

                $content = file_get_contents($css_file);
                if (!$content) continue;

                // Match hex colors: #fff, #ffffff
                preg_match_all('/#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})\b/', $content, $matches);
                if (!empty($matches[0])) {
                    $colors = array_merge($colors, $matches[0]);
                }

                // Match rgb/rgba
                preg_match_all('/rgba?\s*\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/', $content, $matches, PREG_SET_ORDER);
                foreach ($matches as $m) {
                    $r = min(255, max(0, intval($m[1])));
                    $g = min(255, max(0, intval($m[2])));
                    $b = min(255, max(0, intval($m[3])));
                    $colors[] = sprintf('#%02x%02x%02x', $r, $g, $b);
                }
            }
        }

        // Normalize to lowercase 6-char hex
        $hex_colors = [];
        foreach ($colors as $color) {
            $color = strtolower(trim($color));
            // Expand 3-char hex
            if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $color, $m)) {
                $color = '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
            }
            if (preg_match('/^#[0-9a-f]{6}$/', $color)) {
                $hex_colors[] = $color;
            }
        }

        // Sort by frequency (most-mentioned colors first), then deduplicate
        if ($sort === 'frequency') {
            $counts = array_count_values($hex_colors);
            arsort($counts);
            $hex_colors = array_keys($counts);
        } else {
            // Deduplicate, then sort by luminance
            if ($dedupe) {
                $hex_colors = array_unique($hex_colors);
            }
            if ($sort !== 'none') {
                usort($hex_colors, function($a, $b) use ($sort) {
                    $lumA = $this->getColorLuminance($a);
                    $lumB = $this->getColorLuminance($b);
                    if ($sort === 'light_first') {
                        return $lumB <=> $lumA;
                    }
                    return $lumA <=> $lumB; // dark_first
                });
            }
        }

        // Limit
        if ($limit > 0 && count($hex_colors) > $limit) {
            $hex_colors = array_slice($hex_colors, 0, $limit);
        }

        $hex_colors = array_values($hex_colors);
        $color_cache[$cache_key] = $hex_colors;
        return $hex_colors;
    }

    /**
     * Calculate luminance of a hex color
     *
     * @param string $hex Hex color
     * @return float Luminance (0-1)
     */
    protected function getColorLuminance($hex) {
        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;

        // sRGB to linear
        $r = $r <= 0.03928 ? $r / 12.92 : pow(($r + 0.055) / 1.055, 2.4);
        $g = $g <= 0.03928 ? $g / 12.92 : pow(($g + 0.055) / 1.055, 2.4);
        $b = $b <= 0.03928 ? $b / 12.92 : pow(($b + 0.055) / 1.055, 2.4);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /**
     * Output CSS and JavaScript for color picker functionality
     */
    protected function outputColorPickerAssets() {
        static $assets_loaded = false;
        if ($assets_loaded) return;
        $assets_loaded = true;

        echo '<style>
.colorpicker-wrapper {
    --cp-swatch-size: 32px;
    position: relative;
}
.colorpicker-trigger {
    display: flex;
    align-items: center;
    gap: 8px;
    width: fit-content;
}
.colorpicker-preview {
    width: 36px;
    height: 36px;
    border: 1px solid #ccc;
    border-radius: 4px;
    flex-shrink: 0;
}
.colorpicker-hex-input {
    width: 100px;
    font-family: monospace;
}
.colorpicker-expand-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    padding: 0;
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    cursor: pointer;
    color: #495057;
    transition: background 0.15s, border-color 0.15s;
}
.colorpicker-expand-btn:hover {
    background: #e9ecef;
    border-color: #adb5bd;
}
.colorpicker-wrapper.expanded .colorpicker-expand-btn {
    background: #0d6efd;
    border-color: #0d6efd;
    color: #fff;
}
.colorpicker-panel {
    display: none;
    margin-top: 10px;
    padding: 12px;
    background: #fff;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}
.colorpicker-wrapper.expanded .colorpicker-panel {
    display: block;
}
.colorpicker-swatches {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.colorpicker-swatch {
    width: var(--cp-swatch-size);
    height: var(--cp-swatch-size);
    border-radius: 4px;
    cursor: pointer;
    border: 2px solid #ccc;
    transition: border-color 0.15s, transform 0.15s;
}
.colorpicker-swatch:hover {
    border-color: #666;
    transform: scale(1.1);
}
.colorpicker-swatch.selected {
    border-color: #000;
    box-shadow: 0 0 0 2px #fff, 0 0 0 4px #000;
}
.colorpicker-custom-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    background: #f0f0f0;
    border: 2px dashed #999;
    border-radius: 4px;
    cursor: pointer;
    font-size: 13px;
    height: var(--cp-swatch-size);
    box-sizing: border-box;
}
.colorpicker-custom-btn:hover {
    border-color: #333;
    background: #e8e8e8;
}
.colorpicker-custom-btn input[type="color"] {
    width: 20px;
    height: 20px;
    padding: 0;
    border: 1px solid #ccc;
    border-radius: 2px;
    cursor: pointer;
}
.colorpicker-hidden {
    display: none;
}
.colorpicker-show-more {
    display: inline-flex;
    align-items: center;
    padding: 6px 12px;
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    font-size: 13px;
    color: #495057;
    text-decoration: none;
    height: var(--cp-swatch-size);
    box-sizing: border-box;
}
.colorpicker-show-more:hover {
    background: #e9ecef;
    color: #212529;
    text-decoration: none;
}
</style>';

        echo '<script>
(function() {
    "use strict";

    document.addEventListener("click", function(e) {
        // Expand button click
        if (e.target.closest(".colorpicker-expand-btn")) {
            e.preventDefault();
            var wrapper = e.target.closest(".colorpicker-wrapper");
            wrapper.classList.toggle("expanded");
            return;
        }

        // Swatch click
        if (e.target.classList.contains("colorpicker-swatch")) {
            var wrapper = e.target.closest(".colorpicker-wrapper");
            var color = e.target.dataset.color;
            selectColor(wrapper, color);
            wrapper.classList.remove("expanded");
            return;
        }

        // Show more link
        if (e.target.classList.contains("colorpicker-show-more")) {
            e.preventDefault();
            var wrapper = e.target.closest(".colorpicker-wrapper");
            wrapper.querySelectorAll(".colorpicker-hidden").forEach(function(el) {
                el.classList.remove("colorpicker-hidden");
            });
            e.target.style.display = "none";
            return;
        }

        // Click outside - close any open panels
        if (!e.target.closest(".colorpicker-wrapper")) {
            document.querySelectorAll(".colorpicker-wrapper.expanded").forEach(function(w) {
                w.classList.remove("expanded");
            });
        }
    });

    document.addEventListener("input", function(e) {
        // Native color picker change
        if (e.target.classList.contains("colorpicker-native")) {
            var wrapper = e.target.closest(".colorpicker-wrapper");
            var color = e.target.value;
            selectColor(wrapper, color);
        }

        // Hex input change
        if (e.target.classList.contains("colorpicker-hex-input")) {
            var wrapper = e.target.closest(".colorpicker-wrapper");
            var color = e.target.value.trim();
            if (/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(color)) {
                selectColor(wrapper, color, true);
            }
        }
    });

    function selectColor(wrapper, color, fromInput) {
        var valueInput = wrapper.querySelector(".colorpicker-value");
        var preview = wrapper.querySelector(".colorpicker-preview");
        var hexInput = wrapper.querySelector(".colorpicker-hex-input");
        var nativePicker = wrapper.querySelector(".colorpicker-native");

        // Update hidden value
        valueInput.value = color;

        // Update preview
        preview.style.backgroundColor = color;

        // Update hex input (unless triggered from it)
        if (!fromInput) {
            hexInput.value = color;
        }

        // Update native picker
        if (nativePicker) {
            // Expand 3-char hex for native picker
            var expandedColor = color;
            if (/^#[0-9a-fA-F]{3}$/.test(color)) {
                expandedColor = "#" + color[1] + color[1] + color[2] + color[2] + color[3] + color[3];
            }
            nativePicker.value = expandedColor;
        }

        // Update swatch selection
        wrapper.querySelectorAll(".colorpicker-swatch").forEach(function(swatch) {
            var swatchColor = swatch.dataset.color.toLowerCase();
            var selectedColor = color.toLowerCase();
            // Expand for comparison
            if (/^#[0-9a-fA-F]{3}$/.test(selectedColor)) {
                selectedColor = "#" + selectedColor[1] + selectedColor[1] + selectedColor[2] + selectedColor[2] + selectedColor[3] + selectedColor[3];
            }
            swatch.classList.toggle("selected", swatchColor === selectedColor);
        });

        // Trigger change event
        valueInput.dispatchEvent(new Event("change", { bubbles: true }));
    }
})();
</script>';
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
            } else if (isset($options['validation']) && is_string($options['validation'])) {
                $string_validation = $this->getTypeValidation($options['validation']);
                $options['validation'] = array_merge($base_validation, $string_validation);
            } else if (!isset($options['validation'])) {
                $options['validation'] = $base_validation;
            }
        }

        // Propagate required option into validation rules so JoineryValidator
        // gets instantiated (which also sets novalidate on the form).
        if (!empty($options['required'])) {
            $options['validation']['required'] = true;
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
    var hourEl = document.getElementById(hourId);
    var minuteEl = document.getElementById(minuteId);
    var ampmEl = document.getElementById(ampmId);
    var hiddenEl = document.getElementById(hiddenId);
    if (!hourEl || !minuteEl || !ampmEl || !hiddenEl) return;

    var hour = hourEl.value;
    var minute = minuteEl.value;
    var ampm = ampmEl.value;

    if (hour === "" || minute === "") {
        hiddenEl.value = "";
        return;
    }

    var h = parseInt(hour, 10);
    if (ampm === "PM" && h !== 12) h += 12;
    if (ampm === "AM" && h === 12) h = 0;

    hiddenEl.value = String(h).padStart(2, "0") + ":" + String(minute).padStart(2, "0");
}

function wireTimeInput(el) {
    var hourId = el.getAttribute("data-time-hour");
    var minuteId = el.getAttribute("data-time-minute");
    var ampmId = el.getAttribute("data-time-ampm");
    var hiddenId = el.getAttribute("data-time-hidden");
    var ids = [hourId, minuteId, ampmId];
    var update = function() { updateTimeInput(hourId, minuteId, ampmId, hiddenId); };
    ids.forEach(function(id) {
        var node = document.getElementById(id);
        if (!node) return;
        node.addEventListener("change", update);
        node.addEventListener("input", update);
    });
    // Normalize the hidden input on page load so server sees a clean value
    // even if the user submits without changing any of the parts.
    update();
}

document.addEventListener("DOMContentLoaded", function() {
    var timeInputs = document.querySelectorAll("[data-time-hour]");
    timeInputs.forEach(wireTimeInput);
});
</script>';
            $time_input_js_loaded = true;
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

        // If value is a datetime string ("Y-m-d H:i:s" — e.g. produced by
        // convertDateTimeFieldsToLocalTime for a time-only column), strip the
        // date portion so we only parse the time. Without this, the colon-split
        // below treats "2026-04-28 23" as the hour part and intval gives 2026.
        if (strpos($value, ' ') !== false && strpos($value, '-') !== false) {
            $parts = explode(' ', $value);
            $value = end($parts);
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

    // ===== PREPARE/RENDER SPLIT ARCHITECTURE =====
    // Concrete output*() methods call prepare*Data() + abstract render*()
    // Each subclass implements render*() — pure HTML generation only

    // ── Shared HTML attribute helpers ────────────────────────────────────────

    /**
     * Build common boolean/value attributes shared by many inputs
     * @param array $data Prepared data array
     * @param array $extra_keys Map of data key => html attribute name for value attributes
     * @return string HTML attribute string
     */
    protected function buildCommonAttributes($data, $extra_keys = []) {
        $attrs = '';
        if (!empty($data['disabled'])) $attrs .= ' disabled';
        if (!empty($data['required'])) $attrs .= ' required';
        if (!empty($data['readonly'])) $attrs .= ' readonly';
        if (!empty($data['autofocus'])) $attrs .= ' autofocus';
        if (!empty($data['onchange'])) $attrs .= ' onchange="' . htmlspecialchars($data['onchange']) . '"';
        if (!empty($data['autocomplete'])) $attrs .= ' autocomplete="' . htmlspecialchars($data['autocomplete']) . '"';
        foreach ($extra_keys as $key => $attr_name) {
            if (isset($data[$key])) $attrs .= ' ' . $attr_name . '="' . htmlspecialchars($data[$key]) . '"';
        }
        return $attrs;
    }

    /**
     * Build ARIA error attributes for accessible validation
     * @param array $data Prepared data array
     * @return string ARIA HTML attribute string
     */
    protected function buildErrorAttributes($data) {
        if (empty($data['has_errors'])) return '';
        return ' aria-invalid="true" aria-describedby="' . htmlspecialchars($data['name']) . '_error"';
    }

    /**
     * Build the inline AJAX search-select script for dropdown fields.
     * Shared by all theme renderers to avoid duplicating ~100 lines of JS.
     *
     * @param string $id The select element's HTML id
     * @param string $endpoint The AJAX search endpoint URL
     * @return string Script HTML block
     */
    protected function buildAjaxSelectScript($id, $endpoint) {
        return '<script>
(function() {
  class AjaxSearchSelect {
    constructor(selectEl, ajaxUrl) {
      this.select = selectEl;
      this.ajaxUrl = ajaxUrl;
      this.cache = {};
      this.debounceTimer = null;

      const input = document.createElement(\'input\');
      input.type = \'text\';
      input.className = selectEl.className;
      input.placeholder = \'Type to search...\';

      const list = document.createElement(\'datalist\');
      list.id = selectEl.id + \'_list\';
      input.setAttribute(\'list\', list.id);

      selectEl.style.display = \'none\';
      selectEl.parentNode.insertBefore(input, selectEl);
      selectEl.parentNode.insertBefore(list, selectEl);

      this.input = input;
      this.list = list;
      this.data = [];

      if (selectEl.value) {
        input.value = selectEl.options[selectEl.selectedIndex].text;
      }

      input.addEventListener(\'input\', (e) => this.search(e.target.value));
      input.addEventListener(\'change\', (e) => {
        const inputVal = e.target.value.trim();
        if (!inputVal) {
          selectEl.value = \'\';
        } else {
          const matching = this.data.find(item => item.text === inputVal);
          if (matching) {
            let option = selectEl.querySelector(\'option[value="\' + matching.id + \'"]\');
            if (!option) {
              option = document.createElement(\'option\');
              option.value = matching.id;
              option.textContent = matching.text;
              selectEl.innerHTML = \'\';
              selectEl.appendChild(option);
            }
            selectEl.value = matching.id;
          }
        }
        selectEl.dispatchEvent(new Event(\'change\', { bubbles: true }));
      });
    }

    search(query) {
      clearTimeout(this.debounceTimer);
      if (query.length < 3) {
        this.list.innerHTML = \'\';
        this.data = [];
        return;
      }

      if (this.cache[query]) {
        this.updateList(this.cache[query]);
        return;
      }

      this.debounceTimer = setTimeout(() => {
        const separator = this.ajaxUrl.includes(\'?\') ? \'&\' : \'?\';
        fetch(this.ajaxUrl + separator + \'q=\' + encodeURIComponent(query))
          .then(r => r.json())
          .then(data => {
            this.cache[query] = data;
            this.updateList(data);
          });
      }, 250);
    }

    updateList(data) {
      this.data = data;
      this.list.innerHTML = \'\';
      data.forEach(item => {
        const opt = document.createElement(\'option\');
        opt.value = item.text;
        opt.dataset.id = item.id;
        this.list.appendChild(opt);
      });
    }
  }

  document.addEventListener(\'DOMContentLoaded\', () => {
    const select = document.getElementById(\'' . htmlspecialchars($id) . '\');
    if (select) {
      new AjaxSearchSelect(select, \'' . htmlspecialchars($endpoint) . '\');
    }
  });
})();
</script>';
    }

    // ── Prepare methods (behavioral logic) ───────────────────────────────────

    protected function prepareTextData($name, $label, $options) {
        $value = $options['value'] ?? ($this->values[$name] ?? '');
        $raw_placeholder = $options['placeholder'] ?? '';
        $placeholder = ($raw_placeholder && !$value) ? $raw_placeholder : '';
        return [
            'name' => $name, 'label' => $label,
            'id' => $options['id'] ?? $name,
            'value' => $value,
            'type' => $options['type'] ?? 'text',
            'placeholder' => $placeholder,
            'class' => $options['class'] ?? '',
            'prepend' => $options['prepend'] ?? '',
            'readonly' => !empty($options['readonly']),
            'disabled' => !empty($options['disabled']),
            'autofocus' => !empty($options['autofocus']),
            'required' => !empty($options['required']),
            'autocomplete' => $options['autocomplete'] ?? '',
            'onchange' => $options['onchange'] ?? '',
            'pattern' => $options['pattern'] ?? '',
            'min' => $options['min'] ?? null,
            'max' => $options['max'] ?? null,
            'step' => $options['step'] ?? null,
            'minlength' => $options['minlength'] ?? null,
            'maxlength' => $options['maxlength'] ?? null,
            'has_errors' => isset($this->errors[$name]),
            'errors' => $this->errors[$name] ?? [],
            'helptext' => $options['helptext'] ?? '',
        ];
    }

    protected function preparePasswordData($name, $label, $options) {
        $data = $this->prepareTextData($name, $label, $options);
        $data['type'] = 'password';
        $data['strength_meter'] = !empty($options['strength_meter']);
        return $data;
    }

    protected function prepareNumberData($name, $label, $options) {
        $data = $this->prepareTextData($name, $label, $options);
        $data['type'] = 'number';
        return $data;
    }

    protected function prepareDropData($name, $label, $options) {
        $raw_value = $options['value'] ?? ($this->values[$name] ?? '');
        $value = is_bool($raw_value) ? ($raw_value ? 1 : 0) : $raw_value;
        $raw_empty = $options['empty_option'] ?? false;
        if ($raw_empty === true) $empty_option = 'Select...';
        elseif ($raw_empty) $empty_option = $raw_empty;
        else $empty_option = null;
        return [
            'name' => $name, 'label' => $label,
            'id' => $options['id'] ?? $name,
            'value' => $value,
            'options_list' => $options['options'] ?? [],
            'empty_option' => $empty_option,
            'class' => $options['class'] ?? '',
            'multiple' => !empty($options['multiple']),
            'disabled' => !empty($options['disabled']),
            'required' => !empty($options['required']),
            'onchange' => $options['onchange'] ?? '',
            'ajaxendpoint' => $options['ajaxendpoint'] ?? '',
            'has_errors' => isset($this->errors[$name]),
            'errors' => $this->errors[$name] ?? [],
            'helptext' => $options['helptext'] ?? '',
            'visibility_rules' => $options['visibility_rules'] ?? null,
            'custom_script' => $options['custom_script'] ?? null,
        ];
    }

    protected function prepareCheckboxData($name, $label, $options) {
        $checked_value = $options['checked_value'] ?? '1';
        if (array_key_exists('checked', $options)) {
            $is_checked = !empty($options['checked']);
        } else {
            $current_value = $options['value'] ?? ($this->values[$name] ?? '');
            $is_checked = ((string)$current_value === (string)$checked_value);
        }
        return [
            'name' => $name, 'label' => $label,
            'id' => $options['id'] ?? $name,
            'checked_value' => $checked_value,
            'is_checked' => $is_checked,
            'class' => $options['class'] ?? '',
            'disabled' => !empty($options['disabled']),
            'required' => !empty($options['required']),
            'onchange' => $options['onchange'] ?? '',
            'has_errors' => isset($this->errors[$name]),
            'errors' => $this->errors[$name] ?? [],
            'helptext' => $options['helptext'] ?? '',
            'visibility_rules' => $options['visibility_rules'] ?? null,
            'custom_script' => $options['custom_script'] ?? null,
        ];
    }

    protected function prepareRadioData($name, $label, $options) {
        return [
            'name' => $name, 'label' => $label,
            'value' => $options['value'] ?? ($this->values[$name] ?? ''),
            'options_list' => $options['options'] ?? [],
            'class' => $options['class'] ?? '',
            'disabled' => !empty($options['disabled']),
            'required' => !empty($options['required']),
            'onchange' => $options['onchange'] ?? '',
            'has_errors' => isset($this->errors[$name]),
            'errors' => $this->errors[$name] ?? [],
            'helptext' => $options['helptext'] ?? '',
        ];
    }

    protected function prepareDateData($name, $label, $options) {
        $raw_value = $options['value'] ?? ($this->values[$name] ?? '');
        if ($raw_value && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw_value)) {
            try { $raw_value = (new DateTime($raw_value))->format('Y-m-d'); } catch (Exception $e) {}
        }
        return [
            'name' => $name, 'label' => $label,
            'id' => $options['id'] ?? $name,
            'value' => $raw_value,
            'class' => $options['class'] ?? '',
            'min' => $options['min'] ?? null,
            'max' => $options['max'] ?? null,
            'readonly' => !empty($options['readonly']),
            'disabled' => !empty($options['disabled']),
            'required' => !empty($options['required']),
            'onchange' => $options['onchange'] ?? '',
            'has_errors' => isset($this->errors[$name]),
            'errors' => $this->errors[$name] ?? [],
            'helptext' => $options['helptext'] ?? '',
        ];
    }

    protected function prepareTimeData($name, $label, $options) {
        $value = $options['value'] ?? ($this->values[$name] ?? '');
        $parsed = $this->parseTimeValue($value);
        return [
            'name' => $name, 'label' => $label,
            'id' => $options['id'] ?? $name,
            'value' => $value,
            'hour' => $parsed['hour'],
            'minute' => $parsed['minute'],
            'ampm' => $parsed['ampm'],
            'class' => $options['class'] ?? '',
            'readonly' => !empty($options['readonly']),
            'disabled' => !empty($options['disabled']),
            'has_errors' => isset($this->errors[$name]),
            'errors' => $this->errors[$name] ?? [],
            'helptext' => $options['helptext'] ?? '',
        ];
    }

    protected function prepareDateTimeData($name, $label, $options) {
        $value = $options['value'] ?? ($this->values[$name] ?? '');
        $date_value = $options['date_value'] ?? '';
        $time_value = $options['time_value'] ?? '';
        if (!$date_value && !$time_value && $value) {
            if (strpos($value, ' ') !== false) {
                list($date_value, $time_value) = explode(' ', $value, 2);
            } else {
                $date_value = $value;
            }
        }
        $parsed = $this->parseTimeValue($time_value);
        return [
            'name' => $name, 'label' => $label,
            'date_name' => $name . '_dateinput',
            'time_name' => $name . '_timeinput',
            'date_value' => $date_value,
            'time_value' => $time_value,
            'hour' => $parsed['hour'],
            'minute' => $parsed['minute'],
            'ampm' => $parsed['ampm'],
            'class' => $options['class'] ?? '',
            'readonly' => !empty($options['readonly']),
            'disabled' => !empty($options['disabled']),
            'date_errors' => $this->errors[$name . '_dateinput'] ?? [],
            'time_errors' => $this->errors[$name . '_timeinput'] ?? [],
            'helptext' => $options['helptext'] ?? '',
        ];
    }

    protected function prepareFileData($name, $label, $options) {
        return [
            'name' => $name, 'label' => $label,
            'id' => $options['id'] ?? $name,
            'class' => $options['class'] ?? '',
            'accept' => $options['accept'] ?? '',
            'multiple' => !empty($options['multiple']),
            'disabled' => !empty($options['disabled']),
            'required' => !empty($options['required']),
            'onchange' => $options['onchange'] ?? '',
            'has_errors' => isset($this->errors[$name]),
            'errors' => $this->errors[$name] ?? [],
            'helptext' => $options['helptext'] ?? '',
        ];
    }

    protected function prepareHiddenData($name, $label, $options) {
        return [
            'name' => $name,
            'id' => $options['id'] ?? $name,
            'value' => $options['value'] ?? ($this->values[$name] ?? ''),
        ];
    }

    protected function prepareSubmitData($name, $label, $options) {
        return [
            'name' => $name, 'label' => $label,
            'id' => $options['id'] ?? $name,
            'class' => $options['class'] ?? '',
            'disabled' => !empty($options['disabled']),
            'onclick' => $options['onclick'] ?? '',
        ];
    }

    protected function prepareTextareaData($name, $label, $options) {
        $value = $options['value'] ?? ($this->values[$name] ?? '');
        $raw_placeholder = $options['placeholder'] ?? '';
        $placeholder = ($raw_placeholder && !$value) ? $raw_placeholder : '';
        return [
            'name' => $name, 'label' => $label,
            'id' => $options['id'] ?? $name,
            'value' => $value,
            'placeholder' => $placeholder,
            'class' => $options['class'] ?? '',
            'rows' => $options['rows'] ?? 5,
            'cols' => $options['cols'] ?? 80,
            'readonly' => !empty($options['readonly']),
            'disabled' => !empty($options['disabled']),
            'required' => !empty($options['required']),
            'minlength' => $options['minlength'] ?? null,
            'maxlength' => $options['maxlength'] ?? null,
            'onchange' => $options['onchange'] ?? '',
            'has_errors' => isset($this->errors[$name]),
            'errors' => $this->errors[$name] ?? [],
            'helptext' => $options['helptext'] ?? '',
        ];
    }

    protected function prepareCheckboxListData($name, $label, $options) {
        $options_list = $options['options'] ?? [];
        $checked_raw = isset($options['checked']) ? $options['checked'] : ($options['value'] ?? ($this->values[$name] ?? []));
        $checked = is_array($checked_raw) ? $checked_raw : (array)$checked_raw;
        $type = $options['type'] ?? 'checkbox';
        if ($type !== 'checkbox' && $type !== 'radio') {
            throw new DisplayableUserException('checkboxList type must be "checkbox" or "radio"');
        }
        if ($type === 'radio' && count($checked) > 1) {
            throw new DisplayableUserException('Radio checkboxList cannot have more than one checked value');
        }
        if ($type === 'radio' && !empty($options['readonly'])) {
            throw new DisplayableUserException('Radio checkboxList does not support readonly');
        }
        return [
            'name' => $name, 'label' => $label,
            'id' => $options['id'] ?? $name,
            'options_list' => $options_list,
            'checked' => $checked,
            'disabled' => $options['disabled'] ?? [],
            'readonly' => $options['readonly'] ?? [],
            'type' => $type,
            'has_errors' => isset($this->errors[$name]),
            'errors' => $this->errors[$name] ?? [],
            'helptext' => $options['helptext'] ?? '',
        ];
    }

    protected function prepareTextboxData($name, $label, $options) {
        return [
            'name' => $name, 'label' => $label,
            'id' => $options['id'] ?? $name,
            'value' => $options['value'] ?? ($this->values[$name] ?? ''),
            'class' => $options['class'] ?? '',
            'rows' => $options['rows'] ?? 10,
            'htmlmode' => !empty($options['htmlmode']),
            'readonly' => !empty($options['readonly']),
            'disabled' => !empty($options['disabled']),
            'has_errors' => isset($this->errors[$name]),
            'errors' => $this->errors[$name] ?? [],
            'helptext' => $options['helptext'] ?? '',
        ];
    }

    protected function prepareImageData($name, $label, $options) {
        return [
            'name' => $name, 'label' => $label,
            'id' => $options['id'] ?? $name,
            'value' => $options['value'] ?? ($this->values[$name] ?? ''),
            'images' => $options['options'] ?? $options['images'] ?? [],
            'preview_size' => $options['preview_size'] ?? '100px',
            'class' => $options['class'] ?? '',
            'disabled' => !empty($options['disabled']),
            'has_errors' => isset($this->errors[$name]),
            'errors' => $this->errors[$name] ?? [],
            'helptext' => $options['helptext'] ?? '',
        ];
    }

    // ── Concrete output*() methods (call prepare*Data + render*) ─────────────

    protected function outputTextInput($name, $label, $options) {
        $data = $this->prepareTextData($name, $label, $options);
        $html = $this->renderTextInput($data);
        $this->handleOutput($name, $html);
    }

    protected function outputPasswordInput($name, $label, $options) {
        $data = $this->preparePasswordData($name, $label, $options);
        $html = $this->renderPasswordInput($data);
        $this->handleOutput($name, $html);
    }

    protected function outputNumberInput($name, $label, $options) {
        $data = $this->prepareNumberData($name, $label, $options);
        $html = $this->renderNumberInput($data);
        $this->handleOutput($name, $html);
    }

    protected function outputDropInput($name, $label, $options) {
        $data = $this->prepareDropData($name, $label, $options);
        $html = $this->renderDropInput($data);
        if (!empty($data['visibility_rules'])) {
            $html .= $this->generateVisibilityScript($data['name'], $data['id'], $data['visibility_rules']);
        } elseif (!empty($data['custom_script'])) {
            $html .= $this->generateFieldScript($data['id'], $data['custom_script']);
        }
        $this->handleOutput($name, $html);
    }

    protected function outputCheckboxInput($name, $label, $options) {
        $data = $this->prepareCheckboxData($name, $label, $options);
        $html = $this->renderCheckboxInput($data);
        if (!empty($data['visibility_rules'])) {
            $html .= $this->generateVisibilityScript($data['name'], $data['id'], $data['visibility_rules']);
        } elseif (!empty($data['custom_script'])) {
            $html .= $this->generateFieldScript($data['id'], $data['custom_script']);
        }
        $this->handleOutput($name, $html);
    }

    protected function outputRadioInput($name, $label, $options) {
        $data = $this->prepareRadioData($name, $label, $options);
        $html = $this->renderRadioInput($data);
        $this->handleOutput($name, $html);
    }

    protected function outputCheckboxList($name, $label, $options) {
        $data = $this->prepareCheckboxListData($name, $label, $options);
        $html = $this->renderCheckboxList($data);
        $this->handleOutput($name, $html);
    }

    protected function outputDateInput($name, $label, $options) {
        $data = $this->prepareDateData($name, $label, $options);
        $html = $this->renderDateInput($data);
        $this->handleOutput($name, $html);
    }

    protected function outputTimeInput($name, $label, $options) {
        $data = $this->prepareTimeData($name, $label, $options);
        $html = $this->renderTimeInput($data);
        $this->handleOutput($name, $html);
    }

    protected function outputDateTimeInput($name, $label, $options) {
        $data = $this->prepareDateTimeData($name, $label, $options);
        $html = $this->renderDateTimeInput($data);
        $this->handleOutput($name, $html);
    }

    protected function outputFileInput($name, $label, $options) {
        $data = $this->prepareFileData($name, $label, $options);
        $html = $this->renderFileInput($data);
        $this->handleOutput($name, $html);
    }

    protected function outputHiddenInput($name, $options) {
        $data = $this->prepareHiddenData($name, '', $options);
        $html = $this->renderHiddenInput($data);
        $this->handleOutput($name, $html);
    }

    protected function outputSubmitButton($name, $label, $options) {
        $data = $this->prepareSubmitData($name, $label, $options);
        $html = $this->renderSubmitButton($data);
        $this->handleOutput($name, $html);
    }

    protected function outputTextbox($name, $label, $options) {
        $data = $this->prepareTextboxData($name, $label, $options);
        $html = $this->renderTextbox($data);
        $this->handleOutput($name, $html);
    }

    protected function outputTextarea($name, $label, $options) {
        $data = $this->prepareTextareaData($name, $label, $options);
        $html = $this->renderTextarea($data);
        $this->handleOutput($name, $html);
    }

    protected function outputImageInput($name, $label, $options) {
        $data = $this->prepareImageData($name, $label, $options);
        $html = $this->renderImageInput($data);
        $this->handleOutput($name, $html);
    }

    // ── Abstract render*() methods (theme-specific HTML generation) ───────────

    abstract protected function renderTextInput($data);
    abstract protected function renderPasswordInput($data);
    abstract protected function renderNumberInput($data);
    abstract protected function renderDropInput($data);
    abstract protected function renderCheckboxInput($data);
    abstract protected function renderRadioInput($data);
    abstract protected function renderCheckboxList($data);
    abstract protected function renderDateInput($data);
    abstract protected function renderTimeInput($data);
    abstract protected function renderDateTimeInput($data);
    abstract protected function renderFileInput($data);
    abstract protected function renderHiddenInput($data);
    abstract protected function renderSubmitButton($data);
    abstract protected function renderTextbox($data);
    abstract protected function renderTextarea($data);
    abstract protected function renderImageInput($data);

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
        $output .= '    const rules = visibilityRules' . $varName . '[selected] || visibilityRules' . $varName . '["default"] || {};' . "\n";
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

        // Convert hour, minute, ampm directly to 24h format
        $hour = intval($post_vars[$hour_field]);
        $minute = str_pad($post_vars[$minute_field], 2, '0', STR_PAD_LEFT);
        $ampm = strtolower($post_vars[$ampm_field]);
        $hour24 = ($ampm === 'pm') ? ($hour == 12 ? 12 : $hour + 12) : ($hour == 12 ? 0 : $hour);
        $time_db = sprintf('%02d:%s:00', $hour24, $minute);

        // Combine date and time
        $time_combined = $post_vars[$date_field] . ' ' . $time_db;

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
            <div style="margin-top: 10px;">
                <label class="button primary" style="cursor: pointer; display: inline-block; position: relative; overflow: hidden;">
                    📁 Browse Files
                    <input type="file" id="file-input" multiple accept="<?php echo $accept_attr; ?>" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer;">
                </label>
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
        (function() {
            'use strict';

            function initFileUploader() {
            let selectedFiles = [];

            // Get allowed file extensions from server setting
            const allowedExtensions = '<?php echo $allowed_extensions; ?>';
            const allowedTypes = new RegExp('\\.(' + allowedExtensions.replace(/,/g, '|') + ')$', 'i');
            const maxFileSize = <?php echo $max_size; ?>; // Maximum file size in bytes

            // DOM elements
            const dropZone = document.getElementById('file-drop-zone');
            const fileInput = document.getElementById('file-input');
            const browseBtn = document.getElementById('browse-btn');
            const uploadAllBtn = document.getElementById('upload-all-btn');
            const clearAllBtn = document.getElementById('clear-all-btn');
            const filesList = document.getElementById('files-list');
            const noFilesMessage = document.getElementById('no-files-message');
            const overallProgress = document.getElementById('overall-progress');
            const progressBar = document.getElementById('overall-progress-bar');

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
                noFilesMessage.style.display = 'none';

                const fileIcon = getFileIcon(fileObj.file.name);
                const rowHTML = `
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
                `;

                filesList.insertAdjacentHTML('beforeend', rowHTML);
            }

            // Update UI state
            function updateUI() {
                const hasFiles = selectedFiles.length > 0;
                const pendingFilesCount = selectedFiles.filter(f => f.status === 'pending').length;

                uploadAllBtn.disabled = pendingFilesCount === 0;
                clearAllBtn.disabled = !hasFiles;

                if (!hasFiles) {
                    noFilesMessage.style.display = '';
                }

                // Update button text with count
                if (pendingFilesCount > 0) {
                    uploadAllBtn.innerHTML = `⬆️ Upload All (${pendingFilesCount})`;
                } else {
                    uploadAllBtn.innerHTML = '⬆️ Upload All';
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
                    const hiddenForm = document.getElementById('hidden-form-data');
                    if (hiddenForm) {
                        hiddenForm.querySelectorAll('input').forEach(function(input) {
                            formData.append(input.name, input.value);
                        });
                    }

                    const row = document.querySelector(`.file-row[data-file-id="${fileObj.id}"]`);
                    const statusCell = row.querySelector('.file-status');
                    const actionsCell = row.querySelector('.file-actions');

                    // Update UI to uploading state
                    statusCell.innerHTML = '<span style="padding: 2px 8px; background: #007bff; color: white; border-radius: 3px; font-size: 12px;">Uploading...</span>';
                    actionsCell.innerHTML = `
                        <div style="display: flex; align-items: center;">
                            <progress value="0" max="100" style="width: 60px; height: 20px; margin-right: 8px;">0%</progress>
                            <span style="color: #666; font-size: 12px;">0%</span>
                        </div>
                    `;

                    // Create XMLHttpRequest for progress tracking
                    const xhr = new XMLHttpRequest();

                    xhr.upload.addEventListener('progress', function(e) {
                        if (e.lengthComputable) {
                            const progress = Math.round((e.loaded / e.total) * 100);
                            const progressEl = actionsCell.querySelector('progress');
                            const spanEl = actionsCell.querySelector('span');
                            if (progressEl) {
                                progressEl.value = progress;
                                progressEl.textContent = progress + '%';
                            }
                            if (spanEl) {
                                spanEl.textContent = progress + '%';
                            }
                            statusCell.innerHTML = `<span style="padding: 2px 8px; background: #007bff; color: white; border-radius: 3px; font-size: 12px;">Uploading ${progress}%</span>`;
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
                                        statusCell.innerHTML = '<span style="padding: 2px 8px; background: #28a745; color: white; border-radius: 3px; font-size: 12px;">✓ Upload successful</span>';
                                        actionsCell.innerHTML = `
                                            <a href="${file.url}" target="_blank" class="button small success" title="Download file" style="padding: 4px 8px; font-size: 12px; text-decoration: none;">
                                                ⬇️
                                            </a>
                                            <button type="button" class="button small danger remove-file-btn" title="Remove from list" style="padding: 4px 8px; font-size: 12px; margin-left: 5px;">
                                                ❌
                                            </button>
                                        `;

                                        // Make filename clickable if we have a file ID
                                        console.log('Checking for file_id:', file.file_id); // Debug log
                                        if (file.file_id) {
                                            const nameElement = row.querySelector('.file-name');
                                            const fileName = nameElement.textContent;
                                            const fileIcon = getFileIcon(fileName);

                                            console.log('Making filename clickable:', fileName, 'with ID:', file.file_id); // Debug log

                                            nameElement.parentElement.innerHTML = `
                                                <div style="display: flex; align-items: center;">
                                                    <span style="margin-right: 8px; font-size: 20px;">${fileIcon}</span>
                                                    <a href="/admin/admin_file?fil_file_id=${file.file_id}" style="color: #0066cc; text-decoration: none;">${fileName}</a>
                                                </div>
                                            `;
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
                    const row = document.querySelector(`.file-row[data-file-id="${fileObj.id}"]`);
                    const statusCell = row.querySelector('.file-status');
                    const actionsCell = row.querySelector('.file-actions');

                    statusCell.innerHTML = `<span style="padding: 2px 8px; background: #dc3545; color: white; border-radius: 3px; font-size: 12px;">⚠️ Error</span>`;
                    actionsCell.innerHTML = `
                        <button type="button" class="button small upload-single-btn" title="Retry upload" style="padding: 4px 8px; font-size: 12px;">
                            🔄
                        </button>
                        <button type="button" class="button small danger remove-file-btn" title="Remove this file" style="padding: 4px 8px; font-size: 12px; margin-left: 5px;">
                            ❌
                        </button>
                    `;
                    fileObj.status = 'error';
                    showToast(`Upload failed: ${fileObj.file.name} - ${error.message}`, 'error');
                    throw error;
                });
            }

            // Event Handlers
            console.log('File uploader initialized, attaching event listeners...');
            // Note: Browse button is now a <label for="file-input"> which natively triggers the file input
            // No JavaScript handler needed for the browse button

            fileInput.addEventListener('change', function() {
                if (this.files.length > 0) {
                    addFiles(this.files);
                    this.value = ''; // Reset input
                }
            });

            // Drag and drop styling
            ['dragover', 'dragenter'].forEach(function(eventName) {
                dropZone.addEventListener(eventName, function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    this.style.borderColor = '#007bff';
                    this.style.backgroundColor = '#e7f3ff';
                });
            });

            dropZone.addEventListener('dragleave', function(e) {
                e.preventDefault();
                e.stopPropagation();
                this.style.borderColor = '#ccc';
                this.style.backgroundColor = '#f9f9f9';
            });

            dropZone.addEventListener('drop', function(e) {
                e.preventDefault();
                e.stopPropagation();
                this.style.borderColor = '#ccc';
                this.style.backgroundColor = '#f9f9f9';

                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    addFiles(files);
                }
            });

            // Upload all files
            uploadAllBtn.addEventListener('click', async function() {
                const pendingFiles = selectedFiles.filter(f => f.status === 'pending');
                if (pendingFiles.length === 0) return;

                overallProgress.style.display = 'flex';
                uploadAllBtn.disabled = true;

                let completed = 0;
                const total = pendingFiles.length;

                for (const fileObj of pendingFiles) {
                    try {
                        await uploadFile(fileObj);
                        completed++;
                        const progress = Math.round((completed / total) * 100);
                        progressBar.value = progress;
                    } catch (error) {
                        console.error('Upload failed:', error);
                        completed++; // Count errors as completed for progress
                        const progress = Math.round((completed / total) * 100);
                        progressBar.value = progress;
                    }
                }

                setTimeout(function() {
                    overallProgress.style.display = 'none';
                    progressBar.value = 0;
                    updateUI();
                }, 1000);
            });

            // Clear all files
            clearAllBtn.addEventListener('click', function() {
                if (confirm('Are you sure you want to clear all files?')) {
                    selectedFiles = [];
                    filesList.querySelectorAll('.file-row').forEach(function(row) {
                        row.remove();
                    });
                    updateUI();
                }
            });

            // Event delegation for dynamic buttons
            filesList.addEventListener('click', function(e) {
                const uploadBtn = e.target.closest('.upload-single-btn');
                const removeBtn = e.target.closest('.remove-file-btn');

                if (uploadBtn) {
                    const row = uploadBtn.closest('.file-row');
                    const fileId = row.dataset.fileId;
                    const fileObj = selectedFiles.find(f => f.id === fileId);
                    if (fileObj && (fileObj.status === 'pending' || fileObj.status === 'error')) {
                        uploadFile(fileObj).then(function() {
                            updateUI();
                        }).catch(function() {
                            updateUI();
                        });
                    }
                }

                if (removeBtn) {
                    const row = removeBtn.closest('.file-row');
                    const fileId = row.dataset.fileId;

                    // Remove from array
                    selectedFiles = selectedFiles.filter(f => f.id !== fileId);

                    // Remove from DOM with fade animation
                    row.style.transition = 'opacity 0.3s';
                    row.style.opacity = '0';
                    setTimeout(function() {
                        row.remove();
                        updateUI();
                    }, 300);
                }
            });

            // Click to browse anywhere in drop zone (except on the file input label area)
            dropZone.addEventListener('click', function(e) {
                // Don't interfere with the file input or its label container
                if (e.target.closest('label') || e.target.closest('button') || e.target.id === 'file-input') {
                    return; // Let native behavior handle it
                }
                // For clicks on the drop zone itself or text elements, trigger file input
                fileInput.click();
            });
            } // end initFileUploader

            // Run immediately if DOM is already ready, otherwise wait for DOMContentLoaded
            console.log('File uploader script loaded, readyState:', document.readyState);
            if (document.readyState === 'loading') {
                console.log('DOM still loading, waiting for DOMContentLoaded...');
                document.addEventListener('DOMContentLoaded', initFileUploader);
            } else {
                console.log('DOM already ready, initializing immediately...');
                initFileUploader();
            }
        })();
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
        $correct_answer = $settings->get_setting('anti_spam_answer');

        if($correct_answer){
            $output = $this->textinput("antispam_question", "Type '".strtolower($correct_answer)."' into this field (to prove you are human)", [
                'required' => true,
                'validation' => [
                    'required' => true,
                    'matches' => 'antispam_question_answer',
                    'messages' => [
                        'required' => 'This field is required.',
                        'matches' => 'You must type the correct word here',
                    ],
                ],
            ]);
            $output .= $this->hiddeninput("antispam_question_answer", '', ['value' => strtolower($correct_answer)]);
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
        $correct_answer = $settings->get_setting('anti_spam_answer');

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
        $correct_answer = $settings->get_setting('anti_spam_answer');

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
     * Output a honeypot hidden input field
     * Honeypot fields are invisible to humans but filled in by bots.
     * If the field has a value on submission, the form was submitted by a bot.
     *
     * @param string $label Optional label for the hidden field name
     * @param string $type Optional type prefix for the field name
     * @return string|void HTML output
     */
    public function honeypot_hidden_input($label = '', $type = ''){
        $settings = Globalvars::get_instance();
        if(!$settings->get_setting('use_honeypot')){
            return '';
        }
        $field_name = 'website_url';
        if($type){
            $field_name = $type . '_website_url';
        }
        $output = '<div style="position:absolute;left:-9999px;"><label for="' . htmlspecialchars($field_name) . '">Leave this field empty</label>';
        $output .= '<input type="text" name="' . htmlspecialchars($field_name) . '" id="' . htmlspecialchars($field_name) . '" value="" tabindex="-1" autocomplete="off">';
        $output .= '</div>';
        echo $output;
    }

    /**
     * Check if the honeypot field was filled in (indicating a bot)
     *
     * @param array $data POST data to check
     * @param string $type Optional type prefix for the field name
     * @return boolean True if the check passes (not a bot), false if honeypot was filled
     */
    public function honeypot_check($data, $type = ''){
        $settings = Globalvars::get_instance();
        if(!$settings->get_setting('use_honeypot')){
            return true;
        }
        $field_name = 'website_url';
        if($type){
            $field_name = $type . '_website_url';
        }
        if(isset($data[$field_name]) && $data[$field_name] != ''){
            return false;
        }
        return true;
    }

    /**
     * Verify a CAPTCHA response (hCaptcha or Google reCAPTCHA)
     *
     * @param array $data POST data containing captcha response
     * @param string $type Optional type (e.g., 'blog' to check use_captcha_comments setting)
     * @return boolean True if CAPTCHA verification passes or captcha is disabled
     */
    public function captcha_check($data, $type = NULL) {
        $settings = Globalvars::get_instance();

        if ($type == 'blog') {
            $use_captcha = $settings->get_setting('use_captcha_comments');
        } else {
            $use_captcha = $settings->get_setting('use_captcha');
        }

        if (!$use_captcha) {
            return true;
        }

        if ($settings->get_setting('hcaptcha_public') && $settings->get_setting('hcaptcha_private')) {
            $captcha_response = $data['h-captcha-response'] ?? '';
            if (empty($captcha_response)) {
                return false;
            }

            $verify = curl_init();
            curl_setopt($verify, CURLOPT_URL, "https://hcaptcha.com/siteverify");
            curl_setopt($verify, CURLOPT_POST, true);
            curl_setopt($verify, CURLOPT_POSTFIELDS, http_build_query([
                'secret' => $settings->get_setting('hcaptcha_private'),
                'response' => $captcha_response,
            ]));
            curl_setopt($verify, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($verify);
            curl_close($verify);
            $responseData = json_decode($response);
            return !empty($responseData->success);
        } elseif ($settings->get_setting('captcha_public') && $settings->get_setting('captcha_private')) {
            $captcha_response = $data['g-recaptcha-response'] ?? '';
            if (empty($captcha_response)) {
                return false;
            }

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://www.google.com/recaptcha/api/siteverify");
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, [
                'secret' => $settings->get_setting('captcha_private'),
                'response' => $captcha_response,
                'remoteip' => $_SERVER['REMOTE_ADDR'],
            ]);
            $resp = json_decode(curl_exec($ch));
            curl_close($ch);
            return !empty($resp->success);
        }

        // No captcha provider configured
        return true;
    }

    /**
     * Output a CAPTCHA widget (hCaptcha or Google reCAPTCHA)
     *
     * @param string $type Optional type (e.g., 'blog' to check use_captcha_comments setting)
     * @return string|void HTML output
     */
    public function captcha_hidden_input($type = NULL){
        $settings = Globalvars::get_instance();

        // Check if captcha is enabled for this context
        if($type == 'blog'){
            if(!$settings->get_setting('use_captcha_comments')){
                return '';
            }
        } else {
            if(!$settings->get_setting('use_captcha')){
                return '';
            }
        }

        // Prefer hCaptcha, fall back to Google reCAPTCHA
        if($settings->get_setting('hcaptcha_public') && $settings->get_setting('hcaptcha_private')){
            $output = "<script src='https://www.hCaptcha.com/1/api.js' async defer></script>";
            $output .= '<div class="h-captcha" data-sitekey="' . htmlspecialchars($settings->get_setting('hcaptcha_public')) . '"></div>';
            echo $output;
        } else if($settings->get_setting('captcha_public') && $settings->get_setting('captcha_private')){
            $output = '<script src="https://www.google.com/recaptcha/api.js" async defer></script>';
            $output .= '<div class="g-recaptcha" data-sitekey="' . htmlspecialchars($settings->get_setting('captcha_public')) . '"></div>';
            echo $output;
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
        $item_label = $options['item_label'] ?? null;
        $min = isset($options['min']) ? intval($options['min']) : null;
        $max = isset($options['max']) ? intval($options['max']) : null;

        // Ensure items is an array
        if (!is_array($items)) {
            $items = [];
        }

        // Pre-populate minimum rows for new instances with no data
        if (empty($items) && $min !== null && $min > 0) {
            for ($i = 0; $i < $min; $i++) {
                $items[] = [];
            }
        }

        // Repeater container - data-name used by JavaScript for targeting
        echo '<div class="repeater mb-4" data-name="' . htmlspecialchars($name) . '"';
        if ($min !== null) echo ' data-min="' . $min . '"';
        if ($max !== null) echo ' data-max="' . $max . '"';
        echo '>';
        echo '<label class="form-label fw-bold">' . htmlspecialchars($label) . '</label>';

        // Help text if provided
        if (!empty($options['helptext'])) {
            echo '<div class="form-text text-muted mb-2">' . htmlspecialchars($options['helptext']) . '</div>';
        }

        echo '<div class="repeater-items">';

        // Render existing rows from saved data
        foreach ($items as $index => $item) {
            $this->repeater_row($name, $index, $subfields, $item, $item_label);
        }

        echo '</div>';

        // Add button - JavaScript attaches click handler
        echo '<button type="button" class="repeater-add btn btn-secondary btn-sm mt-2">';
        echo htmlspecialchars($add_label);
        echo '</button>';

        // Hidden template for JavaScript cloning - __INDEX__ replaced with actual index
        echo '<template class="repeater-template">';
        $this->repeater_row($name, '__INDEX__', $subfields, [], $item_label);
        echo '</template>';

        echo '</div>';

        // Output repeater JavaScript (once per page)
        $this->outputRepeaterJavaScript();
    }

    /**
     * Output shared JavaScript for repeater field functionality
     * Handles add/remove row operations via event delegation
     */
    protected function outputRepeaterJavaScript() {
        static $repeater_js_loaded = false;
        if (!$repeater_js_loaded) {
            echo '<script type="text/javascript">
document.addEventListener("DOMContentLoaded", function() {
    // Update button disabled states and row numbering
    function updateRepeaterState(repeater) {
        var items = repeater.querySelector(".repeater-items");
        var count = items.querySelectorAll(".repeater-row").length;
        var min = repeater.dataset.min ? parseInt(repeater.dataset.min) : null;
        var max = repeater.dataset.max ? parseInt(repeater.dataset.max) : null;

        // Disable add button at max
        var addBtn = repeater.querySelector(".repeater-add");
        if (addBtn) {
            addBtn.disabled = (max !== null && count >= max);
        }

        // Disable remove buttons at min
        var removeBtns = items.querySelectorAll(".repeater-remove");
        removeBtns.forEach(function(btn) {
            btn.disabled = (min !== null && count <= min);
        });

        // Re-number row labels (if item_label is used)
        var labels = items.querySelectorAll(".repeater-row-number");
        labels.forEach(function(label, i) {
            label.textContent = " " + (i + 1);
        });
    }

    // Add row - with max enforcement
    document.addEventListener("click", function(e) {
        if (e.target.classList.contains("repeater-add")) {
            var repeater = e.target.closest(".repeater");
            var items = repeater.querySelector(".repeater-items");
            var max = repeater.dataset.max;
            var currentCount = items.querySelectorAll(".repeater-row").length;

            if (max && currentCount >= parseInt(max)) {
                return;
            }

            var template = repeater.querySelector(".repeater-template");
            var nextIndex = currentCount;
            var clone = template.content.cloneNode(true);
            var row = clone.querySelector(".repeater-row");
            var html = row.outerHTML.replace(/__INDEX__/g, nextIndex);

            items.insertAdjacentHTML("beforeend", html);
            updateRepeaterState(repeater);
        }
    });

    // Remove row - with min enforcement
    document.addEventListener("click", function(e) {
        if (e.target.classList.contains("repeater-remove")) {
            var repeater = e.target.closest(".repeater");
            var items = repeater.querySelector(".repeater-items");
            var min = repeater.dataset.min;
            var currentCount = items.querySelectorAll(".repeater-row").length;

            if (min && currentCount <= parseInt(min)) {
                return;
            }

            e.target.closest(".repeater-row").remove();
            updateRepeaterState(repeater);
        }
    });

    // Initialize button states on page load for repeaters with min/max
    document.querySelectorAll(".repeater[data-min], .repeater[data-max]").forEach(updateRepeaterState);
});
</script>';
            $repeater_js_loaded = true;
        }
    }

    /**
     * Render a single row within a repeater field.
     *
     * Each row contains all sub-fields defined in the schema plus a remove button.
     * Called by repeater() for each existing item and once for the JS template.
     *
     * @param string      $name       Parent repeater field name
     * @param int|string  $index      Row index (integer for real rows, '__INDEX__' for template)
     * @param array       $subfields  Sub-field definitions from schema (type uses FormWriter method names)
     * @param array       $values     Current values for this row (empty for template)
     * @param string|null $item_label Optional label for row numbering (e.g., "Feature" → "Feature 1")
     * @return void
     *
     * @see Page Component System spec: /specs/page_component_system.md
     */
    protected function repeater_row($name, $index, $subfields, $values, $item_label = null) {
        // Separate regular and advanced fields
        $regular_fields = [];
        $advanced_fields = [];
        foreach ($subfields as $subfield) {
            if (!empty($subfield['advanced'])) {
                $advanced_fields[] = $subfield;
            } else {
                $regular_fields[] = $subfield;
            }
        }

        echo '<div class="repeater-row card card-body mb-2" data-index="' . htmlspecialchars($index) . '">';

        // Show item label if provided (e.g., "Feature 1", "Feature 2")
        if ($item_label) {
            $display_number = ($index === '__INDEX__') ? '' : ' ' . ($index + 1);
            echo '<div class="d-flex justify-content-between align-items-center mb-2">';
            echo '<small class="fw-semibold text-muted repeater-row-label">'
                . htmlspecialchars($item_label)
                . '<span class="repeater-row-number">' . $display_number . '</span>'
                . '</small>';
            echo '</div>';
        }

        echo '<div class="row align-items-end">';

        // Helper to render a field
        $render_subfield = function($subfield, $name, $index, $values, $col_class) {
            $field_name = $name . '[' . $index . '][' . $subfield['name'] . ']';
            $field_value = $values[$subfield['name']] ?? ($subfield['default'] ?? '');
            $method = $subfield['type'] ?? 'textinput';
            $subfield_label = $subfield['label'] ?? '';

            echo '<div class="' . $col_class . '">';

            // Build options for the sub-field
            $field_options = [
                'value' => $field_value,
                'model' => false,
                'validation' => false
            ];

            // Pass through common schema properties
            $passthrough_props = ['placeholder', 'required', 'min', 'max', 'step'];
            foreach ($passthrough_props as $prop) {
                if (isset($subfield[$prop])) {
                    $field_options[$prop] = $subfield[$prop];
                }
            }

            if (isset($subfield['helptext'])) {
                $field_options['helptext'] = $subfield['helptext'];
            }

            // Add required indicator to label
            if (!empty($subfield['required'])) {
                $subfield_label .= ' *';
            }

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
                $this->$method($field_name, $subfield_label, $field_options);
            } else {
                $this->textinput($field_name, $subfield_label, $field_options);
            }

            echo '</div>';
        };

        // Calculate column width based on number of regular fields
        $col_class = 'col-md';
        if (count($regular_fields) <= 2) {
            $col_class = 'col-md-5';
        } elseif (count($regular_fields) == 3) {
            $col_class = 'col-md-3';
        }

        // Render regular fields
        foreach ($regular_fields as $subfield) {
            $render_subfield($subfield, $name, $index, $values, $col_class);
        }

        // Remove button
        echo '<div class="col-auto">';
        echo '<button type="button" class="repeater-remove btn btn-outline-danger btn-sm mb-3">Remove</button>';
        echo '</div>';

        echo '</div>'; // end row

        // Advanced fields section (if any)
        if (!empty($advanced_fields)) {
            $advanced_id = 'repeater_advanced_' . $name . '_' . $index . '_' . uniqid();
            echo '<div class="repeater-advanced-section mt-2">';
            echo '<a href="#" class="repeater-advanced-toggle small text-muted" data-target="' . $advanced_id . '">';
            echo '<i class="fas fa-cog me-1"></i>Advanced (' . count($advanced_fields) . ')';
            echo '</a>';
            echo '<div id="' . $advanced_id . '" class="repeater-advanced-content" style="display:none;">';
            echo '<div class="row align-items-end mt-2 pt-2 border-top">';

            // Calculate column width for advanced fields
            $adv_col_class = 'col-md';
            if (count($advanced_fields) <= 2) {
                $adv_col_class = 'col-md-5';
            } elseif (count($advanced_fields) == 3) {
                $adv_col_class = 'col-md-3';
            }

            foreach ($advanced_fields as $subfield) {
                $render_subfield($subfield, $name, $index, $values, $adv_col_class);
            }

            echo '</div></div></div>';
        }

        echo '</div>'; // end repeater-row
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
