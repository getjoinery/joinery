# Specification: API Security Hardening

## Overview

Enhance the API implementation (`/api/apiv1.php`) with additional security features to meet production-grade security standards while maintaining the solid authentication foundation already in place.

## Current State

### What Works Well ✅

The API currently implements core security practices correctly:
- **Bcrypt Secret Verification**: Uses proper hash comparison via `ApiKey::check_secret_key()`
- **Authentication Flow**: Validates public key → user → IP → secret in correct order
- **Permission Enforcement**: Properly enforces read/write/delete permission levels
- **Object-Level Authorization**: Calls `authenticate_read()`/`authenticate_write()` on individual objects
- **Appropriate Error Messages**: Clear, actionable errors without leaking sensitive data
- **HTTP Status Codes**: Generally correct (400, 401, 403, 200)

### Issues Identified ⚠️

1. **Inconsistent HTTP Status Codes**
2. **No HTTPS Enforcement**
3. **No Rate Limiting**
4. **No Request Logging/Audit Trail**
5. **API Key Status Not Fully Validated**
6. **Commented Out Collection Authentication**
7. **Missing Security Headers**

## Requirements

### 1. Fix Inconsistent HTTP Status Codes

**Issue:** Lines 45 and 303 in apiv1.php have incorrect or commented out status codes.

**File:** `/api/apiv1.php`

**Line 45:** Currently returns HTTP 200 for authentication error
```php
// CURRENT (wrong)
http_response_code(200); // Line 45

// SHOULD BE
http_response_code(400);
```

**Line 303:** Status code commented out
```php
// CURRENT (commented)
//http_response_code(400); // Line 303

// SHOULD BE
http_response_code(400);
```

**Implementation:**
- Change line 45 to use HTTP 400
- Uncomment line 303

### 2. Add HTTPS Enforcement

**Issue:** API accepts requests over plain HTTP, allowing secret keys to be intercepted.

**File:** `/api/apiv1.php`

**Implementation:** Add check immediately after includes (before line 12):

```php
// Enforce HTTPS for API requests
if(empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
	$response = array(
		'api_version' => '1.0',
		'errortype' => 'SecurityError',
		'error' => 'Error: API requires HTTPS. Please use https:// instead of http://',
		'data' => ''
	);
	header("Content-Type: application/json");
	http_response_code(426); // Upgrade Required
	echo json_encode($response) . PHP_EOL;
	exit;
}
```

**Note:** This can be disabled in development environments via a setting.

### 3. Implement Rate Limiting

**Issue:** No protection against brute force attacks on API keys or excessive API usage.

**Strategy:** Track failed authentication attempts by IP address and throttle requests.

**File:** `/api/apiv1.php`

**Implementation:** Add after HTTPS check:

```php
// Rate limiting for API requests
require_once(PathHelper::getIncludePath('includes/RateLimiter.php'));

$rate_limiter = new RateLimiter();
$client_ip = $_SERVER['REMOTE_ADDR'];

// Check general rate limit (e.g., 1000 requests per hour)
if(!$rate_limiter->check_limit($client_ip, 'api_general', 1000, 3600)) {
	$response = array(
		'api_version' => '1.0',
		'errortype' => 'RateLimitError',
		'error' => 'Error: Rate limit exceeded. Please try again later.',
		'data' => ''
	);
	header("Content-Type: application/json");
	http_response_code(429); // Too Many Requests
	echo json_encode($response) . PHP_EOL;
	exit;
}

// Check failed authentication rate limit (e.g., 10 failed attempts per 15 minutes)
// This will be called after authentication failures
```

**New File:** `/includes/RateLimiter.php`

