# Inbound Email Testing via Mailgun Webhooks

## Overview

Receive test emails via Mailgun inbound routing so that automated tests can verify email content and extract links. Emails sent by the application are delivered by Mailgun, received back via MX routing on a test subdomain, and POSTed to a webhook that stores them in a database table. Test automation checks the table directly via database queries.

## Architecture

```
App sends email → Mailgun delivers → MX routes back to Mailgun
    → Mailgun POSTs to webhook → Stored in database → Test reads via DB query
```

---

## Manual Setup Steps

### 1. DNS: Add MX Records

| Type | Host | Value | Priority |
|------|------|-------|----------|
| MX | `inbox.joinerytest.site` | `mxa.mailgun.org` | 10 |
| MX | `inbox.joinerytest.site` | `mxb.mailgun.org` | 10 |

### 2. Mailgun: Add Receiving Domain

1. Log into Mailgun dashboard
2. Go to **Receiving**
3. Add domain `inbox.joinerytest.site`
4. Verify MX records are detected

### 3. Mailgun: Create Inbound Route

- **Expression:** `match_recipient(".*@inbox.joinerytest.site")`
- **Action:** `forward("https://joinerytest.site/ajax/mailgun_inbound_webhook")`
- **Action:** `stop()`

---

## Application Code

### 1. Database Table: `iem_inbound_emails`

Defined via data model class (automatic schema management).

| Column | Type | Description |
|--------|------|-------------|
| `iem_inbound_email_id` | serial (PK) | Primary key |
| `iem_sender` | varchar(500) | From address |
| `iem_recipient` | varchar(500) | To address |
| `iem_subject` | varchar(1000) | Subject line |
| `iem_body_plain` | text | Plain text body |
| `iem_body_html` | text | HTML body |
| `iem_received_time` | timestamp | When webhook was received |
| `iem_delete_time` | timestamp | Soft delete |

### 2. Data Models: `InboundEmail` / `MultiInboundEmail`

**File:** `data/inbound_email_class.php`

`InboundEmail` — Standard SystemBase model.

`MultiInboundEmail` — Standard SystemMultiBase collection class with filter options:
- `recipient` - Filter by recipient address
- `sender` - Filter by sender address

### 3. Webhook Endpoint

**File:** `ajax/mailgun_inbound_webhook.php`

1. Verify POST request
2. Validate Mailgun signature (HMAC-SHA256 using `timestamp`, `token`, `signature` with API key)
3. Extract: `sender`, `recipient`, `subject`, `body-plain`, `body-html`
4. Create `InboundEmail`, populate, save
5. Return 200 on success, 406 on invalid signature

---

## Testing Workflow

1. Create test user with email `testuser@inbox.joinerytest.site`
2. Trigger action that sends email (registration, password reset, etc.)
3. Wait a few seconds for delivery
4. Query database: `SELECT * FROM iem_inbound_emails WHERE iem_recipient LIKE '%testuser%' ORDER BY iem_received_time DESC LIMIT 1;`
5. Extract links or verify content from the result
6. Periodically clean up: `DELETE FROM iem_inbound_emails WHERE iem_received_time < now() - interval '7 days';`

---

## Files to Create

| File | Purpose |
|------|---------|
| `data/inbound_email_class.php` | InboundEmail + MultiInboundEmail model classes |
| `ajax/mailgun_inbound_webhook.php` | Webhook endpoint |

## Files to Modify

None.

## Manual Steps Required

| Step | When |
|------|------|
| Add MX records for `inbox.joinerytest.site` | Before testing |
| Add receiving domain in Mailgun dashboard | Before testing |
| Create inbound route in Mailgun | Before testing |
| Run database update (for new table) | After code deployment |
