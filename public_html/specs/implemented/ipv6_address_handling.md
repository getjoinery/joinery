# IPv6 addresses are first-class everywhere the platform reads an address

**Status:** IMPLEMENTED 2026-10-18 (WP1-WP7). Verified by the test suite
(every touched suite green; db --changed 670/671, the one failure being the
agent bundle drift that clears at publish) and by reviewer2's code review
(F1-F16, all fixed and re-verified). The live checks at the end are in the
live verification queue. Drafted
2026-10-17; reviewed against the code 2026-10-18, Q1 decided by the owner
(option A); reviewed again by reviewer2 the same day (G1-G12, all folded
in); Q2 decided by the owner (option A). Built the same day; what building
found is under "What building found" at the end. Split out of the
bare-metal strategy note (outside git) as the part that stands on its own:
it applies to the fleet as it runs today, on Linode, and does not depend on
moving anywhere.

## The problem in plain terms

About half of the internet now reaches a web site over IPv6. Every site on
the fleet publishes an IPv6 address, and Apache answers on it (checked
2026-10-17 on dev and on a provisioned site). On the dev plane, 47% of the
request log since 2026-07-19 came from IPv6 addresses: nodes talking to the
plane, API calls, sign-ins.

Several of the platform's protections were written for IPv4. For a visitor
or a machine arriving over IPv6 they either do nothing or can be stepped
around, because one IPv6 subscriber holds a /64: 18 quintillion addresses,
each one "a different IP" to a check that counts addresses.

| # | What it protects | Today, for IPv6 | Where |
|---|---|---|---|
| B1 | A signed-in session against hijack from another network | Skipped entirely; the check compares IPv4 only (a TODO in the code says so). In both families its input can be forged: it reads `CF-Connecting-IP` and `X-Forwarded-For` from any caller, so a cookie thief who sends the victim's address in a header passes it | `SessionControl::_is_major_ip_change`, `SessionControl::_get_client_ip` |
| B2 | Sign-in, second factor, password reset, sign-up, vault unlock and the other forms that guard an account against guessing | Counts per exact address, so a caller rotating through its /64 is never limited | `RequestLogger::check_rate_limit`, from the logic files listed under D3 |
| B3 | A user's allowed sign-in addresses | Exact match is a text compare, so a differently spelled IPv6 address fails; CIDR match compares whole bytes, so a /52 or /60 admits more than written; wildcards are IPv4 syntax; the help text shows IPv4 only | `User::is_ip_allowed`, `User::ip_in_cidr`, `adm/admin_users_edit.php` |
| B4 | An API key's allowed addresses | Text compare of whole addresses, no prefixes, so a key cannot be pinned to an IPv6 subscriber at all | `ApiAuth` (`apk_ip_restriction`) |
| B5 | A ScrollDaddy DNS server's key, which works only from that server | Bound to the server's IPv4; a dual-stack server reaching a plane that has an IPv6 address is refused | `DnsResolverAccess::issueKey` |
| B6 | Mail we send arriving | A site that runs its own Postfix (receive mode) may send a bounce over IPv6, from an address with no reverse DNS and no SPF entry; receivers refuse it. The relay already pins sending to IPv4 | `install_email.sh` (no `smtp_address_preference`), `provision_relay.sh` (has it) |
| B7 | A mailbox owner's AI host on their own network, reached over plain http | IPv4 admits every private and CGNAT range; IPv6 admits only `::1` and `fc00::/7`, so a link-local `fe80::` host is refused as "use https" | `MailboxDeviceAiHost::hostIsPrivate` |

