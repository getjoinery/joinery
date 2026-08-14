# Joinery Messenger

The member-facing messages app: open `/profile/messenger`, see your
conversations, and talk to another member or a small group the way you would on
any modern chat app — instant-feeling delivery, read receipts, typing
indicators, replies, emoji reactions, photos and files, group names and
membership.

Two people whose accounts live on **different Joinery instances** can also
message each other. The message travels over Joinery Direct — the signed
instance-to-instance channel — not through any third party.

Conversations carry a protection level from the platform ladder: **Standard**,
**Private** (sealed at rest) and **Guarded** (Private with the doors guarded).

## Where things are

| Path | What |
|---|---|
| `views/profile/index.php` | The app, at `/profile/messenger` |
| `logic/messenger_*_logic.php` | The `/api/v1` actions the app calls |
| `includes/Messenger.php` | The shared middle: gates, settings, and the one description of a conversation and a message |
| `includes/MessengerTyping.php` | Typing state, in APCu, never in the database |
| `includes/MessengerUploads.php` | Photos and files on their way in |
| `includes/MessengerAttachmentGate.php` | Who may open an attachment |
| `includes/MessengerFederation.php` | The sending end of cross-instance chat |
| `includes/ChatDirectHandler.php` | The receiving end — the `chat` kind on Joinery Direct |
| `includes/bootstrap.php` | Sealed Vault hooks: the attachment decrypt hook and the key-rotation re-seal |
| `tasks/DrainChatOutbox.php` | Retries cross-instance messages that have not landed |
| `assets/css/messenger.css` | This app's layout (the bubbles come from the shared kit) |
| `assets/js/messenger.js` | One renderer for the first paint and every later change |

**The data layer is core, not here.** Conversations, participants, messages,
reactions, attachments, key grants and remote peers all live in `data/` at the
platform root, because the iOS member surface and other plugins build on the
same rows. The developer guide for all of it is
[docs/social_features.md](../../../docs/social_features.md) §
Messaging / Conversations.

## Turning it on

Activating the plugin is the whole installation. It seeds its settings, adds
**Messages** to the member menu, and points the header envelope icon at the app;
the older `/profile/conversations` pages hand off to it. Deactivating reverses
all of that and touches no message.

Cross-instance chat additionally needs the **mailbox** plugin (it supplies the
member's address, the contact list that authorizes an incoming message, and the
Direct endpoint) and a Direct signing identity for the sending domain. Without
those the app works normally and simply does not offer cross-site chat.

## Settings

| Setting | Default | What it does |
|---|---|---|
| `messenger_active` | on | The app and its actions |
| `messenger_default_protection_level` | `standard` | What a new conversation starts at |
| `messenger_max_group_size` | 32 | People per group |
| `messenger_max_attachment_mb` | 25 | Per file |
| `messenger_poll_thread_seconds` | 3 | How often an open conversation asks for new messages |
| `messenger_poll_list_seconds` | 12 | How often the list refreshes with nothing open |

The platform's own `messaging_active` switch is honoured too: with member
messaging off, the app is off.

## Two things worth knowing before changing it

**There is no realtime service, and that is deliberate.** The app polls. A held
`text/event-stream` response pins a php-fpm worker per open tab, and the fleet
runs small worker pools — a handful of members with the app open in two tabs
each would exhaust one. Each poll is a single indexed query keyed on a message
id and releases the worker immediately. The upgrade path is kept open at no
cost: `add_message()` emits `NOTIFY message_events`, so a future service LISTENs
on it and pushes over its own transport while this endpoint becomes the
fallback.

**A protected conversation has one key with many holders.** Every other sealed
model on the platform seals to a single owner; this one wraps one conversation
key separately to each participant, and the server reads a message only while
somebody who holds a wrapping is present. Anything touching `msg_body` on a
sealed row has to go through the model — a raw `SELECT` gets ciphertext, which
is exactly what it should get.

## Tests

```
php tests/run.php db --filter=messenger_core        # groups, cursor, reactions, receipts, legacy actions
php tests/run.php db --filter=messenger_sealed      # protection: grants, raise, locked reads, rotation
php tests/run.php db --filter=messenger_federation  # the chat kind: ingest, dedup, control payloads
```
