# DNS Record Management

**Status: BUILT 2026-07-25.** All six build-plan steps are implemented: the core
interface and plan types (`includes/dns/`), all fifteen drivers, the ownership
store (`dnr_dns_records`), the reconciler, the mailbox consumer, and the node
provisioning consumer. Documented in `docs/dns_management.md`; covered by
`tests/dns/` (189 checks, safe tier, no credential or network needed).

Three things to know about how it landed:

- **Only the connect-your-own-account topology is offered.** Owner decision,
  2026-07-25: the platform-authoritative path — create the zone in our account
  and ask the owner to delegate their nameservers — is not supported, and the box
  never prompts for it. Instead it *detects* where a domain's DNS already lives
  (NS records matched against each driver's `nameserverSuffixes()`, walking up
  the label chain for subdomains) and leads with that host. The "Point your
  nameservers at …" panel and its whole-zone warning are gone: configuring a
  domain where it already is has no blast radius, while moving a zone takes the
  website with it and belongs in the owner's registrar, not next to a mail
  checklist. Everything below about delegation, staged zones and NS-switch
  detection describes a path that was deliberately not built.

- **The order is diff → authorize → write.** The state table below reads
  "Ready → *Publish DNS via Linode* — authorizes, then shows the diff". Building
  the diff needs no credential (as this spec argues at length), and authorizing
  before the diff would leave a live grant sitting between two requests. So the
  primary action reveals the diff credential-free and **Apply** is what
  authorizes and writes, inside one request. Same states, same rail, nothing at
  rest.
- **Live verification is the remaining owner step.** Linode and Namecheap are
  the two the spec names, and both need real credentials to exercise. Every
  driver ships with quirk-level unit tests; none has been run against its vendor.

## What this is

Today a deployment is told what DNS to publish and a human publishes it. The
platform already computes the exact records — it renders them as copy-paste
instructions and then re-reads DNS to see whether the human got it right. This
closes that loop: the platform publishes the records itself, and a setup page
becomes a page that *sets* rather than a page that checks.

Scope is the record layer only. **The platform never becomes a nameserver.** It
holds the desired state and writes it through a DNS host that stays responsible
for actually answering queries. The host is a swappable driver; **Linode DNS is
the v1 default**, matching the servers-first-on-Linode bet, with the full
provider roster behind the same interface.

## Why

Every DNS defect this platform has produced was a human-in-the-loop defect, not
a knowledge defect. The system knew the right answer each time:

- Records simply not published — the instruction was read and not acted on.
- A record published at the wrong provider, or in the wrong zone of a
  same-named sibling domain.
- A TXT value over 255 bytes published unquoted: accepted by the API, stored,
  and then never served. No error at either end.
- A provider-managed record silently refusing writes, so the intended change
  never landed and nothing said so.
- Records published but the provider never asked to re-verify them, leaving a
  sending domain unverified with everything correct underneath.

A reconciler turns each of these into a visible diff. That is the whole value:
not saved keystrokes, but the disappearance of a class of error where the
system is right and the deployment is wrong and nothing connects the two.

## What already exists

- **`InboundEmailSetupCheck::dnsFix($type, $name, $value)`** — every mail record
  the platform wants, already structured, already per-domain, already verified
  against live DNS. This is the desired state; it is currently only ever
  rendered.
- **`includes/cloud_compute/`** — `CloudComputeProvider` interface plus
  `LinodeComputeDriver`, promoted to core when a second subsystem needed it. The
  shape to copy: thin interface, one driver per vendor, credentials outside the
  interface.
- **`includes/email_providers/`** — ten drivers behind one interface, including
  the opt-in capability pattern (`DkimRecordSource`) where only some providers
  implement an extra surface. Directly applicable: not every DNS host will
  support every operation.
- **`includes/oauth/`** — the OAuth2 core: authorization-code-with-refresh
  grant, a provider registry, one shared callback, and `SecretBox`-encrypted
  token storage. `LinodeOAuthProvider`, `GoogleOAuthProvider` and
  `MicrosoftOAuthProvider` already ship. Any DNS host that speaks OAuth2 rides
  this instead of asking a human to paste a key — a new host is a new
  `OAuth2Provider`, nothing more.

## Integration points (inventory first, decide once)

Everything that wants DNS today, so the interface is not designed around mail
and retrofitted afterwards:

