//! Turning a server name into a name this filesystem will actually hold — and
//! knowing when it cannot.
//!
//! The server's namespace is more permissive than any local one: it will
//! cheerfully store `Q3: final.xlsx` and, alongside it, `q3: final.xlsx`. A
//! Windows machine can hold neither the colon nor both files. The rule
//! throughout is that the engine never quietly does something the user did not
//! ask for: a name that needs adjusting gets a recorded, reversible mapping,
//! and a name that genuinely cannot coexist with a sibling is refused *and
//! surfaced* rather than silently mangled into something that would sync back
//! as a rename.

use unicode_normalization::UnicodeNormalization;

use crate::personality::Personality;

/// Names the engine creates for its own purposes. They are never synced,
/// never reported as local files, and never collide with a user's file,
/// because a real name starting with this prefix is itself refused.
pub const INTERNAL_PREFIX: &str = ".jd-";

/// What happened when a server name met this filesystem.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum LocalName {
    /// Usable as-is.
    AsIs(String),
    /// Usable after escaping; the mapping is recorded in the state store, which
    /// is authoritative — the escape is not relied upon to be reversible, so a
    /// user's genuine `%3A` is not a trap.
    Escaped { local: String, reason: EscapeReason },
    /// Cannot be materialized here at all. Surfaced to the user with this
    /// reason; never silently dropped.
    Unsyncable(UnsyncableReason),
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum EscapeReason {
    IllegalCharacter,
    ReservedStem,
    TrailingDotOrSpace,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum UnsyncableReason {
    /// Differs from a sibling only by case, on a filesystem that cannot tell
    /// them apart. Materializing a mangled sibling would leak the mangling back
    /// as a rename the next time the user touched it, so the second one waits.
    CaseClash { with: String },
    /// Normalizes to the same NFC form as a sibling. Different bytes, one name
    /// as far as the disk is concerned — `café` typed two ways.
    UnicodeClash { with: String },
    /// Byte-for-byte the same name as a sibling. Nothing about the filesystem
    /// or the spelling is at fault: the server is holding two live items in one
    /// folder with one name, which it should not be.
    ///
    /// Told apart from the two clashes above because it is a different problem
    /// with a different fix, and calling it a unicode clash sent three separate
    /// investigations looking at NFC and NFD for a name like `app.db-wal` that
    /// has nothing but ASCII in it. The user cannot resolve this one either —
    /// they cannot see the second item to rename it.
    DuplicateName { with: String },
    /// Too long for this filesystem once encoded.
    NameTooLong { bytes: usize, limit: usize },
    /// The name is fine; the folder it sits in is too deep for this
    /// filesystem's total path budget. A different failure with a different
    /// fix — the user moves the folder up, they do not rename the file.
    PathTooLong { bytes: usize, limit: usize },
    /// Nothing survived escaping (a name made entirely of illegal characters).
    Empty,
    /// Collides with the engine's own reserved prefix.
    ReservedPrefix,
    /// The server holds this file encrypted and this build cannot open it.
    ///
    /// Unlike every reason above, the name is fine and the filesystem is
    /// willing — what is missing is the ability to turn ciphertext into the
    /// file's real bytes and real name. Recorded rather than ignored because
    /// the alternative is worse than waiting: treated as an ordinary file, the
    /// server's placeholder name and ciphertext hash read as plaintext facts,
    /// and raw ciphertext lands on disk under a name the user never chose.
    ///
    /// Transitional. This variant disappears when the encrypted engine path
    /// lands, and its removal is a compile error at every site that names it.
    EncryptedUnsupported,
}

/// The comparison key for a name: NFC, and case-folded when the filesystem
/// cannot tell case apart. Two names sharing a key cannot coexist as siblings.
///
/// `remote_name` always keeps the server's exact bytes; this is only ever the
/// key used to compare, never what gets written or uploaded.
pub fn comparison_key(name: &str, p: &Personality) -> String {
    let nfc: String = name.nfc().collect();
    if p.case_insensitive {
        nfc.to_lowercase()
    } else {
        nfc
    }
}

/// The NFC form, for comparing names across filesystems that disagree about
/// decomposition. macOS hands back `e` + combining-acute where the server
/// stored `é`; those are the same name and a rename of neither.
pub fn nfc(name: &str) -> String {
    name.nfc().collect()
}

/// True for names the engine owns: download spools, swap temporaries.
pub fn is_internal(name: &str) -> bool {
    name.starts_with(INTERNAL_PREFIX)
}

/// Resolve a server name to what this filesystem should hold, ignoring
/// siblings (clash detection needs the sibling set — see [`resolve_siblings`]).
pub fn to_local_name(remote_name: &str, p: &Personality) -> LocalName {
    if remote_name.is_empty() {
        return LocalName::Unsyncable(UnsyncableReason::Empty);
    }
    if is_internal(remote_name) {
        // A real file named `.jd-tmp-x` would be indistinguishable from the
        // engine's own spool files, and the engine skips those. Refusing is the
        // honest answer; silently syncing it would mean silently losing it.
        return LocalName::Unsyncable(UnsyncableReason::ReservedPrefix);
    }

    // Composing is for a disk that decomposes anyway: there, the composed form
    // is what `read_dir` hands back, so recording it keeps the engine's idea of
    // the name and the disk's in step.
    //
    // On a disk that keeps the bytes it is given, composing invents a rename
    // nobody performs. The server holds `cafe` + combining-acute; this says the
    // local name should be the composed spelling; nothing renames the file,
    // because renaming a user's file to respell it is not something the engine
    // does. Every scan then finds the file missing from where it was recorded,
    // pairs it by content at the decomposed path, and reads that as the user
    // moving it — so the client asks the server to rename the file to the name
    // the server already has. The server answers `name_taken`, against the file
    // itself, and the client waits for a sibling that does not exist. The soak
    // rig held nineteen files that way, one op per accented name, forever.
    let normalized = if p.decomposes_unicode {
        nfc(remote_name)
    } else {
        remote_name.to_string()
    };
    let mut escaped = String::with_capacity(normalized.len());
    let mut reason: Option<EscapeReason> = None;

    for ch in normalized.chars() {
        if p.illegal_chars.contains(&ch) || (ch as u32) < 0x20 {
            escaped.push_str(&format!("%{:02X}", ch as u32));
            reason.get_or_insert(EscapeReason::IllegalCharacter);
        } else {
            escaped.push(ch);
        }
    }

    // A reserved DOS stem is reserved whatever follows it: `CON.txt` is still
    // the console. Escaping the first character is enough to break the match
    // while staying recognizable.
    if !p.reserved_stems.is_empty() {
        let stem = escaped.split('.').next().unwrap_or("").to_ascii_uppercase();
        if p.reserved_stems.contains(&stem.as_str()) {
            let mut chars = escaped.chars();
            let first = chars.next().unwrap_or('_');
            escaped = format!("%{:02X}{}", first as u32, chars.as_str());
            reason.get_or_insert(EscapeReason::ReservedStem);
        }
    }

    // Windows strips these on the way in, which would turn the file into a
    // different name than the server has and read as a rename on the next scan.
    if p.strips_trailing_dots_and_spaces {
        let trimmed = escaped.trim_end_matches(['.', ' ']);
        if trimmed.len() != escaped.len() {
            let stripped_count = escaped.len() - trimmed.len();
            let tail: String = escaped[trimmed.len()..]
                .chars()
                .map(|c| format!("%{:02X}", c as u32))
                .collect();
            escaped = format!("{}{}", trimmed, tail);
            let _ = stripped_count;
            reason.get_or_insert(EscapeReason::TrailingDotOrSpace);
        }
    }

    if escaped.is_empty() {
        return LocalName::Unsyncable(UnsyncableReason::Empty);
    }
    if escaped.len() > p.max_name_bytes {
        return LocalName::Unsyncable(UnsyncableReason::NameTooLong {
            bytes: escaped.len(),
            limit: p.max_name_bytes,
        });
    }

    match reason {
        None => LocalName::AsIs(escaped),
        Some(r) => LocalName::Escaped {
            local: escaped,
            reason: r,
        },
    }
}

/// Whether a path relative to the sync root fits this filesystem's budget.
///
/// The root's own prefix is included by the caller — the same tree under
/// `C:\Users\a\Joinery Drive` and under `D:\jd` has different headroom, and the
/// user's answer to "it does not fit" depends on which one they are looking at.
pub fn path_fits(relative_path_bytes: usize, root_prefix_bytes: usize, p: &Personality) -> bool {
    // The `<` rather than `<=` accounts for the separator between the root and
    // the first component, which is one byte nobody counted.
    relative_path_bytes + root_prefix_bytes < p.max_path_bytes
}

/// One sibling's resolution within a folder, in server order.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Resolved {
    pub remote_name: String,
    pub outcome: LocalName,
}

