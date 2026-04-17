# Self-Hosted Email Service Spec

## Overview

Turn a Joinery instance into a personal email server. Users get `name@theirdomain.com` with a webmail interface, IMAP access for desktop/mobile clients, and outbound delivery via Mailgun (so they never have to worry about deliverability, IP reputation, or landing in spam).

**Deployment model:** One Joinery instance per VPS. This is not multi-tenant hosted email — it is self-hosted email for the person (or small organization) running that Joinery instance. Storage is local to the VPS with optional S3-compatible archiving for old mail.

**What this replaces:** Gmail, Outlook, Fastmail, etc. — for people who want to own their email on their own domain without solving deliverability themselves.

## Current State

### What already exists

**Email Forwarding Plugin (`/plugins/email_forwarding/`):**
- Postfix receives inbound mail and pipes to `email_forwarder.php` via stdin
- `EmailForwarder::parseEmail()` — full raw email parser (headers, body, From extraction)
- `EmailForwarder::verifyDKIM()` — inbound DKIM signature verification
- `SRSRewriter` — SRS envelope rewriting for SPF compatibility
- Domain management with live DNS validation (MX, SPF, DKIM records)
- Per-alias and per-domain rate limiting
- Forwarding transaction logging
- RBL spam filtering at the Postfix level (Spamhaus, SpamCop, Barracuda)
- opendkim integration for outbound DKIM signing
- Docker multi-container support (host Postfix relays to container Postfix by domain)
- Admin UI for domain and alias management

**Outbound Email Infrastructure:**
- `EmailSender` with Mailgun and SMTP providers, automatic failover
- `EmailMessage` fluent builder (from, to, cc, bcc, subject, html, text, attachments, headers)
- `SmtpMailer` (PHPMailer wrapper) for direct SMTP
- Email queue with retry (`equ_queued_emails` table)

**Inbound Webhook (test-only):**
- `iem_inbound_emails` table stores emails received via Mailgun webhook
- Not designed for production mailbox use

### What does NOT exist
- Mailbox storage (per-user email storage with folders)
- Webmail interface
- Compose/send as `user@theirdomain.com`
- IMAP/POP3 server integration
- Spam scoring (beyond Postfix RBL checks)
- Full-text email search
- Attachment storage
- Contact/address book

## Architecture

```
INBOUND:
  Internet → MX record → Postfix (port 25)
    → RBL check (Spamhaus, SpamCop, Barracuda)
    → SpamAssassin scoring (new)
    → Postfix pipe → email_receiver.php (new)
      → DKIM verification (existing)
      → Store message (storage model depends on IMAP option — see Phase 5)

OUTBOUND:
  Webmail compose → Mailgun API → recipient
  IMAP client send → SMTP submission (port 587) → Mailgun relay → recipient

IMAP ACCESS:
  Thunderbird/Apple Mail/K-9 → IMAP server (port 993, TLS)
    → auth against Joinery users table
    → reads mail (storage model depends on IMAP option — see Phase 5)
```

The IMAP server component has three implementation options evaluated in Phase 5. The choice affects the storage model for Phases 1-2 as well.

## Implementation Plan

### Phase 1: Mailbox Storage — The Email Hosting Plugin

Create a new plugin `email_hosting` that depends on `email_forwarding` (reuses its domain management, DNS validation, DKIM, and Postfix infrastructure).

**New plugin:** `/plugins/email_hosting/`

**New data models:**

`EmailMailbox` (`emb_email_mailboxes`):
```php
public static $field_specifications = array(
    'emb_usr_user_id'       => array('type'=>'int', 'foreign_key'=>'usr_users.usr_user_id'),
    'emb_efd_domain_id'     => array('type'=>'int', 'foreign_key'=>'efd_email_forwarding_domains.efd_email_forwarding_domain_id'),
    'emb_local_part'        => array('type'=>'varchar(64)', 'is_nullable'=>false),
    'emb_is_primary'        => array('type'=>'bool', 'default'=>false),
    'emb_is_enabled'        => array('type'=>'bool', 'default'=>true),
    'emb_storage_quota_mb'  => array('type'=>'int', 'default'=>5000),  // 5GB default
    'emb_storage_used_bytes'=> array('type'=>'bigint', 'default'=>0),
);
// Unique constraint on (emb_local_part, emb_efd_domain_id)
```

`EmailFolder` (`efo_email_folders`):
```php
public static $field_specifications = array(
    'efo_emb_mailbox_id'    => array('type'=>'int', 'foreign_key'=>'emb_email_mailboxes.emb_email_mailbox_id'),
    'efo_name'              => array('type'=>'varchar(255)', 'is_nullable'=>false),
    'efo_special_use'       => array('type'=>'varchar(50)'),  // inbox, sent, drafts, trash, junk, archive, NULL for custom
    'efo_sort_order'        => array('type'=>'int', 'default'=>0),
    'efo_message_count'     => array('type'=>'int', 'default'=>0),
    'efo_unread_count'      => array('type'=>'int', 'default'=>0),
);
```

