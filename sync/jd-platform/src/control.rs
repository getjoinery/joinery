//! How the tray, the CLI, and the settings page reach the running daemon.
//!
//! One HTTP server bound to loopback on a port the operating system picks, plus
//! a token file only the user's account can read. Every request must carry a
//! credential; every request without one is refused before it is parsed.
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
//!
//! **How the settings page gets in without the token.** A browser cannot add a
//! header to a page it is told to open, and the token must never travel in a
//! URL: addresses end up in history, in sync'd browser profiles and in
//! extensions' hands, and this one is good for the life of the daemon. So the
//! page is let in by a chain of three things, each worth less than the last:
//!
//! 1. Whoever holds the token (the tray, `joinery-drive settings`) asks for a
//!    **ticket**: single-use, two minutes long, and good for nothing but step 2.
//! 2. The page is opened with the ticket in the URL's *fragment*, which a
//!    browser never sends to any server and never puts in a `Referer`. The
//!    page's script trades it for a **session** and takes it out of the
//!    address bar.
//! 3. Every request the page makes carries the session in a header. Not a
//!    cookie: a browser attaches cookies to requests other sites make, which is
//!    what lets one site drive another, and it attaches custom headers to
//!    nothing it was not told to.
//!
//! Around that: the page's own files are the only thing served without a
//! credential, and they hold no data; every browser-reachable request must name
//! the daemon by its loopback address (`Host`), which is what defeats a site
//! that points its own hostname at 127.0.0.1; anything carrying a session must
//! come from the page's own origin; nothing ever answers with a CORS header, so
//! no other site can read an answer; and the page refuses to be framed.

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
///
/// This is the whole bound on the drain. Time is what a caller can cost the
/// daemon, and this is all of it: a caller claiming four gigabytes gets the
/// same fifth of a second as one claiming four bytes, and anything it has not
/// delivered by then was not in the receive buffer and cannot cause the reset.
/// A byte cap in its place is the wrong bound — a body just over the cap would
/// leave its tail unread and turn the close into exactly the reset the drain
/// exists to prevent.
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

/// Who a request came from, as the channel established it.
///
/// Decided here, before the handler is reached, so the handler never has to
/// work out for itself whether to believe a caller — it only has to decide what
/// each kind of caller is allowed to ask for.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Caller {
    /// The user's own tools — the tray and the CLI — holding the token file.
    Owner,
    /// The settings page in the user's browser, holding a session it was given
    /// in exchange for a ticket.
    Page,
}

/// One request a client made.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Request {
    pub method: String,
    pub path: String,
    pub body: Value,
    pub caller: Caller,
}

/// One file the settings page is made of.
///
/// These are the only thing the channel serves without a credential, so what
/// goes in one is fixed at compile time and holds no data: the page asks for
/// everything it shows, over requests that carry its session.
#[derive(Debug)]
pub struct Page {
    pub path: &'static str,
    pub content_type: &'static str,
    pub body: &'static str,
}

/// Where the settings page lives.
pub const PAGE_PATH: &str = "/settings";
/// Where a token holder asks for a ticket to open the page with.
pub const TICKET_PATH: &str = "/settings/ticket";
/// Where the page trades its ticket for a session.
pub const SESSION_PATH: &str = "/settings/session";

/// How long a ticket lasts. Long enough for a browser that was not running to
/// start; short enough that one found in a history file has long gone off.
const TICKET_TTL: Duration = Duration::from_secs(120);

/// How long a page session lasts without being used. The open page asks for
/// status every couple of seconds, and even a tab the browser has throttled in
/// the background asks once a minute, so this only ends sessions nobody is
/// looking at — a laptop shut overnight comes back to "open Settings again".
const SESSION_IDLE: Duration = Duration::from_secs(60 * 60);

/// How many tickets and sessions are held at once. A person has a page or two
/// open; a local process asking for tickets in a loop must not grow the
/// daemon's memory without bound, so the oldest goes.
const MAX_GRANTS: usize = 8;

/// How many connections are served at the same time.
///
/// More than one because of browsers. Chrome in particular opens a spare
/// connection it may never use, and with one connection served at a time that
/// spare holds the control thread for the whole serve timeout — the page hangs
/// half-drawn, and the tray and the CLI find the daemon unreachable for the
/// same fifteen seconds. A few at once, and a quiet socket only ties up its own
/// thread. Bounded, because a thread per connection without a ceiling is a
/// thread per connection any local process cares to open.
const MAX_CONNECTIONS: usize = 4;

/// How long a stopping daemon waits for the answers it is still writing
/// (`ControlServer::shutdown`). An answer is a few hundred bytes to a
/// process on this machine; this only has to outlast a slow scheduler.
const SHUTDOWN_GRACE: Duration = Duration::from_secs(2);

/// The policy every answer carries.
///
/// Written for the page and harmless on JSON: scripts and styles only from the
/// page's own files (so an issue's file name, which another device chose,
/// cannot become a script even if it slipped past the page's escaping), fetches
/// only back to this daemon, nothing loaded from anywhere else, and no framing —
/// a page that can be framed can be clicked through by a site laid over it.
const CONTENT_SECURITY_POLICY: &str = "default-src 'none'; script-src 'self'; \
     style-src 'self'; img-src 'self' data:; connect-src 'self'; base-uri 'none'; \
     form-action 'none'; frame-ancestors 'none'";

/// Tickets and sessions handed out so far.
///
/// Held in memory only. A daemon restart ends every session, which is right:
/// the restarted daemon is on a different port, so the page's origin — and the
/// browser storage its session sat in — is a different one anyway.
#[derive(Default)]
struct Grants {
    /// Ticket and when it was minted.
    tickets: Vec<(String, std::time::Instant)>,
    /// Session and when it was last used.
    sessions: Vec<(String, std::time::Instant)>,
}

