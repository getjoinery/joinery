# Relay and protection surfaces: the simplification

**Status: BUILT 2026-08-04.** All eight work items landed; safe tier green
(2364 checks), docs updated, `plugins/mailbox/tests/protect_optin_test.php`
guards the branching rule and the vocabulary. **Not yet exercised against a live
Fortress domain** — this deployment hosts none, so the ceremony, the on-disk key
row and the destroy helper are text-asserted only.

Change proposal following `mailbox_relay_state_analysis.md`. That document is the
state map and the reasoning; this one is the work. All three owner decisions are
settled and recorded there.

## The decisions this implements

1. **Send protection is a deliberate opt-in and an advanced feature.** It appears
   in no wizard, guide or checklist. A Fortress domain with send protection off
   is a finished domain.
2. **Standard and Private domains show nothing relay-related in any card or
   wizard**, but the relay stays reachable and usable for a deployment that wants
   one.
3. **The on-disk signing key is detected always, offered where a helper exists,
   and never destroyed automatically.**

## The coupling decision 1 exposes

Scoping decision 1 surfaced a defect that has to be fixed with it rather than
after it.

`checkDomain()` prescribes the protected DNS shape — SPF that does **not**
authorize this server (`-all`), `p=reject` DMARC, a DKIM record for the sealed
key — for any domain at Fortress, **whether or not send protection is on**:

```php
$protected = ($model && ($model->is_protected_identity()
    || $model->security_level() === InboundEmailDomain::LEVEL_FORTRESS));
```

But `MailboxDkimSigner` signs with the sealed key only when
`is_protected_identity()` is true. So a Fortress domain that has published what
the checklist told it to publish, and has not pressed the button, sends mail
through the provider that its own DNS instructs the world to reject. The
checklist prescribes a half-state that breaks sending, and the half-state is
exactly the one decision 1 declares to be a legitimate resting place.

**Therefore the protected sending identity is one opt-in unit.** The inverted
SPF, the strict DMARC, the sealed-key DKIM record, the forwarding subdomain and
the enforcement flag are prescribed together or not at all. Raising a domain to
Fortress turns on arrival sealing and seals a key; it prescribes no DNS.

This reverses the branching rule in `specs/mailbox_security_levels.md`
(§ Setup/health branching), which chose the level as the single branching key so
the Setup tab would guide the operator toward the shape ahead of the ceremony.
That rationale assumed the ceremony was the expected end state. Decision 1 says
it is not.

## Work

### 1. The protected shape follows the flag, not the level

- `InboundEmailSetupCheck::checkDomain()` and `dnsPlan()` branch on
  `is_protected_identity()` alone.
- A Fortress domain without send protection gets the ordinary shape: its mail
  keeps working, and its Setup tab stops prescribing records that would break it.
- The protected shape still renders during the ceremony —
  `protectedDomainChecks()` already assembles exactly this list, and it moves
  into the Sending identity box (work item 2) as the pre-flight the operator
  publishes against.

### 2. Send protection moves out of the guided box entirely

In `plugins/mailbox/admin/admin_mailbox_setup.php`, remove from `$steps`:

- the *say who the signing key belongs to* step,
- the *publish DNS / lock sending to your key* step,
- the *add a Standard subdomain for automated mail* step.

All three exist only because of send protection. What remains in the guided box
for a Fortress domain is the vault and the relay — both genuinely required for
arrival sealing, both non-optional, and both already correct.

The Advanced **Sending identity — {domain}** box becomes the single home for the
whole ceremony: the owner question, the protected-shape pre-flight rows, the
activate control, rotation, and lifting protection. Its current line *"A signing
key exists but nothing is enforced yet. Finish that from the checklist above."*
becomes wrong the moment the steps are removed and is replaced by the control
itself.

The offer must state the cost where it is made, because it is a real one:

- every interactive send needs the vault unlocked,
- automated mail (confirmations, notifications) can no longer leave from this
  domain and needs a Standard subdomain — offered here, not in the guided box,
- the DNS shape changes to one that rejects anything the key did not sign.

### 3. The unlock gate becomes visible before the press

The activate control renders in its refused state — disabled, with an inline
Unlock link — when the acting user's vault is locked. A button that accepts a
press and then explains why it did nothing is the same defect as a checklist
step that is already done.

### 4. Relay surfaces disappear for Standard and Private

- `_setup_is_receiving_row()` promotes `host.relay_scanner` only when the focused
  domain is Fortress. On every other domain the relay scanner is not that
  mailbox's business, and today one deployment-wide fact flips every mailbox on
  the deployment to `attention`.
- The same scoping resolves the verdict roll-up (analysis item 9) for the common
  case: relay health feeds `mailbox_setup_verdict()` only for a mailbox whose
  domain requires a relay.
