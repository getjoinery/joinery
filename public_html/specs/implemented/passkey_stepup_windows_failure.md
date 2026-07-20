# Passkey Step-Up Failure on Windows (jeremytunnell.com)

**Status:** RESOLVED 2026-07-20. Root cause fixed in 0.8.171 and confirmed by
the real scrolldaddy.app Standard → Private raise completing inline in one
pass on the owner's machine. See § Root cause. (An earlier poisoned-tab
theory in this spec's history was superseded by that finding.)

## Problem

The owner cannot complete the domain security-level raise (scrolldaddy.app,
domain id 3, Standard → Private) on jeremytunnell.com because the passkey
**step-up** ceremony fails in his browser (Windows) with:

> The operation either timed out or was not allowed. See:
> https://www.w3.org/TR/webauthn-2/#sctn-privacy-considerations-client

That is the browser's generic `NotAllowedError` text. The failure persists
through every remediation attempted so far (below). Meanwhile **vault
derivation ceremonies succeed on the same machine, same browser, same
credentials** — the account is fully functional except for step-up.

## Environment

- Site: jeremytunnell.com (node 176, `jeremytunnell-vps`), platform 0.8.167,
  mailbox plugin 1.46.9 at time of pause.
- Owner's machine: Windows (Thinkstation desktop), Windows Hello platform
  passkey (credential 2, `pkc_prf_capable` now true after evidence upgrade)
  plus a YubiKey (credential 3, usb). Both vault-active (wrappings exist).
- Account: TOTP enrolled; vault set up (1 passkey wrapping at setup + both
  passkeys activated later; 10 recovery codes; no bypass phrase).

## Evidence timeline (all times UTC, from `rql_request_logs` on the node)

| Time | Event | Result |
|---|---|---|
| 13:14 | `passkey_stepup_verify` (security page, enrolling 2nd passkey; allow list held ONLY the platform passkey) | SUCCESS — last known-good step-up via `authenticate()` |
| ~13:20 | YubiKey enrolled — allow list now mixed platform + usb | |
| 14:03 | Vault setup (derivation, UV required + PRF) | SUCCESS |
| 15:00, 15:47 | `vault_unlock_passkey` (derivation) | SUCCESS |
| 16:36 | verify-stepup page step-up — page then used `derive()` (UV required options? No — step-up options, but the DERIVE helper) | SUCCESS |
| 16:47, 16:58, 17:09 | Inline step-up attempts (editor): `stepup_options` called in PAIRS ~2s apart, zero verifies | FAIL — double-fire era (pre-guard) |
| 16:58:44 | verify-stepup page button (single options call) | FAIL |
| 17:20, 17:22 | Post-guard attempts, still pairs ~2s apart (interpreted as fast-fail + quick manual retry) | FAIL |
| 17:34 | `vault_unlock_passkey` (derivation) | **SUCCESS** — machine's WebAuthn healthy |
| 17:53:45 | Single clean `stepup_options` (guard working), then NOTHING — `get()` hung, no error, no verify | FAIL (hang) |
| ~18:10 | Attempt after step-up switched to UV required + 120s timeout (0.8.167) | FAIL — same NotAllowedError text |

Key discriminator that is now BROKEN as a theory: "UV required works,
UV preferred fails" — 0.8.167 made step-up UV required + 120s timeout and it
STILL failed. The remaining consistent split is:

- **Derivation ceremonies (PRF extension present) succeed.**
- **Assertion ceremonies without the PRF extension fail**, ever since the
  YubiKey joined the allow list (last plain-assertion success 13:14 predates
  it; the 16:36 success went through the `derive()` helper — worth
  re-examining exactly what that sent).

## What was found and fixed along the way (all deployed, all real bugs)

1. **Double-fired ceremonies** (editor submit path could start two WebAuthn
   requests; browser kills the first with NotAllowedError): single-flight
   guard + button feedback in the editor (0.8.164) and a helper-level
   `exclusiveCeremony` lock in `assets/js/passkeys.js` so overlapping
   ceremonies are impossible platform-wide (0.8.166).
