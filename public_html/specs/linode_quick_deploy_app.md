# Linode Quick Deploy App — One-Click Joinery on Akamai Cloud

**Status:** Unbuilt spec.
**Depends on:** nothing new — `install.sh` already does the work. This spec
wraps it, fixes what breaks when the installer is driven by a stranger instead
of by the control plane, and adds a distribution channel.

## What this does for the user

Someone who wants a Joinery site picks **Joinery** from a menu in the Akamai
Cloud (Linode) control panel, fills in their domain and email, clicks Create,
and five minutes later has a running, SSL-secured site with a login they own.
No terminal, no SSH, no copy-pasted install command, no waiting on us.

Today that same person must rent a server, learn what SSH is, paste a
four-line shell command, and then discover on their own that DNS has to
propagate before SSL works. The install itself already works — the friction is
entirely in getting to it.

Secondary benefit, and the reason to build the mechanism even before the
public listing: the same first-boot script installs Joinery on the buyer's own
VPS in the customer-cloud flow, replacing the control plane's SSH-driven
install job with a self-installing instance.

## The three Linode mechanisms, and which ones are real

Akamai Cloud offers four ways to make a pre-built system deployable. Only one
is a distribution channel.

| Mechanism | Verdict |
|-----------|---------|
| **StackScript** | **Build this.** A first-boot bash script with user-supplied fields. Can be private (ours only) or public (any Linode customer can pick it from the StackScripts tab). No submission, no review, no approval. This is the whole mechanism — the Quick Deploy App is a StackScript plus a listing. |
| **Quick Deploy App** (Marketplace) | **Build this second.** A StackScript, plus Ansible playbooks, plus a public git repo, plus a partner submission PR to `akamai-compute-marketplace/marketplace-apps` with description, support URL, docs, and brand assets. Reviewed by Akamai. Gets us into a menu 200+ apps deep that every Linode customer sees. |
| **Custom Image** | **Not a channel.** Images are private to the account that owns them, with no cross-account sharing. A buyer's account cannot see our image — which is exactly the case customer-cloud provisioning lives in. Also constrained: raw `.img` gzipped, ext3/ext4, 6 GB uncompressed / 5 GB compressed ceiling, $0.10/GB/month. A fresh Joinery box (Ubuntu + Apache/PHP/Postgres + ~236 MB app + vendor) crowds that ceiling, and the smallest instance disk is 25 GB, so capture would require shrinking the disk first. |
| **Clone Linode** | **Not a channel.** Same-account only, and it clones instance identity wholesale. See Identity hygiene below. |

Images and Clone are therefore **out of scope as distribution**. They are
noted here only so the question is closed and not re-asked.

### Identity hygiene (applies to any image or clone, whenever we do reach for one)

A captured image or a cloned instance carries the source's identity, not just
its software: the database password in `Globalvars_site.php`, SSH host keys,
the Go agent's enrollment credential, and backup-key/escrow material. Nothing
today regenerates any of that on first boot. If we ever build an
image-or-clone path for internal fleet speed, a first-boot regeneration step
is a prerequisite, not a follow-up.

## What already exists

- `docs/quickstart.md` publishes the exact command a StackScript needs:
  fetch `utils/latest_release`, untar, `install.sh server`, `install.sh site`.
- `install.sh` is fully non-interactive under `-y`, and the site path takes
  sitename, domain, and port as positional arguments.
- `utils/latest_release.php` serves an installable archive to anonymous
  callers and chains to an upstream when the serving site isn't the publisher.
- Measured install duration from `mjb_management_jobs` (`install_node`):
  **4–6 minutes** on a fresh box, 1.5 minutes when dependencies are already
  present. The Marketplace norm is 2–5 minutes, so the timing is acceptable
  as-is.
- `LinodeComputeDriver` (API v4), a Linode DNS driver, and the customer-cloud
  provisioning pipeline that creates instances in a buyer's own account.

