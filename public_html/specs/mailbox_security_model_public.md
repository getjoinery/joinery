# The Security Model of Joinery's Self-Hosted Mailbox

*Written for a technical audience. This document states what the system
protects, what it deliberately does not, and where every residual exposure
lives. If you find a gap between a claim here and the code, that is a bug —
report it. (Placement note: this lives in specs/ pre-launch; it graduates to
public documentation, updated against the shipped implementation, at launch.)*

## The premise, stated first

**Email cannot be end-to-end encrypted by the recipient alone.** SMTP delivers
plaintext (TLS protects the hop, not the message), and your correspondents will
not adopt PGP. Any mail system you host has a moment where the message exists
in cleartext on hardware you operate. Every honest design question is
downstream of that: *how long does plaintext exist, on which machine, and what
can an attacker who owns that machine at a given moment actually do?*

This system's answer is a per-domain choice of three postures. The strongest
posture is designed against the assumption that **your server will eventually
be compromised** — not that it won't be. The interesting property is not
"encrypted," it is *bounded*: what a given attacker position yields, and for
how long, is enumerated below. Where the bound is weak, this document says so.

## Three levels, because there are three questions

- **Standard** — the server manages this mailbox for you. Today's normal
  self-hosted behavior: plaintext at rest, automation works, zero ceremony.
  For the dance-club signup address.
- **Private** — *can a compromised server read my stored history?* No.
  Everything content-shaped — bodies, subjects, senders, attachments, the
  search index — is encrypted at rest to a key the server does not hold.
- **Fortress** — *can a compromised server read my new mail, or send mail as
  me, live?* No. Mail is encrypted at a separate minimal relay before the main
  server ever holds it, and the ability to produce a DMARC-passing message
  from the domain requires an unlock only the user can perform.

The level attaches to the **domain** (MX, SPF, DKIM, DMARC are domain-level
facts), so one deployment can run a throwaway domain at Standard and a
primary identity at Fortress.

## The key hierarchy (what the server never holds)

Mail is sealed with libsodium: each message gets a random data key; content is
encrypted under it (XChaCha20-Poly1305, with additional data binding each
ciphertext to its exact row and field); the data key is sealed to the user's
X25519 public key. Ingest needs only the public key. Reading needs the secret
key — and the secret key exists at rest **only in wrapped form**, once per
enrolled "unlocker":

- **A passkey** (the everyday path): the WebAuthn PRF extension derives a
  32-byte wrapping key inside the authenticator hardware on a touch/face
  check. Nothing to memorize; the ingredient never rests on the server.
- **Recovery codes** (mandatory backup): printed one-time codes, each ≥128
  bits of entropy, each independently wrapping the secret key. A code *is* key
  material — this is why codes work where TOTP-style 2FA structurally cannot
  (a server-verified check is bypassable by whoever owns the server; withheld
  key material is not).
- **An optional passphrase** (Argon2id at the MODERATE cost profile — each
  offline guess against a stolen database costs the attacker ~256 MB and real
  CPU). Stated plainly: this is the one unlocker whose strength the user
  chooses, and the system is exactly as strong as its weakest enrolled
  wrapping. Every enrollment path enforces a 12-character minimum, and the
  enrollment UI says why.

Lose every unlocker and the mail is **permanently unreadable**. There is no
admin override, no support recovery, no exception for the server operator —
structurally, because there is no key to override with. The setup ceremony
forces acknowledgment of this before it completes.

## The unlock window

"Logged in" and "able to read mail" are different states. A web session may
last days; keys do not. Reading, searching, and (on Fortress) sending require
an **unlock** — one passkey tap — which holds the secret key in server RAM for
a bounded idle window (default 30 minutes, activity-extended). Expiry, logout,
or session end wipes the key and every decrypted artifact. Re-unlocking is a
fingerprint tap, which is what makes a short window livable.

Yes: **during an unlock window, the server decrypts on the user's behalf.**
This is server-side crypto, chosen deliberately (next section), and it is the
system's most important honest limit: an attacker resident on the box *while
you are actively reading mail* reads it with you. What the design removes is
everything outside that window — which for a personal mailbox is almost all
of the time — and a key-rotation ceremony exists so that a discovered breach
does not convert into permanent future access.

