# Slow requests: words to tell a slow server from a slow path

Status: IMPLEMENTED 2026-10-23, agent 1.93.0 (all five work packages), after reviewer2's spec review
(F1-F11, G1-G5) and code review (K1-K9), all taken. Verified on dev: unit, gate and Go tests; vhost 2.10 and
the FPM status drop-in live (durations in the access log, status pages 403 from outside); web_workers.sh read
dev's pool; an edge probe ran against getjoinery (job 175599). Live runs of net_health and web_workers on a
released node are in the live verification queue. What the build changed from the text is under *As built*.

## What happened, and what we could not see

The owner unstarred an email, declined a calendar entry and opened a message on
jeremytunnell.com. Each one hung, some for over a minute; the writes landed, the
replies never reached the browser.

What answered the question, by hand, from dev:

- 40 requests at the same moment down three paths: jeremytunnell through
  Cloudflare stalled 5 times (31 to 90 s, ending 520, 408 or a client timeout);
  getjoinery through Cloudflare 0; jeremytunnell pinned to its own address 0.
- Apache's error log (`site_log error`): `AH01075 Error dispatching request
  ... (reading input brigade)`, `(70007) The timeout specified has expired`: the
  request reached Apache and its 2-byte body never followed.
- `host_report`: one CPU, memory 52%, no OOM, web fail2ban jails empty.

Apache gives up on a missing body after 10 s (Ubuntu's `mod_reqtimeout`
`body=10,minrate=500`; reviewer2 reproduced it on dev: 408 and AH01075 at
+10.0 s). So of a 31 to 90 s stall, at most about 10 s is spent at Apache; the
rest is in front of it. That points at the path, which is what WP5 measures.

What we could not see, and what a root shell would have shown in a minute:

1. Whether the kernel or the network interface dropped packets or connections.
2. Whether Apache or PHP-FPM was out of workers at that moment.
3. How long each request took on the server, body wait included.
4. The machine's IPv6 address, the second path Cloudflare may take to it.
5. A side-by-side "through the edge vs. straight to the origin" timing, which
   had to be a shell loop of curl.

The read-only SSH attempt failed on the first key and the sshd jail then banned
dev's address, which is the case for words over shells in one line.

## As built (2026-10-23)

Where the build departs from the work packages below, the build is the answer:

- **WP1a.** `joinery_timed` puts `%D` after the byte count, not at the end of the
  line: fail2ban's `apache-badbots` anchors on the user agent being the last
  field. `default_virtualhost.conf` 2.10.
- **WP3, no new placeholder.** PHP-FPM serves its status page on a listener of
  its own (`pm.status_listen = /run/php/joinery-fpm-status.sock`), a fixed path
  whatever the PHP version, written by `host_housekeeping.sh` 1.31 into the pool
  of the version Apache hands PHP to (and removed from any other). The vhost's
  `/fpm-status` Location names that socket, so none of the template's six
  renderers changed. The Location is on the main site's `:80` host as well as
  `:443`: a site container may answer only on `:80`; the word asks https first,
  then http. The same housekeeping drop-in sets `process_control_timeout = 10s`
  (php_fpm_sizing_bare_metal.md's H1), so the reload that applies it, and every
  later one, lets requests in flight finish; the very first reload still runs
  under the old 0.
  A side effect: from outside, `/server-status` and `/fpm-status` answer 403
  where they answered 404, which says they exist and nothing else (proven
  through Cloudflare with a forged `X-Forwarded-For: 127.0.0.1`: still 403).
  **The first reload on each host after rollout still cuts** requests in
  flight: the running master applies the old 0 to that one reload before it
  re-reads its configuration. One cut per host at its first converge, and per
  container at its next start; the same kind of cut every PHP package update
  already causes, and every later reload is graceful.
  **The `[www]` section of `zz-joinery-status.conf` belongs to
  `php_fpm_sizing_bare_metal.md` once pools are per site:** a `[www]` section
  carrying status keys and no `listen` is a fatal pool error after `www` is
  renamed, and a reload under a bad configuration leaves PHP-FPM down. That
  spec takes the drop-in over (WP3 there).
- **WP1a, the access log's paths.** The safe shape is 64 characters, not 32
  (real routes such as `password_reset_2fa_passkey_options` are 34), and a
  segment of 20 or more characters with letters and at least two digits is cut
  too (a hex or base64url token fits the shape; route names carry at most one
  digit, as in `login_2fa_passkey_verify`). A route that puts a credential in its path still adds
  its prefix beside `/s/` in `accessPath`; the heuristic is the second line.
- **WP5, small.** One DNS resolution per run; the button disables on its first
  click; the verdict names an edge that answers the probe 403/503 and the
  machine never (it may be challenging the probe).
- **WP2.** The container refusal is in `net_health.sh`, by the same two markers
  `RunsInContainer()` reads; the plane also refuses a container site's node
  before asking.
- **WP5.** The probe is its own class, `EdgeOriginProbe`. It runs inside the
  node page's request, which reaches the management node through its own edge
  (100 s), so a request's ceiling is 10 s, a stall is 5 s, and no round starts
  after 45 s. The button shows for any node with a site and a public address of
  its own; the verdict says when the site's name resolves to the machine itself.
- **Found while building, fixed:** `NodeHealthProbe::run_and_record` wrote
  `mjb_start_time`/`mjb_end_time`/`mjb_error`, none of them job columns, so a
  probe job had no times and a failed one no reason; and **Re-run** of any probe
  job refused as "no steps". Both fixed (NodeHealthProbe 1.6, ManagementJob 1.43).

## Goal

From the node page, in two button presses, answer: **is the server slow, or is
the path to it slow, and if the server, which part?**

## Work packages

Five pieces, each independently shippable. WP1 and WP4 need no agent release.

### WP1. Every request carries a server-side duration; two request-log defects fixed

**1a. The access log records each request's duration.** The site vhost logs
with plain `combined` (`maintenance_scripts/install_tools/default_virtualhost.conf`,
`CustomLog ... access.log combined`), which has no duration. The template gains
a `LogFormat` named `joinery_timed`: `combined` plus `%D` (microseconds, from
the moment Apache read the request line to the last byte sent). `%D` includes
the wait for a body and the wait for a free PHP-FPM child, which is exactly
what PHP's own clock cannot see: PHP runs only after Apache has the body, or
has given up on it. Both `CustomLog` lines (`:80` and `:443`) use it. The
template's version line goes up; the fleet takes it the way vhost 2.09 reached
it.

`site_log` cannot read the access log today (`SITE_LOG_FILES` has no `access`).
It gains `access` in the next agent release, under the owner's log-access
switch like the others, and returns a **compiled shape**, never the raw line:
the node parses `joinery_timed` and sends only method, path, status, bytes,
duration (`%D`) and time. Rule 8 shapes each field:

- No client address, no Referer (it carries other sites' full URLs), no
  User-Agent, no query string.
- The path is kept as its segments, and any segment outside a compiled safe
  shape (letters, digits, `-`, `_`, `.`, at most 32 characters) becomes
  `<cut>`, because tokens ride in the path too: `/s/{token}` is a Drive share
  link whose token is the capability (`serve.php:128`), and signed file URLs
  carry their signature. `/s/` is always cut after the prefix whatever the
  segment looks like. Segments are never cut by count: an API action route is
  four segments (`/api/v1/action/drive_upload_init`) and stays readable.

Until that ships, the visitor's duration (`%D`) is on disk only. The
application's duration does not wait: after 1b, `log_table_tail requests`
already sends `rql_response_ms` (`observe_log_table_tail.go:82-84`), so the
per-action PHP time is readable from the node page with no agent release.

**1b. B5: `rql_response_ms` is null on every action row.** `serve.php:355`
includes the view inside the route closure, so `$api_start_time`
(`api/apiv1.php:114`) is local to that closure, and
`ApiLogicEndpoint::executeAction` (`includes/ApiLogicEndpoint.php:490`) reads
`$GLOBALS['api_start_time']` and finds nothing. Dev's last day: action rows
2,829 with 0 durations; `no_credential` 194/0; `app/navigation` 72/0; `form`
13/0; REST `post` and `get`, which log from inside `apiv1.php` itself, carry
theirs.

Fix: when the caller passes no `response_ms`, `RequestLogger::log` fills it from
`$_SERVER['REQUEST_TIME_FLOAT']`, only when `PHP_SAPI !== 'cli'` (a cron task's
row would otherwise carry the task's lifetime). Explicit values win (the
passkey reports measure their own). This is **PHP's** time: it starts after
Apache has the body, so it says how long the application took, never how long
the visitor waited. 1a is the visitor's number; this is the application's.

**1c. B4: a body that never arrived is logged as "too large".**
`ApiLogicEndpoint.php:386` treats `Content-Length > 0` with an empty body as a
body over `post_max_size`. When Apache gives up reading a body, PHP is handed
the same empty body with a small Content-Length, and the row reads
`RequestTooLarge`, "Content-Length 2 exceeds post_max_size 32M". Today that
label hid the real event twice.

Fix: compare `Content-Length` with `post_max_size` in bytes. Over:
`RequestTooLarge`, 413, unchanged. Not over: `RequestBodyIncomplete`, 400, "The
request's body did not arrive in full. Nothing was changed." The same split in
`AgentChannelEndpoint.php:500-503`. Its row carries about 0 ms under 1b, by
design; the access log line from 1a carries the ten seconds.

Tests: the byte comparison (`2` vs `32M`, `33554433` vs `32M`); a
Content-Length with a non-empty `php://input` (a `text/plain` body) passes
untouched; a logged action row carries a non-null `rql_response_ms`; a CLI
`RequestLogger::log` call stays null; the vhost template's two `CustomLog`
lines name `joinery_timed`.

