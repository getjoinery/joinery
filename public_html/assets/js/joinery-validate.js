/**
 * Joinery Validation System - Pure JavaScript validation library
 * No jQuery dependencies, works alongside jQuery validation if present
 * @version 1.2.2
 * @changelog 1.2.1 - A submit event another listener already cancelled is
 *   left alone. The re-dispatch exists so other listeners can veto a
 *   validated submission; without this check a page handler that
 *   preventDefault()s and takes over (e.g. posting via the API) was
 *   validated-then-navigated anyway, unless it also knew to neuter
 *   form.submit. Cancelling the original submission now means what it says.
 * @changelog 1.2.0 - A failed submit attempt fills the form's error summary
 *   (.jy-error-summary, emitted by FormWriter before the first submit button;
 *   created here when absent): one linked item per invalid field, focus moves
 *   to the summary, items live-update as fields are fixed. Off by default
 *   nowhere - options.errorSummary === false opts a form out.
 * @changelog 1.1.1 - The native re-dispatch is deferred out of the submit
 *   event's own dispatch. requestSubmit() returns silently while a form's
 *   submit event is still firing, and a validation pass with no real I/O
 *   resolves in a microtask - which, on a trusted click, still runs inside
 *   that dispatch. The submission was therefore dropped with no error.
 * @changelog 1.1.0 - A valid form re-submits NATIVELY (requestSubmit + a
 *   one-shot stand-aside flag) instead of form.submit(), so other submit
 *   listeners (step-up interceptors, payment tokenizers, analytics) see the
 *   validated submission and may cancel it. form.submit() remains only as
 *   the no-requestSubmit legacy fallback.
 */
