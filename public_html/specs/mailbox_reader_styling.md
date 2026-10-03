# Mailbox Reader — Styling Surface and Layout Modes

## Status

Spec written 2026-10-02. Not started. Depends on the Style Themes spec for the override path (a style theme replaces the retired Custom CSS setting as the way a site restyles the reader); the token, layer and layout work stands on its own.

## Problem

The member mailbox reader (`/profile/mailbox/mailbox`, also mounted in the
admin) has one look, modelled on Gmail: a swap layout (the open conversation
replaces the list), single-line rows with a star column, and a command bar
that appears only once rows are ticked. A member who wants a different look,
Outlook's three panes and card rows for instance, has no way to get one short
of a theme.

A theme is the wrong tool twice over. The reader draws its own markup in
JavaScript, so a theme's view override changes the page chrome and nothing
inside the reader. And a theme is PHP the server runs on every request, so
installing one that is not ours is a signed-package event (the warning page,
the step-up, the Unsigned badge, the email to every superadmin), which is the
right gate for PHP and the wrong experience for someone who wants a blue
accent and a reading pane.

What an operator can change about the reader is its CSS, and the site has
a place for that: a style theme (the Style Themes spec), a package of
stylesheets, fonts and images that layers over the page theme and installs
with no warning. The reader is not built to be styled that way:

| Measured in `plugins/mailbox/assets/mailbox_reader.css` | |
|---|---|
| Lines | 1,758 |
| Selectors | 254 |
| Colour literals | 339 (100 distinct) |
| Kit token uses (`--jy-*`) | 5 |
| Reader tokens (`--mbx-*`) | 13, all sizes |

A reskin today means overriding most of the file. The row's column order and
the swap layout are decided in the JavaScript, so a different row shape or a
side-by-side reading pane cannot be reached from CSS at all.

## Goals

- **One block of variables restyles the reader.** Every colour, font, radius,
  spacing, border and pane width the reader uses is a `--mbx-*` custom
  property with a kit default. A complete reskin is a few dozen overrides,
  one short stylesheet in a style theme.
- **Layout is a declared mode, chosen by the member.** Swap (the list gives
  way to the open conversation) or split (the list stays beside a reading
  pane). The JavaScript never hides a pane itself; CSS reads the mode.
- **The reader's markup is a published contract.** Every element the reader
  draws has a stable `mbx-*` class, rows always render their full set of
  children, and the list is documented as the styling surface.
- **Two shipped presets, Classic and Fluent.** Classic is today's look.
  Fluent is the Outlook look: three panes, card rows with an initial avatar,
  a persistent command bar, Segoe-style type, 4px radii, blue accent. A
  member picks one on the mailbox settings page. A style theme layers on
  top of whichever is chosen.
- **Nothing in the tree changes for a custom look.** No package, no upload,
  no signing.

## Non-goals

- A theme that overrides reader markup. The reader's DOM stays the
  JavaScript's to draw.
- A style editor in the browser. A look is a file, written elsewhere.
- A bottom reading pane. The mode attribute leaves room for it; this spec
  builds swap and split only.
- Restyling the compose window's rich-text toolbar beyond tokens. Its
  buttons keep their markup.
- The native apps. They render the same reader in a webview and inherit
  whatever this spec does; nothing app-side changes.

## Design

### Tokens, with the kit as the default

The reader root (`.mbx-reader`) declares every token it uses, defaulting to
the kit's own (`assets/css/joinery-styles.css`, the `--jy-*` set):

```css
.mbx-reader {
	--mbx-font:            var(--jy-font-sans);
	--mbx-accent:          var(--jy-color-primary);
	--mbx-accent-hover:    var(--jy-color-primary-hover);
	--mbx-text:            var(--jy-color-text);
	--mbx-text-muted:      var(--jy-color-text-muted);
	--mbx-surface:         var(--jy-color-surface);
	--mbx-surface-alt:     var(--jy-color-surface-alt);
	--mbx-border:          var(--jy-color-border);
	--mbx-radius:          var(--jy-radius-md);
	--mbx-rail-w:          264px;
	--mbx-list-w:          380px;      /* split mode only */
	--mbx-row-read-bg:     var(--mbx-surface-alt);
	--mbx-row-unread-bg:   var(--mbx-surface);
	--mbx-row-active-bg:   color-mix(in srgb, var(--mbx-accent) 12%, var(--mbx-surface));
	/* ...the full set is the stylesheet's own header comment */
}
```

Rules in the stylesheet use tokens and nothing else: no colour literal, no
font name, no radius in pixels. The one exception is a value that is the
same in every conceivable style (a `1px` border width, `0`). The existing 13
size tokens stay and join the set.

Light and dark: the kit's tokens already change under the dark scheme, so a
reader that reads only tokens follows. The three hover and selection colours
that Gmail-style rows derive from blue literals today become `color-mix()`
of the accent and the surface, so a dark preset needs no extra rules.

### Cascade layers settle who wins

