//! joinery-drive-probe — the server surface, exercised directly.
//!
//!   joinery-drive-probe login <base-url> [--email E] [--device NAME]
//!   joinery-drive-probe status
//!   joinery-drive-probe list [folder_id] [--search TERM] [--trash]
//!   joinery-drive-probe get <file_id> [--folder ID] [--out PATH]
//!   joinery-drive-probe put <path> [--folder ID] [--name NAME] [--file-id ID]
//!   joinery-drive-probe logout
//!
//! Not the client — `joinery-drive` in `jd-daemon` is. This is the tool for
//! answering "is the server doing what the contract says" without an engine, a
//! state store, or a sync folder in the way, which is a different question and
//! worth being able to ask on its own. It is also the only way in to an
//! instance with a password and no browser, since the device-link ceremony the
//! real client uses needs one.
//!
//! Credentials live in a 0600 JSON config (default
//! `~/.config/joinery-drive/config.json`, override with
//! `JOINERY_DRIVE_CONFIG`) rather than the OS keychain — this is a probe, and
//! keeping it out of the keychain keeps it from disturbing the real client's
//! credentials. The password prompt reads from the terminal (or
//! `JOINERY_DRIVE_PASSWORD` for non-interactive gates) — never an argument.

use std::io::Write as _;
use std::process::ExitCode;

use serde::{Deserialize, Serialize};
use serde_json::{json, Value};

use jd_proto::{Client, Credentials, ProtoError};

#[derive(Serialize, Deserialize)]
struct ConfigFile {
    base_url: String,
    device_name: String,
    public_key: String,
    secret_key: String,
}

fn config_path() -> std::path::PathBuf {
    if let Ok(p) = std::env::var("JOINERY_DRIVE_CONFIG") {
        return p.into();
    }
    let home = std::env::var("HOME").unwrap_or_else(|_| ".".into());
    std::path::Path::new(&home).join(".config/joinery-drive/config.json")
}

fn load_config() -> Result<ConfigFile, String> {
    let path = config_path();
    let raw = std::fs::read_to_string(&path).map_err(|_| {
        format!(
            "not logged in ({} missing) — run: joinery-drive-probe login <url>",
            path.display()
        )
    })?;
    serde_json::from_str(&raw).map_err(|e| format!("config file is corrupt: {e}"))
}

fn save_config(cfg: &ConfigFile) -> Result<(), String> {
    let path = config_path();
    if let Some(dir) = path.parent() {
        std::fs::create_dir_all(dir)
            .map_err(|e| format!("cannot create {}: {e}", dir.display()))?;
    }
    let body = serde_json::to_string_pretty(cfg).expect("config serializes");
    std::fs::write(&path, body).map_err(|e| format!("cannot write {}: {e}", path.display()))?;
    #[cfg(unix)]
    {
        use std::os::unix::fs::PermissionsExt;
        let _ = std::fs::set_permissions(&path, std::fs::Permissions::from_mode(0o600));
    }
    Ok(())
}

fn client() -> Result<Client, String> {
    let cfg = load_config()?;
    Ok(Client::with_credentials(
        &cfg.base_url,
        Credentials {
            public_key: cfg.public_key,
            secret_key: cfg.secret_key,
        },
    ))
}

/// Tiny flag parser: positionals plus `--flag value` pairs.
struct Args {
    positional: Vec<String>,
    flags: std::collections::HashMap<String, String>,
}

fn parse_args(raw: &[String]) -> Result<Args, String> {
    let mut positional = Vec::new();
    let mut flags = std::collections::HashMap::new();
    let mut i = 0;
    while i < raw.len() {
        let a = &raw[i];
        if let Some(name) = a.strip_prefix("--") {
            if name == "trash" {
                flags.insert(name.to_string(), "1".to_string());
            } else {
                i += 1;
                let val = raw
                    .get(i)
                    .ok_or_else(|| format!("--{name} needs a value"))?;
                flags.insert(name.to_string(), val.clone());
            }
        } else {
            positional.push(a.clone());
        }
        i += 1;
    }
    Ok(Args { positional, flags })
}

