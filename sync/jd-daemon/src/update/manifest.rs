//! The update feed's one document: what the newest release on a channel is,
//! and where each platform's copy of it lives.
//!
//! ```json
//! {
//!   "format": "joinery-drive-update/1",
//!   "channel": "beta",
//!   "version": "0.3.0",
//!   "minimum_supported_version": "0.2.0",
//!   "release_notes_url": "https://getjoinery.com/drive/releases/0.3.0",
//!   "artifacts": [
//!     { "os": "macos", "arch": "universal", "kind": "update",
//!       "url": "https://…/joinery-drive-0.3.0-macos-universal.update.tar.gz",
//!       "size": 9123456, "sha256": "…64 hex…" },
//!     { "os": "linux", "arch": "x86_64", "kind": "deb", "url": "…", … }
//!   ]
//! }
//! ```
//!
//! Beside it, at the same URL plus `.sig`, is a detached Ed25519 signature over
//! **the exact bytes** of that document, base64, one per line. The bytes are
//! what is signed, not a re-serialization of them: two JSON encoders agree on
//! meaning and disagree on whitespace, and a signature checked against
//! anything but the bytes received is a signature over something else.
//!
//! More than one line is how a key is rotated without stranding anybody. During
//! a rotation the publisher signs with the old key and the new one; a client
//! that knows only the old key and a client that knows only the new one both
//! find a line they can check.
//!
//! **Only `kind: "update"` is ever installed.** It is the payload this client
//! knows how to put in place ([`super::payload`]): the app bundle on a Mac, the
//! program's files elsewhere. The other kinds — `dmg`, `installer`, `deb`,
//! `rpm`, `tarball` — are listed for the download page and for anything else
//! that reads the feed, and the client never touches them.
//!
//! The order of checks is the point of this file: **signature, then parse,
//! then everything else.** Nothing about an unsigned document is believed, not
//! even its version number, so nothing in it can steer the client before the
//! signature has been checked.

use std::fmt;
use std::str::FromStr;

use ring::signature::{UnparsedPublicKey, ED25519};
use serde::{Deserialize, Serialize};

/// The format this client reads. A future incompatible change gets a new
/// number, and a client that does not know it says so rather than guessing.
pub const FORMAT: &str = "joinery-drive-update/1";

/// The one artifact kind the client installs.
pub const UPDATE_KIND: &str = "update";

/// How large a manifest may be. It is a short list of links; anything larger is
/// not one, and reading it would be letting the feed decide how much memory the
/// daemon spends.
pub const MAX_MANIFEST_BYTES: usize = 1 << 20;
pub const MAX_SIGNATURE_BYTES: usize = 16 << 10;

/// The largest artifact the client will download.
pub const MAX_ARTIFACT_BYTES: u64 = 1 << 30;

/// A release number: `MAJOR.MINOR.PATCH`, optionally `-alpha.N`, `-beta.N`
/// or `-rc.N` — the grammar the workspace version is held to, which every
/// installer is stamped with and the server's upgrade gate orders with PHP's
/// `version_compare()`. Compared the same way here: numbers as numbers, a
/// pre-release before its release, alpha before beta before rc, and a missing
/// `.N` as `.0`.
///
/// Nothing looser is accepted. Build metadata (`+…`) or a free-form suffix has
/// no agreed order, and the one comparison an updater must never get wrong is
/// which of two versions is newer.
#[derive(Debug, Clone, Copy)]
pub struct Version {
    pub major: u64,
    pub minor: u64,
    pub patch: u64,
    pub pre: Option<Pre>,
}

/// A pre-release: its stage, and its number when it has one.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Pre {
    pub stage: Stage,
    pub number: Option<u64>,
}

/// Pre-release stages, in the order they come.
#[derive(Debug, Clone, Copy, PartialEq, Eq, PartialOrd, Ord)]
pub enum Stage {
    Alpha,
    Beta,
    Rc,
}

impl Stage {
    fn as_str(&self) -> &'static str {
        match self {
            Stage::Alpha => "alpha",
            Stage::Beta => "beta",
            Stage::Rc => "rc",
        }
    }
}

impl Version {
    pub const fn release(major: u64, minor: u64, patch: u64) -> Version {
        Version {
            major,
            minor,
            patch,
            pre: None,
        }
    }
}

