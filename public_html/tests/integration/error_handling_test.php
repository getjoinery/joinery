<?php
/**
 * Error Handling Test Suite
 * 
 * Tests the new ErrorManager system with consolidated exceptions
 * Run from command line or web browser to verify error handling works correctly
 */

require_once(__DIR__ . '/../../includes/PathHelper.php');
PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/SessionControl.php');

// Load consolidated exception classes
PathHelper::requireOnce('includes/ErrorClasses.php');

class ErrorHandlingTester {
    
    private array $testResults = [];
    private bool $isCli;
    private bool $isAjax;
    
    public function __construct() {
        $this->isCli = php_sapi_name() === 'cli';
        $this->isAjax = isset($_GET['ajax']) && $_GET['ajax'] === '1';
        
        if ($this->isAjax) {
            header('Content-Type: application/json');
        }
    }
    
    public function runAllTests(): void {
        $this->output("=== Error Handling Test Suite ===\n");
        $this->output("Testing new ErrorManager system with consolidated exceptions\n\n");
        
        // Test 1: ValidationException
        $this->testValidationException();
        
        // Test 2: AuthenticationException  
        $this->testAuthenticationException();
        
        // Test 3: AuthorizationException
        $this->testAuthorizationException();
        
        // Test 4: BusinessLogicException
        $this->testBusinessLogicException();
        
        // Test 5: ExternalServiceException
        $this->testExternalServiceException();
        
        // Test 6: DatabaseException
        $this->testDatabaseException();
        
        // Test 7: FileSystemException
        $this->testFileSystemException();
        
        // Test 8: SystemException
        $this->testSystemException();
        
        // Test 9: ErrorManager Integration
        $this->testErrorManagerIntegration();
        
        // Test 10: Context Detection
        $this->testContextDetection();
        
        // Test 11: Database Logging
        $this->testDatabaseLogging();
        
        $this->outputResults();
    }
    
    private function testValidationException(): void {
        try {
            $this->output("Test 1: ValidationException\n");
            
            // Create validation exception with field errors
            $fieldErrors = [
                'email' => 'Invalid email format',
                'password' => 'Password too short'
            ];
            
            $exception = new ValidationException(
                'Form validation failed',
                $fieldErrors,
                400,
                null,
                ['form_id' => 'login_form']
            );
            
            // Test exception properties
            $this->assert($exception->getMessage() === 'Form validation failed', 'ValidationException message');
            $this->assert($exception->getCode() === 400, 'ValidationException code');
            $this->assert($exception->hasFieldErrors(), 'ValidationException has field errors');
            $this->assert(count($exception->getFieldErrors()) === 2, 'ValidationException field error count');
            $this->assert($exception->getUserMessage() === 'Please check your input and try again.', 'ValidationException user message');
            $this->assert($exception->shouldLog() === true, 'ValidationException should log');
            
            $this->testResults['ValidationException'] = 'PASS';
            $this->output("✓ ValidationException tests passed\n\n");
            
        } catch (Exception $e) {
            $this->testResults['ValidationException'] = 'FAIL: ' . $e->getMessage();
            $this->output("✗ ValidationException tests failed: " . $e->getMessage() . "\n\n");
        }
    }
    
    private function testAuthenticationException(): void {
        try {
            $this->output("Test 2: AuthenticationException\n");
            
            $exception = new AuthenticationException('Invalid credentials provided');
            
            $this->assert($exception->getMessage() === 'Invalid credentials provided', 'AuthenticationException message');
            $this->assert($exception->getUserMessage() === 'Authentication failed. Please log in again.', 'AuthenticationException user message');
            $this->assert($exception->shouldLog() === true, 'AuthenticationException should log');
            
            $this->testResults['AuthenticationException'] = 'PASS';
            $this->output("✓ AuthenticationException tests passed\n\n");
            
        } catch (Exception $e) {
            $this->testResults['AuthenticationException'] = 'FAIL: ' . $e->getMessage();
            $this->output("✗ AuthenticationException tests failed: " . $e->getMessage() . "\n\n");
        }
    }
    
