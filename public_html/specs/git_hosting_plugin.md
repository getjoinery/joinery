# Spec: Git Hosting Plugin — first-class, in-platform version control for plugins & themes

## Problem

Plugin and theme developers have no way to version their work *inside* Joinery. The only
options are external (push to GitHub/GitLab) or none at all. Worse, on the platform
maintainer's own dev box the entire site is a single git repo rooted at `public_html/`,
so every plugin and theme is just a tracked subtree of one monorepo. There is no clean way
to develop a single plugin as its own independently-versioned project: nesting a second
`.git` inside `/plugins/myplugin/` makes the outer repo try to record it as an embedded
gitlink, and symlink-based layouts are fragile.

We want git hosting that is a **first-class capability of the platform** — repos live on
the box, version control happens through the admin interface, and the thing being versioned
is the live working directory the site already runs from.

## Goal

1. Host bare git repositories on a Joinery box, served over **smart-HTTP** (`git clone`/
   `push`/`pull` over HTTPS), authenticated with **existing Joinery API keys**.
2. Make each `/plugins/{name}/` and `/theme/{name}/` directory an ordinary git working copy
   of its own hosted repo — versioned in place, no symlinks, no second checkout.
3. Drive the everyday loop (create repo, status, commit, push/pull, tag, history, diff,
   rollback) from the **admin interface**, while plain `cd … && git …` keeps working
   underneath because it is just a normal repo.
4. Give the maintainer a one-time strategy to extract plugins/themes out of the monorepo
   into their own repos **without changing how consumers receive updates**.

## Non-goals (deliberately excluded)

- **SSH access.** HTTPS + API-key Basic auth only. No `authorized_keys`, no `git-shell`.
- **GitHub Actions / CI, code search, pull-request review UI, issues.** Not a GitHub clone.
- **Public/anonymous repos and inter-dev sharing.** v1 repos are private to their owner
  (plus perm-10 admins). A collaborators model can come later.
- **Changing the last mile.** The publish/upgrade pipeline (`publish_upgrade.php` →
  archives → `upgrade.php` → consumers) and "install stock from central server" are
  untouched. Consumers see no difference whatsoever.
- **Cross-box install consumption.** Joinery does **not** learn to install/update plugins
  by pulling from a hosted repo. Distribution stays with Server Manager / manual packaging.

## Two personas (this distinction drives the whole design)

**The normal plugin/theme dev.** Installed Joinery the ordinary way — their site is *not* a
git repo. They have one plugin under git in `/plugins/myplugin/`. They never touch
extraction or `.gitignore`. They create a repo through the UI and start committing. This is
the steady state for everyone.

**The platform maintainer (us).** The dev box *is* a monorepo. Reaching the steady state
above requires a one-time migration (Phase 2) to carve plugins/themes out of the core repo.
After that, day-to-day work is identical to the normal dev's.

## Phasing

The work splits into two independently-shippable phases:

- **Phase 1 — Hosting + version control, proven on a throwaway test plugin.** Build the
  whole capability and validate it against a brand-new test plugin that was *never* in the
  monorepo. That test plugin is exactly the normal-dev path, so Phase 1 exercises the real
  workflow end-to-end without touching a single existing plugin. Nothing here is irreversible.
- **Phase 2 — Migrate the existing plugins/themes out of the monorepo.** Only after Phase 1
  is known-good: extract real plugins/themes into their own repos, stop the core repo from
  tracking them, and wire fresh-box setup. This is the maintainer-only, higher-risk part.

## Architecture (applies to both phases)

```
  Admin UI (Version Control)          working copies               bare repo store
  ┌───────────────────────┐        public_html/                   {site_root}/
  │ create / status /      │  ───►    plugins/myplugin/  ──push──►  git_repositories/
  │ commit / push / pull / │          (.git, ignored by              jeremy/myplugin.git
  │ tag / history / diff   │           core repo)                    jeremy/mytheme.git
  └───────────────────────┘        theme/mytheme/  ──push──►         ...
        shells out to git              (.git)                      (bare, outside web root)
        in the working dir                                              ▲
                                                                        │ smart-HTTP
   external git client  ── git clone https://host/git/jeremy/myplugin.git ┘
        (HTTP Basic: user = API public key, pass = API secret)
```