### WP2. `net_health` (observe, machine, no parameters)

**Answers:** is this machine dropping packets or connections?

A script word like `listening_sockets` (`maintenance_scripts/sysadmin_tools/net_health.sh`,
verified against the signed manifest, no argv, no stdin). It reads, twice, ten
seconds apart, and reports the totals and the rate over the ten seconds:

| Field | Source | What it means |
|---|---|---|
| `listen_overflows`, `listen_drops` | `/proc/net/netstat` TcpExt | accept queue full: new connections dropped |
| `syn_drops` | TcpExt `TCPReqQFullDrop`, `TCPReqQFullDoCookies` | SYN queue full |
| `backlog_drops` | TcpExt `TCPBacklogDrop` | socket backlog full |
| `retrans_segs`, `timeouts`, `abort_on_timeout` | `/proc/net/snmp` Tcp `RetransSegs`; TcpExt `TCPTimeouts`, `TCPAbortOnTimeout` | the path is losing packets |
| `attempt_fails`, `estab_resets` | `/proc/net/snmp` Tcp `AttemptFails`, `EstabResets` | connections failing or reset |
| `softnet_dropped`, `softnet_time_squeeze` | `/proc/net/softnet_stat` columns 2 and 3, summed over CPUs | the kernel had no time to take packets off the card: where a one-CPU box loses them before TCP counts anything |
| `rx_dropped`, `rx_missed` | `/sys/class/net/<if>/statistics/rx_dropped`, `rx_missed_errors`, for each interface that carries a default route | the interface dropped them |
| `conntrack` | `/proc/sys/net/netfilter/nf_conntrack_count` and `_max` | count, max, percent; `absent` when the module is not loaded |
| `web_connections` | `ss -tn state all '( sport = :443 or sport = :80 )'` | counts by TCP state only |
| `listen_queues` | `ss -ltn` for :80 and :443 | each listener's current queue (Recv-Q) against its limit (Send-Q) |
| `uptime_seconds` | `/proc/uptime` | totals since boot need it to mean anything |

