//! The applications, reproduced faithfully enough to break things.
//!
//! Every persona here is a small state machine that emits the *shape* of writes
//! a particular real program makes. None of them are trying to be realistic for
//! its own sake. They exist because the patterns that have historically broken
//! every sync client on the market are not random writes — they are storms with
//! structure: a lock file appearing and vanishing beside the document, a save
//! that is really a write-to-temp and a rename over the top, a delete of the
//! original a fraction of a second before its replacement lands, a download
//! growing for four minutes under a name it will not keep.
//!
//! A persona is a pure function of its own state and its random draw. It emits
//! operations; it never touches a disk. That split is deliberate and it is what
//! makes the harness itself testable — the house rule being that a verifier with
//! a bug in it does not report a problem, it reports a clean run, which is worse
//! than having no verifier at all. Applying the operations, journaling them, and
//! coping with the filesystem saying no is [`crate::actor`]'s job.
//!
//! One persona emits one *burst* per step, not one operation, because the burst
//! is the unit that matters. Office's save is four operations and the bug it
//! hunts is in how they pair up; splitting them across steps with other
//! personas interleaved would test something else.

use crate::rng::Rng;

/// Something a program does to a filesystem.
///
/// Content is a `(seed, size)` pair rather than bytes: an actor generating a
/// megabyte of random data per write on a one-CPU box is a rig that spends its
/// day in the RNG, and a seed lets both the actor and the verifier reconstruct
/// the exact bytes without either of them keeping a copy.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum FsOp {
    Mkdir {
        path: String,
    },
    /// Truncate and write in place — the naive save, and the one that lets a
    /// scanner catch a file halfway.
    Write {
        path: String,
        seed: u64,
        size: usize,
    },
    /// Write a temporary file and rename it over the target: the safe-save
    /// dance, and the reason "same path, new inode" has to read as an edit
    /// rather than as a delete plus a create.
    AtomicWrite {
        path: String,
        temp: String,
        seed: u64,
        size: usize,
    },
    /// Grow a file without rewriting it. The log-appender's whole life, and the
    /// hazard is a file that is never quiet.
    Append {
        path: String,
        seed: u64,
        size: usize,
    },
    Rename {
        from: String,
        to: String,
    },
    /// Exchange two names via a third. A cycle the engine has to break rather
    /// than chase round.
    Swap {
        a: String,
        b: String,
        via: String,
    },
    Remove {
        path: String,
    },
    RemoveDir {
        path: String,
    },
    /// Move a file's mtime, including backwards. Archive extraction does this
    /// for real, and an engine that trusts mtimes reads it as nothing having
    /// changed.
    Touch {
        path: String,
        mtime_delta_secs: i64,
    },
}

impl FsOp {
    /// The name this operation is journaled under.
    pub fn kind(&self) -> &'static str {
        match self {
            FsOp::Mkdir { .. } => "mkdir",
            FsOp::Write { .. } => "write",
            FsOp::AtomicWrite { .. } => "atomic_write",
            FsOp::Append { .. } => "append",
            FsOp::Rename { .. } => "rename",
            FsOp::Swap { .. } => "swap",
            FsOp::Remove { .. } => "remove",
            FsOp::RemoveDir { .. } => "remove_dir",
            FsOp::Touch { .. } => "touch",
        }
    }

    /// The path this operation is *about*, for journaling and for the oracle.
    pub fn subject(&self) -> &str {
        match self {
            FsOp::Mkdir { path }
            | FsOp::Write { path, .. }
            | FsOp::AtomicWrite { path, .. }
            | FsOp::Append { path, .. }
            | FsOp::Remove { path }
            | FsOp::RemoveDir { path }
            | FsOp::Touch { path, .. } => path,
            FsOp::Rename { from, .. } => from,
            FsOp::Swap { a, .. } => a,
        }
    }
}

/// What every persona is.
pub trait Persona: Send {
    fn name(&self) -> &'static str;
    /// One logical action, as the burst of operations it really is.
    fn step(&mut self, rng: &mut Rng) -> Vec<FsOp>;
}

/// Build a persona by name. Unknown names are refused rather than defaulted:
/// a campaign silently running seven personas when its config asked for eight
/// would report a clean run over an adversary that was never there.
pub fn build(name: &str) -> Option<Box<dyn Persona>> {
    match name {
        "office" => Some(Box::new(Office::new())),
        "editor" => Some(Box::new(Editor::new())),
        "photoshop" => Some(Box::new(Photoshop::new())),
        "sqlite-app" => Some(Box::new(SqliteApp::new())),
        "browser" => Some(Box::new(Browser::new())),
        "messy-human" => Some(Box::new(MessyHuman::new())),
        "name-swapper" => Some(Box::new(NameSwapper::new())),
        _ => None,
    }
}