- `plugin.relay_enable` stays out of the scoped rows — already correct.
- The Setup tab's Relay section remains available on every deployment. It moves
  under Advanced when no domain is at Fortress: reachable for anyone who wants a
  relay, absent from the guided path for everyone who does not.

### 5. The receive-mode choice stops gating

`mailbox_receive_mode() === ''` currently suppresses every mailbox surface until
the operator answers a question about infrastructure they may never need.

- The gate is removed. An undecided deployment resolves to `direct` and works.
- The choice card becomes a control in Advanced, where it can be changed at any
  time.
- The relay requirement is presented at the point it becomes true: raising a
  domain to Fortress.

`mailbox_receive_mode_resolve()` keeps its three-state return; only the callers
that render the gate change. Its unit tests stand.

### 6. One word, one meaning

Pure wording, no behaviour. Three things share the noun *relay* and two share
*protection*:

| Today | Becomes |
|---|---|
| `plugin.relay` check — "Outbound forwarding relay" | **Sending route** — the provider credential or SMTP host that carries outbound mail |
| `outbound_forwarding_relay` provisioner — "Outbound mail relay for forwarding" | **Sending route**, with details saying explicitly it is unrelated to the ingest relay |
| `mailbox_relay_outbound_mode` option — "Through the relay smarthost" | **Through the relay** |
| bare "protection" for the Fortress level | **arrival sealing** |
| bare "protection" for the sending identity | **send protection** |

*The relay* means the ingest VPS and nothing else, everywhere.

Neither the setting **key** nor its stored **value** changes — `smarthost` stays
on disk. Both are plumbing; renaming either costs a reseed or a settings
migration and buys the reader nothing, because *smarthost* names the component
rather than what happens to their mail. The field label "Sent mail leaves
through" was already clear and is left alone. `provision_relay.sh` keeps
`smarthost` as its CLI argument — that is an interface for someone already in a
shell, and renaming it would break existing invocations.

A guard in `protect_optin_test.php` parses `plugin.json` and fails if *smarthost*
appears in any provisioner label or details, or any setting label, helptext or
option — that file is where it would creep back.

Sweep the check summaries, fix texts, flash messages and
`plugins/mailbox/docs/overview.md` together; a partial sweep is worse than none,
because it leaves the reader unable to tell which sense a given sentence is in.

### 7. The on-disk key gets a state

- New check row: a usable signing key is present at
  `/etc/opendkim/keys/{domain}/mail.txt` while send protection is on. RECOMMENDED
  / WARN, saying what it means — anything able to submit through local opendkim
  can still sign as this domain. Detection needs no new plumbing;
  `readDkimKey()` already reads this path.
- A **Destroy the on-disk key** action beside it, gated on send protection being
  on **and** the protected shape complete.
- It runs a fixed-verb root helper in the shape the listener on/off buttons
  already use: `sudo -n /usr/local/sbin/joinery-dkim-remove <domain>`, installed
  by `provision_relay_main.sh` with a sudoers drop-in, validating the domain
  against the registered set and emitting a success marker the caller demands.
  Never blanket sudo.
- Where the helper is not installed, the row falls back to the existing manual
  `provision_dkim.sh --remove` command. The manual path is not retired.
- The action never fires on its own. Deleting key material is irreversible from a
  web button; it happens because a human pressed it.

### 8. Collapse the legacy-relay rows

A relay answering bare `PONG` produces two rows — scanner "legacy" and version
"unknown" — for one cause. Emit one: this relay predates the current
provisioner, upgrade it.

## Docs

`plugins/mailbox/docs/overview.md` is the only doc that carries this vocabulary
and the Fortress path. Update it to describe the end state: three distinct named
things, arrival sealing as what Fortress gives you, send protection as an
advanced opt-in with its cost, and the on-disk key step as a checked state rather
than a remembered command. No migration narrative.

## Tests

- `receive_mode_test.php` — the resolver is unchanged; add coverage that no
  surface is suppressed by an undecided mode.
- `setup_topology_test.php` — a Fortress domain without send protection emits the
  ambient DNS shape, not the inverted one. This is the regression that matters
  most; it is the defect above.
- New: the guided box for a Fortress domain with a vault and a live relay renders
  nothing.
- New: `host.relay_scanner` is absent from a Standard domain's receiving rows at
  every status, and the verdict for that mailbox is unaffected by relay health.
- New: the on-disk key row appears only when send protection is on and the file
  exists; the destroy action is refused when the shape is incomplete.

## Out of scope

- The relay upgrade path (`mailbox_relay_upgrade_without_server_manager.md`) —
  built, unaffected.
- Renaming any database column or setting key.
- The hosted fleet surfaces, still gated off by
  `mailbox_hosted_relay_offered()`.

## Open decisions

None.
