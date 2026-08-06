//! What the tray shows, decided once for all three operating systems.
//!
//! There are three trays and one truth. Every judgement about what a state
//! *means* — which icon, what the tooltip says, whether the pause item reads
//! "Pause" or "Resume", whether the issues item is worth showing at all — is
//! made here, as a pure function of the daemon's answer. The per-OS code below
//! it does nothing but draw the result.
//!
//! That is not tidiness. A tray is the least testable thing in the product: it
//! needs a desktop session, a specific window server, and a human to look at it.
//! Anything decided inside it is decided somewhere no test will ever go, and
//! three separate copies of that reasoning would drift apart in three different
//! ways nobody would notice.

use serde_json::Value;

/// Which icon to draw. Named for what the user should conclude, not for a color,
/// because two of them are not a color on every platform — macOS renders a
/// template image and Windows a monochrome glyph.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Icon {
    /// Everything is where it should be.
    Idle,
    /// Work in flight.
    Busy,
    /// Something needs a person.
    Attention,
    /// Not syncing at all.
    Stopped,
}

impl Icon {
    /// The icon name for platforms that look one up by name (the Linux status
    /// notifier spec). Unused on macOS and Windows, which draw a glyph.
    #[allow(dead_code)]
    pub fn name(&self) -> &'static str {
        match self {
            Icon::Idle => "joinery-drive-idle",
            Icon::Busy => "joinery-drive-busy",
            Icon::Attention => "joinery-drive-attention",
            Icon::Stopped => "joinery-drive-stopped",
        }
    }

    /// A single character standing in for the icon, for the platforms and the
    /// terminals that have no image to draw. Unused on Linux, which looks the
    /// icon up by name through the status-notifier spec.
    #[allow(dead_code)]
    pub fn glyph(&self) -> &'static str {
        match self {
            Icon::Idle => "\u{2713}",      // check
            Icon::Busy => "\u{21bb}",      // clockwise arrow
            Icon::Attention => "\u{26a0}", // warning
            Icon::Stopped => "\u{2298}",   // circled slash
        }
    }
}

/// One entry in the menu. `id` is what comes back when it is clicked.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct MenuItem {
    pub id: &'static str,
    pub label: String,
    pub enabled: bool,
}

impl MenuItem {
    fn on(id: &'static str, label: impl Into<String>) -> MenuItem {
        MenuItem {
            id,
            label: label.into(),
            enabled: true,
        }
    }
    fn off(id: &'static str, label: impl Into<String>) -> MenuItem {
        MenuItem {
            id,
            label: label.into(),
            enabled: false,
        }
    }
}

/// Everything the tray needs to draw itself.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Presentation {
    pub icon: Icon,
    /// The line under the cursor. First sentence is the state; the rest is
    /// context.
    pub tooltip: String,
    pub menu: Vec<MenuItem>,
    /// Where "Open folder" should go, when there is somewhere to go.
    pub sync_root: Option<String>,
}