/// The personas Phase A runs on a device. `remote-user` is not here: it drives
/// the server rather than a disk, and lives in [`crate::remote`].
pub const PHASE_A_LOCAL: &[&str] = &[
    "office",
    "editor",
    "photoshop",
    "sqlite-app",
    "browser",
    "messy-human",
    "name-swapper",
];

// ---------------------------------------------------------------------------
// office — the safe-save dance, with a lock file for company
// ---------------------------------------------------------------------------

/// Word's save: drop `~$doc.docx` beside the file, write `tmpNNNN.tmp`, rename
/// it over the original, remove the lock. Then do it again eight seconds later
/// because the user is still typing.
///
/// What it hunts: the temp-rename pairing. If "the file at this path has a new
/// inode" reads as a delete and a create rather than as an edit, every document
/// in the tree loses its version history the first time somebody saves it.
pub struct Office {
    docs: Vec<String>,
    saves: u64,
}

impl Office {
    pub fn new() -> Office {
        Office {
            docs: Vec::new(),
            saves: 0,
        }
    }
}

impl Default for Office {
    fn default() -> Self {
        Office::new()
    }
}

impl Persona for Office {
    fn name(&self) -> &'static str {
        "office"
    }

    fn step(&mut self, rng: &mut Rng) -> Vec<FsOp> {
        if self.docs.is_empty() || rng.chance(15) {
            let name = format!("Report {}.docx", self.docs.len() + 1);
            self.docs.push(name.clone());
            return vec![FsOp::AtomicWrite {
                temp: format!("tmp{:04}.tmp", rng.below(10_000)),
                path: name,
                seed: rng.next_u64(),
                size: rng.range(8_000, 400_000) as usize,
            }];
        }

        let doc = self.docs[rng.below(self.docs.len())].clone();
        let lock = lock_name(&doc, "~$");
        self.saves += 1;
        vec![
            FsOp::Write {
                path: lock.clone(),
                seed: rng.next_u64(),
                size: 162,
            },
            FsOp::AtomicWrite {
                temp: format!("tmp{:04}.tmp", rng.below(10_000)),
                path: doc,
                seed: rng.next_u64(),
                size: rng.range(8_000, 400_000) as usize,
            },
            FsOp::Remove { path: lock },
        ]
    }
}

/// `Report 1.docx` → `~$port 1.docx`, which is what Word actually writes: the
/// prefix replaces the first two characters rather than being prepended. Worth
/// reproducing exactly, because a junk-file rule matching `~$*` catches both and
/// a rule matching `~$` + full name catches only the wrong one.
fn lock_name(doc: &str, prefix: &str) -> String {
    let (dir, base) = split_dir(doc);
    let trimmed: String = base.chars().skip(2).collect();
    join(dir, &format!("{prefix}{trimmed}"))
}

fn split_dir(path: &str) -> (Option<&str>, &str) {
    match path.rfind('/') {
        Some(i) => (Some(&path[..i]), &path[i + 1..]),
        None => (None, path),
    }
}

fn join(dir: Option<&str>, name: &str) -> String {
    match dir {
        Some(d) => format!("{d}/{name}"),
        None => name.to_string(),
    }
}

// ---------------------------------------------------------------------------
// editor — swap files, backups, and a rename storm around one small file
// ---------------------------------------------------------------------------

/// vim and emacs: `.f.swp` created on open and removed on close, `f~` left
/// behind, the write itself atomic. Emacs adds `#f#` autosaves every few
/// seconds.
///
/// What it hunts: rapid create/delete of siblings around a file that itself
/// barely changes. A client that treats every sibling as a real entity spends
/// the day uploading and trashing scratch files, and a mass-delete guard that
/// counts them trips for no reason.
pub struct Editor {
    files: Vec<String>,
    open: Option<String>,
}

impl Editor {
    pub fn new() -> Editor {
        Editor {
            files: Vec::new(),
            open: None,
        }
    }
}

impl Default for Editor {
    fn default() -> Self {
        Editor::new()
    }
}

