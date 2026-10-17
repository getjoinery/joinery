//! Print the systemd user unit for a given install path.
//!
//!   cargo run -p jd-platform --example systemd_unit -- /usr/bin/joinery-drive
//!
//! The Linux packages (sync/packaging/linux) ship the unit this prints, so the
//! copy a package installs and the copy `joinery-drive autostart on` writes come
//! from one function and cannot drift apart.

fn main() {
    let exe = std::env::args()
        .nth(1)
        .unwrap_or_else(|| "/usr/bin/joinery-drive".to_string());
    print!(
        "{}",
        jd_platform::systemd_user_unit(std::path::Path::new(&exe))
    );
}
