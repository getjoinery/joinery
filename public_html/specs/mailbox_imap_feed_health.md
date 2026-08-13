# Mailbox: IMAP Feed Health — Announce Failures, Stop Failing Silently

## Problem

When an IMAP feed's OAuth token dies (observed live on dev: Google testing-mode
refresh tokens expire after 7 days, refresh returns HTTP 400, `iia_needs_reauth`
is set), the mailbox simply goes quiet. From the owner's chair a broken feed is
indistinguishable from a slow mail day. Every surface that knows about the
failure is passive:

- The **Accounts tab** shows a Reconnect button — discovered only by opening it.
- The **Setup tab** shows a FAIL row for the feed — same.
- `iia_last_status` records the fetch error — visible only on those pages.
- The poller re-detects the dead token **every pass** and tells no one.

The only *pushed* symptom the owner ever sees is Google's own
"Error 403: access_denied" page if they happen to attempt a reconnect under an
expired testing-mode consent — a Google page, outside our UI, with no
explanation of cause or fix.

The platform already solved this exact shape for the relay spam scanner:
`mailbox.relay_scanner_down` / `mailbox.relay_scanner_recovered` — a signal pair
declared in `plugin.json` with a `notify` block (in-app + email), dispatched on
**transition only** so a fault announces once, not every cron pass
(`MailboxRelayReconcile::pollScannerHealth()`). This spec applies that pattern
to IMAP feeds and fixes the reconnect dead-end.

## Non-goals

- Changing Google's 7-day testing-mode token policy. Publishing the OAuth app
  to production is an operational decision made in the Google console, not
  code. This spec makes the failure loud and the fix findable; it does not
  prevent the expiry.
- Proactive expiry *prediction*. Revocation is not announced by Google ahead of
  time; detection at the next poll (≤ one poll interval) is the floor and it is
  already achieved. What is missing is the announcement, not the detection.
- No mail is at risk and none is recovered here: mail stays on the source
  server and the cursor resumes where it left off after reconnect. A test
  asserts this property; nothing about storage changes.

## Design

### 1. Feed health state on the row (transition detection)

Two new columns on `iia_inbound_imap_accounts` (schema via
`$field_specifications`, auto-applied):

| Column | Type | Meaning |
|---|---|---|
| `iia_health_state` | varchar(10), default `'ok'` | `'ok'` or `'broken'` — the last *announced* state, the comparison anchor for transition dispatch (same role as the relay's cached health state) |
| `iia_consecutive_failures` | int, default 0 | failed polls since the last success; reset to 0 on any successful poll |

State transitions, evaluated by the poller after each account's fetch attempt
(and by `ImapSyncer` after a sync write, which already flags auth failures the
same way ingest does):

- **ok → broken, immediately** on an auth failure (`iia_needs_reauth` set: token
  refresh refused, or the server rejects the credential). Auth failures never
  self-heal, so there is nothing to wait for.
- **ok → broken, after 3 consecutive failed polls** for non-auth failures
  (unreachable host, TLS error, mailbox errors). One blip is not an outage;
  three in a row (≥ ~15 min at the default 300 s cadence) is. The threshold is
  a class constant, not a setting.
- **broken → ok** on the first successful poll (or a successful Reconnect —
  the OAuth consumer's token store clears `iia_needs_reauth` already; it also
  resets the failure counter and lets the next poll announce recovery, or
  announces recovery directly if it runs a verification fetch).

Dispatch happens only when the evaluated state differs from `iia_health_state`;
the row is then updated to match. A feed that stays broken is announced once.
A disabled feed (`iia_is_enabled` false) participates in no transitions —
turning a feed off is not an outage.

### 2. Signal pair (the loud part)

Declared in `plugins/mailbox/plugin.json` beside the relay pair, dispatched
from the poller via the signal bus:

**`mailbox.imap_feed_broken`** — notify block with `default_email: true`.
Payload: the mailbox address, the provider label (e.g. "Gmail"), a
plain-language reason, since-when, and whether sending is also affected (the
same connected account powers outbound `connected_account` send, so a dead
token breaks both directions — the announcement must say so). Body leads with
the consequence ("New mail for {address} has stopped arriving"), then the
reason, then the fix ("Press Reconnect on the Accounts tab"); link goes to the
Accounts tab. For an auth failure the reason names the known cause in plain
terms: the stored authorization was revoked or expired — and, for Google, that
an app left in "Testing" mode expires its authorization every 7 days.

**`mailbox.imap_feed_recovered`** — notify block with `default_email: true`.
"Mail for {address} is arriving again", with how many messages the first
successful fetch brought in.

Recipients resolve exactly as the relay pair's do (system notification to
admins, honoring per-user notification preferences).

