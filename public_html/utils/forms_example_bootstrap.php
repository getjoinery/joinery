<?php
/**
 * Complete Bootstrap Forms Test - Joinery Validation System
 *
 * Tests ALL FormWriter field types with comprehensive validation rules.
 * This matches all fields from forms_example_bootstrap_experimental.php
 *
 * FIELD TYPES INCLUDED:
 * ✓ text() - Read-only text display
 * ✓ hiddeninput() - Hidden fields
 * ✓ textinput() - All types: text, email, tel, url, number, password
 * ✓ passwordinput() - Password with confirmation
 * ✓ textbox() - Textarea with/without editor
 * ✓ checkboxinput() - Single checkbox
 * ✓ checkboxList() - Multiple checkboxes
 * ✓ radioinput() - Radio button group
 * ✓ dropinput() - Select dropdown
 * ✓ dateinput() - Date picker
 * ✓ timeinput() - Time picker
 * ✓ datetimeinput() - Separate date & time fields
 * ✓ datetimeinput2() - Combined datetime field
 * ✓ fileinput() - File upload (single and multiple)
 * ✓ All layout variations: default, horizontal, row
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
    'title' => 'Complete Bootstrap Forms Test - Joinery Validation System'
);
$page->public_header($hoptions, NULL);

echo PublicPage::BeginPage('Complete Bootstrap Forms Test - All Field Types with Validation');

// Use standard FormWriterBootstrap
require_once(PathHelper::getIncludePath('/includes/FormWriterBootstrap.php'));
$formwriter = new FormWriterBootstrap('form1');

// ==============================================
// COMPREHENSIVE VALIDATION RULES - ALL FIELDS
// ==============================================
$validation_rules = array();

// === STANDARD INPUT TYPES ===

// 1. Text Input - Required
$validation_rules['text_required']['required']['value'] = 'true';
$validation_rules['text_required']['minlength']['value'] = '3';
$validation_rules['text_required']['maxlength']['value'] = '50';

// 2. Email Input (with AJAX validation to check if email is available)
$validation_rules['email_field']['required']['value'] = 'true';
$validation_rules['email_field']['email']['value'] = 'true';
// Remote validation - send as 'usr_email' parameter which the endpoint expects
// Use clean URL without .php extension
$validation_rules['email_field']['remote']['value'] = json_encode(array(
    'url' => '/ajax/email_check_ajax',
    'dataFieldName' => 'usr_email'  // Send the value as 'usr_email' parameter
));
// Note: Enter an email that exists in your usr_users table to see validation fail

// 3. URL Input
$validation_rules['url_field']['required']['value'] = 'true';
$validation_rules['url_field']['url']['value'] = 'true';

// 4. Number Input
$validation_rules['number_field']['required']['value'] = 'true';
$validation_rules['number_field']['number']['value'] = 'true';
$validation_rules['number_field']['min']['value'] = '1';
$validation_rules['number_field']['max']['value'] = '100';

// 5. Phone Number (with custom validator)
$validation_rules['phone_field']['required']['value'] = 'true';
$validation_rules['phone_field']['phoneUS']['value'] = 'true';

// 6. Password & Confirm
$validation_rules['password']['required']['value'] = 'true';
$validation_rules['password']['minlength']['value'] = '8';
$validation_rules['password_confirm']['required']['value'] = 'true';
$validation_rules['password_confirm']['equalTo']['value'] = 'password';  // Field name, not selector

// 7. Textarea
$validation_rules['comments']['required']['value'] = 'true';
$validation_rules['comments']['minlength']['value'] = '10';
$validation_rules['comments']['maxlength']['value'] = '500';

// 8. Select/Dropdown
$validation_rules['country']['required']['value'] = 'true';

// 9. Radio Buttons
$validation_rules['interval']['required']['value'] = 'true';

// 10. Checkbox (single)
$validation_rules['terms']['required']['value'] = 'true';

// 11. Checkbox List
$validation_rules['products_list']['required']['value'] = 'true';

// 12. File Upload
$validation_rules['upload']['required']['value'] = 'true';

// 13. Date Input
$validation_rules['date_field']['required']['value'] = 'true';
$validation_rules['date_field']['date']['value'] = 'true';

// 14. Time Input
$validation_rules['time_field']['required']['value'] = 'true';
$validation_rules['time_field']['time']['value'] = 'true';

// 15. DateTime Input
$validation_rules['datetime_field']['required']['value'] = 'true';

// 16. Hidden Input (usually not validated but included for completeness)
// No validation

// 17. Color Picker
$validation_rules['color_field']['required']['value'] = 'true';

// 18. Range/Slider - REMOVED per user request

// Layout Variation Fields
$validation_rules['horizontal_input']['required']['value'] = 'true';
$validation_rules['horizontal_check']['required']['value'] = 'true';  // Single checkbox, no brackets
$validation_rules['horizontal_select']['required']['value'] = 'true';
$validation_rules['row_datetime']['required']['value'] = 'true';
$validation_rules['combined_datetime']['required']['value'] = 'true';
$validation_rules['combined_datetime_h']['required']['value'] = 'true';
$validation_rules['rich_text']['required']['value'] = 'true';
$validation_rules['url_prefix']['required']['value'] = 'true';

// DO NOT output jQuery validation - we're using pure Joinery validation
// echo $formwriter->set_validate($validation_rules);

// Begin form
echo $formwriter->begin_form('form1', 'POST', '/admin/admin', true);

echo '<h3>Standard Input Types</h3>';
echo '<p class="text-muted small">The email field demonstrates AJAX validation - it checks if the email is already registered in real-time.</p>';

// 1. Text Input
echo $formwriter->textinput('Text (Required)', 'text_required', NULL, 100, NULL, 'Enter your full name', 50);

// 2. Email (with AJAX validation)
echo $formwriter->textinput('Email Address (checks if available)', 'email_field', NULL, 100, '', 'your@email.com', 255, '', TRUE, FALSE, 'email');

// 3. URL
echo $formwriter->textinput('Website URL', 'url_field', NULL, 100, '', 'https://example.com', 255, '', TRUE, FALSE, 'url');

// 4. Number
echo $formwriter->textinput('Quantity (1-100)', 'number_field', NULL, 100, '', 'Enter a number', 3, '', TRUE, FALSE, 'number');

// 5. Phone
echo $formwriter->textinput('Phone Number', 'phone_field', NULL, 100, '', '(555) 123-4567', 20, '', TRUE, FALSE, 'tel');

// 6. Password Fields
echo $formwriter->passwordinput('Password', 'password', NULL, 100, '', 'Min 8 characters', 255, '', TRUE, FALSE, 'password');
echo $formwriter->passwordinput('Confirm Password', 'password_confirm', NULL, 100, '', 'Re-enter password', 255, '', TRUE, FALSE, 'password');

echo '<hr><h3>Text Areas and Selections</h3>';

// 7. Textarea
echo $formwriter->textbox('Comments', 'comments', '', 5, 80, '', 'Enter your comments (10-500 chars)', 'no');

// 8. Dropdown/Select
$countries = array(
    "United States" => "US",
    "Canada" => "CA",
    "Mexico" => "MX",
    "United Kingdom" => "UK"
);
echo $formwriter->dropinput("Country", "country", "", $countries, NULL, 'Select your country', TRUE);

// 8b. AJAX Dropdown Test
$user_options = array();
echo $formwriter->dropinput("User Lookup (AJAX Test)", "user_search", "", $user_options, NULL, 'Start typing to search users', FALSE, FALSE, '/ajax/user_search_ajax');

// 9. Radio Buttons
$intervals = array("Daily" => "1", "Weekly" => "7", "Monthly" => "30");
echo $formwriter->radioinput("Frequency", "interval", NULL, $intervals, '', array(), array(), 'Choose one');

// 10. Single Checkbox
echo $formwriter->checkboxinput("I agree to terms", "terms", "", "left", NULL, 1, "You must agree to continue");

// 11. Checkbox List
$products = array(
    "Product A" => "1",
    "Product B" => "2",
    "Product C" => "3",
    "Product D" => "4"
);
echo $formwriter->checkboxList("Select Products", "products_list", "", $products, array(), array(), array());

echo '<hr><h3>File and Date Inputs</h3>';

// 12. File Upload
echo $formwriter->fileinput("Upload Document", "upload", "", 30, 'PDF, DOC, DOCX only');

// 13. Date Input
echo $formwriter->dateinput("Date", "date_field", NULL, 30, date('Y-m-d'), "", 10);

// 14. Time Input
echo $formwriter->timeinput("Time", "time_field", NULL, date('H:i'), "");

// 15. DateTime Combined
echo $formwriter->datetimeinput("Date & Time", "datetime_field", NULL, date('Y-m-d H:i'), "", "", "");

echo '<hr><h3>Special Input Types</h3>';

// 16. Hidden Input
echo $formwriter->hiddeninput('form_token', 'abc123xyz');

// 17. Color Picker
echo $formwriter->textinput('Choose Color', 'color_field', NULL, 100, '#0000ff', 'Select a color', 7, '', TRUE, FALSE, 'color');

// 18. Range/Slider - REMOVED per user request

echo '<hr><h3>Advanced FormWriter Methods</h3>';

// 19. Text (read-only display)
echo $formwriter->text('Information Label', 'This is:', 'Some read-only information that is displayed but not editable', NULL);

// 20. Additional test fields from experimental file matching

// Prefixed input
echo $formwriter->textinput('URL with Prefix', 'url_prefix', NULL, 100, NULL, 'example.com', 255, '', TRUE, 'https://', 'text');

// Different layout options
echo '<hr><h3>Layout Variations</h3>';

echo $formwriter->text('Horizontal Layout', 'Label:', 'Text displayed horizontally', NULL, 'horizontal');
echo $formwriter->textinput('Horizontal Input', 'horizontal_input', NULL, 100, NULL, 'Horizontal layout', 255, '', TRUE, FALSE, 'text', 'horizontal');
echo $formwriter->checkboxinput("Horizontal Checkbox", "horizontal_check", "", "left", NULL, 1, "Check this box", 'horizontal');
echo $formwriter->dropinput("Horizontal Select", "horizontal_select", "", $countries, NULL, '', TRUE, FALSE, FALSE, FALSE, 'horizontal');

// Row layout for date/time
echo $formwriter->datetimeinput('Row Layout DateTime', 'row_datetime', '', date('Y-m-d'), date('H:i'), '', '','row');

// Alternative datetime format (needs proper format for datetime-local input)
echo $formwriter->datetimeinput2('Combined DateTime', 'combined_datetime', '', date('Y-m-d\TH:i'), '', '', '', 'default');
echo $formwriter->datetimeinput2('Combined DateTime Horizontal', 'combined_datetime_h', '', date('Y-m-d\TH:i'), '', '', '', 'horizontal');

// Textbox with TinyMCE
echo $formwriter->textbox('Rich Text Editor', 'rich_text', '', 5, 80, NULL, '', 'yes');

// ==============================================
// VISIBILITY & CUSTOM SCRIPT TESTS
// ==============================================
echo '<hr><h3>FormWriter Visibility Rules & Custom Scripts Tests</h3>';
echo '<p>The following test fields demonstrate the new visibility and custom script features:</p>';

// Test 1: Simple toggle between two fields using visibility rules
$test_options_1 = array();
echo $formwriter->dropinput('Test Type (Simple Toggle)', 'test_type_1', array(
    'visibility_rules' => array(
        'option_a' => array('show' => array('test-field-1a'), 'hide' => array('test-field-1b')),
        'option_b' => array('show' => array('test-field-1b'), 'hide' => array('test-field-1a'))
    )
), $test_options_1, '', 'Select an option', true, false);
$test_options_1 = array('option_a' => 'Option A', 'option_b' => 'Option B');
echo $formwriter->dropinput('Test Type (Simple Toggle)', 'test-type-1', array(
    'visibility_rules' => array(
        'option_a' => array('show' => array('test-field-1a'), 'hide' => array('test-field-1b')),
        'option_b' => array('show' => array('test-field-1b'), 'hide' => array('test-field-1a'))
    )
), $test_options_1, 'option_a', 'Select an option', true, false);

// Create the target fields
echo $formwriter->textinput('Field A (shown for Option A)', 'test-field-1a', '', 30, '', 'This field is visible when Option A is selected');
echo $formwriter->textinput('Field B (shown for Option B)', 'test-field-1b', '', 30, '', 'This field is visible when Option B is selected');

// Test 2: Custom script with conditional logic
$test_options_2 = array('small' => 'Small', 'medium' => 'Medium', 'large' => 'Large');
echo $formwriter->dropinput('Test Type (Custom Script)', 'test-type-2', array(
    'custom_script' => '
        const size = this.value;
        const price = document.getElementById("test-price");
        const warning = document.getElementById("test-bulk-warning");

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
), $test_options_2, 'small', 'Select a size', true, false);

echo $formwriter->textinput('Price', 'test-price', '', 10, '9.99', 'Price updates based on size');
echo $formwriter->textinput('Bulk Warning', 'test-bulk-warning', '', 50, 'Bulk orders require approval', 'Shown only for Large size', 255, '', true);

// Test 3: Form-level script for cross-field logic
$formwriter->addReadyScript('
    document.getElementById("test-country").addEventListener("change", function() {
        const country = this.value;
        const stateEl = document.getElementById("test-state");
        const zipEl = document.getElementById("test-zip");
        const customEl = document.getElementById("test-custom-location");

        if (!stateEl || !zipEl || !customEl) return;

        if (country === "us") {
            stateEl.style.display = "";
            stateEl.placeholder = "State";
            zipEl.style.display = "";
            zipEl.placeholder = "ZIP Code (5 digits)";
            customEl.style.display = "none";
        } else if (country === "ca") {
            stateEl.style.display = "";
            stateEl.placeholder = "Province";
            zipEl.style.display = "";
            zipEl.placeholder = "Postal Code";
            customEl.style.display = "none";
        } else {
            stateEl.style.display = "none";
            zipEl.style.display = "none";
            customEl.style.display = "";
        }
    });

    // Initialize on load
    const countryEl = document.getElementById("test-country");
    if (countryEl) {
        const event = new Event("change");
        countryEl.dispatchEvent(event);
    }
');

$country_options = array('us' => 'United States', 'ca' => 'Canada', 'other' => 'Other');
echo $formwriter->dropinput('Country', 'test-country', '', $country_options, 'us', 'Select your country', true, false);
echo $formwriter->textinput('State/Province', 'test-state', '', 30, '', 'Enter your state or province');
echo $formwriter->textinput('ZIP/Postal Code', 'test-zip', '', 15, '', 'Enter your ZIP or postal code');
echo $formwriter->textinput('Custom Location (for Other)', 'test-custom-location', '', 50, '', 'Enter your location');

echo '<div class="alert alert-info mt-3">';
echo '<strong>Test Summary:</strong><ul>';
echo '<li><strong>Test 1 (Simple Toggle):</strong> Select between Option A and B to see fields toggle</li>';
echo '<li><strong>Test 2 (Custom Script):</strong> Size selection updates price field automatically</li>';
echo '<li><strong>Test 3 (Form-Level):</strong> Country selection changes field labels and visibility</li>';
echo '</ul>';
echo '<p>Check browser console (F12) for any warnings or errors.</p>';
echo '</div>';

// 21. Buttons
echo $formwriter->start_buttons();
echo $formwriter->new_form_button('Cancel', 'secondary');
echo $formwriter->new_form_button('Submit Form', 'primary');
echo $formwriter->end_buttons();

echo $formwriter->end_form(true);

echo PublicPage::EndPage();

// Include joinery-validate.js
?>
<script>
// Enable debug mode for detailed logging
window.JOINERY_VALIDATE_DEBUG = true;
console.log('Debug mode enabled for Joinery Validation');
</script>
<script src="/assets/js/joinery-validate.js?v=<?php echo time(); ?>"></script>

<!-- Initialize Joinery Validation -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    console.log('Initializing Joinery Validation...');

    // Pure Joinery validation rules - no jQuery
    const validationOptions = {
        debug: true,
        messages: {
            email_field: {
                remote: "This email address is already registered."
            }
        },
        rules: {
            <?php
            // Output the rules in JavaScript format
            $first = true;
            foreach ($validation_rules as $fieldName => $rules) {
                if (!$first) echo ",\n            ";
                $first = false;
                // Use json_encode to properly quote field names with special characters
                echo json_encode($fieldName) . ': {';
                $firstRule = true;
                foreach ($rules as $ruleName => $ruleData) {
                    if (!$firstRule) echo ', ';
                    $firstRule = false;
                    echo $ruleName . ': ';
                    if (isset($ruleData['value'])) {
                        $value = $ruleData['value'];
                        echo ($value === 'true' || $value === 'false') ? $value : '"' . addslashes($value) . '"';
                    } else {
                        echo 'true';
                    }
                }
                echo '}';
            }
            ?>
        }
    };

    // Initialize Joinery Validation
    JoineryValidation.init('form1', validationOptions);
    console.log('Joinery Validation initialized');
});
</script>

<?php
$page->public_footer($foptions = array('track' => TRUE));
?>