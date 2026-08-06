//! `joinery-drive` — the command line around the daemon.
//!
//!   joinery-drive login <instance-url> [--device NAME] [--root PATH]
//!   joinery-drive daemon        run the sync loop (what autostart runs)
//!   joinery-drive status        what it is doing, and whether that is fine
//!   joinery-drive issues        things needing a person, with what to do
//!   joinery-drive dismiss <id>
//!   joinery-drive pause | resume | sync-now | stop
//!   joinery-drive autostart on|off
//!   joinery-drive unlink        revoke this device and forget its keys
//!
//! Everything except `login` and `daemon` is a request to a running daemon, so
//! the answer to every one of them is whatever the daemon actually believes
//! rather than a second opinion computed here. Two programs reading the same
//! state store and disagreeing about it is not a bug worth being able to have.

use std::process::ExitCode;
use std::sync::mpsc::channel;
use std::sync::{Arc, Mutex};

use jd_core::store::Store;
use jd_daemon::config::Config;
use jd_daemon::control::{ask, ControlServer, Endpoint};
use jd_daemon::daemon::{handle_control, Daemon, Shared};
use jd_daemon::link;
use jd_platform::{Paths, Secret, SecretStore};
use jd_proto::{Client, Credentials};
use jd_vfs::OsVfs;
use serde_json::{json, Value};

const SERVICE: &str = "com.joinery.drive";

fn main() -> ExitCode {
    let argv: Vec<String> = std::env::args().skip(1).collect();
    let (cmd, rest) = match argv.split_first() {
        Some((c, r)) => (c.as_str(), r.to_vec()),
        None => ("help", Vec::new()),
    };

    let outcome = match cmd {
        "login" => cmd_login(&rest),
        "daemon" => cmd_daemon(),
        "status" => cmd_status(),
        "issues" => cmd_issues(),
        "dismiss" => cmd_dismiss(&rest),
        "pause" => simple_command("/pause", "Syncing paused."),
        "resume" => simple_command("/resume", "Syncing resumed."),
        "sync-now" => simple_command("/sync-now", "Checking now."),
        "stop" => simple_command("/stop", "Stopping."),
        "autostart" => cmd_autostart(&rest),
        "unlink" => cmd_unlink(),
        _ => {
            eprintln!("{USAGE}");
            return ExitCode::from(2);
        }
    };

    match outcome {
        Ok(()) => ExitCode::SUCCESS,
        Err(message) => {
            eprintln!("error: {message}");
            ExitCode::FAILURE
        }
    }
}

const USAGE: &str = "\
usage: joinery-drive login <instance-url> [--device NAME] [--root PATH]
       joinery-drive daemon
       joinery-drive status | issues | dismiss <id>
       joinery-drive pause | resume | sync-now | stop
       joinery-drive autostart on|off
       joinery-drive unlink";

// ---------------------------------------------------------------------------
// Linking
// ---------------------------------------------------------------------------

