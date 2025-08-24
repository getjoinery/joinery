<?php
// Test fixture to create email template for testing
require_once(__DIR__ . '/../../../includes/PathHelper.php');
PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('data/email_templates_class.php');

try {
    // Create test template with subject
    $testTemplate = new EmailTemplateStore(NULL);
    $testTemplate->set('emt_name', 'subject_validation_test');
    $testTemplate->set('emt_type', 2); // Inner template
    $testTemplate->set('emt_subject', 'Test Subject - ID *test_id*');
    $testTemplate->set('emt_body', '<h1>Test Email</h1><p>Test ID: *test_id*</p>');
    $testTemplate->save();
    
    echo "Subject test template created successfully.\n";
    
} catch (Exception $e) {
    echo "Error creating subject test template: " . $e->getMessage() . "\n";
    exit(1);
}
?>