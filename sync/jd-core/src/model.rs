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

    /// Does this thing exist on the server yet?
    ///
    /// Something created on this computer needs an identity before the server
    /// has given it one — a file has to be queued for upload, and a folder has
    /// to be able to hold children, both before anything is sent. Those get a
    /// **negative** id, allocated locally and counting downward.
    ///
    /// Negative rather than a separate flag, because a sign cannot get out of
    /// step with the thing it describes: server ids are always positive, so any
    /// id either is one or plainly is not. Once the create lands, the entry is
    /// re-keyed to the real id and the provisional one is never reused.
    pub fn is_provisional(&self) -> bool {
        self.server_id < 0
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

/// Where a folder's directory stands on this disk, and the agreement that
/// was in force when it was found there. See `Entry::stands_at`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct StandsAt {
    /// The parent folder whose directory holds it, and the name it wears on
    /// this disk (a local name, already as this volume writes it).
    pub here: Placement,
    /// The agreed placement it was measured against.
    pub agreed: Placement,
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
    /// The server has trashed it.
    ///
    /// Recorded rather than acted on immediately, because the feed mentions a
    /// deletion exactly once. A pass that heard it and died before removing the
    /// local file would never hear it again, and the file would sit there
    /// forever looking synced.
    pub remote_deleted: bool,
    pub is_encrypted: bool,
    /// The crypto content id of an encrypted file: the string bound into every
    /// chunk's authentication tag, and stable for the life of the file across
    /// every version of its content. Learned from the decrypted metadata,
    /// minted here when this device creates the file.
    ///
    /// Without it a downloaded chunk cannot be verified and a new version
    /// cannot be encrypted so the other devices can read it.
    pub content_id: Option<String>,

    // ---- the last state both sides agreed on ------------------------------
    /// The content both sides had at the last successful sync. This is the
    /// pivot every decision turns on: local differs from it → we edited;
    /// remote differs from it → they edited; both differ → conflict.
    pub synced_content: Option<ContentId>,
    /// The same agreement, measured the way the *server* measures it.
    ///
    /// For a plaintext file this would be identical to `synced_content` and is
    /// left `None`. For an encrypted one the two sides speak different hash
    /// languages — the server only ever sees ciphertext, and encrypting the
    /// same plaintext twice produces different bytes — so "did the server's
    /// copy change?" can only be answered by comparing ciphertext to
    /// ciphertext. This is the ciphertext side of the last agreement.
    ///
    /// The alternative, re-encrypting the local file to see whether it matches
    /// what the server holds, does not work and cannot be made to: random IVs
    /// mean equal plaintexts never produce equal ciphertexts.
    pub synced_remote_content: Option<ContentId>,
    pub synced_placement: Option<Placement>,
    /// The cheap filter for "has the local file changed since we agreed".
    pub synced_fingerprint: Option<jd_vfs::Fingerprint>,

    // ---- how it is materialized here --------------------------------------
    /// Set when the name had to be adjusted to fit this filesystem; the mapping
    /// here is authoritative, not the reversibility of the escape.
    pub local_name: Option<String>,
    pub status: LocalStatus,
    pub wrapped_file_key: Option<String>,
    /// The file on the server whose copy this entry's first upload replaces.
    ///
    /// Set only when the user moved a tracked plaintext file into a vault this
    /// device has no key for. The bytes are at a path inside the vault now, so
    /// this entry is minted to claim them and wait for a key -- and the file
    /// they came from is still on the server with its own entry, which suddenly
    /// has no local file at all. That reads as the user deleting it, and acting
    /// on it would trash the last copy anyone else can reach while the
    /// replacement is one this device cannot yet upload.
    ///
    /// So the source is held until the create here has LANDED, which is
    /// strictly safer than the keyed path -- that trashes first and re-uploads
    /// after. This is a fact about where these bytes came from, not a state
    /// something has to remember to clear: the hold is recomputed from it every
    /// pass, lapses by itself the moment this entry stops being provisional,
    /// and disappears with this entry if the user moves the file back out.
    pub replaces: Option<EntityId>,
    /// The directory on this disk that stands in for a vault folder this
    /// device cannot open.
    ///
    /// A device with no key never materializes a vault folder, so the folder
    /// has no agreement here and no local name of its own. Nothing stops the
    /// user making a directory of the vault's name and saving into it, and the
    /// engine holds what they save there for a key (`PendingKey`). Until this
    /// was recorded, that directory was the vault's only while the two names
    /// matched: the holder renaming the vault on the server turned the user's
    /// directory into a brand-new plain folder of the old name, and the files
    /// they believed private went up in the clear.
    ///
    /// Set the first time a directory is found at the folder's derived path
    /// while the folder waits for a key. From then on the directory IS the
    /// folder's, by identity rather than by name: the pass renames it after the
    /// server (`placeholders_follow_the_server`), and files under it resolve
    /// their paths through it.
    ///
    /// Deliberately not the agreement. A folder with an agreed placement whose
    /// directory goes has been deleted by the user, and that is pushed to the
    /// server; a stand-in directory that goes has merely stopped standing in
    /// -- the user removed a placeholder that never held a byte of the vault --
    /// and the tie lapses with nothing said to anyone. Cleared the moment the
    /// folder is materialized for real, when the agreement takes over.
    pub stand_in: Option<Placement>,
    /// Where this folder's directory stands on this disk while that is not
    /// where its agreement puts it: the user has moved it here and the move
    /// is not agreed yet.
    ///
    /// The agreement is what both sides last settled on, and every decision
    /// about what to tell the server is read against it. It is not where the
    /// directory is. Read as if it were, every path built through a folder
    /// the user had just moved named its old place: a file a peer moved into
    /// it landed in whatever directory now wore the old name -- the vault's,
    /// after a swap -- and went up sealed into the wrong folder (plat3 75424);
    /// a file saved in it was looked for at the old place and forgotten in
    /// the pass that minted it, then sent in the clear (plat3 75455); an
    /// upload from it stood down as overtaken for ever (B-CARRY).
    ///
    /// Learned each pass from the directory's identity, after the scan has
    /// matched directories to folders (`note_where_folders_stand`), so a
    /// volume that keeps no directory identities never has one and behaves
    /// as before. It holds only while the agreement is the one it was
    /// measured against: an agreement written since -- the move agreed, the
    /// folder parked, re-agreed anywhere -- retires it without anyone having
    /// to remember to (`StandsAt::agreed`).
    pub stands_at: Option<StandsAt>,
    /// Which file on this disk is this record's own (a file record only).
    ///
    /// Not the agreement: `synced_fingerprint` is what both sides last agreed
    /// on, for spotting a change, and it is reset whenever the agreement is.
    /// This is the file itself, from the moment the record has one here --
    /// minted from a scanned file, placed by a download, or taken over from a
    /// safe-save -- and it stays through every move of that file, the
    /// agreement's resets and the server deleting the record. It changes hands
    /// when the engine hands the file to another record (a conflict rescue, a
    /// crossing claimant, a merge), and is dropped when the engine gives the
    /// file up (a park, a disowning, a placement dropped). See
    /// `specs/drive_file_identity.md`.
    pub own_file: Option<jd_vfs::FileIdentity>,
    /// The hash of this record's file as the last scan saw it, where it
    /// stood. Not an agreement: the bytes the server has are
    /// `synced_content`. On a volume that cannot say which file is which, a
    /// file whose bytes are still these is this record's file unedited, found
    /// wherever it now stands (`specs/drive_weak_volume_identity.md`, layer 1).
    pub last_seen_sha: Option<String>,
    /// Where this record's own file last stood on this disk, for a record
    /// never sent: the scan or the engine saw it there, or put it there.
    /// Not `remote` -- for a record never sent that is where it will be
    /// created, which clearing a held name moves off the file -- and never a
    /// name naming has planned for it: a name the disk was never given is not
    /// where a file stood (hostile kill2 75111). Written where that is
    /// established, and only there: minting the record from a file found
    /// (`pass::blank`), the scan following the file (`pass` T1-C, the path it
    /// was found at), `pass::follow_its_file`, the engine moving the file
    /// aside (`execute::the_owner_follows_its_file`) and renaming it
    /// (`move_local`, `preserve_local_as`), and a record re-minted from its
    /// agreement (`never_sent_again`) asking where its file stands now. `None`
    /// is not known: a store older than the field, or a file found nowhere.
    pub own_file_seen_at: Option<Placement>,
}

