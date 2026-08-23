# Wizard DNS Relocation — Move the Domain's Records to a Host We Can Automate

**Status:** All three parts built and deployed. Part 1: Linode
personal-access-token support. Part 2: NS detection with the honest
gated/automatable offering (gates declared per-driver as `apiGateNote()`),
in the wizard's sending step and the shared publish box. Part 3: the guided
move (`DnsRelocation` + `dns_relocation_render`), mounted on both surfaces;
an in-flight move persists as the `dns_move_pending` setting until the
domain's NS records answer from the target or the operator cancels, and
choosing Linode in the provider dropdown while the DNS lives elsewhere runs
the move rather than a doomed publish. The seed is live-verified (real
token, zone created and filled at Linode). The wizard now has a single Email
step covering sending and receiving, so detection, the gate radio, and the
move serve the whole mail record set — there is no separate receiving step
left to adopt them. Open: a full delegation switch-over has not been
exercised end to end, and detection of a completed move is pull-only — a
scheduled watcher (poll pending moves, auto-verify, notify the owner) is a
candidate follow-up.

## Problem

The setup wizard's sending step (and the receiving step after it) offers to
publish DNS records automatically through a DNS driver. That offer assumes the
user can produce an API credential for wherever their domain's DNS lives —
usually their registrar. For the audience the wizard serves, that assumption
now fails more often than it holds:

- **Namecheap** grants production API access only to accounts with 20+
  domains, OR a $50 balance, OR $50 spent in the last two years — and requires
  every calling IP to be allowlisted. (Verified against Namecheap's API FAQ,
  2026-08. The credential guide states this; a one-domain user reads it and
  stops.)
- **GoDaddy** issues production keys only to accounts holding ten or more
  domains or a Discount Domain Club membership; keys below that are read-only.
- The trend across registrars is toward gating APIs away from small accounts.

So the automatic path — the whole point of the wizard's DNS stage — is a
non-starter for a regular user whose one domain sits at a mainstream
registrar. The manual tab works but forfeits every future automatic publish:
the receiving step's MX, the machine sender ceremony, Direct key rotation.

## The insight

**Where a domain is registered and who answers its DNS are separable.** The
user keeps the domain at Namecheap forever; they change its nameservers once —
an ordinary dashboard setting, no API required — to a free DNS host whose API
is open to everyone. After that one move, this record set and every future
record publishes automatically for the life of the deployment.

One nameserver change beats hand-pasting six records today and more again
later. That is the pitch, and the wizard should make it.

## Targets

Two recommended destinations, offered side by side with their preconditions
stated (no platform detection — the user picks the account they already have):

1. **Linode DNS** — the primary target: the quick-deploy audience already
   holds a Linode account, so there is nothing new to sign up for. Free, but
   Linode serves a zone only for accounts with **at least one active Linode
   service** — a precondition the primary audience meets by definition,
   because the deployment itself is that service. A deployment running
   anywhere else must not be steered here unless the user separately has a
   Linode; that is what the second target is for.
2. **Cloudflare (free plan)** — the zero-precondition fallback: free at any
   account size, scoped API tokens, and `CloudflareDnsDriver` accepts them
   today unchanged.

deSEC, Hetzner, DigitalOcean and the other open-API drivers remain in the
dropdown as they are; the two above are what the wizard actively recommends.

## Part 1 — Linode personal access token support

**Verified feasible; the mechanism is decided.** A Linode personal access
token (Cloud Manager → username → API Tokens, scope Domains read/write) and
an OAuth access token are interchangeable on API v4: both authenticate as
`Authorization: Bearer <token>` — the exact header `LinodeDnsDriver` already
sends. Every endpoint the driver calls (`domains` list/create/delete, records
CRUD) is covered by the Domains scope. The two ancillary calls a Domains-only
token cannot make — `GET /account` for the account label, and the reseller
child-account listing — already catch their failures and degrade (generic
label; single-account behavior). So the token path needs **no HTTP changes at
all**; the driver's OAuth-ness is bookkeeping, three lines deep.

The change:

- `credentialMode()` returns `CREDENTIAL_API`. `credentialFields()` declares
  one field named `access_token` — that name is deliberate: the base class's
  `accessToken()` reads `credential['access_token']`, so the pasted token
  flows through the existing code untouched.
- `credentialGuide()`: Linode → click your username → API Tokens → Create
  Personal Access Token, scope Domains read/write, expiry the user's choice.
  Same used-once-never-stored contract as every other API credential.
