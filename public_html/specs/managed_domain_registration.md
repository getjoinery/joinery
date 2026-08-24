# Managed Domain Registration — One-Click Domain at Checkout

**Status:** Spec (unbuilt). Build-ready: written for an executor; file paths,
signatures, and schemas below were verified against the codebase 2026-08-24.
**Depends on:** the automated hosting provisioning pipeline
(`specs/implemented/automated_hosting_provisioning.md`,
`specs/automated_hosting_provisioning_setup.md`) and customer-cloud
fulfillment (`specs/customer_cloud_provisioning.md`). This spec adds a
**domain leg** to that pipeline; it does not replace any compute mode.
**Control plane:** getjoinery.com (the store taking the order and the Server
Manager fulfilling it, one install), consistent with the site-roles decision
in `specs/new_site_deployment_fortress_verification.md`.
**First registrar:** Namecheap. The design is a provider seam so a second
registrar is a new class, not a rewrite.

## What this does for the user

Today a buyer who wants hosting still has to own a domain and point DNS at
their new box by hand — three separate chores across two more vendors. This
removes all of them from the buyer's path. At checkout the buyer types the
name they want (`smithfamily.com`), sees live availability and price, and pays
once. Behind that single payment their box is provisioned **and** the domain
is registered, DNS is wired to the box, and email is turned on — so
`jane@smithfamily.com` works when the welcome email arrives. There is no
Linode dashboard, no registrar dashboard, no DNS panel, and no upfront
padding: the buyer pays for one year of the domain and nothing more.

The buyer **legally owns the domain from the moment it is registered** — they
are the registrant (WHOIS owner) on day one, not a tenant on a name we own.
Management and billing start under the operator's registrar account so the
buy is one click; the buyer graduates to full self-custody on their own
schedule (see **Graduation**). This keeps the platform's north star intact:
sovereignty is the guaranteed end state, reached without a dashboard.

## Why not the alternatives (decided)

- **Subdomain of a name we own** (`jane.joinery.app`): genuinely one click and
  zero registrar work, but you cannot run a credible personal email identity on
  it, and the buyer never owns anything transferable — so it solves none of the
  DNS or email problems this spec exists for. Rejected here entirely. A
  subdomain sandbox exists only as a kick-the-tires trial, specified separately
  (`specs/subdomain_sandbox_tier.md`) and unconnected to this pipeline.
- **Buyer brings their own domain** (today's Question-at-checkout path):
  retained for buyers who already own a name, but it is not one click and it
  strands non-technical buyers at the DNS step. Managed registration is the
  default for the paid package.
- **Operator owns the domain and transfers it later**: rejected — registering
  with the buyer as registrant means there is nothing to transfer during
  onboarding and ownership is never in doubt.

## The buyer journey

1. On the product page (requirement fields render there, not on `/checkout`),
   the buyer types the desired domain, sees live availability and the
   one-year price, and fills the registrant contact block — prefilled from
   their account, one already-populated card.
2. Buyer pays once. The order carries the compute line (existing) plus a
   "Domain registration (1 year)" line priced at the live quote.
3. Zero-touch from here: box provisioned (existing pipeline) → domain
   registered with **registrant = buyer**, free WHOIS privacy applied →
   DNS wired to the box → email records published → PTR set → welcome email
   with the live address.
4. From **six months in**, the buyer's own box carries an escalating
   **"take ownership"** banner that opens the guided push flow on the control
   plane (see **Graduation**). Nothing about graduation appears in the setup
   wizard or the welcome email — the six-month banner is the first mention.

## Ownership and graduation

Two things are true at once and must not be conflated:

- **Legal ownership is immediate and universal.** The buyer is the registrant
  from registration. This holds for every registrar and is the sovereignty
  guarantee that never depends on a later step.
- **Management + billing custody starts with the operator** (so the buy is one
  click) and moves to the buyer at graduation.

**Graduation mechanism is a registrar capability:**

- **Namecheap (`account_push`, operator-manual):** custody moves by
  Namecheap's **Change Ownership** push into the buyer's own free Namecheap
  account — free, immediate, no 60-day lock, and DNS/host records, Domain
  Privacy, and auto-renew settings all survive the push untouched (per
  Namecheap KB, confirmed 2026-08-24). The push has no API, so the pipeline
  queues it as an operator task — a two-minute dashboard action at current
  volume. A buyer who later wants a different registrar transfers onward from
  their own account; that is ordinary registrar business, not part of this
  flow.
- **Future reseller registrar (`account_push`, API-driven):** the same push
  performed by the pipeline itself, with no operator task. The seam already
  expresses this; no pipeline change needed to adopt it.

**Intended second registrar: OpenSRS** — the deliberate graduation target once
domain volume justifies its wholesale-reseller onboarding. It wins on both
axes Namecheap concedes: API-driven `account_push` graduation and wholesale
pricing with no first-year/renewal gimmick. Namecheap is first only because it
needs no reseller application; adopting OpenSRS later is a new
`DomainRegistrarProvider` class, not a pipeline change.

**Why graduation is not optional for Namecheap:** while a domain sits in the
operator's Namecheap account, its renewal bills the **operator**. The
no-backstop decision (below) forbids the operator fronting that renewal, so the
domain must reach the buyer's own custody before the first-year expiry for the
buyer to be billed directly.

### The guided flow (D2 resolved)

The first mention is the in-product banner at **six months** (expiry minus
six months), escalating at 30 / 14 / 7 / 1 days before expiry. The banner
links to the control-plane flow, which has three buyer steps:

1. **Create a free Namecheap account.** Link out; no card is required.
2. **Tell us the account.** One field — the buyer's Namecheap username or
   account email. Submitting it queues the operator push task.
3. **Finish in their dashboard.** Shown as a checklist: accept the push
   invitation (an email link valid 7 days — the operator re-pushes if it
   lapses; a recipient with auto-accept enabled skips this), then add a
   payment method and enable auto-renew — the step that actually prevents the
   no-backstop lapse.

When the domain leaves the operator account, the watcher flips
`rdm_graduation_state` to `self_custody`, updates the node banner, and emails
a "the domain is now fully yours" confirmation. **No EPP path is built.**

## Renewal — no backstop (decided)

- Registration is **one year**, funded by the single checkout charge. No
  upfront multi-year padding.
- The buyer's own box shows the escalating take-ownership banner (from six
  months, louder at 30 / 14 / 7 / 1 days). Reminders are in-product, not a
  single email that can be missed.
- **The operator never auto-renews and never fronts the renewal.** After
  graduation the buyer renews in their own account with their own card. If
  the buyer ignores a full year of escalating prompts, the domain lapses.
  That is a sovereign outcome, not an operator failure. There is no grace
  renewal — and note Namecheap cannot even push an expired domain, so the
  prompts must land well before expiry.

