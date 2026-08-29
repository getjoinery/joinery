//! The network between the engine and the server, including everything it does
//! wrong.
//!
//! A network that only ever succeeds or only ever fails is easy to write
//! against and teaches nothing. The failures worth simulating are the awkward
//! ones in the middle, and one above all:
//!
//! > **the request arrived, the server did the work, and the answer never came
//! > back.**
//!
//! From the client's side that is indistinguishable from the request never
//! arriving at all, and the two demand opposite responses — retry, or do not.
//! There is no way to tell them apart, so the engine must not need to: every
//! mutating call carries an idempotency key it wrote down *before* sending, and
//! the retry is recognized rather than repeated. That property is not provable
//! by reading the code. It is provable by making this layer lose answers at
//! random and checking that nothing was ever done twice.
//!
//! The rest of the matrix — 5xx, rate limits, corrupted chunks, truncated
//! downloads, latency — is here for the same reason: so the handling exists and
//! is exercised, rather than existing and being wrong.

use std::io::Write;
use std::sync::{Arc, Mutex};

use jd_proto::{DriveApi, ProtoError, ReadSeek, UploadOutcome, UploadParams};
use serde_json::Value;

use crate::clock::SimClock;
use crate::rng::SimRng;
use crate::server::{ApiRefusal, MockServer, CHUNK_BYTES};

/// How often each thing goes wrong, in parts per thousand of attempts.
///
/// Zero everywhere by default: a scenario asks for the faults it wants to prove
/// something about, and the property-based runs turn the whole matrix on at
/// once.
#[derive(Debug, Clone, Default)]
pub struct NetFaults {
    /// The request never reaches the server.
    pub drop_before: u64,
    /// The server does the work; the answer is lost on the way back. The one
    /// that matters.
    pub drop_after: u64,
    /// The server itself falls over. Retryable, and must not be read as "the
    /// thing I asked for did not happen".
    pub server_5xx: u64,
    /// Too many requests. Retryable, and a client that hammers through it is
    /// how a fleet takes down its own server.
    pub rate_limit: u64,
    /// A byte flips in a chunk on the way up. Nothing downstream may accept
    /// this: the hash check at completion is the backstop.
    pub corrupt_chunk: u64,
    /// A download ends early without saying so.
    pub truncate_download: u64,
    /// Milliseconds the clock advances per call — latency, so timeouts and
    /// expiries are reachable.
    pub latency_ms: u64,
    /// Lose the answer to one named action, once, then stop.
    ///
    /// The rate knobs above are blunt by design, and there is one case they
    /// cannot reach: the completion call at the end of an upload. A rate high
    /// enough to hit it also loses the answer to the init before it, so the
    /// attempt dies early and completion is never reached at all — which is
    /// exactly why an upload whose *completion* answer went missing went
    /// untested here while devices wedged on it.
    pub lose_answer_to: Option<String>,

    /// Refuse one named action, once, before the server sees it — then stop.
    ///
    /// The other half of [`NetFaults::lose_answer_to`], and it exists for the
    /// same reason: a rate high enough to fail one chosen call fails the change
    /// poll at the top of the pass too, and a pass that cannot poll plans
    /// nothing at all. So the states that need *one* operation left queued
    /// while everything around it succeeded could not be built, and every one
    /// of them went untested.
    pub refuse_before: Option<String>,

    /// Stop the process dead after this many more calls have been answered.
    ///
    /// Not a network fault at all, and it sits here only because a network call
    /// is the one moment worth dying at. The server has acted and the client
    /// has not yet written down that it did, which is the entire reason
    /// recovery exists: a retry would repeat the act, and giving up would lose
    /// it, and only asking the server settles which.
    ///
    /// Every kill the simulator could stage before this one landed BETWEEN
    /// passes, so a pass always ran to completion and no decision was ever left
    /// half-written. This is the other half, and it reaches states nothing else
    /// can -- an op recorded in flight with the work behind it already done, a
    /// change cursor moved past entries that were never absorbed.
    pub die_after_calls: Option<u64>,
}

