//! What the client does when the server stops talking without hanging up.
//!
//! A refused connection is easy — it comes back as an error immediately. The
//! dangerous shape is traffic that is dropped rather than refused: the socket
//! stays open, the request goes out, and the reply never arrives. That is what
//! a laptop leaving wifi looks like from the client side, and it is what the
//! soak rig's partition fault injects.
//!
//! Run 44 is why this file exists. A single 66-second partition left device-a
//! blocked on a read with no timeout; the daemon stayed up for three and a half
//! hours afterwards, burned twelve seconds of CPU, planned twenty-three
//! operations and executed none of them. Nothing crashed and nothing was
//! reported — it simply stopped syncing and looked healthy doing it.

mod common;

use std::io::{Read, Write};
use std::net::TcpListener;
use std::time::{Duration, Instant};

/// Accept the connection, read the request, then answer nothing at all.
fn a_server_that_never_answers() -> std::net::SocketAddr {
    let listener = TcpListener::bind("127.0.0.1:0").expect("bind a loopback port");
    let addr = listener.local_addr().expect("read back the bound port");

    std::thread::spawn(move || {
        while let Ok((mut sock, _)) = listener.accept() {
            let mut buf = [0u8; 4096];
            let _ = sock.read(&mut buf);
            // Hold the socket open, say nothing, and let the client decide.
            std::thread::sleep(Duration::from_secs(120));
        }
    });

    addr
}

#[test]
fn a_reply_that_never_comes_does_not_block_a_worker_forever() {
    let addr = a_server_that_never_answers();
    let silence_limit = Duration::from_secs(2);
    let mut client =
        jd_proto::Client::with_silence_limit(&format!("http://{addr}"), silence_limit);

    let started = Instant::now();
    let outcome = client.login("nobody@example.com", "irrelevant", "silence test");
    let waited = started.elapsed();

    assert!(
        outcome.is_err(),
        "a server that answered nothing must not read as a success"
    );

    // Generous next to a 2s limit, and still far below the forever this used to
    // take: the point is that the client gives up on its own, not that it gives
    // up at any exact moment.
    assert!(
        waited < Duration::from_secs(30),
        "the client waited {waited:?} for a reply that was never coming"
    );
}

/// The limit is idle, not total: a connection that keeps producing bytes must
/// be allowed to take as long as it needs. Without this the fix above would
/// trade a hang for a large file that can never finish downloading.
#[test]
fn a_slow_but_talking_server_is_not_cut_off() {
    let listener = TcpListener::bind("127.0.0.1:0").expect("bind a loopback port");
    let addr = listener.local_addr().expect("read back the bound port");

    let body_len = 8usize;
    std::thread::spawn(move || {
        if let Ok((mut sock, _)) = listener.accept() {
            common::read_request(&mut sock);

            use std::io::Write;
            let head = format!("HTTP/1.1 200 OK\r\nContent-Length: {body_len}\r\n\r\n");
            let _ = sock.write_all(head.as_bytes());
            let _ = sock.flush();

            // Dribble the body out one byte at a time, pausing longer in total
            // than the silence limit but never once falling silent for that
            // long. An idle timeout rides this out; a deadline would not.
            for _ in 0..body_len {
                std::thread::sleep(Duration::from_millis(600));
                let _ = sock.write_all(b"x");
                let _ = sock.flush();
            }
        }
    });

    let mut out = Vec::new();
    let client =
        jd_proto::Client::with_silence_limit(&format!("http://{addr}"), Duration::from_secs(2));
    let written = client
        .download_to(&format!("http://{addr}/slow"), &mut out)
        .expect("a server that keeps talking must not be abandoned");

    assert_eq!(written, body_len as u64, "the whole slow body should arrive");
    assert_eq!(out, b"xxxxxxxx", "and arrive intact");
}

/// Answer the first `answers` requests and keep each connection open, the way
/// any server does; then read every later request and answer nothing, on any
/// connection.
fn a_server_that_answers_then_falls_silent(answers: usize) -> std::net::SocketAddr {
    let listener = TcpListener::bind("127.0.0.1:0").expect("bind a loopback port");
    let addr = listener.local_addr().expect("read back the bound port");
    let answered = std::sync::Arc::new(std::sync::atomic::AtomicUsize::new(0));

    std::thread::spawn(move || {
        while let Ok((mut sock, _)) = listener.accept() {
            let answered = answered.clone();
            std::thread::spawn(move || loop {
                common::read_request(&mut sock);
                if answered.fetch_add(1, std::sync::atomic::Ordering::SeqCst) >= answers {
                    std::thread::sleep(Duration::from_secs(120));
                    return;
                }
                let body = br#"{"data":{"public_key":"p","secret_key":"s","received_bytes":4,"expected_bytes":4}}"#;
                let head = format!(
                    "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: {}\r\n\r\n",
                    body.len()
                );
                let _ = sock.write_all(head.as_bytes());
                let _ = sock.write_all(body);
            });
        }
    });

    addr
}

/// Sign in, make one call the server answers, make it again with the server now
/// silent, and fail if that is still waiting long after a 2 s silence limit.
fn gives_up_on_the_second_of<F>(call: F)
where
    F: Fn(&mut jd_proto::Client) -> bool + Send + 'static,
{
    let addr = a_server_that_answers_then_falls_silent(2);
    let (done, waited) = std::sync::mpsc::channel();

    std::thread::spawn(move || {
        let mut client =
            jd_proto::Client::with_silence_limit(&format!("http://{addr}"), Duration::from_secs(2));
        client
            .login("nobody@example.com", "irrelevant", "silence test")
            .expect("the sign-in is answered");
        assert!(!call(&mut client), "the first call is answered");
        let _ = done.send(call(&mut client));
    });

    match waited.recv_timeout(Duration::from_secs(30)) {
        Ok(failed) => assert!(failed, "a reply that never came must not read as a success"),
        Err(_) => panic!("the client was still waiting after 30 s, with a 2 s silence limit"),
    }
}

/// Most of what a busy daemon asks follows another request closely enough to
/// reuse its connection. A server that stops answering between two of them must
/// not stop this device syncing for good.
#[test]
fn a_server_falling_silent_between_two_calls_is_still_given_up_on() {
    gives_up_on_the_second_of(|client| {
        client.login("nobody@example.com", "irrelevant", "silence test").is_err()
    });
}

/// File bytes have no deadline, so the silence limit is all that stops them.
#[test]
fn a_server_falling_silent_between_two_chunks_is_still_given_up_on() {
    gives_up_on_the_second_of(|client| client.upload_chunk("t", 0, 4, b"data").is_err());
}

#[test]
fn a_server_falling_silent_between_two_downloads_is_still_given_up_on() {
    gives_up_on_the_second_of(|client| {
        let mut out = Vec::new();
        client.download_range_to("/d", 0, &mut out).is_err()
    });
}
