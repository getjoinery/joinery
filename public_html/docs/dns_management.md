# DNS Management

The platform holds the DNS records a deployment needs and publishes them itself,
through whichever DNS host the deployment uses. **It never becomes a
nameserver** — it holds desired state and writes it through a host that stays
responsible for answering queries.

The value is not saved keystrokes. It is that a class of failure disappears: the
one where the platform knows the right answer, the deployment's DNS says
something else, and nothing connects the two. A record simply never published; a
record published in a same-named sibling zone; a TXT value over 255 bytes
published unquoted, accepted, stored and then never served. Each of those is a
visible diff here.

## The shape

| Piece | Where | What it is |
|---|---|---|
| `DnsRecord` | `includes/dns/DnsRecord.php` | One record: type, name, value, optional TTL, optional MX priority |
| `DnsRecordPlan` | `includes/dns/DnsRecordPlan.php` | Everything one subsystem wants for one domain |
| `DnsProvider` | `includes/dns/DnsProvider.php` | The driver contract, plus its declared capabilities |
| `DnsDriverBase` | `includes/dns/DnsDriverBase.php` | HTTP plumbing and the quirks every driver shares |
| `DnsRrsetDriverBase` | `includes/dns/DnsRrsetDriverBase.php` | Read-modify-write for providers that store record *sets* |
| `DnsDriverRegistry` | `includes/dns/DnsDriverRegistry.php` | Discovers drivers by interface; resolves the deployment default |
| `DnsReconciler` | `includes/dns/DnsReconciler.php` | The diff, the per-record apply, withdrawal |
| `DnsOwnershipStore` | `includes/dns/DnsOwnershipStore.php` | "Is this record ours?", behind an interface so the diff is testable without a database |
| `ManagedDnsRecord` | `data/dns_records_class.php` | The `dnr_dns_records` table behind that store |
| `DnsPublishBox` | `includes/dns/DnsPublishBox.php` | The one publish surface every page shares |
| `DnsPublishConsumer` | `includes/oauth/consumers/DnsPublishConsumer.php` | Performs a publish with an OAuth grant that never outlives the request |

## The record vocabulary

A, AAAA, CNAME, MX, TXT, CAA and SRV. CAA is included because a wrong or missing
CAA record blocks certificate issuance in the same silent way a missing challenge
record does. SRV is what Joinery Direct's capability record needs; its whole RDATA
— `priority weight port target` — travels in the record VALUE rather than in extra
columns, so a plan still compares one string and a driver that passes values
through needs no special case. Providers whose API models the four fields
separately decompose it with `DnsDriverBase::parseSrv()`.

**A provider that cannot express a type says so rather than guessing.**
`supportsType()` defaults to every type; a driver that models one as vendor-named
fields it has not had verified returns false, the diff shows the record as
**add by hand** with its value ready to copy, and everything else in the plan still
publishes with the button. A record written into the wrong fields is worse than one
not written at all, because it looks published.

**NS and SOA can never be expressed.** `new DnsRecord('NS', …)` throws, and so
does rebuilding a plan from a payload that contains one — a plan that could
rewrite delegation could take a zone away from its owner.

`ttl` is optional, and that matters: a record that omits it means *provider
default*, and a live record whose only difference from the plan is a TTL the
plan never asked for **matches**. A zone left on default TTLs never shows
permanent diff noise. MX priority behaves the same way.

Values are compared canonically, never textually. TXT quoting and chunking is
stripped both ways, hostnames lose case and the trailing dot, MX priority lives
in its own field. A provider handing a long TXT back as `"aaa" "bbb"` and a
provider handing it back joined are describing the same record.

## Producing a plan

Any subsystem can produce one. There is no registration step.

```php
require_once(PathHelper::getIncludePath('includes/dns/DnsRecordPlan.php'));

$plan = new DnsRecordPlan('example.com', 'myplugin');   // domain, owning subsystem
$plan->addRecord('MX', 'example.com', 'mail.example.com', null, 10, 'Inbound mail');
$plan->addRecord('TXT', 'example.com', 'v=spf1 ip4:1.2.3.4 -all', null, null, 'SPF');
```

The last argument is a note. It is rendered next to the record in the diff and
never compared, so it is where you explain *why* the record exists in the
operator's terms.

