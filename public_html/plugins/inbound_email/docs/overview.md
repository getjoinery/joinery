# Inbound Email

## Overview

The Inbound Email plugin (`/plugins/inbound_email/`) is the platform's
inbound email subsystem — the receiving counterpart to outbound sending
(`SystemMailer`). Its first feature is **forwarding**: admins create aliases
(e.g., `info@example.com`) that forward incoming email to real addresses.

Postfix receives inbound mail, pipes it to a PHP handler, which looks up the
alias and relays it through the selected outbound provider (see
[Forwarding relay](#forwarding-relay)).

**Features:** multiple domains, multiple destinations per alias, catch-all addresses, SRS for SPF compatibility, inbound authentication results (SPF/DKIM/DMARC) read from the verifying MTA / provider, outbound DKIM signing (opendkim), per-alias and per-domain rate limiting, RBL spam filtering, inbound email logs with admin viewer, live DNS validation.

## Installation

### Prerequisites

Postfix (with the `postfix-pgsql` map driver), opendkim and opendmarc are
installed and configured by `provisioning/install_email.sh` — run it once per
deployment (see Server Setup below). It assumes one Joinery site per host; that
host may be a Docker container or bare metal.

> **Setup status on the Plugins page.** Once activated, this plugin declares three provisioners, so the admin Plugins page (`/admin/admin_plugins`) reports whether its runtime dependencies are working: a missing inbound mail server shows **Needs setup** with the `provisioning/install_email.sh` fix command; a down or misconfigured outbound relay shows **Needs setup** with the reason; and missing MX/SPF DNS records on any enabled inbound domain show **Needs setup** listing the affected domains. See the "Declaring Host Provisioners" section of `docs/plugin_developer_guide.md`.

### Enabling

1. Activate the plugin in **Admin > System > Plugins**
2. Run **update_database** from admin utilities to create tables and run migrations
3. **Incoming** appears under **Emails** in the admin sidebar — it opens on the
   **Setup** tab

### Setup & verification (mailbox-first)

The **Setup** tab (`Emails > Incoming > Setup`) verifies one mailbox at a time.
Pick a registered mailbox (from the Accounts tab) in the dropdown; the checks
scope to that mailbox and split into two groups:

- **Receiving** (always): the mailbox's domain DNS verified for *correctness*,
  not just presence (MX target actually resolves to this server, SPF authorizes
  the IP, DMARC published), that the domain is registered, that inbound mail is
  being authentication-verified (opendkim-verify + opendmarc), that the alias
  resolves, and an **end-to-end** proof — send a real message and watch it land
  in the logs. For an **IMAP-source** mailbox there is no MX/host stack, so this
  group instead reports the feed's connection state and last fetch.
- **Forwarding** (only when the mailbox forwards): the outbound relay, SRS, and
  DKIM signing — the checks that matter when mail is forwarded back out.

Copy-ready DNS records and exact fix commands appear inline on any failing
check, along with one-click actions to enable the plugin or register a domain.
The tab cannot create DNS records or set reverse DNS for you (those live with
your registrar / VPS provider) — it detects, instructs, and verifies.

#### Advanced server setup

Settings and diagnostics that are server-wide rather than per-mailbox live
behind the **Advanced server setup** disclosure: the inbound **provider** picker
(`inbound_email_provider`), this server's **mail identity** — the FQDN
(`inbound_email_mail_hostname`, used as the MX target, HELO name, and PTR name)
and public IP — the provider's DNS records to publish, and the **full inbound
health run** (every layer: Postfix/pipe transport/domain map/opendkim/port 25,
mail identity, domain DNS, plugin config, and end-to-end). Set the mail hostname
here once; everything else is autodetected.

### The Accounts tree

**Emails > Incoming > Accounts** is the single place to see and manage routing:
every domain, the mailboxes (aliases) nested under it, how each mailbox routes
(stored / forwarded / both), and any IMAP feed pulling mail into it. A domain is
either MX-hosted (mail pushed in) or an **IMAP source** (mail pulled in per
mailbox — e.g. `gmail.com`, no MX needed); both nest identically. The tree is the
overview and entry point; **+ Domain**, **+ Mailbox**, and every **Edit** open the
per-object editor with context pre-filled. Under an **IMAP-source** domain the
mailbox *is* its feed: **+ Mailbox** and **Edit** open one combined editor that
manages the mailbox name, its access grants, and the IMAP feed together (creating
the feed if the mailbox doesn't have one yet) — there is no separate feed object
to add. Hosted (MX) domains keep a distinct **+ IMAP feed** per mailbox.

### Adding a Domain

The Setup tab can register a domain for you (a one-click action on the
"Domain registered" check). To manage domains directly: on the **Accounts** tab
click **+ Add Domain**, enter the name
and save — Postfix picks it up immediately (the inbound domain list is read live
from the database; no host command, no per-domain Postfix config). Then use the
Setup tab to verify and publish the domain's DNS records. Tick **IMAP source** on
a domain whose mail arrives by IMAP poll rather than MX.

### Adding an Alias (mailbox)

1. On the **Accounts** tab, click **+ Mailbox** on the domain you want
2. Enter the alias name, delivery mode, and destinations (for forwarding)
3. Save

## Server Setup

On apt-based systems, run `provisioning/install_email.sh` as root, once per
deployment. It installs Postfix, `postfix-pgsql`, opendkim and opendmarc and
applies the **fixed** base configuration, idempotently:

- the `joinery` pipe transport in `master.cf`;
- `virtual_transport = joinery`, `inet_interfaces = all`, a safe
  `mydestination`, and RBL `smtpd_recipient_restrictions`;
- `virtual_mailbox_domains` wired to a PostgreSQL map (see below) so Postfix
  reads the live inbound-domain list straight from the database;
- opendkim config — inet socket on `localhost:8891`, `Mode sv` (sign **and
  verify**), empty key/signing tables, and an `AuthservID` matching the
  configured mail hostname;
- opendmarc config — inet socket on `localhost:8893`, `SPFSelfValidate true`,
  `RejectFailures false` (stamp-only, never reject);
- both Postfix milters, in order: `smtpd_milters = inet:localhost:8891,
  inet:localhost:8893` (opendkim first so opendmarc can consume its DKIM
  result), with `milter_default_action = accept` so a down/keyless milter never
  blocks mail. Received mail is thereby stamped with an `Authentication-Results`
  header the app reads for SPF/DKIM/DMARC (see **Inbound authentication** below).

The only genuinely per-deployment work left is DNS, and per-domain DKIM keys.
Adding or removing an inbound domain needs **no host action** — see below.

### Inbound authentication (SPF / DKIM / DMARC)

**The app never computes these verdicts itself.** SPF and DMARC are *structurally
impossible* to compute at the PHP layer: SPF is a function of the connecting
client IP evaluated against the sender domain's record, and the inbound Postfix
pipe (`utils/inbound_email_handler.php`) only ever receives the raw MIME on
stdin plus the envelope recipient — the connecting IP is known only to `smtpd`,
before the pipe. So the verdicts come from whoever ran the inbound MTA, in one
of two trusted forms, resolved by `InboundEmailRouter::readAuthResults()` in
precedence order:

1. **Webhook provider verdicts.** A provider that received and verified the
   message upstream (Mailgun, SendGrid, SES) returns its SPF/DKIM/DMARC results
   from `handleInbound()` as an `auth` array. The webhook dispatcher threads
   that into `processEmail()`, and the router records it with
   `iem_auth_source =` the provider key (`mailgun` / `sendgrid` / `ses`).
2. **Authentication-Results header.** For the self-hosted Postfix path, the
   verifying milters — `opendkim` in verify mode and `opendmarc`
   (`SPFSelfValidate`) — evaluate SPF/DKIM/DMARC on receipt and stamp an
   `Authentication-Results` header with our `AuthservID`. `AuthenticationResults`
   (in `includes/`) parses that header and the router records
   `iem_auth_source = 'milter'`.

Either way the verdicts land in `iem_spf_result` / `iem_dkim_result` /
`iem_dmarc_result`. Each provider normalizes its native field values to the same
token set the header parser produces: `pass | fail | softfail | neutral | none |
temperror | permerror`. A method a source does not assert reads `none`. Only SES
reports a real DMARC verdict; Mailgun and SendGrid report SPF and DKIM only, so
their `dmarc` reads `none`.

**Trust model.** Provider verdicts are trusted only because they ride that
provider's authenticated delivery path: Mailgun's HMAC-signed POST (verified in
`MailgunProvider::handleInbound()`), SES's SNS message signature (verified
against AWS's signing certificate, pinned to an `sns.<region>.amazonaws.com`
host), and a shared secret on the Destination URL for SendGrid Inbound Parse
(which does not sign its requests — a blank `sendgrid_inbound_secret` rejects
everything). A forged `X-Mailgun-Spf`, `SPF`, or `receipt` blob on mail that did
**not** arrive through the matching provider is never honored: the `auth` key
only exists when that provider object handled the request. For the header path,
a message can carry attacker-supplied `Authentication-Results` lines from
upstream hops, so the parser honors **only** a line whose authserv-id equals our
mail host (the milters' `AuthservID`, == `inbound_email_mail_hostname` — they
must match or verdicts are ignored). Lines stamped by anyone else are discarded.

**The `unverified` state is normal, not a failure.** When neither a provider
verdict nor a trusted `Authentication-Results` header is present — no verifying
milter installed, or mail that arrived some other way — the verdicts read
**`unverified`** and `iem_auth_source = 'none'`. A hand-rolled `fail` is
**never** emitted; an honest `unverified` is safer than a confident-but-wrong
verdict. Misreading a provider field is fail-safe the same way: an unrecognized
value falls through to `none` (or, when no verdict field is present at all,
`unverified`) — never a synthesized `pass`. The valid `iem_auth_source` values
are `milter`, `mailgun`, `sendgrid`, `ses`, and `none`.

The message detail page and the Mailbox reader show the sourced verdicts, or an
explicit "unverified — no verifying milter installed", never a bare red `fail`.

#### Verification-capability warning (Setup tab)

The Setup tab runs an **Inbound authentication verified** check
(`host.inbound_verification` in `InboundEmailSetupCheck`) so a missing or broken
verifier surfaces as an explained warning rather than a silent `unverified`:

- **WARN** — the selected provider has no inbound verification path at all,
  **or** the provider is Postfix but verification is broken (milter unreachable,
  opendmarc missing, config drift). Fix: run `install_email.sh`, then send a test
  message to confirm an `Authentication-Results` header appears.
- **INFO** (neutral) — the provider verifies, but no verdict-carrying mail has
  arrived **yet** to confirm it. For Postfix this also covers a host whose config
  isn't readable by the web user; for a webhook provider (Mailgun/SendGrid/SES)
  it simply means no message stamped with that provider's `iem_auth_source` has
  been received yet. We legitimately can't tell yet — not an alarm.
- **PASS** — recent mail carries this provider's verdicts (`iem_auth_source` =
  `milter` for Postfix, or `mailgun` / `sendgrid` / `ses` for a webhook
  provider). The behavioral signal (verdict-carrying mail actually seen) is
  authoritative, because a milter can be wired-but-unreachable; for Postfix the
  config probe only enriches the reason.

### The inbound-domain list is live, never "installed"

`install_email.sh` writes `/etc/postfix/joinery-domains.cf`, a `postfix-pgsql`
map (`640 root:postfix`), and sets:

```
virtual_mailbox_domains = pgsql:/etc/postfix/joinery-domains.cf
```

For every inbound recipient, Postfix asks the database whether that domain is
an active inbound domain. Adding, removing, enabling, or disabling a domain
in the admin UI is therefore effective immediately — no SSH, no root, no
re-run, and no drift. `install_email.sh` creates a dedicated least-privilege
PostgreSQL role for the map — it can `SELECT` the inbound-domain list and
nothing else, never the application's superuser — and writes the map. The
role's password lives only in the map file; re-running `install_email.sh`
rotates it.

If Postfix's `smtpd` / `trivial-rewrite` services run chrooted, `install_email.sh`
wires the map as `proxy:pgsql:...` instead (proxymap runs un-chrooted). Modern
Debian/Ubuntu ship these services un-chrooted, so the bare `pgsql:` map is used.

### DNS (per domain)

```
@                 MX   10  mail.yourserver.com.
@                 TXT  "v=spf1 ip4:YOUR_SERVER_IP -all"
mail._domainkey   TXT  "v=DKIM1; k=rsa; p=YOUR_PUBLIC_KEY"
```

### Postfix reference (non-apt systems)

`install_email.sh` is the supported installer. On a non-apt system, apply the
equivalent fixed config by hand. In `/etc/postfix/main.cf`:

```
virtual_transport = joinery
virtual_mailbox_domains = pgsql:/etc/postfix/joinery-domains.cf
inet_interfaces = all
mydestination = localhost, localhost.localdomain

# opendkim (verify) then opendmarc — order matters; accept on milter failure.
milter_default_action = accept
smtpd_milters = inet:localhost:8891, inet:localhost:8893
non_smtpd_milters = inet:localhost:8891

smtpd_recipient_restrictions =
    permit_mynetworks, reject_unauth_destination,
    reject_rbl_client zen.spamhaus.org,
    reject_rbl_client bl.spamcop.net,
    reject_rbl_client b.barracudacentral.org,
    reject_rhsbl_helo dbl.spamhaus.org,
    reject_rhsbl_sender dbl.spamhaus.org, permit
```

opendkim must run `Mode sv` with an `AuthservID` equal to your mail hostname
(== `inbound_email_mail_hostname`), and opendmarc with `SPFSelfValidate true`
and `RejectFailures false`. See `provisioning/install_email.sh` for the exact
managed config both daemons use.

`/etc/postfix/joinery-domains.cf` (the pgsql map). `install_email.sh` creates
the dedicated role and writes this file automatically; on a non-apt system,
create the role by hand — `CREATE ROLE "iemap_<dbname>" LOGIN PASSWORD '...';`
then `GRANT SELECT ON ied_inbound_email_domains` to it — and write the map as
that role:

```
hosts    = localhost
user     = iemap_<dbname>
password = <the role's password — lives only in this file>
dbname   = <db name>
query    = SELECT ied_domain FROM ied_inbound_email_domains
           WHERE lower(ied_domain) = '%s'
             AND ied_is_enabled = true
             AND ied_delete_time IS NULL
```

Add to `/etc/postfix/master.cf`:

```
joinery   unix  -  n  n  -  5  pipe
  flags=DRhu user=www-data
  argv=/usr/bin/php /var/www/html/SITENAME/public_html/plugins/inbound_email/utils/inbound_email_handler.php ${recipient}
```

(Use the PHP CLI path for your system — `install_email.sh` resolves it
automatically; the official `php` Docker images ship it at `/usr/local/bin/php`.)

### opendkim (DKIM signing + inbound verify)

`install_email.sh` installs opendkim's **static** config (the inet socket,
`Mode sv`, `AuthservID`, empty `key.table` / `signing.table` / `trusted.hosts`,
and the Postfix milter). `Mode sv` means it **signs** outbound *and* **verifies**
inbound (stamping the DKIM result into `Authentication-Results` — see **Inbound
authentication** above, where opendmarc adds SPF/DMARC). opendkim runs from
first install — keyless for *signing* until a per-domain key is added, but
*verifying* inbound DKIM immediately — and `milter_default_action = accept`
guarantees a keyless or down opendkim never blocks or defers mail.

