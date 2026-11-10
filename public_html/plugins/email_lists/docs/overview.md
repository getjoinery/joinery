# Email Lists & Campaigns

Mailing lists, newsletter signup forms and email campaigns, with MailChimp sync. A paid plugin (`Joinery-Commercial`, `requires_entitlement`).

Core keeps everyday sending and the **direct message** (an admin's message to one member, a group, or an event's registrants or waiting list; see [Email System § Direct Messages](../../../docs/email_system.md#direct-messages)). This plugin adds lists and campaigns on top, through the same extension points any plugin uses.

## What it owns

| Piece | Where |
|---|---|
| Models | `data/`: `MailingList` (`mlt_`), `MailingListRegistrant` (`mlr_`), `Email` (`eml_`), `EmailRecipient` (`erc_`), `EmailRecipientGroup` (`erg_`) |
| Campaign admin | `/plugins/email_lists/admin/admin_emails` and the pages it links to |
| List admin | `/plugins/email_lists/admin/admin_mailing_lists` |
| Public pages | `/lists`, `/list/{slug}` and `/email_lists/confirm` (declared in core `serve.php` with `plugin => 'email_lists'`) |
| Signup block | the `list_signup` page component (`views/components/`, logic in `logic/components/`) |
| Settings | the plugin's settings tab, `/admin/admin_settings_plugins?plugin=email_lists`: `emails_active`, `default_mailing_list`, `mailing_lists_active`, `mailing_list_provider` and the provider's credentials (all `legacy_core`, keeping their names) |
| Signal | `newsletter.signup` |
| Tasks | `SendCampaigns` (every run, on at install), `PrunePendingSignups` (daily, on at install), `MailingListSyncTask` (daily, turned on by hand) |

## How it plugs into core

| Core page or moment | Extension point | Plugin class |
|---|---|---|
| Member profile and app dashboard | `ProfileDashboardRegistry` (`mailing_lists`) | `EmailListDashboard` |
| Admin user page | `AdminUserPanelRegistry` | `MailingListsUserPanel`: the member's lists (editable) and the campaigns they were sent |
| Group and event admin pages | `AdminEntityPanelRegistry` (`group`, `event`) | `CampaignsToAudiencePanel` |
| Registration and admin add-user forms | `SignupFieldRegistry` | `EmailListPreferences::register_signup_field()`: "add me to the list", its label naming the default list |
| Email preferences page | `ContactPreferencesRegistry` | `EmailListPreferences::register_preferences_section()` |
| Campaign audiences | `RecipientGroupProviderRegistry` (`mailing_list`) | `MailingListRecipientProvider` |
| Checkout | `AbstractProductRequirement::register()` | `NewsletterSignupRequirement`, while the store is active |
| SEO and photos | `SeoPageMetadata`, `EntityPhotoRegistry` (`mailing_list`) | from `serve.php` |
| Tasks of other plugins | `requires_plugins` | event_manager's `WeeklyEventsDigest` queues through `CampaignSender::queue_to_list()` |

## Subscriptions

Every change to someone's lists goes through `EmailListSubscriptions`: `set_lists($user, $ids, $scope, $source)` asks for the given lists and unsubscribes from the others within `$scope` (the lists a page offered; null for every list); `subscribe($user, $ids, $source)` only asks. Asking goes through `request()` (below); leaving is at once.

## Double opt-in

Nobody gets list mail until they press **Confirm** on a link sent to the address. On by default (`email_lists_double_opt_in`); off, every signup subscribes at once, recorded as `single_opt_in`, and the settings tab says the site then has no proof that subscribers own their addresses.

`request($user, $email, $list_ids, $source)` decides:

- **Confirmed at once** for the signed-in owner of a verified address asking for themselves (`verified_owner`), for the list's provider (`provider`, MailChimp sync; a remote `pending` is not imported), and with double opt-in off.
- **Otherwise pending**: a `MailingListPending` row per list (`mlp_mailing_list_pending`: address, user if any, list, the token's sha256, source, request time, last sent time, IP, visitor id), and one confirmation email naming every list. A list the address already waits on with a link mailed is not asked again, so a stranger cannot add lists to someone's unclicked link; one waiting with no email yet (registration's, or one a limit held) goes out with the next request's email. A row is stamped sent only once the provider took the email.

The email goes through core `EmailSender` (everyday mail, allowed on included email), greets by address and names only the lists: nothing a visitor typed. Its link is `/email_lists/confirm?t=…`: opening it shows the lists and a Confirm button and changes nothing (mail scanners open links); the button's POST (`EmailListSubscriptions::confirm()`) creates the account if there is none (named from the address, never from anything typed) and verifies it, since the click proves the inbox; an account that already existed is subscribed but not verified, because someone else may have registered it with that address. It subscribes with source `link`, time, IP and address, sends each list's welcome email, pushes to MailChimp, fires `newsletter.signup`, records the list-signup conversion against the visitor who asked, and deletes the request. The page sends `Referrer-Policy: no-referrer`. Links last 30 days; `PrunePendingSignups` deletes expired requests daily.

**Limits.** One confirmation email per address per day, `email_lists_confirm_limit_per_ip` (10) per requester per day (an IPv6 requester's /64), `email_lists_confirm_limit_per_site` (200) per day. Past a limit the request is kept without its email: the list's page shows it, with a Resend, the next request for it mails it, and the mailing lists page shows how many were held today. A resent link keeps its first request's date, and its email says how many days are left. The signup forms keep their honeypot, anti-spam question and CAPTCHA; every address gets the same answer, so a form never says whether an address has an account here, and no account is made for a typed address until it confirms.

**Registration.** The registration box makes a pending request (source `register`) and sends no list email: the account's activation is the one email, and its label names the list. When the address is proved (`account.email_verified` with a method in `EmailListSubscriptions::PERSON_PROOFS`: the Verify button, a password reset, a confirmed address change, a list confirmation link), `on_email_verified()` confirms the account's `register` requests (source `activation`). An admin marking an address verified, or any other method, confirms nothing.

**Consent follows the address.** Each subscriber records the address that confirmed (`mlr_confirm_email`), and audiences (`MailingList::get_subscribed_users()`, `count_subscribed_users()`, `is_consented()`, and the send-time check) include them only while the account still has it. A proven address change carries the consent to the new address; one made without proof (by an admin) drops the person from audiences until the new address confirms, and their preferences page and the admin user panel say so, with a Resend.

**Admins.** Ticking a list for someone on the admin user panel, or adding them with the box ticked, asks them by email. A list's page shows who is waiting, with Resend, Delete and Mark confirmed (for consent collected another way, recorded as source `admin` under the admin's id, for the address the account has now), and marks a subscriber whose address changed without proof. Saving choices never re-asks a list the person is already on: a lapsed one is renewed by its own Resend.

**The record.** `mlr_confirm_time`, `mlr_confirm_source` (`link`, `activation`, `verified_owner`, `provider`, `admin`, `single_opt_in`, `existing`), `mlr_confirm_ip`, `mlr_confirm_by_usr_user_id` and `mlr_confirm_email` are written only through `MailingList::add_registrant()`, which takes the consent; the API can write none of them nor `mlr_delete_time`, sets the list and the person only at creation, and the model refuses a new row, a left one brought back without a fresh consent, or a row moved to another list or person. Subscribers from before consent was recorded are marked `existing`. Deleting an account deletes its subscriptions through `MailingListRegistrant::permanent_delete()`, which also unsubscribes the address at the provider.

**The footer link only unsubscribes.** Through an email footer's preferences link (signed out, the link's hash naming the person) the list section offers only the lists the person is on and can only take them off: the hash never changes and travels with every forward of an email.

## Sending campaigns

`CampaignSender` sends: `send_due()` (the `SendCampaigns` task and the "Send queued emails" button), `send_email()`, `send_test()`, and `queue_to_list()` for code that writes a campaign. Each recipient is checked again at send time: someone who left the campaign's list, or unsubscribed from its contact type, since it was queued is marked unsubscribed and not sent to. A recipient is marked sent only once the provider took the message, so a pass that stops resumes where it stopped.

## Campaign templates

The plugin ships two email templates, in `plugins/email_lists/email_templates/`, which migration `el_002` adds where a site has no row of that name: `newsletter-1`, the "Standard HTML Template" the campaign editor offers, and `mailing_list_footer`, the footer every campaign goes out with (`CampaignSender::footer()`; where a site has deleted that row, the plugin's own text of it, so a campaign to a list always carries its unsubscribe link). The footer's list block carries the unsubscribe link, and shows when the campaign went to a list. On a site whose `default_footer` carried that block, its body becomes the campaign footer, so an edited footer keeps its wording. Core's `default_footer` has no list block.

## Campaigns need the site's own email provider

A site that sends through the included Joinery email (`EmailSender::usesIncludedMail()`) has a monthly allowance meant for its everyday mail, and one campaign to a list could use it up and stop its password resets. `CampaignRules` refuses queueing a campaign there (`Email::queue()` and `CampaignSender::queue_to_list()` throw `CampaignRefusedException`), and holds one queued before the site moved back to included email: `send_due()` leaves it queued with `eml_held_reason` set, which its page shows, and sends it on the first run after the site has its own provider. The compose and campaign pages say so before anyone writes. Lists, signups, MailChimp sync and a test send to yourself stay allowed. The weekly events digest skips with the reason instead of stacking a held campaign each week.

## Sync with the provider

`MailingListSync::run()` reconciles each list that has a provider list id with the provider: whichever side changed last wins; a bounced address marks the account bouncing; a remote `pending` is not imported. It runs from `MailingListSyncTask` and from the "Sync with provider now" button on the lists page, never from a page view.

## Upgrade

A site that had mailing lists before the plugin existed gets it turned on once by `update_database` (any row in `mlt_mailing_lists`, or a campaign aimed at a list; marker `_email_lists_autoactivate_v1`). A site without them leaves it off, and the tables are created when it is first turned on.

## Mailing list providers

- Interface: `plugins/email_lists/includes/mailing_list_providers/MailingListProvider.php`
- Abstract base: `plugins/email_lists/includes/mailing_list_providers/AbstractMailingListProvider.php` — concrete providers extend this rather than implementing the interface directly
- Typed exception: `plugins/email_lists/includes/mailing_list_providers/MailingListProviderException.php` — `isRetryable()` distinguishes transient (rate limit, 5xx, network) from permanent (list missing, credentials revoked) failures
- Manager: `MailingListService` (`plugins/email_lists/includes/MailingListService.php`) — `MailingListService::getProvider()`, `getAvailableServices()`, `getProviderSettings($key)`
- Implementations: `plugins/email_lists/includes/mailing_list_providers/*Provider.php` (MailChimp, …)

**Required methods on the interface:**
| Method | Purpose |
|---|---|
| `getKey()` / `getLabel()` | Identity for the `mailing_list_provider` setting and admin dropdown |
| `getSettingsFields()` | Setting field definitions rendered dynamically by the admin UI |
| `validateConfiguration()` | Cheap, no-network check that required settings are non-empty |
| `validateApiConnection()` | Live API ping for the admin "Connection OK?" panel |
| `subscribe()` / `unsubscribe()` | Idempotent operations on a remote list. Email is normalized to lowercase; throw `MailingListProviderException` on provider-side failures, `\InvalidArgumentException` on bad input |
| `getSubscribers()` | Opaque-cursor pagination — caller passes `null` first, then echoes back `next_cursor` until it is `null`. Returns the canonical four-value `status` enum (`subscribed`, `unsubscribed`, `bounced`, `pending`) |

**Non-universal methods** (e.g. `getLists()`) live on `AbstractMailingListProvider` with a default body that throws `\BadMethodCallException`. Providers override them when their API supports the operation; consumers wrap calls in `try/catch \BadMethodCallException`. Future non-universal additions (sequences, broadcasts, list stats) follow the same pattern, keeping additions non-breaking for existing provider classes.

**Adding a new provider:**

1. Create `plugins/email_lists/includes/mailing_list_providers/MyServiceProvider.php`:
   ```php
   require_once(PathHelper::getComposerAutoloadPath());
   require_once(PathHelper::getIncludePath('includes/mailing_list_providers/AbstractMailingListProvider.php'));

   class MyServiceProvider extends AbstractMailingListProvider {
       public static function getKey(): string { return 'myservice'; }
       public static function getLabel(): string { return 'My Service'; }
       // … implement the remaining required methods
   }
   ```
2. Add any provider-specific settings to the plugin's `plugin.json` (factory defaults seed automatically), with `show_when` on `mailing_list_provider`.
3. Pick the provider on the plugin's settings tab (`/admin/admin_settings_plugins?plugin=email_lists`) — the dropdown auto-populates from your new class.

No other files need to change. The model layer (`MailingList::sync_subscribe()` / `sync_unsubscribe()`) and `MailingListSync` call the configured provider through `MailingListService::getProvider()`.

**Canonical subscriber status enum.** `getSubscribers()` returns one of four `status` values regardless of provider. Each provider class maps its native vocabulary into this set:

| Canonical | Meaning | MailChimp | ConvertKit | Listmonk |
|---|---|---|---|---|
| `subscribed` | Actively receives mail | `subscribed` | `active` | `enabled` |
| `unsubscribed` | Opted out (incl. spam-complained) | `unsubscribed` | `cancelled`, `inactive`, `complained` | `disabled` |
| `bounced` | Email invalid; provider stopped sending | `cleaned` | `bounced` | `blocklisted` |
| `pending` | Double opt-in not yet confirmed | `pending` | (n/a) | (n/a) |

`complained` (spam-marked) collapses into `unsubscribed` — for the platform's purposes the action taken on the local row is the same. Mapping is typically ~5 lines of `switch` per provider.

**Out of scope (deliberate deferrals).** Three categories of methods are intentionally NOT on the interface today; they will be added when a concrete second provider needs them:

- **Webhooks.** Real-time event notifications (`registerWebhook`, `verifyWebhookSignature`) are not part of the contract. When added they go on the required interface — every modern provider supports them.
- **OAuth flows.** Some providers (HubSpot, Klaviyo) use OAuth2 instead of API keys. The current `getSettingsFields()` shape can't express an OAuth flow. When a provider needing OAuth is added, that provider class implements an additional method (e.g. `getOAuthAuthorizationUrl()`) outside the formal interface; the admin UI checks for its presence via `method_exists`.
- **Programmatic list creation.** `createList()` is not on the interface. Current workflow: admins create lists in the provider's UI and enter the ID locally. Add programmatically only when a concrete use case appears.

Non-universal future methods (sequences, broadcasts, list stats) get default throwing bodies on `AbstractMailingListProvider` so additions stay non-breaking for existing provider classes.

