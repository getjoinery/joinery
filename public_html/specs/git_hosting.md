# Spec: Git Hosting — first-class, in-platform version control for plugins & themes

## Problem

Plugin and theme developers have no way to version their work *inside* Joinery. The only
options are external (push to GitHub/GitLab) or none at all. Worse, on the platform
maintainer's own dev box the entire site is a single git repo rooted at `public_html/`,
so every plugin and theme is just a tracked subtree of one monorepo. There is no clean way
to develop a single plugin as its own independently-versioned project: nesting a second
`.git` inside `/plugins/myplugin/` makes the outer repo try to record it as an embedded
gitlink, and symlink-based layouts are fragile.

We want git hosting that is a **first-class capability of the platform** — repos live on
the box, version control happens through the admin interface (surfaced directly on the
plugins and themes admin pages), and the thing being versioned is the live working
directory the site already runs from.

Two constraints shape the design beyond the hosting itself:

- **No box may be the sole holder of source.** Today the monorepo's GitHub remote gives
  every plugin and theme an off-box copy for free; decoupling must not lose that. Every
  hosted repo is therefore mirrored to GitHub automatically.
- **Reconstruction must not depend on the box being reconstructed.** The list of repos that
  compose the platform is versioned *in the core repo* (a checked-in manifest), never only
  in the database — a fresh box must be buildable from GitHub alone.

## Goal

1. Host bare git repositories on a Joinery box, served over **smart-HTTP** (`git clone`/
   `push`/`pull` over HTTPS), authenticated with **existing Joinery API keys**.
2. Make each `/plugins/{name}/` and `/theme/{name}/` directory an ordinary git working copy
   of its own hosted repo — versioned in place, no symlinks, no second checkout.
3. Drive the everyday loop from the **admin interface**: every plugin/theme row on the
   plugins and themes admin pages shows its repo state (not versioned / up to date /
   changed / unpushed) with a git menu, backed by a per-repo Version Control page (commit,
   push/pull, tag, history, diff, rollback) — while plain `cd … && git …` keeps working
   underneath because it is just a normal repo.
4. **Mirror every hosted repo to GitHub automatically** — the GitHub repo is created via
   the GitHub API at provision time and kept current by a `post-receive` push — so a dead
   box loses nothing and every product is individually clone-able off-box.
5. Give the maintainer a one-time strategy to extract plugins/themes out of the monorepo
   into their own repos **without changing how consumers receive updates**, with fresh-box
   reconstruction driven by a manifest checked into the core repo.

## Non-goals (deliberately excluded)

- **SSH access.** HTTPS + API-key Basic auth only. No `authorized_keys`, no `git-shell`.
- **GitHub Actions / CI, code search, pull-request review UI, issues.** Not a GitHub clone.
- **Public/anonymous repos and inter-dev sharing.** v1 repos are private to their owner
  (plus perm-10 admins). A collaborators model can come later.
- **GitHub as a source of truth.** Mirrors are one-way, push-driven replicas for off-box
  safety and rebuild. Development flows through the Joinery-hosted repos; nothing ever pulls
  *from* GitHub during normal operation. (GitHub offers no pull-mirroring anyway — the
  mirror direction is necessarily push, from our side.)
- **Changing the last mile.** The publish/upgrade pipeline (`publish_upgrade.php` →
  archives → `upgrade.php` → consumers) and "install stock from central server" are
  untouched. Consumers see no difference whatsoever.
- **Cross-box install consumption.** Joinery does **not** learn to install/update plugins
  by pulling from a hosted repo. Distribution stays with Server Manager / manual packaging.

## Packaging choice (recorded): core feature, not a plugin

Git hosting ships as **core**, gated by the `git_hosting_enabled` setting (default off).
The plugin packaging was considered and rejected:

- Every integration point is a core edit anyway — the `/git/*` streaming route in
  `serve.php`, the Version Control cells on `adm/admin_plugins.php` and
  `adm/admin_themes.php`, the manifest consumed by `build_dev_from_source.sh`, and
  `core_git_repos.json` at the `public_html/` root. A plugin here would be a thin directory
  whose real substance is smeared across core.
