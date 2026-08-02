# Platform Protection Levels — one four-rung vocabulary for every service

**Status: DRAFT 2026-08-02 — doctrine + gap matrix. Nothing here is built; the
matrix below is the work list. Companion spec: `specs/implemented/sealed_content_egress.md`
(the egress choke point is what makes the Private rung meaningful platform-wide).
Ladder shape and the Guarded name are owner-resolved (decisions R1/R2 below).**

## Intent

Every service that stores member content draws its protection levels from one
platform ladder of four rungs, chosen with the same card picker, each rung
promising the same outcome everywhere it appears. A service shows only the
rungs it implements. A user learns the vocabulary once — on their mail domain,
on a Drive folder, on an AI conversation — and a given word always means the
same thing:

| | **Standard** | **Private** | **Guarded** | **Fortress** |
|---|---|---|---|---|
| Promise | The server manages this for you | Encrypted at rest — opened only while you're present | Private, with a guard on the doors | Plaintext never exists on the server |
| Encryption | none | server custody (sealed vault, in-window) | server custody + hardened ingress/egress | client custody (zero-knowledge) |
| AI access | always | **while your window is open** | while your window is open (egress hardened) | never (server-side) |
| Survives a stolen database/backup | ✗ | ✓ | ✓ | ✓ |
| Survives a fully hacked live server | ✗ | ✗ (hot memory during your window) | partially — content arriving while you're away, and your sending identity, survive | ✓ |

The driving insight: **Private is the AI-compatible encryption tier.** Client
custody (Fortress) is the strongest promise a server can make, but it makes the
content permanently opaque to every server-side capability — AI, search,
thumbnails, office editing. A member should not have to choose between
"encrypted" and "my AI can help me with it." Private is that middle: sealed to
the member's vault key, decrypted only inside their unlock window, with
everything the AI derives from it protected by the hot-turn egress rule.

**Fortress is reserved, strictly, for client custody.** The strongest word in
the ladder is the one a member must be able to trust absolutely: Fortress
anywhere means a fully hacked server gets ciphertext, full stop. The tiers that
are really "Private plus hardened edges" — server custody with sealed ingress,
gated sending, or local-only models — are **Guarded**, not Fortress. Guarded's
hardening is service-specific and stated in the card's fine print (mail guards
the mail doors; chat guards the model egress); what is constant is the custody:
the server still decrypts in your window, which is exactly why AI still works
there.

## Vocabulary

- **Protection level** is the user-facing term everywhere. (The platform already
  uses "security level" for two unrelated things: the per-action auth
  requirements in `docs/account_security.md`, and the existing DB columns. UI
  copy and docs say *protection level*; the DB columns — `ied_security_level`,
  `aic_security_level` — keep their names, a rename buys nothing. The stored
  **values** do rename: mail and chat rows holding `fortress` become `guarded`
  — cheap now, pre-launch, and only more expensive later.)
- **Server custody** (the Private and Guarded rungs) — content sealed via the
  Sealed Vault Layer 0 contract (`docs/sealed_vault.md`): DEK wrapped to the
  member's vault key, decrypted server-side only while their unlock window is
  open. AI works in-window; derived content is guarded by the egress choke point.
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
   explicit consent (egress spec resolved decision 5).
3. **Guarded** content follows the Private rule — in-window only — with the
   service's own egress hardening on top (chat: local models only; mail: the
   per-domain cloud consent applies exactly as at Private). Guarded never
   *loosens* anything Private promises.
4. **Fortress** content is never readable by server-side AI. If
   AI-over-Fortress is ever wanted, it is a client-side feature (device-local
   decrypt + local model) with its own spec — nothing in this doctrine quietly
   permits it.

---

## The matrix — current vs. target, per service

Legend for the "gap" column: **fill-in** = build something new; **rename** =
same mechanism, align the words; **no-op** = already conforms.

### Mail (mailbox plugin) — the template; one rename

| Facet | Current | Target | Gap |
|---|---|---|---|
| Dial | per **domain**, 3-card picker (`ied_security_level`) | unchanged mechanism; top card renamed | no-op |
| Standard | plaintext, automation runs, ambient DKIM | unchanged | no-op |
| Private | sealed at rest (bodies/subjects/attachments/FTS blob), in-window FTS, automated sends still work | unchanged | no-op |
| Guarded | exists today under the stored value `fortress`: Private + relay-sealed ingress (pending-parse to user key), session-gated outbound signing, strict DMARC | same mechanism; stored value, card copy, and docs say **Guarded** | **rename** |
| Fortress | does not exist | reserved for client-custody mail (`specs/DEFERRED_client_custody_mail.md`, parked); no card shown until it exists | no-op (parked) |
| AI | in-window; cloud egress behind per-domain consent | unchanged (egress spec) | no-op |
| Promise wording | outcome-language cards | becomes the shared template | no-op |

