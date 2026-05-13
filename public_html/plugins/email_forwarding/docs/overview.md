# Email Forwarding

## Overview

The Email Forwarding plugin (`/plugins/email_forwarding/`) provides self-hosted email forwarding. Admins create aliases (e.g., `info@example.com`) that forward incoming email to real addresses.

Postfix receives inbound mail, pipes it to a PHP script, which looks up the alias and forwards via SMTP.

**Features:** multiple domains, multiple destinations per alias, catch-all addresses, SRS for SPF compatibility, inbound DKIM verification, outbound DKIM signing (opendkim), per-alias and per-domain rate limiting, RBL spam filtering, forwarding logs with admin viewer, live DNS validation.

## Installation

### Prerequisites

Postfix and opendkim are installed automatically by `install.sh server`. For Docker, Postfix must also run on the host (see Docker setup below).

### Enabling

1. Activate the plugin in **Admin > System > Plugins**
2. Run **update_database** from admin utilities to create tables and run migrations
3. Set `email_forwarding_enabled` to `1` in **Admin > Settings > Email**
4. Set `email_forwarding_srs_secret` to a random string, then enable SRS
5. **Incoming** appears under **Emails** in the admin sidebar

### Adding a Domain

1. Go to **Emails > Incoming > Domains** tab
2. Add the domain and save
3. Configure Postfix and DNS per the instructions displayed on the page
4. Check the DNS validation badges turn green

### Adding an Alias

1. Go to **Emails > Incoming > Forwarding Aliases** tab
2. Click "New Alias", select domain, enter alias name and destinations
3. Save

## Server Setup

### DNS (per domain)

```
@                 MX   10  mail.yourserver.com.
@                 TXT  "v=spf1 ip4:YOUR_SERVER_IP -all"
mail._domainkey   TXT  "v=DKIM1; k=rsa; p=YOUR_PUBLIC_KEY"
```

### Bare-Metal Postfix

Add to `/etc/postfix/main.cf`:

```
virtual_transport = joinery
virtual_mailbox_domains = example.com

smtpd_recipient_restrictions =
    permit_mynetworks, reject_unauth_destination,
    reject_rbl_client zen.spamhaus.org,
    reject_rbl_client bl.spamcop.net,
    reject_rbl_client b.barracudacentral.org,
    reject_rhsbl_helo dbl.spamhaus.org,
    reject_rhsbl_sender dbl.spamhaus.org, permit
```

Add to `/etc/postfix/master.cf`:

```
joinery   unix  -  n  n  -  5  pipe
  flags=DRhu user=www-data
  argv=/usr/bin/php /var/www/html/SITENAME/public_html/plugins/email_forwarding/scripts/email_forwarder.php ${recipient}
```

### Docker Multi-Container

Host Postfix receives mail on port 25 and routes to containers by domain:

```
# Host /etc/postfix/main.cf
relay_domains = example.com, other.com
transport_maps = hash:/etc/postfix/transport

# Host /etc/postfix/transport
example.com    smtp:[127.0.0.1]:2525
other.com      smtp:[127.0.0.1]:2526
```

After editing: `postmap /etc/postfix/transport && postfix reload`

Each container maps internal port 25 to a unique host port. Container Postfix config:

```
mynetworks = 127.0.0.0/8 172.16.0.0/12 10.0.0.0/8
virtual_transport = joinery
virtual_mailbox_domains = example.com
inet_interfaces = all
```

RBL checks happen on the host only.

### opendkim (DKIM Signing)

```bash
mkdir -p /etc/opendkim/keys/example.com
opendkim-genkey -s mail -d example.com -D /etc/opendkim/keys/example.com
chown opendkim:opendkim /etc/opendkim/keys/example.com/mail.private
```

Configure `/etc/opendkim.conf`, `key.table`, `signing.table`, and `trusted.hosts` per domain. Add the milter to Postfix:

```
milter_default_action = accept
smtpd_milters = inet:localhost:8891
non_smtpd_milters = inet:localhost:8891
```

Publish the public key from `mail.txt` as a DNS TXT record at `mail._domainkey.example.com`.

### Firewall

```bash
ufw allow 25              # Bare-metal
ufw allow 2525:2550/tcp   # Docker host relay ports
```

## Settings

| Setting | Default | Description |
|---|---|---|
| `email_forwarding_enabled` | `0` | Master switch |
| `email_forwarding_srs_enabled` | `0` | SRS envelope rewriting (recommended) |
| `email_forwarding_srs_secret` | (empty) | Required before SRS can be enabled |
| `email_forwarding_max_destinations` | `10` | Max destinations per alias |
| `email_forwarding_rate_limit_per_alias` | `50` | Per-alias limit per window |
| `email_forwarding_rate_limit_per_domain` | `200` | Per-domain limit per window |
| `email_forwarding_rate_limit_window` | `3600` | Rate limit window (seconds) |
| `email_forwarding_log_retention_days` | `30` | Log cleanup threshold |
| `email_forwarding_smtp_host` | (empty) | Optional dedicated SMTP for forwarding (falls back to main) |
| `email_forwarding_smtp_port` | (empty) | Falls back to `smtp_port` |
| `email_forwarding_smtp_username` | (empty) | Falls back to `smtp_username` |
| `email_forwarding_smtp_password` | (empty) | Falls back to `smtp_password` |

## Plugin Structure

```
/plugins/email_forwarding/
├── plugin.json, uninstall.php
├── data/          — Domain, Alias, Log models (auto-create tables)
├── includes/      — EmailForwarder (processing), SRSRewriter
├── scripts/       — Postfix pipe script (email_forwarder.php)
├── admin/         — Admin pages (aliases, alias edit, domains, logs)
├── logic/         — Logic files for admin pages
├── tasks/         — PurgeOldForwardingLogs scheduled task
└── migrations/    — Settings and menu entry
```

**Tables:** `efd_email_forwarding_domains`, `efa_email_forwarding_aliases`, `efl_email_forwarding_logs`

**How forwarded emails appear to recipients:**
- **From:** `"Original Sender via Site Name" <info@your-verified-domain.com>` — uses the site's verified sending address for deliverability
- **Reply-To:** `original-sender@their-domain.com` — hitting Reply goes to the right person
- **Subject:** Preserved from the original email

This approach is required because SMTP services (Mailgun, SendGrid, etc.) require the From address to be on a verified domain. Sending with an arbitrary external From would be silently dropped.

## Testing

Test without Postfix by piping raw email to the script:

```bash
echo "From: alice@gmail.com
To: info@example.com
Subject: Test

Hello" | php plugins/email_forwarding/scripts/email_forwarder.php info@example.com
echo $?   # 0 = success, 67 = unknown alias, 75 = temp failure
```

## Troubleshooting

**Email not arriving:** Check forwarding logs (Incoming > Logs tab), verify alias and domain are enabled, check SMTP settings, check `error.log`.

**Email not reaching Postfix:** Verify MX records (`dig MX domain`), port 25 open, Postfix running, domain in `virtual_mailbox_domains`.

**"User unknown in local recipient table":** The domain is in Postfix's `mydestination` setting, which takes priority over `virtual_mailbox_domains`. The admin domain edit page detects this conflict and shows a red "Conflict" badge. Run the setup script to fix — it sets `mydestination = localhost, localhost.localdomain`.

**Landing in spam:** Enable SRS, verify opendkim running and DKIM DNS record published, check SPF includes server IP, verify rDNS/PTR record, check IP at mxtoolbox.com.
