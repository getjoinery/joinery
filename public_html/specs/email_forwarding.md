# Email Forwarding System

## Overview

A self-hosted email forwarding system that allows Joinery site admins to create virtual mailboxes (aliases) that forward incoming email to one or more real email addresses. Incoming mail is received by Postfix, piped to a PHP script, which looks up the alias in the database and forwards via Joinery's existing EmailSender.

## Architecture

```
Inbound email (SMTP)
  → Postfix (accepts mail for configured domains)
    → Pipes raw email to PHP script via transport
      → PHP parses email headers/body
        → Looks up alias in efa_email_forwarding_aliases table
          → If match: forwards via EmailSender to destination(s)
          → If no match: rejects or discards (configurable)
            → Logs the transaction to efa_email_forwarding_logs
```

All forwarding logic lives in PHP. Postfix is configured minimally as a dumb pipe — one transport entry per domain.

## Files to Create

### Data Layer
- `/data/email_forwarding_alias_class.php` — Single + Multi model for aliases
- `/data/email_forwarding_domain_class.php` — Single + Multi model for managed domains
- `/data/email_forwarding_log_class.php` — Single + Multi model for forwarding logs

### Processing
- `/scripts/email_forwarder.php` — Receives piped email from Postfix, parses and forwards
- `/includes/EmailForwarder.php` — Email parsing and forwarding logic class
- `/includes/SRSRewriter.php` — SRS encode/decode/validate

### Scheduled Tasks
- `/tasks/PurgeOldForwardingLogs.php` — Deletes logs older than `email_forwarding_log_retention_days`
- `/tasks/PurgeOldForwardingLogs.json` — Task configuration

### Admin Interface
- `/adm/admin_email_forwarding.php` — List/manage aliases (view)
- `/adm/admin_email_forwarding_alias.php` — Create/edit single alias (view)
- `/adm/admin_email_forwarding_domains.php` — List/manage forwarding domains (view)
- `/adm/admin_email_forwarding_logs.php` — View forwarding log (view)
- `/adm/logic/admin_email_forwarding_logic.php` — Logic for alias list
- `/adm/logic/admin_email_forwarding_alias_logic.php` — Logic for alias create/edit
- `/adm/logic/admin_email_forwarding_domains_logic.php` — Logic for domain management

### Documentation
- `/docs/email_forwarding.md` — Setup guide, admin usage, server config, DNS, troubleshooting
- Update `/docs/email_system.md` — Add note and link to email forwarding doc
- Update `CLAUDE.md` — Add to documentation index

### Migrations
- Entry in `/migrations/migrations.php` for initial settings and admin menu entries

### Install Script Changes
- `/var/www/html/joinerytest/maintenance_scripts/install_tools/install.sh` — Add Postfix and opendkim packages
- `/var/www/html/joinerytest/maintenance_scripts/install_tools/Dockerfile.template` — Expose port 25, start Postfix in CMD

---

## Data Models

### EmailForwardingDomain

Tracks which domains this system accepts forwarded mail for.

```php
public static $prefix = 'efd';
public static $tablename = 'efd_email_forwarding_domains';
public static $pkey_column = 'efd_email_forwarding_domain_id';

public static $field_specifications = array(
    'efd_email_forwarding_domain_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
    'efd_domain'        => array('type'=>'varchar(255)', 'required'=>true, 'is_nullable'=>false),
    'efd_is_enabled'    => array('type'=>'bool', 'default'=>'true', 'is_nullable'=>false),
    'efd_catch_all_address' => array('type'=>'varchar(500)', 'is_nullable'=>true),
    'efd_reject_unmatched' => array('type'=>'bool', 'default'=>'true', 'is_nullable'=>false),
    'efd_create_time'   => array('type'=>'timestamp(6)', 'default'=>'now()'),
    'efd_update_time'   => array('type'=>'timestamp(6)'),
    'efd_delete_time'   => array('type'=>'timestamp(6)'),
);
```

Fields:
- `efd_domain` — Domain name (e.g., `example.com`). Unique.
- `efd_is_enabled` — Whether forwarding is active for this domain.
- `efd_catch_all_address` — Optional catch-all destination for unmatched aliases. If null and `efd_reject_unmatched` is true, unmatched mail is rejected.
- `efd_reject_unmatched` — If true and no alias matches (and no catch-all), reject with SMTP error. If false, silently discard.

### EmailForwardingAlias

```php
public static $prefix = 'efa';
public static $tablename = 'efa_email_forwarding_aliases';
public static $pkey_column = 'efa_email_forwarding_alias_id';

public static $field_specifications = array(
    'efa_email_forwarding_alias_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
    'efa_efd_email_forwarding_domain_id' => array('type'=>'int4', 'is_nullable'=>false),
    'efa_alias'         => array('type'=>'varchar(255)', 'required'=>true, 'is_nullable'=>false),
    'efa_destinations'  => array('type'=>'text', 'required'=>true, 'is_nullable'=>false),
    'efa_description'   => array('type'=>'varchar(500)'),
    'efa_is_enabled'    => array('type'=>'bool', 'default'=>'true', 'is_nullable'=>false),
    'efa_forward_count' => array('type'=>'int4', 'default'=>'0'),
    'efa_last_forward_time' => array('type'=>'timestamp(6)'),
    'efa_create_time'   => array('type'=>'timestamp(6)', 'default'=>'now()'),
    'efa_update_time'   => array('type'=>'timestamp(6)'),
    'efa_delete_time'   => array('type'=>'timestamp(6)'),
);
```

Fields:
- `efa_efd_email_forwarding_domain_id` — FK to the domain this alias belongs to.
- `efa_alias` — Local part of the address (e.g., `info` for `info@example.com`). Unique per domain.
- `efa_destinations` — Comma-separated list of destination email addresses.
- `efa_description` — Human-readable note (e.g., "Main contact form inbox").
- `efa_is_enabled` — Whether this alias is active.
- `efa_forward_count` — Running total of forwarded messages.
- `efa_last_forward_time` — Timestamp of most recent forward.