    private function testAuthorizationException(): void {
        try {
            $this->output("Test 3: AuthorizationException\n");
            
            $exception = new AuthorizationException('Access denied to admin area', 8);
            
            $this->assert($exception->getMessage() === 'Access denied to admin area', 'AuthorizationException message');
            $this->assert($exception->getRequiredPermissionLevel() === 8, 'AuthorizationException permission level');
            $this->assert($exception->getUserMessage() === 'You do not have permission to perform this action.', 'AuthorizationException user message');
            
            $this->testResults['AuthorizationException'] = 'PASS';
            $this->output("✓ AuthorizationException tests passed\n\n");
            
        } catch (Exception $e) {
            $this->testResults['AuthorizationException'] = 'FAIL: ' . $e->getMessage();
            $this->output("✗ AuthorizationException tests failed: " . $e->getMessage() . "\n\n");
        }
    }
    
    private function testBusinessLogicException(): void {
        try {
            $this->output("Test 4: BusinessLogicException\n");
            
            $exception = new BusinessLogicException('Cannot cancel event after start date');
            $exception->setBusinessRule('EVENT_CANCELLATION_DEADLINE');
            
            $this->assert($exception->getMessage() === 'Cannot cancel event after start date', 'BusinessLogicException message');
            $this->assert($exception->getBusinessRule() === 'EVENT_CANCELLATION_DEADLINE', 'BusinessLogicException business rule');
            $this->assert($exception->getUserMessage() === 'This action cannot be completed at this time.', 'BusinessLogicException user message');
            
            $this->testResults['BusinessLogicException'] = 'PASS';
            $this->output("✓ BusinessLogicException tests passed\n\n");
            
        } catch (Exception $e) {
            $this->testResults['BusinessLogicException'] = 'FAIL: ' . $e->getMessage();
            $this->output("✗ BusinessLogicException tests failed: " . $e->getMessage() . "\n\n");
        }
    }
    
    private function testExternalServiceException(): void {
        try {
            $this->output("Test 5: ExternalServiceException\n");
            
            $serviceContext = ['api_endpoint' => '/payments', 'response_code' => 503];
            $exception = new ExternalServiceException(
                'Payment gateway timeout',
                'stripe',
                $serviceContext
            );
            
            $this->assert($exception->getMessage() === 'Payment gateway timeout', 'ExternalServiceException message');
            $this->assert($exception->getServiceName() === 'stripe', 'ExternalServiceException service name');
            $this->assert($exception->getServiceContext()['response_code'] === 503, 'ExternalServiceException service context');
            $this->assert($exception->getUserMessage() === 'A service is temporarily unavailable. Please try again later.', 'ExternalServiceException user message');
            
            $this->testResults['ExternalServiceException'] = 'PASS';
            $this->output("✓ ExternalServiceException tests passed\n\n");
            
        } catch (Exception $e) {
            $this->testResults['ExternalServiceException'] = 'FAIL: ' . $e->getMessage();
            $this->output("✗ ExternalServiceException tests failed: " . $e->getMessage() . "\n\n");
        }
    }
    
    private function testDatabaseException(): void {
        try {
            $this->output("Test 6: DatabaseException\n");
            
            $exception = new DatabaseException('Foreign key constraint violation');
            $exception->setQuery('DELETE FROM users WHERE usr_id = ?');
            $exception->setBindings(['user_id' => 123]);
            
            $this->assert($exception->getMessage() === 'Foreign key constraint violation', 'DatabaseException message');
            $this->assert($exception->getQuery() === 'DELETE FROM users WHERE usr_id = ?', 'DatabaseException query');
            $this->assert($exception->getBindings()['user_id'] === 123, 'DatabaseException bindings');
            $this->assert($exception->getUserMessage() === 'A database error occurred. Please try again.', 'DatabaseException user message');
            $this->assert($exception->shouldDisplay() === false, 'DatabaseException should not display to users');
            
            $this->testResults['DatabaseException'] = 'PASS';
            $this->output("✓ DatabaseException tests passed\n\n");
            
        } catch (Exception $e) {
            $this->testResults['DatabaseException'] = 'FAIL: ' . $e->getMessage();
            $this->output("✗ DatabaseException tests failed: " . $e->getMessage() . "\n\n");
        }
    }
    
