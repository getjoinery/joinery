# Quick Start: Your First Joinery Site

This guide gets you from nothing to a running Joinery site — no technical experience needed, and nothing to type into a command line. You'll do everything by filling in forms in a web browser. Expect about 30 minutes of active work, plus a short wait while your site installs itself.

If you're comfortable at a command line, or you already have a server you want to install on, the [full installation reference](installation.md) is available — it covers manual installs, multi-site setups, and every option in detail.

---

## How This Works

Joinery is **self-hosted**: your site runs on a computer *you* control, not on ours. Your files, your email, your data — all of it lives on your own machine. That's the whole point.

You probably don't want to run a physical computer at home for this, so instead you'll rent a small computer in a data center — called a **VPS** (virtual private server) — for about the price of a coffee each month. You never see the hardware; you just click a few buttons and it exists.

Joinery has a one-click installer on **Linode** (part of Akamai Cloud), a well-established server rental company. You fill in a short form, click Create, and a few minutes later your site is running with a login you chose. That's the path this guide follows.

---

## Step 1 — Purchase a Domain name

A domain is your address on the internet, and is the part after the @ in your email address.  

You're probably used to yourname@gmail.com or @yahoo.com.  Joinery was created so these companies no longer own your email address.  

[Namecheap](https://www.namecheap.com) is what we recommend, but you can use any service you like.  It takes about five minutes and costs roughly $10–15 a year.

Once you've purchased one, you need to point it to where we will get your server next.  

**ONLY DO THIS IF THIS IS A NEW OR UNCONFIGURED DOMAIN.  IT WILL ERASE WHATEVER ENTRIES ARE THERE**

1. **Go to the Namecheap dashboard** and click "Manage" next to your domain.
   ![The Namecheap domain list, with the Manage button beside the domain circled](/assets/images/docs/namecheap-dashboard.png)
2. Find the section labeled **Nameservers**, choose **Custom** and set your two nameservers to **ns1.linode.com** and **ns2.linode.com**.
   ![The domain page scrolled to Nameservers, set to Custom DNS with ns1.linode.com and ns2.linode.com filled in](/assets/images/docs/namecheap-dns-move.png)
3. Click the green checkmark.

---
## Step 2 — Create an account at an email sending service

Running your own sending server is not recommended.  It's complicated and time consuming.  

Instead, use an email sending service (we recommend [SMTP2GO](https://www.smtp2go.com).  An account is free, and the free plan provides 1000 sent emails per month, which is plenty for most people.  

1. Find the **API Keys** menu item under **Sending** on the left.
   ![The SMTP2GO API Keys page, with Sending and API Keys circled in the left menu and the Add API Key button circled at the top right](/assets/images/docs/smtp2go-apikey.png)
2. Click the **Add API Key** button and give the key a name — anything; `Joinery` is fine.
3. Check the key's **Permissions** before you save. Turn on two groups:
   **Emails** and **Sender Domains**. 
4. Copy the key and save it somewhere — you'll paste it into your site in Step 6.


## Step 3 — Create a Linode Account and get an API Key

[Sign up for Linode using our referral link](https://www.linode.com/lp/refer/?r=f89d0c9308eeef26368cc67356eb8fa81365d488) and you'll receive **$100 of credit** to use over your first 60 days — more than enough to run your site free for the first two months.

You'll need to verify your email and add a payment card. Once you can see the Linode dashboard, you're ready.

1. Find the profile menu in the top right
2. Open it and click on "API Tokens"
   ![The Linode profile menu open, with API Tokens circled](/assets/images/docs/linode-dashboard-tokens.png)
3. Click the **Create a Personal Access Token** button
4. Label it **Joinery Token**
5. Choose expiry "In one month"
6. Select **No Access** for all options except set **Linodes**, to **Read/Write**.
7. Click **Create Token**, and save the displayed token.  You'll need it later.

---

## Step 4 — Create Your Server

Open the Joinery one-click installer:

**[https://cloud.linode.com/stackscripts/2185451](https://cloud.linode.com/stackscripts/2185451)**

Click **Deploy New Linode**. A form opens. Here's every field and what it means:

### The Joinery fields (top of the form)

- **Admin email address** — the email you'll use to log in to your site. Use your real address you use today (not the new one you want): it's also how you recover your account if you ever forget your password.
- **Admin password** — the password you'll use to log in to your site. Choose a strong one and save it in a password manager. (You'll be asked to set a fresh one the first time you log in — a routine precaution.)
- **Site domain** — the domain you bought, like `yourname.com`. Type it exactly, with no `www` and no `https://`. Don't make one up — it must be a domain you actually own, because in the next step you'll connect it to this server.
- **Linode API token** — Copy the token you created in step 3 into this box.

### The server fields (rest of the form)

- **Image** — the server's operating system. Only the version Joinery supports is offered, so just leave it as is.
- **Region** — where in the world your server physically lives. Pick a city near you. Any choice works.
- **Linode Plan** — the size of the server. Click the **Shared CPU** tab and choose the **Nanode 1 GB** ($5/month). It's plenty to start with, and you can upgrade to a bigger size later without reinstalling.
- **Linode Label** — a nickname for the server in your Linode dashboard. Anything works; `joinery` is fine.
- **Root Password** — this one is **not** your website login. It's the master password for the rented machine itself, and Linode requires you to set one. You'll probably never need it again, but save it in your password manager. Make this at least 12 characters, and stick to letters, numbers, and simple punctuation — avoid quotes, backslashes, dollar signs, backticks, and exclamation marks, which can confuse the setup process.  

Click **Create Linode**.

Your server now boots up and installs Joinery entirely on its own — the web server, the database, the application, everything. This takes about **5–10 minutes**. 
---


## Step 5 — Log In

Give the install 5–10 minutes from when you clicked Create, then open a browser and go to:

```
https://yourname.com/admin
```

If the padlock isn't ready yet (DNS still spreading), `http://yourname.com/admin` works in the meantime — the secure version switches on by itself shortly after your domain connects.

Log in with the **admin email and admin password you chose on the form** in Step 2.

You'll be asked to set a new password right away. Do it, and save the new one in your password manager.

---

## Step 6 — Walk Through the Setup Wizard

The first time you log in, your site opens its **setup wizard** — a checklist that walks you through everything a new site needs: your name and the site's name, sign-in security, your personal encryption key, email, calendar, and backups. Each step explains itself, and you can leave and come back at any point.

Go through it in order. The step that matters most is **Email**:

- **Choose your email address** — something like `you@yourname.com`. This becomes the address your site sends from *and* a real mailbox on your site: mail sent to it arrives right there.
- **Paste your SMTP2GO API key** — from the SMTP2GO account you created earlier. That's the whole configuration: the wizard registers your domain with SMTP2GO for you and, if your DNS is at Linode, publishes the records SMTP2GO asks for. It then waits until SMTP2GO confirms they've spread, which usually takes a few minutes. If it's still waiting, leave the step and come back — nothing is lost.
- **Confirm the test email** — the wizard sends you a message, and when it arrives, email is proven working end to end.

Don't skip the email step. If you ever forget your password, a reset email is the way back in — and a brand-new site has no way to send one until this is done.

---

## Step 6 — Visit your new webmail page

Visit yourname.com/profile or click on your avatar in the top right and choose **Dashboard**.

Click **Email** in the submenu.

## What You Have Now

Your site comes ready with:

- **Drive** — file storage, like your own private Dropbox
- **Calendar** — events, reminders, and imports from other calendars
- **Mail** — a full mailbox on your own domain. The address you chose in the setup wizard sends and receives here; more addresses can be added from the mail admin.
- **AI assistant** — installed, and needs one thing from you: an API key from an AI provider (a local one or a service like Fireworks.ai). The setup wizard asks for it, or add it later under **Settings → AI**. You create that key in an account *you* own, so your AI usage is billed to you directly and never passes through anyone else.

---

## If Something Goes Wrong

- **The site never appears** after 15 minutes: the most common cause is a typo in the domain field. The cheapest fix is also the cleanest one — on the Linode dashboard, **delete the server** (Settings → Delete) and repeat Step 2 with the field corrected. 
- **The padlock / HTTPS isn't working** but the site loads over `http://`: DNS just hasn't finished spreading. 
- **You can't log in**: make sure you're using the *admin email and password* from the form (not the root password), and that you're at `/admin`.

For anything deeper, the [full installation reference](installation.md#troubleshooting) has a troubleshooting section.
