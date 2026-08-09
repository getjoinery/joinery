# getjoinery.com Redesign — Private Google Workspace Replacement

**Status:** **Ready to build — every §9 decision is resolved** (§9.2/§9.4 earlier; §9.1, §9.3, §9.5–§9.8 by the owner on 2026-08-09, each recorded in place in §9). Launch framing: a **beta**, with screenshots as the proof device (demo deferred to `specs/getjoinery_demo_instance.md`). Pricing/licensing fully decided (§5.4). Governing copy constraint from §9.8: **no unbuilt protocol or client names anywhere on the site.** Companion spec `specs/automatic_install_mail_topology.md` has its own short open list.
**Updated 2026-08-09** to reflect platform work landed since the draft: calendar reminder/summary emails shipped (§9.8), Drive Private tier + encrypted sync + soak-rig hardening (§5.3), the AI sealed-content guard (§5.3), Ubuntu 24.04/26.04 install targets (§5.2), fleet backups with write-only node keys (§5.4), and Fortress/sending-identity hardening (§9.3). Copy claims below now cite the shipped mechanisms.
**Scope:** Structure and copy only. The visual design (getjoinery theme, warm stone/amber palette) stays. This spec defines the new information architecture, page-by-page content, copy direction, and what moves to developers.getjoinery.com.

---

## 1. The repositioning in one paragraph

getjoinery.com stops selling "membership software" and starts selling **a self-hosted replacement for Google Workspace / Nextcloud: email, calendar, and drive on a server you own**. The single primary goal of every page is to get the visitor to **install** — free self-install, free Linode one-click, or $39.99 automatic install. The current developer/framework/membership story moves to **developers.getjoinery.com**. Paid plugins (Store, Server Manager) are promoted prominently but always as a secondary lane that never blocks the install funnel. Business use is monetized by pay-once lifetime licenses — a 200-seat Founder cohort at $499 with every first-party paid plugin free for life, then a standard $399 lifetime license — presented in the pricing page's business lane, never in the install funnel's way. The Vault/password manager stays out of the marketing story for now.

---

## 2. Target audiences

Three audiences, one shared core promise. They are segmented in the **nav and dedicated landing pages, never in the hero** (the Ghost/Proton pattern) — the homepage tells one story with one CTA.

### Audience A — Gmail leavers (de-Googlers)
People actively trying to get their email (and usually calendar/files) away from Google.

- **Top motivations, in order:** account-lockout fear ("one automated flag and Google can erase 15 years of your life" — this beats abstract privacy), AI reading their mail (the 2025 Gemini "silent switch" backlash), being the product, ownership/ideology. Cost is *not* a motivator — they willingly pay to escape free.
- **Top objections, in order:** **outbound deliverability** (everyone in this audience has read the "self-hosting email, I've thrown in the towel" post — this objection must be named and answered, not avoided), losing 15 years of archive, hundreds of sign-ins tied to their address, losing the integrated calendar, spam filtering getting worse, maintenance burden.
- **What converts them:** an explicit deliverability answer — outbound mail goes through an established mail provider (SPF/DKIM/DMARC configured for you), which is exactly the fix this audience already believes in (the HN consensus is "your MX, their SMTP"); Gmail import that names the source ("import your Gmail — mail, contacts, calendar"); the custom-domain reframe ("do the painful address change once, never again"); open standards as an exit guarantee (IMAP, CalDAV, CardDAV, export everything).

### Audience B — Nextcloud-alternative seekers (self-hosters)
r/selfhosted types running or evaluating Nextcloud, frustrated with it.