impl Ord for Version {
    fn cmp(&self, other: &Version) -> std::cmp::Ordering {
        use std::cmp::Ordering::*;
        (self.major, self.minor, self.patch)
            .cmp(&(other.major, other.minor, other.patch))
            .then_with(|| match (self.pre, other.pre) {
                (None, None) => Equal,
                // 1.0.0-rc.9 comes before 1.0.0.
                (None, Some(_)) => Greater,
                (Some(_), None) => Less,
                (Some(a), Some(b)) => {
                    (a.stage, a.number.unwrap_or(0)).cmp(&(b.stage, b.number.unwrap_or(0)))
                }
            })
    }
}

impl PartialOrd for Version {
    fn partial_cmp(&self, other: &Version) -> Option<std::cmp::Ordering> {
        Some(self.cmp(other))
    }
}

/// Equal exactly when neither is newer, so `-beta` and `-beta.0` are one
/// version, as they are to the gate.
impl PartialEq for Version {
    fn eq(&self, other: &Version) -> bool {
        self.cmp(other) == std::cmp::Ordering::Equal
    }
}

impl Eq for Version {}

impl FromStr for Version {
    type Err = String;

    fn from_str(s: &str) -> Result<Version, String> {
        let bad = || format!("{s:?} is not a version of the form 1.2.3 or 1.2.3-beta.4");
        let digits = |part: &str| -> Result<u64, String> {
            // `parse` accepts a leading `+`; a version does not.
            if part.is_empty() || !part.bytes().all(|b| b.is_ascii_digit()) {
                return Err(bad());
            }
            part.parse()
                .map_err(|_| format!("{s:?} has a component too large to be a version"))
        };
        let (release, pre) = match s.split_once('-') {
            Some((r, p)) => (r, Some(p)),
            None => (s, None),
        };
        let parts: Vec<&str> = release.split('.').collect();
        let [major, minor, patch] = parts[..] else {
            return Err(bad());
        };
        let pre = match pre {
            None => None,
            Some(p) => {
                let (word, number) = match p.split_once('.') {
                    Some((w, n)) => (w, Some(digits(n)?)),
                    None => (p, None),
                };
                let stage = match word {
                    "alpha" => Stage::Alpha,
                    "beta" => Stage::Beta,
                    "rc" => Stage::Rc,
                    _ => return Err(bad()),
                };
                Some(Pre { stage, number })
            }
        };
        Ok(Version {
            major: digits(major)?,
            minor: digits(minor)?,
            patch: digits(patch)?,
            pre,
        })
    }
}

impl fmt::Display for Version {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        write!(f, "{}.{}.{}", self.major, self.minor, self.patch)?;
        if let Some(pre) = self.pre {
            write!(f, "-{}", pre.stage.as_str())?;
            if let Some(n) = pre.number {
                write!(f, ".{n}")?;
            }
        }
        Ok(())
    }
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct Manifest {
    pub format: String,
    pub channel: String,
    pub version: String,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub minimum_supported_version: Option<String>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub release_notes_url: Option<String>,
    pub artifacts: Vec<Artifact>,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct Artifact {
    pub os: String,
    pub arch: String,
    pub kind: String,
    pub url: String,
    pub size: u64,
    pub sha256: String,
}

/// Why a feed was not believed, or not usable.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum FeedError {
    #[error("this build carries no update signing key, so it cannot check the update feed")]
    NoTrustedKeys,
    #[error("the update feed is not signed by a key this build trusts")]
    NotTrusted,
    #[error("the update feed's signature file is not in the expected format")]
    SignatureUnreadable,
    #[error("the update feed is not readable: {0}")]
    Malformed(String),
    #[error("the update feed is for the {got} channel, not {expected}")]
    WrongChannel { expected: String, got: String },
    #[error("the update feed has no update for {os}/{arch}")]
    NoTarget { os: String, arch: String },
}

/// The public keys this client accepts a manifest from.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct TrustedKeys(Vec<[u8; 32]>);

