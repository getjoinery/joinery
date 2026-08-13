# Installer Defects Found Provisioning the Soak VPS

**Status:** IMPLEMENTED. Built and committed 2026-08-06; filed 2026-08-13.
§§ 1–6 fixed in
`install.sh` 2.43 / `Dockerfile.template` 4.8 / `_site_init.sh` 2.7 /
`fix_permissions.sh` 2.7, contract-test assertions added (274 checks green),
live gate written at `tests/functional/install/install_container_gate.sh`.

Two things are owned by the **pre-launch verification round** rather than by this
spec: running that container gate on a real box, and the § 7 publish — every fix
here is committed, but none reaches an installing user until a release carries it.

**§ 8 is built**
(`install.sh` 2.46, 2026-08-07) and proven: the installer's own HTTP-01 path
issued a certificate for `upgradetest.getjoinery.com` on a dual-stack 26.04
box.

Found by running the published install path end to end on a bare Ubuntu
24.04.4 Linode (45.33.72.32, 1 vCPU / 961 MB) to provision the soak host of
`specs/drive_sync_soak.md`: `install.sh docker`, then
`install.sh -y site soak soak.getjoinery.com 8080 --no-ssl`, both from
`https://dev.getjoinery.com/utils/latest_release` (0.8.239, carrying
`install.sh` 2.38). Every defect below was observed on that box, not inferred
from reading, and each was then checked against the current working tree —
which is where the fixes go.

None of these are Drive-related. They are what a stranger following
`docs/quickstart.md` hits today.

| § | Defect | Severity | Still live in tree? |
|---|---|---|---|
| 1 | `install.sh docker` exits silently on non-interactive stdin | high | **no** — fixed, unpublished |
| 2 | `-y` is honoured only *before* the subcommand | high | **no** — fixed, unpublished |
| 3 | Post-start health probe cannot see a site nobody can load | high | **no** — fixed, unpublished |
| 4 | `cache/static_pages` is root-owned; page caching off for the life of the install | medium | **no** — fixed, unpublished |
| 5 | Two cron.d files run the same scheduled-task runner | medium | **no** — fixed, unpublished |
| 6 | `iptables-persistent` installed without `DEBIAN_FRONTEND` | low | **no** — fixed, unpublished |
| — | `--no-ssl` site 301s into a `:443` vhost that does not exist | high | **no** — fixed, unpublished |
| — | Database password baked into the image as ARG + ENV | high | **no** — fixed, unpublished |
| 8 | Automatic SSL never runs on a dual-stack host | high | **yes** — found later, unbuilt |

§§ 1–7 are fixed in the tree and still broken for everyone installing from a
release, because nothing has been published; the publish is § 7. **§ 8 was
found afterwards, setting SSL up on the same box, and is not built.**

---

## 1 — `install.sh docker` exits silently when stdin is not a terminal

**Severity: high.** The first command in every scripted, CI, cloud-init or
remote-ssh install.

`install.sh` runs under `set -e` (`install.sh:220`). The Docker confirmation is
a bare read:

```bash
read -p "Would you like to install Docker now? [Y/n] " -n 1 -r
```

`read` returns 1 at EOF. Under `set -e` that ends the script — no message, no
error, exit status 1, the last line on screen being `Docker is not installed`.
Confirmed mechanically:

```bash
$ bash -c 'set -e; read -p "prompt: " -n 1 -r </dev/null; echo REACHED_AFTER_READ'
$ echo $?
1
```

Observed exactly this on the box: the run stopped dead after
`[INFO] Docker is not installed`, and `which docker` came back empty.

**Fix.** A prompt that cannot be answered is a decision, not an error. Give the
read an explicit default when stdin is not a terminal:

```bash
if [ "$ASSUME_YES" -eq 1 ] || [ ! -t 0 ]; then
    print_info "Proceeding with Docker installation (no terminal to prompt on)"
else
    read -p ... || true
fi
```

Two rules to apply everywhere, not just here: **no bare `read` under `set -e`**
(every one of them needs `|| true` or an explicit EOF branch), and **every
interactive prompt in the script needs a non-tty answer**. Audit all prompts in
one pass; this is the class, not the instance.