| Consumer | Records |
|---|---|
| Mailbox domain setup | MX, SPF TXT, DKIM TXT, DMARC TXT, A for the mail hostname |
| Mailbox relay / fleet | MX to the relay hostname, per-tenant hostnames, TXT ownership claims |
| Node provisioning | A (and AAAA) for a node's site domain — today an owner action the SSL gate waits on |
| Certificate issuance | `_acme-challenge` TXT, if DNS-01 is ever wanted alongside the current path |
| Managed domain registration (`specs/managed_domain_registration.md`) | the initial record set for a domain bought through the platform |

Four of the five are outside the mailbox plugin. **This belongs in core**
(`includes/dns/`), with the mailbox plugin as its first consumer, not its owner.

## Design

### The desired state is a plan, not a page

A `DnsRecordPlan` is a list of `DnsRecord` values — `type`, `name`, `value`,
`ttl`, `priority`. The vocabulary is A, AAAA, CNAME, MX, TXT and **CAA** — CAA earns its
place because a wrong or missing CAA record blocks certificate issuance in the
same silent way a missing challenge record does, so a subsystem that cares about
certs can express it. `NS` and `SOA` are never in a plan. `ttl` is optional: a
record that omits it means *provider default*, and a live record whose only
difference from the plan is a TTL the plan never asked for **matches** rather
than **differs** — so a zone left on default TTLs never shows permanent diff
noise. Any subsystem can produce a plan for a domain.
`InboundEmailSetupCheck` gains a method returning its plan; the rendered
instructions become one consumer of that plan rather than its only form.

### The provider is a driver

`DnsProvider` — `zoneFor($domain)`, `list($zone)`, `create()`, `update()`,
`delete()`. One driver per vendor, each owning its vendor's quirks so no caller
ever learns them. **Linode DNS is the v1 default and the reference driver**: the
platform already runs servers on Linode, the OAuth2 provider already ships, and
the DNS API (`/v4/domains`) sits on the same API base the compute driver already
calls — so `LinodeDnsDriver` is the compute driver's HTTP plumbing with the
`domains:read_write` scope. Servers-first-on-Linode and DNS-first-on-Linode are
the same bet.

The full roster ships behind the one interface. Credential mode is a per-driver
capability — **OAuth2 where the vendor offers it, a scoped API key where it does
not:**

| Provider | Credential | Notes |
|---|---|---|
| **Linode DNS** *(v1 default)* | OAuth2 · `LinodeOAuthProvider` | No refresh token, 2-hour access token — inherently ephemeral, re-consent at publish. |
| Google Cloud DNS | OAuth2 · `GoogleOAuthProvider` | Zone lives in a GCP *project*; the driver selects it. |
| Azure DNS | OAuth2 · `MicrosoftOAuthProvider` | Zone under a subscription / resource group; the driver selects it. |
| DigitalOcean | OAuth2 · new provider | — |
| DNSimple | OAuth2 · new provider | — |
| Cloudflare | API token (scoped, DNS-edit) | No OAuth2 for the DNS API. Proxying + Email-Routing quirks below. |
| AWS Route 53 | API (IAM key/secret, SigV4) | No OAuth2. Zone = hosted zone id. |
| Namecheap | API (key + user + IP allowlist) | No OAuth2. The IP allowlist is a real deployment constraint. |
| GoDaddy | API (sso-key) | No OAuth2. |
| Gandi | API (personal access token) | No OAuth2. |
| Vultr | API (bearer PAT) | No OAuth2. |
| Hetzner DNS | API (token) | No OAuth2. |
| Porkbun | API (key + secret) | No OAuth2. |
| deSEC / Name.com | API (token) | No OAuth2. Round out the long tail. |

Quirks are the driver's job, not the caller's. Some are general and every driver
handles them; some belong to one vendor:

- **Long TXT values** (general) are split into adjacent quoted 255-byte strings.
- **Zone resolution** (general) is longest-suffix match against the zones the
  credential can see, so `mail.example.com` resolves to the `example.com` zone
  and a sibling TLD cannot be hit by accident.
- **Proxying / the orange cloud** (Cloudflare). Creating an A or CNAME through
  the Cloudflare API applies the zone's default proxy setting. A *proxied* mail
  host or node record makes the world resolve Cloudflare's IP instead of the
  server's — breaking mail and hiding the real address from the very SSL gate
  that is waiting on it. The driver forces DNS-only (`proxied=false`) on every
  record it writes; proxying is never something a plan can opt into. This is the
  most dangerous quirk because it fails exactly the silent way the whole
  reconciler exists to end: the write succeeds and the wrong thing resolves.
