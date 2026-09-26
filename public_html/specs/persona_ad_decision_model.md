# A decision model for the persona feed's ad judging

**Status:** Draft 2026-09-18. Phase 1 (measure) is the whole commitment; Phase 2
(integrate) is sketched and gated on Phase 1's numbers.

## What we want

The persona browser marks each Facebook feed post as an advertisement or not
(`MarkAdvertisementsJob`, recipe `persona_browser_mark_ads_default`). Today the
35B MoE on the Mac Studio does it: a system prompt, one post, a JSON verdict
`{is_ad, reason}`, roughly a second per post plus reasoning tokens we cannot
switch off over `/v1/chat/completions`.

The job is one yes/no on a short text (dev: avg 112 chars, max 481). That is
the shape a new class of model is built for. TypeSafe's Jev (announced
2026-09-15) takes a description of a situation plus a typed question and returns
a calibrated probability in one forward pass — no text, 70–500 ms, no
hallucinated JSON. Jev itself is hosted-only and proprietary, so it is out (dev
is local-only and the product direction is local AI). Within days of its launch
several open-weight replicas appeared; the credible one is **Laya**
(`convaiinnovations/laya` on Hugging Face, `pip install laya`, Apache-2.0):
ModernBERT-large plus a decision head, 421M parameters, ~35 ms per question on a
GPU, three question types — Choice, Score, Noul (yes/no probability).

A week later **Ollaya** appeared (`ollaya-dev/ollaya`, Apache-2.0, Rust): "Ollama
for decision models". One binary pulls Laya and other open decision models by
name, serves them from a local daemon on `:11435`, and speaks TypeSafe's
`/v1/systemone` wire format plus a native `/api/decide`. It is the runtime for
this spec. On Apple silicon it runs on the CPU only (ONNX Runtime, fp32) — no
Metal — which is fast enough for this job (measured below).

We want to know whether a model like this can take over ad judging, and at
what cost in accuracy. Measure first; build only if the numbers say so.

## What we already have

Dev holds the evaluation set: **2,521 judged posts, 735 marked ads** (as of
2026-09-26), every label from `qwen3.6:35b-a3b-nvfp4`, with the reason phrase on
each; 121 carry an `event…` reason. The digest the model saw is reproducible
from the row (`author`, `pfi_message`, `pfi_image_alt` — see
`MarkAdvertisementsJob::digest()`).

The labels are the 35B's opinion, not ground truth. Agreement with the 35B is
the metric we can compute cheaply; the owner's own calls on the disagreements
are the metric that matters. Every row was judged on or after 2026-09-12, so
all of them carry the "events are never advertisements" rule.

## Three constraints the model class imposes

**C1 — No reason.** The verdict contract is `{is_ad: bool, reason: string}` and
the feed badge shows the reason in its tooltip. A decision model returns a
probability and nothing else. Phase 1 fills `reason` with the probability
(`"p=0.93"`); whether the tooltip needs more than that is a Phase 2 decision.

**C2 — Policy lives in the labels, not the prompt.** `defaultPrompt()` carries
the operator's rules (what counts, what does not, events never). A decision
model has no prompt to read; it knows only what its training labels encoded.
Off the shelf, Laya will apply *its* notion of "advertisement". Our rules reach
it only through fine-tuning on our own labels — which we have, subject to the
events caveat above.

**C3 — It does not speak the provider protocol.** Every joinery_ai provider is
an `LlmProviderInterface` over a chat-completions endpoint, and
`PipelineRunner::judgeItem()` asks the model for JSON text and parses it. Laya
is a Python package with its own question schema. Production use needs a shim
on the Studio and a provider shape joinery_ai does not have. This is why Phase
1 runs entirely outside the platform.

## Phase 1 — measure

