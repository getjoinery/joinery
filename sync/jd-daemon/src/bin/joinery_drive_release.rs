//! `joinery-drive-release` — the publisher's half of the update feed.
//!
//!   joinery-drive-release keygen --out PATH
//!   joinery-drive-release public-key [--key PATH]
//!   joinery-drive-release pack --out FILE.tar.gz PATH...
//!   joinery-drive-release unpack --out DIR FILE.tar.gz
//!   joinery-drive-release manifest --channel C --version X.Y.Z --base-url URL
//!         --artifact OS:ARCH:KIND:FILE [--artifact …] [--minimum X.Y.Z]
//!         [--notes URL] --out C.json
//!   joinery-drive-release sign --manifest C.json [--key PATH] [--append]
//!   joinery-drive-release verify --manifest C.json --trust BASE64 [--trust …]
//!
//! The order a release goes through them: build, `pack` each platform's
//! update payload, `manifest` over every artifact (payloads and installers
//! alike), `sign`, `verify` against the public key the clients carry, publish
//! the manifest, its `.sig` and the artifacts together. `unpack` reads a
//! payload exactly as a client will, so what clients get can be checked before
//! it is published — on a Mac, with `codesign --verify --deep --strict` on the
//! unpacked app.
//!
//! **The signing key is never read from anywhere it was not pointed to.** It
//! comes from `--key PATH` or the file named by `JOINERY_DRIVE_UPDATE_KEY`, and
//! there is no default location. A key file that other accounts can read is
//! refused, and so is one inside a git working tree that git would track: a
//! signing key that can be committed eventually is.
//!
//! The key file is base64 of the 64-byte libsodium form (seed then public
//! key), which is also what the platform's own release key looks like — so the
//! same file works here and in PHP's `sodium_crypto_sign_detached`. A bare
//! 32-byte seed is accepted too.

use std::path::{Path, PathBuf};
use std::process::ExitCode;

use jd_daemon::update::manifest::{self, Artifact, Manifest, TrustedKeys, FORMAT};
use jd_daemon::update::payload;
use ring::rand::{SecureRandom, SystemRandom};
use ring::signature::{Ed25519KeyPair, KeyPair};

const KEY_ENV: &str = "JOINERY_DRIVE_UPDATE_KEY";

const USAGE: &str = "\
usage: joinery-drive-release keygen --out PATH
       joinery-drive-release public-key [--key PATH]
       joinery-drive-release pack --out FILE.tar.gz PATH...
       joinery-drive-release unpack --out DIR FILE.tar.gz
       joinery-drive-release manifest --channel C --version X.Y.Z --base-url URL
             --artifact OS:ARCH:KIND:FILE [--artifact ...] [--minimum X.Y.Z] [--notes URL] --out C.json
       joinery-drive-release sign --manifest C.json [--key PATH] [--append]
       joinery-drive-release verify --manifest C.json --trust BASE64 [--trust ...] [--channel C]

The signing key comes from --key or the file named by JOINERY_DRIVE_UPDATE_KEY.";

fn main() -> ExitCode {
    let argv: Vec<String> = std::env::args().skip(1).collect();
    let Some((cmd, rest)) = argv.split_first() else {
        eprintln!("{USAGE}");
        return ExitCode::from(2);
    };
    let args = match Args::parse(rest) {
        Ok(a) => a,
        Err(e) => {
            eprintln!("error: {e}\n\n{USAGE}");
            return ExitCode::from(2);
        }
    };
    let outcome = match cmd.as_str() {
        "keygen" => keygen(&args),
        "public-key" => public_key(&args),
        "pack" => pack(&args),
        "unpack" => unpack(&args),
        "manifest" => write_manifest(&args),
        "sign" => sign(&args),
        "verify" => verify(&args),
        _ => {
            eprintln!("{USAGE}");
            return ExitCode::from(2);
        }
    };
    match outcome {
        Ok(()) => ExitCode::SUCCESS,
        Err(e) => {
            eprintln!("error: {e}");
            ExitCode::FAILURE
        }
    }
}

// ---------------------------------------------------------------------------
// Keys
// ---------------------------------------------------------------------------

