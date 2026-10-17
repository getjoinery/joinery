//! `joinery-drive login`, run as the real binary.
//!
//! The address check is the last thing between a typed address and a request
//! that, once approved, carries this computer's credential. So it is tested
//! where a person meets it — the command line — and not only as a function.
//! Nothing here reaches a network: a refused address sends nothing, and the
//! one accepted address is a port on this machine that nothing listens on.

use std::process::Command;

/// Run `login <url>` with its own state directory and no display, and return
/// whether it succeeded and what it said on stderr.
fn login(tag: &str, url: &str) -> (bool, String, std::path::PathBuf) {
    let home = std::env::temp_dir().join(format!("jd-login-cli-{tag}-{}", std::process::id()));
    let _ = std::fs::remove_dir_all(&home);
    let out = Command::new(env!("CARGO_BIN_EXE_joinery-drive"))
        .args(["login", url])
        .env("JOINERY_DRIVE_HOME", &home)
        .env_remove("DISPLAY")
        .env_remove("WAYLAND_DISPLAY")
        .output()
        .expect("the binary runs");
    (
        out.status.success(),
        String::from_utf8_lossy(&out.stderr).to_string(),
        home,
    )
}

#[test]
fn a_plain_http_site_is_refused_and_the_person_is_told_why() {
    let (ok, said, home) = login("http", "http://drive.example.org");
    assert!(!ok);
    assert!(said.contains("https://"), "{said}");
    assert!(
        said.contains("unencrypted"),
        "says why, in plain words: {said}"
    );
    // Refused before anything was set up, let alone sent.
    assert!(!home.exists(), "a refused address left state behind");
}

#[test]
fn plain_http_to_this_machine_is_allowed_through() {
    // There is no network between a process and its own loopback. Port 1 needs
    // privileges to bind, so nothing is there: the request is made and fails,
    // which is exactly what shows the address check let it through.
    let (ok, said, home) = login("loopback", "http://127.0.0.1:1");
    assert!(!ok);
    assert!(!said.contains("unencrypted"), "{said}");
    assert!(said.contains("device link"), "{said}");
    let _ = std::fs::remove_dir_all(&home);
}

/// A stand-in for the site's device-link and session endpoints, on loopback:
/// each link it starts is approved at the first poll with the next key pair
/// (`pk-1`/`sk-1`, then `pk-2`/`sk-2`), every key belongs to account 7, and
/// it notes each key it is asked to revoke.
struct FakeSite {
    base: String,
    revoked: std::sync::Arc<std::sync::Mutex<Vec<(String, String)>>>,
}

impl FakeSite {
    fn start() -> FakeSite {
        use std::io::{Read, Write};
        let listener = std::net::TcpListener::bind("127.0.0.1:0").unwrap();
        let base = format!("http://{}", listener.local_addr().unwrap());
        let revoked = std::sync::Arc::new(std::sync::Mutex::new(Vec::new()));
        let noted = std::sync::Arc::clone(&revoked);
        std::thread::spawn(move || {
            let mut links = 0;
            for stream in listener.incoming() {
                let Ok(mut stream) = stream else { continue };
                let _ = stream.set_read_timeout(Some(std::time::Duration::from_secs(5)));
                let mut raw = Vec::new();
                let mut buf = [0u8; 4096];
                let head_end = loop {
                    if let Some(i) = raw.windows(4).position(|w| w == b"\r\n\r\n") {
                        break i + 4;
                    }
                    match stream.read(&mut buf) {
                        Ok(0) | Err(_) => break raw.len(),
                        Ok(n) => raw.extend_from_slice(&buf[..n]),
                    }
                };
                let head = String::from_utf8_lossy(&raw[..head_end]).to_string();
                let header = |name: &str| {
                    head.lines()
                        .find_map(|l| {
                            let (k, v) = l.split_once(':')?;
                            k.eq_ignore_ascii_case(name).then(|| v.trim().to_string())
                        })
                        .unwrap_or_default()
                };
                let length: usize = header("content-length").parse().unwrap_or(0);
                while raw.len() < head_end + length {
                    match stream.read(&mut buf) {
                        Ok(0) | Err(_) => break,
                        Ok(n) => raw.extend_from_slice(&buf[..n]),
                    }
                }
                let line = head.lines().next().unwrap_or("").to_string();
                let body = if line.starts_with("POST /api/v1/auth/device_link/") {
                    format!(
                        r#"{{"data":{{"status":"approved","public_key":"pk-{links}","secret_key":"sk-{links}","device_id":{links}}}}}"#
                    )
                } else if line.starts_with("POST /api/v1/auth/device_link ") {
                    links += 1;
                    // Not a web address, so nothing tries to open it in a browser.
                    format!(
                        r#"{{"data":{{"link_code":"ABCD-EFGH","poll_token":"tok-{links}","verify_url":"test:approve","expires_time":""}}}}"#
                    )
                } else if line.starts_with("GET /api/v1/auth/session ") {
                    r#"{"data":{"user_id":7}}"#.to_string()
                } else if line.starts_with("POST /api/v1/auth/logout ") {
                    noted
                        .lock()
                        .unwrap()
                        .push((header("public-key"), header("secret-key")));
                    r#"{"data":{}}"#.to_string()
                } else {
                    r#"{"data":{}}"#.to_string()
                };
                let _ = write!(
                    stream,
                    "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{body}",
                    body.len()
                );
            }
        });
        FakeSite { base, revoked }
    }
}

