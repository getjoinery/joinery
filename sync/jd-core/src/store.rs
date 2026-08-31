//! Where the last-agreed state lives.
//!
//! Everything the reconciler does rests on one thing being true: that the
//! engine remembers exactly what the two sides last agreed on, for every entry,
//! across crashes. That memory is this file. If it is wrong, no amount of
//! cleverness downstream recovers — a lost agreement turns a quiet no-op into a
//! conflict, and a *fabricated* one turns a conflict into a silent overwrite.
//!
//! Three properties are load-bearing:
//!
//! **It is transactional.** A reconciliation step that changes both the
//! filesystem and this database writes its intent here first, acts, then marks
//! the intent done. A crash between any two of those leaves a record of what
//! was being attempted, which is what makes recovery a matter of re-deriving
//! rather than guessing.
//!
//! **It is disposable.** Losing it is survivable by design: rebuild from a
//! fresh server walk plus a local scan, pairing by path and hash. Identical
//! bytes are never re-transferred, because the upload path short-circuits on a
//! hash the account already possesses. So the store is precious for
//! correctness, not for safety — which is the right way round.
//!
//! **It lives outside the synced tree.** Putting the database inside the folder
//! it describes would mean syncing it, which means two devices overwriting each
//! other's idea of the truth.

use std::collections::HashSet;
use std::path::Path;

use rusqlite::{params, Connection, OptionalExtension};

use crate::model::{ContentId, EntityId, EntityType, Entry, LocalStatus, Placement};

/// Bumped when the schema changes in a way an older engine could misread.
pub const SCHEMA_VERSION: i64 = 5;

/// What makes a written-off note apply *right now*, as one SQL predicate over
/// `entries e` joined to `unreadable u`.
///
/// Defined once because two places ask it and they must never drift: the pass
/// loop, which decides whether to stop planning work, and the status histogram,
/// which decides what the device tells the user it is doing. If those two
/// disagree the engine goes quiet while the tray keeps reporting the file as
/// still on its way — the same disease the note exists to cure, measured with a
/// different instrument.
///
/// `IS` rather than `=` for the key: it is null-safe in SQLite, and a plaintext
/// file has no wrapped key at all.
const STILL_WRITTEN_OFF: &str = "u.sha256 = e.remote_content_sha256 \
     AND u.size = e.remote_size \
     AND u.wrapped_file_key IS e.wrapped_file_key";

#[derive(Debug, thiserror::Error)]
pub enum StoreError {
    #[error("state store: {0}")]
    Sql(#[from] rusqlite::Error),
    #[error("state store was written by a newer client (schema {found}, this build understands {understood})")]
    SchemaTooNew { found: i64, understood: i64 },
}

pub type StoreResult<T> = Result<T, StoreError>;

/// Where an operation has got to. The three states exist to make the crash
/// window explicit: anything found `InFlight` at startup was interrupted
/// mid-act, with nobody left who knows how far it got. Recovery puts it back on
/// the queue and runs it again, which is what its idempotency key and the
/// server's replay cache are for -- running it is how it finds out whether the
/// server already did it.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum OpState {
    Queued,
    InFlight,
    Done,
}

impl OpState {
    fn as_str(self) -> &'static str {
        match self {
            OpState::Queued => "queued",
            OpState::InFlight => "in_flight",
            OpState::Done => "done",
        }
    }
    fn parse(s: &str) -> OpState {
        match s {
            "in_flight" => OpState::InFlight,
            "done" => OpState::Done,
            _ => OpState::Queued,
        }
    }
}

/// A journaled intent.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Op {
    pub op_id: i64,
    pub kind: String,
    pub entity: EntityId,
    /// Opaque to the store; the executor's own encoding of what to do.
    pub params: String,
    pub state: OpState,
    /// Sent as `Idempotency-Key` on every mutating call. This is what makes a
    /// crash between "the server applied it" and "we recorded that it did"
    /// harmless: the retry is recognized rather than re-applied.
    pub idempotency_key: String,
    pub attempts: i64,
    pub next_retry_time: Option<i64>,
    pub last_error: Option<String>,
}

/// A problem worth showing a person, with enough context to act on it.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct StoredIssue {
    pub issue_id: i64,
    pub entity: Option<EntityId>,
    pub kind: String,
    pub detail: String,
    pub created_at: i64,
    pub dismissed: bool,
}

pub struct Store {
    conn: Connection,
}

impl Store {
    /// Open (creating if needed) the state store for one sync root.
    pub fn open(path: &Path) -> StoreResult<Store> {
        let conn = Connection::open(path)?;
        Store::init(conn)
    }

    /// An in-memory store, for tests and for the simulator.
    pub fn open_in_memory() -> StoreResult<Store> {
        let conn = Connection::open_in_memory()?;
        Store::init(conn)
    }

    fn init(conn: Connection) -> StoreResult<Store> {
        // WAL so a reader (the tray asking "what is my status") never blocks the
        // writer, and so a power cut cannot leave a torn page. NORMAL rather
        // than FULL: a lost final transaction costs one re-derived reconcile
        // round, which the engine is built to survive anyway.
        conn.pragma_update(None, "journal_mode", "WAL")?;
        conn.pragma_update(None, "synchronous", "NORMAL")?;
        conn.pragma_update(None, "foreign_keys", "ON")?;

        conn.execute_batch(
            r#"
            CREATE TABLE IF NOT EXISTS meta (
                key   TEXT PRIMARY KEY,
                value TEXT
            );

            -- One row per known remote entity. Keyed by what the SERVER calls
            -- it, never by path: paths are labels a user can change, identity is
            -- not. This is what makes renaming a folder of 10,000 files one
            -- operation instead of 10,000.
            CREATE TABLE IF NOT EXISTS entries (
                entity_type            TEXT NOT NULL,
                server_id              INTEGER NOT NULL,
                parent_folder_id       INTEGER,
                remote_name            TEXT NOT NULL,
                local_name             TEXT,
                is_encrypted           INTEGER NOT NULL DEFAULT 0,
                remote_content_sha256  TEXT,
                remote_size            INTEGER,
                remote_modified_time   TEXT,
                head_change_id         INTEGER NOT NULL DEFAULT 0,
                remote_deleted         INTEGER NOT NULL DEFAULT 0,

                -- the last state both sides agreed on
                synced_content_sha256  TEXT,
                synced_size            INTEGER,
                synced_parent_id       INTEGER,
                synced_name            TEXT,
                synced_fp_size         INTEGER,
                synced_fp_mtime_ns     INTEGER,
                synced_fp_file_id      INTEGER,

                local_status           TEXT NOT NULL,
                unsyncable_reason      TEXT,
                wrapped_file_key       TEXT,
                content_id             TEXT,
                synced_remote_sha256   TEXT,
                synced_remote_size     INTEGER,
                replaces_type          TEXT,
                replaces_id            INTEGER,
                PRIMARY KEY (entity_type, server_id)
            );
            CREATE INDEX IF NOT EXISTS entries_parent ON entries (parent_folder_id);
            CREATE INDEX IF NOT EXISTS entries_status ON entries (local_status);

            -- The write-ahead intent journal.
            CREATE TABLE IF NOT EXISTS ops (
                op_id            INTEGER PRIMARY KEY AUTOINCREMENT,
                kind             TEXT NOT NULL,
                entity_type      TEXT NOT NULL,
                server_id        INTEGER NOT NULL,
                params           TEXT NOT NULL DEFAULT '',
                state            TEXT NOT NULL DEFAULT 'queued',
                idempotency_key  TEXT NOT NULL,
                attempts         INTEGER NOT NULL DEFAULT 0,
                next_retry_time  INTEGER,
                last_error       TEXT
            );
            CREATE INDEX IF NOT EXISTS ops_state ON ops (state);

            -- inode/file-id → entry, so a moved file is recognized as the same
            -- file rather than a delete plus a fresh upload. Plus a hash cache,
            -- so a rescan does not re-read every byte on disk to learn nothing.
            CREATE TABLE IF NOT EXISTS local_index (
                file_id     INTEGER NOT NULL,
                size        INTEGER NOT NULL,
                mtime_ns    INTEGER NOT NULL,
                sha256      TEXT NOT NULL,
                entity_type TEXT,
                server_id   INTEGER,
                -- When the hash was taken. A fingerprint only becomes evidence
                -- once the file is older than the clock's resolution; see
                -- `cached_hash`.
                cached_at_ns INTEGER,
                PRIMARY KEY (file_id, size, mtime_ns)
            );

            -- Server bytes this device has already proven it cannot turn into
            -- the file: ciphertext that arrived exactly as the server said it
            -- would and still failed its authentication tag.
            --
            -- Kept beside the agreement rather than in it. An entry's status
            -- says how the file is materialized here, and every status that
            -- means "parked" is recomputed each pass from names and keys — so a
            -- reason grounded in bytes would be erased by the next pass and the
            -- download planned all over again. This is not a state to hold; it
            -- is a note about one specific artifact that did not work.
            --
            -- Both of the things that could make it work again are recorded, so
            -- the note stops applying by itself the moment either changes: the
            -- content (the server was given better bytes) and the wrapped key
            -- (a correct grant arrived to replace a stale one). No expiry, no
            -- lifting logic, nothing to forget to clear. The wrapped key is
            -- already stored in `entries` in this same file, so naming it here
            -- puts nothing new on disk.
            CREATE TABLE IF NOT EXISTS unreadable (
                entity_type      TEXT NOT NULL,
                server_id        INTEGER NOT NULL,
                sha256           TEXT NOT NULL,
                size             INTEGER NOT NULL,
                wrapped_file_key TEXT,
                noticed_at       INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (entity_type, server_id)
            );

            CREATE TABLE IF NOT EXISTS issues (
                issue_id    INTEGER PRIMARY KEY AUTOINCREMENT,
                entity_type TEXT,
                server_id   INTEGER,
                kind        TEXT NOT NULL,
                detail      TEXT NOT NULL DEFAULT '',
                created_at  INTEGER NOT NULL DEFAULT 0,
                dismissed   INTEGER NOT NULL DEFAULT 0
            );
            "#,
        )?;

        let store = Store { conn };
        // Columns added after the table shipped. `CREATE TABLE IF NOT EXISTS`
        // does nothing to a table that already exists, so an older store keeps
        // its old shape and every query naming a new column fails. Adding them
        // here is a one-line ALTER; the alternative is a client that will not
        // start and cannot say why.
        for (column, ddl) in [
            ("content_id", "TEXT"),
            ("synced_remote_sha256", "TEXT"),
            ("synced_remote_size", "INTEGER"),
            ("replaces_type", "TEXT"),
            ("replaces_id", "INTEGER"),
        ] {
            store.add_column_if_missing("entries", column, ddl)?;
        }
        store.add_column_if_missing("local_index", "cached_at_ns", "INTEGER")?;
        match store.get_meta("schema_version")? {
            None => store.set_meta("schema_version", &SCHEMA_VERSION.to_string())?,
            Some(v) => {
                let found: i64 = v.parse().unwrap_or(0);
                // Refuse rather than guess. A newer client may have written
                // columns this build does not know to preserve, and a
                // half-understood state store is worse than none — it would
                // produce confident wrong answers about what was agreed.
                if found > SCHEMA_VERSION {
                    return Err(StoreError::SchemaTooNew {
                        found,
                        understood: SCHEMA_VERSION,
                    });
                }
            }
        }
        // The version is written last, and only once the shape matches it: a
        // store stamped 3 that a crash left with a version-2 shape would be
        // refused help by every later start.
        store.set_meta("schema_version", &SCHEMA_VERSION.to_string())?;
        Ok(store)
    }