All seven TcpExt names exist on dev's 6.8 kernel (reviewer2 checked); a name
missing on another kernel is reported as not reported, never as zero (rule 11).
Counts only: no remote address, no port other than the two web ports, no
process. Capped well under 4 KiB. Timeout: one minute (ten seconds of sampling
plus two `ss` runs, each bounded at ten seconds in the script).

**Hostile-caller review.** The worst a compromised management node learns is
how busy the machine's web ports are and whether it is losing packets, which an
outside load test shows anyway. No parameter steers it; it writes nothing; it
reads `/proc` and `/sys` and runs `ss` with fixed arguments. No owner switch
(no log, no content); no redaction needed (numbers only).

**Where it runs.** Host only. Inside a site container the counters would be the
container's own network namespace, which is not where the edge connects. The
registry does not refuse a `Machine` word inside a container today
(`registry.go:259`, `RunnableNames`, only hides non-`Machine` words on a
siteless host), so the word itself refuses when the agent runs in a container
(`primitives.RunsInContainer()`, `container.go:10-12`, the same `/.dockerenv`
and `/run/.containerenv` test the join uses at `join.go:448`) and says to ask
the host's node. The
plane's button on a container site's node page targets its host's node.

**Node page:** a *Network* button beside *Listening ports*. Each rate renders
with a plain verdict: "No packets or connections dropped in the last 10 s", or
"Dropping new connections: 14 in 10 s (accept queue full)".

### WP3. `web_workers` (observe, machine, no parameters)

**Answers:** is the web tier out of workers right now, and has it been?

