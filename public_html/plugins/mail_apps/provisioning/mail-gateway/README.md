# mail-gateway

The program that lets Thunderbird — and any mail program that speaks IMAP and
SMTP submission — read and send Joinery mail (`specs/mail_apps_plugin.md`).

It holds the programs' long connections and turns every IMAP or SMTP command
into a call to the site's `mail_apps/mail_*` API actions over loopback. It
keeps no mail, no database access, no vault access and no credential of its
own: every call carries the app password the program presented, unchanged,
and the site's `ApiAuth` decides whether it is any good. The only mail it ever
holds is in memory: answers in flight, and messages fetched in the last 60
seconds, so a program reading one message in pieces does not make the site
rebuild it per piece — both under gateway-wide budgets (§ Memory).

```
Thunderbird ──TLS 993/465──▶ mail-gateway ──loopback HTTP(S)──▶ site /api/v1/action/mail_apps/mail_*
```

## Running it

```
mail-gateway [-config-dir /etc/joinery/mail-gateway] [-imap-listen :993] [-smtp-listen :465]
             [-smtp-domain NAME] [-max-conns-per-ip 100] [-max-conns-per-credential 20]
             [-max-conns 2000] [-inflight-bytes N] [-body-cache-bytes N] [-folder-list-bytes N]
             [-memory-limit N] [-version]
```

| Flag | Default | |
|---|---|---|
| `-config-dir` | `/etc/joinery/mail-gateway` | holds `sites/` (below) |
| `-imap-listen` | `:993` | IMAP, implicit TLS. Empty turns IMAP off |
| `-smtp-listen` | `:465` | SMTP submission, implicit TLS. Empty turns it off |
| `-smtp-domain` | the only site's hostname, else the host name | the name in the SMTP greeting |
| `-max-conns-per-ip` | `100` | concurrent connections from one address (an IPv6 /64 counts as one) — high enough for an office or household behind one NAT |
| `-max-conns-per-credential` | `20` | concurrent signed-in connections for one app password (counted at sign-in, keyed on a hash of username and password, never the password itself), so one credential cannot take an address's whole allowance. Thunderbird holds 5 per account |
| `-max-conns` | `2000` | concurrent connections in total (each costs ~40 KiB besides the budgets) |
| `-inflight-bytes` | `144 MiB` | bytes of upstream answers and submitted messages held at once, across all connections (§ Memory) |
| `-body-cache-bytes` | `48 MiB` | bytes of recently fetched messages kept for re-reads, across all connections; one connection keeps a quarter, at least 32 MiB |
| `-folder-list-bytes` | `96 MiB` | bytes of the message lists of open folders (32 bytes a message), across all connections |
| `-credential-held-bytes` | `48 MiB` | bytes of fetched messages one app password's connections may hold waiting to be written, together (at least 32 MiB) |
| `-memory-limit` | `400 MiB` | Go's soft memory limit (`GOMEMLIMIT`); keep it under the unit's `MemoryMax`. `0` leaves it alone |
| `-version` | | print the version and exit |

A wildcard listen address listens on IPv4 and IPv6. TLS is implicit only (RFC
8314): there are no plaintext ports and no STARTTLS. Signals: `SIGHUP` reloads
the sites and certificates; `SIGTERM`/`SIGINT` stop. A reload that finds a
site no longer enabled stops serving it at once — its name fails the TLS
handshake and its open connections are closed (IMAP with a `BYE`) — while
every other site's connections carry on.

It runs unprivileged. The only privilege it needs is binding 993 and 465,
which systemd gives it with `CAP_NET_BIND_SERVICE`.

## Sites

One file per site, `sites/<site>.json` in the config directory:

```json
{
  "site": "example",
  "hostname": "mail.example.com",
  "upstream": "https://127.0.0.1:443",
  "upstream_insecure_tls": false,
  "cert_file": "/etc/ssl/joinery/mail.example.com/fullchain.pem",
  "key_file": "/etc/ssl/joinery/mail.example.com/privkey.pem"
}
```

