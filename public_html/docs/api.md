# REST API Documentation

## Overview

The Joinery platform provides a REST API for programmatic access to data and operations.

- **Base URL:** `https://{site-domain}/api/v1/`
- **Format:** JSON (all requests and responses)
- **Methods:** GET (read), POST (create), PUT (update), DELETE (soft delete)
- **HTTPS Required:** All requests must use HTTPS

## Authentication

All API requests require two custom headers:

```
public_key: {your_public_key}
secret_key: {your_secret_key}
```

### Obtaining API Keys

API keys are created by an administrator via **Admin > API Keys**. Each key is associated with a user account. The key inherits that user's identity for object-level authorization checks.

### Key Properties

| Property | Description |
|----------|-------------|
| `public_key` | Public identifier sent in requests |
| `secret_key` | Secret verified via bcrypt hash comparison |
| `is_active` | Key must be active to authenticate |
| `start_time` | If set, key is rejected before this time (UTC) |
| `expires_time` | If set, key is rejected after this time (UTC) |
| `ip_restriction` | Comma-separated list of allowed IPs (optional) |
| `permission` | Access level (see Permission Levels below) |

## Permission Levels

| Level | Read | Create/Update | Delete | Description |
|-------|------|--------------|--------|-------------|
| 1 | Yes | No | No | Read-only |
| 2 | No | Yes | No | Write-only |
| 3 | Yes | Yes | No | Read + Write |
| 4+ | Yes | Yes | Yes | Full access |

**Note:** Permission level 2 grants write access but blocks read operations (GET requests).

## CRUD Endpoints

### Read Single Object

```
GET /api/v1/{ClassName}/{id}
```

**Example:** `GET /api/v1/User/123`

**Response:**
```json
{
    "api_version": "1.0",
    "success_message": "User found.",
    "data": {
        "usr_user_id": 123,
        "usr_first_name": "Jane",
        "usr_last_name": "Doe",
        "usr_email": "jane@example.com"
    }
}
```

### List Objects (Collection)

```
GET /api/v1/{ClassName}s?page=0&numperpage=10&sort=field&sdirection=ASC
```

Add a trailing **s** to the class name for collections.

**Pagination Parameters:**

| Parameter | Default | Description |
|-----------|---------|-------------|
| `page` | 0 | Page number (0-based) |
| `numperpage` | 3 | Items per page |
| `sort` | (none) | Database column to sort by |
| `sdirection` | ASC | Sort direction: `ASC` or `DESC` |

Any additional query parameters are passed as filter options to the Multi class. Check the specific Multi class to see which filter keys it accepts.

**Example:** `GET /api/v1/Users?page=0&numperpage=20&sort=usr_id&sdirection=DESC`

**Response:**
```json
{
    "api_version": "1.0",
    "success_message": "",
    "num_results": 100,
    "page": 0,
    "numperpage": 20,
    "data": [ ... ]
}
```

### Create Object

```
POST /api/v1/{ClassName}
Content-Type: application/x-www-form-urlencoded

field1=value1&field2=value2
```

If the model has a `CreateNew()` static method, it is called first. Otherwise, a new object is created and fields are set from the POST body.

**Response:**
```json
{
    "api_version": "1.0",
    "success_message": "New User successful.",
    "data": { ... }
}
```

### Update Object

```
PUT /api/v1/{ClassName}/{id}?field1=value1&field2=value2
```

Fields to update are passed as query string parameters.

**Response:**
```json
{
    "api_version": "1.0",
    "success_message": "User update successful.",
    "data": { ... }
}
```

### Soft Delete Object

```
DELETE /api/v1/{ClassName}/{id}
```

Sets the delete timestamp on the object. Does not permanently remove data.

**Response:**
```json
{
    "api_version": "1.0",
    "success_message": "Deletion successful.",
    "data": { ... }
}
```

## Available Models

Any SystemBase model class is available via the API. Class names are case-sensitive and use PascalCase.

Common models include: `User`, `Product`, `Event`, `EventRegistrant`, `EventSession`, `Order`, `OrderItem`, `Group`, `GroupMember`, `Post`, `Page`, `Email`, `Message`, `File`, `CouponCode`, `SubscriptionTier`, `Location`, `Video`, `Comment`, `Survey`, `SurveyAnswer`, `Question`, `QuestionOption`, `MailingList`, `MailingListRegistrant`.

## Error Handling