- **Provider-managed records** (Cloudflare Email Routing owns MX and its own
  DKIM) are reported as a named refusal — *this record is managed by Email
  Routing; disable it in the Cloudflare dashboard* — not as a generic failure.
- **Post-write verification hooks** (general), where a vendor must be told to
  re-read DNS before it will trust a record.

### Two topologies, one interface

A driver abstracts the vendor's API; it does not decide who is authoritative.
That is a separate axis, set by whose account holds the zone:

- **Platform-authoritative** — the platform creates the zone in *its own* Linode
  (or other) account and the domain owner delegates their nameservers to it.
  This is the v1 Linode-first path: buy the domain anywhere, point its
  nameservers here, and the platform owns the whole zone. Conflicts barely
  arise because the zone starts empty. The zone is created in the account when
  the domain is added — so records can be staged before the switch — and the box
  detects the switch by resolving the domain's live NS records against the
  provider's nameserver set, going *ready* only once they match.
- **Connect-your-own-account** — the owner grants access (OAuth2 or a scoped
  key) to a zone *they already run* — commonly Cloudflare or Route 53 — and the
  platform writes only its records into that existing zone, alongside their web
  and everything else. Here the ownership, adoption and conflict machinery below
  earns its keep, because the zone is full of records the platform did not
  create.

The delegation topology moves the **entire** zone to the platform's nameservers,
not just mail records — ideal for a greenfield or platform-bought domain,
disruptive for a domain with live production DNS elsewhere. The page states this
before delegation, so no one points a working domain here and drops their
website. For domains bought *through* the platform
(`specs/managed_domain_registration.md`), the registrar API sets nameservers to
the platform's DNS automatically at purchase — those are genuinely one-click;
only externally-registered domains need the manual nameserver step.

### The write credential: OAuth2 where possible, never stored

Building the diff needs no credential. Live DNS is public and the setup check
already resolves it, so the entire reconciliation value — the visible diff, the
adoption bookkeeping, the conflict detection — is a read and costs nothing at
rest. A deployment gets the whole safety surface without holding any secret. A
credential is required only for the **write**.

How that credential is obtained is the driver's declared capability, and the
order of preference is fixed:

- **OAuth2 when the vendor offers it** (Linode, Google, Azure, DigitalOcean,
  DNSimple). The publish step runs the existing OAuth2 consent flow for a
  DNS-write scope, uses the resulting access token for that one publish, and
  discards the grant — the refresh token is never persisted. No key ever touches
  a form field. Linode makes this automatic: it issues no refresh token and its
  access token lasts two hours, so a Linode grant is ephemeral by construction.
- **A scoped API credential when it does not** (Cloudflare, Route 53, Namecheap,
  GoDaddy, Gandi, Vultr, Hetzner, Porkbun, …). Scoped as narrowly as the vendor
  allows — one zone, DNS-edit only, short-lived where the vendor supports it —
  supplied at the moment of publishing and discarded when the request returns.

**Ephemeral is the only mode. Nothing DNS-write-capable is ever stored — not
even sealed.** A write is a setup-time event with an admin present, so the
credential lives for the one publish request and is gone. This is a deliberate
constraint, not a limitation we regret, and nothing in the platform forces us
off it:

- **Drift is detected without any credential.** Detection is a public-DNS read
  the setup check already performs; only *auto-fixing* drift with no human wants
  a standing credential, and an admin re-publishing does the same job attended.
  Unattended drift-fixing is explicitly out of scope for exactly this reason.
- **Certificates need no DNS write.** Issuance and renewal run through
  `certbot` on each node over HTTP-01 (Cloudflare-proxied domains terminate TLS
  at the edge and skip it entirely). The control plane never writes DNS for a
  cert, so no cert timer ever needs a stored key.
- **The one future exception is named and deferred.** Wildcard certificates
  require DNS-01, which the platform does not do today. If that is ever wanted,
  it gets a challenge-only credential or `_acme-challenge` CNAME delegation
  scoped to nothing but the ACME record — never a stored full-zone editor — and
  that decision belongs to that spec, not this one.

