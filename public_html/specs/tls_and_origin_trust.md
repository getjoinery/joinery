# TLS across the estate: strict-ready nodes, issuance, renewal, and origin trust

**Supersedes and replaces two specs**, merged 2026-09-10 at the owner's
request: `origin_tls_and_certificate_issuance.md` (written 2026-08-30) and
`certificate_lifecycle.md` (written 2026-09-10, itself filed first as
`certificate_issuance_without_standing_dns_credential.md`). Reframed
2026-09-11 around the goal below; nothing measured or found earlier is dropped.

## The goal, in one sentence

**A node is strict-ready on its own: the owner flips Cloudflare to Full
(Strict) and the node just works, and keeps working, with no operator step
on the node — ever.** Everything in this spec is ordered by how directly it
serves that sentence. The DNS-01 credential question (D1–D3, WP8–WP9) and the
origin firewall (WP7) do not serve it and are deferred below it.

## Status

| Part | Status |
|---|---|
| **Strict-ready nodes** — the installer, renewal, www, node-side health (WP0, WP1a, WP2, WP3, WP5, WP10, WP11) | **PLANNED 2026-09-11, owner accepted the step-by-step plan.** Build in the order under "The plan". |
| **Flip Strict on our own zones** (WP6) | Waits on WP0 + www on the three bare-metal certificates + WP2. |
| **ScrollDaddy DoH renewal** (WP1) | **DONE 2026-09-11.** New zone-scoped, IP-filtered token on both boxes; both renewed from the production CA (expire 2026-12-10); DoH verified end to end. Credential recorded in the ops guide and `docs/dns_management.md`. |
| **Origin trust layer three** — Authenticated Origin Pulls, the firewall (WP7, D5) | **DEFERRED by owner 2026-08-30.** Unchanged. |
| **Where a standing DNS-01 credential lives** (D1–D3, WP8, WP9) | **DEFERRED 2026-09-11.** Strict needs none of it. WP1 buys the time. |

Dated items:

- **2026-09-17** — first renewal on jeremytunnell after the read-only-tree
  vhost hand-apply; certbot's Apache installer edits the managed vhost and the
  converger then refuses to manage it. B6, WP1a. dev follows 2026-09-20.
- ~~2026-09-30 21:58 UTC — the ScrollDaddy DoH certificate lapses. WP1.~~ Closed 2026-09-11: renewed to 2026-12-10 on both boxes.

## The problem in plain terms

A visitor's connection to one of our sites is encrypted twice: browser to
Cloudflare, then Cloudflare to our server. The first leg is properly locked —
the browser checks Cloudflare's identity. The second leg is only half locked.
Cloudflare encrypts it but, in its **Full** mode, accepts whatever certificate
the server shows. **Full (Strict)** refuses unless the server shows a valid
certificate for that exact name. We are on Full everywhere because on Strict
five of our sites would go dark on the spot, and the other three would lose
`www`.

**Example one.** Ask our server for `galactictribune.net` and it shows a
certificate for `developers.getjoinery.com`. Not a broken certificate — the
wrong one. The server holds none for `galactictribune.net`, so Apache hands
over the first it owns. Strict rejects that. Same for `getjoinery.com`,
`scrolldaddy.app`, `mapsofwisdom.org`, `phillyzouk.org`.

**Example two.** `jeremytunnell.com` holds a certificate for
`jeremytunnell.com` and not for `www.jeremytunnell.com`. People type www;
Cloudflare passes it through; the origin shows the apex certificate; Strict
rejects that too.

**Example three — the customer's path.** A customer installs a node for
`example.com` with DNS already pointing at Cloudflare. The installer compares
the box's address with what the name resolves to, sees they differ, gives up
on HTTP-01, finds no DNS credential file, and issues nothing. The site runs
fine on Cloudflare's default mode. The customer flips Strict and the site goes
dark with no clue why. That give-up is a mistake: HTTP-01 works through
Cloudflare (demo and orgs were issued and have renewed that way), so the
installer refuses for a reason that was never true. This is the common case
for anyone who set up Cloudflare first, and it is exactly how our five sites
got where they are.

Two more things, separate from strict but in the same estate, which is why
this kept looking like several specs:

- **Getting a certificate needs proof of control.** HTTP-01 (the door knock:
  the CA fetches a file from the name) needs no credential. DNS-01 (a note in
  the public directory: a TXT record) needs a key that, as sold, edits every
  record in the zone. Two of our machines are stuck on DNS-01 and each holds
  such a key in a plain file on a public box. On 2026-08-31 it stopped being
  accepted and nobody found out for ten days. Measured and specified below;
  deferred as D1–D3.
- **The renewal that works is not the renewal that is watched.** Renewal on
  Joinery nodes works — measured, not assumed — but the monitor skips every
  machine behind Cloudflare, so most certificates renew unobserved. On Full an
  expired origin certificate is invisible; on Strict it is an instant outage.
  That is why watching comes before flipping.

Throughout, the two proof methods are called by their ACME names, **HTTP-01**
and **DNS-01**.

## Measured 2026-09-10 — the complete inventory

Probed over the wire against every origin address, not read out of config.
Re-confirms the 2026-08-30 measurement in every particular.

### What each name is served, and by what

| Name | Fronting | Certificate the ORIGIN presents | Expires |
|---|---|---|---|
| `developers.getjoinery.com` | direct to origin | its own | 2026-11-17 |
| `demo.getjoinery.com` | Cloudflare | its own | 2026-10-20 |
| `orgs.getjoinery.com` | Cloudflare | its own | 2026-11-30 |
| `jeremytunnell.com` | Cloudflare | its own | 2026-10-17 |
| `getjoinery.com` | Cloudflare | `developers.getjoinery.com` — **mismatch** | — |
| `scrolldaddy.app` | Cloudflare | `developers.getjoinery.com` — **mismatch** | — |
| `galactictribune.net` | Cloudflare | `developers.getjoinery.com` — **mismatch** | — |
| `mapsofwisdom.org` | Cloudflare | `developers.getjoinery.com` — **mismatch** | — |
| `phillyzouk.org` | Cloudflare | `developers.getjoinery.com` — **mismatch** | — |
| `dns.scrolldaddy.app` | direct, **two A records** | its own | 2026-09-30 |
| `joinery-relay-1` | direct | self-signed, 30-year | 2056 |

The mismatch is Apache falling back to its first SSL vhost: those five have no
certificate of their own, so the `<IfFile>` :443 block of their proxy vhost
never activates. All eight containerised nodes share Docker host
`23.239.11.53`. `dev.getjoinery.com` (origin `69.164.209.253`, Cloudflare)
presents its own, expiring 2026-10-20.

### The www names (measured 2026-09-11)

