import SwiftUI
import JoineryKit

/// Active + cancelled subscriptions. Read-only plus cancel — changing tier
/// and billing management are deliberately web-only (Apple IAP policy;
/// specs/mobile_native_member_screens.md § Deliberately web), so those rows
/// open the web pages through `context.web` instead of native purchase UI.
/// Store-billed subscriptions (payment_source app_store / play_store) are
/// managed in their store: the Stripe web rows hide and a deep link to the
/// store's subscription management appears instead.
public struct SubscriptionsScreen: View {
    @StateObject private var store: SubscriptionStore
    private let client: APIClient
    private let web: WebSessionCoordinator?
    @State private var pendingCancel: SubscriptionRow?
    @Environment(\.openURL) private var openURL

    static let appStoreManageURL = URL(string: "https://apps.apple.com/account/subscriptions")!
    static let playStoreManageURL = URL(string: "https://play.google.com/store/account/subscriptions")!

    public init(client: APIClient, web: WebSessionCoordinator?) {
        self.client = client
        self.web = web
        _store = StateObject(wrappedValue: SubscriptionStore(api: MemberAPI(client: client)))
    }

    public var body: some View {
        content
            .navigationTitle("Subscriptions")
            .navigationBarTitleDisplayMode(.inline)
            .task {
                if case .loading = store.phase { await store.initialLoad() }
            }
            .confirmationDialog(
                "Cancel this subscription?", isPresented: cancelDialogBinding, titleVisibility: .visible
            ) {
                Button("Cancel Subscription", role: .destructive) {
                    if let sub = pendingCancel {
                        Task { await store.cancel(orderItemID: sub.orderItemID) }
                    }
                    pendingCancel = nil
                }
                Button("Keep Subscription", role: .cancel) { pendingCancel = nil }
            } message: {
                Text("Your access continues until the end of the current billing period.")
            }
            .alert("Could not cancel", isPresented: cancelErrorBinding) {
                Button("OK") {}
            } message: {
                Text(store.cancelError ?? "")
            }
    }

    @ViewBuilder
    private var content: some View {
        switch store.phase {
        case .loading:
            ProgressView()
                .frame(maxWidth: .infinity, maxHeight: .infinity)
                .accessibilityIdentifier("subscriptions_loading")
        case .failed(let message):
            VStack(spacing: 12) {
                Text(message)
                    .multilineTextAlignment(.center)
                    .foregroundStyle(.secondary)
                    .accessibilityIdentifier("subscriptions_error")
                Button("Try Again") {
                    Task { await store.initialLoad() }
                }
                .buttonStyle(.borderedProminent)
                .accessibilityIdentifier("subscriptions_retry")
            }
            .padding()
        case .loaded:
            if let payload = store.payload {
                list(payload)
            }
        }
    }

    private func list(_ payload: SubscriptionSummaryPayload) -> some View {
        List {
            Section {
                LabeledContent("Current Plan", value: payload.currentTier?.name ?? "Free")
                    .accessibilityIdentifier("subscriptions_current_tier")
                switch payload.paymentSource {
                case "app_store":
                    Button("Manage in App Store") {
                        openURL(Self.appStoreManageURL)
                    }
                    .accessibilityIdentifier("subscriptions_manage_app_store")
                case "play_store":
                    Button("Manage in Google Play") {
                        openURL(Self.playStoreManageURL)
                    }
                    .accessibilityIdentifier("subscriptions_manage_play_store")
                default:
                    if let web {
                        NavigationLink {
                            WebScreen(title: "Change Plan", target: "/profile/change-tier", client: client, web: web)
                        } label: {
                            Text("Change Plan")
                        }
                        .accessibilityIdentifier("subscriptions_change_plan")
                        if payload.paymentSource == "stripe" {
                            NavigationLink {
                                WebScreen(title: "Billing", target: "/profile/billing", client: client, web: web)
                            } label: {
                                Text("Manage Billing")
                            }
                            .accessibilityIdentifier("subscriptions_billing")
                        }
                    }
                }
            }
            if !payload.activeSubscriptions.isEmpty {
                Section("Active") {
                    ForEach(payload.activeSubscriptions) { sub in
                        subscriptionRow(sub, active: true)
                    }
                }
            }
            if !payload.cancelledSubscriptions.isEmpty {
                Section("Cancelled") {
                    ForEach(payload.cancelledSubscriptions) { sub in
                        subscriptionRow(sub, active: false)
                    }
                }
            }
            if payload.activeSubscriptions.isEmpty && payload.cancelledSubscriptions.isEmpty {
                Text("No subscriptions.")
                    .foregroundStyle(.secondary)
                    .accessibilityIdentifier("subscriptions_empty")
            }
        }
        .accessibilityIdentifier("subscriptions_list")
        .refreshable { await store.reload() }
    }

    private func subscriptionRow(_ sub: SubscriptionRow, active: Bool) -> some View {
        VStack(alignment: .leading, spacing: 4) {
            HStack {
                Text(sub.productName)
                    .font(.subheadline.weight(.medium))
                Spacer()
                Text("$\(sub.price)")
                    .font(.subheadline.weight(.semibold))
            }
            HStack {
                Text(sub.status.capitalized)
                    .font(.caption)
                    .foregroundStyle(active ? .secondary : Color.secondary)
                if let date = sub.renewalOrEndDate {
                    Text("· \(MemberDisplay.dateLabel(date))")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
            }
            if active && sub.canCancel {
                Button("Cancel", role: .destructive) {
                    pendingCancel = sub
                }
                .font(.caption)
                .accessibilityIdentifier("subscription_cancel_\(sub.orderItemID)")
            }
        }
        .padding(.vertical, 2)
    }

    private var cancelDialogBinding: Binding<Bool> {
        Binding(get: { pendingCancel != nil }, set: { if !$0 { pendingCancel = nil } })
    }

    private var cancelErrorBinding: Binding<Bool> {
        Binding(get: { store.cancelError != nil }, set: { if !$0 { store.clearCancelError() } })
    }
}