`EmailStored` (`ems_email_stored`):
```php
public static $field_specifications = array(
    'ems_emb_mailbox_id'    => array('type'=>'int', 'foreign_key'=>'emb_email_mailboxes.emb_email_mailbox_id'),
    'ems_efo_folder_id'     => array('type'=>'int', 'foreign_key'=>'efo_email_folders.efo_email_folder_id'),
    'ems_message_id'        => array('type'=>'varchar(255)'),  // Message-ID header
    'ems_in_reply_to'       => array('type'=>'varchar(255)'),  // threading
    'ems_references'        => array('type'=>'text'),           // threading
    'ems_from_address'      => array('type'=>'varchar(255)', 'is_nullable'=>false),
    'ems_from_name'         => array('type'=>'varchar(255)'),
    'ems_to_addresses'      => array('type'=>'jsonb'),          // [{name, address}]
    'ems_cc_addresses'      => array('type'=>'jsonb'),
    'ems_bcc_addresses'     => array('type'=>'jsonb'),
    'ems_subject'           => array('type'=>'text'),
    'ems_body_plain'        => array('type'=>'text'),
    'ems_body_html'         => array('type'=>'text'),
    'ems_raw_headers'       => array('type'=>'text'),
    'ems_date'              => array('type'=>'timestamp(6)'),   // Date header from email
    'ems_size_bytes'        => array('type'=>'int'),
    'ems_is_read'           => array('type'=>'bool', 'default'=>false),
    'ems_is_flagged'        => array('type'=>'bool', 'default'=>false),
    'ems_is_answered'       => array('type'=>'bool', 'default'=>false),
    'ems_spam_score'        => array('type'=>'numeric(5,2)'),
    'ems_spam_status'       => array('type'=>'varchar(20)'),    // ham, spam, unknown
    'ems_dkim_result'       => array('type'=>'varchar(20)'),    // pass, fail, none
    'ems_has_attachments'   => array('type'=>'bool', 'default'=>false),
    'ems_search_vector'     => array('type'=>'tsvector'),       // Full-text search
);
// Index on (ems_emb_mailbox_id, ems_efo_folder_id, ems_date DESC)
// GIN index on ems_search_vector
// Index on ems_message_id for threading lookups
```

`EmailAttachment` (`eat_email_attachments`):
```php
public static $field_specifications = array(
    'eat_ems_email_stored_id' => array('type'=>'int', 'foreign_key'=>'ems_email_stored.ems_email_stored_id'),
    'eat_filename'            => array('type'=>'varchar(255)'),
    'eat_content_type'        => array('type'=>'varchar(127)'),
    'eat_size_bytes'          => array('type'=>'int'),
    'eat_storage_path'        => array('type'=>'varchar(500)'),  // Filesystem path
    'eat_content_id'          => array('type'=>'varchar(255)'),  // For inline images (CID)
    'eat_is_inline'           => array('type'=>'bool', 'default'=>false),
);
```

**Default folders created per mailbox:** Inbox, Sent, Drafts, Trash, Junk, Archive

**Attachment storage:** Files stored at `{site_data_dir}/email_attachments/{mailbox_id}/{year}/{message_id}/filename`. Site data dir is outside the web root. The path is stored in `eat_storage_path`. Inline images (CID references) are served through an authenticated endpoint.

**Storage model note:** The `ems_email_stored` table is always the webmail's primary data source. How it relates to the IMAP server depends on the option chosen in Phase 5:
- **Dovecot:** `ems_email_stored` is an index; Maildir is the source of truth for message content. Inbound writes to both.
- **Stalwart:** Stalwart manages its own PostgreSQL tables. `ems_email_stored` may be replaced by querying Stalwart's schema or JMAP API.
- **Custom Go:** `ems_email_stored` is the single source of truth. The Go IMAP server reads it directly. Simplest data model.

**Full-text search trigger:**
```sql
CREATE FUNCTION email_search_vector_update() RETURNS trigger AS $$
BEGIN
    NEW.ems_search_vector :=
        setweight(to_tsvector('english', COALESCE(NEW.ems_subject, '')), 'A') ||
        setweight(to_tsvector('english', COALESCE(NEW.ems_from_name, '') || ' ' || COALESCE(NEW.ems_from_address, '')), 'B') ||
        setweight(to_tsvector('english', COALESCE(NEW.ems_body_plain, '')), 'C');
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
```

This goes in a migration since it is a database trigger, not a schema change. PostgreSQL tsvector handles single-user email volumes well — years of email from one person is a small corpus. The weighted search prioritizes subject matches over sender matches over body matches.

**Storage quota enforcement:** Checked on inbound delivery and compose. When quota is exceeded, inbound mail returns Postfix exit code 75 (temp failure) so the sender's server retries. A warning is shown in the webmail UI when approaching 80% quota.

### Phase 2: Inbound Delivery

**New script: `email_receiver.php`**

Replaces `email_forwarder.php` in the Postfix pipe for domains configured for hosting (forwarding-only domains continue to use the forwarder). The receiver:

1. Reads raw email from stdin (same as forwarder)
2. Parses email (reuses `EmailForwarder::parseEmail()`)
3. Looks up mailbox by recipient address
4. Verifies DKIM (reuses `EmailForwarder::verifyDKIM()`)
5. Reads SpamAssassin headers (X-Spam-Score, X-Spam-Status) added by Postfix milter
6. Extracts MIME parts (plain text, HTML, attachments)
7. Stores message in `ems_email_stored` — Inbox folder, or Junk if spam score exceeds threshold
8. Saves attachments to filesystem, records in `eat_email_attachments`
9. Updates folder counts and mailbox storage usage
10. Returns exit code 0 (success) or 75 (temp failure for quota/errors)

