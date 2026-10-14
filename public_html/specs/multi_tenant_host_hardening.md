# Multi-Tenant Host Hardening

Status: IN PROGRESS 2026-10-13, from the security talk of the same day. D1's base (B1) is built and released. WP1 without its check and report lines, WP2, WP9 and WP7's two written rules are released in 0.8.492 (commit 8dc5a45b). Live on 0.8.492: dev and a fresh Docker host carry every kernel and network setting, all live, with no warning; under the strip the parser jail gate, the image decoding tests, a site's own backup, a level-3 restore rehearsal and mail through sendmail as www-data all pass. The backup first failed on a root-only file no strip touches (B9, a container site's own backup, fixed in backup_files.sh 1.6.1, released in 0.8.493; on 0.8.494 a site's own backup as www-data passes on two site container engine boxes). Still to run: the same settings checks on a joined fleet host, and WP9's site copy between an unremapped and a remapped host. Owner decisions are recorded where they land.

## The problem in plain terms

A multi-tenant host runs twelve strangers' sites on one kernel, one Docker daemon and one reverse proxy. Each tenant is their site's superadmin and can run PHP in their own container; that is the job, not a bug. The question this spec answers is what that PHP can reach beyond its own container, and what it would get if it did.

What already stands (specs `multi_tenant_docker_hosts`, `host_restrictions`): every site container drops every capability but six, Docker remaps user ids so container root is an unprivileged host uid, each site has its own bridge and nft walls drop container-to-container, container-to-host (except the proxy on 80 and 443), port 25 and the cloud metadata service, memory, CPU, process and disk limits are pinned, unsigned plugins and themes are refused, and a daily check reports a site whose code is not what we shipped.

What does not stand, found 2026-10-13 on the gVisor trial box and by reading the installers:

- **B1.** The kernel hardening block in `install.sh` (a modprobe deny list and a sysctl file) ran only in `do_server_setup()`, the bare-metal path. `do_docker_install()` never called it, so no Docker host had it. That is why a site's web user could make the host load `sctp`, `can` and `can_raw`. **Fixed the same day:** `host_housekeeping.sh` 1.16 section 11 writes the deny list and four kernel sysctls on every machine at every converge, and `install.sh` 3.15 stops the server path appending its own four modules (released 0.8.488, proven on test boxes). 1.17 moves the list into a file of its own, `joinery-rare-modules.conf`, and puts kmod's `blacklist-rare-network.conf` back as shipped (B6). D1 is what remains around it.
- A site can open netlink sockets of the nftables, XFRM and audit families, and can `ptrace` its own processes. Docker's default seccomp profile allows all of these.
- The site image carries setuid binaries, and nothing forbids a process gaining privileges on exec.
- The one host service a tenant can reach, the Apache proxy, runs with no sandbox at all.
- Docker's own apt origin is deliberately excluded from automatic updates (host_housekeeping.sh § 6), because an engine update restarts every site. So a runc or containerd fix waits for a person.
- Every container maps onto the same host uid range (`userns-remap: default` is daemon-wide), so site A's root and site B's root are the same host uid, and the kernel's per-uid counters are pooled across tenants.
- Nothing notices an attempt. A refused syscall returns an error and is logged nowhere; the host report reads the kernel log for OOM, disk-full and I/O errors only.

gVisor was tried and declined (`gvisor_site_sandbox_trial`): six times the idle CPU on Linode's shared instances. This spec is the runc answer that report recommended. Key custody, the warrant posture and the encrypted data root are separate and not changed here.

## What changes for people

- **A tenant** sees nothing. No site needs SCTP, netfilter netlink or a setuid binary.
- **The operator** sees three new things on the node page: a count of refused kernel calls per host over the last day, an incident when the kernel log carries a warning or oops while sites ran, and Docker engine updates applied on their own like Ubuntu's.
- **Single-site Docker hosts and bare metal** get D1 (the hardening block, which was always meant for them too) and D6 (engine updates). A Docker host built after D11 remaps user ids whatever it serves, so it also gets what keys on remapping (D2). D3 runs in every site container. The rest is multi-tenant only.

