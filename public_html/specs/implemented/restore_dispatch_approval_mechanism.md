# Restore Dispatch — authorization, and the artifact it has nothing to restore from

**Status: BUILT 2026-08-30, WP1–WP4. WP5 is deliberately outstanding and is
gated on a live restore, not on more code.** Rewritten 2026-08-30, replacing a
placeholder written 2026-08-28 whose central premise — "restore keeps travelling
by SSH meanwhile" — stopped being true that afternoon when SSH and SCP were
removed from the agent.

**What landed** (agent 1.13.0):

- `download_backup` and `stage_chain`, both `ClassOperate`, with the node-side
  upload ledger (`BackupLedger`, `config/backup-ledger/`), verification before
  an artifact lands, a transfer capped at the size the ledger recorded, and a
  `0600` landing — created that way, not tightened afterwards. Nodes are handed a **pre-signed link**, never a
  bucket credential — the read a restore needs is one object, expiring with the
  job. `S3Signer::presign_get()` is the whole of that grant.
- Chain staging as one node-side script (`utils/stage_chain.php`), replacing the
  six SSH steps and, more to the point, the Python program this plane used to
  compose to decide which artifacts a run needs. The node reads its own manifest.
- The approval: the agent composes its own statement, seals a job-bound one-time
  challenge to the node's own proven recovery public key, stages it through the
  settings handoff, and verifies the answer itself. The screen is the node's own
  Backups page. The management node sees neither half.
- The gate: `node_can_dispatch_destructive()` is true for a paired node running
  1.13.0 or later. It is a **routing** decision; this plane grants no permission
  and holds none.

**What is deliberately not done.** WP5 — deleting the three SSH restore builders
— waits for a live restore over the channel, because they are still the only
written record of what a restore used to do. What changed instead is that they
can no longer be *composed*: `refuse_dead_restore_transport()` turns a restore
aimed at a node that cannot take a primitive into an answer an operator can act
on, rather than a job that dies at step one with a message about a step type.

**One thing an operator has to know.** The ledger only records uploads made
since it existed, so a node's older archives are unrestorable over the channel
until it has taken a backup under this release. Chains re-upload their manifest
every run but not their older artifacts, so a chain restore is available for
artifacts uploaded after the upgrade — in practice, from the next full onward.

**Reading this document.** It is the plan and the outcome in one file, because
the places they diverged are the places worth reading. Blockquoted **BUILT
DIFFERENTLY** notes mark where the build contradicted the design and why; *What
the agent had to gain*, *Found while building this* and *Found in review, fixed*
carry what the plan did not anticipate. Acceptance says which criteria are
proven by a test, which test, and which still need a live restore.

**Where it lives.** Agent (`/home/user1/joinery-agent`): `approval.go`,
`primitives/approval.go`, `primitives/ledger.go`,
`primitives/restore_statement.go`, `primitives/operate_download_backup.go`,
`primitives/operate_stage_chain.go`. Platform: `includes/BackupLedger.php`,
`includes/BackupFetch.php`, `includes/RestoreApproval.php`,
`includes/RestoreApprovalPanel.php`, `utils/download_backup.php`,
`utils/stage_chain.php`, `S3Signer::presign_get()`, and the gate and builders in
`plugins/server_manager/includes/JobCommandBuilder.php`.


This spec covers **one operation**: restore. Nothing else. The other broken
management operations (`backup_database`/`backup_project`, `provision_ssl` on
container nodes, `decommission_node`) each have their own answer elsewhere and
are deliberately not folded in here.

## In plain terms

Restoring a site from its backup is the last thing that saves you, and right now
we cannot do it on any machine we run.

Two separate things are missing, and **either one alone leaves restore
non-functional.** They are usually discussed as one problem and they are not:

1. **Nobody may authorize it.** A restore erases a live database. The agent
   refuses that class of job at a compiled ceiling, and the plane refuses to
   route it, because the thing that would authorize it does not exist yet.
2. **There is nothing on the machine to restore from.** Every node uploads its
   backup and then deletes the local copy. The restore primitive takes the
   *name* of a file it expects to find in its own backup directory. That file is
   never there.

Item 2 was recorded in the placeholder as an edge case for "cloud-only backups."
It is not an edge case. Measured 2026-08-30: `mgn_delete_local_after_upload` is
**true on 11 of the 12 live node rows** — false only on the relay, which has no
backups — and true on all **nine** nodes that have backup history.
`BackupRunner.php:674` and `:1030` unlink every artifact after upload when that
flag is set, which is every node that matters. And a node with the flag *off*
still ages its local artifacts out: `sweep_local()` runs unconditionally after
every backup under `keep_local` retention. There is no node whose backups can be
relied on to be local. Opening the authorization gate on its own produces a
restore that is permitted and still restores nothing.

So the availability half is built first, and it can start today, because it is
not a destructive operation and needs no approval mechanism at all.

## How restore got into this state

The primitives are built and shipped (agent 1.12.0, `destructive_restore_*.go`).
They are unreachable by design, in two places that both had to hold:

- Agent: `Policy.Accepts(ClassDestructive)` (`primitives/policy.go:117`) refuses
  unconditionally, above the policy file, so no policy can ask for it.
- Plane: `node_can_dispatch_destructive()` returns false unconditionally
  (`JobCommandBuilder.php:225`), and `has_primitive()` short-circuits on that
  ahead of every capability question (`:238`).