    /// Add a column an older store predates. Idempotent, and quiet about it.
    fn add_column_if_missing(&self, table: &str, column: &str, ddl: &str) -> StoreResult<()> {
        let existing: i64 = self.conn.query_row(
            "SELECT COUNT(*) FROM pragma_table_info(?1) WHERE name = ?2",
            params![table, column],
            |r| r.get(0),
        )?;
        if existing == 0 {
            self.conn.execute(
                &format!("ALTER TABLE {table} ADD COLUMN {column} {ddl}"),
                [],
            )?;
        }
        Ok(())
    }

    // ---- meta --------------------------------------------------------------

    pub fn get_meta(&self, key: &str) -> StoreResult<Option<String>> {
        Ok(self
            .conn
            .query_row("SELECT value FROM meta WHERE key = ?1", params![key], |r| {
                r.get(0)
            })
            .optional()?)
    }

    pub fn set_meta(&self, key: &str, value: &str) -> StoreResult<()> {
        self.conn.execute(
            "INSERT INTO meta (key, value) VALUES (?1, ?2)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value",
            params![key, value],
        )?;
        Ok(())
    }

    /// The change-feed position this device has replayed up to.
    pub fn cursor(&self) -> StoreResult<i64> {
        Ok(self
            .get_meta("cursor")?
            .and_then(|v| v.parse().ok())
            .unwrap_or(0))
    }

    /// Advance the cursor.
    ///
    /// Only ever called once the batch's deltas are durably queued. Advancing
    /// first would mean a crash in between loses changes permanently: the
    /// server would never mention them again, and nothing local would know to
    /// ask.
    pub fn set_cursor(&self, cursor: i64) -> StoreResult<()> {
        self.set_meta("cursor", &cursor.to_string())
    }

    // ---- entries -----------------------------------------------------------