impl Grants {
    fn mint_ticket(&mut self, now: std::time::Instant) -> String {
        self.tickets
            .retain(|(_, at)| now.duration_since(*at) < TICKET_TTL);
        if self.tickets.len() >= MAX_GRANTS {
            self.tickets.remove(0);
        }
        let ticket = new_token();
        self.tickets.push((ticket.clone(), now));
        ticket
    }

    /// Spend a ticket. Spent whether or not it was still good, so it cannot be
    /// tried twice; a session comes back only for one that was.
    fn redeem(&mut self, presented: &str, now: std::time::Instant) -> Option<String> {
        let at = self
            .tickets
            .iter()
            .position(|(t, _)| constant_time_eq(t, presented))?;
        let (_, minted) = self.tickets.remove(at);
        if now.duration_since(minted) >= TICKET_TTL {
            return None;
        }
        self.sessions
            .retain(|(_, used)| now.duration_since(*used) < SESSION_IDLE);
        if self.sessions.len() >= MAX_GRANTS {
            // The least recently used, which is the page somebody closed.
            let oldest = self
                .sessions
                .iter()
                .enumerate()
                .min_by_key(|(_, (_, used))| *used)
                .map(|(i, _)| i)
                .unwrap_or(0);
            self.sessions.remove(oldest);
        }
        let session = new_token();
        self.sessions.push((session.clone(), now));
        Some(session)
    }

    /// Is this a live session? Using one keeps it alive.
    fn admit(&mut self, presented: &str, now: std::time::Instant) -> bool {
        self.sessions
            .retain(|(_, used)| now.duration_since(*used) < SESSION_IDLE);
        match self
            .sessions
            .iter_mut()
            .find(|(s, _)| constant_time_eq(s, presented))
        {
            Some((_, used)) => {
                *used = now;
                true
            }
            None => false,
        }
    }
}

/// Does this `Host` header name the daemon by its loopback address?
///
/// The defence against DNS rebinding: a site can point its own hostname at
/// 127.0.0.1, and the browser will then let its pages talk to this port as if
/// they were the site's own. What it cannot do is make the browser send a Host
/// it did not navigate to, so a request naming anything but loopback came from
/// a page that is not ours. The port, when given, must be this one — another
/// local web server's pages are not ours either.
pub fn host_is_loopback(host: &str, port: u16) -> bool {
    let host = host.trim();
    let (name, given) = match host.rsplit_once(':') {
        Some((name, port)) => (name, Some(port)),
        None => (host, None),
    };
    let named = name.eq_ignore_ascii_case("127.0.0.1") || name.eq_ignore_ascii_case("localhost");
    named && given.is_none_or(|p| p == port.to_string())
}

/// Is this `Origin` the settings page's own?
pub fn origin_is_ours(origin: &str, port: u16) -> bool {
    let origin = origin.trim();
    origin == format!("http://127.0.0.1:{port}") || origin == format!("http://localhost:{port}")
}

/// What one request's head said, before anything is believed.
#[derive(Default)]
struct Head {
    method: String,
    path: String,
    token: Option<String>,
    ticket: Option<String>,
    session: Option<String>,
    host: Option<String>,
    hosts: usize,
    origin: Option<String>,
    fetch_site: Option<String>,
    content_length: usize,
}

impl Head {
    /// The path without its query string. A query is never a credential here:
    /// nothing reads one, so a token or a session in a URL is simply absent.
    fn route(&self) -> &str {
        self.path.split('?').next().unwrap_or("/")
    }

    /// Did this come from the page's own origin, as far as the browser says?
    ///
    /// Browsers send `Origin` on every request that can change something and
    /// `Sec-Fetch-Site` on everything; when either is there it must say "us".
    /// Absent is not a refusal, because the tray and the CLI send neither —
    /// but nothing reaches here without a ticket or a session, which no other
    /// site can have, so this is the second lock on the door, not the first.
    fn is_from_our_origin(&self, port: u16) -> bool {
        if let Some(origin) = &self.origin {
            if !origin_is_ours(origin, port) {
                return false;
            }
        }
        match self.fetch_site.as_deref() {
            None | Some("same-origin") | Some("none") => true,
            Some(_) => false,
        }
    }
}

/// What the channel decided to do with a request.
enum Incoming {
    /// A request for the handler.
    Request(Request),
    /// Answered by the channel itself: the page's own files, and the
    /// ticket-and-session exchange that is part of deciding who is asking.
    Reply(Reply, usize),
}

