# Sending from your own mail server — an enterprise plugin

**Status:** Draft, 2026-10-04. Nothing here is built. Reviewed the same day by
public-html-d7. Its findings (M1–M10) are folded in below.

**Depends on two specs split out of this one:**
- `mail_reject_at_smtp_time`: the backscatter fix (M3). It ships first, on
  its own.
- `relay_receive_only_forwarding`: relays stop sending and forwarding moves
  to the site (M4).

Together with this spec, the three mean no machine of ours sends on port 25.

## What this does for the user

There are two ways a site's outgoing mail can reach a recipient:

- **Through an email service** (SMTP2GO, Mailgun, SES, any SMTP server
  somewhere else). The service owns the sending reputation: its addresses
  are known to mail providers, it handles blocklists, and it stops a
  compromised site from spamming. This is the right answer for almost
  everyone.
- **From the machine's own mail server**, straight to the recipient's mail
  server on port 25. Doing that well takes a clean IP address, reverse DNS,
  watching blocklists and warming up volume, and getting it wrong lands mail
  in spam. Cloud providers block port 25 on new accounts for this reason
  (Linode and Hetzner both make you ask), and an install that sends this way
  can be turned into a spam source.

Owner decision, 2026-10-04: **the second way is an enterprise feature.** Every
install sends through an email service by default. Sending from the machine's
own mail server moves into a separate paid plugin. Without that plugin, a
machine's mail server delivers nothing to the outside world.

## What stays exactly as it is

- **Receiving mail on your own server.** The mailbox plugin's Postfix keeps
  accepting inbound mail on port 25. Only its ability to deliver *outward*
  changes.
- **Every email service.** That covers SMTP2GO, Mailgun, SES, Postmark,
  SendGrid, Brevo, Mailjet, Resend, a connected mailbox, Joinery Services,
  and SMTP to any server that is not this machine.
- **Forwarding**, which goes out through the email service (mailbox
  overview § Forwarding relay). On relay-fronted sites it moves there too
  (`relay_receive_only_forwarding`).
- **Protected-identity compose on a colocated box** (M6). It submits through
  `SmtpProvider` with the forwarding SMTP settings, which fall back to
  `smtp_host` (`OutboundTransport`, `SmtpConfig::fromForwardingSettings`).
  Pointed at the email service's SMTP endpoint, which every major service
  offers, nothing changes. Only a setup that points it at this machine is
  self-sending, and needs the plugin. The forwarding health check reads the
  same settings and follows the same rule.
- **Joinery Direct**, which delivers between Joinery instances over HTTPS
  (`JoineryDirect`). It never uses port 25.
- **The origin-leak probe.** It goes out through the email service and comes
  back by relay pull. It never opens a port-25 connection of its own.
- **The relay fleet.** Relays keep receiving, sealing and spooling. Their
  sending is `relay_receive_only_forwarding`'s subject.

## Where a machine sends from its own mail server today

There is no explicit "send from this server" option. The capability exists
because **Postfix is installed on every node**: it is baked into the base
image and the bare-metal setup (`install.sh`, "Installing mail stack"),
whether or not the mailbox plugin is active (M1). Stock Postfix delivers
anything handed to it. So:

1. **The SMTP email service pointed at this machine** (`smtp_host` =
   `localhost`, `127.0.0.1`, `::1`, or the machine's own name or address).
   Postfix accepts the submission and delivers it straight to the
   recipient's mail server. On an install without the mailbox plugin, this
   is stock Postfix.
2. **The forwarding override pointed at this machine**
   (`mailbox_forwarding_smtp_host`), by the same route. Protected-identity
   compose and the forwarding health check use these settings too (M6).
3. **Postfix's own messages.** It sends bounce notices for mail it accepted
   and then could not hand on, including backscatter to forged senders.
   `mail_reject_at_smtp_time` removes that cause. This spec's `error:`
   backstop makes sure any bounce that is left cannot leave the box.
4. **Outbound DKIM signing by opendkim** for mail submitted to Postfix. The
   app's own signer covers protected domains, and standard domains behind a
   relay. For a standard domain on a colocated box, the app returns no key
   and leaves signing to opendkim (`MailboxDkimSigner`). When such mail
   leaves through an email service, the service signs it (M9).
5. **The setup checks and DNS advice that assume the box sends** (M8). These
   become wrong on an inbound-only install:
   - the `ip4:` term in the SPF plan for a colocated box
     (`InboundEmailSetupCheck` `spfPlan`)
   - reverse DNS (PTR) as a required check
   - the local opendkim key check
   - `PostfixProvider::getDnsRecords` prescribing `v=spf1 ip4:… -all`
   - `ProvisionManagedDomains` setting PTR

No PHP code calls `mail()` (`SmtpMailer` always uses SMTP), and no shell
script sends mail. When no email service is configured, `EmailSender` queues
the message and sends nothing.

## The design

### 1. One core converger owns Postfix's outbound posture, on every node (M1)

Postfix is on every node, so the switch cannot live in the mailbox plugin's
installer. That only runs where the plugin is active, and nothing runs for an
inactive plugin. A **core** script converges Postfix's outbound posture from
one fact: *is this install allowed to send from its own mail server?* That is
true when the `own_mail_server` plugin is active, or when the install is
grandfathered (section 5). It runs at install, at upgrade, and whenever the
plugin is activated or deactivated. It does not depend on the mailbox plugin.

When the answer is no:

- **Refuse during the SMTP conversation (M2).** Postfix trusts loopback
  (`permit_mynetworks` comes first in `smtpd_recipient_restrictions`), so
  today a localhost submission gets a 250 "accepted", and an `error:`
  transport would only bounce it afterwards. `SmtpProvider` would report
  success and the log would say sent. Instead, a recipient on a domain this
  machine does not receive for is rejected at SMTP time, even from loopback.
  The sender sees the refusal at once.
- **`error:` transports as the backstop.** `default_transport` and
  `relay_transport` become an `error:` transport whose message names the
  plugin. That catches mail injected through `sendmail` rather than SMTP.
- opendkim keeps verifying inbound mail and stops signing outbound.

### 2. The SMTP service refuses this machine as its server

`SmtpProvider`, the forwarding override and protected-identity compose refuse
a server that is this machine unless sending is allowed (section 1). The
check runs when the setting is saved and again at send time.

- **"This machine" is checked as addresses, not text.** The host is resolved
  and compared with `inet_pton` against loopback and every address the
  machine holds, IPv4 and IPv6. A hostname that points back at the machine is
  caught the same way as `127.0.0.1`. A helper for this already exists in a
  plugin (`AgentChannelEndpoint::isThisMachine()`, and `DnsRelocation`). One
  copy moves to core, and both callers use it. The text-only loopback
  checks in `SmtpProvider` and `listener_admin.php` are replaced by it (M7).
- **Save time sees the submitted value.** The email settings page validates
  before it writes (`admin_settings_email_logic.php`), so a check placed
  there must read the submitted `smtp_host`, not the stored one (M7).
- **In a container, "this machine" is the container.** A bridge gateway, or
  the host's public address, can reach a Postfix on the host without being
  this machine. Our Docker hosts run no Postfix. That case is a
  self-hoster's, and the docs say so (M7).

### 3. The plugin

A new plugin, sold under the Joinery Commercial Plugin License (the same
terms as `store` and `server_manager`). Working name: `own_mail_server`.
When active:

- **The core converger opens outbound delivery** (section 1), turns on
  opendkim signing, and opens outbound port 25 in the host firewall where the
  platform manages one.
- **It registers an email service** named "This server's own mail server".
  Sending this way becomes a choice made by name, not an SMTP setting that
  happens to point at localhost.
- **It owns the checks only a self-sending machine needs.** The five
  checks listed in "Where a machine sends today", item 5, move here from the
  mailbox plugin (M8):
  - the SPF `ip4:` term
  - PTR as a required check
  - the local DKIM key
  - the PostfixProvider DNS prescription
  - setting PTR at provisioning

  On an install without the plugin, the mailbox plugin's checks and DNS
  advice name only the email service. **Two are new work, not moves:** a
  blocklist check of the machine's own addresses, and warm-up guidance.
- **It says plainly what the operator is taking on:** their own IP
  reputation, and asking their cloud provider to open port 25.

Deactivating the plugin closes outbound delivery again through the converger.
Outgoing mail stops until an email service is chosen, and the settings page
says so.

The license is a legal boundary, not a lock. A self-hoster with root can edit
Postfix themselves. The goal is that the default is safe and that the
supported path to self-sending is the paid one. The goal is not to make
self-sending impossible.

### 4. Machines we create never send mail themselves

Every machine the platform creates (hosted sites and starter boxes)
drops outbound port 25 in its host firewall. A site's admin can't change
that, because the site runs as `www-data`, not root. Those machines' mail
goes through the hosted tier's SMTP2GO subaccount. This is the promise we can
make to Linode.

- **A platform-created machine refuses the plugin (M10).** Otherwise an Own
  server customer could buy it and break the promise. The plugin's activation
  says why and names bringing their own server as the way to self-send.
- **Generic SMTP to an outside server on port 25 stops working there.**
  Submission ports (587, 465) and every API service are unaffected. The
  settings page says so when port 25 is entered on such a machine.
- **Relays** close outbound port 25 under `relay_receive_only_forwarding`.

### 5. Installs that send from their own mail server today

**One recorded state, "grandfathered: sends from its own mail server", is
read by both the converger (section 1) and the PHP check (section 2).** If
only the converger read it, an upgrade that left Postfix alone would still
refuse at the first send (M5).

Before this ships, use the agent to take an inventory of every managed
install that sends through this machine. That means any install whose email
service, forwarding override or protected-identity compose resolves to this
machine (M6), including installs without the mailbox plugin (M1). Switch each
one to an email service, or give it the plugin, before the upgrade that
closes outbound delivery reaches it.

For installs we do not manage, the upgrade itself checks first. If the
install sends through this machine, the upgrade records it as grandfathered
and leaves outbound delivery open. It raises an admin alert naming the plugin
instead of cutting mail off. The grandfathered state ends when the install
switches to an email service or activates the plugin.

## B1, fixed in this work

`plugins/mailbox/includes/listener_admin.php` labels "no email service
configured" as `local sendmail (no outbound provider configured)` and treats
it as sending through the local listener. `EmailSender` does no such thing:
with no service it queues the message and sends nothing. The label, and the
decommission guardrail that reads it (`listener_admin.php`, pinned by
`listener_decommission_test.php`), describe a path that does not exist. With
this spec the label becomes "no email service configured — nothing is sent",
and "sending through this machine" means only the plugin's service, or an
SMTP host that is this machine by section 2's check. The test changes with
it.

## Testing

| Check | Pass |
|---|---|
| fresh install, mailbox plugin inactive; submit to localhost:25 for an outside address | refused during the SMTP conversation |
| fresh install with the mailbox plugin; same | refused during the SMTP conversation, with the plugin named |
| mail injected through `sendmail` for an outside address | refused by the `error:` transport |
| SMTP service set to `localhost`, `::1`, the box's public IPv4 or IPv6, or a hostname that resolves to it | refused when saved (the submitted value) and when sending |
| protected-identity compose with forwarding SMTP set to the email service | sends |
| plugin activated on a self-hosted install | the "own mail server" service sends, DKIM-signed by opendkim; the SPF, PTR and blocklist checks appear |
| plugin activation on a platform-created machine | refused, with the reason |
| plugin deactivated | outbound delivery is refused again; the settings page says mail is not going out |
| upgrade of an install that sends through itself | recorded as grandfathered; mail keeps flowing; admin alert raised |
| a Joinery Direct delivery from an inbound-only install | arrives |

## Docs to update when this lands

- The mailbox overview: Postfix is inbound-only (§ Firewall, § opendkim,
  § DNS), and self-sending lives in the plugin.
- `docs/email_system.md`: the email service is how mail is sent, and the
  plugin is the one way to send from the machine itself.
- `docs/installation.md`: Postfix on every node is inbound-only unless the
  plugin is active.
- The plugin's own docs.
- `docs/plugin_developer_guide.md` § Licensing: the plugin joins the
  Joinery-Commercial list.