**Custody honesty (why the rename):** mail's top level today is **Private
custody plus a hardened perimeter** — relay-sealed ingress covers mail that
arrives while the owner is away (sealed to the vault key before it reaches the
box, pending-parse until unlock), and the sealed, session-gated DKIM key covers
"can't send as you." During the owner's window the server decrypts, exactly
like Private — which is what makes AI over this mail possible, and why the card
promises "can't read **new** mail," not "can't read mail." That is the Guarded
promise, not the Fortress one. Full client-custody mail is parked in
`specs/DEFERRED_client_custody_mail.md`: it moves parsing, rendering, and
search into the client and puts mail behind the same server-side AI blackout as
a Fortress Drive folder — the deliberate reason it stays parked while AI over
mail is the platform's center. If it ever un-parks, it arrives as mail's true
**Fortress** card, with the AI blackout stated on it.

### AI chat (joinery_ai conversations) — one rename

| Facet | Current | Target | Gap |
|---|---|---|---|
| Dial | per **conversation** (`aic_security_level`), plus one-way taint tightening | unchanged | no-op |
| Standard | plaintext | unchanged | no-op |
| Private | title/instructions/messages/tool calls sealed at rest, in-window reads | unchanged | no-op |
| Guarded | exists today under the stored value `fortress`: Private + model pinned to local hardware (cloud refused; unpinned falls back to local) — egress hardening, not a custody change | same mechanism; stored value, card copy, and taint-gate wording say **Guarded** | **rename** |
| Fortress | does not exist | none planned — a client-custody conversation would mean the *server-side* AI cannot read its own conversation; if device-local chat ever exists it gets its own spec | no-op |
| Promise wording | level names shown in UI | adopt the shared card copy | rename |

### AI recipe runs — derived tier, no picker (correct; stays)

| Facet | Current | Target | Gap |
|---|---|---|---|
| Dial | none — run rows seal iff the recipe's source is protected (`RecipeVaultScope`) | unchanged; document as the derived-tier pattern | no-op |
| Cloud models | refused on protected sources without domain consent | unchanged | no-op |

### Drive — the big fill-in: no middle tier exists

