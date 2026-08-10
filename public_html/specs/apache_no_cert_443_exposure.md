# When there is no certificate, Apache serves the whole site tree

**Status:** Fixed in `install.sh` 2.50, awaiting publish and a live re-gate.
Found 2026-08-10 on the first StackScript instance (50.116.60.22), deployed with
a domain that does not resolve. Confirmed by direct probe, root cause
identified, fleet swept.

**The fix is one change:** the main server's `DocumentRoot` moves off
`/var/www/html` to an empty `/var/www/unmatched` that denies everything. HTTP
behaviour, certificate issuance and the retry timer are all untouched — a box
with no certificate still serves HTTP and still gets a real certificate the
moment DNS points at it.

Owner decision, 2026-08-10: **IP-only is not a supported install pathway.** It
happens during setup, not as a way to run a site. Everything below that existed
to make `https://<ip>` pleasant — the self-signed certificate, the catch-all TLS
vhost — is therefore out of scope. The consequence is accepted: before a
certificate exists, `https://<domain>` gives a TLS protocol error. The site is
on HTTP until the certificate is issued, which is the documented behaviour.

## What is wrong, in plain terms

A Joinery box that has no TLS certificate answers on port 443 anyway — in
cleartext, from the wrong directory. Instead of the site, it serves the folder
that *contains* every site on the machine. That folder holds each site's logs,
config directory, backups and maintenance scripts: everything deliberately kept
above `public_html` so the web server would never reach it.

Nothing is listed, so an attacker has to guess names rather than browse. But the
names are not secret — they are the same on every Joinery install, and the site
directory is derived from the domain the deployer typed.

The site itself is unaffected. Port 80 is correct. This is entirely about what
answers on 443 when no certificate was issued.

## Evidence

From the first StackScript instance, deployed with `stest.getjoinery.com`, a
name with no A record — so the install deferred SSL and continued over HTTP,
which is the documented and correct behaviour.

```
https://50.116.60.22/                    TLS error: wrong version number
cleartext HTTP to port 443:
  GET /                                  200  "Apache2 Ubuntu Default Page: It works"
  GET /stest/logs/error.log              200  Content-Length: 0
  GET /stest/maintenance_scripts/install_tools/install.sh
                                         200
  GET /stest/config/                     403  (Options -Indexes)
  GET /stest/config/Globalvars_site.php  500  (executed by php-fpm, not dumped)
  GET /stest/public_html/                404

the same paths on port 80, including with an unmatched Host header:
                                         404  all of them
```

`/stest/` is the site directory: `_site_init.sh` derives the site name from the
first label of the domain, so it is a guess anyone can make.

Three things limit the damage, and all three are luck rather than design:

- `Options -Indexes` is set on `/var/www/html`, so the tree cannot be browsed.
- `a2enconf php${PHP_VERSION}-fpm` is global, so a `.php` file under any site is
  executed rather than dumped. `Globalvars_site.php` returns 500, not its
  contents. **A `.bak`, `.backup` or `.save` copy of that file would be served
  as text**, and upgrade paths have historically written such copies.
- On a brand-new box the error log is empty. It does not stay empty.

## Root cause

Three correct-looking decisions that combine into a hole:

1. **`a2enmod ssl` runs unconditionally** during server setup. Ubuntu's
   `ports.conf` wraps `Listen 443` in `<IfModule ssl_module>`, so enabling the
   module opens the port whether or not anything is prepared to serve TLS on it.
2. **The site's `:443` vhost is wrapped in `<IfFile .../fullchain.pem>`.** With
   no certificate the block does not exist. This is deliberate and good — it is
   what stops Apache refusing to start — but it means that on a no-cert box
   *nothing at all* claims port 443.
3. **Apache's main server has a compiled-in `DocumentRoot` of
   `/var/www/html`**, which is the parent of every site directory. A request to
   a port with no matching vhost is served by the main server. `a2dissite
   000-default` — which `_site_init.sh` does run — does not help, because the
   main server is not a vhost and cannot be disabled.

The comments in `install.sh` (lines 663, 685, 880) and in `arm_ssl_retry.sh`
describe a **self-signed fallback that does not exist in code**.
`provision_origin_cert` tries LE HTTP-01, then LE DNS-01, then returns 0 having
issued nothing. Had the fallback been real, the `<IfFile>` guard would have been
satisfied and this could not have happened. The comments should not be trusted
as a description of current behaviour.

## Why this never showed up before

Checked per topology rather than assumed:

- **Every existing install has a certificate.** The dev box, the scratch test
  box at 45.33.72.32 (verified: TLS answers, and cleartext to 443 gets a proper
  400), getjoinery and the Docker hosts (TLS terminated by the proxy vhost, same
  `<IfFile>` shape but with a cert present). Where a certificate exists the
  `:443` vhost exists, catches every request on that port, and the main server
  is never reached.
