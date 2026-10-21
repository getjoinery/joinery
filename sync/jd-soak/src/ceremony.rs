//! Approving a device's link the way a person at a browser does
//! (`specs/drive_sync_soak.md` § The encrypted lane, E2).
//!
//! Only the approval is played here. The device's half — its keypair, the
//! request, the poll, keeping what the approval handed it — is the client's own
//! code (`jd_daemon::link`), so what the rig links is what a person links.
//!
//! The approval is a browser-session action: an API key is refused. The rig
//! gets a browser session the way the native apps do: a session key from
//! `auth/login`, a one-time bridge URL from `auth/web_session`, and the cookies
//! that URL sets. The API's CSRF token is the `joinery_api_csrf` cookie, sent
//! back as `X-Joinery-Csrf`.

use std::collections::BTreeMap;
use std::time::Duration;

use serde_json::{json, Value};

use crate::vault::DriveKey;

/// A signed-in browser session on the soak instance.
pub struct BrowserSession {
    base: String,
    agent: ureq::Agent,
    cookies: BTreeMap<String, String>,
    /// The session key the bridge was minted from, signed out on drop.
    key: Option<jd_proto::Client>,
}

const CSRF_COOKIE: &str = "joinery_api_csrf";

impl BrowserSession {
    /// Sign in as `email` and turn the session key into a browser session.
    pub fn open(server: &str, email: &str, password: &str) -> Result<BrowserSession, String> {
        let base = server.trim_end_matches('/').to_string();
        let mut client = jd_proto::Client::new(&base);
        client
            .login(email, password, "jd-soak link approver")
            .map_err(|e| format!("the approver could not sign in: {e}"))?;
        let creds = client
            .credentials()
            .cloned()
            .ok_or("the approver signed in without a credential")?;
        // Redirects are followed by hand: each hop's cookies are the session.
        let agent = ureq::AgentBuilder::new()
            .redirects(0)
            .timeout_connect(Duration::from_secs(15))
            .timeout_read(jd_proto::SILENCE_LIMIT)
            .build();
        let minted = envelope(
            agent
                .post(&format!("{base}/api/v1/auth/web_session"))
                .set("public-key", &creds.public_key)
                .set("secret-key", &creds.secret_key)
                .send_json(json!({ "target": "/" })),
        )
        .map_err(|e| format!("auth/web_session: {e}"))?;
        let bridge = minted
            .get("bridge_url")
            .and_then(Value::as_str)
            .ok_or("auth/web_session handed back no bridge_url")?
            .to_string();

        let mut session = BrowserSession {
            base,
            agent,
            cookies: BTreeMap::new(),
            key: Some(client),
        };
        // The bridge signs the session in and sends the browser on, and a
        // sign-in can replace the session it started in, cookies and all: so
        // every hop is followed, and the cookies are the last ones set.
        let mut next = Some(bridge);
        let mut hops = 0;
        while let Some(path) = next.take() {
            hops += 1;
            if hops > 5 {
                break;
            }
            let url = if path.starts_with("http") {
                path
            } else {
                format!("{}{path}", session.base)
            };
            let response = match session.with_cookies(session.agent.get(&url)).call() {
                Ok(r) => r,
                Err(ureq::Error::Status(_, r)) => r,
                Err(e) => return Err(format!("the bridge: {e}")),
            };
            session.keep_cookies(&response);
            if (300..400).contains(&response.status()) {
                next = response.header("location").map(str::to_string);
            }
        }
        if !session.cookies.contains_key(CSRF_COOKIE) {
            return Err(format!(
                "the bridge set no {CSRF_COOKIE} cookie (it set: {})",
                session.cookies.keys().cloned().collect::<Vec<_>>().join(", ")
            ));
        }
        Ok(session)
    }

    fn with_cookies(&self, request: ureq::Request) -> ureq::Request {
        if self.cookies.is_empty() {
            return request;
        }
        let header = self
            .cookies
            .iter()
            .map(|(k, v)| format!("{k}={v}"))
            .collect::<Vec<_>>()
            .join("; ");
        request.set("Cookie", &header)
    }