impl Persona for Editor {
    fn name(&self) -> &'static str {
        "editor"
    }

    fn step(&mut self, rng: &mut Rng) -> Vec<FsOp> {
        match self.open.clone() {
            None => {
                let file = if self.files.is_empty() || rng.chance(20) {
                    let name = format!("notes-{}.txt", self.files.len() + 1);
                    self.files.push(name.clone());
                    name
                } else {
                    self.files[rng.below(self.files.len())].clone()
                };
                self.open = Some(file.clone());
                // Opening: the swap file appears, and nothing else happens yet.
                vec![FsOp::Write {
                    path: swap_name(&file),
                    seed: rng.next_u64(),
                    size: 4096,
                }]
            }
            Some(file) => {
                let mut ops = Vec::new();
                if rng.chance(40) {
                    // An emacs-style autosave, which is a whole extra file
                    // appearing and disappearing beside the one being edited.
                    let auto = hash_name(&file, '#');
                    ops.push(FsOp::Write {
                        path: auto.clone(),
                        seed: rng.next_u64(),
                        size: rng.range(200, 8_000) as usize,
                    });
                    ops.push(FsOp::Remove { path: auto });
                }
                // The save: backup, then an atomic write, then the swap file
                // goes when the buffer is closed.
                ops.push(FsOp::AtomicWrite {
                    temp: format!("{file}.tmp{}", rng.below(1000)),
                    path: file.clone(),
                    seed: rng.next_u64(),
                    size: rng.range(200, 40_000) as usize,
                });
                ops.push(FsOp::Write {
                    path: format!("{file}~"),
                    seed: rng.next_u64(),
                    size: rng.range(200, 40_000) as usize,
                });
                if rng.chance(50) {
                    ops.push(FsOp::Remove {
                        path: swap_name(&file),
                    });
                    self.open = None;
                }
                ops
            }
        }
    }
}

fn swap_name(file: &str) -> String {
    let (dir, base) = split_dir(file);
    join(dir, &format!(".{base}.swp"))
}

fn hash_name(file: &str, mark: char) -> String {
    let (dir, base) = split_dir(file);
    join(dir, &format!("{mark}{base}{mark}"))
}

// ---------------------------------------------------------------------------
// photoshop — delete the original, then put the replacement where it was
// ---------------------------------------------------------------------------

/// Write the new image to a temp name, **remove the original**, then rename the
/// temp into its place. For a moment the file does not exist at all.
///
/// What it hunts: whether a delete followed by a create at the same path stays
/// one entity. If it does not, the file's whole version history on the server is
/// replaced by a brand new file with none, and every other device sees the
/// original deleted — which, if a delete is racing an edit somewhere else, is
/// how the edit gets thrown away.
pub struct Photoshop {
    images: Vec<String>,
}

impl Photoshop {
    pub fn new() -> Photoshop {
        Photoshop { images: Vec::new() }
    }
}

impl Default for Photoshop {
    fn default() -> Self {
        Photoshop::new()
    }
}

impl Persona for Photoshop {
    fn name(&self) -> &'static str {
        "photoshop"
    }

    fn step(&mut self, rng: &mut Rng) -> Vec<FsOp> {
        if self.images.is_empty() || rng.chance(20) {
            let name = format!("shot-{:03}.psd", self.images.len() + 1);
            self.images.push(name.clone());
            return vec![FsOp::Write {
                path: name,
                seed: rng.next_u64(),
                size: rng.range(50_000, 2_000_000) as usize,
            }];
        }
        let image = self.images[rng.below(self.images.len())].clone();
        let temp = format!("{image}.tmp{}", rng.below(10_000));
        vec![
            FsOp::Write {
                path: temp.clone(),
                seed: rng.next_u64(),
                size: rng.range(50_000, 2_000_000) as usize,
            },
            FsOp::Remove {
                path: image.clone(),
            },
            FsOp::Rename {
                from: temp,
                to: image,
            },
        ]
    }
}

// ---------------------------------------------------------------------------
// sqlite-app — a file that is never quiet
// ---------------------------------------------------------------------------

/// A database and its journal, written continuously in small transactions.
///
/// What it hunts: two things at once. A file that never goes quiet must not
/// stall the rest of the tree waiting for it, and a snapshot taken mid-write
/// must never be uploaded as if it were a commit — a half-written database that
/// syncs to every other device is a corrupted database everywhere.
pub struct SqliteApp {
    txn: u64,
}

impl SqliteApp {
    pub fn new() -> SqliteApp {
        SqliteApp { txn: 0 }
    }
}

impl Default for SqliteApp {
    fn default() -> Self {
        SqliteApp::new()
    }
}

