//! Windows resource files, written directly.
//!
//! Shared by the build scripts of the two programs (`jd-shell/build.rs` for the
//! tray's icon and version, `jd-daemon/build.rs` for the daemon's version),
//! each of which includes it with `#[path]`. Plain `std`, so the daemon's build
//! — which every gate and soak build runs — pays nothing for it.
//!
//! The format is a few fixed headers, so the files are written here rather
//! than compiled by `rc.exe`: the build needs no Windows SDK tool to find on
//! an ARM64 host, and MSVC's linker takes a `.res` among its inputs as it is.

fn env(name: &str) -> String {
    std::env::var(name).unwrap_or_else(|_| panic!("cargo sets {name} for build scripts"))
}

/// An `.ico` file of PNG images (Windows Vista and later read PNG entries).
pub fn ico(images: &[(u32, Vec<u8>)]) -> Vec<u8> {
    let mut out = Vec::new();
    out.extend(0u16.to_le_bytes()); // reserved
    out.extend(1u16.to_le_bytes()); // type: icon
    out.extend((images.len() as u16).to_le_bytes());
    let mut offset = 6 + 16 * images.len();
    for (size, png) in images {
        out.extend([dimension(*size), dimension(*size), 0, 0]);
        out.extend(1u16.to_le_bytes()); // planes
        out.extend(32u16.to_le_bytes()); // bits per pixel
        out.extend((png.len() as u32).to_le_bytes());
        out.extend((offset as u32).to_le_bytes());
        offset += png.len();
    }
    for (_, png) in images {
        out.extend(png);
    }
    out
}

/// A width or height as an icon directory stores it: 256 is written as 0.
fn dimension(size: u32) -> u8 {
    if size >= 256 {
        0
    } else {
        size as u8
    }
}

const RT_ICON: u16 = 3;
const RT_GROUP_ICON: u16 = 14;
const RT_VERSION: u16 = 16;

/// A 32-bit resource (`.res`) file: each image as an `RT_ICON`, one
/// `RT_GROUP_ICON` listing them (the program's icon is the first group), and
/// the version resource. With no images, the version resource alone.
pub fn resource_file(images: &[(u32, Vec<u8>)], version: &[u8]) -> Vec<u8> {
    let mut out = Vec::new();
    // The file starts with an empty entry, which is how a reader tells a 32-bit
    // resource file from a 16-bit one.
    resource(&mut out, 0, 0, 0, 0, &[]);
    if images.is_empty() {
        resource(&mut out, RT_VERSION, 1, 0x0030, 0x0409, version);
        return out;
    }
    for (i, (_, png)) in images.iter().enumerate() {
        resource(&mut out, RT_ICON, i as u16 + 1, 0x1010, 0x0409, png);
    }
    let mut group = Vec::new();
    group.extend(0u16.to_le_bytes());
    group.extend(1u16.to_le_bytes());
    group.extend((images.len() as u16).to_le_bytes());
    for (i, (size, png)) in images.iter().enumerate() {
        group.extend([dimension(*size), dimension(*size), 0, 0]);
        group.extend(1u16.to_le_bytes());
        group.extend(32u16.to_le_bytes());
        group.extend((png.len() as u32).to_le_bytes());
        group.extend((i as u16 + 1).to_le_bytes());
    }
    resource(&mut out, RT_GROUP_ICON, 1, 0x1030, 0x0409, &group);
    resource(&mut out, RT_VERSION, 1, 0x0030, 0x0409, version);
    out
}

/// One entry: a fixed 32-byte header naming type and id by number, then the
/// data, padded to four bytes.
fn resource(out: &mut Vec<u8>, kind: u16, id: u16, flags: u16, language: u16, data: &[u8]) {
    out.extend((data.len() as u32).to_le_bytes());
    out.extend(32u32.to_le_bytes()); // header size
    out.extend(0xFFFFu16.to_le_bytes());
    out.extend(kind.to_le_bytes());
    out.extend(0xFFFFu16.to_le_bytes());
    out.extend(id.to_le_bytes());
    out.extend(0u32.to_le_bytes()); // data version
    out.extend(flags.to_le_bytes());
    out.extend(language.to_le_bytes());
    out.extend(0u32.to_le_bytes()); // version
    out.extend(0u32.to_le_bytes()); // characteristics
    out.extend(data);
    align4(out);
}