| Facet | Current | Target | Gap |
|---|---|---|---|
| Dial | per **folder**, binary: plaintext or encrypted (`fol_encrypted`), contiguous subtree from root | per folder, 3-card picker, same contiguous-subtree rule (a parent's level is the floor for its children) | **fill-in** |
| Standard | plaintext folder | unchanged (renamed card) | rename |
| Private | **does not exist** | file content + per-file metadata sealed server-custody to the owner's vault (same envelope pattern mail attachments use); reads, downloads, previews open in-window only | **fill-in (the build)** |
| Fortress | "encrypted folder" — client custody, zero-knowledge, per-file FK/chunk AEAD | unchanged mechanism; card renamed Fortress | rename |
| AI | plaintext folders only | Standard: always. Private: in-window (hot-turn rule guards derived writes). Fortress: never | fill-in |
| Search | filename search everywhere; no content search in encrypted folders | Private gains in-window content indexing (sealed FTS blob, mail pattern); Fortress unchanged (none) | fill-in |
| Thumbnails / previews | plaintext only; encrypted folders none | Private: generated and stored **sealed**, served in-window; Fortress unchanged | fill-in |
| Office editing | plaintext only; refused in encrypted folders | Private: allowed in-window (editor reads plaintext while you're present — that is the Private promise); Fortress: refused | fill-in |
| Public links / sharing | plaintext subtree only | Standard only. A Private/Fortress folder cannot carry a public link (Private's promise is "opened only while *you're* present") | fill-in (enforcement) |
| Sync clients | plaintext syncs; encrypted folders sync under device custody (Phase 3) | Standard + Fortress unchanged. **Private folders excluded from sync initially** — a headless daemon holds no unlock window (same APCu constraint as cron); see open decision D3 | fill-in (exclusion + UI honesty) |
| Quotas | ciphertext size charged on encrypted files | same rule at Private | fill-in |

### Password vault — fixed at Fortress by design

| Facet | Current | Target | Gap |
|---|---|---|---|
| Dial | none; always zero-knowledge client custody | none — the matrix records it as **Fortress-only, no picker**. Offering weaker levels for credentials is a footgun, not flexibility | no-op (document) |

### Calendar — gains a two-card dial (Standard / Private)

| Facet | Current | Target | Gap |
|---|---|---|---|
| Dial | none; plaintext (`cal_entries`) | per **calendar** (the member's personal calendar), two cards: Standard / Private. No Guarded (calendar has no ingress/egress doors to harden) and no Fortress (client custody would blackout AI scheduling for a promise nobody has asked calendar to make) | **fill-in** |
| Private | — | entry titles/descriptions/locations sealed at rest (Layer 0 columns), opened in-window; times and busy/free stay plaintext (they describe the schedule, not its content — same counts-survive rule as run purging) | fill-in |
| Reminders | full-content from cron | Private entries send **generic** reminders ("You have an appointment at 2pm") — cron holds no window; the Fortress-mail generic-push pattern | fill-in |
| ICS feeds | include everything | Private entries **excluded** from feeds (an ICS URL is an unauthenticated pull with no window); stated on the card | fill-in |
| Shared/group events | plaintext | stay Standard — multi-reader sealing is the messaging problem, out of scope | no-op (document) |
| Rationale | | Hand-typed entries ("appointment with oncologist") are exactly what the demand-driven rule never protects — it only covers what AI writes. A calendar is too revealing to be the one service without the dial | — |

### Notes, AI memories — unleveled today; demand-driven Private

| Facet | Current | Target | Gap |
|---|---|---|---|
| Dial | none; plaintext (`rcn_notes`, `mem_memories`, `rcp_workspace`) | **no picker.** These gain Layer 0 sealed columns on demand — when the hot-turn rule refuses a write a product flow needs (egress spec resolved decision 10). Rows written by a hot turn seal to the content owner; rows the user types by hand stay Standard | fill-in (demand-driven, already owned by the egress spec) |
| Rationale | | Mostly AI-written surfaces, so the derived-tier rule covers the real risk (AI copying protected content in). Revisit — calendar-style — if hand-typed "Private notes" becomes a real ask | — |

### Social messaging — out of scope, recorded for honesty

| Facet | Current | Target | Gap |
|---|---|---|---|
| Dial | none; plaintext | none in this spec. Multi-party custody (N readers per thread) is a different key-management problem than one-owner sealing; folding it in here would balloon the doctrine. Candidate for a future spec | deferred |

---

## Gap summary (the work list, in build order)

1. **Shared level-picker component** — extract the mailbox three-card picker
   (outcome language, prerequisite checklist pattern) into a core component both
   mail and Drive render. Card copy comes from one place so the promise wording
   never drifts. *(fill-in, small)*
2. **Drive Private tier** — the substantive build: per-file server-custody
   sealing (content, metadata blob, thumbnail), in-window read paths, in-window
   content search, office-editing gate flipped to in-window-allowed, public-link
   refusal, sync exclusion, folder-level migration flows (Standard↔Private
   re-encrypt jobs; Private→Fortress is a client-side re-encrypt and may land
   later — see D2). *(fill-in, large — gets its own build spec:
   `specs/drive_private_tier.md`, to be written from this doctrine)*
3. **Renames** — mail and chat stored level values `fortress` → `guarded`
   (column values, card copy, ceremony wording, docs; pre-launch so no
   migration of user expectations, just a data update + sweep). Drive UI:
   "Encrypted folder" card becomes **Fortress**; plaintext becomes
   **Standard**. No DB *column* renames. *(rename, small)*
4. **Docs** — `docs/sealed_vault.md` gains a short "Protection levels" section
   naming the doctrine and pointing at each consumer; `docs/drive_encryption.md`
   and the mailbox overview adopt the shared vocabulary; `docs/account_security.md`
   gets one disambiguation line (auth security levels ≠ content protection
   levels). Written at build time, current-state only. *(docs)*
5. **Calendar Private** — the two-card dial, sealed content columns, generic
   reminders, ICS exclusion. Much smaller than Drive (no chunked content, no
   sharing surface, no sync) — a good first consumer of the shared picker
   component after mail. *(fill-in, medium)*
6. **Notes/memories** — nothing to schedule; the egress spec's demand-driven
   rule already owns it. Recorded here so the matrix is complete.

## Interaction with sealed_content_egress.md

The egress spec is what makes Private a *promise* rather than a column format:
the hot-turn rule guarantees that anything AI derives from Private content
lands sealed or is refused, on every service, with no per-service enumeration.
This doctrine adds no new enforcement — it names the levels the enforcement
already assumes and fills the one hole (Drive's missing middle tier) where a
member currently cannot choose the AI-compatible encrypted posture at all.

## Resolved decisions

- **R1 (owner, 2026-08-02) — Fortress means client custody, strictly.** The
  strongest word is reserved for "plaintext never exists on the server"; using
  it for hardened-server-custody tiers dilutes the one promise a member must be
  able to trust absolutely. Mail's and chat's current top levels are therefore
  renamed, not Fortress.
- **R2 (owner, 2026-08-02) — the ladder is four rungs, services show a subset.**
  Standard / Private / Guarded / Fortress. A service with no meaningful edge
  hardening shows no Guarded card (calendar); a service that cannot offer
  client custody shows no Fortress card (mail, until the parked client-custody
  spec un-parks); Fortress-only services show no picker (passwords).
- **R3 (owner, 2026-08-02) — the tier-3 name is "Guarded."** Chosen over:
  *Protected* (already means "Private or above" platform-wide — the protection
  ceremony, `ied_is_protected_identity`, "protected domain" all lean on that
  meaning, and a Protected rung would imply Private isn't); *Shielded* (Shield
  is the licensing system); fortification metaphors — Rampart, Barbican,
  Bastion, Redoubt — (forced, and opaque without a glossary). Guarded
  self-explains, reads clearly above Private and below Fortress, and matches
  what the tier adds: a guard on the doors.

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
