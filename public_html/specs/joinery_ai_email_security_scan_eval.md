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
(authserv-id = the configured `inbound_email_mail_hostname`,
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

## Known prompt issue to test here

Check B conflates auth **fail** with **missing/unverified**. Mail that
legitimately lacks auth shouldn't read as a strong flag. Candidate refinement:
treat `unverified ≠ fail`, re-validate on the corpus. (The prompt is
frozen/validated — any change requires a full re-run against the corpus.)

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
### Open — candidate next steps (undecided; pick where to go)

- [ ] Prompt tuning pass: calibrate the scoring bands so ordinary ham lands <3
      (the biggest lever — mean ordinary ham is 3.0 on gemma, right at the
      line); fix check-B to treat `unverified ≠ fail`; re-score the corpus.
- [ ] Recalibrate/suppress the amber 3–6 reader badge (the ≥3 band is ~85%
      false positives) — feeds `joinery_ai_email_security_scan.md § reader
      surface`.
- [ ] Set the scan recipe `rcp_model` to `gemma2:9b`; record the decision in
      `joinery_ai_email_security_scan.md`.
- [ ] Close the corpus gap: a source of modern transactional hard negatives.
- [ ] Optional: score `qwen2.5:14b-instruct` and the thinking `qwen3:4b` as
      precision/accuracy ceilings; build `--auth=fail` sets to probe the auth
      axis.