> The opendkim.conf the installer writes is keyed on a managed marker, so a host
> already wired on an older version (which lacked `AuthservID`) is upgraded in
> place on the next run. The live dev box was once found running **Debian-stock**
> opendkim.conf with Postfix dialing a dead `inet:8891` socket — the milter was a
> silent no-op in both directions; re-running the installer realigns the socket
> and restores both verify and signing.

Generating a key is a **per-domain** step (a key file on disk plus a DNS record
cannot be a database lookup). `provisioning/provision_dkim.sh` does the whole
host side in one idempotent command:

```bash
sudo bash plugins/inbound_email/provisioning/provision_dkim.sh example.com
```

It runs `opendkim-genkey`, appends the `key.table` / `signing.table` lines
(only if absent), restarts opendkim, and prints the DNS TXT record to publish
at `mail._domainkey.example.com`. Re-running for a domain that already has a
key is a no-op that just reprints the record. The Setup tab's "DKIM signing
key" check offers this exact command as its fix, and the following "DKIM record
published" check then hands you the TXT record as a copy-paste DNS fix.

Forwarding works without a DKIM key; only outbound DKIM signing is affected.

### Firewall

`install_email.sh` runs `ufw allow 25/tcp` when ufw is active. Bare metal or a
container, the site's Postfix owns port 25 on its host.