fn human_size(bytes: u64) -> String {
    const UNITS: [&str; 5] = ["B", "KiB", "MiB", "GiB", "TiB"];
    let mut size = bytes as f64;
    let mut unit = 0;
    while size >= 1024.0 && unit < UNITS.len() - 1 {
        size /= 1024.0;
        unit += 1;
    }
    if unit == 0 {
        format!("{bytes} B")
    } else {
        format!("{size:.1} {}", UNITS[unit])
    }
}

fn main() -> ExitCode {
    let argv: Vec<String> = std::env::args().skip(1).collect();
    let (cmd, rest) = match argv.split_first() {
        Some((c, r)) => (c.as_str(), r.to_vec()),
        None => ("help", Vec::new()),
    };
    let outcome = match cmd {
        "login" => cmd_login(&rest),
        "status" => cmd_status(),
        "list" => cmd_list(&rest),
        "get" => cmd_get(&rest),
        "put" => cmd_put(&rest),
        "logout" => cmd_logout(),
        _ => {
            eprintln!(
                "usage: joinery-drive-probe login <base-url> [--email E] [--device NAME]\n\
                 \x20      joinery-drive-probe status\n\
                 \x20      joinery-drive-probe list [folder_id] [--search TERM] [--trash]\n\
                 \x20      joinery-drive-probe get <file_id> [--folder ID] [--out PATH]\n\
                 \x20      joinery-drive-probe put <path> [--folder ID] [--name NAME] [--file-id ID]\n\
                 \x20      joinery-drive-probe logout"
            );
            return ExitCode::from(2);
        }
    };
    match outcome {
        Ok(()) => ExitCode::SUCCESS,
        Err(msg) => {
            eprintln!("error: {msg}");
            ExitCode::FAILURE
        }
    }
}

fn api_err(e: ProtoError) -> String {
    e.to_string()
}

fn cmd_login(rest: &[String]) -> Result<(), String> {
    let args = parse_args(rest)?;
    let base_url = args
        .positional
        .first()
        .ok_or("login needs the instance base URL")?;
    let email = match args.flags.get("email") {
        Some(e) => e.clone(),
        None => {
            print!("email: ");
            std::io::stdout().flush().ok();
            let mut line = String::new();
            std::io::stdin()
                .read_line(&mut line)
                .map_err(|e| e.to_string())?;
            line.trim().to_string()
        }
    };
    let password = match std::env::var("JOINERY_DRIVE_PASSWORD") {
        Ok(p) if !p.is_empty() => p,
        _ => rpassword::prompt_password("password: ").map_err(|e| e.to_string())?,
    };
    let device = args.flags.get("device").cloned().unwrap_or_else(|| {
        let host = std::fs::read_to_string("/etc/hostname").unwrap_or_default();
        let host = host.trim();
        if host.is_empty() {
            "joinery-drive CLI".to_string()
        } else {
            format!("{host} (CLI)")
        }
    });

    let mut client = Client::new(base_url);
    let data = client.login(&email, &password, &device).map_err(api_err)?;
    let creds = client.credentials().expect("login installed credentials");
    save_config(&ConfigFile {
        base_url: client.base_url().to_string(),
        device_name: device.clone(),
        public_key: creds.public_key.clone(),
        secret_key: creds.secret_key.clone(),
    })?;
    println!(
        "logged in to {} as {} (device \"{}\", key expires {})",
        client.base_url(),
        data.get("user")
            .and_then(|u| u.get("email"))
            .and_then(Value::as_str)
            .unwrap_or(&email),
        device,
        data.get("expires_time")
            .and_then(Value::as_str)
            .unwrap_or("?"),
    );
    println!("credentials stored in {} (0600)", config_path().display());
    Ok(())
}

