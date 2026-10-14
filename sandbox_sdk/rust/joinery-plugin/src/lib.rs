//! Write a Joinery sandboxed plugin in Rust.
//!
//! A sandboxed plugin is a WebAssembly module and a `manifest.json`
//! (docs/sandboxed_plugins.md in the Joinery tree). The site's plugin host calls
//! the module's exports, `on_ui`, `on_signal` and `on_schedule`, each with one
//! JSON object, `{"input": …, "settings": {…}}`, and the module reaches the
//! world only through three doors the host gives it. This crate is those calls
//! and doors, typed:
//!
//! ```ignore
//! use joinery_plugin::*;
//!
//! #[plugin_fn]
//! pub fn on_signal(raw: String) -> FnResult<String> {
//!     let call: Call<SignalInput> = read_call(&raw)?;
//!     log(Level::Info, &format!("told about {}", call.input.signal));
//!     let answer = api("GET", "/api/v1/Users?numperpage=1", None)?;
//!     answer_json(&serde_json::json!({ "users": answer.body["num_results"] }))
//! }
//! ```
//!
//! Build with `cargo build --release --target wasm32-unknown-unknown`; the
//! module is `target/wasm32-unknown-unknown/release/<crate>.wasm`.

use std::collections::BTreeMap;

pub use extism_pdk::{plugin_fn, FnResult, Error};
// `#[plugin_fn]` names `extism_pdk`; `use joinery_plugin::*;` brings it in.
pub use extism_pdk;
use extism_pdk::{host_fn, Json};
use serde::de::DeserializeOwned;
use serde::{Deserialize, Serialize};
use serde_json::Value;

#[host_fn("extism:host/user")]
extern "ExtismHost" {
    fn joinery_api(request: Json<Value>) -> Json<Value>;
    fn joinery_http(request: Json<Value>) -> Json<Value>;
    fn joinery_log(request: Json<Value>) -> Json<Value>;
}

/// One call from the host: what it is about, and the plugin's settings by
/// their manifest names (secrets opened).
#[derive(Debug, Deserialize)]
pub struct Call<I> {
    pub input: I,
    #[serde(default, deserialize_with = "settings_map")]
    pub settings: BTreeMap<String, Value>,
}

/// The input of `on_ui`: a form on one of the plugin's pages was submitted.
#[derive(Debug, Deserialize)]
pub struct UiInput {
    /// The form's `handler` in the manifest.
    pub handler: String,
    /// The form's declared fields, by name: text, `true`/`false` for a
    /// boolean, one of its options for a select.
    #[serde(default)]
    pub values: BTreeMap<String, Value>,
    /// Who submitted it.
    pub user: Option<User>,
}

#[derive(Debug, Deserialize)]
pub struct User {
    pub id: i64,
    pub permission: i64,
}

/// The input of `on_signal`: something happened on the site.
///
/// Your answer decides whether the site tries it again: return an error (or
/// run out of time) and it is retried on the delivery queue's backoff; answer
/// and it is done. Fail what may work later (a service that was down); answer
/// what never will.
#[derive(Debug, Deserialize)]
pub struct SignalInput {
    /// The signal's name, e.g. `account.signup`.
    pub signal: String,
    /// The same for every receiver of one signal; use it to notice a repeat.
    pub message_id: String,
    /// When it happened, ISO 8601 UTC.
    pub timestamp: String,
    /// The signal's payload (see the site's signal catalog).
    #[serde(default)]
    pub data: Value,
}

/// The input of `on_schedule`: one of the plugin's schedules is due.
#[derive(Debug, Deserialize)]
pub struct ScheduleInput {
    /// The schedule's `name` in the manifest.
    pub schedule: String,
    /// Now, ISO 8601 UTC.
    pub time: String,
}

/// What `on_ui` answers: a message for the person, or errors by field.
#[derive(Debug, Default, Serialize)]
pub struct UiAnswer {
    pub message: String,
    #[serde(skip_serializing_if = "BTreeMap::is_empty")]
    pub errors: BTreeMap<String, String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub status: Option<String>,
}

impl UiAnswer {
    pub fn done(message: impl Into<String>) -> Self {
        UiAnswer { message: message.into(), ..Default::default() }
    }

    pub fn error(message: impl Into<String>) -> Self {
        UiAnswer { message: message.into(), status: Some("error".into()), ..Default::default() }
    }

    pub fn field_error(mut self, field: impl Into<String>, message: impl Into<String>) -> Self {
        self.errors.insert(field.into(), message.into());
        self.status = Some("error".into());
        self
    }
}

/// An answer from a door.
#[derive(Debug, Deserialize)]
pub struct Response {
    pub status: u16,
    #[serde(default)]
    pub headers: BTreeMap<String, String>,
    #[serde(default)]
    pub body: Value,
}

#[derive(Debug, Clone, Copy)]
pub enum Level {
    Debug,
    Info,
    Warn,
    Error,
}

/// Read the call the host made (the export's one argument).
pub fn read_call<I: DeserializeOwned>(raw: &str) -> Result<Call<I>, Error> {
    Ok(serde_json::from_str(raw)?)
}

/// A setting's value as text: empty when it is unset.
pub fn setting<I>(call: &Call<I>, name: &str) -> String {
    match call.settings.get(name) {
        Some(Value::String(s)) => s.clone(),
        Some(Value::Null) | None => String::new(),
        Some(other) => other.to_string(),
    }
}

/// Answer with any JSON value.
pub fn answer_json<T: Serialize>(value: &T) -> FnResult<String> {
    Ok(serde_json::to_string(value)?)
}

/// Answer a form.
pub fn answer_ui(answer: UiAnswer) -> FnResult<String> {
    answer_json(&answer)
}

/// Door 1: the site's API, as this plugin (with the credential an admin's
/// approval minted; the host adds it). `path` starts `/api/v1/`; `body` is a
/// JSON object for a create (POST) or an update (PUT).
pub fn api(method: &str, path: &str, body: Option<Value>) -> Result<Response, Error> {
    let request = serde_json::json!({ "method": method, "path": path, "body": body.unwrap_or(Value::Null) });
    door(unsafe { joinery_api(Json(request))? }.0)
}

/// Door 5: HTTPS to a host the plugin's manifest names and an admin approved.
/// The body is text (send JSON as a string, with its Content-Type header).
pub fn http(method: &str, url: &str, headers: &[(&str, &str)], body: Option<&str>) -> Result<Response, Error> {
    let headers: BTreeMap<&str, &str> = headers.iter().copied().collect();
    let request = serde_json::json!({ "method": method, "url": url, "headers": headers, "body": body.unwrap_or("") });
    door(unsafe { joinery_http(Json(request))? }.0)
}

/// A line in the plugin's call log, beside what it did.
pub fn log(level: Level, message: &str) {
    let level = match level {
        Level::Debug => "debug",
        Level::Info => "info",
        Level::Warn => "warn",
        Level::Error => "error",
    };
    let _ = unsafe { joinery_log(Json(serde_json::json!({ "level": level, "message": message }))) };
}

fn door(answer: Value) -> Result<Response, Error> {
    if let Some(error) = answer.get("error").and_then(Value::as_str) {
        return Err(Error::msg(error.to_string()));
    }
    Ok(serde_json::from_value(answer)?)
}

/// Settings arrive as an object, or as an empty list from a site with none.
fn settings_map<'de, D>(deserializer: D) -> Result<BTreeMap<String, Value>, D::Error>
where
    D: serde::Deserializer<'de>,
{
    Ok(match Value::deserialize(deserializer)? {
        Value::Object(map) => map.into_iter().collect(),
        _ => BTreeMap::new(),
    })
}
