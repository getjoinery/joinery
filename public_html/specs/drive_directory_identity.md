# Directory identity

**Status: being built as WP2 of `drive_sync_reset.md`, from 2026-09-12.** The
two decisions that spec makes (an encrypted folder's id may claim; no id
falls back to today's rules), its precedence table, and its reader-by-reader
bars govern the build; where this document and that one differ, that one is
current.

A folder record does not know which directory on the disk is its own. It finds
out by looking at what stands at the path it expects and what is inside it. That
one gap is the root of Defects AC, AD, AE, AF and AG, and this spec closes it.

**A one-device drag reaches it.** Defect AG needs no second computer, no name
trade, no fault injection and no concurrency: a user with a vault holding one
file makes a folder, drags the file into it, and their vault is renamed onto the
new folder while a new unprotected folder inherits the vault's name. Everything
they save into the name they trust afterwards is published in the clear. This is
not a soak-only curiosity to close after the racing defects; it is the shortest
path to a disclosure on the board.

## What the user gets

Folders keep their identity when their contents move. Renaming a folder, moving
a file out of it, and emptying it are three different acts today only if the
engine can guess which happened; after this it can tell.

## The mechanism

The filesystem already answers "which directory is this" -- every directory has
an inode, stable across renames and moves within a volume, exactly as files do.
Nothing reads it.

1. **The VFS reports a `file_id` for directories.** `DirEntry` already carries a
   `fingerprint` slot and answers for a directory in `read_dir`; a new
   `Vfs::directory_id(path)` answers for a path. `vfs.fingerprint` KEEPS
   returning `None` for a directory: twelve executor sites read its `Some`
   as "a file stands here", which is what `make_room`'s comment records, and
   they must not change meaning. Real and mock both learn to answer. Size
   and mtime stay meaningless for a directory: only the id is read.
2. **Folder entries record it.** The `synced_fp_file_id` column already exists on
   `entries`. A folder writes its directory's id there when it materializes one
   or adopts one, exactly as a file records its inode.
3. **Three readers, and one more:**
   - **Adoption** (`pass.rs`, the folder mint): a directory whose id belongs to a
     live folder record is that folder, whatever its name or contents. Never
     minted as new. This is route 2 and AG.
   - **`make_room`**: whose directory is this? The owner's record follows it, or
     the move stands down. This is route 1.
   - **`move_local`'s source**: is the directory standing at `from` mine? This is
     AC's shape at the executor, and 0e's candidate for 74019.
   - **`crossing_a_vault_edge`**: did the FILE's directory change? A user drag
     moves a file between directories; the engine re-attributing a directory
     around a file that never moved is not a drag and must not convert.

## Why a tie-break cannot substitute

The tempting near-term rule -- *a standing directory at an encrypted folder's
agreed path keeps its identity; contents decide only when the directory is gone*
-- is right for AG and wrong for its mirror. A user who renames the vault
`Private` to `Plain` and then makes a new empty `Private` produces the same disk
and the same server state with the opposite intent. Under the standing-directory
rule the sealed file reads as dragged out and is published in the clear; under
today's rule AG inverts the protection of a name the user trusts. **No reading of
names and contents is right in both worlds**, because the two worlds are
identical in names and contents. Only the directory's own identity separates
them.

## What lands before it -- superseded 2026-09-12

This section proposed a HOLD for an encrypted folder whose pairing rests on
contents alone (its directory standing empty at its agreed path while its
known child sits under another directory), to land ahead of the identity
work. It never landed, and the reset spec supersedes it: the identity work
is being built directly, and in it the id world decides by identity (the
standing directory that carries the record's id IS the folder, so the child
under another directory moved out -- a drag, which converts) while the no-id
world runs today's rules for one pass and re-records the id on the next
corroborated pairing. A hold in front of that would stall the ordinary
drag-out to buy one pass of protection in a world the fix makes rare. The
`empty_and_encrypted` ambiguous arm in `pass.rs` is a different case (a
missing vault beside a new empty directory) and stays as it is.

## Tests

- AG's pin: one keyed device, a vault holding one file, a drag into a new
  folder. The vault keeps its id, its name and its protection; the new folder is
  plain; the file's plaintext is on the server under its own name. Both
  provenances -- seeded-and-downloaded, and written locally -- because they were
  measured to behave identically and a future change must not split them.
- The mirror: rename the vault and make a new empty folder under its old name.
  Nothing is published; the vault keeps its id and its protection.
- The ring arm's id-set oracle (`assert_the_ring_ids_are_intact`): exactly the
  three seeded ids, live, with their seeded protection, and no root folder
  beyond them.
- The identity-survives assertion the AF work already demands of
  `a_second_folder_conflict_at_one_name_gets_its_own_name`: the displaced folder
  keeps its server id rather than being re-minted.
- Every folder-identity change carries AD's rule: refuse_before, lose_answer_to,
  chaos, and a kill immediately after the change.

## Stability and absence

**A directory's `file_id` inherits every rule `synced_fp_file_id` already lives
under**, which settles both questions in one sentence: *it funds order, never
identity* -- with one exception the reset spec decides: an ENCRYPTED folder's
recorded id may claim the directory that carries it, because a wrong claim
over-seals and over-sealing never publishes, while the alternative is a hold
on every rename of an empty vault.

- **It corroborates, it never claims.** The id confirms a pairing the existing
  rules already proposed from name, parent and contents; it never proposes one
  by itself. So a recycled id, or one from a foreign volume, costs at most a
  stand-down -- never an adoption. That is the same bargain the inode gets
  today, and it is what makes a recycled id survivable rather than catastrophic.
- **Record the volume with it.** Windows file reference numbers are per-volume,
  and the VFS personality already carries what files need for the same reason.
- **A recorded id that no longer exists is not an error.** A restore, a
  re-created sync root, a swapped volume: the folder falls back to today's rules
  and re-records the id on its next corroborated pairing. The id is a cache of
  evidence. Its absence is never a reason to forget a folder.

## Open

- Nothing blocking. The two questions this section replaced were answered by the
  rule above (public-html-0e, 2026-09-10).
