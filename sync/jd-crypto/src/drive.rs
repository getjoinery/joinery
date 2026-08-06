//! DriveCrypto parity: per-file content keys, the chunked authenticated
//! content container, the encrypted metadata blob, the encrypted thumbnail,
//! and file-key sealing to a vault public key (FileKeyGrants).
//!
//! Ciphertext container (self-delimiting; decryption needs no size metadata):
//!   repeated per plaintext chunk: uint32be(blockLen) || IV[12] || AES-GCM(ct+tag)
//!   blockLen = 12 + ciphertextLen, AAD = utf8(contentId + ":" + index).
//! The random per-file FK plus the per-chunk AAD mean a chunk can neither be
//! reordered within a file nor transplanted into another.

use std::io::{Read, Write};

use aes_gcm::aead::{Aead, Payload};
use aes_gcm::Nonce;
use rand_core::{CryptoRng, OsRng, RngCore};
use serde::{Deserialize, Serialize};
use zeroize::{Zeroize, ZeroizeOnDrop};

use crate::vault::{self, aes};
use crate::{CryptoError, Result};

/// Plaintext chunk size — matches `DriveCrypto.CHUNK_BYTES` (4 MiB) and the
/// server's `encrypted_size_ceiling` (32 bytes overhead per chunk).
pub const CHUNK_BYTES: usize = 4 * 1024 * 1024;

/// Per-chunk ciphertext overhead: IV (12) + GCM tag (16). The 4-byte length
/// prefix sits outside the block.
pub const CHUNK_OVERHEAD: usize = 12 + 16;

/// A per-file AES-256 content key. Sealed to each reader's vault public key
/// (a FileKeyGrant); never stored in the clear.
#[derive(Clone, Zeroize, ZeroizeOnDrop)]
pub struct FileKey(pub [u8; 32]);

impl FileKey {
    pub fn generate() -> FileKey {
        let mut rng = OsRng;
        let mut fk = [0u8; 32];
        rng.fill_bytes(&mut fk);
        FileKey(fk)
    }

    pub fn from_bytes(bytes: &[u8]) -> Result<FileKey> {
        bytes
            .try_into()
            .map(FileKey)
            .map_err(|_| CryptoError::Malformed("file key must be 32 bytes"))
    }
}

/// A fresh content id: 32 lowercase hex chars (16 random bytes), bound into
/// every chunk's AAD.
pub fn new_content_id() -> String {
    new_content_id_with_rng(OsRng)
}

pub fn new_content_id_with_rng<R: RngCore + CryptoRng>(mut rng: R) -> String {
    let mut raw = [0u8; 16];
    rng.fill_bytes(&mut raw);
    raw.iter().map(|byte| format!("{byte:02x}")).collect()
}

fn chunk_aad(content_id: &str, index: u64) -> Vec<u8> {
    format!("{content_id}:{index}").into_bytes()
}

/// The plaintext metadata record encrypted into the per-file metadata blob.
/// `mtime` (ISO-8601 UTC) is the additive field this client writes — readers
/// are version-tolerant, so unknown fields on either side are ignored.
#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
pub struct FileMetadata {
    pub v: u32,
    pub name: String,
    pub mime: String,
    pub size: u64,
    pub cid: String,
    pub chunk: u32,
    #[serde(default)]
    pub thumb: bool,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub mtime: Option<String>,
}

impl FileMetadata {
    pub fn new(name: &str, mime: &str, size: u64, content_id: &str) -> FileMetadata {
        FileMetadata {
            v: 1,
            name: name.to_string(),
            mime: if mime.is_empty() {
                "application/octet-stream"
            } else {
                mime
            }
            .to_string(),
            size,
            cid: content_id.to_string(),
            chunk: CHUNK_BYTES as u32,
            thumb: false,
            mtime: None,
        }
    }
}

// ---- content ---------------------------------------------------------------

/// Streaming encryptor: feed plaintext in any increments via `Write`, then
/// `finish()`. Chunking to 4 MiB happens internally; the empty-input case
/// emits the mandatory single empty chunk. The engine's spool path writes
/// through this so a large file never fully materializes in memory.
pub struct ContentEncryptor<W: Write, R: RngCore + CryptoRng> {
    out: W,
    rng: R,
    cipher: aes_gcm::Aes256Gcm,
    content_id: String,
    buf: Vec<u8>,
    index: u64,
    wrote_any: bool,
}

