# Error Logging Code Refactoring Plan

## Overview

This document outlines the plan to refactor how ErrorHandler.php integrates with the GeneralError model, improving code patterns without changing the database schema.

## Current State Analysis

### Error Handling Architecture
- **Error Handler**: `ErrorHandler.php` - The error handling system that catches and processes all exceptions
- **Data Model**: `general_errors_class.php` - A SystemBase model that provides database access to `err_general_errors` table
- **Integration**: ErrorHandler's DatabaseLogger calls `GeneralError::LogGeneralError()` to persist errors

### Current Issues

#### Data Model Pattern
- **Non-standard logging**: Static `LogGeneralError()` method bypasses normal SystemBase save() pattern
- **Mixed responsibilities**: The static method does direct database work instead of using model methods
- **Inconsistent with codebase**: Other models use instance methods and save() for persistence

## Refactoring Plan

### Add Instance Method to GeneralError
Add a new instance method that follows standard patterns:

```php
// In general_errors_class.php
public function logError(\Throwable $exception, $session = [], $request = []) {
    $session_obj = SessionControl::get_instance();
    $dbhelper = DbConnector::get_instance();
    
    // Sanitize data
    $safe_session = self::sanitizeSessionData($session);
    $safe_request = self::sanitizeSessionData($request);
    
    $error_context = $exception->getTraceAsString() . "\r\n \r\n REQUEST_URI: " . 
                     $_SERVER['REQUEST_URI'] . "\r\n \r\n $_SESSION: " . 
                     print_r($safe_session, true) . ' $_REQUEST: ' . 
                     print_r($safe_request, true);
    
    // Set fields using standard model methods
    if ($exception instanceof PDOException) {
        $this->set('err_level', 'Database Error');
        $error_context .= 'POSTGRES DEBUG INFO:';
        if(count($dbhelper->query_history)){
            $error_context .= print_r($dbhelper->query_history, true);
        }
        if(count($dbhelper->last_query_params)){
            $error_context .= print_r($dbhelper->last_query_params, true);
        }
    } else {
        $this->set('err_level', 'Exception');
    }
    
    $error_context = '<pre>'.htmlentities($error_context).'</pre>';
    
    $this->set('err_code', $exception->getCode());
    $this->set('err_file', $exception->getFile());
    $this->set('err_line', $exception->getLine());
    $this->set('err_context', $error_context);
    $this->set('err_message', $exception->getMessage());
    
    if($session_obj->get_user_id()){
        $this->set('err_usr_user_id', $session_obj->get_user_id());
    }
    
    // Use standard save method
    $this->save();
}
```

### Update DatabaseLogger
Update the DatabaseLogger in ErrorHandler.php to use standard model pattern:

```php
class DatabaseErrorLogger implements ErrorLoggerInterface {
    
    public function log(\Throwable $exception, ErrorContext $context): void {
        try {
            PathHelper::requireOnce('data/general_errors_class.php');
            
            // Create new instance using standard pattern
            $errorLog = new GeneralError(NULL);
            
            // Use the new instance method
            $errorLog->logError(
                $exception,
                $_SESSION ?? [],
                $_REQUEST ?? []
            );
            
        } catch (\Throwable $e) {
            // Fallback logging to file if database fails
            error_log("Database error logging failed: " . $e->getMessage());
            error_log("Original error: " . $exception->getMessage());
        }
    }
}
```

### Remove Static Method
- Remove the static `LogGeneralError()` method entirely from GeneralError class
- Keep the `sanitizeSessionData()` method as it's used by the new instance method

### Update Error Testing Script
Add database logging tests to `error_handling_test.php`:

```php
private function testDatabaseLogging(): void {
    try {
        $this->output("Test 11: Database Error Logging\n");
        
        // Test that errors are logged to database
        PathHelper::requireOnce('data/general_errors_class.php');
        
        // Create a test exception
        $testException = new ValidationException('Test database logging');
        
        // Trigger error handling
        try {
            throw $testException;
        } catch (Exception $e) {
            ErrorManager::getInstance()->handleException($e);
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
        $stmt->execute(['Test database logging']);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->assert($result['count'] > 0, 'Error was logged to database');
        
        // Test context data is properly stored
        $sql = "SELECT err_context FROM err_general_errors 
                WHERE err_message = ? 
                ORDER BY err_create_time DESC LIMIT 1";
        $stmt = $dblink->prepare($sql);
        $stmt->execute(['Test database logging']);
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
```

Add this test to the `runAllTests()` method after the other tests.

