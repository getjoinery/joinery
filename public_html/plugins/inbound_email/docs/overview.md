# Inbound Email

## Overview

The Inbound Email plugin (`/plugins/inbound_email/`) is the platform's
inbound email subsystem — the receiving counterpart to outbound sending
(`SystemMailer`). Its first feature is **forwarding**: admins create aliases
(e.g., `info@example.com`) that forward incoming email to real addresses.

Postfix receives inbound mail, pipes it to a PHP handler, which looks up the
alias and forwards via SMTP.

**Features:** multiple domains, multiple destinations per alias, catch-all addresses, SRS for SPF compatibility, inbound DKIM verification, outbound DKIM signing (opendkim), per-alias and per-domain rate limiting, RBL spam filtering, inbound email logs with admin viewer, live DNS validation.

## Installation

### Prerequisites

Postfix (with the `postfix-pgsql` map driver) and opendkim are installed and
configured by `provisioning/install_email.sh` — run it once per deployment
(see Server Setup below). It assumes one Joinery site per host; that host may
be a Docker container or bare metal.

> **Setup status on the Plugins page.** Once activated, this plugin declares three provisioners, so the admin Plugins page (`/admin/admin_plugins`) reports whether its runtime dependencies are working: a missing inbound mail server shows **Needs setup** with the `provisioning/install_email.sh` fix command; a down or misconfigured outbound relay shows **Needs setup** with the reason; and missing MX/SPF DNS records on any enabled inbound domain show **Needs setup** listing the affected domains. See the "Declaring Host Provisioners" section of `docs/plugin_developer_guide.md`.

### Enabling

1. Activate the plugin in **Admin > System > Plugins**
2. Run **update_database** from admin utilities to create tables and run migrations
3. **Incoming** appears under **Emails** in the admin sidebar — it opens on the
   **Setup** tab

### Guided Setup (recommended)

The **Setup** tab (`Emails > Incoming > Setup`) is a guided checklist and the
recommended way to configure the plugin. Enter the email address you want to
receive mail at; the tab then:

- autodetects the host state — Postfix, the pipe transport, the domain map,
  opendkim, port 25;
- verifies this server's mail identity — `myhostname`, the mail host's A
  record, and forward-confirmed reverse DNS (PTR);
- verifies every per-domain DNS record (MX, SPF, DKIM, DMARC) for
  *correctness*, not just presence — e.g. that the MX target actually resolves
  to this server;
- shows copy-ready DNS records and exact fix commands for anything failing;
- offers one-click actions to enable the plugin and register the domain;
- runs an end-to-end test — send a real message to the address and watch it
  land in the logs.

It cannot create DNS records or set reverse DNS for you (those live with your
registrar / VPS provider) — it detects, instructs, and verifies.

Set the **mail server hostname** on the Setup tab once: the FQDN of this server
(`inbound_email_mail_hostname`), used as the MX target, HELO name, and PTR
name. Everything else is autodetected.

### Adding a Domain

The Setup tab registers a domain for you as part of the guided flow. To manage
domains directly: go to **Emails > Incoming > Domains**, add the domain and
save — Postfix picks it up immediately (the inbound domain list is read live
from the database; no host command, no per-domain Postfix config). Then use
the Setup tab to verify and publish the domain's DNS records.

### Adding an Alias

1. Go to **Emails > Incoming > Forwarding Aliases** tab
2. Click "New Alias", select domain, enter alias name and destinations
3. Save

## Server Setup

On apt-based systems, run `provisioning/install_email.sh` as root, once per
deployment. It installs Postfix, `postfix-pgsql`, and opendkim and applies the
**fixed** base configuration, idempotently:

- the `joinery` pipe transport in `master.cf`;
- `virtual_transport = joinery`, `inet_interfaces = all`, a safe
  `mydestination`, and RBL `smtpd_recipient_restrictions`;
- `virtual_mailbox_domains` wired to a PostgreSQL map (see below) so Postfix
  reads the live inbound-domain list straight from the database;
- opendkim static config — inet socket on `localhost:8891`, empty key/signing
  tables — and the Postfix milter (`milter_default_action = accept`, so a
  keyless or down opendkim never blocks mail).

The only genuinely per-deployment work left is DNS, and per-domain DKIM keys.
Adding or removing an inbound domain needs **no host action** — see below.

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

smtpd_recipient_restrictions =
    permit_mynetworks, reject_unauth_destination,
    reject_rbl_client zen.spamhaus.org,
    reject_rbl_client bl.spamcop.net,
    reject_rbl_client b.barracudacentral.org,
    reject_rhsbl_helo dbl.spamhaus.org,
    reject_rhsbl_sender dbl.spamhaus.org, permit
```

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

### opendkim (DKIM signing)

`install_email.sh` installs opendkim's **static** config (the inet socket,
empty `key.table` / `signing.table` / `trusted.hosts`, and the Postfix milter).
opendkim then runs from first install — keyless, signing nothing — and
`milter_default_action = accept` guarantees a keyless or down opendkim never
blocks or defers mail.

Generating a key is a genuinely **per-domain, manual** step (a key file on disk
plus a DNS record cannot be a database lookup):

```bash
mkdir -p /etc/opendkim/keys/example.com
opendkim-genkey -s mail -d example.com -D /etc/opendkim/keys/example.com
chown opendkim:opendkim /etc/opendkim/keys/example.com/mail.private
```

Then add one line each to `/etc/opendkim/key.table` and
`/etc/opendkim/signing.table` for the domain, reload opendkim, and publish the
public key from `mail.txt` as a DNS TXT record at `mail._domainkey.example.com`.
Forwarding works without a DKIM key; only outbound DKIM signing is affected.

### Firewall

`install_email.sh` runs `ufw allow 25/tcp` when ufw is active. Bare metal or a
container, the site's Postfix owns port 25 on its host.

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
| `inbound_email_forwarding_smtp_host` | (empty) | Optional dedicated SMTP for forwarding (falls back to main) |
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