/// What a simulated death panics with, so a harness can tell it from a test
/// failure and nothing else has to guess.
pub const DIED: &str = "jd-sim: the process died here";

impl NetFaults {
    /// Everything off.
    pub fn none() -> NetFaults {
        NetFaults::default()
    }

    /// The full matrix at a rate that makes a scenario of a few hundred calls
    /// hit each fault several times.
    pub fn chaos() -> NetFaults {
        NetFaults {
            drop_before: 40,
            drop_after: 40,
            server_5xx: 30,
            rate_limit: 20,
            corrupt_chunk: 20,
            truncate_download: 20,
            latency_ms: 5,
            // Aimed, not random: chaos is about volume, and these two are
            // about hitting a single call a rate would drown.
            lose_answer_to: None,
            refuse_before: None,
            die_after_calls: None,
        }
    }
}

/// What actually happened, so a scenario can assert on it rather than infer it.
#[derive(Debug, Default, Clone)]
pub struct NetStats {
    pub calls: u64,
    pub dropped_before: u64,
    pub dropped_after: u64,
    pub server_errors: u64,
    pub rate_limited: u64,
    pub corrupted_chunks: u64,
    pub truncated_downloads: u64,
}

struct NetInner {
    rng: SimRng,
    faults: NetFaults,
    stats: NetStats,
}

/// The engine's network, wired to a [`MockServer`].
#[derive(Clone)]
pub struct SimNet {
    server: MockServer,
    clock: SimClock,
    inner: Arc<Mutex<NetInner>>,
    device: String,
}

impl SimNet {
    pub fn new(server: MockServer, clock: SimClock, seed: u64, device: &str) -> SimNet {
        SimNet {
            server,
            clock,
            inner: Arc::new(Mutex::new(NetInner {
                rng: SimRng::new(seed),
                faults: NetFaults::none(),
                stats: NetStats::default(),
            })),
            device: device.to_string(),
        }
    }

    pub fn with_faults(self, faults: NetFaults) -> SimNet {
        self.inner.lock().unwrap().faults = faults;
        self
    }

    pub fn set_faults(&self, faults: NetFaults) {
        self.inner.lock().unwrap().faults = faults;
    }

    /// Arm a death without disturbing whatever else is set.
    ///
    /// Deliberately not `set_faults`, which replaces the lot: a workload that
    /// armed a death by that route would quietly switch the network back to
    /// perfect for the rest of the run, and the arm would be testing a kill on
    /// a healthy network while claiming to test one under chaos.
    pub fn arm_death(&self, after_calls: u64) {
        self.inner.lock().unwrap().faults.die_after_calls = Some(after_calls);
    }

    pub fn stats(&self) -> NetStats {
        self.inner.lock().unwrap().stats.clone()
    }

    pub fn server(&self) -> &MockServer {
        &self.server
    }

    fn roll(&self, per_thousand: u64) -> bool {
        if per_thousand == 0 {
            return false;
        }
        let mut inner = self.inner.lock().unwrap();
        inner.rng.chance(per_thousand, 1000)
    }

    /// Everything that can go wrong *before* the server sees the request.
    /// Returns `Some(error)` when the call should not proceed.
    fn before_call(&self) -> Option<ProtoError> {
        let (faults, latency) = {
            let inner = self.inner.lock().unwrap();
            (inner.faults.clone(), inner.faults.latency_ms)
        };
        self.clock.advance_ms(latency);
        self.inner.lock().unwrap().stats.calls += 1;

        if self.roll(faults.drop_before) {
            self.inner.lock().unwrap().stats.dropped_before += 1;
            return Some(ProtoError::Transport(
                "connection reset before the request was sent".into(),
            ));
        }
        if self.roll(faults.server_5xx) {
            self.inner.lock().unwrap().stats.server_errors += 1;
            return Some(ProtoError::Api {
                status: 503,
                errortype: "TransactionError".into(),
                message: "the server is unavailable".into(),
                data: Value::Null,
            });
        }
        if self.roll(faults.rate_limit) {
            self.inner.lock().unwrap().stats.rate_limited += 1;
            return Some(ProtoError::Api {
                status: 429,
                errortype: "RateLimitError".into(),
                message: "slow down".into(),
                data: Value::Null,
            });
        }
        None
    }

