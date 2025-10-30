<?php
/**
 * FormWriter v2 Bootstrap Test & Example File
 *
 * Demonstrates all features of FormWriter v2:
 * - Clean options array API
 * - Auto-filling values from array
 * - Auto-detection of validation from model field names
 * - Manual validation specification
 * - Built-in CSRF protection
 * - All field types
 * - Error handling
 * - Comparison with v1 code
 *
 * @version 2.0.0
 */

require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('/includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('/includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

$session = SessionControl::get_instance();
// No permission check for testing

$page = new PublicPage();
$hoptions = array(
    'is_valid_page' => true,
    'title' => 'FormWriter v2 Test - All Features'
);
$page->public_header($hoptions, NULL);

// Include JoineryValidator for client-side validation
echo '<script src="/assets/js/joinery-validate.js"></script>';

echo PublicPage::BeginPage('FormWriter v2 Test - Complete Feature Demonstration');

// Load FormWriter v2
require_once(PathHelper::getIncludePath('includes/FormWriterV2Bootstrap.php'));

// Helper function for rich text editor textarea
$trumbowyg_loaded = false;
function add_rich_text_textarea($formwriter, $name, $label, $options = []) {
    global $trumbowyg_loaded;

    // Load Trumbowyg scripts only once
    if (!$trumbowyg_loaded) {
        echo '<link rel="stylesheet" href="/assets/vendor/Trumbowyg-2-26/dist/ui/trumbowyg.min.css">';
        echo '<script src="/assets/vendor/Trumbowyg-2-26/dist/trumbowyg.min.js"></script>';
        echo '<script src="/assets/vendor/Trumbowyg-2-26/dist/plugins/cleanpaste/trumbowyg.cleanpaste.min.js"></script>';
        echo '<script src="/assets/vendor/Trumbowyg-2-26/dist/plugins/preformatted/trumbowyg.preformatted.min.js"></script>';
        echo '<script src="/assets/vendor/Trumbowyg-2-26/dist/plugins/allowtagsfrompaste/trumbowyg.allowtagsfrompaste.min.js"></script>';

        echo '<style>';
        echo '.trumbowyg-box, .trumbowyg-editor, .trumbowyg-textarea { height: 500px; }';
        echo '.trumbowyg-box.trumbowyg-fullscreen, .trumbowyg-box.trumbowyg-fullscreen .trumbowyg-editor, .trumbowyg-box.trumbowyg-fullscreen .trumbowyg-textarea { height: 100%; }';
        echo '</style>';

        $trumbowyg_loaded = true;
    }

    // Ensure the textarea has the html_editable class
    if (isset($options['class'])) {
        $options['class'] .= ' html_editable';
    } else {
        $options['class'] = 'form-control html_editable';
    }

    // Add the textarea field
    $formwriter->textarea($name, $label, $options);

    // Initialize Trumbowyg for this specific textarea
    echo '<script type="text/javascript">';
    echo '$(document).ready(function() {';
    echo '    $("#' . htmlspecialchars($name) . '").trumbowyg({';
    echo '        autogrow: false,';
    echo '        autogrowOnEnter: false,';
    echo '        btns: [';
    echo '            ["viewHTML"], ["undo", "redo"], ["formatting"],';
    echo '            ["strong", "em", "del"], ["superscript", "subscript"],';
    echo '            ["link"], ["insertImage"], ["preformatted"],';
    echo '            ["justifyLeft", "justifyCenter", "justifyRight", "justifyFull"],';
    echo '            ["unorderedList", "orderedList"], ["horizontalRule"],';
    echo '            ["removeformat"], ["fullscreen"]';
    echo '        ],';
    echo '        plugins: { allowTagsFromPaste: { allowedTags: ["p", "br","blockquote", "b", "i", "strong", "em", "ul", "li", "ol", "a","code","pre","h1","h2","h3","h4","h5","embed","table","tr","td","th","img","video"] } }';
    echo '    });';
    echo '});';
    echo '</script>';
}

// ============================================================================
// DEMONSTRATION: VALUES AUTO-FILLING
// ============================================================================

echo '<h2>1. Values Auto-Filling Demo</h2>';
echo '<p><strong>Notice:</strong> The fields below are pre-filled with sample data. This data was passed once when the form was created, and each field automatically populated.</p>';

// Prepare mock values (would normally come from model export)
$mock_values = [
    'usr_email' => 'test@example.com',
    'usr_first_name' => 'John',
    'usr_last_name' => 'Doe',
    'usr_phone' => '555-123-4567',
    'preference' => 'option2'
];

$formwriter1 = new FormWriterV2Bootstrap('values_demo_form', [
    'action' => '/test/submit',
    'method' => 'POST',
    'values' => $mock_values  // Pass all values at once!
]);

$formwriter1->begin_form();

echo '<div class="row">';
echo '<div class="col-md-6">';
// All these fields auto-fill from values array
$formwriter1->textinput('usr_email', 'Email', [
    'validation' => ['required' => true],
    'placeholder' => 'user@example.com'
]);
$formwriter1->textinput('usr_first_name', 'First Name', [
    'validation' => ['required' => true],
    'placeholder' => 'John'
]);
$formwriter1->textinput('usr_last_name', 'Last Name', [
    'validation' => ['required' => true],
    'placeholder' => 'Doe'
]);
$formwriter1->textinput('usr_phone', 'Phone', [
    'validation' => ['required' => true],
    'placeholder' => '555-123-4567'
]);
$formwriter1->textinput('address', 'Address (Empty - Placeholder Only)', [
    'validation' => ['required' => true],
    'placeholder' => '123 Main Street, City, State 12345'
]);
$formwriter1->radioinput('preference', 'Preference', [
    'options' => [
        'option1' => 'Option 1',
        'option2' => 'Option 2',
        'option3' => 'Option 3'
    ],
    'validation' => ['required' => true]
]);
echo '</div>';

// Show the code
echo '<div class="col-md-6">';
echo '<div class="card bg-light">';
echo '<div class="card-body">';
echo '<h5>Code:</h5>';
echo '<pre><code>';
echo htmlspecialchars('// ONE LINE to pass all values
$formwriter = new FormWriterV2Bootstrap(\'form\', [
    \'values\' => $mock_values
]);

// All fields auto-fill - no \'value\' parameter needed!
$formwriter->textinput(\'usr_email\', \'Email\');
$formwriter->textinput(\'usr_first_name\', \'First Name\');
$formwriter->textinput(\'usr_last_name\', \'Last Name\');');
echo '</code></pre>';
echo '</div></div></div></div>';

$formwriter1->submitbutton('submit', 'Test Submit');
$formwriter1->end_form();

// ============================================================================
// DEMONSTRATION: AUTO-DETECTION OF VALIDATION
// ============================================================================

echo '<hr><h2>2. Auto-Detection of Validation Demo</h2>';
echo '<p>Fields with model prefixes (usr_, pro_, evt_, etc.) automatically get validation from the model:</p>';

$formwriter2 = new FormWriterV2Bootstrap('auto_validation_form', [
    'action' => '/test/validate',
    'method' => 'POST',
    'debug' => true  // Enable console debug output for this form
]);

$formwriter2->begin_form();

echo '<div class="row">';
echo '<div class="col-md-6">';

// These auto-detect validation from User model (if usr_ fields exist in User::$field_specifications)
$formwriter2->textinput('usr_email', 'Email Address', [
    'placeholder' => 'user@example.com',
    'helptext' => 'Validation auto-detected from User model'
]);

$formwriter2->textinput('usr_first_name', 'First Name', [
    'placeholder' => 'John'
]);

// Manual field - specify validation explicitly
$formwriter2->textinput('custom_field', 'Custom Field', [
    'validation' => [
        'required' => true,
        'minlength' => 5,
        'maxlength' => 20,
        'messages' => [
            'required' => 'This custom field is required',
            'minlength' => 'Must be at least 5 characters'
        ]
    ],
    'helptext' => 'Manual validation (no model prefix)'
]);

echo '</div>';
echo '<div class="col-md-6">';
echo '<div class="card bg-light">';
echo '<div class="card-body">';
echo '<h5>Code:</h5>';
echo '<pre><code>';
echo htmlspecialchars('// AUTO-DETECTED - Field has usr_ prefix
$formwriter->textinput(\'usr_email\', \'Email\');
// No validation parameter needed!

// MANUAL - No model prefix
$formwriter->textinput(\'custom_field\', \'Label\', [
    \'validation\' => [
        \'required\' => true,
        \'minlength\' => 5
    ]
]);');
echo '</code></pre>';
echo '</div></div></div></div>';

$formwriter2->submitbutton('submit', 'Test Validation');
$formwriter2->end_form();

// ============================================================================
// DEMONSTRATION: ALL FIELD TYPES
// ============================================================================

echo '<hr><h2>3. All Field Types Demo</h2>';

$formwriter3 = new FormWriterV2Bootstrap('all_fields_form', [
    'action' => '/test/all-fields',
    'method' => 'POST',
    'enctype' => 'multipart/form-data'  // For file uploads
]);

$formwriter3->begin_form();

// Text Input
$formwriter3->textinput('text_field', 'Text Input', [
    'placeholder' => 'Enter text',
    'validation' => ['required' => true, 'minlength' => 3],
    'helptext' => 'At least 3 characters required'
]);

// Password Input
$formwriter3->passwordinput('password', 'Password', [
    'validation' => [
        'required' => true,
        'minlength' => 8,
        'pattern' => '/(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9])/',
        'messages' => [
            'pattern' => 'Must contain uppercase, lowercase, and number'
        ]
    ],
    'strength_meter' => true
]);

// Password Confirmation
$formwriter3->passwordinput('password_confirm', 'Confirm Password', [
    'validation' => [
        'required' => true,
        'matches' => 'password',
        'messages' => [
            'matches' => 'Passwords do not match'
        ]
    ]
]);

// Textarea (textbox in FormWriter V2)
$formwriter3->textbox('user_comments', 'Comments', [
    'rows' => 4,
    'placeholder' => 'Enter your comments',
    'validation' => ['required' => true, 'minlength' => 10, 'maxlength' => 500]
]);

// Dropdown (Static)
$formwriter3->dropinput('country', 'Country (Static)', [
    'options' => [
        '' => '-- Select Country --',
        'us' => 'United States',
        'ca' => 'Canada',
        'uk' => 'United Kingdom'
    ],
    'validation' => ['required' => true],
    'empty_option' => '-- Select Country --'
]);

// Dropdown with AJAX (requires admin login)
$formwriter3->dropinput('user_lookup', 'User Lookup (AJAX Test - Requires Admin Login)', [
    'options' => [],
    'validation' => ['required' => false],
    'ajaxendpoint' => '/ajax/user_search_ajax',
    'empty_option' => '-- Type 3+ characters to search users --'
]);

// Checkbox
$formwriter3->checkboxinput('accept_terms', 'I accept the terms and conditions', [
    'validation' => [
        'required' => true,
        'messages' => [
            'required' => 'You must accept the terms to continue'
        ]
    ]
]);

// Radio Buttons
$formwriter3->radioinput('subscription', 'Subscription Plan', [
    'options' => [
        'free' => 'Free',
        'basic' => 'Basic ($9.99/mo)',
        'premium' => 'Premium ($19.99/mo)'
    ],
    'validation' => ['required' => true]
]);

// Date Input
$formwriter3->dateinput('start_date', 'Start Date', [
    'min' => '2025-01-01',
    'max' => '2025-12-31',
    'validation' => ['required' => true, 'date' => true]
]);

// Time Input
$formwriter3->timeinput('meeting_time', 'Meeting Time', [
    'validation' => ['required' => true],
    'helptext' => 'Enter hour (1-12), minute, and AM/PM'
]);

// Date and Time Input (Separate Fields)
$formwriter3->datetimeinput('event_datetime', 'Event Date & Time', [
    'validation' => ['required' => true],
    'helptext' => 'Select both date and time for the event'
]);

// Combined DateTime Input
$formwriter3->datetimeinput('deadline', 'Project Deadline', [
    'validation' => ['required' => true],
    'helptext' => 'Pick a date and time for project completion'
]);

// Rich Text Editor
$formwriter3->textbox('rich_text_editor', 'Rich Text Editor', [
    'rows' => 5,
    'cols' => 80,
    'placeholder' => 'Enter your rich text content',
    'htmlmode' => 'yes'
]);

// File Input
$formwriter3->fileinput('document', 'Upload Document', [
    'accept' => '.pdf,.doc,.docx',
    'helptext' => 'PDF or Word documents only'
]);

// Hidden Input
$formwriter3->hiddeninput('form_id', '', ['value' => 'test123']);

// ==============================================
// VISIBILITY & CUSTOM SCRIPT TESTS
// ==============================================
echo '<hr><h3>FormWriter V2 Visibility Rules & Custom Scripts Tests</h3>';
echo '<p>Testing the new visibility and custom script features with FormWriter V2:</p>';

// Test 1: Simple toggle using visibility rules
$formwriter3->dropinput('test_type_v2_1', 'Test Type (Simple Toggle)', [
    'options' => ['Option A' => 'option_a', 'Option B' => 'option_b'],
    'value' => 'option_a',
    'visibility_rules' => [
        'option_a' => ['show' => ['test_field_v2_1a'], 'hide' => ['test_field_v2_1b']],
        'option_b' => ['show' => ['test_field_v2_1b'], 'hide' => ['test_field_v2_1a']]
    ]
]);

$formwriter3->textinput('test_field_v2_1a', 'Field A (for Option A)', [
    'placeholder' => 'Visible when Option A selected'
]);

$formwriter3->textinput('test_field_v2_1b', 'Field B (for Option B)', [
    'placeholder' => 'Visible when Option B selected'
]);

// Test 2: Custom script with conditional logic
$formwriter3->dropinput('test_type_v2_2', 'Size Selector (Custom Script)', [
    'options' => ['Small' => 'small', 'Medium' => 'medium', 'Large' => 'large'],
    'value' => 'small',
    'custom_script' => '
        const size = this.value;
        const price = document.getElementById("test_price_v2");
        const warning = document.getElementById("test_bulk_warning_v2");

        if (size === "small") {
            if (price) price.value = "9.99";
            if (warning) warning.style.display = "none";
        } else if (size === "medium") {
            if (price) price.value = "19.99";
            if (warning) warning.style.display = "none";
        } else if (size === "large") {
            if (price) price.value = "29.99";
            if (warning) warning.style.display = "";
        }
    '
]);

$formwriter3->textinput('test_price_v2', 'Calculated Price', [
    'value' => '9.99',
    'readonly' => true
]);

$formwriter3->textinput('test_bulk_warning_v2', 'Bulk Order Warning', [
    'value' => 'Bulk orders require manager approval',
    'readonly' => true,
    'helptext' => 'Shown only for large size'
]);

// Test 3: Form-level script for cross-field logic
$formwriter3->addReadyScript('
    const countryV2 = document.getElementById("test_country_v2");
    if (countryV2) {
        countryV2.addEventListener("change", function() {
            const country = this.value;
            // Target container elements to hide/show both label and input
            const stateContainer = document.getElementById("test_state_v2_container");
            const zipContainer = document.getElementById("test_zip_v2_container");
            const customContainer = document.getElementById("test_custom_v2_container");

            // Also get the input elements for placeholder changes
            const stateEl = document.getElementById("test_state_v2");
            const zipEl = document.getElementById("test_zip_v2");

            if (!stateContainer || !zipContainer || !customContainer || !stateEl || !zipEl) return;

            if (country === "us") {
                stateContainer.style.display = "";
                zipContainer.style.display = "";
                customContainer.style.display = "none";
                stateEl.placeholder = "State";
                zipEl.placeholder = "ZIP Code (5 digits)";
            } else if (country === "ca") {
                stateContainer.style.display = "";
                zipContainer.style.display = "";
                customContainer.style.display = "none";
                stateEl.placeholder = "Province";
                zipEl.placeholder = "Postal Code";
            } else {
                stateContainer.style.display = "none";
                zipContainer.style.display = "none";
                customContainer.style.display = "";
            }
        });

        // Trigger on load
        const event = new Event("change");
        countryV2.dispatchEvent(event);
    }
');

$formwriter3->dropinput('test_country_v2', 'Country', [
    'options' => ['United States' => 'us', 'Canada' => 'ca', 'Other' => 'other'],
    'value' => 'us'
]);

$formwriter3->textinput('test_state_v2', 'State/Province', [
    'placeholder' => 'State'
]);

$formwriter3->textinput('test_zip_v2', 'ZIP/Postal Code', [
    'placeholder' => 'ZIP Code (5 digits)'
]);

$formwriter3->textinput('test_custom_v2', 'Custom Location', [
    'placeholder' => 'Enter your location'
]);

echo '<div class="alert alert-info mt-3">';
echo '<strong>V2 Test Summary:</strong><ul>';
echo '<li><strong>Test 1:</strong> Toggle fields with Option A/B selector</li>';
echo '<li><strong>Test 2:</strong> Size selector updates price automatically</li>';
echo '<li><strong>Test 3:</strong> Country selector changes field labels and visibility</li>';
echo '</ul></div>';

// Submit Button
$formwriter3->submitbutton('submit', 'Submit Form', ['class' => 'btn btn-primary btn-lg']);

$formwriter3->end_form();

// ============================================================================
// DEMONSTRATION: VALIDATION TYPE SHORTHANDS
// ============================================================================

echo '<hr><h2>4. Validation Type Shorthands Demo</h2>';
echo '<p>Use predefined validation types instead of writing rules repeatedly:</p>';

$formwriter4 = new FormWriterV2Bootstrap('validation_types_form', [
    'action' => '/test/types',
    'method' => 'POST',
    'debug' => true
]);

$formwriter4->begin_form();

echo '<div class="row">';
echo '<div class="col-md-6">';

// Email shorthand
$formwriter4->textinput('email', 'Email', [
    'validation' => ['required' => true, 'email' => true],  // Shorthand for email validation
    'placeholder' => 'user@example.com'
]);

// Phone shorthand
$formwriter4->textinput('phone', 'Phone', [
    'validation' => ['required' => true, 'phone' => true],  // Shorthand for phone validation
    'placeholder' => '(555) 123-4567'
]);

// ZIP shorthand
$formwriter4->textinput('zip', 'ZIP Code', [
    'validation' => ['required' => true, 'zip' => true],  // Shorthand for ZIP validation
    'placeholder' => '12345'
]);

// URL shorthand
$formwriter4->textinput('website', 'Website', [
    'validation' => ['required' => true, 'url' => true],  // Shorthand for URL validation
    'placeholder' => 'https://example.com'
]);

// Number shorthand
$formwriter4->textinput('age', 'Age', [
    'validation' => ['required' => true, 'number' => true],  // Shorthand for number validation
    'placeholder' => '25'
]);

echo '</div>';
echo '<div class="col-md-6">';
echo '<div class="card bg-light">';
echo '<div class="card-body">';
echo '<h5>Code:</h5>';
echo '<pre><code>';
echo htmlspecialchars('// Use validation type shorthands
$formwriter->textinput(\'email\', \'Email\', [
    \'validation\' => \'email\'  // Simple!
]);

$formwriter->textinput(\'phone\', \'Phone\', [
    \'validation\' => \'phone\'
]);

$formwriter->textinput(\'zip\', \'ZIP\', [
    \'validation\' => \'zip\'
]);');
echo '</code></pre>';
echo '</div></div></div></div>';

$formwriter4->submitbutton('submit', 'Test Types');
$formwriter4->end_form();

// ============================================================================
// DEMONSTRATION: CSRF PROTECTION
// ============================================================================

echo '<hr><h2>5. CSRF Protection Demo</h2>';
echo '<p>CSRF tokens are automatically generated and validated for POST forms:</p>';

$formwriter5 = new FormWriterV2Bootstrap('csrf_demo_form', [
    'action' => '/test/csrf',
    'method' => 'POST'  // CSRF is ON by default for POST
]);

echo '<div class="alert alert-info">';
echo '<strong>Note:</strong> Check the form source - you\'ll see a hidden _csrf_token field automatically added!';
echo '</div>';

$formwriter5->begin_form();
$formwriter5->textinput('test_field', 'Test Field', ['validation' => ['required' => true]]);
$formwriter5->submitbutton('submit', 'Submit with CSRF');
$formwriter5->end_form();

echo '<div class="card bg-light mt-3">';
echo '<div class="card-body">';
echo '<h5>Server-side validation:</h5>';
echo '<pre><code>';
echo htmlspecialchars('// In your logic file
if ($_SERVER[\'REQUEST_METHOD\'] === \'POST\') {
    $formwriter = new FormWriterV2Bootstrap(\'form_id\', $_POST);

    // Validate CSRF first
    if (!$formwriter->validateCSRF($_POST)) {
        return LogicResult::Error(\'Security token expired\');
    }

    // Then validate form data
    if (!$formwriter->validate($_POST)) {
        return LogicResult::Error(\'Validation failed\',
            $formwriter->getErrors());
    }

    // Process form...
}');
echo '</code></pre>';
echo '</div></div>';

// ============================================================================
// COMPARISON: V1 vs V2
// ============================================================================

echo '<hr><h2>6. V1 vs V2 Comparison</h2>';

echo '<div class="row">';
echo '<div class="col-md-6">';
echo '<h4>FormWriter v1 (Old Way)</h4>';
echo '<pre><code>';
echo htmlspecialchars('// V1 - Verbose and repetitive
$formwriter->textinput(
    \'email\',           // field name
    \'text\',            // type
    20,                 // size
    $user->get(\'usr_email\'),  // value
    \'\',                // id
    255,                // maxlength
    \'\',                // extra
    true,               // required
    false,              // readonly
    \'Email Address\',  // label
    \'\',                // placeholder
    false               // disabled
);

// Separate validation setup
$validator = new Validator();
if (!$validator->validateEmail($_POST[\'email\'])) {
    // Handle error
}');
echo '</code></pre>';
echo '</div>';

echo '<div class="col-md-6">';
echo '<h4>FormWriter v2 (New Way)</h4>';
echo '<pre><code>';
echo htmlspecialchars('// V2 - Clean and simple
$formwriter = new FormWriterV2Bootstrap(\'form\', [
    \'values\' => $user->export_as_array()
]);

$formwriter->textinput(\'usr_email\', \'Email Address\');
// That\'s it! Value auto-filled AND validation
// auto-detected from User model!

// Validation happens automatically
if (!$formwriter->validate($_POST)) {
    $errors = $formwriter->getErrors();
}');
echo '</code></pre>';
echo '</div>';
echo '</div>';

// ============================================================================
// SUCCESS METRICS
// ============================================================================

echo '<hr><h2>FormWriter v2 Benefits</h2>';
echo '<div class="row">';

echo '<div class="col-md-4">';
echo '<div class="card">';
echo '<div class="card-body">';
echo '<h5 class="card-title">70-80% Less Code</h5>';
echo '<p class="card-text">Options arrays replace 20+ parameters. Values array eliminates repetitive value assignments.</p>';
echo '</div></div></div>';

echo '<div class="col-md-4">';
echo '<div class="card">';
echo '<div class="card-body">';
echo '<h5 class="card-title">Zero Configuration</h5>';
echo '<p class="card-text">Auto-detection means most forms need NO validation specification at all.</p>';
echo '</div></div></div>';

echo '<div class="col-md-4">';
echo '<div class="card">';
echo '<div class="card-body">';
echo '<h5 class="card-title">Unified Validation</h5>';
echo '<p class="card-text">Single source of truth - same rules for frontend JS, backend PHP, and model save().</p>';
echo '</div></div></div>';

echo '</div>';

echo '<hr>';
echo '<div class="alert alert-success">';
echo '<strong>Phase 1 Implementation:</strong> FormWriter v2 is completely separate from v1. ';
echo 'All existing code continues to work unchanged. Use FormWriterV2Bootstrap directly for new forms.';
echo '</div>';

echo PublicPage::EndPage();

$page->public_footer();