| Field | Source |
|---|---|
| `apache` | `mod_status` over loopback, `/server-status?auto`: busy, idle, `MaxRequestWorkers`, the scoreboard's counts by state (reading, writing, keepalive, closing) |
| `fpm` | `/fpm-status?json` over loopback: pool, `pm`, active, idle, max active, **listen queue** (the field that answers "out of workers"), max listen queue, max children reached |
| `fpm_max_children_events_24h` | count of "reached pm.max_children setting" in the PHP-FPM log for the last day: the history the live status cannot give |

Never `?full`: it lists each process's request URI and query string, which is
content (rule 8). `slow requests` is left out: it stays 0 unless
`request_slowlog_timeout` is set, which no host sets.

**Configuration, in the vhost template**, not a housekeeping drop-in. The
catch-all rewrite to `serve.php` lives in the site's `<Directory>` block
(`default_virtualhost.conf:66-67`), per-directory context, which runs after any
server-context rule; reviewer2 confirmed `/server-status` pinned to dev's
loopback returns 404 through the site vhost. And a built node has no
`000-default` to fall back on (`install.sh:3739` removes it). So, in the
template's **`:443` vhost only**:

- In the `<Directory>` block, before the catch-all:
  `RewriteCond %{REQUEST_URI} !^/(server-status|fpm-status)$` on the catch-all
  rule.
- `<Location /server-status> SetHandler server-status; Require local </Location>`
- `<Location /fpm-status> SetHandler "proxy:unix:{{PHP_FPM_SOCK}}|fcgi://{{SITE_NAME}}"; Require local </Location>`.
  The template names no PHP socket today (PHP runs under the global handler in
  `conf-enabled/php8.3-fpm.conf`), and the PHP version is in the socket's
  path, so `{{PHP_FPM_SOCK}}` is a new placeholder `install.sh` fills from the
  installed php-fpm version.
- `pm.status_path = /fpm-status` in that same pool, written by
  `host_housekeeping.sh` (the pool file is host configuration; the template
  cannot reach it), which finds the pool from the same installed version, so
  the two cannot name different PHPs.

The `:80` vhost's redirect to https (a vhost-context rule, which runs before
any per-directory rule or handler) is left alone: a loopback `http://` request
with the site's Host gets a 308. The word therefore asks over https:
`curl https://{SiteDomain}/server-status?auto` and `/fpm-status?json` with
`--resolve {SiteDomain}:443:127.0.0.1`, no certificate verification (loopback
to itself), `SiteDomain` from the agent's `ExecEnv.SiteDomain`
(`dispatch.go:38`). Apache speaks FastCGI to the pool: no `libfcgi-bin`, no
protocol client in bash.

On a bare-metal machine with several sites, each site has its own pool
(`php_fpm_sizing_bare_metal.md` WP3), and a site's `/fpm-status` reads only
that site's pool. The word reads each `zz-joinery-*` pool by name, through each
site's own vhost on this machine, so every pool on the box is reported.

**Where it runs.** Wherever the site's PHP runs. For a container site that is
the container's own Apache and PHP-FPM, so a container's agent answers it for
its site, and the host's agent answers for the host's own sites. On a siteless
host (a Docker host in machine posture) there is no site vhost from this
template and no PHP-FPM pool; its proxy vhosts are written elsewhere. There the
word answers `apache` and `fpm` as not reported, plus the FPM log count when a
log exists. A status endpoint for a proxy host's Apache is later work, named
here so the gap is known.

**Hostile-caller review.** A compromised plane learns worker counts: load, not
content. Both status pages stay `Require local`; the word adds no listener and
never asks for `?full`.

**Node page:** a *Web workers* button; the verdict line names a saturated tier
("PHP-FPM: 5 of 5 busy, 12 waiting; max children reached 41 times today").

A site container sizes its pool from its memory budget at start
(`tune_php_fpm.sh`, `_memory_plan.sh`); a bare-metal host does not, and runs
Ubuntu's default of 5. That gap is `php_fpm_sizing_bare_metal.md`'s.

### WP4. Kernel counts and machine addresses (no agent release)

- `host_report.sh`'s `kernel_events_24h` gains `conntrack_full` ("nf_conntrack:
  table full, dropping packet") and `syn_flood` ("Possible SYN flooding on
  port"), counted as `oom` and `enospc` are (`journal_event_count`, system
  journal, last 24 h).
- **Addresses, from what the plane already receives.** The agent sends
  `machineAddresses()` (container bridges and veths already dropped) on its
  join (`join.go:381/402`, kept by `AgentChannelEndpoint::reported_addresses`)
  and on every escrow state post (`escrow.go:536`, stored as `dre_addresses`).
  The plane copies the newest set onto the node row (`mgn_addresses`, a JSON
  list) whenever either arrives. No shell filter, no agent change; WP5 reads
  IPv6 from it. The join happens once and escrow posts come only from a node
  with an escrowed data root, so a node without escrow whose IPv6 changes
  keeps its join-time list. Acceptable for a probe target; the agent's poll may
  carry addresses later.