    pub fn put_entry(&self, e: &Entry) -> StoreResult<()> {
        let (status, reason) = encode_status(&e.status);
        self.conn.execute(
            "INSERT INTO entries (
                entity_type, server_id, parent_folder_id, remote_name, local_name,
                is_encrypted, remote_content_sha256, remote_size, remote_modified_time,
                head_change_id, remote_deleted, synced_content_sha256, synced_size, synced_parent_id,
                synced_name, synced_fp_size, synced_fp_mtime_ns, synced_fp_file_id,
                local_status, unsyncable_reason, wrapped_file_key,
                content_id, synced_remote_sha256, synced_remote_size,
                replaces_type, replaces_id
             ) VALUES (?1,?2,?3,?4,?5,?6,?7,?8,?9,?10,?11,?12,?13,?14,?15,?16,?17,?18,?19,?20,?21,?22,?23,?24,?25,?26)
             ON CONFLICT(entity_type, server_id) DO UPDATE SET
                parent_folder_id = excluded.parent_folder_id,
                remote_name = excluded.remote_name,
                local_name = excluded.local_name,
                is_encrypted = excluded.is_encrypted,
                remote_content_sha256 = excluded.remote_content_sha256,
                remote_size = excluded.remote_size,
                remote_modified_time = excluded.remote_modified_time,
                head_change_id = excluded.head_change_id,
                remote_deleted = excluded.remote_deleted,
                synced_content_sha256 = excluded.synced_content_sha256,
                synced_size = excluded.synced_size,
                synced_parent_id = excluded.synced_parent_id,
                synced_name = excluded.synced_name,
                synced_fp_size = excluded.synced_fp_size,
                synced_fp_mtime_ns = excluded.synced_fp_mtime_ns,
                synced_fp_file_id = excluded.synced_fp_file_id,
                local_status = excluded.local_status,
                unsyncable_reason = excluded.unsyncable_reason,
                wrapped_file_key = excluded.wrapped_file_key,
                content_id = excluded.content_id,
                synced_remote_sha256 = excluded.synced_remote_sha256,
                synced_remote_size = excluded.synced_remote_size,
                replaces_type = excluded.replaces_type,
                replaces_id = excluded.replaces_id",
            params![
                e.id.entity_type.to_string(),
                e.id.server_id,
                e.remote.parent,
                e.remote.name,
                e.local_name,
                e.is_encrypted as i64,
                e.remote_content.as_ref().map(|c| c.sha256.clone()),
                e.remote_content.as_ref().map(|c| c.size as i64),
                e.remote_modified_time,
                e.head_change_id,
                e.remote_deleted as i64,
                e.synced_content.as_ref().map(|c| c.sha256.clone()),
                e.synced_content.as_ref().map(|c| c.size as i64),
                e.synced_placement.as_ref().and_then(|p| p.parent),
                e.synced_placement.as_ref().map(|p| p.name.clone()),
                e.synced_fingerprint.map(|f| f.size as i64),
                e.synced_fingerprint.map(|f| f.mtime_ns as i64),
                e.synced_fingerprint.map(|f| f.file_id as i64),
                status,
                reason,
                e.wrapped_file_key,
                e.content_id,
                e.synced_remote_content.as_ref().map(|c| c.sha256.clone()),
                e.synced_remote_content.as_ref().map(|c| c.size as i64),
                e.replaces.map(|r| r.entity_type.to_string()),
                e.replaces.map(|r| r.server_id),
            ],
        )?;
        Ok(())
    }

    pub fn get_entry(&self, id: EntityId) -> StoreResult<Option<Entry>> {
        Ok(self
            .conn
            .query_row(
                "SELECT entity_type, server_id, parent_folder_id, remote_name, local_name,
                        is_encrypted, remote_content_sha256, remote_size, remote_modified_time,
                        head_change_id, remote_deleted, synced_content_sha256, synced_size, synced_parent_id,
                        synced_name, synced_fp_size, synced_fp_mtime_ns, synced_fp_file_id,
                        local_status, unsyncable_reason, wrapped_file_key,
                        content_id, synced_remote_sha256, synced_remote_size,
                        replaces_type, replaces_id
                   FROM entries WHERE entity_type = ?1 AND server_id = ?2",
                params![id.entity_type.to_string(), id.server_id],
                row_to_entry,
            )
            .optional()?)
    }

    /// Fold a provisional folder into the real one that turned out to be the
    /// same directory, and forget the provisional.
    ///
    /// This exists because a device can end up holding **two entries for one
    /// directory**. A pass reads the change feed and then walks the disk, so a
    /// directory with no matching entry correctly gets a provisional identity —
    /// no such folder existed on the server when the feed was read. If another
    /// device creates that folder before this one's create lands, the create is
    /// refused and the provisional survives; the winner's folder then arrives as
    /// a second entry describing the same directory.
    ///
    /// Left alone the two deadlock: name resolution treats them as rival
    /// siblings, the provisional outranks the real one, so the real folder is
    /// refused as clashing with a name identical to its own, so it never
    /// materializes, so it never supersedes the provisional, which re-plans its
    /// doomed create every pass. Found on the soak rig at 611 refused creates
    /// per folder with the queue flat for fifteen minutes.
    ///
    /// Merging is safe precisely for folders: the server permits one name per
    /// parent, so a local directory and a remote folder at the same path cannot
    /// be different things.
    ///
    /// What happens to each thing the provisional owned:
    /// - **children** are re-pointed at the real folder — they are the same
    ///   files, and orphaning them would re-upload the whole subtree;
    /// - **local_index rows** move, so the hash cache is not thrown away and a
    ///   rescan does not re-read every byte;
    /// - **queued operations are dropped**, not moved. They were planned against
    ///   an identity that no longer exists, and the only one that can be
    ///   outstanding here is the create that will never succeed.
    pub fn merge_folder(&self, from: EntityId, to: EntityId) -> StoreResult<()> {
        if from == to || from.entity_type != EntityType::Folder {
            return Ok(());
        }
        let t = from.entity_type.to_string();
        self.conn.execute("BEGIN IMMEDIATE", [])?;
        let result = (|| -> StoreResult<()> {
            self.conn.execute(
                "UPDATE entries SET parent_folder_id = ?2 WHERE parent_folder_id = ?1",
                params![from.server_id, to.server_id],
            )?;
            self.conn.execute(
                "UPDATE local_index SET server_id = ?3
                  WHERE entity_type = ?1 AND server_id = ?2",
                params![t, from.server_id, to.server_id],
            )?;
            self.conn.execute(
                "DELETE FROM ops WHERE entity_type = ?1 AND server_id = ?2",
                params![t, from.server_id],
            )?;
            self.conn.execute(
                "DELETE FROM entries WHERE entity_type = ?1 AND server_id = ?2",
                params![t, from.server_id],
            )?;
            Ok(())
        })();
        match result {
            Ok(()) => {
                self.conn.execute("COMMIT", [])?;
                Ok(())
            }
            Err(e) => {
                let _ = self.conn.execute("ROLLBACK", []);
                Err(e)
            }
        }
    }

    /// Fold a provisional file into the real one that turned out to be the same
    /// file, and forget the provisional.
    ///
    /// The file counterpart of [`Store::merge_folder`], and it exists for the
    /// same reason: a device can end up holding **two entries for one path**,
    /// one provisional and one real, and nothing downstream can resolve that.
    /// Naming treats them as rival siblings and ranks the provisional as
    /// materialized, so the real entry is refused as clashing with a name
    /// identical to its own; a pass skips an unsyncable entry entirely, so it
    /// never materializes, never occupies the path, and never supersedes the
    /// provisional — which re-plans its doomed upload every pass. The soak rig
    /// found it as the whole of run 25's residue: 12 of device-a's 13 unsyncable
    /// entries and 17 of device-b's 18, and in every single case it was the
    /// **real** entry wearing the `duplicate_name` reason.
    ///
    /// Merging is what makes the deadlock unreachable however it arose, which
    /// matters more than the genesis: whatever mints the second record, the next
    /// pass folds it.
    ///
    /// **What makes this safe for files is new.** The folder version leans on
    /// the server permitting one name per parent, so a local directory and a
    /// remote folder at one path cannot be different things. Files only gained
    /// that guarantee with the uniqueness rule on `fil_files`; before it, two
    /// live files really could share a name and folding them would have been a
    /// guess. It is the same rule the refusal here comes from.
    ///
    /// **The agreement is left exactly as the real entry recorded it**, and that
    /// is the whole delicacy of this function. Clearing it would read the local
    /// file as a fresh creation meeting a fresh remote one, and every merge
    /// would manufacture a conflicted copy of a file nobody conflicted over.
    /// Kept, an ordinary scan compares the disk against it and calls an edit an
    /// edit. Where there is no agreement to keep, the entry stays a download and
    /// `make_room` preserves whatever is on the disk — a spare copy, which is
    /// the cheap direction to be wrong in.
    ///
    /// The provisional contributes only what it alone holds: its `local_index`
    /// rows, so the inode mapping and hash cache survive and a rescan does not
    /// re-read every byte. Its queued operations are **dropped**, not moved —
    /// the only one that can be outstanding is the upload the server will refuse
    /// for as long as the name is taken, which is forever.
    pub fn merge_file(&self, from: EntityId, to: EntityId) -> StoreResult<()> {
        if from == to || from.entity_type != EntityType::File || to.entity_type != EntityType::File
        {
            return Ok(());
        }
        let t = EntityType::File.to_string();
        self.conn.execute("BEGIN IMMEDIATE", [])?;
        let result = (|| -> StoreResult<()> {
            let Some(real) = self.get_entry(to)? else {
                return Ok(());
            };
            // Unsyncable is the state the deadlock parks it in, and a pass skips
            // an unsyncable entry, so leaving it would fold the rival away and
            // still never look at the survivor. What it goes back to is decided
            // by whether anything was ever agreed about it, which is the same
            // question the scanner asks.
            if matches!(real.status, LocalStatus::Unsyncable(_)) {
                let status = if real.synced_placement.is_some() {
                    LocalStatus::Synced
                } else {
                    LocalStatus::PendingDownload
                };
                self.put_entry(&Entry { status, ..real })?;
            }
            self.conn.execute(
                "UPDATE local_index SET server_id = ?3
                  WHERE entity_type = ?1 AND server_id = ?2",
                params![t, from.server_id, to.server_id],
            )?;
            self.conn.execute(
                "DELETE FROM ops WHERE entity_type = ?1 AND server_id = ?2",
                params![t, from.server_id],
            )?;
            self.conn.execute(
                "DELETE FROM entries WHERE entity_type = ?1 AND server_id = ?2",
                params![t, from.server_id],
            )?;
            Ok(())
        })();
        match result {
            Ok(()) => {
                self.conn.execute("COMMIT", [])?;
                Ok(())
            }
            Err(e) => {
                let _ = self.conn.execute("ROLLBACK", []);
                Err(e)
            }
        }
    }

    /// Forget an entry and everything underneath it.
    ///
    /// This drops the *record*, never a file. Anything still on the disk is
    /// found again by the next scan as a local creation and uploaded afresh,
    /// which is the safe direction: the worst case is re-sending bytes the
    /// server already has.
    ///
    /// Callers use it for entries that have no way back to the root. Removing
    /// only the folder is what a soak run caught: thirty-two files left
    /// pointing at a parent that no longer existed. An entry whose path cannot
    /// be resolved is skipped by every later pass, so those files sat in
    /// `pending_upload` forever with no operation queued and nothing raised —
    /// no work, no issue, no way to notice. Silence is the one failure this
    /// client is not allowed.
    ///
    /// Returns how many entries went.
    /// Everything this store BELIEVES is under `root`, root first.
    ///
    /// Belief is the important word. These are the entries whose parent
    /// pointers lead back here, which is not the same question as what the
    /// server has under that folder — a child moved out while this device was
    /// not looking still points here until something tells it otherwise. Any
    /// caller about to DELETE what this returns needs the server's answer
    /// first; see `forget_folder_the_server_confirms` in `execute`.
    pub fn subtree_ids(&self, root: EntityId) -> StoreResult<Vec<EntityId>> {
        let mut doomed = vec![root];
        let mut frontier = vec![root.server_id];
        let mut guard = 0;
        while let Some(parent) = frontier.pop() {
            guard += 1;
            if guard > 100_000 {
                break; // a cycle in the tree is a bug elsewhere, not a reason to hang
            }
            for child in self.children_of(Some(parent))? {
                doomed.push(child.id);
                if child.id.entity_type == EntityType::Folder {
                    frontier.push(child.id.server_id);
                }
            }
        }
        Ok(doomed)
    }

    pub fn delete_subtree(&self, root: EntityId) -> StoreResult<usize> {
        let doomed = self.subtree_ids(root)?;

        self.conn.execute("BEGIN IMMEDIATE", [])?;
        let result = (|| -> StoreResult<()> {
            for id in &doomed {
                let t = id.entity_type.to_string();
                self.conn.execute(
                    "DELETE FROM ops WHERE entity_type = ?1 AND server_id = ?2",
                    params![t, id.server_id],
                )?;
                self.conn.execute(
                    "DELETE FROM local_index WHERE entity_type = ?1 AND server_id = ?2",
                    params![t, id.server_id],
                )?;
                self.conn.execute(
                    "DELETE FROM unreadable WHERE entity_type = ?1 AND server_id = ?2",
                    params![t, id.server_id],
                )?;
                self.conn.execute(
                    "DELETE FROM entries WHERE entity_type = ?1 AND server_id = ?2",
                    params![t, id.server_id],
                )?;
            }
            Ok(())
        })();
        match result {
            Ok(()) => {
                self.conn.execute("COMMIT", [])?;
                Ok(doomed.len())
            }
            Err(e) => {
                let _ = self.conn.execute("ROLLBACK", []);
                Err(e)
            }
        }
    }

    /// Forget ONE entity and every trace of it, leaving its children alone.
    ///
    /// `delete_subtree` decides for itself what goes, from the parent pointers
    /// this store happens to hold; this takes the caller's word for a single
    /// id. That is the difference a caller needs when the set was decided by
    /// the SERVER rather than by belief -- it purges the same four tables, so
    /// nothing is left behind, but it never widens the set on its own.
    pub fn forget_entry(&self, id: EntityId) -> StoreResult<()> {
        let t = id.entity_type.to_string();
        self.conn.execute("BEGIN IMMEDIATE", [])?;
        let result = (|| -> StoreResult<()> {
            for table in ["ops", "local_index", "unreadable", "entries"] {
                self.conn.execute(
                    &format!("DELETE FROM {table} WHERE entity_type = ?1 AND server_id = ?2"),
                    params![t, id.server_id],
                )?;
            }
            Ok(())
        })();
        match result {
            Ok(()) => {
                self.conn.execute("COMMIT", [])?;
                Ok(())
            }
            Err(e) => {
                let _ = self.conn.execute("ROLLBACK", []);
                Err(e)
            }
        }
    }

    pub fn delete_entry(&self, id: EntityId) -> StoreResult<()> {
        // The note about unreadable bytes goes with it. A server id is reused by
        // nobody, but a row for an entity that no longer exists is a row that
        // never gets looked at again and never gets removed either.
        self.clear_unreadable(id)?;
        self.conn.execute(
            "DELETE FROM entries WHERE entity_type = ?1 AND server_id = ?2",
            params![id.entity_type.to_string(), id.server_id],
        )?;
        Ok(())
    }

    /// Every entry there is, reachable from the root or not.
    ///
    /// A walk that starts at the root and follows children cannot see an entry
    /// whose parent has gone: it is not skipped, it is unreachable. So an
    /// orphan is invisible to the thing that would otherwise notice it, which is
    /// how a soak run ended with thirty-two files stalled and nothing anywhere
    /// saying so. Reading the table is the only way to be sure a pass has
    /// considered everything it holds.
    pub fn every_entry(&self) -> StoreResult<Vec<Entry>> {
        let sql = "SELECT entity_type, server_id, parent_folder_id, remote_name, local_name,
                          is_encrypted, remote_content_sha256, remote_size, remote_modified_time,
                          head_change_id, remote_deleted, synced_content_sha256, synced_size, synced_parent_id,
                          synced_name, synced_fp_size, synced_fp_mtime_ns, synced_fp_file_id,
                          local_status, unsyncable_reason, wrapped_file_key,
                          content_id, synced_remote_sha256, synced_remote_size,
                          replaces_type, replaces_id
                     FROM entries
                    ORDER BY entity_type, server_id";
        let mut stmt = self.conn.prepare(sql)?;
        let rows = stmt.query_map([], row_to_entry)?;
        let mut out = Vec::new();
        for r in rows {
            out.push(r?);
        }
        Ok(out)
    }

    /// Children of a folder (`None` for the drive root).
    pub fn children_of(&self, parent: Option<i64>) -> StoreResult<Vec<Entry>> {
        let sql = "SELECT entity_type, server_id, parent_folder_id, remote_name, local_name,
                          is_encrypted, remote_content_sha256, remote_size, remote_modified_time,
                          head_change_id, remote_deleted, synced_content_sha256, synced_size, synced_parent_id,
                          synced_name, synced_fp_size, synced_fp_mtime_ns, synced_fp_file_id,
                          local_status, unsyncable_reason, wrapped_file_key,
                          content_id, synced_remote_sha256, synced_remote_size,
                          replaces_type, replaces_id
                     FROM entries
                    WHERE parent_folder_id IS ?1
                    ORDER BY entity_type, server_id";
        let mut stmt = self.conn.prepare(sql)?;
        let rows = stmt.query_map(params![parent], row_to_entry)?;
        let mut out = Vec::new();
        for r in rows {
            out.push(r?);
        }
        Ok(out)
    }

    /// Reserve an id for something created here that the server has not named
    /// yet. Counts downward from -1; see [`EntityId::is_provisional`].
    pub fn next_provisional_id(&self) -> StoreResult<i64> {
        let last: i64 = self
            .get_meta("last_provisional_id")?
            .and_then(|v| v.parse().ok())
            .unwrap_or(0);
        let next = last - 1;
        self.set_meta("last_provisional_id", &next.to_string())?;
        Ok(next)
    }

    /// Re-key an entry once the server has named it.
    ///
    /// Children move with it. A folder created here holds its contents under
    /// the provisional id, and if that link were not carried across, every file
    /// inside a newly created folder would be orphaned at the moment the folder
    /// became real — present locally, parented to an id nothing recognizes.
    ///
    /// The whole thing is one transaction because a half-applied re-key is a
    /// tree with a hole in it, which no later round can repair.
    pub fn rekey_entry(&self, from: EntityId, to: EntityId) -> StoreResult<()> {
        if from == to {
            return Ok(());
        }
        let t = from.entity_type.to_string();
        self.conn.execute("BEGIN IMMEDIATE", [])?;
        let result = (|| -> StoreResult<()> {
            self.conn.execute(
                "UPDATE entries SET server_id = ?3 WHERE entity_type = ?1 AND server_id = ?2",
                params![t, from.server_id, to.server_id],
            )?;
            if from.entity_type == EntityType::Folder {
                self.conn.execute(
                    "UPDATE entries SET parent_folder_id = ?2 WHERE parent_folder_id = ?1",
                    params![from.server_id, to.server_id],
                )?;
            }
            self.conn.execute(
                "UPDATE local_index SET server_id = ?3
                  WHERE entity_type = ?1 AND server_id = ?2",
                params![t, from.server_id, to.server_id],
            )?;
            self.conn.execute(
                "UPDATE ops SET server_id = ?3 WHERE entity_type = ?1 AND server_id = ?2",
                params![t, from.server_id, to.server_id],
            )?;
            Ok(())
        })();
        match result {
            Ok(()) => {
                self.conn.execute("COMMIT", [])?;
                Ok(())
            }
            Err(e) => {
                let _ = self.conn.execute("ROLLBACK", []);
                Err(e)
            }
        }
    }

    /// How many entries are currently in agreement — the denominator the
    /// mass-delete guard measures a round's deletes against.
    pub fn synced_count(&self) -> StoreResult<usize> {
        let n: i64 = self.conn.query_row(
            "SELECT COUNT(*) FROM entries WHERE local_status = 'synced'",
            [],
            |r| r.get(0),
        )?;
        Ok(n as usize)
    }

    /// How many entries sit in each state.
    ///
    /// The health indicator is a reduction of exactly this, and it is computed
    /// from the store rather than accumulated as passes run. A counter that is
    /// incremented and decremented drifts — one missed decrement and the client
    /// shows work in flight forever, which is the same as showing nothing at
    /// all, because a user learns to ignore it.
    pub fn status_counts(&self) -> StoreResult<Vec<(String, usize)>> {
        // A written-off entry is reported as what it actually is, not as the
        // download it is no longer waiting for. Left in the `pending_download`
        // bucket it would read to every shell -- and to the soak rig's
        // convergence oracle -- as a device still working on something it has
        // deliberately stopped touching.
        let sql = format!(
            "SELECT CASE WHEN u.server_id IS NOT NULL AND {STILL_WRITTEN_OFF}
                         THEN 'unreadable' ELSE e.local_status END AS bucket,
                    COUNT(*)
               FROM entries e
               LEFT JOIN unreadable u
                      ON u.entity_type = e.entity_type AND u.server_id = e.server_id
              GROUP BY bucket"
        );
        let mut stmt = self.conn.prepare(&sql)?;
        let rows = stmt.query_map([], |r| {
            Ok((r.get::<_, String>(0)?, r.get::<_, i64>(1)? as usize))
        })?;
        let mut out = Vec::new();
        for row in rows {
            out.push(row?);
        }
        out.sort();
        Ok(out)
    }

    /// Operations waiting to run, whether they are due yet or held on a backoff.
    ///
    /// Both count as work. A client reporting itself idle while four uploads sit
    /// waiting on a retry is telling the user their files are safely synced when
    /// they are not.
    pub fn pending_op_count(&self) -> StoreResult<usize> {
        let n: i64 = self.conn.query_row(
            "SELECT COUNT(*) FROM ops WHERE state IN ('queued','in_flight')",
            [],
            |r| r.get(0),
        )?;
        Ok(n as usize)
    }

    // ---- the intent journal ------------------------------------------------

    /// Record an intent before acting on it.
    pub fn queue_op(
        &self,
        kind: &str,
        entity: EntityId,
        params_json: &str,
        idempotency_key: &str,
    ) -> StoreResult<i64> {
        self.conn.execute(
            "INSERT INTO ops (kind, entity_type, server_id, params, state, idempotency_key)
             VALUES (?1, ?2, ?3, ?4, 'queued', ?5)",
            params![
                kind,
                entity.entity_type.to_string(),
                entity.server_id,
                params_json,
                idempotency_key
            ],
        )?;
        Ok(self.conn.last_insert_rowid())
    }

    /// Keep what was sent, so a retry sends the same thing.
    ///
    /// An idempotency key is a promise that the request behind it does not
    /// change. Almost every op keeps that promise for free, because its body is
    /// a plain function of what the op says. A sealed body is the exception: it
    /// is encrypted afresh under a new random nonce every time it is built, so
    /// two attempts at one op produce two different requests and the server --
    /// correctly -- refuses the second. Building it once and writing it down
    /// here is what makes the retry a retry.
    pub fn set_op_params(&self, op_id: i64, params_json: &str) -> StoreResult<()> {
        self.conn.execute(
            "UPDATE ops SET params = ?2 WHERE op_id = ?1",
            params![op_id, params_json],
        )?;
        Ok(())
    }

    pub fn set_op_state(&self, op_id: i64, state: OpState) -> StoreResult<()> {
        self.conn.execute(
            "UPDATE ops SET state = ?2 WHERE op_id = ?1",
            params![op_id, state.as_str()],
        )?;
        Ok(())
    }

    /// Record a failed attempt and when to try again.
    pub fn record_op_failure(
        &self,
        op_id: i64,
        error: &str,
        next_retry_time: i64,
    ) -> StoreResult<()> {
        self.conn.execute(
            "UPDATE ops
                SET attempts = attempts + 1,
                    last_error = ?2,
                    next_retry_time = ?3,
                    state = 'queued'
              WHERE op_id = ?1",
            params![op_id, error, next_retry_time],
        )?;
        Ok(())
    }

    /// Put an interrupted op back on the queue, counting the attempt nobody was
    /// left to count.
    ///
    /// The attempt really was made -- the process died in the middle of it --
    /// and everything downstream that asks *is this a retry?* reads this
    /// number. A failure gets counted because something came back to count it;
    /// a kill leaves nobody, so an op that had been half-way to the server came
    /// back looking untouched. The one question that matters most after a kill
    /// -- can the server's answer be taken at face value, or is it a replay of
    /// a moment that has passed? -- was then answered wrongly, every time.
    ///
    /// No backoff. There is nothing to wait for: the process just started.
    pub fn requeue_interrupted_op(&self, op_id: i64) -> StoreResult<()> {
        self.conn.execute(
            "UPDATE ops
                SET attempts = attempts + 1,
                    state = 'queued'
              WHERE op_id = ?1",
            params![op_id],
        )?;
        Ok(())
    }

    /// Ops that were interrupted mid-act.
    ///
    /// These are the crash window made visible. Each goes back on the queue and
    /// runs again: the server may have applied it before the process died, and
    /// running it is the only thing that both finds that out and writes down
    /// what happened.
    pub fn interrupted_ops(&self) -> StoreResult<Vec<Op>> {
        self.ops_in_state(OpState::InFlight)
    }

    pub fn queued_ops(&self) -> StoreResult<Vec<Op>> {
        self.ops_in_state(OpState::Queued)
    }

    /// Entities that already have work journaled against them.
    ///
    /// A round must leave these alone. The journal is the plan of record for
    /// anything in it, and deciding afresh for an entity that already has an
    /// operation waiting produces a second operation that does the same thing —
    /// once per pass, for as long as the first one keeps failing.
    pub fn entities_with_open_ops(&self) -> StoreResult<Vec<EntityId>> {
        let mut stmt = self.conn.prepare(
            "SELECT DISTINCT entity_type, server_id FROM ops WHERE state IN ('queued','in_flight')",
        )?;
        let rows = stmt.query_map([], |r| {
            Ok(EntityId {
                entity_type: parse_entity_type(&r.get::<_, String>(0)?),
                server_id: r.get(1)?,
            })
        })?;
        let mut out = Vec::new();
        for r in rows {
            out.push(r?);
        }
        Ok(out)
    }

    fn ops_in_state(&self, state: OpState) -> StoreResult<Vec<Op>> {
        let mut stmt = self.conn.prepare(
            "SELECT op_id, kind, entity_type, server_id, params, state,
                    idempotency_key, attempts, next_retry_time, last_error
               FROM ops WHERE state = ?1 ORDER BY op_id",
        )?;
        let rows = stmt.query_map(params![state.as_str()], |r| {
            Ok(Op {
                op_id: r.get(0)?,
                kind: r.get(1)?,
                entity: EntityId {
                    entity_type: parse_entity_type(&r.get::<_, String>(2)?),
                    server_id: r.get(3)?,
                },
                params: r.get(4)?,
                state: OpState::parse(&r.get::<_, String>(5)?),
                idempotency_key: r.get(6)?,
                attempts: r.get(7)?,
                next_retry_time: r.get(8)?,
                last_error: r.get(9)?,
            })
        })?;
        let mut out = Vec::new();
        for r in rows {
            out.push(r?);
        }
        Ok(out)
    }

    /// Withdraw one op.
    ///
    /// For an intent whose premise has evaporated — the file was deleted while
    /// the op waited, the entry is gone from the server. Retrying it forever
    /// would leave a client permanently busy achieving nothing, and marking it
    /// done would be a lie in a journal whose whole value is being true.
    pub fn drop_op(&self, op_id: i64) -> StoreResult<()> {
        self.conn
            .execute("DELETE FROM ops WHERE op_id = ?1", params![op_id])?;
        Ok(())
    }

    /// Drop completed ops. Kept as a separate step so the journal can be
    /// inspected after a run when something has gone wrong.
    pub fn prune_done_ops(&self) -> StoreResult<usize> {
        Ok(self
            .conn
            .execute("DELETE FROM ops WHERE state = 'done'", [])?)
    }

    // ---- the hash cache ----------------------------------------------------

    /// Remember a file's hash against its fingerprint, so a rescan that finds
    /// the fingerprint unchanged can skip reading the bytes.
    pub fn cache_hash(
        &self,
        fp: jd_vfs::Fingerprint,
        sha256: &str,
        entity: Option<EntityId>,
        now_ns: u64,
    ) -> StoreResult<()> {
        self.conn.execute(
            "INSERT INTO local_index
                (file_id, size, mtime_ns, sha256, entity_type, server_id, cached_at_ns)
             VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7)
             ON CONFLICT(file_id, size, mtime_ns) DO UPDATE SET
                sha256 = excluded.sha256,
                entity_type = excluded.entity_type,
                server_id = excluded.server_id,
                cached_at_ns = excluded.cached_at_ns",
            params![
                fp.file_id as i64,
                fp.size as i64,
                fp.mtime_ns as i64,
                sha256,
                entity.map(|e| e.entity_type.to_string()),
                entity.map(|e| e.server_id),
                now_ns as i64,
            ],
        )?;
        Ok(())
    }

    /// The cached hash for exactly this fingerprint, if we have one we can
    /// still vouch for.
    ///
    /// The lookup is exact on all three fields on purpose. A cache keyed on the
    /// inode alone would happily hand back the hash of a file that has since
    /// been rewritten in place — which would then be adopted as agreement, and
    /// the user's edit would be silently discarded.
    ///
    /// All three are not enough by themselves. A file written twice inside one
    /// tick of the clock the filesystem records times with, to the same length,
    /// keeps the same fingerprint through the second write — so the row cached
    /// against the first body answers for the second. The engine then sees an
    /// unchanged file, never uploads the edit, and records the entry as agreeing
    /// with a server copy it does not match. Nothing revisits it, because
    /// nothing believes anything is wrong. The simulator found it as two
    /// nineteen-byte edits either side of one download.
    ///
    /// So a row is evidence only if the file was already older than that tick
    /// when the hash was taken. Inside the window, and for rows from a build
    /// that did not record when it looked, the answer is no and the bytes get
    /// read again.
    pub fn cached_hash(
        &self,
        fp: jd_vfs::Fingerprint,
        granularity_ns: u64,
    ) -> StoreResult<Option<String>> {
        Ok(self
            .conn
            .query_row(
                "SELECT sha256 FROM local_index
                  WHERE file_id = ?1 AND size = ?2 AND mtime_ns = ?3
                    AND cached_at_ns IS NOT NULL
                    AND cached_at_ns - mtime_ns >= ?4",
                params![
                    fp.file_id as i64,
                    fp.size as i64,
                    fp.mtime_ns as i64,
                    granularity_ns.max(1) as i64,
                ],
                |r| r.get(0),
            )
            .optional()?)
    }

    /// Which entry, if any, a local file id was last known to belong to. This
    /// is how a moved file keeps its identity instead of arriving as a stranger.
    pub fn entity_for_file_id(&self, file_id: u64) -> StoreResult<Option<EntityId>> {
        let found: Option<(Option<String>, Option<i64>)> = self
            .conn
            .query_row(
                "SELECT entity_type, server_id FROM local_index
                  WHERE file_id = ?1 AND entity_type IS NOT NULL
                  ORDER BY mtime_ns DESC LIMIT 1",
                params![file_id as i64],
                |r| Ok((r.get(0)?, r.get(1)?)),
            )
            .optional()?;
        Ok(match found {
            Some((Some(t), Some(id))) => Some(EntityId {
                entity_type: parse_entity_type(&t),
                server_id: id,
            }),
            _ => None,
        })
    }

    // ---- issues ------------------------------------------------------------

    /// Record something the user needs to know about, once.
    ///
    /// An issue is a *thing that needs attention*, not an event, so the same
    /// thing said again is the same thing. A pass re-reaches the same
    /// conclusion about the same entity every time it runs, and without this a
    /// single stuck file becomes thousands of identical rows: a soak run
    /// finished with 1,152 issues on a device that had three problems. That is
    /// not a display nuisance — it is the difference between a person seeing
    /// their three problems and giving up on the list.
    ///
    /// Matching is on the exact wording as well as the entity and kind, so a
    /// changed detail is a changed situation and does get through. A dismissed
    /// issue is not matched: if the user waved it away and it happened again,
    /// they should hear about it again.
    /// Remember that these exact server bytes, under this exact key, could not
    /// be opened here.
    ///
    /// One row per entity: a later failure replaces an earlier one rather than
    /// piling up, because only the current content can be the thing standing in
    /// the way.
    pub fn mark_unreadable(
        &self,
        entity: EntityId,
        content: &ContentId,
        wrapped_file_key: Option<&str>,
        now: i64,
    ) -> StoreResult<()> {
        self.conn.execute(
            "INSERT INTO unreadable (entity_type, server_id, sha256, size, wrapped_file_key, noticed_at)
             VALUES (?1,?2,?3,?4,?5,?6)
             ON CONFLICT(entity_type, server_id) DO UPDATE SET
                sha256 = excluded.sha256,
                size = excluded.size,
                wrapped_file_key = excluded.wrapped_file_key,
                noticed_at = excluded.noticed_at",
            params![
                entity.entity_type.to_string(),
                entity.server_id,
                content.sha256,
                content.size as i64,
                wrapped_file_key,
                now
            ],
        )?;
        Ok(())
    }

    /// Everything written off **as of now**: an entry whose note was taken
    /// against the content it still has, under the key it still holds.
    ///
    /// Read once per pass and compared in memory, the same way open ops are, so
    /// deciding about a thousand entries stays one query rather than a thousand.
    pub fn written_off_now(&self) -> StoreResult<HashSet<EntityId>> {
        let sql = format!(
            "SELECT e.entity_type, e.server_id
               FROM entries e
               JOIN unreadable u ON u.entity_type = e.entity_type AND u.server_id = e.server_id
              WHERE {STILL_WRITTEN_OFF}"
        );
        let mut stmt = self.conn.prepare(&sql)?;
        let rows = stmt.query_map([], |r| {
            Ok(EntityId {
                entity_type: parse_entity_type(&r.get::<_, String>(0)?),
                server_id: r.get(1)?,
            })
        })?;
        let mut out = HashSet::new();
        for row in rows {
            out.insert(row?);
        }
        Ok(out)
    }

    /// Drop the note, for when the file is forgotten entirely.
    pub fn clear_unreadable(&self, entity: EntityId) -> StoreResult<()> {
        self.conn.execute(
            "DELETE FROM unreadable WHERE entity_type = ?1 AND server_id = ?2",
            params![entity.entity_type.to_string(), entity.server_id],
        )?;
        Ok(())
    }

    pub fn raise_issue(
        &self,
        entity: Option<EntityId>,
        kind: &str,
        detail: &str,
        now: i64,
    ) -> StoreResult<i64> {
        let etype = entity.map(|e| e.entity_type.to_string());
        let sid = entity.map(|e| e.server_id);
        let existing: Option<i64> = self
            .conn
            .query_row(
                "SELECT issue_id FROM issues
                  WHERE dismissed = 0
                    AND entity_type IS ?1 AND server_id IS ?2
                    AND kind = ?3 AND detail = ?4
                  LIMIT 1",
                params![etype, sid, kind, detail],
                |r| r.get(0),
            )
            .optional()?;
        if let Some(id) = existing {
            return Ok(id);
        }
        self.conn.execute(
            "INSERT INTO issues (entity_type, server_id, kind, detail, created_at)
             VALUES (?1, ?2, ?3, ?4, ?5)",
            params![etype, sid, kind, detail, now],
        )?;
        Ok(self.conn.last_insert_rowid())
    }

    pub fn open_issues(&self) -> StoreResult<Vec<StoredIssue>> {
        let mut stmt = self.conn.prepare(
            "SELECT issue_id, entity_type, server_id, kind, detail, created_at, dismissed
               FROM issues WHERE dismissed = 0 ORDER BY issue_id",
        )?;
        let rows = stmt.query_map([], |r| {
            let etype: Option<String> = r.get(1)?;
            let sid: Option<i64> = r.get(2)?;
            Ok(StoredIssue {
                issue_id: r.get(0)?,
                entity: match (etype, sid) {
                    (Some(t), Some(id)) => Some(EntityId {
                        entity_type: parse_entity_type(&t),
                        server_id: id,
                    }),
                    _ => None,
                },
                kind: r.get(3)?,
                detail: r.get(4)?,
                created_at: r.get(5)?,
                dismissed: r.get::<_, i64>(6)? != 0,
            })
        })?;
        let mut out = Vec::new();
        for r in rows {
            out.push(r?);
        }
        Ok(out)
    }

    pub fn dismiss_issue(&self, issue_id: i64) -> StoreResult<()> {
        self.conn.execute(
            "UPDATE issues SET dismissed = 1 WHERE issue_id = ?1",
            params![issue_id],
        )?;
        Ok(())
    }

    /// Withdraw every open issue of a kind.
    ///
    /// Some issues report an *event* — something was moved aside, something was
    /// rescued, a piece of work was given up on. Those stay until the user
    /// waves them away, because they happened and no later state makes them
    /// untrue.
    ///
    /// Others report a *state*: this name cannot be held here, these items have
    /// no way back to the root. When the state ends, the sentence is simply
    /// false, and leaving it standing means the user is told to look at
    /// something that is no longer there — and can only clear it by hand, one
    /// row at a time. The pass that re-derives the state is the thing that
    /// knows, so it withdraws them.
    ///
    /// Returns how many were withdrawn.
    pub fn withdraw_issues(&self, kind: &str) -> StoreResult<usize> {
        Ok(self.conn.execute(
            "UPDATE issues SET dismissed = 1 WHERE dismissed = 0 AND kind = ?1",
            params![kind],
        )?)
    }

    /// Run a closure inside a transaction, so a step that touches several
    /// tables either lands completely or not at all.
    pub fn transaction<T>(
        &mut self,
        f: impl FnOnce(&Connection) -> StoreResult<T>,
    ) -> StoreResult<T> {
        let tx = self.conn.transaction()?;
        let out = f(&tx)?;
        tx.commit()?;
        Ok(out)
    }
}