Two rules for a plan author:

- **Stay inside the domain's own zone.** A record whose name is not the domain or
  a name beneath it does not belong in that domain's plan — publishing it would
  ask a credential to write somewhere it has no business.
- **Never plan a placeholder.** If a value is not known yet (no public IP, no
  relay hostname), leave the record out. Publishing `YOUR_SERVER_IP` into
  someone's zone is worse than publishing nothing.

### Records that must not exist

Desired state includes what must **not** be there. A plan that could only add and
change would report *nothing to change* on a domain still holding a record that
makes the plan's promise false — and a page saying that beside a check card
naming a record to delete is two things disagreeing about whether a domain is
finished.

```php
// Whatever is published at this name, at this type, must go.
$plan->addAbsent('TXT', 'mailo._domainkey.example.com', DnsRecord::ANY_VALUE,
    'A signing key that is not yours — it can send as example.com and pass DMARC.');

// Or one specific value, leaving anything else at the name alone.
$plan->addAbsent('TXT', 'example.com', 'v=spf1 include:oldhost.test ~all');
```

The **type is part of the requirement**, not decoration: a delegated DKIM
selector is a CNAME and a held one is a TXT, and asking for both when only one is
live puts a permanently-satisfied row on the page. Whoever writes the plan is
expected to know which answers, because they had to look to know there was
anything to remove.

Removals are gated harder than anything else in the box, because every other
outcome converges by publishing again and a deleted record does not come back:

- **Never additive.** `APPLY_ADDITIVE` — adding a domain, provisioning a node,
  anything that publishes without a human reading a diff — deletes nothing.
- **Never without its own tick.** The *adopt* confirmation does not cover it:
  adopting overwrites a record with a known replacement, a removal leaves the
  name answering with nothing. Deleting a live MX or A record needs the *cutover*
  tick as well.
- **Never an ownership claim.** A removal that finds nothing is done, not
  adopted — recording the platform as responsible for a record that does not
  exist would make the next `withdraw()` try to delete it again.

`hasContradiction()` catches a plan that both publishes and removes the same
record. That is the plan author's to resolve: applying it would depend on which
record came first.

The two plans that ship:

| Consumer | Method | Records |
|---|---|---|
| Mailbox domain setup | `InboundEmailSetupCheck::dnsPlan($domain)` | MX, SPF, DKIM, DMARC, the mail host's A record, the fleet ownership proof, and the inverted protected shape for a Fortress domain — which also requires any foreign signing key to be absent |
| Node provisioning | `NodeDnsPlan::forNode($node)` | The node's site A (or AAAA) record — the one certificate issuance waits on |

`InboundEmailSetupCheck::dnsPlan()` computes desired state from the same
planners the checks use (`spfPlan()`, `dkimPlan()`, `topology()`), so every
topology and security-level branch reaches the plan without being restated.

## Adding the box to a page

Two calls: one in the action path, one in the render path.

```php
// Actions. Returns a LogicResult to redirect on, or null when the request
// was not one of ours. The plan factory is only called when it is needed.
require_once(PathHelper::getIncludePath('includes/dns/DnsPublishBox.php'));
$dns_redirect = DnsPublishBox::handle($input, function () use ($id) {
    return MyPlanSource::forThing($id);
}, $return_url);
if ($dns_redirect !== null) { return $dns_redirect; }

// Render vars.
$dns_box = DnsPublishBox::build(MyPlanSource::forThing($id), $input, $return_url);
```

```php
// In the view.
require_once(PathHelper::getIncludePath('includes/dns/dns_publish_box.php'));
dns_publish_box_render($page, $dns_box);
```

`build()` is cheap unless the diff is on screen, at which point it costs a
handful of public DNS lookups and no credential at all.

## The order: diff, then authorize, then write

This is the property everything else rests on.

Building the diff needs **no credential**. Live DNS is public, so the entire
reconciliation value — the visible diff, the adoption bookkeeping, the conflict
detection, the cutover warnings — is a read. A deployment gets the whole safety
surface without holding any secret, and re-checking a published domain is free
forever.

A credential is required only for the write. Pressing **Apply** on a diff the
operator is looking at is what starts the consent flow (or takes a scoped key),
and that credential lives for exactly one request:

- An **OAuth2** driver hands off to the shared consent flow with purpose
  `dns_publish`. The token arrives at `/oauth_callback`, `DnsPublishConsumer`
  does the whole write inside that one request, and the grant — refresh token
  included, for providers that issue one — is gone when the method returns.
- An **API-credential** driver collects its key in the Apply form. The key exists
  as a local variable for the duration of that POST.

**Ephemeral is the only mode. Nothing DNS-write-capable is ever stored — not
even sealed.** There is no persistence path in the code: `DnsDriverBase` refuses
to be serialized and redacts itself from `var_dump`/`print_r`, and
`dnr_dns_records` has no column that could hold a secret. Nothing in the platform
forces us off this: drift is *detected* credential-free, and certificate issuance
runs over HTTP-01 through `certbot` on each node, so no timer ever needs a
standing DNS-write credential. Unattended drift-fixing is out of scope for
exactly this reason.

**Account selection needs nothing stored either.** A grant reaching one account
— the common case — is used with no question asked. A grant reaching several (a
reseller or parent/child login) makes the box ask which, once, as a one-click
choice for that publish. No account identifier is persisted.

## The outcomes

For each planned record, comparing against live state yields exactly one of:

| Outcome | Meaning | What Apply does |
|---|---|---|
| **matches** | Already published as planned (or, for a removal, already gone) | Adopts it — recorded as ours, no DNS write. A removal claims nothing |
| **missing** | Nothing is there | Creates it |
| **differs** | We own this slot and its value drifted | Updates it |
| **conflicts** | Something is there that we do not own | Nothing, unless explicitly adopted |
| **remains** | The plan requires this record gone and it is still published | Nothing, unless explicitly confirmed for removal |
| **unknown** | The resolver did not answer | Nothing. A failed lookup is not evidence of absence |

**Applying is per-record and best-effort.** If one record fails — a rate limit, a
transient error, a managed-record refusal — the others still land and the failed
one reports its own reason. There is no transaction, because the only rollback
would be another write; re-running publish converges whatever is left, which is
why re-publishing a correct domain is a no-op.

**Green means confirmed written, not re-resolved.** Propagation lags the write,
so a record's post-publish state trusts the provider's write receipt. The
credential-free reconciliation catches up on its next pass; the page never claims
a record resolves before it can.

That lag has a visible consequence, and a sixth outcome exists for it:
**pending** — written here, and public DNS has not caught up. "Never published"
and "published a minute ago" look identical to a resolver and mean opposite
things, so without it a successful publish would report its own records as
missing and invite the operator to publish them again.

A pending row is recognised from the write receipt in `dnr_dns_records`, not
from a session flag: the receipt is durable, survives the browser, and is the
same row that already decides what the platform may overwrite. It applies only
to the public-DNS diff — the provider's own view has no propagation delay, so a
record absent *there* really is absent — and only for
`PENDING_WINDOW_MINUTES` (60). Past that, an unresolvable record goes back to
reading as missing, because by then something really is wrong. A receipt whose
value no longer matches the plan is drift, not flight, and does not qualify.

`settled()` is what the page asks: every row either matches or is pending, so
there is nothing left to press. The box then shows a receipt — *3 DNS records
written at Cloudflare on Jul 26, 2026 1:23 AM* — and no form, because offering a
submit button for records already in flight is what makes a successful publish
look like a failed one.

## Reporting the result

The banner after a publish carries a severity, and it is part of the contract
rather than styling. `DnsPublishBox::resultSeverity()` returns success only when
every planned record is in place, a warning when some landed and some did not,
and an error when nothing reached the provider at all. A refused credential
rendered in success green reads as *published* and sends an operator away
believing their DNS is live.

**A provider that restricts a credential by client IP sees whichever address the
outbound connection used.** A server holding both an IPv4 and an IPv6 address
may reach the API over either, so an allowlist written from the IPv4 address
alone refuses the request — Cloudflare's 403 for this names the address it saw,
and the driver reports it as an address restriction rather than a permission
problem, because the two need opposite fixes. Cloudflare mishandles /48 and /64
ranges in that filter; list an IPv6 address on its own. Namecheap's allowlist has
the same shape.