### EmailForwardingLog

```php
public static $prefix = 'efl';
public static $tablename = 'efl_email_forwarding_logs';
public static $pkey_column = 'efl_email_forwarding_log_id';

public static $field_specifications = array(
    'efl_email_forwarding_log_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
    'efl_efa_email_forwarding_alias_id' => array('type'=>'int4'),
    'efl_from_address'  => array('type'=>'varchar(500)'),
    'efl_to_address'    => array('type'=>'varchar(500)'),
    'efl_subject'       => array('type'=>'varchar(1000)'),
    'efl_destinations'  => array('type'=>'text'),
    'efl_status'        => array('type'=>'varchar(50)', 'is_nullable'=>false),
    'efl_error_message' => array('type'=>'text'),
    'efl_create_time'   => array('type'=>'timestamp(6)', 'default'=>'now()'),
    'efl_delete_time'   => array('type'=>'timestamp(6)'),
);
```

Status values: `forwarded`, `rejected`, `discarded`, `rate_limited`, `bounce_forwarded`, `error`

---

## Scheduled Task — PurgeOldForwardingLogs

Follows the existing task pattern (see `PurgeOldRequestLogs` for reference).

**`/tasks/PurgeOldForwardingLogs.json`:**
```json
{
    "name": "Purge Old Forwarding Logs",
    "description": "Deletes email forwarding log entries older than a configurable number of days",
    "default_frequency": "daily",
    "default_time": "03:30:00",
    "config_fields": {
        "days_to_keep": {"type": "number", "label": "Days to Keep", "required": true}
    }
}
```

**`/tasks/PurgeOldForwardingLogs.php`:**
```php
<?php
require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class PurgeOldForwardingLogs implements ScheduledTaskInterface {

    public function run(array $config) {
        $days_to_keep = isset($config['days_to_keep']) ? (int)$config['days_to_keep'] : 0;
        if ($days_to_keep <= 0) {
            return array('status' => 'skipped', 'message' => 'days_to_keep not configured');
        }

        $db = DbConnector::get_instance()->get_db_link();
        $sql = "DELETE FROM efl_email_forwarding_logs
                WHERE efl_create_time < NOW() - (INTERVAL '1 day' * :days)";
        $stmt = $db->prepare($sql);
        $stmt->execute([':days' => $days_to_keep]);
        $deleted = $stmt->rowCount();

        if ($deleted === 0) {
            return array('status' => 'success', 'message' => 'No old forwarding logs to purge');
        }

        return array('status' => 'success', 'message' => 'Purged ' . $deleted . ' forwarding log(s) older than ' . $days_to_keep . ' days');
    }
}
```

The `email_forwarding_log_retention_days` setting provides the default, but the task's own `days_to_keep` config field (set in the admin Scheduled Tasks page) is what the task actually uses.

---

## EmailForwarder Class (`/includes/EmailForwarder.php`)

Core class that handles email parsing and forwarding logic. Separated from the Postfix pipe script so it can be tested independently.

```php
class EmailForwarder {

    /**
     * Process a raw email from stdin (called by the pipe script).
     * $envelope_recipient is the actual delivery address from Postfix (not the To: header).
     * Returns exit code: 0 = success, 67 = unknown user (reject), 75 = temp failure
     */
    public function processEmail($raw_email, $envelope_recipient);

    /**
     * Parse raw email into structured data.
     * Returns array with: from, to, subject, headers (array), body_plain, body_html, raw
     * Uses PHP mailparse extension if available, falls back to manual header parsing.
     */
    public function parseEmail($raw_email);

    /**
     * Look up alias for the given recipient address.
     * Returns EmailForwardingAlias object or null.
     */
    public function lookupAlias($recipient_email);

    /**
     * Forward the parsed email to all destinations for the matched alias.
     * Preserves original From, Subject, and body.
     * Adds X-Forwarded-For and X-Original-To headers.
     * Returns array of ['destination' => bool success]
     */
    public function forwardEmail($parsed_email, $alias);

    /**
     * Log a forwarding transaction.
     */
    public function logTransaction($parsed_email, $alias, $status, $error = null);
}
```

### Email Parsing Strategy

Manual parsing with no extension dependencies. Raw email is headers + blank line + body:

```php
// Split headers from body
list($header_block, $body) = explode("\r\n\r\n", $raw_email, 2);

// Parse headers, handling continuation lines (lines starting with whitespace)
$headers = [];
$current_header = null;
foreach (explode("\r\n", $header_block) as $line) {
    if (preg_match('/^\s+/', $line) && $current_header) {
        $headers[$current_header] .= ' ' . trim($line); // Continuation
    } elseif (preg_match('/^([^:]+):\s*(.*)$/', $line, $m)) {
        $current_header = strtolower($m[1]);
        $headers[$current_header] = trim($m[2]);
    }
}

// Extract From, To, Subject
$from = $headers['from'] ?? '';
$to = $headers['to'] ?? '';
$subject = $headers['subject'] ?? '';
```

For MIME multipart messages (HTML + plain text + attachments), parse the `Content-Type` boundary and split body parts. This is straightforward string manipulation — no extensions needed.

### Forwarding Behavior

When forwarding, the system should:
1. **Preserve the original From header** — so the recipient sees who actually sent the email
2. **Set envelope sender (Return-Path)** to a Joinery SRS address (see SRS section below)
3. **Add headers:**
   - `X-Original-To: info@example.com` (the alias address)
   - `X-Forwarded-For: info@example.com`
   - `X-Forwarded-By: Joinery Email Forwarder`
4. **Preserve original Subject, Date, Message-ID, Reply-To**
5. **Forward both HTML and plain text parts** as they are
6. **Preserve attachments** — forward the raw MIME message when possible

### Sending Strategy