impl TrustedKeys {
    /// Read a key list: one base64 Ed25519 public key per line, `#` starting a
    /// comment, blank lines ignored.
    pub fn parse(text: &str) -> Result<TrustedKeys, String> {
        let mut keys = Vec::new();
        for (n, line) in text.lines().enumerate() {
            let line = line.split('#').next().unwrap_or("").trim();
            if line.is_empty() {
                continue;
            }
            let raw =
                jd_crypto::b64::decode(line).map_err(|_| format!("line {}: not base64", n + 1))?;
            let key: [u8; 32] = raw
                .as_slice()
                .try_into()
                .map_err(|_| format!("line {}: an Ed25519 public key is 32 bytes", n + 1))?;
            if !keys.contains(&key) {
                keys.push(key);
            }
        }
        Ok(TrustedKeys(keys))
    }

    /// The keys this build was compiled with (`trusted_keys.txt`, beside this
    /// file). In the repository rather than in configuration, so that adding
    /// or retiring a key is a commit anybody can read, and so that nothing a
    /// local process writes can teach the client to trust somebody new.
    ///
    /// An error rather than a panic: a bad line committed to the list would
    /// otherwise stop every daemon built from it at start, sync and all. The
    /// updater reports it and checks nothing; a test keeps it from shipping.
    pub fn built_in() -> Result<TrustedKeys, String> {
        TrustedKeys::parse(include_str!("trusted_keys.txt"))
    }

    pub fn from_keys(keys: Vec<[u8; 32]>) -> TrustedKeys {
        TrustedKeys(keys)
    }

    pub fn is_empty(&self) -> bool {
        self.0.is_empty()
    }

    pub fn len(&self) -> usize {
        self.0.len()
    }
}

/// Check a manifest's signature file against the trusted keys.
///
/// Any line, under any key. A bad signature, a signature by a stranger's key,
/// and a document changed after signing are one answer, because Ed25519 cannot
/// tell them apart and the client does not need to: none of them is a feed to
/// act on.
pub fn verify_signature(
    body: &[u8],
    signature_file: &[u8],
    keys: &TrustedKeys,
) -> Result<(), FeedError> {
    if keys.is_empty() {
        return Err(FeedError::NoTrustedKeys);
    }
    let text = std::str::from_utf8(signature_file).map_err(|_| FeedError::SignatureUnreadable)?;
    let mut any = false;
    for line in text.lines() {
        let line = line.split('#').next().unwrap_or("").trim();
        if line.is_empty() {
            continue;
        }
        let Ok(signature) = jd_crypto::b64::decode(line) else {
            return Err(FeedError::SignatureUnreadable);
        };
        if signature.len() != 64 {
            return Err(FeedError::SignatureUnreadable);
        }
        any = true;
        for key in &keys.0 {
            if UnparsedPublicKey::new(&ED25519, key)
                .verify(body, &signature)
                .is_ok()
            {
                return Ok(());
            }
        }
    }
    if !any {
        return Err(FeedError::SignatureUnreadable);
    }
    Err(FeedError::NotTrusted)
}

/// Verify, then read, then validate. The only way a [`Manifest`] comes into
/// existence from the network.
pub fn open(
    body: &[u8],
    signature_file: &[u8],
    keys: &TrustedKeys,
    channel: &str,
) -> Result<Manifest, FeedError> {
    verify_signature(body, signature_file, keys)?;
    let manifest: Manifest =
        serde_json::from_slice(body).map_err(|e| FeedError::Malformed(e.to_string()))?;
    manifest.validate()?;
    // A channel's manifest is signed with the same key as every other
    // channel's. Without this check, a stable client pointed at — or served —
    // the beta document would accept it, and "stable" would mean nothing.
    if manifest.channel != channel {
        return Err(FeedError::WrongChannel {
            expected: channel.to_string(),
            got: manifest.channel.clone(),
        });
    }
    Ok(manifest)
}

