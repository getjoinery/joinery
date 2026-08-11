# A publish must deliver what it promises

**Status:** Implemented 2026-08-11, published in 0.8.263, verified on real
instances. Both defects were found while gating the Linode StackScript.

Both halves are live and were exercised rather than asserted. A fresh StackScript
instance now downloads `mailbox` and `joinery_ai` from the published-archives
manifest, installs and activates them — confirmed twice, with rows in
`plg_plugins` and `plg_active = 1`. The publish guard was proven by attempting a
real publish of a release naming a plugin that did not exist: it refused, before
writing anything, and `VERSION` was untouched afterwards. The version guard has
since republished 0.8.261, 0.8.262 and 0.8.263 from getjoinery on a bare command
with no version argument — the exact invocation that minted the colliding
0.8.259.

Two failures in the publish pipeline, unrelated in mechanism and identical in
shape: **a publish states something it does not deliver, and nothing checks.**

1. **The default plugin bundle never arrives.** The deploy form promises the
   everyday-tools replacement; the site comes up as bare core.
2. **A version number does not identify a build.** Two different archives were
   published as `0.8.259`, one with a security fix in it and one without.

They are specified together because the fix is the same surface — guards in
`publish_upgrade.php` that refuse to publish a claim that is not true — and
because fixing one and not the other leaves the pipeline just as untrustworthy.

**Owner requirement (2026-08-11):** the `personal` bundle is a **default
install**, not an optional extra. A new Joinery box is meant to arrive as the
everyday-tools replacement, and today it arrives as the platform with nothing on
it.

---

# Part 1 — The personal bundle has to actually install

## What is wrong, in plain terms

The deploy form promises a self-hosted replacement for the everyday Google
tools. What a deployer actually gets is files, calendar and nothing else — no
mail, no assistant — because the two plugins that make up that promise are never
put on the machine.

Drive and the personal calendar are core, so they work. `mailbox` and
`joinery_ai` are plugins, and a fresh install has no plugin files at all.

## Evidence

From the install log on a box built from the published 0.8.260 archive:

```
Installing the 'personal' plugin bundle...
Installing bundle 'personal': mailbox, joinery_ai
  mailbox: no plugin directory on disk — skipped
  joinery_ai: no plugin directory on disk — skipped
2 plugin(s) did not install: mailbox, joinery_ai
ERROR: Warning: the 'personal' bundle did not install cleanly.
```

On the resulting site: `public_html/plugins/` is empty and `plg_plugins` has no
rows. The site is otherwise healthy — HTTPS, a real certificate, login, admin
gating all fine.

## Root cause: two independent halves

**1. A fresh install never obtains plugin files.**

The published core archive contains exactly one plugins entry —
`./public_html/plugins/` — and nothing inside it. Plugins are published as their
own archives, and nothing in the install path fetches them: `_site_init.sh`
invokes `install_bundle.php`, which installs and activates plugins that are
already on disk. Its own docblock states the assumption it relies on — *a new
install ends up with every plugin's files on disk* — and for a published release
that has never been true.

**2. The release-serving site does not publish the bundle's plugins.**

`getjoinery.com` publishes what it has on disk. Its published set is
`event_manager`, `joinery_ai`, `server_manager`, `store` — **`mailbox` is
absent, because getjoinery has no mailbox directory.** So even with a fetch step
added, half the `personal` bundle could not be obtained. `included_in_publish`
defaults to true and is not the limiter here; presence at the publishing site
is.

These are independent. Fixing either alone still leaves a broken bundle.

## What already exists and should be reused

The upgrade path already solves "get plugin files from the release site", and
its pieces are reachable without credentials:

- `https://getjoinery.com/utils/upgrade?serve-upgrade=1` returns JSON to an
  anonymous caller, including `published_plugins[]` with `name`, `version` and
  an absolute `url` per archive.
- Those URLs serve anonymously —
  `/static_files/plugins/joinery_ai-0.19.1.tar.gz` returns `200`,
  `application/x-gzip`. Archives are written to `{site_dir}/static_files/` by
  `publish_upgrade.php`.
- `required_plugins` in the same response is the `is_system` list, and is
  currently empty.

A fresh install already fetches the core archive from the same host with no
credentials, so this adds no new trust relationship and no new endpoint.

## Options

