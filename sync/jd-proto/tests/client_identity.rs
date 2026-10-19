//! What the client calls itself on the wire, and what it does when the server's
//! upgrade gate turns it away (docs/api.md § Client Versioning).
//!
//! The gate keys its minimums by `client-app`, so the identifier is the handle an
//! instance has for holding back one platform's release. A Mac that introduced
//! itself as `joinery-sync-linux` could only be stopped by stopping every Linux
//! machine with it, so the identifier is decided by the compile target.

mod common;

use std::io::Write;
use std::net::TcpListener;

/// Accept one request, hand its raw head back, and refuse it the way the
/// server's gate does for a client below its minimum.
fn a_gate_that_refuses_and_reports_the_request() -> (String, std::sync::mpsc::Receiver<String>) {
    let listener = TcpListener::bind("127.0.0.1:0").expect("bind a loopback port");
    let addr = listener.local_addr().expect("read back the bound port");
    let (tx, rx) = std::sync::mpsc::channel();

    std::thread::spawn(move || {
        let Ok((mut sock, _)) = listener.accept() else {
            return;
        };
        let _ = tx.send(common::read_request(&mut sock));

        // The envelope api/apiv1.php's api_error() writes for the gate.
        let body = r#"{"api_version":"1.0","errortype":"UpgradeRequired","error":"A newer version of this app is required.","data":{}}"#;
        let _ = write!(
            sock,
            "HTTP/1.1 426 Upgrade Required\r\nContent-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{}",
            body.len(),
            body
        );
    });

    (format!("http://{addr}"), rx)
}

/// The value of one header in a raw request head. Header names are
/// case-insensitive on the wire, so the comparison is too.
fn header<'a>(head: &'a str, name: &str) -> Option<&'a str> {
    head.lines().find_map(|line| {
        let (key, value) = line.split_once(':')?;
        key.trim().eq_ignore_ascii_case(name).then(|| value.trim())
    })
}

#[test]
fn the_client_names_the_operating_system_it_was_built_for() {
    // `std::env::consts::OS` is decided by the same compile target, by the
    // standard library rather than by this crate, so it is an independent
    // witness: "macos", "windows", "linux".
    let os = std::env::consts::OS;
    if ["macos", "windows", "linux"].contains(&os) {
        assert_eq!(jd_proto::CLIENT_APP, format!("joinery-sync-{os}"));
    } else {
        // Anything else runs the Linux build of the client.
        assert_eq!(jd_proto::CLIENT_APP, "joinery-sync-linux");
    }
}

#[test]
fn the_version_is_one_the_gate_can_order() {
    // The gate compares with PHP's version_compare(). MAJOR.MINOR.PATCH with an
    // optional alpha/beta/rc pre-release orders correctly there; build metadata
    // (`+…`) or a free-form suffix does not, and a minimum set against such a
    // version would lock out or let through the wrong builds.
    let v = jd_proto::CLIENT_VERSION;
    let (release, pre) = match v.split_once('-') {
        Some((r, p)) => (r, Some(p)),
        None => (v, None),
    };
    let parts: Vec<&str> = release.split('.').collect();
    assert_eq!(parts.len(), 3, "{v} is not MAJOR.MINOR.PATCH");
    assert!(
        parts
            .iter()
            .all(|p| !p.is_empty() && p.bytes().all(|b| b.is_ascii_digit())),
        "{v} is not MAJOR.MINOR.PATCH"
    );
    if let Some(pre) = pre {
        let (word, n) = pre.split_once('.').unwrap_or((pre, "0"));
        assert!(
            ["alpha", "beta", "rc"].contains(&word) && n.bytes().all(|b| b.is_ascii_digit()),
            "{v}: a pre-release is -alpha.N, -beta.N or -rc.N"
        );
    }
}

#[test]
fn every_request_carries_the_identity_and_a_refusal_surfaces_as_upgrade_required() {
    let (base, heads) = a_gate_that_refuses_and_reports_the_request();
    let mut client = jd_proto::Client::new(&base);

    let outcome = client.login("nobody@example.com", "irrelevant", "identity test");

    let head = heads.recv().expect("the request reached the gate");
    assert_eq!(header(&head, "client-app"), Some(jd_proto::CLIENT_APP));
    assert_eq!(
        header(&head, "client-version"),
        Some(jd_proto::CLIENT_VERSION)
    );

    match outcome {
        Err(jd_proto::ProtoError::Api {
            status, errortype, ..
        }) => {
            assert_eq!(status, 426);
            assert_eq!(errortype, "UpgradeRequired");
        }
        other => panic!("a 426 from the gate must surface as UpgradeRequired, got {other:?}"),
    }
}