impl Manifest {
    /// Everything that must hold before any field is acted on.
    pub fn validate(&self) -> Result<(), FeedError> {
        let bad = |why: String| Err(FeedError::Malformed(why));
        if self.format != FORMAT {
            return bad(format!(
                "format {:?} is not {FORMAT:?}; a newer client may read it",
                self.format
            ));
        }
        if self.channel.trim().is_empty() {
            return bad("no channel".into());
        }
        if let Err(e) = self.version.parse::<Version>() {
            return bad(e);
        }
        if let Some(min) = &self.minimum_supported_version {
            if let Err(e) = min.parse::<Version>() {
                return bad(e);
            }
        }
        for a in &self.artifacts {
            if a.os.is_empty() || a.arch.is_empty() || a.kind.is_empty() {
                return bad("an artifact without os, arch or kind".into());
            }
            if a.sha256.len() != 64
                || !a
                    .sha256
                    .bytes()
                    .all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b))
            {
                return bad(format!("{}: sha256 is not 64 lowercase hex digits", a.url));
            }
            if a.size == 0 || a.size > MAX_ARTIFACT_BYTES {
                return bad(format!("{}: size {} is not plausible", a.url, a.size));
            }
            if !acceptable_url(&a.url) {
                return bad(format!(
                    "{}: artifacts are fetched over https (or http from this machine)",
                    a.url
                ));
            }
        }
        Ok(())
    }

    pub fn version(&self) -> Version {
        self.version.parse().expect("validated")
    }

    pub fn minimum_supported(&self) -> Option<Version> {
        self.minimum_supported_version
            .as_ref()
            .map(|v| v.parse().expect("validated"))
    }

    /// The update payload for one platform.
    ///
    /// A Mac build matches its own architecture or `universal`, which is what
    /// a universal2 bundle is published as. Elsewhere the architecture has to
    /// match exactly: an x86-64 Windows binary runs on ARM under emulation, and
    /// replacing a native ARM install with an emulated one would be a silent
    /// downgrade.
    pub fn artifact_for(&self, os: &str, arch: &str) -> Option<&Artifact> {
        let exact = self
            .artifacts
            .iter()
            .find(|a| a.kind == UPDATE_KIND && a.os == os && a.arch == arch);
        exact.or_else(|| {
            (os == "macos").then(|| {
                self.artifacts
                    .iter()
                    .find(|a| a.kind == UPDATE_KIND && a.os == os && a.arch == "universal")
            })?
        })
    }
}

/// An artifact is fetched over TLS. Plain http is allowed only from this
/// machine, which is what a test serving its own feed needs and nothing else
/// does; the hash would catch tampering either way, but a feed that could be
/// read off the wire would still tell an observer exactly which build every
/// client runs.
pub fn acceptable_url(url: &str) -> bool {
    if url.starts_with("https://") {
        return true;
    }
    let Some(rest) = url.strip_prefix("http://") else {
        return false;
    };
    let host = rest.split(['/', '?']).next().unwrap_or("");
    let host = match host.rsplit_once(':') {
        Some((h, port)) if port.bytes().all(|b| b.is_ascii_digit()) => h,
        _ => host,
    };
    matches!(host, "127.0.0.1" | "localhost" | "[::1]")
}

/// What a verified manifest means for the running copy.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Decision {
    /// Nothing newer. Includes a feed whose version is *older* than this build:
    /// the client never installs a lower version, whatever a correctly signed
    /// document says, so an old manifest replayed by anybody is inert.
    UpToDate { latest: Version },
    /// A newer release, and the payload for this platform when there is one.
    Newer {
        version: Version,
        artifact: Option<Artifact>,
        /// This build is below the oldest version the feed still supports.
        required: bool,
    },
}

pub fn decide(current: Version, manifest: &Manifest, os: &str, arch: &str) -> Decision {
    let latest = manifest.version();
    if latest <= current {
        return Decision::UpToDate { latest };
    }
    Decision::Newer {
        version: latest,
        artifact: manifest.artifact_for(os, arch).cloned(),
        required: manifest
            .minimum_supported()
            .is_some_and(|min| current < min),
    }
}

#[cfg(test)]
pub(crate) mod tests {
    use super::*;
    use ring::rand::SystemRandom;
    use ring::signature::{Ed25519KeyPair, KeyPair};

    pub(crate) fn keypair() -> Ed25519KeyPair {
        let pkcs8 = Ed25519KeyPair::generate_pkcs8(&SystemRandom::new()).unwrap();
        Ed25519KeyPair::from_pkcs8(pkcs8.as_ref()).unwrap()
    }

    pub(crate) fn trust(pair: &Ed25519KeyPair) -> TrustedKeys {
        TrustedKeys::from_keys(vec![pair.public_key().as_ref().try_into().unwrap()])
    }

    pub(crate) fn sign(pair: &Ed25519KeyPair, body: &[u8]) -> Vec<u8> {
        format!("{}\n", jd_crypto::b64::encode(pair.sign(body).as_ref())).into_bytes()
    }