impl Entry {
    /// Where this entry sits **on this computer**.
    ///
    /// The last agreed placement, not the remote one. They differ exactly while
    /// a remote move is known but not yet applied, and reaching for the remote
    /// placement there is a bug with teeth: the file is still at the old path,
    /// so the scanner finds it somewhere its own records say it is not, reads
    /// that as a local move in the opposite direction, and pushes it back. Two
    /// devices then rename the same file at each other forever.
    ///
    /// Falls back to a stand-in directory when nothing has been agreed but a
    /// directory is standing in for a vault folder this device cannot open
    /// (see `stand_in`), and to the remote placement only when there is
    /// neither, where it is not a second opinion but the only one.
    ///
    /// For a folder the user has moved here and not yet agreed, where its
    /// directory stands (`stands_at`). Every question about the disk asks
    /// this; every question about what was agreed asks `agreed_placement`.
    pub fn local_placement(&self) -> &Placement {
        match self.standing_away() {
            Some(s) => &s.here,
            None => self.agreed_placement(),
        }
    }

    /// The last agreed placement, or the stand-in, or the remote placement:
    /// the base every decision about what to tell the server is read
    /// against. Not where a folder the user has just moved stands.
    pub fn agreed_placement(&self) -> &Placement {
        self.synced_placement
            .as_ref()
            .or(self.stand_in.as_ref())
            .unwrap_or(&self.remote)
    }

    /// `stands_at`, while the agreement is still the one it was measured
    /// against.
    pub fn standing_away(&self) -> Option<&StandsAt> {
        self.stands_at
            .as_ref()
            .filter(|s| self.synced_placement.as_ref() == Some(&s.agreed))
    }

