# Protection levels fold — three rungs in code, add-ons as flags

**Status:** IMPLEMENTED 2026-09-23 — built, reviewed (B1–B13 fixed), db gate green for every suite it touches. Written 2026-09-23. Implements work items 1 (add-on
extension), 3 (the fold), 4 (docs) and 5 (spec sweep) of
`specs/protection_levels_platform.md` (decision R4). Does **not** build
end-to-end mail (`specs/DEFERRED_client_custody_mail.md` stays parked): mail
ends this build with two cards, Standard and Private, plus add-ons.

## What changes for a member

- Mail domain editor: two cards, **Standard** and **Private**. Under Private,
  an **Extra protection** block with two add-ons: **Seal at the relay** (a
  switch, offered once a relay exists) and **Only send while I'm signed in**
  (the existing protect ceremony, reached from here). The Fortress card is
  gone until end-to-end mail exists.
- AI chat: two levels, Standard and Private, plus a **Local models only**
  add-on under Private (offered when a local model is configured).
- Messenger: two levels plus one add-on, **Nothing leaves unsealed** (no
  message text in notifications; no unencrypted federation).
- Drive: unchanged — Standard / Private / Fortress already.
- Wherever a level is shown (chips, badges, setup checklist), the active
  add-ons show with it.

## Mapping — every old-level behaviour to its new owner

**Mail (`ied_security_level = 'fortress'`)**

| Behaviour today | Keyed on after the fold |
|---|---|
| Sealed at rest / "private or fortress" checks (~40 sites: `seals_content`, alias resolvers, `domainHasSealingMailbox`, health SQL, ceremony SQL, AI candidate SQL, `MailboxAliasConfig::isSealedAtRest`, lowering/raising rank maps) | level `private` (drop `fortress` from every IN list / rank map) |
| Relay seals to owner's vault key (`RelayMapExporter::sealTargetForAlias`) | `ied_relay_seals_to_owner` |
| Relay setup required (`mailbox_setup_scope.php` `$needs_relay`, setup relay step, ceremony `relay_fronted` row, `receive_mode.php` row) | `ied_relay_seals_to_owner` (relay required only when the add-on is wanted) |
| Origin-probe skip (`InboundEmailHealth::originProbeTarget`) | `ied_relay_seals_to_owner` |
| DKIM key minted at raise, `$fortress_handoff`, "Sending identity" box, `mailbox_protect_state`, `sendProtectionResult` row, setup-hints "never activated", "Finish Fortress" step | the SEND add-on: offered at any Private domain; the step shows when the member has **asked** for it (see below), finished state = `ied_is_protected_identity` |
| Window caps (`bootstrap.php` `onWindowCaps`) | "hardened" predicate (below) |
| Mandatory independent 2FA (`SessionControl::must_enroll_2fa_for_fortress`, `admin_user_logic` fact, ceremony second-factor row) | removed — owner 2026-09-23: the add-ons do not force a second factor; a passkey alone is enough (encouraged later, not forced) |
| Joinery Direct custody and receive posture | already `seals_content()` — no change; comments that say "Fortress custody" are corrected to "Private" |

**The SEND add-on's two states.** Today "raised to Fortress but not yet
protected" is the in-progress state the setup checklist nags about. After the
fold that intent needs its own record, or a Private domain could not tell
"hasn't asked" from "asked, unfinished". New column
`ied_send_lock_requested` (bool). Switching the add-on on sets it and starts
the ceremony (key minted when the owner is unambiguous, as the raise does
today); the ceremony's activation sets `ied_is_protected_identity` as today.
Switching it off clears both through the existing deactivation path.

**The hardened predicate.** One function replaces `maxSecurityLevelForUser`'s
`=== 'fortress'` checks: `InboundEmailDomain::userHasHardenedDomain(int
$user_id): bool` — true when any domain the user owns, or any live mailbox
they hold a grant on, belongs to a Private domain with
`ied_relay_seals_to_owner` or `ied_send_lock_requested` on. It drives the
window caps, which read it live at window time; there is no session cache and
no 2FA gate (owner 2026-09-23: add-ons do not force a second factor).
`maxSecurityLevelForUser` stays for its remaining standard/private callers.

**Chat (`aic_security_level = 'fortress'`)** → `private` +
`aic_local_models_only` (bool). Every `LEVEL_FORTRESS` model-pin gate
(`ChatLevel`, `ChatSend`, `ChatRunner::defaultModelForLevel`,
`AiModelRequirementBuilder`, `chat_set_capabilities` logic+view,
`chat_view_body` option + `applyFortressModelGate` JS, `chat_shared_logic`
`fortress_available`) asks the flag. The flag is one-way like the level was
(on stays on). `ChatSeal::levels()` = standard/private. Setting
`joinery_ai_default_chat_level` loses `fortress`; new bool setting
`joinery_ai_default_chat_local_only`.

