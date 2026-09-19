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
ModernBERT-large plus a decision head, 421M parameters, runs on Mac MPS or CPU,
~35 ms per question, three question types — Choice, Score, Noul (yes/no
probability).

We want to know whether a model like this can take over ad judging, and at
what cost in accuracy. Measure first; build only if the numbers say so.

## What we already have

Dev holds the evaluation set: **1,911 judged posts, 498 marked ads**, every
label from the 35B (`qwen3.6:35b-a3b-nvfp4` 1,364, `-q4_K_M` 547), with the
reason phrase on each. The digest the model saw is reproducible from the row
(`author`, `pfi_message`, `pfi_image_alt` — see `MarkAdvertisementsJob::digest()`).

Two caveats on those labels:

- They are the 35B's opinion, not ground truth. Agreement with the 35B is the
  metric we can compute cheaply; the owner's own calls on the disagreements are
  the metric that matters.
- The "events are never advertisements" rule was added to the prompt on
  2026-09-12. Posts judged before that may carry an `is_ad = true` for a
  concert or workshop that current policy says is not an ad. The eval must
  separate pre- and post-rule rows.

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

Runs on the Mac Studio (`ssh macstudio`), in a Python venv under
`~/laya-eval/`, never touching the Ollama LaunchAgent or its memory budget
(421M params ≈ 1 GB; runs on MPS alongside the loaded 35B). Scripts and results
also copied to the dev scratchpad — `/tmp` on the Studio gets cleaned.

### Steps

1. **Export the eval set from dev.** One JSON line per judged post:
   `{id, author, message, image_alt, is_ad, reason, judged_time, model}`.
   Build the text exactly as `digest()` does (Author / Post / Image blocks) so
   both models see the same thing.
2. **Zero-shot.** Ask Laya one Noul question per post — "Is this post an
   advertisement or sponsored/promotional content?" — and record `p_ad`.
   Threshold 0.5 for the headline number; sweep the threshold too.
3. **Score against the 35B**, reported as:
   - agreement overall, and separately on the 498 ads and the 1,413 non-ads
     (a model that says "not ad" to everything scores 74% — the per-class
     numbers are the honest ones);
   - agreement on post-rule rows (judged on or after 2026-09-12) vs. pre-rule;
   - agreement on the event subset (35B reason starting `event:`) — the
     expected weak spot;
   - calibration: bucket `p_ad` into deciles and show the agreement rate per
     bucket. The escalation pattern in Phase 2 only works if low confidence
     actually predicts disagreement.
4. **Owner review of the disagreements.** List them with both verdicts, sorted
   by Laya's confidence. The owner marks who was right. This is the only
   ground truth we get, and it may show the 35B is the one that is wrong on
   some of them.
5. **Fine-tune, if zero-shot is not enough.** Train the decision head on ~1,500
   of our labels (post-rule rows weighted, or pre-rule event rows relabelled
   under current policy), hold out the rest, rerun step 3 on the holdout.
   Laya ships its training set and code; if its fine-tune path is not usable,
   the fallback is a plain ModernBERT-large classification head trained the
   same way — same cost, same speed, and it drops the Laya dependency.
6. **Timing.** Wall clock for the full 1,911 on the Studio, cold and warm, vs.
   the 35B's per-post time from the recipe run logs.

### Success bar

Phase 2 is worth building if, on the owner-reviewed disagreements plus the
holdout set, the decision model is at least as often right as the 35B on the
non-ad class (false "Ad" badges are the cost the owner sees — a real post
dimmed and tooltipped as an ad) and within a few points on the ad class, with
calibration good enough that routing the bottom ~10% of confidence to the 35B
recovers most of the remaining gap.

If zero-shot is poor and fine-tuning does not close it, stop; write the numbers
into this spec and leave the 35B in place. Nothing in the platform changes.

## Phase 2 — integrate (sketch, gated)

Only what Phase 1 settles is designed here; the rest is deliberately left open.

- **Serving.** A small HTTP service on the Studio under launchd, same pattern as
  the persona-browser service on the mini: bearer token, `POST /decide` taking
  `{state, questions}` in Laya's own schema, returning per-question
  probabilities. It loads the fine-tuned weights once and stays resident.
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