## Integration-point inventory

Everything this touches, decided up front.

| Piece | Where | What |
|-------|-------|------|
| First-boot script | new `maintenance_scripts/install_tools/linode_stackscript.sh` | The UDF-driven wrapper. Fetches the release, runs `install.sh server` + `install.sh site`, then does the things the control plane normally does. Lives in the repo so it versions with the installer it drives. |
| SSH access preservation | `install.sh` (`do_server_setup`) | New flag so server setup does not orphan the deployer's access. See Gap 1. |
| Admin credential | `install.sh` site path + `_site_init.sh` | New flag to randomize the seeded admin password and write it where the deployer can read it. See Gap 2. |
| Deferred SSL | new on-node retry unit, written by the StackScript | A systemd timer that runs the existing `sysadmin_tools/setup_ssl.sh` until DNS resolves, then disables itself. See Gap 3. |
| DNS record creation (optional path) | existing Linode DNS driver, invoked from the node | With a UDF-supplied Linode API token, create the A record from the instance itself so SSL succeeds on the first attempt. |
| Release endpoint | `utils/latest_release.php`, `install.sh` `UPGRADE_SERVER` | Public installs must resolve to a production endpoint and a pinned version, not to whatever the dev box built last. See Gap 4. |
| Upgrade source seeding | `_site_init.sh` | A self-serve install currently learns nothing about where its upgrades come from. See Gap 4. |
| OS pin | StackScript + listing metadata | Ubuntu 24.04 LTS only. See Gap 5. |
| License notice | release archive build (`publish_upgrade.php`) | `LICENSE.md` is currently absent from the archive. See Licensing. |
| Ansible layer | new public repo `getjoinery/joinery-marketplace` | Marketplace-phase only: playbook wrapping the same script, plus the assets the submission requires. |
| Compute driver | `includes/cloud_compute/LinodeComputeDriver.php` | `createInstance` gains `stackscript_id` + `stackscript_data` (and/or `metadata.user_data`) passthrough, so customer-cloud instances self-install. |

## The six gaps

These are the differences between "the control plane runs the installer" and
"a stranger runs the installer." Each is a build item.

### Gap 1 — server setup orphans the deployer's SSH access

`install.sh:1697` sets `PermitRootLogin no`. `user1` is created with no
password, no `authorized_keys`, and no sudo (`install.sh:1436-1453` —
`usermod -aG www-data` is the only group grant). The managed flow survives
this because the control plane pre-stages user1's key *before* running server
setup ("Pre-stage user1 for managed access" in `build_install_node`). A
self-serve deployer has no such step, so the root key Linode installed at
create time stops working partway through the script and only the LISH console
remains.

**Fix:** the StackScript collects a limited username and an SSH public key as
UDFs (matching the Marketplace convention), pre-stages that user with sudo and
the key, and only then runs server setup. `install.sh server` grows an
explicit `--admin-user=NAME --admin-key-file=FILE` (or equivalent) so the
pre-stage is the installer's job rather than something the wrapper reaches
into sshd to patch. Root password login stays off; key access survives.

Worth noting independently of Linode: this is a latent hazard in the published
one-liner install too. Anyone following `docs/quickstart.md` on a key-only
server loses SSH at the same point.

### Gap 2 — a well-known default password on a public IP

The seed database ships `admin@example.com` / `changeme123` with
`usr_force_password_change` set, so the first login must change it. That is
fine when a human is watching the install finish. It is a race when the
instance is on a public IP and the deployer wanders off: whoever logs in first
sets the password.

**Fix:** the StackScript generates a random admin password (or takes one as a
UDF), applies it after the site is created with `usr_force_password_change`
left on, and writes it to `/home/$USERNAME/.credentials` (mode 600), the
convention Marketplace apps use. The listing tells the user to `cat` that
file. `changeme123` stops being reachable from the network.

### Gap 3 — SSL has nowhere to land