The forwarder uses SmtpMailer directly — not EmailSender — to avoid template wrapping and Mailgun header modifications. SmtpMailer is PHPMailer under the hood.

SMTP settings fall back to the site defaults but can be overridden with `email_forwarding_smtp_*` settings, allowing a separate sending server for forwarding (keeps forwarding reputation isolated from transactional email).

**Raw forwarding via SMTP:** Modify the raw email headers (add X-Forwarded-For, X-Original-To, update Return-Path for SRS) and inject the raw message directly into SmtpMailer/PHPMailer. This preserves the exact MIME structure, attachments, and formatting. PHPMailer supports sending pre-built messages via `preSend()` bypass or by setting the raw body directly.

---

## Postfix Pipe Script (`/scripts/email_forwarder.php`)

Thin wrapper that bootstraps Joinery and calls EmailForwarder:

```php
#!/usr/bin/php
<?php
/**
 * Postfix pipe script for email forwarding.
 * Receives raw email on stdin, envelope recipient as $argv[1].
 *
 * Exit codes (per Postfix pipe conventions):
 *   0  = success
 *   67 = unknown user (permanent rejection)
 *   75 = temporary failure (Postfix will retry)
 */

// Bootstrap Joinery (outside normal web request)
require_once('/var/www/html/joinerytest/public_html/includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/EmailForwarder.php'));

// Check master switch
$settings = Globalvars::get_instance();
if (!$settings->get_setting('email_forwarding_enabled')) {
    exit(0); // Accept silently when disabled
}

// Envelope recipient from Postfix (NOT the To: header — they can differ for BCC, lists, etc.)
$envelope_recipient = isset($argv[1]) ? $argv[1] : null;
if (empty($envelope_recipient)) {
    exit(67); // No recipient — reject
}

// Read raw email from stdin
$raw_email = file_get_contents('php://stdin');
if (empty($raw_email)) {
    exit(75); // Temp failure — retry
}

// Process — wrapped in try/catch so PHP errors return temp failure instead of crashing
try {
    $forwarder = new EmailForwarder();
    $exit_code = $forwarder->processEmail($raw_email, $envelope_recipient);
    exit($exit_code);
} catch (Exception $e) {
    error_log('EmailForwarder fatal: ' . $e->getMessage());
    exit(75); // Temp failure — Postfix will retry
}
```

---

## Server Configuration

### Install Script Changes

**`install.sh`** — Add after the Certbot install section (~line 1129):

```bash
# Install Postfix (email forwarding MTA)
print_step "Installing Postfix..."
debconf-set-selections <<< "postfix postfix/mailname string $(hostname -f)"
debconf-set-selections <<< "postfix postfix/main_mailer_type string 'Internet Site'"
apt install -y postfix

# Install opendkim (DKIM signing for forwarded mail)
print_step "Installing opendkim..."
apt install -y opendkim opendkim-tools

# Configure Postfix milter for opendkim
postconf -e "milter_default_action = accept"
postconf -e "smtpd_milters = inet:localhost:8891"
postconf -e "non_smtpd_milters = inet:localhost:8891"

# RBL spam filtering
postconf -e "smtpd_recipient_restrictions = permit_mynetworks, reject_unauth_destination, reject_rbl_client zen.spamhaus.org, reject_rbl_client bl.spamcop.net, reject_rbl_client b.barracudacentral.org, reject_rhsbl_helo dbl.spamhaus.org, reject_rhsbl_sender dbl.spamhaus.org, permit"

print_success "Postfix and opendkim installed"
```

The `debconf-set-selections` lines prevent Postfix from launching its interactive configuration dialog during install.

This runs inside both bare-metal and Docker container contexts (since `install.sh server` is called during Docker build). The Postfix pipe transport and virtual_mailbox_domains are site-specific and configured separately per the Postfix Setup sections below.

**Host Postfix for Docker deployments** — On the Docker host itself (not inside containers), Postfix must also be installed to act as the front-door SMTP router. This is a manual one-time setup on the Docker host:

```bash
# On the Docker host (e.g., docker-prod)
apt install -y postfix opendkim opendkim-tools
# Configure as described in "Deployment Architecture" section
```

**`Dockerfile.template`** — Two changes:

1. Expose the SMTP port (container listens on 25 internally):
```dockerfile
EXPOSE 25 80 5432
```

2. Start Postfix and opendkim in the CMD chain (before `apache2ctl`):
```bash
service postfix start && \
service opendkim start && \
apache2ctl -D FOREGROUND
```

**`docker run` in `install.sh`** — Add SMTP port mapping with a unique port per container. Each container maps its internal port 25 to a unique high port on the host (e.g., 2525, 2526):

```bash
-p "$PORT":80 \
-p "$DB_PORT":5432 \
-p "$SMTP_PORT":25 \
```

The `install.sh site` command should auto-assign `SMTP_PORT` the same way it assigns `PORT` and `DB_PORT` — find the next available port starting from 2525.

**UFW firewall** — Add port 25 for bare-metal, and the SMTP port range for Docker hosts:

```bash
ufw allow 25        # SMTP for bare-metal
ufw allow 2525:2550/tcp  # SMTP relay ports for Docker containers
```

### Deployment Architecture

**Bare-metal (single site):** Postfix runs directly on the server, accepts mail on port 25, pipes to PHP.

**Docker (multi-container):** Two layers of Postfix — one on the host as a front-door router, one inside each container doing the actual forwarding.

```
Inbound SMTP (port 25)
  → Host Postfix (accepts mail, routes by domain)
    → transport_maps: example.com → smtp:[localhost]:2525
    → transport_maps: other.com   → smtp:[localhost]:2526
      → Container Postfix (receives relay, pipes to PHP forwarder)
        → PHP processes alias lookup, DKIM verify, forward
```

**Host Postfix configuration** (`/etc/postfix/main.cf` on the Docker host):

