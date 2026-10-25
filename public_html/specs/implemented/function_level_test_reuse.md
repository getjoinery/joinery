# Function-level test reuse

**Status:** built and verified 2026-10-25. Unit suites pass (changed_selection 93/93, gate_lock 61/61), and the full `db --changed` gate passed 815/815: 813 results were borrowed, 291 of them only because the suite never ran the changed function. Nothing is left to test live.

## What this does for us

The gate skips a suite when nothing it depends on has changed. "Depends on" means
every file the suite's process loaded, and the startup chain alone loads about a
hundred files into most suites. Edit one function in `SessionControl.php` and
about 750 suites rerun (15-25 minutes), although only a few dozen ever call that
function.

This spec narrows "depends on" from **files loaded** to **functions run**. When
a file changes only inside function bodies, a suite reruns only if it ran one of
the changed functions. Anything else about the file changing (constants,
properties, `$field_specifications`, top-level code, a new class, a method
added or removed or its signature changed) keeps today's
behavior: every suite that loaded the file reruns.

## Measured

Two experiments on dev, 2026-10-25, both run outside the committed code.

**What it would have saved.** All 718 PHP suites ran once with pcov line
coverage, which gave each suite's set of executed functions. A replay then took
the 33 runs of 200+ suites from the last 48 hours, each against the run before
it, and counted the suites each rule would rerun, timed with each suite's
duration from the last fully fresh run:

| Rule | Suites rerun | Suite time |
|---|---|---|
| File level (today, with the comment-only rule) | 13,200 | 477 min |
| Function level, as designed below | 8,838 | 340 min (**29% less**) |
| Function level, if adding a method were not a whole-file change | 7,686 | 302 min (37% less) |

Three of the 33 runs changed the test harness itself, which reruns everything
under any rule. Without them the designed rule saves 39% (352 → 215 min).
Single runs: 751 suites / 21 min → 35 suites / 3 s (a `SessionControl.php` body
edit); 755 → 40; 751 → 86; 439 → 58; 379 → 81.

What still reruns widely is correct to rerun: `$field_specifications` changes
(`users_class.php`, `managed_nodes_class.php`), new classes (`ErrorClasses.php`,
`SystemBase.php`), new methods (see §3 for why), and harness edits.

**What it costs.** pcov itself is too slow to leave on: while collecting, PHP
runs about 7× slower, even for files outside `pcov.directory` (a 3M-iteration
loop: 0.032 s → 0.235 s). Whole suites took ×1.85, which cancels the saving. The
function-call profiler xhprof records exactly what is needed (which functions
ran) and costs nothing on plain code and about 0.2 µs per call. Measured on real
suites, plain → with xhprof:

| Suite | Plain | xhprof |
|---|---|---|
| box_variants | 0.64-0.69 s | 0.72-0.75 s |
| logic_result | 0.62-0.67 s | 0.66-0.70 s |
| sealed_secrets | 2.04-2.12 s | 2.12-2.22 s |
| models_test | 47.9-51.1 s | 49.9-50.7 s |

So about 5-10% on a small suite and nothing measurable on a long one. Net of
that, function level stays about 25% below file level in suite time, and about
a third below it once runs that changed the harness are left out.

**xhprof sees what pcov sees.** For 13 suites across core, plugins, the API,
mail and backups, every function pcov saw executed was in xhprof's list (after
the name rules under *Matching names*). The one apparent miss was four methods
in a file another session edited between the two runs, so the line mapping
was stale.

## Design

### 1. Collecting: the harness records the functions a suite ran

`tests/lib/harness.php` already reports the files a suite loaded:
`harness_loaded_repo_files()` (harness.php:1455) feeds `'files'` in the result
contract (harness.php:1440).

- The runner starts the trace, not the harness. 2 of 739 PHP suites call a
  platform function before loading the harness
  (`plugins/server_manager/tests/republish_from_manifest_test.php:29-30` calls
  `PathHelper::getIncludePath()` to find it), so a trace started inside the
  harness could miss what ran first. `tests/lib/function_trace.php` calls
  `xhprof_enable(XHPROF_FLAGS_NO_BUILTINS)` and defines
  `JOINERY_FUNCTION_TRACE`. `run_one_start()` prepends it with
  `-d auto_prepend_file` (`function_trace_flags()`) when the runner itself
  has xhprof loaded and the tier is not `deploy`. A `-d` flag is not
  inherited by the suite's own child processes.
