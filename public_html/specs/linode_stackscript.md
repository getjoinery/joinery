# Linode StackScript — Self-Installing Joinery on Akamai Cloud

**Status:** Built 2026-07-30, awaiting the live gates.

Phases 0, 1 and 2 are implemented; Gaps 3, 4, 5, 6 and 7 are closed in code, as
are the timezone, bundle, admin-email and email-default items. `safe` tier green
(71/71); `tests/unit/installer_contract_test.php` grew 45 checks covering every
decision here. What is left is what only a real instance can answer: live gates
A, B and C below, and Phase 3, which is a publishing action rather than code.

Two things were verified by construction rather than by running them, and are
called out so nobody assumes otherwise. The retry timer's units were never
installed on a live box — the retry script itself was extracted and exercised,
including both directions of the self-signed-versus-CA check that decides when
it stops. And `install_bundle.php` was run only in `--dry-run` here, because
dev's `mailbox` and `joinery_ai` rows are `stale` and a real run would have
changed their status.
**Companion:** `specs/linode_quick_deploy_app.md` turns this into a public
Marketplace listing. That spec depends on this one; this one stands alone and
is worth building even if the listing never happens.
**Licensing:** out of scope here — see `specs/open_core_licensing.md`.

**Images and Clone Linode were evaluated and rejected** (owner decision,
2026-07-30). Custom images are private to the account that owns them with no
cross-account sharing, so a buyer's account can never see ours — they are not
a distribution channel under any configuration. They are also frozen at
capture, so every release makes them staler. Not revisited.

## What this does for the user

A person who wants a Joinery site picks it from a menu in the Akamai Cloud
control panel, fills in a domain and an email, clicks Create, and a few
minutes later has a running site with SSL and a login they own. No terminal,
no SSH, no pasted install command, no discovering on their own that DNS has to
propagate before SSL works.

The install already does all of this. The friction is entirely in reaching it.

## Scope: standalone only (v1)

**No control plane integration in v1** (owner decision, 2026-07-30). An
instance built by this script is the deployer's, full stop: no agent
installed, no node registered, no enrollment, no outbound call of any kind
beyond fetching the release archive and whatever the site itself does
afterward. There is no field that could opt someone into being managed, so
nobody can be opted in by accident or by anyone else.

Using the same first-boot path for customer-cloud provisioning — passing
`stackscript_id`/`stackscript_data` or `metadata.user_data` through
`LinodeComputeDriver::createInstance` so buyer instances self-install instead
of being installed over SSH by the control plane — is deferred past v1. It is
recorded at the end of this spec so the option stays open, and the standalone
design deliberately does not foreclose it.

## What already exists

- `docs/quickstart.md` publishes the exact command this needs: fetch
  `utils/latest_release`, untar, `install.sh server`, `install.sh site`.
- `install.sh` is fully non-interactive under `-y`; the site path takes
  sitename, domain, and port positionally.
- `utils/latest_release.php` serves an installable archive to anonymous
  callers and chains upstream when the serving site isn't the publisher.
- Measured duration from `mjb_management_jobs` (`install_node`): **4–6 minutes**
  fresh, ~1.5 minutes when dependencies are already present.
- `LinodeComputeDriver` (API v4), a Linode DNS driver, and the customer-cloud
  pipeline that creates instances in a buyer's own account.

## Design rule: thin wrapper, fat repo

Everything the StackScript can delegate, it delegates. The script hosted at
Linode reads its user-defined fields, exports them as environment variables,
fetches the release archive, and hands off to
`maintenance_scripts/install_tools/linode_stackscript.sh` inside that archive.
Nothing else.

Two reasons, and the second is the one that bites later:

1. **Self-updating.** The archive carries `install.sh` and the handoff script,
   so both improve with every publish. An instance created tomorrow installs
   what you published this morning, with no action on the Linode side.
2. **The Marketplace variant is expensive to change.** Once this becomes a
   listing, the Linode-side script lives in Akamai's repository and every edit
   is a pull request and a review cycle rather than a deploy. A wrapper that
   contains logic means every fix to that logic waits on review. A wrapper
   that contains a handoff never needs touching unless the field set changes.

**No version pinning on the production path.** `latest_release` gains an
optional version parameter so a specific build can be reproduced for testing
and for the Marketplace review, but the script ships "latest". A pinned script
needs a bump every publish, and a stale pin is worse than no pin at all.

## User-defined fields (decided)

Fields are a StackScript platform feature: declared in a comment block at the
top of the script, rendered by Linode as a form on the Create page, and passed
to the script as environment variables. `install.sh` never sees them — the
wrapper reads them and turns them into arguments. Two platform constraints:
fields work only in bash scripts, and a field is masked in the UI only if its
name contains "password", which is also what keeps a secret out of the
deployment log.

Ask as little as possible. Every field is expensive to change once a listing
exists, and each one is a chance for a stranger to abandon the form.

