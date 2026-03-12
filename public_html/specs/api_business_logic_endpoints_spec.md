# Specification: API Business Logic Endpoints

## Overview

Add the ability for the REST API to execute business logic functions, not just raw CRUD on model objects. This is made possible by the LogicResult migration (see `specs/implemented/logic_result_with_validation_spec.md`), which converted all logic functions from using `exit()`, `header()` redirects, and direct HTML output to returning clean `LogicResult` objects.

## Background

### Current API Capabilities
The API (`/api/apiv1.php`) supports CRUD operations on any SystemBase model class:
- **GET** `/api/v1/User/123` — Read a single object
- **GET** `/api/v1/Users?page=0` — List objects
- **POST** `/api/v1/User` — Create a new object (raw field insert)
- **PUT** `/api/v1/User/123?field=value` — Update fields directly
- **DELETE** `/api/v1/User/123` — Soft delete

### What's Missing
The API cannot execute multi-step business operations like:
- Register a user (validation, duplicate checking, activation email, session creation)
- Register for an event (capacity checks, waitlist logic, payment)
- Process a cart/payment (Stripe integration, order creation, email receipts)
- Send a message to event registrants (recipient resolution, email templating)

These operations live in logic functions (`register_logic()`, `cart_charge_logic()`, etc.) which now all return `LogicResult` objects.

### Why This Is Now Possible
Before the LogicResult migration, logic functions had side effects that made them incompatible with API use:
- `header('Location: ...')` + `exit()` — Would send HTTP headers and kill the process
- `throw new SystemDisplayableError()` — Would render an HTML error page
- `echo json_encode()` + `exit()` — Would output directly and kill the process
- `PublicPage::OutputGenericPublicPage()` + `exit()` — Would render full HTML pages

Now they return `LogicResult` objects with `->redirect`, `->error`, `->data`, and `->validation_errors`, all of which can be cleanly translated to JSON API responses.

## Requirements

### 1. New API Endpoint Pattern

**URL Pattern:** `POST /api/v1/action/{action_name}`

Actions use POST because they execute operations with side effects.

**Request Format:**
```
POST /api/v1/action/register
Content-Type: application/json
public_key: {key}
secret_key: {key}

{
    "usr_email": "user@example.com",
    "usr_first_name": "Jane",
    "usr_last_name": "Doe",
    "password": "securepassword123"
}
```

**Response Format (success):**
```json
{
    "api_version": "1.0",
    "success_message": "Action completed successfully.",
    "redirect": "/page/register-thanks",
    "data": { ... }
}
```

**Response Format (error with validation):**
```json
{
    "api_version": "1.0",
    "errortype": "ValidationError",
    "error": "Please correct the errors below",
    "validation_errors": {
        "usr_email": "An account has already been registered with this email address.",
        "password": "Password is required"
    },
    "data": {}
}
```

**Response Format (error without validation):**
```json
{
    "api_version": "1.0",
    "errortype": "ActionError",
    "error": "This feature is turned off",
    "data": {}
}
```

### 2. Action Registry

Create a whitelist of logic functions that are safe to expose via API. Not all logic functions should be available — some are admin-only, some require session state, some have UI dependencies.

**File:** `/api/api_actions.php`

```php
<?php
/**
 * Registry of logic functions available via the API.
 * Each entry maps an action name to its configuration.
 */

$api_actions = array(
    'register' => array(
        'logic_file' => 'logic/register_logic.php',
        'logic_function' => 'register_logic',
        'min_permission' => 2,           // Write permission required
        'description' => 'Register a new user account',
        'requires_session' => false,
    ),
    'password_reset_request' => array(
        'logic_file' => 'logic/password-reset-1_logic.php',
        'logic_function' => 'password_reset_1_logic',
        'min_permission' => 2,
        'description' => 'Request a password reset email',
        'requires_session' => false,
    ),
    'event_register' => array(
        'logic_file' => 'logic/event_logic.php',
        'logic_function' => 'event_logic',
        'min_permission' => 2,
        'description' => 'Register for an event',
        'requires_session' => true,
    ),
    'event_withdraw' => array(
        'logic_file' => 'logic/event_withdraw_logic.php',
        'logic_function' => 'event_withdraw_logic',
        'min_permission' => 2,
        'description' => 'Withdraw from an event',
        'requires_session' => true,
    ),
    'contact_preferences' => array(
        'logic_file' => 'logic/contact_preferences_logic.php',
        'logic_function' => 'contact_preferences_logic',
        'min_permission' => 2,
        'description' => 'Update contact preferences',
        'requires_session' => true,
    ),
    'survey_submit' => array(
        'logic_file' => 'logic/survey_logic.php',
        'logic_function' => 'survey_logic',
        'min_permission' => 2,
        'description' => 'Submit a survey response',
        'requires_session' => true,
    ),
);
```