---

# Build specification

## Existing machinery this leg reuses (verified)

| Exists | Where | Use here |
|---|---|---|
| `NamecheapDnsDriver` | `includes/dns/drivers/NamecheapDnsDriver.php` | Publishes DNS records at Namecheap. Already implements the `DnsProvider` seam, declares `credentialFields()` (`api_user`, `api_key` secret, `client_ip`) and `apiGateNote()` documenting the 20-domain/$50 API gate. **The registrar seam does NOT re-implement DNS.** |
| DNS publish stack | `includes/dns/DnsRecordPlan.php`, `DnsReconciler.php`, `DnsDriverRegistry.php`, `DnsRecord.php` | `DnsRecordPlan` (`addRecord($type,$name,$value,$ttl,$priority,$note)`, `merge()`), `DnsReconciler::apply($driver,$zone,$plan,...)` with `DnsReconciler::APPLY_ADDITIVE`. Beware: Namecheap `setHosts` is a whole-list replacement — the reconciler handles the diff; never call setHosts directly. |
| Apex A plan | `plugins/server_manager/includes/NodeDnsPlan.php` | `NodeDnsPlan::forNode($node): ?DnsRecordPlan` (one apex A/AAAA record), `NodeDnsPlan::publicIp($node)` (prefers `cvp_instance_ip`; `mgn_host` only when a literal IP). |
| Mail DNS plan | `plugins/mailbox/includes/InboundEmailSetupCheck.php` | `dnsPlan($domain)` builds the full topology-aware mail record set (MX, A mail-host, SPF, DKIM, DMARC, Joinery Direct SRV/TXT). Runs **on the node**, which is why the node is the source of truth (see the prepare utility). |
| PTR | `includes/cloud_compute/CloudComputeProvider.php` + `plugins/server_manager/includes/NodeReverseDns.php` | `setReverseDns()` implemented on `LinodeComputeDriver`. `NodeReverseDns::setQuietly($node,$hostname)` — has a forward-check gate (the A record must already resolve to `cvp_instance_ip`), refuses nodes without a linked cloud provision (so shared-host rows skip naturally). |
| Provisioning umbrella task | `plugins/server_manager/tasks/ServerManagerAdvanceProvisioning.php` | One `every_run` scheduled task that sequentially runs phase classes from `plugins/server_manager/includes/provisioning/` (`PollHostingOrders`, `ProvisionCustomerCloud`, `ProvisionPendingSsl` — plain classes with `run(array $config): array`, no `.json`). New phases slot into its sequence. |
| Failure conventions | `plugins/server_manager/includes/provisioning/ProvisionCustomerCloud.php` | `alert_and_fail()` (plain `EmailSender::quickSend`), `resolve_alert_recipient()` (setting `server_manager_provisioning_admin_alert_email` → `webmaster_email` → first superadmin), transient-vs-terminal split in `handle_compute_failure()`. Copy these shapes. |
| Requirement types | `plugins/store/includes/requirements/AbstractProductRequirement.php` | Base class + static registry. Template: `EmailRequirement.php`. Plugin-owned types self-register (`AbstractProductRequirement::register('X', __FILE__)`) and are required from the plugin's `serve.php` — precedent: `SurveyRequirement`, and `CustomerCloudFulfillment` registration in `plugins/server_manager/serve.php:24-28`. |
| Answer storage | `plugins/store/data/order_item_requirements_class.php` | `OrderItem::save_cart_data()` writes `oir_order_item_requirements` rows; array values MUST carry `question`/`answer` keys; scalars land as label=key. `$api_readable = true`. |
| Priced second line | `plugins/store/logic/product_logic.php:107-121`, `plugins/store/data/products_class.php:314` | The cart's unit of sale is a product line, and `prv_price_type = 'user'` already means "the price arrives in the line's form data" (`user_price_override`, read by `get_price()`). The domain-year line uses exactly that — no new pricing branch. The donation branch at `:107-121` is prior art for adding a second line but is a hardcoded special case; this spec adds the general hook instead (see **The price line**). `affects_pricing()` / `get_modified_price()` are **dead code** (zero call sites) and would also fold a one-time fee into a potentially recurring line — do not use them. |
| Checkout live API calls | `assets/js/joinery-api.js` + `plugins/store/logic/checkout_check_email_logic.php` | `joineryApi.post(action, body)`; descriptor with `'auth' => ['allow_guest' => true, 'requires_browser_session' => true]` is the template for the availability check. |
| Remote setting write | `plugins/mailbox/includes/FleetProvisionSeeding.php` | `buildRemoteCommand()` / `runSsh()` — SSH + psql `INSERT ... ON CONFLICT` into the node's `stg_settings`. Model the banner-state push on this. |
| Guzzle transport | `includes/cloud_compute/LinodeComputeDriver.php`, `includes/dns/DnsDriverBase.php` | Injectable `?Client $http = null` constructor is the test seam. Mirror it. |
| Welcome email | `plugins/server_manager/includes/JobResultProcessor.php` | `send_provisioning_welcome_email()` — unchanged by this spec. |

## Component inventory

**New files:**

| File | What |
|---|---|
| `plugins/server_manager/includes/domain_registrar/DomainRegistrarProvider.php` | The interface (below) + `DomainRegistrarException`. |
| `plugins/server_manager/includes/domain_registrar/DomainRegistrarRegistry.php` | Discovery registry, copied from `includes/oauth/OAuth2ProviderRegistry.php` (glob dir → `require_once` → walk `get_declared_classes()` for the interface → key by `getKey()`; plus `configured()` and `reset()`). |
| `plugins/server_manager/includes/domain_registrar/NamecheapRegistrar.php` | First implementation (API mapping below). |
| `plugins/server_manager/data/registered_domains_class.php` | `RegisteredDomain` / `MultiRegisteredDomain` (schema below). |
| `plugins/server_manager/includes/requirements/ManagedDomainRequirement.php` | The checkout requirement type. |
| `plugins/server_manager/logic/domain_check_logic.php` | `/api/v1/action/server_manager/domain_check` availability+price action. |
| `plugins/server_manager/includes/provisioning/ProvisionManagedDomains.php` | Registration + DNS + PTR phase class. |
| `plugins/server_manager/includes/provisioning/ManagedDomainWatch.php` | Expiry sync, graduation polling, banner-state push, prompt emails phase class. |
| `plugins/mailbox/utils/managed_domain_prepare.php` | Node-side CLI: make the domain mail-ready and print its DNS plan as JSON. |
| `plugins/server_manager/views/profile/domain.php` + `plugins/server_manager/logic/profile_domain_logic.php` | Buyer-facing graduation flow on the control plane (auto-routes as `/profile/server_manager/domain`). |
| `plugins/server_manager/views/admin/domains.php` + `plugins/server_manager/logic/admin_domains_logic.php` | Operator queue: registered domains, pending pushes, failures (auto-routes as `/admin/server_manager/domains`). |
| `includes/ManagedDomainNotice.php` | Core banner helper rendered on every deployment from node-local settings (below). |
| Tests | listed in **Tests**. |

