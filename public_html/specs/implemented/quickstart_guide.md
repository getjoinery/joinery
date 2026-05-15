# Quick Start Guide (Spec)

## Purpose

Create a new document, `docs/quickstart.md`, that walks a complete beginner through deploying their first Joinery site — from zero to a working, SSL-secured admin login. This is distinct from the existing `docs/installation.md`, which is a comprehensive reference for developers and sysadmins.

## Target Audience

Someone who:
- Has never administered a Linux server
- Has a domain name (or can register one)
- Is not familiar with SSH, DNS records, or command lines
- May have heard terms like "VPS" but doesn't really know what they mean

The tone should be reassuring and explanatory. Every term that might be unfamiliar should be briefly defined where it is first used.

## Scope

**In scope:**
- Renting a Linode VPS (step-by-step with screenshots described)
- Connecting to the server via SSH (Mac/Linux terminal and Windows)
- Pointing a domain at the server (A record, briefly)
- Running the Joinery installer
- Logging into the admin panel for the first time

**Out of scope:**
- Multi-site setups
- Site cloning
- Themes and plugins
- Advanced SSL options

## Document Location

`/docs/quickstart.md` — linked from `docs/installation.md` at the top as a "New? Start here" callout, and linked from the documentation index.

---

## Section-by-Section Outline

### Introduction

Two or three sentences explaining what this guide does. State the expected total time (roughly 15–20 minutes once DNS propagates). Note that DNS propagation is the one thing that can't be hurried and may take a few minutes to a few hours.

---

### Before You Start

A short checklist of what you need:

- A **domain name** — a web address like `yourdomain.com`. Explain where to buy one (Namecheap, Google Domains, Cloudflare Registrar). Note you do not need one to test, but you'll need one for SSL and real use.
- A **credit card** for the server rental (~$5–$12/month depending on plan).
- A computer with internet access and a terminal (explained in the SSH step).

---

### Step 1 — Rent a Server (Linode)

Explain what a VPS (Virtual Private Server) is in one sentence: a Linux computer you rent by the hour that lives in a data center.

**Recommended provider: Linode (now Akamai Cloud).** Display a prominent callout box or highlighted section — not just an inline link — with the affiliate URL `https://www.linode.com/lp/refer/?r=f89d0c9308eeef26368cc67356eb8fa81365d488`. The callout must make the $100 credit offer visually prominent, e.g.:

> **Get $100 in free credit** — Sign up for Linode using our link and you'll receive $100 of credit to use over your first 60 days. That's enough to run your server for months while you get started.
> [Sign up for Linode →](https://www.linode.com/lp/refer/?r=f89d0c9308eeef26368cc67356eb8fa81365d488)

A brief disclosure is fine ("We earn a small referral fee — it doesn't affect your price"). The credit offer and the link must both appear in this callout; neither should be buried in body text.

Walk through:

1. Click the affiliate link above to go to Linode and create an account.
2. Click **Create → Linode**.
3. **Choose an image:** Select **Ubuntu 24.04 LTS**. Explain that this is the operating system — Joinery requires this exact version. The installer will warn and continue on other Ubuntu versions, but they are not supported.
4. **Choose a region:** Pick a data center geographically close to your expected users. Any region works.
5. **Choose a plan:** The **Nanode 1 GB** ($5/month) is sufficient for a small site. Recommend **Linode 2 GB** ($12/month) for anything with real users, because it gives more breathing room. Note that you can resize later.
6. **Set a root password:** This is the master password for your server. Use a strong, random password and save it in a password manager. Note forbidden characters (single quote, backslash, dollar sign) that can cause problems — link to the forbidden characters table in `installation.md`.
7. **Click Create Linode** and wait 60–90 seconds for the server to boot.
8. **Note the IP address** shown on the Linode dashboard. You will need it in the next two steps.

---

### Step 2 — Point Your Domain at the Server

Do this now — before installing — because DNS changes can take a few minutes to a few hours to take effect. Getting this started early means SSL setup will likely work when the installer runs.

Explain what DNS is in one sentence: it's the system that translates `yourdomain.com` into an IP address so browsers know which server to connect to.

**How to add an A record:**

- Log in to wherever you bought your domain.
- Find the DNS settings (usually called DNS Management, DNS Zone, or Nameservers).
- Add an **A record** with:
  - **Name / Host:** `@` (means the root domain, i.e., `yourdomain.com`). Optionally add a second A record with name `www` pointing to the same IP.
  - **Value / Points to:** your Linode IP address
  - **TTL:** 300 (or whatever the minimum is)
- Save the record.

Note: If using Cloudflare, you can proxy (orange cloud) or not. The installer auto-detects Cloudflare. For a first deployment, turning the proxy **off** (grey cloud, DNS-only) is simpler. Link to the Cloudflare section of `installation.md` for more detail.

---

### Step 3 — Connect to Your Server (SSH)

Explain what SSH is in one or two sentences: it's an encrypted connection to your server's command line — like a remote keyboard for your Linux machine.

**Mac or Linux:**

1. Open Terminal (Mac: press `Cmd+Space`, type `Terminal`, press Enter; Linux: press `Ctrl+Alt+T`).
2. Type the following and press Enter, replacing `123.45.67.89` with your server's IP:
   ```
   ssh root@123.45.67.89
   ```
3. The first time you connect, you'll be asked to confirm the server's fingerprint. Type `yes` and press Enter.
4. Enter your root password when prompted (the one you set on Linode). Characters won't appear as you type — that's normal.

**Windows:**

1. Open **Windows Terminal** (search for it in the Start menu; it is pre-installed on Windows 11 and available free from the Microsoft Store for Windows 10).
2. Follow the same steps as Mac/Linux above.

When connected, you will see a prompt like `root@localhost:~#`. You're in.

---

### Step 4 — Install Joinery

Paste this command into your SSH session and press Enter. The installer will download and configure everything automatically.

```bash
mkdir -p /tmp/joinery && \
  curl -sL https://joinerytest.site/utils/latest_release | tar xz -C /tmp/joinery && \
  cd /tmp/joinery/maintenance_scripts/install_tools && \
  sudo ./install.sh server && \
  sudo ./install.sh site mysite yourdomain.com
```

**Before running:** replace `yourdomain.com` with your actual domain. If you don't have a domain yet, you can use your server's IP address in place of the domain — you just won't get SSL.

**What this does:** Downloads the latest Joinery release, installs PHP, Apache, PostgreSQL, and all dependencies, then creates a site called `mysite` at your domain.

**What to expect during the install — two passwords:**

The installer will pause twice for passwords. Have your password manager open before you paste the command.

1. **PostgreSQL master password (you choose this).** During the server-setup step, the installer will pause and prompt:
   ```
   Please enter a password for PostgreSQL postgres user:
   Confirm password:
   ```
   Pick a strong, random password and save it in your password manager. Characters won't appear as you type. Avoid these characters, which break shell escaping: single quote (`'`), backslash (`\`), and dollar sign (`$`) — see the [forbidden characters table](installation.md#forbidden-characters) for the full list.

2. **Per-site database password (auto-generated, displayed once).** During the site-creation step, the installer will print a highlighted yellow block that looks like:
   ```
   ═══════════════════════════════════════════════════════════════
     IMPORTANT: Save this auto-generated password!
     Database Password: <a long random string>
   ═══════════════════════════════════════════════════════════════
   ```
   Copy that password into your password manager immediately. It is shown only once. You will not need it for normal admin use, but you will need it if you ever connect to the database directly or restore from a backup.

**If DNS hasn't propagated yet:** the SSL step may be skipped automatically. The installer will print instructions for running Certbot manually once DNS is ready. You can also run the domain management script later — see `installation.md`.

**If you see errors:** The most common cause is a typo in the command. Check the [Troubleshooting section](installation.md#troubleshooting) of the full installation docs.

---

### Step 5 — Log In

Open a browser and go to:

```
https://yourdomain.com/admin
```

(Or `http://yourdomain.com/admin` if SSL was skipped temporarily.)

Log in with the default credentials:

- **Email:** `admin@example.com`
- **Password:** `changeme123`

You will be prompted to set a new password immediately — do this before anything else. Once you've changed it, you're in.

Note: also prompt them to update their admin email address from `admin@example.com` to their real address — this is in the user profile or admin settings.

---

### What's Next

Short section with links:

- **Change your admin password** — Settings → Users, find your account, click Edit.
- **Install a theme** — visit `/admin/admin_themes` and upload or activate a theme.
- **Install plugins** — visit `/admin/admin_plugins`.
- **Configure your site name and email** — `/admin/admin_settings`.
- **Full installation reference** — for multi-site, cloning, and advanced SSL, see [Installation](installation.md).

---

## Tone and Style Notes

- Never say "simply" or "just" — if something were simple, we wouldn't need to explain it.
- Explain every acronym on first use: VPS, SSH, DNS, SSL, etc.
- Use imperative steps ("Click Create", "Type the following") rather than passive descriptions.
- Every code block should be copy-pasteable, with no placeholders that look like code (use descriptive phrases like `yourdomain.com` as a placeholder, not `<YOUR_DOMAIN>`).
- If a step can go wrong, briefly say what the most common error looks like and what to do.

---

## Related Docs to Update

- `docs/installation.md` — add a "New? Start with the [Quick Start guide](quickstart.md)" callout at the top, above the Table of Contents.
- `docs/index.md` — add a link to the Quick Start guide, prominently near the top.