## 2 — `-y` is honoured only before the subcommand

**Severity: high**, because it compounds § 1 into a silent no-op.

The top-level parse loop breaks on the first non-flag argument:

```bash
while [[ $# -gt 0 ]]; do
    case "$1" in
        -y|--yes) ASSUME_YES=1; shift ;;
        -q|--quiet) QUIET_MODE=1; shift ;;
        *) break ;;
    esac
done
```

so `install.sh docker -y` reaches `do_docker_install` with `-y` as an unread
positional and `ASSUME_YES=0`. The `site` subcommand has a parse loop of its
own and *does* accept trailing flags — which is what makes this a trap rather
than a convention. `install.sh site mysite example.com 8080 -y` works;
`install.sh docker -y` silently does not.

**Fix.** Parse global flags in both positions: keep the pre-command loop, and
have each subcommand hand unrecognised `-y`/`-q`/`--yes`/`--quiet` back to the
same setter instead of ignoring them. An unknown flag on any subcommand should
be a stop with a message, never a silent discard.

## 3 — The post-start health probe cannot see a site nobody can load

**Severity: high.** This is the defect that let the `--no-ssl` breakage ship.

```bash
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost:$PORT/" 2>/dev/null || echo "000")
```

The request carries `Host: localhost`. The vhost's HTTPS redirect is gated on
`RewriteCond %{SERVER_NAME} =DOMAIN`, so a localhost request never triggers it.
On the box the installer printed **`Site is responding with HTTP 200`** while
every request using the real domain returned `301` to an HTTPS vhost that did
not exist. The installer verified that Apache was alive, and reported it as the
site being reachable.

**Fix.** Probe the site the way a user will: same request, plus
`-H "Host: $DOMAIN"`, and treat a 3xx to a scheme or host the install did not
configure as a failure rather than a pass. When `--no-ssl` is in force, a
redirect to `https://` is a hard failure — that is precisely the state that
must never be reported green.

Also on that line: `curl` prints `000` and then the `|| echo "000"` appends its
own, so a failed probe reports `HTTP response: 000000`. Drop the `|| echo`
(curl already emits `000`) or capture the two separately.

## 4 — `cache/static_pages` is root-owned, so page caching is off forever

**Severity: medium.** Silent, permanent, and it writes an error line per
request.

On the fresh install, `/var/www/html/soak/cache/static_pages` was
`drwxrwxr-x root user1`. php-fpm runs as `www-data`, which is neither owner nor
in that group, so `StaticPageCache` logged on **every** request:

```
PHP message: StaticPageCache: Cache directory not writable, caching disabled:
/var/www/html/soak/cache/static_pages/
```

Two separate reasons it survives the existing mitigations:

- `Dockerfile.template` chowns `/var/www/html/${SITENAME}/cache` at **build**
  time (its own header records this as the 3.8 fix for exactly this bug), but
  `cache/` is a **named volume** (`soak_cache -> /var/www/html/soak/cache`) and
  `static_pages` is created inside it at **run** time by the first process to
  touch it. A build-time chown cannot reach a directory that does not exist
  yet, in a filesystem the image does not own.
- `_site_init.sh:172,710` creates and chmods `$SITE_ROOT/public_html/cache` —
  a *different directory*. `StaticPageCache` uses
  `PathHelper::getSiteRoot() . '/cache/static_pages/'`, and `getSiteRoot()` is
  one level **above** `public_html`. The step that looks like it covers this
  has been pointed at the wrong path.

**Fix.** Create `$SITE_ROOT/cache/static_pages` explicitly at container start,
after volumes are mounted, owned `www-data:www-data`, and add `cache/` to
`fix_permissions.sh` so the sweep covers it too (it does not mention `cache`
at all today). Correct or drop the `public_html/cache` lines in `_site_init.sh`
so there is one cache directory, not two, and the one that exists is the one
the code uses.

**Verify by the log, not by the mkdir.** The fix is right when a fresh install
serves ten requests with an empty `logs/error.log` and an `index.json` in
`cache/static_pages`. That is the check that would have caught it originally.

## 5 — Two cron.d files run the same scheduled-task runner

