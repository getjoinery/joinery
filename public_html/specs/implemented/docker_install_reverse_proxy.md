# Docker Install: Auto-provision Reverse Proxy

## Problem

A Server Manager one-click install in Docker mode leaves the site reachable only on the host's high-numbered port (default `8080`). Port 80 has nothing listening. A new operator reasonably expects `http://HOSTNAME/` to work after a successful install and is confused when it doesn't.

Today the install.sh docker path *does* include a proxy setup function (`setup_ssl_docker_proxy`) that installs Apache + mod_proxy and writes a `{sitename}-proxy.conf` vhost. It is gated on `should_setup_ssl()`, which `JobCommandBuilder::build_install_node()` disables by always passing `--no-ssl`. `--no-ssl` is passed because without it `install.sh` hard-fails when DNS isn't already pointing at the target. The HTTP proxy step is collateral damage of that gating — it shouldn't require SSL, but today it does.

## Desired behavior

After a Docker install via the Server Manager UI, port 80 on the host serves the new site (HTTP, no SSL). Apache on the host is installed and configured if missing; existing Apache installations are left alone except for adding a new site-specific proxy vhost. Re-running the install against the same host is idempotent.

SSL is explicitly out of scope here — it stays a separate, admin-triggered step once DNS is pointed at the host. Spec'ing the UI for that is a follow-up.

## Approach

Add a new step in `JobCommandBuilder::build_install_node()` after site creation, executed only when `deployment_mode === 'docker'`:

```
Step: Set up HTTP reverse proxy
cmd: sudo bash /var/www/html/{sitename}/maintenance_scripts/sysadmin_tools/manage_domain.sh set {sitename} {domain} --no-ssl
```

`manage_domain.sh set_domain_docker` already does exactly what we need and is used in production on `docker-prod` for every site:

- `ensure_apache_on_host` — installs `apache2` if missing, enables `proxy` + `proxy_http` modules if not enabled.
- Derives the container's mapped host port via `docker port {sitename} 80/tcp`.
- Writes `/etc/apache2/sites-available/{sitename}-proxy.conf` with a minimal HTTP `<VirtualHost *:80>` that `ProxyPass /` to `http://127.0.0.1:{port}/`.
- `a2ensite` + `apachectl configtest` + `systemctl reload apache2`.

It is idempotent by construction: re-running overwrites the same config file, re-enables the same site, and reloads Apache.

The script lives on the target once `install.sh site` has deployed `maintenance_scripts/` into `/var/www/html/{sitename}/`, so no extra artifact delivery is needed.

## Skip conditions

Skip the proxy step when:

- `deployment_mode !== 'docker'` — bare-metal already serves on port 80 via `default_virtualhost.conf`.
- The domain field is empty, `localhost`, or a bare IP — a proxy needs a `ServerName` that can route by Host header. (The install form already requires a domain, so this is defensive.)

## Failure handling

The step is marked `continue_on_error: false`. A failure here (e.g. port 80 bound by something else, Apache config test fails) is a genuine install problem the admin needs to see. `mjb_output` captures the script's output, and the existing JobResultProcessor will move `mgn_install_state` to `install_failed` on the failed step, matching current behavior for other install failures.

## UI copy update

The install form currently says:

> Apache vhost is configured for this domain. The site is installed with `--no-ssl` (install.sh hard-fails if DNS isn't already pointing here). After DNS cutover, run `sudo certbot --apache -d DOMAIN` on the target.

Update to:

> An HTTP reverse proxy on port 80 is configured automatically so the site is reachable at `http://DOMAIN/` once DNS points here. SSL is not set up at install time — after DNS cutover, run `sudo certbot --apache -d DOMAIN` on the target to add it.

Drop the misleading "Apache vhost is configured for this domain" — in Docker mode the vhost inside the container listens on the container's port, not the host's port 80.

## Why not extend install.sh?

Two reasons.

First, `setup_ssl_docker_proxy()` is tangled with SSL, DNS, and Cloudflare branches. Pulling the HTTP-only path out cleanly means refactoring the 108-line function and its `should_setup_ssl` gate. `manage_domain.sh` already has the clean split.

Second, `manage_domain.sh` is the script documented for domain/SSL lifecycle after install. Using it during install means one canonical path for "configure proxy for a Docker site", exercised by every install in addition to its existing post-install use.

## Files touched

- `plugins/server_manager/includes/JobCommandBuilder.php` — add the reverse-proxy step in the Docker branch of `build_install_node`.
- `plugins/server_manager/views/admin/install_node_form.php` — update the Primary Domain help text.
- `maintenance_scripts/sysadmin_tools/manage_domain.sh` — `set_domain_docker` also disables `000-default.conf` (Apache welcome vhost) so bare-IP requests fall through to the site's proxy instead of the Ubuntu default page. Idempotent.
- `docs/server_manager.md` — one-line mention in the Docker install step list.

## Validation

Re-test the existing UI install flow against a bare Ubuntu 24.04 host (rebuild `104.237.145.176`):

1. Install completes without errors; new step `Set up HTTP reverse proxy` logs `OK` messages.
2. `curl -sI http://104.237.145.176/` returns 200 (first/only vhost matches any Host header).
3. `curl -sI -H 'Host: testvps.joinerytest.site' http://104.237.145.176/` returns 200.
4. `ls /etc/apache2/sites-enabled/testvps-proxy.conf` exists on target.
5. Re-running install on the same host (after volume teardown) leaves Apache running and reconfigures the vhost without error.

## Follow-ups (out of scope)

- Add a "Enable SSL" action on the node detail page that runs `sudo certbot --apache -d DOMAIN` via the agent, gated on a DNS-points-here check.
- Teach install.sh's docker path to run composer install as a Dockerfile `RUN` step so `vendor/` lives in the image layer, not a first-boot `_site_init.sh` step. Today, removing a container without also removing the `{sitename}_config` volume leaves the new container with an old `Globalvars_site.php`, which makes the init script short-circuit and skip composer install. Not triggered by the UI flow under normal use, but it bit manual teardown testing.