/// Resolve a folder's children together, so clashes can be detected.
///
/// Order matters and is the caller's: the first name to claim a key
/// materializes and every later claimant is refused. Feeding these in a stable
/// order (server id) means the same file wins on every device and after every
/// state rebuild — an unstable order would make two machines disagree about
/// which sibling is real.
pub fn resolve_siblings(remote_names: &[String], p: &Personality) -> Vec<Resolved> {
    // (key, winner's local name, winner's name as the server gave it). The
    // server's name is kept as well as the local one because the two answer
    // different questions: the local name says what the disk would have called
    // it, and the server's says whether these are genuinely the same name or
    // two spellings that a normalizing disk brought together.
    let mut claimed: Vec<(String, String, String)> = Vec::new();
    let mut out = Vec::with_capacity(remote_names.len());

    for name in remote_names {
        let outcome = to_local_name(name, p);
        let local = match &outcome {
            LocalName::AsIs(s) => s.clone(),
            LocalName::Escaped { local, .. } => local.clone(),
            LocalName::Unsyncable(_) => {
                out.push(Resolved {
                    remote_name: name.clone(),
                    outcome,
                });
                continue;
            }
        };

        let key = comparison_key(&local, p);
        if let Some((_, winner, winner_remote)) = claimed.iter().find(|(k, _, _)| *k == key) {
            // Distinguish "same letters, different case" from "different bytes,
            // same normalized form" — the user needs to know which, because the
            // fixes are different (rename one, or pick one spelling).
            let case_only = comparison_key(
                &local,
                &Personality {
                    case_insensitive: false,
                    ..*p
                },
            ) != comparison_key(
                winner,
                &Personality {
                    case_insensitive: false,
                    ..*p
                },
            );
            let reason = if name == winner_remote {
                // The SERVER's two names are byte-identical, so neither the
                // filesystem nor the spelling is involved and neither clash
                // describes it. Compared here rather than on the local names,
                // which is not the same question: a disk that normalizes turns
                // two spellings of `café` into one local name, and calling that
                // a duplicate would hide the only thing the user can act on.
                UnsyncableReason::DuplicateName {
                    with: winner.clone(),
                }
            } else if p.case_insensitive && case_only {
                UnsyncableReason::CaseClash {
                    with: winner.clone(),
                }
            } else {
                UnsyncableReason::UnicodeClash {
                    with: winner.clone(),
                }
            };
            out.push(Resolved {
                remote_name: name.clone(),
                outcome: LocalName::Unsyncable(reason),
            });
            continue;
        }

        claimed.push((key, local, name.clone()));
        out.push(Resolved {
            remote_name: name.clone(),
            outcome,
        });
    }

    out
}

