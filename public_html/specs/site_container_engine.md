# Site Container Engine

Status: IN PROGRESS 2026-10-14. Owner decision 2026-10-13 (multi_tenant_host_hardening Q6, option B): site containers move from Docker to Podman 6.x, built and shipped by us, before the fleet grows from 5 sites to about 500. WP1 is built (uncommitted) except its wiring into the publish: `build.sh`, `build_root.sh`, `install_site_engine.sh`, `SiteEnginePublisher` and their gates. Proven on boxes: on a 24.04 machine, as dev is, `build_root.sh create` made the 26.04 root in two minutes (1.7 GB) and `build` made the 16 MB engine in twelve, with the official Go; on a 26.04 host the installer refused a tampered archive, replaced Ubuntu's 5.7, kept both sites through a reboot (the one stopped by hand staying stopped), and `converge` replaced that engine with a rebuild of the same Podman under the newer Go while both sites ran. The owner made the build root on dev (2026-10-14). The publisher is wired into `publish_upgrade.php` beside the jail's, in both modes: a republishing site (getjoinery.com) fetches the archive its received `ENGINE` names from its upstream and checks it. `site_engine/` is held and `ENGINE` is publish's to commit (`ReleaseCommit` 1.7); docs/deploy_and_upgrade.md says so. Waiting on the owner: the commit (until then the held `site_engine/` refuses every publish), then one publish by hand with sudo (the publisher changed), which builds the first engine; WP1's box proof follows, a host converging onto the engine getjoinery serves.

## The problem in plain terms

