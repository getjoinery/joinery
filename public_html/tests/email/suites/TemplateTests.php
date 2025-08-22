<?php
// tests/email/suites/TemplateTests.php
class TemplateTests {
    private array $config;
    private $runner;
    
    public function __construct(array $config, $runner = null) {
        $this->config = $config;
        $this->runner = $runner;
    }
    
    public function run(): array {
        $results = [];
        
        $results['basic_processing'] = $this->testBasicProcessing();
        $results['variable_replacement'] = $this->testVariableReplacement();
        $results['content_generation'] = $this->testContentGeneration();
        $results['getter_methods'] = $this->testGetterMethods();
        $results['subject_extraction'] = $this->testSubjectExtraction();
        $results['subject_priority'] = $this->testSubjectPriority();
        
        return $results;
    }
    
    private function testBasicProcessing(): array {
        try {
            // Use a working template name from the codebase
            $email = new EmailTemplate('activation_content');
            
            // Follow the working pattern from Activation::email_activate_send
            $settings = Globalvars::get_instance();
            $email->email_from = $settings->get_setting('defaultemail');
            $email->email_from_name = $settings->get_setting('defaultemailname');
            $email->add_recipient('test@example.com', 'Test User');
            
            // Don't pass 'subject' unless the template uses *subject*
            $values = [
                'act_code' => 'TEST123',
                'resend' => false,
            ];
            
            $email->fill_template($values);
            
            // Check if the template extracted a subject
            $subject = $email->getEmailSubject();
            $hasSubject = !empty($subject);
            
            // Get debug info
            $debugInfo = [
                'hasContent_result' => $email->hasContent(),
                'subject_extracted' => $subject,
                'subject_exists' => $hasSubject,
                'email_html_length' => strlen($email->getEmailHtml()),
                'template_first_line' => $this->getFirstLineOfTemplate('activation_content'),
            ];
            
            return [
                'passed' => $email->hasContent() && $hasSubject,
                'message' => $hasSubject ? 
                    "Template processing successful with subject: $subject" : 
                    "Template processing completed but no subject found",
                'details' => $debugInfo
            ];
        } catch (Exception $e) {
            return [
                'passed' => false,
                'message' => 'Template processing failed: ' . $e->getMessage(),
            ];
        }
    }
    
    private function testVariableReplacement(): array {
        // Use the working template pattern without User object to avoid complications
        $email = new EmailTemplate('activation_content');
        $settings = Globalvars::get_instance();
        $email->email_from = $settings->get_setting('defaultemail');
        $email->email_from_name = $settings->get_setting('defaultemailname');
        $email->add_recipient('test@example.com', 'Test User');
        
        $email->fill_template([
            'act_code' => 'VARIABLE_TEST_123',
            'resend' => false,
        ]);
        
        // Use new getter methods to inspect content
        $subject = $email->getEmailSubject();
        $html = $email->getEmailHtml();
        
        // Look for evidence that our test variable was replaced
        $htmlHasActCode = $html && strpos($html, 'VARIABLE_TEST_123') !== false;
        $subjectExists = !empty($subject);
        $htmlExists = !empty($html);
        
        return [
            'passed' => $htmlHasActCode && $subjectExists && $htmlExists,
            'message' => 'Variable replacement test',
            'details' => [
                'html_has_act_code' => $htmlHasActCode,
                'subject_exists' => $subjectExists,
                'html_exists' => $htmlExists,
                'final_subject' => $subject,
                'html_length' => $html ? strlen($html) : 0
            ]
        ];
    }
    
    private function testContentGeneration(): array {
        // Use the working template pattern
        $email = new EmailTemplate('activation_content');
        $settings = Globalvars::get_instance();
        $email->email_from = $settings->get_setting('defaultemail');
        $email->email_from_name = $settings->get_setting('defaultemailname');
        $email->add_recipient('test@example.com', 'Test User');
        
        $email->fill_template([
            'act_code' => 'TEST999',
            'resend' => false,
        ]);
        
        // Use getter methods to verify content
        $html = $email->getEmailHtml();
        $text = $email->getEmailText();
        $recipients = $email->getEmailRecipients();
        
        return [
            'passed' => !empty($html) && !empty($text) && count($recipients) > 0,
            'message' => 'Content generation successful',
            'details' => [
                'html_length' => strlen($html),
                'text_length' => strlen($text),
                'recipient_count' => count($recipients),
            ]
        ];
    }
    
