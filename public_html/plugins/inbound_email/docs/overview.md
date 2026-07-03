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

> The opendkim.conf the installer writes is keyed on a managed marker. Re-running
> `install_email.sh` re-asserts the managed config — the `inet:8891` socket,
> `Mode sv`, and the `AuthservID` — and realigns Postfix's milter wiring to match,
> so a host whose opendkim config has drifted is brought back into line on the
> next run.

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

Delivery-policy settings — spam filtering, forwarding limits, the forwarded-From
display, and retention/storage caps — are edited on the **Settings** tab. Server
identity and provisioning (provider, mail hostname/IP, SRS, the relay) live on the
**Setup** tab.

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
| `inbound_email_spam_filtering_enabled` | `0` | Act on auth verdicts to assign a spam verdict and split the inbox/Spam view. See [Spam filtering](#spam-filtering). |
| `inbound_email_content_spam_filtering_enabled` | `0` | Read the content scanner's signal (rspamd `X-Spam` header / webhook provider spam flag) into the spam verdict. Requires the master gate above. See [Content scanner](#content-scanner-rspamd). |
| `inbound_email_rspamd_controller_url` | `http://127.0.0.1:11334` | Loopback rspamd controller endpoint the spam/ham feedback loop POSTs learn requests to. No password (loopback-trusted). |

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
(no scripts, no top-nav), and a per-attachment download. Each attachment is a
private `File`, streamed through a single gated endpoint for every transport (see
**Attachment & message storage** below); the whole-message `.eml` is never
reconstructed.

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
`(iem_message_id_header, iem_recipient, iem_direction)`. A retry with the
same Message-ID header succeeds silently (no duplicate row). Direction is
part of the key because mail between two hosted mailboxes legitimately
produces two rows for the same Message-ID and address — the sender's
outbound (Sent) copy and the recipient's inbound copy. Messages with no
Message-ID header are always inserted (NULLs are distinct in Postgres
unique constraints).

## Attachment & message storage

A stored push message is a **lean record**: the database holds the small, searchable
parts — headers, the decoded text bodies (`iem_body_plain` / `iem_body_html`), and the
attachment manifest — while every non-text MIME part (real attachments *and* inline
`cid:` images) is extracted at ingest into its own **private `File`**. The bytes live
in exactly one place — the `File` — so nothing is stored twice, and each attachment
inherits the `File` layer's bucket offload, small-VPS drain, and gated serving for free.
On the happy path **no raw RFC822 is retained**.

**The manifest is the glue.** Each `ima_` row keeps the email-specific MIME metadata
(filename, content-type, size, MIME section, encoding, content-id, inline flag) and, for
file-backed rows, `ima_fil_file_id` pointing at its `File`. Dispatch everywhere keys on
**presence of `ima_fil_file_id`**, not the transport:

| Manifest row | Where the bytes live | Serve / forward |
|--------------|----------------------|-----------------|
| `ima_fil_file_id` set | a private `File` (push mail, lean record) | read the `File` |
| no `ima_fil_file_id`, driver `remote` | the IMAP source | fetch the part on demand (`ImapIngestor::fetchPart`) |
| no `ima_fil_file_id`, stored raw | inside the raw (legacy / fallback row) | `getRawMimePart($section)` |

**Attachment access has two doors, one rule each.** The member download endpoint
(`/profile/inbound_email/attachment`) authorizes by **mailbox grant** for both
backings: the viewer may access the alias of the attachment's message
(`MailboxViewer`; a NULL-alias catch-all message is superadmin-only) — an
attachment is exactly as private as its message, so every grantee of a mailbox,
including permission-0 members and shared-mailbox teammates, downloads its
attachments. The admin endpoint keeps the File-level posture for file-backed
rows: each attachment `File` carries `fil_private`, so `File::is_viewable()`
admits the file's owner (the single grantee of an individual mailbox; a shared
or catch-all alias is owned by `User::USER_SYSTEM`) or any admin (≥ 5) — the
same algorithm serve.php's `/uploads/*` path uses. No image variants are
generated — attachments are served as their original.

**Ingest is all-or-nothing per message.** Every non-text part is minted as a `File` via
`File::createFromBytes()` and linked in the manifest; the text bodies are extracted as
today. If any `File` write fails (disk full — the pressure this design relieves), the
message's Files are rolled back and it **falls back** to persisting the whole raw with a
section-pointer manifest — the raw-storage shape below. The degradation chain is **lean
record → raw-to-disk → inline-in-DB**; ingest never aborts, and the fallback logs a
distinct `INBOUND_ATTACHMENT_EXTRACTION_FAILED` marker so an operator sees disk pressure.

