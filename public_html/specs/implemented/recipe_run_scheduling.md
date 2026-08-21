# Recipe Run Scheduling: One Control, Honest Promises

**Status:** Implemented 2026-08-21
**Date:** 2026-08-21
**Area:** joinery_ai plugin — recipe edit page, dispatcher, in-window drain, AI panel wording

## 1. Problem

The recipe edit page presents scheduling as two controls: a Schedule Frequency
dropdown (`none` / `hourly` / `daily` / `weekly`) and a "Run automatically"
checkbox whose help text describes both trigger paths in one sentence. The user
is shown the *mechanism* and left to work out which parts apply to their recipe.
For recipes that read sealed mail, the mechanism and the controls disagree:

1. **The clock is inert for a fully sealed binding.** The dispatcher never
   schedules a recipe whose whole binding needs a vault window
   (`RecipeDispatcher::scheduleDueRecipes` skips `!cronRunnable`), so every
   frequency choice on such a recipe is dead weight — including "Hourly", which
   reads as a promise the system cannot keep.
2. **"No Schedule" does not mean no automatic runs.** The in-window drain
   (`RecipeVaultScope::pendingForOwner`) checks only enabled + needs-window +
   has-work. A sealed recipe with frequency `none` still auto-runs every time
   the owner's vault window is open. The only control that actually stops
   automatic runs is the checkbox.
3. **The checkbox help text reads as nonsense case by case.** "On the schedule
   above, or while your vault is unlocked for a job that reads encrypted mail"
   is accurate only as a union across recipe types; for any single recipe, half
   the sentence is false.
4. **Run Now on a fully sealed recipe strands the run.** `run_now.php` inserts
   a pending row; the spawner refuses it (`isSpawnable` → `cronRunnable`
   false), the drain skips the recipe because the pending row counts as an
   active run (`RecipeVaultScope::drain` → `hasActiveRun` → continue), and the
   pending reaper deliberately leaves in-window rows alone. The row sits
   pending forever.
5. **Missed fire points are skipped, not caught up.** `isDue` for daily/weekly
   requires "past the scheduled time *today*" — a fire point missed because
   nothing was around to act on it (server down for cron; no open window for a
   sealed recipe, where missing the moment is the *normal case*) is silently
   dropped until the next calendar occurrence.

## 2. Design

**The schedule control owns time; the job owns events.** The user states an
intent — when should this run by itself — with one control. A computed line of
text underneath states how that intent will be honored for *this* recipe's
binding. Event-driven scheduling ("as mail arrives") exists only where a
pipeline job defines it, worded in the job's own vocabulary.

### 2.1 The one control

One dropdown, labelled **Runs**, replacing both the Schedule Frequency dropdown
and the "Run automatically" checkbox:

```
Runs
[ Manually only ▾ ]
   Manually only
   As mail arrives          ← present only when the selected job offers it,
   Hourly                     using the job's own label
   Daily at [time]
   Weekly on [day] at [time]
```

The day-of-week and time-of-day subfields keep their current show/hide
`visibility_rules` behavior, now keyed to the new option values.

Every choice means the same thing on every binding type:

- **Manually only** — no automatic runs of any kind; Run Now is the only
  trigger. Selecting it also cancels queued runs and stops one in progress
  (the existing disable behavior, inherited unchanged — see § 2.3).
- **The arrival option** — due whenever the job reports unhandled items.
- **Hourly / Daily / Weekly** — "at most this often": due when no run has
  started since the most recent scheduled fire point (§ 2.5).

*Where* a due run executes is a property of the binding, not a choice: a cron
worker takes the part readable without a vault window; the sealed part runs at
the owner's first open window after the run comes due. The user never picks a
mechanism.

### 2.2 The fact line

Directly under the control, a computed sentence about this recipe's binding —
never generic help text about options. Three variants, classified server-side
via `RecipeVaultScope::requiresWindow` and the job's `hasUnsealedBinding`:

- Needs no window (standard mail, non-mail jobs, agent mode):
  > The server runs this by itself.
- Fully sealed:
  > This recipe reads mail only you can unlock, so the server can't run it
  > alone. A due run starts the next time you're signed in with your vault
  > unlocked; anything that arrives in between waits for you.
- Mixed:
  > Some of what this recipe reads is encrypted. The encrypted part runs while
  > you're signed in with your vault unlocked; the rest runs on the server.

Rendered from the saved recipe (computed in `admin_edit_logic`, passed to the
view). A binding edited but not yet saved shows the previous classification
until save — acceptable because save already revalidates and re-renders.

### 2.3 Storage: `rcp_enabled` stays, as the manual/auto bit

