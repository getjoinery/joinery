# Relay scanner health

## Problem

The platform can prove a fronting relay's **authentication** milters are alive.
It has no way to know whether its **content scanner** is.

`InboundEmailSetupCheck::checkRelayInboundVerification()` proves opendkim and
opendmarc work by counting rows with `iem_auth_source = 'relay'` — the verdicts
land in the database, so the evidence is self-generating. The rspamd milter
produces nothing comparable: `readSpamHeader()` records a score **only** when the
message was flagged. A relay that scans and finds nothing, and a relay whose
rspamd is dead, write exactly the same thing to the tenant's database: nothing.

`milter_default_action = accept` makes the failure silent by design — a dead
rspamd means Postfix accepts unscanned rather than deferring.

### The inference trap, demonstrated

Investigating jeremytunnell.com (2,273 messages, `iem_spam_score` NULL on every
row, zero spam verdicts in 30 days), the stored-data reading was that the relay
had never flagged anything. A GTUBE probe sent straight to `relay1.getjoinery.com:25`
disproved it:

```
MAIL=250 2.1.0 Ok | RCPT=250 2.1.5 Ok | DATA=554 5.7.1 Gtube pattern
```

That refusal is rspamd's own. The scanner was alive, wired into the milter chain,
and scanning content the whole time. The mailbox was simply quiet behind a relay
whose Spamhaus RBL rejects the worst at RCPT time.

**So the obvious check is the wrong check.** "Warn when no message in N days
carried a content verdict" reproduces exactly this false positive. The check has
to *ask the relay*, not infer from what did or did not arrive.

## Change

`joinery-ping` answers with the relay's health, and a check reads it.

### The verb

There is no new verb. `joinery-ping` exists in the tenant shell today and
**nothing in PHP calls it** — it is a free slot. It returns one JSON object on
stdout:

```json
{
  "status": "ok",
  "services": {"rspamd": "active", "opendkim": "active", "opendmarc": "active"},
  "milters": {"opendkim": true, "opendmarc": true, "rspamd": true},
  "contract": true,
  "provisioned": "2.14",
  "slug": "example"
}
```

`services` is `systemctl is-active`; `milters` is parsed from `postconf -h
smtpd_milters` against the ports provisioning wired; `contract` is the header
contract check (D4). Nothing else.

**An old relay answers `PONG <slug>`**, and that plain-text reply is how the
check knows the relay predates this. It is a better capability probe than a new
verb would have given: an unknown verb answers `denied: unknown command` on
stderr, which is indistinguishable from every other refusal the shell can
issue, whereas `PONG` is unambiguous and already the documented answer.

Ping's contract therefore changes. `plugins/mailbox/docs/overview.md` is the
doc of record and gets updated; `specs/implemented/mailbox_relay_shared_fleet.md`
describes ping as it was and is not edited, per the rule on implemented specs.

**Multi-tenancy is the binding constraint.** A shared fleet shard serves several
deployments, so the verb reports **shard-level service liveness only** — never
queue depth, message counts, spool sizes, or per-tenant anything. Those would
leak one tenant's mail volume to another. Service state is not tenant data.

### The check

A relay-side counterpart to `checkRelayInboundVerification()`, in the same
`host` layer. Its severity is **conditional on whether the tenant still needs the
relay's scanner**, which after `specs/implemented/mailbox_ingest_scan_decoupled_from_learning.md`
it may not:

| Relay | Local scan (`scanAtIngest` + `scannerAvailable`) | Result |
|---|---|---|
| delivering usable verdicts | either | PASS |
| not delivering — dead scanner **or** drifted contract | active | WARN — your relay is not delivering spam verdicts; this server is covering it |
| not delivering — dead scanner **or** drifted contract | not available | FAIL — nothing is scanning content anywhere |
| answers `PONG` | either | INFO — relay predates this check |

**Dead and drifted share a severity deliberately.** They are different faults
but one finding — the relay's verdict is not reaching the tenant — and one
remedy: reprovision the relay. Splitting them would double the matrix to say
the same thing twice. The distinction survives in the detail text, which names
which of the two it was.

That last row matters: every relay already deployed answers `PONG`. Reading
that as a failure would light up red on every existing deployment the moment
this ships. It is an INFO with a "rebuild the relay to enable" note, never a
warning.

## Decisions

- **D1 — Who asks, and when. RESOLVED: both.** A poll folded into the existing
  `MailboxRelayReconcile` task, which already holds an SSH session to the relay
  every cron pass — one extra ping, no new connection — plus the Setup tab check
  reading the cached result and able to force a fresh probe. On-demand alone was
  rejected: nobody opens the Setup tab until mail already looks wrong, which is
  the failure this spec exists to catch. Cron alone was rejected: an operator
  mid-incident would get a cached answer of unknown age with no way to refresh it.
- **D2 — Where the result is stored. RESOLVED: the relay row.**
  `mrl_last_health_json` + `mrl_last_health_time` on `mrl_mailbox_relays`, beside
  `mrl_last_pull_time` — the same shape of fact about the same relay, and it
  survives the relay being unreachable, which is exactly when the check needs a
  last-seen answer. A generic setup-check cache was rejected as speculative
  generality with one consumer; probing live on page load was rejected because it
  puts an SSH round-trip in a page render and turns an unreachable relay into a
  15-second Setup tab timeout.
