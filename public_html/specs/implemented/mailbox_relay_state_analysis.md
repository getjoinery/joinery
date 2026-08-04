# The relay and protection surfaces: every state, and what the interface says

**Status: ACTED ON 2026-08-04.** The decisions recorded below were built as
`mailbox_relay_surface_simplification.md`. Kept as the state map and the
reasoning behind those decisions.

## Why this exists

Four separate wording fixes were made to the Fortress setup checklist in one
sitting, and the fifth thing the operator read was still wrong. That is the
signature of a surface with more states than anyone is holding in their head,
being repaired one message at a time.

This document is the read-across: every axis of state the relay and protection
features actually have, which combinations are reachable, and what the interface
says in each. It ends with the simplifications that fall out.

**This is analysis, not a change proposal.** Decisions go in a follow-up spec.

## The word "relay" means three different things

This is the first finding, and probably the largest single cause of confusion —
it is not a wording slip in one message, it is three unrelated concepts sharing a
noun across the whole surface.

| What the code calls it | What it actually is | Where it shows |
|---|---|---|
| **The relay** (`MailboxRelay`, Relay section) | A hardened VPS that fronts the public MX, verifies and *seals mail on arrival*, and spools ciphertext for this box to pull. **Inbound.** | Setup tab Relay section, `host.relay_scanner`, `plugin.relay_enable` |
| **Outbound forwarding relay** (`plugin.relay` check) | The outbound SMTP path — the provider credential (Mailgun) or an SMTP relay host — used when *forwarding* mail onward. **Outbound. Nothing to do with the VPS.** | A REQUIRED check row labelled "Outbound forwarding relay" |
| **Relay outbound mode** (`mailbox_relay_outbound_mode`) | Whether this box's *own composed mail* leaves through the ingest relay as a smarthost, or through the provider. **Outbound, but about the VPS.** | Settings tab; changes what `provision_relay.sh` opens |

An operator reading "Outbound forwarding relay — FAIL" next to a Relay section
about a VPS has no way to know these are unrelated. Same for "the relay is not
scanning" (the VPS) versus "the outbound SMTP relay is reachable" (Mailgun).

**Two more collisions in the same family:**

- **"Protection"** means *both* arrival sealing (the Fortress level) and outbound
  send protection (`ied_is_protected_identity`). A box headed "Protected setup —
  Fortress" saying "turn protection on" reads as an instruction to redo the thing
  that is already done. (Partially addressed 2026-08-04; the underlying overload
  remains.)
- **"Enabled"** means *three* things on a relay: the row's `mrl_is_enabled`
  emergency stop, whether the DNS cutover has happened, and whether the relay is
  reachable at all.

## The axes

Thirteen independent axes bear on what the Setup tab renders. Not all combine.

### Deployment-wide

| # | Axis | States | Set by |
|---|---|---|---|
| 1 | Receive mode | `''` undecided · `direct` · `relay` | `mailbox_receive_mode` setting, else inferred from whether a relay row exists |
| 2 | Relay row | none · exists disabled · exists enabled | Provisioning; `mrl_is_enabled` |
| 3 | Relay origin | cloud · managed node · hand-built · hosted slot | `mrl_cloud_instance_id` / `mrl_mgn_managed_node_id` / `mrl_is_hosted` |
| 4 | Relay code version | current · behind · ahead · unknown | `joinery-ping` `provisioned` vs `RELAY_VERSION` |
| 5 | Relay scanner health | ok · not_delivering · legacy · unreadable · unreachable | `joinery-ping` |
| 6 | Relay sole tenancy | true · false · unknown | `joinery-ping` `sole` |
| 7 | Relay outbound mode | provider · smarthost | `mailbox_relay_outbound_mode` |
| 8 | Cutover progress | not started · partial · complete | every hosted domain's MX vs the relay |
| 9 | Hosted fleet offered | on · off (**currently hard-coded off**) | `mailbox_hosted_relay_offered()` |

### Per domain

| # | Axis | States | Set by |
|---|---|---|---|
| 10 | Security level | standard · private · fortress | `ied_security_level` |
| 11 | Signing key | none · live · live + pending rotation | `ied_dkim_sealed_key`, `ied_dkim_pending_sealed_key` |
| 12 | Send protection | off · on | `ied_is_protected_identity` |
| 13 | Protected DNS shape | unpublished · partial · complete | 6 REQUIRED checks |

### Per acting user

| # | Axis | States |
|---|---|---|
| 14 | Vault | none · exists locked · exists unlocked |

Axes 10–14 are what the guided box reads. Axes 1–9 are what the Relay section and
the Advanced disclosure read. **The guided box currently reads axis 2 and axis 13
as well** — which is why it kept saying wrong things: it is the only surface that
spans both halves.

## The Fortress happy path, as states

