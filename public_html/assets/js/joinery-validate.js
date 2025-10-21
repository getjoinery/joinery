/**
 * Joinery Validation System - Pure JavaScript validation library
 * No jQuery dependencies, works alongside jQuery validation if present
 * @version 1.0.0
 */
console.log('%c=== JOINERY VALIDATION v1.0.0 ===', 'color: blue; font-weight: bold');
console.log('Debug mode enabled:', window.JOINERY_VALIDATE_DEBUG || false);

(function() {
    'use strict';

    // Main validator class
    class JoineryValidator {
        constructor(form, options = {}) {
            this.form = typeof form === 'string' ? document.getElementById(form) : form;
            if (!this.form) {
                console.error('JoineryValidator: Form not found');
                return;
            }

            this.options = options;
            this.rules = options.rules || {};
            this.messages = options.messages || {};

            // Debug mode
            this.debug = options.debug || window.JOINERY_VALIDATE_DEBUG || false;

            if (this.debug) {
                console.log('%c=== JoineryValidator INITIALIZED ===', 'color: green; font-weight: bold');
                console.log('Form:', this.form);
                console.log('Rules:', this.rules);
                console.log('Options:', this.options);
            }

            // Validation options - use Bootstrap's standard classes
            this.errorElement = options.errorElement || 'div';
            this.errorClass = options.errorClass || 'is-invalid';
            this.validClass = options.validClass || 'is-valid';
            this.errorLabelClass = options.errorLabelClass || 'invalid-feedback';
            this.errorPlacement = options.errorPlacement;
            this.highlight = options.highlight;
            this.unhighlight = options.unhighlight;
            this.submitHandler = options.submitHandler;
            this.invalidHandler = options.invalidHandler;

            this.init();
        }

        init() {
            // Prevent browser's default validation UI
            this.form.setAttribute('novalidate', 'novalidate');

            // Submit handler
            this.form.addEventListener('submit', async (e) => {
                if (this.debug) {
                    console.log('%c=== FORM SUBMIT ATTEMPT ===', 'color: red; font-weight: bold');
                }

                e.preventDefault();
                e.stopPropagation();

                const isValid = await this.validateForm();

                if (this.debug) {
                    console.log(`Form validation result: ${isValid ? 'VALID' : 'INVALID'}`);
                }

                if (isValid) {
                    if (this.submitHandler) {
                        if (this.debug) console.log('Calling custom submitHandler');
                        this.submitHandler(this.form);
                    } else {
                        if (this.debug) console.log('Submitting form normally');
                        this.form.submit();
                    }
                } else {
                    if (this.invalidHandler) {
                        if (this.debug) console.log('Calling invalidHandler');
                        this.invalidHandler(e, this);
                    } else {
                        if (this.debug) console.log('Form invalid, submission blocked');
                    }
                    // Ensure we don't submit
                    return false;
                }
            });

            // Set up field validation on blur/change
            this.setupFieldValidation();
        }

        setupFieldValidation() {
            Object.keys(this.rules).forEach(fieldName => {
                // Clean quotes from field name for searching
                const cleanName = fieldName.replace(/['"]/g, '');

                if (this.debug) {
                    console.log(`Setting up validation for: ${fieldName} (clean: ${cleanName})`);
                }

                // Find fields - try exact name first, then with [] appended
                let fields = this.findFields(cleanName);

                if (fields.length === 0) {
                    if (this.debug) {
                        console.warn(`Field not found: ${cleanName}`);
                    }
                    return;
                }

                if (this.debug) {
                    console.log(`Found ${fields.length} field(s) for ${cleanName}`);
                }

                // Add event listeners
                fields.forEach(field => {
                    if (field.type === 'radio' || field.type === 'checkbox') {
                        field.addEventListener('change', async () => {
                            if (this.debug) console.log(`Change event: ${field.name}`);
                            await this.validateField(fieldName);
                        });
                    } else {
                        field.addEventListener('blur', async () => {
                            if (this.debug) console.log(`Blur event: ${field.name}`);
                            await this.validateField(fieldName);
                        });
                        field.addEventListener('change', async () => {
                            if (this.debug) console.log(`Change event: ${field.name}`);
                            await this.validateField(fieldName);
                        });
                    }
                });
            });
        }

        findFields(fieldName) {
            // Try exact name first
            const escapedName = fieldName.replace(/\[/g, '\\[').replace(/\]/g, '\\]');
            let fields = this.form.querySelectorAll(`[name="${escapedName}"]`);

            // If not found and name doesn't have brackets, try with [] appended
            if (fields.length === 0 && !fieldName.includes('[')) {
                const bracketName = fieldName + '[]';
                const escapedBracketName = bracketName.replace(/\[/g, '\\[').replace(/\]/g, '\\]');
                fields = this.form.querySelectorAll(`[name="${escapedBracketName}"]`);
            }

            return fields;
        }

        async validateForm() {
            let isValid = true;

            if (this.debug) {
                console.log('=== Validating entire form ===');
            }

            for (const fieldName of Object.keys(this.rules)) {
                const fieldValid = await this.validateField(fieldName);
                if (!fieldValid) {
                    isValid = false;
                }
            }

            if (this.debug) {
                console.log(`Form is ${isValid ? 'VALID' : 'INVALID'}`);
            }

            return isValid;
        }

        async validateField(fieldName) {
            const cleanName = fieldName.replace(/['"]/g, '');
            const rules = this.rules[fieldName];

            if (this.debug) {
                console.log(`Validating field: ${fieldName}`);
            }

            // Get field(s) using findFields method
            const fields = this.findFields(cleanName);
            if (fields.length === 0) {
                if (this.debug) console.warn(`Field not found for validation: ${cleanName}`);
                return true;
            }

            // Get field value
            const value = this.getFieldValue(fields);

            if (this.debug) {
                console.log(`Field value for ${fieldName}:`, value);
                console.log(`  Type: ${typeof value}, IsArray: ${Array.isArray(value)}`);
                if (fields[0]) {
                    console.log(`  Element type: ${fields[0].type}, name: ${fields[0].name}`);
                }
            }

            // Check each rule
            let isValid = true;
            let errorMessage = '';

            for (const [ruleName, ruleParam] of Object.entries(rules)) {
                const validator = JoineryValidator.validators[ruleName];
                if (!validator) {
                    console.warn(`Unknown validator: ${ruleName}`);
                    continue;
                }

                // Extract rule parameter
                const param = ruleParam === true ? true
                            : ruleParam.value !== undefined ? ruleParam.value
                            : ruleParam;

                // Call validator with validator instance as context
                const result = await validator.call(this, value, fields[0], param);

                if (this.debug) {
                    console.log(`Rule ${ruleName}: param=${param}, result=${result}`);
                }

                if (!result) {
                    isValid = false;
                    errorMessage = this.messages[fieldName]?.[ruleName]
                                || ruleParam.message
                                || JoineryValidator.messages[ruleName]
                                || 'Please check this field';

                    // Replace {0} with parameter value
                    if (errorMessage && param !== true) {
                        errorMessage = errorMessage.replace('{0}', param);
                    }
                    break;
                }
            }

            // Show/clear error
            if (!isValid) {
                this.showError(fields[0], errorMessage);
            } else {
                this.clearError(fields[0]);
            }

            return isValid;
        }

        getFieldValue(fields) {
            if (fields.length === 0) return '';

            // Radio buttons
            if (fields[0].type === 'radio') {
                for (let field of fields) {
                    if (field.checked) return field.value;
                }
                return '';
            }

            // Checkboxes
            if (fields[0].type === 'checkbox') {
                if (fields.length === 1) {
                    // Single checkbox - return true/false for checked state
                    return fields[0].checked;
                } else {
                    // Checkbox group - return array (empty array if none selected)
                    const values = [];
                    for (let field of fields) {
                        if (field.checked) values.push(field.value);
                    }
                    return values;
                }
            }

            // Regular field
            return fields[0].value || '';
        }

        showError(field, message) {
            if (this.debug) {
                console.log(`%c[showError] ${field.name}: ${message}`, 'color: orange; font-weight: bold');
            }

            const form = field.closest('form');

            // First, ensure no existing error for this field
            if (this.debug) {
                const existingBefore = this.countErrorLabels(form, field.name);
                console.log(`  Existing errors before clear: ${existingBefore}`);
            }
            this.clearError(field);
            if (this.debug) {
                const existingAfter = this.countErrorLabels(form, field.name);
                console.log(`  Existing errors after clear: ${existingAfter}`);
            }

            // For radio/checkbox groups, apply error class to ALL fields in the group
            if (field.type === 'radio' || field.name.endsWith('[]')) {
                const escapedName = field.name.replace(/\[/g, '\\[').replace(/\]/g, '\\]');
                const allFields = form.querySelectorAll(`[name="${escapedName}"]`);
                allFields.forEach(f => {
                    f.classList.add(this.errorClass);
                    f.classList.remove(this.validClass);
                });
            } else {
                // Single field - just add error class to this field
                field.classList.add(this.errorClass);
                field.classList.remove(this.validClass);
            }

            // Create error element with a unique identifier
            const error = document.createElement(this.errorElement);
            error.className = this.errorLabelClass + ' joinery-error-label';
            error.setAttribute('data-field', field.name);
            error.textContent = message;

            if (this.debug) {
                console.log(`Inserting error label for ${field.name}`);
            }

            // For radio/checkbox groups, put error after the container
            if (field.type === 'radio' || field.type === 'checkbox') {
                // For radio groups or checkbox groups (name ends with [])
                if (field.type === 'radio' || field.name.endsWith('[]')) {
                    const escapedName = field.name.replace(/\[/g, '\\[').replace(/\]/g, '\\]');
                    const allFields = form.querySelectorAll(`[name="${escapedName}"]`);
                    const lastField = allFields[allFields.length - 1];

                    // Find the container that holds all the radio/checkbox options
                    let container = lastField.closest('.errorplacement');
                    if (!container) {
                        // If no errorplacement, find the parent of the last form-check
                        const lastCheck = lastField.closest('.form-check');
                        container = lastCheck ? lastCheck.parentNode : lastField.parentNode;
                    }

                    // Insert after the container
                    if (container.classList && container.classList.contains('errorplacement')) {
                        container.appendChild(error);
                    } else {
                        container.appendChild(error);
                    }
                } else {
                    // Single checkbox
                    const container = field.closest('.form-check') || field.closest('.errorplacement') || field.parentNode;
                    container.parentNode.insertBefore(error, container.nextSibling);
                }
            } else {
                // Regular field - insert error after field
                field.parentNode.insertBefore(error, field.nextSibling);
            }
        }

        clearError(field) {
            const form = field.closest('form');

            // Remove error class from field
            field.classList.remove(this.errorClass);
            field.classList.add(this.validClass);

            // For radio/checkbox groups, clear error from all fields in group
            if (field.type === 'radio' || (field.type === 'checkbox' && field.name.endsWith('[]'))) {
                // Escape square brackets for querySelector
                const escapedName = field.name.replace(/\[/g, '\\[').replace(/\]/g, '\\]');
                const allFields = form.querySelectorAll(`[name="${escapedName}"]`);
                allFields.forEach(f => {
                    f.classList.remove(this.errorClass);
                    f.classList.add(this.validClass);
                });
            }

            // Remove ALL error messages for this field (in case of duplicates)
            const labels = form.querySelectorAll('.joinery-error-label');
            for (let label of labels) {
                if (label.getAttribute('data-field') === field.name) {
                    if (this.debug) {
                        console.log(`Removing error label for ${field.name}`);
                    }
                    label.remove();
                }
            }
        }

        findErrorLabel(form, fieldName) {
            // Find error label by data-field attribute, handling special characters
            const labels = form.querySelectorAll('.joinery-error-label');
            for (let label of labels) {
                if (label.getAttribute('data-field') === fieldName) {
                    return label;
                }
            }
            return null;
        }

        countErrorLabels(form, fieldName) {
            // Count error labels for a field
            let count = 0;
            const labels = form.querySelectorAll('.joinery-error-label');
            for (let label of labels) {
                if (label.getAttribute('data-field') === fieldName) {
                    count++;
                }
            }
            return count;
        }
    }

    // Built-in validators
    JoineryValidator.validators = {
        required: function(value, element, param) {
            // Single checkbox - value is boolean
            if (element && element.type === 'checkbox' && !element.name.endsWith('[]')) {
                return value === true;
            }

            // Radio button group - value is the selected value or empty string
            if (element && element.type === 'radio') {
                return value !== '' && value !== null && value !== undefined;
            }

            // Checkbox group - value is an array
            if (element && element.type === 'checkbox' && element.name.endsWith('[]')) {
                return Array.isArray(value) && value.length > 0;
            }

            // All other fields - check for non-empty string
            return value !== '' && value !== null && value !== undefined;
        },

        email: function(value, element) {
            // Skip validation for empty values, booleans, or arrays
            if (typeof value === 'boolean' || Array.isArray(value)) return true;
            return !value || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
        },

        url: function(value, element) {
            // Skip validation for empty values, booleans, or arrays
            if (typeof value === 'boolean' || Array.isArray(value)) return true;
            return !value || /^https?:\/\/.+/.test(value);
        },

        number: function(value, element) {
            // Skip validation for empty values, booleans, or arrays
            if (typeof value === 'boolean' || Array.isArray(value)) return true;
            return !value || !isNaN(value);
        },

        digits: function(value, element) {
            // Skip validation for empty values, booleans, or arrays
            if (typeof value === 'boolean' || Array.isArray(value)) return true;
            return !value || /^\d+$/.test(value);
        },

        minlength: function(value, element, param) {
            // Skip validation for empty values, booleans, or arrays
            if (typeof value === 'boolean' || Array.isArray(value)) return true;
            return !value || value.length >= param;
        },

        maxlength: function(value, element, param) {
            // Skip validation for empty values, booleans, or arrays
            if (typeof value === 'boolean' || Array.isArray(value)) return true;
            return !value || value.length <= param;
        },

        min: function(value, element, param) {
            // Skip validation for empty values, booleans, or arrays
            if (typeof value === 'boolean' || Array.isArray(value)) return true;
            return !value || Number(value) >= Number(param);
        },

        max: function(value, element, param) {
            // Skip validation for empty values, booleans, or arrays
            if (typeof value === 'boolean' || Array.isArray(value)) return true;
            return !value || Number(value) <= Number(param);
        },

        equalTo: function(value, element, param) {
            // Skip validation for booleans or arrays
            if (typeof value === 'boolean' || Array.isArray(value)) return true;
            const form = element.closest('form');
            const other = form ? form.elements[param] : null;
            return !value || value === (other ? other.value : '');
        },

        time: function(value, element) {
            // Skip validation for empty values, booleans, or arrays
            if (typeof value === 'boolean' || Array.isArray(value)) return true;
            if (!value) return true;
            // Validate time format: HH:MM (24-hour) or H:MM AM/PM (12-hour)
            const time24 = /^([0-1]?[0-9]|2[0-3]):[0-5][0-9]$/;
            const time12 = /^(0?[1-9]|1[0-2]):[0-5][0-9]\s?(AM|PM|am|pm)$/;
            return time24.test(value) || time12.test(value);
        },

        date: function(value, element) {
            // Skip validation for empty values, booleans, or arrays
            if (typeof value === 'boolean' || Array.isArray(value)) return true;
            if (!value) return true;
            // Validate date format YYYY-MM-DD
            return /^\d{4}-\d{2}-\d{2}$/.test(value);
        },

        remote: async function(value, element, param) {
            // AJAX validation - returns promise
            if (!value) return true;

            // param can be a URL string or an object with url and data
            let parsedParam = param;
            if (typeof param === 'string') {
                try {
                    parsedParam = JSON.parse(param);
                } catch (e) {
                    // Not JSON, treat as URL string
                    parsedParam = param;
                }
            }

            const url = typeof parsedParam === 'string' ? parsedParam : parsedParam.url;
            const method = (typeof parsedParam === 'object' && parsedParam.method) ? parsedParam.method : 'GET';
            const extraData = (typeof parsedParam === 'object' && parsedParam.data) ? parsedParam.data : {};
            const dataFieldName = (typeof parsedParam === 'object' && parsedParam.dataFieldName) ? parsedParam.dataFieldName : element.name;

            // Build query data
            const data = { ...extraData };
            data[dataFieldName] = value;

            if (this.debug) {
                console.log(`[Remote validation] URL: ${url}, Field: ${dataFieldName}, Value: ${value}`);
                console.log(`[Remote validation] Data being sent:`, data);
            }

            try {
                let response;
                if (method.toUpperCase() === 'GET') {
                    // GET request - append to URL
                    const queryString = new URLSearchParams(data).toString();
                    const fullUrl = url + (url.includes('?') ? '&' : '?') + queryString;

                    if (this.debug) {
                        console.log(`[Remote validation] Full URL: ${fullUrl}`);
                    }

                    response = await fetch(fullUrl, {
                        method: 'GET',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });
                } else {
                    // POST request
                    response = await fetch(url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: new URLSearchParams(data)
                    });
                }

                const result = await response.text();

                if (this.debug) {
                    console.log(`[Remote validation] Server response: "${result}"`);
                }

                // jQuery validation returns 'true' or 'false' as strings, or boolean true
                return result === 'true' || result === true || result === '1';
            } catch (e) {
                console.error('Remote validation error:', e);
                return true; // Assume valid if request fails (fail gracefully)
            }
        }
    };

    // Default messages
    JoineryValidator.messages = {
        required: "This field is required.",
        email: "Please enter a valid email address.",
        url: "Please enter a valid URL.",
        number: "Please enter a valid number.",
        digits: "Please enter only digits.",
        minlength: "Please enter at least {0} characters.",
        maxlength: "Please enter no more than {0} characters.",
        min: "Please enter a value greater than or equal to {0}.",
        max: "Please enter a value less than or equal to {0}.",
        equalTo: "Please enter the same value again.",
        time: "Please enter a valid time (e.g., 14:30 or 2:30 PM).",
        date: "Please enter a valid date in YYYY-MM-DD format.",
        remote: "Please fix this field."
    };

    // Add custom validators
    JoineryValidator.addValidator = function(name, method, message) {
        JoineryValidator.validators[name] = method;
        if (message) {
            JoineryValidator.messages[name] = message;
        }
    };

    // Custom validators for compatibility
    JoineryValidator.addValidator("phoneUS", function(value, element) {
        if (!value) return true;
        value = value.replace(/\s+/g, "");
        return value.length > 9 && /^(1-?)?(\([2-9]\d{2}\)|[2-9]\d{2})-?[2-9]\d{2}-?\d{4}$/.test(value);
    }, "Please specify a valid phone number");

    // General phone validator (not US-specific)
    JoineryValidator.addValidator("phone", function(value, element) {
        if (!value) return true;
        // Accept formats: (123) 456-7890, 123-456-7890, 123.456.7890, 1234567890
        return /^\(?([0-9]{3})\)?[-. ]?([0-9]{3})[-. ]?([0-9]{4})$/.test(value);
    }, "Please enter a valid phone number");

    // ZIP code validator
    JoineryValidator.addValidator("zip", function(value, element) {
        if (!value) return true;
        // Accept 5 digit or 5+4 digit ZIP codes
        return /^[0-9]{5}([- ]?[0-9]{4})?$/.test(value);
    }, "Please enter a valid ZIP code");

    // SSN validator
    JoineryValidator.addValidator("ssn", function(value, element) {
        if (!value) return true;
        // Accept formats: 123-45-6789 or 123456789
        return /^([0-9]{3})[-]?([0-9]{2})[-]?([0-9]{4})$/.test(value);
    }, "Please enter a valid SSN");

    // EIN validator
    JoineryValidator.addValidator("ein", function(value, element) {
        if (!value) return true;
        // Accept formats: 12-3456789 or 123456789
        return /^([0-9]{2})[-]?([0-9]{7})$/.test(value);
    }, "Please enter a valid EIN");

    // Credit card validator (Luhn algorithm)
    JoineryValidator.addValidator("credit_card", function(value, element) {
        if (!value) return true;

        // Remove spaces and dashes
        var cardNumber = value.replace(/[\s\-]/g, '');

        // Check if numeric and reasonable length
        if (!/^[0-9]{13,19}$/.test(cardNumber)) {
            return false;
        }

        // Luhn algorithm
        var sum = 0;
        var length = cardNumber.length;
        var parity = length % 2;

        for (var i = 0; i < length; i++) {
            var digit = parseInt(cardNumber.charAt(i));
            if (i % 2 == parity) {
                digit *= 2;
                if (digit > 9) {
                    digit -= 9;
                }
            }
            sum += digit;
        }

        return (sum % 10) == 0;
    }, "Please enter a valid credit card number");

    // Pattern validator
    JoineryValidator.addValidator("pattern", function(value, element, param) {
        if (!value) return true;
        // param is the regex pattern
        var regex = new RegExp(param);
        return regex.test(value);
    }, "Please match the required format");

    // Matches validator (alias for equalTo)
    JoineryValidator.addValidator("matches", function(value, element, param) {
        // Use the existing equalTo validator
        return JoineryValidator.validators.equalTo.call(this, value, element, param);
    }, "Please enter the same value again");

    /**
     * require_one_group - At least one field in a named group must be filled
     * Usage in validation rules:
     *
     * $validation_rules['field1']['require_one_group']['value'] = 'group_name';
     * $validation_rules['field1']['require_one_group']['message'] = 'At least one field in this group is required';
     *
     * $validation_rules['field2']['require_one_group']['value'] = 'group_name';
     * $validation_rules['field2']['require_one_group']['message'] = 'At least one field in this group is required';
     *
     * All fields with the same group name will be validated together.
     * At least one field in the group must have a value for validation to pass.
     */
    JoineryValidator.addValidator("require_one_group", function(value, element, groupName) {
        // groupName is the name of the group (e.g., 'discount_fields')
        if (!groupName) return true;

        var form = element.form;
        var validator = this;

        // Build a map of group names to field names if not already built
        if (!validator.groupFieldsMap) {
            validator.groupFieldsMap = {};
        }

        // Build the group map if this group hasn't been processed yet
        if (!validator.groupFieldsMap[groupName]) {
            validator.groupFieldsMap[groupName] = [];

            // Find all fields with this group name in their rules
            for (var fieldName in validator.rules) {
                if (validator.rules[fieldName].require_one_group === groupName) {
                    validator.groupFieldsMap[groupName].push(fieldName);
                }
            }
        }

        // Get all field names in this group
        var fieldNamesInGroup = validator.groupFieldsMap[groupName];

        // Check if at least one field in the group has a value
        for (var i = 0; i < fieldNamesInGroup.length; i++) {
            var fieldName = fieldNamesInGroup[i];
            var field = form.elements[fieldName];

            if (field) {
                var fieldValue = '';

                // Handle different input types
                if (field.type === 'checkbox' || field.type === 'radio') {
                    if (field.checked) {
                        fieldValue = field.value;
                    }
                } else if (field.tagName === 'SELECT') {
                    fieldValue = field.value;
                } else {
                    fieldValue = field.value;
                }

                // If any field has a value, validation passes
                if (fieldValue && fieldValue.trim() !== '') {
                    return true;
                }
            }
        }

        // None of the fields in the group have values - validation fails
        return false;
    }, "At least one field in this group is required");

    // Expose globally
    window.JoineryValidator = JoineryValidator;

    // Initialize function for compatibility with FormWriter output
    window.JoineryValidation = {
        init: function(formId, options) {
            if (window.JOINERY_VALIDATE_DEBUG) {
                console.log('JoineryValidation.init called for form:', formId);
            }

            // Wait for DOM ready
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function() {
                    new JoineryValidator(formId, options);
                });
            } else {
                new JoineryValidator(formId, options);
            }
        }
    };

})();

console.log('%c=== JOINERY VALIDATION LOADED ===', 'color: blue; font-weight: bold');