    /// The answer is lost on the way back. The work is already done.
    fn answer_lost(&self) -> bool {
        let rate = self.inner.lock().unwrap().faults.drop_after;
        if self.roll(rate) {
            self.inner.lock().unwrap().stats.dropped_after += 1;
            true
        } else {
            false
        }
    }

    /// Lose the answer to this one named call, then disarm so the retry gets
    /// through and the scenario can watch what the client does with it.
    fn answer_lost_to(&self, name: &str) -> bool {
        let mut inner = self.inner.lock().unwrap();
        if inner.faults.lose_answer_to.as_deref() == Some(name) {
            inner.faults.lose_answer_to = None;
            inner.stats.dropped_after += 1;
            true
        } else {
            false
        }
    }

    /// Refuse this one named call before the server sees it, then disarm.
    fn refused_before(&self, name: &str) -> bool {
        let mut inner = self.inner.lock().unwrap();
        if inner.faults.refuse_before.as_deref() == Some(name) {
            inner.faults.refuse_before = None;
            inner.stats.dropped_before += 1;
            true
        } else {
            false
        }
    }

    /// Count down to the death, and report the moment it arrives. Nothing is
    /// held when this returns true -- panicking with a lock in hand would
    /// poison it and turn every later use into a failure of its own.
    fn died_now(&self) -> bool {
        let mut inner = self.inner.lock().unwrap();
        match inner.faults.die_after_calls {
            Some(0) => {
                inner.faults.die_after_calls = None;
                true
            }
            Some(n) => {
                inner.faults.die_after_calls = Some(n - 1);
                false
            }
            None => false,
        }
    }

    fn run(&self, name: &str, body: &Value, key: Option<&str>) -> jd_proto::Result<Value> {
        let netlog = std::env::var("JD_NETLOG").is_ok();
        if self.refused_before(name) {
            if netlog {
                eprintln!("NETLOG {} {name} {body} => REFUSED-BEFORE", self.device);
            }
            return Err(ProtoError::Transport(
                "connection reset before the request was sent".into(),
            ));
        }
        if let Some(e) = self.before_call() {
            if netlog {
                eprintln!("NETLOG {} {name} {body} => DROPPED {e:?}", self.device);
            }
            return Err(e);
        }
        self.server.acting_as(Some(&self.device));
        let out = match key {
            Some(k) => self.server.action_idempotent(name, body, k),
            None => self.server.action(name, body),
        };
        if netlog {
            match &out {
                Ok(v) => {
                    let brief = format!("{v}");
                    let brief: String = brief.chars().take(400).collect();
                    eprintln!("NETLOG {} {name} {body} => OK {brief}", self.device);
                }
                Err(r) => eprintln!(
                    "NETLOG {} {name} {body} => ERR {} {} {}",
                    self.device, r.status, r.message, r.data
                ),
            }
        }
        let value = out.map_err(to_proto)?;
        // Deliberately after the server committed and before the caller can
        // record it. See `die_after_calls`.
        if self.died_now() {
            panic!("{DIED}");
        }
        if self.answer_lost() || self.answer_lost_to(name) {
            if netlog {
                eprintln!("NETLOG {} {name} => ANSWER LOST", self.device);
            }
            // Deliberately after the server committed. This is the case the
            // whole idempotency discipline exists for, and the only way to test
            // it is to produce it on purpose.
            return Err(ProtoError::Transport(
                "connection reset while reading the response".into(),
            ));
        }
        Ok(value)
    }
}

fn to_proto(r: ApiRefusal) -> ProtoError {
    ProtoError::Api {
        status: r.status,
        errortype: r.errortype.to_string(),
        message: r.message,
        data: r.data,
    }
}

impl DriveApi for SimNet {
    fn action(&self, name: &str, body: Value) -> jd_proto::Result<Value> {
        self.run(name, &body, None)
    }

