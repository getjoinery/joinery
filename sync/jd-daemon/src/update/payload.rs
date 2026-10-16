//! The update payload: the files that take the running copy's place, as one
//! gzipped tar.
//!
//! A Mac payload holds `Joinery Drive.app/` at its root; a Windows or Linux
//! payload holds the program's files (`joinery-drive`, `joinery-drive-tray`)
//! at its root. Each top-level entry replaces the entry of the same name.
//!
//! **This is a format we write and we read, both here.** The publisher's tool
//! ([`pack`], through `joinery-drive-release pack`) produces it and the client
//! ([`unpack`]) consumes it, so the reader only has to understand what the
//! writer emits: plain ustar headers, directories, regular files, and (on Unix)
//! symlinks. Anything else is refused by name rather than half-understood. The
//! writer is deterministic — sorted entries, zero timestamps and owners,
//! normalised modes, a gzip header with no name or time — so the same build
//! packs to the same bytes, and a payload can be rebuilt and compared.
//!
//! A payload is only ever unpacked after its hash has matched a signed
//! manifest, so every byte here is already one the publisher vouched for. The
//! reader is careful anyway — no absolute paths, no `..`, nothing written
//! through a symlink, a cap on the total size — because the cost is a few lines
//! and the alternative is a single publishing mistake writing outside the
//! staging folder.

use std::io::{self, Read, Write};
use std::path::{Component, Path, PathBuf};

use flate2::read::GzDecoder;
use flate2::write::GzEncoder;
use flate2::{Compression, GzBuilder};

const BLOCK: usize = 512;

/// The most a payload may unpack to. The program is tens of megabytes; this
/// is a ceiling against a mistake, not a budget.
pub const MAX_UNPACKED_BYTES: u64 = 2 << 30;

