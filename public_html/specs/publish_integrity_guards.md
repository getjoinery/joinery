# Publish Integrity Guards

**Status: BUILT 2026-07-24.** All four phases implemented; safe tier green.
Acceptance 1, 2, 4 and 6 are proven by test (`agent_release_channel`,
`agent_bundle_drift`, `publish_log`).

Live publishes 0.8.185, 0.8.186 and 0.8.187 each took the source-matches-bundle
path, reported `v0.4.0 (skipped)`, and wrote a log to `logs/publish/` — proving
acceptance 2 and the logging half of 5 on a real run, and confirming the refusal
does not fire spuriously.

Acceptance 3 is still open: no live publish has yet had agent source newer than
the bundle, so the build-and-ship path has only ever run under test. Move to
`implemented/` after a publish that actually rebuilds the agent.

## What this is

A publish can currently succeed while quietly shipping a stale artifact, and
leave no record of having done so. This spec closes that: a stage that was
supposed to rebuild something and could not stops the publish before anything
is written, every publish keeps a log of itself, and drift between a built
artifact and its source is caught by a test rather than by a failure in the
field.

Scope is the publish pipeline (`plugins/server_manager/includes/publish_upgrade.php`
and `AgentDistPublisher`). Nothing here changes what a release contains or how
nodes apply one.

## Why (the incident this comes from)

Release 0.8.184 shipped `server_manager-1.10.0` containing agent **0.3.1**
while the agent source sat at **0.4.0**. The agent bundling stage had failed;
because it reports through `publish_output` instead of throwing, the publish
read as fully successful.

The consequence was not local. Agent 0.4.0 is the first version that resolves
the `__SM_CREDS_<id>__` placeholder — the mechanism that keeps cloud
credentials out of job rows. A 0.3.1 agent passes the placeholder through to
the node as literal text, so **every** offsite backup in the fleet failed with:

```
UPLOAD_FAIL: Missing required credential field: access_key
```

The first symptom was a failed backup two days later. By then the reason was
unrecoverable: `publish_output` streams to the terminal and persists nothing,
so the message explaining why the build failed no longer existed anywhere. The
root cause of that particular build failure is permanently unknown, and that
is the defect this spec addresses — not the agent, and not backups.

## What already exists (build on it, don't duplicate)

- **`AgentDistPublisher::publish($full_site_dir, $out)`** — cross-compiles
  amd64+arm64, signs with `config/agent_signing_key`, swaps a staging dir into
  `plugins/server_manager/agent_dist/`. Already has the right internal
  decisions (rebuild only when source version ≠ manifest version; staging swap
  so a failure leaves the previous artifact intact). Its public helpers
  `sourcePath()`, `readSourceVersion()`, `readManifest()`, `artifactsPresent()`
  and `findGo()` are already exposed and already used for diagnosis.
- **The downgrade guard** (`publish_upgrade.php` ~163) — the established
  pattern for this file: check, print a refusal, `exit`, having written
  nothing.
- **`$publish_warnings`** (~405) — an accumulator already surfaced in a
  Warnings block at the end of a publish.
- **`publish_output()`** (~97) — the single output chokepoint for the whole
  pipeline. Every stage already goes through it, so persistence needs one
  change, not many.
- **`{site root}/logs/`** — world-writable, outside `public_html`, already
  holds cron and error logs.

What does NOT exist: any distinction between a benign carry-forward and a
failed rebuild, any persistence of publish output, and any check that a built
artifact matches its source.

## Decisions

### D1 — A failed rebuild is a publish failure; a missing source is not

The current catch-all exists for a good reason and must survive: a control
plane with no agent checkout has to be able to publish, carrying the existing
artifact forward. That case stays exactly as it is.

The case that changes is the one where the platform *knew* it had to rebuild:
the agent source is present, and its version differs from the bundled one (or
the bundled artifacts are missing). A failure there means the release would
ship an artifact the publisher already knows is wrong. That refuses the
publish.

Four outcomes, named explicitly:

| Situation | Outcome | Publish |
|---|---|---|
| Source absent or `main.go` unreadable | `carried` | continues |
| Source version unreadable | `carried` | continues |
| Source version == bundled, artifacts present | `skipped` | continues |
| Rebuild needed, succeeded | `built` | continues |
| Rebuild needed, failed for any reason | `failed` | **refuses** |

`AgentDistPublisher::publish()` gains a return value — an array carrying
`status` (one of the four above), `message`, `source_version` and
`bundled_version`. It keeps reporting through `$out` as it does now, and keeps
never throwing; the caller decides what a status means. This keeps the class
usable outside a publish (the standalone rebuild used during the incident) and
puts the refusal policy in the pipeline where the other guards live.

**Rejected:** making `publish()` throw. It is deliberately non-throwing so a
broken agent build cannot abort an unrelated platform publish, and that
property is still wanted for the carry-forward cases. Moving the decision to
the caller preserves it.