The plane's gate was written on the explicit assumption that SSH remained: *"So
restore keeps travelling by SSH"* (`:216`). With the SSH builders now refused by
the agent, `build_restore_database`, `build_restore_project` and
`build_restore_chain` fall through to a transport that no longer exists. The two
gates were each correct alone and, together with the removal, closed the last
door.

## Scope — what restore-over-agent is for

Worth stating because it bounds the work. A node whose **disk is gone** is not
restored, it is **reprovisioned**: a fresh install with `install_mode =
from_backup`, which is bootstrap work over the plane-side executor and needs no
approval mechanism because there is no node yet to approve anything.

Restore-over-agent is for the other case, which is the common one: **the machine
is fine and its data is wrong.** A bad migration, a botched upgrade, a deletion
someone regrets.

Two things follow from that boundary rather than needing their own machinery.
Losing the recovery key does not strand a node, because the recovery path is
reprovision-and-restore, which never consults an approver. And **a node whose
web tier you suspect is compromised is not "the machine is fine"** — it takes the
rebuild path by this spec's own scope rule. Approving a restore on a box you
distrust is the wrong operation, whatever the mechanism allows.

## The threat model, settled

**A compromised management node is in scope** (owner, 2026-08-30). That is the
premise every choice below answers to, and it is why no control the management
node enforces counts for anything: it is the thing assumed compromised. Only the
machine being restored can decide.

It is not a manufactured worry. Restore is the sharpest edge a plane attacker
would have: the one operation *designed* to destroy, rather than one that
destroys only through a bug.

**But be precise about which attacker this defeats, because an earlier draft of
this paragraph overclaimed.** Agent updates are Ed25519-verified against a key
embedded in the binary (`update.go`) — but the *signing* key lives on the
management node, at `config/agent_signing_key`, and
`AgentDistPublisher::ensureKeys()` says so plainly: the signing key is the fleet
trust root and it sits inside this site's own project tree. Anyone who can read
that file can sign a malicious agent that asks nobody anything, and every node
will self-update into it. **No node-side approval survives that**, and none
could.

| Plane attacker holds | Reaches the signing key? | This mechanism |
|---|---|---|
| Database write | **Today, yes** — via the local queue: a web-writable table feeds a root process. That is the defect this whole program exists to close. | Defeated today; sound once the queue is retired |
| Web tier (`www-data`) | No — the key is `0600 user1`, group has no access | **Sound** |
| `user1` or root on the plane | Yes | Defeated, permanently |

So the honest claim is stratified. Against the database-write attacker — the
local-queue defect class — this mechanism is sound **once
`agent_local_queue_retirement.md` lands**, and not before; that retirement is a
prerequisite for this spec's security claim, not merely adjacent to it. Against
plane web-tier compromise it is sound now.

**Signing-key custody is the ceiling on everything here.** While the fleet trust
root sits on the plane's own filesystem, full plane compromise beats any
node-side gate we build. Moving it off the box or into hardware is
`publish_integrity_guards.md`'s problem, not this spec's — but this spec must
not claim a property the filesystem contradicts.

**The approver is the CUSTOMER, not us.** On a customer's machine it is their
own backup recovery key that authorizes a restore, and we hold no copy of it. The approval surface is their own site's admin, which they already
reach and we do not serve. Also stated
plainly because it is an operational cost, not a footnote: **a support-driven
restore requires the customer to be reachable.** There is no unattended
destructive path, including for us.

**Guaranteed local retention was considered and rejected** (owner, 2026-08-30).
Keeping recent archives on the machine would make the common restore need no
download at all, and would remove most of the argument for the ledger. It does
not survive contact with a small VPS, where days of archives overflow the disk —
and a backup system that fills a disk is a worse failure than the one it
prevents. Remote pull is the path, for every node, always. The ledger is
therefore required, not optional.

## Part A — Availability: `download_backup`

**Build this first. It is unblocked today and nothing else in this spec is.**

The node holds a **write-only** bucket credential (`bkt_node_credentials`) so
that a compromised node cannot erase the fleet's backups. It can add to the
shelf and cannot read from it. Restore needs a read.

**The shape: a `download_backup` primitive, the exact mirror of
`upload_backup`.** `operate_upload_backup.go` already establishes the pattern —
the plane passes `bucket`, `path_prefix`, `slug`, `profile` and
`credentials_b64` as declared parameters at dispatch, resolved from the sealed
target at claim time. `download_backup` is the same contract in the other
direction, landing the object in the node's own backup directory under a
node-resolved name (`restore_paths.go` already owns that resolution).

**It is `ClassOperate`, not `ClassDestructive`.** Writing a file into a backup
directory destroys nothing. That is what makes it shippable now, ahead of the
approval round, and it is the whole reason to separate the two halves.

**The credential must be scoped and short-lived**: read, one object key, expiring
with the job. The standing rule from the placeholder holds and is restated —
*do not ship a node a standing bucket credential that can read or delete.* A
dispatch-time hand-out that expires is not a standing credential; a second entry
in `bkt_backup_targets` would be.

> **BUILT DIFFERENTLY, AND BETTER: no credential is sent at all.** Writing the
> requirement out — read, one object, expiring — described a *signature* rather
> than a key, so that is what ships. `S3Signer::presign_get()` signs the object
> on the management node, which already holds the credential, and the node
> receives the signed URL. It names one object (the key is inside the
> signature, so it cannot be re-pointed), it expires with the job's own claim
> budget, and there is no parameter in the primitive through which
> `access_key`, `secret_key` or a credentials blob could arrive. The mirror of
> `upload_backup` in shape; not in what crosses.