fn cmd_login(rest: &[String]) -> Result<(), String> {
    let args = Args::parse(rest)?;
    let base_url = args
        .positional
        .first()
        .ok_or("login needs the address of your Joinery instance, e.g. https://drive.example.com")?
        .trim_end_matches('/')
        .to_string();

    let paths = Paths::discover();
    paths.create().map_err(|e| e.to_string())?;

    let device_name = args
        .flag("device")
        .unwrap_or_else(jd_platform::suggested_device_name);
    let sync_root = args
        .flag("root")
        .map(std::path::PathBuf::from)
        .unwrap_or_else(jd_platform::default_sync_root);

    let client = Client::new(&base_url);
    let pending = link::begin(&client, &device_name, jd_platform::platform_name())
        .map_err(|e| e.to_string())?;

    // The code is printed whether or not a browser opened, because the ceremony
    // works perfectly well with a person reading it off one screen and typing it
    // into another — which is the only way it works on a headless box.
    println!("To link this computer, approve it while signed in to {base_url}:");
    println!("\n    {}\n", pending.verify_url);
    println!("    or enter the code: {}", pending.link_code);
    if jd_platform::browser::open_url(&pending.verify_url) {
        println!("\n(opened in your browser)");
    }
    println!("\nWaiting for approval…");

    let approved = loop {
        std::thread::sleep(link::POLL_INTERVAL);
        match link::poll(&client, &pending).map_err(|e| e.to_string())? {
            link::Outcome::Pending => continue,
            link::Outcome::Approved(a) => break a,
            link::Outcome::Denied => return Err("the link was declined".into()),
            link::Outcome::Expired => {
                return Err("the code expired — run login again to get a new one".into())
            }
        }
    };

    // Secrets first, and before anything that could fail. The server hands the
    // credential over exactly once and scrubs it; a config written before the
    // secret was stored would leave a client that believes it is linked and
    // holds nothing to prove it.
    let secrets = SecretStore::open(SERVICE, &paths.state);
    secrets
        .set(Secret::ApiSecret, &approved.credentials.secret_key)
        .map_err(|e| format!("cannot store the device credential: {e}"))?;
    secrets
        .set(
            Secret::DeviceKey,
            &encode_base64(&pending.device_secret_pkcs8),
        )
        .map_err(|e| format!("cannot store the device key: {e}"))?;

    let mut vault_enabled = false;
    if let Some(sealed) = &approved.sealed_vault_key {
        match link::open_vault_key(&pending, sealed) {
            Ok(vault_key) => {
                secrets
                    .set(Secret::VaultKey, &encode_base64(&vault_key))
                    .map_err(|e| format!("cannot store the vault key: {e}"))?;
                vault_enabled = true;
            }
            // Not fatal. Everything outside encrypted folders syncs perfectly
            // well without it, and refusing to link over this would be refusing
            // the whole account over one feature.
            Err(e) => eprintln!(
                "warning: encrypted folders could not be enabled on this device ({e}). \
                 Everything else will sync; link again to retry."
            ),
        }
    }

    std::fs::create_dir_all(&sync_root)
        .map_err(|e| format!("cannot create {}: {e}", sync_root.display()))?;

    Config {
        base_url: base_url.clone(),
        sync_root: sync_root.clone(),
        device_name: device_name.clone(),
        device_id: approved.device_id,
        public_key: approved.credentials.public_key.clone(),
        poll_seconds: jd_daemon::config::DEFAULT_POLL_SECONDS,
        excluded: Vec::new(),
        vault_enabled,
        autostart: false,
    }
    .save(&paths.config_file())
    .map_err(|e| e.to_string())?;

    println!("\nLinked as \u{201c}{device_name}\u{201d}.");
    println!("Syncing {} \u{2194} {base_url}", sync_root.display());
    println!("Keys are stored {}.", secrets.custody().describe());
    if vault_enabled {
        println!("Encrypted folders are enabled on this device.");
    }
    println!("\nStart syncing with:  joinery-drive daemon");
    Ok(())
}

// ---------------------------------------------------------------------------
// Running
// ---------------------------------------------------------------------------