**Severity: medium.** Every scheduled task in the platform runs on this.

```
/etc/cron.d/joinery-soak      * * * * *    process_scheduled_tasks.php   (_site_init.sh:747)
/etc/cron.d/scheduled-tasks   */5 * * * *  process_scheduled_tasks.php   (Dockerfile.template:236)
```

Both were present on the box and both fire. Observed collision:

```
[18:20:02] Scheduled tasks cron runner started
[18:20:02] Scheduled tasks cron runner started
[18:20:02] Running task: Retention Sweep (RetentionSweep)
[18:20:02] Running task: Retention Sweep (RetentionSweep)
[18:20:02]   skipped: already running
```

Nothing is corrupted, because the runner's own already-running guard catches
it — the two processes collide every five minutes and one loses. That guard is
a safety net being used as a design.

**Fix.** One writer — but *per environment*, not `_site_init.sh` everywhere.
`config/` is a named volume, so a container rebuild keeps
`Globalvars_site.php` and **skips `_site_init.sh` entirely**, while
`/etc/cron.d` dies with the container filesystem. Leaving `_site_init.sh` as
the only writer would leave every rebuilt container with no cron at all —
scheduled tasks, including inbound-mail pulls, silently stop. So: in Docker
the container start command owns the file (it runs every start, knows the
site name, and writes the same per-site every-minute entry); on bare metal
`_site_init.sh` owns it, and skips the write in Docker mode. The contract
test asserts the Dockerfile writes exactly one entry and that `_site_init.sh`
gates its write on bare metal; the live gate asserts exactly one cron.d file
after a fresh install **and again after a rebuild** — the case the original
fix wording would have broken.

## 6 — `iptables-persistent` is installed without `DEBIAN_FRONTEND`

**Severity: low**, but it is the same class as § 1: an unanswerable prompt in a
non-interactive run.

`install.sh:1536` runs `apt-get install -y iptables-persistent` with no
frontend set, so debconf falls back Dialog → Readline → Teletype and asks
`Save current IPv4 rules? [yes/no]` mid-install, alongside perl warnings out of
`Debconf/DbDriver/Stack.pm`. Both Dockerfiles set
`ENV DEBIAN_FRONTEND=noninteractive`; `install.sh` sets it on exactly one apt
call (2031, for postfix) and not this one.

**Fix.** Set `DEBIAN_FRONTEND=noninteractive` once for the whole script rather
than per-call, so a new apt line cannot reintroduce this.

## 7 — Two fixes exist only in the tree

Not new code — a publish, and a reason to treat that as part of the work.

- **`--no-ssl` produces a site nobody can load by its domain.** Published vhost
  template **2.01** redirects HTTP→HTTPS unconditionally while the `:443` block
  sits inside `<IfFile .../fullchain.pem>`, so with no cert every request
  carrying the real Host 301s into a vhost that does not exist. Tree template
  **2.02** wraps the redirect in the same `<IfFile>`. Observed on the box:
  `curl -H "Host: soak.getjoinery.com"` returned 301; after hand-applying the
  2.02 guard it returned 200.
- **The database password is baked into the image.** `POSTGRES_PASSWORD` as
  both build ARG and ENV: Docker's own builder warns twice during the build,
  `docker inspect` prints it, and it appears in 8 `docker history` layers.
  Fixed by `install.sh` 2.41 / `Dockerfile.template` 4.7 (run-time
  `--env-file`). Anyone who can read the image reads the password.

Both are high severity and both are invisible to every user until a release
carries them. **Publishing is the fix for these two**, and the same publish
should carry §§ 1–6.

## 8 — Automatic SSL never runs on a dual-stack host

**Severity: high. FIXED in `install.sh` 2.46 (2026-08-07).** Found reinstalling
the soak box as `drivetest.getjoinery.com` with SSL enabled, and confirmed a
second time on `upgradetest.getjoinery.com` — the 26.04 box, which sat without a
certificate for exactly this reason.

