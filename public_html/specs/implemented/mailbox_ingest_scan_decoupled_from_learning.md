# Ingest scanning decoupled from learning

## Problem

A relay-fronted deployment with spam filing on could file nothing, indefinitely,
while every surface reported the feature as working.

Observed on jeremytunnell.com: 2,273 stored messages, **2,266 `ham`, 0 `spam`**,
and `iem_spam_score` NULL on every row — no content-scanner verdict has ever been
recorded. Over 30 days, 341 messages arrived through the relay spool; 333 carried
`spf/dkim/dmarc = pass/pass/pass` and 8 arrived `unverified`. The box has rspamd
installed and answering on `127.0.0.1:11334`, and `mailbox_spam_filtering_enabled`
is on.

Three layers were supposed to stack. Two could not act:

- **Auth rule** — fires on DMARC `fail`, or no-DMARC plus SPF *and* DKIM both
  `fail`. Nothing in the arriving stream failed anything: `pass` cannot trip it and
  `unverified` is deliberately not `fail`. Structurally incapable of firing.
- **Content layer** — the only layer that catches authenticated bulk spam. The
  local scan was gated behind `learningEnabled()`, which was off, so the sole
  content signal was the relay's `X-Spam` header. `readSpamHeader()` returns
  `none` for anything not flagged, so **a header the relay never stamped is
  indistinguishable from a clean verdict** — and nothing on the box could tell the
  difference.

The gate's stated reason was that without a tenant corpus a local re-score "adds
nothing." That is true only if the upstream actually scanned. A shared relay is
deliberately stateless and its scanner may be absent, misconfigured, or not
wired as a milter — a condition invisible from the tenant, forever.

## Change

Split one predicate into three, and stop letting a training preference decide
whether scanning happens at all.

```
scanAtIngest()         = filingEnabled() AND upstreamScanner() !== 'none'   // posture, pure
scannerAvailable()     = the controller is answering right now              // capability, observed
localVerdictReplaces() = learningEnabled()                                  // trust
```

- **Scanning no longer requires learning.** Relay- and webhook-sourced mail is
  re-scored locally wherever filing is on and a scanner runs. Colocated mail is
  still never re-scored — its own milter ran exactly that scan.
- **Learning now decides only how much the local verdict counts.**

  | Learning | Local verdict | Rationale |
  |---|---|---|
  | off | **OR'd** in — adds spam, never subtracts | Without a corpus the local scan is the same static ruleset the upstream ran, minus the live SMTP client context a milter sees. Not better informed, so it must not overturn an upstream `spam`. |
  | on | **Replaces**, both directions | The corpus exists nowhere else, and only replacement lets a user's *Not spam* correction subtract. |

- **Posture and presence are kept apart.** `scanAtIngest()` stays pure settings +
  topology so it is deterministic and assertable on any box; the router ANDs in
  `scannerAvailable()` before it tries. A box with no scanner is never called, so
  a webhook-only deployment spends no failed request per message. The probe is
  memoized per request — a spool run pulling a hundred messages probes once.

### Why not merge the two checkboxes

Considered and rejected. Filing is reversible and costs a click; learning writes a
persistent per-deployment corpus from user clicks. Learning is unavailable
wherever no scanner runs, so a merged switch would either deny filing to those
deployments or mean different things on different hosts. Filing also needs no
infrastructure at all — it acts on auth verdicts alone. After this change the two
settings are genuinely orthogonal, which is what the split was always claiming.

## Files

| File | Change |
|---|---|
| `plugins/mailbox/includes/MailboxSpamPolicy.php` | `scanAtIngest()` drops the learning condition, gains `filingEnabled()`; adds `localVerdictReplaces()`, `scannerAvailable()` (memoized), `overrideScannerAvailable()` test seam; `reset()` clears the memo; v1.2 |
| `plugins/mailbox/includes/InboundEmailRouter.php` | `resolveContentSpam()` gates on `scannerAvailable()` and merges replace-vs-OR; docblocks; v1.28 |
| `plugins/mailbox/logic/admin_mailbox_settings_logic.php` | Scanner readout no longer attributes re-scoring to learning; states second-opinion vs authoritative; v1.4 |
| `plugins/mailbox/plugin.json` | Learning helptext: what it changes is whose answer counts |
| `plugins/mailbox/docs/overview.md` | Content scanner section + settings table |
| `plugins/mailbox/tests/spam_policy_test.php` | Fronted cells now scan with learning off; asserts `localVerdictReplaces()`; v1.2 |
| `plugins/mailbox/tests/spam_filtering_test.php` | OR semantics with learning off, no-scanner and filing-off short-circuits, pinned scanner presence; v1.4 |

## Verification

- `php tests/run.php db --filter=spam` → 4 tests, 207 checks, PASS
- `php tests/run.php db --filter=mailbox` → 51 tests, 1466 checks, PASS
- `php tests/run.php safe` → 82 tests, 1992 checks, PASS

## Open

- **Not yet deployed.** jeremytunnell.com starts scanning at the next upgrade; its
  rspamd is already installed and answering, so no provisioning is needed.
- **The relay's own scanner is unconfirmed.** `node_exec` to `joinery-relay-1`
  fails on publickey and the tenant's `relay_pull_key` is `600 www-data`, so
  whether the relay stamps `X-Spam` at all was never established — only that no
  message has ever arrived carrying a spam verdict. This change makes the tenant
  independent of that answer, but the relay is still worth checking directly.
- **Relay row is stale on jeremytunnell.com.** `mrl_delete_time` is set
  (2026-07-21) and `mrl_public_ip` (66.175.210.20) does not match what
  `relay1.getjoinery.com` resolves to (45.79.215.171); `mrl_authserv_id` is empty
  and no WireGuard interface is up. Mail flows anyway. Unrelated to spam, but the
  stored topology does not describe reality.