`rcp_enabled` is load-bearing far beyond the edit page: the dispatcher and
drain filter on it, the pending reaper cancels rows for disabled recipes, the
save path kill-switches in-flight runs when it goes false, the AI panel treats
false as "paused" and refuses toggles, and seeded recipes deliberately arrive
with it off ("must never arrive switched on" — RecipeSeeder). All of that
keeps working unchanged. Only the presentation changes:

- **Manually only ⇔ `rcp_enabled = false`.** Choosing it on save sets enabled
  false (and the existing disable path cancels queued/in-flight runs). Choosing
  any other option sets enabled true and writes `rcp_schedule_frequency`.
- The frequency value `none` retires: the save path never writes it again, and
  the UI shows **Manually only** whenever `rcp_enabled` is false *or* the
  stored frequency is `none`/empty. No data migration (pre-launch; and
  `isDue('none') === false` already makes legacy rows inert as a belt).
- `rcp_schedule_frequency` gains the value `arrival`.
- The "paused but remembers its schedule" UI state is dropped deliberately
  (owner decision, this design conversation): un-pausing is re-picking one
  dropdown value.

New-recipe defaults change from enabled+weekly to **Manually only**, keeping
the current Monday-07:00-local prefills on the day/time fields so picking Daily
or Weekly lands on sensible values. Running a brand-new recipe automatically
becomes a deliberate choice, matching the seeder's philosophy.

### 2.4 The job owns events

`PipelineJobInterface` gains one method:

```php
/**
 * Label for this job's arrival-driven schedule option, in the job's own
 * vocabulary ('As mail arrives'), or null when its items have no arrival
 * concept. When non-null, the recipe may use schedule frequency 'arrival':
 * due whenever hasWork() answers true for the asking scheduler's posture.
 */
public function arrivalLabel(): ?string;
```

- `EmailPipelineJobBase` (covers EmailTriageJob, EmailScheduleJob,
  EmailSecurityScanJob) returns `'As mail arrives'`.
- `MarkAdvertisementsJob` (persona_browser) returns `'As posts arrive'`.
- Agent-mode recipes have no job, so the option never appears for them.

The edit page builds the dropdown from the selected job, and rebuilds it when
the job selection changes (the same client-side pattern that already swaps the
job's config field block; each job's arrival label is emitted as data the
script reads). Save-time validation refuses `arrival` when the selected job
does not offer it, with a message telling the admin to pick a clock schedule —
so a job switch cannot strand a meaningless stored value.

### 2.5 Due: one shared answer, catch-up semantics

New class `plugins/joinery_ai/includes/RecipeSchedule.php`, replacing the
dispatcher's private `isDue`, used by both schedulers so they cannot drift:

```php
RecipeSchedule::isClockDue(Recipe $recipe, string $now_utc): bool
```

Clock frequencies are defined by their **most recent fire point**: hourly = the
top of the current UTC hour; daily = today's scheduled time, or yesterday's if
today's has not arrived yet; weekly = the most recent target-day-at-time at or
before now. Due ⇔ no run of this recipe started at or after that point
(`rcr_started_time`, any trigger — a manual run satisfies the schedule).

This is "at most this often" with catch-up: a fire point that passed unmet
stays claimable until a run happens, rather than expiring at midnight or at
week's end. For sealed recipes that is the difference between "Weekly" meaning
*the next time you're here after Monday 07:00* and meaning *only if you happen
to be here on Monday*. For cron it also fixes the server-down-over-the-fire-
point skip. Hourly behavior is identical to today. Fire points are computed in
UTC from the stored UTC time — the same ±1h DST drift as today, unchanged.

`arrival` is due whenever the job's `hasWork()` answers true for the asking
scheduler's posture. `hasWork` is already contract-bound to stay cheap (an
indexed EXISTS), so the dispatcher may ask it once per tick per arrival
recipe.

**Dispatcher** (`scheduleDueRecipes`): same skeleton — skip `!cronRunnable`,
skip recipes with an active run — with dueness answered by
`RecipeSchedule::isClockDue`, or for `arrival` by
`hasWork($config, $recipe, POSTURE_STANDARD)`.

**In-window drain** (`RecipeVaultScope::pendingForOwner`): gains the due gate
it currently lacks. A window-requiring recipe is pending when enabled AND:

- frequency `arrival`: `hasWork(..., POSTURE_SEALED)` — exactly today's
  behavior, now opted into rather than imposed;
- clock frequency: `RecipeSchedule::isClockDue` — mirroring cron, the run
  fires when due even if it finds nothing and reports itself caught up; the
  fire-point comparison then suppresses it until the next period, so an empty
  run costs at most one row per period.

Since `pendingForOwner` is also the heartbeat's work predicate, the due gate
automatically stops the heartbeat requesting drains for sealed recipes that
are not due — quieter, not just more correct.