- In the contract, beside `'files'`: `'functions' => harness_functions_run()`,
  which calls `xhprof_disable()` and returns the distinct callee names (each
  key of the xhprof result is `caller==>callee`, or a lone root such as
  `main()`). Include markers (`run_init::/path`) are dropped. The key is absent
  when `JOINERY_FUNCTION_TRACE` is not defined, and the runner then treats the
  suite as file level only.
- `tests/lib/function_trace.php` joins the harness list: changing it reruns
  everything.
- The contract comment at harness.php:23-25 gains `files` and `functions`.

A result whose process was killed or timed out has no contract and so no
functions. `gate_reach_complete()` (gate.php:380) already refuses such results
outright.

### 2. Recording: stored once per distinct set

A suite runs 91-550 functions (13-suite sample), around 10 KB of names.
Inlined on every result line, a full run's record would grow by about 8 MB. Sets repeat heavily between runs, so they are stored by content:

- `run_one_finish_raw()` (run.php:911) stores the contract's `functions` with
  `coverage_fnset_put()`: normalized keys (see *Matching names*), sorted, in
  `{site root}/cache/test_fnsets/<sha1>.json`, written once (temp + rename)
  and touched when seen again. The result carries `'functions_set' => <sha1>`
  and no list, so the test-db lane's results arrive the same way. The sets
  sit beside the coverage map, not in the gate dir: `JOINERY_GATE_DIR`
  relocates the gate dir for nested runners, and the map is never relocated.
- `coverage_map_update()` (coverage.php:44) stores `functions_set` with
  `files`. A result that reports files but no set clears the entry's set, since
  a set describes the files beside it.
- `coverage_fnsets_prune()`, run after the map update, removes sets older than
  `GATE_RECORD_TTL_S` that the coverage map does not name.
- A set that cannot be read (pruned, unwritable) means "file level for this
  result", never "skip".

### 3. Deciding: what a changed PHP file means for a suite

A new pure helper, `coverage_function_change($old_src, $new_src)`, splits both
versions into regions with the tokenizer and returns one of:

- `whole`: the file was added or removed, does not parse, or changed outside
  function bodies. "Outside" is everything except the bodies of named
  functions and methods: top-level code, class headers, constants, properties,
  closures and anonymous classes at file scope, and every global function's
  signature, so adding, removing or renaming a global function is `whole`
  (it changes what `function_exists()` answers).
- `functions`: the functions and methods whose body changed, the rest of the
  file being the same token for token.

Three method changes are `whole` although they look local:
- **A changed signature** (modifiers, parameters, defaults, return type). A
  subclass whose override no longer matches fails when PHP loads it, which
  hits suites that load the class without ever calling the method. Costs
  nothing measurable in the replay.
- **An added method.** Core code probes for optional hook methods by name:
  `method_exists($profile, 'reverseEligibilityWhere')`
  (`includes/cloud_storage/CloudStorageLifecycle.php:284`),
  `method_exists($profile, 'backupRow')` (`includes/BackupObjectRestore.php:300`),
  `method_exists($class, 'seo_public_records')` (`data/seo_page_metadata_class.php:478`),
  and by names read from data (`method_exists($this, $method)`,
  `includes/FormWriterV2Base.php:5745`; `includes/SignalBus.php:100`). Adding
  such a method changes what a suite does without the suite ever having run a
  method of that name, and a name built at runtime cannot be checked. Treating
  an addition as local would save 8 more points (the third row of the table
  above) at the price of a gate that can reuse a pass it should not have. Not
  taken.
- **A removed method**, for the same probes in reverse: a probe that finds
  nothing takes another path without running anything of that name. Costs
  nothing measurable in the replay.

Comments and whitespace never count (the same token filter as
`coverage_php_code_hash()`, coverage.php:107). Region keys are `Class::method`
using the short class name.

`coverage_select()` (coverage.php:215) takes the function-level answer per
changed file. Where it now selects through `isset($reach[$c])` (coverage.php:252),
it selects through a loaded file only when one of these holds:

1. the change is `whole`;
2. the suite has no function set (no xhprof, or the set is unreadable);
3. the suite ran one of the changed functions (by the name rules below).

The reason string names the function: `runs SessionControl::check_permission`.
Every other selection rule (the suite's own file, fixtures, `covers:`,
`dev-web`, the harness list, comment-only edits) is untouched.

