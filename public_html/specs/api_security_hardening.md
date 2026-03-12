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
8. **Repetitive Error Response Pattern (Code Quality)**

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

### 3. Request Log Table (Shared Infrastructure for Rate Limiting and Audit Trail)

**Issues addressed:** No rate limiting (#3), no audit trail (#4).

**Strategy:** A single general-purpose request log table that serves both as an audit trail and as the data source for rate limiting. This table is **not API-specific** — it can log requests from any site feature (API calls, login attempts, form submissions, password resets, etc.).

**New Data Class:** `/data/request_logs_class.php`

```php
<?php
class RequestLog extends SystemBase {
	public static $prefix = 'rql';
	public static $tablename = 'rql_request_logs';
	public static $pkey_column = 'rql_request_log_id';

	public static $field_specifications = array(
		'rql_request_log_id' => array('type'=>'int8', 'serial'=>true),
		'rql_feature'        => array('type'=>'varchar(50)', 'is_nullable'=>false),  // e.g. 'api', 'login', 'register', 'password_reset'
		'rql_action'         => array('type'=>'varchar(100)', 'is_nullable'=>true),   // e.g. 'GET /api/v1/User/5', 'login_attempt', 'form_submit'
		'rql_ip_address'     => array('type'=>'varchar(45)', 'is_nullable'=>false),
		'rql_usr_user_id'    => array('type'=>'int4', 'is_nullable'=>true),
		'rql_was_success'    => array('type'=>'bool', 'is_nullable'=>false, 'default'=>'true'),
		'rql_status_code'    => array('type'=>'int2', 'is_nullable'=>true),           // HTTP status code (when applicable)
		'rql_error_type'     => array('type'=>'varchar(50)', 'is_nullable'=>true),    // e.g. 'AuthenticationError', 'ValidationError'
		'rql_note'           => array('type'=>'varchar(255)', 'is_nullable'=>true),   // Human-readable detail
		'rql_response_ms'    => array('type'=>'int4', 'is_nullable'=>true),           // Response time in milliseconds
		'rql_create_time'    => array('type'=>'timestamp', 'is_nullable'=>false, 'default'=>'now()'),
	);

	public static $timestamp_fields = array('rql_create_time');
}

class MultiRequestLog extends SystemMultiBase {
	public static $table_name = 'rql_request_logs';
	public static $table_primary_key = 'rql_request_log_id';
}
?>
```

**Key design decisions:**
- `rql_feature` identifies the subsystem (keeps queries fast without needing to parse action strings)
- `rql_action` holds the specific operation (flexible per feature)
- `rql_was_success` is the boolean that rate limiting queries against
- `rql_usr_user_id` is nullable because failed auth attempts won't have a user yet
- No request bodies or secrets are logged (privacy)

**New Utility Class:** `/includes/RequestLogger.php`

A lightweight static class that wraps the data model. Any feature can call it without understanding the table structure.

```php
<?php
require_once(PathHelper::getIncludePath('data/request_logs_class.php'));

class RequestLogger {

	/**
	 * Log a request.
	 */
	public static function log($feature, $action, $success = true, $options = array()) {
		$log = new RequestLog(NULL);
		$log->set('rql_feature', $feature);
		$log->set('rql_action', substr($action, 0, 100));
		$log->set('rql_ip_address', $_SERVER['REMOTE_ADDR']);
		$log->set('rql_was_success', $success);

		if(isset($options['user_id']))     $log->set('rql_usr_user_id', $options['user_id']);
		if(isset($options['status_code'])) $log->set('rql_status_code', $options['status_code']);
		if(isset($options['error_type']))  $log->set('rql_error_type', $options['error_type']);
		if(isset($options['note']))        $log->set('rql_note', substr($options['note'], 0, 255));
		if(isset($options['response_ms'])) $log->set('rql_response_ms', $options['response_ms']);

		$log->save();
	}

	/**
	 * Check if a rate limit has been exceeded.
	 * Counts rows matching feature + IP (optionally filtered by success/failure)
	 * within the given time window.
	 *
	 * @param string $feature       Feature name (e.g. 'api', 'login')
	 * @param int    $max_requests  Maximum allowed requests in the window
	 * @param int    $window_seconds Time window in seconds
	 * @param bool|null $success_filter  null=count all, true=only successes, false=only failures
	 * @return bool  True if within limit, false if exceeded
	 */
	public static function check_rate_limit($feature, $max_requests, $window_seconds, $success_filter = null) {
		$dbconnector = DbConnector::get_instance();
		$db = $dbconnector->get_db_link();

		$sql = "SELECT COUNT(*) as cnt FROM rql_request_logs
		        WHERE rql_feature = ? AND rql_ip_address = ?
		        AND rql_create_time > NOW() - INTERVAL '{$window_seconds} seconds'";
		$params = [$feature, $_SERVER['REMOTE_ADDR']];

		if($success_filter !== null) {
			$sql .= " AND rql_was_success = ?";
			$params[] = $success_filter;
		}

		$stmt = $db->prepare($sql);
		$stmt->execute($params);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		return ($row['cnt'] < $max_requests);
	}

	/**
	 * Delete records older than the given number of days.
	 * Called by the PurgeOldRequestLogs scheduled task.
	 *
	 * @param int $days Records older than this many days are deleted
	 * @return int Number of rows deleted
	 */
	public static function cleanup($days = 90) {
		$dbconnector = DbConnector::get_instance();
		$db = $dbconnector->get_db_link();

		$sql = "DELETE FROM rql_request_logs WHERE rql_create_time < NOW() - (INTERVAL '1 day' * :days)";
		$stmt = $db->prepare($sql);
		$stmt->execute([':days' => $days]);
		return $stmt->rowCount();
	}
}
?>
```

### 3a. Rate Limiting (API)

**File:** `/api/apiv1.php`

**Implementation:** Add after HTTPS check, using RequestLogger:

```php
require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));

// Check general API rate limit (1000 requests per hour per IP)
if(!RequestLogger::check_rate_limit('api', 1000, 3600)) {
	api_error('Rate limit exceeded. Please try again later.', 'RateLimitError', 429);
}

// Check failed API auth rate limit (10 failures per 15 minutes per IP)
if(!RequestLogger::check_rate_limit('api_auth', 10, 900, false)) {
	api_error('Too many failed authentication attempts. Please try again later.', 'RateLimitError', 429);
}
```

After each authentication failure, log it:
```php
RequestLogger::log('api_auth', 'auth_failure', false, [
	'status_code' => 401,
	'error_type' => 'AuthenticationError',
	'note' => 'Incorrect secret key'
]);
```

### 3b. Audit Logging (API)

Log every completed API request at the end of processing:

```php
// On success
RequestLogger::log('api', $operation, true, [
	'user_id' => $api_user->key,
	'status_code' => 200,
	'response_ms' => $response_time_ms
]);

// On error
RequestLogger::log('api', $operation, false, [
	'user_id' => $api_user->key,
	'status_code' => 400,
	'error_type' => 'TransactionError',
	'note' => $e->getMessage()
]);
```

### 3c. Usage from Other Site Features (Examples)

The same infrastructure works for any feature without additional tables or classes:

```php
// Login rate limiting
require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));

// Check before processing login
if(!RequestLogger::check_rate_limit('login', 10, 900, false)) {
	return LogicResult::error('Too many failed login attempts. Please try again in 15 minutes.');
}

// After failed login
RequestLogger::log('login', 'login_attempt', false, [
	'note' => 'Invalid password for ' . $email
]);

// After successful login
RequestLogger::log('login', 'login_attempt', true, [
	'user_id' => $user->key
]);
```

```php
// Registration spam prevention
if(!RequestLogger::check_rate_limit('register', 5, 3600)) {
	return LogicResult::error('Too many registration attempts. Please try again later.');
}
RequestLogger::log('register', 'register_attempt', true, ['user_id' => $user->key]);
```

```php
// Password reset abuse prevention
if(!RequestLogger::check_rate_limit('password_reset', 5, 3600)) {
	return LogicResult::error('Too many password reset requests. Please try again later.');
}
RequestLogger::log('password_reset', 'reset_request', true);
```

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

### 8. Refactor Repetitive Error Response Pattern (Code Quality)

**Issue:** The file contains ~15 instances of the same `echo json_encode() + exit` error response pattern. Each is 7-8 lines of identical boilerplate. This makes the file ~480 lines when it could be ~250, and every error response is a copy-paste opportunity for bugs (as evidenced by the line 45 HTTP 200 and line 303 commented-out status code issues).

**Current Pattern (repeated ~15 times):**
```php
$response = array(
    'api_version' => '1.0',
    'errortype' => 'AuthenticationError',
    'error' => 'Error: Some message',
    'data' => ''
);
header("Content-Type: application/json");
http_response_code(400);

$response = json_encode($response);
echo $response . PHP_EOL;
exit;
```

**Implementation:** Extract a helper function and replace all instances:

```php
/**
 * Send a JSON error response and exit.
 */
function api_error($message, $error_type = 'TransactionError', $status_code = 400) {
    $response = array(
        'api_version' => '1.0',
        'errortype' => $error_type,
        'error' => 'Error: ' . $message,
        'data' => ''
    );
    header("Content-Type: application/json");
    http_response_code($status_code);
    echo json_encode($response) . PHP_EOL;
    exit;
}

/**
 * Send a JSON success response and exit.
 */
function api_success($data, $message = '', $status_code = 200, $extra = array()) {
    $response = array(
        'api_version' => '1.0',
        'success_message' => $message,
        'data' => $data
    );
    $response = array_merge($response, $extra);
    header("Content-Type: application/json");
    http_response_code($status_code);
    echo json_encode($response) . PHP_EOL;
    exit;
}
```

**Conversion examples:**

```php
// BEFORE (7 lines)
$response = array(
    'api_version' => '1.0',
    'errortype' => 'AuthenticationError',
    'error' => 'Error: Public/secret keys not present',
    'data' => '',
);
header("Content-Type: application/json");
http_response_code(400);
$response = json_encode($response);
echo $response . PHP_EOL;
exit;

// AFTER (1 line)
api_error('Public/secret keys not present', 'AuthenticationError', 400);
```

```php
// BEFORE (success, 5 lines)
header("Content-Type: application/json");
http_response_code(200);
$response = json_encode($response);
echo $response . PHP_EOL;
exit;

// AFTER (1 line)
api_success($object->export_as_array(), $class_name . ' found.');
```

**Instances to convert:**
| Line | Type | Error Type | Status Code |
|------|------|-----------|-------------|
| 17-30 | Error | AuthenticationError | 400 |
| 38-50 | Error | AuthenticationError | 200 → 400 (fixes #1) |
| 52-65 | Error | AuthenticationError | 400 |
| 67-84 | Error | AuthenticationError | 400 |
| 86-99 | Error | AuthenticationError | 400 |
| 101-114 | Error | AuthenticationError | 400 |
| 121-134 | Error | AuthenticationError | 401 |
| 140-154 | Error | AuthenticationError | 401 |
| 166-179 | Error | AuthenticationError | 403 |
| 191-204 | Error | TransactionError | 400 |
| 208-221 | Error | AuthenticationError | 403 |
| 242-255 | Error | TransactionError | 400 |
| 259-272 | Error | AuthenticationError | 403 |
| 295-308 | Error | TransactionError | commented → 400 (fixes #1) |
| 312-325 | Error | AuthenticationError | 403 |
| 341-354 | Error | TransactionError | 400 |
| 358-371 | Error | AuthenticationError | 403 |
| 459-467 | Success | — | 200 |
| 468-480 | Error | (generic) | 400 |

**Benefits:**
- Eliminates the two status code bugs by construction (single source of truth)
- Reduces file from ~480 lines to ~250 lines
- Makes it impossible to forget `header("Content-Type: application/json")` or `exit`
- Prepares the codebase for the business logic endpoints spec (which reuses these helpers)

**Note:** This should be done in the same pass as fixes #1-#7 since it touches the same lines and naturally fixes the status code issues.

## Implementation Priority

### Phase 1: Critical Security Fixes & Code Quality (High Priority)
1. ✅ Refactor error response pattern (extract `api_error()`/`api_success()` helpers) — do this first since it touches every error path and naturally fixes the status code bugs
2. ✅ Fix inconsistent HTTP status codes (Lines 45, 303) — absorbed by #1
3. ✅ Add HTTPS enforcement
4. ✅ Validate API key status (active, start_time, expires_time)
5. ✅ Add security headers

### Phase 2: Request Logging & Rate Limiting (Medium Priority)
6. ⚠️ Create `RequestLog` data class and `RequestLogger` utility (shared infrastructure)
7. ⚠️ Add API request logging (audit trail)
8. ⚠️ Add API rate limiting (uses same table)
9. ⚠️ Add cleanup scheduled task
10. ⚠️ Uncomment collection authentication (or document why it's disabled)

### Phase 3: Extend to Other Features (Low Priority)
11. 🔄 Add rate limiting to login
12. 🔄 Add rate limiting to registration
13. 🔄 Add rate limiting to password reset
14. 🔄 Admin page for viewing request logs
15. 🔄 Per-API-key rate limits

## Testing Requirements

### Security Testing
- [ ] Verify HTTPS enforcement (try HTTP request, should fail)
- [ ] Verify inactive API keys are rejected
- [ ] Verify expired API keys are rejected
- [ ] Verify not-yet-active API keys are rejected
- [ ] Test API rate limiting (exceed limits, verify 429 response)
- [ ] Test auth failure rate limiting (10 bad secrets, verify lockout)
- [ ] Verify security headers are present in all responses

### Functional Testing
- [ ] Existing API functionality still works after `api_error()`/`api_success()` refactor
- [ ] Error messages are clear and helpful
- [ ] HTTP status codes are correct (especially the two that were wrong)
- [ ] Request logs are created for API requests
- [ ] `RequestLogger::log()` works from a non-API context (e.g., login)
- [ ] `RequestLogger::check_rate_limit()` correctly counts within time windows
- [ ] `RequestLogger::cleanup()` removes old records

### Performance Testing
- [ ] Rate limit check query is fast (<5ms) even with large log table
- [ ] Logging doesn't significantly slow down API requests
- [ ] Collection authentication performance is acceptable

## Configuration

### New Settings Required

Add to `stg_settings` table:

```sql
-- Enable/disable HTTPS enforcement (for development)
INSERT INTO stg_settings (stg_name, stg_value) VALUES ('api_require_https', 'true');

-- CORS allowed origins (comma-separated)
INSERT INTO stg_settings (stg_name, stg_value) VALUES ('api_allowed_origins', '');

-- Request log retention in days (used by cleanup scheduled task)
INSERT INTO stg_settings (stg_name, stg_value) VALUES ('request_log_retention_days', '90');
```

**Note:** Rate limit thresholds (e.g., 1000 requests/hour, 10 auth failures/15 min) are hardcoded in the calling code, not in settings. This keeps the check fast (no settings lookup per request) and makes limits explicit where they're enforced. They can be moved to settings later if admin-configurable limits are needed.

## Database Changes

### New Table: Request Logs

One table serves both rate limiting and audit logging for all site features. Created automatically by the `RequestLog` data class via `update_database`.

```
rql_request_logs
├── rql_request_log_id  (int8, serial PK)
├── rql_feature          (varchar 50, NOT NULL)   — 'api', 'login', 'register', 'password_reset', etc.
├── rql_action           (varchar 100, nullable)   — specific operation detail
├── rql_ip_address       (varchar 45, NOT NULL)
├── rql_usr_user_id      (int4, nullable)          — null for unauthenticated requests
├── rql_was_success       (bool, NOT NULL, default true)
├── rql_status_code      (int2, nullable)          — HTTP status code when applicable
├── rql_error_type       (varchar 50, nullable)
├── rql_note             (varchar 255, nullable)   — human-readable detail, never secrets
├── rql_response_ms      (int4, nullable)
└── rql_create_time      (timestamp, NOT NULL, default now())
```

**Rate limiting queries** use: `WHERE rql_feature = ? AND rql_ip_address = ? AND rql_create_time > NOW() - INTERVAL '...'`

**Audit queries** use: `WHERE rql_feature = 'api' ORDER BY rql_create_time DESC`

**Indexes** (add to `$field_specifications` or create manually if needed for performance):
- `(rql_feature, rql_ip_address, rql_create_time)` — rate limiting lookups
- `(rql_create_time)` — cleanup and audit trail queries

### Cleanup Scheduled Task

A scheduled task runs daily to delete old request log records. Follows the existing pattern (see `PurgeOldErrors` for reference).

**New File:** `/tasks/PurgeOldRequestLogs.json`
```json
{
    "name": "Purge Old Request Logs",
    "description": "Deletes request log entries older than a configurable number of days",
    "default_frequency": "daily",
    "default_time": "03:15:00",
    "config_fields": {
        "days_to_keep": {"type": "number", "label": "Days to Keep", "required": true}
    }
}
```

**New File:** `/tasks/PurgeOldRequestLogs.php`
```php
<?php
/**
 * PurgeOldRequestLogs - Scheduled Task
 *
 * Deletes request log entries older than a configurable number of days.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));
require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));

class PurgeOldRequestLogs implements ScheduledTaskInterface {

	public function run(array $config) {
		$days_to_keep = isset($config['days_to_keep']) ? (int)$config['days_to_keep'] : 0;
		if ($days_to_keep <= 0) {
			return array('status' => 'skipped', 'message' => 'days_to_keep not configured');
		}

		$deleted = RequestLogger::cleanup($days_to_keep);

		if ($deleted === 0) {
			return array('status' => 'success', 'message' => 'No old request logs to purge');
		}

		return array('status' => 'success', 'message' => 'Purged ' . $deleted . ' request log(s) older than ' . $days_to_keep . ' days');
	}
}
```

**Note:** `RequestLogger::cleanup()` should return the number of deleted rows (update from the current spec to use `$stmt->rowCount()` and return it). After deployment, activate the task via Admin > System > Scheduled Tasks and set `days_to_keep` (recommended: 90).

## Security Considerations

### HTTPS Enforcement
- Allow bypass in development via setting check
- Ensure reverse proxies properly set HTTPS headers

### Rate Limiting
- Uses IP address as primary identifier
- Rate limits are per-feature (API, login, register, etc. are independent)
- Cleanup scheduled task prevents unbounded table growth
- Per-API-key rate limits can be added later by extending `check_rate_limit()` with an optional identifier parameter

### Logging
- Never log secret keys, passwords, or request bodies
- `rql_note` should contain only non-sensitive context (e.g., "Invalid password for user@example.com" is acceptable; the actual password is not)
- Scheduled cleanup respects configurable retention period
- Consider privacy regulations (GDPR, etc.) when setting retention

### Collection Authentication
- Performance impact of per-object auth checks
- Consider caching authentication results
- Document performance vs. security tradeoff

## Documentation Requirements

**No API documentation currently exists.** Create `/docs/api.md` covering both the existing API and the changes in this spec. This document serves external API consumers (developers integrating with the platform).

### New File: `/docs/api.md`

The document should contain the following sections:

#### 1. Overview
- Base URL: `https://{site-domain}/api/v1/`
- JSON-only API (all requests and responses are `application/json`)
- REST conventions: GET (read), POST (create), PUT (update), DELETE (soft delete)

#### 2. Authentication
- All requests require `public_key` and `secret_key` headers
- How to obtain API keys (admin creates them via Admin > API Keys)
- API keys are associated with a user account — the key inherits that user's identity for authorization checks
- IP restriction: keys can optionally be locked to specific IPs
- **NEW: Key lifecycle** — keys have `is_active`, `start_time`, and `expires_time` fields; requests with inactive, not-yet-started, or expired keys are rejected with HTTP 401

#### 3. Permission Levels
Document the permission model clearly, since the current code has a quirk:
- Permission 1: Read-only (GET single objects and collections)
- Permission 2: Write-only (POST, PUT) — **cannot read** (this is the current behavior and should be documented as intentional or flagged as a bug to fix)
- Permission 3: Read + Write
- Permission 4+: Read + Write + Delete

#### 4. CRUD Endpoints
- **GET `/api/v1/{ClassName}/{id}`** — Read a single object
- **GET `/api/v1/{ClassName}s?page=0&numperpage=10&sort=field&sdirection=ASC`** — List objects (note: plural form, trailing 's')
  - Document pagination parameters: `page`, `numperpage`, `sort`, `sdirection`
  - Document that additional query parameters are passed as filters to the Multi class
- **POST `/api/v1/{ClassName}`** — Create a new object (fields in POST body)
- **PUT `/api/v1/{ClassName}/{id}?field=value`** — Update an object (fields in query string)
- **DELETE `/api/v1/{ClassName}/{id}`** — Soft delete an object

#### 5. Available Models
- List of model class names that can be used as endpoints (or explain that any SystemBase model is available)
- Note that the class name is case-sensitive and uses PascalCase (e.g., `User`, `EventRegistrant`, `ProductGroup`)

#### 6. Response Format
Document the two response shapes:

**Success (single object):**
```json
{
    "api_version": "1.0",
    "success_message": "ClassName found.",
    "data": { ... }
}
```

**Success (collection):**
```json
{
    "api_version": "1.0",
    "success_message": "",
    "num_results": 100,
    "page": 0,
    "numperpage": 10,
    "data": [ ... ]
}
```

**Error:**
```json
{
    "api_version": "1.0",
    "errortype": "AuthenticationError",
    "error": "Error: description",
    "data": ""
}
```

#### 7. Error Types and HTTP Status Codes
| Status Code | Error Type | Meaning |
|-------------|-----------|---------|
| 400 | AuthenticationError | Missing headers, invalid key, deleted user |
| 400 | TransactionError | Object not found, validation failure, save error |
| 401 | AuthenticationError | Wrong secret, IP restricted, inactive/expired key |
| 403 | AuthenticationError | Insufficient permission for this operation |
| **426** | **SecurityError** | **HTTPS required (new)** |
| **429** | **RateLimitError** | **Rate limit exceeded (new)** |

#### 8. HTTPS Requirement (NEW)
- All API requests must use HTTPS
- HTTP requests are rejected with 426 Upgrade Required
- Note about development bypass via `api_require_https` setting

#### 9. Rate Limiting (NEW)
- General limit: 1000 requests per hour per IP
- Auth failure limit: 10 failed attempts per 15 minutes per IP
- When exceeded: HTTP 429 with `RateLimitError`
- Limits reset after the time window passes (sliding window)

#### 10. CORS (NEW)
- CORS is disabled by default
- Configurable via `api_allowed_origins` setting (comma-separated origins)
- Preflight OPTIONS requests are handled automatically

#### 11. Security Headers (NEW)
List the headers returned on every response:
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY`
- `X-XSS-Protection: 1; mode=block`
- `Referrer-Policy: no-referrer`

### Update CLAUDE.md

Add an entry to the documentation index in CLAUDE.md:
```
- [API](docs/api.md) - REST API authentication, endpoints, and usage
```

## Backward Compatibility

All changes are backward compatible:
- Existing API keys continue to work
- No changes to request/response format
- No changes to authentication mechanism
- Only adds additional validation and security checks

## Success Criteria

1. ✅ All security vulnerabilities identified are addressed
2. ✅ HTTPS enforced in production
3. ✅ Rate limiting prevents brute force attacks on API
4. ✅ Complete audit trail of API usage
5. ✅ API key status fully validated
6. ✅ Security headers present on all responses
7. ✅ `apiv1.php` refactored — no more copy-paste error response blocks
8. ✅ RequestLogger is reusable by any site feature (login, registration, etc.)
9. ✅ Performance impact is minimal (<10% slower)
10. ✅ All tests pass
11. ✅ Documentation updated

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