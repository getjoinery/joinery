//! The update path end to end, on the machine running the test.
//!
//! A feed and a payload are served from 127.0.0.1, signed with a key made for
//! this test; the payload carries the real `joinery-drive` this build just
//! produced. The updater finds it, downloads it, checks it, runs it once, and
//! swaps it in over a stand-in install — a program folder on Windows and
//! Linux, an app bundle on a Mac. Then the real new binary is started the way
//! autostart starts it: in a home whose config is damaged, where it cannot
//! finish starting, until it gives up and puts the stand-in back; and in a
//! fresh home, where it comes up in first-run setup and clears its marker.

use std::collections::HashMap;
use std::io::{BufRead, BufReader, Write};
use std::net::{TcpListener, TcpStream};
use std::path::{Path, PathBuf};
use std::sync::{Arc, Mutex};

use jd_daemon::update::manifest::{Artifact, Manifest, TrustedKeys, Version, FORMAT};
use jd_daemon::update::pending::{self, Phase};
use jd_daemon::update::{self, payload, Checked, Settings, UpdateError, Updater};
use jd_platform::install::{self, Install, Os};
use ring::rand::SystemRandom;
use ring::signature::{Ed25519KeyPair, KeyPair};

/// The version the stand-in install claims to be: older than this build.
const OLD: &str = "0.0.1";

// ---------------------------------------------------------------------------
// A feed on 127.0.0.1
// ---------------------------------------------------------------------------

struct Feed {
    port: u16,
    files: Arc<Mutex<HashMap<String, Vec<u8>>>>,
    asked: Arc<Mutex<Vec<String>>>,
}

impl Feed {
    fn start() -> Feed {
        let listener = TcpListener::bind("127.0.0.1:0").unwrap();
        let port = listener.local_addr().unwrap().port();
        let files: Arc<Mutex<HashMap<String, Vec<u8>>>> = Arc::default();
        let asked: Arc<Mutex<Vec<String>>> = Arc::default();
        let (f, a) = (Arc::clone(&files), Arc::clone(&asked));
        std::thread::spawn(move || {
            for stream in listener.incoming().flatten() {
                let (f, a) = (Arc::clone(&f), Arc::clone(&a));
                std::thread::spawn(move || answer(stream, &f, &a));
            }
        });
        Feed { port, files, asked }
    }

    fn url(&self, path: &str) -> String {
        format!("http://127.0.0.1:{}{path}", self.port)
    }

    fn put(&self, path: &str, body: Vec<u8>) {
        self.files.lock().unwrap().insert(path.to_string(), body);
    }

    fn was_asked_for(&self, path: &str) -> bool {
        self.asked.lock().unwrap().iter().any(|p| p == path)
    }
}

fn answer(
    mut stream: TcpStream,
    files: &Mutex<HashMap<String, Vec<u8>>>,
    asked: &Mutex<Vec<String>>,
) {
    let mut reader = BufReader::new(stream.try_clone().unwrap());
    let mut line = String::new();
    if reader.read_line(&mut line).is_err() {
        return;
    }
    loop {
        let mut header = String::new();
        if reader.read_line(&mut header).unwrap_or(0) == 0 || header == "\r\n" {
            break;
        }
    }
    let path = line.split_whitespace().nth(1).unwrap_or("/").to_string();
    asked.lock().unwrap().push(path.clone());
    match files.lock().unwrap().get(&path).cloned() {
        Some(body) => {
            let _ = write!(
                stream,
                "HTTP/1.1 200 OK\r\nContent-Length: {}\r\nConnection: close\r\n\r\n",
                body.len()
            );
            let _ = stream.write_all(&body);
        }
        None => {
            let _ = stream.write_all(
                b"HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\nConnection: close\r\n\r\n",
            );
        }
    }
}

// ---------------------------------------------------------------------------
// A stand-in install, and a payload made from this build
// ---------------------------------------------------------------------------

fn temp(tag: &str) -> PathBuf {
    let p = std::env::temp_dir().join(format!("jd-update-e2e-{tag}-{}", std::process::id()));
    let _ = std::fs::remove_dir_all(&p);
    std::fs::create_dir_all(&p).unwrap();
    // Canonical, because on a Mac the temporary directory is a symlink and the
    // daemon judges its install from its resolved path.
    std::fs::canonicalize(&p).unwrap()
}