- After Phase 2 this machinery is **bootstrap infrastructure** — it is how the platform
  reconstructs its own source layout. An uninstallable component (plugin uninstall drops
  tables and deletes files) must not hold the repo registry. A plugin packaging would also
  invite the self-reference of `git_hosting` being extracted into a repo hosted by itself.
- The opt-out a plugin provides is fully covered by the setting: the `/git/*` route is
  gated with `check_setting`, and the admin surfaces render only when enabled.
- The only plugin-exclusive convenience lost is the provisioning-check UI
  (`PluginProvisioning.php` is plugin-only). It is replaced by the git admin pages
  rendering a setup banner when a `GitHostingHealth` check fails (P1.11).

## Two personas (this distinction drives the whole design)

**The normal plugin/theme dev.** Installed Joinery the ordinary way — their site is *not* a
git repo. They have one plugin under git in `/plugins/myplugin/`. They never touch
extraction, `.gitignore`, or the core manifest. They enable the setting, click "Create repo"
on their plugin's row, and start committing; if they configure mirroring, it points at
*their own* GitHub account. This is the steady state for everyone.

**The platform maintainer (us).** The dev box *is* a monorepo. Reaching the steady state
above requires a one-time migration (Phase 2) to carve plugins/themes out of the core repo.
After that, day-to-day work is identical to the normal dev's — plus stewardship of
`core_git_repos.json`, the checked-in manifest of what composes the platform.

**Two jobs, two surfaces.** The admin UI answers "host this repo on this box" — anyone,
at runtime, recorded only in the database. The manifest answers "this repo is part of what
the platform *is*" — maintainer only, versioned in core, changed rarely and deliberately.
Normal devs never have a reason to touch the manifest, and UI-created repos never enter it.

## Phasing

The work splits into two independently-shippable phases:

- **Phase 1 — Hosting + version control + mirroring, proven on a throwaway test plugin.**
  Build the whole capability and validate it against a brand-new test plugin that was
  *never* in the monorepo. That test plugin is exactly the normal-dev path, so Phase 1
  exercises the real workflow end-to-end without touching a single existing plugin. Nothing
  here is irreversible.
- **Phase 2 — Migrate the existing plugins/themes out of the monorepo.** Only after Phase 1
  is known-good: extract real plugins/themes into their own repos (each mirrored to GitHub
  from the moment it exists), stop the core repo from tracking them, record them in
  `core_git_repos.json`, and wire fresh-box setup. This is the maintainer-only,
  higher-risk part.

## Architecture (applies to both phases)

```
  Admin UI (plugins/themes rows         working copies               bare repo store
   + per-repo Version Control)
  ┌───────────────────────┐        public_html/                   {site_root}/
  │ create / status /      │  ───►    plugins/myplugin/  ──push──►  git_repositories/
  │ commit / push / pull / │          (.git, ignored by              jeremy/myplugin.git
  │ tag / history / diff   │           core repo)                    jeremy/mytheme.git
  └───────────────────────┘        theme/mytheme/  ──push──►         ...
        shells out to git              (.git)                      (bare, outside web root)
        in the working dir                                            ▲      │
                                                                      │      │ post-receive →
   external git client ── git clone https://host/git/jeremy/...  ────┘      │ git push --mirror
        (HTTP Basic: user = API public key, pass = API secret)              ▼
                                                                  GitHub mirrors (off-box;
                                                                  per-box owner + token)
```

One filesystem tree, one running site. The core repo's `.gitignore` hides `/plugins/*` and
`/theme/*`, so it never sees the inner `.git` dirs; each plugin/theme dir is its own repo
whose remote is a bare repo in the store. Every push into a bare repo fans out to its GitHub
mirror via a `post-receive` hook. The build reads the working copies off disk and never
looks at git at all.

---

# Phase 1 — Hosting + version control + mirroring (validated with a test plugin)

## P1.1 Bare repo store

- Default location: `{PathHelper::getSiteRoot()}/git_repositories/` (e.g.
  `/var/www/html/joinerytest/git_repositories/`), alongside `uploads/` and `static_files/`,
  **outside the web root** so repos are never served as static files.
