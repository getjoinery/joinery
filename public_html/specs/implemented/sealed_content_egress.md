# Sealed content must not escape into unsealed storage

**Status:** Implemented. The three live defects, **Layer 0**, **Layer 1 for
`rcr_recipe_runs`**, **Layer 2 (the hot-turn rule)** and resolved decisions 6
and 7 are all in the tree (commits 090ad98a, bb71449c, the review fix pass
extending `save()`'s sealed-skip to the seal metadata columns, and the Layer 2
checkin). Layer 3 stays a standing preference rather than a build: the agent
trace cap and the reference-over-copy rule apply at every new write site, and
the hot-turn rule is what raises them when someone forgets. Layer 1's remaining
tables are demand-driven by resolved decision 10 and are deliberately not built
— the next refusal the rule raises is what asks for the next one.

Layer 2 landed as: `SealedEgressGuard` holds one boolean per process, armed by
`VaultCrypto::openField()` and attributed by `VaultUnlock::secretKey()`;
`GuardedPdo` / `GuardedPdoStatement` run the rule under every INSERT and UPDATE
in the platform (`DbConnector` builds the guarded connection, so nothing else
changes); `EmailSender::send()` and `sendBatch()` refuse a hot send that carries
no `EGRESS_*` assertion, and `InboundEmailRouter::forwardStoredMessage()` names
the same assertion so the SMTP relay cannot slip past by using a different
transport. `SealedEgressGuard::isolate()` scopes the rule to one unit of work —
`RecipeRunner::run()` and the harness fixture builders are the callers — and an
outer hot state survives it, so nesting cannot launder a process cold.

Dev triage, per the build note below: three writers tripped and all three
resolved at the write site, none by exempting a table. The error log stores a
type/file/line reference instead of the message and trace while hot
(`GeneralError::logError()`). `RecipeRunner` brackets each run, because a drain
slice runs several independent recipes for one user and the first protected one
would otherwise fail every later one. Test fixtures that deliberately construct
pre-sealing state bracket those writes.

One Layer 0 defect surfaced building this and is fixed in the same checkin:
`rowIsSealed()` read a seal flag with a naive truth test, and every false
spelling a boolean arrives in except a bare `false` — `'f'`, `'0'`, and the
string `'false'` a field spec may declare as its default — is truthy in PHP. A
row wrongly believed sealed has its content columns skipped by `save()` and
silently never written. `SystemBase::sealFlagIsSet()` now matches the false
spellings explicitly, and both the instance and raw-row paths go through it.

**Descope, owner-decided 2026-08-02:** the verbatim-copy matcher (the original
"Layer 2b" — plaintext registration at `VaultCrypto::openField()`, normalized
rolling-hash shingles at the PDO layer) is **removed from scope**, and Layer 1
stops enumerating future tables pre-emptively — `mem_memories`, `rcn_notes`,
`rcp_recipes.rcp_workspace` and `cal_entries` seal **on demand**, when the
hot-turn rule refuses a write a product flow actually needs (see Layer 1).
Rationale recorded under resolved decision 9 and Rejected alternatives. The
first two checkins grew well past the feature's size because every sink was
being closed by hand; the hot-turn rule is the single choke point that caps
that curve, so it is built next and everything else queues behind refusals it
actually raises.

Layer 1 landed for the run table as: the run row seals to the recipe owner at run
start when `RecipeVaultScope::forRecipe()` answers non-null; `rcr_output`,
`rcr_tool_calls`, `rcr_error` and the workspace pair are `$sealed_fields`;
`rcr_tool_calls` moved from `jsonb` to `text`; `RecipeRun::writeContent()` /
`saveContent()` are the writers and `contentOrNull()` / `toolCalls()` the
locked-safe readers; a new unsealed `rcr_status_note` carries the reaper's and
the admin-cancel verdicts, which are written by actors holding no key; the
delivery and failure emails withhold content on a sealed run; `RunContentPurge`
clears pre-existing plaintext (resolved decision 2).

That purge runs from joinery_ai's `sync.php` hook and NOT from a core migration.
Core migrations execute several hundred lines before `PluginManager::sync()` adds
or alters plugin columns, so a migration touching `rcr_content_sealed` queries a
table that has not got it yet. The sync hook is the only place those columns are
guaranteed to exist. Migration slot 160 is retained as a no-op so version
numbering stays unbroken.