**Messenger (`cnv_protection_level = 'guarded'`)** → `private` +
`cnv_sealed_exits_only` (bool, one-way). `is_guarded()` becomes
`sealed_exits_only()`; the notification-preview blank
(`conversations_class.php` ~:392) and `MessengerFederation` `require_sealed`
ask it. `Conversation::LEVELS` = standard/private. Setting
`messenger_default_protection_level` loses `guarded`; new bool setting
`messenger_default_sealed_exits_only`. **No Local-models add-on**: messenger
has no AI participant (`specs/messaging_ai_participant.md` is unbuilt), so the
Guarded card's "not to a cloud model" had nothing behind it; the add-on
arrives with that build.

**Core.** `ProtectionLevel`: `GUARDED` removed; `ORDER` =
standard/private/fortress; docblock describes three rungs + add-ons.
`ProtectionLevelPicker`: Guarded copy removed; learns add-ons — a consumer
passes `addons => [key => [label, protects, costs, checked, disabled]]`; they
render as switches under the Private card, emitted through FormWriter, with
FormWriter `visibility_rules` hiding them unless Private is selected.
`VaultUnlock::FORTRESS_IDLE_CAP_SECONDS` / `FORTRESS_ABSOLUTE_CAP_SECONDS` →
`HARDENED_IDLE_CAP_SECONDS` / `HARDENED_ABSOLUTE_CAP_SECONDS`; comments and
the PluginBootstraps log line say "hardened caps".

## Data update (runs on every node at upgrade)

Plugin migrations (each guards table **and** column existence — an inactive
plugin keeps a stale table; see memory `reference_migration_plugin_table_guard`):

- mailbox: `ied_security_level='fortress'` → `'private'`,
  `ied_relay_seals_to_owner=true`. `ied_send_lock_requested` is set only where
  `ied_is_protected_identity` is already true (owner 2026-09-23: a domain that
  never finished its sending lock converts without one, rather than showing it
  as unfinished). `ied_is_protected_identity` untouched. `iea_security_level='fortress'`
  (should not exist; resolvers read it) → `'private'`.
- joinery_ai: `aic_security_level='fortress'` → `'private'`,
  `aic_local_models_only=true`. Setting `joinery_ai_default_chat_level =
  'fortress'` → `'private'` + `joinery_ai_default_chat_local_only = true`.
- messenger: `cnv_protection_level='guarded'` → `'private'`,
  `cnv_sealed_exits_only=true`. Setting `messenger_default_protection_level
  = 'guarded'` → `'private'` + `messenger_default_sealed_exits_only = true`.

Idempotent; each prints what it changed. Sealed system messages already in
messenger history that say "set this conversation to Guarded" stay as written
(sealed history is not rewritten).

## Wording (current-state, everywhere)

- Never "Fortress" for mail, chat, messenger, Direct, relay, window caps or
  2FA. Fortress appears only for Drive, the password vault, and the reserved
  mail constant.
- Add-on names and card copy (from `protection_levels_platform.md`):
  - **Seal at the relay** — "A hacked server can't read mail that arrives
    while you're away." Cost: "New mail waits to be processed until you sign
    in."
  - **Only send while I'm signed in** — "Nobody can send mail as you, even
    from a hacked server." Cost: "This domain can only send while you're
    signed in."
  - **Local models only** — "Nothing in this chat is sent to an outside AI
    company." Cost: "Only AI models running on your own hardware can answer."
  - **Nothing leaves unsealed** — "No message text appears in notifications
    or crosses to another server unencrypted." Cost: "Notifications don't
    show the message, and people on servers without encryption can't be
    reached."
- No 2FA message: the add-ons carry no second-factor requirement (owner
  2026-09-23). The Extra protection note states only the short unlock window.
- The Go relay and its README keep working unchanged (they read `key_kind`,
  never a level); their comments are reworded in the same sweep.

## Tests

Every suite in the inventory that uses `'fortress'` / `'guarded'` as a
fixture is updated to the new shape (sealed-at-rest fixtures → `private`;
relay/send/local/unsealed fixtures → `private` + the flag). New checks: the
three migrations convert and are idempotent; `userHasHardenedDomain` true for
each add-on and for a grant-holder, false for plain Private; picker renders
add-ons only under Private; `security_level('fortress')` is refused for mail.

## Out of scope / open

- **Q1 — the relay fleet product.** `relay_admin.php` creates a store tier
  and product named "Fortress" / "Fortress Hosting" (`pro_link
  fortress-hosting`) for relay fleet slots; nothing reads the names. New
  creations get "Relay Hosting"; existing product rows on the control plane
  are an owner decision (renaming changes a public URL).
- End-to-end mail (the Fortress mail card) — parked.