### Container persistence

On a **systemd host**, `install_email.sh` runs `systemctl enable`, so Postfix
and opendkim restart on boot automatically — nothing else is needed.

A **Docker container** has no systemd; its `CMD` is the init. The Joinery site
image handles the mail stack the same way it handles PostgreSQL and cron — by
(re)starting it on every container start. When the Inbound Email plugin is
active, the `CMD` runs `_mail_stack_start.sh`, which re-applies the Postfix /
opendkim configuration and starts both daemons (via the idempotent
`install_email.sh`). The mail packages themselves are baked into the base
image. So in a container the mail stack survives a `docker stop`/`start` and
an image rebuild with no manual step.

This applies to images built from base image version 1.1 or later. An older
container keeps relying on a manual `install_email.sh` run until it is rebuilt
and redeployed — base-image changes do not travel through the code-upgrade
pipeline. See the `mail_stack_container_persistence` spec.

### Advanced: multi-site host relay (manual, not installed)

A more complex topology — several sites behind one IP, with a host front-relay
demultiplexing inbound mail to per-container Postfix instances by domain — is
possible but is **manual, operator-level configuration**. `install_email.sh`
assumes one site per host and does not set this up. If you run it, the host
relay (`relay_domains`, `transport_maps`) and per-container port mapping are
yours to maintain; RBL checks would happen on the host relay only.

