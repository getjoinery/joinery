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
        $results['subject_validation'] = $this->testSubjectValidation();
        $results['missing_subject_exception'] = $this->testMissingSubjectException();
        
        return $results;
    }
    
    private function testBasicProcessing(): array {
        try {
            // Use the new EmailMessage architecture
            $message = EmailMessage::fromTemplate('activation_content', [
                'act_code' => 'TEST123',
                'resend' => false,
            ]);
            
            // Follow the working pattern from Activation::email_activate_send
            $settings = Globalvars::get_instance();
            $message->from($settings->get_setting('defaultemail'), $settings->get_setting('defaultemailname'));
            $message->to('test@example.com', 'Test User');
            
            // Check if the template extracted a subject
            $subject = $message->getSubject();
            $hasSubject = !empty($subject);
            $hasContent = !empty($message->getHtmlBody()) || !empty($message->getTextBody());
            
            // Get debug info
            $debugInfo = [
                'hasContent_result' => $hasContent,
                'subject_extracted' => $subject,
                'subject_exists' => $hasSubject,
                'html_body_length' => strlen($message->getHtmlBody()),
                'text_body_length' => strlen($message->getTextBody()),
                'template_first_line' => $this->getFirstLineOfTemplate('activation_content'),
            ];
            
            return [
                'passed' => $hasContent && $hasSubject,
                'message' => $hasSubject ? 
                    "Template processing successful with subject: $subject" : 
                    "Template processing completed but no subject found",
                'details' => $debugInfo
            ];
        } catch (Exception $e) {
            return [
                'passed' => false,
                'message' => 'Template processing failed: ' . $e->getMessage(),
                'details' => [
                    'exception_class' => get_class($e),
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine()
                ]
            ];
        }
    }
    
    private function testVariableReplacement(): array {
        try {
            // Use the new EmailMessage architecture
            $message = EmailMessage::fromTemplate('activation_content', [
                'act_code' => 'VARIABLE_TEST_123',
                'resend' => false,
            ]);
            
            $settings = Globalvars::get_instance();
            $message->from($settings->get_setting('defaultemail'), $settings->get_setting('defaultemailname'));
            $message->to('test@example.com', 'Test User');
            
            // Use EmailMessage methods to inspect content
            $subject = $message->getSubject();
            $html = $message->getHtmlBody();
            
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
        } catch (Exception $e) {
            return [
                'passed' => false,
                'message' => 'Variable replacement test failed with exception: ' . $e->getMessage(),
                'details' => [
                    'exception_class' => get_class($e),
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine()
                ]
            ];
        }
    }
    
    private function testContentGeneration(): array {
        try {
            // Use the new EmailMessage architecture
            $message = EmailMessage::fromTemplate('activation_content', [
                'act_code' => 'TEST999', 
                'resend' => false,
            ]);
            
            $settings = Globalvars::get_instance();
            $message->from($settings->get_setting('defaultemail'), $settings->get_setting('defaultemailname'));
            $message->to('test@example.com', 'Test User');
            
            // Verify content generation
            $html = $message->getHtmlBody();
            $text = $message->getTextBody();
            $recipients = $message->getRecipients();
            
            return [
                'passed' => !empty($html) && !empty($text) && count($recipients) > 0,
                'message' => 'Content generation successful',
                'details' => [
                    'html_length' => strlen($html),
                    'text_length' => strlen($text),
                    'recipient_count' => count($recipients),
                ]
            ];
        } catch (Exception $e) {
            return [
                'passed' => false,
                'message' => 'Content generation failed: ' . $e->getMessage(),
                'details' => []
            ];
        }
    }
    
    private function testGetterMethods(): array {
        try {
            // Test both template processing and message composition
            $message = EmailMessage::fromTemplate('activation_content', [
                'act_code' => 'TEST123',
                'resend' => false,
            ]);
            
            $settings = Globalvars::get_instance();
            $message->from($settings->get_setting('defaultemail'), $settings->get_setting('defaultemailname'));
            $message->to('test@example.com', 'Test User');
            
            // Test new getter methods on EmailMessage
            $getters = [
                'getHtmlBody' => $message->getHtmlBody(),
                'getTextBody' => $message->getTextBody(), 
                'getSubject' => $message->getSubject(),
                'getRecipients' => $message->getRecipients(),
                'getFrom' => $message->getFrom(),
                'getFromName' => $message->getFromName(),
            ];
        
            $allWorking = true;
            $failingMethods = [];
            
            foreach ($getters as $method => $result) {
                // Different validation rules for different methods
                $methodFailed = false;
                
                if ($method === 'getRecipients') {
                    // getRecipients should return an array
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
        } catch (Exception $e) {
            return [
                'passed' => false,
                'message' => 'Getter methods test failed: ' . $e->getMessage(),
                'details' => []
            ];
        }
    }
    
    private function testSubjectExtraction(): array {
        try {
            $results = [];
            
            // Test 1: Template-generated subject
            $message1 = EmailMessage::fromTemplate('activation_content', [
                'act_code' => 'TEST123', 
                'resend' => false
            ]);
            $message1->from('test@example.com');
            $message1->to('test@example.com', 'Test');
            
            $results['template_subject'] = [
                'subject' => $message1->getSubject(),
                'has_subject' => !empty($message1->getSubject()),
            ];
            
            // Test 2: Direct subject override after creation
            $message2 = EmailMessage::fromTemplate('activation_content', [
                'act_code' => 'TEST123', 
                'resend' => false
            ]);
            $message2->from('test@example.com');
            $message2->to('test@example.com', 'Test');
            $message2->subject('Direct Subject Override');
            
            $results['direct_subject'] = [
                'subject' => $message2->getSubject(),
                'has_subject' => !empty($message2->getSubject()),
            ];
            
            // Test 3: Template variable method (if we had a template that uses *subject*)
            // This test may not work unless we have a template that uses *subject*
            $message3 = EmailMessage::fromTemplate('activation_content', [
                'subject' => 'Variable Subject',
                'act_code' => 'TEST123',
                'resend' => false,
            ]);
            $message3->from('test@example.com');
            $message3->to('test@example.com', 'Test');
            
            $results['variable_subject'] = [
                'subject' => $message3->getSubject(),
                'has_subject' => !empty($message3->getSubject()),
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
        } catch (Exception $e) {
            return [
                'passed' => false,
                'message' => 'Subject extraction test failed: ' . $e->getMessage(),
                'details' => []
            ];
        }
    }
    
    private function testSubjectPriority(): array {
        try {
            // Test that direct subject() call overrides template subject
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
            
            $fillValues = ($templateName === 'test_template_with_subject') ? 
                ['test_id' => 'ABC123', 'timestamp' => date('Y-m-d H:i:s')] :
                ['act_code' => 'ABC123', 'resend' => false];
            
            // Create message from template
            $message = EmailMessage::fromTemplate($templateName, $fillValues);
            $settings = Globalvars::get_instance();
            $message->from($settings->get_setting('defaultemail'));
            $message->to('test@example.com', 'Test');
            
            // Get the template-extracted subject
            $templateSubject = $message->getSubject();
            
            // Now override it using the fluent API
            $overrideSubject = 'Override Subject - ' . uniqid();
            $message->subject($overrideSubject);
            
            // Verify override worked
            $finalSubject = $message->getSubject();
            
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
        } catch (Exception $e) {
            return [
                'passed' => false,
                'message' => 'Subject priority test failed: ' . $e->getMessage(),
                'details' => []
            ];
        }
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
    
    private function testSubjectValidation(): array {
        try {
            // Test template with subject loaded from database
            $template = EmailTemplate::CreateLegacyTemplate('subject_validation_test', null);
            $template->fill_template(['test_id' => '12345']);
            
            // Should have subject from database
            $subject = $template->getSubject();
            $expectedSubject = 'Test Subject - ID 12345';
            $subjectMatches = ($subject === $expectedSubject);
            
            return [
                'passed' => $subjectMatches,
                'message' => 'Subject validation test',
                'details' => [
                    'expected_subject' => $expectedSubject,
                    'actual_subject' => $subject,
                    'subject_matches' => $subjectMatches
                ]
            ];
        } catch (Exception $e) {
            return [
                'passed' => false,
                'message' => 'Subject validation test failed: ' . $e->getMessage(),
                'details' => []
            ];
        }
    }
    
    private function testMissingSubjectException(): array {
        try {
            // Create template without subject (should fail validation)
            $template = new EmailTemplateStore(NULL);
            $template->set('emt_name', 'no_subject_test');
            $template->set('emt_type', 2);
            $template->set('emt_body', '<p>No subject template</p>');
            // Deliberately not setting emt_subject
            
            try {
                $template->save(); // This should fail due to required field validation
                
                // If we get here, the validation failed
                return [
                    'passed' => false,
                    'message' => 'Missing subject validation failed - template without subject was allowed',
                    'details' => []
                ];
            } catch (Exception $saveException) {
                // This is expected - save should fail for missing required field
                return [
                    'passed' => true,
                    'message' => 'Missing subject properly rejected',
                    'details' => [
                        'validation_error' => $saveException->getMessage()
                    ]
                ];
            }
        } catch (Exception $e) {
            return [
                'passed' => false,
                'message' => 'Missing subject exception test failed: ' . $e->getMessage(),
                'details' => []
            ];
        }
    }
}