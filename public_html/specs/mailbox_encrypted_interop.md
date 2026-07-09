# Mailbox Encrypted Interop — OpenPGP Federation and Transport Hardening

## Goal

Let a hosted mailbox exchange end-to-end encrypted mail with the outside world using open standards, and make the domain's transport posture independently verifiable by third parties. Today the mailbox protects mail *at rest* (Sealed Vault) and *in identity* (DKIM/SPF/DMARC, protected-identity signing), but every message crosses the wire as ordinary SMTP content readable by the receiving provider. This spec adds the missing layer: automatic encryption to any correspondent whose public key is discoverable, publication of our own users' keys so the world can encrypt to them, and the DNS-verifiable transport standards (MTA-STS, TLS-RPT, DANE/DNSSEC) that the privacy community uses as a litmus test.

Strategic framing: we do not invent a federation protocol. OpenPGP + its modern discovery mechanisms (WKD, Autocrypt) already form a live federation that includes Proton Mail, Thunderbird, GnuPG, Delta Chat, and mailbox.org. By publishing keys and auto-encrypting on discovery, Joinery-to-Joinery mail becomes E2E automatically — and so does Joinery-to-Proton and Joinery-to-anyone-with-a-key, with zero coordination. We inherit an existing network instead of bootstrapping one.

**Relationship to the existing security model.** `specs/mailbox_security_model_public.md` states PGP is a non-goal. That remains true *for the at-rest security story* — Private/Fortress sealing does not depend on correspondents adopting anything. This spec adds OpenPGP as an *interop* layer on top: opportunistic, automatic, and invisible when the correspondent has no key. The public security-model doc must be updated to draw this line explicitly (see Documentation to Update).

## Protocol Inventory