- Overridable via setting `git_hosting_repo_storage_path` (empty → derive the default).
- On-disk layout: `{store}/{owner_username}/{repo_name}.git` (bare).
- Owned exactly like the rest of the site — `www-data:user1` — so it inherits the standard
  `fix_permissions.sh` treatment (`770` prod / `777` dev) with no feature-specific handling.

## P1.2 Smart-HTTP endpoint

- Route `/git/*` registered in `serve.php` as a **custom streaming handler**, gated with
  `check_setting` on `git_hosting_enabled` (disabled → the route does not exist).
- Invokes the `git-http-backend` CGI via `proc_open`, bridging:
  - `php://input` → backend stdin (so push pack data streams in, not buffered),
  - backend stdout → client (parse CGI headers, then stream the body, flushing).
- Sets the CGI environment: `GIT_PROJECT_ROOT` = store, `PATH_INFO` =
  `/{owner}/{repo}.git/...`, `REQUEST_METHOD`, `QUERY_STRING`, `CONTENT_TYPE`, and
  `REMOTE_USER` (set after auth). `GIT_HTTP_EXPORT_ALL` is **not** set — export is gated by
  our own auth, below.
- **Must bypass the front controller's custom-route output buffering**
  (`RouteHelper.php` ~1174-1202, the error-detection capture) — buffering gigabyte pack
  transfers in memory is unacceptable. Precedent for streaming responses already exists:
  `RouteHelper::serveStaticFile()` (`readfile()`, `RouteHelper.php:126-165`) and the ICS /
  uploads custom routes in `serve.php`. This route needs the same raw-streaming treatment,
  with buffering explicitly disabled.

## P1.3 Authentication (reuse API keys, no new credentials)

Git over HTTPS uses HTTP Basic. Map it straight onto the existing `ApiKey` model
(`data/api_keys_class.php`, table `apk_api_keys`):

- **Username** = `apk_public_key`; **password** = the API secret.
- Lookup + validation mirror `api/apiv1.php`: find the key by public key, check
  `apk_is_active`, `apk_start_time`/`apk_expires_time`, and `apk_ip_restriction`, then
  verify the secret with `ApiKey::check_secret_key()` (bcrypt, `api_keys_class.php:54-58`).
- Resolve the owning user from `apk_usr_user_id`.
- On missing/failed auth, return `401` with `WWW-Authenticate: Basic realm="…"` so the git
  client prompts for credentials.

## P1.4 Authorization (ACL)

- After auth, the request must target a repo the user **owns**, or the user must be a
  permission-10 admin. Otherwise `403`.
- v1 has no public read and no per-repo collaborators (non-goal).

## P1.5 Repo data model — `GitRepo` (`data/git_repos_class.php`, prefix `ghr`, table `ghr_git_repos`)

| field | type | notes |
|---|---|---|
| `ghr_git_repo_id` | int8 serial | pk |
| `ghr_usr_user_id` | int4 | owner |
| `ghr_name` | varchar(128) | repo name (per-owner unique) |
| `ghr_description` | varchar(255) | |
| `ghr_target_type` | varchar(16) | `plugin` \| `theme` \| `standalone` |
| `ghr_target_path` | varchar(255) | e.g. `plugins/myplugin` (the working copy; NULL for standalone) |
| `ghr_default_branch` | varchar(128) | default `main` |
| `ghr_mirror_remote_url` | varchar(255) | GitHub mirror clone URL; NULL when mirroring is off |
| `ghr_mirror_push_time` | timestamp(6) | last successful mirror push |
| `ghr_mirror_error` | varchar(255) | last mirror failure message; NULL when healthy |
| `ghr_create_time` | timestamp(6) | default `now()` |
| `ghr_delete_time` | timestamp(6) | soft delete |

Standard core data class — `update_database` creates and maintains the table from
`$field_specifications`. Disk path is derived (`{store}/{owner}/{ghr_name}.git`), not
stored. Rows originate from two sources that share this one table: the admin UI (any dev,
runtime) and the additive seeding of `core_git_repos.json` (maintainer composition,
Phase 2 / P2.3). The table is free to hold rows the manifest knows nothing about; the
manifest is never inferred from the table.

