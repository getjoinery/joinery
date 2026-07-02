import SwiftUI

/// Per-app configuration injected by the brand target. JoineryKit itself
/// carries no brand knowledge — a second app consumes the kit unchanged by
/// supplying a different config.
public struct JoineryConfig: Sendable {
    /// Deployment origin, e.g. `https://dev.getjoinery.com`. No trailing slash.
    public let baseURL: URL
    /// The `client_app` identifier sent on every request and used by the
    /// server for version minimums and tab pinning (e.g. `joinery-member-ios`).
    public let clientApp: String
    /// The app's marketing version, sent as `client-version`.
    public let clientVersion: String
    /// Display name shown on the login screen and settings header.
    public let appName: String
    /// App Store page for the blocking upgrade screen. Optional during
    /// development (the screen still renders, without a store button).
    public let appStoreURL: URL?
    /// In-app registration. Off by default — enabling it triggers Apple's
    /// in-app account-deletion requirement, so an app turns it on only in a
    /// release that also ships deletion.
    public let registrationEnabled: Bool
    /// Brand accent color.
    public let accentColor: Color

    public init(
        baseURL: URL,
        clientApp: String,
        clientVersion: String,
        appName: String,
        appStoreURL: URL? = nil,
        registrationEnabled: Bool = false,
        accentColor: Color = .blue
    ) {
        self.baseURL = baseURL
        self.clientApp = clientApp
        self.clientVersion = clientVersion
        self.appName = appName
        self.appStoreURL = appStoreURL
        self.registrationEnabled = registrationEnabled
        self.accentColor = accentColor
    }
}
