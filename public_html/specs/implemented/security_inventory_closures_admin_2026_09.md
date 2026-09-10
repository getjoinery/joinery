# Security inventory closures — administration (September 2026)

**Status:** Implemented 2026-09-10. The record of the
administration rows closed from `security_inventory.md` (the 1.0 bar),
starting with S4. The inventory keeps the S-numbers and points here. Each
section says what the door was, what closes it, and where the closure is
pinned; this file is the end state.

## S4 — every admin holds a second factor, or the owner is told who does not

**The door.** Route 1 in the inventory's threat model is a stolen session,
and an admin account with a password alone is where phishing lands. The
requirement setting existed, off by default, and checked the authenticator
app only: an admin who signed in with a passkey — the one factor that
resists a relayed code — was forced to add an app to pass a gate meant to
raise their posture. Nothing told an owner which admins had no factor.

**The close.** Three pieces, per the owner's decision to take both shapes
the row offered.

- **The requirement accepts a passkey.** `SessionControl::must_enable_totp_for_admin()`
  asks `user_has_second_factor()`: an authenticator app, or one live passkey
  while passkey sign-in is enabled. The redirect wording says so.
- **A managed deployment is born requiring it.** `utils/hosted_plan_notice.php`,
  the node-side script that lands the hosting-banner facts, switches
  `totp_require_admins` on the first time the deployment's state goes from
  silent to a billing state. Only ever on, only on that transition, never
  off: an owner who later relaxes it is not overruled at the next billing
  update, and the worst a compromised management node achieves through the
  line is tightening somebody's security. Self-hosted deployments stay off
  by default.
- **The notice.** `AdminSecondFactorNotice` is a core `AdminNotices`
  renderer: on every admin page, "N admins have no second factor", naming
  them (four, then "and N more"), with the fix in place — "Enrol yours" when
  the viewer is one of them, and for a superadmin a one-button "Require one
  of every admin" that POSTs to `/admin/admin_require_second_factor` and
  switches the setting on. When the requirement is already on, the notice
  says the named admins are sent to enrol at their next page. Silent when
  every admin holds a factor. It is information, never a gate.

**Pinned by** `tests/security/admin_second_factor_test.php` (db tier): the
predicate counts a temporary admin with no factor and stops counting them
once an authenticator app is enrolled; the notice is silent with nobody
missing, names the admins, offers "Enrol yours" only to a viewer who is
missing, offers the require button only to a superadmin while the
requirement is off, and says "sent to enrol" once it is on.