**MIME parsing:** Use PHP's `mailparse` extension (`mailparse_msg_create`, `mailparse_msg_parse`, `mailparse_msg_get_structure`, `mailparse_msg_get_part_data`). This handles multipart messages, nested MIME, quoted-printable/base64 decoding, and charset conversion. If `mailparse` is unavailable, fall back to a simpler regex-based parser that handles the common cases (text/plain, text/html, single-level multipart/mixed).

**Postfix configuration change for hosted domains:**

The domain model (`EmailForwardingDomain`) gets a new field:
```php
'efd_hosting_mode' => array('type'=>'varchar(20)', 'default'=>'forwarding'),
// Values: 'forwarding' (existing behavior), 'hosting' (store in mailbox)
```

The Postfix pipe script (`email_forwarder.php`) is updated to check this field and delegate to `email_receiver.php` when mode is `hosting`. Alternatively, a new Postfix transport can be configured:

```
# /etc/postfix/master.cf
joinery-hosting   unix  -  n  n  -  5  pipe
  flags=DRhu user=www-data
  argv=/usr/bin/php /path/to/email_receiver.php ${recipient}
```

Transport selection by domain can use Postfix `transport_maps` (already used in Docker setup).

**Threading:** Messages are threaded by `In-Reply-To` and `References` headers. The webmail UI uses these to build conversation views. No separate thread table — queries join on `ems_message_id` / `ems_in_reply_to`. This is sufficient for single-user volumes.

### Phase 3: Outbound Sending via Mailgun

**Compose and send flow:**

1. User composes in webmail (or via IMAP client, see Phase 5)
2. Message is validated (recipient required, size limits)
3. Sent via `EmailSender` using Mailgun provider (existing infrastructure)
4. **From address:** `user@theirdomain.com` — the domain must be verified in Mailgun
5. Copy saved to Sent folder in `ems_email_stored`
6. If send fails, message moves to Drafts with error status

**Mailgun domain verification:** Each hosted domain must be added to the Mailgun account and DNS verified (CNAME records for DKIM, SPF include). The domain admin page already validates DNS — extend it to check Mailgun-specific records and show verification status.

**New settings:**
| Setting | Default | Description |
|---------|---------|-------------|
| `email_hosting_enabled` | `0` | Master switch for email hosting |
| `email_hosting_spam_threshold` | `5.0` | SpamAssassin score above which mail goes to Junk |
| `email_hosting_max_attachment_mb` | `25` | Max attachment size for outbound |
| `email_hosting_quota_default_mb` | `5000` | Default mailbox quota |
| `email_hosting_archive_enabled` | `0` | Enable S3 archiving for old mail |
| `email_hosting_archive_after_days` | `365` | Archive mail older than N days |
| `email_hosting_archive_s3_bucket` | (empty) | S3-compatible bucket for archives |
| `email_hosting_archive_s3_endpoint` | (empty) | S3-compatible endpoint URL |
| `email_hosting_archive_s3_key` | (empty) | S3 access key |
| `email_hosting_archive_s3_secret` | (empty) | S3 secret key |

**Reply/forward handling:** When replying, the `In-Reply-To` and `References` headers are set correctly for threading. When forwarding, attachments from the original message are re-attached. The original message body is quoted with standard `>` prefix (plain text) or `<blockquote>` (HTML).

### Phase 4: Webmail UI

A new plugin view set under `/email/` (or `/mail/`), requiring login.

**Pages:**

`/mail` — Inbox view (default)
`/mail/folder/{folder_id}` — View any folder
`/mail/message/{message_id}` — Read a message
`/mail/compose` — New message
`/mail/compose?reply_to={id}` — Reply
`/mail/compose?forward={id}` — Forward
`/mail/settings` — Mailbox settings (signature, display name, forwarding rules)

**Inbox/folder view:**
- Message list with sender, subject, date, read/unread, flagged, attachment indicator
- Folder sidebar (Inbox with unread count, Sent, Drafts, Trash, Junk, Archive, custom folders)
- Bulk actions: mark read/unread, move to folder, delete (move to Trash), permanently delete (from Trash)
- Sort by date (default), sender, subject
- Search bar — full-text search using `ems_search_vector`
- Pagination (50 messages per page)

**Message view:**
- Full headers toggle
- HTML rendering in sandboxed iframe (sanitized — strip scripts, external image loading blocked by default with "load images" button)
- Plain text fallback
- Attachment list with download links (served through authenticated endpoint)
- Reply, Reply All, Forward buttons
- Move to folder, Mark as spam/not spam

**Compose:**
- To, CC, BCC fields
- Subject
- Rich text editor (use a lightweight one — TinyMCE or similar, but check what theme framework allows)
- Attach files (stored temporarily, attached on send)
- Save draft (auto-save every 60 seconds)
- Send
- Signature insertion (configurable in settings)

**HTML email sanitization:** Use HTMLPurifier (already a common PHP library) to strip XSS vectors from received HTML email before rendering. External images are blocked by default and loaded on user request (prevents tracking pixels).

**UI framework:** Follows the theme system. Since this is a productivity app, the admin theme (Bootstrap + jQuery) would be the natural choice for the webmail interface — it is already available in admin pages and provides the data tables, modals, and form components needed. Alternatively, a dedicated standalone UI could be built if the instance is primarily an email server.

### Phase 5: IMAP Server + Spam Filtering

IMAP is what lets users connect Thunderbird, Apple Mail, K-9 Mail, FairEmail, etc. to their mailbox. There are three viable approaches, each with different tradeoffs for storage architecture, operational complexity, and development effort.