- **Their pain with Nextcloud, in order:** slow bloated web UI (they quote its 15–20MB JS payload at each other), sync clients that lie ("said it was fully synced, only 200GB of 800GB was there") — the trust-killer, upgrades that break the install, "does forty things, none of them well," maintenance as a lifestyle.
- **What they value:** sync that never lies, speed on a cheap VPS (numbers, not adjectives), polished mobile apps, CalDAV/CardDAV/WebDAV/IMAP with the clients they already use (DAVx5, Thunderbird), boring 5-minute upgrades, and above all: **"your data is just files and a Postgres database"** — backup is rsync + pg_dump, leaving is copying a folder.
- **Their skepticisms:** license rug-pulls (the #1 modern fear — ownCloud/Kiteworks is fresh), solo-project abandonment, data traps, phone-home telemetry, "production ready" claims with no demo. **PHP has a stigma in this niche; Postgres is a pure credibility asset.** Don't hide the stack, don't lead with PHP — lead with Postgres and measured performance numbers.

### Audience C — Privacy-focused users (incl. household admins)
The privacyguides.org / r/privacy crowd, plus the privacy-conscious person setting up email/photos/files for a spouse and kids.

- **Threat models, in order:** profiling/surveillance capitalism, account lockout (most visceral), AI training on their data, government access/jurisdiction (CLOUD Act; the 2025-26 EU sovereignty wave), breaches.
- **Trust signals they require:** precisely-described encryption (state the mechanism or nothing), inspectable source, easy full export and custom domains, self-hostability as proof of honesty ("if I can take the server with me, they can't betray me"), **public-facing indie ownership and a plainly stated business model** ("you pay us; that's the whole model"). Paying is a *trust signal*, not a barrier.
- **The family angle** (underserved by every competitor): frame as protecting people who won't protect themselves — "your kids' photos aren't AI training data" — and promise the spouse/kids experience is normal-app easy. Never ideological.

**Cross-audience convergence — the spine of all copy:**
1. **Lockout/ownership is the strongest shared emotion.** "Nobody can lock you out of your own server" is the one claim no hosted competitor (including Proton) can make.
2. **Deliverability is the shared killer objection.** The answer is sending through established mail providers — your domain, your archive, their sending reputation. Say so by mechanism. The relay is a *separate* selling point: security and privacy (your server never has to be exposed to the internet as a mail target, and inbound mail is held for you if your box is down) — never pitch the relay as the deliverability fix.
3. **"Verify, don't trust"** is the shared register: demo, source, export, plain-files storage, stated business model.

---

## 3. Copy rules (apply to every page)

**Do:**
- Ease claim + named incumbent in headlines ("private Google Workspace," "Nextcloud alternative") — the incumbent's name does the explaining.
- Numbers over adjectives: page-load ms, RAM on a $5 VPS, minutes to install, upgrade duration.
- Name objections before the reader does (deliverability, migration effort, maintenance) and answer with mechanism.
- Honest friction: "switching is real work; here's the checklist and the tooling" converts better than "switching is easy!"
- State the business model in one sentence wherever money appears.
- Name only shipped capabilities — the honest set today: `.ics` import (Google/Apple/Outlook) and export, ICS feeds, Gmail import, full data export, the native mobile/desktop apps. **Never name a protocol or client the platform does not serve** (§9.8): no CalDAV, CardDAV, WebDAV, or IMAP-serving claims, no Thunderbird/DAVx5/Apple Mail promises, until they exist. §2 records that these audiences *want* those protocols — copy answers that desire honestly ("not yet, here's what is real"), it does not claim them.
- "Your data is a folder and a pg_dump."

**Don't (instant BS-detector triggers, all documented):**
- "Military-grade encryption," "unbreakable," "anonymous," "NSA-proof," "bank-level" — privacyguides.org formally rejects providers for this language.
- "Production ready" / "enterprise grade" without a demo behind it.
- Claim more encryption than the architecture delivers; state limits proactively.
- Fear-mongering surveillance copy — empowerment ("own it") outperforms dread.
- Trash-talk Proton/Google by name; position against the architecture ("renting trust"), not the brand.
- Hide pricing, funding, or the stack.
- Call it "open source" (licensing is PolyForm Noncommercial + commercial; see §8) — and don't say "source available" as a headline either; lead with the verifiable behaviors (code you can read, export everything, no telemetry, runs air-gapped) rather than a license label.

---

## 4. New site structure

### Navigation (header)

| Item | Route | Notes |
|---|---|---|
| Apps | `/apps` | Replaces Features |
| Install | `/install` | **First-class nav item** (Cloudron pattern — the only site in the space that does this, and it's the ease leader) |
| Pricing | `/pricing` | Rewritten (see below) |
| Why Joinery | `/why` | Replaces Philosophy |
| Developers | developers.getjoinery.com | Single exit door; no dropdown |

Right cluster unchanged (login/signup/account). Footer: Apps · Install · Pricing · Leave Gmail · Nextcloud Alternative · For Families · Why Joinery · About · Developers · GitHub · Privacy · Terms.

**Audience doors** live in the footer + homepage section + SEO, not the header: `/leave-gmail`, `/nextcloud-alternative`, `/families`.

### Page inventory

| Route | Status | Purpose |
|---|---|---|
| `/` | rewrite | One story, install CTA |
| `/apps` | new (replaces `/features`) | Mail / Calendar / Drive deep sections + full app grid |
| `/install` | **new — the money page** | All three install paths, laddered |
| `/pricing` | rewrite | Free software + install options + paid plugins |
| `/leave-gmail` | new | Audience A landing (SEO: "gmail alternative", "leave gmail", "de-google") |
| `/nextcloud-alternative` | new | Audience B landing (SEO: "nextcloud alternative") |
| `/families` | new | Audience C household landing |
| `/why` | rewrite of `/philosophy` | Ownership manifesto + business model + licensing honesty |
| `/about` | light edit | Solo-dev honesty stays — it's a trust asset for these audiences |
| `/terms`, `/privacy` | keep | — |
| `/features`, `/philosophy`, `/showcase`, `/developers`, `/documentation`, `/license` | redirect | See §7 |

---

## 5. Page-by-page content

### 5.1 Homepage `/`

Draft copy is directional — refine at implementation, but keep the argument order.

1. **Hero.** Ease claim + named incumbent + ownership.
   - H1: **"Your own private Google Workspace."**
   - Sub: "Email, calendar, and files on a server you own. Nobody mining it, nobody training AI on it — and nobody who can lock you out. Installed in minutes."
   - Primary CTA: **Install Joinery** → `/install`. Secondary: **See the apps** → `/apps`. (When the demo ships — `specs/getjoinery_demo_instance.md` — the secondary CTA flips to **See it live** and the nav gains a Demo item.)
2. **Trust bar** (rework existing `trust_badges` component): *Runs on your server · Export everything, anytime · Import from Gmail · No telemetry · Free to self-host*.
3. **App grid mapped to incumbents** (the Proton-suite-grid × Nextcloud-"better Microsoft 365" combination): Mail ↔ Gmail, Calendar ↔ Google Calendar, Drive ↔ Google Drive/Photos, Contacts ↔ Google Contacts; second row, smaller, labeled where paid: Store (paid), Server Manager (paid), Events, AI Assistant, Bookings. One line each.
4. **The email section — name the objection.** H2: "Self-hosted email has a reputation problem. We fixed the part that matters." Body: everyone's read the horror stories about outbound delivery from a home-grown server; Joinery sends outbound mail through established mail providers (SPF/DKIM/DMARC configured for you), so your mail *lives on your server* but *arrives with a real provider's reputation* — the exact architecture the self-hosting community itself recommends. Your archive, your domain, your rules; only the last hop is borrowed. Separate follow-on point: the **relay** as a security/privacy feature — your server never has to sit exposed on the internet accepting mail, and inbound mail is held for you if your box is offline. Relay claims use the full-mechanism wording decided in §9.3 (deletes on confirmed delivery; never sees outbound in the default mode); providers named: Mailgun, Amazon SES, or any SMTP smarthost.
5. **The ownership section.** H2: **"Nobody can lock you out of your own server."** Three tiles: the lockout story (one automated flag vs. your own hardware), the AI story (no silent switches; nothing reads your mail unless you install it — and then it's your AI), the exit story ("Your data is a folder and a Postgres database. Backup is rsync and a pg_dump. Leaving is copying a folder.").
6. **Audience doors.** Three cards → `/leave-gmail`, `/nextcloud-alternative`, `/families`.
7. **Install teaser.** The three options with prices (Free / Free / $39.99) → `/install`.
8. **Paid plugins strip** (secondary lane, one row, after the funnel content): "Run a business on it, too." Store + Server Manager, one sentence each → `/pricing#plugins`.
9. **Honest proof section.** No invented numbers. GitHub link, real screenshots, "built by one developer, in the open" → `/about`. An honest **beta** label fits here — this audience rewards stated maturity and punishes overclaiming. (Demo link joins when the demo ships.)
10. **Final CTA.** "Own your email again." Install + Apps buttons (Install + Demo once the demo ships).

### 5.2 `/install` — the money page

Nextcloud's /install is the structural template: one URL, every technical level finds its rung. Order the ladder **easiest first** with honest burden accounting (the Plausible pattern) — the free paths are never hidden or shamed.

1. **Intro line:** "Three ways in. Same software, same ownership — your server, your data, in every case. We never have access."
2. **Option 1 — Automatic Install, $39.99 one-time.** "We install it for you, on *your* server." Connect your Linode account, we provision the server, install, configure SSL and mail, and hand you the keys. At checkout the buyer chooses their mail topology — single server, or server plus a private mail relay (the Fortress-ready setup) — and **one price covers the complete setup either way**; the relay option just adds a second small instance to their own Linode bill, disclosed at the choice. Your account, your bill, your root password. One-time fee, nothing recurring. CTA: **Start automatic install** → checkout → the existing server_manager customer-cloud flow (`/profile/server_manager/connect_cloud`). Mechanics: `specs/automatic_install_mail_topology.md`.
3. **Option 2 — Linode One-Click, free.** Deploy from the Linode Marketplace in ~5 minutes; you create the server, the image does the rest. Honest prerequisites listed (a domain, ~$X/mo server cost, pointing DNS). CTA: **Deploy on Linode** (referral link — disclose it plainly; see §5.5).
4. **Option 3 — Self-install, free.** The literal command printed on the page (`curl … install.sh`-style, matching `docs/installation.md`) — printing the one-liner *is* the CTA for the technical segment and signals "genuinely simple" better than any copy. Prerequisites stated honestly (stock Ubuntu 24.04 or 26.04 VPS, 2GB RAM, a domain — the installer hard-fails on anything else, so the page should name exactly those two). Link to full install docs on the developers subdomain.
5. **"Which one should I pick?"** — a 3-row honest comparison: time, skill needed, what you maintain. Include the Plausible-style candor line: DIY is free and always will be; the $39.99 buys the afternoon back.
6. **Below the fold:** what happens after install (first-run setup, import from Gmail, install the apps on your devices), the backup/restore story ("a folder and a pg_dump" + Server Manager mention), and an FAQ hitting: deliverability, "what if I want to leave," upgrade experience, what it runs on.

### 5.3 `/apps`

Replaces `/features`. Three deep sections + a grid:

- **Mail** — the flagship section. Custom domain ("change providers forever without changing your address"), Gmail import by name, deliverability via the named providers (Mailgun, Amazon SES, or any SMTP smarthost — §9.3), the relay as security/privacy with the full-mechanism claim (§9.3), read your mail in the webmail and the native apps (no IMAP-serving claim — §9.8), spam filtering (be honest about maturity), DKIM/SRS handled.
- **Calendar** — shared household calendars; invitations; ICS feeds; **email reminders and daily summaries, opt-in per member with per-entry overrides (shipped 2026-08-07)** — a concrete "it does the thing Google Calendar does" proof point. Also `.ics` import from Google/Apple/Outlook. No CalDAV/client-sync claims (§9.8 — the surface doesn't exist yet); the FAQ answers "CalDAV?" honestly.
- **Drive** — sync clients (desktop + mobile), E2E encryption described precisely and now *shipped*: the **Private protection level** gives client-custody end-to-end encryption with encrypted content syncing end to end — state that scope and its limits plainly, never a buzzword. Files stored as real files, photo backup, browse view with thumbnails. The sync-honesty claim (Audience B's trust-killer) can now cite mechanism: the desktop client is hardened under a continuous kill/chaos soak rig with a no-loss oracle — but the client is still pre-release, so `/apps` gives it an honest maturity label until it ships.
- **Everything else grid:** Contacts, Events, Bookings, AI Assistant (your AI, on your hardware — the post-Gemini hook; and a verifiable guard behind it: **sealed content is architecturally blocked from leaving the server through the AI** — exactly the "verify, don't trust" register §2 calls for), Store (paid), Server Manager (paid), with honest per-feature maturity labels rather than a blanket "production ready."
- Close with an **objection-answering comparison** vs Google Workspace and Nextcloud on the axes this audience actually uses: who can read your data, who can lock you out, sync honesty, upgrade experience, resource usage (with a measured number), export path.

### 5.4 `/pricing`

Rewritten around "free software, paid convenience" (Cloudron model — the cap is never on privacy or ownership). **The page is two lanes and should feel that simple: personal use is free except the $39.99 automatic install and two optional paid plugins; business use is a pay-once lifetime license.**

**Personal lane:**

1. **The software: free.** Email, calendar, drive, contacts, events — the whole suite, free to self-host for personal use, plainly stated. License terms in one honest sentence (noncommercial free; businesses → the business lane below).
2. **Install options:** Free / Free / $39.99 (mirrors `/install`).
3. **The two paid plugins** (`#plugins` anchor): **Store, $99 one-time** — sell products, subscriptions, memberships from your own server, 0% platform transaction fees. **Server Manager, $149 one-time** — scheduled backups to your own cloud bucket with write-only node keys (a compromised server can't erase its own backups — a real mechanism, worth the sentence), one-click upgrades, fleet management. Each with a one-line "who it's for." Pay-once, matching this audience's documented preference for one-time pricing on self-run software.

**Business lane** (`#business` anchor):

4. **Founder license — $499, limited to 200.** Lifetime business license: one production install, lifetime updates, and **every first-party paid plugin free forever** — the current two ($248 of plugins today) and everything published later. The pitch is stated honestly: founders are betting the plugin catalog grows, and the $100 premium over the standard license buys the whole catalog for life — already rational on day one against today's two plugins alone. Show a **real live counter** ("N of 200 remaining") — genuine scarcity displayed plainly, never a countdown timer or invented deadline.
5. **Standard business license — $399 lifetime.** Opens when the founder cohort sells out. One production install, lifetime updates, plugins sold separately, occasional sales. Dev/staging copies free (standard convention, stated).
6. **The no-guarantee line**, one calm sentence, not a banner: "Lifetime licensing is how we price today; we don't guarantee it will always be offered." (Precedent: Roon carried exactly this caveat for years, then honored it — buyers on the warning felt vindicated, not tricked.)
7. **The business-model sentence** (privacy audiences require it): "Joinery is funded by business licenses, the paid install, two paid plugins, and hosting referral fees. You pay us; that's the whole model. No ads, no data, no investors to satisfy."

The old hosted tiers ($29/$59/$99) and the White Glove $249 card are **gone** — they do not appear anywhere on the marketing site.

### 5.5 Audience landing pages

Each is a focused, honest, SEO-targeted page reusing existing components (`marketing_hero`, `comparison_cards`, `feature_showcase`, `cta_section`), ~5 sections, ending in the install CTA:

- **`/leave-gmail`** — Lead with lockout + AI-reading, not abstract privacy. The migration section is honest-friction: the checklist (set up domain first, import mail/contacts/calendar, keep Gmail alive during transition, update sign-ins gradually), with the custom-domain "never again" reframe. Deliverability section repeated here. The disclosure style for the Linode referral: state it ("Linode pays us a referral fee — that's part of how Joinery is funded") — this audience rewards disclosure and punishes discovery.
- **`/nextcloud-alternative`** — Lead with the four things done properly, not forty done badly. Sync honesty, boring upgrades ("upgrade without holding your breath" + measured upgrade time), plain-file storage, Postgres, resource numbers on a cheap VPS. This audience wants CalDAV/WebDAV — the FAQ answers that honestly per §9.8 ("not yet; here's the export story") rather than dodging. Address the stack directly in an FAQ ("Yes, PHP — here's the page-load number") rather than hiding it.
- **`/families`** — The household-admin story: one server, everyone's email/calendar/photos; kids' photos aren't training data; spouse experience is normal-app easy; shared family calendar; "you administer it, they just use it." Benefit-led, never ideological. This is also where the $39.99 automatic install is most prominent — this buyer wants the afternoon back.

### 5.6 `/why` (replaces `/philosophy`)

Keep the commitments structure but reorient from "membership platform" to ownership: the lockout story, the incentive-alignment argument (renting trust vs owning the server), the commitments (self-hostable, export everything, no telemetry, no data selling, free for personal use), the business model stated plainly, and the licensing question answered honestly (see §8). The solo-developer story links to `/about` — for these audiences an accountable named human beats a faceless VC-funded team, and the research shows they check.

### 5.7 `/about`

Light edits only: swap "membership software" framing for the new story; fill the "Photo coming soon" placeholder or remove the slot.

---

## 6. developers.getjoinery.com

Everything developer/framework/builder-oriented moves off the marketing domain (docs.ghost.org / docs.cloudron.io pattern — marketing keeps exactly one "Developers" exit door):

**Moves there:**
- `/developers` content (stack, architecture, security internals, REST API, plugin system, theme system) — becomes the subdomain's homepage.
- `/documentation` — the full docs tree. **Note:** today this serves all 45 internal docs plus plugin docs publicly with no permission check; the move is the moment to decide which docs are genuinely public developer docs vs internal (e.g. `publish_upgrade_system_analysis.md`, `deploy_and_upgrade.md` read as internal). Curate, don't mirror blindly.
- `/showcase` — reframed as "built on Joinery" (it already reads that way).
- `/license` and the commercial self-hosting license pitch.
- The **membership-platform story** (current homepage/features positioning, clubs/nonprofits audience grid): parked as a "build a membership site on Joinery" use-case page on the developers subdomain rather than deleted — it's a real use case, just no longer the lead.

**Implementation shape (decided 2026-08-09, §9.5): a second Joinery install.** developers.getjoinery.com is its own deployment on the managed fleet with its own theme — no new platform capability needed (theme-per-deployment already exists), upgraded through the same Server Manager tooling as every other node. Its content (docs presentation, showcase, license, membership use-case page) is seeded into that site's own DB components. The docs tree ships in the codebase, so the docs content itself arrives with every upgrade; the curation decision (§6 above — which internal docs are genuinely public) gates what the site *renders*, not what the repo carries. Marketing nav gains one "Developers" link out; developers subdomain nav links back to "getjoinery.com" and to Install.

---

## 7. Redirects and route changes

- `/features` → `/apps`
- `/philosophy` → `/why`
- `/developers` → `https://developers.getjoinery.com/`
- `/documentation` (and its subpaths) → `https://developers.getjoinery.com/documentation/...`
- `/showcase` → `https://developers.getjoinery.com/showcase`
- `/license` → `https://developers.getjoinery.com/license`
- Fix the `/docs` vs `/documentation` broken-link inconsistency as part of the move.
- All former "Start Free Trial" CTAs (currently dead `href="#"` on home, features, pricing ×3, philosophy) are replaced by working Install/Demo CTAs — there must be **zero dead CTAs** on the new site.

---

## 8. Licensing copy (a real tension — handle deliberately)

The self-hosted audience's #1 skepticism is license rug-pulls, and they read "source available" as hostile. Joinery's licensing (PolyForm Noncommercial + commercial licenses, per the open-core work) cannot be described as "open source" — and must not be. The spec's resolution: **compete on verifiable behaviors, not license labels.** Copy leads with: code you can read on GitHub, export everything in standard formats, no telemetry, runs air-gapped, free forever for personal use — and then states the license plainly in one sentence where licensing is genuinely relevant (`/pricing`, `/why`, developers site), without euphemism and without apology. A blunt, permanent statement of what stays free is the documented antidote to rug-pull fear.

---

## 9. Open questions (owner decisions)

1. ~~Live demo instance.~~ **Resolved 2026-08-09:** the site launches as a **beta** with screenshots as its proof device; the demo is deferred to its own spec, `specs/getjoinery_demo_instance.md`, which reserves the CTA/nav slots that activate when it ships. No demo link appears anywhere until then (zero-dead-CTA rule).
2. ~~Do hosted tiers survive?~~ **Resolved:** hosted tiers are removed from the marketing site entirely. Personal use is free except the two paid plugins and the $39.99 install; business use is the lifetime-license lane (§5.4).
3. ~~Exact relay and mail-provider claims.~~ **Resolved 2026-08-09 (owner), three parts:**
   - **Providers:** name 2–3 battle-tested ones — lead with **Mailgun**, plus **Amazon SES** and **any SMTP smarthost** — with an "and more" link to docs for the rest of the catalog (SendGrid, Postmark, Brevo, Mailjet, Resend). Never imply equal battle-testing across all eight.
   - **Relay offer: self-hosted only.** The relay is free software on your own second small instance (the ~$5/mo Linode cost, disclosed plainly, on *your* bill). Hosted relay slots exist in the code (`mrl_is_hosted`) but are **not advertised** — keeps the no-recurring-charges pricing story clean.
   - **Relay privacy claim: full mechanism, stated exactly.** The relay is your MX, so it sees inbound mail in transit like any front-door mail server — say so. Then the verifiable part: your server pulls the spool and **the relay deletes each message the moment your server confirms durable storage** (delete-after-store is the acknowledgment); mail is held on the relay only while your server is unreachable; and in the default outbound mode (provider API) **the relay never sees outbound mail at all** — smarthost is an explicit opt-in. All claims match `RelaySpoolConsumer` behavior as shipped.
4. ~~Paid plugin prices.~~ **Resolved:** Store $99, Server Manager $149, both one-time. Business licensing: Founder $499 (200 seats, all first-party plugins for life) → standard $399 lifetime with occasional sales, plus the no-guarantee-forever line (§5.4).
5. ~~developers subdomain mechanics.~~ **Resolved 2026-08-09 (owner): a second Joinery install** on the managed fleet with its own theme (§6). No host-header theme capability gets built for this.
6. ~~"Automatic install" scope.~~ **Resolved 2026-08-09 (owner):** the $39.99 product is exactly the existing server_manager customer-cloud flow (buyer's own Linode account and bill, one-time fee, root password handed over) — confirmed. **No post-install support promise:** the fee covers the installation itself; copy must not imply ongoing setup help. The install page's below-the-fold "what happens after install" content stays informational (docs, not promises).
7. ~~Audience set.~~ **Resolved 2026-08-09 (owner): confirmed as specced** — three audiences, with `/families` as Audience C's landing page rather than a fourth audience.
8. ~~Calendar maturity.~~ **Resolved 2026-08-09 (owner): headline calendar, claim only what ships.** The "email/calendar/files" positioning stays. Verified platform facts behind the decision: the calendar has recurring entries, `.ics` import (Google/Apple/Outlook), outbound ICS feeds, and reminder + daily/weekly summary emails (shipped 2026-08-07) — but **no CalDAV, no CardDAV** (`docs/calendar.md` states it), **and the mail side serves no IMAP to clients** (IMAP exists only as a pull-source; there is no Dovecot in the stack). Consequence, applied throughout this spec: **no unbuilt protocol or client name appears anywhere on the site** — no CalDAV/CardDAV/IMAP-serving claims, no Thunderbird/DAVx5/Apple Mail promises. The device story is the web apps + the native mobile/desktop apps; the exit-guarantee story is export-everything, `.ics` in and out, and "your data is a folder and a pg_dump." Where a self-hoster will ask "CalDAV?", the FAQ answers honestly: not yet.

---

## 10. Implementation notes (for the build phase, not this spec)

- **Copy lives in the database.** All marketing pages render DB components by slug (`ComponentRenderer::render('gj-home')`), seeded from `utils/seed_getjoinery_content.php`. The rewrite means new/updated `gj-*` component content and a re-run of the seeder; check for DB drift from the seed file before overwriting.
- **Nav is hardcoded** in `theme/getjoinery/includes/PublicPage.php` (header ~lines 126–235, footer below) — the nav changes in §4 land there.
- **Reusable components already exist** (`marketing_hero`, `gj_feature_grid`, `comparison_cards`, `audience_grid`, `pricing_teaser`, `feature_showcase`, `trust_badges`, `cta_section`) — the new pages should compose these, adding new components only where a genuinely new shape is needed (the install ladder, the app-grid-vs-incumbent map).
- **`/pricing` route** is currently an explicit serve.php route into the store plugin, shadowed by the theme view — the rewrite keeps the shadow but must not break store checkout routes (`/checkout`, `/cart`), which the $39.99 flow depends on.
- **Business licenses ride the existing open-core machinery** (license products + the key-minting purchase hook, already built): Founder and Standard are license-product SKUs, and the founder plugin bundle is an entitlement attached to the founder license key. The plugin entitlement gate is deferred (`specs/plugin_entitlement_gate.md`), so enforcement is honor-system at launch — acceptable, since the buyers are self-selecting believers. The "N of 200 remaining" counter must be driven by real sold-count inventory on the founder product (the store's stock mechanism), never a hand-edited number.
- **Screenshot debt:** the current site ships "Screenshot coming soon" ×9. The new site's proof strategy (real screenshots + demo) makes this a launch blocker for the pages that promise proof.
- **Linode one-click is a launch dependency, not a given.** The StackScript mechanism is built (targets Ubuntu 26.04 first, 24.04 kept) and getjoinery.com serves releases itself, but the **Marketplace listing** (`specs/linode_quick_deploy_app.md`) is still unshipped — it needs the live gates plus Akamai's PR process. Until it's listed, `/install` Option 2 either links the StackScript directly with honest copy ("deploy via our StackScript") or ships hidden. Don't write "Linode Marketplace" copy against a listing that doesn't exist.
- Developer docs updates (routing/theme docs if the host-header theme selection changes) go into the relevant existing `/docs/` files at implementation time.
