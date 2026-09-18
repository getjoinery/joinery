# Agent log access: two observe words, one owner switch, and the node-side redactor

**Status: RELEASED 2026-09-18.** Platform 0.8.411 on every site node and agent 1.35.0 on all
eleven agented nodes, each reporting both words and its switch (`on` everywhere a site runs;
`off` on docker-prod, a host with no site and so no setting to read, which is the fail-closed
reading the design asks for). Live proof (§5) run on dev 2026-09-18: `site_log {error, 50}`
(job 22104) and `log_table_tail {logins, 20}` (job 22105) completed with IPs masked on the node;
with dev's switch off, the same two words (jobs 22160, 22161) were refused with the owner's
reason; switch restored. The proof found one defect, fixed the same day and uncommitted:
`JobResultProcessor` had no handler for either word, so a completed log job never recorded a
result and the job page showed only the raw transcript. `process_site_log` and
`process_log_table_tail` record the envelope as the job's result, bounded on intake; the job
page's log box and table render from it. The credential-line half of the proof (job 22204, a
planted line carrying `dbpassword=...`, `api_key=...`, an address, an IP and a token) found the
second defect: the address, IP and token were masked on the node, the two lowercase assignments
were not, because both redactors matched the assignment shape only in uppercase. Fixed the same
day on both sides, uncommitted: `SmSecretRedactor` 1.2 and the agent's `redact` package (agent
1.35.1) mask `name=value` in any case for a name carrying password/passwd/token/secret or naming
a secret key (`api_key` joins both lists); a lowercase name ending in `_key` stays readable. Still
open before the spec moves: commit both repos, a platform release (which carries agent 1.35.1 to
the fleet), then re-read the planted line on dev and see both values masked. Original design note follows. Closes two rows of the running list in
`agent_recipes_and_vocabulary.md` (`site_log`, `log_table_tail`) and builds
the node-side redaction pass that `sentinel_managed_recovery.md` §11 names
as mandatory v1 work. Obeys every rule of `agent_recipes_and_vocabulary.md`
and adds none. Owner decisions taken 2026-09-17: **one switch** for every
log-and-diagnostic read; **on by default**, because the redactor runs
before anything leaves the node; excerpts on the plane are **pruned by the
standard retention sweep**; email masks **keep the domain**; the file word
can read the **previous rotation**; existing paired nodes get **one admin
notice** at upgrade. A node-side record of what the plane read is
**deferred** (see Out).

## What this builds, in one sentence

A paired management node can ask a node for the last lines of one of the
site's own log files, or the newest rows of one of the site's own log
tables, and gets them back with credentials and personal data masked on the
node, unless the site's owner has turned that off.

## Why

The 0.8.408 apply on joinerydemo went through `apply_update` with no shell
open, and the operator could not see the site's error log for the minutes
after the swap, nor the login and request logs that would have said whether
members were affected. Today the agent has no word that returns a log line:
`host_report` reduces the journal to a count, `install_report` tails only
the first-boot log, and `unit_journal` / `file_head` are named in
`agent_tier1_recipes.md` but not built. Nothing reads a log table.

The plane is already granted "redacted log excerpts" in the accepted-limits
table (`implemented/agent_on_node_architecture.md` §3.7). This spec is what
makes that cell true in both halves: the excerpts exist, and they are
redacted.

## Scope

In: the switch and its page; two observe words; the `redact` package in the
agent and its parity test against `SmSecretRedactor`; the plane's builders,
the Logs action on the node overview, and the job-detail rendering; the
spec's own tests and gates.

