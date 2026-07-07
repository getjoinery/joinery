import SwiftUI
import JoineryKit

/// Paginated order history: order id/number, date, total, and its line item
/// summaries. Read-only — there is no native purchase or refund flow.
public struct OrdersScreen: View {
    @StateObject private var store: OrderListStore

    public init(client: APIClient) {
        _store = StateObject(wrappedValue: OrderListStore(api: MemberAPI(client: client)))
    }

    public var body: some View {
        content
            .navigationTitle("Orders")
            .navigationBarTitleDisplayMode(.inline)
            .task {
                if case .loading = store.phase { await store.initialLoad() }
            }
    }

    @ViewBuilder
    private var content: some View {
        switch store.phase {
        case .loading:
            ProgressView()
                .frame(maxWidth: .infinity, maxHeight: .infinity)
                .accessibilityIdentifier("orders_loading")
        case .failed(let message):
            VStack(spacing: 12) {
                Text(message)
                    .multilineTextAlignment(.center)
                    .foregroundStyle(.secondary)
                    .accessibilityIdentifier("orders_error")
                Button("Try Again") {
                    Task { await store.initialLoad() }
                }
                .buttonStyle(.borderedProminent)
                .accessibilityIdentifier("orders_retry")
            }
            .padding()
        case .loaded:
            list
        }
    }

    private var list: some View {
        List {
            if store.orders.isEmpty {
                Text("No orders yet.")
                    .foregroundStyle(.secondary)
                    .accessibilityIdentifier("orders_empty")
            }
            ForEach(store.orders) { order in
                orderRow(order)
                    .onAppear {
                        if order.id == store.orders.last?.id {
                            Task { await store.loadMore() }
                        }
                    }
            }
            if store.isLoadingMore {
                HStack { Spacer(); ProgressView(); Spacer() }
            }
        }
        .accessibilityIdentifier("orders_list")
        .refreshable { await store.reload() }
    }

    private func orderRow(_ order: OrderSummary) -> some View {
        VStack(alignment: .leading, spacing: 4) {
            HStack {
                Text("Order #\(order.number)")
                    .font(.subheadline.weight(.medium))
                Spacer()
                Text("$\(order.total)")
                    .font(.subheadline.weight(.semibold))
            }
            Text(MemberDisplay.dateLabel(order.date))
                .font(.caption)
                .foregroundStyle(.secondary)
            if !order.items.isEmpty {
                ForEach(Array(order.items.enumerated()), id: \.offset) { _, item in
                    HStack {
                        Text(item.productName)
                            .font(.caption)
                            .foregroundStyle(.secondary)
                        Spacer()
                        Text("$\(item.price)")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                }
            }
        }
        .padding(.vertical, 2)
    }
}
