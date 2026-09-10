# Security inventory closures, mail items, September 2026

**Status:** Implemented 2026-09-09. The record of the six mail
rows closed from `security_inventory.md` (the 1.0 bar): S14, S16, S17, S18,
S19 and S21 — the "what a message can make the system do" findings B7, B8
(both halves), B9 and B10, plus the Direct spool bound. The inventory keeps
the S-numbers and points here.

Each section says what the door was, what closes it, and where the closure is
pinned. The reasoning is in the inventory's § What a message can make the
system do; this file is the end state.

## S14 — a stranger's message cannot start a forward loop

**The door.** Inbound filters and aliases forward mail on arrival, and the
forward stamped one custom header and checked nothing. Two owners forwarding
to each other, or a rule whose destination landed back on a matching rule,
relayed one message through the relay until something rate-limited — and one
message from a stranger started it.

**The close.** Every forward the router builds carries
`X-Forwarded-By: Joinery Inbound Email` and `Auto-Submitted: auto-forwarded`
(an original that already declares itself auto-submitted keeps its own
declaration). Before relaying, every forward path — alias forward,
`forward_and_store`, the catch-all forward and the filter action — asks
`InboundEmailRouter::forwardLoopRefusal()`, which refuses on any of three
signals: our own marker, a foreign `auto-forwarded`, or thirty `Received`
headers. `auto-replied` and `auto-generated` are left alone so a bounce or a
vacation reply still reaches the owner. A refused message is never bounced (a
bounce to a forwarder is the same loop by another route): the alias path logs
it `rejected` with the reason and keeps whatever its delivery mode keeps; the
filter action relays nothing. The filter action reads the retained header
block when the row keeps no raw, so a lean record is guarded too — a
synthesized forward would otherwise carry none of the arrival headers.

**Pinned by** `plugins/mailbox/tests/forward_loop_guard_test.php` (db tier,
23 checks): the three signals and the two that must not trip; a forward
parsed back is refused in one hop; the alias path drops and logs and keeps
the `forward_and_store` copy; the stored-message path relays nothing for a
marked lean record and once for a plain one, and what reaches the relay is
itself marked.

## S17 — the schedule job proposes; the owner writes

**The door.** `EmailScheduleJob` turned its verdict on a stranger's message
into a calendar row with nobody clicking. The row was tentative, but it was
on the calendar, in reminders, in the feed.

**The close.** `recordVerdict()` queues a `create_calendar_entry` proposal
through `ActionQueue::propose()` — recipe-sourced, on the job's area, no
conversation — and the entry exists only once the owner approves the card,
which is rendered from the literal arguments. Provenance is the message id:
a re-judged message replaces its pending proposal instead of adding a second,
and an approved re-run updates the same entry. Two pieces are general, not
job-specific: `CreateCalendarEntryTool` (`recipe_tools/`, queueable, the
subject always the acting user, calls `CalendarEntryImporter`) and
`ApprovedActionContext`, the `ToolContext` a recipe-sourced action executes
under on approval — the recipe's allow-lists, the recipe owner as the acting
user — which is what S16 will use when agent-mode recipes queue their
writes. A proposal whose recipe is gone fails closed. The card names the
proposing recipe.

**Pinned by** `plugins/joinery_ai/tests/schedule_job_proposal_test.php` (db
tier, 25 checks): no entry after the verdict, one pending recipe-sourced
proposal with the title on the card; a re-judge replaces it; approval writes
one tentative entry on the owner's calendar with email provenance; decline
writes nothing; a deleted recipe fails the approval.

## S16 — a recipe that reads strangers proposes; the owner writes

**The door.** An agent-mode recipe runs from cron with a tool belt: read
records, create, update and delete them, invoke actions, fetch pages,
remember, save notes. When its allow-lists let it read content written by
other people, the admin ticked "act on content written by other people" once
at save, and from then on every run executed every write with nobody
watching. A message the model had just read could steer it into a write,
and the one-time tick was the whole defence.