fn cmd_daemon() -> Result<(), String> {
    let paths = Paths::discover();
    paths.create().map_err(|e| e.to_string())?;
    let config = Config::load(&paths.config_file()).map_err(|e| e.to_string())?;

    let secrets = SecretStore::open(SERVICE, &paths.state);
    let secret_key = secrets.get(Secret::ApiSecret).map_err(|e| match e {
        // Not "your credential is missing". It is very probably right where it
        // was put, in a keychain this session cannot open — which is what
        // happens to a daemon started over SSH, or at boot before anyone has
        // logged in to the desktop. Telling the user to re-link would send them
        // to replace something that is not lost.
        jd_platform::SecretError::Locked(why) => why,
        other => format!("cannot read this device's credential: {other}"),
    })?;
    let credentials = Credentials {
        public_key: config.public_key.clone(),
        secret_key,
    };

    // Read once, at startup, and held for the life of the process: on macOS
    // every read of the keychain is a potential prompt, and one per file is not
    // a thing to do to somebody.
    let vault = load_vault(&secrets, config.vault_enabled);

    let store = Store::open(&paths.state_db()).map_err(|e| e.to_string())?;
    let vfs = OsVfs::new(config.sync_root.clone(), paths.spool()).map_err(|e| e.to_string())?;

    let (tx, rx) = channel();
    let shared = Arc::new(Shared {
        snapshot: Mutex::new(None),
        commands: Mutex::new(tx),
    });

    let server = ControlServer::bind(&paths.state.join("control.json"), control_token())
        .map_err(|e| format!("cannot open the control channel: {e}"))?;
    println!(
        "joinery-drive: syncing {} with {} (control on 127.0.0.1:{})",
        config.sync_root.display(),
        config.base_url,
        server.port()
    );

    // The control thread holds no database handle at all, so a hung tray or
    // twenty CLI calls at once can slow nothing down and corrupt nothing.
    let control_shared = Arc::clone(&shared);
    std::thread::spawn(move || loop {
        if server
            .serve_one(&mut |req| handle_control(&control_shared, req))
            .is_err()
        {
            // One failed connection is not a reason to stop answering; the
            // listener is still good.
            continue;
        }
    });

    let custody = secrets.custody().describe().to_string();
    Daemon::new(config, store, vfs, credentials, shared, rx, custody, vault).run();
    Ok(())
}

/// Pick up the key for encrypted folders, if this device has one.
///
/// Never fatal, in any of its failure modes, and that is the whole design of
/// this function. Everything outside encrypted folders syncs perfectly well
/// without a vault key, so refusing to start over a missing or unreadable one
/// would take an entire account offline over one feature. What it does instead
/// is say which of the three situations it is in, because they need different
/// things from the user: nothing at all, link again, or look at the keychain.
fn load_vault(secrets: &SecretStore, expected: bool) -> Option<jd_core::vault::Vault> {
    let stored = match secrets.get(Secret::VaultKey) {
        Ok(s) => s,
        Err(e) => {
            // Silent when the config never claimed to have one: a user who has
            // not turned on encrypted folders does not want to be told about
            // them every time the daemon starts.
            if expected {
                eprintln!(
                    "warning: encrypted folders are enabled for this device but its key could not \
                     be read ({e}). Everything else will sync; link again to restore it."
                );
            }
            return None;
        }
    };
    let raw = match jd_crypto::b64::decode(&stored) {
        Ok(r) => r,
        Err(_) => {
            eprintln!(
                "warning: this device's stored vault key is not readable. Everything outside \
                 encrypted folders will sync; link again to restore it."
            );
            return None;
        }
    };
    match jd_core::vault::Vault::from_secret_key(&raw) {
        Ok(v) => Some(v),
        Err(e) => {
            eprintln!(
                "warning: {e}. Everything outside encrypted folders will sync; link again to \
                 restore it."
            );
            None
        }
    }
}

// ---------------------------------------------------------------------------
// Asking the running daemon
// ---------------------------------------------------------------------------

/// The endpoint of the running daemon, or advice about the fact that there is
/// not one.
fn endpoint() -> Result<Endpoint, String> {
    let paths = Paths::discover();
    Endpoint::load(&paths.state.join("control.json")).ok_or_else(|| {
        "the sync daemon is not running — start it with: joinery-drive daemon".to_string()
    })
}

fn simple_command(path: &str, said: &str) -> Result<(), String> {
    let answer = ask(&endpoint()?, "POST", path, Value::Null)
        .ok_or("the sync daemon did not answer".to_string())?;
    if answer.get("ok").and_then(Value::as_bool) == Some(true) {
        println!("{said}");
        Ok(())
    } else {
        Err(answer
            .get("error")
            .and_then(Value::as_str)
            .unwrap_or("the daemon refused")
            .to_string())
    }
}