impl<W: Write> ContentEncryptor<W, OsRng> {
    pub fn new(out: W, fk: &FileKey, content_id: &str) -> Self {
        Self::with_rng(out, fk, content_id, OsRng)
    }
}

impl<W: Write, R: RngCore + CryptoRng> ContentEncryptor<W, R> {
    pub fn with_rng(out: W, fk: &FileKey, content_id: &str, rng: R) -> Self {
        ContentEncryptor {
            out,
            rng,
            cipher: aes(&fk.0),
            content_id: content_id.to_string(),
            buf: Vec::with_capacity(CHUNK_BYTES),
            index: 0,
            wrote_any: false,
        }
    }

    fn emit_chunk(&mut self, plain: &[u8]) -> std::io::Result<()> {
        let mut iv = [0u8; 12];
        self.rng.fill_bytes(&mut iv);
        let aad = chunk_aad(&self.content_id, self.index);
        let ct = self
            .cipher
            .encrypt(
                Nonce::from_slice(&iv),
                Payload {
                    msg: plain,
                    aad: &aad,
                },
            )
            .map_err(|_| std::io::Error::other("AES-GCM encryption failed"))?;
        let block_len = (12 + ct.len()) as u32;
        self.out.write_all(&block_len.to_be_bytes())?;
        self.out.write_all(&iv)?;
        self.out.write_all(&ct)?;
        self.index += 1;
        self.wrote_any = true;
        Ok(())
    }

    fn drain_full_chunks(&mut self) -> std::io::Result<()> {
        while self.buf.len() >= CHUNK_BYTES {
            let rest = self.buf.split_off(CHUNK_BYTES);
            let chunk = std::mem::replace(&mut self.buf, rest);
            self.emit_chunk(&chunk)?;
        }
        Ok(())
    }

    /// Encrypt everything buffered and return the inner writer. Must be
    /// called; dropping without it truncates the container.
    pub fn finish(mut self) -> std::io::Result<W> {
        if !self.buf.is_empty() || !self.wrote_any {
            let chunk = std::mem::take(&mut self.buf);
            self.emit_chunk(&chunk)?; // a 0-byte file is exactly one empty chunk
        }
        self.out.flush()?;
        Ok(self.out)
    }
}

impl<W: Write, R: RngCore + CryptoRng> Write for ContentEncryptor<W, R> {
    fn write(&mut self, data: &[u8]) -> std::io::Result<usize> {
        self.buf.extend_from_slice(data);
        self.drain_full_chunks()?;
        Ok(data.len())
    }

    fn flush(&mut self) -> std::io::Result<()> {
        Ok(())
    }
}

/// Streaming decryptor: reads the self-delimiting container from `input`,
/// AEAD-verifies every chunk against `contentId:index`, writes plaintext to
/// `output`. Returns total plaintext bytes. Any tamper/reorder/transplant
/// fails the GCM tag — nothing unverified is ever emitted for a bad chunk.
pub fn decrypt_content_stream<I: Read, O: Write>(
    mut input: I,
    output: &mut O,
    fk: &FileKey,
    content_id: &str,
) -> Result<u64> {
    let cipher = aes(&fk.0);
    let mut total: u64 = 0;
    let mut index: u64 = 0;
    let mut len_buf = [0u8; 4];
    loop {
        // read the 4-byte block length, tolerating clean EOF between blocks
        match read_exact_or_eof(&mut input, &mut len_buf)? {
            false if index == 0 => {
                return Err(CryptoError::Malformed("empty ciphertext container"))
            }
            false => break,
            true => {}
        }
        let block_len = u32::from_be_bytes(len_buf) as usize;
        if !(CHUNK_OVERHEAD..=CHUNK_BYTES + CHUNK_OVERHEAD).contains(&block_len) {
            return Err(CryptoError::Malformed("implausible chunk block length"));
        }
        let mut block = vec![0u8; block_len];
        if !read_exact_or_eof(&mut input, &mut block)? {
            return Err(CryptoError::Malformed("truncated chunk block"));
        }
        let aad = chunk_aad(content_id, index);
        let pt = cipher
            .decrypt(
                Nonce::from_slice(&block[..12]),
                Payload {
                    msg: &block[12..],
                    aad: &aad,
                },
            )
            .map_err(|_| CryptoError::DecryptFailed)?;
        output
            .write_all(&pt)
            .map_err(|_| CryptoError::Malformed("plaintext sink write failed"))?;
        total += pt.len() as u64;
        index += 1;
    }
    Ok(total)
}

