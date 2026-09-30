# Pushed new-mail notifications through a Joinery-run relay

**Status: DRAFT 2026-09-30, after `specs/fortress_mobile_apps.md`.** That
spec gives both phone apps new-mail notifications by polling (its R15).
This one adds pushed notifications, for the deployments whose owners
choose to use Joinery's notification service. Polling stays as the path
for everyone else and the fallback when the service is unreachable.

## The problem in plain terms

A polling app learns about new mail when the phone's OS lets it wake: on
iOS minutes to hours apart, at the OS's discretion; on Android every 15
minutes at best. For a mail app that is a late notification, not a
notification.

A pushed notification is sent with the app publisher's credentials: an
iPhone push with the publisher's APNs key, an Android push with the app's
Firebase project. Both belong to whoever ships the binary, which is
Joinery, not the self-hosted deployment. A deployment cannot push for the
Joinery app on its own, and shipping the APNs key to every deployment
would let any one of them push to every user of the app. This is the
passkey domain problem in another coat, and the industry answer is the one
Bitwarden ships for self-hosted installs: **a push relay run by the app
publisher**, which deployments hand a token and an opaque payload.

**Owner decision, 2026-09-30:** the relay is opt-in per deployment. The
deployment's owner chooses to use Joinery's notification service; a
deployment that does not gets polling.

## Design

- **What the server knows.** Mail arrives in `storeMessage`,
  `storeDirectMessage` and, on the relay path, `storeRelayPending`. Each
  emits one signal, `mailbox.message_arrived {alias_id, message_id,
  level}`, after commit (the signal bus, `docs/signals.md`). A push
  subscriber turns it into one nudge per registered device of the
  mailbox's holders that opted in to that mailbox.
- **Device tokens.** New core model `PushDeviceToken`
  (`pdt_push_device_tokens`: user, `apk_api_key_id`, platform, token,
  `client_app`, mailboxes opted in, create and last-seen times, failure
  count). `push_register` (session key only) upserts by key; sign-out and
  revocation delete the key's tokens (a revoked key's tokens go with it,
  the `SyncDevice` cascade pattern). A machine key can never register.
- **The nudge carries nothing readable.** Payload `{site: <deployment
  id>, ref: <opaque per-token alias handle>}`: no address, no user, no
  sender, no subject, at any level. The relay learns that a deployment
  nudged a token at a time and nothing else. The phone maps `ref` to a
  mailbox from its own cached `mailboxes` list.
- **What the phone shows.** The same notification the polling path shows
  (`fortress_mobile_apps.md` R15): "New message in {address}", with sender
  and subject for a Standard mailbox only. iOS: a visible push with
  `mutable-content`; a Notification Service Extension rewrites it and, for
  a Standard mailbox, fetches the newest row. Android: an FCM data
  message; the app's messaging service builds the notification. Tapping
  opens that mailbox.
- **The opt-in.** A setting `push_relay_url` (blank = off, the default)
  on the deployment's settings page, with a plain line of what the
  service learns ("that this site nudged a phone at a time; never who,
  from whom, or what"). The apps register a token only when the
  deployment advertises the relay; otherwise they poll.
- **Fallback.** A relay that refuses or is unreachable leaves the phone on
  polling; the apps keep the polling schedule running whether or not a
  push token is registered, and suppress a polled notification a push
  already showed (the same `newest_unread_id` high-water mark).
- **The relay path.** A relay-fronted message becomes a pending row at the
  next pull, so the nudge fires at pull time; the pull interval bounds the
  delay, as it does for arrival itself.
- **Open question carried from the mailbox docs:** a Private mailbox could
  show sender and subject if the server generated the notification text at
  the ingest moment, before sealing, and the phone fetched it once. Decide
  here whether that text may exist at all, and whether a per-mailbox
  "generic notifications" switch covers it.
- **Not built:** notifications for anything but mail arrival (calendar,
  messenger) ride the same signal-to-push subscriber later; the subscriber
  is generic, the signal is mail's.

## Work packages

### WP1. Platform

- The `mailbox.message_arrived` signal from the three store paths;
  `PushDeviceToken` and `push_register`; the push subscriber and the relay
  client (Direct-identity-signed POST, `push_relay_url` setting, failure
  backoff, token retirement after repeated rejections); token deletion on
  sign-out and revocation.
- **Tests:** `tests/functional/api/push_register_test.php` (upsert by key,
  machine key refused, tokens gone after revocation); a subscriber test
  that one arrival nudges each opted-in token once with a content-free
  payload.

### WP2. Relay service (`push.getjoinery.com`)

- A small Go program in the mail relay's mould (`provisioning/`, a
  `build.sh`, provisioned by script, disposable, never agented): verifies
  the deployment signature, forwards to APNs (token auth, HTTP/2) and FCM
  (HTTP v1), records nothing but counters.
- **Credentials:** an APNs key from a paid Apple Developer Program account
  and a Firebase project for the Android app. Neither exists yet; both are
  owner actions before live pushes can be sent.
- **Tests:** Go tests (signature, a changed byte refused, APNs and FCM
  request shapes against recorded fixtures).

### WP3. Apps

- **iOS:** `aps-environment` entitlement, registration, the Notification
  Service Extension, per-mailbox opt-in carried to the token.
- **Android:** Firebase Messaging and the messaging service.
- **Tests:** a gate leg per platform on the Mac mini: the simulator
  (`simctl push`) or emulator delivers a nudge and the app shows the
  notification with the mailbox address and, for Standard, the sender and
  subject.