- **A site is served only while `sites/<site>.enabled` exists** (an empty
  file). That is the site's "Allow mail apps" switch; the root helper creates
  and removes it, then sends `SIGHUP`.
- **`hostname`** is the name programs connect to. The site is chosen by the TLS
  server name (SNI). A connection with no SNI gets the site only when exactly
  one is enabled; a name no enabled site has fails the TLS handshake.
- **`upstream`** is the site's loopback base URL — the same address the front
  proxy uses (`https://127.0.0.1:443`, or a container's published port such as
  `http://127.0.0.1:8081`). Every request carries `Host: <hostname>`; an https
  upstream is verified against `<hostname>` (SNI = hostname), so the site's own
  certificate works on loopback. `upstream_insecure_tls: true` skips the check,
  for an upstream whose certificate cannot name the hostname.
- **Certificates.** When systemd passes credentials named `<site>.crt` and
  `<site>.key` (`LoadCredential=`, read from `$CREDENTIALS_DIRECTORY`), those are
  used; otherwise `cert_file` / `key_file`.

A site whose file or certificate does not load is skipped with a log line; the
others are still served. A reload that cannot read the directory keeps the
previous set.

## What it sends the site

Every command becomes `POST {upstream}/api/v1/action/mail_apps/{action}` with a
JSON body that always includes `"mailbox": <the username the program gave>`,
and these headers:

| Header | Value |
|---|---|
| `X-Joinery-App-Username` | the username as given (the mailbox address) |
| `X-Joinery-App-Secret` | the password exactly as given — never parsed or judged here |
| `X-Joinery-Client-Ip`, `X-Forwarded-For` | the program's address (believed by the site only from loopback) |
| `X-Forwarded-Proto` | `https` |
| `Client-App`, `Client-Version` | `joinery-mail-gateway`, the version |

Answers: HTTP 200 `{"data": …}` is success. 422 `ActionError` is the site
refusing, with `data.imap_code` (`AUTHORIZATIONFAILED`, `NONEXISTENT`, `CANNOT`,
`UNAVAILABLE`, `ALREADYEXISTS`, `TOOBIG`, `CLIENTBUG`, `LIMIT`) put on the IMAP
reply as its response code, and `data.smtp_code` (`"454 4.7.0"`) used as the
SMTP reply, both with the site's `error` sentence. 401/403 (or any
`AuthenticationError`) means the credential is not accepted: a refused sign-in,
or — mid-session — the connection is closed. 429 becomes `NO [LIMIT]` / SMTP
`451 4.7.0` (`454` at AUTH) with the wait from `Retry-After`. 413 becomes
`NO [TOOBIG]` / `552 5.3.4`. A network failure, 404 (the plugin is off) or
502–504 becomes `NO [UNAVAILABLE]` / `451 4.3.0`; a 500 `NO [SERVERBUG]`.

## IMAP

Advertised: `IMAP4rev1`, `UIDPLUS`, `MOVE`, `IDLE`, `SPECIAL-USE`, `LITERAL+`,
`ENABLE`, `UTF8=ACCEPT`, `NAMESPACE`, `UNSELECT`, `CHILDREN`, `SASL-IR`,
`AUTH=PLAIN` (`LOGIN` works too). The greeting and the reply to a sign-in carry
`[CAPABILITY …]`, since Thunderbird never asks with `CAPABILITY`.

All response text the gateway sends is ASCII — its own and the site's
sentences alike (dashes, quotes and ellipses become their ASCII forms):
Thunderbird shows anything else garbled.

