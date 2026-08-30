# Customer-Cloud Provisioning — Bring Your Own Linode

> **SUPERSEDED ON ONE POINT (owner, 2026-08-30): we never put a key on a machine
> we create.** Wherever this document describes installing the platform's SSH
> public key into a new instance's `authorized_keys`, that is no longer the
> design. We already generate the instance's root password at creation; it is
> kept for the length of the install and destroyed once the agent has joined, so
> nothing of ours is ever placed on the machine. See `keyless_provisioning.md`.
> Nodes that already hold a key are dealt with manually — there is no automated
> retirement path, deliberately.

**Status:** Built on dev 2026-07-18 (all components; validated, test suite
written) — pending schema sync, db-tier test run, and live verification via
the First live test below.
**Depends on:** the automated hosting provisioning pipeline
(`specs/implemented/automated_hosting_provisioning.md`) being activated
(`specs/automated_hosting_provisioning_setup.md`) — this spec adds a second
fulfillment mode to that pipeline, it does not replace it.
**Management node:** getjoinery.com (per the site-roles decision in
`specs/new_site_deployment_fortress_verification.md`) — it is both the store
taking the hosting order and the Server Manager fulfilling it.

## What this does for the user

A buyer purchases hosting on getjoinery and gets their site on a VPS **they
own**: the server lives in their Linode account and Linode bills them directly
for it. Joinery never fronts infrastructure cost, never resells compute, and
never holds the customer's hosting money — getjoinery's product becomes a
software/management fee, not a hosting markup. The buyer's total ceremony is:
buy → connect their Linode account (one-time) → done. Every later action
(rebuild, upgrade, SSL renewal) is automatic.

Verified feasible 2026-07-18: Linode's API supports the OAuth2
authorization-code grant with refresh tokens, and an instance created with a
customer-scoped token is created **on the customer's account and billed by
Linode to the customer**. No partner program is required; OAuth client
registration is self-serve. (The Akamai parent/child + reseller program was
evaluated and rejected: it is invite-only and the reseller pays Akamai — it
never achieves Linode-bills-the-customer.)

## The buyer journey

1. Buyer purchases a hosting product on getjoinery configured for
   customer-cloud fulfillment, answering the existing domain Question at
   checkout.
2. Order-complete page (and welcome email) send them to a **Connect your
   server account** page.
3. No Linode account? A "Create one" button deep-links Linode signup through
   the operator's **referral URL** — the referred signup gets Linode's $100
   60-day credit once they add a payment method, which softens the
   add-a-card friction; the operator accrues the referral credit.
4. Buyer clicks Connect → standard platform OAuth consent flow at
   `login.linode.com` → returns via `/oauth_callback`.
5. From here the pipeline is zero-touch: instance created on their account,
   Joinery installed, node registered in Server Manager, welcome email with
   DNS instructions, SSL activates when DNS resolves (existing
   Provision Pending SSL task).

## Architecture

Integration-point inventory (everything this touches, decided up front):

| Piece | Where | What |
|-------|-------|------|
| OAuth provider | `includes/oauth/providers/LinodeOAuthProvider.php` | Endpoints `https://login.linode.com/oauth/authorize` / `https://login.linode.com/oauth/token`; access tokens expire in 2 h and **no refresh token is issued on the code grant** (verified live 2026-07-18 — `refresh_token: null` in the token response; earlier research claiming refresh support was wrong). A grant is a 2-hour credential; expiry parks the provision for re-consent. Credentials in two settings (secret via SecretBox, same shape as Google/Microsoft providers) |
| OAuth consumer | `plugins/server_manager/includes/oauth_consumers/` | Purpose `customer_cloud`. Payload carries the order/user linkage. `onTokenGranted` persists the token and marks the pending provision ready |
| Token + account record | new server_manager data class (`cca_customer_cloud_accounts`) | Per-user provider account link: provider key, SecretBox-encrypted refresh token, granted scopes, status (`active` / `revoked` / `refresh_failed`) |
| Compute driver | `plugins/server_manager/includes/cloud_compute/` | Small `CloudComputeProvider` interface — `createInstance`, `getInstance`, `deleteInstance`, plan/region catalogs — with `LinodeComputeDriver` as the only implementation. This is the seam for DigitalOcean etc.; do not build a second driver now |
| Fulfillment mode | hosting product configuration | Product declares `shared_host` (existing behavior) or `customer_cloud`. Poll Hosting Orders branches on it |
| Provisioning flow | existing Poll Hosting Orders task | `customer_cloud` orders wait in a `pending_connect` state until the OAuth grant lands, then: create instance (Ubuntu LTS image, plan from product config, region from checkout answer or default) with the management node's **provisioning SSH public key** in `authorized_keys` → poll until `running` → run the existing install_node job against the new IP → register managed node |
| Node registry | `mgn_managed_nodes` | Customer-cloud nodes are ordinary managed nodes (install, upgrades, uptime checks all reuse) plus a linkage to the `cca_` account record marking them customer-owned |
| Scopes | consent request | `linodes:read_write` only. Do not request account/billing/ips/volumes scopes |
| Referral link | server_manager setting | `server_manager_linode_referral_url`, shown on the Connect page's signup path |

