# Push Notifications

A member who turns push on in a browser sees their notifications on that computer or phone even when the site is closed. Push is Notify's third channel, beside in-app and email (see [Notifications](notifications.md)): anyone who gets the in-app notification also gets it on every browser they turned push on in, unless they unticked push for that event.

## For members

`/profile/notification_preferences` (and `/admin/admin_notification_preferences` for admins) carries a **Push notifications** box:

- **Turn on in this browser** asks the browser for permission, subscribes it, and registers it to the member.
- **Turn off in this browser** retires it.
- **Send a test** pushes a test notification to this browser now and says what the push service answered.

Below, the **Push to my browsers** list has one box per event, ticked wherever the event itself is on. Signing out of a browser turns push off in it.

iPhone and iPad Safari only allow push for a site added to the Home Screen and opened from there; the box says so in a browser that cannot receive push.

## Pieces

| Piece | Where | Does |
|---|---|---|
| Destinations | `data/push_destinations_class.php` (`psh_push_destinations`) | One row per place a member can be alerted: a browser (`web`), or a phone app (`ios`, `android`). `psh_endpoint` is unique; `psh_details` holds what differs by kind (a browser's `p256dh` key, `auth` secret and user agent). Not exposed through the REST API. |
| Drivers | `includes/PushDriver.php` | One transport per kind: `register()` checks what a client sent, `send()` delivers a batch, `retired()` hears a destination go. `carriesText()` says whether readable text may travel through it. |
| Browser driver | `includes/WebPushDriver.php` | Sends straight to the browser maker's push service with the site's own key pair (VAPID, `minishlink/web-push`), encrypted to the browser (RFC 8291). |
| Sender | `includes/PushSender.php` | Who gets what, the content rule, sending, and the one cleanup path. |
| Service worker | `/service-worker.js` (served from the site root by a static route in `serve.php`) | Shows the notification and opens its link on click, focusing a tab already on the site. |
| Page script | `assets/js/joinery-push.js`, rendered by `PushPanel::html()` | The box above. |

Nothing sends for a kind with no driver; core ships the `web` driver. A phone driver registers itself with `PushSender::register_driver()`.

## Sending

Notify calls `PushSender::queue($user_id, $message)` for each person it creates an in-app notification for, when their preference for that signal leaves push on (`ntp_push_enabled`, default on). Anything else that alerts a member outside Notify calls `queue()` or `send()` itself.

A message is an array:

| Key | |
|---|---|
| `title`, `body` | The full text |
| `link` | A site path or URL the notification opens |
| `tag` | Groups notifications on the device: a newer one with the same tag replaces the older. Notify uses the signal name. |
| `generic_title`, `generic_body` | Optional; default the site name and "You have a new notification." |
| `protected` | Send the generic text only |

`queue()` holds messages to the end of the request. Under PHP-FPM the response is finished first (`fastcgi_finish_request()`), so nobody waits on a push service; elsewhere (the CLI, scheduled tasks) they are sent at shutdown. `send()` sends now and returns each destination's outcome; the test button uses it. A push that fails is not retried: in-app and email are the record, push is the nudge, and a push service already holds a message a day (`WebPushDriver::TTL`) for a browser that is offline.

### The content rule

`PushSender::render()` decides what text a driver is handed, so no caller has to. A message marked `protected`, or a driver whose `carriesText()` is false, gets the generic text; the link is kept. Notify marks a message protected when the signal's `notify` block says `"push_text": "generic"` or the dispatch payload carries `push_protected` true — anything carrying sealed content (a Private conversation, a Private mailbox) is declared or dispatched that way. Titles are cut to `MAX_TITLE` and bodies to `MAX_BODY` characters.

## Destinations and cleanup

`PushSender::register()` checks a registration through the kind's driver and stores it. An endpoint already registered becomes the new registrant's: whoever holds the browser is the one alerted. A member keeps at most `MAX_PER_USER` destinations; registering another retires the least recently seen.

The browser driver accepts only an `https` endpoint on a browser push service (`WebPushDriver::PUSH_SERVICE_HOSTS`: Google's, Mozilla's, Apple's, Microsoft's), on the default port, with a 65-byte P-256 browser key and a 16-byte secret. The server POSTs to that address, so nothing else is accepted.

`PushSender::retire()` is the one path that removes a destination. Everything that ends one goes through it:

| What | Call |
|---|---|
| Signing out on a browser | `SessionControl::logout()` → `retire_device()`, by the browser's device cookie (`joinery_push_device`, set when push is turned on) |
| Turning push off in a browser | `push_unsubscribe` |
| An app key revoked | `retire_for_api_key()` |
| A push service answering 404 or 410 | `PushDriver::RESULT_GONE`, retired at once |
| `MAX_FAILURES` failed sends in a row | retired; a success clears the count |
| A new site key pair | `retire_kind('web')` (below) |

A deleted user's destinations, and those registered under a deleted app key, go with them (`$foreign_key_actions`).

## The site's key pair

Browser push signs every send with the site's key pair (VAPID), and every browser subscription is bound to the public key it was made with. The pair is one sealed setting, `push_vapid_keys` (JSON, base64url `public` and `private`), declared in `settings.json` as a `regenerable` [sealed secret](sealed_secrets.md) with `WebPushDriver::provisionKeys` as its recipe.

- `update_database` mints it cold on every install and upgrade where none exists.
- A pair that cannot be opened here (a database copied from another site) leaves push off until the sealed-secret reconcile re-mints it.
- A new pair retires every browser destination: they were bound to the old key, and on a copied database they were another site's browsers. Members turn push on again; the page notices a subscription made with an older key and replaces it.

## API actions

All `POST /api/v1/action/{name}`, browser session only, signed in.

| Action | Does |
|---|---|
| `push_config` | `{available, public_key, registered}`; pass `endpoint` to ask whether this browser is registered to you |
| `push_subscribe` | Register `endpoint`, `p256dh`, `auth` to you, and give the browser its device cookie |
| `push_unsubscribe` | Retire `endpoint` if it is yours |
| `push_test` | Send a test to `endpoint` if it is yours; answers `{sent, result, reason}` |

## Tests

`tests/functional/push/push_notifications_test.php` (test-db) covers the key pair, registration and its refusals, what a browser receives (it decrypts the request with the browser key it made, and checks the VAPID signature), the content rule, every cleanup path, Notify's push channel and the four actions. No send leaves the machine: `WebPushDriver::useHttpHandlerForTest()` answers them.
