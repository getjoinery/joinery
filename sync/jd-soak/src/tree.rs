//! Walking a device's disk, and diffing it against the server.
//!
//! The one rule that makes this correct rather than merely strict: **names are
//! compared through the volume's own rules, not byte for byte**. A device on a
//! case-insensitive volume that stores `Report.txt` where the server says
//! `report.txt` is obeying its own disk, and failing it for that would fill
//! every settle with findings about nothing. `jd-vfs`'s comparison key is the
//! same function the engine itself uses to decide what "the same name" means,
//! which is what stops the verifier from holding the client to a standard the
//! client was never designed to meet.
//!
//! What the walk deliberately does **not** do is trust the daemon about
//! anything. It reads the disk with `std::fs`, hashes what it finds, and forms
//! its own opinion. The daemon's state store is never opened.

use std::collections::{BTreeMap, BTreeSet};
use std::path::Path;

use jd_vfs::{comparison_key, is_internal, Personality};

use crate::actor::hash_file;
use crate::fleet::Device;
use crate::server::ServerTree;

/// One thing found on a disk.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Local {
    /// The path as the filesystem spells it, relative to the sync root.
    pub path: String,
    pub is_dir: bool,
    pub sha256: Option<String>,
    pub size: u64,
}

/// Everything on one device's disk, keyed by comparison path.
#[derive(Debug, Clone, Default)]
pub struct LocalTree {
    pub entries: BTreeMap<String, Local>,
}

impl LocalTree {
    pub fn contents(&self) -> BTreeSet<String> {
        self.entries
            .values()
            .filter_map(|e| e.sha256.clone())
            .collect()
    }

    pub fn holds(&self, sha256: &str) -> bool {
        self.entries
            .values()
            .any(|e| e.sha256.as_deref() == Some(sha256))
    }
}

/// The comparison form of a path: every component through the volume's rules.
pub fn key_for(path: &str, personality: &Personality) -> String {
    path.split('/')
        .map(|c| comparison_key(c, personality))
        .collect::<Vec<_>>()
        .join("/")
}

/// Walk a sync root.
///
/// Files are hashed. That is real work — a hundred thousand files a settle — and
/// it is the price of assertion 2 meaning anything: a size-and-mtime comparison
/// would agree with a daemon that had written the wrong bytes into the right
/// place, which is precisely the silent corruption the rig is here to find.
pub fn walk_local(root: &Path, personality: &Personality) -> std::io::Result<LocalTree> {
    let mut tree = LocalTree::default();
    if !root.exists() {
        return Ok(tree);
    }
    walk_into(root, root, personality, &mut tree)?;
    Ok(tree)
}

fn walk_into(
    root: &Path,
    dir: &Path,
    personality: &Personality,
    tree: &mut LocalTree,
) -> std::io::Result<()> {
    for entry in std::fs::read_dir(dir)? {
        let entry = entry?;
        let name = entry.file_name().to_string_lossy().to_string();

        // The client's own scratch files, and the freedesktop trash a delete
        // lands in. Neither is part of the tree the two sides are meant to
        // agree about, and counting them would fail every settle that followed
        // a delete.
        if is_internal(&name) || name.starts_with(".Trash-") || name == ".Trash" {
            continue;
        }

        let path = entry.path();
        let relative = path
            .strip_prefix(root)
            .unwrap_or(&path)
            .to_string_lossy()
            .replace('\\', "/");
        let meta = match entry.metadata() {
            Ok(m) => m,
            // A file that vanished between the readdir and the stat. During a
            // settle the actors are quiet, so this is rare; it is not an error,
            // it is a file that is no longer there.
            Err(_) => continue,
        };

        if meta.is_dir() {
            tree.entries.insert(
                key_for(&relative, personality),
                Local {
                    path: relative,
                    is_dir: true,
                    sha256: None,
                    size: 0,
                },
            );
            walk_into(root, &path, personality, tree)?;
        } else if meta.is_file() {
            let (sha, size) = match hash_file(&path) {
                Ok(pair) => pair,
                Err(_) => continue,
            };
            tree.entries.insert(
                key_for(&relative, personality),
                Local {
                    path: relative,
                    is_dir: false,
                    sha256: Some(sha),
                    size,
                },
            );
        }
        // Symlinks and devices are skipped: the engine marks them unsyncable
        // and says so, and nothing in this rig creates one.
    }
    Ok(())
}