## P1.6 GitHub mirroring (automatic off-box copies)

Mirroring is configured per box — owner (`git_hosting_github_owner`, a GitHub org or user)
plus a fine-grained personal access token (`git_hosting_github_token`) — and once enabled
requires zero per-repo steps:

- **Create.** When a bare repo is provisioned, the GitHub repo is created via the API —
  `POST /orgs/{owner}/repos` (or `POST /user/repos` when the owner is the token's own
  account) with `"private": true` — and its clone URL recorded in
  `ghr_mirror_remote_url`. If the GitHub call fails, local provisioning still succeeds;
  the retry task (below) creates the mirror later.
- **Sync.** Every bare repo gets a `post-receive` hook installed at provision time. The hook
  launches `php utils/git_mirror_push.php {owner} {repo}` in the background and exits
  immediately, so a push never blocks on GitHub. The CLI decrypts the token (SecretBox),
  then runs `git push --mirror` against the GitHub remote — a complete replica, all
  branches and tags.
- **Token handling.** The token is supplied to git at push time via `GIT_ASKPASS` (a helper
  that emits it on stdout) — it is **never** written into `.git/config`, the remote URL, a
  command-line argument, or a log line. At rest it lives SecretBox-encrypted in
  `stg_settings` (see `docs/secret_box.md`).
- **Retry.** A core scheduled task — `tasks/GitMirrorRetry.php` + `.json` (see
  `docs/scheduled_tasks.md`) — sweeps repos whose `ghr_mirror_error` is set or whose mirror
  lags their latest local ref, re-running create/push as needed. A GitHub outage therefore
  degrades to "mirror catches up later," never "push fails."
- **Direction.** Push-only, box → GitHub. Nothing in the platform ever pulls from the
  mirror; it exists so that losing the box loses no history, and so fresh-box reconstruction
  (P2.3) has an authoritative off-box source.

When `git_hosting_mirror_enabled` is off (the default), all of the above is skipped and
repos are box-local — the operator's explicit choice and risk. Mirror state (URL, last
push, last error) is shown per repo in the admin surfaces below.

## P1.7 Admin surface (row-first)

### Version Control cells on the plugins and themes pages

The primary surface is **where the artifacts already live**: each row on
`/admin/admin_plugins` (`adm/admin_plugins.php`) and `/admin/admin_themes`
(`adm/admin_themes.php`) gets a Version Control cell, rendered only when
`git_hosting_enabled` is on. The cell drops into the existing per-row `$actions` dropdown
pattern (`admin_plugins.php:264-289`) rather than inventing a new control.

- **Not versioned** — the dir has no repo: show a quiet "Not versioned" state with a
  **Create repo** action (provisions the bare repo, runs `git init` in the working copy,
  wires the remote, installs the `post-receive` hook, creates the GitHub mirror when
  configured).
- **Versioned** — show live state from one `git status --porcelain -b` per row (tens of
  milliseconds per repo; trivial at realistic plugin counts): **Up to date**,
  **N files changed**, **N commits unpushed**, or combinations.
- **Git menu** — split by whether an action needs input: **Push** and **Pull** fire
  directly from the menu; **Commit…**, **History**, **Diff**, and **Rollback** deep-link
  into the per-repo Version Control page. The status badge itself links there too.

### Per-repo Version Control page — `/admin/admin_git_repo` (the daily driver)

Parameterized by repo / target path: **status** (branch, clean/dirty, ahead/behind, mirror
state), **commit** (message field), **push** / **pull**, **tag a release**, **browse
history**, **view diff**, **roll back to a commit**. Every action shells out to `git` *in
the working directory* (`escapeshellarg`, fixed argument lists — never interpolate user
input into a command string).

### Repositories dashboard — `/admin/admin_git_repos`

Houses what has no plugin/theme row: standalone repos, repo creation outside a target dir,
clone URLs, soft-delete, and a mirror-health overview (last push / last error per repo).

