import SwiftUI

/// Everything a registered native screen needs from the shell.
public struct NativeScreenContext {
    public let session: SessionController
    public let user: UserSummary
    public let web: WebSessionCoordinator?

    public init(session: SessionController, user: UserSummary, web: WebSessionCoordinator?) {
        self.session = session
        self.user = user
        self.web = web
    }
}

/// The app-extensible native screen table behind the navigation routing
/// rule: a `{type: "native", screen}` destination renders natively when the
/// build knows the screen name, else falls back to the entry's web URL.
///
/// Layered modules (JoineryMailKit, a future DNSFilterKit) register their
/// screen names at app launch; JoineryKit's own screens ("settings") resolve
/// in NavigationShell before this table is consulted. Registration happens
/// once from the app target's init, so storage is a plain lock-guarded map —
/// builders themselves run on the main actor with the view body.
public enum NativeScreenRegistry {
    public typealias Builder = @MainActor (NativeScreenContext) -> AnyView

    private static let lock = NSLock()
    private static var builders: [String: Builder] = [:]

    /// Register (or replace) the builder for a screen name.
    public static func register(_ name: String, builder: @escaping Builder) {
        lock.lock()
        builders[name] = builder
        lock.unlock()
    }

    /// Does this build know the screen name?
    public static func contains(_ name: String) -> Bool {
        lock.lock()
        defer { lock.unlock() }
        return builders[name] != nil
    }

    @MainActor
    public static func view(for name: String, context: NativeScreenContext) -> AnyView? {
        lock.lock()
        let builder = builders[name]
        lock.unlock()
        return builder.map { $0(context) }
    }
}
