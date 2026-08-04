# A container rebuild must never move a site's code backward

**Status:** Part 1 built 2026-08-04. Part 2 unbuilt.
**Date:** 2026-08-04

## The problem

On the Docker host, a site's PHP code lives in the container's **writable
layer** — not on a volume. The eleven named volumes cover postgres, uploads,
storage, config, backups, static_files, cache, logs, sessions, apache_logs and
pg_logs. `public_html` is not among them.

Two different things write that code, and they never learn about each other:

- **The image build** copies code from an archive root at build time.
- **`upgrade.php`** (the `apply_update` job) writes code into the running
  container, months later, over and over.

So the running code drifts away from the image, and nothing anywhere compares
them. Measured 2026-08-04:

| | joinerydemo |
|---|---|
| Running container | 0.8.221 |
| Its image, built 2026-04-23 | 0.8.24 |
| Host build source `/root/joinery` | April tree, no VERSION file |

All seven site containers are in this shape.

`install.sh site <name> -y` — whose own output says *data volumes preserved* —
does `docker stop`, `docker rm`, then rebuilds the image from the archive root.
That sentence is true about **data** and silently false about **code**. Running
it on any of these sites today would drop ~3.5 months of code onto a database
already migrated forward, against code that has no idea those migrations
happened.

This is not a hypothetical trap. `docs/deploy_and_upgrade.md` instructs
operators to "rebuild each site container" whenever a base-image change lands —
which is exactly the situation the php-fpm switch created — with no warning
that doing so reverts the site's code.

The tooling already checks a *less* dangerous version of this: `do_site_docker`
compares the base image's `install.sh` hash and warns about drift. The check
that would prevent data-corrupting code loss is the one that is missing.

## Part 1 — The guard

**A rebuild refuses when it cannot prove it is moving the site's code forward.**

Where it goes: `do_site_docker`, **before the container is stopped**. The
function currently stops a running container early, as a preflight ahead of the
port check. A guard placed after that point would leave a refused site sitting
down — the refusal must cost nothing.

What it compares:

- **Running code**: the VERSION file inside the existing container
  (`docker exec <site> cat /var/www/html/<site>/public_html/VERSION`).
- **Archive code**: `$ARCHIVE_ROOT/public_html/VERSION`.

Outcomes:

| Situation | Result |
|---|---|
| No existing container | Proceed — nothing to move backward |
| Archive version > running | Proceed |
| Archive version == running | Proceed |
| Archive version < running | **Refuse** |
| Either VERSION missing or unreadable | **Refuse** |
| `--wipe-data` given | Skip the check — the operator is asking for a fresh site, and the data that made the old code load-bearing is going too |

A missing VERSION refuses rather than warns. An archive that cannot say what it
is, is exactly the April tree that started this, and "unknowable" is not a
weaker signal than "older" — it is the same signal with less information.

The refusal names both versions and the archive path, and states the fix
(publish a current archive and extract it here), because an operator who hits
this at 2am needs the next action, not a diagnosis. Override is
`--allow-downgrade`, which must be typed deliberately and prints what it is
about to overwrite.

Version comparison is `sort -V` semantics, not string comparison — 0.8.24 vs
0.8.221 is precisely the pair that string comparison gets backwards, and it is
the pair in front of us.

**Tests** (`tests/unit/installer_contract_test.php`, which already reads
`install.sh` as text and asserts properties of it): the guard exists; it is
ordered before the preflight `docker stop`; `--wipe-data` bypasses it;
`--allow-downgrade` exists and is the only bypass.

## Part 2 — The durable fix

The guard stops the bleeding. It does not fix the reason a rebuild is dangerous
in the first place, which is that **the deployed code has no durable home**.

The invariant worth building toward:

> A site's code only ever moves forward, and the container's OS identity is
> separable from the site's code.

### Shape

1. **The site directory is a volume.** `{site}_code` mounts at
   `/var/www/html/{site}`, which holds `public_html`, `maintenance_scripts` and
   `vendor` — the three trees `upgrade.php` writes to. The eleven existing data
   volumes keep their current mount points and simply nest inside it; Docker
   mounts parents before children, so nesting works and each inner volume still
   shadows the path it covers.

   This settles the earlier open question about `maintenance_scripts`: it shares,
   because it is part of the same tree, and splitting a tree that ships and
   upgrades as a unit would create a second thing to keep in step.

2. **The image carries no site code at all.** No `COPY` of the site tree, no
   compressed bundle, nothing under `/var/www/html/{site}`. What is left in the
   per-site image is the Apache vhost and the start-up script — thin, and a step
   toward one image serving every site.

3. **`install.sh` seeds the volume, once, from the archive it is running from.**
   Not from the network, and not from the image. The operator already extracted
   a release in order to run the installer; that tree is the source. A
   self-hosted customer can therefore rebuild a container with getjoinery.com
   switched off, which would not be true of a start-up fetch.

4. **Container start reconciles, and the rule has two rows:**
   - code present on the volume → **leave it completely alone**, start Apache
   - volume empty → refuse to start, saying the volume is unseeded and naming
     `install.sh` as what seeds it

   `vendor` is the one exception, because it is derived rather than authored: if
   it is missing it is rebuilt by `utils/composer_install_if_needed.php`, the
   same call `_site_init.sh` already makes.