fn keygen(args: &Args) -> Result<(), String> {
    let out = PathBuf::from(args.one("out")?);
    if out.exists() {
        return Err(format!(
            "{} exists; a key is never overwritten",
            out.display()
        ));
    }
    refuse_if_trackable(&out)?;
    let mut seed = [0u8; 32];
    SystemRandom::new()
        .fill(&mut seed)
        .map_err(|_| "the system random source failed".to_string())?;
    let pair = Ed25519KeyPair::from_seed_unchecked(&seed).map_err(|e| e.to_string())?;
    let mut sodium = seed.to_vec();
    sodium.extend_from_slice(pair.public_key().as_ref());

    // Created private, before the key is written into it.
    let mut options = std::fs::OpenOptions::new();
    options.write(true).create_new(true);
    #[cfg(unix)]
    {
        use std::os::unix::fs::OpenOptionsExt;
        options.mode(0o600);
    }
    let mut file = options
        .open(&out)
        .map_err(|e| format!("cannot create {}: {e}", out.display()))?;
    use std::io::Write;
    file.write_all(format!("{}\n", jd_crypto::b64::encode(&sodium)).as_bytes())
        .and_then(|_| file.sync_all())
        .map_err(|e| format!("cannot write {}: {e}", out.display()))?;

    println!("{}", jd_crypto::b64::encode(pair.public_key().as_ref()));
    eprintln!(
        "Wrote the private key to {}. The line above is the public key: add it to \
         jd-daemon/src/update/trusted_keys.txt.",
        out.display()
    );
    Ok(())
}

fn public_key(args: &Args) -> Result<(), String> {
    let pair = load_key(args)?;
    println!("{}", jd_crypto::b64::encode(pair.public_key().as_ref()));
    Ok(())
}

/// The signing key, from where it was pointed to and nowhere else.
fn load_key(args: &Args) -> Result<Ed25519KeyPair, String> {
    let path = match args.maybe("key") {
        Some(p) => PathBuf::from(p),
        None => match std::env::var_os(KEY_ENV) {
            Some(p) if !p.is_empty() => PathBuf::from(p),
            _ => {
                return Err(format!(
                    "no signing key given: pass --key PATH or set {KEY_ENV} to its path"
                ))
            }
        },
    };
    refuse_if_readable_by_others(&path)?;
    refuse_if_trackable(&path)?;
    let text = std::fs::read_to_string(&path)
        .map_err(|e| format!("cannot read the key at {}: {e}", path.display()))?;
    let raw = jd_crypto::b64::decode(text.trim())
        .map_err(|_| format!("{} is not a base64 key", path.display()))?;
    match raw.len() {
        64 => Ed25519KeyPair::from_seed_and_public_key(&raw[..32], &raw[32..]).map_err(|_| {
            format!(
                "{}: the public half does not match the seed",
                path.display()
            )
        }),
        32 => Ed25519KeyPair::from_seed_unchecked(&raw).map_err(|e| e.to_string()),
        n => Err(format!(
            "{} holds {n} bytes; an Ed25519 key file holds 64 (libsodium) or 32 (a seed)",
            path.display()
        )),
    }
}

#[cfg(unix)]
fn refuse_if_readable_by_others(path: &Path) -> Result<(), String> {
    use std::os::unix::fs::PermissionsExt;
    let mode = std::fs::metadata(path)
        .map_err(|e| format!("cannot read the key at {}: {e}", path.display()))?
        .permissions()
        .mode();
    if mode & 0o077 != 0 {
        return Err(format!(
            "{} can be read by other accounts (mode {:o}); chmod 600 it first",
            path.display(),
            mode & 0o777
        ));
    }
    Ok(())
}

#[cfg(not(unix))]
fn refuse_if_readable_by_others(_path: &Path) -> Result<(), String> {
    Ok(())
}

/// Refuse a key inside a git working tree that git would track.
///
/// Asked of git itself (`check-ignore`), because only git knows its ignore
/// rules: a key in an ignored directory such as a site's `config/` is fine,
/// and one beside the source is a key one `git add .` away from being public.
/// A file git already tracks is never reported as ignored, so a committed key
/// is refused too. A machine without git has no working tree to commit from.
fn refuse_if_trackable(path: &Path) -> Result<(), String> {
    // The directory is resolved rather than the file, so this can be asked of
    // a key about to be written as well as of one that exists.
    let parent = match path.parent() {
        Some(p) if !p.as_os_str().is_empty() => p.to_path_buf(),
        _ => PathBuf::from("."),
    };
    let (Ok(dir), Some(name)) = (std::fs::canonicalize(&parent), path.file_name()) else {
        return Ok(());
    };
    let canonical = dir.join(name);
    let dir = dir.as_path();
    let inside = std::process::Command::new("git")
        .arg("-C")
        .arg(dir)
        .args(["rev-parse", "--is-inside-work-tree"])
        .stdout(std::process::Stdio::null())
        .stderr(std::process::Stdio::null())
        .status();
    if !matches!(inside, Ok(s) if s.success()) {
        return Ok(());
    }
    let ignored = std::process::Command::new("git")
        .arg("-C")
        .arg(dir)
        .args(["check-ignore", "-q"])
        .arg(&canonical)
        .status();
    match ignored {
        Ok(s) if s.success() => Ok(()),
        _ => Err(format!(
            "{} is inside a git working tree and not ignored there; keep signing keys \
             outside the repository",
            path.display()
        )),
    }
}

