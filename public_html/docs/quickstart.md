# Quick Start: Deploy Your First Joinery Site

This guide walks you through renting a server, pointing your domain at it, and installing Joinery — from scratch, with no prior experience required. The whole process takes about 15–20 minutes of active work. The one thing that can't be hurried is DNS propagation (more on that in Step 2), which may add anywhere from a few minutes to a few hours of waiting.

If you already have a server and know your way around the command line, see the [full installation reference](installation.md) instead.

---

## Before You Start

You'll need three things:

- **A domain name** — your web address, like `yourdomain.com`. If you don't have one yet, you can buy one from [Namecheap](https://www.namecheap.com), [Cloudflare Registrar](https://www.cloudflare.com/products/registrar/), or Google Domains. You can skip the domain for a quick test, but you'll need one for SSL and real use.
- **A credit card** for the server rental. Expect roughly $5–$12/month depending on the plan you choose.
- **A computer** with internet access. You'll open a terminal window in Step 3 — instructions are provided for Mac, Windows, and Linux.

---

## Step 1 — Rent a Server

Joinery runs on a **VPS** (Virtual Private Server) — a Linux computer you rent by the hour that lives in a data center. You don't need to manage hardware; you just connect to it over the internet.

We recommend **Linode** (now part of Akamai Cloud). It's reliable, straightforward to use, and competitively priced.

