//! On Windows targets, the daemon's version resource: the description Task
//! Manager shows for the background process ("Joinery Drive sync", rather than
//! a bare file name) and the version Explorer's Details tab shows. Elsewhere,
//! nothing.
//!
//! Written by the resource writer the tray's build script uses
//! (`sync/packaging/windows/resource.rs`); the tray also carries the app icon,
//! the daemon only its version. Linked into `joinery-drive.exe` alone, not
//! the release tool.

use std::path::PathBuf;

#[path = "../packaging/windows/resource.rs"]
#[allow(dead_code)] // the icon half is the tray's
mod resource;

fn main() {
    println!("cargo:rerun-if-changed=../packaging/windows/resource.rs");
    if std::env::var("CARGO_CFG_TARGET_OS").as_deref() != Ok("windows") {
        return;
    }
    let out = PathBuf::from(std::env::var("OUT_DIR").expect("cargo sets OUT_DIR"));
    let res = out.join("joinery-drive.res");
    let bytes = resource::resource_file(
        &[],
        &resource::version_info("Joinery Drive sync", "joinery-drive"),
    );
    std::fs::write(&res, bytes).unwrap_or_else(|e| panic!("writing {}: {e}", res.display()));
    if std::env::var("CARGO_CFG_TARGET_ENV").as_deref() == Ok("msvc") {
        println!("cargo:rustc-link-arg-bin=joinery-drive={}", res.display());
    }
}