Every zone proxies `www` through Cloudflare, and Cloudflare passes it to the
origin, which answers the 301 to the apex itself (`Server: Apache` on the
edge's 301). **No certificate on the estate carries a `www` name** — every
lineage is single-name, including jeremytunnell, dev, demo and orgs. Strict
therefore breaks `www.` on all six zones today, the three "correct" sites
included. See B7.

### Where a standing DNS-write credential lives

| Where | Standing credential |
|---|---|
| ScrollDaddy DNS Primary (mgn 27) | **Yes** — zone-wide Cloudflare token, `/etc/systemd/system/caddy.service.d/cloudflare.conf` |
| ScrollDaddy DNS Secondary (mgn 28) | **Yes** — the same token, second copy |
| Docker host `23.239.11.53` | No — HTTP-01 via certbot + apache |
| Relay `joinery-relay-1` (mgn 1800) | No — self-signed, no CA involved |
| Fleet provisioning path | **Yes, by design** — operator hand-drops `/etc/letsencrypt/{provider}.ini` (`SslProvisionOutcome.php`, `install.sh` step 2) |
| Setup wizard first-boot publish | No — `DnsInstallCredential`, sealed, deleted on first use |
| Admin DNS publish box | No — ephemeral, one request, never stored |

**Exactly two machines hold one, and they are exactly the two whose name has
more than one A record.** The pattern is not a ScrollDaddy quirk: the fleet
path mints another every time a customer node needs DNS-01.

### Whether renewal happens, and whether anyone is watching

| Name | Issued | Renewal due | Renewing? | Watched? |
|---|---|---|---|---|
| `orgs.getjoinery.com` | 2026-09-01 | +50 days | **Yes, proven** | **No** |
| `developers.getjoinery.com` | 2026-08-19 | +37 days | **Yes, proven** | Yes |
| `demo.getjoinery.com` | 2026-07-22 | +10 days | presumed | **No** |
| `jeremytunnell.com` | 2026-07-19 | **+6 days** | unproven | **No** |
| `dns.scrolldaddy.app` | 2026-07-02 | **overdue 9 days** | **No — failing** | Yes |

`orgs` renewed on 2026-09-01 and `developers` on 2026-08-19, both unattended.
`orgs` is behind Cloudflare, so renewal demonstrably survives the edge too.
**Automatic renewal on Joinery nodes works, and a new deploy will renew in 90
days.** Certificate monitoring covers 3 of 13 managed nodes.

`certbot.timer` on the Docker host is enabled and active (12-hourly plus
`/etc/cron.d/certbot`). Note that `install.sh` never enables it — it inherits
it from the Debian package.

## What actually happened on the two DNS boxes

- **2026-07-02 21:58 UTC** — certificate issued, 90-day life. Cloudflare token
  created the same evening.
- **2026-08-31** — renewal falls due. Caddy's ARI window opens 00:42 UTC; the
  first failed attempt is logged 22:07 UTC (22:00 on the secondary). Cloudflare
  rejects the challenge write: `HTTP 403 Code 9109 Invalid access token`, later
  `HTTP 401 Code 10000 Authentication error`.
- **through 2026-09-10** — 57 attempts, every six hours, all rejected
  identically, on both boxes. After repeated production failures Caddy falls
  back to the Let's Encrypt **staging** endpoint to protect rate limits.
  Nothing in `/etc/caddy` or the systemd override names staging; it is fallback
  behaviour. **Had a staging attempt ever succeeded, both boxes would have
  begun serving an untrusted certificate and gone hard down.** The renewal
  failing is what kept them up.
- **2026-09-09** — the first alert reaches a human, at expiry minus 20 days.

Nothing on the boxes changed. The token file has not been written since Jul 2.

## Findings

### B1 — the doctrine says this cannot happen

`docs/dns_management.md:198` states the rule and then justifies it:

> **Ephemeral is the only mode. Nothing DNS-write-capable is ever stored — not
> even sealed.** [...] Nothing in the platform forces us off this: drift is
> *detected* credential-free, and certificate issuance runs over HTTP-01
> through `certbot` on each node, so no timer ever needs a standing DNS-write
> credential.

The rule is good. The justification is false, and was false before it was
written: the ScrollDaddy boxes renew over DNS-01 on a timer, and
`SslProvisionOutcome.php` instructs operators to place a DNS-01 credential on
any fleet node that needs one.

This is the root cause, not a side note. The rule forbade the credential on the
plane, where it would have been sealed, rotated and watched. It did not forbid
the credential — so it moved to the two boxes least able to protect it, and no
owner, no rotation and no monitoring followed it there. **A ban that only
displaces the thing it bans leaves the estate worse off than an honest
exception would have.**

Correcting the doc is unconditional and does not wait on D1.

### B2 — a nine-day blind window in the alert

A 90-day certificate falls due for renewal at 30 days remaining.
`server_manager_cert_expiry_warn_days` defaults to **21**. So renewal can be
provably failing for **nine days** before anything is said — which is most of
what happened here.

The alert should fire on *renewal overdue*, not on *expiry approaching*. The
renewal-due date is already computed by `RunNodeUptimeChecks::renewal_due_ts()`;
the check simply does not trigger on it.

### B3 — two thirds of the estate renews unwatched

`RunNodeUptimeChecks::check_cert_expiry()` returns early for any node whose name
does not resolve to that node's own address, commented *"fronted / not directly
exposed — not our cert to monitor"*. Cloudflare does renew the edge
certificate, so the first half is true. But the edge certificate is not the one
at risk. **The node behind the edge has its own, it expires on its own
schedule, and nothing watches it.**

**Why it is invisible.** With Cloudflare in Full (not Strict), an expired origin
certificate is accepted and the site keeps serving. No symptom, no alert, no
visitor-facing damage — right up until strict checking is enabled, at which
point the same expiry is an instant outage. **This is why B3 gates WP6.**

**The normal install sequence produces exactly this state.** DNS is pointed at
the box; the installer sees the name resolve direct and issues over HTTP-01;
Cloudflare's proxy is switched on afterwards. The node now holds a renewing
certificate and has, at that moment, dropped off the monitor. That is the
documented happy path, not an edge case.

**The fix is a deletion.** The check already probes the node's own address with
correct SNI, and already verifies via `cert_covers_host()` that the certificate
covers the hostname. That second test does all the work the first was meant to:
for the five mismatched names it declines correctly, and for `demo`, `orgs` and
`jeremytunnell` it would begin watching correctly. The A-record gate protects
nothing and switches the monitor off for two thirds of the fleet.

### B4 — the origin cannot be authenticated

Cloudflare cannot be put in Full (Strict) for the five mismatched names: strict
rejects a name mismatch. So that hop is encrypted but unauthenticated —
Cloudflare will accept any certificate presented, including an interposed one.

### B5 — the edge can be bypassed

Ports 80 and 443 are open to the internet on the origin address (verified).
Anyone who learns it reaches the sites without passing the edge, which is also
how any WAF, rate limit or bot rule at the edge gets skipped.

### B6 — renewal edits the vhost the renderer owns

`render_vhost.sh` (specs/implemented/read_only_tree.md) applies a template render only
over its own recorded output, so a hand edit is never silently overwritten.
certbot's Apache installer is such an edit, and it recurs: on every renewal
`ApacheConfigurator._deploy_cert` calls `_add_dummy_ssl_directives`, which adds
`Include /etc/letsencrypt/options-ssl-apache.conf` to the domain's vhost
whenever the line is missing (verified in the certbot 2.9.0 installed on dev,
2026-09-11). `install.sh` issues with `certbot --apache`, so every bare-metal
box renews with `installer = apache`.

Two consequences, neither of them an outage:

- Apache stays valid. The Include only duplicates the TLS policy template 2.05
  states itself.
- The vhost stops matching the record, so every later converge refuses to
  re-render it, leaves a `.conf.new` candidate and says so daily. Template
  changes stop landing on that box. A new bare-metal install reaches this state
  on day one: the installer renders the vhost, then certbot edits it.

Renewal opens 30 days before expiry: jeremytunnell (expires 2026-10-17) from
about 2026-09-17, dev (expires 2026-10-20) from about 2026-09-20.

**The fix is to take certbot out of the vhost.** The certificate files land at
the standard path either way, and the template's `<IfFile>` :443 block reads
them from there; all certbot has to do after writing them is reload Apache.
That is `certonly` plus a deploy hook, which is also the shape WP8 lands on.

### B7 — no certificate covers www, and every www reaches the origin

Measured 2026-09-11. All six zones proxy `www` and Cloudflare forwards it to
the origin, which serves the redirect to the apex. Every certificate on the
estate is single-name. Strict rejects the handshake for `www.` on every zone,
including jeremytunnell, dev, demo and orgs. The fix is general and belongs in
the installer, not at the edge: issue for the apex **and** `www` whenever
`www` resolves (to the box or to an edge). An edge redirect rule would also
work but is Cloudflare-specific and gives a directly-served customer nothing.

### B8 — the installer refuses HTTP-01 behind an edge for a reason that is not true

`provision_origin_cert()` in `install.sh` attempts HTTP-01 only when the name
resolves to the box's own address. Behind Cloudflare it never does, so the
installer falls to DNS-01, finds no credential, and issues nothing. HTTP-01
through the Cloudflare proxy works: demo and orgs were issued that way and
`orgs` renewed through the edge on 2026-09-01. The gate protects nothing and
produces the strict-breaking state on every Cloudflare-first install. Same
shape as B3: **the fix is a deletion.** Attempt HTTP-01 whenever the name
resolves anywhere; fall to DNS-01 only when it fails.

One true limit remains and belongs in the health panel, not in code: if the
owner flips Strict before the node ever held a certificate, Cloudflare refuses
to reach an origin without one and the HTTP-01 challenge cannot get through.
The retry timer keeps trying; the panel says to set Full until the first
certificate lands.

### B9 — a self-hosted node cannot tell its owner that renewal is failing

The plane's uptime check (B2, B3) watches managed nodes. A self-hosted box has
no plane. Its owner learns of a failed renewal when the site dies — on Full
never, on Strict at expiry. The node already has `AdminNotices` and the health
panel; certbot already records each lineage's renewal state under
`/etc/letsencrypt`. Nothing reads it.

### B10 — a fresh box behind an edge can never get its first certificate (review, 2026-09-11)

Traced on three of our zones: the edge redirects `/.well-known/acme-challenge/`
to https ("Always Use HTTPS" is on everywhere we looked). A new box has no TLS
listener until a certificate file exists, because both templates activate the
:443 block only under `<IfFile /etc/letsencrypt/live/<domain>/fullchain.pem>`.
So the edge's https hop fails with **525 (handshake failed) in Full mode too**,
not only in Strict. Let's Encrypt's validator follows the same redirect, so
HTTP-01 fails the same way, forever. The reach probe (WP10) predicts that
outcome honestly but misdiagnoses it as "the edge is on Strict" and tells the
owner to set Full, which they already have; the retry timer waits with the
same wrong advice. This is the customer's most common path: Cloudflare first,
default settings, one site on the box. Our five container sites are unaffected
only because the Docker host has other SSL vhosts to fall back on.

**The fix is a placeholder.** Apache must be able to answer TLS from the first
minute, with a certificate nothing trusts. Cloudflare Full accepts a
self-signed origin certificate, so the https hop completes, the challenge
arrives, the real certificate lands, and the vhost switches to it on reload.
The retry timer already refuses to count a self-signed certificate as done
(`have_real_cert` compares issuer and subject), so half the design exists.
See WP12.

## What "end to end" requires

Three layers. The certificate alone is the first and least of them.

1. **A certificate per name on the origin.** Removes the mismatch.
2. **Cloudflare set to Full (Strict).** Without this the origin certificate is
   decoration: non-strict accepts anything, so the second hop stays
   unauthenticated no matter how correct the certificate is. This step is what
   converts encryption into authentication.
3. **Authenticated Origin Pulls, plus 80/443 firewalled to Cloudflare ranges.**
   Makes the origin refuse anything that did not come through the edge. Without
   it the bypass in B5 survives every certificate fix.

A deployment not behind a CDN at all (`developers.getjoinery.com` today, and
any customer node) needs only layer 1; layers 2 and 3 are Cloudflare-specific
and belong to whatever fronting a deployment chooses.

## Settled with the owner, 2026-09-11

- **Q1 www:** covered by the certificate (apex + www in one lineage), issued by
  the installer. Not by an edge redirect rule.
- **Q2 zone scope:** Strict is flipped per zone once every proxied origin in it
  is valid. No per-hostname configuration rules. `getjoinery.com` is one zone
  with five proxied origins (apex, dev, demo, orgs, www); the apex is the only
  one without a certificate.
- **Q3 source of the five missing certificates:** certbot HTTP-01 on the Docker
  host, the path demo and orgs already use. Not Cloudflare Origin CA
  certificates: fifteen-year, no renewal, but trusted only by Cloudflare, so a
  node is tied to one edge and a directly-served node gains nothing.
- **Q4 Strict pinned, not Automatic:** the target mode is Full (Strict), with
  our own monitoring (WP2, WP3, WP11) as the net. Cloudflare's Automatic
  SSL/TLS mode is the fallback only for a zone whose origin cannot yet hold a
  certificate; it is never relied on to catch a lapsed one. Whether Automatic
  steps a zone *down* on a broken origin certificate, rather than only up, is
  unverified against Cloudflare's documentation and must not be assumed.
- **Layer three (WP7) and the DNS-01 credential (D1–D3, WP8, WP9)** stay
  deferred. Neither blocks Strict anywhere.

## The plan, step by step

Each step is independently shippable and leaves the estate better than it
found it. The customer's three DNS states at install (points at the box;
points at Cloudflare; points nowhere yet, retry timer) must all end in the
same place: a certificate for apex + www, renewing on a timer, with the vhost
still owned by the renderer and the owner told when renewal breaks.

