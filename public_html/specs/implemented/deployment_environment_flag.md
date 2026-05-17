# Deployment Environment Flag

**Status:** Implemented 2026-05-17. (Was: Proposal — recommended in favour, all §6 decisions resolved.)
**Author:** Analysis prepared 2026-05-17; revised 2026-05-17 after a call-site inventory.
**Informal name:** "the docker boolean"
**Origin:** Surfaced during the Email Forwarding install unification work
(`specs/email_forwarding_install_unification.md`).

---

## 1. Problem

Several parts of the platform need to know whether they are running inside a
container. The most concrete case today is `PluginProvisioning::resolveHost()`,
which branches on `file_exists('/.dockerenv')` to decide how to reach a service
on the host. Each place that needs the answer re-derives it at runtime with its
own heuristic, and **every available heuristic is unreliable** across runtimes
or kernel versions:

| Method | Limitation |
|---|---|
| `/.dockerenv` | Docker only — absent under podman, containerd, CRI-O, LXC. |
| `/proc/1/cgroup` keyword match | Works on cgroup v1; uninformative on cgroup v2 (modern hosts often show just `0::/`). |
| `systemd-detect-virt --container` | Most general, but the binary is frequently absent from minimal images — exactly the images in question. |
| `container` env var | Set by podman / systemd-nspawn; **not** by Docker. |

There is no single bullet-proof runtime check. The proposal is to detect the
environment **once, at install time**, and record it, so code reads a stored
value instead of re-detecting.

---

## 1a. Inventory of call sites

Where the platform detects "am I in a container" today:

| # | Call site | Mechanism |
|---|---|---|
| 1 | `includes/PluginProvisioning.php` — `resolveHost()` | `file_exists('/.dockerenv')` |
| 2 | `adm/logic/admin_scheduled_tasks_logic.php` — cron-setup UI hint | `/.dockerenv` **or** `/proc/1/cgroup` grep |
| 3 | `maintenance_scripts/install_tools/install.sh` — `is_docker()` | `/.dockerenv` + cgroup grep |
| 4 | `maintenance_scripts/sysadmin_tools/backup_project.sh` | `/.dockerenv` + cgroup grep |
| 5 | `maintenance_scripts/sysadmin_tools/manage_domain.sh` — `is_docker_site()` | per-site, host-side |
| 6 | `maintenance_scripts/archive/server_setup.sh` — `is_docker()` | `/.dockerenv` + cgroup grep |

Sites 1–2 are PHP runtime code; 3–6 are bash scripts. **The two PHP sites
already disagree**: `resolveHost()` checks only `/.dockerenv`, while
`admin_scheduled_tasks_logic.php` also checks the cgroup file — so on a
non-Docker container (podman, cgroup v2) they can return different answers
right now. That is the concrete bug this flag removes.

Of the six, **three become flag customers** — the two PHP sites, plus
`backup_project.sh`, which runs within an already-installed site and can read
its `Globalvars_site.php`. The other three do not. `install.sh` runs *before* a
site exists (it is the installer — no flag file to read, and it decides the
mode itself), and `server_setup.sh` is archived dead code; both keep their own
install-time host detection. `manage_domain.sh`'s `is_docker_site()` already
queries the Docker daemon directly (`docker ps`) — that is authoritative, not a
heuristic, so it is left as-is.

**Not a customer:** `server_manager` (`JobCommandBuilder`, `install_node_form`)
branches on `$node->get('mgn_container_name')` — a value *stored per node*,
describing *remote* nodes, not the host it runs on. It is not self-detection
and needs no flag. It is, however, the proof the pattern works: the Server
Manager already records the environment fact rather than re-detecting it. This
spec applies the same idea to the local deployment.

---

## 2. Proposal