## Settings

| Setting | Default | Description |
|---|---|---|
| `inbound_email_enabled` | `0` | Master switch |
| `inbound_email_mail_hostname` | (empty) | FQDN of this mail server — MX target, HELO, PTR (set on the Setup tab) |
| `inbound_email_public_ip` | (empty) | Optional public-IP override; empty = autodetect |
| `inbound_email_srs_enabled` | `0` | SRS envelope rewriting (recommended) |
| `inbound_email_srs_secret` | (empty) | Required before SRS can be enabled |
| `inbound_email_forwarding_max_destinations` | `10` | Max destinations per alias |
| `inbound_email_forwarding_rate_limit_per_alias` | `50` | Per-alias limit per window |
| `inbound_email_forwarding_rate_limit_per_domain` | `200` | Per-domain limit per window |
| `inbound_email_forwarding_rate_limit_window` | `3600` | Rate limit window (seconds) |
| `inbound_email_log_retention_days` | `30` | Log cleanup threshold |
| `inbound_email_forwarding_smtp_host` | (empty) | Dedicated SMTP relay for forwarding. **When set, it forces the SMTP relay path** (overriding provider relay); falls back to base `smtp_*` for any field left blank. See [Forwarding relay](#forwarding-relay). |
| `inbound_email_forwarding_smtp_port` | (empty) | Falls back to `smtp_port` |
| `inbound_email_forwarding_smtp_username` | (empty) | Falls back to `smtp_username` |
| `inbound_email_forwarding_smtp_password` | (empty) | Falls back to `smtp_password` |

## Plugin Structure

```
/plugins/inbound_email/
├── plugin.json
├── data/          — Domain, Alias, Log models (auto-create tables)
├── includes/      — InboundEmailRouter (processing), InboundEmailHealth,
│                    InboundEmailSetupCheck (guided-setup verification engine), SRSRewriter
├── utils/         — Postfix pipe script (inbound_email_handler.php)
├── provisioning/  — Host setup: install_email.sh, render_pgsql_map.php
├── admin/         — Admin pages (setup, aliases, alias edit, domains, logs)
├── logic/         — Logic files for admin pages
├── tasks/         — PurgeOldInboundEmailLogs scheduled task
└── migrations/    — Settings and menu entry
```

**Tables:** `ied_inbound_email_domains`, `iea_inbound_email_aliases`, `iel_inbound_email_logs`

**How forwarded emails appear to recipients:**
- **From:** `"Original Sender via Site Name" <info@your-verified-domain.com>` — uses the site's verified sending address for deliverability
- **Reply-To:** `original-sender@their-domain.com` — hitting Reply goes to the right person
- **Subject:** Preserved from the original email

This approach is required because SMTP services (Mailgun, SendGrid, etc.) require the From address to be on a verified domain. Sending with an arbitrary external From would be silently dropped.

## Forwarding relay

Forwarding relays the message through the **selected outbound provider**
(`email_service`, the same provider ordinary outbound mail uses) when that
provider can relay raw MIME with a chosen envelope sender — reusing the one
credential the operator already maintains. There is no separate forwarding
SMTP password to configure or let go stale.

The router resolves one of two paths once, in `resolveRelayProvider()`:

1. **Provider relay** — the active provider implements the optional
   `RawMessageRelay` capability **and** no `inbound_email_forwarding_smtp_host`
   override is set. The original message bytes are relayed faithfully through
   the provider's API (Mailgun `messages.mime`, SES `sendEmail` with
   `Content.Raw`) or native SMTP, reusing the provider credential. Providers
   that implement it: **Mailgun, SMTP, SES**.
