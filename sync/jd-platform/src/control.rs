//! How the tray, the CLI, and the settings page reach the running daemon.
//!
//! One HTTP server bound to loopback on a port the operating system picks, plus
//! a token file only the user's account can read. Every request must carry the
//! token; every request without one is refused before it is parsed.
//!
//! **Why loopback HTTP rather than a Unix socket.** The tray is one client, the
//! CLI is a second, and the settings page — a local page opened in the default
//! browser — is a third that can only speak HTTP. A socket would need a bridge
//! for the third, and a named-pipe implementation for Windows, to end up in the
//! same place.
//!
//! **Why a token.** Loopback is not a permission boundary: any process running
//! as any user on the machine can connect to `127.0.0.1`. The token file carries
//! the permission (0600, in the state directory), so authority follows the
//! user's account rather than the machine.
//!
//! **Why port zero.** A fixed port is a fight with whatever else wanted it, and
//! a second instance silently failing to bind is a daemon nothing can talk to.
//! The kernel picks, and the port is written down beside the token.

use std::io::{BufRead, BufReader, Read, Write};
use std::net::{Ipv4Addr, SocketAddr, TcpListener, TcpStream};
use std::path::{Path, PathBuf};
use std::time::Duration;

use serde::{Deserialize, Serialize};
use serde_json::Value;

/// The largest request body the daemon will read.
///
/// Everything it accepts is a short JSON object. Reading an unbounded body from
/// a socket any local process can open is a way to be shut down by a stranger.
const MAX_REQUEST_BODY: usize = 64 * 1024;

/// The largest answer a client will read back.
///
/// **Deliberately not the same number as the request cap**, and this is the one
/// comment in the file worth reading. Those two limits protect against opposite
/// things: the request cap defends the daemon from a local process that will not
/// stop typing, while this one only stops a client waiting forever on an answer
/// that is never going to end. Sharing one constant between them meant a status
/// answer that grew past 64 KiB was silently cut in half by the *client*, failed
/// to parse, and came back as `None` — which every caller reports as **"the sync
/// daemon did not answer"**.
///
/// That is the worst possible shape for this bug. The answer grows with the
/// number of things needing attention, so the client went dark exactly when the
/// user had most to look at, while sync carried on perfectly well behind it —
/// and the user could not even dismiss the issues, because the id list comes
/// from the call that had stopped working. Found on the soak rig at 306 open
/// issues, where the status answer was 70 KB.
///
/// The daemon also caps what it puts in an answer (`snapshot_json`), so this is
/// a backstop rather than the first line of defence. It is generous because a
/// client refusing to read a large answer is not protecting anybody.
const MAX_RESPONSE_BODY: usize = 4 * 1024 * 1024;

/// Collapsing the two caps back into one number is precisely how the bug above
/// happened, so it will not compile. A client has to be able to read an answer
/// larger than the request it sent.
const _: () = assert!(MAX_RESPONSE_BODY > MAX_REQUEST_BODY);

/// How long one connection may take to say what it wants and hear the answer.
///
/// Everything here is a short exchange with a process on the same machine, so
/// this is generous. It exists because connections are served one at a time: a
/// caller that opens a socket and sends nothing would otherwise hold the control
/// thread forever, and the user's tray and CLI would find the daemon
/// unreachable while sync carried on invisibly behind it.
const SERVE_TIMEOUT: Duration = Duration::from_secs(15);

/// How long to spend draining a body that is not going to be used.
///
/// Short, because of what the drain is actually for. The reset it prevents is
/// caused by data **already sitting in the receive buffer** when the socket
/// closes — bytes the caller has not sent yet were never the problem. So the
/// drain takes what is there and stops. Waiting the full serve timeout for a
/// body nobody is sending would hold the control thread, and hold the caller
/// too, since it is waiting on the close to know the answer is complete.
const DRAIN_TIMEOUT: Duration = Duration::from_millis(200);

/// Where a client finds the running daemon.
#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
pub struct Endpoint {
    pub port: u16,
    pub token: String,
    /// The daemon's process id, so a client can tell "not running" from
    /// "running and not answering" — which need different advice.
    pub pid: u32,
}

impl Endpoint {
    pub fn url(&self, path: &str) -> String {
        format!(
            "http://127.0.0.1:{}/{}",
            self.port,
            path.trim_start_matches('/')
        )
    }