## Inventory: what a tenant's process can reach today

| Surface | Today | After |
|---|---|---|
| Kernel modules loaded on demand by `socket()` | refused since host_housekeeping.sh 1.16 (B1, released 0.8.488, proven) | the same, checked and reported (D1) |
| Socket families | the thirty Docker's default profile admits (it refuses AF_ALG, AF_VSOCK and about a dozen more) | UNIX, INET, INET6, NETLINK route only (D2) |
| Netlink nftables, XFRM, audit, generic | reachable | refused (D2) |
| `ptrace`, `process_vm_readv/writev` | allowed within the container | refused (D2) |
| Kernel-surface sysctls (`kptr_restrict`, BPF JIT hardening, `kexec`, protected links) | Ubuntu 24.04 defaults, unpinned | pinned and raised where safe (D1) |
| Setuid binaries in the image | present | stripped at every start, except the parser jail's launcher (D3) |
| `/tmp` | container layer (capped at 1 GB with it on a site with a disk allowance), executable | unchanged (D3, Q3) |
| Host services | Apache proxy on 80 and 443 | the same, sandboxed (D5) |
| Neighbour containers, backend ports, Postgres ports | walled; published on the host loopback only | unchanged |
| Cloud metadata, port 25, host ssh | walled | unchanged |
| Host uid behind container root and www-data | shared by every site | unchanged; pooled limits raised (D4); per-container ranges recorded as the ceiling (Q6) |
| runc, containerd, dockerd fixes | installed by a person | installed nightly (D6) |
| Kernel fixes | nightly, reboot when needed | unchanged; Livepatch deferred (Q1) |
| A refused call | silent | counted per host, incident on a burst (D2, D7) |
| Kernel warnings and oopses | unread | incident (D7) |
| Code not what we shipped | daily report, incident | unchanged cadence unless cheap to raise (D8) |
| An implant in the container's OS layer | lives until the next rebase | gone at the next fix's replacement, or on the recreate word (D9) |

## Design

### D1. One hardening function for every host (B1: built and released; the rest here)

**What stands (released 0.8.488):** `host_housekeeping.sh` section 11 runs on every machine, never in a container, at every converge (`_plugin_installers_start.sh` HOST_INSTALLERS), so bare metal keeps what it had, every Docker host gains it, and an existing host converges within the tick. It writes `/etc/modprobe.d/joinery-rare-modules.conf` whole from one array (`RARE_MODULES`: socket families, rare IPv4/IPv6 protocols, netlink families, two line disciplines; `install NAME /bin/false` plus `blacklist NAME`), unloads any listed module nothing holds, and writes `/etc/sysctl.d/90-joinery-kernel.conf` with `dev.tty.ldisc_autoload=0`, `kernel.unprivileged_bpf_disabled=1`, `kernel.dmesg_restrict=1`, `vm.unprivileged_userfaultfd=0`. kmod's own `blacklist-rare-network.conf` is left as kmod ships it (1.17, B6: apt holds back an update to a package whose config file was changed). `install.sh` 3.15 no longer appends its own four modules on the server path. `host_housekeeping_gate` covers it. Proven on test boxes (gvisor_site_sandbox_trial B2): the trial's probe as a site's www-data on a converged Docker host, and nineteen refused socket kinds as `nobody` on a converged server, load nothing on the host.

**What D1 adds to it:**

