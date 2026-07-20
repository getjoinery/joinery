//! jd-proto — the Joinery server API client for the sync clients
//! (specs/drive_sync_clients.md). Speaks the frozen `/api/v1` contract
//! (public_html/docs/api.md): session-key auth headers, the action envelope,
//! the resumable sequential-chunk upload protocol, and signed-URL downloads.
//!
//! Blocking by design: the engine (`jd-core`) runs a small bounded executor
//! (default 3 transfers) where straight-line blocking calls per worker are the
//! simplest correct shape, and the network trait this crate will grow for
//! `jd-sim` injection stays trivially mockable.

use std::io::{Read, Seek, SeekFrom, Write};

use rand_core::{OsRng, RngCore};
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use sha2::{Digest, Sha256};

/// Client identity for the 426 upgrade-gate handshake (docs/api.md
/// § Client Versioning). One id per OS at packaging time; plumbing builds
/// report the workspace version.
pub const CLIENT_APP: &str = "joinery-sync-linux";
pub const CLIENT_VERSION: &str = env!("CARGO_PKG_VERSION");

#[derive(Debug, thiserror::Error)]
pub enum ProtoError {
    /// The server answered with an error envelope. `errortype` is from the
    /// closed vocabulary (AuthenticationError, ActionError, ValidationError,
    /// SecurityError, UpgradeRequired, RateLimitError, NotFound,
    /// TransactionError); branch on it plus `status`, never on `message`.
    #[error("{errortype} ({status}): {message}")]
    Api {
        status: u16,
        errortype: String,
        message: String,
        data: Value,
    },
    #[error("transport: {0}")]
    Transport(String),
    #[error("response violated the API contract: {0}")]
    Contract(String),
    #[error("not authenticated — login first")]
    NoCredentials,
    #[error("local io: {0}")]
    Io(#[from] std::io::Error),
}

pub type Result<T> = std::result::Result<T, ProtoError>;

impl ProtoError {
    /// The resume offset carried by a 409 chunk-offset conflict, if that is
    /// what this error is.
    pub fn chunk_resync_offset(&self) -> Option<u64> {
        match self {
            ProtoError::Api {
                status: 409, data, ..
            } => data.get("received_bytes").and_then(Value::as_u64),
            _ => None,
        }
    }
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct Credentials {
    pub public_key: String,
    pub secret_key: String,
}

pub struct Client {
    base_url: String,
    agent: ureq::Agent,
    creds: Option<Credentials>,
}

impl Client {
    /// `base_url` like `https://dev.getjoinery.com` (scheme + host, no path).
    pub fn new(base_url: &str) -> Client {
        let agent = ureq::AgentBuilder::new()
            .timeout_connect(std::time::Duration::from_secs(15))
            .build();
        Client {
            base_url: base_url.trim_end_matches('/').to_string(),
            agent,
            creds: None,
        }
    }

    pub fn with_credentials(base_url: &str, creds: Credentials) -> Client {
        let mut c = Client::new(base_url);
        c.creds = Some(creds);
        c
    }

    pub fn credentials(&self) -> Option<&Credentials> {
        self.creds.as_ref()
    }

    pub fn base_url(&self) -> &str {
        &self.base_url
    }

    fn api_url(&self, path: &str) -> String {
        format!("{}/api/v1/{}", self.base_url, path)
    }

    fn request(&self, method: &str, path: &str, authed: bool) -> Result<ureq::Request> {
        // Hyphen-form header names throughout: Apache→FPM stacks silently drop
        // header names containing underscores, and the server normalizes both
        // spellings to one namespace (api/apiv1.php header normalization).
        let mut req = self
            .agent
            .request(method, &self.api_url(path))
            .set("client-app", CLIENT_APP)
            .set("client-version", CLIENT_VERSION);
        if authed {
            let creds = self.creds.as_ref().ok_or(ProtoError::NoCredentials)?;
            req = req
                .set("public-key", &creds.public_key)
                .set("secret-key", &creds.secret_key);
        }
        Ok(req)
    }

    /// Unwrap a ureq outcome into the success envelope's `data`.
    fn envelope(outcome: std::result::Result<ureq::Response, ureq::Error>) -> Result<Value> {
        match outcome {
            Ok(resp) => {
                let body: Value = resp
                    .into_json()
                    .map_err(|e| ProtoError::Contract(format!("success body is not JSON: {e}")))?;
                match body.get("data") {
                    Some(d) => Ok(d.clone()),
                    None => Err(ProtoError::Contract(
                        "success envelope missing `data`".into(),
                    )),
                }
            }
            Err(ureq::Error::Status(status, resp)) => {
                let body: Value = resp.into_json().unwrap_or(Value::Null);
                Err(ProtoError::Api {
                    status,
                    errortype: body
                        .get("errortype")
                        .and_then(Value::as_str)
                        .unwrap_or("(no errortype)")
                        .to_string(),
                    message: body
                        .get("error")
                        .and_then(Value::as_str)
                        .unwrap_or("(no error message)")
                        .to_string(),
                    data: body.get("data").cloned().unwrap_or(Value::Null),
                })
            }
            Err(e) => Err(ProtoError::Transport(e.to_string())),
        }
    }