impl Persona for SqliteApp {
    fn name(&self) -> &'static str {
        "sqlite-app"
    }

    fn step(&mut self, rng: &mut Rng) -> Vec<FsOp> {
        self.txn += 1;
        let mut ops = vec![
            FsOp::Append {
                path: "app.db-wal".into(),
                seed: rng.next_u64(),
                size: rng.range(512, 8_192) as usize,
            },
            FsOp::Write {
                path: "app.db-shm".into(),
                seed: rng.next_u64(),
                size: 32_768,
            },
        ];
        // Every so often the WAL is folded back into the database and reset,
        // which rewrites the main file and truncates the journal.
        if self.txn.is_multiple_of(8) {
            ops.push(FsOp::Write {
                path: "app.db".into(),
                seed: rng.next_u64(),
                size: rng.range(64_000, 900_000) as usize,
            });
            ops.push(FsOp::Write {
                path: "app.db-wal".into(),
                seed: rng.next_u64(),
                size: 0,
            });
        }
        ops
    }
}

// ---------------------------------------------------------------------------
// browser — a file that grows for minutes under a name it will not keep
// ---------------------------------------------------------------------------

/// A download: `thing.zip.crdownload` grows in chunks, then is renamed to
/// `thing.zip`. Sometimes the user cancels and it is simply deleted.
///
/// What it hunts: the growing-file stability check. A client that uploads a
/// partial download has moved bytes for nothing and shown the user a corrupt
/// file on their other machine; a client that never decides it has stopped
/// growing never uploads it at all. And the abandoned ones must not accumulate
/// as tracked entities forever.
pub struct Browser {
    downloading: Option<(String, u32)>,
    completed: u32,
}

impl Browser {
    pub fn new() -> Browser {
        Browser {
            downloading: None,
            completed: 0,
        }
    }
}

impl Default for Browser {
    fn default() -> Self {
        Browser::new()
    }
}

impl Persona for Browser {
    fn name(&self) -> &'static str {
        "browser"
    }

    fn step(&mut self, rng: &mut Rng) -> Vec<FsOp> {
        match self.downloading.clone() {
            None => {
                self.completed += 1;
                let target = format!("download-{:03}.zip", self.completed);
                self.downloading = Some((target.clone(), 0));
                vec![FsOp::Write {
                    path: format!("{target}.crdownload"),
                    seed: rng.next_u64(),
                    size: rng.range(4_000, 60_000) as usize,
                }]
            }
            Some((target, chunks)) => {
                let partial = format!("{target}.crdownload");
                // Abandoned partway: the user pressed cancel, and the partial
                // file is deleted without ever becoming anything.
                if chunks >= 2 && rng.chance(15) {
                    self.downloading = None;
                    return vec![FsOp::Remove { path: partial }];
                }
                if chunks >= 3 && rng.chance(45) {
                    self.downloading = None;
                    return vec![FsOp::Rename {
                        from: partial,
                        to: target,
                    }];
                }
                self.downloading = Some((target, chunks + 1));
                vec![FsOp::Append {
                    path: partial,
                    seed: rng.next_u64(),
                    size: rng.range(20_000, 400_000) as usize,
                }]
            }
        }
    }
}

// ---------------------------------------------------------------------------
// messy-human — the one that breaks move detection
// ---------------------------------------------------------------------------

/// A person using their computer: renaming a folder while files inside it are
/// still being written, dragging a subtree somewhere else, making "Copy of"
/// duplicates, changing only the capitalization of a name, and using names with
/// accents, emoji and CJK in them.
///
/// What it hunts: move detection under concurrency, and name intelligence on a
/// real filesystem rather than a simulated one. A folder rename that arrives
/// while its children are mid-upload is where a client either moves a thousand
/// files or re-uploads a thousand files.
pub struct MessyHuman {
    folders: Vec<String>,
    files: Vec<String>,
    made: u64,
}

/// Names chosen to be awkward in the specific ways filesystems are awkward:
/// composed and decomposed accents, an emoji outside the basic plane, CJK, and
/// a name that is legal here and a case clash on a Windows or macOS volume.
const AWKWARD: &[&str] = &[
    "cafe\u{0301} notes.txt", // decomposed e-acute
    "caf\u{00e9} notes.txt",  // composed — the same name to a Mac, not to Linux
    "\u{1f4c1} plans.txt",    // emoji
    "\u{6587}\u{4ef6}.txt",   // CJK
    "README.txt",
    "readme.txt", // a case-only twin of the line above
];

