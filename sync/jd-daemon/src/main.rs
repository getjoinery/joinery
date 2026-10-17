//! `joinery-drive` — the command line around the daemon.
//!
//!   joinery-drive login <instance-url> [--device NAME] [--root PATH]
//!   joinery-drive daemon        run the sync loop (what autostart runs)
//!   joinery-drive settings      open the settings page in the browser
//!   joinery-drive status        what it is doing, and whether that is fine
//!   joinery-drive issues        things needing a person, with what to do
//!   joinery-drive dismiss <id>
//!   joinery-drive approve-deletes [held-set]  carry out deletes the guard holds
//!   joinery-drive pause | resume | sync-now | stop
//!   joinery-drive autostart on|off
//!   joinery-drive check-update  look for a newer version now
//!   joinery-drive unlink        revoke this device and forget its keys
//!   joinery-drive --version     the workspace version every installer carries
//!
//! Everything except `login` and `daemon` is a request to a running daemon, so
//! the answer to every one of them is whatever the daemon actually believes
//! rather than a second opinion computed here. Two programs reading the same
//! state store and disagreeing about it is not a bug worth being able to have.
//!
//! `daemon` runs whether or not this computer is linked yet. Unlinked, it
//! serves the settings page's first-run setup instead of syncing, and starts
//! syncing the moment the setup finishes — or the moment `login`, run in a
//! terminal, writes a config.

use std::process::ExitCode;
use std::sync::mpsc::channel;
use std::sync::Arc;

use jd_daemon::config::{Config, ConfigError};
use jd_daemon::control::{ask, ControlServer, Endpoint};
use jd_daemon::daemon::{handle_control, Daemon, Exit, Shared};
use jd_daemon::link;
use jd_platform::{Paths, Secret};
use jd_proto::{Client, Credentials};
use jd_vfs::OsVfs;
use serde_json::{json, Value};