Runs on the Mac Studio (`ssh macstudio`), never touching the Ollama LaunchAgent.
Inference goes through Ollaya (`~/.local/bin/ollaya`, pinned 0.6.1, bound to
`127.0.0.1:11435`, started by hand — no service yet). Training runs in a Python
3.12 venv under `~/laya-eval/venv` (made with `uv`; the box's system Python is
Apple's 3.9, too old for `laya`). Work files live in `~/laya-eval/`, with copies
in the dev scratchpad.

**Memory is the constraint.** The 35B peaks near 29 GiB of the Studio's 32 GB.
A decision model has to fit in what is left, one at a time, with a swap guard on
every run. Measured: `laya:en` inference ≈ 2.5 GB, fine. Ollaya's `decider` (a
2B decoder) pushed the box into 15 GB of swap and slowed the 35B from ~50 to
17 tok/s — it is excluded, and was deleted from the box. Laya's own fine-tune
recipe trains the whole encoder (≈ 8–10 GB); here only its top layers and the
decision head train, so training fits beside the 35B.

### Steps

1. **Export the eval set from dev.** One JSON line per judged post:
   `{id, author, message, image_alt, is_ad, reason, judged_time, model}`.
   Build the text exactly as `digest()` does (Author / Post / Image blocks) so
   both models see the same thing.
