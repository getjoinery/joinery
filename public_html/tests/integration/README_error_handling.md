# Error Handling Test Suite

This test suite validates the new ErrorManager system and consolidated exceptions that replaced the old ErrorHandler.php system.

## Usage

### Command Line Testing
Run comprehensive tests from command line:
```bash
cd /path/to/joinery
php tests/integration/error_handling_test.php
```

### Web Browser Testing

#### Run All Tests
Visit: `https://yoursite.com/tests/integration/error_handling_test.php`

#### Test Specific Exception Types
- **Validation Error**: `https://yoursite.com/tests/integration/error_handling_test.php?test=validation`
- **Authentication Error**: `https://yoursite.com/tests/integration/error_handling_test.php?test=authentication`  
- **Authorization Error**: `https://yoursite.com/tests/integration/error_handling_test.php?test=authorization`
- **Business Logic Error**: `https://yoursite.com/tests/integration/error_handling_test.php?test=business`
- **Database Error**: `https://yoursite.com/tests/integration/error_handling_test.php?test=database`

#### AJAX Testing
Add `&ajax=1` to any URL to test JSON error responses:
- `https://yoursite.com/tests/integration/error_handling_test.php?test=validation&ajax=1`

## What The Tests Validate

### 1. Exception Classes (Tests 1-8)
- ✅ **ValidationException**: Form validation errors with field-level details
- ✅ **AuthenticationException**: Login/credential failures  
- ✅ **AuthorizationException**: Permission denied scenarios
- ✅ **BusinessLogicException**: Domain-specific business rule violations
- ✅ **ExternalServiceException**: Third-party API failures (Stripe, PayPal, etc.)
- ✅ **DatabaseException**: Database operation errors with query context
- ✅ **FileSystemException**: File system operation failures
- ✅ **SystemException**: System-level errors (memory, configuration, etc.)

### 2. ErrorManager Integration (Test 9)
- ✅ ErrorManager singleton pattern
- ✅ Proper instantiation and registration
- ✅ Handler selection logic

### 3. Context Detection (Test 10)  
- ✅ CLI vs Web vs AJAX detection
- ✅ Admin vs Public request routing
- ✅ User and session context capture

## Expected Output

### All Tests Passing
```
=== Error Handling Test Suite ===
Testing new ErrorManager system with consolidated exceptions

Test 1: ValidationException
✓ ValidationException tests passed

Test 2: AuthenticationException
✓ AuthenticationException tests passed

[... all tests ...]

=== Test Results Summary ===
Total Tests: 10
Passed: 10
Failed: 0

🎉 All tests passed! Error handling system is working correctly.

=== Integration Status ===
✓ Old ErrorHandler.php removed
✓ New ErrorManager system active
✓ Consolidated exceptions working
✓ Context detection functional
```

### AJAX Response Format
```json
{
  "success": true,
  "summary": "Test results summary...",
  "results": {
    "ValidationException": "PASS",
    "AuthenticationException": "PASS",
    ...
  },
  "stats": {
    "total": 10,
    "passed": 10,
    "failed": 0
  }
}
```

## Error Response Testing

The individual exception tests will trigger the new ErrorManager to demonstrate:

- **Web Requests**: Full HTML error pages with appropriate styling
- **AJAX Requests**: JSON error responses with proper status codes
- **CLI Requests**: Plain text error output
- **Admin Requests**: Bootstrap-styled admin error pages

## Troubleshooting

### Test Failures
If any tests fail, check:
1. All new exception classes are properly loaded
2. ErrorManager.php is accessible 
3. No remaining references to old ErrorHandler.php
4. New exception hierarchy is complete

### Runtime Errors
If you get runtime errors when triggering exceptions:
1. Check server error logs for detailed stack traces
2. Verify ErrorManager is properly registered in SystemClass.php
3. Ensure all handler classes are properly implemented

## Integration Verification

This test suite confirms that:
- ✅ All 80+ old exception classes have been consolidated to 9 new ones
- ✅ Circular dependencies between ErrorHandler and theme system are eliminated  
- ✅ Error context (database queries, user info, etc.) is properly preserved
- ✅ Different request types (web/AJAX/CLI/admin) get appropriate error handling
- ✅ User-friendly messages are displayed while technical details are logged

## Next Steps

After all tests pass:
1. Test real application functionality (login, registration, admin panels)
2. Monitor server logs for any unexpected errors
3. Verify error emails/notifications still work correctly
4. Test payment processing error scenarios if applicable