#### IMAP Option Comparison

| | Dovecot | Stalwart | Custom Go (go-imap) |
|---|---|---|---|
| **Maturity** | 20+ years, industry standard | ~3 years, active development | New code, our responsibility |
| **Mail storage** | Maildir on filesystem | PostgreSQL directly | PostgreSQL directly |
| **Dual storage problem** | Yes — Maildir + SQL index | No — single source of truth in PostgreSQL | No — single source of truth in PostgreSQL |
| **IMAP compliance** | Excellent, every edge case | Good, covers real-world clients | Core subset only (sufficient for major clients) |
| **Spam filtering** | Needs SpamAssassin (separate) | Built-in Sieve filtering | Needs SpamAssassin (separate) |
| **SMTP submission** | Postfix SASL auth via Dovecot | Built-in | Postfix SASL auth (or custom) |
| **Config complexity** | High (many conf files, milters) | Moderate (TOML config or web UI) | Low (single binary, config in Joinery settings) |
| **Processes to manage** | Dovecot + SpamAssassin + spamass-milter | Just Stalwart | Just the Go binary + SpamAssassin |
| **Development effort** | Config/integration only | Config/integration only | 2000-3000 lines Go + integration |
| **Auth against Joinery DB** | SQL passdb query, bcrypt via `{BLF-CRYPT}` | SQL auth backend, bcrypt supported | Direct PostgreSQL query, `bcrypt.CompareHashAndPassword()` |
| **Deployment** | `apt install dovecot-imapd` | Single binary download | Built and deployed like scrolldaddy-dns agent |

#### Option A: Dovecot (Battle-Tested, Filesystem Storage)

The traditional approach. Dovecot is the most widely deployed IMAP server. Every edge case in IMAP has been encountered and handled.

**Storage model:** Maildir on filesystem. The SQL tables from Phase 1 serve as a queryable index for the webmail UI, but Maildir is the source of truth for message content. Dovecot reads from Maildir natively.

```
Maildir structure:
{data_dir}/maildir/{mailbox_id}/
  cur/     — read messages
  new/     — unread messages
  tmp/     — delivery in progress
  .Sent/cur/
  .Drafts/cur/
  .Trash/cur/
  .Junk/cur/
```

**Inbound delivery** writes to both Maildir (for Dovecot) and SQL (for webmail). This is the dual-storage tradeoff — two representations of the same data that must stay in sync.

**Auth configuration (`/etc/dovecot/dovecot-sql.conf.ext`):**
```sql
password_query = SELECT emb.emb_local_part || '@' || efd.efd_domain AS user,
    '{BLF-CRYPT}' || u.usr_password AS password
    FROM emb_email_mailboxes emb
    JOIN usr_users u ON u.usr_user_id = emb.emb_usr_user_id
    JOIN efd_email_forwarding_domains efd
        ON efd.efd_email_forwarding_domain_id = emb.emb_efd_domain_id
    WHERE emb.emb_local_part || '@' || efd.efd_domain = '%u'
    AND emb.emb_is_enabled = true
    AND u.usr_delete_time IS NULL
```

Joinery uses PHP's `password_hash()` (bcrypt, `$2y$...`). Dovecot's `{BLF-CRYPT}` scheme accepts this format directly.

**Dovecot configuration highlights:**
```
# /etc/dovecot/conf.d/10-mail.conf
mail_location = maildir:{data_dir}/maildir/%u

# /etc/dovecot/conf.d/10-auth.conf
auth_mechanisms = plain login
passdb { driver = sql; args = /etc/dovecot/dovecot-sql.conf.ext }
userdb { driver = static; args = uid=www-data gid=www-data home={data_dir}/maildir/%u }

# /etc/dovecot/conf.d/10-ssl.conf
ssl = required
ssl_cert = </etc/letsencrypt/live/domain/fullchain.pem
ssl_key = </etc/letsencrypt/live/domain/privkey.pem
```

**SMTP submission:** Postfix handles submission (port 587) with SASL auth delegated to Dovecot, outbound relayed through Mailgun:
```
# /etc/postfix/master.cf
submission inet n - n - - smtpd
  -o smtpd_tls_security_level=encrypt
  -o smtpd_sasl_auth_enable=yes
  -o smtpd_sasl_type=dovecot
  -o smtpd_sasl_path=private/auth
  -o smtpd_relay_restrictions=permit_sasl_authenticated,reject

# /etc/postfix/main.cf
relayhost = [smtp.mailgun.org]:587
smtp_sasl_auth_enable = yes
smtp_sasl_password_maps = hash:/etc/postfix/sasl_passwd
```

**Spam filtering:** Requires SpamAssassin as a separate service. Runs as a Postfix milter or content filter, adds `X-Spam-Score` / `X-Spam-Status` headers before the message reaches the receiver script.

```
# /etc/postfix/main.cf
smtpd_milters = inet:localhost:8891, inet:localhost:783
# opendkim milter + spamass-milter
```

**Pros:**
- Most mature, most compatible — if an IMAP client exists, it works with Dovecot
- Massive community, every problem has been solved on StackOverflow
- Sieve filtering support (user-defined rules)
- Maildir is a simple, well-understood format

**Cons:**
- Dual storage (Maildir + SQL) — sync bugs are possible
- Lots of configuration files across Dovecot, SpamAssassin, spamass-milter
- Three additional services to manage (Dovecot, SpamAssassin, spamass-milter)
- Flag/folder changes made via IMAP must be synced back to SQL (requires Dovecot plugin or polling)