/// Run `login` against `site` into `home`, syncing `root`.
fn login_to(site: &FakeSite, home: &std::path::Path, root: &std::path::Path) -> (bool, String) {
    let out = Command::new(env!("CARGO_BIN_EXE_joinery-drive"))
        .args(["login", &site.base, "--root"])
        .arg(root)
        .args(["--device", "Laptop"])
        .env("JOINERY_DRIVE_HOME", home)
        .env_remove("DISPLAY")
        .env_remove("WAYLAND_DISPLAY")
        .output()
        .expect("the binary runs");
    (
        out.status.success(),
        format!(
            "{}{}",
            String::from_utf8_lossy(&out.stdout),
            String::from_utf8_lossy(&out.stderr)
        ),
    )
}

#[test]
fn logging_in_again_takes_the_old_device_off_the_account_and_keeps_the_folders_settings() {
    // Running login again — to turn on encrypted folders, or because a vault
    // key went wrong — used to leave the old key live on the account, with
    // nothing on this computer able to name it any more.
    let site = FakeSite::start();
    let home = std::env::temp_dir().join(format!("jd-login-again-{}", std::process::id()));
    let _ = std::fs::remove_dir_all(&home);
    let root = home.join("root");

    let (ok, said) = login_to(&site, &home, &root);
    assert!(ok, "{said}");
    assert!(
        site.revoked.lock().unwrap().is_empty(),
        "nothing to replace yet: {said}"
    );

    let config_file = home.join("config/config.json");
    let mut config: serde_json::Value =
        serde_json::from_str(&std::fs::read_to_string(&config_file).unwrap()).unwrap();
    assert_eq!(config["public_key"], "pk-1");
    assert_eq!(
        config["account_id"], 7,
        "the account is recorded at link time"
    );
    config["excluded"] = serde_json::json!(["Photos"]);
    std::fs::write(&config_file, config.to_string()).unwrap();

    let (ok, said) = login_to(&site, &home, &root);
    assert!(ok, "{said}");
    assert_eq!(
        *site.revoked.lock().unwrap(),
        vec![("pk-1".to_string(), "sk-1".to_string())],
        "the old key, and only it, was revoked with its own credential: {said}"
    );
    assert!(said.contains("taken off the account"), "{said}");

    let config: serde_json::Value =
        serde_json::from_str(&std::fs::read_to_string(&config_file).unwrap()).unwrap();
    assert_eq!(config["public_key"], "pk-2");
    assert_eq!(
        config["excluded"],
        serde_json::json!(["Photos"]),
        "the same drive in the same folder keeps what it was set to"
    );

    // The keys of the second link are what is held now, wherever this session
    // keeps them; tidy them away so a desktop run leaves nothing in the
    // keychain.
    let secrets = jd_platform::SecretStore::for_installation(&jd_platform::Paths {
        config: home.join("config"),
        state: home.join("state"),
        logs: home.join("logs"),
    });
    assert_eq!(secrets.get(jd_platform::Secret::ApiSecret).unwrap(), "sk-2");
    secrets.clear().unwrap();
    let _ = std::fs::remove_dir_all(&home);
}