**Action Selection Criteria:**
- Must return LogicResult (all logic functions now do)
- Must not depend on browser-specific state (cookies, file uploads via multipart form)
- Must not render HTML that the caller needs
- Should perform a meaningful business operation beyond raw CRUD

**Actions intentionally excluded:**
- `login_logic` — API uses key-based auth, not session login
- `cart_charge_logic` — Requires Stripe client-side integration (tokenization)
- `admin_*` logic functions — Admin operations should use the existing CRUD API or a separate admin API
- `get_subscriptions_logic` — Not a standard logic function (renders HTML directly)

### 3. LogicResult-to-JSON Translation

**New helper function** in apiv1.php or a shared API utility:

```php
/**
 * Convert a LogicResult to an API JSON response.
 *
 * @param LogicResult $result
 * @param string $action_name
 * @return array Response array suitable for json_encode
 */
function api_translate_logic_result($result, $action_name) {
    if ($result->error) {
        $response = array(
            'api_version' => '1.0',
            'errortype' => !empty($result->validation_errors) ? 'ValidationError' : 'ActionError',
            'error' => $result->error,
            'data' => $result->data ?: new stdClass()
        );

        if (!empty($result->validation_errors)) {
            $response['validation_errors'] = $result->validation_errors;
        }

        return array('response' => $response, 'status_code' => 422);
    }

    $response = array(
        'api_version' => '1.0',
        'success_message' => 'Action \'' . $action_name . '\' completed successfully.',
        'data' => $result->data ?: new stdClass()
    );

    if ($result->redirect) {
        $response['redirect'] = $result->redirect;
    }

    return array('response' => $response, 'status_code' => 200);
}
```

### 4. Session Simulation for API Calls

Some logic functions call `SessionControl::get_instance()` and expect a logged-in user. For API calls, we need to simulate this.

**Approach:** Set the API user as the session user before calling the logic function, then restore state afterward.

```php
// Before calling logic function that requires_session:
$session = SessionControl::get_instance();
$original_user_id = $session->get_user_id();
$session->set_api_user($api_user->key);  // New method

// Call logic function
$result = $logic_function($get_params, $post_params);

// Restore
$session->clear_api_user();
```

**New SessionControl method:**
- `set_api_user($user_id)` — Sets the user ID for API context without creating a PHP session
- `clear_api_user()` — Clears the API user context
- `is_api_context()` — Returns true if currently in API context (useful for logic functions that need to behave differently)

### 5. Integration into apiv1.php

Add action handling after the existing CRUD routing (after the `else if(in_array(substr($operation, 0, -1), $classes))` block):

```php
else if(strtolower($params[2]) === 'action' && isset($params[3])) {
    $action_name = strtolower($params[3]);

    require_once(PathHelper::getIncludePath('api/api_actions.php'));

    if (!isset($api_actions[$action_name])) {
        // Return 404 - unknown action
    }

    $action = $api_actions[$action_name];

    // Check permission
    if ($api_entry->get('apk_permission') < $action['min_permission']) {
        // Return 403 - insufficient permission
    }

    // Load the logic file
    require_once(PathHelper::getThemeFilePath(
        basename($action['logic_file']),
        dirname($action['logic_file'])
    ));

    // Set up session if needed
    if ($action['requires_session']) {
        $session = SessionControl::get_instance();
        $session->set_api_user($api_user->key);
    }

    // Build parameters from request body
    $get_params = $_GET;
    $post_params = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    // Call the logic function
    try {
        $result = call_user_func($action['logic_function'], $get_params, $post_params);
    } catch (Exception $e) {
        $result = LogicResult::error($e->getMessage());
    }

    // Clean up session
    if ($action['requires_session']) {
        $session->clear_api_user();
    }

    // Translate result to API response
    $translated = api_translate_logic_result($result, $action_name);
    $response = $translated['response'];
    http_response_code($translated['status_code']);
}
```

### 6. Action Discovery Endpoint

Allow API consumers to see what actions are available:

**URL:** `GET /api/v1/actions`

**Response:**
```json
{
    "api_version": "1.0",
    "success_message": "Available actions",
    "data": {
        "register": {
            "description": "Register a new user account",
            "min_permission": 2,
            "requires_session": false
        },
        "event_register": {
            "description": "Register for an event",
            "min_permission": 2,
            "requires_session": true
        }
    }
}
```

## Implementation Priority

### Phase 1: Core Infrastructure
1. Create `api_actions.php` registry
2. Add `api_translate_logic_result()` helper
3. Add action routing to `apiv1.php`
4. Add `set_api_user()`/`clear_api_user()` to SessionControl

### Phase 2: Initial Actions
5. Enable `register` action (good test case — no session required)
6. Enable `password_reset_request` action
7. Enable `contact_preferences` action

