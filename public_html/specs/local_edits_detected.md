# Local Edits Detected

## Status

Spec written 2026-10-03 as the follow-up the Replacing an Installed Package
from an Upload spec names. Not started. Depends on that spec's fork model
(a local fork is a live manifest saying `receives_upgrades: false`, which
every deploy preserves) being built first.

## Problem

The fork model marks a package a local fork when it arrives by upload. An
edit made on the node itself, by an operator at a shell or by the site's
own AI acting on the operator's behalf, leaves the package marked as
Joinery's: the Plugins and Themes pages show nothing, and the next deploy
replaces the edited files with the shipped version without a word. The
operator finds out when their change is gone.

Every installed package carries the signed file listing it was shipped
with (`RELEASE_MANIFEST` and its signature), so whether the files on disk
are still the ones Joinery shipped is a question the site can answer
without any flag: `PackageSignature::verify()` already does, and the
health panel already uses it to list unsigned packages.

## Goals

- **The pages say when a shipped package has been edited on this site.**
  A badge, **Edited since install**, beside the extension whose files no
  longer match their signed listing, with the first differing file named
  on hover.
- **One button makes it a fork.** "Keep my changes" writes
  `receives_upgrades: false` to the live manifest through the
  `set_receives_upgrades` root request the pages already use, so the next
  deploy preserves it. The confirmation says the cost the fork model's
  badge says.
- **The deploy does not decide on its own.** An edited package that is
  not a fork is still replaced on deploy, as today; the deploy's extension
  table says "edited here, will be replaced" for it so the transcript
  carries the warning. Preserving silently because a file changed would
  skip a security fix because somebody touched a comment.

## Non-goals

- Showing a diff. Naming the first differing file is enough to say what
  happened; the operator's own tools show the rest.
- Detecting edits to an unsigned package. It has no listing to compare
  against, and it is already marked and already a fork.
- A three-way merge when Allow upgrade is pressed on a fork. The shipped
  version replaces the fork; `public_html_last` is the undo.

## Design

`PackageSignature::verify()` on the live directory, once per page load per
extension, cached for the request. `signed` means untouched; `tampered`,
`extra_file` or `missing_file` means edited here, and the verdict names the
file. `unsigned` and `unknown_key` are not edits: they are the Unsigned
badge's business. The health panel's existing unsigned listing is the model
for where the check runs and how its cost is kept off every request.

The deploy's `get_installed_extension_info()` adds `edited` from the same
check, and the extension table it prints says "edited here, will be
replaced" when `edited` is true and the live manifest still says `true`.

## Acceptance

1. A shipped plugin with one changed file shows Edited since install on
   the Plugins page, naming the file on hover; an untouched one shows
   nothing.
2. Keep my changes queues `set_receives_upgrades` false; after it runs the
   badge is Local fork and the next deploy preserves the plugin.
3. An edited plugin that is not a fork is replaced on deploy and the
   transcript's extension table said it would be.
4. The check adds no catalog fetch and no root request to a page load.