### Error Response Format

```json
{
    "api_version": "1.0",
    "errortype": "AuthenticationError",
    "error": "Error: description of what went wrong",
    "data": ""
}
```

### Error Types and HTTP Status Codes

| Status | Error Type | Meaning |
|--------|-----------|---------|
| 400 | AuthenticationError | Missing headers, invalid key, deleted user |
| 400 | TransactionError | Object not found, validation failure, save error, invalid object name |
| 401 | AuthenticationError | Wrong secret, IP restricted, inactive/expired key |
| 403 | AuthenticationError | Insufficient permission for this operation |
| 426 | SecurityError | HTTPS required |
| 429 | RateLimitError | Rate limit exceeded |

## Rate Limiting

The API enforces two rate limits per IP address:

| Limit | Threshold | Window |
|-------|-----------|--------|
| General requests | 1,000 | Per hour |
| Failed auth attempts | 10 | Per 15 minutes |

When exceeded, the API returns HTTP 429 with a `RateLimitError`. Wait for the time window to pass before retrying.

## HTTPS Requirement

All API requests must use HTTPS. Requests over plain HTTP are rejected with HTTP 426 (Upgrade Required).

This can be disabled for development by setting `api_require_https` to `false` in the site settings.

## CORS

CORS is disabled by default. To enable it, set `api_allowed_origins` in site settings to a comma-separated list of allowed origins:

```
https://example.com,https://app.example.com
```

Preflight `OPTIONS` requests are handled automatically when CORS is configured.

## Security Headers

All API responses include:

```
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
X-XSS-Protection: 1; mode=block
Referrer-Policy: no-referrer
```

## Action Endpoints

Actions execute multi-step business logic (registration, event signup, payments, etc.) rather than raw CRUD operations. All logic functions that have been opted in via a companion `_api()` function are available.

### Making a Logic Function Available via API

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

That's it — no registry file or mapping needed.

### Action Request Format

```
POST /api/v1/action/{action_name}
Content-Type: application/json
public_key: {key}
secret_key: {key}

{ "field": "value", ... }
```

Actions require API key write permission (level 2+).

### Action Response Formats

**Success (HTTP 200):**
```json
{
    "api_version": "1.0",
    "success_message": "Action 'register' completed successfully.",
    "redirect": "/page/register-thanks",
    "data": { ... }
}
```

- `redirect` is included when the action would have redirected in the web UI (informational — the API consumer decides what to do with it)
- `data` contains any output data from the logic function

**Validation error (HTTP 422):**
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

**Action error (HTTP 422):**
```json
{
    "api_version": "1.0",
    "errortype": "ActionError",
    "error": "This feature is turned off",
    "data": {}
}
```

### Available Actions

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
| `event_register` | Register for an event | Yes |
| `event_withdraw` | Withdraw from event | Yes |
| `event_waiting_list` | Join event waiting list | Yes |
| `event_sessions` | Select event sessions | Yes |
| `event_sessions_course` | Select course sessions | Yes |
| `orders_recurring_action` | Recurring order action | Yes |

### Action Discovery Endpoint

```
GET /api/v1/actions
```

Returns a list of all available actions with descriptions. Useful for API consumers to programmatically determine what actions are available.

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

## Error Types

### CRUD Error Types

| Status | Error Type | Meaning |
|--------|-----------|---------|
| 400 | AuthenticationError | Missing headers, invalid key, deleted user |
| 400 | TransactionError | Object not found, validation failure, save error, invalid object name |
| 401 | AuthenticationError | Wrong secret, IP restricted, inactive/expired key |
| 403 | AuthenticationError | Insufficient permission for this operation |
| 426 | SecurityError | HTTPS required |
| 429 | RateLimitError | Rate limit exceeded |

### Action Error Types

| Status | Error Type | Meaning |
|--------|-----------|---------|
| 404 | ActionError | Unknown action name or action not available via API |
| 405 | ActionError | Wrong HTTP method (actions require POST) |
| 422 | ActionError | Business logic error (e.g., feature disabled, invalid state) |
| 422 | ValidationError | Input validation failed — check `validation_errors` for field-level detail |

## Request Logging

All API requests are logged for audit purposes. Logs include: feature, action, IP address, user ID, success/failure, HTTP status code, and response time. Secret keys and request bodies are never logged.

Logs are retained for a configurable period (default: 90 days) and automatically cleaned up by a scheduled task.