    pub(crate) fn sample(version: &str) -> Manifest {
        Manifest {
            format: FORMAT.into(),
            channel: "beta".into(),
            version: version.into(),
            minimum_supported_version: Some("0.2.0".into()),
            release_notes_url: Some("https://getjoinery.com/drive/releases".into()),
            artifacts: vec![
                Artifact {
                    os: "macos".into(),
                    arch: "universal".into(),
                    kind: "update".into(),
                    url: "https://getjoinery.com/drive/updates/files/m.tar.gz".into(),
                    size: 10,
                    sha256: "a".repeat(64),
                },
                Artifact {
                    os: "linux".into(),
                    arch: "x86_64".into(),
                    kind: "update".into(),
                    url: "https://getjoinery.com/drive/updates/files/l.tar.gz".into(),
                    size: 10,
                    sha256: "b".repeat(64),
                },
                Artifact {
                    os: "linux".into(),
                    arch: "x86_64".into(),
                    kind: "deb".into(),
                    url: "https://getjoinery.com/drive/updates/files/l.deb".into(),
                    size: 10,
                    sha256: "c".repeat(64),
                },
            ],
        }
    }

    fn body(m: &Manifest) -> Vec<u8> {
        serde_json::to_vec_pretty(m).unwrap()
    }

    #[test]
    fn a_manifest_signed_by_a_trusted_key_opens() {
        let pair = keypair();
        let bytes = body(&sample("0.3.0"));
        let m = open(&bytes, &sign(&pair, &bytes), &trust(&pair), "beta").unwrap();
        assert_eq!(m.version(), Version::release(0, 3, 0));
    }

    #[test]
    fn a_signature_by_a_key_this_build_does_not_trust_is_refused() {
        let signer = keypair();
        let trusted = keypair();
        let bytes = body(&sample("0.3.0"));
        assert_eq!(
            open(&bytes, &sign(&signer, &bytes), &trust(&trusted), "beta"),
            Err(FeedError::NotTrusted)
        );
    }

    #[test]
    fn one_byte_changed_after_signing_is_refused() {
        let pair = keypair();
        let bytes = body(&sample("0.3.0"));
        let sig = sign(&pair, &bytes);
        // The version is the field worth forging: it is what makes a client
        // download something.
        let tampered = String::from_utf8(bytes).unwrap().replace("0.3.0", "9.3.0");
        assert_eq!(
            open(tampered.as_bytes(), &sig, &trust(&pair), "beta"),
            Err(FeedError::NotTrusted)
        );
    }

    #[test]
    fn the_signature_covers_the_bytes_not_a_reencoding_of_them() {
        // The same document with different whitespace is different bytes, and
        // a client that re-serialized before checking would accept it.
        let pair = keypair();
        let m = sample("0.3.0");
        let pretty = serde_json::to_vec_pretty(&m).unwrap();
        let compact = serde_json::to_vec(&m).unwrap();
        assert_eq!(
            open(&compact, &sign(&pair, &pretty), &trust(&pair), "beta"),
            Err(FeedError::NotTrusted)
        );
    }

    #[test]
    fn a_garbage_or_empty_signature_file_is_not_a_signature() {
        let pair = keypair();
        let bytes = body(&sample("0.3.0"));
        for sig in [&b""[..], b"\n\n", b"not base64 at all!!", b"AAAA"] {
            assert_eq!(
                verify_signature(&bytes, sig, &trust(&pair)),
                Err(FeedError::SignatureUnreadable),
                "{:?}",
                String::from_utf8_lossy(sig)
            );
        }
    }

    #[test]
    fn a_build_with_no_keys_trusts_nothing_rather_than_everything() {
        let pair = keypair();
        let bytes = body(&sample("0.3.0"));
        assert_eq!(
            open(
                &bytes,
                &sign(&pair, &bytes),
                &TrustedKeys::default(),
                "beta"
            ),
            Err(FeedError::NoTrustedKeys)
        );
    }

    #[test]
    fn during_a_rotation_either_key_alone_is_enough() {
        // The publisher signs with both; a client that knows only the old key
        // and a client that knows only the new one each find a line to check.
        let old = keypair();
        let new = keypair();
        let bytes = body(&sample("0.3.0"));
        let mut both = sign(&old, &bytes);
        both.extend(sign(&new, &bytes));
        assert!(verify_signature(&bytes, &both, &trust(&old)).is_ok());
        assert!(verify_signature(&bytes, &both, &trust(&new)).is_ok());
        // And a client holding both keys accepts a manifest signed by either.
        let two = TrustedKeys::from_keys(vec![
            old.public_key().as_ref().try_into().unwrap(),
            new.public_key().as_ref().try_into().unwrap(),
        ]);
        assert!(verify_signature(&bytes, &sign(&new, &bytes), &two).is_ok());
    }

