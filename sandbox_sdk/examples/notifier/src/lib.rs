//! Notifier: a Joinery sandboxed plugin (specs/plugin_tiers.md WP9).
//!
//! Posts the site events an admin approved (a new member, a published post, a
//! new comment) to a chat webhook, keeps a log of each post in its own model,
//! sends a daily digest of what it posted, and has an admin page with that log,
//! a test form and its settings. It uses every door: the site's API (door 1,
//! its own records), signals (door 2), a schedule (door 3), its page (door 4)
//! and the web (door 5, the webhook).

use joinery_plugin::*;
use serde_json::{json, Value};

const MODEL: &str = "Sbx_notifier_SentLog";
const PREFIX: &str = "notifier__sent_log";

#[plugin_fn]
pub fn on_signal(raw: String) -> FnResult<String> {
    let call: Call<SignalInput> = read_call(&raw)?;
    let label = setting(&call, "site_label");
    let text = describe(&call.input.signal, &call.input.data);
    let sent = post(&call, &format!("{}{}", prefix(&label), text));
    // The answer decides whether the site tries this signal again: a webhook
    // that is down (5xx) or did not answer is worth another try, so this one
    // fails and nothing is recorded yet; one that refused it (4xx: a wrong
    // address) is not, so that is the outcome, recorded.
    if worth_retrying(&sent) {
        return Err(Error::msg(format!("the webhook did not take it ({}); the site will try again", outcome(&sent))).into());
    }
    record(&call.input.signal, &text, &sent);
    answer_json(&json!({ "message": text, "delivered": delivered(&sent) }))
}

#[plugin_fn]
pub fn on_schedule(raw: String) -> FnResult<String> {
    let call: Call<ScheduleInput> = read_call(&raw)?;
    if call.input.schedule != "digest" || setting(&call, "send_digest") != "1" {
        return answer_json(&json!({ "message": "nothing to do" }));
    }
    // What it posted since its last digest: its own log, newest first, up to
    // the previous digest row (a page of 200 at a time).
    let mut count = 0;
    let mut page = 0;
    'pages: loop {
        let path = format!("/api/v1/{}s?numperpage=200&page={}&sort={}_id&sdirection=DESC", MODEL, page, PREFIX);
        let rows = match api("GET", &path, None) {
            Ok(answer) => answer.body.get("data").and_then(Value::as_array).cloned().unwrap_or_default(),
            Err(e) => return Err(Error::msg(format!("could not read its log: {}", e)).into()),
        };
        if rows.is_empty() {
            break;
        }
        for row in &rows {
            let signal = row.get(format!("{}_signal", PREFIX)).and_then(Value::as_str).unwrap_or("");
            if signal == "digest" {
                break 'pages;
            }
            if signal != "test" {
                count += 1;
            }
        }
        if rows.len() < 200 || page >= 50 {
            break;
        }
        page += 1;
    }
    let label = setting(&call, "site_label");
    let text = format!("Daily digest: {} event{} posted since the last digest.", count, if count == 1 { "" } else { "s" });
    let sent = post(&call, &format!("{}{}", prefix(&label), text));
    record("digest", &text, &sent);
    answer_json(&json!({ "message": text, "delivered": delivered(&sent) }))
}

#[plugin_fn]
pub fn on_ui(raw: String) -> FnResult<String> {
    let call: Call<UiInput> = read_call(&raw)?;
    if call.input.handler != "send_test" {
        return answer_ui(UiAnswer::error("This form is not one the notifier knows."));
    }
    let text = call.input.values.get("text").and_then(Value::as_str).unwrap_or("").trim().to_string();
    if text.is_empty() {
        return answer_ui(UiAnswer::error("Nothing to send.").field_error("text", "Write something to send."));
    }
    if setting(&call, "webhook_url").is_empty() {
        return answer_ui(UiAnswer::error("Set the webhook URL in the settings below first."));
    }
    let label = setting(&call, "site_label");
    let sent = post(&call, &format!("{}{}", prefix(&label), text));
    record("test", &text, &sent);
    match sent {
        Ok(status) if (200..300).contains(&status) => answer_ui(UiAnswer::done(format!("Sent: the webhook answered {}.", status))),
        Ok(status) => answer_ui(UiAnswer::error(format!("The webhook answered {}: check its address.", status))),
        Err(e) => answer_ui(UiAnswer::error(format!("It could not be sent: {}", e))),
    }
}

fn prefix(label: &str) -> String {
    if label.is_empty() { String::new() } else { format!("[{}] ", label) }
}

/// What a site event says, in words.
fn describe(signal: &str, data: &Value) -> String {
    let text = |key: &str| data.get(key).and_then(Value::as_str).unwrap_or("").to_string();
    match signal {
        "account.signup" => format!("New member: {}", text("display_name")),
        "post.published" => format!("New post: {} {}", text("post_title"), text("post_url")),
        "comment.posted" => format!("New comment by {} on {}: {}", text("author_name"), text("post_title"), text("comment_excerpt")),
        other => format!("{} happened", other),
    }
}

/// Post one message to the webhook: Slack's and Mattermost's {"text"}, or
/// Discord's {"content"}. The HTTP status, or why it could not be sent.
fn post<I>(call: &Call<I>, message: &str) -> Result<u16, String> {
    let url = setting(call, "webhook_url");
    if url.is_empty() {
        return Err("no webhook URL is set".into());
    }
    let body = if url.contains("discord.com/") {
        json!({ "content": message })
    } else {
        json!({ "text": message })
    };
    match http("POST", &url, &[("Content-Type", "application/json")], Some(&body.to_string())) {
        Ok(answer) => Ok(answer.status),
        Err(e) => {
            log(Level::Warn, &format!("webhook post failed: {}", e));
            Err(e.to_string())
        }
    }
}

/// Whether the webhook took it: a 2xx answer.
fn delivered(sent: &Result<u16, String>) -> bool {
    matches!(sent, Ok(s) if (200..300).contains(s))
}

/// Whether another try could go better: the webhook was down (5xx, 429) or
/// could not be reached. An address that refused it (4xx), one this plugin
/// may not post to, or none at all will be the same next time.
fn worth_retrying(sent: &Result<u16, String>) -> bool {
    match sent {
        Ok(s) => *s >= 500 || *s == 429,
        // The door's own refusals name the rule; a failed request says so.
        Err(e) => e.starts_with("the request failed"),
    }
}

fn outcome(sent: &Result<u16, String>) -> String {
    match sent {
        Ok(s) => format!("HTTP {}", s),
        Err(e) => e.clone(),
    }
}

/// A row in its own log, through the site's API.
fn record(signal: &str, message: &str, sent: &Result<u16, String>) {
    let body = json!({
        format!("{}_signal", PREFIX): signal,
        format!("{}_message", PREFIX): message,
        format!("{}_http_status", PREFIX): sent.as_ref().ok(),
        format!("{}_delivered", PREFIX): delivered(sent),
    });
    if let Err(e) = api("POST", &format!("/api/v1/{}", MODEL), Some(body)) {
        log(Level::Error, &format!("could not record the message: {}", e));
    }
}