console.log('%c=== JOINERY VALIDATION v1.2.0 ===', 'color: blue; font-weight: bold');

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

            // CRITICAL: Detect incompatibilities before proceeding
            // This will throw errors for payment forms and warn for other issues
            this.detectIncompatibilities();

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

            // Error summary: a linked list of everything wrong, shown next to
            // the submit button on a failed submit attempt (never while typing)
            this.errorSummary = options.errorSummary !== false;
            this.errorSummaryTitle = options.errorSummaryTitle || null;
            this.fieldErrors = new Map();   // summary key -> current message
            this.summaryShown = false;      // live-update items only once revealed

            // Track which fields have been touched by user
            this.touchedFields = new Set();

            // Flag to prevent double-submission during validation
            this.isValidating = false;

            this.init();
        }

        init() {
            // Prevent browser's default validation UI
            this.form.setAttribute('novalidate', 'novalidate');

            // Adopt a server-filled summary (PHP renders the container visible
            // when the form re-rendered carrying server-side errors) so the
            // per-field lifecycle below manages its items from the start.
            if (this.errorSummary) {
                const existing = this.getSummaryContainer(false);
                if (existing && !existing.hidden) this.summaryShown = true;
            }

            // Submit handler - simple and deterministic
            this.form.addEventListener('submit', async (e) => {
                // Our own validated re-dispatch (below): stand aside so the
                // event proceeds natively. Other listeners still see it and
                // may preventDefault - that is the point of the re-dispatch.
                if (this.form.dataset.jyValidated === '1') {
                    delete this.form.dataset.jyValidated;
                    if (this.debug) console.log('→ Validated re-dispatch, passing through');
                    return;
                }

                // An earlier listener already cancelled this submission and
                // owns it (API-posting pages preventDefault and take over).
                // Validating and re-dispatching here would navigate over it.
                if (e.defaultPrevented) {
                    if (this.debug) console.log('→ Submission cancelled by another listener; standing aside');
                    return;
                }

                if (this.debug) {
                    console.log('%c=== FORM SUBMIT ATTEMPT ===', 'color: red; font-weight: bold');
                }

                // Capture which submit button triggered this submission. We
                // validate, then submit programmatically via form.submit() —
                // which, unlike native submission, does NOT include the
                // submitter button's name/value. On forms with more than one
                // submit button (e.g. Save vs Save & Write to disk vs Delete)
                // the server would otherwise never learn which was clicked.
                // Captured here (before any await) and reattached below.
                const submitter = e.submitter;

                // A submit button may opt out of validation via the
                // formnovalidate attribute (e.g. Delete / Cancel actions that
                // must fire even when required fields are empty). Native HTML
                // honors this per-button; mirror that here so multi-action forms
                // behave the same whether or not the validator is attached.
                // FormWriter exposes it as the submitbutton() 'formnovalidate'
                // (alias 'skip_validation') option.
                const skipValidation = !!(submitter && submitter.formNoValidate);

                // Prevent double-validation during async validation
                if (this.isValidating) {
                    if (this.debug) console.log('Validation already in progress, ignoring');
                    e.preventDefault();
                    return false;
                }

                // Always prevent the initial submission to validate first
                e.preventDefault();
                e.stopPropagation();

                let isValid;
                if (skipValidation) {
                    if (this.debug) console.log('→ Submitter sets formnovalidate; skipping validation');
                    isValid = true;
                } else {
                    // Mark as validating
                    this.isValidating = true;
                    isValid = await this.validateForm();
                    this.isValidating = false;
                }

                if (isValid) {
                    if (this.submitHandler) {
                        if (this.debug) console.log('→ Calling custom submitHandler');
                        this.submitHandler(this.form);
                    } else if (this.form.requestSubmit) {
                        // Re-submit NATIVELY with a one-shot stand-aside flag.
                        // A native submission fires the submit event again, so
                        // every other listener (step-up interceptors, payment
                        // tokenizers, analytics) sees the validated submission
                        // and may cancel it - form.submit() silently navigated
                        // over them. requestSubmit(submitter) also carries the
                        // clicked button's name/value natively.
                        this.form.dataset.jyValidated = '1';
                        if (this.debug) console.log('→ Valid, re-dispatching natively via requestSubmit');
                        // Deferred by a task on purpose. requestSubmit() returns
                        // silently while this form's submit event is still being
                        // dispatched, and validation that needs no network call
                        // resolves in a microtask - which, on a real click, runs
                        // inside that same dispatch. Calling it here directly
                        // drops the submission with no error and no request.
                        const validatedSubmitter =
                            (submitter && submitter.form === this.form) ? submitter : undefined;
                        setTimeout(() => {
                            this.form.requestSubmit(validatedSubmitter);
                        }, 0);
                    } else {
                        // Legacy fallback (no requestSubmit): preserve the
                        // clicked button's name/value - form.submit() drops the
                        // submitter native submission would have sent.
                        if (submitter && submitter.name) {
                            let preserved = this.form.querySelector('input[type="hidden"][data-joinery-submitter]');
                            if (!preserved) {
                                preserved = document.createElement('input');
                                preserved.type = 'hidden';
                                preserved.setAttribute('data-joinery-submitter', '1');
                                this.form.appendChild(preserved);
                            }
                            preserved.name = submitter.name;
                            preserved.value = submitter.value || '';
                        }
                        try {
                            this.form.submit();
                        } catch (error) {
                            // Fallback for name="submit" shadowing issue
                            if (this.debug) console.warn('⚠️ form.submit() failed, using prototype fallback:', error);
                            HTMLFormElement.prototype.submit.call(this.form);
                        }
                    }
                } else {
                    this.showSummary();
                    if (this.invalidHandler) {
                        if (this.debug) console.log('→ Calling invalidHandler');
                        this.invalidHandler(e, this);
                    }
                }
            });

            // Set up field validation on blur/change
            this.setupFieldValidation();
        }

        detectIncompatibilities() {
            // Allow developers to skip compatibility checking entirely
            if (this.options.skipCompatibilityCheck) {
                if (this.debug) {
                    console.warn('%c[JoineryValidator] Compatibility checking disabled via skipCompatibilityCheck option',
                                'color: orange; font-weight: bold');
                }
                return [];
            }

            const issues = [];

            // 1. Payment forms (CRITICAL - will break payment processing)
            if (this.isPaymentForm()) {
                issues.push({
                    severity: 'error',
                    type: 'payment_form',
                    message: 'Payment form detected. JoineryValidator uses form.submit() which bypasses Stripe/PayPal tokenization handlers. This will expose raw card data and break payment processing.',
                    solution: 'Either:\n' +
                             '  (1) Don\'t use JoineryValidator on payment forms (payment processors have their own validation)\n' +
                             '  (2) Provide a custom submitHandler option\n' +
                             '  (3) Set options.ignoreIncompatibilityErrors = true (NOT RECOMMENDED - security risk)'
                });
            }

            // 2. Analytics tracking (WARNING - will lose tracking data)
            const analytics = this.detectAnalytics();
            if (analytics.length > 0) {
                issues.push({
                    severity: 'warning',
                    type: 'analytics',
                    message: `Analytics detected (${analytics.join(', ')}). Form submissions will not be tracked because form.submit() bypasses submit event handlers.`,
                    solution: 'Provide a custom submitHandler that fires tracking events before calling form.submit():\n' +
                             '  submitHandler: function(form) {\n' +
                             '    gtag(\'event\', \'form_submit\', { form_id: form.id });\n' +
                             '    form.submit();\n' +
                             '  }'
                });
            }

            // 3. Forms with name="submit" (WARNING - might fail)
            if (this.form.elements['submit']) {
                issues.push({
                    severity: 'warning',
                    type: 'name_submit',
                    message: 'Form has an element with name="submit". This shadows the form.submit() method and may cause submission to fail.',
                    solution: 'Rename the element to something other than "submit" (e.g., "submit_button", "save").'
                });
            }

            // 4. Inline onsubmit handler (WARNING - will be bypassed)
            if (this.form.onsubmit || this.form.getAttribute('onsubmit')) {
                issues.push({
                    severity: 'warning',
                    type: 'inline_handler',
                    message: 'Form has an inline onsubmit handler. This will be bypassed when using form.submit().',
                    solution: 'Remove the inline onsubmit and provide a custom submitHandler instead.'
                });
            }

            // 5. Multiple validators on same form (WARNING - may conflict)
            if (this.form.hasAttribute('data-joinery-validator')) {
                issues.push({
                    severity: 'warning',
                    type: 'duplicate_validator',
                    message: 'Multiple JoineryValidator instances detected on the same form. This may cause conflicts.',
                    solution: 'Only create one JoineryValidator instance per form.'
                });
            }
            this.form.setAttribute('data-joinery-validator', 'true');

            // Handle issues
            for (const issue of issues) {
                const msg = `╔════════════════════════════════════════════════════════════════\n` +
                           `║ JoineryValidator ${issue.severity.toUpperCase()}: ${issue.type}\n` +
                           `╠════════════════════════════════════════════════════════════════\n` +
                           `║ ${issue.message}\n` +
                           `║\n` +
                           `║ Solution:\n` +
                           `║ ${issue.solution.replace(/\n/g, '\n║ ')}\n` +
                           `╚════════════════════════════════════════════════════════════════`;

                if (issue.severity === 'error') {
                    console.error(msg);
                    // Always throw errors - use skipCompatibilityCheck to disable
                    throw new Error(`JoineryValidator: ${issue.type} - ${issue.message}`);
                } else if (issue.severity === 'warning') {
                    console.warn(msg);
                }
            }

            return issues;
        }

        isPaymentForm() {
            return !!(
                // Stripe detection
                this.form.querySelector('[name="stripeToken"]') ||
                this.form.querySelector('#card-element') ||
                this.form.querySelector('[data-stripe]') ||
                this.form.querySelector('.StripeElement') ||
                (typeof window.Stripe !== 'undefined' && this.form.querySelector('[data-stripe-key]')) ||

                // PayPal detection
                this.form.querySelector('[data-paypal]') ||
                this.form.querySelector('#paypal-button-container') ||
                this.form.querySelector('.paypal-button') ||
                (typeof window.paypal !== 'undefined' && this.form.querySelector('[data-paypal-button]')) ||

                // Square detection
                this.form.querySelector('#sq-card-number') ||
                this.form.querySelector('[data-square]') ||

                // Generic payment form detection
                this.form.id === 'payment-form' ||
                this.form.id === 'checkout-form' ||
                this.form.id === 'billing-form' ||
                this.form.classList.contains('payment-form') ||
                this.form.classList.contains('checkout-form') ||
                this.form.classList.contains('billing-form') ||

                // Manual override
                this.options.isPaymentForm === true
            );
        }

        detectAnalytics() {
            const detected = [];

            if (typeof window.ga !== 'undefined') detected.push('Google Analytics (UA)');
            if (typeof window.gtag !== 'undefined') detected.push('Google Analytics 4');
            if (typeof window.dataLayer !== 'undefined') detected.push('Google Tag Manager');
            if (typeof window.fbq !== 'undefined') detected.push('Facebook Pixel');
            if (typeof window._paq !== 'undefined') detected.push('Matomo');
            if (typeof window.mixpanel !== 'undefined') detected.push('Mixpanel');
            if (typeof window.heap !== 'undefined') detected.push('Heap Analytics');
            if (typeof window.analytics !== 'undefined' && typeof window.analytics.track === 'function') {
                detected.push('Segment');
            }

            return detected;
        }

        setupFieldValidation() {
            Object.keys(this.rules).forEach(fieldName => {
                // Clean quotes from field name for searching
                const cleanName = fieldName.replace(/['"]/g, '');

                // Find fields - try exact name first, then with [] appended
                let fields = this.findFields(cleanName);

                if (fields.length === 0) {
                    if (this.debug) {
                        console.warn(`⚠️ Field not found: ${cleanName}`);
                    }
                    return;
                }

                // Add event listeners
                fields.forEach(field => {
                    if (field.type === 'radio' || field.type === 'checkbox') {
                        field.addEventListener('change', async () => {
                            this.touchedFields.add(fieldName);
                            await this.validateField(fieldName);
                        });
                    } else {
                        field.addEventListener('blur', async (e) => {
                            this.touchedFields.add(fieldName);
                            await this.validateField(fieldName);
                        });
                        field.addEventListener('change', async () => {
                            this.touchedFields.add(fieldName);
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

            // Mark all fields as touched when validating the entire form (on submit)
            for (const fieldName of Object.keys(this.rules)) {
                this.touchedFields.add(fieldName);
            }

            for (const fieldName of Object.keys(this.rules)) {
                const fieldValid = await this.validateField(fieldName);
                if (!fieldValid) {
                    isValid = false;
                }
            }

            if (this.debug) {
                console.log(`Form is ${isValid ? 'VALID ✓' : 'INVALID ✗'}`);
            }

            return isValid;
        }

        async validateField(fieldName) {
            const cleanName = fieldName.replace(/['"]/g, '');
            const rules = this.rules[fieldName];

            // Get field(s) using findFields method
            let fields = this.findFields(cleanName);
            if (fields.length === 0) {
                if (this.debug) console.warn(`⚠️ Field not found for validation: ${cleanName}`);
                return true;
            }

            // A control the user cannot interact with cannot be asked to fill
            // anything in: skip disabled fields and fields inside hidden
            // sections (conditional panels via visibility_rules or [hidden]).
            const allFields = fields;
            fields = Array.from(fields).filter(el => {
                if (el.disabled) return false;
                if (el.type === 'hidden') return true; // carries data, not UI
                return el.checkVisibility ? el.checkVisibility() : !!el.offsetParent;
            });
            if (fields.length === 0) {
                if (this.debug) console.log(`⏭ Skipping validation for hidden/disabled field: ${cleanName}`);
                this.clearError(allFields[0]);
                return true;
            }

            // Get field value
            const value = this.getFieldValue(fields);

            // Check each rule
            let isValid = true;
            let errorMessage = '';

            for (const [ruleName, ruleParam] of Object.entries(rules)) {
                const validator = JoineryValidator.validators[ruleName];
                if (!validator) {
                    console.warn(`⚠️ Unknown validator: ${ruleName}`);
                    continue;
                }

                // Extract rule parameter. A null/undefined param must not
                // throw — a crash here silently blocks the form's submit.
                const param = ruleParam === true ? true
                            : (ruleParam !== null && typeof ruleParam === 'object' && ruleParam.value !== undefined) ? ruleParam.value
                            : ruleParam;

                // Call validator with validator instance as context
                const result = await validator.call(this, value, fields[0], param);

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
                console.log(`✗ ${field.name}: ${message}`);
            }

            const form = field.closest('form');

            // First, ensure no existing error for this field
            this.clearError(field);

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

            // Record for the error summary. Updates a visible summary's item;
            // never reveals the summary on its own (blur errors stay quiet).
            this.fieldErrors.set(this.summaryKey(field.name), message);
            if (this.summaryShown) this.syncSummaryItem(this.summaryKey(field.name));
        }

        clearError(field) {
            const form = field.closest('form');

            // Remove error class from field
            field.classList.remove(this.errorClass);

            // Only add valid class if field has been touched AND has actual content
            // Empty optional fields should show neutral, not green
            const fieldValue = this.getFieldValue([field]);
            const hasContent = fieldValue && (typeof fieldValue === 'string' ? fieldValue.trim() !== '' : true);

            if (this.touchedFields.has(field.name) && hasContent) {
                field.classList.add(this.validClass);
            } else {
                field.classList.remove(this.validClass);
            }

            // For radio/checkbox groups, clear error from all fields in group
            if (field.type === 'radio' || (field.type === 'checkbox' && field.name.endsWith('[]'))) {
                // Escape square brackets for querySelector
                const escapedName = field.name.replace(/\[/g, '\\[').replace(/\]/g, '\\]');
                const allFields = form.querySelectorAll(`[name="${escapedName}"]`);
                allFields.forEach(f => {
                    f.classList.remove(this.errorClass);
                    if (this.touchedFields.has(field.name) && hasContent) {
                        f.classList.add(this.validClass);
                    } else {
                        f.classList.remove(this.validClass);
                    }
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

            // Drop from the error summary; a visible summary loses this item
            // and hides itself when the last one goes.
            this.fieldErrors.delete(this.summaryKey(field.name));
            if (this.summaryShown) this.syncSummaryItem(this.summaryKey(field.name));
        }

        // ── Error summary ────────────────────────────────────────────────
        // A failed submit attempt reveals one block above the submit button
        // naming every invalid field, each item a link that jumps to and
        // focuses the field. FormWriter emits the container server-side; a
        // hand-rolled form gets one created here. Items share the summary
        // key with PHP's server-side fill (field name without a [] suffix),
        // so both producers manage the same list.

        summaryKey(fieldName) {
            return fieldName.replace(/\[\]$/, '');
        }

        cssEscape(value) {
            return (window.CSS && CSS.escape) ? CSS.escape(value) : value.replace(/(["\\])/g, '\\$1');
        }

        getSummaryContainer(create) {
            let container = this.form.querySelector('.jy-error-summary');
            if (!container && create) {
                container = document.createElement('div');
                container.className = 'jy-error-summary';
                if (this.form.id) container.id = this.form.id + '_error_summary';
                container.setAttribute('role', 'alert');
                container.setAttribute('tabindex', '-1');
                container.hidden = true;
                container.innerHTML = '<p class="jy-error-summary-title"></p><ul class="jy-error-summary-list"></ul>';
                const firstSubmit = this.form.querySelector('button[type="submit"], input[type="submit"], button:not([type])');
                if (firstSubmit) {
                    firstSubmit.parentNode.insertBefore(container, firstSubmit);
                } else {
                    this.form.appendChild(container);
                }
            }
            if (container && !container.dataset.jySummaryWired) {
                container.dataset.jySummaryWired = '1';
                container.addEventListener('click', (e) => {
                    const link = e.target.closest('a[data-field]');
                    if (!link) return;
                    // Move programmatically: a bare hash navigation would add
                    // a history entry per click, so Back would walk the
                    // person's own error list instead of leaving the page.
                    e.preventDefault();
                    this.jumpToField(link.dataset.field, (link.getAttribute('href') || '').slice(1));
                });
            }
            return container;
        }

        showSummary() {
            if (!this.errorSummary || this.fieldErrors.size === 0) return;
            const container = this.getSummaryContainer(true);
            if (!container) return;
            this.summaryShown = true;
            // A validation pass is the whole truth for rule-covered fields:
            // clear leftovers (a server-side fill) this pass found valid.
            // Items for fields the client has no rule for (server-only checks
            // like uniqueness) are left alone.
            const list = container.querySelector('.jy-error-summary-list');
            if (list) {
                const ruleKeys = new Set(Object.keys(this.rules).map(r => this.summaryKey(r.replace(/['"]/g, ''))));
                for (const li of Array.from(list.children)) {
                    const key = li.dataset.field;
                    if (key && ruleKeys.has(key) && !this.fieldErrors.has(key)) li.remove();
                }
            }
            for (const key of this.fieldErrors.keys()) this.syncSummaryItem(key);
            this.updateSummaryChrome(container);
            // Land the person (and a screen reader) on the explanation, not
            // on a button that did nothing.
            try { container.focus({ preventScroll: false }); } catch (e) { container.focus(); }
        }

        syncSummaryItem(key) {
            const container = this.getSummaryContainer(false);
            if (!container) return;
            const list = container.querySelector('.jy-error-summary-list');
            if (!list) return;
            let item = null;
            for (const li of list.children) {
                if (li.dataset.field === key) { item = li; break; }
            }
            if (this.fieldErrors.has(key)) {
                if (!item) {
                    item = document.createElement('li');
                    item.dataset.field = key;
                    list.appendChild(item);
                }
                this.renderSummaryItem(item, key);
            } else if (item) {
                item.remove();
            }
            this.updateSummaryChrome(container);
        }

        renderSummaryItem(item, key) {
            const field = this.findFields(key)[0] || null;
            const link = document.createElement('a');
            link.dataset.field = key;
            link.href = '#' + (field ? this.resolveTargetId(key, field) : '');
            link.textContent = field ? this.resolveFieldLabel(key, field) : this.humanizeFieldName(key);
            item.textContent = '';
            item.appendChild(link);
            item.appendChild(document.createTextNode(' — ' + (this.fieldErrors.get(key) || '')));
        }

        updateSummaryChrome(container) {
            const list = container.querySelector('.jy-error-summary-list');
            const count = list ? list.children.length : 0;
            if (count === 0) {
                container.hidden = true;
                this.summaryShown = false;
                return;
            }
            const title = container.querySelector('.jy-error-summary-title');
            if (title) {
                const template = this.errorSummaryTitle
                    || (count === 1 ? '1 field needs attention:' : '{n} fields need attention:');
                title.textContent = template.replace('{n}', String(count));
            }
            container.hidden = false;
        }

        resolveTargetId(key, field) {
            const isGroup = field.type === 'radio' || (field.type === 'checkbox' && field.name.endsWith('[]'));
            // A group has no single input to point at — link its container.
            if (!isGroup && field.id) return field.id;
            const container = document.getElementById(key + '_container');
            if (container && this.form.contains(container)) return container.id;
            if (field.id) return field.id;
            // Assign one so the link always has somewhere to point.
            field.id = (this.form.id ? this.form.id + '_' : 'jy_') + key.replace(/[^A-Za-z0-9_-]/g, '_');
            return field.id;
        }

        resolveFieldLabel(key, field) {
            const isGroup = field.type === 'radio' || (field.type === 'checkbox' && field.name.endsWith('[]'));
            if (isGroup) {
                // The group's heading label carries no for= — read it from the
                // field container when there is one.
                const container = document.getElementById(key + '_container');
                if (container && this.form.contains(container)) {
                    const groupLabel = container.querySelector('.form-label');
                    if (groupLabel && groupLabel.textContent.trim()) {
                        return this.stripLabelDecoration(groupLabel.textContent);
                    }
                }
            }
            if (field.id) {
                const label = this.form.querySelector('label[for="' + this.cssEscape(field.id) + '"]');
                if (label && label.textContent.trim()) {
                    return this.stripLabelDecoration(label.textContent);
                }
            }
            const aria = field.getAttribute('aria-label');
            if (aria && aria.trim()) return aria.trim();
            if (field.placeholder && field.placeholder.trim()) return field.placeholder.trim();
            return this.humanizeFieldName(key);
        }

        stripLabelDecoration(text) {
            // Trailing required markers: "Email *", "Email:", "Email: *"
            return text.trim().replace(/[\s*:]+$/, '');
        }

        humanizeFieldName(key) {
            const words = key.replace(/[_-]+/g, ' ').trim();
            return words ? words.charAt(0).toUpperCase() + words.slice(1) : key;
        }

        jumpToField(key, targetId) {
            const field = this.findFields(key)[0] || null;
            let target = targetId ? document.getElementById(targetId) : null;
            if (!target) target = field;
            if (!target) return;
            // The target may have been hidden between validation and the
            // click — open ancestor <details> and un-hide ancestors so the
            // link never dead-ends.
            for (let el = target; el && el !== document.body; el = el.parentElement) {
                if (el.tagName === 'DETAILS') el.open = true;
                if (el.hidden) el.hidden = false;
            }
            const reduceMotion = window.matchMedia
                && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            target.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'center' });
            const focusable = (field && !field.disabled) ? field : target;
            try { focusable.focus({ preventScroll: true }); } catch (e) { /* pre-options browsers */ }
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

            // API-action mode: a /api/v1/ endpoint speaks the JSON envelope, not
            // the legacy 'true'/'false' text contract. POST the field data as
            // JSON with the browser-session CSRF header and read data.valid.
            if (typeof url === 'string' && url.indexOf('/api/v1/') === 0) {
                try {
                    const result = await joineryApi.post(url, data);
                    if (this.debug) {
                        console.log(`[Remote validation] API result:`, result);
                    }
                    return !!(result && result.valid);
                } catch (e) {
                    // An error envelope (401 expired session, 403, 422 boundary
                    // rejection) or network failure is not a validity verdict —
                    // fail open and let server-side validation decide, rather
                    // than misreporting it as this field's error.
                    console.error('Remote validation error:', e);
                    return true;
                }
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

    // General phone validator (maximally permissive - international compatible)
    JoineryValidator.addValidator("phone", function(value, element) {
        if (!value) return true;
        // First: reject if ANY invalid characters present (not digits, +, space, hyphen, dot, or parentheses)
        // This catches cases like "555-123-4567s"
        if (!/^[0-9+\s\-().]*$/.test(value)) {
            return false;
        }
        // Then: remove only valid formatting characters
        // Allows: +1 (555) 123-4567, +44 20 7946 0958, +33142685300, etc.
        var cleaned = value.replace(/[\s\-().]/g, '');
        // Require: optional + prefix, then at least 7 digits (minimum for international)
        return /^(\+)?[0-9]{7,}$/.test(cleaned);
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