**A. Ship the bundle's plugins inside the core archive.** No network step, and
the bundle cannot half-arrive. *Catch:* it welds plugin versions to core
releases, ships the files to every site whether or not the bundle is wanted, and
reverses the deliberate split that gives plugins their own versions and
archives.

**B. Fetch the bundle's plugins at install time from the published manifest.**
Reuses the mechanism above; plugin files arrive the same way they do on every
upgrade; versions stay independent. *Catch:* the install gains a second network
dependency, and a partial fetch needs a defined outcome (below).

**C. Mark the bundle's plugins `is_system`.** *Rejected.* `is_system` means
*always re-download these on upgrade*; it says nothing about a fresh install,
which never runs the upgrade path. It would also change behaviour for the whole
fleet to fix the one case it does not cover.

## Recommendation

**B, plus a publish-time guard.**

The fetch belongs next to the existing bundle step: read
`install_bundles.json`, resolve each plugin against `published_plugins` from the
upgrade source the install already knows (`upgrade_source`, written by
`_site_init.sh`), download and unpack into `public_html/plugins/`, then run
`install_bundle.php` exactly as now.

The guard is the durable half. **`publish_upgrade.php` should refuse to publish
— or at minimum fail loudly — when a plugin named in `install_bundles.json` is
not in the published set.** Today the promise and the delivery are recorded in
two places that never check each other, which is precisely how this shipped: the
bundle file named `mailbox`, the publishing site had never heard of it, and
nothing anywhere compared the two. A guard turns a silent product failure into a
publish that stops and says which plugin is missing.

Getting `mailbox` onto getjoinery is then an operational prerequisite, not a
code change — but it is not optional, and the guard is what stops it being
forgotten again. **It is a smaller operation than it sounds: publishing walks
the filesystem** (`glob(plugins/*/plugin.json)`, then `included_in_publish` and
`deprecated` from the manifest) **and never consults the database.** The plugin
has to be present on disk; it does not have to be installed or activated on the
release site. Copying the directory in is enough, and getjoinery's own site
behaviour does not change.

## Failure behaviour

Needs deciding, because both directions have a bad case. A default bundle that
silently does not arrive is what got us here, so silence is not acceptable. But
failing the whole install on a transient download error would destroy a site
that is otherwise fine over something a retry would fix.

Proposed: retry the fetch, and on final failure leave the site running but end
the install on a **loud, non-zero, unmissable** notice naming the plugins that
did not arrive and the one command that completes the job. The site is usable;
nobody is left believing they got mail when they did not.

## Tests

- **safe tier:** every plugin named in `install_bundles.json` exists in the repo
  (already checked); and the new guard exists in `publish_upgrade.php`.
- **A publish-time check with teeth:** publishing a tree where a bundle plugin
  is absent must fail. This is the check that would have caught the live defect,
  and it can be exercised without a real box.
- **Live gate:** a fresh StackScript instance ends with `mailbox` and
  `joinery_ai` present on disk, rows in `plg_plugins`, and both active. This is
  the only check that proves the whole chain, and it is the one that failed.

---

# Part 2 — A version number must identify one build

## What is wrong, in plain terms

Two people can be running `0.8.259` and be running different software. One of
those builds contains a security fix and the other does not, and there is no way
to tell them apart from the version they report.

## Evidence

`getjoinery.com` published its own `0.8.259` on 2026-08-10, built from the code
it was running. On 2026-08-11 a publish on dev auto-bumped from dev's `VERSION`
of `0.8.258` and minted `0.8.259` as well — a different tree, containing the
unmatched-request fix. Neither publish warned. The dev copy was verified to
contain the fix; the getjoinery copy was downloaded and verified not to.

Resolved by hand by going forward to `0.8.260`, published with the version named
explicitly at both ends. The stale pair is inert, and the mechanism that
produced it is untouched.

## Root cause

`publish_upgrade.php` auto-detects the next version by reading **the local
`VERSION` file** and incrementing the patch. Every site runs that same code and
each has its own `VERSION`, so any two sites will eventually mint the same
number from different trees. Nothing catches it: the duplicate check reads the
**local** `upg_upgrades` table, and neither site has ever seen the other's row.