2. **SMTP fallback** — every other provider (Postmark, SendGrid, Brevo,
   Mailjet, Resend), or whenever `inbound_email_forwarding_smtp_host` is set.
   Relays over raw SMTP using the forwarding-specific
   `inbound_email_forwarding_smtp_*` settings, falling back to base `smtp_*`.
   This is also the path for operators who deliberately point forwarding at a
   dedicated relay.

When the provider relay is the primary path, any destination it fails is
**retried over the SMTP relay** — the same primary→fallback the outbound
`EmailSender` uses. Only the failed destinations are retried, so a partial
provider success never double-sends. The retry is skipped when it could not
help: when no base `smtp_host` is configured, or when the active provider *is*
the SMTP relay (same transport). A failure that survives both paths is logged
`STATUS_ERROR` as before.

All forward paths — alias forward, `forward_and_store`, and the domain
catch-all forward — go through this resolver, so the catch-all forward
preserves attachments and MIME structure exactly like the alias forward.

The **SRS bounce notification** (`handleSRSBounce`) is not a relay — it is a
freshly generated delivery-failure message, sent through the normal provider
send path (`EmailSender`), which also reuses the provider credential.

**SRS, per path.** On the **SMTP fallback** path the SRS-rewritten envelope
sender is honored as `MAIL FROM` (we are the MTA and own the return-path), so
SPF aligns at the destination and bounces route back through us for SRS
decoding. On the **provider relay** path, providers that own bounce handling
(Mailgun, SES) align their own SPF/DKIM with their sending domain and manage
bounces, so the SRS envelope is best-effort there and SRS bounce-decoding does
not apply — the From-header rewrite to the verified address is what carries
deliverability either way.

The **Outbound forwarding relay** check on the Setup tab verifies the
*resolved* relay: when provider relay is active it confirms the provider's own
credential is configured (so a healthy API key reads PASS even with empty
`smtp_*`); on the SMTP fallback path it connects to the SMTP relay and closes.

## Testing

Test without Postfix by piping raw email to the handler:

```bash
echo "From: alice@gmail.com
To: info@example.com
Subject: Test

Hello" | php plugins/inbound_email/utils/inbound_email_handler.php info@example.com
echo $?   # 0 = success, 67 = unknown alias, 75 = temp failure
```

## Troubleshooting

**Email not arriving:** Check inbound email logs (Incoming > Logs tab), verify alias and domain are enabled, check SMTP settings, check `error.log`.

**Email not reaching Postfix:** Verify MX records (`dig MX domain`), port 25 open, Postfix running. Confirm `virtual_mailbox_domains` is wired to the pgsql map (`postconf -h virtual_mailbox_domains` should show `pgsql:/etc/postfix/joinery-domains.cf`); the Domains page Server Status panel reports this. If a pgsql lookup fails because the database is down, Postfix returns a temporary error and the sender retries — mail is deferred, not lost.

**"User unknown in local recipient table":** The domain is in Postfix's `mydestination` setting, which takes priority over `virtual_mailbox_domains`. The admin domain edit page detects this conflict and shows a red "Conflict" badge. Run `install_email.sh` to fix — it sets `mydestination = localhost, localhost.localdomain`.

**Landing in spam:** Enable SRS, verify opendkim running and a DKIM key generated and its DNS record published, check SPF includes server IP, verify rDNS/PTR record, check IP at mxtoolbox.com.

## Delivery Modes

Each alias has a **delivery mode** (`iea_delivery_mode`):

- **`forward`** (default) — relay to one or more destination addresses; no copy is kept locally.
- **`store`** — persist the message to `iem_inbound_email_messages` for inspection in the admin Mailbox tab. Nothing is relayed. Destinations are not required.
- **`forward_and_store`** — relay AND keep a faithful copy of the original message.

Each domain also has a **catch-all mode** (`ied_catch_all_mode`):

- **`forward`** — send unmatched recipients to `ied_catch_all_address` (or reject/discard, per `ied_reject_unmatched`).
- **`store`** — persist every unmatched recipient on the domain to the local mailbox. This is the equivalent of a Mailgun wildcard `forward()` route. `ied_reject_unmatched` is ignored when catch-all mode is `store`.

## Local Mailbox

The **Mailboxes** tab (`Emails > Incoming > Mailboxes`) is the default landing
tab — a Gmail-style reader over locally-stored inbound messages. Each message
shows the parsed plain-text body, a sandboxed iframe rendering of the HTML body
(no scripts, no top-nav), and a per-attachment download; the single-message
detail page keeps the original raw MIME with a `.eml` download.

Stored bodies are fully attacker-controlled — admins should never paste a
captured token into a non-admin page or feed an untrusted body to an AI
agent without the platform's untrusted-input markers (see
`specs/implemented/joinery_ai_untrusted_input_markers.md`).

**Settings:**
- `inbound_email_mailbox_retention_days` (default `14`) — age after which the `PurgeOldMailboxMessages` scheduled task hard-deletes a stored message.
- `inbound_email_mailbox_max_per_window` (default `500`, `0` disables) — max non-deleted stored messages per domain inside the forwarding rate-limit window. Stores above the cap are dropped with status `store_capped`.