```php
<?php
class RateLimiter {
	private $db;

	public function __construct() {
		$dbconnector = DbConnector::get_instance();
		$this->db = $dbconnector->get_db_link();
		$this->create_table_if_needed();
	}

	private function create_table_if_needed() {
		// Create rate limit tracking table
		// Table: rtl_rate_limits (rtl_id, rtl_identifier, rtl_limit_key, rtl_timestamp)
	}

	public function check_limit($identifier, $limit_key, $max_requests, $window_seconds) {
		// Check if identifier has exceeded max_requests in the time window
		// Returns true if within limit, false if exceeded
	}

	public function record_request($identifier, $limit_key) {
		// Record a request for rate limiting
	}

	public function record_failure($identifier, $limit_key) {
		// Record a failed authentication attempt
	}

	private function cleanup_old_records() {
		// Remove records older than longest window
	}
}
?>
```

**Future Enhancement:** Create data model classes for rate limits.

### 4. Add Request Logging and Audit Trail

**Issue:** No visibility into API usage or security events.

**File:** `/api/apiv1.php`

**Implementation:** Log key events to database table.

**New Table:** `apl_api_logs`
```sql
CREATE TABLE apl_api_logs (
    apl_api_log_id BIGSERIAL PRIMARY KEY,
    apl_apk_api_key_id INT4,
    apl_ip_address VARCHAR(45),
    apl_endpoint VARCHAR(255),
    apl_method VARCHAR(10),
    apl_status_code INT4,
    apl_error_type VARCHAR(50),
    apl_request_time TIMESTAMP(6) DEFAULT NOW(),
    apl_response_time_ms INT4
);
```

**Add Logging Function:**
```php
function log_api_request($api_key_id, $ip, $endpoint, $method, $status_code, $error_type = null, $response_time_ms = 0) {
	$dbconnector = DbConnector::get_instance();
	$db = $dbconnector->get_db_link();

	$sql = "INSERT INTO apl_api_logs (apl_apk_api_key_id, apl_ip_address, apl_endpoint, apl_method, apl_status_code, apl_error_type, apl_response_time_ms)
	        VALUES (?, ?, ?, ?, ?, ?, ?)";
	$stmt = $db->prepare($sql);
	$stmt->execute([$api_key_id, $ip, $endpoint, $method, $status_code, $error_type, $response_time_ms]);
}
```

**Log Points:**
- Every successful request
- Every authentication failure
- Every authorization failure
- Response times for performance monitoring

**Privacy Note:** Do not log request bodies or secret keys.

### 5. Validate API Key Status Completely

**Issue:** API doesn't check `apk_is_active`, `apk_start_time`, or `apk_expires_time`.

**File:** `/api/apiv1.php`

**Implementation:** Add after line 65 (after checking if key exists):

```php
// Check if API key is active
if(!$api_entry->get('apk_is_active')) {
	$response = array(
		'api_version' => '1.0',
		'errortype' => 'AuthenticationError',
		'error' => 'Error: API key is not active',
		'data' => ''
	);
	header("Content-Type: application/json");
	http_response_code(401);
	echo json_encode($response) . PHP_EOL;
	exit;
}

// Check if API key has started
if($api_entry->get('apk_start_time')) {
	$start_time = new DateTime($api_entry->get('apk_start_time'), new DateTimeZone('UTC'));
	$now = new DateTime('now', new DateTimeZone('UTC'));
	if($now < $start_time) {
		$response = array(
			'api_version' => '1.0',
			'errortype' => 'AuthenticationError',
			'error' => 'Error: API key is not yet active',
			'data' => ''
		);
		header("Content-Type: application/json");
		http_response_code(401);
		echo json_encode($response) . PHP_EOL;
		exit;
	}
}

// Check if API key has expired
if($api_entry->get('apk_expires_time')) {
	$expires_time = new DateTime($api_entry->get('apk_expires_time'), new DateTimeZone('UTC'));
	$now = new DateTime('now', new DateTimeZone('UTC'));
	if($now > $expires_time) {
		$response = array(
			'api_version' => '1.0',
			'errortype' => 'AuthenticationError',
			'error' => 'Error: API key has expired',
			'data' => ''
		);
		header("Content-Type: application/json");
		http_response_code(401);
		echo json_encode($response) . PHP_EOL;
		exit;
	}
}
```