    // ---- auth ---------------------------------------------------------------

    /// `POST /auth/login` — mints the per-device session key and installs it
    /// on this client. Returns the full login `data` (key pair, expiry, user
    /// summary). The secret is only ever returned here; store it in the OS
    /// keychain (the daemon) or the 0600 config file (plumbing CLI).
    pub fn login(&mut self, email: &str, password: &str, device_label: &str) -> Result<Value> {
        let data = Self::envelope(self.request("POST", "auth/login", false)?.send_json(json!({
            "email": email,
            "password": password,
            "device_label": device_label,
        })))?;
        let public_key = data.get("public_key").and_then(Value::as_str);
        let secret_key = data.get("secret_key").and_then(Value::as_str);
        match (public_key, secret_key) {
            (Some(p), Some(s)) => {
                self.creds = Some(Credentials {
                    public_key: p.to_string(),
                    secret_key: s.to_string(),
                });
                Ok(data)
            }
            _ => Err(ProtoError::Contract("login data missing key pair".into())),
        }
    }

    /// `GET /auth/session` — who am I / key expiry probe.
    pub fn session(&self) -> Result<Value> {
        Self::envelope(self.request("GET", "auth/session", true)?.call())
    }

    /// `POST /auth/logout` — revoke this device's session key.
    pub fn logout(&mut self) -> Result<Value> {
        let out = Self::envelope(
            self.request("POST", "auth/logout", true)?
                .send_json(json!({})),
        );
        if out.is_ok() {
            self.creds = None;
        }
        out
    }

    // ---- actions ------------------------------------------------------------

    /// `POST /action/{name}` with a JSON body; resolves to the envelope `data`.
    pub fn action(&self, name: &str, body: Value) -> Result<Value> {
        Self::envelope(
            self.request("POST", &format!("action/{name}"), true)?
                .send_json(body),
        )
    }

    /// Same, carrying an `Idempotency-Key` (client convention: every mutating
    /// action; fresh key per logical operation, reused only for its retries).
    pub fn action_idempotent(&self, name: &str, body: Value, key: &str) -> Result<Value> {
        Self::envelope(
            self.request("POST", &format!("action/{name}"), true)?
                .set("Idempotency-Key", key)
                .send_json(body),
        )
    }

    /// A fresh idempotency key (32 hex chars).
    pub fn new_idempotency_key() -> String {
        let mut raw = [0u8; 16];
        OsRng.fill_bytes(&mut raw);
        raw.iter().map(|b| format!("{b:02x}")).collect()
    }

    // ---- upload (resumable, sequential chunks) ------------------------------

    /// `PUT /drive_upload/{token}` — one raw chunk. Returns the server's new
    /// `(received_bytes, expected_bytes)`. A 409 (offset mismatch) surfaces as
    /// `ProtoError::Api`; read the resume offset via `chunk_resync_offset()`.
    pub fn upload_chunk(
        &self,
        token: &str,
        start: u64,
        total: u64,
        bytes: &[u8],
    ) -> Result<(u64, u64)> {
        let end = start + bytes.len() as u64 - 1;
        let data = Self::envelope(
            self.request("PUT", &format!("drive_upload/{token}"), true)?
                .set("Content-Range", &format!("bytes {start}-{end}/{total}"))
                .set("Content-Type", "application/octet-stream")
                .send_bytes(bytes),
        )?;
        Self::progress_pair(&data)
    }

    /// `GET /drive_upload/{token}` — `(received_bytes, expected_bytes)`.
    pub fn upload_status(&self, token: &str) -> Result<(u64, u64)> {
        let data = Self::envelope(
            self.request("GET", &format!("drive_upload/{token}"), true)?
                .call(),
        )?;
        Self::progress_pair(&data)
    }

    fn progress_pair(data: &Value) -> Result<(u64, u64)> {
        match (
            data.get("received_bytes").and_then(Value::as_u64),
            data.get("expected_bytes").and_then(Value::as_u64),
        ) {
            (Some(r), Some(e)) => Ok((r, e)),
            _ => Err(ProtoError::Contract(
                "upload progress missing byte counts".into(),
            )),
        }
    }

