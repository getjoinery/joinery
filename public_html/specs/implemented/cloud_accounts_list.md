# Cloud Accounts as a List, and a Hosting Tab

**Status:** Implemented 2026-10-11, WP1–WP5. Verified: cloud_accounts_list 56/56 (new) and the seven suites it
touches; db --changed 237/238 (the one failure, broker_managed_run, is unrelated and passes alone); dev migrated
by sm_019 (its token is account #1, test, the default); every Provisioning Setup tab, the dashboard and Remote
Install render on dev. The rest (the browser walk of the account actions, Connect with Linode end to end, the
release on getjoinery and a copy into a second account) is in the live verification queue under 'Cloud accounts list'.
**Related:** `test_cloud_account_and_prod_management` (the test account, adoption, WP9) and
`server_manager_account_tabs` (the dashboard's Main and Test tabs), both of which this builds on.

## What this does for the owner

A management node can hold several cloud accounts of ours, not one. Each is added on a new
**Hosting** tab of Provisioning Setup, by either of two methods: paste an API key, or connect
with a Linode sign-in. One account is the default, where servers this node creates on its own
go (a hosted order). Every form that creates a server (the Copy tab, Install a new node) lists
every account and preselects the default. Every server remembers the account it lives in, so
everything done to it later (removing it, its reverse DNS, a transfer, an IP swap) is done with
that account's key.

The trigger: jeremytunnell-vps lives in the owner's personal Linode account, and today a copy
of it can only be created in Joinery, Inc.'s account. With this, the owner adds the personal
account's key once and copies into it.

The Cloud Connect item leaves the admin menu. Connecting with a Linode sign-in becomes the
second method on the Hosting tab; customers keep their own Connect page on their profile.

Provisioning Setup, today one long page of eleven numbered sections, becomes tabs.

## Current state (read 2026-10-11)

- **One operator token per management node.** Setting `server_manager_operator_cloud_token`
  (sealed), with `_scopes`, `_account` (the account's name) and `_company` read from Linode when
  the Hosted tier card is saved. It is entered in section 9, Hosted tier, beside the SMTP2GO keys.
  On getjoinery it is Joinery, Inc.'s main account; on dev it is the test account's.
- **Customer accounts** are rows in `cca_customer_cloud_accounts`: one per user who connected a
  Linode account through the OAuth sign-in at `/profile/server_manager/connect_cloud`. A grant
  lives two hours and has no refresh token (`profile_connect_cloud_logic.php`), so a row is
  usable for two hours after each connect.
- **The Copy tab's Cloud account list** (`CustomerCloudAccount::provision_targets()`): the
  operator token as `operator`, then every active, unexpired `cca` row of any user. On getjoinery
  today it offers one entry: Joinery, Inc. (operator token).
- **Which account a server is in** is known two ways:
  - the server's provision record (`cvp_customer_cloud_provisions`): `cvp_instance_id`,
    `cvp_hosting_mode` (`operator` means the one operator token) and
    `cvp_cca_customer_cloud_account_id` for a customer's account. Adoption
    (`CloudServerAdoption`) writes one for a server this node did not create, searching the
    operator token and each connected account by the node's addresses;
  - `mgn_cloud_account` / `mgh_cloud_account`: `main`, `test`, `customer` or `external`
    (`CloudAccounts`). Main or test is decided by the operator token's company name: the company
    `Joinery Test disposable` is test, any other is main. The dashboard's Main and Test tabs read it.
- **Twelve call sites use the one operator token**, whatever account the server is in:
  `NodeRemoval` (3: `machine_kind` and the two provider steps), `NodeReverseDns`, `SiteCopyRunner`
  (2), `InstanceTransfers`, `InstanceTransferFinish`, `MachineTransferWatch`, `HostedTrialWatch` (2),
  `TestCloudCleanup`, `ProvisionCustomerCloud` (operator mode), `install_node_form`, and
  `CustomerCloudAccount::provision_targets`. `NodeRemoval::machine_kind()` already refuses the
  provider path for a server not in this node's own account (`hosted_at === plane_account()`).
- **Provisioning Setup** (`views/admin/provisioning_setup.php`, 550 lines), one box, eleven
  sections: 1 Job agent, 2 Store API connection, 3 Domain question, 4 Emails, 5 Scheduled tasks,
  6 Shared-host fulfillment, 7 Customer-cloud fulfillment (OAuth app, default region, type,
  image, referral URL), 8 Domain registration, 9 Hosted tier (operator token, SMTP2GO, allowances,
  trial and grace), 10 Products, 11 Test-account cleanup.
- **The admin menu** has Cloud Connect (`plugin.json`, slug `server-manager-cloud-connect`)
  pointing at the customers' profile page.

## Design

- **D1 — Our accounts in a table of their own (Q1).** A new table holds the cloud accounts of
  this management node: provider (Linode), name and company (read from the provider on save),
  method (`key` or `connect`), the sealed credential, the scopes the provider reports, a test
  flag, status, and who added it (a record, never an owner: deleting that login removes nothing).
  Customers' accounts stay in `cca_customer_cloud_accounts`, unchanged, with their code. A
  provision record gains a column for our account beside `cvp_cca_customer_cloud_account_id`;
  exactly one of the two is set for a server in a cloud account. A copy into one of our accounts
  is our server, never shown on anybody's profile as theirs. The two places that list every
  account (the account choice on forms, and adoption) read both tables.
- **D2 — Two methods, one entry.**
  - **API key:** pasted once, sealed, and checked at once: the provider is asked for the account's
    name, company and the token's scopes, and the entry shows which scopes are missing for what
    (creating servers, the hosted tier's subaccounts, the test cleanup), as the hosted card does today.
  - **Connect with Linode:** the existing OAuth app and callback, saving the entry as ours. The
    entry says plainly that the grant lasts two hours and cannot be refreshed: right for a one-off
    copy into somebody's account, wrong for an account this node keeps managing. After two hours
    it shows expired with a Reconnect button, and is not offered on any form (as `provision_targets`
    already does for an expired customer grant).
- **D3 — The default account.** A setting names the account servers go to when nobody chose
  one: hosted orders and anything else the node creates on its own. Like the backup storage
  setting `server_manager_backup_target_id`. Forms preselect it; they do not depend on it.
  The default cannot be a Connect entry (it would expire under a hosted order) or a test account
  on a production node's hosted tier; the save refuses both, saying why.
- **D4 — Every server knows its account.** The provision record carries the account it was
  created in or adopted from, for ours as for a customer's; `operator` as a hosting mode stops
  meaning "the one token". Every call site above takes the token of the server's own account.
  A server whose account is no longer held (the entry removed or expired) is handled as
  `NodeRemoval` handles one in another account today: the operator attests that it is gone.
  Adoption searches every account held.
- **D5 — Test accounts.** The test flag comes from the company name as today
  (`Joinery Test disposable`), read on save. Test-account cleanup runs on every test account
  held, each behind its own safety catch. `mgn_cloud_account` main/test follows the server's
  account's flag; customer and external are unchanged.
- **D6 — The existing token becomes the first entry.** A migration moves
  `server_manager_operator_cloud_token` and its name, company and scopes into one entry, makes
  it the default, and points every provision in hosting mode `operator` at it. The four settings
  are then retired from `plugin.json`. Nothing about any existing server changes.
- **D7 — Provisioning Setup becomes tabs**, the numbering goes, and each tab holds one subject:
  - **Hosting:** the cloud accounts list (ours: add by API key or Connect with Linode, set default,
    remove; customers': read-only, who connected and when), the Linode OAuth app's status, and
    section 7's defaults (region, size, image, referral URL).
  - **Setup:** job agent, store API connection, scheduled tasks, emails.
  - **Hosted tier:** allowances, trial, grace, SMTP2GO, referral URLs.
  - **Shop:** domain question, shared-host fulfillment, products.
  - **Domains:** registration.
  - **Cleanup:** test-account cleanup.
  - The grouping may shift once the tabs are seen in use (Q3).
  - The Hosted tier's operator token field goes; the hosted tier names the default account and
    whether its scopes cover the tier.
- **D3a — What "a production node" means for the default (decided in the build).** There is no
  production flag on a node. The rule is: the test account cannot be the default while this node holds
  any other account. Dev holds only the test account, so it keeps it as the default; getjoinery
  holds main accounts, so it never can.
- **D5a — The dashboard tabs are named Main and Test** (they were Joinery Main Linode and Joinery
  Test Linode), since a main account need not be Joinery, Inc.'s.
- **D8 — Cloud Connect leaves the admin menu.** The `server-manager-cloud-connect` admin menu
  entry is removed. `/profile/server_manager/connect_cloud` stays: it is the customers' page, and
  a customer-cloud order links to it.

## Work packages

### WP1 — The list, and the existing token as its first entry (D1, D2 key method, D3, D5, D6)

The new table (D1) and the provision record's column for it; the default setting; save checks (name, company, scopes, test flag) through the
provider; the migration of the operator token; the four settings retired. Tests: an entry saves
sealed and reads its facts; missing scopes are named; the default refuses a Connect entry and a
test account for the hosted tier; the migration on a node with a token, without one, and run twice.

### WP2 — Every server uses its own account's key (D4)

The provision record's account (ours or a customer's); adoption over both tables; each of the twelve call sites takes
its server's account. `NodeRemoval::machine_kind` asks "is this server's account held" instead of
"is it this node's account". Tests: each call site with two accounts held picks the right key; a
server whose account was removed falls to the attest path; adoption finds a server in the second
account.

### WP3 — Choosing the account (D3)

The Copy tab and Install a new node list every usable account, ours first, the default
preselected, customers' after. Tests: the list's order and labels; an expired Connect entry is
not offered.

### WP4 — Provisioning Setup as tabs, the Hosting tab, Connect as a method (D2, D7, D8)

The tabs; the Hosting tab's list with both add methods; Connect with Linode from the Hosting tab
saves an entry of ours; the Hosted tier card loses the token field; the Cloud Connect admin
menu entry removed. Browser check of every tab on dev, and the Connect method end to end against
the test account.

### WP5 — The dashboard's Main and Test tabs follow the account's test flag (Q2)

The dashboard keeps its two tabs. A server in an account of ours flagged test is on Test; every
other server (ours, a customer's, elsewhere) is on Main. `CloudAccounts` reads the flag of the
server's own account instead of the one operator token's company. Tests: with a main and a test
account held, each server lands on its account's tab; a second main account's servers are on Main.

## Questions

- **Q1 — Where the rows live. DECIDED 2026-10-11 (owner): two tables, D1.** Considered and
  rejected: widening `cca_customer_cloud_accounts` (its rows are permanently deleted with their
  user, so ours would vanish with the admin who added them; a copy into an account becomes that
  user's server on their profile; and every reader assumes a row is a customer's), and one new
  table for all (moves live customer rows and repoints their servers for a name).
- **Q2 — The dashboard's tabs. DECIDED 2026-10-11 (owner): Main and Test stay, by each account's
  test flag (WP5).** The split exists to keep test sites apart; a tab per account is not wanted.
- **Q3 — The other tabs. DECIDED 2026-10-11 (owner): Setup, Hosting, Hosted tier, Shop, Domains,
  Cleanup (D7)**, to be adjusted if it reads wrong in use.

## For the executor

- Never commit, never `git add`; the owner runs git.
- Docs describe the current state only: `plugins/server_manager/docs/overview.md` (provisioning,
  the copy, removal), `docs/backups.md` only if a cross-reference changes.
- Credentials are sealed with `SecretBox` as the operator token is today, never shown back, never
  in a log or a job.