impl MessyHuman {
    pub fn new() -> MessyHuman {
        MessyHuman {
            folders: Vec::new(),
            files: Vec::new(),
            made: 0,
        }
    }
}

impl Default for MessyHuman {
    fn default() -> Self {
        MessyHuman::new()
    }
}

impl Persona for MessyHuman {
    fn name(&self) -> &'static str {
        "messy-human"
    }

    fn step(&mut self, rng: &mut Rng) -> Vec<FsOp> {
        if self.folders.is_empty() {
            self.folders.push("Projects".into());
            return vec![FsOp::Mkdir {
                path: "Projects".into(),
            }];
        }

        match rng.below(6) {
            // Write a few files into a folder, using awkward names on purpose.
            0 | 1 => {
                let folder = self.folders[rng.below(self.folders.len())].clone();
                let mut ops = Vec::new();
                for _ in 0..rng.range(1, 4) {
                    self.made += 1;
                    let name = if rng.chance(35) {
                        format!("{}-{}", self.made, AWKWARD[rng.below(AWKWARD.len())])
                    } else {
                        format!("doc-{}.txt", self.made)
                    };
                    let path = format!("{folder}/{name}");
                    self.files.push(path.clone());
                    ops.push(FsOp::Write {
                        path,
                        seed: rng.next_u64(),
                        size: rng.range(100, 60_000) as usize,
                    });
                }
                ops
            }
            // Rename a folder — while, in the same burst, still writing into it.
            // The child write is emitted first under the OLD name on purpose: by
            // the time the daemon looks, the folder has moved underneath it.
            2 => {
                let old = self.folders[rng.below(self.folders.len())].clone();
                self.made += 1;
                let child = format!("{old}/in-flight-{}.txt", self.made);
                let new = format!("{old} ({})", self.made);
                for f in self.files.iter_mut() {
                    if let Some(rest) = f.strip_prefix(&format!("{old}/")) {
                        *f = format!("{new}/{rest}");
                    }
                }
                self.files
                    .push(format!("{new}/in-flight-{}.txt", self.made));
                for f in self.folders.iter_mut() {
                    if *f == old {
                        *f = new.clone();
                    }
                }
                vec![
                    FsOp::Write {
                        path: child,
                        seed: rng.next_u64(),
                        size: rng.range(100, 20_000) as usize,
                    },
                    FsOp::Rename { from: old, to: new },
                ]
            }
            // A new subfolder, and drag a file into it.
            3 => {
                let parent = self.folders[rng.below(self.folders.len())].clone();
                self.made += 1;
                let sub = format!("{parent}/Sub {}", self.made);
                self.folders.push(sub.clone());
                let mut ops = vec![FsOp::Mkdir { path: sub.clone() }];
                if !self.files.is_empty() {
                    let i = rng.below(self.files.len());
                    let from = self.files[i].clone();
                    let base = split_dir(&from).1.to_string();
                    let to = format!("{sub}/{base}");
                    self.files[i] = to.clone();
                    ops.push(FsOp::Rename { from, to });
                }
                ops
            }
            // "Copy of" — a duplicate with the same content under a new name,
            // which the server should recognize as content it already holds.
            4 => {
                if self.files.is_empty() {
                    return Vec::new();
                }
                let source = self.files[rng.below(self.files.len())].clone();
                let (dir, base) = split_dir(&source);
                let copy = join(dir, &format!("Copy of {base}"));
                self.files.push(copy.clone());
                vec![FsOp::Write {
                    path: copy,
                    seed: rng.next_u64(),
                    size: rng.range(100, 60_000) as usize,
                }]
            }
            // A case-only rename, which on a case-insensitive volume is a rename
            // onto itself and on this one is a different file entirely.
            _ => {
                if self.files.is_empty() {
                    return Vec::new();
                }
                let i = rng.below(self.files.len());
                let from = self.files[i].clone();
                let (dir, base) = split_dir(&from);
                let flipped: String = base
                    .chars()
                    .map(|c| {
                        if c.is_lowercase() {
                            c.to_uppercase().next().unwrap_or(c)
                        } else {
                            c.to_lowercase().next().unwrap_or(c)
                        }
                    })
                    .collect();
                let to = join(dir, &flipped);
                if to == from {
                    return Vec::new();
                }
                self.files[i] = to.clone();
                vec![FsOp::Rename { from, to }]
            }
        }
    }
}

// ---------------------------------------------------------------------------
// name-swapper — the cycle
// ---------------------------------------------------------------------------