    /// The name this entry is materialized under locally.
    ///
    /// A scratch name the engine parked the directory under is where it is:
    /// the park renamed it in place, in the folder it stood in, after the
    /// scan noted where that was. Read from the note instead, a folder parked
    /// mid-swap was looked for under the name it had worn before the park,
    /// which a peer's rotation had since given to another folder.
    pub fn effective_local_name(&self) -> &str {
        match (self.local_name.as_deref(), self.standing_away()) {
            (Some(scratch), _) if jd_vfs::is_internal(scratch) => scratch,
            (_, Some(s)) => &s.here.name,
            _ => self.agreed_local_name(),
        }
    }

    /// The name the agreement gives it on this disk.
    pub fn agreed_local_name(&self) -> &str {
        self.local_name
            .as_deref()
            .unwrap_or(&self.agreed_placement().name)
    }

    /// Wearing a scratch name on the server, with no agreed placement here to
    /// read in its place.
    ///
    /// Some device is in the middle of renaming it, and a scratch name is not
    /// a name: it cannot be judged (it would read as "reserved"), landed
    /// (the local walk hides internal names, so the next pass reads the file
    /// as deleted -- the reset's C11) or planned against. Every reader of
    /// placement skips such an entry until the park ends. One with an
    /// agreement reads the agreement instead (`pass::observed_remote`).
    pub fn waiting_on_a_park(&self) -> bool {
        self.remote.name.starts_with(crate::order::SWAP_PREFIX)
            && self.synced_placement.is_none()
            && self.stand_in.is_none()
            && !self.remote_deleted
    }

    /// The file id of this record's own file on this disk: its own file, or,
    /// for a record that has none yet, the file its agreement names. Files
    /// only -- a folder's directory id is its agreement's fingerprint. `None`
    /// for an unknown id (0).
    pub fn own_file_id(&self) -> Option<u64> {
        if self.id.entity_type != EntityType::File {
            return None;
        }
        self.own_file
            .map(|o| o.file_id)
            .or_else(|| self.synced_fingerprint.map(|f| f.file_id))
            .filter(|id| *id != 0)
    }

    /// Is this file, standing on the disk, this record's own? By the full
    /// identity where both sides have a birth; by the file id where either
    /// does not, which is all a record from before own files can say.
    pub fn owns(&self, here: jd_vfs::FileIdentity) -> bool {
        match self.own_file {
            Some(own) if own.is_strong() && here.is_strong() => own == here,
            _ => self.own_file_id().is_some_and(|id| id == here.file_id),
        }
    }

    /// Is this entry holding a file on this computer, or is it only recording
    /// where one would go? Asked of the record alone; `pass::holds_here` adds
    /// what only the disk can say.
    ///
    /// Three statuses say the device will not be materializing anything new
    /// here: a name the filesystem cannot hold, an encrypted entry whose key
    /// is not here, and a subtree out of scope. What each means about the disk
    /// differs, and the difference is load-bearing. `Unsyncable` is a release:
    /// the park operation gives up the local copy, so nothing of this entry is
    /// at that path. `PendingKey` and `OutOfScope` are not: both say only that
    /// the ENGINE will neither put anything there nor touch what is, so they
    /// hold exactly what they are tied to (`is_tied_here`). Never placed here,
    /// they hold nothing; established here -- a vault opened and then locked,
    /// a vault parked on a weak drive, a stand-in directory -- they hold their
    /// directory or file. Answered from the status alone, a vault locked after
    /// it was open held "nothing" at its own name, and a peer's new folder
    /// under that name took its directory (B-LV).
    ///
    /// The distinction matters wherever one entry asks whether a path belongs
    /// to another. `PendingDownload` deliberately answers yes: those bytes are
    /// on their way to that path, and letting something else take it in the
    /// meantime is how two files end up fighting over one slot.
    pub fn holds_a_local_file(&self) -> bool {
        match self.status {
            LocalStatus::Unsyncable(_) => false,
            LocalStatus::PendingKey | LocalStatus::OutOfScope => self.is_tied_here(),
            _ => true,
        }
    }

    /// Tied to something on this disk: the placement both sides agreed on,
    /// or the directory standing in for a vault this device cannot open.
    /// A record with neither has only been NAMED by the server -- it has
    /// never stood anywhere here -- and contests no directory a tied record
    /// holds, in any reader: the folder scan, the path maps, naming, the
    /// create.
    pub fn is_tied_here(&self) -> bool {
        self.synced_placement.is_some() || self.stand_in.is_some()
    }

    /// Tied here, and the tie still decides: not a record the server has
    /// deleted. A deleted record is on its way out, and a folder the server
    /// has since made under its name is the live one there; outranking it,
    /// the deleted record kept its directory while its trash went through and
    /// the server's folder was made again empty beside it.
    pub fn holds_its_tie(&self) -> bool {
        self.is_tied_here() && !self.remote_deleted
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