Both pages are declared in `admin_menus.json` (parent: a "Developers"/"Tools" group). All
forms use FormWriter (no hand-rolled HTML). Because the working copy is an ordinary repo,
the CLI (`cd plugins/myplugin && git …`) operates on the same `.git` and is always available
as the escape hatch; UI and CLI do not lock each other out.

**Setup banner:** when a `GitHostingHealth` check (P1.11) fails, the git admin pages render
a banner naming the failing prerequisite and the copy/paste fix, instead of their normal
content. This replaces the plugin provisioning UI.

**Permissions:** nothing feature-specific. The UI writes the working copy as `www-data` and
the dev writes it via CLI as `user1`; the platform's standard ownership (`www-data:user1`)
plus `fix_permissions.sh` (`770` prod / `777` dev) already lets both write every file —
identically on dev and prod. On prod the case doesn't even arise: plugin dirs arrive as
exported archives with no `.git` and no one runs CLI git there.

## P1.8 Publish-pipeline safety change (needed as soon as a `/plugins/*/.git` exists)

The test plugin is the first thing to ever put a `.git/` inside `/plugins/`. The per-plugin
and per-theme `tar` steps in `plugins/server_manager/includes/publish_upgrade.php` currently
exclude nothing (theme tar at lines 417-422, plugin tar at lines 474-479), so they would
sweep git history into release archives. Add `--exclude=.git --exclude=.gitignore` to both
`tar` invocations (the core archive already does this via `rsync --exclude=.git`). After this
change archives are byte-equivalent to today and consumers are unaffected.

## P1.9 Settings (declared in `settings.json` at the `public_html/` root)

```json
{ "name": "git_hosting_enabled", "default": "0" },
{ "name": "git_hosting_repo_storage_path", "default": "" },
{ "name": "git_hosting_clone_host", "default": "" },
{ "name": "git_hosting_mirror_enabled", "default": "0" },
{ "name": "git_hosting_github_owner", "default": "" },
{ "name": "git_hosting_github_token", "default": "" }
```

`git_hosting_enabled` defaults off — a box opts in via admin settings (dev boxes on,
consumer prod sites never notice the feature exists). `git_hosting_repo_storage_path`
empty → derive `{site_root}/git_repositories`. `git_hosting_clone_host` empty → derive
from the request host. `git_hosting_github_owner` is the GitHub org or username that
receives mirrors; `git_hosting_github_token` is SecretBox-encrypted at rest and never
displayed back or echoed. All overridable per box.

## P1.10 File layout (core)

```
data/git_repos_class.php             # GitRepo / MultiGitRepo model
includes/GitHttpBackend.php          # proc_open bridge to git-http-backend
includes/GitWorkingCopy.php          # status/commit/push/pull/tag/log/diff helpers
includes/GitHubMirror.php            # GitHub API (create repo) + mirror push (GIT_ASKPASS auth)
includes/GitHostingHealth.php        # setup check methods (banner + acceptance)
adm/admin_git_repos.php              # repositories dashboard
adm/admin_git_repo.php               # per-repo Version Control page
adm/logic/admin_git_repos_logic.php
adm/logic/admin_git_repo_logic.php
tasks/GitMirrorRetry.php / .json     # core scheduled task: mirror retry sweep
utils/git_mirror_push.php            # invoked by post-receive hook; also the retry task's worker
maintenance_scripts/install_tools/install_git_hosting.sh   # idempotent host installer
docs/git_hosting.md                  # developer doc
```

Core files modified: `serve.php` (the `/git/*` route), `adm/admin_plugins.php` and
`adm/admin_themes.php` (Version Control cells), `settings.json` (settings above),
`admin_menus.json` (menu items), `plugins/server_manager/includes/publish_upgrade.php`
(P1.8 tar excludes).

## P1.11 Host prerequisites & setup checks

### `GitHostingHealth` check methods (`includes/GitHostingHealth.php`)

Public static, side-effect-free, time-bounded; each returns NULL when healthy or a clean
problem message string. The git admin pages call them to render the setup banner (P1.7),
and the acceptance tests call them directly.

- **`checkGitBinary()`** — confirm `git` is on `PATH` (`git --version`), then confirm the
  `git-http-backend` CGI exists under `git --exec-path` (typically
  `/usr/lib/git-core/git-http-backend`). Report the missing piece by name.
