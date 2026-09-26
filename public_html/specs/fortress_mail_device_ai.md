# Fortress mail AI on the person's own model

**Status: BUILT 2026-09-26 (WP0–WP6), uncommitted; the live acceptance walks
of WP1 (an Ollama on the person's computer), WP3 and WP4 need a model the
owner owns and are pending the owner. Owner-directed; revised after review by
public-html-bc (B1–B13, Q1–Q5 folded in), then after WP0 (closed 2026-09-25):
the owner's direction is one generic endpoint the person enters, with a local
model left to the person to configure and documented, so R2 is one kind.**
Follows `specs/client_custody_mail.md` (Fortress: the server never holds the
mail key; its R7 keeps every server-side AI reader off Fortress rows, and this
spec does not loosen that) and `specs/implemented/one_vault_experience.md`
(one unlock opens the mail vault in the browser). Owner decisions are marked
**Decided**; two calls the reviewer's findings forced are marked **Chosen**
for the owner to confirm or reverse.

## The problem, in plain terms

A Fortress mailbox is one the server cannot read. That is the feature, and
it means the platform's AI recipes (triage summaries, the security scan)
cannot run on it: the recipes run on the server, and the server has
ciphertext. Today a recipe bound to a Fortress mailbox finds nothing to do,
and the reader shows no summaries and no danger banner for that mail.

People who chose Fortress still want triage and a scan. The way to give it
to them without handing the server the mail is the way search already works
on Fortress mail: the server keeps the sealed rows and pages them out, and
the browser does the reading after the one unlock. For AI the browser then
sends the text to a model **the person owns**: Ollama on the computer they
are using, or Fireworks under their own key. The Joinery server sees
ciphertext go out and ciphertext come back.

**Decided (owner, 2026-09-25):** the model is the person's, never the
platform's. A judge action that proxies plaintext through the server to the
platform's local model or the platform's Fireworks credits was considered
and rejected: it hands every new message to the server as it arrives, which
is Private with a different badge. Running a model inside the browser tab
(WebGPU) is the fallback if calling the person's endpoint from a page proves
impossible, not the first build.

## Intent

- A Fortress mailbox gets the same two things Private mail gets from AI: a
  one-line summary per new message in the list, and the security scan's
  score, verdict and red flags in the thread, with the judging model named.
- Nothing about it runs on the server. The server pages the work, stores the
  sealed results, and records that an item was done. It never sees a
  message, a prompt, a verdict or the person's model key.
- The person enters one endpoint (address, key, model) in the mailbox's AI
  panel. Fireworks, OpenAI-compatible hosts and their own Ollama are all that
  one kind; the docs carry the notes for pointing it at a machine of their
  own.
- When their model is not reachable, the mailbox says so in one line and
  keeps working without AI. Nothing queues on the server, nothing errors in
  the reader.

## Facts, verified 2026-09-25 against the tree

- Server-side AI never selects a Fortress row: `EmailJobCandidates` (SQL at
  `plugins/joinery_ai/includes/EmailJobCandidates.php:229`) excludes
  `iem_sealed_key LIKE 'v1.edgeseal.%'`; `SystemBase::get()` throws
  `VaultSealedForBrowserException` on a browser-sealed field
  (`includes/SystemBase.php:883`); `ModelQueryExecutor` drops such rows;
  `CreateCalendarEntryTool` refuses them. Pinned by
  `plugins/mailbox/tests/fortress_server_readers_test.php`.
- The two email recipes: `EmailTriageJob` (verdict `{summary}` ≤ 280 chars,
  written to `iem_ai_summary`) and `EmailSecurityScanJob` (verdict `{score
  0–10, verdict safe|caution|dangerous, red_flags[]}`; `recordVerdict()`
  stores `iem_ai_scan` as JSON `{verdict, red_flags, summary, model,
  recipe_id}` with the score kept out of it, and `iem_ai_danger_score` and
  `iem_ai_scan_time` in the clear; it requires `TIER_CAPABLE` via
  `minTier()`). Both feed the model `EmailSecurityDigest::build()`: headers
  from the raw message (Reply-To and Return-Path drive check A of the
  corpus-validated scan prompt), `AUTHENTICATION: spf= dkim= dmarc=`, `URLS
  FOUND` with link text, `BODY` capped at 4096 chars, `ATTACHMENTS` from
  `EmailAttachmentDigest`.
- `PipelineRunner` (`plugins/joinery_ai/includes/PipelineRunner.php`):
  `buildSystem()` renders the judge framing, today's date in the owner's
  timezone, the recipe's prompt (edited or `defaultPrompt()`), and
  `DescriptorValidator::renderOutputInstruction()`; the digest is wrapped by
  `UntrustedEnvelope::wrapBlock()` under a per-run nonce and `neutralize()`
  rewrites marker strings found inside the content; `judgeItem()` sets
  `max_tokens` and retries once with the validator's error; `parseVerdict()`
  strips `<think>` blocks, extracts the first balanced JSON object, runs
  `DescriptorValidator::coerce()` and the job's `validateVerdict()`.
- `iem_ai_summary` and `iem_ai_scan` are `$sealed_fields` members
  (`inbound_email_messages_class.php:230`), optional. On a Fortress row they
  are null today. `iem_ai_danger_score` and `iem_ai_scan_time` are clear
  metadata the list sorts on.
- A Fortress thread message reaches the browser with its sealed columns under
  `sealed` (`MailboxService::fortressThreadMessage()`, :1743; the set at :1747
  does **not** include `iem_raw_headers`) and its authentication results in
  the clear. It hard-codes `ai_danger_score`, `ai_scan` and `ai_scan_time` to
  null (:1786). The list carries `iem_sender`, `iem_subject`, `iem_snippet`
  sealed (`fetchAndDecryptContent()`, :1470). `MailboxFortress`
  (`plugins/mailbox/assets/mailbox_fortress.js`) opens both: `importRowKey`,
  `keyFor`, `openEdgeBytes`, `openList`, `openThread`; `wipe()` clears the row
  keys on lock. The danger banner (`mailbox_reader.js:2163`) returns null
  without `ai_danger_score`.
- The browser write door, `SystemBase::acceptBrowserSealed()` (:1312), takes a
  whole row: a new `sealed_dek` and **every** active sealed field, and refuses
  a post that leaves one out (:1362). It cannot add one field to a row. The
  relay parse of a pending row (`fortress_parse_store`) goes through it. The
  rotation reseal (`acceptBrowserReseal`) rewrites only the key and generation
  columns, never a field.
- `client_custody_mail.md` R8 (lowering, WP5) leaves `v1.edgeseal.user.` on
  rows that are Private again: a `v1.edgeseal.` prefix alone does not mean
  "the browser holds this key".
- `aip_recipe_item_log` stores per item: recipe id, item key, run id,
  status `done|error`, time; `unique_with (recipe, item_key)`;
  `notExistsClause()` (:89) ignores status, so any row hides the item from
  every later candidate query.
- `MailboxAliasConfig::aiProcessingAllowed()` (:203) and
  `processingConsent()` carry the domain's AI posture, including "keep AI
  local".
- The page CSP (`PublicPageBase::csp_policy()`, `connect-src` at :1112) names
  `'self'` and a fixed list; `csp_header_test` pins its shape. A fetch from
  the mailbox page to `localhost` or `api.fireworks.ai` is blocked today.
- Providers: `FireworksProvider` and `OpenAiCompatibleProvider` both speak
  `/v1/chat/completions` with a Bearer key. Ollama's `/v1` compatibility
  layer takes no context-length option and truncates an overlong prompt from
  the front; its native `/api/chat` takes `options.num_ctx`.
- The mailbox AI panel (`JoineryAiPanel.mount({area: 'mailbox'})` in
  `plugins/mailbox/views/profile/mailbox.php:99`; `AiPanelService`) lists
  and toggles the recipes bound to the current mailbox.
- `specs/client_custody_mail.md` WP3 (search entries paged by cursor) is not
  built yet. This spec's queue action has the same shape and does not depend
  on it.

## Design

### R1. Custody: what each party sees

| Party | Sees |
|---|---|
| Joinery server | Ciphertext at rest; the queue of ids; a done/error mark per item; the sealed verdict; the clear score and time. Never a message, a prompt, an answer, or the person's key. |
| The person's model (Ollama on their machine, or Fireworks under their key) | The digest of each message it judges, and the prompt. |
| The browser | Everything, for the duration of the unlock, as it already does to display the mail. |

Fortress's promise, "the operator cannot read this mail", holds
unchanged. A third party the person chose (Fireworks) sees what Private mail
already shows it. The AI panel says both in one sentence before the person
turns it on.

### R2. The person's endpoint: one generic kind

**Decided (owner, 2026-09-25, after WP0):** the built path is one kind. The
person enters an endpoint — base URL, key, model — and the page calls it.
Fireworks is one such endpoint (WP0: it answers any origin). A local or
tailnet Ollama is the same kind pointed at their own machine; the platform
builds no detection, no forwarder and no special setup for it, because
"users running commands somewhere is probably not going to work" as a
product path. The setup notes for a machine of their own live in the docs
(WP5); the no-setup local path is recorded in
`specs/DEFERRED_device_ai_local_model.md` with the WP0 facts.

**The contract.** OpenAI-compatible `POST {base_url}/chat/completions`
with a Bearer key, JSON body, `response_format: {type: 'json_object'}`
where honoured, `max_tokens` from the recipe. That is what Fireworks,
OpenAI, vLLM, LM Studio and Ollama's `/v1` all speak. Ollama's `/v1` ignores
a context option (WP0), so the docs note says how to set the model's
context on the person's side; Ollama 0.34+ refuses an overflow with an HTTP
400 the page shows verbatim rather than truncating silently.

**Where the three parts live.**

- **Base URL: registered with the account, confirmed by a step-up, pinned
  in the page CSP.** One row per user (`MailboxDeviceAiHost`: `mdh_host`,
  the origin of the base URL, in the clear; set or changed only under a
  step-up, the gate `vault_client_add_wrapping` uses; removal and re-saving
  the same origin ask nothing, since neither widens where mail goes). The
  field lives on the mailbox plugin's Email settings page
  (`/profile/mailbox/settings#your-model`), not the core Security page,
  which has no plugin hook (WP1, D1). The server emits exactly that origin
  into the mailbox page's `connect-src`, and only while a Fortress mailbox
  is visible on the page (R7; WP1, D3). Plain http is accepted only for
  `localhost` and private or tailnet IP **literals** (10/8, 172.16/12,
  192.168/16, 100.64/10, 127/8, ::1, fc00::/7); a hostname over plain http
  is refused because the server cannot know where a name points, so a
  `.ts.net` name needs https (WP1, D4). The rest of the address (the path
  such as `/inference/v1`) stays in the browser beside the key. The address is not a secret; holding it gives
  the operator nothing. This is what keeps B7 closed without a special
  local kind: a free-text destination in browser storage would let one XSS
  redirect every later drain to an attacker, and a CSP that names one
  confirmed origin makes that impossible even if a check is bypassed.
  **Decided (owner, 2026-09-25): pin it.** The alternative, a wider
  `connect-src` with the URL kept in the browser, was rejected because it
  reopens B7.
- **Key and model: per browser**, in `localStorage` (`jy_device_ai`). Chosen
  after review (B11): a server row sealed to the mail vault needs the
  client-reseal plumbing, couples mail-vault rotation to the joinery_ai
  plugin, and cannot seal on a site with the mailbox plugin off. The cost
  is entering the key once per computer.

The platform's own Fireworks key never reaches a browser: it is readable by
whoever runs the page and unmeterable. **Decided:** the person's own key
only.

**A machine of their own (documented, not built).** Ollama refuses any site
not in `OLLAMA_ORIGINS` (403 on the preflight), so the person sets that on
their machine and restarts Ollama; Chrome asks once per site before a
public https page may reach their computer or their network (Local Network
Access, measured in 148: loopback and tailnet addresses both prompt; after
it, plain http to a tailnet address runs with a mixed-content warning);
Safari and Firefox were not measured and may block plain http, for which
`tailscale serve` gives an https address. Ollama's `/v1` takes no context
option, so they set the model's context on their side. The Test button
(R7) reports which gate stopped a call.

### R3. The queue: the server pages the work

New action `mailbox/device_ai_entries` (browser session): for `alias_id`
(or all the caller's Fortress mailboxes) and `recipe_id`, page the caller's
Fortress rows the recipe has not judged, by id cursor, 100 per page. Each
entry carries what one judgement needs, so the drain never fetches a whole
thread (Q5): `{id, sealed_dek, sealed_ad_prefix, dkim_result, spf_result,
dmarc_result, auth_source, received_time, sealed: {iem_sender, iem_subject,
iem_body_plain, iem_body_html, iem_raw_headers, iem_attachment_manifest}}`.
Same filters as `EmailJobCandidates` (not deleted, not spam, not a draft,
not pending parse, unread only, lookback days; Q2: unread only is
deliberate, the same window the server gives Private mail) but selecting
**only** rows whose key is `v1.edgeseal.mail.` (B4: a lowered row's
`v1.edgeseal.user.` key is the server's to open, not the browser's),
excluding items with an `aip_recipe_item_log` row for that recipe. The
recipe must be owned by the caller, bound to the mailbox
(`MailboxAliasConfig::resolveBoundAliases`), and be one of the two
device-capable jobs (R5).

**Recording (B1, B2).** `done` is never posted on its own: the verdict write
(R4) inserts the `done` log row in the same transaction, `ON CONFLICT
(recipe, item_key) DO NOTHING`, so a second tab's late post is a no-op and
a `done` without a stored verdict cannot exist. `ai_device_record`
`{recipe_id, item_key}` records `error` only, and only for a reply the
model did give that failed validation after the retry; a transport failure
(endpoint asleep, 429, timeout) records nothing, so the item is offered
again next session. Both actions require all of: the recipe's
`rcp_owner_user_id` is the caller; the row is the caller's; the row's key is
`v1.edgeseal.mail.`; the recipe covers the row's mailbox. Without the owner
check, one user could post `error` for another user's message and hide it
from that user's server scan for good.

### R4. The verdict is sealed under the row's own key

The browser holds the row's DEK (it opened the message with it). It
encrypts the verdict fields with that DEK under the row's AD
(`mail:{id}:iem_ai_summary`, `mail:{id}:iem_ai_scan`) and posts them through
a new, narrower write door:

`SystemBase::acceptBrowserSealedFields(int $row_id, array $fields)`: the row
must already be sealed to a client-custody scope (`isClientCustody` on the
stored key's scope, not the prefix alone), must not be pending parse (B3:
the relay's whole-row parse would otherwise hit the leaves-a-field-out
refusal), each field must be in the model's **`$browser_appendable_fields`**
allow-list (for mail: `iem_ai_summary`, `iem_ai_scan` only; B3: the door
must not be a way to rewrite a received body or sender under the same DEK),
active on the row, and `v1.edge.` ciphertext; the row's `sealed_dek` and
key generation are untouched (a rotation of the mail scope rewrites only
the key columns, so a verdict posted mid-rotation stays valid; restamping
the generation here would be wrong while one is pending).

The mailbox exposes it as `mailbox/device_ai_verdict` `{id, recipe_id,
fields, danger_score?, scan_time?}`: writes the fields, the clear
`iem_ai_danger_score` and `iem_ai_scan_time` for the scan (the same metadata
Private keeps clear, so the list can sort), and the `done` log row, in one
transaction (R3). The sealed `iem_ai_scan` JSON is exactly
`recordVerdict()`'s shape, `{verdict, red_flags, summary, model,
recipe_id}`, with the score kept out of it, so a Private row, a Fortress row
and a lowered row decode the same way (B5).

The reader then shows them: `fetchAndDecryptContent()` adds `iem_ai_summary`
to the list's `sealed` set; `fortressThreadMessage()` adds `iem_ai_summary`,
`iem_ai_scan` and `iem_raw_headers` to the sealed set and carries
`ai_danger_score` and `ai_scan_time` in the clear instead of null (B5);
`MailboxFortress.openList/openThread` open the sealed ones into
`ai_summary`, `ai_scan` and `raw_headers`. The list preview
(`mailbox_reader.js:1601`) and the danger banner (:2163) then render
unchanged, and the banner names the model from the scan JSON (R8).

### R5. Prompts and the digest, built in the browser

New action `ai_device_recipes` `{mailbox}` (browser session): the recipes
bound to that mailbox that a device may run, each as `{recipe_id, job_id,
label, system_blocks, nonce, verdict_descriptor, max_tokens, min_tier}`.
`system_blocks` is the **full** output of `PipelineRunner::buildSystem()`
(judge framing, today's date in the owner's timezone, the recipe's prompt,
the output-format instruction, the untrusted-input block under a fresh
nonce), so a device run judges with exactly the words a server run would
(B8). Only `EmailTriageJob` and `EmailSecurityScanJob` declare themselves
device-capable (`PipelineJobInterface::deviceCapable(): bool`, default
false).

The browser ports, into `assets/js/email-digest.js` and
`assets/js/verdict-check.js`:

- `EmailSecurityDigest` and `EmailAttachmentDigest`: headers from the opened
  `iem_raw_headers`, `AUTHENTICATION` from the clear results, `URLS FOUND`
  from the opened body, `BODY` collapsed and capped at 4096, `ATTACHMENTS`
  from the opened manifest;
- `UntrustedEnvelope::wrapBlock()` and `neutralize()`, applied to the digest
  under the nonce the server issued, so the marker rewrite (S20) holds;
- the one retry with the validator's error, `max_tokens`, the `<think>`
  strip, the first-balanced-JSON extract, `DescriptorValidator::coerce()`
  and each job's `validateVerdict()` (the scan's score/verdict band check).

A reply that fails validation after the retry is recorded `error` (R3) and
not retried in this session.

**Parity (Q3).** `EmailSecurityDigest` gains `buildFromColumns(array
$opened)` taking the opened columns and clear results, which `build()`
calls after reading the raw; the fixture test feeds one message's columns to
both the PHP entry point and the JS port and requires byte-equal digests.

**Model floor (B9).** The server scan refuses models below `TIER_CAPABLE`;
the browser cannot grade a model. So: the panel warns when the model name is
below the recipe's `min_tier` by the reference list the platform already
ships (`ai_model_reference.json`), the Test button sends the recipe's
system blocks plus a 4096-char sample digest and shows an overflow error
verbatim (B10: the scan's prompt with a full digest is ~3,630 tokens, WP0),
and every banner and summary says "judged by <model>". **Consent (B9):** a
domain whose posture keeps AI local (`processingConsent()`) refuses an
endpoint origin that is not a loopback or private address for its
mailboxes; the panel says why.

### R6. The drain runs while the mailbox is open

In `mailbox_fortress.js`, once the mail vault is open and the endpoint is
configured and reachable (one probe per session, R7):

- take the tab-wide lock `navigator.locks.request('jy-device-ai-drain',
  {ifAvailable: true})`; a tab that does not get it does not drain (B12: two
  tabs would otherwise judge every item twice, and pay Fireworks twice);
- for each device-capable recipe bound to the current mailbox, page the queue
  (R3), newest first, and judge one item at a time;
- an item is: open the entry's sealed columns, digest, wrap, call, validate
  (retry once), seal the verdict, post it (R4);
- a lock epoch: `MailboxFortress.onLock` bumps it and `wipe()` clears the row
  keys; the in-flight item re-checks the epoch before its post and drops the
  item on a mismatch, so nothing is sealed or posted after a lock (B12);
  pause on lock and on the page hiding, resume on the next unlock;
- update the visible list row as each summary lands;
- stop after 200 items per session and say so in the panel ("200 judged;
  more next time"), so a first run on a large mailbox does not pin the
  machine.

On-demand, in the thread view of a Fortress message: **Summarize** and
**Scan now** run the same one-item path for that message and show the
result at once. **Draft a reply** is deferred to `client_custody_mail.md`
WP4 (compose), which owns the composer.

### R7. Setup, CSP and failure, in the AI panel

The page CSP (B6): the mailbox page's `connect-src` gains the caller's
registered endpoint origin when one exists, nothing else; `csp_header_test`
pins the new shape (with and without a registered origin). Measured in WP0
with CSP enforced: a target outside `connect-src` is refused by the browser
before any request leaves.

The mailbox AI panel gains a section for a Fortress mailbox, "Your AI, your
model":

- **Your model**: base URL (registered on the Security page under a
  passkey confirmation; the panel links there to set or change it, and
  shows the origin as it will be called), key and model (kept in this
  browser); a **Test** button that calls the endpoint from the page with the
  recipe's system blocks and a sample digest and reports one of: reachable;
  your browser asks first (`navigator.permissions.query({name:
  'local-network-access'})` is `prompt`) or blocked it (`denied`); your model
  refused this site (granted, CORS failure: the origin line); wrong key
  (Fireworks answers a bad key on chat completions as "model not found";
  Test then asks `GET {base}/models` with the same key, and 401/403 there
  means wrong key, anything else a missing model — WP1, D7); context too
  small (the endpoint's overflow error, verbatim); each with one fix line,
  and a link to the docs page for a machine of their own. Test sends the
  security scan's real system text (the caller's own scan recipe for that
  mailbox, else the default) with a made-up 4096-char sample digest
  (`mailbox/device_ai_test_prompt`), never real mail (WP1, D5). The section
  is script-built like the rest of the panel; its inputs go to
  `localStorage` and to the person's model, never to this server (WP1, D9).
- One sentence of custody before the first save: "Your mail is sent from
  this browser to the model you name here. Joinery never sees it. Fireworks
  will."
- **The site's own model, offered (owner, 2026-09-26).** When the site's
  local provider (`joinery_ai_local_base_url`) sits on a private or tailnet
  IP host, with no key, the settings page and the panel offer it in one
  click: "Use this site's model", naming the model and host, filling the
  address and model. The click still registers the origin under the step-up
  (the CSP pin is the person's decision), and a member who is not the
  operator is told the operator runs that machine and could see mail sent
  to it. Loopback is never offered (it is the server's machine, not the
  person's); a hostname or public host is not offered (it is not the site's
  own hardware). Why: the operator, who is also a person with a mailbox,
  otherwise types the same address twice; the two settings stay separate
  because for everyone else "the site's model" is the operator's machine.
- The recipes list as today; a recipe bound to a Fortress mailbox shows
  "runs on your device while this mailbox is open" instead of a schedule.
- While the endpoint is unreachable the section shows one line and the drain
  does not run. The reader never shows an error for AI.

Setup check (`InboundEmailSetupCheck`, server side, B13): it cannot see a
browser's settings, so its row for a Fortress address with a bound recipe
is INFO and device-independent: "AI for this mailbox runs in the owner's
browser against a model they name; it is set up in the mailbox's AI panel."
No pass/fail.

### R8. What does not change

- Every server-side gate in `client_custody_mail.md` R7 stays. The candidate
  SQL keeps excluding Fortress rows for cron. No server job ever reads one.
  `fortress_server_readers_test.php` passes unchanged at every stop, the
  sealed summary included.
- Private mail's AI is untouched: same recipes, same server path.
- The platform's model settings and credits are not used by this path and
  are not metered against it.
- Attachments are not sent to the model in this build beyond the manifest's
  names and sizes (the same as the server digest's ATTACHMENTS section).

### R9. Wording

Person-facing text says "your model". It never says "local model"
(ambiguous with the platform's) or "endpoint" as a heading. The panel
heading is "Your AI, your model". A scan banner produced this way carries
"judged by <model>"; a triage summary is stored bare, as the server stores
it, and carries no model until a column exists for one (WP3, D23).

## Work packages

### WP0. Prove the browser can reach the model (gate) — CLOSED 2026-09-25

Run by public-html-bc; the facts are in R2. In short: CSP enforced on the
site's origin refuses any target it does not name; Chrome (148) asks once
per site before a page may reach loopback or a tailnet address, then both
work over plain http (tailnet with a mixed-content warning) once Ollama's
origin line is set; Ollama refuses any site not in `OLLAMA_ORIGINS`;
Fireworks answers any origin; the scan's prompt with a full digest is
~3,630 tokens and Ollama's `/v1` ignores a context option while 0.34+
refuses an overflow loudly. Real Chrome, Safari, Firefox and the tailnet
https case were not run: the owner's direction after the Chromium result
made a built local kind moot. The scratch page and forwarder are removed;
nothing was staged. Remaining local-model questions live in
`specs/DEFERRED_device_ai_local_model.md`.

### WP1. Endpoint settings, CSP and the Test button — BUILT 2026-09-25 (public-html-bc), reviewed by public-html-5d

- The `MailboxDeviceAiHost` row and its Security page field behind a
  step-up (R2); the per-browser key and model; the panel section (R7); the
  CSP emission of the registered origin and the `csp_header_test` update;
  the Test call and its outcomes.
- **Tests:** `csp_header_test` (new shape, with and without a registered
  origin); panel logic test for the section's states; a unit test that the
  host row refuses a change without a step-up and stores an origin, not a
  path.
- **Acceptance:** register a Fireworks base URL and enter a personal key,
  Test says reachable and names the model. Screenshot. Then point the same
  field at an Ollama on this computer following the docs note: Test reports
  the browser prompt, then reachable. Screenshot.

### WP2. The queue, the door and the sealed verdict — BUILT 2026-09-25 (public-html-bc), reviewed by public-html-5d

As built: an entry's `sealed_dek` and `sealed_ad_prefix` sit inside
`sealed` (the `sealedForBrowser` shape the reader already opens) with the
clear fields top-level (D10); the queue pages newest first on a `before_id`
cursor, 100 per page (D11); the error action is `mailbox/ai_device_record`
with the message id as `item_key` (D12); the scan time is the server's
clock at the verdict write, never the client's (D13); a verdict for a
message the recipe already logged, `done` or `error`, is a no-op that
writes nothing (`INSERT … ON CONFLICT DO NOTHING RETURNING` first in the
transaction; D14) — WP4's on-demand path may replace an `error` row with
`done` (Q3, decided: yes, an upsert only where the existing status is
`error`); device log rows carry no run id (D15); the caller's-row refusal
lives in `MailboxDeviceAi::rowFor`, as the door leaves authorization to its
caller (D16); both suites are `test-db`, dev-only (D17); the mid-rotation
property is pinned by moving the row's key directly (D18). The triage
verdict seals the bare summary string, as the server writes it; the JSON
shape is the scan's only (Q4).

- `mailbox/device_ai_entries` with the entry's own sealed columns,
  `ai_device_record` (error only), the ownership checks (R3);
  `SystemBase::acceptBrowserSealedFields()` with
  `$browser_appendable_fields`, and `mailbox/device_ai_verdict` writing
  fields, clear score and time, and the `done` row in one transaction (R4);
  `deviceCapable()` on the two jobs; list and thread carry the AI columns
  and `iem_raw_headers` sealed and the clear score and time for Fortress
  rows; `MailboxFortress` opens them.
- **Tests:** `tests/vault/accept_browser_sealed_fields_test.php` (`db`):
  adds an allow-listed field under the row's key; refuses a row not
  client-sealed, a `v1.edgeseal.user.` row, a pending-parse row, a field
  outside the allow-list (`iem_body_html` in particular), plaintext, another
  owner's row; leaves `sealed_dek` and generation untouched; a verdict
  posted during a pending mail rotation still opens after the rotation
  commits. `plugins/mailbox/tests/fortress_device_ai_test.php` (`test-db`):
  entries page by cursor, only `v1.edgeseal.mail.` rows, judged items drop
  out, another user's rows never returned; a verdict post writes the fields,
  the clear score and the `done` row atomically and a second post is a
  no-op; `error` from another user for this user's recipe or row is
  refused; a transport failure leaves no row; `fortress_server_readers`
  stays green.
- **Acceptance:** none yet (nothing calls it until WP3).

### WP3. Prompts, digest and the drain — BUILT 2026-09-25 (public-html-bc), reviewed by public-html-5d; live walk pending the owner

As built: the drain's orchestration lives in `mailbox_device_ai.js` and the
one-item judgement (`judgeEntry`, the lock epoch, the seal, the post) in
`mailbox_fortress.js`, which holds the keys (D19). Consent maps the model's
origin to a trust class — loopback or private IP `local`, the host of
`joinery_ai_fireworks_base_url` `trusted`, anything else `cloud` — and is
enforced server-side too: `entries()` pages nothing a domain's consent
forbids, and nothing when no origin is registered (D20). `max_tokens` is a
fixed 4096; `min_tier` is the recipe's or the job's (D21). The device
ATTACHMENTS section is the manifest's metadata only, parity-checked against
`EmailAttachmentDigest::buildFromManifest()` (D22, R8). Parity is proven
byte-equal on five hostile fixtures, three manifests, two envelopes, every
named HTML entity and 1,500 random inputs. The drain suite proves the
request shape, the wrap, the one retry, a sealed verdict opening to what the
model said, a lock mid-call dropping the post, `error` after two invalid
answers, and a 401 stopping with nothing recorded. The triage summary is
sealed bare, so only the scan banner says "judged by <model>" (D23, Q5:
decided, the summary carries no model until a column exists for it; R9
amended). A real verdict walk needs a model the person owns: the owner runs
it on their own machine (dev's consent is `local`, so an Ollama on their
computer qualifies with no change on dev).

- `ai_device_recipes` with the full system blocks and nonce (R5);
  `EmailSecurityDigest::buildFromColumns()`; `assets/js/email-digest.js` and
  `verdict-check.js` with the envelope wrap, retry, think strip and JSON
  extract; the drain in `mailbox_fortress.js` with the Web Lock and the lock
  epoch (R6); the panel's recipe wording, model-floor warning and consent
  refusal (R5, R7); the setup-check INFO row (R7).
- **Tests:** digest parity fixture test (R5); a drain unit test in the
  browser self-check (`MailboxFortress.selfCheck`) that judges one fixture
  message against a stub endpoint, posts a sealed verdict, and proves a lock
  during the call drops the post.
- **Acceptance:** with Ollama on this computer and triage bound to a
  Fortress mailbox, send three messages to it, open the mailbox, touch the
  passkey once: summaries appear in the list within a minute, each marked
  with the model; open one, the summary is there after a reload; check the
  row in the database holds `v1.edge.` in `iem_ai_summary`. Open the same
  mailbox in a second tab: nothing is judged twice (the item log has one row
  per item). Repeat with the security scan on a message carrying a
  suspicious link: the danger banner shows with the score and the model.
  Screenshot each.

### WP4. On demand — BUILT 2026-09-25 (public-html-bc); live walk pending the owner

As built: `MailboxDeviceAi.messageActions(m)` (`mailbox_device_ai.js` 1.3)
is the bar `mailbox_reader.js` (2.80) appends under an opened Fortress
message: one button per device recipe on the mailbox (Summarize, Scan now),
each running `MailboxFortress.judgeEntry()` on the opened message turned
into a queue entry (`entryFromMessage()`), with a fresh nonce fetched per
run, and showing what the model said at once. A verdict for a message the
recipe already logged `done` is not stored again ("not stored: this message
already has one"); a `done` replaces an `error` row (Q3, `MailboxDeviceAi`
1.2, `ON CONFLICT … DO UPDATE` only where the existing status is `error`),
and the write re-checks the domain's consent. The bar is absent when this
browser has no model set up. Pinned by `device_ai_drain_test.php` ("On
demand: an opened message becomes a queue entry") and
`fortress_device_ai_test.php` (the error-to-done replacement).

- **Summarize** and **Scan now** in the Fortress thread view (R6); results
  shown at once and stored the same way.
- **Acceptance:** open a Fortress message with no summary, click Summarize,
  the summary appears and survives a reload. Screenshot.

**B14 (found 2026-09-26 on the Mac Studio, fixed).** A model that reasons by
default (Ollama's qwen3 family) spent the whole output budget thinking and
answered with empty content: Test said "answered, but with nothing in it",
and the drain would have recorded `error` for every message. A server run
never sees this because `OpenAiCompatibleProvider::applyReasoning()` sends
`reasoning_effort` from the recipe's thinking level (`none` for `off`) with
a 16,000-token budget; the device budget is 4,096 (D21) and Test's 1,024.
Now `ai_device_recipes` carries `reasoning_effort`
(`MailboxDeviceAi::reasoningEffort()`, the recipe's level as the server
resolves it) and the drain sends it, asking once more with `none` when the
answer came back empty at `length` (`mailbox_fortress.js` 1.7); Test always
sends `none` (`device_ai_test_prompt` 1.1, `mailbox_device_ai.js` 1.6) and
names an always-reasoning model that still answers nothing. Measured on the
Studio (Ollama 0.34): `reasoning_effort: none` turns the reasoning channel
off (227 completion tokens against 1,937 at `medium` for the 35B and 3,638
for the 9B on a 3,600-token prompt); `think: false` and `reasoning.effort`
do nothing there.

### WP6. The site's own model, offered — BUILT 2026-09-26 (public-html-91)

As built: `MailboxDeviceAi::siteModel()` / `siteModelFrom()` (1.3), handed
to both pages as `device_ai_site_model` (`profile_mailbox_logic` 1.2.0,
`mailbox_settings_page_logic` 1.3.0) and to the scripts as
`MAILBOX_DEVICE_AI.site_model`; the settings page's *Use this site's model*
as the primary button, with *Enter a different model* opening a form that
takes the address, key and model together (owner, 2026-09-26; `settings.php`
2.4, `mailbox_device_ai_settings.js` 1.3: the key and model go to the same
browser entry the panel reads); the
panel's offer while no origin is registered and the prefilled fields once
the site's origin is (`mailbox_device_ai.js` 1.5, `siteOffer()`);
`MailboxDeviceAi.registerOrigin()` is the one registration path, loaded on
both pages. Tests: `device_ai_host_test` (what is offered: tailnet yes,
loopback / name / public / keyed / empty no) and `device_ai_panel_test`
(register, prefill, left alone).

- **Acceptance:** on dev, whose local provider is the Mac Studio, open
  Email settings as the owner: the offer names `qwen3.6:35b-a3b-nvfp4` at
  `100.69.133.69:11434`; one click and a passkey later the address is
  registered and the panel's Test says reachable. Screenshot.

### WP5. Docs — BUILT 2026-09-26 (public-html-91)

As built: `plugins/mailbox/docs/overview.md` § "AI on Fortress mail: the
person's browser and their own model"; `docs/sealed_vault.md` gains the
field-level door beside `acceptBrowserSealed`; `plugins/joinery_ai/docs/overview.md`
lists `deviceCapable()`, the Fortress exclusion under the unlock-window
section, and the panel's `hostSection`; the person-facing page is
`plugins/mailbox/docs/using_your_own_model.md`, served by the public docs
viewer at `/documentation?doc=plugin/mailbox/using_your_own_model` and linked
from the panel section (`mailbox_device_ai.js` 1.4) and the Email settings
field (`settings.php` 2.2), which closes R7's docs link.

- `plugins/mailbox/docs/overview.md` (Fortress: AI in the person's browser
  against a model they name), `docs/sealed_vault.md` (the field-level write
  door and its allow-list beside `acceptBrowserSealed`), the joinery_ai
  plugin docs (device-capable jobs, the panel section, the per-browser
  settings), and one person-facing page, "Using your own model", with the
  notes from R2: the Ollama origin line, the browser's one-time prompt, the
  context setting, and `tailscale serve` for a machine elsewhere. Current
  state only.

## Tests

Listed per work package. The db gate before hand-back is
`php tests/run.php db --changed`. `fortress_server_readers_test.php` is the
tripwire that this spec never gave the server a way in: it must pass
unchanged at every stop.

## Open questions

- **Q1.** Decided 2026-09-25: the endpoint's origin is registered under a
  step-up and pinned in CSP (R2). A wider `connect-src` was rejected.
- **Q2.** Confirm the per-browser key (R2). The alternative on record: a row
  `aik_ai_device_keys` sealed to `mail` with the four sealing columns,
  `client_reseals: ["mail"]` in joinery_ai's `vaultConsumer` and a
  `clientReseal('mail', ['AiDeviceKey'])` in its bootstrap; it makes
  deactivating joinery_ai block mail-vault rotation once the plugin has ever
  been active, and cannot seal on a site where the mailbox plugin is off.
- **Q3.** Attachments beyond the manifest (sending a PDF's extracted text to
  the person's model) are out of scope here; the server's digest does not
  send them either. Revisit with `client_custody_mail.md` WP3's per-file
  search text.

## Review record (public-html-bc, 2026-09-25)

Where each finding landed: B1 → R3 recording; B2 → R3 ownership; B3 → R4
allow-list and pending-parse refusal; B4 → R3/R4 `v1.edgeseal.mail.`; B5 →
R4 clear score and time, scan JSON shape, `iem_raw_headers`; B6 → R7 CSP,
WP0/WP1; B7 → R2 no free-text destination (the endpoint origin is step-up-registered and CSP-pinned); B8 → R5 full system blocks, envelope, retry,
strip, extract, `validateVerdict`; B9 → R5 model floor and consent; B10 →
R2/R5 native `/api/chat` with `num_ctx`, Test checks context; B11 → resolved
by Q1 (per device); B12 → R6 Web Lock and lock epoch; B13 → R7 setup-check
row device-independent; Q1 → R2 Chosen; Q2 → R3 unread only, deliberate; Q3
→ R5 `buildFromColumns()`; Q4 → R2 and WP0 branches; Q5 → R3 entries carry
the item's own sealed columns. The reviewer also confirmed the R4 door
holds against the rotation reseal and a Fortress→Private lowering.