One filesystem tree, one running site. The core repo's `.gitignore` hides `/plugins/*` and
`/theme/*`, so it never sees the inner `.git` dirs; each plugin/theme dir is its own repo
whose remote is a bare repo in the store. The build reads the working copies off disk and
never looks at git at all.

---

# Phase 1 — Hosting + version control (validated with a test plugin)

## P1.1 Bare repo store

- Default location: `{PathHelper::getSiteRoot()}/git_repositories/` (e.g.
  `/var/www/html/joinerytest/git_repositories/`), alongside `uploads/` and `static_files/`,
  **outside the web root** so repos are never served as static files.
- Overridable via setting `git_hosting_repo_storage_path` (empty → derive the default).
- On-disk layout: `{store}/{owner_username}/{repo_name}.git` (bare).
- Owned exactly like the rest of the site — `www-data:user1` — so it inherits the standard
  `fix_permissions.sh` treatment (`770` prod / `777` dev) with no plugin-specific handling.

## P1.2 Smart-HTTP endpoint

- Route `/git/*` registered in `serve.php` as a **custom streaming handler**.
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

## P1.5 Repo data model — `GitRepo` (`git_repo_class.php`, prefix `ghr`, table `ghr_git_repos`)

| field | type | notes |
|---|---|---|
| `ghr_git_repo_id` | int8 serial | pk |
| `ghr_usr_user_id` | int4 | owner |
| `ghr_name` | varchar(128) | repo name (per-owner unique) |
| `ghr_description` | varchar(255) | |
| `ghr_target_type` | varchar(16) | `plugin` \| `theme` \| `standalone` |
| `ghr_target_path` | varchar(255) | e.g. `plugins/myplugin` (the working copy; NULL for standalone) |
| `ghr_default_branch` | varchar(128) | default `main` |
| `ghr_create_time` | timestamp(6) | default `now()` |
| `ghr_delete_time` | timestamp(6) | soft delete |

Disk path is derived (`{store}/{owner}/{ghr_name}.git`), not stored. The set of rows with a
`ghr_target_path` becomes the fresh-box manifest consumed in Phase 2; the column ships in
Phase 1 because the test plugin uses it too.

## P1.6 Admin interface (the first-class surface)

Two admin pages under the plugin, in the admin menu (parent: a "Developers"/"Tools" group):

- **Repositories dashboard** — list the user's repos, create a new repo (provisions the bare
  repo and, when `ghr_target_path` is set, runs `git init` + wires the remote in the working
  copy), copy clone URL, soft-delete.
- **Version Control page** (parameterized by repo / target path) — the daily driver:
  **status** (branch, clean/dirty, ahead/behind), **commit** (message field), **push** /
  **pull**, **tag a release**, **browse history**, **view diff**, **roll back to a commit**.
  Every action shells out to `git` *in the working directory* (`escapeshellarg`, fixed
  argument lists — never interpolate user input into a command string).

All forms use FormWriter (no hand-rolled HTML). Because the working copy is an ordinary repo,
the CLI (`cd plugins/myplugin && git …`) operates on the same `.git` and is always available
as the escape hatch; UI and CLI do not lock each other out.

**Permissions:** nothing plugin-specific. The UI writes the working copy as `www-data` and
the dev writes it via CLI as `user1`; the platform's standard ownership (`www-data:user1`)
plus `fix_permissions.sh` (`770` prod / `777` dev) already lets both write every file —
identically on dev and prod. On prod the case doesn't even arise: plugin dirs arrive as
exported archives with no `.git` and no one runs CLI git there.

## P1.7 Publish-pipeline safety change (needed as soon as a `/plugins/*/.git` exists)

The test plugin is the first thing to ever put a `.git/` inside `/plugins/`. The per-plugin
and per-theme `tar` steps in `plugins/server_manager/includes/publish_upgrade.php` currently
exclude nothing (theme tar at lines 417-422, plugin tar at lines 474-479), so they would
sweep git history into release archives. Add `--exclude=.git --exclude=.gitignore` to both
`tar` invocations (the core archive already does this via `rsync --exclude=.git`). After this
change archives are byte-equivalent to today and consumers are unaffected.