/// An answer on its way out.
enum Reply {
    Json(u16, Value),
    Page(&'static Page),
}

/// The listening side.
pub struct ControlServer {
    listener: TcpListener,
    token: String,
    endpoint_path: PathBuf,
    port: u16,
    pages: &'static [Page],
    grants: std::sync::Mutex<Grants>,
    /// Connections being served now, and the signal that one has finished.
    in_flight: std::sync::Arc<(std::sync::Mutex<usize>, std::sync::Condvar)>,
    /// Set by `shutdown`: a connection accepted after it is closed unanswered.
    closing: std::sync::atomic::AtomicBool,
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
            port,
            pages: &[],
            grants: std::sync::Mutex::new(Grants::default()),
            in_flight: Default::default(),
            closing: std::sync::atomic::AtomicBool::new(false),
        })
    }

    /// Serve the settings page's files. Without this the channel answers the
    /// tray and the CLI only.
    pub fn with_pages(mut self, pages: &'static [Page]) -> ControlServer {
        self.pages = pages;
        self
    }

    pub fn port(&self) -> u16 {
        self.port
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

    /// Serve forever, a few connections at a time (see [`MAX_CONNECTIONS`]).
    ///
    /// The handler is shared between the connections, so it must be safe to
    /// call from several at once — the daemon's is, because all it does is
    /// read a snapshot and post to a queue.
    pub fn serve<F>(self: std::sync::Arc<Self>, handle: F)
    where
        F: Fn(&Request) -> (u16, Value) + Send + Sync + 'static,
    {
        use std::sync::Arc;
        let handle = Arc::new(handle);
        let busy = Arc::clone(&self.in_flight);
        loop {
            {
                let (count, freed) = &*busy;
                let mut n = count.lock().unwrap_or_else(|e| e.into_inner());
                while *n >= MAX_CONNECTIONS {
                    n = freed.wait(n).unwrap_or_else(|e| e.into_inner());
                }
            }
            let stream = match self.listener.accept() {
                Ok((stream, _)) => stream,
                Err(_) => {
                    // Out of file descriptors, most likely. One failed accept
                    // is not a reason to stop answering, but looping on it
                    // flat out would spin a core until it clears.
                    std::thread::sleep(Duration::from_millis(50));
                    continue;
                }
            };
            if self.closing.load(std::sync::atomic::Ordering::SeqCst) {
                // Stopping: the caller sees the connection close, which is
                // what "no daemon here" is, and is true in a moment.
                drop(stream);
                continue;
            }
            *busy.0.lock().unwrap_or_else(|e| e.into_inner()) += 1;
            let (server, handle, done) =
                (Arc::clone(&self), Arc::clone(&handle), Arc::clone(&busy));
            let spawned = std::thread::Builder::new()
                .name("jd-control".into())
                .spawn(move || {
                    let _ = server.serve_stream(stream, &mut |req| handle(req));
                    let (count, freed) = &*done;
                    *count.lock().unwrap_or_else(|e| e.into_inner()) -= 1;
                    // Two kinds of waiter: this loop, at the cap, and a
                    // shutdown waiting for none. One woken alone could be
                    // the wrong one.
                    freed.notify_all();
                });
            if spawned.is_err() {
                // The stream went with the closure and is closed; the caller
                // sees a dropped connection and retries.
                *busy.0.lock().unwrap_or_else(|e| e.into_inner()) -= 1;
            }
        }
    }

    fn serve_stream(
        &self,
        stream: TcpStream,
        handle: &mut dyn FnMut(&Request) -> (u16, Value),
    ) -> std::io::Result<()> {
        // Without these, one connection can hold the control thread forever:
        // open a socket and send nothing, or declare a large body and send two
        // bytes of it. Connections are served a few at a time, so that is every
        // local process's ability to make the daemon unreachable to the user's
        // own tray and CLI — while sync itself carries on, invisibly.
        let _ = stream.set_read_timeout(Some(SERVE_TIMEOUT));
        let _ = stream.set_write_timeout(Some(SERVE_TIMEOUT));

        // One socket, read and written through the same handle. A duplicate for
        // the reader would look harmless, but on Windows a read timeout belongs
        // to the handle it was set on, not to the connection: the drain's
        // shorter timeout set on the original would leave the duplicate waiting
        // out the full serve timeout for bytes nobody is sending.
        let mut reader = BufReader::new(stream);
        let (reply, undrained) = match self.read_request(&mut reader) {
            Ok(Incoming::Request(request)) => {
                let (status, body) = handle(&request);
                (Reply::Json(status, body), 0)
            }
            Ok(Incoming::Reply(reply, undrained)) => (reply, undrained),
            Err(refused) => (Reply::Json(refused.status, refused.body), refused.undrained),
        };

        // Answer first. The refusal was decided before the body was looked at,
        // so making the caller wait for bytes we are going to throw away would
        // be delaying an answer we already have.
        write_response(reader.get_mut(), &reply)?;

        // Then drain, purely so the close is clean. Closing a socket with unread
        // data in its receive buffer sends RST instead of FIN, and on macOS and
        // Windows that RST discards the answer already sitting in the caller's
        // buffer — the refusal arrives as no answer at all, which every caller
        // reads as "the daemon is not running".
        discard_body(&mut reader, undrained);
        let _ = reader.get_ref().shutdown(std::net::Shutdown::Both);
        Ok(())
    }

    /// Stop listening and take the endpoint file with it.
    ///
    /// A stale endpoint file is worse than none: a client reads it, connects to
    /// whatever now holds that port, and reports something that is not the
    /// daemon.
    ///
    /// Then wait, for a moment at most, for the answers still being written.
    /// The process ends when this returns, and an answer cut off by it is no
    /// answer: `joinery-drive stop` was answered "Stopping." by a daemon that
    /// then ended before the words left, and reported that the daemon did not
    /// answer, about the daemon it had just stopped (seen on Windows, where
    /// the exit won that race). The request that asked for the stop is
    /// counted before its handler runs, so its own answer is always waited
    /// for.
    pub fn shutdown(&self) {
        self.closing.store(true, std::sync::atomic::Ordering::SeqCst);
        let _ = std::fs::remove_file(&self.endpoint_path);
        let (count, freed) = &*self.in_flight;
        let deadline = std::time::Instant::now() + SHUTDOWN_GRACE;
        let mut n = count.lock().unwrap_or_else(|e| e.into_inner());
        while *n > 0 {
            let left = deadline.saturating_duration_since(std::time::Instant::now());
            if left.is_zero() {
                break;
            }
            n = freed.wait_timeout(n, left).unwrap_or_else(|e| e.into_inner()).0;
        }
    }

    /// Read one request and decide who is asking.
    ///
    /// Every refusal is decided from the head alone, before the body is
    /// *kept*, so an unauthorized caller cannot make the daemon allocate a
    /// buffer of whatever size they claimed. What is left on the wire rides
    /// along on the refusal, to be drained after the answer has gone out rather
    /// than before.
    fn read_request(&self, reader: &mut BufReader<TcpStream>) -> Result<Incoming, Refused> {
        let head = read_head(reader)?;
        let undrained = head.content_length;
        let now = std::time::Instant::now();

        // The user's own tools. The token is the whole of their credential, so
        // a wrong one is refused outright rather than tried as anything else.
        if let Some(token) = &head.token {
            if !constant_time_eq(token, &self.token) {
                return Err(refusal(
                    401,
                    "this request did not carry the daemon token",
                    undrained,
                ));
            }
            // Not required — a holder of the token is not a page that was
            // tricked into calling — but when one is given it must be ours.
            if head.hosts > 1
                || head
                    .host
                    .as_deref()
                    .is_some_and(|h| !host_is_loopback(h, self.port))
            {
                return Err(refusal(
                    403,
                    "this request named a host other than this daemon",
                    undrained,
                ));
            }
            if head.method == "POST" && head.route() == TICKET_PATH {
                let ticket = self.grants().mint_ticket(now);
                return Ok(Incoming::Reply(
                    Reply::Json(200, serde_json::json!({ "ticket": ticket })),
                    undrained,
                ));
            }
            let body = read_body(reader, head.content_length)?;
            return Ok(Incoming::Request(Request {
                method: head.method,
                path: head.path,
                body,
                caller: Caller::Owner,
            }));
        }

        // Everything below is reachable from a browser, so first: is this a
        // page that reached us under our own name? One `Host`, and it must be
        // loopback; a browser always sends exactly one.
        let host_ok = head.hosts == 1
            && head
                .host
                .as_deref()
                .is_some_and(|h| host_is_loopback(h, self.port));
        if !host_ok {
            return Err(refusal(
                if head.hosts == 0 { 400 } else { 403 },
                "this request did not name the daemon by its loopback address",
                undrained,
            ));
        }

        // The page's own files: no credential, no data, nothing to protect.
        if head.method == "GET" {
            if let Some(page) = self.pages.iter().find(|p| p.path == head.route()) {
                return Ok(Incoming::Reply(Reply::Page(page), undrained));
            }
        }

        // Anything past this point acts or reads the user's state, and must
        // come from the page itself, not from another site or another local
        // web server's page.
        if !head.is_from_our_origin(self.port) {
            return Err(refusal(
                403,
                "this request came from a page that is not this daemon's",
                undrained,
            ));
        }

        if head.method == "POST" && head.route() == SESSION_PATH {
            let session = head
                .ticket
                .as_deref()
                .and_then(|t| self.grants().redeem(t, now));
            return match session {
                Some(session) => Ok(Incoming::Reply(
                    Reply::Json(200, serde_json::json!({ "session": session })),
                    undrained,
                )),
                None => Err(refusal(
                    401,
                    "this ticket is not one the daemon gave out, or it has been used or has run out — open Settings again from Joinery Drive",
                    undrained,
                )),
            };
        }

        let admitted = head
            .session
            .as_deref()
            .is_some_and(|s| self.grants().admit(s, now));
        if !admitted {
            return Err(refusal(
                401,
                "this request did not carry the daemon token or a settings session",
                undrained,
            ));
        }
        let body = read_body(reader, head.content_length)?;
        Ok(Incoming::Request(Request {
            method: head.method,
            path: head.path,
            body,
            caller: Caller::Page,
        }))
    }

    fn grants(&self) -> std::sync::MutexGuard<'_, Grants> {
        // A panic while holding this cannot leave the grants half-written in a
        // way that matters: the worst case is a ticket that did not get spent.
        self.grants.lock().unwrap_or_else(|e| e.into_inner())
    }
}

