# New-Site Deployment + Fortress Live Verification — Program Roadmap

**Status:** Active — sequencing spec. Each item below is worked in order;
an item's exit criterion must hold before the next item starts (except where
marked parallel-safe).
**Version:** 1.0

## Goal

Two goals that converge on one missing artifact — a second, real deployment:

1. **Prove the platform deploys cleanly to a new site.** Months of features
   (Sealed Vault, drive, password vault, mailbox relay + fleet, AI memory)
   have landed since the last from-scratch install; the zero-config install
   principle (nothing beyond `Globalvars_site.php` + `install.sh` args) has
   not been re-proven against them.
2. **Fully test Fortress.** The relay fleet spec's own status line names the
   gap: *live verification needs a real shard VPS plus a second tenant
   deployment (dev is colocated, single deployment)*. Fortress is the one
   feature that cannot be fully proven on dev, because dev's relay and app
   share a box and there is only one tenant.

The new site is therefore not just prep for testing Fortress — it **is** the
test fixture. Everything below sequences toward a green run of
`specs/fortress_live_verification_runbook.md` followed by the pentest brief.

## Item 1 — Commit the backlog and publish a release

**What:** The dev working tree carries a large uncommitted body of work
(mailbox compose maturity — staged, message ready — plus drive core and
encryption, password vault, mobile billing, AI memory, test-estate fixes,
UR