/// Exchange two files' names, and occasionally rotate three of them.
///
/// What it hunts: cycle breaking. A→B and B→A cannot both be applied in either
/// order without a temporary name, and an engine that tries lands one file on
/// top of the other. The three-way rotation is the same trap with a longer
/// cycle, which is where an implementation that special-cased pairs falls over.
pub struct NameSwapper {
    names: Vec<String>,
    round: u64,
}

impl NameSwapper {
    pub fn new() -> NameSwapper {
        NameSwapper {
            names: Vec::new(),
            round: 0,
        }
    }
}

impl Default for NameSwapper {
    fn default() -> Self {
        NameSwapper::new()
    }
}

impl Persona for NameSwapper {
    fn name(&self) -> &'static str {
        "name-swapper"
    }

    fn step(&mut self, rng: &mut Rng) -> Vec<FsOp> {
        if self.names.len() < 3 {
            let name = format!("slot-{}.dat", self.names.len() + 1);
            self.names.push(name.clone());
            return vec![FsOp::Write {
                path: name,
                seed: rng.next_u64(),
                size: rng.range(1_000, 100_000) as usize,
            }];
        }
        self.round += 1;
        if rng.chance(30) {
            // Three-way rotation: a→tmp, b→a, c→b, tmp→c.
            let (a, b, c) = (
                self.names[0].clone(),
                self.names[1].clone(),
                self.names[2].clone(),
            );
            let via = format!(".rotate-{}.tmp", self.round);
            return vec![
                FsOp::Rename {
                    from: a.clone(),
                    to: via.clone(),
                },
                FsOp::Rename {
                    from: b.clone(),
                    to: a,
                },
                FsOp::Rename {
                    from: c.clone(),
                    to: b,
                },
                FsOp::Rename { from: via, to: c },
            ];
        }
        let i = rng.below(self.names.len());
        let j = (i + 1 + rng.below(self.names.len() - 1)) % self.names.len();
        vec![FsOp::Swap {
            a: self.names[i].clone(),
            b: self.names[j].clone(),
            via: format!(".swap-{}.tmp", self.round),
        }]
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// Run a persona for a while and collect everything it emitted.
    fn drive(persona: &mut dyn Persona, steps: usize, seed: u64) -> Vec<FsOp> {
        let mut rng = Rng::new(seed);
        let mut out = Vec::new();
        for _ in 0..steps {
            out.extend(persona.step(&mut rng));
        }
        out
    }

    #[test]
    fn every_phase_a_persona_can_be_built_by_name() {
        // A campaign configured for eight personas and silently running seven
        // would report a clean run over an adversary that was never there.
        for name in PHASE_A_LOCAL {
            let built = build(name).unwrap_or_else(|| panic!("{name} is not buildable"));
            assert_eq!(&built.name(), name);
        }
        assert!(build("not-a-persona").is_none());
    }

    #[test]
    fn a_persona_is_reproducible_from_its_seed() {
        // Not determinism of the rig — that is impossible against a real kernel
        // — but of the decisions, so a bundle can say "seed 41029, op 317".
        for name in PHASE_A_LOCAL {
            let one = drive(&mut *build(name).unwrap(), 60, 41_029);
            let two = drive(&mut *build(name).unwrap(), 60, 41_029);
            assert_eq!(one, two, "{name} did not reproduce its own decisions");
        }
    }

    #[test]
    fn a_different_seed_produces_a_different_run() {
        let one = drive(&mut Office::new(), 80, 1);
        let two = drive(&mut Office::new(), 80, 2);
        assert_ne!(one, two);
    }

    #[test]
    fn no_persona_ever_emits_an_absolute_or_escaping_path() {
        // Every path is relative to the actor's own workspace. One that escaped
        // would have the rig writing outside the tree under test — including,
        // potentially, over its own journal.
        for name in PHASE_A_LOCAL {
            for op in drive(&mut *build(name).unwrap(), 200, 7) {
                for path in paths_of(&op) {
                    assert!(!path.starts_with('/'), "{name}: absolute path {path}");
                    assert!(!path.contains(".."), "{name}: escaping path {path}");
                    assert!(!path.is_empty(), "{name}: empty path");
                }
            }
        }
    }

    #[test]
    fn no_persona_uses_the_clients_own_reserved_prefix() {
        // `.jd-` is the sync client's own temporary-file prefix. A persona using
        // it would be testing the engine's reserved-name refusal by accident,
        // and every such file would be reported unsyncable — which reads as a
        // rig full of issues rather than as a rig with a naming bug.
        for name in PHASE_A_LOCAL {
            for op in drive(&mut *build(name).unwrap(), 200, 3) {
                for path in paths_of(&op) {
                    for component in path.split('/') {
                        assert!(
                            !component.starts_with(".jd-"),
                            "{name} emitted the client's reserved prefix: {path}"
                        );
                    }
                }
            }
        }
    }

    fn paths_of(op: &FsOp) -> Vec<String> {
        match op {
            FsOp::Mkdir { path }
            | FsOp::Write { path, .. }
            | FsOp::Append { path, .. }
            | FsOp::Remove { path }
            | FsOp::RemoveDir { path }
            | FsOp::Touch { path, .. } => vec![path.clone()],
            FsOp::AtomicWrite { path, temp, .. } => vec![path.clone(), temp.clone()],
            FsOp::Rename { from, to } => vec![from.clone(), to.clone()],
            FsOp::Swap { a, b, via } => vec![a.clone(), b.clone(), via.clone()],
        }
    }

    #[test]
    fn office_saves_through_a_temp_and_a_rename_and_never_in_place() {
        // The whole point of this persona. A save that truncated the document in
        // place would be testing a pattern Word does not have.
        let ops = drive(&mut Office::new(), 60, 11);
        let saves: Vec<&FsOp> = ops
            .iter()
            .filter(|o| matches!(o, FsOp::AtomicWrite { path, .. } if path.ends_with(".docx")))
            .collect();
        assert!(saves.len() > 5, "office barely saved anything");
        assert!(
            !ops.iter().any(|o| matches!(
                o,
                // The lock file is also spelled `.docx` and IS written in
                // place — that is exactly what Word does with it.
                FsOp::Write { path, .. } if path.ends_with(".docx") && !path.contains("~$")
            )),
            "a document was written in place, which Word never does"
        );
    }

    #[test]
    fn office_pairs_every_lock_file_with_its_removal() {
        // A lock file left behind forever would drift the tree upward on every
        // save and make the leak watch fire on the rig's own mess.
        let ops = drive(&mut Office::new(), 200, 13);
        let created: Vec<&str> = ops
            .iter()
            .filter_map(|o| match o {
                FsOp::Write { path, .. } if path.contains("~$") => Some(path.as_str()),
                _ => None,
            })
            .collect();
        let removed: Vec<&str> = ops
            .iter()
            .filter_map(|o| match o {
                FsOp::Remove { path } if path.contains("~$") => Some(path.as_str()),
                _ => None,
            })
            .collect();
        assert!(!created.is_empty());
        assert_eq!(created.len(), removed.len());
    }

    #[test]
    fn the_office_lock_name_is_the_one_word_actually_writes() {
        // `~$` replaces the first two characters rather than being prepended. A
        // junk rule written against the wrong spelling would match nothing.
        assert_eq!(lock_name("Report 1.docx", "~$"), "~$port 1.docx");
        assert_eq!(
            lock_name("Projects/Report 1.docx", "~$"),
            "Projects/~$port 1.docx"
        );
    }

    #[test]
    fn photoshop_deletes_the_original_before_the_replacement_lands() {
        // The ordering is the hazard: for an instant the path holds nothing at
        // all, and a scan landing there must not conclude the file was deleted.
        let ops = drive(&mut Photoshop::new(), 60, 17);
        let mut saw_pattern = false;
        for window in ops.windows(3) {
            if let (
                FsOp::Write { path: temp, .. },
                FsOp::Remove { path: gone },
                FsOp::Rename { from, to },
            ) = (&window[0], &window[1], &window[2])
            {
                if temp == from && gone == to {
                    saw_pattern = true;
                }
            }
        }
        assert!(
            saw_pattern,
            "photoshop never performed its delete-then-rename"
        );
    }

    #[test]
    fn the_browser_either_finishes_a_download_or_abandons_it() {
        // Both endings matter: the rename is the happy path, and the abandoned
        // partial is what must not linger as a tracked entity forever.
        let ops = drive(&mut Browser::new(), 400, 19);
        let finished = ops
            .iter()
            .any(|o| matches!(o, FsOp::Rename { from, .. } if from.ends_with(".crdownload")));
        let abandoned = ops
            .iter()
            .any(|o| matches!(o, FsOp::Remove { path } if path.ends_with(".crdownload")));
        assert!(finished, "no download ever completed");
        assert!(abandoned, "no download was ever cancelled");
    }

    #[test]
    fn the_browser_grows_a_partial_before_it_renames_it() {
        // A download that appeared at full size in one step would never exercise
        // the growing-file stability check, which is the thing this persona is
        // for.
        let ops = drive(&mut Browser::new(), 400, 23);
        assert!(ops
            .iter()
            .any(|o| matches!(o, FsOp::Append { path, .. } if path.ends_with(".crdownload"))));
    }

    #[test]
    fn the_database_is_never_quiet_and_the_journal_is_folded_back_in() {
        let ops = drive(&mut SqliteApp::new(), 40, 29);
        assert!(ops
            .iter()
            .any(|o| matches!(o, FsOp::Append { path, .. } if path == "app.db-wal")));
        assert!(ops
            .iter()
            .any(|o| matches!(o, FsOp::Write { path, size: 0, .. } if path == "app.db-wal")));
        assert!(ops
            .iter()
            .any(|o| matches!(o, FsOp::Write { path, .. } if path == "app.db")));
    }

    #[test]
    fn the_messy_human_renames_a_folder_while_still_writing_into_it() {
        // The burst has to keep this order — the child write under the old name,
        // then the rename — or the race it is built to cause never happens.
        let mut human = MessyHuman::new();
        let mut rng = Rng::new(31);
        let mut saw = false;
        for _ in 0..400 {
            let burst = human.step(&mut rng);
            if burst.len() == 2 {
                if let (FsOp::Write { path, .. }, FsOp::Rename { from, .. }) =
                    (&burst[0], &burst[1])
                {
                    if path.starts_with(&format!("{from}/")) {
                        saw = true;
                    }
                }
            }
        }
        assert!(saw, "no folder was renamed out from under a live write");
    }

    #[test]
    fn the_messy_human_uses_names_real_filesystems_argue_about() {
        let ops = drive(&mut MessyHuman::new(), 400, 37);
        let names: String = ops.iter().flat_map(paths_of).collect::<Vec<_>>().join("|");
        assert!(names.contains('\u{1f4c1}') || names.contains('\u{6587}'));
        assert!(names.contains("cafe\u{0301}") || names.contains("caf\u{00e9}"));
        assert!(names.contains("Copy of "));
    }

    #[test]
    fn a_case_only_rename_never_renames_a_name_onto_itself() {
        // On a case-insensitive volume that is a no-op the engine should not see
        // at all; emitting it would have the actor journal a rename that never
        // happened.
        for seed in 0..40 {
            for op in drive(&mut MessyHuman::new(), 200, seed) {
                if let FsOp::Rename { from, to } = &op {
                    assert_ne!(from, to);
                }
            }
        }
    }

    #[test]
    fn the_name_swapper_always_goes_through_a_third_name() {
        // A→B and B→A cannot both be applied in either order. An engine that
        // tries lands one file on top of the other, and this persona must
        // actually present that cycle rather than a pair of safe renames.
        let ops = drive(&mut NameSwapper::new(), 200, 41);
        let swaps: Vec<&FsOp> = ops
            .iter()
            .filter(|o| matches!(o, FsOp::Swap { .. }))
            .collect();
        assert!(!swaps.is_empty());
        for op in swaps {
            if let FsOp::Swap { a, b, via } = op {
                assert_ne!(a, b, "a swap of a name with itself is not a cycle");
                assert!(via.ends_with(".tmp"));
            }
        }
    }

    #[test]
    fn the_name_swapper_also_rotates_three_names() {
        // The same trap with a longer cycle, which is where an implementation
        // that special-cased pairs falls over.
        let ops = drive(&mut NameSwapper::new(), 400, 43);
        let mut rotations = 0;
        for window in ops.windows(4) {
            if window.iter().all(|o| matches!(o, FsOp::Rename { .. })) {
                if let (FsOp::Rename { to: via, .. }, FsOp::Rename { from: last, .. }) =
                    (&window[0], &window[3])
                {
                    if via == last {
                        rotations += 1;
                    }
                }
            }
        }
        assert!(rotations > 0, "no three-way rotation was ever emitted");
    }

    #[test]
    fn every_persona_does_something_on_almost_every_step() {
        // A persona that mostly emits nothing is a quiet corner of the rig
        // pretending to be an adversary.
        for name in PHASE_A_LOCAL {
            let mut persona = build(name).unwrap();
            let mut rng = Rng::new(53);
            let mut empty = 0;
            for _ in 0..200 {
                if persona.step(&mut rng).is_empty() {
                    empty += 1;
                }
            }
            assert!(empty < 40, "{name} did nothing on {empty} of 200 steps");
        }
    }
}