- **Sign-in** (`LOGIN`, `AUTHENTICATE PLAIN`) calls `mail_login`. The folder list
  it answers is kept: the program names folders, the site keys them. `INBOX` is
  case-insensitive; the delimiter is `/`; a parent a name implies but no folder
  provides (`Labels` for `Labels/Inbox`) is listed `\Noselect`. The fixed
  folders (INBOX, Sent, Drafts, Archive, Spam, Trash, All Mail) are
  `\Noinferiors`, so Thunderbird archives straight into Archive instead of
  creating a yearly `Archive/2026`; labels nest. (A fixed folder that already
  has a label listed under it says `\HasChildren` instead.) Special-use attributes come on a
  plain `LIST` too. Every folder counts as subscribed (`LSUB` answers them all);
  `SUBSCRIBE`/`UNSUBSCRIBE` are accepted and do nothing.
- **`SELECT`/`EXAMINE`** calls `mail_sync` for the whole list (number, flags,
  change number, date, size) and keeps it; sequence numbers are ascending UID
  order. `FLAGS` and `PERMANENTFLAGS` are `\Seen \Flagged \Answered \Deleted
  \Draft $Forwarded $Junk $NotJunk` — no `\*`, so a program keeps its own tags
  itself. (Thunderbird's Junk button stores `$Junk`/`$NotJunk` only when both
  are listed; the site takes them as the spam verdict.) `STATUS` is
  `mail_sync` with `status_only`.
- **New mail and changes.** After a command (at most every 5 seconds per
  connection) and every 30 seconds while in `IDLE`, the connection asks
  `mail_changes`; when the folder's highest change number moved, `mail_sync`
  `since_modseq` tells it what: `EXPUNGE` for numbers that vanished (held back
  while a `FETCH`, `STORE` or `SEARCH` is running, as IMAP requires), `FETCH
  (FLAGS)` for changed flags, `EXISTS` for new mail. **The poll is shared**:
  answers are reused for 25 seconds by every connection of the same credential
  on the same mailbox and folder, and concurrent calls are joined, so the five
  connections Thunderbird opens cost one poll. `DONE` ends an `IDLE` at once,
  even while its poll waits on the site (the poll completes for the cache). A `UIDVALIDITY` change closes the
  connection so the program starts the folder over.
- **`FETCH`.** `UID`, `FLAGS`, `INTERNALDATE`, `RFC822.SIZE` come from the list
  (a size the list does not have is asked of `mail_fetch what=header`).
  `ENVELOPE`, `BODY[HEADER]`, `BODY[HEADER.FIELDS (…)]` come from `mail_fetch
  what=header`; `BODY[]`, `BODY[TEXT]`, `BODY[n.m]`, `BODYSTRUCTURE` and partial
  `<offset.length>` from `mail_fetch what=full`, cut up with go-imap's helpers.
  Each call names every number still wanted (up to 1,000); the site answers as
  many as its time and size budget allows (12 MB of whole messages) and names
  the rest in `more`, which go round again — so Thunderbird's offline sync,
  `UID FETCH 1:5 (UID RFC822.SIZE BODY.PEEK[])`, is one call when the five fit.
  A number the site answers `gone` (or leaves out) is no longer in the folder.
  `BODY[]`, `BODY[HEADER]` and `BODY[TEXT]` are the exact bytes the site sent, so
  offsets and sizes agree. Reading a body without `.PEEK` sets `\Seen` — only
  for a message actually served. **A message the site answers `locked`,
  `unavailable` or `gone` is not served**: the command answers `NO
  [UNAVAILABLE] Mailbox locked - unlock at <link>` (or the reason) — never a
  stand-in. A refusal is remembered per connection: for 5 seconds it answers a
  repeat fetch without asking the site (Thunderbird retries a refused download
  several times in a row), and for 60 seconds a `STORE +FLAGS (\Seen)` on that
  number leaves `\Seen` alone (Thunderbird sometimes sends one right after the
  failed fetch; other flags pass through).
- **`STORE`** → `mail_store` (`add`/`remove`/`set`, only the listed flags);
  `COPY` → `mail_copy` with `COPYUID`; `MOVE` → `mail_move` with `COPYUID` then
  one `EXPUNGE` per moved message (the poll that follows does not repeat it); `EXPUNGE` / `UID EXPUNGE` / `CLOSE` → `mail_expunge`; `APPEND` →
  `mail_append` with `APPENDUID` when the site numbered it (the `UTF8 (~{n+}…)`
  form Thunderbird uses after `ENABLE UTF8=ACCEPT` included); `CREATE` / `DELETE`
  / `RENAME` → `mail_folder_create` / `mail_folder_delete` /
  `mail_folder_rename` (the site refuses renames, `NO [CANNOT]`).