fn cmd_status() -> Result<(), String> {
    let answer = ask(&endpoint()?, "GET", "/status", Value::Null)
        .ok_or("the sync daemon did not answer".to_string())?;

    let get = |k: &str| {
        answer
            .get(k)
            .and_then(Value::as_str)
            .unwrap_or("")
            .to_string()
    };
    println!("{}", get("summary"));
    println!();
    println!("  instance : {}", get("base_url"));
    println!("  device   : {}", get("device_name"));
    println!("  folder   : {}", get("sync_root"));
    println!("  keys     : {}", get("custody"));
    println!(
        "  encrypted: {}",
        match answer.get("vault").and_then(Value::as_bool) {
            Some(true) => "folders can be opened on this device".to_string(),
            _ => "not enabled here — link again to turn on encrypted folders".to_string(),
        }
    );
    println!(
        "  items    : {} of {} settled",
        answer.get("settled").and_then(Value::as_u64).unwrap_or(0),
        answer.get("tracked").and_then(Value::as_u64).unwrap_or(0),
    );
    if let Some(pending) = answer.get("pending_ops").and_then(Value::as_u64) {
        if pending > 0 {
            println!("  waiting  : {pending} transfers");
        }
    }
    if let Some(blocker) = answer.get("blocker").and_then(Value::as_str) {
        println!("\n  {blocker}");
    }
    // The total, not the length of the list — the answer carries at most fifty
    // of them, and saying "50" when there are three hundred is the
    // understatement the whole health model exists to prevent.
    let issues = answer
        .get("issues_total")
        .and_then(Value::as_u64)
        .unwrap_or_else(|| {
            answer
                .get("issues")
                .and_then(Value::as_array)
                .map(|list| list.len() as u64)
                .unwrap_or(0)
        });
    if issues > 0 {
        println!("\n  {issues} issue(s) — see: joinery-drive issues");
    }

    // A stopped client exits non-zero so that a monitoring script does not have
    // to parse prose to notice.
    if answer.get("indicator").and_then(Value::as_str) == Some("stopped") {
        return Err(get("summary"));
    }
    Ok(())
}

fn cmd_issues() -> Result<(), String> {
    let answer = ask(&endpoint()?, "GET", "/status", Value::Null)
        .ok_or("the sync daemon did not answer".to_string())?;
    let issues = answer
        .get("issues")
        .and_then(Value::as_array)
        .cloned()
        .unwrap_or_default();
    if issues.is_empty() {
        println!("Nothing needs your attention.");
        return Ok(());
    }
    for issue in &issues {
        println!(
            "[{}] {}",
            issue.get("id").and_then(Value::as_i64).unwrap_or(0),
            issue.get("summary").and_then(Value::as_str).unwrap_or(""),
        );
    }
    // Said out loud when the list is not all of them. Printing fifty lines and
    // stopping would read as fifty being all there is.
    let total = answer
        .get("issues_total")
        .and_then(Value::as_u64)
        .unwrap_or(issues.len() as u64);
    if total > issues.len() as u64 {
        println!(
            "\n…and {} more. Dealing with these will reveal the rest.",
            total - issues.len() as u64
        );
    }
    println!("\nDismiss one with: joinery-drive dismiss <id>");
    Ok(())
}

fn cmd_dismiss(rest: &[String]) -> Result<(), String> {
    let id: i64 = rest
        .first()
        .ok_or("dismiss needs an issue id — see: joinery-drive issues")?
        .parse()
        .map_err(|_| "an issue id is a number".to_string())?;
    ask(&endpoint()?, "POST", "/dismiss", json!({ "issue_id": id }))
        .ok_or("the sync daemon did not answer".to_string())?;
    println!("Dismissed.");
    Ok(())
}

// ---------------------------------------------------------------------------
// Installation
// ---------------------------------------------------------------------------