### 6. Uncomment Collection Authentication

**Issue:** Lines 430-445 have `authenticate_read()` for collection objects commented out.

**File:** `/api/apiv1.php`

**Decision Needed:** This was likely commented out for a reason (performance?). Options:

**Option A:** Uncomment and enforce (most secure)
```php
foreach($objects as $object){
	if(!$object->authenticate_read(array('current_user_id'=>$api_user->key, 'current_user_permission'=>$api_user->get('usr_permission')))){
		// Skip this object or throw error
		continue; // Skip unauthorized objects
	}
	$response_array[] = $object->export_as_array();
}
```

**Option B:** Add as optional flag in query parameters
```php
// Allow ?skip_auth=1 for performance if needed (document this risk)
$skip_auth = isset($url_parts['skip_auth']) && $url_parts['skip_auth'] == '1';

foreach($objects as $object){
	if(!$skip_auth && !$object->authenticate_read(...)){
		continue;
	}
	$response_array[] = $object->export_as_array();
}
```

**Option C:** Document why it's disabled and leave commented

**Recommendation:** Option A (uncomment) for security, unless there's a documented performance requirement.

### 7. Add Security Headers

**Issue:** Missing important security headers for API responses.

**File:** `/api/apiv1.php`

**Implementation:** Add after initial includes (around line 11):

```php
// Security headers for API responses
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: no-referrer");

// CORS headers (if needed - configure allowed origins)
$allowed_origins = $settings->get_setting('api_allowed_origins'); // e.g., "https://example.com,https://app.example.com"
if($allowed_origins && isset($_SERVER['HTTP_ORIGIN'])) {
	$origin = $_SERVER['HTTP_ORIGIN'];
	$allowed_list = explode(',', $allowed_origins);
	$allowed_list = array_map('trim', $allowed_list);

	if(in_array($origin, $allowed_list)) {
		header("Access-Control-Allow-Origin: $origin");
		header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");
		header("Access-Control-Allow-Headers: public_key, secret_key, Content-Type");
		header("Access-Control-Max-Age: 86400");
	}
}

// Handle preflight OPTIONS requests
if($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
	http_response_code(204);
	exit;
}
```

**New Setting:** Add `api_allowed_origins` to settings table for CORS configuration.

## Implementation Priority

### Phase 1: Critical Security Fixes (High Priority)
1. ✅ Fix inconsistent HTTP status codes (Lines 45, 303)
2. ✅ Add HTTPS enforcement
3. ✅ Validate API key status (active, start_time, expires_time)
4. ✅ Add security headers