/// Turn the daemon's `/status` answer into what to draw.
///
/// `None` means the daemon is not running, which is a different picture from a
/// daemon reporting a problem: there is nothing to pause, nothing to sync now,
/// and the only useful action is starting it.
pub fn present(status: Option<&Value>) -> Presentation {
    let Some(status) = status else {
        return Presentation {
            icon: Icon::Stopped,
            tooltip: "Joinery Drive is not running.".into(),
            menu: vec![
                MenuItem::off("state", "Not running"),
                MenuItem::on("start", "Start syncing"),
                MenuItem::on("quit", "Quit"),
            ],
            sync_root: None,
        };
    };

    let field = |k: &str| status.get(k).and_then(Value::as_str).unwrap_or("");
    let indicator = field("indicator");
    let paused = status.get("paused").and_then(Value::as_bool) == Some(true);

    let icon = match indicator {
        "green" => Icon::Idle,
        "working" => Icon::Busy,
        "attention" => Icon::Attention,
        _ => Icon::Stopped,
    };

    let summary = field("summary");
    // The total rather than the length of the list the answer carried. The
    // daemon sends at most fifty, so a tray counting the array would say "50
    // issues" to somebody who has three hundred — an understatement, which is
    // the one direction this indicator must never err in.
    let issues = status
        .get("issues_total")
        .and_then(Value::as_u64)
        .map(|n| n as usize)
        .unwrap_or_else(|| {
            status
                .get("issues")
                .and_then(Value::as_array)
                .map(Vec::len)
                .unwrap_or(0)
        });

    let mut menu = vec![MenuItem::off("state", summary.to_string())];

    // The first thing on a sync client's menu is the folder. It is what people
    // open it for.
    menu.push(MenuItem::on("open", "Open Joinery Drive folder"));

    if issues > 0 {
        let word = if issues == 1 { "issue" } else { "issues" };
        menu.push(MenuItem::on("issues", format!("{issues} {word}\u{2026}")));
    }

    // One item that changes its own label, rather than two of which one is
    // always dead. A permanently greyed "Resume" teaches people to ignore the
    // menu.
    if paused {
        menu.push(MenuItem::on("resume", "Resume syncing"));
    } else {
        menu.push(MenuItem::on("pause", "Pause syncing"));
        // Asking for a check while paused would do nothing, and an item that
        // does nothing is worse than one that is not there.
        menu.push(MenuItem::on("sync-now", "Check now"));
    }

    menu.push(MenuItem::on("settings", "Settings\u{2026}"));
    menu.push(MenuItem::on("quit", "Quit"));

    let tooltip = {
        let mut lines = vec![format!("Joinery Drive \u{2014} {summary}")];
        let device = field("device_name");
        let instance = field("base_url");
        if !device.is_empty() && !instance.is_empty() {
            lines.push(format!("{device} \u{2192} {instance}"));
        }
        if let Some(blocker) = status.get("blocker").and_then(Value::as_str) {
            lines.push(blocker.to_string());
        }
        lines.join("\n")
    };

    Presentation {
        icon,
        tooltip,
        menu,
        sync_root: Some(field("sync_root").to_string()).filter(|s| !s.is_empty()),
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn ids(p: &Presentation) -> Vec<&'static str> {
        p.menu.iter().map(|m| m.id).collect()
    }

    #[test]
    fn a_settled_client_shows_the_idle_icon_and_says_so() {
        let p = present(Some(&json!({
            "indicator": "green",
            "summary": "Up to date \u{2014} 1204 items",
            "paused": false,
            "device_name": "MacBook",
            "base_url": "https://drive.example.com",
            "sync_root": "/Users/u/Joinery Drive",
            "issues": [],
        })));
        assert_eq!(p.icon, Icon::Idle);
        assert!(p.tooltip.contains("Up to date"));
        assert!(p.tooltip.contains("MacBook"));
        assert_eq!(p.sync_root.as_deref(), Some("/Users/u/Joinery Drive"));
    }

    #[test]
    fn issues_are_only_offered_when_there_are_some() {
        // A permanently present "0 issues" item teaches people that the menu
        // never has anything in it, which is exactly when they stop reading it.
        let quiet = present(Some(&json!({
            "indicator": "green", "summary": "Up to date", "paused": false, "issues": []
        })));
        assert!(!ids(&quiet).contains(&"issues"));

        let noisy = present(Some(&json!({
            "indicator": "attention", "summary": "2 items need your attention", "paused": false,
            "issues": [{"id": 1}, {"id": 2}]
        })));
        assert!(ids(&noisy).contains(&"issues"));
        assert_eq!(noisy.icon, Icon::Attention);
        let item = noisy.menu.iter().find(|m| m.id == "issues").unwrap();
        assert_eq!(item.label, "2 issues\u{2026}");
    }

    #[test]
    fn one_issue_is_singular() {
        let p = present(Some(&json!({
            "indicator": "attention", "summary": "x", "paused": false, "issues": [{"id": 1}]
        })));
        let item = p.menu.iter().find(|m| m.id == "issues").unwrap();
        assert_eq!(item.label, "1 issue\u{2026}");
    }

    #[test]
    fn pause_becomes_resume_rather_than_going_grey() {
        let running = present(Some(&json!({
            "indicator": "green", "summary": "x", "paused": false, "issues": []
        })));
        assert!(ids(&running).contains(&"pause"));
        assert!(!ids(&running).contains(&"resume"));

        let paused = present(Some(&json!({
            "indicator": "stopped", "summary": "Syncing is paused.", "paused": true, "issues": []
        })));
        assert!(ids(&paused).contains(&"resume"));
        assert!(!ids(&paused).contains(&"pause"));
    }

    #[test]
    fn checking_now_is_not_offered_while_paused() {
        // It would do nothing, and an item that does nothing is worse than one
        // that is not there.
        let paused = present(Some(&json!({
            "indicator": "stopped", "summary": "Syncing is paused.", "paused": true, "issues": []
        })));
        assert!(!ids(&paused).contains(&"sync-now"));
    }

    #[test]
    fn a_daemon_that_is_not_running_offers_starting_it_and_nothing_else_useful() {
        // Different from a daemon reporting a problem: there is nothing to
        // pause, nothing to check now, and no folder path to open.
        let p = present(None);
        assert_eq!(p.icon, Icon::Stopped);
        assert_eq!(ids(&p), vec!["state", "start", "quit"]);
        assert!(p.sync_root.is_none());
    }

    #[test]
    fn a_blocker_reaches_the_tooltip_where_a_person_will_see_it() {
        // The unmounted-drive message in particular: the user has to be able to
        // learn that nothing was deleted without opening anything.
        let p = present(Some(&json!({
            "indicator": "stopped",
            "summary": "Not syncing",
            "paused": false,
            "issues": [],
            "blocker": "Your sync folder is not where it was (/Volumes/Backup). Nothing has been deleted \u{2014} reconnect the drive or move the folder back."
        })));
        assert!(p.tooltip.contains("Nothing has been deleted"));
    }

    #[test]
    fn the_folder_is_the_first_thing_you_can_actually_click() {
        // It is what people open a sync client's menu for.
        let p = present(Some(&json!({
            "indicator": "green", "summary": "x", "paused": false, "issues": []
        })));
        let first_enabled = p.menu.iter().find(|m| m.enabled).unwrap();
        assert_eq!(first_enabled.id, "open");
    }

    #[test]
    fn every_state_has_a_way_out_of_the_menu() {
        for status in [
            None,
            Some(json!({"indicator": "green", "summary": "x", "paused": false, "issues": []})),
            Some(json!({"indicator": "stopped", "summary": "x", "paused": true, "issues": []})),
            Some(
                json!({"indicator": "attention", "summary": "x", "paused": false, "issues": [{"id":1}]}),
            ),
        ] {
            let p = present(status.as_ref());
            assert!(ids(&p).contains(&"quit"), "{p:?} cannot be quit");
        }
    }

    #[test]
    fn an_indicator_this_build_does_not_know_reads_as_stopped_not_as_fine() {
        // A newer daemon inventing a state must not make an older tray show a
        // reassuring tick.
        let p = present(Some(&json!({
            "indicator": "something-new", "summary": "?", "paused": false, "issues": []
        })));
        assert_eq!(p.icon, Icon::Stopped);
    }

    #[test]
    fn every_icon_has_a_distinct_name_and_glyph() {
        let icons = [Icon::Idle, Icon::Busy, Icon::Attention, Icon::Stopped];
        for (i, a) in icons.iter().enumerate() {
            for b in &icons[i + 1..] {
                assert_ne!(a.name(), b.name());
                assert_ne!(a.glyph(), b.glyph());
            }
        }
    }
}