The deeper error is conceptual. Publishing means two different things depending
on who is doing it — *I wrote new code, give it a number*, and *I am serving
code somebody else wrote, keep its number* — and one command with one behaviour
serves both. getjoinery is a relay: it upgrades itself from dev and republishes
so it only ever serves what it runs. Every relay publish that bumps invents a
number for code it did not author.

**Role cannot be inferred from `upgrade_source`.** Dev's `upgrade_source` is
`https://getjoinery.com` — the authoring site points at the release server, so
that setting says where a site was installed from, not who authors its code.

## Recommendation

**A publish never invents a version for code it did not author.**

Record at upgrade time the version received from the source
(`upgrade_received_version`). The place to do it already exists and already
holds both values: `upgrade.php` compares the version the server announced
against the `VERSION` inside the tarball, and **trusts the tarball** — so the
version already travels with the code rather than with whoever served it. That
reconciliation point is where the received version gets recorded; nothing new
has to be discovered to know it.

At publish time compare it with the local `VERSION`:

- **They match** — this tree is what the source sent. Relay publish: republish
  that same version, never bump. This is exactly the manual step the promotion
  procedure already documents, made automatic and unforgettable.
- **They differ** — code was authored here. Auto-bump as now.

**Backstop, independent of role detection: a cross-site duplicate check.** Before
publishing version X, read the upgrade source's manifest — the anonymous
`?serve-upgrade=1` response already carries `system_version`. If upstream
already serves X, refuse unless the content matches or the operator passes an
explicit override. This catches a collision even if the role logic is wrong,
which is the property that matters for a guard.

Publishing the identical number remains legal on purpose: the downgrade guard
rejects only *lower*, and republishing what was received is the whole point of a
relay.

## Tests

- **safe tier:** publish refuses to auto-bump when `VERSION` equals
  `upgrade_received_version`; `upgrade.php` records that value on a successful
  upgrade.
- **safe tier:** the manifest exposes `system_version` (it already does), so the
  cross-site check has something to read.
- **Live gate:** publish on dev, upgrade getjoinery, publish on getjoinery with
  no version argument, and assert it republished the received number rather than
  minting one. That sequence is what produced the collision by hand.

---

## Docs to update

- `docs/plugin_developer_guide.md` — how plugin files reach a *fresh* install,
  not only an upgrade.
- `docs/deploy_and_upgrade.md` — the published-archives manifest as the single
  source for plugin files, on install as well as upgrade.
- `specs/linode_stackscript.md` — the default bundle is part of what a deploy
  delivers, so its gates should assert the plugins arrived.
- `docs/deploy_and_upgrade.md` — the promotion sequence, and what a version
  number means: one number, one build, whoever is serving it. The manual
  instruction to pass the version explicitly on a relay publish becomes a
  description of what the tool does on its own.

## Decisions

**D1 — a fresh install takes the newest published plugin. Settled by
construction, not preference.** `publish_upgrade.php` wipes the existing plugin
archives before regenerating them, so the serving site holds exactly one archive
per plugin: getjoinery currently offers `event_manager-1.1.5`,
`joinery_ai-0.19.1`, `server_manager-1.16.11`, `store-1.3.5` and nothing else.
There is no older version to pin to. Pinning would first require archive
retention and a per-release manifest of plugin versions — a much larger change,
and one worth making only if reproducible installs turn out to matter.

**D3 — `install_bundle.php` stays install-only.** `upgrade.php` already owns
refreshing plugin files, and it downloads the published archives on every run.
Teaching a second tool to update the same directories on a different trigger
buys nothing and creates a question with no good answer: which of the two is
authoritative when they disagree. A bundle install puts files there once; the
first upgrade brings them current.

**D2 — no bundle field on the deploy form. The `personal` bundle comes with
every new install.** It is what a Joinery box is, not a configuration of one.
A dropdown with one entry asks the deployer to make a choice that does not
exist, and the second bundle — when there is one — is a different product and
may well want its own listing rather than a field on this one.

The handoff script's existing `JOINERY_INSTALL_BUNDLE` environment variable
stays as an internal override for testing. It is not a form field and is not
documented for deployers; nothing needs to change for it to keep working.

**D4 — the two stale `0.8.259` archives are left alone.** Nothing serves either
one now; both sites are on `0.8.260`. Rewriting published history to reclaim a
number would be a larger risk than the ambiguity it removes, and the ambiguity
is confined to a version no deployment is running.

## Open decisions

None. Ready to build.
