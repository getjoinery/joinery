# Mailbox Reader — Phone Layout

## Status

Built 2026-09-03, verified on dev at phone width (table at the end). Open: the iOS webview walkthrough (acceptance item 11). Uncommitted.

## Problem

On a phone the member mailbox (`/profile/mailbox/mailbox`) shows the mailbox
rail and nothing else. Reproduced on dev at 390×844 (iPhone width) and measured:

| Element | Measured |
|---|---|
| Reader area the viewport leaves over | 582px, `overflow: hidden` |
| Left rail (24 mailboxes + the active one's 7 folders) | 1157px, `flex-shrink: 0` |
| Message list pane | 0px tall, positioned 1331px down |

Three rules collide:

1. The reader's narrow-screen rule (`@media (max-width: 760px)` in
   `plugins/mailbox/assets/mailbox_reader.css`) stacks the rail on top of the
   main pane. Written 2026-06-06 for a page that scrolled.
2. The member app-page layout (`.page-content--app`, 2026-07-28, commit
   36afe420) pins the reader to the viewport leftover with `flex: 1 1 0` and
   the reader hides its overflow. The page stopped scrolling.
3. The rail keeps the desktop `flex: 0 0 264px` shrink factor of zero (the
   narrow rule only resets `flex-basis`). It is exactly as tall as its content
   and never yields.

So the rail fills the screen, is clipped wherever the screen ends, and the list
pane is squeezed to zero height below the clip. Nothing scrolls to it. The
rail's order — active mailbox, its folder list, then the remaining mailboxes —
is why the other inboxes are hidden too. Every member with more than a handful
of mailboxes or folders sees this; a Gmail-connected mailbox brings its labels
along as folders and hits it on its own.

A second, independent defect on the same screen (**B2**): the member header
overflows sideways. At 375px the account name in the user dropdown pushes
`.jy-header-right` to 544px, so the whole page scrolls horizontally.

Letting the page scroll again would show the list but is not a fix: with two
dozen mailboxes the reader would open on a full screen of rail to scroll past.
The original reader spec (`specs/implemented/inbound_email_mailbox_reader.md`,
"Layout") already said what narrow screens should do — panes collapse to a
single column, the switcher becomes a dropdown — and the dropdown half was
never built. This spec builds it.

## Goals

- On a phone the reader shows **one pane at a time**: the conversation list by
  default, an open conversation in its place, the rail only when asked for.
- The rail keeps its DOM, its behaviour, and its data. It changes how it is
  presented on a phone, not what it is.
- Phone Back (hardware key, edge-swipe, browser button) undoes the last
  in-reader step — closes the drawer, closes the conversation — never leaves
  the reader by surprise. The reading-entry mechanism that already does this
  for conversations is reused, not duplicated.
- No horizontal scroll anywhere on the member mailbox page at 375px, in Safari
  and in the native app's webview.
- Desktop and tablet (above the breakpoint) render byte-for-byte as today.

## Non-goals

- A native list screen in the iOS/Android apps. The apps load this page in
  their webview; it must work there as-is.
- The contacts/context panel on phones. It is hidden below 1100px today and
  stays hidden.
- The admin oversight reader (`/plugins/mailbox/admin/admin_mailbox_reader`).
  It shares the mount and gets the same CSS for free, but its acceptance is
  not part of this spec — admin phone work is `admin_mobile_responsive.md`.
- Touch gestures beyond tap and Back (swipe-to-archive, pull-to-refresh).

## Design

### One breakpoint

The reader's narrow rule moves from 760px to **768px**, the kit's own header
breakpoint (`assets/css/joinery-styles.css`). Below it the page is a phone
layout everywhere; above it nothing here applies. Reader and header collapse
at the same width so a device never gets a phone header over a desktop reader.

### The height model stays

The reader keeps filling the viewport leftover and scrolling internally; that
is correct on a phone and is what the app layout was for. The narrow rule
stops overriding it (`height: auto; min-height: 70vh` go). What changes is
what lives inside that height.

`body.jy-default:has(.page-content--app)` sizes itself with `min-height:
100vh`; on iOS Safari the toolbar makes 100vh taller than the visible screen
and the footer lands under the toolbar. It becomes `min-height: 100dvh` with
the `100vh` line kept above it as the fallback for browsers without `dvh`.