2. **Zero-shot.** Ask each candidate one question per post — "Is this post an
   advertisement or sponsored/promotional content?" — and record `p_ad`.
   Threshold 0.5 for the headline number; sweep the threshold too. Candidates:
   `laya:en`, and the two Ollaya models of the same size class, `nli`
   (DeBERTa-v3-large zero-shot NLI) and `gliclass` (GLiClass instruct large).
   `laya:en` is asked twice: as a Noul, and as a two-option Choice — Laya's own
   notes say Noul on the English checkpoint can follow its `false`/`true`
   labels instead of the text (laya issue #156).
3. **Score against the 35B**, reported as:
   - agreement overall, and separately on the 735 ads and the 1,786 non-ads
     (a model that says "not ad" to everything scores 71% — the per-class
     numbers are the honest ones);
   - agreement on the event subset (35B reason starting `event:`) — the
     expected weak spot;
   - calibration: bucket `p_ad` into deciles and show the agreement rate per
     bucket. The escalation pattern in Phase 2 only works if low confidence
     actually predicts disagreement.
4. **Owner review of the disagreements.** List them with both verdicts, sorted
   by Laya's confidence. The owner marks who was right. This is the only
   ground truth we get, and it may show the 35B is the one that is wrong on
   some of them.
5. **Fine-tune, if zero-shot is not enough.** Split by a hash of the post id:
   20% holdout, 8% for fitting the calibration temperature, the rest (~1,800)
   to train. Train Laya's decision head plus its top encoder layers on the
   35B's labels, then rerun step 3 on the holdout, alongside the zero-shot
   models scored on the same holdout. The script (`finetune.py`) is Laya's
   own notebook (`laya_finetune_typed_decisions_2xT4_kaggle.ipynb`) cut down to
   one device, with cross-entropy on hard labels in place of its RL term.
6. **Timing.** Wall clock for all 2,521 on the Studio, cold and warm, vs. the
   35B's per-post time from the recipe run logs.

### Success bar

Phase 2 is worth building if, on the owner-reviewed disagreements plus the
holdout set, the decision model is at least as often right as the 35B on the
non-ad class (false "Ad" badges are the cost the owner sees — a real post
dimmed and tooltipped as an ad) and within a few points on the ad class, with
calibration good enough that routing the bottom ~10% of confidence to the 35B
recovers most of the remaining gap.

If zero-shot is poor and fine-tuning does not close it, stop; write the numbers
into this spec and leave the 35B in place. Nothing in the platform changes.

## Phase 1 results so far (2026-09-26)

Steps 1, 2, 3, 5 (first pass) and 6 are done; step 4 (owner review) is not.
Question: "Is this post an advertisement or sponsored/promotional content?"
Scored on the holdout slice (481 posts, 148 ads by the 35B) so every row is
comparable; "@0.5" is the plain cutoff, "balanced" is the cutoff that maximises
the mean of the two per-class rates.

| Model | Ads @0.5 | Non-ads @0.5 | Balanced (ads / non-ads) | CPU per post | RAM |
|---|---|---|---|---|---|
| `decider` 1.9B, untrained (Ollama paused to fit) | 55% | 97% | 88% / 85% | 1.0 s | 17.5 GB |
| `laya:en` trained, top 6 layers, 3 epochs | 61% | 91% | 84% / 77% | — | ~4 GB (training) |
| `nli` 435M, untrained | 67% | 89% | 78% / 83% | 0.20 s | ~2.5 GB |
| `laya:en` untrained, two-option choice | 48% | 93% | 80% / 78% | 0.20 s | ~2.5 GB |
| `laya:en` untrained, noul | 47% | 93% | 85% / 71% | 0.19 s | ~2.5 GB |
| `gliclass` 439M, untrained | 78% | 63% | 71% / 74% | 0.15 s | ~2.5 GB |

On all 2,521 posts the untrained numbers match the holdout's within a few
points. Timing: `laya:en` did all 2,521 in 9 min (5 s cold load, 190 ms median,
390 ms p95).

What the numbers say:

- **No model clears the success bar.** The best untrained model at a cutoff
  that keeps false "Ad" badges near the 35B's rate still misses ~45% of its
  ads.
- **`decider` cannot run beside the 35B.** It needs 17.5 GB; the one attempt
  with the 35B loaded swapped the box to 15 GB and slowed the 35B to 17 tok/s.
  It only runs with Ollama stopped.
- **The first training pass underfits.** With 22 of 28 encoder layers frozen
  (to stay beside the 35B), training loss sat at ~0.44 from epoch 1 to 3 —
  better than the ~0.60 of guessing the base rate, far from what a 421M model
  should reach on 1,851 examples. Training gave the model usable confidence
  (its top three deciles agree with the 35B 92–98%) but moved accuracy only a
  few points. A full fine-tune needs the 35B unloaded for the run.
- **The labels are noisy, which caps every number above.** The confident
  disagreements include 35B calls that look wrong: a MacRumors news headline
  about a model launch marked "brand promoting software product", a post with
  no text marked an ad, "you DRY FAST each and every day while you sleep"
  marked not an ad. Agreement with the 35B cannot tell a better model from a
  worse one on these. Step 4 is now the deciding step, not an optional one.
  **Owner direction:** the owner labels the corpus by hand before any further
  model work. Under way on dev since 2026-09-26: the AI recipe is off, its
  verdicts are hidden (kept in `pfi_is_ad` for comparison), the AI's 114
  auto-blocks and all ad tallies are cleared, post retention is off so no
  labelled post is deleted, and the feed has an Ad button (a marked post
  leaves the feed) and "Reviewed down to here" (`?review=1` lists only
  unreviewed posts). The owner's verdict is `pfi_owner_is_ad` (NULL = not
  reviewed).
- **The two-option choice form barely differs from noul** (48% vs 47% of ads
  at 0.5) on this data; the laya #156 effect is not what holds Laya back here.

Files: `~/laya-eval/` on the Studio (scripts `eval_run.py`, `score.py`,
`finetune.py`; per-model `p_*.jsonl`; `disagree_*.jsonl`; the trained checkpoint
`ft_choice_u6_e3/`), with copies in the dev scratchpad.

## Phase 2 — integrate (sketch, gated)

Only what Phase 1 settles is designed here; the rest is deliberately left open.