## The other failure mode: losing the key

A system with no admin override must take key *loss* as seriously as key
theft — "we can't read your mail" and "nobody can ever read your mail again"
are one bad ceremony apart. Encryption bugs that destroy data pass every
happy-path test; they live in state ordering across crash boundaries, not in
the math. So the loss side is engineered explicitly:

- **You cannot strip your last unlocker.** Every wrapping delete — revoking a
  passkey, removing the passphrase — passes a floor check: at least one live
  vault passkey or three unused recovery codes must survive the operation, or
  it is refused and the refusal names what to enroll first. A revoked
  passkey's wrapping is retired with it (its hardware-derived key can never
  exist again), so a dead unlocker can't satisfy the floor by miscount.

- **Using a recovery code is treated as a possible attack.** A consumed code
  first ends every open unlock window on every session everywhere, then opens
  one only for the session that presented it, then emails the account. If the
  code was stolen rather than recovered, the thief's use of it evicts any
  windows they already held — and the owner learns immediately, holding a
  re-locked vault.

- **Key rotation is ordered so a crash cannot strand content.** The new key
  becomes durably recoverable — wrapped under every re-derivable unlocker, in
  the same database transaction that publishes the new public key — before
  anything can seal to it. Every consumer then re-seals its content off the
  old key and must positively confirm; one failed item aborts the ceremony
  with every old unlocker still working, and re-running it converges. Only
  after every consumer confirms does the old key retire. Envelope encryption
  makes the ceremony cheap enough to actually use post-breach: rotating a
  10,000-message archive re-wraps 10,000 tiny per-message key envelopes, never
  the messages themselves.

- **Setup is atomic and acknowledged.** The ceremony cannot leave a vault with
  zero unlockers (one transaction covers the keypair and every wrapping), and
  it will not complete until the user acknowledges that losing every unlocker
  is permanent. It also hands over a key file — the wrapped keys, public key,
  and salt — useless to a thief without a live unlocker, sufficient to
  reconstruct the wrappings if database rows are ever lost independently of a
  backup.

## Why server-side decryption (and not a crypto SPA)

ProtonMail decrypts in the browser; a compromised Proton server cannot read
stored mail, but a compromised server *can* ship you backdoored JavaScript
that exfiltrates keys at decrypt time. Browser-delivered crypto narrows the
window; it cannot close it. We chose server-side decryption inside bounded
unlock windows because it keeps the reader a plain server-rendered app with a
small auditable surface, and because the client-side model forces the search
architecture and HTML sanitization into a WASM SPA — a large, divergent build.
The client-side fork is recorded in the design as future work, not rejected on
principle: with edge-sealing already in place (below), moving decryption to
the browser would remove the server's plaintext window entirely. We are not
there yet, and this document will not pretend otherwise.

## Sending identity: enforcement that survives root

The nastier compromise is not reading your mail — it is **sending as you**.
The usual controls (rate limits, audit logs) live on the box and die with
root. Fortress moves enforcement off-box: the domain publishes
`p=reject; aspf=s; adkim=s` DMARC; its SPF authorizes no ambient sender; its
DKIM private key exists only sealed to the user's key and is used in-memory,
per send, inside an unlock window. The verifier is **every receiving mail
server on the internet applying your published policy** — infrastructure the
attacker does not control. Root on your box can disable anything local; it
cannot forge a signature it does not have, and it cannot make Gmail ignore
`p=reject`.

The honest costs: a Fortress domain cannot send while locked — mailing-list
confirmations and cron notifications move to a Standard subdomain, whose keys
cannot sign as the bare domain under strict alignment. And a minority of
receivers don't enforce DMARC; spoofed mail can still reach *them*.

## The relay: relocating the plaintext moment

