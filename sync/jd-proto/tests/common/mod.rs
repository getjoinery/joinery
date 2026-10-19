//! What the loopback servers in these tests share.

use std::io::Read;
use std::net::TcpStream;

/// Read one whole request, its head and the body its Content-Length names,
/// and hand the head back.
///
/// A server that answers and hangs up with part of the request still unread
/// resets the connection rather than closing it, which happens whenever the
/// body arrives after the head. The client sees the reset, and on macOS the
/// answer it had already received is lost with it, so a test server reads all
/// of what it was sent, as a real one does.
pub fn read_request(sock: &mut TcpStream) -> String {
    let mut got = Vec::new();
    let mut buf = [0u8; 4096];
    let head_end = loop {
        if let Some(at) = got.windows(4).position(|w| w == b"\r\n\r\n") {
            break at + 4;
        }
        match sock.read(&mut buf) {
            Ok(0) | Err(_) => return String::from_utf8_lossy(&got).to_string(),
            Ok(n) => got.extend_from_slice(&buf[..n]),
        }
    };
    let head = String::from_utf8_lossy(&got[..head_end]).to_string();

    let body_len = head
        .lines()
        .find_map(|line| {
            let (key, value) = line.split_once(':')?;
            key.trim()
                .eq_ignore_ascii_case("content-length")
                .then(|| value.trim().parse::<usize>().ok())?
        })
        .unwrap_or(0);
    while got.len() < head_end + body_len {
        match sock.read(&mut buf) {
            Ok(0) | Err(_) => break,
            Ok(n) => got.extend_from_slice(&buf[..n]),
        }
    }

    head
}