Three sources style the reader, and they must win in a fixed order whatever
the page emits first: the reader's own rules, then the chosen preset, then
the site's style theme. Page order cannot guarantee this (the look's
stylesheet is in the head; the reader mounts in the body), and specificity
cannot either (a preset must select by mode and style attributes, which
outranks the plain `.mbx-reader` a look would write).

Cascade layers do. The reader stylesheet wraps its rules in
`@layer mbx-base`, the presets in `@layer mbx-style`, and the file opens with
`@layer mbx-base, mbx-style;` so the order is fixed before either is filled.
A style theme's stylesheet is unlayered, and unlayered rules beat every
layer regardless of specificity or order. A look's
`.mbx-reader { --mbx-accent: tomato }` wins over the preset's
`.mbx-reader[data-style="fluent"] { --mbx-accent: ... }` with no
`!important` and no specificity games.

### Layout mode: one attribute, CSS decides

The reader root carries `data-layout="swap|split"`. The JavaScript sets it
once at mount from the member's preference and never touches pane visibility
again. The `reading` class stays as the one signal that a conversation is
open; what it does is the stylesheet's decision per mode:

| | swap (today) | split |
|---|---|---|
| List while reading | hidden | visible, `flex: 0 0 var(--mbx-list-w)` |
| Read view while reading | fills the main column | beside the list |
| Read view while not reading | hidden | visible, an empty state ("Select a conversation") |
| Back arrow | shown | hidden (the list is already there) |
| Active row | n/a | highlighted (`.active`, already set) |
| Phone width | the phone layout spec's one-pane rule, unchanged | same: split collapses to swap below the breakpoint |

Three JavaScript changes follow from this, all small:

1. Opening a conversation in split mode must not blank the list or lose its
   scroll position (it does not today, because the list is only hidden; the
   change is that nothing hides it).
2. Arrow keys on the list move the open conversation in split mode (Outlook's
   behaviour); in swap mode they keep today's meaning.
3. The empty read-view state is rendered once at mount and shown by CSS.

The phone layout spec established the rule "the elements exist in every
layout; CSS decides whether they show". This spec extends that rule to the
desktop.

### The row is a grid of named areas

`threadRow()` always emits the full set of children, in a fixed order, each
with its class:

| Class | Content | Classic | Fluent |
|---|---|---|---|
| `mbx-thread-check` | select box | shown | shown on hover / when any row is ticked |
| `mbx-thread-star` | star | shown | hidden (`display: none`) |
| `mbx-thread-avatar` | sender's initial, **new** | hidden | shown |
| `mbx-thread-from` | sender | one line | line 1, bold when unread |
| `mbx-thread-subject` | subject | line 1, after sender | line 2 |
| `mbx-thread-snippet` | preview, **split out of `mbx-thread-mid`** | line 1, after subject | line 3, muted |
| `mbx-thread-count` | message count | inline | after sender |
| `mbx-thread-clip` | attachment mark | inline | top right, under time |
| `mbx-thread-time` | time | right | top right |
| `mbx-danger-badge`, `mbx-direct-mark` | flags | inline | after sender |

The row is `display: grid` with `grid-template-areas`, and each preset
writes its own areas. Reflowing a one-line row into a three-line card is then
a preset's CSS and no JavaScript. The avatar is the one new element: a
`<span>` holding the first letter of the sender's display name, with the
sender's address on `title`, always rendered, hidden by Classic.

The existing `mbx-thread-mid` wrapper goes; its two children (subject,
snippet) become direct children of the row so the grid can place them.

### The command bar

