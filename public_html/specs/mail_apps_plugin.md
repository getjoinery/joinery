# Mail Apps Plugin — Thunderbird and Other IMAP Clients

**Purpose:** Let a person read and send their Joinery mail from a desktop mail program — Thunderbird first, and any client that speaks standard IMAP and SMTP submission (Apple Mail, K-9/Thunderbird for Android, Outlook's IMAP mode). Joinery mail today is reachable only through the web reader and the native app's JSON API.

**Last Updated:** 2026-10-13

**Status:** Released in 0.8.484 (J1–J4 in 0.8.485). Phase 0 measured Thunderbird 157.0.1 (§ Phase 0 findings), and the findings are folded into the rules below. Phases 1–4 are built (§ Build status). The gateway's life cycle (§ Running the gateway: A1 build at publish, A2 watching and self-repair, A3 removal), the J3 re-fix (APPENDUID always) and the relay pass-through (§ Where programs connect) are released in 0.8.486; their live checks are in the live verification queue. The pass-through's live test (mailapps-walk.joinerytest.com with its own 3.11 relay) found that gateway 1.0.0 listened on IPv6 only (1.0.1 listens on IPv4 and IPv6). Resumed on 0.8.488 (2026-10-13): IMAP through the relay passes from outside and the server's own 993 refuses strangers, but submission was cut off, because the relay dialed the server's 465 onward and cloud providers block outbound 465 (B1, § Where programs connect, *Onward ports*). B1 is fixed on dev (relay 3.12, gateway 1.0.2, helper 1.7, the relay's view on the admin page); the test resumes on the release carrying it. A4 build steps 1–4 are built and tested on dev (2026-10-13): the kept-route rule for single-site servers (§ Which route a deployment uses), the helper's converge verb, the gateway on Docker hosts, and the container admin page; step 5 ran live on 0.8.488 (2026-10-13, a container site on a test Docker host): the host gets the gateway from the bundle switched off, Allow is served within a minute, programs sign in from outside, Stop and deactivating close the ports; a second site on one host and Thunderbird itself are in the live verification queue. Not built: a separate mail name for container sites behind Cloudflare (step 6), the pass-through on shared hosted relays (step 7), and the shared certificate automation.

**Plugin name:** `mail_apps` — depends on `mailbox`.

**Also touches:**
- **core** — app passwords and the borrowed vault unlock, both built generally (§ Core: app access, § Core: the borrowed unlock);
- **mailbox** — three public entry points, plus one small general extension: drafts accept parts given as bytes (§ What mailbox exposes).

---

## Decisions already made (owner, 2026-10-11)

1. **Private mailboxes are included.** A mail app does not get its own way to unlock the vault. It **borrows the website unlock**: when the person unlocks on the website with their passkey, their mail apps can read too; when that unlock ends, the apps stop getting mail.
2. **A mail app's checks count as activity.** Thunderbird checking for mail keeps the unlock alive, up to the existing hard limit — **7 days** for Private, **24 hours** on a hardened domain (one with *Seal at the relay* or *Only send while I'm signed in*). After that the person unlocks on the website again. This is a deliberate, bounded exception to the rule in `VaultUnlock` that machine-driven presence never keeps a key in memory ("presence is cheap to fake for a resident attacker and must never be what keeps a key in RAM"). The absolute cap is what still bounds it. The person sees the cost in plain words before turning a mail app on (§ What the person is told).
3. **Its own plugin, plus a general piece in core.**
   - Mail-app access is a plugin that depends on `mailbox`. `mailbox` does not know it exists: no mailbox code calls into it, and no mailbox write path changes for it.
   - App passwords and the borrowed unlock belong to sign-in and the vault, so they are core. They are built for any future purpose (calendar or contacts sync, Drive over WebDAV), but only the `mail` purpose is built now.
4. **App passwords for v1; browser sign-in stays open for later.** Signing in through a browser with a passkey (OAuth) is not built now. Nothing in v1 may close it off: the rules attach to the *purpose* and the *credential id*, never to "an app password" specifically (§ Core: app access, § Keeping browser sign-in open).

---

## Phase 0 findings (Thunderbird 157.0.1, measured 2026-10-11)

The rig is in `plugins/mail_apps/tests/thunderbird_rig/`: real Thunderbird driven headless through its own UI code paths against a go-imap v2 server. Logs and screenshots are in `~/mail-apps-rig/`. What changed the rules (each rule below already reads as changed):

| | Finding | Rule now |
|---|---|---|
| F1 | CONDSTORE is off by default. Every SELECT is `UID FETCH 1:* (FLAGS)`. While selected, Thunderbird learns changes only from unsolicited `FETCH`/`EXPUNGE`/`EXISTS`. QRESYNC is not implemented at all. go-imap v2 has no CONDSTORE either | v1 does not advertise CONDSTORE. Change comparison still drives the pushed FETCH/EXPUNGE on IDLE and NOOP, and the full-folder listing is one query |
| F2 | Up to 5 connections per account: one unselected, up to four idling on whatever folders they last used | The poll is shared per (credential, mailbox, folder); plan about 4 idling folders per Thunderbird |
| F3 | A 454 at the end of DATA shows a modal with the server's text and leaves the compose window open. Nothing goes to the Outbox, and there is no retry. A refusal at MAIL FROM is misreported as a wrong address | Refuse at the end of DATA (the gateway does); rule 6's wording fixed |
| F4 | Thunderbird mangles non-ASCII in NO text, and the link in it is not clickable | Response text the gateway composes is ASCII |
| F5 | A refused body fetch gives two modal alerts on click, and silent retries in the background. Recovery after unlock is clean. Sometimes Thunderbird still STOREs `\Seen` | The gateway drops `\Seen` for a message it just refused, so an unread locked message stays unread |
| F6 | Archive defaults to one folder per year (`CREATE "Archive/2026"`) | Fixed folders are `\Noinferiors`, so Thunderbird archives flat into Archive |
| F7 | The Junk button only stores `$Junk`/`$NotJunk`, and only when both are listed in FLAGS | Both are listed in FLAGS and PERMANENTFLAGS, and STORE maps them to `setSpamVerdict()` |
| F8 | Delete in Trash stores `\Deleted` and does not EXPUNGE until 20+ are pending, a compact, or Empty Trash | Open decision D1 |
| F9 | Each draft save APPENDs, then `UID EXPUNGE`s the previous version | Drafts EXPUNGE → `deleteDraft()` |
| F10 | The Sent APPEND carries a `Bcc:` header the submission did not. Thunderbird never refetches its own appended copy | The stored Sent copy writes the envelope's Bcc in |
| F11 | A forward carries `X-Forwarded-Message-Id` and `In-Reply-To` | Forwarded is checked first, so a forward is never marked answered |
| F12 | Autoconfig: https and http for both URLs, with `?emailaddress=`; then an MX-based lookup; no RFC 6186 SRV. Exchange autodiscover runs alongside | Open decision D2 (MX base domain). Our 404 for `/autodiscover/` is never a 401 |
| F13 | Offline sync fetches several whole messages per command (`UID FETCH 1:5 (… BODY.PEEK[])`). Only displaying an undownloaded message is chunked, without PEEK | `mail_fetch` batches to a 12 MB budget. A non-PEEK fetch sets `\Seen` |
| F14 | Thunderbird never sends CAPABILITY, uses two-step AUTHENTICATE PLAIN, needs special-use attributes in plain LIST, hides folders missing from LSUB, and APPENDs in the `UTF8 (~{n+}` form | The gateway does all of these |
| F15 | go-imap v2 beta.8 lacks CONDSTORE, QRESYNC, ID and COMPRESS | v1 ships without them. ID and COMPRESS are not advertised, so Thunderbird does not send them |

## Build status (2026-10-11)

**Built and tested on dev:**
- **Core:** `AppAccess`, the `app_password` key type, `apk_purpose`, `apk_last_used_ip` (`inet`, now a declarable column type), `ApiAuth` purpose confinement and app sign-in limits, the borrowed unlock in `VaultUnlock`, the lock-chip line, and the App Sessions listing.
- **Mailbox:** `viewScopeSql()` (the reader's list now builds from the same fragments), `sendRaw()`, `storeRawOutbound()`, and drafts that take parts as bytes.
- **The plugin:** the 15 `mail_*` actions, numbering and change comparison, the rebuild, the Mail apps page, the admin page, autoconfig, and the host installer with its root helper.
- **The Go gateway.**

The tests listed in § Tests pass, including a live run: the real gateway on dev, IMAP and SMTP against a real mailbox.

**Not built:**
- Container sites (A4, § Running the gateway), and the kept route (§ Which route a deployment uses).
- The shared front-door certificate automation for `autoconfig.` names.
- Re-running the rig against the built gateway on the Mac mini and the Windows VM.

**Implementation choices that differ from the first draft:**
- Action names carry a `mail_` prefix (`mail_apps/mail_sync`, …), because logic functions are global PHP names and `login_logic` already exists in core.
- The gateway never splits the password. It relays what the program typed in `X-Joinery-App-Secret`, with `X-Joinery-App-Username`, and `ApiAuth` resolves it.
- The program's address travels in `X-Joinery-Client-Ip`, believed only from loopback. This needs no change to the web server's proxy trust.
- `sendRaw()` carries the program's message over part for part into an `EmailMessage` and sends it through the ordinary pipeline; it does not replay the bytes. Every transport, test mode and Joinery Direct then works unchanged. A message signed or encrypted as a whole (PGP/MIME, S/MIME) is refused, since re-signing would break it.
- The Sent copy is the program's own message, stored through mailbox's archive-import store.
- EXPUNGE in a label folder takes the label off and does not trash the message. RENAME is refused, because labels cannot be renamed.
- A message's size is fixed at its first header fetch, not at numbering. A rebuilt message's size is computed without reading attachments.
- The largest message a program can submit or APPEND is about 23 MB: the base64 JSON body must fit under PHP's 32 MB `post_max_size`.

## Where programs connect (B1, owner 2026-10-11)

**Problem found after the build.** The gateway answered on the site's web name, and web names are often behind Cloudflare (dev is). Cloudflare carries web ports only, so 993 and 465 never reach the server. A mail program needs a name that reaches the server, and on a relay-fronted deployment no public name may point at the server (the hidden origin).

**Decided (owner):** programs connect to the deployment's **mail host name**, and relay-fronted deployments get a **pass-through on the relay**:
- **Encryption ends at the main server.** The relay forwards encrypted bytes, choosing the deployment by the TLS name the program asked for. It never sees an app password or a message.
- **The relay connects to the server.** The server opens 993/465 at its firewall to the relay's address alone.

**Built (direct receiving):**
- `MailAppGateway::servedName()` is `mailbox_mail_hostname` (while mail apps are on, the name recorded at switch-on).
- The helper's `on SITE MAILHOST` issues that name's certificate with the system's certbot, only when the name points at this machine (review S1, S3, S4).
- The site entry carries `mail_hostname`, and the gateway selects the site by it while still calling the site under its web name.
- Allow mail apps is refused, saying why, where no mail host name is set.

**Built (relay-fronted), 2026-10-12, uncommitted:** the pass-through below, end to end: relay 3.11 (`mailapps_pass.go`, `mailapps_cert.go`, 993/465 open), the gateway's PROXY handling and `via_relay` (`proxy.go`), the helper's `csr` / `install-cert` / `on SITE NAME RELAY_IPS` with the firewall and `proxy_from` kept in step, `MailAppGateway::turnOn()` through the relay, the fragment's `mail_apps_origins`, and the daily renewal. Tested: Go tests on both sides, the helper gate, PHP against the real relay binary, and a local chain on dev (a TLS client through relay-serve to the gateway greeting on IMAP and SMTP; a wrong name cut off). Not yet tested live: a real relay upgraded to 3.11, the Let's Encrypt order, Thunderbird through it (live verification queue).

**Design:**

| | Direct receiving (no relay) | Relay-fronted |
|---|---|---|
| Name programs use | `mailbox_mail_hostname` (the MX target, already public and pointing at the server) | the relay's mail host name (`mrl_mx_hostname`), which points at the relay |
| Where TLS ends | the gateway on the server | the gateway on the server; the relay splices bytes, routing by SNI |
| Certificate for that name | issued on the server: the root helper serves the ACME HTTP-01 challenge for the mail host name through Apache (a small vhost it writes) and runs certbot `certonly --webroot` | **issued through the relay for the server's key**: the gateway makes a key and a CSR; the plane sends the CSR over the signed relay API; the relay completes the order with its own ACME account (TLS-ALPN-01 on its 443, as it already does for itself) and returns the certificate. The private key never leaves the server. Renewed the same way |
| Server firewall | 993/465 open to all | 993, 465 and 10465 open to the relay's address only (IPv4 and IPv6) |
| What the relay is told | — | the server's address and the names to route, as part of the operator-signed tenant routes |

### Which route a deployment uses (owner, 2026-10-13)

**The rule: a relay passes mail programs through only where it hides the server's address.**
- *A server with a site of its own,* whose mail arrives through a relay of its own (one tenant, version 3.12 or later): through the relay.
- *A server with a site of its own and no relay* (direct receiving): direct, under `mailbox_mail_hostname`.
- *A server with a site of its own on a shared hosted relay:* through the relay, under its slot's mail name (step 7, § Shared hosted relays). Until step 7 is built: refused, saying why, because a direct name would publish the address the relay hides.
- *A site in a container:* always direct to its Docker host (A4). A container's relay hides nothing: the host's address is already public in its neighbours' DNS, which is why mailbox drops the hidden-origin rules for containers too (plugin docs, "A container's relay hides nothing"). Routing it through the relay would add a host-to-relay trust link to buy two conveniences (no extra DNS row, moves invisible to programs).

**The route is kept, not followed (owner, 2026-10-13, option 2).** Mail programs store the name they connect to, so changing it silently breaks every member's setup.
- Allow mail apps records the route (`mail_apps_route`: `direct` or `relay`) beside the name (`mail_apps_served_name`).
- When the rule later prefers the other route (a relay of its own is added), the admin page says so: which name programs use now, which they would use, that each member must change the server name in their mail program, and that until the switch the direct name keeps this server's address public (mailbox's hidden-origin check fails on it too). A **Switch** button turns mail apps on by the new route and records it. Nothing changes until the admin presses it.
- Where the recorded route can no longer work, it changes by itself, through the hourly task `KeepMailAppsReachable` (it keeps mail apps reachable: the certificate, the route and the relay's address), and the admin page says what happened and that members must change the server name:
  - *Relay route, and the relay it was turned on with is gone* (deleted, or the deployment moved between its own relay and a hosted slot): mail apps are turned on by whatever the rule now picks (the new relay's name, or direct when mail arrives directly again). If that cannot be done, mail apps are turned off and the page says why.
- *Relay route, and the relay's address changed* (re-provisioned, or re-sharded): the server's firewall and the gateway's trusted-relay list follow it within the hour, so programs keep working (reviewer2 A3, 2026-10-13).
- *Relay route, and the relay is only disabled* (mailbox's emergency stop): not gone. Its row stays, programs wait for it as mail does, and nothing goes direct, which would publish the address it hides.
- One predicate decides all of it: the name the rule picks now against the recorded name and route. The existing name-drift notice (`nameDrift()`) is that predicate for a changed mail host name, and is folded into it.

**Relay side** (`relay-sealer`, `relay-serve` mode): two new listeners, 993 and 465.
- Each reads the TLS ClientHello's SNI, looks the name up in the tenant routes, and splices the TCP stream to that tenant's server: IMAP to its 993, submission to its 10465 (see *Onward ports* below). It never terminates TLS.
- An unknown name is closed.
- Connection caps apply per source and per tenant.
- Health reports the pass-through's state.

**Build design (W2, 2026-10-12)**, after reading the relay as it is. Three things the sketch above assumed are not so: the relay never dials the server (no tunnel since provision_relay.sh 3.0; the server pulls), a self-hosted relay has no operator key (only the tenant `main` key), and a relay is never updated in place (Upgrade re-images it from the support bundle). So:
- **Where it runs: a relay with one tenant.** A self-hosted relay serves one deployment, and its mail host name (`mrl_mx_hostname`) is the relay's own `--hostname`, the name it already holds an ACME certificate for on 443. The pass-through serves exactly that name, on a relay whose routing holds exactly one tenant. A shared shard does nothing (fleet slots are out of scope, above), which also means no tenant can ever have the relay issue a certificate for a name another tenant uses.
- **The server's address reaches the relay in the tenant's fragment** (`mail_apps_origins`: up to four public IPv4/IPv6 addresses), signed with the tenant key like the rest of its routing, merged into `routing.json`. An empty list turns the pass-through off. `RelayMapSync` puts `mailbox_public_ip` and the detected IPv6 there while mail apps are on.
- **Splice:** relay-serve peeks the ClientHello (SNI, offered ALPN) without consuming it, dials the origin (IPv4 first, then IPv6) on 993, or 10465 for submission, writes a PROXY protocol v1 line carrying the program's address, replays the hello and copies both ways. Caps: 20 connections per source address (IPv6 by /64), 1,000 in all, 10 s to read the hello, 10 s to dial.
- **The program's address survives.** The gateway accepts a PROXY line only from addresses listed in `/etc/joinery/mail-gateway/proxy_from` (the relay's), so its per-address caps, its sign-in throttle and the `X-Joinery-Client-Ip` it sends the site name the program, not the relay.
- **The origin stays hidden.** A site entry marked `via_relay` is served only to a connection that came through a listed relay (or loopback, for the host report and the admin page's round trip): anyone else reaching the server's 993 directly gets no handshake, so a scan cannot find a gateway holding the relay's certificate. The server's firewall opens 993, 465 and 10465 to the relay's addresses only.
- **Onward ports (B1, found live 2026-10-13).** Cloud providers block a machine's outbound 25, 465 and 587 (Linode does on our accounts), so a relay dialing the server's 465 never connects: IMAP worked through the relay and submission was cut off at the handshake. Programs still use 465 at the relay; the relay dials the server's 10465 (`mailAppsSubmissionTarget`), where the gateway serves the same submission server (`-relay-smtp-listen`, gateway 1.0.2), opened by the helper (1.7) to the relays only, never to everyone. Relay 3.12.
- **Certificate:** the root helper makes the key and a CSR (`csr SITE NAME`; the key never leaves the server); the site sends the CSR to `POST /relay/mail-apps/certificate` with its tenant signature; the relay checks the CSR names only its own hostname, completes an ACME order with its own Let's Encrypt account (TLS-ALPN-01: its 443 answers the `acme-tls/1` hello from the order's challenge certificate before autocert's), and returns the chain; the helper installs it (`install-cert SITE`, which checks it matches the key). At most one issue per tenant per 12 hours, because the relay's own certificate shares Let's Encrypt's duplicate-certificate limit for that name. Renewed by a daily task inside 30 days of expiry.
- **Version gate:** relay 3.12 (`RelayVersion::PASSES_MAIL_APPS`; 3.11 dialed 465 onward). An older relay is named in the refusal, with Upgrade as the way forward.
- **Health:** the relay's ping carries `mail_apps`: origins set, connections spliced and refused in the last hour, the last refusal and its reason, the last certificate issued and the last error, and `reaches_server`: whether the server answered on each onward port at the relay's check each minute. The Mail apps admin page shows the relay's view from the report the site already keeps (`MailAppGateway::relayReport()`), so a blocked onward port shows there before a member meets it.
- **Decided while building:**
  - The relay reaches the server over **IPv4 only**: the deployment records the relay's IPv4 (`mrl_public_ip`) and not its IPv6, and the server can trust only an address it knows. Programs still reach the relay over IPv6, and their IPv6 address reaches the gateway in the PROXY line.
  - "One tenant" is counted from the relay's **registrations** (`tenants/`), not the merged map, which lists only tenants that have pushed: a shard whose second tenant has not pushed yet is still shared.

**Hosted fleet slots** pass nothing through until step 7 (§ Shared hosted relays). A container site on one connects directly to its host either way (§ Which route a deployment uses).

**Thunderbird settings** (the Mail apps page and autoconfig) name whichever host above applies.

**Not affected:** `checkOriginHidden`. Direct deployments publish nothing new. Relay-fronted ones point programs at the relay, not the server.

## Running the gateway: build, install, watch, remove (owner 2026-10-12)

The owner asked for the whole life cycle of the gateway program to be automatic: built into every release, installed and kept current, watched, restarted or escalated when it fails, and removed with the plugin. Four pieces.

**A1 — built at publish. BUILT.** `plugins/mail_apps/provisioning/bin/` was git-ignored build output that no publish step made, so 0.8.484 and 0.8.485 shipped mail_apps without its binaries and the host installer stopped at "no prebuilt gateway" everywhere but dev.
- `MailGatewayPublisher` (a `GoBinaryPublisher`) cross-compiles the gateway with the toolchain `go.mod` pins, stamps the source (`.source_sha256`), and leaves `bin/` byte-identical when nothing changed. A build owed and not made refuses the release.
- The relay sealer was hard-coded by name in seven places. Both are now on one list, `GoBinaryPublisher::PLUGIN_PUBLISHERS`, which publish, `ReleaseStatementPublisher::binaryArtifacts()` and `ReleaseVerifier` (manifest entries, rebuild) each walk. `ReleaseCommit` holds the gateway's source and generates its `bin/`.
- A release that records none of a program's binaries shipped none, so older releases (0.8.484, 0.8.485) still verify.
- Verified on dev: the publisher built both binaries, skipped on a second run, and an independent rebuild with the verifier's flags is byte-identical; `verify_release` passes on 0.8.485.

**A2 — watched, restarted, escalated. BUILT; agent change awaits its version (below).**
- *Status:* `host_report.sh` 1.20 carries `mail_gateway`: version, unit state, systemd's `NRestarts`, uptime, open connections, and per site turned on whether a TLS connection under its mail name reaches an IMAP and a submission greeting, and the certificate's days left. A process that runs and no longer answers fails the greeting. This replaces the separate `mail_gateway_status` word the spec first named: the host report is already run hourly by the plane and every ten minutes by the agent's recipes, and a new script key needs no new agent word.
- *Alarm:* `plane:mail_gateway` (`IncidentSourceMailGateway`). Quiet where no site has mail apps on, and where the unit has failed outright (`plane:failed_units` names it: one fault, one incident). Critical when a site's programs cannot connect or a certificate is under seven days; a warning for three or more restarts within the hour, or a certificate under 14 days.
- *Self-repair:* agent recipe `mail_gateway`: while a site has mail apps on, the gateway runs and greets, or `restart_unit joinery-mail-gateway`; a case after the recipe's attempts. systemd's `Restart=on-failure` already covers a gateway that exits.
- *By hand:* `joinery-mail-gateway` joins `restart_unit` and `unit_journal` / `reset_failed_unit` (agent, scripts and plane mirrors). The node page's Services gains a Mail apps gateway row with Restart and Why?.
- The agent part needs a release of the agent: `one-data-root` holds an uncommitted 1.73.1 in `main.go`. Whichever agent commit lands second bumps to 1.74.0 (agreed with that session); until then an older agent refuses the new unit name in `restart_unit` with a plain refusal.

**A3 — removed with the plugin. BUILT.** There is no platform hook for undoing a host installer, and `uninstall.php` runs as the web user. So the root helper gains `remove SITE`, and the plugin gains `uninstall.php`, which calls it the way the deactivate hook calls `off`. It forgets the site, and once no site on the machine has the plugin it removes the unit and drop-in, the binary, the `joinery-mailgw` user, the renewal hook, the sudoers rule and the helper, and closes the ports. The mail host's certificate stays: the name is the mailbox plugin's too. A failure returns false, which keeps the plugin installed so the uninstall can be retried.

**A4 — container sites. Steps 1–4 BUILT, 5–7 not. Design decided (owner, 2026-10-13), simplified after review (reviewer2, 2026-10-13).**

A container site's programs connect **directly to its Docker host**, never through a relay (§ Which route a deployment uses). The host runs one gateway for all its container sites, each served under its own mail name. **The host reads each site's request itself**: no management-node channel, no new agent word. The management node sees the outcome through what already exists (`host_report`'s `mail_gateway` section, `plane:mail_gateway`).

**How it works.**
1. **The site asks.** Allow mail apps on a container site writes `storage/mail_apps/request.json`: `{"on": true|false, "name": "<mail name>"}`. `storage/` is the site's own volume (`<site>_storage`); the site root itself is root-owned, and a new top-level folder would die with the container.
2. **The host acts.** `install_mail_gateway.sh --machine` runs from the support bundle as the runner's one `HOST_PLUGIN_INSTALLERS` entry (`_plugin_installers_start.sh` 2.33), on a new bundle and daily. On a Docker host it installs the gateway switched off (binary, unit, user, helper, renewal hook; no sudoers rule, since only root calls the helper there) and the timer `joinery-mail-gateway-converge.timer`, which runs the helper's `converge` every minute. The machine-mode runner alone would act on a request only when the next bundle or day came round.
   - `converge` finds Docker's root (`docker info -f '{{.DockerRootDir}}'`, as the agent's `victim.go` does) and reads `<root>/volumes/<site>_storage/_data/mail_apps/request.json` for every site that has a run spec (`/etc/joinery/sites/<site>/run_spec`: only the host's own sites count, and the site name comes from the host's record, never from the file).
   - The folder belongs to the container, so every read and write opens each step without following links (python3, `O_NOFOLLOW` with directory descriptors): a link, a FIFO or a large file the container plants is never followed, and nothing is written outside its folder.
   - A site asking for on, under its front door's name, is given a container entry (`container: true`) and turned on with the helper's own `on`; a site no longer asking is turned off; an entry whose container is gone is forgotten. A request that fails is tried again after an hour, not every minute. A pass that finds everything as asked changes nothing.
   - The gateway stays installed, switched off, on a Docker host with no site asking: it is part of the host's tooling, like the relay pieces the bundle carries.
3. **The host answers.** Beside the request it writes `storage/mail_apps/answer.json`: `{"state": "on"|"off"|"failed", "name", "cert_expires", "error", "time"}`. The site's admin page reads it.
4. **Trust.** Whoever can write the site's storage volume can ask: the site itself, or root. That is the same boundary the agent's signed claim draws. The request is data, never a command: `on` is a boolean, and the name must be the site's own web name (below), checked against the host's own vhost for the site.

**How the gateway reaches the site: through the host's front door.** The entry's upstream is `https://127.0.0.1:443` with the site's web name, exactly as on bare metal. The front door's vhost for that name proxies into the container (`default_proxy_vhost.conf`). The gateway already sends `X-Forwarded-For` with the program's address, the front door appends its own, and the container's Apache trusts the chain from the Docker bridge and 127.0.0.1 (`host_housekeeping.sh` remoteip), so `ApiAuth::appClientIp()` sees the program's address with no change. A suspended site's front door answers 503, so its mail programs stop with its website.

**The mail name.** First version: the site's web name, which must point straight at the host (not through Cloudflare, which carries no 993/465). The host already holds that name's certificate, so `set_mail_host` reuses the lineage and no certificate is ordered. The admin page says so, and says why when the web name is proxied. A separate mail name (for a site behind Cloudflare) is a later addition: it needs a permanent port-80 vhost for the name that serves only `/.well-known/acme-challenge/`, sorted after `000-joinery-no-site.conf`, and certbot `--webroot` against it (renewal reuses the recorded webroot).

**What changes where.**
| Piece | Change |
|---|---|
| Support bundle (`SupportBundlePublisher`) | Carries `mail-gateway-x86_64`, `-aarch64`, `.source_sha256` and `install_mail_gateway.sh` |
| `install_mail_gateway.sh` | A `--machine` mode (above); the helper's three `php -r` JSON calls become `python3 -c` (every host with certbot has python3) |
| Helper | `converge` verb, run by root's timer on a Docker host; `refresh` without a site; on a site machine nothing else changes |
| `_plugin_installers_start.sh` | `HOST_PLUGIN_INSTALLERS`: the gateway's installer, run `--machine ROOT` after the host installers |
| `MailAppGateway` | In a container: `mailHostname()` is the web name (checked before any relay), Allow writes the request, status reads the answer, and `listening()` / `certificate()` / `roundTrip()` dial the mail name instead of 127.0.0.1 |
| Watching | Nothing new: `host_report` already reports each site in `/etc/joinery/mail-gateway/sites/`, and the recipe and alarm run on the host's node |

**Moves.** A site copied to another host carries its storage, so the new host reads the same request and turns it on. The old host forgets its entry on the first pass after the old container's run spec is gone.

**Build order.**
1. *Kept route and relay address* on single-site servers (§ Which route a deployment uses): `mail_apps_route`, the Switch notice, the automatic changes, and following a relay's new address (reviewer2 A3). **BUILT 2026-10-13, uncommitted:** `MailAppGateway` 1.4.0 (`preferredRoute()`, `recordedRoute()`, `routeState()` with offer / exposed / gone, `allow()` / `disallow()`, `relayAddressMoved()`, `keepReachable()`), the hourly task `KeepMailAppsReachable` (replaces `RenewMailAppsCertificate`, retired on sync), settings `mail_apps_route` and `mail_apps_route_notice`, admin page 1.3.0 (Switch, the public-address warning, the change notice), test `mail_apps_route` 31/31. A relay that is only disabled is not gone (going direct would publish the address it hides).
2. *Helper without PHP*, and its `converge` verb. **BUILT 2026-10-13, uncommitted** (`install_mail_gateway.sh` 1.6; helper gate 60/60, with converge on a fake Docker root: on, idempotent pass, another name refused, off, planted links never followed, failure waits an hour, gone container forgotten, other entries left).
3. *Gateway on a Docker host:* the bundle entries, `--machine` mode, the converge timer. **BUILT 2026-10-13, uncommitted** (`SupportBundlePublisher` 1.19, runner 2.33, `installer_contract` pins).
4. *The container admin page.* **BUILT 2026-10-13, uncommitted** (`MailAppGateway` 1.5.0, admin page 1.4.0, deactivate 1.3.0, uninstall 1.1.0; `mail_apps_route` 42/42 with the container stood in for).
5. *Live:* a container site on a test Docker host, Allow, Thunderbird; a second site on the same host; then off, and uninstall.
6. *A separate mail name for a container site* (the challenge vhost).
7. *Shared hosted relays pass mail programs through* (§ Shared hosted relays).

**Tests.** The helper gate with a fake Docker root: a request turns a site on, a withdrawn or missing request turns it off, a request from a volume with no run spec is ignored, a name other than the site's vhost name is refused, the last site off removes the gateway, the answer file is written each time. The kept-route rules (each automatic change, the Switch notice, nothing changes without the button). Following a relay's new address.

**Shared hosted relays (step 7). NOT BUILT. Decided (owner, 2026-10-13).** A server with a site of its own on a hosted fleet slot gets the same pass-through as one with a relay of its own, so its address stays hidden and its members can still use mail programs. Each slot has its own mail name, `<slug>.<mailbox_fleet_mx_zone>` (slug `t<id>`), an operator-controlled A record pointing at the shard. Today that name lives only on the plane's slot row (`mft_mx_hostname`) and the tenant's `mrl_mx_hostname`; the shard does not hold it (reviewer2, 2026-10-13).
- *The shard learns each slot's name:* the operator-signed tenant route gains `mx_hostname` (`relay_serve.go` route body, `registerTenant`'s root-owned files in `relay_apply.go`), sent by `FleetService::applyTenant`. The pass-through's name-to-tenant map is built from it.
- *Routing:* the shard splices by the name the program asked for (SNI) to the tenant whose slot name it is, at that tenant's `mail_apps_origins` (tenant-signed, as today). An unknown name, or a slot with no origins, is closed.
- *Certificates, the one rule that must hold:* the shard issues a certificate only for the slot name the operator assigned to the tenant whose signature is on the request. A CSR naming anything else, or a tenant with no assigned name, is refused. The check reads the operator-signed `mx_hostname`, never anything the tenant sent. One issue per tenant per 12 hours, recorded per tenant. TLS-ALPN-01 for a slot name already works: the challenge certificate is answered before autocert for any name.
- *Code that assumes one tenant today:* the SNI match against the relay's own hostname (`mailapps_pass.go`), `soleTenant()`, the CSR check and ACME order against the relay's hostname and the single 12-hour record (`mailapps_cert.go`), and the relay-wide `mail_apps` group in the ping, which on a shard is reported per calling tenant (`relay_status.go`).
- *Caps per tenant* as well as per source and in total. The per-tenant cap is applied after the ClientHello is read, since the name is not known before.
- *What the tenant sees:* programs connect to the slot name. Re-sharding moves the slot name's A record and the shard's address changes; the daily task follows the new address (build step 1), and the certificate lives on the tenant's server, so programs keep working. A shard re-image drops every tenant's program connections once; programs reconnect.
- *Let's Encrypt:* every slot name is under one registered domain (the fleet zone), and new certificates for a registered domain are limited per week. Renewals of an unchanged name do not count against it. A fleet that adds more slots a week than the limit allows needs the limit raised with Let's Encrypt.
- *Tests:* a CSR for another tenant's slot name is refused; a CSR for the shard's own name is refused; a tenant with no assigned name is refused; routing by slot name reaches the right origin and an unknown name is closed; per-tenant caps; per-tenant 12-hour records.
- `cannotTurnOn()`'s shared-relay refusal is removed in the same change, and the version gate names the relay version that carries it.

## Second code review (reviewer2, 2026-10-11)

This review covered everything changed after the first one.

**Fixed:**

| | Finding | Fix |
|---|---|---|
| S1 blocker | Allow mail apps could not issue the mail host name's certificate. `setup_ssl.sh` only uses HTTP-01 for names Apache already serves | The helper issues it itself with the system's certbot. It uses the Apache method, which adds the challenge to the port-80 sites, and falls back to the webroot of Apache's default port-80 site. It only does so for a name whose A/AAAA records include one of this machine's own addresses; otherwise it says so. |
| S2 blocker | A Bcc-only send (`To: undisclosed-recipients:;`) put every recipient in To | To is the mailbox itself, and every recipient stays Bcc. Tested. |
| S3 | The root helper ran `setup_ssl.sh` and `install.sh` from the site tree with no trust check | No script from the tree runs as root in the helper any more. |
| S4 | No reach gate or rate bound on certificate orders | Ordered only for a name that points at this machine, at most once an hour per site. |
| S5 | Changing the mail host name after switching on left the pages advertising a name the gateway did not serve | While on, the served name is the one recorded at switch-on (`mail_apps_served_name`). The admin page says when the setting has moved. |
| S7 | `on` restarted the gateway for every site | It restarts only when the handed-over credentials change; otherwise it reloads. |
| S8 | Allow mail apps also rendered on the Plugin Settings page, without its side effects | Both settings are `managed`. |
| S10 | `NOT IN` is hashed only up to about 260,000 numbers per folder | Back to the anti-join (NOT EXISTS). The ANALYZE after large changes is what fixed the stale-statistics plan. |
| S11 | A hard delete mid-reconcile could null the flags | `COALESCE(…, 0)`. |
| S12 | A failure after the message had left answered "try again" | The bookkeeping after the send is caught and logged. |
| S14 | An envelope recipient that failed validation was silently dropped | Refused 554, naming it. |
| S16 | Permanent refusals answered 451 | 554. |
| S19–S24 | Gateway: held bytes, poll-cache memory, refusal map, a panic in the shared poll, trailing full stops on sign-in replies, `\Noinferiors` with a nested label, and IDLE DONE waiting on a check | Fixed in the gateway. |
| S25 | The health check accepted any certificate | It verifies the chain and the name, as a mail program does. |
| S27 | The test-only window reset worked in a web request | It throws outside the command line. |

**S13** (mailbox arrival): `InboundEmailRouter::extractBodies()` and `enumerateNonTextParts()` no longer take a part inside an attached message (`message/*`) as the outer message's body. Such a part is still listed as a part, so every part an earlier enumeration recorded is still found. A forward-as-attachment of an HTML message is tested.

**Gateway details (S19–S24):**
- Each fetched message's hold is released as it is written.
- A per-credential cap (`-credential-held-bytes`, 48 MiB) means one app password cannot starve the host: past it, that credential waits and then gets `NO [LIMIT]`.
- The shared poll cache stores the compact form, at most 2,000 changes per entry, in its own 8 MiB budget.
- Refusal memory covers the open folder only, up to 1,000 entries.
- A panic in a cached call completes the entry.
- Every SMTP reply goes through one helper, which strips the trailing full stop.
- A fixed folder with a listed label under it is `\HasChildren`.
- IDLE `DONE` is answered without waiting for an in-progress check.
- The defaults total about 389 MiB, under the 400 MiB soft limit.

**Noted, not changed:**
- **S6:** the installer's JSON rewrite and the helper's merge produce the same bytes.
- **S9:** a refused action with the header is answered before the limiter (commented in `apiv1.php`).
- **S15:** To/Cc entries not on the envelope are dropped from the sent headers, because `EmailMessage` delivers to every header address.
- **S17:** an APPEND that matches a message already here applies the folder to that message.
- **S26:** the deactivate hook runs inside PluginManager's transaction; a rollback would leave the switch on with the gateway off.
- **On a bare-metal host with several sites,** every site's PHP runs as `www-data`, so one site's admin could switch another installed site on or off. This is inherent to the shared web user.
- **S18:** J1's decision covers `$NotJunk` as well as `$Junk`.

## Live Thunderbird re-run against the built gateway (2026-10-11)

Real Thunderbird 157.0.1 against the real gateway and dev. Every scenario passed:
- account setup;
- the folder list, with special uses and archiving flat into Archive;
- offline download, with the size told equal to the bytes served;
- read, flag and unread round-tripping to the database;
- moves to Archive and Trash;
- a draft saved twice ending as one row;
- new mail during IDLE arriving within 29 s;
- delete in Trash marked but not expunged (D1);
- APPEND to INBOX;
- a foreign From refused 550;
- send-to-self with the Sent copy deduplicated.

**Open from the run** (§ Decisions after the build):
- **J1 `$Junk`:** Thunderbird's own spam filter, on by default, learns from one Junk click and then STOREs `$Junk` on messages the person never touched. Each one becomes a server spam verdict and leaves the Inbox everywhere.
- **J2 All Mail:** Thunderbird downloads `\All` for offline use, so every message is downloaded twice.
- **J3 Sent bytes:** Thunderbird keeps its own appended copy of a sent message and never refetches it, while the server serves the stored copy rebuilt. The two differ, for example in `Content-Language`, `format=flowed` and transfer encoding.
- **J4 Labels from other mailboxes:** a mailbox with no feed lists every label on the site, so a new mailbox showed another member's label ("Meditation"). This is mailbox's existing reader behaviour, which IMAP now exposes too.

**Fixed from the run:** SMTP replies drop their closing full stop (Thunderbird adds its own).

**Decided and fixed (2026-10-12):**
- **J1:** `$Junk` and `$NotJunk` are no longer offered (FLAGS and PERMANENTFLAGS are `\Seen \Flagged \Answered \Deleted \Draft $Forwarded`). A STORE of them is dropped by the gateway, and `store()` no longer maps them to a verdict. Only a move into or out of Spam sets one.
- **J2:** there is no All Mail folder.
- **J3:** first fixed by answering `APPENDUID` only when the served bytes equal the appended ones, which in practice was never. The Thunderbird re-run (2026-10-12, on 0.8.485) showed that is wrong. Thunderbird with UIDPLUS takes a missing `APPENDUID` as the UID of the last message fetched on that connection. It files its copy under it and runs its after-send filters on it, and after every send it showed 'Your message has been sent and saved, but there was an error while running message filters on it.' Changed: `APPEND` always answers its number (`MailAppMutations` 1.0.1). Thunderbird keeps its own appended bytes under it. Other devices download the site's copy, which can differ in encoding (`Content-Language`, `format=flowed`, transfer encoding). That difference is accepted. Making the bytes identical would need mailbox to keep the original of every appended message.
- **J4:** open. Labels are one site-wide set by design (`MailboxService::deleteLabel()`: "one shared concept across every mailbox"), so it is a mailbox design question, not a listing fix.

## Code review (reviewer2, 2026-10-11)

Seventeen findings. All are fixed with tests, except the three noted at the end.

| | Finding | Fix |
|---|---|---|
| R1 blocker | The app-access header switched both per-address limiters off on any route, `auth/login` included, which allowed unlimited password guessing | Only an action request is app-shaped. A sessionless action refuses the headers (403). |
| R2 | Gateway memory was unbounded per user | Gateway-wide budgets: in-flight answers 144 MiB, cached bodies 48 MiB, open folder lists 96 MiB (32 bytes per message), up to 2,000 connections. One copy fewer per message. About 381 MiB in all, under the 400 MiB soft limit and `MemoryMax=512M`. |
| R3 | Allow mail apps did not gate the credentials | `appAccess.requiresSetting`: a switched-off purpose is not available. No credential is minted, every one is refused, and none borrows. The actions also declare `requires_setting`. |
| R4 | "Forward as attachment" sent the attached message's insides as loose parts | Send and drafts keep a `message/*` part whole. The rebuild skips manifest rows nested inside one. |
| R5 | Ten failures in someone's name locked their devices out | 60 per network and 600 per username per 15 minutes. The secret is 256 bits, so the limits bound load, not guessing. |
| R6 | A slot was armed uncapped for people with no Private mailbox | No slot is armed when the caps carry no absolute bound. |
| R7 | Deactivating a key, the switch or the plugin left the slot armed | Saving a deactivated credential, turning the switch off, and the plugin's deactivate hook all end the slots. The deactivate hook also turns the gateway off. |
| R8 | Every send refusal was temporary (451) | A refusal of the message itself is 554. Transport trouble stays 451. |
| R10 | APPEND to a label left the message in the Inbox | Archived, as a move into a label is. |
| R12 | Useful headers were dropped on send | Read-receipt request, priority, language, Organization, Autocrypt/OpenPGP and User-Agent are carried. |
| R13 | 30 connections per source was too low behind NAT | 100 per source, plus 20 per app password. |
| R14 | Turning one site off restarted the gateway for every site | Off reloads (SIGHUP). Only that site's connections close. |
| R15, R16 | Form dispatch ignored `app_purpose`; an unused parameter | Done. |

**Not changed:**
- **R9 (remote size):** fixing the size exactly would need a live fetch per message at header time. A mismatch is logged, and the real bytes are served.
- **R11:** not a defect. A program's draft save always creates a new draft, which seals to the public key and works while locked.
- **R17:** consistent with the browser's own re-unlock.

## What the person gets

- On their profile, a **Mail apps** page: "Use Thunderbird or another mail program." They name the device ("Laptop"), get an **app password** shown once, and copy the settings into Thunderbird — or Thunderbird finds the settings itself from their address (§ Finding the server).
- Thunderbird shows every mailbox they hold as an account, with Inbox, Sent, Drafts, Archive, Spam, Trash and one folder per label. Reading, starring, archiving, labelling, deleting and sending from Thunderbird do the same thing as doing it in the web reader, and both stay in step.
- On a **Private** mailbox: Thunderbird works while the vault is unlocked. When it is locked, Thunderbird keeps showing the mail it already downloaded, new mail waits, and Thunderbird's Inbox shows one notice: "Your mailbox is locked. New mail is waiting. Unlock it at {link}." Unlocking on the website removes the notice and the waiting mail arrives.
- On a **Fortress** mailbox: not offered. The Mail apps page says why in one line ("Fortress mail can only be opened on your own devices; mail programs are not supported yet").

### What the person is told

Shown on the Mail apps page before the first app password is made, when any of their mailboxes is Private:

> **While a mail app is checking your mail, your mail stays unlocked on the server** — for up to 7 days after you last unlocked on the website (24 hours on {domain}). That is the same as staying signed in to the website the whole time. A stolen copy of the database or a backup still can't be read.
>
> **Your mail app also keeps its own copy of your mail on this computer.** Anyone who can open this computer's files can read it. Turn on disk encryption if you haven't.

The lock chip, while an app holds the unlock, adds one line: "Also unlocked for: Laptop (Mail apps), until Oct 18."

---

## Scope

### In scope (v1)

- IMAP4rev1 with the extensions Thunderbird uses: `UIDPLUS`, `MOVE`, `IDLE`, `SPECIAL-USE`, `LITERAL+`, `ENABLE`, `UTF8=ACCEPT`, `NAMESPACE`. Not in v1: `CONDSTORE` and `QRESYNC` (Thunderbird does not use them by default; go-imap v2 has neither), `ID`, `COMPRESS` (F1, F15)
- SMTP submission with `AUTH PLAIN`
- Implicit TLS only: IMAP on **993**, submission on **465** (RFC 8314). No plaintext ports, no STARTTLS.
- App passwords: per device, one purpose, revocable, listed with last use
- Standard and Private mailboxes; shared (team) mailboxes at Standard
- Thunderbird autoconfig where the mail domain allows it; manual settings always shown

### Out of scope (v1) — the firm lines

- **Fortress mailboxes.** The server cannot open them. The route is a local bridge — this same gateway run on the person's own computer, holding the mail key the way the phone does (`docs/sealed_vault.md` § Handing a vault to a device). Separate spec.
- **App-password purposes other than `mail`.** Core accepts any declared purpose; nothing else declares one yet.
- **Reply marks from the web reader.** Thunderbird's "replied" and "forwarded" marks come from mail apps only, because `mailbox` does not know this plugin exists. A reply sent from the web reader shows no replied mark in Thunderbird.
- POP3, JMAP, Exchange (EWS), CalDAV/CardDAV
- OAuth sign-in for mail apps — see § Future. Thunderbird's support for OAuth on servers outside its built-in list is new and needs manual setup; other clients don't have it.
- The catch-all "Unmatched" box and the all-access "All mail" view
- Server-side Sieve

---

## Architecture

```
Thunderbird ──TLS 993/465──▶ mail gateway (Go, on the host) ──loopback HTTP──▶ site /api/v1 mail_apps/* actions
                                                                               │
                                                     mail_apps plugin PHP ─────┤ (numbering, change tracking, rebuild)
                                                                               │
                                       mailbox public entry points + VaultUnlock (core)
```

**Why a separate program.** IMAP clients hold a connection open for hours and wait on it for new mail (`IDLE`). PHP serves one request and exits. The gateway holds the long connections and turns each IMAP or SMTP command into calls to the site's API.

**Why not Dovecot or Postfix submission.** Dovecot stores mail its own way. Using it would mean a second copy of every message outside our sealing model, or a custom storage plugin in C. The gateway keeps no mail of its own.

**The gateway knows nothing and holds nothing.** It has no database access, no vault access, and no credential of its own. Every call carries the person's app password, so the site's existing scoping (`MailboxViewer`), sealing and audit apply unchanged. A compromised gateway can do only what a person who typed their app password could. It never writes mail to disk; the plaintext it holds is one message in memory per connection while a fetch is in progress.

**Where it runs.** On the host, beside the Apache front door. It reads the same certificates (`/etc/ssl/joinery/<domain>` or the Let's Encrypt lineage, `default_proxy_vhost.conf` § certificate dir), picks the site by the TLS server name (SNI), and calls that site's published loopback port — the same path the front proxy uses. A single-site box is the same with one site. On a multi-tenant host one gateway serves every site. That matches the trust the front proxy already has: it terminates TLS for every site's web traffic, the web reader's mail included.

**Built like `relay-sealer`.** A single static Go binary, source at `plugins/mail_apps/provisioning/mail-gateway/`, prebuilt at publish into `plugins/mail_apps/provisioning/bin/mail-gateway-<uname -m>`. It is built on `emersion/go-imap` v2 (server side) and `emersion/go-smtp`; Phase 0 confirms go-imap v2's server is mature enough, and versions are pinned.

**Tension with the shrunken main box.** With a relay in front, the main box deliberately runs no mail listener (mailbox overview § The shrunken main box). The gateway is a mail listener on the box that holds the data. What keeps this acceptable:
- It accepts nothing anonymously. A connection can do nothing but sign in until it has, and it is not an MX — it never accepts mail from the internet.
- It is memory-safe Go and reaches data only through the API.
- It is off until a site turns it on (§ Network and install).
- Programs reach it by the deployment's mail host name, which is already public as the MX. It adds nothing to the mail domain's DNS, so `checkOriginHidden` is unaffected (§ Where programs connect). Autoconfig records (§ Finding the server) are the exception and are opt-in per domain.

---

## Core: app access

A program that can't do a passkey sign-in — a mail app today, a calendar or file-sync app later — signs in with an **app credential**: one device, one purpose, revocable by the person.

**Two things are separate on purpose:**
- **The purpose** — what the credential may do (`mail`). It is declared by a plugin and decides which actions accept it, whether it borrows the unlock, and where it is listed.
- **The credential kind** — how the program proves it is that device. v1 has one kind, the **app password**. Browser sign-in (OAuth) would be a second kind (§ Keeping browser sign-in open).

Every rule below — acceptance, borrowed unlock, revocation, listing, rate limits — is written against the purpose and the credential's `apk_api_keys` id. None is written against the kind.

**The v1 kind: a new API key type, `app_password`,** in `apk_api_keys`, beside `machine` and `session`:

| | `app_password` |
|---|---|
| Minted by | The person, through core's `app_password_create` action (browser session + step-up, `docs/account_security.md` § Step-up confirmation), with a purpose and a device name. A plugin's page — here, Mail apps — calls it |
| Purpose | `apk_purpose` (new, `varchar(32)`). Must be declared by an active plugin's `plugin.json` under `appAccess` (below). Minting an undeclared purpose is refused, the same way `Setting::put()` refuses an undeclared name |
| Shown | Once, as one string: `jm-{public}-{secret}`, grouped for typing. The gateway relays it as typed in `X-Joinery-App-Secret`, and `ApiAuth` splits it, forgiving spaces and case |
| Secret hashing | SHA-256, as session keys (random 256-bit) |
| Identity | The person who minted it |
| Accepted by | Only actions whose descriptor declares `'app_purpose' => '{purpose}'` matching the key's purpose — any credential kind of that purpose. Refused 403 everywhere else: other actions, CRUD, forms, `auth/*` (including the `auth/web_session` bridge), `app/*`, management |
| Not a person credential | `requires_person_credential` does not admit it. An app credential cannot unlock, set up, rotate, approve a device or change a protection level |
| Expiry | None. Revoked by the person, by password change (as session keys), by an admin, and when the declaring plugin is deactivated (its purpose stops being declared, so every key of that purpose is refused) |
| Listing | Core's App Sessions page lists every app credential with its purpose and kind. The declaring plugin's page lists its own. Each row shows device name (`apk_name`), created, last used, and last address (`apk_last_used_ip`, new — whichever of IPv4 or IPv6 the request arrived on, stored as `inet`, stamped beside `apk_last_used_time` for every key type) |

**Declaring a purpose** (`plugins/mail_apps/plugin.json`):

```json
"appAccess": {
    "mail": { "label": "Mail apps", "borrowsUnlock": true }
}
```

`borrowsUnlock` says credentials of this purpose read sealed content and take part in § Core: the borrowed unlock. A purpose without it never gets a window.

**Failed sign-ins** are limited in an `app_access_auth` bucket: 60 per 15 minutes per source network (IPv4 /32, IPv6 /64), and 600 per username. The secret is 256 random bits, so the limits bound load, not guessing. The per-username limit is high so that nobody can lock a person's devices out by failing in their name (review R5). The gateway passes the client's address in `X-Joinery-Client-Ip`, believed only from loopback, so limits and last-used see the real address. The per-address pre-auth buckets are skipped only for an app-shaped ACTION request, since those all arrive from the gateway's loopback address and are metered after authentication. A sessionless action refuses the app-access headers outright, and on every other route family the headers switch nothing off (review R1).

**Mail usernames.** The username Thunderbird sends is the mailbox address, whatever the credential kind. The gateway passes it to `mail_apps/login`, which resolves it to the alias and checks the credential's person holds a grant on it. One credential works for every mailbox the person holds, because Thunderbird asks per account and people should not juggle several.

**The gateway does not interpret credentials.** It advertises the SASL mechanisms the site supports (v1: `PLAIN`) and passes what the client presented to the site unchanged. Core's `ApiAuth` resolves it to (person, purpose, credential id). The gateway never decides whether a credential is good.

---

## Core: the borrowed unlock

Today a window belongs to one PHP session: `VaultUnlock::apcuKey()` is `vault:{session_id}:{user_id}:{scope}`. An app has no PHP session.

**Each app credential whose purpose `borrowsUnlock` gets its own window slot,** `vault:apk{key_id}:{user_id}:user` (the credential's `apk_api_keys` id, whatever its kind), in the same APCu pool with the same metadata. A request authenticated by that key uses that slot as its session for every `VaultUnlock` read (`VaultUnlock::useCredentialWindow('apk' . $key_id)`, set by `ApiAuth` before dispatch). Everything else — `secretKey()`, `endedByPolicy()`, the egress guard, the audit — runs unchanged.

**How the slot opens.** When a person unlocks on the website (`VaultUnlock::arm()` from a browser session, scope `user`), the same key is also armed into the slot of each of their live borrowing keys. It uses the caps `capsForUser()` already folds, measured from that moment, and the audit records `via = app_borrow` naming the credential. Unlocking again on the website re-arms them, which restarts the 7-day / 24-hour limit.

**How the slot stays open (decision 2).** A request on that key that reads sealed content is a content decrypt, so `secretKey()` refreshes the idle time as it does for the web. A check that decrypts nothing (`mail_apps/changes` with no new mail) calls `VaultUnlock::touchActivity()`. That method is new: it refreshes the idle time and content stamp, and refuses to run outside a borrowing-key request. No heartbeat applies to these slots, and neither does the network-change rule (session keys are not IP-bound either).

**How it closes.**
- The absolute or idle cap is reached, at read time, as now.
- The person presses **Lock** on the website. `vault_lock` also locks every borrowing slot of that user.
- Signing out of one website session does not lock apps; signing out is about that browser.
- A credential event or password change. `lockAll()` already matches `vault:[^:]*:{user}:` and covers these slots with no change.
- The key is revoked, which locks its slot.

**Visible.** `vault_status` lists open borrowing slots with device name, purpose label and end time, for the lock chip line in § What the person is told.

---

## Keeping browser sign-in open

Browser sign-in is not built in v1 (decision 4). A person would add Thunderbird by typing their address; a browser window opens on this site, they use their passkey, and Thunderbird gets a token instead of an app password.

**State of clients (checked 2026-10-11).**
- Thunderbird has just added custom OAuth settings for IMAP and SMTP (Mozilla bug 2053191, 155 branch). It supports XOAUTH2 only, with PKCE and an external browser, and the endpoints and client id are typed in by hand.
- Finding the settings from the address alone is planned but not built (bugs 2068627, 1602166), and OAUTHBEARER is open (bug 2068623).
- Thunderbird has an add-on hook for registering OAuth providers (bug 2055286), so a Joinery add-on could make setup one step.
- Apple Mail and K-9 have no equivalent for servers they don't already know.

**What v1 does so that adding it later changes no v1 code outside core sign-in and the gateway's mechanism list:**

| Seam | v1 shape | What browser sign-in adds |
|---|---|---|
| Credential storage | an `apk_api_keys` row with `apk_purpose` | a second `apk_type` (`app_oauth`) in the same table, same purpose column, holding the refresh token's hash; access tokens are short-lived and derived |
| Who accepts it | actions declare `app_purpose`, never a kind | nothing |
| Borrowed unlock | slot keyed by the credential's `apk_api_keys` id | nothing. Signing in through the browser is itself a website visit, so unlocking there arms the slot in the same visit |
| Revocation, listing, rate limits | by credential id and purpose | nothing; the App Sessions and Mail apps pages show the kind |
| Gateway | advertises mechanisms, forwards credentials, never judges them | advertise `XOAUTH2` (and `OAUTHBEARER` once Thunderbird has it) |
| `ApiAuth` | resolves a credential to (person, purpose, id) in one place | resolve a bearer token the same way |
| Autoconfig | `<authentication>password-cleartext</authentication>` | an OAuth2 element per the mailmaint autoconfig draft, beside the password one |

**What a later spec would build:** Joinery as an OAuth server — authorization and token endpoints, PKCE, refresh tokens, a consent page, and metadata at `/.well-known/oauth-authorization-server` (RFC 8414) — in core, generally, since "Sign in with Joinery" for other apps would use the same server. Today core is only an OAuth client (`docs/oauth2.md`). App passwords stay either way, for every client without OAuth.

**Kept honest by a test.** A mechanical test fails if `VaultUnlock`, the `mail_apps` plugin, or any action descriptor names the `app_password` kind. Only `ApiAuth`, `ApiKey` and the mint/revoke logic may name it. That keeps the seams above from closing by accident.

---

## What mailbox exposes

Three public entry points. Each already exists as private code. Making it public adds no new behaviour, and the reader keeps using the same code.

| Entry point | From | Used for |
|---|---|---|
| `MailboxService::viewScopeSql(int $aliasId, string $view, ?int $labelId = null): string` | the private `readScopeSql()` / `trashScopeSql()` / `draftScopeSql()` and the reader's view filters, as one WHERE fragment over `iem_` | which messages each IMAP folder holds. `$view` is `inbox`, `sent`, `drafts`, `archive`, `spam`, `trash`, `all`, `label`. The reader's own list queries switch to it, so "what Inbox means" is defined once |
| `MailboxSender::sendRaw(InboundEmailAlias $alias, string $raw_mime, string $mail_from, array $rcpt_to): array` | the path `send()` takes after `buildRawMime()`: in-app DKIM, `OutboundTransport`, `storeOutboundRow()`, the attempt record, send protection and rate limits | sending what Thunderbird submitted |
| `MailboxSender::storeRawOutbound(InboundEmailAlias $alias, string $raw_mime): int` | `storeOutboundRow()` without the send | a message Thunderbird puts in Sent that Joinery did not send |

One general extension: `MailboxDrafts::saveDraft()` takes a part given as bytes (`name`, `type`, `bytes`, `content_id`) as well as an HTTP upload, because a Thunderbird draft arrives as MIME. Without it a draft's attachments would be lost on the server copy.

Everything else the plugin needs is already public:
- reading: `getThread()`, the attachment opener, `getRawMessage()`;
- changing: `markRead()`, `setStarred()`, `setArchived()`, `setSpamVerdict()`, `softDelete()`, `restoreFromTrash()`, `purgeFromTrash()`, `setMembership()`, `createFolder()`, `deleteLabel()`;
- drafts: `MailboxDrafts::saveDraft()` / `deleteDraft()`;
- storing an added message: `InboundEmailRouter::storeMessage()`.

`mailbox` bumps its minor version, and `mail_apps` declares `"depends": {"mailbox": ">={that version}"}`.

---

## Locked behaviour

A Private mailbox's sender, subject and bodies are sealed, so while locked the gateway cannot describe a new message at all — not even its list entry. The rules:

1. **A message becomes visible to IMAP only when it can be served** (§ Message numbers). While locked, new mail gets no number, so Thunderbird sees nothing new. On unlock it is numbered and Thunderbird picks it up as new mail.
2. **Mail already visible stays visible.** Thunderbird keeps showing what it downloaded. A fetch of a message Thunderbird never downloaded answers `NO [UNAVAILABLE] Mailbox locked - unlock at {link}` (ASCII: F4). The gateway does not let that message become read (F5).
3. **Never a stand-in.** The gateway never answers a fetch with placeholder text in place of a real message. Thunderbird stores each message's bytes by number forever, so a placeholder would stay in its copy for good.
4. **The lock notice is its own message.** While any of the mailbox's mail is waiting on the lock, Inbox holds one generated message, numbered like any other, with the unlock link. Its content never changes. It is expunged when the lock lifts and a fresh one is numbered the next time.
5. **Changes still work.** Read, star, archive, label, delete and move are metadata (mailbox's mutations already work while locked) and apply at once.
6. **Sending works while locked**, except on a domain with *Only send while I'm signed in*, where the signing key lives in the window. There the submission is refused at the end of DATA with `454 4.7.0 Mailbox locked - unlock at {link}`. Thunderbird shows that text in a dialog and keeps the compose window open to send again; nothing goes to its Outbox (F3).

Phase 0 measured how Thunderbird presents rules 2, 4 and 6 (F3–F5). The lock notice is dropped and renumbered cleanly, and arrives as new mail like any other.

---

## Folders

Joinery uses labels, not folders: a message can carry several. Thunderbird sees them the way Gmail presents labels over IMAP.

| IMAP folder | `SPECIAL-USE` | `viewScopeSql()` view |
|---|---|---|
| `INBOX` | — | `inbox` |
| `Sent` | `\Sent` | `sent` |
| `Drafts` | `\Drafts` | `drafts` (author = the signed-in person) |
| `Archive` | `\Archive` | `archive` |
| `Spam` | `\Junk` | `spam` |
| `Trash` | `\Trash` | `trash` |
| `All Mail` | `\All` | `all` |
| one per label | — | `label` |

**What Thunderbird's actions do** (each calls the mailbox method named):

| In Thunderbird | In Joinery |
|---|---|
| Mark read / unread, flag | `markRead()`, `setStarred()` |
| Move Inbox → Archive | `setArchived(true)` |
| Move anything → Trash | `softDelete()` |
| Move or copy into a label folder | `setMembership(true)`. A move out of Inbox also archives, and a move out of a label folder also removes that label |
| Move → Spam, or the Junk button | `setSpamVerdict('spam')` (teaches the filter, as the reader's button does) |
| Move out of Spam | `setSpamVerdict('ham')` |
| Move out of Trash | `restoreFromTrash()` |
| Delete in Trash (`\Deleted` + `EXPUNGE`) | `purgeFromTrash()` — gone for good |
| `\Deleted` + `EXPUNGE` in a label folder | the label comes off; the message stays |
| `\Deleted` + `EXPUNGE` in Drafts | `deleteDraft()` (each Thunderbird draft save expunges the previous version: F9) |
| `\Deleted` + `EXPUNGE` elsewhere | `softDelete()` |
| The Junk / Not Junk buttons (`STORE +$Junk` / `+$NotJunk`) | `setSpamVerdict('spam' / 'ham')` (F7) |
| Create / delete a folder | `createFolder()` / `deleteLabel()` |
| Rename a folder | refused: labels cannot be renamed |
| Mark replied / forwarded | the plugin's own `mam_` row (below) |

Flags Joinery does not store (Thunderbird's own tags `$label1`–`$label5`, `$MDNSent`) are left out of `PERMANENTFLAGS`, so Thunderbird keeps them locally (confirmed, Q12). `$Junk` and `$NotJunk` are listed so the Junk button reaches the server. The fixed folders are `\Noinferiors`, so Thunderbird archives into Archive itself, not yearly subfolders (F6).

---

## Message numbers (UIDs)

IMAP numbers each folder's messages, and a message added to a folder must get a number higher than every number that folder has used. Message ids cannot do this: a message archived and moved back to Inbox, or labelled a year after it arrived, would come back with an old number and Thunderbird would never fetch it.

**`mau_mail_app_uids`**, one row per (alias, folder, message) appearance:

| Column | |
|---|---|
| `mau_iea_inbound_email_alias_id` | the mailbox |
| `mau_folder` | `inbox`, `sent`, `drafts`, `archive`, `spam`, `trash`, `all`, `label:{ilb id}` |
| `mau_uid` | allocated from that folder's counter |
| `mau_iem_inbound_email_message_id` | the message |
| `mau_flags_told` | the flags Thunderbird was last told, as a small bitmask (seen, flagged, answered, forwarded) |
| `mau_modseq` | the change number when those flags were last told |
| `mau_expunged_time` | set when the message leaves the folder; the number is never reused |

**`maf_mail_app_folders`**, one per (alias, folder): `maf_uidvalidity`, `maf_uidnext`, `maf_highest_modseq`.

These are separate from mailbox's `ilm_imap_uid` / `ilm_imap_uidvalidity`, which record numbers on a source server Joinery *pulls from* (two-way IMAP sync). Different direction, different numbers.

**Numbers are allocated when the plugin reconciles a folder** (`mail_apps/sync`). Messages now in the folder's view without a live row are numbered in arrival order, and live rows no longer in the view are expunged. A sealed message is numbered only when it can be served — the window is open — which is what makes § Locked behaviour rule 1 hold. Reconciling is one set difference between `mau_` and `viewScopeSql()`, and each folder's counter is taken under a row lock.

**`UIDVALIDITY` changes** only when a folder's numbering must start over: a label deleted and recreated under the same name, or a change to the message format (§ Whole messages). Thunderbird re-downloads that folder.

---

## How Thunderbird learns what changed

Thunderbird syncs fastest when it can ask "what changed since change number N?" (`CONDSTORE`). The plugin works that out itself by comparison, without mailbox telling it:

- **Each reconcile compares what Thunderbird was told with what is true now.** For every live row in the folder, the current flags are built from `iem_is_read`, `iem_is_starred` and the plugin's `mam_` row, and compared with `mau_flags_told`. Rows that differ get the folder's next change number and their `mau_flags_told` updated, in one statement.
- **Moves need no comparison.** Archive, spam, trash and label changes move a message between views. They show up as an expunge in one folder and a new number in another — the numbering reconcile above.
- **Nothing is missed while nobody is connected.** The comparison is against the current truth, not a log of events, so a change made in the web reader while Thunderbird was closed is found on the next sync.

**Measured (2026-10-11, dev, 100,000-message INBOX, `mail_apps_scale_test.php`):**

| Step | Time |
|---|---|
| First numbering of 100,000 messages | 3.6 s |
| Reconcile with nothing changed (each IDLE poll) | 0.92 s median |
| Reconcile after 100 flag changes | 0.63 s |
| Full flag listing (every SELECT) | 0.84 s, 7.7 MB of JSON |

The first run found the "not yet numbered" check planned as a 100,000 × 100,000 nested loop, because statistics went stale after the bulk numbering. It now uses a hashed `NOT IN`, and statistics are refreshed after any reconcile that changes 1,000 or more numbers.

An idle Thunderbird keeps about four folders polled every 30 s. At this size that is roughly 0.1 s of database time per second per person. Acceptable for v1; the change-counter alternative below is the lever if it isn't.

**Cost.** Each check is one indexed comparison over the folder's rows. `IDLE` polls only the folder each connection has selected. Thunderbird keeps up to four connections idling, on whatever folders they last used (F2); the gateway shares one poll per (credential, mailbox, folder). Because Thunderbird does not use CONDSTORE (F1), the comparison drives the unsolicited FETCH and EXPUNGE lines the gateway pushes during IDLE and NOOP. Every SELECT still lists the whole folder's flags. A 100,000-message folder has not been measured yet.

**Rejected alternative:** mailbox bumping a change counter on every write. It is cheaper per check, but it threads this feature through every mailbox write path, which decision 3 rules out. It is reconsidered only if the measured cost is too high, and then as an owner decision.

**`mam_mail_app_messages`**, one per (alias, message) the plugin has something to say about:
- `mam_is_answered`, `mam_is_forwarded` — set by a mail app's `STORE` and by `mail_apps/submit` (§ Sending);
- `mam_client_size`, `mam_client_format` — § Whole messages.

---

## Whole messages

On most stored mail the original is not kept: arrival keeps the header block (`iem_raw_headers`), the decoded text bodies and each attachment as a private File (mailbox overview § Attachment & message storage). IMAP clients ask for the whole message, so the plugin serves a **rebuilt** one (`MailAppRebuild`):

- The retained header block, minus `Content-Type` / `Content-Transfer-Encoding` / `MIME-Version`, then a rebuilt MIME tree: `multipart/mixed` over `multipart/related` (HTML + inline parts by `content_id`) over `multipart/alternative` (plain + HTML), with attachments from the manifest in manifest order.
- **The same bytes every time.** The boundary is derived from the message id and the format version. Bodies are always UTF-8 quoted-printable, attachments always base64 at 76 columns, CRLF throughout. Thunderbird caches by number and size, so a changed byte shows up as a broken message.
- **The format is versioned** (`MailAppRebuild::FORMAT = 1`). Changing the output means a new version and a `UIDVALIDITY` change for every folder, never a silent change to bytes already served.
- Its size is stored at first numbering (`mam_client_size`, with `mam_client_format`), because `RFC822.SIZE` is asked for in bulk and must not trigger rebuilds.
- A message stored whole (`stored`), or pulled in from a connected source (`remote`), is served as its original through `getRawMessage()` — the latter fetched live from the source as Show original does. A remote message whose source no longer has it answers `NO [UNAVAILABLE]`.
- The sender's DKIM signature will not verify against a rebuilt body. Thunderbird does not check DKIM by default.

**This does not change Download .eml.** The reader's export still serves only a stored original, because a downloaded file that claims to be the original must be one (mailbox overview § The message kebab). A mail app's copy is a different contract: a faithful rendering of what Joinery holds. The plugin's doc states it, and the mailbox overview's Download .eml paragraph gains one sentence pointing to it.

Rebuilding a Private message needs the key. It happens in the API (`mail_apps/fetch`), in the window, and the rebuilt bytes go back to the gateway over loopback and are not stored. Large messages are fetched in pieces by Thunderbird (`BODY[]<offset.length>`). The gateway keeps the current message's bytes in memory for the connection so pieces don't trigger repeat rebuilds, dropped when the next message is fetched or after 60 seconds.

---

## Messages Thunderbird adds

- **Drafts** (`APPEND` to Drafts, and Thunderbird's delete of the previous version on each save) become drafts through `MailboxDrafts::saveDraft()` / `deleteDraft()`, parsed from the MIME Thunderbird sent. A draft started in Thunderbird opens in the web reader and the reverse.
- **Sent** (`APPEND` to Sent after sending). The submission already stored Joinery's Sent copy, so an `APPEND` whose Message-ID matches an outbound row of the same mailbox stores nothing and answers with that row's number (`APPENDUID`). Any other `APPEND` to Sent goes through `MailboxSender::storeRawOutbound()`.
- **Any other folder** (`APPEND` to Inbox or a label — Thunderbird's "copy local message to server"): stored as an inbound row through `InboundEmailRouter::storeMessage()`, sealed when the mailbox is, with the original kept whole.

Everything Thunderbird adds is sealed with the mailbox's public key, so adding works while locked.

---

## Sending

Thunderbird hands over a finished message and an envelope. **`mail_apps/submit`** takes `{raw_mime, mail_from, rcpt_to[]}`:

1. **The From must be this mailbox.** The `From` header address must be the alias the session signed in as, or one it may send as (`MailboxSender::sendCapabilityFor()`). Otherwise `550 5.7.1`.
2. **Headers are made ours.** Drop any `Received`, `X-Originating-IP` and `Bcc`, and any header naming the local machine's hostname. Keep Thunderbird's `Message-ID` when its right side is the mailbox's domain, otherwise replace it with one minted from the mail hostname as composed sends do.
3. **Signed, sent and stored by mailbox** — `MailboxSender::sendRaw()`. The message is carried over part for part into an `EmailMessage` and sent on the same path as a reader send: in-app DKIM, `OutboundTransport`, the hidden-origin rules, send protection, test mode, Joinery Direct, the attempt record. The Sent copy is Thunderbird's own message (minus `Received`/`X-Originating-IP`, with the envelope's Bcc written in: F10), stored once and sealed where the mailbox is. The gateway never relays mail itself.
4. **Reply marks.** A forward (`X-Forwarded-Message-Id`, checked first because a forward also carries `In-Reply-To`: F11) marks its original `mam_is_forwarded`. Otherwise the message `In-Reply-To` names gets `mam_is_answered`. Thunderbird also stores these itself after sending; other programs may not.
5. **Not carried:** a message signed or encrypted as a whole (PGP/MIME, S/MIME) is refused `554 5.6.0`, because Joinery re-signs what it sends as the mailbox. Inline PGP works.

---

## Finding the server

**Manual settings are always shown** on the Mail apps page:

| | |
|---|---|
| Incoming | IMAP, `{mail host name}`, port 993, SSL/TLS, normal password |
| Outgoing | SMTP, `{mail host name}`, port 465, SSL/TLS, normal password |
| Username | the mailbox address |
| Password | the app password |

**Autoconfig.** Thunderbird looks for `autoconfig.{maildomain}/mail/config-v1.1.xml` and `{maildomain}/.well-known/autoconfig/mail/config-v1.1.xml`, over https and http, then at `autoconfig.{MX host's base domain}`. It does not consult SRV records (F12). The plugin serves both (a public view, no sign-in, answering only for domains this site hosts) with the manual settings above.
- Where the mail domain's website already points at this site, the second works with no setup.
- Otherwise the plugin offers one optional DNS row on the mail domain: a CNAME `autoconfig.{maildomain}` → the site hostname. The front door then needs a certificate covering that name, by the same certificate automation the encrypted-interop spec needs for `openpgpkey.` and `mta-sts.` subdomains (spec `mailbox_encrypted_interop`). Solve it once for all three.
- The row warns that it points at this server's address. On a relay-fronted deployment, `checkOriginHidden` treats an `autoconfig` CNAME as the person's choice, not a leak.

The MX-based lookup is not served in v1 (D2), and may be added later as an opt-in.

---

## Network and install

**Turning it on** is a guarded platform action on the plugin's admin page — **Allow mail apps** — never host surgery. It follows the mailbox `listener_admin.php` / `joinery-mail-listener` pattern: a narrow root helper, `joinery-mail-gateway on|off`, that installs the binary and systemd unit, opens 993/tcp and 465/tcp on IPv4 and IPv6, and closes them on `off`.

- On a multi-tenant host the helper is the host's and the setting is per site. The gateway refuses SNI names for sites that have not turned it on. The host keeps the ports open while any site has.
- The unit runs as an unprivileged `joinery-mailgw` user with read access to the certificate directories only, and reloads on certificate renewal.
- A site container gains nothing. The gateway lives on the host.
- Deactivating the plugin turns the site's switch off. Its app passwords stop working at once (§ Core: app passwords, Expiry). Its tables are kept. Uninstalling drops the tables and deletes the plugin's app passwords.
- Health on the admin page: listening, certificate valid for the mail host name, and a round trip — sign in with a throwaway app password, `SELECT INBOX`, sign out, revoke.

**Managed nodes.** On a site's own machine the host installer puts the helper in place and the site's switch runs it; the host report, `plane:mail_gateway` and the agent's `mail_gateway` recipe watch it (§ Running the gateway, A2). A container site's host gateway is A4.

---

## Data model

| Change | Where |
|---|---|
| `apk_type` value `app_password`; new `apk_purpose` (`varchar(32)`); `apk_name` holds the device name; new `apk_last_used_ip` (`inet`, every key type) | core `apk_api_keys` |
| `mau_mail_app_uids` | `plugins/mail_apps/data/mail_app_uids_class.php` |
| `maf_mail_app_folders` | `plugins/mail_apps/data/mail_app_folders_class.php` |
| `mam_mail_app_messages` | `plugins/mail_apps/data/mail_app_messages_class.php` |

Deletion: `mau_` and `mam_` rows cascade with the message, and `mau_` label rows with the label. `maf_` rows cascade with the alias. Mailbox gains no columns.

## API actions

All `POST /api/v1/action/mail_apps/mail_*`, each declaring `'app_purpose' => 'mail'`, each building a grant-only `MailboxViewer` for the credential's person and scoping to the mailbox the body names (`mailbox`). The table's names drop the `mail_` prefix:

| Action | Purpose |
|---|---|
| `login` | check username/key; answer the alias, its level, lock state and folder list. Fortress refused with the Mail apps page message |
| `folders` | folders with `uidvalidity`, `uidnext`, counts, `highest_modseq` |
| `sync` | reconcile a folder (numbering + change comparison); answer numbers, flags, size, internal date, modseq — all, or changed since a modseq |
| `changes` | cheap poll for `IDLE`: reconcile the selected folder and answer its highest modseq, and whether the lock state changed. Counts as activity (decision 2) |
| `fetch` | one message's bytes by folder + number (rebuilt or original) |
| `store` | flag changes |
| `move` / `copy` | folder moves per the table above |
| `expunge` | expunge `\Deleted` numbers in a folder |
| `append` | add a message (Drafts / Sent / other) |
| `folder_create` / `folder_rename` / `folder_delete` | labels |
| `search` | IMAP `SEARCH` criteria mapped to the reader's search (`q`) plus flags and dates; Private search uses the in-window index |
| `submit` | send (above) |

Plus the person-facing page actions (browser session): `mail_apps/status` for the Mail apps page, and core `app_password_create` / revoke.

---

## Integration points (up-front inventory)

**Core**
- `includes/VaultUnlock.php`: borrowing slots, borrow-on-arm, `useCredentialWindow()`, `touchActivity()`, `vault_lock` reaching borrowing slots, `vault_status` listing them
- `includes/ApiAuth.php` + `apiv1.php`: `app_password` type, purpose match against `app_purpose`, refusals everywhere else, resolving a credential to (person, purpose, id) in one place
- `data/api_keys_class.php` (`ApiKey`): type, `apk_purpose`, `apk_last_used_ip`, revocation on password change
- `appAccess` declarations in `plugin.json`, read through `PluginHelper`, the same way plugin settings declarations are read
- `app_password_create` / revoke logic; App Sessions page lists app passwords
- `VaultAudit`: `via = app_borrow`, and the slot's opens and closes
- Lock chip (`JoineryVaultLock`): the "Also unlocked for" line

**Mailbox** — the three entry points in § What mailbox exposes, and the reader's list queries moved onto `viewScopeSql()`. Nothing else.

**Mail apps plugin**
- the gateway, actions, numbering and change comparison, `MailAppRebuild`
- Mail apps profile page, admin page with the switch and health
- autoconfig views and DNS row
- `joinery-mail-gateway` root helper, provisioning
- server manager: host report `mail_gateway`, `plane:mail_gateway`, agent recipe `mail_gateway`, `restart_unit` / `unit_journal` for the gateway (§ Running the gateway)

**Shared** — front-door certificate automation, with spec `mailbox_encrypted_interop`

---

## Build phases

**Phase 0 — measure Thunderbird.** A throwaway go-imap v2 server with an in-memory store, driven by current Thunderbird on the Mac mini and the Windows VM. Record:
- what Thunderbird shows for `NO [UNAVAILABLE]` at `FETCH`, and for a `454` at submission;
- whether it downloads bodies for offline use by default;
- how it reacts to the lock notice being expunged and renumbered;
- which extensions it uses (`CONDSTORE`, `QRESYNC`, `MOVE`);
- its `APPEND`-to-Sent and draft-save sequences;
- the autoconfig lookup order, and SRV;
- go-imap v2 server-side completeness.

Any finding that changes a rule above is written back into this spec before Phase 1.

**Phase 1 — Standard mailboxes, read and sync.** Core app passwords (without the borrowed unlock). The mailbox entry point `viewScopeSql()`. The plugin: gateway, `login` / `folders` / `sync` / `changes` / `fetch` / `store` / `move` / `copy` / `expunge`, numbering, change comparison (with the 100,000-message measurement), rebuild, the install switch and health.

**Phase 2 — sending and adding.** Mailbox `sendRaw()` / `storeRawOutbound()`. `submit`, `append`, drafts, reply marks, Sent dedupe.

**Phase 3 — Private.** Core borrowed unlock, locked behaviour, the lock notice, the warnings, the lock chip line.

**Phase 4 — finding the server.** Autoconfig views, the DNS row, the shared certificate automation.

## Tests

- **Numbers only rise:** archive then unarchive, label late, trash then restore → each re-entry is numbered above the folder's `uidnext`. No number reused after expunge.
- **Changes found:** a read/star change made through mailbox directly (as the web reader does) is reported by the next `sync` since the old modseq; a change made while no client was connected is found too.
- **Same bytes:** rebuild a message twice, and across a process restart, byte-identical, with the size matching `mam_client_size`, over a fixture corpus covering every manifest shape.
- **Kind-blind seams:** the mechanical test in § Keeping browser sign-in open.
- **Core scope:** an `app_password` key is refused by every action not declaring its purpose, CRUD, `auth/web_session`, `vault_*`. An undeclared purpose cannot be minted. Deactivating the declaring plugin refuses its keys. A key on alias A cannot read alias B without a grant. A Fortress alias is refused.
- **Borrowed unlock:** a browser unlock arms the key's slot; `fetch` opens sealed content; the absolute cap ends it; `vault_lock` ends it; revoking the key ends it; signing out of the browser session does not; `touchActivity()` refuses outside a borrowing-key request.
- **Locked:** new sealed mail is not numbered while locked and is numbered on unlock; no `fetch` ever returns placeholder text; metadata changes work locked; the lock notice appears and is expunged.
- **Send:** a From outside the mailbox is refused; `Received` and `Bcc` are stripped; the Sent `APPEND` dedupes; a send-lock domain refuses while locked.
- **Mailbox unchanged:** the reader's existing list and mutation suites pass unchanged after the `viewScopeSql()` move.
- **Gateway:** a gate script runs a scripted IMAP session (Python `imaplib`) and SMTP session against the dev gateway, run in `live`.
- **Real client:** the Phase 0 rig re-run against the built gateway, in the live verification queue.

## Docs to write or update

- `plugins/mail_apps/docs/overview.md` (new): the whole feature, including what a mail app's copy of a message is
- `plugins/mailbox/docs/overview.md`: the three public entry points; one sentence beside Download .eml pointing to the mail apps doc; the Security levels table gains a "Mail apps" row (Standard yes, Private yes while unlocked, Fortress no)
- `docs/sealed_vault.md`: borrowing slots, under § The unlock window and § How long a window lasts
- `docs/api.md`: the `app_password` credential column, `app_purpose`, `appAccess` declarations
- `docs/account_security.md`: app passwords as a purpose-limited sign-in path
- `docs/plugin_developer_guide.md`: the `appAccess` manifest key
- CLAUDE.md documentation index (via the agent files admin): the Mail Apps Plugin entry

## Complexity and risk

- **Rebuild determinism** is the most fragile piece. Every message ever served is pinned to the bytes of one format version. Mitigated by the frozen format version and the byte-identity test over the fixture corpus.
- **The borrowed unlock** is the highest-risk change: it alters how the vault opens. It lands in its own phase, after the rest works at Standard.
- **The relaxed idle rule** (decision 2) is a real weakening for Private users who turn on a mail app, bounded by the absolute caps and made visible in the lock chip.
- **A new listener on the data box.** Mitigated by authenticated-only, TLS-only, memory-safe, API-only, and off by default.
- **Load.** Each idle Thunderbird account polls `changes` through php-fpm, and Thunderbird opens up to 5 connections per account. The gateway shares one poll per (key, alias, folder) across that key's connections, every 30 seconds. The comparison's cost is measured in Phase 1.

## Decisions after the build

**D1 — Delete in Trash from Thunderbird (F8). DECIDED (owner, 2026-10-11): leave it until expunged.** Thunderbird marks the message `\Deleted` and hides it, but does not EXPUNGE until 20 or more are pending, a compact, or Empty Trash. The message stays in the web reader's Trash until Thunderbird expunges it or Trash retention removes it. Nothing is purged on the mark alone, because IMAP treats `\Deleted` as undoable until EXPUNGE.

**D2 — Autoconfig at the mail host's parent domain (F12). DECIDED (owner, 2026-10-11): not now.** Thunderbird's MX step asks `autoconfig.{parent domain of the MX host}`. With programs connecting to the mail host name (§ Where programs connect), that is the parent of `mailbox_mail_hostname` (for dev, `autoconfig.getjoinery.com`), or of the relay's name. The parent domain is often shared (production owns `getjoinery.com` too) and one name can point at only one server, so v1 serves only the per-domain routes. **We may have to add it later** as an opt-in for an operator who owns the mail host's parent domain outright: one setup row and one certificate would then let Thunderbird find every hosted domain with no per-domain DNS row.

**D3 — Submission size. DECIDED (owner, 2026-10-11): about 23 MB for v1.** A program may send or add messages up to 23,000,000 bytes on the wire, which holds about 17 MB of files; the web reader allows 25 MB of files. The limit comes from the base64 JSON body fitting PHP's 32 MB `post_max_size`. The gateway advertises SMTP `SIZE 23000000`, and Thunderbird says why before sending; an oversize APPEND gets `NO [TOOBIG]`. This is close to Gmail's own 25 MB message limit.

The Phase 1 cost measurement may still bring back the rejected change-counter alternative.

## Future

- **Fortress through a local bridge:** the gateway run on the person's computer, holding the mail key handed to it as the phone receives it.
- **Browser sign-in (OAuth) for mail apps** — § Keeping browser sign-in open. Worth building once Thunderbird can find the settings from the address alone, or as a Joinery Thunderbird add-on.
- Other purposes on the same core app access: calendar and contacts sync (CalDAV/CardDAV), Drive over WebDAV.
- Accounts that hold several mailboxes under one login, with per-mailbox namespaces.