#### Option B: Stalwart Mail Server (Modern, PostgreSQL-Native)

Stalwart is a single Rust binary that handles IMAP, SMTP, and JMAP. Its key advantage: it can store mail directly in PostgreSQL, eliminating the dual-storage problem entirely.

**Storage model:** PostgreSQL is the single source of truth. Stalwart reads and writes mail directly in the database. The webmail UI reads the same tables (or queries Stalwart's tables). No Maildir, no filesystem sync.

This changes the Phase 1 storage design. Instead of the Joinery `ems_email_stored` table, Stalwart manages its own schema. The webmail either:
- Queries Stalwart's tables directly (couples to Stalwart's internal schema, which may change between versions)
- Uses Stalwart's JMAP API (clean interface, no schema coupling, but adds a network hop)
- Maintains its own index table populated by Stalwart webhooks or triggers

The JMAP approach is cleanest — Stalwart exposes a full JMAP API that the webmail PHP code can call over HTTP to localhost. JMAP is specifically designed for webmail (it is the modern successor to IMAP, created by Fastmail). This means the webmail UI becomes a JMAP client rather than a direct database reader.

**Auth configuration (stalwart.toml):**
```toml
[directory."joinery"]
type = "sql"
address = "postgresql://user:pass@localhost/joinerytest"

[directory."joinery".pool]
max-connections = 10

[directory."joinery".lookup]
name = "SELECT emb_local_part || '@' || efd_domain AS name FROM emb_email_mailboxes emb JOIN efd_email_forwarding_domains efd ON efd.efd_email_forwarding_domain_id = emb.emb_efd_domain_id WHERE emb_local_part || '@' || efd_domain = $1 AND emb_is_enabled = true"

[directory."joinery".columns]
secret = "usr_password"
type = "bcrypt"
```

**SMTP submission:** Stalwart includes its own SMTP server. Configure it to relay outbound through Mailgun:
```toml
[remote."mailgun"]
address = "smtp.mailgun.org"
port = 587
tls.starttls = true
auth.username = "postmaster@yourdomain.com"
auth.secret = "mailgun-smtp-password"
```

**Spam filtering:** Stalwart has built-in spam filtering with Sieve scripts, DNS blocklists, SPF/DKIM/DMARC validation, and collaborative filtering. Not as mature as SpamAssassin's Bayesian learning, but covers the common cases. SpamAssassin can still be added as a milter if needed.

**Pros:**
- Single binary, single service — replaces Dovecot + SpamAssassin + spamass-milter
- PostgreSQL-native storage — no Maildir, no dual-storage sync
- JMAP API — clean interface for webmail, purpose-built for web email clients
- Built-in spam filtering, SMTP submission, Sieve — fewer moving parts
- Active development, modern codebase

**Cons:**
- Younger software (~3 years) — fewer edge cases handled than Dovecot
- Stalwart's PostgreSQL schema is its own — webmail either couples to it or uses JMAP API
- Less community knowledge — problems may require reading source code
- JMAP compliance is good but not as tested across as many clients as Dovecot's IMAP
- If Stalwart's development stalls, we are dependent on a single project

#### Option C: Custom Go IMAP Server (go-imap Library)

Build a minimal IMAP server in Go that reads directly from the PostgreSQL tables defined in Phase 1. The `go-imap` library provides the IMAP protocol parser and session management — we implement the storage backend interface.

**Storage model:** PostgreSQL is the single source of truth, using the Joinery-owned `ems_email_stored` schema from Phase 1. The Go server and the PHP webmail read the same tables. No external schema dependency, no Maildir.

**What go-imap provides:**
- Full IMAP protocol parsing (the ugly parenthesized-list wire format)
- Session management and connection handling
- Server framework with backend interface to implement
- TLS support

**What we implement (~2000-3000 lines):**
- Backend interface: `Login`, `ListMailboxes`, `GetMailbox`, `CreateMailbox`, `DeleteMailbox`, `RenameMailbox`
- Mailbox interface: `ListMessages`, `SearchMessages`, `CreateMessage`, `UpdateMessagesFlags`, `CopyMessages`, `Expunge`
- Auth: query `emb_email_mailboxes` + `usr_users`, verify bcrypt password with `golang.org/x/crypto/bcrypt`
- IDLE: PostgreSQL `LISTEN/NOTIFY` on new message insert — when a message is delivered, `NOTIFY new_email, '{mailbox_id}'`, the Go server pushes to connected IMAP clients
- Message serving: read `ems_email_stored` fields, reconstruct RFC 822 message from stored headers + body for FETCH

**IMAP commands that matter (what clients actually use):**
- `LOGIN` / `AUTHENTICATE` — auth against Joinery users table
- `SELECT` / `EXAMINE` — open a folder (query `efo_email_folders`)
- `FETCH` — get message headers/body/flags (query `ems_email_stored`)
- `STORE` — set flags: read, flagged, deleted (update `ems_email_stored`)
- `SEARCH` — find messages by criteria (SQL queries against `ems_email_stored`)
- `COPY` / `MOVE` — between folders (update `ems_efo_folder_id`)
- `EXPUNGE` — permanently remove deleted messages
- `IDLE` — push notifications via PostgreSQL LISTEN/NOTIFY
- `LIST` / `LSUB` — folder listing (query `efo_email_folders`)
- `APPEND` — save to folder (insert `ems_email_stored` — used by clients for Sent/Drafts)
- `NOOP` — keepalive