1. **WP0 — the five sites, by hand, once.** On the Docker host, one certbot
   `certonly` per name covering apex + www. The proxy vhost's `<IfFile>` :443
   block activates on reload; nothing is hand-edited. Closes the mismatch for
   the estate. The installer changes in step 4 make sure no customer ever
   needs this.
2. **WP1a — certbot out of the vhost. Deadline 2026-09-17.** Already worked
   out (below). Also the shape WP0 and the installer use from here on.
3. **WP2 + WP3 — watch every origin certificate, alert on renewal overdue.**
   Prerequisite for flipping anything.
4. **WP10 — the installer issues for every install (B7, B8).** Attempt HTTP-01
   whenever the name resolves anywhere; include `www` when it resolves; the
   retry timer inherits both. `certonly` + deploy hook, never `--apache`.
5. **WP12 — the placeholder certificate (B10).** Apache answers TLS from the
   first minute so the edge's https hop completes and the challenge arrives.
6. **WP11 — node-side renewal health (B9).** A notice and a health row from
   certbot's own records.
7. **WP5 — assert `certbot.timer` at install.**
8. **Re-issue the three bare-metal certificates with www** (jeremytunnell, dev,
   developers) — by hand once, or let WP10's `setup_ssl.sh` do it.
9. **WP6 — flip Full (Strict), zone by zone**, in the dashboard, by hand, once
   steps 1–8 hold for every origin in the zone.
10. **WP4 — the docs.**

WP1 (ScrollDaddy) runs whenever, before 2026-09-30; it touches none of the
above.

## Deferred half: the DNS-01 credential (D1–D3, WP8, WP9)

Kept in full so the decision is made once, from the measurements, when it is
taken up. Nothing in it blocks Strict.

### Why the two DNS boxes cannot use HTTP-01

