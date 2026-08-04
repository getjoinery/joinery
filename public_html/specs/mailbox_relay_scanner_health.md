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
  "scanner": {"actions": {"reject": null, "add_header": 6}}
}
```

`services` is `systemctl is-active`; `milters` is parsed from `postconf -h
smtpd_milters` against the ports provisioning wired. Nothing else.

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
| alive | either | PASS |
| dead | active | WARN — "your relay is not scanning; this server is covering it" |
| dead | not available | FAIL — nothing is scanning content anywhere |
| verb absent | either | INFO — relay predates the verb |

That last row matters: every relay already deployed answers `denied: unknown
command`. Reading that as a failure would light up red on every existing
deployment the moment this ships. It is an INFO with a "rebuild the relay to
enable" note, never a warning.

## Decisions

- **D1 — Who asks, and when.** The existing relay check runs on-demand in the
  Setup tab, which nobody visits; a scanner that dies in month three is caught by
  a scheduled probe or not at all. **Recommend:** both — the Setup tab check for
  an operator looking, and a poll folded into the existing `MailboxRelayReconcile`
  task (which already holds an SSH session to the relay every cron pass) with the
  result cached on the relay row. One extra verb per pass, no new connection.
- **D2 — Where the result is stored.** A new `mrl_last_health_json` +
  `mrl_last_health_time` on the relay row, or a generic setup-check cache?
  **Recommend:** the relay row. The topology already lives there and the check
  needs the last-seen answer when the relay is unreachable.
- **D3 — Should a dead relay scanner notify?** A WARN nobody reads is the same as
  no check. **Recommend:** defer — the notification surface is a separate
  question and this spec is worth shipping without it.
- **D4 — Keep a manual probe?** A "test the relay scanner" button that sends
  GTUBE end-to-end. **Recommend: no.** GTUBE is special-cased to `reject` in
  rspamd, so it proves the scanner *runs* but never exercises the `add_header`
  path that actually stamps `X-Spam` — the thing the tenant reads. It would prove
  less than the verb while sending real mail. The probe is a diagnostic technique,
  not a feature.

## Files

| File | Change |
|---|---|
| `plugins/mailbox/provisioning/provision_relay.sh` | `joinery-health` verb in the tenant shell; version bump |
| `plugins/mailbox/includes/InboundEmailSetupCheck.php` | `checkRelayScannerHealth()`; severity reads `MailboxSpamPolicy` |
| `plugins/mailbox/includes/RelayHealth.php` | **New** — runs the verb, parses/validates the JSON, tolerates the old-relay refusal |
| `plugins/mailbox/tasks/MailboxRelayReconcile.php` | Poll the verb once per pass, cache to the relay row (D1) |
| `plugins/mailbox/data/mailbox_relay_class.php` | `mrl_last_health_json`, `mrl_last_health_time` (D2) |
| `plugins/mailbox/docs/overview.md` | Health section + the verb's contract |
| `plugins/mailbox/tests/relay_health_test.php` | **New** |

## Tests

- Parsing: a well-formed payload, a truncated one, non-JSON, and the
  `denied: unknown command` refusal — the last must resolve to "verb absent",
  never "scanner dead".
- The severity matrix above, all four rows, with `MailboxSpamPolicy` pinned via
  `overrideScannerAvailable()`.
- The verb's own output shape, asserted against the shell script, so a
  provisioning edit that changes the contract fails here rather than in the field.
- **A guard that the check never infers from stored messages** — the regression
  this spec exists to prevent.

## Open

- Whether the fleet shard's own operator (not the tenant) needs a wider view —
  out of scope here; this spec is the tenant's question only.
