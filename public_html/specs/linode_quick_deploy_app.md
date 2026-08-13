# Linode Quick Deploy App — Joinery in the Akamai Marketplace

**Status:** UNBUILT — but **no longer blocked**. Every dependency is closed; what
remains is the submission itself.

**The mechanism is done.** `specs/implemented/linode_stackscript.md` shipped
2026-08-11: StackScript **2185451** is public at
`https://cloud.linode.com/stackscripts/2185451`, targeting `linode/ubuntu26.04`
with the domain field required. Phases 0–2 are implemented and Gaps 3–7 are
closed in code — including **Gap 3 (SSL without console intervention)**, the hard
Akamai requirement, now a systemd timer that resolves the domain, issues the
certificate, and disarms itself, watched doing so on a live box. Gates A2, B and
C pass. **Gate A1 has never been run** — the hands-off record-creation path went
public untested by owner's call, and it is the one path a Marketplace visitor
could take that nobody has taken. Run it before submitting.

**Licensing is resolved.** `specs/implemented/open_core_licensing.md` closed this
spec's open decision #1 and deleted its licensing work: PolyForm Shield 1.0.0
core makes the listing line **"free for your own use — source available."**

**What is left is the listing, not the software:** the public glue repo, the
Ansible playbook, the 100–125 word description, the support URL, the technical
documentation, the brand assets, and the pull request to
`akamai-compute-marketplace/marketplace-apps`.

One coupling to watch: item 2 of `specs/php_85_pg18_stack_cutover.md` re-points
the stackscript wrapper at 26.04 and calls for re-running these live gates. The
StackScript already targets 26.04 only, so submitting before that cutover lands
is safe.

## What this does for the user

Someone browsing the Akamai Cloud control panel finds Joinery in a menu
alongside 200+ other one-click apps, next to WordPress and GitLab, without
ever having heard of us. That is the entire point: the StackScript makes
Joinery installable in one click, and the Marketplace makes it *discoverable*
in one click. Everything else in this spec is the cost of getting into that
menu.

## What Akamai requires

Gathered up front so this is a checklist and not a discovery exercise:

- **Three components:** a StackScript, Ansible playbooks following the
  official sample layout, and a public git repository to clone from.
- **A short description**, 100–125 words, for the listing.
- **A support URL** reaching a real human, forum, or contact form.
- **Thorough technical documentation.**
- **Brand assets** for the listing.
- **Hands-off deployment:** no command-line intervention before the app
  reaches its initial state. This is the requirement the StackScript spec's
  Gap 3 exists to satisfy.
- **Plan sizes at or below** 16 GB shared CPU / 8 GB dedicated CPU. Joinery's
  floor is far below this; no action needed.
- **Submission** as a pull request to
  `akamai-compute-marketplace/marketplace-apps`, reviewed by Akamai.

## The constraint that shapes everything

**Anything Akamai hosts is expensive to change.** The submitted StackScript
and playbook live in their repository, so every edit is a pull request and a
review cycle rather than a deploy.

The StackScript spec's thin-wrapper rule is therefore a hard requirement here,
not a preference: the submitted script reads its user-defined fields, fetches
the release archive, and hands off to
`maintenance_scripts/install_tools/linode_stackscript.sh` inside it. All real
logic stays in our repo, where it updates itself with each publish. The
submitted artifact should need revision only when the field set changes or the
listing copy does.

The same reasoning rules out version pinning in the submitted script. It ships
"latest"; a pin would need a pull request every publish, and a stale pin is
worse than none. Pin only to reproduce a specific build for the review itself.

## Integration-point inventory

| Piece | Where | What |
|-------|-------|------|
| Public glue repo | new `getjoinery/joinery-marketplace` | The clone target Akamai requires. Holds the playbook, the submitted StackScript, and the listing assets. Public and GPLv3-compatible — see Licensing note. |
| Ansible layer | that repo | Wraps the same handoff. `install.sh` is already idempotent shell, so this is a thin playbook, not a reimplementation of the installer. |
| Submitted StackScript | that repo, then Akamai's | UDF declarations plus fetch-and-delegate. Identical in shape to the private StackScript from the companion spec. |
| Listing copy | that repo | Description, support URL, docs links. |
| Brand assets | that repo | Logo and listing imagery to Akamai's spec. |
| Support destination | getjoinery.com | Whatever the support URL points at must exist and be monitored before submission. Open decision 1. |

## Build items

1. **Stand up the public glue repo** with the playbook, the StackScript, the
   assets, and its own README. Nothing proprietary goes in it.
2. **Port the handoff to Ansible.** The playbook's job is to do what the
   private StackScript does, in the structure Akamai's sample layout expects.
   Keep it delegating to the in-archive script for the same self-updating
   reason.
3. **Write the listing copy** — the 100–125 word description, and the
   technical documentation the submission requires. Most of the latter already
   exists in `docs/installation.md` and `docs/quickstart.md` and needs
   assembling rather than writing.
4. **Produce brand assets** to Akamai's specification.
5. **Verify a deployment from the submitted artifacts**, not from the private
   StackScript — the playbook path is what reviewers run, so it gets its own
   live gate.
6. **Submit the pull request** and work the review.

## Licensing note

The Marketplace glue repository is **GPLv3**, so a submitted StackScript and
playbook are licensed GPLv3 as part of it. That is fine: deployment glue that
fetches a separately licensed archive is not a derivative work of what it
installs. Two consequences to respect — nothing proprietary may go in the
playbook, and anyone may fork the glue.

The listing's own license line depends on `specs/open_core_licensing.md`. A
permissive core makes it simply "open source, free," with no bring-your-own
license line and no eligibility for a reader to assess. Submitting before that
decision is made would mean writing listing copy we then have to revise
through a review cycle — so the licensing decision should land first.

## Verification

- A fresh instance deployed through the submitted playbook, on Ubuntu 24.04
  LTS, reaching HTTPS and a forced password change with no console access
  used and no command typed.
- The same with DNS pointed after create, proving the deferred-SSL path holds
  through the Ansible route.
- A deployment on the smallest plan we intend to list, confirming the install
  completes within it.

## Documentation

- `docs/installation.md` — a Marketplace section alongside the StackScript
  one: where to find the app, what it asks for, where credentials land.
- `docs/quickstart.md` — lead with the Marketplace path once it is live.

## Open decisions

1. **Where does the support URL point?** It must reach a real human, forum, or
   contact form, and it must exist before submission. This is a commitment to
   answer strangers, not just a URL.
2. *Resolved 2026-07-30: the 1 GB Nanode is a supported recommendation. The
   default bundle — including mail and cloud-backed AI — runs comfortably at
   that size, matching the existing quickstart guidance (1 GB for a small
   site, 2 GB if real users are expected from day one).*
3. **Timing against the licensing decision.** Recommendation: land
   `specs/open_core_licensing.md`'s core-license decision first, so the
   listing copy is written once.