2. **Lost form POST through the redirect step-up** (`/verify-stepup` dance
   discarded the submission; user returned to find Standard still selected):
   inline step-up before submit; fallback carries `target_level` back and
   preselects it (0.8.161–0.8.165).
3. **Stale render-time freshness snapshot** skipped the inline ceremony when
   the marker expired while the form sat open: interception now always runs
   on a level change (0.8.162).
4. **Dead-end on passkey failure**: failure dialog now offers "Use
   authenticator code" (server TOTP ceremony) so a broken passkey never
   blocks the save (0.8.165). NOTE: owner explicitly wants the passkey path
   FIXED, not the TOTP workaround.
5. **verify-stepup page used `derive()`** (PRF helper) for a plain step-up:
   switched to `authenticate()` (0.8.164). *Given the evidence split above,
   this "fix" may actually have REMOVED the accidentally-working shape —
   see Hypotheses.*
6. **Step-up options**: UV `preferred` → `required`, 120s timeout on all
   assertion/derivation options (0.8.167). Did not resolve it.
7. Unrelated but found during this: ceremony facts `MultiPasskey` missing
   `->load()` (unlock-by-touch row always yellow); `.d-none` losing to
   `.jy-ui .btn` specificity (hidden buttons visible platform-wide). Both
   fixed with tests.

## Verified working (rules these out)

- The full inline flow (select Private → Update → inline assertion → save)
  passes end-to-end on dev with a CDP virtual authenticator on 0.8.164+ code.
- Server mints valid options every time (`stepup_options` 200s throughout).
- No Cloudflare Rocket Loader on jeremytunnell.com; pages are `no-store`.
- No `Permissions-Policy` header blocking `publickey-credentials-get`.
- The owner's authenticators, browser, and OS complete derivation ceremonies
  (PRF, UV required) reliably — including at 17:34, mid-failure-window.

## Hypotheses still open, in priority order

1. **The PRF extension presence is the discriminator.** Every ceremony that
   works sends the PRF eval extension; every ceremony that fails sends no
   extensions. Something in the owner's Windows credential-broker path (or a
   browser extension/password manager intercepting WebAuthn) may only handle
   the extension-bearing request shape. TEST: mint step-up options WITH a
   throwaway PRF eval input (harmless — verify ignores extension output) and
   see if it completes. If yes: ship step-up-with-PRF as the Windows-safe
   shape (or investigate what intercepts extensionless requests).
2. **A browser extension (password manager) intercepts extensionless
   `credentials.get`.** 1Password/Bitwarden/Dashlane register WebAuthn
   interceptors; some mishandle specific option shapes. TEST: owner retries
   once in an incognito window (extensions disabled) — one datum, settles it.
3. **Where exactly does the quoted error render?** The 0.8.165+ editor
   dialog prefixes the error NAME (`NotAllowedError: ...`); the owner's
   quotes never include a prefix, matching the verify-stepup page's error
   element instead. If the errors are coming from /verify-stepup, the INLINE
   path may not be engaging at all in his browser (e.g. `JoineryPasskeys`
   undefined at submit → silent server fallback) — instrument or ask which
   surface showed it.
4. **Timeout mismatch**: the 17:53 hang produced no client error for minutes.
   With 0.8.167's 120s timeout this should now always resolve to an error —
   if hangs persist longer than 120s, the request is not reaching the
   browser's WebAuthn layer at all (points harder at an interceptor).

## Diagnostic plan for the next session

**Primary tool: the passkey lab** (`/admin/admin_passkey_lab`, superadmin,
built 2026-07-20 on dev). The failure is machine-side, and passkeys are
per-site while authenticators are not — so the owner enrolls the same
Windows Hello + YubiKey on the dev account, then runs the lab's variant
matrix from the failing machine. Each button fires one ceremony variant and
records the outcome (including browser-side rejections, with error name)
server-side under request-log feature `passkey_lab`:

1. Plain step-up (all keys, UV required, no extensions) — the failing shape
2. With PRF extension (the working shape) — tests hypothesis 1
3. Platform passkey only — tests whether the mixed allow list matters
4. Security key only — same, from the other side
5. UV preferred — re-tests the retired UV theory cleanly