fn cmd_status() -> Result<(), String> {
    let cfg = load_config()?;
    let client = client()?;
    let data = client.session().map_err(api_err)?;
    println!("instance : {}", cfg.base_url);
    println!("device   : {}", cfg.device_name);
    println!(
        "user     : {} (id {}, permission {})",
        data.get("email").and_then(Value::as_str).unwrap_or("?"),
        data.get("user_id").and_then(Value::as_i64).unwrap_or(0),
        data.get("permission").and_then(Value::as_i64).unwrap_or(0),
    );
    if let Some(tier) = data
        .get("tier")
        .and_then(|t| t.get("display_name"))
        .and_then(Value::as_str)
    {
        println!("tier     : {tier}");
    }
    Ok(())
}

fn cmd_list(rest: &[String]) -> Result<(), String> {
    let args = parse_args(rest)?;
    let client = client()?;
    let folder_id: i64 = args
        .positional
        .first()
        .map(|s| s.parse().map_err(|_| format!("not a folder id: {s}")))
        .transpose()?
        .unwrap_or(0);

    let view = if args.flags.contains_key("trash") {
        "trash"
    } else if args.flags.contains_key("search") {
        "search"
    } else {
        "mine"
    };

    let mut offset = 0u64;
    let mut folders = 0u32;
    let mut files = 0u32;
    let mut usage: Option<Value> = None;
    loop {
        let mut body = json!({ "view": view, "folder_id": folder_id, "offset": offset });
        if let Some(term) = args.flags.get("search") {
            body["search"] = json!(term);
        }
        let data = client.action("drive_list", body).map_err(api_err)?;
        let items = data
            .get("items")
            .and_then(Value::as_array)
            .cloned()
            .unwrap_or_default();
        for item in &items {
            let entity = item
                .get("entity_type")
                .and_then(Value::as_str)
                .unwrap_or("?");
            let id = item.get("id").and_then(Value::as_i64).unwrap_or(0);
            let name = item
                .get("name")
                .and_then(Value::as_str)
                .unwrap_or("(encrypted)");
            let encrypted = if item.get("encrypted").and_then(Value::as_bool) == Some(true) {
                " [encrypted]"
            } else {
                ""
            };
            if entity == "folder" {
                folders += 1;
                println!("{id:>10}  {:>10}  {name}/{encrypted}", "folder");
            } else {
                files += 1;
                let size = item.get("size").and_then(Value::as_u64).unwrap_or(0);
                println!("{id:>10}  {:>10}  {name}{encrypted}", human_size(size));
            }
        }
        if usage.is_none() {
            usage = data.get("usage").cloned();
        }
        if data.get("truncated").and_then(Value::as_bool) == Some(true) && !items.is_empty() {
            offset += items.len() as u64;
        } else {
            break;
        }
    }
    println!("{folders} folder(s), {files} file(s)");
    if let Some(u) = usage {
        let used = u.get("bytes_used").and_then(Value::as_u64).unwrap_or(0);
        let quota = u.get("quota_bytes").and_then(Value::as_u64).unwrap_or(0);
        println!("usage: {} of {}", human_size(used), human_size(quota));
    }
    Ok(())
}