**The close.** The recipe run context answers `queuesWrites()` from the same
predicate the gate always used, `TaintGate::forRecipe()`: an agent recipe
whose tools can write something the world sees while it reads an untrusted
model field, a web page, or its own carried workspace, queues every mutating
call through `ActionQueue::enqueue()` as a recipe-sourced proposal — no
conversation, the recipe as its source — and the model is told the call has
not run. The owner approves or declines the card, which names the recipe,
and an approval executes under `ApprovedActionContext` with the recipe's own
allow-lists and its owner as the acting user (the S17 machinery). The run's
status note says how many changes are waiting. Two refinements to the
predicate: the write set now includes `remember`, `forget` and `save_note`
(a memory or note the next conversation reads is a write the world sees),
and the web tools count as an untrusted source (a fetched page is a
stranger's text). The one write a queuing recipe keeps inline is its own
workspace (`RecipeRunContext::OWN_STATE_TOOLS`): nothing but that recipe
reads it, and it is wrapped as untrusted when it does, so queuing it would
break the recipe's own bookkeeping without closing anything.

With that, the standing approval covers pipeline verdicts only. The agent-
mode save gate and run-start drift stop are gone: a recipe that drifts into
reading outside content starts queuing, which needs nobody's acknowledgment.
The editor's live badge says which case applies — changes apply directly,
or each becomes a card — and the checkbox is labelled as pipeline-only. A
recipe that reads nothing outside still runs its writes inline, and hot
egress there is still refused rather than queued, since no one is present.

**Pinned by** `plugins/joinery_ai/tests/recipe_queued_writes_test.php` (db
tier): the predicate for models, web tools and memory tools; a tainted
recipe's context queues and an untainted one does not; a scripted run
queues a `remember` call as a recipe-sourced pending row and writes no
memory, while `set_workspace` in the same turn runs inline; approval writes
the memory under the recipe's scope; the agent-mode save gate and drift stop
no longer fire. `tests/taint_gate_test.php` keeps the predicate matrix and
the pipeline-mode gate.

## S18 — a planted memory cannot hide

**The door.** `remember` inserted a row verbatim from model output in the
same turn the model read an email. Scope is the acting user only and recall
re-wraps the text as untrusted, so a planted memory could not escalate, but
it could steer every later conversation of that user, and nothing at recall
time said where it had come from.

**The close.** Two halves. Held for approval: chat has queued memory writes
since S15, and a recipe that reads outside content queues them since S16,
so no memory is written from a turn that read a stranger without the owner
seeing the card, content verbatim. Provenance: every context answers
`writeProvenance()` — "recipe *name*, run #N" or "chat #N, approved by you"
or "recipe *name*, approved by you", with "; the recipe reads content
written by other people" appended when the predicate says so — and
`RememberTool` stores it in `mem_provenance`. Recall shows it as "from …"
in the memory index, in prefetched bodies and in `recall` results, and the
memory lists and edit pages show it too. A memory a message planted says so
wherever it is seen.

**Pinned by** the same suite: a memory written on approval carries the
recipe name and the outside-content clause, and recall renders it.

## S19 — the domain's consent binds for Standard mail too

**The door.** The per-domain `local|trusted|cloud` consent was folded into
the recipe's model requirement only when something sealed was in play. A
Standard mailbox's mail went to whichever endpoint had a key, with the
setting ignored — and the form did not even show it at Standard.

**The close.** `MailboxAliasConfig::aiProcessingConsent()` answers from the
stored consent at every security level; `EmailPipelineJobBase::processingConsent()`
folds every bound address, not only the sealed ones; and
`RecipeVaultScope::consentTrustFloor()` binds for every pipeline recipe with
a job (agent mode stays out of scope for the invariant `scopeOrThrow()`
states). The domain form shows the travel consent at Standard, with the read
switch still sealed-only, and the save path no longer forces it back to
`local` there. The refusal wording no longer assumes the mail is encrypted at
rest. Existing Standard domains carry the column default, `local`, so a
Standard-domain recipe pinned to a cloud model stops at its next run until
the domain says otherwise — the owner accepted that flip on 2026-09-09.

**Pinned by** `plugins/joinery_ai/tests/in_window_email_test.php` (db tier):
a Standard domain answers `local`; a Standard-mailbox recipe is floored to
local, a cloud pin is refused with a message naming the domain page and not
claiming encryption, and consenting to the cloud lifts the floor.

## S21 — one sender cannot be the whole of a recipient's allowance

**The door.** On Private and Fortress the Direct receiver accepts
unconditionally and defers the contact gate to unlock, so held mail is
bounded only per recipient domain and per recipient address. One stranger
could fill an address's or the domain's entire allowance by itself.

**The close.** A third cap, `joinery_direct_spool_sender_cap_bytes` (default
512 MB), bounds the held bytes per **verified sending domain** across every
recipient — per domain, not per address, because addresses under a domain
are free to invent. `DirectSpoolService::capRefusal()` takes the verified
domain from the receiver and refuses `507 Direct spool is full for this
sender`; instance configuration applied identically to every address, so it
discloses nothing about the recipient. `DirectSpool::bytesForSenderDomain()`
is the counter, over the `jdp_sender_domain` the spool row already kept.

**Pinned by** `tests/direct/joinery_direct_receive_test.php` (db tier, new
section): a sender under its cap is accepted and charged; its next delivery
is refused 507 naming the sender; a different sending domain to the same
recipient is still accepted.