One two-minute visit yields the full matrix. Supporting steps if the matrix
alone doesn't settle it:

- One incognito-window run of the failing variant (rules browser-extension
  interception in/out).
- Ask which surface showed the production error: the on-page dialog prefixes
  the error name (`SomethingError:`); the /verify-stepup page does not — the
  owner's quotes have never carried a prefix.

Once the discriminating variable is identified on dev, fix the production
step-up shape accordingly and re-attempt the scrolldaddy.app raise.

## Lab results (2026-07-20, owner's Windows machine)

The passkey lab ran its full five-variant matrix twice:

- **dev.getjoinery.com** (both authenticators freshly enrolled): **5/5 verified.**
- **jeremytunnell.com** (0.8.168, the existing production credentials): **5/5 verified** — including "plain step-up", the exact shape that failed all evening.

Every request-shape hypothesis is therefore dead: the machine, browser, both
authenticators, the mixed allow list, the extensionless request, and UV
required all work — on both sites, with both credential sets.

## Root cause

**The form-validation library raced the inline step-up interceptor and
navigated the page out from under the ceremony.** `JoineryValidator`
(assets/js/joinery-validate.js) attaches its own submit listener to every
form carrying validation rules — the domain editor's form qualifies (required
fields). On every submit it unconditionally `preventDefault()`s, validates
asynchronously, and on success re-submits via **`form.submit()`** — the
programmatic path that **bypasses all other submit listeners** (its own
comments admit this for payment forms and analytics). So on Update Domain,
two listeners fired on the same click:

1. The step-up interceptor: `preventDefault()`, begin the inline ceremony.
2. The validator: validate (fast, all-local rules), then `form.submit()` —
   native navigation, ignoring the interceptor's preventDefault entirely.

The navigation killed the in-flight WebAuthn ceremony. Depending on timing,
the client saw an instant `NotAllowedError` (Chrome killing `credentials.get`
on unload) or nothing at all; the server saw an options mint with no verify;
the POST arrived without a step-up marker and redirected to /verify-stepup.

Why every piece of evidence fits:

- Failures began at **16:47** on the dot — the deploy that introduced the
  inline interceptor. Before it, level changes used the redirect dance and
  worked. The YubiKey correlation was spurious.
- **Paired `stepup_options` calls ~2s apart**: the editor's doomed mint,
  then the user's click on the verify-stepup page they landed on.
- **The lab and the security page never failed**: no form, no validator —
  nothing racing the ceremony. Hence 5/5 everywhere while the editor failed.
- **The dev CDP e2e "pass" was a false pass**: the session had a fresh
  step-up marker, so the validator's raced-through POST succeeded
  server-side while the inline ceremony died unobserved.

**Fix (platform layer, 0.8.171):** joinery-validate.js 1.1.0 — a valid form
re-submits **natively** via `requestSubmit()` with a one-shot stand-aside
flag instead of `form.submit()`. The re-dispatched submit event reaches every
listener, so an interceptor's `preventDefault()` is honored: the ceremony
runs to completion on a live page, then re-submits with the marker stamped.
`requestSubmit(submitter)` also carries the clicked button's name/value
natively (the hidden-input preservation hack remains only for the
no-`requestSubmit` legacy fallback). This also un-breaks the documented
payment-tokenizer and analytics bypass classes.

The earlier fixes remain correct hardening: single-flight guards
(0.8.164/0.8.166), 120s timeout (0.8.167), error-name display (0.8.169),
client failure telemetry (0.8.170, feature `passkey_client`).

Confirmed: the scrolldaddy.app raise completed in one pass on the editor
page (2026-07-20), backlog sealed. Follow-up queued separately: the
post-raise UI should present a single completion receipt (the ceremony card
becoming the record of what changed) instead of scattered alerts.

## Constraints

- Owner wants the passkey path fixed properly — the TOTP fallback exists but
  is not an acceptable resolution.
- scrolldaddy.app (domain 3) remains at `standard` until this completes; the
  walkthrough goal (Private conversion + sealed reader test) resumes after.