- **Port 80 was never at risk on a fresh install**, because `_site_init.sh`
  disables `000-default` and the site vhost is then the only `*:80` vhost. This
  is why a site with no cert still looked fine: everyone tests over HTTP.
- **The no-certificate state had never survived on a reachable box.** It is a
  transient during a hand-run install — the operator points DNS and re-runs SSL
  within minutes, usually while still logged in.

The StackScript is what turns that transient into an ordinary, persistent state.
A deployer who has not yet pointed DNS, or who supplies no domain at all, gets a
box that sits in this state indefinitely, unattended, on a public IP. The
mechanism is old; the exposure is new because the product now creates boxes that
live in the vulnerable state.

**One legacy exception, found while checking:** the dev box still has
`000-default` enabled (it predates the `a2dissite`), so locally
`http://127.0.0.1/joinerytest/logs/error.log` returns 200 with an unmatched Host
header. From the public internet the same request 404s, so it is not exposed,
but the box should be brought in line.

## What is at risk

On a no-cert box, over cleartext, to anyone who guesses the site name:

- `logs/error.log` — verbose, includes routing detail and any error text the
  platform writes. The single highest-value file here.
- Any non-PHP file under the site root: backups, dumps, `.sql`, `.tar.gz`,
  archived config copies, anything an operator has left in place.
- `maintenance_scripts/` in full. Not secret, but it maps the install.
- `config/` is 403 as a directory and its `.php` files execute rather than dump,
  so credentials do not leak *today*. That is one careless `.bak` away from
  being untrue, and it should not be the thing standing between us and a
  disclosed database password.

## Options

**A. Catch-all `_default_:443` vhost with a self-signed cert and an empty
DocumentRoot.** Something always claims 443, so the main server is never
reached; `https://<ip>` gives a certificate warning instead of a protocol error.
*Catch:* needs a cert to exist at install time (Ubuntu's snakeoil, or one we
generate), and adds a vhost whose only job is to say no.

**B. Always issue a self-signed certificate, so the site's own `:443` vhost
always exists.** The site is on TLS from first boot; the retry timer later
replaces the self-signed cert with the real one. The timer's stop condition is
already "issuer differs from subject", i.e. it was written expecting exactly
this. *Catch:* browser warning on an IP-only install, and a self-signed cert on
disk that must not be mistaken for a real one.

**C. Point the main server's DocumentRoot at an empty directory.** One line.
Does not stop 443 answering in cleartext, but removes the file-tree exposure
completely and keeps it removed for any future misconfiguration on any port.
*Catch:* on its own it leaves `https://<ip>` broken-looking.

**D. Do not enable mod_ssl until a certificate exists.** *Rejected.* If a
certificate ever appears while the module is off, Apache refuses to start on an
unknown `SSLEngine` directive — turning a cosmetic problem into an outage. It
also puts the retry timer in charge of module state.

**E. Firewall 443 until a certificate exists.** *Rejected.* Moves state into
`ufw`, needs an explicit teardown later, and a stale rule silently breaks HTTPS
after the certificate lands — failing in the direction where nobody notices.

**F. Deny-list the sensitive subdirectories (`logs`, `config`,
`maintenance_scripts`) in `apache2.conf`.** Cheap belt-and-braces, but a
deny-list only covers what we remembered to name, and the next directory somebody
adds is not on it.

## Decision: C alone

C is the security fix. It is structural, changes no behaviour anyone can see,
and means a fall-through to the main server — from this cause or any later one,
on any port — reaches an empty directory instead of the fleet's private files.

A and B were both about making `https://` behave well before a certificate
exists, which only matters if running on a bare IP is a thing we support. It is
not, so they are dropped rather than deferred. F is unnecessary once the main
server can serve nothing: a deny-list of directories we remembered to name adds
no protection over a root with nothing in it.

What shipped, in `install.sh` 2.50:

- `/var/www/unmatched` is created during server setup, and `apache2.conf` gets a
  `DocumentRoot` pointing at it plus a `<Directory>` block with `Require all
  denied`. Guarded by a marker so re-running `install.sh server` appends once.
- Comments in `install.sh`, `arm_ssl_retry.sh` and `setup_ssl.sh` that described
  a self-signed fallback now describe what the code does: try HTTP-01, then
  DNS-01, then return having issued nothing. The `issuer != subject` check in
  `arm_ssl_retry.sh` stays — an operator or an origin-certificate flow can still
  place a self-signed certificate at that path, so file-exists is still not a
  finish line.

## Tests