/// A request that will not be served, and how much of its body is still on the
/// wire.
struct Refused {
    status: u16,
    body: Value,
    undrained: usize,
}

/// Read the request line and headers.
fn read_head(reader: &mut BufReader<TcpStream>) -> Result<Head, Refused> {
    let mut line = String::new();
    if reader.read_line(&mut line).is_err() || line.trim().is_empty() {
        return Err(refusal(400, "empty request", 0));
    }
    let mut parts = line.split_whitespace();
    let mut head = Head {
        method: parts.next().unwrap_or("").to_string(),
        path: parts.next().unwrap_or("/").to_string(),
        ..Head::default()
    };

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
            let value = value.trim().to_string();
            match name.trim().to_ascii_lowercase().as_str() {
                "x-joinery-token" => head.token = Some(value),
                "x-joinery-ticket" => head.ticket = Some(value),
                "x-joinery-session" => head.session = Some(value),
                "host" => {
                    head.hosts += 1;
                    head.host = Some(value);
                }
                "origin" => head.origin = Some(value),
                "sec-fetch-site" => head.fetch_site = Some(value.to_ascii_lowercase()),
                "content-length" => head.content_length = value.parse().unwrap_or(0),
                _ => {}
            }
        }
    }
    Ok(head)
}

/// Read a body the caller has been allowed to send.
fn read_body(reader: &mut BufReader<TcpStream>, length: usize) -> Result<Value, Refused> {
    if length > MAX_REQUEST_BODY {
        return Err(refusal(413, "request body too large", length));
    }
    let mut raw = vec![0u8; length];
    if length > 0 && reader.read_exact(&mut raw).is_err() {
        return Err(refusal(400, "truncated body", 0));
    }
    if raw.is_empty() {
        return Ok(Value::Null);
    }
    serde_json::from_slice(&raw).map_err(|_| refusal(400, "body is not JSON", 0))
}

