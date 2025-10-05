/**
 * Joinery Validation System - Pure JavaScript validation library
 * No jQuery dependencies, works alongside jQuery validation if present
 */
console.log('%c=== JOINERY VALIDATION LOADING ===', 'color: blue; font-weight: bold');
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

            // Validation options
            this.errorElement = options.errorElement || 'label';
            this.errorClass = options.errorClass || 'error';
            this.validClass = options.validClass || 'valid';
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
            this.form.addEventListener('submit', (e) => {
                if (this.debug) {
                    console.log('%c=== FORM SUBMIT ATTEMPT ===', 'color: red; font-weight: bold');
                }

                e.preventDefault();
                e.stopPropagation();

                const isValid = this.validateForm();

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
                        field.addEventListener('change', () => {
                            if (this.debug) console.log(`Change event: ${field.name}`);
                            this.validateField(fieldName);
                        });
                    } else {
                        field.addEventListener('blur', () => {
                            if (this.debug) console.log(`Blur event: ${field.name}`);
                            this.validateField(fieldName);
                        });
                        field.addEventListener('change', () => {
                            if (this.debug) console.log(`Change event: ${field.name}`);
                            this.validateField(fieldName);
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

        validateForm() {
            let isValid = true;

            if (this.debug) {
                console.log('=== Validating entire form ===');
            }

            Object.keys(this.rules).forEach(fieldName => {
                if (!this.validateField(fieldName)) {
                    isValid = false;
                }
            });

            if (this.debug) {
                console.log(`Form is ${isValid ? 'VALID' : 'INVALID'}`);
            }

            return isValid;
        }

        validateField(fieldName) {
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
                const result = validator.call(this, value, fields[0], param);

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
            error.className = this.errorClass + ' joinery-error-label';
            error.setAttribute('data-field', field.name);
            error.textContent = message;
            error.style.cssText = 'display: block; color: #dc3545; margin-top: 0.25rem; font-size: 0.875rem;';

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
        date: "Please enter a valid date in YYYY-MM-DD format."
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