# Spam Learning in Core

## Status

Spec written 2026-10-02. **Built 2026-10-02/03, all three WPs; reviewed by public-html-91 (10-03).** Live fleet-wide in 0.8.455 (10-03): migration iem_019 applied on every node with mailbox active, and scanner 2.1 removed redis and left rspamd stateless on the first upgraded node. Owner decisions 10-02:
- Learning moves into core, and every box runs one stateless rspamd
  configuration.
- A clear sender fingerprint on sealed mailboxes: **yes**.
- AI moving mail to Spam: **no for now**. The AI still gets some messages badly
  wrong.

Reviewed 10-02 by public-html-91 (B1–B19). All findings are folded in below.
- Sealed mail keeps feeding the shared corpus: **yes** (owner 10-02). The
  disclosure is in the Disclosure table.

Build notes (10-02):
- `ibt_` has a serial id primary key with `ibt_token` unique (the platform's
  model contract requires a serial key). Teaching upserts on `ibt_token`.
- `reply` matches an outbound row's own Message-ID on the alias, or a composed
  send in `mst_mailbox_send_attempts` (which outlives a Sent copy that failed
  to store). Never the thread key.
- Token hashes are bound as individual integers: `SealedEgressGuard` refuses any
  long string written by a process that opened sealed content.
- `iem_spam_meta` is a short code string (`SpamMeta`, e.g. `f.dp.sp.kp.r6|u`), not
  JSON: ingest can run in a process that opened sealed content, and the guard
  refuses any string over 64 characters there (it caught this in
  fortress_level_change).
- The test harness turns learning off in memory for every suite (like
  `email_test_mode`): a fixture's teaching would otherwise outlive the fixture in
  the live corpus. `spam_learning_test` turns it on in the test database.
- Review by public-html-91 (10-03), B1–B14, all fixed. Decisions folded in:
  - A forward never counts as a send: its Sent copy is marked recorded at send,
    and the backfill skips older ones by the composer's `Fwd:` subject prefix.
  - IMAP-polled rows are outside the sender record too (inbound and remote Sent),
    matching the IMAP non-goal; a remote Sent folder holds forwards nobody can
    tell apart from composed mail.
  - A reply to a forward the user sent counts as `reply`: the forward's Sent copy
    carries the user's own Message-ID, and only its recipients know it.
  - A forward-only mailbox records arrivals in `processEmail`, so `first_contact`
    keeps its meaning there.
  - Steps 1–2 decide before the relationship lookups and the corpus vote.
  - The scanner gate pins the five files' settings by hash: a settings change
    needs a `RELAY_VERSION` bump.
  - Scanner 2.1 reloads a running rspamd instead of restarting it, and keeps
    redis until rspamd is confirmed on the new configuration.
- `iem_sender_recorded` (new, clear bool) marks rows whose facts are in the sender
  record; the backfill selects on it.
- Open: the converger path (install_email.sh → scanner 2.0 removing redis) cannot
  run on dev, where the mailbox plugin's status is `stale` and its host installer
  is skipped. Verify on the first node after release.

## Problem

A user's "Mark as spam" / "Not spam" should make the filter better. Today that
learning lives inside rspamd, a separate program, and costs more than it returns:

| Measured 10-02 | |
|---|---|
| rspamd configurations we maintain | 2 — the tenant scanner (`provision_spam_scanner.sh`) and the relay (`provision_relay.sh`). The 09-30 dead-blocklist fix had to land in both (scanner 1.1, relay 3.3 with a relay version bump). |
| Services that exist only for learning | redis, on every box that hosts its own mail (~95% of deploys) |
| Code that exists only to reach rspamd's learning | `SpamLearning.php` (308 lines: rebuilds a fake email from sealed fields, posts it, sorts 2xx/4xx/5xx), `LearnSpamFeedback` task, the controller-reachability half of `MailboxSpamPolicy` (282 lines), the ingest re-scan in `InboundEmailRouter` (`resolveContentSpam` re-scan, `scanContentSpam`, `interpretScanResponse`), the learning-unavailable branches on the Settings page, the setup check and the health probe |
| Tests that need a real or stand-in rspamd | `spam_scanner_gate.sh` (117 lines, real rspamd), `spam_learning_corrections_test.php` (stub rspamd via `php -S`) |
| Track record | Learning shipped 07-22. On jeremytunnell the corpus stayed empty until 09-30: the task was never activated, it skipped sealed mail, and its selection taught every ingest verdict rather than corrections. Nobody could see it, because the state lived in redis. |
| Self-reinforcement | Tenant rspamd runs Bayes with `autolearn = true`: it trains on its own confident verdicts, which locks in its own mistakes. |
| Webhook-only deploys | Cannot learn at all — no scanner, so the Settings checkbox is disabled. |

