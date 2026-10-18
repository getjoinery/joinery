# Agent-Agnostic Commit Flow and Emergency Deploy

**Status:** IMPLEMENTED 2026-10-18. Verified in session: held list equals `held_blockers.php`, bad messages refused by both entry points, `--prepare` through a terminal including the spec move, `--commit` with nothing prepared exits at once, `CLAUDE.md`/`GEMINI.md` regenerated from the record. The end-to-end runbook dry run and two owner checks are in the live verification queue.
**Location:** `public_html/specs/agent_agnostic_commit_and_emergency_deploy.md`
**Build trigger:** Claude subscription quota limits (100% usage) and the introduction of alternative coding agents (e.g., Antigravity / Gemini CLI) and human-in-terminal emergency release operations.

---

## 1. The Problem

The platform's release and deployment system (`publish_upgrade.php`, Sigstore transparency logging, Go binary compilation, and remote node upgrades) is completely model-agnostic: it is written in pure PHP, shell, and Go.

However, the **developer commit and release staging workflow** on `dev.getjoinery.com` developed an accidental, single-vendor lock on Anthropic Claude Code:

1. **`work <name> --commit` assumes Claude in `screen`:**
   When an operator or script runs `work <name> --commit` for a session that has not yet staged its private index, `work` invokes `request_prepare(name)`. This sends keystrokes `screen ... stuff '/committhis\r'` into the session's screen window and blocks for up to 20 minutes (`COMMIT_WAIT_SECONDS`).
   - If Claude has hit a 100% quota/rate limit, Claude outputs an error or sits idle.
   - If the session was started by or handed over to another assistant (e.g. Antigravity / Gemini CLI) or a plain bash shell, typing `/committhis` does nothing.
   - The command hangs printing dots (`...`) until aborted with `Ctrl-C` or timing out after 20 minutes.

2. **No native terminal or alternative agent commit preparation:**
   Commit preparation logic (surveying dirty files, moving completed specs from `specs/` to `specs/implemented/`, formatting terse commit messages without quotes or backticks, and invoking `work --prepare-commit`) lived solely inside a Claude Code prompt macro (`~/.claude/commands/committhis.md`). Neither `work` nor the repository provided a standalone script or interactive CLI prompt to prepare a compliant commit from a standard shell.

3. **Deployments blocked by held files across concurrent sessions:**
   A release is built strictly from Git commits, but `publish_upgrade.php` refuses to run if any file on a **held path** (`ReleaseCommit::heldPath()`) has uncommitted changes in the working tree. Held paths include data classes (`data/*_class.php`), plugin data models, migrations, site engine templates, and publishers.
   - When multiple sessions leave draft schema changes or new site engine files in the tree, publish refuses.
   - Finding which files are held and which session holds them required either attempting a publish and failing, or writing an ad-hoc PHP snippet. There is no CLI flag on `work` to query held paths directly.

4. **Emergency deploy deadlock:**
   During an urgent production incident (e.g., repairing node deployment states or patching regression bugs), an operator whose Claude subscription is exhausted cannot easily commit fixes, unblock held paths, or verify release readiness without hand-crafting private Git indices or risking pollution of the shared working tree index.

---

## 2. Requirements & Goals

- **G1. Complete Agent Agnosticism:** Commit preparation must work equally well from Claude Code, Antigravity (Gemini), any future AI coding assistant, or directly by a human operator in a standard terminal.
- **G2. `work` Never Types Into a Session:** `work <name> --commit` only commits what has already been prepared. With nothing prepared it exits at once and names the ways to prepare one. It never sends keystrokes to any agent session and never waits.
- **G3. First-Class Held Blocker Inspection (`work --held`):** Provide an instant view of all uncommitted files that would block a release, grouped by the session that touched them, matching `ReleaseCommit::heldPath()`.
- **G4. Standalone Preparation Helper:** Provide a unified helper (or `work` subcommand) to validate and stage private commits, enforcing the platform's commit rules (no double quotes/backticks, imperative subject <= 72 chars, terse `-` bullets, spec moves) without requiring Claude macros.
- **G5. Clear Emergency Deploy Runbook:** A documented, repeatable procedure for deploying fixes during incidents without Claude.

---

## 3. Architecture & Design

### 3.1 Enhancements to `work` (`~/work-tool/work`)

`work` is versioned in its own local-only git repo at `~/work-tool` (no remote; a `pre-push` hook refuses any push). `~/.local/bin/work` is a symlink to `~/work-tool/work`. It is not part of the project repo and never ships to GitHub. Changes are committed there by the owner, like any other repo.