- **D3 — Should a dead relay scanner notify? RESOLVED: yes, on transition.**
  A WARN that lives only in the Setup tab inherits the exact problem D1 was chosen
  to solve, so the cron pass that discovers the fact also raises it.

  Two new signals declared in `plugin.json` under the existing `signals` key
  (which already carries `mail_import.scanned` / `mail_import.finished`), each
  with a `notify` block: **`mailbox.relay_scanner_down`** and
  **`mailbox.relay_scanner_recovered`**. Dispatch is on TRANSITION only —
  comparing against the cached state from D2 — never once per cron pass. This is
  the shape `RunNodeUptimeChecks` already uses for node up/down and cert expiry
  (`apply_state()` returns `'down'`/`'recovered'`, and only those fire an alert),
  so the pattern is established rather than invented.

  Nothing new is built for delivery: `Notify` is already a SignalBus subscriber
  and handles in-app notification plus a queued email per the recipient's
  `ntp_notification_preferences`. See `docs/signals.md` and
  `docs/notifications.md`.
- **D4 — How deep does the verb go? RESOLVED: it asserts the header contract too.**
  "Is rspamd running" and "does its `X-Spam` header reach the tenant" are
  different failures. A relay whose `milter_headers.conf` drifted out of declaring
  the `spam-header` contract scans perfectly and stamps nothing `readSpamHeader()`
  can parse — reporting healthy while the tenant sees the same silent clean
  verdict this whole spec exists to eliminate.

  **The contract is checked by hash, not by parsing.** Provisioning *writes*
  `/etc/rspamd/local.d/milter_headers.conf` and `actions.conf` itself, from
  heredocs in `provision_relay.sh`, so their correct content is known at write
  time. It records a `contract.sha256` beside them; ping re-hashes both files and
  returns `"contract": true|false`. PHP reads a boolean.

  Reading the files back into PHP and modelling rspamd's config format against
  `InboundEmailRouter`'s header constants was rejected: it is ~150 lines and a
  test group to reach the same verdict. A hash reports that the config drifted,
  not how — but the remedy is reprovision the relay either way, and these are
  our own files in `local.d/`, which distro packages do not rewrite.

  **A manual end-to-end mail probe was rejected.** GTUBE is special-cased to
  `reject` in rspamd — proven in this investigation, `554 5.7.1 Gtube pattern` —
  so it never exercises the `add_header` path at all, and any non-GTUBE sample
  crafted to score above the threshold is fragile and rspamd-version-dependent. It
  would prove less than the verb while sending real mail on every click. The probe
  stays a diagnostic technique, not a feature.

## Files

| File | Change | ~Lines |
|---|---|---|
| `plugins/mailbox/provisioning/provision_relay.sh` | `joinery-ping` answers health JSON; write `contract.sha256` beside the two rspamd configs; version bump | 25 |
| `plugins/mailbox/data/mailbox_relay_class.php` | `pollHealth()` — run ping, decode, tolerate `PONG`, cache to the row; plus `mrl_last_health_json` / `mrl_last_health_time` in `$field_specifications` (D2) | 60 |
| `plugins/mailbox/tasks/MailboxRelayReconcile.php` | Poll once per pass, dispatch the transition signal (D1, D3) | 50 |
| `plugins/mailbox/includes/InboundEmailSetupCheck.php` | `checkRelayScannerHealth()` reading the cached row; severity reads `MailboxSpamPolicy`; a Re-check forces a fresh probe (D1) | 90 |
| `plugins/mailbox/plugin.json` | `mailbox.relay_scanner_down` / `mailbox.relay_scanner_recovered` signals with `notify` blocks (D3) | 30 |
| `plugins/mailbox/docs/overview.md` | Health section + ping's contract | 40 |
| `plugins/mailbox/tests/relay_health_test.php` | **New** | 120 |

**No `RelayHealth.php`.** Once the contract check is a boolean and the verb is
one `RelaySsh::run(RelaySsh::sshCommand($relay, 'joinery-ping'))`, what is left
is decode-and-store — so it lives on `MailboxRelay`, the row that stores it,
the way `RelayMapSync` handles the merge verdict inline rather than behind a
class of its own.

## Tests

- Parsing: a well-formed payload, a truncated one, non-JSON, and `PONG example`
  — the last must resolve to "relay predates this", never "scanner dead".
- The severity matrix above, all four rows, with `MailboxSpamPolicy` pinned via
  `overrideScannerAvailable()`.
- **Contract drift (D4):** `"contract": false` with every service `active` must
  not pass — the failure mode a services-only ping would have called healthy —
  and must land on the same severity as a dead scanner while saying which it was.
- Transition dispatch (D3): healthy→dead fires once, dead→dead fires nothing,
  dead→healthy fires the recovery. A cron pass that changes nothing must be silent.
- Ping's output shape, asserted against the shell script, so a provisioning edit
  that changes the contract fails here rather than in the field.
- **A guard that the check never infers from stored messages** — the regression
  this spec exists to prevent.

## Open

- Whether the fleet shard's own operator (not the tenant) needs a wider view —
  out of scope here; this spec is the tenant's question only.