fn read_exact_or_eof<I: Read>(input: &mut I, buf: &mut [u8]) -> Result<bool> {
    let mut filled = 0;
    while filled < buf.len() {
        match input.read(&mut buf[filled..]) {
            Ok(0) if filled == 0 => return Ok(false),
            Ok(0) => return Err(CryptoError::Malformed("truncated ciphertext container")),
            Ok(n) => filled += n,
            Err(e) if e.kind() == std::io::ErrorKind::Interrupted => continue,
            Err(_) => return Err(CryptoError::Malformed("ciphertext source read failed")),
        }
    }
    Ok(true)
}

/// Streaming decryptor for callers that receive ciphertext in pushed
/// increments rather than pulling it from a reader — a download, where the
/// bytes arrive from the network and the caller owns no `Read` to hand over.
///
/// Same guarantees as [`decrypt_content_stream`]: every chunk is AEAD-verified
/// against `contentId:index` before any of its plaintext is written out, so a
/// tampered or transplanted chunk produces an error rather than partially
/// trusted bytes.
///
/// `finish()` is not optional. A container that ends mid-block is a truncated
/// download, and the only place that can be noticed is at the end.
pub struct ContentDecryptor<W: Write> {
    out: W,
    cipher: aes_gcm::Aes256Gcm,
    content_id: String,
    /// Ciphertext not yet forming a complete block.
    buf: Vec<u8>,
    index: u64,
    plain_bytes: u64,
}

impl<W: Write> ContentDecryptor<W> {
    pub fn new(out: W, fk: &FileKey, content_id: &str) -> Self {
        ContentDecryptor {
            out,
            cipher: aes(&fk.0),
            content_id: content_id.to_string(),
            buf: Vec::with_capacity(CHUNK_BYTES + CHUNK_OVERHEAD + 4),
            index: 0,
            plain_bytes: 0,
        }
    }

    /// Plaintext bytes written so far.
    pub fn plain_bytes(&self) -> u64 {
        self.plain_bytes
    }

    /// Decrypt every complete block currently buffered.
    fn drain_blocks(&mut self) -> Result<()> {
        loop {
            if self.buf.len() < 4 {
                return Ok(());
            }
            let block_len =
                u32::from_be_bytes([self.buf[0], self.buf[1], self.buf[2], self.buf[3]]) as usize;
            if !(CHUNK_OVERHEAD..=CHUNK_BYTES + CHUNK_OVERHEAD).contains(&block_len) {
                // Checked before allocating: a corrupted length prefix would
                // otherwise ask for an arbitrary amount of memory.
                return Err(CryptoError::Malformed("implausible chunk block length"));
            }
            if self.buf.len() < 4 + block_len {
                return Ok(());
            }
            let rest = self.buf.split_off(4 + block_len);
            let block = std::mem::replace(&mut self.buf, rest);
            let aad = chunk_aad(&self.content_id, self.index);
            let pt = self
                .cipher
                .decrypt(
                    Nonce::from_slice(&block[4..16]),
                    Payload {
                        msg: &block[16..],
                        aad: &aad,
                    },
                )
                .map_err(|_| CryptoError::DecryptFailed)?;
            self.out
                .write_all(&pt)
                .map_err(|_| CryptoError::Malformed("plaintext sink write failed"))?;
            self.plain_bytes += pt.len() as u64;
            self.index += 1;
        }
    }

    /// Push ciphertext. Errors the moment a complete block fails to verify.
    pub fn push(&mut self, ciphertext: &[u8]) -> Result<()> {
        self.buf.extend_from_slice(ciphertext);
        self.drain_blocks()
    }

    /// Assert the container ended where a container may end, and return the
    /// sink. A leftover partial block is a truncated transfer.
    pub fn finish(mut self) -> Result<(W, u64)> {
        if !self.buf.is_empty() {
            return Err(CryptoError::Malformed("truncated ciphertext container"));
        }
        if self.index == 0 {
            return Err(CryptoError::Malformed("empty ciphertext container"));
        }
        self.out
            .flush()
            .map_err(|_| CryptoError::Malformed("plaintext sink write failed"))?;
        Ok((self.out, self.plain_bytes))
    }
}

/// Whole-buffer convenience over the streaming encryptor.
pub fn encrypt_content(plain: &[u8], fk: &FileKey, content_id: &str) -> Vec<u8> {
    encrypt_content_with_rng(plain, fk, content_id, OsRng)
}