Complete inventory of the open standards in this space, each with what it does in plain terms, what it costs us, what it buys, and a verdict. Verdicts: **Adopt** (build in this spec), **Adopt-later** (design for it, build in a follow-up), **Display-only** (show instructions/records, don't build machinery), **Skip** (documented reason), **Watch** (not actionable yet).

### Content layer — end-to-end encryption

| # | Protocol | What it is | Verdict |
|---|----------|------------|---------|
| C1 | OpenPGP message format (RFC 4880 / RFC 9580) | The encrypted-message payload format itself | **Adopt** |
| C2 | PGP/MIME (RFC 3156) | Standard way to wrap an email (body + attachments) as one OpenPGP object | **Adopt** |
| C3 | WKD — Web Key Directory | Key discovery: fetch a recipient's key over HTTPS from their mail domain | **Adopt** |
| C4 | Autocrypt (Level 1) | Key discovery: every outbound mail carries the sender's key in a header | **Adopt** |
| C5 | keys.openpgp.org / VKS API | Central verified keyserver; also offers hosted WKD via CNAME | **Adopt** (lookup) / **Adopt-later** (publish) |
| C6 | DANE OPENPGPKEY (RFC 7929) | Key discovery via DNS record, secured by DNSSEC | **Display-only** |
| C7 | Protected headers (encrypted Subject) | Puts the real Subject inside the encrypted body; visible one says "..." | **Adopt** |
| C8 | WKS — Web Key Service | Companion protocol letting external mail clients submit keys to a domain | **Skip** |
| C9 | S/MIME + SMIMEA (RFC 8162) | The certificate-based rival to OpenPGP | **Skip** |
| C10 | HKP keyservers (SKS-style pools) | Legacy unverified keyserver network | **Skip** |
| C11 | Key transparency (IETF KEYTRANS, Proton KT) | Audit log proving a keyserver never lied about someone's key | **Watch** |

**C1 — OpenPGP format.** Generate keys as v4 with Ed25519 (sign) + Curve25519 (encrypt) subkey. Not v6/RFC 9580 keys yet: GnuPG 2.4 diverged from the crypto-refresh (LibrePGP split) and v6 keys break interop with the very clients we're targeting. v4+Ed25519 is what Proton, Thunderbird, and Delta Chat all speak today. Cost center: PHP has no maintained OpenPGP library and the `gnupg` PECL extension is not installed — the realistic engine is shelling out to the system GnuPG 2.4.4 (`/usr/bin/gpg`) with an ephemeral `GNUPGHOME` per operation (`--batch --no-tty`, key material passed via stdin/fd, `sodium_memzero` on buffers after). This is the single largest implementation-risk item in the spec; see Open Items.

**C2 — PGP/MIME.** Encrypt the *entire* composed MIME body (text + HTML + attachments) as one `multipart/encrypted` object. Never inline-PGP (breaks HTML mail, leaks attachment names). Consequence for inbound: an encrypted inbound message arrives as one opaque part — the router cannot extract attachments or index text at ingest time. That is the honest behavior: it's E2E, we can't read it (see Inbound Handling).

**C3 — WKD.** Two roles. *Lookup (cheap, pure client):* on send, fetch `https://openpgpkey.<recipientdomain>/.well-known/openpgpkey/<domain>/hu/<hash>` (advanced method), falling back to `https://<recipientdomain>/.well-known/openpgpkey/hu/<hash>` (direct method). Just HTTPS GETs with a zbase32/SHA-1 hash of the lowercased local part. *Publish (the goodwill move):* serve our own users' keys the same way. Publishing has a hosting wrinkle — WKD lives on the *customer's mail domain*, whose website often doesn't point at this box. Two paths, per domain:
  - **Direct hosting:** if the domain's web traffic already resolves to the platform, serve `/.well-known/openpgpkey/` from a route (plus the required empty `policy` file). For the advanced method, the customer adds a CNAME `openpgpkey.<domain>` → the box, and the box needs a TLS cert covering that name (cert automation is an Open Item).
  - **Delegated hosting:** customer CNAMEs `openpgpkey.<domain>` → `wkd.keys.openpgp.org` and we publish the key there (C5). Zero hosting on our side; requires the keys.openpgp.org verification round-trip — which lands in a mailbox *we host*, so verification can eventually be automated (Adopt-later).
  The domain DNS tab gains a WKD row in the existing detect/instruct/verify pattern (`InboundEmailSetupCheck`), same as MX/SPF/DKIM today.

**C4 — Autocrypt.** Add an `Autocrypt: addr=<from>; prefer-encrypt=mutual; keydata=<minimal key>` header to every outbound mail from an E2E-enabled alias. Parse the header on every inbound mail and record the peer's key. This is the zero-infrastructure discovery channel — no DNS, no keyserver, works for correspondents on Delta Chat and K-9/Thunderbird immediately, and it seeds our peer-key cache from normal correspondence. Also emit Autocrypt-Gossip headers inside encrypted multi-recipient mail per the Level 1 spec. Very low cost (header assembly + parser + peer-state table), disproportionate interop payoff.

**C5 — keys.openpgp.org.** *Lookup:* `GET https://keys.openpgp.org/vks/v1/by-email/<addr>` as a discovery fallback after WKD and the Autocrypt cache. Trivial. *Publish:* upload our users' keys via the VKS API; identities only become searchable after an email verification round-trip. Since the verification mail arrives at an alias we host, this can be automated end-to-end later — deferred so the first release doesn't depend on a third-party flow.

**C6 — DANE OPENPGPKEY.** The architecturally pure "key in DNS" answer, and in practice a ghost town: requires DNSSEC on the customer zone, almost no client queries it, and record generation is awkward (binary key in DNS). We *display* the record on the DNS tab for customers whose zone is DNSSEC-signed (badge appeal for the hardcore crowd), and optionally *query* it as a last-resort lookup, but build no publishing machinery.

**C7 — Protected headers.** When encrypting, place the real Subject inside the protected part and set the outer Subject to `...` (draft-ietf-lamps-header-protection / "Memory Hole" convention, implemented by Thunderbird and Proton). Without this, E2E mail still leaks its subject to every relay — privacy users notice. Low cost once C1/C2 exist. Applies the same way to the encrypted-to-self stored copy.

**C8 — WKS.** Solves key submission for domains whose users run external MUAs and generate their own keys. Our keys are platform-generated and platform-published; there is nothing to submit. Skip.

**C9 — S/MIME + SMIMEA.** A parallel universe: per-user certificates from CAs, enterprise directory assumptions, near-zero presence in the privacy community we're courting, and adopting it doubles every crypto surface (two formats, two stores, two discovery paths). Skip entirely; revisit only if a business market demands it.

**C10 — Legacy HKP pools.** Unverified, spam-poisoned (key-flooding attacks), effectively deprecated in favor of keys.openpgp.org. Skip.

**C11 — Key transparency.** The eventual answer to "how do I know the keyserver didn't swap keys" — IETF WG in progress, Proton runs a proprietary implementation. Nothing interoperable to adopt yet. Watch; our positioning meanwhile is TOFU + multi-source cross-checking (WKD vs Autocrypt vs VKS disagreement is surfaced, see Sending Rules).

### Transport layer — verifiable server-to-server hardening

| # | Protocol | What it is | Verdict |
|---|----------|------------|---------|
| T1 | MTA-STS (RFC 8461) | Domain publishes an HTTPS policy: "only deliver to me over verified TLS" | **Adopt** |
| T2 | TLS-RPT (RFC 8460) | Daily JSON reports from big senders about TLS failures reaching you | **Adopt** (record + ingest) |
| T3 | DNSSEC | Signed DNS — prerequisite for all DANE records | **Display-only** (detect + instruct) |
| T4 | DANE for SMTP (RFC 7672) | TLSA record pins the MX host's TLS key, secured by DNSSEC | **Adopt** (publish side) |
| T5 | Outbound DANE/MTA-STS enforcement | *Verifying* remote domains' policies when we send | **Adopt-later** (deployment-dependent) |
| T6 | REQUIRETLS (RFC 8689) | Per-message flag demanding TLS the whole way | **Skip** |
| T7 | ARC (RFC 8617) | Preserves auth results across forwarding hops | **Skip** (for this spec) |

**T1 — MTA-STS.** Per customer domain: a TXT record `_mta-sts.<domain>` and a policy file at `https://mta-sts.<domain>/.well-known/mta-sts.txt` listing the MX and a mode (`testing` → `enforce`). The platform generates and serves the policy (it knows the MX — it *is* the MX); the customer adds the TXT record plus an A/CNAME for the `mta-sts` subdomain pointing at the box. Same TLS-cert-for-subdomain need as WKD direct hosting — solve once for both. DNS tab gains MTA-STS rows with mode selection on the domain edit page (testing first, enforce after the check passes for a period).

**T2 — TLS-RPT.** TXT record `_smtp._tls.<domain>` with `rua=mailto:tls-reports@<domain>` — and since we host the mailbox, we can *ingest* the reports instead of letting them rot: a store-mode system alias per enabled domain, a parser for the gzipped JSON attachments (Google/Microsoft send these daily), and a small admin surface showing delivery/TLS-failure trends per domain. This is an unusually good fit for us — most tooling in this space is third-party SaaS; we get it native.

**T3 — DNSSEC.** We can't sign customer zones (registrar/DNS-host function), but we detect and instruct: extend `DnsResolver` with a DNSSEC-validity check (DoH query to a validating resolver, e.g. `cloudflare-dns.com/dns-query`, reading the AD flag) and show signed/unsigned status on the DNS tab, with registrar-specific guidance. DNSSEC on the customer zone unlocks their DANE rows (T4 display, C6).

**T4 — DANE for SMTP (publish side).** The TLSA record lives at `_25._tcp.<mx-hostname>` — in the zone of the *box's own hostname*, which the platform operator controls once, not per-customer. Publish `3 1 1` (SPKI pin), keep Let's Encrypt renewals TLSA-stable via key reuse, and add a setup-check row verifying the live cert matches the published TLSA. This single record upgrades *every* hosted domain's inbound story at once and is precisely what internet.nl and the self-hosting community test. Requires DNSSEC on the operator's server zone — an operator-level prerequisite, documented in provisioning, not a per-customer ask.

**T5 — Outbound enforcement.** Verifying *remote* MTA-STS/DANE when we send is the sender's half of the bargain. Whether we can honor it depends on the outbound transport: provider-relay sends (Mailgun/SES) delegate this to the provider; direct-SMTP deployments where local Postfix does remote delivery get it via Postfix config (`smtp_tls_security_level = dane` + a local validating resolver). Design decision: record the posture per outbound transport and surface it honestly in the admin, enable Postfix DANE in provisioning docs, but build no PHP-side policy engine. Adopt-later if we ever do direct-to-MX delivery from PHP.

**T6 — REQUIRETLS.** Sender demands TLS end-to-end per message. Near-zero receiver support; a message flag that mostly causes bounces. Skip.

**T7 — ARC.** Matters for our *forwarding* path reputation, not for encryption; it's a separate workstream touching the relay/SRS machinery. Out of scope here; noted so the inventory is complete.

## Key Model

**One OpenPGP keypair per hosted alias** (the alias *is* the identity — one email address, one key), generated server-side at enablement:

- **Eligibility:** store-mode or forward_and_store alias with a single owner grant whose owner has a Sealed Vault (any server-custody scope). Same precondition as content sealing. Group/shared mailboxes are not E2E-eligible (no single custody root) — same rule as Fortress today.
- **Custody:** exactly the protected-identity DKIM pattern (`ied_dkim_sealed_key` precedent): the OpenPGP secret key is generated in-session, sealed to the owner's vault public key via `SealedBox`, and exists in plaintext only inside an unlock window. Public key + fingerprint stored cleartext for publication.
- **Algorithm:** v4, Ed25519 primary (certify+sign), Cv25519 encryption subkey, no expiry initially (rotation covered below). UID `<display name> <alias@domain>`.
- **Rotation / revocation:** key generation counter per alias (mirrors `ied_dkim_key_generation`); a rotation generates a new pair, republishes WKD/Autocrypt, and keeps prior generations' sealed secret keys for decrypting old mail. A revocation certificate is generated at key creation and stored sealed alongside (needed if we later publish to keys.openpgp.org).
- **Vault lifecycle integration:** the sealed secret keys join the existing `onReseal` / `onWipe` consumer hooks — vault key rotation re-seals them; vault wipe destroys them (old E2E inbound becomes permanently unreadable — consistent with vault semantics, must be stated in the wipe warning copy).

## Sending Rules

All in the single existing funnel `MailboxSender::send()`, immediately before the DKIM/`EmailSender` handoff (the same seam the in-app DKIM signer already uses):

1. **Discovery, per recipient:** Autocrypt peer cache → WKD (advanced, then direct) → keys.openpgp.org VKS → (optional, DNSSEC domains only) OPENPGPKEY. First verified hit wins; disagreements between sources on the same address are logged and surfaced as a compose-time notice, not silently resolved.
2. **Policy:** *opportunistic by default* — encrypt iff **every** recipient (To/Cc) resolved a key; otherwise send plaintext exactly as today. Per-message override in compose: "Require encryption" (fail the send listing unresolved recipients) and "Don't encrypt this message". Per-alias default setting later if usage warrants.
3. **Sign-when-encrypting:** encrypted mail is also signed with the alias key — which requires an open unlock window. Practical consequence: E2E sends are session-gated like Fortress sends. Unsigned-but-encrypted is not offered (poor interop reputation, confusing UX).
4. **Encrypt-to-self:** the stored outbound copy (and the wire message's Bcc-to-self behavior) is encrypted to the alias's own key too, so Sent mail remains readable in-window.
5. **Autocrypt header** on every outbound from an E2E-enabled alias, encrypted or not.
6. **Protected headers** (C7) applied whenever encrypting.

## Inbound Handling

- **Detection at ingest:** `multipart/encrypted; protocol="application/pgp-encrypted"` (and legacy inline-PGP heuristic) → mark the message row `iem_is_openpgp`, store the ciphertext part as delivered, skip body extraction/attachment explosion/FTS for that message. On sealed domains the ciphertext is additionally vault-sealed like any content — harmless double wrap, no special case.
- **Decryption on view, in-window:** the reader decrypts via the alias secret key (unwrapped from the vault) on demand, renders body + extracts attachments transiently. No plaintext is written back to the row by default — an E2E message stays E2E at rest, strictly stronger than Private sealing (not even in-window server jobs can index it). "Decrypt permanently into my sealed mailbox" can be a later per-message action; not in scope.
- **Signature verification:** verify signatures against the sender's known key (Autocrypt peer state / prior discovery); show signed/unknown-key/invalid states in the reader. Store verdict alongside the existing SPF/DKIM/DMARC columns.
- **Autocrypt parsing:** every inbound mail (encrypted or not) updates the peer-state table per the Level 1 state machine (`prefer-encrypt`, key updates, gossip keys from decrypted mail).
- **Limitations to state honestly in UI/docs:** E2E inbound is excluded from FTS search, automation/rules that need body content, and forwarding-with-content transforms. Subject shows the protected header's value once decrypted, placeholder in list view otherwise (list-view subject caching in-window is an Open Item).

## Where the Cost/Benefit Line Sits (recommendation)

- **Core build (this spec):** C1, C2, C3 (lookup + direct-host publish + delegated-CNAME instructions), C4, C5-lookup, C7 · T1, T2, T3-detect, T4. This is the coherent product: auto-E2E with the existing OpenPGP federation plus the full internet.nl-testable transport badge set.
- **Fast follows:** C5-publish with automated verification; T5 Postfix outbound DANE in provisioning; DANE OPENPGPKEY display rows (C6).
- **Not doing:** C8, C9, C10, T6, T7 (reasons inline above). **Watching:** C11.

The single most defensible descope if the core is too big: ship the transport tier (T1–T4) alone first — zero cryptography, pure DNS/HTTPS/parsing, immediately verifiable goodwill — then the content tier. The content tier's cost is dominated by one item: the GnuPG execution engine (see Open Items #1).

## Schema Changes (via data-class $field_specifications)

New plugin data classes (names indicative):

- `iok_inbound_openpgp_keys` — alias keypair store: `iok_iea_inbound_email_alias_id`, `iok_fingerprint`, `iok_public_key` (armored), `iok_sealed_secret_key`, `iok_sealed_revocation_cert`, `iok_key_generation`, `iok_owner_usr_user_id`, `iok_status` (active|retired|revoked), timestamps.
- `iop_inbound_openpgp_peers` — Autocrypt peer state + discovery cache: `iop_iea_inbound_email_alias_id` (the local account scope), `iop_peer_address`, `iop_keydata`, `iop_fingerprint`, `iop_source` (autocrypt|wkd|vks|dane|gossip), `iop_prefer_encrypt`, `iop_last_seen_time`, `iop_autocrypt_timestamp`, state fields per Autocrypt Level 1.
- `itr_inbound_tlsrpt_reports` — parsed TLS-RPT reports: `itr_ied_inbound_email_domain_id`, `itr_reporter`, `itr_date_range`, `itr_policy_type`, `itr_success_count`, `itr_failure_count`, `itr_raw_json` (or File ref), received time.

Column additions:

- `iem_inbound_email_messages`: `iem_is_openpgp` (bool), `iem_openpgp_sig_result` (varchar, nullable).
- `ied_inbound_email_domains`: `ied_e2e_enabled` (bool), `ied_wkd_mode` (off|direct|delegated), `ied_mta_sts_mode` (off|testing|enforce), `ied_mta_sts_policy_id` (varchar), `ied_tlsrpt_enabled` (bool), `ied_dnssec_signed` (bool, cached check result + checked time).

Settings (plugin.json): engine paths/flags (`mailbox_gpg_binary`), discovery timeouts, keys.openpgp.org lookup toggle, TLS-RPT ingest alias local part.

## Documentation to Update

- `plugins/mailbox/docs/overview.md` — new major sections: OpenPGP interop (key model, discovery, sending rules, inbound handling), transport hardening (MTA-STS/TLS-RPT/DANE), DNS tab additions.
- `docs/sealed_vault.md` — add the OpenPGP key store to the consumer list (reseal/wipe semantics).
- `specs/mailbox_security_model_public.md` *(spec, but public-facing narrative)* — reconcile the "PGP is a non-goal" passage: non-goal as the at-rest foundation, adopted as the interop layer.
- `docs/routing.md` — only if `/.well-known/openpgpkey` and `mta-sts.<domain>` hosting need a routing-layer mechanism worth documenting.

## Open Items to Confirm During Implementation

1. **OpenPGP engine.** Shell-out to GnuPG 2.4.4 with ephemeral `GNUPGHOME` is the working assumption; validate secret-key handling (stdin/fd-passing, no temp-file plaintext, `gpg-agent` suppression, concurrency) under php-fpm before committing. Fallback options if it proves too fragile: install the `gnupg` PECL extension (still uses GnuPG underneath, cleaner lifecycle), or a vendored pure-PHP implementation (none currently maintained to a standard we'd trust — reassess at build time).
2. **TLS certs for customer subdomains** (`openpgpkey.<domain>`, `mta-sts.<domain>` pointing at the box): per-domain ACME issuance on the box (http-01 works since the name resolves here) vs. instructing delegated/CNAME modes only. Decide once; it gates WKD direct hosting and MTA-STS policy serving.
3. **Multi-generation decryption UX** — after key rotation, decrypting old mail needs the retired generation's key; confirm the unlock window unwraps all generations or lazily per message.
4. **List-view subject for E2E mail** — placeholder-only vs. in-window decrypt-and-cache (RAM/`/dev/shm`, MailboxIndex-style) for usable threading; decide against real usage.
5. **Encrypted inbound on Standard domains** — E2E-enabled alias on a Standard (plaintext-at-rest) domain stores OpenPGP ciphertext it can only read in-window; confirm this asymmetry is acceptable UX or gate E2E enablement to Private/Fortress domains.
6. **DoH dependency for DNSSEC checks** — confirm using an external validating resolver (Cloudflare/Google DoH, AD flag) is acceptable vs. requiring a local validating resolver (unbound) in provisioning; affects T3 detect and any C6/T5 validation.
7. **Delivery-mode edge:** forward-only aliases can't be E2E (nothing stored, key custody pointless) — confirm eligibility gating covers forward_and_store sanely (forwarded leg carries ciphertext the destination may not decrypt).
8. **internet.nl target** — decide whether "100% on internet.nl mail test" is a formal acceptance criterion for the transport tier (it tests IPv6, DNSSEC, DKIM/SPF/DMARC, STARTTLS+DANE; some items are operator-level, not per-customer).
