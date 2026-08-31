# Managed domain over the channel — the last direct SSH in server_manager

**Status: BUILT 2026-08-31 — both primitives in agent 1.14.0, plane side
converted, both test suites rewritten to the job shape, db tier green.
Acceptance 11 (the notice rendering on a real node from settings pushed over
the channel) has not run; the spec stays here until it has. Written
2026-08-31, reviewed by `public-html-c3` the same day; its findings F1-F9 are
folded in below.**

> Annex. Status and ordering live in `agent_management_first_principles.md`.
> Follows `check_status_without_ssh.md`, which crossed the first operation off
> SSH. This one crosses off the only remaining SSH that is not part of the
> bootstrap window or the disposable-machine problem.

## What a managed domain is

Someone buys hosting from us and, in the same checkout, types the domain name
they want. They pay once. What arrives is a working site at their own name,
with working email at that name, and nothing for them to configure. They never
open a registrar account, never copy a nameserver, never paste a DNS record.

Delivering that means someone has to buy the name on their behalf, point it at
the box we just built for them, and set up the box to receive mail there. That
someone is us. `specs/managed_domain_registration.md` is the feature; two
classes in `plugins/server_manager/includes/provisioning/` do the work, both
running as phases of the `ServerManagerAdvanceProvisioning` scheduled task:

- **`ProvisionManagedDomains`** (751 lines) carries a new domain from paid to
  live: register it at the registrar, publish apex and www pointing at the
  box's IP, make the box mail-ready and publish the mail records it asks for,
  set reverse DNS, mark active.
- **`ManagedDomainWatch`** (539 lines) then counts it down. The buyer is the
  legal registrant from day one, but the name sits in *our* registrar account,
  so its renewal bills *us* — and the platform never renews a customer's domain
  and never fronts the cost. It has to move into the buyer's own account before
  it expires. Six months before that date, and not one day sooner, the buyer
  starts seeing a notice on their own site telling them so.

Both of those steps need the plane to reach into the box. That reach is what
this spec is about.

## The two reaches, and why they are SSH

Everything else this pipeline does is against the registrar or the DNS
provider — an HTTPS API call from the management node. Only two things happen
on the customer's box, and both are done by `proc_open(['ssh', ...])` straight
from PHP:

### Reach 1 — ask the box what mail DNS it needs

`ProvisionManagedDomains::prepare_on_node()` (line 619) runs
`plugins/mailbox/utils/managed_domain_prepare.php <domain>` on the node and
reads one JSON line back:

```json
{"ok":true,"dkim_ready":true,"records":[{"type":"MX","name":"@","value":"...","priority":10}, ...]}
```

The split is deliberate and correct: the management node owns the registrar and
the zone, but the *box* owns everything that decides what belongs in that zone —
its receive topology, its SPF shape, its DKIM key, whether it speaks Joinery
Direct. A management node that computed those records itself would publish a
plausible set the box does not match, and the mismatch shows up as mail
silently failing authentication. So the box prints desired state and the plane
publishes it. Nothing about that split changes here. Only the transport does.

The utility is also idempotent by design — it registers the domain for
receiving, ensures a DKIM key exists, and prints the plan; re-running it for a
prepared domain changes nothing and just reprints.

### Reach 2 — write the four notice settings onto the box

`ManagedDomainWatch::pushBannerState()` (line 395) writes four core settings on
the node — `managed_domain_name`, `managed_domain_expiry_time`,
`managed_domain_state`, `managed_domain_manage_url` — which
`includes/ManagedDomainNotice.php` renders into the take-ownership notice. All
four are declared `managed: true` in `settings.json`, meaning they are kept off
the node's own settings page: the management node is their only author.

`ProvisionManagedDomains::push_banner_state()` (line 534) is the same push,
called once at activation with an empty state, so the box holds the facts and
says nothing until the watcher pushes a custody state six months out.

The command it sends is `buildBannerCommand()` (line 413), and it is the worst
thing in this pipeline:

```
docker exec -i <container> bash -c '
  set -e
  CFG=/var/www/html/<sitename>/config/Globalvars_site.php
  DB_NAME=$(grep dbname $CFG | head -1 | cut -d";" -f1 | cut -d"=" -f2 | tr -d " " | sed "s/^.//;s/.$//")
  DB_USER=$(grep dbusername $CFG | ...)
  export PGPASSWORD=$(grep dbpassword $CFG | ...)
  psql -q -U "$DB_USER" -d "$DB_NAME" <<JOINERY_SQL
  INSERT INTO stg_settings (stg_name, stg_value) VALUES
    (...) ON CONFLICT (stg_name) DO UPDATE SET ...
JOINERY_SQL
'
```