### 2.6 Adopting a stranded manual run

`RecipeVaultScope::drain` currently skips a recipe with an active run. For a
fully sealed recipe that logic orphans Run Now rows (Problem 4): the pending
manual row is exactly what the drain should execute. New rule: when a pending
recipe has an existing **pending** run and the recipe is `!cronRunnable`, the
drain claims and runs *that* row instead of creating a new one. A **running**
row still skips (mid-run elsewhere). Mixed-binding pending rows stay the
workers' to claim, as today — the drain adopts only what no worker will ever
take. A pending manual row also makes the recipe pending regardless of the due
gate (the human pressed the button; dueness is about *automatic* runs).

The run status page (`views/admin/run.php`) additionally explains a pending
row on a `!cronRunnable` recipe: "Waiting for you — this recipe reads mail
only you can unlock, so the run starts while you're signed in with your vault
unlocked." Today that state displays as a queue position that will never
advance.

### 2.7 AI panel wording

`AiPanelService` surfaces `rcp_enabled = false` as "Paused from the recipes
dashboard." (toggle refusal + card `blocked_text`). Under the new model the
state is named by what the user chose: "Set to run manually only — give it a
schedule on the recipes dashboard." Behavior (panel never writes the bit;
toggle refused while manual) is unchanged.

### 2.8 Seeder

- Shipped declarations keep their `schedule_frequency` / day / time fields as
  the prefill for when the admin picks a clock option; recipes still arrive
  `rcp_enabled = false` (displayed as Manually only).
- `instantiateForUser` (the panel's toggle-ON path) currently enables the
  recipe; it now also sets frequency `arrival` — a panel-born mail recipe's
  whole point is "handle my mail as it comes."
- Declarations may name `arrival` as their `schedule_frequency`; export
  (`declarationFromRecipe`) passes it through like any other value.

## 3. Touched surfaces

| File | Change |
|---|---|
| `plugins/joinery_ai/includes/PipelineJobInterface.php` | `arrivalLabel()` |
| `plugins/joinery_ai/includes/EmailPipelineJobBase.php` | `'As mail arrives'` |
| `plugins/persona_browser/pipeline_jobs/MarkAdvertisementsJob.php` | `'As posts arrive'` |
| `plugins/joinery_ai/includes/RecipeSchedule.php` | **new** — shared fire-point dueness |
| `plugins/joinery_ai/tasks/RecipeDispatcher.php` | use RecipeSchedule; `arrival` branch; drop private `isDue` |
| `plugins/joinery_ai/includes/RecipeVaultScope.php` | due gate in `pendingForOwner`; adoption in `drain` |
| `plugins/joinery_ai/includes/RecipeSeeder.php` | `arrival` on panel instantiation; declaration passthrough |
| `plugins/joinery_ai/logic/admin_edit_logic.php` | single-control save mapping; `arrival` validation; fact-line vars; Manually-only default for new recipes |
| `plugins/joinery_ai/views/admin/edit.php` | one **Runs** dropdown + fact line; checkbox and old help text removed |
| `plugins/joinery_ai/views/admin/run.php` | waiting-for-window explanation for stranded-state pending rows |
| `plugins/joinery_ai/includes/AiPanelService.php` | paused wording |
| `plugins/joinery_ai/docs/overview.md` | scheduling section rewritten current-state |

Version numbers bump on every touched class/view per house rules.

## 4. Tests

- **New, safe:** `plugins/joinery_ai/tests/recipe_schedule_test.php` —
  fire-point math for hourly/daily/weekly including catch-up (missed
  yesterday's 07:00 → due now), suppression after a run, `arrival` refusal
  when the job offers no label, Manually-only mapping (`rcp_enabled` false ⇔
  option shown, legacy `none` rows display as Manually only).
- **Extend `in_window_email_test.php` (db):** sealed recipe with a clock
  frequency drains only when due; `arrival` sealed recipe drains on work as
  today; pending manual row on a fully sealed recipe is adopted and completes
  (regression for Problem 4); heartbeat work predicate goes quiet when
  nothing is due.
- **Panel/seeder suites:** update any assertion pinning the old "Paused from
  the recipes dashboard." wording or the enabled+weekly new-recipe default.

## 5. Out of scope

- Per-address schedule facts on mixed bindings (naming which address runs
  where). The three-variant fact line ships first; a job-provided detail line
  can follow if mixed bindings become common.
- Any change to run execution, budgets, consent/egress gating, or the taint
  gate. This spec moves *when runs start* and *what the user is told*, nothing
  about what runs do.
- Live re-rendering of the fact line as an unsaved binding is edited.
