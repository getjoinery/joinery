# Credential Help Guides

**Status: BUILT 2026-07-26.** All six build-plan steps are implemented: the
`help_modal` FormWriter option, `credentialGuide()` on the DNS driver contract
with guides on all ten API drivers, `configFields()` / `configGuide()` on all
five OAuth providers, a registry-driven `/admin/admin_oauth_providers`, in-place
OAuth app registration in the publish box, and docs. Covered by
`tests/dns/dns_records_test.php` (269 checks, safe tier); the whole safe tier is
green at 64/64.

Four things to know about how it landed:

- **The open permission decision was resolved as recommended.** Saving an OAuth
  app registration requires permission 10
  (`DnsPublishBox::OAUTH_CONFIG_PERMISSION`), including from the mailbox setup
  page, which itself opens at 5. A permission-5 admin sees a sentence saying a
  full administrator sets it up once, and no submit button — the credential is
  shared with sign-in, so a lesser admin overwriting it could break login for
  everyone.
- **A `caution` slot was added to the guide shape**, which this spec did not
  anticipate. Cloudflare's "not the Global API Key" and GoDaddy's OTE warning
  read as instructions when numbered among the clicks; they now render apart from
  the steps.
- **Two defects in the shared modal surfaced and were fixed at that layer**, not
  worked around: `dialog.jy-ui` had no height cap, so a six-step guide pushed its
  own buttons off screen (it is now a flex column with a scrolling content area),
  and the guide's `<ol>` lost its numbers to the kit reset.
- **A permission-5 admin is shown the sentence, not the guide.** The spec said
  "sees the guide read-only"; rendering a trigger needs a field to hang it on, and
  the step-by-step is only actionable by someone who can actually save. The note
  conveys what is missing, which was the point.

## What this is

The platform asks an operator for a credential at the moment it needs one — a
scoped DNS API token when they press Apply on a publish diff — and then leaves
them to work out where their vendor hides it. Fifteen vendors, fifteen different
dashboards, and the field gives no clue which one.

This puts a **How do I get this?** link beside every credential field. It opens a
modal with the steps for that specific vendor, and where the vendor's own form
needs something from us — a callback URL, an allowlist IP address — the modal
hands it over as a copy button rather than asking the operator to transcribe it.

Scope is three pieces: the guide capability on DNS drivers, in-place OAuth app
registration so no credential entry ever sends someone to a different page, and
the FormWriter affordance that makes both reusable by the next thing that asks
for a key.

## Why

The friction here is asymmetric. An operator who knows their provider loses ten
seconds to a link they don't click. An operator who doesn't know is stopped
outright: the box is asking for a "Cloudflare API token with Zone · DNS · Edit"
and nothing on screen says that lives under Profile → API Tokens, or that the
Global API Key sitting right next to it is the wrong credential and will fail
with a permissions error that reads like our bug. Setup attempts end there.

There is also a defect underneath this. Two of the five OAuth providers —
DigitalOcean and DNSimple — **cannot be configured anywhere in the software
today.** `settings.json:20-23` carries all four of their slots, but
`adm/admin_oauth_providers.php` renders a hardcoded field pair per provider and
only covers Google, Linode and Microsoft. Adding a provider means remembering to
edit a second file, and twice now nobody did. Adding two more hardcoded pairs
reloads the same gun, so this spec drives that page from the registry instead.

## 1. The guide capability

One more static on `DnsProvider`, alongside `credentialFields()` and
`prerequisiteNote()` — read before any credential exists, like the rest of the
static half of the contract.

```php
public static function credentialGuide(): ?array {
    return array(
        'title'     => 'Create a Cloudflare API token',
        'url'       => 'https://dash.cloudflare.com/profile/api-tokens',
        'url_label' => 'Open Cloudflare API tokens',
        'steps'     => array(
            'Sign in at dash.cloudflare.com and open My Profile → API Tokens.',
            'Create Token → use the Edit zone DNS template.',
            'Under Zone Resources, choose Include → Specific zone → this domain.',
            'Continue to summary → Create Token, then copy the token shown once.',
        ),
        'copy'      => array(),   // values the vendor's form needs from us
    );
}
```

`DnsDriverBase` returns `null` by default, so no driver is obliged to have one
and the link simply doesn't render when there isn't one.

**The content standard matters more than the mechanism.** Fifteen mediocre
guides are worse than five good ones, because a guide that doesn't match what
the operator sees on screen costs more trust than an absent link:

- Steps are what the operator **clicks**, in order, using the vendor's own words
  for its buttons and menus. Not a description of the outcome.
- Name the **exact** scope. "Zone · DNS · Edit", not "DNS permissions".
- Say what *not* to pick where a wrong-but-adjacent credential exists —
  Cloudflare's Global API Key, an AWS root access key, a GoDaddy production vs.
  OTE key.
- No prose about why. The box already explains that nothing is stored.
- Every URL and every menu path **verified against current vendor docs at the
  time of writing.** A stale deep link is worse than no link: it teaches the
  operator that the box doesn't know what it's talking about.

**`prerequisiteNote()` keeps its job and is not folded into this.** A
prerequisite is a blocking setup step the operator must do or the publish fails
— Namecheap's IP allowlist — and it is shown unconditionally in the box, never
hidden behind a link someone might not click. The guide is how-to; the note is a
warning. Namecheap has both, and they read differently.

## 2. OAuth app registration, in place

**Owner decision: any configuration a credential moment needs happens at that
moment.** No credential entry point sends the operator to a different page in
our own software to finish the job.

The five OAuth drivers (Linode, Google Cloud, Azure, DigitalOcean, DNSimple) ask
the operator for nothing per-publish — consent covers that. What they need is
the deployment's OAuth app registration: `oauth_{key}_client_id` and
`oauth_{key}_client_secret`. When `isConfigured()` is false, the box collects
that registration itself.

Three changes:

- **`configFields()` on `OAuth2Provider`.** Defaults to the two settings the
  provider's own key implies — the interface already documents `getKey()` as the
  settings prefix — so a provider declares nothing unless it's unusual.
  Microsoft overrides it to add the tenant it already reads.
- **`/admin/admin_oauth_providers` renders from `OAuth2ProviderRegistry::all()`**
  and saves through the same declaration. DigitalOcean and DNSimple appear
  immediately; a sixth provider needs no edit here at all.
- **The DNS publish box renders the same `configFields()`** through the same save
  helper, so the two surfaces cannot drift.

**The ordering is forced by OAuth and worth stating.** Registering the app at the
vendor requires our callback URL, and the client id and secret must be persisted
*before* we redirect away to consent. So the sequence is: the modal shows the
registration steps with the callback URL as a copy button → the operator
registers the app at the vendor → pastes id and secret into the box → one POST
saves them and continues to consent. Two round trips through the vendor is
inherent, not a design choice.

**This is not a weakening of the ephemeral rule.** An OAuth app registration
cannot write DNS. A per-publish user grant is still required, still arrives at
`/oauth_callback`, and is still discarded inside the request that used it. The
client id stores plain and the secret through `SecretBox`, exactly as
`admin_oauth_providers_logic` does today. Nothing DNS-write-capable becomes
stored.

### Open decision — who may save an app registration

**Needs an owner decision before step 4 is built.** The two pages hosting the box
sit at different permission levels: `plugins/mailbox/logic/admin_mailbox_setup_logic.php`
is `check_permission(5)`, `plugins/server_manager/views/admin/node_detail.php`
is `check_permission(10)`, and `/admin/admin_oauth_providers` is 10. These app
registrations are not DNS-only — `oauth_google_client_id` is the same value
Google sign-in uses, so a permission-5 admin saving one could break sign-in for
every user.

- **Recommended: gate the in-place save at 10.** A permission-5 admin sees the
  guide read-only, worded as needing a full admin. That is not a redirect, it's
  an accurate boundary — they genuinely cannot do it.
- **Alternative: allow 5 for providers not used by sign-in**, keeping 10 on the
  shared ones. More permissive, at the cost of a per-provider rule that has to
  stay correct as providers gain uses.

## 3. `help_modal` on FormWriter inputs

The trigger is a declarative input option, not markup emitted by each caller.

Two reasons. `helptext` is `htmlspecialchars`-escaped at render
(`FormWriterV2HTML5.php:76` and eight sibling call sites), so a link cannot live
inside it — every caller would hand-roll its own trigger beside the field. And
"how do I get this credential" recurs well beyond DNS: cloud storage targets,
payment providers and inbound mail all ask for keys the same way.

`visibility_rules` is the precedent — normalized into the input's data in
`FormWriterV2Base.php` and consumed at render, with no per-form JS wiring:

```php
$form->passwordinput('dns_cred_api_token', 'Cloudflare API token', array(
    'help_modal' => $driver_class::credentialGuide(),
));
```