Layer 0 landed as: `save()` skips a sealed row's sealed columns,
generic `decryptSealedField()` / `decryptSealedFieldStatic()` / `sealColumns()` on
`SystemBase` behind the `{prefix}_content_sealed` / `_sealed_key` /
`_sealed_owner_user_id` / `_key_generation` convention, with
`sealedOwnerUserIdFor()` / `sealedFieldIsActive()` / `sealAd()` as the override
surface. `InboundEmailMessage` and `MailboxContact` are migrated onto it; the chat
models still carry their own hooks (their DEK resolves through the conversation).
Documented in `docs/sealed_vault.md` § The two generic consumer hooks.

## The problem

A Fortress mailbox promises that nobody — not an admin, not someone holding a
database dump — can read the mail without the owner's key. The vault keeps that
promise at the source: `iem_subject`, `iem_body_plain`, `iem_ai_summary` and the
rest are encrypted at rest and decryptable only inside the owner's unlock window.

Then the AI triage recipe runs inside that window, decrypts a subject, and writes
it here:

```json
[{"label": "Delivery Status Notification (Failure)", "status": "done",
  "verdict": {"label": "none",
              "summary": "This is a delivery failure notification stating that an
                          email sent to cae-gmail-...@inbox.dev.getjoinery.com
                          could not be delivered because the recipient's domain
                          does not exist."},
  "item_key": "92"}]
```

That is `rcr_recipe_runs.rcr_tool_calls` — a plain `jsonb` column on a table with
no sealing of any kind. The subject came out of the vault. The summary is a
description of the body. Both now sit in the clear, permanently, readable by
anyone who can read the database, with no unlock window required.

### Sinks on the recipe feature

| Sink | What leaks |
|---|---|
| `rcr_recipe_runs.rcr_tool_calls` | item label (subject) + full verdict incl. AI summary; in **agent mode** the trace records each tool's *full output* (`AgentLoop.php:367`) — entire decrypted bodies, not just subjects |
| `rcr_recipe_runs.rcr_output` | the Markdown tally — one line per item, subject + verdict gist |
| `rcr_recipe_runs.rcr_workspace_before` / `rcr_workspace_after` | LLM-authored workspace prose about whatever the agent read |
| `rcr_recipe_runs.rcr_error` | provider and job errors can echo content |
| `rcp_recipes.rcp_workspace` | the *durable* copy of the workspace — a different table, re-injected into every later prompt |
| `iem_ai_scan` on the message row itself | jsonb `{verdict, red_flags, summary}` where `red_flags[].finding` quotes the body by prompt design — while sibling `iem_ai_summary` **is** sealed, with a comment explaining why |
| `cal_entries.cal_title` | `EmailScheduleJob` writes model-derived titles from sealed bodies/ICS |
| Delivery email (`rcp_delivery_email`) | the tally, over SMTP |
| `equ_queued_emails.equ_subject` / `equ_body` | a delivery email that fails to send is persisted verbatim by `EmailSender::queueForRetry()` — permanent plaintext |
| The provider request | **sink zero**: the decrypted digest is POSTed to whatever `rcp_model` names. `RecipeRunner.php:86` uses `LlmProviderFactory::forModel()`; the Fortress local-model pin (`forConversation()`) covers chat only |

### The same pattern platform-wide

The copy-instead-of-reference pattern is not a Joinery AI quirk. Confirmed
instances elsewhere:

| Where | The copy |
|---|---|
| Standard chat, `aim_content` / `aim_tool_calls` | Chat sealing is per-conversation (`ChatSeal::turnColumns()`); a *standard* chat with an open window reads sealed mail via `query_model` and its plaintext trace stores the decrypted rows verbatim |
| `remember` → `mem_memories` | Unsealed table; `ChatMemory::activeFor()` lets a standard chat remember sealed content, which is then re-injected into every future turn, including cloud ones |
| `save_note` → `rcn_notes` | Unsealed; offered to protected chats too, so a Fortress chat can launder its own content out |
| `search_conversations` | The gate (`SearchConversationsTool.php:91`) checks model + window but not the *calling* conversation's level — private/Fortress snippets land in a standard chat's plaintext trace |
| `aik_api_idempotency_keys.aik_response_body` | Every `/api/v1` response is cached verbatim when the client sends an idempotency key — including protected-chat `chat_send` payloads and mail read over the API. Core code, invisible to a consumer-side audit |
| `AiAttachment.php:205` | Decrypted attachment bytes staged to disk-backed `/tmp` for text extraction — while `ChatAsync::scratchDir()` explicitly fails closed rather than use a disk temp dir |
| `ima_filename`, `iel_from_address` | Sideways plaintext copies of sealed facts (the delivery log blanks the subject with a "no sideways copies" comment, but keeps the sender) |
| Filter `forward_to` | Relays the full decrypted MIME off-platform in-window (user-configured — consent-gated per resolved decision 7) |

Verified clean, for the record: drive and the password manager (client-custody —
the server never holds plaintext), the mailbox FTS index (sealed blob, `/dev/shm`
working copies, passive sweep), thread-list previews (render-time only),
notifications (none exist for mail/chat), the `error_log` surface (ids and
exception messages only), and `aip_recipe_item_log` (references only).

## Live defects — fix first, independent of the layers

These are bugs in the current tree, not design work:

1. **`save()` on a sealed message corrupts it.** `EmailTriageJob.php:186` and
   `EmailSecurityScanJob.php:201` call `$msg->save()`. `SystemBase::save()`
   rebuilds every column via `get()` (`SystemBase.php:1447-1450`), which
   *decrypts* sealed fields — so the first sealed message triaged gets its
   sender, subject and both bodies written back as plaintext with
   `iem_content_sealed` still true. Every later read then AEAD-opens plaintext
   and throws `'SealedBox: malformed AEAD blob.'` Leak and corruption in one
   move. The rule that prevents it exists only as a docblock in
   `ChatSeal.php:23-29` — which is itself the defect Layer 0 removes.
2. **`iem_ai_scan` must join `$sealed_fields`** (per-row, like its siblings). It
   is an AI summary of the sealed body sitting in clear jsonb on the same row
   that seals `iem_ai_summary`.
3. **Recipes bypass the provider locality pin.** `RecipeRunner` must stop
   routing on model id alone; the policy it enforces is resolved decision 5
   (cloud behind a second, explicit per-domain consent), but the status quo —
   sealed digests silently POSTable to cloud endpoints with no check at all —
   is a defect independent of that policy.

## This is a discipline failure, not a trade-off

`specs/implemented/joinery_ai_item_pipeline.md` specifies `rcr_output` as
"Markdown tally (one line per item: label + verdict gist)" and mentions sealing
zero times. The pipeline was designed before its jobs could reach sealed mail, and
nothing re-examined it when they could.

The proof that this was avoidable is in the same subsystem. `aip_recipe_item_log`
stores `aip_item_key` — a message id — plus a status and a timestamp. No subject,
no summary, no content at all. One table in this feature stores a reference; the
other stores copies. Same authors, same week, opposite outcomes, because nothing
in the platform made one easier than the other.

The second proof is live defect 1 above: chat documented the never-`save()` rule
for itself, and the very next sealed consumer — same platform, months later —
walked straight into the hazard. A rule that lives in a docblock protects exactly
one subsystem.

That is the actual defect. Not the sinks — the fact that a developer writing
the next one has nothing stopping them.

## What "can't be screwed up" has to mean

The original draft of this spec rested on two structural premises that turned out
to be false. Recording the corrected versions, because the design's anchoring
follows from them:

1. **Decryption choke points — two for sealed model columns, more overall.**
   `SystemBase::get()` → `decryptSealedField()` and the raw-row
   `decryptSealedFieldStatic()` cover column reads through models. But attachment
   bytes, raw RFC822 messages, the FTS index blob, chat attachment bytes, and the
   key-rotation callbacks all call `VaultCrypto::openField()` /
   `SealedBox` directly. The one function *everything* server-side passes through
   is `VaultCrypto::openField()` — so that, not the model hooks, is where
   registration and the hot-turn flag anchor (with the model hooks adding
   source attribution when available). Drive and passwords are client-custody:
   the server never holds their plaintext, so they are out of scope by
   construction — nothing to register, nothing to leak server-side.