Both callers use it:
- **Reuse.** `gate_changed_between()` (gate.php:244) already reads both blob ids
  of every changed file from `git diff-tree -r -z`. It computes the function
  change for each changed PHP file whose code differs (blobs through
  `coverage_git_objects()`, results cached per blob pair, since a reuse plan
  diffs many records sharing most blobs) and hands it, with the result's
  function set, to `gate_reuse_refusal()` (gate.php:398).
- **`--changed`.** The working tree against `HEAD` (and against the ref when
  one is given), in the way `coverage_changed_code_unchanged()`
  (coverage.php:173) already pairs them, using the coverage map's
  `functions_set`.

### Matching names

xhprof reports a method as `Namespace\DeclaringClass::method`, and a global
function by its name.
- Strip the namespace on both sides before comparing. A short-name collision
  only widens a run.
- A method defined in a **trait** is reported under the class that uses the
  trait, not the trait. For a changed method in a trait file, match any
  class's method of that name.
- Methods of an anonymous class at file scope: match by method name likewise
  (an anonymous class inside a function body belongs to that function's body).
- Closures (`{closure}`) are never matched directly. A closure inside a method
  belongs to that method's body, and the method ran when the closure was
  created.

### 4. Where the code lives

- `tests/lib/coverage.php`: the region splitter (`coverage_php_regions()`),
  `coverage_function_change()` / `coverage_regions_change()`, name matching
  (`coverage_function_key()`, `coverage_ran_any()`), the set store
  (`coverage_fnset_put/get`, `coverage_fnsets_prune()`,
  `coverage_map_with_functions()`), and `coverage_changed_function_changes()`
  for `--changed`. It is on the harness list (coverage.php:225-227).
- `tests/lib/function_trace.php`: new, §1.
- `tests/lib/harness.php`: `harness_functions_run()` and the contract key.
- `tests/lib/gate.php`: `gate_blob_changes()` (comment-only and function-level
  answers per blob pair, regions cached per blob id), and the set lookup in
  `gate_reuse_plan()`.
- `tests/run.php`: `function_trace_flags()`, the set in `run_one_finish_raw()`,
  the `--changed` call and the prune.

### 5. Installing xhprof

Dev only. Production nodes run only the `deploy` tier, which is never reused.
- `sudo apt install php8.3-xhprof` (deb.sury.org, the source of the installed
  PHP). Loaded but not enabled, it costs nothing, so it may stay enabled for
  FPM too. Nothing turns it on outside the harness.
- Without it the harness emits no `functions` and everything is file level, as
  today. No script installs the dev box's PHP extensions (APCu, which the
  runner turns on with `-d apc.enable_cli=1` in `run_one_start()`, is
  installed by hand too), so the install note goes in `docs/testing.md` beside
  the runner.

### 6. What it still does not see (unchanged from file level)

- Code a suite runs in a **subprocess** (a CLI script it spawns, Apache for
  `dev-web` suites). These rely on `covers:` and the `dev-web` rule today and
  keep doing so.
- A suite that reads a source file as **text** without declaring it in
  `covers:`.
- Behavior that depends on data rather than code (the same database, the same
  settings): the standing assumption of reuse.

The pre-publish gate is `db --fresh`, which borrows nothing, so none of these
can reach a release.

## Tests

- `tests/unit/changed_selection_test.php`:
  - the splitter: body-only change → `functions`; constant, property,
    `$field_specifications`, top-level, new class, new global function, added
    or removed method, or signature change → `whole`; traits; enums;
    namespaced classes; comments ignored.
  - `coverage_select()` with function sets: a body change to an unrun method
    does not select; a run one does; a global function's body change selects
    the suites that ran it; a suite without a set falls back to its files.
- `tests/unit/gate_lock_test.php`:
  - fnset write, dedup, prune and unreadable fallback;
  - reuse across two built trees differing by one method body (the probe-tree
    pattern at the end of *The tree id* section).
- A check that a suite run with the trace prepended emits `functions`
  containing a function it called, and one run without it emits none.

## Docs

`docs/testing.md`: *Finer reach: the functions a suite ran* (the trace, the
sets, the install note), the function rule under *Selection rules*, and
*Run records and reuse*.

## Open questions

None. The measurement settles whether this is worth doing. Where safety and
speed pulled apart (added methods) the spec takes safety, and says what it
costs.
