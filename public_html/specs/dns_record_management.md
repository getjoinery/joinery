# DNS Record Management

**Status: UNBUILT — proposed 2026-07-25.** Design only.

## What this is

Today a deployment is told what DNS to publish and a human publishes it. The
platform already computes the exact records — it renders them as copy-paste
instructions and then re-reads DNS to see whether the human got it right. This
closes that loop: the platform publishes the records itself, and a setup page
becomes a page that *sets* rather than a page that checks.

Scope is the record layer only. **The platform never becomes a nameserver.** It
holds the desired state and writes it through whichever DNS host a domain uses,
which stays swappable and stays responsible for actually answering queries.

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
- **`SecretBox`** (`docs/secret_box.md`) — authenticated encryption for secrets
  at rest, for the provider credential.

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
`ttl`, `priority`. Any subsystem can produce one for a domain.
`InboundEmailSetupCheck` gains a method returning its plan; the rendered
instructions become one consumer of that plan rather than its only form.

### The provider is a driver

`DnsProvider` — `zoneFor($domain)`, `list($zone)`, `create()`, `update()`,
`delete()`. `CloudflareDnsDriver` first. A driver owns its vendor's quirks so no
caller ever learns them:

- **Long TXT values** are split into adjacent quoted 255-byte strings.
- **Zone resolution** is longest-suffix match against the zones the credential
  can see, so `mail.example.com` resolves to the `example.com` zone and a
  sibling TLD cannot be hit by accident.
- **Provider-managed records** (Cloudflare Email Routing owns MX and its own
  DKIM) are reported as a named refusal — *this record is managed by Email
  Routing; disable it in the Cloudflare dashboard* — not as a generic failure.
- **Post-write verification hooks**, where a vendor must be told to re-read DNS
  before it will trust a record.

### Credentials are a list, and a domain finds its own

A deployment holds zero or more DNS credentials, each sealed with SecretBox.
Publishing for a domain uses whichever credential can actually see that zone —
the same longest-suffix zone lookup the driver already performs, run across the
configured credentials rather than against one.

This costs nothing in the common case: with a single credential it behaves
exactly like a single deployment-wide login, and no domain names a credential
anywhere. It buys the case that a single login cannot serve at all — domains
split across two providers, or a zone moved to a new account — without making
every domain carry a setup step for the sake of the exception.

Where **no** configured credential can see a zone, that domain falls back to
rendered instructions. So does a deployment with no credentials at all: the plan
renders and nothing writes. That is a supported state, not a degraded one — see
[[project-zero-config-install]].

Two credentials that can both see the same zone is a real possibility and not an
error. The first match wins, deterministically ordered, and the diff names which
credential it will publish through so the choice is never invisible.

### Reconciliation is a diff, and the diff is the UI

For each record in the plan, comparing against live provider state yields
exactly one of: **matches** (nothing to do), **missing** (create), **differs**
(update, showing before and after), **conflicts** (a record exists that the
platform does not own — never silently overwritten).

The page shows the diff and a single action to apply it. Nothing is written
without the diff having been displayed.

### Ownership is explicit

The platform manages only records it created or adopted. A `dnr_dns_records`
table records domain, type, name and the owning subsystem. Consequences:

- It never deletes a record it does not own.
- Removing a domain from the platform offers to withdraw the records it owns,
  and touches nothing else.

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

## Build plan

1. **Core interface and driver.** `includes/dns/DnsProvider.php`,
   `DnsRecord`, `DnsRecordPlan`, `CloudflareDnsDriver`, credential storage,
   settings surface. Tests cover the quirks directly: long-TXT splitting,
   zone longest-suffix match, managed-record refusal.
2. **Ownership store.** `dnr_dns_records`, adoption, withdrawal.
3. **Reconciler.** Plan versus live diff, the four outcomes, the cutover flag.
4. **Mailbox consumer.** `InboundEmailSetupCheck` exposes its plan; the Setup
   tab gains the diff and the publish action; the copy-paste rendering remains
   for deployments with no provider.
5. **Node provisioning consumer.** The A record the SSL gate waits on becomes
   publishable, closing the one manual step in cloud node birth.

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
6. A deployment with no DNS credentials configured behaves exactly as it does
   today, with no new required configuration.
7. Re-running publish on an already-correct domain writes nothing and reports
   no changes.
8. A domain whose live records already match the plan is adopted on first
   publish with no DNS write, and shows no conflicts afterwards.
9. Adding a domain creates its missing records without a second step, and does
   not perform a cutover or overwrite a conflicting unowned record.
10. A deployment holding two credentials publishes each domain through the one
    that can see its zone, and the diff names which.

## Open decisions

None — all resolved 2026-07-25.