fn cmd_autostart(rest: &[String]) -> Result<(), String> {
    let home = jd_platform::home_dir();
    let exe = std::env::current_exe().map_err(|e| format!("cannot find my own path: {e}"))?;
    match rest.first().map(String::as_str) {
        Some("on") => {
            jd_platform::autostart::enable(&exe, &home).map_err(|e| e.to_string())?;
            println!("Joinery Drive will start when you log in.");
        }
        Some("off") => {
            jd_platform::autostart::disable(&home).map_err(|e| e.to_string())?;
            println!("Joinery Drive will not start automatically.");
        }
        _ => {
            let on = jd_platform::autostart::is_enabled(&home);
            println!("autostart is {}", if on { "on" } else { "off" });
        }
    }
    Ok(())
}

fn cmd_unlink() -> Result<(), String> {
    let paths = Paths::discover();

    // Stop first. A daemon still running against a credential we are about to
    // revoke would spend the next minute failing every request and reporting
    // itself broken.
    if let Ok(endpoint) = endpoint() {
        let _ = ask(&endpoint, "POST", "/stop", Value::Null);
    }

    let config = Config::load(&paths.config_file()).map_err(|e| e.to_string())?;
    let secrets = SecretStore::open(SERVICE, &paths.state);

    // Revoke server-side before forgetting locally. The other order leaves a
    // live credential on the server that nothing on this machine can name any
    // more, which is exactly the key a lost laptop is carrying.
    if let Ok(secret_key) = secrets.get(Secret::ApiSecret) {
        let mut client = Client::with_credentials(
            &config.base_url,
            Credentials {
                public_key: config.public_key.clone(),
                secret_key,
            },
        );
        match client.logout() {
            Ok(_) => println!("Revoked this device's access on {}.", config.base_url),
            Err(e) => eprintln!(
                "warning: could not revoke on the server ({e}). \
                 Remove the device from Security settings in your browser."
            ),
        }
    }

    secrets.clear().map_err(|e| e.to_string())?;
    let _ = std::fs::remove_file(paths.config_file());
    let _ = std::fs::remove_file(paths.state.join("control.json"));
    let _ = jd_platform::autostart::disable(&jd_platform::home_dir());

    // The synced files themselves are left exactly where they are. Unlinking is
    // "stop syncing this", never "delete the user's documents".
    println!(
        "Unlinked. Your files in {} were left alone.",
        config.sync_root.display()
    );
    println!(
        "The sync record in {} can be deleted if you are not relinking.",
        paths.state.display()
    );
    Ok(())
}

// ---------------------------------------------------------------------------
// Odds and ends
// ---------------------------------------------------------------------------

fn control_token() -> String {
    jd_daemon::control::new_token()
}

/// Secrets are stored base64 and read back base64, so the two halves have to be
/// the same implementation. They are the shared one, rather than a local encoder
/// paired with a local decoder: two hand-rolled halves agree until one of them
/// is edited, and the symptom of disagreement is a key that stored fine and
/// cannot be read back.
fn encode_base64(bytes: &[u8]) -> String {
    jd_crypto::b64::encode(bytes)
}

/// Positionals plus `--flag value` pairs.
struct Args {
    positional: Vec<String>,
    flags: std::collections::HashMap<String, String>,
}

impl Args {
    fn parse(raw: &[String]) -> Result<Args, String> {
        let mut positional = Vec::new();
        let mut flags = std::collections::HashMap::new();
        let mut i = 0;
        while i < raw.len() {
            match raw[i].strip_prefix("--") {
                Some(name) => {
                    i += 1;
                    let value = raw
                        .get(i)
                        .ok_or_else(|| format!("--{name} needs a value"))?;
                    flags.insert(name.to_string(), value.clone());
                }
                None => positional.push(raw[i].clone()),
            }
            i += 1;
        }
        Ok(Args { positional, flags })
    }

    fn flag(&self, name: &str) -> Option<String> {
        self.flags.get(name).cloned()
    }
}
