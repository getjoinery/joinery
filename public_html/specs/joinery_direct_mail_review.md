# Joinery Direct Mail — Security & Design Review

**Status:** OPEN — review findings against `specs/joinery_direct_mail.md`. Companion
document, not a replacement. Each finding is a self-contained unit with a stable ID
so we can walk them one at a time and record a resolution per finding without
relitigating the others.

**How to read this:** every finding follows the same shape — *what the spec claims*,
*what the code actually shows* (with file:line evidence), *why it matters*, and *the
decision to make*. Nothing here is resolved yet. The intent is to settle each one and
fold the outcome back into `joinery_direct_mail.md` (or reject the finding on record).

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

**What the spec claims.** The send decision looks up `_joinery-mail._tcp.<recipient-domain>`
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

**Resolution:** _pending._

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

**Resolution:** _pending._

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

**Resolution:** _pending._

---

## F4 — Contact sealing is per-vault, not per-tier; the Standard live-gate premise is false — HIGH, BLOCKING

**What the spec claims.** "At Standard the gate runs live, so a non-contact gets
`use-smtp` on the wire" and "(The live two-answer gate … applies at Standard, where
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
- Fall back to `use-smtp` when locked — which **reintroduces the exact lock-state
  oracle** the Fortress design worked to close (locked → `use-smtp`, unlocked+contact →
  `accept`), now observable at Standard/Private.

As written, the spec's tier model and the code contradict each other, and the resolution
touches the oracle guarantees.

**Decision to make.** Reconcile the model. Options: (a) run the deferred-ingest path
whenever the contact list is unreadable regardless of tier (accept-then-decide-locally
everywhere the vault is locked), (b) redefine the tiers so the live gate is only claimed
when contacts are genuinely readable, or (c) something else. Whichever, the lock-state
oracle must not reappear at Standard/Private.

**Resolution:** _pending._

---

## F5 — Block list and ingest rate-limiter are claimed as existing but do not exist — MED, SHOULD SCOPE

**What the spec claims.** "Mark as spam / block = remove the contact *and* add the sender
to the block list," blocked senders get an indistinguishable `use-smtp`, and "the
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

**Resolution:** _pending._

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

**Resolution:** _pending._

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

**Resolution:** _pending._

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

**Resolution:** _pending._

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

**Resolution:** _pending._

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

**Resolution:** _pending._

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