That is roughly 15 commands. The gnarly parts of IMAP (CONDSTORE, QRESYNC, partial fetches with byte ranges, MULTIAPPEND) are optimizations that clients degrade gracefully without. A single-user server with at most phone + desktop connected simultaneously avoids most concurrency edge cases.

**SMTP submission:** Same as Dovecot option — Postfix handles submission with SASL auth. The Go server can provide a SASL auth endpoint, or Postfix authenticates directly against the database via `pam_pgsql` or a simple auth daemon.

Alternatively, the Go binary handles SMTP submission itself (libraries like `go-smtp` exist). This keeps everything in one process but is more development work.

**Spam filtering:** Still needs SpamAssassin as a Postfix milter (same as Dovecot option). The Go server does not handle spam scoring.

**Deployment:** Built and deployed the same way as the ScrollDaddy DNS server — `make release`, single binary, systemd service. Already a known pattern in this project.

```go
// Simplified backend structure
type JoineryBackend struct {
    db *pgxpool.Pool
}

func (b *JoineryBackend) Login(connInfo *imap.ConnInfo, username, password string) (imap.User, error) {
    // Query emb_email_mailboxes JOIN usr_users
    // Verify bcrypt password
    // Return JoineryUser
}

type JoineryUser struct {
    mailboxID int
    db        *pgxpool.Pool
}

func (u *JoineryUser) ListMailboxes(subscribed bool) ([]imap.MailboxInfo, error) {
    // SELECT from efo_email_folders WHERE efo_emb_mailbox_id = $1
}

type JoineryMailbox struct {
    folderID  int
    mailboxID int
    db        *pgxpool.Pool
}

func (m *JoineryMailbox) ListMessages(uid bool, seqset *imap.SeqSet, items []imap.FetchItem, ch chan<- *imap.Message) error {
    // SELECT from ems_email_stored WHERE ems_efo_folder_id = $1
    // Reconstruct RFC 822 message from stored fields
}
```

**Pros:**
- PostgreSQL-native — same tables as webmail, no sync, no external schema dependency
- Single binary under our control — no config file sprawl, behavior matches exactly what we need
- LISTEN/NOTIFY for IDLE — elegant push without polling
- Familiar deployment pattern (Go binary + systemd, same as ScrollDaddy DNS)
- Only implements what major clients need — less code than full IMAP, but sufficient
- Can evolve independently — add commands as needed based on actual client behavior

**Cons:**
- Development effort — 2000-3000 lines of Go, plus testing against real clients
- We own the bugs — Dovecot has had 20 years of edge-case fixes, we are starting fresh
- Obscure clients may hit unimplemented commands — need graceful error responses
- RFC 822 message reconstruction from stored fields must be correct (headers, MIME boundaries, encoding) — subtle and easy to get wrong
- Still needs SpamAssassin (unlike Stalwart which has built-in filtering)

#### IMAP Option Recommendation

**For shipping fast:** Dovecot. It is `apt install` and config files. The dual-storage tradeoff is annoying but manageable for a single-user server.

**For the cleanest architecture:** Stalwart. PostgreSQL-native storage eliminates the biggest pain point. JMAP API is purpose-built for webmail. One process replaces three. The risk is maturity — if something breaks, the community is smaller.

**For maximum control and long-term ownership:** Custom Go server. The code is bounded (2000-3000 lines), the deployment pattern is proven, and the storage model is exactly what the webmail needs. The risk is development time and IMAP edge cases.

A pragmatic path: **start with Dovecot to ship, build the Go server as a second-phase replacement.** Dovecot gets IMAP working immediately. The Go server can be developed against the same PostgreSQL tables and swapped in when ready, at which point the Maildir layer and Dovecot dependency are removed. Stalwart is the middle ground if you want PostgreSQL-native storage without building IMAP yourself.

### Phase 5b: Spam Filtering

Spam filtering approach depends on the IMAP option chosen:

**With Dovecot or Custom Go:** SpamAssassin runs as a Postfix milter or content filter. It adds headers (`X-Spam-Score`, `X-Spam-Status`, `X-Spam-Flag`) before the message reaches the receiver script.

```
# spamass-milter approach (cleaner)
# /etc/postfix/main.cf
smtpd_milters = inet:localhost:8891, inet:localhost:783
# opendkim milter + spamass-milter
```

**With Stalwart:** Built-in spam filtering handles DNS blocklists, SPF/DKIM/DMARC validation, header analysis, and Sieve-based rules. SpamAssassin can optionally be added as a milter for Bayesian learning.