## P1.8 Settings & provisioners (declared in `plugin.json`)

```json
"provisioners": [
    {
        "key": "git_binary",
        "label": "git and git-http-backend",
        "details": "The git CLI must be installed, and its git-http-backend CGI (used to serve smart-HTTP) must be present under git --exec-path.",
        "check": { "type": "code", "call": "GitHostingHealth::checkGitBinary" },
        "script": "provisioning/install_git_hosting.sh"
    },
    {
        "key": "repo_store",
        "label": "Bare repository store directory",
        "details": "The repo store (git_hosting_repo_storage_path, or {site_root}/git_repositories) must exist and be writable by the web-server user.",
        "check": { "type": "code", "call": "GitHostingHealth::checkRepoStore" },
        "script": "provisioning/install_git_hosting.sh"
    }
],
"settings": [
    { "name": "git_hosting_enabled", "default": "1" },
    { "name": "git_hosting_repo_storage_path", "default": "" },
    { "name": "git_hosting_clone_host", "default": "" }
]
```

`git_hosting_repo_storage_path` empty → derive `{site_root}/git_repositories`.
`git_hosting_clone_host` empty → derive from the request host. Both overridable per box.

The two `code` checks live on `GitHostingHealth` (P1.10), mirroring
`InboundEmailHealth`'s convention: each is side-effect-free, time-bounded, and throws
`ProvisioningCheckFailed` with a clean message on failure. Both point at the same
idempotent installer so a failed check renders a "Fix" action in the provisioning UI.

## P1.9 Plugin layout

```
plugins/git_hosting/
  plugin.json
  data/git_repo_class.php
  includes/GitHttpBackend.php      # proc_open bridge to git-http-backend
  includes/GitWorkingCopy.php      # status/commit/push/pull/tag/log/diff helpers
  includes/GitHostingHealth.php    # provisioning check methods (code checks)
  admin/admin_git_repos.php        # repositories dashboard
  admin/admin_git_repo.php         # per-repo Version Control page
  logic/...                        # logic files for the above
  provisioning/install_git_hosting.sh   # idempotent host installer
  docs/overview.md                 # developer doc
```

## P1.10 Host prerequisites & provisioning

### `GitHostingHealth` check methods (`includes/GitHostingHealth.php`)

Same shape as `InboundEmailHealth` (`plugins/inbound_email/includes/InboundEmailHealth.php`):
public static, side-effect-free, throw `ProvisioningCheckFailed` on failure.

- **`checkGitBinary()`** — confirm `git` is on `PATH` (`git --version`), then confirm the
  `git-http-backend` CGI exists under `git --exec-path` (typically
  `/usr/lib/git-core/git-http-backend`). Throw with a clean message naming the missing piece.
- **`checkRepoStore()`** — resolve the store path (setting or `{site_root}/git_repositories`),
  confirm it exists and `is_writable()` for the web-server user. Throw otherwise.

### `provisioning/install_git_hosting.sh`

Idempotent host installer in the same style as `install_email.sh` (versioned header, safe to
re-run). It installs git and creates/permissions the store. The provisioning UI runs it as
the "Fix" action when either check fails.

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

### Plugin install path

Standard plugin lifecycle — activation goes through PluginManager (the admin Plugins page),
never the Plugin model directly. After activation, run the host installer / re-run checks:

```bash
# Sync the plugin's tables after install/schema changes (admin Plugins page
# "Sync with Filesystem", or from the CLI):
php /var/www/html/joinerytest/public_html/utils/update_database.php

# Run the host installer (or click "Fix" on a failed provisioner check in the admin UI):
sudo bash /var/www/html/joinerytest/public_html/plugins/git_hosting/provisioning/install_git_hosting.sh
```

## Phase 1 acceptance criteria

