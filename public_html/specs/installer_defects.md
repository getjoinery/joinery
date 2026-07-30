# Installer Defects — Live Faults in the Published Install Path

**Status:** Unbuilt. Every item here is a defect in shipped behavior, not a
new feature, and each is independently fixable.

**Why this spec exists separately:** these were found while writing
`specs/linode_stackscript.md`, where they appear as Gaps 1, 3, and 7 because
that spec has to work around them. They are recorded here as well so they do
not depend on the Linode work being scheduled — they affect anyone following
`docs/quickstart.md` today. Whichever spec is built first should fix them; the
other then drops its workaround.

## 1 — A domain whose DNS is not ready aborts the install

**Severity: high.** This is the first thing a new user hits.

`install.sh site` runs an early DNS check and, when a real domain does not
resolve to this machine and no Cloudflare proxy is detected, **exits before
doing any work** (`install.sh:2131`, comment: "Early DNS validation - fail
before doing any work"). There is no `-y` bypass; only `--no-ssl`, a matching
A record, or detected Cloudflare gets past it.

`docs/quickstart.md` tells readers the opposite:

> **If DNS hasn't propagated yet:** the SSL step will be skipped
> automatically, and the installer will print instructions for running it
> manually once DNS is ready. Your site will still be accessible over HTTP in
> the meantime.

None of that happens. The install stops. A first-time user who followed the
guide in order — buy a domain, add the A record, install immediately — meets a
hard failure at the exact moment they are least equipped to diagnose it.

**The gate is protecting nothing.** `provision_origin_cert` is already
failure-tolerant: HTTP-01, then DNS-01, then give up and return success. The
bare-metal vhost template guards its `:443` block with `<IfFile>`, so with no
cert the HTTPS vhost does not activate and the site serves HTTP. "No cert yet"
is handled gracefully everywhere downstream. The early gate predates that
tolerance and is the only thing turning it into "no site at all."

No caller depends on it as a precondition check either — the managed path
always passes `--no-ssl` (`JobCommandBuilder.php:1908`, "DNS typically not yet
pointing here") and never reaches the gate. The only people who hit it are
humans following `docs/quickstart.md`.

**Fix:** delete the abort at `install.sh:2131`; keep the warning and the
guidance it already prints, and let the install continue. The site comes up on
HTTP, `provision_origin_cert` declines to issue, and the closing message tells
the user to re-run `sysadmin_tools/setup_ssl.sh <domain>` once DNS resolves.
That is exactly what `docs/quickstart.md` already promises, so the doc needs no
change beyond naming the command.

*Considered and rejected:* a `--require-ssl` flag preserving the hard fail for
callers that must have TLS. Nothing wants it — the failure it would guard
against now yields a working HTTP site rather than a broken one — and an unused
branch is another thing to keep true.

## 2 — Server setup orphans SSH access

**Severity: high**, and silent until the operator tries to log back in.

`install.sh server` sets `PermitRootLogin no` (`install.sh:1697`), while
`user1` is created with no password, no `authorized_keys`, and no sudo
(`install.sh:1436-1453` — `usermod -aG www-data` is the only group grant).

The managed path survives this only because the control plane pre-stages
user1's key *before* running server setup (the "Pre-stage user1 for managed
access" step in `JobCommandBuilder::build_install_node`). Anyone running the
installer by hand has no such step. On a key-only server the operator's own
key stops working partway through the run, leaving only the provider's serial
console.

**The control plane already solved this, one layer too high.**
`JobCommandBuilder.php:1853` ("Pre-stage user1 for managed access") refuses to
continue when root's `authorized_keys` is empty — its own message says
"Aborting before install.sh server locks out root SSH" — then copies root's
keys to user1 and grants `user1 ALL=(ALL:ALL) NOPASSWD: ALL`. Managed nodes are
safe; everyone else gets the unguarded script.

**Fix:** move that logic into `install.sh server` so it derives the reachable
account itself, with no new arguments. The published one-liner is then fixed
for someone who reads nothing, which is the population that gets hurt — flags
only help people who already know the trap exists.

- Running as root with a non-empty root `authorized_keys` → copy those keys to
  user1, grant sudo, then apply `PermitRootLogin no`.
- Running under `sudo` from a normal account (the AWS/DO `ubuntu` pattern) →
  `SUDO_USER` already holds a key and sudo, so disabling root login orphans
  nobody. Apply it.
- Neither → leave `PermitRootLogin` alone and print the remedy.

**Scope note: only one directive is conditional.** `MaxAuthTries`,
`PermitEmptyPasswords`, the `ClientAlive` timeouts, and the whole fail2ban/ufw
block cannot lock anyone out and always apply. The skipped case is
`PermitRootLogin no` alone.

**The one unhardened outcome** is reaching the box as root by *password* with no
root key installed — Linode's default when no SSH key is attached at create
time. That box keeps root password login, but still gets fail2ban,
`MaxAuthTries 3`, and empty passwords refused. The remedy already exists:
`install.sh host-harden` (`:1150`) refuses to run without a non-empty
`authorized_keys`, then disables password auth and sets `PermitRootLogin
prohibit-password`. The skip message points at it, turning a lockout into a
two-step.

**Same edit, while we are in there:** `install.sh server` actively enables
password auth (`sed 's/#PasswordAuthentication yes/PasswordAuthentication yes/'`,
`:1699`). Combined with root login disabled and user1 holding no password, that
leaves password authentication on with no account able to use it — open surface,
no benefit. Drop it.

Eventually the control plane's pre-stage step can collapse into the installer
rather than two copies of the same logic drifting apart.

## 3 — A forgotten admin password is unrecoverable

**Severity: medium-high** on any fresh deployment, which is all of them.

There is no CLI admin password reset anywhere in `utils/` or
`maintenance_scripts/`. Recovery flows in `logic/` are email-based apart from
a passkey route that a day-one user has not configured. A fresh install has no
mail provider, and on Linode a local MTA cannot deliver — outbound port 25 is
blocked at the account level (established in
`specs/step8_email_stack_activation.md`).

So: fresh install, owner sets a password at the forced first-login change,
forgets it, and the only route back in is editing Postgres by hand.

**Fix:** a root-only CLI password reset. Small — the pieces exist already:
`User::GeneratePassword()` (argon2id), the `usr_force_password_change` field,
and an existing method that clears TOTP and rotates the second-factor key
(`data/users_class.php:777`).

**It goes in `maintenance_scripts/sysadmin_tools/`, not `utils/`.** `/utils/<name>`
is web-routable and `RouteHelper` (`:540-575`) applies no permission check of
its own — every script in there self-guards. A password reset placed there
would be one forgotten `check_permission()` away from an internet-facing
account takeover. `sysadmin_tools/` is outside the web root entirely; add a
`PHP_SAPI === 'cli'` guard as a second line.

**Second factor is cleared only on an explicit `--clear-second-factor`.**
Someone who lost a password may have lost the TOTP device with it — same phone,
same laptop — so a password-only reset can leave them stopped at the code
prompt, which is not recovery. But it stays opt-in. Being honest about what the
flag is: root on the box can already do this in Postgres, so it is not a
security boundary, only a way to keep the destructive half deliberate rather
than a side effect of a routine password change.

Remaining shape, at the obvious defaults: the password is read from a prompt or
`--password-file`, never a positional argument (it lands in shell history and
`ps` otherwise); `--email=` selects the account, defaulting to the sole
permission-10 user when there is exactly one; `usr_force_password_change` is set
so the typed password does not become permanent; use is logged.

## 4 — The release archive ships without its license

**Severity: low as a defect, higher as an obligation.**

`publish_upgrade.php` assembles the archive from three explicit roots —
`public_html/`, `config/`, `maintenance_scripts/` (`:297-299`) — and
`LICENSE.md` sits at the repo root as a sibling of those, so nothing collects
it. There is no ignore rule to remove; a copy has to be added. Every install
performed by the published one-liner lacks the license text entirely.

**This does not wait on the licensing decision.** Shipping a license file is
correct under any license; `specs/open_core_licensing.md` decides the file's
*contents*, not whether one accompanies the code. Build the packaging now
against the current PolyForm text, and the open-core work then changes nothing
but the file itself.

**Fix — one copy plus one guard:**

Publish copies `LICENSE.md` from the repo root into `public_html/` in the
archive, never reading it. That is the content-agnostic half. The guard is what
keeps it from being quietly broken again: publish refuses to build when
`LICENSE.md` is missing or empty. It belongs in the same preflight as the
existing integrity guard that refuses a publish owing an agent rebuild.

**It goes in `public_html/`, not the archive root.** `upgrade.php` deploys only
two things from a staged archive — it swaps `public_html` and rsyncs
`maintenance_scripts` (`:1043-1071`). Nothing else at the archive root reaches
an existing site. A root-level `LICENSE.md` would be laid down by `install.sh`
at first install and never updated again, so every upgraded site would keep
whatever license it was born with — precisely the case the open-core work
breaks. `public_html/LICENSE.md` is carried by the existing rsync with no new
exclusion, deployed by the existing swap, refreshed on every upgrade, and sits
in the served tree if it should later be linked. No new deploy logic.

The canonical copy stays at the repo root where GitHub and license scanners
expect it; publish copies it inward. One source, no drift.

**Deferred to `specs/open_core_licensing.md`:** once core and paid plugins can
carry different licenses, the rule generalizes to "every published artifact
carries a license at its root," and `publish_theme.php` needs the same copy and
guard. Enforcing that today would block publishing every existing plugin, since
none carry a `LICENSE.md` yet — so it is stated as a rule and inherited, not
built here.

## 5 — The seeded admin credential is public knowledge

**Severity: low while installs are supervised, high the moment they are not.**

Fresh installs seed `admin@example.com` / `changeme123`, mitigated by
`usr_force_password_change`.

**Severity is higher than "well-known default."** `views/index.php:96-100` —
the default homepage of a fresh install — renders both values to any anonymous
visitor in an "Admin Access" card. An attacker does not have to know the
default; a fresh site publishes it on its front page until the owner replaces
the homepage. That window opens the moment Apache starts, attended or not, so
this is not confined to unattended installs and is owned here rather than by
`specs/linode_stackscript.md`.

It also means the two halves must land together. Randomizing the seeded
password alone would leave the homepage advertising `changeme123` — now simply
wrong, with the owner staring at a password that does not work.

**Fix, split by install path**, because the delivery problem differs and the
one-click owner may never open a terminal:

- **Terminal install** (the `docs/quickstart.md` one-liner): the operator is at
  a shell by definition. Generate a random admin password, apply it with
  `usr_force_password_change` left on, print it, and write it to a mode-600
  credentials file.
- **One-click install** (StackScript / Marketplace): the deploy form collects
  the admin password as a masked user-defined field, so the owner logs in with
  credentials they chose before the instance existed. No file to read, no
  terminal, no default at any point. Akamai requires deployment with no
  command-line intervention, so a credentials file on disk does not satisfy
  that path.

Either way the literal leaves `views/index.php`, replaced by a pointer to
whichever route applied.

**Amendment this forces on `specs/linode_stackscript.md`:** admin password
becomes a second **required** user-defined field. Optional-and-blank would fall
back to a file on disk and strand exactly the non-CLI owner the one-click path
exists to serve.

**Accepted residual:** a UDF password reaches the instance as an environment
variable and can land in cloud-init logs on that box. The disclosure is to
someone who already holds root on the owner's own server. It is the reason
`usr_force_password_change` stays on even when the owner chose the password.

While that block is open: it carries `alert alert-info`, `btn btn-primary`, and
`d-flex` — Bootstrap classes in a base view. Clean them in the same edit rather
than tracking separately.

## Interaction with the multi-distro refactor

`specs/linode_stackscript.md` decided that `install.sh` should hard-fail on
anything but Ubuntu 24.04 (with an `--allow-unsupported-os` override) rather
than warn and continue, since it hardcodes PHP 8.3 paths and continuing yields
a half-configured box.

That is deliberately a "for now" decision. `specs/multi_distro_install_refactor.md`
(deferred, 2026-07-16) aims at installing on any mainstream distro, and when
that is picked up the hard-fail becomes a per-distro capability check instead
of a version equality test. Fixing this defect list does not conflict with
that spec, but the OS gate is the one piece the refactor will revisit.

## Testing

Each fix wants a check that survives it:

- A `safe`-tier assertion that `install.sh`'s DNS-not-ready path and the
  quickstart's description of it agree — the failure mode here was a doc and a
  behavior drifting apart, and only a test notices that a second time.
- A `db`-tier check that the password reset tool sets a working password with
  `usr_force_password_change` on, refuses to run outside CLI SAPI, and leaves
  the second factor intact unless `--clear-second-factor` was passed.
- A `safe`-tier check that no view renders a credential literal — the homepage
  card is the known instance, and the point is to notice the next one.
- A publish-preflight check that refuses to build an archive without a
  non-empty `LICENSE.md`, plus an assertion that the built archive contains
  `public_html/LICENSE.md` (archive-root placement would pass a naive
  "is it in the tarball" test while never reaching an upgraded site).
- The SSH item is verified live: run `install.sh server` on a key-only box and
  confirm the operator's key still authenticates afterward. Cover the three
  branches — root with a key, `sudo` from a normal account, and root by
  password with no key (which must decline to touch `PermitRootLogin` and print
  the `host-harden` remedy).

## Documentation

- `docs/quickstart.md` — the DNS/SSL claim becomes true once the abort is
  removed, so it needs only the `sysadmin_tools/setup_ssl.sh` command named.
  Also drop any first-login credential text: there is no longer a password to
  print, and the homepage no longer shows one.
- `docs/installation.md` — how `install.sh server` derives the reachable
  account, the one case where it declines to disable root login, and the
  `host-harden` follow-up for that case.
- `docs/account_security.md` — the CLI password reset: what it does, that it is
  CLI-only and lives outside the web root, what `--clear-second-factor` does and
  why it is opt-in, and that its use is logged.
