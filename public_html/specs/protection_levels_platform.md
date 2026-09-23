# Platform Protection Levels — one three-rung vocabulary for every service

**Status: DRAFT 2026-08-02, reshaped 2026-09-23 — doctrine + gap matrix; the
matrix below is the work list. Companion spec:
`specs/implemented/sealed_content_egress.md` (the egress choke point is what
makes the Private rung meaningful platform-wide).**

**2026-09-23 reshape (owner, R4 below):** the ladder is three rungs —
Standard / Private / Fortress — and **Fortress means end-to-end encrypted**,
nothing less. The former fourth rung, Guarded ("Private with a guard on the
doors"), is gone. What it added becomes **add-ons** on Private: individual,
named protections a service offers where it has a door to guard. Mail's and
chat's stored `fortress` values, and messenger's `guarded`, fold into Private
plus the matching add-ons (work item 3).

**Built so far.** Drive's Private rung, per
`specs/implemented/drive_private_tier.md` — its level UI is Drive's own
(`assets/js/drive.js`). Social messaging, per
`specs/implemented/joinery_messenger.md`, which also **delivered work item 1**:
the shared card picker is `includes/ProtectionLevelPicker.php`, it owns the card
copy for every service, and the messenger is its first consumer. Mail and Drive
adopt it when next touched. Both were built against the four-rung ladder; the
messenger's Guarded card is part of work item 3.

## Intent

Every service that stores member content draws its protection levels from one
platform ladder of three rungs, chosen with the same card picker, each rung
promising the same outcome everywhere it appears. A service shows only the
rungs it implements. A user learns the vocabulary once — on their mail domain,
on a Drive folder, on an AI conversation — and a given word always means the
same thing:

| | **Standard** | **Private** | **Fortress** |
|---|---|---|---|
| Promise | The server manages this for you | Encrypted at rest — opened only while you're present | End-to-end encrypted — only your devices can read it |
| Encryption | none | server custody (sealed vault, in-window) | client custody (zero-knowledge) |
| AI access | always | **while your window is open** | never (server-side) |
| Survives a stolen database/backup | ✗ | ✓ | ✓ |
| Survives a fully hacked live server | ✗ | ✗ by default; each add-on names the part it saves (see Add-ons) | ✓ for everything stored; mail arriving *during* the hack is the one exception unless the relay add-on is on (see § Arrival) |

The driving insight: **Private is the AI-compatible encryption tier.** Client
custody (Fortress) is the strongest promise a server can make, but it makes the
content permanently opaque to every server-side capability — AI, search,
thumbnails, office editing. A member should not have to choose between
"encrypted" and "my AI can help me with it." Private is that middle: sealed to
the member's vault key, decrypted only inside their unlock window, with
everything the AI derives from it protected by the hot-turn egress rule.

**Fortress is reserved, strictly, for end-to-end encryption.** The strongest
word in the ladder is the one a member must be able to trust absolutely:
Fortress anywhere means the server holds nothing it can decrypt — no key, no
window, ever. Hardened edges — sealed ingress, gated sending, local-only
models — are add-ons, not levels. On Private they narrow what the server can
do; they never turn Private into Fortress, because the server still decrypts
in your window, which is exactly why AI still works there.

### Arrival: the one moment end-to-end cannot cover

Content a member creates on their own device (a Drive file, a password) is
encrypted before it leaves the device. **Mail is different: it is written by
someone else and arrives over SMTP in plaintext.** Whoever receives it holds
plaintext for the instant before encrypting it — the same model Proton and
every end-to-end mail service uses. Fortress mail therefore promises:

- **Stored mail:** only your devices can read it. A stolen database, a stolen
  backup, or a fully hacked server gets ciphertext, window or not.
- **Arriving mail:** encrypted to your device key the moment it is received.
  A server hacked *while mail is arriving* can read what arrives during the
  hack, and nothing already stored.

The **Seal at the relay** add-on moves that receiving moment off the main
server onto a separate relay box, so the main server never holds arriving
plaintext at all. That is a real gain, but it is an add-on: Fortress is
honest without it, and the Fortress mail card states the arrival exception
plainly when the relay add-on is off.

## Add-ons (normative)

An add-on is one protection a service bolts onto a level at a door it actually
has: where content comes in, where it goes out, or who may act in your name.
Rules:

1. **An add-on never changes custody.** Private with every add-on on is still
   server custody: the server decrypts in your window. No add-on, and no
   combination of them, is ever described as end-to-end or as Fortress.
   Equally, **no level requires an add-on**: Fortress is end-to-end with or
   without any of them.
2. **Add-ons exist on Private and Fortress, wherever the door exists.**
   Standard has nothing sealed for a door guard to protect, so it offers none.
   The same add-on means the same thing at both levels (mail's relay and
   sending lock are offered at both). Lowering a resource to Standard turns its
   add-ons off; the stored flags stay, inert, so raising again restores them.
3. **Each add-on states two sentences on its card**: what it protects against
   in plain terms ("a hacked server can't read mail that arrives while you're
   away") and what it costs ("this domain can only send while you're signed
   in"). The copy lives in `ProtectionLevelPicker` with the level cards, so it
   cannot drift between services.
4. **The level card shows which add-ons are on.** The "survives a fully
   hacked live server" answer depends on add-ons, so wherever the level is
   displayed — the picker, a level chip, a setup checklist — the active add-ons
   show with it. A member never has to open settings to learn what their
   resource actually promises. Chips and badges stay short (owner 2026-09-23):
   the level with a "+" when any add-on is on ("Private+"), the add-ons named
   on hover; the picker and settings name them in full.
5. **An add-on whose protection depends on the window being closed shortens
   the unlock window.** Such an add-on only helps while no window is open; a
   week-long window quietly undoes it. When any of a member's resources has one
   on, their window is capped at **2 hours idle / 24 hours absolute** (today's
   `VaultUnlock::FORTRESS_*_CAP_SECONDS`, renamed with the add-on). This rides
   with the add-on; it is not a switch of its own. Plain Private keeps its
   7-day absolute cap. Which add-ons qualify is per level: the sending lock
   always does (the signing key opens in the window); relay sealing does on
   Private (mail is opened in the window) but not on Fortress (there is no
   server window over Fortress mail to shorten). The window cap is the add-on's
   only demand on the account: owner 2026-09-23, add-ons do not force a second
   factor — a passkey alone is enough (a second factor will be encouraged, not
   forced).
6. **An add-on may have prerequisites and a setup sequence.** Mail's relay
   sealing needs a running relay; mail's sending lock needs DNS cut over and a
   vault-held signing key. The add-on is offered once its prerequisite exists
   (or with a link to set it up), and switching it on runs the service's own
   ceremony — the verify-gated protect flow for sending, for example. The flag
   records the finished state, never the intent.

The add-on set per service is in the matrix below. A service may offer none
(Drive, calendar).

## Vocabulary

- **Protection level** is the user-facing term everywhere. (The platform
  already uses "security level" for two unrelated things: the per-action auth
  requirements in `docs/account_security.md`, and the existing DB columns. UI
  copy and docs say *protection level*; the DB columns — `ied_security_level`,
  `aic_security_level` — keep their names, a rename buys nothing.)
- **Add-on** is the internal term; UI copy says **Extra protection** (a heading
  over the add-on switches on the level card).
- **Server custody** (the Private rung) — content sealed via the Sealed Vault
  Layer 0 contract (`docs/sealed_vault.md`): DEK wrapped to the member's vault
  key, decrypted server-side only while their unlock window is open. AI works
  in-window; derived content is guarded by the egress choke point.
- **Client custody** (the Fortress rung, exclusively) — keys never leave the
  member's devices; the server stores ciphertext only and cannot decrypt under
  any circumstance, window or not. Drive encrypted folders and the password
  vault are this today.
- **Derived tier** — content that has no picker of its own because it inherits
  the strictest source it read (chat taint, recipe run sealing). The one-way
  tightening rule: a derived artifact can gain protection, never lose it.

## The AI access rule (normative)

1. **Standard** content is readable by AI at any time, including from cron.
2. **Private** content is readable by AI only inside the owner's unlock window
   (in-window drain / hot request). Everything the AI writes while hot falls
   under the hot-turn egress rule; cloud-model egress requires the per-source
   explicit consent (egress spec resolved decision 5). An add-on may narrow
   this further (chat's and messenger's local-models-only add-on); no add-on
   loosens it.
3. **Fortress** content is never readable by server-side AI. If
   AI-over-Fortress is ever wanted, it is a client-side feature (device-local
   decrypt + local model) with its own spec — nothing in this doctrine quietly
   permits it.

---

## The matrix — current vs. target, per service

Legend for the "gap" column: **fill-in** = build something new; **fold** =
an existing top level becomes Private plus add-ons; **rename** = same
mechanism, align the words; **no-op** = already conforms.

### Mail (mailbox plugin) — the template; top level folds into add-ons

| Facet | Current | Target | Gap |
|---|---|---|---|
| Dial | per **domain**, 3-card picker (`ied_security_level`: standard / private / fortress) | 2 cards (Standard / Private) plus add-ons; a third, Fortress, when end-to-end mail exists. The add-ons are offered at Private and Fortress alike | **fold** |
| Standard | plaintext, automation runs, ambient DKIM | unchanged | no-op |
| Private | sealed at rest (bodies/subjects/attachments/FTS blob), in-window FTS, automated sends still work | unchanged | no-op |
| Add-on: **Seal at the relay** | part of `fortress`: the relay seals incoming mail to the owner's vault key (pending-parse until unlock) instead of the transport key (`RelayMapExporter::sealTargetForAlias`) | own flag, new column `ied_relay_seals_to_owner` (bool). Offered once a relay fronts the domain, at Private or Fortress. At Private it seals to the owner's vault key — card: "A hacked server can't read mail that arrives while you're away"; cost: new mail waits to be processed until you sign in. At Fortress it seals to the owner's device key on the relay — card: "This server never sees your mail, not even as it arrives." Needs a single owner for the domain's mailboxes (today's rule). Fortress never requires it (§ Arrival) | fold |
| Add-on: **Only send while I'm signed in** | part of `fortress`: vault-sealed DKIM key, session-gated signing, strict DMARC (`ied_is_protected_identity`, set by the verify-gated protect ceremony) | own add-on; the existing column **is** the flag. Offered at any Private or Fortress domain, independent of the relay add-on; switching it on runs the protect ceremony. Card: "Nobody can send mail as you, even from a hacked server." Cost: "This domain can only send while you're signed in." | fold |
| Unlock window | `fortress` → 2h idle / 24h absolute; `private` → 7 days (`mailbox/includes/bootstrap.php` `onWindowCaps`) | the short caps apply when the sending lock is on, or relay sealing is on at a Private domain, for any domain the member holds; otherwise 7 days (Add-ons rule 5) | fold |
| Fortress | stored value `fortress` means the hardened server-custody posture above | **end-to-end mail** only, per `specs/DEFERRED_client_custody_mail.md` (parked). Works with or without the relay: without it, the main server encrypts each message to the device key on arrival. No card until it exists; when it does, it states the server-side AI blackout and (relay off) the arrival exception | no-op (parked) |
| AI | in-window; cloud egress behind per-domain consent | unchanged (egress spec) | no-op |
| Promise wording | outcome-language cards | level card lists its active add-ons (rule 4); becomes the shared template | fill-in |

**Why the fold:** mail's top level today is **Private custody plus two
hardened doors** — relay sealing covers mail that arrives while the owner is
away, and the vault-held DKIM key covers "can't send as you." During the
owner's window the server decrypts, exactly like Private — which is what makes
AI over this mail possible, and why the card promised "can't read **new**
mail," not "can't read mail." That is not the Fortress promise. The two doors
are also independent in practice: setup already brings them up in order (relay
first, sending lock last, after MX cut-over), and a member may reasonably want
one without the other. Two switches say that honestly; one level name hid it.

The relay itself is infrastructure, not a protection level: it hides the
origin and seals at the edge for every domain it fronts. Only *whose key* it
seals to is a protection choice — the add-on — and no level depends on it.

### AI chat (joinery_ai conversations) — top level folds into one add-on

| Facet | Current | Target | Gap |
|---|---|---|---|
| Dial | per **conversation** (`aic_security_level`: standard / private / fortress), plus one-way tightening | 2 cards plus add-on under Private | **fold** |
| Standard | plaintext | unchanged | no-op |
| Private | title/instructions/messages/tool calls sealed at rest, in-window reads | unchanged | no-op |
| Add-on: **Local models only** | stored value `fortress`: Private + model pinned to local hardware (cloud refused; unpinned falls back to local; offered only when a local model is configured — `ChatLevel::fortressAvailable`) | own flag, new column `aic_local_models_only` (bool). Card: "Nothing in this chat is sent to an outside AI company." Cost: "Only the AI models running on your own hardware can answer." One-way like the level: once on, it stays on | fold |
| Unlock window | no effect | no effect — this add-on guards egress, not a signed-out door (rule 5 does not apply) | no-op |
| Fortress | not offered | none planned — a client-custody conversation would mean the *server-side* AI cannot read its own conversation; if device-local chat ever exists it gets its own spec | no-op |
| Promise wording | level names shown in UI | adopt the shared card copy | rename |

### AI recipe runs — derived tier, no picker (correct; stays)

| Facet | Current | Target | Gap |
|---|---|---|---|
| Dial | none — run rows seal iff the recipe's source is protected (`RecipeVaultScope`) | unchanged; document as the derived-tier pattern | no-op |
| Cloud models | refused on protected sources without domain consent | unchanged | no-op |

### Drive — Private built; no add-ons

| Facet | Current | Target | Gap |
|---|---|---|---|
| Dial | per **folder**, Standard / Private / Fortress, contiguous subtree from root | unchanged; adopts the shared picker when next touched | no-op |
| Standard | plaintext folder | unchanged | no-op |
| Private | built (`specs/implemented/drive_private_tier.md`) | unchanged | built |
| Fortress | "encrypted folder" — client custody, zero-knowledge, per-file FK/chunk AEAD | unchanged mechanism; card says Fortress / end-to-end encrypted | rename |
| Add-ons | none | none — Drive has no ingress or egress door of its own to guard | no-op |
| AI | Standard: always. Private: in-window. Fortress: never | unchanged | built |
| Search | filename search everywhere; Private content search specced separately (`specs/drive_content_search.md`) | unchanged | per its spec |
| Thumbnails / previews | Private: generated and stored sealed, served in-window; Fortress none | unchanged | built |
| Office editing | Private: allowed in-window; Fortress: refused | unchanged | built |
| Public links / sharing | Standard only | unchanged | built |
| Sync clients | Standard + Fortress sync; Private excluded (a headless daemon holds no window, D3) | unchanged | built |
| Quotas | ciphertext size charged at Private and Fortress | unchanged | built |

### Password vault — fixed at Fortress by design

| Facet | Current | Target | Gap |
|---|---|---|---|
| Dial | none; always zero-knowledge client custody | none — the matrix records it as **Fortress-only, no picker**. Offering weaker levels for credentials is a footgun, not flexibility | no-op (document) |

### Calendar — gains a two-card dial (Standard / Private)

| Facet | Current | Target | Gap |
|---|---|---|---|
| Dial | none; plaintext (`cal_entries`) | per **calendar** (the member's personal calendar), two cards: Standard / Private. No add-ons (calendar has no ingress/egress doors to guard) and no Fortress (client custody would blackout AI scheduling for a promise nobody has asked calendar to make) | **fill-in** |
| Private | — | entry titles/descriptions/locations sealed at rest (Layer 0 columns), opened in-window; times and busy/free stay plaintext (they describe the schedule, not its content — same counts-survive rule as run purging) | fill-in |
| Reminders | full-content from cron | Private entries send **generic** reminders ("You have an appointment at 2pm") — cron holds no window; the relay-sealed-mail generic-push pattern | fill-in |
| ICS feeds | include everything | Private entries **excluded** from feeds (an ICS URL is an unauthenticated pull with no window); stated on the card | fill-in |
| Shared/group events | plaintext | stay Standard — multi-reader sealing is the messaging problem, out of scope | no-op (document) |
| Rationale | | Hand-typed entries ("appointment with oncologist") are exactly what the demand-driven rule never protects — it only covers what AI writes. A calendar is too revealing to be the one service without the dial | — |

### Notes, AI memories — unleveled today; demand-driven Private

| Facet | Current | Target | Gap |
|---|---|---|---|
| Dial | none; plaintext (`rcn_notes`, `mem_memories`, `rcp_workspace`) | **no picker.** These gain Layer 0 sealed columns on demand — when the hot-turn rule refuses a write a product flow needs (egress spec resolved decision 10). Rows written by a hot turn seal to the content owner; rows the user types by hand stay Standard | fill-in (demand-driven, already owned by the egress spec) |
| Rationale | | Mostly AI-written surfaces, so the derived-tier rule covers the real risk (AI copying protected content in). Revisit — calendar-style — if hand-typed "Private notes" becomes a real ask | — |

### Social messaging — BUILT; Guarded folds into two add-ons

| Facet | Current | Target | Gap |
|---|---|---|---|
| Dial | per **conversation**, 3-card picker (`cnv_protection_level`: standard / private / guarded) | 2 cards plus add-ons under Private | **fold** |
| Custody | one key per conversation, wrapped to each participant (`ckg_conversation_key_grants`); the server reads a message only while a holder is present | as current | built |
| Add-on: **Nothing leaves unsealed** | part of `guarded`: no message content in any notification, and no opportunistic plaintext federation to an instance that published no key | own flag (column named at build). Card: "No message text appears in notifications or crosses to another server unencrypted." Cost: "Notifications don't show the message, and people on servers without encryption can't join." | fold |
| Add-on: **Local models only** | part of `guarded`: the AI participant pinned to local models | own flag, same meaning and copy as chat's | fold |
| Unlock window | no effect | no effect — neither add-on guards a signed-out door | no-op |
| Fortress | not offered. Client custody for a multi-party thread is per-participant browser ceremonies, re-sealing on every membership change, and client-side decrypt of every render — a different key-management problem, and a service that cannot honestly offer a rung shows no card for it | a client-custody messaging spec, if ever wanted, arrives as this picker's third card | deferred, on record |

Multi-party custody is the one place the platform's one-owner sealing shape
varies, and it varies in exactly one part: where the key wrapping lives. See
`docs/sealed_vault.md` § Many readers.

---

## Gap summary (the work list, in build order)

1. **Shared level-picker component** — **BUILT** as
   `includes/ProtectionLevelPicker.php` (delivered by
   `specs/implemented/joinery_messenger.md`). A consumer declares its subset of
   the ladder and a copy flavour; the cards, and the three promises on each of
   them, come from one place. Rendered through FormWriter's card radio. Mail
   and Drive still render their own hand-rolled pickers and adopt this when
   next touched. **Extension (with item 3):** the component learns add-ons — a
   consumer declares its add-on set, the Private card renders them as switches
   under an "Extra protection" heading, and the card's summary lists the active
   ones (Add-ons rule 4). Add-on copy lives here with the level copy.
2. **Drive Private tier** — **BUILT**, per
   `specs/implemented/drive_private_tier.md`.
3. **The fold** — the three-rung ladder lands in code:
   - `ProtectionLevel` loses `GUARDED`; its docblock describes three rungs and
     add-ons.
   - **Mail:** add `ied_relay_seals_to_owner`. Data update: every domain at
     `fortress` becomes `private` with `ied_relay_seals_to_owner = true`;
     `ied_is_protected_identity` is already correct per domain and is left
     alone. Every `LEVEL_FORTRESS` branch (about 20 sites: relay map export,
     setup checklist and scope, health checks, protect ceremony, domain editor,
     IMAP edit, window caps) re-reads as the add-on it actually means — the
     relay branches ask the relay flag, the sending branches ask
     `ied_is_protected_identity`, the window cap asks "either add-on". The
     "Finish Fortress: lock sending to your key" setup step becomes the
     sending add-on's own step. `LEVEL_FORTRESS` stays defined but unused,
     reserved for end-to-end mail.
   - **Chat:** add `aic_local_models_only`. Data update: `fortress` → `private`
     + flag on. `ChatLevel`'s local-model gates ask the flag.
   - **Messenger:** add the two flags. Data update: `guarded` → `private` +
     both flags on. The Guarded card leaves the picker.
   - **Window caps:** rename `FORTRESS_IDLE_CAP_SECONDS` /
     `FORTRESS_ABSOLUTE_CAP_SECONDS` to name the add-on case, not the level.
   - Pre-launch, no production users: a data update and a sweep, no
     compatibility shims. *(fold, medium)*
4. **Docs** — `docs/sealed_vault.md` gains a short "Protection levels" section
   naming the ladder and the add-on rules and pointing at each consumer; the
   mailbox overview, `docs/social_features.md`, `plugins/messenger/docs/overview.md`
   and `docs/drive_encryption.md` adopt the vocabulary; `docs/account_security.md`
   gets one disambiguation line (auth security levels ≠ content protection
   levels). Written at build time, current-state only. *(docs)*
5. **Spec sweep** — active specs that use "Fortress" for mail's hardened
   server-custody level are re-read against the fold and reworded to name the
   add-on they mean: `fortress_live_verification_runbook.md`,
   `new_site_deployment_fortress_verification.md`,
   `fortress_deployment_readiness.md`, `mailbox_security_model_public.md`,
   `mailbox_security_model_pentest_brief.md`,
   `DEFERRED_automatic_install_mail_topology.md`,
   `step8_email_stack_activation.md`, `vault_key_memory_exposure.md`,
   `native_vault_unlock.md`, `mailbox_strict_sending_identities.md`,
   `mailbox_encrypted_interop.md`, `unseal_daemon.md`,
   `security_inventory.md`, `messaging_ai_participant.md`,
   `getjoinery_site_redesign.md`, `drive_content_search.md`,
   `vault_exposure_quick_fixes.md`, `mailbox_group_collaboration.md`. Files in
   `specs/implemented/` are history and stay as written. Done with item 3, so
   the runbooks match the code they test. *(docs, small)*
6. **Calendar Private** — the two-card dial, sealed content columns, generic
   reminders, ICS exclusion. Much smaller than Drive (no chunked content, no
   sharing surface, no sync) — a good first consumer of the shared picker
   component after mail. *(fill-in, medium)*
7. **Notes/memories** — nothing to schedule; the egress spec's demand-driven
   rule already owns it. Recorded here so the matrix is complete.

## Interaction with sealed_content_egress.md

The egress spec is what makes Private a *promise* rather than a column format:
the hot-turn rule guarantees that anything AI derives from Private content
lands sealed or is refused, on every service, with no per-service enumeration.
This doctrine adds no new enforcement — it names the levels the enforcement
already assumes. Add-ons narrow egress further on specific doors; they never
widen it.

## Open questions

- **Q1 — Mail's add-ons at domain creation.** Today a domain can be created
  directly at `fortress`. After the fold, creating a domain at Private offers
  the add-ons too, or only the level (add-ons from the domain editor
  afterwards)? Recommendation: level only at creation; the add-ons need a
  relay and DNS cut-over that a brand-new domain does not have yet, and the
  setup checklist already leads there.
- **Q2 — Messenger's "Nothing leaves unsealed" as one add-on or two.** The
  notification rule and the federation rule are both "content never leaves
  unsealed," so one switch reads naturally; split them only if a member
  plausibly wants one without the other. Recommendation: one.

## Resolved decisions

- **R1 (owner, 2026-08-02) — Fortress means client custody, strictly.** The
  strongest word is reserved for "plaintext never exists on the server"; using
  it for hardened-server-custody tiers dilutes the one promise a member must be
  able to trust absolutely. Reaffirmed by R4, in the member's words:
  *end-to-end encrypted*.
- **R2 (owner, 2026-08-02) — services show a subset of the ladder.** A service
  that cannot offer client custody shows no Fortress card (mail, until the
  parked client-custody spec un-parks; chat; messenger); Fortress-only
  services show no picker (passwords). *The four-rung ladder this decision
  originally named is superseded by R4.*
- **R3 (owner, 2026-08-02) — the tier-3 name "Guarded."** *Superseded by R4:
  the rung no longer exists.* The naming analysis (Protected already means
  "Private or above"; Shield is the licensing system) still applies if an
  add-on heading ever needs a new word.
- **R4 (owner, 2026-09-23) — three rungs; hardening is add-ons, not a level.**
  Standard / Private / Fortress. Fortress = end-to-end encrypted, and needs no
  add-on — Fortress mail without the relay encrypts on arrival at the main
  server (owner, same day: the relay is a pure add-on). What Guarded
  added (mail's relay sealing and sending lock, chat's and messenger's
  local-models pin, messenger's no-unsealed-exit rule) becomes add-ons on
  Private. Why: three levels read the same on every service; the hardening
  pieces are independent in practice (mail sets them up in separate steps,
  with the relay as shared infrastructure); and a fourth word between Private
  and Fortress asked members to learn a distinction that is really a list of
  specific doors. The cost, accepted: Private's hacked-server answer depends
  on which add-ons are on, which is why rule 4 puts them on the card.

## Open decisions — ALL RESOLVED 2026-08-02 (retained with their reasoning)

- **D1 — RESOLVED (owner 2026-08-02): v1 ships content sealing + previews + thumbnails; content search is v2, specced separately in `specs/drive_content_search.md`.** Recommendation: content + previews
  + office editing in v1, in-window content search in v2. Search is the only
  facet with real index-build cost; everything else rides existing seams.
- **D2 — RESOLVED (owner 2026-08-02, = drive spec P4). Level transitions on existing folders.** Standard→Private and
  Private→Standard are server-side re-encrypt/decrypt jobs (batched, like the
  mailbox raise). Private→Fortress and Fortress→anything require client-side
  ceremonies. Recommendation: v1 ships Standard↔Private only; Fortress folders
  continue to be created as Fortress (today's flow), transitions to/from
  Fortress deferred.
- **D3 — RESOLVED (owner 2026-08-02, = drive spec P2). Private folders on sync clients.** Excluded in v1 (no window on a
  headless daemon). The honest longer-term options are (a) a per-device
  custody grant — approaching Fortress's machinery, at which point the user
  should maybe just use Fortress — or (b) sync of ciphertext with no local
  read. Recommendation: exclude, revisit only on demand.
- **D4 — RESOLVED (owner 2026-08-02). Where the picker lives for Drive:**
  three-card picker on top-level folder creation + folder settings (levels
  boundary only at the root, so nested folders show an inherited read-only
  level), a level chip in the listing, transitions per drive spec P4.