Row one is the whole fix: **a stale image becomes inert instead of
destructive.** Rebuilding for an OS or PHP change stops touching site code at
all, which is what an operator following the deploy doc already believes is
happening. There is no version comparison at start-up and no upgrade-on-boot —
seeding is an install-time act performed once by a human running the installer,
not something a container decides to do to itself at 3am.

### What happens to the Part 1 guard

It is removed when this lands. After Part 2 a rebuild cannot write code at all
unless the volume is empty, and seeding an empty volume cannot move anything
backward — so the guard would have nothing left to protect, and a refusal it
issued would be blocking a rebuild that is in fact safe. Leaving it in place
would reintroduce the original problem in mirror image: an operator told not to
do something harmless learns to reach for `--allow-downgrade` by reflex.

### Migrating the seven existing containers

Each one's only copy of its current code is a writable layer, so the migration
has to capture it before recreating anything: create the volume, seed it from
the live container's tree, then recreate the container against it. Per site,
verified by version before and after. This is the one operation where getting
the order wrong loses the code, so it is scripted once and run seven times, not
improvised per site.

### Two alternatives, and why not

**Fetch the code from the control plane at container start.** Tempting, since
the release is already served from there and the code would always be current.
Rejected: it makes getjoinery.com a runtime dependency of somebody else's
disaster recovery. A customer rebuilding their own container should not need our
server to be alive, and the archive they installed from is already in their
hands. It would also move seeding out of a deliberate operator action into an
automatic one, which is the property that made the writable layer dangerous.

**Immutable infrastructure.** The textbook Docker answer: no in-place upgrades
at all, every release is a new image, containers are cattle. It would also fix
this, and it is rejected deliberately — it costs a full image rebuild and a
recreate per site per release, discards the fast publish/upgrade path the
platform is built around, and imposes downtime on an upgrade that currently
takes seconds. The platform's model is in-place upgrades on both bare metal and
Docker; the fix should make that model safe, not replace it.

### Follow-on, deliberately out of scope

With the code gone, what remains per-site in an image is a vhost and three
environment values, and the only reason a shared image is not possible is that
paths are named after the site (`/var/www/html/joinerydemo`). Un-naming them to
a fixed path would let all sites run one image — meaning **one build to patch
the OS instead of seven**, which is the reason the fleet is still on April's
Ubuntu packages. The disk savings were already taken by the shared base image
work (`specs/implemented/docker_shared_base_image.md`); this is purely about the
cost of patching.

Related and also out of scope: `POSTGRES_PASSWORD` is currently baked into each
site image as a build argument, so it sits at rest in an image layer. A shared
image cannot do that, forcing it out to container runtime configuration where it
belongs.

## Build tasks

1. ✅ The guard in `do_site_docker`, ahead of the preflight stop, plus
   `--allow-downgrade` and the refusal message.
2. ✅ `installer_contract_test` assertions for the guard and its ordering.
3. ✅ Correct `docs/deploy_and_upgrade.md` § *Upgrade-flow split* — its "rebuild
   each site container" instruction is what walks an operator into this.
4. Part 2: mount `{site}_code` at the site root in the installer's `docker run`,
   and stop the Dockerfile copying the site tree in.
5. Part 2: seed the volume from `$ARCHIVE_ROOT` at install time; refuse to start
   on an empty volume.
6. Part 2: the one-time migration for the seven existing containers.
7. Part 2: remove the Part 1 guard and its `installer_contract_test` section,
   and correct `docs/deploy_and_upgrade.md` to describe a rebuild that no longer
   touches code.

## Built — Part 1 (2026-08-04, install.sh 2.29)

`assert_rebuild_moves_code_forward()` sits immediately above `do_site_docker`
and is called as that function's first real act, before the port-check preflight
stop. `ARCHIVE_ROOT` moved to the top of `do_site_docker` so the guard can see
it.

Two departures from the spec above, both discovered while building:

- **The running version is read with `docker cp`, not `docker exec`.** A
  previous failed run can leave the container stopped, and `docker exec` cannot
  read a stopped container — it would report the version unreadable and refuse a
  rebuild that was in fact moving forward. `docker cp` reads either state.
- **The guard checks `docker ps -a`, not `docker ps`.** Same reason: a stopped
  container still holds the only copy of the site's code.

Verified by exercising the extracted function against a stubbed `docker` across
all eight rows of the outcomes table — no container, newer, equal, older,
archive VERSION missing, container VERSION unreadable, `--wipe-data`,
`--allow-downgrade` — each producing the specified exit status. The
`installer_contract_test` section pins the guard's existence, its ordering ahead
of the preflight stop, `sort -V` comparison, refusal rather than warning, both
bypasses, and `--allow-downgrade` being the only one.

## Open decisions

- **Does Part 2 happen before or after the next publish?** Doing it first means
  the fpm rebuild is safe by construction; doing it after means one more
  hand-checked rebuild cycle. Recommendation: first — the seven sites are
  already fragile, and the fpm fix is the operation that would expose it.
- **Does the migration run per site, or all seven in one pass?** Per site with a
  verified stop between each is slower and gives six chances to catch a mistake
  before it is repeated. Recommendation: per site, joinerydemo first.

Settled while specifying: `maintenance_scripts` shares the code volume, because
the volume covers the whole site root rather than `public_html` alone.