rspamd also cannot use anything the platform knows:
- whether a message replies to mail the user sent;
- whether the sender is a contact, or someone the user writes to;
- what the user did with that sender's earlier mail.

rspamd's static rules (phishing, malformed MIME, fingerprints, URL lists,
auth-aware scoring) stay. On a deploy without a relay they are the only content
check a new or small deployment has, and they are maintained upstream. This spec
keeps rspamd as a header-stamping milter and moves only the learning.

## Goals

- **One rspamd configuration.** Tenant boxes and relays write the same stateless
  settings from one file: static rules, header stamping, Bayes off, no redis, no
  controller use by the app.
- **No redis.** Removed from every box on upgrade.
- **Learning is a Postgres table** taught inside the request that records the
  user's action, and visible to tests. It works on every deploy, webhook-only
  included.
- **The filter uses what the platform knows**: replies to the user's own mail,
  contacts, correspondents, and the user's past decisions about a sender.
- **The filter learns from replies as well as explicit corrections,** and shows
  how far it is from voting.
- **Every verdict says why.** The message timeline shows the deciding reason.

## Non-goals

- **AI verdicts.** The danger score keeps running after ingest and stays
  display-only (owner 10-02).
- **Removing rspamd** from boxes without a relay.
- **IMAP-polled mail.** It is neither classified nor taught (B19). The remote's
  junk folder stays the verdict, and a correction on a polled row moves it here
  without touching the corpus. Its content is the remote's mail stream, and the
  core classifier never scores it.
- **Per-user corpora.** One corpus per deployment. Most deployments are one person
  or one household, and a shared corpus trains faster. The `member` signal was
  dropped (B2), so no deployment-wide trust is granted by any one user's data.

## Design

### The verdict, in order

`InboundEmailRouter::classifySpam()` becomes the one decision, evaluated top down;
the first step that answers wins. Each answer carries a reason code stored in
`iem_spam_reason`.

