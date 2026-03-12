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

Model classes are auto-discovered — no registry or mapping file. The API simply checks if the class exists.

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

## Design Principles

**No registry file.** Consistent with the rest of the system (model auto-discovery, settings auto-creation, plugin auto-discovery), action endpoints use convention-based discovery with opt-in via a companion function in each logic file.

**No per-action permissions.** Access control is already handled by two layers:
1. **API key permission level** (`apk_permission`) — controls read vs write access
2. **Logic functions themselves** — already perform their own business rule validation, ownership checks, etc.

Adding a third per-action permission layer would be redundant.

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

### 2. Convention-Based Action Discovery (No Registry)

Instead of a separate registry file, logic functions opt in to API exposure by defining a companion metadata function. This follows the same pattern as plugin `settings_form.php` discovery — no mapping file to maintain, and the configuration lives with the code it describes.

**Convention:**
- Action name in URL maps to logic file: `{action_name}` → `logic/{action_name}_logic.php`
- Logic function name follows existing convention: `{action_name}_logic()`
- Opt-in via companion function: `{action_name}_logic_api()`

**Example — making `register` available via API:**

In `logic/register_logic.php`, add:

```php
/**
 * API metadata — presence of this function opts this logic into API access.
 * @return array Configuration: 'requires_session' (default true), 'description'
 */
function register_logic_api() {
    return [
        'requires_session' => false,
        'description' => 'Register a new user account',
    ];
}
```

That's it. No registry file, no mapping.

**How the API resolves an action:**

1. Extract action name from URL: `/api/v1/action/register` → `register`
2. Validate action name matches `^[a-zA-Z0-9_]+$` (security gate)
3. Build logic file path: `logic/register_logic.php`
4. Check if file exists — 404 if not
5. Include the file
6. Check if `register_logic_api()` function exists — 404 if not (this is the opt-in gate)
7. Check if `register_logic()` function exists — 500 if not (broken logic file)
8. Call `register_logic_api()` to get metadata
9. Execute `register_logic($get_params, $post_params)`

**Why this is secure:**
- Logic files are **closed by default** — no `_api()` function means no API access
- You can't expose a function by accident — you have to deliberately add the companion function
- The action name validation (`^[a-zA-Z0-9_]+$`) prevents path traversal
- The file must exist in the `logic/` directory — no arbitrary file inclusion

**Metadata options:**

| Key | Default | Description |
|-----|---------|-------------|
| `requires_session` | `true` | Whether to set up API user session before calling the logic function |
| `description` | `''` | Human-readable description (used by discovery endpoint) |

Defaults are chosen so the simplest opt-in is:

```php
function some_action_logic_api() {
    return [];
}
```

This exposes the action with session simulation enabled and no description.

**Actions to expose via API:**

All logic functions that perform operations (as opposed to display-only rendering) should be opted in. Display-only logic is already accessible via the CRUD API.

**Standard signature** — just add `_api()` function to existing file:

| Logic file | Description | Session |
|------------|------------|---------|
| `register_logic` | Register a new user account | No |
| `password_reset_1_logic` | Request password reset email | No |
| `password_reset_2_logic` | Set new password via reset code | No |
| `password_set_logic` | Set password on first login | No |
| `password_edit_logic` | Change password (logged in) | Yes |
| `change_password_required_logic` | Forced password change | Yes |
| `contact_preferences_logic` | Update contact preferences | Yes |
| `account_edit_logic` | Update profile fields | Yes |
| `address_edit_logic` | Update address | Yes |
| `phone_numbers_edit_logic` | Update phone numbers | Yes |
| `change_tier_logic` | Change subscription tier | Yes |
| `survey_logic` | Submit survey response | Yes |
| `booking_logic` | Book an appointment | Yes |
| `cart_logic` | Add item to cart | Yes |
| `cart_clear_logic` | Clear cart | Yes |
| `event_withdraw_logic` | Withdraw from event | Yes |
| `event_sessions_logic` | Select event sessions | Yes |
| `event_sessions_course_logic` | Select course sessions | Yes |
| `orders_recurring_action_logic` | Recurring order action | Yes |

**Non-standard signature** — create wrapper file with `_api()` function:

| Original file | Extra params | Wrapper action name |
|---------------|-------------|-------------------|
| `event_logic.php` | `$event, $instance_date` | `event_register` |
| `event_waiting_list_logic.php` | `$event_id` | `event_waiting_list` |

**Wrapper file example** — `logic/event_register_logic.php`:

```php
<?php
require_once(PathHelper::getIncludePath('data/events_class.php'));
require_once(PathHelper::getThemeFilePath('event_logic.php', 'logic'));

function event_register_logic($get_vars, $post_vars) {
    $event = new Event($post_vars['evt_event_id'] ?? $get_vars['evt_event_id'] ?? null, TRUE);
    $instance_date = $post_vars['instance_date'] ?? $get_vars['instance_date'] ?? null;
    return event_logic($get_vars, $post_vars, $event, $instance_date);
}

function event_register_logic_api() {
    return [
        'requires_session' => true,
        'description' => 'Register for an event',
    ];
}
```

The wrapper is a standalone file — the original logic file is not modified.

**Excluded — not exposed via API:**

| Logic file | Reason |
|------------|--------|
| `login_logic` | API uses key-based auth, not session login |
| `cart_charge_logic` | Requires Stripe client-side tokenization |
| `get_subscriptions_logic` | Not a standard logic function (renders HTML directly) |
| `get_appointments_logic` | Display-only — use CRUD API |
| `events_logic` | Display-only — use CRUD API |
| `products_logic` | Display-only — use CRUD API |
| `product_logic` | Display-only — use CRUD API |
| `post_logic` | Display-only — use CRUD API |
| `blog_logic` | Display-only — use CRUD API |
| `video_logic` | Display-only — use CRUD API |
| `location_logic` | Display-only — use CRUD API |
| `page_logic` | Display-only — use CRUD API |
| `list_logic` | Display-only — use CRUD API |
| `lists_logic` | Display-only — use CRUD API |
| `pricing_logic` | Display-only — use CRUD API |
| `subscriptions_logic` | Display-only — use CRUD API |
| `items_logic` | Display-only — use CRUD API |
| `profile_logic` | Display-only — use CRUD API |
| `list_signup_logic` | Component-only (non-standard `$config` signature) |

**Prerequisite: Rename hyphenated logic files**

Several logic files use hyphens in their filenames but underscores in their function names. Rename these for consistency with the convention:

| Current filename | New filename | Function name (unchanged) |
|-----------------|-------------|--------------------------|
| `password-reset-1_logic.php` | `password_reset_1_logic.php` | `password_reset_1_logic()` |
| `password-reset-2_logic.php` | `password_reset_2_logic.php` | `password_reset_2_logic()` |
| `password-set_logic.php` | `password_set_logic.php` | `password_set_logic()` |
| `change-password-required_logic.php` | `change_password_required_logic.php` | `change_password_required_logic()` |

Update any `require_once` or `getThemeFilePath` references to these files throughout the codebase. The function names stay the same, so no logic changes are needed.

**Actions that should NOT have an `_api()` function:**
- `login_logic` — API uses key-based auth, not session login
- `cart_charge_logic` — Requires Stripe client-side integration (tokenization)
- `admin_*` logic functions — Admin operations should use the existing CRUD API
- `get_subscriptions_logic` — Not a standard logic function (renders HTML directly)
- Any logic function that depends on browser-specific state (cookies, file uploads, multipart form data)

### 3. LogicResult-to-JSON Translation

**New helper function** in apiv1.php:

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

Some logic functions call `SessionControl::get_instance()` and expect a logged-in user (checking `get_user_id()`, `get_permission()`, etc.). For API calls, we need to simulate this using the API key's associated user.

**Approach:** Set the API user as the session user before calling the logic function, then restore state afterward.

```php
// Before calling logic function that requires_session:
$session = SessionControl::get_instance();
$session->set_api_user($api_user->key);  // New method

// Call logic function
$result = $logic_function($get_params, $post_params);

// Restore
$session->clear_api_user();
```

**New SessionControl methods:**
- `set_api_user($user_id)` — Sets `$_SESSION['usr_user_id']`, `$_SESSION['loggedin']`, and `$_SESSION['permission']` for the API user without creating a real browser session. Stores original values for restoration.
- `clear_api_user()` — Restores the original session state.
- `is_api_context()` — Returns true if currently in API context (useful for logic functions that need to behave differently, e.g., skipping CSRF checks).

### 5. Integration into apiv1.php

Add action handling after the existing CRUD routing. The action name comes from `$url_segments[3]` (the 4th URL segment), with `$url_segments[2]` being `"action"`:

```php
else if (strtolower($url_segments[2] ?? '') === 'action' && isset($url_segments[3])) {
    // Actions always require write permission on the API key
    if ($api_entry->get('apk_permission') < 2) {
        api_error('Insufficient API key permission for actions', 'AuthenticationError', 403);
    }

    $action_name = strtolower($url_segments[3]);

    // Validate action name format (security: prevent path traversal)
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $action_name)) {
        api_error('Invalid action name', 'ActionError', 400);
    }

    // Convention: action name maps to logic/{action_name}_logic.php
    $logic_filename = $action_name . '_logic.php';
    $logic_filepath = PathHelper::getThemeFilePath($logic_filename, 'logic');

    if (!file_exists($logic_filepath)) {
        api_error('Unknown action: ' . $action_name, 'ActionError', 404);
    }

    require_once($logic_filepath);

    // Check for opt-in: the logic file must define {action_name}_logic_api()
    $api_meta_function = $action_name . '_logic_api';
    $logic_function = $action_name . '_logic';

    if (!function_exists($api_meta_function)) {
        api_error('Unknown action: ' . $action_name, 'ActionError', 404);
    }

    if (!function_exists($logic_function)) {
        api_error('Action is misconfigured: ' . $action_name, 'ActionError', 500);
    }

    // Get metadata
    $meta = call_user_func($api_meta_function);
    $requires_session = $meta['requires_session'] ?? true;

    // Set up session simulation if needed
    if ($requires_session) {
        $session = SessionControl::get_instance();
        $session->set_api_user($api_user->key);
    }

    // Build parameters from JSON request body
    $get_params = $_GET;
    $post_params = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    // Call the logic function
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    try {
        $result = call_user_func($logic_function, $get_params, $post_params);
    } catch (Exception $e) {
        $result = LogicResult::error($e->getMessage());
    }

    // Clean up session simulation
    if ($requires_session) {
        $session->clear_api_user();
    }

    // Translate LogicResult to API response
    $translated = api_translate_logic_result($result, $action_name);
    $response = $translated['response'];
    http_response_code($translated['status_code']);
}
```

### 6. Action Discovery Endpoint

Allow API consumers to see what actions are available:

**URL:** `GET /api/v1/actions`

This scans the `logic/` directory for files matching `*_logic.php`, includes each, and checks for the companion `_api()` function.

**Response:**
```json
{
    "api_version": "1.0",
    "success_message": "Available actions",
    "data": {
        "register": {
            "description": "Register a new user account",
            "requires_session": false
        },
        "event_register": {
            "description": "Register for an event",
            "requires_session": true
        }
    }
}
```

**Note:** The discovery endpoint includes all logic files, which may be slow on first call. Consider caching the result if the logic directory is large. For this system (~20 logic files) it should be fine.

**Implementation:** Add this as a separate branch before the action handler:

```php
else if (strtolower($url_segments[2] ?? '') === 'actions' && $request_method === 'get') {
    $logic_dir = PathHelper::getIncludePath('logic');
    $actions = [];

    foreach (glob($logic_dir . '/*_logic.php') as $file) {
        $basename = basename($file, '.php');           // e.g., "register_logic"
        $action_name = substr($basename, 0, -6);       // e.g., "register" (strip "_logic")
        $api_meta_function = $basename . '_api';        // e.g., "register_logic_api"

        require_once($file);

        if (function_exists($api_meta_function)) {
            $meta = call_user_func($api_meta_function);
            $actions[$action_name] = [
                'description' => $meta['description'] ?? '',
                'requires_session' => $meta['requires_session'] ?? true,
            ];
        }
    }

    ksort($actions);
    api_success($actions, 'Available actions');
}
```

## Implementation Priority

### Phase 0: Prerequisite Cleanup
1. Rename hyphenated logic files to use underscores (4 files)
2. Update all references to renamed files (~7 `getThemeFilePath` calls in view files)

### Phase 1: Core Infrastructure
3. Add `api_translate_logic_result()` helper to `apiv1.php`
4. Add `set_api_user()`/`clear_api_user()`/`is_api_context()` to SessionControl
5. Add action routing to `apiv1.php`
6. Add action discovery endpoint to `apiv1.php`

### Phase 2: All Actions
7. Add `_api()` functions to all 19 standard-signature logic files
8. Create 2 wrapper files (`event_register_logic.php`, `event_waiting_list_logic.php`)

### Phase 3: Documentation & Testing
9. Update `/docs/api.md` with action endpoint documentation
10. Integration testing

## Testing Requirements