/// Read and throw away a request body we are not going to use.
///
/// Necessary, not merely polite, and the reason is a genuine difference between
/// operating systems. Closing a socket that still has unread data in its receive
/// buffer makes the kernel send RST instead of FIN — and on macOS and Windows
/// that RST **discards the response already sitting in the client's buffer**.
/// So a refused request arrived at the caller as no answer at all, which every
/// caller reads as "the daemon is not running": exactly the distinction this
/// channel exists to keep. Linux is more forgiving and never showed it.
///
/// Bounded by [`DRAIN_TIMEOUT`] as a deadline on the whole drain, which is the
/// only bound that serves the purpose: everything the caller has delivered by
/// then is gone from the buffer, and anything still to come was never in it. A
/// read that times out, fails, or reaches end of stream ends the drain.
fn discard_body(reader: &mut BufReader<TcpStream>, declared: usize) {
    let mut scratch = [0u8; 64 * 1024];
    let mut left = declared;
    let deadline = std::time::Instant::now() + DRAIN_TIMEOUT;
    while left > 0 {
        let remaining = deadline.saturating_duration_since(std::time::Instant::now());
        if remaining.is_zero() || reader.get_ref().set_read_timeout(Some(remaining)).is_err() {
            return;
        }
        let want = left.min(scratch.len());
        match reader.read(&mut scratch[..want]) {
            Ok(0) | Err(_) => return,
            Ok(n) => left -= n,
        }
    }
}

