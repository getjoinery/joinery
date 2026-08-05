# Fortress is not finished until send protection is on

**Supersedes:** decision 1 of
`specs/implemented/mailbox_relay_surface_simplification.md` (2026-08-04), which
held that a Fortress domain resting with send protection off was a finished
domain. It is not. **Every other decision in that spec stands** — the shape
follows the enforcement flag, relay surfaces stay scoped to Fortress, the
receive-mode choice stays out of the way, and the vocabulary sweep holds.

## Why the reversal

`protection_ceremony.php` has always said so:

> A Fortress raise before outbound protection never claims Fortress — one step
> still remains.

The raise receipt refuses to title the domain Fortress and reads *one step left*.
The Setup tab was then rebuilt to call the same state finished and optional. Two
surfaces, one domain, opposite claims — and the ceremony has the better one.

Fortress is a two-sided promise: **nobody can read your mail** (arrival sealing)
and **nobody can send as you** (send protection). A domain with only the first
half is a domain anyone can still impersonate. That is not a security level, it
is half of one.

## Decisions

1. **Fortress is incomplete until send protection is on.** Unfinished is a
   *transit* state, never a resting one. It cannot be made simultaneous with the
   raise — the switch needs published DNS and a vault unlock — so the honest
   model is a declared finishing state, exactly as the ceremony already words it.
2. **An unfinished Fortress domain reads `attention`**, and says so on a card of
   its own rather than by implication.
3. **Turning send protection off states the consequences plainly**, and does not
   strand the domain in DNS that rejects its own mail.
4. **No step of the ceremony may cause silent rejection.** This is the one that
   changes the design; see below.

Send protection remains absent from the general setup path. It is the completion
of the Fortress raise, and the raise is already the advanced, gated ceremony —
so this does not reopen the surface the previous spec closed.

## The ordering defect

The ceremony is publish → verify → activate. Publishing the strict shape
(`v=spf1 -all`, `p=reject; aspf=s; adkim=s`) tells the world to reject anything
the sealed key did not sign — but the sealed key does not sign anything until
activate flips the flag. **Every run therefore has a mandatory window, as long as
DNS propagation takes, in which the domain's own mail is rejected**, accepted by
the provider and discarded downstream where nobody sees it.

That window is not a mistake an operator makes. It is the design.

**The fix is ordering.** Each step is individually harmless if taken in this
order:

| Step | What it does | Why it is safe |
|---|---|---|
| 1. Publish the DKIM record | Adds the sealed key's public half | Changes nothing; no one is asked to reject anything |
| 2. Start signing (the flag) | Messages carry the sealed key's signature | DNS is still ambient, so mail passes on either signature. Cost lands here: sending needs an unlock, and it fails **visibly** with a message rather than silently downstream |
| 3. Publish the strict records | The world starts rejecting unsigned mail | The signature it demands is already on every message |

No step causes a message to be accepted and then silently discarded. The cost of
protection arrives at step 2 as a refusal the operator can see and act on.

This is why `protectedShapeApplies()` branching on the enforcement flag is
load-bearing rather than incidental: the flag means *signing*, and the strict
shape is prescribed exactly once signing is live.

## Work

### 1. The activate gate moves to the DKIM record

`protect_activate` currently requires every REQUIRED row of
`protectedShapeResults()`. It requires instead:

- the vault unlocked (unchanged — this decides what the world accepts as the
  domain),
- `protectedDkimResult()` PASS — the sealed key's record is live,
- the forwarding subdomain's SPF and MX rows PASS — bounces have somewhere to go.

The strict SPF, strict DMARC and provider-verification rows stop gating. They
become step 3, tracked as checks.

### 2. One card for the whole state: `domain.send_protection`

REQUIRED, domain layer, Fortress only. Four states, each with a distinct fix:

| State | Status | Says |
|---|---|---|
| Signing, strict records live | PASS | Only your key can send as this domain |
| Not signing, ambient DNS | FAIL | Fortress is not finished — arriving mail is sealed, but anyone can still send as you |
| Signing, strict records missing | WARN | Your mail is signed, but nothing tells other servers to reject forgeries yet |
| **Not signing, strict records live** | FAIL | **Your DNS is rejecting your own mail.** Either finish turning protection on, or restore the ordinary records |

The fourth row is the state this whole thread began in. It is the only one that
is actively breaking mail, and it must read as urgent rather than as one more
amber row.

### 3. The verdict

`mailbox_setup_verdict()` already grades any REQUIRED FAIL as `attention`, so
the new row carries this with no change to the roll-up — a Fortress domain that
never finished turns its mailboxes amber, correctly.

### 4. The guided box carries the finishing step

Send protection returns to the *Still to set up* box, and only there, as the
completion of the raise. It renders only when:

- the domain is Fortress, **and**
- the relay exists and is enabled, **and**
- the domain's MX points at the relay.

Ordering matters: offering the sending half before mail is arriving through the
relay asks the operator to finish a thing that has not started. The step
disappears the instant protection is on — it never narrates a completed step
back, which was the original complaint that opened this work.

### 5. Turning it off must not strand the DNS

`protect_disable` today flips the flag and suggests re-running
`provision_dkim.sh`. That drops the domain straight into state four: strict
records live, nothing signing.

The disable flow becomes:

- **State the consequence in the confirm, concretely**: this server will be able
  to send as the domain again without you signed in; anyone who breaks into it
  will be able to send as you; arriving mail stays sealed.
- **Revert the DNS in the same action** where a DNS provider is connected —
  the ambient plan is already computable (`dnsPlan()` with the flag cleared).
- **Where it cannot**, say exactly which records must change, and keep the
  `domain.send_protection` row at FAIL until they do. The operator is not left
  to remember.
- **Offer to restore local signing** — with the sealed key no longer in use, the
  domain has nothing signing it until `provision_dkim.sh` runs. If the on-disk
  key was destroyed, say so.

### 6. Lifting is not a normal choice

It stays reachable — a lost vault or an absent owner must not brick a domain —
but it is presented as recovery, not as a preference, and it sits under
Advanced with the rest of the lifecycle.

## Tests

- The four `domain.send_protection` states, each producing its own status and
  fix. The strict-DNS-without-signing state is the regression that matters.
- `protect_activate` succeeds with the DKIM record live and the strict records
  absent, and still refuses with the vault locked.
- The guided box renders the finishing step only with a live relay and the MX
  cut over, and never once protection is on.
- Disabling with a connected DNS provider leaves the ambient shape; disabling
  without one leaves the row at FAIL.
- A Fortress domain without send protection produces an `attention` verdict.

## Out of scope

- The relay, its upgrade path, and the vocabulary sweep — all landed.
- The security levels themselves. Fortress keeps its meaning; this makes the
  interface hold the domain to it.

## Open decisions

None.
