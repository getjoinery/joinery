# ScrollDaddy Block Editor: Overrides Redesign

## Overview

Replace the current 3-state (`— / Block / Allow`) per-category dropdowns on **scheduled blocks** with a 2-state baseline editor on the always-on block plus an **overrides list** UI on scheduled blocks. The user mental model becomes "what's normal" (always-on) vs "what's different during this window" (scheduled), instead of "abstain or act on every category."

### What this accomplishes

1. **Eliminates the abstain-vs-act distinction from the user-facing UI.** Today, every scheduled block confronts the user with a dropdown for every category — most of which they leave at `—`. The `—` state is hard to explain ("this block has no opinion"); even a careful description leaks the underlying merge model. After: scheduled blocks show only the rows the user chose to override.
2. **Always-on becomes a clear baseline.** Two states (Block / Allow) per category, just like a normal preferences screen. No three-state ambiguity.
3. **Scheduled blocks read like exception lists.** "During Work Mode, also block social media and allow ads." Empty-by-default; users add overrides only when they want a deviation.
4. **No data migration.** The block-level filter/service tables are already override lists internally — a missing row means "no opinion." The current 3-state UI is just a thin wrapper that creates/deletes rows. The new UI manipulates the same rows; the resolver merge logic is unchanged.

### Why now

The 3-state UI is the single biggest source of confusion in the editor today. We've already shipped the unified block model (one editor for both always-on and scheduled), so the foundation is in place — this is the next refinement.

## Current State

`views/profile/scheduled_block_edit.php` renders the same widget for both always-on and scheduled blocks: a 3-state dropdown for each filter and service. The form posts `rule_<key>` values of `''`, `'0'`, or `'1'`. `SdScheduledBlock::update_filters()` (in `data/scheduled_blocks_class.php:57`) and `update_services()` (`:110`) translate this back to row-level operations:

- `''` → delete the existing `sbf_*` / `sbs_*` row, if any.
- `'0'` → upsert with `sbf_action = 0` (Block).
- `'1'` → upsert with `sbf_action = 1` (Allow).

The DNS resolver merges all currently-active blocks' rows: Allow > Block > category default. Missing rows contribute nothing.

**Implication:** the data layer already represents "no opinion" as the absence of a row. The 3-state UI is the only place the abstain state is materialized; it is purely cosmetic.

## Proposed Design

### Always-on block — 2-state editor

When `sdb_is_always_on = true`, render every category as a **two-button segmented radio** (`Block | Allow`). Both positions are visible at once, the user picks one, and there is no "off" state to misinterpret.

Rejected alternative: toggle switch. A toggle reads as "feature on/off," but Allow and Block are both active positions, so "off" would be the wrong mental model.

**Critical on-disk semantics.** "Allow" on the always-on editor means **no row**, not an explicit `action=1` row. The resolver merge (`resolver.go:183-239`) unions `AllowKeys` from every active block and uses them to delete categories from the effective block set — which means an explicit Allow row on always-on would erase any `Block` override on a scheduled block, silently breaking the override semantics. The resolver's default (no row → not blocked) already gives us free Allow on the baseline, so:

- "Block" on always-on → write/keep `action=0` row.
- "Allow" on always-on → delete row if it exists; do not write `action=1`.

This matches how the legacy always-on checkbox UI worked (checkbox on = Block row, off = no row); the segmented radio is a clearer visual over the same on-disk shape. Scheduled blocks retain explicit Allow rows for their override behavior — that's where Allow rows are semantically meaningful.

**Data behavior:** every category written as a row (action 0 or 1), no missing rows. This is a small semantic tightening from today (where always-on can technically have `—` rows) but matches how users will think about it.

### Scheduled block — overrides list

When `sdb_is_always_on = false`, replace the per-category dropdowns with:

1. An **"Overrides"** section header with help text: *"During this block's schedule, these settings differ from your always-on rules."*
2. A **list of current overrides**, one row each: `<Category name>  <Block | Allow>  <Remove>`. Empty list shows a muted "No overrides — this schedule uses your always-on rules" placeholder.
3. An **"Add override"** affordance: an inline row at the bottom of the list with two `<select>` controls and an "Add" button — a category dropdown (using `<optgroup>` to preserve Social / Messaging / Gambling / Adult / Ads / Malware / etc. grouping, listing only categories *not yet overridden*) and a Block/Allow action dropdown. Mirrors the existing custom-domain-rules add-row pattern at the bottom of the same editor, so the visual rhythm is consistent.
4. Each existing row's action button toggles between Block and Allow inline; the Remove button deletes the override.