    pub fn load(path: &Path) -> Option<Endpoint> {
        serde_json::from_str(&std::fs::read_to_string(path).ok()?).ok()
    }

    /// Write the endpoint file, readable only by this account.
    ///
    /// The mode is set at creation rather than after, so the token is never on
    /// disk world-readable even briefly.
    pub fn save(&self, path: &Path) -> std::io::Result<()> {
        if let Some(dir) = path.parent() {
            std::fs::create_dir_all(dir)?;
        }
        let mut options = std::fs::OpenOptions::new();
        options.write(true).create(true).truncate(true);
        #[cfg(unix)]
        {
            use std::os::unix::fs::OpenOptionsExt;
            options.mode(0o600);
        }
        let mut file = options.open(path)?;
        file.write_all(
            serde_json::to_string(self)
                .expect("endpoint serializes")
                .as_bytes(),
        )?;
        file.sync_all()
    }
}

/// One request a client made.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Request {
    pub method: String,
    pub path: String,
    pub body: Value,
}

/// The listening side.
pub struct ControlServer {
    listener: TcpListener,
    token: String,
    endpoint_path: PathBuf,
}

impl ControlServer {
    /// Bind to loopback on a kernel-chosen port and publish the endpoint file.
    pub fn bind(endpoint_path: &Path, token: String) -> std::io::Result<ControlServer> {
        // Loopback explicitly, never `0.0.0.0`: binding the control surface to
        // every interface would put the daemon's controls on the network.
        let listener = TcpListener::bind(SocketAddr::from((Ipv4Addr::LOCALHOST, 0)))?;
        let port = listener.local_addr()?.port();
        let endpoint = Endpoint {
            port,
            token: token.clone(),
            pid: std::process::id(),
        };
        endpoint.save(endpoint_path)?;
        Ok(ControlServer {
            listener,
            token,
            endpoint_path: endpoint_path.to_path_buf(),
        })
    }

    pub fn port(&self) -> u16 {
        self.listener.local_addr().map(|a| a.port()).unwrap_or(0)
    }

    /// Accept one connection and hand the request to `handle`.
    ///
    /// A malformed or unauthorized request is answered and never reaches the
    /// handler, so the handler only ever sees requests from the user's own
    /// tools.
    pub fn serve_one(
        &self,
        handle: &mut dyn FnMut(&Request) -> (u16, Value),
    ) -> std::io::Result<()> {
        let (stream, _) = self.listener.accept()?;
        self.serve_stream(stream, handle)
    }

    fn serve_stream(
        &self,
        mut stream: TcpStream,
        handle: &mut dyn FnMut(&Request) -> (u16, Value),
    ) -> std::io::Result<()> {
        // Without these, one connection can hold the control thread forever:
        // open a socket and send nothing, or declare a large body and send two
        // bytes of it. Connections are served one at a time, so that is every
        // local process's ability to make the daemon unreachable to the user's
        // own tray and CLI — while sync itself carries on, invisibly.
        let _ = stream.set_read_timeout(Some(SERVE_TIMEOUT));
        let _ = stream.set_write_timeout(Some(SERVE_TIMEOUT));

        let mut reader = BufReader::new(stream.try_clone()?);
        let (status, body, undrained) = match read_request(&mut reader, &self.token) {
            Ok(request) => {
                let (status, body) = handle(&request);
                (status, body, 0)
            }
            Err(refused) => (refused.status, refused.body, refused.undrained),
        };

        // Answer first. The refusal was decided before the body was looked at,
        // so making the caller wait for bytes we are going to throw away would
        // be delaying an answer we already have.
        write_response(&mut stream, status, &body)?;

        // Then drain, purely so the close is clean. Closing a socket with unread
        // data in its receive buffer sends RST instead of FIN, and on macOS that
        // RST discards the answer already sitting in the caller's buffer — the
        // refusal arrives as no answer at all, which every caller reads as "the
        // daemon is not running".
        let _ = stream.set_read_timeout(Some(DRAIN_TIMEOUT));
        discard_body(&mut reader, undrained);
        let _ = stream.shutdown(std::net::Shutdown::Both);
        Ok(())
    }

    /// Stop listening and take the endpoint file with it.
    ///
    /// A stale endpoint file is worse than none: a client reads it, connects to
    /// whatever now holds that port, and reports something that is not the
    /// daemon.
    pub fn shutdown(&self) {
        let _ = std::fs::remove_file(&self.endpoint_path);
    }
}