- **`checkRepoStore()`** — resolve the store path (setting or `{site_root}/git_repositories`),
  confirm it exists and `is_writable()` for the web-server user.

### `maintenance_scripts/install_tools/install_git_hosting.sh`

Idempotent host installer in the same style as `install_email.sh` (versioned header, safe to
re-run). It installs git and creates/permissions the store. The setup banner names it as
the fix.

### Copy/paste CLI (run on the host only if a check reports missing)

```bash
# 1. Install git (provides the git-http-backend CGI used for smart-HTTP)
sudo apt-get update && sudo apt-get install -y git

# 2. Confirm the smart-HTTP backend is present
git --version
ls "$(git --exec-path)/git-http-backend"

# 3. Create the bare-repo store outside the web root (default location; override with the
#    git_hosting_repo_storage_path setting). Ownership matches the rest of the site; the
#    standard fix_permissions.sh pass (770 prod / 777 dev) sets the modes.
sudo mkdir -p /var/www/html/joinerytest/git_repositories
sudo chown www-data:user1 /var/www/html/joinerytest/git_repositories
```

After the schema lands, run `update_database` (admin utilities page) so the `ghr_git_repos`
table is created and the settings are seeded.

## Phase 1 acceptance criteria

Using a throwaway test plugin `git_hosting_demo` (created fresh under `/plugins/`, marked
`included_in_publish: false`) — i.e. exercising the normal-dev path, no existing plugin
touched:

1. **Gating** → with `git_hosting_enabled` off, `/git/*` 404s and no Version Control cells
   or pages render; turning the setting on lights everything up with no other steps.
2. **Row cell + create** → the test plugin's row on `/admin/admin_plugins` shows
   "Not versioned"; **Create repo** provisions the bare repo, inits the working copy, wires
   the remote; the row now shows "Up to date".
3. **Edit → row state → commit → push** → editing a file flips the row to "1 file changed";
   commit (with message) via the per-repo page lands it; **Push** from the row menu
   succeeds to the bare repo and the row returns to "Up to date".
4. **External clone with API-key auth** → `git clone https://host/git/{user}/git_hosting_demo.git`
   (Basic auth: public key / secret) succeeds and shows the commit; a commit pushed from that
   clone is brought in by a UI **pull**.
5. **Tag / history / diff / rollback via UI** all behave correctly.
6. **Auth/ACL** → wrong/expired key → 401; a repo owned by another non-admin user → 403.
7. **Publish safety** → a publish run with a `/plugins/*/.git` present produces archives that
   contain no `.git` (verifies P1.8).
8. **Mirroring** → with mirroring configured, creating the repo creates the private GitHub
   repo and a push appears there (all refs) shortly after landing locally; with GitHub
   unreachable, the local push still succeeds and the retry task brings the mirror current
   once GitHub returns; the token appears nowhere on disk outside `stg_settings` and in no
   process listing or log.

---

# Phase 2 — Migrate existing plugins/themes out of the monorepo

Maintainer-only. Runs only after Phase 1 passes. Normal devs never do any of this.

## P2.1 Extract each plugin/theme with history

Use `git filter-repo` (or `git subtree split`) to carve `plugins/myplugin` into its own repo
preserving its commits; push it to its new hosted repo (via the Phase 1 host — the GitHub
mirror populates automatically on that first push); then `git rm -r` it from the core repo
and add its entry to `core_git_repos.json` in the same core commit, so composition history
records exactly when each piece left the monorepo.

## P2.2 Ignore the directories once, not per-plugin

Add `/plugins/*` and `/theme/*` to the core repo's `.gitignore`, with negations only for any
plugins/themes deliberately kept *in* the monorepo as core. This is the decided-once
structural choice — no incremental per-plugin gitignore churn.

## P2.3 The checked-in manifest — `core_git_repos.json`

A JSON file at the `public_html/` root, **checked into the core repo**, listing every repo
that composes the platform itself. Precedent: `admin_menus.json` — a versioned file at the
root, seeded into its table by `update_database`.

