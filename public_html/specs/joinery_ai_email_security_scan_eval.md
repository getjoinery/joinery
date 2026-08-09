# Joinery AI — Email security scan: model eval + labeled corpus

**Status:** In progress
**Companion to:** `joinery_ai_email_security_scan.md` (the feature),
`joinery_ai_item_pipeline.md` (the engine)

## Why

The scan works mechanically — validated end-to-end through the real
`PipelineRunner`, zero errors. The open question is *which local model scores
accurately*: high phishing recall without drowning legitimate mail in false
positives. That can't be answered from synthetic probes — it needs a labelled
corpus of real mail scored through the real pipeline. This doc tracks building
that corpus, the scoring harness, the model comparison, and the prompt/threshold
tuning that follows. It is a long-running effort; the Progress list at the
bottom is the running state.

## Approach: hold authentication constant with a controlled stamp

The digest's `AUTHENTICATION` line drives the score heavily, and the case the
feature exists to catch is phishing that *passes* SPF/DKIM/DMARC ("authenticated
but malicious"). Two facts shaped the method:

- **Re-received mail can't carry the original verdict.** Forwarding or
  re-sending makes the receiver re-evaluate auth against the *forwarding hop*:
  SPF fails, DKIM usually breaks, DMARC follows — so a phish that originally
  passed arrives looking failed, backwards for the exact case we care about.
- **We chose not to source from the user's Gmail** (the one place real original
  verdicts live) — declined on privacy grounds.

So instead of chasing real verdicts we **stamp a controlled
`Authentication-Results` verdict** onto real message content at fetch time
(authserv-id = the configured `mailbox_mail_hostname`,
`devmail.getjoinery.com`). Holding auth constant (`pass`) across both ham and
phish isolates what we actually want to measure — content discrimination — and
lets us manufacture the passing-auth phish class on demand. The loader derives
the `iem_*_result` fields from the stamped header via the existing
`AuthenticationResults.php`, so the digest reads it exactly as it would a
delivered message. (Later work can build `--auth=fail` sets to probe the auth
axis directly.)

## Sourcing (public corpora, no Gmail)

