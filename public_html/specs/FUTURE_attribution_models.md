# FUTURE: Multi-Touch Attribution Models (speculative)

> **⚠ Speculative.** This is a research/design placeholder, **not** an actionable spec. Nothing here should be built until the core reporting in [`scrolldaddy_marketing_infrastructure.md`](scrolldaddy_marketing_infrastructure.md) Part E has been running in production for long enough to show whether multi-touch journeys are common enough to justify the model. If they aren't, this whole doc is moot — delete it and move on.

## The question this would answer

A visitor rarely converts on first touch. Realistic journey:

> User lands via **Reddit** Monday (`utm_source=reddit`) → returns direct Tuesday → clicks an **email** Wednesday (`utm_source=email`) → clicks a **Twitter** link Thursday (`utm_source=twitter`) and buys.

Part E's reporting implicitly treats this as a **last-touch** attribution — the PURCHASE event carries whatever UTM was in session at conversion time (Twitter here). That's the simplest and most common default, but it systematically under-credits channels that introduce users and over-credits channels that close.

Multi-touch attribution distributes credit across the full journey instead of stamping it all on the last touch.

## Models

| Model | Credit allocation | Bias |
|---|---|---|
| **First-touch** | 100% to the first UTM'd event | Favors awareness channels (Reddit here) |
| **Last-touch** (what Part E does) | 100% to the last UTM'd event before conversion | Favors closing channels (Twitter here) |
| **Linear** | Equal split across all UTM'd touches | Treats intro and close as equivalent |
| **Time-decay** | Exponential weight toward recent touches | Plausible default, tunable half-life |
| **Position-based (U-shaped)** | 40% first + 40% last + 20% split among middle | Heuristic that rewards intro and close |
| **Data-driven (Shapley / Markov)** | Credit derived from actual conversion lift per channel | Only meaningful with real volume; not worth considering until >10k conversions |

**Nobody has the "right" answer.** The model you pick changes which channels look good. Budget decisions swing on that choice. Reasonable businesses pick different models for different reasons.

## What would it cost to build

Schema prerequisite already present after Part D/E: `vse_visitor_events` has every touch plus each visitor's `vse_visitor_id`, and conversion rows carry `vse_ref_type`/`vse_ref_id`. No new columns needed.

New work:

1. **Attribution service** — helper that, given a conversion event row, walks back through `vse_visitor_events` for that `vse_visitor_id` (or matched visitor chain if cookies rotate), collects distinct UTM'd touches in order, and returns an array of `(source, campaign, credit_fraction)` tuples per the chosen model. ~200 lines.
2. **Setting: default model** — single admin setting that picks one of the models above. Applied system-wide.
3. **Reporting UI toggle** — Part E's attribution page gets a model dropdown. Queries re-run with credit fractions instead of count(*).
4. **Query shape changes** — "revenue by channel" stops being `GROUP BY source, SUM(total)` and becomes `SUM(total * credit_fraction) GROUP BY source` with per-conversion credit rows. Typically done by materializing an `attribution_credits` view that the reports query, so the expensive walk runs once per conversion rather than per report load.
5. **Cross-session visitor stitching (optional, open question)** — if we identify users across devices (by login), should attribution use the full user history rather than the single-visitor cookie chain? Design call.

Rough total: ~1–2 weeks of focused work if the prerequisites are solid and we skip data-driven.

## What has to happen first

**Do not build this until all three are true:**

1. Part E has been live long enough to show meaningful multi-touch data. Quick proxy: run `SELECT vse_visitor_id, COUNT(DISTINCT vse_source) FROM vse_visitor_events WHERE vse_source IS NOT NULL GROUP BY vse_visitor_id HAVING COUNT(DISTINCT vse_source) > 1` and see how many visitors actually have more than one source. If it's <5% of converters, last-touch is fine forever.
2. Someone has asked the question "but how much credit should channel X really get?" — if nobody's asking, nobody needs the answer.
3. The business is spending enough on marketing that a 20% channel-credit swing is worth caring about. At small scale, intuition beats modeling.

## Decision criteria when it's time

If you do build it, pick the simplest model that answers the question at hand. The progression is:

- Default to **last-touch** for reporting (what Part E already does). Cheap, simple, conservative.
- Add **first-touch** as a second column — it's a free lookup against the same data and reveals a lot.
- Graduate to **time-decay** only when last-touch vs first-touch disagreement becomes actionable and you find yourself handwaving between them.
- Don't bother with position-based or data-driven until you have a dedicated marketing analyst who's asking for it by name.

## Out of scope even within this speculative doc

- Incrementality testing (holdout groups, geo-splits) — different problem.
- Cross-domain / cross-device user stitching beyond `SessionControl`'s existing visitor cookie.
- Paid-ads platform integration for bid optimization.