/// One way in which a device and the server disagree.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Difference {
    /// On the device, absent from the server.
    OnlyLocal { path: String },
    /// On the server, absent from the device.
    OnlyRemote { path: String },
    /// Both have it, and the bytes differ. The one that matters.
    ContentDiffers {
        path: String,
        local: String,
        remote: String,
    },
    /// One says folder, the other says file.
    KindDiffers { path: String },
}

impl Difference {
    pub fn describe(&self) -> String {
        match self {
            Difference::OnlyLocal { path } => {
                format!("{path} is on the device and not on the server")
            }
            Difference::OnlyRemote { path } => {
                format!("{path} is on the server and not on the device")
            }
            Difference::ContentDiffers {
                path,
                local,
                remote,
            } => format!(
                "{path} differs: device {} vs server {}",
                short(local),
                short(remote)
            ),
            Difference::KindDiffers { path } => {
                format!("{path} is a folder on one side and a file on the other")
            }
        }
    }
}

fn short(sha: &str) -> &str {
    &sha[..std::cmp::min(12, sha.len())]
}

/// Diff a device against the server.
///
/// `excluded` are subtrees the device was told not to sync, as ordinary paths —
/// they come straight off the daemon's config. They are the user's own choice
/// and are not disagreements. Converted to comparison form here so a caller
/// never has to know that they must be.
pub fn diff(
    local: &LocalTree,
    server: &ServerTree,
    personality: &Personality,
    excluded: &[String],
) -> Vec<Difference> {
    let excluded: Vec<String> = excluded
        .iter()
        .map(|p| key_for(p.trim_end_matches('/'), personality))
        .collect();
    // Grouped by the name this volume compares them under. Two server names
    // that differ only in case (or in normal form) are one name on a volume
    // that folds them: a device can hold one of them, and holds the others as
    // unsyncable clashes, which the engine reports and `issues-honest` checks.
    // Keyed to one entry, the later name stood in for both: the device was
    // judged against the other file's bytes, and the name it could not hold
    // was never asked about (Mac soak run 40).
    let mut remote: BTreeMap<String, Vec<(bool, Option<String>, String)>> = BTreeMap::new();
    for (path, e) in server.live_paths() {
        remote
            .entry(key_for(&path, personality))
            .or_default()
            .push((e.is_folder, e.sha256.clone(), path));
    }

    let is_excluded = |key: &str| {
        excluded
            .iter()
            .any(|prefix| key == prefix.as_str() || key.starts_with(&format!("{prefix}/")))
    };

    let mut out = Vec::new();
    for (key, entry) in &local.entries {
        if is_excluded(key) {
            continue;
        }
        let Some(members) = remote.get(key) else {
            out.push(Difference::OnlyLocal {
                path: entry.path.clone(),
            });
            continue;
        };
        // The device agrees when what stands at the name is ANY member's: the
        // same kind and, for a file, that member's bytes. A folding disk's
        // spelling is not an identity -- the user may case-rename the held
        // file, and the server compares case-sensitively -- so the exact tie
        // between a record, its bytes and the clash it keeps is checked from
        // the device's store (`verify::clash_findings`). A server file with
        // no content hash at all is not a disagreement about content -- there
        // is nothing to disagree with; it is reported by the walk.
        let agrees = members.iter().any(|(is_folder, sha, _)| {
            *is_folder == entry.is_dir
                && (entry.is_dir
                    || match (entry.sha256.as_ref(), sha.as_ref()) {
                        (Some(l), Some(r)) => l == r,
                        _ => true,
                    })
        });
        if agrees {
            continue;
        }
        let same_kind = members.iter().find(|(is_folder, _, _)| *is_folder == entry.is_dir);
        match (same_kind, entry.sha256.as_ref()) {
            (Some((_, Some(r), _)), Some(l)) => out.push(Difference::ContentDiffers {
                path: entry.path.clone(),
                local: l.clone(),
                remote: r.clone(),
            }),
            _ => out.push(Difference::KindDiffers {
                path: entry.path.clone(),
            }),
        }
    }
    for (key, members) in &remote {
        if is_excluded(key) {
            continue;
        }
        if !local.entries.contains_key(key) {
            for (_, _, path) in members {
                out.push(Difference::OnlyRemote { path: path.clone() });
            }
        }
    }
    out
}

