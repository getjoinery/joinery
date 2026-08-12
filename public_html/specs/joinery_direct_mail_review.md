# Joinery Direct Mail — Security & Design Review

**Status:** F pass (F1–F10 security) and G pass (G1–G7 generality/plugin API)
COMPLETE — resolved and folded into `specs/joinery_direct_mail.md`. A third,
pre-build pass (H1–H5, end of this document) is COMPLETE — all five resolved and
folded in. Companion document,
not a replacement. Each finding is a self-contained unit with a stable ID and a
recorded resolution, kept so settled ground is not relitigated.

**How to read this:** every finding follows the same shape — *what the spec claims*,
*what the code actually shows* (with file:line evidence), *why it matters*, *the
decision to make*, and the recorded **Resolution** that was folded back into
`joinery_direct_mail.md`.

**Evidence base:** claims below were checked against the current tree. Key files:
`plugins/mailbox/ajax/inbound_email_webhook.php`, `includes/SealedBox.php`,
`includes/VaultCrypto.php`, `data/user_encryption_vaults_class.php`,
`plugins/mailbox/includes/RelayMapExporter.php`,
`plugins/mailbox/data/mailbox_contacts_class.php`,
`plugins/mailbox/includes/MailboxContacts.php`,
`plugins/mailbox/data/inbound_email_message_class.php`,
`plugins/mailbox/includes/DeferredIngest.php`, `includes/OutboundTransport.php`,
`includes/UrlSafetyValidator.php`,
`plugins/mailbox/data/inbound_email_domain_class.php`.

---

## Summary table

| ID | Severity | One-line | Blocking build? |
|----|----------|----------|-----------------|
| F1 | High | Attacker-controlled SRV host/port is an SSRF vector on the sender | Yes |
| F2 | High | Dropping manifest hashes removed all integrity binding between signed envelope and delivered parts | Yes |
| F3 | High/Med | Sealing confidentiality collapses under recipient-domain DNS hijack; no DNSSEC required | Should resolve |
| F4 | High | Spec assumes cleartext contacts at Standard; code seals contacts per-vault at any tier | Yes |
| F5 | Med | Block list and ingest rate-limiter are filed under "already exists" but do not exist | Should scope |
| F6 | Med | Replay protection unspecified, in a codebase that habitually omits it | Should resolve |
| F7 | Med | "Spam structurally impossible" has an asterisk at locked Fortress (storage, not inbox) | Reword + bound |
| F8 | Low | Decoy key is distinguishable over time and via the generation field | Clarify |
| F9 | Low | Preflight forces an attacker-named DNS lookup per request (amplification) | Clarify |
| F10 | Low | DKIM custody note implies relay holds the signing key; today the box does | Clarify |

Blocking = needs a spec answer before build starts. Should resolve/scope = needs to be
written into the spec but does not gate starting the build.

---

## F1 — Attacker-controlled SRV target and port (SSRF) — HIGH, BLOCKING

**What the spec claims.** The send decision looks up `_joinery._tcp.<recipient-domain>`
and POSTs the preflight to "the advertised host and port," with port "advertised, never
hardcoded; the default is 443" (spec §The send decision, §The capability record).

**What the code shows.** There is one SSRF guard in the tree,
`includes/UrlSafetyValidator.php` — it resolves every A/AAAA record, blocks
`0.0.0.0/8`, RFC1918, `169.254.0.0/16` (cloud metadata) and returns resolved IPs for
`CURLOPT_RESOLVE` pinning. It is wired into **only two callers**
(`plugins/joinery_ai/recipe_tools/FetchUrlTool.php:121`,
`plugins/dns_filtering/logic/scan_url_logic.php:121`). Every other outbound caller —
including `plugins/mailbox/includes/FleetClient.php:216` — hand-rolls curl with no
guard. There is no shared, SSRF-safe HTTP helper.

**Why it matters.** The recipient domain is attacker-controllable: anyone can publish an
SRV record for their own domain pointing the target at `127.0.0.1`, `169.254.169.254`,
an internal host, or any port. Triggering the request only requires being an address one
of our users emails. The sending instance then issues an outbound POST wherever the
record points. Because the port is advertised rather than fixed, the attacker also
chooses the port (22, 6379, 11211, …). This is a classic server-side request forgery,
and the original spec does not mention it.

**Decision to make.** Mandate that the Direct transport resolves and validates the SRV
target through `UrlSafetyValidator` (or an equivalent), pins the connection to the
validated IP, and constrains the allowed port set. Decide the port policy: 443-only,
443 + an explicit allowlist, or full range with private-range blocking. Decide redirect
policy (Direct should never follow redirects).

**Broader than this feature.** Verifying F1 surfaced that the weakness is platform-wide,
not specific to Direct Mail: there is exactly one SSRF guard (`UrlSafetyValidator`) wired
into two callers, and ~30 other outbound-request callsites hand-roll curl /
`file_get_contents` / `fsockopen` with no guard. None is an *unauthenticated* open SSRF
today (the two live-parameter ones — `utils/cache_benchmark.php`,
`adm/admin_static_cache.php` — are permission-8/9 gated; the SES/SNS fetch is
signature-gated and host-pinned; the rest are superadmin-configured or hardcoded vendor
endpoints). Direct Mail's F1 would add the **first externally-triggerable** outbound
request, which is why it stands apart. The fix is a shared safe HTTP client rather than
30 point patches. That is specified separately in **`specs/safe_http_client.md`**; F1's
port/redirect policy is decision **D1** there. Resolving F1 = pick the port policy and
adopt that client for the Direct transport.

**Resolution:** Resolved. **Port policy (D1): the sender follows an SRV to port 443 or
any port ≥ 1024; privileged ports < 1024 (other than 443) are refused.** 443-only was
rejected because the Direct design deliberately keeps non-443 dedicated listeners open as
a future ("What the advertised port keeps open"); any-port was rejected as too loose. The
≥1024 rule blocks the SSH/SMTP/DNS-class targets while preserving that future, and
mandatory TLS verification is the load-bearing control at any allowed port (a raw
Redis/SSH port cannot present a valid cert, so it never connects). Full sender-side guard,
now folded into `joinery_direct_mail.md` (it was absent — the omission this finding
flagged): resolve the SRV target through the SSRF-safe client (`safe_http_client.md`),
block private/reserved/loopback/link-local IPs, pin the connection to a validated public
IP, restrict the port as above, verify TLS against the SRV hostname, **never follow
redirects**, and fall back to SMTP on any failure. Added to §The capability record (SRV
bullet), §The send decision (new SSRF-guard paragraph), Acceptance #32, and an *Attacks
considered* bullet. D1 also marked RESOLVED in `safe_http_client.md`.

---

## F2 — No integrity binding between the signed envelope and the delivered parts — HIGH, BLOCKING

**What the spec claims.** The instance signature is "an Ed25519 signature over the
message" (spec §Vocabulary). The manifest was deliberately reduced to "size, type, and
part role" with **no** content hashes, to kill the skip-if-held possession oracle (spec
§Message transfer, final paragraph). Content parts arrive on the *second* request.

**What the code shows.** Sealing is `SealedBox::sealDek()` →
`sodium_crypto_box_seal()` (`includes/SealedBox.php:63`), an **anonymous** sealed box:
confidentiality to the recipient's public key, but no sender authentication — anyone
holding `uev_public_key` (a public value; `RelayMapExporter` already ships it off-box)
can construct a valid sealed part.