What is already right, and stays: every column that stores an address is
wide enough (`varchar(45)` and up, or `inet`); the API's app sign-in limit
already counts an IPv6 caller by its /64 (`ApiAuth::sourceNetwork`); the
`IpAddress` helper compares addresses as addresses; the SSRF guard judges
both families; a node joining the plane over IPv6 is matched to its record;
Cloudflare's IPv6 edge ranges are trusted beside the IPv4 ones, and
mod_remoteip on every host turns an edge request's `REMOTE_ADDR` into the
visitor's address (`host_housekeeping.sh` writes `RemoteIPTrustedProxy`
from the same ranges file), so the request log's raw `REMOTE_ADDR` is the
visitor's in both families; the firewall rules are paired or
family-neutral; the escrow address check compares as addresses.

fail2ban's Apache jails ban one address at a time, so a caller rotating
inside its /64 is never banned there. That is not changed here: the
platform's own attempt limits (D3) cover it, and nobody should expect
fail2ban to.

## What changes for people

- **A visitor on IPv6** gets the same session protection and the same
  sign-in limits as one on IPv4. Nothing visible.
- **An admin** can write an IPv6 address or prefix in a user's allowed
  sign-in addresses, and in an API key's address restriction, and it works.
- **An operator** of a ScrollDaddy DNS server records its IPv6 beside its
  IPv4; the key works from either.
- **A site owner** running their own mail never has a bounce refused for
  coming from an address nobody vouches for.

## Design

### D1. One definition of "the same network"

`IpAddress` (exists) gains three methods, and every check below uses them.
`ApiAuth::sourceNetwork` moves here so the unit is defined once. Moving it
closes a bug: today it reads an IPv4 address in IPv6 form as IPv6 and
takes its /64, which is `::/64`, so every such caller shares one app
sign-in bucket.

There are four other prefix matches in the tree. Three delegate to
`inPrefix`, so the Cloudflare ranges file and every allowlist are read one
way: `SessionControl::ip_is_cloudflare_edge`,
`JobCommandBuilder::is_cloudflare_domain` (which also reads the same
ranges file, `SessionControl::cloudflare_edge_ranges()`, over A and AAAA
records, in place of its own fetched IPv4 list) and
`UrlSafetyValidator::ipv6InCidr`. `InboundEmailSetupCheck::ip4InCidr`
stays: its SPF check is IPv4 by D6's design.

- `network($ip)`: the limiting unit, what one subscriber holds: the IPv4
  address itself; an IPv6 address's /64. Returns the canonical text.
- Every method reads an IPv4 address written in IPv6 form
  (`::ffff:192.0.2.1`) as the IPv4 address it is, so one machine never
  counts as two families.
- `inPrefix($ip, $cidr)`: bit-exact prefix match, either family, any
  spelling. A /52 admits exactly a /52.
- `matchesEntry($ip, $entry)`: one allowlist entry, which may be an
  address in any spelling (compared as an address), a prefix in CIDR form
  of either family, or the IPv4 wildcard form (`104.23.*`), kept so existing
  entries keep working. An entry of the wrong family is simply not a match.

### D2. The session check compares networks in both families

**What the check does when it fires.** `get_permission()` answers 0 and
closes the session's vault window, for every user; `check_permission()`
sends a user of permission 5 or more to the sign-in page. A member is not
signed out: they are treated as signed out for as long as the session
lasts, because the session's address is never rewritten. "Challenged" in
this spec means that.

**The address it reads.** Both the address stored at sign-in and the one
each request is compared with come from `get_client_ip(true)`, the mode the
API's address restriction and the agent channel already use: it honours
`CF-Connecting-IP` only from a Cloudflare edge and never honours
`X-Forwarded-For`. Behind Cloudflare that is still the visitor's address,
because mod_remoteip has already put it in `REMOTE_ADDR`. Today's default
mode takes either header from anyone, which makes the check worthless
against the one person it is for.