Out: the access log (visitor addresses and URLs, nine megabytes on dev, not
wanted by any diagnosis on the list); plugin log tables (`iel_inbound_email_logs`
exists only where the mailbox plugin is active — a later row adds it behind a
`to_regclass` check); compressed rotations (only the current file and the
`.1` rotation are readable); any word that takes a path, a table
name outside the compiled list, or SQL; the model-facing structuring of the
Sentinel spec's component F; local use of these words by an unpaired node's
own AI (a later spec, once the words exist); a **node-side record of what
the plane read**, shown to the node's owner. Today every paired node's owner
is the plane's operator (the two beta testers are outside the fleet and not
paired), so the plane's job row is the owner's record. The day a node owned
by someone else is paired, that record is owed first: `managed_hosting_and_services.md`
carries it as a precondition of its first customer node.

## 1. The switch

**Setting:** `agent_log_access`, declared `managed` in `settings.json`
beside `agent_enabled`, default `1`.

**Page:** the Management Node admin page (`/admin/admin_management_node`),
below the agent's own on/off switch and above the pairing section, so it is
read in the same breath as the act that gives a plane any sight of the
machine at all. Its wording says what the plane may then read, in the words
an owner would use:

> **Let the management node read this site's logs.** When on, a management
> node this machine is connected to can ask for the last lines of the site's
> error and task logs and the newest rows of the login, request, event,
> form-error and webhook logs. Passwords, keys, tokens, addresses and the
> personal half of email addresses are masked on this machine before
> anything is sent. When off, every such
> request is refused here, and the management node is told why. On by
> default. It has no effect until this machine is connected to a management
> node.

The setting is written the way `agent_enabled` is written from the same
page: a POST action handled in `admin_management_node_logic.php` through
`Setting::put`. The agent reads it through `readAgentSetting`, the one
settings read every agent word already uses.

**Projection.** The agent's on/off switch is projected one way into a
root-owned marker (`quiet.go`, `projectSwitch`) so a machine without a
database still knows its own state. The log switch is projected the same
way, to a second marker (`log_access`), by the same watcher on the same
five-second cadence, for the same reason turned the other way round: the
error log is wanted most when the database is down, and a word that has to
ask the database for permission to read the error log would be refused at
exactly that moment. The reading rule for the file word is: **setting when
the database answers, marker when it does not, refuse when neither exists.**
A missing marker on a node whose database is down reads as OFF, not on: the
`agent_enabled` marker reads missing-as-on for an upgrade-safety reason that
does not apply here, since a node upgraded to this agent projects the marker
within five seconds of the database being up, and a node whose database has
never been up since the upgrade has nothing the file word should be reading
on a plane's say-so.

The table word needs the database to answer at all, so it reads the setting
only.

**Reported at poll (rule 7).** The claim body already carries the node's
words, recipes and cases; it gains `log_access: "on"|"off"`, read from the
same setting-or-marker rule. The plane stores it on the node row and the
Logs action renders disabled, with the owner's reason, when it is off, so an
operator is told before asking rather than refused after.

**One switch, decided.** The two host diagnosis words still to be built
(`unit_journal`, `file_head`, `agent_tier1_recipes.md`) read the same
setting when they arrive. An owner who will not let the plane see the error
log will not want it in the journal either, and one sentence on one page is
the whole of what an owner has to understand.

## 2. The words

Both are `ClassObserve`, embedded (`Run`, not `Script`): neither starts a
process. Both refuse before reading anything when the switch is off, with
the reason `this site's owner has not allowed log access (agent_log_access
is off)`, which travels in the job result as every refusal does.

### 2.1 `site_log {file, lines}`

- `file`: `ParamEnum`, one of `error`, `cron_scheduled_tasks`,
  `joinery_ai_worker`, `install_executor`, `host_converger`. Each maps to
  `SiteRoot/logs/<name>.log`.
- `previous`: `ParamBool`, not required. True reads `<name>.log.1`, the
  most recent rotation, and nothing else: logs rotate at midnight, so a swap
  late in the evening has its lines there by morning. The compressed
  rotations stay unreadable; a diagnosis that needs last week is a restore
  question, not a log question. The list is a compiled
  Go slice; a name the site does not have on disk returns an empty tail and
  `present: false`, not an error, so a machine without the AI worker is
  still readable.
