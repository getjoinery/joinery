# Inbound Email — Guided Setup & Verification

## Overview

The Inbound Email plugin can receive and forward mail, but getting a domain to
that point means wiring up host software, DNS records, reverse DNS, and plugin
config — and today the admin UI only *partially* checks that work, and checks
*existence* rather than *correctness* (see "Current state" below).

This spec defines a **guided setup**: a single Setup tab that autodetects
everything detectable, verifies every record for correctness (not just
presence), and walks the admin through the rest with copy-ready values and
exact fix commands. The goal is "Cadillac" hand-holding — an admin who knows
the email address they want, can edit their DNS zone, and can find their
server's IP should be able to finish setup with no outside knowledge.

This spec assumes the `inbound_email` rename (`inbound_email_rename.md`) is
already done. It is independent of `inbound_email_local_mailbox.md`.

### What the plugin can and cannot do

The plugin's role is **detect → instruct → verify**, never "do it for you" for
anything outside its own host:

- It **cannot** create DNS records or set reverse DNS — those live in the
  admin's registrar / DNS host / VPS control panel. It can only state exactly
  what to create and then confirm it.
- It **can** detect host state, derive every record's correct value, and
  verify each one with forward and reverse DNS lookups.
- It **can** run host fixes that are already scripted (`install_email.sh`),
  by telling the admin the exact root command.

### Current state — gap analysis

Detection today lives ad hoc in `admin/admin_inbound_email_domains.php` and
`includes/InboundEmailHealth.php`. What works: Postfix / transport / domain-map
/ opendkim host checks, the `mydestination` conflict check, and an SPF check
that confirms the server IP is present. What is missing or shallow:

1. **MX check is existence-only** — it never verifies the MX target resolves
   to *this* server's IP. A domain pointed at the wrong mail host shows green.
2. **No A-record check** for the MX target hostname.
3. **No reverse DNS / PTR check** of any kind.
4. **No `myhostname` consistency check** (HELO name vs A record vs PTR).
5. **DKIM check is existence-only** — a published `v=DKIM1` record is never
   compared against the local key, so a stale record shows green.
6. **Server IP via external HTTP** (`file_get_contents('https://api.ipify.org')`)
   on every page load; on failure it prints the literal `YOUR_SERVER_IP`.
7. **Inconsistent DNS paths** — the domain edit page uses `DnsResolver`; the
   domain list rows use raw `dns_get_record()`.

This spec closes all seven.

## Design principles

1. **Verify correctness, not existence.** Every check confirms the record
   carries the *right value*, not merely that something is published.
2. **One verification engine.** All detection moves into a single
   `InboundEmailSetupCheck` class. The Setup tab, the Domains page, and the
   `InboundEmailHealth` provisioner checks all consume it — no second copy.
3. **No external HTTP for detection.** The server IP is found locally.
4. **Fail-open on resolver errors.** A transient DNS failure yields an
   `unknown` status (distinct from `fail`), never a false negative.
5. **One genuine deployment input.** Everything is autodetected or derived
   except the *mail-server hostname*, which the wizard proposes and the admin
   confirms once.

## The one deployment input: mail-server hostname

A mail server needs a stable FQDN of its own (the name its MX records point
at, its HELO name, and its PTR target) — separate from the mail *domains* it
serves and from any website hostname.

A new setting **`inbound_email_mail_hostname`** stores it (e.g.
`mail.example.com`). The Setup wizard:

- proposes a default — the current Postfix `myhostname` if it is already a
  FQDN, otherwise `mail.<first configured domain>`;
- lets the admin confirm or edit it;
- treats it as the canonical mail-host name for every downstream check (MX
  target, A record, PTR expected value, `myhostname` expected value).

## New: `InboundEmailSetupCheck` — the verification engine

`includes/InboundEmailSetupCheck.php` — a class that runs all checks and
returns a structured result. Side-effect-free and time-bounded (every DNS
lookup goes through `DnsResolver`; every host probe is a bounded `exec`).

### Result shape

`run()` returns an ordered list of check results, each:

| Field | Meaning |
|-------|---------|
| `id` | Stable identifier, e.g. `domain.mx_points_here` |
| `layer` | `host` \| `mailhost` \| `domain` \| `plugin` \| `e2e` |
| `label` | Human-readable name |
| `severity` | `required` \| `recommended` |
| `status` | `pass` \| `fail` \| `warn` \| `unknown` |
| `summary` | One-line current state |
| `detail` | What was actually found (the wrong value, the resolver error, …) |
| `fix` | Structured fix: free text plus optional `command` (copy-ready shell) and/or `dns_record` (type, name, value) |
| `recheckable` | Whether a per-item "Re-check" makes sense |

`status` semantics: `pass` = verified correct; `fail` = verified wrong/missing
and `required`; `warn` = a `recommended` item missing, or a soft issue;
`unknown` = could not determine (resolver error, command unavailable) — shown
distinctly, never counted as failure.

### Scoping

`run(?string $domain = null, ?string $address = null)` — host/mailhost/plugin
checks are global; domain checks run for `$domain` (or every enabled domain if
null); the e2e check is scoped to `$address` when the wizard supplies one.

## The check catalogue

### Host layer

| id | Verifies | How | Fix |
|----|----------|-----|-----|
| `host.postfix` | Postfix installed and running | `which postfix`, `pgrep -x master` | `sudo bash install_email.sh` |
| `host.transport` | `joinery` pipe transport in `master.cf` | `postconf -M joinery/unix` | `sudo bash install_email.sh` |
| `host.domain_map` | `virtual_mailbox_domains` wired to the pgsql map | `postconf -h virtual_mailbox_domains` | `sudo bash install_email.sh` |
| `host.opendkim` | opendkim installed and running (`recommended`) | `which opendkim`, `pgrep` | `sudo bash install_email.sh` |
| `host.port25` | Port 25 listening locally | probe `127.0.0.1:25` | `sudo bash install_email.sh`; note inbound reachability is proven only by the e2e test |

### Mail-host identity layer

| id | Verifies | How | Fix |
|----|----------|-----|-----|
| `mailhost.hostname_set` | Postfix `myhostname` is a FQDN, not `localhost`/bare | `postconf -h myhostname` | `sudo postconf -e "myhostname=<mail hostname>" && sudo postfix reload` |
| `mailhost.hostname_matches` | `myhostname` == `inbound_email_mail_hostname` | compare | same `postconf` command |
| `mailhost.a_record` | The mail hostname has an A record | `DnsResolver::getA()` | DNS record: `A <mail hostname> → <public IP>` |
| `mailhost.a_matches_ip` | That A record == the server's public IP | compare | correct the A record |
| `mailhost.ptr` | Reverse DNS of the public IP resolves to a name | `DnsResolver::getPtr()` | Set PTR in the VPS/host control panel |
| `mailhost.ptr_fcrdns` | PTR name forward-resolves back to the same IP (FCrDNS) | `getPtr()` then `getA()` | Align forward and reverse DNS |
| `mailhost.ptr_matches` | PTR name == the mail hostname (`recommended`) | compare | Set PTR to the mail hostname |

### Per-domain DNS layer

