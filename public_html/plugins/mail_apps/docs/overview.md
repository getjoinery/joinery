# Mail Apps Plugin

Members read and send their Joinery mail from a mail program — Thunderbird, Apple Mail, K-9, Outlook's IMAP mode, anything that speaks IMAP and SMTP submission — signing in with an **app password** made on their Mail apps page.

- **Standard** mailboxes work at all times.
- **Private** mailboxes work while the member's vault is unlocked on the website.
- **Fortress** mailboxes are not offered: the server cannot open them.

The plugin depends on `mailbox`; `mailbox` does not know it exists.

## The pieces

```
mail program ──TLS 993/465──▶ mail gateway (Go, on the host) ──loopback HTTPS──▶ /api/v1/action/mail_apps/mail_*
                                                                                   │
                                            mail_apps PHP (numbering, change tracking, rebuild)
                                                                                   │
                                        mailbox's public methods + VaultUnlock + ApiAuth (core)
```

| Piece | Where |
|---|---|
| The gateway: holds programs' long connections, turns each IMAP/SMTP command into an API call. No database, no vault, no credential of its own | `provisioning/mail-gateway/` (source), `provisioning/bin/mail-gateway-<arch>` (prebuilt) |
| The actions the gateway calls | `logic/mail_*_logic.php` |
| Who and which mailbox | `includes/MailAppContext.php` |
| Folders, numbering, change tracking | `includes/MailAppFolders.php` |
| What a program's changes do | `includes/MailAppMutations.php` |
| The bytes a program is served | `includes/MailAppRebuild.php` |
| Text search | `includes/MailAppSearch.php` |
| The Allow mail apps switch and health | `includes/MailAppGateway.php`, `admin/admin_mail_apps.php` |
| The member's page | `views/profile/index.php` (`/profile/mail_apps`), `assets/mail_apps.js` |
| Autoconfig | `views/autoconfig.php` (core routes `/.well-known/autoconfig/mail/config-v1.1.xml` and `/mail/config-v1.1.xml` to it) |
| Host install | `provisioning/install_mail_gateway.sh` (the host installer) |

## Signing in

A program signs in with the **mailbox address** as its username and an **app password** as its password. The app password is a core app credential of the `mail` purpose, declared in this plugin's `plugin.json`:

```json
"appAccess": {
    "mail": { "label": "Mail apps", "borrowsUnlock": true }
}
```

Core owns everything about app credentials (`docs/api.md` § App credentials, `docs/account_security.md`):
- A member makes one on the Mail apps page, through core's `app_password_create` action, after confirming it is them (step-up). It is shown once, as `jm-{public}-{secret}`.
- It reaches only actions whose descriptor declares `'app_purpose' => 'mail'` — the `mail_*` actions here. Those actions admit nothing else.
- Failed sign-ins count in their own bucket (`app_access_auth`): 60 per 15 minutes per source network, 600 per username.
- The plugin declares `"requiresSetting": "mail_apps_enabled"` on the purpose. While Allow mail apps is off, no app password is made and every one is refused, and its borrowed windows end.

The gateway relays what the program presented, unchanged, in `X-Joinery-App-Username` / `X-Joinery-App-Secret`, and the program's address in `X-Joinery-Client-Ip`. `ApiAuth` resolves the credential and believes the address header only from loopback. The gateway never decides whether a credential is good.

One app password opens every mailbox the member holds a **grant** on. An all-access administrator's oversight of other mailboxes does not reach a mail program.

Deactivating the plugin turns every `mail` credential off at once: the purpose is no longer declared.

## Folders

Joinery uses labels, not folders. A program sees them the way Gmail presents labels over IMAP:

| Folder | Special use | Holds (`MailboxService::viewScopeSql()` view) |
|---|---|---|
| `INBOX` | | `inbox` |
| `Sent` | `\Sent` | `sent` |
| `Drafts` | `\Drafts` | `drafts` — the signed-in member's own |
| `Archive` | `\Archive` | `archive` |
| `Spam` | `\Junk` | `spam` |
| `Trash` | `\Trash` | `trash` |
| one per label of the mailbox | | `label` (a label named like a fixed folder is listed as `Labels/{name}`) |