```json
[
    {
        "name": "inbound_email",
        "owner_username": "jeremy",
        "target_type": "plugin",
        "target_path": "plugins/inbound_email",
        "mirror_url": "https://github.com/getjoinery/inbound_email.git"
    }
]
```

- **Scope.** Only platform composition — the extracted core plugins/themes and the
  maintainer's product plugins. UI-created repos (any dev's day-to-day work, standalone
  repos, experiments) live only as `ghr_git_repos` rows and never enter this file.
- **Seeding is additive.** `update_database` upserts manifest entries into `ghr_git_repos`
  exactly as it seeds `admin_menus.json`, keyed on `(owner, name)`, resolving
  `owner_username` to `ghr_usr_user_id`. It never deletes or overwrites rows the file
  doesn't mention — UI-created repos always survive.
- **Fresh-box reconstruction needs no database and no running site.** Extend
  `maintenance_scripts/install_tools/build_dev_from_source.sh`: after cloning core from
  GitHub, parse `core_git_repos.json` from the working tree and clone each entry's
  `mirror_url` into its `target_path`, then continue the normal build. Each working copy's
  `origin` is rewired to the local hosted repo once the site is up (the mirror is a recovery
  source, not the development remote).

## P2.4 Structure choice (recorded)

**Independent repos + checked-in manifest**, chosen over **git submodules**: submodules
would make `.gitmodules` the manifest and pin exact plugin versions in a core commit, but at
the cost of detached-HEAD friction and a double-commit every time a plugin advances —
friction that lands squarely on the develop-a-plugin loop this feature exists to make easy.
The release system already versions plugins independently (per-plugin `version` +
per-plugin archives), so core-commit pinning buys nothing here.

The manifest is a file in core rather than database rows because site composition is a
versioned, deliberate decision that must survive the box and be readable before any database
exists; the database holds the runtime registry of *all* hosted repos, of which the
composition set is a small, maintainer-curated subset.

## Phase 2 acceptance criteria

1. **Pilot first** — migrate one low-risk plugin; its new repo's `git log` matches the
   history that was in the monorepo, and its GitHub mirror holds the same refs.
2. **Core repo clean** — after extraction the core repo no longer tracks the pilot dir;
   `.gitignore` covers `/plugins/*` and `/theme/*`; `core_git_repos.json` lists the pilot;
   `git status` at the root is clean and does not warn about embedded repos.
3. **Fresh-box reconstruction from GitHub alone** — a clean dev box, with no access to the
   original box, runs the build script: core clones from GitHub, every manifest repo clones
   from its mirror into place, and the site runs.
4. **Seeding is additive** — after `update_database`, manifest entries exist as
   `ghr_git_repos` rows and a UI-created repo from before the run is untouched.
5. **Byte-equivalent release** — a publish run after migration produces archives equivalent
   to a pre-migration run; `upgrade.php` consumers are unaffected.
6. **Roll out the rest** — repeat extraction for the remaining plugins/themes.

---

## Documentation

- New developer doc `docs/git_hosting.md` (Phase 1): the dev loop (row cell → create repo →
  commit/push via UI or CLI), the repo store layout, API-key auth for clone/push, and
  GitHub mirroring (settings, token handling, hook mechanics, retry task). Add the
  maintainer migration and `core_git_repos.json` to it in Phase 2. Add its index line to the
  docs list via the admin "Internal CLAUDE.md" record (CLAUDE.md is regenerated from
  `agf_agent_files` — never edited on disk).
- The publish-pipeline `.git` exclusion (P1.8) is reflected in the Deploy/Upgrade doc.
- The fresh-box manifest step is reflected in the Deploy/Upgrade doc's build-from-source
  section (Phase 2).

## Open items to confirm

1. **First Phase-2 extraction target** — which existing plugin to pilot.

## Versioning

Bump `@version` on each modified core file (`serve.php`, `adm/admin_plugins.php`,
`adm/admin_themes.php`, `plugins/server_manager/includes/publish_upgrade.php`, and any
others touched during build). No migrations — the table comes from `$field_specifications`
via `update_database`, and settings seed from `settings.json`.