| id | Verifies | How | Fix |
|----|----------|-----|-----|
| `domain.mx_exists` | An MX record exists for the domain | `DnsResolver::getMx()` | DNS record: `MX <domain> → 10 <mail hostname>` |
| `domain.mx_not_cname` | The MX target is not a CNAME (RFC 2181 §10.3) | `DnsResolver::getCname()` on the target | Point MX at a hostname with an A record, not a CNAME |
| `domain.mx_resolves` | The MX target has an A record | `DnsResolver::getA()` | Add the mail host's A record |
| `domain.mx_points_here` | The MX target's A record == the server's public IP | compare | Correct the MX target or the A record |
| `domain.spf_exists` | An SPF (`v=spf1`) TXT record exists | `DnsResolver::getTxt()` | DNS record: `TXT <domain> → v=spf1 ip4:<public IP> -all` |
| `domain.spf_authorizes` | SPF authorizes the server IP — parse `ip4`/`ip6`/`a`/`mx` mechanisms, not a substring match | parse TXT | Add `ip4:<public IP>` to the SPF record |
| `domain.dkim_key` | A local opendkim key exists for the domain (`recommended`) | check `/etc/opendkim/keys/<domain>/mail.txt` | `opendkim-genkey …` (link to docs) |
| `domain.dkim_published` | The published `mail._domainkey` TXT `p=` matches the local key `p=` byte-for-byte (`recommended`) | read local key, compare to `DnsResolver::getTxt()` | DNS record: `TXT mail._domainkey.<domain> → <generated value>` |
| `domain.dmarc` | A `_dmarc` TXT record exists (`recommended`) | `DnsResolver::getTxt('_dmarc.<domain>')` | DNS record: `TXT _dmarc.<domain> → v=DMARC1; p=none; rua=mailto:…` |
| `domain.mydestination` | The domain is NOT in Postfix `mydestination` | `postconf -h mydestination` | `sudo bash install_email.sh` |

### Plugin-config layer

| id | Verifies | How | Fix |
|----|----------|-----|-----|
| `plugin.enabled` | `inbound_email_enabled` = 1 | setting | Toggle it in the wizard / settings |
| `plugin.domain_row` | The domain exists in `ied_inbound_email_domains`, enabled, not deleted | model query | "Add domain" — the wizard offers it |
| `plugin.alias_or_catchall` | The target address has an alias, or the domain has a catch-all | model query | "Add alias" — the wizard offers it |
| `plugin.srs_secret` | If `inbound_email_srs_enabled`, `inbound_email_srs_secret` is non-empty | setting | Set a secret in the wizard / settings |
| `plugin.relay` | The outbound forwarding relay is reachable | reuse `InboundEmailHealth::checkForwardingRelay` logic | Fix relay settings |

### End-to-end layer

| id | Verifies | How | Fix |
|----|----------|-----|-----|
| `e2e.test_message` | A real inbound message for the target address has been received and logged | poll `iel_inbound_email_logs` for a row newer than the wizard step started | "Send a test email to `<address>` from any external account" — the wizard polls and reports when it arrives |

## DnsResolver addition

`DnsResolver` has `getA`/`getAaaa`/`getMx`/`getTxt`/`getCname` but no reverse
lookup. Add:

```
public static function getPtr(string $ip): array
```

Returns the PTR hostname(s) for an IPv4 or IPv6 address (builds the
`in-addr.arpa` / `ip6.arpa` name, looks up `DNS_PTR` through the same
`rawLookup` chokepoint). Throws `DnsLookupException` on resolver failure, like
its siblings. This is a small, general platform addition — reverse DNS is
broadly useful, not specific to this plugin.

## Server IP autodetection

Replace the `api.ipify.org` HTTP call. Detection order:

1. **Local primary IP** — open a UDP socket toward a public address and read
   `socket_getsockname()`. This yields the primary outbound-interface IP with
   no packet sent and no external dependency.
2. **If that IP is RFC 1918 private** (the box is behind NAT), it is not the
   public IP. The wizard surfaces this and asks the admin to enter the public
   IP — acceptable, since the admin is assumed to know how to find it.
3. The entered/confirmed public IP is stored in a new optional setting
   **`inbound_email_public_ip`** (empty = autodetect each time).

The wizard cross-checks the three signals it has — detected local IP, the mail
hostname's A record, and the PTR — and flags any disagreement explicitly
rather than silently trusting one.

## The Setup tab

A new admin page `admin/admin_inbound_email_setup.php` (+ logic), added as the
**first tab** in the Incoming admin area (before Forwarding Aliases / Domains /
Logs), reached at `/plugins/inbound_email/admin/admin_inbound_email_setup`.

Layout — a vertical checklist grouped by layer (Host → Mail host → each Domain
→ Plugin → End-to-end). Each item shows:

- a status pill (green `pass` / red `fail` / amber `warn` / grey `unknown`);
- the one-line `summary`;
- an expander revealing `detail` and the `fix` — a copy-ready DNS record card
  (type / name / value) or a copy-ready shell command.

