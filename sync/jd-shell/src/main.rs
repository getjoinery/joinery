//! `joinery-drive-tray` — the menu-bar icon.
//!
//! Deliberately the thinnest thing in the repository. It asks the daemon what is
//! happening, draws the answer, and turns a click back into a request. It holds
//! no state, opens no database, and decides nothing — everything it would have
//! decided lives in [`view`], as a pure function tested on every platform.
//!
//! That split is what makes three trays affordable. The Linux one speaks the
//! status-notifier protocol over D-Bus, the macOS and Windows ones use the
//! native tray APIs, and each is a few dozen lines of drawing because none of
//! them contains a judgement.
//!
//! The daemon is a separate process on purpose. Sync must keep running when
//! nobody is logged in, when the desktop session restarts, and on a machine with
//! no desktop at all — so the thing that syncs cannot be the thing that draws.
//! Quitting the tray therefore stops the tray, not the sync; that is what the
//! "Pause syncing" item is for, and the menu says both plainly.

mod view;

use std::path::PathBuf;
use std::time::Duration;

use serde_json::Value;
use view::{present, Presentation};

/// How often to ask the daemon how it is doing.
///
/// A tray that polls faster than a person can notice is spending a laptop's
/// battery to redraw an identical icon.
const REFRESH: Duration = Duration::from_secs(2);

fn main() {
    let mut tray = platform::Tray::new();
    let mut last: Option<Presentation> = None;

    loop {
        let status = fetch_status();
        let presentation = present(status.as_ref());

        // Redrawing an unchanged icon makes some desktops flash it, which is
        // how a tray becomes the thing a user wants gone.
        if last.as_ref() != Some(&presentation) {
            tray.draw(&presentation);
            last = Some(presentation.clone());
        }

        for clicked in tray.clicks() {
            if !act(&clicked, &presentation) {
                return;
            }
        }
        std::thread::sleep(REFRESH);
    }
}

/// Do what a menu item says. Returns false when the tray should exit.
fn act(id: &str, presentation: &Presentation) -> bool {
    match id {
        "quit" => return false,
        "open" => {
            if let Some(root) = &presentation.sync_root {
                open_folder(&PathBuf::from(root));
            }
        }
        "settings" => {
            if let Some(endpoint) = endpoint() {
                jd_platform::open_url(&endpoint.url("/settings"));
            }
        }
        "issues" => {
            if let Some(endpoint) = endpoint() {
                jd_platform::open_url(&endpoint.url("/settings#issues"));
            }
        }
        "start" => start_daemon(),
        "pause" | "resume" | "sync-now" => {
            if let Some(endpoint) = endpoint() {
                let _ =
                    jd_platform::control::ask(&endpoint, "POST", &format!("/{id}"), Value::Null);
            }
        }
        _ => {}
    }
    true
}

fn endpoint() -> Option<jd_platform::Endpoint> {
    let paths = jd_platform::Paths::discover();
    jd_platform::Endpoint::load(&paths.state.join("control.json"))
}

fn fetch_status() -> Option<Value> {
    let endpoint = endpoint()?;
    jd_platform::control::ask(&endpoint, "GET", "/status", Value::Null)
}

/// Start the daemon as a detached process.
///
/// Detached because the tray must not become the daemon's parent: quitting the
/// tray would then take sync down with it, which is exactly the surprise the
/// two-process split exists to avoid.
fn start_daemon() {
    let Ok(exe) = std::env::current_exe() else {
        return;
    };
    // The daemon sits beside the tray in every packaging layout.
    let daemon = exe.with_file_name(jd_platform::BINARY_NAME);
    let _ = std::process::Command::new(daemon)
        .arg("daemon")
        .stdin(std::process::Stdio::null())
        .stdout(std::process::Stdio::null())
        .stderr(std::process::Stdio::null())
        .spawn();
}

fn open_folder(path: &std::path::Path) {
    #[cfg(target_os = "macos")]
    let program = "/usr/bin/open";
    #[cfg(target_os = "windows")]
    let program = "explorer";
    #[cfg(not(any(target_os = "macos", target_os = "windows")))]
    let program = "xdg-open";

    let _ = std::process::Command::new(program)
        .arg(path)
        .stdout(std::process::Stdio::null())
        .stderr(std::process::Stdio::null())
        .spawn();
}

// ---------------------------------------------------------------------------
// The per-OS drawing, and nothing else
// ---------------------------------------------------------------------------

#[cfg(target_os = "linux")]
mod platform {
    //! The status-notifier protocol, over D-Bus.
    //!
    //! Not GTK: a tray that needed a widget toolkit would drag one onto every
    //! Linux machine that installs the client, including the ones with no
    //! desktop at all. The protocol is what KDE, and GNOME with the appindicator
    //! extension, actually listen to.

