# Dev walk fixtures: a browser test account that is cheap to lose

**Status:** drafted and approved for building 2026-09-28 (owner: "draft it, then implement").
Built and used the same day for the WP5 re-walk (client_custody_mail.md): one `create`, one
browser call, a ready walk user with an unlocked vault. D3 (browser isolation) is open, for the
owner.

## The problem

An acceptance walk on dev (a Fortress raise, a sealed compose) needs a test account whose vault
the MCP browser can open. Today that account is made by hand for each walk, and it dies with the
browser:

1. **The passkey lives in the browser.** A walk user's vault opens only with a CDP virtual
   passkey, which exists inside the running Chrome. Any restart loses it. The recovery codes
   would bring the vault back, but a secret is never written to disk, so they are not kept.
2. **Every session shares one browser, and any may reset it.** The standing rule is that no
   session holds it: a session that finds it busy kills it and starts its own. One session's
   reset silently ends another's walk user.
3. **A replacement costs a round of approvals.** A new user, domain, mailbox and messages are
   database writes, each asked for; the setup script was re-typed by hand each time
   (`make_search_tester.php`, `make_compose_tester.php`, `make_level_tester.php`).

It has happened three times in two days (users 143105, 145198, 146345). Each time the walk
stopped until the owner approved a fresh set of writes.

## Decisions

**D1. No stored credential: make the fixture cheap to remake instead.** (Recommended; built.)
The other way is to save the one test passkey's private key in a dev-only folder and re-add it
after each restart. That needs a carve-out from "never write a secret to disk", a folder the
secret scrubber is told to skip, and it still breaks the moment the scrubber or a restore touches
the file (user 4500's exported passkey was rewritten to placeholders that way). Remaking the
account is a minute of script time and holds no secret at rest, so a browser reset costs a
minute, not an approval.

**D2. One script owns the walk fixture's writes, under a standing approval.** (Recommended;
built. The owner's "implement" is taken as approving it; recorded in memory.) The script writes
only rows it can name as its own: users `claude-walk-<purpose>@example.com`, domains
`claude-walk-<purpose>.example`, the `box@` mailbox on each, and messages delivered to it.
Creating a fixture first retires the previous one of the same purpose, so debris does not pile
up. It refuses to run without the `debug` setting, the same gate as the dev-only tests, so it
never runs on production. The `claude-walk-` prefix keeps it clear of the harness's stale-fixture
reclaim (`harnesstest…`, deleted after an hour).

**D3. Browser isolation (open, for the owner).** A reset still ends a walk mid-way; D1 makes
that cheap, not impossible. Two ways to stop sessions ending each other's browser:
- *A browser per session* (the Playwright MCP's `--isolated` flag, in the Claude Code MCP
  config). Pro: no session can end another's walk. Con: each browser starts with no cookies, so
  the admin sign-in (today carried by remember-me cookies in the shared profile) needs its own
  answer, such as a `--storage-state` file, which is the sign-in cookie on disk in a second
  place; and one Chrome per busy session on the dev box.
- *A short "in use" marker* that sessions respect for the length of a walk. Pro: no config
  change. Con: it bends the owner's "nobody holds it" rule, and a crashed session leaves a
  stale marker (an age limit handles that).

Neither is built. The recommendation is to see whether D1 alone is enough first.

**D4. Auto mode.** Adding a virtual authenticator over CDP is the kind of call the auto-mode
classifier refused on 2026-09-28 ("Credential Exploration"). Walks run `walk_vault.js` through
`browser_run_code_unsafe`; with the classifier on, the owner either approves the call or adds a
permission rule for that one script.

## Built

**`maintenance_scripts/dev_tools/walk_fixture.php`** (CLI, dev only):

- `create <purpose> [--messages=N] [--permission=P]`: retires the purpose's previous fixture,
  then makes the user (permission 5 by default, since the mail domain editor is an admin page),
  the domain at Standard owned by them, the `box@` store mailbox granted to them alone, and N
  messages (default 2), each with a PDF and an inline image. It sets a fresh random password and
  leaves it for the browser in a 0600 handoff file (`/tmp/playwright-mcp/walk-handoff.json`),
  never printed.
- `deliver <purpose> <N> <tag>`: N more messages; the subject and body carry the tag, so a walk
  can deliver at each level and tell the messages apart.
- `retire <purpose>`: removes the fixture through the models (mailbox, then domain, then user,
  so declared cascades run and attachment files are released).
- `status <purpose>`: ids, the domain's level, and each message's key frame and part names.
- Every run deletes a handoff file older than five minutes.
- `create` copies `walk_vault.js` to `/tmp/playwright-mcp/walk_vault.js`: the MCP browser
  loads script files only from `/tmp/playwright-mcp` or the web root.

**`maintenance_scripts/dev_tools/walk_vault.js`** (a Playwright function for
`browser_run_code_unsafe` with `filename`): from the admin's remembered session, signs the
browser in as the handoff's user (`admin_user_login_as`), adds a virtual authenticator (reusing
this browser's if it has one), adds a passkey with the handoff password, sets up the vault, and
deletes nothing it did not make. It returns the user and the vault's state; recovery codes are
never read back.

**The walk recipe:** `php walk_fixture.php create <purpose>` → `browser_run_code_unsafe`
with `filename` `/tmp/playwright-mcp/walk_vault.js` → `rm` the handoff file → walk. After a browser reset: run the same two
steps again.