### Phone layout: three screens, one DOM

**1. List screen (default).** `.mbx-main` takes the full reader height. The
rail is hidden. A new **scope bar** sits above the list header, phone only:

```
┌──────────────────────────────────────────┐
│ ☰  info@scrolldaddy.app › Inbox      6   │  ← scope bar (button)
├──────────────────────────────────────────┤
│ [✓] ↻                     + New message  │  ← existing list tools
│ 🔍 Search mail…                          │
├──────────────────────────────────────────┤
│ Alice Example                    10:42   │  ← row line 1
│ Re: invoice — Thanks, I've attached…     │  ← row line 2
├──────────────────────────────────────────┤
```

The scope bar is a single full-width button showing the active mailbox
address, the active folder name, and the mailbox's unread count. It is the
phone's answer to "what am I looking at" — the job the rail highlight does on
desktop — so it is fed from the same place: `state.mailboxLabel` and the
folder name `selectFolder()` receives, both of which `setListContext()` already
sees. Rendering it is one more line in `setListContext()`; nothing new tracks
state. It is hidden above the breakpoint by CSS and never rendered into the
desktop layout's flow.

**2. Rail drawer.** Tapping the scope bar opens the existing `.mbx-rail` as a
left-side drawer: `position: fixed`, full viewport height, its own vertical
scroll, over a scrim that covers the main pane. It is the same element with
the same children, so mailbox rows, unread badges, the folder list under the
active mailbox, the Unmatched boxes, and the protection chip all work
unchanged. Selecting a mailbox or a folder closes the drawer (both paths run
through `selectMailbox()` / `selectFolder()`, which get one `closeRail()` call
each). Tapping the scrim or the drawer's close button closes it too.

The rail must scroll on its own inside the drawer — today it is content-sized
and depends on the reader clipping it. In the drawer it gets `height: 100dvh;
overflow-y: auto` and its `flex-shrink: 0` no longer matters because it is out
of the flex flow.

State: `.mbx-reader.rail-open`. Opening pushes a history entry marked
`{ mbxRail: true }` the same way `enterReadingHistory()` pushes
`{ mbxReading: true }`; the `popstate` handler closes the drawer when it leaves
that entry. Closing by tap hands the entry back like `leaveReadingHistory()`.
The three functions generalise to take the marker name rather than being
copied.

**3. Read screen.** Already built: `.mbx-reader.reading` hides the list view
and shows the read view, hides the rail, and the conversation carries its own
Back button (`.mbx-thread-back`) plus the history entry. On a phone the scope
bar hides too while reading (the conversation header names the mailbox
context better than the bar would). Compose — reply, forward, new message —
renders inside the read view today and stays there.

### Rows on a phone

The desktop row is one line: fixed-width sender, then subject + snippet
sharing the remainder, then time. At 327px the fixed sender column
(`--mbx-from-w`, 182px) leaves nothing for the subject. Below the breakpoint
a row is two lines:

- Line 1: sender (grows, clipped) and time (right).
- Line 2: subject and snippet on one clipped line, as today.

Checkbox and star stay in their columns spanning both lines; the paperclip
sits beside the time. Unread weight, the AI summary line, section headings,
Trash purge dates, and the Load more footer are untouched. This is CSS
(`flex-wrap` on the row, `order` on the pieces); the row markup does not
change.

### Floating panels

Four panels are `position: absolute` and drop below their trigger: the
select-all panel, the folder/label panel (`.mbx-folder-panel`), the row kebab
menu, and the compose contacts autocomplete (`.mbx-ac-dropdown`). The
autocomplete already spans its field edge to edge and needs nothing. The other
three get `max-width: calc(100vw - 2rem)` below the breakpoint, and a panel
anchored `left: 0` under a trigger in the right half of the list header
anchors `right: 0` instead. Verified one by one at 375px (see Acceptance),
not assumed.

### B2: the member header at phone width

In `assets/css/joinery-styles.css`, inside the existing
`@media (max-width: 768px)` header block:

- `.jy-user-name` is hidden; the avatar alone is the dropdown toggle.
- `.jy-header-right` and `.jy-header-icons` get `min-width: 0` so they can
  shrink instead of pushing the header wide.
- The Admin link keeps its text (it is short) but drops its horizontal padding
  to the icon-button size.