/// The name a losing local version is preserved under.
///
/// Nothing is ever overwritten to resolve a conflict: the remote head keeps the
/// path the user knows, and the local version lands beside it under a name that
/// says what it is, when it happened, and which machine it came from. `suffix`
/// disambiguates repeats within the same day.
pub fn conflict_copy_name(name: &str, date: &str, device: &str, suffix: u32) -> String {
    let (stem, ext) = split_extension(name);
    let n = if suffix <= 1 {
        String::new()
    } else {
        format!(" {}", suffix)
    };
    match ext {
        Some(e) => format!(
            "{} (conflicted copy {} from {}){}.{}",
            stem, date, device, n, e
        ),
        None => format!("{} (conflicted copy {} from {}){}", stem, date, device, n),
    }
}

/// Split a filename into stem and extension, treating a leading dot as part of
/// the stem (`.bashrc` has no extension, `archive.tar.gz` extends with `gz`).
pub fn split_extension(name: &str) -> (&str, Option<&str>) {
    match name.rfind('.') {
        Some(idx) if idx > 0 && idx + 1 < name.len() => (&name[..idx], Some(&name[idx + 1..])),
        _ => (name, None),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn plain_names_pass_through() {
        for p in [
            Personality::linux(),
            Personality::macos(),
            Personality::windows(),
        ] {
            assert_eq!(
                to_local_name("Report.txt", &p),
                LocalName::AsIs("Report.txt".into())
            );
        }
    }

    #[test]
    fn a_decomposed_server_name_is_left_alone_where_the_disk_will_keep_it() {
        // The engine may only ask for a spelling the disk will still be holding
        // when it looks again. ext4, APFS and NTFS keep what they are given, so
        // the server's spelling is the local name and there is nothing to map.
        //
        // Composing here is not cosmetic. Nothing renames the file to match, so
        // the next scan finds it missing from the composed path, pairs it by
        // content at the decomposed one, and calls that a move by the user — and
        // the client asks the server to rename the file to the name it already
        // has. That comes back `name_taken`, against the file itself, and is
        // retried until somebody looks.
        for p in [
            Personality::linux(),
            Personality::macos(),
            Personality::windows(),
        ] {
            assert_eq!(
                to_local_name("cafe\u{301} notes.txt", &p),
                LocalName::AsIs("cafe\u{301} notes.txt".into()),
                "a preserving volume gets the server's spelling"
            );
        }
    }

    #[test]
    fn a_decomposing_volume_is_told_the_composed_name() {
        // The other half: HFS+ will decompose whatever it is handed and hand
        // back the decomposed form, which `OsVfs` composes on the way in. The
        // composed spelling is therefore the one that matches what the engine
        // will see, and recording it is what stops the round trip reading as a
        // rename.
        assert_eq!(
            to_local_name("cafe\u{301} notes.txt", &Personality::hfs_plus()),
            LocalName::AsIs("caf\u{e9} notes.txt".into())
        );
    }

    #[test]
    fn windows_escapes_illegal_characters_linux_does_not() {
        assert_eq!(
            to_local_name("Q3: final.xlsx", &Personality::windows()),
            LocalName::Escaped {
                local: "Q3%3A final.xlsx".into(),
                reason: EscapeReason::IllegalCharacter,
            }
        );
        assert_eq!(
            to_local_name("Q3: final.xlsx", &Personality::linux()),
            LocalName::AsIs("Q3: final.xlsx".into())
        );
    }

    #[test]
    fn reserved_stems_are_escaped_with_any_extension() {
        let p = Personality::windows();
        assert!(matches!(
            to_local_name("CON", &p),
            LocalName::Escaped {
                reason: EscapeReason::ReservedStem,
                ..
            }
        ));
        assert!(matches!(
            to_local_name("con.txt", &p),
            LocalName::Escaped {
                reason: EscapeReason::ReservedStem,
                ..
            }
        ));
        // Not reserved: a longer stem that merely starts with one.
        assert_eq!(
            to_local_name("CONTRACT.txt", &p),
            LocalName::AsIs("CONTRACT.txt".into())
        );
    }

    #[test]
    fn trailing_dots_and_spaces_are_escaped_on_windows_only() {
        assert!(matches!(
            to_local_name("report.", &Personality::windows()),
            LocalName::Escaped {
                reason: EscapeReason::TrailingDotOrSpace,
                ..
            }
        ));
        assert_eq!(
            to_local_name("report.", &Personality::linux()),
            LocalName::AsIs("report.".into())
        );
    }

    #[test]
    fn the_engines_own_prefix_is_refused() {
        assert_eq!(
            to_local_name(".jd-tmp-abc", &Personality::linux()),
            LocalName::Unsyncable(UnsyncableReason::ReservedPrefix)
        );
    }

    #[test]
    fn overlong_names_are_unsyncable_not_truncated() {
        let long = "a".repeat(300);
        assert!(matches!(
            to_local_name(&long, &Personality::linux()),
            LocalName::Unsyncable(UnsyncableReason::NameTooLong { .. })
        ));
    }

    #[test]
    fn nfc_and_nfd_spellings_share_a_comparison_key() {
        let composed = "caf\u{e9}.txt";
        let decomposed = "cafe\u{301}.txt";
        assert_ne!(composed, decomposed);
        let p = Personality::macos();
        assert_eq!(comparison_key(composed, &p), comparison_key(decomposed, &p));
    }

    #[test]
    fn case_clash_refuses_the_second_sibling_only_where_case_is_ignored() {
        let names = vec!["Report.txt".to_string(), "report.txt".to_string()];

        let mac = resolve_siblings(&names, &Personality::macos());
        assert_eq!(mac[0].outcome, LocalName::AsIs("Report.txt".into()));
        assert!(matches!(
            &mac[1].outcome,
            LocalName::Unsyncable(UnsyncableReason::CaseClash { with }) if with == "Report.txt"
        ));

        // On Linux both are real, distinct files.
        let linux = resolve_siblings(&names, &Personality::linux());
        assert!(matches!(linux[0].outcome, LocalName::AsIs(_)));
        assert!(matches!(linux[1].outcome, LocalName::AsIs(_)));
    }

    #[test]
    fn unicode_clash_is_reported_as_such_not_as_a_case_clash() {
        let names = vec!["caf\u{e9}.txt".to_string(), "cafe\u{301}.txt".to_string()];
        let out = resolve_siblings(&names, &Personality::linux());
        assert!(matches!(out[0].outcome, LocalName::AsIs(_)));
        assert!(matches!(
            &out[1].outcome,
            LocalName::Unsyncable(UnsyncableReason::UnicodeClash { .. })
        ));
    }

    #[test]
    fn two_identical_names_are_a_duplicate_and_not_a_unicode_clash() {
        // What the server hands over when it has taken two live files with one
        // name in one folder. Reported as a unicode clash for most of this
        // engine's life, which sent every investigation looking at NFC and NFD
        // for names like `app.db-wal` that hold nothing but ASCII.
        let names = vec!["app.db-wal".to_string(), "app.db-wal".to_string()];
        let out = resolve_siblings(&names, &Personality::linux());
        assert!(matches!(out[0].outcome, LocalName::AsIs(_)));
        assert!(matches!(
            &out[1].outcome,
            LocalName::Unsyncable(UnsyncableReason::DuplicateName { with }) if with == "app.db-wal"
        ));
    }

    #[test]
    fn sibling_resolution_is_order_stable() {
        // The same input order must always produce the same winner, on every
        // device — otherwise two machines disagree about which file is real.
        let names = vec![
            "A.txt".to_string(),
            "a.txt".to_string(),
            "a.TXT".to_string(),
        ];
        let first = resolve_siblings(&names, &Personality::windows());
        let second = resolve_siblings(&names, &Personality::windows());
        assert_eq!(first, second);
        assert!(matches!(first[0].outcome, LocalName::AsIs(_)));
        assert!(matches!(first[1].outcome, LocalName::Unsyncable(_)));
        assert!(matches!(first[2].outcome, LocalName::Unsyncable(_)));
    }

    #[test]
    fn a_deep_tree_from_a_mac_does_not_fit_a_windows_path_budget() {
        // Every name in it is legal; the problem is only where it sits. The two
        // are reported separately because the fixes are different.
        let deep = 400;
        let win = Personality {
            max_path_bytes: 260,
            ..Personality::windows()
        };
        assert!(!path_fits(deep, 24, &win));
        assert!(path_fits(deep, 24, &Personality::linux()));
    }

    #[test]
    fn the_root_prefix_counts_against_the_budget() {
        let p = Personality {
            max_path_bytes: 100,
            ..Personality::windows()
        };
        assert!(path_fits(50, 40, &p));
        // Same file, root moved somewhere with a longer name: no longer fits.
        assert!(!path_fits(50, 60, &p));
    }

    #[test]
    fn conflict_copies_are_named_for_when_and_where() {
        assert_eq!(
            conflict_copy_name("Report.xlsx", "2026-07-16", "MacBook", 1),
            "Report (conflicted copy 2026-07-16 from MacBook).xlsx"
        );
        assert_eq!(
            conflict_copy_name("Report.xlsx", "2026-07-16", "MacBook", 2),
            "Report (conflicted copy 2026-07-16 from MacBook) 2.xlsx"
        );
        assert_eq!(
            conflict_copy_name("Makefile", "2026-07-16", "PC", 1),
            "Makefile (conflicted copy 2026-07-16 from PC)"
        );
    }

    #[test]
    fn extension_splitting_handles_dotfiles_and_multi_dots() {
        assert_eq!(
            split_extension("archive.tar.gz"),
            ("archive.tar", Some("gz"))
        );
        assert_eq!(split_extension(".bashrc"), (".bashrc", None));
        assert_eq!(split_extension("Makefile"), ("Makefile", None));
        assert_eq!(split_extension("trailing."), ("trailing.", None));
    }
}