A constraint, not a preference, and it should not be re-litigated.
`dns.scrolldaddy.app` has two A records so a device keeps resolving when one box
is down. HTTP-01 asks Let's Encrypt to fetch a file from the name being
validated, and the validator may land on either box. Whichever box is renewing
cannot make its twin serve the token, so HTTP-01 fails unpredictably about half
the time.

Two alternatives that look like they dodge this, and why they do not:

- **Per-box names** (`dns1`/`dns2`) with one A record each: each box could then
  use HTTP-01, but clients connect to the shared name and would be handed a
  certificate for a different one. The shared name still needs validating.
- **Put the resolver behind Cloudflare**: the edge holds the certificate and
  the problem disappears. It also routes every customer's DNS query through a
  third party, which is the opposite of what the product is for.

### Issuance: DNS-01, and the machinery that already exists

**Prefer DNS-01 for the estate generally.** HTTP-01 needs the challenge to
survive a round trip through the edge. It usually does — that is how demo and
orgs got their certificates, and it is still working — but it breaks under
Under Attack mode, cannot issue wildcards, and makes renewal depend on the very
edge we are trying to stop depending on. DNS-01 proves control by writing a TXT
record, so it works whether a site is proxied, firewalled or offline.

**The machinery is not certificate-specific and is already built.**
`includes/dns/` carries fifteen provider drivers including
`CloudflareDnsDriver`, TXT support, CAA handling (`docs/dns_management.md`
notes a wrong CAA record blocks issuance the same silent way a missing
challenge record does), ownership tracking in `dnr_dns_records`, and
`DnsPublishConsumer`, whose OAuth grant does not outlive the request. DNS-01 is
a consumer of that subsystem, not a new one.

**Where issuance runs — the design decision.** The node generates the private
key and a CSR; the plane performs the DNS-01 challenge and returns the signed
certificate; the node installs it. This keeps all three doctrine lines intact:
the private key never leaves the machine that serves it, the node never holds a
DNS credential, and the plane holds nothing that opens a node. The obvious
alternative — issue centrally and ship key + certificate to the node — is
rejected: it puts a private key on the wire and in the plane's memory for no
gain.

**But it does not say which credential the plane uses**, and unattended renewal
on a timer needs a standing one. That gap is D1, and it must be answered before
this is built. The options below are the ways to answer it.

### Options for the standing credential

#### O1 — Delegate the challenge to a throwaway zone

`_acme-challenge.dns.scrolldaddy.app` becomes a permanent CNAME into a separate
zone holding nothing else. The real zone gets one record, once, by hand; it
never changes again. The credential is scoped to the throwaway zone.

- **Pro:** blast radius goes to almost nothing — a stolen credential writes TXT
  records in an empty zone. No new infrastructure. Works for any node, any
  provider, any name, so it generalises to the fleet path. A credential over a
  worthless zone can be minted non-expiring without concern.
- **Con:** a standing credential still lives on the box and can still be
  revoked. Requires confirming the ACME client follows the CNAME when
  *presenting* — `certbot` and `lego` do; **Caddy/certmagic is unverified and
  must be tested before this is chosen.**

#### O2 — Plane-side issuance: the node sends a request, never a credential

The design decision above, built. Node generates key and CSR, plane performs
the challenge, node installs.

- **Pro:** the node holds no DNS credential at all; the private key never
  leaves the machine that serves it. One path for bare metal, containers and
  fronted nodes. Collapses the `is_bare_metal` / `on_host` branching in
  `build_provision_ssl`.
- **Con:** two blockers.
  1. **Transport.** Delivery is the agent's `install_certificate` primitive.
     Nodes 27 and 28 will never run the agent — settled decision A8, reaffirmed
     2026-08-30, **not reopened here**. Reaching them needs another channel, or
     they stay on O1 while the fleet moves to O2.
  2. **The credential moves, it does not vanish.** It lands on the plane, which
     the ephemeral-only rule forbids outright.

#### O3 — Non-expiring credential, louder alerting

Mint a Cloudflare token with no expiry, keep the arrangement, fix B2 and B3.

- **Pro:** about an hour of work, no design commitment.
- **Con:** fixes the clock and not the blast radius. Two internet-facing boxes
  keep zone-wide edit rights, and the fleet path keeps minting more.

#### O1 + O2 — the reason to prefer O1 first

If the zone delegated *to* is one the platform serves authoritatively itself,
no third-party DNS credential exists anywhere: not on the node, not on the
plane. The proof-of-control record is a row in our own database. That satisfies
the ephemeral-only rule honestly instead of displacing it, closes O2's blocker
2, and is reusable for every fleet node and every customer domain.

The cost is honest: new authoritative-DNS infrastructure, small but real
(`acme-dns` is precisely this and is one Go binary), which must be run and
monitored like anything else. `scrolldaddy-dns` is a *resolver* and does not
give us this for free.

## Work packages

- **WP0 — Issue the five missing certificates.** Docker host `23.239.11.53`,
  root by hand, with `sysadmin_tools/issue_origin_cert.sh <domain>` (built in
  Step 6) for each of `getjoinery.com`, `scrolldaddy.app`,
  `galactictribune.net`, `mapsofwisdom.org`, `phillyzouk.org`: apex + www,
  `certonly` with a reload hook, verified at the origin with SNI for both
  names. No vhost edit: the proxy template's `<IfFile>` block reads the
  standard path. **Read "Corrections" first — never delete demo or orgs.**
- **WP1 — Restore ScrollDaddy renewal. DONE 2026-09-11** (two gotchas, recorded in the ops guide: an account-owned token fails `/user/tokens/verify` though valid, and Caddy reaches the API over IPv6 so the IP filter needs both families). Was: New Cloudflare
  credential, both boxes, `daemon-reload`, restart Caddy so the next attempt
  goes to production rather than staging. Confirm a fresh certificate lands.
  Buys the time to decide D1.
- **WP1a — Fix B6: take certbot out of the vhost. Deadline 2026-09-17.**
  Three parts, all in install tools, pinned by `installer_contract_test`:
  (1) `install.sh` issues with `certbot certonly --apache ... --deploy-hook
  'systemctl reload apache2'`, then reloads Apache once so the `<IfFile>` block
  comes up. (2) `render_vhost.sh`, root on every converge: where
  `/etc/letsencrypt/renewal/<domain>.conf` says `installer = apache`, set
  `installer = None` and `renew_hook = systemctl reload apache2`. Idempotent, so
  every existing box fixes itself without a shell. (3) The renderer's adoption
  rule treats certbot's two known insertions — the Include line and the
  `RewriteCond %{SERVER_NAME}` redirect ending `[END,NE,R=permanent]` — as not
  an operator edit, so a box that already carries them adopts instead of
  waiting for a hand-apply. The Docker host has no renderer, so nothing breaks
  there, but its lineages take the same `installer = None` for consistency.
- **WP2 — Fix B3: watch every origin certificate.** Remove the A-record gate in
  `check_cert_expiry()` and let `cert_covers_host()` decide. Check `www` as well
  as the apex once B7 is closed. **Prerequisite for WP6.**
- **WP3 — Fix B2:** alert on renewal overdue, not only on expiry approaching.
- **WP10 — Fix B7 + B8: the installer issues for every install.**
  `provision_origin_cert()`: attempt HTTP-01 whenever the name resolves to any
  address (drop the own-IP comparison; keep it only as the log line saying
  "direct" or "behind an edge"); add `-d www.<domain>` whenever `www` resolves;
  fall to DNS-01 only when HTTP-01 fails. `setup_ssl.sh` and the deferred-DNS
  retry timer call the same function and inherit both. Pinned by
  `installer_contract_test`: no `certbot --apache`; an HTTP-01 attempt is not
  gated on an IP match; `www` appears in the issue line.
