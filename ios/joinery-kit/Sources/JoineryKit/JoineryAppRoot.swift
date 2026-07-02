import SwiftUI

/// The app shell a brand target mounts as its root view. Owns the
/// SessionController and switches between launch, login, upgrade, and the
/// signed-in surface — the navigation shell (server-driven tab bar + More).
public struct JoineryAppRoot: View {
    @StateObject private var session: SessionController

    public init(config: JoineryConfig, keychainService: String) {
        _session = StateObject(wrappedValue: SessionController(config: config, keychainService: keychainService))
    }

    public var body: some View {
        Group {
            switch session.state {
            case .launching:
                ProgressView()
                    .accessibilityIdentifier("root_launching")
            case .loggedOut:
                LoginView(session: session)
            case .upgradeRequired(let message):
                UpgradeRequiredView(config: session.client.config, message: message)
            case .loggedIn(let user):
                NavigationShell(session: session, user: user)
                    .tint(session.client.config.accentColor)
            }
        }
        .task { await session.bootstrap() }
    }
}
