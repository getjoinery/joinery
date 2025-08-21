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
            $email = new EmailTemplate('default_outer_template');
            $email->add_recipient('test@example.com', 'Test User');
            
            $values = [
                'subject' => 'Test Subject',
                'mail_body' => '<p>Test content with *name*</p>',
                'name' => 'John Doe',
            ];
            
            $email->fill_template($values);
            
            return [
                'passed' => $email->hasContent(), // Use new hasContent() method
                'message' => 'Template processing completed',
                'has_content' => $email->hasContent(),
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
        $email = new EmailTemplate('default_outer_template');
        $email->add_recipient('test@example.com', 'Test User');
        
        $email->fill_template([
            'subject' => 'Content Test',
            'mail_body' => '<h1>Test Email</h1><p>This is a test email.</p>',
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
        $email = new EmailTemplate('default_outer_template');
        $email->add_recipient('test@example.com', 'Test User');
        $email->fill_template([
            'subject' => 'Getter Test',
            'mail_body' => '<p>Testing getter methods</p>',
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
        foreach ($getters as $method => $result) {
            if ($result === null && $method !== 'getEmailFrom') {
                $allWorking = false;
                break;
            }
        }
        
        return [
            'passed' => $allWorking,
            'message' => 'All getter methods working',
            'getters' => array_map(function($v) { 
                return is_array($v) ? count($v) . ' items' : (strlen($v) > 50 ? substr($v, 0, 50) . '...' : $v); 
            }, $getters),
        ];
    }
}