- `lines`: `ParamInt`, `Min` 1, `Max` 200, not required; when absent the
  word supplies 100 (`Params.Has`), since a parameter spec carries no default.
- Reads the tail with the byte-bounded `tailOf` that `observe_install_report`
  already uses (cut forward to a line boundary), then keeps the last N
  lines. Byte cap 32 KiB before redaction, so the whole result sits under the
  framework's 64 KiB backstop with room for the redactor to grow a line.
- Result: `{file, previous, present, size_bytes, modified_time,
  lines_returned, truncated, text}`. `text` has been through `redact.Text`.

### 2.2 `log_table_tail {table, rows}`

- `table`: `ParamEnum`, one of `logins`, `requests`, `events`,
  `form_errors`, `webhooks`. The word, not the plane, maps each to a table
  and a **compiled column list**; the plane never names a column and never
  sees one it was not given.

  | Enum | Table | Columns returned | Left out, and why |
  |---|---|---|---|
  | `logins` | `log_logins` | id, user id, login time, login type | `log_ip` — a member's address is not needed to see that logins stopped |
  | `requests` | `rql_request_logs` | id, feature, action, user id, was_success, status_code, error_type, note, api_key_type, response_ms, create_time | `rql_ip_address` |
  | `events` | `evl_event_logs` | id, event, user id, create_time, was_success, note | nothing; `note` goes through `redact.Text` |
  | `form_errors` | `lfe_log_form_errors` | id, error, user id, log_time, page, url, context | `lfe_user_agent`, `lfe_form` — the form is the member's submitted data |
  | `webhooks` | `wbh_webhook_logs` | id, provider, event_type, event_id, processed, error_message, create_time | `wbh_payload` — the provider's raw body, which carries card and customer detail |

- `rows`: `ParamInt`, `Min` 1, `Max` 200, not required; absent means 50,
  supplied by the word. Ordered by the table's time
  column descending; the query is a compiled string per enum with `$1` for
  the limit.
- Runs through `ExecEnv.DB`, the provider `check_status` uses. A node whose
  database is down reports that as the word's own legible failure.
- Result: `{table, rows_returned, columns, rows}`, every text column
  through `redact.Text`. Output cap: rows are added until 48 KiB, then
  `truncated: true`.

### 2.3 Hostile-caller review (rule 5)

What is the worst a compromised management node can do with these words?
Read, on a node whose owner has left the switch on, the last 200 lines of
one of five site logs and the newest 200 rows of five tables with their
addresses, payloads and submitted forms already left out and their text
masked. That is the "redacted log excerpts" cell of the accepted-limits
table, no wider. It cannot name a file, a table, a column, or a query; it
cannot read a rotated file, a config file, or anything outside
`SiteRoot/logs`; it cannot write; it cannot learn who a member is from an
address, because addresses never leave the node. The owner can close the
cell entirely from their own admin, and that refusal is node-enforced.

## 3. The redactor: a standard call, built once

Today the platform has exactly one redactor: `SmSecretRedactor`, PHP, in the
server-manager plugin, applied at display time on the plane to job commands
and output, masking credential values by key name (twenty call sites).
There is no redaction on any node, and no personal-data pass anywhere. The
Sentinel spec says the boundary is the node and the pass is v1 work; this is
where it gets built, and it is built as a call every later word uses rather
than as part of these two.

**Package:** `redact`, in the agent, beside `primitives` and `recipes`.

```go
// Text masks credential material and personal data in free text.
func Text(s string) string
// Fields masks every string value of a result map in place, recursing into
// nested maps and slices, so a word can redact its whole result in one line.
func Fields(m map[string]interface{})
```

**What it masks, and how each is decided:**

