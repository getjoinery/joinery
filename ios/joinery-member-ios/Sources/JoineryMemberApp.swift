import SwiftUI
import JoineryKit

/// The Joinery member app: pure brand shell. All behavior lives in
/// JoineryKit; this target supplies configuration only.
@main
struct JoineryMemberApp: App {
    private static let keychainService = "com.getjoinery.member"

    init() {
        // Deterministic UI-test startup: wipe stored credentials so every
        // test run begins signed out.
        if ProcessInfo.processInfo.arguments.contains("--reset-auth") {
            KeychainStore(service: Self.keychainService).deleteCredentials()
        }
    }

    var body: some Scene {
        WindowGroup {
            JoineryAppRoot(config: Self.config, keychainService: Self.keychainService)
        }
    }

    static var config: JoineryConfig {
        let env = ProcessInfo.processInfo.environment
        // UI tests may point the app at a different deployment or masquerade
        // as a different build number; production uses the baked-in values.
        let base = env["JOINERY_BASE_URL"] ?? "https://dev.getjoinery.com"
        let version = env["JOINERY_CLIENT_VERSION"]
            ?? (Bundle.main.infoDictionary?["CFBundleShortVersionString"] as? String ?? "0.1.0")
        return JoineryConfig(
            baseURL: URL(string: base)!,
            clientApp: "joinery-member-ios",
            clientVersion: version,
            appName: "Joinery",
            appStoreURL: nil,
            registrationEnabled: false,
            accentColor: Color(red: 0.16, green: 0.42, blue: 0.75)
        )
    }
}
