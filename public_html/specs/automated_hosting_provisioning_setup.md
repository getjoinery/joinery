# Automated Hosting Provisioning — Activation

**Status (updated 2026-07-18): activation is now a guided admin page, not a
manual checklist.** The management node's **Server Manager → Provisioning** page
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
4. **Scheduled tasks**: activates the provisioning umbrella (which runs
   order polling, customer cloud, SSL, and the managed-domain phases in one
   pass) and Send Queued Emails (creates missing rows, resumes paused). Send Queued Emails is core-owned but a hard
   pipeline requirement — the buyer welcome email queues into
   `equ_queued_emails` on the store site and only that task drains the
   queue; in the remote-store case activate it on the store site.
5. **Customer-cloud settings**: SSH key path (with key/.pub existence
   badges), referral URL, region/type/image defaults, and a status badge for
   the Linode OAuth app credentials. The provisioning keypair itself is
   generated automatically at plugin activation (default
   `{site root}/config/provisioning_key`); the page's Generate button covers
   management nodes activated before the key existed.

6. **Domain registrar** (only if you want to sell buyers their domain name):
   the Namecheap API username, API key (sealed at rest by the card itself),
   the allowlisted client IP, the offered TLDs, and a sandbox switch. A
   sellable badge tells you whether the leg is actually live.

**Remote-store case:** when the store is a different site from the control
plane, mint the service user + key on the store site and paste the values
into the three API settings; everything else on the page works the same.
Managed domain registration is the exception: its intake runs in-process on
the management node, so it needs the store and Server Manager on one install.

The page also shows (as requirement #1) a **job agent heartbeat badge** —
every job the pipeline creates sits pending until a joinery-agent polling
this site's queue claims it, so a management node without a live agent cannot
execute anything.

## Remaining operator steps (genuinely manual)

0. **Job agent**: install joinery-agent on the management node's host
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
   (`https://<management-node-host>/profile/server_manager/connect_cloud`) in
   the product's after-purchase message. For shared-host products, attach
   the domain question as a requirement instead — the attachment is what
   makes an order a hosting order. The Connect page is deliberately not in
   any member menu: buyers reach it by link at the moments that need it
   (purchase, progress, re-connect).
1b. **Managed domain registration** (optional, per hosting product): the
   buyer buys their domain in the same click as the box, and never touches
   DNS. Three steps, all one-time except the last:

   - **Registrar account.** Namecheap grants API access only to accounts
     with 20 or more domains, $50 in the balance, or $50 spent in the last
     two years. Turn on API access at Profile → Tools → Namecheap API
     Access, add the management node's public **IPv4** address to Whitelisted
     IPs (IPv6 is not accepted), and copy the key into the Provisioning
     page's Domain registration card. Rehearse against the sandbox first —
     the same card has the switch.
   - **Domain-year product.** Create a store product ("Domain registration
     (1 year)"), not publicly listed, with one version whose price type is
     `user`. Select it in the store's **Domain registration product**
     setting. Its price is the live registrar quote at checkout, so the
     buyer pays one year at cost with no markup and no padding.
   - **Attach it.** On each hosting product, tick **Managed domain** under
     *Info to collect before purchase*. Buyers who already own a domain use
     the domain question path instead; the two are independent.

   If the hosting product is a **subscription**, note that the cart then mixes
   a recurring line with the one-time domain line, and PayPal cannot process a
   mixed cart — those deployments take card payment through Stripe.

   Ongoing operator work is one thing only: **Server Manager → Domains**
   lists hand-overs waiting for a Change Ownership push in the Namecheap
   dashboard. That push has no API, so it is a two-minute manual action, and
   the domain must reach the buyer's own account before its first expiry —
   the platform never renews a buyer's domain and never fronts the cost.

2. **Shared-host fulfillment only**: opt at least one managed host in from
   the Server Manager dashboard (Edit → Max Sites + Provisioning Enabled).
   The host's IP is sent to customers as the DNS A-record target — it must
   be a routable public IP.
3. **Customer-cloud fulfillment only**: register the OAuth client in Linode
   Cloud Manager (Profile → OAuth Apps → Create OAuth App, **not** public,
   callback `https://<management-node-host>/oauth_callback`) and enter the
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
| Domain field says registration is unavailable | No registrar credentials, or no domain-year product selected — the Provisioning page's Sellable badge names which |
| Domain row stuck at `pending` with a transient error | The registrar is refusing or unreachable; the queue page shows its own words. An IP-allowlist error names the address to add |
| Domain row `failed` | Terminal — the name was taken, or the registrar refused. Resolve with the buyer (refund or an alternate name), then Retry from the Domains page |
| `[managed-domain] Paid but never registered: order N` | The buyer kept the domain line and removed the hosting line, so nothing ever asked for the registration. Refund the domain line, or register the name and file the row by hand. Reported once per order |
| Domain row `failed` saying the order did not pay for it | The buyer removed or repriced the domain-year line in the cart before checkout. Nothing was registered — refund the difference or take payment, then Retry |
| Domain registered but mail records never publish | The box could not be reached over SSH, or its mailbox provisioning refused — the row's error says which. A DKIM key that has not generated yet keeps the step open on purpose |
