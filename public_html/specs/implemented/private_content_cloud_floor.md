# Private content and cloud models: the member's floor

**Status: BUILT 2026-10-01 (uncommitted, owner commits).** Work item 8 of
`specs/protection_levels_platform.md`, from `specs/implemented/calendar_contacts_private.md`
Q1. The owner's rule, recorded there: **never an absolute block on the AI
account a member chooses, but very clear, and impossible by mistake, to send
Private content to a cloud that is not private.** Current state is in
`plugins/joinery_ai/docs/overview.md` § Private content and cloud models.

## Today (read from the tree 2026-10-01)

- The only cloud consent is mail's per-domain `ied_ai_processing_consent`
  (`local` / `trusted` / `cloud`), folded into every pipeline recipe's model
  requirement by `RecipeVaultScope::consentTrustFloor()`.
- Every model in the catalog carries a trust class (`local` / `trusted` /
  `cloud`, `AiEndpointRegistry::trustForModel()`); a requirement states a
  trust floor and the resolver refuses a pin below it.
- **Sealed content from any service reaches a chat only in a Private chat.**
  `ChatTurnContext::sealedReadsAllowed()` is the chat's level: the generic
  reader (`query_model`) excludes sealed rows for a Standard chat exactly as it
  does for a locked vault, so a Standard chat never goes hot. A Private chat
  reads sealed Drive rows, calendar entries and mail, and its own transcript
  is sealed content too.
- Nothing compares a Private chat's model against what its owner allows: a
  Private chat pinned to a cloud model sends its sealed history and every
  Private row it reads to that cloud, with only the composer's passive
  sensitivity banner and the optional Local models only add-on in the way.

## The rule

One member-level setting, **where my private content may be read by AI**,
with mail's three values and mail's words: *Stay on my hardware* (default),
*My hardware, or a vendor I have accepted*, *Any configured AI endpoint,
including cloud*. It is the most permissive endpoint trust class the member's
Private content may reach (`usr_private_ai_consent`, `PrivateContentConsent`).

It binds every chat that holds Private content: a Private chat, and a Standard
chat whose transcript became sealed-derived (`aic_egress_restricted`). Such a
chat's model must sit within the setting. Two gates, one predicate:

1. **At send**, `chat_send` refuses a turn whose model is outside the setting
   before anything is persisted, in words that name the model's class, the
   setting, and both ways out (pick a local model; or allow it in Security
   settings). Never a silent switch to another model.
2. **At resolution**, `AiModelRequirementBuilder::forConversation()` tightens
   the turn's trust floor to the setting, so the worker refuses a pin outside
   it even when the model changed under the turn. The Local models only
   add-on stays the stricter pin on top.

The composer shows the gate before it bites: on a Private chat the models the
setting excludes are greyed out with a note, the way the Local models only
add-on greys out everything off the box.

Loosening the setting needs a recent second factor (as loosening a domain's
consent does); tightening needs none. The setting lives on the Security page
under the Encrypted Vault, saved through the `private_ai_consent` action.

## What this does not change

- Mail's per-domain consent governs pipeline recipes, as before.
- Standard chats, which never open sealed content.
- Agent-mode recipes: their trust floor is stated by the operator or falls
  back to the platform's conservative default; an in-window recipe on a cloud
  pin is a deliberate operator statement, not a member's mistake.
- No add-on and no level: this is where a member's own Private content may
  travel, one answer across Drive, the calendar and Private chats.

## Decisions

- **D1 — the setting is per member, not per service or per chat.** A per-chat
  answer is what the Local models only add-on already is; a per-service answer
  asks the same question three times. One answer, raised deliberately.
- **D2 — the default is local.** The owner's rule is "impossible by mistake";
  a default that sends Private content to a cloud is the mistake.
- **D3 — a chat that has opened sealed content of any kind follows the
  setting, mail included.** The transcript holds decrypted private content
  whatever its source; mail's per-domain consent governs recipes, where the
  content never enters a member's transcript.

## Tests

`plugins/joinery_ai/tests/private_ai_consent_test.php`: the default; the
comparison; a Private chat on a cloud model is refused at send with the
reasons stated and nothing persisted; a Standard chat is not; raising the
setting admits it; the resolver's floor follows the setting; loosening asks a
second factor of a member who has one.