**Why it matters.** The signature covers the envelope + manifest (sizes/types). With
hashes removed, nothing binds the actual sealed bytes in request two to what was signed
in request one. Since the sealed box is anonymous, any in-path element can substitute
wholesale part content and the receiver will decrypt it cleanly — then apply the
verified-direct mark to attacker-chosen content. This is worst at Fortress, where the
spec calls the relay "a pure forwarder it cannot read": it can't read, but nothing stops
it re-sealing different content to the recipient's public key.

The oracle and the fix do not actually conflict. The possession oracle is
*receiver→sender* (a sender watching which parts get skipped). Signed per-part hashes
provide integrity without enabling skip-if-held, **as long as the receiver always takes
the transfer** — which the spec's own closing paragraph already requires. The spec
conflated "hashes enable a skip oracle" with "no hashes at all" and lost content
authentication.

**Decision to make.** Put per-part content hashes back into the *signed* manifest for
integrity, and forbid the receiver from acting on them differently per part (no
skip-if-held). Confirm the instance signature covers the full manifest including those
hashes. Decide hash function and whether hashes are over ciphertext or plaintext (over
the sealed bytes is simplest and still binds).

**Resolution:** Accepted. Per-part hashes go back into the *signed* manifest, over the
**sealed ciphertext bytes**, as **BLAKE2b-256**. The instance signature is defined to
cover the envelope + full manifest (hashes included) and is computed in the preflight,
so it commits to the exact content before any of it is sent. The receiver hashes each
delivered part at receive time and rejects the whole message on any mismatch; it never
uses a hash to skip, short-circuit, or differentially handle a part (no skip-if-held).
This binds the delivered bytes to the signature without reopening the possession
oracle, because the anti-oracle invariant — *always take the full transfer, then
decide* — is exactly what integrity checking does anyway. Hashing the ciphertext (not
the plaintext) keeps the check runnable at receive time even when a Fortress vault is
locked; the Ed25519 signature authenticates the plaintext transitively at unseal.
Folded into `joinery_direct_mail.md` §Vocabulary (Manifest + Instance signature) and
§Message transfer, which also resolves a pre-existing contradiction — Vocabulary said
"content hash," §Message transfer said "not content hashes." Replay of an intact
{manifest + matching ciphertext} is out of scope here and handled by **F6**.
**Ordering correction (see F10):** the signed sealed-byte hashes travel with the
*content transfer*, not the preflight, because sealing is post-accept — the security
property is unchanged, only the location of the hash. See F10's cross-finding note.

---

## F3 — Sealing confidentiality is only as strong as the recipient's DNS — HIGH/MED, SHOULD RESOLVE

**What the spec claims.** The sender seals each part to the recipient's public key
returned in the preflight `accept`, so "nothing between the two endpoints — proxy, CDN,
or relay — can read it" (spec §Vocabulary, §Encryption). Sealing is framed as making
Direct "strictly better" than SMTP.

**What the code shows.** The sender seals to whatever key the `accept` returns, trusting
that response because it arrived over TLS from the SRV-named host. The capability records
are ordinary DNS (`SRV` + `_joinery-key` TXT); nothing in the spec or the DNS record
management plan requires DNSSEC.

**Why it matters.** A DNS hijack of the recipient domain lets an attacker point SRV at
their own host, terminate TLS with a valid cert for *that* host, and return *their own*
key in the `accept`. The sender seals to it; the attacker decrypts. Sealing provides no
protection, because the key's trust anchor is DNS + the SRV host's TLS cert, both of
which the hijacker controls. The instance signature does not help — it authenticates the
*sender* to the *receiver*, not the receiver to the sender. Binding the key to the
`_joinery-key` TXT does not help either, since a domain hijacker rewrites TXT too.

Net: Direct-with-sealing is no *worse* than email under MX hijack, but it is not the
strict upgrade the Encryption section claims — sealing defends against in-path
middleboxes, not against a compromise of the recipient's DNS.

**Decision to make.** Either (a) require DNSSEC on capability records, (b) adopt a
trust-on-first-use / key-continuity check on the recipient key, or (c) accept the limit
and state plainly in §Encryption that sealing protects against middleboxes, not against
recipient-DNS compromise. Pick one and record why.

**Resolution:** Accepted (c). §Encryption now scopes the claim honestly: sealing is
"better against an in-path reader," not "strictly better," and a new paragraph states
that under a recipient-DNS hijack Direct is *no worse than SMTP* but not an
unconditional upgrade — the bar rises from "sit anywhere in the path" to "control the
recipient's DNS." (a) rejected as a hard requirement (mandating DNSSEC makes Direct
unavailable to the DNSSEC-less majority) but recorded as an *ambient* benefit: a
recipient running DNSSEC closes the redirect for free via a validating resolver, and
Direct honors it without requiring or re-implementing it. (b) rejected for v1 in its
key-caching form because it collides head-on with the spec's deliberate
never-cache-the-key rule (a key cached across a rotation seals unopenable messages);
TOFU on *box identity* (not the key) is noted as possible later hardening. No code
change — wording only. Folded into `joinery_direct_mail.md` §Encryption.

---

## F4 — Contact sealing is per-vault, not per-tier; the Standard live-gate premise is false — HIGH, BLOCKING

**What the spec claims.** "At Standard the gate runs live, so a non-contact gets
`declined` on the wire" and "(The live two-answer gate … applies at Standard, where
contacts are cleartext and the gate runs at receive.)" (spec §Security levels). The whole
Standard-vs-Fortress split rests on *contacts being cleartext at Standard*.

**What the code shows.** `mailbox_contacts_class.php` / `MailboxContacts` seal contact
rows based purely on **whether the adding user holds a Sealed Vault** — there is no
`security_level()` / `seals_content()` check anywhere in the contacts path (contrast
messages: `InboundEmailRouter.php:448` gates on `$domain->seals_content()`). A
vault-holding user on a **Standard** domain has sealed contacts, and
`MailboxContacts::listForMailbox()` returns `['locked'=>true]` when their window is
closed (`MailboxContacts.php:231`).

**Why it matters.** For a vault-holding user at Standard/Private with the vault locked,
the live contact gate cannot run at receive time. Both escapes are bad:
- Defer like Fortress — but the spec gives Standard no deferred-ingest path.
- Fall back to `declined` when locked — which **reintroduces the exact lock-state
  oracle** the Fortress design worked to close (locked → `declined`, unlocked+contact →
  `accept`), now observable at Standard/Private.

As written, the spec's tier model and the code contradict each other, and the resolution
touches the oracle guarantees.

**Decision to make.** Reconcile the model. Options: (a) run the deferred-ingest path
whenever the contact list is unreadable regardless of tier (accept-then-decide-locally
everywhere the vault is locked), (b) redefine the tiers so the live gate is only claimed
when contacts are genuinely readable, or (c) something else. Whichever, the lock-state
oracle must not reappear at Standard/Private.

**Resolution:** Accepted via option (b), realized as: **contact sealing gates on the
same condition as mail sealing** — `vault present AND $domain->seals_content()` —
instead of vault-possession alone. This makes contacts genuinely readable at Standard
(the live gate always runs; no locked-contacts case exists there) and sealed only at
Private/Fortress (which already always accept-and-file-locally, so no live `declined`
answer and no lock-state oracle). Option (a) was rejected: it would preserve a
"plaintext mail + sealed contacts" state that secures nothing — every correspondent is
already exposed by the plaintext mail — at the cost of a new deferred-gate path and a
tolerated lock-state flip at Standard. Aligning the two axes onto one posture switch
removes the incoherence instead of adding machinery.