/// A request that will not be served, and how much of its body is still on the
/// wire.
struct Refused {
    status: u16,
    body: Value,
    undrained: usize,
}

/// Read and authorize one HTTP request.
fn read_request(reader: &mut BufReader<TcpStream>, token: &str) -> Result<Request, Refused> {
    let mut line = String::new();
    if reader.read_line(&mut line).is_err() || line.trim().is_empty() {
        return Err(refusal(400, "empty request", 0));
    }
    let mut parts = line.split_whitespace();
    let method = parts.next().unwrap_or("").to_string();
    let path = parts.next().unwrap_or("/").to_string();

    let mut presented: Option<String> = None;
    let mut content_length = 0usize;
    loop {
        let mut header = String::new();
        if reader.read_line(&mut header).is_err() {
            return Err(refusal(400, "truncated headers", 0));
        }
        let header = header.trim_end();
        if header.is_empty() {
            break;
        }
        if let Some((name, value)) = header.split_once(':') {
            let value = value.trim();
            match name.to_ascii_lowercase().as_str() {
                "x-joinery-token" => presented = Some(value.to_string()),
                "content-length" => content_length = value.parse().unwrap_or(0),
                _ => {}
            }
        }
    }

    // Checked before the body is *kept*, so an unauthorized caller cannot make
    // the daemon allocate a buffer of whatever size they claimed. What is left
    // on the wire rides along on the refusal, to be drained after the answer has
    // gone out rather than before.
    if !presented
        .map(|p| constant_time_eq(&p, token))
        .unwrap_or(false)
    {
        return Err(refusal(
            401,
            "this request did not carry the daemon token",
            content_length,
        ));
    }
    if content_length > MAX_REQUEST_BODY {
        return Err(refusal(413, "request body too large", content_length));
    }

    let mut raw = vec![0u8; content_length];
    if content_length > 0 && reader.read_exact(&mut raw).is_err() {
        return Err(refusal(400, "truncated body", 0));
    }
    let body = if raw.is_empty() {
        Value::Null
    } else {
        match serde_json::from_slice(&raw) {
            Ok(v) => v,
            Err(_) => return Err(refusal(400, "body is not JSON", 0)),
        }
    };

    Ok(Request { method, path, body })
}

/// Read and throw away a request body we are not going to use.
///
/// Necessary, not merely polite, and the reason is a genuine difference between
/// operating systems. Closing a socket that still has unread data in its receive
/// buffer makes the kernel send RST instead of FIN — and on macOS that RST
/// **discards the response already sitting in the client's buffer**. So a
/// refused request arrived at the caller as no answer at all, which every caller
/// reads as "the daemon is not running": exactly the distinction this channel
/// exists to keep. Linux is more forgiving and never showed it.
///
/// Bounded twice over: by [`MAX_REQUEST_BODY`], so a caller claiming four gigabytes gets
/// no more of the daemon's attention than one claiming four bytes, and by the
/// socket's [`DRAIN_TIMEOUT`], so one claiming four gigabytes and sending two
/// bytes gets none of its patience either. A read that times out ends the drain,
/// because anything still to come was not in the buffer and cannot cause the
/// reset this exists to prevent.
fn discard_body(reader: &mut BufReader<TcpStream>, declared: usize) {
    let mut scratch = [0u8; 4096];
    let mut left = declared.min(MAX_REQUEST_BODY);
    while left > 0 {
        let want = left.min(scratch.len());
        match reader.read(&mut scratch[..want]) {
            Ok(0) | Err(_) => return,
            Ok(n) => left -= n,
        }
    }
}

fn write_response(stream: &mut TcpStream, status: u16, body: &Value) -> std::io::Result<()> {
    let text = serde_json::to_string(body).unwrap_or_else(|_| "{}".into());
    let reason = match status {
        200 => "OK",
        400 => "Bad Request",
        401 => "Unauthorized",
        404 => "Not Found",
        413 => "Payload Too Large",
        _ => "Error",
    };
    write!(
        stream,
        "HTTP/1.1 {status} {reason}\r\n\
         Content-Type: application/json\r\n\
         Content-Length: {}\r\n\
         Cache-Control: no-store\r\n\
         Connection: close\r\n\
         \r\n{text}",
        text.len()
    )?;
    stream.flush()
}

fn refusal(status: u16, message: &str, undrained: usize) -> Refused {
    Refused {
        status,
        body: serde_json::json!({ "error": message }),
        undrained,
    }
}