A `store`-only deployment does not need the outbound forwarding relay
provisioner — the `outbound_forwarding_relay` check may legitimately
report "Needs setup" without actually preventing inbound mail from being
captured.

**Test workflow:**

1. Add an inbound domain (e.g. `inbox.dev.getjoinery.com`) with catch-all mode **store**.
2. Publish its MX record pointing at this host and an SPF record; let the Setup tab confirm them green.
3. The test sends application mail to `whoever@inbox.dev.getjoinery.com`.
4. The test queries the store:
   ```sql
   SELECT * FROM iem_inbound_email_messages
   WHERE iem_recipient LIKE '%whoever%'
   ORDER BY iem_received_time DESC LIMIT 1;
   ```
5. Tests extract links / verify content from `iem_body_plain` / `iem_body_html`.
6. The retention task handles cleanup — no manual DELETE needed.

Dedup is enforced at the DB layer by a UNIQUE constraint on
`(iem_message_id_header, iem_recipient)`. A retry with the same Message-ID
header succeeds silently (no duplicate row). Messages with no Message-ID
header are always inserted (NULLs are distinct in Postgres unique
constraints).

## Mailbox Reader

The **Mailboxes** tab is a two-pane Gmail-style reader over the stored messages:
a left rail (mailbox switcher + filters + search) and a single main pane that
shows either the conversation list or an opened conversation full-width (a back
arrow or Esc returns to the list). It supports threading, read/unread, star, and
search. It is `admin_inbound_email_reader.php`, a vanilla-JS client
(`assets/mailbox_reader.js` + `.css`, cache-busted by file mtime) talking to four
scoped AJAX endpoints. The single-message detail page is kept for raw MIME /
`.eml` download and deep links.

### Mailbox-per-address model

**A mailbox IS an address (alias).** `beth@` and `legal@` are two mailboxes
because they are two aliases — there is no separate container entity. Because the
router stores **one `iem` row per (message, recipient)**, every stored row
belongs to exactly one mailbox via `iem_iea_inbound_email_alias_id`.

### Grants and the switcher

Access is an explicit **grant** of a user to an alias, stored in
`ieg_inbound_email_mailbox_grants` (`InboundEmailMailboxGrant`). One alias can be
granted to several users (a shared team `legal@`); one user can hold several
mailboxes. Grants are managed on the **alias editor** ("Users with access"); on
save the editor calls `InboundEmailMailboxGrant::sync_for_alias($alias_id, $user_ids)`,
which diffs the set (insert added, delete removed). Grants cascade-delete with
either the alias or the user.

The reader's **left rail** is a switcher over the addresses the viewer has been
granted, each independently badged with its unread count. Selecting one scopes
the whole reader to that mailbox; below the switcher are All / Unread / Starred
filters and a debounced search box.

### Threading and shared state

Threading is by `iem_thread_key`, computed at store time by
`InboundEmailRouter::computeThreadKey()` (References first token → In-Reply-To →
own Message-ID → null; a null key is a singleton, keyed client-side as `m:<id>`).
Subject-based grouping for header-less mail is a deliberate non-goal.

Read/star state lives **on the message row** (`iem_is_read`, `iem_is_starred`,
`iem_read_time`) — not in a per-viewer table. On a shared mailbox this means read
state is **shared** among everyone with access (team-inbox semantics: you see
what a colleague already handled). Opening a thread marks it read for everyone on
that mailbox. (Per-person read state would require reintroducing a per-(message,
user) state table — explicitly deferred.)

### The viewer seam

`MailboxViewer` (`includes/MailboxViewer.php`) answers *who is looking and what
may they touch*:

- `accessibleAliasIds()` — for a permission-10 superadmin, **every** alias
  (all-access oversight: every mailbox plus a merged "All mail" view that also
  surfaces unmatched NULL-alias mail); otherwise the aliases the viewer holds a
  grant for.
- `scopeAliasIds(?int $aliasId)` — the **single** place audience becomes a query
  filter: an accessible alias → `[id]`; a null selection → the full accessible
  set; a non-accessible alias → `[]` (matches nothing). The superadmin "All mail"
  unconstrained case is handled in `MailboxService`, gated by `isAllAccess()`.

`MailboxService` funnels **every** read and mutation through the viewer's scope,
so a crafted id/thread/alias for an un-granted mailbox returns nothing and mutates
nothing. `MailboxViewer::forUser($user_id, $permission)` builds a viewer
independent of the session — used by tests and by the deferred member-mount.

### Permissions

The reader, its endpoints, and grant management are **permission-5 (staff)** in
v1; grants partition which mailboxes each staff member sees. Permission-10
superadmins are all-access. **Opening the reader to non-admin members later needs
no schema change or migration** — the grant table, per-row state, and the
viewer/scope seam are keyed on the user generically. It is purely additive code:
relax the endpoint permission gate (currently `get_permission() < 5` in each
`ajax/mailbox_*.php`) and add a member-area mount. `MailboxViewer::canCompose()`
is the seam for a future compose/reply.