fn exe_name(base: &str) -> String {
    if cfg!(windows) {
        format!("{base}.exe")
    } else {
        base.to_string()
    }
}

/// Where the two programs sit inside an install (or a payload) rooted at `root`.
fn program_dir(root: &Path) -> PathBuf {
    if cfg!(target_os = "macos") {
        root.join("Joinery Drive.app/Contents/MacOS")
    } else {
        root.join("Joinery Drive")
    }
}

/// An install of "version 0.0.1": two files that are not programs at all,
/// which is what lets the test tell afterwards which copy is where.
fn old_install(root: &Path) -> PathBuf {
    let dir = program_dir(root);
    std::fs::create_dir_all(&dir).unwrap();
    if cfg!(target_os = "macos") {
        std::fs::write(
            dir.parent().unwrap().join("Info.plist"),
            b"<plist>old</plist>",
        )
        .unwrap();
    }
    std::fs::write(dir.join(exe_name("joinery-drive")), b"old daemon").unwrap();
    std::fs::write(dir.join(exe_name("joinery-drive-tray")), b"old tray").unwrap();
    dir.join(exe_name("joinery-drive"))
}

/// The payload: this build's real daemon, and a stand-in tray. Packed once
/// and shared, because deflating a debug build's binary is the slowest thing
/// any of these tests does.
fn new_payload(_root: &Path) -> Vec<u8> {
    static PAYLOAD: std::sync::OnceLock<Vec<u8>> = std::sync::OnceLock::new();
    PAYLOAD
        .get_or_init(|| {
            let root = temp("payload");
            let bytes = pack_payload(&root);
            let _ = std::fs::remove_dir_all(&root);
            bytes
        })
        .clone()
}

fn pack_payload(root: &Path) -> Vec<u8> {
    let dir = program_dir(&root.join("payload"));
    std::fs::create_dir_all(&dir).unwrap();
    if cfg!(target_os = "macos") {
        std::fs::write(
            dir.parent().unwrap().join("Info.plist"),
            b"<plist>new</plist>",
        )
        .unwrap();
    }
    std::fs::copy(
        env!("CARGO_BIN_EXE_joinery-drive"),
        dir.join(exe_name("joinery-drive")),
    )
    .unwrap();
    std::fs::write(dir.join(exe_name("joinery-drive-tray")), b"new tray").unwrap();
    #[cfg(unix)]
    {
        use std::os::unix::fs::PermissionsExt;
        std::fs::set_permissions(
            dir.join(exe_name("joinery-drive-tray")),
            std::fs::Permissions::from_mode(0o755),
        )
        .unwrap();
    }
    let sources = if cfg!(target_os = "macos") {
        vec![root.join("payload/Joinery Drive.app")]
    } else {
        vec![
            dir.join(exe_name("joinery-drive")),
            dir.join(exe_name("joinery-drive-tray")),
        ]
    };
    let mut bytes = Vec::new();
    payload::pack(&sources, &mut bytes).unwrap();
    bytes
}

fn keypair() -> Ed25519KeyPair {
    let pkcs8 = Ed25519KeyPair::generate_pkcs8(&SystemRandom::new()).unwrap();
    Ed25519KeyPair::from_pkcs8(pkcs8.as_ref()).unwrap()
}

fn trust(pair: &Ed25519KeyPair) -> TrustedKeys {
    TrustedKeys::from_keys(vec![pair.public_key().as_ref().try_into().unwrap()])
}

fn sha256(bytes: &[u8]) -> String {
    update::hex(ring::digest::digest(&ring::digest::SHA256, bytes).as_ref())
}