The intended progression for one domain, and what must be true at each step:

| Step | Gate | Who can do it |
|---|---|---|
| 1. Vault exists | axis 14 ≠ none | The owner |
| 2. Domain raised to Fortress | axis 10 = fortress | Admin. **Seals a signing key as a side effect** (axis 11 → live) |
| 3. Relay exists and is enabled | axis 2 = enabled | Admin, once per deployment |
| 4. Domain's MX points at the relay | `domain.mx` PASS | DNS |
| 5. Protected DNS shape published | axis 13 = complete | DNS |
| 6. Send protection on | axis 12 = on | The owner, **vault unlocked** |
| 7. Old on-disk key destroyed | manual `provision_dkim.sh --remove` | root on the box |

**Steps 3–5 are DNS-and-infrastructure; steps 1, 2, 6 are ceremony; step 7 is a
shell command nobody is reminded about again.** Step 7 is the only step with no
state at all — nothing records whether it was done, and nothing checks.

### What is REQUIRED versus OPTIONAL, honestly

| Thing | Required for what | Actually optional? |
|---|---|---|
| Vault | Private and Fortress | Required at those levels |
| Relay | **Fortress only** | Optional at Standard/Private — but the receive-mode gate presents it as a deployment-wide choice before any domain has a level |
| Send protection | Nothing — it is the *point* of Fortress, but a Fortress domain works without it | **Optional, and the checklist never says so** |
| Standard subdomain for automated mail | Only if automated mail exists | Optional; offered unconditionally |
| Relay upgrade | Nothing | Optional |
| Spam scanner health | Nothing — mail flows either way | Reports as WARN/FAIL |
| Destroying the on-disk key (step 7) | Real security value | Presented once, in a flash message, then never again |

**The largest honesty gap:** a Fortress domain with send protection OFF is a
perfectly working configuration — arriving mail is sealed, sending works
normally. The checklist presents step 6 as unfinished business rather than as an
optional tightening with a real cost (every interactive send needs an unlock;
automated mail must move to a subdomain).

## What the interface does, per state

### The receive-mode gate (axis 1)

| State | Setup tab shows |
|---|---|
| `''` undecided | **Only** the choice card. Every other surface is suppressed. |
| `direct` | No Relay section unless Advanced is open |
| `relay` | Relay section renders |

**Problem:** the choice is forced before the operator has any domain at a level
that needs a relay. A relay is a Fortress requirement, but it is asked for as a
deployment-wide decision up front.

### The Relay section (axes 2–9)

| Relay state | Renders |
|---|---|
| No relay | "No relay yet" + provisioning paths (cloud / node / by hand) |
| Exists, disabled | Row with a Disabled badge; Enable button |
| Exists, enabled | Row + health dots + details disclosure |
| Cloud origin, version behind/unknown | **Upgrade relay** button |
| Managed node, live node | **Rebuild** button |
| Hand-built | Sentence: re-run `provision_relay.sh` |
| Hosted slot | Sentence: operator-managed |
| Sole tenancy false | Upgrade refused with an explanation |
| Scanner WARN/FAIL | A card in Receiving (promoted 2026-08-03) |

This section is **state-complete and reasonably honest.** The confusion is not
here.

### The guided "Protected setup" box (axes 10–14)

This is where the trouble is. Current behaviour after the 2026-08-04 fixes:

| Condition | Step rendered |
|---|---|
| No vault | Set up your vault |
| Fortress, no key | Say who the signing key belongs to |
| Fortress, key, protection off, DNS incomplete | Publish DNS, then lock sending |
| Fortress, key, protection off, DNS complete | Lock sending to your key |
| Fortress, no active relay | Provision / enable the relay |
| Fortress, no Standard subdomain registered | Add one for automated mail |
| Everything done | **Box does not render** |

**Remaining problems:**

1. **The box mixes three scopes.** The vault is per-user, the relay is
   deployment-wide, protection and DNS are per-domain. Viewing a second Fortress
   domain re-offers deployment-wide work that is already done for the first.
2. **Nothing conveys that step 6 is optional**, or what it costs.
3. **Step 7 (destroy the on-disk key) is absent entirely** — it appears once in a
   flash message and is never checked or re-offered.
4. **The unlock gate is invisible until you press.** The button refuses with
   "unlock your vault first" and changes nothing; a locked vault is knowable
   before rendering the button.
5. **`mail.jeremytunnell.com` is Mailgun's sending domain but not a registered
   Joinery domain**, so the "add a Standard subdomain" offer persists for a
   deployment that effectively has one.

### The verdict roll-up

`mailbox_setup_verdict()` returns `attention` if **any** row is FAIL or WARN.
Consequence: promoting the relay scanner to a WARN card (2026-08-03) flips every
mailbox behind that relay to `attention` — one deployment-wide fact repeated as a
per-mailbox alarm. Documented as deliberate at the time; worth revisiting given
the confusion budget.