| Class | Rule | Mask |
|---|---|---|
| Credential values | The same key list as `SmSecretRedactor::$secret_keys`, in every shape that class handles (var_export, JSON, `KEY=value` env assignments), plus bearer-token and `PGPASSWORD=` shapes | `********`, key name kept |
| Email addresses | RFC-shaped local@domain | `<email>@example.com` — the local part is the personal half; the domain is what a mail diagnosis needs, and a masked domain would make every delivery failure read the same |
| IP addresses | v4 and v6 literal | `<ip>` |
| Long opaque tokens | 32+ hex or base64 characters standing alone | `<token>` |

**Parity is pinned.** A platform test in `tests/unit/`, beside
`installer_contract_test.php`, reads the Go package's key list from the agent
source checkout (the path `server_manager_agent_source_path` names, or its
default) and fails if it differs from `SmSecretRedactor::$secret_keys`; it
skips where the checkout is absent, as the installer contract test does. The
platform side is where the tests that read the agent's source already live.
One list read two ways is how a secret gets masked on the plane's screen and
not on the node's wire.

**Where it runs:** in the word, on the result, before `Run` returns. Not in
the dispatcher: the dispatcher does not know which fields are text a person
wrote and which are numbers a machine measured, and a redactor over a
version string would mask the release number. Every word that returns free
text calls it; the review question for a new word gains one line, "does it
redact?".

**Honest limit, stated on the page and here.** Free-text redaction masks
shapes. A member's name inside an exception message, or an order number in
a URL, is not a shape and passes. The words are still safe to leave on by
default because the excerpts are short, bounded, chosen from a compiled
list, and already granted to the plane in the accepted-limits table; the
redactor removes the classes of data that are dangerous on sight, and the
switch removes the rest for an owner who wants it removed.

**Plane side.** `SmSecretRedactor` stays exactly what it is, a second pass
at display time. Nothing on the plane relaxes because the node now redacts:
a plane must not rely on the node it is not trusting.

## 4. The plane

- `JobCommandBuilder`: `build_site_log_primitive($node, $file, $lines)` and
  `build_log_table_tail_primitive($node, $table, $rows)`. Both validate the
  enum against a mirrored PHP list and the integer against the same range,
  so a bad choice fails on the plane with a message rather than on the node
  with a refusal; the node's own validation stands regardless. Version bump.
- `has_primitive` needs nothing new: it keys on the builder existing and
  the node's reported vocabulary carrying the word.
- Node overview tab: a **Logs** action beside Host report, a select for the
  file or table and a number for the count, posting `action=site_log` or
  `action=log_table_tail` the way the host-report form posts. The result is
  not stored on the node row (unlike `host_report`); it lives in the job.
- Job detail: a primitive result whose word is one of these two renders
  `text` in a `<pre>` and `rows` as a table, both through
  `SmSecretRedactor`. A refusal renders its reason as every refusal does;
  the owner's-switch reason is the one line an operator needs.
- `JobResultProcessor`: no new fold. These words change no node state.
- **Retention, by the standard sweep.** A job row is never pruned today, so
  an excerpt would otherwise sit in the plane's database for good.
  `ManagementJob` gains a `$retention_policy` in the method form
  (`docs/scheduled_tasks.md` § Retention windows): `purge_method`
  `purgeLogExcerpts`, `window_setting` `server_manager_log_excerpt_retention_days`,
  declared in the plugin's `plugin.json` with default `30`, label "Log
  excerpts". The method blanks `mjb_result` and `mjb_output` on completed
  jobs of type `site_log` or `log_table_tail` older than the window and
  writes `{"pruned": true}` in their place, so the job row, its timing and
  its outcome stay on the record and only the excerpt goes. It returns the
  standard `['removed' => n, 'message' => ...]`. The window is a setting, so
  a plane that wants a week sets a week and `0` keeps everything. The three
  things a rule needs (declaration, setting, seeded row) all land in one
  release; the retention registry test picks the rule up on its own.

## 4.1 Existing paired nodes: one notice