fn parse_entity_type(s: &str) -> EntityType {
    if s == "folder" {
        EntityType::Folder
    } else {
        EntityType::File
    }
}

fn encode_status(status: &LocalStatus) -> (String, Option<String>) {
    match status {
        LocalStatus::Synced => ("synced".into(), None),
        LocalStatus::PendingDownload => ("pending_download".into(), None),
        LocalStatus::PendingUpload => ("pending_upload".into(), None),
        LocalStatus::Conflict => ("conflict".into(), None),
        LocalStatus::PendingKey => ("pending_key".into(), None),
        LocalStatus::OutOfScope => ("out_of_scope".into(), None),
        LocalStatus::Unsyncable(reason) => ("unsyncable".into(), Some(encode_reason(reason))),
    }
}

fn decode_status(status: &str, reason: Option<String>) -> LocalStatus {
    match status {
        "pending_download" => LocalStatus::PendingDownload,
        "pending_upload" => LocalStatus::PendingUpload,
        "conflict" => LocalStatus::Conflict,
        "pending_key" => LocalStatus::PendingKey,
        "out_of_scope" => LocalStatus::OutOfScope,
        "unsyncable" => match reason.as_deref().and_then(decode_reason) {
            Some(r) => LocalStatus::Unsyncable(r),
            // A reason this build cannot read. Rather than invent one — the UI
            // would then tell the user something specific and false about their
            // file — hand the entry back as unresolved. The naming layer derives
            // this status from scratch on every pass, so the real verdict is
            // back within milliseconds, with the right reason attached.
            None => LocalStatus::PendingDownload,
        },
        _ => LocalStatus::Synced,
    }
}