**Download** streams the bytes by where they live (see the table): a file-backed row
reads its `File`; `remote` fetches the one part from IMAP; a legacy/fallback row
extracts it from the stored raw. Retrieval and streaming (original `ima_filename`,
attachment disposition, `nosniff`) are the shared helpers in
`includes/attachment_retrieval.php`, used by both download endpoints — each endpoint
gates first with its own authorization posture, then retrieves. The whole `.eml` is
never reassembled.

**Forward** re-attaches the original's parts in one manifest-driven loop dispatching per
row: a file-backed row reads its `File`, `remote` fetches from IMAP, a legacy raw row
extracts the section. An inline (`cid:`) part is **re-embedded** with its original
Content-ID via `EmailMessage::attachInlineData()` so the forwarded HTML body's `cid:`
references still resolve in the recipient's client; every other part attaches normally.
The message is rebuilt fresh (forwarding re-signs DKIM/SRS), so byte-exact replay was
never on the wire.

### Raw storage (fallback, legacy, and IMAP)

When a message is stored as a raw (the extraction fallback, or a legacy row), the heavy
raw RFC822 lives in the cheapest durable store for its transport, **not** in the
`iem_raw_message` column. A per-row **storage descriptor** (`iem_raw_storage_driver`) says
where, and one accessor resolves it so callers are tier-blind:

| Driver | Where the raw lives | Set by |
|--------|---------------------|--------|
| `inline` | `iem_raw_message` (the column) | lean records (empty), legacy rows, and the local-write-failure fallback |
| `local` | a file under `{site_root}/storage/`, keyed by `iem_raw_storage_key` | the extraction fallback (raw-to-disk) |
| `cloud` | an object in the verified-private store, the **same** key | the shared cloud-offload engine; reversible |
| `remote` | no platform copy — parts fetched on demand from the IMAP source | the IMAP poller (`storeExtracted`) |

`iem_raw_storage_key` is a single **tier-invariant** relative key,
`inbound_email/{yyyy}/{mm}/{message_id}.eml` (received-month shard). The local tier
prepends `{site_root}/storage/`; the cloud tier prepends the shared bucket's
`{site_template}/` prefix automatically — so offload is a flag flip + byte copy with no
key rewrite. `RawMessageStore` (the mail `StorageProfile`) owns the key scheme and the
request-time `write` / `read` / `delete`. `InboundEmailMessage::getRawMessage()` returns
the whole raw and `getRawMimePart($section)` one decoded part, dispatching on the driver
(`inline` reads the column, `local` the file, `cloud` pulls the private object to a unique
temp and unlinks it, `remote` yields null so the caller fetches from IMAP). A transient
cloud outage surfaces a clean "temporarily unavailable", never a fatal.