**Decryption needs nothing new.** Backups are sealed to the node's own recovery
key (A4), which the node already holds at `~/.joinery_backup_key`. The plane can
neither read nor re-seal them, and does not need to.

### The integrity ledger — required, not optional

A download is not destructive, but **the plane chooses the bucket, the
credential and the landing name**, so without a check a compromised plane stages
bytes of its choosing where an approved restore will load them. The approval in
Part B blesses a *name*; it never sees content, so the approval mechanism cannot
catch this on its own. Two distinct attacks:

- **Forgery.** `bkh_encrypted` defaults to `false` in the schema
  (`data/backup_history_class.php:93`) and nothing structurally enforces
  sealing, so an unsealed artifact is possible and its bytes are then arbitrary
  — attacker SQL or an attacker project tree loaded as root after `DROP SCHEMA
  public CASCADE`. *Latent rather than live:* all nine nodes with backup history
  report, node-side, a recovery fingerprint (`mgn_backup_recovery_fpr`, set on
  all nine) and `backup_recovery_state = proven`, and the plane's own history is
  208 rows all encrypted. Note what that evidence does and does not cover — it
  says each node holds a proven key and seals what it *reports*, not that every
  object sitting in the bucket is sealed. Which is one more reason the ledger
  rests on replay rather than on forgery.
- **Replay, which sealing does not stop at all.** The plane serves the node's own
  genuine month-old artifact under a fresh-looking name. The human approves
  "yesterday's backup" and gets last month's. This works against a fully sealed
  fleet and is the argument that carries the ledger on its own.

**The fix, which keeps `download_backup` non-destructive:** a node-side
**upload-time ledger**, written by the same path that uploads the artifact.
`download_backup` verifies the fetched object against it before the file lands,
and restore refuses an artifact that is not in the ledger. Replay under the
original name still works and is honest, because the human sees the real name.
Replay under a new name is refused. Forgery is refused.

**What it turned out to be** (`BackupLedger`, and `primitives/ledger.go` reading
it). Four things the plan did not say, each settled by building it:

- **`config/backup-ledger/{profile}.json`**, not `/var/lib` and not "beside the
  backups". `config/` is a named volume on a container node, so the ledger
  survives a rebuild; a ledger under `/var/lib` lives in the writable layer and
  would be wiped by one — and since a ledger only records uploads made *since*,
  the recovery path would stay broken for as long as the current chain is old.
  Both restore scripts hold it across an extraction for the matching reason.
- **Keyed by the name relative to the profile's backup directory**, so a chain
  artifact is `chain-XXX/files-0001.tar.gz.enc`, not a bare filename. Two chains
  can each hold a `files-0001`. A leading slash is REFUSED rather than trimmed,
  on both sides, so a key means one thing.
- **NOT root-owned**, which the plan asserted and the build contradicted.
  Backups legitimately run under more than one account — root via the agent on a
  managed node, the web user on a site's own schedule — so requiring root would
  refuse ledgers written by a party that is not the adversary. What is enforced
  instead is that nothing else may write it: 0700/0600, pinned out of
  `fix_permissions.sh`'s sweep, and the agent **refuses** a ledger that is group-
  or other-writable rather than trusting what it finds.
- **A name that is legitimately rewritten keeps its earlier versions.** Only
  `manifest.json` is; see the review section below for why that is provenance
  working correctly rather than a weakening.

This is in scope by this spec's own boundary: restore-over-agent means the
machine is fine, so its ledger is there. The reprovision path never consults it.

Add a free-space check in the same package, so an unapproved download cannot
fill a node's disk.

### What this also closes

`restore_chain` currently refuses unless its artifacts and key are already
staged. With `download_backup` in the vocabulary, the staging step becomes a
node-side program that opens the chain envelope against the node's own recovery
key and downloads what `manifest.json` names — one signed-manifest entry, and
the plane composes no heredoc.

> **BUILT AS ITS OWN PRIMITIVE, not as a preamble to the restore.** The plan had
> one program that staged *and then called* `restore_chain.sh`. What ships is
> `stage_chain` (`ClassOperate`, `utils/stage_chain.php`) and `restore_chain`
> (`ClassDestructive`) as two jobs, because they are two decisions. Staging
> destroys nothing, so it needs no approval and can run while the operator is
> still deciding — and the destructive half stays as small as it can be, which
> is the whole argument Part A rests on. It also means an operator can stage a
> chain, look at what arrived, and only then ask for the restore.
>
> The plane signs every object under the chain's prefix and hands the links over
> **keyed by bare artifact name**; the node reads its own manifest and picks
> which it needs. That is the part worth keeping: the SSH path built a Python
> program on the management node to work out which artifacts a run required,
> which made the chain layout something two implementations computed with the
> authoritative one running on the machine that did not write the chain.

## Part B — Authorization: the recovery key, on the node's own site

**The machine being restored decides. The management node is not in the approval
path at all — not as a gate, and not even as a relay.**

Every node already holds the public half of its own backup recovery key, and has
already proven someone holds the private half — that proof is why it is allowed
to seal a backup at all, and all nine report `backup_recovery_state = proven`.
**That key is the approver.** Nothing is enrolled, nothing is registered, and
there is no second credential to keep.

It is the right authority on its own terms, not a convenient stand-in: whoever
holds that key can already read every backup that machine has ever made.
Proving possession of it is at least as strong as anything we could invent, and
weaker than nothing we could.

### The flow

1. The agent claims a destructive job and **runs nothing**. It composes its own
   statement of what it would do — which database, which archive, the archive's
   true age, size and hash, its position in a chain — from its own records.
