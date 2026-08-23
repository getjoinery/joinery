# Wizard DNS Relocation — Move the Domain's Records to a Host We Can Automate

**Status:** Draft, not started.

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

Two recommended destinations, in this order:

1. **Linode DNS** — for deployments that run on Linode (the quick-deploy
   audience already holds a Linode account, so there is nothing new to sign up
   for). Free with any active Linode service. Requires driver work (below).
2. **Cloudflare (free plan)** — the zero-precondition universal target: free
   at any account size, scoped API tokens, and `CloudflareDnsDriver` accepts
   them today unchanged.

deSEC, Hetzner, DigitalOcean and the other open-API drivers remain in the
dropdown as they are; the two above are what the wizard actively recommends.

## Part 1 — Linode personal access token support

`LinodeDnsDriver` is `CREDENTIAL_OAUTH2` today: publishes ride an OAuth
consent grant, which requires a one-time per-deployment app registration at
Linode before the first publish. Right for the mail Setup tab; too much
ceremony for a first-run wizard.

Linode also issues **personal access tokens** (Profile → API Tokens, scope
Domains read/write) that authenticate against the same API with the same
bearer-token header. The driver should accept either:

- Add a credential field for a personal access token and report a mode the
  publish surfaces can treat as `CREDENTIAL_API` when a token is supplied.
  Decide the mechanism during build: either the driver becomes
  `CREDENTIAL_API` with the OAuth path kept as an upgrade the Setup tab still
  uses, or the registry learns that one driver can carry both modes. The
  deciding constraint: the wizard's dropdown filters on
  `credentialMode() === CREDENTIAL_API`, and the Setup tab's consent flow must
  keep working unchanged.
- `credentialGuide()` for the token path: Linode → Profile → API Tokens →
  Create token, scope Domains read/write, expiry the user's choice. Same
  used-once-never-stored contract as every other API credential.

## Part 2 — Nameserver detection and honest offering

Before showing the DNS-host dropdown, the wizard resolves the domain's NS
records (one `dns_get_record(DNS_NS)` — cheap, render-time). Classify the
result against a small static map of NS suffixes:

| NS pattern | Meaning |
| --- | --- |
| `registrar-servers.com` | Namecheap BasicDNS (API gated) |
| `domaincontrol.com` | GoDaddy (API gated) |
| `ns.cloudflare.com` | Cloudflare (automatable now) |
| `linode.com` | Linode (automatable once Part 1 lands) |
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

## Open questions

- Cloudflare free-plan zone creation requires the account to add the site and
  returns the assigned nameserver pair; confirm the token scopes needed for
  zone creation (Zone.Zone edit) versus record edits (Zone.DNS edit) and
  whether the guide should have the user create the zone in the dashboard
  instead (fewer scopes, one manual step).
- How the wizard knows a deployment runs on Linode (to lead with Linode over
  Cloudflare): detect via the Linode metadata service at install time, or
  simply always offer both and let the user pick the account they have.
- Whether the receiving step's `wizard_provision` publish should also adopt
  the detection + relocation offer in the same change or a follow-up.
