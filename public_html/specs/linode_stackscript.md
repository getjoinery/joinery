# Linode StackScript — Self-Installing Joinery on Akamai Cloud

**Status:** Unbuilt spec.
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
| Admin email | **Yes** | Sets the admin account's address at install instead of leaving `admin@example.com` for the owner to change by hand — the difference between a recoverable account and a locked-out one. |
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

#### "System" is now doing two jobs

`plg_is_system` currently means "auto-installed", and the bundle above is a
different idea: a *product selection*, not a set of components the core
requires. Conflating them means changing the marketing bundle edits a flag
that also governs what the platform assumes is present.

Separate them: keep `is_system` for what core requires, and give the installer
a named default bundle it pulls unless told otherwise. `store` then drops out
of the default set for two independent reasons — it is not in the bundle, and
it declares `requires_entitlement` — so neither decision is load-bearing alone.

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
- **Timezone — UTC.** `install.sh` currently hardcodes `America/New_York` into
  php.ini (`install.sh:1681`), which sits oddly against the platform's own
  doctrine that all database times are UTC with per-user display conversion.
  UTC is the correct default, not merely a neutral one. Change it in
  `install.sh` rather than patching it from the wrapper.

## Integration-point inventory

| Piece | Where | What |
|-------|-------|------|
| Linode-side wrapper | StackScript body, authored once | UDF declarations plus a fetch-and-delegate. Kept under ~20 lines by construction. |
| Handoff script | new `maintenance_scripts/install_tools/linode_stackscript.sh` | The real first-boot logic. Lives in the repo so it versions with the installer it drives. |
| SSH access preservation | `install.sh` `derive_ssh_access` | Built. Server setup derives a reachable account before disabling root login. Gap 1. |
| Admin credential | `_site_init.sh` | Built. Pass the UDF password as `JOINERY_ADMIN_PASSWORD`; the site uses it instead of generating one, and writes no file. Gap 2. |
| Deferred SSL | new systemd timer, written by the handoff script | Runs the existing `sysadmin_tools/setup_ssl.sh` until DNS resolves, then disables itself. Gap 3. |
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

**Fix, two layers:**

1. **Install with `--no-ssl`** so the run does not spend time on a certificate
   attempt that cannot succeed on a minutes-old instance. This is now an
   optimization rather than a requirement — without it the install still
   completes, just slower.
2. **Always:** install a systemd timer running `sysadmin_tools/setup_ssl.sh`
   on a backoff until it succeeds, then disabling itself. This covers both the
   user whose DNS propagates ten minutes later and the one who points it a
   week later.
3. **When a Linode API token UDF is supplied:** create the A record from the
   node using the existing Linode DNS driver before the site install, so the
   timer's first attempt succeeds instead of its fifth.

Layer 2 is what makes the install hands-off, which is the Marketplace gate.

### Gap 4 — the release endpoint points at dev, and teaches nothing

`install.sh:722` defaults `UPGRADE_SERVER` to `https://dev.getjoinery.com`,
and nothing sets `upgrade_source` on the newly created site, so a self-serve
install doesn't know where its own upgrades come from.

The current topology is also self-contradictory: dev's own `upgrade_source` is
already `https://getjoinery.com`, but dev publishes its own releases, so
`latest_release` on dev serves dev's newest build and never chains upstream.
The published one-liner points at dev. Every public install today therefore
gets a dev build.

**Fix (decided 2026-07-30): getjoinery.com becomes the release-serving site,
and all stable releases are served from there.** The channel is a site rather
than a flag on a row — no schema, no promotion state to forget, and it reuses
the chaining `latest_release` already implements. Specifically:

- `install.sh`'s `UPGRADE_SERVER` default and the published one-liner both
  point at getjoinery.com.
- `_site_init.sh` seeds `upgrade_source` on the new site to the endpoint the
  install came from, so a deployment knows where its own upgrades live.
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

The test gate (Gap 6) applies to the getjoinery publish, the one strangers
consume. Dev publishes stay ungated.

#### New single point of failure