- The kernel-surface sysctls section 11 does not carry, in the same `KERNEL_SETTINGS` array so there is one list: `kernel.kptr_restrict=2`, `net.core.bpf_jit_harden=2`, `kernel.perf_event_paranoid=4`, `kernel.yama.ptrace_scope=1`, `kernel.kexec_load_disabled=1`, `kernel.apparmor_restrict_unprivileged_userns=1`, `fs.protected_symlinks=1`, `fs.protected_hardlinks=1`, `fs.protected_regular=2`, `fs.protected_fifos=2`, and D4's `fs.inotify.max_user_instances=2048`. Ubuntu 24.04 ships most of these already; pinning them means a distro change or a hand edit cannot loosen a shared kernel quietly. `kernel.modules_disabled=1` is not used: Docker loads bridge and netfilter modules at network creation, and a one-way sysctl that refuses them breaks a site rebuild.
- The network sysctl file `do_server_setup()` still writes only on bare metal (`/etc/sysctl.d/99-security.conf`: rp_filter, redirects, source routing, syncookies) moves into section 11 as a second array, so a Docker host gets it too and `install.sh` writes it nowhere itself.
- `multi_tenant_host.sh check` gains a line per file (present, identical to what section 11 would write) and a line per setting (live value equals the file). `host_report.sh` carries `hardening: ok|drift|unknown` so the node page can say so.

### D2. A seccomp profile for site containers, with canaries

Docker's default profile is the base. The bundle carries `joinery-site.seccomp.json`; host_housekeeping.sh section 11 writes it to `/etc/docker/seccomp/joinery-site.json`, and `_site_run_spec.sh` adds `--security-opt seccomp=/etc/docker/seccomp/joinery-site.json` for every site on a host that remaps user ids. A host that remaps but has no profile refuses to run a site, the way it already refuses without the restrictions directory (`run_spec_restrictions_present`).

Changes from the default:

- `socket`: allowed only for domain `AF_UNIX`, `AF_INET`, `AF_INET6`, and `AF_NETLINK` with protocol `NETLINK_ROUTE`. Every other domain or netlink protocol is refused. This is what a static probe found reachable and no site uses. Docker's default already filters `socket` by domain (it admits thirty); this narrows that list rather than adding a rule. A refused domain answers `EAFNOSUPPORT` and a refused netlink protocol `EPROTONOSUPPORT`, never the default's `EPERM`: PAM's audit hook (cron, runuser) reads "not supported" as "this kernel has no audit" and carries on, and reads "permission denied" as a failure. WP3's box proof runs cron and runuser under the profile.
- Refused outright: `ptrace`, `process_vm_readv`, `process_vm_writev`, `kcmp`, `personality` except persona 0, `userfaultfd`, `io_uring_*`, `bpf`, `perf_event_open`, `keyctl`, `add_key`, `request_key`, `unshare`, `setns`, `mount`, `umount2`, `pivot_root`, `open_by_handle_at`, `init_module`, `finit_module`, `kexec_load`, `kexec_file_load`. Most are already refused by the default; naming them here means the list is ours to read.
**Following Docker's own profile.** Ours is Docker's default plus the changes above, so it is generated, never hand-copied. On the site container engine the same holds: the repository already carries Docker's `default.json`, moby/profiles `seccomp/v0.2.4`, as `install_tools/site_engine/site_seccomp.json` with its tag, commit and hash in `site_seccomp.source`, and every site there runs under it (site_container_engine D5), because Podman's built-in profile lets a process with no `CAP_SYS_ADMIN` make user namespaces; this spec's profile is generated from that file. The repository carries Docker's `default.json` with the moby/profiles tag it came from; publish generates `joinery-site.seccomp.json` from it; a gate fails when the generated profile differs from Docker's anywhere but the changes listed here; and the host report carries the Docker version the profile was built from beside the engine's own, so the node page shows the gap. D6 updates engines nightly and a release follows Docker at its own pace, so a host can run a newer engine than its profile for a while. That costs little: a site's software changes only with a release, so a newer engine brings nothing that needs a newer profile, and the realistic loss is a tightening of Docker's reaching sites one release late. Not chosen: pinning the engine version (it gives back D6), and a second filter stacked inside the container over Docker's untouched default (installing it needs `no_new_privs`, which D3 rules out for the parser jail).