2. **There is no single write path.** `save()` is sealed-unaware, sealed
   consumers therefore *ban* it and persist via `updateColumns()` raw UPDATEs
   (44 call sites), and the motivating leak itself is a raw UPDATE
   (`RecipeRunContext::flushToolCalls()`, line 226). A guard hooked at `save()`
   would miss the very write that produced the example row above. The only
   layer every DB write crosses is the PDO statement layer in `DbConnector` —
   so that is where the guard anchors.
3. **Sealing needs only the owner's vault public key.** Any process can seal
   content to a user at any time (`sealItemDek($dek, uev_public_key)`); only
   *reading* needs the in-window secret. So sealed run rows can always be
   written — even if the window lapses mid-run — and Layer 1 has no
   liveness problem.

## The design

Four layers. They are not alternatives — each covers what the others cannot.

### Layer 0 — Write-side sealing becomes a SystemBase primitive

Today, sealing a model costs ~130 lines of bespoke crypto plumbing per model
(`sealAndPersistContent()` and friends), `save()` will silently destroy a sealed
row, and every consumer must know a docblock rule to avoid corruption. That is
why one table in the same feature stored references while its sibling stored
copies: the safe thing was the expensive thing.

Layer 0 makes it declarative:

- A model that declares `$sealed_fields` also declares (or gets by
  `{prefix}_content_sealed` / `{prefix}_sealed_key` convention) its row-flag and
  key columns. `SystemBase` supplies the generic implementations of
  `decryptSealedField()` / `decryptSealedFieldStatic()` — the per-row
  flag check, owner resolution, DEK unwrap and AEAD open that
  `InboundEmailMessage` and `ChatSeal` currently hand-roll — with a small
  override surface (owner-field name, AD convention) for the existing models to
  migrate onto.
- `SystemBase` gains a `sealColumns(owner_vault, [col => plaintext, ...])`
  helper: mints or reuses the row DEK, seals each value, sets the row flag, and
  persists via a targeted UPDATE. This is `sealAndPersistContent()` promoted
  from a mailbox routine to the platform primitive.
- **`save()` becomes sealed-safe:** on a row whose seal flag is set, `save()`
  skips the `$sealed_fields` columns entirely — they are owned by
  `sealColumns()`. Writing them any other way is what live defect 1 is. This
  single change retires the entire corruption hazard class and removes the
  reason sealed consumers needed raw-UPDATE bypasses in the first place.

### Layer 1 — Protection is a property of the record, not the field

