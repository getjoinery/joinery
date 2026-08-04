# A container rebuild must never move a site's code backward

**Status:** Spec, unbuilt
**Date:** 2026-08-04

## The problem

On the Docker host, a site's PHP code lives in the container's **writable
layer** — not on a volume. The ten named volumes cover postgres, config,
uploads, static_files, backups, cache, logs and sessions. `public_html` is not
among them.

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

1. **Code lives on a named volume.** `{site}_code` mounts at
   `public_html`, alongside `maintenance_scripts` (the CMD and several jobs
   reach into it). In-place upgrades then survive container recreation the same
   way uploads already do.

2. **The image ships the release as a bundle, not as the live tree** — a
   compressed archive at a known path, not a second extracted copy that would
   double an already 2.5 GB image.

3. **Container start reconciles, and only rolls forward:**
   - volume empty → extract the bundle (a fresh site)
   - volume version < bundle version → run the standard upgrade path from the
     bundle, so migrations and `update_database` happen through the mechanism
     that already knows how to do them
   - volume version >= bundle version → leave the code alone

That third rule is the whole fix: **a stale image becomes inert instead of
destructive.** Rebuilding for an OS or PHP change stops touching site code at
all, which is what an operator following the deploy doc already believes is
happening.

### Migrating the seven existing containers

Each one's only copy of its current code is a writable layer, so the migration
has to capture it before recreating anything: create the volume, seed it from
the live container's tree, then recreate the container against it. Per site,
verified by version before and after. This is the one operation where getting
the order wrong loses the code, so it is scripted once and run seven times, not
improvised per site.

### The alternative, and why not

The textbook Docker answer is immutable infrastructure: no in-place upgrades at
all, every release is a new image, containers are cattle. It would also fix
this, and it is rejected deliberately — it costs a full image rebuild and a
recreate per site per release, discards the fast publish/upgrade path the
platform is built around, and imposes downtime on an upgrade that currently
takes seconds. The platform's model is in-place upgrades on both bare metal and
Docker; the fix should make that model safe, not replace it.

## Build tasks

1. The guard in `do_site_docker`, ahead of the preflight stop, plus
   `--allow-downgrade` and the refusal message.
2. `installer_contract_test` assertions for the guard and its ordering.
3. Correct `docs/deploy_and_upgrade.md` § *Upgrade-flow split* — its "rebuild
   each site container" instruction is what walks an operator into this.
4. Part 2: code volume, bundle-in-image, roll-forward reconcile at start.
5. Part 2: the one-time migration for the seven existing containers.

## Open decisions

- **Does Part 2 happen before or after the next publish?** Doing it first means
  the fpm rebuild is safe by construction; doing it after means one more
  hand-checked rebuild cycle. Recommendation: first — the seven sites are
  already fragile, and the fpm fix is the operation that would expose it.
- **Does `maintenance_scripts` share the code volume or get its own?** Shared
  is simpler; separate matches how the two are versioned. Recommendation:
  shared — they ship and upgrade together.