Both families are now probed explicitly (`curl -4` / `curl -6`, `dig A` /
`dig AAAA`) and either one reaching this host satisfies the check. **Every probe
ends in `|| true`**, which is not decoration: `sysadmin_tools/setup_ssl.sh`
sources this function under `set -euo pipefail`, so a domain with no AAAA record
makes the `grep` return non-zero and kills the run before certbot is reached —
silently, with exit 1 and no output. That was introduced while fixing this and
caught only because the re-run produced nothing at all.

Proof it works: `setup_ssl.sh upgradetest.getjoinery.com` reported
`Issued LE certificate ... (HTTP-01)` on a box whose `ifconfig.me` answers with
IPv6. Note the fleet never hit this because getjoinery and scrolldaddy have no
global IPv6 — it bites new Linodes, which come up dual-stack, and so it would
have hit every rebuild-from-backup node in the OS campaign.

`provision_origin_cert` decides whether to attempt the HTTP-01 challenge by
comparing this machine's address to the domain's:

```bash
server_ip=$(curl -s --max-time 5 ifconfig.me 2>/dev/null || curl -s --max-time 5 icanhazip.com 2>/dev/null)
dns_ip=$(dig +short "$domain" @1.1.1.1 2>/dev/null | grep -E '^[0-9.]+$' | head -1)
if [ -n "$server_ip" ] && [ -n "$dns_ip" ] && [ "$server_ip" = "$dns_ip" ]; then
```

`curl` prefers IPv6, so on a dual-stack host `ifconfig.me` answers with the
box's **IPv6** address — while `dns_ip` is filtered to IPv4 by that
`grep -E '^[0-9.]+$'`. The two can never be equal. Measured on the box:

```
server_ip: 2600:3c03::2000:d9ff:fe81:41a2
dns_ip:    45.33.72.32
```

So the HTTP-01 branch was skipped, DNS-01 detected Cloudflare but found no
credentials file, and the install finished with **no certificate at all**.
Issuing one by hand immediately afterwards took a single
`certbot certonly --apache` and succeeded on the first attempt: the challenge
was always going to work, the installer just never tried it.

Every Linode, DigitalOcean and Vultr box has IPv6 on by default and an A
record. This is the ordinary case, not an edge one — automatic SSL has
effectively never worked on a fresh VPS.

**Fix.** Pin the lookup to the family being compared: `curl -4 -s ifconfig.me`.
Better, compare *sets* rather than single values — every A record against every
local IPv4 — so a multi-homed box or a round-robin record still matches. If
AAAA is supported later, compare families pairwise; never across.

Two further faults sit on the same path:

- **The failure is reported as success.** With no cert issued,
  `setup_ssl_docker_proxy` still prints `[OK] Reverse proxy + SSL configured
  for <domain>`. The `:443` vhost is inside `<IfFile>`, so it silently does not
  load, and the operator is told SSL is configured for a site that answers only
  on `:80` — which then 301s them to an HTTPS port with nothing behind it. The
  success line must be conditional on a cert existing at the path the vhost
  names.