- **Canaries: our refusals only (owner, 2026-10-13).** The filter's log flag is not set. It logs every refusal the filter makes, and Docker's default refuses some calls on purpose in normal running: `clone3` answers `ENOSYS` to any process without `CAP_SYS_ADMIN`, and current glibc tries it first at every thread start and spawn, then falls back. With the flag, every site would log thousands of refusals a day, and the kernel's own rate limiting under that flood would drop the records worth counting. Instead, audit rules record only the refusals this profile adds: a `socket` call answered `EAFNOSUPPORT` or `EPROTONOSUPPORT`, and the refused `ptrace` and `process_vm_*` calls, from the remapped uid range (an audit record in the system journal, keyed `joinery_refused`). A quiet site counts zero. The rules apply to every matching call on the host, so WP3's first step measures their cost on a busy box; if it is too high, the count is dropped (D7's kernel-warning incident and D1's module check remain the breakout signals) and the spec says so.

Attribution: the audit record names the pid, uid, comm and exe, not the container. The host report counts `joinery_refused` records per host over the last day (`kernel_events.refused`, beside `oom`), and the plane opens a warning incident on the host above a threshold (first figure: 20 a day; a site's normal operation produces zero, measured in WP3). Naming the site is a follow-up for the agent, which is the one process on the host that could resolve a live pid to its container; it is not built until a count fires.

### D3. No setuid binaries

Setuid, setgid and file-capability bits are stripped at every container start by `_site_supervisor.sh` 1.2 (`find / -xdev -type f -perm /6000`, then `chmod u-s,g-s`, and `setcap -r` on what `getcap -r /usr /opt` lists), in every site container: the image is the same everywhere, and on a host that does not remap container root is host root, where the strip matters most. Three keep their bits, by path: the parser jail's launcher, `/usr/local/sbin/joinery-jail`, and Postfix's `postdrop` and `postqueue`, through which www-data's `sendmail` hands mail to the local queue (a site with no outbound provider sends that way). Doing it at start rather than in `Dockerfile.template` means every existing container gets it at its next restart with no rebase. What is stripped is Ubuntu's (`su`, `passwd`, `mount` and the like), each a known route from www-data to container root; Apache, PHP-FPM, cron and `pg_ctlcluster` drop privileges from root themselves and need none of them.

**The parser jail keeps its bit (owner, 2026-10-13).** The jail (parser_jail spec) is how a stranger's attachment is opened by a throwaway user instead of www-data, and only root can switch users, so its launcher is setuid root by design: written for the job, reading no file and no input beyond its own argv. Stripping its bit makes the platform treat the jail as missing and parse as www-data again; for that reason the strip skips it by path, and the parser jail gate runs in the box proof under the strip.

**`no-new-privileges` is not set.** It makes the kernel ignore every setuid bit, the launcher's included: the jail would then refuse to run and every attachment preview, AI attachment read and arriving report would fail. With every other setuid bit stripped at every start, the flag would guard only the one binary we kept on purpose. Revisit only if the jail stops needing setuid; rebuilding it as a root helper the web user calls was rejected in the parser_jail spec (a second implementation and a wire protocol for a job the subprocess contract already does).

**`/tmp` stays where it is (Q3, owner 2026-10-13).** It is in the container layer, which a site with a disk allowance has capped at 1 GB, and it is where PHP spools every upload in progress. A tmpfs there would charge those uploads to the site's memory limit (384 MB on a shared host), and `noexec` on `/tmp` alone stops nothing: starting a program from `/tmp` already takes code running as the site, which can write to its own volumes (`uploads`, `storage`), and those stay executable because Docker's `-v` cannot set mount flags on a named volume after creation. Nor does `noexec` stop PHP including a file, the usual `/tmp` trick against PHP. The one extra a tmpfs gives, an empty `/tmp` at every start, D9 gives for the whole OS layer. If the volumes are ever created `noexec`, `/tmp` follows them.

### D4. Pooled per-uid limits

Because every container's www-data is host uid 100033, the kernel's per-uid counters are one pool for the box. The two that matter:

- `fs.inotify.max_user_instances` (128 by default) is charged up to the host uid, so twelve sites share 128. D1 sets 2048. `max_user_watches` stays: it is a memory question and the default is generous.
- The per-uid process ceiling is bounded already: a container's `--pids-limit` caps it, and `multi_tenant_host.sh check` gains the arithmetic, the sum of every site's `pids_limit` must be below the process rlimit the daemon hands containers.

WP6 measures both by filling one site and watching a neighbour before and after.

Per-container uid ranges would end the pooling and the shared ownership of every site's files behind the mount namespace. Docker cannot do it; Podman's `--userns=auto` can. Recorded as the ceiling of the current design (Q6), not planned.

### D5. The proxy runs in a sandbox

On a Docker host the host's Apache serves no PHP; it terminates TLS and proxies to containers. A drop-in `/etc/systemd/system/apache2.service.d/joinery-sandbox.conf`, written by `proxy_default_site.sh install` and converged by host housekeeping:

```
[Service]
NoNewPrivileges=yes
ProtectSystem=strict
ReadWritePaths=/var/log/apache2 /run/apache2 /var/lib/apache2
ProtectHome=yes
PrivateTmp=yes
PrivateDevices=yes
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectKernelLogs=yes
ProtectControlGroups=yes
RestrictNamespaces=yes
RestrictRealtime=yes
LockPersonality=yes
RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX AF_NETLINK
SystemCallArchitectures=native
SystemCallFilter=@system-service
SystemCallErrorNumber=EPERM
CapabilityBoundingSet=CAP_NET_BIND_SERVICE CAP_SETUID CAP_SETGID CAP_CHOWN CAP_KILL CAP_DAC_READ_SEARCH
```

Apache's parent stays root to bind 80 and 443 and to read certificates, starts its children and switches them to www-data, gives them their lock files, and signals them on a reload; the bounding set is what that needs. `@privileged` is not refused: it holds `setuid`, `setgroups` and `chown`, the calls that switch the children. The set above is the first figure; the gate, running a real Apache under the drop-in through a start, a request and a reload, decides the final one. Certbot's renewal reloads Apache through systemd, which the drop-in does not touch. Bare-metal servers run PHP inside Apache and are out of scope for this drop-in; only a host with `proxy_default_site.sh` installed gets it. The `proxy_default_site` gate starts a real Apache under the drop-in on spare ports.

### D6. Docker engine updates install themselves

`_docker_daemon_json.sh` sets `live-restore: true` on every Docker host, so a dockerd restart leaves containers running; containerd restarts already do, through its shims, and runc is read only at container start. With that, host_housekeeping.sh § 6 adds the Docker origin (`Docker:${distro_codename}`) to the platform origins it already writes for PHP and PostgreSQL, and the reason it gave for leaving Docker out is gone. A multi-tenant host installs the fix at 05:30 UTC with the rest. WP5 proves on a box that an engine upgrade under live-restore drops no site request, and that the walls hold through it (the `docker.service` drop-in already asserts them before start).

### D7. The kernel log is read for breakout smoke

`host_report.sh` already counts OOM, disk-full and I/O errors in the last day's journal. Two patterns join them: `refused` (D2's `joinery_refused` audit records) and `kernel_warning` (`WARNING:|BUG:|Oops:|general protection fault|kernel NULL pointer`; not `Call Trace:`, which every OOM kill report and hung-task notice prints). The plane's existing kernel-events fold raises a warning incident for a nonzero `kernel_warning` on a multi-tenant host and for `refused` above the D2 threshold. A kernel warning while twelve strangers run code is worth a look every time; on a single-site host it is noted, not alarmed.