/// Publish a manifest for `version` over `payload`, signed by `signer`.
fn publish(feed: &Feed, signer: &Ed25519KeyPair, version: &str, payload: &[u8]) {
    let os = Os::current().as_str();
    let arch = if os == "macos" {
        // How a universal2 bundle is published.
        "universal".to_string()
    } else {
        std::env::consts::ARCH.to_string()
    };
    let manifest = Manifest {
        format: FORMAT.into(),
        channel: "beta".into(),
        version: version.into(),
        minimum_supported_version: None,
        release_notes_url: None,
        artifacts: vec![
            Artifact {
                os: os.into(),
                arch: arch.clone(),
                kind: "update".into(),
                url: feed.url("/files/payload.tar.gz"),
                size: payload.len() as u64,
                sha256: sha256(payload),
            },
            // Listed, and never touched by the client.
            Artifact {
                os: os.into(),
                arch,
                kind: "installer".into(),
                url: feed.url("/files/installer"),
                size: 1,
                sha256: "0".repeat(64),
            },
        ],
    };
    let body = serde_json::to_vec_pretty(&manifest).unwrap();
    let sig = format!("{}\n", jd_crypto::b64::encode(signer.sign(&body).as_ref()));
    feed.put("/beta.json", body);
    feed.put("/beta.json.sig", sig.into_bytes());
    feed.put("/files/payload.tar.gz", payload.to_vec());
}

fn updater(feed: &Feed, keys: TrustedKeys, home: &Path, daemon: &Path) -> Updater {
    Updater {
        settings: Settings {
            channel: "beta".into(),
            feed_url: Some(feed.url("/beta.json")),
            automatic: true,
        },
        settings_problem: None,
        keys,
        keys_problem: None,
        current: Some(OLD.parse().unwrap()),
        os: Os::current(),
        arch: std::env::consts::ARCH.to_string(),
        dir: home.join("state/updates"),
        daemon: daemon.to_path_buf(),
        install: install::detect(daemon),
    }
}

fn check(u: &Updater) -> Result<Checked, UpdateError> {
    u.check(&update::agent(u.os, &u.arch), false, None, &|_| {})
}

/// The version `--version` prints (`joinery-drive 0.3.0`), or why there is none.
fn run_version(program: &Path) -> Result<String, String> {
    let out = std::process::Command::new(program)
        .arg("--version")
        .output()
        .map_err(|e| e.to_string())?;
    let said = String::from_utf8_lossy(&out.stdout).trim().to_string();
    Ok(said.split_whitespace().last().unwrap_or("").to_string())
}

/// The installed daemon, started the way autostart does, in `home`.
fn daemon_in(daemon: &Path, home: &Path, args: &[&str]) -> std::process::Child {
    std::process::Command::new(daemon)
        .args(args)
        .env("JOINERY_DRIVE_HOME", home)
        // Not under a supervisor, whatever the test runner is under: the
        // daemon must not exit expecting something to restart it.
        .env_remove("INVOCATION_ID")
        .env_remove("SYSTEMD_EXEC_PID")
        .env_remove("XPC_SERVICE_NAME")
        .stdout(std::process::Stdio::null())
        .stderr(std::process::Stdio::null())
        .spawn()
        .unwrap()
}

/// Wait for a process, failing the test rather than hanging it.
fn wait_for(mut child: std::process::Child, what: &str) -> std::process::ExitStatus {
    let deadline = std::time::Instant::now() + std::time::Duration::from_secs(60);
    loop {
        if let Some(status) = child.try_wait().unwrap() {
            return status;
        }
        if std::time::Instant::now() > deadline {
            let _ = child.kill();
            let _ = child.wait();
            panic!("{what} was still running after a minute");
        }
        std::thread::sleep(std::time::Duration::from_millis(50));
    }
}

/// Start the installed daemon in a home whose config is damaged: it refuses to
/// start — after counting the attempt, which is what a new copy that cannot
/// start looks like.
fn start_failing_daemon(daemon: &Path, home: &Path) {
    std::fs::create_dir_all(home.join("config")).unwrap();
    std::fs::write(home.join("config/config.json"), "{ damaged").unwrap();
    let status = wait_for(
        daemon_in(daemon, home, &["daemon"]),
        "a daemon with a damaged config",
    );
    assert!(
        !status.success(),
        "a daemon with a damaged config cannot start"
    );
}

// ---------------------------------------------------------------------------
// The scenarios
// ---------------------------------------------------------------------------