Rendering emits a small trigger button plus the guide content as an inert
template, and delegated JS opens it with `JoineryModal.open(content, { buttons })`
— the kit's existing content mode, loaded on every theme. `data-jy-copy` is
already delegated, so copy buttons inside the modal work with nothing extra.

## The fifteen guides

| Provider | Credential | Where it lives | Verified |
|---|---|---|---|
| Linode DNS | OAuth app | cloud.linode.com → My Profile → OAuth Apps | live-verified driver |
| Namecheap | API key + user + allowlist IP | ap.www.namecheap.com → Profile → Tools → API Access | live-verified driver |
| Cloudflare | API token (Zone · DNS · Edit) | dash.cloudflare.com → My Profile → API Tokens | live-verified 2026-07-26 |
| Google Cloud DNS | OAuth app | console.cloud.google.com → APIs & Services → Credentials | docs only |
| Azure DNS | OAuth app | portal.azure.com → Entra ID → App registrations | docs only |
| DigitalOcean | OAuth app | cloud.digitalocean.com → API → Applications | docs only |
| DNSimple | OAuth app | dnsimple.com → Account → Automation | docs only |
| AWS Route 53 | IAM key pair | IAM → Users → Security credentials | docs only |
| GoDaddy | sso-key pair | developer.godaddy.com → API Keys | docs only |
| Gandi LiveDNS | Personal access token | admin.gandi.net → User settings | docs only |
| Vultr DNS | Bearer PAT | my.vultr.com → Account → API | docs only |
| Hetzner DNS | API token | dns.hetzner.com → API tokens | docs only |
| Porkbun | API key + secret | porkbun.com → Account → API Access | docs only |
| deSEC | API token | desec.io → Token management | docs only |
| Name.com | Username + API token | name.com → Account → API for resellers | docs only |

Paths in this table are the starting point for research, **not** the guide
content — each is re-verified against current vendor docs when its guide is
written. The three live-verified drivers get theirs from the real run-through.

Porkbun and deSEC additionally require enabling API access per-domain before any
key works; if that holds on verification it belongs in `prerequisiteNote()`
rather than the guide, same as Namecheap's allowlist.

## Documentation

- `docs/dns_management.md` — a `credentialGuide()` row in the driver capability
  table, and a short passage on the guide/prerequisite split.
- `docs/oauth2.md` — `configFields()`, registry-driven provider config, and
  in-place app registration.
- `docs/formwriter.md` — the `help_modal` input option.
- `docs/admin_pages.md` — `JoineryModal.open()` content mode. Only
  `confirm`/`alert`/`prompt` are documented today, which is why the shared modal
  was missed once already.

## Tests

Extend `tests/dns/dns_records_test.php` (safe tier, no credential or network):

- Every `CREDENTIAL_API` driver returns a guide, or `null` deliberately.
- Every guide has a non-empty title, at least two steps, an `https` URL, and no
  placeholder text (`YOUR_`, `example`, `TODO`).
- No `copy` value contains secret-shaped material — the copy list is for values
  we give the vendor, never values we hold.
- Every `configFields()` entry names a setting that exists in `settings.json`.
- `OAuth2ProviderRegistry::all()` and the settings page cover the same set —
  the assertion that would have caught DigitalOcean and DNSimple.

## Build plan

1. `help_modal` — `FormWriterV2Base` normalization, `FormWriterV2HTML5` render,
   delegated JS in `assets/js/base.js`.
2. `credentialGuide()` on `DnsProvider` with a `null` default in
   `DnsDriverBase`; wired into `dns_publish_box.php`.
3. `configFields()` on `OAuth2Provider` and all five providers;
   `admin_oauth_providers` driven from the registry.
4. In-place OAuth registration in the box, gated per the open decision above.
5. The fifteen guides, each verified as it is written.
6. Docs and tests.

Steps 1–3 are independent of the open permission decision and can land first.

## Out of scope

- Guides for credential fields outside DNS. The `help_modal` option makes cloud
  storage targets and payment providers cheap to add later; none are touched
  here.
- Any change to what is stored, or to how long a publish grant lives.
- Moving a domain's nameservers, still not offered.

## Version bumps

`includes/dns/DnsProvider.php` 1.0 → 1.1, `includes/dns/DnsDriverBase.php`,
`includes/dns/dns_publish_box.php` 1.1 → 1.2,
`includes/oauth/OAuth2Provider.php` 1.0 → 1.1,
`adm/admin_oauth_providers.php` 1.2 → 1.3, plus each driver that gains a guide.