### D8. The code check's cadence

`site_housekeeping.sh` writes `config/code_check` and the host report carries it; the plane already raises `IncidentSourceSiteCodeUnverified`. The only question is how often the check runs. WP7 measures `PackageSignature::verifyTree()` on a 256 MB site; if it is cheap it runs at every converge tick, else hourly. Nothing else changes.

### D9. Containers are replaced when Ubuntu ships a fix (B7, Q5; owner 2026-10-13)

A site's code, vendor tree, scripts, database, uploads, storage, config and backups are all named volumes (`RUN_SPEC_VOLUMES`); the container layer holds only the OS. So a container is patched by replacing it: a fresh image, then the container recreated from its run spec onto it, what `rebase_site_container.sh` does minus the database move. That also resets the layer, so nothing written into the OS outlives the next fix. Every Docker host does this, not only shared ones.

- **Nightly check.** At a quiet hour the host converger asks a throwaway container of the current base image whether Ubuntu has a fix for anything in it (`apt-get update`, then a simulated upgrade). With nothing pending, nothing happens.
- **Fresh build, sites serving.** On a pending fix it builds `joinery-base` with `--pull --no-cache` (without them Docker returns the cached layers, B7), then each site's image. Measured on a 2-CPU, 4 GB Linode: base 123 s, site image 17 s; the site answered every request through both builds (slowest 0.11 s), and the new container had nothing left to upgrade.
- **Build first, then swap one site at a time.** `install.sh site` on an existing site stops and removes the container before it builds the image, so a rebuild by hand is down for the whole build; the swap here is only stop, remove, run. Measured on a fresh site capped at 384 MB: 16 to 17 s without an answer, the same as `docker restart`; a site with more data and plugins is expected to take longer and is measured in WP8's box proof.
- **Two fixed waits go.** About 8 s of those 16 are waits: PostgreSQL's start wrapper (`pg_ctlcluster`) probes readiness with `psql` as root, is refused, and gives up only after ten refusals half a second apart (a cluster ready in 0.3 s takes 6.2 s to start), and the start command then sleeps 3 s. The start command starts PostgreSQL so that the probe succeeds or is not needed, and drops the sleep. Every container start gains from it, a host reboot's included.
- **Rollback.** The previous image is kept until the next cycle. A site whose new container is not healthy within its health check's start period is recreated on the previous image, the cycle stops for the rest of the host's sites, and an incident is raised.
- **The same on the operator's word.** `recreate_site` on the host (agent job and `install.sh site --recreate`) replaces one site's container now, with the same build-first order.

