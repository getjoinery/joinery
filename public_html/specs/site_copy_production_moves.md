# Site Copy — The Production Moves

**Status:** Waiting on its gate (2026-10-25). Split out of `site_copy_final_test.md`
(its Part P, E3 and the two-layer check of its Done when), which moved to implemented
with `site_copy.md` and `site_copy_anything_to_anything.md` once every test-plane
run C1–C19 had passed. This spec is the last of copy: the three production machines
that still carry Linode's layer move by copy onto servers with ours only.

## The owner's goal in plain terms

Three of our production machines were born before we encrypted anything ourselves, so
their data is protected only by Linode's disk encryption. Rather than adding our
encryption on top (two layers, which the platform's rule forbids everywhere but the
two management nodes), each moves by copy onto a new server born with ours and with
Linode's off. The copy path these moves use is the one the final test proved on the
test plane; this spec is the same thing done for real, one machine at a time.

## The gate

The moves start only after the encryption spec's D7 steps 3–6 are done
(`hosted_data_promise_disk_encryption.md`):

- dev is encrypted;
- the release keys are rotated;
- getjoinery is encrypted;
- dev and getjoinery hold each other's escrow.

Until then no management node may hold an escrow slot (that spec's D7 and O4), and an
IP swap onto a copy born with our layer is refused while nobody holds its key.
getjoinery must also carry a release with the copy work (`site_copy_anything_to_anything.md`
WP0–WP5, `site_copy_final_test.md` E1, E2 and E4), with
`data_root_encryption_offered` on.

## E3 — The encryption spec's D7 step 7 follows this spec

`hosted_data_promise_disk_encryption.md` D7 step 7 says the other three machines are
encrypted in place and keep Linode's layer. Under the owner's decision of 2026-10-18
(`site_copy_final_test.md`, "One layer by copy wherever a copy can do it"), step 7
instead points here: joinerydemo, jeremytunnell-vps and docker-prod's sites move by
copy, and their old servers are retired at Keep and deleted at the provider. The node
pages of dev and getjoinery keep "two layers until replaced". Made to that spec on
2026-10-25, with this one.

## Where it runs

All three machines are on getjoinery's plane, not dev's. The moves are driven by hand
from getjoinery's Copy tab. `CopySecurityCheck` runs on getjoinery from its CLI
(`plugins/server_manager/utils/copy_security_check.php`), before as the baseline and
after on the copy, and each result is recorded in the live verification queue. No
probe that acts as the site (`copy_probes`) and no check over a root login runs on a
production machine.

Before each move, the copy smoke test (`plugins/server_manager/tests/site_copy_live_test.php`,
run by name on dev, on dev's own code) has passed since the last change to copy code,
and getjoinery runs a release that carries that code.

## The moves

In this order, each only after the one before has been kept for a day:

1. **joinerydemo** (own server, alive) → a Linode made here, IP swap (as the final
   test's C1).
   - First confirm its server has a provision row on getjoinery's plane with an
     instance id.
   - Confirm no AAAA record names it; the swap's preflight refuses one.
2. **jeremytunnell-vps** (own server, alive, mail) → a Linode made here in our
   account, **proxy** (as C2). This is also `site_copy_anything_to_anything.md`'s
   proof L4, and `site_copy.md` Q4's choice for this site.
   - Not the IP swap. It sits in the owner's personal Linode account (customer cloud
     account 7) with no provision row, and a swap never crosses accounts.
   - A copy made in that account would be born with Linode's layer and none of ours.
     The proxy lets the copy be born in our account with ours.
   - The mail checks of C1 and C3 run here too.
3. **docker-prod's sites** (containers, alive) → containers on one new engine host
   born with our layer, its container storage on the data root (as C8), one at a
   time.
   - First, an inventory of what lives on docker-prod today: which site containers
     are left (getjoinery and joinerydemo have gone), and what else answers on its
     address (developers.getjoinery.com and demo.getjoinery.com, `site_copy.md`
     B46).
   - Its agent must run `site_quiet_host` and `hold_container`; record its version.
   - docker-prod is retired when the last site is kept and nothing else answers on
     it.

Each move is checked as the final test's runs were ("What every run checks" in
`site_copy_final_test.md`), less the probes and the login checks, and afterwards the
machine's layer line says one.

**After Keep,** each old server is deleted at its provider, with its provider backups
and snapshots, within a day. It holds live production secrets under Linode's layer
only: `secret_box_key`, the DKIM private key, the ACME account, the DNS-01 zone
credential, and `relay_pull_key`.

dev and getjoinery are not copied: they follow the encryption spec's D7 steps 3–6.

## Done when

- joinerydemo, jeremytunnell-vps and docker-prod's sites are each on one layer, and
  their old servers are deleted at the provider with their backups.
- No machine but dev and getjoinery reports two layers: no open two-layer incident
  (`IncidentSourceTwoLayers`) on getjoinery's plane, and every node page there reads
  one layer.
- dev and getjoinery are on the encryption spec's path.
- Each move is on record in the live verification queue under this spec's name.
