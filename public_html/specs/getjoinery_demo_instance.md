# getjoinery.com Public Demo Instance

**Status:** Deferred — owner decision 2026-08-09. The site redesign launches as a **beta with screenshots as its proof device**; the demo follows as its own project when the owner green-lights it. This spec exists so the deferral is deliberate and the design is ready when that day comes. Companion: `specs/getjoinery_site_redesign.md` (§9.1 resolves to this deferral).

---

## Why a demo (one paragraph)

The redesign's research pass found a public demo is the #1 de-risking device in this space: every ease-leader competitor offers one, and for a small project it is the strongest available proof that the software is real, polished, and maintained. All three target audiences share the "verify, don't trust" register — a demo is the purest form of verification. This stays true after a beta launch; the demo's value doesn't expire.

## What it is

- A standard Joinery install on its own small Linode instance, publicly reachable (e.g. `demo.getjoinery.com`), running the same personal bundle a real install gets (mail, calendar, drive, contacts, AI where sensible).
- **Auto-resetting:** restored to a seeded snapshot on a schedule using the existing backup/restore machinery — the reset is a restore, not custom tooling.
- Visitors land in a lived-in account: demo credentials printed on the marketing site, or an auto-login link.

## Seed content

A convincing fictional household — the marketing story made tangible:

- Mailboxes with realistic threads (family logistics, a newsletter, an order confirmation).
- A shared family calendar with entries, an invitation, and a reminder configured.
- Drive with folders, photos, and documents; at least one item at the Private protection level so the encryption story is visible.
- Contacts populated.
- All content fictional; no real names, addresses, or photos of real people.

## Hardening (build-time requirements)

- **Outbound email disabled entirely** (email service left unconfigured, or pointed at a sink) — the demo must be structurally unable to spam.
- Tiny storage quota and small upload caps.
- No payment configuration; Store not installed.
- Reset cadence is the primary abuse defense; keep it short enough that vandalism is self-cleaning.
- `noindex` on the demo host — demo content must never outrank the marketing site.

## Integration with the marketing site

When the demo goes live, the redesign spec's reserved slots activate:

- Hero secondary CTA flips to **"See it live."**
- Nav gains the **Demo** item (§4 of the redesign spec).
- `/install`, the audience landing pages, and the homepage proof section gain demo links.
- Until then, none of these links exist anywhere — the zero-dead-CTA rule governs.

## Open questions (decide at build time, not now)

1. Reset cadence (hourly vs nightly).
2. Whether the demo exposes the admin surface — for the household-admin audience, seeing the admin *is* part of the pitch; read-only or resettable admin access is worth considering against the abuse surface.
3. One shared demo account vs per-visitor ephemeral accounts.
4. Whether the AI assistant is live in the demo (cost + abuse) or shown via screenshots inside the demo.