### Phase 2: Operational Security (Medium Priority)
5. ⚠️ Implement basic rate limiting
6. ⚠️ Add request logging for audit trail
7. ⚠️ Uncomment collection authentication (or document why it's disabled)

### Phase 3: Advanced Features (Low Priority)
8. 🔄 Advanced rate limiting (per-key limits, dynamic throttling)
9. 🔄 Performance monitoring and alerting
10. 🔄 API key usage statistics dashboard

## Testing Requirements

### Security Testing
- [ ] Verify HTTPS enforcement (try HTTP request, should fail)
- [ ] Verify inactive API keys are rejected
- [ ] Verify expired API keys are rejected
- [ ] Verify not-yet-active API keys are rejected
- [ ] Test rate limiting (exceed limits, verify 429 response)
- [ ] Verify security headers are present in all responses

### Functional Testing
- [ ] Existing API functionality still works
- [ ] Error messages are clear and helpful
- [ ] HTTP status codes are correct
- [ ] Logs are created for all requests

### Performance Testing
- [ ] Rate limiting doesn't impact normal usage
- [ ] Logging doesn't significantly slow down requests
- [ ] Collection authentication performance is acceptable

## Configuration

### New Settings Required

Add to `stg_settings` table:

```sql
-- Enable/disable HTTPS enforcement (for development)
INSERT INTO stg_settings (stg_name, stg_value) VALUES ('api_require_https', 'true');

-- CORS allowed origins (comma-separated)
INSERT INTO stg_settings (stg_name, stg_value) VALUES ('api_allowed_origins', '');

-- Rate limiting: General requests per hour
INSERT INTO stg_settings (stg_name, stg_value) VALUES ('api_rate_limit_general', '1000');

-- Rate limiting: Failed auth attempts per 15 minutes
INSERT INTO stg_settings (stg_name, stg_value) VALUES ('api_rate_limit_auth_failures', '10');

-- Enable/disable API request logging
INSERT INTO stg_settings (stg_name, stg_value) VALUES ('api_enable_logging', 'true');
```

## Database Changes

### New Table: Rate Limiting
```sql
CREATE TABLE rtl_rate_limits (
    rtl_rate_limit_id BIGSERIAL PRIMARY KEY,
    rtl_identifier VARCHAR(255) NOT NULL,
    rtl_limit_key VARCHAR(50) NOT NULL,
    rtl_timestamp TIMESTAMP(6) DEFAULT NOW(),
    rtl_is_failure BOOLEAN DEFAULT FALSE
);

CREATE INDEX idx_rtl_identifier_key ON rtl_rate_limits(rtl_identifier, rtl_limit_key);
CREATE INDEX idx_rtl_timestamp ON rtl_rate_limits(rtl_timestamp);
```

### New Table: API Logs
```sql
CREATE TABLE apl_api_logs (
    apl_api_log_id BIGSERIAL PRIMARY KEY,
    apl_apk_api_key_id INT4,
    apl_ip_address VARCHAR(45),
    apl_endpoint VARCHAR(255),
    apl_method VARCHAR(10),
    apl_status_code INT4,
    apl_error_type VARCHAR(50),
    apl_request_time TIMESTAMP(6) DEFAULT NOW(),
    apl_response_time_ms INT4
);

CREATE INDEX idx_apl_api_key ON apl_api_logs(apl_apk_api_key_id);
CREATE INDEX idx_apl_request_time ON apl_api_logs(apl_request_time);
CREATE INDEX idx_apl_ip_address ON apl_api_logs(apl_ip_address);
```

## Security Considerations

### HTTPS Enforcement
- Allow bypass in development via setting check
- Ensure reverse proxies properly set HTTPS headers

### Rate Limiting
- Use IP address as primary identifier
- Consider adding per-API-key rate limits
- Clean up old rate limit records regularly
- Don't let rate limit table grow unbounded

### Logging
- Never log secret keys or sensitive request data
- Implement log rotation/cleanup
- Consider privacy regulations (GDPR, etc.)
- Provide admin interface to view logs

### Collection Authentication
- Performance impact of per-object auth checks
- Consider caching authentication results
- Document performance vs. security tradeoff

## Documentation Updates

Update `/docs/api_documentation.md` to document:
- HTTPS requirement
- Rate limiting behavior and limits
- New error types (SecurityError, RateLimitError)
- New HTTP status codes (426, 429)
- CORS configuration
- API key time restrictions behavior

## Backward Compatibility

All changes are backward compatible:
- Existing API keys continue to work
- No changes to request/response format
- No changes to authentication mechanism
- Only adds additional validation and security checks

## Success Criteria

1. ✅ All security vulnerabilities identified are addressed
2. ✅ HTTPS enforced in production
3. ✅ Rate limiting prevents brute force attacks
4. ✅ Complete audit trail of API usage
5. ✅ API key status fully validated
6. ✅ Security headers present on all responses
7. ✅ Performance impact is minimal (<10% slower)
8. ✅ All tests pass
9. ✅ Documentation updated

## Future Enhancements

Beyond this spec:
- API versioning (v2, v3) for breaking changes
- Webhook support for async operations
- GraphQL endpoint as alternative to REST
- OAuth2 support for third-party integrations
- API key scopes (limit access to specific endpoints/models)
- Request signature verification (HMAC)
- API analytics dashboard
- Automated API key rotation