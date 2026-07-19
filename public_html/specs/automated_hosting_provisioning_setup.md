# Automated Hosting Provisioning — Activation

**Status (updated 2026-07-18): activation is now a guided admin page, not a
manual checklist.** The control plane's **Server Manager → Provisioning** page
(`/admin/server_manager/provisioning_setup`) shows the live state of every
pipeline requirement and does the automatable work itself
(`ProvisioningSetup` engine, `plugins/server_manager/includes/`). What
remains below is the operator work the platform cannot do for itself.

## The Provisioning page does this for you

One click each, idempotent, with live status badges:

1. **Store API credentials** (self-store case): creates the service user
   (`provisioning@<host>`, permission 5 — the API grants cross-user read
   only at >= 5; password recovery disabled), mints a machine API key
   (capability read+write, no delete), and writes
   `server_manager_getjoinery_api_url` / `..._api_public_key` /
   `..._api_secret_key`. A loopback probe badge confirms the API answers
   with the stored credentials. Rotation is a button.
2. **Domain question**: creates the required short-text Question and writes
   `server_manager_provisioning_domain_question_id`. The page lists which
   products the question is attached to.
3. **Email settings**: welcome from address/name and the admin alert
   address, edited in place.
4. **Scheduled tasks**: activates Poll Hosting Orders, Provision Pending
   SSL, Provision Customer Cloud, and Send Queued Emails (creates missing
   rows, resumes paused). Send Queued Emails is core-owned but a hard
   pipeline requirement — the buyer welcome email queues into
   `equ_queued_emails` on the store site and only that task drains the
   queue; in the remote-store case activate it on the store site.
5. **Customer-cloud settings**: SSH key path (with key/.pub existence
   badges), referral URL, region/type/image defaults, and a status badge for
   the Linode OAuth app credentials. The provisioning keypair itself is
   generated automatically at plugin activation (default
   `{site root}/config/provisioning_key`); the page's Generate button covers
   control planes activated before the key existed.

**Remote-store case:** when the store is a different site from the control
plane, mint the service user + key on the store site and paste the values
into the three API settings; everything else on the page works the same.

The page also shows (as requirement #1) a **job agent heartbeat badge** —
every job the pipeline creates sits pending until a joinery-agent polling
this site's queue claims it, so a control plane without a live agent cannot
execute anything.

## Remaining operator steps (genuinely manual)

0. **Job agent**: install joinery-agent on the control plane's host
   (`sudo bash joinery-agent-installer.sh --config <Globalvars_site.php path>`,
   built from `{agent repo}/build_installer.sh`). Hosts without systemd
   (Docker containers) are auto-detected and supervised via cron. The
   Provisioning page's agent badge must show Online before anything below
   can execute.

1. **Per hosting product** (product edit page): for customer-cloud
   fulfillment, pick **Customer cloud server** under Purchase grants — that
   stamps `pro_fulfillment_provider = customer_cloud` and asks the domain
   question at checkout automatically (the `CustomerCloudFulfillment`
   provider contributes it) — and put the Connect link
   (`https://<control-plane-host>/profile/server_manager/connect_cloud`) in
   the product's after-purchase message. For shared-host products, attach
   the domain question as a requirement instead — the attachment is what
   makes an order a hosting order. The Connect page is deliberately not in
   any member menu: buyers reach it by link at the moments that need it
   (purchase, progress, re-connect).
2. **Shared-host fulfillment only**: opt at least one managed host in from
   the Server Manager dashboard (Edit → Max Sites + Provisioning Enabled).
   The host's IP is sent to customers as the DNS A-record target — it must
   be a routable public IP.
3. **Customer-cloud fulfillment only**: register the OAuth client in Linode
   Cloud Manager (Profile → OAuth Apps → Create OAuth App, **not** public,
   callback `https://<control-plane-host>/oauth_callback`) and enter the
   client ID/secret at **Admin → System → OAuth Providers**. Optionally copy
   the referral URL from Cloud Manager → Profile → Referrals into the
   Provisioning page's field.

## Verify End-to-End

1. Place a test order on the store for a hosting product, entering a test
   domain.
2. Wait up to 15 minutes for the next Poll Hosting Orders run.
3. Check **Admin > Server Manager** — a new node should appear with
   `install_state = installing` (shared-host), or watch the buyer's Connect
   page progress table (customer-cloud).
4. Once the install job completes, verify the welcome email arrived at the
   buyer's address.
5. Point the test domain's A record at the node IP.
6. Wait for the next Provision Pending SSL run (~15 min). The node's SSL
   badge should flip from `pending` to `active` once certbot succeeds.

## Failure Modes to Watch

| Symptom | Likely cause |
|---------|-------------|
| No node appears after 15 min | API credentials wrong (check the Provisioning page's probe badge), or question not attached to the product — check Poll Hosting Orders last run status in Scheduled Tasks |
| Node stuck at `install_failed` | install_node job failed — click the job for details, fix the host, click Retry |
| SSL stuck at `pending` for hours | DNS not pointing to the correct IP — verify with `dig domain.com` |
| SSL badge flips to `failed` | ~16 hours of certbot failures — check job output for certbot errors (rate limits, DNS misconfiguration) |
| Welcome email not received | Check the store's queued email queue; verify `welcome_from_email` is SPF/DKIM-authorized |