/// `VS_VERSIONINFO` for the program `name` (`name.exe`), described as
/// `description`: the numbers Explorer's Details tab shows, and the description
/// and company Task Manager shows for the process and for a logon entry naming
/// it. The version is the package's own, which is the workspace version.
pub fn version_info(description: &str, name: &str) -> Vec<u8> {
    let part = |name: &str| -> u16 { env(name).parse().expect("a version number part") };
    let (major, minor, patch) = (
        part("CARGO_PKG_VERSION_MAJOR"),
        part("CARGO_PKG_VERSION_MINOR"),
        part("CARGO_PKG_VERSION_PATCH"),
    );
    let ms = (u32::from(major) << 16) | u32::from(minor);
    let ls = u32::from(patch) << 16;
    let mut fixed = Vec::new();
    for word in [
        0xFEEF_04BDu32, // signature
        0x0001_0000,    // structure version
        ms,
        ls, // file version
        ms,
        ls,          // product version
        0x3F,        // flags mask
        0,           // flags
        0x0004_0004, // VOS_NT_WINDOWS32
        1,           // VFT_APP
        0,
        0,
        0, // subtype, date
    ] {
        fixed.extend(word.to_le_bytes());
    }

    let version = env("CARGO_PKG_VERSION");
    let file_name = format!("{name}.exe");
    let strings: Vec<Vec<u8>> = [
        ("CompanyName", "Joinery"),
        ("FileDescription", description),
        ("FileVersion", version.as_str()),
        ("InternalName", name),
        ("LegalCopyright", "Joinery"),
        ("OriginalFilename", file_name.as_str()),
        ("ProductName", "Joinery Drive"),
        ("ProductVersion", version.as_str()),
    ]
    .iter()
    .map(|(key, value)| {
        let text = utf16z(value);
        node(key, 1, &text, (text.len() / 2) as u16, &[])
    })
    .collect();
    // U.S. English, Unicode: the one translation the strings are in.
    let table = node("040904B0", 1, &[], 0, &strings);
    let string_info = node("StringFileInfo", 1, &[], 0, &[table]);
    let mut translation = Vec::new();
    translation.extend(0x0409u16.to_le_bytes());
    translation.extend(1200u16.to_le_bytes());
    let var = node("Translation", 0, &translation, 4, &[]);
    let var_info = node("VarFileInfo", 1, &[], 0, &[var]);
    node("VS_VERSION_INFO", 0, &fixed, 52, &[string_info, var_info])
}

/// One block of the version resource: length, value length, type (0 binary, 1
/// text), a NUL-terminated UTF-16 key, the value, then children, each starting
/// on a four-byte boundary. A block's length does not count padding after it.
fn node(key: &str, kind: u16, value: &[u8], value_len: u16, children: &[Vec<u8>]) -> Vec<u8> {
    let mut b = Vec::new();
    b.extend([0, 0]); // length, filled in below
    b.extend(value_len.to_le_bytes());
    b.extend(kind.to_le_bytes());
    b.extend(utf16z(key));
    align4(&mut b);
    b.extend(value);
    for child in children {
        align4(&mut b);
        b.extend(child);
    }
    let len = b.len() as u16;
    b[0..2].copy_from_slice(&len.to_le_bytes());
    b
}

fn utf16z(s: &str) -> Vec<u8> {
    s.encode_utf16()
        .chain(std::iter::once(0))
        .flat_map(u16::to_le_bytes)
        .collect()
}

fn align4(b: &mut Vec<u8>) {
    while !b.len().is_multiple_of(4) {
        b.push(0);
    }
}
