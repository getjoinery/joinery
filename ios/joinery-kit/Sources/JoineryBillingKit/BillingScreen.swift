import SwiftUI
import StoreKit
import JoineryKit

/// The `billing` native screen: current plan (server's view), purchasable
/// plans with StoreKit-localized prices, restore, and manage-routing by
/// source. When the user's subscription is billed elsewhere (source
/// exclusivity), the screen shows the existing source instead of purchase
/// buttons.
public struct BillingScreen: View {
    @StateObject private var store: BillingStore
    @Environment(\.openURL) private var openURL

    static let appStoreManageURL = URL(string: "https://apps.apple.com/account/subscriptions")!

    public init(client: APIClient) {
        _store = StateObject(wrappedValue: BillingStore(client: client))
    }

    init(store: BillingStore) {
        _store = StateObject(wrappedValue: store)
    }

    public var body: some View {
        content
            .navigationTitle("Subscription")
            .navigationBarTitleDisplayMode(.inline)
            .task {
                if case .loading = store.phase { await store.initialLoad() }
            }
            .alert("Something went wrong", isPresented: errorBinding) {
                Button("OK") {}
            } message: {
                Text(store.actionError ?? "")
            }
            .alert("Done", isPresented: messageBinding) {
                Button("OK") {}
            } message: {
                Text(store.actionMessage ?? "")
            }
    }

    @ViewBuilder
    private var content: some View {
        switch store.phase {
        case .loading:
            ProgressView()
                .frame(maxWidth: .infinity, maxHeight: .infinity)
                .accessibilityIdentifier("billing_loading")
        case .failed(let message):
            VStack(spacing: 12) {
                Text(message)
                    .multilineTextAlignment(.center)
                    .foregroundStyle(.secondary)
                    .accessibilityIdentifier("billing_error")
                Button("Try Again") {
                    Task { await store.initialLoad() }
                }
                .buttonStyle(.borderedProminent)
                .accessibilityIdentifier("billing_retry")
            }
            .padding()
        case .loaded:
            if let catalog = store.catalog {
                list(catalog)
            }
        }
    }

    private func list(_ catalog: BillingCatalog) -> some View {
        List {
            statusSection
            if catalog.canPurchase {
                plansSection(catalog)
            } else if let source = catalog.activeSource {
                otherSourceSection(source)
            }
            Section {
                Button("Restore Purchases") {
                    Task { await store.restorePurchases() }
                }
                .disabled(store.purchasing)
                .accessibilityIdentifier("billing_restore")
                if store.summary?.paymentSource == "app_store" {
                    Button("Manage in App Store") {
                        openURL(Self.appStoreManageURL)
                    }
                    .accessibilityIdentifier("billing_manage_app_store")
                }
            }
        }
        .accessibilityIdentifier("billing_list")
        .refreshable { await store.load() }
    }

    private var statusSection: some View {
        Section {
            LabeledContent("Current Plan", value: store.summary?.currentTierName ?? "Free")
                .accessibilityIdentifier("billing_current_tier")
            if let summary = store.summary, summary.activeCount > 0 {
                if let status = summary.status {
                    LabeledContent("Status", value: status.capitalized)
                        .accessibilityIdentifier("billing_status")
                }
                if let date = summary.renewalOrEndDate {
                    LabeledContent("Renews", value: BillingDisplay.dateLabel(date))
                        .accessibilityIdentifier("billing_renewal")
                }
            }
        }
    }

    private func plansSection(_ catalog: BillingCatalog) -> some View {
        Section("Plans") {
            if catalog.plans.isEmpty {
                Text("No plans are available right now.")
                    .foregroundStyle(.secondary)
                    .accessibilityIdentifier("billing_no_plans")
            }
            ForEach(catalog.plans) { plan in
                planRow(plan)
            }
        }
    }

    private func planRow(_ plan: BillingPlan) -> some View {
        let product = store.storeProducts[plan.storeProductID]
        let isCurrent = store.summary?.currentTierName == plan.tier?.name && (store.summary?.activeCount ?? 0) > 0
        return Button {
            Task { await store.purchase(plan) }
        } label: {
            HStack {
                VStack(alignment: .leading, spacing: 2) {
                    Text(plan.tier?.name ?? plan.productName)
                        .font(.subheadline.weight(.medium))
                        .foregroundStyle(.primary)
                    if !plan.period.isEmpty {
                        Text("per \(plan.period)")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                }
                Spacer()
                if isCurrent {
                    Text("Current")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                } else if let product {
                    Text(product.displayPrice)
                        .font(.subheadline.weight(.semibold))
                } else {
                    Text("Unavailable")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
            }
        }
        .disabled(store.purchasing || isCurrent || product == nil)
        .accessibilityIdentifier("billing_plan_\(plan.storeProductID)")
    }

    private func otherSourceSection(_ source: String) -> some View {
        Section {
            Text(Self.otherSourceMessage(source))
                .font(.subheadline)
                .foregroundStyle(.secondary)
                .accessibilityIdentifier("billing_other_source")
            if source == "play_store" {
                Button("Manage in Google Play") {
                    openURL(URL(string: "https://play.google.com/store/account/subscriptions")!)
                }
                .accessibilityIdentifier("billing_manage_play_store")
            }
        }
    }

    static func otherSourceMessage(_ source: String) -> String {
        switch source {
        case "stripe", "paypal":
            return "Your subscription is billed through the website. Manage it there."
        case "play_store":
            return "Your subscription is billed through Google Play. Manage it there."
        default:
            return "Your subscription is billed elsewhere."
        }
    }

    private var errorBinding: Binding<Bool> {
        Binding(get: { store.actionError != nil }, set: { if !$0 { store.actionError = nil } })
    }

    private var messageBinding: Binding<Bool> {
        Binding(get: { store.actionMessage != nil }, set: { if !$0 { store.actionMessage = nil } })
    }
}

/// Small date formatting shared inside the kit.
enum BillingDisplay {
    static func dateLabel(_ raw: String) -> String {
        let formats = ["yyyy-MM-dd HH:mm:ss", "yyyy-MM-dd'T'HH:mm:ssZ", "yyyy-MM-dd"]
        let parser = DateFormatter()
        parser.locale = Locale(identifier: "en_US_POSIX")
        parser.timeZone = TimeZone(identifier: "UTC")
        for format in formats {
            parser.dateFormat = format
            if let date = parser.date(from: raw) {
                return date.formatted(date: .abbreviated, time: .omitted)
            }
        }
        return raw
    }
}