```
# Accept mail for all forwarding domains across all containers
relay_domains = example.com, other.com
transport_maps = hash:/etc/postfix/transport

# RBL spam filtering at the front door
smtpd_recipient_restrictions =
    permit_mynetworks,
    reject_unauth_destination,
    reject_rbl_client zen.spamhaus.org,
    reject_rbl_client bl.spamcop.net,
    reject_rbl_client b.barracudacentral.org,
    reject_rhsbl_helo dbl.spamhaus.org,
    reject_rhsbl_sender dbl.spamhaus.org,
    permit
```

**Host transport map** (`/etc/postfix/transport`):

```
# Route domains to the correct container's SMTP port
example.com    smtp:[127.0.0.1]:2525
other.com      smtp:[127.0.0.1]:2526
```

After editing: `postmap /etc/postfix/transport && postfix reload`

**Container Postfix configuration** — each container's Postfix accepts relayed mail from the host and pipes to PHP:

```
# /etc/postfix/main.cf (inside container)
# Accept mail relayed from host Postfix
mynetworks = 127.0.0.0/8 172.16.0.0/12 10.0.0.0/8
virtual_transport = joinery
virtual_mailbox_domains = example.com
inet_interfaces = all
```

```
# /etc/postfix/master.cf (inside container)
joinery   unix  -       n       n       -       5       pipe
  flags=DRhu user=www-data
  argv=/usr/bin/php /var/www/html/${SITENAME}/public_html/scripts/email_forwarder.php ${recipient}
```

The `mynetworks` includes Docker bridge ranges so the container accepts relayed mail from the host.

**RBL checks happen on the host only** — the container trusts relayed mail from the host since it's already been filtered. This avoids double-checking and also avoids RBL lookups on localhost relay connections.

### Bare-Metal Postfix Setup

For bare-metal (non-Docker) deployments, Postfix is simpler — single instance, direct pipe:

```
# /etc/postfix/main.cf
virtual_transport = joinery
virtual_mailbox_domains = example.com, anotherdomain.com

# RBL spam filtering
smtpd_recipient_restrictions =
    permit_mynetworks,
    reject_unauth_destination,
    reject_rbl_client zen.spamhaus.org,
    reject_rbl_client bl.spamcop.net,
    reject_rbl_client b.barracudacentral.org,
    reject_rhsbl_helo dbl.spamhaus.org,
    reject_rhsbl_sender dbl.spamhaus.org,
    permit
```

```
# /etc/postfix/master.cf
joinery   unix  -       n       n       -       5       pipe
  flags=DRhu user=www-data
  argv=/usr/bin/php /var/www/html/joinerytest/public_html/scripts/email_forwarder.php ${recipient}
```

The `5` limits concurrent forwarder processes. The `flags=DRhu` tell Postfix to pass envelope recipient/sender info and fold long headers.

### DNS Requirements (per forwarding domain)

```
; MX records — point to the Joinery server
@   MX  10  mail.joineryserver.com.

; SPF — authorize the server to send on behalf of this domain
@   TXT "v=spf1 ip4:SERVER_IP -all"

; DKIM — public key for outbound signing (selector "mail", adjust as needed)
mail._domainkey   TXT   "v=DKIM1; k=rsa; p=PUBLIC_KEY_HERE"
```

### DKIM Signing (outbound)

Signs all forwarded messages with the forwarding domain's DKIM key. This is critical for deliverability — the original sender's DKIM signature often breaks during forwarding (any header modification invalidates it), so the destination server needs a valid signature from *your* domain instead.

**Server setup (one-time):**

```bash
# Install
apt install opendkim opendkim-tools

# Generate key pair per domain
mkdir -p /etc/opendkim/keys/example.com
opendkim-genkey -s mail -d example.com -D /etc/opendkim/keys/example.com
chown opendkim:opendkim /etc/opendkim/keys/example.com/mail.private
```

This creates:
- `/etc/opendkim/keys/example.com/mail.private` — private key (stays on server)
- `/etc/opendkim/keys/example.com/mail.txt` — public key formatted as a DNS TXT record

**opendkim configuration:**

```
# /etc/opendkim.conf
Syslog          yes
Domain          example.com
Selector        mail
KeyTable        /etc/opendkim/key.table
SigningTable    refile:/etc/opendkim/signing.table
InternalHosts   /etc/opendkim/trusted.hosts

# /etc/opendkim/key.table
mail._domainkey.example.com    example.com:mail:/etc/opendkim/keys/example.com/mail.private

# /etc/opendkim/signing.table
*@example.com    mail._domainkey.example.com

# /etc/opendkim/trusted.hosts
127.0.0.1
localhost
```

For multiple domains, add one line per domain to `key.table` and `signing.table`.

**Postfix integration** (add to `/etc/postfix/main.cf`):

```
milter_default_action = accept
smtpd_milters = inet:localhost:8891
non_smtpd_milters = inet:localhost:8891
```

After this, all outbound mail is automatically DKIM-signed. PHP is not involved.

**DNS:** Publish the public key from `mail.txt` as a TXT record at `mail._domainkey.example.com`.

### DNS Validation on Domain Admin Page

The domain management page performs live DNS checks for each domain and displays status indicators:

```php
// Check MX record
$mx_records = dns_get_record($domain, DNS_MX);
$mx_ok = false;
foreach ($mx_records as $mx) {
    if (strpos($mx['target'], 'expected_server') !== false) {
        $mx_ok = true;
    }
}

// Check SPF record
$txt_records = dns_get_record($domain, DNS_TXT);
$spf_ok = false;
foreach ($txt_records as $txt) {
    if (strpos($txt['txt'], 'v=spf1') !== false && strpos($txt['txt'], $server_ip) !== false) {
        $spf_ok = true;
    }
}

// Check DKIM record
$dkim_records = dns_get_record('mail._domainkey.' . $domain, DNS_TXT);
$dkim_ok = false;
foreach ($dkim_records as $txt) {
    if (strpos($txt['txt'], 'v=DKIM1') !== false) {
        $dkim_ok = true;
    }
}
```

