//! Draws the tray's pictures from the SVGs in `sync/packaging/icons`.
//!
//! One source for the art, and no binary file in the repository: the same SVGs
//! the Linux packages install for icon-theme lookup and the macOS build turns
//! into the app icon are rasterized here, at build time.
//!
//! - Every target: the four state icons as 32×32 straight RGBA, which the tray
//!   draws on Windows and hands to the Linux status notifier (`icons` in
//!   main.rs).
//! - Windows targets: the app icon as a multi-size `.ico`, and a compiled
//!   resource file holding it plus a version resource. Linked into
//!   `joinery-drive-tray.exe`, that is the icon Explorer, the Start menu entry
//!   and Apps & Features show, and the name and publisher Task Manager shows.
//!   The installer uses the same `.ico` (build.ps1 reads it from this crate's
//!   OUT_DIR).
//!
//! The resource file is written by `sync/packaging/windows/resource.rs`, which
//! the daemon's build script shares.

use std::path::{Path, PathBuf};

use resvg::{tiny_skia, usvg};

#[path = "../packaging/windows/resource.rs"]
mod resource;

const STATES: [&str; 4] = ["idle", "busy", "attention", "stopped"];
const STATE_SIZE: u32 = 32;
/// The sizes Windows asks an `.ico` for: 100%, 125%, 150%, 200% and 250% of the
/// 16 and 32 pixel slots, 48 for large icon views, 256 for the biggest tiles.
const APP_SIZES: [u32; 9] = [16, 20, 24, 32, 40, 48, 64, 128, 256];

fn main() {
    let manifest = PathBuf::from(env("CARGO_MANIFEST_DIR"));
    let icons = manifest.join("../packaging/icons");
    let out = PathBuf::from(env("OUT_DIR"));
    println!("cargo:rerun-if-changed={}", icons.display());
    println!("cargo:rerun-if-changed=../packaging/windows/resource.rs");

    for state in STATES {
        let svg = icons.join(format!("joinery-drive-{state}.svg"));
        let pixmap = render(&svg, STATE_SIZE);
        let straight: Vec<u8> = pixmap
            .pixels()
            .iter()
            .flat_map(|p| {
                let c = p.demultiply();
                [c.red(), c.green(), c.blue(), c.alpha()]
            })
            .collect();
        write(&out.join(format!("joinery-drive-{state}.rgba")), &straight);
    }

    if env("CARGO_CFG_TARGET_OS") != "windows" {
        return;
    }
    let app = icons.join("joinery-drive.svg");
    let images: Vec<(u32, Vec<u8>)> = APP_SIZES
        .iter()
        .map(|&size| {
            let png = render(&app, size)
                .encode_png()
                .unwrap_or_else(|e| panic!("encoding the {size}px app icon: {e}"));
            (size, png)
        })
        .collect();
    write(&out.join("joinery-drive.ico"), &resource::ico(&images));

    let res = out.join("joinery-drive-tray.res");
    write(
        &res,
        &resource::resource_file(
            &images,
            &resource::version_info("Joinery Drive", "joinery-drive-tray"),
        ),
    );
    if env("CARGO_CFG_TARGET_ENV") == "msvc" {
        println!(
            "cargo:rustc-link-arg-bin=joinery-drive-tray={}",
            res.display()
        );
    }
}

fn env(name: &str) -> String {
    std::env::var(name).unwrap_or_else(|_| panic!("cargo sets {name} for build scripts"))
}

fn write(path: &Path, bytes: &[u8]) {
    std::fs::write(path, bytes).unwrap_or_else(|e| panic!("writing {}: {e}", path.display()));
}

/// One SVG drawn into a square of `size` pixels.
fn render(svg: &Path, size: u32) -> tiny_skia::Pixmap {
    let data = std::fs::read(svg).unwrap_or_else(|e| panic!("reading {}: {e}", svg.display()));
    let tree = usvg::Tree::from_data(&data, &usvg::Options::default())
        .unwrap_or_else(|e| panic!("parsing {}: {e}", svg.display()));
    let mut pixmap = tiny_skia::Pixmap::new(size, size).expect("a non-empty square");
    let scale = size as f32 / tree.size().width().max(tree.size().height());
    resvg::render(
        &tree,
        tiny_skia::Transform::from_scale(scale, scale),
        &mut pixmap.as_mut(),
    );
    pixmap
}