fn main() -> ExitCode {
    let argv: Vec<String> = std::env::args().skip(1).collect();
    let (cmd, rest) = match argv.split_first() {
        Some((c, r)) => (c.as_str(), r.to_vec()),
        None => ("help", Vec::new()),
    };

    let outcome = match cmd {
        "login" => cmd_login(&rest),
        "daemon" => {
            // On Windows, a console made for this process alone (double-click,
            // or a logon entry naming the daemon itself) is a window whose
            // close button stops sync. Let go of it; a terminal's is kept.
            jd_platform::console::release_own_console();
            cmd_daemon()
        }
        "settings" => cmd_settings(),
        "status" => cmd_status(),
        "issues" => cmd_issues(),
        "dismiss" => cmd_dismiss(&rest),
        "pause" => simple_command("/pause", "Syncing paused."),
        "resume" => simple_command("/resume", "Syncing resumed."),
        "sync-now" => simple_command("/sync-now", "Checking now."),
        "stop" => simple_command("/stop", "Stopping."),
        "autostart" => cmd_autostart(&rest),
        "check-update" => simple_command("/check-update", "Checking for updates."),
        "approve-deletes" => cmd_approve_deletes(&rest),
        "unlink" => cmd_unlink(),
        // What an installer, a bug report, or the upgrade gate's minimum is
        // compared against: the same number the server sees in client-version.
        // The updater also runs a staged copy with this, and installs it only
        // if it answers with the version the feed promised. That makes the
        // first line a contract: its last word is the version
        // (`update::preflight`), so this wording changes only with that.
        "--version" | "-V" | "version" => {
            println!("{} {}", jd_platform::BINARY_NAME, jd_proto::CLIENT_VERSION);
            Ok(())
        }
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
       joinery-drive settings
       joinery-drive status | issues | dismiss <id>
       joinery-drive approve-deletes [held-set]
       joinery-drive pause | resume | sync-now | stop
       joinery-drive autostart on|off
       joinery-drive check-update
       joinery-drive unlink
       joinery-drive --version";

// ---------------------------------------------------------------------------
// Linking
// ---------------------------------------------------------------------------

fn cmd_login(rest: &[String]) -> Result<(), String> {
    let args = Args::parse(rest)?;
    let typed = args.positional.first().ok_or(
        "login needs the address of your Joinery instance, e.g. https://drive.example.com",
    )?;
    // The same rule, in the same words, as the settings page's first run —
    // checked before anything is sent, so a refused address never sees a request.
    let base_url = jd_daemon::setup::instance_url(typed)?;

    let paths = Paths::discover();
    paths.create().map_err(|e| e.to_string())?;

    let device_name = args
        .flag("device")
        .unwrap_or_else(jd_platform::suggested_device_name);
    let sync_root = args
        .flag("root")
        .map(std::path::PathBuf::from)
        .unwrap_or_else(jd_platform::default_sync_root);

    // Opened before the ceremony, for two answers needed first: what the link
    // this one replaces holds, read while its key is still the one stored, and
    // whether this session can keep a vault key at all.
    let secrets = link::secrets(&paths);
    let previous = link::Previous::find(&paths, &secrets)?;
    let offer_vault = secrets.can_keep(Secret::VaultKey);
    if !offer_vault {
        println!(
            "This session cannot open this computer's credential store, so encrypted folders \
             will not be offered; plain folders link and sync as usual. To enable encrypted \
             folders, run joinery-drive login from your desktop session.\n"
        );
    }

    let client = Client::new(&base_url);
    let pending = link::begin(
        &client,
        &device_name,
        jd_platform::platform_name(),
        offer_vault,
    )
    .map_err(|e| e.to_string())?;

    let opened = jd_platform::browser::show_url(&pending.verify_url);
    print!("{}", link::instructions(&base_url, &pending, opened));

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
    let handoff = match link::store_approved(&secrets, &pending, &approved) {
        Ok(handoff) => handoff,
        Err(e) => {
            // The new device cannot be kept, so it comes straight back off the
            // account rather than staying live with nothing here to name it,
            // and the link it was to replace is left as it was.
            let _ = Client::with_credentials(&base_url, approved.credentials.clone()).logout();
            if let Some(old) = &previous {
                old.restore(&secrets);
            }
            return Err(format!("{e}. Nothing was linked."));
        }
    };
    let account_id = link::account_of(&base_url, &approved.credentials);

    // A daemon syncing the link this replaces would carry on with a key about
    // to be revoked, against a state store that may be about to be set aside.
    let stopped = previous.is_some() && stop_a_syncing_daemon();

    std::fs::create_dir_all(&sync_root)
        .map_err(|e| format!("cannot create {}: {e}", sync_root.display()))?;

    // Whose drive the link being replaced synced, written into the state store
    // if it does not say yet, so a store of another account is set aside when
    // syncing starts rather than read against this one.
    let previous_account = previous.as_ref().and_then(link::Previous::account);
    if let Some(account) = previous_account {
        let _ = jd_daemon::daemon::note_store_account(&paths.state_db(), account);
    }
    // Re-linking the same drive into the same folder (to turn on encrypted
    // folders, say) keeps what this computer was set to for it.
    let kept = previous.as_ref().map(|p| &p.config).filter(|old| {
        old.base_url == base_url
            && old.sync_root == sync_root
            && match (previous_account, account_id) {
                (Some(old), Some(new)) => old == new,
                _ => true,
            }
    });
    Config {
        base_url: base_url.clone(),
        sync_root: sync_root.clone(),
        device_name: device_name.clone(),
        device_id: approved.device_id,
        account_id,
        public_key: approved.credentials.public_key.clone(),
        poll_seconds: kept.map_or(jd_daemon::config::DEFAULT_POLL_SECONDS, |c| c.poll_seconds),
        excluded: kept.map(|c| c.excluded.clone()).unwrap_or_default(),
        vault_enabled: handoff.enabled(),
        autostart: kept.is_some_and(|c| c.autostart),
    }
    .save(&paths.config_file())
    .map_err(|e| e.to_string())?;

    // The old device comes off the account only now that the new one is
    // linked: a ceremony that failed part way must not have cost the user the
    // link they had.
    let revoked = previous.as_ref().map(|p| (p, p.revoke()));

    println!("\nLinked as \u{201c}{device_name}\u{201d}.");
    println!("Syncing {} \u{2194} {base_url}", sync_root.display());
    println!("Keys are stored {}.", secrets.describe(secrets.custody()));
    if handoff.enabled() {
        println!("Encrypted folders are enabled on this device.");
    }
    if let Some(said) = handoff.sentence() {
        println!("{said}");
    }
    match revoked {
        Some((old, Some(Ok(())))) => println!(
            "The link it replaces (\u{201c}{}\u{201d} on {}) was taken off the account.",
            old.config.device_name, old.config.base_url
        ),
        Some((old, Some(Err(e)))) => eprintln!(
            "warning: the link it replaces could not be taken off the account ({e}). Remove \
             \u{201c}{}\u{201d} from Security in your account on {}.",
            old.config.device_name, old.config.base_url
        ),
        Some((old, None)) => eprintln!(
            "warning: the key of the link it replaces is not on this computer, so that link \
             could not be taken off the account from here. Remove \u{201c}{}\u{201d} from \
             Security in your account on {}.",
            old.config.device_name, old.config.base_url
        ),
        None => {}
    }
    if let (Some(old), Some(new)) = (previous_account, account_id) {
        if old != new
            && previous
                .as_ref()
                .is_some_and(|p| p.config.sync_root == sync_root)
        {
            println!(
                "This folder was synced with another account. Its sync record is set aside, and \
                 what is in {} is merged into this account's drive when syncing starts.",
                sync_root.display()
            );
        }
    }
    if stopped {
        println!(
            "\nThe sync that was running was stopped. Start it again with:  joinery-drive daemon"
        );
    } else {
        println!("\nStart syncing with:  joinery-drive daemon");
    }
    Ok(())
}

/// Stop a daemon that is syncing, so a `login` that replaces its link does not
/// leave it running on a revoked key. A daemon still in first-run setup is left
/// alone: it notices the new config and starts syncing with it. Returns whether
/// one was stopped.
///
/// Waits, for a while, for it to be gone — its endpoint file goes with it —
/// so the state store it held is closed before `login` writes into it. A pass
/// in the middle of a long transfer can outlast the wait; `login` then carries
/// on, and the store is only written if it is free.
fn stop_a_syncing_daemon() -> bool {
    let Ok(running) = endpoint() else {
        return false;
    };
    let syncing = ask(&running, "GET", "/status", Value::Null)
        .is_some_and(|status| status.get("linked").and_then(Value::as_bool) == Some(true));
    if !syncing || ask(&running, "POST", "/stop", Value::Null).is_none() {
        return false;
    }
    let deadline = std::time::Instant::now() + std::time::Duration::from_secs(30);
    while std::time::Instant::now() < deadline && endpoint().is_ok() {
        std::thread::sleep(std::time::Duration::from_millis(250));
    }
    true
}

// ---------------------------------------------------------------------------
// Running
// ---------------------------------------------------------------------------

fn cmd_daemon() -> Result<(), String> {
    let paths = Paths::discover();
    paths.create().map_err(|e| e.to_string())?;
    // Before anything that can fail: a new copy that dies on the way up has
    // to have counted the attempt first (`jd_daemon::update::pending`).
    let start = jd_daemon::update::on_start(&paths);

    // A config that is there but unreadable is refused before anything opens,
    // as it always was: carrying on would point a client that had been syncing
    // one folder at whatever a default says, and the first pass would read that
    // as everything having been deleted. Only "no config at all" means setup.
    match Config::load(&paths.config_file()) {
        Ok(_) | Err(ConfigError::NotLinked) => {}
        Err(e) => return Err(e.to_string()),
    }

    let shared = Arc::new(Shared::new());
    let update = jd_daemon::update::start(&paths, &shared, &start);
    // The channel opens first, linked or not, and stays the same one for the
    // life of the process: the settings page that walks a user through setup
    // is still talking to the same port, with the same session, when syncing
    // starts — and again if they unlink from it.
    let server = Arc::new(
        ControlServer::bind(&paths.state.join("control.json"), control_token())
            .map_err(|e| format!("cannot open the control channel: {e}"))?
            .with_pages(jd_daemon::settings::PAGES),
    );
    let port = server.port();

    // The control thread holds no database handle at all, so a hung tray or
    // twenty CLI calls at once can slow nothing down and corrupt nothing.
    let control_shared = Arc::clone(&shared);
    let serving = Arc::clone(&server);
    std::thread::spawn(move || serving.serve(move |req| handle_control(&control_shared, req)));

    let outcome = run_daemon(&paths, &shared, port);
    // A stale endpoint file sends the tray and the CLI to whatever holds that
    // port next, so it goes with the process.
    server.shutdown();
    // Stopped for an update: the store is closed, so the files can move.
    if let Some(restart) = update.take_restart() {
        let paused = shared.snapshot().is_some_and(|s| s.paused);
        jd_daemon::update::restart(restart, &paths, paused);
    }
    outcome
}

/// Set up when there is nothing to sync yet, sync when there is, and go back
/// to setup when the user unlinks from the settings page.
fn run_daemon(paths: &Paths, shared: &Arc<Shared>, port: u16) -> Result<(), String> {
    let mut notice = None;
    loop {
        let config = match Config::load(&paths.config_file()) {
            Ok(config) => config,
            Err(ConfigError::NotLinked) => {
                println!(
                    "joinery-drive: not linked yet — open Settings from the Joinery Drive menu, \
                     or run: joinery-drive settings (control on 127.0.0.1:{port})"
                );
                match jd_daemon::setup::run(paths, shared, notice.take()) {
                    Some(config) => config,
                    None => return Ok(()),
                }
            }
            Err(e) => return Err(e.to_string()),
        };
        match sync(paths, shared, config.clone(), port)? {
            Exit::Stopped => return Ok(()),
            Exit::Unlinked => {
                shared.show_setup(jd_daemon::setup::View::unlinking(), paths);
                notice = Some(unlink_from_page(paths, &config));
            }
        }
    }
}

/// Unlink at the settings page's request, and say what happened in words the
/// page can show.
fn unlink_from_page(paths: &Paths, config: &Config) -> String {
    let secrets = link::secrets(paths);
    match link::unlink(paths, config, &secrets) {
        Ok(done) => {
            let revoked = match done.revoked {
                Some(Err(e)) => format!(
                    " The site could not be told ({e}); remove this computer from Security in your account on the web."
                ),
                _ => String::new(),
            };
            format!(
                "Unlinked from {}. Your files in {} were left where they are.{revoked}",
                done.base_url,
                done.sync_root.display()
            )
        }
        Err(e) => format!("Unlinking did not finish: {e}. Run joinery-drive unlink to try again."),
    }
}

/// Run the sync loop for one linked configuration.
fn sync(paths: &Paths, shared: &Arc<Shared>, config: Config, port: u16) -> Result<Exit, String> {
    let secrets = link::secrets(paths);
    let (secret_key, held) = secrets.get_held(Secret::ApiSecret).map_err(|e| match e {
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
    let probe = Client::with_credentials(&config.base_url, credentials.clone());
    let (vault, said) = link::load_vault(&secrets, &config, &probe);
    if let Some(said) = said {
        eprintln!("warning: {said}");
    }

    let store = jd_daemon::daemon::open_store(&paths.state_db(), &config)?;
    let vfs = OsVfs::new(config.sync_root.clone(), paths.spool()).map_err(|e| e.to_string())?;

    let (tx, rx) = channel();
    shared.attach(tx);
    // Paused before an update stays paused after it, from the first pass.
    jd_daemon::update::keep_paused(shared);
    println!(
        "joinery-drive: syncing {} with {} (control on 127.0.0.1:{port})",
        config.sync_root.display(),
        config.base_url,
    );

    // Where the key actually is, which is not always where a key written now
    // would go: one linked while the credential store was locked is a file
    // even when the daemon runs where the store is open.
    let custody = secrets.describe(held).to_string();
    // Said either way, so an archive with no journal in it is provably off.
    let pass_journal = jd_daemon::passlog::PassJournal::from_env(&paths.logs);
    match &pass_journal {
        Some(j) => eprintln!("pass journal: on -> {}", j.path().display()),
        None => eprintln!("pass journal: off"),
    }
    let mut daemon = Daemon::new(
        config,
        store,
        vfs,
        credentials,
        Arc::clone(shared),
        rx,
        custody,
        vault,
        pass_journal,
    );
    Ok(daemon.run())
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

    if answer.get("linked").and_then(Value::as_bool) == Some(false) {
        println!(
            "{}",
            answer
                .get("summary")
                .and_then(Value::as_str)
                .unwrap_or("Not set up yet")
        );
        println!("\n  Finish setting up in: joinery-drive settings");
        println!("  or link from here:    joinery-drive login <instance-url>");
        // Not syncing, so not a success to a monitoring script.
        return Err("this computer is not linked to an account".into());
    }

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
        match (
            answer.get("vault").and_then(Value::as_bool),
            answer.get("vault_relink").and_then(Value::as_bool),
        ) {
            (_, Some(true)) => "the key was replaced in the browser — link this computer again \
                                (joinery-drive login, from the desktop session)"
                .to_string(),
            (Some(true), _) => "folders can be opened on this device".to_string(),
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
    if let Some(update) = answer.pointer("/update/summary").and_then(Value::as_str) {
        println!("  updates  : {update}");
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

/// Carry out the deletes the mass-delete guard holds, once a person has seen
/// them.
///
/// With no argument it shows what is held and asks. Given the held set, as the
/// issue names it, it approves that set without asking -- for a script, or a
/// person who has already read the issue -- and only that set: if what is held
/// has changed since, nothing is approved and the new set is shown. Either
/// way the daemon's next round carries the deletes out only if it would still
/// hold exactly that set.
fn cmd_approve_deletes(rest: &[String]) -> Result<(), String> {
    let endpoint = endpoint()?;
    let status = ask(&endpoint, "GET", "/status", Value::Null)
        .ok_or("the sync daemon did not answer".to_string())?;
    let set = match approval_for(&status, rest.first().map(String::as_str)) {
        Approval::NothingHeld => {
            println!("No deletes are being held.");
            return Ok(());
        }
        Approval::NotWhatIsHeld { said } => {
            return Err(format!(
                "that is not the set being held now, so nothing was approved. What is held:\n\n{said}\n\n\
                 Look again with: joinery-drive approve-deletes"
            ))
        }
        Approval::Send(set) => set,
        Approval::Ask { said, set } => {
            println!("{said}\n");
            print!("Carry out these deletes? Type yes to go ahead: ");
            let _ = std::io::Write::flush(&mut std::io::stdout());
            let mut line = String::new();
            std::io::stdin()
                .read_line(&mut line)
                .map_err(|e| format!("cannot read the answer: {e}"))?;
            if line.trim() != "yes" {
                println!("Nothing was deleted.");
                return Ok(());
            }
            set
        }
    };
    let answer = ask(&endpoint, "POST", "/approve-deletes", json!({ "fingerprint": set }))
        .ok_or("the sync daemon did not answer".to_string())?;
    if answer.get("ok").and_then(Value::as_bool) == Some(true) {
        println!(
            "Approved. The next pass carries out exactly these deletes; if what is held changes first, \
             nothing is deleted and you are asked again."
        );
        Ok(())
    } else {
        Err(answer
            .get("error")
            .and_then(Value::as_str)
            .unwrap_or("the daemon refused")
            .to_string())
    }
}

/// What `approve-deletes` does, read off the status answer and the set it
/// was given, if any.
#[derive(Debug, PartialEq, Eq)]
enum Approval {
    NothingHeld,
    /// Show the person what is held, in the engine's own words (the summary
    /// adds the command this is), and ask.
    Ask { said: String, set: String },
    /// Given the set held now: approve it.
    Send(String),
    /// Given a set that is not what is held now.
    NotWhatIsHeld { said: String },
}

fn approval_for(status: &Value, given: Option<&str>) -> Approval {
    let Some(held) = status.get("held_deletes").filter(|h| !h.is_null()) else {
        return Approval::NothingHeld;
    };
    let said = held.get("detail").and_then(Value::as_str).unwrap_or("").to_string();
    let set = held.get("held_set").and_then(Value::as_str).unwrap_or("").to_string();
    match given {
        None => Approval::Ask { said, set },
        Some(given) if given == set => Approval::Send(set),
        Some(_) => Approval::NotWhatIsHeld { said },
    }
}

/// Open the settings page in the browser.
///
/// The address carries a ticket that is spent when the page loads, so it is
/// printed as well as opened: on a machine with no desktop, a person can paste
/// it into a browser on the same machine within the two minutes it lasts.
fn cmd_settings() -> Result<(), String> {
    let url = jd_daemon::control::settings_url(&endpoint()?, None)
        .ok_or("the sync daemon did not answer".to_string())?;
    if jd_platform::browser::show_url(&url) {
        println!("Opened Joinery Drive settings in your browser.");
    } else {
        println!("Open this in a browser on this computer within two minutes:\n\n    {url}");
    }
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
    let config = Config::load(&paths.config_file()).map_err(|e| e.to_string())?;
    let secrets = link::secrets(&paths);
    // Before the daemon is stopped: an unlink that is going to be refused (its
    // key is in a credential store this session cannot open) must not take the
    // sync down on its way to saying so.
    link::check_unlink(&config, &secrets)?;

    // Stop first. A daemon still running against a credential we are about to
    // revoke would spend the next minute failing every request and reporting
    // itself broken.
    if let Ok(endpoint) = endpoint() {
        let _ = ask(&endpoint, "POST", "/stop", Value::Null);
    }

    let done = link::unlink(&paths, &config, &secrets)?;
    match &done.revoked {
        Some(Ok(())) => println!("Revoked this device's access on {}.", done.base_url),
        Some(Err(e)) => eprintln!(
            "warning: could not revoke on the server ({e}). \
             Remove the device from Security settings in your browser."
        ),
        None => {}
    }
    let _ = std::fs::remove_file(paths.state.join("control.json"));

    println!(
        "Unlinked. Your files in {} were left alone.",
        done.sync_root.display()
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

#[cfg(test)]
mod tests {
    use super::*;

    fn status_holding(set: &str) -> Value {
        json!({
            "indicator": "attention",
            "held_deletes": { "issue_id": 4, "summary": "61 items ... From a terminal: joinery-drive approve-deletes", "detail": "61 items ...", "held_set": set },
        })
    }

    /// Asked with no set, it shows what is held and asks; given the set held
    /// now, it sends that; given any other, it sends nothing and shows what
    /// is held instead.
    #[test]
    fn approve_deletes_sends_only_the_set_held_now() {
        let status = status_holding("0123456789abcdef");
        assert_eq!(
            approval_for(&status, None),
            Approval::Ask { said: "61 items ...".into(), set: "0123456789abcdef".into() }
        );
        assert_eq!(approval_for(&status, Some("0123456789abcdef")), Approval::Send("0123456789abcdef".into()));
        assert_eq!(
            approval_for(&status, Some("fedcba9876543210")),
            Approval::NotWhatIsHeld { said: "61 items ...".into() }
        );
        let quiet = json!({ "indicator": "green", "held_deletes": null });
        assert_eq!(approval_for(&quiet, Some("0123456789abcdef")), Approval::NothingHeld);
        assert_eq!(approval_for(&json!({ "summary": "Starting up" }), None), Approval::NothingHeld);
    }
}