**Server checks (global, not per-domain):**

```php
// Postfix installed?
exec('which postfix 2>/dev/null', $output, $exit_code);
$postfix_installed = ($exit_code === 0);

// Postfix running? (Postfix master process)
exec('pgrep -x master 2>/dev/null', $output, $exit_code);
$postfix_running = ($exit_code === 0);

// Joinery pipe transport configured?
exec('postconf -M joinery/unix 2>/dev/null', $output);
$transport_configured = !empty($output);

// opendkim installed?
exec('which opendkim 2>/dev/null', $output, $exit_code);
$opendkim_installed = ($exit_code === 0);

// opendkim running?
exec('pgrep -x opendkim 2>/dev/null', $output, $exit_code);
$opendkim_running = ($exit_code === 0);
```

**Per-domain checks:**

```php
// Domain in this container's virtual_mailbox_domains?
// (On Docker, this checks the container's Postfix — the host transport map
// must also be configured, but we can't check that from inside the container)
exec('postconf virtual_mailbox_domains 2>/dev/null', $output);
$domains_line = implode('', $output);
$domain_in_postfix = (strpos($domains_line, $domain) !== false);

// MX, SPF, DKIM DNS checks (as above)
```

**Display — server status panel:**
- **Postfix** — green "Installed and running" / warning "Not installed" / caution "Installed but not running"
- **Joinery transport** — green "Configured" / warning "Pipe transport not found in Postfix config"
- **opendkim** — green "Installed and running" / caution "Not installed — outbound DKIM signing disabled"

**Display — per domain:**
- **Postfix domain** — green check or warning "Domain not in Postfix virtual_mailbox_domains — mail will not be accepted"
- **MX** — green check or warning "MX records not pointing to this server"
- **SPF** — green check or warning "SPF record missing or doesn't include server IP"
- **DKIM** — green check or caution "No DKIM record found at mail._domainkey.example.com — outbound signing may not be verified by recipients"

### Postfix Domain Configuration Instructions

When a domain is added in the admin UI, the domain edit/view page displays manual setup instructions. These differ by deployment type:

**Bare-metal instructions:**
```
1. Add domain to Postfix:
   sudo postconf -e "virtual_mailbox_domains = example.com otherexisting.com"
   sudo postfix reload

2. Add DNS records for example.com:
   MX  10  mail.yourserver.com.
   TXT "v=spf1 ip4:SERVER_IP -all"
   mail._domainkey TXT "v=DKIM1; k=rsa; p=PUBLIC_KEY"
```

**Docker multi-container instructions:**
```
1. Inside the container — add domain to container's Postfix:
   docker exec CONTAINER postconf -e "virtual_mailbox_domains = example.com"
   docker exec CONTAINER postfix reload

2. On the Docker host — add domain to host transport map:
   echo "example.com    smtp:[127.0.0.1]:CONTAINER_SMTP_PORT" >> /etc/postfix/transport
   sudo postmap /etc/postfix/transport
   sudo postfix reload

3. Add DNS records for example.com:
   MX  10  mail.yourserver.com.
   TXT "v=spf1 ip4:SERVER_IP -all"
   mail._domainkey TXT "v=DKIM1; k=rsa; p=PUBLIC_KEY"
```

The page should auto-detect whether it's running in Docker (check for `/.dockerenv`) and show the appropriate instructions, pre-filled with the actual server IP and domain name.

---

## Admin Pages

### Admin Menu Registration

The admin menu is database-driven (`amu_admin_menus` table). The "Emails" parent menu already exists (ID 11). Current children and their sort order:

| order | menu item |
|---|---|
| 1 | Emails list |
| 3 | Contact Types |
| 5 | Email Templates |
| 7 | Mailing Lists |

The admin menu system only supports two levels (parent → child), so three-level nesting isn't possible without modifying the menu renderer. Instead, add a single "Incoming" menu item under Emails that links to the aliases page. The aliases page provides tab navigation to domains and logs.

```sql
INSERT INTO amu_admin_menus (amu_menudisplay, amu_defaultpage, amu_parent_menu_id, amu_order, amu_min_permission, amu_slug, amu_setting_activate)
VALUES ('Incoming', 'admin_email_forwarding', 11, 10, 5, 'incoming', 'email_forwarding_enabled');
```

```
Emails
├── Emails list
├── Contact Types
├── Email Templates
├── Mailing Lists
└── Incoming          ← single menu entry, links to aliases page
```

All three forwarding admin pages (`admin_email_forwarding`, `admin_email_forwarding_domains`, `admin_email_forwarding_logs`) share the `incoming` slug so the menu item stays highlighted. Each page renders a tab bar at the top for navigation between them:

```
[Forwarding Aliases]  [Domains]  [Logs]
```

### Admin Logic File Bootstrap

All admin logic files must bootstrap PathHelper because they are included by view files, not served through the front controller:

```php
<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
// ... rest of logic
```

### Email Forwarding Aliases List (`/admin/admin_email_forwarding`)

**Table columns:**
- Alias (e.g., `info@example.com`)
- Destinations (comma-separated list)
- Description
- Enabled (yes/no toggle)
- Forward count
- Last forwarded
- Actions (edit, delete)

**Filters:** Domain dropdown, enabled/disabled, search by alias name.

**Actions:** Add new alias button, bulk enable/disable.

### Edit Alias (`/admin/admin_email_forwarding_alias`)

**Form fields:**
- Domain (dropdown of enabled EmailForwardingDomains)
- Alias (text input — the local part)
- Destinations (textarea — one per line or comma-separated)
- Description (text input)
- Enabled (checkbox)

**Validation:**
- Alias must be alphanumeric + dots/hyphens/underscores
- Alias + domain combination must be unique
- Each destination must be a valid email address
- At least one destination required

### Domain Management (`/admin/admin_email_forwarding_domains`)

**Table columns:**
- Domain name
- Enabled
- Catch-all address (if set)
- Reject unmatched (yes/no)
- Alias count
- Actions (edit, delete)