### Endpoints

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/ajax/mailbox_mailboxes` | GET | switcher: accessible mailboxes + unread |
| `/ajax/mailbox_list` | GET | thread list (`alias_id`, filters, `page`) |
| `/ajax/mailbox_thread` | GET | messages in a `thread_key` (with bodies) |
| `/ajax/mailbox_action` | POST | mark read/unread, star/unstar, delete — CSRF-protected, accepts `ids[]` or a `thread_key` expanded server-side |

HTML bodies stay sandboxed (`<iframe sandbox="">`, no `allow-scripts`) exactly as
the detail page does — stored mail is fully attacker-controlled.

## Inbound Providers

Inbound mail is **provider-based** and composes with the platform's
outbound `EmailServiceProvider` model. A single provider class may
implement both `EmailServiceProvider` and `InboundEmailProvider`
interfaces — Mailgun is the canonical example.

```
includes/email_providers/MailgunProvider.php
    implements EmailServiceProvider, InboundEmailProvider

includes/email_providers/SmtpProvider.php
    implements EmailServiceProvider       (outbound only)

includes/email_providers/PostfixProvider.php
    implements InboundEmailProvider       (inbound only)
```

One inbound provider is active at a time, selected by the
`inbound_email_provider` setting. All providers feed the same
`InboundEmailRouter::processEmail()`, so delivery modes, dedup, rate
limits, and the Mailbox tab all work identically regardless of which
front door let the message in.

### Shipping providers

- **PostfixProvider** (default, `postfix`) — local Postfix accepts mail
  via MX and pipes it to `utils/inbound_email_handler.php`. The Setup
  tab's Host / Mail-host / per-domain DNS checks come from this provider.
- **MailgunProvider** (`mailgun`) — Mailgun accepts mail via MX and
  POSTs to `ajax/inbound_email_webhook.php?provider=mailgun`. Reuses
  the outbound Mailgun settings (`mailgun_api_key`, `mailgun_domain`,
  `mailgun_eu_api_link`) and adds an inbound-only
  `mailgun_webhook_signing_key`. Configure a Mailgun route with
  `match_recipient(".*@your-domain")` → `forward("https://.../ajax/inbound_email_webhook?provider=mailgun")`,
  set to deliver `body-mime` (raw MIME).

### Adding a provider

Adding inbound support to an existing outbound provider is one diff to
one class — append `, InboundEmailProvider` to its `implements` clause
and add the interface methods (`getInboundSettingsFields()`,
`getSetupChecks()`, `getDnsRecords()`, `isWebhook()`, `handleInbound()`).

Adding a new HTTP-based provider is one new file in
`includes/email_providers/` implementing `InboundEmailProvider`.
`isWebhook()` returns true; `handleInbound()` verifies the request and
returns `['raw_mime' => ..., 'recipient' => ...]`. No router changes, no
Setup-tab changes, no new endpoints — the generic dispatcher handles
routing via `?provider=<key>`.

## Receiving by IMAP poll

Besides the push transports above (Postfix MX→pipe, Mailgun webhook), the platform
can receive mail by **polling an existing mailbox** over IMAP — Gmail, Microsoft
365, Yahoo, iCloud, Fastmail, or any IMAP host. Paired with the generic **SMTP**
outbound provider, this gives a complete **bring-your-own-mailbox** path (SMTP out
+ IMAP in, same account) with no self-hosted MX and no webhook service. It targets
the low-volume user who already has a mailbox and wants the platform to read it.

IMAP feeds are managed from the **Accounts** tree, attached to the mailbox they
fill. They are **additive**: any number run alongside whatever the system's single
push transport is, and adding one never changes that transport. Each feed binds to
an inbound **alias** (the mailbox it populates), so fetched mail lands in
`iem_inbound_email_messages` and appears in the **Mailbox Reader** like any other
stored mail, honoring the same grant model. **No MX/DNS is needed** for an
IMAP-sourced mailbox — the mail is already in the remote mailbox.

A polled mailbox is modeled as a normal `alias@domain`: the address you poll
*is* the mailbox. So a Gmail you read becomes the domain **`gmail.com`** with the
`ied_is_imap_source` flag set (Setup skips MX/DNS for it) and `me@gmail.com` as a
mailbox under it, fed by an IMAP feed. Multiple polled Gmail accounts sit as
sibling mailboxes under the one `gmail.com` domain.

### Per-host matrix — who needs OAuth vs. an app password

| Provider | IMAP host | Auth |
|----------|-----------|------|
| Gmail / Google Workspace | `imap.gmail.com:993` | **OAuth2** (App Passwords retired) |
| Microsoft 365 / Outlook.com | `outlook.office365.com:993` | **OAuth2** (basic auth disabled) |
| Yahoo / AOL | `imap.mail.yahoo.com:993` | app password |
| iCloud | `imap.mail.me.com:993` | app-specific password |
| Fastmail | `imap.fastmail.com:993` | app password |
| Generic IMAP | user-supplied | password |

Connection details are **data, not code**: the `InboundImapAccount::PRESETS`
catalog is the single inventory of every supported host (host/port/encryption/auth
and, for OAuth hosts, the OAuth provider key). Gmail and Microsoft are not special
— they are simply the rows whose auth is `oauth2`. Adding a host is a one-line edit
there. Authentication is a single branch in `ImapIngestor`: `password` LOGIN vs.
`XOAUTH2` with a bearer token. The IMAP library (`horde/imap_client`) is wrapped
entirely behind `ImapIngestor`.

### OAuth accounts (Gmail / Microsoft)

OAuth accounts use the platform's [OAuth2 Core](/docs/oauth2.md) — the IMAP
transport is its first consumer (purpose `inbound_imap`). Register the Google/Azure
app **once** and paste its client id/secret on `/admin/admin_oauth_providers`; that
is documented in the [OAuth2 Core guide](/docs/oauth2.md) and not repeated here.
Then: on an IMAP-source domain, **+ Mailbox** → enter the address as the username →
save → click **Connect** on the mailbox row in the Accounts tree (on a hosted
domain it is **+ IMAP feed** on the mailbox instead). That begins a consent flow through the shared
`/oauth_callback`; on return, `InboundImapOAuthConsumer` stores the granted tokens
(encrypted) on the account. The poller keeps the access token fresh via
`OAuth2Client::ensureFresh`. IMAP-specific scopes requested at consent:

- **Google:** `https://mail.google.com/`
- **Microsoft:** `https://outlook.office365.com/IMAP.AccessAsUser.All offline_access`
  (`offline_access` is required for a refresh token).

