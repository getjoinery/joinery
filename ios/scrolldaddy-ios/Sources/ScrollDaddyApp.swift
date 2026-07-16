import SwiftUI
import JoineryKit
import JoineryDNSFilterKit
import JoineryBillingKit

/// The ScrollDaddy app: a pure brand shell over JoineryKit. All account- and
/// navigation-shaped behavior comes from JoineryKit; the DNS-filtering surface
/// comes from JoineryDNSFilterKit. This target supplies only configuration,
/// branding, and module registration — the same shape as JoineryMemberApp.
///
/// Billing is login-only at launch (registration off): accounts are created on
/// the website; the app signs users in and every tier keeps its full function.
@main
struct ScrollDaddyApp: App {
    private static let keychainService = "app.scrolldaddy.ios"
    private static let tunnelBundleID = "app.scrolldaddy.ios.tunnel"

    init() {
        // The DNS filter kit registers its native screens; the server's
        // navigation table lights them up (nativeScreen on the dns_filtering
        // profileMenu entries), with the /profile/dns_filtering/* webviews as
        // the version-skew fallback.
        JoineryDNSFilter.registerScreens(config: Self.dnsFilterConfig)

        // The billing kit registers the `billing` native screen (StoreKit 2
        // purchase/restore, server-authoritative status). It stays dormant
        // until the server flips a nav entry to nativeScreen "billing"; the
        // web pricing page is the fallback.
        JoineryBilling.registerScreens()

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
        let base = env["JOINERY_BASE_URL"] ?? "https://dev.getjoinery.com"
        let version = env["JOINERY_CLIENT_VERSION"]
            ?? (Bundle.main.infoDictionary?["CFBundleShortVersionString"] as? String ?? "0.1.0")
        return JoineryConfig(
            baseURL: URL(string: base)!,
            clientApp: "scrolldaddy-ios",
            clientVersion: version,
            appName: "ScrollDaddy",
            appStoreURL: nil,
            registrationEnabled: false,
            accentColor: Color(red: 0.36, green: 0.24, blue: 0.80)
        )
    }

    static var dnsFilterConfig: DNSFilterConfig {
        DNSFilterConfig(
            baseURL: config.baseURL,
            brandName: "ScrollDaddy",
            tunnelBundleID: Self.tunnelBundleID
        )
    }
}