Every new install now depends on getjoinery.com serving an archive.
`latest_release` skips rows whose file is missing and chains to an upstream,
but getjoinery has no upstream — a missing or unservable archive there is a
404 with no fallback, and it fails every install silently from our side. This
needs a monitoring check that actually fetches the endpoint and asserts a
200/302 plus a non-trivial body, not merely that the host is up.

### Gap 5 — the OS is effectively pinned but softly checked

`install.sh:1398` warns and continues on anything that isn't Ubuntu 24.04
while hardcoding PHP 8.3 paths throughout, so a wrong-image deployment
half-succeeds.

**Fix:** the platform does most of this for us. A StackScript declares a
**Target Images** set — at least one image is required, and the deploy form
only offers the images listed there — so declaring `linode/ubuntu24.04` alone
means an incompatible deployment cannot be selected in the first place. No
build work on this path.

The handoff script still refuses to run on anything but Ubuntu 24.04 LTS, as a
backstop: the archive it lives in can be fetched and run anywhere, and Target
Images constrains only the Linode create flow.

**`install.sh` itself now hard-fails** on anything but Ubuntu 24.04 rather
than warning and continuing (decided 2026-07-30). It hardcodes PHP 8.3 paths
throughout, so continuing produces a half-configured box that looks installed
— the failure is worse for being late and quiet. This changes behavior for the
hand-run one-liner, which is where it matters; the StackScript path was
already protected by Target Images.

Include a documented `--allow-unsupported-os` escape hatch that warns loudly
and proceeds, for the same reason the publish gate gets one: a check with no
override gets deleted the first time someone genuinely needs past it.

### Gap 6 — nothing checks that a published build works

`publish_upgrade.php` has a downgrade guard and the agent-rebuild integrity
check, but nothing that asks whether the code runs. `CLAUDE.md` already names
the db tier as the pre-publish gate, so the discipline is written down and
simply unenforced.

This is not a risk the StackScript creates. `latest_release` and the upgrade
endpoint both serve the newest release, and `upgrade.php` on every existing
deployment pulls from the same place, so a bad publish already reaches real
sites. The public install path adds strangers to the blast radius — people
whose first experience of Joinery would be an install that dies halfway.

**Fix:** the getjoinery publish refuses unless the db-tier suite passed
against the current tree. It slots into the guard pattern
`publish_upgrade.php` already has and costs roughly five and a half minutes on
an operation that is not frequent.

Include an explicit override flag that logs loudly. A gate with no escape
hatch gets commented out the first time a publish is urgent, and then it is
gone for good.

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

**Part 2 — still owned here: email setup as the obvious first task.** The
post-install output and the credentials file name `/admin/admin_settings_email`
as step one, and the forced-password-change screen hands off to it. Guidance at
the one moment it is useful, not a recurring prompt.

Rejected: collecting SMTP credentials as a create-form field. Most deployers
do not have them to hand at that moment, and a wall of questions in front of
someone who has not yet seen the product converts a recoverable gap into an
abandoned signup.

### Gap 8 — script size (recorded, not a problem)

`install.sh` is ~117 KB; StackScript data is capped at 65,535 characters. The
thin-wrapper design already avoids this by fetching rather than embedding.
Noted so nobody tries to inline it.

## Phases

**Phase 1 — private StackScript.** The wrapper, the handoff script, and Gaps
1, 2, 4, 5. Deploy in our own account until a fresh instance comes up green
with no console intervention.

**Phase 2 — SSL automation.** Gap 3, both layers. This is the gate for
anything public.

**Phase 3 — publish the StackScript publicly.** Any Linode customer can then
select it from the StackScripts tab. No submission, no review, no approval —
this is distribution without the Marketplace, and it builds a real population
using the script before any listing review reads it.

## Testing

- `safe` tier: the OS guard rejects non-24.04; the release URL is a production
  host; the UDF set is complete; no secret is echoed to stdout, since
  StackScript output lands in a log the deployer can read.
- `db` tier: `_site_init.sh` seeds `upgrade_source`; the randomized admin
  password path leaves `usr_force_password_change` set.
- Live gate A: a fresh Linode from the StackScript with DNS pointed *before*
  create, reaching HTTPS and a forced password change with no console access
  used.
- Live gate B: the same with DNS pointed *after* create, proving the SSL retry
  timer.
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
