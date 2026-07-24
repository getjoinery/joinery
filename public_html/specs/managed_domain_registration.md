# Managed Domain Registration — One-Click Domain at Checkout

**Status:** Spec (unbuilt).
**Depends on:** the automated hosting provisioning pipeline
(`specs/implemented/automated_hosting_provisioning.md`,
`specs/automated_hosting_provisioning_setup.md`) and customer-cloud
fulfillment (`specs/customer_cloud_provisioning.md`). This spec adds a
**domain leg** to that pipeline; it does not replace any compute mode.
**Control plane:** getjoinery.com (the store taking the order and the Server
Manager fulfilling it), consistent with the site-roles decision in
`specs/new_site_deployment_fortress_verification.md`.
**First registrar:** Namecheap. The design is a provider seam so a second
registrar is a new class, not a rewrite.

## What this does for the user

Today a buyer who wants hosting still has to own a domain and point DNS at
their new box by hand — three separate chores across two more vendors. This
removes all of them from the buyer's path. At checkout the buyer types the
name they want (`smithfamily.com`), sees live availability and price, and pays
once. Behind that single payment their box is provisioned **and** the domain
is registered, DNS is wired to the box, and email is turned on — so
`jane@smithfamily.com` works when the welcome email arrives. There is no
Linode dashboard, no registrar dashboard, no DNS panel, and no upfront
padding: the buyer pays for one year of the domain and nothing more.

The buyer **legally owns the domain from the moment it is registered** — they
are the registrant (WHOIS owner) on day one, not a tenant on a name we own.
Management and billing start under the operator's registrar account so the
buy is one click; the buyer graduates to full self-custody on their own
schedule (see **Graduation**). This keeps the platform's north star intact:
sovereignty is the guaranteed end state, reached without a dashboard.

## Why not the alternatives (decided)

- **Subdomain of a name we own** (`jane.joinery.app`): genuinely one click and
  zero registrar work, but you cannot run a credible personal email identity on
  it, and the buyer never owns anything transferable. Kept only as the *free /
  try-it tier* (out of scope here) — never as the paid Workspace-replacement
  identity.