#[test]
fn an_update_is_found_checked_installed_and_undone_when_it_will_not_start() {
    let root = temp("full");
    let home = root.join("home");
    let live = old_install(&root.join("install"));
    let feed = Feed::start();
    let pair = keypair();
    let built = update::current_version();
    publish(&feed, &pair, built, &new_payload(&root));

    let u = updater(&feed, trust(&pair), &home, &live);
    assert!(
        u.install.can_replace(),
        "a stand-in install the test owns: {:?}",
        u.install
    );

    // Found, downloaded, hash-checked, unpacked, and run once.
    let staged = match check(&u).unwrap() {
        Checked::Ready { staged, .. } => staged,
        other => panic!("{other:?}"),
    };
    assert_eq!(staged.version, built.parse::<Version>().unwrap());
    assert!(
        !feed.was_asked_for("/files/installer"),
        "only the update payload is fetched"
    );
    assert_eq!(
        std::fs::read(&live).unwrap(),
        b"old daemon",
        "nothing moves before the swap"
    );

    // Swapped in.
    let marker = u.marker();
    update::install_staged(&staged, &marker, OLD, false).unwrap();
    assert_eq!(
        run_version(&live).unwrap(),
        built,
        "the live path is the new program"
    );
    let tray = live.with_file_name(exe_name("joinery-drive-tray"));
    assert_eq!(std::fs::read(&tray).unwrap(), b"new tray");
    if cfg!(target_os = "macos") {
        let plist = live.parent().unwrap().parent().unwrap().join("Info.plist");
        assert_eq!(
            std::fs::read(plist).unwrap(),
            b"<plist>new</plist>",
            "the whole bundle"
        );
    }

    // Started three times by "autostart", failing each time — each is an
    // attempt, and the new copy stays.
    for attempt in 1..=pending::MAX_START_ATTEMPTS {
        start_failing_daemon(&live, &home);
        let p = pending::Pending::load(&marker).unwrap();
        assert_eq!(p.attempts, attempt);
        assert_eq!(p.phase, Phase::Swapped);
    }
    // Once more: it gives up and puts the old copy back.
    start_failing_daemon(&live, &home);
    assert_eq!(std::fs::read(&live).unwrap(), b"old daemon");
    assert_eq!(std::fs::read(&tray).unwrap(), b"old tray");

    // And the old copy, starting, learns why.
    let (version, why) = pending::take_rollback(&marker, OLD).unwrap();
    assert_eq!(version, built);
    assert!(why.contains("did not start"), "{why}");
    let _ = std::fs::remove_dir_all(&root);
}

#[test]
fn an_update_that_starts_keeps_the_new_copy_and_deletes_the_old() {
    let root = temp("good");
    let home = root.join("home");
    let live = old_install(&root.join("install"));
    let feed = Feed::start();
    let pair = keypair();
    let built = update::current_version();
    publish(&feed, &pair, built, &new_payload(&root));
    let u = updater(&feed, trust(&pair), &home, &live);

    let Checked::Ready { staged, .. } = check(&u).unwrap() else {
        panic!("expected an update");
    };
    let staging = staged.staging.clone();
    update::install_staged(&staged, &u.marker(), OLD, false).unwrap();

    // The real new daemon, started in a home where it is not linked yet: it
    // comes up in first-run setup, which is up, and clears its marker.
    let marker = u.marker();
    let running = daemon_in(&live, &home, &["daemon"]);
    let deadline = std::time::Instant::now() + std::time::Duration::from_secs(60);
    while marker.exists() && std::time::Instant::now() < deadline {
        std::thread::sleep(std::time::Duration::from_millis(100));
    }
    let cleared = !marker.exists();
    // Stopped the way the tray stops it, whatever happened above.
    let stop = wait_for(daemon_in(&live, &home, &["stop"]), "joinery-drive stop");
    let stopped = wait_for(running, "the new daemon after stop");
    assert!(cleared, "the new copy did not clear its start marker");
    assert!(stop.success() && stopped.success(), "{stop} / {stopped}");
    assert!(
        !staging.exists(),
        "the old copy is gone once the new one is up"
    );
    assert_eq!(run_version(&live).unwrap(), built);
    let _ = std::fs::remove_dir_all(&root);
}

