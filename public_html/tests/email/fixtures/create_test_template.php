<?php
// Test fixture to create email template for testing
require_once(__DIR__ . '/../../../includes/PathHelper.php');
PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('data/email_templates_class.php');

try {
    // Create test template with known subject
    $testTemplate = new EmailTemplateStore(NULL);
    $testTemplate->set('emt_name', 'test_template_with_subject');
    $testTemplate->set('emt_type', 2); // Inner template
    $testTemplate->set('emt_body', 'subject: Test Email - *test_id*
<h1>Test Email</h1>
<p>This is a test email with ID: *test_id*</p>
<p>Timestamp: *timestamp*</p>
<p>Activation code: *act_code*</p>');
    $testTemplate->save();
    
    echo "Test template 'test_template_with_subject' created successfully.\n";
    
    // Also create a template without subject for testing
    $testTemplateNoSubject = new EmailTemplateStore(NULL);
    $testTemplateNoSubject->set('emt_name', 'test_template_no_subject');
    $testTemplateNoSubject->set('emt_type', 2); // Inner template
    $testTemplateNoSubject->set('emt_body', '<h1>Test Email Without Subject</h1>
<p>This template has no subject line.</p>
<p>Test ID: *test_id*</p>');
    $testTemplateNoSubject->save();
    
    echo "Test template 'test_template_no_subject' created successfully.\n";
    
} catch (Exception $e) {
    echo "Error creating test templates: " . $e->getMessage() . "\n";
    exit(1);
}
?>