### Requesting minimum scope

The token can create and manage instances and nothing else — no billing reads,
no account reads. The root password generated at instance-create is random,
never stored; all management is via the provisioning SSH key.

### Token lifecycle

A Linode grant lives two hours and cannot be refreshed (no refresh token on
the code grant). The pipeline is built around that: the normal buy → connect
→ create → install run completes well inside the window, and any use of an
expired grant (`OAuth2Client::ensureFresh` throws) or a provider 401 marks
the `cca_` record `refresh_failed`/`revoked`, parks the provision at
`pending_connect`, and emails the buyer a re-connect link. The Connect page
doubles as the re-connect page; a fresh grant resumes the provision
automatically.

## Lifecycle decisions

- **Customer cancels the getjoinery subscription:** the VPS is theirs — never
  delete or touch it. Joinery stops managing (upgrades, SSL, monitoring); the
  site keeps running as-is. The node is marked unmanaged, not removed.
- **Customer deletes the instance or their Linode card fails:** the node goes
  down; existing uptime monitoring surfaces it. This is between the customer
  and Linode; the admin alert is informational.
- **OAuth grant revoked:** management operations stop working; node flagged;
  customer prompted to re-connect. The already-installed site is unaffected.

## Activation (operator steps, one-time)

1. Register an OAuth client in Linode Cloud Manager (Profile → OAuth Apps):
   confidential/private client, callback URL
   `https://getjoinery.com/oauth_callback`. Store the client ID and secret
   in the provider's two settings on getjoinery.com (secret is
   SecretBox-encrypted by the settings layer).
2. Copy the referral URL from the Linode Cloud Manager profile into the
   Provisioning page's referral field on getjoinery.com.
3. The rest of the pipeline activation is the guided **Server Manager →
   Provisioning** page (`/admin/server_manager/provisioning_setup`) on
   getjoinery.com (the prod management node) — see
   `specs/automated_hosting_provisioning_setup.md`.

## First live test

The owner acts as the test customer (owner's own Linode account = the
customer account). Test order #1 on getjoinery.com exercises the full BYO
flow and the instance it creates **is VPS A** in
`specs/new_site_deployment_fortress_verification.md` — satisfying that
plan's fresh-install gate and final-state provisioning proof in one run.
VPS A is then flipped to Provisioning Enabled and a second test order proves
the shared-host mode on the same box.

## Out of scope

- Any second compute provider (the driver interface is the seam; Linode only).
- Migrating existing shared-host sites to customer-cloud (separate effort;
  the copy-site tooling is the obvious basis).
- Akamai partner / parent-child / reseller accounts.
- Letting the customer pick arbitrary plans/regions beyond what the product
  configuration exposes.

## Documentation

When built, update — current-state voice only:

- `docs/oauth2.md` — add the Linode provider to the catalog and
  `customer_cloud` to the consumer list.
- `plugins/server_manager/docs/overview.md` — the two fulfillment modes,
  the Connect flow, customer-owned node semantics.
- `specs/automated_hosting_provisioning_setup.md` — the customer-cloud
  activation steps (OAuth client registration at Linode, referral URL
  setting) once they exist.