- **safe tier** (`installer_contract_test.php`): the main-server DocumentRoot is
  set away from `/var/www/html`; `a2enmod ssl` is still unconditional (so the
  fix is C, not D); the vhost template's `:443` guard is unchanged; and no
  comment claims a self-signed fallback that no code performs.
- **A live probe, and it must be a live probe.** Static checks cannot see a
  fallback that only exists once Apache has parsed a config with a missing file.
  Against a freshly built no-cert box: cleartext to 443 must not return 200 for
  `/<site>/logs/error.log`, and `/` on 443 must not be the Apache default page.
  This belongs with the StackScript gates, since building a no-cert box is
  exactly what those do.
- **A fleet sweep** — every managed node, both ports, unmatched Host header,
  asserting 404. Cheap, and the only thing that turns "we think only the new box
  was affected" into a fact.

## Docs to update

- `docs/deploy_and_upgrade.md` — what answers on 443 before a certificate
  exists, and what the self-signed cert is for.
- `docs/installation.md` — the StackScript path already tells deployers the site
  is on HTTP until DNS is pointed; it should say what `https://` does in the
  meantime.
- `specs/linode_stackscript.md` — gate B's acceptance criteria must include the
  443 probe, not just "a certificate eventually appears".

## Why an empty DocumentRoot is the mechanism, and what it does not cover

The change looks like pointing a real setting at a fake place. What makes it the
right lever rather than a trick is **when the main server is consulted at all**:
only for a port that no vhost claims. Where vhosts exist, the first one for that
address and port is its default and catches everything unmatched — which is why
`http://<ip>/` on a Joinery box serves the site rather than the default page.
So the blast radius of this change is exactly the case that is already broken:
a port listening with zero vhosts behind it. Nothing that works today goes
through the code path being changed.

It is also the least-coupled way to say it. `DocumentRoot` and `<Directory>` are
scoped to a filesystem path, so a vhost that sets its own root is untouched. The
URL-scoped alternatives — a server-level `<Location />` deny, a blanket
`RedirectMatch 404` — are inherited by every vhost and have to be undone in each
one, which is how a hardening line becomes an outage two years later.

Limits, stated so nobody reads more into it than it says:

- **An empty root is not "serves nothing".** `Alias` directives are independent
  of `DocumentRoot`, and Ubuntu enables `/javascript` →
  `/usr/share/javascript/` globally. Shared library files, no site data, but the
  fallback is not a closed door.
- **It does nothing while `000-default` is enabled**, because that is a vhost
  rooted at `/var/www/html` and it wins before the main server is reached.
  `_site_init.sh` disables it when a site is created; server setup now disables
  it too, so the window between "server prepared" and "first site" is covered.
- **A vhost that omits `DocumentRoot` inherits this one.** Every template we
  ship sets its own. An inherited empty root fails closed, which is the right
  direction, but it will look mystifying to whoever writes that vhost.
- **The directory has to exist.** It is created before Apache is told about it;
  a missing `DocumentRoot` is a startup warning, and on a container rebuild that
  skips server setup it would be absent. `AllowOverride None` on it also matters:
  the parent `/var/www/` grants `AllowOverride All`, so without it a stray
  `.htaccess` could re-open what the `Require all denied` closes.

## Fleet sweep, 2026-08-10

Every managed node plus the scratch box and the StackScript instance, probed on
port 80 cleartext, port 443 cleartext and port 443 TLS, each with a Host header
matching no vhost, then for site files under guessable directory names.

| Box | Result |
|---|---|
| 23.239.11.53 (8 sites) | clean — 443 cleartext answers `400`, no main server |
| 45.79.204.178 (jeremytunnell) | clean |
| 45.56.103.84, 97.107.131.227 (DNS) | clean |
| 45.79.215.171 (relay) | no web listener at all |
| 45.33.72.32 (scratch box) | serves the Apache default page on **:80** — this is `000-default`, still enabled, predating the `a2dissite`. A vhost, not the main server. No site files found. Not a managed node, and rebuilt routinely. |
| 50.116.60.22 (StackScript) | the reported exposure |
| dev box | `000-default` enabled locally, same legacy artifact; from the public internet both ports `404`, so not exposed |

The exposure was confined to the one box with no certificate. Every node in
service has one, which is exactly why this stayed invisible.

**No fleet remediation is needed**, and `upgrade.php` not touching Apache config
stays as it is. The two boxes carrying the legacy `000-default` are a scratch
box and the dev box; neither is a customer install, and both get the fix the
next time server setup runs.

## Still to do

- Publish, then rebuild a no-certificate instance from the StackScript and
  re-run the probe. The static checks cannot see this — only a real box can.
- Destroy 50.116.60.22, or keep it as the before-picture until the re-gate and
  destroy it after. It is serving an empty log file and its own copy of
  `install.sh`, on a box holding no data.