#### A. Direct Held Path Query (`work --held`)
Add `work --held` (and `work <name> --held`) to inspect the working tree:
1. The rule is not copied into `work`. `maintenance_scripts/dev_tools/held_blockers.php` prints `ReleaseCommit::ownerBlockers()` as JSON (the held files, the files a release leaves out, and any repository git cannot read), the same question the Publish button and `publish_upgrade.php` ask first, so the held list cannot drift from the publisher's. `ReleaseCommit::heldPath()` is the single definition.
2. `work` cross-references each held file against `edit_log_entries()` to name the session behind it (a direct edit beats a shell mention, which is marked a guess) and against the prepared commits to show who already holds it.
3. Output: held files grouped by session, with `(running)` or `(not running)`, line counts, and the prepared commit if there is one; files no session claims; how many other uncommitted files a release leaves out. `work <name> --held` shows only the files that session last changed or has prepared.
4. Exit status 1 while anything is held (or the answer could not be had), 0 otherwise, so the runbook's gate is a command.

#### B. Remove `request_prepare(name)`
`work <name> --commit` stops typing `/committhis` into the session's screen window.
1. Delete `request_prepare()` and `COMMIT_WAIT_SECONDS` from `~/work-tool/work`, along with the whole `work --nothing-to-commit` channel (`NOTHING_DIR`, `write_nothing()`, `read_nothing()`, `cmd_nothing_to_commit()`, the help entry), which existed only to end that wait.
2. `cmd_commit()` uses `prepared_or_exit(name)`, the same check `--status` and `--diff` already use. With nothing prepared it exits immediately:
   `work: '<name>' has no prepared commit. /committhis in that session prepares one, or stage it by hand with 'work --prepare-commit ...'.` Once WP3 lands the message points at `work <name> --prepare`.
3. Update the `--commit` help text, which said it types `/committhis` into a running session.
4. Drop the `work --nothing-to-commit` clause from `~/.claude/commands/committhis.md` and `commitall.md`.

Preparing a commit is always the operator's act: a person or agent runs `/committhis` (Claude), follows the Section 4 protocol (any other assistant), or runs `work <name> --prepare` (terminal). `work` never works out which agent is on the other end of a session, because it never talks to one.

#### C. Interactive / Direct Commit Preparation (`work <name> --prepare`)
Allow an operator or script to prepare a commit directly from the terminal:
```bash
work <name> --prepare [--all | PATH...]
```
1. Scope: the PATHs named; else the uncommitted files `<name>` edited directly (per the edit log). A shell command that merely mentioned a file is logged too, but the log credits every session running a command as the file changed, so a mention is a guess and never puts a file in the commit. `--all` also takes every file no other running session appears in. It prints the files, and what it left out with the reason, and asks before going on when the scope was not named.
2. Prompts for the subject and bullets, or reads `--message-file FILE`. Without a terminal and without `--message-file` it exits at once.
3. Checks the message against the commit rules (`message_problems()`): subject of 72 characters or fewer with no trailing period, a blank line, `- ` bullets, no double quote or backtick. A prompted message is asked again until it passes; a file that fails exits with the list. `--prepare-commit` applies the same check, so any agent's message is held to the same rules.
4. On a terminal, for each spec in `public_html/specs/` that the commit holds or the message names, asks whether it is built (showing unticked work packages); if so it sets the status line to `IMPLEMENTED <date>`, moves it to `specs/implemented/`, and adds both paths (the old one only when it is in `HEAD`). Without a terminal it prints a note and moves nothing.
5. Stages through the same code as `--prepare-commit` into `.git/work-commits/<name>/`. Never commits.

---

## 4. Universal Assistant Commit Protocol

Any assistant (including Antigravity / Gemini) follows this standard protocol to prepare and land commits:

### Step 1: Establish Scope and Inspect Tree
```bash
git status --porcelain
work --owners
```
Identify files belonging to the task at hand. Leave out files owned by other running sessions.

### Step 2: Promote Completed Specs
If the work implements a specification located in `public_html/specs/<name>.md`:
1. Update its status line to `IMPLEMENTED <date>`.
2. Move it to `public_html/specs/implemented/<name>.md` using `mv`.
3. Include both the source and target paths in the commit file list.