- **Buyer brings their own domain** (today's Question-at-checkout path):
  retained for buyers who already own a name, but it is not one click and it
  strands non-technical buyers at the DNS step. Managed registration is the
  default for the paid package.
- **Operator owns the domain and transfers it later**: rejected — registering
  with the buyer as registrant means there is nothing to transfer during
  onboarding and ownership is never in doubt.

## The buyer journey

1. Buyer configures a hosting product whose fulfillment includes the
   **managed-domain requirement**. At checkout the requirement:
   - takes the desired domain and checks availability live against the
     registrar,
   - shows the one-year price as a checkout line item (no multi-year padding),
   - collects the **registrant contact** (name, postal address, phone, email)
     — legally mandatory for any registration — **prefilled from the store
     account / billing address**, so it is one already-populated block, once.
2. Buyer pays once. The order now carries a compute intent (existing) and a
   domain intent (new).
3. Zero-touch from here: box provisioned (existing pipeline) → domain
   registered with **registrant = buyer**, free WHOIS privacy applied →
   DNS wired to the box → email records published → welcome email with the
   live address.
4. Over the following year the buyer's own interface carries an escalating
   **"take ownership"** prompt that walks them through moving the domain into
   their own registrar custody and billing (see **Graduation**).

## Architecture

The domain leg reuses every existing pipeline convention. Integration-point
inventory, decided up front:

| Piece | Where | What |
|-------|-------|------|
| Registrar seam | `plugins/server_manager/includes/domain_registrar/DomainRegistrarProvider.php` | Small interface (methods below), mirroring `CloudComputeProvider`. First and only implementation is `NamecheapRegistrar`. Do not build a second registrar now — the seam is the extension point. |
| Registrar registry | `plugins/server_manager/includes/domain_registrar/DomainRegistrarRegistry.php` | Interface-based discovery + `configured()`, copied from `OAuth2ProviderRegistry` (glob the dir, `require_once`, walk `get_declared_classes()` for the interface, key by `getKey()`). |
| Credentials | server_manager settings | Per-registrar API credentials; secret values SecretBox-encrypted by the settings layer, same shape as the OAuth providers. Namecheap: API user, API key, and the allowlisted client IP. |
| Domain record | new data class `rdm_registered_domains` | One row per registered domain: registrar key, domain, linked order + user, SecretBox-encrypted registrant-contact snapshot, registration + expiry timestamps, `status` (`registering` / `active` / `graduating` / `released` / `failed`), and `graduation_state` (`operator_managed` / `transfer_offered` / `self_custody`). |
| Checkout collection | new **Product Requirement** type `managed_domain` (`plugins/store`) | Per platform rule #7, data is collected through a Product Requirement, not an ad-hoc form. This requirement type owns the live availability check and the registrant-contact block. It is the managed-domain equivalent of the existing domain Question. |
| Fulfillment wiring | hosting product configuration | A product opts into managed domains alongside its compute mode (`shared_host` or `customer_cloud`). The domain leg is orthogonal to the compute leg — either compute mode can carry a managed domain. |
| Registration step | new scheduled task **Provision Managed Domain** (or a branch of Poll Hosting Orders) | Runs after the box has an IP: register the domain, then publish DNS. Idempotent and retried like the existing install/SSL steps; failures park the order and alert, they never half-register. |
| DNS publish | `NamecheapRegistrar::setDns()` via the registrar DNS API | Publishes the full record set to point the domain at the box: `A @` and `A mail`/`A www` → node IP; `MX` → `mail.<domain>`; `TXT` SPF, DKIM, DMARC. |
| DKIM source | mailbox plugin | The DKIM public key is pulled from the mailbox plugin's per-domain key generation and published as the DKIM TXT record — the domain is email-ready without a manual step. |
| Reverse DNS (PTR) | `CloudComputeProvider` | PTR for the node IP is set to the mail host through the compute driver (Linode supports rDNS), so the box passes reverse-DNS checks. This is the one DNS-adjacent record that lives at the compute provider, not the registrar. |
| SSL | existing Provision Pending SSL task | Unchanged. Because DNS is now published automatically, certbot succeeds on the first pass instead of waiting for the buyer to point an A record. |
| Renewal watcher | new scheduled task **Watch Domain Renewals** | Checks `rdm_registered_domains` expiries, drives the escalating in-product take-ownership / renewal prompt, and — per the no-backstop decision — **never auto-renews**. |

### DomainRegistrarProvider interface

Kept deliberately small — the seam, not a full registrar SDK:

```php
interface DomainRegistrarProvider {
    public static function getKey(): string;            // 'namecheap'
    public static function isConfigured(): bool;        // creds present

    // Purchase
    public function checkAvailability(array $domains): array;   // domain => [available, price_year]
    public function register(string $domain, array $registrant, int $years): DomainRegistrationResult;
    public function applyWhoisPrivacy(string $domain): void;

    // DNS (registrar-hosted DNS mode — see open decision D1)
    public function setDns(string $domain, array $records): void;

    // Lifecycle
    public function getExpiry(string $domain): string;
    public function renew(string $domain, int $years): void;     // used only if a registrar ever bills the operator; not in the Namecheap happy path

    // Graduation — capability-gated
    public function graduationMechanism(): string;               // 'account_push' | 'transfer_out'
    public function pushToEndUserAccount(string $domain, string $accountRef): void; // no-op / unsupported on Namecheap
    public function getTransferAuthCode(string $domain): string; // EPP code for customer-initiated transfer-out
}
```

`graduationMechanism()` is the key abstraction: it lets Namecheap and a future
reseller-program registrar coexist behind one seam without the pipeline
knowing which is in play.

## Ownership and graduation

Two things are true at once and must not be conflated:

- **Legal ownership is immediate and universal.** The buyer is the registrant
  from registration. This holds for every registrar and is the sovereignty
  guarantee that never depends on a later step.
- **Management + billing custody starts with the operator** (so the buy is one
  click) and moves to the buyer at graduation.

**Graduation mechanism is a registrar capability:**

- **Namecheap (`transfer_out`):** Namecheap's standard API has no
  per-customer sub-account, so custody moves by a **customer-initiated
  transfer-out** — the buyer creates (or already has) their own registrar
  account and pulls the domain to it using the EPP auth code the interface
  hands them. Available after the ICANN 60-day new-registration lock. This is
  slightly more visible than an instant push, and it is the honest cost of
  Namecheap-first.
- **Future reseller registrar (`account_push`):** an intra-registrar push to a
  customer-owned sub-account — instant, free, no lock, effectively invisible.
  The seam already expresses this; no pipeline change needed to adopt it.

**Intended second registrar: OpenSRS.** OpenSRS is the deliberate graduation
target once domain volume justifies its wholesale-reseller onboarding. It wins
on both axes that Namecheap loses: `account_push` graduation (instant and
invisible instead of customer-initiated transfer-out) and wholesale pricing
with no first-year/renewal gimmick — so customers pay materially less at
renewal than Namecheap's ~$16–18/yr `.com`. Namecheap is first only because it
needs no reseller application; adopting OpenSRS later is a new
`DomainRegistrarProvider` class, not a pipeline change.

**Why graduation is not optional for Namecheap:** while a domain sits in the
operator's Namecheap account, its renewal bills the **operator**. The
no-backstop decision (below) forbids the operator fronting that renewal, so the
domain must reach the buyer's own custody before the first-year expiry for the
buyer to be billed directly. Graduation is therefore the mechanism by which the
buyer takes over billing, not a nicety.

## Renewal — no backstop (decided)

- Registration is **one year**, funded by the single checkout charge. No
  upfront multi-year padding.
- The buyer's own interface watches the expiry and shows an **escalating,
  in-product take-ownership / renewal prompt** (persistent banner, louder at
  30 / 14 / 7 / 1 days). Reminders are in-product, not a single email that can
  be missed.
- **The operator never auto-renews and never fronts the renewal.** If the
  buyer takes ownership, they renew in their own account with their own card
  (held in the registrar's vault — the operator holds no card number, exactly
  as with Stripe today). If the buyer ignores a full year of escalating
  prompts, the domain lapses. That is a sovereign outcome, not an operator
  failure — but it is unforgiving for an email identity, so the warning surface
  must be strong. There is no grace renewal.

## Namecheap verification items (before build)

These are Namecheap-specific facts to confirm live (sandbox first), not design
choices:

1. **API eligibility.** Namecheap gates API access behind account thresholds
   (domains held / balance / spend) and requires client-IP allowlisting. Confirm
   the operator account qualifies and allowlist the control plane's egress IP.
2. **Sandbox parity.** Validate register → setHosts → getInfo →
   auth-code retrieval end to end in the Namecheap sandbox before touching
   production.
3. **Registrant = buyer at registration.** Confirm `namecheap.domains.create`
   accepts distinct registrant contacts per domain and that WHOIS reflects the
   buyer, with free WHOIS privacy applied.
4. **Transfer-out path.** Confirm auth-code retrieval via API and the exact
   post-60-day transfer-out steps the take-ownership prompt will document.
5. **Pricing source.** Confirm live TLD pricing via API so the checkout line
   item matches what is charged, including promo vs. renewal price divergence.

## Open decisions

- **D1 — Where DNS lives. RESOLVED: registrar-hosted DNS first.** Records are
  set via the registrar DNS API, which is simplest at provision time and
  matches the existing pipeline. Note the consequence: because DNS management
  is tied to registrar-account access, DNS management moves with custody at
  graduation. **Authoritative DNS on the buyer's box** (registrar only
  delegates nameservers) — more sovereign, survives graduation without
  registrar-account access, but requires the box to run a reliable nameserver
  — is deferred as a later sovereignty enhancement, not built now.