    use super::Presentation;
    use std::sync::mpsc::{channel, Receiver, Sender};

    struct Item {
        presentation: Presentation,
        clicks: Sender<String>,
    }

    impl ksni::Tray for Item {
        fn id(&self) -> String {
            jd_platform::BUNDLE_ID.into()
        }
        fn icon_name(&self) -> String {
            self.presentation.icon.name().into()
        }
        fn title(&self) -> String {
            jd_platform::APP_NAME.into()
        }
        fn tool_tip(&self) -> ksni::ToolTip {
            ksni::ToolTip {
                title: jd_platform::APP_NAME.into(),
                description: self.presentation.tooltip.clone(),
                ..Default::default()
            }
        }
        fn menu(&self) -> Vec<ksni::MenuItem<Self>> {
            self.presentation
                .menu
                .iter()
                .map(|entry| {
                    let id = entry.id;
                    ksni::menu::StandardItem {
                        label: entry.label.clone(),
                        enabled: entry.enabled,
                        activate: Box::new(move |this: &mut Item| {
                            let _ = this.clicks.send(id.to_string());
                        }),
                        ..Default::default()
                    }
                    .into()
                })
                .collect()
        }
    }

    pub struct Tray {
        handle: Option<ksni::blocking::Handle<Item>>,
        clicks: Receiver<String>,
        sender: Sender<String>,
    }

    impl Tray {
        pub fn new() -> Tray {
            let (sender, clicks) = channel();
            Tray {
                handle: None,
                clicks,
                sender,
            }
        }

        pub fn draw(&mut self, presentation: &Presentation) {
            let item = Item {
                presentation: presentation.clone(),
                clicks: self.sender.clone(),
            };
            match &self.handle {
                Some(handle) => {
                    let next = presentation.clone();
                    let _ = handle.update(move |i: &mut Item| i.presentation = next.clone());
                }
                // A desktop with no status-notifier host is not a reason to
                // fail: the daemon is doing the work, and the CLI says the same
                // things this would have.
                None => {
                    use ksni::blocking::TrayMethods;
                    self.handle = item.spawn().ok();
                }
            }
        }

        pub fn clicks(&mut self) -> Vec<String> {
            self.clicks.try_iter().collect()
        }
    }
}

#[cfg(any(target_os = "macos", target_os = "windows"))]
mod platform {
    //! The native tray on macOS and Windows.

    use super::Presentation;
    use tray_icon::menu::{Menu, MenuEvent, MenuItem as NativeItem};
    use tray_icon::{TrayIcon, TrayIconBuilder};

    pub struct Tray {
        icon: Option<TrayIcon>,
        /// Menu-item id → our own id, because the native menu hands back its own
        /// identifiers and the mapping has to survive a rebuild of the menu.
        ids: Vec<(String, String)>,
    }

    impl Tray {
        pub fn new() -> Tray {
            Tray {
                icon: None,
                ids: Vec::new(),
            }
        }

        pub fn draw(&mut self, presentation: &Presentation) {
            let menu = Menu::new();
            self.ids.clear();
            for entry in &presentation.menu {
                let item = NativeItem::new(&entry.label, entry.enabled, None);
                self.ids.push((item.id().0.clone(), entry.id.to_string()));
                let _ = menu.append(&item);
            }

            // The glyph rather than an image: the tray is deliberately thin, and
            // a bundled icon set is a packaging concern that belongs with the
            // installers rather than in the loop that draws.
            let title = format!("{} ", presentation.icon.glyph());
            match &mut self.icon {
                Some(icon) => {
                    let _ = icon.set_menu(Some(Box::new(menu)));
                    let _ = icon.set_title(Some(&title));
                    let _ = icon.set_tooltip(Some(&presentation.tooltip));
                }
                None => {
                    self.icon = TrayIconBuilder::new()
                        .with_menu(Box::new(menu))
                        .with_title(&title)
                        .with_tooltip(&presentation.tooltip)
                        .build()
                        .ok();
                }
            }
        }

        pub fn clicks(&mut self) -> Vec<String> {
            let mut out = Vec::new();
            while let Ok(event) = MenuEvent::receiver().try_recv() {
                if let Some((_, id)) = self.ids.iter().find(|(native, _)| *native == event.id.0) {
                    out.push(id.clone());
                }
            }
            out
        }
    }
}

#[cfg(not(any(target_os = "linux", target_os = "macos", target_os = "windows")))]
mod platform {
    //! Somewhere with no tray. The daemon still syncs; this simply draws
    //! nothing, which is better than refusing to build.

    use super::Presentation;

    pub struct Tray;

    impl Tray {
        pub fn new() -> Tray {
            Tray
        }
        pub fn draw(&mut self, _presentation: &Presentation) {}
        pub fn clicks(&mut self) -> Vec<String> {
            Vec::new()
        }
    }
}
