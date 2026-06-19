# Inbound Email — Import Filters from a Gmail Export

## Summary

Add an **Import** button to the Filters tab that ingests Gmail's
`mailFilters.xml` export (Gmail *Settings → Filters and Blocked Addresses →
Export*) and turns each exported filter into an `InboundEmailFilter` row for the
**currently-selected mailbox**. The operator uploads the file, sees a preview of
exactly what will be created (and what can't be mapped), and confirms. This makes
moving an existing Gmail rule set onto the platform a one-shot operation instead
of re-entering dozens of filters by hand.

This builds on `specs/implemented/inbound_email_filters.md` — it reuses that
feature's data model, mapping vocabulary, and the per-mailbox Filters UI. It adds
**no** new disposition behavior: an imported filter is an ordinary
`InboundEmailFilter` and runs through the same `runForMessage` engine.

## Scope

- Imports the Gmail **filter** export (`mailFilters.xml`, an Atom feed with
  `apps:property` elements). Nothing else — not Gmail labels, settings, vacation
  responders, or `.mbox` mail.
- Imports **into the mailbox the operator is viewing** on the Filters tab (a
  mailbox, or a domain-wide bucket — the same scope model the manual create flow
  uses). The Filters tab already lists only mailboxes where filters can fire
  (locally-stored, non-IMAP), so import inherits that constraint for free.
- One Gmail `<entry>` → at most one `InboundEmailFilter` row.

## Goals

- A single **Import filters** button on the per-mailbox Filters list.
- Faithfully map every Gmail criterion/action that has a platform equivalent
  (see the mapping table); cleanly and **visibly** drop the ones that don't,
  rather than silently losing them.
- A **preview-then-confirm** step (mirroring Gmail's own import dialog) so nothing
  is created until the operator sees the result, including per-entry "skipped
  because…" notes.
- Be re-import-safe: importing the same file twice does not silently double every
  rule.

## Non-Goals

- **No label import.** Gmail's `label` action has no target on a filterable
  mailbox — labels are `iif_` folders that belong to IMAP feeds, and filters only
  run on non-IMAP mailboxes (see `specs/implemented/inbound_email_filters.md`). A
  `label` action is reported as skipped, not applied. (If local labels for
  store-only mailboxes are ever added, this becomes a follow-up.)
- **No importance / categories / chats.** `shouldAlwaysMarkAsImportant`,
  `shouldNeverMarkAsImportant`, `smartLabelToApply` (Categorize as), and
  chat-exclusion properties have no platform concept and are skipped (recorded in
  the inventory below so the omission is a decision, not a gap) — exactly as the
  base filters spec decided.
- **No Gmail search-operator parsing.** `hasTheWord` / `doesNotHaveTheWord` map to
  the existing tokenized has-words / excludes fields verbatim; Gmail's
  `from:`/`OR`/parenthesis mini-DSL inside those strings is not interpreted (same
  position as the base spec's full-text matching).
- **No export.** This spec is import-only. (Exporting our filters *to* Gmail XML
  is a possible future symmetry, not built here.)
- **No re-forward of history.** Import only creates rules; it does not run them
  against existing stored mail. (The operator can tick the filter's existing
  "Also apply to matching existing mail" afterward, which the backfill task
  handles.)

## The Gmail export format

`mailFilters.xml` is an Atom `<feed>` (namespaces `http://www.w3.org/2005/Atom`
and `apps: http://schemas.google.com/apps/2006`). Each filter is an `<entry>`
with `<category term='filter'>` and a flat list of:

```xml
<entry>
  <category term='filter'></category>
  <title>Mail Filter</title>
  <id>tag:mail.google.com,2008:filter:1404318321164</id>
  <apps:property name='from' value='dealnews'/>
  <apps:property name='label' value='deals'/>
  <apps:property name='shouldArchive' value='true'/>
  <apps:property name='sizeOperator' value='s_sl'/>
  <apps:property name='sizeUnit' value='s_smb'/>
</entry>
```

Properties are name/value pairs; boolean actions carry `value='true'`. Gmail
filters are **unnamed** (every `<title>` is the literal "Mail Filter").

**The size trap (observed in `specs/mailFilters.xml`):** every one of the 44
entries carries `sizeOperator='s_sl'` and `sizeUnit='s_smb'` even though none has
a `size` value. Gmail emits default size enums unconditionally. The importer MUST
treat size as a criterion **only when a `size` property with a numeric value is
present**, and ignore a lone `sizeOperator`/`sizeUnit`. Otherwise every imported
filter would gain a bogus "size < 0 MB" criterion.

## Property mapping

### Criteria

| Gmail property | → `InboundEmailFilter` field | Notes |
|---|---|---|
| `from` | `fil_match_from` | verbatim; commas already mean OR in both systems |
| `to` | `fil_match_to` | verbatim |
| `subject` | `fil_match_subject` | verbatim |
| `hasTheWord` | `fil_match_has_words` | verbatim string (no operator parsing) |
| `doesNotHaveTheWord` | `fil_match_excludes` | verbatim string |
| `hasAttachment` = `true` | `fil_match_has_attachment` = true | |
| `size` (+ `sizeOperator`, `sizeUnit`) | `fil_match_size_op` + `fil_match_size_bytes` | **only when `size` has a numeric value.** `sizeOperator`: `s_sl`→`lt`, `s_sg`→`gt`. `sizeUnit`: `s_sb`→bytes, `s_skb`→×1024, `s_smb`→×1048576. Normalize to bytes. |

### Actions

| Gmail property | → `InboundEmailFilter` field | Notes |
|---|---|---|
| `shouldArchive` = `true` | `fil_action_archive` = true | "Skip the Inbox" |
| `shouldMarkAsRead` = `true` | `fil_action_mark_read` = true | |
| `shouldStar` = `true` | `fil_action_star` = true | |
| `shouldTrash` = `true` | `fil_action_delete` = true | soft-delete |
| `shouldNeverSpam` = `true` | `fil_action_never_spam` = true | |
| `forwardTo` | `fil_action_forward_to` | validated as an email on save (operator-only, no verification handshake — same as manual create) |
| `label` | **skipped** | no label target on a filterable (non-IMAP) mailbox; reported per entry |
| `shouldAlwaysMarkAsImportant`, `shouldNeverMarkAsImportant` | **skipped** | no importance signal |
| `smartLabelToApply` (Categorize as) | **skipped** | no category tabs |
| chat-exclusion properties | **skipped** | no chat stream |

Gmail has **no "mark as spam" filter action**, so `fil_action_mark_spam` is never
set by import (it stays available for manual filters).

**Name.** Gmail entries are unnamed, so the importer synthesizes `fil_name` from
the first non-empty criterion (e.g. `From: dealnews`) so the list is readable.

**Importable test.** After mapping, an entry is imported only if it has **≥ 1
criterion AND ≥ 1 action** — the same floor the model enforces. An entry that maps
to no actions (e.g. a label-only filter, once `label` is dropped) is **skipped**
and shown in the preview with the reason, rather than creating a rule that does
nothing.

## Design

### UI — Import button + preview/confirm

The per-mailbox Filters list (`admin_inbound_email_filters.php`, the `list` mode)
gains an **Import filters** button next to *Create filter for this mailbox*,
carrying the active scope. The flow is three small views of the same admin page,
matching the existing `op=`-driven wizard:

1. **`op=import`** — a FormWriter form with a single file input (the Gmail
   `mailFilters.xml`) and a fixed *"Import into: `<mailbox>`"* context line (the
   active scope, carried hidden), plus an *Import* submit. No prose beyond the
   helptext; self-documenting per the admin-page conventions.
2. **`op=import_preview`** (on upload) — parse the XML and render a **preview
   table**: one row per `<entry>` with its synthesized name, mapped criteria,
   mapped actions as chips, and a *Skipped* column listing any unmapped Gmail
   properties (e.g. "label: deals", "categorize"). Importable rows have a checked
   *Import* checkbox (default on); skipped/non-importable rows are shown disabled
   with the reason. The raw XML is carried in a hidden field so the confirm step
   re-parses deterministically (no server-side session state). A *"Create N
   filters"* submit confirms.
3. **confirm** (`save_import`) — re-parse, create one `InboundEmailFilter` per
   checked, importable entry scoped to the active mailbox, then redirect back to
   the scoped Filters list with a flash summary: *"Imported N filters; skipped M
   (K label actions, …)."*

All three are FormWriter forms. The preview is read-only data; the only inputs are
the per-row checkboxes + the hidden raw XML + scope.

### Parsing & mapping (on the model)

Keep the class count flat (consistent with the base spec folding the engine onto
the model): add **one static method** to `InboundEmailFilter`:

```
InboundEmailFilter::parseGmailExport(string $xml): array
```

It returns an array of **candidate** structs — pure data, no DB writes, unit
testable:

```
[
  'name'      => 'From: dealnews',
  'fields'    => [ fil_match_from => 'dealnews', fil_action_archive => true, ... ],
  'skipped'   => [ 'label: deals' ],     // human-readable unmapped properties
  'importable'=> true,                    // ≥1 criterion AND ≥1 action
]
```

Parsing uses `SimpleXMLElement` with the `apps` namespace registered; the size
trap and the importable test live here. The admin logic calls
`parseGmailExport()` for both the preview and the confirm, then — on confirm — for
each checked importable candidate, builds an `InboundEmailFilter`, sets the
mapped fields + the active scope, and calls `prepare()`/`save()` (the same path
`_filter_save()` already uses; mapping reuses, not duplicates, that save logic).

The parser writes through the **model layer**; there is no new engine and no new
class — just `parseGmailExport()` (mapping) and a create loop in the logic.

### Idempotency / re-import

To keep a double-import from doubling every rule, the confirm step **skips a
candidate that exactly matches an existing non-deleted filter in the same scope**
(same criteria + same actions). This is a cheap signature compare over the mapped
fields; matches are reported in the summary as "N already present." This is a
*should*, not a hard guarantee — the operator can still create intentional
duplicates manually.

## Security

- The upload is operator-only (permission ≥ 5), like the rest of the Filters tab.
- The XML is untrusted input: parse with external entity loading disabled and
  `LIBXML_NONET` (no DOCTYPE/XXE, no network fetch); cap the upload size (e.g. 1 MB
  — the sample is ~22 KB) and the entry count; treat all property values as opaque
  text bound through the model's prepared statements.
- `forwardTo` targets inherit the manual-create validation (valid address; same
  open question about an allow-list as the base spec) — import does not widen the
  forward surface.

## Testing

- **`parseGmailExport()` unit tests** against `specs/mailFilters.xml` (moved to a
  test fixture on implementation): asserts 44 entries parse; the size trap yields
  **no** size criterion; `from`/`label`/`shouldArchive` map correctly; `label`
  lands in `skipped`; the single `subject`, `shouldMarkAsRead`, `hasTheWord`,
  `doesNotHaveTheWord`, and `shouldNeverSpam` entries map as tabled.
- **Mapping edge tests:** size *with* a value (each unit + operator → bytes);
  `hasAttachment`; a synthetic label-only entry → non-importable; an entry with no
  criteria → non-importable.
- **End-to-end:** upload → preview shows the right counts → confirm creates exactly
  the importable/checked rows scoped to the active mailbox → re-import of the same
  file skips all as "already present."
- **Malformed input:** non-XML, wrong-root XML, and an entry with unknown
  properties all fail gracefully (clear error, nothing created).

## Docs

When this ships, fold an **Importing from Gmail** note into the Filters section of
`plugins/inbound_email/docs/overview.md` (current-state voice; do not create a new
doc file): the Import button, what maps, what is skipped (labels/importance/
categories/chats), and the size-default caveat. No doc changes land with the spec
itself.

The example `specs/mailFilters.xml` should move to a test fixture
(`plugins/inbound_email/tests/fixtures/`) when implemented, rather than staying in
`specs/`.

## Open decisions (resolve at implementation, not now)

- **Skipped-label loudness.** The sample is dominated by "apply label + archive"
  rules; after dropping `label` they import as archive-only. Confirm that
  surfacing the dropped labels in the preview + summary is enough, versus also
  offering (future) local labels so the label action has a target.
- **Name synthesis vs. blank.** Synthesize `From: …`-style names (proposed) or
  import unnamed and let the list show "(unnamed)".
- **Duplicate signature.** Exact criteria+action match (proposed) vs. a looser
  match (e.g. criteria only) for the re-import skip.
- **Multi-scope import.** v1 imports everything into the one active mailbox.
  Whether to ever let one file fan out across mailboxes (Gmail has no per-account
  dimension in the export) is deferred.
