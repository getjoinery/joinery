//! `jd-soak` — the command line around the soak rig
//! (`specs/drive_sync_soak.md`).
//!
//!   jd-soak init <fleet.json> --base DIR --server URL [--devices N]
//!   jd-soak provision <fleet.json>      link every device to the soak account
//!   jd-soak actor <fleet.json> --device D --persona P [--seconds N]
//!   jd-soak chaos <fleet.json> --device D --fault kill|freeze|partition|restart
//!   jd-soak verify <fleet.json>         one settle, six assertions, no storm
//!   jd-soak orchestrate <fleet.json> [--cycles N] [--stop-on-violation]
//!   jd-soak report <fleet.json>
//!
//! `orchestrate` is what a campaign runs. The other four exist because when
//! something is wrong at three in the morning, being able to run one actor, one
//! fault, or one settle by hand is the difference between diagnosing it and
//! restarting everything and hoping.
//!
//! Exit codes are load-bearing: **non-zero means an invariant was broken**, so a
//! gate script and a systemd unit can both tell a bad run from a good one
//! without parsing prose.

use std::path::PathBuf;
use std::process::ExitCode;
use std::sync::atomic::AtomicBool;
use std::time::{Duration, Instant};

use jd_proto::{Client, Credentials};
use jd_soak::chaos::{Chaos, Fault, RealReach};
use jd_soak::fleet::{Device, Fleet};
use jd_soak::orchestrate::{self, Campaign};
use jd_soak::{journal, persona, report, verify};

const USAGE: &str = "\
usage: jd-soak init <fleet.json> --base DIR --server URL [--devices N]
       jd-soak provision <fleet.json>
       jd-soak actor <fleet.json> --device D --persona P [--seconds N] [--seed N]
       jd-soak chaos <fleet.json> --device D --fault kill|freeze|partition|restart
       jd-soak verify <fleet.json>
       jd-soak orchestrate <fleet.json> [--cycles N] [--storm-seconds N]
                                        [--settle-seconds N] [--seed N]
                                        [--pace-ms N] [--fault-seconds N]
                                        [--stop-on-violation]
       jd-soak report <fleet.json>

An ordinary account on the soak instance, from JD_SOAK_ACCOUNT (its sign-in
identifier) and JD_SOAK_PASSWORD. Nothing here sends mail.";

fn main() -> ExitCode {
    let argv: Vec<String> = std::env::args().skip(1).collect();
    let (command, rest) = match argv.split_first() {
        Some((c, r)) => (c.as_str(), r.to_vec()),
        None => ("help", Vec::new()),
    };

    let outcome = match command {
        "init" => cmd_init(&rest),
        "provision" => cmd_provision(&rest),
        "actor" => cmd_actor(&rest),
        "chaos" => cmd_chaos(&rest),
        "verify" => cmd_verify(&rest),
        "orchestrate" => cmd_orchestrate(&rest),
        "report" => cmd_report(&rest),
        _ => {
            eprintln!("{USAGE}");
            return ExitCode::from(2);
        }
    };

    match outcome {
        Ok(true) => ExitCode::SUCCESS,
        // A clean exit for a run in which an invariant broke would let a gate
        // pass over the one thing it exists to catch.
        Ok(false) => ExitCode::FAILURE,
        Err(message) => {
            eprintln!("error: {message}");
            ExitCode::from(2)
        }
    }
}

// ---------------------------------------------------------------------------
// init / provision
// ---------------------------------------------------------------------------

fn cmd_init(rest: &[String]) -> Result<bool, String> {
    let args = Args::parse(rest)?;
    let path = args.first_path("init needs somewhere to write the fleet description")?;
    let base = PathBuf::from(
        args.flag("base")
            .ok_or("init needs --base DIR, the directory the rig lives under")?,
    );
    let server = args
        .flag("server")
        .ok_or("init needs --server URL, the soak instance")?;
    let count: usize = args.number("devices").unwrap_or(2) as usize;

    let devices: Vec<Device> = (0..count)
        .map(|i| {
            let letter = (b'a' + i as u8) as char;
            let name = format!("device-{letter}");
            Device {
                home: base.join(&name).join("home"),
                root: base.join(&name).join("root"),
                container: None,
                // Where that account's OS trash lives, which is not the drive
                // home. setup-host.sh creates the accounts with this home.
                unix_home: Some(PathBuf::from(format!("/var/lib/soak-{letter}"))),
                unix_user: Some(format!("soak-{letter}")),
                service: Some(format!("soak-device@{letter}.service")),
                name,
            }
        })
        .collect();

    let fleet = Fleet {
        server,
        devices,
        journal_dir: base.join("journal"),
        bundle_dir: base.join("bundles"),
        storm_seconds: 45 * 60,
        settle_deadline_seconds: 15 * 60,
        poll_seconds: args.number("poll-seconds").unwrap_or(30),
    };
    for device in &fleet.devices {
        std::fs::create_dir_all(&device.root).map_err(|e| e.to_string())?;
        std::fs::create_dir_all(&device.home).map_err(|e| e.to_string())?;
    }
    std::fs::create_dir_all(&fleet.journal_dir).map_err(|e| e.to_string())?;
    std::fs::create_dir_all(&fleet.bundle_dir).map_err(|e| e.to_string())?;
    fleet.save(&path).map_err(|e| e.to_string())?;

    println!(
        "Wrote {} — {} devices against {}",
        path.display(),
        fleet.devices.len(),
        fleet.server
    );
    println!("Next: jd-soak provision {}", path.display());
    Ok(true)
}