#[derive(Debug, thiserror::Error)]
pub enum PayloadError {
    #[error("io: {0}")]
    Io(#[from] io::Error),
    #[error("the update payload is not in the expected format: {0}")]
    Format(String),
}

fn format_err(why: impl Into<String>) -> PayloadError {
    PayloadError::Format(why.into())
}

// ---------------------------------------------------------------------------
// Writing
// ---------------------------------------------------------------------------

enum Entry {
    Dir,
    File { exec: bool, path: PathBuf },
    Symlink { target: String },
}

/// Pack each source — a file or a directory — at the root of the payload,
/// under its own name.
pub fn pack(sources: &[PathBuf], out: impl Write) -> Result<(), PayloadError> {
    let mut entries: Vec<(String, Entry)> = Vec::new();
    for source in sources {
        let name = source
            .file_name()
            .ok_or_else(|| format_err(format!("{} has no name", source.display())))?
            .to_string_lossy()
            .to_string();
        collect(source, &name, &mut entries)?;
    }
    entries.sort_by(|a, b| a.0.cmp(&b.0));
    for pair in entries.windows(2) {
        if pair[0].0 == pair[1].0 {
            return Err(format_err(format!("{} is given twice", pair[0].0)));
        }
    }

    // No file name and a zero time in the gzip header, so the bytes depend on
    // the contents alone.
    let gz: GzEncoder<_> = GzBuilder::new()
        .mtime(0)
        .operating_system(255)
        .write(out, Compression::default());
    let mut tar = gz;
    for (name, entry) in &entries {
        match entry {
            Entry::Dir => write_header(&mut tar, &format!("{name}/"), b'5', 0o755, 0, "")?,
            Entry::Symlink { target } => write_header(&mut tar, name, b'2', 0o777, 0, target)?,
            Entry::File { exec, path } => {
                let len = std::fs::metadata(path)?.len();
                let mode = if *exec { 0o755 } else { 0o644 };
                write_header(&mut tar, name, b'0', mode, len, "")?;
                let mut file = std::fs::File::open(path)?;
                let copied = io::copy(&mut (&mut file).take(len), &mut tar)?;
                if copied != len {
                    return Err(format_err(format!("{} changed while it was packed", name)));
                }
                let pad = (BLOCK - (len as usize % BLOCK)) % BLOCK;
                tar.write_all(&vec![0u8; pad])?;
            }
        }
    }
    tar.write_all(&[0u8; BLOCK * 2])?;
    tar.finish()?.flush()?;
    Ok(())
}

fn collect(path: &Path, name: &str, out: &mut Vec<(String, Entry)>) -> Result<(), PayloadError> {
    let meta = std::fs::symlink_metadata(path)?;
    if meta.file_type().is_symlink() {
        let target = std::fs::read_link(path)?
            .to_string_lossy()
            .replace('\\', "/");
        out.push((name.to_string(), Entry::Symlink { target }));
    } else if meta.is_dir() {
        out.push((name.to_string(), Entry::Dir));
        for child in std::fs::read_dir(path)? {
            let child = child?;
            let child_name = child.file_name().to_string_lossy().to_string();
            collect(&child.path(), &format!("{name}/{child_name}"), out)?;
        }
    } else if meta.is_file() {
        out.push((
            name.to_string(),
            Entry::File {
                exec: is_executable(&meta, path),
                path: path.to_path_buf(),
            },
        ));
    } else {
        return Err(format_err(format!("{name} is neither a file nor a folder")));
    }
    Ok(())
}

#[cfg(unix)]
fn is_executable(meta: &std::fs::Metadata, _path: &Path) -> bool {
    use std::os::unix::fs::PermissionsExt;
    meta.permissions().mode() & 0o111 != 0
}

#[cfg(not(unix))]
fn is_executable(_meta: &std::fs::Metadata, path: &Path) -> bool {
    // Windows has no execute bit. A payload packed here for a Mac or Linux
    // keeps its programs runnable by the one rule that holds for this
    // program's files: the binaries have no extension.
    path.extension().is_none() || path.extension().is_some_and(|e| e == "exe")
}

fn write_header(
    out: &mut impl Write,
    name: &str,
    kind: u8,
    mode: u32,
    size: u64,
    link: &str,
) -> Result<(), PayloadError> {
    let mut h = [0u8; BLOCK];
    let (prefix, base) = split_name(name)?;
    h[..base.len()].copy_from_slice(base.as_bytes());
    octal(&mut h[100..108], mode as u64);
    octal(&mut h[108..116], 0);
    octal(&mut h[116..124], 0);
    octal(&mut h[124..136], size);
    octal(&mut h[136..148], 0);
    h[156] = kind;
    if link.len() > 100 {
        return Err(format_err(format!(
            "{name}: link target longer than 100 bytes"
        )));
    }
    h[157..157 + link.len()].copy_from_slice(link.as_bytes());
    h[257..263].copy_from_slice(b"ustar\0");
    h[263..265].copy_from_slice(b"00");
    h[345..345 + prefix.len()].copy_from_slice(prefix.as_bytes());
    // The checksum is computed with its own field read as eight spaces.
    h[148..156].copy_from_slice(b"        ");
    let sum: u32 = h.iter().map(|&b| b as u32).sum();
    let field = format!("{sum:06o}\0 ");
    h[148..156].copy_from_slice(field.as_bytes());
    out.write_all(&h)?;
    Ok(())
}

/// ustar's 100-byte name plus 155-byte prefix, split at a slash.
fn split_name(name: &str) -> Result<(&str, &str), PayloadError> {
    if name.len() <= 100 {
        return Ok(("", name));
    }
    let bytes = name.as_bytes();
    for i in (0..bytes.len()).rev() {
        if bytes[i] == b'/' && i <= 155 && bytes.len() - i - 1 <= 100 && i > 0 {
            return Ok((&name[..i], &name[i + 1..]));
        }
    }
    Err(format_err(format!(
        "{name}: too long for the payload format"
    )))
}

fn octal(field: &mut [u8], value: u64) {
    let width = field.len() - 1;
    let text = format!("{value:0width$o}");
    field[..width].copy_from_slice(text.as_bytes());
    field[width] = 0;
}

// ---------------------------------------------------------------------------
// Reading
// ---------------------------------------------------------------------------

/// Unpack a payload into `dest`, which must not exist yet. Returns the names at
/// the payload's root.
pub fn unpack(source: impl Read, dest: &Path) -> Result<Vec<String>, PayloadError> {
    std::fs::create_dir(dest)?;
    let mut tar = GzDecoder::new(source);
    let mut roots: Vec<String> = Vec::new();
    let mut symlinks: Vec<PathBuf> = Vec::new();
    let mut total: u64 = 0;
    let mut header = [0u8; BLOCK];
    loop {
        tar.read_exact(&mut header)
            .map_err(|_| format_err("it ends before its end marker"))?;
        if header.iter().all(|&b| b == 0) {
            break;
        }
        check_sum(&header)?;
        if &header[257..263] != b"ustar\0" {
            return Err(format_err("an entry that is not ustar"));
        }
        let name = entry_name(&header)?;
        let kind = header[156];
        let size = parse_octal(&header[124..136])?;
        let mode = parse_octal(&header[100..108])? as u32;

        let rel = safe_relative(&name)?;
        // Nothing may be written through a link the payload itself made: a
        // link to `/etc` followed by a file at `link/passwd` is the classic
        // way an archive writes outside the folder it was unpacked into.
        if symlinks.iter().any(|l| rel.starts_with(l)) {
            return Err(format_err(format!("{name} is inside a link")));
        }
        let root = rel
            .components()
            .next()
            .map(|c| c.as_os_str().to_string_lossy().to_string())
            .unwrap_or_default();
        if !roots.contains(&root) {
            roots.push(root);
        }
        let target = dest.join(&rel);

        match kind {
            b'5' => {
                if size != 0 {
                    return Err(format_err(format!(
                        "{name}: a folder with contents in its header"
                    )));
                }
                std::fs::create_dir_all(&target)?;
            }
            b'0' | 0 => {
                total = total.saturating_add(size);
                if total > MAX_UNPACKED_BYTES {
                    return Err(format_err("larger than any update could be"));
                }
                if let Some(parent) = target.parent() {
                    std::fs::create_dir_all(parent)?;
                }
                let mut file = std::fs::OpenOptions::new()
                    .write(true)
                    .create_new(true)
                    .open(&target)?;
                let copied = io::copy(&mut (&mut tar).take(size), &mut file)?;
                if copied != size {
                    return Err(format_err(format!("{name} is cut short")));
                }
                file.sync_all()?;
                set_mode(&target, mode)?;
                let pad = (BLOCK - (size as usize % BLOCK)) % BLOCK;
                let mut skip = vec![0u8; pad];
                tar.read_exact(&mut skip)
                    .map_err(|_| format_err(format!("{name} is cut short")))?;
            }
            b'2' => {
                let link = field_str(&header[157..257])?;
                make_symlink(&rel, &link, &target)?;
                symlinks.push(rel);
            }
            other => {
                return Err(format_err(format!(
                    "{name}: entry type {:?} is not one the publisher writes",
                    other as char
                )))
            }
        }
    }
    if roots.is_empty() {
        return Err(format_err("it is empty"));
    }
    Ok(roots)
}

fn check_sum(h: &[u8; BLOCK]) -> Result<(), PayloadError> {
    let stored = parse_octal(&h[148..156])?;
    let sum: u64 = h
        .iter()
        .enumerate()
        .map(|(i, &b)| {
            if (148..156).contains(&i) {
                b' ' as u64
            } else {
                b as u64
            }
        })
        .sum();
    if stored != sum {
        return Err(format_err("a header whose checksum does not match"));
    }
    Ok(())
}

fn field_str(field: &[u8]) -> Result<String, PayloadError> {
    let end = field.iter().position(|&b| b == 0).unwrap_or(field.len());
    String::from_utf8(field[..end].to_vec()).map_err(|_| format_err("a name that is not UTF-8"))
}

fn entry_name(h: &[u8; BLOCK]) -> Result<String, PayloadError> {
    let base = field_str(&h[0..100])?;
    let prefix = field_str(&h[345..500])?;
    let name = if prefix.is_empty() {
        base
    } else {
        format!("{prefix}/{base}")
    };
    Ok(name.trim_end_matches('/').to_string())
}

fn parse_octal(field: &[u8]) -> Result<u64, PayloadError> {
    let text = field_str(field)?;
    let text = text.trim_matches(|c: char| c == ' ' || c == '\0');
    if text.is_empty() {
        return Ok(0);
    }
    u64::from_str_radix(text, 8).map_err(|_| format_err("a header number that is not octal"))
}

/// A path that stays inside the folder it is joined to: relative, made only of
/// ordinary names, and spelled the same on every platform.
fn safe_relative(name: &str) -> Result<PathBuf, PayloadError> {
    if name.is_empty() || name.contains('\\') || name.contains(':') {
        return Err(format_err(format!("{name:?} is not a payload path")));
    }
    let path = Path::new(name);
    let mut clean = PathBuf::new();
    for part in path.components() {
        match part {
            Component::Normal(p) => clean.push(p),
            _ => return Err(format_err(format!("{name:?} leaves the payload"))),
        }
    }
    if clean.as_os_str().is_empty() {
        return Err(format_err(format!("{name:?} is not a payload path")));
    }
    Ok(clean)
}

#[cfg(unix)]
fn make_symlink(rel: &Path, link: &str, target: &Path) -> Result<(), PayloadError> {
    // Relative, and resolving inside the payload: a bundle's internal links
    // point at its own folders and nowhere else.
    let base = rel.parent().unwrap_or(Path::new(""));
    let mut depth: i64 = base.components().count() as i64;
    if link.starts_with('/') || link.contains('\\') || link.is_empty() {
        return Err(format_err(format!("{}: link to {link:?}", rel.display())));
    }
    for part in link.split('/') {
        match part {
            "" | "." => {}
            ".." => depth -= 1,
            _ => depth += 1,
        }
        if depth < 0 {
            return Err(format_err(format!(
                "{}: link leaves the payload",
                rel.display()
            )));
        }
    }
    if let Some(parent) = target.parent() {
        std::fs::create_dir_all(parent)?;
    }
    std::os::unix::fs::symlink(link, target)?;
    Ok(())
}

#[cfg(not(unix))]
fn make_symlink(rel: &Path, _link: &str, _target: &Path) -> Result<(), PayloadError> {
    // No update for Windows carries one, and making one needs a privilege a
    // normal account does not have.
    Err(format_err(format!(
        "{}: a link in a Windows payload",
        rel.display()
    )))
}

#[cfg(unix)]
fn set_mode(path: &Path, mode: u32) -> io::Result<()> {
    use std::os::unix::fs::PermissionsExt;
    let mode = if mode & 0o111 != 0 { 0o755 } else { 0o644 };
    std::fs::set_permissions(path, std::fs::Permissions::from_mode(mode))
}

#[cfg(not(unix))]
fn set_mode(_path: &Path, _mode: u32) -> io::Result<()> {
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    pub(crate) fn temp(tag: &str) -> PathBuf {
        let p = std::env::temp_dir().join(format!(
            "jd-payload-{tag}-{}-{:?}",
            std::process::id(),
            std::thread::current().id()
        ));
        let _ = std::fs::remove_dir_all(&p);
        std::fs::create_dir_all(&p).unwrap();
        p
    }

    fn bundle(dir: &Path) -> PathBuf {
        let app = dir.join("Joinery Drive.app");
        std::fs::create_dir_all(app.join("Contents/MacOS")).unwrap();
        std::fs::create_dir_all(app.join("Contents/Resources")).unwrap();
        std::fs::write(app.join("Contents/Info.plist"), b"<plist/>").unwrap();
        std::fs::write(app.join("Contents/MacOS/joinery-drive"), vec![7u8; 1500]).unwrap();
        std::fs::write(app.join("Contents/MacOS/joinery-drive-tray"), b"tray").unwrap();
        #[cfg(unix)]
        {
            use std::os::unix::fs::PermissionsExt;
            for f in ["joinery-drive", "joinery-drive-tray"] {
                std::fs::set_permissions(
                    app.join("Contents/MacOS").join(f),
                    std::fs::Permissions::from_mode(0o755),
                )
                .unwrap();
            }
        }
        app
    }

    #[test]
    fn a_bundle_packs_and_unpacks_to_the_same_files() {
        let dir = temp("roundtrip");
        let app = bundle(&dir);
        let mut bytes = Vec::new();
        pack(std::slice::from_ref(&app), &mut bytes).unwrap();

        let out = dir.join("out");
        let roots = unpack(&bytes[..], &out).unwrap();
        assert_eq!(roots, vec!["Joinery Drive.app".to_string()]);
        for f in [
            "Contents/Info.plist",
            "Contents/MacOS/joinery-drive",
            "Contents/MacOS/joinery-drive-tray",
        ] {
            assert_eq!(
                std::fs::read(app.join(f)).unwrap(),
                std::fs::read(out.join("Joinery Drive.app").join(f)).unwrap(),
                "{f}"
            );
        }
        assert!(out.join("Joinery Drive.app/Contents/Resources").is_dir());
        #[cfg(unix)]
        {
            use std::os::unix::fs::PermissionsExt;
            let mode =
                std::fs::metadata(out.join("Joinery Drive.app/Contents/MacOS/joinery-drive"))
                    .unwrap()
                    .permissions()
                    .mode();
            assert_eq!(mode & 0o777, 0o755, "a program has to stay runnable");
            let mode = std::fs::metadata(out.join("Joinery Drive.app/Contents/Info.plist"))
                .unwrap()
                .permissions()
                .mode();
            assert_eq!(mode & 0o777, 0o644);
        }
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn packing_the_same_files_twice_gives_the_same_bytes() {
        // So a payload can be rebuilt from the same build and compared, and so
        // two publishers produce one hash.
        let dir = temp("determinism");
        let app = bundle(&dir);
        let mut a = Vec::new();
        let mut b = Vec::new();
        pack(std::slice::from_ref(&app), &mut a).unwrap();
        // A newer timestamp on one file changes nothing.
        std::fs::write(app.join("Contents/Info.plist"), b"<plist/>").unwrap();
        pack(&[app], &mut b).unwrap();
        assert_eq!(a, b);
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn loose_files_land_at_the_root() {
        let dir = temp("files");
        std::fs::write(dir.join("joinery-drive"), b"daemon").unwrap();
        std::fs::write(dir.join("joinery-drive-tray"), b"tray").unwrap();
        let mut bytes = Vec::new();
        pack(
            &[dir.join("joinery-drive"), dir.join("joinery-drive-tray")],
            &mut bytes,
        )
        .unwrap();
        let out = dir.join("out");
        let roots = unpack(&bytes[..], &out).unwrap();
        assert_eq!(roots, vec!["joinery-drive", "joinery-drive-tray"]);
        assert_eq!(std::fs::read(out.join("joinery-drive")).unwrap(), b"daemon");
        let _ = std::fs::remove_dir_all(&dir);
    }

    /// One handmade entry, for the shapes the writer never produces.
    fn archive(entries: &[(&str, u8, &[u8], &str)]) -> Vec<u8> {
        let mut gz = GzEncoder::new(Vec::new(), Compression::fast());
        for (name, kind, body, link) in entries {
            write_header(&mut gz, name, *kind, 0o644, body.len() as u64, link).unwrap();
            gz.write_all(body).unwrap();
            gz.write_all(&vec![0u8; (BLOCK - body.len() % BLOCK) % BLOCK])
                .unwrap();
        }
        gz.write_all(&[0u8; BLOCK * 2]).unwrap();
        gz.finish().unwrap()
    }

    #[test]
    fn nothing_is_written_outside_the_folder_it_unpacks_into() {
        let dir = temp("escape");
        let cases: Vec<Vec<u8>> = vec![
            archive(&[("../evil", b'0', b"x", "")]),
            archive(&[("a/../../evil", b'0', b"x", "")]),
            archive(&[("/tmp/evil", b'0', b"x", "")]),
            archive(&[("C:evil", b'0', b"x", "")]),
            archive(&[("a\\..\\evil", b'0', b"x", "")]),
            // Through a link the archive made itself.
            archive(&[("l", b'2', b"", "/tmp"), ("l/evil", b'0', b"x", "")]),
            archive(&[("l", b'2', b"", "../.."), ("l/evil", b'0', b"x", "")]),
            archive(&[("ok", b'2', b"", "sub"), ("ok/evil", b'0', b"x", "")]),
        ];
        for (i, bytes) in cases.iter().enumerate() {
            let out = dir.join(format!("out{i}"));
            assert!(unpack(&bytes[..], &out).is_err(), "case {i} was accepted");
        }
        assert!(!dir.join("evil").exists());
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn entry_types_the_publisher_never_writes_are_refused_by_name() {
        let dir = temp("types");
        // A hard link and a device node.
        for kind in *b"13xL" {
            let bytes = archive(&[("thing", kind, b"", "")]);
            let err = unpack(&bytes[..], &dir.join(format!("o{kind}"))).unwrap_err();
            assert!(
                err.to_string().contains("not one the publisher writes")
                    || err.to_string().contains("format")
            );
        }
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_truncated_or_corrupted_payload_is_refused() {
        let dir = temp("broken");
        std::fs::write(dir.join("joinery-drive"), vec![1u8; 4000]).unwrap();
        let mut bytes = Vec::new();
        pack(&[dir.join("joinery-drive")], &mut bytes).unwrap();
        let cut = &bytes[..bytes.len() / 2];
        assert!(unpack(cut, &dir.join("o1")).is_err());
        // One flipped bit in a header fails its checksum.
        let mut raw = Vec::new();
        GzDecoder::new(&bytes[..]).read_to_end(&mut raw).unwrap();
        raw[10] ^= 1;
        let mut gz = GzEncoder::new(Vec::new(), Compression::fast());
        gz.write_all(&raw).unwrap();
        assert!(unpack(&gz.finish().unwrap()[..], &dir.join("o2")).is_err());
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_long_path_in_a_bundle_survives_the_round_trip() {
        let dir = temp("long");
        let deep = dir
            .join("Joinery Drive.app/Contents/Resources")
            .join("a-rather-long-folder-name-for-localized-resources.lproj")
            .join("another-quite-long-folder-name-inside-it");
        std::fs::create_dir_all(&deep).unwrap();
        std::fs::write(deep.join("InfoPlist.strings-with-a-long-name-too"), b"s").unwrap();
        let mut bytes = Vec::new();
        pack(&[dir.join("Joinery Drive.app")], &mut bytes).unwrap();
        let out = dir.join("out");
        unpack(&bytes[..], &out).unwrap();
        let rel = deep.strip_prefix(&dir).unwrap();
        assert_eq!(
            std::fs::read(out.join(rel).join("InfoPlist.strings-with-a-long-name-too")).unwrap(),
            b"s"
        );
        let _ = std::fs::remove_dir_all(&dir);
    }
}
