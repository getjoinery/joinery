//! The settings page, compiled into the binary.
//!
//! Three files of plain HTML, CSS and JavaScript, served by the control channel
//! at `/settings` and opened in the default browser by the tray. No framework
//! and nothing fetched from anywhere else: the page is a window onto the
//! daemon's control requests and nothing more, so everything it can do is a
//! line in `daemon::ROUTES`, and everything it shows comes from `/status` and
//! `/folders`.
//!
//! The files themselves hold no data and are served without a credential. How
//! the page then gets one — a ticket in the URL's fragment traded for a session
//! held in a header — is the control channel's business, written down in
//! `jd_platform::control`.

use jd_platform::control::Page;

pub static PAGES: &[Page] = &[
    Page {
        path: jd_platform::control::PAGE_PATH,
        content_type: "text/html; charset=utf-8",
        body: include_str!("settings/index.html"),
    },
    Page {
        path: "/settings/app.css",
        content_type: "text/css; charset=utf-8",
        body: include_str!("settings/app.css"),
    },
    Page {
        path: "/settings/app.js",
        content_type: "text/javascript; charset=utf-8",
        body: include_str!("settings/app.js"),
    },
];

#[cfg(test)]
mod tests {
    //! The page and the daemon's routes, over real HTTP on 127.0.0.1: the
    //! refusals a local page has to make to be safe on a machine with other
    //! users and in a browser that visits other sites.

    use super::*;
    use crate::control::{ControlServer, Endpoint};
    use crate::daemon::{handle_control, Command, Shared, Snapshot};
    use crate::health::{Health, Indicator};
    use serde_json::Value;
    use std::io::{Read, Write};
    use std::net::TcpStream;
    use std::sync::mpsc::{channel, Receiver};
    use std::sync::Arc;
    use std::time::Duration;

