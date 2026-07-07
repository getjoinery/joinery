import SwiftUI
import JoineryKit
import JoineryMailKit
import JoineryCalendarKit
import JoineryAIChatKit
import JoineryMemberKit

/// The Joinery member app: pure brand shell. All behavior lives in
/// JoineryKit and its layered modules; this target supplies configuration
/// and module registration only.
@main
struct JoineryMemberApp: App {
    private static let keychainService = "com.getjoinery.member"

    init() {
        // Native modules register their navigation screens; the server's
        // routing table lights them up (screen "mailbox" → JoineryMailKit,
        // screen "calendar" → JoineryCalendarKit, screen "ai_chat" →
        // JoineryAIChatKit, screen "profile"/"orders"/"subscriptions"/
        // "events"/"conversations"/"security" → JoineryMemberKit).
        JoineryMail.registerScreens()
        JoineryCalendar.registerScreens()
        JoineryAIChat.registerScreens()
        JoineryMember.registerScreens()

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
