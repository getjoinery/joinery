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

// A tray started at logon must not bring a console window with it, and closing
// that window would kill the tray. Windows decides this from the executable's
// subsystem, so it is a property of the build rather than of how it is started.
#![cfg_attr(windows, windows_subsystem = "windows")]

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
    // Answered before any tray exists, so an installer or a person checking
    // which build is installed gets the number on a machine with no desktop.
    if std::env::args().nth(1).as_deref() == Some("--version") {
        println!("joinery-drive-tray {}", env!("CARGO_PKG_VERSION"));
        return;
    }

    // Started at Windows logon (the `Run` value autostart.rs writes), the tray
    // is also what brings sync up: a windowed program starting the daemon with
    // no console window, which the daemon started directly would have had.
    if std::env::args().any(|a| a == jd_platform::autostart::START_DAEMON_FLAG)
        && fetch_status().is_none()
    {
        start_daemon();
    }

    let mut tray = platform::Tray::new();
    let mut last: Option<Presentation> = None;
    // An update, or a package manager, replaces this program's file while it
    // runs; this is how the tray notices and becomes the new copy.
    let mut me = jd_platform::install::ExeWatch::current();

    loop {
        if me.as_mut().is_some_and(|w| w.replaced()) {
            if let Some(w) = &me {
                if jd_platform::install::spawn_detached(w.path(), &[]).is_ok() {
                    return;
                }
            }
            // Could not start the new copy; keep drawing with this one.
            me = None;
        }

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
        tray.wait(REFRESH);
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
        // All three open the settings page, which shows the setup while the
        // computer is not linked. The address carries a ticket the page trades
        // for a session as it loads, never the daemon's own token: a URL lands
        // in browser history, and the token is good for as long as the daemon
        // runs.
        "settings" | "setup" => open_settings(None),
        "issues" => open_settings(Some("issues")),
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

fn open_settings(section: Option<&str>) {
    if let Some(url) = endpoint().and_then(|e| jd_platform::control::settings_url(&e, section)) {
        jd_platform::open_url(&url);
    }
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
    // The daemon sits beside the tray in every packaging layout. Detached the
    // way the updater restarts it: on Windows with no console at all (it is a
    // console program, and a console window of its own would stop sync when
    // closed), and in a process group of its own everywhere, out of reach of
    // a Ctrl+C meant for whatever started the tray.
    let daemon = exe.with_file_name(jd_platform::BINARY_NAME);
    let _ = jd_platform::install::spawn_detached(&daemon, &["daemon"]);
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

/// The state icons as pixels, for the platforms that are handed an image
/// rather than an icon name.
///
/// build.rs draws them from `sync/packaging/icons/joinery-drive-<state>.svg`,
/// the same files the Linux packages install for icon-theme lookup, so there is
/// one picture of each state and no binary file in the repository.
#[cfg(any(target_os = "linux", target_os = "windows"))]
mod icons {
    use super::view::Icon;

    /// Width and height. 32 is the Windows notification area at 200% scaling;
    /// smaller scales are drawn down from it.
    pub const SIZE: u32 = 32;

    /// Straight (not premultiplied) RGBA, row by row, top row first.
    pub fn rgba(icon: Icon) -> &'static [u8] {
        match icon {
            Icon::Idle => include_bytes!(concat!(env!("OUT_DIR"), "/joinery-drive-idle.rgba")),
            Icon::Busy => include_bytes!(concat!(env!("OUT_DIR"), "/joinery-drive-busy.rgba")),
            Icon::Attention => {
                include_bytes!(concat!(env!("OUT_DIR"), "/joinery-drive-attention.rgba"))
            }
            Icon::Stopped => {
                include_bytes!(concat!(env!("OUT_DIR"), "/joinery-drive-stopped.rgba"))
            }
        }
    }

    #[cfg(test)]
    mod tests {
        use super::*;

        #[test]
        fn every_state_has_a_whole_visible_picture_of_its_own() {
            let all = [Icon::Idle, Icon::Busy, Icon::Attention, Icon::Stopped];
            for icon in all {
                let pixels = rgba(icon);
                assert_eq!(pixels.len(), (SIZE * SIZE * 4) as usize, "{icon:?}");
                // Not blank: a tray icon that rendered as nothing is a tray
                // nobody can find.
                let opaque = pixels.chunks(4).filter(|p| p[3] > 128).count();
                assert!(
                    opaque > (SIZE * SIZE / 8) as usize,
                    "{icon:?} is nearly empty"
                );
            }
            for (i, a) in all.iter().enumerate() {
                for b in &all[i + 1..] {
                    assert_ne!(rgba(*a), rgba(*b), "{a:?} and {b:?} look the same");
                }
            }
        }
    }
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
        /// The same picture as pixels, for a desktop whose icon theme does
        /// not have the named one — the tarball install, which puts nothing
        /// in /usr/share/icons.
        fn icon_pixmap(&self) -> Vec<ksni::Icon> {
            // The status-notifier wants ARGB, most significant byte first.
            let data = super::icons::rgba(self.presentation.icon)
                .chunks(4)
                .flat_map(|p| [p[3], p[0], p[1], p[2]])
                .collect();
            vec![ksni::Icon {
                width: super::icons::SIZE as i32,
                height: super::icons::SIZE as i32,
                data,
            }]
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

        /// ksni serves D-Bus on its own thread, so there is nothing to pump.
        pub fn wait(&mut self, timeout: std::time::Duration) {
            std::thread::sleep(timeout);
        }
    }
}

#[cfg(any(target_os = "macos", target_os = "windows"))]
mod platform {
    //! The native tray on macOS and Windows.
    //!
    //! Both deliver the tray's clicks as window-system events to the thread that
    //! made the icon, and nothing arrives unless that thread takes them off its
    //! queue — so the wait between refreshes is spent doing that rather than
    //! sleeping. macOS also refuses to make a status item at all before the
    //! process has an application object: without one, the first call into the
    //! window server aborts the process.

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
            #[cfg(target_os = "macos")]
            mac::become_menu_bar_app();
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

            // macOS shows the glyph as the menu-bar item's title. The Windows
            // notification area has no titles, only images, and an entry with
            // no image is an empty, unclickable gap — so there the state's icon
            // is drawn (`super::icons`).
            let title = format!("{} ", presentation.icon.glyph());
            #[cfg(target_os = "windows")]
            let image = state_icon(presentation.icon);
            match &mut self.icon {
                Some(icon) => {
                    let _ = icon.set_menu(Some(Box::new(menu)));
                    let _ = icon.set_title(Some(&title));
                    let _ = icon.set_tooltip(Some(&presentation.tooltip));
                    #[cfg(target_os = "windows")]
                    let _ = icon.set_icon(image);
                }
                None => {
                    let builder = TrayIconBuilder::new()
                        .with_menu(Box::new(menu))
                        .with_title(&title)
                        .with_tooltip(&presentation.tooltip);
                    #[cfg(target_os = "windows")]
                    let builder = match image {
                        Some(image) => builder.with_icon(image),
                        None => builder,
                    };
                    self.icon = builder.build().ok();
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

        /// Handle window-system events until `timeout` has passed.
        pub fn wait(&mut self, timeout: std::time::Duration) {
            #[cfg(target_os = "macos")]
            mac::pump(timeout);
            #[cfg(target_os = "windows")]
            win::pump(timeout);
        }
    }

    #[cfg(target_os = "windows")]
    fn state_icon(icon: super::view::Icon) -> Option<tray_icon::Icon> {
        let size = super::icons::SIZE;
        tray_icon::Icon::from_rgba(super::icons::rgba(icon).to_vec(), size, size).ok()
    }

    #[cfg(target_os = "macos")]
    mod mac {
        use objc2::MainThreadMarker;
        use objc2_app_kit::{NSApplication, NSApplicationActivationPolicy, NSEventMask};
        use objc2_foundation::{NSDate, NSDefaultRunLoopMode};

        /// Create the application object, as an accessory: a menu-bar item with
        /// no Dock icon and no menu bar of its own. The bundle says the same
        /// with LSUIElement; saying it here as well keeps the bare binary from
        /// growing a Dock icon when it is run outside the app.
        pub fn become_menu_bar_app() {
            let Some(mtm) = MainThreadMarker::new() else {
                return;
            };
            let app = NSApplication::sharedApplication(mtm);
            app.setActivationPolicy(NSApplicationActivationPolicy::Accessory);
            app.finishLaunching();
        }

        pub fn pump(timeout: std::time::Duration) {
            let Some(mtm) = MainThreadMarker::new() else {
                std::thread::sleep(timeout);
                return;
            };
            let app = NSApplication::sharedApplication(mtm);
            let until = NSDate::dateWithTimeIntervalSinceNow(timeout.as_secs_f64());
            // Every event before the deadline, not one per refresh: opening a
            // menu is several events, and taking one every two seconds would
            // make the menu take seconds to appear. And not past it either: a
            // steady stream of events must not starve the status refresh.
            // SAFETY: an immutable string constant AppKit exports for exactly
            // this argument.
            let mode = unsafe { NSDefaultRunLoopMode };
            while let Some(event) = app.nextEventMatchingMask_untilDate_inMode_dequeue(
                NSEventMask::Any,
                Some(&until),
                mode,
                true,
            ) {
                app.sendEvent(&event);
                if until.timeIntervalSinceNow() <= 0.0 {
                    break;
                }
            }
        }
    }

    #[cfg(target_os = "windows")]
    mod win {
        use std::time::{Duration, Instant};
        use windows_sys::Win32::UI::WindowsAndMessaging::{
            DispatchMessageW, MsgWaitForMultipleObjects, PeekMessageW, TranslateMessage, MSG,
            PM_REMOVE, QS_ALLINPUT,
        };

        pub fn pump(timeout: Duration) {
            let deadline = Instant::now() + timeout;
            loop {
                // SAFETY: MSG is plain data, and every call gets a pointer to
                // this one live, writable value.
                let mut msg: MSG = unsafe { std::mem::zeroed() };
                while unsafe { PeekMessageW(&mut msg, std::ptr::null_mut(), 0, 0, PM_REMOVE) } != 0
                {
                    unsafe {
                        TranslateMessage(&msg);
                        DispatchMessageW(&msg);
                    }
                }
                let left = deadline.saturating_duration_since(Instant::now());
                if left.is_zero() {
                    return;
                }
                // Sleeps until the deadline or the next message, whichever is
                // first, so a click is handled when it happens.
                // SAFETY: no handles, so the null array is never read.
                unsafe {
                    MsgWaitForMultipleObjects(
                        0,
                        std::ptr::null(),
                        0,
                        left.as_millis() as u32,
                        QS_ALLINPUT,
                    )
                };
            }
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
        pub fn wait(&mut self, timeout: std::time::Duration) {
            std::thread::sleep(timeout);
        }
    }
}

#[cfg(test)]
mod tests {
    use std::time::{Duration, Instant};

    #[test]
    fn the_wait_between_refreshes_lasts_the_refresh_and_not_much_more() {
        // The loop's only clock. Returning early spins a laptop's CPU; never
        // returning stops the icon from following the daemon.
        let mut tray = super::platform::Tray::new();
        let started = Instant::now();
        tray.wait(Duration::from_millis(300));
        let waited = started.elapsed();
        assert!(waited >= Duration::from_millis(290), "{waited:?}");
        assert!(waited < Duration::from_secs(3), "{waited:?}");
    }

    #[cfg(windows)]
    #[test]
    fn on_windows_the_wait_takes_what_is_queued_for_the_tray() {
        // A click on the icon arrives as a message to this thread. A wait that
        // slept instead would leave it queued for ever and the menu dead.
        use windows_sys::Win32::System::Threading::GetCurrentThreadId;
        use windows_sys::Win32::UI::WindowsAndMessaging::{
            PeekMessageW, PostThreadMessageW, MSG, PM_NOREMOVE, WM_USER,
        };
        let pending = || {
            // SAFETY: MSG is plain data; the pointer is to this live value.
            let mut msg: MSG = unsafe { std::mem::zeroed() };
            unsafe { PeekMessageW(&mut msg, std::ptr::null_mut(), 0, 0, PM_NOREMOVE) != 0 }
        };
        // The first call gives this thread a message queue to post to.
        assert!(!pending());
        // SAFETY: posting to this thread's own queue, which now exists.
        assert_ne!(
            unsafe { PostThreadMessageW(GetCurrentThreadId(), WM_USER, 0, 0) },
            0
        );
        assert!(pending());

        let mut tray = super::platform::Tray::new();
        tray.wait(Duration::from_millis(100));
        assert!(!pending(), "the wait left a message in the queue");
    }
}
