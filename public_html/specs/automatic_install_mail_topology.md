# Checkout-Time Mail Topology for Automatic Install

**Status:** Draft — awaiting owner confirmation on three defaults, all in §8: **8.2 sitewide default topology (`single` recommended), 8.4 no smarthost option at checkout (recommended), 8.5 relay region/instance type (site's region + smallest plan via setting, recommended).** Pricing is resolved (§8.1, §8.3: one $39.99 price either way, no upgrade order flow). Build order is in §9.
**Companion to:** `specs/getjoinery_site_redesign.md` (the $39.99 automatic install this configures)

## 1. Summary

The automatic-install checkout gains one question: **how should your mail be set up?** Buyers choose between a single server (everything on one box) or a server plus a private mail relay (the box that makes Fortress-level domains possible). Provisioning then builds either one Linode instance or two — both in the buyer's own Linode account — and hands over a site whose mailbox Setup tab already knows its topology, instead of landing every buyer on the undecided receive-mode gate card.

## 2. Vocabulary discipline (the code's, not marketing's)

The mailbox plugin keeps four axes apart, and this spec must too:

- **Receive topology** (deployment-wide, *derived* from whether a `MailboxRelay` row exists): `colocated` | `self_hosted` | `fleet`. This is what checkout actually configures.
- **Receive mode** (the admin's recorded choice, setting `mailbox_receive_mode`): `direct` | `relay` | undecided. Today it is never seeded — every fresh install shows the choice card.
- **Security level** (per-domain, `ied_security_level`): `standard` | `private` | `fortress`. **Fortress is not a topology.** It *requires* a fronted topology, plus a vault ceremony, 2FA, and owner-held keys — none of which can happen at checkout because the keys must be created by the owner on their own device, post-install.
- **Delivery mode** (per-alias): forward / store / both. Out of scope here.

So the checkout question sells the topology; Fortress remains a guided post-install upgrade that the relay topology unlocks. Copy must say "Fortress-ready," never "Fortress included."

## 3. Buyer experience

### 3.1 At checkout

One new required multiple-choice question on the automatic-install product, alongside the existing domain question:

> **How should your email be set up?**
> - **Single server** — email, calendar, and files all on one server. Simplest and cheapest. *(default)*
> - **Server + private mail relay** — a second small server receives your mail and forwards it over an encrypted tunnel. Your main server never sits exposed to the internet, mail is held for you if it's ever down, and this is the setup required for Fortress-level domain security. Included in the setup price; adds a second instance to your Linode bill (~$5/mo).

**One price either way: $39.99 covers the complete setup of whichever topology is chosen** — the relay build costs the buyer nothing extra at setup; the only delta is the second instance on their own Linode bill, and that disclosure is mandatory (discovering it later reads as a trap). The pitch is "we set it all up up front for one price"; buyers who pick single-server can still add a relay themselves later (§8.3).

### 3.2 After provisioning

Both paths end at the buyer's own site with the mailbox Setup tab as the landing place (the same doctrine as the stackscript spec):

- **Single server:** `mailbox_receive_mode` seeded to `direct`, `mailbox_mail_hostname` set, mail stack configured on the box. Setup tab shows the colocated checklist (A/PTR/MX/SPF/DKIM/DMARC) — the remaining steps are the buyer's DNS acts, publishable in one authorized click via the existing DnsPublishBox when their DNS provider is one of the 15 driven ones.
- **Server + relay:** `mailbox_receive_mode` seeded to `relay`, `MailboxRelay` row registered **in the buyer's site DB** and enabled, WireGuard tunnel peered, spool-pull and map-sync tasks activated. Setup tab shows the self-hosted-relay checklist with MX/SPF prescriptions already retargeted at the relay. The welcome email states the DNS records for their chosen topology.

What stays manual, by existing design: the MX cutover and ownership TXT records (the platform deliberately holds no standing DNS credential — DnsPublishBox authorizes at write time), the outbound provider credential, and every Fortress ceremony.

## 4. Checkout plumbing

Follows the existing domain-question pattern exactly:

1. **Question:** `ProvisioningSetup` creates a multiple-choice `Question` (values `single` | `relay`) the same way it creates the domain question; its id stored in new setting `server_manager_provisioning_topology_question_id` (declared in `plugins/server_manager/plugin.json`, group `provisioning`).
2. **Injection:** `CustomerCloudFulfillment::extraRequirements()` returns a second `QuestionRequirement` for it. No new requirement class — `Question` multiple-choice already renders, validates, and persists to `oir_order_item_requirements` via `save_cart_data()`.
3. **Reading:** `CustomerCloudFulfillment::create_provision()` already iterates all `MultiOrderItemRequirement` rows for the order item — capture the topology answer there. `PollHostingOrders` (remote-store path) currently fetches only the domain question id; widen it to fetch the order item's full requirement set.
4. **Default:** a missing/blank answer resolves to `single` — old orders and any product without the question keep today's behavior.

## 5. Data model changes (`cvp_customer_cloud_provisions`)

- `cvp_mail_topology` — `single` | `relay`; recorded on the site row.
- `cvp_role` — `site` (default) | `relay`.
- `cvp_parent_cvp_id` — set on the relay row, pointing at its site row.
- `cvp_external_order_item_id` loses its schema-level `unique`; dedup becomes per-(order item, role), enforced in `create_provision()`, `PollHostingOrders`, and `validate_row()`.
- `validate_row()`'s rule "`install_mode='bare'` requires admin origin" relaxes to: bare is allowed on order origin **iff** `cvp_role = 'relay'`. (Bare-for-infrastructure is exactly what the provisioner's header comment anticipated: "infrastructure roles (e.g. mail relay shards) build on the bare node via their own provision jobs.")

A `relay`-topology order creates two rows up front: the site row as today, plus a relay row (`role=relay`, `install_mode=bare`, region defaulting to the site row's region, instance type from a new setting `server_manager_relay_instance_type` — smallest plan). Both flip `pending_connect → ready` together via the existing `CustomerCloudConsumer` loop, which already handles N provisions per user.

## 6. Provisioning orchestration

The site row runs the existing pipeline unchanged. The relay row and the mail wiring add these stages:

1. **Site box installs first.** The relay row's `handle_ready()` is gated until its parent site row reaches `done` — the relay build needs identity material from the main box, and a failed site install shouldn't leave an orphan relay billing the buyer.
2. **Root moment on the site box** (both topologies): the install already has a root SSH phase (`install_node` job). Extend the job command set so that, post-install:
   - *single:* run `plugins/mailbox/provisioning/install_email.sh` (the existing "Run Plugin Installers" job builder covers this) and seed `mailbox_receive_mode = 'direct'` + `mailbox_mail_hostname`.
   - *relay:* run `install_email.sh` **and** `provision_relay_main.sh` (WireGuard identity, peer helper + sudoers, listener switch, pull key, registers `mailbox_wg` setting). This closes the "no automated moment for the root step" gap — the root moment already exists; we're adding scripts to it.
3. **Relay box builds:** bare instance boots (existing bare path), then dispatch `JobCommandBuilder::build_provision_relay($relay_node, ['mail_hostname' => "mx.{buyer domain}"])` — the existing job scps `provision_relay.sh` + the relay-sealer and emits `RELAY_WG_PUBKEY` / `RELAY_PUBLIC_IP` markers. Set the relay's PTR via the existing `setReverseDns` support.
4. **Registration on the buyer's site, not the control plane.** A relay row lives in the *served* site's DB (`mrl_mailbox_relays`). The control plane cannot call its own `register_relay_row()` here; instead it seeds the buyer's box over SSH, following the `FleetProvisionSeeding::seedNode()` pattern exactly (settings + rows written via psql over SSH, secrets over stdin, success marker expected, best-effort with ops email on failure). Seeded: the `MailboxRelay` row (`mrl_mx_hostname`, `mrl_public_ip`, `mrl_wg_public_key`, ssh user/spool/tenant slug from job markers, `mrl_ssh_key_path` at the pull key), `mailbox_receive_mode = 'relay'`, `mailbox_mail_hostname`, and activation of the `PullRelaySpool` / `SyncRelayMap` scheduled tasks. Then invoke the tunnel peering via the `joinery-relay-peer` helper that `provision_relay_main.sh` installed for exactly this purpose.
5. **Tenant registration on the relay:** `add-tenant main` with the buyer box's pull/WG pubkeys and domain allowlist — already part of the `build_provision_relay` sequence.
6. **Welcome email** branches by topology: the DNS record set differs (MX to the box vs MX to the relay, SPF that never names the box on the relay path).

### Failure handling

- Site row fails → relay row is cancelled (never boots); buyer gets today's failure path. No second instance is ever created for a failed site.
- Relay row fails after the site is `done` → the site is still a working install; the provision parks as `failed` with the existing admin Retry path, the buyer's site stays on the (seeded) `relay` receive mode only after successful seeding — until then it remains `direct`-capable with the choice card available, and ops is emailed. The buyer is never left with mail routed at a relay that doesn't exist: MX prescriptions come from the Setup tab, which derives topology from the `MailboxRelay` row, which only exists after successful seeding. This is the payoff of topology-as-derived-state — a half-built relay can't misdirect DNS guidance.
- Seeding fails (SSH) → same best-effort doctrine as fleet seeding: ops email, provision still completes, admin re-runs seeding.

## 7. Explicitly out of scope

- **Fortress at checkout.** The per-domain raise (vault ceremony, 2FA gate, sealed DKIM key, protected identity) is owner-interactive by design. The relay topology is its precondition; the Setup tab's existing Fortress checklist takes over post-install.
- **Hosted fleet slots.** `mailbox_hosted_relay_offered()` stays `false`; this spec covers the buyer-owned relay only. Offering hosted shards at checkout is a future spec on top of the same question.
- **Zero-touch MX/DNS.** Deliberate platform doctrine (no stored DNS credential); DnsPublishBox already gives one-click attended publishing.
- **Outbound provider setup.** Choosing/keying Mailgun etc. needs the buyer's credential and stays a Setup-tab step (`mailbox_provider`).

## 8. Open questions

1. ~~Price.~~ **Resolved:** one price — $39.99 covers the complete setup of either topology. The buyer's ~$5/mo second Linode instance is the only delta, disclosed at the choice.
2. **Default and framing.** Spec defaults to `single`. Alternative: default `relay` for buyers coming through the `/families` or privacy funnel. Recommend keeping one default (`single`) sitewide for predictability.
3. ~~Topology change after purchase.~~ **Resolved:** no upgrade order flow. A `single` buyer who wants a relay later sets it up themselves through the existing Setup-tab paths (RelayCloudProvisioner against their own account); the welcome email says so. The $39.99 up-front offer is the only "we do it for you" moment.
4. **Relay smarthost.** `provision_relay.sh` accepts an optional smarthost and `mailbox_relay_outbound_mode` exists. Recommend not exposing at checkout — outbound stays provider-based by default per existing doctrine.
5. **Region/type for the relay.** Spec says same region as the site box, smallest instance type via setting. Confirm.

## 9. Implementation notes

- Touch points, in dependency order: `plugins/server_manager/plugin.json` (settings) → `ProvisioningSetup` (question) → `CustomerCloudFulfillment::extraRequirements()` / `create_provision()` → `customer_cloud_provision_class.php` (fields + `validate_row()`) → `PollHostingOrders` (widened requirement fetch + two-row creation) → `ProvisionCustomerCloud` (relay gating, bare-branch relay job dispatch, seeding call) → `JobCommandBuilder` (mail scripts in the install job; no changes expected to `build_provision_relay`) → a new `RelaySeeding` (or extended `FleetProvisionSeeding`) for the buyer-site writes → welcome-email branching in `JobResultProcessor`.
- Schema changes land via `$field_specifications` + plugin sync, per standard rules; no migrations.
- Tests: extend the provisioning-pipeline tests for the two-row order shape and the bare-on-order-origin validation change; a seeding contract test in the mailbox plugin mirroring the fleet-seeding one.
- Docs at implementation time: `plugins/server_manager/docs/overview.md` (provisioning pipeline), `plugins/mailbox/docs/overview.md` (topology sources), `docs/scheduled_tasks.md` only if task activation semantics change.
