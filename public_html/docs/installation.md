# Installation

> **New to Joinery?** The [Quick Start guide](https://getjoinery.com/page/quickstart) walks you through renting a server, configuring your domain, and installing Joinery step by step — no prior experience required.

Deploy Joinery on a fresh Ubuntu 24.04 or 26.04 LTS server, either in a Docker container or directly on the host (bare-metal). The same `install.sh` script handles both — the deployment mode is auto-detected from whether a port is supplied. Docker mode is how you put several sites on one machine; it is not a sandbox — see [What Docker mode is](#what-docker-mode-is).

## Table of Contents

1. [Quick Start](#quick-start)
2. [Prerequisites](#prerequisites)
3. [Password Security](#password-security)
4. [The Data Root](#the-data-root)
5. [Docker Deployment](#docker-deployment)
6. [Bare-Metal Deployment](#bare-metal-deployment)
7. [Outbound Limits](#outbound-limits)
8. [SSL Certificates](#ssl-certificates)
9. [Cloudflare Proxy Support](#cloudflare-proxy-support)
10. [Themes and Plugins](#themes-and-plugins)
11. [Domain Management](#domain-management)
12. [Site Management](#site-management)
13. [Maintenance Operations](#maintenance-operations)
14. [Troubleshooting](#troubleshooting)
15. [Script Reference](#script-reference)

## Quick Start

### One-liner install (latest version)

Docker:

```bash
mkdir -p /tmp/joinery && \
  curl -sL https://getjoinery.com/utils/latest_release | tar xz -C /tmp/joinery && \
  cd /tmp/joinery/maintenance_scripts/install_tools && \
  sudo ./install.sh docker && \
  sudo ./install.sh site mysite example.com 8080
```

Bare-metal:

```bash
mkdir -p /tmp/joinery && \
  curl -sL https://getjoinery.com/utils/latest_release | tar xz -C /tmp/joinery && \
  cd /tmp/joinery/maintenance_scripts/install_tools && \
  sudo ./install.sh server && \
  sudo ./install.sh site mysite example.com
```

### One-click deployment (Linode StackScript)

A StackScript installs Joinery while the instance first boots, so the deployer fills in a form and never opens a terminal. Select it when creating a Linode, answer the fields, and a few minutes later the site is running with SSL and a login.

The deploy form asks for as little as it can — every field is a chance for someone to abandon the form, and once this is a Marketplace listing each one is expensive to change:

| Field | Required | What it does |
|---|---|---|
| Admin email address | Yes | The admin account's address. Password reset needs a mailbox someone can receive at. |
| Admin password | Yes | The password for that account. Masked in the UI and kept out of the deployment log, which is what the `password` in its field name buys. It is the password the owner keeps; no change is forced at first sign-in. |
| Your domain | Yes | The domain the site is installed as. A site with no domain can get no certificate and every link it emits names an IP address, so the form does not offer that state. `install.sh` run by hand still detects the IP when no domain is given. |
| Linode API token | No | Only useful when the domain's nameservers point at Linode. Needs the Domains Read/Write scope. Creates the zone when the account holds none, then the A record, from the instance, so the first certificate attempt succeeds rather than the retry timer's. Never printed. Once it has proved usable it is sealed into the site for the one DNS publish of the mail records — made during the install when a sending key is supplied, otherwise by the setup wizard's email step — and deleted on use. |
| Email sending API key | No | Sets email up during the install, so the setup wizard's Email step opens on the delivery proof (or a DNS wait) instead of an empty form. The provider is told from the key: every provider one key configures declares the shape of its keys (`SingleKeyProvider`: SMTP2GO, Mailgun, SendGrid, Resend, Brevo, Postmark), and a key no shape matches is tried live against each. `utils/install_mail_provider.php` then runs the wizard's own ceremony: the From address is derived from the admin address on the site's domain, the owner's mailbox is provisioned for it, the domain is registered at the provider where its API allows, its mail records are published through the kept Linode token, and the provider is asked to verify. A key every provider rejects leaves nothing configured and the wizard asks again; the closing summary says which happened. Never printed. |
| Backblaze B2 bucket, key ID, key | No | Three fields that together point backups at a bucket during the install. `utils/install_backup_target.php` creates the target, fills the region and endpoint from Backblaze's own answer, tests the connection, and makes it the scheduled target — the same as the wizard's "Save and test". A bucket that cannot be reached is removed again and the wizard asks for one. The recovery key that turns nightly backups on is a secret shown once to a human and stays the wizard's. The key is never printed. |

The form declares no SSH key field, and the install places no key and creates no account: root keeps the password the deployer set in Linode. Region, plan and firewall are the deployer's own Create-form choices; a StackScript cannot preset or hide them, and every field it declares is shown.

Neither optional service is a condition of the install. The StackScript only passes these fields on: `_site_init.sh` does the work, so a hand-run `install.sh site` takes the same inputs (see "Services set up at install" below).

Nothing is asked that can be worked out. The site name comes from the domain (or the instance ID); the install is always bare-metal, one site per instance.

There is no credentials file on this path — the owner already knows the password, because they chose it. Every other install writes one, since nobody chose that password.

An instance built this way is entirely the deployer's: no agent, no registration, no enrollment, no outbound call beyond fetching the release archive.

**How it is put together.** The script hosted at Linode is a wrapper of about twenty lines: it declares the fields, fetches the release archive, and hands off to `maintenance_scripts/install_tools/linode_stackscript.sh` inside it. All the real logic lives in the archive, so it ships with every release and an instance created today installs what was published this morning — with nothing to update on the Linode side. The pasted wrapper is kept in the repo at `maintenance_scripts/install_tools/linode_stackscript_wrapper.sh` so it stays reviewable.

If a step fails the script stops and says so in `/var/log/stackscript.log`, rather than continuing into a half-installed box that looks alive. The remedy is to destroy the instance and redeploy with the field corrected.

### Manual transfer

```bash
# Copy the archive to the target server
scp joinery-X-Y.tar.gz root@YOUR_SERVER:~/
ssh root@YOUR_SERVER
tar -xzf joinery-X-Y.tar.gz
cd maintenance_scripts/install_tools

# One-time host setup
sudo ./install.sh docker         # OR: sudo ./install.sh server

# Create your first site (password auto-generated — save it!)
sudo ./install.sh site mysite mysite.com 8080   # Docker (with port)
sudo ./install.sh site mysite mysite.com        # Bare-metal (no port)
```

The presence of a port signals Docker mode; omitting it signals bare-metal. To force either explicitly, use `--docker` or `--bare-metal`. The resolved mode is recorded in the site's `Globalvars_site.php` as `deployment_environment` (`docker` or `baremetal`) — the single source of truth the platform reads instead of probing for a container at runtime.

## Prerequisites

### Server requirements

- Fresh Ubuntu 24.04 or 26.04 LTS — `install.sh server` refuses to run on anything else. It installs whichever PHP the release offers and derives every package, service, and config path from that, so no version is pinned; the gate is about which releases the package and service layout has been verified on. On an unverified release the setup can leave a server that does not work while looking like it installed. To proceed anyway and finish the setup by hand, pass `--allow-unsupported-os`; the check is not repeated by `install.sh site`, which presupposes `server` already ran.
- Root access
- 1 GB RAM minimum
- 3 GB disk minimum
- Docker mode: port 8080+ available (or your chosen range)
- Bare-metal mode: ports 80 / 443 available

### Archive contents

The `joinery-X-Y.tar.gz` archive contains:

- `public_html/` — application code
- `config/` — configuration templates
- `maintenance_scripts/install_tools/` — installer, Dockerfile, defaults
- `maintenance_scripts/sysadmin_tools/` — backup, restore, maintenance utilities

## Password Security

**Never use weak or example passwords in production.** Auto-generation is the recommended path.

### Auto-generated (recommended)

Omit the password and the installer generates a 24-character secure password, then displays it once at the end of installation:

```bash
sudo ./install.sh site mysite mysite.com 8080
# Output: "Auto-generated secure password: xK9mN2pQ7rT4vW8yB3cF6hJ1"
```

Save the password immediately — it's also written to the site's `Globalvars_site.php`.

### Bring your own password

Hand it over in a file so it never appears on a command line:

```bash
echo 'YourStr0ng&Secure#Pass@9' > /tmp/dbpass.txt
sudo ./install.sh site mysite --password-file=/tmp/dbpass.txt mysite.com 8080
rm /tmp/dbpass.txt
```

### Any character works

Quotes, backslashes, dollar signs, backticks, exclamation marks, spaces: all fine. The one value the password cannot hold is a line break, because it travels through single-line files.

The password is never pasted into a command. It reaches PostgreSQL as a quoted SQL literal on psql's standard input, reaches `Globalvars_site.php` through a PHP writer that emits a correct string literal, and reaches the database driver as a connection argument rather than part of a connection string.

What can still alter it is your own shell, before the installer runs: `!` triggers history expansion in an interactive bash session, and `$` and backticks expand inside double quotes. A file, or a single-quoted environment value, sidesteps that:

```bash
sudo POSTGRES_PASSWORD='It'"'"'s $12.50 & a "quote"!' ./install.sh site mysite mysite.com 8080
```

### Requirements

- Minimum 16 characters (24+ recommended)
- Mix of upper, lower, digits, symbols
- No dictionary words, personal info, sequential patterns, or example passwords

### Non-interactive deployment

```bash
sudo ./install.sh -y docker
sudo ./install.sh -y -q site mysite mysite.com 8080
```

`-y` accepts all prompts; `-q` suppresses progress output. Both flags work before or after the subcommand — write them first, as above, so every example reads the same way. An unknown flag stops the install with a message.

Without `-y`, a run with no terminal on stdin (cloud-init, CI, piped ssh) still completes: every prompt takes its default. Defaults are conservative — proposals (install Docker, use a suggested port) proceed; destructive choices (overwrite an existing site, delete data volumes, downgrade code) refuse, and only their explicit flags (`--wipe-data`, `--allow-downgrade`) can say otherwise. The one hard requirement is the bare-metal server setup's database password, which must arrive via `POSTGRES_PASSWORD` in the environment when nobody can type it.

## The Data Root

Everything a host keeps as data lives under one mount point, `/srv/joinery`, on a filesystem of its own: a new bare-metal server's database, mail queue, rspamd state and sites' files, and a new Docker host's containers, images and volumes. One place to measure, grow and back up, the same on every install. A site container needs nothing of its own: its volumes are already under Docker's data, on the data root.

`install.sh server` and `install.sh docker` make it on a new host, before anything that keeps data is installed. `--data-root=SIZE` gives its first size and `--data-root=/dev/sdX` a spare device for it; without either it starts at the size below. A root disk that cannot give it 4 GiB above its reserve (below) stops the install: the host would have nowhere for its data. A host that already keeps data where the data root would go (a server or Docker host installed before it, or `server` run again on such a box) keeps it where it is and says so; `joinery_data_root.sh migrate` moves it (below).

- **A file, allocated whole.** `/srv/joinery.img` on the root disk, fully allocated so it never promises space the disk lacks, formatted XFS and mounted with project quotas through `/etc/fstab`. The loop device reads the file with direct I/O. `/etc/joinery/data_root` records what backs it; `--data-root=/dev/sdX` (or `joinery_data_root.sh create /dev/sdX`) puts it on a spare device instead, mounted by UUID.
- **Services wait for it.** `joinery-data.target` is reached when `/srv/joinery` is mounted. PostgreSQL, Docker and containerd, Apache, every PHP-FPM version, Postfix and rspamd require it (a drop-in `joinery-data-root.conf` under each unit's `.d/`), so none starts on an empty directory of the root disk. The mount carries `nofail`, so a host whose data root fails to mount still boots and its agent can say why.
- **It starts modest and grows as it fills.** XFS grows but never shrinks, so the first size is the one given (`--data-root=SIZE`), or the larger of 16 GiB and a quarter of the root disk when none is. The root disk always keeps the larger of 6 GiB and 15% of itself. Every minute the host converger runs `joinery_data_root.sh tick`: when the data root has less than the larger of 8 GiB and 20% of its size free, the file grows online to the largest of a quarter more, its used space plus 12 GiB, and its used space over 0.7, as far as the root disk can give. When it cannot grow, the converger's transcript says so once a day, and the agent's `disk_headroom` recipe opens a case when it reaches its floor, as for any full disk. A data root on a device grows only after its provider grows the device.
- **Nothing runs without it.** While a declared data root is not mounted, the host converger runs no installer, in any mode, and records `data-root-not-ready`; the site's admin header names it with the `status` command, and a `run_plugin_installers` or `host_converge` job goes red with the same reason. A failed growth or unit reload on a mounted data root is reported in the transcript and does not stop the run. A package upgrade that restarts PHP-FPM, Postfix or PostgreSQL while the data root is unmounted cannot start the service, so the package stays half-configured; once the data root is back, `dpkg --configure -a` finishes it. `joinery_data_root.sh status` says what backs it, its size, use and free space, whether it can still grow, each place mounted from it, and what is under it; `check` exits 0 when it is right, 1 with the reason when not, and 2 on a host with none.
- **The host report shows it.** `host_report`'s `disk_pool` carries the data root's figures, shown on the node page as **Data root**, and the agent's `disk_usage` word reports its biggest directories beside the site tree's.

**The same paths as always.** Packages and the site address their data where they always have; each of those paths is a **bind mount** of its place under the data root, kept by `joinery_data_root.sh bind REL TARGET`, recorded in `/etc/joinery/data_binds`:

| Under `/srv/joinery` | Mounted at | Made by |
|---|---|---|
| `postgresql` | `/var/lib/postgresql` | `install.sh server` |
| `mail/postfix` | `/var/spool/postfix` | `install.sh server` |
| `mail/rspamd` | `/var/lib/rspamd` | `install.sh server` |
| `sites/{site}/{folder}`, for `uploads`, `static_files`, `storage`, `backups`, `logs` and `cache` | `/var/www/html/{site}/{folder}` | `install.sh site` (`_site_init.sh`) |
| `sites/{site}_test/logs` | `/var/www/html/{site}_test/logs` | `install.sh site --with-test-site` |
| `docker` | (Docker's `data-root` in `/etc/docker/daemon.json`) | `install.sh docker` |

A mount, not a symlink: the backup engine, restore, the site census and the permission sweep all treat a symlink as one small file and never go inside it, so a backup would quietly stop carrying the files behind it. To every tool a mounted folder is an ordinary folder. A file moved between two of them (`uploads/` to `static_files/`) is copied rather than renamed; PHP's `rename()` does that by itself.

- Each mount is a unit of its own (`var-lib-postgresql.mount`, `var-www-html-{site}-uploads.mount`) that requires the data root's mount, and `joinery-data.target` requires every recorded one, so a service that waits for the target waits for its folders too.
- The folder underneath each mount is root's, mode 000 and immutable: while the data root is down, nothing writes to the root disk in its place.
- `bind` refuses a path that holds data and changes nothing (exit 3). A new site whose folders already hold data keeps every one of them where it is.
- `check` fails while any recorded mount is not in place, naming it, and the converger's `tick` mounts it again. Because the target requires every recorded mount, a mount whose place under `/srv/joinery` is gone keeps every service that waits for the data root down; take it away with `unbind`.
- `unbind TARGET` takes one away: the target lets it go first, so no service stops, and the path is left an empty folder with its place's owner and mode; the data stays under `/srv/joinery`. `remove-site NAME` takes every mount of a site and its data there; `remove_account.sh` runs it before it removes a bare-metal site's directory.
- Incremental backups ignore the device number (`--no-check-device`): the data root's loop device can come back under another number after a reboot.
- `/var/lib/joinery/host`, the agent and its log, the code and `config/` stay on the root disk, so a host whose data root is the thing that failed can still say why.

**Moving an existing host onto it.** `joinery_data_root.sh migrate [SIZE|DEVICE]`, as root, moves a host's data onto the data root, making the data root first when the host has none:

- **What moves:** every place in the table above that is on this host and not yet mounted from the data root (PostgreSQL, the Postfix queue, rspamd, each site's six folders, a companion test site's logs), and Docker's data-root, which becomes `/srv/joinery/docker` in `daemon.json`. A directory under `/var/www/html` without `config/Globalvars_site.php` is not a site and is left alone.
- **Before anything changes**, it says every reason it cannot move, and stops: a folder that is a symlink or not on the root disk, a cluster whose `data_directory` is outside `/var/lib/postgresql`, a site that is upgrading, a data root too small for the data with room, or a root disk that cannot hold the data twice. Without a size, a new data root is the largest of the first size above, the data plus 12 GiB, and the data over 0.7, as far as the root disk can give.
- **The move:** it holds every host converger off (their runner locks), stops every running service whose data moves, and cron, waits up to two minutes for anything still holding a file there, copies each place with `rsync` (hard links, ACLs, extended attributes, numeric owners) and compares each copy with its original, nanosecond times included. Only when every copy matches does it switch them: each original is moved aside to `/srv/joinery.old`, each path becomes a mount of its copy, and the services start again. The downtime is the time to copy the data once.
- **A move that does not finish** (a copy that fails or differs, a mount that does not take) puts every folder back, points Docker back, removes what it copied and starts the services again; the host keeps its data where it did.
- **The copies from before** stay at `/srv/joinery.old`, root's only, and `status` says so. `/etc/joinery/data_root_migrated` records the boot that moved them; the first `tick` on a later boot whose `check` passes removes them. Reboot after a move: the reboot is the proof that the data root and every mount come up on their own.
- Run again, it moves only what is not moved yet, so a site added the old way joins the others, and it stops only what keeps the data that moves: a site alone stops the web stack and cron, never PostgreSQL.
- It runs in a systemd unit of its own, `joinery-data-root-migrate.service`, and logs to `/var/log/joinery-data-root-migrate.log`: a caller that goes away (an ssh session, an agent job that times out) does not end it, and a second move is refused while one runs. `systemctl stop` on the unit puts everything back. Run it from `/`, not from inside a folder that moves: the shell that started it counts as holding that folder.
- For the length of the move the stopped units, the host converger, logrotate and unattended upgrades are masked (`systemctl mask --runtime`), so nothing starts them; the move unmasks them before it starts the services, and the log names the command that does it by hand if the move is ever killed outright.

## Docker Deployment

### What Docker mode is

Docker mode is how you run several Joinery sites on one machine. Each site gets a container holding a complete small server — Apache, PHP-FPM, PostgreSQL, cron, and whatever daemons its plugins need — with its own database, its own files and its own host port. The container's job is to keep sites from colliding with each other over ports, database names and file paths.

The container is not a security boundary, and a site is not a sealed appliance that can be dropped safely onto a shared machine. Treat every site on a host, and the host itself, as one trust domain:

- **Each site has a network of its own, but not a wall.** Docker keeps one site's network from another's, so a site cannot connect straight to another site's container. It can still reach the host and whatever the host can reach, including another site's ports where they answer on the machine's own addresses. See [Each site's network](#each-sites-network).
- **The host is part of the site.** HTTPS terminates on the host's Apache, which proxies to the container over plain HTTP on loopback. The certificate, the proxy vhost and the site's DNS all live outside the container.
- **`docker` access is access to every site.** Site data lives in named volumes on the host, `docker exec` opens a root shell in any container, and membership of the `docker` group is equivalent to root on the machine.
- **Root in a container holds six capabilities.** Every site container runs with `--cap-drop=ALL` and only what a site needs back: CHOWN, DAC_OVERRIDE, FOWNER, SETUID, SETGID and KILL. So it cannot open raw sockets, create device nodes or change the container's firewall. There is no read-only root filesystem, and no user-namespace remapping unless the host was built with `install.sh docker --multi-tenant`. Processes start as root inside the container; Apache and PHP-FPM drop their workers to `www-data`.
- **A site's ports answer on the host itself.** A site with a domain publishes its web port on `127.0.0.1`, where the host proxy reaches it, so the proxy — HTTPS, the redirect to it, and the host's fail2ban — is the only way in. A site installed with no domain, or with `--no-ssl`, has no proxy: its web port is its only way in and answers on every interface, and since Docker's forwarding rules are consulted before UFW's, a UFW rule does not close it. The database port publishes on `127.0.0.1` (see [PostgreSQL access](#postgresql-access)), and `install.sh docker` adds a `DOCKER-USER` rule, for IPv4 and IPv6, dropping ports 9080-9099 arriving on the public interface.

`install.sh docker` installs the host's own agent (siteless, joining the management node given with `--management-node=URL --node-name=NAME`); it is the only moment a host agent is installed, and a host built before that step existed gets its agent by running the same command once from a current release tree. `install.sh docker` also does the host's housekeeping: fail2ban (see [fail2ban and the real client address](#fail2ban-and-the-real-client-address)), a 100M cap on the system journal, Docker BuildKit garbage collection, 1G of encrypted swap, apport off, and cleared failed-login logs. It does not configure UFW on a Docker host — the UFW rules in this guide belong to bare-metal server setup.

So run Joinery containers on a machine you control, and put sites that must not reach each other on separate machines rather than in separate containers.

### One-time setup

```bash
sudo ./install.sh docker
```

Checks for Docker, installs Docker CE if missing, starts the daemon, verifies it's operational. On a host Docker is not yet on, it makes the [data root](#the-data-root) first and points Docker's `data-root` at `/srv/joinery/docker` (`install_tools/docker_disk_pool.sh create`), so every container, image and volume lives on it. `--data-root=SIZE|DEVICE` gives its first size or a device.

```bash
sudo ./install.sh docker --multi-tenant
```

For a host that will carry sites belonging to different people. Docker remaps user ids (`userns-remap`), so root inside a site container is an unprivileged user on the host (uid 100000 and up), and the files in every site's volumes are owned by those ids. It is set before Docker first starts. On a host that already has Docker it is turned on only when Docker holds no container and no volume, since remapping moves Docker's data under `/var/lib/docker/100000.100000` and would leave existing sites behind. A host that already remaps is left as it is. Every site on the host shares the one range, so remapping protects the host from a site, not one site from another. The walls below do that.

`--multi-tenant` then runs `install_tools/multi_tenant_host.sh install`, which adds two things. They are added on a new host and on one that already remaps, and the agent does not join until they are in place.

- **Walls.** Firewall tables named `joinery_site_walls` (nftables, `inet` for IPv4 and IPv6 alike, plus `bridge`) apply to every site's bridge: `docker0`, and each site's own `jsnetN`. Docker's other networks are not walled.
  - To the host itself, a site reaches only ports 80 and 443. That is enough to load another site's pages through the proxy. Replies pass: the proxy's requests to the site, and the host's reads of the site's database through its published port. The host agent, the host's own database and anything else that listens there are out of reach.
  - Nothing passes from one site to another, across networks or on the same bridge.
  - Nothing from a site reaches port 25 anywhere, or the cloud's metadata address (`169.254.169.254`; over IPv6, `fd00:a9fe:a9fe::1` on Linode and `fd00:ec2::254` on AWS).
  - The host's own outbound port 25 is dropped too.

  `joinery-site-walls.service` loads the walls at every boot, before Docker starts. It runs a root-owned copy of the script at `/usr/local/sbin/joinery-site-walls`. Each load replaces each table whole, in one transaction. Docker itself will not start unless both tables are loaded (a drop-in, `docker.service.d/joinery-site-walls.conf`, runs `joinery-site-walls assert` first). So if the walls fail to load, the sites stay down rather than run without them, and the host report lists `docker.service` as failed. `nftables.service` flushes the whole ruleset when it starts, reloads or stops; a drop-in loads the walls again after each. `multi_tenant_host.sh check` says whether the walls are loaded, whether Docker waits for them, and whether apt will update and reboot, and exits 1 if not. `multi_tenant_host.sh ruleset` prints the rules.
- **Reboot after kernel updates.** Automatic updates run at 05:30 UTC (spread over 30 minutes), after the backup window. When an update needs a reboot, the host reboots at once, even with someone logged in. A night the host was off is not made up at the next boot, so an update never reboots it in the day or in the middle of its own install. The settings are `/etc/apt/apt.conf.d/52joinery-multi-tenant-reboot` (which also turns on the nightly run) and a drop-in for `apt-daily-upgrade.timer`. The node page shows how long a reboot has been pending: grey for the first day, amber after.

Running `install.sh docker --multi-tenant` again rewrites only what differs, and reloads the walls only when they changed or are missing, so their drop counts are kept. If the walls cannot be installed (nft refuses the rules, say), nothing is written and the install stops.

It then runs `install_tools/proxy_default_site.sh install`, which gives the host's proxy a **default site**. Apache picks a site by the name a request asks for, and a name no site claims goes to the first site loaded for that port. On a shared host, that would be another customer's site and certificate: for a domain pointed at the host by mistake, a site whose certificate has not been issued yet, or the host's bare address. The default site answers every such request on 80 and 443 with a plain "There is no site at this address" page (404). Over TLS it uses a self-signed certificate for `no-site.invalid`, which names no customer.

- The script installs Apache if it is missing and turns off Ubuntu's `000-default`, which would serve `/var/www/html`.
- It writes `/etc/apache2/sites-available/000-joinery-no-site.conf`, which Apache loads first. Its certificate is at `/etc/ssl/joinery/no-site.invalid`.
- `install.sh site` refuses a Docker site whose name would load ahead of it or take its file name (`0`, `0-x`, `00-a`, `000-a`, `000-joinery-no-site`).
- Host housekeeping runs the install again on every run on a host that has the default site. It rewrites only what differs, turns a returned `000-default` back off, and fails the run if a site loads ahead.
- If Apache refuses the configuration, the script takes it back out and does not reload.
- It fails unless `apache2ctl -S` names the default site as the default on both ports, and the install stops with it.
- `proxy_default_site.sh check` says the same and exits 1 if not.

**A suspended site.** Every container site's proxy vhost (`default_proxy_vhost.conf`) carries a switch. While the mark `/etc/joinery/sites/<site>/suspended` exists, the site's name answers on both ports with a plain "This site is suspended" page (503), and nothing reaches the container. The switch is read when Apache loads its configuration. The host agent's `suspended_page` word (`sysadmin_tools/suspended_page.sh show|clear <site>`) places or removes the mark, tests the configuration, reloads Apache, and then asks the proxy what it answers for the site's name.

- A site whose proxy vhost carries no switch is refused.
- If Apache refuses the configuration, the mark goes back as it was.
- certbot's challenge rule loads ahead of the switch, so a suspended site's certificate still renews.
- Removing the site removes its mark.

**A disk allowance per site.** Docker on an ordinary cloud disk cannot cap one container's disk, so one site could fill the disk for all of them. A multi-tenant host can: Docker keeps its data on the host's [data root](#the-data-root), which has XFS project quotas, and with user ids remapped a site cannot move its own files out of its allowance. `install_tools/docker_disk_pool.sh can-cap` says whether this host can cap a site's disk, and why not.

`install.sh site SITENAME ... --disk=4G` then gives the site its allowance, and a site install with `--disk` on a host that cannot cap it is refused:

- All of the site's volumes but `backups` and `deploy` share one XFS project, so the allowance covers its whole footprint. `backups` (fleet backups stage there) and `deploy` (an upgrade's staging and the previous code) are each a project of their own, outside the allowance, so a full site can still be backed up and upgraded.
- The project's hard limit is the allowance plus 10%. The site itself stops taking uploads and stored mail at the allowance (`DiskAllowance`), naming what is used and the way out, and inbound mail is deferred so senders retry. The 10% above is left for the database, logs and system writes, so PostgreSQL never meets the wall first.
- The container's writable layer is capped at 1 GB (`--storage-opt size=1G`): packages and temporary files only.
- The allowance is the run spec's `disk=` line, so a rebuild keeps it; `--disk=none` lifts it. The host writes it to the site's `config/disk_allowance`, and the site's status check reports its disk as its use out of the allowance. `df` on any of the site's volumes shows the hard limit.
- Site projects are numbered from 1,000,000,000 (`/etc/projid`, `/etc/projects`), far above the numbers Docker gives its own layer quotas. Removing the site removes its projects.

### Create a site

```bash
sudo ./install.sh site SITENAME [DOMAIN_NAME] [PORT] [OPTIONS]
```

| Parameter         | Required | Default   | Notes                                          |
|-------------------|----------|-----------|------------------------------------------------|
| `SITENAME`        | Yes      | —         | Site & database name (e.g., `mysite`)          |
| `DOMAIN_NAME`     | No       | Server IP | Domain for VirtualHost                         |
| `PORT`            | No       | 8080      | Host port for web traffic                      |

The installer:

1. Validates parameters and archive structure.
2. Checks port availability and suggests alternatives on conflict.
3. Prepares an isolated build context.
4. Builds the Docker image.
5. Starts the container with persistent volumes.
6. Verifies the site responds — the probe carries the configured domain in its `Host:` header, so a green check means a visitor can load the site, not merely that Apache is up. A redirect to an HTTPS vhost the install did not create fails the check.
7. Optionally downloads stock themes/plugins (with `--themes`).
8. Displays access info and the list of running containers.

### Multi-site support

Each site needs unique ports. The installer detects conflicts and suggests the next pair:

| Site   | Web port | DB port |
|--------|----------|---------|
| site1  | 8080     | 9080    |
| site2  | 8081     | 9081    |
| site3  | 8082     | 9082    |

### Limits

Each site container can be given limits, so one site cannot use up the machine:

| Option | Limits | Default |
|---|---|---|
| `--memory=SIZE` | memory, in Docker's syntax (`512m`, `1G`); swap is held to the same figure | none |
| `--cpus=N` | CPU, in cores (`1.0` is at most one core) | none |
| `--pids-limit=N` | processes and threads together; at least 128 | 512 for a new site |
| `--disk=SIZE` | disk, over all of the site's volumes but `backups` and `deploy` (`4G`, `500M`; at least 100M); only on a multi-tenant host, see above | none |

Under load, sites already share the CPU evenly, whatever their ceilings. The limits are recorded in the site's run spec, so a rebuild keeps them; give an option again to change one, or `none` to lift it. `install.sh site-limits SITENAME [--memory=SIZE] [--cpus=N] [--disk=SIZE]` changes memory, CPU and the disk allowance on the running site without a rebuild (`sysadmin_tools/site_limits.sh`, the host agent's `site_limits` word, the node page's **Change a site's limits**); a flag left out keeps that limit, a memory change restarts the site, and lifting a memory limit or CPU ceiling takes a rebuild. A limit is refused before anything stops if Docker would refuse it, such as a CPU ceiling above the machine's CPUs. A bare-metal site has no container, so the options are refused there. With a memory budget, PostgreSQL, PHP's worker pool and the database connections between them are sized from it at every start. The capabilities above are not a limit and not in the run spec: every rebuild applies them to every site. See [Deploy and Upgrade](deploy_and_upgrade.md) for the run spec and the sizing.

### Each site's network

Every site container runs on a Docker network of its own, `SITENAME_net`, with a private IPv4 subnet and a private IPv6 /64. Each host numbers its site networks from 1 to 254. Network N is `10.250.N.0/24` and `fd00:250:N::/64`, on the host bridge `jsnetN`. `install.sh site` picks the lowest number nothing on the host already uses: no other site, Docker network, route (a VPN, a private network) or interface. It creates the network before it stops anything and records it in the run spec (`network=`, `bridge=`, `subnet=`, `subnet6=`), so a rebuild, rebase or move keeps it. A network found missing or different when a container is about to run on it is made again as the spec says. If a container is still attached to a different one, the run is refused instead.

A site's outbound traffic leaves through the host from the machine's one IPv4 address or its one IPv6 address. Docker translates both. Inside the container, `/etc/gai.conf` (written by `host_housekeeping.sh` at every start) makes the container use IPv6 wherever a destination has it. Without it, glibc puts IPv4 first from a private IPv6 address. Many providers do not charge for IPv6 traffic between machines, and to object storage, in the same data center.

A network is IPv4 only when the machine has no IPv6 route out, when Docker is older than 27 (the first version to carry a container's IPv6 out without a `daemon.json` setting), or when `daemon.json` sets `ip6tables` to `false`. The installer says which. Once the host can carry IPv6, the next rebuild gives the network its IPv6 subnet.

A site running on Docker's default network moves to its own network without stopping:

```bash
sudo maintenance_scripts/sysadmin_tools/move_site_to_own_network.sh SITENAME   # or --all
```

The script records the site's run spec if it has none, creates the network, connects the container to it and disconnects it from the default network. It then runs `host_housekeeping.sh` inside the container, so Apache trusts the new gateway and PostgreSQL admits the host from it. Apache bound its port before the container had IPv6, so it is started once more, under the supervisor's hold, to answer over IPv6 too. Published ports follow the container, and visitors are answered throughout. Running it again finishes a move that was interrupted. It refuses a site whose code is older than that housekeeping step, because on the new network such a site would log every visitor as the gateway. Apply the site's update first. `remove_account.sh` removes a site's network with its container.

A site's own network is also what the [outbound limits](#outbound-limits) name it by: a site still on Docker's default network shares `docker0` with every other such site and is not limited until it moves.

### What keeps a site running

A container's main process is its supervisor, `_site_supervisor.sh`. It starts Apache, then checks every 5 seconds that PostgreSQL, PHP-FPM, Apache and cron are running. When a site runs out of memory, the kernel kills its biggest process. When one of the four has been gone for three checks in a row, the supervisor clears whatever it left behind (a dead PHP-FPM master's workers, a dead Apache parent's children still holding port 80) and starts it again. Each restart is a line in the site's `logs/error.log` and in `docker logs`. `docker stop` stops all four cleanly, so PostgreSQL shuts down rather than being killed.

The container's health check runs `_site_supervisor.sh --check`. A service the supervisor cannot bring back turns the container **unhealthy** after about 90 seconds; the host agent's `container_health` then restarts the container. This catches the case a page probe cannot: a dead cron leaves the site answering while its scheduled tasks stop.

A script that stops one of the four on purpose takes the hold first, and the supervisor restarts nothing while it is there:

```bash
mkdir -p /run/joinery && echo "who and why" > /run/joinery/supervisor.hold
```

The next container start clears it. `rebase_site_container.sh` holds the supervisor while it moves a database.

### Volume mounts

| Volume                 | Container path                | Purpose                |
|------------------------|-------------------------------|------------------------|
| `{site}_postgres`      | `/var/lib/postgresql`         | Database files         |
| `{site}_uploads`       | `.../uploads`                 | User uploads           |
| `{site}_config`        | `.../config`                  | Site configuration     |
| `{site}_backups`       | `.../backups`                 | Database backups       |
| `{site}_static`        | `.../static_files`            | Generated files        |
| `{site}_logs`          | `.../logs`                    | Application logs       |
| `{site}_cache`         | `.../cache`                   | Runtime cache          |
| `{site}_sessions`      | `/var/lib/php/sessions`       | PHP sessions           |
| `{site}_apache_logs`   | `/var/log/apache2`            | Apache logs            |
| `{site}_pg_logs`       | `/var/log/postgresql`         | PostgreSQL logs        |

## Bare-Metal Deployment

### One-time setup

```bash
sudo ./install.sh server
```

Makes the host's [data root](#the-data-root) and mounts PostgreSQL's, Postfix's and rspamd's directories from it (`--data-root=SIZE|DEVICE` gives its first size or a device), then installs and configures PHP 8.3, Apache (with `mod_rewrite`), PostgreSQL, Composer, Certbot, UFW, fail2ban, SSH hardening, and unattended security updates. Beside PHP's `gd` it installs `libjpeg-turbo-progs`, whose `djpeg` lets the site decode a large JPEG already shrunk to the sizes it needs (about 15 MB for a 24-megapixel photo instead of 92; see [Photo System](photo_system.md#how-sizes-are-made)); the same step runs inside the base image build. The package is also declared in root `composer.json` (`extra.joinery-system-packages`), so the dependency resolver installs it at every other root moment too — container start, `install.sh site`, and `upgrade.php` on a node that was installed before it was declared. Without it a site still works, decoding large JPEGs in full.

#### How SSH hardening picks its account

Turning off root SSH login is the one hardening step that can lock an operator out, so the installer works out who will still be able to reach the box before it does that. It creates no account and copies no key. Everything else — `MaxAuthTries 3`, empty passwords refused, idle-session timeouts, fail2ban, UFW — is applied unconditionally.

| What the installer finds | What it does |
|---|---|
| Running as root, and `/root/.ssh/authorized_keys` has keys | Sets `PermitRootLogin prohibit-password`: root keeps its key login and loses password login. |
| Running under `sudo` from an ordinary account | That account already has its own key and sudo, so it sets `PermitRootLogin no`. |
| Neither — root reached by password, no key installed | Leaves `PermitRootLogin` alone and says so. Disabling it here would leave nothing able to log in. |

The setting is written to `/etc/ssh/sshd_config.d/00-joinery-root-login.conf`. sshd takes the first value it reads, and Ubuntu's `sshd_config` includes that directory first, so a file there is what takes effect; `sshd -t` checks it before SSH restarts.

The third case is the only one that finishes with root password login still enabled. It is what you get on a provider that boots you a machine with a root password and no SSH key attached. On a self-hosted machine that password is the owner's only way in, so the installer leaves it on and relies on the fail2ban jail (three failures in ten minutes, banned for an hour) to limit guessing. On a machine a management node provisioned, the management node turns password login off itself once the machine's agents are admitted; see the Server Manager plugin's `retire_install_password` job.

#### fail2ban and the real client address

`maintenance_scripts/install_tools/host_housekeeping.sh` is the one implementation of "fail2ban is configured". It is a core host installer, so it runs at install, on every converge of the host timer (`_plugin_installers_start.sh`, `CORE_INSTALLERS`), through the runner alone with `sudo bash /var/www/html/SITE/maintenance_scripts/install_tools/_plugin_installers_start.sh --only=host_housekeeping.sh` (the same lock and ownership check the timer's run gets, nothing else run), on a machine with no site through the agent's support bundle (`_plugin_installers_start.sh --machine`, see [deploy_and_upgrade.md](deploy_and_upgrade.md)), and by hand:

```bash
sudo bash /var/www/html/SITE/maintenance_scripts/install_tools/host_housekeeping.sh
```

What it leaves behind, every time: `/etc/fail2ban/jail.d/joinery-sshd.local` (the SSH jail: three failures in ten minutes, banned for an hour) and, where Apache is installed **and logs the real client** (the `mod_remoteip` step below is in place), `jail.d/joinery-apache.local` (`apache-auth`, `apache-badbots`, `apache-noscript`, `apache-overflows`, each on the file backend and watching `/var/www/html/*/logs/` — a site's `access.log`/`error.log` and a Docker host's `proxy_access.log`/`proxy_error.log` — as well as `/var/log/apache2/`). Both are written whole, never appended to. Without the remoteip configuration an Apache log names the peer, which behind Cloudflare is an edge, so the Apache drop-in is removed rather than written and the output says why; the SSH jail is configured regardless. A `/etc/fail2ban/jail.local` that is `jail.conf` followed only by section headers, `enabled` lines and a ban policy (`bantime`, `findtime`, `maxretry`) for jails the drop-ins carry is deleted — that is a copy-and-append "enable fail2ban" in any wording, it repeats sections `jail.conf` already declares, and fail2ban 1.0.2 refuses to start on a repeated section; one with any other content is left alone and named in the output, with a warning when it repeats a section itself. The script then **proves** the service: it exits non-zero, with the journal's last lines, when `fail2ban` is not active afterwards or the `sshd` jail does not answer. SSH posture (`PasswordAuthentication`, `PermitRootLogin`) is read from `sshd -T` and reported, never changed.

The range list is read from the site tree the script lives in (`SITE_ROOT/public_html/includes/cloudflare_ip_ranges.txt`). On a Docker host, whose site trees are inside the containers, run it from a checkout of the code or name the tree: `sudo bash host_housekeeping.sh "" /path/to/checkout`.

A jail bans whatever address the log names, so the log must name the real client, and must name it **only when every hop between the client and Apache is a proxy we know**. The same script enables `mod_remoteip` with `conf-available/joinery-remoteip.conf`: `X-Forwarded-For` is trusted from Cloudflare's published edge ranges (`public_html/includes/cloudflare_ip_ranges.txt`, the one list `SessionControl` also trusts a `CF-Connecting-IP` from) and, inside a container, from the host's reverse proxy, which arrives from the gateway of the site's network (IPv4 and IPv6, read from the container's routes at every start); the `combined` and `vhost_combined` log formats record that resolved client (`%a`). A forged forwarding header on a direct connection stops at the untrusted peer, so a ban can never be steered onto a third party. The Docker host's proxy vhost **appends** its peer to `X-Forwarded-For` rather than replacing it, so the container walks the chain right to left to the client. Behind an edge a ban is inert (the connections come from the edge); on a directly reached site it lands on the attacker.

### Create a site

```bash
sudo ./install.sh site SITENAME DOMAIN_NAME [OPTIONS]
```

Common options:

- `--admin-email=ADDRESS` — the admin account's address. Set at the same moment as its password, so the only account on a new site is recoverable by email from the start. Omitted, the account is `admin@example.com`.
- `--activate THEME` — activate a specific theme after install
- `--with-test-site` — create a companion test site (bare-metal only)
- `--upgrade-server=URL` — fetch the code from somewhere other than the release site (see [Where a site gets its upgrades](#where-a-site-gets-its-upgrades))

The installer:

1. Verifies prerequisites (Apache, PHP, PostgreSQL).
2. Deploys code to `/var/www/html/{sitename}/`.
3. Runs `_site_init.sh` to create directories, configure `Globalvars_site.php`, create the database, load the schema, record where upgrades come from, install Composer deps, install the default plugin bundle, and create the Apache VirtualHost.
4. Optionally creates a test site.
5. Verifies the site responds.

### What a new site comes with

A fresh install is not the bare platform. Drive and the personal calendar are core and always present; on top of them the installer turns on a **bundle** — a named set of plugins declared in `install_bundles.json` at the `public_html/` root.

The default bundle is `personal`: mail and the AI assistant, which together with Drive and Calendar make the deployment a self-hosted replacement for the everyday Google tools. Everything else — events, commerce, bookings, the password vault, DNS filtering, server management — is installed from `/admin/admin_plugins` when it is wanted.

Both bundled plugins arrive installed and unconfigured, and each needs the owner to supply something before it does anything: mail needs MX and DKIM records and an outbound provider, the assistant needs a model provider.

```bash
# choose a different bundle at install time
JOINERY_INSTALL_BUNDLE=personal sudo ./install.sh site mysite mysite.com

# or apply one to an existing site
sudo php /var/www/html/{sitename}/maintenance_scripts/sysadmin_tools/install_bundle.php --list
sudo php /var/www/html/{sitename}/maintenance_scripts/sysadmin_tools/install_bundle.php --bundle=personal
```

### Services set up at install

Three things the setup wizard would otherwise ask for can be handed to `install.sh site` in the environment, on every path (bare metal, Docker, the StackScript). `_site_init.sh` honours them on fresh installs, after the plugin bundle, and none is a condition of the install: a failure is recorded and the wizard asks for that one again.

| Variable | What it does |
|---|---|
| `JOINERY_MAIL_API_KEY` (+ `JOINERY_MAIL_PROVIDER` to name the provider, else detected from the key; `JOINERY_MAIL_FROM`, default derived from the admin address on the site's domain) | Email is set up by `utils/install_mail_provider.php`, the wizard's own ceremony: From address, the owner's mailbox, the domain registered at the provider where its API allows, its mail records published through the kept DNS credential, the provider asked to verify. The wizard then opens on the delivery proof. |
| `JOINERY_BACKUP_BUCKET` + `JOINERY_BACKUP_KEY_ID` + `JOINERY_BACKUP_KEY` (+ `JOINERY_BACKUP_PROVIDER` b2/s3/linode, default b2; `JOINERY_BACKUP_REGION` for s3 and linode) | `utils/install_backup_target.php` creates the target, tests it, and makes it the scheduled one. The recovery key stays the wizard's. |
| `JOINERY_DNS_CREDENTIAL` | The JSON `utils/install_dns_credential.php` takes, e.g. `{"driver":"linode","credential":{"access_token":"…"}}`, kept for the one publish of the mail records and deleted on use. |

```bash
JOINERY_MAIL_API_KEY=api-… \
JOINERY_BACKUP_BUCKET=joinerybackups JOINERY_BACKUP_KEY_ID=… JOINERY_BACKUP_KEY=… \
sudo -E ./install.sh site mysite mysite.com
```

Outcomes land in `{site root}/config/install_services.txt` (`mail=`, `backup=`, `dns_credential=`, each `done:`, `failed:` or `skipped:`; `640 root:www-data`, no secrets), which the closing summary reads and the setup wizard's Welcome step shows (`InstallReport`) — on a Linode StackScript deploy the closing summary is never seen, so the wizard is where the owner learns what the keys they handed over did; the first-task email notice is printed only when email was not set up. On Docker, every `JOINERY_*` input `_site_init.sh` reads crosses into the container from one list in `install.sh` (`SITE_INIT_ENV_INPUTS`). Each tool is CLI-only, reads only the environment, and can be run by hand on an existing site.

`JOINERY_INSTALL_BUNDLE=none` installs no plugins. Bundles are flat lists and never extend one another — they are alternative products rather than layers, so each names everything it wants.

### Where a site gets its upgrades

Two separate things, which the installer keeps in agreement:

- `--upgrade-server=URL` tells *this run* where to fetch the archive from. It defaults to `https://getjoinery.com`, the release site.
- `upgrade_source`, a setting on the finished site, tells `upgrade.php` where to fetch from every time after.

`_site_init.sh` writes the second from the first, so whatever a site was installed from is what it upgrades from. Nothing to configure and nothing to keep in sync: pass `--upgrade-server` and both follow, leave it off and the site tracks stable releases.

### Directory layout

```
/var/www/html/{sitename}/
├── public_html/      # Application code
├── config/           # Site configuration
├── uploads/          # User uploads           (mounted from /srv/joinery/sites/{sitename}/uploads)
├── static_files/     # Generated files        (mounted from the data root, as uploads)
├── storage/          # Stored mail and files  (mounted from the data root)
├── backups/          # Database backups       (mounted from the data root)
├── logs/             # Application logs       (mounted from the data root)
└── cache/            # Page cache             (mounted from the data root)
```

On a host with no data root the folders are ordinary directories in the site directory.

## Outbound Limits

Every install limits what its sites can open toward the outside, so a hacked plugin cannot turn the server into a scanner or a flood. Real use never meets these limits. For each site, at the built-in figures (see [The figures](#the-figures) to change them):

- **New connections:** 20 a second, in bursts of up to 100. An opening over the rate is dropped, and the site's own TCP sends it again a second later, so a short burst is slowed rather than refused. Only a sustained one fails.
- **Connections open at once:** 256.
- **UDP:** none. A site's only UDP is its name lookups, and those go to the host's own resolver, never out as the site's packets. Nothing in the platform uses UDP for anything else, and a container keeps time from the host's clock.
- **Speed:** 200 Mbit/s for everything the site sends, its visitors' downloads included. A month flat out at that speed is about 65 TB, so a runaway site's cost is bounded while someone looks; the transfer watch is what says it is happening.

One limit covers IPv4 and IPv6 together. The limits are a firewall table of their own, `joinery_limits` (nftables, `inet`), beside Docker's rules and the multi-tenant walls. A packet any of them drops is dropped.

- **On a Docker host,** each site is matched by its own network's bridge (`jsnetN`, see [Each site's network](#each-sites-network)), on the traffic the host forwards from it.
- **On a bare-metal server,** everything the web server's user (`www-data`) opens is limited as one, except over loopback, so the site's own database and the local resolver are never touched.
- **What is not limited:** connections a site's visitors open to it, and the machine's own traffic (the host agent, updates, the host's proxy). The replies to visitors count toward the site's speed only.

The speed is held by traffic shaping (`tc`), where the site's traffic is its own:

- **On a Docker host,** a visitor's download leaves the machine from the host's proxy, not from the site's network, so the ceiling sits where the site's traffic enters the host. What arrives on the site's bridge is redirected to a device of its own (`ifb`, `jifbN` for `jsnetN`) that holds it to the ceiling. Replies to the proxy and connections the site opens itself both pass through it. So do the host's own reads of the site's database over its loopback-published port.
- **On a bare-metal server,** the firewall table marks what the web server's user sends, and each interface a default route leaves by redirects the marked packets to one device, `jifbweb`. Apache's replies to visitors carry its user, so downloads are held to the ceiling. Nothing else on the machine passes through it.
- **Uploads to a site** are inbound and are never shaped.

Each device queues fairly (`fq_codel`), so a site's pages stay quick while it sends at its ceiling.

`install.sh docker` and `install.sh server` install the limits as the host's `joinery-limits` unit, run at boot after Docker and every five minutes by `joinery-limits.timer`. `install.sh site`, `move_site_to_own_network.sh` and `remove_account.sh` run the unit when a site arrives, moves or goes. A run that would change nothing leaves the table as it is. A run that changes it replaces it in one step and carries each site's drop count over. Every run puts back any part of the speed ceiling that is missing, such as a site's bridge made again by a rebuild, and takes out what belongs to a site that is gone. If `tc` refuses part of the ceiling, the connection limits stay in force, the unit fails, and the node page says the ceiling is not in force for every site.

The limits depend on the host's resolver being a loopback address, which it is on Ubuntu (`systemd-resolved`, `127.0.0.53`). Were `/etc/resolv.conf` to name an outside resolver, Docker would forward a container's lookups from the site's own network, and dropping its UDP would break them. So the unit checks first. It refuses with the reason, removes its table and fails, and the host report shows the limits as not in force.

```bash
sudo joinery-limits status   # what is in force, for which sites, and each one's drops
sudo joinery-limits show     # each figure, the machine's and each site's
sudo joinery-limits off      # every limit off on this machine, UDP drop and speed ceiling included
sudo joinery-limits on
```

`--no-outbound-limits` on `install.sh docker` or `install.sh server` installs them off. The choice lives in `/etc/joinery/outbound_limits.json` (`"enabled": false`), which only root writes. Running the installer again keeps it.

The installed `joinery-limits` is a copy of `outbound_limits.sh`. Host housekeeping, which runs on every converge, brings the copy up to the release on the machine when that release's is newer, and runs the unit then, so an upgrade carries the limits' new behaviour without a reinstall. It never takes a copy back to an older release.

### The figures

The figures live where only root writes, at two levels:

- **The machine's,** in `/etc/joinery/outbound_limits.json`: `ceiling_mbit` (a number, or `"off"`), `conn_rate`, `conn_burst` and `open_conns`, and `set_by` (`"plane"` when a management node set them). A figure the file does not hold is the built-in one.
- **A container site's own,** in its run spec beside `--memory` (`outbound_ceiling`, `outbound_conn_rate`, `outbound_conn_burst`, `outbound_open_conns`, and `outbound_set_by=plane` when a management node set them), so a rebuild, rebase or move keeps them. A site with none has the machine's. On bare metal every site sends as the web server's user, so the machine's figures are the sites'.

A site is told a management node set its figures when it set the machine's or that site's own; a site's own figures changed by hand on the machine drop the mark. While the limits are off, `joinery-limits show` and the host report keep the figures that turning them on puts back.

A figure that is not one (a hand edit) is passed over for the one below it, with a warning in the unit's journal.

Set them at install time:

| Flag | Sets | Built in |
|---|---|---|
| `--outbound-ceiling=MBIT` or `=off` | the speed ceiling | 200 |
| `--outbound-conn-rate=N` | new connections a second | 20 |
| `--outbound-conn-burst=N` | the burst above it | 100 |
| `--outbound-open-conns=N` | connections open at once | 256 |

On `install.sh docker` and `install.sh server` they are the machine's. On `install.sh site` they are that container site's own, and a rebuild without the flag keeps the figure; `default` takes one back to the machine's. A bare-metal site refuses them. `install.sh site` also takes `--outbound-notice-gb=N`, the first figure of the site's monthly transfer notice (`outbound_monthly_notice_gb`), a setting the site's admin can change.

Change them later without a reinstall:

```bash
sudo joinery-limits ceiling 500                  # the machine's ceiling
sudo joinery-limits ceiling off --site=mysite    # one container site's
sudo joinery-limits conn-rate default            # back to the built-in figure
sudo joinery-limits set --ceiling=300 --open-conns=512 --site=mysite
```

Each writes the host file, or the site's run spec through the same helper `install.sh site` uses, and runs the unit.

**A site can lower its speed ceiling, never raise it.** Anything the site's settings page can write, the site's own code can write too, so a ceiling the site could raise would be lifted by the hacked plugin it exists to slow. The site's own setting, `outbound_speed_ceiling_mbit` (Settings, Outbound transfer), counts only when it is lower than root's figure. On every run the unit reads it from the site as the web server's user (inside the container, or on the host for a bare-metal site) with `utils/outbound_site_ceiling.php`, and takes only a whole number from what comes back. On bare metal the lowest of the machine's sites holds them all, since they share one ceiling. Every run then tells each site its outcome in `/run/joinery/outbound_limits.site` (on, off, not in force, the ceiling refused, or not covered because the site is not on a network of its own) and the figures in force, and the settings page shows them. To go higher it shows the command to run on the server and what that risks. Where someone else hosts the site, it says to ask them.

The connection figures are not on the settings page. Real use never meets them; an owner who knows why changes them with the flags or `joinery-limits`.

On a machine its management node manages, the node page turns the limits on or off and sets the figures, the machine's or one container site's, through the host agent's `outbound_limits` word. A machine the management node creates is installed with the figures in its provisioning settings, and with `--outbound-set-by=plane`, so its sites' settings pages say the figures are set by whoever hosts them.

Every drop counts against the site that sent it. The host report carries each site's count, and the node page shows a site whose limits dropped anything since the last report in amber, because many dropped connections is how scanning shows. Counts start again from zero when the machine reboots.

## SSL Certificates

SSL is configured automatically when a domain (not localhost or an IP) is provided.

### How it works

1. The installer checks whether the domain's DNS points to this server.
2. If it does, Certbot runs to fetch a Let's Encrypt certificate.
3. If it doesn't, the install goes ahead anyway and no certificate is issued. The vhost guards its `:443` block with `<IfFile>`, so a missing certificate means the site serves HTTP rather than Apache refusing to start.

DNS not being ready never stops an install, and it does not leave you anything to remember either.

#### The retry timer

An install that could not issue a certificate leaves behind a systemd timer, `joinery-ssl-retry@{domain}`, that finishes the job whenever DNS lands — minutes later or a week later. Nothing needs to be run by hand.

Each run resolves the domain first and only invokes Certbot when the A record actually points at this server. That is what makes an open-ended retry safe: Let's Encrypt allows five *failed validations* per hostname per hour, and a DNS lookup that comes back empty costs nothing against that budget. On a CA-issued certificate the timer disables itself and removes its config.

```bash
sudo systemctl list-timers 'joinery-ssl-retry@*'      # is one pending
sudo journalctl -fu joinery-ssl-retry@mysite.example.com   # what it is seeing
```

Its state is a single file per domain at `/etc/joinery/ssl-retry/{domain}.conf`. Delete it to stop the retries.

To issue immediately rather than wait for the next check:

```bash
sudo /var/www/html/{sitename}/maintenance_scripts/sysadmin_tools/setup_ssl.sh mysite.example.com
```

Requirements: domain DNS pointing here, port 80 reachable from the internet, Certbot installed (included in `install.sh server`).

### Bare-metal

Certbot configures Apache directly:

```bash
sudo ./install.sh site mysite mysite.example.com
```

### Docker

The installer adds Apache on the host (if not present), creates a reverse proxy `mysite.example.com → localhost:8080`, then runs Certbot against the proxy:

```bash
sudo ./install.sh site mysite mysite.example.com 8080
```

### Skip SSL

```bash
sudo ./install.sh site mysite mysite.example.com --no-ssl
```

What `--no-ssl` gives you: a site that answers on plain HTTP for its domain. No HTTP→HTTPS redirect exists until a certificate does — the vhost's redirect is gated on the certificate file, so requests carrying the real domain load over HTTP rather than bouncing into a `:443` vhost that isn't there.

### Manual SSL later

```bash
sudo /var/www/html/{sitename}/maintenance_scripts/sysadmin_tools/setup_ssl.sh mysite.example.com
```

Works for both modes — Docker sites terminate TLS at the host's reverse proxy, which is the same Apache the script reloads. It tries an HTTP-01 challenge, falls back to DNS-01 when a provider credential file is present at `/etc/letsencrypt/<provider>.ini`, and leaves the site on HTTP if neither succeeds.

## Cloudflare Proxy Support

The installer detects domains behind Cloudflare's proxy (orange cloud) by matching the resolved IP against Cloudflare's IP ranges, and adapts:

1. Skips Let's Encrypt — Cloudflare provides edge SSL.
2. Creates an HTTP proxy for Docker sites so Cloudflare can reach the origin.

Set the SSL mode in Cloudflare → SSL/TLS:

| Mode             | Browser ↔ Cloudflare | Cloudflare ↔ Origin     | Origin cert        |
|------------------|----------------------|--------------------------|--------------------|
| Flexible         | HTTPS                | HTTP                     | None required      |
| Full             | HTTPS                | HTTPS (any cert)         | Self-signed OK     |
| Full (Strict)    | HTTPS                | HTTPS (valid cert)       | Cloudflare Origin Certificate |

For Full (Strict), generate an Origin Certificate in Cloudflare → SSL/TLS → Origin Server and install it on Apache.

## Themes and Plugins

By default, fresh installs include only the core application. Use `--themes` to download stock themes and plugins from the upgrade server during site creation:

```bash
sudo ./install.sh site mysite mysite.com 8080 --themes
```

To download themes and plugins after the site exists, use `upgrade.php`:

```bash
# Docker
docker exec mysite php /var/www/html/mysite/public_html/utils/upgrade.php

# Bare-metal
php /var/www/html/mysite/public_html/utils/upgrade.php
```

The `--themes` flag uses the same distribution system as `upgrade.php`. See [Deploy and Upgrade](deploy_and_upgrade.md) for the upgrade pipeline.

## Domain Management

Use `manage_domain.sh` (in `maintenance_scripts/sysadmin_tools/`) to add, change, or remove domains on existing sites. Works for both Docker and bare-metal.

```bash
cd maintenance_scripts/sysadmin_tools

# Current state
sudo ./manage_domain.sh status mysite

# Assign a domain (with SSL via Let's Encrypt unless Cloudflare detected)
sudo ./manage_domain.sh set mysite example.com

# Without SSL (e.g. Cloudflare-proxied or testing)
sudo ./manage_domain.sh set mysite example.com --no-ssl

# Revert to IP-only access
sudo ./manage_domain.sh clear mysite

# Restore the previous configuration
sudo ./manage_domain.sh rollback mysite

# Remove SSL only, keep the domain
sudo ./manage_domain.sh remove-ssl mysite
```

For Docker sites, `set` creates an Apache reverse proxy on the host and disables `000-default.conf` so bare-IP requests don't fall through to Ubuntu's welcome page.

## Site Management

### Docker container lifecycle

```bash
docker stop mysite
docker start mysite
docker restart mysite
docker ps --filter "name=mysite"
```

### Logs

```bash
# Docker
docker logs mysite                                       # Startup
docker logs -f mysite                                    # Follow
docker logs --tail 100 mysite                            # Last 100
docker exec mysite tail -100 /var/www/html/mysite/logs/error.log

# Bare-metal
tail -f /var/www/html/mysite/logs/error.log
tail -f /var/log/apache2/access.log
```

### Shell access

```bash
# Docker
docker exec -it mysite bash

# Bare-metal — just use the host shell
cd /var/www/html/mysite/
```

### Apache management

In Docker, **never `service apache2 restart`** — it kills the container. Use `reload` or `graceful`:

```bash
docker exec mysite service apache2 reload
docker exec mysite apache2ctl graceful
docker exec mysite apache2ctl configtest
```

Bare-metal:

```bash
sudo systemctl reload apache2
sudo apache2ctl configtest
```

### PostgreSQL access

A site's database answers only on its own machine. On a standalone server PostgreSQL listens on `localhost`, and `pg_hba.conf` admits local and loopback connections only. In a Docker site the container's PostgreSQL admits its own loopback and the Docker host, which reaches it through the site's database port published on the host's `127.0.0.1`; every other container on the host is refused. `host_housekeeping.sh` holds this on every converge and at every container start: any rule admitting another address is removed (the first rewrite keeps the original as `pg_hba.conf.pre-local-only`), and a standalone server's `listen_addresses` is pinned by `conf.d/99-joinery-local-only.conf`.

There is no exception. A machine that needs a site's data reads it through the site's API, over HTTPS, with a scoped machine key — as ScrollDaddy's DNS resolvers do (`plugins/dns_filtering/docs/overview.md`). A `config/postgres_access.conf` left on a config volume is not read; housekeeping names it in its output until it is removed.

```bash
# Docker (the password is read inside the container, never on the host's command line)
docker exec -it mysite bash -c 'PGPASSWORD="$POSTGRES_PASSWORD" psql -h 127.0.0.1 -U postgres -d mysite'

# Bare-metal
psql -U postgres -d mysite
```

## Maintenance Operations

### Database backup and restore

```bash
# Backup (Docker)
docker exec mysite pg_dump -U postgres mysite | gzip > backup.sql.gz

# Backup (bare-metal)
./maintenance_scripts/sysadmin_tools/backup_database.sh mysite

# Restore (Docker)
gunzip -c backup.sql.gz | docker exec -i mysite psql -U postgres -d mysite

# Restore (bare-metal)
./maintenance_scripts/sysadmin_tools/restore_database.sh mysite backup.sql
```

Run `backup_database.sh` with no database name and it backs up every database on
the machine, except any that a site's config names as its `dbname_test`. A test
database holds no content of its own — it is rebuilt from live on demand — so
backing one up would ship a second encrypted copy of the site's data for
nothing. The skips are listed in the run's output. The match is exact and a
database that is some site's live `dbname` is never skipped, so a separate test
**site** (`{site}_test`, with real content of its own) is always backed up.

### Update application code

**Docker** — stop and re-create the container; volumes persist:

```bash
docker stop mysite && docker rm mysite
tar -xzf joinery-NEW-VERSION.tar.gz
cd maintenance_scripts/install_tools
sudo ./install.sh site mysite mysite.com 8080
```

The container detects this isn't a fresh install and skips initial setup.

**Bare-metal** — use `upgrade.php`:

```bash
php /var/www/html/mysite/public_html/utils/upgrade.php
```

For more detail on the upgrade pipeline, see [Deploy and Upgrade](deploy_and_upgrade.md).

### Run database migrations

```bash
# Docker
docker exec mysite php /var/www/html/mysite/public_html/utils/update_database.php

# Bare-metal
php /var/www/html/mysite/public_html/utils/update_database.php
```

### Remove a site

`remove_account.sh` detects whether the site is Docker or bare-metal and handles both:

```bash
sudo ./maintenance_scripts/sysadmin_tools/remove_account.sh mysite
sudo ./maintenance_scripts/sysadmin_tools/remove_account.sh mysite -y   # No prompt
```

| Docker sites                                  | Bare-metal sites           |
|-----------------------------------------------|----------------------------|
| Docker container                              | Website directories        |
| All Docker volumes (postgres, uploads, etc.)  | Test site directories      |
| Docker image                                  | Apache VirtualHost         |
| Build directory                               | PostgreSQL database        |

## Troubleshooting

### Container won't start

```bash
docker logs mysite
```

Common causes: port already in use (the installer normally detects this and offers alternatives), volume permission issues, or out of disk space.

### Services not running after a host restart

The container's CMD should bring services up automatically. If not:

```bash
docker exec mysite service postgresql start
docker exec mysite service apache2 start
```

### Permission errors (bare-metal)

```bash
sudo ./fix_permissions.sh mysite --production
```

### Database load failure during install

Almost always a syntax error, or a password the shell altered on its way in.

1. If the password was typed on a command line, pass it with `--password-file` or `POSTGRES_PASSWORD=` instead. See [Any character works](#any-character-works).
2. Verify any locally-modified `joinery-install.sql.gz` for SQL syntax.
3. Confirm UTF-8 encoding on the SQL file.

`pg_hba.conf` settings, authentication method, and database user permissions are not the cause — the installer handles all of those.

Debugging:

```bash
docker logs mysite 2>&1 | grep -i "error\|fail"

docker exec -it mysite bash
su postgres -c "psql -d mysite -c '\\dt'"
```

### Composer autoload errors after cloning

The `composerAutoLoad` setting was copied from the source and points to an invalid absolute path. Set it back to the portable relative path:

```bash
# Docker
docker exec -it mysite bash
PGPASSWORD='your_db_password' psql -U postgres -d mysite \
  -c "UPDATE stg_settings SET stg_value = '../vendor/' WHERE stg_name = 'composerAutoLoad';"

# Bare-metal
sudo -u postgres psql -d mysite \
  -c "UPDATE stg_settings SET stg_value = '../vendor/' WHERE stg_name = 'composerAutoLoad';"
```

### Port conflict handling

If the chosen port is in use, the installer shows existing Joinery containers and suggests the next available port pair, then prompts you to accept.

## Script Reference

### `install.sh`

| Subcommand                | Purpose                                       |
|---------------------------|-----------------------------------------------|
| `install.sh docker`       | Install Docker (one-time)                     |
| `install.sh server`       | Set up bare-metal host (one-time)             |
| `install.sh site …`       | Create a new Joinery site                     |
| `install.sh list`         | List existing sites                           |

Global flags:

| Flag         | Description                                                |
|--------------|------------------------------------------------------------|
| `-y`, `--yes`   | Auto-accept all prompts (non-interactive)               |
| `-q`, `--quiet` | Suppress progress output; show errors and final summary |

`install.sh site` options:

```
install.sh [-y] [-q] site [--docker|--bare-metal] SITENAME [DOMAIN] [PORT] [OPTIONS]

  --password-file=FILE   Read database password from file (recommended)
  --activate THEME       Activate this theme after install
  --with-test-site       Create a companion test site (bare-metal only)
  --themes               Download stock themes/plugins from upgrade server
  --no-ssl               Skip automatic SSL setup
  --dormant --copy-of=ID --copy-of-key=KEY
                         The target of a site copy: bare metal, alone on the machine,
                         no certificate attempt, quiet once installed (see below)
```

If no password is given (and no `--password-file`), the installer auto-generates a 24-character password.

`--dormant --copy-of=ID --copy-of-key=KEY` installs the target of a site copy (`specs/site_copy.md`): the site that will be replaced whole by node `ID`'s backups. `KEY` is node `ID`'s agent public key (base64), by which the copy checks every export its source signs. It installs as any bare-metal site does, makes no certificate attempt (the source's certificate travels with the copy), and ends in the quiet state `quiet copy` ([Deploy and Upgrade](deploy_and_upgrade.md), *The quiet state*), with the source's node id and key recorded beside it. The three flags go together. A copy is installed from the source's own release (`utils/latest_release?version=X.Y.Z`), because `vendor/` never travels in a backup; the source's **Copy** tab on its management node shows the whole command. It refuses on a machine that hosts any other site or runs Docker containers, because the quiet state quiets the whole machine, and with `--with-test-site`, `--docker` or a port.

### Supporting scripts

| Script                          | Purpose                                                        | Called by                              |
|---------------------------------|----------------------------------------------------------------|----------------------------------------|
| `_site_init.sh`                 | Internal site initialization (DB, config, Composer)             | `install.sh site`, Dockerfile CMD      |
| `fix_permissions.sh`            | Sets ownership and permissions on site files                    | `_site_init.sh`, manual                |
| `Dockerfile.template`           | Template for building Docker images                             | `install.sh site` (Docker)             |
| `default_Globalvars_site.php`   | Template for site configuration                                 | `_site_init.sh`                        |
| `default_virtualhost.conf`      | Template for Apache VirtualHost                                 | `_site_init.sh`                        |

`_site_init.sh` is internal — don't invoke it directly. Use `install.sh site`.

### Sysadmin tools

Located in `maintenance_scripts/sysadmin_tools/`:

| Script                  | Purpose                                                  |
|-------------------------|----------------------------------------------------------|
| `manage_domain.sh`      | Domain management: `set`, `clear`, `status`, `rollback`, `remove-ssl` |
| `backup_database.sh`    | Backup PostgreSQL database                               |
| `restore_database.sh`   | Restore PostgreSQL database                              |
| `backup_project.sh`     | Full site backup (files + database)                      |
| `restore_project.sh`    | Full site restore                                        |
| `copy_database.sh`      | Copy database between sites                              |
| `remove_account.sh`     | Remove a site completely                                 |

### Reverse proxy for production (multiple Docker sites on 80/443)

For multiple Docker sites sharing standard ports, install Apache on the host:

```bash
apt-get install -y apache2
a2enmod proxy proxy_http headers ssl rewrite
systemctl restart apache2
```

Create `/etc/apache2/sites-available/yoursite.conf`:

```apache
<VirtualHost *:80>
    ServerName yoursite.com
    ServerAlias www.yoursite.com

    ProxyPreserveHost On
    ProxyRequests Off
    ProxyPass / http://127.0.0.1:8080/
    ProxyPassReverse / http://127.0.0.1:8080/

    RequestHeader set X-Real-IP %{REMOTE_ADDR}s
    RequestHeader set X-Forwarded-For %{REMOTE_ADDR}s
    RequestHeader set X-Forwarded-Proto "http"
</VirtualHost>
```

Enable and add SSL:

```bash
a2ensite yoursite
systemctl reload apache2
apt-get install -y certbot python3-certbot-apache
certbot --apache -d yoursite.com -d www.yoursite.com
```

## Related Documentation

- [Deploy and Upgrade](deploy_and_upgrade.md) — Upgrade pipeline and `upgrade.php`
- [Publish/Upgrade System Analysis](publish_upgrade_system_analysis.md) — How upgrade archives are built and distributed
- [Server Manager](/plugins/server_manager/docs/overview.md) — Remote node management and applying upgrades via the admin UI
- [Settings](settings.md) — Configuring a site after installation