`_site_init.sh` — the single script every install path funnels through to
create a site — already knows the mode: `install.sh` resolves `--docker` /
`--bare-metal` (or its auto-fallback) into `$MODE` and passes it down as
`_site_init.sh`'s existing `--docker-mode` flag (`$DOCKER_MODE`). `_site_init.sh`
also already generates `config/Globalvars_site.php` from the
`default_Globalvars_site.php` template by `sed`-substituting `{{PLACEHOLDER}}`
tokens. So recording the flag is **one new placeholder**
(`{{DEPLOYMENT_ENVIRONMENT}}`) and **one `sed` line** driven by the
`$DOCKER_MODE` the script already holds — no new operator surface. It is
written to this **file-based** per-deployment config — **not** the
`stg_settings` database table.

*Why file, not DB:* this is a property of the physical machine.
`Globalvars_site.php` belongs to the deployment, is outside source control, and
does not travel with a database backup. A value placed in `stg_settings` would
be copied into any environment that restores the database and would then be
silently wrong.

*Shape:* a string is more future-proof than a bare boolean, stored as a
`$this->settings[...]` entry to match the file's actual convention —
`Globalvars_site.php` is a list of `$this->settings['key'] = ...;` assignments,
`require`d inside the Globalvars constructor:

```php
$this->settings['deployment_environment'] = 'docker'; // 'baremetal' | 'docker' | 'other'
```

*Access from PHP:* no new accessor is needed. `Globalvars` already exposes
every value in that file through `get_setting()`:

```php
Globalvars::get_instance()->get_setting('deployment_environment')
```

`get_setting()` reads `$this->settings` first, so a value present in the file
returns without a DB hit. The stored value is authoritative — there is **no
live-detection fallback**. The flag is the single source of truth, treated
exactly like every other value in `Globalvars_site.php` (DB credentials,
paths): set once at install, corrected by editing the file if ever wrong.
Existing deployments are backfilled once (§6, decision 4).

*Access from bash:* the file cannot be `source`-d (it is PHP) nor `require`-d
standalone (it needs the Globalvars object context). `backup_project.sh`, which
runs within an already-installed site, reads the value by `grep`-ing that one
fixed-format line:

```bash
DEPLOY_ENV="$(grep -oP "settings\['deployment_environment'\]\s*=\s*'\K[^']+" \
    /path/to/config/Globalvars_site.php)"
```

`install.sh` and the archived `server_setup.sh` are host-bootstrap scripts that
run *while creating* a deployment — they already hold `$MODE` / `$DOCKER_MODE`
and have no installed site to read a flag from, so they keep their own
install-time logic. `manage_domain.sh` already asks the Docker daemon directly
and is left untouched. No new storage file is introduced: one `$this->settings`
entry, read by the two PHP sites via `get_setting()` and by `backup_project.sh`
via `grep`.

---

## 3. Arguments FOR

1. **Single source of truth.** One value, set once, read by all three flag
   customers (§1a) — instead of scattered heuristics that *already* disagree
   (the two PHP sites can return different answers under a non-Docker container
   today).
2. **Set deliberately, when the truth is known.** The installer — or the
   operator running it — knows the real environment. That beats runtime
   guessing, especially under non-Docker runtimes and cgroup v2, where every
   runtime heuristic degrades.
3. **Cheap to read.** No per-request `/proc` parsing or filesystem probing.
4. **Inspectable and correctable.** An operator can see the value and fix a
   misdetection in one place; today a wrong heuristic is invisible and
   duplicated.
5. **Decouples callers from detection mechanics.** If the detection method must
   change, only the installer changes — not every call site.

---

## 4. Arguments AGAINST — what survives scrutiny

The first draft listed six objections. The §1a inventory and a closer look
dismiss four of them:

- *YAGNI* — dismissed. Six detection sites today, three of them flag customers,
  and the two PHP ones **already disagree** (§1a). The need is demonstrated,
  not speculative.
- *Detection is still heuristic, just moved* — dismissed. The installer does
  not detect at all: `install.sh` *decides* docker vs. bare-metal and builds
  it, then passes that decision to `_site_init.sh`, which records it (§2). The
  recorded value is authoritative by construction, not a guess.