### Unit Testing
- [ ] `api_translate_logic_result()` correctly translates success, error, and validation error LogicResults
- [ ] Action name validation rejects path traversal attempts (`../`, special characters)
- [ ] Missing `_api()` function returns 404 (not 500)

### Integration Testing
- [ ] `POST /api/v1/action/register` with valid data creates a user
- [ ] `POST /api/v1/action/register` with duplicate email returns ValidationError with field-level detail
- [ ] `POST /api/v1/action/register` with missing fields returns ValidationError
- [ ] Actions requiring session correctly simulate the API key's user
- [ ] Unknown action names return 404
- [ ] Logic files without `_api()` functions return 404
- [ ] Read-only API keys (permission < 2) get 403 on all actions
- [ ] `GET /api/v1/actions` returns only opted-in actions

### Security Testing
- [ ] Actions cannot be called without authentication
- [ ] API key write permission is required for all actions
- [ ] Session simulation doesn't leak state between requests
- [ ] JSON request body is properly parsed and sanitized
- [ ] Action names like `../../config/Globalvars_site` are rejected by the regex

## Backward Compatibility

Fully backward compatible:
- New `/api/v1/action/*` route doesn't conflict with existing model CRUD routes (model names are capitalized, "action" is lowercase)
- No changes to existing API behavior
- Logic files without `_api()` functions are completely unaffected

## Security Considerations

- **Closed by default:** Logic functions are NOT accessible via API unless they explicitly define a `_api()` companion function.
- **Action name validation:** Regex `^[a-zA-Z0-9_]+$` prevents path traversal before any file operations.
- **Write permission required:** All actions require the API key to have write permission (level 2+), enforced once at the top of the action handler.
- **Session isolation:** API session simulation stores and restores original session state; must not persist beyond the request.
- **Input sanitization:** Logic functions already validate their own input. The API layer adds authentication on top.
- **No admin actions:** Admin logic files live in `adm/`, not `logic/`, so they're naturally excluded by the convention.

## Documentation Requirements

Update `/docs/api.md` with:

### New Section: Action Endpoints

#### Actions Overview
- Actions execute multi-step business logic (registration, event signup, etc.) rather than raw CRUD
- All actions use `POST /api/v1/action/{action_name}`
- Request body is JSON
- Actions are opt-in per logic file — no centralized registry

#### Making a Logic Function Available via API

Add a companion function to your logic file:

```php
// In logic/your_action_logic.php

function your_action_logic_api() {
    return [
        'requires_session' => true,   // default: true
        'description' => 'What this action does',
    ];
}
```

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

#### Available Actions

| Action | Description | Session |
|--------|------------|---------|
| `register` | Register a new user account | No |
| `password_reset_1` | Request password reset email | No |
| `password_reset_2` | Set new password via reset code | No |
| `password_set` | Set password on first login | No |
| `password_edit` | Change password (logged in) | Yes |
| `change_password_required` | Forced password change | Yes |
| `contact_preferences` | Update contact preferences | Yes |
| `account_edit` | Update profile fields | Yes |
| `address_edit` | Update address | Yes |
| `phone_numbers_edit` | Update phone numbers | Yes |
| `change_tier` | Change subscription tier | Yes |
| `survey` | Submit survey response | Yes |
| `booking` | Book an appointment | Yes |
| `cart` | Add item to cart | Yes |
| `cart_clear` | Clear cart | Yes |
| `event_register` | Register for an event (wrapper) | Yes |
| `event_withdraw` | Withdraw from event | Yes |
| `event_waiting_list` | Join event waiting list (wrapper) | Yes |
| `event_sessions` | Select event sessions | Yes |
| `event_sessions_course` | Select course sessions | Yes |
| `orders_recurring_action` | Recurring order action | Yes |

#### Action Discovery Endpoint
- `GET /api/v1/actions` returns a list of all available actions with descriptions
- Useful for API consumers to programmatically determine what actions are available

### New Error Types to Add to Error Reference Table
| Status Code | Error Type | Meaning |
|-------------|-----------|---------|
| 404 | ActionError | Unknown action name or action not available via API |
| 422 | ActionError | Business logic error (e.g., feature disabled, invalid state) |
| 422 | ValidationError | Input validation failed — check `validation_errors` for field-level detail |

## Future Enhancements

- Action-specific rate limiting (e.g., registration limited to 5/minute per IP)
- Webhook callbacks for long-running actions
- Batch action execution
- Action-specific API key scopes (key X can only call actions Y and Z)
- OpenAPI/Swagger spec generation from the discovery endpoint