**Tier gating** applies to the picker, not the saved rows: advanced filters (ads, malware, fakenews, typo) only appear in the **Add override** picker for users who have `scrolldaddy_advanced_filters`. Existing overrides on advanced filters owned by a downgraded user are **visible and removable, but not editable**: the action button (Block/Allow toggle) is disabled with a small "Upgrade to edit" hint, while the Remove button stays active. This lets users escape an unwanted advanced override without paying — fair on a free tier whose principle is supporting recovery use cases — while still gating creation and modification behind the paid tier.

### Custom domain rules

No change. The custom domain rules section already follows the override-list pattern (it's a literal list with inline add/delete). Keep it as-is at the bottom of the editor.

### Schedule controls

No change. Scheduled blocks still have time/day controls in their dedicated section above the overrides.

## Implementation Sketch

### View layer

`views/profile/scheduled_block_edit.php` splits into two render branches at the existing `$is_always_on` check (already at `:25`):

- **Always-on branch** — renders the binary widget per category. Reuses the existing category groupings (`$social_services`, `$msg_services`, etc.).
- **Scheduled branch** — renders the overrides list and the "Add override" affordance. The category pool comes from the same `ScrollDaddyHelper::$filters` and `$services` arrays that drive the always-on editor, so there's a single source of truth for "what categories exist."

Both branches submit to the same `scheduled_block_edit_logic.php` action handler.

### Logic layer

`scheduled_block_edit_logic.php` already calls `update_filters()` / `update_services()` with whatever `rule_*` keys are in `$_POST`. The scheduled-block form will simply post a different shape — instead of every category as a `rule_<key>` field, it posts only the keys that exist as overrides. The existing logic in `update_filters()` already handles "missing key = delete row," so no change is needed.

The always-on form keeps posting every category but only with `'0'` or `'1'` (no empty values). `update_filters()` already handles those fine.

### AJAX endpoints (optional, for nicer UX)

If we want the overrides list to feel snappy (add/remove without a full form save), we mirror the existing `block_rule_add` / `block_rule_delete` AJAX pair for filter/service overrides:

- `ajax/block_filter_override_add.php` — POST `block_id`, `filter_key`, `action`. Creates the `sbf_*` row.
- `ajax/block_filter_override_delete.php` — POST `block_id`, `filter_key`. Deletes the `sbf_*` row.
- Same pair for services (`block_service_override_*`).

Without AJAX, the overrides list submits with the main form, which is also fine and matches today's flow. Recommend starting with form-submit and adding AJAX in a follow-up if it feels clunky.

### Data model

**No changes.** `sbf_scheduled_block_filters`, `sbs_scheduled_block_services`, `sbr_scheduled_block_rules` already model overrides as "row exists with action 0 or 1." The resolver merge already treats missing rows as "no opinion."

### Migration

**None required.** Existing scheduled blocks with `—` rows already have no row; existing Block/Allow rows already have `sbf_action` set. The new UI reads and writes the same rows.

The one minor tightening: today an always-on block could theoretically have a category with no row (i.e., the user picked `—` on the always-on editor and saved). Under the new design, the always-on editor never produces missing rows — every category has a position. We can ignore pre-existing missing rows on always-on blocks (they fall through to "no opinion → upstream default," same as today) and let them get filled in next time the user touches the editor. No data backfill needed.

## Planned Follow-ups

- **Effective-policy preview on scheduled blocks.** A read-only panel that shows the merged result of always-on + this scheduled block's overrides during the block's window — e.g., "Block: gambling (always-on), Facebook (override) … Allow: ads-light (override) …" This helps users diagnose surprises like "I overrode Facebook to Allow but it's still blocked" (because the social-media category is also blocking it). Out of scope for v1 to keep the initial build tight, but should follow shortly after — implement as a collapsed `<details>` panel below the overrides list. Don't let this slip indefinitely; revisit within the same release cycle as the v1 ship.

## Files Affected

- `plugins/scrolldaddy/views/profile/scheduled_block_edit.php` — major rewrite of the rules-rendering section, split by `$is_always_on`.
- `plugins/scrolldaddy/logic/scheduled_block_edit_logic.php` — no change required (existing `update_filters` / `update_services` handle the new POST shape).
- `plugins/scrolldaddy/data/scheduled_blocks_class.php` — no change required.
- `plugins/scrolldaddy/ajax/block_filter_override_*.php`, `block_service_override_*.php` — new files (only if we go AJAX in v1; otherwise defer).
- `docs/scrolldaddy_plugin.md` — update the "Editor UI" section to describe the always-on baseline + scheduled overrides model and remove the 3-state dropdown reference.

## Out of Scope

- Custom domain rules UI (already overrides-list-shaped).
- DNS resolver merge logic (unchanged).
- Tier feature key changes (unchanged).
- Always-on vs scheduled block lifecycle (unchanged — always-on is still auto-created per device, scheduled blocks still respect `scrolldaddy_max_scheduled_blocks`).