/// Why an entry cannot be materialized here, as a string that survives a
/// restart.
///
/// Written out by hand rather than through the debug formatter, because the
/// debug formatter is not a format — it is whatever the struct definition
/// happens to print today, and reading it back is guesswork. What made that
/// worth fixing: the reason is the entire user-facing content of an unsyncable
/// entry, so a reason that does not round-trip is a panel that tells somebody
/// their file clashes with a file called `NameTooLong { bytes: 300, limit: 255 }`.
pub(crate) fn encode_reason(reason: &jd_vfs::UnsyncableReason) -> String {
    use jd_vfs::UnsyncableReason as R;
    match reason {
        R::CaseClash { with } => format!("case_clash:{with}"),
        R::UnicodeClash { with } => format!("unicode_clash:{with}"),
        R::DuplicateName { with } => format!("duplicate_name:{with}"),
        R::NameTooLong { bytes, limit } => format!("name_too_long:{bytes}:{limit}"),
        R::PathTooLong { bytes, limit } => format!("path_too_long:{bytes}:{limit}"),
        R::Empty => "empty".into(),
        R::ReservedPrefix => "reserved_prefix".into(),
        R::EncryptedUnsupported => "encrypted_unsupported".into(),
    }
}

pub(crate) fn decode_reason(raw: &str) -> Option<jd_vfs::UnsyncableReason> {
    use jd_vfs::UnsyncableReason as R;
    let (kind, rest) = raw.split_once(':').unwrap_or((raw, ""));
    match kind {
        // A filename may contain colons, so the name takes the whole remainder.
        "case_clash" => Some(R::CaseClash { with: rest.into() }),
        "unicode_clash" => Some(R::UnicodeClash { with: rest.into() }),
        "duplicate_name" => Some(R::DuplicateName { with: rest.into() }),
        "name_too_long" | "path_too_long" => {
            let (bytes, limit) = rest.split_once(':')?;
            let bytes = bytes.parse().ok()?;
            let limit = limit.parse().ok()?;
            Some(if kind == "name_too_long" {
                R::NameTooLong { bytes, limit }
            } else {
                R::PathTooLong { bytes, limit }
            })
        }
        "empty" => Some(R::Empty),
        "reserved_prefix" => Some(R::ReservedPrefix),
        "encrypted_unsupported" => Some(R::EncryptedUnsupported),
        _ => None,
    }
}