**Edit form:**
- Domain name (text — validated as proper domain)
- Enabled (checkbox)
- Catch-all address (text — optional, valid email)
- Reject unmatched (checkbox)

**DNS instructions panel:** After adding a domain, display the required MX and SPF records for that domain, plus Postfix config instructions.

### Forwarding Logs (`/admin/admin_email_forwarding_logs`)

**Table columns:**
- Timestamp
- From
- To (alias address)
- Subject
- Destinations
- Status (forwarded/rejected/discarded/error)
- Error message (if any)

**Filters:** Date range, status, domain, alias.

Read-only — no edit actions. Soft-deletable for cleanup.

---

## Settings

New settings to add via migration:

| Setting Name | Default | Description |
|---|---|---|
| `email_forwarding_enabled` | `0` | Master switch for the forwarding system |
| `email_forwarding_log_retention_days` | `30` | Days to keep forwarding logs |
| `email_forwarding_max_destinations` | `10` | Max forwarding destinations per alias |
| `email_forwarding_rate_limit_per_alias` | `50` | Max forwards per alias per rate limit window |
| `email_forwarding_rate_limit_per_domain` | `200` | Max forwards per domain per rate limit window |
| `email_forwarding_rate_limit_window` | `3600` | Rate limit window in seconds (default 1 hour) |
| `email_forwarding_srs_enabled` | `0` | Enable SRS rewriting (see SRS section) |
| `email_forwarding_srs_secret` | (empty) | Secret key for SRS hash generation. Required to enable SRS — settings page validates this is non-empty before allowing `srs_enabled` to be set to 1. |
| `email_forwarding_smtp_host` | (empty) | SMTP host for forwarding (falls back to `smtp_host`) |
| `email_forwarding_smtp_port` | (empty) | SMTP port for forwarding (falls back to `smtp_port`) |
| `email_forwarding_smtp_username` | (empty) | SMTP username for forwarding (falls back to `smtp_username`) |
| `email_forwarding_smtp_password` | (empty) | SMTP password for forwarding (falls back to `smtp_password`) |

---

## SRS (Sender Rewriting Scheme)

### The Problem

When forwarding email, SPF breaks. Here's why:

1. Alice (`alice@gmail.com`) sends to `info@example.com`
2. Joinery forwards it to `bob@yahoo.com`
3. Yahoo checks SPF for `alice@gmail.com` — expects it to come from Gmail's servers
4. But it came from Joinery's server — **SPF fails**
5. Yahoo may reject or spam-folder the message

### The Solution

SRS rewrites the **envelope sender** (Return-Path) so the forwarding server takes responsibility:

- Original envelope sender: `alice@gmail.com`
- SRS-rewritten: `SRS0=HHH=TT=gmail.com=alice@example.com`

Where `HHH` is a hash (for anti-forgery), `TT` is a timestamp (for expiry), and the original address is encoded.

When a bounce comes back to the SRS address, the system decodes it and sends the bounce to the original sender.

### Implementation

```php
class SRSRewriter {
    /**
     * Rewrite a sender address for forwarding.
     * Input:  alice@gmail.com
     * Output: SRS0=HASH=TT=gmail.com=alice@example.com
     */
    public function rewrite($sender_address, $forwarding_domain);

    /**
     * Decode an SRS address back to the original sender.
     * Used for bounce processing.
     */
    public function decode($srs_address);

    /**
     * Validate an SRS address (check hash and timestamp).
     */
    public function validate($srs_address);
}
```

The hash uses HMAC-SHA256 with the `email_forwarding_srs_secret` setting, truncated to a few characters. The timestamp is days since epoch, and addresses expire after ~21 days.

### SRS Bounce Handling

When SRS rewrites the envelope sender, your server becomes responsible for bounces. Here's the full lifecycle:

```
1. Alice (alice@gmail.com) sends to info@example.com
2. Joinery forwards to bob@yahoo.com
   - Envelope sender rewritten: SRS0=HsX3=5A=gmail.com=alice@example.com
3. Bob's mailbox is full — Yahoo generates a bounce
4. Bounce goes to: SRS0=HsX3=5A=gmail.com=alice@example.com
   (which is YOUR domain — example.com)
5. Postfix receives the bounce at that SRS address
6. ??? — something needs to decode it and notify Alice
```

**Without bounce handling:** Step 6 goes nowhere. Alice never learns her email failed. The bounce piles up or gets discarded. This is functional but not ideal — the original sender has no idea their message didn't arrive.

**With bounce handling (PHP approach):** The forwarder script also handles SRS-addressed mail:

```php
// In EmailForwarder::processEmail()
$recipient = $parsed_email['to'];

// Check if this is a bounce to an SRS address
if (SRSRewriter::isSRSAddress($recipient)) {
    $srs = new SRSRewriter();
    if ($srs->validate($recipient)) {
        $original_sender = $srs->decode($recipient);
        // Forward the bounce notification to the original sender
        // so they know their email to bob@yahoo.com failed
        $this->forwardBounce($parsed_email, $original_sender);
        return 0;
    }
    return 0; // Invalid SRS address — discard silently
}

// Normal alias forwarding continues...
```

Postfix needs to accept mail for SRS addresses too. Since SRS addresses use the forwarding domain (e.g., `SRS0=...@example.com`), they're already covered by `virtual_mailbox_domains`. The pipe script receives them, detects the SRS prefix, and routes accordingly.

Since all mail for the domain already pipes through the PHP forwarder, SRS bounce handling is just a check at the top of `processEmail()` — the SRS address is on the domain we're already accepting mail for, so Postfix delivers it to the same script. No additional Postfix configuration needed for bounces.

### SRS in EmailForwarder Flow