**Account selection needs nothing stored.** A grant reaches one or more provider
accounts. When it reaches exactly one — the common case — that account is used
with no question asked. When it reaches several (a reseller or parent-child
Linode login), the publish box asks which, once, as a one-click choice, not a
saved identity. A grant can only manage zones in accounts it reaches: if a later
publish authorizes a different login, it acts as that login's account — correct
behaviour, not a fault — and the diff simply reflects what that account holds.
Ambiguity is resolved at the moment it appears, so no account identifier is ever
persisted.

Whatever the mode, the driver still does longest-suffix zone resolution, so
`mail.example.com` resolves to the `example.com` zone the credential can see and
a sibling TLD cannot be hit by accident. A credential that cannot see a domain's
zone falls back to rendered instructions for that domain — exactly as a
deployment that supplies no credential renders the plan and writes nothing. That
is a supported state, not a degraded one — see [[project-zero-config-install]].

### Reconciliation is a diff, and the diff is the UI

For each record in the plan, comparing against live provider state yields
exactly one of: **matches** (nothing to do), **missing** (create), **differs**
(update, showing before and after), **conflicts** (a record exists that the
platform does not own — never silently overwritten).

The page shows the diff and a single action to apply it. Nothing is written
without the diff having been displayed.

**Applying is per-record and best-effort.** A plan is several records and the
apply is several provider calls. If one fails — a rate limit, a transient error,
a managed-record refusal — the others still land and the failed record reports
its own reason. There is no all-or-nothing transaction, because the only
"rollback" would be another write; re-running publish converges whatever is
left, which is exactly why re-publish on a correct domain is a no-op.

**Green means confirmed written, not re-resolved.** DNS propagation lags the
write, so a record's immediate post-publish state trusts the provider's write
receipt rather than re-reading public DNS in the same request. The live-DNS
reconciliation the setup check already performs catches up on its next pass —
the page never claims a record resolves before it can.

### Ownership is explicit

The platform manages only records it created or adopted. A `dnr_dns_records`
table records domain, type, name and the owning subsystem. Consequences:

- It never deletes a record it does not own.
- Removing a domain from the platform offers to withdraw the records it owns,
  and touches nothing else. In the authoritative case the whole zone is the
  platform's, so withdrawal also offers to delete the zone — but only when it
  holds no records the platform does not own; a zone with foreign records is
  never deleted.

`NS` and `SOA` are never written under any circumstances.

**Ownership is acquired by agreement, not only by authorship.** A record whose
live value is already exactly what the plan wants is adopted on first publish
without touching DNS: the platform and the zone already agree, so recording who
is responsible is bookkeeping, not a change. A record that exists and *differs*
is a conflict — shown with both values, resolved by an explicit *adopt and
overwrite* choice, never silently.

The alternative, where ownership comes strictly from having created a record,
was rejected for punishing exactly the deployments that did the work by hand:
every correct record they published would sit permanently unowned, showing as a
conflict the platform could not act on until it was deleted and recreated
through the platform. Adopting on exact match means a domain that is already
correct converges with no clicking and no DNS write.

### Adding a domain publishes what is safe to publish

Adding a domain reconciles it immediately, but only the outcomes that cannot
take anything away: records the zone does not have at all. SPF, DKIM, DMARC and
new hostnames appear without a second step, because creating a record where
none exists breaks nothing — the worst case is a record nothing yet uses.

Everything else waits. A cutover, and a conflict with an unowned differing
value, still require their own explicit confirmation. So adding a domain does
the bulk of the work by itself and leaves exactly the decisions that deserve a
human in front of them, rather than making someone press a button to do the
part that was never in question.

This is the shape of the simplification being asked for. A page that requires
confirmation for everything, including creating a TXT record in empty space,
has moved the checklist rather than removed it.

### Cutovers stay deliberate

Creating a TXT record breaks nothing. Replacing MX moves live mail. Records
whose change redirects existing traffic — MX, and A records for a host already
resolving elsewhere — are flagged in the diff and require their own
confirmation, stating what currently receives and that it will stop.

### The page: one box, Linode by default

The Setup tab gains a single **publish box** above the copy-paste table it
already renders. It defaults to the deployment's DNS provider — Linode today (the
labels below name it concretely) — and shows one primary action; the provider
list hides behind a **"use another provider"** link, so the common path never
sees a chooser. The box carries a little state, not just a button, because the
honest steps cannot all be one click:

| State | What the box shows |
|---|---|
| Not delegated | *Point your nameservers at Linode* — the one-time registrar step (skipped for platform-bought domains, whose nameservers are set at purchase). |
| Ready | **Publish DNS via Linode** — authorizes, then shows the diff. |
| Diff shown | The four outcomes, with cutovers (MX, live-A) called out for their own confirmation. **Apply** writes; nothing is written before the diff is on screen. |
| All green | *DNS is published ✓* — re-check is a credential-free read, always available. |
| No provider | The existing copy-paste table, unchanged — the fallback, not a degraded state. |

"Use another provider" swaps the default for any driver in the roster; an OAuth2
provider authorizes by consent, an API-credential provider takes a scoped key at
the publish moment. Neither changes the box's shape or its states — only how the
one publish is authorized. Nothing about the chooser persists a credential. A
provider with a setup prerequisite surfaces it in the box before it can publish —
Namecheap, for instance, shows *allowlist this server's IP in your Namecheap
account* until the API accepts a call, rather than failing silently.

The chooser leads with the default and tucks every other driver behind the link
with **no tier labels** — it is a list of providers, not a ranking, and the fact
that most are not yet live-tested is not surfaced as a warning. The
diff-before-write rail is what makes that safe: any driver, whichever way it was
built, can only write what the user saw and confirmed on screen, so an untested
driver misbehaving shows up as a wrong diff to decline, never a silent bad write.

### The default provider is a preference, not a hardcoded vendor

Leading with Linode is a *default*, not a coupling. DNS hosting is independent of
where a server runs: a Linode zone can hold the A record for a node on any
compute provider — the record simply points at that node's IP. Adding VPS
providers later changes nothing here; Linode DNS keeps serving as the default no
matter where compute lives.

The one thing to get right now is to not bake the string "Linode" into the page.
The default is a **deployment preference** — `dns_default_provider`, a settings
value that is a provider *name*, never a secret — resolved at render time and
defaulting to Linode with no configuration (see [[project-zero-config-install]]).
When a second DNS provider is worth making canonical, an admin changes one
setting; the chooser already lists every driver that ships, so nothing else
moves. The provider dropdown is populated from the driver registry, not a
hardcoded list, so a newly added driver appears without touching the page.

Optional future sugar, not built now: because a node already knows its compute
provider, a `CloudComputeProvider` may later declare a companion DNS driver, so a
node provisioned on a vendor that also hosts DNS defaults its records to that
same vendor. The existing `includes/cloud_compute/` interface is the slot for
that capability — the same opt-in pattern as `DkimRecordSource`. Until then, the
deployment preference is the only default, and it is Linode.

## Build plan

The full roster is v1 scope. Linode is built first only because it is the
reference that locks the interface; the rest of the drivers follow immediately
behind the same interface, before the consumers rather than after them.

1. **Core interface, plan types, and the reference driver.**
   `includes/dns/DnsProvider.php`, `DnsRecord`, `DnsRecordPlan`, and
   `LinodeDnsDriver` reusing `LinodeOAuthProvider`. The credential is ephemeral —
   the only mode — request-scoped, nothing at rest, no persistence path in the
   code at all. Tests cover the quirks directly: long-TXT splitting, zone
   longest-suffix match, and that no credential survives a publish.
2. **The rest of the roster — built, most unverified.** Every remaining driver
   behind the one interface: OAuth2 (Google, Azure via the shipping providers;
   DigitalOcean and DNSimple as new `OAuth2Provider`s) and API-credential
   (Cloudflare with its proxying + Email-Routing quirks, Route 53, GoDaddy,
   Gandi, Vultr, Hetzner, Porkbun, and the deSEC / Name.com tail). Every driver
   ships with quirk-level **unit** tests (long-TXT split, zone match — no live
   credential needed); none changes a caller, and none persists a credential.

   **Live verification for v1 is Linode and Namecheap only** — deliberately the
   two that between them exercise both credential modes (OAuth2 and API key) and
   both topologies (authoritative delegation and registrar-set nameservers).
   Every other driver ships **built but not live-tested**. This is not surfaced
   in the UI as a tier or a warning — the chooser lists providers plainly — and
   the diff-before-write rail is what makes that acceptable: real-world quirks in
   an untested driver surface as a wrong diff the user declines, not a silent bad
   write, and beta reports drive the fixes. A chosen trade — breadth now,
   correctness proven on two — not an oversight.
3. **Ownership store.** `dnr_dns_records`, adoption, withdrawal.
4. **Reconciler.** Plan versus live diff, the four outcomes, per-record
   best-effort apply, the cutover flag.