// ---------------------------------------------------------------------------
// Payloads and manifests
// ---------------------------------------------------------------------------

fn pack(args: &Args) -> Result<(), String> {
    let out = PathBuf::from(args.one("out")?);
    if args.positional.is_empty() {
        return Err("pack needs the files or the app to put in the payload".into());
    }
    let sources: Vec<PathBuf> = args.positional.iter().map(PathBuf::from).collect();
    let file =
        std::fs::File::create(&out).map_err(|e| format!("cannot create {}: {e}", out.display()))?;
    payload::pack(&sources, std::io::BufWriter::new(file)).map_err(|e| e.to_string())?;
    let (size, sha) = hash_file(&out)?;
    println!("{sha}  {size}  {}", out.display());
    Ok(())
}

fn unpack(args: &Args) -> Result<(), String> {
    let out = PathBuf::from(args.one("out")?);
    let [file] = &args.positional[..] else {
        return Err("unpack takes one payload".into());
    };
    let source = std::fs::File::open(file).map_err(|e| format!("cannot read {file}: {e}"))?;
    let roots =
        payload::unpack(std::io::BufReader::new(source), &out).map_err(|e| e.to_string())?;
    for root in roots {
        println!("{}", out.join(root).display());
    }
    Ok(())
}

fn write_manifest(args: &Args) -> Result<(), String> {
    let base = args.one("base-url")?.trim_end_matches('/').to_string();
    let mut artifacts = Vec::new();
    for spec in args.all("artifact") {
        let parts: Vec<&str> = spec.splitn(4, ':').collect();
        let [os, arch, kind, file] = parts[..] else {
            return Err(format!("--artifact {spec}: expected OS:ARCH:KIND:FILE"));
        };
        let path = PathBuf::from(file);
        let (size, sha256) = hash_file(&path)?;
        let name = path
            .file_name()
            .ok_or_else(|| format!("{file} has no file name"))?
            .to_string_lossy()
            .to_string();
        artifacts.push(Artifact {
            os: os.into(),
            arch: arch.into(),
            kind: kind.into(),
            url: format!("{base}/{name}"),
            size,
            sha256,
        });
    }
    if artifacts.is_empty() {
        return Err("a manifest lists at least one --artifact".into());
    }
    let m = Manifest {
        format: FORMAT.into(),
        channel: args.one("channel")?,
        version: args.one("version")?,
        minimum_supported_version: args.maybe("minimum"),
        release_notes_url: args.maybe("notes"),
        artifacts,
    };
    // Refuse to write what no client would accept.
    m.validate().map_err(|e| e.to_string())?;
    let out = PathBuf::from(args.one("out")?);
    let mut body = serde_json::to_vec_pretty(&m).map_err(|e| e.to_string())?;
    body.push(b'\n');
    std::fs::write(&out, &body).map_err(|e| format!("cannot write {}: {e}", out.display()))?;
    println!("{}", out.display());
    Ok(())
}

fn sign(args: &Args) -> Result<(), String> {
    let path = PathBuf::from(args.one("manifest")?);
    let body = std::fs::read(&path).map_err(|e| format!("cannot read {}: {e}", path.display()))?;
    let m: Manifest =
        serde_json::from_slice(&body).map_err(|e| format!("{}: {e}", path.display()))?;
    m.validate().map_err(|e| e.to_string())?;

    let pair = load_key(args)?;
    let signature = pair.sign(&body);
    // Checked against the public half before it is written: a signature
    // nobody verified at signing time is one discovered to be wrong by every
    // client refusing the feed.
    let public: [u8; 32] = pair.public_key().as_ref().try_into().expect("32 bytes");
    let line = format!("{}\n", jd_crypto::b64::encode(signature.as_ref()));
    manifest::verify_signature(
        &body,
        line.as_bytes(),
        &TrustedKeys::from_keys(vec![public]),
    )
    .map_err(|e| format!("the signature does not verify against its own key: {e}"))?;

    let sig_path = PathBuf::from(format!("{}.sig", path.display()));
    let mut text = if args.flag("append") {
        std::fs::read_to_string(&sig_path).unwrap_or_default()
    } else {
        String::new()
    };
    if !text.is_empty() && !text.ends_with('\n') {
        text.push('\n');
    }
    text.push_str(&line);
    std::fs::write(&sig_path, text)
        .map_err(|e| format!("cannot write {}: {e}", sig_path.display()))?;
    println!(
        "{} signed by {}",
        sig_path.display(),
        jd_crypto::b64::encode(&public)
    );
    Ok(())
}