Every site on a host runs in a container, and each container has its own root and www-data. Docker maps those to the same two host users for every container on the machine: every site's root is host uid 100000 and every site's www-data host uid 100033. Nothing changes while each site stays inside its container. After a breakout, though (a flaw in the container machinery that lets a process see the host's disk while keeping its ids), the process owns every other site's config, database, uploads and code on that host. Remapping (multi_tenant_host_hardening D11) keeps such a breakout off host root; it does not keep it out of the neighbours.

Docker cannot give each container its own ids, and has not since the request was opened in 2016. Podman can, with an explicit id block per container. Ubuntu's Podman (5.7.0, universe) has had no security update since 26.04 shipped, and upstream publishes no Linux engine packages, so we build the engine ourselves, as we already build the parser jail's launcher, and ship it with each release.

## What changes for people

- **A tenant:** nothing they can see. Their site restarts once when its host moves to the new engine.
- **The neighbours:** a breakout from one site reaches that site's files only.
- **An operator:** the host runs `podman`, not `docker`; the docs say so. Commands are otherwise the same words.
- **The owner:** an engine release whenever upstream ships a security fix, about every two weeks, built by the publish pipeline when the pinned versions change.

## What the trial established (test box pm1, 2026-10-13/14)

Two of our sites from release 0.8.489 on a 4 GB Ubuntu 26.04 Linode, first on Ubuntu's Podman 5.7.0, then on 6.1.4 built from source with `runc` as the runtime:

- Site 1's root and www-data are refused at every file of site 2's (config, database, uploads, code), and read their own.
- The image is shared on disk; each site's own layer is 18 MB. The 1 GB layer cap, memory, process and capability limits, the parser jail, the setuid strip, the health check and the site walls all hold. A swap takes 17 to 18 s (Docker 16 to 17). A reboot brings every site back with the same ids.
- 23 of the 28 inspect templates our scripts use run unchanged on 6.1.4.

What differs from Docker, each met on the box: the build cache ignores build arguments (a second site's build reused the first site's layers, on 6.1.4 too); a shell-form start command is stored for `/bin/sh` whatever `SHELL` says; port 80 needs `ip_unprivileged_port_start=0` passed, which Docker sets by itself; the boot service starts only `--restart=always` containers and is off by default; Podman's DNS service on the bridge is dropped by the walls; `--userns=auto` blocks move when containers come and go, and a recreated site could not open its own database; there is no `/.dockerenv`; five inspect templates and the capability readout differ; a build without AppArmor support refuses AppArmor-confined containers.

## Design

### D1. The engine is built at publish time, from pinned sources

`maintenance_scripts/install_tools/site_engine/versions.env` names each part by upstream tag and commit: Podman (with quadlet), netavark and aardvark-dns. `site_engine/build.sh` builds them inside Ubuntu 26.04: Podman with the official Go toolchain `versions.env` pins (`GO_TOOLCHAIN`, fetched by Go from proxy.golang.org and checked against sum.golang.org; Ubuntu's golang-1.26 is 1.26.0, without the standard library's later security fixes, and only fetches it), netavark and aardvark-dns with Ubuntu's Rust (1.93), the C libraries from Ubuntu's archive. Podman from its vendored modules with the tags `seccomp systemd apparmor exclude_graphdriver_btrfs`, netavark and aardvark-dns with `cargo build --release --locked`, every checkout checked against its pinned commit before anything compiles. It strips the binaries, packs them with a `BUILDINFO` (versions, commits, tags, toolchain) into `joinery-site-engine-<podman version>-<arch>.tar.xz`, and refuses its own output unless the binary itself (`go version -m`) carries the pinned commit, the pinned Go and every build tag `versions.env` asks for.

On dev it runs in a 26.04 build root, `/var/lib/joinery-site-engine-build/resolute`, which the owner makes once with `sudo bash site_engine/build_root.sh create` (debootstrap from Ubuntu's archive, checked against Ubuntu's archive key; dev runs 24.04). `build_root.sh build` brings the root's packages up to date, runs `build.sh` inside it, and leaves no mounts behind. `SiteEnginePublisher` runs it at publish time only when `versions.env` or `build.sh` changed, each build in a folder of its own; a build owed and not done refuses the release. Once wired, `site_engine/` joins `ReleaseCommit::JOINERY_HELD_DIRS`, as the jail's source did.

### D2. The engine ships beside the release, under its signature

The archive (16 MB) is published under `static_files/site_engine/`, and the publisher writes `site_engine/ENGINE` into the tree: the archive's name, its SHA-256 and the hash of the source it was built from. ENGINE ships like every file, covered by the signed manifest, so no new signing path is needed. A host runs `install_site_engine.sh converge`: it reads ENGINE from its own signed tree, and only when its current engine is not that one does it fetch the archive and install it, refusing any whose hash differs. Inside the core archive it would add 24 MB to every site's upgrade download for a file only hosts use.

### D3. What a host runs

- Our binaries under `/usr/local/lib/joinery-site-engine/<version>-<first 12 of the archive's SHA-256>/`: a rebuild of the same Podman (a newer Go, a changed build) is a different engine. The current one is named by a `current` link that `helper_binaries_dir` follows to netavark and aardvark-dns. Podman itself is copied to `/usr/local/bin/podman`, not linked: it records its own resolved path in every container it starts (the exit command conmon runs when the container stops), so the path stays the same across versions. The previous version's directory is kept for rollback. An apt preference keeps Ubuntu's `podman`, `netavark`, `aardvark-dns`, `crun` and `podman-docker` from ever being installed beside them.
- From Ubuntu's archive: `runc` (main, patched by Canonical) as the runtime, `conmon`, and `golang-github-containers-common` for the configuration files.
- `containers.conf.d`: runtime `runc`, netavark, the systemd cgroup manager. `storage.conf`: overlay on the data root (`/srv/joinery/containers/storage`, XFS with project quotas, where Docker's data root is today).
- `podman-restart.service` from the Podman source, enabled. A running container is held by its own `conmon`; replacing the engine's binaries restarts nothing, so the hardening spec's D6 (`live-restore`) has no counterpart here.

### D4. Each site owns a block of ids, recorded in its run spec

The run spec gains `idblock=N`, chosen under the same lock as the network slot; the site's ids are host `2147483648 + N × 65536` onward, 65536 of them, passed as `--uidmap 0:BASE:65536 --gidmap 0:BASE:65536`. Never `--userns=auto`. The block stays with the site for its life on that host; a site copied to another host has its files re-owned to the block it gets there.

### D5. The run spec on Podman

What `run_spec_args` adds or changes: `--sysctl net.ipv4.ip_unprivileged_port_start=0` and the id block. The restart policy stays `unless-stopped`: the 6.x boot unit starts `always` and `unless-stopped` containers (Ubuntu's 5.7 unit started only `always`), so a site held stopped stays stopped across a reboot, as on Docker. Networks are made with `podman network create --disable-dns --interface-name jsnetN` and the same subnets; a site then resolves through the host's own resolvers, and the walls stay as they are. `--storage-opt size=1G`, the six capabilities, the limits and the volumes are unchanged.

### D6. Images

Every base and site image is built with `podman build --format docker --no-cache` (the health check survives only the Docker format; the cache cannot be trusted across sites). The site image's start command is written in exec form, `CMD ["/bin/bash", "-c", "..."]`.

### D7. The scripts speak to one engine

- Every container command goes through one helper in `install_tools` that names the engine; no `podman-docker` shim, whose `/usr/bin/docker` would hide a stray Docker.
- Five inspect templates are rewritten (two network reads, the CPU count, the remap check, the data root). The run spec's adopt check reads the container's effective capabilities, not `CapAdd`, which Podman reports against its own defaults.
- One "am I in a container" helper accepts `/run/.containerenv` and `/.dockerenv`, used at all sixteen places that look for the second today.
- The `DOCKER-USER` rule (ports 9080 to 9099 from the public interface) moves into the walls' own nft table. The walls' assert and the data root's `Requires=` hang on `podman-restart.service` instead of `docker.service`.
- Agent: `victim.go` takes the config volume's path from `podman volume inspect`; `moved_site.go` uses `podman cp`; `addresses.go` leaves out `podman0` beside `jsnet*`; its three `/.dockerenv` checks accept `/run/.containerenv`.

### D8. What this changes in multi_tenant_host_hardening

D2's profile is generated from containers-common's `seccomp.json`, not moby's. D6 is replaced by D1 and D2 here. D9's recreate is built on Podman. D11 (remap on every new Docker host) holds until a host moves, then D4 here replaces it. WP3, WP5 and WP8 there wait for this spec's WP2, so none is built twice.

## Work packages

- **WP1 (D1, D2, D3):** `site_engine/versions.env`, `build.sh`, `build_root.sh`, `install_site_engine.sh` (`site_engine_gate`), `SiteEnginePublisher` (`site_engine_publisher_test`), its call in `publish_upgrade.php` beside the jail's and `site_engine/` held; box: a host converges onto the published engine, AppArmor and seccomp on, a tampered archive refused.
- **WP2 (D4, D5, D6):** the run spec on Podman (id block, run arguments, networks), the Dockerfiles; `site_run_spec_gate`; box: two sites through build, start, swap and reboot.
- **WP3 (D7, scripts):** the engine helper, the template rewrites, the container check, the firewall rule and unit hooks; every stub-engine gate and `installer_contract_test`.
- **WP4 (D7, agent).**
- **WP5:** moving today's Docker hosts (Q1), then Docker's install path removed.
- **WP6:** docs (installation.md, deploy_and_upgrade.md, server_manager overview, INSTALL_README).

## Open questions

- **Q1. Moving today's hosts.** In place (sites stop, volumes move into Podman's store with their ids shifted to each site's block, Docker removed) or by site copy onto new Podman hosts. Raised when WP5 is next.

## Testing

Each WP's gates as named above; the trial's checks (cross-site refusal, swap, reboot) become WP2's box proof; the platform's upgrade, backup and restore, site copy between hosts, the outbound limits and the volume disk allowance, untested in the trial, run in WP2's and WP5's box proofs.