| Field | Required | Behavior |
|-------|----------|----------|
| Site domain | No | Blank means the site comes up on the instance's IP, which `install.sh` already handles by auto-detecting it. The SSL timer stays idle until a domain exists. |
| Admin email | **Yes** | Sets the admin account's address at install instead of leaving `admin@example.com` for the owner to change by hand — the difference between a recoverable account and a locked-out one. The seam does not exist yet and is built as part of this work: an optional `--set-email=` on `reset_admin_password.php`, `JOINERY_ADMIN_EMAIL` honoured by `_site_init.sh`, and an `--admin-email=` flag on `install.sh site`. One call sets password and address together, so there is no window where the account has a new password under the placeholder address. Unset leaves `admin@example.com`. |
| Admin password | **Yes** | *Amended 2026-07-30 (was: derived, not asked).* Password-named so it is masked and kept out of the deployment log. The owner logs in with a credential they chose before the instance existed. A generated password written to a file on disk assumes the owner can SSH in and read it — the one thing this path exists to avoid, and something Akamai's no-command-line-intervention rule forbids relying on. Optional-and-blank would fall back to that file and strand exactly the non-CLI owner, so it is required. `usr_force_password_change` stays on regardless: a UDF value reaches the instance as an environment variable and can land in cloud-init logs on the box. |
| SSH public key | No | Presence changes behavior rather than gating install, and the handoff script only has to place it: written to `/root/.ssh/authorized_keys` before `install.sh server`, which then mirrors it to `user1` with sudo and hardens root login off on its own. Blank: root login is left as Linode configured it, which is `install.sh`'s third branch. Either answer is safe, so nobody is forced to understand the question. |
| Linode API token | No | Password-named so it is masked. Only useful to deployers whose DNS is at Linode; buys first-pass SSL instead of waiting on the retry timer. |

### Install mode: bare-metal (decided)

`install.sh` requires an explicit mode. The script always passes
`--bare-metal`: Apache, PHP, and Postgres on the instance, one site per box,
certbot on the host, and the ordinary `upgrade.php` upgrade path. It is also
the mode customer-cloud provisioning already uses for buyer instances, so
phase 3 inherits it rather than diverging.

Docker mode is rejected here. It exists for the shared-host case where one box
carries several sites, which a self-serve deployer does not have, and it adds
an image build to first boot — on a 1 GB instance that risks pushing past what
a stranger will patiently watch, and it adds a failure mode (build fails, no
site, no explanation) to the least-supervised install path we have.

The accepted cost: a second site on the same instance is not easily possible.
For a one-click app, a second site is a second instance.

### Default install bundle (decided)

v1 is marketed as a self-hosted replacement for Google's suite, so the default
install is that product, not "whatever is flagged system."

**Already core, nothing to install:** Drive (`views/drive.php`,
`data/files_class.php` and siblings) and the personal Calendar
(`views/profile/calendar.php`), which carries its own native entries, .ics
import, and recurrence, and merely *also* surfaces events and bookings when
those plugins are present. De-emphasising the events toolkit does not empty the
calendar.

**Installed by default:** `mailbox` (email) and `joinery_ai` (AI).

**Not installed by default:** `event_manager` (de-emphasised for v1), `store`
(paid — see `specs/open_core_licensing.md`), `vault` (password manager, alpha),
`bookings`, `items`, `dns_filtering`, `server_manager`.

#### The mechanism (decided 2026-07-30)

An earlier draft framed this as splitting `plg_is_system` in two. That premise
was wrong. `is_system` does not mean "auto-installed" — it means *always
download these files on upgrade, even if the site does not take plugin
upgrades* (`upgrade.php::get_system_required_extensions`). It is about file
delivery, and it is set on `event_manager` and `store` only.

There is no default-install mechanism at all. A fresh site ends up with plugin
*files* present and nothing installed or activated. So the work is not
separating two meanings; it is that the second concept does not exist yet.

**Named bundles in one file.** `install_bundles.json` at the `public_html/`
root, beside `admin_menus.json` and `settings.json`, where the platform already
keeps declarative sets:

```json
{
  "personal": {
    "label": "Personal suite",
    "plugins": ["mailbox", "joinery_ai"]
  }
}
```

**`maintenance_scripts/sysadmin_tools/install_bundle.php`** does the work:
`--bundle=personal`, with `--plugins=a,b` to bypass the file entirely. An
unknown bundle name is a hard error listing what is available, rather than
installing nothing and looking successful. Already-installed plugins are
skipped, so it is safe to re-run.

The plugin layer needs nothing new. `PluginManager::install($name)` already
refreshes files from upstream, creates tables, runs migrations, and seeds
declared settings and menus, leaving the plugin `inactive`; `activate($name)`
(inherited from `AbstractExtensionManager`) turns it on. The bundle install is
a loop over those two calls.

**Not in `utils/`** — that directory is web-routable with no router-level
permission check, and a reachable endpoint that installs and activates plugins
should not depend on a self-guard. Same placement as
`reset_admin_password.php`, for the same reason.