- **Phishing** — [Phishing Pot](https://github.com/rf-peixoto/phishing_pot):
  ~4,300 real honeypot `.eml`. `fetch_phishing_pot.php` pulls N, replaces the
  redacted `phishing@pot` recipient, strips the honeypot's temperror/none auth,
  and stamps a controlled verdict.
- **Ham** — [SpamAssassin public corpus](https://spamassassin.apache.org/old/publiccorpus/):
  `easy_ham` (ordinary legit) + `hard_ham` (commercial/newsletter mail that is
  structurally closer to spam). `fetch_spamassassin_ham.php` pulls, replaces the
  recipient, and stamps auth; labels easy as `ham`, hard as `ham_hard`.
- **Real-world phish** (`phish_real/`) — hand-added `.eml` of actual phishing
  received by the user, transformed by hand the same way the fetch tools do:
  recipient → eval alias, original auth-verdict headers stripped, controlled
  `Authentication-Results` stamped. First sample: a Gmail-delegation phish
  sent through Google's own notification infrastructure
  (`scoutcamp.bounces.google.com`, DKIM `d=google.com`, `dmarc=pass` under
  `p=REJECT`) that passed all of Gmail's checks — ~2.4k invisible characters
  padding the Subject, and an `accounts.google.com/ServiceLogin?continue=`
  open redirect into a fake sign-in on `sites.google.com`. The premium
  corpus class: real, delivered, fully authenticated brand-infrastructure
  phishing.

**Known corpus gap:** SpamAssassin is ~2002-era and lacks *modern* phishy-looking
transactional hard negatives (account security alerts, "verify your login",
password resets) — exactly the class most prone to false positives. So the
false-positive rate measured here is real but optimistic on that axis. Closing
it needs modern transactional mail; a curated Gmail set was declined, so an
alternative source is an open question.

## Ingestion

- `load_email_corpus.php` reads a corpus dir (`.eml` + `manifest.csv`), parses
  each message through the production parse/body-extraction path
  (`InboundEmailRouter::parseEmail` + `extractBodies`) so a corpus digest is
  identical to delivered mail, and scrubs each field to valid UTF-8 (some
  samples carry Latin-1 bytes a UTF8 column rejects).
- Derives `iem_spf/dkim/dmarc` from the stamped Authentication-Results
  (authserv-id `devmail.getjoinery.com`, matching the configured hostname),
  marks each row non-spam so `nextItem()` selects it, and writes
  `eval_labels.csv` (message id → label) for the scorer to join on.
- Loads onto a dedicated, disposable eval alias
  (`security-eval@dev.getjoinery.com`) so scoring reuses the real `nextItem()`
  path unchanged. `--reset` clears the alias; load phish with `--reset` then ham
  without, so both land.

## Scoring

`score_email_corpus.php --model=<id>` runs a candidate over the eval alias
through the real `PipelineRunner`, joins every `*/eval_labels.csv` under the
corpus root, and reports recall + false-positive rate at the ≥3 and ≥7
thresholds, mean score per label, wall-clock, tokens, and a per-message table.
Each invocation creates its own recipe (fresh processing log) so it re-scores
the whole corpus and overwrites the prior run's `iem_ai_*` verdicts.

`--ids=618,650,...` (scorer v1.1) scores only the listed messages — a targeted
partial run for testing a prompt change against a prior run's failures without
paying for the full corpus. It pre-seeds the recipe's item log for every other
message on the alias (status `skipped`) so `nextItem()` never selects them,
and restricts the report to the subset. The scorer also treats any verdict
timestamped before the run started as an error rather than a fresh score —
without that, an item that errors on a re-run silently counts with its stale
verdict from the previous run.

Candidates run so far: `qwen3:4b` (thinking baseline, speed only — too slow for
the full corpus), `qwen3:4b-instruct`, `gemma2:9b`. `qwen2.5:14b-instruct` is
the bigger option if we want to push precision further.

## Tooling (all local-only, gitignored under `tests/`)

- `tests/tools/fetch_phishing_pot.php` — phishing samples + auth stamp.
- `tests/tools/fetch_spamassassin_ham.php` — ham/hard-ham samples + auth stamp.
- `tests/tools/load_email_corpus.php` — parse + load onto the eval alias.
- `tests/tools/score_email_corpus.php` — score a model, report metrics.
- `tests/fixtures/email_security_corpus/{phish,ham}/` — `.eml` + manifests +
  `eval_labels.csv` (data, gitignored).

## Results — first full corpus (2026-07-06)

99 messages (49 phish / 30 ham / 20 hard-ham), all `auth=pass`, scored through
the real pipeline:

| model | recall @≥7 | FP @≥7 | recall @≥3 | FP @≥3 | mean phish / ham / hard | time |
|---|---|---|---|---|---|---|
| qwen3:4b-instruct | 100% (49/49) | **70%** (35/50) | 100% | 88% | 8.7 / 5.9 / 7.3 | 21 min |
| gemma2:9b | 90% (44/49) | **8%** (4/50) | 100% | 84% | 7.9 / 3.0 / 4.4 | 33 min |

**Decision: `gemma2:9b` at the `≥7` (dangerous) threshold.** It separates the
classes cleanly (phish 7.9 vs ordinary ham 3.0) where the 4B-instruct compresses
everything high (5.9–8.7) and can't. 90% recall with 8% false positives is a
deployable operating point; 3 of gemma's 4 false positives are deal/prize/
promotional mail that is genuinely phish-adjacent. The 9B's extra capacity buys
the precision the 4B lacks — worth its ~2× latency for a background scan.

**The `≥3` "suspicious" band is noise** — both models flag 84–88% of ham there.
The reader badge should fire only on `≥7`; the spec's amber 3–6 band should be
suppressed or the bands recalibrated. (Feeds back into
`joinery_ai_email_security_scan.md` § reader surface.)

**Resolved (2026-08-09) — bands recalibrated.** `0–4` safe (green, no badge on
the thread list at all), `5–6` caution (amber), `7–10` dangerous (red). The
break at 4|5 is the rubric's own: 3–4 is "minor flags only", 5–6 is "one strong
flag". The scoring rubric itself is untouched, so the corpus numbers above still
hold — only the label attached to a band moved, and the `suspicious` verdict
word became `caution`. The reader takes its tier from the score rather than the
stored verdict word, so rows scanned under the old mapping render under the new
one without a re-score.

## Failure analysis of the gemma run → prompt/digest v1.1 (2026-07-06)

Reading gemma's per-message verdicts off the eval messages showed one root
cause behind nearly all ham inflation: **check C fired on newsletter
click-trackers**. Almost every ham in the 3–6 band and 3 of the 4 ≥7 false
positives flagged the same two URLs — the Yahoo Groups ad footer
(`us.click.yahoo.com/...`) and CNET's `clickthru.online.com/Click?q=...`. The
v1.0 prompt's open-redirect clause ("wraps another URL in a parameter
(continue=, url=, redirect=, q=)") is a precise description of every
legitimate bulk-mail tracker, and the rubric's "exactly one strong flag =
5–6" then lifted a single footer link to 5. The same rubric line capped the
"GREETING TO YOU" 419 at 5 (advance-fee scams have no links or deadlines, so
only D fires), and the `mail-stellar.com` lookalike stalled at 6.

Changes (prompt + digest v1.1; job checklist grew a letter):

- **Check C rewritten**: a link is a strong flag only when tied to a
  sensitive ask (sign-in / verify / payment / account) or contradicting the
  claimed sender; trackers, ad-footer, and unsubscribe links in ordinary bulk
  mail are explicitly NOT flags.
- **Check G added (scam content)**: unsolicited windfall / advance-fee /
  guaranteed-return offers are definite phishing on content alone → 9–10 even
  with no links and passing auth. Verdict enum A-F → A-G.
- **Check A strengthened**: a lookalike/brand-impersonating domain is a
  strong flag on its own; scoring band 7–8 now lists it directly.
- **Check B fixed**: `unverified ≠ fail` — missing results are a minor flag
  at most (was the previously known issue below).
- **Scoring band 0–2 rewritten** to give the model an explicit landing spot
  for newsletters/mailing lists/marketing *including* their tracking links.
- **Digest additions** (`EmailSecurityDigest` v1.1, unit test extended):
  each anchor URL carries its visible link text when it differs (the classic
  text/href mismatch signal previously destroyed by tag-stripping), and a
  `DOMAINS:` per-host count summary (top 15) precedes the URL list so a small
  model never has to aggregate raw URLs itself.

Expectation: the ham FP mass is concentrated in those two tracker patterns,
so this should be a step change, not a nudge. Partially validated — see the
partial run below; the full-corpus re-run is still pending and the v1.0 table
above stands until it lands.

## Partial run — v1.1 prompt/digest, 28 targeted messages (2026-07-06)

`--ids` run on gemma2:9b covering every interesting case from the baseline:
the 5 missed phish, the 4 false-positive ham, 11 tracker-band ham (3–6), plus
regression guards (3 clean ham that scored 0, 5 confident phish that scored
8–9). 563s, 0 errors. The 9 baseline failures, v1.0 → v1.1:

| id | case | v1.0 | v1.1 |
|---|---|---|---|
| 654 | "GREETING TO YOU" 419 | 5 | **9** — check G fired |
| 647 | mail-stellar.com lookalike | 6 | **7** — lookalike = dangerous |
| 709 | CNET newsletter (hard ham) | 7 | **3** — tracker carve-out |
| 648 | Google Drive lure, no subject | 5 | 5 — still missed |
| 650 | "Scan your device", 53 URLs | 5 | 5 — still missed |
| 661 | Dutch lead-gen spam | 6 | 5 — dropped; carve-out excused its link |
| 683 | Yahoo Groups ad footer (ham) | 8 | 7 — still FP |
| 698 | Sweepstakes prize (hard ham) | 8 | 8 — see label tension below |
| 708 | CNET iPaq deal (hard ham) | 8 | 8 — still FP |

Both guards held: clean ham stayed 0/0/0, confident phish stayed 7–8 — the
softer link language cost no recall on real phishing (subset recall @≥7 went
5/10 → 7/10).

Two findings that shape what's next:

- **The tracker carve-out applies inconsistently.** Three mailing-list
  messages carrying the *identical* Yahoo footer link dropped to 3 while two
  others stayed at 5 and one at 7 — a 9B applies a rule like this
  probabilistically, not mechanically. The 3–6 band drifted down (several 5s
  → 3s) but did not empty; the plan to act only on ≥7 and suppress the amber
  band is unchanged.
- **Message 698 is now a deliberate disagreement, not a mistake.** It's a
  sweepstakes-prize notification demanding the recipient mail in personal
  information; check G intentionally scores prize-winnings content 9–10. The
  SpamAssassin `hard_ham` label reflects that it was technically legitimate
  2002 marketing — but a guardian that warns on it is arguably doing its job.
  Open labeling decision: reclassify phish-shaped promotions (698, 708-style
  "too good a deal" mail) or accept a small FP floor on that class.

Read on the aggregates: the subset is deliberately failure-heavy, so its raw
FP rate overstates the corpus rate. Directionally: recall @≥7 rises from 90%
(two of five misses fixed, none regressed) and FP @≥7 falls (one of four
fixed, none added).

## Known prompt issue to test here

Check B conflated auth **fail** with **missing/unverified**. Fixed in the
v1.1 prompt (minor flag at most); re-validation on the corpus still pending.
Note the corpus is all `auth=pass`, so this axis needs the `--auth=fail` /
stripped-auth sets to actually measure.

## Context already landed (2026-07-06)

- Local per-call token cap `4000 → 16000` (reasoning models need headroom;
  fixed the empty-output truncation that skipped every message on the 4B).
- `OpenAiCompatibleProvider`: `reasoning_effort` replaces the inert
  `/no_think` token (current Ollama qwen3 templates ignore the in-prompt
  switch; they read the request field). Docs updated to match.
- Speed baselines (12 messages, 0 errors each):
  `qwen3:4b` 2133s / 60k tok · `gemma2:9b` 163s / 1.5k tok ·
  `qwen3:4b-instruct` 88s / 2.5k tok.

## Progress

- [x] Fix token cap + thinking control; validate end-to-end (0 errors).
- [x] Speed head-to-head across three models.
- [x] Build the Phishing Pot fetch/rewrite tool (`fetch_phishing_pot.php`);
      verified stamped auth parses back correctly.
- [~] Gmail set for modern transactional hard negatives — DECLINED (privacy).
      Alternative source for that class still open.
- [x] Build the `.eml` loader (`load_email_corpus.php`) + disposable eval
      alias `security-eval@dev.getjoinery.com` + owner grant + `eval_labels.csv`.
      Reuses the production MIME parse/body extraction so a corpus digest is
      identical to delivered mail; verified a loaded passing-auth phish renders
      `spf=pass dkim=pass (d=...) dmarc=pass`.
- [x] Build the scorer (`score_email_corpus.php`): runs a model over the eval
      alias via the real `PipelineRunner`, joins `eval_labels.csv`, reports
      recall / false-positive rate at >=3 and >=7, mean score, timing, errors,
      per-message table. Smoke test (3 passing-auth phish, `qwen3:4b-instruct`):
      scored 9/9/8 dangerous — held the line despite `dmarc=pass` (check B).
- [x] Ham source without Gmail: SpamAssassin corpus + `fetch_spamassassin_ham.php`.
- [x] Score instruct + gemma on the 99-msg corpus → **gemma2:9b @ ≥7** picked
      (90% recall, 8% FP). See Results above.
- [x] Failure analysis of the gemma run; prompt + digest v1.1 (tracker
      carve-out, scam-content check G, lookalike = strong A, unverified ≠
      fail, anchor text + DOMAINS summary in the digest). See section above.
- [x] Scorer `--ids` partial-run mode + stale-verdict guard (scorer v1.1).
- [x] Partial run of v1.1 on the 28 interesting messages: 419 and lookalike
      fixed, one CNET FP fixed, guards held, carve-out inconsistent, 698
      label tension surfaced. See section above.
- [x] First real-world sample (`phish_real/sample-real-001.eml`, message 795):
      the Google-infrastructure delegation phish. Digest verified (subject
      annotation "removed 2419 invisible/whitespace characters"; the
      continue= redirect URL survives). gemma2:9b @ v1.1 scored it **7 —
      dangerous**, correctly citing the sites.google.com destination behind
      the accounts.google.com wrapper; it did not also cite the padding (F)
      or 24-hour deadline (E) that would justify 9-10 under the rubric —
      right verdict, under-cited evidence.
- [x] Four-model head-to-head on that one real phish (v1.1 prompt/digest):

      | model | score | flags cited | time |
      |---|---|---|---|
      | qwen3.5:9b-nvfp4 | **9 dangerous** | A,C,D,E,F — all of them, incl. the padding annotation and Return-Path mismatch | 44s |
      | qwen3:4b-instruct | 8 dangerous | C,D,E,F (but this model flags 70% of ham too) | 22s |
      | gemma2:9b | 7 dangerous | C only | 32s |
      | qwen3:4b (thinking) | **3 suspicious — MISS** | E only; 9.5k reasoning tokens concluded "standard Google Workspace delegation notification" | 420s |

      Two lessons: (1) extended thinking actively hurt — the model reasoned
      itself into trusting the authenticated sender, exactly the trap check
      B warns about; (2) qwen3.5:9b-nvfp4 gave the ideal verdict (right
      score, every flag cited with the digest's annotations as evidence) at
      gemma-class speed — it has never been scored on the full corpus and is
      now the lead candidate for the v1.1 full re-run alongside gemma.
### Open — candidate next steps (undecided; pick where to go)

- [ ] Re-score the full corpus on the v1.1 prompt/digest — gemma2:9b and
      qwen3.5:9b-nvfp4 (new lead candidate per the head-to-head above); the
      v1.0 numbers above are superseded once this lands.
- [ ] Labeling decision from the partial run: reclassify phish-shaped
      promotions (698 sweepstakes, 708-style deals) vs. accept an FP floor
      on that class.
- [x] Recalibrate the amber reader badge (the ≥3 band was ~85% false
      positives): bands are now 0–4 safe / 5–6 caution / 7–10 dangerous, the
      list badge is silent below 5, and the verdict word `suspicious` became
      `caution`. Scoring rubric untouched, so the corpus numbers still hold.
      See the resolution note under Results.
- [ ] Set the scan recipe `rcp_model` to `gemma2:9b`; record the decision in
      `joinery_ai_email_security_scan.md`.
- [ ] Close the corpus gap: a source of modern transactional hard negatives.
- [ ] Optional: score `qwen2.5:14b-instruct` and the thinking `qwen3:4b` as
      precision/accuracy ceilings; build `--auth=fail` sets to probe the auth
      axis.

### Future directions (parked; recorded so the reasoning isn't lost)

- **Anchorable evidence quotes → highlight-and-hover reader.** The vision:
  the reader highlights the dodgy content inside the rendered email and
  explains each highlight on hover. The enabler is a required `quote` field
  on each red flag — an exact substring copied from the digest — which
  `validateVerdict()` verifies mechanically (quote must appear in the digest;
  reject → the runner's existing one-retry path) so a small model's shaky
  quoting becomes trustworthy. The reader then wraps matching body text in a
  highlight span whose tooltip is the finding sentence; flagged URLs are the
  easy case (they map to hrefs). The corpus harness can measure quote
  fidelity when this is built. Today's surface already shows the score,
  summary, and red-flag list in the reader's danger banner; the badge on the
  thread list row stays score-only.
- **Second-stage link resolution ("clicking the links").** Landing-page
  detonation is what commercial scanners do, but fetching has side effects
  (one-time tracking tokens, GET-triggered actions), attackers cloak against
  known scanner IPs (an arms race a single self-hosted IP loses), and the
  fetched page is attacker-controlled input (SSRF + injection surface). The
  shape that captures most of the value at a fraction of the risk: an
  escalation tier for messages scoring ≥3 that follows each URL's redirect
  chain in a sandboxed resolver (capped hops, no cookies, no JS) and appends
  only the final domain per URL to the digest for a re-score — collapsing
  the shortener/redirect ambiguity that drives most link uncertainty. Full
  page fetching waits for evidence that final-domain resolution isn't
  enough. Note: not evaluable on this corpus (2002 ham URLs and taken-down
  phish URLs are dead) — needs live mail.