- **`SEARCH`.** Flags, keywords, sequence and UID sets, dates (`SENT*` by the
  internal date), `LARGER`/`SMALLER`, `NOT` and `OR` are evaluated here from the
  list. Each text criterion (`TEXT`, `BODY`, `SUBJECT`, `FROM`, `TO`, `CC`, `BCC`,
  `HEADER Message-ID`) is one `mail_search` call; the answers are combined here.
  Any other `HEADER` field matches nothing.

**Message size.** A message travels to the site as base64 inside JSON, which
PHP's `post_max_size` (32M) caps, so anything over 23,000,000 bytes (about 30.7
MB as base64) is refused before it is read or sent: `APPEND` answers `NO
[TOOBIG] Message too large for this mailbox (limit 23 MB)` from the literal's
announced size, and SMTP advertises `SIZE 23000000`. `APPENDLIMIT` is not
advertised: go-imap would refuse an over-limit literal before reading it, and a
non-synchronizing one (`{n+}`, which Thunderbird sends) would then be read as
commands.

Limits: commands 50 KiB and non-`APPEND` literals 4 KiB (go-imap), messages as
above, connections per address, per credential and in total (flags), a sign-in slowed by a second
per recent failure from that address (up to 10 s, 60 in 15 minutes refuses
outright), a fourth attempt on a connection after three failures closes it,
and 30 minutes without a command logs out.

### Memory

One member with a valid app password can open many connections, and one
gateway serves every site on the host, so memory is bounded gateway-wide, by
four budgets:

- **In flight** (`-inflight-bytes`, 144 MiB). Every answer from the site is
  reserved before it is read — its buffer plus what it decodes to (base64
  decodes to three quarters) — sized from its `Content-Length`, or, for an
  answer of unknown length (as large PHP answers are), starting at 1 MiB and
  growing by a quarter at a time as it arrives (one answer is at most 64 MiB).
  Fetched messages (and header blocks) stay reserved, at their size, only until
  each has been written to the program — they are given back one by one, so a
  program that reads slowly holds just what it has not read yet. They also
  count, until written, against their credential's own budget
  (`-credential-held-bytes`, 48 MiB, shared by every connection signed in
  with that app password; a round reserves a likely 16 MiB before asking and
  settles to what came): a few slow connections on one app password wait on
  each other, and never take what other credentials and sites need. A
  folder's full listing at `SELECT` stays reserved until it has
  been turned into the compact list. A message a program submits or appends is
  reserved as it is read (an `APPEND` by its announced size, `DATA` as it
  grows) and streamed to the site as base64 rather than built in memory again.
  When the budget is spent a call waits up to 20 seconds, then answers `NO
  [LIMIT] The mail gateway is busy; try again shortly.` (SMTP `451 4.7.0`).
- **Kept messages** (`-body-cache-bytes`, 48 MiB). Shared by all connections,
  oldest out first; one connection keeps a quarter of it but at least 32 MiB
  (so the largest message still fits while a program reads it in pieces), and a
  message bigger than that is not kept at all.
- **Open folders** (`-folder-list-bytes`, 96 MiB). Each connection holds one
  entry per message of the folder it has open — 32 bytes (change number, date,
  size, UID and a flag bitmask; looked up by binary search), so a
  100,000-message folder costs 3.2 MB. The list is reserved at
  `SELECT`/`EXAMINE` for its capacity, grown (by an eighth, plus a little) when
  new mail arrives, and given back on `UNSELECT`, the next `SELECT`, or close.
  When the budget is spent, `SELECT` waits up to 5 seconds, then answers `NO
  [LIMIT] The mail gateway is busy; try again shortly.`; new mail that would
  grow a list waits for the next poll (nothing is lost — the poll asks again
  from the same change number).
