# Support Chat — Help With Installing and Using Joinery

**Status:** Built 2026-10-15 (WP1–WP5), reviewed by reviewer2 (R1–R11, all fixed).
The plugin is committed (d9624923, 80e69686) and kept out of releases
(`included_in_publish` false); the core Help panel ships off (a blank `support_url`).
Verified on dev: the engine, intake and site client suites, live reads through dev's
own agent, the cache check on dev's local model, and the panel, widget and operator
pages in a browser. The cloud-model cache check, a first real site asking getjoinery,
and the panel staying off across the fleet are in the live verification queue.

## The goal, in one sentence

A person running Joinery, or about to, asks a question in plain words and gets
an answer drawn from the docs for the release they are on and, when their site
is joined to getjoinery, from what their site is actually doing right now.

## What exists, and is reused rather than rebuilt

- **The chat engine** is the joinery_ai plugin: `AgentLoop`, `ChatRunner`, the
  tool registry (`ToolContext`), detached turns with polling, attachments,
  `CostGuard`, the model catalog, and the untrusted-input wrapping
  (`UntrustedEnvelope`, `TaintGate`). The support bot is one more turn context
  over the same loop. There is no second engine and no second transcript store.
- **The sessionless intake pattern** is the bug_reports plugin's: a sessionless
  action with its own rate bucket per address, refusing what is not well formed.
  Its proof of identity is not enough here: a HEAD call that reads
  `X-Joinery-Version` proves a Joinery site exists at the claimed host, not
  that the request came from it, and a thread can read a joined site's logs.
  Support confirms the origin instead (see *Identity the site presents*).
- **The scrubbed facts about a site** are `ProblemReportBundle`: an explicit
  allowlist of sections, every value through `LogRedactor`, never a setting
  value, a member, a secret, or anything sealed. The page shows the bundle to
  the person before it is sent. The support panel sends the same bundle.
- **The destination** is the node `support_url` names; blank means no Help
  panel (see *Who serves a site*).
- **The account link** is `ServicesConnect` (on the management node) and
  `ServicesClient` (on the site): the "Connect your getjoinery account" button
  on the setup wizard, one `ApiKey` per site per account.
- **The agent's vocabulary** (`specs/agent_recipes_and_vocabulary.md`) is the
  complete list of what can happen to a joined node. Its observe words have
  closed parameters, bounded redacted output, a ledger entry per call, and each
  was reviewed as if the caller were hostile. The plane mirrors their closed
  parameter sets in `JobCommandBuilder` and routes by the words a node reports
  at poll (`mgn_agent_primitives`).
- **The docs** are the `docs/` tree, every plugin's `docs/`, and the marketing
  pages, all inside every release archive.
- **The AI panel's docking contract** (`plugins/joinery_ai/assets/ai_panel.js`)
  is the slide-over the help panel uses.

## Decisions

- **D1 The engine runs on getjoinery.** Settled 2026-10-14. One current docs
  index, zero setup on the customer's site, the model cost is the vendor's, and
  the question leaves the customer's box in a form the person has read. A
  site with no AI configured still gets help. The tool set is the same on
  either side, so a site that wants to run it on its own model later can: the
  docs are already on its disk.
- **D2 Retrieval is full-text first.** Settled 2026-10-14. Postgres full-text
  search over doc sections, with the model searching agentically (several
  queries, read a section, search again). No embedding step and no vector
  extension. The "questions the docs could not answer" report decides whether
  embeddings are ever needed.
- **D3 Any Joinery site may ask, with no account.** Settled 2026-10-14. The
  host verification callback plus a per-host daily budget bound the cost. The
  account link adds history across threads, priority, and human replies.
- **Live reads of the person's site are in scope.** Settled 2026-10-14. On a
  joined node the model may call observe words during a turn, under the
  consent rules below. Operate and destructive words never run from a chat.
- **The plane's own record of a managed site is in scope.** Settled 2026-10-14
  (Q2 of the first discussion). Incidents, backup runs, the job ledger, script
  trust, the version: everything the node page shows.

## The site side (core)

### Where it appears

- A **Help** button in the admin header on every admin page, permission 5 and
  up. It opens a slide-over built on the AI panel's docking contract. An admin
  page with a right-hand column may dock it there.