**Modified files:**

| File | Change |
|---|---|
| `plugins/server_manager/serve.php` | `require_once` + `AbstractProductRequirement::register('ManagedDomainRequirement', ...)` next to the existing `FulfillmentRegistry::register(new CustomerCloudFulfillment())` block (guard with the same store-plugin presence check). |
| `plugins/server_manager/tasks/ServerManagerAdvanceProvisioning.php` | Append `ProvisionManagedDomains` and `ManagedDomainWatch` to the phase sequence. |
| `plugins/server_manager/plugin.json` | New settings + settings group + adminMenu item (below). |
| `plugins/store/plugin.json` | `store_domain_registration_product_id` setting (below). |
| `plugins/store/includes/requirements/AbstractProductRequirement.php` | New base hook `extra_cart_lines($form_data, $product): array`, default `[]` (below). |
| `plugins/store/logic/product_logic.php` | Generic loop adding the lines requirements return from `extra_cart_lines()` (below). The existing donation branch is untouched. |
| `settings.json` (core) | Four `managed: true` node-banner settings (below). |
| `includes/PublicPageBase.php` / `includes/AdminPage.php` | One call site each for `ManagedDomainNotice::render()` (below). |
| `plugins/server_manager/views/admin/provisioning_setup.php` + `logic/admin_provisioning_setup_logic.php` | "Domain registrar" credentials card (below). |
| `includes/dns/DnsRecordPlan.php` | Add `toArray(): array` / `static fromArray(array): DnsRecordPlan` **only if no equivalent serialization exists** — check first; bump the file version if touched. |

Increment the version header of every modified file. Run
`php maintenance_scripts/dev_tools/validate_php_file.php` on every new/edited
PHP file. `chmod 666` new files.

## The registrar seam

`plugins/server_manager/includes/domain_registrar/DomainRegistrarProvider.php`:

```php
class DomainRegistrarException extends Exception {
    public $transient = false;   // true → retry next tick; false → terminal
    public static function transient(string $message): self;
    public static function terminal(string $message): self;
}

interface DomainRegistrarProvider {
    public static function getKey(): string;          // 'namecheap'
    public static function getLabel(): string;        // 'Namecheap'
    public static function isConfigured(): bool;      // credentials present

    // Purchase
    /** @return array domain => ['available'=>bool, 'price_year'=>?string, 'premium'=>bool] */
    public function checkAvailability(array $domains): array;
    /** @return array ['expiry'=>string ISO-UTC]  Throws DomainRegistrarException. */
    public function register(string $domain, array $registrant, int $years): array;
    public function applyWhoisPrivacy(string $domain): void;

    // DNS publishes through the EXISTING DnsProvider stack — not this seam.
    public function dnsDriverKey(): string;           // 'namecheap' (DnsDriverRegistry key)
    public function dnsCredential(): array;           // credential array for that driver

    // Lifecycle
    public function getExpiry(string $domain): ?string;   // ISO-UTC or null
    public function inAccount(string $domain): bool;      // custody probe (graduation)

    // Graduation capability
    public function graduationMechanism(): string;    // 'account_push' | 'transfer_out'
}
```

Deliberately omitted until a registrar needs them: `renew()`,
`pushToEndUserAccount()`, `getTransferAuthCode()` — the v1 flow uses none of
them (Namecheap's push is manual; a graduated buyer transfers onward without
us). The seam grows when OpenSRS lands.

### NamecheapRegistrar

Transport: Guzzle, injectable (`__construct(?Client $http = null)`), modeled
on `LinodeComputeDriver`. Read `NamecheapDnsDriver` first — if its low-level
XML request method is cleanly liftable, extract a shared private client or
helper; otherwise mirror it. Every call is a GET to
`https://api.namecheap.com/xml.response` (sandbox:
`https://api.sandbox.namecheap.com/xml.response` when the sandbox setting is
on) with `ApiUser`, `ApiKey`, `UserName` (= ApiUser), `ClientIp`, `Command`.
Responses are XML; `<ApiResponse Status="ERROR">` carries `<Errors>` —
surface the error text. HTTP 5xx / 429 / network → `transient()`; API-level
errors → `terminal()` unless clearly rate-limit-shaped.

| Method | Namecheap command | Notes |
|---|---|---|
| `checkAvailability` | `namecheap.domains.check` (`DomainList`) | Availability + `IsPremiumName`/premium price. Regular price via `namecheap.users.getPricing` (`ProductType=DOMAIN`, `ActionName=REGISTER`, `ProductName=<TLD>`), cached per-TLD for the request. Refuse premium names in v1 (`available=false`, message says so). |
| `register` | `namecheap.domains.create` | `DomainName`, `Years=1`, `AddFreeWhoisguard=yes`, `WGEnabled=yes`, and **all four contact sets** (Registrant/Tech/Admin/AuxBilling) filled from the buyer's registrant block. Phone must be `+NNN.NNNNNNNNNN` — normalize before sending. Parse the expiry from the response (or follow with `getInfo`). |
| `applyWhoisPrivacy` | covered by `AddFreeWhoisguard`/`WGEnabled` on create | Method verifies/enables; a no-op if create already enabled it. |
| `getExpiry` / `inAccount` | `namecheap.domains.getInfo` | `inAccount()` = getInfo succeeds and reports the domain in this account; the "not found in account" API error → `false` (that is the graduation success signal, not an exception). |

Registrant array shape (built by the requirement, validated before charge):
`first_name`, `last_name`, `address1`, `city`, `state_province`,
`postal_code`, `country` (ISO-3166 alpha-2), `phone`, `email`.

### Credentials & settings

`plugins/server_manager/plugin.json` — add to `settingsGroups`:
`"domains": "Domain registration"`, and to `settings`:

```json
{ "name": "server_manager_namecheap_api_user",  "default": "", "group": "domains", "label": "Namecheap API user", "type": "text" },
{ "name": "server_manager_namecheap_api_key",   "default": "", "secret": true, "group": "domains", "label": "Namecheap API key" },
{ "name": "server_manager_namecheap_client_ip", "default": "", "group": "domains", "label": "Allowlisted client IP", "type": "text",
  "helptext": "The control plane's egress IP, allowlisted in the Namecheap API panel." },
{ "name": "server_manager_namecheap_sandbox",   "default": "", "group": "domains", "label": "Use Namecheap sandbox", "type": "checkbox" },
{ "name": "server_manager_domain_tlds",         "default": "com net org", "group": "domains", "label": "Offered TLDs", "type": "text",
  "helptext": "Space-separated. A domain outside these TLDs is refused at checkout." }
```

