# Step 8: Email Stack Activation on the jeremytunnell.com VPS

**Status:** Prep complete (probes + inventory done 2026-07-19). Execution blocked on the owner decisions below.
**Parent program:** `specs/new_site_deployment_fortress_verification.md` (steps 8–9).
**Doctrine:** nothing manual unless it's a one-off — every activation step becomes a platform action first.

## Goal

The new VPS (node 176, `jeremytunnell-vps`, 45.79.204.178, bare-metal) owns its email stack: Postfix on port 25, DKIM signing, local mailboxes for the owner's addresses, all through the mailbox plugin. This is the live gate for the self-hosted mail path, and the staging ground for step 9 (Fortress verification against a real off-box relay).

## Findings — DNS and box posture (probed 2026-07-19, read-only)

| Check | Result | Consequence |
|---|---|---|
| MX jeremytunnell.com | `10 mail.protonmail.ch` | **Domain's mail is live on Protonmail today** — see Decision 1 |
| SPF | `v=spf1 include:mailgun.org include:_spf.protonmail.ch mx -all` | Rewrite at cutover; `mx` token will point at the box once MX does |
| DMARC | `p=quarantine; aspf=s; adkim=s; rua=mailto:jeremy.tunnell@gmailcom` | **rua address has a typo** (`gmailcom`, missing dot) — aggregate reports have been going nowhere; fix during the DNS edit |
| DKIM selectors (default/mail/joinery/s1/k1) | none published | Proton signs under its own selectors; box needs its own at cutover |
| PTR 45.79.204.178 | `45-79-204-178.ip.linodeusercontent.com` | Generic — must become the mail hostname before outbound, or major receivers will junk/refuse |
| Postfix on the box | **already installed and running** (postfix 3.8.6 + postfix-pgsql + opendkim, active, listening on :25) — probe job #642 | Phase 1 is a completion re-run, not a fresh install |
| Port 25 inbound from internet | blocked — ufw has no 25/tcp allow rule (`install_email.sh` normally adds it, so its ufw step didn't run or ran before ufw activation) | Fix in Phase 1 |
| opendmarc / rspamd | not installed | `install_email.sh` installs opendmarc — evidence the installer only partially ran; re-run and verify |
| Outbound port-25 egress | **blocked** (connection to gmail-smtp-in.l.google.com:25 times out; ufw default-allows outgoing, so this is Linode's account-level block) | Owner support ticket required — see Decision 3 |
| Hostname | `hostname -f` returns `localhost` | Mail needs a real FQDN (HELO/EHLO name); set with the mail hostname in Phase 2 |
| Provisioning scripts on box | all present (`install_email.sh`, `provision_dkim.sh`, `provision_relay*.sh`, `relay-sealer/`) | Nothing to ship; just execute |
| WireGuard | not present | Fine — only needed for step 9 relay topology |
| **Postgres port 5432** | **open to the entire internet and accepting connections** (ufw ALLOW 5432 from Anywhere; verified reachable from dev; password auth is the only gate). VPS A refuses 5432, so this is specific to node 176's bare-metal/from-backup install path | **Security finding — close before this box holds real mail.** Determine what legitimately needs it (management-node restore push?) and restrict to that source, or remove the rule. Logged as deferred_fixes entry 14 |

## What already exists (inventory, verified in code 2026-07-19)

The mail stack is **built**, not greenfield. Full detail in `plugins/mailbox/docs/overview.md`; the load-bearing pieces:

- **Host install is one script:** `plugins/mailbox/provisioning/install_email.sh` installs and idempotently configures postfix + postfix-pgsql + opendkim + opendmarc (optionally rspamd/redis), wires the `joinery` pipe transport into the PHP handler, and reads the enabled-domain list live from the DB via a pgsql map — adding a domain later needs no host action. On bare-metal it is designed to run once as root.
- **Per-domain DKIM:** `provisioning/provision_dkim.sh <domain>` generates the key, wires opendkim, and prints the DNS TXT record.
- **The account model:** a mailbox IS an alias (`iea_` row) on a domain (`ied_` row); a user gets it via a grant (`ieg_` row, synced from the alias editor's "Users with access"). Admin pages: Accounts tab (`/plugins/mailbox/admin/admin_mailbox_accounts`) for domains + mailboxes, Setup tab for DNS verification, member reader at `/profile/mailbox/mailbox`.
- **Settings that switch it on** (per-deployment, so set on the jeremytunnell.com site, not dev): `mailbox_enabled`, `mailbox_provider` (postfix), `mailbox_mail_hostname`, `mailbox_public_ip`.
- **Health checks already exist** for verification: `inbound_mail_server`, `domain_dns_records`, `outbound_transport_class`, etc. (`InboundEmailHealth`), surfaced on the plugins page.
- **Fortress machinery is implemented** (edge-seal relay, sealed DKIM, deferred ingest, `admin_mailbox_protect` ceremony) but never proven against a real off-box shard — that is step 9, per `specs/fortress_live_verification_runbook.md`.

## Owner decisions (blocking, in order)

1. **The Proton fork.** jeremytunnell.com's mail lives on Protonmail today. Moving MX to the box means Proton stops receiving for this domain — real personal mail flow is affected, not just test traffic.
   - (a) Full cutover: box owns the domain's mail; Proton retired for it. Matches the program's intent ("the box owns the email stack") and makes the test real.
   - (b) Subdomain first: platform mailboxes on e.g. `mail.jeremytunnell.com` or another domain; Proton keeps the apex. Lower stakes, but the live gate then never exercises a real primary domain.
   - No dual-delivery option exists (MX is one owner).
2. **Which addresses.** The list of mailboxes to create ("all of the email accounts") — addresses, delivery mode (store vs forward), and who gets grants. Owner supplies the list.
3. **Port-25 egress — confirmed blocked (and that's fine).** Doctrine (owner, 2026-07-19): **outbound mail rides a provider by default** — self-hosting outbound is advanced setup, not a fork (recorded in `docs/email_system.md`). The box's outbound (forwards, replies, transactional) goes through the configured provider and works with egress blocked. The Linode unblock ticket (they ask what the mail is for and usually want rDNS set first) is only needed if the owner later pursues direct self-hosted delivery — optional, not on the critical path.
4. **rDNS.** PTR for 45.79.204.178 must become the mail hostname. Settable via Linode panel — or via the platform's existing Linode OAuth grant (build item below). Owner picks hostname (proposal: `mail.jeremytunnell.com`).
5. **Old node 32 decommission** — still open from step 7; unrelated to mail but same queue.

## Execution plan

**Phase 1 — complete the mail stack on the box.** SOLVED AND BUILT (2026-07-19 overnight). The mystery is closed: `install.sh` bakes the postfix/opendkim *packages* into every node (Ubuntu auto-starts postfix with default config — that's why it runs), while the *configurator* (`install_email.sh`, the mailbox plugin's declared `host_installer`) never ran because the mailbox plugin has never been installed on the site — probe job #653 shows its plugins table holds only `event_manager` and `store`, both inactive; probe #647 confirms no `joinery` transport in master.cf, no `ied_` tables, no mailbox settings. The platform action now exists: **node detail → Actions → Run Plugin Installers** (`build_run_plugin_installers`, runs `_plugin_installers_start.sh` as root — the root moment bare-metal lacked). So phase 1 is: on jeremytunnell.com's admin Plugins page, install + activate the mailbox plugin (schema + settings seed), then click Run Plugin Installers on node 176. Also in this phase: close the public 5432 hole on the live box (`sudo ufw delete allow 5432` — installer already fixed at v2.23, deferred_fixes 14).
**Phase 1 execution log (2026-07-19).** The first-ever fresh mailbox install surfaced three fresh-install bugs, all fixed in tree and shipped in 0.8.110:
1. **Migration error masking** — a failed plugin migration inside install()'s wrapping transaction aborted the whole transaction, so the recorded/reported error was the useless "current transaction is aborted" instead of the real one. PluginManager now holds a SAVEPOINT per migration (php and sql runners); real errors record and surface. Regression test: `tests/integration/plugin_migration_isolation_test.php` (db tier, 13 checks).
2. **Mailbox migrations assumed legacy tables** — iem_005/iem_008 indexed retired tables (`imf_`/`ifm_`) that never exist on a fresh install; iem_009 required a deleted class. Now existence-guarded / historical no-op (migrations.php 1.23.0, plugin 1.39.1).
3. **Core migration 150 assumed plugin tables** — `cleanup_orphaned_fk_rows` swept every relation discovered from code on disk, including plugins not installed on the target site (`imi_` DELETE failed → whole node upgrade rolled back). Now filters relations to tables that exist in the target database.
Also fixed while shipping these: CLI `upgrade.php` re-execs itself after a self-update instead of completing green without deploying (deferred_fixes 20), and rollback re-aligns `system_version` with the restored tree's VERSION (update_database stamps it before error accounting, so a failed upgrade left the DB claiming the new version and the next attempt would refuse with "same version").
5432 rule deletion: queued as a platform job was blocked by session permissions — owner runs `php <scratchpad>/queue_ufw_5432_job.php` or deletes the rule directly.

**Phase 2 — platform switches.** On the jeremytunnell.com site's admin: `mailbox_enabled=1`, `mailbox_provider=postfix`, `mailbox_mail_hostname`, `mailbox_public_ip=45.79.204.178`.
**Phase 3 — domain + DNS.** Register the domain in the Accounts tab; `provision_dkim.sh` for it; publish MX/SPF/DKIM and the corrected DMARC record; set PTR. Setup tab must go green (`InboundEmailSetupCheck`).
**Phase 4 — accounts.** Create the decision-2 alias list with grants; verify each in the member reader.
**Phase 5 — live verification.** Round-trip send/receive with an external mailbox (Gmail), auth results pass (SPF/DKIM/DMARC), health checks green, `profile_mailbox_test` (live tier) green on the new site.

## Build items (gaps found)

1. ~~Mail-stack install as a platform action~~ **BUILT 2026-07-19**: `JobCommandBuilder::build_run_plugin_installers` + node-detail Actions item — general (any plugin's `host_installer`), not mail-specific. Tests 84/84, UI verified live.
2. ~~rDNS as a platform action~~ **BUILT 2026-07-19**, three layers so standalone sites are covered (the panel is management-node-only):
   - **Automatic at cert issuance**: `JobResultProcessor` (1.5) sets the PTR to the site domain the first time `provision_ssl` completes (and on the check_status SSL-transition path) — the moment the domain provably resolves here, which is the provider's own precondition. Best-effort via `NodeReverseDns::setQuietly` (stale grant/manual node → recorded on the job result, never blocks SSL); first-issuance-only so a custom PTR is never overwritten by renewals.
   - **Operator button**: Reverse DNS panel on node detail Overview (cloud-born nodes) — shows current PTR, suggests `mail.<domain>`, forward-A-record precheck with an actionable refusal, expired-grant reconnect link. Live-verified on node 176.
   - **Manual checklist fallback**: the mailbox Setup tab PTR check (guidance text updated) remains the item a standalone site owner acts on via their provider's panel.
   - Plumbing: `CloudComputeProvider::setReverseDns` + Linode driver (1.1), `NodeReverseDns` helper, `node_id` filter on MultiCustomerCloudProvision. Tests: customer_cloud_provisioning 41/41.
3. From probe job #642: ufw 25/tcp allow missing + opendmarc missing (both converge when `install_email.sh` runs via item 1); public 5432 exposure (installer fixed v2.23; live box still needs the rule deleted — deferred_fixes 14); box FQDN is `localhost` (install_email.sh sets a fallback myhostname from `mailbox_mail_hostname`).

## Step-9 readiness (Fortress) — sequenced after step 8

The path is fully specified, and the one missing platform piece was found and built (2026-07-19 overnight):

- **A relay shard hosts no Joinery site** — `build_provision_relay` is self-delivering (tarballs the sealer + installer from the management node's tree, pushes over SSH, runs `provision_relay.sh` on the host). But the cloud-birth pipeline always installed a site. The gap is closed: the Install New Node cloud target now offers install type **Bare instance** (admin-origin only; instance + SSH key + managed node with `mgn_skip_joinery_checks`, no web root/site URL/SSL flow; completion = passing `check_status` job). Tests 31/31, form behavior verified live.
- **Topology:** dev is the fleet operator — VPS B is born bare on dev's server_manager, stood up as a shard via the Relay tab's provision action (`skeleton_only` for fleet mode), and jeremytunnell.com enrolls as a *tenant* through the fleet service (`fleet_enroll`, DNS TXT domain claim, MX → per-tenant hostname in the operator's `mailbox_fleet_mx_zone`). The tenant's own steady-state access (spool pull, map push) uses its relay pull key, not dev's admin key.

Sequence: VPS B bare-birth → shard provision → operator fleet service on (`mailbox_fleet_service_enabled`, shard row, MX zone) → tenant enrollment from jeremytunnell.com → `specs/fortress_live_verification_runbook.md` phases (guided Fortress domain setup → edge-seal proof → protected-identity send) → `specs/mailbox_security_model_pentest_brief.md`. The N=2 multi-tenant proof (`specs/mailbox_relay_shared_fleet.md`) rides on the same shard.
