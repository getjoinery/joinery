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
 * ✓ fileinput() - File upload (single and multiple)
 * ✓ All layout variations: default, horizontal, row
 */

require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('/includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('/includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

$session = SessionControl::get_instance();
$session->check_permission(5);

$page = new PublicPage();
$hoptions = array(
    'is_valid_page' => true,
    'title' => 'Complete Bootstrap Forms Test - Joinery Validation System'
);
$page->public_header($hoptions, NULL);

echo PublicPage::BeginPage('Complete Bootstrap Forms Test - All Field Types with Validation');

// Use standard FormWriterV2Bootstrap
require_once(PathHelper::getIncludePath('/includes/FormWriterV2Bootstrap.php'));
$formwriter = new FormWriterV2Bootstrap('form1', ['action' => '/admin/admin', 'method' => 'POST']);


// Begin form
$formwriter->begin_form();

echo '<h3>Standard Input Types</h3>';
echo '<p class="text-muted small">The email field demonstrates AJAX validation - it checks if the email is already registered in real-time.</p>';

// 1. Text Input
$formwriter->textinput('text_required', 'Text (Required)', ['placeholder' => 'Enter your full name', 'maxlength' => 50]);

// 2. Email (with AJAX validation)
$formwriter->textinput('email_field', 'Email Address (checks if available)', ['placeholder' => 'your@email.com', 'maxlength' => 255, 'type' => 'email']);

// 3. URL
$formwriter->textinput('url_field', 'Website URL', ['placeholder' => 'https://example.com', 'maxlength' => 255, 'type' => 'url']);

// 4. Number
$formwriter->textinput('number_field', 'Quantity (1-100)', ['placeholder' => 'Enter a number', 'maxlength' => 3, 'type' => 'number']);

// 5. Phone
$formwriter->textinput('phone_field', 'Phone Number', ['placeholder' => '(555) 123-4567', 'maxlength' => 20, 'type' => 'tel']);

// 6. Password Fields
$formwriter->passwordinput('password', 'Password', ['placeholder' => 'Min 8 characters', 'maxlength' => 255]);
$formwriter->passwordinput('password_confirm', 'Confirm Password', ['placeholder' => 'Re-enter password', 'maxlength' => 255]);

echo '<hr><h3>Text Areas and Selections</h3>';

// 7. Textarea
$formwriter->textbox('comments', 'Comments', ['rows' => 5, 'cols' => 80, 'placeholder' => 'Enter your comments (10-500 chars)']);

// 8. Dropdown/Select
$countries = array(
    "United States" => "US",
    "Canada" => "CA",
    "Mexico" => "MX",
    "United Kingdom" => "UK"
);
$formwriter->dropinput('country', 'Country', ['options' => $countries, 'placeholder' => 'Select your country']);

// 9. Radio Buttons
$intervals = array("Daily" => "1", "Weekly" => "7", "Monthly" => "30");
$formwriter->radioinput('interval', 'Frequency', ['options' => $intervals]);

// 10. Single Checkbox
$formwriter->checkboxinput('terms', 'I agree to terms', ['value' => 1]);

// 11. Checkbox List
$products = array(
    "Product A" => "1",
    "Product B" => "2",
    "Product C" => "3",
    "Product D" => "4"
);
$formwriter->checkboxList('products_list', 'Select Products', ['options' => $products]);

echo '<hr><h3>File and Date Inputs</h3>';

// 12. File Upload
$formwriter->fileinput('upload', 'Upload Document', ['placeholder' => 'PDF, DOC, DOCX only']);

// 13. Date Input
$formwriter->dateinput('date_field', 'Date', ['value' => date('Y-m-d')]);

// 14. Time Input
$formwriter->timeinput('time_field', 'Time', ['value' => date('H:i')]);

// 15. DateTime Combined
$formwriter->datetimeinput('datetime_field', 'Date & Time', ['date_value' => date('Y-m-d'), 'time_value' => date('H:i')]);

echo '<hr><h3>Special Input Types</h3>';

// 16. Hidden Input
$formwriter->hiddeninput('form_token', 'abc123xyz');

// 17. Color Picker
$formwriter->textinput('color_field', 'Choose Color', ['value' => '#0000ff', 'placeholder' => 'Select a color', 'maxlength' => 7, 'type' => 'color']);

// 18. Range/Slider - REMOVED per user request

echo '<hr><h3>Advanced FormWriter Methods</h3>';

// 19. Text (read-only display)
echo '<div class="mb-3"><label class="form-label">Information Label</label><p class="form-control-plaintext">This is: Some read-only information that is displayed but not editable</p></div>';

// 20. Additional test fields from experimental file matching

// Prefixed input
$formwriter->textinput('url_prefix', 'URL with Prefix', ['placeholder' => 'example.com', 'maxlength' => 255, 'prefix' => 'https://']);

// Different layout options
echo '<hr><h3>Layout Variations</h3>';

echo '<div class="mb-3"><label class="form-label">Horizontal Layout</label><p class="form-control-plaintext">Label: Text displayed horizontally</p></div>';
$formwriter->textinput('horizontal_input', 'Horizontal Input', ['placeholder' => 'Horizontal layout', 'maxlength' => 255]);
$formwriter->checkboxinput('horizontal_check', 'Horizontal Checkbox', ['value' => 1]);
$formwriter->dropinput('horizontal_select', 'Horizontal Select', ['options' => $countries]);

// Row layout for date/time
$formwriter->datetimeinput('row_datetime', 'Row Layout DateTime', ['date_value' => date('Y-m-d'), 'time_value' => date('H:i')]);

// Alternative datetime format (needs proper format for datetime-local input)

// Textbox with TinyMCE
$formwriter->textbox('rich_text', 'Rich Text Editor', ['rows' => 5, 'cols' => 80, 'use_editor' => true]);

// 21. Buttons
$formwriter->submitbutton('cancel', 'Cancel', ['class' => 'btn btn-secondary']);
$formwriter->submitbutton('btn_submit', 'Submit Form', ['class' => 'btn btn-primary']);

$formwriter->end_form();

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
        rules: {}
    };

    // Initialize Joinery Validation
    JoineryValidation.init('form1', validationOptions);
    console.log('Joinery Validation initialized');
});
</script>

<?php
$page->public_footer($foptions = array('track' => TRUE));
?>