**Build item (code, not just spec).** The contacts path currently seals whenever
`$vault !== null` (`MailboxContacts::upsertOne`/`sealContact`, and the address-hash
selection in `addressHash()`/the data class). It must additionally require
`$domain->seals_content()` for the mailbox the contact belongs to, mirroring
`InboundEmailRouter.php:448`. At Standard a vault holder's contact rows then store like
a no-vault user's: `imc_content_sealed=false`, plaintext columns, plain-SHA-256 index —
a state the schema already supports. No data migration (pre-launch). Folded into
`joinery_direct_mail.md` §Security levels.

---

## F5 — Block list and ingest rate-limiter are claimed as existing but do not exist — MED, SHOULD SCOPE

**What the spec claims.** "Mark as spam / block = remove the contact *and* add the sender
to the block list," blocked senders get an indistinguishable `declined`, and "the
endpoint rate-limits by sending instance and drops early once a signature identifies a
blocked sender" (spec §Abuse, §The receive decision).

**What the code shows.**
- **No block list.** No blocked-sender table or column exists in the mailbox schema. The
  nearest mechanism is an inbound *filter* rule
  (`inbound_email_filter_class.php:92`) with actions `mark_spam` / `delete`. Contacts are
  documented as a deliberate-entry cache with no negative/traffic-derived state.
- **No ingest rate limiter.** The only rate limiters
  (`InboundEmailRouter.php:275`, `:2290`) gate outbound *forwarding*. Nothing throttles
  the inbound webhook.

**Why it matters.** Two of the abuse defenses the design leans on are net-new, not reuse.
The "reuse, don't reinvent" framing presents unbuilt security controls as
already-proven. The oracle-closure argument for blocked senders specifically depends on
block state existing and behaving identically to non-contact.

**Decision to make.** Scope both as build items. Decide how "block" maps onto the
existing filter engine (a sender-criteria `mark_spam` rule, or a first-class block
store), and define the ingest rate-limit key (sending instance identity from the
verified signature) and its bounds.

**Resolution:** Scoped. **Block = reuse the filter engine, no new store:** "block
sender X" is remove-the-contact **+** a sender-matched `mark_spam` inbound filter
(`inbound_email_filter`: `fil_match_from`, `fil_action_mark_spam`). Because blocking
removes the contact, a blocked sender is already a non-contact at the Direct gate and
gets the same `declined` — no separate gate branch, no gate-time block lookup. The block
is a post-storage disposition (the filter engine runs `matches()`/`applyActions()`
against a stored message), which is exactly the "filed to spam at unlock" behavior
§Security levels already settled. This also resolved an **internal contradiction**: the
old "endpoint drops early once a signature identifies a blocked sender" claim required
the very locked-readable block index §Security levels rejects — that clause is removed.
**Rate limiter = build item:** key on the *verified sending-instance identity* (not the
individual sender, so no per-recipient block state and no locked-index problem), applied
to preflights at the endpoint / at the relay under Fortress. Default policy: token
bucket, **60 preflights/min per instance, burst 120**, as a declared setting (tunable).
Folded into `joinery_direct_mail.md` §The receive decision (step 3 Blocked bullet),
§Abuse (both bullets), and cross-referenced by F7's spam-cap wording. **Amended by
H4:** the token-bucket default is re-expressed as the platform's existing
sliding-window limiter idiom — 120 preflights per rolling 2 minutes, the same
average and burst — reusing `RequestLogger::check_rate_limit` (per-peer) and
log-window counts (per-instance); no new rate-limiting engine.

---

## F6 — Replay protection unspecified — MED, SHOULD RESOLVE

**What the spec claims.** The instance signature is "over the message" (spec §Vocabulary).
No nonce, timestamp, or freshness window is mentioned.

**What the code shows.** Every existing inbound-signature path in the repo is replayable:
Mailgun verifies `hash_hmac('sha256', timestamp.token, key)` with **no** freshness check
and no token cache (`MailgunProvider.php:579`); SES/SNS has no `Timestamp` check and still
accepts SHA1 (`SesProvider.php:611`). The codebase precedent is to omit anti-replay.

**Why it matters.** Without a signed timestamp + freshness window and a nonce/dedup
cache, a captured preflight + content can be replayed to re-deliver a message. Left
unstated, Direct will inherit the same omission by default.

**Decision to make.** Mandate a signed timestamp with a short acceptance window and a
replay cache (nonce or envelope-id dedup) in the receive path. Specify the window and the
cache lifetime.

**Resolution:** Accepted. The envelope carries a **signed timestamp** and a **random
per-delivery nonce (≥128 bits)**, both under the instance signature (which F2 already
extended to cover the full envelope + manifest). Receive step 2 (new, before the contact
gate) refuses a timestamp older than **5 min** or more than **1 min** in the future, and
refuses a nonce already seen. The nonce cache TTL is **10 min** — longer than the
acceptance window on purpose, so the freshness check and the cache compose with no gap
(a replay that aged out of the cache is already too stale to pass freshness). Chosen
properties: the cache stores only opaque nonces + expiries (no sealed data), so it dedups
while a Fortress vault is locked; and a replay failure is a request-level refusal in the
same bucket as an invalid signature — not one of the two contact-gate answers — so it
adds no oracle (only a replayer, who already holds the message, triggers it). Send step 2
notes the sender generates a fresh timestamp+nonce per attempt, so benign retries never
collide. Folded into `joinery_direct_mail.md` §The send decision (step 2) and §The
receive decision (step 2, with 3/4 renumbered).

---

## F7 — "Spam structurally impossible" has an asterisk at locked Fortress — MED, REWORD + BOUND

**What the spec claims.** "Spam is structurally impossible on the direct path,"
"everything unsolicited never reaches the direct receiver's inbox step" (spec §Why there
is no spam problem in v1).

**What the code shows.** At Fortress with the vault locked the contact gate cannot run
(`DeferredIngest.php`, pending-parse spool `iem_pending_parse` /
`iem_relay_sealed_raw`). Combined with the decoy-key behavior (every address, real or
not, receives an `accept`), any holder of a valid instance signature can get accepted and
stored against guessed addresses until unlock.

**Why it matters.** The inbox claim holds — junk is filed to spam at unlock — but
"unsolicited never reaches the receiver" is false at that tier: it reaches *storage*. It
is no worse than SMTP-at-locked-Fortress today, so it is defensible, but the absolute
framing overstates. The bound on this is the (currently nonexistent, see F5) rate
limiter.

**Decision to make.** Soften the §Why there is no spam problem wording to "no unsolicited
mail reaches the *inbox*," acknowledge the bounded storage cost at locked Fortress, and
point at the rate limiter as the cap. No design change required beyond F5.

**Resolution:** Accepted, wording only. §Why there is no spam problem now leads with "no
unsolicited mail reaches the *inbox*" (not "spam is structurally impossible"), then adds
an explicit paragraph: the one asterisk is *storage, not inbox, and only at locked
Fortress*, where a signed message is spooled before the gate can run and filed at
unlock — no worse than SMTP-at-locked-Fortress and bounded by the F5 per-instance
preflight rate limiter. The intro's absolute "nothing unrequested can arrive on it" is
narrowed to "…can reach your inbox on it," with a pointer to the bounded nuance. No
design change beyond F5. Folded into `joinery_direct_mail.md` (intro + §Why there is no
spam problem).

