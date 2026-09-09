# Security inventory

**Status:** Open, investigating. Started 2026-09-09 from the vault key review
(`vault_key_memory_exposure.md`) and grown as findings land. This is the one
list of security items to handle soon; each row says what it closes and
where the finding is described. Add to it; do not fork it. The architecture
section is the shape every row is a step toward; read it first.

**Owner decision (2026-09-09): this inventory is the 1.0 bar.** The residual
threat model in § The threat model after all of it is accepted as what 1.0
ships with. Reaching that list — S1–S21 plus the four separations, PHP kept —
is the condition for calling the platform 1.0. Items added later are either
part of that bar or explicitly marked post-1.0.

## The architecture behind the list

Read this before working any row below, so a fix lands as a step toward one
shape rather than a patch on the current one.

**The language is not the problem; the process topology is.** One php-fpm
pool does four jobs at once: it holds the vault keys, it runs every plugin, it
parses strangers' bytes, and it can write its own code tree. Every finding in
this inventory is one of those four jobs reaching another. Isolation means
pulling them into separate processes with narrow doors between them. PHP can
stay in every one of those processes, or in none, and the security outcome is
the same. Core is ~200k lines of PHP and the plugins ~170k more; a rewrite is
years and carries every trust decision below across untouched (a settings
value becoming a `require` path, an editor that writes the front controller,
AI tools that skip approval, a forward with no loop guard — none of these are
PHP bugs). The separations are weeks to months.

### The four separations, cheapest first

1. **Strangers' bytes parse in a jail.** The attachment extractor is already a
   subprocess (`DocumentText::run()`), so the shape exists; today it is a memory
   and time fence under the same uid. Give it its own user, no network, a
   read-only view of the tree and a seccomp profile (`systemd-run` with
   `DynamicUser=`, `ProtectSystem=strict`, `PrivateNetwork=`, or bubblewrap).
   The same jail takes the Postfix pipe and the MIME parse the Refresh button
   runs. This is where WebAssembly genuinely helps: the memory-unsafe surface is
   libzip, libxml2 and the PDF parser, not PHP. Compiled to wasm and run under
   wasmtime, a bug in them corrupts a sandbox with no filesystem and no
   syscalls. Wasm belongs at the parsers, not at PHP. (S5, S6, S7)
2. **Keys leave the pool.** The unseal daemon (`vault_key_memory_exposure.md`
   § Mitigation A): its own uid, `mlock`, answers "unwrap this 32-byte key"
   over a socket. The pool never holds a long-term key again. Medium work,
   mostly the 58 `VaultUnlock::secretKey()` call sites. (S13)
3. **The web user cannot write code.** Mitigation C. Only the agent, as root
   and only from a signed archive, touches the tree; everything the pool writes
   moves under uploads. This turns every transient break-in into "nothing
   persists" and puts a floor under package signing. Entangled with the
   agent-on-node migration, but it is filesystem permissions and an install
   step, not a rewrite. Dev, where files are edited in place, needs its own
   exception. (S10, and it makes S5/S6 structural)
4. **Plugins run somewhere else.** The only genuinely hard one, and the only
   place a new plugin contract is unavoidable. Today a plugin is PHP in the
   same process that may call any class. Signing (Mitigation B, S9) is trust,
   not isolation: it decides *whose* code runs in the pool, not *what* that
   code can reach.

### Isolated plugins: the two shapes

Both change what a plugin is; neither changes what the platform is.

- **A plugin is its own php-fpm master under its own uid.** APCu is per
  master, so the plugin pool never sees a key. It reaches core only through
  the API the browser already uses (`/api/v1`, the `_logic_descriptor()`
  opt-in), which is the surface the logic-descriptor migration is building
  toward. PHP throughout, no new toolchain, and the theme chain still serves
  the plugin's views. The cost is that today's plugins reach into the data
  classes, the schema sync and core hooks directly, so each of the twelve
  would be rehomed against the API surface.
- **A plugin is a wasm module.** Any language; host functions expose a
  capability list; the module can do nothing not on the list. This is what a
  marketplace for third-party code eventually wants (Extism is the reference
  shape). It rewrites plugin authoring, not the platform.

**Not a shape:** PHP itself compiled to wasm. Server-side PHP-in-wasm exists
for browsers and edge functions; it loses the extensions that matter here
(the database driver, libzip) and sandboxes the wrong layer. The things that
need a sandbox are the parser and the plugin, not the request handler.