fn cmd_get(rest: &[String]) -> Result<(), String> {
    let args = parse_args(rest)?;
    let file_id: i64 = args
        .positional
        .first()
        .ok_or("get needs a file id")?
        .parse()
        .map_err(|_| "file id must be a number".to_string())?;
    let folder_id: i64 = args
        .flags
        .get("folder")
        .map(|s| s.parse().map_err(|_| format!("not a folder id: {s}")))
        .transpose()?
        .unwrap_or(0);

    let client = client()?;
    // No single-entity fetch until Phase 0's drive_stat lands — walk the
    // folder listing (offset-paged) for the id.
    let mut offset = 0u64;
    let found = loop {
        let data = client
            .action(
                "drive_list",
                json!({ "view": "mine", "folder_id": folder_id, "offset": offset }),
            )
            .map_err(api_err)?;
        let items = data
            .get("items")
            .and_then(Value::as_array)
            .cloned()
            .unwrap_or_default();
        let hit = items.iter().find(|item| {
            item.get("entity_type").and_then(Value::as_str) == Some("file")
                && item.get("id").and_then(Value::as_i64) == Some(file_id)
        });
        if let Some(item) = hit {
            break Some(item.clone());
        }
        if data.get("truncated").and_then(Value::as_bool) == Some(true) && !items.is_empty() {
            offset += items.len() as u64;
        } else {
            break None;
        }
    };
    let file = found.ok_or_else(|| {
        format!(
            "file {file_id} not found in folder {folder_id} (pass --folder for a non-root file)"
        )
    })?;

    if file.get("encrypted").and_then(Value::as_bool) == Some(true) {
        return Err(
            "file is in an encrypted vault folder — encrypted download rides Phase 4".into(),
        );
    }
    let url = file
        .get("download_url")
        .and_then(Value::as_str)
        .ok_or("file export carried no download_url")?;
    let name = file
        .get("name")
        .and_then(Value::as_str)
        .unwrap_or("download.bin");
    let out_path = args
        .flags
        .get("out")
        .cloned()
        .unwrap_or_else(|| name.to_string());

    let mut out =
        std::fs::File::create(&out_path).map_err(|e| format!("cannot create {out_path}: {e}"))?;
    let written = client.download_to(url, &mut out).map_err(api_err)?;
    println!("{out_path}: {} written", human_size(written));
    Ok(())
}

fn cmd_put(rest: &[String]) -> Result<(), String> {
    let args = parse_args(rest)?;
    let path = args
        .positional
        .first()
        .ok_or("put needs a local file path")?;
    let folder_id: Option<i64> = args
        .flags
        .get("folder")
        .map(|s| s.parse().map_err(|_| format!("not a folder id: {s}")))
        .transpose()?;
    let file_id: Option<i64> = args
        .flags
        .get("file-id")
        .map(|s| s.parse().map_err(|_| format!("not a file id: {s}")))
        .transpose()?;
    let name = match args.flags.get("name") {
        Some(n) => n.clone(),
        None => std::path::Path::new(path)
            .file_name()
            .and_then(|n| n.to_str())
            .ok_or("cannot derive a file name; pass --name")?
            .to_string(),
    };

    let (sha256, size_bytes) = jd_proto::sha256_reader(
        std::fs::File::open(path).map_err(|e| format!("cannot open {path}: {e}"))?,
    )
    .map_err(|e| e.to_string())?;

    let client = client()?;
    let reader = std::fs::File::open(path).map_err(|e| format!("cannot open {path}: {e}"))?;
    let outcome = client
        .upload_from_reader(
            &jd_proto::UploadParams {
                name: name.clone(),
                folder_id,
                file_id,
                size_bytes,
                sha256,
                mime_type: None,
                idempotency_key: None,
            },
            reader,
        )
        .map_err(api_err)?;

    let file = &outcome.file;
    println!(
        "{} → file id {}{} ({})",
        name,
        file.get("id").and_then(Value::as_i64).unwrap_or(0),
        if outcome.deduped {
            " (deduped — no bytes moved)"
        } else {
            ""
        },
        human_size(
            file.get("size")
                .and_then(Value::as_u64)
                .unwrap_or(size_bytes)
        ),
    );
    Ok(())
}

fn cmd_logout() -> Result<(), String> {
    let mut client = client()?;
    match client.logout() {
        Ok(_) => {}
        // an already-revoked/expired key still means "logged out" locally
        Err(ProtoError::Api { status: 401, .. }) => {}
        Err(e) => return Err(api_err(e)),
    }
    let path = config_path();
    std::fs::remove_file(&path).map_err(|e| format!("cannot remove {}: {e}", path.display()))?;
    println!("logged out; {} removed", path.display());
    Ok(())
}