/// Compare two tokens without leaking where they first differ.
///
/// The token is guessable only by brute force, and a comparison that returns
/// early turns brute force over the whole token into brute force one byte at a
/// time. The length is deliberately compared first and separately — lengths are
/// not secret, and a token of the wrong length is not a near miss.
fn constant_time_eq(a: &str, b: &str) -> bool {
    let (a, b) = (a.as_bytes(), b.as_bytes());
    if a.len() != b.len() {
        return false;
    }
    let mut diff = 0u8;
    for (x, y) in a.iter().zip(b) {
        diff |= x ^ y;
    }
    diff == 0
}

/// A token with enough entropy that guessing it is not a strategy.
///
/// Straight from the operating system's generator. Not a hash of the time and
/// the process id, which is the shape this reaches for when nobody is looking
/// and which someone on the same machine can reproduce in a loop.
pub fn new_token() -> String {
    let mut raw = [0u8; 32];
    rand_core::RngCore::fill_bytes(&mut rand_core::OsRng, &mut raw);
    raw.iter().map(|b| format!("{b:02x}")).collect()
}

// ---------------------------------------------------------------------------
// The asking side
// ---------------------------------------------------------------------------

/// How long to wait on a daemon that has accepted the connection but not
/// answered. Without it, a wedged daemon wedges the tray and every CLI call.
const ASK_TIMEOUT: Duration = Duration::from_secs(10);

