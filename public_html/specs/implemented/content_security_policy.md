---
title: Content-Security-Policy (CSP) Header
status: Phase 1 built 2026-09-02 — settings, header, policy builder, safe test (tests/security/csp_header_test.php). The rollout step (enable report-only, use the site, enforce) is operator work and has not been run on any site
priority: medium
---

# Content-Security-Policy (CSP) Header

## What this does for users

A CSP header tells the browser which sources of scripts, styles, and other
content are allowed to load, so an injected `<script>` (XSS) or a data
exfiltration to an attacker's domain is blocked by the browser itself. The
site currently sends no CSP header at all — the known gap is marked
`// TODO (security): Implement Content-Security-Policy.` at
`includes/PublicPageBase.php:97` (HSTS was added separately; CSP was not).

## Phase 1 — permissive, toggleable policy (build this now)

Small, safe, shippable: one header, gated on settings, no code refactoring.

1. **Settings** (declare with factory defaults in `settings.json` at the
   `public_html/` root — seeded automatically, no migration):
   - `enable_csp` (bool, default off)
   - `csp_report_only` (bool, default on) — when on, send
     `Content-Security-Policy-Report-Only` instead of the enforcing header,
     so violations log to the browser console without breaking anything.
2. **Emit the header** in `PublicPageBase` (the same layer that sends HSTS),
   replacing the TODO:
   ```
   default-src 'self'; script-src 'self' 'unsafe-inline';
   style-src 'self' 'unsafe-inline'; img-src 'self' data: https:;
   font-src 'self' https:; connect-src 'self' https:;
   frame-ancestors 'self'
   ```
   `'unsafe-inline'` stays in Phase 1 — the codebase (FormWriter output,
   view templates, plugins) relies heavily on inline scripts/handlers/styles,
   and removing them is the strict-CSP project below, not this ticket.
   External payment scripts (Stripe/PayPal) and any other third-party
   resources found during rollout get appended to the relevant directives.
3. **Rollout:** enable with `csp_report_only` on; browse the public site,
   checkout, and admin while watching the DevTools console for violations;
   add any legitimately-needed sources to the policy; then flip report-only
   off.

### Acceptance

- With `enable_csp` off (factory default): no CSP header sent — zero change
  for existing deployments.
- With it on + report-only: `Content-Security-Policy-Report-Only` header
  present, site fully functional.
- With it on + enforcing: header present; public pages, forms (FormWriter),
  checkout (Stripe), photo upload, and admin pages all function; an injected
  inline `<script>` from a non-whitelisted source is blocked.
- Test: a `safe`-tier test asserting the header is present/absent according
  to the two settings (curl against the dev site or header-capture in the
  harness).

## Future (explicitly not Phase 1) — strict CSP

Dropping `'unsafe-inline'` requires nonces on every inline script, FormWriter
emitting no inline event handlers, inline styles moved to classes, and a
per-plugin resource audit — a large refactor with high breakage risk. Do not
start it until Phase 1 has run enforcing in production and there's a concrete
driver. A violation-report endpoint (`report-uri`/`report-to`) is a sensible
first step of that project.

## References

- [MDN: Content-Security-Policy](https://developer.mozilla.org/en-US/docs/Web/HTTP/CSP)
- [OWASP CSP Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Content_Security_Policy_Cheat_Sheet.html)
- [CSP Evaluator](https://csp-evaluator.withgoogle.com/)
