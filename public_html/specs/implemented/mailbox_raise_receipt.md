# Mailbox raise receipt: one card from intent to receipt

**Status:** Active — agreed 2026-07-20 (owner walkthrough follow-up)
**Builds on:** specs/implemented/mailbox_protection_ceremony.md (checklist, gated save, backlog sealing)

## Problem

A raise to Private/Fortress currently ends in three disconnected fragments on the
domain editor: a flash message ("Domain saved — sealing earlier messages now"),
a yellow alert that auto-resubmits the whole page per 200-row sealing batch, and
a green one-liner ("All earlier messages on this domain are sealed"). With a
small backlog the first two flash by in under a second, so the only thing the
operator reads is a sentence about sealing — and the actual event, *your domain
is now Private*, is stated nowhere. With a large backlog the page visibly
reloads once per batch (10,000 messages = 50 full reloads). A raise with an
**empty** backlog is worse: it bounces to the accounts list with a generic
"Domain saved" and no headline at all.

## Design

The ceremony card (`mailbox_protection_render()`'s container on the domain
editor) owns the whole arc. Four moments, one component:

### 1. Intent (checklist) — exists, one wording change

The heading names the destination: **"Before this domain can be Private"** /
**"…can be Fortress"** instead of the generic "protected", so the receipt title
later reads as the same sentence resolved.

### 2. The raise (inline step-up) — exists, unchanged

### 3. Working (sealing in place)

After the save redirect (`sealed_now=1`, unchanged marker), the card renders
the **receipt layout immediately** — title and fact rows visible — with the
sealing row live: "Sealing earlier messages — N remaining…". A JS loop drives
it in place:

- New API action **`mailbox/seal_batch`** (`plugins/mailbox/logic/seal_batch_logic.php`,
  `_logic_api` descriptor, browser-session credential per docs/api.md;
  permission gate same as the editor). Body: `{domain_id}`. Calls
  `mailbox_protection_seal_batch()` unchanged and returns `{sealed, remaining}`.
  `/ajax/` is not used.
- The loop updates the row's count after each batch and stops at
  `remaining === 0`, resolving the row into its completed form in place. No
  page reloads, no separate yellow alert.
- **Stuck-batch guard:** if a batch returns `sealed === 0` with
  `remaining > 0` (rows whose holder lost their vault after the raise — the
  Setup tab's red "Mail sealed at rest" row), the loop STOPS and the row turns
  red naming the count, linking the Setup tab. Today's page-reload loop would
  spin forever on this state; the new loop must not.
- **No-JS fallback:** the current auto/`<noscript>` POST form
  (`ceremony_seal_batch`) is retained.
- **Interruption-free by construction:** the level is recorded at save, so a
  closed tab mid-backlog loses nothing. Any later editor visit that finds
  `backlog > 0` on a sealing domain re-enters this state and resumes (this is
  today's resume rule, kept — only the rendering changes).

### 4. Receipt

The card resolves to:

- **Title:** "This domain is now Private" (Fortress: see handoff below).
- **Fact rows** in the checklist's green-dot language, derived from real state,
  never hardcoded:
  - "N earlier messages sealed" — N accumulated from batch returns (initial
    backlog on arrival; zero-backlog raises read "No earlier messages needed
    sealing").
  - "New mail seals on arrival".
  - "Reading takes an unlock" — one row, naming the holder(s) from
    `mailbox_protection_facts()` when the acting user is not the sole holder.
- **One button:** "Open mailbox" → the staff reader
  (`/plugins/mailbox/admin/admin_mailbox_reader`), whose rail shows the
  protection badge — the natural "go look at it" destination from an admin page.

**Receipt lifetime:** shown only on arrival from a raise (the `sealed_now`
marker) or on the in-place completion transition. An ordinary editor visit
never shows it — it is the response to an event, not a banner. Re-loading the
marker URL re-shows it; the facts are still true, so this is harmless.

### Every raise lands on the receipt

The zero-backlog raise redirect changes: instead of the accounts list with a
generic flash, redirect to the editor with `sealed_now=1` like every other
raise. One code path, one destination.

### Fortress: the receipt is a handoff

Sealing completion is not the Fortress finish line — outbound protection
(`admin_mailbox_protect`) still follows. The card resolves to the same layout
with an honest title — "Earlier messages sealed — one step left" — and the
button becomes **"Continue: activate outbound protection"** → the protect
ceremony. This replaces today's automatic `then=protect` redirect chain; the
zero-backlog Fortress raise keeps its direct redirect to the protect page (no
receipt to show yet — the protect ceremony owns its own completion).

### What disappears

For level raises: the "Domain saved — sealing…" flash, the yellow countdown
alert, and the green "All earlier messages…" one-liner. The card is the only
voice. Non-level edits keep the plain "Domain saved." flash.

## Out of scope (deferred)

- **Lowering receipt + unsealing.** Lowering a sealing level today is a plain
  save, and messages sealed while the domain was Private/Fortress **stay
  sealed** afterward (there is no unseal job). Whether lowering should unseal
  history — and what its receipt says — is a separate spec
  (`mailbox_lowering_unseal.md`, unwritten). Until then lowering keeps its
  plain "Domain saved."

## Tests

Extend `plugins/mailbox/tests/` (db tier, shared harness):

- Zero-backlog raise redirects to the editor with the `sealed_now` marker (not
  the accounts list).
- `mailbox/seal_batch` action: permission gate (below-staff refused), response
  shape `{sealed, remaining}`, and single-batch bound.
- Stuck-batch state: a domain with an unsealable row (holder vault removed
  post-raise) returns `sealed=0, remaining>0` — the contract the JS guard
  keys on.
- Ceremony state for the editor: `sealing_active` unchanged; receipt facts
  derive holder names from `mailbox_protection_facts()`.

## Docs updates (same change)

- `plugins/mailbox/docs/overview.md` — rewrite the "A raise converges history"
  paragraph as current state: the editor's ceremony card runs sealing in place
  via `mailbox/seal_batch` and resolves into a receipt (title, fact rows, Open
  mailbox); Fortress hands off to the protect ceremony from the card. No
  migration narrative. (docs/api.md has no per-action table — plugin actions
  are documented in their feature docs, so overview.md is the whole update.)