## Reachable-but-wrong states

States the system can be in that no surface explains well:

| State | What the operator sees | What is actually true |
|---|---|---|
| Fortress, relay enabled, MX **not** cut over | Checks mostly pass | Mail is arriving **unsealed** at this box |
| Fortress, protection on, vault locked | Sends fail at compose | Correct, but reads as a bug |
| Relay enabled, cutover complete, relay disabled later | `plugin.relay_enable` REQUIRED FAIL | Mail arriving with no consumer — good, this one is handled |
| Relay answers `PONG` | Scanner "legacy", version "unknown" | Two separate rows for one cause: an old relay |
| Send protection on, on-disk key never destroyed | Nothing | A usable signing key sits on the machine |
| Standard domain, relay exists | Relay section renders | The relay does nothing for that domain |

## Simplifications this suggests

Ordered by confusion removed per unit of work.

1. **Rename the two non-VPS "relays".** "Outbound forwarding relay" → *outbound
   mail path* or *sending route*. `mailbox_relay_outbound_mode` → *how composed
   mail leaves*. Pure wording; no behaviour.
2. **Split "protection" into two named things everywhere** — *arrival sealing*
   (the level) and *send protection* (the identity). Partly done; finish it.
3. **Say that send protection is optional, and what it costs**, at the point of
   offering it. It is a tightening, not an unfinished step.
4. **Separate the guided box by scope**: per-domain steps in the domain box;
   deployment-wide setup (relay) in the Relay section only, where it already
   reads correctly.
5. **Gate the send-protection button on the unlock state** and offer Unlock
   inline, instead of refusing after the press.
6. **Give step 7 a state.** Either check for the on-disk key and render a step, or
   have the platform destroy it and stop asking.
7. **Collapse the legacy-relay rows.** One "this relay predates X, upgrade it"
   fact, not a scanner row plus a version row.
8. **Defer the receive-mode choice** until a domain needs a relay, rather than
   forcing it before any domain has a level.
9. **Reconsider the verdict roll-up** for deployment-wide facts, so one relay
   problem does not read as every mailbox being broken.

## Decisions

**Send protection is a deliberate opt-in and an advanced feature.** It is not a
step in any wizard, guide or checklist. A Fortress domain with send protection
off is a finished domain, and the interface must treat it as one.

Consequences:

- Item 3 above changes: do not *explain* that step 6 is optional in the guided
  box — **remove step 6 from the guided box entirely.** Offering it with a
  caveat still reads as unfinished business.
- The offer lives on an Advanced surface for the domain, where the cost (an
  unlock per interactive send; automated mail must move to a subdomain) is
  stated at the point of choosing.
- The guided box for a Fortress domain is complete once the key exists, the DNS
  shape is published and the relay is carrying mail. Nothing after that.
- The "add a Standard subdomain for automated mail" offer belongs with send
  protection, not in the guided box — it only exists because of protection.

**Standard and Private domains show nothing relay-related in any card or
wizard, but may still use a relay.** The relay is deployment-wide
infrastructure, not a per-domain feature, so it stays reachable in the Relay
section and Advanced — it just never appears as a step, card or verdict input
for a domain whose level does not require it.

Consequences:

- Item 8 above hardens: the receive-mode gate (axis 1) must stop being a
  mandatory up-front choice. It becomes an Advanced setting, defaulting to
  direct, and is only *asked for* when a domain is raised to Fortress.
- The relay scanner card and `plugin.relay_enable` REQUIRED row must be scoped
  to deployments that have at least one Fortress domain. On a Standard-only
  deployment with a relay, relay health is informational, not a verdict input —
  which also resolves item 9 for the common case.

**On-disk key destruction: detect always, offer where possible, never silent.**
The key lives on this box (`/etc/opendkim/keys/{domain}/mail.txt`), not on a
relay, and the setup checks already read it — detection needs no new plumbing.

Consequences (item 6 above):

- A check row reports that a usable signing key is still on disk while send
  protection is on, and says what that means: anything able to submit through
  local opendkim can still sign as this domain.
- A one-click destroy action sits next to it, gated on send protection being on
  **and** the protected DNS shape being complete. It runs a fixed-verb root
  helper in the shape already used by the listener on/off buttons
  (`sudo -n` a helper in `/usr/local/sbin`, sudoers drop-in, success marker) —
  never blanket sudo, never an argument the web user controls freely.
- Where the helper is not installed, the row falls back to the existing manual
  command. The manual path is not retired.
- The action is never taken automatically. Deleting the key is destructive and
  irreversible from a web button; it happens because a human pressed it.

## Open questions for the owner

None outstanding. The follow-up change proposal is
`mailbox_relay_surface_simplification.md`.