Four things wrong with it, each independently sufficient:

1. **It scrapes the site's database password out of a PHP config file with
   `grep | cut | cut | tr | sed`** and puts it in the environment. The password
   parse is five string operations deep and fails silently into an empty
   variable on any config file whose formatting differs.
2. **It writes settings as hand-built SQL** with hand-escaped quotes
   (`str_replace("'", "''")`), bypassing `Setting::put()` and therefore
   bypassing the declared-settings gate that is the only thing stopping a typo
   minting a row nothing reads.
3. **It is told the site name by the plane** — `basename(dirname(web_root))`
   computed from a column in the plane's own database, interpolated into a
   filesystem path. `operate_run_plugin_installers` already rejected exactly
   this: *a node told its own name by a remote party is a node whose identity is
   only as correct as a row someone else can edit — and, since the name becomes
   a filesystem path, only as safe.*
4. **The SSH runner is duplicated.** `ManagedDomainWatch::runSsh()` (line 480)
   and `ProvisionManagedDomains::run_on_node()` (line 657) are byte-identical
   30-line copies of the same `proc_open` block.

## Why this is the right next operation

**It is invisible to everything we have built.** The agent stopped accepting
`ssh` and `scp` steps in 1.13.1 (`runner.go:197`), which is on all nine paired
nodes. That refusal is the tripwire that forces operations onto the primitive
vocabulary — and it cannot reach these two, because they never enter the job
system at all. They are PHP shelling out. Nothing in `JobCommandBuilder`,
`transports_for()`, `can_run()` or the SSH-only inventory test knows they exist.
No mechanism will ever make them move on their own.

**Everything it needs already exists.** These run against *paired* nodes — the
box was installed by the same pipeline — so the channel is available today. No
bootstrap chicken-and-egg, unlike `install_node`, `enable_agent` and unpaired
`provision_ssl`. No settled policy in the way, unlike the five relay operations.
No destructive gate to wait for, unlike `decommission_node`.

**The seams are already there.** Both classes route their SSH through a single
overridable method, put there so tests could intercept it. The swap is two
method bodies.

**The phase already tolerates waiting.** `ProvisionManagedDomains::advance()`
does one step per tick and returns; an unstamped step is simply retried on the
next tick. That is what makes the synchronous-to-asynchronous conversion small
instead of structural — and `ProvisionPendingSsl::advance_primitive_ssl()` has
already proven the pattern on the SSL chain.

## Design

### Two new primitives

Following `operate_run_plugin_installers`, which is the closest precedent: the
plane sends the smallest vocabulary that expresses the operation, and the node
supplies everything it already knows about itself.

Both are `ClassOperate`, and for prepare that is not a judgment call.
`registry.go:32` defines `ClassObserve` as *reads: collectors, status, list. No
state changes* — and preparing a domain registers it for receiving and mints a
DKIM key. `policy.go` lets a node accept classes rather than primitives, so a
node whose policy accepts only `observe` has to be able to trust that an
`observe` primitive writes nothing.

**`operate_managed_domain_prepare`**

| | |
|---|---|
| Params | `domain` — string, required, `Pattern: ^[a-z0-9.-]+\.[a-z]{2,}$`, `MaxLen: 253` |
| Script | `/usr/bin/php`, `public_html/plugins/mailbox/utils/managed_domain_prepare.php` |
| Args | `["{domain}"]` |
| Stdin | none |
| Timeout | 5 minutes |

The utility lives inside a plugin, so it verifies against the mailbox plugin's
signed manifest before running. No site name, no web root, no credentials: the
agent resolves its own `SiteRoot`, and the utility reads the site's own database
through the platform.

**`operate_managed_domain_notice`**

| | |
|---|---|
| Params | `domain` — same pattern and bound as above<br>`expiry_time` — string, `Pattern: ^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$`<br>`state` — enum over `operator_managed`, `push_requested`, `push_sent`, `self_custody`, `''`<br>`manage_url` — string, `Pattern: ^https://[A-Za-z0-9.\-/_]+$`, `MaxLen: 512` |
| Script | `/usr/bin/php`, `utils/managed_domain_notice.php` (new, core) |
| Args | none |
| Stdin | `StdinFrom` — a JSON object built from the four validated params |
| Timeout | 1 minute |