`renderBulkActions()` renders the same buttons it does now, always. A
`data-selection="none|some"` attribute on the list header says whether
anything is ticked. Classic hides the bar at `none` (today's behaviour);
Fluent shows it at all times with the buttons disabled at `none`. The
"New mail" button, which lives in the rail today, gains a second home at the
start of the command bar; each preset shows one and hides the other.

### Presets

`plugins/mailbox/assets/mailbox_reader_styles.css`, declared in the plugin's
`styles` list after the reader stylesheet, holds every preset in
`@layer mbx-style`, each scoped by `.mbx-reader[data-style="<name>"]`:

- **classic**: no overrides. The reader's defaults are the Classic look.
  Exists so "Classic" is a real choice in the list and so a future change
  to the defaults is a change to Classic, by name.
- **fluent**: tokens (font stack starting with "Segoe UI", accent `#0f6cbd`,
  4px radius, flat 1px borders, pale grey rail), row areas for the card
  shape, the persistent command bar, and `--mbx-list-w: 380px`. Fluent
  implies split layout as its default but does not force it; the layout
  preference is separate.

A preset never contains a rule that is not a token override, a grid-area
assignment, or a show/hide of a contracted element. That is the test for
whether something belongs in the preset or in the base stylesheet.

### The member's preference

Two values per member, style and layout, stored server-side so they follow
the member across devices and into the apps:

`plugins/mailbox/data/mailbox_reader_preferences_class.php`,
`MailboxReaderPreference`, prefix `mrp`, table `mrp_mailbox_reader_preferences`:

| Column | Type | Values |
|---|---|---|
| `mrp_usr_user_id` | int, unique | the member |
| `mrp_style` | varchar(32) | `classic` (default), `fluent` |
| `mrp_layout` | varchar(16) | `swap` (default), `split` |

Read and write permission: the row's own member, or a superadmin, following
`CalendarPreference`. Unknown values are refused at save. The mount reads
the row (or the defaults) and sets `data-style` and `data-layout` on the
reader root before the JavaScript runs, so the first paint is already in
the chosen mode and nothing flashes.

The mailbox settings page gains a **Look** section above Signature: a
FormWriter form with two selects, Style (Classic, Fluent) and Reading pane
(Off, Beside the list), posting to the page as the other sections do. Each
option carries one line of help text saying what it changes. The gear on the
reader already links to the settings page.

### Documentation

`plugins/mailbox/docs/styling.md`, linked from the mailbox overview: how a
style theme reaches the reader, the full token list with defaults, the
element contract (every `mbx-*` class a preset may rely on, by region: rail,
list header, row, read view, compose), the two presets, and a worked example
("make Classic use your brand colour", "make Fluent dense"). Written in the
current-state voice the docs rules require.

A change to a contracted class name or a token name bumps the stylesheet's
version header and the doc together.

## Files

- `plugins/mailbox/assets/mailbox_reader.css` — tokens, layers, grid rows,
  mode rules
- `plugins/mailbox/assets/mailbox_reader_styles.css` — **new**, the presets
- `plugins/mailbox/assets/mailbox_reader.js` — mode attribute at mount, row
  children, avatar, persistent command bar, split-mode keys, empty read state
- `plugins/mailbox/includes/mailbox_reader_mount.php` — reads the preference,
  sets the root attributes, renders the empty read-view state
- `plugins/mailbox/data/mailbox_reader_preferences_class.php` — **new**
- `plugins/mailbox/logic/mailbox_settings_page_logic.php`,
  `plugins/mailbox/views/profile/settings.php` — the Look section
- `plugins/mailbox/plugin.json` — the second stylesheet in `styles`, version
- `plugins/mailbox/docs/styling.md` — **new**; `overview.md` links it
- `plugins/mailbox/tests/mailbox_reader_styling_test.php` — **new**

## Work packages

- **WP1 — tokens and layers.** Rewrite the stylesheet so every value is a
  token with a kit default, wrap it in `@layer mbx-base`, and declare the
  layer order. The rendered Classic look is unchanged: screenshot
  before/after on dev at desktop and phone widths, and the diff is empty.
  A safe-tier test reads the stylesheet and fails on any colour literal,
  `font-family` with a literal name, or pixel radius outside the token block.
- **WP2 — the row contract and the mode attribute.** Row children, avatar,
  snippet split, grid areas; `data-layout` and `data-style` set at mount;
  split-mode CSS; the empty read state; split-mode arrow keys; the
  persistent command bar with `data-selection`. Classic still renders
  pixel-identical to WP1.
- **WP3 — the preference and the settings section.** The data class, the
  mount reading it, the Look section on the settings page, the API action
  the page's save goes through (`_logic_descriptor()` on the settings logic,
  per the API rule). `update_database` on dev to create the table.
- **WP4 — the Fluent preset.** Tokens, areas, command bar. Proven on dev in
  split and swap, desktop and phone, light and dark, with the Fortress and
  AI panels docked (their slots are contracted elements too).
- **WP5 — the doc and the proof of the goal.** Write `styling.md`. Then the
  acceptance that matters: with Fluent chosen, apply a style theme on dev
  whose one 30-line stylesheet turns the reader green and dense, and see it
  win over the preset with no `!important`.

## Acceptance

1. After WP1 the stylesheet contains no colour literal outside the token
   block, and Classic renders unchanged (screenshot diff on dev, desktop and
   phone).
2. A member on Classic who sets Reading pane to "Beside the list" sees the
   list stay beside the open conversation, the active row highlighted, and
   the up/down keys move the open conversation.
3. A member who chooses Fluent sees three panes, card rows with initials, a
   command bar that is present with nothing ticked and enabled once a row is,
   and the Fluent type and colours. Switching back to Classic restores today's
   reader exactly.
4. The preference follows the member to another browser and into the app
   webview.
5. A style theme of token overrides changes the reader on both presets and
   wins over the preset's own values with no `!important`.
6. The phone layout's one-pane behaviour holds on both presets and both
   modes at 390×844.
7. The Fortress banner, the AI panel and the contacts panel dock correctly on
   both presets.
8. `styling.md` lists every token and every contracted class, and a reader of
   it alone can write the green-and-dense example in WP5.
9. `php tests/run.php db --changed` passes; the new safe-tier test fails on a
   reintroduced colour literal.

## Open

- **Q1** — should Fluent be the default for new members, or Classic? This
  spec defaults to Classic (no change for anyone on upgrade); the owner may
  prefer the three-pane look as the first impression.
- **Q2** — the admin mount shares the reader. Does an admin's own preference
  apply there, or does the admin mount stay Classic/swap? This spec applies
  the preference everywhere the reader mounts, since it is the same person
  reading mail.