### D2 — The agent stage runs before anything is written

Today the stage sits at ~504, after the VERSION file has been rewritten, the
install SQL generated, the core archive built and the release row saved.
Refusing there would leave a half-built release behind.

It moves to a pre-flight position immediately after the downgrade guard and
before the VERSION write. On `failed` the publish prints the compiler error
and exits, having changed nothing.

This does not regress the reason it was placed where it was. The stage was put
before plugin archive creation so a fresh `agent_dist` is captured in the
`server_manager` archive and its tree hash; running it earlier still satisfies
that.

### D3 — Every publish writes a log

`publish_output()` accumulates each line into a buffer alongside its existing
output. A shutdown function registered at the top of the script writes the
buffer to `{site root}/logs/publish/publish-{version}-{YmdHis}.log`, so it
captures `exit`, `die` and fatal-error paths as well as a clean finish — the
paths that currently lose the most.

- Version is not known at the very start; the filename uses the version once
  set and `unknown` before that.
- The final line of a successful publish names the log path.
- Retention: keep the newest 20, delete older, on each publish. Small text
  files; no separate task.
- Not in `static_files` — that directory is web-served, and a publish log
  names paths and component versions.

### D4 — Drift is a test, not an inspection

A safe-tier test asserts the invariant directly: if the agent source is
present on this box, `agent_dist/manifest.json` version must equal `var
version` in the source `main.go`. Where the source is absent (any box that is
not the publishing control plane) the test reports the check as skipped and
passes.

This is the guard that would have caught the incident on any day in the two
days it went unnoticed, without anyone publishing or running a backup.

## Build plan

### Phase 1 — Status-returning publisher (D1)

`plugins/server_manager/includes/AgentDistPublisher.php`

- `publish()` returns the result array described in D1. Every existing early
  return and the catch block set an explicit status.
- The catch block distinguishes: if the failure happened once a rebuild was
  known to be needed, status is `failed`; there is no other path into the
  catch, since the pre-rebuild checks all return early.
- `$out` messages are unchanged in wording except that the `failed` case no
  longer claims the previous artifact was carried forward — it says the
  publish is being refused.
- Version bump.

### Phase 2 — Pre-flight placement and refusal (D2)

`plugins/server_manager/includes/publish_upgrade.php`

- Move the `AgentDistPublisher` require and call to just after the downgrade
  guard, before the VERSION write.
- On `failed`: print the message and `exit`.
- On `built`: keep the existing report; add the built version to
  `$publish_warnings`-style summary output so the release's agent version is
  visible at the end of the run. (`$publish_warnings` is initialised later in
  the file; use a separate variable set at pre-flight and reported alongside
  it.)
- Remove the old call site at ~504.

### Phase 3 — Publish log (D3)

`plugins/server_manager/includes/publish_upgrade.php`

- Buffer inside `publish_output()`.
- `register_shutdown_function` near the top writes
  `{site root}/logs/publish/publish-{version}-{YmdHis}.log`, creating the
  directory if needed.
- Prune to the newest 20.
- Report the log path as the last line of a successful publish.

### Phase 4 — Drift test (D4)

`plugins/server_manager/tests/agent_bundle_drift_test.php`, tier `safe`,
env `any`, needs `[]`.

- Skip-and-pass when `AgentDistPublisher::sourcePath()` has no `main.go`.
- Assert manifest version === source version.
- Assert every binary named in the manifest exists on disk (this is
  `artifactsPresent()`, and a manifest naming a missing file is the other way
  a bundle can be wrong).

## Documentation

`plugins/server_manager/docs/overview.md` — the release-channel section
already describes bundling. Update the paragraph covering
`server_manager_agent_source_path` to state the refusal rule: a control plane
with no agent source carries the artifact forward and publishes normally, and
a control plane whose agent source is newer than the bundle must rebuild it
successfully or the publish is refused.

`docs/deploy_and_upgrade.md` — document the publish log: where it is written,
that it captures aborted publishes, and the retention count.

Both read as though the end state always existed. No mention of the incident;
that belongs here and in git history.

## Acceptance

1. A publish on a box with no agent source completes and reports the artifact
   carried forward.
2. A publish where source and bundle versions match completes without invoking
   the Go toolchain.
3. A publish where the source is newer and the build succeeds completes, and
   the resulting `server_manager` plugin archive contains the new agent
   version.
4. A publish where the source is newer and the build fails prints the
   compiler error and refuses. Afterwards: VERSION is unchanged, no new core
   archive exists, no new `upg_upgrades` row exists, and `agent_dist` still
   holds the previous artifact.
5. Every one of the four runs above leaves a log file in `logs/publish/`,
   including run 4.
6. `agent_bundle_drift_test` passes on the control plane, and fails if
   `agent_dist/manifest.json` is edited to a version the source does not have.
7. `php tests/run.php safe` is green.