- **The self-signed fallback does not exist.** Four comments promise it:
  `install.sh:537` ("falls back to a self-signed certificate rather than
  failing"), `:718`, `:740`, and `:923` ("never fails the install; falls back to
  self-signed if neither HTTP-01 nor DNS-01 work"). `setup_ssl.sh`'s header goes
  further and names a `write_self_signed_cert` helper, promising the script
  "always leaves a working cert at /etc/letsencrypt/live/<domain>/". **No such
  function is defined anywhere in the tree**, and `provision_origin_cert` simply
  `return 0`s when both challenges fail. Either write it or delete the promise —
  a comment describing behaviour the script does not have is exactly how § 3
  shipped.

**Ordering trap for whoever builds this.** The proxy vhost's `:80` block
redirects everything to HTTPS, so a webroot challenge cannot be served before
the first cert exists. Use the apache authenticator (`certbot certonly
--apache`), which inserts its own temporary challenge config, and leave
`installer = None` in the renewal config so certbot never rewrites the
template-owned vhost. A deploy hook that reloads Apache is what lets `<IfFile>`
pick the renewed cert up. That combination is what now runs on the box, with
`certbot renew --dry-run` green.

---

# Testing

`tests/unit/installer_contract_test.php` (safe) already exists for exactly this
— text assertions over the scripts, pinning defects that shipped once. Add:

| Assertion | Guards |
|---|---|
| No bare `read` under `set -e` in `install.sh` — every prompt has `\|\| true` or a `-t 0` branch | § 1 |
| `-y` and `--yes` are reachable after every subcommand, not just before | § 2 |
| The availability probe sends a `Host:` header naming the configured domain | § 3 |
| Under `--no-ssl`, a 3xx to `https://` in the probe is a failure path, not a success | § 3 |
| Exactly one file under `/etc/cron.d` in the image references `process_scheduled_tasks.php` | § 5 |
| `install.sh` sets `DEBIAN_FRONTEND` globally, and no `apt-get install` line lacks it | § 6 |
| The vhost template's HTTPS redirect is inside an `<IfFile>` naming the cert | § 7 |
| `Dockerfile.template` names `POSTGRES_PASSWORD` in neither `ARG` nor `ENV` | § 7 |
| The origin-IP lookup is family-pinned (`curl -4`), so it is comparable to an A record | § 8 |
| The SSL success line is reachable only when a cert exists at the vhost's path | § 8 |
| No comment promises a self-signed fallback unless the helper is defined | § 8 |

The permission and cron defects (§§ 4, 5) can only be *proved* on a real
install, so they also belong in the live gate:

`tests/functional/install/install_container_gate.sh` (live, needs `[docker]`) —
a container install on a throwaway host, then assert: the site answers 200 for
a request carrying its configured domain; `logs/error.log` is empty after ten
requests; `cache/static_pages` is `www-data`-writable and contains a rendered
entry; exactly one cron.d entry runs the task runner; `docker inspect` and
`docker history` contain no database password. That gate is the one that fails
today on every item in this spec.

§ 8 needs the same gate run against a **resolving domain on a dual-stack host**
— the only shape that exposes it. Assert that the install issues a certificate
unattended, that `https://<domain>/` returns 200 with a chain that verifies,
and that no success line is printed on a run where no cert was issued.

# Docs to update

- `docs/quickstart.md` — the non-interactive install path, with flags before
  the subcommand, and what `--no-ssl` gives you.
- `docs/installation.md` — same, plus the health check's meaning.
- `maintenance_scripts/install_tools/INSTALL_README.md` — its Docker one-liner
  omits `-y`, and its multi-site examples put flags after the subcommand for
  `site` but never show the `docker` form; correct both to one rule, flags
  first.

Current-state voice throughout: describe the install path as it will be, with
no reference to how it used to behave.

# Decisions

- **D1 — Prompts get non-tty defaults rather than becoming errors.** An
  install that stops because nobody was there to say yes is worse than one
  that proceeds; the destructive prompts (`--wipe-data`, overwrite,
  downgrade) keep their guards and must default to *refuse*, not to yes.
- **D2 — The health probe is a user-facing check, not a liveness check.** It
  answers "can someone load this site", so it uses the domain the install
  configured. A green probe on a site that only answers to `localhost` is a
  false statement.
- **D3 — One writer per artifact.** Cron files, cache directories and
  permissions get exactly one owner between `install.sh`, `_site_init.sh` and
  `Dockerfile.template`. Every defect in §§ 4 and 5 is two scripts believing
  they own the same thing.
- **D4 — Build time cannot fix run time.** Anything under a named volume must
  be created and owned at container start. The § 4 mitigation was written at
  build time and has never had any effect.
- **D5 — Never compare addresses across families.** An IPv6 answer and an A
  record are not unequal, they are incomparable; the check in § 8 read the
  difference as "this domain does not point here" and silently withheld a
  certificate. Any address comparison pins its family first.
- **D6 — A green line requires the thing it claims.** §§ 3 and 8 both end with
  the installer reporting success for something that did not happen. Success
  messages assert state — a cert on disk, a page fetched by its real domain —
  never that a step was reached.

# Related

- `specs/implemented/installer_defects.md` — the 2026-07-30 pass over the same
  install path; these are the defects that pass did not reach.
- `specs/drive_sync_soak.md` — the soak host this run was provisioning; its
  standing container install is a continuous installer soak, and would have
  surfaced these itself.