#[test]
fn a_payload_that_does_not_match_the_signed_hash_is_never_installed() {
    let root = temp("tampered");
    let home = root.join("home");
    let live = old_install(&root.join("install"));
    let feed = Feed::start();
    let pair = keypair();
    let payload = new_payload(&root);
    publish(&feed, &pair, update::current_version(), &payload);
    // Same length, one byte different: only the hash can tell.
    let mut tampered = payload.clone();
    let middle = tampered.len() / 2;
    tampered[middle] ^= 0x40;
    feed.put("/files/payload.tar.gz", tampered);

    let u = updater(&feed, trust(&pair), &home, &live);
    match check(&u) {
        Err(UpdateError::Mismatch(why)) => assert!(why.contains("SHA-256"), "{why}"),
        other => panic!("{other:?}"),
    }
    assert_eq!(std::fs::read(&live).unwrap(), b"old daemon");
    // Nothing half-downloaded is left lying around.
    let leftovers: Vec<_> = std::fs::read_dir(home.join("state/updates"))
        .map(|d| d.flatten().map(|e| e.file_name()).collect())
        .unwrap_or_default();
    assert!(leftovers.is_empty(), "{leftovers:?}");
    let _ = std::fs::remove_dir_all(&root);
}

#[test]
fn a_feed_signed_by_a_stranger_is_not_believed_and_nothing_is_downloaded() {
    let root = temp("stranger");
    let home = root.join("home");
    let live = old_install(&root.join("install"));
    let feed = Feed::start();
    let stranger = keypair();
    publish(
        &feed,
        &stranger,
        update::current_version(),
        &new_payload(&root),
    );

    let u = updater(&feed, trust(&keypair()), &home, &live);
    assert!(matches!(
        check(&u),
        Err(UpdateError::Feed(jd_daemon::update::FeedError::NotTrusted))
    ));
    assert!(!feed.was_asked_for("/files/payload.tar.gz"));
    let _ = std::fs::remove_dir_all(&root);
}

#[test]
fn an_older_version_in_a_correctly_signed_feed_is_never_installed() {
    let root = temp("downgrade");
    let home = root.join("home");
    let live = old_install(&root.join("install"));
    let feed = Feed::start();
    let pair = keypair();
    publish(&feed, &pair, "0.0.1", &new_payload(&root));

    let mut u = updater(&feed, trust(&pair), &home, &live);
    u.current = Some("0.0.2".parse().unwrap());
    assert!(matches!(check(&u).unwrap(), Checked::UpToDate { .. }));
    assert!(!feed.was_asked_for("/files/payload.tar.gz"));
    let _ = std::fs::remove_dir_all(&root);
}

#[test]
fn a_copy_the_package_manager_owns_hears_about_the_update_and_touches_nothing() {
    let root = temp("packaged");
    let home = root.join("home");
    let live = old_install(&root.join("install"));
    let feed = Feed::start();
    let pair = keypair();
    publish(&feed, &pair, update::current_version(), &new_payload(&root));

    let mut u = updater(&feed, trust(&pair), &home, &live);
    u.install = Install::PackageManager {
        name: "the system package manager (apt, dnf)".into(),
    };
    match check(&u).unwrap() {
        Checked::Available { version, why, .. } => {
            assert_eq!(version.to_string(), update::current_version());
            assert!(why.contains("package manager"), "{why}");
        }
        other => panic!("{other:?}"),
    }
    assert!(!feed.was_asked_for("/files/payload.tar.gz"));
    assert_eq!(std::fs::read(&live).unwrap(), b"old daemon");
    let _ = std::fs::remove_dir_all(&root);
}

#[test]
fn a_new_program_that_is_not_the_version_the_feed_promised_is_refused_before_the_swap() {
    // The binary in the payload answers with this build's version; the feed
    // says something else. Whatever went wrong in publishing, installing it
    // would leave a client that does not know what it is.
    let root = temp("liar");
    let home = root.join("home");
    let live = old_install(&root.join("install"));
    let feed = Feed::start();
    let pair = keypair();
    publish(&feed, &pair, "99.0.0", &new_payload(&root));

    let u = updater(&feed, trust(&pair), &home, &live);
    match check(&u) {
        Err(UpdateError::Preflight(why)) => assert!(why.contains("99.0.0"), "{why}"),
        other => panic!("{other:?}"),
    }
    assert_eq!(std::fs::read(&live).unwrap(), b"old daemon");
    let parent = u.install.staging_parent().unwrap();
    let staged: Vec<_> = std::fs::read_dir(&parent)
        .unwrap()
        .flatten()
        .filter(|e| {
            e.file_name()
                .to_string_lossy()
                .starts_with(update::STAGING_PREFIX)
        })
        .collect();
    assert!(staged.is_empty(), "a refused update leaves nothing staged");
    let _ = std::fs::remove_dir_all(&root);
}