/// Give every device a credential and a config, so the real daemon can be
/// started against it with nothing else done to it.
///
/// The daemon itself is never modified and never told it is being tested
/// (spec S2). What this does is exactly what a person does at a keyboard, minus
/// the keyboard: obtain a credential and write the two files the client reads.
///
/// Phase A uses the **password login** rather than the browser ceremony. Both
/// mint a real per-device session key through the real API; what only the
/// ceremony can do is hand the device a vault key sealed to it, and no Phase A
/// persona touches an encrypted folder. When the encrypted lane turns on
/// (Phase C) this grows a ceremony path, because that is the point at which the
/// difference starts to matter.
fn cmd_provision(rest: &[String]) -> Result<bool, String> {
    let args = Args::parse(rest)?;
    let fleet = load_fleet(&args)?;
    let (email, password) = Fleet::credentials().map_err(|e| e.to_string())?;

    for device in &fleet.devices {
        std::fs::create_dir_all(&device.root).map_err(|e| e.to_string())?;
        // Resolved by setting the variable and asking, rather than by building
        // the three paths here. The daemon works them out the same way, and two
        // implementations of "where does this device keep its things" agree
        // until one of them is edited — at which point the rig writes a
        // credential the client cannot find and reports it as unlinked.
        std::env::set_var("JOINERY_DRIVE_HOME", &device.home);
        let paths = jd_platform::Paths::discover();
        paths.create().map_err(|e| e.to_string())?;

        let mut client = Client::new(&fleet.server);
        let data = client
            .login(&email, &password, &device.name)
            .map_err(|e| format!("{} could not sign in: {e}", device.name))?;
        let credentials = client
            .credentials()
            .cloned()
            .ok_or_else(|| format!("{} signed in without a credential", device.name))?;

        // Secrets first, and before anything that could fail. A config written
        // before the credential was stored leaves a device that believes it is
        // linked and holds nothing to prove it.
        let secrets = jd_platform::SecretStore::open("com.joinery.drive", &paths.state);
        secrets
            .set(jd_platform::Secret::ApiSecret, &credentials.secret_key)
            .map_err(|e| format!("{} could not store its credential: {e}", device.name))?;

        jd_daemon::config::Config {
            base_url: fleet.server.clone(),
            sync_root: device.root.clone(),
            device_name: device.name.clone(),
            device_id: data.get("device_id").and_then(|v| v.as_i64()),
            public_key: credentials.public_key.clone(),
            poll_seconds: fleet.poll_seconds,
            excluded: Vec::new(),
            vault_enabled: false,
            autostart: false,
        }
        .save(&paths.config_file())
        .map_err(|e| e.to_string())?;

        println!(
            "{} linked — root {}, keys {}",
            device.name,
            device.root.display(),
            secrets.custody().describe()
        );
    }

    println!(
        "\nStart each daemon with JOINERY_DRIVE_HOME set to its home and: joinery-drive daemon"
    );
    Ok(true)
}

// ---------------------------------------------------------------------------
// one actor / one fault / one settle
// ---------------------------------------------------------------------------

fn cmd_actor(rest: &[String]) -> Result<bool, String> {
    let args = Args::parse(rest)?;
    let fleet = load_fleet(&args)?;
    let device = args.device(&fleet)?;
    let name = args.flag("persona").ok_or_else(|| {
        format!(
            "actor needs --persona; one of: {}",
            persona::PHASE_A_LOCAL.join(", ")
        )
    })?;
    let built = persona::build(&name).ok_or_else(|| {
        format!(
            "{name} is not a persona; one of: {}",
            persona::PHASE_A_LOCAL.join(", ")
        )
    })?;
    let seconds = args.number("seconds").unwrap_or(60);
    let seed = args.number("seed").unwrap_or(1);

    let workspace = device.root.join(format!("Shared-{name}"));
    let mut actor = jd_soak::Actor::new(&device.name, &workspace, built, seed, &fleet.journal_dir)
        .map_err(|e| e.to_string())?;
    let until = Instant::now() + Duration::from_secs(seconds);
    while Instant::now() < until {
        actor.step().map_err(|e| e.to_string())?;
        std::thread::sleep(Duration::from_millis(250));
    }
    println!(
        "{} ran {name} for {seconds}s — {} operations into {}",
        device.name,
        actor.ops_done(),
        workspace.display()
    );
    Ok(true)
}