    private function testGetterMethods(): array {
        // Use the same working template and pattern as Basic Processing
        $email = new EmailTemplate('activation_content');
        $settings = Globalvars::get_instance();
        $email->email_from = $settings->get_setting('defaultemail');
        $email->email_from_name = $settings->get_setting('defaultemailname');
        $email->add_recipient('test@example.com', 'Test User');
        
        $email->fill_template([
            'act_code' => 'TEST123',
            'resend' => false,
        ]);
        
        // Test all new getter methods
        $getters = [
            'getEmailHtml' => $email->getEmailHtml(),
            'getEmailText' => $email->getEmailText(),
            'getEmailSubject' => $email->getEmailSubject(),
            'getEmailRecipients' => $email->getEmailRecipients(),
            'hasContent' => $email->hasContent(),
            'getServiceType' => $email->getServiceType(),
        ];
        
        $allWorking = true;
        $failingMethods = [];
        
        foreach ($getters as $method => $result) {
            // Different validation rules for different methods
            $methodFailed = false;
            
            if ($method === 'hasContent') {
                // hasContent should return a boolean, not null
                $methodFailed = !is_bool($result);
            } elseif ($method === 'getEmailRecipients') {
                // getEmailRecipients should return an array
                $methodFailed = !is_array($result);
            } else {
                // Other methods should not be null (but empty strings are OK)
                $methodFailed = ($result === null);
            }
            
            if ($methodFailed) {
                $allWorking = false;
                $failingMethods[] = $method . ' (' . gettype($result) . ')';
            }
        }
        
        return [
            'passed' => $allWorking,
            'message' => $allWorking ? 'All getter methods working' : 'Some getter methods failing: ' . implode(', ', $failingMethods),
            'details' => [
                'getters' => array_map(function($v) { 
                    return is_array($v) ? count($v) . ' items' : (is_bool($v) ? ($v ? 'true' : 'false') : (strlen($v) > 50 ? substr($v, 0, 50) . '...' : $v)); 
                }, $getters),
                'failing_methods' => $failingMethods
            ]
        ];
    }
    
    private function testSubjectExtraction(): array {
        $results = [];
        
        // Test 1: Direct subject assignment
        $email1 = new EmailTemplate('activation_content');
        $email1->email_from = 'test@example.com';
        $email1->add_recipient('test@example.com', 'Test');
        $email1->fill_template(['act_code' => 'TEST123', 'resend' => false]);
        
        $results['template_subject'] = [
            'subject' => $email1->getEmailSubject(),
            'has_subject' => !empty($email1->getEmailSubject()),
        ];
        
        // Test 2: Direct subject assignment
        $email2 = new EmailTemplate('activation_content');
        $email2->email_from = 'test@example.com';
        $email2->add_recipient('test@example.com', 'Test');
        $email2->fill_template(['act_code' => 'TEST123', 'resend' => false]);
        $email2->email_subject = 'Direct Subject';
        
        $results['direct_subject'] = [
            'subject' => $email2->getEmailSubject(),
            'has_subject' => !empty($email2->getEmailSubject()),
        ];
        
        // Test 3: Template variable method (if we had a template that uses *subject*)
        // This test may not work unless we have a template that uses *subject*
        $email3 = new EmailTemplate('activation_content');
        $email3->email_from = 'test@example.com';
        $email3->add_recipient('test@example.com', 'Test');
        
        $email3->fill_template([
            'subject' => 'Variable Subject',
            'act_code' => 'TEST123',
            'resend' => false,
        ]);
        
        $results['variable_subject'] = [
            'subject' => $email3->getEmailSubject(),
            'has_subject' => !empty($email3->getEmailSubject()),
            'note' => 'This only works if template contains *subject*'
        ];
        
        $allPassed = $results['direct_subject']['has_subject'] && 
                     ($results['template_subject']['has_subject'] || 
                      $results['variable_subject']['has_subject']);
        
        return [
            'passed' => $allPassed,
            'message' => 'Subject extraction methods tested',
            'details' => $results
        ];
    }
    
    private function testSubjectPriority(): array {
        // Test that direct assignment overrides template subject
        // Try to use test template, fall back to activation_content if not available
        $templateName = 'test_template_with_subject';
        
        // Check if test template exists, if not use activation_content
        try {
            $testTemplates = new MultiEmailTemplateStore(['email_template_name' => $templateName]);
            $testTemplates->load();
            if ($testTemplates->count_all() == 0) {
                $templateName = 'activation_content';
            }
        } catch (Exception $e) {
            $templateName = 'activation_content';
        }
        
        $email = new EmailTemplate($templateName);
        $settings = Globalvars::get_instance();
        $email->email_from = $settings->get_setting('defaultemail');
        $email->add_recipient('test@example.com', 'Test');
        
        $fillValues = ($templateName === 'test_template_with_subject') ? 
            ['test_id' => 'ABC123', 'timestamp' => date('Y-m-d H:i:s')] :
            ['act_code' => 'ABC123', 'resend' => false];
            
        $email->fill_template($fillValues);
        
        // Get the template-extracted subject
        $templateSubject = $email->getEmailSubject();
        
        // Now override it
        $overrideSubject = 'Override Subject - ' . uniqid();
        $email->email_subject = $overrideSubject;
        
        // Verify override worked
        $finalSubject = $email->getEmailSubject();
        
        return [
            'passed' => $finalSubject === $overrideSubject,
            'message' => 'Subject priority test',
            'details' => [
                'template_used' => $templateName,
                'template_subject' => $templateSubject,
                'override_subject' => $overrideSubject,
                'final_subject' => $finalSubject,
                'override_worked' => $finalSubject === $overrideSubject,
            ]
        ];
    }
    
    // New helper method to check template content
    private function getFirstLineOfTemplate($templateName): ?string {
        try {
            $templates = new MultiEmailTemplateStore(['email_template_name' => $templateName]);
            $templates->load();
            if ($templates->count_all() > 0) {
                $template = $templates->get(0);
                $body = $template->get('emt_body');
                $lines = preg_split('/[\r\n]/', $body, 2, PREG_SPLIT_NO_EMPTY);
                return $lines[0] ?? null;
            }
        } catch (Exception $e) {
            return 'Error loading template: ' . $e->getMessage();
        }
        return null;
    }
}