- Inside every **setup wizard** step (`views/setup.php`), with the step's key
  as context, so "I'm on the Email step and it says the domain is not verified"
  needs no explaining.
- Members never see it. A customer site's own members asking how to use that
  site is a different product, served by the site's own joinery_ai chat on its
  own model (open question Q1).

### What travels with a question

The first message of a thread carries `ProblemReportBundle` built for the
asking person's own permission, as a problem report is: an operator (9 and up)
sends the site-wide sections (runtime, plugins, settings names, health, recent
errors), anyone below the member scope. The bundle gains a `support` section:
the admin route or wizard step the person is on, and the thread's consent
flags. Later messages
carry the thread id and the current route only. A new version of the bundle
format is a new `FORMAT` value; the receiver reads what it knows.

Before the first send on a site the panel shows the bundle, section by
section, exactly as the report page does, with one sentence per section on
what it grants, and asks once. The answer is a site setting
(`support_chat_consent`: `yes`, `no`, or blank until answered), changeable on
the Management Node page beside `agent_log_access`. The site's facts are sent
only when it is yes; no asks without them. A site that has not answered shows
the question in the panel instead of the composer.

### The transcript

The transcript renderer is core and one component: `ChatTranscript` (a
finished turn), `assets/js/chat_transcript.js` (the turn being written, its
status line, the poll loop, Retry) and `assets/css/chat_transcript.css`.
joinery_ai's `ChatRender` and chat page draw through it (byte-identical output,
no visible change), and the Help panel draws the same markup. joinery_ai keeps
its composer controls (model, toggles, levels) and its attachments, usage and
Copy/Delete, which the help panel does not have.

The panel never talks to the serving node from the browser: the site is the
identity, the browser has no key, and the bundle is built where the facts
are. It calls this site's own actions (`support_state`, `support_consent`,
`support_send`, `support_poll`, `support_cancel`, `support_threads`,
`support_feedback`, `support_escalate`), and `SupportChatClient` sends them on
to the node's `desk_*` actions. A finished answer comes back drawn by
`ChatTranscript` on the site's server (the desk's Markdown, raw HTML escaped).

### Identity the site presents

- **A new thread proves its origin.** The site's server issues a one-time
  code, keeps its hash on the site's own thread row, and sends it with the
  first question. The desk calls the claimed host back
  (`POST /api/v1/action/support_origin_confirm`), and the site answers yes
  only for a code it issued in the last five minutes and has not used, naming
  the desk it issued the code for. The desk refuses a code issued for another
  desk, so a code handed to one desk opens nothing at another. A host that
  does not confirm is refused outright. The confirm action has no rate limit
  of its own: a code cannot be guessed, and a limit keyed to the desk would
  let anyone who prompts the desk to call back lock the real desk out.
- **Every later call proves continuity** with the thread's token, which the
  desk gives once when it opens the thread and keeps only as a hash.
- **Connected site** (services key for the same node): it also signs with the
  key pair; the thread belongs to that account and takes calls signed with
  that account's key only. With the key, no token is needed: the desk lists
  the account's threads from the host, and a reinstalled site, connected
  again, keeps a row for each with no token (history across threads, D3).
- **Joined node:** the desk finds the node by `mgn_site_url` matching the
  proven host, again on every question, so a site that joins later is read
  from its next question on. Only then do live reads exist.

### What the person can do in a thread

- Ask, see the answer stream, cancel a turn, retry a failed one.
- Thumbs up or down on an answer, with an optional line. Stored on the message.
- **Talk to a person.** Flags the thread for a human on the plane. A reply
  from the human appears in the thread on the panel's next poll or open. The
  panel shows the thread as waiting on a person and does not pretend the
  model is still answering.
- Open a past thread: the site's own `spt_support_threads` rows (desk thread
  id and token); a connected site also sees its account's threads for this
  host.
- **Report this as a bug.** Hands the thread to the existing problem report
  flow with the thread id in the comment.

## The server side (plugin `support_desk` on the management node)

### Who serves a site

