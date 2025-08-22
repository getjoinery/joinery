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
            
            $values = [
                'subject' => 'Test Subject',
                'act_code' => 'TEST123',
                'resend' => false,
            ];
            
            $email->fill_template($values);
            
            // Get debug info to understand why hasContent() might be false
            $hasContentResult = $email->hasContent();
            $htmlContent = method_exists($email, 'getEmailHtml') ? $email->getEmailHtml() : 'Method not available';
            $firstLine = '';
            if (is_string($htmlContent)) {
                $htmlLines = preg_split('/[\r\n]/', $htmlContent, NULL, PREG_SPLIT_NO_EMPTY);
                $firstLine = isset($htmlLines[0]) ? $htmlLines[0] : 'No first line found';
            }
            
            $debugInfo = [
                'hasContent_result' => $hasContentResult,
                'email_html_length' => is_string($htmlContent) ? strlen($htmlContent) : 'Method not available',
                'email_text_length' => method_exists($email, 'getEmailText') ? strlen($email->getEmailText()) : 'Method not available', 
                'email_subject' => method_exists($email, 'getEmailSubject') ? $email->getEmailSubject() : 'Method not available',
                'recipients_count' => method_exists($email, 'getEmailRecipients') ? count($email->getEmailRecipients()) : 'Method not available',
                'html_first_line' => $firstLine,
                'first_line_starts_with_subject' => stripos(trim($firstLine), 'subject:') === 0
            ];
            
            $message = $hasContentResult ? 
                'Template processing completed successfully' : 
                'Template processing completed but hasContent() returned false';
            
            return [
                'passed' => $hasContentResult,
                'message' => $message,
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
        $email = new EmailTemplate('default_outer_template');
        $email->add_recipient('test@example.com', 'Test User');
        
        $email->fill_template([
            'subject' => 'Hello *name*',
            'mail_body' => '<p>Welcome *name*, your email is *email*</p>',
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);
        
        // Use new getter methods to inspect content
        $subject = $email->getEmailSubject();
        $html = $email->getEmailHtml();
        
        $subjectCorrect = strpos($subject, 'John Doe') !== false;
        $htmlCorrect = strpos($html, 'John Doe') !== false && strpos($html, 'john@example.com') !== false;
        
        return [
            'passed' => $subjectCorrect && $htmlCorrect,
            'message' => 'Variable replacement test',
            'details' => [
                'subject_replaced' => $subjectCorrect,
                'html_replaced' => $htmlCorrect,
                'final_subject' => $subject,
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
            'subject' => 'Content Generation Test',
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
            'subject' => 'Getter Methods Test',
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
}