```php
public function processEmail($raw_email, $envelope_recipient) {
    $parsed = $this->parseEmail($raw_email);

    // 1. Check if this is an SRS bounce (using envelope recipient, not To: header)
    if (SRSRewriter::isSRSAddress($envelope_recipient)) {
        return $this->handleSRSBounce($parsed, $envelope_recipient);
    }

    // 2. Normal alias lookup using envelope recipient
    $alias = $this->lookupAlias($envelope_recipient);
    // ... (rate limit, DKIM verify, forward, log)
}

private function handleSRSBounce($parsed) {
    $srs = new SRSRewriter();
    if (!$srs->validate($parsed['to'])) {
        return 0; // Invalid/expired — discard
    }
    $original_sender = $srs->decode($parsed['to']);
    // Forward the bounce to the original sender
    EmailSender::quickSend($original_sender, $parsed['subject'], $parsed['body']);
    $this->logTransaction($parsed, null, 'bounce_forwarded');
    return 0;
}
```

---

## Spam Filtering

### The Risk

Forwarding amplifies spam. If spammers send to your aliases, you forward that spam onward, and the destination mail server sees **your server** as the spam source. This can get your IP blacklisted.

### Rate Limiting (Phase 1 — via forwarding log table)

Rate limiting uses the `efl_email_forwarding_logs` table directly — every forward is already logged there, so we just count recent rows. No need for a separate rate limiting system.

**Per-alias rate limit** — prevents any single alias from being flooded:

```php
private function checkAliasRateLimit($alias_id) {
    $db = DbConnector::get_instance()->get_db_link();
    $window = Globalvars::get_instance()->get_setting('email_forwarding_rate_limit_window') ?: 3600;
    $max = Globalvars::get_instance()->get_setting('email_forwarding_rate_limit_per_alias') ?: 50;

    $sql = "SELECT COUNT(*) as cnt FROM efl_email_forwarding_logs
            WHERE efl_efa_email_forwarding_alias_id = ?
            AND efl_status = 'forwarded'
            AND efl_create_time > NOW() - INTERVAL '" . intval($window) . " seconds'";
    $stmt = $db->prepare($sql);
    $stmt->execute([$alias_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return ($row['cnt'] < $max);
}
```

**Per-domain rate limit** — prevents the entire domain from being overwhelmed:

```php
private function checkDomainRateLimit($domain_id) {
    $db = DbConnector::get_instance()->get_db_link();
    $window = Globalvars::get_instance()->get_setting('email_forwarding_rate_limit_window') ?: 3600;
    $max = Globalvars::get_instance()->get_setting('email_forwarding_rate_limit_per_domain') ?: 200;

    $sql = "SELECT COUNT(*) as cnt FROM efl_email_forwarding_logs efl
            JOIN efa_email_forwarding_aliases efa ON efa.efa_email_forwarding_alias_id = efl.efl_efa_email_forwarding_alias_id
            WHERE efa.efa_efd_email_forwarding_domain_id = ?
            AND efl.efl_status = 'forwarded'
            AND efl.efl_create_time > NOW() - INTERVAL '" . intval($window) . " seconds'";
    $stmt = $db->prepare($sql);
    $stmt->execute([$domain_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return ($row['cnt'] < $max);
}
```

**Usage in processEmail():**

```php
if (!$this->checkAliasRateLimit($alias->key)) {
    $this->logTransaction($parsed_email, $alias, 'rate_limited');
    return 0; // Accept silently — don't tell the sender to retry
}

if (!$this->checkDomainRateLimit($domain->key)) {
    $this->logTransaction($parsed_email, $alias, 'rate_limited');
    return 0;
}
```

Rate-limited emails are silently accepted (exit code 0) rather than rejected. This prevents Postfix from retrying them endlessly while also not revealing to spammers that the limit was hit.

### Postfix RBL Checks (Phase 1)

RBL (Real-time Blackhole List) checks happen at the Postfix level *before* mail reaches PHP. This blocks known spam sources with zero PHP overhead.

Add to `/etc/postfix/main.cf`:

```
smtpd_recipient_restrictions =
    permit_mynetworks,
    reject_unauth_destination,
    reject_rbl_client zen.spamhaus.org,
    reject_rbl_client bl.spamcop.net,
    reject_rbl_client b.barracudacentral.org,
    reject_rhsbl_helo dbl.spamhaus.org,
    reject_rhsbl_sender dbl.spamhaus.org,
    permit
```

What each does:
- **`zen.spamhaus.org`** — Combined blocklist (SBL + XBL + PBL). The most widely used RBL. Free for low-volume non-commercial use.
- **`bl.spamcop.net`** — Community-reported spam sources. Good for catching recent spam campaigns.
- **`b.barracudacentral.org`** — Barracuda reputation database. Free with registration.
- **`dbl.spamhaus.org`** (RHSBL) — Checks the sender's domain name (not just IP) against Spamhaus domain blocklist.

These are DNS-based lookups — very fast, no software to install. Postfix rejects matching connections with a 5xx error before the message body is even transmitted.

### Basic Header Checks (Phase 1)

In EmailForwarder, reject obviously bad messages before forwarding:

```php
// In EmailForwarder::processEmail()
if (empty($parsed_email['from'])) {
    $this->logTransaction($parsed_email, $alias, 'rejected', 'Missing From header');
    return 0;
}

if (strlen($raw_email) > 25 * 1024 * 1024) { // 25MB
    $this->logTransaction($parsed_email, $alias, 'rejected', 'Message too large');
    return 0;
}
```

### DKIM Verification (Phase 1)

Verify the DKIM signature on inbound email before forwarding. If the signature is invalid, the message was likely tampered with or spoofed — don't forward it.

DKIM verification involves:
1. Extract the `DKIM-Signature` header from the email
2. Look up the sender's public key via DNS TXT record (the `d=` and `s=` fields in the header tell you the domain and selector)
3. Verify the cryptographic signature against the message headers and body

**PHP implementation** using built-in functions only (`dns_get_record()` for the public key lookup, `openssl_verify()` for the signature check — both standard PHP, no extensions):