    #[test]
    fn a_correctly_signed_manifest_for_another_channel_is_refused() {
        let pair = keypair();
        let bytes = body(&sample("0.3.0"));
        assert_eq!(
            open(&bytes, &sign(&pair, &bytes), &trust(&pair), "stable"),
            Err(FeedError::WrongChannel {
                expected: "stable".into(),
                got: "beta".into()
            })
        );
    }

    #[test]
    fn a_signed_manifest_with_nonsense_in_it_is_still_refused() {
        // Signed is not the same as sane: a publishing mistake should stop at
        // the client, not install.
        let pair = keypair();
        type Mutation = Box<dyn Fn(&mut Manifest)>;
        let cases: Vec<Mutation> = vec![
            Box::new(|m| m.format = "joinery-drive-update/2".into()),
            Box::new(|m| m.version = "1.2".into()),
            Box::new(|m| m.version = "1.2.3+build.7".into()),
            Box::new(|m| m.minimum_supported_version = Some("one".into())),
            Box::new(|m| m.artifacts[0].sha256 = "A".repeat(64)),
            Box::new(|m| m.artifacts[0].sha256 = "a".repeat(63)),
            Box::new(|m| m.artifacts[0].size = 0),
            Box::new(|m| m.artifacts[0].url = "http://getjoinery.com/x.tar.gz".into()),
            Box::new(|m| m.artifacts[0].url = "file:///etc/passwd".into()),
        ];
        for (i, mutate) in cases.iter().enumerate() {
            let mut m = sample("0.3.0");
            mutate(&mut m);
            let bytes = body(&m);
            assert!(
                matches!(
                    open(&bytes, &sign(&pair, &bytes), &trust(&pair), "beta"),
                    Err(FeedError::Malformed(_))
                ),
                "case {i}"
            );
        }
    }

    #[test]
    fn fields_added_by_a_later_publisher_do_not_break_this_client() {
        // Additive changes keep the format number; only a change an old client
        // would misread gets a new one.
        let pair = keypair();
        let mut value = serde_json::to_value(sample("0.3.0")).unwrap();
        value["published"] = "2026-10-15T00:00:00Z".into();
        value["artifacts"][0]["signature_team"] = "ABCDE12345".into();
        let bytes = serde_json::to_vec(&value).unwrap();
        assert!(open(&bytes, &sign(&pair, &bytes), &trust(&pair), "beta").is_ok());
    }

    #[test]
    fn versions_compare_as_numbers_not_as_text() {
        let v = |s: &str| s.parse::<Version>().unwrap();
        assert!(v("0.10.0") > v("0.9.0"));
        assert!(v("1.0.0") > v("0.99.99"));
        assert!(v("0.3.10") > v("0.3.9"));
        for bad in [
            "",
            "1",
            "1.2",
            "1.2.3.4",
            "1.2.x",
            "+1.2.3",
            "1..3",
            " 1.2.3 x",
            "1.2.3-",
            "1.2.3-gamma",
            "1.2.3-beta.x",
            "1.2.3+build.5",
            "1.2.3-beta.1.2",
            "1.2.3-beta-1",
        ] {
            assert!(bad.parse::<Version>().is_err(), "{bad:?}");
        }
    }

    #[test]
    fn pre_releases_order_the_way_the_server_gate_orders_them() {
        // The order PHP's version_compare() gives these, which is what the
        // upgrade gate and the installers use: a beta updates to its rc, an rc
        // to its release, and a release never "updates" back to a beta of
        // itself.
        let ordered = [
            "0.1.0-alpha",
            "0.1.0-alpha.1",
            "0.1.0-beta",
            "0.1.0-beta.2",
            "0.1.0-beta.10",
            "0.1.0-rc.1",
            "0.1.0",
            "0.1.1-alpha.1",
            "0.1.1",
            "0.10.0-beta.1",
        ];
        let parsed: Vec<Version> = ordered.iter().map(|s| s.parse().unwrap()).collect();
        for pair in parsed.windows(2) {
            assert!(pair[0] < pair[1], "{} < {}", pair[0], pair[1]);
        }
        // Written back the way it was read.
        for s in ordered {
            assert_eq!(s.parse::<Version>().unwrap().to_string(), s);
        }
        // A missing number is .0, as it is to the gate.
        assert_eq!(
            "1.0.0-beta".parse::<Version>().unwrap(),
            "1.0.0-beta.0".parse::<Version>().unwrap()
        );
    }

