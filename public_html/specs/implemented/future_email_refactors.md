# Future Email System Refactoring

## 1. DNS Authentication Status in Admin Settings

### Goal
Add a quick SPF/DKIM/DMARC status panel to the email settings page so admins can see at a glance whether their configured domains have proper email authentication, with links to the existing deep-dive tools.

### Current State
- **`admin_settings_email.php`** (lines 276-319) already shows service status (Mailgun/SMTP configured or not) via `EmailSender::validateService()`. DNS auth is not shown here.
- **`utils/email_setup_check.php`** has a full `EmailAuthChecker` class with `checkSPF()`, `checkDKIM()`, `checkDMARC()` methods. These are private methods — not reusable from other files without refactoring.
- **`tests/email/suites/AuthenticationTests.php`** has simpler DNS checks using `dns_get_record()` directly.
- **`tests/email/auth_analysis.php`** does real-world delivery testing (send + IMAP retrieval). Unique and valuable — leave it alone.

### Implementation

#### Step 1: Extract DNS checking from `email_setup_check.php` into a reusable utility

The `EmailAuthChecker` class in `utils/email_setup_check.php` already has solid `checkSPF()`, `checkDKIM()`, and `checkDMARC()` methods — but they're private and embedded in the page. Extract the core checking logic into `/includes/DnsAuthChecker.php`:

```php
class DnsAuthChecker {
    /**
     * Quick check of SPF/DKIM/DMARC for a domain
     * Returns array with 'spf', 'dkim', 'dmarc' keys, each containing:
     *   'status' => 'pass'|'warn'|'fail'
     *   'detail' => string description
     */
    public static function quickCheck($domain) { ... }

    public static function checkSPF($domain) { ... }
    public static function checkDKIM($domain, $selectors = ['mx', 'default', 'mail', 'dkim']) { ... }
    public static function checkDMARC($domain) { ... }
}
```

The basic SPF/DKIM/DMARC record checks move here. The advanced scanning in `EmailAuthChecker` (400+ DKIM selectors, pattern discovery, date-based discovery, DNS enumeration) stays in `email_setup_check.php` as local methods — that's deep-dive functionality specific to that tool.

#### Step 2: Refactor `email_setup_check.php` to use `DnsAuthChecker`

Update `EmailAuthChecker` to:
- Use `DnsAuthChecker::checkSPF()`, `DnsAuthChecker::checkDKIM()`, `DnsAuthChecker::checkDMARC()` for its basic checks
- Keep its comprehensive DKIM scanning (`discoverDKIMSelectors`, `discoverSelectorPatterns`, `discoverDateBasedSelectors`, `discoverProviderPatterns`, `attemptDNSEnumeration`) as local private methods that extend beyond what the shared class does
- The page still works exactly as before — same UI, same results — it just delegates the foundation to the shared class

#### Step 3: Update `AuthenticationTests.php` to use `DnsAuthChecker`

Replace the inline `dns_get_record()` calls in `testSPFRecord()`, `testDKIMConfiguration()`, and `testDMARCPolicy()` with calls to the shared class. This eliminates the third copy of this logic.

#### Step 4: Add DNS status panel to `admin_settings_email.php`

Add below the existing "Service Status" section (after line 319). Auto-detect domains from `defaultemail` and `mailgun_domain` settings.

```
Email Authentication Status
  SPF:   [pass/warn/fail] mg.example.com
  DKIM:  [pass/warn/fail] mx._domainkey.mg.example.com
  DMARC: [pass/warn/fail] _dmarc.mg.example.com
  [Detailed Analysis] -> links to /utils/email_setup_check?domain=mg.example.com
```

Run the check only when `?run_dns_check=1` is in the URL (button click), not on every page load — DNS lookups are slow and shouldn't block settings page rendering.

### Files Affected
- **New:** `includes/DnsAuthChecker.php` — extracted from `EmailAuthChecker`
- **Modified:** `utils/email_setup_check.php` — `EmailAuthChecker` delegates basic checks to shared class
- **Modified:** `tests/email/suites/AuthenticationTests.php` — use shared class instead of inline DNS calls
- **Modified:** `adm/admin_settings_email.php` — add DNS status section