A brand-new instance has no DNS pointing at it — the IP does not exist until
the instance does. `install.sh` correctly detects this, skips certbot, and
prints a manual command. But the retry lives in the control plane's
Provision Pending SSL task; nothing on the node ever tries again. A
Marketplace app must reach its initial state with no command-line
intervention, so "print a command for the user to run later" fails the bar.

**Fix, two layers:**

1. **Preferred, when the user supplies a Linode API token UDF:** create the A
   record from the node using the existing Linode DNS driver before running
   the site install, so certbot succeeds on the first pass. This is the
   pattern the WordPress Marketplace app uses.
2. **Always:** install a systemd timer that runs
   `sysadmin_tools/setup_ssl.sh` on a backoff until it succeeds, then
   disables itself. This covers the user who points DNS an hour later at a
   registrar we have no token for.

### Gap 4 — the release endpoint points at dev, unpinned, and teaches nothing

`install.sh:722` defaults `UPGRADE_SERVER` to `https://dev.getjoinery.com`,
and `latest_release` always redirects to the newest archive with no way to
request a version. A public app would install dev builds off the dev box.
Separately, nothing sets `upgrade_source` on the newly created site, so a
self-serve install does not know where its own upgrades come from.

**Fix:** a production distribution endpoint is the default for any public
install path; `latest_release` accepts an optional version parameter so the
StackScript can pin (and the listing can be tested against a known build);
`_site_init.sh` seeds `upgrade_source` to the endpoint the install came from.

### Gap 5 — the OS is effectively pinned but only softly checked

`install.sh:1398` warns and continues on anything that is not Ubuntu 24.04,
while hardcoding PHP 8.3 paths throughout. A deployment on the wrong image
half-succeeds.

**Fix:** the StackScript refuses to run on anything but Ubuntu 24.04 LTS with
a clear message, and the listing pins the image. Whether `install.sh` itself
should hard-fail rather than warn is a separate call — see Open decisions.

### Gap 6 — script size

`install.sh` is ~117 KB; StackScript data is capped at 65,535 characters. Not
a problem, because the wrapper fetches the installer rather than embedding it
— which is what Marketplace apps do anyway. Recorded so nobody tries to
inline it.

## Licensing

This is the part with a real decision in it.

**Joinery is under the PolyForm Noncommercial License 1.0.0** (`LICENSE.md`).
Any noncommercial purpose is permitted, including hobby projects, personal
study, charities, schools, public research, and government. Commercial use is
not covered. The license also carries a **plugin and theme exception**: work
that extends Joinery through its plugin system, theme system, base classes, or
APIs is not a derivative work, and its authors may license it as they like.

What that means for each channel:

- **Akamai does not require apps to be open source.** BYOL apps are
  supported; the end user is billed for cloud resources plus any applicable
  licenses. A "free for noncommercial use, commercial license available from
  Joinery" listing fits the existing BYOL shape.
- **A public listing puts Joinery in front of commercial deployers**, who are
  not licensed by PolyForm to use it. Since we hold the copyright we can
  dual-license, but the listing and the post-install output must say plainly
  what the license permits and where to get a commercial one. Silence here
  invites exactly the misuse the license forbids, at volume, with our own
  distribution channel as the vector.
- **The Marketplace glue repo is GPLv3** (`akamai-compute-marketplace/marketplace-apps`).
  A submitted StackScript and playbook are licensed GPLv3 as part of that
  repo. That is fine — deployment glue that fetches a separately licensed
  archive is not a derivative work of Joinery — but it means nothing
  proprietary may go into the playbook, and anyone may fork the glue.
- **Compliance gap, already live:** PolyForm requires that anyone who receives
  a copy of the software also receives the license terms. The release archive
  does not contain `LICENSE.md` — its top level is only `./config`,
  `./maintenance_scripts`, `./public_html`. Every install performed by the
  published one-liner today is missing the notice the license requires. The
  archive build must include `LICENSE.md`, the StackScript must leave it
  somewhere obvious on the box, and the first-run admin screen should link it.