`secret: true` does **not** encrypt at rest. Add a "Domain registrar" card to
the provisioning-setup admin page that writes the key through the existing
`ProvisioningSetup::writeSetting()` + `encryptSecret()` path
(`plugins/server_manager/includes/ProvisioningSetup.php:100-137`), and read it
with the tolerant decrypt shape (`SecretBox::looksEncrypted()` → decrypt,
else plaintext pass-through), exactly like `readApiSecret()`.
`isConfigured()` = api_user, api_key, and client_ip all non-empty.

`plugins/store/plugin.json`:

```json
{ "name": "store_domain_registration_product_id", "default": "0", "group": "products",
  "label": "Domain registration product", "type": "select",
  "options_from": "StoreSettingOptions::donationProducts",
  "helptext": "Product added as the domain-year line when a purchase includes a managed domain." }
```

(Reuse `donationProducts` if it lists suitable products; otherwise add a
sibling static in `plugins/store/includes/StoreSettingOptions.php`.)

Core `settings.json` — the node-banner settings, present on every deployment,
written only by the control plane (declare `managed: true` so the settings
page does not offer them for editing; empty default = banner absent =
zero-config install preserved):

```json
{ "name": "managed_domain_name",        "default": "", "managed": true, "group": "general", "label": "Managed domain" },
{ "name": "managed_domain_expiry_time", "default": "", "managed": true, "group": "general", "label": "Managed domain expiry (UTC)" },
{ "name": "managed_domain_state",       "default": "", "managed": true, "group": "general", "label": "Managed domain custody state" },
{ "name": "managed_domain_manage_url",  "default": "", "managed": true, "group": "general", "label": "Managed domain take-ownership URL" }
```

## Data class: `rdm_registered_domains`

`plugins/server_manager/data/registered_domains_class.php` —
`class RegisteredDomain extends SystemBase`, prefix `rdm`, table
`rdm_registered_domains`, pkey `rdm_id`. Follow `docs/example_class.php`.

```php
public static $field_specifications = array(
    'rdm_id'                     => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
    'rdm_registrar'              => array('type'=>'varchar(32)', 'is_nullable'=>false, 'default'=>'namecheap'),
    'rdm_domain'                 => array('type'=>'varchar(255)', 'required'=>true, 'unique'=>true),
    'rdm_usr_user_id'            => array('type'=>'int8', 'is_nullable'=>false,
        'foreign_key'=>array('table'=>'usr_users','column'=>'usr_user_id','on_delete'=>'RESTRICT')),
    'rdm_external_order_item_id' => array('type'=>'int8', 'unique'=>true),
    'rdm_mgn_node_id'            => array('type'=>'int8'),   // resolved during fulfillment
    'rdm_buyer_email'            => array('type'=>'varchar(255)'),
    'rdm_registrant_sealed'      => array('type'=>'text'),   // SecretBox JSON envelope, see below
    'rdm_price_paid'             => array('type'=>'numeric(10,2)'),
    'rdm_status'                 => array('type'=>'varchar(20)', 'is_nullable'=>false, 'default'=>'pending',
        'allowed_values'=>array('pending','registered','active','failed')),
    'rdm_graduation_state'       => array('type'=>'varchar(20)', 'is_nullable'=>false, 'default'=>'operator_managed',
        'allowed_values'=>array('operator_managed','push_requested','push_sent','self_custody')),
    'rdm_ncp_username'           => array('type'=>'varchar(128)'),  // buyer's Namecheap account (step 2)
    'rdm_registered_time'        => array('type'=>'timestamp(6)'),
    'rdm_expiry_time'            => array('type'=>'timestamp(6)'),
    'rdm_dns_bootstrap_time'     => array('type'=>'timestamp(6)'),  // apex/www A published
    'rdm_dns_mail_time'          => array('type'=>'timestamp(6)'),  // mail record set published
    'rdm_ptr_time'               => array('type'=>'timestamp(6)'),  // PTR set (or skipped-stamped)
    'rdm_error'                  => array('type'=>'text'),
    'rdm_create_time'            => array('type'=>'timestamp(6)', 'default'=>'now()'),
    'rdm_update_time'            => array('type'=>'timestamp(6)'),
    'rdm_delete_time'            => array('type'=>'timestamp(6)'),
);
protected static $foreign_key_actions = [
    'rdm_usr_user_id' => ['action' => 'prevent', 'message' => 'This user owns a registered domain.'],
];
```

- **Status is coarse; step timestamps are the idempotency ledger.**
  `pending` → (registration succeeded) `registered` → (all three step
  timestamps set) `active`; `failed` is terminal-parked. Each null timestamp
  is an outstanding step the phase class retries; a set timestamp is never
  redone. Registration itself is guarded by status, not a timestamp: only a
  `pending` row attempts `register()`.
- **Registrant snapshot** is sealed with SecretBox pattern B
  (`data/backup_target_class.php:113-165`): accessor pair
  `seal_registrant(array $contact)` / `open_registrant(): ?array` storing
  `json_encode(['enc' => (new SecretBox())->encrypt(json_encode($contact))])`;
  tolerate a missing `secret_box_key` (store plaintext JSON — zero-config
  rule) and use `SecretBox::looksEncrypted()` on read.
- `MultiRegisteredDomain` option keys: `user_id`, `status`, `statuses`
  (array → `IN`), `graduation_state`, `external_order_item_id`, plus
  inherited `deleted`. Mirror `MultiCustomerCloudProvision`.
- No `$api_readable` / `$ai_readable` — this table is not exposed.

## Checkout

### `ManagedDomainRequirement`

`plugins/server_manager/includes/requirements/ManagedDomainRequirement.php`,
extends `AbstractProductRequirement`, `const LABEL = 'Managed domain'`,
self-registers at file bottom
(`AbstractProductRequirement::register('ManagedDomainRequirement', __FILE__);`)
and is `require_once`'d from `plugins/server_manager/serve.php` inside the
existing store-present guard. It then appears automatically in the product
edit page's "Info to collect before purchase" checkbox list
(`getGrouped()` → `plugins/store/admin/admin_product_edit.php:125-129`) — the
admin attaches it per product; no config needed (the checkbox path writes
`config => []`, which is fine). **Attach as a `pri_` row, never via
`extraRequirements()`** — provider-injected requirements never receive
`post_purchase()` (`cart_charge_logic.php:820-829` iterates
`AbstractProductRequirement::getProductRequirements()` only), and this type's
intake IS `post_purchase()`. Being a `pri_` attachment also keeps the domain
leg orthogonal to compute mode (shared-host products have no fulfillment
provider row at all).

Overrides:

- `getFormGroup()` → `'info'`.
- `render_fields($formwriter, $product, $existing_data)`: FormWriter fields
  `managed_domain_name` (textinput + an availability status element the JS
  fills), then the registrant block: `md_first_name`, `md_last_name`
  (prefill `usr_first_name`/`usr_last_name` from `$existing_data`),
  `md_address1`, `md_city`, `md_state_province`, `md_postal_code`,
  `md_country` (default `US`), `md_phone`, `md_email` (prefill `usr_email`).
  No hidden price field — the quote is display-only client-side and
  server-derived everywhere it matters. No hand-rolled HTML.
- `get_javascript()`: debounced (600ms) handler on `managed_domain_name` blur/
  input calling `joineryApi.post('server_manager/domain_check', {domain: v})`
  and rendering available/taken/price into the status element. `joineryApi`
  is injected on every public page by `PublicPageBase::public_header()` —
  nothing to include.
- `validate($post_data, $product)`: first the config gate (a registrar
  `isConfigured()` and `store_domain_registration_product_id > 0`, else
  "Domain registration is not available right now"); then syntax
  (`/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i` after
  lowercasing/trimming), TLD in `server_manager_domain_tlds`, registrant
  fields all present, country `/^[A-Z]{2}$/i`, email syntax, phone
  normalizable. Then the authoritative server-side quote:
  `checkAvailability([$domain])` — unavailable/premium → error string;
  transport failure → error asking to retry. Return `string[]`.
- `process($post_data, $product, $order_detail, $user)`: derives the
  server-side price (nothing price-shaped is trusted from the POST) and
  returns `[$data, $display]` where `$data` =
  `['managed_domain' => ['question' => 'Registered domain', 'answer' => $domain],
    'managed_domain_price_line' => $price,
    'md_first_name' => ..., /* every registrant field as a scalar */]`.
  `managed_domain_price_line` is the carrier `extra_cart_lines()` reads —
  `process()` output is merged into `$form_data` before that hook runs. The
  `question`/`answer` array shape is REQUIRED by
  `OrderItem::save_cart_data()` (`order_items_class.php:173`); scalars become
  `oir_` rows labelled by key (same PII exposure class as the existing
  Address/Email requirements). Note `process()` is called from
  `Product::validate_form()` with `$order_detail = null, $user = null`.
- `extra_cart_lines($form_data, $product)`: the domain-year line — see
  **The price line** below for the exact return shape. Returns `[]` only
  when `$form_data` carries no quote (i.e. the requirement wasn't part of
  this submission). A configured-but-missing setup cannot reach here
  silently: `validate()` fails with "Domain registration is not available
  right now" when `store_domain_registration_product_id` is unset or no
  registrar `isConfigured()` — a misconfiguration must never sell an
  unpriced or unregistrable domain.
- `post_purchase($data, $order_item, $user, $order)` — **the intake**:
  idempotently (probe `MultiRegisteredDomain(['external_order_item_id' =>
  $order_item->key, 'deleted' => false])`) create the `rdm_` row: domain,
  `rdm_usr_user_id = $user->key`, `rdm_external_order_item_id =
  $order_item->key`, `rdm_buyer_email`, sealed registrant from the `md_*`
  scalars in `$data`, `rdm_price_paid`, status `pending`. Wrap in try/catch
  that `error_log`s — `post_purchase` is best-effort by contract and must
  never break the charge path. (Store and Server Manager are one install on
  the control plane, so this is in-process. A remote-store topology would
  need a poll-based intake like `PollHostingOrders`; out of scope, noted in
  **Out of scope**.)

### The price line (extra-cart-lines hook)

**This adds one deliberate cart capability — requirement-contributed
companion lines** — and its scope is stated here explicitly so it cannot
creep:

> A product requirement may contribute additional cart lines whose price is
> derived server-side, from the buyer's validated answers, at the moment the
> item is added to the cart.

For domains that is the whole need: the buyer's answer (which name) selects
a real, per-TLD, changes-daily price that is charged as **passthrough** — so
it must come from a live registrar lookup at quote time. "Real-time" means
**live at quote time, frozen after**: the line is added with the quoted
number and never moves again; coupon recalculation re-reads the frozen value
(never the API). A cart held across days registers later at that day's
registrar cost — the delta is cents and accepted, like the availability race.

What this capability is **not**, by design:

- **Not a parent-price modifier.** The hook cannot discount or surcharge the
  product it is attached to; the hosting line's price is untouched. (The
  dead `affects_pricing()` hooks gestured at that; this is deliberately not
  that.)
- **Not a fees engine.** Every contributed line is a real product with a
  real version — visible in orders, reports, and refunds like any other.
  The product stays the platform's unit of sale.
- **Not buyer-priceable.** The contributed line's form data is constructed
  by the requirement server-side, never taken from the POST.

Why a *line* rather than a price fold-in — the load-bearing reason: a line
carries its own product version and therefore its own recurrence. The domain
year is one-time; the hosting line may be a subscription. A folded-in fee
would recur every cycle; a separate non-recurring line makes "one year, one
charge, never again from us" structurally true, and the receipt shows
exactly what the domain cost. The existing donation flow is the second
consumer of this shape (an answer producing a priced companion line),
currently hardcoded in `product_logic.php:107-121`; it stays untouched now
and migrates to the hook whenever next touched.

Two pieces, neither a special case:

**1. A general hook on the requirement base class.**
`plugins/store/includes/requirements/AbstractProductRequirement.php` gains:

```php
/** Extra cart lines this requirement contributes. Called after validate_form().
 *  @return array of ['product_id' => int, 'form_data' => array]  */
public function extra_cart_lines($form_data, $product) { return []; }
```

`plugins/store/logic/product_logic.php`, after `validate_form()` succeeds:
loop `$product->get_product_requirements()`, and for each returned line
`$cart->add_item(new Product($line['product_id'], TRUE), $line['form_data'])`
(skip lines whose product id is `<= 0` or fails to load). The store plugin
never learns what a domain is. The existing donation branch (`:107-121`)
stays as-is; it now has an obvious migration target instead of being the
pattern people copy.

**2. Pricing via the existing `'user'` price type — no `get_price()` change.**
`ManagedDomainRequirement::extra_cart_lines()` returns, when validation
produced a quote and `store_domain_registration_product_id > 0`:

```php
[['product_id' => (int)$setting,
  'form_data'  => [
      'user_price_override' => $server_quote,   // authoritative, server-derived
      'managed_domain'      => ['question' => 'Registered domain', 'answer' => $domain],
  ]]]
```

The domain product's version is configured with `prv_price_type = 'user'`,
so `Product::get_price()` (`products_class.php:314`) reads
`user_price_override` from the line's form data through the branch that
already exists. The buyer never posts this form data — the requirement
constructs it — so the value is trustworthy, and coupon repricing
(`ShoppingCart::update_items_for_coupon()` re-calling `get_price()` with
persisted form data) re-derives the same number with no live API call.
`prv_price_type = 'user'` is not a subscription interval, so the line never
enters recurring totals. The `managed_domain` array gives the line an
`oir_` row naming the domain on receipts and the admin order view.