- **Shared polls** (8 MiB, fixed). A poll answer kept for the connections of
  one credential is the compact form (32 bytes a changed message), kept only
  when it lists at most 2,000 changes and while this budget has room, and
  dropped after 25 seconds. A bigger answer is handed to the connections
  waiting for it and not kept.
- **Decoding.** Whole messages decode from the JSON straight into bytes (no
  intermediate string), and the raw answer is let go once decoded.

**The arithmetic, with the defaults:**

| | |
|---|---|
| In flight | 144 MiB |
| Kept messages | 48 MiB |
| Open folders | 96 MiB |
| Shared polls | 8 MiB |
| Connections: 2,000 × ~40 KiB (TLS state, buffers, goroutines, session; an idle TLS connection measured 35 KiB) | ~78 MiB |
| Go runtime, binary, sites, poll cache | ~15 MiB |
| **Total** | **~389 MiB** |

That is under the 400 MiB soft limit (`-memory-limit`, Go's `GOMEMLIMIT`,
which makes the collector work harder before memory is reached) and leaves
~120 MiB below the unit's `MemoryMax=512M`. (The per-credential budget
divides the in-flight one; it adds nothing.) Raising a budget or `-max-conns`
means raising `-memory-limit` and `MemoryMax` by the same amount.

### go-imap gaps

Built on `github.com/emersion/go-imap/v2 v2.0.0-beta.8`. Its server cannot:

- **`CONDSTORE`** — it parses neither `MODSEQ`/`CHANGEDSINCE` in `FETCH`,
  `UNCHANGEDSINCE` in `STORE`, `MODSEQ` in `SEARCH`, nor `(CONDSTORE)` on
  `SELECT`, and writes no `HIGHESTMODSEQ`. So it is not advertised, and a program
  re-reads flags with `UID FETCH 1:* (FLAGS)` on each `SELECT`. That costs the
  gateway-to-program link only: the site is asked for the full list on `SELECT`
  either way, since sequence numbers need it.
- **`ID`** — no `ID` command. Not advertised, so Thunderbird does not send it
  (an unknown command before sign-in closes the connection).
- `ENABLE` only enables `UTF8=ACCEPT` (and `IMAP4rev2`, which is not advertised).

Adding `CONDSTORE` and `ID` means a fork of `imapserver` (both need its
internal wire decoder); the gateway already holds every message's change
number from `mail_sync`, ready to answer them.

## SMTP submission

Port 465, implicit TLS, `AUTH PLAIN` only, checked by calling `mail_login` — a
wrong password fails at `AUTH` with `535 5.7.8`. Nothing is accepted before
sign-in (`530 5.7.0`). `SIZE 23000000` is advertised; a `MAIL FROM … SIZE=`
over it is refused by go-smtp (`552 5.3.4 Max message size exceeded`), and
`DATA` over it with `552 5.3.4 Message too large for this mailbox (limit 23
MB)`. (go-smtp stops reading an over-size `DATA` without draining the rest, so a
client that ignores `SIZE` sees its remaining lines refused as commands and the
connection closed; Thunderbird honours `SIZE`.) `MAIL FROM` and up to 500 `RCPT
TO` are collected, and the whole lot goes to `mail_submit`
(`{message: base64, mail_from, rcpt_to[]}`). The site sends it through the
mailbox's own path or refuses with its own reply (`454 4.7.0` locked, `550
5.7.1` not this mailbox, …). The gateway never relays anything itself.

## Logs

To stderr (the journal under systemd): connections, sign-ins (username, client
address, ok or fail), upstream errors, folder renumbering. Never message
content, passwords or other secrets.

## Under systemd

```ini
[Unit]
Description=Joinery mail gateway (IMAP 993 / submission 465 for mail apps)
After=network-online.target
Wants=network-online.target