fn verify(args: &Args) -> Result<(), String> {
    let path = PathBuf::from(args.one("manifest")?);
    let sig_path = args
        .maybe("sig")
        .map(PathBuf::from)
        .unwrap_or_else(|| PathBuf::from(format!("{}.sig", path.display())));
    let body = std::fs::read(&path).map_err(|e| format!("cannot read {}: {e}", path.display()))?;
    let sig =
        std::fs::read(&sig_path).map_err(|e| format!("cannot read {}: {e}", sig_path.display()))?;
    let keys = TrustedKeys::parse(&args.all("trust").join("\n"))?;
    let channel = match args.maybe("channel") {
        Some(c) => c,
        None => serde_json::from_slice::<Manifest>(&body)
            .map(|m| m.channel)
            .map_err(|e| format!("{}: {e}", path.display()))?,
    };
    let m = manifest::open(&body, &sig, &keys, &channel).map_err(|e| e.to_string())?;
    // Every artifact named beside the manifest is checked too, when present.
    let dir = path.parent().unwrap_or(Path::new("."));
    for a in &m.artifacts {
        let name = a.url.rsplit('/').next().unwrap_or("");
        let local = dir.join(name);
        if local.is_file() {
            let (size, sha) = hash_file(&local)?;
            if size != a.size || sha != a.sha256 {
                return Err(format!("{} does not match the manifest", local.display()));
            }
        }
    }
    println!(
        "{} {} on {}: signature good",
        path.display(),
        m.version,
        m.channel
    );
    Ok(())
}

fn hash_file(path: &Path) -> Result<(u64, String), String> {
    use std::io::Read;
    let mut file =
        std::fs::File::open(path).map_err(|e| format!("cannot read {}: {e}", path.display()))?;
    let mut hash = ring::digest::Context::new(&ring::digest::SHA256);
    let mut buf = vec![0u8; 64 * 1024];
    let mut size = 0u64;
    loop {
        let n = file
            .read(&mut buf)
            .map_err(|e| format!("cannot read {}: {e}", path.display()))?;
        if n == 0 {
            break;
        }
        size += n as u64;
        hash.update(&buf[..n]);
    }
    Ok((size, jd_daemon::update::hex(hash.finish().as_ref())))
}

// ---------------------------------------------------------------------------
// Arguments
// ---------------------------------------------------------------------------

/// Positionals, `--name value` pairs (repeatable), and bare `--append`.
struct Args {
    positional: Vec<String>,
    named: Vec<(String, String)>,
    flags: Vec<String>,
}

const BARE_FLAGS: &[&str] = &["append"];

impl Args {
    fn parse(raw: &[String]) -> Result<Args, String> {
        let mut args = Args {
            positional: Vec::new(),
            named: Vec::new(),
            flags: Vec::new(),
        };
        let mut i = 0;
        while i < raw.len() {
            match raw[i].strip_prefix("--") {
                Some(name) if BARE_FLAGS.contains(&name) => args.flags.push(name.to_string()),
                Some(name) => {
                    i += 1;
                    let value = raw
                        .get(i)
                        .ok_or_else(|| format!("--{name} needs a value"))?;
                    args.named.push((name.to_string(), value.clone()));
                }
                None => args.positional.push(raw[i].clone()),
            }
            i += 1;
        }
        Ok(args)
    }

    fn maybe(&self, name: &str) -> Option<String> {
        self.named
            .iter()
            .rev()
            .find(|(n, _)| n == name)
            .map(|(_, v)| v.clone())
    }

    fn one(&self, name: &str) -> Result<String, String> {
        self.maybe(name)
            .ok_or_else(|| format!("--{name} is required"))
    }

    fn all(&self, name: &str) -> Vec<String> {
        self.named
            .iter()
            .filter(|(n, _)| n == name)
            .map(|(_, v)| v.clone())
            .collect()
    }

    fn flag(&self, name: &str) -> bool {
        self.flags.iter().any(|f| f == name)
    }
}