    fn keep_cookies(&mut self, response: &ureq::Response) {
        for line in response.all("set-cookie") {
            if let Some((name, value)) = parse_set_cookie(line) {
                if value.is_empty() || value == "deleted" {
                    self.cookies.remove(&name);
                } else {
                    self.cookies.insert(name, value);
                }
            }
        }
    }

    /// `POST /api/v1/action/{name}` as this browser session.
    pub fn action(&self, name: &str, body: Value) -> Result<Value, String> {
        let csrf = self.cookies.get(CSRF_COOKIE).cloned().unwrap_or_default();
        let request = self
            .agent
            .post(&format!("{}/api/v1/action/{name}", self.base))
            .set("X-Joinery-Csrf", &csrf);
        envelope(self.with_cookies(request).send_json(body)).map_err(|e| format!("{name}: {e}"))
    }
}

impl Drop for BrowserSession {
    // The approver's session key is one more credential on the account; it
    // goes when the approving is over, not when the account is purged.
    fn drop(&mut self) {
        if let Some(mut client) = self.key.take() {
            let _ = client.logout();
        }
    }
}

/// Approve the device that is showing `code`, handing it the account's Drive
/// key sealed to the public key it offered — what the approval page does with
/// the encrypted-folders box ticked.
pub fn approve(session: &BrowserSession, code: &str, drive_key: &DriveKey) -> Result<(), String> {
    let info = session.action("drive_device_link_info", json!({ "code": code }))?;
    let device_pubkey = info
        .get("device_pubkey")
        .and_then(Value::as_str)
        .filter(|k| !k.is_empty())
        .ok_or("the device offered no key to seal the vault key to")?;
    let sealed = jd_crypto::vault::seal_to_public_key(&drive_key.secret_pkcs8, device_pubkey)
        .map_err(|e| format!("cannot seal the vault key to the device: {e}"))?;
    let approved = session.action(
        "drive_device_link_approve",
        json!({ "code": code, "enable_vault": true, "sealed_vault_key": sealed }),
    )?;
    if approved.get("vault_shared").and_then(Value::as_bool) != Some(true) {
        return Err(format!("the approval did not hand the vault over: {approved}"));
    }
    Ok(())
}

/// The success envelope's `data`, or what the error said.
fn envelope(outcome: Result<ureq::Response, ureq::Error>) -> Result<Value, String> {
    match outcome {
        Ok(response) => {
            // Redirects are not followed (`open`), so a refusal that sends the
            // caller to a sign-in page arrives here as a 3xx with no body.
            let status = response.status();
            let location = response.header("location").unwrap_or_default().to_string();
            let body: Value = response.into_json().map_err(|e| {
                format!("the reply ({status}{}) is not JSON: {e}", if location.is_empty() {
                    String::new()
                } else {
                    format!(" to {location}")
                })
            })?;
            Ok(body.get("data").cloned().unwrap_or(Value::Null))
        }
        Err(ureq::Error::Status(status, response)) => {
            let body: Value = response.into_json().unwrap_or(Value::Null);
            Err(format!(
                "{status} {}",
                body.get("error").and_then(Value::as_str).unwrap_or("(no message)")
            ))
        }
        Err(e) => Err(e.to_string()),
    }
}

/// `name=value` from a `Set-Cookie` line, attributes dropped.
fn parse_set_cookie(line: &str) -> Option<(String, String)> {
    let pair = line.split(';').next()?.trim();
    let (name, value) = pair.split_once('=')?;
    let name = name.trim();
    (!name.is_empty()).then(|| (name.to_string(), value.trim().to_string()))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_set_cookie_line_gives_its_name_and_value_and_nothing_else() {
        assert_eq!(
            parse_set_cookie("joinery_api_csrf=abc123; path=/; secure; SameSite=Lax"),
            Some(("joinery_api_csrf".into(), "abc123".into()))
        );
        assert_eq!(
            parse_set_cookie("PHPSESSID=x=y; HttpOnly"),
            Some(("PHPSESSID".into(), "x=y".into()))
        );
        assert_eq!(parse_set_cookie("; path=/"), None);
    }
}