`_site_init.sh` calls it on fresh installs only, under the same condition as
the admin password block (no clone, new database), after `update_database` has
run. The bundle name comes from an env var defaulting to `personal`, so the
StackScript can later expose it as a deploy-form dropdown with no core change.

**Flat lists, no inheritance.** Bundles are product selections, not layers. The
planned creator-platform bundle (a Patreon-style hosting product) shares nothing
with `personal` rather than extending it, so composition would never pay for the
indirection.

`store` drops out of the default set for two independent reasons — it is not in
the bundle, and it declares `requires_entitlement` — so neither decision is
load-bearing alone.

When the installer omits something, it says so and points at where to get it.
A stranger who wanted a store should learn that at install time with a link,
not infer later that the platform cannot do commerce.

#### Installed is not the same as working

Both default plugins land inert and need owner action before they do anything,
and the v1 marketing claim will be judged on whether they eventually work:

- **Mailbox** needs MX and DKIM records the deployer controls, and an outbound
  provider — Linode blocks port 25 outbound at the account level, so
  self-hosted sending is not available on this path at all. The plugin's
  topology-aware Setup tab is the right landing place, and first-run guidance
  should send people straight to it.
- **Joinery AI** needs a model provider. Local models are not viable on the
  instance sizes this listing will recommend, so in practice v1 means a cloud
  provider key the owner supplies.

Neither is a reason to drop them from the bundle — an installed, clearly
unconfigured capability with a Setup tab is far better than an absent one — but
the post-install summary must be honest about what still needs doing.

**Designing that setup experience is a separate task, out of scope here.** This
spec's only obligation is to install the bundle and state plainly what remains
unconfigured; making mail and AI pleasant to configure is its own piece of
work.

#### Plan sizing is unaffected

The bundle runs fine on the 1 GB Nanode. AI against a cloud provider is HTTP
calls with negligible memory cost, and personal-scale mail volume is
comfortable at that size. Local model hosting is a different tier entirely and
is not what v1 offers, so it does not shape the listing's recommendation.

The existing guidance in `docs/quickstart.md` — 1 GB is enough for a small
site, 2 GB if you expect real users from day one — stands unchanged.

### Failure handling: fail loudly (decided)

If a step fails, the script stops and says so rather than continuing into a
half-installed box that looks alive. The failure is reported in the deployment
log at `/var/log/stackscript.log`, and the documented remedy is to destroy the
instance and redeploy with the offending field corrected — cheap and honest at
this stage.

Not built in v1: a status file and an install-progress holding page served on
port 80. That would let a deployer diagnose a typo'd domain without a terminal
and would make support requests arrive with a cause attached, but it is real
work for a failure mode we have not yet seen at volume. Revisit if failed
installs turn into inbound "it didn't work" mail.

### Derived, not asked

- **Site name** — derived from the domain (or the instance ID when no domain
  is given). It determines the web root and database name and means nothing to
  the deployer.
- ~~**Admin password**~~ — moved to the field table above and made **required**
  on 2026-07-30. A generated password lands in a file the non-CLI owner this
  path exists for cannot read.
- **Timezone — the platform runs UTC, the seeded user displays New York.**
  `install.sh:1807` writes `date.timezone = America/New_York` into php.ini, and
  the result today is three-way inconsistent: dev web requests get New York (the
  sed ran), dev CLI gets UTC (the sed touches only the apache and fpm inis), and
  a Docker site gets UTC (the image never runs that step). A scheduled task and a
  web request on the same box already disagree about what `date()` means, so
  nothing can be depending on New York correctly. Change php.ini to UTC — it
  makes all three agree and matches the platform's doctrine that DB times are
  UTC with per-user display conversion.

  **The seeded admin user's own timezone stays `America/New_York`**
  (`create_install_sql.php:288`, decided 2026-07-30). Most deployers will be
  American, and this is the best default available without adding another
  install switch. Platform timezone and display timezone are different
  questions; only the first one is being changed.

## Integration-point inventory

| Piece | Where | What |
|-------|-------|------|
| Linode-side wrapper | StackScript body, authored once | UDF declarations plus a fetch-and-delegate. Kept under ~20 lines by construction. |
| Handoff script | new `maintenance_scripts/install_tools/linode_stackscript.sh` | The real first-boot logic. Lives in the repo so it versions with the installer it drives. |
| SSH access preservation | `install.sh` `derive_ssh_access` | Built. Server setup derives a reachable account before disabling root login. Gap 1. |
| Admin credential | `_site_init.sh` | Built. Pass the UDF password as `JOINERY_ADMIN_PASSWORD`; the site uses it instead of generating one, and writes no file. Gap 2. |
| Admin email | `reset_admin_password.php`, `_site_init.sh`, `install.sh` | New `--set-email=`, `JOINERY_ADMIN_EMAIL`, `--admin-email=`. The address is currently hardcoded in six places. Gap 7. |
| Default bundle | new `install_bundles.json`, new `sysadmin_tools/install_bundle.php` | Named plugin bundles installed and activated on fresh installs. `personal` by default. |
| Deferred SSL | new systemd timer, installed by `install.sh` when `SSL_DEFERRED` is set | Resolves the domain first, then runs the existing `sysadmin_tools/setup_ssl.sh`; disables itself on success. Core, not this path. Gap 3. |
| Upgrade test run | `utils/upgrade.php` | `safe` tier after the swap, rollback to `public_html_last` on failure. Fresh installs run nothing. Gap 6. |
| Email default | `settings.json`, `includes/EmailSender.php` | `email_service` defaults to empty; the five `?: 'mailgun'` fallbacks become an explicit unconfigured result. Gap 7. |
| DNS record creation (optional) | existing Linode DNS driver, invoked from the node | With a UDF-supplied API token, create the A record from the instance so SSL succeeds first pass. |
| Release endpoint | `utils/latest_release.php`, `install.sh` `UPGRADE_SERVER` | Production default; optional version parameter for reproducible testing. Gap 4. |
| Upgrade source seeding | `_site_init.sh` | A self-serve install currently learns nothing about where its upgrades come from. Gap 4. |
| OS pin | handoff script | Ubuntu 24.04 LTS only. Gap 5. |