- *Two sources of truth* — not an objection, a discipline rule: one accessor
  (or `php -r`), every caller through it (already in §5).
- *New config surface* — negligible: one bare assignment in a file that
  already exists, read with no new storage location (§2).

Two honest caveats remain — neither blocks the proposal; both are things to get
right:

1. **It can go stale — and there is no auto-correction.** Physically migrate a
   deployment (bare metal → container) without re-running the installer and the
   flag is simply wrong until someone edits it. With autodetection removed
   (§6, decision 3) there is no self-healing fallback. This is accepted as
   consistent: every other value in `Globalvars_site.php` (DB credentials,
   paths) has the same property — authoritative, wrong only if mis-set — and a
   real migration re-runs the installer, which rewrites the file.
2. **"In a container" is a coarse signal.** It does not answer the *real*
   questions — host gateway IP, external hostname, is-there-a-front-relay. The
   flag must not become an overloaded proxy for topology facts it cannot
   determine. A usage-discipline rule: callers ask only "container or not,"
   nothing more.

---

## 5. Recommendation

**In favour.** The §1a inventory removed the one doubt that mattered (YAGNI):
six detection sites today, three of them flag customers, with a demonstrated
live inconsistency between the two PHP ones. Adopt it, with *all* of:

- File-based storage in `Globalvars_site.php`, never `stg_settings` (§2).
- A string enum, not a bare boolean.
- PHP reads through the existing `Globalvars::get_setting()`; `backup_project.sh`
  reads by `grep` (§2). No new accessor. No `/.dockerenv` checks survive in the
  running application — `install.sh` keeps host-bootstrap detection only because
  it runs before any site exists.

The flag is the single source of truth. The staleness caveat (§4.1) is
accepted: it is the same property every other `Globalvars_site.php` value has,
and a real migration re-runs the installer. The coarse-signal caveat (§4.2) is
usage discipline, not a reason to reject.

---

## 6. Open questions

1. **Shape** — *Resolved (2026-05-17).* Plain string enum
   (`'docker' | 'baremetal' | 'other'`). Structured facts (gateway IP, external
   hostname) are rejected: the motivating caller `resolveHost()` uses the flag
   only as a gate and then computes the host gateway *live* — storing that
   gateway would be a stale-data surface (§4.1) serving no caller better than
   runtime computation already does. Enum over bare boolean costs nothing and
   reserves `'other'` for a future non-Docker container runtime without a
   schema change.
2. **Install-time detection** — *Resolved (2026-05-17).* No new operator
   surface is needed. `_site_init.sh` already receives the mode via its
   `--docker-mode` flag and already templates `Globalvars_site.php`; it writes
   `$DOCKER_MODE` through as one more substituted placeholder (§2). The
   installer is not *detecting* the environment — `install.sh` *decides* it and
   builds accordingly — so there is no heuristic to be wrong. A deployment
   created outside `_site_init.sh` (a hand-built box, a pre-flag install) needs
   the value set explicitly — see decision 4.
3. **Migration safety / no autodetection** — *Resolved (2026-05-17).* The
   accessor trusts the stored value unconditionally: no periodic re-validation,
   and no live-detection fallback. Autodetection is removed from the design
   entirely — the flag is the single source of truth. Re-validating against a
   heuristic already deemed unreliable (§1) would only generate false-mismatch
   noise in exactly the environments the flag exists to serve. A wrong flag is
   corrected at the source, by editing `Globalvars_site.php` or re-running the
   installer.
4. **Backfilling existing deployments** — *Resolved (2026-05-17).* No automated
   backfill. The current fleet is small and known: every production site runs
   in Docker, and the single host/dev server is bare-metal. The value is set by
   hand once, after this feature is deployed — `'docker'` on the prod sites,
   `'baremetal'` on the host/dev server. New sites get it from `_site_init.sh`
   (decision 2) thereafter, so the manual step never recurs.