    fn action_idempotent(&self, name: &str, body: Value, key: &str) -> jd_proto::Result<Value> {
        self.run(name, &body, Some(key))
    }

    fn upload(
        &self,
        params: &UploadParams,
        reader: &mut dyn ReadSeek,
    ) -> jd_proto::Result<UploadOutcome> {
        use std::io::SeekFrom;

        // The bodies come from `UploadParams` itself, not from here. Two
        // hand-written copies of one wire format diverge the first time either
        // is edited, and the copy that diverges silently is this one — the
        // simulator would go on passing while testing a protocol the server
        // does not speak.
        let init = self.run("drive_upload_init", &params.init_body(), None)?;

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
        let chunk = init
            .get("chunk_bytes")
            .and_then(Value::as_u64)
            .unwrap_or(CHUNK_BYTES) as usize;

        let total = params.size_bytes;
        let mut offset = 0u64;
        let mut stuck = 0u32;
        let mut buf = vec![0u8; chunk];
        while offset < total {
            let want = std::cmp::min(chunk as u64, total - offset) as usize;
            reader.seek(SeekFrom::Start(offset))?;
            reader.read_exact(&mut buf[..want])?;

            let mut wire = buf[..want].to_vec();
            let corrupt_rate = self.inner.lock().unwrap().faults.corrupt_chunk;
            let corrupt = self.roll(corrupt_rate);
            if corrupt && !wire.is_empty() {
                self.inner.lock().unwrap().stats.corrupted_chunks += 1;
                wire[0] ^= 0xFF;
            }

            if let Some(e) = self.before_call() {
                return Err(e);
            }
            match self.server.upload_chunk(&token, offset, &wire) {
                Ok(v) => {
                    offset = v
                        .get("received_bytes")
                        .and_then(Value::as_u64)
                        .unwrap_or(offset);
                    stuck = 0;
                    if self.answer_lost() {
                        return Err(ProtoError::Transport(
                            "connection reset while reading the chunk response".into(),
                        ));
                    }
                }
                Err(r) => {
                    let e = to_proto(r);
                    match e.chunk_resync_offset() {
                        Some(server_offset) => {
                            if server_offset == offset {
                                stuck += 1;
                                if stuck > 3 {
                                    return Err(ProtoError::Contract(
                                        "chunk upload loops at one offset without progress".into(),
                                    ));
                                }
                            } else {
                                stuck = 0;
                            }
                            offset = server_offset;
                        }
                        None => return Err(e),
                    }
                }
            }
        }

        // Scoped to the token, matching the real client: a retry gets a fresh
        // token and so sends a different request, which a longer-lived key
        // would misdescribe.
        let complete_key = match &params.idempotency_key {
            Some(key) => format!("{key}-complete-{token}"),
            None => format!("complete-{token}"),
        };
        let complete = self.run(
            "drive_upload_complete",
            &params.complete_body(&token),
            Some(&complete_key),
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

    fn download(&self, url: &str, from: u64, out: &mut dyn Write) -> jd_proto::Result<u64> {
        if let Some(e) = self.before_call() {
            return Err(e);
        }
        let bytes = self.server.download(url, from).map_err(to_proto)?;
        let truncate_rate = self.inner.lock().unwrap().faults.truncate_download;
        let truncate = self.roll(truncate_rate);
        let slice = if truncate && bytes.len() > 1 {
            self.inner.lock().unwrap().stats.truncated_downloads += 1;
            &bytes[..bytes.len() / 2]
        } else {
            &bytes[..]
        };
        out.write_all(slice)?;
        Ok(slice.len() as u64)
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::server::sha256_hex;
    use serde_json::json;

    fn net(faults: NetFaults) -> SimNet {
        let clock = SimClock::new();
        SimNet::new(MockServer::new(clock.clone()), clock, 1, "laptop").with_faults(faults)
    }

    #[test]
    fn a_clean_network_passes_calls_straight_through() {
        let n = net(NetFaults::none());
        let out = n
            .action("drive_folder_create", json!({ "name": "Docs" }))
            .unwrap();
        assert_eq!(out["folder"]["name"], json!("Docs"));
    }

    #[test]
    fn a_lost_answer_still_did_the_work() {
        // The whole reason idempotency keys exist. The client sees a transport
        // error and cannot tell it apart from "never arrived" — but the folder
        // is on the server.
        let n = net(NetFaults {
            drop_after: 1000,
            ..NetFaults::none()
        });
        let err = n
            .action("drive_folder_create", json!({ "name": "Docs" }))
            .unwrap_err();
        assert!(matches!(err, ProtoError::Transport(_)));
        assert_eq!(
            n.server().live_counts(),
            (1, 0),
            "the server did the work anyway"
        );
    }

    #[test]
    fn retrying_a_lost_answer_under_the_same_key_does_not_do_it_twice() {
        // And this is why that is survivable.
        let n = net(NetFaults {
            drop_after: 1000,
            ..NetFaults::none()
        });
        let key = "op-42";
        assert!(n
            .action_idempotent("drive_folder_create", json!({ "name": "Docs" }), key)
            .is_err());
        n.set_faults(NetFaults::none());
        let second = n.action_idempotent("drive_folder_create", json!({ "name": "Docs" }), key);
        assert!(second.is_ok());
        assert_eq!(
            n.server().live_counts(),
            (1, 0),
            "one folder, however many times the answer was lost"
        );
    }

    #[test]
    fn retrying_without_a_key_does_the_work_again() {
        // The failure mode the keys prevent, demonstrated so the test above
        // means something.
        let n = net(NetFaults {
            drop_after: 1000,
            ..NetFaults::none()
        });
        assert!(n
            .action("drive_folder_create", json!({ "name": "Docs" }))
            .is_err());
        n.set_faults(NetFaults::none());
        // A different name on the retry, because the server refuses a duplicate
        // and would otherwise hide the very thing being demonstrated behind a
        // name collision. What is under test is that the work happened twice,
        // not what it was called.
        n.action("drive_folder_create", json!({ "name": "Docs (retry)" }))
            .unwrap();
        assert_eq!(n.server().live_counts(), (2, 0), "two folders — the bug");
    }

    #[test]
    fn a_dropped_request_never_reaches_the_server() {
        let n = net(NetFaults {
            drop_before: 1000,
            ..NetFaults::none()
        });
        assert!(n
            .action("drive_folder_create", json!({ "name": "Docs" }))
            .is_err());
        assert!(n.server().tree().is_empty());
    }

    #[test]
    fn a_server_error_is_reported_with_its_status_so_it_can_be_retried() {
        let n = net(NetFaults {
            server_5xx: 1000,
            ..NetFaults::none()
        });
        match n.action("drive_changes", json!({ "cursor": 0 })) {
            Err(ProtoError::Api { status, .. }) => assert_eq!(status, 503),
            other => panic!("expected a 503, got {other:?}"),
        }
    }

    #[test]
    fn rate_limiting_is_distinguishable_from_everything_else() {
        let n = net(NetFaults {
            rate_limit: 1000,
            ..NetFaults::none()
        });
        match n.action("drive_changes", json!({ "cursor": 0 })) {
            Err(ProtoError::Api {
                status, errortype, ..
            }) => {
                assert_eq!(status, 429);
                assert_eq!(errortype, "RateLimitError");
            }
            other => panic!("expected a 429, got {other:?}"),
        }
    }

    #[test]
    fn an_upload_over_a_clean_network_lands() {
        let n = net(NetFaults::none());
        let body = b"a body longer than one chunk";
        let params = UploadParams::plain("a.txt".into(), None, body.len() as u64, sha256_hex(body));
        let out = n
            .upload(&params, &mut std::io::Cursor::new(body.to_vec()))
            .unwrap();
        assert!(!out.deduped);
        assert_eq!(n.server().blob(&sha256_hex(body)).unwrap(), body);
    }

    #[test]
    fn a_corrupted_chunk_never_becomes_a_file() {
        // The bytes are wrong on the wire and the server has no way to know
        // until the end. What must not happen is that they land anyway.
        let n = net(NetFaults {
            corrupt_chunk: 1000,
            ..NetFaults::none()
        });
        let body = b"exactly six";
        let params = UploadParams::plain("a.txt".into(), None, body.len() as u64, sha256_hex(body));
        let out = n.upload(&params, &mut std::io::Cursor::new(body.to_vec()));
        assert!(out.is_err(), "corrupt bytes must be refused at completion");
        assert!(n.server().blob(&sha256_hex(body)).is_none());
        assert!(n.server().tree().is_empty());
    }

    #[test]
    fn an_upload_resumes_from_where_the_server_actually_is() {
        // Half the file went up, then the process gave up. A second attempt
        // realigns on the 409 instead of starting over.
        let clock = SimClock::new();
        let server = MockServer::new(clock.clone());
        let n = SimNet::new(server.clone(), clock, 7, "laptop");
        let body = b"0123456789";
        let sha = sha256_hex(body);

        let init = server
            .action(
                "drive_upload_init",
                &json!({ "name": "a.bin", "size_bytes": 10, "sha256": sha }),
            )
            .unwrap();
        let token = init["upload_token"].as_str().unwrap().to_string();
        server.upload_chunk(&token, 0, b"012").unwrap();

        // The engine comes back and pushes from zero; the server tells it where
        // it really is and the rest goes up from there.
        let mut offset = 0u64;
        loop {
            let end = ((offset + 3) as usize).min(body.len());
            match server.upload_chunk(&token, offset, &body[offset as usize..end]) {
                Ok(v) => offset = v["received_bytes"].as_u64().unwrap(),
                Err(r) => offset = r.data["received_bytes"].as_u64().unwrap(),
            }
            if offset as usize >= body.len() {
                break;
            }
        }
        server
            .action("drive_upload_complete", &json!({ "upload_token": token }))
            .unwrap();
        assert_eq!(server.blob(&sha).unwrap(), body);
        let _ = n;
    }

    #[test]
    fn a_truncated_download_reports_fewer_bytes_than_the_file_has() {
        // The caller has to notice. A short read written into a spool and
        // committed would be silent corruption, which is the worst kind.
        let clock = SimClock::new();
        let server = MockServer::new(clock.clone());
        let id = server.seed_file(None, "a.txt", b"0123456789");
        let n = SimNet::new(server.clone(), clock, 3, "laptop").with_faults(NetFaults {
            truncate_download: 1000,
            ..NetFaults::none()
        });
        let stat = server
            .action(
                "drive_stat",
                &json!({ "entities": [{ "entity_type": "file", "entity_id": id }], "urls": true }),
            )
            .unwrap();
        let url = stat["items"][0]["download_url"].as_str().unwrap();
        let mut sink = Vec::new();
        let written = n.download(url, 0, &mut sink).unwrap();
        assert!(written < 10, "the caller must be able to see it fell short");
        assert_eq!(written as usize, sink.len());
    }

    #[test]
    fn latency_moves_the_clock_so_expiries_are_reachable() {
        let clock = SimClock::new();
        let n = SimNet::new(MockServer::new(clock.clone()), clock.clone(), 1, "laptop")
            .with_faults(NetFaults {
                latency_ms: 250,
                ..NetFaults::none()
            });
        let before = clock.now_ms();
        let _ = n.action("drive_changes", json!({ "cursor": 0 }));
        assert_eq!(clock.now_ms(), before + 250);
    }

    #[test]
    fn the_same_seed_produces_the_same_faults() {
        // Without this a failing chaos run is an anecdote.
        let run = |seed: u64| {
            let clock = SimClock::new();
            let n = SimNet::new(MockServer::new(clock.clone()), clock, seed, "laptop")
                .with_faults(NetFaults::chaos());
            let mut outcomes = Vec::new();
            for i in 0..60 {
                outcomes.push(
                    n.action("drive_folder_create", json!({ "name": format!("f{i}") }))
                        .is_ok(),
                );
            }
            outcomes
        };
        assert_eq!(run(99), run(99));
        assert_ne!(run(99), run(100));
    }
}
