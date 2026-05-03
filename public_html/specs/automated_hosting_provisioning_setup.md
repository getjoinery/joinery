# Automated Hosting Provisioning — Setup Checklist

This document covers the one-time operational setup required to activate the automated hosting provisioning pipeline. All code is already deployed; these are configuration steps only.

## 1. getjoinery.com — Domain Question

Create a Question and attach it to every hosting product.

1. Go to **Admin > Questions** and create a new Question:
   - **Label:** "What domain would you like to use for your site?"
   - **Type:** text (single line)
   - **Required:** yes
   - **Internal name / slug:** something memorable, e.g. `hosting_domain`

2. Note the **Question ID** (`qst_id`) — you'll need it in step 3.

3. For each hosting product, go to **Admin > Products > [product] > Requirements** and attach this Question as a QuestionRequirement. This causes the domain field to appear at checkout and the answer to land in `oir_order_item_requirements.oir_answer`.

## 2. getjoinery.com — API Service User

The control plane needs a dedicated API key with permission to read orders and queue emails.

1. Create a user (e.g. `provisioning@getjoinery.com`) with **permission level 3**.
2. Generate an API key pair for that user under **Admin > API Keys**.
3. Note the **public key** and **secret key**.

## 3. Control Plane — Plugin Settings

On the control plane (Server Manager plugin settings), configure:

| Setting | Value |
|---------|-------|
| `server_manager_getjoinery_api_url` | `https://getjoinery.com` |
| `server_manager_getjoinery_api_public_key` | public key from step 2 |
| `server_manager_getjoinery_api_secret_key` | secret key from step 2 |
| `server_manager_provisioning_domain_question_id` | Question ID from step 1 |
| `server_manager_provisioning_admin_alert_email` | your ops alert address |
| `server_manager_provisioning_welcome_from_email` | `support@getjoinery.com` (must be authorized for getjoinery's mail domain) |
| `server_manager_provisioning_welcome_from_name` | `Get Joinery Support` |

## 4. Control Plane — Enable a Provisioning Host

At least one managed host must be opted in before any orders will be fulfilled.

1. Go to **Admin > Server Manager** and click **Edit** on the host you want to use for auto-provisioning.
2. Set **Max Sites** to the number of sites this host should hold (e.g. 50).
3. Check **Provisioning Enabled**.
4. Save.

> The host's IP (`mgh_host`) is sent to customers in the welcome email as the DNS A-record target. Make sure it is a routable public IP, not a hostname or private address.

## 5. Control Plane — Activate Scheduled Tasks

Go to **Admin > System > Scheduled Tasks** and activate both tasks:

- **Poll Hosting Orders** — polls getjoinery every cron tick (~15 min) for new paid orders
- **Provision Pending SSL** — watches for DNS to resolve and runs certbot once it does

Both default to `every_run` frequency. No additional configuration is required.

## 6. Verify End-to-End

1. Place a test order on getjoinery.com for a hosting product, entering a test domain.
2. Wait up to 15 minutes for the next Poll Hosting Orders run.
3. Check **Admin > Server Manager** — a new node should appear in the host accordion with `install_state = installing`.
4. Once the install job completes, verify the welcome email arrived at the buyer's address.
5. Point the test domain's A record to the host IP.
6. Wait for the next Provision Pending SSL run (~15 min). The node's SSL badge should flip from `pending` to `active` once certbot succeeds.

## Failure Modes to Watch

| Symptom | Likely cause |
|---------|-------------|
| No node appears after 15 min | API credentials wrong, or question ID incorrect — check Poll Hosting Orders last run status in Scheduled Tasks |
| Node stuck at `install_failed` | install_node job failed — click the job for details, fix the host, click Retry |
| SSL stuck at `pending` for hours | DNS not pointing to the correct IP — verify with `dig domain.com` |
| SSL badge flips to `failed` | ~16 hours of certbot failures — check job output for certbot errors (rate limits, DNS misconfiguration) |
| Welcome email not received | Check getjoinery's queued email queue; verify `welcome_from_email` is SPF/DKIM-authorized |
