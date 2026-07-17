<?php
/** @joinery-test
 * name: error_handling
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Error Handling Test Suite
 *
 * Tests the ErrorManager system with consolidated exceptions.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

// Load consolidated exception classes
require_once(PathHelper::getIncludePath('includes/ErrorClasses.php'));

class ErrorHandlingTester {

    private bool $isCli;
    private bool $isAjax;

    public function __construct() {
        $this->isCli = php_sapi_name() === 'cli';
        $this->isAjax = isset($_GET['ajax']) && $_GET['ajax'] === '1';
    }

    public function runAllTests(): void {
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
    }

    private function testValidationException(): void {
        section('ValidationException');
        try {
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
        } catch (Throwable $e) {
            check(false, 'ValidationException tests', 'unexpected exception: ' . $e->getMessage());
        }
    }

    private function testAuthenticationException(): void {
        section('AuthenticationException');
        try {
            $exception = new AuthenticationException('Invalid credentials provided');
            
            $this->assert($exception->getMessage() === 'Invalid credentials provided', 'AuthenticationException message');
            $this->assert($exception->getUserMessage() === 'Authentication failed. Please log in again.', 'AuthenticationException user message');
            $this->assert($exception->shouldLog() === true, 'AuthenticationException should log');
        } catch (Throwable $e) {
            check(false, 'AuthenticationException tests', 'unexpected exception: ' . $e->getMessage());
        }
    }

    private function testAuthorizationException(): void {
        section('AuthorizationException');
        try {
            $exception = new AuthorizationException('Access denied to admin area', 8);
            
            $this->assert($exception->getMessage() === 'Access denied to admin area', 'AuthorizationException message');
            $this->assert($exception->getRequiredPermissionLevel() === 8, 'AuthorizationException permission level');
            $this->assert($exception->getUserMessage() === 'You do not have permission to perform this action.', 'AuthorizationException user message');
        } catch (Throwable $e) {
            check(false, 'AuthorizationException tests', 'unexpected exception: ' . $e->getMessage());
        }
    }

    private function testBusinessLogicException(): void {
        section('BusinessLogicException');
        try {
            $exception = new BusinessLogicException('Cannot cancel event after start date');
            $exception->setBusinessRule('EVENT_CANCELLATION_DEADLINE');
            
            $this->assert($exception->getMessage() === 'Cannot cancel event after start date', 'BusinessLogicException message');
            $this->assert($exception->getBusinessRule() === 'EVENT_CANCELLATION_DEADLINE', 'BusinessLogicException business rule');
            $this->assert($exception->getUserMessage() === 'This action cannot be completed at this time.', 'BusinessLogicException user message');
        } catch (Throwable $e) {
            check(false, 'BusinessLogicException tests', 'unexpected exception: ' . $e->getMessage());
        }
    }

    private function testExternalServiceException(): void {
        section('ExternalServiceException');
        try {
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
        } catch (Throwable $e) {
            check(false, 'ExternalServiceException tests', 'unexpected exception: ' . $e->getMessage());
        }
    }

    private function testDatabaseException(): void {
        section('DatabaseException');
        try {
            $exception = new DatabaseException('Foreign key constraint violation');
            $exception->setQuery('DELETE FROM users WHERE usr_id = ?');
            $exception->setBindings(['user_id' => 123]);
            
            $this->assert($exception->getMessage() === 'Foreign key constraint violation', 'DatabaseException message');
            $this->assert($exception->getQuery() === 'DELETE FROM users WHERE usr_id = ?', 'DatabaseException query');
            $this->assert($exception->getBindings()['user_id'] === 123, 'DatabaseException bindings');
            $this->assert($exception->getUserMessage() === 'A database error occurred. Please try again.', 'DatabaseException user message');
            $this->assert($exception->shouldDisplay() === false, 'DatabaseException should not display to users');
        } catch (Throwable $e) {
            check(false, 'DatabaseException tests', 'unexpected exception: ' . $e->getMessage());
        }
    }

    private function testFileSystemException(): void {
        section('FileSystemException');
        try {
            $exception = new FileSystemException(
                'Permission denied writing to file',
                '/uploads/file.txt',
                'write'
            );
            
            $this->assert($exception->getMessage() === 'Permission denied writing to file', 'FileSystemException message');
            $this->assert($exception->getFilePath() === '/uploads/file.txt', 'FileSystemException file path');
            $this->assert($exception->getOperation() === 'write', 'FileSystemException operation');
            $this->assert($exception->getUserMessage() === 'A file operation error occurred.', 'FileSystemException user message');
        } catch (Throwable $e) {
            check(false, 'FileSystemException tests', 'unexpected exception: ' . $e->getMessage());
        }
    }

    private function testSystemException(): void {
        section('SystemException');
        try {
            $exception = new SystemException('Memory limit exceeded', 'php_engine');
            
            $this->assert($exception->getMessage() === 'Memory limit exceeded', 'SystemException message');
            $this->assert($exception->getComponent() === 'php_engine', 'SystemException component');
            $this->assert($exception->getUserMessage() === 'A system error occurred. Our team has been notified.', 'SystemException user message');
        } catch (Throwable $e) {
            check(false, 'SystemException tests', 'unexpected exception: ' . $e->getMessage());
        }
    }

    private function testErrorManagerIntegration(): void {
        section('ErrorManager Integration');
        try {

            // Test that ErrorManager exists and can be instantiated
            require_once(PathHelper::getIncludePath('includes/ErrorHandler.php'));
            $errorManager = ErrorManager::getInstance();
            
            $this->assert($errorManager instanceof ErrorManager, 'ErrorManager instance creation');
            $this->assert($errorManager === ErrorManager::getInstance(), 'ErrorManager singleton pattern');
        } catch (Throwable $e) {
            check(false, 'ErrorManager integration tests', 'unexpected exception: ' . $e->getMessage());
        }
    }

    private function testContextDetection(): void {
        section('Context Detection');
        try {
            require_once(PathHelper::getIncludePath('includes/ErrorHandler.php'));
            
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
        } catch (Throwable $e) {
            check(false, 'Context detection tests', 'unexpected exception: ' . $e->getMessage());
        }
    }

    private function testDatabaseLogging(): void {
        section('Database Error Logging');
        try {

            // Test that errors are logged to database
            require_once(PathHelper::getIncludePath('data/general_errors_class.php'));
            require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
            
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

            // Clean up the row this test just wrote (db-tier tests must self-clean).
            // Registered immediately so a crash mid-assert can't leak it.
            harness_defer(function () use ($testMessage) {
                try {
                    $db = DbConnector::get_instance()->get_db_link();
                    $db->prepare("DELETE FROM err_general_errors WHERE err_message = ?")->execute([$testMessage]);
                } catch (\Throwable $e) { /* best effort */ }
            });

            // The insert is a synchronous PDO write — no wait needed (the old
            // sleep(1) here was pure dead time on the pre-deploy gate).

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
        } catch (Throwable $e) {
            check(false, 'Database logging tests', 'unexpected exception: ' . $e->getMessage());
        }
    }

    private function assert(bool $condition, string $description): void {
        check($condition, $description);
    }
}

$t = new ErrorHandlingTester();
$t->runAllTests();
harness_finish();