- **WP12 — Fix B10: the placeholder certificate.** Two parts, both templates,
  pinned by `installer_contract_test`:
  (1) `install.sh` gains `mint_placeholder_cert <domain>`: when
  `/etc/letsencrypt/live/<domain>/fullchain.pem` is absent and
  `/etc/ssl/joinery/<domain>/fullchain.pem` is absent, mint a self-signed
  EC certificate (`openssl req -x509`, ten years, CN and SAN = the apex and
  `www.<apex>`) into `/etc/ssl/joinery/<domain>/{fullchain,privkey}.pem`,
  root:root, key 0600. Called by `write_universal_vhost` in both modes, and by
  `render_vhost.sh` on every converge when neither file exists, so an
  existing box that somehow has no certificate at all gets one too.
  `issue_origin_cert.sh` never touches it.
  (2) `default_virtualhost.conf` → 2.06 and `default_proxy_vhost.conf` →
  1.02: the :443 block reads the Let's Encrypt lineage when it exists and the
  placeholder otherwise. Preferred mechanism: one :443 block, the certificate
  directory chosen by a per-site `Define` (`JOINERY_CERT_DIR_{{SITE_NAME}}`)
  set inside `<IfFile ...>` / `<IfFile !...>` on the Let's Encrypt path, the
  block guarded by `<IfFile ${JOINERY_CERT_DIR_{{SITE_NAME}}}/fullchain.pem>`.
  The name carries the site so two sites on one host (the Docker host) do not
  collide. If `Define` inside `<IfFile>` or variable expansion in an `IfFile`
  argument does not behave on Apache 2.4.58, fall back to a second :443 block
  under `<IfFile !<le path>>` + `<IfFile <placeholder path>>` with the paths
  swapped, and say so in the handoff. **The :80 → https redirect stays guarded
  on the Let's Encrypt path alone**: a placeholder must never pull a direct
  visitor onto a certificate their browser will warn about.
  Pins: rendered with neither file, no :443 block and the site answers on :80;
  with the placeholder only, :443 carries the placeholder paths and :80 has no
  https redirect; with both, :443 carries the Let's Encrypt paths and the
  redirect is on. The reach probe's return codes then mean what they say: 526
  is Strict refusing the placeholder, 525 is a box with no TLS at all, which
  after WP12 is a defect and is reported as one.

- **WP11 — Fix B9: node-side renewal health.** A core notice (the
  `HostConvergerNotice` shape) plus a `VaultHealth` row reading
  `/etc/letsencrypt/renewal/*.conf` and each lineage's `notAfter`: green while
  renewal is on schedule; a named warning when the lineage is past its renewal
  window without a new certificate; the Strict-before-first-certificate advice
  from B8 when there is no lineage and the name resolves to an edge. The
  root-owned tree makes `/etc/letsencrypt` unreadable to the pool
  (`JobResultProcessor` already notes this) — the converger writes a small
  world-readable summary under `cache/` on every run, the same way it writes
  `host_converger.last`, and the notice reads that.
- **WP5 — Assert `certbot.timer` in `install.sh`** rather than inheriting it
  from the package, so a change of install method cannot silently disable
  renewal on every new node.
- **WP4 — Correct `docs/dns_management.md`.** Replace the false justification at
  line 198 with what the estate actually does, and say where each standing
  credential lives. Add the strict-ready contract to `docs/deploy_and_upgrade.md`:
  what a node guarantees an owner who flips Strict.
- **WP6 — Turn on Full (Strict), zone by zone.** In the Cloudflare dashboard,
  by hand, after WP0, the www re-issue and WP2, never before: enabling it while
  a name is uncovered takes that site down, and enabling it while renewals are
  unwatched arms a silent failure. Zones: `getjoinery.com` (apex, dev, demo,
  orgs, www), `jeremytunnell.com`, `scrolldaddy.app` (`dns.` is DNS-only and
  unaffected), `galactictribune.net`, `mapsofwisdom.org`, `phillyzouk.org`.

Deferred, unchanged in substance:

- **WP7 — Authenticated Origin Pulls and the origin firewall.** Closes B5.
  Needs D5. Firewalling the Docker host to Cloudflare ranges cuts off
  `developers.getjoinery.com`, served direct on purpose.
- **WP8 — Implement the chosen option** from D1, including the fleet path in
  `SslProvisionOutcome.php` and `install.sh`, not only nodes 27 and 28.
- **WP9 — Retire `build_provision_ssl`** and its `local` + `ssh` steps, which
  WP8 makes unnecessary. Removes one of the thirteen local-queue dependants.

## Open decisions (all deferred; none blocks Strict)

- **D1 — Where does the standing DNS-write credential live?** On the node (O1),
  on the plane (O2), or nowhere because we serve the challenge zone ourselves
  (O1+O2). Everything in WP8 follows from this.
- **D2 — Do nodes 27 and 28 stay on self-service issuance permanently?** They
  will never run the agent. Either they keep a local ACME client and O1 is
  their end state, or O2 needs a non-agent transport built for them.
- **D3 — Does the ephemeral-only rule take a written exception, or do we build
  our way back to honesty?** O1 and O2 both need an exception recorded. O1+O2
  does not, which is its strongest argument.
- **D5 — How is a legitimately direct-served node exempted from the origin
  firewall?** `developers.getjoinery.com` is served direct on purpose, and WP7
  must not break it.

**D4 — do these two specs merge? — ANSWERED 2026-09-10: yes, by the owner.**
Recorded rather than deleted so a reader does not re-open it.

## What this supersedes elsewhere

**R3 (Docker-host certificates)** in
`agent_machine_posture_and_relay_converge.md` should be reconsidered rather than
built as written. R3 teaches the agent to run certbot on the Docker host, which
keeps the inbound-HTTP dependency and the container/bare-metal split. WP8
removes both: one path for bare metal, containers and fronted nodes.

**G2** in `agent_local_queue_retirement.md` — "new certificate issuance for a
directly-served container node has no transport once the local queue is gone" —
is dissolved by WP8, which needs nothing inbound at all.

## Corrections this spec preserves

**Do not delete the demo and orgs certificates on `23.239.11.53`.** They were
briefly read as vestigial leftovers for names now served at the edge, and a
`certbot delete` was proposed. That was wrong — they are the origin-leg
certificates for a live serving path, and deleting them downgrades the two
sites currently doing this correctly. Anyone tempted to tidy the host should
read the inventory tables first. This is exactly the tidy-up a future reader
attempts.

**Renewal-due dates agree with a CA's ARI window to the day, not the minute.**
A CA jitters each certificate's window. The two-thirds-of-lifetime rule in
`renewal_due_ts()` landed on 2026-08-31 for the ScrollDaddy certificate; Caddy's
own window opened 2026-08-31 00:42 UTC. Same day, ~21 hours apart. Claims of
minute-level agreement are wrong.

## Acceptance

Strict-ready (this round):

- A fresh install whose DNS points at Cloudflare, with the edge on Full and
  "Always Use HTTPS" on, ends with a certificate for apex + www, issued over
  HTTP-01, with no credential file and no operator step. The box answers TLS
  from its first minute (a placeholder until the real one lands).
- The same holds when DNS points at the box, and when it points nowhere yet
  (the retry timer lands it later).
- Every site name, and its `www`, presents its own valid certificate at the
  origin under SNI.
- Renewal never edits a vhost the renderer owns: after a renewal the vhost
  still equals the recorded render, on every bare-metal box.