## The gaps

These are the differences between "the control plane runs the installer" and
"a stranger runs the installer." Each is a build item.

Gaps 1, 2, 3, and 7 were pre-existing defects in shipped behavior rather than
anything this path introduces — they affected anyone following
`docs/quickstart.md`. They were tracked independently in
`specs/installer_defects.md` and **are now fixed in core**
(`specs/implemented/installer_defects.md`). What remains below for each is only
the part this path still owns.

### Gap 1 — server setup orphans the deployer's SSH access — RESOLVED IN CORE

`install.sh server` derives the account that survives hardening on its own
(`derive_ssh_access`): running as root with a key in `/root/.ssh/authorized_keys`
mirrors it to `user1` with passwordless sudo before disabling root login;
running under `sudo` from an ordinary account leaves that account reachable;
with neither, `PermitRootLogin` is left alone and the remedy is printed. No
flags, so it protects a deployer who reads nothing.

**Nothing left for this path.** With no SSH key UDF supplied, the instance keeps
whatever root access Linode configured, which is the third branch above.

### Gap 2 — a well-known default password on a public IP — RESOLVED IN CORE

The seeded account's password hash now has no known plaintext, `_site_init.sh`
gives every fresh site its own password, and `views/index.php` no longer prints
a credential. `JOINERY_ADMIN_PASSWORD` is the seam this path uses.

**What this path still owns:** the delivery half. The admin password is a
required user-defined field (see the field table above), passed to the install
as `JOINERY_ADMIN_PASSWORD`, so a one-click owner logs in with a credential they
chose and never has to read a file off the box. When that variable is set,
`_site_init.sh` writes no credentials file at all.

### Gap 3 — SSL has nowhere to land

A brand-new instance has no DNS pointing at it; the IP doesn't exist until the
instance does.

**The abort is gone.** `install.sh site` no longer exits when a domain's DNS
does not resolve to this box — it warns, installs on HTTP, and names
`sysadmin_tools/setup_ssl.sh` in the closing summary. `docs/quickstart.md` now
describes what actually happens.

What remains is that nothing on the node ever *retries*; that logic lives only
in the control plane's Provision Pending SSL task, and this path has no control
plane.

**The retry timer belongs in core, not in the handoff script** (decided
2026-07-30). `install.sh` already knows when DNS was not ready — that is the
`SSL_DEFERRED` flag — and today responds by printing a command for the operator
to run later. Anyone following `docs/quickstart.md` on any host has exactly the
problem the StackScript has. Installing the timer from `install.sh`, gated on
`SSL_DEFERRED`, fixes it for all of them, and leaves the handoff script needing
nothing at all for SSL.

**Fix:**

1. **`install.sh` installs a self-disabling systemd timer** when `SSL_DEFERRED`
   is set. The unit resolves the domain first and invokes
   `sysadmin_tools/setup_ssl.sh` only when the A record actually points at this
   box. On success it disables itself, so it is not a permanent resident.
2. **The DNS precheck is what makes the cadence free.** Let's Encrypt allows
   five failed validations per hostname per hour — a tight budget to spend on
   attempts that cannot succeed. A failed lookup costs nothing, so the timer can
   poll every few minutes indefinitely and the deployer who points DNS a week
   later still gets a certificate with no action taken.
3. **Install with `--no-ssl`** so the run does not spend time on a certificate
   attempt that cannot succeed on a minutes-old instance. An optimization
   rather than a requirement — without it the install still completes, just
   slower.
4. **When a Linode API token UDF is supplied:** create the A record from the
   node using the existing Linode DNS driver before the site install, so the
   timer's first attempt succeeds instead of its fifth.

Item 1 is what makes the install hands-off, which is the Marketplace gate.

### Gap 4 — the release endpoint points at dev, and teaches nothing

`install.sh:849` defaults `UPGRADE_SERVER` to `https://dev.getjoinery.com`, so
the published one-liner hands every public install a dev build.