---

## F8 — Decoy key is distinguishable over time and via the generation field — LOW, CLARIFY

**What the spec claims.** The Fortress decoy is `HMAC(domain secret, lowercased address)`
seeded into a valid X25519 point, "indistinguishable from a real key," and deterministic
(spec §Encryption). The `accept` "carries the recipient's current public key and key
generation."

**What the code shows.** A real vault key changes on rotation (`uev_key_generation`
increments, `user_encryption_vaults_class.php:60`); a decoy is deterministic and never
changes.

**Why it matters.** An attacker probing the same address across a rotation sees a real
key move while a decoy stands still — a weak long-horizon distinguisher. Separately, if
real accepts carry an incrementing generation and decoys carry a fixed/absent one, the
generation field itself is the tell that defeats the decoy.

**Decision to make.** Specify what generation value a decoy reports so it is
indistinguishable from a plausible real one, and decide whether the residual
rotation-timing distinguisher is acceptable (likely yes, given the cost of probing) —
record the reasoning either way.

**Resolution:** Accepted. A decoy reports **generation 1** — the value a never-rotated
vault carries (`uev_key_generation` default = 1) and the modal real answer, so seeing
generation 1 discloses nothing and the decoy stays deterministic. The residual
distinguisher (a *rotated* address reports generation > 1, and a real key advances across
a rotation while a decoy stands still) is **accepted on the record**: it is one-sided
(confirms existence only, never denies), fires only for an address the attacker already
guessed *and* whose owner has rotated (a rare event), and closing it fully would require
the decoy to forge a believable per-address rotation history — complexity not worth it
against a probe that must first guess a low-entropy address. Wording only, no code.
Folded into `joinery_direct_mail.md` §Encryption (decoy section).

---

## F9 — Preflight forces an attacker-named DNS lookup per request — LOW, CLARIFY

**What the spec claims.** On preflight the receiver verifies the instance signature "against
the sender domain's capability record (fresh DNS lookup, key id matched)" (spec §The
receive decision).

**Why it matters.** The sender domain in a preflight is attacker-chosen, so each request
forces the receiver to resolve an attacker-named domain. Unbounded, this is a
DNS-amplification / forced-outbound lever.

**Decision to make.** Cache the capability lookup with a sane TTL, and rate-limit or
short-circuit preflights whose sender domain fails to resolve, so an attacker cannot pin
the receiver to unbounded outbound DNS.

**Resolution:** Accepted. §The receive decision (step 1 + new *Resolving the sender's
capability record*) now mandates three bounds: **cache** the capability record by
domain/key-id honoring DNS TTL (with a single, rate-limited refresh on an unseen key id
so rotations are still picked up); **negative-cache** no-record/NXDOMAIN/SERVFAIL as "no
Direct"; and **rate-limit by connecting peer** — the pre-verification limiter, since
instance identity (F5's key) isn't known until after the lookup. Scoped explicitly as a
*lookup-volume* concern via the system resolver, not the `safe_http_client.md` SSRF
surface (DNS, not HTTP). Also added Build-plan wording (resolver helper caches +
rate-limits), Acceptance #29, and an *Attacks considered* bullet. **Incidental fix:** the
*Attacks considered* "manifest hash as a possession oracle" bullet was stale from F2
(claimed "manifest carries size and type only, not hashes") and is corrected to the
integrity-hash-but-never-skip resolution. Folded into `joinery_direct_mail.md`.

---

## F10 — DKIM custody note implies the relay holds the signing key — LOW, CLARIFY

**What the spec claims.** "Send-side key custody … sealed and off-box at Fortress, where
the relay holds the instance signing key alongside the sending identity it already
holds" (spec §Security levels).

**What the code shows.** Today the protected/Fortress DKIM private key is sealed in the DB
(`ied_dkim_sealed_key`) and unwrapped **on the box**, in-window, per send
(`MailboxDkimSigner.php:90`); it is zeroed after signing. The relay never holds it
(explicit at `OutboundTransport.php:101`).

**Why it matters.** The spec implies parity with existing DKIM custody, but moving the
instance signing key onto the relay would be a *new* custody model, not the one that
exists. This affects the F2/F3 trust story (what the relay can and cannot do).

**Decision to make.** Decide whether the instance signing key lives on the relay (new
custody, new exposure to reason about) or on the box like DKIM (relay originates the
connection but does not sign). Align the §Security levels text with the choice.

**Resolution:** Accepted — **box signs, relay transports**, mirroring the existing DKIM
custody exactly (`MailboxDkimSigner` unwraps in-window on the box and zeroes; the relay
carries an app-signed message it cannot alter, per `OutboundTransport`). The instance
signing key never moves to the relay; relay-holds-key is rejected as a new, more-exposed
custody model that contradicts the "relay is a pure forwarder" posture. §Security levels
"Send-side key custody" rewritten. Wording only, no code.

**Cross-finding correction (F2 ordering).** Grounding F10's "who signs when" exposed that
F2's fold-in had the per-part **ciphertext hashes in the preflight manifest** and claimed
the signature "commits to content before a byte crosses" — but sealing is *post-accept*
(the key rides the accept; step 4), so the sealed bytes do not exist at preflight time.
Corrected: the signed per-part sealed-byte hashes travel **with the content transfer**
(a second box signature, bound to the preflight nonce), verified by the receiving box
against the delivered ciphertext *without unsealing* (so a locked Fortress box still
rejects a relay substitution at receive). Ciphertext hashing retained. Updated
`joinery_direct_mail.md` §Vocabulary (Manifest, Instance signature), §The send decision
(step 4), §Message transfer, the *Attacks considered* bullet, and Acceptance #30–#31. F2
above remains resolved; this only fixes where the integrity hash lives.

---

## Not flaws — what held up under review

Recorded so we do not re-examine settled ground:

- **Oracle discipline** (two answers on the wire, accept-then-file-locally at Fortress,
  no bounce/backscatter) is internally consistent.
- **Rejecting the locked-readable contact index and the blocklist index** is sound: a key
  usable while locked is a key a box-thief holds, and low-entropy addresses make the set
  dictionary-guessable. The "anything the locked box can compute, a thief can compute"
  rule is correct.
- **Deferred-ingest reuse** is real and accurately described (`iem_pending_parse`,
  `iem_relay_sealed_raw`, `DeferredIngest.php`, Fortress-only via `key_kind=user`).
- **Sealing primitive** exists as described (`crypto_box_seal` to `uev_public_key`;
  `uev_key_generation` present).
- **`RelayMapExporter` user/transport `key_kind` distinction** exists
  (`RelayMapExporter.php:178`), so the preflight-answer reuse is real.
- **Parts-not-a-blob storage** matches the existing model
  (`imc_inbound_message_attachment`, per-row `ima_is_sealed`).
- **Named tiers** Standard/Private/Fortress exist (`inbound_email_domain_class.php:51`).

---

## Walkthrough order

Suggested order for slow review, hardest-first among the blockers:

1. **F2** (integrity binding) — its fix interacts with the manifest design.
2. **F4** (contact sealing vs tier) — touches the oracle guarantees.
3. **F1** (SSRF) — mostly mechanical once the policy is chosen.
4. **F3** (DNS trust anchor) — decide the honest security claim.
5. **F6, F5, F7** — controls to write into the spec.
6. **F8, F9, F10** — clarifications.

Take them one at a time; record the outcome in each finding's **Resolution** line and
fold accepted changes back into `joinery_direct_mail.md`.

---

# Second pass — the pipe as a platform service (G-findings)

**Status:** COMPLETE — G1–G7 all resolved and folded in. This pass
reviews a different question: the spec claims "the pipe is general; mail is its
founding payload" — is that claim *load-bearing*? Can any part of Joinery (core or a
plugin) actually use the channel to talk to another instance, and is the developer
surface straightforward? The verdict: **the security design generalizes cleanly, but
the spec never defines the service interface it promises.** Every shared-layer
property (discovery, signing, preflight, sealing, replay, rate limits) is written as
prose about the mail kind, embedded in mail code paths. The messenger spec — the
pipe's own second consumer — already exposes the gap: it says chat ingest is
"registered with the Direct receiver the way inbound webhook handlers are," and no
such registration mechanism exists anywhere (there is exactly one webhook file, not a
registry).

