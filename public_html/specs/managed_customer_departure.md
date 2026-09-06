# Managed customer departure — taking a site to self-hosting

**Status:** Placeholder, 2026-09-06. Not designed, not built. Opened because
the two-choice pricing decision (Self-hosted free / Managed $12.99) retired
the "move to your own cloud" product that `hosted_trial_provisioning.md` §7
named as the off-ramp, so a Managed customer currently has no described way
out. Companion: `hosted_trial_provisioning.md`, `docs/backups.md`.

## 1. What this does for the customer

A Managed customer decides to run Joinery themselves. They install Joinery on
a machine of their own, bring their site across — files, database, mail
settings, everything a backup carries — and point their domain at it. Their
Managed subscription ends, their old server is switched off, and nothing of
theirs is left behind that they cannot reach. The promise on the page is
*take everything with you*, and this spec is what makes that true.

## 2. What already exists

- **The backup is already theirs to open.** A Managed site's backups are the
  fleet manager profile, sealed to the site's own recovery key, which only
  the customer holds. An archive opens on any machine with the recovery
  private key and stock tools, no Joinery code needed
  (`docs/backups.md` § Opening a backup with no Joinery anywhere).
- **The restore onto a fresh install exists.** Install Joinery, then
  `restore_project.sh` with the archive and the unsealed data key, with
  `--domain` to name the domain (`docs/backups.md` § Restoring). A chain
  restores full-plus-incrementals oldest first.
- **The shelf lives on for 90 days** after a failed or cancelled payment
  before the retention pass prunes it, so a customer who cancels first and
  fetches later still has their archives.
- **Shutdown never deletes.** The instance is powered off; deletion at the
  provider is a person.

## 3. What is missing

1. **A way to get the archives.** The shelf is on the operator's bucket under
   the node's prefix, and the only credentials that can read it are the
   plane's master credential and the per-run keys minted for the agent. A
   customer has neither. Something has to hand them their archives: a page
   of time-limited download links to every object under their prefix, or a
   short-lived read-only credential pinned to the prefix (the same shape the
   restore path already mints, `__SM_RUN_CREDS_` read-only).
2. **A departure action.** Today "leave" is "cancel the subscription and wait
   for grace to run out". A deliberate departure should be its own action on
   the sites page: take a final backup now, offer the archives, end mail
   sending, and start the shutdown clock — without the 30-day grace the
   non-payment path uses, because this is a decision, not a fault.
3. **The written path for the customer.** One page: install on your own
   machine (self-install or the StackScript), fetch your archives, unseal
   with your recovery key, restore, repoint DNS. Every step exists; the
   sequence is written nowhere a customer can read.
4. **What the domain and mail need.** A domain registered through the
   operator (managed domain registration) must reach the customer's own
   registrar account; the SMTP2GO subaccount is closed; the `mail.<domain>`
   DNS records the operator published are the customer's to replace.

## 4. Open decisions

- **Q1. Links or credential?** Download links per object are simplest for a
  person and need no tooling on their side; a scoped credential lets the
  restore tooling fetch a whole chain itself. Could be both, links first.
- **Q2. Does the plane help with the restore, or only with the bytes?** The
  customer's new machine is not a managed node, so restore-over-agent does
  not apply. The honest floor is bytes plus a written procedure.
- **Q3. When does the old server go off?** Immediately on the customer's
  say-so, or after they confirm the new site is up. A departing customer who
  loses both is the failure to design against.

## 5. Not in scope

- Moving a Managed site to a *bring-your-own-cloud* provision. That product
  is retired; the destination is a self-install.
- Migrating the customer's SMTP2GO subaccount or backup shelf as ongoing
  services. Managed services for self-hosters are shelved
  (`managed_backups.md`).