- **D2 — Free subdomain tier.** `jane.joinery.app` for the free / try-it tier
  is referenced here but specified separately. Confirm it is out of scope for
  this spec.
- **D3 — Take-ownership UX.** Whether the graduation prompt is pure guidance
  (show EPP code + steps) or a more guided flow. Recommendation: start with
  guided guidance; the seam does not depend on the answer.

## Out of scope

- Any second registrar (the seam is the extension point; Namecheap only).
- The free `*.joinery.app` subdomain tier (separate spec — D2).
- Box-authoritative DNS (D1 — later enhancement).
- Migrating existing bring-your-own-domain sites onto managed registration.
- Buyer-selected nameservers / arbitrary registrar features beyond what the
  requirement exposes.

## Documentation

When built, update — current-state voice only (no migration narration):

- `plugins/server_manager/docs/overview.md` — the domain leg: the managed-domain
  requirement, the registrar seam and registry, the registration + DNS-publish
  steps, ownership/graduation semantics, and the renewal watcher.
- `plugins/store/docs/product_requirements.md` — the `managed_domain`
  requirement type.
- `docs/settings.md` — the per-registrar credential settings.
- `specs/automated_hosting_provisioning_setup.md` — the managed-domain
  activation steps (registrar credentials, IP allowlist) once they exist.