Same finding shape as the first pass. Same workflow: walk one at a time, record a
Resolution, fold into `joinery_direct_mail.md`.

## Summary table

| ID | Severity | One-line | Blocking build? |
|----|----------|----------|-----------------|
| G1 | High | The pipe has no send API — it is built inside the mail transport | Yes |
| G2 | High | No receive-side registration contract for payload kinds | Yes |
| G3 | High | Oracle discipline is prose; the handler contract must make it structural | Yes |
| G4 | Med | Locked-Fortress deferred path is specified for mail only | Should resolve |
| G5 | Med | Relay kind-agnosticism undecided — decides whether new kinds need a fleet upgrade | Should resolve |
| G6 | Low | Addressing/sealing contract for non-mail kinds unstated | Clarify |
| G7 | Low | No protocol version on the envelope | Clarify |

---

## G1 — The pipe has no send API; it is built inside the mail transport — HIGH, BLOCKING

**What the spec claims.** "The pipe is general; mail is its founding payload"
(§What this is for). The messenger is already named as the second kind, and future
kinds (calendar invites, contact-card exchange, drive share offers) are anticipated.

**What the spec actually specifies.** Build plan item 2: "a new `EmailServiceProvider`
that preflights … Wire it into the `EmailSender` transport resolver as a branch." The
entire send side — SRV discovery, SSRF-guarded connect, preflight, sealing, part
transfer, fallback — lives inside a mail transport resolved by `EmailSender::send()`.
`EmailServiceProvider` is a mail interface (`send(EmailMessage)`,
`getSpfMechanism()`, DKIM status); a chat message or a drive share offer cannot go
through it, and §The send decision bakes mail policy into the pipe itself: "on any
connection failure … fall back to the existing SMTP/relay path." SMTP fallback is a
*mail-kind* behavior — chat's own spec defines `declined` as "renders not-reachable,"
and a future kind will define something else again.

**Why it matters.** As written, the messenger build must either duplicate the pipe or
reach into a mail transport class for non-mail traffic — both wrong. The generality
claim needs a named artifact.

**Decision to make / recommendation.** Define a first-class client — e.g.
`JoineryDirect::send($recipient_address, $kind, array $parts, $options)` — that owns
the whole shared layer (capability resolution + cache, SSRF-guarded connect,
preflight with timestamp/nonce, sealing to the returned key, signed sealed-byte
hashes, part transfer) and returns a **typed result**, not a behavior:
`delivered | declined | no_capability | failed`, plus whether content was sealed and
to which key generation. The caller owns kind policy: the mail
`EmailServiceProvider` becomes a thin adapter that maps everything short of
`delivered` to the SMTP path; chat maps it to not-reachable. Restructure the spec so
§The send decision describes the client, and the SMTP fallback moves to a mail-kind
subsection. One send call for every consumer, mail included — mail is caller #1, not
the owner.

**Resolution:** Accepted as recommended. The send side is now defined as the
**Direct client** — `JoineryDirect::send($recipient_address, $kind, $parts,
$options)` — owning the whole shared layer (capability resolution + cache,
SSRF-guarded connect, preflight with timestamp/nonce, sealing, signed sealed-byte
hashes, part transfer) and returning a typed result (`delivered | declined |
no_capability | failed`, plus sealed-or-not and key generation), never a behavior.
Kind policy moves to the caller: the mail `EmailServiceProvider` is a thin adapter
that calls the client with `kind: mail` and maps every non-`delivered` result to the
SMTP/relay path — the SMTP fallback exists only there, and no other kind's result
ever produces an SMTP send. Folded into `joinery_direct_mail.md`: §Vocabulary (new
*Direct client* entry), §The send decision (rewritten around the client, with a
"mail is the client's first caller, not its owner" paragraph; SSRF-guard and
forged-refusal wording now speak in results, not SMTP), §The SMTP fallback boundary
(reframed as the mail adapter's result mapping), Build plan item 2 (client + mail
adapter), and new Acceptance #33.

---

## G2 — No receive-side registration contract for kinds — HIGH, BLOCKING

**What the spec claims.** "The route first dispatches on the envelope kind" and "a
receiver refuses a preflight whose kind it does not serve" (§The receive decision).
The messenger spec assumes chat ingest is "registered with the Direct receiver the
way inbound webhook handlers are" (§8.4).

**What the code shows.** There is no webhook-handler registry to imitate —
`plugins/mailbox/ajax/` contains exactly one webhook file. Nothing in either spec
says how a kind maps to its consumer, so "dispatch on kind" has no defined mechanism
behind it.

**Why it matters.** This is the plugin-developer surface. Without a declared
contract, each new kind is a core edit to the receiver route — which forecloses
plugin-provided kinds entirely and makes the refuse-unserved-kinds check ad hoc.

**Decision to make / recommendation.** Registration should be **declarative**, in the
idiom the platform already uses for everything else (`plugin.json` already declares
`settings`, `signals`, `adminMenu`, `provisioners`): a `directKinds` key mapping kind
name → handler class, with core kinds (mail) declared equivalently. Declarative
registration makes "which kinds does this instance serve" answerable cheaply at
preflight time without loading plugin code, keeps unknown-kind refusal honest for
deactivated plugins (deactivated ⇒ kind not served — the partially-upgraded-federation
story falls out for free), and gives the docs one place to point a plugin developer.
Add the handler interface itself in G3. Fold the registration mechanism into
§The receive decision and the Build plan.

**Resolution:** Accepted as recommended (resolved together with G3). Registration is
declarative: a `directKinds` key in `plugin.json` (kind name → handler class), core
kinds declared the same way with mail as the first entry. The registry is instance
configuration readable without loading plugin code; a deactivated plugin's kind is
absent from the served set, so its preflights refuse exactly like an unknown
kind's — request-level, before any handler runs. Folded into
`joinery_direct_mail.md` as the new §Serving a kind section (plus a pointer from
§The receive decision, a rewritten Build plan item 3, and Acceptance #34). The
messenger spec's stale phrase ("registered the way inbound webhook handlers are" —
no such registry existed) now references `directKinds` and the canned contact gate
(§8.3).

---

## G3 — The oracle discipline must be structural in the handler contract, not prose — HIGH, BLOCKING

**What the spec claims.** "Authorization is per-kind — the contact gate defined here
is the mail and chat rule, not a property of the channel, and a future kind … defines
its own gate" (§What this is for).