- **Serving.** Ollaya under a launchd LaunchAgent on the Studio, bound to the
  tailnet with `OLLAYA_HOST` and `OLLAYA_API_KEY` set, called on `/api/decide`
  (or `/v1/systemone`, TypeSafe's format). Nothing is written for this. The
  open piece is getting a fine-tuned checkpoint into it: Ollaya loads only what
  it pulls from a registry, never a local weights file, so a model trained here
  goes through Ollaya's `convert/` step (ONNX export plus parity check) into a
  private registry on the Studio, which Ollaya supports
  (`http://127.0.0.1:<port>/library/<name>`). The fallback is a small wrapper
  around `laya.Agent("<checkpoint folder>")` in the venv, which loads a local
  checkpoint and runs on MPS (`laya serve` itself only serves Laya's built-in
  checkpoints by name).
- **Provider.** joinery_ai gains a second provider *kind* — a decision
  provider, not a chat provider — declared in the catalog with the tier vocab
  it already has (`AiModelRequirement`). A pipeline job that wants one says so
  in its `verdictDescriptor()`: a descriptor whose fields are all `bool` /
  enum / bounded number can be answered by a decision model; one with a free
  `string` cannot. `PipelineRunner` picks the path by the resolved provider's
  kind — decision providers get the descriptor as typed questions, chat
  providers get the JSON instruction as today.
- **The `reason` field (C1).** Becomes optional in the descriptor. When the
  provider cannot supply it, the runner fills it from the probability and the
  feed tooltip shows "Ad (93%)". If the owner wants the phrase kept, the
  escalation path supplies it: low-confidence posts go to the 35B, which
  answers with a reason as now.
- **Escalation.** A confidence floor on the recipe (`rcp_decision_floor`, say
  0.8); a post under it is re-judged by the recipe's chat model. The item log
  records which model answered, as `pfi_ad_model` does today.
- **Policy changes (C2).** A prompt edit no longer changes behaviour for this
  model. The honest answer is that a rule change means relabelling and
  retraining; the recipe page must say so next to the prompt when a decision
  model is selected, and the fine-tune script must be a repeatable, checked-in
  step rather than a one-off.

## Not in scope

- Jev itself, or any hosted decision API. Dev is local-only and the product
  direction is local AI; the persona feed's whole point is that the owner's
  feed does not leave their machines.
- Other recipes. Email triage/summaries generate prose and are out; a future
  security-scan or label-routing recipe may fit, but each gets its own
  measurement, not this one's.
- Replacing the 35B as the deployment default. This is a per-recipe model
  choice at most.

## Decisions for the owner

- **D1 — reason tooltip.** Keep the phrase (costs an escalation to the 35B on
  every ad, or all posts) or accept "Ad (93%)". Pros of the number: honest,
  free, and the confidence is itself useful. Pros of the phrase: the owner
  reads it today and it explains *which* rule fired. Recommendation: number,
  phrase only on escalated posts — decide after seeing Phase 1 disagreements.
- **D2 — ground truth.** Whether the owner will review the disagreement list
  (likely 100–300 posts). Without it we only know agreement with the 35B,
  which caps what Phase 1 can prove.

## References

- TypeSafe Jev: https://typesafe.ai/blog/introducing-system-one-models-and-jev
- Laya: https://pypi.org/project/laya/ ; author's write-up
  https://dev.to/nandakishor_m_6cc0adfde9f/i-built-non-autoregressive-decision-models-a-year-ago-then-a-frontier-lab-called-it-a-18me
- Other replicas surveyed and not chosen: Verdict/OpenJev (ModernBERT-base
  151M, 48% on TypeSafe's public benchmark vs Jev's 91%),
  `open-alternative-jev` (logit-reading harness over a chat model; needs
  vLLM/Transformers, not Ollama), `system-one-gemma` / `system-one-open`
  (Gemma 270M/E2B heads, unbenchmarked).
- Code: `plugins/persona_browser/pipeline_jobs/MarkAdvertisementsJob.php`,
  `plugins/joinery_ai/includes/PipelineRunner.php`,
  `plugins/joinery_ai/includes/llm/LlmProviderInterface.php`.