The member subnav (Dashboard / Email / Messages / Calendar …) already scrolls
horizontally inside itself with a hidden scrollbar; nothing changes there.

### The native app webview

The apps bridge a web session and load this page in **app display mode**
(`docs/mobile_apps.md`): no site header, no footer, `body.jy-app-mode`. The
reader layout above is the same there; B2 does not arise because the header is
not rendered. The scope bar is the only mailbox switcher the app user has, so
it is not conditional on chrome being present.

## Files

| File | Change |
|---|---|
| `plugins/mailbox/assets/mailbox_reader.css` | Breakpoint 760→768; delete the stacking rule; scope bar, drawer, scrim, two-line rows, panel clamps. Version bump. |
| `plugins/mailbox/assets/mailbox_reader.js` | Scope bar render in `setListContext()`; `openRail()` / `closeRail()`; `closeRail()` calls in `selectMailbox()` and `selectFolder()`; history helpers take a marker; `popstate` handles both. Version bump. |
| `plugins/mailbox/includes/mailbox_reader_mount.php` | Scope-bar button and drawer close button in the markup; scrim element. Version bump. |
| `assets/css/joinery-styles.css` | B2 header rules in the 768px block; `100dvh` on the app-page body rule. Version bump. |
| `plugins/mailbox/plugin.json` | Version bump (assets are cache-busted by mtime, the bump records the change). |
| `plugins/mailbox/docs/overview.md` | The member reader section describes the phone layout: scope bar, drawer, one pane at a time, Back behaviour. Current-state wording only. |

No PHP logic, no schema, no settings, no migration.

## Acceptance

All on dev at 390×844 (Playwright `browser_resize`), signed in as a member
holding several mailboxes, one of them with tracked folders. Measured, not
eyeballed — each line is a `getBoundingClientRect` / `scrollWidth` check.

1. `/profile/mailbox/mailbox` opens on the conversation list. The list pane
   has height > 0 and the first conversation row is inside the viewport.
2. `document.documentElement.scrollWidth` equals the viewport width on the
   list screen, the drawer, the read screen, and the compose screen. (B2)
3. The scope bar names the active mailbox and folder and shows its unread
   count. Switching mailbox or folder updates it.
4. Tapping the scope bar opens the drawer; every mailbox is reachable by
   scrolling the drawer; the drawer's own `scrollHeight` exceeds its
   `clientHeight` when the rail is taller than the screen.
5. Selecting a mailbox in the drawer closes it and loads that mailbox's
   Inbox. Selecting a folder closes it and loads that folder.
6. With the drawer open, `history.back()` closes the drawer and leaves the
   reader on the list. With a conversation open, `history.back()` returns to
   the list. Two Backs from a conversation opened via the drawer land on the
   list, then leave the page — never a dead press.
7. A row shows sender and time on line 1, subject and snippet on line 2; no
   row text overflows its line.
8. Each of the four floating panels opens fully inside the viewport.
9. Reply, forward, and new message open a usable compose on the read screen;
   the send button is reachable without horizontal scroll.
10. At 1024px width the page renders as it does on `main` today: rail in the
    flex row, no scope bar, no drawer classes, single-line rows.
11. Same walkthrough in the iOS member app webview (Mac mini, see
    `reference_mac_mini_ios.md`) for items 1, 3, 4, 5, 6, and 9. App mode has
    no header, so item 2 is Safari-only.

Existing suites: `plugins/mailbox/tests/mailbox_reader_test.php` (db tier)
and the `.mjs` slices are unaffected and stay green. There is no
browser-driven tier in the test estate; the walkthrough above is the gate, and
the measured numbers go in the commit message.

## Open

None. Swipe gestures and a native list screen are out of scope by design, not
deferred bugs.

## Verification 2026-09-03 (dev, Playwright at 390×844 and 375×812)