2. It seals a one-time challenge to the recovery public key it already holds,
   binding it to that specific job and that statement, and writes both into the
   node's settings table (the agent↔web-tier handoff the join and leave watchers
   already use).
3. The node's own site shows a pending-approval screen: this is what will be
   destroyed, this is the archive, this is its real date.
4. The operator opens the challenge there with their recovery key — the same
   in-browser flow that proved the key in the first place — and answers.
5. The agent verifies the answer against the challenge it issued, and only then
   runs the restore.

**The management node never sees the challenge or the answer.** Both live
between the node's own site and the node's own agent. A compromised plane can
dispatch a restore job and can do nothing whatsoever to get it approved.

### What this costs, decided deliberately

**Restore-in-place requires the node's site to be up. If the site is down, the
recovery path is rebuild-and-restore, not restore** (owner, 2026-08-30). A fresh
install with `install_mode = from_backup` needs no approval, because there is no
node yet to ask — and for a machine whose site will not boot, that is usually
what was going to happen anyway.

This was decided against the alternative of enrolling a passkey so that approval
could happen on a page we serve when the customer's site is dead. That design is
recorded below rather than built.

### Why not a passkey — the rejected design

A passkey was specified through several drafts and then cut. The reasoning is
kept because it is the argument anyone will re-derive.

A typed secret and a passkey fail differently on a hostile page. **Capture a
typed secret once and it approves everything, forever** — it is static, and the
attacker never needs the human again. A passkey's private half never leaves the
authenticator, so the worst a hostile page gets is a single approval it deceived
someone into making, one machine at a time, with a human present. A second
restore-only phrase does not fix this; it is static too.

That asymmetry is real, and it matters **only on a page the adversary serves**.
Since approval now happens exclusively on the node's own site, there is no such
page in the flow, and the passkey's entire justification disappears with it. It
bought one scenario — approving from a hosting portal while the customer's site
was down — and that scenario has been explicitly given up.

What went with it: approver-key enrolment and its whole ceremony, WebAuthn
assertion verification in the agent, relying-party pinning and the domain fork it
forced, first-write-wins semantics, key rotation, and the retrofit problem for
the nine already-paired nodes. **All nine can approve a restore today with a key
they already hold.**

### What the approval proves — and what it does not

The operator approves against a statement the **machine** composed from its own
records, and that statement is what the challenge is bound to. So the machine
acts only on what it itself stated, and afterwards there is a tamper-evident
record of exactly what was authorized. The plane can neither forge it nor alter
it, because the plane never touches it.

**The residual is the node's own web tier.** The approval screen is served by
the node's site, so a compromised web tier on that machine could display one
thing while the challenge binds another, and could capture the recovery key as
it is entered — which opens every archive that machine ever made. Two things
about that, both honest:

- It is **the same trust** the existing recovery-key setup flow already asks for
  — but **not the same stakes**, and an earlier draft blurred that. At setup the
  key is minutes old with no archives behind it. At restore time it opens the
  machine's entire history, and restores happen precisely when something is
  already wrong.

Three things narrow it, none of which rely on anyone reading carefully:

1. **The key alone opens nothing.** The node retains no archives — deleting the
   local copy after upload is this spec's own headline fact — its bucket
   credential is write-only, and WP1 lands the restore artifact 0600 (root-owned,
   because the agent that fetches it is root) where the web tier cannot read it
   either. Read access to the shelf exists only plane-side. So a node-web-tier
   attacker who captures the key at approval time holds a key with **no
   ciphertext to apply it to**, and needs a second, independent compromise to
   reach the history. Capture is necessary and not sufficient, and the
   architecture had already paid for most of that.

   Narrowed further by the build than the plan expected: the ledger is closed to
   the web tier as well. (The pre-restore safety dump, written under `umask 077`
   for the same reason, no longer exists — see the superseded section below.) What is NOT
   closed is the ledger's ownership — see Part A — so a compromised web tier on
   the node could still forge a ledger entry. That stays inside the scope rule:
   a machine whose web tier you suspect is rebuilt, not restored in place.

   Out of scope but worth knowing: a node's own pre-upload window still writes
   archives as the site user before `delete_local` removes them. That is the
   backup path's business, not this spec's.
2. The machine refuses an archive absent from its own ledger, whatever was
   approved.
3. It carries the archive's true age as a first-class fact rather than a detail.

### The boundary this rests on