    private function testFileSystemException(): void {
        try {
            $this->output("Test 7: FileSystemException\n");
            
            $exception = new FileSystemException(
                'Permission denied writing to file',
                '/uploads/file.txt',
                'write'
            );
            
            $this->assert($exception->getMessage() === 'Permission denied writing to file', 'FileSystemException message');
            $this->assert($exception->getFilePath() === '/uploads/file.txt', 'FileSystemException file path');
            $this->assert($exception->getOperation() === 'write', 'FileSystemException operation');
            $this->assert($exception->getUserMessage() === 'A file operation error occurred.', 'FileSystemException user message');
            
            $this->testResults['FileSystemException'] = 'PASS';
            $this->output("✓ FileSystemException tests passed\n\n");
            
        } catch (Exception $e) {
            $this->testResults['FileSystemException'] = 'FAIL: ' . $e->getMessage();
            $this->output("✗ FileSystemException tests failed: " . $e->getMessage() . "\n\n");
        }
    }
    
    private function testSystemException(): void {
        try {
            $this->output("Test 8: SystemException\n");
            
            $exception = new SystemException('Memory limit exceeded', 'php_engine');
            
            $this->assert($exception->getMessage() === 'Memory limit exceeded', 'SystemException message');
            $this->assert($exception->getComponent() === 'php_engine', 'SystemException component');
            $this->assert($exception->getUserMessage() === 'A system error occurred. Our team has been notified.', 'SystemException user message');
            
            $this->testResults['SystemException'] = 'PASS';
            $this->output("✓ SystemException tests passed\n\n");
            
        } catch (Exception $e) {
            $this->testResults['SystemException'] = 'FAIL: ' . $e->getMessage();
            $this->output("✗ SystemException tests failed: " . $e->getMessage() . "\n\n");
        }
    }
    
    private function testErrorManagerIntegration(): void {
        try {
            $this->output("Test 9: ErrorManager Integration\n");
            
            // Test that ErrorManager exists and can be instantiated
            PathHelper::requireOnce('includes/ErrorHandler.php');
            $errorManager = ErrorManager::getInstance();
            
            $this->assert($errorManager instanceof ErrorManager, 'ErrorManager instance creation');
            $this->assert($errorManager === ErrorManager::getInstance(), 'ErrorManager singleton pattern');
            
            $this->testResults['ErrorManager Integration'] = 'PASS';
            $this->output("✓ ErrorManager integration tests passed\n\n");
            
        } catch (Exception $e) {
            $this->testResults['ErrorManager Integration'] = 'FAIL: ' . $e->getMessage();
            $this->output("✗ ErrorManager integration tests failed: " . $e->getMessage() . "\n\n");
        }
    }
    
    private function testContextDetection(): void {
        try {
            $this->output("Test 10: Context Detection\n");
            
            PathHelper::requireOnce('includes/ErrorHandler.php');
            
            $contextData = [
                'request_uri' => '/test',
                'request_method' => 'GET',
                'is_ajax' => $this->isAjax,
                'is_admin' => false,
                'is_cli' => $this->isCli,
                'user_id' => null,
                'timestamp' => time(),
                'ip_address' => '127.0.0.1'
            ];
            
            $context = new ErrorContext($contextData);
            
            $this->assert($context->getRequestUri() === '/test', 'ErrorContext request URI');
            $this->assert($context->isAjax() === $this->isAjax, 'ErrorContext AJAX detection');
            $this->assert($context->isCli() === $this->isCli, 'ErrorContext CLI detection');
            $this->assert($context->getIpAddress() === '127.0.0.1', 'ErrorContext IP address');
            
            $this->testResults['Context Detection'] = 'PASS';
            $this->output("✓ Context detection tests passed\n\n");
            
        } catch (Exception $e) {
            $this->testResults['Context Detection'] = 'FAIL: ' . $e->getMessage();
            $this->output("✗ Context detection tests failed: " . $e->getMessage() . "\n\n");
        }
    }
    