    /// The whole upload protocol: `drive_upload_init` → sequential chunk PUTs
    /// (realigning on 409) → idempotent `drive_upload_complete`. The reader
    /// must yield exactly `params.size_bytes` bytes and supports seeking for
    /// resume. Returns the completed file export (`deduped` short-circuits
    /// without moving bytes).
    pub fn upload_from_reader<R: Read + Seek>(
        &self,
        params: &UploadParams,
        mut reader: R,
    ) -> Result<UploadOutcome> {
        let mut init_body = json!({
            "name": params.name,
            "size_bytes": params.size_bytes,
            "sha256": params.sha256,
        });
        if let Some(folder_id) = params.folder_id {
            init_body["folder_id"] = json!(folder_id);
        }
        if let Some(file_id) = params.file_id {
            init_body["file_id"] = json!(file_id);
        }
        if let Some(mime) = &params.mime_type {
            init_body["mime_type"] = json!(mime);
        }
        let init = self.action("drive_upload_init", init_body)?;

        if init.get("deduped").and_then(Value::as_bool) == Some(true) {
            let file = init
                .get("file")
                .cloned()
                .ok_or_else(|| ProtoError::Contract("dedup response missing file".into()))?;
            return Ok(UploadOutcome {
                deduped: true,
                file,
            });
        }

        let token = init
            .get("upload_token")
            .and_then(Value::as_str)
            .ok_or_else(|| ProtoError::Contract("init response missing upload_token".into()))?
            .to_string();
        let chunk_bytes = init
            .get("chunk_bytes")
            .and_then(Value::as_u64)
            .filter(|&n| n > 0)
            .ok_or_else(|| ProtoError::Contract("init response missing chunk_bytes".into()))?
            as usize;

        let total = params.size_bytes;
        let mut offset: u64 = 0;
        let mut resyncs_at_offset = 0u32;
        let mut buf = vec![0u8; chunk_bytes];
        while offset < total {
            let want = std::cmp::min(chunk_bytes as u64, total - offset) as usize;
            reader.seek(SeekFrom::Start(offset))?;
            reader.read_exact(&mut buf[..want])?;
            match self.upload_chunk(&token, offset, total, &buf[..want]) {
                Ok((received, _expected)) => {
                    offset = received;
                    resyncs_at_offset = 0;
                }
                Err(e) => match e.chunk_resync_offset() {
                    // realign to the server's truth; a server that keeps
                    // reporting the same offset is not making progress
                    Some(server_offset) => {
                        if server_offset == offset {
                            resyncs_at_offset += 1;
                            if resyncs_at_offset > 3 {
                                return Err(ProtoError::Contract(
                                    "chunk upload loops at one offset without progress".into(),
                                ));
                            }
                        } else {
                            resyncs_at_offset = 0;
                        }
                        offset = server_offset;
                    }
                    None => return Err(e),
                },
            }
        }

        let complete = self.action_idempotent(
            "drive_upload_complete",
            json!({ "upload_token": token }),
            &Self::new_idempotency_key(),
        )?;
        let file = complete
            .get("file")
            .cloned()
            .ok_or_else(|| ProtoError::Contract("complete response missing file".into()))?;
        Ok(UploadOutcome {
            deduped: false,
            file,
        })
    }

    // ---- download -----------------------------------------------------------

    /// Stream a signed `download_url` (from a file export) into `out`.
    /// Returns bytes written. Whole-file; ranged resume rides the Phase 0
    /// server work (`docs/file_signed_urls.md` range contract).
    pub fn download_to<W: Write>(&self, download_url: &str, out: &mut W) -> Result<u64> {
        let url = if download_url.starts_with("http://") || download_url.starts_with("https://") {
            download_url.to_string()
        } else {
            format!("{}{}", self.base_url, download_url)
        };
        let resp = match self.agent.request("GET", &url).call() {
            Ok(r) => r,
            Err(ureq::Error::Status(status, _)) => {
                return Err(ProtoError::Api {
                    status,
                    errortype: "DownloadError".into(),
                    message: format!("signed URL answered {status}"),
                    data: Value::Null,
                })
            }
            Err(e) => return Err(ProtoError::Transport(e.to_string())),
        };
        let mut reader = resp.into_reader();
        let written = std::io::copy(&mut reader, out)?;
        Ok(written)
    }
}

#[derive(Debug, Clone)]
pub struct UploadParams {
    pub name: String,
    /// Destination folder (None = drive root).
    pub folder_id: Option<i64>,
    /// Set to upload a new VERSION of an existing file.
    pub file_id: Option<i64>,
    pub size_bytes: u64,
    /// Lowercase hex sha256 of the exact bytes being uploaded (enables the
    /// possessed-hash dedup short-circuit).
    pub sha256: String,
    pub mime_type: Option<String>,
}

#[derive(Debug)]
pub struct UploadOutcome {
    pub deduped: bool,
    /// The file export (`DriveHelper::file_export` shape).
    pub file: Value,
}

/// Hash a reader's full contents to lowercase-hex sha256, returning
/// `(sha256, size_bytes)`.
pub fn sha256_reader<R: Read>(mut reader: R) -> std::io::Result<(String, u64)> {
    let mut hasher = Sha256::new();
    let mut buf = [0u8; 65536];
    let mut total: u64 = 0;
    loop {
        let n = reader.read(&mut buf)?;
        if n == 0 {
            break;
        }
        hasher.update(&buf[..n]);
        total += n as u64;
    }
    let digest = hasher.finalize();
    Ok((digest.iter().map(|b| format!("{b:02x}")).collect(), total))
}
