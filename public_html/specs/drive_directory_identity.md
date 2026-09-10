# Directory identity

**Status: specified, not built. 2026-09-10.**

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
   `fingerprint` slot; `vfs.fingerprint` returns `None` for a directory today,
   which is what `make_room`'s comment records. Real and mock both learn to
   answer. Size and mtime stay meaningless for a directory: only the id is read.
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

## What lands before it

An encrypted folder whose pairing rests on contents alone -- its directory
standing empty at its agreed path while its known child sits under another
directory -- is HELD. Folder and file both `Unsyncable`, one issue naming both
readings: *"Private was emptied and Plain holds its file: was Private renamed,
or was the file moved out?"* No rename pushed, no conversion, no adoption of
either directory. The `empty_and_encrypted` ambiguous arm already says exactly
this for its own case.

A hold costs a stall with a sentence the user can act on. Both alternatives cost
a vault.

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
identity*.

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