| Check | Result |
|---|---|
| 1 List pane / first row | list pane 582px tall, first row at y=340 (was 0px at y=1331) |
| 2 Sideways scroll | document scrollWidth 375 on a 390 viewport, 360 on 375; none on list, drawer, read, compose |
| 3 Scope bar | "appdev.phase2@… › Inbox 6"; follows mailbox and folder picks |
| 4 Drawer | opens; scrollHeight 1156 > clientHeight 844; all 24 mailboxes reachable |
| 5 Picks close it | mailbox pick and folder pick both close the drawer and reload the list |
| 6 Back | drawer → list → previous page; conversation → list → previous page; no dead press |
| 7 Rows | sender y=349 / time y=351 on line 1, subject+snippet y=374 on line 2 |
| 8 Panels | kebab 176–336, select 38–178, labels 131–382, bulk Move/Labels 131–382 (clamped by `keepPanelOnScreen`) |
| 9 Compose | reply opens inside the read view, no sideways scroll, Send in view after scrolling |
| 10 Desktop 1024px | scope bar `display:none`, rail static 264px in the row, rows `flex`, account name shown |
| 11 iOS webview | **not run** — needs the Mac mini phase-3 gate; open |

Two defects found and fixed during the walkthrough, beyond the spec's list:
the message card's sender block could not shrink below a full address and
pushed the time and menu out of the card, giving the reading pane a sideways
scroll (`.mbx-message-left`); and the folder/label panels hang from the left
edge of their button, so one under a right-side button ran off screen — a
measured slide-left after showing (`keepPanelOnScreen`) replaced the spec's
CSS-only clamp, which cannot know where the button is.

## Adjustments 2026-09-03 (owner feel-out)

- The page heading ("Email") goes on a phone. The reader moves the app bar's
  action nodes onto the scope row (`placeAppBarActions`, on load and on every
  breakpoint crossing) and marks the bar `mbx-app-bar-moved`, which hides it.
- AI and Actions are icons on a phone and words on a desktop. The kit owns the
  pair: an app-bar action carries `.jy-btn-icon` + `.jy-btn-label`; below
  768px the icon shows alone and the Actions caret is dropped. The AI button
  (joinery_ai `ai_panel.js` 1.2.0) and the mailbox view's Actions summary both
  render the pair.
- The kit footer is hidden below 768px on every page it renders.
- Verified at 390 wide: app bar and footer `display:none`, AI at 271–305 and
  Actions at 309–343 on the scope row, both menus open inside the screen,
  document scrollWidth 375. Desktop at 1024: heading, labels and footer back.
- Second round: the app page is full-bleed on a phone (`.page-content--app`
  padding 0 below 768px, kit) so the reader runs edge to edge from the subnav
  to the bottom of the screen; the search line is folded away behind a search
  icon on the scope row, immediately left of Actions (`toggleSearch`: opening
  focuses the box, closing with a term in it cancels the search). Verified at
  390 wide: reader 0–375 × 110–844, search filter 32→8 rows and back on close.
- Third round: section headings (Unread / Starred / Everything else) hidden
  on a phone; the list footer collapses when Load more is hidden, which was a
  20px blank strip under the last row. Reproduced in real WebKit (Playwright
  WebKit 18.2 on the Mac mini, iPhone 14 profile, 390×664): the reader spans
  110–664 and the rows now run to 664.
- Fourth round: the member section nav (Dashboard / Email / Messages …) folds
  behind a hamburger at the left of the header below 768px — a fixed panel
  under the header, one link per line, opened by `script.js` through the
  button's aria-controls; the same script finally wires the public site's
  nav hamburger, which had no handler (script.js looked for the pre-kit
  `.menu-toggle` / `.nav-links` classes). The scope bar drops the ☰ for a
  caret after the folder name. Verified at 390: toggle at 16–52, panel 61–624
  with 11 links, outside tap closes it, reader now starts at 61; public-site
  hamburger opens its nav 60–208; desktop 1024 unchanged.
- Fifth round: on a phone the list toolbar shows only for a selection (its
  bulk actions and count) or an open search; select-all and Refresh are gone
  (pull-to-refresh reloads); New message floats as a pill over the foot of
  the list (`.mbx-fab`, moved into the list view by `placeAppBarActions` so
  it is absent while reading); the protection chip joins the scope row.
  Verified at 390: rows start at 102 straight under the scope row, pill at
  216–359 × 786–828, toolbar 102–156 with bulk tools when a row is ticked and
  gone again when unticked; desktop 1024 unchanged.
- Sixth round: the scope row's three buttons, the New message pill and the
  scope-row unread count are monochrome (white / hairline border / dark
  glyph; count on dark grey). The rail's own blue badges and the row
  checkbox accent are untouched.