fn cmd_chaos(rest: &[String]) -> Result<bool, String> {
    let args = Args::parse(rest)?;
    let fleet = load_fleet(&args)?;
    let device = args.device(&fleet)?;
    let seconds = args.number("seconds").unwrap_or(60);
    let fault = match args.flag("fault").as_deref() {
        Some("kill") => Fault::Kill,
        Some("freeze") => Fault::Freeze { seconds },
        Some("partition") => Fault::Partition { seconds },
        Some("restart") => Fault::Restart,
        _ => return Err("chaos needs --fault kill|freeze|partition|restart".into()),
    };

    let reach = RealReach;
    let mut chaos = Chaos::new(
        &reach,
        &orchestrate::server_host(&fleet.server),
        &fleet.journal_dir,
    )
    .map_err(|e| e.to_string())?;
    chaos
        .inject(&device, fault, &|d| std::thread::sleep(d))
        .map_err(|e| e.to_string())?;
    println!("{} on {}: {}", fault.kind(), device.name, fault.detail());
    Ok(true)
}

fn cmd_verify(rest: &[String]) -> Result<bool, String> {
    let args = Args::parse(rest)?;
    let fleet = load_fleet(&args)?;
    let api = api_client(&fleet)?;
    let records = journal::read_dir(&fleet.journal_dir).map_err(|e| e.to_string())?;
    let personality = jd_vfs::Personality::probe(&fleet.devices[0].root);

    let verification = verify::settle(
        &fleet,
        &api,
        &records,
        &personality,
        &Default::default(),
        &[],
        &Default::default(),
        Duration::from_secs(fleet.settle_deadline_seconds),
        &|d| std::thread::sleep(d),
    );

    for verdict in &verification.verdicts {
        println!(
            "{} {:<16} {}",
            if verdict.ok { "PASS" } else { "FAIL" },
            verdict.assertion,
            verdict.detail
        );
    }
    Ok(!verification.violated())
}

// ---------------------------------------------------------------------------
// the campaign
// ---------------------------------------------------------------------------

fn cmd_orchestrate(rest: &[String]) -> Result<bool, String> {
    let args = Args::parse(rest)?;
    let fleet = load_fleet(&args)?;
    let api = api_client(&fleet)?;
    let campaign = Campaign {
        storm: Duration::from_secs(args.number("storm-seconds").unwrap_or(fleet.storm_seconds)),
        settle_deadline: Duration::from_secs(
            args.number("settle-seconds")
                .unwrap_or(fleet.settle_deadline_seconds),
        ),
        cycles: args.number("cycles"),
        pace: Duration::from_millis(args.number("pace-ms").unwrap_or(250)),
        fault_interval: Duration::from_secs(args.number("fault-seconds").unwrap_or(20 * 60)),
        seed: args.number("seed").unwrap_or(1),
        stop_on_violation: args.has("stop-on-violation"),
    };

    println!(
        "campaign against {} — {} devices, {}s storms, {}s settle deadline",
        fleet.server,
        fleet.devices.len(),
        campaign.storm.as_secs(),
        campaign.settle_deadline.as_secs()
    );

    let reach = RealReach;
    let stop = AtomicBool::new(false);
    let outcome =
        orchestrate::run(&fleet, &campaign, &api, &reach, &stop).map_err(|e| e.to_string())?;

    println!("\n{} cycle(s) run", outcome.cycles);
    if outcome.clean() {
        println!("No invariant was broken.");
    } else {
        println!("INVARIANT VIOLATIONS: {}", outcome.violations.len());
        for violation in &outcome.violations {
            println!("  {violation}");
        }
        for bundle in &outcome.bundles {
            println!("  evidence: {}", bundle.display());
        }
    }
    println!("Report: {}", fleet.journal_dir.join("report.txt").display());
    Ok(outcome.clean())
}

fn cmd_report(rest: &[String]) -> Result<bool, String> {
    let args = Args::parse(rest)?;
    let fleet = load_fleet(&args)?;
    let records = journal::read_dir(&fleet.journal_dir).map_err(|e| e.to_string())?;
    let summary = report::summarize(&records);
    print!("{}", report::render(&summary));
    Ok(summary.violations.is_empty())
}

// ---------------------------------------------------------------------------
// Odds and ends
// ---------------------------------------------------------------------------

