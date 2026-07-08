import SwiftUI
import JoineryKit

/// JoineryDNSFilterKit — the reusable DNS-filtering surface for any
/// ScrollDaddy-style Joinery deployment (ScrollDaddy today, a future
/// NetworkSentry with only branding changed). It layers on JoineryKit exactly
/// like JoineryMailKit/JoineryMemberKit: the app registers these screens at
/// launch and the server's navigation routing table lights them up
/// (`nativeScreen` on the dns_filtering plugin's profileMenu entries), with the
/// existing `/profile/dns_filtering/*` webviews as the version-skew fallback.
///
/// Screen names (matched against `amu_native_screen`):
///   `dns_protection` → ProtectionScreen (this phone's activation + mode)
///   `dns_devices`    → DevicesScreen (per-device always-on policy editor)
public enum JoineryDNSFilter {
    /// Register the native DNS screens. The app passes its DNSFilterConfig
    /// (deployment origin, brand name, packet-tunnel bundle id) so the
    /// activation and strict-mode layers are brand-neutral in the kit.
    public static func registerScreens(config: DNSFilterConfig) {
        NativeScreenRegistry.register("dns_protection") { context in
            AnyView(ProtectionScreen(client: context.session.client, config: config))
        }
        NativeScreenRegistry.register("dns_devices") { context in
            AnyView(DevicesScreen(client: context.session.client, web: context.web))
        }
    }
}