- The OAuth constants (`oauthProviderKey()`, `oauthScopes()`) and the
  Linode consent path are **dropped for this driver, not kept as a dual
  mode**. All three `CREDENTIAL_OAUTH2` branch points (consent hand-off in
  `DnsPublishBox::startApply`, app-registration collection in
  `renderVars`, the footer copy in `dns_publish_box.php`) fall through to
  the API path with no edits. Consequence: the mail Setup tab asks for a
  pasted token instead of sending the operator through consent — which also
  deletes the per-deployment OAuth app registration ceremony that consent
  required. One credential story everywhere; the OAuth machinery itself
  stays for any future driver that needs it.
- Trade-off named honestly: an OAuth grant expired in two hours by
  construction; a PAT lives until its chosen expiry. The platform never
  stores either (used within the request, discarded), so the exposure
  difference is on the user's side of the fence, and the guide tells them to
  set an expiry.

## Part 2 — Nameserver detection and honest offering

Before showing the DNS-host dropdown, the wizard resolves the domain's NS
records (one `dns_get_record(DNS_NS)` — cheap, render-time). Classify the
result against a small static map of NS suffixes:

| NS pattern | Meaning |
| --- | --- |
| `registrar-servers.com` | Namecheap BasicDNS (API gated) |
| `domaincontrol.com` | GoDaddy (API gated) |
| `ns.cloudflare.com` | Cloudflare (automatable) |
| `linode.com` | Linode (automatable) |
| `digitalocean.com`, `hetzner.*`, `desec.*`, … | automatable now |
| anything else | unknown |

Behavior per class:

- **Already automatable** → preselect that host in the dropdown and just ask
  for its credential. No relocation talk at all — do not advertise a migration
  to someone who does not need one.
- **Gated** → say so plainly ("Namecheap only opens its automation to large
  accounts") and lead with the relocation offer (Part 3). The manual tab
  remains for those who prefer it.
- **Unknown** → offer both: the dropdown (their host may be listed) and the
  relocation path.

The detection also fixes an honesty problem that exists today: the dropdown
currently offers every API driver as if any of them could work, when only the
one actually answering the domain's DNS can.

## Part 3 — The guided move

Order is the whole design. Flipping nameservers before the records exist at
the new host takes the user's site (and any existing mail) down. The flow:

1. **Collect the destination credential** (Linode token / Cloudflare token).
2. **Create the zone** at the destination via its API if absent.
3. **Seed the zone before the switch:**
   - Everything in the deployment's own plan (`sendingDnsPlan()`, and the A
     record for the site host — the wizard knows the server's address from the
     secure-connection step's diagnosis).
   - The domain's currently visible records, copied by resolving the obvious
     names: apex A/AAAA, `www`, MX, apex TXT, `_dmarc`, and the common DKIM
     selector names. Copy-not-improve: what resolves is recreated verbatim.
   - **Honesty requirement:** DNS cannot be enumerated from outside, so
     records on subdomains we did not guess will NOT carry over. The page must
     say this and show exactly what was copied, so a user with a `shop.` or
     `blog.` subdomain adds it at the destination before switching.
4. **Hand over the nameserver list** for the destination (`ns1–ns5.linode.com`
   / the two Cloudflare assigns per zone — Cloudflare's are per-account, so
   they must be read from the zone-create API response, not hardcoded) with
   per-registrar instructions for where the nameserver setting lives.
5. **Watch the delegation.** "Check now" resolves NS until the destination
   answers, then falls through to the normal publish/verify loop (which
   becomes a formality — the records were seeded in step 3).

State machine note: the move spans days-long DNS propagation and page
reloads. Persist nothing beyond what is re-derivable: destination choice can
be re-detected from where the zone now exists; seeded-or-not is re-askable
from the destination's API. Follow the wizard's standing rule — completion
derived live, never stored.

## Where it lives

Built once, mounted twice: the wizard's sending step is the first surface,
the mail Setup tab's publish box the second (same gated-registrar problem,
same fix). The detection and move logic belong beside the drivers in
`includes/dns/` (core), not in the mailbox plugin — the sending step already
depends on core DNS only.

## Non-goals

- **Platform-hosted authoritative DNS** (running our own nameservers, as the
  ScrollDaddy infrastructure does). The sovereign long-run answer, but a
  different project with an operations tail.
- **Registrar transfer.** The domain stays registered where it is; only
  delegation moves.
- **Managed domain registration** — separate existing spec
  (`managed_domain_registration.md`), unbuilt; complementary, not replaced.

## Decisions

- **Cloudflare zone creation happens in Cloudflare's dashboard**, not through
  our API call: its "Add a site" flow shows the assigned per-account
  nameserver pair on screen, and the token the user then creates needs only
  the Zone · DNS · Edit scope. Part 3's guide walks that flow; Linode zones
  are created through the driver's `createZone()`.
- **The receiving step adopts detection + the relocation offer as a
  follow-up**, after Part 3 exists — the sending step is the proving ground.
  (Superseded: the wizard's mail steps merged into one Email step, whose DNS
  stage publishes the full sending + receiving plan through this machinery.)