`upgrade_source` is a separate knob and nothing connects the two.
`--upgrade-server` tells the *installer* where to fetch the archive;
`upgrade_source` is a setting on the finished site telling `upgrade.php` where
to fetch from every time after. It is a declared setting defaulting to
`https://getjoinery.com`, so today's pairing is the worst available: a fresh
site pulls its code from dev (0.8.198 as of 2026-07-30) and comes up believing
it upgrades from getjoinery (0.8.185). It is born ahead of its own upstream.

**Fix (decided 2026-07-30): getjoinery.com becomes the release-serving site,
and all stable releases are served from there.** The channel is a site rather
than a flag on a row — no schema, no promotion state to forget, and it reuses
the chaining `latest_release` already implements. Specifically:

- `install.sh`'s `UPGRADE_SERVER` default and the published one-liner both
  point at getjoinery.com.
- `_site_init.sh` writes `upgrade_source` to the endpoint the install actually
  came from. One rule covers both audiences: nobody overrides, so a stranger's
  site upgrades from getjoinery; we pass `--upgrade-server=dev.getjoinery.com`,
  so ours upgrades from dev. The override flag already is the distinction, and
  following it is enough — no branch, no special case.
- `latest_release` accepts an optional version parameter for reproducible test
  builds only; the script ships "latest".
- Dev keeps publishing its own builds for internal use. Nothing about dev
  iteration slows down.

#### Promotion, and what it gates

A release reaches getjoinery only by being published *there*, and
`publish_upgrade.php` builds its archive from the tree of the site it runs on.
So promotion is: dev builds → getjoinery upgrades itself to that build →
getjoinery publishes it. That ordering has a useful property beyond
housekeeping — **getjoinery is running the code it serves**, so shipping a
build to strangers implies it at least came up on a real site first.

getjoinery keeps `upgrade_source = https://dev.getjoinery.com` permanently.
This is not circular. Upgrades flow *into* getjoinery from dev; releases flow
*out of* getjoinery to the world. Dev is upstream for us, getjoinery is
upstream for everyone else.

**Promotion republishes the same version, it does not mint a new one.**
`publish_upgrade.php` auto-detects the next patch from the `VERSION` file and
writes the new number back, so "upgrade getjoinery to 0.8.199, then publish"
would emit **0.8.200** carrying 0.8.199's code — and dev's next publish would
also be 0.8.200, two different archives under one number. Pass the version
explicitly instead. Nothing blocks it: the downgrade guard rejects only
*lower*, and the duplicate-version check reads the local `upg_upgrades` table,
which on getjoinery does not contain dev's rows.

```
# on getjoinery, after upgrade.php brings it to 0.8.199
php plugins/server_manager/includes/publish_upgrade.php 0.8.199 "release notes"
```

**Promotion stays manual** (decided 2026-07-30). It happens rarely, the two
steps are separately verifiable, and a one-button version that half-completes
leaves the public release channel in an unclear state.

#### No monitoring check is needed

An earlier draft called for one, on the reasoning that getjoinery has no
upstream to chain to. It does — dev — and more to the point the failure it
guarded against cannot occur. Publishing is a deliberate act. If the upgrade
from dev fails, the promotion simply does not happen and getjoinery keeps
serving its previous archive; `latest_release` prefers a servable local
release and only chains when there is none. Reaching the chain would require
someone deleting archives from `static_files`, which is not a failure mode we
have.

### Gap 5 — the OS is effectively pinned but softly checked

`install.sh:1524` warns and continues on anything that isn't Ubuntu 24.04
while hardcoding PHP 8.3 paths throughout, so a wrong-image deployment
half-succeeds.

**Fix:** the platform does most of this for us. A StackScript declares a
**Target Images** set — at least one image is required, and the deploy form
only offers the images listed there — so declaring `linode/ubuntu24.04` alone
means an incompatible deployment cannot be selected in the first place. No
build work on this path.

**The handoff script carries no OS check of its own** (decided 2026-07-30). An
earlier draft gave it one as a backstop, since the archive can be fetched and
run anywhere while Target Images constrains only the Linode create flow. But
the handoff script calls `install.sh server` a few lines in, and that now hard
fails on the wrong OS — so the backstop duplicates a check that fires anyway
and becomes a second place to update when a newer LTS is supported. One check,
one message, one place to change. The handoff script simply never passes
`--allow-unsupported-os`.

**`install.sh` itself now hard-fails** on anything but Ubuntu 24.04 rather
than warning and continuing (decided 2026-07-30). It hardcodes PHP 8.3 paths
throughout, so continuing produces a half-configured box that looks installed
— the failure is worse for being late and quiet. This changes behavior for the
hand-run one-liner, which is where it matters; the StackScript path was
already protected by Target Images.

Include a documented `--allow-unsupported-os` escape hatch that warns loudly
and proceeds: a check with no override gets deleted the first time someone
genuinely needs past it.

The guard stays in the `server` subcommand only. `site` presupposes `server`
ran, so that is the one place it can fire on a real path; adding it to `site`
would catch only a box prepared by other means, and would mean passing the
override twice since nothing persists it.