**The confirmations are unticked by default, and that is deliberate.** A record
that collides with one the platform does not own needs an explicit *adopt* tick;
a change that redirects traffic already flowing needs a *cutover* tick; a record
the plan requires gone needs a *remove* tick. They are the only choices in the
box that can take mail down, and the asymmetry decides the default: unticked
costs a second press, ticked-by-default costs the mail, and only one of those is
undone by pressing again. Ordinary additions need no tick and are written without
asking.

The action column grades by what is at stake, and every label is a verb — a badge
sitting alone in a column is read as an adjective, so a word that could describe
the record rather than the action is the wrong word. **Add** carries no alarm
colour (nothing is there to lose), **Change** is amber (a live value goes away),
**Replace** is stronger (it destroys a record the platform did not create, but
puts a known value in its place), and **Remove** is the only red: it is the one
thing the box does that publishing again cannot undo.

**Leaving one unticked skips that record, and a skip is never green.** Both lists
say so at the point of choice, `resultSeverity()` grades any skip amber, and the
summary names the records and the reason rather than printing a count. A publish
where nothing was written leads with *nothing was published*. The alternative —
green after a diff that wrote nothing — is the failure the severity contract
exists to prevent, and it read as success to the operator who hit it.

**Apply is disabled while the current ticks would publish nothing.** The test is
per record, not a count of ticks: a conflicting MX is both a cutover and an
overwrite, so ticking one of its boxes still writes nothing. A record publishes
when every gate on it is satisfied, and a diff containing any unconditional write
emits no gate at all. With scripting off the button stays enabled and the amber
summary catches it.

**A 429 is the account being rate-limited, not this server.** Every vendor here
counts requests per user or per token, and that quota is shared with the vendor's
own dashboard — which is API-driven and spends it quickly while an operator
clicks around. So a first publish attempt can be refused, and the vendor's own
wording ("please wait and consider throttling your request speed") is a machine
talking to a machine.

`DnsDriverBase` handles it once for every driver, raising
`DnsRateLimitedException` with the vendor's `Retry-After` (both the delta-seconds
and HTTP-date forms) and a message written for a person: what is limited, whether
anything changed, how long to wait, and that the vendor's website spends the same
quota.

**Reads are retried; writes never are.** Every driver reads before it writes, so
a rate-limited read means the records were not touched and repeating costs only
the wait — bounded by `RATE_LIMIT_MAX_RETRIES` and `RATE_LIMIT_MAX_WAIT_SECONDS`,
deliberately small because this runs inside a page request. A rate-limited *write*
may have been applied before the vendor decided to refuse the response, so
repeating a create could leave a duplicate record; those are reported for a human,
who is told to look at the diff before publishing again.

**Every vendor failure is logged**, whatever its status: the driver key, method,
URL path, status, and whichever trace and rate-limit headers the vendor sent
(`CF-RAY`, `X-Request-Id`, `Retry-After`, `X-RateLimit-*`), plus a truncated body.
A publish failure used to reach the operator as one flashed sentence and leave
nothing behind, so it could not be investigated afterwards. **The credential is
never part of that line** — only the response is logged, and the URL is reduced to
its path because a query string can carry a token.

## Ownership

The platform manages only records it created or adopted, and `dnr_dns_records`
is what makes that enforceable. It never modifies or deletes a record absent from
that table, and removing a domain withdraws only what is listed — and offers to
delete the zone only when it holds no records the platform does not own.

**Ownership is acquired by agreement, not only by authorship.** A record whose
live value is already exactly what the plan wants is adopted on first publish
without touching DNS: the platform and the zone already agree, so recording
responsibility is bookkeeping, not a change. Ownership strictly from authorship
was rejected for punishing the deployments that did the work by hand — every
correct record they published would sit permanently unowned, showing as a
conflict the platform could not act on.

A record that exists and *differs* is a conflict, shown with both values and
resolved only by an explicit *adopt and overwrite* choice.

## Cutovers

Creating a TXT record breaks nothing. Replacing MX moves live mail. Records whose
change redirects traffic that already flows — MX, and A/AAAA/CNAME for a name
already resolving — are flagged, and each needs its own confirmation stating what
currently receives and that it will stop. Ticking *adopt* on a conflicting record
is not enough; a cutover is a separate decision.

## The box configures a domain where it already lives

