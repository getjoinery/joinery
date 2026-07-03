import SwiftUI

/// The signed-in surface: a tab bar of server-pinned entries plus a More tab
/// holding everything else and the native Settings screen. Fed entirely by
/// `GET /api/v1/app/navigation`, so menu changes (new plugin pages, retitles,
/// tab re-pinning) reach shipped apps with no release.
public struct NavigationShell: View {
    @ObservedObject var session: SessionController
    let user: UserSummary

    @StateObject private var store: NavigationStore
    @State private var web: WebSessionCoordinator?
    @Environment(\.scenePhase) private var scenePhase

    /// The tab bar holds at most this many server entries; the More tab is
    /// always the last slot.
    private static let maxTabs = 4

    public init(session: SessionController, user: UserSummary) {
        self.session = session
        self.user = user
        _store = StateObject(wrappedValue: NavigationStore(client: session.client))
    }

    public var body: some View {
        content
            .task {
                if web == nil {
                    let coordinator = WebSessionCoordinator(client: session.client)
                    web = coordinator
                    session.onSignOut = { Task { await coordinator.reset() } }
                }
                if case .loading = store.phase { await store.load() }
            }
            .onChange(of: scenePhase) { phase in
                // Foreground: pick up menu changes and notice a revoked
                // session (both calls 401 → the client handler signs out).
                guard phase == .active else { return }
                Task {
                    await session.refreshUser()
                    await store.refresh()
                }
            }
    }

    @ViewBuilder
    private var content: some View {
        switch store.phase {
        case .loading:
            ProgressView()
                .accessibilityIdentifier("nav_loading")
        case .failed(let message):
            VStack(spacing: 12) {
                Text(message)
                    .multilineTextAlignment(.center)
                    .foregroundStyle(.secondary)
                    .accessibilityIdentifier("nav_error")
                Button("Try Again") {
                    Task { await store.load() }
                }
                .buttonStyle(.borderedProminent)
                .accessibilityIdentifier("nav_retry")
            }
            .padding()
        case .loaded(let navigation):
            shell(for: navigation)
        }
    }

    private func shell(for navigation: AppNavigation) -> some View {
        let pinned = Array(navigation.tabEntries.prefix(Self.maxTabs))
        // Overflow pinned entries and everything unpinned live in More.
        let overflow = Array(navigation.tabEntries.dropFirst(Self.maxTabs))
        let more = overflow + navigation.moreEntries

        return TabView {
            ForEach(pinned) { entry in
                NavigationStack {
                    destinationView(for: entry)
                }
                .tabItem { Label(entry.title, systemImage: entry.systemImage) }
                .tag(entry.slug)
            }
            NavigationStack {
                moreList(entries: more)
            }
            .tabItem { Label("More", systemImage: "ellipsis") }
            .tag("more")
        }
    }

    private func moreList(entries: [NavEntry]) -> some View {
        List {
            Section {
                ForEach(entries) { entry in
                    NavigationLink {
                        destinationView(for: entry)
                    } label: {
                        Label(entry.title, systemImage: entry.systemImage)
                    }
                    .accessibilityIdentifier("more_\(entry.slug)")
                }
            }
            Section {
                NavigationLink {
                    SettingsView(session: session, user: user, web: web)
                } label: {
                    Label("Settings", systemImage: "gearshape")
                }
                .accessibilityIdentifier("more_settings")
            }
        }
        .navigationTitle("More")
    }

    /// Version-safe destination resolution: web renders in the webview;
    /// native renders the named screen when this build knows it — the kit's
    /// own screens first, then the app-registered NativeScreenRegistry —
    /// else its fallback URL (spec § Navigation endpoint).
    @ViewBuilder
    private func destinationView(for entry: NavEntry) -> some View {
        switch entry.destination {
        case .web(let url):
            webScreen(title: entry.title, target: url)
        case .native(let screen, let fallbackURL):
            switch screen {
            case "settings":
                SettingsView(session: session, user: user, web: web)
            default:
                if let registered = NativeScreenRegistry.view(
                    for: screen,
                    context: NativeScreenContext(session: session, user: user, web: web)
                ) {
                    registered
                } else if fallbackURL.isEmpty {
                    Text("Update the app to use \(entry.title).")
                        .foregroundStyle(.secondary)
                        .padding()
                } else {
                    webScreen(title: entry.title, target: fallbackURL)
                }
            }
        }
    }

    @ViewBuilder
    private func webScreen(title: String, target: String) -> some View {
        if let web {
            WebScreen(title: title, target: target, client: session.client, web: web)
        } else {
            ProgressView()
        }
    }
}