/// Contents sitting in a local OS trash, which is a legitimate place for the
/// last copy of something the engine decided to remove.
///
/// The engine never unlinks — a delete it got wrong has to be recoverable by the
/// person it happened to — so the trash is part of the answer to "where did this
/// file go", not evidence that it is gone.
/// Everything recoverable from the trash of one device.
///
/// Takes the device rather than a path because there are two directories here
/// that both get called "home", and the verifier reached for the wrong one for
/// the whole life of the rig: `home` is `JOINERY_DRIVE_HOME` — config, state,
/// spool — while the daemon runs as its own unix account and trashes into
/// *that* account's freedesktop trash. Nothing is ever trashed under the drive
/// home, so the search found an empty set every time and every correctly
/// trashed file was reported permanently lost.
pub fn device_trash_contents(device: &Device) -> BTreeSet<String> {
    trash_contents(&device.trash_home(), &[device.root.as_path()])
}

pub fn trash_contents(home: &Path, roots: &[&Path]) -> BTreeSet<String> {
    // The freedesktop trash, and macOS's, which is `~/.Trash` with no
    // `files/` beneath it.
    let mut places = vec![home.join(".local/share/Trash/files"), home.join(".Trash")];
    if let Ok(data_home) = std::env::var("XDG_DATA_HOME") {
        places.push(Path::new(&data_home).join("Trash/files"));
    }
    // A volume that is not the home volume gets its own top-level trash.
    for root in roots {
        if let Some(parent) = root.parent() {
            for entry in std::fs::read_dir(parent).into_iter().flatten().flatten() {
                let name = entry.file_name().to_string_lossy().to_string();
                if name.starts_with(".Trash-") || name == ".Trash" {
                    places.push(entry.path().join("files"));
                    places.push(entry.path());
                }
            }
        }
        places.push(root.join(".Trash"));
    }

    let mut out = BTreeSet::new();
    for place in places {
        collect_hashes(&place, &mut out);
    }
    out
}

