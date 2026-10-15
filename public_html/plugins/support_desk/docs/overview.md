# Support Desk

The support desk answers questions from the administrators of the sites that
upgrade from this node, and from visitors on its public pages. Answers come
from the documentation and FAQ of the release the asking site runs and, for a
site joined to this node that allowed it, from reads of the site through its
agent. It runs on joinery_ai's agent loop with a fixed, read-only tool set.

Install it on a management node that serves sites. A site asks the node named
by its `support_url` setting, or else the node it upgrades from. A node
without this plugin answers "Unknown action", which a site's Help panel shows
as "support is not offered here".

## What it knows

**The index.** `SupportDocIndex` splits a release's documents by heading into
`sds_doc_sections` rows (release, path, heading chain, body), searched with
Postgres full text through `FullTextSearch`. A release's documents are
`docs/**/*.md`, the `docs/**/*.md` of every plugin that goes out with releases
(`included_in_publish` not false) and the root files in
`SupportDocIndex::ROOT_FILES`. A release is indexed once and never edited;
`sdr_doc_releases` holds one row per indexed release with its docs map and its
shipped FAQ.

`SupportIndexSweep` (hourly, and the plugin's sync for the node's own release)
indexes what the node holds and has not indexed: its own release (from the
archive it serves when it serves one, else from its tree) and the newest five
`static_files/joinery-core-*.tar.gz` archives, with plugin docs from the
archives in `static_files/plugins/`. **Index now** on the admin page runs it.

A site on a release with no index here reads the nearest indexed release not
newer than its own, and the model is told so.

**Rows of no release** carry `current`: this node's published public pages
(re-read by each sweep) and the operator's FAQ entries.

**The FAQ.** The shipped FAQ is `docs/faq/*.md` in the release (one file per
topic, each `##` heading a question); a plugin may ship `docs/faq/*.md` too.
The local FAQ is `sfq_faq_entries` (`SupportFaqEntry`), written on this node,
indexed as `faq/local/{id}` on every save and removed on delete. Both are also
carried whole in every thread's fixed prefix. Neither is a page: a public FAQ
is an ordinary page.

## A turn

`SupportTurn::runAndFinalize()` runs `AgentLoop` with a `SupportTurnContext`:
the reserved user (`support_desk_system_user_id`, made by the sync, disabled)
owns the transcript, which is an `AiConversation` with `aic_support_thread_id`
set; the thread's own nonce wraps everything from another machine.

The system prompt has three layers (`SupportPrompt`):

1. The fixed prefix: the voice (`support_desk_system_prompt`, or the shipped
   one), the tool rules, the release's docs map, the shipped FAQ and the local
   FAQ. The same bytes for every thread on a release with the same tools; one-
   hour cache breakpoint.
2. The thread's facts: date, release, identity, consent, the reads the site's
   agent cannot be asked for, the bundle as received, and the untrusted-input
   contract. Stable for the thread; default-lifetime breakpoint.
3. The turn: the history, the newest question prefixed with the page it was
   asked from, a breakpoint on the last message, and tool results.

**Tools** (`SupportTools::forThread()`), none of which changes a site:

| Group | Tools | Offered to |
|---|---|---|
| docs | `search_docs`, `read_doc_section` | every thread |
| handoff | `escalate`, `file_bug_report` (with bug_reports and a bundle) | site threads |
| plane | `node_overview`, `node_incidents`, `node_backup_runs`, `node_jobs` | a site joined here |
| node | one tool per word in `SupportNodeTools::WORDS` the node reports | a joined site that allowed live reads |

A node tool is one `ManagementJob` of that word on the node's own job list,
created by the reserved user with `requested_by: support:{thread}` in its
parameters, waited on for up to 60 seconds; the answer is the node's own
redacted output inside the envelope. The plane refuses log words while the
node's `agent_log_access` is off, and each thread may read the site
`support_desk_node_reads_per_thread` times a UTC day.

## Who may ask, and how they prove it

The actions are under `/api/v1/action/support_desk/`, sessionless:

| Action | Does |
|---|---|
| `desk_ask` | A question. A new thread needs `origin_nonce`; a later one `thread_id` + `token`. |
| `desk_poll`, `desk_cancel` | One answer's state; stop it being written. |
| `desk_feedback` | Helpful / not helpful on an answer, with a line. |
| `desk_escalate` | Hand the thread to a person. |
| `desk_threads` | The site's threads' states and unread replies; with `thread_id`, its transcript (clears the unread mark). |
| `widget_ask`, `widget_poll` | The public widget (guest browser session). |

A **new thread proves its origin**: this node POSTs the nonce to
`https://{host}/api/v1/action/support_origin_confirm`, and the site answers
yes only for a nonce its own Help panel issued in the last five minutes and
has not used, naming the desk it issued the nonce for. A host that does not
confirm is refused, and so is a nonce issued for another desk: this node
accepts only its own host (`ServicesClient::host()`). **Every later call
proves continuity** with the thread's token, given once when the thread was
made and kept here only as a hash. A site connected to an account at this node
also signs with its services key. A thread an account opened takes calls
signed with that account's key only, and with that key needs no token:
`desk_threads` lists the account's other threads from the host, and a
reinstalled site, connected again, keeps a row for each and opens them by the
key. The joined node is found from the proven host on every question, never
from the request.

Order of checks on `desk_ask`: the address's refused calls this hour, and for
a new thread the new threads this address opened this hour
(`support_desk_new_threads_per_hour`, counted when tried); the site's shape,
the key if one is presented; for a new thread, the budgets and then the origin;
for a later one, the token; then the budgets again before a turn starts
(`SupportBudget`: Joinery AI's monthly cap, `support_desk_daily_tokens_total`,
`support_desk_daily_tokens_per_host`, and for the widget
`support_desk_widget_daily_tokens_per_address`). A call that holds a thread
is not otherwise counted: polls, which a panel sends about once a second while
an answer is written, are not even logged.

A turn runs in a background worker (`cli/run_support_turn.php`). A node that
cannot start one refuses the question before making anything: run inline, the
turn would outlast the asking site's wait.

A thread waiting on a person, or taken by an operator, keeps new messages for
the person and starts no model turn.

## The operator's pages

`/plugins/support_desk/admin/admin_support_threads` (permission 9):

- **Threads**, waiting on a person first. A thread's page shows the site's
  facts, every turn with its tool trace and tokens, and the reply form. A
  reply is an assistant-role message with `aim_author` set; the site's panel
  marks it "From a person", a connected site's account gets one content-free
  email (`SupportNotify`), and the next question goes back to the model unless
  the operator took the thread.
- **Questions the docs could not answer**: turns that handed off or were
  thumbed down, with the searches the model ran. **Answer once** opens an
  empty FAQ entry beside the thread; saving indexes the entry and sends the
  answer to a site's thread as a reply.
- **Words the site could not read**: node words the model reached for that a
  site's agent does not offer, by word and agent version (`SupportWordGap`).
- **FAQ entries**, oldest written first.
- **Index**: the indexed releases and what each fixed prefix weighs.

`support_desk_notify_email` gets one email when a thread starts waiting on a
person, at most one an hour for a thread.

## The public widget

`/support_desk/ask`, when `support_desk_widget_enabled` is on. A visitor's
conversation lives in their browser session; the first question passes the
platform captcha when one is configured. It reads the newest indexed release
and the public pages, has no handoff and no reads, and cannot be replied to.

## Keeping threads

Threads, their thumbs, the answers people wrote and the FAQ entries they
became are kept: `support_desk_thread_retention_days` (0, keep) is the window
the retention sweep applies to closed threads, with their transcripts.

## Tests

- `tests/support_desk_engine_test.php` (db): index, search, prompt layers,
  intake, a scripted turn, escalation, budgets, local FAQ.
- `tests/support_desk_node_reads_test.php` (live): reads of this node's own
  joined site through its agent.
- `tests/support_desk_cache_test.php` (live): two turns on the configured
  model; the second reads from cache.
- `tests/integration/support_panel_test.php` (db): the site side.