### The decision that picks between "sign" and "isolate"

A product question, not a technical one: **is the marketplace our catalog of
our plugins, or will strangers publish to it?** If ours, signing plus
separations 1–3 closes every door found so far, and separation 4 is never
needed. If strangers will publish, isolation is required before the first
such plugin ships, and the API-only pool is the path that keeps PHP. Until
that is answered, every plugin-facing change should be one both answers
accept: the API surface grows, in-process reach does not.

### Proposed answer: two plugin tiers

The owner's proposal (2026-09-09) is its own spec, `plugin_tiers.md`, while
it is discussed. The part that touches this list: the *policy* that the
marketplace carries no third-party PHP closes the plugin question inside the
1.0 bar with signing alone (S9); the sandboxed-tier host is post-1.0.

### Order

Parser jail and read-only tree first (cheap, and they close routes 1 and 2 of
the malicious-message analysis); the unseal daemon next; the plugin question
decided, not deferred. Wasm at the parsers now, at the plugin boundary later
if the answer is "strangers", at PHP never.

### The threat model after all of it

Assume S1–S21 and the four separations are built, with PHP kept. What an
attacker can still do, honestly, most to least likely:

1. **Steal a session, not a key.** Phishing that relays a TOTP code in real
   time still works; only passkeys resist it, and a stolen session cookie or
   malware on the admin's machine bypasses both. What the session buys has
   shrunk: no page turns it into code, no setting becomes a `require`, and a
   sealed mailbox stays sealed because opening one needs the owner's passkey.
   It still reads every Standard mailbox (log-in-as), changes any setting that
   is not vault-gated, and installs anything we signed.
2. **Read what the owner reads.** Code in the pool during an open window
   sees that window's plaintext and can ask the unseal daemon to unwrap any
   key it can name until the window closes. The daemon makes that countable
   and rate-limited, and the loss stops being permanent, but "in the process
   during the window" is accepted by design (docs/sealed_vault.md § One unlock
   opens everything). The unlock request itself carries the PRF output through
   the pool once.
3. **Our own release pipeline.** Every node trusts the signing key on the
   management node and whatever the publisher resolves into a signed archive
   (vendor included). Compromise of the plane, the publisher box, or a
   dependency we ship is the whole fleet at its next upgrade. Nothing on a
   node can detect a signed malicious release. This is the largest residual
   and it is structural to being the operator.
4. **The operator.** Sealed mail cannot be read at rest by us, and there is
   no skeleton key. But the operator can always ship a signed release that
   exfiltrates at the owner's next unlock. Every hosted end-to-end system has
   this floor; the honest promise to a managed customer is "not readable at
   rest, not readable without an update you could in principle inspect", not
   "not readable by us".
5. **Metadata.** A stolen database copy of a sealed mailbox shows no sender,
   subject, body or recipient (`$sealed_fields`), but it shows that mail
   arrived, when, how big, in which mailbox, with which labels, spam verdict
   and thread shape, and the Message-ID headers. Who-talks-to-whom is largely
   hidden; how-much-and-when is not.
6. **Sandbox escape.** The parser jail reduces a libzip or libxml2 bug from
   "www-data with a writable tree" to "a kernel or seccomp escape from a
   process with no filesystem and no network". Wasm at the parsers reduces it
   further to a runtime bug. Not zero; a different order of attacker.
7. **Root beneath us.** Node root via a kernel bug, the Docker host, Linode's
   console, the hypervisor. Reaches memory by design; only the daemon's
   `mlock` and no-dump pages make it forensic work rather than a file read.
8. **The AI as a persuasion channel.** With every write queued for approval,
   a message can no longer make the AI *do* anything; it can still make the AI
   *say* something, and the owner acts on advice. Provenance marking on
   recalled memory helps; the residual is the owner's judgement.
9. **Availability.** Five Postfix pipe slots, spool caps, parser time limits:
   a flood degrades inbound mail. Denial, not disclosure.
10. **The owner's devices.** The passkey lives on a laptop or phone; the
    browser holds the PRF output at unlock; native apps bridge a web session.
    A compromised endpoint is the owner, and nothing server-side distinguishes
    the two.