```php
// In EmailForwarder::processEmail(), before forwarding:
$dkim_result = $this->verifyDKIM($raw_email);

// $dkim_result is one of: 'pass', 'fail', 'none' (no signature present)
if ($dkim_result === 'fail') {
    $this->logTransaction($parsed_email, $alias, 'rejected', 'DKIM verification failed');
    return 0;
}

// 'none' is acceptable — not all senders use DKIM
// 'pass' — signature valid, proceed with forwarding
```

The verification function:

```php
private function verifyDKIM($raw_email) {
    // 1. Extract DKIM-Signature header
    //    Contains: v=, a= (algorithm), d= (domain), s= (selector),
    //    h= (signed headers), bh= (body hash), b= (signature)

    // 2. DNS lookup for public key
    //    Query: {selector}._domainkey.{domain} TXT record
    //    e.g.: google._domainkey.gmail.com

    // 3. Compute body hash, compare to bh= value
    // 4. Canonicalize signed headers per c= field (relaxed/simple)
    // 5. Verify RSA/Ed25519 signature using public key

    // Returns 'pass', 'fail', or 'none'
}
```

This is ~60-80 lines for a basic implementation covering RSA signatures with relaxed canonicalization (covers the vast majority of DKIM-signed email). The DNS lookup is a single `dns_get_record()` call.

**Behavior:**
- **DKIM pass** — forward normally
- **DKIM fail** — reject (don't forward spoofed/tampered mail)
- **DKIM none** (no signature) — forward normally (many legitimate senders don't use DKIM)
- **DKIM error** (DNS timeout, unsupported algorithm) — forward normally (fail open, log the issue)

### Later Phase Mitigations

**Phase 2:**
- **SpamAssassin or rspamd** — Score inbound email before forwarding. Discard or tag high-scoring messages.
- **Sender blocklist** — Admin-managed list of blocked sender addresses or domains.
- **SPF checking** on inbound mail (reject mail that fails the sender's own SPF)
- **IP reputation monitoring** — Track delivery success rates, alert on blacklist appearances

### Server IP Reputation

For the forwarding server to work well:
- **rDNS/PTR record** must be set for the server's IP (reverse DNS matching the mail hostname)
- IP should **not be on any blacklists** — check at mxtoolbox.com
- **Sending volume** should ramp gradually (IP warming)

---

## Implementation Phases

### Phase 1 — Core Forwarding
- [ ] Data models (domain, alias, log)
- [ ] EmailForwarder class with basic parsing (manual header parsing, no mailparse dependency)
- [ ] SRSRewriter class (encode, decode, validate — ~40 lines)
- [ ] SRS bounce handling in EmailForwarder (detect SRS recipient, decode, forward bounce)
- [ ] DKIM verification on inbound (verify signature, reject failures, pass unsigned)
- [ ] Postfix pipe script
- [ ] Raw forwarding via SmtpMailer (preserves MIME, attachments, no template wrapping)
- [ ] Admin pages (domains, aliases, logs)
- [ ] Settings migration
- [ ] Rate limiting via forwarding log table (per-alias and per-domain)
- [ ] Basic header checks (missing From, oversized messages)
- [ ] Postfix RBL configuration (zen.spamhaus.org, bl.spamcop.net, b.barracudacentral.org)
- [ ] Postfix configuration documentation
- [ ] `/docs/email_forwarding.md` — setup guide, admin usage, server config, DNS, troubleshooting, local testing
- [ ] Update `/docs/email_system.md` with note and link to forwarding doc
- [ ] Update `CLAUDE.md` documentation index
- [ ] Scheduled task for forwarding log cleanup (uses `email_forwarding_log_retention_days`)

### Future Considerations
- Spam scoring integration (SpamAssassin or rspamd) — content-based filtering, likely overkill at low volume
- Sender blocklist — admin-managed list of blocked sender addresses/domains
- SPF checking on inbound mail
- IP reputation monitoring
- Admin alerts for rate limit hits and forwarding errors

---

## Success Criteria

- [ ] Admin can add a forwarding domain and create aliases via the admin UI
- [ ] Email sent to an alias is forwarded to all configured destinations
- [ ] Email to a non-existent alias is rejected (or caught by catch-all)
- [ ] Forwarded email preserves original From, Subject, and body content
- [ ] All forwarding transactions are logged with status
- [ ] Disabled aliases/domains stop forwarding immediately
- [ ] Rate limiting prevents runaway forwarding
- [ ] SRS rewrites envelope sender so forwarded mail passes SPF at destination
- [ ] SRS bounces are decoded and forwarded to the original sender

## Testing Plan

### Local testing without MX records

The forwarder can be tested by piping a raw email directly to the script, bypassing Postfix entirely:

```bash
# Basic test
echo "From: alice@gmail.com
To: info@example.com
Subject: Test forwarding

This is a test message." | php scripts/email_forwarder.php info@example.com

# Check exit code
echo $?   # 0 = forwarded, 67 = unknown alias

# Test with a saved .eml file
php scripts/email_forwarder.php info@example.com < /tmp/test_email.eml
```

This exercises the full PHP path (parsing, alias lookup, DKIM check, rate limiting, forwarding via SmtpMailer) without needing DNS or Postfix configured.

### Functional tests

- [ ] Send test email to configured alias — verify it arrives at destination
- [ ] Send to non-existent alias — verify rejection/discard per domain setting
- [ ] Send to disabled alias — verify no forwarding
- [ ] Send to alias with multiple destinations — verify all receive
- [ ] Verify forwarding logs capture all transactions
- [ ] Test rate limiting by sending rapid burst
- [ ] Verify admin CRUD operations for domains and aliases
- [ ] Verify SRS encoding produces valid addresses and decode round-trips correctly
- [ ] Verify SRS bounce to an encoded address is forwarded to original sender
- [ ] Verify expired/invalid SRS addresses are discarded
- [ ] Send email with attachment — verify it arrives (Phase 2)