Before offering anything, the box works out **where the domain's DNS actually
is** — it reads the domain's NS records and matches them against each driver's
`nameserverSuffixes()`. That host becomes the provider it leads with. A domain on
Cloudflare gets *Configure DNS at Cloudflare*, whatever the deployment default
says.

A subdomain almost never has NS records of its own (`mail.example.com` lives in
`example.com`'s zone), so the lookup walks up the label chain until something
answers. It stops before a bare TLD; a registry's nameservers match no driver, so
an over-long walk identifies nothing rather than identifying something wrong.

Identification is a case-insensitive **substring** match, not an exact name,
because most vendors assign per-zone nameservers — Cloudflare answers
`chuck.ns.cloudflare.com`, Route 53 `ns-123.awsdns-45.org`. The fragments
`ns.cloudflare.com` and `awsdns-` identify those hosts whatever the per-zone
prefix is. When several drivers match, the longest fragment wins.

**Moving a domain's nameservers is not something the platform offers.**
Configuring a domain at the host it already uses has no blast radius: the diff
covers a handful of records and touches nothing else. Moving a zone takes the
website and every other name with it, which is a decision that belongs with
whoever runs that domain, made in their registrar — not a button next to a mail
setup checklist. So the box never prompts for it and never shows a
whole-zone warning, because there is no path here that would trigger one.

The consequence: the platform only ever writes into a zone someone else already
runs, which is where the ownership, adoption and conflict machinery earns its
keep — the zone is full of records the platform did not create.

When the domain's DNS host has no driver here, **the box does not render at
all** — no empty panel, no explanation of what cannot happen. The page is the one
it has always been: each check carrying the record to publish. That is a
supported state, not a degraded one, and it needs no announcement.

The same is true when a page supplies no plan. `dns_publish_box_render()` returns
before opening a box in both cases, so a caller can hand it whatever it has
without guarding first.

## Writing a driver

Drop a class into `includes/dns/drivers/` implementing `DnsProvider` — usually by
extending `DnsDriverBase`, or `DnsRrsetDriverBase` when the vendor stores record
*sets* rather than individual records. The registry discovers it by interface and
the provider chooser picks it up with no page change.

The static half declares capability, read before any credential exists:

| Method | Purpose |
|---|---|
| `getKey()` / `getLabel()` | Identity, and the value of `dns_default_provider` |
| `credentialMode()` | `CREDENTIAL_OAUTH2` or `CREDENTIAL_API` |
| `oauthProviderKey()` / `oauthScopes()` | The `OAuth2Provider` to consent through |
| `credentialFields()` | What an API driver collects at the publish moment |
| `prerequisiteNote()` | A setup step that must happen first (Namecheap's IP allowlist) — surfaced in the box rather than failing silently |
| `apiGateNote()` | Who the vendor's API is open to, when that is not everyone (Namecheap's 20-domain/$50 gate, GoDaddy's ten-domain gate). A prerequisite is something the operator can go and do; a gate is a qualification most never meet, so a publish surface leads with an alternative — the setup wizard recommends moving the domain's DNS to an open host |
| `credentialGuide()` | Where that credential comes from: the clicks that produce it, opened in a modal from the field itself |
| `nameservers()` | The vendor's fixed nameserver set, where it publishes one |
| `nameserverSuffixes()` | **How the driver is recognised.** Fragments of a nameserver name, matched as substrings. Defaults to `nameservers()`; override with the shared fragment for a vendor that assigns per-zone names, or the box can never lead with it |
| `supportsZones()` | Whether `createZone()`/`deleteZone()` work |
| `txtChunkingIsAutomatic()` | True only when the vendor splits over-length TXT itself |

The instance half is `zoneFor()`, `listRecords()`, `createRecord()`,
`updateRecord()`, `deleteRecord()`, and optionally `createZone()`,
`deleteZone()`, `accounts()`, `afterPublish()` and `zoneNameservers()` — the
names a registrar's nameserver setting should hold for the zone, defaulting to
the vendor's fixed set; Cloudflare overrides it to read the account's assigned
pair.

### Saying where the credential comes from

The box asks for a scoped API credential at the publish moment, and fifteen
vendors hide theirs in fifteen different places. `credentialGuide()` answers
that in place: a **How do I get this?** link on the credential field opens the
steps for that vendor, deep-linked to the page where it starts.

```php
public static function credentialGuide(): ?array {
    return array(
        'title'     => 'Create a Cloudflare API token',
        'url'       => 'https://dash.cloudflare.com/profile/api-tokens',
        'url_label' => 'Open Cloudflare API tokens',
        'steps'     => array('Sign in to Cloudflare and open My Profile, then API Tokens.', /* … */),
        'copy'      => array(),
    );
}
```

The shape is FormWriter's `help_modal` option, so the box passes it straight
through and the kit modal renders it. `copy` rows are values the *vendor's* form
needs from us — an allowlist IP, a callback URL — rendered as click-to-copy
buttons, because a mistyped one fails in a way that looks like our bug. A
credential never travels in a `copy` row; nothing there is ours to give.

Three rules for writing one:

- **Steps are what the operator clicks**, in order, in the vendor's own words for
  its buttons and menus.
- **Name the exact scope.** "Zone · DNS · Edit", not "DNS permissions".
- **Say what not to pick** where a wrong-but-adjacent credential exists —
  Cloudflare's Global API Key, GoDaddy's OTE environment, an AWS root key. That
  goes in `caution`, not in `steps`: a warning numbered among the clicks reads as
  an instruction to go and do it.

`prerequisiteNote()` is the other half and stays separate. A prerequisite blocks
the publish and is shown unconditionally; a guide is optional reading behind a
link. Namecheap, Porkbun, GoDaddy and Vultr have both — an eligibility rule or a
per-domain toggle that no amount of correct clicking in the guide can substitute
for.

The guide hangs off the **first** credential field only. One guide covers the
whole credential, so a driver collecting a username, a key and an IP address
offers the link once rather than three times.

**Quirks are the driver's job, not the caller's.** The general ones live in the
base:

- **Long TXT values** are split into adjacent quoted 255-byte character-strings.
  A short value is sent exactly as written — quoting it would risk a vendor
  storing the quotes literally.
- **Zone resolution** is longest-suffix match against the zones the credential
  can see, on a label boundary, so `mail.example.com` finds the `example.com`
  zone, `notexample.com` finds nothing, and a same-named sibling TLD cannot be
  hit by accident.
- **Post-write verification** has a hook (`afterPublish()`) for vendors that must
  be told to re-read DNS before they will trust a record.

Vendor-specific ones live in the driver. Two worth reading as examples:

- **Cloudflare's orange cloud.** Creating an A or CNAME through the API applies
  the zone's default proxy setting, and a proxied mail host or node record makes
  the world resolve Cloudflare's address instead of the server's — breaking mail
  and hiding the real address from the SSL gate waiting on it. `proxied` is
  forced to `false` on every record the driver writes; proxying is not something
  a plan can opt into. This is the most dangerous quirk in the roster because it
  fails exactly the silent way the reconciler exists to end: the write succeeds
  and the wrong thing resolves.
- **Namecheap's full-zone replace.** `setHosts` replaces the domain's entire host
  list, so writing one record means sending them all. The driver carries the raw
  host rows through untouched — including types outside the platform's
  vocabulary, like URL redirects — so a publish can never quietly destroy
  something it does not understand.

A provider feature that owns a record (Cloudflare Email Routing owning MX)
throws `DnsManagedRecordException` naming the feature, so the diff can say what
to turn off instead of reporting a generic failure.

## The shipped roster

Every driver is behind the one interface, listed in the chooser with no tier
labels and no warnings. **Live-verified: Linode and Namecheap** — between them
they cover both topologies: the platform-authoritative zone-creating host, and
registrar-hosted DNS. Every other driver ships
built with quirk-level unit tests but without live verification. That is a chosen
trade, and the diff-before-write rail is what makes it safe: a driver can only
write what the operator saw and confirmed on screen, so a real-world quirk in an
untested driver surfaces as a wrong diff to decline, never a silent bad write.

| Provider | Credential |
|---|---|
| **Linode DNS** *(default)* | Personal access token, scope Domains read/write — self-serve at any account size. Prerequisite: Linode serves a zone only for accounts with at least one active Linode service |
| Google Cloud DNS | OAuth2 · `GoogleOAuthProvider`; the zone lives in a GCP project the driver selects |
| Azure DNS | OAuth2 · `MicrosoftOAuthProvider`; the zone sits under a subscription the driver selects |
| DigitalOcean DNS | OAuth2 · `DigitalOceanOAuthProvider` |
| DNSimple | OAuth2 · `DnsimpleOAuthProvider` |
| Cloudflare | API token (Zone · DNS · Edit) |
| AWS Route 53 | IAM key pair, signed by the AWS SDK |
| Namecheap | API key + username + IP allowlist |
| GoDaddy | sso-key pair |
| Gandi LiveDNS | Personal access token |
| Vultr DNS | Bearer PAT |
| Hetzner DNS | API token |
| Porkbun | API key + secret key |
| deSEC | API token |
| Name.com | Username + API token |

## The default provider

The detected host wins, so `dns_default_provider` is a fallback rather than the
usual answer. It applies when the domain's host cannot be identified *and* the
resolver returned nothing at all — a brand-new domain, or a lookup that failed —
because silence is not evidence that a host is unsupported.

It is a settings value holding a provider **name**, never a secret, and defaults
to `linode` with no configuration. The chooser is populated from the driver
registry, so making a different provider canonical is one setting change and
adding a driver is one file.

An operator can always override both with "use another provider". The box then
says plainly that the domain's DNS is hosted elsewhere and that records written
to the chosen provider will not be answered — it does not silently accept the
mismatch.

DNS hosting is independent of where compute runs: a Cloudflare zone can hold the
A record for a node on Linode — the record simply points at that node's IP.
Adding VPS providers changes nothing here.

## The guided move to an open host

When a domain's DNS lives at a host whose API is gated (`apiGateNote()`),
`DnsRelocation` (`includes/dns/DnsRelocation.php`) turns the honest advice —
move your nameservers, once, to a host that automates for everyone — into a
guided flow, rendered by `dns_relocation_render()`
(`includes/dns/dns_relocation_box.php`) and mounted on two surfaces: the setup
wizard's sending step (`mail_send_move` action) and the publish box's
gated-host callout (`dns_action=dns_move`, handled in
`DnsPublishBox::handle()`).

- **Destinations** are a deliberate pair, not a capability scan:
  `DnsRelocation::targets()` offers Linode DNS (free with any active Linode
  service) and Cloudflare's free plan (free for anyone). Linode zones are
  created through the driver; Cloudflare zones are created by the operator in
  Cloudflare's own "Add a site" flow, which also shows them their assigned
  nameserver pair — the driver reads that pair back via `zoneNameservers()`.
- **The order is the whole design.** `seed()` creates and fills the
  destination zone — the caller's plan plus everything the domain visibly
  answers — before the operator's one manual step, the nameserver change at
  their registrar. Flipping first would take the site down over an empty zone.
- **The copy is honest about its limits.** Only guessable names are resolved
  (apex, www, `_dmarc`, common DKIM selectors) and recreated verbatim; the
  handover shows exactly what was copied and says plainly that an unguessed
  subdomain did not carry over.
- **Copied reality outranks the plan at seed time** (`seedPlan()`): an
  existing SPF or DMARC is recreated as-is even when the plan wants a
  different value, because two SPF records is a permanent failure and the move
  must change nothing observable. After delegation, the ordinary diff rail is
  where a change of value gets consented to.
- **Nothing is persisted.** Before the nameserver change, pressing the button
  again re-runs an idempotent seed; after it, the domain's own NS records
  identify the new host and every surface takes its normal path. The
  credential lives inside the one request, like every publish credential.

## Tests

- `tests/dns/dns_records_test.php` — the vocabulary, TXT canonicalization and
  splitting across every driver, zone longest-suffix matching, the TTL rule,
  Cloudflare's forced DNS-only, host identification for all fifteen providers,
  and that no driver's credential can be serialized, printed or stored.
- `tests/dns/dns_reconciler_test.php` — the four outcomes, adoption on exact
  match with no DNS write, conflicts and cutovers requiring their own choice,
  per-record best-effort apply, convergence on re-run, and withdrawal touching
  only owned records.
- `tests/dns/dns_publish_banner_test.php` — the severity of the banner a publish
  leaves behind: green only when the whole plan is in place, amber when it is
  partial, red when nothing reached the provider.

All three are `safe` tier and need no credential or network.