5. **Mailbox consumer.** `InboundEmailSetupCheck` exposes its plan; the Setup
   tab gains the publish box and the diff (see UI below); the copy-paste
   rendering remains for deployments with no provider.
6. **Node provisioning consumer.** The A record the SSL gate waits on becomes
   publishable — an owner authorizes DNS at provision time and the record is
   written with a diff, rather than hand-typing it into a dashboard. Attended,
   not zero-touch: the ephemeral-only credential means cloud node birth still
   has a human at the publish step, it just no longer has a copy-paste step.

## Documentation

- New `docs/dns_management.md` — the interface, the driver contract, ownership
  rules, and how a subsystem contributes a plan.
- `plugins/mailbox/docs/domain_onboarding.md` — the record set becomes something
  the platform publishes; the manual sequence stays for unmanaged domains.
- `docs/plugin_developer_guide.md` — how a plugin declares DNS needs.

## Acceptance

1. A domain with no records gets a complete, correct record set from one action,
   and the setup check goes green without anyone opening a DNS dashboard.
2. A TXT value over 255 bytes is published and resolves.
3. A record the platform does not own is never modified without an explicit
   adopt choice.
4. A provider-managed record produces a refusal naming the feature to disable.
5. An MX change that redirects existing mail requires its own confirmation and
   names what currently receives.
6. A deployment that authorizes no DNS credential behaves exactly as it does
   today, with no new required configuration — the plan renders and nothing
   writes.
7. Re-running publish on an already-correct domain writes nothing and reports
   no changes.
8. A domain whose live records already match the plan is adopted on first
   publish with no DNS write, and shows no conflicts afterwards.
9. Adding a domain creates its missing records without a second step, and does
   not perform a cutover or overwrite a conflicting unowned record.
10. No DNS credential is ever written to disk — not even sealed; after any
    publish returns, nothing DNS-write-capable remains at rest. A credential that
    cannot see a domain's zone falls back to rendered instructions for that
    domain.
11. An A record written through Cloudflare resolves to the server's own IP, not a
    proxy address — a publish never enables proxying.
12. When one record in a multi-record publish fails, the others still land and
    the failed record reports its own reason; re-running publish converges the
    remainder and then writes nothing.
13. A Linode DNS publish is authorized by OAuth2 consent with no key pasted into
    a form, and leaves nothing at rest (Linode issues no refresh token).
14. A domain delegated to the platform's nameservers is told, before delegation,
    that the whole zone moves — not only its mail records.
15. An OAuth2 publish through a provider that *does* issue refresh tokens
    (Google, Azure, DigitalOcean, DNSimple) still persists nothing — the grant
    is used for the one publish and discarded, refresh token included.

## Open decisions

None. Owner-resolved 2026-07-25: Linode-first for both servers and DNS; ship the
full provider roster in v1 (Linode built first only as the interface-locking
reference), live-verified for Linode and Namecheap only — those two cover both
credential modes and both topologies, and every other driver ships built but
not live-tested, with real-world quirks caught by the diff-before-write rail and
beta reports rather than a UI warning. The Setup tab is one publish box that
leads with the deployment's default DNS provider and hides the rest behind a
"use another provider" link with no tier labels. The default is a resolved
deployment preference (`dns_default_provider`, a name not a secret, defaulting to
Linode), not a hardcoded vendor, and DNS is independent of the compute provider —
so adding VPS providers changes nothing and adding DNS providers is one setting
plus a registry entry; a compute driver optionally declaring a companion DNS
provider is deferred future sugar. Prefer OAuth2 where the vendor offers it and
fall back to a scoped API credential where it does not; the credential is
ephemeral in every mode — nothing DNS-write-capable is ever stored, not even
sealed, and unattended drift-fixing is out of scope because nothing in the
platform (certificates included — they use HTTP-01) requires a standing
DNS-write credential.

Resolved same day, second pass: the publishing account is chosen at publish time
and only when a grant reaches more than one account — nothing about the account
is persisted; CNAME is in the record vocabulary; the authoritative zone is
created at add-domain time and delegation is detected by NS lookup against the
provider's nameservers; provider prerequisites (Namecheap's IP allowlist) surface
in the box rather than failing silently; domain removal withdraws owned records
and deletes the zone only when it holds no foreign records; bulk multi-domain
publish is out of scope for v1.