- Renewal on a new node does not depend on a default we did not set.
- A renewal that starts failing is reported within one day, not at expiry
  minus twenty — on the plane for a managed node, on the node's own health
  panel for a self-hosted one.
- Every certificate the estate serves is watched by something, including the
  origin certificate behind an edge.
- Cloudflare is Full (Strict) for every proxied zone we own, and stays up
  through one renewal cycle per zone.
- `docs/deploy_and_upgrade.md` states what a node guarantees an owner who
  flips Strict.

Deferred halves (unchanged):

- The origin refuses a connection that did not come through the edge, except
  for nodes deliberately served direct. (WP7)
- A certificate issues and renews with no inbound HTTP to the node. (WP8)
- `grep` finds no `local` or `ssh` step in any certificate path. (WP9)
- No machine holds a DNS-write credential broader than the job it performs, and
  every credential that exists has a recorded owner and a rotation path. (D1)
- `docs/dns_management.md` describes the estate as it is; a reader can tell from
  it where every standing credential lives. (WP4, the DNS half)
- The fleet DNS-01 path and nodes 27/28 use the same mechanism — no bespoke
  arrangement survives for the two boxes. (D2)
- `dns.scrolldaddy.app` presents a production-CA certificate, renewed without
  human help, through two consecutive renewal cycles. (WP1 then WP8)

## Execution notes for the builder

This section is the build order. Follow it in sequence; each step ends with a
command whose output decides whether the next step starts. Two **stop points**
hand the work back to the reviewer before anything irreversible. The reviewer
is the owner's session; the owner runs every root and every by-hand command.

### Standing rules

- Never `git commit`, never `git add`. The owner commits from their own
  session; a shared index means staged work gets swept into someone else's
  commit.
- Root on the developer box is the owner's. When a step needs root on dev, on
  the Docker host, or on any node, print the exact command with absolute
  paths and stop; do not look for another way to get root. Never open an SSH
  session to a node yourself.
- **Before editing anything under `maintenance_scripts/install_tools/` on
  dev, the converger timer must be stopped.** The dev box runs the host
  converger from this very tree as root every minute; a change to any
  installer or template alters its hash and it runs your half-finished edit
  as root. Step 0 asks the owner for that. Confirm with
  `systemctl is-active joinery-host-converger.timer` printing `inactive`
  before the first edit, and again before any later session's first edit.
- Never issue a certificate from dev against a real name except where a step
  says so; Let's Encrypt counts failed validations per hostname per hour.
  Every shell test in this spec runs against fixtures in a temp root, never
  against `/etc/letsencrypt`.
- After every PHP edit: `php -l` and
  `php /var/www/html/joinerytest/maintenance_scripts/dev_tools/validate_php_file.php <file>`.
  The validator executes the file's top level, so never point it at a script
  that acts on include.
- After every shell edit: `bash -n <file>` and `shellcheck <file>` where
  installed.
- Working loop: `php tests/run.php --changed`. Pre-handoff gate:
  `php tests/run.php db --changed`. Never `live`; never any tier as root
  except `deploy`. Run from `public_html/`.
- Docs describe the end state only: no "now", "previously", "replaces".
- Bump the version header of every file touched; `install.sh` takes a
  `#VERSION` line per change with a one-paragraph note, in the existing style.
- Schema changes go through data classes and `update_database`, never
  migrations. WP3 adds one column; see the step.
- No new `/ajax/` endpoints; nothing here needs one.
- The two ScrollDaddy DNS boxes (WP1) are not in this build. The owner does
  WP1 by hand from the OPS guide; do not touch them or write for them.

### Where things are

- `maintenance_scripts/install_tools/install.sh` 2.70 —
  `provision_origin_cert()` (~line 975, the decision tree and the only
  `certbot` invocations), `write_universal_vhost()` (~line 890),
  `setup_ssl_docker_proxy()`, the retry-timer arming (`joinery-ssl-retry@`,
  ~line 751).
- `maintenance_scripts/sysadmin_tools/setup_ssl.sh` — sources `install.sh`
  for `provision_origin_cert` and calls it; the operator's re-entry.
- `maintenance_scripts/sysadmin_tools/arm_ssl_retry.sh` — installs
  `joinery-ssl-retry@<domain>.timer`, five-minutely, which waits until "the
  domain resolves HERE", then runs `setup_ssl.sh` once and disables itself.
- `maintenance_scripts/install_tools/render_vhost.sh` 1.5 — the renderer;
  the adoption rule and the recorded-render check live here.
- `maintenance_scripts/install_tools/_plugin_installers_start.sh` 2.8 — the
  converger runner; writes `cache/host_converger.last` every run
  (`LAST_FILE`, ~line 425); `CONVERGE_OUTCOME` values at ~365/543/560.
- `maintenance_scripts/install_tools/default_virtualhost.conf` 2.05 and
  `default_proxy_vhost.conf` 1.01 — both carry the `<IfFile
  /etc/letsencrypt/live/<domain>/fullchain.pem>` :443 block. Not edited in
  this build.
- `plugins/server_manager/tasks/RunNodeUptimeChecks.php` 2.1 —
  `check_cert_expiry()` at line 476 (the A-record gate is the
  `DnsResolver::getA` comparison ending "not our cert to monitor"),
  `cert_covers_host()` at 570, `renewal_due_ts()`, `send_cert_alert()`; the
  alert already *reports* overdue renewal in its body, it just does not
  *trigger* on it.
- `plugins/server_manager/plugin.json` — `server_manager_cert_expiry_warn_days`
  (default 21).
- `plugins/server_manager/tests/cert_renewal_verdict_test.php` (safe) — pins
  the renewal arithmetic.
- `includes/HostConvergerNotice.php` (`facts()`, `forState()`, `render()`)
  and `includes/VaultHealth.php` (`checkHostConverger()` returns
  `['key','label','state','reason']`, listed in `runAll()`) — the shape WP11
  copies.
- `tests/unit/installer_contract_test.php` (safe, 46 sections) — sections
  "A deferred certificate finishes on its own", "A site with no certificate
  is still reachable", "The vhost renderer adopts ..." are the ones this
  build extends.
- `tests/integration/host_converger_gate.sh` — renders the runner into a temp
  root; extend for WP11's summary file.
- `docs/deploy_and_upgrade.md` lines ~700–735 — the origin-SSL section WP4
  rewrites; `docs/dns_management.md:198` — the false justification.

### Step 0 — the owner stops dev's converger

Print, and wait for the owner to confirm it ran:

```
sudo systemctl stop joinery-host-converger.timer
```

At the end (Step 8) the owner starts it again, and its first run applies the
build to dev as root — that is the dev proof, not something you do by hand.

### Step 1 — WP1a, certbot out of the vhost

1. `install.sh`: the HTTP-01 branch of `provision_origin_cert()` becomes
   `certbot certonly --apache -d "$domain" --non-interactive --agree-tos
   --register-unsafely-without-email --deploy-hook 'systemctl reload apache2'`
   followed by one `systemctl reload apache2` so the `<IfFile>` block comes up
   on this install. `--apache` stays as the *authenticator* (it answers the
   challenge through the running vhost); it stops being the *installer*. The
   DNS-01 branch is already `certonly`; give it the same `--deploy-hook`.
2. `render_vhost.sh` → 1.6: on every run, for the site's domain, if
   `/etc/letsencrypt/renewal/<domain>.conf` exists and says
   `installer = apache`, rewrite that line to `installer = None` and ensure a
   `[renewalparams]` line `renew_hook = systemctl reload apache2`. Idempotent
   (a second run changes nothing); log one line when it changed something.
   Keep a `.before-render.<ts>` copy the first time only.