A node already paired when this release lands has the setting seeded on
with no owner action. That is the accepted default, but it must not be
silent. A renderer registered with `AdminNotices` shows one notice to the
node's administrators while three things hold: the node is paired
(`agent_join_state` carries a joined state), `agent_log_access` is on, and
`agent_log_access_notice_seen` (a `managed` setting, default empty) is
empty. The notice says what the management node can now read, links to the
Management Node page, and carries a **Got it** button that POSTs the
acknowledgement to that page; using the switch itself also acknowledges it.
Never a write on a page view: the platform's rule that a server-initiated
write during a view must declare itself is not one this notice needs to
invoke. A node paired after this release never sees the notice: the switch
is on the page the owner used to pair.

## 5. Tests and gates

- `primitives/observe_site_log_test.go`: enum refusal, path containment
  (the enum resolves under `SiteRoot/logs` and nowhere else, `previous`
  resolves to `.1` and never to a `.gz`), line and byte
  caps, `present: false` for a missing file, refusal when the switch is off
  in each of the three states (setting off, database down and marker off,
  database down and marker missing).
- `primitives/observe_log_table_tail_test.go`: enum refusal, the compiled
  column list per table and that no excluded column can appear, the row
  cap, the byte cap, refusal when the switch is off.
- `redact/redact_test.go`: each class in the table above, the shapes
  `SmSecretRedactor` handles, and the parity test against the PHP key list.
- `vocabulary_test.go` gains the two names; `remote_test.go` pins
  `log_access` in the claim body.
- Platform: `tests/unit/` test that `agent_log_access` is declared managed
  in `settings.json` with default `1`, and that the Management Node logic
  writes it the way it writes `agent_enabled`; the redactor parity test
  above; a test that `purgeLogExcerpts` blanks only the two job types and
  only past the window, leaving the row; a test that the notice renders
  under the three conditions and not otherwise.
- Live proof, on dev, before the spec moves to implemented: one
  `site_log {error, 50}` and one `log_table_tail {logins, 20}` from the
  plane, the result read on the job page, a credential-shaped line in the
  dev error log confirmed masked; then the switch off on dev, the same two
  jobs refused with the owner's reason on the job page.

## 6. Work packages

| WP | Scope | Where |
|----|-------|-------|
| WP1 | `redact` package and parity test | agent |
| WP2 | `agent_log_access` setting, page section, logic, projection into the second marker | platform + agent (`quiet.go`) |
| WP3 | `site_log`, `log_table_tail`, their tests, vocabulary test | agent |
| WP4 | Builders, Logs action, job-detail rendering, the retention rule and its setting | server_manager plugin |
| WP4a | The one-time notice for already-paired nodes and its seen flag | platform |
| WP5 | Live proof on dev; running-list rows closed in `agent_recipes_and_vocabulary.md`; server-manager doc gains the two words and the switch | platform |

WP1 and WP2 have no dependency on each other. WP3 needs both. WP4 needs
WP3's names. One agent release and one platform release carry all of it.

## 7. What complies with what

- `agent_recipes_and_vocabulary.md`: closed parameters (two enums, two
  bounded integers), bounded output, fail closed on a missing switch,
  ledgered as every word is, reviewed hostile, named never composed,
  reported at poll. Its running list loses two rows to this spec.
- `sentinel_managed_recovery.md` §11: the personal-data pass runs on the
  node, in the result path, over known shapes; consent is a per-node switch
  on the owner's own admin. §14.E's redaction lands here; its
  collection-set and model-facing structuring do not.
- `implemented/agent_on_node_architecture.md` §3.7: the plane's cell is
  unchanged in width and becomes true in fact.
- `agent_tier1_recipes.md`: `unit_journal` and `file_head`, when built,
  read `agent_log_access` and call `redact.Text`; noted there when they are.

## Open questions

None. Settled by the owner 2026-09-17: one switch; on by default; retention
by the standard sweep; email masks keep the domain; the previous rotation
is readable; one notice for already-paired nodes; the node-side read record
deferred to the first customer-owned node.