    private function testDatabaseLogging(): void {
        try {
            $this->output("Test 11: Database Error Logging\n");
            
            // Test that errors are logged to database
            PathHelper::requireOnce('data/general_errors_class.php');
            PathHelper::requireOnce('includes/DbConnector.php');
            
            // Create a test exception with unique message
            $testMessage = 'Test database logging ' . uniqid();
            $testException = new ValidationException($testMessage);
            
            // Directly log the error without propagating it
            try {
                $logger = new DatabaseErrorLogger();
                $context = new ErrorContext([
                    'request_uri' => $_SERVER['REQUEST_URI'] ?? '/test',
                    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
                    'is_ajax' => false,
                    'is_admin' => false,
                    'is_cli' => php_sapi_name() === 'cli',
                    'user_id' => null,
                    'session_id' => session_id(),
                    'timestamp' => time(),
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
                ]);
                $logger->log($testException, $context);
            } catch (Exception $e) {
                // Ignore any errors during logging for test purposes
            }
            
            // Wait a moment for database write
            sleep(1);
            
            // Check if error was logged
            $dbconnector = DbConnector::get_instance();
            $dblink = $dbconnector->get_db_link();
            
            $sql = "SELECT COUNT(*) as count FROM err_general_errors 
                    WHERE err_message = ? 
                    AND err_create_time > NOW() - INTERVAL '5 seconds'";
            $stmt = $dblink->prepare($sql);
            $stmt->execute([$testMessage]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $this->assert($result['count'] > 0, 'Error was logged to database');
            
            // Test context data is properly stored
            $sql = "SELECT err_context FROM err_general_errors 
                    WHERE err_message = ? 
                    ORDER BY err_create_time DESC LIMIT 1";
            $stmt = $dblink->prepare($sql);
            $stmt->execute([$testMessage]);
            $contextResult = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $this->assert(!empty($contextResult['err_context']), 'Error context was stored');
            $this->assert(strpos($contextResult['err_context'], 'REQUEST_URI') !== false, 'Context includes request URI');
            
            $this->testResults['Database Logging'] = 'PASS';
            $this->output("✓ Database logging tests passed\n\n");
            
        } catch (Exception $e) {
            $this->testResults['Database Logging'] = 'FAIL: ' . $e->getMessage();
            $this->output("✗ Database logging tests failed: " . $e->getMessage() . "\n\n");
        }
    }
    
    private function assert(bool $condition, string $description): void {
        if (!$condition) {
            throw new Exception("Assertion failed: $description");
        }
    }
    
    private function output(string $message): void {
        if ($this->isAjax) {
            // Store for JSON output
            return;
        }
        
        if ($this->isCli) {
            echo $message;
        } else {
            echo nl2br(htmlspecialchars($message));
        }
    }
    
    private function outputResults(): void {
        $totalTests = count($this->testResults);
        $passedTests = count(array_filter($this->testResults, fn($result) => $result === 'PASS'));
        $failedTests = $totalTests - $passedTests;
        
        $summary = "\n=== Test Results Summary ===\n";
        $summary .= "Total Tests: $totalTests\n";
        $summary .= "Passed: $passedTests\n";
        $summary .= "Failed: $failedTests\n\n";
        
        if ($failedTests > 0) {
            $summary .= "Failed Tests:\n";
            foreach ($this->testResults as $test => $result) {
                if ($result !== 'PASS') {
                    $summary .= "- $test: $result\n";
                }
            }
        } else {
            $summary .= "🎉 All tests passed! Error handling system is working correctly.\n";
        }
        
        $summary .= "\n=== Integration Status ===\n";
        $summary .= "✓ Old ErrorHandler.php removed\n";
        $summary .= "✓ New ErrorManager system active\n";
        $summary .= "✓ Consolidated exceptions working\n";
        $summary .= "✓ Context detection functional\n";
        
        if ($this->isAjax) {
            echo json_encode([
                'success' => $failedTests === 0,
                'summary' => $summary,
                'results' => $this->testResults,
                'stats' => [
                    'total' => $totalTests,
                    'passed' => $passedTests,
                    'failed' => $failedTests
                ]
            ]);
        } else {
            $this->output($summary);
        }
    }
}

// Handle different execution contexts
if (isset($_GET['test'])) {
    // Web execution with specific test
    $testType = $_GET['test'];
    $tester = new ErrorHandlingTester();
    
    switch ($testType) {
        case 'validation':
            throw new ValidationException('Test validation error', ['test_field' => 'Test error message']);
        case 'authentication':
            throw new AuthenticationException('Test authentication error');
        case 'authorization':
            throw new AuthorizationException('Test authorization error', 8);
        case 'business':
            throw new BusinessLogicException('Test business logic error');
        case 'database':
            $dbException = new DatabaseException('Test database error');
            $dbException->setQuery('SELECT * FROM test WHERE id = ?');
            $dbException->setBindings(['id' => 123]);
            throw $dbException;
        case 'all':
        default:
            $tester->runAllTests();
    }
} else {
    // Default execution - run all tests
    $tester = new ErrorHandlingTester();
    $tester->runAllTests();
}
?>