fn collect_hashes(dir: &Path, out: &mut BTreeSet<String>) {
    let Ok(entries) = std::fs::read_dir(dir) else {
        return;
    };
    for entry in entries.flatten() {
        let path = entry.path();
        match entry.metadata() {
            Ok(m) if m.is_dir() => collect_hashes(&path, out),
            Ok(m) if m.is_file() => {
                if let Ok((sha, _)) = hash_file(&path) {
                    out.insert(sha);
                }
            }
            _ => {}
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::server::Entity;
    use std::path::PathBuf;

    struct Bed(PathBuf);

    impl Bed {
        fn new(tag: &str) -> Bed {
            let dir = std::env::temp_dir().join(format!(
                "jd-soak-tree-{}-{}-{:?}",
                tag,
                std::process::id(),
                std::thread::current().id()
            ));
            let _ = std::fs::remove_dir_all(&dir);
            std::fs::create_dir_all(&dir).unwrap();
            Bed(dir)
        }
        fn write(&self, relative: &str, body: &str) {
            let p = self.0.join(relative);
            std::fs::create_dir_all(p.parent().unwrap()).unwrap();
            std::fs::write(p, body).unwrap();
        }
        fn mkdir(&self, relative: &str) {
            std::fs::create_dir_all(self.0.join(relative)).unwrap();
        }
    }

    impl Drop for Bed {
        fn drop(&mut self) {
            let _ = std::fs::remove_dir_all(&self.0);
        }
    }

    fn server(entries: Vec<Entity>) -> ServerTree {
        let mut tree = ServerTree::default();
        for e in entries {
            if e.is_folder {
                tree.folders.insert(e.id, e);
            } else {
                tree.files.insert(e.id, e);
            }
        }
        tree
    }

    fn folder(id: i64, name: &str, parent: Option<i64>) -> Entity {
        Entity {
            id,
            is_folder: true,
            name: name.into(),
            parent_id: parent,
            deleted: false,
            encrypted: false,
            sha256: None,
            size: 0,
        }
    }

    fn file(id: i64, name: &str, parent: Option<i64>, sha: &str) -> Entity {
        Entity {
            id,
            is_folder: false,
            name: name.into(),
            parent_id: parent,
            deleted: false,
            encrypted: false,
            sha256: Some(sha.into()),
            size: 3,
        }
    }

    fn sha_of(body: &str) -> String {
        crate::actor::hash_bytes(body.as_bytes())
    }

    #[test]
    fn a_walk_finds_files_and_folders_and_hashes_the_files() {
        let bed = Bed::new("walk");
        bed.write("Projects/a.txt", "abc");
        bed.mkdir("Empty");
        let tree = walk_local(&bed.0, &Personality::linux()).unwrap();
        assert!(tree.entries["Empty"].is_dir);
        assert_eq!(tree.entries["Projects/a.txt"].sha256, Some(sha_of("abc")));
        assert!(tree.holds(&sha_of("abc")));
    }

    #[test]
    fn the_clients_own_scratch_files_and_the_trash_are_not_part_of_the_tree() {
        // Counting them would fail every settle that followed a delete, and
        // report the client's own spool as a difference.
        let bed = Bed::new("internal");
        bed.write(".jd-spool-1", "x");
        bed.write(".Trash-1000/files/gone.txt", "y");
        bed.write("real.txt", "z");
        let tree = walk_local(&bed.0, &Personality::linux()).unwrap();
        assert_eq!(tree.entries.len(), 1);
        assert!(tree.entries.contains_key("real.txt"));
    }

    #[test]
    fn a_device_is_not_failed_for_obeying_its_own_volumes_naming_rules() {
        // The single most important property of this differ. On a
        // case-insensitive volume `Report.txt` and `report.txt` are the same
        // name, and holding the client to a byte comparison would fill every
        // settle with findings about nothing.
        let bed = Bed::new("case");
        bed.write("Report.txt", "abc");
        let insensitive = Personality::windows();
        let tree = walk_local(&bed.0, &insensitive).unwrap();
        let remote = server(vec![file(1, "report.txt", None, &sha_of("abc"))]);
        assert!(diff(&tree, &remote, &insensitive, &[]).is_empty());

        // On Linux they are genuinely two different names, and the same pair is
        // a real disagreement.
        let sensitive = Personality::linux();
        let tree = walk_local(&bed.0, &sensitive).unwrap();
        assert!(!diff(&tree, &remote, &sensitive, &[]).is_empty());
    }

    #[test]
    fn a_case_clash_on_the_server_is_judged_by_the_name_the_device_holds() {
        // Mac soak run 40: the server holds DOC-4.TXT and doc-4.txt in one
        // folder, a pair no case-insensitive disk can hold together. Each
        // device holds one and reports the other unsyncable. Keyed to one
        // entry, the device's DOC-4.TXT was judged against doc-4.txt's bytes.
        let remote = server(vec![
            folder(1, "Projects", None),
            file(2, "DOC-4.TXT", Some(1), &sha_of("a's")),
            file(3, "doc-4.txt", Some(1), &sha_of("b's")),
        ]);
        let mac = Personality::macos();
        for (name, body) in [("DOC-4.TXT", "a's"), ("doc-4.txt", "b's")] {
            let bed = Bed::new("clash");
            bed.write(&format!("Projects/{name}"), body);
            let tree = walk_local(&bed.0, &mac).unwrap();
            assert_eq!(diff(&tree, &remote, &mac, &[]), vec![], "holding {name}");
        }
        // Bytes that are neither name's are a disagreement still.
        let bed = Bed::new("clash-wrong");
        bed.write("Projects/DOC-4.TXT", "something else");
        let tree = walk_local(&bed.0, &mac).unwrap();
        assert!(matches!(diff(&tree, &remote, &mac, &[]).as_slice(), [Difference::ContentDiffers { .. }]));
        // Holding neither, both are missing.
        let bed = Bed::new("clash-none");
        bed.mkdir("Projects");
        let tree = walk_local(&bed.0, &mac).unwrap();
        assert_eq!(diff(&tree, &remote, &mac, &[]).len(), 2);
        // Where the disk tells them apart, they are two files.
        let linux = Personality::linux();
        let bed = Bed::new("clash-linux");
        bed.write("Projects/DOC-4.TXT", "a's");
        let tree = walk_local(&bed.0, &linux).unwrap();
        assert_eq!(diff(&tree, &remote, &linux, &[]), vec![Difference::OnlyRemote { path: "Projects/doc-4.txt".into() }]);
    }

    #[test]
    fn identical_trees_produce_no_differences() {
        let bed = Bed::new("same");
        bed.write("Projects/a.txt", "abc");
        let tree = walk_local(&bed.0, &Personality::linux()).unwrap();
        let remote = server(vec![
            folder(1, "Projects", None),
            file(2, "a.txt", Some(1), &sha_of("abc")),
        ]);
        assert_eq!(diff(&tree, &remote, &Personality::linux(), &[]), vec![]);
    }

    #[test]
    fn the_same_path_holding_different_bytes_is_the_difference_that_matters() {
        // A size-and-mtime comparison would agree with a daemon that had put the
        // wrong bytes in the right place. That is the silent corruption this rig
        // exists to find, so the hash is the price of the assertion meaning
        // anything.
        let bed = Bed::new("content");
        bed.write("a.txt", "local version");
        let tree = walk_local(&bed.0, &Personality::linux()).unwrap();
        let remote = server(vec![file(1, "a.txt", None, &sha_of("server version"))]);
        let differences = diff(&tree, &remote, &Personality::linux(), &[]);
        assert!(matches!(
            differences.as_slice(),
            [Difference::ContentDiffers { .. }]
        ));
        assert!(differences[0].describe().contains("differs"));
    }

    #[test]
    fn each_side_holding_something_the_other_does_not_is_reported_from_that_side() {
        let bed = Bed::new("onlys");
        bed.write("only-here.txt", "x");
        let tree = walk_local(&bed.0, &Personality::linux()).unwrap();
        let remote = server(vec![file(1, "only-there.txt", None, "aa")]);
        let differences = diff(&tree, &remote, &Personality::linux(), &[]);
        assert_eq!(differences.len(), 2);
        assert!(differences.contains(&Difference::OnlyLocal {
            path: "only-here.txt".into()
        }));
        assert!(differences.contains(&Difference::OnlyRemote {
            path: "only-there.txt".into()
        }));
    }

    #[test]
    fn a_folder_where_the_other_side_has_a_file_is_called_out_as_such() {
        let bed = Bed::new("kind");
        bed.mkdir("thing");
        let tree = walk_local(&bed.0, &Personality::linux()).unwrap();
        let remote = server(vec![file(1, "thing", None, "aa")]);
        assert_eq!(
            diff(&tree, &remote, &Personality::linux(), &[]),
            vec![Difference::KindDiffers {
                path: "thing".into()
            }]
        );
    }

    #[test]
    fn a_subtree_the_user_opted_out_of_is_not_a_disagreement() {
        let bed = Bed::new("excluded");
        bed.write("Photos/big.raw", "x");
        let tree = walk_local(&bed.0, &Personality::linux()).unwrap();
        let remote = server(vec![
            folder(1, "Photos", None),
            file(2, "other.raw", Some(1), "aa"),
        ]);
        // Given as an ordinary path, the way the daemon's own config holds it —
        // a caller should not have to know the differ compares in another form.
        let excluded = vec!["Photos".to_string()];
        assert!(diff(&tree, &remote, &Personality::linux(), &excluded).is_empty());

        // And on a volume whose comparison form is not the spelling on disk,
        // which is where a caller passing a raw path would otherwise match
        // nothing and the whole opted-out subtree would be reported.
        let folded = walk_local(&bed.0, &Personality::windows()).unwrap();
        assert!(diff(&folded, &remote, &Personality::windows(), &excluded).is_empty());
    }

    #[test]
    fn a_missing_root_is_an_empty_tree_and_not_an_error() {
        // An unmounted volume during a settle is a finding for the convergence
        // assertion to make, not a crash for the walker to have.
        let tree = walk_local(Path::new("/nonexistent-soak-root"), &Personality::linux()).unwrap();
        assert!(tree.entries.is_empty());
    }

    #[test]
    fn contents_in_a_local_trash_are_findable() {
        // The engine never unlinks, so the trash is part of the answer to "where
        // did this file go" rather than evidence that it is gone.
        let bed = Bed::new("trash");
        bed.write("home/.local/share/Trash/files/deleted.txt", "recovered");
        let found = trash_contents(&bed.0.join("home"), &[]);
        assert!(found.contains(&sha_of("recovered")));
    }

    #[test]
    fn a_devices_trash_is_looked_for_under_its_unix_account_not_its_drive_home() {
        // The rig ran for twenty-two campaigns reading the drive home, where a
        // trash cannot exist, so every file the engine correctly trashed was
        // counted as gone for good. Two directories called "home", and the
        // wrong one is silently empty rather than an error.
        let bed = Bed::new("trash-home");
        bed.mkdir("drive-home/config");
        bed.write(
            "account-home/.local/share/Trash/files/Projects/doc-28.txt",
            "the last copy",
        );
        let device = Device {
            name: "device-b".into(),
            home: bed.0.join("drive-home"),
            root: bed.0.join("root"),
            container: None,
            unix_home: Some(bed.0.join("account-home")),
            unix_user: Some("soak-b".into()),
            service: None,
        };
        assert!(device_trash_contents(&device).contains(&sha_of("the last copy")));
    }
}