### What NOT to do
- Don't remove `AuthenticationTests.php` or `auth_analysis.php` — they serve different purposes (automated test suite, real-world delivery verification)
- Don't cache DNS results — the on-demand button click approach is simpler and more reliable
- Don't build monitoring/periodic checks — overkill for now


## 2. Automatic Email Retry Mechanism

### Goal
Two things:
1. Fix `QueuedEmail::send()` to use `EmailSender` (service selection + fallback) instead of calling `systemmailer` directly
2. Add automatic retry for failed emails — currently when `EmailSender` fails on both services, the failure is logged but the email is lost

### Current State

**`QueuedEmail` class** (`data/queued_email_class.php`):
- Stores emails as discrete columns: `equ_from`, `equ_to`, `equ_subject`, `equ_body`, etc.
- Status constants: QUEUED(1), READY_TO_SEND(2), LOCKED(3), SENT(4), DELETED(5), ERROR_SENDING(6), NORMAL_MAILER_ERROR(7)
- `send()` method bypasses `EmailSender` — creates a `systemmailer` (SmtpMailer) directly, ignoring service selection and fallback logic
- Single recipient per row

**`MultiQueuedEmail`** supports filtering by `status` and `multi_status`.

**`SendQueuedEmails` task** (`tasks/SendQueuedEmails.php`):
- Runs `every_run` frequency (every 15 minutes via cron)
- Processes **campaign/bulk emails** from the `Email` class, not `QueuedEmail`
- Delegates to `adm/admin_emails_send.php`

**`EmailSender`** (`includes/EmailSender.php`):
- Handles service selection (mailgun/smtp) with fallback
- On total failure, logs "email queued for retry" — but doesn't actually queue anything

### Implementation

#### Step 1: Fix `QueuedEmail::send()` to use `EmailSender`

Replace the `systemmailer` code with `EmailSender`. The method keeps its existing locking/status logic but delegates actual sending:

```php
function send($queue_on_failure = true) {
    $dbhelper = DbConnector::get_instance();
    $dblink = $dbhelper->get_db_link();

    $dblink->beginTransaction();

    $this->load();
    if ($this->get('equ_status') != self::READY_TO_SEND) {
        $dblink->rollBack();
        throw new QueuedEmailException(
            'Attempting to send a Queued Email which is not in the correct state. Aborting...');
    }

    $this->set('equ_status', self::LOCKED);
    $this->save();
    $dblink->commit();

    // Build EmailMessage and send via EmailSender (service selection + fallback)
    require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
    $message = EmailMessage::create(
        $this->get('equ_to'),
        $this->get('equ_subject'),
        $this->get('equ_body')
    );
    $message->from($this->get('equ_from'), $this->get('equ_from_name'));

    $sender = new EmailSender();
    $result = $sender->send($message, $queue_on_failure);

    $dblink->beginTransaction();
    $this->load();

    if ($result) {
        $this->set('equ_status', self::SENT);
        $this->update_stats_sent();
    } else {
        $this->set('equ_status', self::ERROR_SENDING);
    }

    $this->save();
    $dblink->commit();
}
```

#### Step 2: Add `$queue_on_failure` parameter to `EmailSender::send()`

Add a second parameter defaulting to `true`:

```php
public function send(EmailMessage $message, $queue_on_failure = true) {
    // ... existing service selection + fallback logic ...

    if (!$result && !$fallback_result) {
        $this->logEmailDebug("Both primary and fallback services failed");
        if ($queue_on_failure) {
            $this->queueForRetry($message);
        }
    }

    return $result;
}
```

This prevents re-queuing when the retry task calls `QueuedEmail::send()` — it passes `false` since the email is already in the queue.

#### Step 3: Add `queueForRetry()` to `EmailSender`

```php
private function queueForRetry(EmailMessage $message) {
    require_once(PathHelper::getIncludePath('data/queued_email_class.php'));

    foreach ($message->getRecipients() as $recipient) {
        $queued = new QueuedEmail(NULL);
        $queued->set('equ_from', $message->getFrom());
        $queued->set('equ_from_name', $message->getFromName());
        $queued->set('equ_to', $recipient['email']);
        $queued->set('equ_to_name', $recipient['name']);
        $queued->set('equ_subject', $message->getSubject());
        $queued->set('equ_body', $message->getHtmlBody());
        $queued->set('equ_status', QueuedEmail::ERROR_SENDING);
        $queued->set('equ_retry_count', 0);
        $queued->save();
    }
}
```