### D10. Two rules, written down

In `docs/installation.md`, multi-tenant section: a site the operator owns, or one that serves a management node, never runs on a host that serves strangers; and twelve sites per 4 GB host is the blast radius chosen on 2026-10-04, not a tuning knob.

### D11. Every new Docker host remaps user ids (Q2, owner 2026-10-13)

`install.sh docker` sets `userns-remap` before Docker's first start on every new host, not only with `--multi-tenant`, so root in any container is an unprivileged host uid and a breakout from a single-site host (a management node's included) lands without root. `--multi-tenant` keeps adding what only a shared host needs: the walls, the reboot policy and the host restrictions. An existing host is not changed: remapping strands its containers, volumes and images, and `docker_multi_tenant_existing`'s refusals stand.

What follows from it: every new Docker host can cap a site's disk (the allowance needs remapping); D2, which keys on a remapping host, applies there too, and nothing in a site needs what it removes; and an operator reading a volume from the host sees owners 100000 and up. Nothing on a site container needs container root to be host root: none uses the host's network, process namespace or privileged mode. `migrate_site_to_code_volumes.sh` refuses on a remapping host and does not matter there, since a new host's sites are born on code volumes.

## Work packages

Each is self-contained and lands on its own.

- **WP1 (D1):** the added sysctls and the network file folded into section 11, the `99-security.conf` block deleted from `do_server_setup()`; `multi_tenant_host.sh check` lines; `host_report.sh` `hardening`; `host_housekeeping_gate` and `multi_tenant_host_gate` with mutations (a missing line, a drifted sysctl). 
- **WP2 (D3):** supervisor strip at start (the jail's launcher kept); `site_supervisor_gate`; box: upgrade, backup, photo upload, restore, and the parser jail gate, under the strip.
- **WP3 (D2, D7):** first, the audit rules' cost measured on a busy box (the count stays or goes on that figure); the profile in the bundle, written by section 11, required by the run spec; the audit rules, written by section 11 on a remapping host; `host_report.sh` two counts; plane fold and two incident sources; `host_report_gate`, `incident_sources_test`; box: the probe's refused calls appear as counts, a quiet site counts zero over a day.
- **WP4 (D5):** the drop-in, `proxy_default_site.sh` writes and converges it, `proxy_default_site_gate` runs Apache under it; box: twelve sites serve, certbot renew and reload work.
- **WP5 (D6):** `live-restore`, the Docker origin in § 6, `docker_multi_tenant_gate`; box: engine upgrade with a request probe running.
- **WP6 (D4):** the measurement, the sysctl in D1's file, the pids arithmetic in `check`.
- **WP7 (D8, D10):** the cadence measurement and change; the two rules in the doc.
- **WP8 (D9, B7):** the `recreate_site` word, host and plane halves, building before it stops anything; the nightly check, the fresh build (`--pull --no-cache`) and the one-at-a-time swap with rollback in the host converger; the start command's two waits removed; box: a pending fix is found, built and swapped with a request probe running on a site with real data, a swap whose container never turns healthy rolls back and raises the incident, and a night with no fix does nothing.

- **WP9 (D11):** `install.sh docker` remaps on every new host; `docker_multi_tenant_gate` (or its single-site sibling) asserts it; docs/installation.md's isolation paragraph; box: a single-site host built new runs a site through an upgrade, backup, restore and the parser jail gate, and a site moved by the site copy path from an unremapped host to a remapped one, and back, keeps working.

Each WP that changes the bundle (`install_tools`) ships in a release; existing hosts converge through housekeeping with no action on them.

## Out of scope, and why

- **Livepatch (deferred, Q1).** Kept as the next step for kernel fixes once multi-tenant hosts carry paying customers.

- **PHP-FPM shell-out functions.** Disabling `exec` and friends in the FPM pool while the CLI keeps them would take a shell away from a web RCE. The upload path shells out to `djpeg` from a web request (`ImageWorkLock`, `UploadHandler`), so the decode has to move to a worker first, and the change touches every install, not only multi-tenant hosts. It is its own spec once the shell-out inventory is done.
- **Stronger isolation (gVisor on KVM, Firecracker, Kata).** Only on hardware with KVM; not on Linode's shared instances. Revisit with the provider question.
- **A read-only root filesystem.** The container layer is only the OS now, so this is closer than it was; but `apt` and the supervisor still write there. Not worth it beside D3 and D9.
- **A custom AppArmor profile.** Marginal over `docker-default` once seccomp and the mount flags are in place.

## Open questions

- **B7. Site containers never install Ubuntu's security fixes (found 2026-10-13). Decided 2026-10-13: replaced on a fix by D9 (WP8).** The official `ubuntu` image ships `/etc/apt/apt.conf.d/docker-disable-periodic-update` (`APT::Periodic::Enable "0"`), so the container's daily `apt-compat` run exits at once although unattended-upgrades, `20auto-upgrades` and cron are all in place; host_housekeeping § 6 leaves containers alone on the assumption that their image is rebuilt, and nothing rebuilds it on a schedule. Nor would a rebuild by hand help: `do_build_base` runs `docker build` without `--pull` or `--no-cache`, so with `install.sh` unchanged Docker hands back the cached layers, as stale as before. A container's PHP, PostgreSQL, Apache, libxml2, libzip and openssl (all from Ubuntu's archive) stay at the image's build. A fix to a running program takes effect only when it restarts, whichever option is chosen; the base image removes the `policy-rc.d` that blocks package restarts, so an in-place patch restarts the program itself. Planning an upgrade with apt's cache took about 110 MB on dev. Options: let each container patch itself daily (drop the file in `Dockerfile.base`; catch: unattended-upgrade's memory peak inside a 384 MB site, every site at once); rebuild the base image and recreate every container on a schedule (Q5's monthly option, needing D9 first; catch: a restart per site per cycle); or the host converger patches each container in turn at a quiet hour (catch: the same memory peak, one site at a time).

