# Relays only receive; sites do the forwarding

**Status:** Draft, 2026-10-04. Nothing here is built. Split out of
`own_mail_server_sending` (its section 6, from review finding M4,
public-html-d7). Depends on nothing. `own_mail_server_sending` relies on it
for "no machine of ours sends on port 25".

## What this does for the user

**Owner decision, 2026-10-04: no machine of ours sends mail on port 25,
relays included.** Sending from our own IP addresses carries a reputation we
have to defend, and it makes our cloud provider nervous. Forwarding stays,
under rules that make it safe.

A forwarding address (`info@club.org` → someone's Gmail) is the default kind
of alias. Something has to send the copy on. Today, for a site behind a
relay, that something is the relay, from its own IP address, shared by every
tenant on it. After this, the site sends the copy through its own email
service, as a site that receives its own mail already does. The relay sends
nothing at all.

## Why relays have to change

- **A relay sends on port 25 today.** It carries out forward-mode aliases
  itself and re-injects them through its own Postfix
  (`relay-sealer/forward.go`; `provision_relay.sh` "outbound (forward + SRS
  bounce legs)"). Gmail and others judge forwarded mail by the IP address
  that delivers it. Every tenant on a relay shares that address's
  reputation.
- **Relays forward spam today.** The relay's rspamd only adds headers and
  never rejects (`rspamd_stateless.sh`). Nothing in the sealer reads the spam
  verdict before forwarding (`relay-sealer/main.go`). A colocated box never
  forwards a message judged spam (mailbox overview § Forward suppression).
  So relay addresses carry spam to Gmail on every tenant's behalf.

## The five rules that make forwarding safe

The build makes each one true:

1. **The copy leaves through the site's email service, never from a machine
   of ours.**
   - The relay stops carrying out forward-mode aliases. It spools
     forward-mode mail like any other mail.
   - The site forwards it at pull time, through the same `InboundEmailRouter`
     path colocated forwarding uses (`resolveRelayProvider()`, the email
     service). Today `RelaySpoolConsumer` runs store ingest with "no
     re-forwarding — the relay already forwarded"; it runs the full router
     instead.
   - A forward-only alias keeps no stored copy, as now. The spooled message
     is forwarded and then discarded.
   - `RelayMapExporter` stops sending the relay its forward instructions, the
     forward From identity and the SRS secret.
   - The relay keeps accepting SRS bounces and spooling them, as now.
2. **The site can read the message when it arrives.** On a Standard domain,
   or a Private domain without Seal at the relay, the relay seals to the
   transport key the site holds, so the site opens it at pull. **On a domain
   with Seal at the relay, or at Fortress, forwarding is not offered.**
   Aliases there are store-only, and the alias editor says why: a forward
   hands the recipient's provider a readable copy, which is what those levels
   exist to prevent. Existing forward-mode aliases on such a domain stop
   forwarding, are logged, and keep their address so the change is visible.
   That is the same pattern as raising a domain's level (mailbox overview §
   Forwarding off a protected domain).
3. **Nothing judged spam is forwarded.** The router's existing forward
   suppression applies, because forwarding now runs through the router.
4. **The person at the destination has confirmed it.** A new forwarding
   destination receives one confirmation message ("mail for info@club.org
   will be forwarded to you; confirm or ignore"). Nothing is forwarded to it
   until they click. Without this, an alias can push mail into a stranger's
   inbox, and that complaint is the one that hurts a sending reputation. This
   applies on every install, colocated or relay-fronted, to any destination
   that is added or changed. Destinations that already forward before this
   ships keep working with no message.
5. **Forwards count against the site's sending allowance.** On a hosted or
   starter site that is the SMTP2GO subaccount's monthly allowance, so the
   provider caps and polices the volume, not our relay. A forwarding address
   that gets 40 messages a day uses 1,200 forwards a month, more than a
   starter site's whole 1,000. The banner's send line already says so when
   the allowance is near.

**What it costs.**

- **Delay:** forwards wait for the site's next pull
  (`MailboxRelayReconcile`, every scheduled-task pass), so minutes rather
  than seconds.
- **Money:** forwards are billed like sent mail, $0.75 per 1,000 on SMTP2GO
  Professional.
- **Sealed domains:** they cannot forward (rule 2).

The routing protections that exist carry over unchanged: the From rewrite to
the site's verified address, SRS on the envelope with bounces routed back to
the original sender (`handleSRSBounce`), and the forward loop guard.

## The relay closes outbound port 25

Once it forwards nothing, a relay sends nothing:

- Its firewall drops outbound port 25.
- Its Postfix gets an `error:` default and relay transport. That backstops
  anything injected locally.
- `provision_relay.sh` loses the forward and SRS-bounce outbound legs, and
  `smtp_address_preference`, which existed only for them.
- The sealer's forward code (`forward.go`) and the per-tenant forward
  throttle are removed.
- Self-hosted relay deployments follow the same rules: their relay stops
  forwarding too, and their site forwards through its own email service.

**Rollout order matters.** A tenant's site must be on the version that
forwards at pull before its relay stops forwarding. Otherwise forwards stop
in between. The plane pushes the new routing (no forward instructions) per
tenant only once that tenant's site reports the new version. The relay drops
outbound 25 once no tenant on it still has forward instructions.

## Testing

| Check | Pass |
|---|---|
| a forward-mode alias on a relay-fronted Standard domain receives mail | the relay spools it and sends nothing; the site forwards it through its email service at the next pull; no stored copy is kept |
| the same, with rspamd flagging the message as spam | not forwarded; logged `spam_held` |
| a relay tries to send to an outside server on port 25 | dropped |
| a new forwarding destination is added, on a colocated install and a relay-fronted one | one confirmation message goes out; nothing is forwarded until it is confirmed |
| a destination that forwarded before the upgrade | keeps forwarding, no message |
| a forward-mode alias on a domain with Seal at the relay | the editor offers store-only; an existing forward stops and is logged |
| a forwarded message bounces | the bounce reaches the relay, is spooled, and the original sender gets the failure notice |
| forwards on a starter site | counted in the SMTP2GO subaccount's monthly allowance |
| a tenant whose site is still on the old version | its relay keeps forwarding for it until the site upgrades |

## Docs to update when this lands

- The mailbox overview § Hardened ingest relay: the relay receives, seals and
  spools, and sends nothing.
- § Forwarding relay: forwards from a relay-fronted site go out at pull time
  through the email service, need a confirmed destination, and are not
  offered on Seal at the relay or Fortress domains.
- § Delivery Modes: a forwarding destination needs confirming.