### Gap 6 — nothing checks that a published build runs

`publish_upgrade.php` builds its archive from the tree of the site it runs on,
and dev *is* the working tree — so a publish captures whatever happened to be
on disk at that moment, half-finished edits included.

**No new publish guard** (decided 2026-07-30). An earlier draft gated the
getjoinery publish on the db tier. That is the wrong end: getjoinery upgrades
itself from dev before it publishes, so a broken build breaks our own site,
visibly, and we simply do not promote it. The promotion chain is already a
smoke test. Gating the one publish that has been proven by an upgrade buys
nothing for five and a half minutes plus an override flag to maintain.

What is genuinely exposed is our own fleet — scrolldaddy, phillyzouk,
mapsofwisdom and the rest pull from dev directly, with no getjoinery in
between, so they eat a bad dev publish first.

**Fix: `upgrade.php` runs the tests, not the publisher.** After the new code is
in place, a test tier runs; on failure the upgrade rolls back to
`public_html_last`, which `upgrade.php` already keeps and already has a restore
path for. The failure lands where it can be acted on rather than being guessed
at from the publishing side.

- **Fresh installs run no tests.** There is nothing to roll back to and
  nothing to regress from.
- Migrations run before the swap, so a rollback returns the code but not the
  schema. Schema changes here are additive, so old code against a new schema
  generally runs — but this is a recovery, not a clean undo, and should be
  described that way in the output.

#### Which tier — corrected 2026-07-30, after it rolled back a good release

This spec originally said the `safe` tier, on the reasoning that it is fifteen
seconds, needs no database, and catches a parse error in code that shipped
mid-edit. The reasoning about *what to catch* was right. `safe` was the wrong
instrument, and the first real promotion proved it: getjoinery took 0.8.199,
migrated cleanly, then failed **eleven** suites and reverted.

None of the eleven said anything about the release. `safe`, `db` and `test-db`
are **development** gates — they run in a checkout and are entitled to assert
things about one: the full first-party plugin set, the components manifest, the
layout of `maintenance_scripts`. getjoinery carries four plugins because it uses
four. `env: any` has always meant *safe on any development environment*, and
nothing exercised that assumption until a node was made to run them.

`prod-verify` looked like the ready-made answer and is not — all four of those
tests are `live` tier and hit the network. There was no deployment-verification
set, so one was built.

**A new `deploy` tier** (`php tests/run.php deploy`), cumulative with nothing in
either direction, holding three checks in `tests/deploy/`:

| Check | What it catches |
|---|---|
| `deploy_syntax_sweep` | Every deployable PHP file compiles — the failure actually in scope. |
| `deploy_bootstrap` | Core classes load, the database answers, the declarative manifests parse, the licence shipped. |
| `deploy_site_responds` | Homepage and sign-in return without a 5xx, through Apache and the theme. |

About two seconds, entirely reads. Three rules for anything added to it: assume
no repository, read only, and treat an unreachable dependency as a SKIP rather
than a failure — reverting a working release because a socket would not open is
the worse error of the two.

The sweep compiles rather than lints. `opcache_compile_file()` parses without
executing, doing the whole tree in about a second against a minute-plus for
`php -l` per file, which matters on the 1 GB instances this path targets. One
process shares one symbol table, so files that legitimately declare the same
function name collide; anything failing the fast pass is re-checked with an
isolated `php -l` before it counts. Verified against a deliberately broken file,
and against the exact getjoinery tree that failed.

**The version-agreement check was removed from the gate.** It compared `VERSION`
to `system_version` and would have reverted a deploy over bookkeeping. The two
numbers are reported in the upgrade output instead.

### Gap 7 — a fresh site cannot send email, so lockout is unrecoverable

The install gives the site its own admin password and forces a change at first
login — after which the credentials file is stale, holding a password that no
longer works. The remaining recovery route is password reset, which needs email,
which a fresh install does not have.

The obvious fix is unavailable. A local mail server will not deliver: **Linode
blocks outbound port 25 at the account level**, confirmed by testing in
`specs/step8_email_stack_activation.md`. Nothing can make a fresh instance
send mail without the deployer supplying provider credentials.

**Part 1 — the safety net — RESOLVED IN CORE.**
`maintenance_scripts/sysadmin_tools/reset_admin_password.php` sets a new
password with `usr_force_password_change` on, over SSH or the LISH console. It
is CLI-only, outside the web root, takes the password from a prompt or a file
rather than an argument, and logs its use.

**Part 2 — still owned here: email setup as the obvious first task.**

There is no clean "unconfigured" signal to detect. `email_service` defaults to
`smtp`, so a fresh install *looks* configured and simply cannot deliver,
because SMTP has no host. So the guidance is unconditional and stateless, in
two places:

- The install summary and the credentials file name
  `/admin/admin_settings_email` as step one, reaching the deployer while the
  terminal is still open.
- The forced-password-change screen shows a **link** to it for permission-10
  users. A link, not a redirect — someone who just chose a password is
  mid-task, and bouncing them into a settings form reads as a bug.