[Sign up for Linode using our referral link](https://www.linode.com/lp/refer/?r=f89d0c9308eeef26368cc67356eb8fa81365d488) and you'll receive $100 of credit to use over your first 60 days.

Once you have a Linode account, follow these steps to create your server:

1. Log in and click **Create → Linode**.
2. **Choose an image:** Select **Ubuntu 24.04 LTS** or **Ubuntu 26.04 LTS**. This is the operating system your server will run — the installer stops on any other version.
3. **Choose a region:** Pick a data center geographically close to your expected users. Any region works.
4. **Choose a plan:** The **Nanode 1 GB** ($5/month) is sufficient for a small site. If you expect real users from day one, the **Linode 2 GB** ($12/month) gives more breathing room. You can resize later if needed.
5. **Set a root password:** This is the master password for your server. Use a strong, random password and save it in a password manager. Avoid these characters, which can cause problems during installation: `'  "  \  $  `  !`
6. Click **Create Linode** and wait 60–90 seconds for the server to boot.
7. **Note the IP address** displayed on the Linode dashboard — a number like `123.45.67.89`. You'll need it in the next two steps.

---

## Step 2 — Point Your Domain at the Server

Do this step now, before installing, because DNS changes can take anywhere from a few minutes to a few hours to propagate. Starting early means the installer will likely be able to set up SSL automatically when you get there.

**DNS** (Domain Name System) is what translates `yourdomain.com` into an IP address so browsers know which server to connect to. You configure it by adding an **A record** — a simple entry that says "requests for this domain should go to this IP."

**How to add an A record:**

1. Log in to wherever you bought your domain (Namecheap, Cloudflare, etc.).
2. Find the DNS settings — usually labelled DNS Management, DNS Zone, or Nameservers.
3. Add a new **A record** with these values:
   - **Name / Host:** `@` — this means the root domain (`yourdomain.com`). Optionally add a second A record with name `www` pointing to the same IP, so both `yourdomain.com` and `www.yourdomain.com` work.
   - **Value / Points to:** your Linode IP address (from Step 1)
   - **TTL:** 300 seconds (or the minimum your registrar allows)
4. Save the record.

**If you're using Cloudflare:** the installer detects Cloudflare automatically. For your first deployment, set the proxy to **DNS only** (grey cloud icon) to keep things straightforward. See the [Cloudflare section of the installation docs](installation.md#cloudflare-proxy-support) if you want to enable it later.

---

## Step 3 — Connect to Your Server

**SSH** (Secure Shell) is an encrypted connection to your server's command line — like a remote keyboard for your Linux machine. You'll use it to run the installer.

### Mac or Linux

1. Open **Terminal**. On Mac: press `Cmd+Space`, type `Terminal`, press Enter. On Linux: press `Ctrl+Alt+T`.
2. Type the following command, replacing `123.45.67.89` with your actual IP address:
   ```
   ssh root@123.45.67.89
   ```
3. The first time you connect, you'll see a message asking you to confirm the server's identity. Type `yes` and press Enter.
4. Enter your root password when prompted (the one you set in Step 1). Characters won't appear as you type — that's normal for password fields in terminals.

### Windows

1. Open **Windows Terminal** (search for it in the Start menu; it's pre-installed on Windows 11 and available free from the Microsoft Store on Windows 10).
2. Follow the same steps as Mac/Linux above.

When connected, you'll see a prompt that looks something like `root@localhost:~#`. You're in.

---

## Step 4 — Install Joinery

Copy the command below, replace `yourdomain.com` with your actual domain, then paste it into your SSH session and press Enter. The installer will take care of everything — PHP, Apache, PostgreSQL, SSL, and the Joinery application itself.

```bash
mkdir -p /tmp/joinery && \
  curl -sL https://getjoinery.com/utils/latest_release | tar xz -C /tmp/joinery && \
  cd /tmp/joinery/maintenance_scripts/install_tools && \
  sudo ./install.sh server && \
  sudo ./install.sh site mysite yourdomain.com --admin-email=you@example.com
```

Put your own address in `--admin-email`. That becomes the login for the admin account, and it's how you get back in if you ever lose the password — leave it off and the account is created as `admin@example.com`, which you'd have to change by hand.

If you don't have a domain yet, substitute your server's IP address for `yourdomain.com`. The site will work, but you won't get SSL until you add a domain later.

**Running it from a script** (cloud-init, CI, a provisioning tool — anywhere nobody is at a keyboard): put `-y` before the subcommand and supply the database password in the environment, since there is no terminal to prompt on:

```bash
sudo POSTGRES_PASSWORD='choose-one' ./install.sh -y server && \
  sudo ./install.sh -y site mysite yourdomain.com --admin-email=you@example.com
```

With `-y`, every prompt takes its default; the destructive ones (overwriting an existing site, deleting data volumes) always refuse unless their own flags — `--wipe-data`, `--allow-downgrade` — say otherwise.

**`--no-ssl`** on the `site` command skips certificate setup entirely. What you get is a site that answers on plain HTTP for its domain — nothing redirects to HTTPS until a certificate exists. Add one later with `setup_ssl.sh` (below).

The install takes a few minutes. You'll see output scrolling past — that's normal. When it finishes, your site is live, with Drive, Calendar, mail and the AI assistant already installed.

**If DNS hasn't propagated yet:** the SSL step is skipped automatically and the install continues. Your site is reachable over HTTP in the meantime, and a background timer watches for your domain — once it points at this server, a certificate is issued within a few minutes with nothing for you to do.

To watch that happen:

```bash
sudo journalctl -fu joinery-ssl-retry@yourdomain.com
```

To issue one immediately instead of waiting for the next check:

```bash
sudo /var/www/html/yoursite/maintenance_scripts/sysadmin_tools/setup_ssl.sh yourdomain.com
```

**If you see an error:** the most common cause is a typo in the command. Check that `yourdomain.com` was replaced correctly. For other issues, see the [Troubleshooting section](installation.md#troubleshooting) in the full installation docs.

---

## Step 5 — Log In

Open a browser and go to:

```
https://yourdomain.com/admin
```

(Use `http://` if SSL was skipped temporarily.)

Log in as:

- **Email:** the address you passed to `--admin-email` (or `admin@example.com` if you left it off)
- **Password:** the one the installer printed when it finished

Every site gets its own admin password — there is no shared default. If you've lost the line the installer printed, it's also saved on the server at `/var/www/html/yoursite/config/admin_credentials.txt` (readable by root only):

```bash
sudo cat /var/www/html/yoursite/config/admin_credentials.txt
```

You'll be asked to set a new password immediately. Do that first — use something strong and save it somewhere safe, then delete the credentials file.

**If you've lost the password entirely,** reset it from the server:

```bash
sudo php /var/www/html/yoursite/maintenance_scripts/sysadmin_tools/reset_admin_password.php
```

Once you're in, two housekeeping items before anything else:

1. **Set up email.** Go to `/admin/admin_settings_email` and name a provider. Do this first: password reset is the only way back into your account once the installer's password is gone, and it needs somewhere to send from. A new site has no provider configured, and most hosts — Linode included — block outbound port 25, so running a mail server on this machine will not deliver.
2. **Set your site name.** Go to `/admin/admin_settings` and update the Site Name field.

If you installed without `--admin-email`, also change the admin address at `/admin/admin_user?usr_user_id=1`.

---

## What's Next

- **Install a theme** — `/admin/admin_themes`
- **Install more plugins** — `/admin/admin_plugins`. A fresh install comes with mail and the AI assistant; events, commerce and the password vault are there to add.
- **Set up your mailbox** — the mail plugin needs MX and DKIM records you control before it can receive
- **Give the AI assistant a provider** — `/admin/admin_settings` under AI
- **Full installation reference** — for multi-site setups, site cloning, manual SSL, and advanced configuration, see [Installation](installation.md)
