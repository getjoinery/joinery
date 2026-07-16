import SwiftUI
import JoineryKit

/// JoineryBillingKit — the native in-app purchase surface for any Joinery
/// deployment that sells subscriptions inside its iOS app. It layers on
/// JoineryKit exactly like JoineryMailKit/JoineryDNSFilterKit: the app
/// registers the screen at launch and the server's navigation routing table
/// lights it up (`nativeScreen: "billing"` on a menu entry), with the web
/// pricing page as the version-skew fallback.
///
/// The kit is server-authoritative: plans come from `store/billing_catalog`,
/// StoreKit 2 supplies localized prices and runs the purchase sheet, and the
/// signed transaction is posted to `store/app_store_claim` — the server
/// verifies it and grants the tier. Subscription status shown here is the
/// server's view, not StoreKit's.
///
/// Screen names (matched against `amu_native_screen`):
///   `billing` → BillingScreen (plans, purchase, restore, manage-routing)
public enum JoineryBilling {
    /// Register the native billing screen.
    public static func registerScreens() {
        NativeScreenRegistry.register("billing") { context in
            AnyView(BillingScreen(client: context.session.client))
        }
    }
}