The token grants full mailbox **read** access; secrets (IMAP passwords and OAuth
refresh tokens) are stored encrypted at rest with [`SecretBox`](/docs/secret_box.md)
and never logged or echoed.

### The poll cadence

The **PollImapAccounts** scheduled task is the heartbeat. It runs every cron pass
(`every_run`) as a **floor**; each account's own `iia_poll_interval_seconds`
(default 300) is the **actual cadence** — the task self-throttles per account, and
claims each account with an atomic stamp so two runs can't race the same cursor.
**First connect** behaviour is a per-mailbox choice set at creation
(`iia_import_history`):
- **Future only** (default) — the cursor seeds to the folder's current high UID,
  so a 50 GB archive and an empty mailbox behave identically; only mail arriving
  after hookup is ingested.
- **Full history** — the cursor starts at 0 and the mailbox is backfilled
  oldest-first.

Either way each fetch walks **one bounded UID window** (`(cursor+1):(cursor+max_per_account)`,
a numeric `UID FETCH` range — never `SEARCH`, which Gmail's ESEARCH rejects), so a
full-history backfill of a large mailbox imports in batches across successive
fetches rather than one enormous fetch. A UIDVALIDITY change re-seeds per the same
choice. Failures are per-account and non-fatal: one unreachable mailbox or expired
token never stops the rest, and the reason is recorded in the account's last status
(`iia_needs_reauth` is set when a token refresh/auth fails, surfacing a Reconnect).

### Reference-backed storage + the attachment list

IMAP-sourced messages are **reference-backed**, not copied whole. Unlike a pushed
delivery (which is gone after one delivery, so the full raw must be kept), an IMAP
mailbox is a durable remote store. So the poller stores only what the reader shows
— headers + the `text/plain`/`text/html` bodies + an attachment **manifest** — plus
a locator (account + UID + UIDVALIDITY + folder) back to the message, and leaves
`iem_raw_message` empty. A 50 GB Gmail costs the platform kilobytes per message.

Every message view now shows a clickable **attachment list** (filename, size,
type), built from the `ima_inbound_message_attachments` manifest. **Attachment
bytes are never stored on the platform** — clicking one fetches exactly that MIME
part on demand (`FETCH BODY[<section>]`, Message-ID fallback if UIDVALIDITY
changed), decodes it, and streams it pass-through with `Content-Disposition:
attachment` + `X-Content-Type-Options: nosniff`. The download enforces the **same
mailbox-grant check as the reader** — an attachment is exactly as private as its
message. Inline (`cid:`) parts belong to the HTML body and are excluded from the
list. If a part can't be retrieved (message deleted/moved/account disabled), the
endpoint says so honestly. The manifest + endpoint + reader list are
**transport-agnostic**: Postfix/Mailgun mail can adopt the same clickable
attachments later by populating the same table from a MIME parser over their stored
raw — no new schema, no UI change. (The whole-message *.eml* download and raw-source
view have been retired for every transport.)

### Setting up a Gmail account (end to end)

The live connect/fetch path is wrapped behind Horde; unit tests cover the platform
side (model + encryption, reference-backed store + dedup, manifest + grant parity,
poller summary). To connect a real Gmail account:

1. **Google Cloud Console (one-time).** Create/select a project → **OAuth consent
   screen** (External; app name + support email; add scope `https://mail.google.com/`;
   keep status Testing and add the target Gmail as a **Test user**) → **Credentials →
   Create OAuth client ID → Web application**, and under Authorized redirect URIs paste
   the exact value shown on `/admin/admin_oauth_providers` (`https://<host>/oauth_callback`).
   Copy the Client ID + secret. (No need to "enable the Gmail API" — IMAP uses
   `imap.gmail.com` with XOAUTH2; the scope authorizes it.)
2. **Platform credentials.** Paste the Client ID + secret on `/admin/admin_oauth_providers`.
3. **Gmail prep.** In Gmail: Settings → Forwarding and POP/IMAP → **Enable IMAP** → Save.
4. **Accounts tree.** **+ Add Domain** → Type **IMAP — Gmail** (domain `gmail.com` is
   implied; no MX needed) → save. Then **+ Mailbox** on the `gmail.com` row, enter the
   full address as the username (this creates the mailbox and its feed together) → save
   → **Connect** and grant consent as the test user.
5. **Verify.** Click **Test**, then **Fetch now**. The first fetch seeds the cursor to
   "now" and ingests nothing — send a **new** email to the Gmail afterward, **Fetch now**
   again, and confirm it appears under the mailbox in the **Mailboxes** reader; open it
   and download an attachment. For hands-off fetching, activate the **Fetch inbound IMAP
   mail** scheduled task.
