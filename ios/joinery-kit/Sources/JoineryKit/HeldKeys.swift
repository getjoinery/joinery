import Foundation
import UIKit

/// The vault secrets this app holds in memory, and when it lets go of them
/// (specs/fortress_mobile_apps.md § R3).
///
/// A secret is read from the Keychain behind the biometric gate once, when a
/// screen that needs it opens, and then lives here. It is dropped — zeroed —
/// when the app has been in the background for `idleMinutes` (default 5, the
/// browser idle lock's spirit), on sign-out and on a 401 (SessionController's
/// sign-out hooks), on unlink, and when the server says the key was retired.
/// Nothing is ever held while the app runs in the background on the OS's
/// schedule: a background task never unlocks.
@MainActor
public final class HeldKeys: ObservableObject {
    /// The scopes whose secrets are in memory now.
    @Published public private(set) var openScopes: Set<String> = []
    /// Bumped by every drop, so work in flight can tell a lock happened under it.
    @Published public private(set) var epoch = 0

    private var secrets: [String: Data] = [:]
    private var extras: [String: Data] = [:]
    private var backgroundedAt: Date?
    private var lockHandlers: [(Bool) -> Void] = []
    private var beforeLockHandlers: [() -> Void] = []
    private let now: () -> Date
    private var observers: [NSObjectProtocol] = []

    public static let idleMinutesKey = "joinery.heldkeys.idle_minutes"

    public var idleMinutes: Int {
        get {
            let v = UserDefaults.standard.integer(forKey: Self.idleMinutesKey)
            return v > 0 ? v : 5
        }
        set { UserDefaults.standard.set(newValue, forKey: Self.idleMinutesKey) }
    }

    public init(now: @escaping () -> Date = Date.init, observeApp: Bool = true) {
        self.now = now
        Self.live.append(Weak(value: self))
        guard observeApp else { return }
        let center = NotificationCenter.default
        observers.append(center.addObserver(forName: UIApplication.didEnterBackgroundNotification,
                                            object: nil, queue: .main) { [weak self] _ in
            MainActor.assumeIsolated { self?.enteredBackground() }
        })
        observers.append(center.addObserver(forName: UIApplication.willEnterForegroundNotification,
                                            object: nil, queue: .main) { [weak self] _ in
            MainActor.assumeIsolated { self?.enteredForeground() }
        })
    }

    // MARK: Holding

    public func hold(_ secret: Data, scope: String) {
        if var old = secrets[scope] { old.wipe() }
        secrets[scope] = secret
        openScopes.insert(scope)
    }

    public func secret(scope: String) -> Data? { secrets[scope] }

    public func isOpen(_ scope: String) -> Bool { secrets[scope] != nil }

    /// A small secret held beside the scopes (the search record key, the AI key).
    public func holdExtra(_ data: Data, name: String) {
        if var old = extras[name] { old.wipe() }
        extras[name] = data
    }

    public func extra(_ name: String) -> Data? { extras[name] }

    // MARK: Letting go

    /// fn(everything) runs after a drop: `true` when every scope went.
    public func onLock(_ fn: @escaping (Bool) -> Void) { lockHandlers.append(fn) }

    /// fn() runs just before the background lock drops the keys — the chance
    /// to save an open compose while its key still exists (the browser's B14).
    public func beforeLock(_ fn: @escaping () -> Void) { beforeLockHandlers.append(fn) }

    public func drop(scope: String) {
        guard var s = secrets.removeValue(forKey: scope) else { return }
        s.wipe()
        openScopes.remove(scope)
        epoch += 1
        lockHandlers.forEach { $0(false) }
    }

    public func dropAll() {
        for key in secrets.keys {
            secrets[key]?.wipe()
        }
        for key in extras.keys {
            extras[key]?.wipe()
        }
        secrets = [:]
        extras = [:]
        openScopes = []
        epoch += 1
        lockHandlers.forEach { $0(true) }
    }

    // MARK: The background timer

    private var idleTimer: DispatchWorkItem?

    public func enteredBackground() {
        backgroundedAt = now()
        // Save what is open while the key still exists; the drop itself waits
        // for the idle time, so a quick app switch costs nothing. The timer
        // fires in the background if the process runs (a background task wakes
        // it), and on return otherwise, where the check below covers it too.
        if !secrets.isEmpty { beforeLockHandlers.forEach { $0() } }
        idleTimer?.cancel()
        let item = DispatchWorkItem { [weak self] in
            MainActor.assumeIsolated {
                guard let self, self.backgroundedAt != nil else { return }
                self.dropAll()
            }
        }
        idleTimer = item
        DispatchQueue.main.asyncAfter(deadline: .now() + .seconds(idleMinutes * 60), execute: item)
    }

    /// A task the OS runs in the background (the new-mail check) is never the
    /// person present: every live holder lets go before it runs (R3).
    public static func dropForBackgroundTask() {
        for box in live { box.value?.dropAll() }
        live.removeAll { $0.value == nil }
    }

    private struct Weak { weak var value: HeldKeys? }
    private static var live: [Weak] = []

    public func enteredForeground() {
        idleTimer?.cancel()
        idleTimer = nil
        defer { backgroundedAt = nil }
        guard let since = backgroundedAt, !secrets.isEmpty else { return }
        if now().timeIntervalSince(since) >= TimeInterval(idleMinutes * 60) {
            dropAll()
        }
    }
}