3. `render_vhost.sh` adoption rule: certbot's two known insertions —
   `Include /etc/letsencrypt/options-ssl-apache.conf` and the three-line
   `RewriteEngine on` / `RewriteCond %{SERVER_NAME} =<domain>` /
   `RewriteRule ^ https://%{SERVER_NAME}%{REQUEST_URI} [END,NE,R=permanent]`
   block — count as not an operator edit. A file that equals the recorded
   render plus any subset of those lines adopts. Nothing else is tolerated.
4. `installer_contract_test.php`: three pins. `install.sh` contains no
   `certbot --apache` invocation and every `certbot` line that issues carries
   `certonly` and `--deploy-hook`; `render_vhost.sh`, run in a temp root
   against a fixture `renewal/<domain>.conf` saying `installer = apache`,
   leaves it saying `installer = None` with the hook, twice; a fixture vhost
   equal to a recorded render plus the Include line plus the redirect block
   adopts, and the same fixture plus one unrelated line does not.
5. Gate: `php tests/run.php --changed` green. Then print for the owner the
   read-only check on jeremytunnell (`SSH_KEY=/home/user1/.ssh/joinery_provisioning_key`,
   `sudo grep installer /etc/letsencrypt/renewal/jeremytunnell.com.conf`) so
   the reviewer can see the before state; the after state arrives with
   0.8.387.

### Step 2 — WP2 and WP3, the plane watches every origin certificate

1. `RunNodeUptimeChecks::check_cert_expiry()`: delete the `DnsResolver::getA`
   comparison and its early return. Keep the transient-resolver early return
   only if it still guards something after the deletion; if it guards nothing,
   delete it too and say so in the handoff. `cert_covers_host()` is the sole
   "is this our certificate" test. Bump to 2.2.
2. Probe `www.<hostname>` as well when it resolves: same node address, SNI
   `www.<hostname>`, `cert_covers_host()` against `www.<hostname>`. A www that
   resolves and is not covered is its own alert reason ("www is not covered by
   the origin certificate; Strict would reject it"). Do not alert on a www that
   does not resolve.
3. WP3, the trigger: alert when `renewal_due_ts()` is more than one day in the
   past and the certificate on the wire is still the one that was due, or
   when `days_left < warn_days` as today, whichever comes first. The mail
   body already says which. `mgn_cert_alerted_ts` keeps its reset-on-renewal
   semantics. If tracking "still the one that was due" needs a stored
   fingerprint, add `mgn_cert_fingerprint` (`varchar(64)`, nullable) to
   `plugins/server_manager/data/managed_nodes_class.php` — a data-class
   change synced by "Sync with Filesystem", never a migration.
4. `cert_renewal_verdict_test.php`: extend with the trigger arithmetic (due
   yesterday + same fingerprint = alert; due yesterday + new fingerprint = no
   alert and reset; 25 days left, due in 5 = no alert). A new safe test for
   the www branch with an injected certificate array is fine; do not probe
   the network from a test.
5. Gate: `php tests/run.php --changed`, then `db --changed`.

**STOP POINT 1.** Hand the reviewer the diff list for Steps 1–2 and the gate
output. These two steps carry the 2026-09-17 date and ship in 0.8.387 even if
the rest waits.

### Step 3 — WP10, the installer issues for every install

The rule changes from "the name resolves to this box" to **"the name reaches
this box"**, which is what HTTP-01 actually requires and which is true both
direct and through an edge.

1. `install.sh`, new function `name_reaches_here(domain)`: write a nonce to
   `${SITE_ROOT}/static_files/reach-<nonce>.txt` (the data dir the vhost
   serves as files; root can write it, www-data owns it), fetch
   `http://<domain>/static_files/reach-<nonce>.txt` with `curl -sL
   --max-time 10` (follow redirects: an edge with "always HTTPS" redirects to
   https, and in Full mode the edge reaches an origin without a certificate),
   compare the body to the nonce, delete the file. Returns 0 when the body
   matches. Log which of three states was seen: reaches direct (resolves to
   our address), reaches through an edge (resolves elsewhere, body matched),
   does not reach (no match). Spends no Let's Encrypt budget.
2. `provision_origin_cert()`: attempt HTTP-01 whenever `name_reaches_here`
   succeeds; keep the own-address comparison only as the "direct / through an
   edge" word in the log line. Fall to DNS-01 only when HTTP-01 fails or the
   name does not reach. Add `-d "www.${domain}"` whenever `www.<domain>`
   resolves **and** `name_reaches_here www.<domain>` succeeds; a www that
   resolves to someone else is left out and named in the log.
3. `arm_ssl_retry.sh` / the timer's unit: the wait condition becomes
   `name_reaches_here` (through `setup_ssl.sh`, which already sources
   `install.sh`); the DNS-resolves-here check goes. The budget argument in the
   file's header is rewritten for the new gate: a failed *reach* costs
   nothing, so the five-minute cadence stays safe.
4. The Strict-before-first-certificate limit (B8): when the name resolves
   elsewhere and the reach probe fails over https specifically (http reached,
   the https hop did not), print the one sentence: the edge is refusing an
   origin without a certificate; set it to Full until the first certificate
   lands. Same sentence in the retry timer's journal line.
5. `installer_contract_test.php`: no HTTP-01 attempt is gated on an IP
   comparison (grep the function body: the `certbot certonly` line is
   reachable from a branch guarded by `name_reaches_here`, not by
   `server_ip4 = dns_ip4`); the issue line carries `www.` behind a resolve
   check; the retry timer's script calls the reach probe, not `dig`.
   `name_reaches_here` itself: the function honours `JOINERY_REACH_BASE_URL`
   only when set, so a test runs it in a temp root against a local
   `python3 -m http.server` on 127.0.0.1 serving that root and never needs
   DNS. Match, mismatch, and unreachable all pinned.
6. `docs/deploy_and_upgrade.md` origin-SSL section: rewritten around "reaches
   this box"; the three DNS states at install all end in the same place; the
   Strict-before-first-certificate sentence; the provider table stays as the
   DNS-01 fallback.
7. Gate: `php tests/run.php --changed`.

### Step 4 — WP11, the node tells its owner about renewal

1. `_plugin_installers_start.sh` → 2.9: after the installers, write
   `${SITE_ROOT}/cache/certificates.json` (www-data:www-data 0640, same
   mechanics as `host_converger.last`): for every lineage under
   `/etc/letsencrypt/live/*/cert.pem`: `names` (SANs), `not_before`,
   `not_after` (unix), `renewal_installer` (from the renewal conf), and
   `timer_active` (`systemctl is-active certbot.timer` or the cron.d file).
   No log parsing. An absent `/etc/letsencrypt` writes `{"lineages":[]}`.
2. `includes/CertificateNotice.php` (new, the `HostConvergerNotice` shape):
   `facts()` reads the JSON; `forState()` decides. States, in this order:
   no lineage and the site's name resolves to an edge → *advice* (Full until
   the first certificate lands, and how to issue: the `setup_ssl.sh` line);
   a lineage whose `not_after` is past → *unmet*; a lineage whose renewal was
   due (two thirds of life, the same arithmetic as `renewal_due_ts()`) more
   than one day ago and `not_after` unchanged → *unmet* "renewal is overdue
   since <date>; on the host: `sudo certbot renew` and read its output";
   `timer_active` false → *unmet* naming `systemctl enable --now
   certbot.timer`; the site's name or its resolving www not among `names` →
   *advice* "Strict would reject <name>"; otherwise *met* with the expiry
   date. The summary older than two days → *unknown* (the converger is the
   thing that is stale, and `checkHostConverger` already says so).