**What is gone from the list:** a stranger's message reaching code; an admin
page becoming code; a plugin ZIP as a door; permanent, portable loss of a
long-term key from a pool compromise; anything persisting from a transient
break-in; unapproved AI action on mail; mail leaving the box against the
domain's consent.

## Items to handle soon

Ordered by likelihood of the route each closes. S-numbers are stable for
tracking. B-numbers are findings; B1–B6 are described in
`vault_key_memory_exposure.md`, B7–B11 below, Q-numbers in
`vault_exposure_quick_fixes.md`. A row marked Closed is finished and its
record lives in `implemented/security_inventory_closures_2026_09.md`;
everything else is open and the owner is investigating and adding.

| # | Item | Closes | Size |
|---|---|---|---|
| S1 | Closed 2026-09-09 (2429131f). Agent-files editor target names are one folder name ending in `.md` (B5) — closures spec § S1 | admin session → code | one function |
| S2 | Closed 2026-09-09. Path-bearing settings carry a validation pattern and are vault gated; the theme-name sink refuses a bad name (B6) — closures spec § S2 | admin session → code | small |
| S3 | Closed 2026-09-09. The sweep and every verdict — closures spec § S3 | the class behind B5/B6 | inventory |
| S4 | `totp_require_admins`: default on for managed nodes, or the protection ceremony reports "N admins have no second factor" as information with an in-place fix (never a gate, per the declined item) | route 1 | owner decision + small |
| S5 | Extractor subprocess under its own uid with no write to the webroot (a `joinery-extract` user, or `systemd-run` with `ProtectSystem=strict` and `PrivateNetwork`) | route 2 | medium, per node |
| S6 | Postfix pipe under a uid that can write only the attachment store and the DB, not the tree; falls out of Mitigation C if C lands first | route 2 | medium, install + fleet |
| S7 | Refresh: the pool never parses stranger bytes. The button enqueues and polls; the parse runs in the cron tier | route 3 | medium |
| S8 | Remote images in mail: block by default with a per-message "load images", or proxy through the node | recon and privacy | medium, reader UI |
| S9 | Mitigation B: signed plugin and theme packages, vendor inside the archive (B3), site-local root-held key | admin session → code | large |
| S10 | Mitigation C: web user cannot write the tree (B4); makes S5/S6 structural | persistence after any break-in | large, agent-migration territory |
| S11 | CSP on with nonces, dropping `'unsafe-inline'` (`project_csp_phase1`) | defence in depth for route 1 | medium |
| S12 | Closed 2026-09-09 (c6b89dd8). Quick fixes Q1–Q6 — `vault_exposure_quick_fixes.md`, which stays open for its gates: a reboot proof, dev's own conversion, the agent release carrying swap telemetry | residue, route 3 in § Ranked | small each |
| S13 | Mitigation A: the unseal daemon | long-term key never in the pool; the only close for disk-image residue | large, after agent migration |
| S14 | Forward loop guard: stamp `Auto-Submitted: auto-forwarded` on filter forwards, refuse to forward a message that carries it or our own `X-Forwarded-By`, and cap hops (B7) | mail loop started by a stranger | small |
| S15 | Closed 2026-09-09 (2429131f). Memory, note and workspace writes are mutating and render their card, so chat queues them (B8) — closures spec § S15 | unapproved AI writes | one function |
| S16 | Agent-mode recipes that read untrusted content queue their writes instead of executing inline, or lose the write tools; the standing approval covers pipeline verdicts only (B8) | unattended AI action on mail | medium |
| S17 | `EmailScheduleJob` writes a *proposed* calendar entry the owner confirms, not a live row (B8) | attacker-authored calendar entries | small |
| S18 | Memory writes from a turn that read untrusted content are held for approval, or marked so recall shows their provenance (B9) | memory poisoning | small to medium |
| S19 | `consentTrustFloor()` honours the domain's `local|trusted|cloud` consent for Standard mail, not only sealed mail (B10) | mail leaving the box against the consent setting | small |
| S20 | Closed 2026-09-09 (2429131f). The untrusted markers are built in one place and any marker inside content is rewritten (B11) — closures spec § S20 | envelope escape | one class |
| S21 | Direct on Private/Fortress: bound what an unapproved stranger can spool per sender, not only in total | storage from strangers | small |


## What a message can make the system do (2026-09-09)