| # | Step | Verdict | Reason code |
|---|---|---|---|
| 0 | Filing off | NULL | — |
| 1 | Auth rule (`InboundEmailMessage::authRuleSaysSpam`, unchanged) | spam | `auth` |
| 2 | rspamd score ≥ `SCANNER_FLOOR` (15, rspamd's own reject level). Only for scores rspamd produced (local milter or relay), never a webhook provider's scale (B8). | spam | `scanner` |
| 3 | Relationship (below) | ham | `reply`, `contact`, `correspondent`, `rescued` |
| 4 | Sender record: ≥ 2 spam teachings and no ham teachings for this fingerprint on this mailbox | spam | `sender_history` |
| 5 | Bayes trained and p ≥ 0.99 | spam | `bayes` |
| 6 | Bayes trained and p ≤ 0.01 | ham | `bayes` |
| 7 | Scanner signal (milter/relay `X-Spam`, webhook provider flag) says spam | spam | `scanner` |
| 8 | Otherwise | ham | `none` |

Mail rules still run after the verdict and are final (`never_spam` /
`mark_spam`, reason `rule`). Joinery Direct's verified-contact path is unchanged.

**Nothing overrides step 1.** A DMARC failure means the From header is
unattested, so no claim about the sender can rescue it — the same reasoning
`elevateForContact` documents today. The Spam view's "Always allow sender" rule
stays the one deliberate way past it.

**Nothing but a mail rule overrides step 2 (B1).** A score high enough that
rspamd itself would reject is spam regardless:
- Bayes can't rescue it: a trained corpus learns what normal mail looks like,
  and good phishing looks like normal mail.
- A relationship can't rescue it either. Thread-hijack phishing replays real
  Message-IDs stolen from a compromised correspondent, and a compromised contact's
  account passes DMARC.

This is a behaviour change: today a contact clears even a very high score. The
explicit "Always allow sender" rule is the way to trust such a sender knowingly.

### Relationship signals (step 3)

A From address only counts when DMARC passes (`iem_dmarc_result = 'pass'`), so a
spammer cannot borrow a contact's address. This deliberately ignores domains with
no DMARC record. Those fall through to Bayes, which sees their auth state as a
meta token.

| Code | Fires when | Needs DMARC pass |
|---|---|---|
| `reply` | One of the Message-IDs in the message's References / In-Reply-To equals the `iem_message_id_header` of an outbound row on the same alias, and that row was composed by the user. Matched only against the user's own random Message-IDs (`generateMessageId`), **never `iem_thread_key`** (B3): the thread key is the thread's root, which in a thread someone else started is the stranger's ID, and on a mailing list it is public. | No — knowing a private Message-ID is the proof. Steps 1–2 still apply, which covers IDs that went public on a list. |
| `contact` | The sender fingerprint is on a contact row for this mailbox (`imc_sender_fingerprint`), and the sender record has no spam teachings | Yes |
| `correspondent` | The sender record shows ≥ 1 composed send to this address, and no spam teachings (B18) | Yes |
| `rescued` | The sender record shows ≥ 1 ham teaching and no spam teachings | Yes |

`contact` replaces `elevateForContact()`. It overrides the content layer below
step 2, Bayes included, and works on sealed mailboxes.

`member` (a verified site user) was dropped (B2). Registration is open by default
(`register_active` = 1) and email verification is self-service, so membership
proves nothing about a stranger.

### The sender record

One row per (mailbox, sender fingerprint) holds every fact the relationship and
history steps read (B7):

| `isr_inbound_sender_records` | |
|---|---|
| `isr_iea_inbound_email_alias_id`, `isr_sender_fingerprint` | unique pair |
| `isr_sent_count`, `isr_last_sent_time` | composed sends to this address (not forwards, B4) |
| `isr_spam_taught`, `isr_ham_taught` | teachings, written in the teach transaction |
| `isr_first_seen_time`, `isr_message_count` | inbound history (drives `first_contact`) |

These are counters, not row counts. Trash retention purges deleted messages after
30 days, so counting `iem_` rows would let "taught spam twice" decay and "never
taught spam" flip back. A counter keeps what the user taught after the messages
are gone. Unteaching a message decrements its counter.

### The sender fingerprint (owner decision, 10-02)

`fingerprint(address) = HMAC-SHA256(key, lowercase(trim(address)))`, 64 hex
characters.

**The key** is a per-deployment secret generated on first use in the setting
`mailbox_sender_fingerprint_key`, declared `managed` in `plugin.json`: it is
machine-written and never rendered on a form (B13).
- A `secret` declaration would render as a password field, where a blank
  submission removes the value.
- Generation refuses to replace an existing value. A new key would silently
  orphan every fingerprint.
- Backups and site copy carry it with every other setting.

**Computing it.** At ingest, from the header From address while the plaintext is
in memory. On a composed send, from each recipient address (into the sender
record). On contact add or import, into `imc_sender_fingerprint`.

New clear columns:
- `iem_sender_fingerprint` (indexed with the alias id);
- `imc_sender_fingerprint`;
- the `isr_` table.

**Fortress mail.** The server never sees its header From, so it gets no
fingerprint and no relationship except `reply` (B17). `storeRelayPending` already
stores `iem_message_id_header` and the References-derived keys in the clear from
relay metadata, so `reply` needs no device post.

**Backfill.** Standard mailboxes are filled by the upgrade task: message
fingerprints, contact fingerprints, and sent counts from existing composed
outbound rows. Sealed rows are filled when the owner's window is open, by the
same drain that teaches sealed corrections. The signals work on new mail at once
and get stronger as the backfill completes.

### The Bayes classifier

`SpamBayes` (new, `plugins/mailbox/includes/`) has three parts.

**Tokenizer.** It works from the parsed message, never a rebuilt email:
- the subject and the plain body (HTML converted to text when there is no plain
  part), lowercased and split into words of 2–40 characters;
- every word plus every pair of words up to 4 apart (the word-pair method rspamd
  uses);
- the hosts of the URLs in the body;
- the sender's domain and display name;
- the meta tokens (below);
- capped at the first 2,000 distinct tokens.

Each token is stored as the first 8 bytes of
`HMAC-SHA256(token key, token)`, a bigint. The token key is a second `managed`
setting, `mailbox_spam_token_key`, separate from the fingerprint key (B11).
`SpamBayes::TOKENIZER_VERSION` is stamped on every message it teaches (B10).

**Meta tokens.** Weak signals become tokens, so Bayes learns their weight from
the user's own teaching instead of from weights we tune by hand:
- `first_contact`: no earlier message on this mailbox from this fingerprint;
- `catch_all`: no alias;
- `dmarc:pass|none|unverified`, `spf:…`, `dkim:…`;
- `scanner:<source>:<band>`: the scanner score in 2-point bands, tagged with who
  produced it (`rspamd`, `sendgrid`, `mailgun`, `ses`), so scales never mix (B8);
- `burst`: the same envelope sender reached 5 or more of this deployment's
  aliases in 10 minutes (from `iel_` rows).

They are computed at ingest from clear facts and stored in `iem_spam_meta`
(clear; meta tokens only, never content words), so learning later uses the same
facts the verdict saw.

**Store.**

| Table | Columns |
|---|---|
| `ibt_inbound_bayes_tokens` | `ibt_token` bigint primary key, `ibt_spam_count` int, `ibt_ham_count` int, `ibt_last_seen_time` |

The message totals live on a reserved row (`ibt_token = 0`).

**Scoring.** Robinson's per-token probability with Fisher's chi-square combining,
using the 150 most decisive tokens. Bayes votes once the totals reach
`MIN_SPAM = 50` and `MIN_HAM = 50` (B6).
- Spam teachings come only from explicit marks, and 200 (rspamd's gate) would
  rarely be reached on a one-person deployment.
- The strict 0.99 / 0.01 thresholds are the safety margin. A young corpus lands
  between them and defers to the scanner.

**Progress is visible.** The Settings page shows the corpus totals and how many
more examples Bayes needs before it votes ("needs 37 more spam examples").
Timeline entries decided below step 6 say when Bayes was not yet voting.

### Teaching

The learner teaches a row whenever `iem_train_verdict IS DISTINCT FROM
iem_learned_verdict`. `iem_train_verdict` (new, clear) is what the user's
behaviour says about that message. NULL means no evidence, so ingest verdicts are
never taught and the classifier never trains on its own output.

| User action | Sets `iem_train_verdict` |
|---|---|
| Mark as spam | spam (also stamps `iem_spam_corrected_time`, as today) |
| Not spam | ham (same) |
| Always allow sender | ham, on the messages in hand |
| Sends a reply to the message: a composed send (`mst_kind` = compose, never forward) whose In-Reply-To equals the source message's Message-ID (B4) | ham |

What teaches nothing:
- **Deleting from the Spam view (B5).** Nobody opens spam; the view is emptied in
  bulk. Teaching those deletes would teach every false positive in it, which is
  the classifier training on its own output through the user's hand.
- **Reading or archiving.** People read and archive spam too.
- **Forwards,** automatic or by a mail rule.

**One teaching, one transaction (B9).**
1. Compare-and-set first: `UPDATE … SET iem_learned_verdict = :new,
   iem_learned_tokenizer = :v WHERE id = :id AND iem_learned_verdict IS NOT
   DISTINCT FROM :old`. A row count other than 1 means another request already
   taught it, so this transaction writes nothing more.
2. Upsert the tokens in ascending order, the totals row (0) first. Every teaching
   locks rows in the same order, so two cannot deadlock.
3. Update the sender record's counters.

A half-taught message cannot exist.

**Unteaching.** Teaching a message the opposite verdict first subtracts it from
the class it was taught to. Subtracting needs the tokens, which means the
plaintext. Standard rows retokenize at once; sealed rows wait for the owner's
window, as today. If `iem_learned_tokenizer` differs from the current version,
the token subtraction is skipped. The totals and the sender record are still
adjusted, and counts are clamped at 0 (B10). No per-message token list is ever
stored: a bag of word hashes per message would be a readable copy of the content.

**Where teaching runs.** Standard rows are taught in the request that records the
action. Sealed rows are taught by the existing window drain. `SpamLearning` keeps
its selection and drain role. It loses the email rebuild, the HTTP call and the
outcome sorting, and it keeps marking Fortress corrections `handled`, so they are
never selected again (B17).

**Pruning.** A weekly scheduled task:
- deletes tokens with spam + ham ≤ 1 that have not been seen for 60 days;
- then deletes the oldest low-count tokens beyond 2M rows;
- removes sender records with no sends, no teachings and no message in 180 days.

### One rspamd configuration

- **One shared file.** `provisioning/rspamd_stateless.sh` (new) is the only
  writer of rspamd's joinery-managed `local.d` files, on tenant boxes and relays
  alike: the header contract, add-header-only actions, the dead-blocklist list,
  the milter worker, and `classifier-bayes.conf` with `enabled = false;
  autolearn = false;`. Tenants keep that file rather than deleting it (B14):
  rspamd's stock `statistic.conf` still declares a redis-backed classifier and
  logs an error on every scan without it.
- **The relay.** It sources the shared file. Its settings are unchanged, but some
  comment text changes (the relay's `classifier-bayes.conf` comment claims each
  tenant's rspamd re-scores with its own state, which stops being true).
  - Settings identical, comments not: `RELAY_VERSION` is not bumped and no relay
    has to be updated. Relays pick up the new text when they are next rebuilt.
  - The gate diffs all five files with comments stripped, not just the two in
    the contract digest.
- **Tenant boxes on upgrade.** `provision_spam_scanner.sh` 2.0 `install`:
  - writes the shared files;
  - deletes `redis.conf` and `worker-controller.inc`;
  - stops and purges `redis-server` (its header already states redis exists only
    for the scanner);
  - reloads rspamd;
  - is idempotent on a box where redis is already gone (B15).

  **Trigger:** `install_email.sh` calls `install` unconditionally, and the host
  converger re-runs `install_email.sh` when the deployed VERSION changes. That
  was observed on jeremytunnell 09-30, when 0.8.450's `rbl.conf` arrived with no
  hand step. The build verifies that path in the converger before relying on it,
  and documents it in the script header.
- **New installs.** `install_email.sh` section 5b already calls
  `provision_spam_scanner.sh install`, so a new box gets the stateless config and
  never installs redis. No new hand step. `install_email.sh` changes text only:
  - the 5b comment (no redis, no ingest re-scan, no learning loop);
  - a version line.
- **`remove`** still purges `redis-server` alongside rspamd, for boxes that
  never upgraded.
- **Health check text.** The `content_spam_scanner` entry in `plugin.json` says
  "installs rspamd + redis"; it now names rspamd only.
- **Removed from the app (B16):**
  - the ingest re-scan: `resolveContentSpam` reads the arriving signal only;
    `scanContentSpam` and `interpretScanResponse` go;
  - in `MailboxSpamPolicy`: `scannerAvailable`, `overrideScannerAvailable`,
    `controllerUrl`, `controllerReachable`, `scanAtIngest` and
    `localVerdictReplaces`;
  - the callers of those:
    - `InboundEmailSetupCheck` (the `covered` computation, ~:1396),
    - `MailboxRelayReconcile` phase 3 (~:234), which reports the relay's
      scanner on its own,
    - `InboundEmailHealth` (~:365),
    - the Settings logic,
    - `LearnSpamFeedback` (~:47);
  - the `mailbox_rspamd_controller_url` setting.
- **Health.** A deploy without a relay checks that the milter is wired and
  answering on 11332. Relay and webhook deploys have no local scanner to check.
- **Settings.** `mailbox_spam_learning_enabled` is offered everywhere filing is on.
  The scanner-presence clamp and its disabled-with-reason state go.
- **Agent vocabulary.** The server_manager file-read words
  `rspamd_classifier_bayes` and `rspamd_redis` stay: relays and not-yet-upgraded
  boxes still have those files, and an absent `redis.conf` is the expected answer
  on an upgraded tenant. Update the words' descriptions and
  `agent_vocabulary_words_test`.

### Moving to the new corpus

The redis corpus is discarded with redis. Corrections since 0.8.450 are on the
rows (`iem_spam_corrected_time`). The upgrade task:
- sets `iem_train_verdict` from them;
- clears `iem_learned_verdict`, so the learner re-teaches all of them into the
  table (Standard rows at once, sealed rows in the owner's window).

**What can be lost (B7):**
- A sealed correction whose owner opens no window before trash retention purges
  the row (30 days by default) is never taught.
- Corrections made before 0.8.450 left no record (09-30) and are already gone.

### Explaining a verdict

`MailboxMessageTimeline` reads `iem_spam_reason` and shows it in plain words. For
example: "Not spam: a reply to mail you sent", "Spam: you marked this sender as
spam twice", "Spam: learned from your corrections", "Spam: the scanner's score
was very high". The Spam view's explanation line uses the same text.

## Disclosure

Everything below is clear on every mailbox, sealed ones included. The
protection-level docs list all of it among the columns that stay readable.

| What | What someone holding the database (and the keys, which are in it) can learn |
|---|---|
| `iem_sender_fingerprint`, `isr_` rows | For a guessed address: did it write to this mailbox, how often, when; has the mailbox written to it; how the owner taught its mail. The envelope sender is already clear in `iel_inbound_email_logs`; this adds the header From. |
| `imc_sender_fingerprint` | For a guessed address: is it one of this mailbox's contacts. |
| `iem_spam_reason`, `iem_spam_meta` (B12) | How a message relates to its owner (a reply to their mail, a contact, a first contact), with no address. |
| `ibt_` tokens (B11) | For a guessed word, word pair or URL host: how often it appeared in mail taught spam or ham across the deployment, sealed mail included. Aggregate only, with no link to any message, mailbox or time. The redis corpus held the same counts with unkeyed hashes. The difference is that the table is now in backups and site copies. |

Fortress mail contributes only `reply` reasons and its meta tokens. Its
corrections are never taught.

## Work packages

All three ship in one release: WP2 removes the old learning, so WP1 has to be
there to replace it.

**WP1 — Classifier and teaching**
- `SpamBayes` (tokenizer, store, scoring, teach/unteach, transaction order).
- The `ibt_` table and `mailbox_spam_token_key`.
- `iem_train_verdict`, `iem_learned_tokenizer`, `iem_spam_meta`, `iem_spam_reason`.
- `SpamLearning` reduced to selection plus drain.
- The pruning task.
- The re-teach upgrade task.
- `classifySpam` steps 2 and 5–8.
- The Settings progress line and the timeline text.

**WP2 — One rspamd configuration**
- `rspamd_stateless.sh`.
- Scanner 2.0 removing redis.
- `install_email.sh` comment and version; the `plugin.json` health check text.
- Relay sources the shared file, with settings identical.
- App-side removals and their callers.
- Health and Settings changes.
- Agent vocabulary descriptions.

**WP3 — Relationship**
- The fingerprint key, the columns and the `isr_` table.
- Composed-send recording.
- Backfill: immediate for Standard, window drain for sealed.
- `classifySpam` steps 3–4, replacing `elevateForContact`.
- Reply teaching.

## Tests

- **Classifier (safe tier):**
  - tokenizer output and its version stamp;
  - chi-square scoring against fixed counts;
  - the 50/50 gate;
  - meta tokens per scanner source.
- **Verdict order (db tier):** every row of the table, including:
  - DMARC fail beats everything;
  - `SCANNER_FLOOR` beats a contact and a `reply`;
  - a webhook provider's score never triggers step 2;
  - `reply` matches only the user's own composed Message-IDs, never a thread
    root someone else started;
  - `contact` and `correspondent` lose to a single prior spam teaching.
- **Teaching (db tier):**
  - teach, flip and unteach;
  - two concurrent teachings of one message add it once;
  - two teachings of different messages with overlapping tokens finish without
    deadlock;
  - ingest verdicts never teach;
  - a forward (`mst_kind` forward) never teaches or counts as a send;
  - deleting from Spam teaches nothing;
  - unteach after a tokenizer bump skips tokens and clamps at 0;
  - sender counters survive the purge of the messages they came from;
  - IMAP-polled rows never teach.
- **Sealed (db tier):**
  - a sealed correction waits for the window, then teaches, writing no plaintext;
  - the fingerprint is computed at ingest on a sealed mailbox;
  - a Fortress correction is marked `handled` and never re-selected.
- **Upgrade (db tier):**
  - corrected rows are re-taught into the table;
  - generating the fingerprint or token key refuses to overwrite an existing
    value.
- **Scanner gate:** `spam_scanner_gate.sh` rewritten to check:
  - the scanner stamps headers;
  - redis is absent after `install`, and a second `install` succeeds;
  - `classifier-bayes.conf` is present with `enabled = false`;
  - all five managed files match the relay's with comments stripped.
- **Retired:** the stub-rspamd learning test.

## Docs

- `plugins/mailbox/docs/overview.md`:
  - rewrite § Content scanner (one config, no re-scan, the topology table
    collapses);
  - add § Spam learning (verdict order, signals, sender record, teaching,
    corpus, progress);
  - list the new clear columns where the doc lists what stays readable on
    sealed mailboxes.
- `docs/sealed_vault.md`: the Disclosure table.
- Version bumps on every touched file that carries one.
