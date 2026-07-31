//! What the engine knows about one thing being synced.
//!
//! The whole design turns on one idea: for every entry there are three states —
//! what is on this machine now, what is on the server now, and **the last state
//! both sides agreed on**. Sync is not "copy the newer one"; it is working out
//! what each side did since they last agreed, from those three. Keep the
//! last-agreed state honestly and the hard cases become arithmetic. Lose it and
//! no amount of cleverness downstream recovers.
//!
//! Entries are keyed by server id, never by path. A path is a label the user
//! can change; identity is not. That is what makes renaming a folder of ten
//! thousand files one operation, and what lets a moved file keep its sharing
//! and its version history instead of arriving as a stranger.

use std::fmt;

#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash, PartialOrd, Ord)]
pub enum EntityType {
    File,
    Folder,
}

impl fmt::Display for EntityType {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(match self {
            EntityType::File => "file",
            EntityType::Folder => "folder",
        })
    }
}

/// The identity of a synced thing: what the server calls it.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash, PartialOrd, Ord)]
pub struct EntityId {
    pub entity_type: EntityType,
    pub server_id: i64,
}

impl EntityId {
    pub fn file(id: i64) -> Self {
        EntityId {
            entity_type: EntityType::File,
            server_id: id,
        }
    }
    pub fn folder(id: i64) -> Self {
        EntityId {
            entity_type: EntityType::Folder,
            server_id: id,
        }
    }
}

/// Where an entry sits and what it is called, on one side.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Placement {
    /// Parent folder's server id; `None` is the drive root.
    pub parent: Option<i64>,
    /// The name in the server's exact bytes (or, for an encrypted file, the
    /// decrypted name — the engine works in the plaintext domain throughout).
    pub name: String,
}

/// Content identity. For an encrypted file this is the plaintext-domain hash,
/// so that "did the content change?" means the same thing on both sides even
/// though the bytes on the wire differ every time they are encrypted.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ContentId {
    pub sha256: String,
    pub size: u64,
}

/// The visible state of an entry. Every entry is always in exactly one of
/// these, and the tray reduces the whole set to a single honest indicator.
/// There is deliberately no "unknown" — an entry the engine cannot place is an
/// entry the user gets told about.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum LocalStatus {
    Synced,
    PendingDownload,
    PendingUpload,
    Conflict,
    /// Cannot exist on this filesystem; carries the reason so the UI can say
    /// something true and specific.
    Unsyncable(jd_vfs::UnsyncableReason),
    /// An encrypted file whose key has not arrived yet. Not an error — the
    /// owner may simply not have granted it — so it waits and says so.
    PendingKey,
    /// Deliberately not synced here: a descoped subtree, or something that left
    /// the caller's visibility. Tracked, not absent, which is what makes
    /// "unchecked" structurally different from "deleted".
    OutOfScope,
}

/// One row of the state store: everything known about one entity.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Entry {
    pub id: EntityId,

    // ---- what the server has now -----------------------------------------
    pub remote: Placement,
    pub remote_content: Option<ContentId>,
    pub remote_modified_time: Option<String>,
    /// The change-feed position that produced the current remote content. Lets
    /// the engine say "what I hold corresponds to feed position N" without
    /// hashing anything.
    pub head_change_id: i64,
    pub is_encrypted: bool,

    // ---- the last state both sides agreed on ------------------------------
    /// The content both sides had at the last successful sync. This is the
    /// pivot every decision turns on: local differs from it → we edited;
    /// remote differs from it → they edited; both differ → conflict.
    pub synced_content: Option<ContentId>,
    pub synced_placement: Option<Placement>,
    /// The cheap filter for "has the local file changed since we agreed".
    pub synced_fingerprint: Option<jd_vfs::Fingerprint>,

    // ---- how it is materialized here --------------------------------------
    /// Set when the name had to be adjusted to fit this filesystem; the mapping
    /// here is authoritative, not the reversibility of the escape.
    pub local_name: Option<String>,
    pub status: LocalStatus,
    pub wrapped_file_key: Option<String>,
}

impl Entry {
    /// The name this entry is materialized under locally.
    pub fn effective_local_name(&self) -> &str {
        self.local_name.as_deref().unwrap_or(&self.remote.name)
    }

    /// Has this entry ever completed a sync? A `None` last-agreed state means
    /// it has not, which is why a brand-new entry can never be read as "the
    /// other side deleted it".
    pub fn is_established(&self) -> bool {
        self.synced_content.is_some() || self.synced_placement.is_some()
    }
}

/// What one side did to an entry since the last agreement.
///
/// Content and location are independent axes, deliberately. An id-keyed entry
/// can be moved on the server while being edited locally, and those compose
/// into "apply the move, then upload the edit" rather than fighting.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Delta {
    /// Nothing happened on this side.
    None,
    /// The content changed; placement did not.
    Edited { content: ContentId },
    /// The entry appeared on this side and was not known before.
    Created {
        placement: Placement,
        content: Option<ContentId>,
    },
    /// The entry is gone from this side.
    Deleted,
    /// Same content, new name or parent.
    Moved { to: Placement },
    /// Both at once: moved and edited.
    MovedAndEdited { to: Placement, content: ContentId },
}

impl Delta {
    pub fn is_none(&self) -> bool {
        matches!(self, Delta::None)
    }

    /// The content this side now holds, when it changed.
    pub fn content(&self) -> Option<&ContentId> {
        match self {
            Delta::Edited { content } | Delta::MovedAndEdited { content, .. } => Some(content),
            Delta::Created { content, .. } => content.as_ref(),
            _ => None,
        }
    }

    /// Where this side now puts it, when it moved.
    pub fn placement(&self) -> Option<&Placement> {
        match self {
            Delta::Moved { to } | Delta::MovedAndEdited { to, .. } => Some(to),
            Delta::Created { placement, .. } => Some(placement),
            _ => None,
        }
    }

    pub fn is_delete(&self) -> bool {
        matches!(self, Delta::Deleted)
    }

    /// Did this side change the bytes? A move alone did not.
    pub fn touched_content(&self) -> bool {
        matches!(
            self,
            Delta::Edited { .. } | Delta::MovedAndEdited { .. } | Delta::Created { .. }
        )
    }
}
