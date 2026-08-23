# Quick Start: Your First Joinery Site

This guide gets you from nothing to a running Joinery site — no technical experience needed, and nothing to type into a command line. You'll do everything by filling in forms in a web browser. Expect about 15 minutes of active work, plus a short wait while your site installs itself.

If you're comfortable at a command line, or you already have a server you want to install on, use the [full installation reference](installation.md) instead — it covers manual installs, multi-site setups, and every option in detail.

---

## How This Works

Joinery is **self-hosted**: your site runs on a computer *you* control, not on ours. Your files, your email, your data — all of it lives on your own machine. That's the whole point.

You almost certainly don't want to run a physical computer at home for this, so instead you'll rent a small computer in a data center — called a **VPS** (virtual private server) — for about the price of a coffee each month. You never see the hardware; you just click a few buttons and it exists.

Joinery has a one-click installer on **Linode** (part of Akamai Cloud), a well-established server rental company. You fill in a short form, click Create, and a few minutes later your site is running with a login you chose. That's the path this guide follows.

---

## Before You Start

You'll need three things:

### 1. A domain name

A domain is your address on the internet — `yourname.com`. Your site needs one for three reasons:

- It's how people (and you) find your site. Without one, your site only answers at a raw number like `123.45.67.89`.
- The padlock in the browser — **HTTPS**, the thing that keeps logins and passwords encrypted — can only be issued to a domain, never to a bare number.
- Every link your site creates, and every email address it will eventually handle, is built from your domain.

**Why can't Joinery register one for you?** Domains are rented year-by-year from companies called **registrars**, and the registration is in the owner's name. Your domain should be registered to *you* — it's the deed to your address. If you own it, your site can never be taken from you, and you can move it anywhere, anytime. So this is the one piece you have to buy yourself.