### WP5. Edge versus origin probe (plane side, no agent word)

**Answers:** is the slowness in the edge (Cloudflare or a relay) or in the
server? The comparison that settled today's question, done by the plane.

`NodeHealthProbe::edge_vs_origin($node, $pairs = 20)`, beside the existing
`http()` probe:

- For each pair, at the same moment (`curl_multi`): one request to the site's
  public URL, and one pinned (`CURLOPT_RESOLVE`) to each of the node's own
  addresses (`mgn_host`, plus each IPv6 in `mgn_addresses`).
- Two kinds of request: a GET of `/login` (the cheapest page every site serves,
  no session), and a POST of `{}` to an action that refuses it without a
  session (`vault_heartbeat` answers 400 in about 0.1 s). Both, so a body-read
  stall like today's is seen.
- Each request has a 20 s ceiling; one that reaches it counts as a stall.
- **The pinned leg verifies neither name nor chain** (`VERIFYPEER` and
  `VERIFYHOST` both off, on that leg only). An origin behind an edge may present
  a Cloudflare Origin CA certificate, which no public chain trusts, or Apache's
  default-vhost fallback certificate, the case `NodeHealthProbe.php:182`
  describes. It is a timing measure; nothing is acted on. The result names the
  certificate the origin presented (subject and issuer), so a wrong one is seen.
- **Identifies itself.** A `User-Agent` of `JoineryEdgeProbe/1` (the file sets
  none today, so the probe would arrive as curl's default), and the site's
  visitor-event recording skips that agent, so a run does not land as 40
  visitor events.
- **Stays under the site's own limit.** The POST leg arrives with no credential
  and counts against the keyless per-address limit (`api_rate_limit_requests`,
  1,000 an hour, `settings.json:879`; logged as `no_credential`,
  `ApiAuth.php:534`). A run is 20 pairs times (1 + addresses) legs, 60 to 120
  requests. The button allows 10 runs per node per hour, and a 429 is reported
  as "rate-limited by the site", apart from errors. Each run also adds its POST
  legs to the node's request log as `no_credential` rows, which the result
  says.
- Result, per path: requests, p50, p95, stalls, errors by status, 429s. One
  verdict line: "Slow through the edge, fast direct (5 of 40 stalled through
  Cloudflare, 0 of 40 direct)", or "Slow on the server (both paths p95 over
  3 s)", or "No problem seen".
- Recorded as a completed management job like `run_and_record`, so it sits in
  the node's job history beside the other checks.

**Node page:** a *Compare edge and direct* button in the Health block, shown
for nodes whose public name does not resolve to their own address (fronted
nodes), since for a direct node the two legs are one path.

## What is not in this spec

- Fixing today's stalls. Their cause is still open; these words are how it gets
  found.
- A plane-side alert on any of these numbers. The words answer when asked;
  turning a reading into an incident is a later decision.
- The Cloudflare zone's own settings (an IPv6 origin record, HTTP/2 to origin).
  Reading those is a Cloudflare API job, not a node word; noted for the DNS
  management work.

## Order

WP1 first (platform and vhost template; two defects and the duration every
request lacks). WP4 and WP5 next (no agent release; together they answer
today's question). WP2, WP3 and `site_log access` ride the next agent release.

## Open questions

None. (Sizing PHP-FPM on bare-metal hosts moved to
`php_fpm_sizing_bare_metal.md`.)

Settled in review: Q1 of round 1 (addresses come from join and escrow, WP4);
Q2 of round 1 (Apache's proxy handler speaks FastCGI, WP3); Q3 of round 1 (the
rewrite does take `/server-status`; the guard goes in the template's
`<Directory>`, WP3). Q1 of round 2 (the access log goes as a compiled shape
with unsafe path segments cut, WP1a).

## Tests

- WP1: as listed under 1c, in `tests/unit/`.
- WP2, WP3: Go registration tests in `running_list_words_test.go` (class
  observe, machine, no params, script path); script tests over fixture
  `/proc/net/netstat`, `softnet_stat` and status outputs; `net_health` refuses
  in a container; `machine_vocabulary_test.go` list.
- WP4: `host_report` fixture test for the two new counts; a plane test that a
  join or escrow post updates `mgn_addresses`.
- WP5: the verdict over fixed timings, 429s counted apart; the run cap; a live
  test against dev (fronted by Cloudflare) that both legs return.