    fn page(path: &str) -> &'static str {
        PAGES.iter().find(|p| p.path == path).unwrap().body
    }

    /// A daemon's control server with no sync thread behind it: a snapshot
    /// set by hand, and the command queue held by the test.
    fn daemon(tag: &str) -> (Endpoint, Receiver<Command>, std::path::PathBuf) {
        let dir = std::env::temp_dir().join(format!("jd-settings-{tag}-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&dir);
        std::fs::create_dir_all(&dir).unwrap();
        let path = dir.join("control.json");

        let (tx, rx) = channel();
        let shared = Arc::new(Shared::new());
        shared.attach(tx);
        *shared.snapshot.lock().unwrap() = Some(Snapshot {
            health: Health {
                indicator: Indicator::Attention,
                entries: [("synced".to_string(), 3usize)].into_iter().collect(),
                pending_ops: 0,
                issues: vec![crate::health::Issue {
                    id: 9,
                    kind: "unsyncable".into(),
                    detail: "NameTooLong".into(),
                    summary: "<img src=x onerror=alert(1)>.txt".into(),
                    created_at: 0,
                }],
                blocker: None,
                last_pass_ms: Some(1),
                cursor: 0,
            },
            paused: false,
            device_name: "Laptop".into(),
            base_url: "https://drive.example.org".into(),
            sync_root: "/home/u/Joinery Drive".into(),
            custody: "in a file".into(),
            vault: false,
        });

        let server = Arc::new(
            ControlServer::bind(&path, crate::control::new_token())
                .unwrap()
                .with_pages(PAGES),
        );
        let endpoint = Endpoint::load(&path).unwrap();
        std::thread::spawn(move || server.serve(move |req| handle_control(&shared, req)));
        (endpoint, rx, dir)
    }

    fn raw(port: u16, request: &str) -> (u16, String, String) {
        let mut stream = TcpStream::connect(("127.0.0.1", port)).unwrap();
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
        assert!(
            !head.to_ascii_lowercase().contains("access-control-allow"),
            "a CORS header would let another site read this:\n{head}"
        );
        (status, head.to_string(), body.to_string())
    }

    /// A request the way the page's own script makes one.
    fn from_page(port: u16, method: &str, path: &str, session: &str, body: &str) -> (u16, Value) {
        let (status, _, text) = raw(
            port,
            &format!(
                "{method} {path} HTTP/1.1\r\nHost: 127.0.0.1:{port}\r\nOrigin: http://127.0.0.1:{port}\r\n\
                 Sec-Fetch-Site: same-origin\r\nX-Joinery-Session: {session}\r\n\
                 Content-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{body}",
                body.len()
            ),
        );
        (status, serde_json::from_str(&text).unwrap_or(Value::Null))
    }

    fn session_for(endpoint: &Endpoint) -> String {
        let url = crate::control::settings_url(endpoint, Some("issues")).expect("a ticket");
        let ticket = url
            .split("#t=")
            .nth(1)
            .unwrap()
            .split('&')
            .next()
            .unwrap()
            .to_string();
        let port = endpoint.port;
        let (status, _, body) = raw(
            port,
            &format!(
                "POST /settings/session HTTP/1.1\r\nHost: 127.0.0.1:{port}\r\nOrigin: http://127.0.0.1:{port}\r\n\
                 X-Joinery-Ticket: {ticket}\r\nContent-Length: 0\r\nConnection: close\r\n\r\n"
            ),
        );
        assert_eq!(status, 200, "{body}");
        serde_json::from_str::<Value>(&body).unwrap()["session"]
            .as_str()
            .unwrap()
            .to_string()
    }

    #[test]
    fn the_page_and_its_files_are_served_from_the_binary() {
        let (endpoint, _rx, dir) = daemon("files");
        let port = endpoint.port;
        for (path, kind) in [
            ("/settings", "text/html"),
            ("/settings/app.css", "text/css"),
            ("/settings/app.js", "text/javascript"),
        ] {
            let (status, head, body) = raw(
                port,
                &format!(
                    "GET {path} HTTP/1.1\r\nHost: 127.0.0.1:{port}\r\nConnection: close\r\n\r\n"
                ),
            );
            assert_eq!(status, 200, "{path}");
            assert!(
                head.to_ascii_lowercase()
                    .contains(&format!("content-type: {kind}")),
                "{head}"
            );
            assert!(head.contains("frame-ancestors 'none'"));
            assert_eq!(body, page(path));
        }
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn the_page_drives_the_daemon_with_its_session_and_nothing_else() {
        let (endpoint, rx, dir) = daemon("drive");
        let port = endpoint.port;
        let session = session_for(&endpoint);

        let (status, body) = from_page(port, "GET", "/status", &session, "");
        assert_eq!(status, 200);
        assert_eq!(body["linked"], true);
        assert_eq!(body["device_name"], "Laptop");
        assert_eq!(body["issues"][0]["id"], 9);

        let (status, body) = from_page(port, "POST", "/pause", &session, "");
        assert_eq!(status, 200);
        assert_eq!(body["ok"], true);
        assert_eq!(
            rx.recv_timeout(Duration::from_secs(5)).unwrap(),
            Command::Pause
        );

        let (status, _) = from_page(port, "POST", "/dismiss", &session, r#"{"issue_id":9}"#);
        assert_eq!(status, 200);
        assert_eq!(
            rx.recv_timeout(Duration::from_secs(5)).unwrap(),
            Command::DismissIssue(9)
        );

        // What the page may not ask for, even holding a good session.
        let (status, _) = from_page(port, "POST", "/stop", &session, "");
        assert_eq!(status, 403);
        // A link or an image is a GET; nothing that changes anything is one.
        let (status, _) = from_page(port, "GET", "/pause", &session, "");
        assert_eq!(status, 405);
        assert!(rx.try_recv().is_err(), "neither reached the sync thread");
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn no_other_page_can_drive_the_daemon() {
        let (endpoint, rx, dir) = daemon("refuse");
        let port = endpoint.port;
        let session = session_for(&endpoint);

        // No credential: what any other user's process or any website gets.
        let (status, _, _) = raw(
            port,
            &format!("POST /pause HTTP/1.1\r\nHost: 127.0.0.1:{port}\r\nContent-Length: 0\r\nConnection: close\r\n\r\n"),
        );
        assert_eq!(status, 401);

        // A site that rebound its own name to 127.0.0.1.
        let (status, _, _) = raw(
            port,
            &format!(
                "POST /pause HTTP/1.1\r\nHost: drive-settings.attacker.example:{port}\r\nX-Joinery-Session: {session}\r\nContent-Length: 0\r\n\r\n"
            ),
        );
        assert_eq!(status, 403);

        // Another site's page holding the session (it cannot, but if it could).
        let (status, _, _) = raw(
            port,
            &format!(
                "POST /pause HTTP/1.1\r\nHost: 127.0.0.1:{port}\r\nOrigin: https://attacker.example\r\nX-Joinery-Session: {session}\r\nContent-Length: 0\r\n\r\n"
            ),
        );
        assert_eq!(status, 403);

        // The browser's own preflight for a cross-site request with a custom
        // header gets no permission to send it.
        let (status, _, _) = raw(
            port,
            &format!(
                "OPTIONS /pause HTTP/1.1\r\nHost: 127.0.0.1:{port}\r\nOrigin: https://attacker.example\r\nAccess-Control-Request-Method: POST\r\nAccess-Control-Request-Headers: x-joinery-session\r\n\r\n"
            ),
        );
        assert_ne!(status, 200);

        // Status leaks names of files; it is as closed as the controls are.
        let (status, _, body) = raw(
            port,
            &format!("GET /status HTTP/1.1\r\nHost: 127.0.0.1:{port}\r\nConnection: close\r\n\r\n"),
        );
        assert_eq!(status, 401);
        assert!(!body.contains("Laptop"));

        assert!(rx.try_recv().is_err(), "nothing reached the sync thread");
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn the_page_never_builds_markup_from_text() {
        // Issue summaries and folder names carry names another device chose.
        // Set as text they are inert; parsed as markup, one is a script.
        let js = page("/settings/app.js");
        for forbidden in [
            "innerHTML",
            "outerHTML",
            "insertAdjacentHTML",
            "document.write",
            "eval(",
            "new Function",
        ] {
            assert!(!js.contains(forbidden), "app.js uses {forbidden}");
        }
    }

    #[test]
    fn the_page_loads_nothing_from_anywhere_else() {
        let html = page("/settings");
        // Every src and href is the page's own, a data: URI, or a link the
        // script fills in (the approval page, which opens in a new tab).
        for attr in ["src=\"", "href=\""] {
            for (i, _) in html.match_indices(attr) {
                let value = &html[i + attr.len()..];
                let value = &value[..value.find('"').unwrap()];
                assert!(
                    value.starts_with("/settings/") || value.starts_with("data:"),
                    "{attr}{value}\" points somewhere else"
                );
            }
        }
        assert!(
            !html.contains("<script>"),
            "no inline script: the policy forbids it"
        );
        assert!(
            !html.contains("style=\""),
            "no inline style: the policy forbids it"
        );
        let css = page("/settings/app.css");
        assert!(!css.contains("@import") && !css.contains("url("));
        let js = page("/settings/app.js");
        assert!(!js.contains("http://") && !js.contains("//cdn"));
    }

    #[test]
    fn every_element_the_script_looks_up_is_on_the_page() {
        // A renamed id in the HTML is a button that silently stops working.
        let js = page("/settings/app.js");
        let html = page("/settings");
        let mut missing = Vec::new();
        for (i, _) in js.match_indices("$('") {
            let id = &js[i + 3..];
            let id = &id[..id.find('\'').unwrap()];
            if !id.is_empty() && !html.contains(&format!("id=\"{id}\"")) {
                missing.push(id.to_string());
            }
        }
        // Built from a stage name at run time.
        for stage in ["instance", "starting", "approve", "folder", "unlinking"] {
            assert!(
                html.contains(&format!("id=\"stage-{stage}\"")),
                "stage-{stage}"
            );
        }
        for n in 1..=3 {
            assert!(html.contains(&format!("id=\"step-{n}\"")));
        }
        missing.retain(|id| !id.starts_with("stage-") && !id.starts_with("step-"));
        assert!(
            missing.is_empty(),
            "the script looks up ids the page does not have: {missing:?}"
        );
    }
}