There is no path for a compromised plane to substitute its own recovery public
key ahead of time, held by three locks: the fleet push is **retired**
(`RecoveryKeyFleet` 1.1 — *"this management node cannot do it from here and must
not be able to"*), the agent's only recovery-key primitive is
`recovery_key_report` and is **read-only**, and `backup_recovery_save` requires a
**superadmin session on the node's own site** and refuses to overwrite a proven
key without an explicit `rotate=1`, with rotation clearing the proof and ending
the chain visibly.

**All of that holds only while the plane stores no superadmin credential to any
node's site.** True today, and it is the boundary — if that ever changes, this
mechanism changes with it.

### Challenge construction

Ephemeral X25519 → HKDF-SHA256 → AES-256-GCM, which is what the browser already
knows how to open. **Not** sodium's sealed box: that is the envelope's
construction (`BackupEnvelope.php:108`), not the browser's. An X25519 key cannot
produce a signature at all, so a signing challenge was never an option to
decline.

> **The construction is reused; the CODE is not.** The plan said reuse
> `BackupRecoveryKey::browser_challenge()`. It seals a fixed proof string on the
> PHP side, and the party that has to seal here is the AGENT — so
> `sealToRecoveryKey` reimplements the same layout in Go, with a different HKDF
> context (`joinery-restore-approval:`) so an approval challenge and a
> possession challenge can never be answers to each other. What opens it is the
> shipped `recovery-readiness.js`, unchanged apart from taking the context as a
> parameter.
>
> That leaves one seam nothing checks at runtime: the agent seals in Go, the
> operator opens in JavaScript, and a disagreement surfaces to a customer as
> *"my recovery key does not work"*, during a restore. So it is checked at build
> time instead — `tests/backups/approval_challenge_parity_gate.sh` has the real
> agent seal a real challenge and asks the real browser file to open it. That
> gate is not decoration: it is what would have caught the worst bug in this
> mechanism, below.

Seal the job binding and the statement hash **inside the box**, so the answer
cannot be paired with a different job. Give the challenge an **expiry** as well
as one-time use, so a staged approval nobody answers dies rather than sitting
answerable on the node's admin page for weeks.

**The sealed value is ONE LINE and the whole of it is what gets compared.** Two
reasons, both learned the hard way. The browser posts back the entire recovered
plaintext, so an agent comparing only part of it refuses every genuine approval.
And a form POST normalises line breaks to CRLF, so a multi-line plaintext would
not survive the round trip byte-for-byte even once the comparison was right.
Comparing the whole line also puts the binding inside the CHECK rather than only
inside the ciphertext.

**Make "the plane cannot relay an approval" a property of the wire format, not
of builder care.** The restore primitives' vocabulary declares no parameter
through which an approval answer could arrive, and the agent accepts answers
only from the local settings handoff — the same shape rule as
`restore_paths.go`. Without that, someone later adds a convenience parameter and
the acceptance criterion rots silently.

### What the agent had to gain, which the plan did not anticipate

Three changes to the agent that are not "restore" and had to happen anyway. Each
widens something the architecture deliberately keeps narrow, so each is written
down rather than left in a diff.

**A destructive job now BLOCKS while a human answers.** Nothing in the primitive
model did that before: a job was claimed, run, and reported. The approval wait
(`ApprovalWindow`, fifteen minutes) sits inside the primitive's own deadline, so
each restore's declared `Timeout` is work *plus* the window and every claim
budget in `PRIMITIVE_CLAIM_BUDGETS` exceeds it — otherwise the plane requeues a
job that is still running and starts a second restore over the first, which is
the one operation in this vocabulary where doing it twice destroys what it was
recovering. The agent claims one job at a time, so a node awaiting approval does
nothing else for up to fifteen minutes; that is a real cost, accepted because a
node in this position is mid-incident and because the alternatives are worse (a
re-dispatch would issue a *different* challenge, making the first approval
worthless).

**`ShippedPolicy()` now lists `ClassDestructive`, and that is not a relaxation
of A2.** A2 says destructive work is never run *unattended*, and it is not:
`Execute` requires an operator at the machine's own site to open a challenge
sealed to that machine's own recovery key. Accepting the class means "this node
is willing to be ASKED", never "this node will do it" — the two halves are
deliberately in different places, because the class question knows nothing about
a job and the approval needs its validated parameters. Listing it is what lets
the nine already-paired nodes approve with a key they already hold; the
alternative was pushing a policy file to every node, which is the enrolment step
this whole design was chosen to avoid. A node that wants destructive work
refused outright still says so by leaving it out of its own root-owned policy
file, and that refusal is final.

**`ParamMap`, the vocabulary's first composite type.** `stage_chain` sends signed
links keyed by artifact name, and there was no shape for that. It is bounded in
four directions — entry count, key length, value length, and the whole object
against `MaxParamsBytes` — its values are strings and only strings, so "a map"
cannot become "an object with a field somebody later reads as a path". The
plane refuses to build a staging job whose links exceed the node's own ceiling,
so a chain too long to describe fails where an operator is standing.

### Found while building this: the pre-restore safety dump

> **THE FEATURE DESCRIBED BELOW WAS REMOVED ON 2026-08-30**, the day after it
> shipped, by owner decision — and this file is edited, against the rule that
> implemented specs are never touched, because leaving it would have this
> document describe a safety mechanism the code does not have.
>
> **The defect it records was real** and the fix below was right at the time:
> unattended restores genuinely took no dump, on reasoning that could not hold
> on the primitive path. What changed is the answer, not the finding. **A
> restore happens because the current state is wrong**, so saving that state
> first preserves precisely what the operator has decided to discard — and it
> kept a full copy of the database, per restore, indefinitely, on a disk sized
> for backups rather than for regret. The approval the operator answers already
> says in words that anything written since the archive was taken is gone.
>
> Knowingly given up: a load that fails part way leaves the schema replaced with
> nothing on the machine to put back (`RESTORE_LOAD_FAILED`). The answer is the
> archive itself, still on the shelf, and the chain behind it.
>
> `restore_database.sh` 3.7 removed the stage and both flags; the engine refuses
> an unknown option, so a caller still passing one fails loudly rather than
> being ignored. `restore_roundtrip_gate.sh` now proves the inverse — that
> nothing is left behind, and that the flags are refused.

Not part of the plan, and the plan was wrong not to have noticed it.

`restore_database.sh` skipped its pre-restore safety dump whenever
`--non-interactive` was given, on the stated reasoning that *"the dashboard
always prepends its own auto-backup step"*. That was true of the SSH path, which
composed a `pg_dump` step in front of the restore — and it is **structurally
impossible on the primitive path**, where one job runs one script and there is
nowhere to prepend anything. So every restore over the agent channel would have
dropped a live schema with nothing behind it, on all three restores, since
`restore_project.sh` and `restore_chain.sh` both delegate the database to that
one engine.

Fixed at the root rather than in the caller: the dump is now taken by default,
including unattended, and a caller that has genuinely taken its own passes
`--no-pre-restore-dump` (the SSH builders do). It lands beside the archive —
the node's own backup directory — rather than in whatever directory the caller
happened to be standing in, and `restore_project.sh` directs it away from the
staging directory it deletes on the way out. Proven in
`plugins/server_manager/tests/restore_roundtrip_gate.sh` against a real
PostgreSQL.

**Still outstanding, and decisions rather than bugs.** Two things the SSH job
carried that a primitive structurally cannot, both recorded here and both
visible in the test estate rather than quietly dropped:

- **The project-tree snapshot.** The SSH path tarred the tree before a project
  or chain restore replaced it. Adding that back means an unconditional
  full-tree copy before every restore, on machines chosen for small disks —
  the same objection that killed guaranteed local retention. The database,
  which is the irreplaceable half, is covered.
- **Proof that the site came back up.** The SSH job ended with plane-side
  checks: the web root holds a `serve.php`, the site agrees with this machine,
  and the site is actually *served* over HTTPS. That last one exists because an
  HTTP-only check once passed comfortably while a site answered on :80 under a
  container virtualhost with a valid certificate sitting unused. The reconcile
  half moved into the scripts; the probes did not, because a primitive runs one
  script. Putting a probe inside the restore script would fail good restores
  whenever SSL or DNS was not yet settled, so the answer is probably a
  `check_status` dispatched after a completed restore — which is job
  orchestration this platform does not have. Recorded as a standing skip in
  `plugins/server_manager/tests/job_command_builder_test.php`, so it shows in
  every run summary until it is decided.

### Found in review, fixed

An adversarial review of the built code found seven defects. Recorded because
the shapes recur, not for the changelog:

- **The approval could never have succeeded.** The browser posts the *whole*
  recovered plaintext; the agent compared only its first line. Every genuine
  approval would have been refused. Both sides' unit tests were green because
  each was written against its author's belief about the other — including
  mine, which posted the first line because that was what the agent wanted. The
  sealed value is now a single line, compared whole, and the cross-language gate
  asserts the recovered value is the exact byte string the agent compares, has
  no line break (a form POST would normalise it to CRLF), and carries the
  binding.
- **`fix_permissions.sh` reopened the ledger on every deploy**, to 770 in
  production and 777 in dev — so anything with a shell could vouch for any
  bytes it liked, on the one file whose job is vouching. The directory is now
  pinned out of the sweep at 0700/0600, and the agent **refuses** a ledger that
  is group- or other-writable rather than trusting whatever it finds. That
  refusal is the structural half: the pin can be undone again, the check cannot
  be silently passed.
- **A chain restore deleted the ledger.** `tar --incremental` replays deletions
  from each archive's directory listings, and `config/backup-ledger` is absent
  from the listings of runs taken before it existed — so the first chain restore
  removed the record every later restore checks against, and the next one
  refused everything. Held across the extraction now, and
  `backup_chain_gate.sh` proves it (verified to fail without the fix).
- **The version floor was never applied to a node that reports its
  vocabulary**, which is every real 1.12.0 node. The reported list wins early —
  right for every other operation — so a restore routed to an agent that ships
  the primitives and refuses the whole class. The floor moved into
  `node_can_dispatch_destructive()`, which is the only place that knows shipping
  and authorizing are different facts.
- The interactive `restore_project.sh` branch moved the tree aside and lost the
  ledger with it; the safety dump was created world-readable and tightened
  afterwards, a window in which a full plaintext copy of the live database sat
  in a directory the web tier can read (that dump was removed entirely the next
  day — see the superseded section above, which is what finally closed this
  one); `decline_restore` took nothing but a job
  id printed on the page, on a handler that fires on GET; and the download's
  profile was not required to agree with the shelf the signed object was
  actually on.

**Also fixed: a backup landing during the approval window refused the approved
restore.** A chain's manifest is rewritten by every run under a stable name, so
a scheduled backup between staging and approval moved the recorded hash forward
and the post-approval re-check refused a manifest that was, in fact, this
machine's own.

It was first written up as a decision — whether keeping prior hashes changes
what the ledger means. It does not, and the framing was wrong. The ledger's
question is **"did this machine make these bytes"**, and "are these the newest
bytes it made" was never a designed property: it was an accident of keying a map
by name, and only one file in the whole ledger ever exercises the difference.
Chain artifacts are named per run (`files-0003.tar.gz.enc`) and written once;
`manifest.json` is the sole name that is legitimately rewritten.

Nor does accepting an earlier version reopen replay. An older manifest only
restores to an earlier point in the chain, which the plane can already ask for
openly through the declared `seq` parameter and which the operator reads on the
approval screen — and every artifact the manifest names is separately ledgered
under an immutable name and re-checked against the manifest's own hash inside
`restore_chain.sh`.

The one real cost was honesty on the screen, and it is fixed rather than
accepted: `verify()` returns the version that MATCHED, not the newest, so the
approval's "Chain last extended" is the age of the bytes in front of the
operator. Bounded at eight prior versions — a chain starts a fresh full every
seven days, so that covers its whole life.

### Found in a second review, fixed

A second adversarial pass over the built code, after the fixes above. Four
defects, and they share a shape worth naming: **each is a property the design
states and the code stated back in a comment.** The screen shows what is
destroyed; the fetch is bounded; the download is private; the transcript carries
nothing. All four were true of the paragraph and not of the instruction.

- **The chain approval screen never named what it would destroy.** `restore_chain`
  takes a `project` chosen by the plane, and `restore_chain.sh` spends it twice —
  as the tree it replaces (`/var/www/html/<project>`) and as the **database name**
  it hands the restore engine. The statement omitted it, so the operator approved
  "this machine's site files and its database" with no way to see the plane had
  aimed it elsewhere. `restore_database` names its database on screen and
  `restore_project` names its project; the fleet's *normal* restore was the one
  that did not.

  Fixed at the root rather than on the screen. The node resolves the project from
  its own site root and **refuses a job naming any other** — the rule
  `restoreDatabaseTarget` already applied to the database — and the screen then
  shows the value the node resolved, with the database named separately and
  omitted entirely under `skip_database`. The script's own check (the archive's
  carried directory name must match the target's last segment) is not a
  substitute: it runs after a root process has started, and it says nothing at
  all about the database name.

- **The disk-fill claim was enforced before the transfer and not during it.**
  `BackupFetch` checked free space against the recorded size, then streamed
  without a ceiling — so a response with no `Content-Length` writes until the
  3600-second deadline. Transient, because the `.part` is unlinked on failure,
  but for up to an hour a compromised plane can take a node's disk to zero. The
  ledger already knows the exact size, so it is now a hard ceiling: `MAXFILESIZE`
  for a response that advertises one, and a progress callback that aborts for a
  chunked response that does not.

- **A small SSRF read.** On a non-200 the fetcher copied up to 2KB of the
  response body into the error, and the error lands in a job transcript the plane
  reads — while the plane chooses the URL, which may name any https host and
  port. That is a way to read error bodies from inside the node's network. The
  status is reported; the body is not.

- **The `0600` promise had a race.** The sink was opened under the agent's
  inherited umask (the agent sets none) and chmod'd afterwards, so on a container
  node — where the backup directory is inside the site tree — anything local that
  won the race held a readable descriptor for the whole transfer. That is exactly
  the property the design leans on when it says a captured recovery key has no
  ciphertext here to open. `open_private_sink()` sets the umask around the open,
  removes any stale file first, and is its own method so the test asserts the
  resulting **mode under `umask 0000`** rather than the sequence of calls.

Also closed, from the same pass: the platform side of the ledger did not mirror
the agent's refusal of a group- or other-writable ledger. Harmless as a gap —
the destructive gate re-checks in Go — but it meant the bytes moved before the
refusal instead of after, so `BackupLedger::untrusted()` now refuses in the same
place, and the test asserts both sides test the same bits.

One reported asymmetry was **not** a defect: a malformed answer row is already
cleared each pass, by the same branch that clears an answer for a different job,
rather than being re-parsed until expiry.

### Consequences elsewhere — flagged, not resolved here

- **`sentinel_managed_recovery.md` is built on the approval passkey** (O10, the
  enrollment flow, rungs 3–5). Its scenario is a node in trouble, so "approve on
  the node's own site" may not carry there. Sentinel either needs its own answer
  or re-introduces a passkey for its own rungs; that is sentinel's round to
  settle, not this one.
- **`agent_management_first_principles.md`** states destructive actions require
  a passkey signature. That sentence needs updating to name the recovery key.
- **`decommission_node`** was to ride this flow. It deletes the machine from the
  outside through the provider API, so a node-side approval can only work while
  the node is still alive and serving. It needs its own decision rather than an
  assumed inheritance.

## Work

**WP1 — `download_backup`, with the integrity ledger. DONE.** `ClassOperate`,
`utils/download_backup.php`, a pre-signed link rather than a credential, the
upload-time ledger, verification before the file lands, and a free-space check
sized from the ledger's own recorded size — so a name this machine never
uploaded is refused before a byte moves, and cannot be used to fill a disk.

**The artifact lands 0600**, root-owned because the agent that fetches it is
root. On a container node the backup directory resolves inside the site tree
(`{siteRoot}/backups`, per `backupdirs.go`), so anything landing there with
default permissions is readable by the web tier. It is created restricted rather
than tightened afterwards: a file made readable and then fixed was readable for
the length of a multi-gigabyte transfer.

**WP2 — Chain staging. DONE**, as its own `ClassOperate` primitive rather than a
preamble to the restore (above). The plan asked to confirm which nodes run
chains before building: **all nine**. Every node inherits the fleet default and
no node overrides it, so chain restore is the fleet's normal restore and
`restore_database` / `restore_project` are the standalone-mode path.

**WP3 — Approval. DONE.** Agent-side: compose the statement from the node's own
records, seal the job-bound challenge to the recovery public key it already
holds, stage it through the settings handoff, verify the answer in constant
time. Site-side: the pending-approval screen on the node's own Backups page,
reusing the in-browser recovery-key flow under its own global so the two panels
cannot overwrite each other's wiring.

Note where the gate ended up: **not** in `Policy.Accepts`, as the plan said. That
answers a question about a CLASS and has no job, no parameters and no way to
reach the node's own state, so folding the approval into it would have meant
either approving a class in the abstract or giving the policy file a dependency
on the whole environment. The class check stays there; the approval is a
separate step in `Execute`, after validation and before anything runs.

**WP4 — Gate flip. DONE.** `node_can_dispatch_destructive()` is true for a
paired node running the agent release that can ask its operator. The version
floor lives *inside* that method rather than in `has_primitive()`'s general
path, because that path returns early for a node that reports its vocabulary —
right for every other operation, and wrong here, since shipping the restore
primitives and being able to authorize a job in one are different facts.

**WP5 — Delete the three `build_restore_*_ssh` builders. NOT DONE, and still
gated on a live restore.** They are the only written record of what a restore
used to do. What changed instead: `refuse_dead_restore_transport()` means they
can no longer be *composed*, so a restore aimed at a node that cannot take a
primitive gets an answer an operator can act on rather than a job that dies at
its first step with a message about a step type.

## Acceptance

Held against tests rather than against belief, because the mechanism's worst bug
was one where both sides' tests were green. Where a criterion is proven by a
test, the test is named; where it is not yet proven, it says so.

**Proven:**

- A restore dispatched without an approval is refused **by the node** — and a
  node that cannot ask its operator refuses rather than treating "nobody to ask"
  as "nobody objected" (`destructive_restore_test.go`).
- An approval issued for one job is refused when replayed against another, a
  guessed answer is refused, and a decline is reported as a decision rather than
  a fault (`approval_test.go`).
- **The answer the browser posts is the answer the agent compares** — the seal is
  produced by the real agent code and opened by the real shipped JavaScript, and
  the recovered value is asserted to be the exact byte string the verifier
  compares, carrying the binding and surviving a form POST
  (`approval_challenge_parity_gate.sh`).
- No bucket credential crosses at all; what crosses is a signature over one
  object, expiring with the job's own claim budget
  (`restore_dispatch_test.php`).
- An artifact whose bytes are not ones this machine uploaded under that name is
  refused before it lands and again before it is loaded; a ledger that anything
  else could have written is refused outright
  (`backup_ledger_test.php`, `destructive_restore_test.go`).
- The machine's own ledger survives a chain restore — verified to fail without
  the fix (`backup_chain_gate.sh`).
- ~~An unattended restore takes a pre-restore safety dump of what it is about to
  destroy.~~ **Reversed 2026-08-30** — a restore now keeps nothing of what it
  replaces, and the same gate proves that instead: no dump is left anywhere, and
  the two flags that controlled it are refused rather than ignored
  (`restore_roundtrip_gate.sh`). See the superseded section above.
- The restore vocabulary declares no parameter that could carry an approval
  answer — read out of the agent's own source, not this plane's belief about it
  (`restore_dispatch_test.php`).
- The approval wait is inside the primitive's deadline, and every claim budget
  exceeds every agent timeout (`destructive_restore_test.go`,
  `primitive_transport_parity_test.php`).
- An unanswered challenge expires (`approval_test.go`).
- The approval screen names the project **and** the database a chain restore
  would replace, names neither more than it destroys under `skip_database`, and
  the node refuses a job aiming its own chain at another project
  (`destructive_restore_chain_test.go`).
- A download is `0600` from its first byte under `umask 0000`, is capped at the
  size the ledger recorded, quotes neither the signed link nor a failed
  response's body, and refuses an unrecorded name before any bytes move
  (`backup_fetch_test.php`).

**Not yet proven, and only a live restore can:**

- A backup that exists only in the bucket brought back to its own node and
  restored, end to end. Every part has a test; the whole has not been run
  against a real node and a real bucket.
- All nine already-paired nodes can approve with no enrolment step. True by
  construction — each reports `backup_recovery_state = proven` — but not
  demonstrated on any of them.
- That a management node with full database write cannot approve, relay, or
  substitute the key. Argued and reviewed; the wire-format half is tested, the
  rest is a property of code paths rather than something a test can execute.

**One thing an operator has to know before the first live attempt.** The ledger
records only uploads made since it existed, so a node's older archives are not
restorable over the channel until it has taken a backup under this release —
in practice, from its next full onward.

## Also gated on Part B

`decommission_node` is destructive, but it can no longer simply inherit this
flow: it deletes the machine from the outside through the provider API, so a
node-side approval only works while the node is alive and serving. It needs its
own decision. Do not assume it rides along.

## Provenance

The placeholder (2026-08-28) deferred this deliberately: the primitives round was
self-contained and low-risk, and authorizing a destructive job is the most
delicate mechanism in the architecture, so it earned a focused round. That
reasoning still stands. What changed is that the fallback it assumed is gone,
and that a measurement — every node deletes its local backups — turned a
footnote into the first work package.

**What the round itself taught, which is worth more than the changelog.** Three
of the defects found afterwards shared one shape: a property that was *asserted
in a comment* and *not enforced anywhere*. The ledger was described as
root-owned and was not; "the dashboard always prepends its own auto-backup step"
was true of a transport that no longer existed; the version floor's docblock
said it was checked where it was not. Each read as settled and each was a
sentence.

The fourth was worse and is the one to remember. The approval could never have
succeeded — the browser posts the whole recovered plaintext, the agent compared
its first line — and every test on both sides passed, because each was written
against its author's belief about the other. `primitive_transport_parity_test`
exists in this codebase precisely to say that, and the test written for this
round reproduced the mistake it warns about. The correction is not "be careful":
it is that a seam between two implementations needs a check that executes BOTH
of them, which is what `approval_challenge_parity_gate.sh` now does.

## Related

- `plane_side_executor.md` — bootstrap transport; deliberately NOT where restore
  goes. Putting restore back on SSH was proposed on 2026-08-30 as WP4a and
  rejected by the owner: it rebuilds the management channel we had just deleted.
- `sentinel_managed_recovery.md` — sits on top of this mechanism.
- `fleet_ssh_credential_custody.md`, `agent_management_first_principles.md`