A site asks the node named by `support_url`. Blank means no Help panel: no
button, and the panel's actions refuse. Q4 settled the upgrade source
(`ProblemReport::destinationUrl()`, where bug reports go) as the fallback; it
was built that way and switched off on 2026-10-15, when the core panel had to
go out in a release before getjoinery runs the desk, so that no site shows a
button that answers "not offered". Making the upgrade source the fallback
again is a one-line change in `SupportChatClient::destinationUrl()`, for the
release after getjoinery runs the plugin. Settled 2026-10-14 (Q4):

- **The serving node answers when the plugin is active there**, with its own
  index, its own model keys and its own operator. getjoinery serves the sites
  that upgrade from it; a republishing site serves the sites below it.
- **Nothing is forwarded.** A node without the plugin answers the panel's
  first call with "support is not offered here", and the panel shows that
  sentence with a link to the public docs. getjoinery never holds
  facts about a site it has no relationship with, and live reads work exactly
  where the node is joined.
- **Anyone may run it.** The plugin installs on any management node from the
  plugin installer, with the node's own joinery_ai model keys. That node
  receives releases through the ordinary updater, and with each release the
  docs and the shipped FAQ for it, so a self-run support node is never
  behind getjoinery on what it knows. Its sites reach it by republishing
  from it, or by `support_url` alone when they upgrade from getjoinery
  directly.

### The knowledge index

Two sources, one table. `sds_doc_sections` holds release, path, heading
chain, body and a `tsvector`, one row per section, and every tool reads it
without caring which source a row came from.