Controls: a top-level **Re-check all** and a per-item **Re-check** (for
`recheckable` items). A summary banner — "Setup complete", or "N required item(s)
remaining" — drives the eye to what is left. `unknown` items are listed apart
so a flaky resolver never reads as a hard failure.

The Domains edit page keeps domain CRUD but drops its inline DNS panels; it
links to the Setup tab instead. `InboundEmailHealth::checkDomainDns` is
reimplemented on top of `InboundEmailSetupCheck` so the Plugins-page
provisioner badge and the Setup tab can never disagree.

## Guided "add an address" flow

From the Setup tab, **Add an address** starts a focused wizard:

1. **Address** — admin enters the address they want, e.g. `info@example.com`.
   The wizard derives the domain (`example.com`).
2. **Mail hostname** — the wizard proposes `inbound_email_mail_hostname` (or a
   default) and the admin confirms/edits it.
3. **Host** — runs the host-layer checks. Anything failing shows the single
   `sudo bash install_email.sh` fix.
4. **DNS** — shows every record to publish for this domain and mail host (MX,
   the mail-host A record, SPF, DKIM if a key exists, DMARC) as copy-ready
   cards. The admin publishes them; **Re-check** re-verifies until green.
5. **Plugin** — the wizard offers to create the `ied_inbound_email_domains`
   row and the alias, and to set `inbound_email_enabled = 1`, in place.
6. **Confirm** — "Send a test email to `info@example.com` from any external
   account." The wizard polls `iel_inbound_email_logs` and reports the moment
   the message lands — the only real proof inbound port 25 is reachable.

## install_email.sh change

`install_email.sh` runs as root, so it (not the web UI) can set Postfix
`myhostname`. Add a step: if `myhostname` is currently unset, `localhost`, or
not a FQDN, set it to the system FQDN (`hostname -f`) as a sane default. The
authoritative value remains the wizard's `mailhost.hostname_matches` fix
command, which the admin runs when they want a specific name. Bump the script
header version and note it.

## New settings

Declared in `plugin.json` `settings`, auto-seeded:

| Setting | Default | Purpose |
|---------|---------|---------|
| `inbound_email_mail_hostname` | `""` | FQDN of this mail server (MX target, HELO, PTR) |
| `inbound_email_public_ip` | `""` | Optional public-IP override; empty = autodetect |

## Out of scope

- Creating DNS records or setting PTR automatically — impossible without the
  admin's registrar / VPS credentials; the wizard instructs and verifies only.
- Registrar-API integrations.
- Inbound port 25 reachability as a positive automated test — genuinely
  un-self-testable; the e2e test message is the proof. The wizard notes that
  some ISPs block inbound 25.
- Outbound deliverability scoring / blocklist lookups (possible future work).

## Documentation

- Rewrite the "Installation" and "Server Setup" sections of
  `plugins/inbound_email/docs/overview.md` around the Setup tab as the primary
  path; keep the manual record reference as a fallback.
- Add a "Reverse DNS" note to `docs/email_system.md` cross-referencing the
  Setup tab.
- Document `DnsResolver::getPtr()` wherever the other `DnsResolver` methods are
  documented.

## Testing

- Unit-test `InboundEmailSetupCheck` against a mocked `DnsResolver` backend
  (`DnsResolver::setBackend()` already supports this) — one case per check id
  covering `pass` / `fail` / `unknown`.
- Test SPF parsing against `ip4`, `ip6`, `a`, `mx`, and `include` mechanisms.
- Test `getPtr()` for IPv4 and IPv6, and the FCrDNS round-trip.
- Manually walk the add-an-address wizard end to end on `dev.getjoinery.com`.

## Phasing

1. `DnsResolver::getPtr()` + server-IP autodetection helper.
2. `InboundEmailSetupCheck` engine + the full check catalogue.
3. The Setup tab UI and the add-an-address wizard.
4. Repoint `InboundEmailHealth` and the Domains page at the engine; delete the
   old shallow/`dns_get_record` checks.
5. `install_email.sh` `myhostname` step; docs.
