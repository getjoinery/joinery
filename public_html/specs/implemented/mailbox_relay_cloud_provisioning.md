# Relay Provisioning via the Customer's Own Cloud Account

**Status:** Implemented 2026-07-19 (same day as decided; built reuse-first after an owner-prompted second look — see "What was reused").
**Depends on:** OAuth2 core, `includes/cloud_compute/`, `provision_relay.sh`, the Setup tab's Relay section (specs/implemented/mailbox_receive_mode_and_relay_surfaces.md).

## Goal

Make "run your own relay" a no-skills path. Before this: rent a VPS, SSH in as root, run a script. After: click **Connect Linode & provision**, approve, and the deployment creates a small instance *in the customer's own Linode account* and provisions it into a relay automatically. The customer owns the machine, the IP and its reputation, and the bill — full sovereignty without the skills. With the hosted-relay offering gated off for V1, this is the path that makes the relay choice usable by normal people.

## Decisions (owner, 2026-07-19)

### D1 — Ephemeral token custody (grant-per-act)

A cloud-account token can create and destroy servers. The deployment never holds one at rest. The credential arrives **just-in-time, inside the flow** (owner decision, same day): submitting the form shows one step — a short-lived Linode API token the customer mints for this act (scope Linodes read/write only, expiry as short as an hour), verified live with a cheap read call, SecretBox-sealed onto the run row, and erased at every terminal state (as is the per-run root SSH key). Later acts — retry, destroy — ask for a fresh token.