Neither can drift out of sync with a detection rule that guesses wrong. The
cost is an admin who already configured email seeing one link they do not need,
once, on a screen they see once.

**Separately: `email_service` should default to empty, not `smtp`** (decided
2026-07-30). A default that is configured-but-useless is worse than none — it
denies us an honest unconfigured state and misreports the reason a send failed.
The setting already declares `empty_option: true`, so empty is representable.

The default flip alone does not achieve it. `EmailSender` carries `?: 'mailgun'`
in five places, so an empty setting silently means Mailgun — with no key,
failing at the API call rather than at the decision. Those fallbacks become an
explicit unconfigured result: no send attempted, a clear reason returned, so an
unsent email says *no email service is configured* instead of surfacing a
Mailgun auth error to someone who never chose Mailgun. Every existing site has
the row populated, so nothing in the fleet changes behavior.

**An install wizard is the right long-term home for this**, with email setup as
its first step. Recorded as follow-up work, not built here.

Rejected: collecting SMTP credentials as a create-form field. Most deployers
do not have them to hand at that moment, and a wall of questions in front of
someone who has not yet seen the product converts a recoverable gap into an
abandoned signup.

### Gap 8 — script size (recorded, not a problem)

`install.sh` is ~117 KB; StackScript data is capped at 65,535 characters. The
thin-wrapper design already avoids this by fetching rather than embedding.
Noted so nobody tries to inline it.

## Phases

**Phase 0 — core changes. DONE.** None of these are Linode-specific and all of
them improve the hand-run install: the release endpoint and `upgrade_source`
(Gap 4), the OS hard fail (Gap 5), the upgrade test run and rollback (Gap 6),
php.ini to UTC, the bundle mechanism, the admin email seam, and the
email-service default.

Where each landed:

| Item | Files |
|---|---|
| Release endpoint + `upgrade_source` | `install.sh` (`UPGRADE_SERVER` default, `export`), `_site_init.sh` (UPGRADE SOURCE block), `utils/latest_release.php` (`?version=`) |
| OS hard fail | `install.sh` `do_server_setup` |
| Upgrade test run | `utils/upgrade.php` (POST-DEPLOY SMOKE TEST) |
| Timezone | `install.sh` php.ini sed |
| Bundles | new `install_bundles.json`, new `sysadmin_tools/install_bundle.php`, `_site_init.sh` |
| Admin email | `sysadmin_tools/reset_admin_password.php` (`--set-email=`), `_site_init.sh`, `install.sh` (`--admin-email=`) |
| Email default | `settings.json`, `includes/EmailSender.php` (new `activeServiceKey()`), plus the two mailbox callers that had their own `?: 'mailgun'` |
| Email guidance | `install.sh` `print_email_setup_notice`, `_site_init.sh` credentials file, `logic/change_password_required_logic.php` + `views/change-password-required.php` |

`email_fallback_service` was flipped to empty alongside `email_service`. It had
the same defect for the same reason, and leaving it at `smtp` would have meant
the unconfigured branch never fired on a fresh site.

**Phase 1 — private StackScript. DONE (code).** New
`maintenance_scripts/install_tools/linode_stackscript.sh` (the handoff) and
`linode_stackscript_wrapper.sh` (the body to paste at Linode, kept in the repo
so what was pasted stays reviewable). Not yet deployed in our own account —
that is live gate A.

**Phase 2 — SSL automation. DONE.** Gap 3. The timer is core, gated on
`SSL_DEFERRED`, installed by `print_ssl_deferred_notice` so it lands from
whichever install mode deferred the certificate; units are templated per domain
(`joinery-ssl-retry@<domain>`) so a multi-site box gets one each. The optional
Linode DNS record creation lives in the handoff script and talks to the Linode
API directly — the platform's DNS driver is not reachable before the site
exists.

**Phase 3 — publish the StackScript publicly.** Not started; gated on A and B.
Any Linode customer can then select it from the StackScripts tab. No submission,
no review, no approval — this is distribution without the Marketplace, and it
builds a real population using the script before any listing review reads it.

## Testing

- `safe` tier — **done**, all in `tests/unit/installer_contract_test.php`
  (79 checks, up from 34). Seven new sections: the release endpoint and
  `upgrade_source` seeding; the OS guard being a stop with exactly one override
  in exactly one subcommand; the retry timer resolving DNS first and stopping
  only on a CA-issued certificate; the upgrade running `safe` and rolling back
  while saying the schema did not come back; php.ini on UTC with the seeded
  user still on New York; every plugin named in `install_bundles.json` existing
  on disk and an unknown bundle being an error rather than a silent no-op; the
  admin address changing in the same save as the password; `EmailSender`
  carrying no `?: 'mailgun'` anywhere and `email_service` declaring no default;
  and the Linode wrapper delegating, pinning nothing, masking both secrets and
  echoing neither.

  `tests/email/email_provider_config_test.php` also learned the unconfigured
  case: it used to assert the active provider resolves to an instance, which an
  unconfigured site can no longer satisfy, and now asserts the honest answer
  instead.
