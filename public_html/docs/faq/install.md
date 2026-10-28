# Installing Joinery

## How do I install Joinery on a new server?

If you want a guided path with nothing to type at a command line, follow the walkthrough at https://getjoinery.com/page/quickstart. It covers renting a server, pointing a domain at it, and installing Joinery from a web browser.

From a command line, on a fresh server, run as root:

```bash
mkdir -p /tmp/joinery && \
  curl -sL https://getjoinery.com/utils/latest_release | tar xz -C /tmp/joinery && \
  cd /tmp/joinery/maintenance_scripts/install_tools && \
  sudo ./install.sh server && \
  sudo ./install.sh site mysite example.com
```

`install.sh server` sets up the machine once. `install.sh site` creates the site. Replace `mysite` with a short site name and `example.com` with your domain.

See: docs/installation.md > One-liner install (latest version)

## What kind of server do I need?

- Ubuntu 24.04 or 26.04 LTS, freshly installed. The installer refuses any other operating system.
- Root access.
- At least 1 GB of RAM and 3 GB of disk.
- Ports 80 and 443 free for a bare-metal install, or port 8080 (or another port you choose) for a Docker install.

See: docs/installation.md > Server requirements

## Should I use Docker or install directly on the server (bare-metal)?

Use bare-metal for one site on one machine. A machine carries one bare-metal site.

Use Docker to run several sites on one machine. Each site gets its own container, database, files and port. A container is not a security wall between sites: treat every site on a host, and the host itself, as one trust domain. Put sites that must not reach each other on separate machines.

The installer picks the mode from the command: give a port (`install.sh site mysite example.com 8080`) for Docker, leave it off for bare-metal. Run `install.sh docker` instead of `install.sh server` for the one-time host setup on a Docker host.

See: docs/installation.md > What Docker mode is

## Can I install Joinery without using a terminal?

Yes, on Linode. Choose the Joinery StackScript when you create a Linode and fill in the form: admin email address, admin password and your domain are required. A few minutes later the site is running with SSL and a login.

Optional fields set up email (an email sending API key) and backups (a Backblaze B2 bucket, key ID and key) during the install. If a step fails, the install stops and says so in `/var/log/stackscript.log`. Destroy the instance and deploy again with the field corrected.

See: docs/installation.md > One-click deployment (Linode StackScript)

## What do I sign in with after the install?

The admin account has the username `admin`, which always signs in, and the address you gave with `--admin-email=ADDRESS`, which signs in too. If you left the address out, the account has none until you add one on its profile.

- On a Linode StackScript install, the password is the one you entered in the form, and you keep it.
- On every other install, the installer generates the password and writes it to a credentials file. You are asked to change it at your first sign-in.

After signing in, the setup wizard at `/setup` walks you through the remaining setup steps.

See: docs/installation.md > Create a site

## Why is my new site not on HTTPS yet?

Your domain's DNS probably did not point at the server during the install. The install still finishes, and the site serves plain HTTP until a certificate exists.

You do not need to do anything. The install leaves a timer, `joinery-ssl-retry@{domain}`, that fetches the certificate as soon as the domain points at the server. To fetch it right away once DNS is correct:

```bash
sudo /var/www/html/{sitename}/maintenance_scripts/sysadmin_tools/setup_ssl.sh mysite.example.com
```

This needs requests for the domain to reach the server on port 80, directly or through an edge such as Cloudflare.

Behind Cloudflare, set SSL/TLS to **Full**, not Full (Strict), until the first certificate lands. Strict refuses the temporary certificate the site answers with until then, so the certificate cannot be fetched through it. Once the certificate is in place, Full (Strict) works.

See: docs/installation.md > SSL Certificates; docs/installation.md > Cloudflare Proxy Support

## How do I add or change a site's domain after the install?

Use `manage_domain.sh` in `maintenance_scripts/sysadmin_tools/`, as root. It works for Docker and bare-metal sites; for a Docker site, run it on the host. It sets the site's vhost and its own address, and fetches the certificate the way the install does.

```bash
sudo ./manage_domain.sh status mysite                # what is set today
sudo ./manage_domain.sh set mysite example.com       # assign a domain, with SSL
sudo ./manage_domain.sh set mysite example.com --no-ssl
sudo ./manage_domain.sh rollback mysite              # restore the previous configuration
sudo ./manage_domain.sh clear mysite                 # remove the domain
```

See: docs/installation.md > Domain Management

## I lost the admin password and email is not set up. How do I get back in?

Run the reset tool on the server:

```bash
sudo php /var/www/html/{sitename}/maintenance_scripts/sysadmin_tools/reset_admin_password.php
```

It asks for the new password at a prompt (or reads it from `--password-file=PATH`). With no `--email=ADDRESS` it resets the only superadmin account. You are asked to change the password at the next sign-in. Two-factor sign-in stays on unless you add `--clear-second-factor`.

See: docs/account_security.md > Reset from the server

## What comes installed on a new site, and how do I add more?

A new site has Drive and the personal calendar, plus the `personal` bundle: mail and the AI assistant. Mail needs DNS records and an outbound email provider before it does anything; the assistant needs a model provider.

Everything else (events, commerce, bookings, the password vault, DNS filtering, server management) is installed from `/admin/admin_plugins` when you want it. To find plugins and themes your site does not have yet, use **Add New** on the Plugins or Themes page, which opens the marketplace at `/admin/admin_marketplace`.

See: docs/installation.md > What a new site comes with

## How do I change which theme my site uses?

Go to **System > Themes** (`/admin/admin_themes`). A page theme is the whole site, its pages and their styling: click **Activate** on the one you want. A style theme only changes colors, fonts and images on top of the page theme: **Apply** puts it on and **Remove** takes it off. The same two choices are on the settings page (`/admin/admin_settings`), under the theme settings.

If your account has a vault, a theme change asks you to unlock it first. To get a theme your site does not have yet, use **Add New** on the Themes page.

See: docs/settings.md > System-managed settings

## Can I set up email and backups during the install?

Yes. Pass these to `install.sh site` in the environment:

- `JOINERY_MAIL_API_KEY`: an email provider's sending key. The provider is detected from the key, the From address is derived from the admin address, and the domain is registered with the provider where its API allows.
- `JOINERY_BACKUP_BUCKET`, `JOINERY_BACKUP_KEY_ID` and `JOINERY_BACKUP_KEY`: a backup bucket and its key pair (`JOINERY_BACKUP_PROVIDER` is any provider the Backups page offers, such as `b2`, `linode` or `s3`, default `b2`; `JOINERY_BACKUP_REGION` or `JOINERY_BACKUP_ENDPOINT` where the provider asks for one).

```bash
JOINERY_MAIL_API_KEY=api-… \
JOINERY_BACKUP_BUCKET=joinerybackups JOINERY_BACKUP_KEY_ID=… JOINERY_BACKUP_KEY=… \
sudo -E ./install.sh site mysite mysite.com
```

Neither is required. If one fails, the setup wizard asks for it again. The backup recovery key is always created in the setup wizard, never by the installer.

See: docs/installation.md > Services set up at install