### Step 3: Format Commit Message
Write the message to a scratchpad file (e.g. `scratch/commit_msg.txt`).
**Message Rules:**
- **No double quotes (`"`) or backticks (`` ` ``)** anywhere in the message (these break shell HEREDOCs during release and git scripting). Use single quotes.
- Subject: imperative mood, no trailing period, $\le 72$ characters.
- Blank line, followed by plain `- ` bullets.
- Mention new files, version bumps, and behavioral changes explicitly.
- Terse facts only; no trailers (`Co-Authored-By`, `Generated with`, etc.).

### Step 4: Stage into Private Index
```bash
work --prepare-commit --name <session-or-task-name> --message-file scratch/commit_msg.txt -- <paths...>
```
`work --prepare-commit` stages the paths into `.git/work-commits/<name>/index`, creates the message and pathspec files, verifies that the paths differ from `HEAD`, and refuses a message that breaks the Step 3 rules. It writes nothing to the shared git index.

### Step 5: Commit and Push
The operator (or the assistant upon explicit instruction) runs:
```bash
work <name> --commit
```
`work` displays the cached diff, prompts `Commit '<name>'? [y/N]`, commits to git, resets the shared index for those paths, and prompts `Push them? [y/N]`.

---

## 5. Emergency Deploy Runbook (When Claude is Unavailable)

When an emergency hotfix or deploy must be released and Claude cannot be used:

### Phase 1: Blocker Audit & Resolution
1. **Check for held release blockers:**
   ```bash
   work --held
   ```
2. **Handle held files:**
   - **Case A: The held file is part of the emergency fix.** Include it in the commit prepared for this fix.
   - **Case B: The held file belongs to finished work from another session.** Prepare and commit that session's work using `work <session> --prepare` and `work <session> --commit`.
   - **Case C: The held file is an unfinished draft from an idle session.** Cleanly stash or temporarily shelve the file outside the repository tree, or revert if discarded.

### Phase 2: Prepare and Land the Fix
1. Draft fix files and unit tests.
2. Prepare the commit using the universal assistant protocol (Section 4):
   ```bash
   work --prepare-commit --name hotfix-xyz --message-file scratch/hotfix_msg.txt -- <paths...>
   ```
3. Commit and push:
   ```bash
   work hotfix-xyz --commit
   ```
   Confirm both commit and push.

### Phase 3: Pre-Release Gate Verification
1. Verify changed test coverage:
   ```bash
   php tests/run.php db --changed
   ```
2. Verify zero held blockers remain:
   ```bash
   work --held
   ```
   Must print `Nothing held` and exit 0.

### Phase 4: Execute Publish
Publish can be initiated via either interface:
- **CLI:**
  ```bash
  php plugins/server_manager/includes/publish_upgrade.php "Emergency hotfix: <summary>"
  ```
- **Web:** Navigate to `https://dev.getjoinery.com/admin/server_manager` and click **Publish Upgrade**.

### Phase 5: Fleet Rollout & Verification
1. In the Server Manager dashboard, open the target node's detail page.
2. Under the **Updates** tab, dispatch `Apply Upgrade` to the target node(s).
3. If the node is wedged or siteless, diagnose it from its detail page and the management agent first. SSH is the last resort, per the project's troubleshooting rules.
4. Confirm node reports healthy on the new version.

---

## 6. Work Packages

- [x] **WP1: Add `work --held` blocker inspection** (done 2026-10-18)
  `work --held` / `work NAME --held` in `~/work-tool/work`, reading the held list from `ReleaseCommit::ownerBlockers()` through `maintenance_scripts/dev_tools/held_blockers.php`, so the rule is written once.
- [x] **WP2: Stop typing into agent sessions** (done 2026-10-18, uncommitted in `~/work-tool`)
  Removed `request_prepare()`, `COMMIT_WAIT_SECONDS` and the `--nothing-to-commit` channel from `work`; `cmd_commit()` calls `prepared_or_exit()` and fails at once when nothing is prepared. Help text and the two commit macros updated.
- [x] **WP3: Terminal-native `work <name> --prepare` command** (done 2026-10-18)
  `work NAME --prepare [--all | PATH...] [--message-file FILE]`, plus the commit-rule check shared with `--prepare-commit` (`message_problems()`), so a human or any agent prepares a compliant commit without Claude Code.
- [x] **WP4: Assistant instruction template & documentation update** (done 2026-10-18)
  `docs/deploy_and_upgrade.md` (*Preparing a commit, and publishing without an agent session*), and a short *Preparing commits (any agent)* paragraph under *Version Control Rules* in the `Internal CLAUDE.md` agent-file record, which feeds both `CLAUDE.md` and `GEMINI.md`.

---

## 7. Verification Plan

1. **Held path inspection:**
   Run `work --held` and verify it reports exactly the files in the `core` list of `php maintenance_scripts/dev_tools/held_blockers.php`, each mapped to its session owner, and exits 1; with the tree clean of held files it prints `Nothing held` and exits 0.
2. **Agent-neutral preparation:**
   Prepare a dummy commit via `work --prepare-commit --name test-prep` from Antigravity/bash, verify that `.git/work-commits/test-prep/` is created, and verify that `work test-prep --status` renders the diff correctly.
3. **No typing, no waiting:**
   Run `work <name> --commit` for a running session with nothing prepared; verify it exits at once with the "no prepared commit" message and that nothing was typed into the session's screen window.
4. **End-to-end dry run:**
   Run through Phase 1 through Phase 3 on a clean branch/state to ensure all checks pass without touching live production.