**The prefix lengths.** Today's rule, kept for IPv4: the check fires when
the first two octets change (a /16), which tolerates roaming inside one
ISP. The IPv6 counterpart is the /32: an ISP's allocation, inside which a
phone or a home connection may be handed a new /64 at any time. Comparing
/64s would log people out when their prefix rotates; comparing nothing, as
today, protects nobody. Both families are compared as binary prefixes,
never as text. Both lengths are a judgement, kept as two constants in one
place. Some large home ISPs hold allocations wider than a /32 (Comcast's
`2601::/20`, AT&T's `2600:1700::/28`), so a re-issued home prefix can cross
a /32 boundary where the same customer's IPv4 would stay inside its /16; if
that shows up in the `IP_VIOLATION` log lines, the IPv6 constant is the one
to widen.

**A change of family is not a change of network.** A browser on a
dual-stack connection may reach the site over IPv4 on one request and IPv6
on the next (Happy Eyeballs picks per connection). Nothing relates a
machine's IPv4 address to its IPv6 one, so the check compares only two
addresses of the same family and lets a family switch through, as it does
today. Challenging it would sign admins out over and over on an ordinary
home connection.

The session keeps one address per family. The address it signed in from
is never replaced. The first request from the other family is let through
and its address kept beside it (`ip_address_other_family`); after that each
family is compared with its own address, so a session that has seen IPv6
cannot then move between IPv6 networks unchallenged. The first appearance
of a new family still passes; nothing can relate the two. The first address
seen in the other family wins, whoever sent it: if a cookie thief reaches
the session over IPv6 before its owner does, the owner's first IPv6
request is the one challenged, and an `IP_VIOLATION` line then names the
owner's own address as the current one. The outcome is still right: the
owner signs in again, and signing in regenerates the session id, which
ends the thief's copy.

The code's own TODO about tightening IPv4 to /24 is a separate decision and
is not taken here; with the prefix length in one place it is a one-line
change later.

### D3. Limits that guard an account count by network; capacity limits count by address

Two kinds of limit share the request log today, and they need different
units:

- **Attempt limits** stop a stranger from guessing a secret, making
  accounts, or having mail sent to someone. A caller who rotates through
  its /64 must not get a fresh allowance per address, so these count by
  network.
- **Capacity limits** stop one caller from using up the server: the
  general API limit, uploads, the agent channel, previews, polls. These keep
  counting by address. Linode hands every server in a data center an
  address from one shared /64 (all seven fleet nodes seen calling the dev
  plane over IPv6 in October 2026 sit in `2600:3c03::/64`), so counting
  these by network would let one busy node use up every other node's
  allowance. An IPv6 caller can step around a capacity limit by rotating
  addresses; that costs the server load, not anyone's account.

`rql_request_logs` gains `rql_ip_network` (`varchar(45)`), written on every
row from `IpAddress::network()`, beside `rql_ip_address`, which keeps the
full address for the record. A new index on `(feature, network, time)` sits
beside the existing `(feature, address, time)` one, which capacity limits
still use. No backfill: the longest attempt window is an hour, and older
rows are never counted.

`RequestLogger` gains `check_attempt_limit()` and `attempt_limit_state()`,
the same arguments as `check_rate_limit()` and `rate_limit_state()`,
counting by network. The call site's choice of method says which kind of
limit it is. The attempt limits, each moved to the new method:

| Feature | Call sites |
|---|---|
| `login` | `login_logic` |
| `totp` | `verify_totp_logic`, `login_2fa_passkey_options_logic`, `login_2fa_passkey_verify_logic` |
| `totp_test` | `test_authenticator_logic` |
| `stepup` | `verify_stepup_logic` |
| `passkey_login` | `passkey_login_options_logic`, `passkey_login_verify_logic` |
| `password_reset` | `password_reset_1_logic`, `password_reset_2fa_logic`, `password_reset_totp_logic`, `password_reset_passkey_options_logic`, `password_reset_passkey_verify_logic`, `password_reset_2fa_passkey_options_logic`, `password_reset_2fa_passkey_verify_logic` |
| `password_reset_complete` | `password_reset_2_logic` |
| `register` | `register_logic` |
| `vault_unlock_passphrase`, `vault_unlock_recovery`, `vault_setup_passphrase` | their logic files |
| `recovery_readiness_verify`, `recovery_verify_send` | `security_logic`, `adm/logic/admin_recovery_readiness_logic` |
| `device_link_code` | `DeviceLink` (`data/device_links_class.php`) |
| `bug_reports` | `plugins/bug_reports/logic/report_submit_logic` (every report emails the operator) |
| `support_desk_open` | `SupportApi::limit` (a new thread can email the operator) |

App sign-in (`ApiAuth::refuseOverAppAccessLimit`) already counts by
network through its own key and is left as it is, with `sourceNetwork`
delegating to the helper (D1).

Staying per address, and why:

- Capacity: `api`, `api_upload`, `api_device_link` (a poll every few
  seconds by design), `api_agent`, `api_relay` (`RelayBirthEndpoint`),
  `api_broker` (`BrokerEndpoint`), `mailbox_preview`,
  `mailbox_direct_status`, `messenger_fresh_check`, `domain_setup_check`,
  and Joinery Direct's peer lookup.
- `api_auth`, the failed-auth limit in `apiv1.php`: once it is over, it
  refuses every request from its unit, including good ones. Counted by /64,
  one fleet node with a broken key would lock every node in its data center
  out of the API. API keys are long random secrets that rotation does not
  help anyone guess, so this one stays per address.
- `support_desk`, which counts refused support-desk calls (a wrong thread
  token): the same reason as `api_auth`. The token is a long random secret.

Limits keyed by something other than the address are not touched: the
per-user ones (`content_report`, `markdown_preview`, `mention_search`,
`forum_post`, `forum_upload`, `device_key_enroll`'s `api_device_link`, the
signed `api` session limit) and the per-node ones (`api_agent_node`,
`api_agent_artifact`).

The /64 stops one subscriber from rotating its way past a limit. It does
not stop a caller with several networks (a routed /56, a handful of
servers) from trying one account from each, in either family. So web
sign-in also counts failures per account (Q2), as app sign-in already does:

- Each failed web sign-in's log row carries the key app sign-in uses for
  the typed username, `user:` plus the first 32 hex characters of the
  SHA-256 of the lower-cased, trimmed email (`ApiAuth::appAccessBucketKeys`;
  the hash moves to one shared method so both paths compute it the same
  way).
- `login_logic` refuses when either count is over: 10 failures in 15
  minutes from the network (`check_attempt_limit`), or 10 failures in 15
  minutes for the typed email (`rate_limit_state` with that key). One row
  feeds both counts.
- The key is made from what was typed, whether or not an account has that
  email, and the refusal reads the same for both counts, so the limit says
  nothing about which emails have accounts.
- Passkey sign-in keeps its own count (`passkey_login`), so an account
  whose password sign-in is held by someone failing on purpose can still
  sign in with a passkey.

### D4. Allowlists compare as addresses, and accept prefixes

`User::is_ip_allowed` and the API key restriction both walk their entries
with `matchesEntry`. The API key restriction therefore accepts a prefix,
which it must for IPv6 to be usable at all: a subscriber's address changes
inside its /64, so a key pinned to one address is a key that stops working.
`User::ip_in_cidr` goes away.

**Entries are checked when saved.** With "an entry of the wrong family is
simply not a match", a typo is a silent lockout at the next sign-in. Both
pages (`adm/logic/admin_users_edit_logic.php`,
`adm/logic/admin_api_key_edit_logic.php`) refuse to save an entry that is
not an address, a prefix or an IPv4 wildcard, naming the bad entry: a
malformed address of either family, a prefix length out of range (`/33`
for IPv4, `/129` for IPv6), a zone id (`fe80::1%eth0`), brackets
(`[2001:db8::1]`). Empty items (a trailing comma, a blank line) are
dropped, not refused.

Help text on both pages shows one IPv4 and one IPv6 example each, says a
prefix is accepted, and says that a machine on a dual-stack connection
needs both of its families listed or it is refused whenever its browser
picks the missing one. `docs/api.md`'s "comma separated IPs" says the
same.

### D5. A DNS server key holds both of the server's addresses

The DNS server slot gains an IPv6 setting beside its IPv4 one, for both
slots (primary and secondary), declared in the plugin's `plugin.json`
beside the IPv4 ones. `issueKey` writes both into the key's restriction,
comma-separated, and refuses only when neither is set. Each setting is
validated for its own family (`FILTER_FLAG_IPV4`, `FILTER_FLAG_IPV6`), and
the admin page refuses an address pasted into the wrong box, so an IPv6
address in the IPv4 box cannot leave a key that works from neither. The
plane sees the server from whichever family its connection used; both are
the machine.

The DNS servers' fetch command lives in the ops guide, outside git; it is
unchanged by this.

`DnsResolverAccess::panelState()` warns when the slot's address and its
key's restriction disagree, so the key needs re-issuing. It compares the
set of addresses in the settings with the set in the restriction, as
addresses, so adding an IPv6 address to a slot shows the warning and a
re-issued key clears it.

### D6. Mail leaves every machine over IPv4

`install_email.sh` sets `smtp_address_preference = ipv4`, as
`provision_relay.sh` already does. The mail setup check reads it back and
names a site whose Postfix would send over IPv6. The setting is a
preference: a receiving server that publishes only IPv6 mail servers is
still reached over IPv6, as it is from the relay today. Every other
receiver gets our mail from an IPv4 address with reverse DNS and an SPF
entry. Sending over IPv6
(reverse DNS on the IPv6, `ip6:` in SPF, the check verifying both) is a
later spec if it is ever wanted; nothing today needs it.

### D7. A mailbox owner's AI host on their own network, in both families

`MailboxDeviceAiHost::hostIsPrivate` admits link-local `fe80::/10` beside
`::1` and `fc00::/7`, matching the IPv4 side, which admits every private
and CGNAT range and, for the same reason, IPv4 link-local
`169.254.0.0/16`. Both families use `inPrefix` in place of the IPv4 side's
own `ip2long` loop.

## Out of scope

- IPv6-only servers, NAT64 and a shared IPv4 edge: they belong to the
  bare-metal strategy note and `edge_routing_tier.md`.
- Reverse DNS on a node's IPv6, and SPF `ip6:` entries: not needed while D6
  holds.
- The relay dialling a site's mail gateway over IPv6: a design choice
  recorded in `RelayMapExporter`, unchanged.
- A node keyed by its IPv4 on join, and the IP swap leaving an AAAA record
  behind: both already handled or already noted on their pages.
- Analytics: the raw address is stored and never counted, so nothing to do.
- ScrollDaddy's setup instructions and device profiles
  (`activation_logic`, `mobileconfig_logic`) show the DNS server's IPv4
  only. D5's IPv6 setting is for the server's key; offering the IPv6 to
  devices as a resolver is a feature of its own, not an address check.

## Work packages

- **WP1. The helper.** BUILT. `IpAddress` 1.1: `network`, `inPrefix`,
  `matchesEntry`; `ApiAuth::sourceNetwork` and the three prefix matches in
  D1 delegate to it. Extends `tests/unit/ip_address_test.php`: every
  spelling, both families, prefixes that are not a multiple of eight bits.
- **WP2. The session check (D2).** BUILT. The address source, the prefix
  constants, both families.
- **WP3. The limits (D3).** BUILT. Column, index, `RequestLogger`'s two attempt
  methods, the call sites in D3's table moved to them, the per-account
  count in `login_logic` with the username key shared with `ApiAuth`. Two tests
  insert request log rows for attempt limits directly and fill
  `rql_ip_network`: `tests/account_security/login_test.php` and
  `tests/api/rate_limit_state_test.php`. (`attachment_preview_test` and
  `agent_channel_metering_test` insert rows for capacity limits, which
  count by address, and need nothing.)
- **WP4. Allowlists (D4).** BUILT. `User`, `ApiAuth`, validation on save in both
  admin logic files, the two help texts, the docs.
- **WP5. DNS server keys (D5).** BUILT. The settings, `issueKey`, `panelState`,
  the admin page and its per-family validation.
- **WP6. Mail over IPv4 (D6).** BUILT. `install_email.sh`, the setup check.
- **WP7. The AI host rule (D7).** BUILT. `MailboxDeviceAiHost::hostIsPrivate`.

WP1 first; WP2 to WP7 are independent of each other.

## Decisions

- **Q1 (owner, 2026-10-18): option A.** Only attempt limits count by
  network; capacity limits and the failed-API-auth limit stay per address
  (D3). Counting everything by /64 was refused because the fleet's nodes
  share one /64 per data center.
- **Q2 (owner, 2026-10-18): option A.** Web sign-in also counts failures
  per account and refuses for the window over either count, as app sign-in
  already does (D3). Accepted cost: someone who knows an account's email can
  hold its password sign-in refused by failing on purpose; passkey sign-in
  is unaffected. Asking for a second factor or a CAPTCHA over the limit
  instead of refusing (option B) can be added on top later. A growing
  delay was refused: it either holds a php-fpm worker for its length (dev
  runs five) or is a refusal under another name.

## Testing

| Check | Pass |
|---|---|
| a session created from `2001:db8:1::1` is used from `2001:db8:1:ffff::1` (same /32, different /64) | stays signed in |
| the same session is used from an address in a different /32 | challenged: an admin is sent to sign in; a member's `get_permission()` answers 0 and the vault window closes |
| a request carrying a forged `X-Forwarded-For` or `CF-Connecting-IP` from a peer that is not a Cloudflare edge | the session's stored address and the compared address are the real peer's; the request log records the real peer |
| ten failed sign-ins from ten different addresses inside one IPv6 /64 within 15 minutes | the eleventh is refused |
| ten failed web sign-ins for one email from ten different networks within 15 minutes | the eleventh for that email is refused; a sign-in for another email from those networks is not |
| the same, for an email that has no account | refused with the same message |
| an account whose password sign-in is over its count | passkey sign-in still works |
| a session created over IPv4, then used over IPv6, then over IPv4 again from the same /16 | stays signed in throughout |
| a session's address written as `::ffff:192.0.2.1`, used from `192.0.2.1` | the same network; stays signed in |
| API requests from many addresses inside one IPv6 /64, beyond one address's `api` allowance in total but within it per address | all accepted |
| ten failed `api_auth` calls from ten addresses in one /64 | ten units; the eleventh is accepted |
| a request log row from an IPv4 caller | its network equals its address |
| app sign-ins from `::ffff:192.0.2.1` and `::ffff:198.51.100.7` | two units, not one |
| `inPrefix` on `/0`, `/128`, an IPv4 `/32`, upper-case IPv6 | `/0` admits its whole family, `/128` and `/32` exactly one address, upper case matches |
| an allowlist entry `[2001:db8::1]` | refused at save by `entryProblem` (matching itself reads an address the way `IpAddress::binary` does, brackets tolerated) |
| saving a user's allowed addresses, or an API key's restriction, with `2001:db8::/129`, `fe80::1%eth0`, `[2001:db8::1]`, `10.0.0.300` | refused, naming the entry; nothing saved |
| an AI host at `http://[fe80::1]` | accepted as private, as `http://192.168.1.5` is |
| an allowed sign-in address written as `2001:DB8::1` while the request arrives as `2001:db8:0:0::1` | allowed |
| an allowed prefix of `2001:db8:abcd:1230::/60` | admits `2001:db8:abcd:123f::1`, refuses `2001:db8:abcd:1240::1` |
| an API key restricted to an IPv6 /64, called from another address in it | accepted; from outside it, refused with the address named |
| a DNS server with both addresses set, calling the plane over IPv6 through Cloudflare | its key works |
| a DNS server slot with only its IPv6 set | a key is issued and works from that address |
| an IPv6 address typed into the IPv4 box | refused at save |
| `panelState` with the key's two addresses in the other order or another spelling | no drift shown |
| a receive-mode site's Postfix after install | `postconf smtp_address_preference` says `ipv4`; the setup check is green |
| the full `safe` and `db` tiers | green |

## Docs to update when this lands

- `docs/account_security.md`: the session address check, where its
  address comes from, both families, the prefix lengths and what firing
  does to an admin and to a member; the allowed sign-in address syntax with
  an IPv6 example and the dual-stack note.
- `docs/api.md`: `ip_restriction` compared as addresses and accepting
  prefixes; which limits count by network and which by address.
- `plugins/dns_filtering/docs/overview.md`: a DNS server's key is bound to
  both of its addresses.
- `plugins/mailbox/docs/overview.md`: mail leaves every machine over IPv4.

## What building found

- **The index needs `--upgrade`.** `update_database` adds columns in its
  plain mode but builds declared indexes only with `--upgrade` (or
  `--cleanup`). Deploys run it with `--upgrade`; on dev it was run by hand.
- **Sign-in queries the new column, so dev's sign-in was down until
  `update_database` added it.** Any machine runs the release's
  `update_database` before serving the new code, so this is only a dev
  hazard.
- **`ApiAuth::restrictionAllows()`** is the API key restriction pulled out
  of `authenticate()` so it can be tested without a request; the DNS server
  keys' tests use it too.
- **`ApiAuth::sourceNetwork` is gone**, not delegated: its one caller calls
  `IpAddress::network()` directly.
- **`UrlSafetyValidator::ipInCidr`** (IPv4) went with `ipv6InCidr`, so the
  SSRF guard has no prefix match of its own.
- **`RequestLogger::username_key()`** holds the per-account key both sign-in
  paths use.
- **`IpAddress::sharePrefix()`** answers the session check (null when the
  families differ), beside `family()`, `network()`, `inPrefix()`,
  `matchesEntry()` and `entryProblem()`.
- **Every stored allowlist on dev passes `entryProblem`** (checked
  2026-10-18: one user, seven keys), so no saved entry stops matching.

- **Code review (reviewer2, 2026-10-18, F1-F16), fixed:** an IPv4-mapped
  prefix (`::ffff:0:0/96`) is refused at save (it admitted every IPv4
  address); an entry of stars alone admits both families, as it always did;
  partial-octet wildcards (`104.2*`) keep matching; the session keeps one
  address per family (D2); `is_cloudflare_domain` reads the one ranges file
  over both families; the IPv4-sending row asks only a live Postfix; the
  bug-report and new-thread refusals name a network for an IPv6 caller
  (`RequestLogger::attempt_unit_label()`); the IPv6 DNS setting refuses
  `:`-junk and an IPv4-mapped address; the allowlist on web sign-in reads
  the auth-grade address, as the API key restriction does.
- **Sign-in timing (review F13, older than this work):** an unknown email
  was answered faster than a known one with a wrong password, which said
  which emails have accounts. Web and API sign-in now spend a password
  check's time on a missing account (`User::spend_password_check()`).
- **A DNS server's IPv4 box holding something other than an IPv4 address**
  shows the problem in the panel and refuses a new key; the old key keeps
  working until re-issued.

## Live checks owed

- dev's Postfix reads `smtp_address_preference = any` until the mail
  installer runs again; the Setup tab's "Mail leaves over IPv4" row warns
  until then.
- A session on dev used over IPv6 from a phone or home connection stays
  signed in (the /32), and `IP_VIOLATION` lines stay quiet.
- A ScrollDaddy DNS server reaching a plane over IPv6 through Cloudflare,
  with both addresses on its key.