The credential step is a **hybrid** (owner, same day): when a `linode` OAuth client is configured on the deployment, the step is a single **Approve at Linode** button (consent lands on the run via `RelayCloudConsumer`, purpose `relay_cloud` — the OAuth core's grant-per-act shape, documented in docs/oauth2.md); otherwise the pasted-token floor applies. Both branches have identical custody.

The path here: OAuth was built first, then rejected as the *only* path because it demanded a pre-registered per-deployment OAuth app ("go create a client, then return here") — an up-front instruction serving the mechanism, not the goal — then restored as the optional one-click branch on top of the token floor.

**One-click for every deployment without registration** is designed but blocked: a single operator-registered *public* OAuth client with a redirect bouncer on the operator site would work with **PKCE** (the bouncer sees only an authorization code that is unredeemable without the code-verifier held on the customer's server — no usable credential transits the operator). Verified 2026-07-19 against Linode's API reference: login.linode.com documents no PKCE support (confidential clients need the secret at exchange; public clients are implicit-style). Without PKCE the bounced credential would be operator-usable — the sovereignty objection returns — so this waits on Linode (or ships first with a provider whose OAuth supports PKCE). The design is recorded here for that day.

### D2 — A cloud-provider interface, not a Linode feature

`CloudComputeProvider` is the contract; `LinodeComputeDriver` the first implementation. Both **already existed** (built for customer-cloud order fulfillment) and were promoted from `plugins/server_manager/includes/cloud_compute/` to core `includes/cloud_compute/` — the mailbox plugin cannot depend on an inactive plugin's files, and the abstraction is platform-level. DigitalOcean/Vultr are later drop-ins behind the same interface.

## What was reused (the second-look inventory)

The owner's instinct — "I feel like we already have all of this" — was right. Built for the customer-cloud order flow and reused untouched:

- **`CloudComputeProvider` + `LinodeComputeDriver`** — create/poll/delete instance, set reverse DNS, normalized instance arrays, on the account that issued the bearer token.
- **The provisioning sequence** — tarball (relay-sealer + `provision_relay.sh`) → scp → skeleton run → `add-tenant main` (a self-hosted relay is a fleet of one) → `RELAY_WG_PUBKEY`/`RELAY_PUBLIC_IP`/`PROVISION_RELAY_SUCCESS` markers → relay-row registration → main-box WireGuard peer (`joinery-relay-peer`). Identical to `JobCommandBuilder::build_provision_relay` + `JobResultProcessor`, minus the Go agent.
- **The failure policy** from `ProvisionCustomerCloud` — 4xx terminal, 5xx/network retry next tick — with one grant-per-act divergence: 401 is terminal too (there is no standing account to park for re-connect; a retry brings a fresh grant).
- **`RelaySsh::run`** for command execution; **SecretBox** for sealing; the **fake-driver test pattern** from the customer-cloud suite.

Genuinely new: one small state-machine data class, one consumer, one engine class, one scheduled task, and UI wiring.

## User experience (Setup tab → Relay section → Run your own relay)

1. Form: mail hostname (FQDN), region, instance type (defaults `us-southeast` / `g6-nanode-1` — the smallest plan; a relay idles). Button: **Provision into my Linode account**. Always shown once the main box has its relay identity (`provision_relay_main.sh`); nothing to configure beforehand.
2. The just-in-time credential step (token field + direct link to the provider's token page with the exact scope/expiry to pick). On Start, the section shows live progress: *creating the server in your account → waiting for it to boot → building the relay (several minutes)* — advanced by the `AdvanceRelayCloudProvisions` scheduled task, so the browser need not stay open.
3. On success the relay row appears **born enabled** (owner decision, day after build — replacing register-disabled/enable-last): pulling and address-list pushes start immediately, so the relay is ready before any MX points at it, closing the propagation window where an enable-last relay would reject recipients it did not know yet. Enabling stops being a ritual; Disable remains as an emergency stop. The doctrine consequences that "enabled" used to trigger — outbound API-class enforcement, the origin-hidden assertion — key off the **recorded cutover verdict** instead (`mailbox_relay_cutover_complete`, persisted by every `relayCutoverState()` evaluation): a fronted deployment keeps sending the legacy way until DNS is actually cut over, so nothing breaks mid-move and nothing leaks after it. The `plugin.relay_enable` check row becomes cutover progress (INFO with the first incomplete reason → PASS; REQUIRED FAIL only for cutover-complete-but-disabled). The topology-aware checks walk the DNS cutover from there. Reverse DNS is attempted through the provider API at the end of the run; providers refuse it until the hostname's forward A record resolves, so the Setup PTR check carries the instruction from there.
4. A failure shows its plain-language error with Dismiss; retry is simply filling the form again (fresh run, fresh grant, fresh instance — never a resumed half-built box). A failed run destroys the instance it created *within the same grant*, so the customer is never left paying for a half-built server.
5. **The platform never destroys a customer's running server** (owner decision, day after build): a destroy-at-provider act was built, then removed — offering server destruction from our UI was judged wrong, and the delete-vs-destroy button pair confusing. A cloud relay's Delete removes only the deployment's row, and its confirm says the server keeps running (and billing) at the provider until the customer deletes it there. The single exception, kept deliberately: a FAILED provision run deletes the half-built instance it itself created within the same grant, so a customer never silently pays for a broken box.

## Architecture (as built)

- `plugins/mailbox/data/relay_cloud_provision_class.php` — `RelayCloudProvision` (`rcp_`): kind `provision|destroy`; status `awaiting_grant → ready → booting → provisioning → done|failed` (destroy: `awaiting_grant → ready → done|failed`); sealed token + sealed per-run SSH key columns, erased by `eraseCredentials()` at every terminal state; one live run at a time (`live()`).
- The `relay_cloud_token` action — verifies the pasted token with a cheap `regions()` read (401 rejects with a plain message; transient trouble does not block), seals it onto the run, and flips it `ready`.
- `plugins/mailbox/includes/RelayCloudProvisioner.php` — the engine. `ready`: generate per-run ed25519 keypair (ssh-keygen), create the instance (public key injected, random never-stored root password, label `joinery-relay-<run>`); `booting`: poll until running+IP (30-minute timeout → destroy + fail); `provisioning`: the reused SSH sequence, then relay-row registration (`mrl_cloud_provider`/`mrl_cloud_instance_id` added to `MailboxRelay`), WireGuard peering, best-effort rDNS. Injectable `$driver_factory` and `$runner` statics are the test seams.
- `plugins/mailbox/tasks/AdvanceRelayCloudProvisions` — advances all live runs each cron tick (the SSH build runs synchronously in the tick; `set_time_limit` raised). Activated from the Scheduled Tasks admin page like every task.
- UI in `relay_section.php` (form/progress/error/destroy) + actions in `relay_admin.php` (`relay_cloud_begin` / `relay_cloud_dismiss` / `relay_cloud_destroy`).
- Instance id is recorded the moment the create call returns — orphan cleanup is always possible.

## Testing

`plugins/mailbox/tests/relay_cloud_provision_test.php` (db tier, 32 checks): fake `CloudComputeProvider` + scripted runner drive the full matrix — happy path (relay row registered disabled with cloud coordinates and marker-derived WG key, credentials erased, rDNS attempted), create-fails (4xx terminal, nothing to destroy), transient 5xx retries in place with the token kept, boot-timeout (instance destroyed within the grant), script-fails (half-built instance destroyed, output tail in the error), destroy kind (instance deleted, relay row soft-deleted). `LinodeComputeDriver` request shaping is covered by the customer-cloud suite it was built with. A real end-to-end run against Linode needs a registered OAuth client and is a manual pre-release gate.

## Cadence (first-live-run finding)

The first real run exposed customer boxes' scheduled-task cron at every 15 minutes: the instance booted in ~1 minute and then napped until the next tick, twice. Fixes: the installer default (`_site_init.sh`, Docker template) is every 5 minutes; the cheap state transitions (create instance, boot poll) also advance on every Setup page load (`advanceCheap` — the long SSH build stays with the task); and the spool-freshness health threshold is 30 minutes so a 5-minute cadence with misses never false-alarms.

## Bundled fix — slot release frees domain claims (implemented 2026-07-19, ahead of the rest of this spec)

Found while rolling back the first walkthrough: releasing a fleet slot only flipped `mft_status`, leaving its pending/verified domain claims live — and the live-claim filter is status-based, so a released (even evicted) slot's claims blocked those domains fleet-wide forever. Any re-enrollment (this spec's flows included) would fail to claim the domains again.

Fix: `FleetService::releaseSlot()` marks the slot released AND revokes its live claims in the same act — release means the domains are moving, and their next home must be able to claim them before the old slot finishes evicting. `fleet_release_logic` goes through it; `relay_fleet` test covers the revocation. The two stale claims from the pre-fix release of slot t172 (scrolldaddy.app, getjoinery.com) were revoked by hand on the operator deployment as a one-off.

## Out of scope

- Providers beyond Linode (the interface is the deliverable; more implementations are follow-on work).
- Using the customer's cloud account for anything but the relay.
- Cost display from provider pricing APIs; region/plan pickers fetched live (no token exists at form time — the fields are prefilled text).
- Rebuild-in-place for cloud relays (delete the row, remove the instance at the provider, provision fresh).
- Any act that deletes a customer's running server (removed by owner decision — see UX point 5).
