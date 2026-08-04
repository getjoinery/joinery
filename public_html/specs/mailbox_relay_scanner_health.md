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

A `joinery-health` verb on the relay's tenant shell, and a check that reads it.

### The verb

`provision_relay.sh` installs it alongside the existing five verbs
(`rsync` pull/push, `joinery-ack`, `joinery-merge`, `joinery-ping`). It returns
one JSON object on stdout:

```json
{
  "status": "ok",
  "services": {"rspamd": "active", "opendkim": "active", "opendmarc": "active"},
  "milters": {"opendkim": true, "opendmarc": true, "rspamd": true},
  "headers": {"use": ["spam-header", "x-spam-status", "authentication-results"],
              "extended_spam_headers": true},
  "actions": {"reject": null, "greylist": null, "add_header": 6}
}
```

`services` is `systemctl is-active`; `milters` is parsed from `postconf -h
smtpd_milters` against the ports provisioning wired; `headers` and `actions` are
read back from `/etc/rspamd/local.d/milter_headers.conf` and `actions.conf`
(D4). Nothing else.

**Multi-tenancy is the binding constraint.** A shared fleet shard serves several
deployments, so the verb reports **shard-level service liveness only** — never
queue depth, message counts, spool sizes, or per-tenant anything. Those would
leak one tenant's mail volume to another. Service state is not tenant data.

### The check

A relay-side counterpart to `checkRelayInboundVerification()`, in the same
`host` layer. Its severity is **conditional on whether the tenant still needs the
relay's scanner**, which after `specs/implemented/mailbox_ingest_scan_decoupled_from_learning.md`
it may not:

| Relay scanner | Local scan (`scanAtIngest` + `scannerAvailable`) | Result |
|---|---|---|
| alive, header contract intact | either | PASS |
| alive, header contract drifted | active | WARN — relay scans but its verdict cannot reach you; this server is covering it |
| alive, header contract drifted | not available | FAIL — the relay's verdict is being scanned and then discarded |
| dead | active | WARN — your relay is not scanning; this server is covering it |
| dead | not available | FAIL — nothing is scanning content anywhere |
| verb absent | either | INFO — relay predates the verb |

That last row matters: every relay already deployed answers `denied: unknown
command`. Reading that as a failure would light up red on every existing
deployment the moment this ships. It is an INFO with a "rebuild the relay to
enable" note, never a warning.

## Decisions

- **D1 — Who asks, and when. RESOLVED: both.** A poll folded into the existing
  `MailboxRelayReconcile` task, which already holds an SSH session to the relay
  every cron pass — one extra verb, no new connection — plus the Setup tab check
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

  So the verb also reads back `/etc/rspamd/local.d/milter_headers.conf` and
  `actions.conf` and reports what they declare: the `use` list, `reject`,
  `add_header`. The check compares that against what `readSpamHeader()` actually
  parses (`X-Spam`, `X-Spam-Flag`, `X-Spam-Score`, `X-Spam-Status`) and fails on a
  mismatch. One extra file read, no mail sent.

  **A manual end-to-end mail probe was rejected.** GTUBE is special-cased to
  `reject` in rspamd — proven in this investigation, `554 5.7.1 Gtube pattern` —
  so it never exercises the `add_header` path at all, and any non-GTUBE sample
  crafted to score above the threshold is fragile and rspamd-version-dependent. It
  would prove less than the verb while sending real mail on every click. The probe
  stays a diagnostic technique, not a feature.

## Files

| File | Change |
|---|---|
| `plugins/mailbox/provisioning/provision_relay.sh` | `joinery-health` verb in the tenant shell, reporting services + milters + header contract (D4); version bump |
| `plugins/mailbox/includes/RelayHealth.php` | **New** — runs the verb, parses/validates the JSON, compares the header contract against what `readSpamHeader()` parses, tolerates the old-relay refusal |
| `plugins/mailbox/includes/InboundEmailSetupCheck.php` | `checkRelayScannerHealth()` reading the cached row; severity reads `MailboxSpamPolicy`; a Re-check forces a fresh probe (D1) |
| `plugins/mailbox/tasks/MailboxRelayReconcile.php` | Poll the verb once per pass, cache to the relay row, dispatch the transition signal (D1, D3) |
| `plugins/mailbox/data/mailbox_relay_class.php` | `mrl_last_health_json`, `mrl_last_health_time` via `$field_specifications` (D2) |
| `plugins/mailbox/plugin.json` | `mailbox.relay_scanner_down` / `mailbox.relay_scanner_recovered` signals with `notify` blocks (D3) |
| `plugins/mailbox/docs/overview.md` | Health section + the verb's contract |
| `plugins/mailbox/tests/relay_health_test.php` | **New** |

## Tests

- Parsing: a well-formed payload, a truncated one, non-JSON, and the
  `denied: unknown command` refusal — the last must resolve to "verb absent",
  never "scanner dead".
- The severity matrix above, all six rows, with `MailboxSpamPolicy` pinned via
  `overrideScannerAvailable()`.
- **Header-contract comparison (D4):** a payload whose `use` list drops
  `spam-header` must fail even with every service `active` — the failure mode a
  services-only verb would have called healthy. Assert against the same header
  names `readSpamHeader()` parses, read from the constants rather than retyped, so
  the two cannot drift.
- Transition dispatch (D3): healthy→dead fires once, dead→dead fires nothing,
  dead→healthy fires the recovery. A cron pass that changes nothing must be silent.
- The verb's own output shape, asserted against the shell script, so a
  provisioning edit that changes the contract fails here rather than in the field.
- **A guard that the check never infers from stored messages** — the regression
  this spec exists to prevent.

## Open

- Whether the fleet shard's own operator (not the tenant) needs a wider view —
  out of scope here; this spec is the tenant's question only.