- `db` tier — **not written.** `_site_init.sh` writing `upgrade_source` and the
  `--set-email=` behaviour both need a real database and a real install to
  exercise end to end; asserting them from the outside would only re-check the
  text the safe tier already pins.
- Live gate A: an instance whose domain already resolves to it by the time
  certbot runs, reaching HTTPS and a forced password change with no console
  access used. An A record cannot exist before create — the IP is assigned by
  the create — so there are exactly two ways to reach this state, and both are
  worth running:
  - **A1, the hands-off path:** supply the domain and a Linode API token for an
    account that already holds the zone. The handoff script creates the A
    record itself during first boot, before `install.sh` reaches certbot. This
    is the path a real deployer takes, and the only one available on a first
    create.
  - **A2, the isolated path:** deploy once, point the A record at the IP by
    hand, then *Rebuild* the same instance with the StackScript. A rebuild
    keeps the IPv4, so the record already resolves when the script runs. This
    exercises the certificate path with the record-creation step removed, which
    is what separates an SSL failure from a Linode API failure.
- Live gate B: the same with no DNS at create and no token, proving the SSL
  retry timer issues a real certificate once the record is pointed afterwards.
- Live gate C: the instance makes no outbound call other than fetching the
  release archive and its declared dependencies — verified from the node, not
  assumed. This is the standalone guarantee, and it is the kind of promise
  that erodes silently if nothing checks it.

## Documentation

- `docs/installation.md` — the StackScript path, the UDF set, where
  credentials land.
- `docs/quickstart.md` — lead with the one-click path, keep the SSH one-liner
  as the manual alternative. The credentials step and the DNS/SSL claim are
  already correct; the one-click path adds that the password came from the
  deploy form and there is no file to read.
- `docs/deploy_and_upgrade.md` — the release endpoint's version parameter and
  `upgrade_source` seeding.
- `docs/email_system.md` — configuring a provider is the first task on a fresh
  deployment, and why a local MTA is not an option on Linode.

## Deferred past v1

**First-boot install for customer-cloud.** `LinodeComputeDriver::createInstance`
gains `stackscript_id` + `stackscript_data` (or `metadata.user_data`)
passthrough, and the handoff script grows a control-plane-only enrollment
input that installs the agent and registers the node. This would replace the
control plane's SSH install job with an instance that installs itself.

Not in v1 by owner decision. Recorded because the standalone design is
deliberately compatible with it: the enrollment path would be additive — a
value only the control plane can supply, absent from any public listing — so
adopting it later does not require reworking anything decided here.

## Decisions

All resolved 2026-07-30. Nothing in this spec is waiting on an answer; the
remaining dependency is external — the core-license choice in
`specs/open_core_licensing.md`, which affects what the default bundle omits
but not how the omission works.

1. *Resolved 2026-07-30: `install.sh` hard-fails on a non-24.04 OS, with a
   documented `--allow-unsupported-os` override that warns and proceeds. See
   Gap 5.*
2. *Resolved 2026-07-30: the Linode API token is an optional,
   password-masked field. The retry timer covers everyone regardless, so the
   token only accelerates SSL for deployers whose DNS is at Linode. The script
   must never write it to disk or echo it — the deployment log is readable by
   the deployer.*

### Second pass, 2026-07-30

Walked item by item with the owner after `specs/implemented/installer_defects.md`
landed. Three of these overturned what the spec previously said.

3. *`_site_init.sh` writes `upgrade_source` to the endpoint the install came
   from — one rule, no branch. See Gap 4.*
4. *Promotion is manual and republishes the same version number rather than
   minting the next one. No monitoring check: a failed promotion leaves the
   previous release served. See Gap 4.* **Overturns** the "no upstream, 404
   with no fallback" claim, which was wrong — getjoinery's upstream is dev.
5. *No publish-time test gate. `upgrade.php` runs the `safe` tier after the
   swap and rolls back on failure; fresh installs run nothing. See Gap 6.*
   **Overturns** the db-tier publish gate.
6. *php.ini goes to UTC; the seeded admin user's display timezone stays
   `America/New_York`. See "Derived, not asked".*
7. *Named bundles in `install_bundles.json`, installed by a new
   `sysadmin_tools/install_bundle.php`. Flat lists, no inheritance, `personal`
   is the default. See "Default install bundle".* **Overturns** the
   `plg_is_system` split, which rested on a wrong reading of that flag.
8. *The admin email seam is built here: `--set-email=`,
   `JOINERY_ADMIN_EMAIL`, `--admin-email=`. See the field table and Gap 7.*
9. *`email_service` defaults to empty and `EmailSender` stops falling back to
   Mailgun. An install wizard is recorded as follow-up work. See Gap 7.*
10. *The SSL retry timer moves into `install.sh` gated on `SSL_DEFERRED`, and
    resolves the domain before invoking certbot. The handoff script owns
    nothing for SSL and carries no OS check. See Gaps 3 and 5.*