fn load_fleet(args: &Args) -> Result<Fleet, String> {
    let path = args.first_path("this command needs the path to a fleet description")?;
    Fleet::load(&path).map_err(|e| e.to_string())
}

/// A client for the verifier and the remote actor.
///
/// It signs in as the same account the devices use, which is the point: it has
/// to see exactly what they see, through the same API, or it is auditing
/// something else.
fn api_client(fleet: &Fleet) -> Result<Client, String> {
    let (email, password) = Fleet::credentials().map_err(|e| e.to_string())?;
    let mut client = Client::new(&fleet.server);
    client
        .login(&email, &password, "jd-soak verifier")
        .map_err(|e| format!("the verifier could not sign in: {e}"))?;
    let credentials = client
        .credentials()
        .cloned()
        .ok_or("the verifier signed in without a credential")?;
    Ok(Client::with_credentials(
        &fleet.server,
        Credentials {
            public_key: credentials.public_key,
            secret_key: credentials.secret_key,
        },
    ))
}

struct Args {
    positional: Vec<String>,
    flags: std::collections::HashMap<String, String>,
    switches: std::collections::HashSet<String>,
}

impl Args {
    fn parse(raw: &[String]) -> Result<Args, String> {
        // A flag whose value is missing is an error rather than a default. The
        // alternative silently runs a different campaign from the one somebody
        // typed.
        const SWITCHES: &[&str] = &["stop-on-violation"];
        let mut positional = Vec::new();
        let mut flags = std::collections::HashMap::new();
        let mut switches = std::collections::HashSet::new();
        let mut i = 0;
        while i < raw.len() {
            match raw[i].strip_prefix("--") {
                Some(name) if SWITCHES.contains(&name) => {
                    switches.insert(name.to_string());
                }
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
        Ok(Args {
            positional,
            flags,
            switches,
        })
    }

    fn flag(&self, name: &str) -> Option<String> {
        self.flags.get(name).cloned()
    }

    fn has(&self, name: &str) -> bool {
        self.switches.contains(name)
    }

    fn number(&self, name: &str) -> Option<u64> {
        self.flags.get(name).and_then(|v| v.parse().ok())
    }

    fn first_path(&self, complaint: &str) -> Result<PathBuf, String> {
        self.positional
            .first()
            .map(PathBuf::from)
            .ok_or_else(|| complaint.to_string())
    }

    fn device(&self, fleet: &Fleet) -> Result<Device, String> {
        let name = self
            .flag("device")
            .ok_or("this command needs --device NAME")?;
        fleet.device(&name).cloned().ok_or_else(|| {
            format!(
                "{name} is not in this fleet; it has: {}",
                fleet
                    .devices
                    .iter()
                    .map(|d| d.name.as_str())
                    .collect::<Vec<_>>()
                    .join(", ")
            )
        })
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn args(raw: &[&str]) -> Args {
        Args::parse(&raw.iter().map(|s| s.to_string()).collect::<Vec<_>>()).unwrap()
    }

    #[test]
    fn flags_switches_and_positionals_are_told_apart() {
        let a = args(&["fleet.json", "--cycles", "3", "--stop-on-violation"]);
        assert_eq!(a.positional, vec!["fleet.json"]);
        assert_eq!(a.number("cycles"), Some(3));
        assert!(a.has("stop-on-violation"));
        assert!(!a.has("cycles"));
    }

    #[test]
    fn a_flag_with_no_value_is_an_error_rather_than_a_default() {
        // Silently defaulting would run a different campaign from the one
        // somebody typed, and the run would look fine.
        let raw = vec!["fleet.json".to_string(), "--cycles".to_string()];
        assert!(Args::parse(&raw).is_err());
    }

    #[test]
    fn a_switch_does_not_swallow_the_argument_after_it() {
        // `--stop-on-violation fleet.json` must not consume the path.
        let a = args(&["--stop-on-violation", "fleet.json"]);
        assert!(a.has("stop-on-violation"));
        assert_eq!(a.positional, vec!["fleet.json"]);
    }

    #[test]
    fn asking_for_a_device_that_is_not_there_lists_the_ones_that_are() {
        let fleet = Fleet {
            server: "https://x.example.com".into(),
            devices: vec![Device {
                name: "device-a".into(),
                home: "/soak/a/home".into(),
                root: "/soak/a/root".into(),
                container: None,
                unix_home: None,
                unix_user: Some("soak-a".into()),
                service: None,
            }],
            journal_dir: "/soak/journal".into(),
            bundle_dir: "/soak/bundles".into(),
            storm_seconds: 60,
            settle_deadline_seconds: 60,
            poll_seconds: 30,
        };
        let err = args(&["--device", "device-z"]).device(&fleet).unwrap_err();
        assert!(err.contains("device-a"), "{err}");
    }
}
