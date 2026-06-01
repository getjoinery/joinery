# Dev Workstation Migration — Runbook

How to move the **local development environment** (this box) to a new host — Hetzner
or any other provider. This is deliberately separate from migrating the *application*:
the Server Manager `install_node` / `restore_project` jobs handle the app (database,
project files, Apache config), and they have **zero awareness** of the developer
environment described here. That part is a manual `rsync` + reinstall, documented below.

## Scope: what this covers vs. what the app tooling covers

| Concern | Owner | Notes |
|---|---|---|
| Site DB + project files + Apache vhost | Server Manager (`install_node` from-backup, `restore_project`) | Push-button; see `plugins/server_manager/docs/overview.md` |
| PHP/Apache stack tuning (mpm_event + php-fpm ondemand) | Manual | Dev box runs a RAM-optimized stack the standard installer does not reproduce |
| Claude Code / Gemini state, history, memory | **This runbook** | Manual rsync — path-keyed, see below |
| Symlinks, theme working dirs, secrets, side repos | **This runbook** | Manual rsync — paths must match |
| CLIs, Playwright browsers, Go/Node/PHP toolchains | **This runbook** | Reinstall, not rsync |

## The hard constraint: keep username and absolute paths identical

Two categories of state are keyed to absolute paths. If the new host uses the same
username (`user1`), same home (`/home/user1`), and same project path
(`/var/www/html/joinerytest`), everything resolves automatically. Diverge and you lose
history association and break symlinks.

1. **Claude/Gemini history is path-keyed.** Conversation history lives in
   `~/.claude/projects/-var-www-html-joinerytest-public-html/` — the directory name is
   the escaped absolute project path. Same for `~/.gemini/history`. The auto-memory dir
   lives under that path-keyed directory too. Move the project elsewhere and the history
   + memory silently stop associating with the project.

2. **Two absolute symlinks** point from the project into the home dir:
   - `public_html/theme-sources` → `/home/user1/theme-sources`
   - `public_html/.claude` → `/home/user1/joinery/joinery-claude`

   `rsync -aH` preserves them as symlinks; the targets land at the same absolute path
   only if you copy the whole home dir. Recreating the same paths is what makes this work.

## Step 1 — Provision the Hetzner box

Server Manager does **not** create VMs (no Hetzner Cloud API integration). Rent the box
manually:

1. Create an Ubuntu 24.04 server on Hetzner Cloud.
2. Inject your SSH public key during creation.
3. Create the `user1` account with home `/home/user1` (matching the source) and sudo.
4. Confirm SSH access as `user1`.

## Step 2 — rsync the home directory

Run from the **source** box (out-of-band for secrets — do not echo key contents). Use
archive + hardlinks + ACLs/xattrs, and preserve symlinks as-is:

```bash
rsync -aHAX --info=progress2 \
  /home/user1/ user1@NEW_HOST:/home/user1/
```

This carries, in one shot:

- **Claude Code state** — `~/.claude/` (`projects/` history ~193M, `file-history/` ~46M,
  `conversations/`, `history.jsonl`, `commands/`, `plugins/`, `tasks/`, settings, memory
  dir) and `~/.claude.json` (global config + MCP server definitions).
- **Gemini state** — `~/.gemini/` (settings, history, `projects.json`, oauth creds).
- **Side repos / working dirs** (each its own git repo): `~/joinery-agent` (Go agent
  source), `~/scrolldaddy-dns`, `~/urbitproject`, `~/theme-sources` (canvas / falcon /
  linka / typology, both commercial sources and the `-html5` conversions),
  `~/joinery/joinery-claude` (per-project Claude settings, the `.claude` symlink target).
- **Shell/git state** — `.bashrc`, `.profile`, `.gitconfig`, `.bash_history`.

### Secrets carried by the rsync — verify they arrived, keep them private

These do **not** regenerate; several are high-blast-radius. Confirm presence after sync;
never print their contents into a transcript.

- `~/.ssh/` — keys incl. `id_ed25519_claude` (encrypted B2 backup exists; see Claude memory).
- `~/.joinery_backup_key` — **AES key for the encrypted `*.sql.gz.enc` backups in home.
  Lose it and those dumps are unrecoverable.**
- `~/.claude/.credentials.json`, `~/.gemini/oauth_creds.json` — auth tokens.
- `~/.gnupg/`, `~/.pki/`, `~/.npmrc`.

Optional bulk data you may skip to save transfer: `~/joinerytest-*.sql.gz.enc` and
`~/joinery-install-sql.sql` (~150M of dumps; only useful with `~/.joinery_backup_key`).

## Step 3 — rsync the project tree

```bash
rsync -aHAX --info=progress2 \
  /var/www/html/joinerytest/ user1@NEW_HOST:/var/www/html/joinerytest/
```

Preserves the `.git`, the working tree, and the two in-project symlinks (which now
resolve because Step 2 placed their targets at the matching absolute paths). Fix
ownership if Apache runs as `www-data`:

```bash
sudo chown -R www-data:user1 /var/www/html/joinerytest
```

> Alternatively, migrate the *app* via Server Manager `restore_project` and use this
> runbook only for the home dir. Either works; rsync of the tree is simplest when you
> control both boxes directly.

## Step 4 — reinstall toolchains (not captured by rsync)

Binaries live outside dotfiles and must be reinstalled on the new host:

- **Node + npm** and the **Claude Code CLI** and **Gemini CLI** (`~/.npm-global` on PATH).
- **Playwright browser binaries** — the `browser`/`playwright` MCP needs
  `npx playwright install` (dotfiles hold only config, not the Chromium binary).
- **Go toolchain** (for `joinery-agent`, `scrolldaddy-dns` builds).
- **PHP + composer + psql client**.
- **Dev Apache/PHP stack** — reapply mpm_event + php-fpm ondemand if you want the dev
  box's RAM profile (the standard installer produces prefork + mod_php). See Claude
  memory `reference_dev_apache_php_fpm.md`.

## Step 5 — re-auth interactive sessions

- **MCP OAuth servers** (`claude_ai` Gmail / Drive / Calendar) re-authenticate
  interactively on first use; the cached tokens won't transfer cleanly.
- Confirm `~/.claude/.credentials.json` still validates; re-login if not.
- Gemini `oauth_creds.json` likewise may need a refresh.

## Step 6 — verify

- `claude` launches in `/var/www/html/joinerytest/public_html` and **prior conversation
  history + auto-memory appear** (proves the path-keyed dirs landed correctly).
- Both in-project symlinks resolve: `ls -l public_html/theme-sources public_html/.claude`.
- `~/.joinery_backup_key` present and a test `*.enc` dump decrypts.
- Side repos build (`go build` in `joinery-agent`, `scrolldaddy-dns`).
- Browser MCP drives a page (Playwright browsers installed).
- App serves (handled by the app-migration path, not this runbook).

## Difficulty summary

- **App migration:** push-button (Server Manager).
- **Dev workstation migration:** one `rsync -aHAX` of `/home/user1`, one of the project
  tree, then reinstall CLIs/Playwright/toolchains and re-auth OAuth — **easy, provided
  username and absolute paths are kept identical.** All real risk is in that proviso:
  path-keyed history, the two absolute symlinks, and not losing `~/.joinery_backup_key`.