fn row_to_entry(r: &rusqlite::Row<'_>) -> rusqlite::Result<Entry> {
    let entity_type = parse_entity_type(&r.get::<_, String>(0)?);
    let remote_sha: Option<String> = r.get(6)?;
    let remote_size: Option<i64> = r.get(7)?;
    let synced_sha: Option<String> = r.get(11)?;
    let synced_size: Option<i64> = r.get(12)?;
    let synced_parent: Option<i64> = r.get(13)?;
    let synced_name: Option<String> = r.get(14)?;
    let fp_size: Option<i64> = r.get(15)?;
    let fp_mtime: Option<i64> = r.get(16)?;
    let fp_file_id: Option<i64> = r.get(17)?;
    let status: String = r.get(18)?;
    let reason: Option<String> = r.get(19)?;
    let synced_remote_sha: Option<String> = r.get(22)?;
    let synced_remote_size: Option<i64> = r.get(23)?;

    Ok(Entry {
        id: EntityId {
            entity_type,
            server_id: r.get(1)?,
        },
        remote: Placement {
            parent: r.get(2)?,
            name: r.get(3)?,
        },
        local_name: r.get(4)?,
        is_encrypted: r.get::<_, i64>(5)? != 0,
        remote_content: match (remote_sha, remote_size) {
            (Some(sha256), Some(size)) => Some(ContentId {
                sha256,
                size: size as u64,
            }),
            _ => None,
        },
        remote_modified_time: r.get(8)?,
        head_change_id: r.get(9)?,
        remote_deleted: r.get::<_, i64>(10)? != 0,
        synced_content: match (synced_sha, synced_size) {
            (Some(sha256), Some(size)) => Some(ContentId {
                sha256,
                size: size as u64,
            }),
            _ => None,
        },
        synced_placement: synced_name.map(|name| Placement {
            parent: synced_parent,
            name,
        }),
        synced_fingerprint: match (fp_size, fp_mtime, fp_file_id) {
            (Some(size), Some(mtime_ns), Some(file_id)) => Some(jd_vfs::Fingerprint {
                size: size as u64,
                mtime_ns: mtime_ns as u64,
                file_id: file_id as u64,
            }),
            _ => None,
        },
        synced_remote_content: match (synced_remote_sha, synced_remote_size) {
            (Some(sha256), Some(size)) => Some(ContentId {
                sha256,
                size: size as u64,
            }),
            _ => None,
        },
        status: decode_status(&status, reason),
        wrapped_file_key: r.get(20)?,
        content_id: r.get(21)?,
        replaces: match (
            r.get::<_, Option<String>>(24)?,
            r.get::<_, Option<i64>>(25)?,
        ) {
            (Some(kind), Some(server_id)) => Some(EntityId {
                entity_type: if kind == "folder" {
                    EntityType::Folder
                } else {
                    EntityType::File
                },
                server_id,
            }),
            _ => None,
        },
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn every_unsyncable_reason_survives_a_restart_intact() {
        // The reason is the whole user-facing content of an unsyncable entry. A
        // reason that does not round-trip is a panel telling somebody their file
        // clashes with one called `NameTooLong { bytes: 300, limit: 255 }`.
        use jd_vfs::UnsyncableReason as R;
        for reason in [
            R::CaseClash {
                with: "Report.txt".into(),
            },
            R::UnicodeClash {
                with: "caf\u{e9}.txt".into(),
            },
            R::NameTooLong {
                bytes: 300,
                limit: 255,
            },
            R::PathTooLong {
                bytes: 32_100,
                limit: 32_000,
            },
            R::Empty,
            R::ReservedPrefix,
        ] {
            let status = LocalStatus::Unsyncable(reason.clone());
            let (kind, encoded) = encode_status(&status);
            assert_eq!(
                decode_status(&kind, encoded),
                status,
                "{reason:?} did not survive"
            );
        }
    }

    #[test]
    fn a_name_containing_a_colon_still_round_trips() {
        // The encoding is colon-separated and filenames may contain colons, so
        // the name has to take the whole remainder rather than one field.
        use jd_vfs::UnsyncableReason as R;
        let status = LocalStatus::Unsyncable(R::CaseClash {
            with: "Q3: final: v2.txt".into(),
        });
        let (kind, encoded) = encode_status(&status);
        assert_eq!(decode_status(&kind, encoded), status);
    }

    #[test]
    fn an_unreadable_reason_becomes_unresolved_rather_than_a_made_up_one() {
        // Written by a future build, or corrupted. Inventing a reason would tell
        // the user something specific and false; handing the entry back
        // unresolved gets the real verdict re-derived on the next pass.
        assert_eq!(
            decode_status("unsyncable", Some("something_from_the_future:x".into())),
            LocalStatus::PendingDownload
        );
        assert_eq!(
            decode_status("unsyncable", None),
            LocalStatus::PendingDownload
        );
    }

    fn entry(id: i64, name: &str) -> Entry {
        Entry {
            id: EntityId::file(id),
            remote: Placement {
                parent: None,
                name: name.into(),
            },
            remote_content: Some(ContentId {
                sha256: "remote-sha".into(),
                size: 12,
            }),
            remote_modified_time: Some("2026-07-16 10:00:00".into()),
            head_change_id: 42,
            remote_deleted: false,
            is_encrypted: false,
            content_id: None,
            synced_remote_content: None,
            synced_content: Some(ContentId {
                sha256: "agreed-sha".into(),
                size: 10,
            }),
            synced_placement: Some(Placement {
                parent: None,
                name: name.into(),
            }),
            synced_fingerprint: Some(jd_vfs::Fingerprint {
                size: 10,
                mtime_ns: 1234,
                file_id: 99,
            }),
            local_name: None,
            status: LocalStatus::Synced,
            wrapped_file_key: None,
            replaces: None,
        }
    }

    #[test]
    fn a_fresh_store_stamps_its_schema_version() {
        let s = Store::open_in_memory().unwrap();
        assert_eq!(
            s.get_meta("schema_version").unwrap().unwrap(),
            SCHEMA_VERSION.to_string()
        );
    }

    #[test]
    fn a_store_from_a_newer_client_is_refused_not_guessed_at() {
        // A user who upgraded on one machine and then ran an older build here
        // must get a clear refusal. Half-understanding a state store produces
        // confident wrong answers about what the two sides agreed on, and those
        // answers overwrite files.
        let dir = std::env::temp_dir().join(format!("jd-store-newer-{}", std::process::id()));
        std::fs::create_dir_all(&dir).unwrap();
        let path = dir.join("state.db");
        let _ = std::fs::remove_file(&path);

        {
            let s = Store::open(&path).unwrap();
            s.set_meta("schema_version", "999").unwrap();
        }
        match Store::open(&path) {
            Err(StoreError::SchemaTooNew { found, .. }) => assert_eq!(found, 999),
            Err(other) => panic!("wrong error: {other}"),
            Ok(_) => panic!("an older build must refuse a newer state store, not open it"),
        }

        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn an_entry_round_trips_including_its_last_agreed_state() {
        let s = Store::open_in_memory().unwrap();
        let e = entry(7, "Report.txt");
        s.put_entry(&e).unwrap();
        let back = s.get_entry(EntityId::file(7)).unwrap().unwrap();
        assert_eq!(back, e);
        // The agreement is the whole point — spot-check it explicitly.
        assert_eq!(back.synced_content.unwrap().sha256, "agreed-sha");
        assert_eq!(back.synced_fingerprint.unwrap().file_id, 99);
    }

    #[test]
    fn writing_an_entry_twice_updates_rather_than_duplicating() {
        let s = Store::open_in_memory().unwrap();
        s.put_entry(&entry(7, "a.txt")).unwrap();
        let mut e = entry(7, "renamed.txt");
        e.head_change_id = 100;
        s.put_entry(&e).unwrap();
        let back = s.get_entry(EntityId::file(7)).unwrap().unwrap();
        assert_eq!(back.remote.name, "renamed.txt");
        assert_eq!(back.head_change_id, 100);
        assert_eq!(s.children_of(None).unwrap().len(), 1);
    }

    #[test]
    fn files_and_folders_with_the_same_number_are_different_entries() {
        // The key is (type, id) — the server's two id spaces are independent,
        // and collapsing them would cross-wire a folder with an unrelated file.
        let s = Store::open_in_memory().unwrap();
        let mut file = entry(5, "file.txt");
        s.put_entry(&file).unwrap();
        file.id = EntityId::folder(5);
        file.remote.name = "Folder".into();
        s.put_entry(&file).unwrap();

        assert_eq!(
            s.get_entry(EntityId::file(5)).unwrap().unwrap().remote.name,
            "file.txt"
        );
        assert_eq!(
            s.get_entry(EntityId::folder(5))
                .unwrap()
                .unwrap()
                .remote
                .name,
            "Folder"
        );
    }

    #[test]
    fn the_cursor_starts_at_zero_and_persists() {
        let s = Store::open_in_memory().unwrap();
        assert_eq!(s.cursor().unwrap(), 0);
        s.set_cursor(918).unwrap();
        assert_eq!(s.cursor().unwrap(), 918);
    }

    #[test]
    fn an_interrupted_op_is_findable_after_a_crash() {
        let s = Store::open_in_memory().unwrap();
        let op = s
            .queue_op("upload", EntityId::file(3), "{}", "idem-abc")
            .unwrap();
        assert_eq!(s.queued_ops().unwrap().len(), 1);
        assert!(s.interrupted_ops().unwrap().is_empty());

        // The engine marks the intent in flight, then dies here.
        s.set_op_state(op, OpState::InFlight).unwrap();

        let found = s.interrupted_ops().unwrap();
        assert_eq!(found.len(), 1);
        assert_eq!(found[0].entity, EntityId::file(3));
        // The idempotency key survives, which is what makes replaying safe: the
        // server recognizes the retry instead of applying it twice.
        assert_eq!(found[0].idempotency_key, "idem-abc");
    }

    #[test]
    fn a_failed_op_goes_back_to_queued_with_its_reason_and_a_retry_time() {
        let s = Store::open_in_memory().unwrap();
        let op = s
            .queue_op("download", EntityId::file(4), "{}", "idem-x")
            .unwrap();
        s.set_op_state(op, OpState::InFlight).unwrap();
        s.record_op_failure(op, "quota exceeded", 1_700_000_000)
            .unwrap();

        let queued = s.queued_ops().unwrap();
        assert_eq!(queued.len(), 1);
        assert_eq!(queued[0].attempts, 1);
        // Failures are never silent — the reason is what the issues panel shows.
        assert_eq!(queued[0].last_error.as_deref(), Some("quota exceeded"));
        assert_eq!(queued[0].next_retry_time, Some(1_700_000_000));
    }

    #[test]
    fn done_ops_are_pruned_and_the_rest_are_left_alone() {
        let s = Store::open_in_memory().unwrap();
        let a = s.queue_op("upload", EntityId::file(1), "{}", "k1").unwrap();
        let _b = s.queue_op("upload", EntityId::file(2), "{}", "k2").unwrap();
        s.set_op_state(a, OpState::Done).unwrap();
        assert_eq!(s.prune_done_ops().unwrap(), 1);
        assert_eq!(s.queued_ops().unwrap().len(), 1);
    }

    #[test]
    fn the_hash_cache_only_answers_for_the_exact_fingerprint_it_learned() {
        let s = Store::open_in_memory().unwrap();
        let fp = jd_vfs::Fingerprint {
            size: 100,
            mtime_ns: 5000,
            file_id: 42,
        };
        s.cache_hash(fp, "sha-of-those-bytes", Some(EntityId::file(1)), 9000)
            .unwrap();
        assert_eq!(
            s.cached_hash(fp, 1).unwrap().as_deref(),
            Some("sha-of-those-bytes")
        );

        // Rewritten in place: same inode, same size, new mtime. The cache must
        // NOT answer — answering would adopt stale content as agreement and
        // discard the user's edit.
        let rewritten = jd_vfs::Fingerprint {
            mtime_ns: 6000,
            ..fp
        };
        assert_eq!(s.cached_hash(rewritten, 1).unwrap(), None);

        // Same inode and mtime but a different size is also a different file.
        let resized = jd_vfs::Fingerprint { size: 101, ..fp };
        assert_eq!(s.cached_hash(resized, 1).unwrap(), None);

        // And the row is only evidence once the file was older than the clock's
        // own resolution when it was read. Taken 4000ns after the file was
        // written, it answers for a filesystem that times to the nanosecond and
        // refuses for one that times in ten-microsecond steps — where a second
        // write of the same length would have kept this very fingerprint.
        assert_eq!(s.cached_hash(fp, 4_000).unwrap().as_deref(), Some("sha-of-those-bytes"));
        assert_eq!(s.cached_hash(fp, 4_001).unwrap(), None);
    }

    #[test]
    fn a_local_file_id_resolves_back_to_its_entry_so_moves_keep_identity() {
        let s = Store::open_in_memory().unwrap();
        let fp = jd_vfs::Fingerprint {
            size: 10,
            mtime_ns: 1,
            file_id: 777,
        };
        s.cache_hash(fp, "sha", Some(EntityId::file(12)), 2).unwrap();
        assert_eq!(s.entity_for_file_id(777).unwrap(), Some(EntityId::file(12)));
        assert_eq!(s.entity_for_file_id(778).unwrap(), None);
    }

    #[test]
    fn issues_are_listed_until_dismissed() {
        let s = Store::open_in_memory().unwrap();
        let id = s
            .raise_issue(
                Some(EntityId::file(1)),
                "conflict",
                "Report.xlsx — both sides changed it",
                1000,
            )
            .unwrap();
        s.raise_issue(None, "quota", "Owner's Drive is full", 1001)
            .unwrap();
        assert_eq!(s.open_issues().unwrap().len(), 2);

        s.dismiss_issue(id).unwrap();
        let open = s.open_issues().unwrap();
        assert_eq!(open.len(), 1);
        assert_eq!(open[0].kind, "quota");
    }

    #[test]
    fn the_same_problem_said_twice_is_still_one_problem() {
        // A pass re-reaches the same conclusion about the same entity every
        // time it runs. A soak run finished with 1,152 issues on a device that
        // had three problems, 1,166 of them one stuck file saying the same
        // sentence over and over.
        let s = Store::open_in_memory().unwrap();
        let first = s
            .raise_issue(Some(EntityId::file(1)), "reconcile", "the same thing", 1000)
            .unwrap();
        for t in 1..500 {
            let again = s
                .raise_issue(
                    Some(EntityId::file(1)),
                    "reconcile",
                    "the same thing",
                    1000 + t,
                )
                .unwrap();
            assert_eq!(again, first, "it is the same issue, not a new one");
        }
        assert_eq!(s.open_issues().unwrap().len(), 1);

        // A different situation about the same file still gets through.
        s.raise_issue(Some(EntityId::file(1)), "reconcile", "something else", 2000)
            .unwrap();
        // As does the same sentence about a different file.
        s.raise_issue(Some(EntityId::file(2)), "reconcile", "the same thing", 2000)
            .unwrap();
        assert_eq!(s.open_issues().unwrap().len(), 3);

        // And waving one away means the next occurrence is heard again — it
        // happened a second time, which is news.
        s.dismiss_issue(first).unwrap();
        assert_eq!(s.open_issues().unwrap().len(), 2);
        s.raise_issue(Some(EntityId::file(1)), "reconcile", "the same thing", 3000)
            .unwrap();
        assert_eq!(s.open_issues().unwrap().len(), 3);
    }

    #[test]
    fn the_synced_count_measures_only_entries_in_agreement() {
        let s = Store::open_in_memory().unwrap();
        s.put_entry(&entry(1, "a")).unwrap();
        s.put_entry(&entry(2, "b")).unwrap();
        let mut pending = entry(3, "c");
        pending.status = LocalStatus::PendingUpload;
        s.put_entry(&pending).unwrap();

        // The mass-delete guard measures against this, so counting in-flight
        // work would make the denominator lie in the dangerous direction.
        assert_eq!(s.synced_count().unwrap(), 2);
    }

    #[test]
    fn a_deleted_entry_is_gone_from_its_parents_children() {
        let s = Store::open_in_memory().unwrap();
        s.put_entry(&entry(1, "a")).unwrap();
        s.put_entry(&entry(2, "b")).unwrap();
        assert_eq!(s.children_of(None).unwrap().len(), 2);
        s.delete_entry(EntityId::file(1)).unwrap();
        assert_eq!(s.children_of(None).unwrap().len(), 1);
    }

    #[test]
    fn state_survives_a_reopen() {
        let dir = std::env::temp_dir().join(format!("jd-store-test-{}", std::process::id()));
        std::fs::create_dir_all(&dir).unwrap();
        let path = dir.join("state.db");
        let _ = std::fs::remove_file(&path);

        {
            let s = Store::open(&path).unwrap();
            s.put_entry(&entry(1, "persisted.txt")).unwrap();
            s.set_cursor(555).unwrap();
        }
        {
            let s = Store::open(&path).unwrap();
            assert_eq!(s.cursor().unwrap(), 555);
            let back = s.get_entry(EntityId::file(1)).unwrap().unwrap();
            assert_eq!(back.remote.name, "persisted.txt");
            assert_eq!(back.synced_content.unwrap().sha256, "agreed-sha");
        }
        let _ = std::fs::remove_dir_all(&dir);
    }
}