Setup (runbook item, `specs/automated_hosting_provisioning_setup.md`): create
a "Domain registration (1 year)" product, not publicly listed, with one
version whose `prv_price_type = 'user'`, and select it in
`store_domain_registration_product_id`. The domain-year line lands as its
own `odi_order_items` row; `rdm_price_paid` records what was charged.

Accepted race: availability is verified at validate/process time, not
re-verified at charge. A domain taken in between fails at registration and
parks with an alert; the operator resolves with the buyer (refund or an
alternate name). Never half-registered.

### Availability action

`plugins/server_manager/logic/domain_check_logic.php`:

```php
function domain_check_logic(array $input): LogicResult
// syntax + TLD check → DomainRegistrarRegistry::configured() first provider
// → checkAvailability → LogicResult::render(['available'=>bool,'price_year'=>?string,'message'=>string])
// transport failure → LogicResult::error('Availability check failed — try again.')

function domain_check_logic_descriptor(): array {
    return [
        'description'      => 'Live availability and one-year price for a managed-domain checkout.',
        'requires_session' => true,
        'mutates'          => false,
        'requires_setting' => 'server_manager_namecheap_api_user',
        'auth'             => ['allow_guest' => true, 'requires_browser_session' => true],
        'input'            => ['domain' => ['type' => 'string', 'required' => true,
                                            'max_length' => 253, 'label' => 'Domain']],
    ];
}
```