It takes about five minutes and costs roughly $10–15 a year. [Namecheap](https://www.namecheap.com) is the simplest place to do it. Pick any name you like that's available.

### 2. A credit card

For the server rental. Expect **$5/month**. Billing is hourly, so experimenting costs pennies — if you make a mistake, you can throw the server away and start over for less than a dime.

### 3. A free Mailgun account

Your server can't deliver email by itself: server rental companies — Linode included — block servers from sending mail directly, an anti-spam measure across the whole industry. So every self-hosted site hands its outgoing mail to a delivery service. The one this guide assumes is **Mailgun**, whose free account gives you 100 free emails per day — plenty for a personal or small-group site.

[Sign up at Mailgun](https://www.mailgun.com) now. That's all you need to do for the moment — after your site is running, its setup wizard will ask you for a Mailgun **API key** and show you exactly where to find it.

---

## Step 1 — Create a Linode Account

[Sign up for Linode using our referral link](https://www.linode.com/lp/refer/?r=f89d0c9308eeef26368cc67356eb8fa81365d488) and you'll receive **$100 of credit** to use over your first 60 days — more than enough to run your site free for the first two months.

You'll need to verify your email and add a payment card. Once you can see the Linode dashboard, you're ready.

---

## Step 2 — Create Your Server

Open the Joinery one-click installer:

**[https://cloud.linode.com/stackscripts/2185451](https://cloud.linode.com/stackscripts/2185451)**

Click **Deploy New Linode**. A form opens. Here's every field and what it means:

### The Joinery fields (top of the form)

- **Admin email address** — the email you'll use to log in to your site. Use a real address you check: it's also how you recover your account if you ever forget your password.
- **Admin password** — the password you'll use to log in to your site. Choose a strong one and save it in a password manager. (You'll be asked to set a fresh one the first time you log in — a routine precaution.)
- **Site domain** — the domain you bought, like `yourname.com`. Type it exactly, with no `www` and no `https://`. Don't make one up — it must be a domain you actually own, because in the next step you'll connect it to this server.
- **SSH public key** *(optional)* — a way for technical users to open a command line on the server. If you don't know what this is, **leave it blank**. Everything in this guide works without it.
- **Linode API token** *(optional)* — only useful if your domain's DNS is managed *at Linode*. If it is, provide a token and the installer connects your domain for you, letting you skip Step 3. If you bought your domain at Namecheap, Cloudflare, or anywhere else, **leave it blank**.

### The server fields (rest of the form)

- **Image** — the server's operating system. Only the version Joinery supports is offered, so just leave it as is.
- **Region** — where in the world your server physically lives. Pick a city near you or your visitors. Any choice works.
- **Linode Plan** — the size of the server. Click the **Shared CPU** tab and choose the **Nanode 1 GB** ($5/month). It's plenty to start with, and you can upgrade to a bigger size later without reinstalling.
- **Linode Label** — a nickname for the server in your Linode dashboard. Anything works; `joinery` is fine.
- **Root Password** — this one is **not** your website login. It's the master password for the rented machine itself, and Linode requires you to set one. You'll probably never type it again, but save it in your password manager anyway. Stick to letters, numbers, and simple punctuation — avoid quotes, backslashes, dollar signs, backticks, and exclamation marks, which can confuse the setup process.

Click **Create Linode**.

Your server now boots up and installs Joinery entirely on its own — the web server, the database, the application, everything. This takes about **5–10 minutes**. While it works, do Step 3.

---

## Step 3 — Connect Your Domain to Your Server

*(Skip this step if you gave a Linode API token in Step 2 and your DNS is hosted at Linode — it's already done.)*

Right now your domain and your server don't know about each other. Connecting them is one small edit called an **A record** — an entry in your domain's settings that says "send visitors for this name to this server."

The reason this comes *after* creating the server: the server's address doesn't exist until the server does.

1. **Find your server's IP address.** On the Linode dashboard, click your new server. Its **IP address** — four numbers separated by dots, like `123.45.67.89` — is shown near the top. Copy it.
2. **Log in where you bought your domain** (e.g. Namecheap) and find the DNS settings — usually labelled **DNS**, **DNS Management**, or **DNS Zone**.
3. **Add an A record:**
   - **Name / Host:** `@` (this symbol means "the domain itself")
   - **Value / Points to:** your server's IP address
   - **TTL:** the smallest value offered (often 300 or "5 min")
4. Optionally add a second A record with name `www` pointing to the same IP, so `www.yourname.com` works too.
5. Save.

**Using Cloudflare?** For your first setup, set the record to **DNS only** (the grey cloud icon, not the orange one). You can turn the orange cloud on later once everything works.

DNS changes take time to spread across the internet — usually minutes, occasionally a few hours. **You don't have to watch it.** The install continues without waiting for DNS: your server checks every few minutes on its own, and the moment your domain points at it, it fetches its HTTPS certificate automatically. There is nothing for you to do.

---

## Step 4 — Log In

Give the install 5–10 minutes from when you clicked Create, then open a browser and go to:

```
https://yourname.com/admin
```

If the padlock isn't ready yet (DNS still spreading), `http://yourname.com/admin` works in the meantime — the secure version switches on by itself shortly after your domain connects.

Log in with the **admin email and admin password you chose on the form** in Step 2.

You'll be asked to set a new password right away. Do it, and save the new one in your password manager.

---

## Step 5 — Walk Through the Setup Wizard

The first time you log in, your site opens its **setup wizard** — a checklist that walks you through everything a new site needs: your name and the site's name, sign-in security, your personal encryption key, email, calendar, and backups. Each step explains itself, and you can leave and come back at any point.

Go through it in order. The step that matters most is **Email**:

- **Choose your email address** — something like `you@yourname.com`. This becomes the address your site sends from *and* a real mailbox on your site: mail sent to it arrives right there.
- **Paste your Mailgun API key** — from the Mailgun account you created earlier. The wizard shows you exactly where in Mailgun to find it, and registers your domain with Mailgun for you.
- **Add the DNS records it shows** — a handful of entries that prove to the world your domain sends and receives mail here. They go in the same place you added the A record in Step 3. If your DNS host has an open API, the wizard can add them all for you; otherwise it lists them to copy and paste.
- **Confirm the test email** — the wizard sends you a message, and when it arrives, email is proven working end to end.

Don't skip the email step. If you ever forget your password, a reset email is the way back in — and a brand-new site has no way to send one until this is done.

---

## What You Have Now

Your site comes ready with:

- **Drive** — file storage, like your own private Dropbox
- **Calendar** — events, reminders, and imports from other calendars
- **Mail** — a full mailbox on your own domain. The address you chose in the setup wizard sends and receives here; more addresses can be added from the mail admin.
- **AI assistant** — installed, and needs one thing from you: an API key from an AI provider (such as Anthropic or OpenAI). The setup wizard asks for it, or add it later under **Settings → AI**. You create that key in an account *you* own, so your AI usage is billed to you directly and never passes through anyone else.

More is available under **Admin → Plugins** — events, an online store, and others — each a click to add.

---

## If Something Goes Wrong

- **The site never appears** after 15 minutes: the most common cause is a typo in the domain field. The cheapest fix is also the cleanest one — on the Linode dashboard, **delete the server** (Settings → Delete) and repeat Step 2 with the field corrected. It costs a few cents and takes ten minutes.
- **The padlock / HTTPS isn't working** but the site loads over `http://`: DNS just hasn't finished spreading. Check that your A record from Step 3 has the right IP address, then wait — the certificate is fetched automatically once the domain connects. If you'd rather not wait for the next automatic attempt, SSH in and run the `setup_ssl.sh` command the install printed in its closing summary (it lives under `maintenance_scripts/sysadmin_tools/setup_ssl.sh` in your install directory) — it fetches the certificate immediately.
- **You can't log in**: make sure you're using the *admin email and password* from the form (not the root password), and that you're at `/admin`.

For anything deeper, the [full installation reference](installation.md#troubleshooting) has a troubleshooting section.
