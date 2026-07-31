//! The network, as the engine is allowed to see it.
//!
//! `jd-core` never holds a [`Client`](crate::Client). It holds a [`DriveApi`],
//! which the real client implements and the simulator also implements. That is
//! what makes "the process died halfway through a chunk upload" an ordinary
//! test case instead of a story we tell ourselves about code we cannot run.
//!
//! The surface is deliberately narrow and deliberately *untyped* in the middle:
//! everything the server does is a named action carrying JSON, exactly as it
//! appears on the wire. A richly typed trait would be pleasanter to call and
//! would quietly let the mock drift away from the server, because the types
//! would describe what we believe rather than what is sent. Keeping the wire
//! shape at the boundary is what lets one scenario script run against the mock
//! and against a real deployment and have the difference mean something.

use std::io::{Read, Seek, Write};

use serde_json::Value;

use crate::{Client, Result, UploadOutcome, UploadParams};

/// A source of bytes the upload protocol can rewind — needed because a chunk
/// upload that is told to resume at an earlier offset has to go back and read
/// from there.
pub trait ReadSeek: Read + Seek {}
impl<T: Read + Seek + ?Sized> ReadSeek for T {}

/// Everything the engine may ask of a server.
pub trait DriveApi: Send + Sync {
    /// `POST /action/{name}` — the whole action surface, one method.
    fn action(&self, name: &str, body: Value) -> Result<Value>;

    /// The same, carrying an `Idempotency-Key`.
    ///
    /// Every mutating call the engine makes goes through here with a key it
    /// journaled *before* sending. That is the only reason a crash between
    /// "server committed" and "client recorded it" is survivable: the retry
    /// after restart is recognized as the same operation rather than performed
    /// a second time.
    fn action_idempotent(&self, name: &str, body: Value, key: &str) -> Result<Value>;

    /// Run the resumable upload protocol to completion.
    fn upload(&self, params: &UploadParams, reader: &mut dyn ReadSeek) -> Result<UploadOutcome>;

    /// Stream a signed download URL into `out`, starting at byte `from`.
    ///
    /// A non-zero `from` is a resumed download; a server that ignores the range
    /// and sends the whole file again is a correctness problem the caller must
    /// detect, so the returned count is bytes *written*, not bytes claimed.
    fn download(&self, url: &str, from: u64, out: &mut dyn Write) -> Result<u64>;
}

impl DriveApi for Client {
    fn action(&self, name: &str, body: Value) -> Result<Value> {
        Client::action(self, name, body)
    }

    fn action_idempotent(&self, name: &str, body: Value, key: &str) -> Result<Value> {
        Client::action_idempotent(self, name, body, key)
    }

    fn upload(&self, params: &UploadParams, reader: &mut dyn ReadSeek) -> Result<UploadOutcome> {
        Client::upload_from_reader(self, params, reader)
    }

    fn download(&self, url: &str, from: u64, out: &mut dyn Write) -> Result<u64> {
        Client::download_range_to(self, url, from, out)
    }
}
