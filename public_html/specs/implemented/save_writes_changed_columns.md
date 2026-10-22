# Saving a record writes only what changed

**Status:** built 2026-10-22 (B12, owner's option 1 over Drive-only row locks). Verified on dev: the pins in tests/models/save_changed_columns_test.php and the seal, signal, egress-guard and event suites, knockouts, and the full db gate. reviewer1 VALID. The live checks (the Drive soak on the test server, a sealed-content save on a real vault) are in the live verification queue.

## The problem

Saving a record that already exists writes every column back from the copy in memory, including columns the caller never touched. A copy loaded a moment earlier therefore puts back whatever changed in between:

- a rename that started before a trash brings the file back out of the trash;
- a trash, a rename and a move undo each other;
- an upload's date or size is set back over a version that landed after the copy was loaded;
- the same race exists for every model on the platform, not only Drive.

B11 protected two Drive columns with `insert_only`. This makes the rule general.

## The rule

1. **An update writes the columns the caller set.** `set()` marks a column changed. Loading a row (`load()`, `load_from_data()`, the constructor's `$and_load`) starts with nothing changed. An update writes only the changed columns.
2. **An insert writes every column**, as it does today. Defaults and `zero_on_create` still apply only on insert.
3. **After an update the copy holds the row as stored.** The same UPDATE returns every column it did not write (`RETURNING`), and the copy takes those values. What the caller set stays as the caller set it: no type changes for JSON arrays or `now()` strings the caller is holding.
4. **A save leaves the copy clean.** After a successful insert or update nothing is marked changed, so saving the same copy again writes nothing.
5. **An update with nothing changed writes nothing.** No UPDATE runs; the copy is still refreshed from the row; the seal step and the post-save steps still run; the return value is the same as any successful save.
6. **`insert_only` stays.** It still refuses a column on update even when the caller set it. Its read-back becomes part of rule 3.
7. **Syncing a copy after a targeted UPDATE does not mark it changed.** A new `set_as_stored($field, $value)` records a value the database already holds. The read-back, `load_from_data()`, and the existing targeted-UPDATE callers (FileVersion, the files copy-on-write split, repair) use it.
8. **Sealed columns are unchanged.** They already travel through the seal plan only when set, and the seal metadata columns are already never written by save(). A sealed column the caller did not set is neither written nor sealed.

## How save() runs an update

reviewer1's design review, 2026-10-22, is folded in here.

- **Each column of an update falls into one of four cases:**
  - Unchanged: not written, read back.
  - Changed and written: written, and the copy keeps the caller's value.
  - Changed but refused by `insert_only`: not written, read back. File's post-save steps act on the stored value.
  - Changed but on the seal path: a column a `seal_on_save` model seals is unset after sealing. A sealed column a `seal_on_save=false` model skips keeps its plaintext and its sealed mark.
- **The read-back skips any column that carries a sealed mark,** even after a save has cleared its changed mark. So the two marks differ on purpose: a second save of a `seal_on_save=false` copy keeps the plaintext and its mark, as today.
- **The early `return FALSE` for an empty column list goes.** A caller that sets only a sealed column has nothing for the UPDATE, yet the seal step must still run. When nothing is written, no UPDATE runs. The seal transaction opens whenever there is a seal plan, whether or not an UPDATE runs.
- **Order of steps:**
  1. the UPDATE with `RETURNING` the read-back columns (or a SELECT when nothing is written);
  2. the read-back is applied to the copy;
  3. `applySealOnSave()`;
  4. the commit;
  5. the changed set is cleared;
  6. the post-save steps.

  If the seal step throws, the transaction rolls back and the copy stays changed, so a retry writes again.
- **After a first-time seal,** the copy's key, owner and generation columns are not refreshed. `applySealOnSave()` sets only the flag, and save() never writes those columns. This is unchanged.
- **A row deleted under the save** (the UPDATE matches no row, nothing is returned) leaves the copy as it was.
- **The sealed-egress guard** (`SealedEgressGuard::destinationProtectsOwner`) recognizes an UPDATE ending in a WHERE on the table's own id. It also accepts a trailing `RETURNING` of plain column names, which sends nothing anywhere.
- **`DbConnector`** gains a way to fetch the row an executed statement returned.
- **A save inside a caller's transaction that later rolls back** leaves the copy clean while the row is not. Saving the same copy again writes nothing; reload it to retry.
- **`-NOUPDATE-` throws** if a value still carries it, instead of writing the literal string. Themes and plugins outside the repo could still use it.
- **`User::save()`** compares against a snapshot taken at load to decide whether to send `account.updated`. It uses `changed_columns()` taken before `parent::save()` instead, so a field another request changed does not count as changed by this save.

## What has to move with it

The sweep looked for direct writes to a model's data that skip `set()`, uses of the old `-NOUPDATE-` sentinel, keyed saves of records never loaded, subclass overrides, callers testing save() for FALSE, clones, and tests asserting the old behavior.

### Inventory (read-only sweep, 2026-10-22)

**Breaks, and changes with this work:**
- `RecipeRun::saveContent()` (`plugins/joinery_ai/data/recipe_runs_class.php`) collects every sealed column holding a value, not the ones the caller set. With rule 3, a sealed run's ciphertext comes back into the copy on the first save, and the next `saveContent()` would seal that ciphertext again, leaving the output unreadable. It collects only the changed columns, through a new `changed_columns()`. Pin: two `saveContent()` calls on one sealed run read back both writes.
- The read-back and `set_as_stored()` must not go through `set()`. `Conversation::set()` throws on a protection change, and `InboundEmailDomain::set()` adds conversion columns. Both write `$this->data` directly, inside SystemBase.
- The read-back fills only unchanged columns. Their sealed mark is cleared, so `get()` decrypts them as it does after `load()`.
- `load_from_data()` clears the changed set, as it already clears the sealed marks. `load_from_object()` (no callers) keeps its marks.
- `applySealOnSave()` writes the flag into `$this->data` directly, so it is not marked changed.

**Simplify, in the same change:**
- `-NOUPDATE-` has no users; save() refuses it rather than honoring it.
- `AdminMenu` builds rows with `new AdminMenu($id)` plus `set()` (`data/admin_menus_class.php`); it moves to `load_from_data()`.
- `ProblemReport::save()` re-reads `prr_occurrences` before saving only to survive the full-row write; the re-read goes.
- About 30 places mirror a targeted UPDATE into the copy with `set()`; they move to `set_as_stored()` (users, sync_devices, site_support_threads, file_blobs, file_versions, files, DriveFolderLevel, SessionControl, repair_missing_file_blobs, relay_cloud_provisions, mail_import_runs, desk_threads, SupportIntake, joinery_ai conversations and messages, ChatConversationLevel, RecipeRun::sealToOwner).

**Checked, unchanged:**
- 61 subclass overrides of save()/load()/set()/load_from_data() all go through `set()`. Columns that a `save()` override sets every time are simply always written.
- No keyed object is saved without being loaded. No `clone` of a row is saved. No database triggers depend on the column list.
- Only test code compares save() with FALSE, and only on inserts.
- No test asserts the full-row write. The B10/B11 Drive pins, `blob_layer`, `seal_on_save` and `staged_rollout` already assert the new behavior.
- `updateColumns()` keeps working; its callers can move to `set()` plus `save()` later. Some must stay: sealed columns, and writes made during a GET.
- Validation still checks every column, which is unchanged.

**Derived columns (reviewer1 build review, F1):** `Event::save()` and `EventSession::save()` recompute the UTC times from the local times on every save. Under rule 1, a stale copy saving an unrelated column would write a UTC time derived from a local time it does not write, leaving the row disagreeing with itself. Each UTC time is derived only on insert, or when the save sets its own local time, the timezone, or (for a session) the event, so a copy setting only the end time leaves the start alone. Every other `save()` override that sets a column sets its own normalized value or an update stamp, which is unchanged.

**Docs:** `docs/example_class.php` (insert_only; the save rule), `docs/sealed_vault.md` (§ Writing), and `plugins/mailbox/docs/overview.md` (the rule backlog's per-owner places) describe the current behavior. Comments that gave a whole-row save as the reason for a targeted UPDATE are rewritten.

## Testing

- **Pins, red on today's code, green after:**
  - two copies of one record, each changing a different column, keep both changes, on a plain model and on File;
  - a trash plus a rename of the same file, in both orders, leaves it trashed and renamed;
  - a save after a targeted UPDATE does not put the old value back;
  - saving a clean copy issues no UPDATE.
- **Guard pins, green before and after** (they fail if the build gets the sealed path wrong): two `saveContent()` calls on one sealed recipe run read back both writes; a read-back sealed column decrypts through `get()`.
- **Sealed rows:** set a sealed field on a copy whose row sealed after it was loaded. The wrapping survives, and the content reads back. The existing seal suites pass unchanged.
- **Review pins (reviewer1):**
  - a `seal_on_save` sealed row where the caller sets only a sealed column: it seals and reads back;
  - the first seal of an existing plaintext row through save(): afterwards no plaintext is left and `get()` does not throw;
  - setting an `insert_only` column: after the save the copy holds the stored value;
  - a `seal_on_save=false` sealed row, one sealed column set, saved twice: the plaintext and its mark stay both times;
  - a clean, stale File copy whose row has gone public since it was loaded: saving it places the blob as the row says;
  - the egress guard passes an UPDATE ending in `RETURNING` on an owner-sealed row, and still refuses everything else;
  - type fidelity, in the generic model suite: after a save, the copy's unchanged columns are `===` a fresh load of the same row.
- **Gate:** the full `db` gate with `--fresh`, because this touches every model.
