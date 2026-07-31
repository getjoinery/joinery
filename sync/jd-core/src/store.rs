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

use std::path::Path;

use rusqlite::{params, Connection, OptionalExtension};

use crate::model::{ContentId, EntityId, EntityType, Entry, LocalStatus, Placement};

/// Bumped when the schema changes in a way an older engine could misread.
pub const SCHEMA_VERSION: i64 = 2;

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
/// mid-act, and is re-derived rather than blindly retried — the server may
/// already have applied it.
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
                PRIMARY KEY (file_id, size, mtime_ns)
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
        Ok(store)
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
                local_status, unsyncable_reason, wrapped_file_key
             ) VALUES (?1,?2,?3,?4,?5,?6,?7,?8,?9,?10,?11,?12,?13,?14,?15,?16,?17,?18,?19,?20,?21)
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
                wrapped_file_key = excluded.wrapped_file_key",
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
                        local_status, unsyncable_reason, wrapped_file_key
                   FROM entries WHERE entity_type = ?1 AND server_id = ?2",
                params![id.entity_type.to_string(), id.server_id],
                row_to_entry,
            )
            .optional()?)
    }

    pub fn delete_entry(&self, id: EntityId) -> StoreResult<()> {
        self.conn.execute(
            "DELETE FROM entries WHERE entity_type = ?1 AND server_id = ?2",
            params![id.entity_type.to_string(), id.server_id],
        )?;
        Ok(())
    }

    /// Children of a folder (`None` for the drive root).
    pub fn children_of(&self, parent: Option<i64>) -> StoreResult<Vec<Entry>> {
        let sql = "SELECT entity_type, server_id, parent_folder_id, remote_name, local_name,
                          is_encrypted, remote_content_sha256, remote_size, remote_modified_time,
                          head_change_id, remote_deleted, synced_content_sha256, synced_size, synced_parent_id,
                          synced_name, synced_fp_size, synced_fp_mtime_ns, synced_fp_file_id,
                          local_status, unsyncable_reason, wrapped_file_key
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

    /// Ops that were interrupted mid-act.
    ///
    /// These are the crash window made visible. Each is re-derived — both sides
    /// are re-checked — rather than re-run, because the server may have applied
    /// it before the process died.
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
    ) -> StoreResult<()> {
        self.conn.execute(
            "INSERT INTO local_index (file_id, size, mtime_ns, sha256, entity_type, server_id)
             VALUES (?1, ?2, ?3, ?4, ?5, ?6)
             ON CONFLICT(file_id, size, mtime_ns) DO UPDATE SET
                sha256 = excluded.sha256,
                entity_type = excluded.entity_type,
                server_id = excluded.server_id",
            params![
                fp.file_id as i64,
                fp.size as i64,
                fp.mtime_ns as i64,
                sha256,
                entity.map(|e| e.entity_type.to_string()),
                entity.map(|e| e.server_id),
            ],
        )?;
        Ok(())
    }

    /// The cached hash for exactly this fingerprint, if we have one.
    ///
    /// The lookup is exact on all three fields on purpose. A cache keyed on the
    /// inode alone would happily hand back the hash of a file that has since
    /// been rewritten in place — which would then be adopted as agreement, and
    /// the user's edit would be silently discarded.
    pub fn cached_hash(&self, fp: jd_vfs::Fingerprint) -> StoreResult<Option<String>> {
        Ok(self
            .conn
            .query_row(
                "SELECT sha256 FROM local_index
                  WHERE file_id = ?1 AND size = ?2 AND mtime_ns = ?3",
                params![fp.file_id as i64, fp.size as i64, fp.mtime_ns as i64],
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

    pub fn raise_issue(
        &self,
        entity: Option<EntityId>,
        kind: &str,
        detail: &str,
        now: i64,
    ) -> StoreResult<i64> {
        self.conn.execute(
            "INSERT INTO issues (entity_type, server_id, kind, detail, created_at)
             VALUES (?1, ?2, ?3, ?4, ?5)",
            params![
                entity.map(|e| e.entity_type.to_string()),
                entity.map(|e| e.server_id),
                kind,
                detail,
                now
            ],
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
        LocalStatus::Unsyncable(reason) => ("unsyncable".into(), Some(format!("{:?}", reason))),
    }
}

fn decode_status(status: &str, reason: Option<String>) -> LocalStatus {
    match status {
        "pending_download" => LocalStatus::PendingDownload,
        "pending_upload" => LocalStatus::PendingUpload,
        "conflict" => LocalStatus::Conflict,
        "pending_key" => LocalStatus::PendingKey,
        "out_of_scope" => LocalStatus::OutOfScope,
        "unsyncable" => LocalStatus::Unsyncable(jd_vfs::UnsyncableReason::CaseClash {
            with: reason.unwrap_or_default(),
        }),
        _ => LocalStatus::Synced,
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
        status: decode_status(&status, reason),
        wrapped_file_key: r.get(20)?,
    })
}

#[cfg(test)]
mod tests {
    use super::*;

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
        s.cache_hash(fp, "sha-of-those-bytes", Some(EntityId::file(1)))
            .unwrap();
        assert_eq!(
            s.cached_hash(fp).unwrap().as_deref(),
            Some("sha-of-those-bytes")
        );

        // Rewritten in place: same inode, same size, new mtime. The cache must
        // NOT answer — answering would adopt stale content as agreement and
        // discard the user's edit.
        let rewritten = jd_vfs::Fingerprint {
            mtime_ns: 6000,
            ..fp
        };
        assert_eq!(s.cached_hash(rewritten).unwrap(), None);

        // Same inode and mtime but a different size is also a different file.
        let resized = jd_vfs::Fingerprint { size: 101, ..fp };
        assert_eq!(s.cached_hash(resized).unwrap(), None);
    }

    #[test]
    fn a_local_file_id_resolves_back_to_its_entry_so_moves_keep_identity() {
        let s = Store::open_in_memory().unwrap();
        let fp = jd_vfs::Fingerprint {
            size: 10,
            mtime_ns: 1,
            file_id: 777,
        };
        s.cache_hash(fp, "sha", Some(EntityId::file(12))).unwrap();
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