**The setting names are compiled into the node-side script, not sent.** The
plane supplies four values; it cannot express which settings they land in. A
generic write-a-setting primitive would hand a compromised plane the whole
`stg_settings` table, and the declared list is a security boundary, not
documentation. `state` is a `ParamEnum` over the exact four custody states plus
empty — `operator_managed`, `push_requested`, `push_sent`, `self_custody`, `''`
— so an unrecognised state is refused at the node rather than rendered.

`utils/managed_domain_notice.php` reads the JSON object on stdin and calls
`Setting::put()` four times. That restores the declared-settings gate the raw
SQL bypassed, and drops the password scrape entirely: the script is already
inside the site, so it has a database connection.

**Every string is bounded, including `manage_url`.** It is the one plane-supplied
value that renders as a live link on every customer's admin notice, which makes
it the only new plane-to-node influence this design introduces — so it is
pinned to `https://` and a length rather than left at `DefaultMaxLen`.

Both timeouts sit under `ManagementJob::CLAIM_TIMEOUT_SECONDS` (900), so
neither primitive needs a `PRIMITIVE_CLAIM_BUDGETS` entry and
`primitive_transport_parity_test`'s budget-at-least-timeout rule is met by the
default. Do not add one.

### Plane side

Two builders in `JobCommandBuilder`, primitive-only, no SSH sibling:

```php
public static function build_managed_domain_prepare_primitive($node, $params)
public static function build_managed_domain_notice_primitive($node, $params)
```

A node without the primitive gets an exception naming what is missing, the same
shape `check_status` now throws. No SSH fallback is written — writing one would
recreate the thing this spec removes.

Two result processors in `JobResultProcessor`:

- `process_managed_domain_prepare` parses the JSON line out of `mjb_output`,
  the way `process_list_backups` already does, and stores it on the job.
- `process_managed_domain_notice` needs only completed-or-failed.

### The synchronous-to-asynchronous conversion

This is the only real work in the spec. Today `prepare_on_node()` blocks and
returns the payload inline. Over the channel the answer arrives later.

`ProvisionManagedDomains::mail_dns()` becomes a four-state check:

1. **A completed, unconsumed prepare job for this domain exists** → read its
   payload, publish the records, **mark the job consumed**, and stamp
   `rdm_dns_mail_time` (or, on `dkim_ready: false`, publish and deliberately do
   not stamp, exactly as now).
2. **A prepare job is queued or running** → return 0, wait for the next tick.
3. **The last job is consumed or failed, and `PREPARE_RETRY_GAP_MINUTES` has
   passed since it finished** → dispatch a new one, return 0.
4. **No job at all** → dispatch one, return 0.

**A completed job is consumed exactly once, and that is load-bearing.** Without
it, the two paths that deliberately do not stamp — `dkim_ready: false` and
`ok: false` — leave state 1 true for the same job forever: the same payload is
re-read and the same records re-published every tick, and a new job is never
dispatched. The `dkim_ready: false` case would then never come back for the
signing key, which is the one thing that path exists to do, and the `ok: false`
case would park the row with no path back — the exact failure the *transient in
every case* comment forbids. Mark it the way `ManagedDomainWatch::mark_alert_sent()`
already marks jobs, as a `consumed_time` key folded into `mjb_parameters`.

**The lookup filters on the domain, not just the node.** `ProvisionPendingSsl::chain_jobs()`
filters by node alone, which is correct there because one node has one
certificate — but a shared host carries many managed domains, as
`set_ptr()` says in its own comment. So the query filters
`mjb_parameters->>'domain'`. `createPrimitiveJob()` writes `mjb_parameters` as
well as the `mjb_commands` envelope, so the value is there to filter on and no
new column is needed on the domain row.

A builder exception — the node lacks the primitive — is caught inside
`mail_dns()` and written to `rdm_error` like every other transient path there.
Letting it propagate to `run()`'s per-row catch would retry the row correctly
but leave the Domains admin page with nothing to show about why it is parked.

### The banner push converges rather than fires

**Correction to an earlier draft of this spec, which claimed `pushIfLive()`
returns a bool nobody branches on.** It does: `push_prompt()` stamps
`rdm_prompt_pushed_time` only when the push returns true. Made
fire-and-forget, true would mean *queued*, so a job that then failed — agent
down that hour, node missing the primitive — would record the prompt as shown
when it never was. That prompt is the buyer's first mention of a deadline that
takes their site and their email with it if they miss it.