A record derived from sealed material is itself sealed material. `rcr_recipe_runs`
gains `rcr_content_sealed` and `rcr_sealed_key`; a run whose recipe reads from a
sealed source (the job's `requiresVaultScope()` already answers this) is a sealed
run, encrypted to the recipe owner.

**Sensitivity here is a property of the row, not the column.** `rcr_output` holds
nothing protected for a standard-mailbox recipe and holds subjects lifted out of
sealed mail for a Fortress one. Same model, same column, different answer per
row. Only the row knows, so the row carries the flag — exactly the
`iem_content_sealed` pattern already shipped on three models.

Content columns, declared the ordinary way on `RecipeRun`:

```php
public static $sealed_fields = array(
    'rcr_output', 'rcr_tool_calls', 'rcr_error',
    'rcr_workspace_before', 'rcr_workspace_after',
);
```

(The original draft listed three of these five. The fix spec itself
under-enumerated the one table it audited — which is why the estate test below
asserts across *every* column, and why Layer 2 exists.)

The same row-flag treatment extends to the other per-row-sensitive tables the
audit surfaced — `rcp_recipes` (`rcp_workspace`), `mem_memories`
(`mem_title`, `mem_content`, `mem_tags`), `rcn_notes` (`rcn_title`,
`rcn_content`) — but **on demand, not pre-emptively**. Each of those tables
seals when the hot-turn rule (Layer 2) refuses a write that a product flow
actually needs: the refusal names the destination, and the fix is either a
Layer 0 declaration on that model (a flag column plus `$sealed_fields`, not a
crypto project) or — preferred — storing a reference instead (Layer 3).
Until then they carry no sealing columns and no code. What protects them in
the meantime is the same thing that protects them today: the flows that would
write sealed-derived content into them are refused by the hot-turn rule the
moment it exists, and before it exists they are unreachable from sealed
content by runner topology (a CLI worker holds no window; the in-window drain
runs pipeline recipes, which use none of these sinks — the invariant pinned in
`RecipeVaultScope::scopeOrThrow()` and the `sealed_egress` test).

Enumerating and sealing all of them up front was the original plan and is
deliberately abandoned: it is O(sinks) forever, each sink is a checkin the
size of the run-log one, and the enumeration is exactly the thing the audit
proved nobody keeps complete.

This is the only layer that covers *derived* content — an AI summary is new prose
that shares no substring with the body it describes, so no mechanical check can
recognise it. Structure is the only thing that catches it.

### Layer 2 — The hot-turn rule: one rule at one anchor

**This is the single choke point the rest of the design leans on.** It lives at
the PDO statement layer in `DbConnector` (see corrected premise 2 — the only
true write choke point), plus one hook in `EmailSender::send()`. It is armed
only when the process has actually opened sealed content, which is rare, so the
production cost rounds to zero everywhere else.

When `VaultCrypto::openField()` first hands out a sealed plaintext, the process
is marked *hot*, recording the owner and scope (the model hooks add source
model+field attribution when they are the caller). While hot, any string value
above the threshold (resolved decision 8: 64 characters) written by an INSERT
or UPDATE must land in a row that seals:

- destination model declares sealing support → the write proceeds with the row
  flag set and the columns sealed to the recorded owner (Layer 0 makes this one
  call);
- the value is already ciphertext (`v1.aead.` prefix) or the destination row is
  sealed to the recorded owner → the write proceeds; this is how `sealColumns()`
  and `writeContent()` pass through the guard they sit behind;
- destination cannot seal → `SealedContentEgressException`, naming the
  destination table and the source scope that made the process hot. The fix is
  at the write site, in preference order: store a reference (Layer 3), make the
  destination sealable (a Layer 0 declaration), or don't write the content.

At `EmailSender::send()`, the same state applies: while hot, a send is refused
unless the call site passes an explicit content-free flag — the reviewed
assertion that the message was built only from unsealed data (the sealed-run
pointer emails `RecipeRunner` already sends are the pattern). This closes both
the SMTP sink and the `equ_queued_emails` retry spill, because a message that
is never sent is never queued for retry.

This is the confidentiality twin of `TaintGate` (which tracks *injection*
provenance and already proves the predicate-enforced-at-the-choke-point
pattern). It is deliberately coarse: no string matching, so it cannot be
defeated by paraphrase — and the audit shows the majority of real leaks
(`remember`, `save_note`, calendar titles, workspaces, AI summaries) are
paraphrase-shaped, invisible to any matcher. Strictness is resolved
decision 8: always refuse, no table exemptions.

Note the distinction from the rejected *tainted-value wrapper*: nothing wraps
values, so there is no `__toString` escape hatch and no read-site breakage. The
taint is one flag on the process, not a type threaded through the codebase.

Two deliberate exemptions, both consent-shaped rather than table-shaped:

- **The AI provider client.** Sending the digest to the model *is the feature*;
  which providers may receive sealed digests is governed by resolved decision 5
  (per-domain cloud consent), enforced in `LlmProviderFactory`, not here.
- **The acknowledged `forward_to` filter** (resolved decision 7): the recorded
  acknowledgment *is* the consent, so the forward path passes the
  `EmailSender` hook the same content-free-style flag, backed by the
  acknowledgment row rather than a code-review claim.

**Build note — first triage list.** Arming is per PHP process (one web request,
one CLI run). Known writers that will trip the rule in dev and must be triaged
at their write sites during the build, not by exempting tables: session
persistence if DB-backed, `vew_visitor_events` URLs, error/audit log rows that
quote values, and `aip_recipe_item_log` notes if any grow past the threshold.
Each is expected to resolve as "reference or cap, not content" — if one cannot,
that is evidence worth bringing back to this spec, not a reason to weaken the
rule.

### Layer 3 — Prefer a reference to a copy

Where a pointer will do, store the pointer. The run trace and tally keep
`item_key` and the outcome; subjects and summaries are resolved from the message
at display time, through the sealed reader, inside a window. `aip_recipe_item_log`
already works this way and is the model to copy.

Two additions from the audit:

- **Agent-mode traces cap tool outputs** regardless of sealing. Recording a
  bounded excerpt of what a tool returned is a trace; recording entire mail
  bodies is a second copy of the mailbox, disproportionate even encrypted.
- Beyond leaking, a copy goes stale: edit or delete a message and the run log
  still quotes the old text forever — a reference degrades to "item deleted",
  which is the truthful answer.

## What the operator sees

Run history for a sealed recipe shows what it did, not what it read: item counts,
per-item status, tokens, cost, errors. Inside an unlock window the display
resolves item keys and shows subjects; outside one it shows ids.

The delivery email for a sealed recipe carries the tally with content suppressed —
counts and outcomes, no subjects, no summaries — and says why, with a link to the
run. Mail is an unencrypted channel; sealed content must not go through it at all.
(The hot-turn hook at `EmailSender::send()` enforces this even if a future
producer forgets.)

## Resolved decisions

1. **The guard is always-on.** Both rules arm only on requests that actually
   opened sealed content; every other request pays one boolean check.
   Dev-and-test-only was rejected because it contradicts the guard's whole
   justification — covering code paths no test exercises. Cost is confirmed by
   measurement in dev before ship, not by guess.
2. **Existing unsealed run rows: purge content columns** on runs belonging to
   sealed-source recipes, keep counts and timings. No production users, and no
   sealed mail has ever been AI-processed (see Blast radius).
3. **No opt-out declaration mechanism** (`$unsealed_fields`). See Rejected
   alternatives. The gap it would have covered — a future content column nobody
   thought about — is instead covered by the hot-turn rule (Layer 2) and the
   every-column estate test.
4. **The agent workspace seals.** Survey confirmed no out-of-window consumer:
   the dispatcher reaper reads only status/error columns
   (`RecipeDispatcher.php:46-58`), and the dashboard renders in-session.
   `rcr_workspace_before/after` and `rcp_workspace` are in the Layer 1 lists.
5. **Sealed mail may go to a cloud model only behind a second, explicit
   per-domain consent.** The existing "allow AI processing" toggle governs
   whether AI reads the domain's mail at all; a separate acknowledgment —
   "send this domain's decrypted mail to cloud AI models", default off, set
   where the first toggle lives (`MailboxAliasConfig`) — governs whether that
   reading may leave the box. Enforcement is a recipe analogue of
   `forConversation()` in `LlmProviderFactory`: a sealed-source recipe naming a
   cloud model is refused unless every domain it reads carries the consent.
   Checked at recipe save **and re-checked at run start** (the `TaintGate`
   precedent), so withdrawing consent later stops a cloud-pinned recipe at its
   next run rather than silently continuing. `isPrivate()` stays advisory UI
   metadata; Fireworks counts as cloud. The chat-side Fortress pin is
   unchanged by this decision.

6. **The idempotency cache seals the cached body per-row.**
   `ApiIdempotencyKey` gets the standard Layer 1 treatment: when the request
   was hot, `aik_response_body` is sealed to the vault owner whose scope was
   open. Replay inside the owner's window returns the body normally; replay
   outside it — or when the original request opened more than one owner's
   scope, in which case no body is stored at all — gets a "response not
   retained, re-issue the request" error while the key row still suppresses
   duplicates. This is not special-cased code: the `aik` INSERT during a hot
   request trips the hot-turn rule (Layer 2), and auto-sealing is that rule's
   normal resolution — the cache is simply the first consumer of the general
   mechanism. Retention sweep behavior is unchanged (sealed rows purge on the
   same `idempotency_key_retention_hours` window, default 24, `0` = keep).

7. **Filter `forward_to` on sealed domains is consent-gated, with re-arm on
   level raise.** Saving a `forward_to` filter on a sealed domain requires an
   explicit acknowledgment naming the egress ("sends decrypted mail from this
   domain in clear text to X"). Raising a domain's security level **clears**
   prior acknowledgments — existing forward filters stop forwarding (mail
   still matches, stores and labels normally) until re-acknowledged. Same
   one-way-tightening rule as `TaintGate`'s opt-in and the same shape as
   resolved decision 5: sealed content leaves the box only behind explicit,
   informed, revocable consent. The forward path itself carries a hot-turn
   exemption analogous to the provider client — the acknowledged filter *is*
   the consent — so the `EmailSender` hook does not fight it.

8. **Hot-turn strictness: always refuse, no opt-out mechanism.** A hot write
   of a long string to a destination that cannot seal throws — the fix is
   always at the write site: make the destination sealable (a declaration
   under Layer 0), store a reference, or don't write the content. There is no
   "this table may receive sealed-derived content in the clear" declaration,
   deliberately: the audit found no legitimate case for one, and it would
   exist only to be misused. Accepted trade, stated plainly: on a code path
   never exercised in dev, this turns a would-be silent leak into a loud
   production exception. For a confidentiality promise that is the correct
   failure direction. Parameters: the threshold is 64 characters; the
   residual gap is any copy — verbatim or paraphrase — below it, accepted
   because the surfaces where short strings carry sealed content (subjects in
   run rows, summaries on message rows) are already sealed structurally by
   Layer 1, and Layer 3 prefers references over copies at every new write
   site. The standing exemption list starts empty, and a false positive found
   in dev is fixed at the write site — cap the log note, store the reference
   — never by exempting the table. The threshold is a constant, recalibrated
   only from dev estate-run evidence; if that evidence ever shows short
   verbatim copies escaping, lowering it is the dial, not resurrecting the
   matcher.

9. **The verbatim-copy matcher is descoped** (owner decision 2026-08-02). The
   original design paired the hot-turn rule with a plaintext-registration +
   normalized-shingle containment check at the same anchors. Dropped because:
   the leak classes it uniquely covered (sub-64-character verbatim copies)
   are handled by Layer 1 at the surfaces that actually carry them; the
   majority leak class (paraphrase) was never coverable by matching at all;
   and the matcher was the single most complex remaining component — its own
   normalization pipeline, defeated in-tree by JSON escaping and truncation
   before it was ever built. One rule at one choke point is the design; two
   rules at the same choke point was the old design paying twice for the
   weaker guarantee. Revisit only on dev estate evidence per decision 8.

10. **Layer 1 stops at demand-driven** (owner decision 2026-08-02, same
    conversation). No new table gains sealing columns until the hot-turn rule
    refuses a write a product flow needs. The refusal is the requirements
    document: it names the destination, the source scope, and the value size,
    and the preferred resolution is a reference, not a seal.

## Blast radius today, and the shipped templates

Real, but not yet realised, and three independent gates stand between a fresh
install and a leak. All three must be passed deliberately, by a person:

1. A shipped template arrives **disabled**, so it never runs on its own.
2. It arrives **unbound** — no mailbox — and the mailbox picker has no
   preselection, so saving without choosing one is refused rather than silently
   binding to whatever sorts first.
3. Pointing any recipe at a sealed domain is refused outright until that domain
   is set to allow AI reading — `EmailJobCandidates::assertAiProcessingAllowed()`
   throws at save time, naming the domain and the setting.

One correction from the survey: had all three gates been passed, the outcome
would not have been a silent leak but a *loud* one — live defect 1 means the
first sealed message triaged is corrupted, and its plaintext is written into the
sealed columns. Encryption at rest fails either way.

That safety is circumstantial rather than structural, which is what makes the
ordering matter: **this fix should land before any operator is encouraged to point
a shipped template at a sealed mailbox.** Nothing in the product currently invites
them to, and nothing should until the run log stops copying what it reads.

## Tests

- a pipeline run against a sealed binding leaves no plaintext of any source
  `$sealed_fields` value anywhere in `rcr_recipe_runs`, `rcp_recipes`,
  `mem_memories`, `rcn_notes`, `cal_entries`, `aik_api_idempotency_keys` or
  `equ_queued_emails` — asserted across **every** column of each table, so a
  column added later is covered without anyone updating the test;
- the same run against a standard binding still records subjects and summaries,
  so the suppression is conditional and not a blanket loss of detail;
- `save()` on a sealed row never writes plaintext into a sealed column
  (regression for live defect 1), and `sealColumns()` round-trips;
- a sealed run's content columns are unreadable with the vault locked and
  readable with it open;
- the hot-turn rule: a process that opened sealed content and writes a long
  string into a non-sealing table throws `SealedContentEgressException` naming
  the destination; the same write in a cold process passes; a write of
  already-sealed ciphertext, and a write into a row sealed to the recorded
  owner, both pass while hot;
- values at and below the threshold pass while hot (the accepted residual gap
  of resolved decision 8, pinned so a threshold change is a deliberate act);
- the delivery email for a sealed recipe contains no subject or summary, and
  `EmailSender::send()` refuses a hot send that does not carry the
  content-free flag — and therefore nothing hot ever reaches
  `equ_queued_emails` via the retry queue;
- run history renders outside an unlock window without throwing;
- a sealed-source recipe naming a cloud model is refused at save and at run
  start without the domain's cloud consent, and runs with it; withdrawing the
  consent stops the next run;
- a `forward_to` filter on a sealed domain is refused at save without the
  acknowledgment, forwards with it, and stops forwarding (while still
  matching and labeling) after the domain's level is raised, until
  re-acknowledged;
- a hot request's cached idempotency body is sealed: replay in-window returns
  it, replay locked gets "response not retained" with duplicate-suppression
  intact, and no plaintext of the response survives in
  `aik_api_idempotency_keys`.

## Docs to update

- **`docs/sealed_vault.md`** — a "Derived content" section: the record-level
  sealing rule and why sensitivity lives on the row rather than the column; the
  Layer 0 write-side contract (`save()` skips sealed columns; `sealColumns()`
  is the only writer); the hot-turn rule, its threshold, and the accepted
  residual gap; the reference-over-copy rule for anything reading sealed
  material.
- **`plugins/joinery_ai/docs/overview.md`** — what a sealed recipe's run history
  and delivery email contain, and why they differ from a standard recipe's;
  memory/notes sealing behavior for protected sources.
- **`docs/api.md`** — idempotency-cache behavior for sealed content: sealed
  replay bodies, the "response not retained" outcome, per resolved decision 6.

## Rejected alternatives

**Suppress item content in the tally when the binding is sealed.** The first fix
proposed, and a band-aid: it patches one sink of many, and it patches it by asking
the producer to remember a rule. The next sink leaks.

**A documented invariant plus one estate test.** Better, and the test is worth
having regardless — it is in the list above. But a test only covers the paths it
exercises, and it fails *after* someone has written the leaking code rather than
preventing it. (The never-`save()` docblock in `ChatSeal.php` is this
alternative's real-world trial: it protected chat and did not protect the next
consumer.)

**Invert the declaration (`$unsealed_fields`).** A record that can carry protected
content would name the columns that are safe in the clear, and everything else
would be sealed automatically, so a column added later without thought failed
safe. Rejected on cost: most columns added to a run table are metadata — a retry
count, a duration, a status — and under opt-out every one of those is encrypted
unless its author remembers to exempt it, which breaks any query that sorts or
filters on it. The mechanism would fire constantly for the common case to protect
against the rare one. The gap it uniquely covered — a future *paraphrase* column
nobody declared — is now covered structurally by the hot-turn rule (Layer 2), which
fires on the write regardless of what the column is called.

**Tainted-value wrapper.** Decryption returns a `SealedString` object that sinks
must explicitly unwrap. The strongest per-value guarantee available, and rejected
on cost: without `__toString` every existing read site breaks, and with it the
protection silently evaporates at the first `(string)` cast. The *per-turn* taint
adopted as Layer 2 keeps the provenance idea and discards the wrapper: one
boolean on the request, no type threading, no cast escape hatch.

**The verbatim-copy matcher (the original Layer 2b).** Plaintext registration
at decrypt time plus normalized rolling-hash shingle containment at the PDO
anchor and `EmailSender`. Carried in this spec through two checkins and then
descoped (resolved decision 9): its unique coverage — short verbatim copies —
is carried by Layer 1 at the surfaces that hold them, its normalization
pipeline was already defeated in-tree by JSON escaping and truncation before
it existed, and it could never see the paraphrase class that motivated the
guard in the first place. The hot-turn rule alone is the guard.

**Just do not log content.** Sounds clean, is not: the run trace is the debugging
surface, and for a standard mailbox there is nothing to protect and real value in
the detail. The rule has to be conditional on the source's protection, which is
what Layer 1 encodes.