**The cloud tier is the platform's verified-private store**, reached only through the
shared offload layer's server-side `get()` behind a permission gate — never a public URL
or presigned link. Both attachment `File`s and any fallback raw declare
`visibility = 'private'` and inherit the private store, the privacy gate, the offload
engine, and the admin lifecycle from the cloud-storage layer (see
[Cloud Storage](../../../docs/cloud_storage.md)). Until a private store is configured,
bytes stay `local` on disk forever — the feature degrades cleanly to local-only. Offload
runs through the platform's single `CloudOffloadRun` tick; `RawMessageStore` is declared
under `storage_profiles` in `plugin.json`. Because the plugin owns a `private` profile,
**uninstalling** it requires the mail store drained back to local first (the offload
layer's drain-before-uninstall rule); deactivation alone is safe.

**Durability.** `{site_root}/storage/` is durable runtime data on par with `uploads/`
and `backups/` — it **must** be backed by a persistent Docker volume (`{site}_storage`),
or a container rebuild destroys stored mail. The install layer provisions and persists
it; `upgrade.php` never touches runtime data dirs, so `storage/` survives upgrades. The
directory and the private bucket are **never** web-served. Permanent delete / purge of a
message reclaims its attachment `File`s **and** any stored raw through the message's
hard-delete hook (the single reclaim path); soft delete leaves everything in place (the
row is recoverable).

## Mailbox Reader

The Mailbox Reader is a two-pane Gmail-style reader over the stored messages:
a left rail (mailbox switcher + filters + search) and a single main pane that
shows either the conversation list or an opened conversation full-width (a back
arrow or Esc returns to the list). It supports threading, read/unread, star, and
search. It is a vanilla-JS client (`assets/mailbox_reader.js` + `.css`,
cache-busted by file mtime) talking to five scoped AJAX endpoints.

The reader has **two mounts** of one shared UI
(`includes/mailbox_reader_mount.php`):

- **Admin** — the **Mailboxes** tab (`admin_inbound_email_reader.php`), staff
  chrome, with attachment downloads at the admin endpoint and kebab deep links
  to the single-message detail page (raw MIME / `.eml` download).
- **Member** — `/profile/inbound_email/mailbox`
  (`views/profile/mailbox.php` + `logic/profile_mailbox_logic.php`), theme
  chrome, for any signed-in member; what they see is their granted mailboxes.
  A member with no grants gets a short "no mailboxes are assigned to your
  account" state. Attachment chips point at the member endpoint; there are no
  detail-page deep links (`messageDetailBase` null hides the kebab). The
  plugin's `profileMenu` declares the "Email" entry that puts the page in the
  member menu on every theme and in the apps' navigation.

The mounts differ only in chrome and endpoint URLs (handed to the JS via
`window.MAILBOX_READER`); the endpoints themselves scope every read and write
via `MailboxViewer`.

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
filters and a debounced search box. The search box runs a single PostgreSQL
full-text query (`websearch_to_tsquery`) over the sender, subject, and both
plain and HTML body fields at once, backed by the `iem_fulltext_idx` GIN index
on the matching `to_tsvector` expression. A mailbox whose IMAP feed has discovered
folders also lists them **indented under the selected mailbox** (an "All Mail"
root for the folder-unfiltered view, then each tracked folder); see the Sync
subsection for how membership drives folder contents.

### Threading and shared state

Threading is by `iem_thread_key`, computed at store time by
`InboundEmailRouter::computeThreadKey()` (References first token → In-Reply-To →
own Message-ID → null; a null key is a singleton, keyed client-side as `m:<id>`).
Subject-based grouping for header-less mail is a deliberate non-goal.

Read/star state lives **on the message row** (`iem_is_read`, `iem_is_starred`,
`iem_read_time`) — not in a per-viewer table. On a shared mailbox this means read
state is **shared** among everyone with access (team-inbox semantics: you see
what a colleague already handled). Opening a thread marks it read for everyone on
that mailbox. Read/star state is a property of the mailbox row, shared by everyone
granted access to it.

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
independent of the session.

### Permissions

The endpoints (`ajax/mailbox_*.php`) require a **signed-in session** — any
member — and `MailboxViewer` is the sole authority on which mailboxes a viewer
touches: grants partition mailboxes per user, and permission-10 superadmins are
all-access (every mailbox plus "All mail"/"Unmatched"). The admin page itself
stays permission-5, and grant management (the alias editor) is admin-only.
Reply/Reply-All/Forward are gated by `MailboxViewer::canCompose()` — a grant
means full access to the mailbox, reading it and sending as it, so any viewer
with at least one accessible mailbox may compose; per-alias send scope is
enforced inside `MailboxSender`.

### Endpoints

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/ajax/mailbox_mailboxes` | GET | switcher: accessible mailboxes + unread |
| `/ajax/mailbox_list` | GET | thread list (`alias_id`, filters, `page`) |
| `/ajax/mailbox_thread` | GET | messages in a `thread_key` (with bodies) |
| `/ajax/mailbox_action` | POST | mark read/unread, star/unstar, delete — CSRF-protected, accepts `ids[]` or a `thread_key` expanded server-side |
| `/ajax/mailbox_send` | POST (multipart) | send a reply / reply-all / forward AS the mailbox; stores the sent copy |

HTML bodies stay sandboxed (`<iframe sandbox="">`, no `allow-scripts`) exactly as
the detail page does — stored mail is fully attacker-controlled.

### Reply / Forward

From an open conversation, **Reply**, **Reply All**, and **Forward** compose a
message sent **as that mailbox**, threaded into the conversation, with the sent
copy stored so the thread reads as a back-and-forth dialog (outbound messages are
labelled "Sent").

- **Compose UI.** A single `FormWriter` form is rendered once in the reader
  (hidden) and the reader's JS shows it, populates To/Cc/Subject and the quoted
  context, and submits it by `fetch` so the page never reloads. The form is
  rendered with `csrf => false` (FormWriter's single-use, 2-hour token would
  break a second compose in a long-lived reader); the endpoint validates the
  reader's persistent `mailbox_reader_csrf` token instead, as the other reader
  actions do.
- **Identity & transport.** `mailbox_send.php` resolves the mailbox to a
  transport with `resolveOutboundTransport()` and sends through the one
  `EmailSender` pipeline. An **IMAP-source** mailbox (a connected account) sends
  through the feed's own SMTP as the feed address; a **hosted alias**
  (`alias@our-domain`) sends through the platform's active provider as the alias,
  with the domain's DKIM/SRS. Sending is gated by the same grant that governs
  reading the mailbox.
- **Threading.** Replies set `In-Reply-To`/`References` on the wire from the
  replied-to message; the stored outbound row reuses the conversation's
  `iem_thread_key` (a singleton original is given a real thread key on first
  reply so the two group). A forward starts a fresh external thread (no reply
  headers) but still files into the conversation locally.
- **Forward attachments.** The original's attachments are re-attached: an
  IMAP-source original loads its `ima_` manifest and fetches each part on demand
  (`ImapIngestor::fetchPart`); a hosted original parses `iem_raw_message` with
  `Horde_Mime`. If a reference-backed original is no longer in the source mailbox,
  the forward fails with a clear message rather than sending an empty body.
  User-uploaded attachments ride along in every mode.
- **The stored copy.** Each successful send is persisted as an
  `iem_direction = 'outbound'` row (sender = mailbox address, recipient = the
  To/Cc list, `iem_is_read = true`) so the conversation renders from the local
  row immediately — no poll needed. A failed send stores **no** row and surfaces
  the error inline; the draft stays in the panel to fix and resend.

## API Surface

The mailbox is exposed to API clients (the native mobile mail screens,
`docs/mobile_apps.md`) as five actions under the plugin namespace,
`POST /api/v1/action/inbound_email/{action}`, session-key authenticated:

| Action | Purpose |
|---|---|
| `mailboxes` | The viewer's granted mailboxes with unread/total counts and folder rails, plus `can_compose` |
| `thread_list` | Paged threads for a mailbox view — params `alias_id`, `q`, `unread_only`, `starred_only`, `spam`, `inbox`, `folder_id`, `page`; same row shapes as the web reader's list endpoint |
| `thread` | One full thread: messages with plain/HTML bodies, attachment manifest, and the thread's folder ids |
| `thread_action` | The reader's full mutation set: `mark_read`/`mark_unread`, `star`/`unstar`, `archive`/`unarchive`, `delete`, `mark_spam`/`mark_not_spam`, `set_membership`, `create_folder` — targets `ids[]` or a `thread_key` |
| `send` | Reply / reply-all / forward as the mailbox (JSON transport — no uploads; forwards re-attach the original's parts server-side) |

Each action is a `logic/{action}_logic.php` with an `_logic_api()` opt-in that
builds a `MailboxViewer` for the key's user and goes through
`MailboxService` / `MailboxSender` — the same shared brain the web AJAX
endpoints wrap, so scoping, threading, view semantics, and send side effects
live in exactly one place. There is no authorization logic in the actions
themselves: viewer scope is the single authority, and out-of-scope ids
silently affect nothing (same guarantee as the AJAX layer).

**Signed URL transport.** Sessionless clients can't fetch attachments with
web cookies, so the `thread` action enriches its payload via
`MailboxService::withSignedTransport()`: every file-backed attachment carries
a short-lived signed download URL (`docs/file_signed_urls.md`), and each HTML
body has its inline `cid:` references rewritten to signed URLs for that
message's inline file-backed parts. Minting happens only after the
viewer-scope check that gated the thread fetch; the serving path validates
signature + expiry with no session at all. Attachments whose bytes are not a
private File (IMAP on-demand / raw-section parts) carry `url: null` and
stream only through the sessioned member endpoint.

**The app route flip.** The plugin's `profileMenu` entry declares
`"nativeScreen": "mailbox"`, so the app navigation endpoint serves the Email
entry as `{type: "native", screen: "mailbox", fallback_url:
"/profile/inbound_email/mailbox"}` — clients with the native mail module
render these actions' screens; older builds keep loading the web reader.

## Spam filtering

Spam is a **first-class verdict on the message**, `iem_spam_verdict` (`ham` /
`spam`; NULL = not evaluated). It is what the reader filters on, so one Spam view
works identically for locally-received mail and IMAP-polled mailboxes. There is no
folder membership — the verdict is the disposition. The app runs **no scorer of its
own**: it acts on the auth verdicts and on a binary spam result a content scanner (or
the webhook provider) decides, recording the scanner's numeric score only for display.
The one exception is SendGrid, which exposes a score but no binary, so its result is
derived from a configurable threshold (see [Content scanner](#content-scanner-rspamd)).

Three protection layers stack: the MTA's RBLs at RCPT time, the auth rule below
(DMARC/SPF/DKIM), and a content scanner — the only layer that catches **authenticated
bulk spam** (junk that passes its own DMARC/SPF/DKIM: lookalike domains, bulk mail
from real ESPs, a compromised aligned account). All three feed the same
`iem_spam_verdict`.

Gated by `inbound_email_spam_filtering_enabled` (default off), toggled on the
**Settings** tab. When off, the verdict stays NULL and nothing changes.

**Classification rule.** The router acts on the SPF/DKIM/DMARC verdicts it already
records (it never computes them — see [Inbound authentication](#inbound-authentication-spf--dkim--dmarc)).
`InboundEmailRouter::classifySpam()`:

- **DMARC `fail` → `spam`.** The primary rule. DMARC is alignment-based and already
  subsumes SPF and DKIM, so it is the one signal worth acting on directly. Applies
  wherever a DMARC verdict exists (Postfix milters, SES).
- **No DMARC verdict, and SPF *and* DKIM both `fail` → `spam`.** The fallback for
  providers that supply SPF/DKIM but no DMARC field (Mailgun, SendGrid). Both must
  fail: raw SPF/DKIM lack DMARC's alignment check, so a single failure has too many
  legitimate causes (forwarding breaks SPF; some legit mail breaks DKIM), whereas
  both failing is a clean "even basic auth broke" signal.
- otherwise **`ham`**.

The rule is intentionally strict because the disposition is reviewable, never
rejection: a false positive costs a click in the Spam view, not a lost message.

**Forward suppression.** A judged-`spam` message is **never relayed** — forwarding
spam burns the platform's sending reputation and can relay abuse. The forward is
suppressed and logged with status `spam_held`. A `forward_and_store` alias still
stores the message (with its `spam` verdict) so it stays reviewable; only the
outbound forward is dropped. Pure-store and catch-all-store aliases store as usual,
verdict and all.

**IMAP-polled mail.** No auth rule runs — the remote server already classified it.
A message ingested into a folder whose `iif_role` is `junk` is marked
`iem_spam_verdict = 'spam'`, giving the Spam view the same meaning for polled mail.

**Reader.** The default inbox (and the mailbox unread badges) exclude `spam`-verdict
rows; a **Spam** entry in the per-mailbox folder rail shows only them. Per
conversation, **Mark as spam** (inbox) / **Not spam** (Spam view) set the verdict
directly.

### Content scanner (rspamd)

The content layer is a second verdict source **OR'd** into `classifySpam()`: a message
is `spam` if the content scanner flagged it **or** the auth rule fires. It changes no
downstream behavior — same `iem_spam_verdict`, same Spam view, same forward
suppression. The signal is resolved per ingest path:

- **Postfix path.** rspamd runs as a Postfix milter *after* opendkim + opendmarc (so it
  scores on the auth results), in header-stamping mode only (**never** rejects —
  consistent with the reviewable-verdict model). It stamps `X-Spam: Yes` on a spam
  verdict (plus `X-Spam-Status` carrying the score); `InboundEmailRouter::readSpamHeader()`
  reads that header, trusting it on the same basis as the `Authentication-Results` line
  (the milter is ours, and rspamd strips any inbound-forged `X-Spam` before re-stamping).
- **Webhook providers.** Mailgun, SendGrid and SES supply their own content/reputation
  spam signal in the authenticated payload; each provider's `handleInbound()` surfaces it
  as a `spam` key, carried into the router as a sibling of the auth verdicts. SES's
  `spamVerdict` and Mailgun's `X-Mailgun-Sflag` are binary verdicts. SendGrid posts only a
  numeric `spam_score` (its SpamAssassin score, no yes/no), so the binary is derived by
  comparing it to `sendgrid_inbound_spam_threshold` (default `5.0`, SpamAssassin's own
  `required_score`; tunable on the Setup tab). The raw score is recorded either way.
- **IMAP-polled mail.** Unchanged — the remote already classified it (junk-folder mapping).

Gated by `inbound_email_content_spam_filtering_enabled` (default off), which **requires
the master `inbound_email_spam_filtering_enabled`** to be on — content filtering is a
source feeding the same disposition. With the content gate off the milter may still stamp
headers (harmless); the router ignores them.

**Recorded score.** `iem_spam_score` (nullable) holds the scanner's/provider's numeric
score as reported, for display and tuning only — **nothing in PHP ever branches on it**.
The reader shows it on the message detail when present.

**Provisioning (Postfix path only).** `provisioning/install_email.sh` installs `rspamd`
and its `redis-server` dependency when the content gate is on, wires the milter on
`inet:localhost:11332` after opendkim/opendmarc, pins the `X-Spam` header contract, puts
the Bayes classifier on redis, and exposes the rspamd **controller** on loopback
`127.0.0.1:11334` (trusted via `secure_ip` — **no password**, since a privileged learn
command is authorized by originating inside the container). The
`content_spam_scanner` provisioner (`InboundEmailHealth::checkContentSpamScanner`) probes
the milter port. Webhook deployments install none of this — the provider scans upstream.
rspamd queries DNS RBLs while scanning, so the host needs outbound DNS egress.

**redis is disposable.** The Bayes corpus lives in redis (the container's writable layer)
and a recreate/rebuild wipes it. That is acceptable: the **durable** signal is
`iem_spam_verdict` in Postgres, and the corpus self-heals from ongoing corrections after a
wipe — the failure degrades to "the filter is temporarily less sharp," never "training
data lost." A redis volume mount is an optional deploy-layer optimization, never a
correctness requirement.

**Spam/ham feedback (Bayes training).** A reader correction (**Mark as spam** / **Not
spam**) is the whole trigger — there is no separate "report" control. Flipping
`iem_spam_verdict` leaves the row *diverged* from `iem_learned_verdict` (the marker of
what was last taught). The **`LearnSpamFeedback`** scheduled task (every cron pass, gated
on the content setting) reconciles the divergence out-of-band: for each diverged row it
POSTs the raw RFC822 to the controller's `/learnspam` | `/learnham` over loopback and, on
success, stamps `iem_learned_verdict = iem_spam_verdict` so the row stops re-selecting.
Flip-backs and idempotency fall out for free. Webhook-sourced rows and rows whose raw is
gone (pruned, or IMAP reference-backed) are marked handled as permanent no-ops; a
controller outage leaves rows diverged to retry on the next pass, so the loop self-heals
rather than stranding corrections. (rspamd's classifier needs roughly 200 messages of each
class before it contributes, so early corrections have little visible effect.)

## Filters

Operator-defined rules that match incoming mail and apply actions to it
automatically — the inbound-email equivalent of Gmail's *Filters and Blocked
Addresses*. Managed under the **Filters** admin tab (between Accounts and Logs),
**one mailbox at a time**: a mailbox picker scopes the list, and *Create filter*
is pre-scoped to the picked mailbox. The picker also offers each domain's *All
mailboxes in `<domain>`* bucket for managing domain-wide rules. It lists **only
mailboxes where filters can actually fire** — those that store locally-received
mail (delivery mode store / forward-and-store, and not IMAP-backed); IMAP-polled
and pure-forward mailboxes are omitted because the filter hook never runs for them.

**Scope.** Filters run on **locally-received** mail only — the Postfix milter path
and the provider-webhook path, both of which funnel through
`InboundEmailRouter::storeMessage()`. They do **not** run on IMAP-polled feeds: an
IMAP feed mirrors an upstream account that already applies its own filters, and the
reader's two-way sync treats the remote as the source of truth for flag/label
state. Because `storeMessage` is the single local-only path, the ingest hook there
covers the Postfix and webhook paths identically with no per-path branch, and never
touches IMAP mail.

**A filter has two parts** (Gmail's split):

- **Criteria** — From, To, Subject, *Has the words*, *Doesn't have*, Size
  (greater/less than a value + unit), and *Has attachment*. From/To accept
  comma-separated terms (any one matches); *Has the words* requires every word, and
  *Doesn't have* excludes any. A filter matches when **all** non-empty criteria
  match. At least one criterion is required.
- **Actions** — apply a label, star, mark read, *Skip the Inbox* (archive), mark as
  spam, never send to spam, forward to an address, delete.

**Scope of a rule.** A filter belongs to a mailbox, or to *all mailboxes in a
domain* (a domain-wide rule). A label is a custom label (an `ilb_inbound_email_labels`
row) with a single global namespace rather than belonging to one mailbox — every scope,
domain-wide rules included, can apply a label. The *Apply the label* dropdown lists
the existing labels and offers **Create new label…** to mint one inline.

**Engine.** The match and action logic lives on the `InboundEmailFilter` model.
`runForMessage()` loads every in-scope enabled filter (the mailbox's own plus the
domain-wide ones), runs `matches()`, accumulates the actions of all that match, and
applies them once in a fixed order so multi-filter interactions are well-defined:
*never-spam → mark-spam → label/star/read/archive → forward → delete*. An explicit
*never send to spam* always beats *mark as spam*. It runs at ingest **after** the
spam verdict is set, so a filter is the last word on disposition; it writes the state
columns and label memberships directly (system authority), reusing the same primitives
the reader uses. A forward action relays a copy through the same path alias-forwarding
uses; a delete soft-deletes the stored copy last, so a forwarded copy still went out.

**Archive ("Skip the Inbox").** The reader's default mailbox view is the **Inbox**
(non-archived, non-spam, non-deleted); an **All Mail** rail entry shows everything,
archived included. The open-thread toolbar offers **Archive** in the Inbox and **Move
to Inbox** in All Mail — the manual counterpart to the filter's archive action.

**Apply to existing.** A filter saved with *Also apply to matching existing mail*
sets a pending flag drained by the `ApplyInboundEmailFilters` scheduled task, which
pages through that mailbox's locally-received, non-deleted history in bounded
batches and applies the same matcher and actions (forwarding is never re-applied to
historical mail), resuming across runs via a per-filter cursor.

**Logging.** Each ingest that matches at least one filter writes a `filtered` line to
the inbound transaction log (the **Logs** tab) recording the matched filter ids and
the actions taken.

**Importing from Gmail.** The Filters list has an **Import filters** button that
ingests Gmail's `mailFilters.xml` export (*Gmail → Settings → Filters and Blocked
Addresses → Export*) into the picked mailbox. The operator uploads the file and sees a
**preview** — one row per Gmail filter with its synthesized name, mapped criteria,
mapped actions, and a *Skipped* column for anything that has no platform equivalent —
then confirms to create the checked rows. An imported filter is an ordinary
`InboundEmailFilter`; import adds no new behavior.

- **Criteria** map directly: `from`, `to`, `subject`, `hasTheWord` → *Has the words*,
  `doesNotHaveTheWord` → *Doesn't have*, `hasAttachment`, and `size`.
- **Actions** map directly too: archive, mark-read, star, trash → delete, never-spam,
  and forward. Gmail's `label` action **find-or-creates a custom label** by name (a
  nested `Parent/Child` name is kept verbatim); new labels are created on confirm and
  their count is shown in the summary.
- **Skipped:** importance (`shouldAlwaysMarkAsImportant` / `shouldNeverMarkAsImportant`),
  categories (`smartLabelToApply`), and chat exclusion have no platform concept and are
  dropped visibly, listed per row in the preview.
- **The size default caveat:** Gmail emits a default `sizeOperator`/`sizeUnit` on every
  exported filter even when no size is set, so a size criterion is imported **only when
  a `size` value is actually present** — otherwise every filter would gain a bogus
  "size < 0 MB" rule.

An entry is importable only when it has at least one criterion **and** at least one
action (a label counts). Re-importing the same file is safe: a candidate whose criteria,
actions, and resolved label already exist in the scope is skipped and reported as
*already present*.

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

> **One account, both directions.** The same connected account also powers
> **outbound**: selecting the **Connected Email Account** provider sends all site
> mail through this account's SMTP (Gmail/M365 via XOAUTH2, app-password hosts via
> SMTP AUTH), reusing the same stored grant and `iia_needs_reauth` health flag — one
> Reconnect fixes both inbound and outbound. The connect flow requests both the IMAP
> read scope and the SMTP send scope, so connecting once enables both directions. See
> [Email System → Two send modes](../../../docs/email_system.md#two-send-modes--smtpconfig).

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

Every message view shows a clickable **attachment list** (filename, size,
type), built from the `ima_inbound_message_attachments` manifest. For **IMAP
(`remote`) mail the bytes stay on the server** — clicking one fetches exactly that
MIME part on demand (`FETCH BODY[<section>]`, Message-ID fallback if UIDVALIDITY
changed), decodes it, and streams it pass-through with `Content-Disposition:
attachment` + `X-Content-Type-Options: nosniff`. For **push (Postfix/Mailgun) mail
the part is a private `File`** streamed the same way. Inline (`cid:`) parts belong to
the HTML body and are excluded from the list. If a part can't be retrieved (message
deleted/moved/account disabled), the endpoint says so honestly. The manifest +
endpoint + reader list are **transport-agnostic**: the download dispatches on where
the bytes live — a `File` for push mail, an IMAP fetch for `remote` mail, a raw
section for a legacy/fallback row (see **Attachment & message storage**) — through the
same endpoint, same table, same UI. The whole-message *.eml* download and raw-source
view do not exist for any transport.

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

### Sync (read-only and two-way)

Each IMAP feed has a **Sync** mode, set per feed on the mailbox editor and **off by
default**:

- **Off** — one-time import. The source is never written to and local read/star/
  delete state stays in Joinery.
- **Read-only** — Joinery *follows* the source: a read/star/move/delete made in the
  native client is reflected in Joinery; Joinery never writes back.
- **Two-way** — full reconciliation: acting in either place is reflected in the other.

Read-only and Two-way require the server to advertise **CONDSTORE** (incremental
flag/membership pull via `CHANGEDSINCE`). Detecting messages that *left* a folder
uses **QRESYNC**'s `VANISHED` when the server also has it; on a CONDSTORE-only server
(notably **Gmail**, which has CONDSTORE but not QRESYNC) it falls back to diffing the
folder's current UID set against the stored membership UIDs — same result, a little
more bandwidth. A server without CONDSTORE offers only Off. Capabilities are detected
on connect/**Test** and cached on the feed. No OAuth re-consent is needed — the
granted IMAP scope already permits the `STORE`/`COPY`/`MOVE`/`APPEND`/`EXPUNGE`
writes. **Gmail is reconciled by the same folder model as every other host** — no
Gmail IMAP extensions are used.

**State mapped to IMAP.** Read ↔ `\Seen`, star ↔ `\Flagged`, custom label ↔ the
remote folder that mirrors it, deletion ↔ move to Trash. Standard state (read, star,
spam, archive, deletion) is a column on the message; only **custom** labels are
folder memberships.

**Custom labels are rows; folders are bindings.** A custom label is an
`ilb_inbound_email_labels` row, and a message *has* it iff it carries an
`ilm_inbound_label_members` row with `ilm_present_local` — the same truth for
locally-received and IMAP mail. An IMAP folder (`iif_inbound_imap_folders`) is a
**binding** that mirrors one label to a remote folder on one feed
(`iif_ilb_inbound_email_label_id`). Special-use folders (Inbox, Sent, Trash, Junk) and
the `\All` coverage view bind no label — their state is a message column, not a label.
The membership row is also the IMAP **shadow**: `ilm_present_base` records whether the
message was in the bound folder at the last sync, alongside the folder UID, so truth
and shadow share one row. Adding a label is a `COPY` (a Gmail label add) on a
multi-folder host or a `MOVE` on a classic one-folder host; removing is `STORE
\Deleted` + `EXPUNGE`; deleting is a `MOVE`/`COPY` to Trash. Operators pick which
folders are tracked on the mailbox editor; special-use folders are pre-selected.

**Changing labels from the reader.** The open-thread toolbar has a **Move ▾**
(exclusive feeds) / **Labels ▾** (non-exclusive feeds, e.g. Gmail) control: pick a
folder to relocate the thread, or toggle label checkboxes. Each change applies or
removes the custom-label membership (`MailboxService::setMembership`, via the
`set_membership` action); when the label is bound to a Two-way feed the next sync
pushes the change to the source, and an unbound (local) label is pure membership that
never touches a remote.

**Creating a label/folder.** The same control has a **New label… / New folder…**
field. Creating one makes an `ilb_` label; on a mailbox with an IMAP feed it also makes
a tracked binding flagged `iif_pending_remote_create` (the remote folder does not
exist yet) and files the thread into it. The folder is materialized on the source
**during the sync push** — `ImapSyncer` issues the IMAP `CREATE`, clears the pending
flag, then `COPY`s the message in; pull/ingest skip a pending folder until it exists.
Creation is idempotent (a folder that already exists is adopted). Conversely, a label
created on the *source* is discovered each sync as an untracked folder — tick it on
the mailbox editor to start syncing it.

**The `\All` coverage view (Gmail All Mail).** An all-mail folder is tracked as a
**coverage source**, not a navigable label: it ingests every message — including
mail archived with no label — so nothing is missed, but it carries no label. In the
reader, the **mailbox root is the label-unfiltered “All Mail” view**, so messages with
no label are reachable there; the labels listed beneath it narrow to one label.

**Reconciliation.** Each cycle runs **Pull → Ingest → Push** on one connection.
Flags are a three-way merge keyed on a per-row dirty signal (a local change since the
last push wins over an incoming remote change). Custom-label membership is reconciled
through the single `ilm_` row: an element is dirty when `ilm_present_local` differs
from `ilm_present_base` — a column predicate a partial index covers, so the push scans
only the dirty rows. Each (message, label) bit is a conflict-free boolean merge; on a
one-folder host a divergent move converges to the local destination within two cycles
with no explicit tiebreak. A pushed change is re-read next cycle as a value-equal
no-op, so nothing loops.

**Deletion** (a separate **“Also sync deletions”** toggle): driven by the
`iem_delete_time` column, not a label. A local delete moves/copies the source message
to Trash (the locator follows, so it is never re-pushed); a message arriving in Trash
on the source soft-deletes the local row at ingest. Archiving (the `iem_is_archived`
column) is distinct from deletion and stays local.

**Compose / Sent** (the **“Enable compose / Sent sync”** toggle, with the reader’s
reply/forward feature): the source Sent folder is ingested like any tracked folder,
so mail sent from the native client appears in Joinery. When a feed’s SMTP does not
auto-file sent mail (self-hosted / generic), Joinery `APPEND`s the sent copy to the
source Sent folder itself. Sent dedup is **by Message-ID only**: a provider that
preserves the Message-ID reconciles the filed copy to the locally-stored sent row,
while a provider that rewrites it on send (Gmail) stores no local row — the message
appears on the next Sent ingest (one poll-interval later).