[Service]
User=joinery-mailgw
Group=joinery-mailgw
ExecStart=/usr/local/bin/mail-gateway -config-dir /etc/joinery/mail-gateway
ExecReload=/bin/kill -HUP $MAINPID
AmbientCapabilities=CAP_NET_BIND_SERVICE
CapabilityBoundingSet=CAP_NET_BIND_SERVICE
NoNewPrivileges=yes
# One pair per enabled site; the gateway prefers these over cert_file/key_file.
LoadCredential=example.crt:/etc/letsencrypt/live/mail.example.com/fullchain.pem
LoadCredential=example.key:/etc/letsencrypt/live/mail.example.com/privkey.pem
ProtectSystem=strict
ProtectHome=yes
PrivateTmp=yes
PrivateDevices=yes
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectControlGroups=yes
RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX
MemoryDenyWriteExecute=yes
LockPersonality=yes
Restart=on-failure
RestartSec=2

[Install]
WantedBy=multi-user.target
```

systemd reads credentials when the service starts, so with `LoadCredential`
the certificate renewal hook restarts the unit (programs reconnect on their
own). With `cert_file`/`key_file` readable by the service user instead, the hook
only needs `systemctl reload` (`SIGHUP`).

## Trying it against dev on high ports

The mail_apps plugin must be active on the site and you need an app password
from the Mail apps page. Then, as yourself:

```bash
mkdir -p /tmp/mgw/sites
openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:P-256 -nodes -days 7 \
  -subj /CN=dev.getjoinery.com -addext subjectAltName=DNS:dev.getjoinery.com \
  -keyout /tmp/mgw/key.pem -out /tmp/mgw/cert.pem
cat > /tmp/mgw/sites/dev.json <<'EOF'
{"site":"dev","hostname":"dev.getjoinery.com","upstream":"https://127.0.0.1:443",
 "cert_file":"/tmp/mgw/cert.pem","key_file":"/tmp/mgw/key.pem"}
EOF
touch /tmp/mgw/sites/dev.enabled
../bin/mail-gateway-$(uname -m) -config-dir /tmp/mgw -imap-listen 127.0.0.1:1993 -smtp-listen 127.0.0.1:1465
```

and in another terminal:

```bash
python3 - <<'EOF'
import imaplib, ssl
ctx = ssl.create_default_context(); ctx.check_hostname = False; ctx.verify_mode = ssl.CERT_NONE
m = imaplib.IMAP4_SSL("127.0.0.1", 1993, ssl_context=ctx, server_hostname="dev.getjoinery.com")
m.login("you@your-mail-domain", "jm-xxxx-…")
print(m.list()); print(m.select("INBOX")); print(m.uid("FETCH", "1:*", "(FLAGS RFC822.SIZE)"))
m.logout()
EOF
```

## Building and testing

```bash
bash build.sh            # ../bin/mail-gateway-x86_64 and ../bin/mail-gateway-aarch64, static
go test ./...            # no network: a fake site (httptest) drives real go-imap and go-smtp clients
```

The tests run a full IMAP session (sign-in, list, select, header and whole
fetches, a locked message answered `NO [UNAVAILABLE]`, store, search, move,
expunge, append, create/rename/delete), `IDLE` noticing new mail with two
connections sharing the poll, changes and expunges made elsewhere, a wrong SNI,
and an SMTP submission (good send, `454 4.7.0` while locked, `550 5.7.1` for a
foreign From, `535 5.7.8` for a wrong password). `thunderbird_paths_test.go`
checks the wire the way Thunderbird 157 speaks it (Phase 0 rig): capabilities
in the greeting and sign-in replies, two-step `AUTHENTICATE PLAIN`,
`\Noinferiors` and special-use on a plain `LIST`, `LSUB`, `$Junk`/`$NotJunk` in
the flags, ASCII refusals, `\Seen` kept off a refused message, `APPEND` in the
`UTF8` form, one `EXPUNGE` per `MOVE`, offline-sync batching with `more`, `gone`,
and the 23 MB limit on `APPEND` and `DATA`.