/// Ask the running daemon something.
///
/// `None` means there is no daemon to ask, which is a different situation from a
/// daemon that answered with an error — one wants "start it", the other wants
/// the message.
///
/// Written directly on a socket rather than through an HTTP client, because
/// every request this makes is plain HTTP to `127.0.0.1` and an HTTP client
/// brings a TLS stack that is never used. That is not only weight: the TLS
/// crates carry C code, and C code is what stops this crate — and therefore the
/// tray — from being compiled for macOS and Windows from a Linux box.
pub fn ask(endpoint: &Endpoint, method: &str, path: &str, body: Value) -> Option<Value> {
    let addr = SocketAddr::from((Ipv4Addr::LOCALHOST, endpoint.port));
    let mut stream = TcpStream::connect_timeout(&addr, ASK_TIMEOUT).ok()?;
    stream.set_read_timeout(Some(ASK_TIMEOUT)).ok()?;
    stream.set_write_timeout(Some(ASK_TIMEOUT)).ok()?;

    let payload = if body.is_null() {
        String::new()
    } else {
        serde_json::to_string(&body).ok()?
    };
    write!(
        stream,
        "{method} {path} HTTP/1.1\r\n\
         Host: 127.0.0.1\r\n\
         x-joinery-token: {}\r\n\
         Content-Type: application/json\r\n\
         Content-Length: {}\r\n\
         Connection: close\r\n\
         \r\n{payload}",
        endpoint.token,
        payload.len()
    )
    .ok()?;
    stream.flush().ok()?;

    // The daemon closes the connection after answering, so reading to the end is
    // both the simplest framing and the correct one.
    //
    // Read one byte past the cap on purpose: getting exactly the cap back is
    // ambiguous — it could be a whole answer that happens to be that long, or the
    // front of one that was cut off. The extra byte tells them apart, so a
    // truncated answer can be refused as what it is instead of failing to parse
    // and arriving at the caller as silence.
    let mut raw = String::new();
    stream
        .take(MAX_RESPONSE_BODY as u64 + 1)
        .read_to_string(&mut raw)
        .ok()?;
    if raw.len() > MAX_RESPONSE_BODY {
        return None;
    }
    let (_, answer) = raw.split_once("\r\n\r\n")?;
    serde_json::from_str(answer).ok()
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn temp(tag: &str) -> PathBuf {
        let p = std::env::temp_dir().join(format!(
            "jd-control-{}-{}-{:?}",
            tag,
            std::process::id(),
            std::thread::current().id()
        ));
        let _ = std::fs::remove_dir_all(&p);
        std::fs::create_dir_all(&p).unwrap();
        p
    }

    /// Run a server on its own thread for exactly `n` requests.
    fn spawn(server: ControlServer, n: usize) -> std::thread::JoinHandle<()> {
        std::thread::spawn(move || {
            for _ in 0..n {
                let _ = server
                    .serve_one(&mut |req| (200, json!({ "path": req.path, "echo": req.body })));
            }
        })
    }

    #[test]
    fn a_request_carrying_the_token_is_answered() {
        let dir = temp("ok");
        let path = dir.join("control.json");
        let server = ControlServer::bind(&path, new_token()).unwrap();
        let endpoint = Endpoint::load(&path).unwrap();
        let handle = spawn(server, 1);

        let answer = ask(&endpoint, "POST", "/status", json!({"hello": true})).unwrap();
        assert_eq!(answer["path"], "/status");
        assert_eq!(answer["echo"]["hello"], true);

        handle.join().unwrap();
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn an_answer_larger_than_the_request_cap_still_reaches_the_caller() {
        // The regression this exists for, found on the soak rig. The request cap
        // and the response cap were one constant, so a status answer that grew
        // past 64 KiB was cut in half by the CLIENT, failed to parse, and came
        // back as None — which every caller reports as "the sync daemon did not
        // answer".
        //
        // The shape of that failure is what makes it serious: the answer grows
        // with the number of things needing attention, so the client went dark
        // exactly when the user had most to look at, while sync carried on
        // perfectly well behind it. Sixty-five kilobytes here, just over the old
        // limit and nowhere near the real one.
        let dir = temp("biganswer");
        let path = dir.join("control.json");
        let server = ControlServer::bind(&path, new_token()).unwrap();
        let endpoint = Endpoint::load(&path).unwrap();
        let big = "x".repeat(65 * 1024);
        let expected = big.clone();
        let handle = std::thread::spawn(move || {
            let _ = server.serve_one(&mut |_| (200, json!({ "issues": big })));
        });

        let answer = ask(&endpoint, "GET", "/status", Value::Null)
            .expect("a large answer must arrive, not read as a dead daemon");
        assert_eq!(answer["issues"], expected);

        handle.join().unwrap();
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_request_without_the_token_is_refused() {
        // Loopback is not a permission boundary: any process running as any
        // user on this machine can open this port.
        let dir = temp("noauth");
        let path = dir.join("control.json");
        let server = ControlServer::bind(&path, new_token()).unwrap();
        let mut endpoint = Endpoint::load(&path).unwrap();
        let handle = spawn(server, 1);

        endpoint.token = "not-the-token".into();
        let answer = ask(&endpoint, "POST", "/status", json!({})).unwrap();
        assert!(answer["error"].as_str().unwrap().contains("token"));

        handle.join().unwrap();
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_refusal_reaches_the_caller_even_when_the_request_carried_a_body() {
        // Found by the macOS gate, invisible on Linux. Refusing without reading
        // the body leaves unread bytes in the receive buffer, and closing a
        // socket in that state sends RST rather than FIN — which on macOS throws
        // away the response the client had already received. The refusal then
        // arrives as no answer at all, and every caller reads that as "the
        // daemon is not running".
        let dir = temp("refuse-with-body");
        let path = dir.join("control.json");
        let server = ControlServer::bind(&path, new_token()).unwrap();
        let mut endpoint = Endpoint::load(&path).unwrap();
        let handle = spawn(server, 1);

        endpoint.token = "not-the-token".into();
        let fat = json!({ "payload": "x".repeat(32 * 1024) });
        let answer = ask(&endpoint, "POST", "/dismiss", fat);

        assert!(
            answer.is_some(),
            "a refused request must come back as a refusal, not as silence"
        );
        assert!(answer.unwrap()["error"].as_str().unwrap().contains("token"));

        handle.join().unwrap();
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn an_over_large_body_is_refused_and_the_refusal_still_arrives() {
        let dir = temp("toobig");
        let path = dir.join("control.json");
        let server = ControlServer::bind(&path, new_token()).unwrap();
        let endpoint = Endpoint::load(&path).unwrap();
        let handle = spawn(server, 1);

        // Larger than MAX_REQUEST_BODY, so the server refuses on size rather
        // than auth.
        let huge = json!({ "payload": "x".repeat(MAX_REQUEST_BODY + 1024) });
        let answer = ask(&endpoint, "POST", "/dismiss", huge).unwrap();
        assert!(answer["error"].as_str().unwrap().contains("too large"));

        handle.join().unwrap();
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn draining_a_body_never_reads_more_than_the_cap_however_much_is_claimed() {
        // The whole point of not trusting the declared length still holds while
        // draining: a caller claiming four gigabytes must not get four gigabytes
        // of the daemon's attention.
        let dir = temp("draincap");
        let path = dir.join("control.json");
        let server = ControlServer::bind(&path, new_token()).unwrap();
        let endpoint = Endpoint::load(&path).unwrap();

        let handle = std::thread::spawn(move || {
            let _ = server.serve_one(&mut |_| (200, json!({ "ok": true })));
        });

        // Claim four gigabytes and send two bytes of it. The refusal was decided
        // from the headers alone, so it must come back immediately — not after
        // the daemon has waited out its own timeout for bytes that will never
        // arrive, and certainly not never.
        let mut stream =
            TcpStream::connect(SocketAddr::from((Ipv4Addr::LOCALHOST, endpoint.port))).unwrap();
        write!(
            stream,
            "POST /status HTTP/1.1\r\nx-joinery-token: wrong\r\nContent-Length: 4294967296\r\n\r\nab"
        )
        .unwrap();
        stream.flush().unwrap();
        stream.set_read_timeout(Some(SERVE_TIMEOUT * 2)).unwrap();

        let asked_at = std::time::Instant::now();
        let mut raw = String::new();
        let _ = stream.take(8192).read_to_string(&mut raw);
        let waited = asked_at.elapsed();

        assert!(
            raw.contains("401"),
            "the daemon must refuse and say so rather than wait: {raw:?}"
        );
        assert!(
            waited < SERVE_TIMEOUT,
            "the refusal took {waited:?} — it was decided from the headers and \
             should not wait on a body nobody is sending"
        );

        handle.join().unwrap();
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn the_endpoint_file_is_readable_only_by_this_account() {
        #[cfg(unix)]
        {
            use std::os::unix::fs::PermissionsExt;
            let dir = temp("mode");
            let path = dir.join("control.json");
            let server = ControlServer::bind(&path, new_token()).unwrap();
            let mode = std::fs::metadata(&path).unwrap().permissions().mode() & 0o777;
            assert_eq!(mode, 0o600, "the token is what carries the permission");
            server.shutdown();
            let _ = std::fs::remove_dir_all(&dir);
        }
    }

    #[test]
    fn shutting_down_takes_the_endpoint_file_with_it() {
        // A stale file is worse than none: a client reads it, connects to
        // whatever now holds that port, and reports something that is not the
        // daemon.
        let dir = temp("shutdown");
        let path = dir.join("control.json");
        let server = ControlServer::bind(&path, new_token()).unwrap();
        assert!(path.exists());
        server.shutdown();
        assert!(!path.exists());
        assert!(Endpoint::load(&path).is_none());
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn the_control_surface_is_never_bound_to_a_network_interface() {
        let dir = temp("loopback");
        let path = dir.join("control.json");
        let server = ControlServer::bind(&path, new_token()).unwrap();
        let addr = server.listener.local_addr().unwrap();
        assert!(addr.ip().is_loopback(), "bound to {addr}");
        assert_ne!(server.port(), 0, "the kernel picked a real port");
        server.shutdown();
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn two_daemons_do_not_fight_over_a_port() {
        // A fixed port would mean the second instance silently fails to bind and
        // becomes a daemon nothing can talk to.
        let dir = temp("two");
        let a = ControlServer::bind(&dir.join("a.json"), new_token()).unwrap();
        let b = ControlServer::bind(&dir.join("b.json"), new_token()).unwrap();
        assert_ne!(a.port(), b.port());
        a.shutdown();
        b.shutdown();
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn token_comparison_does_not_stop_early() {
        assert!(constant_time_eq("abc", "abc"));
        assert!(!constant_time_eq("abc", "abd"));
        assert!(!constant_time_eq("abc", "abcd"));
        assert!(!constant_time_eq("", "a"));
    }

    #[test]
    fn a_token_is_long_enough_that_guessing_is_not_a_strategy() {
        let token = new_token();
        assert_eq!(token.len(), 64);
        assert_ne!(token, new_token());
    }

    #[test]
    fn asking_a_daemon_that_is_not_running_says_so_rather_than_hanging() {
        // "Not running" and "running and unhappy" need different advice, so they
        // cannot both be an error string.
        let endpoint = Endpoint {
            // Port 1 needs privileges to bind, so nothing of ours is ever on it.
            port: 1,
            token: "t".into(),
            pid: 0,
        };
        assert!(ask(&endpoint, "GET", "/status", Value::Null).is_none());
    }
}