### Phase 3: Complex Actions
8. Enable `event_register` and `event_withdraw` (require session simulation)
9. Enable `survey_submit`
10. Add action discovery endpoint

## Testing Requirements

### Unit Testing
- [ ] `api_translate_logic_result()` correctly translates all LogicResult types
- [ ] Action registry correctly validates action names
- [ ] Permission checks work for action endpoints

### Integration Testing
- [ ] `POST /api/v1/action/register` with valid data creates a user
- [ ] `POST /api/v1/action/register` with duplicate email returns validation error
- [ ] `POST /api/v1/action/register` with missing fields returns validation errors
- [ ] Actions requiring session work with API user simulation
- [ ] Unknown action names return 404
- [ ] Insufficient permissions return 403

### Security Testing
- [ ] Actions cannot be called without authentication
- [ ] Permission levels are enforced per-action
- [ ] Session simulation doesn't leak state between requests
- [ ] JSON request body is properly parsed and sanitized

## Backward Compatibility

Fully backward compatible:
- New `/api/v1/action/*` route doesn't conflict with existing model CRUD routes
- No changes to existing API behavior
- Action registry is opt-in (only explicitly listed functions are exposed)

## Security Considerations

- **Whitelist only:** Only explicitly registered actions are available. A new logic function is NOT automatically exposed.
- **Permission per action:** Each action has its own minimum permission level.
- **Session isolation:** API session simulation must not persist beyond the request.
- **Input sanitization:** Logic functions already validate their own input. The API layer adds authentication/authorization on top.
- **No admin actions:** Admin logic functions should not be exposed through the public API without careful review.

## Documentation Requirements

Update `/docs/api.md` (created by the security hardening spec) with the following new sections:

### New Section: Action Endpoints

#### Actions Overview
- Actions execute multi-step business logic (registration, event signup, etc.) rather than raw CRUD
- All actions use `POST /api/v1/action/{action_name}`
- Request body is JSON
- Only explicitly registered actions are available (whitelist, not auto-discovery)

#### Action Request/Response Format

**Request:**
```
POST /api/v1/action/{action_name}
Content-Type: application/json
public_key: {key}
secret_key: {key}

{ "field": "value", ... }
```

**Success response:**
```json
{
    "api_version": "1.0",
    "success_message": "Action 'action_name' completed successfully.",
    "redirect": "/some/path",
    "data": { ... }
}
```
- `redirect` is included when the action would have redirected in the web UI (informational — the API consumer decides what to do with it)
- `data` contains any output data from the logic function

**Validation error response (HTTP 422):**
```json
{
    "api_version": "1.0",
    "errortype": "ValidationError",
    "error": "Please correct the errors below",
    "validation_errors": {
        "field_name": "Error message for this field"
    },
    "data": {}
}
```

**Action error response (HTTP 422):**
```json
{
    "api_version": "1.0",
    "errortype": "ActionError",
    "error": "This feature is turned off",
    "data": {}
}
```

#### Available Actions Reference
For each registered action, document:
- Action name (URL slug)
- Description
- Required permission level
- Whether it requires session context
- Required and optional fields in the request body
- Example request and response
- Possible error messages

**Initial actions to document:**

| Action | Description | Permission | Fields |
|--------|------------|-----------|--------|
| `register` | Register a new user | 2 | `usr_email`, `usr_first_name`, `usr_last_name`, `password` |
| `password_reset_request` | Request password reset email | 2 | `usr_email` |
| `contact_preferences` | Update contact preferences | 2 | (varies by site config) |
| `event_register` | Register for an event | 2 | `evt_event_id`, plus registration fields |
| `event_withdraw` | Withdraw from an event | 2 | `evt_event_id` |
| `survey_submit` | Submit a survey response | 2 | `srv_survey_id`, plus answer fields |

#### Action Discovery Endpoint
- `GET /api/v1/actions` returns a list of available actions with descriptions and permission requirements
- Useful for API consumers to programmatically determine what actions are available to their key

### New Error Types to Add to Error Reference Table
| Status Code | Error Type | Meaning |
|-------------|-----------|---------|
| 404 | ActionError | Unknown action name |
| 422 | ActionError | Business logic error (e.g., feature disabled, invalid state) |
| 422 | ValidationError | Input validation failed — check `validation_errors` for field-level detail |

### Update CLAUDE.md

The API doc entry added by the security hardening spec covers this too — no additional CLAUDE.md changes needed. Just ensure the doc mentions both CRUD and action endpoints.

## Future Enhancements

- Action-specific rate limiting (e.g., registration limited to 5/minute per IP)
- Webhook callbacks for long-running actions
- Batch action execution
- Action-specific API key scopes (key X can only call actions Y and Z)
- OpenAPI/Swagger spec generation from the action registry