- **Q1. Livepatch. Decided 2026-10-13: the nightly reboot stays; Livepatch is deferred.** A multi-tenant host installs a kernel fix at 05:30 UTC and reboots at once, so a flaw's window is under a day. Livepatch (Ubuntu Pro) would close it to hours with no reboot, at the cost of attaching every host to a Canonical account and a per-machine subscription (the free tier is for personal use on five machines; whether a hosting business qualifies is unclear). Revisit once multi-tenant hosts carry paying customers.
- **Q2. userns-remap on new single-site Docker hosts. Decided 2026-10-13: yes, every new Docker host remaps (D11).**
- **Q3. The `/tmp` figure. Decided 2026-10-13: no tmpfs; `/tmp` stays in the capped container layer (D3).**
- **Q4. The refused-call threshold. Not an owner question:** WP3 measures a quiet site over a day and sets the threshold from it with a margin (first figure 20 a day); the spec records the measured figure.
- **Q5. Recreate cadence. Decided 2026-10-13: a nightly check, and a replacement whenever Ubuntu has shipped a fix for something in the image (D9).** The operator's word alone would have left B7 unfixed; a fixed monthly cycle would hold a fix up to a month and restart every site with nothing to install.
- **Q6. Per-container uid ranges. Decided 2026-10-13: B, site containers move to Podman 6.x built by us, before the fleet grows (spec `site_container_engine`).** The trial behind it: The fleet grows from 5 sites to about 500 in two months, so the engine question is settled before that, not with the provider. Docker 29 still maps ids for the whole daemon; Sysbox CE gives every container one common mapping; nerdctl can do it but is young. Rootful Podman 5.7 (Ubuntu 26.04) with an explicit `--uidmap`/`--gidmap` block per site, recorded in the run spec like the network slot, was run on a box with two sites: one site's root and www-data are refused at every file of the other's, the image is shared on disk (each site's own layer 18 MB), the 1 GB layer cap, limits, parser jail, setuid strip, health check and the site walls hold, a swap takes 17 to 18 s, and a reboot brings both back with the same ids. What a move would change: site images always built without cache (Podman's cache ignores build arguments), the start command in exec form, `ip_unprivileged_port_start=0` passed explicitly, `--restart=always` with `podman-restart.service` enabled, networks made `--disable-dns`, ids allocated by us (`--userns=auto` broke a recreated site), `/run/.containerenv` accepted wherever `/.dockerenv` is checked (14 places), 7 of 28 inspect templates and the capability check rewritten, and the host pieces (install, disk pool, the `DOCKER-USER` rule, unit drop-ins, two agent files). Ubuntu's Podman (5.7.0, universe) has had no security update since 26.04 shipped while upstream fixed six CVEs from August to October, and upstream publishes no Linux engine packages, so a move means building Podman ourselves: 6.1.4 with netavark and aardvark-dns 2.1.0 built from source with Ubuntu's Go 1.26 and Rust 1.93 in about ten minutes, AppArmor support switched on, with `runc` from Ubuntu main (patched by Canonical) as the runtime. On that build every check above passed again, a reboot brought both sites back, and a network made `--disable-dns` leaves the walls as they are. The cost Docker does not have: a rebuild and release for each upstream security fix, about every two weeks.

## Testing

Gates to extend, all mutation-checked as the existing ones are: `host_housekeeping_gate`, `multi_tenant_host_gate`, `site_run_spec_gate`, `site_supervisor_gate`, `host_report_gate`, `proxy_default_site_gate`, `docker_multi_tenant_gate`, `incident_sources_test`. Box proof on a scratch multi-tenant Linode in the test account, two sites, with the static probe rewritten from the gVisor trial's: every refused family, netlink protocol and syscall; `lsmod` unchanged on the host; setuid count one (the jail's launcher); counts and incidents on the plane; a full upgrade, backup, restore, photo upload and site copy under every flag; an engine upgrade with a request probe running; `multi_tenant_host.sh check` exits 0 after every WP.