Guest-reachable, browser-credential-only — the template is
`plugins/store/logic/checkout_check_email_logic.php`. Cache the per-TLD price
lookup within the request; no other rate limiting in v1 (the debounce plus
Namecheap's own limits suffice at current volume).

## Fulfillment: `ProvisionManagedDomains`

Phase class (`run(array $config): array`, no `.json`), appended to
`ServerManagerAdvanceProvisioning`'s sequence, so it runs `every_run`. It
iterates `MultiRegisteredDomain(['statuses' => ['pending','registered'],
'deleted' => false])`. Registrar and DNS driver come from overridable seams
(`protected function get_registrar()`, `get_dns_driver()`) mirroring
`ProvisionCustomerCloud::get_driver()` — the test injection point. If
`DomainRegistrarRegistry::configured()` is empty, return
`['status'=>'skipped','message'=>'No registrar configured']`.

**Per-row state machine:**

1. **Resolve the node + IP** (any status): find the compute leg by
   `rdm_external_order_item_id` — a `MultiCustomerCloudProvision(
   ['external_order_item_id' => ...])` row (customer_cloud) or a
   `mjb_management_jobs` row with that `mjb_external_order_item_id`
   (shared-host) → `ManagedNode` → stamp `rdm_mgn_node_id`. IP =
   `NodeDnsPlan::publicIp($node)`. No node or no IP yet → skip the row this
   tick (the compute leg is still working).
2. **`pending` → register.** `checkAvailability([$domain])` (guard against
   double-charge on ambiguous prior failures: if unavailable but
   `getExpiry()` returns a date — we already own it — treat as registered
   and continue); then `register($domain, $registrant, 1)` with
   `open_registrant()`; then `applyWhoisPrivacy()`. Success → status
   `registered`, `rdm_registered_time = gmdate('Y-m-d H:i:s')`,
   `rdm_expiry_time` from the result, clear `rdm_error`. Failure: registrar
   says taken → **terminal** (below); `DomainRegistrarException::$transient`
   → stamp `rdm_error = 'Transient (register): ...'`, stay `pending`, retry
   next tick.
3. **`registered`, `rdm_dns_bootstrap_time` null → bootstrap DNS.** Build a
   `DnsRecordPlan($domain, 'server_manager')`: apex `A @ → IP` (reuse/merge
   `NodeDnsPlan::forNode($node)`) plus `A www.<domain> → IP`. Publish via
   `DnsDriverRegistry::get($registrar->dnsDriverKey())` instantiated with
   `$registrar->dnsCredential()`, applied with
   `DnsReconciler::apply(..., APPLY_ADDITIVE)`. Success → stamp the
   timestamp. This unblocks the existing `ProvisionPendingSsl` retries —
   certbot now succeeds without the buyer touching DNS.
4. **`registered`, `rdm_dns_mail_time` null → mail DNS.** Requires the box
   installed (`mgn_install_state` NULL and, for customer_cloud, `cvp_status
   = 'done'`); otherwise skip this tick. Execute the node prepare utility
   over SSH (below), parse its JSON record list into a `DnsRecordPlan`
   (records already carry names/types/values from the box's own
   `InboundEmailSetupCheck::dnsPlan()` — topology, SPF, DKIM, DMARC,
   Joinery Direct all decided node-side), publish `APPLY_ADDITIVE` as in
   step 3. If the utility reports `dkim_ready = false`, publish what it
   returned but do NOT stamp the timestamp — retried next tick until DKIM
   is included. Success with DKIM → stamp.
5. **`registered`, `rdm_ptr_time` null → PTR.**
   `NodeReverseDns::setQuietly($node, 'mail.' . $domain)` (its forward-check
   gate needs the A record from step 4 to have propagated — a not-yet
   result is left unstamped and retried). A node with no linked cloud
   provision (shared-host) → stamp immediately with a note in the message;
   per-domain PTR is impossible on a shared IP and the host's own PTR
   already stands.
6. **All three timestamps set → `active`.** Push the initial banner state to
   the node (see **Banner push**). The existing welcome email
   (`JobResultProcessor::send_provisioning_welcome_email()`) is untouched.

**Terminal failure** (`fail_and_alert($row, $reason)`): status `failed`,
`rdm_error = $reason` (truncate 4000), then `EmailSender::quickSend($to,
'[managed-domain] Registration failed: ' . $domain, $body)` with the
recipient chain copied from
`ProvisionCustomerCloud::resolve_alert_recipient()`. Failed rows appear on
the operator queue page with a Retry button (clears `rdm_error`, sets status
back to `pending`). Never auto-retry a terminal failure.

**Node prepare utility** — `plugins/mailbox/utils/managed_domain_prepare.php`
(runs ON the node, CLI only):

```
php plugins/mailbox/utils/managed_domain_prepare.php <domain>
```

1. `mailbox_provision_domain($domain)` (idempotent —
   `plugins/mailbox/includes/provisioning.php`); abort with `{"ok":false}` on
   its error.
2. Ensure the standard DKIM keypair exists for the domain (the `mail`
   selector under `/etc/opendkim/keys/<domain>/` — invoke the same
   generation path the existing mailbox setup uses; executor: locate it from
   `InboundEmailSetupCheck`'s standard-selector reader
   (`readDkimKey`, `provision_dkim.sh`) and call it, don't reimplement).
3. Print one JSON line:
   `{"ok":true,"dkim_ready":bool,"records":[{"type","name","value","priority"}...]}`
   from `(new InboundEmailSetupCheck())->dnsPlan($domain)` (serialize via the
   plan's existing accessor or the new `toArray()`).

Control-plane side, execute it with a private `run_on_node($node, $cmd)`
modeled on `FleetProvisionSeeding::buildRemoteCommand()`/`runSsh()`
(`docker exec -i <sitename> php public_html/...` for docker nodes, direct php
for bare-metal; same ssh options). Parse the LAST line of stdout as JSON. A
node without the utility (pre-upgrade core) → treat as transient, stamp
`rdm_error`, retry; never wedge.

## Graduation: `ManagedDomainWatch`

Second phase class in the umbrella. Cheap early-outs; work only when rows
demand it.

- **Expiry sync:** for `active` rows in operator custody, refresh
  `rdm_expiry_time` via `getExpiry()` at most once per 7 days (stamp
  `rdm_update_time`).
- **Prompt threshold:** nothing is user-visible before
  `rdm_expiry_time − 6 months`. At the threshold, push banner state (below)
  — this is the buyer's first mention of graduation. Escalation levels are
  computed client-side on the node from `managed_domain_expiry_time`
  (banner styling at ≤30/14/7/1 days); the control plane just keeps the
  settings current.
- **Push queue:** `push_requested` rows → alert the operator once (same
  quickSend + recipient chain; idempotency: only on transition, which
  happens in the profile logic, not here). `push_requested`/`push_sent`
  rows → `inAccount($domain)`; `false` → `rdm_graduation_state =
  'self_custody'`, push banner state, and
  `EmailSender::quickSend($rdm_buyer_email, 'Your domain is now fully
  yours', ...)` naming the domain, their Namecheap custody, and the
  auto-renew reminder.
- **Never auto-renew. Never front a renewal.** This class watches and
  informs; it has no renewal call to make (the seam doesn't even expose
  one).

**Banner push** — write the four node settings over SSH with a psql
`INSERT ... ON CONFLICT (stg_name) DO UPDATE` heredoc, copied from
`FleetProvisionSeeding::buildRemoteCommand()` (values are non-secret; no
stdin dance needed): `managed_domain_name`, `managed_domain_expiry_time`
(ISO UTC), `managed_domain_state` (`operator_managed` before the 6-month
threshold is pushed as empty string — banner absent; from the threshold on,
the real `rdm_graduation_state`), `managed_domain_manage_url`
(`https://<control plane host>/profile/server_manager/domain`). Push on:
`active` transition (name+expiry only, state empty), 6-month threshold,
every `rdm_graduation_state` change. Failure to push is transient — log and
retry next tick.

**Node banner** — `includes/ManagedDomainNotice.php`,
`ManagedDomainNotice::render(): string` (empty string when
`managed_domain_state` is `''` or `self_custody`... no: render a one-time
"fully yours" variant on `self_custody`? No — keep it simple: banner renders
only while `managed_domain_state` is `operator_managed`, `push_requested`,
or `push_sent`). Content: plain-language line ("Your domain
`<name>` should move into your own registrar account before `<local
expiry date>` — that's when its renewal becomes yours"), urgency class by
days-to-expiry, one link to `managed_domain_manage_url`. Vanilla HTML/CSS
(`.jy-ui` conventions, no frameworks). Call sites: once in
`AdminPage::admin_header()` and once in the member-area chrome via
`PublicPageBase` (executor: place it where the member-area app chrome
renders its header notices; one include, guarded by
`get_setting('managed_domain_state') !== ''`).

**Buyer flow pages** (control plane, store-account session; plugin views
auto-discover — no serve.php route):

- `/profile/server_manager/domain` — lists the signed-in user's
  `MultiRegisteredDomain(['user_id' => ..., 'deleted' => false])` rows and,
  per row, renders the three-step flow keyed off `rdm_graduation_state`:
  `operator_managed` → steps 1+2 (step 2 is a FormWriter form posting the
  Namecheap username; on POST: validate non-empty, set `rdm_ncp_username`,
  state → `push_requested`, quickSend the operator alert, redirect);
  `push_requested` → "we're moving it — usually within a day";
  `push_sent` → step 3 checklist (accept the 7-day invitation, add payment
  method, enable auto-renew) with "we'll confirm automatically";
  `self_custody` → done state with the auto-renew reminder.
  All POSTs through `process_logic(profile_domain_logic(...))`; redirect
  after POST.
- The page is reachable before the 6-month threshold too (a buyer who finds
  it early may graduate early — allowed, harmless); only the banner waits.

**Operator queue** — `/admin/server_manager/domains`
(`$session->check_permission(10)`, `admin_header(['menu-id' =>
'server-manager', ...])`, adminMenu item in `plugin.json` under the existing
`server-manager` group: `{ "slug": "server-manager-domains", "title":
"Domains", "url": "/admin/server_manager/domains" }`). Three tables:
pending pushes (`push_requested`: domain, buyer, `rdm_ncp_username`,
dashboard how-to note, `AdminPage::action_button('Mark push sent', ...,
['hidden' => ['action' => 'mark_pushed', 'rdm_id' => ...]])`), failures
(`failed`: domain, `rdm_error`, Retry action button), and all registered
domains (status, custody, expiry via `get_local('rdm_expiry_time')`).
Actions are POSTs handled before display data loads; redirect after.

## Tests

All new tests carry the `@joinery-test` header
(`tests/lib/harness.php` contract; see `docs/testing.md`), live in
`plugins/server_manager/tests/`, and mock the network — no live Namecheap
calls in any tier.

| Test | Tier | Asserts |
|---|---|---|
| `domain_registrar_registry_test.php` | safe | Registry discovers `NamecheapRegistrar`, keys by `getKey()`, `configured()` empty without credentials, `reset()` rescans. |
| `namecheap_registrar_test.php` | safe | With a Guzzle `MockHandler`: `checkAvailability` parses available/taken/premium XML; `register` sends Years=1, WGEnabled, all four contact sets, normalized `+N.N` phone; sandbox setting switches the base URL; 5xx→`transient`, API error→`terminal`; `inAccount` false on the not-found error code. |
| `managed_domain_requirement_test.php` | db | `validate()` rejects bad syntax/TLD/missing contact and accepts a good submission (registrar seam stubbed); `process()` output survives `OrderItem::save_cart_data()` (oir row `Registered domain` = domain); `post_purchase()` creates exactly one `rdm_` row across two invocations; sealed registrant round-trips; `extra_cart_lines()` returns the configured product with `user_price_override` = the server quote (and `[]` when the form data carries no quote), and a `prv_price_type='user'` version prices that line correctly through `Product::get_price()`. |
| `provision_managed_domains_test.php` | db | With injected fake registrar/DNS driver and a fabricated node+provision: `pending`→`registered`→timestamps→`active` across ticks; taken-domain → `failed` + alert recipient resolution; transient error leaves status and stamps `rdm_error`; already-owned recovery path (unavailable + getExpiry set) does not double-register; shared-host row stamps PTR as skipped. |
| `managed_domain_watch_test.php` | db | `push_requested` + fake `inAccount()===false` → `self_custody`; banner-state push command built (assert the generated psql command string, don't execute SSH); no prompt state pushed before the 6-month threshold. |

Register `rdm_` fixtures with `harness_register_row()`. Model the fabricated
order-item/requirement fixtures on
`plugins/server_manager/tests/provisioning_setup_test.php:281-285`.

## Executor gotchas (verified facts, do not rediscover)

- `affects_pricing()` / `get_modified_price()` on requirements are **dead
  code** — zero call sites. The second-product cart line is the mechanism.
- `post_purchase()` fires only for `pri_`-attached requirements, not
  `extraRequirements()`-injected ones.
- `OrderItem::save_cart_data()` array values must be
  `['question'=>...,'answer'=>...]` or the writer breaks; scalars are fine.
- `Product::get_price()` is re-called on coupon changes with persisted
  `form_data` — the domain price must live in `form_data`.
- Namecheap `setHosts` replaces the whole host list — always go through
  `DnsReconciler`, never the driver's raw call.
- `NodeReverseDns` has a forward-check gate (A record must resolve first)
  and refuses nodes without a cloud provision — use `setQuietly()` and treat
  not-yet as retry.
- `secret: true` settings are NOT encrypted at rest; encryption is the
  consumer's job (ProvisioningSetup pattern).
- `ProvisioningSetup::TASK_CLASSES` (`ProvisioningSetup.php:46`) still names
  retired task classes (`PollHostingOrders` etc. are phase classes now, not
  tasks). Do not copy that list; if touching that file anyway, fix it to
  name `ServerManagerAdvanceProvisioning`.
- Scheduled-task rows: the umbrella task already exists; adding phases means
  editing the umbrella's sequence only — no new `sct_` rows, no `.json`.
- Freshly provisioned nodes run the current release and will have the
  prepare utility; do not add version-probing beyond the transient-failure
  path.
- `mgn_host` may be a hostname on manual nodes — always take the IP from
  `NodeDnsPlan::publicIp($node)`.
- No `new DateTime()`; DB times are `gmdate('Y-m-d H:i:s')` UTC, displayed
  with `get_local()`.

## Namecheap verification items (before build)

Namecheap-specific facts to confirm live (sandbox first), not design choices:

1. **API eligibility.** Namecheap gates API access behind account thresholds
   (20+ domains, or $50+ balance, or $50+ spend in two years) and requires
   client-IP allowlisting. Confirm the operator account qualifies and
   allowlist the control plane's egress IP.
2. **Sandbox parity.** Validate register → setHosts → getInfo end to end in
   the Namecheap sandbox before touching production.
3. **Registrant = buyer at registration.** Confirm `namecheap.domains.create`
   accepts distinct registrant contacts per domain and that WHOIS reflects the
   buyer, with free WHOIS privacy applied.
4. **Push mechanics.** Documented and confirmed against Namecheap KB
   (2026-08-24): the Change Ownership push is free and immediate with no
   60-day wait; the recipient accepts via a 7-day email link (or an
   auto-accept account setting); DNS/host records, Domain Privacy, and
   auto-renew settings survive the push; an expired domain cannot be pushed.
   Re-confirm with a live push during the first real graduation — DNS
   preservation especially.
5. **Pricing source.** Confirm live TLD pricing via API so the checkout line
   item matches what is charged, including promo vs. renewal price divergence.

## Decisions (all resolved)

- **D1 — Where DNS lives. RESOLVED: registrar-hosted DNS first**, via the
  existing `NamecheapDnsDriver` + `DnsReconciler`. Because DNS management is
  tied to registrar-account access, it moves with custody at graduation —
  the records themselves survive the push. Authoritative DNS on the buyer's
  box is deferred as a later sovereignty enhancement.
- **D2 — Take-ownership UX. RESOLVED: guided three-step push flow** —
  push-only, first surfaced at six months, never during setup.

## Out of scope

- Any second registrar (the seam is the extension point; Namecheap only).
- The subdomain sandbox (`specs/subdomain_sandbox_tier.md`) — a trial tier,
  not a domain tier; nothing in this pipeline touches it.
- Box-authoritative DNS (D1 — later enhancement).
- Remote-store intake: `post_purchase()` intake assumes store and Server
  Manager share one install (true of the control plane). A split topology
  would need a `PollHostingOrders`-style REST intake leg.
- Premium domain names (refused at availability check).
- Migrating existing bring-your-own-domain sites onto managed registration.
- An EPP/transfer-out flow, renewal via the operator, or buyer-selected
  nameservers.

## Documentation

When built, update — current-state voice only (no migration narration):

- `plugins/server_manager/docs/overview.md` — the domain leg: the
  `ManagedDomainRequirement`, the registrar seam and registry, the
  provisioning phases and state machine, ownership/graduation semantics, the
  operator queue, and the node banner.
- `plugins/store/docs/overview.md` — add a single **"How a cart line gets
  its price"** section consolidating the now-scattered pricing surface into
  one place: the cart line tuple, price resolution (version price /
  `prv_price_type = 'user'` via `user_price_override` / the optional-donation
  product), requirement-contributed companion lines (the scope statement
  above, with the donation piggyback described as one instance of the same
  shape), coupon repricing from persisted form data, and a pointer to the
  own-once guard points. Fold the existing "Optional piggyback donation"
  section into it rather than adding a parallel section — the cart's docs
  should get more coherent from this change, not longer. Current-state voice.
- `plugins/store/docs/product_requirements.md` — the `ManagedDomainRequirement`
  row in the built-in types table, and the new `extra_cart_lines()` hook in
  the interface summary — including its scope statement (server-priced
  companion lines from validated answers; not a parent-price modifier, not a
  fees engine, not buyer-priceable). While there, correct the two stale claims the audit
  found: `post_purchase()` runs for attached (`pri_`) requirements only, and
  remove `affects_pricing()`/`get_modified_price()` from the interface
  summary (dead hooks).
- `docs/settings.md` — the registrar credential settings and the managed
  node-banner settings.
- `plugins/mailbox/docs/overview.md` — the prepare utility.
- `specs/automated_hosting_provisioning_setup.md` — registrar credential +
  IP-allowlist + domain-product activation steps.
