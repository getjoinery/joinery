# Server Manager node console — run a command on a managed node from the admin

**Status:** Built 2026-08-03
**Date:** 2026-08-03

## The problem

Fleet operations sometimes need a command that no built-in job covers. The
motivating case was real: switching a node's Apache from mod_php to php-fpm
took six shell commands, and the only ways to run them were raw SSH from a
terminal or `node_exec.php` from the control-plane CLI.

Both paths work. Both are invisible. Neither leaves any record of who ran
what, on which node, when, or what came back — while every *built-in*
operation (backup, restore, upgrade) produces a permanent job row with
captured output. The ad-hoc command, the one most worth an audit trail
because nobody reviewed it in advance, is the only operation that has none.

The capability itself is not new risk. Server Manager already holds SSH keys
to every node; `node_exec.php` already runs arbitrary commands with them.
Anyone with shell access to the control plane has this power today. The
missing piece is not the power — it is visibility and accountability around
its use.

## What "safeguards" honestly means

Arbitrary shell cannot be sandboxed by inspection. A denylist of dangerous
commands is theater: `rm` has a hundred spellings (`find -delete`, a heredoc,
a base64-piped script). Any parser that claims to classify commands as safe
will be wrong exactly when it matters, and its existence implies a protection
it does not provide.

The safeguards that genuinely hold are about **who**, **where**, and
**what happened**:

- **Who:** superadmin only, with a fresh second-factor step-up.
- **Where:** per-node opt-in, default off. A node is not console-reachable
  until the operator deliberately makes it so.
- **What happened:** every run is a management job — command, operator, node,
  timestamps, output, exit status — browsable forever in the existing jobs UI.

No allowlists, no denylists, no "safe mode". That is a deliberate scope
statement, not an omission.

## Design

### Per-node opt-in: `mgn_allow_console`

New boolean on `ManagedNode` (`mgn_allow_console`, default false), exposed as
a toggle on the node edit form. The schema updates automatically from the
field specification via plugin sync.

Default off means a freshly added or provisioned node cannot be reached from
the console UI until the operator flips it — consistent with zero-config
installs (nothing is *required*; the default is simply the safe one).

### The Console tab

A new node detail tab (`includes/node_detail_tabs/console.php`, registered in
the `node_detail.php` shell's tab list and `$valid_tabs`).

When `mgn_allow_console` is off, the tab renders a single guided control: a
short line stating the console is disabled for this node and a link to the
node edit form where it can be enabled. No explainer prose beyond that.

When enabled, the tab shows:

- **The command form** (FormWriter):
  - Command — single multiline text input. One command line per run; chaining
    with `&&`/`;` is the operator's choice and arrives verbatim.
  - Timeout — select: 60 / 120 / 300 / 600 seconds, default 120. This becomes
    the job step's `timeout` and is the runaway guard.
  - Run on host — checkbox, shown only for Docker nodes. Maps to the step's
    `on_host` flag: default runs inside the container (`docker exec`), checked
    runs on the host.
- **A caution line by the command box:** the command is recorded verbatim in
  the job record — do not inline passwords or keys. (Secret-carrying input is
  a deferred phase, below.)
- **Recent console runs:** the node's `run_command` jobs (status, operator,
  age, link to job detail). This is the audit trail made visible where the
  commands are issued.

### Confirmation before dispatch

Submitting opens a `<dialog>` confirmation showing the *resolved* execution
context — node name and slug, host, SSH user, container name or "bare
metal", on-host choice, timeout, and the exact command — with Run/Cancel. No
editing inside the dialog; cancel returns to the form. The point is that the
operator confirms what will actually execute, not what they think they typed.

### The gate, at POST time

A new `run_command` action in `NodeDetailActions` (with an `$error_tab`
entry → `console`). Checks in order, each failing loudly back to the tab:

1. **CSRF** — already centralized in `dispatch()`.
2. **Permission 10.** The node detail page admits permission 5; the console
   demands superadmin explicitly.
3. **`mgn_allow_console`** — refused if off, even on a hand-crafted POST.
4. **Step-up.** If the account holds any second factor (TOTP or a live
   passkey), a recent step-up marker is required —
   `PasskeyService::hasRecentStepUp()`, the same session-bound short-lived
   marker every other sensitive action uses (see docs/account_security.md).
   Stale or absent → bounce with a message; the tab offers the standard
   step-up ceremony and the operator retries. An account with no second
   factor passes this check — matching platform doctrine (the same rule the
   vault applies to recovery-code unlocks): the gate binds a factor the
   account *has*, it does not invent an enrollment requirement.
5. **Validation** — non-empty command, timeout within the offered set.

Step-up freshness is the marker's own window (the platform's standing
"recent step-up" definition), not a per-command ceremony: confirming five
commands in a working session with five authenticator touches adds friction
without adding protection, since the session is the thing the step-up vouches
for.

### Execution: a management job like any other

`JobCommandBuilder::build_run_command($node, $params)` returns a single step:

```php
[['type' => 'ssh', 'label' => 'Run command', 'cmd' => $command,
  'timeout' => $timeout, 'on_host' => $on_host]]
```

`ManagementJob::createJob()` records it as job type `run_command`
(`created_by` = the operator), and the handler redirects to the job detail
page. Everything after that already exists: the Go agent claims the pending
job, runs the step through the node's SSH transport, streams output into
`mjb_output`; the job detail page live-polls output, warns when the agent is
offline, warns when a job runs long. Zero new execution or streaming code.

A non-zero exit marks the job failed with the output visible — honest, and
exactly what the operator needs (`apache2ctl configtest` failing *should*
show red). `run_command` joins `ManagementJob::filterTypes()` so both jobs
pages can filter on it. No `JobResultProcessor` handler is needed; the type
has no structured result to extract.

### Privilege is the SSH identity's, not the feature's

The command runs as the node's configured `mgn_ssh_user` over the node's
existing key. If that user lacks `sudo`, so does the console. The feature
grants no privilege the control plane's SSH identity does not already hold —
worth stating because it is the honest answer to "how dangerous is this?":
exactly as dangerous as the SSH key Server Manager already has.

### Output display

The job detail page already renders output through `SmSecretRedactor`;
console output inherits that. The step timeout is the resource guard; output
size is left to the agent's existing handling (if bloat ever proves real, a
cap belongs in the agent, for all job types — not here).

### CLI parity: `node_exec.php` joins the audit stream

The CLI keeps working (it is the primary investigation path) but stops being
invisible: after running a command, `node_exec.php` writes the same
`run_command` job row — command, node, output, exit status, terminal status,
`mjb_parameters` `{"source": "cli"}`, `created_by` null. Capturing output
means switching `passthru()` to a `proc_open()` tee (echo as it arrives,
retain for the row). Stored command and output pass through
`SmSecretRedactor` first, because CLI invocations are where `PGPASSWORD=...`
prefixes actually appear. List mode and prefix mode record nothing — only
executions.

The Console tab's history and the jobs pages then show one unified stream:
UI runs and CLI runs, same shape, same place.

## Explicitly not built

- **Command allow/denylists or "safe mode" parsing** — false safety, per
  above.
- **Interactive sessions** (TTY, streamed stdin) — jobs are one-shot by
  design; anything interactive belongs in real SSH.
- **Secret input** — a separate stdin box whose content is fed to the
  command's stdin and scrubbed from the job row on completion (precedent:
  the `scrub_job_row_inline_credentials` migration). Deferred: it needs an
  agent contract change, and the caution line covers the gap meanwhile.
- **Fleet-wide run** (one command across all console-enabled nodes — the
  php-fpm switch was exactly this shape). Deferred until single-node runs
  have proven the ergonomics; a fleet run is then a loop over nodes creating
  one job each, nothing structurally new.

## Build tasks

1. `mgn_allow_console` field spec + node edit toggle (plugin sync handles
   schema).
2. `JobCommandBuilder::build_run_command()` + `run_command` in
   `ManagementJob::filterTypes()`.
3. `NodeDetailActions` `run_command` handler with the five-check gate;
   `$error_tab` entry.
4. `includes/node_detail_tabs/console.php` + tab registration in
   `node_detail.php`.
5. Step-up wiring on the tab (existing ceremony components).
6. `node_exec.php` job-row recording with redaction.
7. Tests (plugin `tests/`): builder shape and timeout bounds; gate refusals
   (flag off, permission, stale step-up when a factor exists); `filterTypes`
   membership.
8. Docs: add a "Node console" section to
   `plugins/server_manager/docs/overview.md` (current-state voice: the tab,
   the gate, the job type, the CLI audit parity).

## Open decisions

- **None blocking.** Step-up window (platform marker), per-node default
  (off), and no-allowlist scope are all settled above; say so if any should
  reopen.

## Built

All eight tasks landed 2026-08-03, verified end to end on dev: a command typed
in the tab produced a `run_command` job, the agent claimed and ran it, and the
failure (a deliberately unusable SSH key on the throwaway fixture node) came
back in the job output. The CLI path was verified separately — exit codes
preserved, output streamed and stored, `PGPASSWORD=` redacted.

Two things the build revealed that the spec did not anticipate:

- **The refusal path returns null, not a redirect.** `NodeDetailActions::run()`
  now returns `?string`; null re-renders the page so a refusal (step-up owed,
  empty command, bad timeout) keeps the typed command instead of discarding it.
- **The confirm interceptor needs a one-way latch.** FormWriter's validator
  also intercepts the form's submit and re-dispatches it, so one click raises
  several submit events. A flag consumed on pass-through gets eaten by the
  wrong event and the dialog reopens forever — observed, then fixed with a
  latch that is set once and never cleared.

`SmSecretRedactor` gained an env-var assignment pattern (`PGPASSWORD=...`,
`AWS_SECRET_ACCESS_KEY=...`), which the structured JSON/header patterns did not
cover — that is the shape a hand-typed console command actually carries.