**What SpamAssassin gives you (when used):**
- Header analysis (forged headers, missing headers)
- Body analysis (known spam phrases, patterns)
- Bayesian filtering (learns from the user's ham/spam over time — this is the single-user advantage)
- Network tests (Razor, Pyzor, DCC — collaborative filtering networks)
- URI blocklists (SURBL, URIBL)
- DNS-based checks (SPF validation, sender verify)

**Bayesian learning integration:**

When a user marks a message as spam or not-spam via the webmail UI, feed the message back to SpamAssassin's Bayes learner. The mechanism depends on storage model:

With Maildir (Dovecot):
```php
// Move to Junk:
exec('sa-learn --spam --file=' . escapeshellarg($maildir_path));
// Move out of Junk:
exec('sa-learn --ham --file=' . escapeshellarg($maildir_path));
```

With PostgreSQL-only (Stalwart or Go server):
```php
// Write message to temp file, then sa-learn
$tmp = tempnam('/tmp', 'spam_');
file_put_contents($tmp, $raw_message);
exec('sa-learn --spam --file=' . escapeshellarg($tmp));
unlink($tmp);
```

Over time, Bayesian learning becomes quite effective for a single user. The database is per-user, stored locally, and learns the specific spam patterns that target that user.

**Honest tradeoff:** RBL + SpamAssassin + DKIM verification + Bayesian learning is the industry standard for self-hosted email. It is not Gmail-level (Gmail has billions of messages to train on), but it is good enough for personal email. The combination catches the vast majority of commodity spam. What it misses: highly targeted spear-phishing, brand-new campaigns before network tests catch up, and sophisticated social engineering.

### Phase 6: S3 Archiving (Optional)

For long-term storage and backup, old messages can be archived to an S3-compatible bucket (AWS S3, Backblaze B2, MinIO, etc.).

**Archive process (scheduled task):**
1. Find messages older than `email_hosting_archive_after_days`
2. Export raw message to S3: `s3://{bucket}/archive/{mailbox_id}/{year}/{month}/{message_id}.eml`
3. Mark `ems_email_stored` record as archived (new field: `ems_is_archived`, `ems_archive_path`)
4. Remove message body content from local storage (Maildir file for Dovecot, body columns for PostgreSQL-only options) — keep the SQL index row for search
5. Message disappears from IMAP (expected behavior for archived mail)

**Retrieval:** When a user clicks an archived message in webmail, fetch from S3 on demand. Cache locally for the session. Optionally allow "un-archive" to restore fully.

**Why S3 and not just bigger disk:** Disk is cheap but VPS disk is not unlimited. A 50GB VPS disk with 20GB of email after 3 years benefits from offloading old mail to $0.005/GB/month storage. This is an optimization, not a launch requirement.

## Plugin Structure

```
/plugins/email_hosting/
├── plugin.json
├── uninstall.php
├── data/
│   ├── email_mailbox_class.php          — EmailMailbox + MultiEmailMailbox
│   ├── email_folder_class.php           — EmailFolder + MultiEmailFolder
│   ├── email_stored_class.php           — EmailStored + MultiEmailStored
│   └── email_attachment_class.php       — EmailAttachment + MultiEmailAttachment
├── includes/
│   ├── EmailReceiver.php                — Inbound delivery (parse, store, index)
│   ├── EmailComposer.php                — Compose, reply, forward logic
│   ├── MimeParser.php                   — MIME multipart parsing + attachment extraction
│   ├── MaildirStorage.php               — Maildir read/write (Dovecot option only)
│   ├── EmailSearcher.php                — Full-text search wrapper
│   └── S3Archiver.php                   — S3 archive/retrieve operations
├── scripts/
│   ├── email_receiver.php               — Postfix pipe script (inbound delivery)
│   └── email_submission.php             — Outbound via Mailgun (called by submission flow)
├── views/
│   └── mail/
│       ├── index.php                    — Inbox / folder list view
│       ├── message.php                  — Read message view
│       ├── compose.php                  — Compose / reply / forward
│       └── settings.php                 — Mailbox settings
├── logic/
│   └── mail/
│       ├── index_logic.php
│       ├── message_logic.php
│       ├── compose_logic.php
│       └── settings_logic.php
├── admin/
│   ├── admin_email_hosting.php          — Mailbox management
│   ├── admin_email_hosting_mailbox.php  — Edit mailbox
│   └── admin_email_hosting_setup.php    — Server setup guide / status
├── logic/admin/
│   ├── admin_email_hosting_logic.php
│   ├── admin_email_hosting_mailbox_logic.php
│   └── admin_email_hosting_setup_logic.php
├── ajax/
│   ├── email_move.php                   — Move messages between folders (AJAX)
│   ├── email_flag.php                   — Toggle read/flagged/spam (AJAX)
│   ├── email_delete.php                 — Delete messages (AJAX)
│   ├── email_search.php                 — Search endpoint (AJAX)
│   └── email_attachment.php             — Serve attachment (authenticated)
├── tasks/
│   ├── ArchiveOldEmail.php              — S3 archiving scheduled task
│   ├── UpdateStorageUsage.php           — Recalculate mailbox storage
│   └── TrainSpamFilter.php              — Batch sa-learn for spam/ham feedback
├── migrations/
│   └── (settings, menu entries, search trigger)
└── install/
    ├── dovecot_setup.sh                 — Dovecot installation and configuration (Option A)
    ├── stalwart_setup.sh                — Stalwart installation and configuration (Option B)
    ├── spamassassin_setup.sh            — SpamAssassin installation (Options A/C)
    └── postfix_hosting.sh               — Postfix config for hosting mode

# Custom Go IMAP server (Option C) lives in its own repo:
# /home/user1/joinery-imap/
#   cmd/imap/main.go
#   internal/backend/        — PostgreSQL backend implementing go-imap interfaces
#   internal/auth/           — bcrypt auth against Joinery users table
#   internal/notify/         — PostgreSQL LISTEN/NOTIFY for IDLE
#   Makefile                 — same build pattern as scrolldaddy-dns
```

## Server Dependencies

**Already installed (via email forwarding plugin):**
- Postfix
- opendkim
- PHP `mailparse` extension (check — may need `apt install php-mailparse`)

**New dependencies (vary by IMAP option):**

| Dependency | Dovecot | Stalwart | Custom Go |
|---|---|---|---|
| Dovecot (`dovecot-imapd`, `dovecot-lmtpd`) | Required | No | No |
| Stalwart binary | No | Required | No |
| Go build toolchain | No | No | Build-time only |
| SpamAssassin (`spamassassin`, `spamc`) | Required | Optional (has built-in) | Required |
| `spamass-milter` | Required | Optional | Required |
| HTMLPurifier (PHP) | Required (all options) | Required (all options) | Required (all options) |

**Optional (all options):**
- AWS SDK for PHP (for S3 archiving) — or use the simpler `aws` CLI

All system packages are available via `apt` on Ubuntu/Debian. Stalwart is a standalone binary download. The custom Go server is built from source (same as ScrollDaddy DNS).

## DNS Requirements (Per Hosted Domain)

Same as email forwarding, plus Mailgun verification:

```
; MX record — points to this server
@                 MX   10  mail.yourserver.com.

; SPF — authorize Mailgun to send on your behalf
@                 TXT  "v=spf1 ip4:YOUR_SERVER_IP include:mailgun.org -all"

; DKIM — both your server's key and Mailgun's key
mail._domainkey   TXT  "v=DKIM1; k=rsa; p=YOUR_SERVER_KEY"
; Mailgun DKIM records (CNAME) — provided by Mailgun during domain verification

; DMARC (recommended)
_dmarc            TXT  "v=DMARC1; p=quarantine; rua=mailto:dmarc@yourdomain.com"

; Autodiscover / autoconfig for mail client setup
_autodiscover._tcp  SRV  0 0 443 mail.yourserver.com.
autoconfig          CNAME mail.yourserver.com.
```

The admin domain setup page already validates MX, SPF, and DKIM. Extend it to validate Mailgun CNAMEs and DMARC.

## Security Considerations

1. **TLS everywhere:** IMAPS (993) only, no plaintext IMAP. Postfix submission (587) with STARTTLS required. Let's Encrypt certificates (already used for web).
2. **Authentication:** IMAP server authenticates against bcrypt passwords in the users table. Failed login rate limiting via fail2ban (all options) or built-in mechanisms (Dovecot, Stalwart).
3. **HTML sanitization:** All HTML email displayed in webmail must be sanitized (HTMLPurifier) to prevent XSS. External images blocked by default.
4. **Attachment serving:** Authenticated endpoint only — verify the requesting user owns the mailbox before serving any attachment.
5. **CSRF protection:** All webmail actions (move, delete, send) use CSRF tokens (existing Joinery pattern).
6. **File permissions:** If using Maildir (Dovecot option): `0700` on directories, `0600` on message files, owned by `www-data`. Attachment storage directory: same permissions regardless of IMAP option.
7. **Quota enforcement:** Hard quota prevents a mailbox from consuming all VPS disk. Soft warning at 80%.

## Implementation Order

1. **Phase 1 (Mailbox Storage)** — Data models, folder management, storage quota. Foundation for everything else.
2. **Phase 2 (Inbound Delivery)** — Get mail into the system. Extend Postfix pipe, MIME parsing, store in SQL. Test by sending email to a hosted address.
3. **Phase 3 (Outbound via Mailgun)** — Compose and send. Verify Mailgun domain setup. Saves to Sent folder.
4. **Phase 4 (Webmail UI)** — Inbox, read, compose, folders, search. This is the largest single phase by UI effort.
5. **Phase 5 (IMAP + Spam)** — Choose and deploy IMAP option, spam filtering. Can be developed in parallel with Phase 4.
6. **Phase 6 (S3 Archiving)** — Nice-to-have, not launch-critical.

Phases 1-4 are the minimum viable product — webmail-only email that works in a browser. Phase 5 makes it a real email client replacement (Thunderbird, phone apps). Phase 6 is operational optimization.

**IMAP option decision point:** The IMAP option should be chosen before starting Phase 2, because it affects the storage model. With Dovecot, Phase 2 writes to Maildir + SQL. With Stalwart or custom Go, Phase 2 writes to SQL only. Starting with SQL-only is simpler and keeps the Dovecot option available later (Maildir can be generated from SQL if needed).

## Open Questions

1. **IMAP option:** Dovecot (ship fast, dual storage), Stalwart (PostgreSQL-native, newer), or custom Go (full control, development effort). See Phase 5 for detailed comparison. Pragmatic path: start SQL-only for Phases 1-4, choose IMAP option when Phase 5 begins.
2. **Webmail theme:** Should the webmail use the admin theme (Bootstrap, available now) or get its own dedicated theme? An email client benefits from information density that a CMS frontend theme might not optimize for.
3. **Calendar/contacts:** Users leaving Gmail will eventually want CalDAV and CardDAV. Radicale is a lightweight Python CalDAV/CardDAV server that stores in filesystem/SQLite. Not in scope here but worth noting as a future addition.
4. **Multiple mailboxes per user:** The schema supports it (one user can have several addresses). Should the webmail show a unified inbox or per-mailbox views? Start with per-mailbox, add unified later.
5. **Forwarding coexistence:** A domain could have some addresses as hosted mailboxes and others as forwarding aliases. The receiver script needs to check both tables. Worth supporting from the start.
6. **Mobile app:** IMAP covers mobile access via existing apps (K-9 Mail, FairEmail, Apple Mail). A responsive webmail is a bonus but IMAP is the primary mobile story.