3. `VaultHealth::checkCertificates()` listed in `runAll()` after the
   converger row; `AdminNotices` registration next to the converger notice.
   Both take injected facts for tests.
4. Tests: `tests/unit/certificate_notice_test.php` (safe) — every state above
   from a fixed facts array and a fixed `now`; `host_converger_gate.sh` — the
   runner writes the JSON in the temp root from a fixture `live/` tree with a
   self-signed cert (`openssl req -x509` at test time), and writes the empty
   form when there is no `letsencrypt` dir.
5. Gate: `php tests/run.php --changed`; `bash tests/integration/host_converger_gate.sh`.

### Step 5 — WP5, the timer is asserted

`install.sh` server step: after installing certbot, `systemctl enable --now
certbot.timer` when systemd is present, else assert `/etc/cron.d/certbot`
exists. One line in `installer_contract_test.php`.

### Step 6 — the by-hand scripts (the owner runs them)

1. `maintenance_scripts/sysadmin_tools/issue_origin_cert.sh` (new, 1.0):
   `issue_origin_cert.sh <domain>` run as root on the box that terminates TLS
   for the name (a bare-metal node, or the Docker host for a container):
   runs the reach probe for the apex and www, prints the exact `certbot
   certonly` line it will run, asks `y/N`, runs it, reloads Apache, then
   verifies at `127.0.0.1:443` with SNI for each name that the presented
   certificate covers it. Never deletes anything. This is WP0's tool and the
   www re-issue tool for the three bare-metal names; `--expand` is what
   certbot needs when the lineage already exists, and the script passes it
   only then.
2. `maintenance_scripts/sysadmin_tools/strict_readiness.sh` (new, 1.0): run
   from dev, read-only: for a list of zone names, resolves apex and www,
   probes the origin address with SNI for each, and prints one line per name:
   covered / not covered / does not resolve. The reviewer runs it before and
   after each Step 8 flip. Takes the origin address per name on the command
   line; it must not guess.
3. Both scripts: `bash -n`, shellcheck, and an `installer_contract_test`
   section pinning that neither contains `certbot delete`.

### Step 7 — WP4, the docs

`docs/dns_management.md:198` — replace the false justification with what the
estate does: where each standing credential lives (the table from
"Measured"), and that ephemeral remains the rule for the platform's own DNS
writes. `docs/deploy_and_upgrade.md` — the strict-ready contract, one short
section: what a node guarantees an owner who flips Strict (apex + www
covered, renewal on a timer, the health panel says when it breaks), and the
one limit (Strict before the first certificate). `docs/settings.md` if WP3
added a setting. No "now", no "previously".

### Step 8 — the handoff (STOP POINT 2)

Hand the reviewer:

1. The full diff list grouped by WP, every version bump, the gate outputs
   (`php tests/run.php db --changed`, the converger gate).
2. The line for the owner to restart dev's converger
   (`sudo systemctl start joinery-host-converger.timer`) and what its first
   run should print in `logs/host_converger.log`: the renderer rewriting dev's
   renewal conf to `installer = None`, and `cache/certificates.json` written.
3. The rollout order: owner commits → publish 0.8.387 → jeremytunnell first
   (its renewal window opens 2026-09-17; after the upgrade
   `/etc/letsencrypt/renewal/jeremytunnell.com.conf` must say
   `installer = None`) → the fleet.
4. WP0, in order, for the owner to run on the Docker host with
   `issue_origin_cert.sh`: the five names. Then the www re-issue on
   jeremytunnell, dev and developers with the same script. Then
   `strict_readiness.sh` for all six zones must print every name covered.
5. WP6, per zone, in the Cloudflare dashboard: flip to Full (Strict) only for
   a zone `strict_readiness.sh` reports fully covered; re-run it after each
   flip; a site that answers 526 was flipped too early — set it back to Full
   and re-check. Do not flip anything yourself.

Do not publish. Do not move this spec; the reviewer decides when the
acceptance list is met.

### Review round 1 (2026-09-11) — what STOP POINT 2 sent back

Everything through Step 7 was read and its tests re-run by the reviewer
(installer contract 522, certificate notice 41, renewal verdict 31, vault
health 59, converger gate 59, all green; validator clean). Build these, then
hand off again the same way.

- **R1 — WP12, the placeholder (B10).** As specified above. Version bumps:
  install.sh, both templates, render_vhost.sh, `vhost_history/` gains the
  2.05 template so 2.05 boxes adopt. Run the pins. On dev the converger
  applies it; the reviewer checks `openssl s_client` at the origin still
  presents the Let's Encrypt certificate afterwards (dev has one; the
  placeholder must not win).
- **R2 — 525 and 526 are two messages.** In `name_reaches_here`, when the
  https hop fails: 526 → "the edge is on Full (Strict) and refuses the
  placeholder; set it to Full until the first certificate lands"; 525 (or no
  response) → "this box did not complete a TLS handshake; the placeholder is
  missing — run render_vhost.sh". Same two sentences in the retry timer's
  journal line and in `issue_origin_cert.sh`. Return code 2 for 526 only;
  525 is return 3.
- **R3 — the plane reports an uncovered origin.** In
  `RunNodeUptimeChecks::check_cert_expiry()`, a node that answers TLS on its
  address but presents a certificate that does not cover its name is a third
  alert reason, `uncovered`: "the origin presents a certificate for <names>,
  not for <hostname>; Strict would take the site down". Same alert cadence
  as the others; `mgn_cert_expiry_ts` is not stored for a foreign
  certificate. Pin it in `cert_renewal_verdict_test` from an injected
  certificate array. The five container sites trigger it until WP0 runs,
  which is the point.
- **R4 — small ones.** (a) The www resolve check in `provision_origin_cert`
  and `issue_origin_cert.sh` accepts a CNAME target made of hex letters as an
  address; resolve www the way the runner does (`getent ahostsv4`) or filter
  with the IPv4 and IPv6 patterns separately. (b) When `fix_permissions.sh`
  fails, the runner logs the fact and not the reason; capture the script's
  stderr into the log line. (c) `note_ssl_deferred_if_missing` prints a shell
  glob as the path to run; print `${SITE_ROOT}` resolved.
- **R5 — the handoff's dev claim.** The first converge after a runner edit
  runs the stale sbin copy, which refreshes itself for the run after; the
  certificate summary appears on the second converge, not the first. Say so
  in the handoff, and give the reviewer the trigger: saving a help doc queues
  a root request, which starts a converge at once. The same two-step happens
  on every node after the upgrade, so the health row is a day late there.

## Related

- `docs/dns_management.md` — the driver subsystem WP8 consumes, and the
  ephemeral-only rule whose justification B1 corrects
- `agent_local_queue_retirement.md` — G2, which WP8 dissolves
- `agent_machine_posture_and_relay_converge.md` — R3, which WP8 supersedes
- `agent_management_first_principles.md` — the credential doctrine WP8 obeys
- `plugins/server_manager/includes/SslProvisionOutcome.php` — the fleet DNS-01 path
- `maintenance_scripts/install_tools/install.sh` — `provision_origin_cert()`, the
  two-step issuance path every new node takes
- `plugins/server_manager/tasks/RunNodeUptimeChecks.php` — the alert, B2 and B3
- `plugins/server_manager/tests/cert_renewal_verdict_test.php` — pins the
  renewal arithmetic
- `/etc/scrolldaddy/OPS_GUIDE.md` on each DNS node — the current arrangement