pub fn encrypt_content_with_rng<R: RngCore + CryptoRng>(
    plain: &[u8],
    fk: &FileKey,
    content_id: &str,
    rng: R,
) -> Vec<u8> {
    let mut enc = ContentEncryptor::with_rng(Vec::new(), fk, content_id, rng);
    enc.write_all(plain).expect("Vec sink cannot fail");
    enc.finish().expect("Vec sink cannot fail")
}

/// Whole-buffer convenience over the streaming decryptor.
pub fn decrypt_content(cipher: &[u8], fk: &FileKey, content_id: &str) -> Result<Vec<u8>> {
    let mut out = Vec::new();
    decrypt_content_stream(cipher, &mut out, fk, content_id)?;
    Ok(out)
}

// ---- metadata ---------------------------------------------------------------

/// The metadata blob rides VaultCrypto's plain content contract (IV||ct, no
/// AAD): the per-file-unique FK already binds it to this file.
pub fn encrypt_metadata(meta: &FileMetadata, fk: &FileKey) -> Result<String> {
    vault::encrypt_string(&serde_json::to_string(meta)?, &fk.0)
}

pub fn decrypt_metadata(blob: &str, fk: &FileKey) -> Result<FileMetadata> {
    Ok(serde_json::from_str(&vault::decrypt_string(blob, &fk.0)?)?)
}

// ---- thumbnail --------------------------------------------------------------

/// Raw `IV[12] || ct` bytes (the server stores decoded bytes; base64 never
/// appears on the read path), AAD = contentId + ":thumb".
pub fn encrypt_thumbnail(plain: &[u8], fk: &FileKey, content_id: &str) -> Vec<u8> {
    encrypt_thumbnail_with_rng(plain, fk, content_id, OsRng)
}

pub fn encrypt_thumbnail_with_rng<R: RngCore + CryptoRng>(
    plain: &[u8],
    fk: &FileKey,
    content_id: &str,
    mut rng: R,
) -> Vec<u8> {
    let cipher = aes(&fk.0);
    let mut iv = [0u8; 12];
    rng.fill_bytes(&mut iv);
    let aad = format!("{content_id}:thumb");
    let ct = cipher
        .encrypt(
            Nonce::from_slice(&iv),
            Payload {
                msg: plain,
                aad: aad.as_bytes(),
            },
        )
        .expect("AES-GCM encryption cannot fail on in-memory input");
    let mut out = Vec::with_capacity(12 + ct.len());
    out.extend_from_slice(&iv);
    out.extend_from_slice(&ct);
    out
}

pub fn decrypt_thumbnail(bytes: &[u8], fk: &FileKey, content_id: &str) -> Result<Vec<u8>> {
    if bytes.len() < 12 + 16 {
        return Err(CryptoError::Malformed("thumbnail ciphertext too short"));
    }
    let cipher = aes(&fk.0);
    let aad = format!("{content_id}:thumb");
    cipher
        .decrypt(
            Nonce::from_slice(&bytes[..12]),
            Payload {
                msg: &bytes[12..],
                aad: aad.as_bytes(),
            },
        )
        .map_err(|_| CryptoError::DecryptFailed)
}

// ---- sharing ----------------------------------------------------------------

/// Seal a file key to a vault public key — a FileKeyGrant's `wrapped_file_key`.
pub fn wrap_file_key_to(fk: &FileKey, recipient_public_key_b64: &str) -> Result<String> {
    vault::seal_to_public_key(&fk.0, recipient_public_key_b64)
}

/// Open a wrapped file key with the vault secret key (PKCS8).
pub fn open_wrapped_file_key(
    wrapped_b64: &str,
    secret_key_pkcs8: &[u8],
    vault_public_key_b64: &str,
) -> Result<FileKey> {
    let bytes = vault::open_from_secret_key(wrapped_b64, secret_key_pkcs8, vault_public_key_b64)?;
    FileKey::from_bytes(&bytes)
}

/// The exact ciphertext size for a given plaintext size — must agree with the
/// server's `DriveHelper::encrypted_size_ceiling`.
pub fn encrypted_size(plain_size: u64) -> u64 {
    let chunks = if plain_size == 0 {
        1
    } else {
        plain_size.div_ceil(CHUNK_BYTES as u64)
    };
    plain_size + chunks * (4 + CHUNK_OVERHEAD) as u64
}

// b64 is re-exported for callers assembling wire payloads next to these blobs.
pub use crate::b64::{decode as b64_decode, encode as b64_encode};