#### Step 4: Add `equ_retry_count` field and `PERMANENT_FAILURE` constant to `QueuedEmail`

Add to `$field_specifications`:
```php
'equ_retry_count' => array('type'=>'int2', 'default'=>0),
```

Add constant:
```php
const PERMANENT_FAILURE = 8;
```

Update `$status_to_text` to include it.

#### Step 5: Expand `SendQueuedEmails` task to retry failed emails

Add a second pass to the existing task that picks up error rows:

```php
public function run(array $config) {
    // --- Existing campaign send logic (unchanged) ---
    $queued = new MultiEmail(['scheduleddate' => MultiEmail::SCHEDULED_PAST, 'status' => Email::EMAIL_QUEUED]);
    $count = $queued->count_all();
    $campaign_message = '';

    if ($count > 0) {
        define('SCHEDULED_TASK_CONTEXT', true);
        ob_start();
        require(PathHelper::getIncludePath('adm/admin_emails_send.php'));
        $campaign_message = 'Processed ' . $count . ' queued email(s). ' . trim(strip_tags(ob_get_clean()));
    }

    // --- New: retry failed transactional emails ---
    $max_retries = intval($config['max_retries'] ?? 3);
    $failed_emails = new MultiQueuedEmail(
        ['multi_status' => [QueuedEmail::ERROR_SENDING, QueuedEmail::NORMAL_MAILER_ERROR]],
        ['equ_queued_email_id' => 'ASC'],
        50
    );

    $retry_count_total = $failed_emails->count_all();
    $sent = 0;
    $permanent = 0;

    if ($retry_count_total > 0) {
        $failed_emails->load();

        foreach ($failed_emails as $email) {
            $retries = intval($email->get('equ_retry_count'));

            if ($retries >= $max_retries) {
                $email->set('equ_status', QueuedEmail::PERMANENT_FAILURE);
                $email->save();
                $permanent++;
                continue;
            }

            // Set to READY_TO_SEND so send() accepts it, pass false to prevent re-queuing
            $email->set('equ_status', QueuedEmail::READY_TO_SEND);
            $email->save();

            try {
                $email->send(false);
            } catch (Exception $e) {
                // send() already sets ERROR_SENDING on failure
            }

            $email->load();
            if ($email->get('equ_status') == QueuedEmail::SENT) {
                $sent++;
            } else {
                $email->set('equ_retry_count', $retries + 1);
                $email->save();
            }
        }
    }

    $parts = [];
    if ($campaign_message) $parts[] = $campaign_message;
    if ($retry_count_total > 0) $parts[] = "Retried $retry_count_total failed: $sent sent, $permanent permanent failures";
    if (empty($parts)) $parts[] = 'No queued or failed emails to process';

    return ['status' => 'success', 'message' => implode('. ', $parts)];
}
```

Update `SendQueuedEmails.json` to add the config field:
```json
{
    "name": "Send Queued Emails",
    "description": "Sends bulk emails that are queued and past their scheduled send date, and retries failed transactional emails",
    "default_frequency": "every_run",
    "config_fields": {
        "max_retries": {
            "label": "Maximum retry attempts for failed emails",
            "type": "number",
            "default": 3
        }
    }
}
```

### Files Affected
- **Modified:** `data/queued_email_class.php` — add `equ_retry_count` field, `PERMANENT_FAILURE` constant
- **Modified:** `includes/EmailSender.php` — add `$queue_on_failure` param to `send()`, add `queueForRetry()`
- **Modified:** `tasks/SendQueuedEmails.php` + `.json` — add retry pass

### Key Design Decisions
- **Fix `QueuedEmail::send()` first** — all queued email sending now goes through `EmailSender` with proper service selection and fallback, not just retries
- **One field, not four** — only `equ_retry_count` is needed. No `next_retry_time` (task runs every 15 min anyway), no `last_error` or `sent_time` (not worth the complexity)
- **Expand existing task** instead of creating a new one — `SendQueuedEmails` already runs every 15 min, just add a second pass for error rows
- **`$queue_on_failure` boolean** on `EmailSender::send()` prevents infinite re-queuing loops — retries pass `false` since the email is already queued
- **No exponential backoff** — 15-minute intervals (the task frequency) are fine for transient failures. Three retries over ~45 minutes covers most outages
