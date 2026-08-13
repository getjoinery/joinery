# PHP 8.5 / PostgreSQL 18 — Stack Cutover

**Status:** Unbuilt — gated on 26.04 nodes existing.
**Date:** 2026-08-13 (split out of `specs/implemented/php_85_pg18_code_prep.md`,
whose Phase 1 is built, committed and deployed fleet-wide).
**Companion:** `specs/fleet_ubuntu_2604_postgres_upgrade.md` (the fleet migration
itself — this spec is the code-side half of the same cutover).

## What this is

The preparation work is finished. Every code change that could be made
*compatible with both stacks* landed in Phase 1 of the prep spec: version
literals, the IMAP removal, installer PHP parameterization, PostgreSQL SCRAM
auth, the CSV escape change, the Composer floor and platform pin. All eight
Joinery nodes carry it.

What is left is the set of changes that **cannot be made compatible with both
stacks** — each one flips a target from 24.04/PHP 8.3/PG 16 to 26.04/PHP 8.5/PG
18, and breaks the old target in doing so. They land as the fleet migrates, not
before.

The behaviour verification that needed the new stack is **already done** — run on
a real Ubuntu 26.04 / PHP 8.5.4 / PostgreSQL 18.4 box on 2026-08-06, with results
recorded in the prep spec. Nothing below is waiting on an unknown; it is waiting
on nodes.

## The cutover items

1. **Base image.** `Dockerfile.base:17` — `FROM ubuntu:24.04` becomes
   `ubuntu:26.04`, with a `BASE_IMAGE_VERSION` bump at `install.sh:119`
   (currently `1.1`). Every host then needs `install.sh build-base` before site
   containers are recreated.

   **Verified but deliberately not landed.** `joinery-base:2.0` was built
   `FROM ubuntu:26.04` on the test box and a container site on it serves, with
   the `Dockerfile.template` runtime globs and the container pg_hba round trip
   both proven. Merging it forces `install.sh build-base` on every Docker host,
   which is why it waits for the campaign rather than riding a release.

2. **Linode stackscript.** Point
   `install_tools/linode_stackscript_wrapper.sh` and the Linode-side deploy
   image at 26.04, then re-run the quick-deploy app's live gates from
   `specs/linode_quick_deploy_app.md`.

3. **Drop 24.04 from the installer gate**, once no node remains on it. The
   corresponding assertion in `tests/unit/installer_contract_test.php:233` moves
   with it. This is an installer-scope decision, not an application-scope one:
   per the compatibility policy, an existing 24.04 site keeps running and keeps
   receiving upgrades — what stops is `install.sh` provisioning a *new* one, and
   `--allow-unsupported-os` still covers that case by hand.

4. **Documentation restatement.** Per the docs rule, these read as current state
   with no migration narrative: `docs/installation.md:95` and `:233`,
   `docs/deploy_and_upgrade.md:19`,
   `maintenance_scripts/install_tools/INSTALL_README.md:440` and `:527` (the
   latter names `postgresql-16-main.log`), and the Server Manager overview's node
   OS expectations.

5. **PostGIS pin.** `specs/geolocation_postgis_spec.md:47` and `:578` pin
   `postgresql-16-postgis-3`. That spec is unbuilt; update the pin when it is
   built rather than now.

## Verification still outstanding

These need hardware that did not exist when Phase 1 was verified, and are the
only gaps in the prep spec's own verification list:

- `migrate_site_to_code_volumes.sh` and `install_email.sh` on a container host.
- The `config.platform.php` pin on a 26.04 / PHP 8.5 build box.

Item 1's base-image work above already closed the Dockerfile CMD globs and the
container pg_hba round trip.

## Out of scope

- The fleet migration procedure, per-node ordering, and rollback — see
  `specs/fleet_ubuntu_2604_postgres_upgrade.md`.
- Multi-distro support beyond Ubuntu — see
  `specs/multi_distro_install_refactor.md`.
- The `stripe/stripe-php` major bump. Held deliberately: it moves the pinned
  Stripe API version and changes subscription response shapes, and must not ride
  along with an OS migration — when checkout misbehaves afterwards, nobody should
  have to guess whether Ubuntu, PHP, or Stripe caused it. Its reasoning is
  recorded in the prep spec's Dependency Refresh section.

## Open decisions

None. The prep spec resolved all three of its decisions; every item here is a
mechanical target flip whose only gate is node availability.