`viewScopeSql()` is mailbox's own definition of each view, the same one its reader lists from.

There is no All Mail (`\All`) folder. A program downloads every folder for offline use, so it would store every message twice; Archive and the labels already reach everything outside the Inbox.

The gateway names folders by **key** (`inbox`, `sent`, `drafts:{user id}`, `archive`, `spam`, `trash`, `label:{label id}`); the program sees names. A `label:{label id}` key names only one of the mailbox's own labels; another mailbox's is no folder here.

### What a program's actions do

| In the program | In Joinery |
|---|---|
| Mark read / unread, flag | `markRead()`, `setStarred()` |
| `\Answered`, `$Forwarded` | this plugin's `mam_` row (a reply sent from the web reader sets neither) |
| Move Inbox → Archive | `setArchived(true)` |
| Move anything → Trash | `softDelete()`; a draft is deleted |
| Move or copy into a label | `setMembership(true)`; a move out of Inbox also archives, and out of a label also removes that label |
| Move → Spam | `setSpamVerdict('spam')` |
| Move out of Spam | `setSpamVerdict('ham')` |
| Move out of Trash | `restoreFromTrash()` |
| `\Deleted` + `EXPUNGE` in Trash | `purgeFromTrash()` — gone for good |
| `\Deleted` + `EXPUNGE` in a label | the label comes off; the message stays |
| `\Deleted` + `EXPUNGE` in Drafts | the draft is deleted |
| `\Deleted` + `EXPUNGE` elsewhere | `softDelete()` |
| Create / delete a folder | `createFolder()` / `deleteLabel()` (one of the mailbox's own labels) |
| Rename a folder | refused: labels cannot be renamed |
| Move into Sent or Drafts | refused |

The junk keywords `$Junk` and `$NotJunk` are not offered (they are in neither `FLAGS` nor `PERMANENTFLAGS`), so a program keeps its junk marks to itself. A program's automatic junk classifier sets them on mail the person never touched, and as server verdicts they would move that mail out of the Inbox everywhere. Only a move into or out of Spam sets a verdict.

Flags Joinery does not keep (a program's own tags such as `$label1`) are left out of `PERMANENTFLAGS`, so the program keeps them locally.

## Message numbers

IMAP numbers each folder's messages, and a message added to a folder must get a number above every number that folder has used. Each **appearance** of a message in a folder is a row in `mau_mail_app_uids`, numbered from the folder's counter in `maf_mail_app_folders`. A row is never reused: when the message leaves the folder, the row is stamped expunged and its number retired. A message archived and moved back, or labelled late, comes back with a new, higher number.

These numbers are not mailbox's `ilm_imap_uid` / `iem_imap_uid`, which record numbers on a source server Joinery pulls from.

**Reconcile** (`MailAppFolders::reconcile()`, under the folder row's lock):
1. Rows whose message left the folder's view are expunged.
2. Messages in the view with no live row are numbered in arrival order — a sealed one only when it can be served.
3. Each live row's flags as last told (`mau_flags_told`) are compared with the truth now. Rows that differ get the folder's next change number (`mau_modseq`, CONDSTORE).
4. In Inbox only, the lock notice is added or expunged (below).

Because step 3 compares against the current truth, not a log of events, a change made in the web reader while no program was connected is found on the next reconcile. Mailbox reports nothing to this plugin.

`UIDVALIDITY` changes only when a folder's numbering starts over:
- A label deleted and recreated is a new label, so a new folder.
- A change to `MailAppRebuild::FORMAT` starts every folder over.

## Whole messages

Programs ask for whole messages, and most stored mail keeps no original. `MailAppRebuild` serves one of three things:

- **stored** — the original, where one is stored whole.
- **remote** — a message pulled in from a connected source and kept there: its original, fetched live from the source, as Show original does. A source that no longer has it answers unavailable.
- **rebuilt** — everything else: the retained header block (or headers made from the row's columns), then a rebuilt MIME tree. The tree is `multipart/mixed` over `multipart/related` (HTML and its inline parts by Content-ID) over `multipart/alternative` (plain and HTML), with attachments in manifest order.

A rebuilt message is **the same bytes every time**:
- boundaries are derived from the message id and the format version;
- bodies are UTF-8 quoted-printable by the class's own encoder;
- attachments are base64 at 76 columns;
- CRLF throughout.

The output is versioned by `MailAppRebuild::FORMAT`. Changing a byte of it means a new FORMAT, which starts every folder's numbering over.

A message's size is fixed the first time it is told (`mam_client_size`), because programs ask for sizes in bulk and read a message in pieces up to that size. A rebuilt message's size is known without reading an attachment: a file-backed part records its decoded length, and base64's length follows from it.

A mail app's copy is a faithful rendering of what Joinery holds, not a claim to be the original. The reader's Download .eml still serves only a stored original. The sender's DKIM signature does not verify against a rebuilt body.

## Private mailboxes and the lock

A program never unlocks anything. Its app credential **borrows** the member's website unlock (`docs/sealed_vault.md` § Borrowed windows): unlocking on the website also arms a window slot for each of the member's mail-app credentials.

A program's checks count as activity, up to the window's absolute cap: 7 days, or 24 hours on a hardened domain. The member is told this on the Mail apps page before making a password, and the lock chip names each program reading through the unlock.

While the window is shut:
1. **New sealed mail gets no number**, so nothing new appears. When the member unlocks, it is numbered and arrives as new mail.
2. **Mail already numbered stays visible.** A fetch of one the program never downloaded answers `NO [UNAVAILABLE] Mailbox locked — unlock at {link}`.
3. **Nothing is ever served in a message's place.** A program keeps each message's bytes by number for good.
4. **Inbox holds one lock notice**, a generated message with the unlock link, while sealed mail waits. Its bytes never change. It is expunged when the lock lifts. A notice the member deletes stays deleted until the lock lifts.
5. **Read, star, archive, label, delete and move still work**: they are metadata.
6. **Sending works**, except on a domain with *Only send while I'm signed in*, whose signing key needs the window. There the submission is refused with `454 4.7.0`, a temporary failure, so the program keeps the message in its Outbox.

## Sending

`mail_submit` takes the program's whole message and envelope:
1. The `From` must be this mailbox (`550 5.7.1` otherwise).
2. A message signed or encrypted as a whole (PGP/MIME, S/MIME) is refused (`554 5.6.0`): Joinery signs what it sends as the mailbox, which would break it. Inline PGP works.
3. `MailboxSender::sendRaw()` carries the message over part for part and sends it the way the web reader sends: the mailbox's transport, in-app DKIM, the hidden-origin rules, test mode, Joinery Direct, and the attempt record.
   - The envelope decides who receives it.
   - Envelope recipients not in To or Cc are Bcc.
   - The program's Message-ID is kept when it names this mailbox's domain, and minted there otherwise.
4. The Sent copy is the program's own message, stored once through `storeRawOutbound()`, without `Received` and `X-Originating-IP`.
5. The message it replies to gets `\Answered`; a forward's original gets `$Forwarded`.

A program's `APPEND` of its copy to Sent is recognised by Message-ID (or the one it gave before a minted one replaced it), so nothing is stored twice.

**`APPEND` always answers the message's number (`APPENDUID`).** A program keeps the bytes it appended under that number and does not fetch them again. What the site serves for the same number can differ in encoding, because a copy to Sent is matched to the site's own sent copy, a draft is saved through mailbox's drafts, and other mail is stored as headers and decoded parts. It is the same message, but a program's own copy of something it appended can differ in such details from what another device downloads. The number cannot be left out: Thunderbird reads a missing one as the number of the last message it fetched, then files its copy and runs its after-send filters under that wrong number.

## Drafts and other added messages

- **`APPEND` to Drafts** becomes a Joinery draft through `MailboxDrafts::saveDraft()`, parsed from the program's MIME, attachments and inline parts included. A draft started in a program opens in the web reader and the reverse. The program's Message-ID is kept on the plugin's row, so a program that searches for its draft by Message-ID finds it.
- **`APPEND` to any other folder** (a program's "copy to server") is stored as mail of the mailbox and placed in that folder. It is sealed where the mailbox seals, so adding works while locked.

## Finding the server

The Mail apps page always shows the manual settings:
- IMAP: the deployment's mail host name (`mailbox_mail_hostname`, its MX target), port 993, SSL/TLS. Not the web name, which may sit behind a CDN that carries web ports only. Where mail arrives through a relay, the relay's mail host name (`mrl_mx_hostname`; see *Through a relay*).
- SMTP: the same host name, port 465, SSL/TLS.
- Username: the mailbox address.
- Password: the app password.

Thunderbird finds them itself from `https://{maildomain}/.well-known/autoconfig/mail/config-v1.1.xml` when the mail domain's website is this site. It also looks at `https://autoconfig.{maildomain}/mail/config-v1.1.xml`, which needs a CNAME `autoconfig.{maildomain}` to this site's host and a certificate for that name. Both answer only for mail domains this site hosts, and only while mail apps are allowed.

## Turning it on

The **Allow mail apps** switch (`/plugins/mail_apps/admin/admin_mail_apps`, permission 9) runs the root helper `/usr/local/sbin/joinery-mail-gateway on|off|status|refresh|remove SITE` through sudo.

**On** takes the site's mail host name and, when it has no certificate yet, issues one with the system's certbot. It does so only if the name's A/AAAA records include this machine's own address, and at most once an hour. The Apache method adds the challenge to the port-80 sites, with the webroot of Apache's default port-80 site as the fallback; no script from the site's tree runs as root. It then serves the site by that name (recorded as `mail_apps_served_name`, which the pages advertise while mail apps are on), starts the `joinery-mail-gateway` service, and opens 993/tcp and 465/tcp (IPv4 and IPv6, through ufw when active). **Off** stops serving the site. When no site is left, it stops the service and closes the ports. The switch records `mail_apps_enabled`, which the Mail apps page and autoconfig read. Both settings are `managed`: the Mail apps admin page is the only switch, because the gateway and the borrowed windows follow it.

**The binaries are built at publish.** `MailGatewayPublisher` (listed in `GoBinaryPublisher::PLUGIN_PUBLISHERS`, beside the mailbox plugin's relay sealer) cross-compiles `provisioning/mail-gateway/` with the toolchain its `go.mod` pins into `provisioning/bin/mail-gateway-x86_64` and `-aarch64`, stamped with the source they were built from (`.source_sha256`). A publish that owes a build and cannot make it is refused. The directory is ignored by git and ships as a generated directory; the release statement records each binary as `mail-gateway/<uname -m>`, and `verify_release` rebuilds it from the commit and compares the bytes.

**Through a relay.** A deployment whose mail arrives through a relay of its own keeps this server's address hidden, so mail programs connect to the relay's mail host name and the relay passes them through, TLS unopened (relay 3.12, `RelayVersion::PASSES_MAIL_APPS`; the mailbox overview's *The mail apps pass-through*). Programs use 993 and 465 at the relay. The relay dials this server's 993 for IMAP but 10465 for submission, because cloud providers (Linode among them) block a machine's outbound 25, 465 and 587: the gateway serves submission on 10465 too (`-relay-smtp-listen`), and the helper opens 10465 to the relays only, never to everyone. Allow mail apps there:
1. Makes a key and a certificate request on this server (helper `csr SITE NAME`; the key stays here, root only), sends the request to the relay (`RelayClient::mailAppsCertificate`), which obtains the certificate for its own name, and installs the chain (helper `install-cert SITE NAME`, which checks it names NAME and matches the key). Skipped while the installed one has 30 days left.
2. Turns the site on through the relay (helper `on SITE NAME RELAY_IP`): the entry is marked `via_relay`, 993, 465 and 10465 open to the relay's address only, and the relay is listed in `proxy_from`. The gateway believes a PROXY line from a listed address alone, so its limits and the address the site is told name the program; and it serves a `via_relay` site only to a connection that came through the relay, or from this machine, so a scan of this server finds nothing.
3. Pushes the fragment, which then carries this server's IPv4 (`mail_apps_origins`), so the relay starts passing programs through. Disallowing and deactivating push it again, empty.

The relay checks each minute that this server answers on every port it dials onward, and reports it in its ping (`reaches_server`). The admin page shows the relay's view from the report the site keeps (`MailAppGateway::relayReport()`): whether the relay reaches this server for IMAP and for submission, connections passed and refused in the last hour, and the last refusal with its reason. A blocked port shows there before a member meets it.

It is offered when the relay is this deployment's own (not a shared one), runs 3.12 or later, has its name and address, and `mailbox_public_ip` is set; the admin page says which is missing.

**The route is kept.** Mail programs store the name they connect to, so the switch records the route with the name (`mail_apps_route`: `direct` or `relay`, beside `mail_apps_served_name`) and keeps it. The rule (`MailAppGateway::preferredRoute()`): through a relay of this deployment's own, otherwise direct; never directly while a relay hides this server's address, since a direct name would publish it. `MailAppGateway::routeState()` compares the recorded name and route with what the rule picks now:
- **offer**: the rule picks another name or route (a relay of its own was added, or the mail host name changed). The admin page says so, says each member must change the server name in their mail program, and offers **Switch**, which is Allow again by the new route. Nothing changes until it is pressed.
- **exposed**: programs connect directly while mail now arrives through a relay that cannot pass them through (a shared one, or one older than 3.11). There is nothing to switch to; the page warns that this server's address is public.
- **gone**: programs connect through a relay this deployment no longer has. A relay that is only disabled (mailbox's emergency stop) is not gone: its row stays, and programs wait for it as mail does.

The scheduled task **KeepMailAppsReachable** (hourly, `MailAppGateway::keepReachable()`) never changes a working route. It:
- re-points a **gone** route by whatever the rule picks now (Allow again), or turns mail apps off when nothing can carry them, and leaves the admin page a notice (`mail_apps_route_notice`) until it is dismissed;
- follows a relay that moved to a new address (re-provisioned, or moved to another shard): the helper's `status` names the relay addresses it was told, and when the relay's `mrl_public_ip` is not among them, `on` runs again so the firewall and `proxy_from` follow;
- renews the certificate obtained through the relay once it has fewer than 30 days left; the relay issues at most one every 12 hours.

The host installer (`provisioning/install_mail_gateway.sh`) runs as root on every converge of a site with the plugin active, and puts these in place, switched off:
- the binary and the `joinery-mailgw` user;
- the site's entry in `/etc/joinery/mail-gateway/sites/{site}.json` (web name for the loopback upstream, the mail host name programs connect to, certificate files);
- the helper, its sudoers rule, and the unit;
- a certificate-renewal hook that restarts the service.

The service runs unprivileged with `CAP_NET_BIND_SERVICE`. systemd hands it each served site's certificate and key (`LoadCredential`), so it never reads a private key on disk.

**A site in a container** connects mail programs straight to its Docker host, under the site's web name, which must point at the host directly (a name proxied by Cloudflare carries no mail ports, and the switch says so). A container's relay hides nothing, so it is never in the path. The host runs one gateway for all its container sites:
- The support bundle carries the installer and both binaries. The host's machine-mode runner runs the installer `--machine` (its one `HOST_PLUGIN_INSTALLERS` entry), which installs the gateway switched off, with no sudoers rule, and the timer `joinery-mail-gateway-converge.timer`.
- The site's switch writes `storage/mail_apps/request.json` (`{"on": true, "name": ...}`, or `{"on": false}`) in its own storage volume. Every minute the helper's `converge` reads each container site's request, for sites the host has a run spec for, opening each step without following links. It serves the site through the host's front door (`https://127.0.0.1:443`, the web name), with the certificate the host already holds for that name, or turns it off. It writes `answer.json` beside the request: `on`, `off` or `failed` with the reason, which the admin page shows. A failed request is tried again after an hour; an entry whose container is gone is forgotten.
- The program's address reaches the site as on a site machine: the gateway sends it in `X-Forwarded-For`, and the container's Apache trusts the front door's chain.
- The admin page's checks connect to the web name, the host, rather than the container's own loopback.
- Deactivating and uninstalling write the off request.

**Watching it.** The agent's host report carries `mail_gateway`: the gateway's version, its unit's state, systemd's restarts, its open connections, and for each site turned on whether a TLS connection under the site's mail name reaches an IMAP and a submission greeting, and the days left on that certificate. On the node page it is the **Mail apps gateway** row of Services, with **Restart** and **Why?** (its journal). The agent's `mail_gateway` recipe restarts a gateway that a site has on and that is stopped or no longer greets, and opens a case when restarting does not help. The management node raises `plane:mail_gateway` when a site has mail apps on and the gateway is not running, does not greet, keeps restarting, or a certificate is under 14 days. systemd restarts a gateway that exits, five seconds later.

**Deactivating** the plugin turns the site off (helper `off`). **Uninstalling** it runs `uninstall.php`, which calls the helper's `remove`: the site's entry is forgotten, and once no site on the machine has the plugin the unit, the binary, the `joinery-mailgw` user, the renewal hook, the sudoers rule and the helper itself are removed and the ports closed. The mail host's certificate is kept: that name is the mailbox plugin's too. A removal that fails keeps the plugin installed, so the uninstall can be run again.

The admin page shows:
- whether the gateway answers on both ports;
- whether its certificate covers the site's name;
- on request, a round trip: sign in with a throwaway app password, open INBOX, sign out, revoke.

## Tests

| Suite | Covers |
|---|---|
| `tests/unit/app_access_test.php` | purposes, purpose confinement both ways, the password's shape, the client address |
| `tests/unit/app_access_kind_blind_test.php` | only core sign-in names the app-password kind |
| `tests/vault/vault_borrowed_unlock_test.php` | arming, activity, every way a borrowed window ends |
| `plugins/mail_apps/tests/mail_apps_folders_test.php` | folder list, numbers only rise, changes found, what actions do |
| `plugins/mail_apps/tests/mail_apps_rebuild_test.php` | byte-identical rebuilds across processes over a corpus of shapes, size told = size served |
| `plugins/mail_apps/tests/mail_apps_private_test.php` | locked behaviour and the lock notice through a borrowed window |
| `plugins/mail_apps/tests/mail_apps_send_test.php` | submission rules, the Sent copy and its dedupe, drafts |
| `plugins/mail_apps/tests/mail_gateway_publish_test.php` | the publisher and the installer agree on the binary names; publish, the statement and the verifier reach it |
| `plugins/mail_apps/tests/mail_gateway_helper_gate.sh` | the helper through a relay (csr, install-cert refusing another name or key, `on` with relay addresses, the firewall and `proxy_from`, off), its `remove`, and `converge` on a fake Docker root (on, a pass that changes nothing, another name refused, off, planted links never followed, a failure waits, a gone container forgotten) |
| `plugins/mail_apps/tests/mail_apps_route_test.php` | the kept route: offers, the public-address warning, following a moved relay, re-pointing a gone one, a disabled relay left alone; a site in a container asking its host |
| `plugins/mail_apps/tests/mail_apps_relay_test.php` | through a relay: the version gate, the relay's name, what `cannotTurnOn()` names, the fragment's `mail_apps_origins` |
| `provisioning/mail-gateway` `proxy_test.go` | PROXY lines believed from `proxy_from` only, a `via_relay` site refused to anyone else |
| `plugins/server_manager/tests/incident_sources_test.php` | `plane:mail_gateway`'s conditions and severities |
| `provisioning/mail-gateway` (`go test`) | the gateway's IMAP and SMTP sessions against a fake site |

**Where it is not offered yet:**
- A deployment on a shared relay (a hosted fleet slot).
- A site container.
- A site with no mail host name set.

The admin page says which applies.