Compromise is one loss. The other is a message that makes the platform act:
forward mail, write a calendar entry, plant a memory the AI reads next week.
These do not reach the pool (that analysis is `vault_key_memory_exposure.md`
§ A malicious message), but they are what a loyal guardian is supposed to
refuse, and three of them run with nobody watching.

**B7 — a stranger's message can cause an outbound forward.** Inbound filters
carry a `forward_to` action (`inbound_email_filter_class.php:101`), applied on
arrival to SMTP mail and to Joinery Direct mail alike
(`InboundEmailRouter.php:1110-1119`). The owner chose the rule and acknowledged
the destination (`forwardConsentSatisfied()`), so this is intended. What is
missing is a **loop guard**: `buildForwardMessage()` (`:2455-2458`) stamps
`X-Forwarded-By: Joinery Inbound Email` and nothing else, no `Auto-Submitted:
auto-forwarded`, and arrival checks neither that header nor `Auto-Submitted`
nor a hop count. Two owners forwarding to each other, or one forwarding to an
address that bounces back into a matching rule, loop through the relay until
something rate-limits. A stranger can start that loop with one message.

**B8 — the AI acts on mail unattended, and some of its tools skip approval.**
Recipes run from cron, including "as mail arrives"
(`EmailPipelineJobBase.php:34`). In pipeline mode the model returns one verdict
and the job writes one fixed field on that item, which is the right shape; but
`EmailScheduleJob` (`:94-133`) turns that verdict into a **calendar row** with
no one clicking. In agent mode the model drives a tool belt, and
`RecipeRunContext::queuesWrites()` is `false` (`:132`), so `create_model`,
`update_model`, `delete_model` and `invoke_action` execute inline under the
owner's one-time standing approval. In interactive chat those are queued for
approval, but `RiskHeuristic::isMutating()` (`:50-61`) classifies only those
four; **`remember`, `forget`, `save_note` and `set_workspace` run inline
without approval** in every mode.

**B9 — memory poisoning.** `RememberTool::execute()` (`:53-87`) inserts an
`AiMemory` row verbatim from model output, in the same turn the model read an
email, with no approval. Scope is the acting user only and recall re-wraps the
stored text as untrusted, so a planted memory cannot escalate, but it can steer
every later conversation of that user ("the owner prefers replies be sent to
…"). This is the `[[project_joinery_ai_memory]]` surface seen from the
attacker's side.

**B10 — mail on a Standard domain has no AI-egress consent gate.** Email
content is wrapped `<<UNTRUSTED_{nonce}>>…<</UNTRUSTED_{nonce}>>`
(`PipelineRunner.php:100`, `ModelQueryExecutor.php:263`) before it reaches a
model, and the model can be local, `trusted`, or a cloud API
(`ai_endpoints.json`). The per-domain consent `local|trusted|cloud`
(`inbound_email_domain_class.php:70-75`) is folded into
`RecipeVaultScope::consentTrustFloor()`, which returns **null when nothing
sealed is in play** (`:174-176`). So a Private or Fortress mailbox's consent
binds; a Standard mailbox's mail goes to whichever endpoint has a key, with the
consent setting ignored. Not a compromise, but a stranger's message leaving the
box for a hosted API is exactly what the domain's consent setting exists to
refuse.

**B11 — the untrusted envelope is not escaped inside the content.** The nonce is
32 random bits per run (`RecipeRunContext.php:48`) and never shown to the
sender, so closing the envelope from inside a message is a guess against 2^32.
Adequate, but cheap to make structural: strip or rewrite any `<</UNTRUSTED_`
token found in content before wrapping, regardless of nonce.

**Joinery Direct, for completeness.** Any domain publishing the SRV and key
records can preflight and sign; the signature is verified with no allow-list
(`DirectReceiver.php:105-120`). Authorization is the contact gate
(`DirectContactGate.php:50-78`): the sender must be in the recipient's
contacts and domain-aligned with the signature. On Private and Fortress the
receiver accepts unconditionally and defers the gate to unlock, so an
unapproved stranger fills spool up to `DirectSettings` caps. A declined `mail`
message is filed through spam classification rather than refused on the wire;
a first `chat` message auto-creates a conversation and a peer record
(`ChatDirectHandler.php:250-273`). No kind changes settings, sends, or writes
outside the store. Consistent with `[[reference_contacts_are_permission]]`.