fn write_response(stream: &mut TcpStream, reply: &Reply) -> std::io::Result<()> {
    let (status, content_type, text) = match reply {
        Reply::Json(status, body) => (
            *status,
            "application/json",
            serde_json::to_string(body).unwrap_or_else(|_| "{}".into()),
        ),
        Reply::Page(page) => (200, page.content_type, page.body.to_string()),
    };
    let reason = match status {
        200 => "OK",
        400 => "Bad Request",
        401 => "Unauthorized",
        403 => "Forbidden",
        404 => "Not Found",
        405 => "Method Not Allowed",
        409 => "Conflict",
        413 => "Payload Too Large",
        _ => "Error",
    };
    // No `Access-Control-Allow-*`, on anything, ever: without one a browser
    // will not let any other site read an answer, nor send it the preflighted
    // requests that carry a session header.
    write!(
        stream,
        "HTTP/1.1 {status} {reason}\r\n\
         Content-Type: {content_type}\r\n\
         Content-Length: {}\r\n\
         Cache-Control: no-store\r\n\
         Content-Security-Policy: {CONTENT_SECURITY_POLICY}\r\n\
         X-Frame-Options: DENY\r\n\
         X-Content-Type-Options: nosniff\r\n\
         Referrer-Policy: no-referrer\r\n\
         Cross-Origin-Resource-Policy: same-origin\r\n\
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

/// The address that opens the settings page, with a fresh ticket in it.
///
/// For the tray and `joinery-drive settings`, which hold the token and hand the
/// browser something worth far less: a ticket that is spent the moment the page
/// loads and is gone in two minutes either way. It rides in the fragment, which
/// the browser never sends anywhere. `section` picks the part of the page to
/// show first (`issues`), and is only ever one of the page's own words.
pub fn settings_url(endpoint: &Endpoint, section: Option<&str>) -> Option<String> {
    let answer = ask(endpoint, "POST", TICKET_PATH, Value::Null)?;
    let ticket = answer.get("ticket")?.as_str()?;
    let mut fragment = format!("t={ticket}");
    if let Some(section) =
        section.filter(|s| !s.is_empty() && s.bytes().all(|b| b.is_ascii_lowercase() || b == b'-'))
    {
        fragment.push_str("&s=");
        fragment.push_str(section);
    }
    Some(endpoint.url(&format!("{PAGE_PATH}#{fragment}")))
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
    fn a_stop_waits_for_the_answer_it_is_still_writing() {
        // The handler stands for `/stop`: it tells the daemon to stop, and the
        // daemon shuts the channel down while the answer is still on its way.
        // Shutdown returns only once that answer has gone, and the caller has it.
        let dir = temp("stop-answer");
        let path = dir.join("control.json");
        let server = std::sync::Arc::new(ControlServer::bind(&path, new_token()).unwrap());
        let endpoint = Endpoint::load(&path).unwrap();
        let (asked, stop) = std::sync::mpsc::channel();
        let serving = std::sync::Arc::clone(&server);
        std::thread::spawn(move || {
            serving.serve(move |_| {
                let _ = asked.send(());
                // Well inside SHUTDOWN_GRACE, and well above the floor below,
                // so a slow box neither cuts the wait short nor fakes it.
                std::thread::sleep(Duration::from_millis(1500));
                (200, json!({ "ok": true }))
            })
        });
        let caller = std::thread::spawn(move || ask(&endpoint, "POST", "/stop", Value::Null));
        stop.recv().unwrap();
        let started = std::time::Instant::now();
        server.shutdown();
        assert!(
            started.elapsed() >= Duration::from_millis(300),
            "shutdown returned while the answer was still being written"
        );
        assert_eq!(caller.join().unwrap(), Some(json!({ "ok": true })));
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_connection_made_after_shutdown_is_not_served() {
        let dir = temp("after-stop");
        let path = dir.join("control.json");
        let server = std::sync::Arc::new(ControlServer::bind(&path, new_token()).unwrap());
        let endpoint = Endpoint::load(&path).unwrap();
        let served = std::sync::Arc::new(std::sync::atomic::AtomicBool::new(false));
        let seen = std::sync::Arc::clone(&served);
        let serving = std::sync::Arc::clone(&server);
        std::thread::spawn(move || {
            serving.serve(move |_| {
                seen.store(true, std::sync::atomic::Ordering::SeqCst);
                (200, json!({ "ok": true }))
            })
        });
        server.shutdown();
        assert_eq!(ask(&endpoint, "GET", "/status", Value::Null), None);
        assert!(!served.load(std::sync::atomic::Ordering::SeqCst));
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

    // -----------------------------------------------------------------------
    // The settings page: who gets in, and who does not
    // -----------------------------------------------------------------------

    static TEST_PAGES: &[Page] = &[Page {
        path: "/settings",
        content_type: "text/html; charset=utf-8",
        body: "<!doctype html><title>t</title>",
    }];

    /// A server serving the test page, forever, with a handler that echoes who
    /// it was asked by — and counts, so a test can prove a refused request
    /// never reached it.
    fn page_server(
        tag: &str,
    ) -> (
        Endpoint,
        std::sync::Arc<std::sync::atomic::AtomicUsize>,
        PathBuf,
    ) {
        use std::sync::atomic::{AtomicUsize, Ordering};
        let dir = temp(tag);
        let path = dir.join("control.json");
        let server = std::sync::Arc::new(
            ControlServer::bind(&path, new_token())
                .unwrap()
                .with_pages(TEST_PAGES),
        );
        let endpoint = Endpoint::load(&path).unwrap();
        let reached = std::sync::Arc::new(AtomicUsize::new(0));
        let counter = std::sync::Arc::clone(&reached);
        std::thread::spawn(move || {
            server.serve(move |req| {
                counter.fetch_add(1, Ordering::SeqCst);
                (
                    200,
                    json!({ "path": req.path, "caller": format!("{:?}", req.caller) }),
                )
            })
        });
        (endpoint, reached, dir)
    }

    /// Send exactly these bytes and return (status, headers, body).
    fn raw(port: u16, request: &str) -> (u16, String, String) {
        let mut stream = TcpStream::connect(SocketAddr::from((Ipv4Addr::LOCALHOST, port))).unwrap();
        stream
            .set_read_timeout(Some(Duration::from_secs(10)))
            .unwrap();
        stream.write_all(request.as_bytes()).unwrap();
        let mut answer = String::new();
        let _ = stream.read_to_string(&mut answer);
        let (head, body) = answer
            .split_once("\r\n\r\n")
            .unwrap_or((answer.as_str(), ""));
        let status = head
            .split_whitespace()
            .nth(1)
            .and_then(|s| s.parse().ok())
            .unwrap_or(0);
        (status, head.to_string(), body.to_string())
    }

    fn get(port: u16, path: &str, extra: &str) -> (u16, String, String) {
        raw(
            port,
            &format!(
                "GET {path} HTTP/1.1\r\nHost: 127.0.0.1:{port}\r\n{extra}Connection: close\r\n\r\n"
            ),
        )
    }

    fn post(port: u16, path: &str, extra: &str) -> (u16, String, String) {
        raw(
            port,
            &format!(
                "POST {path} HTTP/1.1\r\nHost: 127.0.0.1:{port}\r\nOrigin: http://127.0.0.1:{port}\r\n{extra}Content-Length: 0\r\nConnection: close\r\n\r\n"
            ),
        )
    }

    /// A session the way the page gets one: a ticket from the token holder,
    /// traded at the session path.
    fn session(endpoint: &Endpoint) -> String {
        let url = settings_url(endpoint, None).unwrap();
        let ticket = url.split("#t=").nth(1).unwrap().to_string();
        let (status, _, body) = post(
            endpoint.port,
            SESSION_PATH,
            &format!("X-Joinery-Ticket: {ticket}\r\n"),
        );
        assert_eq!(status, 200, "{body}");
        serde_json::from_str::<Value>(&body).unwrap()["session"]
            .as_str()
            .unwrap()
            .to_string()
    }

    fn no_cors(headers: &str) {
        assert!(
            !headers
                .to_ascii_lowercase()
                .contains("access-control-allow"),
            "an answer carried a CORS header, which would let another site read it:\n{headers}"
        );
    }

    #[test]
    fn the_page_is_served_without_a_credential_and_refuses_to_be_framed() {
        let (endpoint, reached, dir) = page_server("page");
        let (status, headers, body) = get(endpoint.port, "/settings", "");
        assert_eq!(status, 200);
        assert!(body.contains("<title>"));
        let lower = headers.to_ascii_lowercase();
        assert!(lower.contains("content-type: text/html"));
        assert!(lower.contains("frame-ancestors 'none'"), "{headers}");
        assert!(lower.contains("x-frame-options: deny"));
        assert!(lower.contains("x-content-type-options: nosniff"));
        assert!(lower.contains("script-src 'self'"));
        no_cors(&headers);
        // Served by the channel; the handler, which holds the user's state,
        // never saw it.
        assert_eq!(reached.load(std::sync::atomic::Ordering::SeqCst), 0);
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_page_reached_under_any_other_name_is_refused() {
        // DNS rebinding: a site points its own hostname at 127.0.0.1 and its
        // pages can then reach this port as if it were their own. The Host the
        // browser sends still names the site.
        let (endpoint, reached, dir) = page_server("rebind");
        let port = endpoint.port;
        let (status, headers, _) = raw(
            port,
            &format!("GET /settings HTTP/1.1\r\nHost: attacker.example:{port}\r\nConnection: close\r\n\r\n"),
        );
        assert_eq!(status, 403);
        no_cors(&headers);
        // Another local web server's port is not ours either.
        let (status, _, _) = raw(
            port,
            &format!(
                "GET /settings HTTP/1.1\r\nHost: 127.0.0.1:{}\r\nConnection: close\r\n\r\n",
                port.wrapping_add(1)
            ),
        );
        assert_eq!(status, 403);
        // No Host at all is not a browser.
        let (status, _, _) = raw(port, "GET /settings HTTP/1.1\r\nConnection: close\r\n\r\n");
        assert_eq!(status, 400);
        // Two of them is somebody hoping one check reads the first and another
        // the second.
        let (status, _, _) = raw(
            port,
            &format!("GET /settings HTTP/1.1\r\nHost: 127.0.0.1:{port}\r\nHost: attacker.example\r\nConnection: close\r\n\r\n"),
        );
        assert_eq!(status, 403);
        // And a rebinding page holding a stolen session still gets nowhere.
        let s = session(&endpoint);
        let (status, _, _) = raw(
            port,
            &format!("GET /status HTTP/1.1\r\nHost: attacker.example:{port}\r\nX-Joinery-Session: {s}\r\nConnection: close\r\n\r\n"),
        );
        assert_eq!(status, 403);
        assert_eq!(reached.load(std::sync::atomic::Ordering::SeqCst), 0);
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_request_with_no_credential_never_reaches_the_handler() {
        let (endpoint, reached, dir) = page_server("anon");
        let (status, headers, _) = get(endpoint.port, "/status", "");
        assert_eq!(status, 401);
        no_cors(&headers);
        let (status, _, _) = post(endpoint.port, "/pause", "");
        assert_eq!(status, 401);
        assert_eq!(reached.load(std::sync::atomic::Ordering::SeqCst), 0);
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn only_the_token_holder_is_given_a_ticket() {
        let (endpoint, _reached, dir) = page_server("ticket");
        let (status, _, body) = post(endpoint.port, TICKET_PATH, "");
        assert_eq!(status, 401, "{body}");
        assert!(!body.contains("ticket\":"));
        // A page session is not the token either: its request goes to the
        // handler as an ordinary page request and mints nothing.
        let s = session(&endpoint);
        let (_, _, body) = post(
            endpoint.port,
            TICKET_PATH,
            &format!("X-Joinery-Session: {s}\r\n"),
        );
        let answer: Value = serde_json::from_str(&body).unwrap();
        assert!(answer.get("ticket").is_none());
        assert_eq!(answer["caller"], "Page");
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_ticket_buys_one_session_once() {
        let (endpoint, reached, dir) = page_server("redeem");
        let port = endpoint.port;
        let url = settings_url(&endpoint, Some("issues")).unwrap();
        assert!(
            url.starts_with(&format!("http://127.0.0.1:{port}/settings#t=")),
            "{url}"
        );
        assert!(url.ends_with("&s=issues"));
        let ticket = url
            .split("#t=")
            .nth(1)
            .unwrap()
            .split('&')
            .next()
            .unwrap()
            .to_string();

        let (status, _, body) = post(
            port,
            SESSION_PATH,
            &format!("X-Joinery-Ticket: {ticket}\r\n"),
        );
        assert_eq!(status, 200);
        let s = serde_json::from_str::<Value>(&body).unwrap()["session"]
            .as_str()
            .unwrap()
            .to_string();
        assert_ne!(s, ticket);

        // Spent. A ticket that turns up again — in a history file, a synced
        // browser profile — buys nothing.
        let (status, _, _) = post(
            port,
            SESSION_PATH,
            &format!("X-Joinery-Ticket: {ticket}\r\n"),
        );
        assert_eq!(status, 401);

        // The session works, and the handler knows it is the page asking.
        let (status, _, body) = get(port, "/status", &format!("X-Joinery-Session: {s}\r\n"));
        assert_eq!(status, 200);
        assert_eq!(
            serde_json::from_str::<Value>(&body).unwrap()["caller"],
            "Page"
        );
        assert_eq!(reached.load(std::sync::atomic::Ordering::SeqCst), 1);

        // A made-up session does not.
        let (status, _, _) = get(port, "/status", "X-Joinery-Session: 00\r\n");
        assert_eq!(status, 401);
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_credential_counts_only_in_its_own_header() {
        // Not in a cookie, which a browser attaches to requests other sites
        // make; not in a query string, which ends up in history and logs.
        let (endpoint, reached, dir) = page_server("where");
        let port = endpoint.port;
        let s = session(&endpoint);
        for (path, extra) in [
            (format!("/status?session={s}"), String::new()),
            (
                "/status".to_string(),
                format!("Cookie: x-joinery-session={s}\r\n"),
            ),
            (format!("/status?token={}", endpoint.token), String::new()),
            (
                "/status".to_string(),
                format!("Cookie: x-joinery-token={}\r\n", endpoint.token),
            ),
        ] {
            let (status, _, _) = get(port, &path, &extra);
            assert_eq!(status, 401, "{path} {extra}");
        }
        assert_eq!(reached.load(std::sync::atomic::Ordering::SeqCst), 0);
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_session_or_ticket_from_another_origin_is_refused() {
        let (endpoint, reached, dir) = page_server("origin");
        let port = endpoint.port;
        let s = session(&endpoint);
        for extra in [
            "Origin: https://attacker.example\r\n".to_string(),
            format!("Origin: http://127.0.0.1:{}\r\n", port.wrapping_add(1)),
            "Origin: null\r\n".to_string(),
            "Sec-Fetch-Site: cross-site\r\n".to_string(),
            "Sec-Fetch-Site: same-site\r\n".to_string(),
        ] {
            let (status, headers, _) = raw(
                port,
                &format!(
                    "POST /pause HTTP/1.1\r\nHost: 127.0.0.1:{port}\r\nX-Joinery-Session: {s}\r\n{extra}Content-Length: 0\r\nConnection: close\r\n\r\n"
                ),
            );
            assert_eq!(status, 403, "{extra}");
            no_cors(&headers);
        }
        // The exchange itself is held to the same rule, and a refused exchange
        // does not spend the ticket for the real page.
        let url = settings_url(&endpoint, None).unwrap();
        let ticket = url.split("#t=").nth(1).unwrap().to_string();
        let (status, _, _) = raw(
            port,
            &format!("POST {SESSION_PATH} HTTP/1.1\r\nHost: 127.0.0.1:{port}\r\nOrigin: https://attacker.example\r\nX-Joinery-Ticket: {ticket}\r\nContent-Length: 0\r\n\r\n"),
        );
        assert_eq!(status, 403);
        let (status, _, _) = post(
            port,
            SESSION_PATH,
            &format!("X-Joinery-Ticket: {ticket}\r\n"),
        );
        assert_eq!(status, 200);
        assert_eq!(reached.load(std::sync::atomic::Ordering::SeqCst), 0);
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn nothing_ever_answers_a_preflight_with_permission() {
        // The request a browser sends before letting another site attach a
        // custom header. Without an Access-Control-Allow answer it stops there.
        let (endpoint, reached, dir) = page_server("preflight");
        let port = endpoint.port;
        let (status, headers, _) = raw(
            port,
            &format!(
                "OPTIONS /pause HTTP/1.1\r\nHost: 127.0.0.1:{port}\r\nOrigin: https://attacker.example\r\nAccess-Control-Request-Method: POST\r\nAccess-Control-Request-Headers: x-joinery-session\r\nAccess-Control-Request-Private-Network: true\r\nConnection: close\r\n\r\n"
            ),
        );
        assert_ne!(status, 200);
        no_cors(&headers);
        assert_eq!(reached.load(std::sync::atomic::Ordering::SeqCst), 0);
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_quiet_connection_does_not_hold_up_everybody_else() {
        // Chrome opens a spare connection it may never use. Served one at a
        // time, that spare held the daemon for the whole serve timeout and the
        // page hung half-drawn.
        let (endpoint, _reached, dir) = page_server("quiet");
        let _idle =
            TcpStream::connect(SocketAddr::from((Ipv4Addr::LOCALHOST, endpoint.port))).unwrap();
        std::thread::sleep(Duration::from_millis(100));
        let asked = std::time::Instant::now();
        let answer = ask(&endpoint, "GET", "/status", Value::Null).expect("answered");
        assert_eq!(answer["caller"], "Owner");
        assert!(
            asked.elapsed() < Duration::from_secs(5),
            "a silent socket held the daemon for {:?}",
            asked.elapsed()
        );
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_ticket_runs_out_and_a_session_left_alone_ends() {
        let t0 = std::time::Instant::now();
        let mut grants = Grants::default();
        let late = grants.mint_ticket(t0);
        assert!(grants
            .redeem(&late, t0 + TICKET_TTL + Duration::from_secs(1))
            .is_none());

        let ticket = grants.mint_ticket(t0);
        let session = grants.redeem(&ticket, t0 + Duration::from_secs(5)).unwrap();
        // Used, it stays alive past the idle limit measured from its birth...
        assert!(grants.admit(&session, t0 + SESSION_IDLE / 2));
        assert!(grants.admit(&session, t0 + SESSION_IDLE));
        // ...and left alone, it ends.
        assert!(!grants.admit(&session, t0 + SESSION_IDLE * 2 + Duration::from_secs(1)));
    }

    #[test]
    fn handing_out_tickets_in_a_loop_does_not_grow_without_bound() {
        let t0 = std::time::Instant::now();
        let mut grants = Grants::default();
        let first = grants.mint_ticket(t0);
        for _ in 0..100 {
            grants.mint_ticket(t0);
        }
        assert!(grants.tickets.len() <= MAX_GRANTS);
        assert!(grants.redeem(&first, t0).is_none(), "the oldest went first");
        for _ in 0..100 {
            let t = grants.mint_ticket(t0);
            grants.redeem(&t, t0).unwrap();
        }
        assert!(grants.sessions.len() <= MAX_GRANTS);
    }

    #[test]
    fn only_loopback_names_this_daemon() {
        assert!(host_is_loopback("127.0.0.1:4000", 4000));
        assert!(host_is_loopback("localhost:4000", 4000));
        assert!(host_is_loopback("LOCALHOST:4000", 4000));
        assert!(host_is_loopback("127.0.0.1", 4000));
        assert!(!host_is_loopback("127.0.0.1:4001", 4000));
        assert!(!host_is_loopback("attacker.example:4000", 4000));
        assert!(!host_is_loopback("127.0.0.1.attacker.example:4000", 4000));
        assert!(!host_is_loopback("localhost.:4000", 4000));
        assert!(!host_is_loopback("[::1]:4000", 4000));
        assert!(!host_is_loopback("", 4000));

        assert!(origin_is_ours("http://127.0.0.1:4000", 4000));
        assert!(origin_is_ours("http://localhost:4000", 4000));
        assert!(!origin_is_ours("https://127.0.0.1:4000", 4000));
        assert!(!origin_is_ours("http://127.0.0.1:4001", 4000));
        assert!(!origin_is_ours("null", 4000));
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
