# Parallel sessions and the release flow

**Status:** Ideas, 2026-10-08. Nothing built; the owner is deciding.
Easy-to-medium changes only; no restructuring of how sessions work. Section 6
adds the `work <name> --commit` integration the owner asked for.

## 1. The problem

Several Claude sessions work on dev at the same time, in **one working tree
with one git index**. Three things go wrong because of that:

1. **One session's unfinished work blocks another's release.** A publish
   refuses while any file it would ship has uncommitted changes
   (`ReleaseCommit::joineryBlockers()`, publish_upgrade.php:817). It cannot
   tell "another session is halfway through this" from "I forgot to commit".
   Tonight, 0.8.467 waited on a second session's edits to admin_settings,
   admin_menus.json and upgrade.php.
2. **Staged work gets swept into the wrong commit.** `/committhis` stages
   into the shared index. A commit made for another session between staging
   and the owner's commit takes it along. In one session this happened
   three times (memory: never stage early); the worst put 1048 lines of Drive
   specs into a mailbox commit.
3. **Nobody can see whose change a file is.** `/commitall` and the owner
   look at `git status` and guess which session a dirty file belongs to.

## 2. Ideas, easiest first

### I1. Record who changed each file (easy)

A Claude Code hook after every Edit/Write appends one line to a shared log:
time, session name (from the `work` launcher), and path. A small command
reads it as **"dirty file → session that last touched it"**.

- The publish refusal names the owner of each blocker: "3 files are
  public-html-41's, still uncommitted". You know whom to ask instead of
  guessing.
- `/committhis` and `/commitall` group dirty files by session, so each
  session stages only its own.
- **Catch:** edits made outside Claude (by you, or by a script) are not
  logged. The tool reports them as "unknown", which is still useful.

### I2. A private index per session (easy)

`/committhis` stages into a session-private index file
(`.git/index-<session>`) instead of the shared one, and saves its commit
message beside it (`.git/commit-msg-<session>`). Nothing another session
commits can pick up this session's staging. You commit it with
`work <session> --commit` (section 6); there is no command to paste.

- A forgotten private index is harmless: it is a file under .git, and the
  next run replaces it.
- This removes problem 2 at the root, instead of the "never stage early"
  workaround.

### I3. A release freeze (easy)

Before you publish, you turn on a freeze flag (one file). While it is on, a
hook in every other session warns before it edits a file that ships, and
says a release is in progress. The freeze ends when the publish finishes or
you turn it off.

- **Catch:** other sessions pause or switch to work that doesn't ship
  (specs, memory, research) for the few minutes of a publish. A warning
  rather than a block keeps urgent fixes possible.
- It doesn't help with work that was already half done when the freeze
  started; I1 tells you whose that is.

### I4. Build the release from the commit, not from the working tree (medium)

The publish checks out the commit it is releasing into a separate clean
directory (`git worktree add`) and builds every archive from there. Edits
sitting uncommitted in dev's tree are simply not part of the release, so
they can't block it.

- **This removes problem 1 for good.** The release is exactly the commit,
  which is also what release transparency wants (a release is a commit).
- **The install SQL is generated from dev's database, not from files.** An
  uncommitted data-class change that update_database has already applied
  would be inside the schema dump. Guard: the publish still refuses when an
  uncommitted change touches a file that shapes the database (`data/*_class.php`,
  plugin `data/`, `settings.json`, `plugin.json`, `admin_menus.json`,
  `migrations/`). That is a far smaller set than "every shipping file", and
  it names a real risk.
- **Files the publish writes** (VERSION, the install SQL, plugin version
  bumps) still go into dev's tree for you to commit. Its refusal already
  prints a commit command scoped to those paths.
- The deploy test gate keeps running against dev's live tree, as today.
- **Catch:** the build directory costs disk space during a publish (a
  checkout of the tree), removed afterwards.

### I5. Tell sessions to leave shipping files clean between turns (easy, habit)

A line in the shared CLAUDE.md: don't leave a shipping file half-edited at
the end of a turn. Finish the change, or keep the draft in the scratchpad
until it's ready. Each session also says "ready to commit" through
cross-session messaging, so finished work reaches you sooner.

- **Catch:** it relies on discipline, so it shrinks the problem rather than
  fixing it. It costs nothing.

## 3. Not proposed now

**Each session in its own git worktree and branch,** merged when done. This
is the full fix for a shared tree, but dev.getjoinery.com serves one tree,
so a session couldn't test its work in the browser without its own site
copy. That is the restructuring you asked to leave for later.

## 4. Recommendation

- **First, I1 + I2.** Both are hooks and command changes outside the
  product, about a session's work. They fix the swept-commit problem at its
  root and make every refusal name its owner.
- **Then I4,** the medium one that removes release blocking. It is one
  change to publish_upgrade.php plus the database-file guard, and it is the
  same direction release transparency already took.
- **I3 and I5** are stopgaps worth having only until I4 lands.

## 5. Open questions

- **Q1.** Settled 2026-10-08: the launcher runs the commit (section 6), so
  the private index costs you nothing to type.
- **Q2.** I3 freeze: warn, or block?
- **Q3.** I4: should the deploy test gate also run against the clean
  checkout? It would make the gate test exactly what ships, but the tests
  need a site and database around them, which is a bigger change.

## 6. In the `work` launcher

`work` (`~/.local/bin/work`, outside the repo) already knows every session by
name: its Claude session id, its folder, and its screen window. It becomes
the one place you commit a session's work.

**`work <name> --commit`**

1. If the session has prepared a commit (`.git/index-<name>` and
   `.git/commit-msg-<name>`), it shows the files and the message and asks
   `Commit? [y/N]`.
2. If it hasn't, and the session is running, the launcher types
   `/committhis` into that session's screen window. It waits for the
   prepared commit to appear (with a timeout, saying so if it runs out),
   then shows it as in step 1. A session that isn't running is reported,
   and nothing is typed anywhere.
3. On yes, it runs `git commit` with the session's private index and the
   saved message. The commit holds exactly that session's files, whatever
   else is dirty or staged in the tree.
4. It brings the shared index up to date for just those paths
   (`git reset -q -- <paths>`). Otherwise `git status` would show them as
   staged reversals of the new commit. It then deletes the private index and
   the message file, and prints the new commit.
5. It asks `Push? [y/N]`, since a publish needs the commit on the public
   remote.

**`work --commits`** lists every session with a prepared commit waiting:
name, file count and subject line. One look tells you what is ready.

**What changes in `/committhis` and `/commitall`:**

- They stage into the session's private index and save the message file,
  through a small helper the launcher provides (`work --prepare-commit`).
  The helper resolves the calling session's name from its Claude session id
  in the registry.
- They still print the message in the chat, so you can read it there.
- `/commitall` uses I1's log to leave out files another live session is
  holding, and lists them by owner instead of staging them.

**Build order:**

1. I1, the edit log hook.
2. `work --prepare-commit` and the `/committhis` change.
3. `work <name> --commit` and `work --commits`.

Each step is usable on its own. Until step 3, a prepared commit can still be
committed by hand with the command `/committhis` prints.