### 3. Reader banner (surface where the owner lives)

The mailbox reader is where silent failure is *experienced* — an inbox that
went quiet. When the selected mailbox has a feed whose `iia_health_state` is
`broken`, the reader renders a dismissable-per-session banner above the message
list: "This mailbox stopped syncing with {provider} on {date}. New mail is not
arriving." For a viewer with admin permission the banner carries the Reconnect
link (Accounts tab, feed pre-selected); for a non-admin grant holder it states
the administrator has been notified. The banner is driven by the same row
state the reader mount already loads the feed for (folder tree), so it costs no
extra query.

### 4. Provisioning health check

New `code` check `InboundEmailHealth::checkImapFeeds()`: FAIL naming each
enabled feed in `broken` state (address + reason), PASS otherwise, silently
passing when no feeds exist. This puts feed health on the plugin's standard
health surfaces alongside every other runtime dependency, so a broken feed is
also visible anywhere provisioning status is shown — not only inside the
mailbox plugin's own pages.

### 5. Reconnect must not dead-end on Google's error page

The OAuth callback's provider-error path currently lands on the neutral error
view. When the provider returns an error for an `inbound_imap` consumer flow
(e.g. `access_denied`), redirect back to the **Accounts tab** with a flash that
translates the error: for `access_denied` on Google, name the two usual causes
— the Google account is not listed as a test user on the OAuth app, or the app
sits in Testing mode with an expired consent — and what to change in the
Google console. The generic-provider fallback shows the provider's error code
with the same return-to-Accounts framing. No error from the consent flow may
strand the operator on a page with no path back to the feed.

### 6. Reconnect verifies before it celebrates

On return from a successful consent, the consumer stores tokens and clears
`iia_needs_reauth`. Add a verification fetch (the existing Test connection
path) in the same request: if the fresh token still cannot open the mailbox,
say so immediately with the error — never show "Connected" for a feed that the
next cron pass will re-flag. On verification success, announce recovery per §1.

## Docs updates (same change, per the docs-current-state rule)

- `plugins/mailbox/docs/overview.md` § Receiving by IMAP poll — the poll
  cadence subsection gains the health-state/announcement behavior; the Gmail
  end-to-end subsection gains the Testing-mode 7-day expiry note and the
  publish-to-production alternative (it is the operational answer to weekly
  reconnects).
- `docs/email_system.md` — the connected-account outbound section already
  documents the shared `iia_needs_reauth` flag; extend with the announcement
  (one broken token → one notification covering both directions).
- `docs/signals.md` catalog listing if it enumerates signals by hand (verify;
  the preferences UI reads the catalog automatically).

## Tests (`plugins/mailbox/tests/`)

Unit (db tier), driving the poller's evaluation directly with an injected
failing/succeeding fetch:

1. Auth failure announces `imap_feed_broken` **once**; a second failing pass
   announces nothing (`iia_health_state` anchors the transition).
2. Non-auth failure announces nothing at 1 and 2 consecutive failures,
   announces at 3; a success between failures resets the counter.
3. Recovery announces `imap_feed_recovered` once; subsequent successes silent.
4. Disabled feed: no transitions, no signals, regardless of state.
5. Cursor survives an outage: fail 3 polls, reconnect, next fetch resumes from
   the same UID cursor — no gap, no re-ingest (dedup unchanged).
6. `checkImapFeeds()`: FAIL names the broken feed's address; PASS when
   recovered; passes with no feeds.
7. Callback provider-error path for an `inbound_imap` flow redirects to the
   Accounts tab with the translated flash (assert `access_denied` wording names
   the test-user/Testing causes); neutral path for non-IMAP consumers
   unchanged.
8. Reader banner renders for a broken feed's mailbox and not for a healthy
   one; admin sees the Reconnect link, non-admin grant holder sees the
   notified wording.
9. Signal declarations: both signals present in the merged catalog with
   `notify` blocks (`default_email` true), so the preferences UI lists them.

## Live verification gate (append to the live-verification queue on build)

Dev's real broken feed (account 34, `joineryemailtests@gmail.com`) is the test
subject and closes the loop:

- With the fix deployed, the next cron pass announces `imap_feed_broken` once
  — one in-app notification + one email, none on later passes. The reader
  shows the banner on that mailbox.
- Press Reconnect, complete consent (add the account as a test user first if
  Google shows 403 — which itself exercises § 5's translated flash), and
  confirm: verification fetch passes, `imap_feed_recovered` announces once,
  banner clears, health check returns to PASS, and mail sent to the Gmail
  during the outage arrives on the next fetch (the cursor property).