- **No commercial third-party assets leak.** Only `default` and
  `joinery-system` are system themes, and only `event_manager` and `store` are
  system plugins, so a default install pulls nothing licensed from a theme
  vendor.

## Marketplace submission requirements (phase 2)

Recorded so the phase-2 work is a checklist, not a discovery exercise:

- StackScript + Ansible playbooks + a public git repo to clone from.
- A short description (100–125 words), a support URL that reaches a real human
  or forum, thorough technical documentation, and brand assets.
- Deployment must be hands-off: no command-line intervention before the app
  reaches its initial state. Gap 3 is the only thing standing between us and
  this bar.
- Required plan sizes must stay at or below 16 GB shared CPU / 8 GB dedicated
  CPU. Joinery's floor is far below that.
- Submission is a pull request to `akamai-compute-marketplace/marketplace-apps`,
  reviewed by Akamai.

## Phases

**Phase 1 — StackScript, private.** The wrapper script plus Gaps 1, 2, 4, 5,
and the `LICENSE.md` archive fix. Deploy it in our own account until a fresh
instance comes up green with no console intervention.

**Phase 2 — SSL automation.** Gap 3, both layers. This is what makes the
install hands-off, and it is the gate for both the public StackScript and the
Marketplace listing.

**Phase 3 — driver passthrough.** `stackscript_id`/`stackscript_data` (or
`metadata.user_data`) in `LinodeComputeDriver::createInstance`, so
customer-cloud provisioning uses the same first-boot path instead of the
control plane's SSH install job. This is the internal payoff and it proves the
script across real buyer accounts before strangers see it.

**Phase 4 — publish the StackScript publicly.** Zero-approval distribution,
and a live population using the script before the Marketplace review reads it.

**Phase 5 — Marketplace submission.** The public glue repo, the Ansible
layer, the listing assets, the PR.

## Testing

- A `safe`-tier test asserting the StackScript's own preconditions: the OS
  guard rejects non-24.04, the release URL is a production host, the UDF set
  is complete, and no secret is echoed to stdout (the StackScript's output
  lands in a log the deployer can read).
- A `db`-tier test that `_site_init.sh` seeds `upgrade_source` and that the
  randomized admin password path leaves `usr_force_password_change` set.
- Live gate: a fresh Linode deployed from the StackScript with DNS pointed
  before create, reaching HTTPS and a forced password change with no console
  access used. A second run with DNS pointed *after* create, proving the SSL
  retry timer.

## Documentation

- `docs/installation.md` — new section for the StackScript / Quick Deploy
  path, the UDF set, and where credentials land.
- `docs/quickstart.md` — lead with the one-click path; keep the SSH one-liner
  as the manual alternative. Also correct the default-credentials step once
  Gap 2 lands.
- `docs/deploy_and_upgrade.md` — the release endpoint's version-pinning
  parameter and `upgrade_source` seeding.
- `plugins/server_manager/docs/overview.md` — customer-cloud instances
  self-install via first-boot script (phase 3).

## Open decisions

1. **Commercial licensing posture for a public listing.** Free-for-noncommercial
   with a commercial license sold separately is the coherent reading of
   PolyForm, but the listing needs concrete wording and a place to send
   commercial users. This is an owner call and it blocks phase 4, not phase 1.
2. **Should `install.sh` hard-fail on a non-24.04 OS** rather than warning and
   continuing? Hard-failing is the honest behavior and the StackScript wants
   it, but it changes existing installer behavior for anyone deliberately
   running elsewhere.
3. **Is the Linode API token UDF worth asking for?** It buys first-pass SSL
   for Linode-DNS users, and it is a credential we hold for the life of the
   script's run. The retry timer covers everyone regardless, so this is
   convenience versus asking a stranger for a token.