**What that implies as written.** The spec's hardest-won properties — exactly two
answers on the wire; request-level refusals distinct from gate answers; Fortress
accepts unconditionally with a decoy key; no lock-state oracle; reject-at-unlock is
local, never returned — are currently prose describing the *mail* gate. If each
kind's author hand-implements a gate against the raw request, one of them will
eventually return a third answer, answer differently while locked, or bounce — and a
single sloppy kind reopens, for the whole endpoint, the oracles F-pass closed. A
receiving endpoint is only as oracle-free as its leakiest kind.

**Decision to make / recommendation.** Split the receive path into what the
**framework** owns and what the **kind handler** may express, and write it into the
spec as a table. Framework (runs identically for every kind): signature
verification, freshness/replay, per-instance rate limit, kind dispatch, key answer
including Fortress decoy derivation, sealed-byte hash verification, spooling while
locked. Handler contract (the entire per-kind surface):

- `gate(envelope): accept | decline` — a pure decision. The handler never sees lock
  state, never composes a wire response, and at Fortress is **not called at
  receive** — the framework accepts unconditionally and defers (G4).
- `ingest(envelope, parts): void` — store the delivered payload; runs only after the
  framework has verified hashes (and, at Fortress, only at unlock after the deferred
  gate).

With that shape, a kind *cannot* create a third wire answer, a lock-state
distinguisher, or a bounce — the discipline holds by construction, and reviewing a
new kind means reviewing two pure functions. Also offer the **contact gate as a
canned, reusable gate** (mail and chat both use `imc_mailbox_contacts`; a plugin kind
should be able to opt into it in one line rather than reimplement it).

**Resolution:** Accepted as recommended (resolved together with G2). The new
§Serving a kind section in `joinery_direct_mail.md` draws the line as a table:
the framework owns everything on the wire — signature verification,
freshness/replay, rate limits, kind dispatch, every wire answer including the
Fortress key/decoy, sealed-byte hash verification, spool-while-locked and unlock
scheduling — and a handler's entire surface is two pure functions:
`gate(envelope): accept | decline` (never sees lock state, never composes a wire
response, not called at receive under Fortress — the framework accepts
unconditionally and defers) and `ingest(envelope, parts)` (runs only after hash
verification, and at unlock under a locked vault). A `decline` becomes `declined`
on the wire at Standard and a silent local filing at Fortress, and the handler
cannot tell which. The contact gate is exported as a canned reusable gate — mail
and chat declare it; a new kind opts in or supplies its own. Also folded: Build
plan item 3 and Acceptance #35 (no kind can produce a third wire answer, a
lock-state-dependent answer, or a bounce).

---

## G4 — The locked-Fortress deferred path is specified for mail only — MED, SHOULD RESOLVE

**What the spec claims.** While locked, Direct "accepts and seals, exactly like SMTP
deferred ingest," and at the next unlock "the existing unseal → parse pipeline gains
one step: run the contact gate" (§Security levels).

**What that covers.** The mail kind. The spool named is the mail pending-parse spool
(`iem_pending_parse`), the unlock step is a mail-pipeline step, and storage lands in
`iem_inbound_email_messages`. A chat message or a future kind arriving at a locked
Fortress box has no specified holding place and no specified way for its gate and
ingest to run at unlock.

**Decision to make / recommendation.** Make deferral a shared-layer service: the
framework spools accepted-while-locked deliveries *keyed by kind* (sealed parts +
envelope + verified-sender fact, none of it needing the vault), and at unlock invokes
each kind's `gate` then `ingest` from the handler contract (G3). Specify the edge
cases: a spooled kind whose plugin is deactivated or uninstalled before unlock
(recommend: hold until reactivation or expire quietly with the spool's retention —
never an error back to anyone), and note that mail's existing pending-parse flow
becomes the mail kind's implementation of this contract rather than a special case.

**Resolution:** Accepted, with one refinement the recommendation missed. Deferral is
now a shared-layer service: the framework owns a **Direct spool** keyed by kind
(envelope + verified-sender fact + sealed parts — nothing needing the vault; hash
verification already runs at receive per F10), drained at the same unlock trigger as
the existing SMTP pending-parse spool, running each delivery's deferred `gate` and
then its `ingest`. The refinement: because the sender was already answered `accept`,
a deferred decline is a **local disposition, not a drop** — mail files a declined
message where SMTP would have (ordinary/spam, no mark) rather than losing it — so
the handler signature gained the outcome parameter:
`ingest(envelope, parts, gate_outcome)`. On the live path ingest still runs only on
accept. Deactivated-plugin edge case resolved as recommended: spooled deliveries are
held sealed until reactivation or expire quietly with the spool's retention; nothing
is ever returned to the sender. Folded into `joinery_direct_mail.md`: §Serving a
kind (table + ingest bullet), §Security levels (rewritten while-locked paragraph +
new deactivated-plugin paragraph), and Acceptance #36.

---

## G5 — Relay kind-agnosticism is undecided, and it decides whether plugins can ship kinds — MED, SHOULD RESOLVE

**What the spec claims.** At Fortress "the relay authenticates, the box authorizes" —
the relay terminates Direct, verifies signatures, drops forged senders, rate-limits
(§Security levels). New kinds are expected from plugins (§What this is for).

**The unstated question.** Does the relay participate in kind dispatch? If the relay
must know which kinds the tenant's box serves (to refuse unserved kinds at the
edge), then every plugin that adds a kind drags a `RELAY_VERSION` bump and a fleet
upgrade — which quietly kills the plugins-can-add-kinds story for Fortress tenants,
the tier the relay exists to serve.

**Decision to make / recommendation.** Declare the relay **kind-agnostic**: it
authenticates, rate-limits, and forwards *any* kind; kind refusal is the box's job.
The box can refuse an unserved kind live even while locked — the kind registry (G2)
is instance configuration, not sealed per-user data, so answering "I don't serve
chat" discloses nothing personal and adds no oracle. State this in §Security levels
(relay paragraph) and add an acceptance item: a plugin-provided kind works through an
unmodified relay with no fleet upgrade.

