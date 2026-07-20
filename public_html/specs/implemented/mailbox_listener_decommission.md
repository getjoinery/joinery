# Mailbox — Local Mail Listener Decommission (Shrunken Main Box)

**Status:** Draft.
**Builds on:** `specs/mailbox_setup_topology_aware.md` (the Setup tab's
"decommission pending" row becomes this spec's control),
`specs/mailbox_relay_shared_fleet.md` + `specs/implemented/mailbox_hardened_ingest_relay.md`
(the topology whose end state this completes).

## Goal

Once a relay fronts the deployment, the box's own mail listener is dead
weight with live attack surface: port 25 open to the internet and a public
Postfix answering on the machine that holds the mail archive, keys, drive,
and passwords. The "shrunken main box" claim in the relay docs is only true
after that listener is gone. This spec makes decommission (and its reversal)
a guided platform action — never manual host surgery.

## End state (already described in the mailbox docs)

- Postfix, opendkim, opendmarc: stopped and disabled on the main box.
- Port 25: closed at the firewall.
- **rspamd stays** — deferred ingest still scores pulled messages through the
  controller interface at parse time; only the milter mode is unused.
- Outbound is untouched: compose/forward sends ride the provider API
  (inbound-only doctrine) or the relay smarthost — neither uses the local
  Postfix.

## Mechanism

**A narrow root helper, following the established relay pattern**
(`joinery-relay-peer` / `joinery-relay-addr`): `provision_relay_main.sh`
installs `/usr/local/sbin/joinery-mail-listener` plus a sudoers rule for the
web user. Idempotent verbs:

- `off` — `systemctl disable --now postfix opendkim opendmarc`; remove the
  ufw 25/tcp allow rule; print `LISTENER_OFF`.
- `on` — inverse (enable/start all three, restore the ufw rule); print
  `LISTENER_ON`. This is the return path to colocated.
- `status` — report unit + firewall state, machine-readable.

No server_manager dependency: the action runs on the deployment's own box via
the sudo helper, so standalone tenants get the same button. (A managed-node
job variant is unnecessary — the helper is the single code path.)

**State is recorded**, not inferred: setting `mailbox_local_listener`
(`active` | `decommissioned`, factory `active`) is written by the action on a
successful helper run. Health and setup checks read the setting so
expectations and reality can be compared honestly (setting says
decommissioned but port 25 answers → FAIL, and vice versa).

## Guardrails (the action refuses unless all hold)

1. A live relay row exists and is **enabled** (the relay is actually
   consuming mail).
2. Every enabled inbound domain's MX resolves to the relay MX target — no
   domain still delivers to the box. (Same evaluation the topology-aware
   Setup tab performs; shared code, not a copy.)
3. The relay spool pull is healthy (a recent successful pull), so mail has a
   working path before the fallback listener disappears.
4. The active outbound path does not depend on local Postfix: refuse when the
   outbound provider is the local-sendmail/Postfix transport.

Re-enable (`on`) has no guardrails — restoring a listener is always safe.

## Surfaces

- **Relay tab**: a "Local mail listener" box (fronted topologies only) showing
  current state + the Decommission / Restore action with a typed-confirm modal
  (`confirm_typed`) on decommission. Guardrail failures render as the refusal
  reason, not a disabled mystery button.
- **Setup tab** (`host.port25`, `host.postfix`): read `mailbox_local_listener`
  — decommissioned expects the listener gone (open port 25 becomes FAIL);
  active under a fronted topology keeps the advanced-view INFO pointing at
  this action.
- **Health checks** (`InboundEmailHealth::inbound_mail_server`): same
  setting-aware inversion, so the plugins page doesn't report a decommissioned
  listener as an outage.

## Integration points that change

- `plugins/mailbox/provisioning/provision_relay_main.sh` — install the helper
  + sudoers rule (idempotent re-run adds it to existing relay-fronted boxes).
- New helper script under `plugins/mailbox/provisioning/` (shipped like the
  peer/addr helpers).
- `plugins/mailbox/logic/admin_mailbox_relay_logic.php` + admin view — the
  action + state box.
- `plugins/mailbox/plugin.json` — `mailbox_local_listener` setting with
  factory default.
- `InboundEmailHealth` / `InboundEmailSetupCheck` — setting-aware
  expectations.
- Tests: guardrail matrix (each refusal), setting/reality mismatch rows,
  helper idempotency (shell gate on a relay-fronted box).

## Out of scope

- Uninstalling the mail packages (disabled units are sufficient; packages
  make restore instant).
- Any change to rspamd.
- Automating the decision — decommission stays an explicit admin act with a
  typed confirm, given its blast radius if guardrails were somehow wrong.

## UI revision (owner, 2026-07-20, same build cycle)

The surface was reworked for plainness: the box appears ONLY when the action is possible — an amber **Uninstall local mail** offer (one sentence: with a relay fronting mail, the local mail software is unnecessary and a security risk) with a plain-confirm button. Guardrail refusals render nothing at all instead of a refusal list (the Setup rows already walk each missing piece); the server-side guardrail re-check on POST remains the enforcement. The typed confirm was dropped with the refusal list — the guardrails are the safety. After an uninstall, a quiet state line offers **Reinstall local mail**; a recorded-uninstalled-but-port-answering mismatch renders red with the uninstall offered again. "Uninstall/Reinstall" is user-facing vocabulary for the same off/on helper verbs (services disabled, packages kept for instant restore).