**The docs.** Every `docs/**/*.md`, every plugin's `docs/**/*.md`, the root
files in `SupportDocIndex::ROOT_FILES`, and the shipped FAQ (below) are split
by heading into rows. A section longer than a cap is split at paragraph
boundaries with the heading chain repeated. Full-text search runs on an
expression GIN index (`FullTextSearch`), not a stored `tsvector` column.
The index for a release is built by `SupportDocIndex` from whatever the node
holds of it: `buildFromArchive()` reads a release archive it serves (it
published it, or received it to republish), `buildFromTree()` its own running
tree. The one caller is `SupportIndexSweep` (hourly, and the plugin's sync for
the node's own release): it indexes every release the node holds that has no
index yet, the newest five archives and its own release. No hook in
publishing or upgrading, so a self-run node indexes what it holds and
getjoinery indexes what it makes. Tables: `sds_doc_sections` (the rows) and
`sdr_doc_releases` (one per release, with its docs map and shipped FAQ). A release's index is built once and never edited; a
republish of the same release leaves it. A thread searches the rows of the
release the asking site runs; a site on a release that has no index on this
node searches the nearest indexed release and the answer says so.

**The marketing pages** carry no release; they are indexed from the live
`page_contents` of the serving node on every index build and tagged
`current`. A thread searches its release's rows plus `current`.

### FAQs

Questions people actually ask, answered once, searched like the docs, read
by the model and shown to nobody else. The heading of a FAQ section is the
question itself, which is why it lands in the same section table as a doc: a
question matches a question far better than it matches a reference heading,
so the model's first search usually hits the FAQ when one exists.

This is knowledge, not a page. A public FAQ, if a node wants one, is a page
in page contents like any other marketing page, written for visitors and
indexed as `current` with the rest; it needs nothing from this plugin and
this plugin writes nothing to it. A support thread is a conversation, and
what the operator learns from it reaches the model as a rewritten question
and answer, never as the thread.

**Shipped FAQ.** `docs/faq/*.md` in the core tree, one file per topic
(install, upgrade, mail, backups, ...), one `##` heading per question, the
answer beneath, plain Markdown with no front matter. It is docs: it ships
in every release, it is versioned with the release, it reads as the current
state only, and it is indexed with the docs. A plugin may carry
`docs/faq/*.md` of its own.

**Local FAQ.** `sfq_faq_entries` (`SupportFaqEntry`) on the serving node:
question, answer (Markdown), topic, the thread it came from, who wrote it,
when, and the release it was written on. Written by the operator, mostly
from the gap report: a thread the model could not answer has an **Answer
once** button that opens the entry form with the thread beside it as
reference and empty fields for the general question and its answer; saving
it answers that thread as a person *and* indexes the entry (path
`faq/local/{id}`, tagged `current`, re-indexed on every save, removed on
delete). The next site that asks the same thing gets it from the model. The
thread reply is the same mechanism a plain human reply uses, with "keep as
FAQ" as the one addition.

How the local FAQ touches the rest of the system, and nothing else:

- **The index** is its only contact with the model. The support engine never
  reads the table; it searches. The table needs no model-read opt-in.
- **Release drift.** A row records the release it was written on; the model
  sees that line with the answer, and the admin FAQ page sorts oldest-written
  first so stale rows come up for a reread.
- **Not the memory system.** joinery_ai's memory is per-person and
  automatic; the FAQ is per-node and curated. The support turn context has
  no memory tool.
- Soft delete, foreign-key actions to the thread and the author, backed up
  and copied like any table.

**From local to shipped.** An entry worth every site having is moved into
`docs/faq/` by hand and committed; the local row is then retired. The
suggest-upstream button, which would send a local entry to the serving
node's own upgrade source as a proposed FAQ, is phase two.

### The engine

`SupportTurnContext implements ToolContext`, built by `ChatRunner` the way
`ChatTurnContext` is, with these differences:

- **No write tools, ever.** `queuesWrites()` is irrelevant because no mutating
  tool is in the allowed set. The data-access, web, history and memory toggles
  of a user chat do not exist here; the tool set is fixed by the thread's
  identity and consent.
- **Tools, docs group** (every thread): `search_docs {query, release?}` returns
  heading chains and a snippet, capped; `read_doc_section {path, heading}`
  returns one section, capped.
- **Tools, plane group** (threads from a joined node): `node_overview`
  (version, posture, state, last poll, script trust, the agent's words),
  `node_incidents {open_only}`, `node_backup_runs {count}`, `node_jobs
  {count}`. Read from the plugin's own models; nothing is asked of the node.
- **Tools, node group** (threads from a joined node whose thread consent
  allows live reads): one tool per allowed observe word, each tool's schema
  derived from the plane's mirror of the word's closed set
  (`JobCommandBuilder::SITE_LOG_FILES`, `LOG_TABLES`, `UNIT_JOURNAL_UNITS`,
  `FILE_HEAD_FILES`, and the parameterless words). The allowed list is a
  constant in the plugin (`SupportNodeTools::WORDS`), a subset of the observe
  class: `host_report`, `site_log`, `log_table_tail`, `unit_journal`,
  `file_head`, `disk_usage`, `schema_probe`, `page_probe`,
  `container_inventory`, `listening_sockets`, `spam_learning_status`. The
  tools offered in a turn are that list intersected with the words the node
  reported at its last poll. A word the node does not report is not offered,
  so an older agent gives the model fewer tools and never a refusal to
  explain.
- **Tools, handoff group** (every thread): `escalate {reason}` flags the
  thread for a person and ends the model's part of the turn with a fixed
  sentence; `file_bug_report {summary}` opens a bug_reports row from the
  thread's bundle.
- **One tool call is one job.** A node-group call creates a `ManagementJob`
  of that word, created by the support desk's reserved user, with
  `requested_by: support:{thread id}` in the job's recorded parameters, waits for the result
  with a per-call ceiling (60 s, then the tool returns "the site did not
  answer in time" and the turn continues), and hands the result to the model
  inside the untrusted envelope. Turns already run detached, so a few seconds
  per read costs nothing at the proxy; the activity label reads "Reading your
  site's error log…" while it waits. The per-turn iteration cap bounds how
  many reads one question can spend, and a per-thread cap (20 node reads a
  day) bounds the rest.
- **Only offered tools run.** joinery_ai's `AgentLoop` refuses a tool the run
  did not offer, before anything is looked up (the tool registry knows every
  plugin's tools). That closed a gap in chat too: a chat with Web search off
  still ran `fetch_url` when the model named it. The refusal is in the trace,
  which is how a node word the model reached for on an agent without it is
  counted.
- **Model and cost.** A model from the catalog, chosen by `support_desk_model`;
  the budgets are `SupportBudget`: Joinery AI's monthly cap (support turns are
  chat messages and count toward it), a per-host daily token budget
  (`support_desk_daily_tokens_per_host`) and a plane-wide daily budget. Over
  budget, the panel says so and offers the docs link and "Talk to a person".
- **System prompt and context.** See *The prompt, in three layers* below.

### The prompt, in three layers

The provider caches by exact prefix and charges a fraction for the cached
part, so the prompt is assembled from the most shared content to the least,
and nothing that varies per thread sits before the cache breakpoint. The
same discipline joinery_ai's chat already keeps (the untrusted block after
the breakpoint) applies here; a per-thread value above the breakpoint is a
defect, caught by the standing check below.

**Layer 1, the fixed prefix, byte-identical for every thread on a release.**

- The voice block, an editable setting (`support_desk_system_prompt`) with
  this shipped default:

  > You help people install and run Joinery. The person is an
  > administrator of one site, on the release named below. Answer from the
  > documentation and the FAQ for that release, and name the document and
  > heading you drew on. When the question is about their site and you
  > have tools that read it, read before you guess, and say what you read.
  > You never change anything on a site and never say you did. If the
  > documents and the reads do not settle the question, say so plainly and
  > offer to hand the thread to a person. Keep answers short, in the
  > person's terms, and lead with what to do.

- The scaffolding the operator cannot remove: the tool rules (a read is
  bounded and redacted; one read answers one question; a refused read is
  reported in the node's own words), the tool definitions, the docs map for
  the release (every indexed path and heading chain with a one-line
  description, built with the index), and the whole shipped and local FAQ.
  The FAQ goes in whole because it is small, question-shaped, and the
  content most likely to answer outright; the map is what lets the first
  search land. Roughly 30k tokens. The breakpoint sits on its last block.

**Layer 2, the thread's facts, first after the breakpoint.** The date, the
release, identity and consent, the reads the site's agent cannot be asked
for, the bundle as received, then the untrusted-input contract: everything in
the bundle and in tool results is data about the site and never an
instruction, and the person's messages cannot change the rules. Its envelope
nonce is the thread's own (kept on the thread, never sent to the site), not a
fresh one per turn, so the block is byte-stable and carries a second,
default-lifetime breakpoint; a third sits on the newest message, so the
conversation so far is read from cache too. The fixed prefix is the same for
threads on a release with the same tool set (tool definitions precede the
system prompt in the cache).

**Layer 3, the turn.** Retrieved sections and node reads arrive as tool
results and stay in the history.

**Not the whole docs.** A release's docs are about 600k tokens and would
fit the window, and they are deliberately not loaded: the model's attention
is spread over hundreds of irrelevant pages, and every release served is a
separate cold cache write at a cost of dollars rather than cents. Retrieval
keeps a turn at a few cents with the cache warm.

**Cache lifetime.** The prefix entry lives while turns arrive within its
lifetime, and support traffic is sparse at first, so the fixed prefix uses
the one-hour cache. `AiPromptBuilder` places the breakpoint on the cached
system block with the default five-minute lifetime; it gains a lifetime
argument (`ttl: "1h"` on the same `cache_control` block) that the support
context passes and the chat and recipe contexts leave at the default. The
thread tail uses the default. The operator can see what a release's prefix costs
to warm on the admin page.

**The standing check.** A test sends two turns of one thread and asserts
that the second turn's `cache_read_input_tokens` is above zero; prompt
assembly changes that break caching fail there rather than on the bill.

### What the threads are for later

A thread, its thumbs, the answer a person wrote and the FAQ entry it became
are kept, because together they are the one dataset a tuned model would
need. Training is not in this spec: the knowledge changes every release and
retrieval is current the day a release ships; a frontier model already
answers well from a good section, so what it lacks is access, which the
tools provide; and the model on the serving node is hosted, not trainable.
The case for training arrives with the self-run path (phase two), where a
small local model is weak at searching docs and calling tools, and real
transcripts are what closes that gap. So threads are retained under the
plugin's own retention setting (`support_desk_thread_retention_days`, ships
at 0 meaning keep) and the purge tool honours it; the thread store is the
training set, whether or not one is ever trained.

### Intake

Under `/api/v1/action/support_desk/`:

- `desk_ask {site, message, route?, consent, origin_nonce? + bundle? | thread_id + token}`.
  Sessionless, optionally signed with a services key. In order: the
  address's refused calls this hour, and for a new thread the new threads it
  opened this hour (counted when tried: each costs a call back); the site's
  shape (a host and a version); the key when one is presented; for a new
  thread, whether this node can start a worker and the budgets, then the
  origin callback; for a later one, the token; the budgets; then the user
  message and the assistant placeholder are written, a worker is spawned
  (`ChatWorkerSpawner::spawnWorker`), and the poll handle is returned. A
  node that cannot spawn one refuses before writing anything: the turn is
  never run inline, where it would outlast the site's wait. A call that holds
  a thread is not otherwise counted, and polls are not logged. An
  unconfirmed host is refused outright, unlike bug_reports: a report is
  evidence worth keeping, a chat is a cost worth refusing. No attachments are
  carried.
- `desk_poll`, `desk_cancel`, `desk_feedback {message_id, verdict, note?}`,
  `desk_escalate`, each with `thread_id` + `token`; `desk_threads {threads:
  [{thread_id, token}], thread_id?}` (states, unread marks, a transcript).

Every answer is bounded by the same caps the chat API already applies.

### Threads and storage

`sth_support_threads` (`SupportThread`): host, confirmed version, verdict,
the `ApiKey` id when connected, the matched `ManagedNode` id when joined, the
consent flags as sent, state (`open`, `waiting_on_person`, `answered_by_person`,
`closed`), the bundle as received (one per thread, the first message's),
token totals, node reads spent today, the token's hash, the thread's nonce,
the gap-report close, and the `AiConversation` id. A thread waiting on a
person, or taken by an operator, keeps new messages for the person and starts
no model turn.

The transcript is an `AiConversation` with its `AiConversationMessage` rows,
owned by a reserved system user (`support_desk_system_user_id`, created on
plugin install, disabled for sign-in). The conversation carries
`aic_support_thread_id`, a column joinery_ai gains for this. Because the
transcript is the same model, the operator's thread page draws it with the core
transcript renderer, with the tool trace (inputs folded in) and the token
counts each turn recorded; the chat page stays the owner's own list. Thumbs are
stored on the message (`aim_feedback`, `aim_feedback_note`).

### The admin page

`/plugins/support_desk/admin/admin_support_threads` (permission 9):

- Threads, waiting-on-person first, then by last activity. Host, version,
  joined or not, connected or not, thumbs, node reads spent, state.
- A thread opens on its own page; the operator's reply is a message with
  role `assistant` and `aim_author` set to the operator's user id (a column
  joinery_ai gains), which the panel renders with a "from a person" mark. A
  reply moves the thread to `answered_by_person`; the next question from the
  site reopens it for the model unless the operator pinned it to themselves.
- **Questions the docs could not answer:** every turn whose answer escalated
  or was thumbed down, grouped by the search queries the model ran, with the
  releases asked. This is the docs gap list. Each row has **Answer once**,
  which writes a local FAQ entry and answers the thread (see *FAQs*); a row
  is otherwise closed by naming the doc commit or the agent word that
  answers it (`sth_gap_note`). A handoff the person asked for themselves is
  not on it; one the model made is. Widget threads cannot be replied to (a
  visitor leaves no address); their page offers Answer once instead.
- **FAQ entries:** the local entries, editable, with the thread each came
  from and how often the model has cited it since.
- **Words the site could not read:** every node-group tool the model wanted
  that the node did not report, counted by word and agent version, so the
  version floor and the running list in `agent_recipes_and_vocabulary.md` §
  "Words the fleet has asked for" get their rows from use rather than from
  memory.

### Notification

A thread entering `waiting_on_person` sends one email to
`support_desk_notify_email` with the host, the version, the person's last
message and a link to the thread. Not more than one email per thread per hour.

**A reply from a person is announced, never carried, by email.** Settled
2026-10-14 (Q3). For a connected site, one email to the account's address:
"A person replied to your question. Open Help on your site to read it." No
content, no site facts, so a follow-up comes back in the thread and the
conversation stays in one place. An unconnected site has no address and gets
no email. Every site gets the header badge: the Help button carries an unread
mark when a thread has a reply the person has not opened, learned on the
site's daily `SupportReplyCheck` task, when the panel lists threads, and,
while the panel has been used in the last week, from the header at most every
ten minutes through `support_threads`. Opening the thread clears it.

## Consent, the ledger and the floor

Three layers, each one independent of the two others:

1. **The node's own switch.** `agent_log_access` on the node refuses the log
   words and `file_head` whatever the plane asks. Support cannot reach past it
   and does not try: a word the node refuses comes back to the model as the
   refusal sentence, which the model repeats.
2. **The thread's consent.** `support_chat_consent` on the site grants the
   bundle. A second flag, `support_chat_live_reads` (needs the first, and
   permission 9 to grant), grants the node group, asked with the consent
   question on a joined site in words that name the words: "the assistant may read your site's error and task logs, the last
   rows of its login and request logs, its service journals, its host
   configuration files, its disk usage and its listening ports, redacted on
   your server before they leave it; never your members, their mail, their
   files, or any key". Off, the node group is not offered at all.
3. **The ledger.** Every node read is a job on the node's own job list with
   the requester `support:{thread id}`, visible on the node page and in the
   site's own job history, with what came back. The owner can read every
   line the assistant read.

The job ledger is the audit; the thread stores no second copy of a read.

## Trust posture

- Everything in a bundle, a message, an attachment and a node read came from
  another machine. Stored as text, escaped on display, inside the untrusted
  envelope on the way to the model, never a path, never executed.
- The model has no write tool and no operate word, so the worst a hostile
  log line can do is produce a wrong answer. Phase two (below) changes that,
  and is gated on its own approval design.
- The origin callback is the only use of the claimed host. The matched
  `ManagedNode` comes from the confirmed host, never from a node id in the
  request.
- A thread's host is fixed at creation. A call naming a thread is refused,
  with one answer that says nothing about the thread, unless it comes from
  that host, is signed with the account's key when an account opened the
  thread, and carries the token (which that key alone may stand in for).
- Budgets are counted on the plane, per host and plane-wide, with the
  refusal shown in the panel rather than a silent empty answer.

## Pre-install: the marketing widget

The same engine behind a widget on the management node's own marketing
pages, for people with no site yet. A browser session, a captcha on the first
message, a per-address budget, no bundle, no node group, docs tagged
`current` plus the newest release's docs. Threads belong to the session; a
signed-in account's threads belong to the account. Built after the site
panel; it is the same plugin with a second entry point.

## Phase two, not in this spec's work packages

- **Proposed fixes.** The model proposes an operate word ("restart the mail
  gateway", "reset the failed unit"); the proposal lands in the customer's
  panel as a card, and the customer's click runs it as a job with the
  customer as the approver. This is the chat engine's proposal queue with the
  approver moved to the site's side, and needs its own approval design before
  any operate word is reachable from a chat.
- **The site's own model.** `SupportTurnContext` with the docs group reading
  the site's own `docs/` tree and the node group reading the site's own
  agent locally, on the site's joinery_ai model. Nothing leaves the box.
  A small model tuned on the serving node's threads (see *What the threads
  are for later*) is what would make this path answer as well as the hosted
  one.
- **Embeddings**, if the gap report says full-text is the limit.
- **Suggest upstream.** A local FAQ entry sent to the serving node's own
  upgrade source as a proposed shipped FAQ, over the bug_reports intake.

## Work packages

All five built 2026-10-15.

- **WP1 Index and engine, on the plane.** Built: the `support_desk` plugin;
  `sds_doc_sections` / `sdr_doc_releases` built by `SupportDocIndex` through
  `SupportIndexSweep`; `docs/faq/` with a first shipped FAQ (install, upgrade,
  mail, backups); `search_docs` and `read_doc_section`; `SupportTurnContext`
  over `AgentLoop` with the docs and handoff groups; the three-layer prompt;
  `SupportThread`; the `desk_*` intake with origin confirmation and thread
  tokens; the reserved user; `aic_support_thread_id`, `aim_author`,
  `aim_feedback`, `aim_feedback_note` in joinery_ai. Tests:
  `support_desk_engine` (db, fixture index, scripted model) and
  `support_desk_cache` (live: the standing check, passed on dev's local model).
- **WP2 The site panel, in core.** Built: the transcript component in core
  (`ChatTranscript`, `chat_transcript.js`, `chat_transcript.css`; joinery_ai's
  chat page re-pointed, output byte-identical); the Help button, the wizard
  step mount, the consent screen, the `support_*` actions,
  `SupportChatClient`, the bundle's `support` section, `spt_support_threads`
  (`SiteSupportThread`), thumbs, Talk to a person, Report this as a bug,
  `support_url`, "support is not offered here", the unread mark and
  `SupportReplyCheck`, the consent switches on the Management Node page.
  Test: `support_panel` (db, stand-in desk).
- **WP3 The plane and node groups.** Built: `node_overview` and its siblings;
  `SupportNodeTools` with one tool per allowed word from the mirrored closed
  sets; one job per call with its ceiling and the requester; the live-reads
  consent; the per-thread read cap. Test: `support_desk_node_reads` (live,
  dev's own joined node).
- **WP4 Operator surfaces.** Built: the threads page (waiting first), a
  thread's page with replies from a person, take / let go / close, the two
  notifications, the two gap reports with Answer once and close-by-note,
  `SupportFaqEntry` and its page, the index page.
- **WP5 The marketing widget.** Built: `/support_desk/ask`, `widget_ask`,
  `widget_poll`, behind `support_desk_widget_enabled`.

## Open questions

- **Q1** Members of a customer site: no, for this spec. Noted so the
  decision is visible, not because it is in doubt.
- **Q3** Settled 2026-10-14: notify only. See *Notification*.
- **Q4** Settled 2026-10-14: serve if active, never forward, anyone may run
  it on their own management node. See *Who serves a site*.

None open.

## Found while building

- **B1 (fixed)** `AgentLoop` ran any registered tool the model named, offered
  or not: a chat with Web search off still fetched URLs by naming
  `fetch_url`. It refuses unoffered tools for every surface.
- **B2 (fixed)** The bug_reports host callback proves a Joinery site exists at
  a host, not that a request came from it; reused here it would have let
  anyone open a thread as someone else's joined site and read its logs.
  Support uses the origin callback and thread tokens.
- **B3 (fixed)** `_mgmt_stats_format_uptime()` took `%` of a float (uptime is
  fractional): a deprecated implicit conversion, an error under the test
  harness, reached by every operator problem-report bundle.
- **B4 (open, docs)** Four contradictions between docs found writing the
  first FAQ: Cloudflare and Let's Encrypt at install (installation.md vs
  deploy_and_upgrade.md); upgrading a Docker site (re-run install.sh vs
  upgrade.php in the container); whether the Backup task switches on by
  itself (two sections of backups.md); deploy_and_upgrade.md links
  INSTALL_README.md as the installation guide where docs/installation.md is
  the one.
- **B5 (fixed)** The site's `support_send` answer passed the desk's thread
  token to the browser on a first question; the browser is never to hold it.
  It stays on the server. Found by the live check after the review.
- **B6 (decided)** The core panel had to go out in a release before any node
  serves it, so a blank `support_url` turns the panel off instead of falling
  back to the upgrade source (see *Who serves a site*).

## Code review (reviewer2, 2026-10-15)

Identity, tool gating and escaping held; the ChatRender → ChatTranscript move
was traced byte-identical over 356 real renders. Eleven findings, all fixed:

- **R1** One bucket per address counted every call, polls included (about 43
  per answer): a site ran out after two or three questions an hour. Only a new
  thread is counted (`support_desk_new_threads_per_hour`), plus refused calls;
  polls are not logged. The unread check runs once per browser, not per tab.
- **R2** The origin code proved the question came from the site, not that it
  was meant for this desk. The confirm answer names the desk; others refuse it.
- **R3** A follow-up question saved the whole thread row, writing back token
  totals and read counts a running turn had just changed. It writes only its
  own columns.
- **R4** A site over budget still cost a call back and left an empty thread.
  The budget is checked first (the widget likewise).
- **R5** The account's thread list was dead end to end. Built as D3 says: the
  account's key opens its threads, and a reinstalled site adopts them.
- **R6** "Report this as a bug" quoted the title, not the thread. It quotes
  the desk's thread number and host.
- **R7** With no background worker, a turn ran inline past the site's 20 s
  wait. Such a node refuses before writing anything.
- **R8** The transcript sheet loaded on every public page. Only pages that
  show a transcript load it (`ChatTranscript::assets()`).
- **R9** The joined node was fixed at thread creation. Matched again on every
  question.
- **R10** A node indexing its own tree also indexed plugins kept off releases.
  Only plugins that go out with releases are indexed.
- **R11** The site's confirm action allowed 60 checks an hour per caller,
  which the desk is. Removed (see *Identity the site presents*).