Using a throwaway test plugin `git_hosting_demo` (created fresh under `/plugins/`, marked
`included_in_publish: false`) — i.e. exercising the normal-dev path, no existing plugin
touched:

1. **Create repo via UI** → bare repo appears in the store; the test plugin's working copy
   gets `git init` + a wired remote.
2. **Edit → status → commit → push via UI** → UI shows dirty, the commit lands, push
   succeeds to the bare repo.
3. **External clone with API-key auth** → `git clone https://host/git/{user}/git_hosting_demo.git`
   (Basic auth: public key / secret) succeeds and shows the commit; a commit pushed from that
   clone is brought in by a UI **pull**.
4. **Tag / history / diff / rollback via UI** all behave correctly.
5. **Auth/ACL** → wrong/expired key → 401; a repo owned by another non-admin user → 403.
6. **Publish safety** → a publish run with a `/plugins/*/.git` present produces archives that
   contain no `.git` (verifies P1.7).

---

# Phase 2 — Migrate existing plugins/themes out of the monorepo

Maintainer-only. Runs only after Phase 1 passes. Normal devs never do any of this.

## P2.1 Extract each plugin/theme with history

Use `git filter-repo` (or `git subtree split`) to carve `plugins/myplugin` into its own repo
preserving its commits; push it to its new hosted repo (via the Phase 1 host); then
`git rm -r` it from the core repo.

## P2.2 Ignore the directories once, not per-plugin

Add `/plugins/*` and `/theme/*` to the core repo's `.gitignore`, with negations only for any
plugins/themes deliberately kept *in* the monorepo as core. This is the decided-once
structural choice — no incremental per-plugin gitignore churn.

## P2.3 Fresh-box setup uses the manifest

Extend the existing dev-build path
(`maintenance_scripts/install_tools/build_dev_from_source.sh`) to clone each repo from its
`ghr_target_path` into place. The manifest is just the `GitRepo` rows that have a target path
— no separate manifest file to maintain.

## P2.4 Structure choice (recorded)

**Independent repos + manifest**, chosen over **git submodules**: submodules would make
`.gitmodules` the manifest and pin exact plugin versions in a core commit, but at the cost of
detached-HEAD friction and a double-commit every time a plugin advances — friction that lands
squarely on the develop-a-plugin loop this feature exists to make easy. The release system
already versions plugins independently (per-plugin `version` + per-plugin archives), so
core-commit pinning buys nothing here.

## Phase 2 acceptance criteria

1. **Pilot first** — migrate one low-risk plugin; its new repo's `git log` matches the
   history that was in the monorepo.
2. **Core repo clean** — after extraction the core repo no longer tracks the pilot dir;
   `.gitignore` covers `/plugins/*` and `/theme/*`; `git status` at the root is clean and
   does not warn about embedded repos.
3. **Fresh-box reconstruction** — a clean dev box runs the build script and ends with the
   full working tree present (all manifest repos cloned into place) and the site running.
4. **Byte-equivalent release** — a publish run after migration produces archives equivalent
   to a pre-migration run; `upgrade.php` consumers are unaffected.
5. **Roll out the rest** — repeat extraction for the remaining plugins/themes.

---

## Documentation

- New developer doc `plugins/git_hosting/docs/overview.md` (Phase 1): the dev loop (create
  repo → commit/push via UI or CLI), the repo store layout, and API-key auth for clone/push.
  Add the maintainer migration to it in Phase 2. Add its index line to the docs list via the
  admin "Internal CLAUDE.md" record (CLAUDE.md is regenerated from `agf_agent_files` — never
  edited on disk).
- The publish-pipeline `.git` exclusion (P1.7) is reflected in the Deploy/Upgrade doc.

## Open items to confirm

1. **Version Control UI placement** — Phase 1 ships dedicated plugin admin pages. Deeper
   injection of a "Version Control" tab into the core `/admin/admin_plugins` and themes pages
   depends on whether a plugin-injected-admin-tab mechanism exists; confirm or treat as a
   follow-up rather than hand-rolling page edits.
2. **First Phase-2 extraction target** — which existing plugin to pilot.

## Version

Plugin `version`: `1.0.0` (initial).