So the watcher becomes a desired-state check instead of four push sites:
compute the four values from the row, compare them against the last **completed**
notice job for this node and domain, and dispatch only when they differ or none
exists. `rdm_prompt_pushed_time` is then stamped from the first completed notice
job carrying a non-empty state.

That single check replaces all four current push sites — activation,
`refresh_expiry`, `check_custody` and `push_prompt` — and a failed push
self-heals on the next tick rather than leaving stale values on the customer's
box until the next expiry change happens to trigger another push. The primitive
itself does not change.

### What gets deleted

- `ManagedDomainWatch::runSsh()` and `buildBannerCommand()` (≈75 lines)
- `ProvisionManagedDomains::run_on_node()` and the shell composition inside
  `prepare_on_node()` (≈50 lines)
- `provision_managed_domains_test.php` and `managed_domain_watch_test.php` both
  override the SSH seams and assert on the shell command — the watch suite
  asserts `PGPASSWORD` is *in* the pushed command. Both suites are rewritten to
  the job shape.

That leaves **zero direct SSH in server_manager**. It does not leave zero SSH in
the platform: `plugins/mailbox/includes/RelaySsh.php` runs `exec()` against the
relay and has callers in six files (`RelayCloudProvisioner`, `RelaySpoolConsumer`,
`RelayMapSync`, `FleetClient`, `mailbox_relay_class`, `relay_admin`). That is the
relay problem — out of scope here, and closed to agenting by settled policy.

## Acceptance

1. `operate_managed_domain_prepare` and `operate_managed_domain_notice` are
   registered, and their Go tests assert the parameter bounds — in particular
   that the notice primitive declares no parameter through which a setting name
   could arrive.
2. `grep -rn 'proc_open.*ssh' plugins/server_manager` returns nothing.
3. A managed domain reaching the mail-DNS step dispatches a prepare job,
   parks, and publishes the records on the following tick.
4. `dkim_ready: false` still publishes without stamping, and the next tick
   dispatches again.
5. A node whose agent lacks either primitive writes a transient `rdm_error`
   naming the missing primitive, visible on the Domains admin page, and the row
   is retried rather than failed.
6. The four settings land through `Setting::put()`; an undeclared name is
   refused by the gate rather than written.
7. **A completed prepare job is consumed once.** Two consecutive ticks over a
   `dkim_ready: false` payload dispatch a second job rather than re-publishing
   the first one's records.
8. **`rdm_prompt_pushed_time` is stamped from a completed notice job, never
   from a dispatch.** A notice job that fails leaves the row unstamped and the
   next tick re-dispatches.
9. **The prepare lookup is domain-scoped.** Two managed domains on one shared
   host advance independently.
10. `provision_managed_domains_test.php` and `managed_domain_watch_test.php` are
    rewritten to the job shape — the watch suite's `PGPASSWORD` assertion
    (line 156) and the empty-command-for-a-bare-node assertion (line 173) both
    describe a command that no longer exists.
11. The notice renders on a real node from settings pushed over the channel.
12. safe and db tiers green.

### Doc residue to clear in the same change

Two headers describe the transport being removed, and docs read as end state:
`plugins/mailbox/utils/managed_domain_prepare.php` says the utility is *called
over SSH*, and `ProvisionManagedDomains.php` line 31 says the mail plan is
fetched *over SSH*.

## Open

- **A live pass has never happened.** `project_managed_domain_registration`
  records that the node banner was never rendered against a real row, and that
  nothing has touched the real registrar. This conversion does not change that;
  acceptance 7 is the first time the banner will have been seen end to end.
- **`manage_url` may not need sending.** The agent already knows which
  management node it talks to, so the node could derive it. Left as a parameter
  for now because the channel endpoint and the public profile URL are not
  necessarily the same host. Worth revisiting — it would take the notice
  primitive from four parameters to three.
- **This is capability work, not urgent work.** `rdm_registered_domains` has
  zero rows on dev in any status, and per `project_managed_domain_registration`
  nothing has ever touched the real registrar. Nothing is broken today that this
  fixes; what it removes is a shape that should not ship.
- **The whole pipeline is currently broken upstream of this.** `install_node`
  emits SSH steps the agent refuses, so no new customer node can be built at
  all today. Fixing that is the bootstrap window
  (`keyless_provisioning.md`), not this spec — but acceptance 3 and 7 need a
  node, so they will have to run against an existing one.