**Resolution:** Accepted, with the recommendation corrected on one point. "Kind
refusal is the box's job" cannot hold literally at Fortress — the relay terminates
the wire and answers preflights there, so the refusal must happen at the edge. The
resolution that keeps the goal: relay **code** is kind-blind (a kind is an opaque
string it compares, never logic it implements), and the tenant's **served-kind
list** is exported to the relay as data in the relay map `RelayMapExporter` already
ships. The relay refuses unserved kinds at the edge exactly as the box would —
request-level, no oracle, since the served set is instance configuration, not
per-user data. A new kind therefore reaches the fleet as a map update; a
`RELAY_VERSION` bump happens only when the shared layer itself changes. Deactivation
propagates the same way; a delivery accepted in the propagation window lands in the
G4 Direct spool and is held or expires there. Folded into `joinery_direct_mail.md`:
§Serving a kind (relay-map paragraph), §Security levels (new "relay is
kind-agnostic by construction" paragraph), Build plan item 4 (map additions bullet),
and Acceptance #37.

---

## G6 — The addressing and sealing contract for non-mail kinds is unstated — LOW, CLARIFY

**What the spec assumes.** Everywhere, implicitly: the recipient is a *user mail
address* on a mail-capable domain, and sealing targets that user's vault public key
via the mailbox/relay-map machinery. Chat adopts this explicitly ("your Joinery mail
address is your chat handle").

**Why it needs saying.** A future consumer will eventually want instance-to-instance
exchange with no user in it (fleet coordination, server-manager traffic) and will
reach for the shiny federation pipe. That is the wrong tool — the pipe's consent
model, key discovery, decoys, and Fortress deferral are all *per-user* constructs —
and `FleetClient` territory already exists for machine-to-machine.

**Decision to make / recommendation.** One paragraph in §Vocabulary or §What this is
for: the pipe's addressing unit is a user address; every kind's recipient is a user;
sealing is to that user's vault key generation returned in the `accept`; kinds
without a user recipient are out of scope for this channel. This is the sentence
that answers a plugin developer's first two questions — "who do I address, who do I
seal to" — and fences off the misuse before it happens.

**Resolution:** Accepted as recommended, wording only. A new "The pipe addresses
people, not machines" paragraph in §What this is for states the contract: every
kind's recipient is a user address on a capability-publishing domain; consent, key
discovery, decoys, and deferred authorization are per-user constructs; sealing is
always to the recipient user's vault key from the `accept`; payloads with no user
recipient (fleet coordination, machine-to-machine sync) are out of scope and stay
on `FleetClient`-style machine channels. Folded into `joinery_direct_mail.md`
§What this is for.

---

## G7 — No protocol version on the envelope — LOW, CLARIFY

**What the spec has.** Kind dispatch handles unknown *payloads* (refuse, converge
with all other failures), and the key id in the capability record handles signing-key
rotation. Nothing names the version of the shared layer itself — envelope shape,
manifest fields, hash algorithm, signature construction.

**Why it matters.** The first change to the shared wire format (a new manifest
field, a hash migration) will otherwise have to be inferred from behavior across a
federation that upgrades at different speeds. A version field costs one integer now
and is painful to retrofit into a signed structure later.

**Decision to make / recommendation.** Add a protocol version to the signed
envelope. An unrecognized major version is refused in the same request-level bucket
as an unknown kind — which, per the spec's own convergence rule, a sender treats
like any other failure. Note in §Vocabulary; one acceptance item.

**Resolution:** Accepted as recommended. The signed envelope carries a **protocol
version** integer naming the shared layer's version (envelope shape, manifest
fields, hash and signature construction), distinct from the kind, which names the
payload. An unimplemented version is refused in the same request-level bucket as an
unknown kind, and the sender treats the refusal like any other failure, so version
skew converges on the caller's fallback. Folded into `joinery_direct_mail.md`
§Vocabulary (new entry) and Acceptance #38.

---

## G walkthrough order

1. **G1** (send API) — everything else assumes the client exists.
2. **G2 + G3 together** (registration + handler contract) — two halves of one
   developer surface.
3. **G4** (deferred spool) — follows directly from G3's contract.
4. **G5** (relay agnosticism) — one declared sentence, one acceptance item.
5. **G6, G7** — clarifications.

---

# Third pass — pre-build review (H-findings)

**Status:** COMPLETE — H1–H5 all resolved and folded in. A final read of the
folded spec before implementation, focused on internal consistency after the F/G
folds, and on the developer surface ("all complex logic behind the API"). Same
shape and workflow as the earlier passes.

## Summary table

| ID | Severity | One-line | Status |
|----|----------|----------|--------|
| H1 | High | Private tier's wire behavior contradicted the F4 resolution — decoys and unconditional accept were scoped Fortress-only | RESOLVED |
| H2 | Med | The accept→transfer delivery session (second-request auth, single-use redemption, manifest enforcement window) is unspecified | RESOLVED |
| H3 | Med | Message/part size limits are load-bearing ("refusal before transfer") but no layer owns enforcing them | RESOLVED |
| H4 | Med | The locked sealed-tier storage bound doesn't survive the spec's own Sybil analysis — the per-instance limiter is per-domain and domains are cheap | RESOLVED |
| H5 | Low | Developer API surface underdefined: part descriptor/streaming, typed envelope object, canned-gate opt-in mechanics, loopback test path, silent-fallback visibility | RESOLVED |

---

## H1 — Private's wire behavior contradicted the F4 resolution — HIGH, RESOLVED

**What the spec claimed.** F4's resolution sealed contacts at Private *and*
Fortress and recorded that both tiers "already always accept-and-file-locally, so
no live `declined` answer and no lock-state oracle." But §Encryption still said
"Decoys are a Fortress mechanism only… At Standard and Private the contact gate
refuses a stranger with `declined` before any key is offered," and every
accept-and-defer, decoy, and no-lock-state-oracle passage — in the spec and in
`docs/joinery_direct.md` — was written Fortress-only.

**Why it matters.** Both cannot hold. At Private the contacts are sealed, so the
live gate cannot run while the vault is locked. The only alternative reading —
answer live while unlocked, defer while locked — is precisely the lock-state
oracle F4 forbade at Private.

**Resolution:** Private's wire behavior is defined as **identical to Fortress**:
accept unconditionally whether locked or unlocked, never a live `declined`, answer
every preflight with a key (deterministic decoy, generation 1, for unknown
addresses), and defer `gate` + `ingest` through the shared Direct spool. Only
topology differs: Fortress adds the relay and edge concealment; a Private box
terminates its own connections and holds the decoy domain secret locally (at
Fortress it travels in the relay map, since the relay answers preflights there).
The blind-index rejection gains a uniformity argument: Private shares Fortress's
receive path rather than gaining a mechanism whose only job is saving the middle
tier a deferral. Folded into `joinery_direct_mail.md` (§What this is for nuance,
§The receive decision tier scoping, §Serving a kind gate bullet + framework table,
§Encryption both decoy paragraphs + secret custody, §Security levels heading and
five paragraphs, §Why there is no spam problem, Acceptance #3/#14/#16/#23, three
*Attacks considered* bullets) and `docs/joinery_direct.md` (§Receiving, handler
`gate` bullet, §Security tiers merged bullet).

---

## H2 — The accept→transfer delivery session was unspecified — MED, RESOLVED

**What the spec claimed.** The `accept` "admits the declared manifest" and the
content transfer is "bound to the preflight nonce" — implying state between the
two requests that the spec never named. Left undefined with it: which key the
second request is verified against; whether a captured *content transfer* can be
replayed inside the 10-minute nonce window to re-deliver the message (F6 closes
preflight replay only); how long an `accept` stays redeemable; and where H3's
transfer-time manifest enforcement actually lives.

**Resolution:** An `accept` opens a **single-use delivery session**, keyed by the
envelope nonce, recording the admitted manifest, the verified sender identity
(domain + key id), and the answered key generation. The transfer redeems it:
parts are verified against the session's sender key (transfer signature bound to
the nonce) and enforced against the admitted manifest (count, declared sizes,
roles). Completion consumes it, and so does a terminal failure (hash mismatch) —
a second transfer for the same nonce is a request-level refusal, closing
content-transfer replay symmetrically with F6. Unredeemed sessions expire after a
declared TTL (default **15 minutes**), discarding partial parts; the sender's
retry is a fresh preflight with a new nonce. The session holds only envelope and
manifest data — nothing sealed, nothing per-user — so it works while a vault is
locked. Placement at Fortress: the session lives at the relay (where the wire
terminates); the origin box deliberately needs no session, because it
independently re-verifies both signatures and every sealed-byte hash on the
forwarded delivery — the session disciplines the wire, the signatures protect
against the relay. Folded into `joinery_direct_mail.md` §Vocabulary (new
*Delivery session* entry), §The send decision (step 4), §The receive decision
(new session paragraph), §Message transfer, Build plan item 3, Acceptance
#41–#42, a new *Attacks considered* bullet (content-transfer replay), and
`docs/joinery_direct.md` (channel overview step 2, receiving step 6).

