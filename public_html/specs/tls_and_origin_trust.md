# TLS across the estate: issuance, renewal, and origin trust

**Supersedes and replaces two specs**, merged 2026-09-10 at the owner's
request: `origin_tls_and_certificate_issuance.md` (written 2026-08-30) and
`certificate_lifecycle.md` (written 2026-09-10, itself filed first as
`certificate_issuance_without_standing_dns_credential.md`). They overlapped at
one work package and shared one unanswered question; kept apart they would have
been built twice. Nothing from either is dropped — where they disagreed, this
file says so.

## Status is deliberately mixed. Read this before picking anything up.

| Half | Status |
|---|---|
| **Origin trust** — the mismatch, Full (Strict), Authenticated Origin Pulls, the firewall | **DEFERRED by owner 2026-08-30.** Unchanged by this merge. Every site serves fine; what is wrong is the strength of one hop and the existence of a bypass. |
| **Certificate lifecycle** — issuance, renewal, and knowing when renewal breaks | **OPEN.** Three findings, two of them cheap to fix and one with a dated deadline. |

Two items are not deferred and not optional:

- **2026-09-30 21:58 UTC** — the ScrollDaddy DoH certificate lapses. Every
  customer device fails its TLS handshake. WP1 below.
- **B3** — three origin certificates renew with nothing watching them. This
  **gates WP6** (Full (Strict)), because strict checking is exactly what turns
  an unnoticed expiry into an instant outage.

## The problem in plain terms

Three separate things, which is why this kept looking like two specs.

**One.** A visitor's connection to one of our sites is encrypted twice: browser
to Cloudflare, then Cloudflare to the machine. The first hop is fine. On the
second, five of eight sites answer with a certificate belonging to a different
site — so Cloudflare cannot be checking who it is talking to. It encrypts, and
accepts whatever answers. Separately, those machines answer the public internet
directly, so the edge can be skipped by connecting to the address. Neither is
visible to a visitor. Both mean the protection is thinner than the padlock
implies.

**Two.** To get a certificate at all, a machine must prove it controls the
name, and there are two ways to do it. It can **answer a knock on the door** —
Let's Encrypt visits the name over the web and asks for a file it named. That
needs no passwords, and it is what almost every machine here does. Or it can
**leave a note in the public directory** that turns names into addresses. That
one requires a key to write in the directory — and the key sold is not "you may
write this one line", it is "you may edit every record for this domain".

Two of our machines are stuck with the second method, so each holds a key that
can rewrite all of `scrolldaddy.app`, in a plain text file, on a box facing the
public internet, to do sixty seconds of work every two months. Both hold the
same key. On 2026-08-31 it stopped being accepted, and nobody found out for ten
days.

**Three.** The renewal that works is not the renewal that is watched. Automatic
renewal on Joinery nodes genuinely works — that is measured below, not assumed.
But the monitor skips every machine sitting behind Cloudflare, so most
certificates renew unobserved. Today that is invisible. The day Full (Strict)
goes on, it stops being invisible in the worst possible way.

Throughout this spec the two proof methods are called by their ACME names,
**HTTP-01** (the door knock) and **DNS-01** (the note in the directory).

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
SSL vhost of their own. All eight containerised nodes share Docker host
`23.239.11.53`.

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

## Why the two DNS boxes cannot use HTTP-01

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

## Issuance: DNS-01, and the machinery that already exists

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

## Options for the standing credential

### O1 — Delegate the challenge to a throwaway zone

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

### O2 — Plane-side issuance: the node sends a request, never a credential

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

### O3 — Non-expiring credential, louder alerting

Mint a Cloudflare token with no expiry, keep the arrangement, fix B2 and B3.

- **Pro:** about an hour of work, no design commitment.
- **Con:** fixes the clock and not the blast radius. Two internet-facing boxes
  keep zone-wide edit rights, and the fleet path keeps minting more.

### O1 + O2 — the reason to prefer O1 first

If the zone delegated *to* is one the platform serves authoritatively itself,
no third-party DNS credential exists anywhere: not on the node, not on the
plane. The proof-of-control record is a row in our own database. That satisfies
the ephemeral-only rule honestly instead of displacing it, closes O2's blocker
2, and is reusable for every fleet node and every customer domain.

The cost is honest: new authoritative-DNS infrastructure, small but real
(`acme-dns` is precisely this and is one Go binary), which must be run and
monitored like anything else. `scrolldaddy-dns` is a *resolver* and does not
give us this for free.

## Open decisions

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
This file is the result. Recorded rather than deleted so a reader does not
re-open it.

## Work packages

Ordered. WP1–WP5 do not wait on any decision; WP6–WP7 are the deferred
origin-trust half; WP8–WP9 wait on D1.

- **WP1 — Restore ScrollDaddy renewal. Deadline 2026-09-30.** New Cloudflare
  credential, both boxes, `daemon-reload`, restart Caddy so the next attempt
  goes to production rather than staging. Confirm a fresh certificate lands.
  Buys the time to decide D1.
- **WP2 — Fix B3: watch every origin certificate.** Remove the A-record gate in
  `check_cert_expiry()` and let `cert_covers_host()` decide. **Prerequisite for
  WP6.** Cheap now, expensive late.
- **WP3 — Fix B2:** alert on renewal overdue, not only on expiry approaching.
- **WP4 — Correct `docs/dns_management.md`.** Replace the false justification at
  line 198 with what the estate actually does, and say where each standing
  credential lives.
- **WP5 — Assert `certbot.timer` in `install.sh`** rather than inheriting it
  from the package, so a change of install method cannot silently disable
  renewal on every new node.
- **WP6 — Close the mismatch, then turn on Full (Strict).** Issue a per-name
  certificate and SSL vhost for the five, with today's certbot, by hand. This
  is the whole of the live origin defect. Then Full (Strict) per zone — after
  the mismatch is closed **and after WP2**, never before: enabling it while a
  mismatch stands takes those sites down, and enabling it while renewals are
  unwatched arms a silent failure.
- **WP7 — Authenticated Origin Pulls and the origin firewall.** Closes B5.
  Needs D5.
- **WP8 — Implement the chosen option** from D1, including the fleet path in
  `SslProvisionOutcome.php` and `install.sh`, not only nodes 27 and 28.
- **WP9 — Retire `build_provision_ssl`** and its `local` + `ssh` steps, which
  WP8 makes unnecessary. Removes one of the thirteen local-queue dependants.

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

- Every site name presents its own valid certificate at the origin under SNI.
- Cloudflare is Full (Strict) for every proxied zone.
- The origin refuses a connection that did not come through the edge, except
  for nodes deliberately served direct.
- A certificate issues and renews with no inbound HTTP to the node.
- `grep` finds no `local` or `ssh` step in any certificate path.
- No machine holds a DNS-write credential broader than the job it performs, and
  every credential that exists has a recorded owner and a rotation path.
- `docs/dns_management.md` describes the estate as it is; a reader can tell from
  it where every standing credential lives.
- A renewal that starts failing is reported within one day, not at expiry minus
  twenty.
- Every certificate the estate serves is watched by something, including the
  origin certificate behind an edge.
- Renewal on a new node does not depend on a default we did not set.
- The fleet DNS-01 path and nodes 27/28 use the same mechanism — no bespoke
  arrangement survives for the two boxes.
- `dns.scrolldaddy.app` presents a production-CA certificate, renewed without
  human help, through two consecutive renewal cycles.

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