    #[test]
    fn the_client_never_installs_a_lower_or_equal_version() {
        let m = sample("0.3.0");
        assert_eq!(
            decide(Version::release(0, 3, 0), &m, "linux", "x86_64"),
            Decision::UpToDate {
                latest: Version::release(0, 3, 0)
            }
        );
        // A correctly signed but old manifest, replayed: inert.
        assert_eq!(
            decide(Version::release(0, 4, 1), &m, "linux", "x86_64"),
            Decision::UpToDate {
                latest: Version::release(0, 3, 0)
            }
        );
        match decide(Version::release(0, 2, 5), &m, "linux", "x86_64") {
            Decision::Newer {
                version,
                artifact: Some(a),
                required,
            } => {
                assert_eq!(version, Version::release(0, 3, 0));
                assert_eq!(a.kind, "update");
                assert!(!required);
            }
            other => panic!("{other:?}"),
        }
    }

    #[test]
    fn below_the_minimum_supported_version_the_update_is_required() {
        let m = sample("0.3.0");
        assert!(matches!(
            decide(Version::release(0, 1, 9), &m, "linux", "x86_64"),
            Decision::Newer { required: true, .. }
        ));
    }

    #[test]
    fn a_platform_the_feed_does_not_carry_is_told_apart_from_up_to_date() {
        // Newer exists, nothing to install here. Saying "up to date" would hide
        // that this machine has been left behind.
        let m = sample("0.3.0");
        assert!(matches!(
            decide(Version::release(0, 2, 0), &m, "windows", "aarch64"),
            Decision::Newer { artifact: None, .. }
        ));
        assert!(matches!(
            decide(Version::release(0, 2, 0), &m, "linux", "aarch64"),
            Decision::Newer { artifact: None, .. }
        ));
    }

    #[test]
    fn only_the_update_payload_is_ever_chosen_and_a_mac_takes_universal() {
        let m = sample("0.3.0");
        assert_eq!(m.artifact_for("linux", "x86_64").unwrap().kind, "update");
        assert_eq!(
            m.artifact_for("macos", "aarch64").unwrap().arch,
            "universal"
        );
        assert_eq!(m.artifact_for("macos", "x86_64").unwrap().arch, "universal");
        // Universal is a Mac idea; nowhere else does it stand in for an arch.
        let mut m = sample("0.3.0");
        m.artifacts[1].arch = "universal".into();
        assert!(m.artifact_for("linux", "x86_64").is_none());
    }

    #[test]
    fn plain_http_is_accepted_only_from_this_machine() {
        assert!(acceptable_url("https://getjoinery.com/a"));
        assert!(acceptable_url("http://127.0.0.1:8080/a"));
        assert!(acceptable_url("http://localhost/a"));
        assert!(!acceptable_url("http://getjoinery.com/a"));
        assert!(!acceptable_url("http://127.0.0.1.evil.com/a"));
        assert!(!acceptable_url("ftp://127.0.0.1/a"));
    }

    #[test]
    fn the_built_in_key_list_parses() {
        // Empty today, which parses. A bad line committed later fails here,
        // before it reaches a build, rather than in every daemon at start.
        if let Err(e) = TrustedKeys::built_in() {
            panic!("jd-daemon/src/update/trusted_keys.txt does not parse: {e}");
        }
        // And the parser does refuse what such a line would look like.
        assert!(TrustedKeys::parse("# a comment\n\n").unwrap().is_empty());
        assert!(TrustedKeys::parse("AAAA\n").is_err());
        assert!(TrustedKeys::parse("not base64 at all\n").is_err());
        let ok = format!("{}  # a comment\n", jd_crypto::b64::encode(&[7u8; 32]));
        assert_eq!(TrustedKeys::parse(&ok).unwrap().len(), 1);
    }
}