---

## H3 — Size limits were load-bearing but unowned — MED, RESOLVED

**What the spec claimed.** "Refusal before transfer" for oversized messages is a
stated benefit of the manifest — but `gate()` answers only sender-acceptance, at
the sealed tiers it is not called at receive at all, and the signed sealed-byte
hashes are the *sender's own* signature, so nothing constrained a hostile sender's
sizes. No layer was assigned to enforce a limit, and any enforcer must be able to
run while the vault is locked.

**Resolution:** The framework owns **manifest bounds** as a new receive step 3,
before any gate, identical at every tier: maximum parts per message, maximum bytes
per part, maximum total bytes — declared settings, defaults 64 / 100 MB / 250 MB.
Exceeding any is a request-level refusal in the invalid-signature bucket; safe to
signal because the caps are instance configuration applied identically to every
recipient and kind, so the answer discloses nothing per-user. The admitted
manifest is also the transfer-time contract: a delivered part exceeding its
declared size aborts the delivery (H2's session will formalize where that state
lives). Folded into `joinery_direct_mail.md` §The receive decision (new step 3,
steps renumbered 4–5, tier-scoping sentence updated), §Serving a kind (framework
table row), §Abuse (new caps bullet), §Message transfer (refusal-before-transfer
bullet), Build plan item 3, Acceptance #39, and `docs/joinery_direct.md`
(§Receiving step list, §Blocking and abuse).

---

## H4 — The storage bound didn't survive Sybil; spool caps + reuse of the existing rate limiter — MED, RESOLVED

**What the spec claimed.** §Why there is no spam problem bounded locked-tier
storage by the F5 per-instance preflight limiter. But instance identity is a
domain with a TXT record, and domains are cheap — N domains buy N× the limit, as
the spec's own Sybil bullet observes for a different property. No byte-denominated
bound existed anywhere. Separately (owner directive), rate limiting must reuse the
platform's existing feature, not reinvent it — and F5's "token bucket" would have
been a new engine: the tree's two real idioms are
`RequestLogger::check_rate_limit` (IP-keyed sliding window over
`rql_request_logs`, used by API auth, device-link, password reset, register) and
per-entity window counts over a feature's own log table (the mailbox forwarding
limiters, `checkAliasRateLimit`/`checkDomainRateLimit`).

**Resolution — caps:** the Direct spool is bounded in bytes by two declared
settings: a **per-domain cap** (default 10 GB, request-level refusal once full —
instance-level state, same answer for every address, no oracle) and a
**per-address cap** beneath it (default 1 GB) so one flooded recipient cannot
consume the domain's budget. Two subtleties are the substance: (1) a naive
per-address "full → refuse" is an existence probe, because decoy addresses discard
and would never fill — closed by **phantom-byte accounting**: decoy deliveries are
discarded but their declared bytes counted, so a flooded address refuses
identically whether real or not. (2) refusal was chosen over a silent local drop
deliberately — a silent drop could lose a legitimate contact's sealed mail, while
a request-level refusal downgrades mail to SMTP and loses nothing. Counters drain
as the spool drains at unlock (retention expiry for addresses holding nothing).
At Fortress enforcement runs at the relay (where the wire terminates), with cap
values carried as relay-map data.

**Resolution — limiter reuse:** the per-peer pre-verification check is
`RequestLogger::check_rate_limit` under a Direct feature key, as-is; the
per-instance check is window counts over Direct's own request log (the mailbox
forwarding pattern), since `RequestLogger` hardcodes IP keying and Direct keys on
the verified sender domain. F5's token-bucket default is re-expressed in window
form — **120 preflights per instance per rolling 2 minutes** (same average and
burst) — and F5's resolution carries an amendment note. No new rate-limiting
engine. Folded into `joinery_direct_mail.md` §Security levels (new spool-cap
paragraph), §Abuse (rewritten limiter bullet + caps bullet), §Why there is no spam
problem (bounded-twice wording), §The receive decision (per-peer bullet names
`RequestLogger`), Build plan items 3–4, Acceptance #40, a new *Attacks considered*
bullet (storage exhaustion), and `docs/joinery_direct.md` (§Blocking and abuse).

---

## H5 — The developer surface was underdefined — LOW, RESOLVED

**What was missing.** The abstraction boundary (one send call; two pure handler
functions) was right, but five things a developer hits on day one had no
definition: the shape of `$parts` — and whether a 40 MB attachment must sit in
memory as a string; what objects `gate`/`ingest` actually receive; the promised
"one line" canned-gate opt-in, with no syntax anywhere; no way to test a kind
without standing up a second instance; and zero operator visibility when Direct
silently degrades (a clock-drifted box fails every freshness check and loses
Direct wholesale, with no symptom, because every failure legitimately falls back
to SMTP).

**Resolution, five parts:**
(a) **Part descriptors** — `role` / `content_type` / `filename`, content as
`bytes` or a `path`/stream. On the record: `crypto_box_seal` is one-shot, so peak
memory scales with the largest single part — exactly the ceiling the per-part
size cap enforces. `$options` stays minimal (timeout override); kind-specific
data travels as parts, never as new envelope fields.
(b) **Typed envelope and part objects** with named accessors for `gate`/`ingest`;
a handler never parses wire bytes, so the wire format can move under a protocol
version without touching handlers.
(c) **Canned-gate syntax** — `directKinds` takes an object form
`{"handler": …, "gate": "contacts"}` (string shorthand = handler supplies its own
gate); with the canned gate the framework runs the gate itself and the handler
implements only `ingest`.
(d) **Loopback send** for the test estate: a send to an address served by the
same instance runs the full receive framework locally, no DNS or network — a
test-tier tool, not a delivery path.
(e) **Operator surface** — request-level refusal and SMTP-downgrade counters from
Direct's request log on the admin dashboard: silence is a wire posture toward
senders, never toward the operator.
Folded into `joinery_direct_mail.md` (§The send decision caller paragraph,
§Serving a kind ×4, §Abuse operator bullet, Build plan items 2/3/5, Acceptance
#43–#44) and `docs/joinery_direct.md`. Housekeeping rode along:
`safe_http_client.md` D2 and D3 flipped from "leaning" to RESOLVED as decided
(per-caller construction; TLS-skip stays bespoke).

---

## Clarification recorded after the H pass — the gate elevates, it never places

Owner question: does accept-everyone at Fortress bypass the spam apparatus for
non-contacts? **No, and the spec now says so explicitly.** The contact gate's
only outputs are elevation (verified mark + inbox) or no-elevation; a
non-contact's message is handed to the same full inbound pipeline deferred SMTP
mail runs at unlock — `DeferredIngest` already runs parse, attachment split, and
filter rules there, and the spam verdict uses `MailboxSpamPolicy`'s existing
no-upstream-verdict path (`scanContentSpam` on the box), which for sender-sealed
content necessarily runs at unlock, the first readable moment. Direct changes
the transport, not the classification; "worst case is SMTP" holds as an
end-state identity, with one honest delta: time-sensitive spam signals evaluate
at unlock rather than at receive, inherent to content no path machine can read.
Folded into `joinery_direct_mail.md` §Security levels (new paragraph after the
deferred-gate description) and `docs/joinery_direct.md` §Security tiers.