MX records cannot hide behind a CDN; they must name a real IP. Colocated mail
therefore advertises exactly where your archive lives, and the plaintext-
arrival moment runs on the same box as the web app, plugins, and database —
the largest possible target. Fortress puts a **minimal, disposable relay** at
the MX instead: Postfix, verification milters, a small sealing program,
WireGuard. No PHP, no web UI, no database, no user accounts. It seals each
accepted message to the user's public key at the moment of acceptance and
spools ciphertext; the main server dials out to collect. The archive box's IP
appears in no mail DNS.

Stated plainly, this is **trust relocation, not elimination**: whoever
controls the relay (or hosts it) reads mail in its transit window — both
directions, including what you send through it. What changes is what that
position is worth: no archive, no history, no credentials, nothing readable
at rest beyond queue metadata, and a box you rebuild from a script in
minutes — on a schedule, so persistence there has a shelf life.

## What each attacker position actually gets

| Attacker position | Standard | Private | Fortress |
|---|---|---|---|
| Stolen database / leaked backup | everything | metadata only¹ | metadata only¹ |
| Main box, no unlock window open (incl. root) | everything | stored mail unreadable; **new arrivals readable at ingest**; can send as you | stored + new mail unreadable; cannot send as you |
| Main box, during an unlock window | everything | what the window decrypts + the key² | same² |
| Relay box | n/a | n/a | mail in transit, both directions, until rebuild |
| Your DNS registrar account | full identity takeover — publish their own DKIM/MX. Off-box, out of scope, and the reason registrar 2FA matters more than anything here |||
| Push notification channel | full previews | sender/subject (a toggle reduces to generic) | generic by construction — content doesn't exist unsealed to include |

¹ Metadata is cleartext by design and this is a real disclosure: who mails
you, when, thread shapes, sizes, recipient addresses, message-ids. Routing,
threading, and receiving-while-locked are impossible without it. Anyone
telling you their mail server hides metadata from itself is selling something.

² Post-breach, a **key rotation ceremony** re-seals the archive to a fresh
keypair in minutes; the stolen key stops opening anything it hadn't already
exfiltrated, including all future mail.

## Other limits, stated rather than discovered

- **AI features send content to your configured LLM provider** if you enable
  them — a disclosure and a choice (local model or cloud), not silently on.
  Processing of protected mail runs only inside unlock windows; its outputs
  seal like the content they derive from.
- **Search** works by decrypting into a RAM-only index during unlock windows;
  hosts running protected mail must disable swap or encrypt it (the installer
  checks). Attachment *contents* are not indexed; filenames are.
- **Sealing is confidentiality, not authenticity.** Ingest seals to a public
  key; an attacker with database write access can fabricate a plausible sealed
  message. Ciphertexts are bound to their rows (splicing real messages around
  is detected), but wholesale forgery is inherent to receive-while-locked.
- **The spam filter learns only while you're around** on protected domains
  (training reads bodies; scoring at arrival is unaffected).
- **IMAP-sourced mailboxes** (mail pulled from Gmail etc.) cap at Private:
  the remote provider holds plaintext and identity regardless, and pretending
  otherwise would be theater.

## Why PHP (the comment section, pre-answered)

The crypto is libsodium — the same audited primitives regardless of binding
language; nothing cryptographic is hand-rolled, and the sealing envelope lives
in one small helper. More to the point: the threat model already **grants the
attacker code execution on the box** and asks what that position is worth.
When your design assumes compromise, the implementation language moves from
the load-bearing wall to the paint. The load-bearing pieces are libsodium,
WireGuard, Postfix, PostgreSQL, SQLite, and published DMARC policy — boring
infrastructure on purpose. What PHP buys is a single self-hostable codebase
with no build step and a dependency surface small enough to actually read.

## Versus Proton (the fair comparison)

Proton's client-side model is stronger during active use — their server never
holds your unwrapped key. This design is stronger in what you're trusting:
nobody's infrastructure but your own, no JavaScript delivery you can't audit,
sending identity that survives even your own server's root, and a documented,
rotatable recovery path from compromise. Proton asks you to trust Proton;
this asks you to administer a box (with the installer and health checks doing
most of that). Different people should choose differently, and the deciding
question is honest on both sides: *whose compromise do you consider more
likely — the vendor's, or your own box during the minutes you're reading
mail?*
